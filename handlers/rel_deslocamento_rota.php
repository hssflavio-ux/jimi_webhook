<?php
/**
 * JIMI Webhook System — Rota do Deslocamento no Mapa v4.3.0
 * Rota: /relatorios/deslocamento/rota?trip_id={id}
 *       /relatorios/deslocamento/rota?imei={imei}&dia={Y-m-d}   (fechamento diário)
 *
 * Desenha no mapa (Leaflet) o percurso de um deslocamento (ou do dia inteiro):
 *   - balão de Partida (data/hora) e de Chegada (data/hora);
 *   - um ponto por posição/comunicação da câmera (popup com hora/velocidade);
 *   - pontos com ocorrência em cor de destaque, citando a ocorrência no balão.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();

$tripId = (int)($_GET['trip_id'] ?? 0);
$selImei = $_GET['imei'] ?? '';
$dia = $_GET['dia'] ?? '';

$error = '';
$imei = '';
$utcFrom = $utcTo = null;
$summary = ['distance_km' => 0, 'max_speed' => 0, 'alarm_count' => 0, 'viagens' => 0];

try {
    if ($tripId > 0) {
        // Modalidade por deslocamento: janela = a própria viagem
        $sql = "SELECT t.*, COALESCE(d.device_name, t.imei) AS device_name
                FROM trips t LEFT JOIN devices d ON d.imei = t.imei
                WHERE t.id = :id" . ($customerId ? " AND t.customer_id = :cid" : "");
        $stmt = $db->prepare($sql);
        $p = [':id' => $tripId];
        if ($customerId) $p[':cid'] = $customerId;
        $stmt->execute($p);
        $trip = $stmt->fetch();
        if (!$trip) {
            $error = 'Viagem não encontrada.';
        } else {
            $imei = $trip['imei'];
            $deviceName = $trip['device_name'];
            $utcFrom = $trip['started_at'];
            $utcTo = $trip['ended_at'] ?: $trip['started_at'];
            $summary = [
                'distance_km' => (float)$trip['distance_km'],
                'max_speed'   => (float)$trip['max_speed'],
                'alarm_count' => (int)$trip['alarm_count'],
                'viagens'     => 1,
            ];
        }
    } elseif ($selImei !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
        // Fechamento diário: janela = primeira ignição ligada → última desligada
        // do dia BRT (viagens que COMEÇARAM neste dia, como na grade do relatório)
        [$dayFrom, $dayTo] = brt_day_range_to_utc($dia, $dia);
        $sql = "SELECT MAX(COALESCE(d.device_name, t.imei)) AS device_name,
                       MIN(t.started_at) AS primeira_on, MAX(t.ended_at) AS ultima_off,
                       SUM(t.distance_km) AS distance_km, MAX(t.max_speed) AS max_speed,
                       SUM(t.alarm_count) AS alarm_count, COUNT(*) AS viagens
                FROM trips t LEFT JOIN devices d ON d.imei = t.imei
                WHERE t.imei = :imei AND t.started_at BETWEEN :df AND :dt"
                . ($customerId ? " AND t.customer_id = :cid" : "");
        $stmt = $db->prepare($sql);
        $p = [':imei' => $selImei, ':df' => $dayFrom, ':dt' => $dayTo];
        if ($customerId) $p[':cid'] = $customerId;
        $stmt->execute($p);
        $day = $stmt->fetch();
        if (!$day || !$day['primeira_on']) {
            $error = 'Nenhum deslocamento encontrado para este ativo neste dia.';
        } else {
            $imei = $selImei;
            $deviceName = $day['device_name'];
            $utcFrom = $day['primeira_on'];
            $utcTo = $day['ultima_off'] ?: $day['primeira_on'];
            $summary = [
                'distance_km' => (float)$day['distance_km'],
                'max_speed'   => (float)$day['max_speed'],
                'alarm_count' => (int)$day['alarm_count'],
                'viagens'     => (int)$day['viagens'],
            ];
        }
    } else {
        $error = 'Parâmetros inválidos: informe trip_id ou imei + dia.';
    }
} catch (Exception $e) {
    $error = 'Erro ao carregar o deslocamento.';
}

$points = [];
$occPins = [];
$sampled = false;
$totalPoints = 0;
$occCount = 0;

if (!$error) {
    // Posições/comunicações da câmera na janela (idx_imei_time)
    $stmt = $db->prepare("
        SELECT latitude, longitude, speed, acc, gps_time
        FROM gps_data
        WHERE imei = :imei AND gps_time BETWEEN :df AND :dt
          AND latitude IS NOT NULL AND latitude <> 0
        ORDER BY gps_time ASC
        LIMIT 20000");
    $stmt->execute([':imei' => $imei, ':df' => $utcFrom, ':dt' => $utcTo]);
    $raw = $stmt->fetchAll();
    $totalPoints = count($raw);

    // Amostragem para não pesar o navegador: mantém no máx. ~3000 pontos,
    // preservando sempre o primeiro e o último.
    $maxPts = 3000;
    $step = $totalPoints > $maxPts ? (int)ceil($totalPoints / $maxPts) : 1;
    $sampled = $step > 1;
    foreach ($raw as $i => $r) {
        if ($i % $step !== 0 && $i !== $totalPoints - 1) continue;
        $points[] = [
            'lat' => (float)$r['latitude'],
            'lng' => (float)$r['longitude'],
            't'   => fmt_brt($r['gps_time'], 'd/m/Y H:i:s'),
            'ts'  => strtotime($r['gps_time'] . ' UTC'),
            's'   => $r['speed'] !== null ? (float)$r['speed'] : null,
            'acc' => (int)$r['acc'],
            'occ' => [],
        ];
    }

    // Ocorrências da janela: posição do primeiro alarme agrupado; sem
    // coordenada própria, a ocorrência é anexada ao ponto mais próximo no tempo.
    $sql = "SELECT o.id, o.alarm_type, o.risk, o.status, o.first_alarm_at,
                   a.latitude AS lat, a.longitude AS lng, a.alarm_time
            FROM occurrences o
            LEFT JOIN occurrence_events oe ON oe.occurrence_id = o.id
            LEFT JOIN alarms a ON a.id = oe.alarm_id AND a.latitude IS NOT NULL AND a.latitude <> 0
            WHERE o.imei = :imei AND o.first_alarm_at BETWEEN :df AND :dt"
            . ($customerId ? " AND o.customer_id = :cid" : "") . "
            ORDER BY o.id, a.alarm_time";
    $stmt = $db->prepare($sql);
    $p = [':imei' => $imei, ':df' => $utcFrom, ':dt' => $utcTo];
    if ($customerId) $p[':cid'] = $customerId;
    $stmt->execute($p);

    $statusLabels = ['aguardando' => 'Aguardando', 'em_tratativa' => 'Em tratativa',
                     'resolvida' => 'Resolvida', 'descartada' => 'Descartada'];
    $occs = [];
    while ($r = $stmt->fetch()) {
        $id = (int)$r['id'];
        // primeira linha com coordenada vence; senão fica a sem coordenada
        if (isset($occs[$id]) && $occs[$id]['lat'] !== null) continue;
        $when = $r['alarm_time'] ?: $r['first_alarm_at'];
        $occs[$id] = [
            'lat' => $r['lat'] !== null ? (float)$r['lat'] : null,
            'lng' => $r['lng'] !== null ? (float)$r['lng'] : null,
            'ts'  => strtotime($when . ' UTC'),
            'label' => htmlspecialchars($r['alarm_type'])
                . ' — ' . fmt_brt($when, 'd/m/Y H:i:s')
                . ' · risco ' . htmlspecialchars($r['risk'])
                . ' · ' . ($statusLabels[$r['status']] ?? htmlspecialchars($r['status'])),
        ];
    }

    $occCount = count($occs);

    foreach ($occs as $o) {
        if ($o['lat'] !== null) {
            $occPins[] = ['lat' => $o['lat'], 'lng' => $o['lng'], 'label' => $o['label']];
            continue;
        }
        // Sem coordenada: destaca o ponto de comunicação mais próximo no tempo
        $best = -1; $bestDiff = PHP_INT_MAX;
        foreach ($points as $i => $pt) {
            $diff = abs($pt['ts'] - $o['ts']);
            if ($diff < $bestDiff) { $bestDiff = $diff; $best = $i; }
        }
        if ($best >= 0) $points[$best]['occ'][] = $o['label'];
    }
}

$jornadaS = $utcFrom && $utcTo ? max(0, strtotime($utcTo) - strtotime($utcFrom)) : 0;

$page_title = 'Rota do Deslocamento';
$current_route = 'rel_deslocamento';
$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
#rota-map{height:calc(100vh - 300px);min-height:460px;border-radius:var(--radius-lg);border:1px solid var(--hairline);}
.rota-kpi{font-family:\'JetBrains Mono\',monospace;font-size:15px;font-weight:600;color:var(--ink);}
.rota-kpi-label{font-size:10px;font-weight:600;text-transform:uppercase;color:var(--muted);}
.rota-pin{display:flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;
  color:#fff;font-weight:700;font-size:13px;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Rota do Deslocamento</h2>
    <div style="display:flex;align-items:center;gap:12px;">
        <?php if (!$error): ?>
        <span style="font-size:13px;color:var(--muted);">
            <?= htmlspecialchars($deviceName) ?> · <span class="text-mono"><?= htmlspecialchars($imei) ?></span>
            · <?= fmt_brt($utcFrom) ?> → <?= fmt_brt($utcTo) ?>
        </span>
        <?php endif; ?>
        <?= report_back_button('/relatorios/deslocamento', 'Voltar ao relatório') ?>
    </div>
</div>

<?php if ($error): ?>
<div class="card" style="padding:32px;text-align:center;color:var(--muted);"><?= htmlspecialchars($error) ?></div>
<?php else: ?>

<div class="card mb-16" style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:28px;">
    <div><div class="rota-kpi-label">Distância</div><div class="rota-kpi"><?= number_format($summary['distance_km'], 1) ?> km</div></div>
    <div><div class="rota-kpi-label">Duração</div><div class="rota-kpi"><?= $jornadaS > 0 ? sprintf('%dh%02dm', floor($jornadaS/3600), floor(($jornadaS%3600)/60)) : '—' ?></div></div>
    <div><div class="rota-kpi-label">Vel. Máx</div><div class="rota-kpi"><?= number_format($summary['max_speed'], 1) ?> km/h</div></div>
    <div><div class="rota-kpi-label">Viagens</div><div class="rota-kpi"><?= $summary['viagens'] ?></div></div>
    <div><div class="rota-kpi-label">Alarmes</div><div class="rota-kpi"><?= $summary['alarm_count'] ?></div></div>
    <div><div class="rota-kpi-label">Posições</div><div class="rota-kpi"><?= $totalPoints ?><?= $sampled ? ' <span style="font-size:10px;color:var(--muted);">(amostrado)</span>' : '' ?></div></div>
    <div><div class="rota-kpi-label">Ocorrências</div><div class="rota-kpi"><?= $occCount ?></div></div>
</div>

<?php if (empty($points)): ?>
<div class="card" style="padding:32px;text-align:center;color:var(--muted);">Nenhuma posição GPS registrada na janela deste deslocamento.</div>
<?php else: ?>
<div id="rota-map"></div>

<div class="mt-16" style="display:flex;gap:20px;flex-wrap:wrap;font-size:12px;color:var(--muted);align-items:center;">
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#0a9955;vertical-align:-2px;"></span> Partida</span>
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#e5484d;vertical-align:-2px;"></span> Chegada</span>
    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#0052ff;vertical-align:-1px;"></span> Posição enviada pela câmera</span>
    <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#f5a623;vertical-align:-2px;"></span> Posição com ocorrência</span>
</div>

<script>
var routeData = {
    points: <?= json_encode(array_map(fn($pt) => ['lat'=>$pt['lat'],'lng'=>$pt['lng'],'t'=>$pt['t'],'s'=>$pt['s'],'acc'=>$pt['acc'],'occ'=>$pt['occ']], $points)) ?>,
    occPins: <?= json_encode($occPins) ?>,
    startLabel: <?= json_encode('Partida — ' . fmt_brt($utcFrom, 'd/m/Y H:i:s')) ?>,
    endLabel: <?= json_encode('Chegada — ' . fmt_brt($utcTo, 'd/m/Y H:i:s')) ?>
};

var map = L.map('rota-map', { preferCanvas: true });
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '&copy; OSM'}).addTo(map);

var latlngs = routeData.points.map(function(p) { return [p.lat, p.lng]; });
L.polyline(latlngs, { color: '#0052ff', weight: 3, opacity: 0.75 }).addTo(map);

// Pontos de posição/comunicação (azul); com ocorrência → laranja + balão citando
routeData.points.forEach(function(p, i) {
    if (i === 0 || i === routeData.points.length - 1) return; // partida/chegada têm balão próprio
    var hasOcc = p.occ && p.occ.length > 0;
    var html = 'Posição — ' + p.t
        + (p.s !== null ? '<br>Velocidade: ' + p.s.toFixed(1) + ' km/h' : '')
        + '<br>Ignição: ' + (p.acc ? 'ligada' : 'desligada');
    if (hasOcc) html += '<br><strong>Ocorrência:</strong><br>' + p.occ.join('<br>');
    L.circleMarker([p.lat, p.lng], {
        radius: hasOcc ? 8 : 4,
        color: hasOcc ? '#c47d0e' : '#0052ff',
        fillColor: hasOcc ? '#f5a623' : '#0052ff',
        fillOpacity: 0.85, weight: hasOcc ? 2 : 1
    }).addTo(map).bindPopup(html);
});

// Ocorrências com coordenada própria (posição exata do alarme)
routeData.occPins.forEach(function(o) {
    L.circleMarker([o.lat, o.lng], {
        radius: 8, color: '#c47d0e', fillColor: '#f5a623', fillOpacity: 0.9, weight: 2
    }).addTo(map).bindPopup('<strong>Ocorrência:</strong><br>' + o.label);
});

// Balões de partida (verde) e chegada (vermelho)
function pinIcon(bg, letter) {
    return L.divIcon({
        className: '',
        html: '<div class="rota-pin" style="background:' + bg + ';">' + letter + '</div>',
        iconSize: [28, 28], iconAnchor: [14, 14], popupAnchor: [0, -14]
    });
}
var first = routeData.points[0], last = routeData.points[routeData.points.length - 1];
L.marker([first.lat, first.lng], { icon: pinIcon('#0a9955', 'A'), zIndexOffset: 1000 })
    .addTo(map).bindPopup('<strong>' + routeData.startLabel + '</strong>');
L.marker([last.lat, last.lng], { icon: pinIcon('#e5484d', 'B'), zIndexOffset: 1000 })
    .addTo(map).bindPopup('<strong>' + routeData.endLabel + '</strong>');

map.fitBounds(latlngs, { padding: [30, 30] });
</script>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
