-- ============================================================================
-- JIMI Webhook System — Migração v4.9.5
--
-- Unifica a CATEGORIA do alarme, que estava dividida por protocolo e por
-- idioma, e com isso torna o evento único no cadastro de ocorrências.
--
-- O problema, medido no homolog: a mesma categoria existia duas vezes, uma em
-- inglês (nas linhas JIMI) e outra em português (nas linhas JT/T):
--
--     Driving (JIMI 5, JTT 3)  ×  conducao (JTT 7)
--     Security (JIMI 11)       ×  seguranca (JTT 3)
--     Vehicle (JIMI 9)         ×  veiculo (JTT 11)
--     Device (JIMI 22, JTT 6)  ×  dispositivo (JTT 8)
--     Geofence (JIMI 2)        ×  cerca (JTT 2)
--     Emergency (JIMI 1)       ×  emergencia (JTT 1)
--
-- Consequência na tela: o `<optgroup>` do cadastro de ocorrências é montado a
-- partir de `category`, então o usuário via "Driving" e "Condução" como dois
-- grupos distintos — e o MESMO evento aparecia nos dois, uma vez por protocolo.
-- É a origem da duplicata que o pedido descreve ("Colisão com Pedestre" em
-- JIMI:207 e JT/T:264-4 como se fossem dois eventos).
--
-- A prova de que as duas coisas são o mesmo defeito: os ÚNICOS 6 nomes de
-- alarme que cruzam mais de uma categoria cruzam exatamente estes pares
-- (Aceleração Brusca, Alerta de Deslocamento, Colisão do Veículo, Entrada e
-- Saída de Cerca Eletrônica, Excesso de Velocidade). Depois desta migração
-- nenhum nome cruza categoria, e agrupar o dropdown por nome passa a ser
-- inequívoco — a conferência no fim do arquivo verifica isso.
--
-- ⚠️ `DMS` e `ADAS` NÃO são traduzidos. São siglas técnicas do setor (Driver
-- Monitoring System / Advanced Driver Assistance System), não palavras em
-- inglês, e `handlers/rel_alarmes.php` filtra por `category IN ('DMS','ADAS')`.
-- Traduzi-las quebraria o filtro de tipos do Relatório de Alarmes. A tradução
-- para o usuário é feita na EXIBIÇÃO, por `alarm_category_label()`.
--
-- ⚠️ O REMAP DE `notification_rules` NÃO É OPCIONAL. `notification_engine.php`
-- casa a regra por `at.category = nr.alarm_type`, e as 6 regras do homolog
-- casam TODAS por categoria (nenhuma por nome). Renomear a categoria sem
-- remapear a regra faria a notificação parar de disparar em silêncio — o mesmo
-- modo de falha que a v4.8.3 causou no motor de ocorrências e que a v4.8.6
-- precisou consertar. Ver CLAUDE.md.
--
-- `occurrence_config_params` NÃO precisa de remap: os 22 parâmetros do homolog
-- casam por NOME (`alarm_name_pt`), nenhum por categoria. Conferido antes de
-- escrever esta migração, e a conferência final prova que continua assim.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── 1. Categoria canônica em alarm_types ────────────────────────────────────
UPDATE `alarm_types` SET `category` = 'acidente'    WHERE `category` = 'Accident';
UPDATE `alarm_types` SET `category` = 'cerca'       WHERE `category` = 'Geofence';
UPDATE `alarm_types` SET `category` = 'conducao'    WHERE `category` = 'Driving';
UPDATE `alarm_types` SET `category` = 'dispositivo' WHERE `category` = 'Device';
UPDATE `alarm_types` SET `category` = 'emergencia'  WHERE `category` = 'Emergency';
UPDATE `alarm_types` SET `category` = 'pessoal'     WHERE `category` = 'Personal';
UPDATE `alarm_types` SET `category` = 'seguranca'   WHERE `category` = 'Security';
UPDATE `alarm_types` SET `category` = 'sensor'      WHERE `category` = 'Sensor';
UPDATE `alarm_types` SET `category` = 'veiculo'     WHERE `category` = 'Vehicle';
UPDATE `alarm_types` SET `category` = 'video'       WHERE `category` = 'Video';
-- `DMS` e `ADAS` ficam como estão, de propósito (ver cabeçalho).

