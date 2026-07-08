<?php
/**
 * JIMI Webhook System — BI v4.0.0
 * Rota: /bi
 *
 * Gerador de análises sob demanda.
 * Filtros: Cliente, Ativos, Motoristas, Alarmes (multi-select chips), Período + [Gerar].
 * Gráficos: barras, pizza, linha.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$filterCust     = $_GET['customer_id'] ?? '';
$filterImei     = $_GET['imei'] ?? '';
$filterMotorista = $_GET['driver_id'] ?? '';
$filterAlarmes  = isset($_GET['alarm_types']) ? explode(',', $_GET['alarm_types']) : [];
$dateFrom       = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo         = $_GET['date_to'] ?? date('Y-m-d');
$generated      = !empty($_GET['gerar']);

// Download data
$chartData = [];
if ($generated) {
    try {
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
    if ($filterMotorista) {
        $cWhere .= ' AND o.driver_id = :driver_id';
        $cParams[':driver_id'] = (int)$filterMotorista;
    }
    if (!empty($filterAlarmes)) {
        $placeholders = [];
        foreach ($filterAlarmes as $i => $at) {
            $key = ':at' . $i;
            $placeholders[] = $key;
            $cParams[$key] = $at;
        }
        $cWhere .= ' AND a.alarm_type IN (' . implode(',', $placeholders) . ')';
    }
    $cParams[':df'] = $dateFrom . ' 00:00:00';
    $cParams[':dt'] = $dateTo . ' 23:59:59';

    // Alarms by type (top 10)
    $alarmsByType = $db->prepare("
        SELECT alarm_type, COUNT(*) as cnt
        FROM alarms a
        LEFT JOIN devices d ON d.imei = a.imei
        WHERE a.alarm_time BETWEEN :df AND :dt $cWhere
        GROUP BY alarm_type ORDER BY cnt DESC LIMIT 10
    ");
    $alarmsByType->execute($cParams);
    $chartData['alarms_by_type'] = $alarmsByType->fetchAll();

    // Occurrences by risk
    $occByRisk = $db->prepare("
        SELECT o.risk, COUNT(*) as cnt
        FROM occurrences o
        LEFT JOIN devices d ON d.imei = o.imei
        WHERE o.last_alarm_at BETWEEN :df AND :dt "
        . str_replace('a.', 'o.', str_replace('a.customer_id', 'o.customer_id', str_replace('a.alarm_type', 'o.alarm_type', $cWhere)))
    );
    $cParams2 = $cParams;
    $occByRisk->execute($cParams2);
    $chartData['occ_by_risk'] = $occByRisk->fetchAll();

    // Alarms by day
    $alarmsByDay = $db->prepare("
        SELECT DATE(alarm_time) as dt, COUNT(*) as cnt
        FROM alarms a
        LEFT JOIN devices d ON d.imei = a.imei
        WHERE a.alarm_time BETWEEN :df AND :dt $cWhere
        GROUP BY DATE(alarm_time) ORDER BY dt
    ");
    $alarmsByDay->execute($cParams);
    $chartData['alarms_by_day'] = $alarmsByDay->fetchAll();

    // Drivers with most alarms (if driver filter inactive)
    if (!$filterMotorista) {
        $drByStmt = $db->prepare("
            SELECT COALESCE(dr.name, 'Desconhecido') as driver, COUNT(*) as cnt
            FROM occurrences o
            LEFT JOIN drivers dr ON dr.id = o.driver_id
            LEFT JOIN devices d ON d.imei = o.imei
            WHERE o.last_alarm_at BETWEEN :df AND :dt " . str_replace('a.', 'o.', str_replace('a.customer_id', 'o.customer_id', $cWhere)) . "
            GROUP BY dr.id ORDER BY cnt DESC LIMIT 10
        ");
        $drByStmt->execute($cParams2);
        $chartData['alarms_by_driver'] = $drByStmt->fetchAll();
    }
    } catch (Exception $e) {}
}

$customers = $db->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();
$devices = $db->prepare("SELECT imei, device_name FROM devices WHERE customer_id = :cid ORDER BY device_name");
$devices->execute([':cid' => $customerId ?? 1]);
$devices = $devices->fetchAll();

$drivers = [];
try {
    $drivers = $db->prepare("SELECT id, name FROM drivers WHERE customer_id = :cid AND is_active = 1 ORDER BY name");
    $drivers->execute([':cid' => $customerId ?? 1]);
    $drivers = $drivers->fetchAll();
} catch (Exception $e) {}

// All alarm types for multi-select
$allAlarmTypes = $db->query("SELECT DISTINCT alarm_type FROM alarms ORDER BY alarm_type")->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'BI';
$current_route = 'bi';
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.chart-box{position:relative;height:300px;margin-top:16px;}
.alarm-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:12px;font-size:11px;cursor:pointer;border:1px solid var(--hairline);background:#fff;color:var(--body);transition:all .15s;}
.alarm-chip.selected{background:var(--primary);color:#fff;border-color:var(--primary);}
.alarm-chip .chip-close{font-size:14px;opacity:.7;}
.alarm-chips-wrapper{display:flex;flex-wrap:wrap;gap:4px;max-height:80px;overflow:hidden;position:relative;}
.alarm-chips-wrapper.expanded{max-height:none;}
.chips-overflow{display:inline-flex;align-items:center;padding:3px 8px;border-radius:12px;font-size:11px;cursor:pointer;background:var(--muted-soft);color:var(--muted);border:none;}
.chip-count{display:none;font-size:11px;color:var(--muted);margin-top:4px;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Business Intelligence</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">Gerador de análises sob demanda com filtros configuráveis</p>
    </div>
</div>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" id="bi-form">
        <input type="hidden" name="gerar" value="1">
        <input type="hidden" name="alarm_types" id="alarm-types-hidden" value="<?= htmlspecialchars(implode(',', $filterAlarmes)) ?>">

        <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;margin-bottom:12px;">
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
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Motorista</label>
                <select name="driver_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:160px;">
                    <option value="">Todos</option>
                    <?php foreach ($drivers as $dr): ?>
                    <option value="<?= $dr['id'] ?>" <?= $filterMotorista==$dr['id']?'selected':'' ?>><?= htmlspecialchars($dr['name']) ?></option>
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
        </div>

        <!-- Multi-select Alarm Chips -->
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;margin-bottom:6px;">Tipos de Alarme</label>
            <div class="alarm-chips-wrapper" id="alarm-chips-wrapper">
                <?php
                $shownAlarms = array_slice($allAlarmTypes, 0, 15);
                $hiddenCount = count($allAlarmTypes) - 15;
                foreach ($allAlarmTypes as $at):
                    $sel = in_array($at, $filterAlarmes);
                    $hidden = !in_array($at, $shownAlarms);
                ?>
                <span class="alarm-chip<?= $sel?' selected':'' ?><?= $hidden?' hidden-chip':'' ?>" data-type="<?= htmlspecialchars($at) ?>" onclick="toggleChip(this)" style="<?= $hidden?'display:none;':'' ?>">
                    <?= htmlspecialchars($at) ?>
                </span>
                <?php endforeach; ?>
                <?php if ($hiddenCount > 0): ?>
                <button type="button" class="chips-overflow" onclick="expandChips()" id="chips-expand">+<?= $hiddenCount ?></button>
                <?php endif; ?>
            </div>
            <div class="chip-count" id="chip-count"></div>
        </div>
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

<?php if (!empty($chartData['alarms_by_driver'])): ?>
<!-- Top Motoristas -->
<div class="card mb-24" style="padding:16px;">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;">Top Motoristas por Ocorrências</h4>
    <div class="chart-box"><canvas id="chart-driver-bar"></canvas></div>
</div>
<?php endif; ?>
<?php else: ?>
<div class="empty-state" style="padding:60px 24px;">
    <h3 style="font-size:18px;">Configure os filtros e clique em Gerar Análise</h3>
    <p style="font-size:13px;margin-top:6px;">Os gráficos serão renderizados com base nos filtros selecionados. Use os chips para filtrar por tipos específicos de alarme.</p>
</div>
<?php endif; ?>

<script>
// ── Alarm Chips Multi-Select ─────────────────────────────────
var selectedAlarms = <?= json_encode($filterAlarmes) ?>;
function toggleChip(el) {
    var type = el.dataset.type;
    el.classList.toggle('selected');
    var idx = selectedAlarms.indexOf(type);
    if (idx >= 0) selectedAlarms.splice(idx, 1);
    else selectedAlarms.push(type);
    document.getElementById('alarm-types-hidden').value = selectedAlarms.join(',');
    updateChipCount();
}
function expandChips() {
    var wrapper = document.getElementById('alarm-chips-wrapper');
    wrapper.classList.add('expanded');
    document.getElementById('chips-expand').style.display = 'none';
    document.querySelectorAll('.hidden-chip').forEach(function(c) { c.style.display = 'inline-flex'; });
}
function updateChipCount() {
    var el = document.getElementById('chip-count');
    if (selectedAlarms.length > 0) {
        el.style.display = 'block';
        el.textContent = selectedAlarms.length + ' tipo(s) selecionado(s)';
    } else {
        el.style.display = 'none';
    }
}
updateChipCount();

<?php if ($generated): ?>
// ── Charts ───────────────────────────────────────────────────
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

    <?php if (!empty($chartData['alarms_by_driver'])): ?>
    // Top Drivers
    <?php $drLabels=[]; $drVals=[];
    foreach ($chartData['alarms_by_driver'] as $r) { $drLabels[]=$r['driver']; $drVals[]=(int)$r['cnt']; } ?>
    new Chart(document.getElementById('chart-driver-bar'), {
        type: 'bar', data: {
            labels: <?= json_encode($drLabels) ?>,
            datasets: [{ data: <?= json_encode($drVals) ?>, backgroundColor: bgColors, borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { ticks: { font: { size: 10 } }, grid: { color: '#eef0f3' } }, y: { ticks: { font: { size: 10 } }, grid: { display: false } } }
        }
    });
    <?php endif; ?>
})();
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
