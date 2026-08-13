-- ============================================================================
-- JIMI Webhook System — Migração v4.9.10
--
-- Fecha os DOIS códigos que ainda chegavam sem nome ao homolog, medidos em
-- 12/08/2026 com o mesmo par de JOINs que `alarm_label_sql()` usa na leitura:
--
--     JT/T 1047  → 10 linhas   (05/08 a 12/08/2026)  "Código 1047 (JTT)"
--     JIMI  146  →  4 linhas   (11/08/2026)          "Código 146 (JIMI)"
--
-- Eram os únicos. Depois desta migração a consulta de conferência (§6) devolve
-- ZERO linhas.
--
-- ── 1047: CAPOTAMENTO, por informação do fornecedor ─────────────────────────
--
-- ⚠️ O `CLAUDE.md` diz que só entram códigos que a **doc oficial publica**, e
-- 1047 continua fora dela: a tabela 2.7 "Other Alarms" vai de 1046 (*Collision
-- (ACDU)*) direto para 3073 (conferido no HTML servido em 12/08/2026). O que
-- mudou não foi a doc — foi haver **informação do fornecedor**: 1047 é
-- capotamento. A regra existia contra batizar por PALPITE, não contra
-- catalogar o que se sabe.
--
-- Duas evidências independentes sustentam a informação:
--   a) o próprio JT/T 808 põe capotamento ao lado de colisão no bitmask padrão
--      — bit 27 `Pré-aviso de Colisão`, bit 28 `Pré-aviso de Capotamento`
--      (ver `decodeStandardAlarm()` em pushalarm.php);
--   b) na faixa 1042–1046 a doc segue a mesma ordem das unidades ADAS
--      (AHADU, AHBDU, AHTDU, … ACDU); 1047 é o passo seguinte.
--
-- ── O NOME É CHAVE DE JUNÇÃO, e é por isso que a linha JIMI é renomeada ─────
--
-- O mesmo evento físico já existia no catálogo do outro protocolo: JIMI `45`,
-- *Vehicle tipped over onto its side*, gravado como **"Veículo Tumbado"** —
-- que não é português ("tumbado" é espanhol; a doc diz *tipped over*).
--
-- Cadastrar 1047 como "Capotamento" e deixar 45 como "Veículo Tumbado" criaria
-- exatamente o defeito que a v4.9.0 documentou para os pares 1024/1042: **o
-- filtro da tela casa por NOME**, então o usuário escolheria um dos dois
-- rótulos e perderia metade dos eventos — os do outro protocolo. Os dois
-- passam a se chamar "Capotamento", e a junção por nome (`get_occurrence_param()`
-- casa por `at.alarm_name_pt = ocp.alarm_type`) passa a cobrir os dois de uma vez.
--
-- O remapeamento de `occurrence_config_params` acompanha a renomeação, como o
-- CLAUDE.md exige. No homolog não há linha apontando para "Veículo Tumbado"
-- (conferido: 0), mas outra instalação pode ter — e um parâmetro órfão desliga
-- a geração de ocorrência **em silêncio**.
--
-- ── categoria `acidente`, não `veiculo` ─────────────────────────────────────
--
-- 1046 (Colisão) está em `veiculo`; capotamento vai para `acidente`. Não é
-- inconsistência: é seguir a classificação que o MESMO evento já tem no outro
-- protocolo (JIMI 45 é `acidente` desde sempre) em vez de inventar uma.
--
-- A categoria tem consequência real: `notification_engine.php` casa a regra por
-- `at.category = nr.alarm_type`, e no homolog há regra ATIVA para `acidente`
-- (id 2) e NENHUMA para `veiculo`. Em `acidente`, capotamento notifica.
--
-- 🔴 Fica REGISTRADO e NÃO corrigido aqui: `Colisão do Veículo` (JT/T 1046 e
-- JIMI 147) está em `veiculo` e portanto **não dispara notificação**, enquanto
-- `Airbag Acionado / Colisão` (JIMI 30) está em `acidente` e dispara. Mover
-- colisão para `acidente` aumentaria o volume notificado de um alarme frequente
-- — é decisão de produto, não de migração, e por isso está fora desta.
--
-- ── 144/145/146: a geração nova de condução brusca do JIMI ──────────────────
--
-- A doc (`1.7 Driving Behavior Alerts`) publica os três; o catálogo tinha só os
-- antigos 41 e 48. Só o 146 chegou até agora, mas os três vêm do mesmo grupo de
-- firmware — cadastrar apenas o que já apareceu deixaria os outros dois caindo
-- no rótulo genérico no dia em que aparecerem, que é o defeito que a v4.8.1
-- criou ao inserir 11 de 39 códigos.
--
-- Nomes IDÊNTICOS aos das linhas JT/T equivalentes (1042/1043/1044), de novo
-- porque o filtro casa por nome. Severidade também espelha a geração "harsh".
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── 1. JT/T 1047 — Capotamento ──────────────────────────────────────────────
INSERT IGNORE INTO alarm_types
    (alarm_code, protocol, category, severity, alarm_name_pt, alarm_name_en, requires_action, is_diagnostic)
