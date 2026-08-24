-- ============================================================================
-- JIMI Webhook System — Migração v4.10.4
--
-- Falha de lógica no cadastro: um chip (sim_cards) só pode estar vinculado a
-- UM equipamento por vez, mas nada no banco garantia isso — `sim_cards.imei`
-- tinha só um índice comum (idx_sim_imei), não UNIQUE. O cadastro de chip
-- (handlers/chips.php) deixava escolher QUALQUER equipamento no <select>,
-- inclusive um que já tivesse outro chip vinculado, e o cadastro de
-- equipamento (handlers/ativos_novo.php / ativos.php) nem tinha campo de
-- chip — o vínculo só existia entrando pela tela de Chips, na direção
-- contrária à que faz sentido operacionalmente (o chip já existe no estoque
-- antes do equipamento; quando a câmera chega, você escolhe um chip LIVRE
-- para ela).
--
-- Esta migração só garante a trava no banco. As trocas de tela (novo campo
-- "Chip" em /ativos e /ativos/novo, filtro por chip livre nos dois sentidos)
-- estão no código desta versão, não aqui.
--
-- ⚠️ Duplicata existente TRAVA esta migração de propósito — corrigir supondo
-- qual dos dois vínculos é o certo seria inventar um fato que o banco não
-- tem. Se o SELECT de diagnóstico abaixo devolver linhas, resolva-as à mão
-- (decida qual sim_cards.id fica com o IMEI e zere o outro) e rode de novo.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

SET @dup := (
    SELECT COUNT(*) FROM (
        SELECT imei FROM sim_cards
        WHERE imei IS NOT NULL AND imei <> ''
        GROUP BY imei HAVING COUNT(*) > 1
    ) x
);

SELECT * FROM sim_cards
 WHERE imei IN (
     SELECT imei FROM sim_cards
     WHERE imei IS NOT NULL AND imei <> ''
     GROUP BY imei HAVING COUNT(*) > 1
 )
 ORDER BY imei, id;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'sim_cards'
              AND index_name = 'uk_sim_imei');
SET @sql := IF(@c = 0 AND @dup = 0,
    'ALTER TABLE `sim_cards` ADD UNIQUE KEY `uk_sim_imei` (`imei`)',
    IF(@c > 0,
       'SELECT ''uk_sim_imei ja existe'' AS status',
       'SELECT ''uk_sim_imei NAO criado — ha duplicata (ver SELECT acima); resolva e rode a migracao de novo'' AS status'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
