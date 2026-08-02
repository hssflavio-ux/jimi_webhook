-- ============================================================
-- Migração v4.8.1 — alarm_types contém SOMENTE alarmes oficiais
-- ============================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- FONTE: docs.jimicloud.com/integration/integration.html, seção "Alarm
-- Reference" → "1 JIMI Device Alarms (msgClass = 0)" e "2 JT/T Device Alarms
-- (msgClass = 1)", esta última incluindo a subseção "2.7 Other Alarms".
-- Extraído do DOM renderizado em 01/08/2026: 197 códigos JIMI, 74 JT/T.
--
-- ⚠️ POR QUE O DOM E NÃO O HTML CRU. A página é uma SPA: `file_get_contents`
-- devolve 804 KB que contêm apenas a navegação — as tabelas de alarme são
-- hidratadas por JavaScript. Uma leitura do HTML estático (ou um fetch que
-- resuma a página) conclui, erradamente, que a "Alarm Reference" não existe.
-- Foi exatamente esse o erro que levou a afirmar que os códigos 1030, 3073,
-- 3075, 3076 e 3086 não estavam documentados. Estão — em 2.7 Other Alarms:
--     1030 Parking vibration alarm (AVD)   3073 SOS emergency alarm (ASOS)
--     3075 ACC ON alarm                    3076 ACC OFF alarm
--     3086 Power Off alarm
--
-- O QUE ESTA MIGRAÇÃO FAZ. Remove de `alarm_types` todo código que a doc
-- oficial não publica. NÃO reescreve os nomes dos que ficam: eles estão em
-- português (`alarm_name_pt`), traduzidos e revisados, o que é melhor para o
-- usuário final do que o texto original em inglês.
--
-- Os subcódigos ADAS/DMS no formato `264-1`, `265-4` etc. são PRESERVADOS: a
-- doc publica 264 e 265 como campos compostos, cujo `alarmType` interno vai de
-- 0x01 a 0x1F — nossa convenção `<alertType>-<alarmType>` é o desdobramento
-- fiel dessa tabela, não invenção.
--
-- Backup recomendado antes: mysqldump ... alarm_types > alarm_types.bak.sql

-- ── Diagnóstico: o que será removido (aparece no log do deploy) ──
SELECT CONCAT('Removendo ', COUNT(*), ' alarme(s) fora da doc oficial') AS aviso
FROM alarm_types
WHERE (protocol = 'JIMI' AND alarm_code NOT REGEXP '-'
       AND CAST(alarm_code AS UNSIGNED) NOT IN (
         0,1,2,3,4,5,6,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,32,
         35,36,37,38,39,40,41,42,43,44,45,48,49,50,51,52,53,54,55,56,57,58,59,60,61,62,63,
         64,65,66,67,68,69,70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,87,88,89,90,
         91,92,93,94,95,96,97,98,99,100,101,102,103,104,105,106,107,111,112,113,114,115,
         116,117,118,119,120,121,122,123,124,126,128,129,130,131,132,133,134,135,136,137,
         138,139,140,141,142,143,144,145,146,147,148,149,150,151,152,153,154,160,161,162,
         163,164,165,166,167,168,169,170,171,172,173,177,178,179,180,181,182,183,184,185,
         186,187,188,189,190,191,197,198,199,200,201,202,203,204,205,206,207,224,225,226,
         227,228,229,230,231,232,233,254,255,261,262))
   OR (protocol = 'JTT' AND alarm_code NOT REGEXP '-'
       AND CAST(alarm_code AS UNSIGNED) NOT IN (
         1,3,4,5,6,15,41,44,47,48,71,76,77,83,106,119,120,182,185,191,224,227,241,242,243,
         244,245,246,254,255,256,257,258,259,262,264,265,
         1024,1025,1026,1027,1028,1029,1030,1031,1032,1033,1036,1037,1040,1041,1042,1043,
         1044,1046,3073,3074,3075,3076,3077,3078,3079,3080,3081,3082,3083,3084,3085,3086,
         3087,3088,3089,3090,3091,3092,3093,3094,3095,3096,3097));

