-- ============================================================================
-- Migração v4.20.0 — Mapa de Risco ADAS/DMS (/mapa-risco)
-- ============================================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- O que entra:
--   1. alarm_types.risk_group — o COMPORTAMENTO de cada tipo ADAS/DMS, por
--      protocolo + código. O mesmo ato aparece com nomes diferentes nos dois
--      protocolos (uso de celular = JIMI 151 + JT/T 265-2 + 265-13), e o mapa
--      precisa somá-los. `is_diagnostic` não serve para separar comportamento
--      de equipamento: é 0 em todos os 45 tipos ADAS/DMS (medido em 13/09/2026).
--   2. risk_events     — uma linha por alerta que conta como risco.
--   3. risk_exposure   — o denominador: horas em movimento e km.
--   4. risk_day_state  — a jornada contínua passa de um dia para o outro.
--   5. Índices que o scripts/risk_builder.php precisa.
--
-- 🔴 A classificação é por CÓDIGO, nunca por nome. Renomear `alarm_name_pt`
-- já desligou o motor de ocorrências duas vezes (v4.8.3 → v4.8.6, e o 265-2
-- corrigido na v4.19.1). `NULL` = ainda não classificado: o risk_builder
-- registra WARNING e deixa o tipo fora do mapa, em vez de adivinhar.
--
-- As tabelas 2-4 são DERIVADAS e reconstruíveis a qualquer momento:
--   php scripts/risk_builder.php --desde=2026-06-10

SET time_zone = '+00:00';

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;
DROP PROCEDURE IF EXISTS `create_index_if_not_exists`;
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
-- 1. Comportamento de cada tipo ADAS/DMS
-- ============================================================
CALL add_column_if_not_exists('alarm_types', 'risk_group',
    "VARCHAR(40) NULL DEFAULT NULL COMMENT 'Comportamento no mapa de risco (includes/risk_map.php). excluido = fora do mapa; NULL = não classificado' AFTER `category`");

-- ADAS
UPDATE alarm_types SET risk_group = 'colisao_frontal'         WHERE (protocol, alarm_code) IN (('JIMI','204'), ('JIMI','229'), ('JTT','264-1'));
UPDATE alarm_types SET risk_group = 'colisao_pedestre'        WHERE (protocol, alarm_code) IN (('JIMI','207'), ('JTT','264-4'));
UPDATE alarm_types SET risk_group = 'distancia_insegura'      WHERE (protocol, alarm_code) IN (('JIMI','206'), ('JTT','264-3'));
UPDATE alarm_types SET risk_group = 'saida_faixa'             WHERE (protocol, alarm_code) IN (('JIMI','205'), ('JIMI','228'), ('JTT','264-2'));
UPDATE alarm_types SET risk_group = 'mudanca_faixa_frequente' WHERE (protocol, alarm_code) IN (('JTT','264-5'));
UPDATE alarm_types SET risk_group = 'excesso_placa'           WHERE (protocol, alarm_code) IN (('JTT','264-6'));
UPDATE alarm_types SET risk_group = 'obstaculo_frente'        WHERE (protocol, alarm_code) IN (('JTT','264-7'));

-- DMS
UPDATE alarm_types SET risk_group = 'uso_celular'             WHERE (protocol, alarm_code) IN (('JIMI','151'), ('JTT','265-2'), ('JTT','265-13'));
UPDATE alarm_types SET risk_group = 'fadiga'                  WHERE (protocol, alarm_code) IN (('JIMI','71'), ('JTT','265-1'));
UPDATE alarm_types SET risk_group = 'bocejo'                  WHERE (protocol, alarm_code) IN (('JIMI','160'));
UPDATE alarm_types SET risk_group = 'piscadas_frequentes'     WHERE (protocol, alarm_code) IN (('JIMI','140'));
-- 163 "Motorista de Cabeça Baixa" é postura de distração, não evento à parte.
UPDATE alarm_types SET risk_group = 'distracao'               WHERE (protocol, alarm_code) IN (('JIMI','143'), ('JIMI','163'), ('JTT','265-4'));
UPDATE alarm_types SET risk_group = 'cinto'                   WHERE (protocol, alarm_code) IN (('JIMI','132'), ('JIMI','167'), ('JTT','265-10'));
UPDATE alarm_types SET risk_group = 'fumando'                 WHERE (protocol, alarm_code) IN (('JIMI','154'), ('JTT','265-3'));
UPDATE alarm_types SET risk_group = 'bebendo_comendo'         WHERE (protocol, alarm_code) IN (('JIMI','170'));
UPDATE alarm_types SET risk_group = 'maos_fora_volante'       WHERE (protocol, alarm_code) IN (('JTT','265-12'));
-- "Rosto Não Detectado" (JIMI) e "Motorista não Detectado" (JT/T) são o mesmo ato.
UPDATE alarm_types SET risk_group = 'motorista_nao_detectado' WHERE (protocol, alarm_code) IN (('JIMI','148'), ('JTT','265-5'));
UPDATE alarm_types SET risk_group = 'conducao_prolongada'     WHERE (protocol, alarm_code) IN (('JIMI','199'), ('JTT','265-8'));

