-- ============================================================
-- Migração v4.8.3 — nomes de alarme conforme a documentação oficial
-- ============================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- FONTE: docs.jimicloud.com/integration/integration.html, seção "Alarm
-- Reference" (1 JIMI Device Alarms / 2 JT/T Device Alarms), extraída em
-- 02/08/2026. As subseções que interessam aqui são:
--   1.5 Vehicle Safety Alerts     1.7 Driving Behavior Alerts
--   1.8 Algorithm-Generated       1.9 Other Alerts
--   2.5 ADAS Alarm (alertType 264)   2.6 DMS Alarm (alertType 265)
--
-- ⚠️ A v4.8.1 PODOU `alarm_types` para os códigos publicados, mas não conferiu
-- os NOMES. Esta migração faz isso — e a conferência achou erros graves de
-- mapeamento, não divergências de tradução.
--
-- ── O que estava errado nos subtipos DMS (265-X) ───────────────────────────
-- A doc define `alarmType` de 0x01 a 0x11 dentro do campo composto 265. Nossa
-- tabela vinha com metade dos subtipos deslocada, o que fazia o relatório
-- ACUSAR O MOTORISTA DA COISA ERRADA. Os casos mais sérios:
--   265-6  gravado como "Captura Automática"          → é Câmera Obstruída
--   265-8  gravado como "Troca de Motorista"          → é Condução Prolongada
--   265-10 gravado como "Comendo ou Bebendo ao Volante" → é Cinto Não Afivelado
--   265-11 gravado como "Ausência do Motorista"       → é Óculos Escuros
--   265-13 gravado como "Falha na Autenticação ID"    → é Uso de Celular
--   265-16 gravado como "Bocejando"                   → é Captura Automática
--   265-17 gravado como "Fadiga ao Dirigir (Nível 2)" → é Troca de Motorista
-- "Comendo ou Bebendo", "Bocejando", "Postura da Cabeça", "Nível 2" etc. não
-- existem na tabela 2.6: eram nomes inventados ocupando subtipos reais.
--
-- ── E nos códigos-base ────────────────────────────────────────────────────
-- JIMI 147 estava como "Fadiga Extrema do Motorista" na categoria DMS; a doc
-- (1.5) diz "Vehicle collided". JIMI 44 estava como "Frenagem Brusca" — 44 é
-- colisão, quem freia forte é o 48. JTT 1040/1041 estavam como "Ociosidade
-- Excessiva"/"Ignição Não Autorizada"; a doc (2.7) diz "Sleep Mode Event" e
-- "Working Mode Event" — e são os dois códigos com mais linhas gravadas.
--
-- ── Faixas "User defined" ─────────────────────────────────────────────────
-- 264 0x08~0x0F e 0x12~0x1F, 265 0x12~0x1F são declaradas "User defined" pela
-- doc: NÃO têm nome oficial. Os que tínhamos ali (264-8..12, 265-18..21) eram
-- invenção e saem. O grupo 266 (BSD) sai pelo mesmo motivo: não existe na
-- "Alarm Reference" — nem como código-base (a v4.8.1 já o excluiu da lista
-- JTT) nem como subtipo. Nenhum deles tem uma única linha em `alarms`.
--
-- Backup recomendado antes:
--   mysqldump ... alarm_types alarms > backup_alarmes.sql

