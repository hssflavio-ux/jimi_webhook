<?php
/**
 * JIMI Webhook System — Risk Builder v4.20.0
 * Script: scripts/risk_builder.php
 *
 * Cron (a cada 15 minutos) que alimenta o Mapa de Risco (/mapa-risco):
 *   risk_events     — um alerta ADAS/DMS por linha, com peso, célula, faixa do
 *                     dia, velocidade e direção contínua no instante do alerta
 *   risk_exposure   — horas em movimento e km, o denominador do índice
 *   risk_day_state  — a jornada contínua passando de um dia para o outro
 *
 * As regras são funções puras de includes/risk_map.php, testadas sem banco em
 * tests/helpers/risk_map.test.php. Este script só lê, chama e grava.
 *
 * Uso:
 *   php scripts/risk_builder.php                                   # incremental (cron)
 *   php scripts/risk_builder.php --desde=2026-06-10                # reprocessa até hoje
 *   php scripts/risk_builder.php --desde=2026-09-01 --ate=2026-09-10 --veiculo=12
 *
 * ── Por que a marca-d'água é o id ────────────────────────────────────────
 * 11% das posições e 11% dos alertas chegam horas ou dias depois do evento (a
 * câmera descarrega o que guardou sem sinal — máximo medido de 6,8 dias). Uma
 * marca pelo horário do evento perderia esses dados para sempre, que foi o
 * defeito do state_builder corrigido na v4.19.2. O id cresce na ordem de
 * chegada: cada rodada pega as linhas novas, descobre quais (veículo, dia BRT)
 * elas afetam e recalcula esses dias por inteiro.
 *
 * ── Virada do dia ─────────────────────────────────────────────────────────
 * A jornada de um dia depende de como o anterior terminou. Recalcular um dia
 * pode mudar o estado com que ele termina; enquanto mudar, os dias seguintes
 * com dado também são recalculados. A propagação para sozinha na primeira
 * pausa, onde o estado converge.
 *
 * ── Limitações conhecidas ─────────────────────────────────────────────────
 * - O dono (cliente/veículo) é resolvido na CHEGADA do dado
 *   (resolve_installation_for_imei). Posição reenviada depois de uma troca de
 *   câmera cai no veículo novo — a mesma limitação de todo o histórico.
 * - GPS anterior à v4.12.0 não tem vehicle_id e fica de fora.
 * - Estado de jornada com mais de RISK_CARRY_LOOKBACK_DAYS dias não é herdado:
 *   veículo parado há dias não "continua dirigindo" desde a última posição.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/functions.php';   // is_valid_coordinate()
require_once __DIR__ . '/../includes/risk_map.php';

const RISK_BUILDER = 'risk_builder';

/** Até quantos dias para trás a jornada de um dia pode ser herdada. */
const RISK_CARRY_LOOKBACK_DAYS = 3;

const RB_EVENT_COLS = [
    'alarm_id', 'customer_id', 'vehicle_id', 'driver_id', 'imei', 'protocol', 'alarm_code',
    'risk_group', 'risk_level', 'weight', 'false_positive', 'alarm_time', 'brt_date', 'brt_hour',
    'brt_weekday', 'day_period', 'latitude', 'longitude', 'cell_y', 'cell_x', 'speed', 'speed_band',
    'continuous_s', 'continuous_band',
];

const RB_EXPOSURE_COLS = [
    'customer_id', 'vehicle_id', 'brt_date', 'brt_hour', 'cell_y', 'cell_x', 'speed_band',
    'continuous_band', 'driver_id', 'driving_s', 'idle_s', 'km',
];

$opts        = getopt('', ['desde:', 'ate:', 'veiculo:']);
$desde       = isset($opts['desde']) ? (string)$opts['desde'] : null;
$ate         = isset($opts['ate']) ? (string)$opts['ate'] : null;
$onlyVehicle = isset($opts['veiculo']) ? (int)$opts['veiculo'] : null;

foreach (['desde' => $desde, 'ate' => $ate] as $nome => $valor) {
    if ($valor !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        fwrite(STDERR, "Risk Builder: --{$nome} deve ser AAAA-MM-DD.\n");
        exit(1);
    }
}
if ($desde === null && ($ate !== null || $onlyVehicle !== null)) {
    fwrite(STDERR, "Risk Builder: --ate e --veiculo só valem junto com --desde.\n");
    exit(1);
}

$db = Database::getInstance()->getConnection();

// Duas rodadas ao mesmo tempo apagariam e regravariam o mesmo veículo-dia em
// paralelo. A trava é da conexão e some sozinha se o processo morrer.
if ((int)$db->query("SELECT GET_LOCK('risk_builder', 0)")->fetchColumn() !== 1) {
    echo "Risk Builder: outra rodada em curso — nada a fazer.\n";
    exit(0);
}

