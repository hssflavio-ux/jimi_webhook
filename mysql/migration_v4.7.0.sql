-- ============================================================
-- Migração v4.7.0 — Relatório agendado por e-mail + modelos
-- ============================================================
-- Fase 4 de docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md.
--
-- Entrega: o relatório configurado uma vez chega sozinho por e-mail na
-- frequência escolhida, e os filtros usados com frequência viram modelos
-- reutilizáveis.
--
--   schedule_dispatcher.php (cron 5 * * * *)
--        │  seleciona is_active=1 AND next_run_at <= NOW()
--        │  RECALCULA next_run_at ANTES de enfileirar (reentrância)
--        ▼
--   jobs (type='report', params.deliver_email)
--        │
--        ▼
--   worker.php → gera o arquivo → send_mail() com anexo
--        │        (acima de MAIL_MAX_ATTACH_MB, envia link)
--        ▼
--   report_schedule_runs (histórico: enfileirado → enviado | falhou)
--
-- Peças:
--   report_schedules      — a configuração recorrente
--   report_schedule_runs  — o histórico de execuções
--   report_templates      — filtros salvos por usuário nas telas de relatório
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
-- 1. report_schedules — a configuração recorrente
-- ============================================================
-- ATENÇÃO AO FUSO — é o ponto de maior risco desta fase:
--   `send_hour` é hora BRT (o que o usuário digita na tela)
--   `next_run_at` é UTC (o que o dispatcher compara com NOW())
-- A conversão é feita SEMPRE por DateTimeZone em includes/schedule.php,
-- nunca somando 3 horas na mão. Offset fixo produz relatório chegando na
-- hora errada em parte do ano se o país voltar a adotar horário de verão,
-- e já erra hoje para datas históricas anteriores a 2019.
--
-- `next_run_at` é recalculado ANTES de o job ser enfileirado: se o cron
-- atrasar e dois processos coincidirem, o segundo não encontra a linha
-- vencida. É a guarda de reentrância.
CREATE TABLE IF NOT EXISTS `report_schedules` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `user_id` bigint unsigned DEFAULT NULL COMMENT 'Criador — recebe a notificação se o agendamento for desativado',
    `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `report_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        COMMENT 'Mesmos tipos de buildReportSource() no worker',
    `format` enum('csv','xlsx','pdf') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'xlsx',
    `filters` json DEFAULT NULL COMMENT 'Mesmo shape de jobs.params, SEM o período (que é derivado da frequência)',
    `frequency` enum('diaria','semanal','mensal') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'diaria',
    `send_hour` tinyint unsigned NOT NULL DEFAULT 7 COMMENT 'Hora BRT (0-23) — NÃO é UTC',
    `send_dow` tinyint unsigned DEFAULT NULL COMMENT '1=segunda … 7=domingo (frequência semanal)',
    `send_dom` tinyint unsigned DEFAULT NULL COMMENT 'Dia do mês 1-28 (frequência mensal)',
    `recipients` json NOT NULL COMMENT 'Array de até 3 e-mails',
    `skip_if_empty` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = não envia quando não há registro no período',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `fail_count` tinyint unsigned NOT NULL DEFAULT 0 COMMENT 'Falhas consecutivas; 3 desativam o agendamento',
    `last_run_at` datetime DEFAULT NULL COMMENT 'UTC',
    `next_run_at` datetime DEFAULT NULL COMMENT 'UTC — calculado a partir de send_hour BRT',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rs_due` (`is_active`,`next_run_at`),
    KEY `idx_rs_customer` (`customer_id`,`is_active`),
    CONSTRAINT `fk_rs_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Relatórios agendados por e-mail';

-- ============================================================
-- 2. report_schedule_runs — o histórico
-- ============================================================
-- Sem histórico, "o relatório não chegou" é indepurável: não se distingue
-- agendamento que nunca disparou de e-mail recusado pelo provedor.
CREATE TABLE IF NOT EXISTS `report_schedule_runs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `schedule_id` bigint unsigned NOT NULL,
    `job_id` bigint unsigned DEFAULT NULL,
    `status` enum('enfileirado','enviado','vazio','falhou') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enfileirado',
    `period_from` datetime DEFAULT NULL COMMENT 'UTC — início da janela consultada',
    `period_to` datetime DEFAULT NULL COMMENT 'UTC — fim da janela consultada',
    `row_count` int unsigned DEFAULT NULL COMMENT 'Linhas do relatório gerado',
    `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    `executed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rsr_schedule` (`schedule_id`,`executed_at`),
    KEY `idx_rsr_job` (`job_id`),
    CONSTRAINT `fk_rsr_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `report_schedules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Execuções dos relatórios agendados';

-- ============================================================
-- 3. report_templates — filtros salvos
-- ============================================================
-- Por USUÁRIO, não por cliente: o filtro que interessa a quem trata
-- ocorrências não é o mesmo que interessa a quem audita combustível, e as
-- duas pessoas podem pertencer ao mesmo cliente.
CREATE TABLE IF NOT EXISTS `report_templates` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `user_id` bigint unsigned NOT NULL,
    `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `report_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        COMMENT 'Rota do relatório (ex.: rel_alarmes) — o modelo só aparece na tela que o criou',
    `filters` json DEFAULT NULL COMMENT 'Query string da tela, sem page/export/sort',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rt_user_name` (`user_id`,`report_type`,`name`),
    KEY `idx_rt_lookup` (`user_id`,`report_type`),
    CONSTRAINT `fk_rt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Modelos de filtro das telas de relatório';

-- ============================================================
-- 4. jobs — vínculo com o agendamento que o originou
-- ============================================================
-- Sem isto, o worker não sabe qual execução atualizar ao terminar, e o
-- histórico ficaria eternamente em "enfileirado".
CALL add_column_if_not_exists('jobs', 'schedule_run_id',
    "bigint unsigned DEFAULT NULL COMMENT 'FK para report_schedule_runs quando o job veio de um agendamento' AFTER `requested_by`");

-- ── Limpeza dos helpers ─────────────────────────────────────
DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;

-- ============================================================
-- Versão do sistema
-- ============================================================
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '4.7.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '4.7.0', `last_update` = NOW();