-- ── 1. Subtipos ADAS (264-X) — tabela 2.5, campo alarmType ────────────────
-- 0x06 é "Road sign overrun" (ultrapassou o limite da placa), evento distinto
-- de 0x10 "Road sign recognition" (só reconheceu a placa). Estavam fundidos.
UPDATE alarm_types SET alarm_name_pt = 'ADAS: Colisão Frontal (FCW)',        alarm_name_en = 'Forward collision alarm',            category='ADAS', severity='critical', requires_action=1 WHERE alarm_code='264-1'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'ADAS: Saída de Faixa (LDW)',         alarm_name_en = 'Lane departure alarm',               category='ADAS', severity='warning',  requires_action=0 WHERE alarm_code='264-2'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'ADAS: Distância Insegura (HMW)',     alarm_name_en = 'Vehicle distance too close alarm',   category='ADAS', severity='warning',  requires_action=0 WHERE alarm_code='264-3'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'ADAS: Colisão com Pedestre (PCW)',   alarm_name_en = 'Pedestrian collision alarm',         category='ADAS', severity='critical', requires_action=1 WHERE alarm_code='264-4'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'ADAS: Mudança de Faixa Frequente',   alarm_name_en = 'Frequent lane change alarm',         category='ADAS', severity='warning',  requires_action=0 WHERE alarm_code='264-5'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'ADAS: Excesso em Placa de Trânsito', alarm_name_en = 'Road sign overrun alarm',            category='ADAS', severity='warning',  requires_action=1 WHERE alarm_code='264-6'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'ADAS: Obstáculo à Frente',           alarm_name_en = 'Obstacle alarm',                     category='ADAS', severity='critical', requires_action=1 WHERE alarm_code='264-7'  AND protocol='JTT';

INSERT INTO alarm_types (alarm_code, protocol, category, severity, alarm_name_pt, alarm_name_en, requires_action) VALUES
('264-16','JTT','ADAS','info', 'ADAS: Reconhecimento de Placa de Trânsito', 'Road sign recognition event', 0),
('264-17','JTT','ADAS','info', 'ADAS: Captura Ativa',                       'Active capture event',        0)
ON DUPLICATE KEY UPDATE alarm_name_pt=VALUES(alarm_name_pt), alarm_name_en=VALUES(alarm_name_en),
                        category=VALUES(category), severity=VALUES(severity), requires_action=VALUES(requires_action);

-- ── 2. Subtipos DMS (265-X) — tabela 2.6, campo alarmType ─────────────────
-- O nível de fadiga NÃO é subtipo: vem em `fatigueLevel` (1~10) e em
-- `alarmLevel` (1 ou 2), colunas próprias em `alarms`. Por isso 265-1 volta a
-- ser só "Fadiga ao Dirigir", sem o "(Nível 1)" que ocupava o nome.
UPDATE alarm_types SET alarm_name_pt = 'DMS: Fadiga ao Dirigir',        alarm_name_en = 'Fatigue driving alarm',                  category='DMS', severity='critical', requires_action=1 WHERE alarm_code='265-1'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Chamada Telefônica',       alarm_name_en = 'Making or answering phone calls alarm',  category='DMS', severity='high',     requires_action=1 WHERE alarm_code='265-2'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Motorista Fumando',        alarm_name_en = 'Smoking alarm',                          category='DMS', severity='warning',  requires_action=0 WHERE alarm_code='265-3'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Direção Distraída',        alarm_name_en = 'Distracted driving alarm',               category='DMS', severity='high',     requires_action=1 WHERE alarm_code='265-4'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Motorista não Detectado',  alarm_name_en = 'Driver abnormal alarm (no driver detected)', category='DMS', severity='critical', requires_action=1 WHERE alarm_code='265-5'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Câmera Obstruída',         alarm_name_en = 'Camera blocked alarm',                   category='DMS', severity='high',     requires_action=1 WHERE alarm_code='265-6'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Condução Prolongada',      alarm_name_en = 'Over-drivering alarm',                   category='DMS', severity='high',     requires_action=1 WHERE alarm_code='265-8'  AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Cinto Não Afivelado',      alarm_name_en = 'Seatbelt unfastened alarm',              category='DMS', severity='high',     requires_action=1 WHERE alarm_code='265-10' AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Óculos Escuros',           alarm_name_en = 'Sunglasses detected alarm',              category='DMS', severity='info',     requires_action=0 WHERE alarm_code='265-11' AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Uso de Celular',           alarm_name_en = 'Phone use alarm',                        category='DMS', severity='high',     requires_action=1 WHERE alarm_code='265-13' AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Captura Automática',       alarm_name_en = 'Automatic (active) capture event',       category='DMS', severity='info',     requires_action=0 WHERE alarm_code='265-16' AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt = 'DMS: Troca de Motorista',       alarm_name_en = 'Driver change event',                    category='DMS', severity='info',     requires_action=0 WHERE alarm_code='265-17' AND protocol='JTT';

