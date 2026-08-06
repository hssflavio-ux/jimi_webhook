-- ============================================================================
-- JIMI Webhook System — Migração v4.9.0
--
-- Completa o catálogo `alarm_types` com os alertTypes JT/T que a doc oficial
-- lista em "Other Alarms" e que nunca foram inseridos.
--
-- Por que isto aparece no relatório: `handlers/pushalarm.php` resolve o nome
-- do alarme UMA vez, na chegada do webhook, consultando `alarm_types`. Código
-- ausente do catálogo vira o rótulo genérico `Código NNNN (JTT)` — que é
-- gravado em `alarms.alarm_name` e a partir daí é o que o Relatório de Alarmes
-- imprime, para sempre. A v4.8.1 abriu a lista branca da poda para toda a
-- faixa 1024–3097, mas só INSERIU onze desses códigos; os demais continuaram
-- caindo no rótulo genérico.
--
-- ⚠️ `INSERT IGNORE`, nunca `ON DUPLICATE KEY UPDATE`. Renomear `alarm_name_pt`
-- de uma linha existente quebraria em silêncio o motor de ocorrências —
-- `occurrence_config_params.alarm_type` guarda o NOME, e `get_occurrence_param()`
-- casa por ele (ver CLAUDE.md e a migração v4.8.6). Esta migração só ACRESCENTA
-- códigos que não existem, então nenhum parâmetro fica órfão; a conferência no
-- fim do arquivo prova isso em vez de presumir.
--
-- Nomes repetidos são propositais. `1024` e `1042` são ambos "aceleração
-- brusca" em gerações diferentes de firmware, e o filtro da tela casa por
-- NOME: dois rótulos quase iguais fariam o usuário escolher um e perder metade
-- dos eventos. Mesma decisão da v4.8.3 para os pares 228/205 e 229/204.
--
-- Nenhum destes entra como DMS/ADAS de propósito — são eventos de veículo, de
-- energia e de periférico. O filtro de tipos do Relatório de Alarmes lista só
-- DMS e ADAS, e enchê-lo de "cartão SD removido" enterraria o núcleo do
-- produto, que é comportamento do motorista.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── Alarmes de condução e de veículo (1028–1046) ────────────────────────────
INSERT IGNORE INTO alarm_types (alarm_code, protocol, category, severity, alarm_name_pt, alarm_name_en, requires_action) VALUES
('1028','JTT','conducao','high',    'Condução Prolongada',              'Overtime/Fatigue driving alarm (AODD)', 1),
('1031','JTT','seguranca','high',   'Alerta de Deslocamento',           'Displacement alarm',                   1),
('1032','JTT','cerca','warning',    'Entrada em Cerca Eletrônica',      'Fence entry alarm',                    0),
('1033','JTT','cerca','warning',    'Saída de Cerca Eletrônica',        'Fence exit alarm',                     0),
('1036','JTT','veiculo','info',     'Abertura de Porta',                'Door open alarm',                      0),
('1037','JTT','veiculo','info',     'Fechamento de Porta',              'Door close alarm',                     0),
('1044','JTT','conducao','warning', 'Curva Brusca',                     'Harsh Turn (AHTDU)',                   0),
('1046','JTT','veiculo','critical', 'Colisão do Veículo',               'Collision (ACDU)',                     1);

