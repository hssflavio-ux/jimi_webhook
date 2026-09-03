-- ============================================================================
-- bycamera — migration v4.17.0
-- Cadastro COMPLETO dos alarmes JIMI: os 102 códigos que faltavam
--
-- Pedido do dono do produto (03/09/2026), na sequência da análise do primeiro
-- JM-VL01 real: "precisamos cadastrá-los completamente e corretamente no nosso
-- banco para termos a informação certa nos relatórios, e esteja atento se algum
-- código de número semelhante trabalhe também com JT/T, para não termos
-- confusão futura."
--
-- O catálogo JIMI vai de **95 para 197** códigos — que é o total da Alarm
-- Reference oficial (`https://docs.jimicloud.com/integration/integration.html`,
-- seção "1. JIMI Device Alarms (msgClass=0)"). Depois desta migração, NENHUM
-- código publicado pela fabricante cai mais como "Código NNNN (JIMI)" nos
-- relatórios.
--
-- ── DE ONDE VEIO CADA COISA ────────────────────────────────────────────────
-- `alarm_name_en` é a descrição OFICIAL copiada literalmente da doc — não uma
-- tradução minha de volta ao inglês. `category` vem das 9 subseções em que a
-- própria Jimi agrupa os alarmes (GNSS / Device status / Vehicle status /
-- Personal safety / Vehicle safety / Peripheral triggered / Driving behavior /
-- Algorithm generated / Other), mapeadas para as categorias que já existem
-- aqui. `alarm_name_pt` é meu, e só aí.
--
-- ⚠️ O recorte da doc é POR SEÇÃO, não por varredura do documento inteiro. Um
-- parser ingênuo engole as tabelas de BITS DE STATUS ("0: ACC OFF 1: ACC ON"),
-- que também têm duas células com número na primeira — e foi assim que a
-- primeira tentativa "descobriu" que o alarme 2 significava "0: North latitude".
--
-- ── 🔴 A COLISÃO DE NÚMERO COM JT/T ────────────────────────────────────────
-- O espaço JIMI vai até 262; o JT/T do nosso catálogo começa em 256. Eles se
-- cruzam, e há UM caso real:
--
--     JIMI 262 = Fim de Movimento          (esta migração)
--     JT/T 262 = Comportamento de Condução Irregular   (já existia)
--
-- Isso NÃO é duplicidade e não deve ser "consertado": a chave é
-- `(alarm_code, protocol)`, e `alarm_label_sql()` (includes/functions.php)
-- resolve o rótulo com `atb.protocol = IF(a.msg_class = 1, 'JTT', 'JIMI')` —
-- o alarme sabe de que protocolo veio pelo `msg_class` gravado na chegada. As
-- duas linhas convivem e cada uma aparece para o equipamento certo.
--
-- O que precisava de cuidado era o NOME, porque o filtro dos relatórios casa
-- por `alarm_name_pt`: dois nomes parecidos em 262 fariam o operador marcar um
-- e achar que perdeu eventos. "Fim de Movimento" e "Comportamento de Condução
-- Irregular" não se confundem. Os outros números do JIMI nessa faixa (261) e
-- os do JT/T (256–259, 264-x, 265-x) não colidem.
--
-- ── NOMES REPETIDOS DE PROPÓSITO ───────────────────────────────────────────
-- A fabricante publica o MESMO evento em mais de um código, e o filtro da tela
-- casa por NOME (`SELECT DISTINCT alarm_name_pt`). Dar nomes diferentes
-- partiria o evento em dois chips e o operador perderia metade dos registros ao
-- marcar um deles — é a razão pela qual a v4.9.0 já repetiu nomes nos pares
-- 1024/1042. Por isso, de propósito:
--
--     Capotamento                      45 (existia) + 106 + 183
--     Excesso de Velocidade             6 (existia) + 135
--     Porta Aberta / Porta Fechada     28/29 (existiam) + 80/81
--     Cinto Afivelado                 166 (existia) + 131
--     Impacto Violento Detectado      101 (existia) + 55
--     Tampa do Dispositivo Aberta      24 (existia) + 39
--     Tensão da Alimentação Ext. Baixa 14 (existia) + 90
--     Dispositivo Arrancado            50 (v4.16.1) + 191
--     Fora do Raio Predefinido         56 + 57  (a doc repete o texto nos dois)
--
-- 🔑 O `182 = Reboque do Veículo` é o alarme de REBOQUE de verdade, e só dá
-- para cadastrá-lo com esse nome porque a v4.16.1 tirou "Alerta de Reboque" do
-- 50 (que é "Device was plugged out"). Tivesse ficado, haveria dois "reboque"
-- diferentes na lista — exatamente o erro que o parágrafo acima evita.
--
-- ── EFEITO EM OCORRÊNCIA E NOTIFICAÇÃO — 98 dos 102 não mudam nada ─────────
-- `notify_from_occurrence()` (includes/notification_engine.php) só é chamado
-- por `occurrence_engine.php`, que retorna cedo quando `get_occurrence_param()`
-- devolve NULL — e o parâmetro casa por NOME em `occurrence_config_params`.
-- Para 98 dos 102 códigos o nome é novo, não há parâmetro, e portanto **não há
-- ocorrência nem sino**: o alarme só passa a ter NOME nos relatórios. Ligar
-- qualquer um deles é decisão de produto, em /config-ocorrencias.
--
-- 🔴 Os OUTROS 4 são a exceção, e ela é CONSEQUÊNCIA DIRETA do parágrafo dos
-- nomes repetidos — herdam o parâmetro do irmão porque compartilham o nome:
--
--     106, 183 -> "Capotamento"                       (o 45 já tem parâmetro)
--     135      -> "Excesso de Velocidade"             (o 6 já tem parâmetro)
--     90       -> "Tensão da Alimentação Externa Baixa" (o 14 já tem parâmetro)
--
-- Isso é o comportamento CERTO, não um efeito colateral a evitar: se o
-- capotamento pelo código 45 abre ocorrência, o mesmo capotamento anunciado
-- pelo código 106 tem de abrir também — hoje ele não abre porque cai como
-- "Código 106 (JIMI)" e o motor não o reconhece. Em volume, só muda algo se o
-- equipamento realmente usar o código alternativo.
--
-- ✅ Nenhum nome existente é renomeado, então nada em `occurrence_config_params`
-- nem em `notification_rules` deixa de casar (a armadilha da v4.8.3/v4.9.5).
--
-- ── DIAGNÓSTICO ────────────────────────────────────────────────────────────
-- 26 dos 102 entram com `is_diagnostic = 1`: são o que o equipamento diz ao
-- SISTEMA, não ao operador — carga de bateria interna, cartão SD, erro de chip,
-- MAC de Bluetooth, uso de dados. Ficam fora dos relatórios de alarme por
-- padrão e visíveis ao administrador no modo diagnóstico (v4.9.9). Sem isso,
-- cadastrar 102 códigos afogaria a tela em ruído de infraestrutura — que é o
-- oposto de "informação certa nos relatórios".
--
-- ⚠️ Esta migração NÃO roda no deploy que a traz. Rode
-- `./scripts/deploy.sh --force` duas vezes, ou aplique este .sql à mão.
-- ============================================================================

