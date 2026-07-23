<?php
/**
 * JIMI Webhook System — Trip Builder v4.0.0
 * Script: scripts/trip_builder.php
 *
 * Cron job que segmenta gps_data em viagens (trips) por ignição (lig→desl)
 * para cada device. Cruza com alarms da janela para contagem.
 *
 * Uso: php scripts/trip_builder.php
 */

require_once __DIR__ . '/../config/database.php';

// Filtro de qualidade: uma viagem só conta como deslocamento real se houve
// movimento efetivo. Descarta viagens de 1 ponto, paradas com ignição ligada
// e deriva de GPS. Velocidade é o sinal primário (deriva nunca passa de ~1-2
// km/h); a distância é um escape para devices que não reportam speed.
const MIN_TRIP_MAX_SPEED   = 6.0; // km/h
const MIN_TRIP_DISTANCE_KM = 1.0; // km
const MIN_TRIP_DURATION_S  = 60;  // s — descarta "viagens" de poucos segundos (ruído)

// Segmentação por parada sustentada. Muitos devices NÃO reportam acc=desligado
// entre um deslocamento e outro (mantêm a ignição/voltagem ligada o dia todo);
// sem isto, uma jornada inteira colapsaria numa única "viagem" de 24h. Uma
// parada com velocidade abaixo de STOP_SPEED_KMH por mais de STOP_IDLE_SECONDS
// encerra a viagem no último ponto em movimento — o próximo movimento abre uma
// viagem nova. Assim a segmentação não depende só do sinal de ignição.
const STOP_SPEED_KMH    = 3.0; // km/h — abaixo disto o veículo é considerado parado
const STOP_IDLE_SECONDS = 300; // s (5 min) — parada mais longa que isto encerra a viagem

$db = Database::getInstance()->getConnection();

// Limite de "frescor": uma viagem que ficou aberta (sem acc=desligado no fim
// do lote) só é finalizada se o último ponto já é mais velho que isto (2h).
// Se for recente, a viagem provavelmente ainda está em curso — não persistimos
// agora para não fragmentá-la; os pontos são reavaliados no próximo cron.
$staleBefore = date('Y-m-d H:i:s', strtotime('-2 hours'));

// Lookback inicial para device SEM viagens ainda: 1 dia no cron (comportamento
// padrão, incremental daí em diante). Para backfill histórico, passe a qtd de
// dias como argumento: `php scripts/trip_builder.php 30`.
$initialLookbackDays = (isset($argv[1]) && (int)$argv[1] > 0) ? (int)$argv[1] : 1;