-- ── Poda ────────────────────────────────────────────────────
-- `NOT REGEXP '-'` protege os subcódigos ADAS/DMS (264-1, 265-4…), que não são
-- alertTypes soltos e sim o desdobramento dos campos compostos 264 e 265.
DELETE FROM alarm_types
WHERE (protocol = 'JIMI' AND alarm_code NOT REGEXP '-'
       AND CAST(alarm_code AS UNSIGNED) NOT IN (
         0,1,2,3,4,5,6,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,32,
         35,36,37,38,39,40,41,42,43,44,45,48,49,50,51,52,53,54,55,56,57,58,59,60,61,62,63,
         64,65,66,67,68,69,70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,87,88,89,90,
         91,92,93,94,95,96,97,98,99,100,101,102,103,104,105,106,107,111,112,113,114,115,
         116,117,118,119,120,121,122,123,124,126,128,129,130,131,132,133,134,135,136,137,
         138,139,140,141,142,143,144,145,146,147,148,149,150,151,152,153,154,160,161,162,
         163,164,165,166,167,168,169,170,171,172,173,177,178,179,180,181,182,183,184,185,
         186,187,188,189,190,191,197,198,199,200,201,202,203,204,205,206,207,224,225,226,
         227,228,229,230,231,232,233,254,255,261,262))
   OR (protocol = 'JTT' AND alarm_code NOT REGEXP '-'
       AND CAST(alarm_code AS UNSIGNED) NOT IN (
         1,3,4,5,6,15,41,44,47,48,71,76,77,83,106,119,120,182,185,191,224,227,241,242,243,
         244,245,246,254,255,256,257,258,259,262,264,265,
         1024,1025,1026,1027,1028,1029,1030,1031,1032,1033,1036,1037,1040,1041,1042,1043,
         1044,1046,3073,3074,3075,3076,3077,3078,3079,3080,3081,3082,3083,3084,3085,3086,
         3087,3088,3089,3090,3091,3092,3093,3094,3095,3096,3097));

-- ── Os cinco que faltavam, agora com o nome oficial ─────────
-- Estes chegavam do device e caíam no rótulo genérico "Código NNNN (JTT)"
-- porque não existiam aqui. INSERT IGNORE: reexecutar não duplica.
INSERT IGNORE INTO alarm_types (alarm_code, protocol, category, severity, alarm_name_pt, alarm_name_en, requires_action) VALUES
('1030','JTT','veiculo','warning', 'Vibração com Veículo Parado',      'Parking vibration alarm (AVD)', 0),
('3073','JTT','emergencia','critical','SOS — Emergência',              'SOS emergency alarm (ASOS)',    1),
('3074','JTT','energia','warning',  'Bateria do Veículo Baixa',        'External low power alarm',      0),
('3075','JTT','veiculo','info',     'Ignição Ligada',                  'ACC ON alarm',                  0),
('3076','JTT','veiculo','info',     'Ignição Desligada',               'ACC OFF alarm',                 0),
('3086','JTT','energia','high',     'Desligamento de Energia',         'Power Off alarm',               1),
('1024','JTT','conducao','warning', 'Aceleração Brusca',               'Sharp acceleration',            0),
('1025','JTT','conducao','warning', 'Desaceleração Brusca',            'Sharp deceleration',            0),
('1026','JTT','conducao','warning', 'Curva Brusca',                    'Sharp turn',                    0),
('1027','JTT','conducao','high',    'Excesso de Velocidade',           'Overspeed alarm (AOSD)',        1),
('1029','JTT','conducao','critical','Colisão em Movimento',            'Driving collision alarm',       1);

-- ── Versão ──────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.8.1', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.8.1', last_update = NOW();
