-- ============================================================
-- Migração v4.6.0 — Relatórios operacionais
-- ============================================================
-- Fase 3 de docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md.
--
-- Entrega: Parada, Ociosidade, Ignição, Excesso de Velocidade e Status da
-- Frota. Os quatro primeiros são RECORTES DA MESMA segmentação de estado —
-- calcular cada um na hora da consulta significaria varrer gps_data quatro
-- vezes com a lógica duplicada em quatro handlers.
--
--   gps_data ──▶ state_builder.php ──▶ device_state_segments ──┬─▶ /relatorios/paradas
--                                                              ├─▶ /relatorios/ociosidade
--                                                              ├─▶ /relatorios/ignicao
--                                                              └─▶ /relatorios/status-frota
--            └──────────────────────▶ speeding_events         ───▶ /relatorios/velocidade
--
-- Excesso de velocidade fica em tabela própria porque não é um estado do
-- veículo e sim um evento com limiar próprio (por equipamento, por cliente
-- ou global).
--
-- Idempotente: pode rodar mais de uma vez sem efeito colateral.
-- ============================================================

-- ── Helpers idempotentes ────────────────────────────────────
DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;
DELIMITER //
CREATE PROCEDURE `add_column_if_not_exists`(IN p_table VARCHAR(128), IN p_column VARCHAR(128), IN p_definition TEXT)
BEGIN
    DECLARE col_count INT;
    SELECT COUNT(*) INTO col_count FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column;
    IF col_count = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS `create_index_if_not_exists`;
DELIMITER //
CREATE PROCEDURE `create_index_if_not_exists`(IN p_table VARCHAR(128), IN p_index VARCHAR(128), IN p_columns TEXT)
BEGIN
    DECLARE idx_count INT;
    SELECT COUNT(*) INTO idx_count FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = p_index;
    IF idx_count = 0 THEN
        SET @sql = CONCAT('CREATE INDEX `', p_index, '` ON `', p_table, '` ', p_columns);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

-- ============================================================
-- 1. device_state_segments — a linha do tempo do veículo
-- ============================================================
-- Um segmento por período contínuo no mesmo estado. Os segmentos de um
-- equipamento são CONTÍGUOS e não se sobrepõem: o `ended_at` de um é o
-- `started_at` do seguinte. É essa propriedade que faz a soma das durações
-- de um dia fechar em 86.400 s — e é o teste que pega furo de segmentação.
--
-- Estados (includes/fleet_state.php é a fonte única das regras):
--   movimento — acc = 1 e speed  > STOP_SPEED_KMH (3 km/h)
--   ocioso    — acc = 1 e speed <= STOP_SPEED_KMH (motor ligado, parado)
--   parado    — acc = 0 (ignição desligada)
--   offline   — buraco entre pontos >= OFFLINE_GAP_SECONDS (30 min)
--
-- O último segmento de cada equipamento fica com `ended_at IS NULL` (estado
-- em curso) e é reavaliado na rodada seguinte — sem isso, um estado ainda em
-- andamento seria fatiado em pedaços a cada execução do cron.
--
-- uk_dss_imei_start é o que torna a reexecução inofensiva: o worker regrava
-- o segmento em curso com ON DUPLICATE KEY UPDATE em vez de criar um novo.
CREATE TABLE IF NOT EXISTS `device_state_segments` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `customer_id` bigint unsigned DEFAULT NULL,
    `state` enum('movimento','ocioso','parado','offline') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `started_at` datetime NOT NULL COMMENT 'UTC — gps_time do ponto que abriu o estado',
    `ended_at` datetime DEFAULT NULL COMMENT 'UTC — NULL = estado em curso',
    `duration_s` int unsigned DEFAULT NULL COMMENT 'NULL enquanto o segmento está aberto',
    `start_lat` decimal(10,8) DEFAULT NULL,
    `start_lng` decimal(11,8) DEFAULT NULL,
    `end_lat` decimal(10,8) DEFAULT NULL,
    `end_lng` decimal(11,8) DEFAULT NULL,
    `distance_km` decimal(10,3) DEFAULT NULL COMMENT 'Percorrido no segmento (haversine ponto a ponto)',
    `max_speed` decimal(6,2) DEFAULT NULL,
    `point_count` int unsigned NOT NULL DEFAULT 0 COMMENT 'Pontos que sustentam o segmento',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dss_imei_start` (`imei`,`started_at`),
    KEY `idx_dss_customer_time` (`customer_id`,`started_at`),
    KEY `idx_dss_state_time` (`customer_id`,`state`,`started_at`),
    KEY `idx_dss_open` (`imei`,`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Segmentos de estado do veículo (movimento/ocioso/parado/offline)';

