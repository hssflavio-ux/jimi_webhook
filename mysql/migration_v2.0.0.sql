-- ============================================================
-- JIMI Webhook System v2.0.0 — Migration Script
-- Database: jimi_tracker
-- Description: Novas colunas + tabela command_responses
-- Execute: mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql
-- ============================================================

USE `jimi_tracker`;

-- ------------------------------------------------------------
-- 1. Novas colunas: heartbeats (pushhb — Seção 1.2)
-- ------------------------------------------------------------
ALTER TABLE `heartbeats`
    ADD COLUMN `acc` tinyint(1) DEFAULT NULL COMMENT '0=ACC_OFF, 1=ACC_ON' AFTER `gsm_signal`,
    ADD COLUMN `oil_ele` tinyint(1) DEFAULT NULL COMMENT '0=Fuel/electricity connected, 1=disconnected' AFTER `acc`,
    ADD COLUMN `gps_pos` tinyint(1) DEFAULT NULL COMMENT '0=GPS not positioning, 1=GPS positioning' AFTER `oil_ele`,
    ADD COLUMN `remote_lock` tinyint(1) DEFAULT NULL COMMENT '0=No remote locking, 1=Remote locking' AFTER `gps_pos`,
    ADD COLUMN `power_status` tinyint(1) DEFAULT NULL COMMENT '0=No power charging, 1=Power charging' AFTER `remote_lock`,
    ADD COLUMN `fortify` tinyint(1) DEFAULT NULL COMMENT '0=Defense deactivated, 1=Defense activated' AFTER `power_status`;

-- ------------------------------------------------------------
-- 2. Novas colunas: gps_data (pushgps — Seção 1.3)
-- ------------------------------------------------------------
ALTER TABLE `gps_data`
    ADD COLUMN `post_type` tinyint DEFAULT NULL COMMENT '1=GPS, 2=LBS, 3=WiFi' AFTER `altitude`,
    ADD COLUMN `post_method` tinyint DEFAULT NULL COMMENT 'Upload mode (0x00-0x0F)' AFTER `post_type`,
    ADD COLUMN `undecoded_gps_add_info` text DEFAULT NULL COMMENT 'Base64 encoded additional GPS info' AFTER `post_method`,
    ADD COLUMN `driver_license_status` int DEFAULT NULL COMMENT 'Driver license status' AFTER `undecoded_gps_add_info`,
    ADD COLUMN `driver_license` varchar(100) DEFAULT NULL COMMENT 'Driver license data' AFTER `driver_license_status`,
    ADD COLUMN `buzzer_alarm_status` tinyint DEFAULT NULL COMMENT '0=No alarm, 1=Alarm' AFTER `driver_license`,
    ADD COLUMN `credit_card_status` tinyint DEFAULT NULL COMMENT '0=Other, 1=Card swiping detected' AFTER `buzzer_alarm_status`,
    ADD COLUMN `door_status` tinyint DEFAULT NULL COMMENT '0=Closed, 1=Open' AFTER `credit_card_status`,
    ADD COLUMN `sos_status` tinyint DEFAULT NULL COMMENT '0=Not triggered, 1=Triggered' AFTER `door_status`,
    ADD COLUMN `temperature` decimal(5,2) DEFAULT NULL COMMENT 'Temperature in Celsius' AFTER `sos_status`,
    ADD COLUMN `transparent_data` text DEFAULT NULL COMMENT 'Pass-through data (HEX string)' AFTER `temperature`;

-- ------------------------------------------------------------
-- 3. Nova tabela: command_responses
--    Referenciada por pushinstructresponse (Seção 1.16) e commandstatus.php
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `command_responses` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `instruct_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `msg_type` tinyint DEFAULT NULL COMMENT '1=async, 2=offline',
    `command_content` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `response_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'success, failed',
    `server_flag_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `remark` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `execute_time` datetime DEFAULT NULL,
    `server_time` datetime DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cr_imei` (`imei`),
    KEY `idx_cr_time` (`created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Respostas de comandos assíncronos/offline (pushInstructResponse)';

-- ------------------------------------------------------------
-- 4. Atualizar stored procedure: update_device_stats_after_alarm
--    Agora aceita coordenadas opcionais (pushalarm v7.0 → v8.0)
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `update_device_stats_after_alarm`;
DELIMITER //
CREATE PROCEDURE `update_device_stats_after_alarm`(
    IN p_imei VARCHAR(20), 
    IN p_alarm_time DATETIME,
    IN p_lat DECIMAL(10,8),
    IN p_lon DECIMAL(11,8)
)
BEGIN
    INSERT INTO device_statistics (imei, total_alarm_count, is_online, updated_at)
    VALUES (p_imei, 1, 1, NOW())
    ON DUPLICATE KEY UPDATE
        total_alarm_count = total_alarm_count + 1, 
        is_online = 1,
        updated_at = NOW();

    -- Atualizar coordenadas se fornecidas
    IF p_lat IS NOT NULL AND p_lon IS NOT NULL THEN
        UPDATE device_statistics 
        SET last_latitude = p_lat, 
            last_longitude = p_lon, 
            last_gps_time = IF(p_alarm_time > COALESCE(last_gps_time, '2000-01-01'), p_alarm_time, last_gps_time)
        WHERE imei = p_imei;
    END IF;

    INSERT IGNORE INTO devices (imei, last_communication) VALUES (p_imei, NOW())
    ON DUPLICATE KEY UPDATE last_communication = NOW();
END//
DELIMITER ;

-- ------------------------------------------------------------
-- 5. Atualizar versão do sistema
-- ------------------------------------------------------------
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '2.0.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '2.0.0', `last_update` = NOW();
