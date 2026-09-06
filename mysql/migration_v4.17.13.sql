-- ============================================================================
-- Migration v4.17.13 — SMS: carimbos do provedor em UTC + eventos crus por comando
--
-- Dois pedidos do dono do produto, saídos do teste de ponta a ponta do canal
-- de SMS (06/09/2026), em que o webhook foi inocentado e dois defeitos nossos
-- apareceram no caminho.
--
-- 1) A Allcance manda HORA LOCAL (BRT) e gravávamos a string crua em colunas
--    que o sistema inteiro trata como UTC. Medido nos payloads capturados em
--    `webhook_payloads`: 180 minutos exatos de defasagem. Como a tela renderiza
--    `resposta_em` com `fmt_brt()` (−3 h) enquanto `created_at` (TIMESTAMP) sai
--    correto, a MESMA linha mostraria a resposta chegando 3 h ANTES do comando.
--    A conversão passou a ser feita na entrada, em `sms_data_utc_ou_null()`.
--
-- 2) `eventos_raw` guarda, por comando, o item do webhook EXATAMENTE como a
--    Allcance mandou. `webhook_payloads` já guarda o corpo inteiro da
--    requisição (v4.17.12); esta coluna é o recorte por comando, para não
--    precisar garimpar JSON por referência quando a pergunta é "o que o
--    provedor disse sobre ESTE envio?".
-- ============================================================================

-- 🔴 Sessão em UTC: `created_at` é TIMESTAMP e o MySQL o converte para o fuso
-- da sessão na leitura. O CLI usa o fuso do SISTEMA (que em produção é BRT),
-- então sem esta linha a comparação do passo 2 abaixo compararia BRT com UTC e
-- "consertaria" as linhas erradas.
SET time_zone = '+00:00';

-- ── 1. Coluna dos eventos crus ──────────────────────────────────────────────
--
-- 🔴 Condicional, não `ALTER` puro: o remédio documentado para migração nova é
-- rodar `./scripts/deploy.sh --force` DUAS vezes, então esta migração roda
-- duas vezes por construção. Um `ADD COLUMN` repetido devolve 1060 Duplicate
-- column e **aborta o script inteiro** — o conserto de fuso do passo 2, a
-- versão e as conferências nunca rodariam. Mesmo padrão da v4.16.0.
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

CALL add_column_if_not_exists('sms_commands', 'eventos_raw',
    "json DEFAULT NULL COMMENT 'Itens do webhook da Allcance, como chegaram (append por evento)' AFTER `resposta_em`");

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;

-- ── 2. Conserto das linhas gravadas em BRT ──────────────────────────────────
--
-- O critério NÃO é a data da migração: é a ASSINATURA do defeito. `created_at`
-- é UTC de verdade (TIMESTAMP), e o provedor confirma a entrega segundos depois
-- do envio — então uma linha sã tem `entregue_em` ~= `created_at`. Estar 2 h ou
-- mais ATRÁS só acontece com a string BRT gravada como UTC.
--
-- Usar a assinatura em vez de um corte de data é o que torna esta migração
-- segura de rodar depois do deploy do código: linha já gravada em UTC pela
-- versão nova não casa com o WHERE e não é tocada duas vezes.

UPDATE `sms_commands`
   SET `entregue_em` = `entregue_em` + INTERVAL 3 HOUR
 WHERE `entregue_em` IS NOT NULL
   AND `entregue_em` < `created_at` - INTERVAL 2 HOUR;

UPDATE `sms_commands`
   SET `resposta_em` = `resposta_em` + INTERVAL 3 HOUR
 WHERE `resposta_em` IS NOT NULL
   AND `resposta_em` < `created_at` - INTERVAL 2 HOUR;

-- ============================================================
-- Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.17.13', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.17.13', last_update = NOW();

-- ============================================================
-- Conferência
-- ============================================================
SELECT CONCAT('coluna eventos_raw existe: ', COUNT(*)) AS status
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_commands'
   AND COLUMN_NAME = 'eventos_raw';

-- Deve devolver 0. Qualquer linha aqui é carimbo do provedor ainda em BRT.
SELECT CONCAT('linhas ainda defasadas: ', COUNT(*)) AS status
  FROM sms_commands
 WHERE (entregue_em IS NOT NULL AND entregue_em < created_at - INTERVAL 2 HOUR)
    OR (resposta_em IS NOT NULL AND resposta_em < created_at - INTERVAL 2 HOUR);

SELECT 'Migracao v4.17.13 concluida' AS status;