-- Fora do mapa: descrevem o equipamento ou a condição de leitura, não o
-- motorista (Óculos Escuros foi o 5º tipo mais frequente em 90 dias e inflaria
-- o risco sozinho), e os de severidade info, que são registro, não alerta.
UPDATE alarm_types SET risk_group = 'excluido' WHERE (protocol, alarm_code) IN (
    ('JTT','265-11'),                    -- Óculos Escuros
    ('JIMI','107'),                      -- Falha de Comunicação da Câmera
    ('JIMI','161'), ('JTT','265-6'),     -- Câmera Obstruída
    ('JIMI','162'),                      -- Erro de Alinhamento Facial
    ('JTT','3085'),                      -- Falha de Autenticação do Motorista
    ('JTT','264-16'), ('JTT','264-17'),  -- info: Reconhecimento de Placa, Captura Ativa
    ('JTT','265-16'), ('JTT','265-17'),  -- info: Captura Automática, Troca de Motorista
    ('JIMI','117')                       -- info: Fadiga Reconhecida
);

-- ============================================================
-- 2. risk_events — uma linha por alerta que conta
-- ============================================================
CREATE TABLE IF NOT EXISTS `risk_events` (
  `alarm_id` bigint unsigned NOT NULL COMMENT 'alarms.id',
  `customer_id` bigint unsigned NOT NULL COMMENT 'Snapshot gravado no alerta (alarms.customer_id)',
  `vehicle_id` bigint unsigned NOT NULL COMMENT 'Snapshot gravado no alerta (alarms.vehicle_id)',
  `driver_id` bigint unsigned DEFAULT NULL COMMENT 'drivers.id; NULL = não identificado',
  `imei` varchar(20) NOT NULL,
  `protocol` enum('JIMI','JTT') NOT NULL,
  `alarm_code` varchar(20) NOT NULL COMMENT 'Código do catálogo (composto no JT/T: 265-2)',
  `risk_group` varchar(40) NOT NULL,
  `risk_level` enum('baixo','medio','alto') NOT NULL COMMENT 'Perfil de ocorrências ATUAL do cliente',
  `weight` tinyint unsigned NOT NULL COMMENT 'baixo 1 · medio 3 · alto 5',
  `false_positive` tinyint(1) NOT NULL DEFAULT 0,
  `alarm_time` datetime NOT NULL COMMENT 'UTC',
  `brt_date` date NOT NULL,
  `brt_hour` tinyint unsigned NOT NULL,
  `brt_weekday` tinyint unsigned NOT NULL COMMENT '1 = domingo … 7 = sábado (mesma numeração do DAYOFWEEK)',
  `day_period` enum('madrugada','manha','tarde','noite') NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `cell_y` int DEFAULT NULL COMMENT 'Linha da grade de 1 km; NULL = coordenada inválida',
  `cell_x` int DEFAULT NULL,
  `speed` decimal(6,2) DEFAULT NULL,
  `speed_band` tinyint unsigned DEFAULT NULL,
  `continuous_s` int unsigned DEFAULT NULL COMMENT 'Direção contínua no instante do alerta',
  `continuous_band` tinyint unsigned DEFAULT NULL COMMENT 'NULL = sem ponto de GPS antes do alerta',
  `computed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`alarm_id`),
  KEY `idx_re_customer_date` (`customer_id`, `brt_date`),
  KEY `idx_re_vehicle_date` (`vehicle_id`, `brt_date`),
  KEY `idx_re_customer_cell` (`customer_id`, `cell_y`, `cell_x`),
  KEY `idx_re_driver_date` (`driver_id`, `brt_date`),
  KEY `idx_re_customer_code` (`customer_id`, `protocol`, `alarm_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mapa de risco: alertas ADAS/DMS enriquecidos (derivada — scripts/risk_builder.php)';

-- ============================================================
-- 3. risk_exposure — horas em movimento e km (o denominador)
-- ============================================================
CREATE TABLE IF NOT EXISTS `risk_exposure` (
  `customer_id` bigint unsigned NOT NULL,
  `vehicle_id` bigint unsigned NOT NULL,
  `brt_date` date NOT NULL,
  `brt_hour` tinyint unsigned NOT NULL,
  `cell_y` int NOT NULL,
  `cell_x` int NOT NULL,
  `speed_band` tinyint unsigned NOT NULL,
  `continuous_band` tinyint unsigned NOT NULL,
  `driver_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '0 = motorista não identificado',
  `driving_s` int unsigned NOT NULL DEFAULT 0 COMMENT 'Ignição ligada acima de STOP_SPEED_KMH',
  `idle_s` int unsigned NOT NULL DEFAULT 0 COMMENT 'Ignição ligada, parado',
  `km` decimal(10,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (`customer_id`, `vehicle_id`, `brt_date`, `brt_hour`, `cell_y`, `cell_x`, `speed_band`, `continuous_band`, `driver_id`),
  KEY `idx_rx_vehicle_date` (`vehicle_id`, `brt_date`),
  KEY `idx_rx_customer_date` (`customer_id`, `brt_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mapa de risco: exposição por veículo/dia/hora/célula (derivada — scripts/risk_builder.php)';

-- ============================================================
-- 4. risk_day_state — jornada contínua na virada do dia
-- ============================================================
CREATE TABLE IF NOT EXISTS `risk_day_state` (
  `vehicle_id` bigint unsigned NOT NULL,
  `brt_date` date NOT NULL,
  `carry_continuous_s` int unsigned DEFAULT NULL COMMENT 'Contador de direção contínua no último ponto do dia',
  `carry_off_since` datetime DEFAULT NULL COMMENT 'UTC — início da sequência de ignição desligada em curso',
  `last_point_time` datetime DEFAULT NULL COMMENT 'UTC',
  `last_acc` tinyint DEFAULT NULL,
  `computed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`vehicle_id`, `brt_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mapa de risco: estado da jornada ao fim de cada dia (derivada — scripts/risk_builder.php)';

-- ============================================================
-- 5. Índices para o risk_builder
-- ============================================================
-- Os de v4.12.0 são só por vehicle_id; o builder lê o dia do veículo por período.
CALL create_index_if_not_exists('gps_data', 'idx_gps_vehicle_time', '(`vehicle_id`, `gps_time`)');
CALL create_index_if_not_exists('alarms', 'idx_alarms_vehicle_time', '(`vehicle_id`, `alarm_time`)');
-- Falso positivo marcado depois é achado por occurrences.updated_at.
CALL create_index_if_not_exists('occurrences', 'idx_occ_updated_at', '(`updated_at`)');

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;
DROP PROCEDURE IF EXISTS `create_index_if_not_exists`;

-- ============================================================
-- Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.20.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.20.0', last_update = NOW();

-- ============================================================
-- Conferência (aparece no log do deploy)
-- ============================================================
SELECT CONCAT(COUNT(*), ' tipo(s) ADAS/DMS sem risk_group (esperado: 0 — tipo sem classificação fica FORA do mapa)') AS conferencia
  FROM alarm_types
 WHERE category IN ('DMS', 'ADAS') AND risk_group IS NULL;

SELECT protocol, alarm_code, alarm_name_pt AS sem_classificacao
  FROM alarm_types
 WHERE category IN ('DMS', 'ADAS') AND risk_group IS NULL
 ORDER BY protocol, alarm_code;

SELECT risk_group, COUNT(*) AS tipos
  FROM alarm_types
 WHERE risk_group IS NOT NULL
 GROUP BY risk_group
 ORDER BY risk_group;

SELECT 'Migracao v4.20.0 concluida — rode: php scripts/risk_builder.php --desde=2026-06-10' AS status;