VALUES
    ('1047','JTT','acidente','critical','Capotamento','Rollover',1,0);

-- ── 2. JIMI 45 — "Veículo Tumbado" passa a se chamar "Capotamento" ─────────
-- `alarm_name_en` também é corrigido: a doc diz *Vehicle tipped over onto its
-- side*, e `get_occurrence_param()` também casa por `at.alarm_name_en`.
UPDATE alarm_types
   SET alarm_name_pt = 'Capotamento',
       alarm_name_en = 'Vehicle tipped over onto its side'
 WHERE protocol = 'JIMI' AND alarm_code = '45'
   AND alarm_name_pt = 'Veículo Tumbado';

-- 2a. Remapeia quem apontava para o nome antigo. `UPDATE IGNORE` por causa das
--     chaves únicas — se a instalação já tiver os DOIS nomes, a linha velha é
--     removida no passo seguinte em vez de estourar a chave.
UPDATE IGNORE occurrence_config_params
   SET alarm_type = 'Capotamento'
 WHERE alarm_type = 'Veículo Tumbado';

DELETE FROM occurrence_config_params
 WHERE alarm_type = 'Veículo Tumbado';

UPDATE IGNORE notification_rules
   SET alarm_type = 'Capotamento'
 WHERE alarm_type = 'Veículo Tumbado';

DELETE FROM notification_rules
 WHERE alarm_type = 'Veículo Tumbado';

-- ── 3. JIMI 144/145/146 — condução brusca (geração nova) ───────────────────
INSERT IGNORE INTO alarm_types
    (alarm_code, protocol, category, severity, alarm_name_pt, alarm_name_en, requires_action, is_diagnostic)
VALUES
    ('144','JIMI','conducao','critical','Aceleração Brusca','Vehicle has been accelerated harshly',0,0),
    ('145','JIMI','conducao','critical','Frenagem Brusca',  'Vehicle has been braked hard',        0,0),
    ('146','JIMI','conducao','warning', 'Curva Brusca',     'Vehicle has turned abruptly',         0,0);

-- ── 4. O parâmetro de ocorrência de capotamento volta ao perfil padrão ──────
--
-- A v4.0.0 semeou `(1, 'Capotamento', 1, 'alto', 5)`. A v4.8.7 o APAGOU, e a
-- justificativa dela é o que esta migração acabou de derrubar: "nome SEM alvo
-- no catálogo". Agora há alvo — nos dois protocolos.
--
-- Sem este parâmetro o capotamento seria catalogado, apareceria no relatório e
-- **não geraria ocorrência nenhuma**: `process_alarm_occurrence()` retorna cedo
-- quando `get_occurrence_param()` devolve NULL. Nomear o alarme e parar aí
-- entregaria meia correção — e do lado invisível, que é o modo de falha que o
-- CLAUDE.md descreve três vezes.
--
-- `risk` e `threshold` copiam o irmão de acidente que já existe no perfil
-- (`Airbag Acionado / Colisão`: alto / 5 min). Só o perfil PADRÃO é tocado —
-- perfil que o cliente montou é configuração dele.
INSERT IGNORE INTO occurrence_config_params
    (config_id, alarm_type, generates_occurrence, risk, threshold)
SELECT oc.id, 'Capotamento', 1, 'alto', 5
  FROM occurrence_configs oc
 WHERE oc.is_default = 1;

-- ── 5. Conferência: parâmetro de ocorrência sem alarme correspondente ───────
-- Deve devolver ZERO linhas. Uma linha aqui = motor de ocorrências desligado em
-- silêncio para aquele parâmetro (a armadilha da v4.8.3/v4.8.6).
SELECT ocp.alarm_type AS parametro_orfao, ocp.config_id
  FROM occurrence_config_params ocp
  LEFT JOIN alarm_types at ON (at.alarm_name_pt = ocp.alarm_type
                            OR at.alarm_name_en = ocp.alarm_type
                            OR at.category      = ocp.alarm_type)
 WHERE at.id IS NULL;