INSERT IGNORE INTO `alarm_types`
    (`alarm_code`, `protocol`, `category`, `severity`, `alarm_name_pt`, `alarm_name_en`, `requires_action`, `is_diagnostic`)
VALUES
    ('27', 'JIMI', 'pessoal', 'info', 'Suspeita de Saída do Rebanho', 'Suspected of the subject leaving the designated herd', 0, 0),
    ('37', 'JIMI', 'sensor', 'info', 'Luz Detectada', 'Light detected', 0, 0),
    ('38', 'JIMI', 'seguranca', 'warning', 'Afastamento do Beacon', 'Device has been moving away from the beacon', 0, 0),
    ('39', 'JIMI', 'seguranca', 'warning', 'Tampa do Dispositivo Aberta', 'Device was opened unexpectedly', 1, 0),
    ('49', 'JIMI', 'pessoal', 'warning', 'Saída do Rebanho', 'Subject has already left designated herd', 1, 0),
    ('54', 'JIMI', 'seguranca', 'warning', 'Falha ao Desbloquear o Dispositivo', 'Failed to unlock the device', 0, 0),
    ('55', 'JIMI', 'dispositivo', 'warning', 'Impacto Violento Detectado', 'Device was hit violently', 0, 0),
    ('56', 'JIMI', 'cerca', 'warning', 'Fora do Raio Predefinido', 'Device is out of the preset range', 1, 0),
    ('57', 'JIMI', 'cerca', 'warning', 'Fora do Raio Predefinido', 'Device is out of the preset range', 1, 0),
    ('58', 'JIMI', 'seguranca', 'info', 'Tampa do Dispositivo Aberta (Confirmado)', 'That the device was opened unexpectedly is known', 0, 0),
    ('59', 'JIMI', 'dispositivo', 'info', 'Dispositivo Parado por Tempo Excessivo', 'Device has been stationary for too long', 0, 0),
    ('63', 'JIMI', 'seguranca', 'info', 'Saída do Modo de Vigilância', 'Device has exited defense mode', 0, 0),
    ('64', 'JIMI', 'seguranca', 'info', 'Entrada no Modo de Vigilância', 'Device has entered defense mode', 0, 0),
    ('65', 'JIMI', 'dispositivo', 'info', 'Dispositivo Silenciado', 'Device is muted', 0, 1),
    ('66', 'JIMI', 'veiculo', 'info', 'Localizar Veículo (Acionado)', 'Vehicle finding alert (custom)', 0, 0),
    ('67', 'JIMI', 'seguranca', 'warning', 'Abertura de Baú Detectada', 'Detected truck has been opened', 1, 0),
    ('68', 'JIMI', 'dispositivo', 'info', 'Reservado 1 (Personalizado)', 'RSV1 (custom)', 0, 1),
    ('69', 'JIMI', 'dispositivo', 'info', 'Reservado 2 (Personalizado)', 'RSV2 (custom)', 0, 1),
    ('70', 'JIMI', 'dispositivo', 'info', 'Reservado 3 (Personalizado)', 'RSV3 (custom)', 0, 1),
    ('72', 'JIMI', 'pessoal', 'warning', 'Perda do Objeto Rastreado', 'Detected pet has been lost', 1, 0),
    ('73', 'JIMI', 'dispositivo', 'info', 'Bateria Interna Carregada', 'Internal battery is fully charged', 0, 1),
    ('74', 'JIMI', 'dispositivo', 'warning', 'Erro na Bateria Interna', 'Internal battery error', 1, 0),
    ('80', 'JIMI', 'veiculo', 'info', 'Porta Aberta', 'Door opening alarm', 0, 0),
    ('81', 'JIMI', 'veiculo', 'info', 'Porta Fechada', 'Door closing alarm', 0, 0),
    ('82', 'JIMI', 'pessoal', 'high', 'Temperatura Corporal Anormal', 'The body temperature of the user is abnormal', 1, 0),
    ('84', 'JIMI', 'dispositivo', 'warning', 'Antena GNSS Externa Desconectada', 'External GNSS antenna disconnected', 1, 0),
    ('85', 'JIMI', 'dispositivo', 'warning', 'Temperatura Alta na Bateria Interna', 'Temperature of internal battery was high', 1, 0),
    ('86', 'JIMI', 'dispositivo', 'info', 'Início da Carga da Bateria Interna', 'Charging of internal battery has started', 0, 1),
    ('87', 'JIMI', 'dispositivo', 'info', 'Fim da Carga da Bateria Interna', 'Charging of internal battery has stopped', 0, 1),
    ('88', 'JIMI', 'dispositivo', 'info', 'Bateria Interna Quase Carregada', 'Internal battery is about to be fully charged', 0, 1),
    ('89', 'JIMI', 'dispositivo', 'info', 'Carga da Bateria Interna Concluída', 'Charging of the internal battery is complete', 0, 1),
    ('90', 'JIMI', 'dispositivo', 'warning', 'Tensão da Alimentação Externa Baixa', 'Voltage of external power is rather low', 0, 0),
    ('91', 'JIMI', 'sensor', 'warning', 'Temperatura Alta', 'Temperature was too high', 1, 0),
    ('92', 'JIMI', 'sensor', 'warning', 'Temperatura Baixa', 'Temperature was too low', 1, 0),
    ('93', 'JIMI', 'dispositivo', 'warning', 'Erro no Leitor RFID', 'RFID sensor error', 1, 0),
    ('95', 'JIMI', 'cerca', 'warning', 'Excesso de Velocidade em Cerca', 'Vehicle has been speeding inside geofence', 1, 0),
    ('96', 'JIMI', 'seguranca', 'warning', 'Anomalia no Fio de Ignição', 'Live wire exception', 1, 0),
    ('97', 'JIMI', 'sensor', 'warning', 'Erro no Sensor de Temperatura', 'Temperature sensor error', 1, 0),
    ('98', 'JIMI', 'dispositivo', 'warning', 'Tensão da Alimentação Externa Alta', 'Voltage of external power is too high', 1, 0),
    ('99', 'JIMI', 'dispositivo', 'info', 'Aproximação de Beacon Bluetooth', 'Device is close to Bluetooth beacon', 0, 0),
    ('100', 'JIMI', 'sensor', 'info', 'Temperatura Normalizada', 'Temperature recovered to normal', 0, 0),
    ('106', 'JIMI', 'acidente', 'critical', 'Capotamento', 'Vehicle tipped over on its side', 1, 0),
    ('111', 'JIMI', 'dispositivo', 'warning', 'Falha no Cartão SD', 'SD card fault', 1, 1),
    ('112', 'JIMI', 'dispositivo', 'info', 'Cartão SD Montado', 'SD card is already mounted', 0, 1),
    ('113', 'JIMI', 'sensor', 'warning', 'Reabastecimento Necessário', 'Tank needs refill', 1, 0),
    ('114', 'JIMI', 'dispositivo', 'info', 'Dispositivo Instalado', 'Device is already installed', 0, 1),
    ('115', 'JIMI', 'sensor', 'warning', 'Nível de Combustível Anormal', 'Abnormal fuel level', 1, 0),
    ('116', 'JIMI', 'conducao', 'info', 'Velocidade Normalizada', 'Vehicle speed has resumed to normal range', 0, 0),
    ('118', 'JIMI', 'sensor', 'warning', 'Timeout do Sensor de Temperatura', 'Temperature sensor connection timeout', 1, 0),
    ('119', 'JIMI', 'sensor', 'warning', 'Tensão Excessivamente Alta (ADC1)', 'Detected voltage is exceptionally high (ADC1)', 1, 0),
    ('120', 'JIMI', 'sensor', 'warning', 'Tensão Excessivamente Baixa (ADC1)', 'Detected voltage is exceptionally low (ADC1)', 1, 0),
    ('121', 'JIMI', 'sensor', 'warning', 'Subida Anormal de Tensão (ADC1)', 'Detected voltage has been rising abnormally (ADC1)', 1, 0),
    ('122', 'JIMI', 'sensor', 'warning', 'Queda Anormal de Tensão (ADC1)', 'Detected voltage has been dropping abnormally (ADC1)', 1, 0),
    ('123', 'JIMI', 'sensor', 'warning', 'Subida Anormal de Temperatura', 'Detected temperature has been rising abnormally', 1, 0),
    ('124', 'JIMI', 'sensor', 'warning', 'Queda Anormal de Temperatura', 'Detected temperature has been dropping abnormally', 1, 0),
    ('126', 'JIMI', 'sensor', 'warning', 'Umidade Alta', 'Humidity was too high', 1, 0),
    ('128', 'JIMI', 'veiculo', 'warning', 'Vibração no Retrovisor', 'Rear mirror vibration', 0, 0),
    ('129', 'JIMI', 'dispositivo', 'warning', 'Consumo de Dados Anormal', 'Device mobile data usage exception', 0, 1),
    ('130', 'JIMI', 'dispositivo', 'info', 'Dispositivo Reiniciado', 'Device is already restarted', 0, 1),
    ('131', 'JIMI', 'veiculo', 'info', 'Cinto Afivelado', 'Seatbelt fastened alarm', 0, 0),
    ('133', 'JIMI', 'video', 'warning', 'Falha na Câmera 2', 'Camera 2 exception', 1, 1),
    ('135', 'JIMI', 'conducao', 'warning', 'Excesso de Velocidade', 'Vehicle has been speeding', 1, 0),
    ('152', 'JIMI', 'video', 'info', 'Captura Concluída', 'Device has already completed the capture', 0, 1),
    ('153', 'JIMI', 'conducao', 'info', 'Motorista Alterado', 'Driver info has changed', 0, 0),
    ('164', 'JIMI', 'dispositivo', 'warning', 'Espaço do Cartão SD Baixo', 'Memory card space low', 1, 1),
    ('165', 'JIMI', 'dispositivo', 'info', 'Cartão RFID Lido', 'RFID sensor has detected a card swipe', 0, 0),
    ('168', 'JIMI', 'veiculo', 'high', 'Falha no Motor', 'Engine failed', 1, 0),
    ('169', 'JIMI', 'veiculo', 'warning', 'Subtensão da Bateria do Veículo', 'Vehicle battery was undervoltage', 1, 0),
    ('171', 'JIMI', 'seguranca', 'warning', 'Violação de Embalagem', 'Package had been opened unexpectedly', 1, 0),
    ('172', 'JIMI', 'dispositivo', 'info', 'Endereços MAC Bluetooth Encontrados', 'Bluetooth MAC addresses found', 0, 1),
    ('173', 'JIMI', 'dispositivo', 'info', 'Nenhum Endereço MAC Bluetooth Encontrado', 'No Bluetooth MAC addresses are found', 0, 1),
    ('177', 'JIMI', 'sensor', 'warning', 'Aumento Anormal de Combustível (CLS2)', 'Fuel level has increased unexpectedly (CLS2 peripheral)', 1, 0),
    ('178', 'JIMI', 'sensor', 'critical', 'Queda Anormal de Combustível (CLS2)', 'Fuel level has dropped unexpectedly (CLS2 peripheral)', 1, 0),
    ('179', 'JIMI', 'sensor', 'warning', 'Erro do Sensor de Combustível (CLS2)', 'Fuel sensor communication error (CLS2 peripheral)', 1, 1),
    ('180', 'JIMI', 'sensor', 'info', 'Sensor de Combustível Restabelecido (CLS2)', 'Fuel sensor communication has resumed (CLS2 peripheral)', 0, 1),
    ('181', 'JIMI', 'sensor', 'warning', 'Erro do Sensor de Temperatura (1-Wire)', 'Temperature sensor communication error (1-wire peripheral)', 1, 1),
    ('182', 'JIMI', 'seguranca', 'critical', 'Reboque do Veículo', 'Vehicle has been towing away unexpectedly', 1, 0),
    ('183', 'JIMI', 'acidente', 'critical', 'Capotamento', 'Vehicle has tipped over on its side', 1, 0),
    ('184', 'JIMI', 'dispositivo', 'warning', 'Tempo Excessivo para Fixar GPS', 'Time to position fix takes too long', 0, 1),
    ('185', 'JIMI', 'conducao', 'warning', 'Ociosidade Excessiva', 'Vehicle has been idling for too long', 1, 0),
    ('186', 'JIMI', 'dispositivo', 'warning', 'Erro no Acelerômetro 3D', 'Detected 3D acceleration sensor error', 1, 1),
    ('187', 'JIMI', 'dispositivo', 'warning', 'Erro no Módulo GNSS', 'Detected GNSS module error', 1, 1),
    ('188', 'JIMI', 'dispositivo', 'warning', 'Erro no Chip do Sensor UBI', 'Detected UBI sensor chip error', 1, 1),
    ('189', 'JIMI', 'dispositivo', 'warning', 'Erro no CI de Criptografia UBI', 'Detected UBI Encrypted IC error', 1, 1),
    ('190', 'JIMI', 'dispositivo', 'warning', 'Erro no Chip GNSS UBI', 'Detected UBI GNSS chip error', 1, 1),
    ('191', 'JIMI', 'seguranca', 'critical', 'Dispositivo Arrancado', 'Device was plugged out', 1, 0),
    ('197', 'JIMI', 'veiculo', 'info', 'Motor Ligado', 'Engine is already turned on', 0, 0),
    ('198', 'JIMI', 'veiculo', 'info', 'Motor Desligado', 'Engine is already turned off', 0, 0),
    ('200', 'JIMI', 'conducao', 'info', 'Condução Prolongada (Confirmado)', 'Extended driving of driver is already known', 0, 0),
    ('201', 'JIMI', 'sensor', 'info', 'Entrada 1 Acionada', 'INPUT1 is activated', 0, 0),
    ('202', 'JIMI', 'conducao', 'warning', 'Aviso de Velocidade', 'Vehicle speed warning', 0, 0),
    ('203', 'JIMI', 'veiculo', 'info', 'Veículo Estacionado por Tempo Excessivo', 'Vehicle has parked for too long', 0, 0),
    ('224', 'JIMI', 'dispositivo', 'info', 'Dispositivo Conectado', 'Device was plugged in', 0, 0),
    ('225', 'JIMI', 'dispositivo', 'warning', 'Erro de FLASH (GID)', 'Detected FLASH error (GID)', 1, 1),
    ('226', 'JIMI', 'dispositivo', 'warning', 'Erro no Módulo CAN (GID)', 'Detected CAN module error (GID)', 1, 1),
    ('227', 'JIMI', 'veiculo', 'high', 'Temperatura da Água Alta', 'Water temperature is too high', 1, 0),
    ('230', 'JIMI', 'sensor', 'warning', 'Alarme de Entrada 1 Acionada', 'INPUT1 activated alarm', 1, 0),
    ('231', 'JIMI', 'sensor', 'info', 'Alarme de Entrada 1 Desacionada', 'INPUT1 deactivated alarm', 0, 0),
    ('232', 'JIMI', 'sensor', 'warning', 'Alarme de Entrada 2 Acionada', 'INPUT2 activated alarm', 1, 0),
    ('233', 'JIMI', 'sensor', 'info', 'Alarme de Entrada 2 Desacionada', 'INPUT2 deactivated alarm', 0, 0),
    ('261', 'JIMI', 'veiculo', 'info', 'Início de Movimento', 'Motion Start Alert (transition from stationary to moving)', 0, 0),
    ('262', 'JIMI', 'veiculo', 'info', 'Fim de Movimento', 'Motion Stop Alert (transition from moving to stationary)', 0, 0);

