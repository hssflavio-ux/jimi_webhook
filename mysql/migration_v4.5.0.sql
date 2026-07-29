-- ============================================================
-- Migração v4.5.0 — Geocercas e POIs
-- ============================================================
-- Fase 2 de docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md.
--
-- Entrega: cercas desenhadas na plataforma (círculo ou polígono), vinculadas
-- a equipamentos, gerando eventos de entrada/saída, alerta pelo motor de
-- notificações (v4.4.0) e relatório de permanência.
--
-- Peças:
--   geofences        — a cerca (geometria, alerta, cor)
--   geofence_devices — quais equipamentos são avaliados contra a cerca
--   geofence_state   — dentro/fora atual + marca-d'água de leitura incremental
--   geofence_events  — transições entrada/saída (fonte do relatório)
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

-- ============================================================
-- 1. geofences — a cerca
-- ============================================================
-- shape='circulo'  → center_lat/center_lng/radius_m
-- shape='poligono' → polygon = [[lat,lng],[lat,lng],...] (mínimo 3 vértices)
--
-- A bounding box é calculada e gravada ao salvar (handlers/geocercas.php),
-- não a cada avaliação: é o pré-filtro barato que o worker usa para
-- descartar a esmagadora maioria dos pares ponto×cerca antes de qualquer
-- trigonometria.
CREATE TABLE IF NOT EXISTS `geofences` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `kind` enum('cerca','poi') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cerca'
        COMMENT 'cerca = área monitorada | poi = ponto de interesse (base, cliente, posto)',
    `shape` enum('circulo','poligono') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'circulo',
    `center_lat` decimal(10,8) DEFAULT NULL,
    `center_lng` decimal(11,8) DEFAULT NULL,
    `radius_m` int unsigned DEFAULT NULL COMMENT 'Raio em metros (shape=circulo)',
    `polygon` json DEFAULT NULL COMMENT 'Array [[lat,lng],...] quando shape=poligono',
    `bbox_min_lat` decimal(10,8) DEFAULT NULL COMMENT 'Pré-filtro barato (calculado ao salvar)',
    `bbox_max_lat` decimal(10,8) DEFAULT NULL,
    `bbox_min_lng` decimal(11,8) DEFAULT NULL,
    `bbox_max_lng` decimal(11,8) DEFAULT NULL,
    `alert_on` enum('entrada','saida','ambos','nenhum') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ambos'
        COMMENT 'Qual transição gera notificação (o evento é sempre gravado)',
    `alert_emails` json DEFAULT NULL COMMENT 'Array de até 3 destinatários (o sino não depende disto)',
    `color` varchar(9) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#0052ff',
    `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_by` bigint unsigned DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gf_customer` (`customer_id`,`is_active`),
    CONSTRAINT `fk_gf_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gf_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Geocercas e pontos de interesse';

-- ============================================================
-- 2. geofence_devices — vínculo cerca × equipamento
-- ============================================================
-- Sem vínculo a cerca não é avaliada por ninguém: o worker parte DESTA
-- tabela, não da frota inteira. É o que mantém o custo proporcional ao que
-- realmente foi configurado.
CREATE TABLE IF NOT EXISTS `geofence_devices` (
    `geofence_id` bigint unsigned NOT NULL,
    `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`geofence_id`,`imei`),
    KEY `idx_gfd_imei` (`imei`),
    CONSTRAINT `fk_gfd_fence` FOREIGN KEY (`geofence_id`) REFERENCES `geofences`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Equipamentos monitorados por cada geocerca';

-- ============================================================
-- 3. geofence_state — dentro/fora atual + marca-d'água
-- ============================================================
-- last_gps_time é a marca-d'água da leitura incremental: o worker só lê
-- pontos posteriores a ela. Sem isto cada rodada reprocessaria o histórico.
CREATE TABLE IF NOT EXISTS `geofence_state` (
    `geofence_id` bigint unsigned NOT NULL,
    `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `is_inside` tinyint(1) NOT NULL DEFAULT 0,
    `last_gps_time` datetime DEFAULT NULL COMMENT 'UTC — último ponto avaliado',
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`geofence_id`,`imei`),
    CONSTRAINT `fk_gfs_fence` FOREIGN KEY (`geofence_id`) REFERENCES `geofences`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Estado atual do equipamento em relação à cerca';

-- ============================================================
-- 4. geofence_events — as transições
-- ============================================================
-- uk_gfe_dedupe torna o worker seguro para reexecução: rodar duas vezes
-- sobre a mesma janela não duplica evento (o INSERT usa IGNORE).
CREATE TABLE IF NOT EXISTS `geofence_events` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `geofence_id` bigint unsigned NOT NULL,
    `customer_id` bigint unsigned DEFAULT NULL,
    `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `event_type` enum('entrada','saida') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `event_time` datetime NOT NULL COMMENT 'UTC — gps_time do ponto que causou a transição',
    `latitude` decimal(10,8) NOT NULL,
    `longitude` decimal(11,8) NOT NULL,
    `speed` decimal(6,2) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_gfe_dedupe` (`geofence_id`,`imei`,`event_time`),
    KEY `idx_gfe_customer_time` (`customer_id`,`event_time`),
    KEY `idx_gfe_fence_imei_time` (`geofence_id`,`imei`,`event_time`),
    CONSTRAINT `fk_gfe_fence` FOREIGN KEY (`geofence_id`) REFERENCES `geofences`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Entradas e saídas de geocerca';

-- ── Limpeza dos helpers ─────────────────────────────────────
DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;

-- ============================================================
-- Versão do sistema
-- ============================================================
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '4.5.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '4.5.0', `last_update` = NOW();