-- ── 6. Conferência: sobrou algum alarme SEM nome no histórico? ──────────────
-- Deve devolver ZERO linhas. Mesmos JOINs de `alarm_label_sql()` — composto
-- primeiro, base depois —, então o que passar aqui é exatamente o que a tela
-- mostraria como "Código NNNN (PROTOCOLO)".
SELECT a.msg_class, a.alarm_type, a.alarm_subtype, a.alarm_name AS nome_gravado,
       COUNT(*) AS linhas, MAX(a.alarm_time) AS ultimo
  FROM alarms a
  LEFT JOIN alarm_types atc ON a.msg_class = 1 AND a.alarm_subtype IS NOT NULL
                           AND atc.protocol = 'JTT'
                           AND atc.alarm_code = CONCAT(a.alarm_type, '-', a.alarm_subtype)
  LEFT JOIN alarm_types atb ON atb.protocol = IF(a.msg_class = 1, 'JTT', 'JIMI')
                           AND atb.alarm_code = a.alarm_type
 WHERE COALESCE(atc.alarm_name_pt, atb.alarm_name_pt) IS NULL
 GROUP BY a.msg_class, a.alarm_type, a.alarm_subtype, a.alarm_name
 ORDER BY linhas DESC;

-- ── 7. Conferência: o rótulo que a tela passa a mostrar ────────────────────
-- As 14 linhas históricas têm `alarms.alarm_name` congelado em "Código …"; quem
-- as conserta é a re-resolução na leitura, não um UPDATE. Esta consulta usa a
-- MESMA expressão de `alarm_label_sql()['expr']`, então é a prova de tela.
SELECT a.alarm_type, a.alarm_name AS gravado,
       CASE WHEN COALESCE(atc.alarm_name_pt, atb.alarm_name_pt) IS NULL
                 THEN COALESCE(NULLIF(a.alarm_name, ''), a.alarm_type)
            WHEN a.alarm_name LIKE 'Fim de Alarme: Código %'
                 THEN CONCAT('Fim de Alarme: ', COALESCE(atc.alarm_name_pt, atb.alarm_name_pt))
            WHEN a.alarm_name IS NULL OR a.alarm_name = '' OR a.alarm_name LIKE 'Código %'
                 THEN COALESCE(atc.alarm_name_pt, atb.alarm_name_pt)
            ELSE a.alarm_name
       END AS exibido,
       COUNT(*) AS linhas
  FROM alarms a
  LEFT JOIN alarm_types atc ON a.msg_class = 1 AND a.alarm_subtype IS NOT NULL
                           AND atc.protocol = 'JTT'
                           AND atc.alarm_code = CONCAT(a.alarm_type, '-', a.alarm_subtype)
  LEFT JOIN alarm_types atb ON atb.protocol = IF(a.msg_class = 1, 'JTT', 'JIMI')
                           AND atb.alarm_code = a.alarm_type
 WHERE a.alarm_name LIKE 'Código %'
 GROUP BY a.alarm_type, a.alarm_name, exibido
 ORDER BY linhas DESC;

-- ── 8. Conferência: sobrou "Veículo Tumbado" em alguma camada? ─────────────
-- `BINARY` de propósito: a collation é utf8mb4_unicode_ci e sem ele a
-- comparação é frouxa (a lição da v4.9.5). Deve devolver ZERO linhas.
SELECT 'alarm_types' AS tabela, COUNT(*) AS sobras FROM alarm_types
 WHERE BINARY alarm_name_pt = 'Veículo Tumbado'
UNION ALL
SELECT 'occurrence_config_params', COUNT(*) FROM occurrence_config_params
 WHERE BINARY alarm_type = 'Veículo Tumbado'
UNION ALL
SELECT 'notification_rules', COUNT(*) FROM notification_rules
 WHERE BINARY alarm_type = 'Veículo Tumbado';

-- ── 9. Conferência: as linhas novas, como ficaram ──────────────────────────
SELECT protocol, alarm_code, category, severity, alarm_name_pt, alarm_name_en, requires_action
  FROM alarm_types
 WHERE (protocol = 'JTT'  AND alarm_code = '1047')
    OR (protocol = 'JIMI' AND alarm_code IN ('45','144','145','146'))
 ORDER BY protocol, CAST(alarm_code AS UNSIGNED);

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.10', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.10', last_update = NOW();

SELECT 'Migracao v4.9.10 concluida' AS status;