-- ── 2. Regras de notificação que casavam pela categoria antiga ──────────────
-- `UPDATE IGNORE` por causa da UNIQUE KEY (customer_key, alarm_type): se o
-- cliente já tiver uma regra para o nome novo, a antiga não pode sobrescrevê-la
-- — ela é removida logo abaixo, como a v4.9.0 fez no caso análogo.
UPDATE IGNORE `notification_rules` SET `alarm_type` = 'acidente'   WHERE `alarm_type` = 'Accident';
UPDATE IGNORE `notification_rules` SET `alarm_type` = 'cerca'      WHERE `alarm_type` = 'Geofence';
UPDATE IGNORE `notification_rules` SET `alarm_type` = 'conducao'   WHERE `alarm_type` = 'Driving';
UPDATE IGNORE `notification_rules` SET `alarm_type` = 'dispositivo' WHERE `alarm_type` = 'Device';
UPDATE IGNORE `notification_rules` SET `alarm_type` = 'emergencia' WHERE `alarm_type` = 'Emergency';
UPDATE IGNORE `notification_rules` SET `alarm_type` = 'pessoal'    WHERE `alarm_type` = 'Personal';
UPDATE IGNORE `notification_rules` SET `alarm_type` = 'seguranca'  WHERE `alarm_type` = 'Security';
UPDATE IGNORE `notification_rules` SET `alarm_type` = 'sensor'     WHERE `alarm_type` = 'Sensor';
UPDATE IGNORE `notification_rules` SET `alarm_type` = 'veiculo'    WHERE `alarm_type` = 'Vehicle';
UPDATE IGNORE `notification_rules` SET `alarm_type` = 'video'      WHERE `alarm_type` = 'Video';

-- Sobra do UPDATE IGNORE: regra antiga que não pôde migrar porque a nova já
-- existia. Manter as duas deixaria uma regra morta (categoria inexistente).
DELETE FROM `notification_rules`
 WHERE `alarm_type` IN ('Accident','Geofence','Driving','Device','Emergency',
                        'Personal','Security','Sensor','Vehicle','Video');

-- ── 3. Conferência: regra de notificação órfã ───────────────────────────────
-- Deve devolver ZERO linhas. Uma linha aqui = regra que não casa com nenhum
-- alarme (nem por nome, nem por categoria, nem por código) e que portanto
-- nunca mais dispara — em silêncio, que é o que torna este defeito caro.
SELECT nr.id, nr.alarm_type AS regra_orfa
  FROM `notification_rules` nr
 WHERE NOT EXISTS (SELECT 1 FROM `alarm_types` t WHERE t.alarm_name_pt = nr.alarm_type)
   AND NOT EXISTS (SELECT 1 FROM `alarm_types` t WHERE t.alarm_name_en = nr.alarm_type)
   AND NOT EXISTS (SELECT 1 FROM `alarm_types` t WHERE t.category      = nr.alarm_type)
   AND NOT EXISTS (SELECT 1 FROM `alarm_types` t WHERE t.alarm_code    = nr.alarm_type);

-- ── 4. Conferência: parâmetro de ocorrência órfão ───────────────────────────
-- Deve devolver ZERO linhas (esta migração não toca em nome de alarme, então
-- nada deveria mudar aqui — a conferência existe para provar, não para supor).
SELECT ocp.alarm_type AS parametro_orfao
  FROM `occurrence_config_params` ocp
  LEFT JOIN `alarm_types` at ON at.alarm_name_pt = ocp.alarm_type
 WHERE at.id IS NULL;

-- ── 5. Conferência: nenhum nome pode cruzar categoria ───────────────────────
-- Deve devolver ZERO linhas. É a condição que torna o dropdown de evento único
-- inequívoco: agrupado por nome, cada evento cai em UM `<optgroup>` só.
SELECT alarm_name_pt, COUNT(DISTINCT category) AS categorias_distintas
  FROM `alarm_types`
 GROUP BY alarm_name_pt
HAVING COUNT(DISTINCT category) > 1;

-- ── 6. Conferência: nenhuma categoria em inglês pode restar ─────────────────
-- Deve devolver ZERO linhas. `DMS` e `ADAS` são siglas e estão fora da lista.
--
-- ⚠️ `BINARY` não é enfeite. A collation da coluna é `utf8mb4_unicode_ci`, que
-- ignora caixa: sem ele, `IN ('Sensor','Video')` casa os valores JÁ corrigidos
-- (`sensor`, `video`) e a conferência acusa duas linhas que estão certas. Foi
-- o que aconteceu na primeira execução desta migração. Comparação sensível a
-- caixa é o que distingue "ainda em inglês" de "já normalizado".
SELECT DISTINCT category AS categoria_em_ingles
  FROM `alarm_types`
 WHERE BINARY category IN ('Accident','Geofence','Driving','Device','Emergency',
                           'Personal','Security','Sensor','Vehicle','Video');

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.5', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.5', last_update = NOW();

SELECT 'Migracao v4.9.5 concluida' AS status;
