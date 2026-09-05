-- ============================================================================
-- bycamera — migration v4.17.8
-- A ignição exibida vinha SÓ do GPS, e atrasava horas atrás do heartbeat
--
-- Medido em produção em 05/09/2026: `heartbeats.acc` está **100% preenchido**
-- (6806 de 6806 heartbeats de 2 dias — 2468 ON, 4338 OFF), mas
-- `update_device_stats_after_heartbeat` não recebe nem grava `acc`.
-- `device_statistics.last_acc_status` só era atualizado por GPS, então a
-- ignição mostrada nas telas ficava com a idade do último PONTO, não a da
-- última leitura:
--
--     400D             IGN: OFF   ult. comunicacao 02:10   ult. GPS 19:49   382 min
--     Device E2E Test  IGN: ON    ...                                       176 min
--     371_3241         IGN: ON    ...                                        59 min
--
-- Seis horas de atraso com o valor correto disponível o tempo todo, chegando
-- a cada heartbeat e sendo descartado na porta. Não havia sintoma: o número
-- existe, é plausível, e só está velho.
--
-- 🔴 Isso importa mais a partir da v4.17.6/v4.17.7, que passaram a exibir
-- `IGN: ON/OFF` na linha de cada ativo em `/rastreamento` — e mais ainda com a
-- decisão do dono do produto de que **a bolinha da lista é o status da
-- IGNIÇÃO, não o da comunicação**. Sinal que vai virar a cor da linha não pode
-- ser o de seis horas atrás.
--
-- ⚠️ `last_acc_status` passa a ser "a leitura de ignição MAIS RECENTE, de
-- qualquer fonte" — e é por isso que as duas procedures comparam contra o
-- `GREATEST` das duas marcas, não cada uma contra a sua. Sem isso as duas
-- disputariam a coluna: um heartbeat atrasado sobrescreveria um GPS novo, e o
-- valor ficaria oscilando entre o certo e o velho a cada push, o que é pior
-- que atrasar de forma estável.
-- ============================================================================

-- ============================================================
-- 1. Heartbeat passa a carregar a ignição
-- ============================================================
-- 🔴 `last_acc_status` é atribuído ANTES de `last_heartbeat_time`, de
-- propósito. O MySQL avalia o `ON DUPLICATE KEY UPDATE` da esquerda para a
-- direita e as linhas seguintes já enxergam os valores NOVOS: se
-- `last_heartbeat_time` fosse atribuído primeiro, o `GREATEST` abaixo leria o
-- próprio `p_hb_time` e a comparação viraria `p_hb_time >= p_hb_time`, sempre
-- verdadeira — a proteção contra heartbeat FORA DE ORDEM sumiria em silêncio.
-- Mesma armadilha de ordem documentada na v4.17.5.
--
-- ⚠️ `p_acc IS NOT NULL` é a outra guarda: o campo é opcional no payload
-- (`$item['acc'] ?? null` em pushhb.php) e um heartbeat sem ele não pode
-- apagar uma leitura boa.
DROP PROCEDURE IF EXISTS `update_device_stats_after_heartbeat`;
DELIMITER //
CREATE PROCEDURE `update_device_stats_after_heartbeat`(
    IN p_imei VARCHAR(20),
    IN p_hb_time DATETIME,
    IN p_bat INT,
    IN p_gsm INT,
    IN p_acc TINYINT
)
BEGIN
    INSERT INTO device_statistics (
        imei, last_heartbeat_time, battery_level, gsm_signal, last_acc_status, is_online, updated_at
    )
    VALUES (p_imei, p_hb_time, p_bat, p_gsm, p_acc, 1, NOW())
    ON DUPLICATE KEY UPDATE
        last_acc_status     = IF(p_acc IS NOT NULL
                                 AND p_hb_time >= GREATEST(COALESCE(last_gps_time, '2000-01-01'),
                                                           COALESCE(last_heartbeat_time, '2000-01-01')),
                                 p_acc, last_acc_status),
        last_heartbeat_time = IF(p_hb_time > COALESCE(last_heartbeat_time, '2000-01-01'), p_hb_time, last_heartbeat_time),
        battery_level       = COALESCE(p_bat, battery_level),
        gsm_signal          = COALESCE(p_gsm, gsm_signal),
        is_online           = 1,
        updated_at          = NOW();

    INSERT IGNORE INTO devices (imei, last_communication) VALUES (p_imei, NOW())
    ON DUPLICATE KEY UPDATE last_communication = NOW();
END//
DELIMITER ;

