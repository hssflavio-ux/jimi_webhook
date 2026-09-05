-- ============================================================================
-- bycamera — migration v4.17.5
-- update_device_stats_after_gps comparava com NULL e descartava a posição
--
-- Medido em produção em 05/09/2026 no JM-VL02 (`863282040221152`, instalado
-- 04/09 19:22): o equipamento estava LIGADO, transmitindo, com 34 posições
-- boas em `gps_data` (a última a 3 segundos do `server_time`, coordenada
-- `-19.905073 / -44.028040`), e `device_statistics` com
-- `last_gps_time`/`last_latitude`/`last_longitude` **NULL**. O
-- `total_gps_count` subia normalmente — 22 → 34 em meia hora — enquanto a
-- posição era descartada a cada push.
--
-- 🔴 A causa é a comparação com NULL nesta procedure. Ela é a ÚNICA das quatro
-- `update_device_stats_after_*` que não protege a coluna com `COALESCE`:
--
--     last_gps_time = IF(p_gps_time >  last_gps_time, p_gps_time, last_gps_time),
--     last_latitude = IF(p_gps_time >= last_gps_time, p_lat,      last_latitude),
--
-- Com `last_gps_time` NULL, `'2026-09-05 00:55:59' >= NULL` é **NULL**, e
-- `IF(NULL, …)` cai no ramo *else* — mantém o valor antigo, que é NULL. Como
-- `last_gps_time` também nunca chega a ser gravado, a condição jamais vira
-- verdadeira: a linha fica travada em NULL **para sempre**, e nenhuma posição
-- futura a destrava. Conferido no próprio MySQL de produção: os dois ramos
-- devolvem o valor antigo.
--
-- **Como a linha nasce com `last_gps_time` NULL:** as outras três procedures
-- (`_heartbeat`, `_event`, `_alarm`) inserem a linha em `device_statistics`
-- sem essa coluna. Basta o equipamento mandar heartbeat ou evento ANTES da
-- primeira posição — que é o caso normal de equipamento ligado antes de fixar
-- satélite. No VL02 foram **4h16 de diferença**: evento às 19:36:02, heartbeat
-- às 19:37:17, primeira posição só às 23:52:07. Quando o GPS enfim chegou, já
-- era `ON DUPLICATE KEY UPDATE`, e o `IF` contra NULL o descartou.
--
-- ⚠️ **Por que só ele apareceu, e por que a linha VL é a mais exposta:**
-- `update_device_stats_after_alarm` grava `last_latitude`/`last_longitude`
-- **incondicionalmente** quando o alarme traz coordenada — é o conserto
-- acidental que mascarava o defeito na frota de câmeras. O JM-VL01 tem 5
-- alarmes e escapou por aí; o JM-VL02 tem **zero**, e ficou preso. Rastreador
-- não gera alarme DMS/ADAS, então é justamente a linha `JM-VL0x` que não tem o
-- conserto acidental — o defeito é antigo e geral, mas só ficou visível quando
-- entrou na frota um equipamento sem alarme.
--
-- **Sintoma na tela:** `/rastreamento` monta `has_pos` a partir de
-- `device_statistics` (não de `gps_data`), então a caixa de seleção do ativo
-- nasce `disabled` com o título "Sem posição conhecida — nada a exibir" — o
-- equipamento aparece na lista e não é selecionável. Junto disso,
-- `resolve_live_state()` devolve `offline` quando `last_gps_time` é NULL,
-- então ele ainda ordena no fim da lista com a bolinha cinza, enquanto o
-- contador On/Off do cabeçalho — que lê `devices.last_communication`, essa sim
-- atualizada — o conta como On. Um sintoma só, não dois.
--
-- 🔴 NÃO é problema de fuso. Medido nos 34 pontos do VL02: `gps_time` →
-- `server_time` fica entre **2 e 7 segundos** (média 5s), zero pontos fora de
-- 3000s. Se o equipamento mandasse BRT a diferença seria ~10800s. A cadeia dos
-- três carimbos bate: `gps_time 01:34:59 → gateway_time 01:35:00 →
-- server_time 01:35:02`. O corpo do webhook chega em GMT 0, como o resto da
-- frota.
-- ============================================================================

