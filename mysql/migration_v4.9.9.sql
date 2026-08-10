-- ============================================================================
-- JIMI Webhook System — Migração v4.9.9
--
-- Separa EVENTO DE DIAGNÓSTICO de ALARME. Diagnóstico é o que o equipamento
-- diz ao SISTEMA — handshake de upload, sono/despertar, defeito de hardware —
-- e não o que o veículo diz ao OPERADOR. Some das telas de alarme e ocorrência;
-- a linha continua inteira em `alarms`, visível ao administrador.
--
-- POR QUE ISTO É NECESSÁRIO, medido no homolog em 10/08/2026:
--
--     Falha no Armazenamento .................... 3.508
--     Fim de Alarme: Falha no Armazenamento ....... 540
--     Fim de Alarme: Perda de Sinal de Vídeo ...... 305
--     Evento de Modo Trabalho / Repouso ........... 342
--     Perda de Sinal de Vídeo / Falha de Câmera ... 376
--     Upload de Vídeo Concluído ..................... 2
--     ─────────────────────────────────────────────────
--     DMS + ADAS (o núcleo do produto) .............. 16   de 5.112
--
-- O relatório de alarmes, o gráfico do Resumo e o "top 3 equipamentos" eram
-- 99,7% ruído de infraestrutura.
--
-- ⚠️ A CLASSIFICAÇÃO É POR CÓDIGO, NUNCA POR NOME. Duas razões:
--   1. `alarms.alarm_name` é congelado na chegada e existe em variantes —
--      `Fim de Alarme: Falha no Armazenamento` são 540 linhas com o MESMO
--      código 259 do alarme de abertura. Filtrar por código pega as duas de
--      graça; por nome exigiria conhecer cada variante.
--   2. É a armadilha que o CLAUDE.md documenta três vezes: renomear
--      `alarm_name_pt` desliga em silêncio qualquer junção por nome.
-- ============================================================================

-- ── 1. A coluna ─────────────────────────────────────────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'alarm_types'
              AND column_name = 'is_diagnostic');
