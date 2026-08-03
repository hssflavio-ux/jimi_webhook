<?php
/**
 * JIMI Webhook System — Relatório de Excesso de Velocidade v4.6.0
 * Rota: /relatorios/velocidade
 *
 * Fonte: speeding_events, produzida pelo scripts/state_builder.php.
 *
 * Excesso de velocidade não é um estado do veículo e sim um evento com limiar
 * próprio — por isso tem tabela separada de device_state_segments. O limite
 * vigente é resolvido por equipamento → cliente → padrão global de 80 km/h
 * (includes/fleet_state.php) e é GRAVADO no evento: quem audita precisa saber
 * contra qual limite a infração foi apurada, não contra o limite de hoje.
 *
 * Pontos consecutivos acima do limite formam um único evento; o piso de
 * MIN_SPEEDING_POINTS pontos descarta spike de GPS.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/report_templates.php';
require_once __DIR__ . '/../includes/geocode.php';   // endereço no lugar de lat/lng
// Salvar/aplicar/excluir modelo — antes de qualquer saída (as três ações redirecionam)
handle_template_actions('rel_velocidade', '/relatorios/velocidade');

require_once __DIR__ . '/../includes/fleet_state.php';

$page_title    = 'Relatório de Excesso de Velocidade';
$current_route = 'rel_velocidade';

$db         = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user       = get_jimi_user();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$dateFrom = $_GET['date_from'] ?? brt_today();
$dateTo   = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo);

$filterCust  = $_GET['customer_id'] ?? null;
$filterImei  = trim($_GET['imei'] ?? '');
// Excedente mínimo: separa quem passou 2 km/h do limite (margem de medição)
// de quem passou 30 — a pergunta de quem trata infração é sempre a segunda.
$minOver     = max(0, (int)($_GET['min_over'] ?? 0));
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 25;

$validSorts = ['started_at', 'max_speed', 'duration_s', 'imei'];
[$sort, $order] = report_sort_params($validSorts, 'started_at', 'ASC');

[$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo);
$where  = 'WHERE e.started_at BETWEEN :df AND :dt';
$params = [':df' => $utcFrom, ':dt' => $utcTo];

// Escopo multi-tenant centralizado (v4.7.3) — ver report_customer_scope()
$scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);
if ($scopeCust !== null) {
    $where .= ' AND e.customer_id = :cid';
    $params[':cid'] = $scopeCust;
}
if ($filterImei !== '') {
    $where .= ' AND e.imei LIKE :imei';
    $params[':imei'] = "%$filterImei%";
}
if ($minOver > 0) {
    $where .= ' AND (e.max_speed - e.limit_kmh) >= :minover';
    $params[':minover'] = $minOver;
}

$durExpr = 'COALESCE(e.duration_s, TIMESTAMPDIFF(SECOND, e.started_at, UTC_TIMESTAMP()))';

$from = "
    FROM speeding_events e
    LEFT JOIN devices d ON d.imei = e.imei
    LEFT JOIN customers c ON c.id = e.customer_id
    $where";

$selectCols = "
    SELECT e.id, e.imei, e.started_at, e.ended_at, $durExpr AS dur_s,
           e.max_speed, e.avg_speed, e.limit_kmh, e.point_count,
           (e.max_speed - e.limit_kmh) AS over_by,
           e.start_lat, e.start_lng, e.max_lat, e.max_lng,
           COALESCE(d.device_name, e.imei) AS device_label,
           COALESCE(c.name, '—') AS customer_name";

$orderBy = $sort === 'duration_s' ? "$durExpr $order" : "e.$sort $order";

// ── Export síncrono ────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';

    $expRows = [];
    try {
        $stmt = $db->prepare("$selectCols $from ORDER BY $orderBy LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $stmt->execute($params);
        // fetchAll antes do laço: endereço resolvido em UM lote paralelo
        $src = $stmt->fetchAll();
        $geoExp = geocode_map_rows($src, 'max_lat', 'max_lng', 2000);
        foreach ($src as $r) {
            $expRows[] = [
                fmt_brt($r['started_at'], 'd/m/Y H:i:s'),
                $r['ended_at'] ? fmt_brt($r['ended_at'], 'd/m/Y H:i:s') : 'Em curso',
                fmt_duration((int)$r['dur_s']),
                $r['device_label'],
                $r['imei'],
                $r['customer_name'],
                number_format((float)$r['max_speed'], 1, ',', ''),
                number_format((float)($r['avg_speed'] ?? 0), 1, ',', ''),
                (int)$r['limit_kmh'],
                number_format((float)$r['over_by'], 1, ',', ''),
                geocode_cell($geoExp, $r['max_lat'], $r['max_lng']),
            ];
        }
    } catch (Throwable $e) { /* tabela ausente → export vazio */ }

    stream_export($export, 'relatorio_velocidade',
        ['Início', 'Fim', 'Duração', 'Equipamento', 'IMEI', 'Cliente',
         'Vel. máxima (km/h)', 'Vel. média (km/h)', 'Limite (km/h)', 'Excedente (km/h)',
         'Endereço'],
        $expRows, 'Relatório de Excesso de Velocidade', report_period_label($dateFrom, $dateTo));
}

