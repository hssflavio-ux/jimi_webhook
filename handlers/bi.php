<?php
/**
 * JIMI Webhook System — BI v4.0.0
 * Rota: /bi
 *
 * Gerador de análises sob demanda.
 * Filtros: Cliente, Ativos, Motoristas, Alarmes, Período + [Gerar].
 * Gráficos: barras, pizza.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$filterCust = $_GET['customer_id'] ?? '';
$filterImei = $_GET['imei'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$generated = !empty($_GET['gerar']);

// Download data
$chartData = [];
if ($generated) {
    $cWhere = '';
    $cParams = [];

    if ($isAdmin && $filterCust) {
        $cWhere .= ' AND a.customer_id = :fcid';
        $cParams[':fcid'] = (int)$filterCust;
    } elseif (!$isAdmin && $customerId) {
        $cWhere .= ' AND d.customer_id = :cid';
        $cParams[':cid'] = $customerId;
    }
    if ($filterImei) {
        $cWhere .= ' AND a.imei = :imei';
        $cParams[':imei'] = $filterImei;
    }
    $cParams[':df'] = $dateFrom . ' 00:00:00';
    $cParams[':dt'] = $dateTo . ' 23:59:59';

    // Alarms by type (top 10)
    $alarmsByType = $db->prepare("
        SELECT alarm_type, COUNT(*) as cnt
        FROM alarms a
        LEFT JOIN devices d ON d.imei = a.imei
        WHERE a.alarm_time BETWEEN :df AND :dt $cWhere
        GROUP BY alarm_type
        ORDER BY cnt DESC LIMIT 10
    ");
    $alarmsByType->execute($cParams);
    $chartData['alarms_by_type'] = $alarmsByType->fetchAll();

    // Occurrences by risk
    $occByRisk = $db->prepare("
        SELECT risk, COUNT(*) as cnt
        FROM occurrences o
        LEFT JOIN devices d ON d.imei = o.imei
        WHERE o.last_alarm_at BETWEEN :df AND :dt " . str_replace('a.', 'o.', str_replace('a.customer_id', 'o.customer_id', $cWhere)) . "
        GROUP BY risk
    ");
    $cParams2 = $cParams;
    $occByRisk->execute($cParams2);
    $chartData['occ_by_risk'] = $occByRisk->fetchAll();

    // Alarms by day
    $alarmsByDay = $db->prepare("
        SELECT DATE(alarm_time) as dt, COUNT(*) as cnt
        FROM alarms a
        LEFT JOIN devices d ON d.imei = a.imei
        WHERE a.alarm_time BETWEEN :df AND :dt $cWhere
        GROUP BY DATE(alarm_time)
        ORDER BY dt
    ");
    $alarmsByDay->execute($cParams);
    $chartData['alarms_by_day'] = $alarmsByDay->fetchAll();
}

$customers = $db->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();
$devices = $db->prepare("SELECT imei, device_name FROM devices WHERE customer_id = :cid ORDER BY device_name");
$devices->execute([':cid' => $customerId ?? 1]);
$devices = $devices->fetchAll();

$page_title = 'BI';
$current_route = 'bi';
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>.chart-box{position:relative;height:300px;margin-top:16px;}</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Business Intelligence</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">Gerador de análises sob demanda com filtros configuráveis</p>
    </div>
</div>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <input type="hidden" name="gerar" value="1">
        <?php if ($isAdmin): ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
            <select name="customer_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:160px;">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filterCust==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Ativo</label>
            <select name="imei" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:160px;">
                <option value="">Todos</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>" <?= $filterImei==$d['imei']?'selected':'' ?>><?= htmlspecialchars($d['device_name']??$d['imei']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Período</label>
            <div style="display:flex;gap:4px;">
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 24px;">Gerar Análise</button>
    </form>
</div>

<?php if ($generated): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
    <!-- Alarmes por Tipo -->
    <div class="card" style="padding:16px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;">Top 10 Alarmes por Tipo</h4>
        <div class="chart-box"><canvas id="chart-alarm-types"></canvas></div>
    </div>

    <!-- Ocorrências por Risco -->
    <div class="card" style="padding:16px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;">Ocorrências por Risco</h4>
        <div class="chart-box"><canvas id="chart-risk-pie"></canvas></div>
    </div>
</div>

<!-- Alarmes por Dia -->
<div class="card mb-24" style="padding:16px;">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;">Alarmes por Dia</h4>
    <div class="chart-box" style="height:250px;"><canvas id="chart-alarm-daily"></canvas></div>
</div>
<?php else: ?>
<div class="empty-state" style="padding:60px 24px;">
    <h3 style="font-size:18px;">Configure os filtros e clique em Gerar Análise</h3>
    <p style="font-size:13px;margin-top:6px;">Os gráficos serão renderizados com base nos filtros selecionados.</p>
</div>
<?php endif; ?>

<?php if ($generated): ?>
<script>
(function() {
    var bgColors = ['#0052ff','#05b169','#f4b000','#cf202f','#7c3aed','#0ea5e9','#f97316','#84cc16','#ec4899','#6b7280'];

    // Alarm by Type
    <?php $labels = []; $values = [];
    foreach ($chartData['alarms_by_type'] as $r) { $labels[] = $r['alarm_type']; $values[] = (int)$r['cnt']; } ?>
    new Chart(document.getElementById('chart-alarm-types'), {
        type: 'bar', data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [{ data: <?= json_encode($values) ?>, backgroundColor: bgColors, borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { ticks: { font: { size: 10 } }, grid: { color: '#eef0f3' } }, y: { ticks: { font: { size: 10 } }, grid: { display: false } } }
        }
    });

    // Risk Pie
    <?php $pLabels=[]; $pVals=[]; $colors=['baixo'=>'#0052ff','medio'=>'#f4b000','alto'=>'#cf202f'];
    foreach ($chartData['occ_by_risk'] as $r) { $pLabels[]=ucfirst($r['risk']); $pVals[]=(int)$r['cnt']; } ?>
    new Chart(document.getElementById('chart-risk-pie'), {
        type: 'doughnut', data: {
            labels: <?= json_encode($pLabels) ?>,
            datasets: [{ data: <?= json_encode($pVals) ?>, backgroundColor: ['#0052ff','#f4b000','#cf202f'] }]
        },
        options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }
        }
    });

    // Daily alarms
    <?php $dLabels=[]; $dVals=[];
    foreach ($chartData['alarms_by_day'] as $r) { $dLabels[]=$r['dt']; $dVals[]=(int)$r['cnt']; } ?>
    new Chart(document.getElementById('chart-alarm-daily'), {
        type: 'line', data: {
            labels: <?= json_encode($dLabels) ?>,
            datasets: [{ data: <?= json_encode($dVals) ?>, borderColor: '#0052ff', backgroundColor: 'rgba(0,82,255,0.06)', fill: true, tension: 0.3, pointRadius: 2 }]
        },
        options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { x: { ticks: { font: { size: 10 }, maxTicksLimit: 15 }, grid: { display: false } }, y: { beginAtZero: true, ticks: { font: { size: 10 } }, grid: { color: '#eef0f3' } } }
        }
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
