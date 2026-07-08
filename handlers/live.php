<?php
/**
 * JIMI Webhook System — Rastreamento ao Vivo v3.1.0
 * Endpoint: /live
 *
 * Mapa multi-ativo com auto-refresh a cada 30s.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_customer_id();
$db = Database::getInstance()->getConnection();
$tz_brt = new DateTimeZone('America/Sao_Paulo');

$devicesStmt = $db->prepare("
    SELECT d.imei, d.device_name, d.last_communication,
           s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status
    FROM devices d
    LEFT JOIN device_statistics s ON d.imei = s.imei
    WHERE d.customer_id = :cid AND d.is_active = 1
    ORDER BY d.last_communication DESC
");
$devicesStmt->execute([':cid' => $customer_id]);
$devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);

$hasGpsData = false;
foreach ($devices as $d) {
    if (!empty($d['last_latitude']) && $d['last_latitude'] != 0) { $hasGpsData = true; break; }
}

$devicesJson = json_encode($devices);

$page_title    = 'Ao Vivo';
$current_route = 'live';

$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>#live-map{width:100%;height:calc(100vh - 150px);border-radius:var(--radius-lg);overflow:hidden;border:1px solid var(--hairline)}
.no-gps{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;color:var(--muted);z-index:1000;background:var(--surface);padding:24px 32px;border-radius:var(--radius-lg);border:1px solid var(--hairline)}
.device-count{font-size:13px;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:8px}
.device-count .dot{width:8px;height:8px;border-radius:50%}
</style>';

include __DIR__ . '/../web/layout_base.php';
?>

<div style="position:relative">
    <div id="live-map"></div>
    <?php if (!$hasGpsData): ?>
    <div class="no-gps" id="no-gps-msg">
        <i class="bi bi-map" style="font-size:36px;display:block;margin-bottom:8px;opacity:.3"></i>
        <strong style="color:var(--ink);display:block;margin-bottom:4px">Sem dados de localização</strong>
        <span style="font-size:13px">Nenhum dispositivo enviou coordenadas GPS ainda.</span>
    </div>
    <?php endif; ?>
</div>
<div class="device-count" style="margin-top:8px">
    <span class="dot" style="background:#0052ff"></span> Ignição ligada &nbsp;
    <span class="dot" style="background:#9fbbe0"></span> Parado &nbsp;
    <span style="margin-left:auto" id="device-count-text"></span>
</div>

<script>
var devices = <?= $devicesJson ?>;
var map = L.map('live-map').setView([-15.78, -47.93], 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19, attribution:'&copy; OpenStreetMap' }).addTo(map);

function loadMarkers() {
    map.eachLayer(function(layer) { if (layer instanceof L.CircleMarker) map.removeLayer(layer); });
    var bounds = [], count = 0;

    devices.forEach(function(d) {
        if (!d.last_latitude || d.last_latitude == 0) return;
        var lat = parseFloat(d.last_latitude), lng = parseFloat(d.last_longitude);
        var speed = Math.round(d.last_speed || 0);
        var isMoving = d.last_acc_status == 1;
        var color = isMoving ? '#0052ff' : '#a8acb3';

        L.circleMarker([lat, lng], { radius:9, fillColor:color, color:'#fff', weight:2, fillOpacity:0.9 }).addTo(map)
            .bindPopup('<strong>' + (d.device_name || d.imei) + '</strong><br>' + speed + ' km/h<br><a href="/ativos/' + d.imei + '">Detalhes</a>');
        bounds.push([lat, lng]);
        count++;
    });

    var el = document.getElementById('device-count-text');
    if (el) el.textContent = count + ' dispositivo(s) com GPS';

    if (bounds.length > 0) map.fitBounds(bounds, { padding:[40, 40] });

    var msg = document.getElementById('no-gps-msg');
    if (msg) msg.style.display = count > 0 ? 'none' : 'block';
}

loadMarkers();
setInterval(function() {
    fetch('/camerasdata?token=' + encodeURIComponent(<?= json_encode($dashToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123') ?>))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.devices) {
                devices = data.devices.map(function(d) { return { imei:d.imei, device_name:d.name, last_latitude:d.lat, last_longitude:d.lng, last_speed:d.speed, last_acc_status:d.acc, last_communication:d.last }; });
                loadMarkers();
            }
        }).catch(function(){});
}, 30000);
</script>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
