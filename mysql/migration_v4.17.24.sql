-- ============================================================================
-- Migration v4.17.24 — SMS: método Pull como alternativa ao webhook p/ respostas
--
-- Achado ao investigar por que `sms_commands.resposta_texto` nunca era
-- preenchido: 0 de 29 comandos, 0 payloads com o campo `mensagem` em
-- `webhook_payloads` (endpoint=pushsms). A Allcance nunca empurrou um evento
-- de resposta/interação para o webhook nesta conta — mas a própria doc oficial
-- (seção "Consulta Respostas — Método Pull") documenta um endpoint SEPARADO
-- para exatamente essa situação: `GET /v2/api/relatorios/campanhas/respostas/sms`.
--
-- Pedido do dono do produto (08/09/2026): as duas vias (webhook e busca
-- periódica) devem coexistir como REDUNDÂNCIA uma da outra, com uma opção para
-- ligar uma ou outra conforme a necessidade — nunca as duas escrevendo ao
-- mesmo tempo por padrão. Nesta fase, liga-se a busca (`pull`): é a única das
-- duas que já provou trazer dado nos testes com equipamento real.
-- ============================================================================

SET time_zone = '+00:00';

-- ── Coluna condicional — mesmo motivo do `eventos_raw` na v4.17.13: o remédio
-- documentado para migração nova é rodar `deploy.sh --force` duas vezes, e um
-- `ADD COLUMN` puro rodando pela segunda vez aborta o script inteiro com 1060
-- Duplicate column, antes da versão e das conferências.
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

CALL add_column_if_not_exists('sms_settings', 'respostas_metodo',
    "ENUM('webhook','pull') NOT NULL DEFAULT 'pull' COMMENT 'Como preencher resposta_texto/resposta_em: webhook (Allcance empurra) ou pull (scripts/sms_respostas_pull.php busca) — mutuamente exclusivos por pedido do dono do produto' AFTER `webhook_secret`");

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;

-- Linha global já existente (se houver) começa em 'pull': é o modo que já
-- provou trazer dado real; 'webhook' nunca recebeu um evento de resposta
-- nesta conta (ver STATUS.md v4.17.24).
UPDATE `sms_settings` SET `respostas_metodo` = 'pull' WHERE `customer_id` IS NULL;

-- ============================================================
-- Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.17.24', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.17.24', last_update = NOW();

-- ============================================================
-- Conferência
-- ============================================================
SELECT CONCAT('coluna respostas_metodo existe: ', COUNT(*)) AS status
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_settings'
   AND COLUMN_NAME = 'respostas_metodo';

SELECT 'Migracao v4.17.24 concluida' AS status;
