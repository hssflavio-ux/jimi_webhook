-- ============================================================================
-- bycamera — migration v4.16.0
-- JM-VL01 e JM-VL02: os dois primeiros equipamentos SEM CÂMERA do catálogo
--
-- Até aqui, "equipamento" e "câmera" eram sinônimos: `device_models` só tinha
-- as seis câmeras da linha JC, e todo modelo declarava `camera_count >= 1`.
-- Os dois rastreadores da linha VL falam o MESMO protocolo JIMI (msgClass=0),
-- chegam pelos MESMOS webhooks (`/pushgps`, `/pushalarm`, `/pushhb`) e aceitam
-- os MESMOS comandos de texto proNo 128 — mas não têm vídeo, nem canal, nem
-- DMS/ADAS.
--
-- 🔴 `camera_count = 0` NÃO é "não sei quantas" — é "não tem". As consultas do
-- sistema já resolvem o canal por `COALESCE(NULLIF(d.camera_count,0),
-- dm.camera_count, 1)`, e essa expressão devolve 0 (não 1) quando os DOIS são
-- zero, que é exatamente o que se quer aqui. O `1` do final só socorre o
-- equipamento sem modelo nenhum.
--
-- 🔴 A coluna `family` existe porque `camera_count = 0` responde "quantos
-- canais", não "que espécie de aparelho é este". Quem precisa da segunda
-- pergunta é a trava por modelo de `/comandos`: o flag `universal` do
-- `command_catalog.php` foi derivado de "presente em >= 5 das 6 páginas de
-- CÂMERA da wiki", e liberar a trava para a frota inteira passou a significar
-- oferecer `RECORDSW`/`VOLUME`/`SSID`/`WIFIAP` a um rastreador. `family` é o
-- que deixa `universal` continuar querendo dizer o que sempre quis.
--
-- ⚠️ NOME DO MODELO: `JM-VL01`/`JM-VL02`, com JM, não JC. `JC` é a linha de
-- câmeras (JC400, JC371, JC450…); `JM` é a de rastreadores, e é como a wiki da
-- Jimi (https://wiki.jimibrasil.com.br) e a própria fabricante os chamam.
-- `model_name` é UNIQUE e vira a chave de `command_catalog.modelos`,
-- `firmware_releases.device_model_id` e da trava da tela de comandos —
-- renomear depois quebra o casamento em silêncio, do mesmo jeito que
-- `alarm_types.alarm_name_pt` quebra o motor de ocorrências (ver CLAUDE.md).
--
-- ⚠️ Esta migração NÃO roda no deploy que a traz (o bash relê o deploy.sh em
-- execução do disco). Rode `./scripts/deploy.sh --force` duas vezes, ou
-- aplique este .sql à mão logo após o primeiro deploy.
-- ============================================================================

-- ------------------------------------------------------------
-- 1. device_models.family — câmera x rastreador
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;
DELIMITER //
CREATE PROCEDURE `add_column_if_not_exists`(IN p_table VARCHAR(128), IN p_column VARCHAR(128), IN p_definition TEXT)
BEGIN
    DECLARE col_count INT;
    SELECT COUNT(*) INTO col_count FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column;
    IF col_count = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

-- DEFAULT 'camera': todo modelo que já existia é câmera, e um modelo novo
-- cadastrado sem pensar no assunto herda o comportamento de hoje.
CALL add_column_if_not_exists('device_models', 'family',
    "enum('camera','tracker') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'camera' COMMENT 'camera = linha JC (video/DMS/ADAS); tracker = linha VL (so GPS/telemetria)' AFTER `protocol`");

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;

-- Idempotente: as seis câmeras são explicitamente 'camera' mesmo que a coluna
-- já existisse com outro valor por engano.
UPDATE `device_models` SET `family` = 'camera'
 WHERE `model_name` IN ('JC400D','JC400AD','JC371','JC450','JC181','JC182');

-- ------------------------------------------------------------
-- 2. Os dois modelos novos
-- ------------------------------------------------------------
-- camera_count = 0 (não têm canal de vídeo), protocol = JIMI (msgClass=0 —
-- comandos de texto proNo 128, nunca JT/T 808; ver ADR-001).
INSERT INTO `device_models` (`model_name`, `protocol`, `family`, `camera_count`, `description`) VALUES
('JM-VL01', 'JIMI', 'tracker', 0, 'Rastreador veicular JIMI 4G LTE com hotspot WiFi — sem câmera (protocolo JIMI)'),
('JM-VL02', 'JIMI', 'tracker', 0, 'Rastreador veicular JIMI LTE Cat-M1/NB2 — sem câmera (protocolo JIMI)')
ON DUPLICATE KEY UPDATE
    `protocol`     = VALUES(`protocol`),
    `family`       = VALUES(`family`),
    `camera_count` = VALUES(`camera_count`),
    `description`  = VALUES(`description`);

-- ------------------------------------------------------------
-- 3. Alarmes da linha VL que faltavam em `alarm_types`
-- ------------------------------------------------------------
-- Fonte: wiki.jimibrasil.com.br, páginas "Alarmes - VL01" (Tabela-vl01) e
-- "Alarmes - VL02" (Tabela-VL02) — a tabela do campo <event> do protocolo.
--
-- 🔑 O espaço de códigos é o MESMO do resto da linha JIMI, e isso foi
-- conferido código a código antes de escrever aqui: 1=SOS, 2=corte de
-- alimentação, 41/48=aceleração/frenagem brusca, 45=capotamento ("rolamento do
-- veículo" na wiki da VL) já existiam com o mesmo significado. Só entram os
-- códigos que a VL publica e o catálogo ainda não tinha.
--
-- 🔴 NENHUM alarme existente é RENOMEADO aqui, de propósito. `254` continua
-- "Status de Ignição Alterado" mesmo a wiki da VL o descrevendo como "ignição
-- ligada": `occurrence_config_params.alarm_type` e `notification_rules`
-- casam por NOME, e renomear desliga ocorrência/notificação em silêncio
-- (CLAUDE.md, v4.8.3 e v4.9.5). O que faltava era o par `255`.
--
-- 🔴 `33` e `34` ficam de fora de propósito: a wiki os publica como
-- "Reservado". Batizar por palpite é o erro que o catálogo já pagou; se
-- chegarem, aparecem como "Código 33 (JIMI)" — que é a verdade.
--
-- ⚠️ Nenhum destes ganha linha em `occurrence_config_params`: alarme sem
-- parâmetro NÃO gera ocorrência (`process_alarm_occurrence()` retorna cedo), e
-- ligar o motor de ocorrências para eventos de rastreador muda volume de
-- tratativa — é decisão de produto, não de migração. Mesmo tratamento dado à
-- "Colisão do Veículo" na v4.9.10.
INSERT IGNORE INTO `alarm_types`
    (`alarm_code`, `protocol`, `category`, `severity`, `alarm_name_pt`, `alarm_name_en`, `requires_action`, `is_diagnostic`)
VALUES
    ('0',   'JIMI', 'dispositivo', 'info',     'Normal (sem alarme)',           'Normal',                 0, 1),
    ('19',  'JIMI', 'seguranca',   'critical', 'Dispositivo Desmontado',        'Dismount Alarm',         1, 0),
    ('50',  'JIMI', 'seguranca',   'warning',  'Alerta de Reboque',             'Tow Alarm',              1, 0),
    ('60',  'JIMI', 'seguranca',   'critical', 'Roubo do Veículo',              'Vehicle Theft',          1, 0),
    ('61',  'JIMI', 'seguranca',   'critical', 'Partida Ilegal',                'Illegal Ignition',       1, 0),
    ('62',  'JIMI', 'dispositivo', 'info',     'Botão de Upload Pressionado',   'Upload Button Pressed',  0, 0),
    ('75',  'JIMI', 'veiculo',     'warning',  'Inclinação Anormal',            'Tilt Alarm',             0, 0),
    ('76',  'JIMI', 'conducao',    'warning',  'Curva Fechada',                 'Sharp Turn',             0, 0),
    ('77',  'JIMI', 'conducao',    'warning',  'Mudança Abrupta de Faixa',      'Abrupt Lane Change',     0, 0),
    ('78',  'JIMI', 'veiculo',     'warning',  'Estabilidade do Veículo',       'Vehicle Stability',      0, 0),
    ('79',  'JIMI', 'veiculo',     'warning',  'Angulação Anormal do Veículo',  'Abnormal Vehicle Angle', 0, 0),
    ('83',  'JIMI', 'seguranca',   'critical', 'Roubo de Óleo/Combustível',     'Oil Theft',              1, 0),
    ('94',  'JIMI', 'dispositivo', 'warning',  'Corte de Sinal de Pulso',       'Pulse Signal Cut',       0, 0),
    ('255', 'JIMI', 'seguranca',   'info',     'Ignição Desligada (ACC)',       'ACC Off',                0, 0);

-- ------------------------------------------------------------
-- 4. Conferência (roda no fim, aparece no log do deploy)
-- ------------------------------------------------------------
SELECT 'device_models (rastreadores)' AS conferencia;
SELECT `model_name`, `protocol`, `family`, `camera_count`
  FROM `device_models` WHERE `family` = 'tracker' ORDER BY `model_name`;

SELECT 'alarmes VL sem catalogo (deve vir vazio)' AS conferencia;
SELECT c.code
  FROM (SELECT '0' code UNION SELECT '19' UNION SELECT '50' UNION SELECT '60'
        UNION SELECT '61' UNION SELECT '62' UNION SELECT '75' UNION SELECT '76'
        UNION SELECT '77' UNION SELECT '78' UNION SELECT '79' UNION SELECT '83'
        UNION SELECT '94' UNION SELECT '255') c
  LEFT JOIN `alarm_types` a ON a.alarm_code = c.code AND a.protocol = 'JIMI'
 WHERE a.id IS NULL;
