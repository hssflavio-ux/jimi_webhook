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
require_once __DIR__ . '/../includes/fleet_state.php';
require_once __DIR__ . '/../includes/vehicle_icons.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$customers = report_customer_options($db);
$selCustomerId = $_GET['customer_id'] ?? ($customerId ?? ($customers[0]['id'] ?? 1));
$nowUtc = gmdate('Y-m-d H:i:s');

// Estado calculado com includes/fleet_state.php::resolve_live_state() — a
// partir do ÚLTIMO PONTO (device_statistics), não do segmento aberto. Esta
// tela é AO VIVO (auto-refresh 30s) e mistura Estado com Ignição/Velocidade
// no mesmo balão; resolve_current_state() (usada pelos relatórios batch)
// lê o segmento, que só é regravado a cada 15 min pelo cron
// state_builder.php — ver o comentário de resolve_live_state() no motivo.
$devices = [];
try {
    $devStmt = $db->prepare("
        SELECT d.imei, d.device_name, d.vehicle_type,
               dm.model_name,
               ds.last_gps_time, ds.last_acc_status AS ignition, ds.last_speed AS speed
        FROM devices d
        LEFT JOIN device_models dm ON d.device_model_id = dm.id
        LEFT JOIN device_statistics ds ON ds.imei = d.imei
        WHERE d.customer_id = :cid AND d.is_active = 1
        ORDER BY d.device_name ASC
    ");
    $devStmt->execute([':cid' => $selCustomerId]);
    foreach ($devStmt->fetchAll() as $row) {
        $row['current_state'] = resolve_live_state($row['last_gps_time'] ?? null, $row['ignition'] ?? null, $row['speed'] ?? null, $nowUtc);
        $row['is_online'] = $row['current_state'] !== 'offline';
        $devices[] = $row;
    }
    usort($devices, function ($a, $b) {
        return ($b['is_online'] <=> $a['is_online']) ?: strcmp($a['device_name'] ?? '', $b['device_name'] ?? '');
    });
} catch (Exception $e) {}

// Latest positions — device_statistics (cache já mantido pelos workers de
// ingestão) em vez de reabrir gps_data por device a cada request.
$positions = [];
try {
    $posStmt = $db->prepare("
        SELECT d.imei, COALESCE(d.device_name, d.imei) AS device_name, d.vehicle_type, d.speed_limit_kmh,
               c.default_speed_limit_kmh,
               ds.last_latitude AS latitude, ds.last_longitude AS longitude, ds.last_speed AS speed,
               ds.last_gps_time AS gps_time, ds.last_acc_status AS ignition
        FROM devices d
        LEFT JOIN customers c ON c.id = d.customer_id
        LEFT JOIN device_statistics ds ON ds.imei = d.imei
        WHERE d.customer_id = :cid AND d.is_active = 1
    ");
    $posStmt->execute([':cid' => $selCustomerId]);
    foreach ($posStmt->fetchAll() as $row) {
        $state = resolve_live_state($row['gps_time'] ?? null, $row['ignition'] ?? null, $row['speed'] ?? null, $nowUtc);
        $limit = resolve_speed_limit($row['speed_limit_kmh'], $row['default_speed_limit_kmh']);
        // "excesso" sobrepõe movimento/ocioso — nunca offline/parado, que já
        // não têm velocidade real associada.
        if ($state !== 'offline' && (int)$row['ignition'] === 1 && (float)$row['speed'] > $limit) {
            $state = 'excesso';
        }
        $row['state'] = $state;
        $positions[] = $row;
    }
} catch (Exception $e) {}

// D2 (v4.2.0 — YUV): modo AJAX para auto-refresh sem reload
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 0, 'positions' => array_map(function ($p) {
        return ['imei' => $p['imei'], 'lat' => (float)$p['latitude'], 'lng' => (float)$p['longitude'],
                'name' => $p['device_name'], 'speed' => (float)$p['speed'], 'ignition' => $p['ignition'],
                'state' => $p['state'], 'vehicleType' => $p['vehicle_type'],
                'online' => $p['state'] !== 'offline', 'time' => fmt_brt($p['gps_time'], 'd/m/Y H:i:s', '')];
    }, $positions)], JSON_UNESCAPED_UNICODE);
    exit;
}

$page_title = 'Rastreamento';
$current_route = 'rastreamento';
$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
#tracking-map{height:calc(100vh - 140px);border-radius:var(--radius-lg);border:1px solid var(--hairline);}
.map-wrap{padding:4px;position:relative;}
.device-list-item{cursor:pointer;padding:10px 12px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:10px;transition:background .1s;}
.device-list-item:hover{background:var(--canvas-soft);}
.device-list-item.selected{background:var(--primary-soft);border-left:3px solid var(--primary);}
.device-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.device-dot.online{background:var(--success);}
.device-dot.offline{background:var(--muted-soft);}
.left-panel{max-height:calc(100vh - 140px);overflow-y:auto;}
/* Pin do veículo (item 5, v4.10) — o SVG fica branco, quem muda por estado é
   o fundo do círculo. leaflet-div-icon tem fundo/borda padrão que precisam
   ser zerados aqui. */