-- 0x0C existia só como "265-21" (fora da faixa oficial); entra no lugar certo.
INSERT INTO alarm_types (alarm_code, protocol, category, severity, alarm_name_pt, alarm_name_en, requires_action) VALUES
('265-12','JTT','DMS','high', 'DMS: Mãos Fora do Volante', 'Hands off steering wheel alarm', 1)
ON DUPLICATE KEY UPDATE alarm_name_pt=VALUES(alarm_name_pt), alarm_name_en=VALUES(alarm_name_en),
                        category=VALUES(category), severity=VALUES(severity), requires_action=VALUES(requires_action);

-- ── 3. Subtipos sem respaldo na doc ───────────────────────────────────────
-- 264 0x08~0x0F e 0x12+, 265 0x07 e 0x12+ são "User defined"/indefinidos; 266
-- (BSD) não consta da Alarm Reference. Nenhum tem linha em `alarms`.
DELETE FROM alarm_types
 WHERE protocol = 'JTT'
   AND (alarm_code IN ('264-8','264-9','264-10','264-11','264-12',
                       '265-7','265-18','265-19','265-20','265-21')
        OR alarm_code LIKE '266-%');

-- ── 4. Códigos-base JIMI com mapeamento trocado ───────────────────────────
UPDATE alarm_types SET alarm_name_pt='Tensão da Alimentação Externa Baixa', alarm_name_en='Voltage of external power is rather low',            category='Device',  severity='warning',  requires_action=0 WHERE alarm_code='14'  AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='Desligado Manualmente',               alarm_name_en='Device was powered off manually',                    category='Device',  severity='info',     requires_action=0 WHERE alarm_code='17'  AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='Modo Avião por Baixa Alimentação',    alarm_name_en='Device entered airplane mode due to low external power', category='Device', severity='warning', requires_action=0 WHERE alarm_code='18'  AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='Colisão do Veículo',                  alarm_name_en='Vehicle collided',                                  category='Vehicle', severity='critical', requires_action=1 WHERE alarm_code='44'  AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='Login Registrado no Dispositivo',     alarm_name_en='Device has already signed in',                       category='Device',  severity='info',     requires_action=0 WHERE alarm_code='103' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='Logout Registrado no Dispositivo',    alarm_name_en='Device has already signed out',                      category='Device',  severity='info',     requires_action=0 WHERE alarm_code='104' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='Colisão do Veículo',                  alarm_name_en='Vehicle collided',                                   category='Vehicle', severity='critical', requires_action=1 WHERE alarm_code='147' AND protocol='JIMI';

-- ── 5. Códigos-base JT/T com mapeamento trocado (2.7 Other Alarms) ────────
-- São os dois códigos com mais linhas gravadas em homologação — o relatório
-- vinha rotulando evento de modo de operação como falta de autorização.
UPDATE alarm_types SET alarm_name_pt='Evento de Modo Repouso',  alarm_name_en='Sleep Mode Event',   category='Device', severity='info', requires_action=0 WHERE alarm_code='1040' AND protocol='JTT';
UPDATE alarm_types SET alarm_name_pt='Evento de Modo Trabalho', alarm_name_en='Working Mode Event', category='Device', severity='info', requires_action=0 WHERE alarm_code='1041' AND protocol='JTT';

