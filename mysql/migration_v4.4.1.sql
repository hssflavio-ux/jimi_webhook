-- ============================================================
-- Migração v4.4.1 — Credenciais SMTP cadastráveis pela interface
-- ============================================================
-- A v4.4.0 lia o SMTP só do .env, o que obriga acesso ao servidor para
-- trocar um provedor de e-mail. Agora as credenciais são cadastradas em
-- /config-smtp e o .env vira apenas fallback.
--
-- Escopo: customer_id NULL = configuração GLOBAL da plataforma; uma linha
-- por cliente sobrepõe a global (white-label — cliente que quer enviar do
-- próprio domínio). Resolução em mail_config(): cliente → global → .env.
--
-- A senha é gravada CIFRADA (AES-256-GCM, includes/crypto.php). Trocar
-- APP_KEY/WEBHOOK_TOKEN torna as senhas gravadas indecifráveis — basta
-- recadastrar, mas o envio para até lá.
--
-- Idempotente: pode rodar mais de uma vez sem efeito colateral.
-- ============================================================

CREATE TABLE IF NOT EXISTS `smtp_settings` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned DEFAULT NULL COMMENT 'NULL = configuração global da plataforma',
    `host` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `port` smallint unsigned NOT NULL DEFAULT 587,
    `secure` enum('tls','ssl','none') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tls'
        COMMENT 'tls = STARTTLS (587) | ssl = implícito (465) | none',
    `username` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `password_enc` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Senha cifrada (AES-256-GCM) — nunca em texto puro',
    `from_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `from_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Jimi Tracker',
    `timeout_s` smallint unsigned NOT NULL DEFAULT 20,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `last_test_at` datetime DEFAULT NULL COMMENT 'UTC — último envio de teste',
    `last_test_ok` tinyint(1) DEFAULT NULL,
    `last_test_error` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `updated_by` bigint unsigned DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Mesma razão de notification_rules: MySQL trata NULLs como distintos
    -- num índice único, então sem esta coluna gerada seria possível criar
    -- duas configurações globais. VIRTUAL (e não STORED) porque uma FK
    -- sobre a coluna-base de uma coluna gerada STORED não aceita CASCADE.
    `customer_key` bigint unsigned GENERATED ALWAYS AS (COALESCE(`customer_id`, 0)) VIRTUAL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_smtp_customer` (`customer_key`),
    CONSTRAINT `fk_smtp_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_smtp_user` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Credenciais do servidor SMTP (global e por cliente)';

-- ============================================================
-- Versão do sistema
-- ============================================================
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '4.4.1', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '4.4.1', `last_update` = NOW();