.leaflet-div-icon.vehicle-pin-wrap{background:transparent;border:none;}
.vehicle-pin{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;
             border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);}
#fleet-legend{position:absolute;bottom:24px;left:16px;z-index:1000;background:var(--canvas);
              border:1px solid var(--hairline);border-radius:var(--radius-sm);padding:8px 12px;
              font-size:11px;color:var(--body);box-shadow:0 1px 4px rgba(0,0,0,.15);}
#fleet-legend .legend-row{display:flex;align-items:center;gap:6px;padding:2px 0;white-space:nowrap;}
#fleet-legend .legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
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
    <div class="map-wrap">
        <div id="tracking-map"></div>
        <div id="fleet-legend">
            <?php
            $legendStates = ['movimento', 'ocioso', 'parado', 'excesso', 'offline'];
            $legendColors = array_merge(FLEET_STATE_COLORS, ['excesso' => '#f4b000']);
            $legendLabels = array_merge(FLEET_STATE_LABELS, ['excesso' => 'Excesso de velocidade']);
            foreach ($legendStates as $lst):
            ?>
            <div class="legend-row">
                <span class="legend-dot" style="background:<?= htmlspecialchars($legendColors[$lst]) ?>"></span>
                <span><?= htmlspecialchars($legendLabels[$lst]) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
var FLEET_STATE_COLORS = <?= json_encode(array_merge(FLEET_STATE_COLORS, ['excesso' => '#f4b000'])) ?>;
var FLEET_STATE_LABELS = <?= json_encode(array_merge(FLEET_STATE_LABELS, ['excesso' => 'Excesso de velocidade'])) ?>;
var VEHICLE_ICONS = <?= json_encode(vehicle_icons_js_catalog(), JSON_UNESCAPED_SLASHES) ?>;

var mapData = <?= json_encode(array_map(function($p) {
    return ['imei'=>$p['imei'],'lat'=>(float)$p['latitude'],'lng'=>(float)$p['longitude'],
            'state'=>$p['state'],'vehicleType'=>$p['vehicle_type'],
            'name'=>$p['device_name'],'speed'=>(float)$p['speed'],'ignition'=>$p['ignition'],
            'online'=>$p['state'] !== 'offline','time'=>fmt_brt($p['gps_time'], 'd/m/Y H:i:s', '')];
}, $positions)) ?>;

var map = L.map('tracking-map');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'&copy; OSM'}).addTo(map);
var markers = {};
var bounds = [];

// Balão identifica o veículo pela PLACA (p.name = devices.device_name), nunca
// pelo IMEI: quem opera a frota reconhece a placa, não o número do rastreador.
function popupHtml(p) {
    var stateLabel = FLEET_STATE_LABELS[p.state] || '';
    return '<b>Placa: ' + (p.name || p.imei) + '</b><br>Estado: ' + stateLabel +
           '<br>Vel: ' + (p.speed||0) + ' km/h<br>Ignição: ' + (p.ignition==1?'Ligada':'Desligada') + '<br>' + (p.time||'');
}

// Pin = círculo colorido por estado (FLEET_STATE_COLORS) + ícone branco do
// tipo de veículo (Tabler Icons, ver includes/vehicle_icons.php). Sem tipo
// cadastrado, o pin fica só o círculo — comportamento anterior preservado.
function vehicleIconHtml(type, color) {
    var icon = VEHICLE_ICONS[type];
    if (!icon) return '';
    var attrs = icon.stroke
        ? 'fill="none" stroke="' + color + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        : 'fill="' + color + '"';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" ' + attrs + '>' + icon.paths + '</svg>';
}

function pinIcon(state, type) {
    var color = FLEET_STATE_COLORS[state] || '#5b616e';
    var svg = vehicleIconHtml(type, '#fff');
    return L.divIcon({
        className: 'vehicle-pin-wrap',
        html: '<div class="vehicle-pin" style="background:' + color + '">' + svg + '</div>',
        iconSize: [26, 26],
        iconAnchor: [13, 13],
        popupAnchor: [0, -13]
    });
}

mapData.forEach(function(p) {
    if (p.lat && p.lng && p.lat !== 0) {
        bounds.push([p.lat, p.lng]);
        var m = L.marker([p.lat, p.lng], {icon: pinIcon(p.state, p.vehicleType)})
            .addTo(map)
            .bindPopup(popupHtml(p));
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

// D2 (v4.2.0 — YUV): auto-refresh 30s sem reload — atualiza pins in-place
setInterval(function() {
    var url = new URL(location.href);
    url.searchParams.set('ajax', '1');
    fetch(url.toString()).then(function(r) { return r.json(); }).then(function(resp) {
        if (!resp || resp.code !== 0) return;
        (resp.positions || []).forEach(function(p) {
            if (!p.lat || p.lat === 0) return;
            var m = markers[p.imei];
            if (m) {
                m.setLatLng([p.lat, p.lng]);
                m.setIcon(pinIcon(p.state, p.vehicleType));
                m.setPopupContent(popupHtml(p));
            } else {
                markers[p.imei] = L.marker([p.lat, p.lng], {icon: pinIcon(p.state, p.vehicleType)})
                    .addTo(map).bindPopup(popupHtml(p));
            }
        });
    }).catch(function() {});
}, 30000);
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
