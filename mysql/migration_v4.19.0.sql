-- ============================================================================
-- Migration v4.19.0 — driver_sessions: motorista corrente do veículo (AFIS)
--
-- Pedido do dono do produto: as câmeras JT/T já mandam reconhecimento facial
-- (alertType 6, "Face Recognition Success" — catalogado na v4.18.4) logo após
-- a ignição ligar e periodicamente durante a viagem. Até aqui esse dado era
-- gravado ponto a ponto, sem memória — nada fazia o motorista reconhecido
-- "valer" para os pontos GPS e alarmes seguintes. Esta migração cria a tabela
-- que sustenta a regra pedida: o motorista fica "corrente" para o veículo até
-- a câmera reconhecer outro OU até a ignição desligar.
--
-- Mesmo padrão de `device_installations` (v4.11.0): uma linha ABERTA
-- (`ended_at IS NULL`) por veículo. MySQL não tem índice único parcial, então
-- a invariante "só uma sessão aberta por veículo" é garantida em PHP, com
-- `SELECT ... FOR UPDATE` dentro da MESMA transação de lote que o
-- WebhookHandler já abre por request (não é uma transação nova) — ver
-- includes/functions.php (driver_session_recognize/close_on_ign_off/
-- handle_acc_reading) e handlers/pushgps.php, pushhb.php, pushalarm.php.
--
-- `end_reason` regista COMO a sessão fechou — útil pra auditoria e para o
-- backfill (scripts/backfill_driver_sessions.php) marcar sessões
-- reconstruídas de forma diferente das fechadas em tempo real, se precisar.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `driver_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL COMMENT 'Snapshot do dono do veículo no momento do reconhecimento',
  `driver_id` bigint unsigned NOT NULL,
  `imei` varchar(20) NOT NULL COMMENT 'Câmera que reconheceu (auditoria)',
  `started_at` datetime NOT NULL,
  `last_confirmed_at` datetime NOT NULL COMMENT 'Último AFIS que confirmou o MESMO motorista',
  `ended_at` datetime DEFAULT NULL COMMENT 'NULL = sessão corrente',
  `end_reason` enum('driver_changed','ign_off','device_uninstalled') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ds_vehicle_open` (`vehicle_id`,`ended_at`),
  KEY `idx_ds_driver` (`driver_id`),
  CONSTRAINT `fk_ds_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ds_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Motorista corrente por veículo (AFIS) — aberta até troca de motorista, ACC OFF, ou desinstalação da câmera';

-- Sem ALTER em alarms/gps_data/occurrences/trips — as colunas de motorista já
-- existem nas quatro desde v4.0.0/v4.8.0; o que faltava era QUEM as alimenta.

-- ============================================================
-- Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.19.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.19.0', last_update = NOW();

-- ============================================================
-- Conferência
-- ============================================================
SELECT 'driver_sessions criada' AS conferencia;
SELECT COUNT(*) AS total_tabelas FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'driver_sessions';

SELECT 'Migracao v4.19.0 concluida' AS status;
