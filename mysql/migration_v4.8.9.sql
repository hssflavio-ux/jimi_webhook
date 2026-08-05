-- ============================================================================
-- JIMI Webhook System — Migração v4.8.9
--
-- Rastreabilidade ponta a ponta do despacho de comandos.
--
-- `sendcommand.php` já gera um `requestId` ("dash_<data>_<8 hex>") e já decide
-- um `serverFlagId`, manda os dois ao IoTHub e **não guarda nenhum dos dois**.
-- Sem eles gravados não há como ligar uma linha de `commands` ao rastro que o
-- IoTHub tem do mesmo envio — que é exatamente para isso que a doc oficial diz
-- que o `requestId` serve ("used for troubleshooting and log tracing").
--
-- ⚠️ Esta migração NÃO muda a correlação da resposta offline. Ver a nota em
-- handlers/pushinstructresponse.php: a doc define `serverFlagId` como a chave
-- de correspondência requisição↔resposta, mas este sistema o usa como seletor
-- de gateway (0=JT/T, 1=JIMI), então ele não é único por comando. Gravar a
-- coluna é o passo que torna aquela correção possível de verificar depois,
-- com device real.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── commands.request_id / commands.server_flag_id ───────────────────────────
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'commands'
               AND column_name = 'request_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE commands ADD COLUMN request_id VARCHAR(40) NULL COMMENT "requestId enviado ao IoTHub (log tracing, doc oficial 1.2.2)" AFTER api_type',
    'SELECT "commands.request_id ja existe" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'commands'
               AND column_name = 'server_flag_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE commands ADD COLUMN server_flag_id VARCHAR(20) NULL COMMENT "serverFlagId enviado ao IoTHub; hoje seletor de gateway (0=JTT,1=JIMI), nao unico por comando" AFTER request_id',
    'SELECT "commands.server_flag_id ja existe" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Índice para a correlação da resposta offline (imei + status + conteúdo)
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'commands'
               AND index_name = 'idx_commands_request_id');
SET @sql := IF(@idx = 0,
    'ALTER TABLE commands ADD INDEX idx_commands_request_id (request_id)',
    'SELECT "idx_commands_request_id ja existe" AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.8.9', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.8.9', last_update = NOW();

SELECT 'Migracao v4.8.9 concluida' AS status;
