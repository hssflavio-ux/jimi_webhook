<?php
/**
 * JIMI Webhook System — Relatório de Deslocamento v4.0.0
 * Rota: /relatorios/deslocamento
 *
 * Filtro: Ativos + Período + [Gerar] + Export.
 * Grade: Identificador, Motorista, Início/Local, Fim/Local, Evento,
 *        Duração, Velocidade Máxima, KM, Qtd. Alarmes.
 * Dados da tabela trips (preenchida pelo trip_builder).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$selImei  = $_GET['imei'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo   = $_GET['date_to'] ?? brt_today();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$generated = !empty($_GET['gerar']);

$rows = [];
$totalRows = 0;
$totalPages = 1;

if ($generated) {
    $where = 'WHERE t.started_at BETWEEN :df AND :dt';
    [$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo); // dias BRT → janela UTC
    $params = [':df' => $utcFrom, ':dt' => $utcTo];

    if ($customerId) {
        $where .= ' AND t.customer_id = :cid';
        $params[':cid'] = $customerId;
    }
    if ($selImei) {
        $where .= ' AND t.imei = :imei';
        $params[':imei'] = $selImei;
    }

    // Export síncrono (padrão YUV §9.2): mesma query da grade, sem paginação
    $export = $_GET['export'] ?? '';
    if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
        require_permission('relatorios', 'export');
        require_once __DIR__ . '/../includes/export_helper.php';
        $expRows = [];
        try {
            $expStmt = $db->prepare("
                SELECT t.*, COALESCE(d.device_name, t.imei) as device_name,
                       COALESCE(dr.name, '—') as driver_name
                FROM trips t
                LEFT JOIN devices d ON d.imei = t.imei
                LEFT JOIN drivers dr ON dr.id = t.driver_id
                $where
                ORDER BY t.started_at DESC
                LIMIT " . SYNC_EXPORT_MAX_ROWS);
            $expStmt->execute($params);
            while ($r = $expStmt->fetch()) {
                $duration = (int)($r['duration_s'] ?? 0);
                $expRows[] = [
                    $r['imei'],
                    $r['device_name'],
                    $r['driver_name'],
                    fmt_brt($r['started_at']),
                    $r['start_addr'] ?? '—',
                    $r['ended_at'] ? fmt_brt($r['ended_at']) : '—',
                    $r['end_addr'] ?? '—',
                    $duration > 0 ? sprintf('%dh%02dm', floor($duration / 3600), floor(($duration % 3600) / 60)) : '—',
                    $r['max_speed'] ? number_format((float)$r['max_speed'], 1) : '—',
                    $r['distance_km'] ? number_format((float)$r['distance_km'], 1) : '—',
                    (int)($r['alarm_count'] ?? 0),
                ];
            }
        } catch (Exception $e) { /* tabela trips ausente → export vazio */ }
        stream_export($export, 'relatorio_deslocamento',
            ['IMEI', 'Dispositivo', 'Motorista', 'Início', 'Local Início', 'Término', 'Local Fim', 'Duração', 'Vel. Máx (km/h)', 'Distância (km)', 'Alarmes'],
            $expRows, 'Relatório de Deslocamento', "Período (BRT): $dateFrom a $dateTo");
    }

    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM trips t $where");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, ceil($totalRows / $perPage));
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT t.*, COALESCE(d.device_name, t.imei) as device_name,
                   COALESCE(dr.name, '—') as driver_name
            FROM trips t
            LEFT JOIN devices d ON d.imei = t.imei
            LEFT JOIN drivers dr ON dr.id = t.driver_id
            $where
            ORDER BY t.started_at DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        $totalRows = 0; $totalPages = 1; $rows = [];
    }
}

$devices = $db->prepare("SELECT d.imei, d.device_name FROM devices d WHERE d.customer_id = :cid ORDER BY d.device_name");
$devices->execute([':cid' => $customerId]);
$devices = $devices->fetchAll();

$page_title = 'Relatório de Deslocamento';
$current_route = 'rel_deslocamento';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Deslocamento</h2>
    <?php if ($generated): ?>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
    </div>
    <?php endif; ?>
</div>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <input type="hidden" name="gerar" value="1">
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Ativo</label>
            <select name="imei" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:180px;">
                <option value="">Todos</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>" <?= $selImei===$d['imei']?'selected':'' ?>><?= htmlspecialchars($d['device_name']??$d['imei']) ?></option>
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
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
    </form>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>IMEI</th>
                <th>Dispositivo</th>
                <th>Motorista</th>
                <th>Início</th>
                <th>Término</th>
                <th>Duração</th>
                <th>Vel. Máx</th>
                <th>Distância</th>
                <th>Alarmes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted);">
                <?= $generated ? 'Nenhuma viagem encontrada no período.' : 'Selecione os filtros e clique em Gerar.' ?>
            </td></tr>
            <?php else: ?>
            <?php foreach ($rows as $r):
                $duration = (int)($r['duration_s'] ?? 0);
                $h = floor($duration / 3600);
                $m = floor(($duration % 3600) / 60);
                $durStr = $duration > 0 ? sprintf('%dh%02dm', $h, $m) : '—';
            ?>
            <tr>
                <td><span class="text-mono"><?= htmlspecialchars($r['imei']) ?></span></td>
                <td><?= htmlspecialchars($r['device_name']) ?></td>
                <td><?= htmlspecialchars($r['driver_name']) ?></td>
                <td class="text-mono"><?= fmt_brt($r['started_at']) ?><br><span style="font-size:10px;color:var(--muted);"><?= htmlspecialchars(substr($r['start_addr']??'—', 0, 40)) ?></span></td>
                <td class="text-mono"><?= $r['ended_at'] ? fmt_brt($r['ended_at']) : '—' ?><br><span style="font-size:10px;color:var(--muted);"><?= htmlspecialchars(substr($r['end_addr']??'—', 0, 40)) ?></span></td>
                <td><?= $durStr ?></td>
                <td><?= $r['max_speed'] ? number_format((float)$r['max_speed'], 1) . ' km/h' : '—' ?></td>
                <td><?= $r['distance_km'] ? number_format((float)$r['distance_km'], 1) . ' km' : '—' ?></td>
                <td><?= (int)($r['alarm_count'] ?? 0) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">
    <span>Página <?= $page ?> de <?= $totalPages ?> (<?= $totalRows ?> viagens)</span>
    <div style="display:flex;gap:4px;">
        <?php
        $queryStr = $_GET; unset($queryStr['page']);
        $base = http_build_query($queryStr);
        if ($page > 1): ?><a href="?<?= $base ?>&page=<?= $page-1 ?>" class="btn btn-outline btn-sm">&laquo;</a><?php endif;
        for ($i = 1; $i <= min($totalPages, 8); $i++):
            if ($i === $page): ?><span class="btn btn-primary btn-sm"><?= $i ?></span>
            <?php else: ?><a href="?<?= $base ?>&page=<?= $i ?>" class="btn btn-outline btn-sm"><?= $i ?></a><?php endif;
        endfor;
        if ($page < $totalPages): ?><a href="?<?= $base ?>&page=<?= $page+1 ?>" class="btn btn-outline btn-sm">&raquo;</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
