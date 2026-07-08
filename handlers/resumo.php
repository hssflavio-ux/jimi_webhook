<?php
/**
 * JIMI Webhook System — Resumo v4.0.0 (YUV Parity)
 * Rota: /
 *
 * Visão 360° executiva. Blocos:
 *   1. Tempo real: 4 KPIs + Mapa de Calor
 *   2. Operação: Velocidade da Frota, Ociosidade
 *   3. Desatualizados
 *   4. Visão por Clientes (revendedor)
 *   5. Séries temporais
 * Auto-refresh 30s.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isReseller = ($user['user_type'] ?? '') === 'revendedor';

// ── KPIs ──────────────────────────────────────────────────────
$kpiStmt = $db->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, last_communication, NOW()) <= 5 THEN 1 ELSE 0 END) as online,
        SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, last_communication, NOW()) > 5 THEN 1 ELSE 0 END) as offline
    FROM devices
    WHERE customer_id = :cid
");
$kpiStmt->execute([':cid' => $customerId ?? 1]);
$devKpi = $kpiStmt->fetch();

$occStmt = $db->prepare("
    SELECT COUNT(*) as total, SUM(CASE WHEN status='aguardando' THEN 1 ELSE 0 END) as waiting
    FROM occurrences WHERE customer_id = :cid
");
$occStmt->execute([':cid' => $customerId ?? 1]);
$occKpi = $occStmt->fetch();

// Recent GPS for heatmap
$gpsRows = $db->prepare("
    SELECT DISTINCT g.imei, g.latitude, g.longitude, g.speed, g.gps_time,
           COALESCE(d.device_name, g.imei) as device_name
    FROM gps_data g
    JOIN devices d ON d.imei = g.imei AND d.customer_id = :cid
    WHERE g.latitude != 0 AND g.longitude != 0
      AND ABS(g.latitude) > 0.0001 AND ABS(g.longitude) > 0.0001
      AND g.gps_time >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
    ORDER BY g.gps_time DESC
    LIMIT 500
");
$gpsRows->execute([':cid' => $customerId ?? 1]);
$gpsData = $gpsRows->fetchAll();

// Fleet speed distribution
$speedStmt = $db->prepare("
    SELECT
        SUM(CASE WHEN speed = 0 THEN 1 ELSE 0 END) as parados,
        SUM(CASE WHEN speed > 0 AND speed <= 20 THEN 1 ELSE 0 END) as ate20,
        SUM(CASE WHEN speed > 20 AND speed <= 60 THEN 1 ELSE 0 END) as ate60,
        SUM(CASE WHEN speed > 60 THEN 1 ELSE 0 END) as acima60,
        COUNT(*) as total
    FROM gps_data g
    JOIN devices d ON d.imei = g.imei AND d.customer_id = :cid
    WHERE g.gps_time >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
      AND g.ignition = 1
");
$speedStmt->execute([':cid' => $customerId ?? 1]);
$speedDist = $speedStmt->fetch();

// Desatualizados
$outdatedStmt = $db->prepare("
    SELECT
        SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_position_at, NOW()) BETWEEN 0 AND 6 THEN 1 ELSE 0 END) as lt7d,
        SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_position_at, NOW()) BETWEEN 7 AND 29 THEN 1 ELSE 0 END) as gt7d,
        SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_position_at, NOW()) >= 30 THEN 1 ELSE 0 END) as gt30d,
        SUM(CASE WHEN last_position_at IS NULL THEN 1 ELSE 0 END) as never
    FROM devices WHERE customer_id = :cid AND is_active = 1
");
$outdatedStmt->execute([':cid' => $customerId ?? 1]);
$outdated = $outdatedStmt->fetch();

// Top clientes (revendedor only)
$topCustomers = [];
if ($isReseller) {
    $topCustomers = $db->query("
        SELECT c.name, COUNT(d.id) as dev_count,
               (SELECT COUNT(*) FROM occurrences o WHERE o.customer_id = c.id) as occ_count
        FROM customers c
        LEFT JOIN devices d ON d.customer_id = c.id AND d.is_active = 1
        WHERE c.is_active = 1
        GROUP BY c.id
        ORDER BY dev_count DESC
        LIMIT 5
    ")->fetchAll();
}

// Alarmes hoje (hourly)
$alarmsToday = $db->prepare("
    SELECT HOUR(alarm_time) as hr, COUNT(*) as cnt
    FROM alarms a
    JOIN devices d ON d.imei = a.imei AND d.customer_id = :cid
    WHERE a.alarm_time >= CURDATE()
    GROUP BY HOUR(alarm_time)
    ORDER BY hr
");
$alarmsToday->execute([':cid' => $customerId ?? 1]);
$alarmsHourly = $alarmsToday->fetchAll();

// Ocorrências hoje (hourly)
$occsToday = $db->prepare("
    SELECT HOUR(first_alarm_at) as hr, COUNT(*) as cnt
    FROM occurrences
    WHERE customer_id = :cid AND first_alarm_at >= CURDATE()
    GROUP BY HOUR(first_alarm_at)
    ORDER BY hr
");
$occsToday->execute([':cid' => $customerId ?? 1]);
$occsHourly = $occsToday->fetchAll();

$page_title = 'Resumo';
$current_route = 'resumo';
$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
#heatmap-map{height:360px;border-radius:var(--radius-lg);border:1px solid var(--hairline);}
.velocity-bar{display:flex;height:24px;border-radius:12px;overflow:hidden;margin:8px 0;}
.velocity-bar div{display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:#fff;}
.chart-box{position:relative;height:200px;margin-top:12px;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<!-- ═══════ KPIs ═══════ -->
<div class="kpi-grid">
    <div class="kpi-item">
        <div class="kpi-item-label">Equipamentos</div>
        <div class="kpi-item-value"><?= $devKpi['active'] ?? 0 ?>/<?= $devKpi['total'] ?? 0 ?></div>
        <div class="kpi-item-delta">ativos</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Conectividade</div>
        <div class="kpi-item-value">On <span style="color:var(--success);"><?= $devKpi['online'] ?? 0 ?></span> / Off <span style="color:var(--error);"><?= $devKpi['offline'] ?? 0 ?></span></div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Ocorrências</div>
        <div class="kpi-item-value"><?= $occKpi['total'] ?? 0 ?> <span style="font-size:16px;font-weight:400;color:var(--warning);">(<?= $occKpi['waiting'] ?? 0 ?> aguardando)</span></div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Desatualizados</div>
        <div class="kpi-item-value"><?= ($outdated['lt7d']??0)+($outdated['gt7d']??0)+($outdated['gt30d']??0)+($outdated['never']??0) ?></div>
        <div class="kpi-item-delta">+7d: <?= $outdated['gt7d'] ?? 0 ?> · Nunca: <?= $outdated['never'] ?? 0 ?></div>
    </div>
</div>

<!-- ═══════ Mapa de Calor ═══════ -->
<div class="card mb-24" style="padding:12px 16px;">
    <div class="flex-between mb-8">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);">Mapa de Posições Recentes</h4>
        <span style="font-size:11px;color:var(--muted);">Últimas 2 horas</span>
    </div>
    <div id="heatmap-map"></div>
</div>

<!-- ═══════ Velocidade + Desatualizados ═══════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
    <div class="card" style="padding:16px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:8px;">Velocidade da Frota</h4>
        <?php
        $st = (int)($speedDist['total'] ?? 0);
        if ($st > 0):
            $pParado = round(($speedDist['parados']??0)/$st*100);
            $p20 = round(($speedDist['ate20']??0)/$st*100);
            $p60 = round(($speedDist['ate60']??0)/$st*100);
            $p60p = 100 - $pParado - $p20 - $p60;
        ?>
        <div class="velocity-bar">
            <div style="width:<?=$pParado?>%;background:var(--muted-soft);"><?=$pParado?>%</div>
            <div style="width:<?=$p20?>%;background:var(--primary);"><?=$p20?>%</div>
            <div style="width:<?=$p60?>%;background:var(--warning);"><?=$p60?>%</div>
            <div style="width:<?=$p60p?>%;background:var(--error);"><?=$p60p?>%</div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px;">
            <span style="color:var(--muted-soft);">■ Parados <?=$speedDist['parados']??0?></span>
            <span style="color:var(--primary);">■ ≤20 <?=$speedDist['ate20']??0?></span>
            <span style="color:var(--warning);">■ ≤60 <?=$speedDist['ate60']??0?></span>
            <span style="color:var(--error);">■ >60 <?=$speedDist['acima60']??0?></span>
        </div>
        <?php else: ?>
        <p class="text-muted" style="font-size:12px;">Sem dados de velocidade recentes.</p>
        <?php endif; ?>
    </div>

    <div class="card" style="padding:16px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:8px;">Desatualizados</h4>
        <?php
        $ot = ($outdated['lt7d']??0)+($outdated['gt7d']??0)+($outdated['gt30d']??0)+($outdated['never']??0);
        if ($ot > 0):
            $plt = $ot>0?round(($outdated['lt7d']??0)/$ot*100):0;
            $pg7 = $ot>0?round(($outdated['gt7d']??0)/$ot*100):0;
            $pg30 = $ot>0?round(($outdated['gt30d']??0)/$ot*100):0;
            $pnv = 100-$plt-$pg7-$pg30;
        ?>
        <div class="velocity-bar">
            <div style="width:<?=$plt?>%;background:var(--primary);"><?=$plt?>%</div>
            <div style="width:<?=$pg7?>%;background:var(--warning);"><?=$pg7?>%</div>
            <div style="width:<?=$pg30?>%;background:#f4b000;"><?=$pg30?>%</div>
            <div style="width:<?=$pnv?>%;background:var(--error);"><?=$pnv?>%</div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px;">
            <span style="color:var(--primary);">■ &lt;7d <?=$outdated['lt7d']??0?></span>
            <span style="color:var(--warning);">■ &gt;7d <?=$outdated['gt7d']??0?></span>
            <span style="color:#f4b000;">■ &gt;30d <?=$outdated['gt30d']??0?></span>
            <span style="color:var(--error);">■ Nunca <?=$outdated['never']??0?></span>
        </div>
        <?php else: ?>
        <p class="text-muted" style="font-size:12px;">Nenhum dispositivo desatualizado.</p>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════ Visão por Clientes ═══════ -->
<?php if ($isReseller && !empty($topCustomers)): ?>
<div class="card mb-24" style="padding:16px;">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px;">Top Clientes por Equipamentos</h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));gap:12px;">
        <?php foreach ($topCustomers as $tc): ?>
        <div style="padding:12px;border:1px solid var(--hairline-soft);border-radius:var(--radius-sm);">
            <div style="font-size:13px;font-weight:600;color:var(--ink);"><?= htmlspecialchars($tc['name']) ?></div>
            <div style="font-size:12px;color:var(--muted);margin-top:4px;">
                <?= $tc['dev_count'] ?> dispositivos · <?= $tc['occ_count'] ?> ocorrências
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══════ Séries Temporais ═══════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div class="card" style="padding:16px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);">Alarmes Hoje</h4>
        <div class="chart-box">
            <canvas id="chart-alarms"></canvas>
        </div>
    </div>
    <div class="card" style="padding:16px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);">Ocorrências Hoje</h4>
        <div class="chart-box">
            <canvas id="chart-occs"></canvas>
        </div>
    </div>
</div>

<script>
// ── Heatmap Map ────────────────────────────────────────────
(function() {
    var container = document.getElementById('heatmap-map');
    var data = <?= json_encode($gpsData) ?>;
    var map = L.map(container);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'&copy; OSM'}).addTo(map);
    var bounds = [];
    data.forEach(function(p) {
        var lat = parseFloat(p.latitude), lng = parseFloat(p.longitude);
        if (lat && lng && lat !== 0) {
            bounds.push([lat, lng]);
            L.circleMarker([lat, lng], {
                radius: 4, color: '#0052ff', fillColor: '#0052ff', fillOpacity: 0.4, weight: 1
            }).addTo(map).bindPopup(p.device_name + '<br>' + (p.speed||0) + ' km/h');
        }
    });
    if (bounds.length > 0) map.fitBounds(bounds);
    else map.setView([-15.78, -47.93], 5);
    setTimeout(function() { map.invalidateSize(); }, 200);
})();

// ── Charts ─────────────────────────────────────────────────
(function() {
    var hours = ['00','01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23'];
    var alarmData = <?= json_encode($alarmsHourly) ?>;
    var occData = <?= json_encode($occsHourly) ?>;
    var aVals = Array(24).fill(0), oVals = Array(24).fill(0);
    alarmData.forEach(function(r) { aVals[parseInt(r.hr)] = parseInt(r.cnt); });
    occData.forEach(function(r) { oVals[parseInt(r.hr)] = parseInt(r.cnt); });

    function makeChart(canvasId, label, values, color) {
        return new Chart(document.getElementById(canvasId), {
            type: 'bar',
            data: { labels: hours, datasets: [{ label: label, data: values, backgroundColor: color, borderRadius: 4 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { font: { size: 10 } }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { font: { size: 10 } }, grid: { color: '#eef0f3' } }
                }
            }
        });
    }
    makeChart('chart-alarms', 'Alarmes', aVals, 'rgba(0,82,255,0.7)');
    makeChart('chart-occs', 'Ocorrências', oVals, 'rgba(244,176,0,0.7)');
})();
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
