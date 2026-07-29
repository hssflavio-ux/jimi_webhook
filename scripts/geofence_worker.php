<?php
/**
 * JIMI Webhook System — Geofence Worker v4.5.0
 * Script: scripts/geofence_worker.php
 *
 * Cron (a cada 2 minutos) que avalia os pontos novos de gps_data contra as
 * geocercas vinculadas e grava as transições em geofence_events, notificando
 * pelo motor da v4.4.0 quando a cerca pede alerta.
 *
 * Uso:
 *   php scripts/geofence_worker.php        # incremental (cron)
 *   php scripts/geofence_worker.php 7      # backfill: 7 dias para cerca sem estado
 *
 * Três decisões que sustentam o custo:
 *
 *   1. Parte de geofence_devices, não da frota. Cerca sem equipamento
 *      vinculado não custa nada; device sem cerca nunca é lido.
 *   2. Leitura incremental por geofence_state.last_gps_time — cada ponto é
 *      avaliado uma vez por cerca, não a cada rodada.
 *   3. Pré-filtro por bounding box antes da trigonometria (includes/geofence.php).
 *
 * Reexecução é segura: os eventos entram com INSERT IGNORE protegido pela
 * UNIQUE (geofence_id, imei, event_time), então rodar duas vezes sobre a
 * mesma janela não duplica nada.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/geofence.php';
require_once __DIR__ . '/../includes/notification_engine.php';

/** Teto de pontos lidos por equipamento por rodada (protege contra backlog). */
const GEOFENCE_MAX_POINTS_PER_DEVICE = 20000;

/** Lookback padrão para cerca/equipamento ainda sem estado gravado. */
const GEOFENCE_DEFAULT_LOOKBACK_DAYS = 1;

$lookbackDays = (isset($argv[1]) && (int)$argv[1] > 0)
    ? (int)$argv[1]
    : GEOFENCE_DEFAULT_LOOKBACK_DAYS;

$db = Database::getInstance()->getConnection();