-- ============================================================
-- 2. GPS deixa de sobrescrever uma leitura de heartbeat mais nova
-- ============================================================
-- O lado espelho do passo 1. Só `last_acc_status` muda de critério (passa a
-- comparar contra o GREATEST das duas marcas); as outras cinco colunas
-- continuam exatamente como a v4.17.5 as deixou, COALESCE incluído — elas são
-- de posição, e posição só vem por GPS.
--
-- ⚠️ Aqui a ordem funciona a favor: `last_gps_time` é atribuído antes, então o
-- `GREATEST` já lê o valor novo e a comparação vira "este ponto é pelo menos
-- tão novo quanto o último heartbeat?", que é exatamente a pergunta certa.
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
        last_acc_status   = IF(p_acc IS NOT NULL
                               AND p_gps_time >= GREATEST(COALESCE(last_gps_time, '2000-01-01'),
                                                          COALESCE(last_heartbeat_time, '2000-01-01')),
                               p_acc, last_acc_status),
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
-- 3. Alcançar o atraso que já existe
-- ============================================================
-- Sem este passo, os equipamentos cujo GPS está velho ficam com a ignição
-- velha até o PRÓXIMO heartbeat — o que é rápido, mas deixa a tela mentindo
-- no intervalo, e a tela é justamente o motivo desta migração. Traz o `acc`
-- do heartbeat mais recente de cada equipamento, quando ele for mais novo que
-- a última posição.
UPDATE `device_statistics` ds
  JOIN (SELECT imei, MAX(heartbeat_time) AS mx FROM `heartbeats` GROUP BY imei) u
    ON u.imei = ds.imei
  JOIN `heartbeats` h
    ON h.imei = u.imei AND h.heartbeat_time = u.mx
   SET ds.last_acc_status = h.acc,
       ds.updated_at      = NOW()
 WHERE h.acc IS NOT NULL
   AND h.heartbeat_time > COALESCE(ds.last_gps_time, '2000-01-01');

-- ============================================================
-- 4. Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.17.8', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.17.8', last_update = NOW();

-- ============================================================
-- 5. Conferência
-- ============================================================
-- A idade da ignição passa a ser a da última COMUNICAÇÃO, não a da última
-- posição. Esperado: nenhum ativo com ignição mais velha que o último
-- heartbeat que trouxe `acc`.
-- ⚠️ `JOIN`, não subconsulta correlacionada: equipamento SEM heartbeat com
-- `acc` não tem com o que ser comparado, e a primeira versão desta conferência
-- o acusava como divergente — o subselect voltava NULL e `1 <=> NULL` é 0.
-- Pego rodando a migração no banco de desenvolvimento, que tem ZERO
-- heartbeats: dois falsos positivos. Ausência de dado não é divergência.
-- E `NOT (a <=> b)` em vez de `a <=> b = 0`, que depende da precedência entre
-- `<=>` e `=` para significar o que se quer.
SELECT 'ativos com ignicao mais velha que o ultimo heartbeat com acc (esperado: vazio)' AS conferencia;
SELECT d.device_name, ds.last_gps_time, hb.heartbeat_time AS hb_com_acc,
       ds.last_acc_status, hb.acc AS acc_do_ultimo_hb
  FROM `devices` d
  JOIN `device_statistics` ds ON ds.imei = d.imei
  JOIN (SELECT h.imei, h.acc, h.heartbeat_time
          FROM `heartbeats` h
          JOIN (SELECT imei, MAX(heartbeat_time) AS mx FROM `heartbeats`
                 WHERE acc IS NOT NULL GROUP BY imei) m
            ON m.imei = h.imei AND m.mx = h.heartbeat_time
         WHERE h.acc IS NOT NULL) hb ON hb.imei = d.imei
 WHERE d.is_active = 1
   AND hb.heartbeat_time > COALESCE(ds.last_gps_time, '2000-01-01')
   AND NOT (ds.last_acc_status <=> hb.acc);

SELECT 'as duas procedures leem acc do GREATEST das duas marcas (esperado: 2)' AS conferencia;
SELECT COUNT(*) AS procedures_ok
  FROM information_schema.ROUTINES
 WHERE ROUTINE_SCHEMA = DATABASE()
   AND ROUTINE_NAME IN ('update_device_stats_after_gps', 'update_device_stats_after_heartbeat')
   AND INSTR(ROUTINE_DEFINITION, 'GREATEST(COALESCE(last_gps_time') > 0;

SELECT 'Migracao v4.17.8 concluida' AS status;
