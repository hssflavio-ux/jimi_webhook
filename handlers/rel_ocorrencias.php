<?php
/**
 * JIMI Webhook System — Relatório de Ocorrências v4.0.0
 * Rota: /relatorios/ocorrencias
 *
 * Versão histórica/auditável do dashboard DMS.
 * Filtros: Clientes, Filiais, Ativos, Tipo de Alarme, Motoristas,
 *          Falso positivo, Status, Período.
 * Grid: Cliente, Identificador, Motorista, IMEI, Último alarme em,
 *       Alarme, Falso positivo, Situação.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = 'Relatório de Ocorrências';
$current_route = 'rel_ocorrencias';
$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$dateFrom  = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo    = $_GET['date_to'] ?? date('Y-m-d');
$filterCust   = $_GET['customer_id'] ?? null;
$filterImei  = $_GET['imei'] ?? null;
$filterType  = $_GET['alarm_type'] ?? null;
$filterStatus = $_GET['status'] ?? null;
$filterFP    = $_GET['false_positive'] ?? null;
$filterRisk  = $_GET['risk'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = 'WHERE o.last_alarm_at BETWEEN :df AND :dt';
$params = [':df' => $dateFrom . ' 00:00:00', ':dt' => $dateTo . ' 23:59:59'];

if (!$isAdmin && !$filterCust) {
    if ($customerId) {
        $where .= ' AND o.customer_id = :cid';
        $params[':cid'] = $customerId;
    }
} elseif ($filterCust) {
    $where .= ' AND o.customer_id = :fcid';
    $params[':fcid'] = (int)$filterCust;
}
if ($filterImei) {
    $where .= ' AND o.imei LIKE :imei';
    $params[':imei'] = "%$filterImei%";
}
if ($filterType) {
    $where .= ' AND o.alarm_type = :atype';
    $params[':atype'] = $filterType;
}
if ($filterStatus) {
    $where .= ' AND o.status = :st';
    $params[':st'] = $filterStatus;
}
if ($filterFP !== null && $filterFP !== '') {
    $where .= ' AND o.false_positive = :fp';
    $params[':fp'] = (int)$filterFP;
}
if ($filterRisk) {
    $where .= ' AND o.risk = :risk';
    $params[':risk'] = $filterRisk;
}

// Count
try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM occurrences o $where");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));
    $offset = ($page - 1) * $perPage;

    // Data
    $dataStmt = $db->prepare("
        SELECT o.*, c.name as customer_name, COALESCE(dr.name, '—') as driver_name, b.name as branch_name
        FROM occurrences o
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN drivers dr ON dr.id = o.driver_id
        LEFT JOIN branches b ON b.id = o.branch_id
        $where
        ORDER BY o.last_alarm_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll();
} catch (Exception $e) {
    $totalRows = 0; $totalPages = 1; $rows = [];
}

// Dropdowns
$customers = $db->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();

try {
    $typeStmt = $db->query("SELECT DISTINCT alarm_type FROM occurrences ORDER BY alarm_type");
    $alarmTypes = $typeStmt->fetchAll();
} catch (Exception $e) {
    $alarmTypes = [];
}

require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Ocorrências</h2>
    <button class="btn btn-outline btn-sm" onclick="alert('Export Excel em desenvolvimento')">Exportar Excel</button>
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
            <input type="text" name="imei" value="<?= htmlspecialchars($filterImei ?? '') ?>" placeholder="Buscar IMEI..."
                   style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:140px;">
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Tipo</label>
            <select name="alarm_type" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <?php foreach ($alarmTypes as $t): ?>
                <option value="<?= htmlspecialchars($t['alarm_type']) ?>" <?= $filterType === $t['alarm_type'] ? 'selected' : '' ?>><?= htmlspecialchars($t['alarm_type']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Status</label>
            <select name="status" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="aguardando" <?= $filterStatus === 'aguardando' ? 'selected' : '' ?>>Aguardando</option>
                <option value="em_tratativa" <?= $filterStatus === 'em_tratativa' ? 'selected' : '' ?>>Em Tratativa</option>
                <option value="resolvida" <?= $filterStatus === 'resolvida' ? 'selected' : '' ?>>Resolvida</option>
                <option value="descartada" <?= $filterStatus === 'descartada' ? 'selected' : '' ?>>Descartada</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Risco</label>
            <select name="risk" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="baixo" <?= $filterRisk === 'baixo' ? 'selected' : '' ?>>Baixo</option>
                <option value="medio" <?= $filterRisk === 'medio' ? 'selected' : '' ?>>Médio</option>
                <option value="alto" <?= $filterRisk === 'alto' ? 'selected' : '' ?>>Alto</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Falso Positivo</label>
            <select name="false_positive" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="1" <?= $filterFP === '1' ? 'selected' : '' ?>>Sim</option>
                <option value="0" <?= $filterFP === '0' ? 'selected' : '' ?>>Não</option>
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
    </form>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Cliente</th>
                <th>IMEI</th>
                <th>Motorista</th>
                <th>Tipo de Alarme</th>
                <th>Último Alarme</th>
                <th>Qtd</th>
                <th>Risco</th>
                <th>Falso Pos.</th>
                <th>Situação</th>
                <th style="text-align:center;">Ação</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="10" style="text-align:center;padding:32px;color:var(--muted);">Nenhuma ocorrência encontrada</td></tr>
            <?php else: ?>
            <?php
            $riskBadges = ['baixo'=>'badge-primary','medio'=>'badge-warning','alto'=>'badge-error'];
            $statusBadges = ['aguardando'=>'badge-warning','em_tratativa'=>'badge-info','resolvida'=>'badge-success','descartada'=>'badge'];
            $statusLabels = ['aguardando'=>'Aguardando','em_tratativa'=>'Em Tratativa','resolvida'=>'Resolvida','descartada'=>'Descartada'];
            foreach ($rows as $r):
            ?>
            <tr>
                <td><?= htmlspecialchars($r['customer_name']) ?></td>
                <td><span class="text-mono"><?= htmlspecialchars($r['imei']) ?></span></td>
                <td><?= htmlspecialchars($r['driver_name']) ?></td>
                <td><?= htmlspecialchars($r['alarm_type']) ?></td>
                <td class="text-mono"><?= date('d/m/Y H:i', strtotime($r['last_alarm_at'])) ?></td>
                <td><?= (int)$r['alarm_count'] ?></td>
                <td><span class="badge <?= $riskBadges[$r['risk']] ?? 'badge' ?>"><?= ucfirst($r['risk']) ?></span></td>
                <td><?= $r['false_positive'] ? '<span class="badge badge-warning">Sim</span>' : 'Não' ?></td>
                <td><span class="badge <?= $statusBadges[$r['status']] ?? 'badge' ?>"><?= $statusLabels[$r['status']] ?? $r['status'] ?></span></td>
                <td style="text-align:center;">
                    <a href="/ocorrencias/dashboard?id=<?= $r['id'] ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">Abrir</a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">
    <span>Página <?= $page ?> de <?= $totalPages ?> (<?= $totalRows ?> registros)</span>
    <div style="display:flex;gap:4px;">
        <?php
        $queryStr = $_GET; unset($queryStr['page']);
        $base = http_build_query($queryStr);
        if ($page > 1): ?>
        <a href="?<?= $base ?>&page=<?= $page-1 ?>" class="btn btn-outline btn-sm">&laquo; Anterior</a>
        <?php endif;
        for ($i = 1; $i <= $totalPages; $i++):
            if ($i === $page): ?>
            <span class="btn btn-primary btn-sm" style="pointer-events:none;"><?= $i ?></span>
            <?php elseif ($i <= 3 || $i >= $totalPages - 2 || abs($i - $page) <= 1): ?>
            <a href="?<?= $base ?>&page=<?= $i ?>" class="btn btn-outline btn-sm"><?= $i ?></a>
            <?php elseif ($i === 4 || $i === $totalPages - 3): ?>
            <span style="padding:4px 2px;">...</span>
            <?php endif;
        endfor;
        if ($page < $totalPages): ?>
        <a href="?<?= $base ?>&page=<?= $page+1 ?>" class="btn btn-outline btn-sm">Próximo &raquo;</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
