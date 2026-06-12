<?php
/**
 * JIMI Webhook System — Painel Principal v3.1.0
 * Endpoint: /dashboard
 *
 * NavTrack-style: KPI cards + lista de ativos + mapa Leaflet.
 * Clique no dispositivo → centraliza no mapa.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_customer_id();
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo');

function fmt_brt($dt) { global $tz_utc, $tz_brt; if (!$dt) return '-'; $d = new DateTime($dt, $tz_utc); $d->setTimezone($tz_brt); return $d->format('d/m/Y H:i:s'); }
function fmt_brt_short($dt) { global $tz_utc, $tz_brt; if (!$dt) return '-'; $d = new DateTime($dt, $tz_utc); $d->setTimezone($tz_brt); return $d->format('d/m H:i'); }

$db = Database::getInstance()->getConnection();

// KPI
$totalDevices  = $db->query("SELECT COUNT(*) FROM devices WHERE customer_id = $customer_id")->fetchColumn();
$onlineDevices = $db->query("SELECT COUNT(*) FROM devices d JOIN device_statistics s ON d.imei=s.imei WHERE d.customer_id=$customer_id AND s.is_online=1")->fetchColumn();
$alarmsToday   = $db->query("SELECT COUNT(*) FROM alarms a JOIN devices d ON a.imei=d.imei WHERE d.customer_id=$customer_id AND a.created_at>=DATE(NOW())")->fetchColumn();
$cmdsToday     = $db->query("SELECT COUNT(*) FROM commands c JOIN devices d ON c.imei=d.imei WHERE d.customer_id=$customer_id AND c.created_at>=DATE(NOW())")->fetchColumn();

// Devices
$devices = $db->query("
    SELECT d.imei, d.device_name, d.last_communication,
           s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status, s.is_online,
           COALESCE(dm.model_name, d.device_model, '-') AS model_display
    FROM devices d
    LEFT JOIN device_statistics s ON d.imei = s.imei
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    WHERE d.customer_id = $customer_id
    ORDER BY d.last_communication DESC
")->fetchAll(PDO::FETCH_ASSOC);

$devicesJson = json_encode($devices);

$page_title    = 'Painel';
$current_route = 'dashboard';

$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>#dash-map{width:100%;height:380px;border-radius:var(--radius-lg);overflow:hidden;border:1px solid var(--hairline)}</style>';

include __DIR__ . '/../web/layout_base.php';
?>

<div class="kpi-grid">
    <div class="kpi-item"><div class="kpi-item-label">Dispositivos</div><div class="kpi-item-value"><?= $totalDevices ?></div><div class="kpi-item-delta"><?= $onlineDevices ?> online</div></div>
    <div class="kpi-item"><div class="kpi-item-label">Online</div><div class="kpi-item-value"><?= $onlineDevices ?></div><div class="kpi-item-delta">dispositivos</div></div>
    <div class="kpi-item"><div class="kpi-item-label">Alarmes Hoje</div><div class="kpi-item-value"><?= $alarmsToday ?></div><div class="kpi-item-delta">últimas 24h</div></div>
    <div class="kpi-item"><div class="kpi-item-label">Comandos Hoje</div><div class="kpi-item-value"><?= $cmdsToday ?></div><div class="kpi-item-delta">enviados</div></div>
</div>

<div class="table-wrap mb-16">
    <table id="device-table">
        <thead><tr><th>Dispositivo</th><th>IMEI</th><th>Modelo</th><th>Status</th><th>Velocidade</th><th>Última Com.</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($devices as $i => $dev):
                $isOnline = false;
                if ($dev['last_communication']) { $dtL = new DateTime($dev['last_communication'], $tz_utc); $isOnline = (new DateTime('now', $tz_utc))->getTimestamp() - $dtL->getTimestamp() < 600; }
                $hasGps = !empty($dev['last_latitude']) && $dev['last_latitude'] != 0;
            ?>
            <tr class="device-row<?= $hasGps ? ' has-gps' : '' ?>" data-index="<?= $i ?>" data-lat="<?= $dev['last_latitude'] ?? '' ?>" data-lng="<?= $dev['last_longitude'] ?? '' ?>" data-name="<?= htmlspecialchars($dev['device_name'] ?? $dev['imei']) ?>" style="cursor:<?= $hasGps ? 'pointer' : 'default' ?>">
                <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($dev['device_name'] ?? 'Sem Nome') ?></td>
                <td class="text-mono"><?= htmlspecialchars($dev['imei']) ?></td>
                <td><?= htmlspecialchars($dev['model_display']) ?></td>
                <td><?php if ($isOnline): ?><span class="badge badge-success">Online</span><?php else: ?><span class="badge" style="background:var(--surface-strong);color:var(--muted)">Offline</span><?php endif; ?><?php if ($dev['last_acc_status']==1): ?> <span class="badge badge-warning">Ligado</span><?php endif; ?></td>
                <td><?= round($dev['last_speed'] ?? 0) ?> km/h</td>
                <td><?= fmt_brt_short($dev['last_communication']) ?></td>
                <td><a href="/ativos/<?= urlencode($dev['imei']) ?>" class="btn btn-outline btn-sm">Detalhes</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($devices)): ?>
            <tr><td colspan="7"><div class="empty-state"><h3>Nenhum dispositivo</h3><p>Cadastre seu primeiro equipamento.</p><a href="/ativos/novo" class="btn btn-primary mt-16">Cadastrar</a></div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="dash-map"></div>
<div style="font-size:12px;color:var(--muted);margin-top:4px;text-align:center">Clique em um dispositivo com GPS para centralizar no mapa</div>

<script>
var devices = <?= $devicesJson ?>;
var map = L.map('dash-map').setView([-15.78, -47.93], 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19, attribution:'&copy; OpenStreetMap' }).addTo(map);

var markers = [];
var allBounds = [];

devices.forEach(function(d, i) {
    if (!d.last_latitude || d.last_latitude == 0) return;
    var lat = parseFloat(d.last_latitude), lng = parseFloat(d.last_longitude);
    var speed = Math.round(d.last_speed || 0);
    var isMoving = d.last_acc_status == 1;
    var color = isMoving ? '#f54e00' : '#9fbbe0';

    var marker = L.circleMarker([lat, lng], { radius:7, fillColor:color, color:'#fff', weight:2, fillOpacity:0.9 }).addTo(map);
    marker.bindPopup('<strong>' + (d.device_name || d.imei) + '</strong><br>' + speed + ' km/h<br><a href="/ativos/' + d.imei + '">Detalhes</a>');
    marker._deviceIndex = i;
    markers.push(marker);
    allBounds.push([lat, lng]);
});

if (allBounds.length > 0) map.fitBounds(allBounds, { padding: [30, 30] });

// Click handler
document.querySelectorAll('.device-row.has-gps').forEach(function(row) {
    row.addEventListener('click', function() {
        var idx = parseInt(this.dataset.index);
        document.querySelectorAll('.device-row').forEach(function(r) { r.style.background = ''; });
        this.style.background = 'var(--canvas-soft)';
        if (markers[idx]) {
            map.setView(markers[idx].getLatLng(), 15);
            markers[idx].openPopup();
        }
    });
});
</script>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
