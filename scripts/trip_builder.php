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

$db = Database::getInstance()->getConnection();

// Limite de "frescor": uma viagem que ficou aberta (sem acc=desligado no fim
// do lote) só é finalizada se o último ponto já é mais velho que isto (2h).
// Se for recente, a viagem provavelmente ainda está em curso — não persistimos
// agora para não fragmentá-la; os pontos são reavaliados no próximo cron.
$staleBefore = date('Y-m-d H:i:s', strtotime('-2 hours'));

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

    $from = $lastEnd ? date('Y-m-d H:i:s', strtotime($lastEnd)) : date('Y-m-d H:i:s', strtotime('-24 hours'));

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

    foreach ($points as $p) {
        $ignitionOn = !empty($p['ignition']);

        if ($ignitionOn && !$trip) {
            $trip = [
                'imei'       => $imei,
                'customer_id' => $customerId,
                'started_at' => $p['gps_time'],
                'start_lat'  => $p['latitude'],
                'start_lng'  => $p['longitude'],
                'max_speed'  => (float)$p['speed'],
                'distance_km' => 0,
                'points'     => [$p],
            ];
        } elseif ($ignitionOn && $trip) {
            $trip['points'][] = $p;
            if ((float)$p['speed'] > $trip['max_speed']) {
                $trip['max_speed'] = (float)$p['speed'];
            }
        } elseif (!$ignitionOn && $trip) {
            $lastPoint = end($trip['points']);
            $trip['ended_at'] = $p['gps_time'];
            $trip['end_lat'] = $p['latitude'];
            $trip['end_lng'] = $p['longitude'];
            $trip['duration_s'] = strtotime($trip['ended_at']) - strtotime($trip['started_at']);
            $trip['distance_km'] = calcDistance($trip['points']);
            $trip['alarm_count'] = countAlarms($db, $imei, $trip['started_at'], $trip['ended_at']);

            if (isRealTrip($trip)) {
                saveTrip($db, $trip);
                $tripsCreated++;
            }
            $trip = null;
        }
    }

    // Viagem ainda aberta ao fim dos pontos (sem acc=desligado): só finaliza se
    // o último ponto já está velho (<= $staleBefore); do contrário deixa em
    // aberto p/ o próximo cron — evita fragmentar uma viagem em curso.
    if ($trip && count($trip['points']) >= 2) {
        $lastPoint = end($trip['points']);
        if ($lastPoint['gps_time'] <= $staleBefore) {
            $trip['ended_at'] = $lastPoint['gps_time'];
            $trip['end_lat'] = $lastPoint['latitude'];
            $trip['end_lng'] = $lastPoint['longitude'];
            $trip['duration_s'] = strtotime($trip['ended_at']) - strtotime($trip['started_at']);
            $trip['distance_km'] = calcDistance($trip['points']);
            $trip['alarm_count'] = countAlarms($db, $imei, $trip['started_at'], $trip['ended_at']);
            if (isRealTrip($trip)) {
                saveTrip($db, $trip);
                $tripsCreated++;
            }
        }
    }
}

echo "Trip Builder: $tripsCreated viagens criadas.\n";

/**
 * Uma viagem só é um "deslocamento" real se teve movimento efetivo: pelo menos
 * 2 pontos E (velocidade máxima acima do ruído de GPS parado OU distância
 * mínima percorrida). Filtra viagens de 1 ponto, paradas com ignição ligada
 * (ex.: veículo estacionado a noite toda com ACC on) e deriva de GPS.
 *
 * @param array $trip Viagem finalizada (com max_speed, distance_km, points)
 * @return bool true se deve ser persistida
 */
function isRealTrip(array $trip): bool {
    if (count($trip['points']) < 2) return false;
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
