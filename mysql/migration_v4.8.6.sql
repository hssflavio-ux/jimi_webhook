-- ============================================================
-- Migração v4.8.6 — religa o motor de ocorrências, quebrado pela v4.8.3
-- ============================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- 🔴 O QUE ACONTECEU. `occurrence_config_params.alarm_type` guarda o **NOME**
-- do alarme, não o código. `get_occurrence_param()` (includes/occurrence_engine.php)
-- resolve o parâmetro assim:
--
--     LEFT JOIN alarm_types at ON (at.alarm_name_pt = ocp.alarm_type OR ...)
--     WHERE ocp.alarm_type = :atype OR ocp.alarm_type = :aname OR at.alarm_code = :atype2
--
-- A v4.8.3 renomeou dezenas de alarmes DMS/ADAS (o prefixo `DMS:` da §7b, os
-- sete subtipos deslocados, o "Nível" que saiu do nome da fadiga) e **não
-- remapeou esta tabela**. Sem o nome antigo em `alarm_types`, o JOIN não casa,
-- `at.alarm_code` vem NULL, nenhum parâmetro é encontrado — e o alarme é
-- gravado normalmente **sem gerar ocorrência**. Falha silenciosa: nada no log,
-- nada na tela, o alarme aparece nos relatórios e a ocorrência simplesmente
-- não nasce. No homolog isso matou **21 dos 41** parâmetros.
--
-- Ocorrência de comportamento do motorista é o NÚCLEO do produto (CLAUDE.md),
-- então isto não é detalhe de configuração: é o motor parado.
--
-- COMO FOI DESCOBERTO. Provisionando `TEST_IMEI`/`WEBHOOK_TOKEN` para tirar
-- `webhook_occurrence.spec.js` do estado "pulado". O spec existe desde a Fase
-- M.4 e nunca havia rodado. Na primeira execução real ele falhou: o alarme 143
-- chegou, foi gravado, e nenhuma ocorrência apareceu. O mesmo IMEI com o mesmo
-- alarme gerava ocorrência até 09/07/2026 (`occurrences` 1–5) — prova de que é
-- regressão, não configuração ausente.
--
-- ── Regra da mesclagem, e por que ela é esta ──────────────────────────────
-- Vários nomes antigos apontam para o MESMO nome novo (a v4.8.3 fundiu
-- "Nível 1"/"Nível 2" numa só fadiga, por exemplo), e existe UNIQUE
-- (config_id, alarm_type). Ao fundir, fica `MAX(generates_occurrence)` e o
-- maior `risk` do grupo — inclusive contra a linha de nome novo que já exista.
--
-- É decisão consciente, não sorteio: esses parâmetros estavam **mortos**, não
-- casavam com nada, então nenhum comportamento recente dependia do valor
-- deles; e, num produto de segurança, errar para "gera a ocorrência" mostra o
-- evento em vez de escondê-lo. Quem quiser desligar, desliga em
-- /config-ocorrencias, que é fluxo suportado.
--
-- Backup recomendado antes:
--   mysqldump ... occurrence_config_params occurrences > backup_ocorrencias.sql

