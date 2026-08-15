-- ============================================================================
-- JIMI Webhook System — Migração v4.9.24
--
-- Fecha o catálogo de parâmetros contra a tabela COMPLETA do JT/T 808.
--
-- 🔑 A FONTE NOVA: `QuecPython/jtt808`, `docs/en/API_Reference.md`, método
-- `TerminalParams.set_params`. É a tabela `0x0001`–`0x0110` inteira — **86
-- IDs**, com tipo, unidade, faixa e a distinção 2013 vs 2019 — que nem a
-- Tabela 2.3.9.1 da Jimi nem o PDF da MEITRACK (`docs/jtt-808-2019-meigou.pdf`,
-- que publica só 4 IDs) trazem. Ela CONFIRMA a descoberta da v4.9.15: os
-- números do device são os IDs da norma em decimal. Nenhuma divergência de
-- numeração apareceu em 47 parâmetros conferidos.
--
-- ⚠️ O PDF da MEITRACK não contradiz nada e não acrescenta parâmetro nenhum.
-- Fica registrado para ninguém reabrir a conferência achando que falta ler.
--
-- ── O QUE MUDA E POR QUÊ ────────────────────────────────────────────────────
--
-- 🔴 1. `93` (0x005D) NÃO é bitmask — e estava GRAVÁVEL com dica errada.
--    A norma define DOIS campos: tempo de colisão (ms) e aceleração de
--    colisão (0,1 g, faixa 0–79, default 10). O catálogo dizia `bitmask`, e
--    `param_input_spec()` exibia "Máscara de bits em DECIMAL" num parâmetro de
--    SEGURANÇA gravável: quem digitasse `10` querendo 10 × 0,1 g escreveria
--    tempo de colisão = 0. Vira `composite` e SOMENTE LEITURA até alguém
--    medir como o hub serializa a escrita de um composto — é a mesma regra
--    que segurou o `100` desde a v4.9.15, aplicada ao caso em que ela custa
--    caro: aqui não se perde informação, se evita desconfigurar uma câmera.
--
-- 🔑 2. `100` (0x0064) TEM NOME — era o último `Parâmetro NNN` oculto.
--    `Timing photo control`: composto de 12 campos (liga/desliga e destino de
--    5 canais + unidade de tempo + intervalo). A v4.9.15 o deixou oculto
--    dizendo "continua sem fonte"; a fonte existe. Sai da lista de ocultos e
--    entra no grupo `video`, SOMENTE LEITURA pela mesma razão do `93`.
--
-- ⚠️ 3. `132` (0x0084) — faltavam DOIS códigos no enum.
--    JT/T 697.7-2014: azul 1, amarelo 2, preto 3, branco 4, **verde 5**,
--    outra 9 — e **`0` = veículo sem placa**. O `0` não é detalhe: é
--    exatamente o valor que o JC371 devolveu em campo, e a tela vinha
--    mostrando o número cru por não achá-lo no mapa.
--
-- ✅ 4. `6`, `16`, `17`, `18` deixam de ser inferência.
--    `6` entrou na v4.9.15 como `0x0006 (simetria)` por falta de fonte; a
--    fonte confirma "SMS message response timeout". `16`/`17`/`18` estavam
--    como `medido/inferido` e são `0x0010`/`0x0011`/`0x0012`. A inferência
--    estava certa nos quatro — o que muda é o `doc_ref` parar de mentir sobre
--    a procedência.
--
-- ⚠️ 5. `24`/`25` (0x0018/0x0019) são OBSOLETOS no 808-2019.
--    A edição 2019 fundiu porta TCP/UDP dentro de `0x0013`, que passa a ser
--    `host:porta;host:porta`. O JC371 respondeu à moda 2013 (`19` = IP puro,
--    `24` = 21122 à parte), então hoje funciona — mas o `doc_ref` passa a
--    dizer isso, senão o primeiro firmware 2019 puro vira caça ao fantasma.
--    Ficam graváveis: é o que os equipamentos em operação usam.
--
-- ── 6. OS 39 QUE A NORMA TEM E NÓS NÃO TÍNHAMOS ─────────────────────────────
--    Nenhuma câmera nossa reportou nenhum deles — o catálogo sempre foi
--    dirigido por medição, e por isso a ausência não era defeito. Entram
--    agora TODOS como SOMENTE LEITURA, e a razão é a lição da v4.9.15: sem
--    linha no catálogo, o dia em que um firmware reportar `80` a tela mostra
--    `Parâmetro 80` e o esconde. Linha no dicionário não aparece em tela
--    nenhuma enquanto não houver leitura em `device_params` (a grade é
--    `FROM device_params LEFT JOIN device_param_catalog`), então o custo aqui
--    é zero e o ganho é não repetir a arqueologia.
--
--    O mais relevante do lote é o `80` (0x0050), **máscara de bloqueio de
--    alarme**: é a alavanca de volume mais direta que existe num produto de
--    ocorrências, e não estava sequer nomeada.
--
-- GRAVABILIDADE: nada nesta migração vira gravável. Dois viram somente
-- leitura (`93`, `100`). Escrever composto sem seletor de campos na tela é o
-- mesmo convite a erro que a v4.9.15 recusou para os bitmasks `82`/`83`.
-- ============================================================================