-- ── Alarmes de dispositivo, energia e periférico (3077–3097) ────────────────
INSERT IGNORE INTO alarm_types (alarm_code, protocol, category, severity, alarm_name_pt, alarm_name_en, requires_action) VALUES
('3077','JTT','seguranca','critical','Alerta Antifurto',                'Anti-theft alarm',                     1),
('3078','JTT','dispositivo','warning','Anomalia de Calibração da Câmera DMS', 'DMS Calibration Anomaly (ADCA)',  1),
('3079','JTT','dispositivo','info', 'Alerta de Identificação',          'Identification alarm',                 0),
('3080','JTT','veiculo','info',     'Acionamento de Porta',             'Door trigger alarm',                   0),
('3081','JTT','veiculo','warning',  'Anomalia de Combustível',          'Abnormal oil alarm',                   1),
('3082','JTT','veiculo','warning',  'Anomalia de Temperatura ou Umidade','Abnormal temperature and humidity alarm', 1),
('3083','JTT','dispositivo','info', 'Login de Cartão DLT',              'DLT card login alarm',                 0),
('3084','JTT','dispositivo','info', 'Logout de Cartão DLT',             'DLT card logout alarm',                0),
('3085','JTT','seguranca','warning','Cartão DLT Não Registrado',        'DLT non-registered card alarm',        1),
('3087','JTT','energia','warning',  'Bateria Interna Fraca',            'Built in low battery alarm',           0),
('3088','JTT','energia','critical', 'Desligamento por Bateria Baixa',   'Low power shutdown alarm',             1),
('3089','JTT','dispositivo','info', 'Alerta por Comando de Voz',        'Voice controlled alarm',               0),
('3090','JTT','seguranca','critical','Violação do Equipamento',         'Anti-disassembly alarm',               1),
('3091','JTT','dispositivo','warning','Desconexão Ativa do Equipamento','Active offline alarm',                 1),
('3092','JTT','dispositivo','info', 'Cartão SD Inserido',               'SD card inserted or mounted',          0),
('3093','JTT','dispositivo','warning','Cartão SD Ausente ou Removido',  'SD card not inserted or removed',      1),
('3094','JTT','dispositivo','high', 'Cartão SD Corrompido',             'SD card corrupted (abnormal writing)', 1),
('3095','JTT','dispositivo','warning','Franquia de Dados Excedida',     'Data overage alarm',                   0),
('3096','JTT','veiculo','info',     'Combustível e Energia Restaurados','Restore oil and electricity',          0),
('3097','JTT','veiculo','high',     'Combustível e Energia Cortados',   'Disconnect oil and electricity',       1);

-- ── Sobra da v4.8.3 que a v4.8.6 não pegou: "Bateria Fraca" ────────────────
-- Achado pela conferência de órfãos aqui embaixo, rodando esta migração num
-- banco real. A v4.8.3 renomeou o código JIMI 14 de "Bateria Fraca" para
-- "Tensão da Alimentação Externa Baixa"; a v4.8.6 remapeou 21 parâmetros mas
-- deixou este. Desde então o parâmetro existe em /config-ocorrencias, o
-- alarme chega e é gravado, e ocorrência nenhuma nasce — sem erro no log nem
-- na tela, que é exatamente o modo de falha descrito no CLAUDE.md.
--
-- O UPDATE só toca a linha órfã (`NOT EXISTS`), e o `IGNORE` cobre o caso de
-- a configuração já ter os dois nomes: aí a linha velha some no DELETE
-- seguinte em vez de estourar a chave única (config_id, alarm_type).
UPDATE IGNORE occurrence_config_params ocp
   SET ocp.alarm_type = 'Tensão da Alimentação Externa Baixa'
 WHERE ocp.alarm_type = 'Bateria Fraca'
   AND NOT EXISTS (SELECT 1 FROM alarm_types at WHERE at.alarm_name_pt = 'Bateria Fraca');

DELETE ocp FROM occurrence_config_params ocp
 WHERE ocp.alarm_type = 'Bateria Fraca'
   AND NOT EXISTS (SELECT 1 FROM alarm_types at WHERE at.alarm_name_pt = 'Bateria Fraca');

-- ── Conferência: parâmetro de ocorrência sem alarme correspondente ──────────
-- Deve devolver ZERO linhas. Se devolver alguma, algum `alarm_name_pt` foi
-- alterado (não deveria: aqui só há INSERT IGNORE) e o motor de ocorrências
-- deixou de gerar ocorrência para aquele parâmetro — em silêncio.
SELECT ocp.alarm_type AS parametro_orfao
  FROM occurrence_config_params ocp
  LEFT JOIN alarm_types at ON at.alarm_name_pt = ocp.alarm_type
 WHERE at.id IS NULL;

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.0', last_update = NOW();

SELECT 'Migracao v4.9.0 concluida' AS status;
