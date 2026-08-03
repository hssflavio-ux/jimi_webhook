-- ============================================================
-- Migração v4.8.4 — decisão sobre os códigos JIMI ambíguos
-- ============================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- CONTEXTO. Dos 197 códigos JIMI da "Alarm Reference" oficial
-- (docs.jimicloud.com/integration/integration.html), exatamente QUATRO
-- aparecem duas vezes, em subseções diferentes e com sentidos conflitantes.
-- Conferido no HTML servido em 03/08/2026 — varredura de todas as `<tr>` da
-- seção 1, que confirma serem esses quatro e mais nenhum:
--
--   80  → 1.6 Peripheral-Triggered  "Door was closed"
--         1.9 Other Alerts          "Door opening alarm"
--   81  → 1.6 Peripheral-Triggered  "Door was opened"
--         1.9 Other Alerts          "Door closing alarm"
--   131 → 1.5 Vehicle Safety        "Vehicle collided"
--         1.8 Algorithm-Generated   "Seatbelt fastened alarm"
--   132 → 1.8 Algorithm-Generated   "Seatbelt unfastened alarm"
--         1.9 Other Alerts          "Camera 1 exception"
--
-- Em 80/81 as duas leituras são ESPELHADAS (uma diz "fechou" onde a outra diz
-- "abriu"), e as tabelas têm só duas colunas — não há modelo, firmware nem
-- protocolo que sirva de desempate. A v4.8.3 deixou os quatro de fora de
-- propósito: escolher um sentido sem critério é exatamente como os sete
-- subtipos DMS acabaram deslocados (`265-10` acusava "Comendo ou Bebendo"
-- quando é Cinto Não Afivelado).
--
-- DECISÃO DE PRODUTO (03/08/2026). O impasse não se resolve pela doc, mas
-- deixa de importar pelo escopo: das cinco funcionalidades em disputa (abrir
-- porta, fechar porta, colisão, cinto afivelado, falha da câmera 1), a única
-- que o sistema terá é **cinto NÃO afivelado**. Logo:
--
--   132 → catalogado como "DMS: Cinto Não Afivelado"  (a leitura de 1.8)
--   80, 81, 131 → NÃO catalogados; nenhuma das leituras é funcionalidade
--                 nossa. 131 vale notar: as duas leituras caem fora — a de
--                 1.8 é o cinto AFIVELADO, o evento positivo, não a infração.
--
-- A mesma regra alcança um código que JÁ estava catalogado: `166` ("Driver is
-- already buckled up") é o evento positivo e era um dos 33 chips do filtro.
-- Sai de DMS para Vehicle na seção 3 — recategorizado, não apagado.
--
-- ⚠️ O QUE ESTA DECISÃO NÃO RESOLVE. Ela diz o que queremos ver, não o que o
-- equipamento quis dizer. Se algum firmware emitir 132 significando "Camera 1
-- exception", o sistema passa a rotular falha de hardware como infração do
-- motorista. O risco é aceito com estes atenuantes:
--   1. Incidência ZERO — em 3.564 alarmes do homolog não há uma linha com
--      tipo 80, 81, 131 ou 132 (a frota de lá é 99% JT/T).
--   2. A funcionalidade de cinto JÁ está coberta por códigos NÃO ambíguos —
--      `167` (JIMI) e `265-10` (JT/T), ambos corrigidos na v4.8.3. O 132 é
--      redundância defensiva, para o caso de uma geração de firmware usar
--      esse número no lugar do 167.
--   3. Falha de câmera correlaciona com `107` e `161` e chega SEM mídia de
--      motorista: se 132 aparecer em produção, dá para conferir antes de
--      confiar no rótulo.
--
-- O nome é DELIBERADAMENTE idêntico ao de `167` e `265-10`: desde a v4.8.3 o
-- filtro de alarmes casa por NOME, então um único chip pega o evento venha ele
-- de qualquer um dos três códigos. Os códigos seguem separados por protocolo —
-- o isolamento do ADR-001 é de código, não de rótulo.
--
-- Nota de idempotência: a whitelist da v4.8.1 já inclui 80, 81, 131 e 132
-- entre os códigos oficiais, então reexecutar aquela migração NÃO apaga o 132
-- inserido aqui.
--
-- Backup recomendado antes: mysqldump ... alarm_types alarms > backup.sql

-- ── 1. O único dos quatro que vira alarme do sistema ──────────────────────
INSERT INTO alarm_types (alarm_code, protocol, category, severity, alarm_name_pt, alarm_name_en, requires_action) VALUES
('132','JIMI','DMS','high', 'DMS: Cinto Não Afivelado', 'Seatbelt unfastened alarm', 1)
ON DUPLICATE KEY UPDATE alarm_name_pt=VALUES(alarm_name_pt), alarm_name_en=VALUES(alarm_name_en),
                        category=VALUES(category), severity=VALUES(severity), requires_action=VALUES(requires_action);

-- ── 2. Reaplicar ao histórico já gravado ──────────────────────────────────
-- `alarms.alarm_name` é DESNORMALIZADO (pushalarm.php resolve na chegada e
-- grava a string). Sem catálogo, todo 132 que já chegou virou o fallback
-- "Código 132 (JIMI)" da linha 395 de handlers/pushalarm.php. No homolog isso
-- é zero linha; em PRODUÇÃO pode não ser — daí o UPDATE.
-- Escopo estreito de propósito: só msg_class=0 e só o código 132.
-- O prefixo "Fim de Alarme: " marca `removeAlarmType` e é preservado.
UPDATE alarms
   SET alarm_name = IF(alarm_name LIKE 'Fim de Alarme: %',
                       'Fim de Alarme: DMS: Cinto Não Afivelado',
                       'DMS: Cinto Não Afivelado')
 WHERE msg_class = 0 AND alarm_type = '132';

-- ── 3. O evento POSITIVO de cinto sai do filtro ───────────────────────────
-- Mesma regra, aplicada a um código que já estava catalogado: se só "sem uso
-- do cinto" é alarme, então `166` "Driver is already buckled up" — o motorista
-- COLOCOU o cinto — não é. Ele era um dos 33 chips oferecidos no filtro de
-- alarmes, que desde a v4.8.3 lista `WHERE category IN ('DMS','ADAS')`.
--
-- É RECATEGORIZAÇÃO, não exclusão. Sai de DMS para Vehicle: deixa de ser
-- opção do filtro (33 → 32 chips) e continua resolvendo o nome se algum
-- equipamento mandar o código — apagar a linha faria o alarme cair no
-- fallback "Código 166 (JIMI)", trocando um rótulo correto por um genérico.
-- A doc publica o 166; o que mudou é o nosso escopo, não a doc.
--
-- O prefixo "DMS:" cai junto. Ele foi criado na v4.8.3 §7b justamente porque o
-- filtro passou a listar só DMS/ADAS e as entradas precisavam se anunciar como
-- tal. Fora do filtro, o prefixo passaria a afirmar uma categoria que a linha
-- não tem mais.
UPDATE alarm_types
   SET category = 'Vehicle', alarm_name_pt = 'Cinto Afivelado', severity = 'info', requires_action = 0
 WHERE alarm_code = '166' AND protocol = 'JIMI';

UPDATE alarms
   SET alarm_name = IF(alarm_name LIKE 'Fim de Alarme: %',
                       'Fim de Alarme: Cinto Afivelado',
                       'Cinto Afivelado')
 WHERE msg_class = 0 AND alarm_type = '166';

-- Porta (`20`, `28`, `29`) fica como está: é categoria Vehicle desde sempre e
-- por isso JÁ não aparecia no filtro. Não há o que remover.

-- ── 4. Conferência (aparece no log do deploy) ─────────────────────────────
SELECT CONCAT(COUNT(*), ' código(s) de cinto não afivelado no catálogo (esperado: 3 — JIMI 132, JIMI 167, JTT 265-10)') AS resultado
  FROM alarm_types WHERE alarm_name_pt = 'DMS: Cinto Não Afivelado';

SELECT CONCAT(COUNT(DISTINCT alarm_name_pt), ' chip(s) no filtro de alarmes (esperado: 32 — era 33 com o Cinto Afivelado)') AS filtro
  FROM alarm_types WHERE category IN ('DMS','ADAS');

-- Os três descartados NÃO devem existir. Se algum aparecer aqui, alguém
-- preencheu a lacuna adivinhando — que é o erro que esta migração documenta.
SELECT CONCAT(COUNT(*), ' código(s) ambíguo(s) catalogado(s) indevidamente (esperado: 0)') AS pendencia
  FROM alarm_types WHERE protocol = 'JIMI' AND alarm_code IN ('80','81','131');

-- Se isto for > 0 em produção, os quatro códigos chegam de verdade e a
-- ambiguidade precisa ser decidida com dado real, não com a doc.
SELECT CONCAT(COUNT(*), ' alarme(s) recebido(s) com os códigos ambíguos descartados') AS observacao
  FROM alarms WHERE msg_class = 0 AND alarm_type IN ('80','81','131');

-- ── Versão ────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.8.4', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.8.4', last_update = NOW();
