<?php
/**
 * JIMI Webhook System — Resumo v4.0.0 (YUV Parity)
 * Rota: /
 *
 * Visão 360° executiva. Blocos:
 *   1. KPIs via metrics_snapshots (5-min cache) + welcome tour
 *   2. Mapa de Calor (GPS recentes, on-the-fly)
 *   3. Velocidade da Frota + Desatualizados
 *   4. Visão por Clientes (revendedor)
 *   5. Séries temporais (alarmes/ocorrências hora-a-hora)
 * Auto-refresh 30s. Tour de boas-vindas com localStorage.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isReseller = ($user['user_type'] ?? '') === 'revendedor';

function get_metric($db, $cid, $key, $fallback = 0) {
    try {
        $stmt = $db->prepare("
            SELECT metric_value FROM metrics_snapshots
            WHERE customer_id = :cid AND metric_key = :key
            ORDER BY snapshot_at DESC LIMIT 1
        ");
        $stmt->execute([':cid' => $cid, ':key' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['metric_value'] : $fallback;
    } catch (Exception $e) {
        return $fallback;
    }
}

// ── KPIs from cache (or on-the-fly if stale) ─────────────────
$devTotal   = get_metric($db, $customerId, 'devices_total', 0);
$devActive  = get_metric($db, $customerId, 'devices_active', 0);
$devOnline  = get_metric($db, $customerId, 'devices_online', 0);
$devOffline = get_metric($db, $customerId, 'devices_offline', 0);
$occTotal   = get_metric($db, $customerId, 'occurrences_total', 0);
$occWaiting = get_metric($db, $customerId, 'occurrences_waiting', 0);

// On-the-fly fallback if no cached metrics
if ($devTotal == 0 && $devActive == 0 && $devOnline == 0 && $devOffline == 0) {
    try {
        $kpiStmt = $db->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active,
                   SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, last_communication, NOW()) <= 5 THEN 1 ELSE 0 END) as online,
                   SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, last_communication, NOW()) > 5 THEN 1 ELSE 0 END) as offline
            FROM devices WHERE customer_id = :cid
        ");
        $kpiStmt->execute([':cid' => $customerId ?? 1]);
        $devKpiFb = $kpiStmt->fetch();
        $devTotal   = $devKpiFb['total'] ?? 0;
        $devActive  = $devKpiFb['active'] ?? 0;
        $devOnline  = $devKpiFb['online'] ?? 0;
        $devOffline = $devKpiFb['offline'] ?? 0;
    } catch (Exception $e) {}

    try {
        $occStmt = $db->prepare("
            SELECT COUNT(*) as total, SUM(CASE WHEN status='aguardando' THEN 1 ELSE 0 END) as waiting
            FROM occurrences WHERE customer_id = :cid
        ");
        $occStmt->execute([':cid' => $customerId ?? 1]);
        $occKpiFb = $occStmt->fetch();
        $occTotal   = $occKpiFb['total'] ?? 0;
        $occWaiting = $occKpiFb['waiting'] ?? 0;
    } catch (Exception $e) {}
}

// ── GPS Heatmap (always on-the-fly, last 2h) ─────────────────
$gpsData = [];
try {
    $gpsRows = $db->prepare("
        SELECT DISTINCT g.imei, g.latitude, g.longitude, g.speed, g.gps_time,
               COALESCE(d.device_name, g.imei) as device_name
        FROM gps_data g
        JOIN devices d ON d.imei = g.imei AND d.customer_id = :cid
        WHERE g.latitude != 0 AND g.longitude != 0
          AND ABS(g.latitude) > 0.0001 AND ABS(g.longitude) > 0.0001
          AND g.gps_time >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
        ORDER BY g.gps_time DESC LIMIT 500
    ");
    $gpsRows->execute([':cid' => $customerId ?? 1]);
    $gpsData = $gpsRows->fetchAll();
} catch (Exception $e) {}

// ── Speed + Outdated from cache ──────────────────────────────
$spdParados = get_metric($db, $customerId, 'speed_parados', 0);
$spdAte20   = get_metric($db, $customerId, 'speed_ate20', 0);
$spdAte60   = get_metric($db, $customerId, 'speed_ate60', 0);
$spdAcima60 = get_metric($db, $customerId, 'speed_acima60', 0);

$outLt7d  = get_metric($db, $customerId, 'outdated_lt7d', 0);
$outGt7d  = get_metric($db, $customerId, 'outdated_gt7d', 0);
$outGt30d = get_metric($db, $customerId, 'outdated_gt30d', 0);
$outNever = get_metric($db, $customerId, 'outdated_never', 0);

// On-the-fly fallback for speed
if ($spdParados == 0 && $spdAte20 == 0 && $spdAte60 == 0 && $spdAcima60 == 0) {
    try {
        $speedStmt = $db->prepare("
            SELECT
                SUM(CASE WHEN speed = 0 THEN 1 ELSE 0 END) as parados,
                SUM(CASE WHEN speed > 0 AND speed <= 20 THEN 1 ELSE 0 END) as ate20,
                SUM(CASE WHEN speed > 20 AND speed <= 60 THEN 1 ELSE 0 END) as ate60,
                SUM(CASE WHEN speed > 60 THEN 1 ELSE 0 END) as acima60,
                COUNT(*) as total
            FROM gps_data g
            JOIN devices d ON d.imei = g.imei AND d.customer_id = :cid
            WHERE g.gps_time >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND g.ignition = 1
        ");
        $speedStmt->execute([':cid' => $customerId ?? 1]);
        $speedDist = $speedStmt->fetch();
        $spdParados = $speedDist['parados'] ?? 0;
        $spdAte20   = $speedDist['ate20'] ?? 0;
        $spdAte60   = $speedDist['ate60'] ?? 0;
        $spdAcima60 = $speedDist['acima60'] ?? 0;
    } catch (Exception $e) {}
}
$speedTotal = $spdParados + $spdAte20 + $spdAte60 + $spdAcima60;
$outTotal   = $outLt7d + $outGt7d + $outGt30d + $outNever;

// ── Top clientes (revendedor only) ───────────────────────────
$topCustomers = [];
if ($isReseller) {
    try {
        $topCustomers = $db->query("
            SELECT c.name, COUNT(d.id) as dev_count,
                   (SELECT COUNT(*) FROM occurrences o WHERE o.customer_id = c.id) as occ_count
            FROM customers c
            LEFT JOIN devices d ON d.customer_id = c.id AND d.is_active = 1
            WHERE c.is_active = 1
            GROUP BY c.id ORDER BY dev_count DESC LIMIT 5
        ")->fetchAll();
    } catch (Exception $e) {}
}

// ── Charts: alarmes + ocorrências hoje (hora-a-hora) ─────────
// "Hoje" e horas em BRT (banco em UTC): início do dia local em UTC +
// hora local via CONVERT_TZ com offset fixo (não requer tz tables)
[$todayStartUtc, ] = brt_day_range_to_utc(brt_today(), brt_today());

$alarmsHourly = [];
try {
    $alarmsToday = $db->prepare("
        SELECT HOUR(CONVERT_TZ(alarm_time, '+00:00', '-03:00')) as hr, COUNT(*) as cnt
        FROM alarms a
        JOIN devices d ON d.imei = a.imei AND d.customer_id = :cid
        WHERE a.alarm_time >= :ts
        GROUP BY hr ORDER BY hr
    ");
    $alarmsToday->execute([':cid' => $customerId ?? 1, ':ts' => $todayStartUtc]);
    $alarmsHourly = $alarmsToday->fetchAll();
} catch (Exception $e) {}

$occsHourly = [];
try {
    $occsToday = $db->prepare("
        SELECT HOUR(CONVERT_TZ(first_alarm_at, '+00:00', '-03:00')) as hr, COUNT(*) as cnt
        FROM occurrences
        WHERE customer_id = :cid AND first_alarm_at >= :ts
        GROUP BY hr ORDER BY hr
    ");
    $occsToday->execute([':cid' => $customerId ?? 1, ':ts' => $todayStartUtc]);
    $occsHourly = $occsToday->fetchAll();
} catch (Exception $e) {}

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
.announce-banner{display:none;align-items:center;justify-content:space-between;padding:10px 16px;border-radius:var(--radius-sm);margin-bottom:16px;font-size:13px;}
.announce-banner.info{background:#eaf0ff;border:1px solid #b8d4ff;color:var(--primary);}
.announce-banner.warning{background:#fff8e1;border:1px solid #ffe082;color:#f4b000;}
.announce-banner.success{background:#e8f5e9;border:1px solid #a5d6a7;color:#05b169;}
.announce-banner .announce-close{cursor:pointer;opacity:.6;font-size:16px;line-height:1;padding:0 4px;}
.tour-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;}
.tour-card{background:#fff;border-radius:var(--radius-lg);padding:24px 28px;max-width:420px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.15);}
.tour-card h3{font-size:18px;font-weight:600;color:var(--ink);margin-bottom:8px;}
.tour-card p{font-size:14px;color:var(--body);margin-bottom:20px;line-height:1.5;}
.tour-card .tour-dots{display:flex;gap:6px;margin-bottom:20px;}
.tour-card .tour-dot{width:8px;height:8px;border-radius:50%;background:var(--hairline);}
.tour-card .tour-dot.active{background:var(--primary);}
.tour-card .tour-actions{display:flex;justify-content:flex-end;gap:8px;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<!-- Banner de Comunicado -->
<div id="announce-banner" class="announce-banner info">
    <span id="announce-text">Bem-vindo ao JIMI v4.0. Explore os novos recursos do painel.</span>
    <span class="announce-close" onclick="dismissBanner()">&times;</span>
</div>

<!-- ═══════ KPIs ═══════ -->
<div class="kpi-grid">
    <div class="kpi-item">
        <div class="kpi-item-label">Equipamentos</div>
        <div class="kpi-item-value"><?= $devActive ?>/<?= $devTotal ?></div>
        <div class="kpi-item-delta">ativos</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Conectividade</div>
        <div class="kpi-item-value">On <span style="color:var(--success);"><?= $devOnline ?></span> / Off <span style="color:var(--error);"><?= $devOffline ?></span></div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Ocorrências</div>
        <div class="kpi-item-value"><?= $occTotal ?> <span style="font-size:16px;font-weight:400;color:var(--warning);">(<?= $occWaiting ?> aguardando)</span></div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Desatualizados</div>
        <div class="kpi-item-value"><?= $outTotal ?></div>
        <div class="kpi-item-delta">+7d: <?= $outGt7d ?> · Nunca: <?= $outNever ?></div>
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
        if ($speedTotal > 0):
            $pParado = round($spdParados / $speedTotal * 100);
            $p20 = round($spdAte20 / $speedTotal * 100);
            $p60 = round($spdAte60 / $speedTotal * 100);
            $p60p = 100 - $pParado - $p20 - $p60;
        ?>
        <div class="velocity-bar">
            <div style="width:<?=$pParado?>%;background:var(--muted-soft);"><?=$pParado?>%</div>
            <div style="width:<?=$p20?>%;background:var(--primary);"><?=$p20?>%</div>
            <div style="width:<?=$p60?>%;background:var(--warning);"><?=$p60?>%</div>
            <div style="width:<?=$p60p?>%;background:var(--error);"><?=$p60p?>%</div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px;">
            <span style="color:var(--muted-soft);">■ Parados <?= $spdParados ?></span>
            <span style="color:var(--primary);">■ ≤20 <?= $spdAte20 ?></span>
            <span style="color:var(--warning);">■ ≤60 <?= $spdAte60 ?></span>
            <span style="color:var(--error);">■ >60 <?= $spdAcima60 ?></span>
        </div>
        <?php else: ?>
        <p class="text-muted" style="font-size:12px;">Sem dados de velocidade recentes.</p>
        <?php endif; ?>
    </div>

    <div class="card" style="padding:16px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:8px;">Desatualizados</h4>
        <?php
        if ($outTotal > 0):
            $plt = round($outLt7d / $outTotal * 100);
            $pg7 = round($outGt7d / $outTotal * 100);
            $pg30 = round($outGt30d / $outTotal * 100);
            $pnv = 100 - $plt - $pg7 - $pg30;
        ?>
        <div class="velocity-bar">
            <div style="width:<?=$plt?>%;background:var(--primary);"><?=$plt?>%</div>
            <div style="width:<?=$pg7?>%;background:var(--warning);"><?=$pg7?>%</div>
            <div style="width:<?=$pg30?>%;background:#f4b000;"><?=$pg30?>%</div>
            <div style="width:<?=$pnv?>%;background:var(--error);"><?=$pnv?>%</div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px;">
            <span style="color:var(--primary);">■ &lt;7d <?= $outLt7d ?></span>
            <span style="color:var(--warning);">■ &gt;7d <?= $outGt7d ?></span>
            <span style="color:#f4b000;">■ &gt;30d <?= $outGt30d ?></span>
            <span style="color:var(--error);">■ Nunca <?= $outNever ?></span>
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
        <div class="chart-box"><canvas id="chart-alarms"></canvas></div>
    </div>
    <div class="card" style="padding:16px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);">Ocorrências Hoje</h4>
        <div class="chart-box"><canvas id="chart-occs"></canvas></div>
    </div>
</div>

<!-- ═══════ Tour Overlay ═══════ -->
<div id="tour-overlay" class="tour-overlay">
    <div class="tour-card">
        <h3 id="tour-title"></h3>
        <p id="tour-body"></p>
        <div class="tour-dots" id="tour-dots"></div>
        <div class="tour-actions">
            <button class="btn btn-outline btn-sm" id="tour-skip">Pular</button>
            <button class="btn btn-primary btn-sm" id="tour-next">Próximo</button>
        </div>
    </div>
</div>

<script>
// ── Welcome Tour ─────────────────────────────────────────────
(function() {
    var TOUR_KEY = 'jimi_tour_seen_v4';
    var steps = [
        { title: 'Bem-vindo ao JIMI v4.0', body: 'Esta é sua central de rastreamento. Acompanhe equipamentos, alarmes e ocorrências em tempo real.' },
        { title: 'KPIs em Tempo Real', body: 'No topo você vê o resumo da sua frota: equipamentos ativos, conectividade, ocorrências pendentes e dispositivos desatualizados.' },
        { title: 'Mapa de Posições', body: 'O mapa mostra a última posição conhecida de cada equipamento. Clique nos pontos para ver detalhes.' },
        { title: 'Gráficos de Atividade', body: 'Acompanhe a evolução de alarmes e ocorrências ao longo do dia nos gráficos abaixo.' },
        { title: 'Navegação Lateral', body: 'Use a barra lateral para acessar rastreamento, vídeos, relatórios e cadastros. O menu tem grupos expansíveis.' }
    ];
    var currentStep = 0;

    function showTour() {
        if (localStorage.getItem(TOUR_KEY)) return;
        var overlay = document.getElementById('tour-overlay');
        overlay.style.display = 'flex';
        renderStep();
    }

    function renderStep() {
        var s = steps[currentStep];
        document.getElementById('tour-title').textContent = s.title;
        document.getElementById('tour-body').textContent = s.body;
        var dots = '';
        for (var i = 0; i < steps.length; i++) {
            dots += '<div class="tour-dot' + (i === currentStep ? ' active' : '') + '"></div>';
        }
        document.getElementById('tour-dots').innerHTML = dots;
        document.getElementById('tour-next').textContent = currentStep === steps.length - 1 ? 'Concluir' : 'Próximo';
    }

    document.getElementById('tour-next').onclick = function() {
        if (currentStep < steps.length - 1) { currentStep++; renderStep(); }
        else { closeTour(); }
    };
    document.getElementById('tour-skip').onclick = closeTour;

    function closeTour() {
        document.getElementById('tour-overlay').style.display = 'none';
        localStorage.setItem(TOUR_KEY, '1');
    }

    showTour();
})();

// ── Announce Banner ──────────────────────────────────────────
(function() {
    var BANNER_KEY = 'jimi_banner_dismissed';
    var banner = document.getElementById('announce-banner');
    if (localStorage.getItem(BANNER_KEY)) { banner.style.display = 'none'; }
    else { banner.style.display = 'flex'; }
})();
function dismissBanner() {
    document.getElementById('announce-banner').style.display = 'none';
    localStorage.setItem('jimi_banner_dismissed', '1');
}

// ── Heatmap Map ──────────────────────────────────────────────
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

// ── Charts ───────────────────────────────────────────────────
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
