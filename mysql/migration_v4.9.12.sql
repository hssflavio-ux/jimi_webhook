-- ============================================================================
-- JIMI Webhook System — Migração v4.9.12
--
-- F1 do `PROJETO_PARAMETROS.md`: as três tabelas da parametrização das câmeras
-- JT/T e o catálogo que traduz `"85":"110"` em "Velocidade máxima — 110 km/h".
--
-- ⚠️ O catálogo tem DUAS procedências, e a coluna `doc_ref` diz qual:
--
--   '2.3.9.1'          — publicado na Tabela 2.3.9.1 da doc oficial (29 itens)
--   'medido'           — CHEGOU de câmera real e a doc não publica (17 itens)
--   'medido/inferido'  — chegou de câmera real, nome deduzido do VALOR (3)
--
-- A distinção não é burocracia. A regra do repo é **nunca batizar por
-- palpite** (CLAUDE.md), e ela vale aqui: `Parâmetro 128` é um nome honesto
-- para algo que só se sabe existir. Já `16` = `cmnet` é o APN da operadora, com
-- `17` = `usr` e `18` = `pwd` na sequência — isso não é palpite, é leitura de
-- valor, e fica marcado como inferido para quem vier depois saber a diferença.
--
-- ⚠️ Junção SEMPRE por `param_no`. Mesma armadilha que o CLAUDE.md documenta
-- três vezes para `alarm_types`: junção por NOME morre em silêncio quando
-- alguém renomeia o rótulo. Nenhuma tabela daqui guarda `name_pt` como chave.
--
-- Só JT/T. `33027`/`33028`/`33030` são da seção 2 da doc (msgClass=1) e
-- câmera JIMI não participa, por ADR-001 — por isso não há coluna `protocol`:
-- ela teria um valor só, e um valor só é uma mentira à espera de acontecer.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── 1. O dicionário ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `device_param_catalog` (
  `param_no`   SMALLINT UNSIGNED NOT NULL COMMENT 'Numero do parametro no protocolo JT/T',
  `name_pt`    VARCHAR(120) NOT NULL,
  `name_en`    VARCHAR(120) NULL,
  `unit`       VARCHAR(20)  NULL COMMENT 's, m, km/h, graus, kbps',
  `value_kind` ENUM('int','decimal','ip','port','text','enum','bitmask','csv')
               NOT NULL DEFAULT 'text',
  `enum_json`  JSON NULL COMMENT '{"0":"CBR","1":"VBR"} para value_kind=enum',
  `grupo`      VARCHAR(40) NOT NULL DEFAULT 'outros',
  `writable`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Nada e gravavel por omissao',
  `is_secret`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Mascarar na tela (credencial)',
  `doc_ref`    VARCHAR(24) NULL COMMENT '2.3.9.1 | medido | medido/inferido',
  PRIMARY KEY (`param_no`),
  KEY `idx_dpc_grupo` (`grupo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dicionario dos parametros de configuracao das cameras JT/T';

-- ── 2. O estado atual ───────────────────────────────────────────────────────
--
-- `channel` na chave primária é o que resolve o bloco `channel_N` sem tabela
-- extra: o CSV de 12 posições do canal 2 vira (imei, 119, 2). Canal 0 = global.
CREATE TABLE IF NOT EXISTS `device_params` (
  `imei`          VARCHAR(20) NOT NULL,
  `param_no`      SMALLINT UNSIGNED NOT NULL,
  `channel`       TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = global; 1..N = canal de video',
  `value_raw`     VARCHAR(255) NOT NULL,
  `value_json`    JSON NULL COMMENT 'CSV de canal expandido e nomeado',
  `read_at`       DATETIME NOT NULL,
  `source`        VARCHAR(16) NOT NULL COMMENT '33028 | 33030 | 33027-echo',
  `desired_value` VARCHAR(255) NULL COMMENT 'Valor pedido; gravado ANTES do envio',
  `applied_at`    DATETIME NULL,
  PRIMARY KEY (`imei`, `param_no`, `channel`),
  KEY `idx_dp_param` (`param_no`),
  KEY `idx_dp_read`  (`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configuracao ATUAL de cada camera, um registro por parametro';

-- ── 3. A verdade bruta ──────────────────────────────────────────────────────
--
-- Append-only. `param_count` (o que o device declarou) e `parsed_count` (o que
-- o parser extraiu) lado a lado: divergir é o NORMAL — o JC371 medido declara
-- 87 e entrega 46 chaves —, não é erro. Mas some do radar se só um for
-- guardado, e foi não guardar o payload bruto que criou o defeito da v4.9.11.
CREATE TABLE IF NOT EXISTS `device_param_snapshots` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `imei`         VARCHAR(20) NOT NULL,
  `pro_no`       SMALLINT UNSIGNED NOT NULL COMMENT '33028 | 33030',
  `content_raw`  TEXT NOT NULL COMMENT '_content INTEIRO, como chegou',
  `param_count`  INT NULL COMMENT 'paramCount declarado pelo device',
  `parsed_count` INT NOT NULL COMMENT 'Quantas entradas o parser extraiu',
  `command_id`   BIGINT UNSIGNED NULL,
  `created_at`   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dps_imei` (`imei`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historico bruto de cada leitura de parametros';

-- ── 4. Colunas em tabelas existentes ────────────────────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'devices'
              AND column_name = 'params_synced_at');
SET @sql := IF(@c = 0,
    'ALTER TABLE `devices`
       ADD COLUMN `params_synced_at`  DATETIME NULL COMMENT ''Ultima leitura completa de parametros (NULL = nunca)'',
       ADD COLUMN `params_sync_tries` SMALLINT NOT NULL DEFAULT 0,
       ADD COLUMN `params_sync_next`  DATETIME NULL COMMENT ''Backoff: nao tentar antes disto''',
    'SELECT ''devices.params_synced_at ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- `commands.pro_no`: hoje o proNo só existe embutido em `api_type` ('jtt_33028').
-- Coluna própria porque a correlação do callback precisa dele e parsear string
-- para decidir fluxo é o tipo de coisa que quebra calada.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'commands'
              AND column_name = 'pro_no');
SET @sql := IF(@c = 0,
    'ALTER TABLE `commands` ADD COLUMN `pro_no` SMALLINT UNSIGNED NULL
       COMMENT ''proNo enviado ao IoTHub; antes so existia dentro de api_type''',
    'SELECT ''commands.pro_no ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Retroalimenta o histórico a partir do `api_type` ('jtt_33028' → 33028).
--
-- ⚠️ `REGEXP` e não `LIKE 'jtt\_%'`: dentro de string do MySQL o `\_` perde a
-- barra (escape não reconhecido) e vira o CORINGA de um caractere do LIKE, que
-- é outra coisa. Com o padrão frouxo a linha `api_type = 'instruct'` entrava no
-- UPDATE e o `CAST('uct' AS UNSIGNED)` derrubava a migração inteira em modo
-- estrito: `Truncated incorrect INTEGER value: 'instruct'`. O REGEXP ancorado
-- exige literalmente `jtt_` seguido só de dígitos.
UPDATE `commands`
   SET `pro_no` = CAST(SUBSTRING(`api_type`, 5) AS UNSIGNED)
 WHERE `pro_no` IS NULL AND `api_type` REGEXP '^jtt_[0-9]+$';

-- ── 5. Semente do catálogo ──────────────────────────────────────────────────
--
-- `INSERT IGNORE`, nunca `ON DUPLICATE KEY UPDATE`: se alguém melhorou um
-- rótulo pela tela, a migração não pode desfazer.

-- 5a. Rede e servidor ───────────────────────────────────────────────────────
-- 🔴 Estes tiram a câmera da plataforma se forem escritos errado. Ficam
-- `writable = 1` por decisão do dono do produto (12/08/2026): a recuperação é
-- por SMS. Ver PROJETO_PARAMETROS.md §8.1 — a contrapartida é gravar
-- `desired_value` ANTES do envio, senão o SMS não tem para onde apontar.
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(16, 'APN da Operadora',            'APN',                        NULL, 'text', NULL, 'rede', 1, 0, 'medido/inferido'),
(17, 'Usuário do APN',              'APN user',                   NULL, 'text', NULL, 'rede', 1, 1, 'medido/inferido'),
(18, 'Senha do APN',                'APN password',               NULL, 'text', NULL, 'rede', 1, 1, 'medido/inferido'),
(19, 'Servidor Principal',          'Main server address',        NULL, 'ip',   NULL, 'rede', 1, 0, '2.3.9.1'),
(20, 'Parâmetro 20',                NULL,                         NULL, 'text', NULL, 'rede', 0, 0, 'medido'),
(21, 'Parâmetro 21',                NULL,                         NULL, 'text', NULL, 'rede', 0, 0, 'medido'),
(22, 'Parâmetro 22',                NULL,                         NULL, 'text', NULL, 'rede', 0, 0, 'medido'),
(23, 'Servidor de Backup',          'Backup server address',      NULL, 'ip',   NULL, 'rede', 1, 0, '2.3.9.1'),
(24, 'Porta TCP do Servidor',       'Server TCP port',            NULL, 'port', NULL, 'rede', 1, 0, '2.3.9.1'),
(25, 'Porta UDP do Servidor',       'Server UDP port',            NULL, 'port', NULL, 'rede', 1, 0, '2.3.9.1');

-- 5b. Estratégia de reporte ─────────────────────────────────────────────────
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(1,  'Intervalo de Heartbeat',            'Heartbeat interval',            's', 'int',  NULL, 'reporte', 1, 0, '2.3.9.1'),
(32, 'Estratégia de Reporte',             'Position reporting strategy',   NULL,'enum',
     '{"0":"Por tempo","1":"Por distância","2":"Por tempo e distância"}',                   'reporte', 1, 0, '2.3.9.1'),
(33, 'Critério de Reporte',               'Location reporting scheme',     NULL,'enum',
     '{"0":"Pelo ACC","1":"Pelo login do motorista e ACC"}',                                'reporte', 1, 0, '2.3.9.1'),
(34, 'Intervalo sem Motorista Logado',    'Interval driver not logged in', 's', 'int',  NULL, 'reporte', 1, 0, '2.3.9.1'),
(39, 'Intervalo em Repouso',              'Sleep reporting interval',      's', 'int',  NULL, 'reporte', 1, 0, '2.3.9.1'),
(40, 'Intervalo em Emergência',           'Emergency reporting interval',  's', 'int',  NULL, 'reporte', 1, 0, '2.3.9.1'),
(41, 'Intervalo Padrão de Reporte',       'Default time report interval',  's', 'int',  NULL, 'reporte', 1, 0, '2.3.9.1'),
(44, 'Distância Padrão de Reporte',       'Default distance interval',     'm', 'int',  NULL, 'reporte', 1, 0, '2.3.9.1'),
(45, 'Distância sem Motorista Logado',    'Distance driver not logged in', 'm', 'int',  NULL, 'reporte', 1, 0, '2.3.9.1'),
(46, 'Parâmetro 46',                      NULL,                            NULL,'text', NULL, 'reporte', 0, 0, 'medido'),
(47, 'Distância em Emergência',           'Emergency distance interval',   'm', 'int',  NULL, 'reporte', 1, 0, '2.3.9.1'),
(48, 'Ângulo de Ponto de Inflexão',       'Inflection point angle',        '°', 'int',  NULL, 'reporte', 1, 0, '2.3.9.1');

-- 5c. Comportamento de condução ─────────────────────────────────────────────
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(85, 'Velocidade Máxima',               'Maximum speed',                 'km/h','int',    NULL,'conducao', 1, 0, '2.3.9.1'),
(86, 'Duração para Excesso',            'Overspeed duration',            's',   'int',    NULL,'conducao', 1, 0, '2.3.9.1'),
(87, 'Condução Contínua (limite)',      'Continuous driving threshold',  's',   'int',    NULL,'conducao', 1, 0, '2.3.9.1'),
(88, 'Condução Acumulada no Dia',       'Cumulative driving of the day', 's',   'int',    NULL,'conducao', 1, 0, '2.3.9.1'),
(89, 'Descanso Mínimo',                 'Minimum rest time',             's',   'int',    NULL,'conducao', 1, 0, '2.3.9.1'),
(90, 'Tempo Máximo Estacionado',        'Maximum parking time',          's',   'int',    NULL,'conducao', 1, 0, '2.3.9.1'),
(91, 'Margem de Pré-aviso de Excesso',  'Overspeed warning difference',  '1/10 km/h','decimal', NULL,'conducao', 1, 0, '2.3.9.1'),
(92, 'Margem de Pré-aviso de Fadiga',   'Fatigue warning difference',    's',   'int',    NULL,'conducao', 1, 0, '2.3.9.1');

-- 5d. Segurança — colisão, capotamento, cerca ───────────────────────────────
-- O `94` é o ângulo de capotamento, e liga direto no alarme JT/T 1047 que a
-- v4.9.10 batizou de `Capotamento`. Ele é DOCUMENTADO e o JC371 sondado NÃO o
-- devolveu — que é a prova viva de que ausência não é "desconfigurado".
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(49, 'Raio da Cerca Eletrônica',        'Electronic fence radius',       'm','int',    NULL,'seguranca', 1, 0, '2.3.9.1'),
(93, 'Parâmetro de Colisão',            'Collision alarm parameter',     NULL,'bitmask',NULL,'seguranca', 1, 0, '2.3.9.1'),
(94, 'Ângulo de Capotamento',           'Rollover angle',                '°','int',    NULL,'seguranca', 1, 0, '2.3.9.1');

-- 5e. Vídeo ─────────────────────────────────────────────────────────────────
-- ⚠️ `119` NÃO chega como a doc descreve. O device manda um bloco estruturado
-- (`paramId`/`channelCount`/`channel_N`), e cada `channel_N` é o CSV de 12
-- posições da Tabela 2.3.9.2.3. Ver PROJETO_PARAMETROS.md §2.5.3.
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(117,'Áudio e Vídeo (geral)',           'Audio/video parameters',        NULL,'csv',NULL,'video', 1, 0, '2.3.9.2.1'),
(119,'Vídeo por Canal',                 'Single channel video params',   NULL,'csv',NULL,'video', 1, 0, '2.3.9.2.3');

-- 5f. Identificação ─────────────────────────────────────────────────────────
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(132,'Cor da Placa',                    'License plate color',           NULL,'enum',
     '{"1":"Azul","2":"Amarelo","3":"Preto","4":"Branco","9":"Outra"}',                 'identificacao', 1, 0, '2.3.9.1');

-- 5g. Medidos e não documentados ────────────────────────────────────────────
-- Chegaram de câmera real em 12/08/2026 e a Tabela 2.3.9.1 não os publica.
-- Entram com nome honesto (`Parâmetro NNN`) e `writable = 0`: não se escreve o
-- que não se sabe nomear. Quando o fornecedor informar, viram um UPDATE — a
-- mesma trajetória do alarme 1047.
INSERT IGNORE INTO `device_param_catalog`
  (param_no, name_pt, name_en, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref) VALUES
(2,  'Parâmetro 2',   NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(3,  'Parâmetro 3',   NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(4,  'Parâmetro 4',   NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(5,  'Parâmetro 5',   NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(6,  'Parâmetro 6',   NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(7,  'Parâmetro 7',   NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(82, 'Parâmetro 82',  NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(83, 'Parâmetro 83',  NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(100,'Parâmetro 100', NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(128,'Parâmetro 128', NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(129,'Parâmetro 129', NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(130,'Parâmetro 130', NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido'),
(131,'Parâmetro 131', NULL, NULL, 'text', NULL, 'outros', 0, 0, 'medido');

-- ── 6. Conferência: o catálogo por procedência ──────────────────────────────
SELECT doc_ref, COUNT(*) AS itens FROM `device_param_catalog`
 GROUP BY doc_ref ORDER BY itens DESC;

-- ── 7. Conferência: cobertura do que a câmera real mandou ───────────────────
-- Os 46 parâmetros numéricos medidos no JC371 em 12/08/2026. Deve devolver
-- ZERO — nenhum pode ficar sem linha no catálogo, senão a tela esconde valor
-- que o equipamento reporta.
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

-- ── 8. Conferência: nada gravável sem nome de verdade ───────────────────────
-- Deve devolver ZERO. Escrever parâmetro que ninguém sabe nomear é oferecer ao
-- usuário mexer no escuro num equipamento em operação.
--
-- ⚠️ O teste é `doc_ref = 'medido'`, e NÃO `name_pt LIKE 'Parâmetro %'`: o 93
-- se chama "Parâmetro de Colisão", é documentado e gravável, e casava o padrão
-- de texto — a primeira versão desta conferência acusou erro onde não havia.
-- Invariante boa é sobre a PROCEDÊNCIA do dado, não sobre como o rótulo começa.
SELECT param_no, name_pt, doc_ref
  FROM `device_param_catalog`
 WHERE writable = 1 AND doc_ref = 'medido';

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.12', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.12', last_update = NOW();

SELECT 'Migracao v4.9.12 concluida' AS status;
