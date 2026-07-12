<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$customer_id = get_customer_id();
$db = Database::getInstance()->getConnection();
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo');

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    // RBAC ação fina (v4.2.0 — Fase B2)
    require_permission('ativos', $action === 'delete' ? 'delete' : 'edit');
    $imei   = $_POST['imei'] ?? '';
    if ($action === 'delete' && $imei) {
        $db->prepare("UPDATE devices SET is_active=0 WHERE imei=? AND customer_id=?")->execute([$imei, $customer_id]);
        $msg = 'Dispositivo removido.';
    } elseif ($action === 'edit' && $imei) {
        $name   = trim($_POST['device_name'] ?? '');
        $model  = (int)($_POST['device_model_id'] ?? 0);
        $cam    = max(1, (int)($_POST['camera_count'] ?? 1));
        $db->prepare("UPDATE devices SET device_name=?, device_model_id=?, camera_count=? WHERE imei=? AND customer_id=?")
           ->execute([$name, $model ?: null, $cam, $imei, $customer_id]);
        $msg = 'Dispositivo atualizado.';
    }
}

// Fase C (padrão CRUD YUV §9.1): busca + paginação + export
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$listWhere = 'WHERE d.customer_id=:cid';
$listParams = [':cid' => $customer_id];
if ($q !== '') {
    $listWhere .= ' AND (d.device_name LIKE :q1 OR d.imei LIKE :q2)';
    $listParams[':q1'] = "%$q%";
    $listParams[':q2'] = "%$q%";
}

// Export síncrono
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('ativos', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expRows = [];
    try {
        $expStmt = $db->prepare("
            SELECT d.imei, d.device_name, d.camera_count, d.is_active, d.last_communication, d.activation_date,
                   COALESCE(dm.model_name, d.device_model, '-') AS model_display, COALESCE(dm.protocol, '') AS protocol
            FROM devices d LEFT JOIN device_models dm ON d.device_model_id=dm.id
            $listWhere ORDER BY d.is_active DESC, d.last_communication DESC
            LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $expStmt->execute($listParams);
        while ($dev = $expStmt->fetch(PDO::FETCH_ASSOC)) {
            $expRows[] = [
                $dev['device_name'] ?? '—',
                $dev['imei'],
                $dev['model_display'],
                $dev['protocol'] ?: '—',
                (int)($dev['camera_count'] ?? 1),
                $dev['activation_date'] ?? '—',
                $dev['last_communication'] ? fmt_brt($dev['last_communication']) : '—',
                $dev['is_active'] ? 'Ativo' : 'Inativo',
            ];
        }
    } catch (Exception $e) {}
    stream_export($export, 'ativos',
        ['Nome', 'IMEI', 'Modelo', 'Protocolo', 'Câmeras', 'Ativação', 'Última Comunicação', 'Status'],
        $expRows, 'Ativos');
}

$totalRows = 0;
try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM devices d $listWhere");
    $countStmt->execute($listParams);
    $totalRows = (int)$countStmt->fetchColumn();
} catch (Exception $e) {}
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