// ── Grade + KPIs ───────────────────────────────────────────────
$tableMissing = false;
$rows       = [];
$totalRows  = 0;
$totalPages = 1;
$kpi = ['total' => 0, 'devices' => 0, 'max' => 0, 'avg_over' => 0, 'sum_s' => 0];

try {
    $countStmt = $db->prepare("SELECT COUNT(*) $from");
    $countStmt->execute($params);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $offset     = ($page - 1) * $perPage;

    $dataStmt = $db->prepare("$selectCols $from ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll();

    $kpiStmt = $db->prepare("
        SELECT COUNT(*) AS total, COUNT(DISTINCT e.imei) AS devices,
               COALESCE(MAX(e.max_speed),0) AS max_speed,
               COALESCE(AVG(e.max_speed - e.limit_kmh),0) AS avg_over,
               COALESCE(SUM($durExpr),0) AS sum_s
        $from");
    $kpiStmt->execute($params);
    if ($k = $kpiStmt->fetch()) {
        $kpi = [
            'total'    => (int)$k['total'],
            'devices'  => (int)$k['devices'],
            'max'      => (float)$k['max_speed'],
            'avg_over' => (float)$k['avg_over'],
            'sum_s'    => (int)$k['sum_s'],
        ];
    }
} catch (Throwable $e) {
    $tableMissing = true;
}

$customers = [];
try {
    $customers = report_customer_options($db);
} catch (Throwable $e) {}

require_once __DIR__ . '/../web/layout_base.php';
?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Excesso de Velocidade</h2>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?php if (report_has_query()) echo report_back_button('/relatorios/velocidade'); ?>
    </div>
</div>

<?php if ($tableMissing): ?>
<div class="card mb-16" style="padding:12px 16px;border-left:3px solid var(--error);">
    <div style="font-size:13px;color:var(--muted);">
        <strong>Tabela de eventos de velocidade indisponível.</strong> Aplique a migração
        <code>v4.6.0</code> e rode <code>php scripts/state_builder.php 30</code>.
    </div>
</div>
<?php endif; ?>

<?php render_template_bar('rel_velocidade', '/relatorios/velocidade'); ?>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <?php if ($isAdmin): ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
            <select name="customer_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $filterCust == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">IMEI</label>
            <input type="text" name="imei" value="<?= htmlspecialchars($filterImei) ?>" placeholder="Buscar..."
                   style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:140px;">
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Excedente mínimo</label>
            <select name="min_over" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <?php foreach ([0 => 'Qualquer', 5 => '+5 km/h', 10 => '+10 km/h', 20 => '+20 km/h', 30 => '+30 km/h'] as $ov => $ol): ?>
                <option value="<?= $ov ?>" <?= $minOver === $ov ? 'selected' : '' ?>><?= $ol ?></option>
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
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
    </form>
</div>

<?php if ($rangeClamped): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid #f5a623;font-size:13px;color:var(--muted);">
    O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias:
    <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px;">
    <?php
    $kpiCards = [
        ['Infrações',        (string)$kpi['total']],
        ['Equipamentos',     (string)$kpi['devices']],
        ['Maior velocidade', number_format($kpi['max'], 1, ',', '.') . ' km/h'],
        ['Excedente médio',  '+' . number_format($kpi['avg_over'], 1, ',', '.') . ' km/h'],
        ['Tempo em excesso', fmt_duration($kpi['sum_s'], '0min')],
    ];
    foreach ($kpiCards as [$label, $value]):
    ?>
    <div class="card" style="padding:14px 18px;">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);"><?= $label ?></div>
        <div class="text-mono" style="font-size:22px;font-weight:500;color:var(--ink);margin-top:4px;"><?= htmlspecialchars($value) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th><?= report_sort_link('started_at', 'Início', $sort, $order) ?></th>
                <th><?= report_sort_link('duration_s', 'Duração', $sort, $order) ?></th>
                <th><?= report_sort_link('imei', 'Equipamento', $sort, $order) ?></th>
                <th>Cliente</th>
                <th><?= report_sort_link('max_speed', 'Vel. máxima', $sort, $order) ?></th>
                <th>Limite</th>
                <th>Excedente</th>
                <th>Mapa</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted);">Nenhum excesso de velocidade no período</td></tr>
            <?php else: foreach ($rows as $r):
                $lat = $r['max_lat'] ?: $r['start_lat'];
                $lng = $r['max_lng'] ?: $r['start_lng'];
                $hasCoords = $lat && $lng && is_valid_coordinate($lat, $lng);
                $over = (float)$r['over_by'];
                // Faixa de gravidade: até 10 km/h acima é margem de medição,
                // acima de 20 é conduta deliberada.
                $overBadge = $over >= 20 ? 'badge-error' : ($over >= 10 ? 'badge-warning' : 'badge');
            ?>
            <tr>
                <td class="text-mono"><?= fmt_brt($r['started_at'], 'd/m/Y H:i:s') ?></td>
                <td class="text-mono">
                    <?= htmlspecialchars(fmt_duration((int)$r['dur_s'])) ?>
                    <?php if ($r['ended_at'] === null): ?>
                        <span class="badge badge-info">em curso</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= htmlspecialchars($r['device_label']) ?>
                    <div class="text-mono text-muted" style="font-size:11px;"><?= htmlspecialchars($r['imei']) ?></div>
                </td>
                <td><?= htmlspecialchars($r['customer_name']) ?></td>
                <td class="text-mono"><?= number_format((float)$r['max_speed'], 1, ',', '.') ?> km/h</td>
                <td class="text-mono"><?= (int)$r['limit_kmh'] ?> km/h</td>
                <td><span class="badge <?= $overBadge ?> text-mono">+<?= number_format($over, 1, ',', '.') ?></span></td>
                <td>
                    <?php if ($hasCoords): ?>
                    <a href="https://www.openstreetmap.org/?mlat=<?= $lat ?>&mlon=<?= $lng ?>&zoom=16"
                       target="_blank" class="badge badge-primary">Ver Mapa</a>
                    <?php else: echo '—'; endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, 'infrações') ?>

<p class="text-muted" style="font-size:11px;margin-top:8px;">
    O limite exibido é o que estava vigente quando o evento foi apurado — mudar o limite do
    equipamento hoje não reescreve o histórico. A precedência é
    <strong>equipamento → cliente → padrão global de <?= DEFAULT_SPEED_LIMIT_KMH ?> km/h</strong>;
    configure em <a href="/equipamentos">Equipamentos</a> e <a href="/clientes">Clientes</a>.
    O ponto do mapa é onde a velocidade máxima foi registrada.
</p>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
