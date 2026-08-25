-- ═══════════════════════════════════════════════════════════════════════════
-- Migration v4.13.0 — "Configurações IA"
--
-- Tabela nova para guardar o último valor lido/aplicado de cada comando de
-- configuração de ADAS/DMS/velocidade (catálogo próprio em
-- includes/ia_config_catalog.php, proNo 128 — texto, não JT/T binário).
--
-- 🔴 NÃO mexe em device_param_catalog/device_params/device_param_snapshots
-- (parâmetros JT/T 33027/33028/33030) — a área de Parâmetros JT/T foi só
-- PAUSADA na tela (não apagada), até o fabricante corrigir o firmware.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `device_ia_config_state` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `imei`            VARCHAR(20) NOT NULL,
  `cmd_key`         VARCHAR(80) NOT NULL COMMENT 'Chave de includes/ia_config_catalog.php, ex.: EVENTSET,ALDW,P1#',
  `last_response`   TEXT NULL COMMENT 'Resposta bruta da câmera à consulta ou ao comando aplicado',
  `requested_value` TEXT NULL COMMENT 'Comando pedido, aguardando confirmação na próxima leitura',
  `previous_value`  TEXT NULL COMMENT 'Valor anterior ao pedido, para exibir "antes/depois"',
  `read_at`         DATETIME NULL COMMENT 'Quando a última resposta chegou',
  `requested_at`    DATETIME NULL COMMENT 'Quando o pedido foi enviado',
  `command_id`      BIGINT UNSIGNED NULL COMMENT 'commands.id do envio mais recente',
  `created_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ia_state_imei_cmd` (`imei`, `cmd_key`),
  KEY `idx_ia_state_imei` (`imei`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Último valor lido/aplicado por câmera dos comandos de includes/ia_config_catalog.php';
