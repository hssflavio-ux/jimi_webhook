<?php
/**
 * JIMI Webhook System — Rastreamento ao Vivo v3.1.0
 * Endpoint: /live
 *
 * Mapa multi-ativo estilo NavTrack OrganizationLiveTrackingPage.
 * Mostra todos os dispositivos do cliente em um único mapa Leaflet.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_current_customer_id();
$db = Database::getInstance()->getConnection();
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo');

function fmt_brt_live($dt) {
    global $tz_utc, $tz_brt;
    if (!$dt) return '';
    $d = new DateTime($dt, $tz_utc);
    $d->setTimezone($tz_brt);
    return $d->format('d/m/Y H:i:s');
}

$devices = $db->query("
    SELECT d.imei, d.device_name, d.last_communication,
           s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status
    FROM devices d
    LEFT JOIN device_statistics s ON d.imei = s.imei
    WHERE d.customer_id = $customer_id
      AND s.last_latitude IS NOT NULL AND s.last_latitude != 0
    ORDER BY d.last_communication DESC
")->fetchAll(PDO::FETCH_ASSOC);

$devicesJson = json_encode($devices);

$page_title    = 'Ao Vivo';
$current_route = 'live';

$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>#live-map{width:100%;height:calc(100vh - 120px);border-radius:var(--radius-lg);overflow:hidden}</style>';

include __DIR__ . '/../web/layout_base.php';
?>

<div id="live-map"></div>

<script>
var devicesJson = <?= $devicesJson ?>;
var fmtBrts = {};
var map = L.map('live-map').setView([-15.78, -47.93], 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap'
}).addTo(map);

var markers = [];
var bounds = [];

devicesJson.forEach(function(d) {
    if (!d.last_latitude || d.last_latitude == 0) return;
    var lat = parseFloat(d.last_latitude);
    var lng = parseFloat(d.last_longitude);
    var speed = Math.round(d.last_speed || 0);
    var isMoving = d.last_acc_status == 1;

    var iconColor = isMoving ? '#f54e00' : '#9fbbe0';

    var marker = L.circleMarker([lat, lng], {
        radius: 8,
        fillColor: iconColor,
        color: '#fff',
        weight: 2,
        fillOpacity: 0.9
    }).addTo(map);

    marker.bindPopup(
        '<strong>' + (d.device_name || d.imei) + '</strong><br>' +
        '<small>' + speed + ' km/h</small><br>' +
        '<a href="/ativos/' + d.imei + '">Abrir detalhes</a>'
    );
    markers.push(marker);
    bounds.push([lat, lng]);
});

if (bounds.length > 0) {
    map.fitBounds(bounds, { padding: [50, 50] });
}
</script>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