-- ── 1. `composite`: tipo novo para parâmetro de MÚLTIPLOS campos ────────────
--
-- Diferente de `csv` (lista posicional de um canal de vídeo) e de `bitmask`
-- (um inteiro cujos BITS têm significado): aqui são campos independentes, com
-- unidades diferentes, empacotados num valor só. Sem tipo próprio, o `93`
-- volta a cair na dica de bitmask.
ALTER TABLE `device_param_catalog`
  MODIFY `value_kind` ENUM('int','decimal','ip','port','text','enum','bitmask','csv','composite')
         NOT NULL DEFAULT 'text';

-- ── 2. `93` (0x005D): composto, e fora da escrita ───────────────────────────
UPDATE `device_param_catalog` SET
  name_pt    = 'Alarme de Colisão (tempo + aceleração)',
  name_en    = 'Collision alarm parameter',
  unit       = NULL,
  value_kind = 'composite',
  grupo      = 'seguranca',
  writable   = 0,
  doc_ref    = 'JT/T 808 0x005D'
WHERE param_no = 93;

-- ── 3. `100` (0x0064): identificado, sai dos ocultos ────────────────────────
UPDATE `device_param_catalog` SET
  name_pt    = 'Controle de Foto por Tempo',
  name_en    = 'Timing photo control',
  unit       = NULL,
  value_kind = 'composite',
  grupo      = 'video',
  writable   = 0,
  is_hidden  = 0,
  doc_ref    = 'JT/T 808 0x0064'
WHERE param_no = 100;

-- ── 4. `132` (0x0084): enum completo da JT/T 697.7-2014 ─────────────────────
UPDATE `device_param_catalog` SET
  name_en   = 'License plate color',
  enum_json = '{"0":"Sem placa","1":"Azul","2":"Amarelo","3":"Preto","4":"Branco","5":"Verde","9":"Outra"}',
  doc_ref   = 'JT/T 808 0x0084'
WHERE param_no = 132;

-- ── 5. Procedência: inferência vira documentação ────────────────────────────
UPDATE `device_param_catalog` SET doc_ref = 'JT/T 808 0x0006' WHERE param_no = 6;
UPDATE `device_param_catalog` SET doc_ref = 'JT/T 808 0x0010' WHERE param_no = 16;
UPDATE `device_param_catalog` SET doc_ref = 'JT/T 808 0x0011' WHERE param_no = 17;
UPDATE `device_param_catalog` SET doc_ref = 'JT/T 808 0x0012' WHERE param_no = 18;
UPDATE `device_param_catalog` SET doc_ref = 'JT/T 808 0x0017' WHERE param_no = 23;

-- O `19` carrega o aviso do formato 2019 no próprio `doc_ref` porque é ele que
-- a tela mostra ao lado do valor.
UPDATE `device_param_catalog` SET
  doc_ref = 'JT/T 808 0x0013 (2019: host:porta)' WHERE param_no = 19;
