-- ============================================================================
-- JIMI Webhook System — Migração v4.12.0
--
-- Fase 2 da correção do fluxo chip → câmera → veículo (Fase 1: v4.11.0).
--
-- ── O PROBLEMA ──────────────────────────────────────────────────────────────
-- `gps_data`, `alarms`, `events`, `heartbeats` e `media_files` não têm
-- `customer_id` próprio — são lidos sempre pelo dono ATUAL da câmera
-- (`devices.customer_id`, via JOIN). Isso significa que transferir uma
-- câmera de veículo ainda reatribui retroativamente o dono de TODO o
-- histórico de telemetria daquele IMEI: o dono antigo perde acesso ao que já
-- era dele, o novo dono vê o que nunca foi dele. `occurrences` já escapa
-- disso — grava `customer_id` como snapshot na criação — e é esse o padrão
-- que esta migração generaliza para as demais tabelas, usando
-- `device_installations` (v4.11.0) como fonte.
--
-- Esta migração só prepara o dado (colunas + backfill). A troca de leitura
-- (relatórios, painel, `/ativos/{id}`) está no código desta versão, não aqui.
--
-- ⚠️ `gps_data` pode ter muitas linhas em produção — o UPDATE de backfill
-- roda uma vez (é idempotente: só afeta `customer_id IS NULL`), mas em base
-- grande considere rodar fora do horário de pico.
--
-- O backfill é EXATO, não aproximado: como a v4.11.0 acabou de nascer, cada
-- `devices` tem no máximo UMA `device_installations` (a aberta) — nenhuma
-- câmera trocou de veículo ainda neste sistema, então toda linha histórica
-- de um IMEI pertenceu mesmo ao único veículo que esse IMEI já teve.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── 1. Colunas novas (customer_id + vehicle_id nas 5 tabelas de telemetria;
--       só vehicle_id em occurrences, que já tem customer_id) ──────────────
SET @c := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'gps_data' AND column_name = 'customer_id');
SET @sql := IF(@c = 0,
    "ALTER TABLE `gps_data` ADD COLUMN `customer_id` BIGINT UNSIGNED NULL AFTER `imei`,
                             ADD COLUMN `vehicle_id` BIGINT UNSIGNED NULL AFTER `customer_id`,
                             ADD INDEX `idx_gps_customer` (`customer_id`),
                             ADD INDEX `idx_gps_vehicle` (`vehicle_id`)",
    "SELECT 'gps_data.customer_id ja existe' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'alarms' AND column_name = 'customer_id');
SET @sql := IF(@c = 0,
    "ALTER TABLE `alarms` ADD COLUMN `customer_id` BIGINT UNSIGNED NULL AFTER `imei`,
                           ADD COLUMN `vehicle_id` BIGINT UNSIGNED NULL AFTER `customer_id`,
                           ADD INDEX `idx_alarms_customer` (`customer_id`),
                           ADD INDEX `idx_alarms_vehicle` (`vehicle_id`)",
    "SELECT 'alarms.customer_id ja existe' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'events' AND column_name = 'customer_id');
SET @sql := IF(@c = 0,
    "ALTER TABLE `events` ADD COLUMN `customer_id` BIGINT UNSIGNED NULL AFTER `imei`,
                           ADD COLUMN `vehicle_id` BIGINT UNSIGNED NULL AFTER `customer_id`,
                           ADD INDEX `idx_events_customer` (`customer_id`),
                           ADD INDEX `idx_events_vehicle` (`vehicle_id`)",
    "SELECT 'events.customer_id ja existe' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'heartbeats' AND column_name = 'customer_id');
SET @sql := IF(@c = 0,
    "ALTER TABLE `heartbeats` ADD COLUMN `customer_id` BIGINT UNSIGNED NULL AFTER `imei`,
                               ADD COLUMN `vehicle_id` BIGINT UNSIGNED NULL AFTER `customer_id`,
                               ADD INDEX `idx_hb_customer` (`customer_id`),
                               ADD INDEX `idx_hb_vehicle` (`vehicle_id`)",
    "SELECT 'heartbeats.customer_id ja existe' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'media_files' AND column_name = 'customer_id');
SET @sql := IF(@c = 0,
    "ALTER TABLE `media_files` ADD COLUMN `customer_id` BIGINT UNSIGNED NULL AFTER `imei`,
                                ADD COLUMN `vehicle_id` BIGINT UNSIGNED NULL AFTER `customer_id`,
                                ADD INDEX `idx_media_customer` (`customer_id`),
                                ADD INDEX `idx_media_vehicle` (`vehicle_id`)",
    "SELECT 'media_files.customer_id ja existe' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'occurrences' AND column_name = 'vehicle_id');
SET @sql := IF(@c = 0,
    "ALTER TABLE `occurrences` ADD COLUMN `vehicle_id` BIGINT UNSIGNED NULL AFTER `customer_id`,
                                ADD INDEX `idx_occ_vehicle` (`vehicle_id`)",
    "SELECT 'occurrences.vehicle_id ja existe' AS status");
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Backfill — exato, ver cabeçalho. Idempotente (só afeta NULL). ───────
UPDATE `gps_data` t
  JOIN `devices` d ON d.imei = t.imei
  JOIN `device_installations` di ON di.device_id = d.id AND di.removed_at IS NULL
   SET t.customer_id = di.customer_id, t.vehicle_id = di.vehicle_id
 WHERE t.customer_id IS NULL;

UPDATE `alarms` t
  JOIN `devices` d ON d.imei = t.imei
  JOIN `device_installations` di ON di.device_id = d.id AND di.removed_at IS NULL
   SET t.customer_id = di.customer_id, t.vehicle_id = di.vehicle_id
 WHERE t.customer_id IS NULL;

UPDATE `events` t
  JOIN `devices` d ON d.imei = t.imei
  JOIN `device_installations` di ON di.device_id = d.id AND di.removed_at IS NULL
   SET t.customer_id = di.customer_id, t.vehicle_id = di.vehicle_id
 WHERE t.customer_id IS NULL;

UPDATE `heartbeats` t
  JOIN `devices` d ON d.imei = t.imei
  JOIN `device_installations` di ON di.device_id = d.id AND di.removed_at IS NULL
   SET t.customer_id = di.customer_id, t.vehicle_id = di.vehicle_id
 WHERE t.customer_id IS NULL;

UPDATE `media_files` t
  JOIN `devices` d ON d.imei = t.imei
  JOIN `device_installations` di ON di.device_id = d.id AND di.removed_at IS NULL
   SET t.customer_id = di.customer_id, t.vehicle_id = di.vehicle_id
 WHERE t.customer_id IS NULL;

UPDATE `occurrences` t
  JOIN `devices` d ON d.imei = t.imei
  JOIN `device_installations` di ON di.device_id = d.id AND di.removed_at IS NULL
   SET t.vehicle_id = di.vehicle_id
 WHERE t.vehicle_id IS NULL;

SELECT
    (SELECT COUNT(*) FROM gps_data WHERE customer_id IS NULL) AS gps_sem_dono,
    (SELECT COUNT(*) FROM alarms WHERE customer_id IS NULL) AS alarms_sem_dono,
    (SELECT COUNT(*) FROM events WHERE customer_id IS NULL) AS events_sem_dono,
    (SELECT COUNT(*) FROM heartbeats WHERE customer_id IS NULL) AS heartbeats_sem_dono,
    (SELECT COUNT(*) FROM media_files WHERE customer_id IS NULL) AS media_sem_dono;
-- "sem_dono" > 0 é esperado para IMEI órfão (sem customer_id em `devices`) ou
-- sem instalação — mesmo comportamento que `occurrences.customer_id` NULL já
-- tinha antes desta migração.
