<?php
/**
 * JIMI Webhook System — Relatório de Desatualizados v4.0.0
 * Rota: /relatorios/desatualizados
 *
 * Filtro: Cliente.
 * Resumo em faixas: <24h, >1d, >7d, >30d, Nunca posicionados.
 * Cada faixa com Detalhes + Export.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$filterCust = $_GET['customer_id'] ?? null;
$detailBucket = $_GET['bucket'] ?? null;

// Ordenação do detalhe: só por data/hora da última posição; default crescente
// (mais desatualizado no topo). "Nunca posicionados" (NULL) acompanha o extremo
// mais antigo — primeiro em ASC, último em DESC.
[$sort, $order] = report_sort_params(['last_gps_time'], 'last_gps_time', 'ASC');
$nullsOrder = $order === 'ASC' ? 'DESC' : 'ASC';
$detailOrderBy = "ORDER BY ds.last_gps_time IS NULL $nullsOrder, ds.last_gps_time $order";

$where = '';
$params = [];
if (!$isAdmin && !$filterCust) {
    if ($customerId) {
        $where = 'WHERE d.customer_id = :cid';
        $params[':cid'] = $customerId;
    }
} elseif ($filterCust) {
    $where = 'WHERE d.customer_id = :fcid';
    $params[':fcid'] = (int)$filterCust;
}

// Bucketização — última posição vem de device_statistics.last_gps_time
// (devices.last_position_at não existe no schema; ver Fase M.2)
$buckets = [
    'lt24h'  => ['label' => 'Menos de 24 horas', 'cond' => 'TIMESTAMPDIFF(HOUR, ds.last_gps_time, NOW()) BETWEEN 0 AND 23'],
    'gt1d'   => ['label' => 'Mais de 1 dia',     'cond' => 'TIMESTAMPDIFF(DAY, ds.last_gps_time, NOW()) BETWEEN 1 AND 6'],
    'gt7d'   => ['label' => 'Mais de 7 dias',    'cond' => 'TIMESTAMPDIFF(DAY, ds.last_gps_time, NOW()) BETWEEN 7 AND 29'],
    'gt30d'  => ['label' => 'Mais de 30 dias',   'cond' => 'TIMESTAMPDIFF(DAY, ds.last_gps_time, NOW()) >= 30'],
    'never'  => ['label' => 'Nunca posicionados', 'cond' => 'ds.last_gps_time IS NULL'],
];

$bucketCounts = [];
$total = 0;
try {
    foreach ($buckets as $key => $b) {
        $full = $where ? "$where AND {$b['cond']}" : "WHERE {$b['cond']}";
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM devices d
            LEFT JOIN customers c ON c.id = d.customer_id
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            $full");
        $stmt->execute($params);
        $bucketCounts[$key] = (int)$stmt->fetchColumn();
        $total += $bucketCounts[$key];
    }
} catch (Exception $e) {
    $bucketCounts = array_fill_keys(array_keys($buckets), 0);
}

$detailRows = [];
if ($detailBucket && isset($buckets[$detailBucket])) {
    try {
        $b = $buckets[$detailBucket];
        $full = $where ? "$where AND {$b['cond']}" : "WHERE {$b['cond']}";
        $stmt = $db->prepare("
            SELECT d.imei, d.device_name, ds.last_gps_time AS last_position_at, d.last_communication,
                   COALESCE(c.name, '—') as customer_name,
                   TIMESTAMPDIFF(HOUR, ds.last_gps_time, NOW()) as hours_since,
                   COALESCE(dm.model_name, '—') as model_name
            FROM devices d
            LEFT JOIN customers c ON c.id = d.customer_id
            LEFT JOIN device_models dm ON d.device_model_id = dm.id
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            $full
            $detailOrderBy
            LIMIT 200
        ");
        $stmt->execute($params);
        $detailRows = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// Export síncrono da faixa selecionada (padrão YUV: cada faixa com Detalhes + Export)
$export = $_GET['export'] ?? '';
if ($detailBucket && isset($buckets[$detailBucket]) && in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expRows = [];
    try {
        $b = $buckets[$detailBucket];
        $full = $where ? "$where AND {$b['cond']}" : "WHERE {$b['cond']}";
        $expStmt = $db->prepare("
            SELECT d.imei, d.device_name, ds.last_gps_time AS last_position_at,
                   COALESCE(c.name, '—') as customer_name,
                   TIMESTAMPDIFF(HOUR, ds.last_gps_time, NOW()) as hours_since,
                   COALESCE(dm.model_name, '—') as model_name
            FROM devices d
            LEFT JOIN customers c ON c.id = d.customer_id
            LEFT JOIN device_models dm ON d.device_model_id = dm.id
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            $full
            $detailOrderBy
            LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $expStmt->execute($params);
        while ($d = $expStmt->fetch()) {
            $expRows[] = [
                $d['imei'],
                $d['device_name'] ?? '—',
                $d['model_name'],
                $d['customer_name'],
                $d['last_position_at'] ? fmt_brt($d['last_position_at']) : 'Nunca',
                $d['hours_since'] !== null ? (int)$d['hours_since'] : '—',
            ];
        }
    } catch (Exception $e) { /* tabelas ausentes → export vazio */ }
    stream_export($export, 'desatualizados_' . $detailBucket,
        ['IMEI', 'Nome', 'Modelo', 'Cliente', 'Última Posição', 'Horas Desde'],
        $expRows, 'Desatualizados — ' . $buckets[$detailBucket]['label']);
}

