-- ============================================================
-- Migração v4.4.0 — Motor de Notificações
-- ============================================================
-- Fase 1 de docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md.
--
-- Entrega: alarme relevante deixa de depender de alguém com o dashboard
-- aberto. Regra por cliente × tipo de alarme decide se notifica no sino,
-- em pop-up, com som e/ou por e-mail.
--
-- Peças:
--   notification_rules — o que notificar e por qual canal
--   notifications      — caixa de entrada (sino)
--   jobs.type          — novo valor 'notification' (envio assíncrono)
--   jobs.attempts      — retry de e-mail (falha de SMTP é transitória)
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
-- 1. notification_rules — o que notificar, por qual canal, para quem
-- ============================================================
-- alarm_type aceita código, nome PT/EN ou categoria: o motor resolve pelo
-- mesmo matching triplo do occurrence_engine (get_occurrence_param).
-- customer_id NULL = regra global, usada como fallback quando o cliente
-- não tem regra própria para aquele tipo.
CREATE TABLE IF NOT EXISTS `notification_rules` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned DEFAULT NULL COMMENT 'NULL = regra global (fallback)',
    `alarm_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Código, nome ou categoria do alarme',
    `min_risk` enum('baixo','medio','alto') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Risco mínimo da ocorrência (NULL = qualquer)',
    `notify_bell` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Registra no sino',
    `notify_popup` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Abre toast em tempo real',
    `notify_sound` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Toca som ao chegar',
    `notify_email` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Enfileira e-mail',
    `emails` json DEFAULT NULL COMMENT 'Array de até 3 destinatários',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- MySQL trata NULLs como DISTINTOS num índice único: sem esta coluna
    -- gerada, duas regras globais (customer_id NULL) para o mesmo alarm_type
    -- seriam aceitas e o seed duplicaria a cada execução da migração.
    --
    -- VIRTUAL, e não STORED, por imposição do MySQL: uma FK sobre a coluna-base
    -- de uma coluna gerada STORED não pode usar CASCADE/SET NULL como ação
    -- referencial (erro 1215). Com VIRTUAL o ON DELETE CASCADE abaixo é aceito
    -- e a unicidade continua sendo garantida pelo índice.
    `customer_key` bigint unsigned GENERATED ALWAYS AS (COALESCE(`customer_id`, 0)) VIRTUAL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_nr_customer_type` (`customer_key`,`alarm_type`),
    KEY `idx_nr_active` (`is_active`),
    CONSTRAINT `fk_nr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Regras de notificação por cliente x tipo de alarme';

-- ============================================================
-- 2. notifications — caixa de entrada do sino
-- ============================================================
-- user_id NULL = visível para todos os usuários do cliente. want_popup /
-- want_sound viajam na notificação (e não na regra) porque o frontend só
-- consulta esta tabela — não precisa reabrir a regra a cada polling.
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned DEFAULT NULL,
    `user_id` bigint unsigned DEFAULT NULL COMMENT 'NULL = todos os usuários do cliente',
    `kind` enum('alarme','ocorrencia','geocerca','lembrete','sistema') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sistema',
    `severity` enum('info','warning','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
    `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `body` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `link_url` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `ref_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'occurrence|alarm|geofence_event',
    `ref_id` bigint unsigned DEFAULT NULL,
    `want_popup` tinyint(1) NOT NULL DEFAULT 0,
    `want_sound` tinyint(1) NOT NULL DEFAULT 0,
    `is_read` tinyint(1) NOT NULL DEFAULT 0,
    `read_at` datetime DEFAULT NULL COMMENT 'UTC',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
    PRIMARY KEY (`id`),
    KEY `idx_notif_cust_unread` (`customer_id`,`is_read`,`created_at`),
    KEY `idx_notif_user` (`user_id`,`is_read`,`created_at`),
    KEY `idx_notif_created` (`created_at`),
    CONSTRAINT `fk_notif_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Caixa de entrada de notificações (sino)';

-- ============================================================
-- 3. jobs — novo tipo 'notification' + retry
-- ============================================================
-- MODIFY é naturalmente idempotente: reaplicar produz o mesmo enum.
ALTER TABLE `jobs` MODIFY COLUMN `type`
    enum('report','video_download','rollup','notification')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

-- Envio de e-mail falha por motivo transitório (SMTP fora do ar, timeout).
-- Sem retry, uma indisponibilidade de 2 minutos perderia a notificação.
CALL add_column_if_not_exists('jobs', 'attempts',
    "tinyint unsigned NOT NULL DEFAULT 0 COMMENT 'Tentativas de execução (retry)' AFTER `error_message`");

-- ============================================================
-- 4. Seed — regras globais por categoria
-- ============================================================
-- E-mail nasce DESLIGADO de propósito: ninguém deve receber e-mail sem ter
-- pedido. O administrador liga em /config-notificacoes.
-- As categorias abaixo existem em alarm_types (seed do schema base).
INSERT INTO `notification_rules`
    (`customer_id`, `alarm_type`, `min_risk`, `notify_bell`, `notify_popup`, `notify_sound`, `notify_email`, `emails`, `is_active`)
VALUES
    (NULL, 'Emergency', NULL,    1, 1, 1, 0, NULL, 1),
    (NULL, 'Accident',  NULL,    1, 1, 1, 0, NULL, 1),
    (NULL, 'DMS',       'alto',  1, 1, 0, 0, NULL, 1),
    (NULL, 'ADAS',      'alto',  1, 1, 0, 0, NULL, 1),
    (NULL, 'Security',  NULL,    1, 0, 0, 0, NULL, 1),
    (NULL, 'Driving',   'alto',  1, 0, 0, 0, NULL, 1)
ON DUPLICATE KEY UPDATE `alarm_type` = VALUES(`alarm_type`);

-- ── Limpeza dos helpers ─────────────────────────────────────
DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;

-- ============================================================
-- Versão do sistema
-- ============================================================
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '4.4.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '4.4.0', `last_update` = NOW();
