<?php
/**
 * JIMI Webhook System — Relatório de Alarmes v4.0.0
 * Rota: /relatorios/alarmes
 *
 * Filtros: Clientes, Equipamentos (IMEI), Tipo de Alarme, Período.
 * Grid: Cliente, IMEI, Tipo de Alarme, Nome, Data, Protocolo, Status.
 * Paginação server-side, volume alto (~448 alarmes/dia).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = 'Relatório de Alarmes';
$current_route = 'rel_alarmes';
$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$dateFrom    = $_GET['date_from'] ?? brt_today();
$dateTo      = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo); // teto global 31 dias
$filterCust  = $_GET['customer_id'] ?? null;
$filterImei  = $_GET['imei'] ?? null;
// Multiselect de tipos (chips, CSV) + retrocompat com o antigo campo texto alarm_type
$filterTypes = array_values(array_filter(array_map('trim', explode(',', $_GET['alarm_types'] ?? ''))));
$filterType  = $_GET['alarm_type'] ?? null;
$filterBranch = $_GET['branch_id'] ?? null;
$filterStatus = $_GET['alarm_status'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// Ordenação: whitelist de colunas + default crescente por data/hora
$validSorts = ['alarm_time', 'alarm_type', 'alarm_name', 'imei'];
[$sort, $order] = report_sort_params($validSorts, 'alarm_time', 'ASC');

$where = 'WHERE a.alarm_time BETWEEN :df AND :dt';
[$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo); // dias BRT → janela UTC
$params = [':df' => $utcFrom, ':dt' => $utcTo];

if (!$isAdmin && !$filterCust) {
    if ($customerId) {
        $where .= ' AND d.customer_id = :cid';
        $params[':cid'] = $customerId;
    }
} elseif ($filterCust) {
    $where .= ' AND d.customer_id = :fcid';
    $params[':fcid'] = (int)$filterCust;
}
if ($filterImei) {
    $where .= ' AND a.imei LIKE :imei';
    $params[':imei'] = "%$filterImei%";
}
if ($filterTypes) {
    $ph = [];
    foreach ($filterTypes as $i => $t) {
        $ph[] = ":at$i";
        $params[":at$i"] = $t;
    }
    $where .= ' AND a.alarm_name IN (' . implode(',', $ph) . ')';
} elseif ($filterType) {
    $where .= ' AND (a.alarm_type = :atype OR a.alarm_name LIKE :aname)';
    $params[':atype'] = $filterType;
    $params[':aname'] = "%$filterType%";
}
if ($filterBranch) {
    $where .= ' AND d.branch_id = :bid';
    $params[':bid'] = (int)$filterBranch;
}
if ($filterStatus) {
    $where .= ' AND a.status = :st';
    $params[':st'] = $filterStatus;
}

// Export síncrono (padrão YUV §9.2): mesma query da grade, sem paginação
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expStmt = $db->prepare("
        SELECT a.imei, a.alarm_type, a.alarm_name, a.alarm_time, a.status, a.msg_class,
               a.speed, a.latitude, a.longitude, COALESCE(c.name, '—') as customer_name
        FROM alarms a
        LEFT JOIN devices d ON d.imei = a.imei
        LEFT JOIN customers c ON c.id = d.customer_id
        $where
        ORDER BY a.$sort $order
        LIMIT " . SYNC_EXPORT_MAX_ROWS);
    $expStmt->execute($params);
    $expRows = [];
    while ($r = $expStmt->fetch()) {
        $expRows[] = [
            fmt_brt($r['alarm_time'], 'd/m/Y H:i:s'),
            $r['customer_name'],
            $r['imei'],
            $r['alarm_type'],
            $r['alarm_name'] ?? '—',
            ((int)($r['msg_class'] ?? 0) === 0 ? 'JIMI' : 'JT/T'),
            $r['speed'] !== null ? number_format((float)$r['speed'], 1) : '—',
            $r['status'],
            $r['latitude'],
            $r['longitude'],
        ];
    }
    stream_export($export, 'relatorio_alarmes',
        ['Data/Hora', 'Cliente', 'IMEI', 'Código', 'Nome do Alarme', 'Protocolo', 'Velocidade (km/h)', 'Status', 'Latitude', 'Longitude'],
        $expRows, 'Relatório de Alarmes', "Período (BRT): $dateFrom a $dateTo");
}

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) FROM alarms a
    LEFT JOIN devices d ON d.imei = a.imei
    $where
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

// Data
$dataStmt = $db->prepare("
    SELECT a.id, a.imei, a.alarm_type, a.alarm_name, a.alarm_time,
           a.status, a.msg_class, a.speed, a.latitude, a.longitude,
           d.device_model,
           COALESCE(c.name, '—') as customer_name
    FROM alarms a
    LEFT JOIN devices d ON d.imei = a.imei
    LEFT JOIN customers c ON c.id = d.customer_id
    $where
    ORDER BY a.$sort $order
    LIMIT $perPage OFFSET $offset
");
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll();

// Dropdowns
$custStmt = $db->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name");
$customers = $custStmt->fetchAll();

$types = $db->query("SELECT DISTINCT alarm_name FROM alarms WHERE alarm_name IS NOT NULL ORDER BY alarm_name")->fetchAll();

$branchList = [];
try {
    $branchList = $db->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();
} catch (Exception $e) {}

require_once __DIR__ . '/../web/layout_base.php';
?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Alarmes</h2>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?php if (report_has_query()) echo report_back_button('/relatorios/alarmes'); ?>
    </div>
</div>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <?php if ($isAdmin): ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
            <select name="customer_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filterCust == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">IMEI</label>
            <input type="text" name="imei" value="<?= htmlspecialchars($filterImei ?? '') ?>" placeholder="Buscar..."
                   style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:140px;">
        </div>
        <?php if ($branchList): ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Filial</label>
            <select name="branch_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todas</option>
                <?php foreach ($branchList as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $filterBranch == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div style="flex-basis:100%;">
            <?php
            $chips_id = 'alarmtypes';
            $chips_label = 'Tipos de Alarme';
            $chips_param = 'alarm_types';
            $chips_options = array_column($types, 'alarm_name');
            $chips_selected = $filterTypes;
            include __DIR__ . '/../web/components/chips_multiselect.php';
            ?>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Status</label>
            <select name="alarm_status" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Ativo</option>
                <option value="resolved" <?= $filterStatus === 'resolved' ? 'selected' : '' ?>>Resolvido</option>
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
    O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias: <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
</div>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th><?= report_sort_link('alarm_time', 'Data/Hora', $sort, $order) ?></th>
                <th>Cliente</th>
                <th><?= report_sort_link('imei', 'IMEI', $sort, $order) ?></th>
                <th><?= report_sort_link('alarm_type', 'Código', $sort, $order) ?></th>
                <th><?= report_sort_link('alarm_name', 'Nome do Alarme', $sort, $order) ?></th>
                <th>Protocolo</th>
                <th>Velocidade</th>
                <th>Status</th>
                <th>Mapa</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted);">Nenhum alarme encontrado</td></tr>
            <?php else: ?>
            <?php foreach ($rows as $r):
                $hasCoords = $r['latitude'] && $r['longitude'] && $r['latitude'] != 0 && $r['longitude'] != 0;
                $proto = (int)($r['msg_class'] ?? 0) === 0 ? 'JIMI' : 'JT/T';
            ?>
            <tr>
                <td class="text-mono"><?= fmt_brt($r['alarm_time'], 'd/m/Y H:i:s') ?></td>
                <td><?= htmlspecialchars($r['customer_name']) ?></td>
                <td><span class="text-mono"><?= htmlspecialchars($r['imei']) ?></span></td>
                <td><span class="text-mono"><?= htmlspecialchars($r['alarm_type']) ?></span></td>
                <td><?= htmlspecialchars($r['alarm_name'] ?? '—') ?></td>
                <td><span class="badge <?= $proto === 'JIMI' ? 'badge-primary' : 'badge-info' ?>"><?= $proto ?></span></td>
                <td><?= $r['speed'] !== null ? number_format((float)$r['speed'], 1) . ' km/h' : '—' ?></td>
                <td>
                    <?php if ($r['status'] === 'active'): ?>
                    <span class="badge badge-warning">Ativo</span>
                    <?php elseif ($r['status'] === 'resolved'): ?>
                    <span class="badge badge-success">Resolvido</span>
                    <?php else: ?>
                    <span class="badge"><?= htmlspecialchars($r['status']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($hasCoords): ?>
                    <a href="https://www.openstreetmap.org/?mlat=<?= $r['latitude'] ?>&mlon=<?= $r['longitude'] ?>&zoom=16"
                       target="_blank" class="badge badge-primary">Ver Mapa</a>
                    <?php else: echo '—'; endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">
    <span>Página <?= $page ?> de <?= $totalPages ?> (<?= number_format($totalRows, 0, ',', '.') ?> registros)</span>
    <div style="display:flex;gap:4px;">
        <?php
        $queryStr = $_GET; unset($queryStr['page']);
        $base = http_build_query($queryStr);
        if ($page > 1): ?>
        <a href="?<?= $base ?>&page=<?= $page-1 ?>" class="btn btn-outline btn-sm">&laquo;</a>
        <?php endif;
        for ($i = 1; $i <= min($totalPages, 10); $i++):
            if ($i === $page): ?>
            <span class="btn btn-primary btn-sm" style="pointer-events:none;"><?= $i ?></span>
            <?php else: ?>
            <a href="?<?= $base ?>&page=<?= $i ?>" class="btn btn-outline btn-sm"><?= $i ?></a>
            <?php endif;
        endfor;
        if ($totalPages > 10): ?>
        <span style="padding:4px 2px;">... <?= $totalPages ?></span>
        <?php endif;
        if ($page < $totalPages): ?>
        <a href="?<?= $base ?>&page=<?= $page+1 ?>" class="btn btn-outline btn-sm">&raquo;</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
