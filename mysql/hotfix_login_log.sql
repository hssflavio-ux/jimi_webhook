-- ============================================================
-- JIMI Webhook — Hotfix: criar login_log
-- ============================================================
-- Execute no servidor de produção:
--   mysql -u root -p jimi_tracker < mysql/hotfix_login_log.sql
--
-- A tabela é necessária para rate limiting e auditoria de login.
-- O código PHP já é resiliente (fallback se a tabela não existir),
-- mas crie-a para ativar rate limiting.
-- ============================================================

USE `jimi_tracker`;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'login_log created OK' AS result;
