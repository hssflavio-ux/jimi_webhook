<?php
/**
 * JIMI Webhook System — BI v4.0.0
 * Rota: /bi
 *
 * Gerador de análises sob demanda.
 * Filtros: Cliente, Ativos, Motoristas, Eventos (chips, por NOME), Período + [Gerar].
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
// NOMES de evento (CSV dos chips), não códigos. O `array_filter` não é
// enfeite: o formulário envia o campo mesmo vazio, `explode(',', '')` devolve
// `['']`, e `!empty([''])` é verdadeiro — o filtro virava `IN ('')` e zerava
// TODOS os gráficos em toda análise gerada sem chip marcado.
$filterAlarmes  = array_values(array_filter(array_map('trim', explode(',', $_GET['alarm_types'] ?? ''))));
$dateFrom       = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo         = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo); // teto global 31 dias
$generated      = !empty($_GET['gerar']);

// Nome do EVENTO resolvido na LEITURA, pelo helper compartilhado (v4.9.19) —
// o mesmo ponto único que o Relatório de Alarmes, `relatorios.php` e
// `ativo_detalhe.php` já usavam. O BI era a última tela que agrupava, rotulava
// e filtrava pelo CÓDIGO CRU: o gráfico dizia "264" e "45" onde as outras
// telas diziam "Fadiga do Motorista" e "Capotamento", e os chips ofereciam
// números para o operador escolher.
['joins' => $alarmJoins, 'expr' => $alarmExpr, 'diag' => $diagExpr] = alarm_label_sql();

// Download data
$chartData = [];
$chartError = false;
if ($generated) {
    try {
        // DOIS conjuntos de filtro, e não um só reescrito com `str_replace`:
        // `alarms` (alias `a`) e `occurrences` (alias `o`) não têm as mesmas
        // colunas, então trocar `a.` por `o.` numa string produzia SQL que ora
        // funcionava, ora referenciava coluna inexistente. Como o `catch` desta
        // função era mudo, o erro virava "gráfico vazio" — indistinguível de
        // "não houve alarme no período" e sem uma linha em log nenhum.
        $aWhere = ''; $aParams = [];   // alarms
        $oWhere = ''; $oParams = [];   // occurrences

        // Dias digitados são BRT; colunas do banco são UTC
        [$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo);
        $aParams[':df'] = $utcFrom; $aParams[':dt'] = $utcTo;
        $oParams[':df'] = $utcFrom; $oParams[':dt'] = $utcTo;

        // Escopo multi-tenant pelo ponto único (ver report_customer_scope):
        // para não-admin o `?customer_id` da URL é ignorado, não validado.
        // 🔴 `alarms` NÃO TEM `customer_id` — o ramo do admin filtrava por
        // `a.customer_id` e derrubava a consulta inteira em "Unknown column".
        // O cliente vem de `devices`, que é onde ele existe.
        $scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);
        if ($scopeCust !== null) {
            $aWhere .= ' AND d.customer_id = :cid';
            $oWhere .= ' AND o.customer_id = :cid';
            $aParams[':cid'] = $scopeCust;
            $oParams[':cid'] = $scopeCust;
        }
        if ($filterImei) {
            $aWhere .= ' AND a.imei = :imei'; $aParams[':imei'] = $filterImei;
            $oWhere .= ' AND o.imei = :imei'; $oParams[':imei'] = $filterImei;
        }
        // Motorista é cadastro, e cadastro só está em `occurrences`
        // (`alarms.driver_id` é o identificador que o device manda, outra
        // coisa). O filtro ia junto para as consultas de alarme e quebrava as
        // duas.
        if ($filterMotorista) {
            $oWhere .= ' AND o.driver_id = :driver_id';
            $oParams[':driver_id'] = (int)$filterMotorista;
        }
        // Filtro por NOME do evento. Em `alarms` casa contra o nome RESOLVIDO,
        // não contra a coluna crua: senão o evento que o gráfico mostra como
        // "Capotamento" sumiria ao marcar o próprio chip, porque em
        // `alarms.alarm_name` ele pode estar congelado como "Código 1047 (JTT)"
        // (ver alarm_label_sql). Em `occurrences` a coluna `alarm_type` JÁ
        // guarda o nome — e era exatamente por isso que marcar qualquer chip
        // zerava os dois gráficos de ocorrência: comparava-se código com nome.
        if ($filterAlarmes) {
            $ph = [];
            foreach ($filterAlarmes as $i => $nome) {
                $ph[] = ":at$i";
                $aParams[":at$i"] = $nome;
                $oParams[":at$i"] = $nome;
            }
            $in = implode(',', $ph);
            $aWhere .= " AND ($alarmExpr) IN ($in)";
            $oWhere .= " AND o.alarm_type IN ($in)";
        }

        // Top 10 eventos, rotulados pelo NOME.
        // Fora os eventos de DIAGNÓSTICO (v4.9.9): o BI descreve a operação.
        // Com eles, o "top 10 de alarmes" era a lista de defeitos de equipamento.
        $alarmsByType = $db->prepare("
            SELECT ($alarmExpr) AS alarm_label, COUNT(*) AS cnt
            FROM alarms a
            LEFT JOIN devices d ON d.imei = a.imei
            $alarmJoins
            WHERE a.alarm_time BETWEEN :df AND :dt AND ($diagExpr) = 0 $aWhere
            GROUP BY alarm_label ORDER BY cnt DESC LIMIT 10
        ");
        $alarmsByType->execute($aParams);
        $chartData['alarms_by_type'] = $alarmsByType->fetchAll();

        // Ocorrências por risco. O `GROUP BY` faltava: sem ele a consulta
        // devolvia uma linha só (ou erro, com ONLY_FULL_GROUP_BY) e a rosca de
        // três fatias virava uma.
        $occByRisk = $db->prepare("
            SELECT o.risk, COUNT(*) AS cnt
            FROM occurrences o
            WHERE o.last_alarm_at BETWEEN :df AND :dt $oWhere
            GROUP BY o.risk
        ");
        $occByRisk->execute($oParams);
        $chartData['occ_by_risk'] = $occByRisk->fetchAll();

        // Alarmes por dia — dias em BRT (banco em UTC)
        $alarmsByDay = $db->prepare("
            SELECT DATE(CONVERT_TZ(a.alarm_time, '+00:00', '-03:00')) AS dt, COUNT(*) AS cnt
            FROM alarms a
            LEFT JOIN devices d ON d.imei = a.imei
            $alarmJoins
            WHERE a.alarm_time BETWEEN :df AND :dt AND ($diagExpr) = 0 $aWhere
            GROUP BY dt ORDER BY dt
        ");
        $alarmsByDay->execute($aParams);
        $chartData['alarms_by_day'] = $alarmsByDay->fetchAll();

        // Motoristas com mais ocorrências (só quando não há motorista escolhido)
        if (!$filterMotorista) {
            $drByStmt = $db->prepare("
                SELECT COALESCE(dr.name, 'Desconhecido') AS driver, COUNT(*) AS cnt
                FROM occurrences o
                LEFT JOIN drivers dr ON dr.id = o.driver_id
                WHERE o.last_alarm_at BETWEEN :df AND :dt $oWhere
                GROUP BY dr.id ORDER BY cnt DESC LIMIT 10
            ");
            $drByStmt->execute($oParams);
            $chartData['alarms_by_driver'] = $drByStmt->fetchAll();
        }
    } catch (Throwable $e) {
        // Nunca mais em silêncio: os defeitos acima sobreviveram porque o
        // `catch` vazio os exibia como "nenhum dado no período".
        $chartData = [];
        $chartError = true;
        Logger::error('Falha ao gerar os gráficos do BI', [
            'source' => 'bi', 'error' => $e->getMessage(),
        ]);
    }
}

$customers = report_customer_options($db);
$devices = $db->prepare("SELECT imei, device_name FROM devices WHERE customer_id = :cid ORDER BY device_name");
$devices->execute([':cid' => $customerId ?? 1]);
$devices = $devices->fetchAll();

$drivers = [];
try {
    $drivers = $db->prepare("SELECT id, name FROM drivers WHERE customer_id = :cid AND is_active = 1 ORDER BY name");
    $drivers->execute([':cid' => $customerId ?? 1]);
    $drivers = $drivers->fetchAll();
} catch (Exception $e) {}

// Chips do multiselect: NOMES de evento, na mesma resolução dos gráficos.
// Sem os de diagnóstico (v4.9.9) — oferecer um filtro para algo que os
// gráficos não contam mais só produz consulta vazia.
//
// A lista continua vindo do que ACONTECEU (e não do catálogo inteiro, como no
// Relatório de Alarmes) porque aqui ela alimenta gráficos de contagem: chip de
// evento que nunca ocorreu produziria gráfico vazio por construção.
$allAlarmTypes = [];
try {
    $allAlarmTypes = $db->query(
        "SELECT DISTINCT ($alarmExpr) AS alarm_label
           FROM alarms a $alarmJoins
          WHERE ($diagExpr) = 0
          ORDER BY alarm_label"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    Logger::error('Falha ao montar os chips de evento do BI', [
        'source' => 'bi', 'error' => $e->getMessage(),
    ]);
}

$page_title = 'BI';
$current_route = 'bi';
// Os chips passam a ser o componente compartilhado (extraído DESTA tela na
// v4.2.0 e nunca reaproveitado aqui) — com ele some a cópia de CSS/JS que
// vivia só neste arquivo e que já divergia da do Relatório de Alarmes.
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.chart-box{position:relative;height:300px;margin-top:16px;}
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
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Período (máx. <?= REPORT_RANGE_MAX_DAYS ?> dias)</label>
                <div style="display:flex;gap:4px;">
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 24px;">Gerar Análise</button>
        </div>

        <?php
        // Multiselect de eventos — mesmo componente do Relatório de Alarmes.
        $msel_id       = 'bialarmtypes';
        $msel_label    = 'Tipos de Evento';
        $msel_param    = 'alarm_types';
        $msel_options  = $allAlarmTypes;
        $msel_selected = $filterAlarmes;
        $msel_vazio    = 'Todos os tipos';
        include __DIR__ . '/../web/components/select_multi.php';
        ?>
    </form>
</div>

<?php if ($rangeClamped): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid #f5a623;font-size:13px;color:var(--muted);">
    O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias: <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
</div>
<?php endif; ?>

<?php if ($chartError): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid var(--error);font-size:13px;color:var(--muted);">
    Não foi possível gerar os gráficos com estes filtros. O erro está no log do sistema.
</div>
<?php endif; ?>

<?php if ($generated): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
    <!-- Eventos por Tipo -->
    <div class="card" style="padding:16px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;">Top 10 Eventos</h4>
        <div class="chart-box"><canvas id="chart-alarm-types"></canvas></div>
    </div>

    <!-- Ocorrências por Risco -->
    <div class="card" style="padding:16px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;">Ocorrências por Risco</h4>
        <div class="chart-box"><canvas id="chart-risk-pie"></canvas></div>
    </div>
</div>

<!-- Eventos por Dia -->
<div class="card mb-24" style="padding:16px;">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;">Eventos por Dia</h4>
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
    <p style="font-size:13px;margin-top:6px;">Os gráficos serão renderizados com base nos filtros selecionados. Use os chips para filtrar por eventos específicos.</p>
</div>
<?php endif; ?>

<script>
<?php if ($generated): ?>
// ── Charts ───────────────────────────────────────────────────
(function() {
    var bgColors = ['#0052ff','#05b169','#f4b000','#cf202f','#7c3aed','#0ea5e9','#f97316','#84cc16','#ec4899','#6b7280'];

    // Top 10 eventos — rótulo é o NOME resolvido, não o código
    <?php $labels = []; $values = [];
    foreach (($chartData['alarms_by_type'] ?? []) as $r) { $labels[] = $r['alarm_label']; $values[] = (int)$r['cnt']; } ?>
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
    foreach (($chartData['occ_by_risk'] ?? []) as $r) { $pLabels[]=occurrence_risk_label($r['risk']); $pVals[]=(int)$r['cnt']; } ?>
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
    foreach (($chartData['alarms_by_day'] ?? []) as $r) { $dLabels[]=$r['dt']; $dVals[]=(int)$r['cnt']; } ?>
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