-- ── 6. DMS/ADAS JIMI (msgClass=0) que faltavam no catálogo ────────────────
-- Sem estes, o alarme de câmera de um equipamento JIMI caía no rótulo genérico
-- "Código NNN (JIMI)" e ficava de fora do filtro de DMS/ADAS.
INSERT INTO alarm_types (alarm_code, protocol, category, severity, alarm_name_pt, alarm_name_en, requires_action) VALUES
('48', 'JIMI','Driving','warning', 'Frenagem Brusca',                    'Vehicle has been braked hard',            0),
('71', 'JIMI','DMS',    'critical','Fadiga ao Dirigir',                  'Fatigue driving was detected',            1),
('107','JIMI','DMS',    'warning', 'Falha de Comunicação da Câmera DMS', 'DMS camera communication abnormality',    1),
('117','JIMI','DMS',    'info',    'Fadiga do Motorista Reconhecida',    'Driver fatigue is known',                 0),
('140','JIMI','DMS',    'warning', 'Piscadas Frequentes do Motorista',   'Driver has been blinking frequently',     0),
('148','JIMI','DMS',    'critical','Rosto do Motorista Não Detectado',   'No driver face detected',                 1),
('161','JIMI','DMS',    'high',    'Lente da Câmera Obstruída',          'Camera lens is blocked',                  1),
('162','JIMI','DMS',    'warning', 'Erro de Alinhamento Facial',         'Face alignment error',                    0),
('163','JIMI','DMS',    'high',    'Motorista de Cabeça Baixa',          'Detected driver has lowered the head',    1),
('166','JIMI','DMS',    'info',    'Cinto de Segurança Afivelado',       'Driver is already buckled up',            0),
('167','JIMI','DMS',    'high',    'Cinto de Segurança Não Afivelado',   'Driver is not buckled up',                1),
('170','JIMI','DMS',    'high',    'Motorista Bebendo',                  'Detected driver has been drinking',       1),
('199','JIMI','DMS',    'high',    'Condução Prolongada',                'Driver has been driving extendedly',      1),
-- 228/229 são a MESMA advertência que 205/204 em outra geração de firmware
-- (a doc lista as duas). Nome idêntico de propósito: o filtro casa por nome,
-- e dois rótulos quase iguais fariam o usuário escolher um e perder metade
-- dos eventos.
('228','JIMI','ADAS',   'warning', 'ADAS: Saída de Faixa (LDW)',         'Vehicle has departed from current lane',  0),
('229','JIMI','ADAS',   'critical','ADAS: Colisão Frontal (FCW)',        'Forward collision warning',               1)
ON DUPLICATE KEY UPDATE alarm_name_pt=VALUES(alarm_name_pt), alarm_name_en=VALUES(alarm_name_en),
                        category=VALUES(category), severity=VALUES(severity), requires_action=VALUES(requires_action);

-- ── 7. Nomes oficiais dos DMS/ADAS JIMI já cadastrados ────────────────────
UPDATE alarm_types SET alarm_name_pt='Distração do Motorista', alarm_name_en='Driver attention has been distracted', category='DMS',  severity='high',     requires_action=1 WHERE alarm_code='143' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='Motorista ao Telefone',  alarm_name_en='Driver has been using the phone',      category='DMS',  severity='high',     requires_action=1 WHERE alarm_code='151' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='Motorista Fumando',      alarm_name_en='Driver has been smoking',              category='DMS',  severity='warning',  requires_action=0 WHERE alarm_code='154' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='Motorista Bocejando',    alarm_name_en='Driver has been yawning',              category='DMS',  severity='warning',  requires_action=0 WHERE alarm_code='160' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='ADAS: Colisão Frontal (FCW)',      alarm_name_en='Forward Collision Warning (ADAS-FCW)', category='ADAS', severity='critical', requires_action=1 WHERE alarm_code='204' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='ADAS: Saída de Faixa (LDW)',       alarm_name_en='Lane Departure Warning (ADAS-LDW)',    category='ADAS', severity='warning',  requires_action=0 WHERE alarm_code='205' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='ADAS: Distância Insegura (HMW)',   alarm_name_en='Headway Monitor Warning (ADAS-HMW)',   category='ADAS', severity='warning',  requires_action=0 WHERE alarm_code='206' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='ADAS: Colisão com Pedestre (PCW)', alarm_name_en='Pedestrian collision warning',         category='ADAS', severity='critical', requires_action=1 WHERE alarm_code='207' AND protocol='JIMI';