-- ------------------------------------------------------------
-- Conferência
-- ------------------------------------------------------------
SELECT 'total JIMI (esperado 197)' AS conferencia;
SELECT COUNT(*) AS total, SUM(`is_diagnostic`) AS diagnosticos
  FROM `alarm_types` WHERE `protocol` = 'JIMI';

SELECT 'a colisao 262: as duas linhas convivem, uma por protocolo' AS conferencia;
SELECT `alarm_code`, `protocol`, `alarm_name_pt`
  FROM `alarm_types` WHERE `alarm_code` = '262' ORDER BY `protocol`;

SELECT 'nomes compartilhados de proposito (o filtro casa por nome)' AS conferencia;
SELECT `alarm_name_pt`, GROUP_CONCAT(`alarm_code` ORDER BY CAST(`alarm_code` AS UNSIGNED)) AS codigos
  FROM `alarm_types` WHERE `protocol` = 'JIMI'
 GROUP BY `alarm_name_pt` HAVING COUNT(*) > 1 ORDER BY `alarm_name_pt`;

-- 🔴 Espera-se EXATAMENTE 4 linhas aqui — 106, 183, 135 e 90 —, e é o efeito
-- deliberado do compartilhamento de nome (ver o cabeçalho). Linha a MAIS
-- significa que algum nome novo colidiu por acaso com um parâmetro existente,
-- e aí o alarme passa a abrir ocorrência sem ninguém ter decidido isso.
SELECT 'quais dos novos herdam parametro de ocorrencia (esperado: 90, 106, 135, 183)' AS conferencia;
SELECT at.alarm_code, at.alarm_name_pt
  FROM `alarm_types` at
  JOIN `occurrence_config_params` ocp ON ocp.alarm_type = at.alarm_name_pt
 WHERE at.protocol = 'JIMI' AND CAST(at.alarm_code AS UNSIGNED) IN
       (27,37,38,39,49,54,55,56,57,58,59,63,64,65,66,67,68,69,70,72,73,74,80,81,82,84,85,86,87,88,89,90,
        91,92,93,95,96,97,98,99,100,106,111,112,113,114,115,116,118,119,120,121,122,123,124,126,128,129,
        130,131,133,135,152,153,164,165,168,169,171,172,173,177,178,179,180,181,182,183,184,185,186,187,
        188,189,190,191,197,198,200,201,202,203,224,225,226,227,230,231,232,233,261,262);