-- ============================================================
-- 1. A procedure, com COALESCE nas seis comparações
-- ============================================================
-- O `'2000-01-01'` é a mesma sentinela que `_heartbeat`, `_event` e `_alarm`
-- já usam — a intenção é "linha sem leitura anterior aceita a primeira que
-- chegar", e é exatamente o que faltava aqui.
--
-- ⚠️ As seis linhas levam COALESCE, não só a primeira. O MySQL avalia as
-- atribuições do `ON DUPLICATE KEY UPDATE` da esquerda para a direita, e as
-- seguintes já enxergam o valor NOVO de `last_gps_time` — então só a primeira
-- seria estritamente necessária. Mas isso torna a correção dependente da ORDEM
-- das linhas: reordenar o bloco (ou mover `last_gps_time` para baixo numa
-- edição futura) reintroduz o defeito em silêncio. Protegidas as seis, cada
-- comparação é correta por si só.
DROP PROCEDURE IF EXISTS `update_device_stats_after_gps`;
DELIMITER //
CREATE PROCEDURE `update_device_stats_after_gps`(
    IN p_imei VARCHAR(20),
    IN p_gps_time DATETIME,
    IN p_lat DECIMAL(10,8),
    IN p_lon DECIMAL(11,8),
    IN p_speed DECIMAL(6,2),
    IN p_dist DECIMAL(10,2),
    IN p_gsm INT,
    IN p_acc TINYINT
)
BEGIN
    INSERT INTO device_statistics (
        imei, last_gps_time, last_latitude, last_longitude, last_speed,
        total_distance_km, gsm_signal, is_online, total_gps_count, last_acc_status, updated_at
    )
    VALUES (
        p_imei, p_gps_time, p_lat, p_lon, p_speed,
        COALESCE(p_dist, 0), p_gsm, 1, 1, p_acc, NOW()
    )
    ON DUPLICATE KEY UPDATE
        last_gps_time     = IF(p_gps_time >  COALESCE(last_gps_time, '2000-01-01'), p_gps_time, last_gps_time),
        last_latitude     = IF(p_gps_time >= COALESCE(last_gps_time, '2000-01-01'), p_lat,      last_latitude),
        last_longitude    = IF(p_gps_time >= COALESCE(last_gps_time, '2000-01-01'), p_lon,      last_longitude),
        last_speed        = IF(p_gps_time >= COALESCE(last_gps_time, '2000-01-01'), p_speed,    last_speed),
        last_acc_status   = IF(p_gps_time >= COALESCE(last_gps_time, '2000-01-01'), p_acc,      last_acc_status),
        gsm_signal        = IF(p_gps_time >= COALESCE(last_gps_time, '2000-01-01'), p_gsm,      gsm_signal),
        total_distance_km = total_distance_km + COALESCE(p_dist, 0),
        total_gps_count   = total_gps_count + 1,
        is_online         = 1,
        updated_at        = NOW();

    INSERT IGNORE INTO devices (imei, last_communication) VALUES (p_imei, NOW())
    ON DUPLICATE KEY UPDATE last_communication = NOW();
END//
DELIMITER ;

-- ============================================================
-- 2. Destravar as linhas que já ficaram presas
-- ============================================================
-- 🔴 Sem este passo a procedure corrigida NÃO conserta nada nas linhas que já
-- estão em NULL: o `IF` agora tem COALESCE e passaria a gravar, mas só no
-- próximo push — e o VL02 ficaria offline na tela até lá. Pior, uma linha em
-- que só a POSIÇÃO seja NULL (com `last_gps_time` preenchido por alarme) não
-- se conserta sozinha nunca. Restaurar a partir do último ponto real de
-- `gps_data` é o que devolve o equipamento à tela no mesmo instante.
--
-- Só toca linha comprovadamente quebrada (alguma das três colunas NULL) e só
-- quando existe ponto com coordenada de verdade — `latitude <> 0` porque
-- posição zerada é o "sem fix" do equipamento, não uma coordenada no Golfo da
-- Guiné.
UPDATE `device_statistics` ds
  JOIN (SELECT imei, MAX(gps_time) AS mx FROM `gps_data` GROUP BY imei) ult
    ON ult.imei = ds.imei
  JOIN `gps_data` g
    ON g.imei = ult.imei AND g.gps_time = ult.mx
   SET ds.last_gps_time   = g.gps_time,
       ds.last_latitude   = g.latitude,
       ds.last_longitude  = g.longitude,
       ds.last_speed      = COALESCE(g.speed, ds.last_speed),
       ds.last_acc_status = COALESCE(g.acc, ds.last_acc_status),
       ds.gsm_signal      = COALESCE(g.gsm_signal, ds.gsm_signal),
       ds.updated_at      = NOW()
 WHERE (ds.last_gps_time IS NULL OR ds.last_latitude IS NULL OR ds.last_longitude IS NULL)
   AND g.latitude IS NOT NULL AND g.longitude IS NOT NULL AND g.latitude <> 0;

-- ============================================================
-- 3. Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.17.5', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.17.5', last_update = NOW();

-- ============================================================
-- 4. Conferência
-- ============================================================
-- Esperado: NENHUMA linha. Equipamento ATIVO que tem ponto em `gps_data` e
-- continua sem posição em `device_statistics` significa que o passo 2 não
-- pegou — e é exatamente o estado em que o ativo some do mapa e a caixa de
-- seleção de `/rastreamento` nasce desabilitada.
SELECT 'ativos com GPS gravado e sem posicao em device_statistics (esperado: vazio)' AS conferencia;
SELECT d.imei, d.device_name, dm.model_name,
       s.last_gps_time, s.last_latitude, s.total_gps_count,
       (SELECT COUNT(*) FROM `gps_data` g WHERE g.imei = d.imei) AS pontos_gravados
  FROM `devices` d
  JOIN `device_statistics` s ON s.imei = d.imei
  LEFT JOIN `device_models` dm ON dm.id = d.device_model_id
 WHERE d.is_active = 1
   AND (s.last_gps_time IS NULL OR s.last_latitude IS NULL OR s.last_longitude IS NULL)
   AND EXISTS (SELECT 1 FROM `gps_data` g WHERE g.imei = d.imei);

-- Esperado: `COALESCE` presente. Uma regeneração da procedure a partir de doc
-- velha, ou um `mysql/jimi_tracker.sql` desatualizado aplicado por cima,
-- reintroduz o defeito — e ele volta em SILÊNCIO, porque a frota de câmeras é
-- consertada de acidente pelo caminho do alarme.
SELECT 'procedure protegida contra NULL (esperado: SIM)' AS conferencia;
SELECT IF(INSTR(ROUTINE_DEFINITION, 'COALESCE(last_gps_time') > 0, 'SIM', 'NAO — DEFEITO DE VOLTA') AS coalesce_presente
  FROM information_schema.ROUTINES
 WHERE ROUTINE_SCHEMA = DATABASE()
   AND ROUTINE_NAME = 'update_device_stats_after_gps';

SELECT 'Migracao v4.17.5 concluida' AS status;
