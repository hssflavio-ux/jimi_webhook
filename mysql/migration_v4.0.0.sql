-- ============================================================
-- JIMI Webhook System v4.0.0 — YUV Parity Migration
-- Database: jimi_tracker
-- Description: Novas tabelas (branches, drivers, sim_cards,
--     permission_groups, occurrence_configs, occurrence_config_params,
--     occurrences, occurrence_events, trips, jobs, geocode_cache,
--     impersonation_log, checklist_configs, checklist_items,
--     checklist_responses) + alterações em users, customers, devices,
--     media_files + índices + seeds.
-- Execute: mysql -u root -p jimi_tracker < mysql/migration_v4.0.0.sql
-- ============================================================

USE `jimi_tracker`;

-- ------------------------------------------------------------
-- Auxiliares idempotentes (herdados do padrão v3.1.0)
-- ------------------------------------------------------------
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
-- 1. NOVAS TABELAS
-- ============================================================

-- 1.1 Filial (nível abaixo de customer)
CREATE TABLE IF NOT EXISTS `branches` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_branch_customer` (`customer_id`),
    CONSTRAINT `fk_branch_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Filiais de clientes';

-- 1.2 Motorista + compliance + identificação (FaceID)
CREATE TABLE IF NOT EXISTS `drivers` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `branch_id` bigint unsigned DEFAULT NULL,
    `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `birth_date` date DEFAULT NULL,
    `cnh_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Número da CNH',
    `cnh_category` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Categoria da CNH (A, B, AB, etc.)',
    `cnh_expires_at` date DEFAULT NULL COMMENT 'Vencimento da CNH',
    `tox_exam_expires_at` date DEFAULT NULL COMMENT 'Vencimento do exame toxicológico',
    `identifier` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Vínculo FaceID/RFID para identificação do motorista',
    `photo_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_driver_customer` (`customer_id`),
    KEY `idx_driver_branch` (`branch_id`),
    KEY `idx_driver_identifier` (`identifier`),
    CONSTRAINT `fk_driver_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_driver_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Motoristas com dados de compliance e identificação FaceID';

-- 1.3 Chip/SIM
CREATE TABLE IF NOT EXISTS `sim_cards` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned DEFAULT NULL,
    `carrier` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Operadora (Vivo, Claro, TIM, etc.)',
    `msisdn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Número do telefone',
    `iccid` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ICCID do chip',
    `imei` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IMEI do device vinculado (FK lógico)',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sim_customer` (`customer_id`),
    KEY `idx_sim_imei` (`imei`),
    KEY `idx_sim_iccid` (`iccid`),
    CONSTRAINT `fk_sim_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Chips SIM vinculados a devices';

-- 1.4 Grupos de permissão (RBAC)
CREATE TABLE IF NOT EXISTS `permission_groups` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `user_type` enum('revendedor','cliente') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cliente',
    `permissions` json DEFAULT NULL COMMENT 'Matriz tela→ações: {"resumo":["view"],"rastreamento":["view"],...}',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pg_usertype` (`user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Grupos de permissão RBAC';

-- 1.5 Perfis de configuração de ocorrência (motor de regras)
CREATE TABLE IF NOT EXISTS `occurrence_configs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Perfil padrão do sistema',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfis de configuração de ocorrências';

CREATE TABLE IF NOT EXISTS `occurrence_config_params` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `config_id` bigint unsigned NOT NULL,
    `alarm_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Código do alarme Jimi ou nome do tipo',
    `generates_occurrence` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Este alarme gera ocorrência?',
    `risk` enum('baixo','medio','alto') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'baixo',
    `threshold` int DEFAULT NULL COMMENT 'Janela de agrupamento em minutos (NULL = usa default 10)',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_config_alarm` (`config_id`, `alarm_type`),
    KEY `idx_ocp_alarmtype` (`alarm_type`),
    CONSTRAINT `fk_ocp_config` FOREIGN KEY (`config_id`) REFERENCES `occurrence_configs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Parâmetros de cada tipo de alarme por perfil';

-- 1.6 Ocorrências (núcleo DMS)
CREATE TABLE IF NOT EXISTS `occurrences` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `branch_id` bigint unsigned DEFAULT NULL,
    `imei` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `driver_id` bigint unsigned DEFAULT NULL COMMENT 'Motorista identificado (FaceID, se habilitado)',
    `alarm_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `risk` enum('baixo','medio','alto') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'baixo',
    `status` enum('aguardando','em_tratativa','resolvida','descartada') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aguardando',
    `false_positive` tinyint(1) NOT NULL DEFAULT 0,
    `first_alarm_at` datetime NOT NULL COMMENT 'Timestamp do primeiro alarme agrupado',
    `last_alarm_at` datetime NOT NULL COMMENT 'Timestamp do alarme mais recente',
    `alarm_count` int NOT NULL DEFAULT 1 COMMENT 'Quantos alarmes foram agrupados',
    `media_file_id` bigint unsigned DEFAULT NULL COMMENT 'Vínculo com o vídeo/mídia do evento',
    `treated_by` bigint unsigned DEFAULT NULL COMMENT 'Usuário que tratou a ocorrência',
    `treated_at` datetime DEFAULT NULL,
    `treatment_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_occ_customer_status` (`customer_id`, `status`, `last_alarm_at`),
    KEY `idx_occ_imei_type` (`imei`, `alarm_type`, `last_alarm_at`),
    KEY `idx_occ_driver` (`driver_id`),
    KEY `idx_occ_branch` (`branch_id`),
    KEY `idx_occ_treated_by` (`treated_by`),
    CONSTRAINT `fk_occ_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_occ_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_occ_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_occ_treated_by` FOREIGN KEY (`treated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ocorrências DMS — núcleo do motor de gestão de comportamento';

CREATE TABLE IF NOT EXISTS `occurrence_events` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `occurrence_id` bigint unsigned NOT NULL,
    `alarm_id` bigint unsigned NOT NULL COMMENT 'FK para a tabela alarms',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_oe_occurrence` (`occurrence_id`),
    KEY `idx_oe_alarm` (`alarm_id`),
    CONSTRAINT `fk_oe_occurrence` FOREIGN KEY (`occurrence_id`) REFERENCES `occurrences`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Alarmes agrupados em cada ocorrência';

-- 1.7 Viagens (relatório de deslocamento)
CREATE TABLE IF NOT EXISTS `trips` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `imei` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `driver_id` bigint unsigned DEFAULT NULL,
    `started_at` datetime NOT NULL,
    `start_lat` decimal(9,6) DEFAULT NULL,
    `start_lng` decimal(9,6) DEFAULT NULL,
    `start_addr` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `ended_at` datetime DEFAULT NULL,
    `end_lat` decimal(9,6) DEFAULT NULL,
    `end_lng` decimal(9,6) DEFAULT NULL,
    `end_addr` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `duration_s` int DEFAULT NULL COMMENT 'Duração em segundos',
    `max_speed` decimal(8,2) DEFAULT NULL COMMENT 'Velocidade máxima em km/h',
    `distance_km` decimal(10,2) DEFAULT NULL COMMENT 'Distância percorrida em km',
    `alarm_count` int NOT NULL DEFAULT 0 COMMENT 'Qtd. de alarmes durante a viagem',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_trips_imei_time` (`imei`, `started_at`),
    KEY `idx_trips_customer` (`customer_id`),
    KEY `idx_trips_driver` (`driver_id`),
    CONSTRAINT `fk_trips_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_trips_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Viagens detectadas por ignição (lig→desl)';

-- 1.8 Fila de jobs assíncronos
CREATE TABLE IF NOT EXISTS `jobs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `type` enum('report','video_download','rollup') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `customer_id` bigint unsigned DEFAULT NULL,
    `params` json DEFAULT NULL COMMENT 'Parâmetros da tarefa (filtros, IMEIs, etc.)',
    `status` enum('pendente','processando','concluido','falhou') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
    `result_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Caminho do arquivo gerado',
    `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `requested_by` bigint unsigned DEFAULT NULL COMMENT 'Usuário que solicitou',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_jobs_status` (`status`),
    KEY `idx_jobs_type` (`type`),
    KEY `idx_jobs_customer` (`customer_id`),
    CONSTRAINT `fk_jobs_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_jobs_user` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Fila de jobs assíncronos (relatórios, downloads, agregações)';

-- 1.9 Cache de geocodificação reversa
CREATE TABLE IF NOT EXISTS `geocode_cache` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `lat` decimal(9,6) NOT NULL,
    `lng` decimal(9,6) NOT NULL,
    `address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_geocode_coords` (`lat`, `lng`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache de endereços por coordenadas (geocodificação reversa)';

-- 1.10 Auditoria de impersonação (segurança)
CREATE TABLE IF NOT EXISTS `impersonation_log` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `reseller_user_id` bigint unsigned NOT NULL COMMENT 'Usuário revendedor que impersonou',
    `customer_id` bigint unsigned NOT NULL COMMENT 'Cliente impersonado',
    `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_il_reseller` (`reseller_user_id`),
    KEY `idx_il_customer` (`customer_id`),
    CONSTRAINT `fk_il_reseller` FOREIGN KEY (`reseller_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_il_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Auditoria de impersonação de cliente por revendedor';

-- 1.11 Checklist — Inspeção veicular
CREATE TABLE IF NOT EXISTS `checklist_configs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `customer_id` bigint unsigned DEFAULT NULL COMMENT 'NULL = template global',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cc_customer` (`customer_id`),
    CONSTRAINT `fk_cc_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurações de checklist de inspeção veicular';

CREATE TABLE IF NOT EXISTS `checklist_items` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `config_id` bigint unsigned NOT NULL,
    `question` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `sort_order` int NOT NULL DEFAULT 0,
    `is_required` tinyint(1) NOT NULL DEFAULT 1,
    `value_type` enum('boolean','text','photo','number') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'boolean',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_config` (`config_id`),
    CONSTRAINT `fk_ci_config` FOREIGN KEY (`config_id`) REFERENCES `checklist_configs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Itens de checklist de inspeção';

CREATE TABLE IF NOT EXISTS `checklist_responses` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `config_id` bigint unsigned NOT NULL,
    `device_imei` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `driver_id` bigint unsigned DEFAULT NULL,
    `answers` json DEFAULT NULL COMMENT '{"item_id":"value", ...}',
    `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `inspected_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cr_config` (`config_id`),
    KEY `idx_cr_device` (`device_imei`),
    KEY `idx_cr_driver` (`driver_id`),
    KEY `idx_cr_inspected` (`inspected_at`),
    CONSTRAINT `fk_cr_config` FOREIGN KEY (`config_id`) REFERENCES `checklist_configs`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cr_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Respostas de checklists de inspeção veicular';

-- ============================================================
-- 2. ALTERAÇÕES EM TABELAS EXISTENTES
-- ============================================================

-- 2.1 users: tipo de usuário + grupo de permissão + foto
CALL add_column_if_not_exists('users', 'user_type', "enum('revendedor','cliente') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'cliente' COMMENT 'Tipo de usuário: revendedor ou cliente' AFTER `role`");
CALL add_column_if_not_exists('users', 'permission_group_id', "bigint unsigned DEFAULT NULL COMMENT 'FK para permission_groups' AFTER `user_type`");
CALL add_column_if_not_exists('users', 'photo_url', "varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL da foto de perfil' AFTER `permission_group_id`");

-- Índice e FK para permission_group_id
CALL create_index_if_not_exists('users', 'idx_user_pg', '(`permission_group_id`)');

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE table_schema=DATABASE() AND table_name='users' AND constraint_name='fk_user_pg');
SET @sql_fk = IF(@fk_exists = 0, 'ALTER TABLE `users` ADD CONSTRAINT `fk_user_pg` FOREIGN KEY (`permission_group_id`) REFERENCES `permission_groups`(`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql_fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2.2 customers: white-label + configs + filial + feature flags
CALL add_column_if_not_exists('customers', 'reseller_id', "bigint unsigned DEFAULT NULL COMMENT 'FK para users — quem revende este cliente' AFTER `is_active`");
CALL add_column_if_not_exists('customers', 'brand_color', "varchar(9) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Cor da sidebar (hex)' AFTER `reseller_id`");
CALL add_column_if_not_exists('customers', 'logo_url', "varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL do logo do cliente' AFTER `brand_color`");
CALL add_column_if_not_exists('customers', 'occurrence_config_id', "bigint unsigned DEFAULT NULL COMMENT 'FK para occurrence_configs — perfil de ocorrências do cliente' AFTER `logo_url`");
CALL add_column_if_not_exists('customers', 'checklist_config_id', "bigint unsigned DEFAULT NULL COMMENT 'Configuração de checklist (fase futura)' AFTER `occurrence_config_id`");
CALL add_column_if_not_exists('customers', 'faceid_enabled', "tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Feature flag: FaceID habilitado?' AFTER `checklist_config_id`");

CALL create_index_if_not_exists('customers', 'idx_cust_reseller', '(`reseller_id`)');
CALL create_index_if_not_exists('customers', 'idx_cust_occ_config', '(`occurrence_config_id`)');

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE table_schema=DATABASE() AND table_name='customers' AND constraint_name='fk_cust_reseller');
SET @sql_fk = IF(@fk_exists = 0, 'ALTER TABLE `customers` ADD CONSTRAINT `fk_cust_reseller` FOREIGN KEY (`reseller_id`) REFERENCES `users`(`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql_fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE table_schema=DATABASE() AND table_name='customers' AND constraint_name='fk_cust_occ_config');
SET @sql_fk = IF(@fk_exists = 0, 'ALTER TABLE `customers` ADD CONSTRAINT `fk_cust_occ_config` FOREIGN KEY (`occurrence_config_id`) REFERENCES `occurrence_configs`(`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql_fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2.3 devices: streaming + periféricos + firmware + branch + sim
CALL add_column_if_not_exists('devices', 'sim_card_id', "bigint unsigned DEFAULT NULL COMMENT 'FK para sim_cards' AFTER `camera_count`");
CALL add_column_if_not_exists('devices', 'peripherals', "json DEFAULT NULL COMMENT 'Periféricos instalados' AFTER `sim_card_id`");
CALL add_column_if_not_exists('devices', 'streaming_rotation', "smallint NOT NULL DEFAULT 0 COMMENT 'Rotação do streaming (0/90/180/270/360)' AFTER `peripherals`");
CALL add_column_if_not_exists('devices', 'streaming_watermark', "tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Marca dagua no streaming' AFTER `streaming_rotation`");
CALL add_column_if_not_exists('devices', 'firmware_version', "varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Versão do firmware' AFTER `streaming_watermark`");
CALL add_column_if_not_exists('devices', 'branch_id', "bigint unsigned DEFAULT NULL COMMENT 'FK para branches' AFTER `firmware_version`");

CALL create_index_if_not_exists('devices', 'idx_dev_simcard', '(`sim_card_id`)');
CALL create_index_if_not_exists('devices', 'idx_dev_branch', '(`branch_id`)');

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE table_schema=DATABASE() AND table_name='devices' AND constraint_name='fk_dev_simcard');
SET @sql_fk = IF(@fk_exists = 0, 'ALTER TABLE `devices` ADD CONSTRAINT `fk_dev_simcard` FOREIGN KEY (`sim_card_id`) REFERENCES `sim_cards`(`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql_fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE table_schema=DATABASE() AND table_name='devices' AND constraint_name='fk_dev_branch');
SET @sql_fk = IF(@fk_exists = 0, 'ALTER TABLE `devices` ADD CONSTRAINT `fk_dev_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql_fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2.4 media_files: canal + status de download
CALL add_column_if_not_exists('media_files', 'channel', "tinyint unsigned DEFAULT NULL COMMENT 'Número do canal de câmera' AFTER `file_type`");
CALL add_column_if_not_exists('media_files', 'download_status', "enum('solicitado','disponivel','erro') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Status de download para /video/downloads' AFTER `channel`");

CALL create_index_if_not_exists('media_files', 'idx_mf_download_status', '(`download_status`)');
CALL create_index_if_not_exists('media_files', 'idx_mf_channel', '(`channel`)');

-- ============================================================
-- 3. ÍNDICES CRÍTICOS DE PERFORMANCE
-- ============================================================
CALL create_index_if_not_exists('alarms', 'idx_alarms_imei_time', '(`imei`, `alarm_time`)');
CALL create_index_if_not_exists('gps_data', 'idx_gps_imei_time', '(`imei`, `gps_time`)');
CALL create_index_if_not_exists('request_logs', 'idx_payload_hash_created', '(`payload_hash`, `created_at`)');

-- ============================================================
-- 4. SEEDS
-- ============================================================

-- 4.1 Perfil de ocorrência padrão do sistema
INSERT IGNORE INTO `occurrence_configs` (`id`, `name`, `is_default`) VALUES
(1, 'Padrão Sistema', 1);

-- 4.2 Parâmetros para tipos DMS comuns (usando os alarm_types existentes)
-- Mapeamos categorias DMS conhecidas: ADAS, DMS, Segurança, Acidente
INSERT IGNORE INTO `occurrence_config_params` (`config_id`, `alarm_type`, `generates_occurrence`, `risk`, `threshold`) VALUES
-- DMS — Comportamento do motorista (via câmera com IA)
(1, 'Distração', 1, 'medio', 10),
(1, 'Uso de Celular', 1, 'alto', 10),
(1, 'Sem Cinto de Segurança', 1, 'baixo', 10),
(1, 'Fadiga', 1, 'alto', 10),
(1, 'Cigarro', 1, 'baixo', 10),
(1, 'Olhos Fechados', 1, 'alto', 10),
(1, 'Bocejo', 1, 'medio', 10),
(1, 'Obstrução de Câmera', 1, 'baixo', 30),
(1, 'Motorista Não Identificado', 1, 'baixo', 30),
-- ADAS — Assistência ao motorista
(1, 'Colisão Frontal', 1, 'alto', 5),
(1, 'Saída de Faixa', 1, 'medio', 10),
(1, 'Pedestre Detectado', 1, 'alto', 5),
(1, 'Alerta de Distância', 1, 'medio', 10),
-- Acidente / Segurança
(1, 'Airbag Acionado', 1, 'alto', 5),
(1, 'Capotamento', 1, 'alto', 5),
(1, 'SOS', 1, 'alto', 5),
(1, 'Corte de Alimentação', 1, 'medio', 30),
(1, 'Vibração', 1, 'medio', 10),
-- Genéricos — sem ocorrência
(1, 'Cerca Eletrônica', 0, 'baixo', NULL),
(1, 'Excesso de Velocidade', 0, 'medio', NULL),
(1, 'Bateria Fraca', 0, 'baixo', NULL),
(1, 'SIM Alterado', 0, 'baixo', NULL);

-- 4.3 Grupos de permissão padrão
INSERT IGNORE INTO `permission_groups` (`id`, `name`, `user_type`, `permissions`) VALUES
(1, 'Administrador', 'revendedor', '{"*":["view","create","edit","delete","export"]}'),
(2, 'Operador Padrão', 'cliente', '{"resumo":["view"],"rastreamento":["view"],"bi":["view"],"ocorrencias_dashboard":["view","edit"],"comandos":["view","create"],"video_aovivo":["view"],"video_playback":["view"],"video_downloads":["view"],"relatorios":["view","export"],"ativos":["view"],"motoristas":["view"]}');

-- ============================================================
-- 5. LIMPEZA / ATUALIZAÇÃO
-- ============================================================

-- Atualizar versão do sistema
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '4.0.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '4.0.0', `last_update` = NOW();

-- Remover rotas mortas (R08, R09): apagar handlers obsoletos
-- (Feito em PHP — não há registros no banco para limpar)

-- 1.12 Snapshots de métricas pré-computadas (KPIs Resumo/BI)
CREATE TABLE IF NOT EXISTS `metrics_snapshots` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `metric_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Chave da métrica (devices_total, devices_online, ocurrences_waiting, alarms_today, etc.)',
    `metric_value` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `snapshot_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Momento do snapshot',
    PRIMARY KEY (`id`),
    KEY `idx_ms_customer_key` (`customer_id`, `metric_key`),
    KEY `idx_ms_snapshot` (`snapshot_at`),
    CONSTRAINT `fk_ms_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Métricas pré-computadas por cliente (KPIs Resumo/BI)';

-- 1.13 Log de tentativas de login (rate limiting + auditoria)
CREATE TABLE IF NOT EXISTS `login_log` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `success` tinyint(1) NOT NULL DEFAULT 0,
    `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ll_email` (`email`),
    KEY `idx_ll_ip_time` (`ip_address`, `created_at`),
    KEY `idx_ll_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de tentativas de login para rate limiting e auditoria';

-- ============================================================
-- Limpeza de procedures auxiliares
-- ============================================================
DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;
DROP PROCEDURE IF EXISTS `create_index_if_not_exists`;