try {
    $devicesStmt = $db->prepare("
        SELECT d.imei, d.device_name, d.device_model, d.last_communication, d.activation_date, d.camera_count, d.device_model_id, d.is_active,
               s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status, s.is_online,
               COALESCE(dm.model_name, d.device_model, '-') AS model_display, COALESCE(dm.protocol, '') AS protocol
        FROM devices d LEFT JOIN device_statistics s ON d.imei=s.imei LEFT JOIN device_models dm ON d.device_model_id=dm.id
        $listWhere ORDER BY d.is_active DESC, d.last_communication DESC
        LIMIT $perPage OFFSET $offset
    ");
    $devicesStmt->execute($listParams);
    $devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $devicesStmt = $db->prepare("
        SELECT d.imei, d.device_name, d.device_model, d.last_communication, d.activation_date, d.camera_count, d.device_model_id, d.is_active,
               NULL as last_latitude, NULL as last_longitude, NULL as last_speed, NULL as last_acc_status, NULL as is_online,
               COALESCE(dm.model_name, d.device_model, '-') AS model_display, COALESCE(dm.protocol, '') AS protocol
        FROM devices d LEFT JOIN device_models dm ON d.device_model_id=dm.id
        $listWhere ORDER BY d.is_active DESC, d.last_communication DESC
        LIMIT $perPage OFFSET $offset
    ");
    $devicesStmt->execute($listParams);
    $devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);
}

$models = $db->query("SELECT id, model_name, protocol, camera_count FROM device_models ORDER BY protocol, model_name")->fetchAll(PDO::FETCH_ASSOC);

$page_title='Ativos'; $current_route='ativos';
include __DIR__ . '/../web/layout_base.php';
?>

<?php if ($msg): ?><div class="card mb-16" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-24" style="gap:8px;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:6px;">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Pesquisar nome ou IMEI..."
               style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:240px;">
        <button type="submit" class="btn btn-outline btn-sm">Pesquisar</button>
    </form>
    <div style="display:flex;gap:6px;align-items:center;">
        <span style="font-size:13px;color:var(--muted)"><?= $totalRows ?> dispositivo(s)</span>
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <a href="/ativos/novo" class="btn btn-primary">+ Novo Dispositivo</a>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead><tr><th>Dispositivo</th><th>IMEI</th><th>Modelo</th><th>Câmeras</th><th>Status</th><th>Velocidade</th><th>Última Com.</th><th style="width:180px"></th></tr></thead>
        <tbody>
            <?php foreach ($devices as $dev):
                $off = !$dev['is_active'];
                $dtLast = $dev['last_communication'] ? new DateTime($dev['last_communication'], $tz_utc) : null;
                $isOnline = !$off && $dtLast && ((new DateTime('now', $tz_utc))->getTimestamp() - $dtLast->getTimestamp()) < 600;
                $hasGps = !empty($dev['last_latitude']) && $dev['last_latitude'] != 0;
            ?>
            <tr id="row-<?= $dev['imei'] ?>" style="<?= $off ? 'opacity:.5' : '' ?>">
                <td style="font-weight:500;color:var(--ink)">
                    <span class="view-name-<?= $dev['imei'] ?>"><?= htmlspecialchars($dev['device_name'] ?? 'Sem Nome') ?></span>
                </td>
                <td class="text-mono"><?= htmlspecialchars($dev['imei']) ?></td>
                <td><span class="view-model-<?= $dev['imei'] ?>"><?= htmlspecialchars($dev['model_display']) ?></span></td>
                <td><span class="view-cam-<?= $dev['imei'] ?>"><?= $dev['camera_count'] ?? 1 ?></span></td>
                <td>
                    <?php if ($off): ?><span class="badge badge-error">Inativo</span>
                    <?php elseif ($isOnline): ?><span class="badge badge-success">Online</span>
                    <?php else: ?><span class="badge" style="background:var(--surface-strong);color:var(--muted)">Offline</span><?php endif; ?>
                    <?php if (!$off && $dev['last_acc_status']==1): ?><span class="badge badge-warning">Ligado</span><?php endif; ?>
                </td>
                <td><?= round($dev['last_speed'] ?? 0) ?> km/h</td>
                <td><?php if ($dtLast) { $dtLast->setTimezone($tz_brt); echo $dtLast->format('d/m/Y H:i:s'); } else echo '-'; ?></td>
                <td>
                    <a href="/ativos/<?= urlencode($dev['imei']) ?>" class="btn btn-outline btn-sm">Abrir</a>
                    <?php if (!$off): ?>
                    <button class="btn btn-outline btn-sm" onclick="editRow('<?= $dev['imei'] ?>')">Editar</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('Remover este dispositivo?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="imei" value="<?= $dev['imei'] ?>"><button class="btn btn-outline btn-sm" style="color:var(--error)">Remover</button></form>
                    <?php endif; ?>
                </td>
            </tr>
            <tr id="edit-<?= $dev['imei'] ?>" style="display:none;background:var(--canvas-soft)">
                <td><input type="text" id="edit-name-<?= $dev['imei'] ?>" value="<?= htmlspecialchars($dev['device_name'] ?? '') ?>" style="width:100%;padding:4px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:4px"></td>
                <td class="text-mono"><?= htmlspecialchars($dev['imei']) ?></td>
                <td><select id="edit-model-<?= $dev['imei'] ?>" style="width:100%;padding:4px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:4px"><?php foreach ($models as $m): ?><option value="<?= $m['id'] ?>" <?= $dev['device_model_id']==$m['id']?'selected':'' ?>><?= $m['model_name'] ?></option><?php endforeach; ?></select></td>
                <td><input type="number" id="edit-cam-<?= $dev['imei'] ?>" value="<?= $dev['camera_count'] ?>" min="1" max="16" style="width:60px;padding:4px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:4px"></td>
                <td colspan="4">
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="imei" value="<?= $dev['imei'] ?>"><input type="hidden" id="edit-f-name-<?= $dev['imei'] ?>" name="device_name"><input type="hidden" id="edit-f-model-<?= $dev['imei'] ?>" name="device_model_id"><input type="hidden" id="edit-f-cam-<?= $dev['imei'] ?>" name="camera_count"><button class="btn btn-primary btn-sm">Salvar</button></form>
                    <button class="btn btn-outline btn-sm" onclick="cancelEdit('<?= $dev['imei'] ?>')">Cancelar</button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($devices)): ?>
            <tr><td colspan="8"><div class="empty-state"><h3>Nenhum dispositivo</h3><p>Cadastre seu primeiro equipamento.</p><a href="/ativos/novo" class="btn btn-primary mt-16">Cadastrar</a></div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php if ($totalPages > 1): ?>
<div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">
    <span>Página <?= $page ?> de <?= $totalPages ?> (<?= $totalRows ?> dispositivos)</span>
    <div style="display:flex;gap:4px;">
        <?php if ($page > 1): ?><a href="?<?= $expBase ?>&page=<?= $page-1 ?>" class="btn btn-outline btn-sm">&laquo;</a><?php endif; ?>
        <?php if ($page < $totalPages): ?><a href="?<?= $expBase ?>&page=<?= $page+1 ?>" class="btn btn-outline btn-sm">&raquo;</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>
<script>
function editRow(imei) {
    document.getElementById('row-'+imei).style.display = 'none';
    var er = document.getElementById('edit-'+imei);
    er.style.display = '';
    document.getElementById('edit-name-'+imei).focus();
}
function cancelEdit(imei) {
    document.getElementById('row-'+imei).style.display = '';
    document.getElementById('edit-'+imei).style.display = 'none';
}
document.querySelectorAll('form').forEach(function(f) {
    f.addEventListener('submit', function() {
        if (f.querySelector('[name=action]') && f.querySelector('[name=action]').value === 'edit') {
            var imei = f.querySelector('[name=imei]').value;
            f.querySelector('[name=device_name]').value = document.getElementById('edit-name-'+imei).value;
            f.querySelector('[name=device_model_id]').value = document.getElementById('edit-model-'+imei).value;
            f.querySelector('[name=camera_count]').value = document.getElementById('edit-cam-'+imei).value;
        }
    });
});
</script>
<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