UPDATE `device_param_catalog` SET
  doc_ref = 'JT/T 808 0x0018 (2019 -> 0x0013)' WHERE param_no = 24;
UPDATE `device_param_catalog` SET
  doc_ref = 'JT/T 808 0x0019 (2019 -> 0x0013)' WHERE param_no = 25;

-- ── 6. Os 39 da norma que faltavam — TODOS somente leitura ──────────────────
-- `INSERT IGNORE`, como toda semente deste catálogo: quem já melhorou um
-- rótulo pela tela não pode ser desfeito por migração.

-- 6a. Rede: autenticação de cartão IC e servidor secundário ─────────────────
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(26, 'Servidor IC: Endereço Principal', 'IC card auth main server',   NULL, 'ip',   NULL, 'rede', 0, 0, 'JT/T 808 0x001A'),
(27, 'Servidor IC: Porta TCP',          'IC card auth TCP port',      NULL, 'port', NULL, 'rede', 0, 0, 'JT/T 808 0x001B'),
(28, 'Servidor IC: Porta UDP',          'IC card auth UDP port',      NULL, 'port', NULL, 'rede', 0, 0, 'JT/T 808 0x001C'),
(29, 'Servidor IC: Endereço de Backup', 'IC card auth backup server', NULL, 'ip',   NULL, 'rede', 0, 0, 'JT/T 808 0x001D'),
(35, 'APN do Servidor Secundário',      'Slave server APN',           NULL, 'text', NULL, 'rede', 0, 0, 'JT/T 808 0x0023'),
(36, 'Usuário do APN Secundário',       'Slave server dial username', NULL, 'text', NULL, 'rede', 0, 1, 'JT/T 808 0x0024'),
(37, 'Senha do APN Secundário',         'Slave server dial password', NULL, 'text', NULL, 'rede', 0, 1, 'JT/T 808 0x0025'),
(38, 'Servidor Secundário (backup)',    'Slave backup server address',NULL, 'ip',   NULL, 'rede', 0, 0, 'JT/T 808 0x0026');

-- 6b. Condução: faixa de horário proibido ───────────────────────────────────
-- Composto de 4 campos (hora e minuto de início, hora e minuto de fim).
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(50, 'Faixa de Horário Proibido', 'Illegal driving time range', NULL, 'composite', NULL, 'conducao', 0, 0, 'JT/T 808 0x0032');

-- 6c. Segurança: as três máscaras que faltavam ──────────────────────────────
-- 🔑 O `80` é o irmão que faltava do `82`/`83` que já tínhamos: os três são
-- máscaras sobre o MESMO campo de flags do relatório de posição. O `80`
-- bloqueia o alarme na origem — num produto de ocorrências, é volume.
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(80, 'Máscara de Bloqueio de Alarme', 'Alarm blocking word',   NULL, 'bitmask', NULL, 'seguranca', 0, 0, 'JT/T 808 0x0050'),
(81, 'Alarme por SMS (máscara)',      'Alarm SMS switch',      NULL, 'bitmask', NULL, 'seguranca', 0, 0, 'JT/T 808 0x0051'),
(84, 'Alarme Prioritário (máscara)',  'Key alarm flag',        NULL, 'bitmask', NULL, 'seguranca', 0, 0, 'JT/T 808 0x0054');

-- 6d. Telefonia ─────────────────────────────────────────────────────────────
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(64, 'Telefone da Central',               'Monitoring platform phone',   NULL, 'text', NULL, 'telefonia', 0, 0, 'JT/T 808 0x0040'),
(65, 'Telefone de Reinício',              'Reset phone number',          NULL, 'text', NULL, 'telefonia', 0, 0, 'JT/T 808 0x0041'),
(66, 'Telefone de Restauro de Fábrica',   'Factory reset phone number',  NULL, 'text', NULL, 'telefonia', 0, 0, 'JT/T 808 0x0042'),
(67, 'Telefone SMS da Central',           'Platform SMS phone number',   NULL, 'text', NULL, 'telefonia', 0, 0, 'JT/T 808 0x0043'),
(68, 'Telefone para SMS de Alarme',       'Alarm SMS receiving number',  NULL, 'text', NULL, 'telefonia', 0, 0, 'JT/T 808 0x0044'),
(69, 'Política de Atendimento',           'Phone answer policy',         NULL, 'enum',
     '{"0":"Atende sempre","1":"Atende com ignição ligada"}',                  'telefonia', 0, 0, 'JT/T 808 0x0045'),
