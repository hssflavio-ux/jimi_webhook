-- ============================================================================
-- JIMI Webhook System — Migração v4.11.0
--
-- Fase 1 da correção do fluxo chip → câmera → veículo (ver PLANO no chat que
-- gerou esta migração — dono do produto pediu para separar as 3 entidades).
--
-- ── O PROBLEMA ──────────────────────────────────────────────────────────────
-- `devices` (a câmera) sempre FOI o "ativo": `device_name` ("Placa"),
-- `vehicle_type` e `activation_date` moram na mesma linha da câmera física.
-- Resultado: não existe veículo sem câmera, não existe histórico de duas
-- instalações da mesma câmera em veículos diferentes, e trocar a câmera de
-- veículo significa reescrever a identidade da própria linha — o dado antigo
-- simplesmente desaparece.
--
-- Esta migração:
--   1. Cria `vehicles` (a entidade veículo, independente de câmera).
--   2. Cria `device_installations` (histórico: qual câmera esteve em qual
--      veículo, de quando a quando — o que faltava para reuso sequencial).
--   3. Faz o backfill 1:1: toda `devices` com `customer_id` definido vira
--      exatamente 1 `vehicles` (mesma placa/tipo/cliente/status) + 1
--      `device_installations` (aberta se a câmera está ativa, fechada na
--      `updated_at` se já estava soft-deletada). Nada some da grade.
--   4. Remove `devices.sim_card_id` — FK criada em v4.0.0 e NUNCA escrita por
--      nenhum código (confirmado por leitura de `ativos_novo.php`,
--      `ativos.php` e `link_sim_card_to_device()`): o vínculo chip↔câmera
--      sempre rodou por `sim_cards.imei` (já com UNIQUE desde a v4.10.4).
--      Mantê-la era um artefato morto e enganoso.
--
-- `devices.device_name` / `vehicle_type` / `activation_date` NÃO são
-- removidos aqui — ficam ignorados pelo código novo. Apagá-los é um passo
-- separado, só depois de confirmar em produção que nada mais os lê.
--
-- Idempotente: a presença de `devices.sim_card_id` é o sinal de "ainda não
-- migrado" (é a última coisa que este script remove) — rodar de novo depois
-- de completa não repete o backfill nem falha.
-- ============================================================================

-- ── 1. Tabela `vehicles` ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `plate` varchar(100) NOT NULL COMMENT 'Placa/identificador — texto livre, sem formato exigido',
  `vehicle_type` varchar(30) DEFAULT NULL COMMENT 'Chave de VEHICLE_ICONS (includes/vehicle_icons.php)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vehicle_customer` (`customer_id`),
  KEY `idx_vehicle_active` (`is_active`),
  CONSTRAINT `fk_vehicle_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Veículo — entidade própria, independente da câmera instalada nele';

-- ── 2. Tabela `device_installations` ────────────────────────────────────────
-- Invariante "no máximo 1 linha aberta (removed_at IS NULL) por device_id e
-- por vehicle_id" é garantida em código (install_device_on_vehicle() /
-- uninstall_device_from_vehicle(), includes/functions.php), dentro de
-- transação — MySQL não tem índice único parcial (WHERE removed_at IS NULL).
CREATE TABLE IF NOT EXISTS `device_installations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `device_id` bigint unsigned NOT NULL,
  `vehicle_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL COMMENT 'Snapshot do dono do veículo no momento da instalação',
  `installed_at` datetime NOT NULL,
  `removed_at` datetime DEFAULT NULL COMMENT 'NULL = instalação corrente',
  `installed_by` bigint unsigned DEFAULT NULL,
  `removed_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_di_device_open` (`device_id`,`removed_at`),
  KEY `idx_di_vehicle_open` (`vehicle_id`,`removed_at`),
  CONSTRAINT `fk_di_device` FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_di_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico de instalação de câmera em veículo — permite reuso sequencial';

-- ── 3/4. Backfill + remoção da FK morta (gate único: sim_card_id existe?) ──
SET @needs_migration := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices' AND column_name = 'sim_card_id');

-- 3a. Coluna transiente em `vehicles` só para casar cada linha nova com a
-- `devices` de origem pelo IMEI (único) — nunca pela ORDEM de inserção, que o
-- SQL não garante. Removida no passo 3d, não sobra no schema final.
SET @sql := IF(@needs_migration = 1,
    "ALTER TABLE `vehicles` ADD COLUMN `_src_imei` VARCHAR(20) NULL",
    "SELECT 'coluna transiente _src_imei nao necessaria' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- `devices` sem cliente (órfãos) NÃO viram veículo — são um problema à parte,
-- já tratado por `?customer_id=none` em /equipamentos; fabricar veículo para
-- eles inventaria um dono que não existe.
SET @sql := IF(@needs_migration = 1,
    "INSERT INTO `vehicles` (`customer_id`, `plate`, `vehicle_type`, `is_active`, `created_by`, `created_at`, `updated_at`, `_src_imei`)
     SELECT `customer_id`,
            COALESCE(NULLIF(`device_name`, ''), CONCAT('(sem placa) ', `imei`)),
            `vehicle_type`, `is_active`, `created_by`, `created_at`, `updated_at`, `imei`
       FROM `devices`
      WHERE `customer_id` IS NOT NULL",
    "SELECT 'backfill vehicles ja aplicado (ou nao necessario)' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3b. Uma instalação por par (devices.id, vehicles.id), casados pelo IMEI —
-- exato, sem depender de ordem de leitura/escrita.
SET @sql := IF(@needs_migration = 1,
    "INSERT INTO `device_installations`
        (`device_id`, `vehicle_id`, `customer_id`, `installed_at`, `removed_at`)
     SELECT d.id, v.id, d.customer_id,
            COALESCE(d.activation_date, d.created_at, NOW()),
            IF(d.is_active = 1, NULL, COALESCE(d.updated_at, NOW()))
       FROM `devices` d
       JOIN `vehicles` v ON v.`_src_imei` = d.`imei`
      WHERE d.`customer_id` IS NOT NULL",
    "SELECT 'backfill device_installations ja aplicado (ou nao necessario)' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3c. FK morta (nunca escrita — ver cabeçalho) removida só depois do backfill.
-- Checagem por nome em information_schema (não em `devices.sim_card_id`, que
-- só é removida no passo seguinte): DROP FOREIGN KEY numa constraint
-- inexistente erra alto e travaria um rerun no meio do caminho.
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'devices'
      AND constraint_name = 'fk_dev_simcard' AND constraint_type = 'FOREIGN KEY');
SET @sql := IF(@needs_migration = 1 AND @fk_exists = 1,
    "ALTER TABLE `devices` DROP FOREIGN KEY `fk_dev_simcard`",
    "SELECT 'fk_dev_simcard ja removida (ou nunca existiu)' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(@needs_migration = 1,
    "ALTER TABLE `devices` DROP COLUMN `sim_card_id`",
    "SELECT 'sim_card_id ja removida' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3d. Coluna transiente sai por último — só depois de casar 3a/3b.
SET @sql := IF(@needs_migration = 1,
    "ALTER TABLE `vehicles` DROP COLUMN `_src_imei`",
    "SELECT 'coluna transiente ja removida (ou nunca criada)' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