SET @sql := IF(@c = 0,
    'ALTER TABLE `alarm_types` ADD COLUMN `is_diagnostic` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = evento tecnico (nao aparece em alarmes/ocorrencias; visivel so ao admin)''',
    'SELECT ''is_diagnostic ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. `Falha de Câmera` não existia no catálogo ────────────────────────────
--
-- Ela chega como JT/T 256 (o bitmask de alarme padrão) com `alarm_subtype`
-- 2048 — o bit 11, que `decodeStandardAlarm()` já traduz em pushalarm.php.
-- Sem linha no catálogo, `alarm_label_sql()` não consegue re-resolver o nome e
-- nada podia classificá-la. São 188 linhas hoje.
--
-- 🔴 MARCAR A BASE `256` SERIA PERIGOSO e é o motivo de a linha ser composta:
-- o mesmo código 256 carrega TODO o bitmask padrão do JT/T 808, incluindo
-- `Emergência / SOS` (bit 0) e `Excesso de Velocidade` (bit 1). E o decode
-- COMBINA os bits ativos num só nome (`implode(' + ', …)`), então um evento com
-- câmera + SOS chega como subtype 2049 — código composto DIFERENTE, que segue
-- visível. Esconder só o valor exato 2048 é a única forma segura: qualquer
-- combinação que inclua um alarme de verdade continua aparecendo.
INSERT INTO `alarm_types` (alarm_code, protocol, alarm_name_pt, alarm_name_en, category, severity, is_diagnostic)
SELECT '256-2048', 'JTT', 'Falha de Câmera', 'Camera Fault', 'dispositivo', 'critical', 1
 WHERE NOT EXISTS (SELECT 1 FROM `alarm_types` WHERE alarm_code = '256-2048' AND protocol = 'JTT');

-- ── 3. Classificação ────────────────────────────────────────────────────────
--
-- Handshake e ciclo de vida do equipamento — ninguém age sobre eles:
--   JIMI 105  Upload de Vídeo Concluído   (sinaliza que o vídeo subiu)
--   JTT 1040  Evento de Modo Repouso
--   JTT 1041  Evento de Modo Trabalho
--
-- Defeito de hardware — alguém PRECISA agir, mas é manutenção, não operação.
-- Foi decisão de produto tirá-los da tela do operador (10/08/2026); eles
-- continuam no relatório em modo diagnóstico, que é onde a manutenção olha:
--   JTT 257   Perda de Sinal de Vídeo      (+ os "Fim de Alarme:" do mesmo código)
--   JTT 259   Falha no Armazenamento       (+ idem)
--   JTT 256-2048  Falha de Câmera
UPDATE `alarm_types`
   SET is_diagnostic = 1
 WHERE (protocol = 'JIMI' AND alarm_code = '105')
    OR (protocol = 'JTT'  AND alarm_code IN ('1040', '1041', '257', '259', '256-2048'));

-- ── 4. Conferência: o que passou a ser diagnóstico ──────────────────────────
SELECT protocol, alarm_code, alarm_name_pt, category, severity
  FROM `alarm_types`
 WHERE is_diagnostic = 1
 ORDER BY protocol, alarm_code;

-- ── 5. Conferência: quantas linhas de `alarms` somem da tela ────────────────
-- Casa pelo mesmo par de JOINs de alarm_label_sql() — composto primeiro, base
-- depois —, que é exatamente o que as telas passam a usar.
SELECT COALESCE(atc.alarm_name_pt, atb.alarm_name_pt, a.alarm_name) AS evento,
       COUNT(*) AS linhas_ocultadas
  FROM `alarms` a
  LEFT JOIN `alarm_types` atc ON a.msg_class = 1 AND a.alarm_subtype IS NOT NULL
                             AND atc.protocol = 'JTT'
                             AND atc.alarm_code = CONCAT(a.alarm_type, '-', a.alarm_subtype)
  LEFT JOIN `alarm_types` atb ON atb.protocol = IF(a.msg_class = 1, 'JTT', 'JIMI')
                             AND atb.alarm_code = a.alarm_type
 WHERE COALESCE(atc.is_diagnostic, atb.is_diagnostic, 0) = 1
 GROUP BY evento
 ORDER BY linhas_ocultadas DESC;

-- ── 6. Conferência: o que SOBRA para o operador ─────────────────────────────
-- Tem de conter os DMS/ADAS, o SOS e a ignição. Se algum sumir daqui, a
-- classificação passou do ponto.
SELECT COALESCE(atc.alarm_name_pt, atb.alarm_name_pt, a.alarm_name) AS evento,
       COUNT(*) AS linhas_visiveis
  FROM `alarms` a
  LEFT JOIN `alarm_types` atc ON a.msg_class = 1 AND a.alarm_subtype IS NOT NULL
                             AND atc.protocol = 'JTT'
                             AND atc.alarm_code = CONCAT(a.alarm_type, '-', a.alarm_subtype)
  LEFT JOIN `alarm_types` atb ON atb.protocol = IF(a.msg_class = 1, 'JTT', 'JIMI')
                             AND atb.alarm_code = a.alarm_type
 WHERE COALESCE(atc.is_diagnostic, atb.is_diagnostic, 0) = 0
 GROUP BY evento
 ORDER BY linhas_visiveis DESC;

-- ── 7. Conferência: nenhum DMS/ADAS pode ter virado diagnóstico ─────────────
-- Deve devolver ZERO linhas. É a trava contra classificar o núcleo do produto.
SELECT alarm_code, alarm_name_pt, category
  FROM `alarm_types`
 WHERE is_diagnostic = 1
   AND category IN ('DMS', 'ADAS', 'emergencia', 'acidente');

-- ── 8. Conferência: diagnóstico com parâmetro de ocorrência ─────────────────
-- Deve devolver ZERO linhas. Um evento técnico que gera ocorrência é
-- contradição — o motor tem guarda em código, mas o cadastro não deveria
-- deixar chegar lá.
SELECT ocp.id, ocp.alarm_type, ocp.generates_occurrence
  FROM `occurrence_config_params` ocp
  JOIN `alarm_types` at ON at.alarm_name_pt = ocp.alarm_type
 WHERE at.is_diagnostic = 1
   AND ocp.generates_occurrence = 1;

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.9', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.9', last_update = NOW();

SELECT 'Migracao v4.9.9 concluida' AS status;
