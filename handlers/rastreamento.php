<?php
/**
 * JIMI Webhook System — Rastreamento v4.0.0
 * Rota: /rastreamento
 *
 * Mapa ao vivo com navegação Cliente → Ativo.
 * Duas colunas (Clientes + Ativos) + mapa Leaflet.
 * Auto-refresh 30s.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$customers = $db->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();
$selCustomerId = $_GET['customer_id'] ?? ($customerId ?? ($customers[0]['id'] ?? 1));

$devices = [];
try {
    $devStmt = $db->prepare("
        SELECT d.imei, d.device_name, d.last_communication,
               dm.model_name,
               CASE WHEN TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) <= 5 THEN 1 ELSE 0 END as is_online
        FROM devices d
        LEFT JOIN device_models dm ON d.device_model_id = dm.id
        WHERE d.customer_id = :cid AND d.is_active = 1
        ORDER BY is_online DESC, d.device_name ASC
    ");
    $devStmt->execute([':cid' => $selCustomerId]);
    $devices = $devStmt->fetchAll();
} catch (Exception $e) {}

// Latest positions
$positions = [];
try {
    $posStmt = $db->prepare("
        SELECT g.imei, g.latitude, g.longitude, g.speed, g.gps_time, g.ignition,
               COALESCE(d.device_name, g.imei) as device_name,
               CASE WHEN TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) <= 5 THEN 1 ELSE 0 END as is_online
        FROM devices d
        LEFT JOIN gps_data g ON g.id = (
            SELECT g2.id FROM gps_data g2 WHERE g2.imei = d.imei AND g2.latitude != 0 ORDER BY g2.gps_time DESC LIMIT 1
        )
        WHERE d.customer_id = :cid AND d.is_active = 1
    ");
    $posStmt->execute([':cid' => $selCustomerId]);
    $positions = $posStmt->fetchAll();
} catch (Exception $e) {}

$page_title = 'Rastreamento';
$current_route = 'rastreamento';
$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
#tracking-map{height:calc(100vh - 140px);border-radius:var(--radius-lg);border:1px solid var(--hairline);}
.device-list-item{cursor:pointer;padding:10px 12px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:10px;transition:background .1s;}
.device-list-item:hover{background:var(--canvas-soft);}
.device-list-item.selected{background:var(--primary-soft);border-left:3px solid var(--primary);}
.device-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.device-dot.online{background:var(--success);}
.device-dot.offline{background:var(--muted-soft);}
.left-panel{max-height:calc(100vh - 140px);overflow-y:auto;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:240px 260px 1fr;gap:0;height:calc(100vh - 110px);">
    <!-- Clientes -->
    <div class="left-panel" style="border-right:1px solid var(--hairline);padding:8px;">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);padding:8px 4px 6px;">Clientes</div>
        <input type="text" id="customer-search" placeholder="Buscar cliente..." oninput="filterCustomers()"
               style="width:100%;padding:6px 8px;font-size:12px;border:1px solid var(--hairline);border-radius:var(--radius-sm);margin-bottom:8px;">
        <div id="customer-list">
            <?php foreach ($customers as $c): ?>
            <div class="device-list-item <?= $selCustomerId==$c['id']?'selected':'' ?>" data-cid="<?= $c['id'] ?>" onclick="selectCustomer(<?= $c['id'] ?>)">
                <span><?= htmlspecialchars($c['name']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Ativos -->
    <div class="left-panel" style="border-right:1px solid var(--hairline);padding:8px;">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);padding:8px 4px 6px;">
            Ativos <span style="font-weight:400;color:var(--muted);">(<?= count($devices) ?>)</span>
        </div>
        <input type="text" id="device-search" placeholder="Buscar ativo..." oninput="filterDevices()"
               style="width:100%;padding:6px 8px;font-size:12px;border:1px solid var(--hairline);border-radius:var(--radius-sm);margin-bottom:8px;">
        <div id="device-list">
            <?php foreach ($devices as $d): ?>
            <div class="device-list-item" data-imei="<?= $d['imei'] ?>" data-name="<?= htmlspecialchars($d['device_name']??$d['imei']) ?>" onclick="selectDevice('<?= $d['imei'] ?>')">
                <div class="device-dot <?= $d['is_online']?'online':'offline' ?>"></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:12px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?>
                    </div>
                    <div class="text-mono" style="font-size:10px;color:var(--muted);"><?= htmlspecialchars($d['imei']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Mapa -->
    <div style="padding:4px;">
        <div id="tracking-map"></div>
    </div>
</div>

<script>
var mapData = <?= json_encode(array_map(function($p) {
    return ['imei'=>$p['imei'],'lat'=>(float)$p['latitude'],'lng'=>(float)$p['longitude'],
            'name'=>$p['device_name'],'speed'=>(float)$p['speed'],'ignition'=>$p['ignition'],
            'online'=>(bool)$p['is_online'],'time'=>$p['gps_time']];
}, $positions)) ?>;

var map = L.map('tracking-map');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'&copy; OSM'}).addTo(map);
var markers = {};
var bounds = [];

mapData.forEach(function(p) {
    if (p.lat && p.lng && p.lat !== 0) {
        bounds.push([p.lat, p.lng]);
        var color = p.online ? '#05b169' : '#a8acb3';
        var m = L.circleMarker([p.lat, p.lng], {radius:6, color:color, fillColor:color, fillOpacity:0.6, weight:1})
            .addTo(map)
            .bindPopup('<b>' + (p.name||'') + '</b><br>IMEI: ' + p.imei + '<br>Vel: ' + (p.speed||0) + ' km/h<br>Ignição: ' + (p.ignition?'Ligada':'Desligada') + '<br>' + (p.time||''));
        markers[p.imei] = m;
    }
});

if (bounds.length > 0) map.fitBounds(bounds);
else map.setView([-15.78, -47.93], 5);

function selectDevice(imei) {
    document.querySelectorAll('#device-list .device-list-item').forEach(function(el) { el.classList.remove('selected'); });
    var el = document.querySelector('#device-list [data-imei="' + imei + '"]');
    if (el) el.classList.add('selected');
    var m = markers[imei];
    if (m) { map.setView(m.getLatLng(), 16); m.openPopup(); }
}

function selectCustomer(cid) { location.href = '?customer_id=' + cid; }

function filterCustomers() {
    var term = document.getElementById('customer-search').value.toLowerCase();
    document.querySelectorAll('#customer-list .device-list-item').forEach(function(el) {
        el.style.display = el.textContent.toLowerCase().indexOf(term) >= 0 ? '' : 'none';
    });
}

function filterDevices() {
    var term = document.getElementById('device-search').value.toLowerCase();
    document.querySelectorAll('#device-list .device-list-item').forEach(function(el) {
        var name = (el.dataset.name||'').toLowerCase();
        var imei = (el.dataset.imei||'').toLowerCase();
        el.style.display = name.indexOf(term) >= 0 || imei.indexOf(term) >= 0 ? '' : 'none';
    });
}

setTimeout(function() { map.invalidateSize(); }, 300);
setInterval(function() { location.reload(); }, 60000);
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