-- ── 7b. Prefixo "DMS:" também nos códigos JIMI ────────────────────────────
-- Os alarmes de câmera do protocolo JT/T já saíam prefixados ("DMS: …",
-- "ADAS: …"); os equivalentes JIMI, não. Num filtro que agora lista SÓ DMS e
-- ADAS, isso produzia uma lista em que metade das entradas não se anunciava
-- como tal — "Motorista Bebendo" e "Erro de Alinhamento Facial" pareciam ter
-- caído ali por engano — e ainda espalhava alfabeticamente coisas iguais.
--
-- Onde o evento é O MESMO nos dois protocolos, o nome é DELIBERADAMENTE
-- idêntico (fumo, fadiga, cinto, câmera obstruída, condução prolongada): o
-- filtro casa por nome, então um único chip passa a pegar o alarme venha ele
-- de equipamento JIMI ou JT/T. Os CÓDIGOS seguem separados por protocolo em
-- `alarm_types` — o isolamento do ADR-001 é de código, não de rótulo.
UPDATE alarm_types SET alarm_name_pt='DMS: Fadiga ao Dirigir'            WHERE alarm_code='71'  AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Falha de Comunicação da Câmera' WHERE alarm_code='107' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Fadiga Reconhecida'           WHERE alarm_code='117' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Piscadas Frequentes'          WHERE alarm_code='140' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Distração do Motorista'       WHERE alarm_code='143' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Rosto Não Detectado'          WHERE alarm_code='148' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Motorista ao Telefone'        WHERE alarm_code='151' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Motorista Fumando'            WHERE alarm_code='154' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Motorista Bocejando'          WHERE alarm_code='160' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Câmera Obstruída'             WHERE alarm_code='161' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Erro de Alinhamento Facial'   WHERE alarm_code='162' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Motorista de Cabeça Baixa'    WHERE alarm_code='163' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Cinto Afivelado'              WHERE alarm_code='166' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Cinto Não Afivelado'          WHERE alarm_code='167' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Motorista Bebendo'            WHERE alarm_code='170' AND protocol='JIMI';
UPDATE alarm_types SET alarm_name_pt='DMS: Condução Prolongada'          WHERE alarm_code='199' AND protocol='JIMI';

-- ── 8. Reaplicar os nomes ao histórico já gravado ─────────────────────────
-- `alarms.alarm_name` é DESNORMALIZADO: pushalarm.php resolve o nome uma vez,
-- na chegada, e grava a string. Corrigir só `alarm_types` deixaria o relatório
-- exibindo o nome errado em tudo que já entrou — inclusive nos "Código NNNN
-- (JTT)" dos códigos que a v4.8.1 cadastrou sem nunca reprocessar.
--
-- Exclusões deliberadas:
--   256 → o nome vem da decodificação do bitmask de 32 bits (pode ser
--         "Emergência / SOS + Excesso de Velocidade"); `alarm_subtype` guarda
--         o VALOR do bitmask, não um subtipo. Rejuntar destruiria o dado.
--   264/265 sem `alarm_subtype` → sem o subtipo não há como saber qual alarme
--         de câmera foi; o código-base sozinho não é um alarme.
-- O prefixo "Fim de Alarme: " marca `removeAlarmType` e é preservado.
UPDATE alarms a
  JOIN alarm_types t
    ON t.protocol = IF(a.msg_class = 0, 'JIMI', 'JTT')
   AND t.alarm_code = IF(a.msg_class = 1 AND a.alarm_type IN ('264','265') AND a.alarm_subtype IS NOT NULL,
                         CONCAT(a.alarm_type, '-', a.alarm_subtype),
                         a.alarm_type)
   SET a.alarm_name = IF(a.alarm_name LIKE 'Fim de Alarme: %',
                         CONCAT('Fim de Alarme: ', t.alarm_name_pt),
                         t.alarm_name_pt)
 WHERE NOT (a.msg_class = 1 AND a.alarm_type = '256')
   AND NOT (a.msg_class = 1 AND a.alarm_type IN ('264','265') AND a.alarm_subtype IS NULL);

-- ── 9. Conferência (aparece no log do deploy) ─────────────────────────────
SELECT CONCAT(COUNT(*), ' tipo(s) DMS/ADAS disponíveis no filtro de alarmes') AS resultado
  FROM alarm_types WHERE category IN ('DMS','ADAS');
SELECT CONCAT(COUNT(*), ' alarme(s) ainda com rótulo genérico "Código …"') AS pendencia
  FROM alarms WHERE alarm_name LIKE 'Código %';

-- ── Versão ────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.8.3', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.8.3', last_update = NOW();