$devices = $db->query("
    SELECT d.imei, d.customer_id
    FROM devices d
    WHERE d.is_active = 1
    ORDER BY d.imei
")->fetchAll();

$tripsCreated = 0;

foreach ($devices as $dev) {
    $imei = $dev['imei'];
    $customerId = $dev['customer_id'];

    $lastTrip = $db->prepare("
        SELECT MAX(ended_at) as last_end FROM trips WHERE imei = :imei
    ");
    $lastTrip->execute([':imei' => $imei]);
    $lastEnd = $lastTrip->fetchColumn();

    $from = $lastEnd
        ? date('Y-m-d H:i:s', strtotime($lastEnd))
        : date('Y-m-d H:i:s', strtotime("-{$initialLookbackDays} days"));

    // A ignição fica na coluna `acc` de gps_data (pushgps grava acc/accStatus).
    // Aliasamos para `ignition` para manter a lógica de detecção legível.
    $points = $db->prepare("
        SELECT id, latitude, longitude, speed, gps_time, acc AS ignition
        FROM gps_data
        WHERE imei = :imei AND gps_time > :from
        ORDER BY gps_time ASC
    ");
    $points->execute([':imei' => $imei, ':from' => $from]);
    $points = $points->fetchAll();

    if (count($points) < 2) continue;

    $trip = null;
    // Índice, dentro de $trip['points'], do último ponto em que o veículo
    // estava em movimento. A viagem sempre termina nele (a cauda parada é
    // descartada), seja ao desligar a ignição ou ao detectar parada sustentada.
    $lastMovingIdx = 0;

    foreach ($points as $p) {
        $ignitionOn = !empty($p['ignition']);
        $moving = (float)$p['speed'] > STOP_SPEED_KMH;

        // Ignição desligada encerra a viagem no último ponto em movimento.
        if (!$ignitionOn) {
            if ($trip) {
                $tripsCreated += finalizeTrip($db, $trip, $lastMovingIdx);
                $trip = null;
            }
            continue;
        }

        // Ignição ligada, sem viagem aberta: só abre quando há movimento real
        // (evita iniciar viagem em veículo parado com ignição ligada / deriva).
        if (!$trip) {
            if ($moving) {
                $trip = [
                    'imei'        => $imei,
                    'customer_id' => $customerId,
                    'points'      => [$p],
                ];
                $lastMovingIdx = 0;
            }
            continue;
        }

        // Buraco de dados: se o intervalo desde o último ponto da viagem passou
        // de STOP_IDLE_SECONDS, o device ficou offline/parado sem reportar — não
        // dá para afirmar que houve deslocamento contínuo. Encerra a viagem no
        // último ponto em movimento; o ponto atual reinicia o ciclo (uma viagem
        // nunca atravessa um período de silêncio do rastreador).
        $prev = $trip['points'][count($trip['points']) - 1];
        if (strtotime($p['gps_time']) - strtotime($prev['gps_time']) >= STOP_IDLE_SECONDS) {
            $tripsCreated += finalizeTrip($db, $trip, $lastMovingIdx);
            $trip = null;
            if ($moving) {
                $trip = ['imei' => $imei, 'customer_id' => $customerId, 'points' => [$p]];
                $lastMovingIdx = 0;
            }
            continue;
        }

        // Viagem aberta: acumula o ponto.
        $trip['points'][] = $p;
        $idx = count($trip['points']) - 1;

        if ($moving) {
            $lastMovingIdx = $idx;
        } else {
            // Parada: mede há quanto tempo o veículo não se move. Se passou de
            // STOP_IDLE_SECONDS, encerra a viagem (no último ponto em movimento)
            // — o próximo movimento abrirá uma viagem nova.
            $idleSecs = strtotime($p['gps_time']) - strtotime($trip['points'][$lastMovingIdx]['gps_time']);
            if ($idleSecs >= STOP_IDLE_SECONDS) {
                $tripsCreated += finalizeTrip($db, $trip, $lastMovingIdx);
                $trip = null;
            }
        }
    }

    // Viagem ainda aberta ao fim dos pontos (sem acc=desligado nem parada longa):
    // só finaliza se o último ponto em movimento já está velho (<= $staleBefore);
    // do contrário deixa em aberto p/ o próximo cron — evita fragmentar uma
    // viagem em curso.
    if ($trip && $trip['points'][$lastMovingIdx]['gps_time'] <= $staleBefore) {
        $tripsCreated += finalizeTrip($db, $trip, $lastMovingIdx);
    }
}

echo "Trip Builder: $tripsCreated viagens criadas.\n";

/**
 * Fecha uma viagem no último ponto em movimento (descarta a cauda parada),
 * calcula os agregados (duração, distância, vel. máx., alarmes) e persiste se
 * passar no filtro de qualidade (isRealTrip). Centraliza o fechamento usado
 * tanto por ignição-desligada quanto por parada sustentada e fim de lote.
 *
 * @param PDO   $db
 * @param array $trip    Viagem aberta (com 'imei', 'customer_id', 'points')
 * @param int   $endIdx  Índice do último ponto em movimento (fim da viagem)
 * @return int 1 se a viagem foi persistida, 0 caso contrário
 */
function finalizeTrip($db, array $trip, int $endIdx): int {
    // Recorta a viagem até o último ponto em movimento — pontos parados no fim
    // (semáforo, veículo aguardando desligado) não fazem parte do deslocamento.
    $pts = array_slice($trip['points'], 0, $endIdx + 1);
    if (count($pts) < 2) return 0;

    $first = $pts[0];
    $last  = $pts[count($pts) - 1];
    $maxSpeed = 0.0;
    foreach ($pts as $p) {
        if ((float)$p['speed'] > $maxSpeed) $maxSpeed = (float)$p['speed'];
    }

    $trip['started_at']  = $first['gps_time'];
    $trip['start_lat']   = $first['latitude'];
    $trip['start_lng']   = $first['longitude'];
    $trip['ended_at']    = $last['gps_time'];
    $trip['end_lat']     = $last['latitude'];
    $trip['end_lng']     = $last['longitude'];
    $trip['duration_s']  = strtotime($trip['ended_at']) - strtotime($trip['started_at']);
    $trip['max_speed']   = $maxSpeed;
    $trip['distance_km'] = calcDistance($pts);
    $trip['points']      = $pts;
    $trip['alarm_count'] = countAlarms($db, $trip['imei'], $trip['started_at'], $trip['ended_at']);

    if (!isRealTrip($trip)) return 0;
    saveTrip($db, $trip);
    return 1;
}

/**
 * Uma viagem só é um "deslocamento" real se teve movimento efetivo: pelo menos
 * 2 pontos, duração mínima E (velocidade máxima acima do ruído de GPS parado OU
 * distância mínima percorrida). Filtra viagens de 1 ponto, slivers de poucos
 * segundos, paradas com ignição ligada (ex.: veículo estacionado a noite toda
 * com ACC on) e deriva de GPS.
 *
 * @param array $trip Viagem finalizada (com duration_s, max_speed, distance_km, points)
 * @return bool true se deve ser persistida
 */
function isRealTrip(array $trip): bool {
    if (count($trip['points']) < 2) return false;
    if ((int)$trip['duration_s'] < MIN_TRIP_DURATION_S) return false;
    return (float)$trip['max_speed'] >= MIN_TRIP_MAX_SPEED
        || (float)$trip['distance_km'] >= MIN_TRIP_DISTANCE_KM;
}

function calcDistance(array $points): float {
    $dist = 0;
    for ($i = 1; $i < count($points); $i++) {
        $dist += haversine(
            (float)$points[$i-1]['latitude'], (float)$points[$i-1]['longitude'],
            (float)$points[$i]['latitude'],   (float)$points[$i]['longitude']
        );
    }
    return round($dist, 2);
}

function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earth = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)*sin($dLng/2);
    return $earth * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function countAlarms($db, string $imei, string $from, string $to): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM alarms WHERE imei = :imei AND alarm_time BETWEEN :fr AND :to");
    $stmt->execute([':imei' => $imei, ':fr' => $from, ':to' => $to]);
    return (int)$stmt->fetchColumn();
}

function saveTrip($db, array $trip): void {
    $stmt = $db->prepare("
        INSERT INTO trips (customer_id, imei, started_at, start_lat, start_lng,
            ended_at, end_lat, end_lng, duration_s, max_speed, distance_km, alarm_count)
        VALUES (:cid, :imei, :st, :sla, :slg, :et, :ela, :elg, :dur, :ms, :dist, :ac)
    ");
    $stmt->execute([
        ':cid'  => $trip['customer_id'],
        ':imei' => $trip['imei'],
        ':st'   => $trip['started_at'],
        ':sla'  => $trip['start_lat'],
        ':slg'  => $trip['start_lng'],
        ':et'   => $trip['ended_at'],
        ':ela'  => $trip['end_lat'],
        ':elg'  => $trip['end_lng'],
        ':dur'  => $trip['duration_s'],
        ':ms'   => $trip['max_speed'],
        ':dist' => $trip['distance_km'],
        ':ac'   => $trip['alarm_count'],
    ]);
}
