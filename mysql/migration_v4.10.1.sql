-- ============================================================================
-- JIMI Webhook System — Migração v4.10.1
--
-- Item 3 do docs/PLANO_IMPLEMENTACAO_v4.10.md: manutenção preventiva por
-- métrica (odômetro/horas de ignição/horímetro/data) e lembrete de vencimento
-- de documento do motorista (CNH/toxicológico).
--
-- Decisão de implementação que o plano deixou em aberto (§"Pendências"):
-- `notify()` (includes/notification_engine.php) só dedupe o E-MAIL dentro de
-- uma janela curta (NOTIFY_EMAIL_DEDUPE_MINUTES) — o SINO (linha em
-- `notifications`) é gravado a cada chamada, sem dedupe nenhum. Um worker
-- diário que chamasse notify() sem controle próprio duplicaria o sino (e o
-- e-mail, fora da janela) todo dia em que o item continuasse vencido. Por
-- isso `maintenance_reminders.last_notified_at` e `drivers.cnh_notified_at`/
-- `tox_notified_at` — todos DATE, não DATETIME: a pergunta do worker é "já
-- notifiquei HOJE?", não "há quanto tempo". O comportamento resultante é
-- "lembra uma vez por dia enquanto estiver vencido/próximo", que é o padrão
-- esperado de um lembrete de manutenção (ao contrário do alarme, que é
-- evento único).
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── 1. maintenance_reminders — item de manutenção por métrica ──────────────
CREATE TABLE IF NOT EXISTS `maintenance_reminders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `imei` varchar(20) DEFAULT NULL COMMENT 'NULL = lembrete nao associado a um veiculo (ex.: so por motorista/data)',
  `driver_id` bigint unsigned DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `metric` enum('odometro','horas_ignicao','horimetro','data') NOT NULL,
  `interval_km` decimal(10,1) DEFAULT NULL,
  `interval_hours` decimal(10,1) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `last_done_km` decimal(12,2) DEFAULT NULL,
  `last_done_hours` decimal(10,1) DEFAULT NULL,
  `last_done_at` datetime DEFAULT NULL,
  `notify_bell` tinyint(1) NOT NULL DEFAULT 1,
  `notify_email` tinyint(1) NOT NULL DEFAULT 0,
  `emails` json DEFAULT NULL,
  `last_notified_at` date DEFAULT NULL COMMENT 'Dedupe do worker diario — ver cabecalho desta migracao',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mr_customer` (`customer_id`),
  KEY `idx_mr_imei` (`imei`),
  KEY `idx_mr_driver` (`driver_id`),
  KEY `idx_mr_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lembretes de manutencao preventiva por veiculo/motorista (item 3, v4.10)';

-- ── 2. Horímetro reportado pelo equipamento ─────────────────────────────────
-- Campo do webhook ainda NÃO confirmado contra device real (ver
-- docs/PLANO_IMPLEMENTACAO_v4.10.md §Pendências) — handlers/pushgps.php e
-- pushhb.php tentam vários nomes de campo prováveis; se nenhum bater, a
-- coluna fica NULL para sempre e o metric='horimetro' simplesmente não tem
-- valor atual (a tela mostra "—", não quebra).
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'devices'
              AND column_name = 'engine_hours');
SET @sql := IF(@c = 0,
    "ALTER TABLE `devices` ADD COLUMN `engine_hours` decimal(10,1) NULL DEFAULT NULL
       COMMENT 'Horimetro reportado pelo equipamento (campo do webhook nao confirmado ainda)'
       AFTER `speed_limit_kmh`",
    'SELECT ''devices.engine_hours ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. Lembrete de vencimento de documento do motorista ─────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'drivers'
              AND column_name = 'remind_cnh');
SET @sql := IF(@c = 0,
    "ALTER TABLE `drivers` ADD COLUMN `remind_cnh` tinyint(1) NOT NULL DEFAULT 0
       COMMENT 'Notificar quando cnh_expires_at estiver proxima'
       AFTER `tox_exam_expires_at`",
    'SELECT ''drivers.remind_cnh ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'drivers'
              AND column_name = 'remind_tox');
SET @sql := IF(@c = 0,
    "ALTER TABLE `drivers` ADD COLUMN `remind_tox` tinyint(1) NOT NULL DEFAULT 0
       COMMENT 'Notificar quando tox_exam_expires_at estiver proxima'
       AFTER `remind_cnh`",
    'SELECT ''drivers.remind_tox ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'drivers'
              AND column_name = 'cnh_notified_at');
SET @sql := IF(@c = 0,
    "ALTER TABLE `drivers` ADD COLUMN `cnh_notified_at` date NULL DEFAULT NULL
       COMMENT 'Dedupe do worker diario (ver cabecalho da migracao v4.10.1)'
       AFTER `remind_tox`",
    'SELECT ''drivers.cnh_notified_at ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'drivers'
              AND column_name = 'tox_notified_at');
SET @sql := IF(@c = 0,
    "ALTER TABLE `drivers` ADD COLUMN `tox_notified_at` date NULL DEFAULT NULL
       COMMENT 'Dedupe do worker diario (ver cabecalho da migracao v4.10.1)'
       AFTER `cnh_notified_at`",
    'SELECT ''drivers.tox_notified_at ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SELECT CONCAT('maintenance_reminders: ', COUNT(*), ' linhas') AS resultado FROM maintenance_reminders;