// ── Carrega o trabalho: cerca ativa × equipamento vinculado × estado ──
try {
    $rows = $db->query("
        SELECT gd.imei,
               g.id AS geofence_id, g.customer_id, g.name, g.kind, g.shape,
               g.center_lat, g.center_lng, g.radius_m, g.polygon,
               g.bbox_min_lat, g.bbox_max_lat, g.bbox_min_lng, g.bbox_max_lng,
               g.alert_on, g.alert_emails,
               s.is_inside, s.last_gps_time
        FROM geofence_devices gd
        JOIN geofences g ON g.id = gd.geofence_id AND g.is_active = 1
        LEFT JOIN geofence_state s ON s.geofence_id = gd.geofence_id AND s.imei = gd.imei
        ORDER BY gd.imei, g.id
    ")->fetchAll();
} catch (Throwable $e) {
    fwrite(STDERR, "Geofence Worker: tabelas indisponíveis — aplique a migração v4.5.0.\n");
    Logger::error('Geofence Worker: tabelas indisponíveis', ['error' => $e->getMessage()]);
    exit(1);
}

if (!$rows) {
    echo "Geofence Worker: nenhuma cerca com equipamento vinculado.\n";
    exit(0);
}

// Agrupa por equipamento: um device é lido uma única vez, mesmo pertencendo
// a várias cercas.
$byDevice = [];
foreach ($rows as $r) {
    $byDevice[$r['imei']][] = $r;
}

$defaultSince = date('Y-m-d H:i:s', strtotime("-{$lookbackDays} days"));
$eventsCreated = 0;
$pointsRead    = 0;
$notified      = 0;

foreach ($byDevice as $imei => $fences) {
    // Marca-d'água do device: o ponto mais antigo que ainda interessa a
    // ALGUMA das suas cercas. Cada cerca ignora individualmente o que já
    // avaliou (checagem por cerca dentro do laço).
    $since = null;
    foreach ($fences as $f) {
        $fenceSince = $f['last_gps_time'] ?: $defaultSince;
        if ($since === null || $fenceSince < $since) {
            $since = $fenceSince;
        }
    }

    $stmt = $db->prepare("
        SELECT latitude, longitude, speed, gps_time
        FROM gps_data
        WHERE imei = :imei AND gps_time > :since
        ORDER BY gps_time ASC
        LIMIT " . GEOFENCE_MAX_POINTS_PER_DEVICE);
    $stmt->execute([':imei' => $imei, ':since' => $since]);
    $points = $stmt->fetchAll();

    if (!$points) {
        continue;
    }
    $pointsRead += count($points);

    // Estado em memória por cerca: evita um SELECT por ponto.
    $state = [];
    foreach ($fences as $f) {
        $state[(int)$f['geofence_id']] = [
            'is_inside'     => (int)($f['is_inside'] ?? 0) === 1,
            'last_gps_time' => $f['last_gps_time'],
            // Cerca sem estado gravado: o primeiro ponto avaliado apenas
            // SEMEIA o estado, sem gerar evento. Do contrário, criar uma
            // cerca em cima da garagem produziria uma "entrada" retroativa
            // para cada veículo já estacionado ali.
            'seeded'        => $f['last_gps_time'] !== null,
            'dirty'         => false,
        ];
    }

    try {
        $db->beginTransaction();

        foreach ($points as $p) {
            $lat = (float)$p['latitude'];
            $lng = (float)$p['longitude'];
            $gpsTime = $p['gps_time'];

            // Ponto (0,0) e coordenada fora de faixa não dizem nada sobre
            // posição — avaliá-los criaria entrada/saída fantasma (R06).
            if (!is_valid_coordinate($lat, $lng)) {
                continue;
            }

            foreach ($fences as $f) {
                $gid = (int)$f['geofence_id'];

                // Esta cerca já avaliou este ponto numa rodada anterior
                if ($state[$gid]['last_gps_time'] !== null
                    && $gpsTime <= $state[$gid]['last_gps_time']) {
                    continue;
                }

                $inside = point_in_geofence($lat, $lng, $f, $state[$gid]['is_inside']);

                if (!$state[$gid]['seeded']) {
                    $state[$gid]['seeded']    = true;
                    $state[$gid]['is_inside'] = $inside;
                } elseif ($inside !== $state[$gid]['is_inside']) {
                    $type = $inside ? 'entrada' : 'saida';
                    if (record_geofence_event($db, $f, $imei, $type, $gpsTime, $lat, $lng, $p['speed'])) {
                        $eventsCreated++;
                        if (geofence_alert_matches($f['alert_on'] ?? 'nenhum', $type)
                            && notify_geofence_event($db, $f, $imei, $type, $gpsTime)) {
                            $notified++;
                        }
                    }
                    $state[$gid]['is_inside'] = $inside;
                }

                $state[$gid]['last_gps_time'] = $gpsTime;
                $state[$gid]['dirty'] = true;
            }
        }

        foreach ($fences as $f) {
            $gid = (int)$f['geofence_id'];
            if (!empty($state[$gid]['dirty'])) {
                save_geofence_state($db, $gid, $imei, $state[$gid]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        Logger::error('Geofence Worker: falha ao avaliar equipamento', [
            'imei'  => $imei,
            'error' => $e->getMessage(),
        ]);
    }
}

echo "Geofence Worker: {$pointsRead} pontos avaliados, {$eventsCreated} eventos, {$notified} notificações.\n";

/**
 * A transição casa com o que a cerca pediu alertar?
 *
 * O EVENTO é sempre gravado (o relatório de permanência precisa dos dois
 * lados do par); `alert_on` decide apenas se há notificação.
 *
 * @param string $alertOn entrada|saida|ambos|nenhum
 * @param string $type    entrada|saida
 * @returns bool
 */
function geofence_alert_matches(string $alertOn, string $type): bool
{
    return $alertOn === 'ambos' || $alertOn === $type;
}

/**
 * Grava a transição.
 *
 * INSERT IGNORE + UNIQUE (geofence_id, imei, event_time) é o que torna a
 * reexecução do worker inofensiva: o mesmo ponto reprocessado não vira um
 * segundo evento.
 *
 * @param PDO         $db      Conexão ativa
 * @param array       $fence   Cerca
 * @param string      $imei    Equipamento
 * @param string      $type    entrada|saida
 * @param string      $time    gps_time UTC do ponto que causou a transição
 * @param float       $lat     Latitude
 * @param float       $lng     Longitude
 * @param string|null $speed   Velocidade no ponto
 * @returns bool true se uma linha nova foi criada
 */
function record_geofence_event(PDO $db, array $fence, string $imei, string $type,
                               string $time, float $lat, float $lng, $speed): bool
{
    $stmt = $db->prepare("
        INSERT IGNORE INTO geofence_events
            (geofence_id, customer_id, imei, event_type, event_time, latitude, longitude, speed)
        VALUES (:gid, :cid, :imei, :type, :time, :lat, :lng, :speed)
    ");
    $stmt->execute([
        ':gid'   => (int)$fence['geofence_id'],
        ':cid'   => $fence['customer_id'] !== null ? (int)$fence['customer_id'] : null,
        ':imei'  => $imei,
        ':type'  => $type,
        ':time'  => $time,
        ':lat'   => $lat,
        ':lng'   => $lng,
        ':speed' => $speed !== null ? (float)$speed : null,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Persiste o estado da dupla cerca × equipamento.
 *
 * @param PDO    $db    Conexão ativa
 * @param int    $gid   Geocerca
 * @param string $imei  Equipamento
 * @param array  $st    Estado em memória (is_inside, last_gps_time)
 * @returns void
 */
function save_geofence_state(PDO $db, int $gid, string $imei, array $st): void
{
    $stmt = $db->prepare("
        INSERT INTO geofence_state (geofence_id, imei, is_inside, last_gps_time)
        VALUES (:gid, :imei, :inside, :last)
        ON DUPLICATE KEY UPDATE is_inside = VALUES(is_inside), last_gps_time = VALUES(last_gps_time)
    ");
    $stmt->execute([
        ':gid'    => $gid,
        ':imei'   => $imei,
        ':inside' => $st['is_inside'] ? 1 : 0,
        ':last'   => $st['last_gps_time'],
    ]);
}

/**
 * Enfileira a notificação da transição pelo motor da v4.4.0.
 *
 * notify() só grava — o e-mail sai depois, no scripts/worker.php. O teto
 * horário por cliente e o kill-switch NOTIFY_ENABLED valem aqui do mesmo
 * jeito que valem para ocorrências.
 *
 * @param PDO    $db    Conexão ativa
 * @param array  $fence Cerca
 * @param string $imei  Equipamento
 * @param string $type  entrada|saida
 * @param string $time  gps_time UTC da transição
 * @returns bool true se a notificação foi gravada
 */
function notify_geofence_event(PDO $db, array $fence, string $imei, string $type, string $time): bool
{
    $emails = [];
    $decoded = json_decode((string)($fence['alert_emails'] ?? '[]'), true);
    if (is_array($decoded)) {
        $emails = array_slice(array_values(array_filter(array_map('trim', $decoded))), 0, 3);
    }

    $label  = notify_device_label($db, $imei);
    $fname  = (string)($fence['name'] ?? 'Geocerca');
    $verbo  = $type === 'entrada' ? 'entrou em' : 'saiu de';
    $quando = fmt_notify_time($time);

    $id = notify($db, [
        'customer_id' => $fence['customer_id'] !== null ? (int)$fence['customer_id'] : null,
        'kind'        => 'geocerca',
        'severity'    => 'warning',
        'title'       => $label . ' ' . $verbo . ' ' . $fname,
        'body'        => ucfirst($type) . ' registrada em ' . $quando . '.',
        'link_url'    => '/relatorios/geocercas?geofence_id=' . (int)$fence['geofence_id'],
        'ref_type'    => 'geofence_event',
        'ref_id'      => (int)$fence['geofence_id'],
        'want_popup'  => 1,
        'want_sound'  => 0,
        'emails'      => $emails,
        // Dedupe do e-mail por cerca × equipamento × sentido: um veículo que
        // manobra no portão não dispara 5 e-mails em 15 minutos.
        'dedupe_key'  => 'gf|' . (int)$fence['geofence_id'] . '|' . $imei . '|' . $type,
    ]);

    return $id !== null;
}
