-- ============================================================================
-- JIMI Webhook System — Migração v4.9.14
--
-- F3 do `PROJETO_PARAMETROS.md`: perfis de configuração **por modelo** e a
-- infraestrutura da ESCRITA (`33027`).
--
-- ⚠️ POR MODELO, NÃO POR CLIENTE — e isso foi medido, não suposto. Em
-- 12/08/2026 o JC371 devolveu 49 parâmetros e o JC181, **6**, com conjuntos
-- diferentes. Um perfil por cliente compararia os dois e acusaria dezenas de
-- divergências falsas; pior, aplicá-lo mandaria para o JC181 parâmetros que ele
-- não tem. O cliente pode sobrepor (`customer_id`), mas a base é o modelo.
--
-- 🔴 A ESCRITA É A ÚNICA PARTE DESTE PROJETO QUE MEXE EM EQUIPAMENTO EM
-- OPERAÇÃO. Três travas, e nenhuma é decorativa:
--
--   1. só entra em perfil o parâmetro `writable = 1` do catálogo — e nada é
--      gravável por omissão. Parâmetro `medido` (sem doc) nunca é gravável:
--      não se escreve o que não se sabe nomear;
--   2. `device_params.desired_value` é gravado ANTES do despacho, junto com o
--      valor anterior. Foi a contrapartida acordada com o dono do produto em
--      12/08/2026 quando ele aceitou o risco dos parâmetros de rede: a
--      recuperação é por SMS, e **sem o valor anterior no banco o SMS não tem
--      para onde apontar**;
--   3. `is_network` marca os sete que tiram a câmera da plataforma se forem
--      escritos errado (`16`,`17`,`18`,`19`,`23`,`24`,`25`). Não bloqueiam —
--      decisão registrada em §8.1 do blueprint —, mas a tela exige confirmação
--      explícita dizendo que a volta é por SMS.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── 1. Marca os parâmetros de risco de rede ─────────────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'device_param_catalog'
              AND column_name = 'is_network');
SET @sql := IF(@c = 0,
    'ALTER TABLE `device_param_catalog` ADD COLUMN `is_network` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = escrever errado tira a camera da plataforma (volta so por SMS)''',
    'SELECT ''is_network ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE `device_param_catalog`
   SET `is_network` = 1
 WHERE `param_no` IN (16, 17, 18, 19, 23, 24, 25);

-- ── 2. Guarda o valor anterior junto do desejado ────────────────────────────
-- `desired_value` e `applied_at` já existem desde a v4.9.12. Falta o de onde
-- se veio: sem ele, "desfazer" depende da memória de quem aplicou.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'device_params'
              AND column_name = 'previous_value');
SET @sql := IF(@c = 0,
    'ALTER TABLE `device_params`
       ADD COLUMN `previous_value` VARCHAR(255) NULL
         COMMENT ''Valor antes da ultima escrita — e para onde o SMS de recuperacao aponta'',
       ADD COLUMN `desired_at` DATETIME NULL COMMENT ''Quando a escrita foi pedida''',
    'SELECT ''device_params.previous_value ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. Perfis ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `param_profiles` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(100) NOT NULL,
  `device_model_id` BIGINT UNSIGNED NOT NULL,
  `customer_id`     BIGINT UNSIGNED NULL COMMENT 'NULL = perfil padrao do modelo, vale para todos',
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_perfil_modelo_cliente` (`device_model_id`, `customer_id`),
  KEY `idx_pp_customer` (`customer_id`),
  CONSTRAINT `fk_pp_model`    FOREIGN KEY (`device_model_id`) REFERENCES `device_models` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pp_customer` FOREIGN KEY (`customer_id`)     REFERENCES `customers` (`id`)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Perfil de configuracao por MODELO (cliente pode sobrepor)';

-- A chave única é `(device_model_id, customer_id)`: um perfil padrão por modelo
-- e no máximo um por cliente. Permitir vários criaria a pergunta "qual vale?",
-- que é exatamente o tipo de ambiguidade que faz configuração divergir sem
-- ninguém perceber.

CREATE TABLE IF NOT EXISTS `param_profile_values` (
  `profile_id` BIGINT UNSIGNED NOT NULL,
  `param_no`   SMALLINT UNSIGNED NOT NULL,
  `channel`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `value`      VARCHAR(255) NOT NULL,
  PRIMARY KEY (`profile_id`, `param_no`, `channel`),
  CONSTRAINT `fk_ppv_profile` FOREIGN KEY (`profile_id`) REFERENCES `param_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Valores desejados de um perfil';

-- ── 4. Histórico de escrita ─────────────────────────────────────────────────
--
-- `device_params` guarda o ESTADO; isto guarda o que foi TENTADO. São coisas
-- diferentes e confundi-las é como se perde auditoria: uma escrita recusada
-- pelo device não muda o estado, mas precisa aparecer para quem investiga.
CREATE TABLE IF NOT EXISTS `device_param_writes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `imei`       VARCHAR(20) NOT NULL,
  `param_no`   SMALLINT UNSIGNED NOT NULL,
  `channel`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `from_value` VARCHAR(255) NULL COMMENT 'Valor lido antes da escrita',
  `to_value`   VARCHAR(255) NOT NULL,
  `origem`     VARCHAR(32)  NOT NULL COMMENT 'manual | perfil:<id>',
  `command_id` BIGINT UNSIGNED NULL,
  `status`     VARCHAR(16)  NOT NULL DEFAULT 'pedido' COMMENT 'pedido | aceito | falhou',
  `user_id`    BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dpw_imei` (`imei`, `created_at` DESC),
  KEY `idx_dpw_cmd`  (`command_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Toda escrita de parametro tentada, com o valor de origem';

-- ── 5. Conferência: os sete de risco de rede ────────────────────────────────
SELECT param_no, name_pt, writable, is_network
  FROM `device_param_catalog` WHERE is_network = 1 ORDER BY param_no;

-- ── 6. Conferência: nada gravável sem nome de verdade ───────────────────────
-- Deve devolver ZERO. Escrever parâmetro que ninguém sabe nomear é mexer no
-- escuro num equipamento em operação.
SELECT param_no, name_pt, doc_ref
  FROM `device_param_catalog` WHERE writable = 1 AND doc_ref = 'medido';

-- ── 7. Conferência: perfil não pode conter parâmetro não-gravável ───────────
-- Deve devolver ZERO agora (não há perfis) e continuar zero depois. A tela
-- barra na entrada; esta consulta é a rede embaixo.
SELECT ppv.profile_id, ppv.param_no, c.name_pt, c.writable
  FROM `param_profile_values` ppv
  LEFT JOIN `device_param_catalog` c ON c.param_no = ppv.param_no
 WHERE c.param_no IS NULL OR c.writable = 0;

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.14', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.14', last_update = NOW();

SELECT 'Migracao v4.9.14 concluida' AS status;
