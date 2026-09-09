-- ============================================================================
-- Migration v4.18.0 — Consulta da fila offline do hub (§2.21 queryOfflineInstruct)
--
-- Até aqui a tela dizia "o comando será entregue quando o equipamento
-- reconectar" como SUPOSIÇÃO — não mandávamos offlineFlag e a §2.21 tinha
-- devolvido fila vazia numa medição de 07/09/2026 (docs/FILA_OFFLINE_COMANDOS.md).
-- O teste decisivo de 09/09/2026 (equipamento real, offline há 19 dias) mostrou
-- que o hub CACHEIA o comando offline mesmo sem offlineFlag — a fila vazia
-- medida antes era de comandos cuja janela de validade já tinha expirado, não
-- de comandos nunca cacheados. `scripts/offline_instruct_poll.php` (cron)
-- consulta essa fila periodicamente para os comandos pendentes e grava o
-- resultado aqui, para a tela mostrar o estado CONFIRMADO em vez de suposto.
-- ============================================================================

SET time_zone = '+00:00';

-- Coluna condicional (mesmo motivo do `eventos_raw`/`respostas_metodo`: uma
-- migração nova só entra em vigor no SEGUNDO deploy, e um ADD COLUMN puro
-- rodando de novo aborta com 1060 Duplicate column antes da versão gravar).
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

CALL add_column_if_not_exists('commands', 'hub_queue_status',
    "VARCHAR(20) NULL DEFAULT NULL COMMENT 'Último resultado de iothub_query_offline_instruct(): queued | not_found | error. NULL = nunca consultado.' AFTER `response_payload`");

CALL add_column_if_not_exists('commands', 'hub_queue_checked_at',
    "DATETIME NULL DEFAULT NULL COMMENT 'Quando scripts/offline_instruct_poll.php consultou a fila do hub pela última vez para este comando' AFTER `hub_queue_status`");

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;

-- Índice para o cron selecionar rápido "comandos pendentes que precisam de
-- checagem", sem varrer a tabela inteira a cada rodada.
SET @idx_existe = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'commands' AND index_name = 'idx_hub_queue_poll'
);
SET @sql = IF(@idx_existe = 0,
    'CREATE INDEX idx_hub_queue_poll ON commands (status, hub_queue_checked_at)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.18.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.18.0', last_update = NOW();

-- ============================================================
-- Conferência
-- ============================================================
SELECT CONCAT('colunas hub_queue_status/hub_queue_checked_at existem: ', COUNT(*)) AS status
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commands'
   AND COLUMN_NAME IN ('hub_queue_status', 'hub_queue_checked_at');

SELECT 'Migracao v4.18.0 concluida' AS status;
