-- ============================================================================
-- JIMI Webhook System — Migração v4.9.15
--
-- Nomeia 16 dos 17 parâmetros que estavam como `Parâmetro N` e oculta o único
-- que continua sem identificação.
--
-- 🔑 A DESCOBERTA QUE DESTRAVOU ISTO: **os números do device são os IDs padrão
-- do JT/T 808 em DECIMAL, e a norma os publica em HEXADECIMAL.** A tabela da
-- Jimi (`2.3.9.1`) é um recorte parcial do padrão — o que ela omite não é
-- desconhecido, é só documentado em outro lugar.
--
-- Não é palpite, e a prova estava dentro do próprio catálogo: `23` já constava
-- como `Servidor de Backup` e `132` como `Cor da Placa`, ambos vindos da tabela
-- DOCUMENTADA da Jimi — e são exatamente `0x0017` e `0x0084` da norma. Se a
-- numeração da Jimi coincide com a da norma nos parâmetros que ela publica, ela
-- coincide também nos que ela omite.
--
-- Os valores medidos em campo fecham a conta, um a um:
--   `16`=`cmnet` → 0x0010 APN;  `19`=`189.22.240.43` → 0x0013 servidor;
--   `24`=`21122` → 0x0018 porta TCP;  `48`=`45` → 0x0030 (ângulo, <180° pela
--   norma);  `131`=`""` + `132`=`0` → 0x0083 placa vazia e 0x0084 cor 0, que é
--   o que a norma manda preencher para veículo sem placa.
--
-- E `20`/`21`/`22` vazios deixam de parecer defeito: são o APN do servidor de
-- BACKUP, coerentes com o `23` (backup) também vazio.
--
-- ⚠️ `100` (0x0064) CONTINUA SEM FONTE e por isso continua `Parâmetro 100`.
-- Não batizar por palpite é a regra que evitou o erro no alarme `1047`; ela
-- vale aqui igual. Ele apenas sai da tela (`is_hidden`), a pedido do dono do
-- produto — mas a linha continua no banco e a tela informa quantos estão
-- ocultos, para que "sumiu da tela" nunca vire "não existe".
--
-- ⚠️ MUDANÇA DE DECISÃO DE PRODUTO: a §7.1 do PROJETO_PARAMETROS.md dizia
-- "parâmetro sem catálogo aparece com o valor cru — visível, não escondido",
-- espelhando o `Código NNNN (JTT)` dos alarmes. O dono decidiu o contrário em
-- 13/08/2026: dado que ninguém sabe ler não ajuda a operar. O contrapeso é o
-- rodapé com a contagem — o custo de esconder é virar invisível, e é esse custo
-- que o rodapé paga.
--
-- GRAVABILIDADE — nada aqui vira gravável por inércia:
--   • `2`–`7` (timeouts e retransmissões do protocolo) ficam SOMENTE LEITURA.
--     São ajuste fino de transporte; errar aqui degrada a comunicação de um
--     jeito que não aparece na tela, só na ausência de dado.
--   • `82`/`83` são BITMASK. Enquanto a tela não tiver seletor de bits,
--     escrever por caixa de texto livre é convite a erro — somente leitura.
--   • `128` (hodômetro) fica somente leitura: é LEITURA do veículo, não ajuste.
--     Escrevê-lo corrompe relatório de distância silenciosamente.
--   • `129`/`130` são província/município CHINESES — sem uso aqui.
--   • `20`/`21`/`22` (APN de backup) ficam graváveis COM `is_network=1`: são
--     rede, mas ao contrário do servidor principal, errá-los não derruba a
--     câmera (o principal continua valendo). A trava de confirmação vale igual.
-- ============================================================================

-- ⚠️ DUAS ARMADILHAS ENCONTRADAS AO APLICAR ESTA MIGRAÇÃO, ambas registradas
-- aqui porque a segunda já tinha mordido o projeto antes:
--
--   1. `doc_ref` era VARCHAR(24) e `JT/T 808 0x0006 (simetria)` tem 26 — o
--      MySQL aborta com `1406 Data too long`. Alargada abaixo.
--
--   2. 🔴 O filtro ÓBVIO — `name_pt LIKE 'Parâmetro %'` — casa com
--      **`Parâmetro de Colisão`** (nº 93), que é DOCUMENTADO e GRAVÁVEL.
--      Ocultá-lo tiraria da tela um parâmetro de segurança em uso, e a
--      conferência de "gravável sem nome" acusaria erro onde não há.
--      O STATUS.md já registrava esse mesmo falso positivo na F1, e a
--      armadilha foi pisada de novo aqui. A regra correta é
--      `REGEXP '^Parâmetro [0-9]+$'`: "Parâmetro" seguido SÓ de dígitos.