try {
    foreach (['risk_events', 'risk_exposure', 'risk_day_state', 'worker_watermarks'] as $tabela) {
        $db->query("SELECT 1 FROM `$tabela` LIMIT 1");
    }
    $db->query("SELECT risk_group FROM alarm_types LIMIT 1");
} catch (Throwable $e) {
    fwrite(STDERR, "Risk Builder: tabelas indisponíveis — aplique as migrações v4.19.2 e v4.20.0.\n");
    Logger::error('Risk Builder: tabelas indisponíveis', ['error' => $e->getMessage()]);
    exit(1);
}

$runStart   = gmdate('Y-m-d H:i:s');
$today      = risk_brt_parts(time())['date'];
$maxAlarmId = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM alarms")->fetchColumn();
$maxGpsId   = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM gps_data")->fetchColumn();
$marks      = rb_load_marks($db);
$backfill   = $desde !== null;

// Catálogo ADAS/DMS por protocolo + código.
$catalog = [];
foreach ($db->query("SELECT protocol, alarm_code, alarm_name_pt, alarm_name_en, category, risk_group
                     FROM alarm_types WHERE category IN ('DMS', 'ADAS')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $catalog[$row['protocol'] . '|' . $row['alarm_code']] = $row;
}

$pairs      = [];
$semVeiculo = ['alarms' => 0, 'gps' => 0];

if ($backfill) {
    [$fromTs] = risk_brt_day_bounds($desde);
    [, $toTs] = risk_brt_day_bounds($ate ?? $today);
    rb_pairs_by_time($db, gmdate('Y-m-d H:i:s', $fromTs), gmdate('Y-m-d H:i:s', $toTs), $onlyVehicle, $pairs);
} else {
    if (!isset($marks['alarms'], $marks['gps_data'])) {
        // Primeira rodada: só grava as marcas. O histórico entra pelo --desde,
        // de propósito — um cron que vira backfill escondido pesa no deploy.
        rb_save_marks($db, $maxAlarmId, $maxGpsId, $runStart);
        echo "Risk Builder: primeira rodada — marcas gravadas; o histórico entra por --desde=AAAA-MM-DD.\n";
        exit(0);
    }
    rb_pairs_by_id($db, (int)$marks['alarms']['last_id'], $maxAlarmId,
        (int)$marks['gps_data']['last_id'], $maxGpsId, $pairs, $semVeiculo);
}

$ctx = [
    'configs'      => [],
    'params'       => [],
    'unclassified' => [],
    'events'       => 0,
    'exposure'     => 0,
];
$processed = 0;
$failures  = 0;

foreach ($pairs as $vehicleId => $dates) {
    while ($dates) {
        ksort($dates);
        $date = (string)array_key_first($dates);
        unset($dates[$date]);
        try {
            $changed = rb_process_day($db, (int)$vehicleId, $date, $catalog, $ctx);
            $processed++;
            if ($changed) {
                foreach (rb_data_days_after($db, (int)$vehicleId, $date, $today) as $next) {
                    $dates[$next] = true;
                }
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $failures++;
            Logger::error('Risk Builder: falha ao recalcular veículo-dia', [
                'vehicle_id' => $vehicleId,
                'dia'        => $date,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}

foreach ($ctx['unclassified'] as $tipo => $nome) {
    Logger::warning('Risk Builder: tipo ADAS/DMS sem risk_group — fica fora do mapa até ser classificado', [
        'tipo' => $tipo,
        'nome' => $nome,
    ]);
}

$fpUpdated = 0;
if (!$backfill) {
    $fpUpdated = rb_resync_false_positives($db, (string)($marks['occurrences']['last_time'] ?? $runStart));
}
$weightsUpdated = rb_resync_weights($db, $catalog, $ctx);

// Incremental: a marca só avança se todo veículo-dia fechou. Reprocessamento
// da frota inteira grava as marcas só quando ainda não existem, com os máximos
// fotografados ANTES da leitura — o que chegou durante ele fica para o cron.
if ($failures === 0) {
    if (!$backfill) {
        rb_save_marks($db, $maxAlarmId, $maxGpsId, $runStart);
    } elseif ($onlyVehicle === null && !isset($marks['alarms'], $marks['gps_data'])) {
        rb_save_marks($db, $maxAlarmId, $maxGpsId, $runStart);
    }
}

echo "Risk Builder: {$processed} veículo-dia(s) recalculado(s), {$ctx['events']} alerta(s), "
   . "{$ctx['exposure']} balde(s) de exposição, {$fpUpdated} falso(s) positivo(s) e "
   . "{$weightsUpdated} peso(s) ressincronizados; sem veículo: {$semVeiculo['alarms']} alerta(s), "
   . "{$semVeiculo['gps']} ponto(s); falhas: {$failures}.\n";

/**
 * Marcas-d'água deste script, por fonte.
 *
 * @param PDO $db
 * @returns array<string, array{last_id:string,last_time:?string}>
 */
function rb_load_marks(PDO $db): array
{
    $stmt = $db->prepare("SELECT source, last_id, last_time FROM worker_watermarks WHERE worker = :w");
    $stmt->execute([':w' => RISK_BUILDER]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['source']] = $row;
    }
    return $out;
}

/**
 * Grava as três marcas deste script.
 *
 * @param PDO    $db
 * @param int    $alarmId Maior alarms.id processado
 * @param int    $gpsId   Maior gps_data.id processado
 * @param string $occTime UTC a partir do qual occurrences.updated_at é relido
 * @returns void
 */
function rb_save_marks(PDO $db, int $alarmId, int $gpsId, string $occTime): void
{
    $stmt = $db->prepare("
        INSERT INTO worker_watermarks (worker, source, last_id, last_time)
        VALUES (:w, :s, :id, :t)
        ON DUPLICATE KEY UPDATE last_id = VALUES(last_id), last_time = VALUES(last_time)");
    foreach ([['alarms', $alarmId, null], ['gps_data', $gpsId, null], ['occurrences', 0, $occTime]] as [$source, $id, $time]) {
        $stmt->execute([':w' => RISK_BUILDER, ':s' => $source, ':id' => $id, ':t' => $time]);
    }
}

/**
 * Veículo-dias afetados pelo que chegou desde a última rodada.
 *
 * Qualquer alerta novo marca o dia (não só ADAS/DMS): filtrar aqui pediria o
 * mesmo par de joins do catálogo, e o dia quase sempre já vem marcado pelo GPS.
 *
 * @param PDO   $db
 * @param int   $lastAlarm  Última marca de alarms
 * @param int   $maxAlarm   Maior alarms.id desta rodada
 * @param int   $lastGps    Última marca de gps_data
 * @param int   $maxGps     Maior gps_data.id desta rodada
 * @param array $pairs      [vehicle_id => [dia => true]] (por referência)
 * @param array $semVeiculo Contadores de linhas sem veículo (por referência)
 * @returns void
 */
function rb_pairs_by_id(PDO $db, int $lastAlarm, int $maxAlarm, int $lastGps, int $maxGps, array &$pairs, array &$semVeiculo): void
{
    $fontes = [
        'alarms' => ['alarms', 'alarm_time', $lastAlarm, $maxAlarm],
        'gps'    => ['gps_data', 'gps_time', $lastGps, $maxGps],
    ];
    foreach ($fontes as $chave => [$tabela, $coluna, $last, $max]) {
        if ($max <= $last) {
            continue;
        }
        $stmt = $db->prepare("
            SELECT DISTINCT vehicle_id, DATE(CONVERT_TZ($coluna, '+00:00', '-03:00')) AS dia
            FROM $tabela
            WHERE id > :a AND id <= :b AND vehicle_id IS NOT NULL");
        $stmt->execute([':a' => $last, ':b' => $max]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pairs[(int)$row['vehicle_id']][$row['dia']] = true;
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM $tabela WHERE id > :a AND id <= :b AND vehicle_id IS NULL");
        $stmt->execute([':a' => $last, ':b' => $max]);
        $semVeiculo[$chave] = (int)$stmt->fetchColumn();
    }
}

/**
 * Veículo-dias com dado numa janela de tempo (reprocessamento).
 *
 * @param PDO      $db
 * @param string   $fromUtc   Início, inclusivo
 * @param string   $toUtc     Fim, exclusivo
 * @param int|null $vehicleId Só este veículo
 * @param array    $pairs     [vehicle_id => [dia => true]] (por referência)
 * @returns void
 */
function rb_pairs_by_time(PDO $db, string $fromUtc, string $toUtc, ?int $vehicleId, array &$pairs): void
{
    foreach (['gps_data' => 'gps_time', 'alarms' => 'alarm_time'] as $tabela => $coluna) {
        $sql = "SELECT DISTINCT vehicle_id, DATE(CONVERT_TZ($coluna, '+00:00', '-03:00')) AS dia
                FROM $tabela
                WHERE $coluna >= :f AND $coluna < :t AND vehicle_id IS NOT NULL";
        $params = [':f' => $fromUtc, ':t' => $toUtc];
        if ($vehicleId !== null) {
            $sql .= " AND vehicle_id = :v";
            $params[':v'] = $vehicleId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pairs[(int)$row['vehicle_id']][$row['dia']] = true;
        }
    }
}

/**
 * Dias com dado nos RISK_CARRY_LOOKBACK_DAYS seguintes a `$date`, até hoje —
 * os que herdam a jornada de `$date` e precisam ser recalculados quando ela muda.
 *
 * @param PDO    $db
 * @param int    $vehicleId
 * @param string $date  Dia BRT recalculado
 * @param string $today Hoje, BRT
 * @returns string[]
 */
function rb_data_days_after(PDO $db, int $vehicleId, string $date, string $today): array
{
    [, $endTs] = risk_brt_day_bounds($date);
    $from = gmdate('Y-m-d H:i:s', $endTs);
    $to   = gmdate('Y-m-d H:i:s', $endTs + RISK_CARRY_LOOKBACK_DAYS * 86400);
    $stmt = $db->prepare("
        SELECT DISTINCT DATE(CONVERT_TZ(gps_time, '+00:00', '-03:00')) AS dia
        FROM gps_data WHERE vehicle_id = :v AND gps_time >= :f AND gps_time < :t
        UNION
        SELECT DISTINCT DATE(CONVERT_TZ(alarm_time, '+00:00', '-03:00')) AS dia
        FROM alarms WHERE vehicle_id = :v2 AND alarm_time >= :f2 AND alarm_time < :t2");
    $stmt->execute([':v' => $vehicleId, ':f' => $from, ':t' => $to, ':v2' => $vehicleId, ':f2' => $from, ':t2' => $to]);
    return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), fn($d) => $d > $date && $d <= $today));
}

/**
 * Pontos de GPS de um veículo numa janela, já no formato de risk_walk_points().
 *
 * @param PDO $db
 * @param int $vehicleId
 * @param int $fromTs  Início, inclusivo (Unix)
 * @param int $toTs    Fim, exclusivo (Unix)
 * @param int $limit   Teto de linhas (0 = sem teto)
 * @returns array
 */
function rb_load_points(PDO $db, int $vehicleId, int $fromTs, int $toTs, int $limit = 0): array
{
    // v4.21.0 — ponto de equipamento SEM câmera (rastreador JM-VL) não entra na
    // exposição: o índice é ADAS/DMS por hora dirigida, e as horas de um
    // veículo que não tem como gerar alerta diluíam o risco da frota com câmera.
    $stmt = $db->prepare("
        SELECT g.gps_time, g.acc, g.speed, g.latitude, g.longitude, g.driver_id, g.customer_id
        FROM gps_data g
        WHERE g.vehicle_id = :v AND g.gps_time >= :f AND g.gps_time < :t
          AND NOT EXISTS (SELECT 1 FROM devices d
                            LEFT JOIN device_models dm ON dm.id = d.device_model_id
                           WHERE d.imei = g.imei AND NOT (" . device_has_camera_sql('d', 'dm') . "))
        ORDER BY g.gps_time, g.id" . ($limit > 0 ? " LIMIT $limit" : ''));
    $stmt->execute([':v' => $vehicleId, ':f' => gmdate('Y-m-d H:i:s', $fromTs), ':t' => gmdate('Y-m-d H:i:s', $toTs)]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Mesma regra do state_builder: (0,0) ou fora de faixa não diz nada sobre posição.
        if (!is_valid_coordinate($row['latitude'], $row['longitude'])) {
            continue;
        }
        $out[] = [
            't'           => risk_utc_ts($row['gps_time']),
            'acc'         => empty($row['acc']) ? 0 : 1,
            'speed'       => (float)($row['speed'] ?? 0),
            'lat'         => (float)$row['latitude'],
            'lng'         => (float)$row['longitude'],
            'driver_raw'  => $row['driver_id'],
            'driver_id'   => 0,
            'customer_id' => (int)($row['customer_id'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Estado da jornada herdado por `$date`: o do último dia calculado dentro do
 * limite ou, sem nenhum (primeiro reprocessamento), o resultado de percorrer os
 * dias anteriores sem gravar nada.
 *
 * @param PDO    $db
 * @param int    $vehicleId
 * @param string $date Dia BRT
 * @returns array carry de risk_walk_points()
 */
function rb_seed_carry(PDO $db, int $vehicleId, string $date): array
{
    $stmt = $db->prepare("
        SELECT carry_continuous_s, carry_off_since, last_point_time, last_acc
        FROM risk_day_state
        WHERE vehicle_id = :v AND brt_date < :d AND brt_date >= :min
        ORDER BY brt_date DESC LIMIT 1");
    $stmt->execute([':v' => $vehicleId, ':d' => $date, ':min' => rb_add_days($date, -RISK_CARRY_LOOKBACK_DAYS)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return rb_state_to_carry($row);
    }

    [$dayStart] = risk_brt_day_bounds($date);
    $points = rb_load_points($db, $vehicleId, $dayStart - RISK_CARRY_LOOKBACK_DAYS * 86400, $dayStart);
    return risk_walk_points($points, rb_state_to_carry(null), [], $dayStart)['carry'];
}

/**
 * Recalcula um veículo num dia BRT: apaga e regrava alertas e exposição, e
 * grava o estado com que a jornada termina.
 *
 * @param PDO    $db
 * @param int    $vehicleId
 * @param string $date    Dia BRT
 * @param array  $catalog Catálogo ADAS/DMS por protocolo|código
 * @param array  $ctx     Caches e contadores (por referência)
 * @returns bool true se o estado de fim do dia mudou (os dias seguintes herdam)
 * @throws Throwable Erro de banco — a transação é desfeita pelo chamador
 */
function rb_process_day(PDO $db, int $vehicleId, string $date, array $catalog, array &$ctx): bool
{
    [$startTs, $endTs] = risk_brt_day_bounds($date);
    $carry  = rb_seed_carry($db, $vehicleId, $date);
    $points = rb_load_points($db, $vehicleId, $startTs, $endTs);
    $after  = rb_load_points($db, $vehicleId, $endTs, $endTs + 7 * 86400, 5)[0] ?? null;

    $stmt = $db->prepare("
        SELECT id, imei, customer_id, driver_id, alarm_type, alarm_subtype, msg_class, alarm_name,
               alarm_time, latitude, longitude, speed, car_speed, status
        FROM alarms
        WHERE vehicle_id = :v AND alarm_time >= :s AND alarm_time < :e AND customer_id IS NOT NULL
        ORDER BY alarm_time, id");
    $stmt->execute([':v' => $vehicleId, ':s' => gmdate('Y-m-d H:i:s', $startTs), ':e' => gmdate('Y-m-d H:i:s', $endTs)]);

    $counted = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        // O fim de um alarme é gravado como linha própria, com o código base
        // (pushalarm.php, removeAlarmType): contaria o mesmo alerta duas vezes.
        if ($a['status'] === 'resolved' || strncmp((string)$a['alarm_name'], 'Fim de Alarme:', 14) === 0) {
            continue;
        }
        $cat = rb_catalog_row($catalog, $a);
        if ($cat === null) {
            continue;   // não é ADAS/DMS
        }
        if ($cat['risk_group'] === null) {
            $ctx['unclassified'][$cat['protocol'] . '|' . $cat['alarm_code']] = $cat['alarm_name_pt'];
            continue;
        }
        if ($cat['risk_group'] === RISK_GROUP_EXCLUDED) {
            continue;
        }
        $a['_cat'] = $cat;
        $counted[] = $a;
    }

    $drivers = rb_driver_map($db, array_merge(array_column($points, 'driver_raw'), array_column($counted, 'driver_id')));
    foreach ($points as &$p) {
        $p['driver_id'] = rb_driver_for($drivers, $p['driver_raw'], $p['customer_id']);
    }
    unset($p);

    $alertTimes = [];
    foreach ($counted as $a) {
        $alertTimes[] = ['id' => (int)$a['id'], 't' => risk_utc_ts($a['alarm_time'])];
    }
    $walk = risk_walk_points($points, $carry, $alertTimes, $endTs, $after);
    $falsePositive = rb_false_positive_set($db, array_column($alertTimes, 'id'));

    $events = [];
    foreach ($counted as $a) {
        $id     = (int)$a['id'];
        $cat    = $a['_cat'];
        $parts  = risk_brt_parts(risk_utc_ts($a['alarm_time']));
        $level  = rb_level_for($db, (int)$a['customer_id'], $cat, $ctx);
        $valid  = is_valid_coordinate($a['latitude'], $a['longitude']);
        [$cy, $cx] = $valid ? risk_cell((float)$a['latitude'], (float)$a['longitude']) : [null, null];
        $speed  = rb_alarm_speed($a);
        $cont   = $walk['alert_continuous'][$id] ?? null;
        $driver = rb_driver_for($drivers, $a['driver_id'], (int)$a['customer_id']);

        $events[] = [
            'alarm_id'        => $id,
            'customer_id'     => (int)$a['customer_id'],
            'vehicle_id'      => $vehicleId,
            'driver_id'       => $driver > 0 ? $driver : null,
            'imei'            => $a['imei'],
            'protocol'        => $cat['protocol'],
            'alarm_code'      => $cat['alarm_code'],
            'risk_group'      => $cat['risk_group'],
            'risk_level'      => $level,
            'weight'          => risk_weight($level),
            'false_positive'  => isset($falsePositive[$id]) ? 1 : 0,
            'alarm_time'      => $a['alarm_time'],
            'brt_date'        => $parts['date'],
            'brt_hour'        => $parts['hour'],
            'brt_weekday'     => $parts['weekday'],
            'day_period'      => risk_day_period($parts['hour']),
            'latitude'        => $valid ? $a['latitude'] : null,
            'longitude'       => $valid ? $a['longitude'] : null,
            'cell_y'          => $cy,
            'cell_x'          => $cx,
            'speed'           => $speed,
            'speed_band'      => risk_speed_band($speed),
            'continuous_s'    => $cont,
            'continuous_band' => risk_continuous_band($cont),
        ];
    }

    $exposure = [];
    foreach ($walk['exposure'] as $row) {
        $exposure[] = $row + ['vehicle_id' => $vehicleId];
    }

    $db->beginTransaction();
    $db->prepare("DELETE FROM risk_events WHERE vehicle_id = :v AND brt_date = :d")
       ->execute([':v' => $vehicleId, ':d' => $date]);
    $db->prepare("DELETE FROM risk_exposure WHERE vehicle_id = :v AND brt_date = :d")
       ->execute([':v' => $vehicleId, ':d' => $date]);
    // REPLACE: o alerta é chave primária; se um cálculo anterior o gravou sob
    // outra data (relógio do equipamento corrigido), a linha é substituída.
    $ctx['events']   += rb_insert_many($db, 'risk_events', RB_EVENT_COLS, $events, 'REPLACE');
    $ctx['exposure'] += rb_insert_many($db, 'risk_exposure', RB_EXPOSURE_COLS, $exposure);

    // Dia sem ponto não tem estado próprio: os seguintes herdam do último que teve.
    $changed = false;
    if ($points) {
        $stmt = $db->prepare("
            SELECT carry_continuous_s, carry_off_since, last_point_time, last_acc
            FROM risk_day_state WHERE vehicle_id = :v AND brt_date = :d");
        $stmt->execute([':v' => $vehicleId, ':d' => $date]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $state    = rb_carry_to_state($walk['carry']);
        $changed  = $previous === null || rb_carry_to_state(rb_state_to_carry($previous)) !== $state;

        $db->prepare("
            INSERT INTO risk_day_state (vehicle_id, brt_date, carry_continuous_s, carry_off_since, last_point_time, last_acc)
            VALUES (:v, :d, :c, :o, :l, :a)
            ON DUPLICATE KEY UPDATE carry_continuous_s = VALUES(carry_continuous_s),
                carry_off_since = VALUES(carry_off_since), last_point_time = VALUES(last_point_time),
                last_acc = VALUES(last_acc)")
           ->execute([
               ':v' => $vehicleId, ':d' => $date,
               ':c' => $state['carry_continuous_s'], ':o' => $state['carry_off_since'],
               ':l' => $state['last_point_time'], ':a' => $state['last_acc'],
           ]);
    }
    $db->commit();

    return $changed;
}

/**
 * Linha do catálogo de um alerta. JT/T com subtipo casa pelo código composto
 * (265-2); JIMI e JT/T sem subtipo, pelo base — o mesmo par de joins de
 * alarm_label_sql() (no JIMI o subtipo guarda o canal de vídeo, não o tipo).
 *
 * @param array $catalog
 * @param array $alarm Linha de alarms
 * @returns array|null
 */
function rb_catalog_row(array $catalog, array $alarm): ?array
{
    $protocol = (int)$alarm['msg_class'] === 1 ? 'JTT' : 'JIMI';
    $subtype  = $alarm['alarm_subtype'];
    if ($protocol === 'JTT' && $subtype !== null && $subtype !== '') {
        $row = $catalog["JTT|{$alarm['alarm_type']}-{$subtype}"] ?? null;
        if ($row !== null) {
            return $row;
        }
    }
    return $catalog["{$protocol}|{$alarm['alarm_type']}"] ?? null;
}

/**
 * Velocidade no alerta. O JT/T tem duas colunas (`speed` do GPS e `car_speed`
 * do veículo) e nem sempre preenche as duas: vale a que tiver valor.
 *
 * @param array $alarm
 * @returns float|null
 */
function rb_alarm_speed(array $alarm): ?float
{
    if ($alarm['speed'] !== null && (float)$alarm['speed'] > 0) {
        return (float)$alarm['speed'];
    }
    if ($alarm['car_speed'] !== null) {
        return (float)$alarm['car_speed'];
    }
    return $alarm['speed'] !== null ? (float)$alarm['speed'] : null;
}

/**
 * Motoristas cadastrados entre os identificadores recebidos: id → cliente.
 *
 * @param PDO   $db
 * @param array $raw Valores de driver_id de gps_data/alarms
 * @returns array<int,int>
 */
function rb_driver_map(PDO $db, array $raw): array
{
    $ids = [];
    foreach ($raw as $value) {
        $s = trim((string)$value);
        if ($s !== '' && $s !== '0' && ctype_digit($s)) {
            $ids[(int)$s] = true;
        }
    }
    if (!$ids) {
        return [];
    }
    $in  = implode(',', array_keys($ids));   // inteiros vindos de ctype_digit
    $out = [];
    foreach ($db->query("SELECT id, customer_id FROM drivers WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['id']] = (int)$row['customer_id'];
    }
    return $out;
}

/**
 * Motorista de uma linha, validado contra o cadastro E contra o cliente.
 *
 * Alarme gravado antes da v4.19.0 guarda o identificador CRU da câmera ("4"),
 * que pode coincidir com o drivers.id de outra pessoa. Exigir o mesmo cliente
 * impede ao menos que o motorista de outro tenant apareça no mapa.
 *
 * @param array    $drivers    rb_driver_map()
 * @param mixed    $raw        driver_id da linha
 * @param int      $customerId Cliente da linha
 * @returns int drivers.id, ou 0 quando não identificado
 */
function rb_driver_for(array $drivers, $raw, int $customerId): int
{
    $s = trim((string)$raw);
    if ($s === '' || !ctype_digit($s)) {
        return 0;
    }
    $id = (int)$s;
    return (isset($drivers[$id]) && $drivers[$id] === $customerId) ? $id : 0;
}

/**
 * Alertas cuja ocorrência foi marcada como falso positivo.
 *
 * `occurrence_events.alarm_id` não é único: basta UMA ocorrência marcada.
 *
 * @param PDO   $db
 * @param int[] $alarmIds
 * @returns array<int,true>
 */
function rb_false_positive_set(PDO $db, array $alarmIds): array
{
    $out = [];
    foreach (array_chunk(array_map('intval', $alarmIds), 500) as $chunk) {
        $in = implode(',', $chunk);
        foreach ($db->query("
            SELECT DISTINCT oe.alarm_id FROM occurrence_events oe
            JOIN occurrences o ON o.id = oe.occurrence_id
            WHERE o.false_positive = 1 AND oe.alarm_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int)$id] = true;
        }
    }
    return $out;
}

/**
 * Nível de risco de um tipo no perfil de ocorrências ATUAL do cliente.
 *
 * O perfil é o do cliente gravado no ALERTA. get_occurrence_config_for_imei()
 * não serve: lê o dono atual da câmera e reescreveria o histórico de uma
 * câmera que trocou de cliente.
 *
 * @param PDO   $db
 * @param int   $customerId
 * @param array $cat Linha do catálogo
 * @param array $ctx Caches (por referência)
 * @returns string baixo|medio|alto
 */
function rb_level_for(PDO $db, int $customerId, array $cat, array &$ctx): string
{
    if (!array_key_exists($customerId, $ctx['configs'])) {
        $stmt = $db->prepare("
            SELECT COALESCE(c.occurrence_config_id,
                            (SELECT id FROM occurrence_configs WHERE is_default = 1 LIMIT 1))
            FROM customers c WHERE c.id = :c");
        $stmt->execute([':c' => $customerId]);
        $config = $stmt->fetchColumn();
        $ctx['configs'][$customerId] = $config !== false && $config !== null ? (int)$config : null;
    }
    $configId = $ctx['configs'][$customerId];
    if ($configId === null) {
        return RISK_DEFAULT_LEVEL;
    }
    if (!isset($ctx['params'][$configId])) {
        $stmt = $db->prepare("SELECT alarm_type, risk FROM occurrence_config_params WHERE config_id = :c");
        $stmt->execute([':c' => $configId]);
        $ctx['params'][$configId] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $code = (string)$cat['alarm_code'];
    return risk_resolve_level($ctx['params'][$configId], [
        'alarm_name_pt' => $cat['alarm_name_pt'],
        'alarm_name_en' => $cat['alarm_name_en'],
        'alarm_code'    => $code,
        'base_code'     => strpos($code, '-') !== false ? strstr($code, '-', true) : $code,
        'category'      => $cat['category'],
    ]);
}

/**
 * Relê o falso positivo dos alertas cujas ocorrências mudaram desde a última
 * rodada — marcar falso positivo não cria linha nova, só atualiza a ocorrência.
 *
 * @param PDO    $db
 * @param string $sinceUtc
 * @returns int Linhas de risk_events alteradas
 */
function rb_resync_false_positives(PDO $db, string $sinceUtc): int
{
    $stmt = $db->prepare("
        SELECT DISTINCT oe.alarm_id FROM occurrences o
        JOIN occurrence_events oe ON oe.occurrence_id = o.id
        WHERE o.updated_at >= :s");
    $stmt->execute([':s' => $sinceUtc]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $changed = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $fp   = array_keys(rb_false_positive_set($db, $chunk));
        $inFp = $fp ? implode(',', $fp) : '0';
        $in   = implode(',', $chunk);
        $changed += (int)$db->exec("UPDATE risk_events SET false_positive = IF(alarm_id IN ($inFp), 1, 0) WHERE alarm_id IN ($in)");
    }
    return $changed;
}

/**
 * Acompanha edições do perfil de ocorrências: recalcula o nível de cada
 * (cliente, tipo) presente no mapa e grava só onde mudou.
 *
 * @param PDO   $db
 * @param array $catalog
 * @param array $ctx Caches (por referência)
 * @returns int Linhas de risk_events alteradas
 */
function rb_resync_weights(PDO $db, array $catalog, array &$ctx): int
{
    // Cache novo: o perfil pode ter mudado durante a rodada.
    $ctx['configs'] = [];
    $ctx['params']  = [];

    $update = $db->prepare("
        UPDATE risk_events SET risk_level = :lvl, weight = :w
        WHERE customer_id = :c AND protocol = :p AND alarm_code = :code AND risk_level <> :lvl2");
    $changed = 0;
    foreach ($db->query("SELECT DISTINCT customer_id, protocol, alarm_code FROM risk_events")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cat = $catalog[$row['protocol'] . '|' . $row['alarm_code']] ?? null;
        if ($cat === null) {
            continue;
        }
        $level = rb_level_for($db, (int)$row['customer_id'], $cat, $ctx);
        $update->execute([
            ':lvl' => $level, ':w' => risk_weight($level), ':c' => (int)$row['customer_id'],
            ':p' => $row['protocol'], ':code' => $row['alarm_code'], ':lvl2' => $level,
        ]);
        $changed += $update->rowCount();
    }
    return $changed;
}

/**
 * Insere linhas em lotes.
 *
 * @param PDO      $db
 * @param string   $table
 * @param string[] $cols
 * @param array    $rows
 * @param string   $verb INSERT|REPLACE
 * @returns int Linhas enviadas
 */
function rb_insert_many(PDO $db, string $table, array $cols, array $rows, string $verb = 'INSERT'): int
{
    $sent = 0;
    $tuple = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    foreach (array_chunk($rows, 300) as $chunk) {
        $values = [];
        foreach ($chunk as $row) {
            foreach ($cols as $col) {
                $values[] = $row[$col];
            }
        }
        $db->prepare("$verb INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES "
            . implode(',', array_fill(0, count($chunk), $tuple)))->execute($values);
        $sent += count($chunk);
    }
    return $sent;
}

/**
 * @param string $date 'Y-m-d'
 * @param int    $days
 * @returns string
 */
function rb_add_days(string $date, int $days): string
{
    return gmdate('Y-m-d', (int)strtotime($date . ' 00:00:00 UTC') + $days * 86400);
}

/**
 * Linha de risk_day_state → estado de risk_walk_points().
 *
 * @param array|null $row
 * @returns array
 */
function rb_state_to_carry(?array $row): array
{
    if ($row === null) {
        return ['continuous_s' => null, 'off_since' => null, 'last_t' => null, 'last_acc' => null];
    }
    return [
        'continuous_s' => $row['carry_continuous_s'] !== null ? (int)$row['carry_continuous_s'] : null,
        'off_since'    => $row['carry_off_since'] !== null ? risk_utc_ts($row['carry_off_since']) : null,
        'last_t'       => $row['last_point_time'] !== null ? risk_utc_ts($row['last_point_time']) : null,
        'last_acc'     => $row['last_acc'] !== null ? (int)$row['last_acc'] : null,
    ];
}

/**
 * Estado de risk_walk_points() → colunas de risk_day_state.
 *
 * @param array $carry
 * @returns array
 */
function rb_carry_to_state(array $carry): array
{
    return [
        'carry_continuous_s' => $carry['continuous_s'],
        'carry_off_since'    => $carry['off_since'] !== null ? gmdate('Y-m-d H:i:s', $carry['off_since']) : null,
        'last_point_time'    => $carry['last_t'] !== null ? gmdate('Y-m-d H:i:s', $carry['last_t']) : null,
        'last_acc'           => $carry['last_acc'],
    ];
}