DROP TABLE IF EXISTS _remap_v486;
CREATE TABLE _remap_v486 (
    antigo VARCHAR(50) NOT NULL PRIMARY KEY,
    novo   VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada linha é "nome que a v4.8.3 aposentou" → "nome que existe hoje em
-- alarm_types". Os alvos foram conferidos contra o catálogo, não supostos.
INSERT INTO _remap_v486 (antigo, novo) VALUES
  ('Distração do Motorista',            'DMS: Distração do Motorista'),      -- 143, §7b
  ('Motorista ao Telefone',             'DMS: Motorista ao Telefone'),       -- 151, §7b
  ('Motorista Fumando',                 'DMS: Motorista Fumando'),           -- 154, §7b
  ('Motorista Bocejando',               'DMS: Motorista Bocejando'),         -- 160, §7b
  ('DMS: Bocejando',                    'DMS: Motorista Bocejando'),         -- funde com o de cima
  ('Câmera DMS Bloqueada',              'DMS: Câmera Obstruída'),            -- 161
  ('DMS: Lente da Câmera Bloqueada',    'DMS: Câmera Obstruída'),            -- funde
  ('DMS: Sem Cinto de Segurança',       'DMS: Cinto Não Afivelado'),         -- 167 / 265-10 / 132
  ('DMS: Uso de Celular ao Volante',    'DMS: Uso de Celular'),              -- 265-13
  ('DMS: Fadiga ao Dirigir (Nível 1)',  'DMS: Fadiga ao Dirigir'),           -- 265-1; o nível vem de fatigueLevel
  ('DMS: Fadiga ao Dirigir (Nível 2)',  'DMS: Fadiga ao Dirigir'),           -- funde
  ('Fadiga Extrema do Motorista',       'DMS: Fadiga ao Dirigir'),           -- funde; 147 virou colisão na v4.8.3
  ('DMS: Ausência do Motorista',        'DMS: Motorista não Detectado'),     -- 265-5
  ('Motorista Ausente',                 'DMS: Motorista não Detectado'),     -- funde
  ('DMS: Distração Visual',             'DMS: Direção Distraída'),           -- 265-4 (distracted driving)
  ('ADAS: Colisão com Pedestre',        'ADAS: Colisão com Pedestre (PCW)'); -- 207 / 264-4

-- ── 1. Valores mesclados por (config_id, nome novo) ───────────────────────
-- Entram no cálculo tanto as linhas de nome ANTIGO quanto a de nome NOVO que
-- por acaso já exista — senão o upsert atropelaria uma configuração viva.
DROP TABLE IF EXISTS _merged_v486;
CREATE TABLE _merged_v486 ENGINE=InnoDB AS
SELECT config_id, alarm_type,
       MAX(generates_occurrence) AS generates_occurrence,
       MAX(risk)                 AS risk,   -- enum: baixo < medio < alto
       MIN(threshold)            AS threshold
  FROM (
        SELECT ocp.config_id, r.novo AS alarm_type,
               ocp.generates_occurrence, ocp.risk, ocp.threshold
          FROM occurrence_config_params ocp
          JOIN _remap_v486 r ON r.antigo = ocp.alarm_type
        UNION ALL
        SELECT ocp.config_id, ocp.alarm_type,
               ocp.generates_occurrence, ocp.risk, ocp.threshold
          FROM occurrence_config_params ocp
          JOIN _remap_v486 r2 ON r2.novo = ocp.alarm_type
  ) u
 GROUP BY config_id, alarm_type;

-- ── 2. Fora os nomes aposentados, dentro os mesclados ─────────────────────
DELETE ocp FROM occurrence_config_params ocp
  JOIN _remap_v486 r ON r.antigo = ocp.alarm_type;

INSERT INTO occurrence_config_params (config_id, alarm_type, generates_occurrence, risk, threshold)
SELECT config_id, alarm_type, generates_occurrence, risk, threshold FROM _merged_v486
ON DUPLICATE KEY UPDATE
    generates_occurrence = VALUES(generates_occurrence),
    risk                 = VALUES(risk),
    threshold            = VALUES(threshold);

-- ── 3. Lixo de codificação (só em bases de desenvolvimento) ───────────────
-- Linhas com mojibake ("Distra├º├úo do Motorista") são duplicatas corrompidas
-- de importação antiga: não casam com nada, nunca casaram, e poluem a tela de
-- /config-ocorrencias com opções que não fazem nada. Nenhuma existe no homolog.
DELETE FROM occurrence_config_params WHERE alarm_type LIKE '%├%';

DROP TABLE _remap_v486;
DROP TABLE _merged_v486;

-- ── 4. Conferência (aparece no log do deploy) ─────────────────────────────
SELECT CONCAT(
         SUM(at.id IS NOT NULL), ' de ', COUNT(*),
         ' parâmetro(s) de ocorrência resolvem para um alarme do catálogo'
       ) AS resultado
  FROM occurrence_config_params ocp
  LEFT JOIN alarm_types at ON (at.alarm_name_pt = ocp.alarm_type
                            OR at.alarm_name_en = ocp.alarm_type
                            OR at.category      = ocp.alarm_type);

-- Os que sobrarem são nomes SEM alvo no catálogo — "Capotamento",
-- "Olhar Lateral Prolongado", "Comendo ou Bebendo ao Volante" e afins, que a
-- doc oficial não publica (a v4.8.1/v4.8.3 mostraram que eram inventados).
-- NÃO são apagados de propósito: são configuração visível do usuário, e apagar
-- ajuste alheio por conta própria é mais invasivo do que deixar um botão que
-- não dispara. Ficam listados aqui para decisão.
SELECT ocp.alarm_type AS orfao_sem_alvo, ocp.generates_occurrence, ocp.risk
  FROM occurrence_config_params ocp
  LEFT JOIN alarm_types at ON (at.alarm_name_pt = ocp.alarm_type
                            OR at.alarm_name_en = ocp.alarm_type
                            OR at.category      = ocp.alarm_type)
 WHERE at.id IS NULL
 ORDER BY ocp.alarm_type;

-- ── Versão ────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.8.6', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.8.6', last_update = NOW();