-- ── 1. Coluna de ocultação e largura do doc_ref ─────────────────────────────
-- Coluna explícita em vez de deduzir por rótulo: regra por substring quebra no
-- dia em que alguém traduzir o texto — e, como o nº 93 mostrou, já quebra hoje.
ALTER TABLE `device_param_catalog` MODIFY `doc_ref` VARCHAR(40) NULL;
SET @tem := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'device_param_catalog'
                AND column_name = 'is_hidden');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `device_param_catalog` ADD COLUMN `is_hidden` TINYINT(1) NOT NULL DEFAULT 0
     COMMENT "1 = nao exibir na tela (parametro ainda sem identificacao)"',
  'SELECT "coluna is_hidden ja existe" AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Rede: timeouts e retransmissões (0x0002–0x0007) ──────────────────────
UPDATE `device_param_catalog` SET
  name_pt = 'Timeout de Resposta TCP', name_en = 'TCP response timeout',
  unit = 's', value_kind = 'int', grupo = 'rede', writable = 0,
  doc_ref = 'JT/T 808 0x0002' WHERE param_no = 2;
UPDATE `device_param_catalog` SET
  name_pt = 'Retransmissões TCP', name_en = 'TCP retransmission times',
  unit = 'vezes', value_kind = 'int', grupo = 'rede', writable = 0,
  doc_ref = 'JT/T 808 0x0003' WHERE param_no = 3;
UPDATE `device_param_catalog` SET
  name_pt = 'Timeout de Resposta UDP', name_en = 'UDP response timeout',
  unit = 's', value_kind = 'int', grupo = 'rede', writable = 0,
  doc_ref = 'JT/T 808 0x0004' WHERE param_no = 4;
UPDATE `device_param_catalog` SET
  name_pt = 'Retransmissões UDP', name_en = 'UDP retransmission times',
  unit = 'vezes', value_kind = 'int', grupo = 'rede', writable = 0,
  doc_ref = 'JT/T 808 0x0005' WHERE param_no = 5;
-- 0x0006 é o único do bloco sem fonte direta encontrada; entra pela SIMETRIA
-- do par timeout/retransmissão que 0x0002–0x0005 e 0x0007 estabelecem. O
-- doc_ref registra isso, para ninguém o tratar como confirmado.
UPDATE `device_param_catalog` SET
  name_pt = 'Timeout de Resposta SMS', name_en = 'SMS response timeout',
  unit = 's', value_kind = 'int', grupo = 'rede', writable = 0,
  doc_ref = 'JT/T 808 0x0006 (simetria)' WHERE param_no = 6;
UPDATE `device_param_catalog` SET
  name_pt = 'Retransmissões SMS', name_en = 'SMS retransmission times',
  unit = 'vezes', value_kind = 'int', grupo = 'rede', writable = 0,
  doc_ref = 'JT/T 808 0x0007' WHERE param_no = 7;

-- ── 3. Servidor de backup (0x0014–0x0016) ───────────────────────────────────
UPDATE `device_param_catalog` SET
  name_pt = 'APN do Servidor de Backup', name_en = 'Backup server APN',
  unit = NULL, value_kind = 'text', grupo = 'rede', writable = 1, is_network = 1,
  doc_ref = 'JT/T 808 0x0014' WHERE param_no = 20;
UPDATE `device_param_catalog` SET
  name_pt = 'Usuário do APN de Backup', name_en = 'Backup server dial username',
  unit = NULL, value_kind = 'text', grupo = 'rede', writable = 1, is_secret = 1,
  is_network = 1, doc_ref = 'JT/T 808 0x0015' WHERE param_no = 21;
UPDATE `device_param_catalog` SET
  name_pt = 'Senha do APN de Backup', name_en = 'Backup server dial password',
  unit = NULL, value_kind = 'text', grupo = 'rede', writable = 1, is_secret = 1,
  is_network = 1, doc_ref = 'JT/T 808 0x0016' WHERE param_no = 22;

