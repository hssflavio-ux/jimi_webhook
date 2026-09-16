-- ============================================================================
-- Migração v4.21.5 — gps_data.status_bits (bitmask de status do pushgps)
-- ============================================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- Pedido do dono do produto: conferir se `postMethod` e `status` — campos
-- documentados em §1.3 Push GPS Data da doc oficial
-- (https://docs.jimicloud.com/integration/integration.html) — estavam sendo
-- gravados. `postMethod` já era (post_method, desde a reescrita v2.0.0 de
-- handlers/pushgps.php); só falta rótulo pros valores, e a doc oficial só
-- documenta 0x00–0x0F (16 valores) — 27 e 28, medidos em produção, continuam
-- sem legenda oficial (achado já registrado no CHANGELOG v4.17.11).
--
-- `status` NUNCA foi extraído: só sobrevivia dentro de `raw_data`, se o
-- device mandasse a chave. Existe uma coluna `gps_data.status` desde o schema
-- original, mas é VARCHAR(50) DEFAULT 'VALID' — outra coisa (grep confirmou
-- ZERO leituras dela em código nenhum), NÃO o campo documentado abaixo.
-- Legado congelado, mesma classe do `devices.device_name` pré-v4.11.0: não se
-- apaga, não se reaproveita por cima com um significado novo.
--
-- Grava CRU, sem decodificar bit a bit — mesmo padrão já em uso para
-- `device_status_code` (gravado desde a v2.0.0 do handler, nunca lido nem
-- decodificado em código nenhum até hoje). A tabela abaixo é a doc OFICIAL
-- (`Description of status Parameters`, não inferida por medição):
--
--   Bit 0     0=ACC OFF, 1=ACC ON
--   Bit 1     0=Não posicionado, 1=Posicionado
--   Bit 2     0=Latitude Norte, 1=Latitude Sul
--   Bit 3     0=Longitude Leste, 1=Longitude Oeste
--   Bit 4     0=Em operação, 1=Fora de serviço
--   Bit 5     0=Lat/lng não criptografada, 1=criptografada (plugin de sigilo)
--   Bit 6-7   Reservado
--   Bit 8-9   00=Vazio, 01=Meia carga, 10=Reservado, 11=Carga cheia
--   Bit 10    0=Circuito de óleo normal, 1=Desconectado
--   Bit 11    0=Circuito elétrico normal, 1=Desconectado
--   Bit 12    0=Porta destravada, 1=Travada
--   Bit 13    0=Porta 1 fechada, 1=Aberta (dianteira)
--   Bit 14    0=Porta 2 fechada, 1=Aberta (do meio)
--   Bit 15    0=Porta 3 fechada, 1=Aberta (traseira)
--   Bit 16    0=Porta 4 fechada, 1=Aberta (motorista)
--   Bit 17    0=Porta 5 fechada, 1=Aberta (customizada)
--   Bit 18    0=Sem satélite GPS na fixação, 1=Com GPS
--   Bit 19    0=Sem satélite BeiDou, 1=Com BeiDou
--   Bit 20    0=Sem satélite GLONASS, 1=Com GLONASS
--   Bit 21    0=Sem satélite Galileo, 1=Com Galileo
--   Bit 22-31 Reservado
--
-- Não há bit de "motivo do envio" — mesma pergunta que motivou a investigação
-- de `postMethod` na v4.17.11, reconfirmada agora na tabela completa.
-- Decodificar é trabalho de tela, quando algum relatório precisar de um bit
-- específico; esta migração só garante que o dado pare de se perder.

SET time_zone = '+00:00';

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

CALL add_column_if_not_exists('gps_data', 'status_bits',
    "INT UNSIGNED DEFAULT NULL COMMENT 'Bitmask cru do campo status (doc oficial Sec 1.3 Push GPS Data) - nao decodificado' AFTER `device_status_code`");

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;
