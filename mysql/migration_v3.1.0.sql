-- ============================================================
-- JIMI Webhook System v3.1.0 — Migration Script
-- Database: jimi_tracker
-- Description: Multi-tenant (customers), device models, users,
--              auth sessions, device registration enhancements
-- Execute: mysql -u root -p jimi_tracker < mysql/migration_v3.1.0.sql
-- ============================================================

USE `jimi_tracker`;

-- ------------------------------------------------------------
-- 1. Tabela: customers (Clientes — multi-tenant)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `document` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'CPF/CNPJ',
    `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Clientes do sistema (multi-tenant)';

-- Seed: Cliente Padrão
INSERT INTO `customers` (`id`, `name`, `document`, `email`, `phone`, `address`, `is_active`) VALUES
(1, 'Frota Principal', NULL, NULL, NULL, NULL, 1);

-- ------------------------------------------------------------
-- 2. Tabela: users (Usuários do sistema)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `role` enum('admin','operator','viewer') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operator',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `last_login` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Usuários do sistema (autenticação)';

-- O primeiro usuário admin será criado via /setup após a migração

-- ------------------------------------------------------------
-- 3. Tabela: customer_users (Vínculo cliente ↔ usuário)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_users` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `user_id` bigint unsigned NOT NULL,
    `role` enum('admin','operator','viewer') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operator',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_customer_user` (`customer_id`, `user_id`),
    KEY `idx_cu_user` (`user_id`),
    CONSTRAINT `fk_cu_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cu_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vínculo de usuários a clientes';

-- O vínculo do admin será criado via /setup

-- ------------------------------------------------------------
-- 4. Tabela: sessions (Sessões de login)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` bigint unsigned NOT NULL,
    `customer_id` bigint unsigned DEFAULT NULL COMMENT 'Cliente ativo no contexto da sessão',
    `expires_at` timestamp NOT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_session_user` (`user_id`),
    KEY `idx_session_expires` (`expires_at`),
    CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sessões de usuários autenticados';

-- ------------------------------------------------------------
-- 5. Tabela: device_models (Modelos de câmera/dispositivo)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `device_models` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `model_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `protocol` enum('JIMI','JTT') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `camera_count` int NOT NULL DEFAULT 1,
    `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_model_name` (`model_name`),
    KEY `idx_model_protocol` (`protocol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Catálogo de modelos de dispositivos suportados';

-- Seed: 6 modelos de câmera
INSERT INTO `device_models` (`model_name`, `protocol`, `camera_count`, `description`) VALUES
('JC400D',  'JIMI', 1, 'Câmera veicular JIMI 1 canal (protocolo JIMI)'),
('JC400AD', 'JIMI', 1, 'Câmera veicular JIMI avançada 1 canal (protocolo JIMI)'),
('JC371',   'JTT',  1, 'Câmera veicular JT/T 1 canal (protocolo JT/T 808)'),
('JC450',   'JTT',  2, 'Câmera veicular JT/T 2 canais (protocolo JT/T 808)'),
('JC181',   'JTT',  1, 'Câmera compacta JT/T 1 canal (protocolo JT/T 808)'),
('JC182',   'JTT',  1, 'Câmera compacta JT/T 1 canal avançada (protocolo JT/T 808)');

-- ------------------------------------------------------------
-- 6. Alterar tabela: devices (novas colunas para cadastro)
-- ------------------------------------------------------------
ALTER TABLE `devices`
    ADD COLUMN `customer_id` bigint unsigned DEFAULT NULL COMMENT 'Cliente proprietário do dispositivo' AFTER `imei`,
    ADD COLUMN `device_model_id` bigint unsigned DEFAULT NULL COMMENT 'FK para device_models' AFTER `device_model`,
    ADD COLUMN `camera_count` int DEFAULT 1 COMMENT 'Quantidade de câmeras' AFTER `device_model_id`,
    ADD COLUMN `created_by` bigint unsigned DEFAULT NULL COMMENT 'Usuário que cadastrou' AFTER `is_active`,
    ADD INDEX `idx_dev_customer` (`customer_id`),
    ADD INDEX `idx_dev_model` (`device_model_id`),
    ADD CONSTRAINT `fk_dev_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_dev_model` FOREIGN KEY (`device_model_id`) REFERENCES `device_models`(`id`) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 7. Migrar dispositivos existentes para cliente padrão
-- ------------------------------------------------------------
UPDATE `devices` SET `customer_id` = 1 WHERE `customer_id` IS NULL;

-- ------------------------------------------------------------
-- 8. Atualizar versão do sistema
-- ------------------------------------------------------------
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '3.1.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '3.1.0', `last_update` = NOW();