-- ── 4. Reporte por distância em repouso (0x002E) ────────────────────────────
-- Completa o bloco 0x002C–0x002F, cujos outros três (44, 45, 47) já estavam
-- catalogados como distância em metros. O valor medido (300) é coerente.
UPDATE `device_param_catalog` SET
  name_pt = 'Distância em Repouso', name_en = 'Sleep distance reporting interval',
  unit = 'm', value_kind = 'int', grupo = 'reporte', writable = 1,
  doc_ref = 'JT/T 808 0x002E' WHERE param_no = 46;

-- ── 5. Foto no alarme (0x0052–0x0053) ───────────────────────────────────────
UPDATE `device_param_catalog` SET
  name_pt = 'Fotografar ao Alarmar', name_en = 'Alarm shooting switch',
  unit = NULL, value_kind = 'bitmask', grupo = 'seguranca', writable = 0,
  doc_ref = 'JT/T 808 0x0052' WHERE param_no = 82;
UPDATE `device_param_catalog` SET
  name_pt = 'Foto do Alarme: Armazenar', name_en = 'Alarm photo storage flag',
  unit = NULL, value_kind = 'bitmask', grupo = 'seguranca', writable = 0,
  doc_ref = 'JT/T 808 0x0053' WHERE param_no = 83;

-- ── 6. Identificação do veículo (0x0080–0x0083) ─────────────────────────────
-- ⚠️ O hodômetro vem em DÉCIMOS DE QUILÔMETRO pela norma. O JC181 devolveu
-- `15`, que são **1,5 km** — exibir o valor cru diria "15" e ninguém
-- desconfiaria. A unidade aqui é o que faz a tela formatar certo.
UPDATE `device_param_catalog` SET
  name_pt = 'Hodômetro', name_en = 'Vehicle odometer reading',
  unit = '1/10 km', value_kind = 'int', grupo = 'identificacao', writable = 0,
  doc_ref = 'JT/T 808 0x0080' WHERE param_no = 128;
UPDATE `device_param_catalog` SET
  name_pt = 'ID da Província (China)', name_en = 'Province ID',
  unit = NULL, value_kind = 'int', grupo = 'identificacao', writable = 0,
  doc_ref = 'JT/T 808 0x0081' WHERE param_no = 129;
UPDATE `device_param_catalog` SET
  name_pt = 'ID do Município (China)', name_en = 'City ID',
  unit = NULL, value_kind = 'int', grupo = 'identificacao', writable = 0,
  doc_ref = 'JT/T 808 0x0082' WHERE param_no = 130;
UPDATE `device_param_catalog` SET
  name_pt = 'Placa do Veículo', name_en = 'License plate number',
  unit = NULL, value_kind = 'text', grupo = 'identificacao', writable = 1,
  doc_ref = 'JT/T 808 0x0083' WHERE param_no = 131;

-- ── 7. O que continua sem identificação sai da tela ─────────────────────────
UPDATE `device_param_catalog`
   SET is_hidden = 1
 WHERE name_pt REGEXP '^Parâmetro [0-9]+$';

-- E o que JÁ foi identificado volta a aparecer, se estava oculto — torna a
-- migração reexecutável sem deixar resíduo.
UPDATE `device_param_catalog`
   SET is_hidden = 0
 WHERE name_pt NOT REGEXP '^Parâmetro [0-9]+$';

-- ── 8. Conferência ──────────────────────────────────────────────────────────
SELECT 'ainda sem nome (devem ficar ocultos)' AS conferencia,
       GROUP_CONCAT(param_no ORDER BY CAST(param_no AS UNSIGNED)) AS params,
       COUNT(*) AS total
  FROM `device_param_catalog` WHERE name_pt REGEXP '^Parâmetro [0-9]+$';

SELECT 'nomeados nesta migracao' AS conferencia, COUNT(*) AS total
  FROM `device_param_catalog` WHERE doc_ref LIKE 'JT/T 808 0x%';

-- Nenhum parâmetro pode ficar gravável sem nome — é a trava da v4.9.14, aqui
-- conferida de novo porque esta migração mexeu em `writable`.
SELECT 'GRAVAVEL SEM NOME (tem de vir vazio)' AS conferencia,
       GROUP_CONCAT(param_no) AS params
  FROM `device_param_catalog` WHERE writable = 1 AND name_pt REGEXP '^Parâmetro [0-9]+$';

SELECT grupo, COUNT(*) AS params, SUM(is_hidden) AS ocultos
  FROM `device_param_catalog` GROUP BY grupo ORDER BY params DESC;

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.15', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.15', last_update = NOW();

SELECT 'Migracao v4.9.15 concluida' AS status;