-- ============================================================
-- 2. speeding_events — excesso de velocidade
-- ============================================================
-- Pontos consecutivos acima do limite viram UM evento; fecha quando a
-- velocidade cai abaixo do limite ou quando há buraco de dados. Piso de
-- MIN_SPEEDING_POINTS (2) pontos para descartar spike de GPS: um único ponto
-- de 140 km/h no meio de uma via de 60 é erro de leitura, não infração.
--
-- `limit_kmh` é gravado no evento, e não consultado depois: o limite pode
-- mudar amanhã, e o relatório precisa dizer contra qual limite a infração
-- foi apurada.
CREATE TABLE IF NOT EXISTS `speeding_events` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `customer_id` bigint unsigned DEFAULT NULL,
    `started_at` datetime NOT NULL COMMENT 'UTC — primeiro ponto acima do limite',
    `ended_at` datetime DEFAULT NULL COMMENT 'UTC — NULL = ainda em curso',
    `duration_s` int unsigned DEFAULT NULL,
    `max_speed` decimal(6,2) NOT NULL,
    `avg_speed` decimal(6,2) DEFAULT NULL,
    `limit_kmh` smallint unsigned NOT NULL COMMENT 'Limite vigente quando o evento foi apurado',
    `start_lat` decimal(10,8) DEFAULT NULL,
    `start_lng` decimal(11,8) DEFAULT NULL,
    `max_lat` decimal(10,8) DEFAULT NULL COMMENT 'Onde a velocidade máxima foi registrada',
    `max_lng` decimal(11,8) DEFAULT NULL,
    `point_count` int unsigned NOT NULL DEFAULT 0,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_spd_imei_start` (`imei`,`started_at`),
    KEY `idx_spd_customer_time` (`customer_id`,`started_at`),
    KEY `idx_spd_open` (`imei`,`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Eventos de excesso de velocidade';

-- ============================================================
-- 3. Limite de velocidade — precedência equipamento → cliente → global
-- ============================================================
-- NULL em devices.speed_limit_kmh herda do cliente; NULL em
-- customers.default_speed_limit_kmh cai no padrão global de
-- includes/fleet_state.php (DEFAULT_SPEED_LIMIT_KMH = 80 km/h).
CALL add_column_if_not_exists('devices', 'speed_limit_kmh',
    "smallint unsigned DEFAULT NULL COMMENT 'Limite de velocidade em km/h (NULL = herda do cliente)' AFTER `branch_id`");

CALL add_column_if_not_exists('customers', 'default_speed_limit_kmh',
    "smallint unsigned DEFAULT NULL COMMENT 'Limite de velocidade padrão da frota em km/h' AFTER `faceid_enabled`");

-- ============================================================
-- 4. Índice de leitura do worker
-- ============================================================
-- O state_builder lê gps_data por (imei, gps_time ASC). O idx_imei_time
-- existente já cobre — declarado aqui só para a migração ser autossuficiente
-- em base que tenha perdido o índice.
CALL create_index_if_not_exists('gps_data', 'idx_imei_time', '(`imei`,`gps_time`)');

-- ── Limpeza dos helpers ─────────────────────────────────────
DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;
DROP PROCEDURE IF EXISTS `create_index_if_not_exists`;

-- ============================================================
-- Versão do sistema
-- ============================================================
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '4.6.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '4.6.0', `last_update` = NOW();
