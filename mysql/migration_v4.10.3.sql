-- ============================================================================
-- JIMI Webhook System — Migração v4.10.3
--
-- Item 7 do docs/PLANO_IMPLEMENTACAO_v4.10.md: painel widgetizado por
-- usuário, em `/painel` — tela NOVA e PARALELA a `/` (handlers/resumo.php),
-- que não é tocado.
--
-- `user_id` NULL é o padrão GLOBAL do sistema — não por cliente. É intencional
-- (decisão do plano): um único layout de fallback para todo mundo que ainda
-- não editou o próprio, editável só por quem tiver acesso de escrita na tela
-- (mesma permissão de 'painel'). A UNIQUE em `user_key` garante no máximo UMA
-- linha global e UMA por usuário.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `dashboard_layouts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL COMMENT 'NULL = padrao global do sistema',
  `layout` json NOT NULL COMMENT 'Array ordenado de chaves de DASHBOARD_WIDGETS (includes/dashboard_widgets.php)',
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `user_key` bigint GENERATED ALWAYS AS (COALESCE(`user_id`, 0)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dl_user` (`user_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Layout do painel widgetizado (item 7, v4.10) — 1 linha por usuario + 1 global';

SELECT CONCAT('dashboard_layouts: ', COUNT(*), ' linhas') AS resultado FROM dashboard_layouts;