(70, 'Duração Máxima por Chamada',        'Max duration per call',       's',  'int',  NULL, 'telefonia', 0, 0, 'JT/T 808 0x0046'),
(71, 'Tempo de Chamada no Mês',           'Max talk time per month',     's',  'int',  NULL, 'telefonia', 0, 0, 'JT/T 808 0x0047'),
(72, 'Telefones de Escuta',               'Listen-in phone numbers',     NULL, 'text', NULL, 'telefonia', 0, 0, 'JT/T 808 0x0048'),
(73, 'Telefone SMS Privilegiado',         'Privileged SMS number',       NULL, 'text', NULL, 'telefonia', 0, 0, 'JT/T 808 0x0049');

-- 6e. Vídeo: foto por distância e qualidade de imagem ───────────────────────
-- ⚠️ `112` é invertido: 1 é a MELHOR qualidade, 10 a pior. Escrever "10"
-- achando que é o máximo entrega o mínimo — está na dica porque é o tipo de
-- inversão que ninguém confere.
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(101,'Controle de Foto por Distância', 'Fixed distance photo control', NULL,'composite',NULL,'video', 0, 0, 'JT/T 808 0x0065'),
(112,'Qualidade de Imagem (1=melhor)', 'Image/video quality 1-10',     NULL,'int',      NULL,'video', 0, 0, 'JT/T 808 0x0070'),
(113,'Brilho',                         'Brightness 0-255',             NULL,'int',      NULL,'video', 0, 0, 'JT/T 808 0x0071'),
(114,'Contraste',                      'Contrast 0-127',               NULL,'int',      NULL,'video', 0, 0, 'JT/T 808 0x0072'),
(115,'Saturação',                      'Saturation 0-127',             NULL,'int',      NULL,'video', 0, 0, 'JT/T 808 0x0073'),
(116,'Cromaticidade',                  'Chromaticity 0-255',           NULL,'int',      NULL,'video', 0, 0, 'JT/T 808 0x0074');

-- 6f. GNSS ──────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(144,'Constelações Habilitadas',    'GNSS positioning mode',        NULL,'composite',NULL,'gnss', 0, 0, 'JT/T 808 0x0090'),
(145,'Baud Rate do GNSS',           'GNSS baud rate',               NULL,'enum',
     '{"0":"4800","1":"9600","2":"19200","3":"38400","4":"57600","5":"115200"}',      'gnss', 0, 0, 'JT/T 808 0x0091'),
(146,'Frequência de Saída do GNSS', 'GNSS output frequency',        NULL,'enum',
     '{"0":"500 ms","1":"1000 ms","2":"2000 ms","3":"3000 ms","4":"4000 ms"}',        'gnss', 0, 0, 'JT/T 808 0x0092'),
(147,'Frequência de Coleta do GNSS','GNSS acquisition frequency',   's', 'int',  NULL, 'gnss', 0, 0, 'JT/T 808 0x0093'),
(148,'Modo de Upload do GNSS',      'GNSS upload method',           NULL,'enum',
     '{"0":"Só armazena","1":"Por tempo","2":"Por distância","11":"Tempo acumulado","12":"Distância acumulada","13":"Quantidade acumulada"}',
                                                                                      'gnss', 0, 0, 'JT/T 808 0x0094'),
(149,'Ajuste de Upload do GNSS',    'GNSS upload setting',          NULL,'int',  NULL, 'gnss', 0, 0, 'JT/T 808 0x0095');