$customers = $db->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();

$page_title = 'Relatório de Desatualizados';
$current_route = 'rel_desatualizados';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Desatualizados</h2>
</div>

<?php if ($isAdmin): ?>
<div class="card mb-24" style="padding:12px 16px;">
    <form method="GET" style="display:flex;align-items:flex-end;gap:10px;">
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
            <select name="customer_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:180px;">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filterCust==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
        <?php if ($filterCust): ?><a href="/relatorios/desatualizados" class="btn btn-outline btn-sm" style="color:var(--muted);">Limpar</a><?php endif; ?>
    </form>
</div>
<?php endif; ?>

<!-- Distribution bars -->
<div class="kpi-grid">
    <?php foreach ($buckets as $key => $b):
        $count = $bucketCounts[$key];
        $pct = $total > 0 ? round($count / $total * 100, 1) : 0;
        $colors = ['lt24h'=>'var(--success)','gt1d'=>'var(--primary)','gt7d'=>'var(--warning)','gt30d'=>'#f4b000','never'=>'var(--error)'];
    ?>
    <div class="kpi-item" style="cursor:pointer;" onclick="location.href='?bucket=<?= $key ?><?= $filterCust ? '&customer_id='.$filterCust : '' ?>'">
        <div class="kpi-item-label"><?= $b['label'] ?></div>
        <div class="kpi-item-value" style="color:<?= $colors[$key] ?? 'var(--ink)' ?>;font-size:24px;"><?= $count ?></div>
        <div class="kpi-item-delta"><?= $pct ?>% do total</div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($total > 0): ?>
<div class="card mb-16" style="padding:12px 16px;">
    <div style="display:flex;height:10px;border-radius:5px;overflow:hidden;margin-bottom:6px;">
        <?php foreach ($buckets as $key => $b):
            $pct = $total > 0 ? round($bucketCounts[$key] / $total * 100, 1) : 0;
            $colors = ['lt24h'=>'var(--success)','gt1d'=>'var(--primary)','gt7d'=>'var(--warning)','gt30d'=>'#f4b000','never'=>'var(--error)'];
        ?>
        <div style="width:<?= $pct ?>%;background:<?= $colors[$key] ?? 'var(--muted)' ?>;" title="<?= $b['label'] ?>: <?= $bucketCounts[$key] ?>"></div>
        <?php endforeach; ?>
    </div>
    <div style="font-size:11px;color:var(--muted);">Total: <?= $total ?> dispositivos</div>
</div>
<?php endif; ?>

<?php if ($detailBucket): ?>
<div class="flex-between mb-12">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);">
        Detalhes: <?= $buckets[$detailBucket]['label'] ?>
        <span style="font-size:12px;color:var(--muted);font-weight:400;">(<?= count($detailRows) ?>)</span>
    </h3>
    <div style="display:flex;gap:8px;">
        <?php $expQ = $_GET; unset($expQ['export']); $expBase = http_build_query($expQ); ?>
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?= report_back_button('/relatorios/desatualizados' . ($filterCust ? '?customer_id=' . urlencode($filterCust) : '')) ?>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead><tr><th>IMEI</th><th>Nome</th><th>Modelo</th><th>Cliente</th><th><?= report_sort_link('last_gps_time', 'Última Posição', $sort, $order) ?></th><th>Horas Desde</th></tr></thead>
        <tbody>
            <?php if (empty($detailRows)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted);">Nenhum dispositivo nesta faixa</td></tr>
            <?php else: foreach ($detailRows as $d): ?>
            <tr>
                <td><span class="text-mono"><?= htmlspecialchars($d['imei']) ?></span></td>
                <td><?= htmlspecialchars($d['device_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($d['model_name']) ?></td>
                <td><?= htmlspecialchars($d['customer_name']) ?></td>
                <td><?= $d['last_position_at'] ? fmt_brt($d['last_position_at']) : '<span class="badge badge-error">Nunca</span>' ?></td>
                <td><?= $d['hours_since'] !== null ? number_format($d['hours_since'], 0) . 'h' : '—' ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
