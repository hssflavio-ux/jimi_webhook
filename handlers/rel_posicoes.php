<?php
/**
 * JIMI Webhook System — Relatório de Posições v4.0.0
 * Rota: /relatorios/posicoes
 *
 * Filtro: Ativo + Período + Intervalo + [Gerar] + [Ver posições] (mapa) + Export.
 * Grade: Identificador, Endereço (geocodificado), Motorista, Ignição, Sinal, Velocidade, Horário.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/geocode.php';

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$selImei    = $_GET['imei'] ?? '';
$dateFrom   = $_GET['date_from'] ?? brt_today();
$dateTo     = $_GET['date_to'] ?? brt_today();
$interval   = $_GET['interval'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$generated = !empty($_GET['gerar']);

$rows = [];
$totalRows = 0;
$totalPages = 1;
$hasCoords = [];
$geoCache = [];

if ($generated && $selImei) {
    try {
        $where = 'WHERE imei = :imei AND gps_time BETWEEN :df AND :dt';
        [$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo); // dias BRT → janela UTC
        $params = [':imei' => $selImei, ':df' => $utcFrom, ':dt' => $utcTo];

        if ($interval === 'sampled') {
            $where .= ' AND MOD(id, 10) = 0';
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM gps_data $where");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, ceil($totalRows / $perPage));
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT g.id, g.imei, g.latitude, g.longitude, g.speed, g.gps_time,
                   COALESCE(d.device_name, g.imei) as device_name
            FROM gps_data g
            LEFT JOIN devices d ON d.imei = g.imei
            $where
            ORDER BY g.gps_time DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            if ($r['latitude'] && $r['longitude'] && $r['latitude'] != 0) {
                $hasCoords[] = ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 'imei' => $r['imei'], 'name' => $r['device_name']];
            }
        }
    } catch (Exception $e) {}
}

$devices = $db->prepare("SELECT d.imei, d.device_name FROM devices d WHERE d.customer_id = :cid ORDER BY d.device_name");
$devices->execute([':cid' => $customerId]);
$devices = $devices->fetchAll();

$page_title = 'Relatório de Posições';
$current_route = 'rel_posicoes';

$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>#map-container{height:400px;border-radius:var(--radius-lg);border:1px solid var(--hairline);margin-bottom:16px;display:none;}</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Posições</h2>
    <button class="btn btn-outline btn-sm" onclick="alert('Export Excel em desenvolvimento')">Exportar Excel</button>
</div>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <input type="hidden" name="gerar" value="1">
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Ativo</label>
            <select name="imei" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:180px;">
                <option value="">— Selecione —</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>" <?= $selImei===$d['imei']?'selected':'' ?>><?= htmlspecialchars($d['device_name']??$d['imei']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Intervalo</label>
            <select name="interval" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="all" <?= $interval==='all'?'selected':'' ?>>Todas as posições</option>
                <option value="sampled" <?= $interval==='sampled'?'selected':'' ?>>Amostrado (1:10)</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Período</label>
            <div style="display:flex;gap:4px;">
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
        <?php if ($generated && !empty($hasCoords)): ?>
        <button type="button" class="btn btn-outline btn-sm" onclick="toggleMap()" id="btn-map">Ver Posições no Mapa</button>
        <?php endif; ?>
    </form>
</div>

<?php if ($generated && !empty($hasCoords)): ?>
<div id="map-container"></div>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Data/Hora</th><th>IMEI</th><th>Dispositivo</th><th>Latitude</th><th>Longitude</th><th>Velocidade</th><th>Ignição</th><th>Sinal GPS</th></tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted);"><?= $generated ? 'Nenhuma posição encontrada' : 'Selecione um ativo e clique em Gerar' ?></td></tr>
            <?php else: ?>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td class="text-mono"><?= fmt_brt($r['gps_time'], 'd/m/Y H:i:s') ?></td>
                <td><span class="text-mono"><?= htmlspecialchars($r['imei']) ?></span></td>
                <td><?= htmlspecialchars($r['device_name']) ?></td>
                <td class="text-mono"><?= number_format((float)$r['latitude'], 6) ?></td>
                <td class="text-mono"><?= number_format((float)$r['longitude'], 6) ?></td>
                <td><?= $r['speed'] !== null ? number_format((float)$r['speed'], 1) . ' km/h' : '—' ?></td>
                <td><?= $r['ignition'] ? '<span class="badge badge-success">Ligada</span>' : '<span class="badge">Desligada</span>' ?></td>
                <td><?= $r['gps_status'] === 'A' ? '<span class="badge badge-success">Válido</span>' : '<span class="badge">' . htmlspecialchars($r['gps_status']??'—') . '</span>' ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">
    <span>Página <?= $page ?> de <?= $totalPages ?> (<?= number_format($totalRows, 0, ',', '.') ?> posições)</span>
    <div style="display:flex;gap:4px;">
        <?php
        $queryStr = $_GET; unset($queryStr['page']);
        $base = http_build_query($queryStr);
        if ($page > 1): ?><a href="?<?= $base ?>&page=<?= $page-1 ?>" class="btn btn-outline btn-sm">&laquo;</a><?php endif;
        for ($i = 1; $i <= min($totalPages, 10); $i++):
            if ($i === $page): ?><span class="btn btn-primary btn-sm"><?= $i ?></span>
            <?php else: ?><a href="?<?= $base ?>&page=<?= $i ?>" class="btn btn-outline btn-sm"><?= $i ?></a><?php endif;
        endfor;
        if ($page < $totalPages): ?><a href="?<?= $base ?>&page=<?= $page+1 ?>" class="btn btn-outline btn-sm">&raquo;</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($hasCoords)): ?>
<script>
var mapData = <?= json_encode($hasCoords) ?>;
var mapInstance = null;
function toggleMap() {
    var container = document.getElementById('map-container');
    if (container.style.display === 'block') { container.style.display = 'none'; return; }
    container.style.display = 'block';
    if (!mapInstance) {
        mapInstance = L.map('map-container');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'&copy; OSM'}).addTo(mapInstance);
        var bounds = [];
        mapData.forEach(function(p) {
            bounds.push([p.lat, p.lng]);
            L.marker([p.lat, p.lng]).addTo(mapInstance).bindPopup(p.imei + '<br>' + (p.name||''));
        });
        if (bounds.length > 0) mapInstance.fitBounds(bounds);
        else mapInstance.setView([-15.78, -47.93], 5);
    }
    setTimeout(function(){ mapInstance.invalidateSize(); }, 100);
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