-- 6g. CAN bus ───────────────────────────────────────────────────────────────
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(256,'CAN1: Intervalo de Coleta', 'CAN1 acquisition interval', 'ms','int',      NULL,'can', 0, 0, 'JT/T 808 0x0100'),
(257,'CAN1: Intervalo de Envio',  'CAN1 upload interval',      's', 'int',      NULL,'can', 0, 0, 'JT/T 808 0x0101'),
(258,'CAN2: Intervalo de Coleta', 'CAN2 acquisition interval', 'ms','int',      NULL,'can', 0, 0, 'JT/T 808 0x0102'),
(259,'CAN2: Intervalo de Envio',  'CAN2 upload interval',      's', 'int',      NULL,'can', 0, 0, 'JT/T 808 0x0103'),
(272,'CAN: Coleta por ID',        'CAN bus ID separate collect',NULL,'composite',NULL,'can', 0, 0, 'JT/T 808 0x0110');

-- ── 7. Conferências ─────────────────────────────────────────────────────────

-- 7a. Nada oculto deve sobrar: o `100` era o último. Tem de vir VAZIO.
SELECT 'ainda oculto (tem de vir vazio)' AS conferencia,
       GROUP_CONCAT(param_no ORDER BY CAST(param_no AS UNSIGNED)) AS params
  FROM `device_param_catalog` WHERE is_hidden = 1;

-- 7b. A trava da v4.9.14: nenhum gravável sem nome de verdade. VAZIO.
SELECT 'GRAVAVEL SEM NOME (tem de vir vazio)' AS conferencia,
       GROUP_CONCAT(param_no) AS params
  FROM `device_param_catalog`
 WHERE writable = 1 AND name_pt REGEXP '^Parâmetro [0-9]+$';

-- 7c. 🔴 Nenhum `composite` pode ser gravável enquanto a tela não tiver
--     seletor de campos — é a razão de ser desta migração. VAZIO.
SELECT 'COMPOSITE GRAVAVEL (tem de vir vazio)' AS conferencia,
       GROUP_CONCAT(param_no) AS params
  FROM `device_param_catalog` WHERE value_kind = 'composite' AND writable = 1;

-- 7d. Cobertura: os 46 medidos no JC371 continuam todos com linha. VAZIO.
SELECT n AS param_sem_catalogo FROM (
  SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
  UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 16 UNION ALL SELECT 17
  UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20 UNION ALL SELECT 21
  UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24 UNION ALL SELECT 25
  UNION ALL SELECT 32 UNION ALL SELECT 33 UNION ALL SELECT 34 UNION ALL SELECT 39
  UNION ALL SELECT 40 UNION ALL SELECT 41 UNION ALL SELECT 44 UNION ALL SELECT 45
  UNION ALL SELECT 46 UNION ALL SELECT 47 UNION ALL SELECT 48 UNION ALL SELECT 49
  UNION ALL SELECT 82 UNION ALL SELECT 83 UNION ALL SELECT 85 UNION ALL SELECT 86
  UNION ALL SELECT 87 UNION ALL SELECT 88 UNION ALL SELECT 89 UNION ALL SELECT 90
  UNION ALL SELECT 91 UNION ALL SELECT 92 UNION ALL SELECT 93 UNION ALL SELECT 100
  UNION ALL SELECT 128 UNION ALL SELECT 129 UNION ALL SELECT 130 UNION ALL SELECT 131
  UNION ALL SELECT 132
) medidos
 LEFT JOIN `device_param_catalog` c ON c.param_no = medidos.n
 WHERE c.param_no IS NULL;

-- 7e. Cor da placa: o `0` do JC371 tem de resolver para um rótulo.
SELECT 'cor 0 resolve?' AS conferencia,
       JSON_UNQUOTE(JSON_EXTRACT(enum_json, '$."0"')) AS rotulo_0,
       JSON_UNQUOTE(JSON_EXTRACT(enum_json, '$."5"')) AS rotulo_5
  FROM `device_param_catalog` WHERE param_no = 132;

-- 7f. Panorama final por grupo.
SELECT grupo, COUNT(*) AS params, SUM(writable) AS gravaveis, SUM(is_hidden) AS ocultos
  FROM `device_param_catalog` GROUP BY grupo ORDER BY params DESC;

SELECT 'total no catalogo' AS conferencia, COUNT(*) AS itens FROM `device_param_catalog`;

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.24', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.24', last_update = NOW();

SELECT 'Migracao v4.9.24 concluida' AS status;
