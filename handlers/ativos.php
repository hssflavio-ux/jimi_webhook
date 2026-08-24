<?php
/**
 * JIMI Webhook System — Ativos (Veículos) v4.11.0
 * Endpoint: /ativos
 *
 * Fase 1 do fluxo chip→câmera→veículo: esta grade é do VEÍCULO, entidade
 * própria desde a migração v4.11.0 — câmera (imei/modelo/chip/canais) é
 * cadastrada em /equipamentos; aqui só placa, tipo e o vínculo corrente com
 * uma câmera (histórico completo fica em /ativos/{id}).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/vehicle_icons.php';
require_login();

$customer_id = get_customer_id();
$db = Database::getInstance()->getConnection();
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo');

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    // RBAC ação fina (v4.2.0 — Fase B2)
    require_permission('ativos', $action === 'delete' ? 'delete' : 'edit');
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);

    if ($action === 'delete' && $vehicleId) {
        // Só desativa veículo SEM câmera instalada — mesma regra aplicada a
        // câmera/chip: desativar em uso deixaria a câmera "presa" a um
        // veículo inexistente aos olhos do resto do sistema.
        if (get_open_installation_for_vehicle($db, $vehicleId)) {
            $err = 'Este veículo tem uma câmera instalada — desinstale-a antes de desativar.';
        } else {
            $db->prepare("UPDATE vehicles SET is_active=0 WHERE id=? AND customer_id=?")->execute([$vehicleId, $customer_id]);
            $msg = 'Veículo removido.';
        }
    } elseif ($action === 'edit' && $vehicleId) {
        $plate  = trim($_POST['plate'] ?? '');
        $vtype  = trim($_POST['vehicle_type'] ?? '');
        $vtype  = array_key_exists($vtype, VEHICLE_ICONS) ? $vtype : null;
        if ($plate === '') {
            $err = 'Placa é obrigatória.';
        } else {
            $db->prepare("UPDATE vehicles SET plate=?, vehicle_type=? WHERE id=? AND customer_id=?")
               ->execute([$plate, $vtype, $vehicleId, $customer_id]);
            // Espelha em `devices.vehicle_type` (fonte que /rastreamento lê) se
            // houver câmera instalada agora — mesma sincronização feita na
            // instalação, em install_device_on_vehicle().
            $db->prepare("
                UPDATE devices d
                JOIN device_installations di ON di.device_id = d.id AND di.removed_at IS NULL
                SET d.vehicle_type = ?
                WHERE di.vehicle_id = ?
            ")->execute([$vtype, $vehicleId]);
            $msg = 'Veículo atualizado.';
        }
    } elseif ($action === 'install' && $vehicleId) {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $errInstall = $deviceId ? install_device_on_vehicle($db, $deviceId, $vehicleId, $_SESSION['user_id'] ?? null) : 'Selecione uma câmera.';
        if ($errInstall) { $err = $errInstall; } else { $msg = 'Câmera instalada.'; }
    } elseif ($action === 'uninstall' && $vehicleId) {
        $errUninstall = uninstall_device_from_vehicle($db, $vehicleId, $_SESSION['user_id'] ?? null);
        if ($errUninstall) { $err = $errUninstall; } else { $msg = 'Câmera desinstalada — livre para outro veículo.'; }
    }
}

// Fase C (padrão CRUD YUV §9.1): busca + paginação + export
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$listWhere = 'WHERE v.customer_id=:cid';
$listParams = [':cid' => $customer_id];
if ($q !== '') {
    $listWhere .= ' AND (v.plate LIKE :q1 OR d.imei LIKE :q2)';
    $listParams[':q1'] = "%$q%";
    $listParams[':q2'] = "%$q%";
}

$baseFrom = "
    FROM vehicles v
    LEFT JOIN device_installations di ON di.vehicle_id = v.id AND di.removed_at IS NULL
    LEFT JOIN devices d ON d.id = di.device_id
";

// Export síncrono
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('ativos', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expRows = [];
    try {
        $expStmt = $db->prepare("
            SELECT v.plate, v.is_active, d.imei, d.device_name, d.last_communication,
                   COALESCE(dm.model_name, d.device_model, '-') AS model_display, COALESCE(dm.protocol, '') AS protocol
            $baseFrom
            LEFT JOIN device_models dm ON d.device_model_id=dm.id
            $listWhere ORDER BY v.is_active DESC, d.last_communication DESC
            LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $expStmt->execute($listParams);
        while ($veh = $expStmt->fetch(PDO::FETCH_ASSOC)) {
            $expRows[] = [
                $veh['plate'],
                $veh['imei'] ?? '—',
                $veh['model_display'],
                $veh['protocol'] ?: '—',
                $veh['last_communication'] ? fmt_brt($veh['last_communication']) : '—',
                $veh['is_active'] ? 'Ativo' : 'Inativo',
            ];
        }
    } catch (Exception $e) {}
    stream_export($export, 'ativos',
        ['Placa', 'IMEI', 'Modelo', 'Protocolo', 'Última Comunicação', 'Status'],
        $expRows, 'Ativos');
}

$totalRows = 0;
try {
    $countStmt = $db->prepare("SELECT COUNT(*) $baseFrom $listWhere");
    $countStmt->execute($listParams);
    $totalRows = (int)$countStmt->fetchColumn();
} catch (Exception $e) {}
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

try {
    $vehiclesStmt = $db->prepare("
        SELECT v.id, v.plate, v.vehicle_type, v.is_active,
               d.id AS device_id, d.imei, d.device_name AS device_label, d.last_communication,
               s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status, s.is_online,
               COALESCE(dm.model_name, d.device_model, NULL) AS model_display,
               sc.msisdn AS chip_msisdn
        $baseFrom
        LEFT JOIN device_statistics s ON d.imei = s.imei
        LEFT JOIN device_models dm ON d.device_model_id = dm.id
        LEFT JOIN sim_cards sc ON sc.imei = d.imei AND sc.is_active = 1
        $listWhere ORDER BY v.is_active DESC, d.last_communication DESC
        LIMIT $perPage OFFSET $offset
    ");
    $vehiclesStmt->execute($listParams);
    $vehicles = $vehiclesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $vehicles = [];
}

$page_title='Ativos'; $current_route='ativos';
include __DIR__ . '/../web/layout_base.php';
?>

<?php if ($msg): ?><div class="card mb-16" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="card mb-16" style="border-color:#fce4eb;background:#fef2f5;color:var(--error);font-size:13px"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-24" style="gap:8px;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:6px;">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Pesquisar placa ou IMEI da câmera..."
               style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:260px;">
        <button type="submit" class="btn btn-outline btn-sm">Pesquisar</button>
    </form>
    <div style="display:flex;gap:6px;align-items:center;">
        <span style="font-size:13px;color:var(--muted)"><?= $totalRows ?> veículo(s)</span>
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <a href="/ativos/novo" class="btn btn-primary">+ Novo Veículo</a>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead><tr><th>Placa</th><th>Tipo</th><th>Câmera atual</th><th>Chip</th><th>Modelo</th><th>Status</th><th>Velocidade</th><th>Última Com.</th><th style="width:220px"></th></tr></thead>
        <tbody>
            <?php foreach ($vehicles as $veh):
                $off = !$veh['is_active'];
                $hasCamera = !empty($veh['device_id']);
                $dtLast = $veh['last_communication'] ? new DateTime($veh['last_communication'], $tz_utc) : null;
                $isOnline = !$off && $dtLast && ((new DateTime('now', $tz_utc))->getTimestamp() - $dtLast->getTimestamp()) < 600;
            ?>
            <tr id="row-<?= $veh['id'] ?>" style="<?= $off ? 'opacity:.5' : '' ?>">
                <td style="font-weight:500;color:var(--ink)">
                    <span class="view-plate-<?= $veh['id'] ?>"><?= htmlspecialchars($veh['plate']) ?></span>
                </td>
                <td>
                    <span style="display:flex;align-items:center;gap:6px;color:var(--muted)">
                        <?php if ($veh['vehicle_type']): ?>
                            <?= vehicle_icon_svg($veh['vehicle_type'], 'var(--body)', 16) ?>
                        <?php endif; ?>
                        <?= htmlspecialchars(vehicle_type_label($veh['vehicle_type'])) ?>
                    </span>
                </td>
                <td>
                    <?php if ($hasCamera): ?>
                    <span class="text-mono" style="font-size:12px"><?= htmlspecialchars($veh['imei']) ?></span>
                    <span style="font-size:11px;color:var(--muted);display:block"><?= htmlspecialchars($veh['device_label'] ?: '') ?></span>
                    <?php else: ?>
                    <span style="color:var(--muted)">Sem câmera</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px"><?= $veh['chip_msisdn'] ? htmlspecialchars($veh['chip_msisdn']) : '—' ?></td>
                <td><?= htmlspecialchars($veh['model_display'] ?? '—') ?></td>
                <td>
                    <?php if ($off): ?><span class="badge badge-error">Inativo</span>
                    <?php elseif (!$hasCamera): ?><span class="badge" style="background:var(--surface-strong);color:var(--muted)">Sem câmera</span>
                    <?php elseif ($isOnline): ?><span class="badge badge-success">Online</span>
                    <?php else: ?><span class="badge" style="background:var(--surface-strong);color:var(--muted)">Offline</span><?php endif; ?>
                    <?php if (!$off && $veh['last_acc_status']==1): ?><span class="badge badge-warning">Ligado</span><?php endif; ?>
                </td>
                <td><?= $hasCamera ? round($veh['last_speed'] ?? 0) : '—' ?><?= $hasCamera ? ' km/h' : '' ?></td>
                <td><?php if ($dtLast) { $dtLast->setTimezone($tz_brt); echo $dtLast->format('d/m/Y H:i:s'); } else echo '-'; ?></td>
                <td>
                    <a href="/ativos/<?= $veh['id'] ?>" class="btn btn-outline btn-sm">Abrir</a>
                    <?php if (!$off): ?>
                    <button class="btn btn-outline btn-sm" onclick="editRow('<?= $veh['id'] ?>')">Editar</button>
                    <?php if (!$hasCamera): ?>
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="vehicle_id" value="<?= $veh['id'] ?>"><button class="btn btn-outline btn-sm" style="color:var(--error)" onclick="return confirm('Remover este veículo?')">Remover</button></form>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr id="edit-<?= $veh['id'] ?>" style="display:none;background:var(--canvas-soft)">
                <td><input type="text" id="edit-plate-<?= $veh['id'] ?>" value="<?= htmlspecialchars($veh['plate']) ?>" style="width:100%;padding:4px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:4px"></td>
                <td colspan="2">
                    <select id="edit-vtype-<?= $veh['id'] ?>" style="width:100%;padding:4px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:4px">
                        <option value="" <?= !$veh['vehicle_type'] ? 'selected' : '' ?>>Não informado</option>
                        <?php foreach (VEHICLE_ICONS as $vtKey => $vtInfo): ?>
                        <option value="<?= htmlspecialchars($vtKey) ?>" <?= $veh['vehicle_type']===$vtKey?'selected':'' ?>><?= htmlspecialchars($vtInfo['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td colspan="6">
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="vehicle_id" value="<?= $veh['id'] ?>"><input type="hidden" id="edit-f-plate-<?= $veh['id'] ?>" name="plate"><input type="hidden" id="edit-f-vtype-<?= $veh['id'] ?>" name="vehicle_type"><button class="btn btn-primary btn-sm">Salvar</button></form>
                    <button class="btn btn-outline btn-sm" onclick="cancelEdit('<?= $veh['id'] ?>')">Cancelar</button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($vehicles)): ?>
            <tr><td colspan="9"><div class="empty-state"><h3>Nenhum veículo</h3><p>Cadastre seu primeiro veículo.</p><a href="/ativos/novo" class="btn btn-primary mt-16">Cadastrar</a></div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php if ($totalPages > 1): ?>
<div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">
    <span>Página <?= $page ?> de <?= $totalPages ?> (<?= $totalRows ?> veículos)</span>
    <div style="display:flex;gap:4px;">
        <?php if ($page > 1): ?><a href="?<?= $expBase ?>&page=<?= $page-1 ?>" class="btn btn-outline btn-sm">&laquo;</a><?php endif; ?>
        <?php if ($page < $totalPages): ?><a href="?<?= $expBase ?>&page=<?= $page+1 ?>" class="btn btn-outline btn-sm">&raquo;</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>
<script>
function editRow(id) {
    document.getElementById('row-'+id).style.display = 'none';
    var er = document.getElementById('edit-'+id);
    er.style.display = '';
    document.getElementById('edit-plate-'+id).focus();
}
function cancelEdit(id) {
    document.getElementById('row-'+id).style.display = '';
    document.getElementById('edit-'+id).style.display = 'none';
}
document.querySelectorAll('form').forEach(function(f) {
    f.addEventListener('submit', function() {
        if (f.querySelector('[name=action]') && f.querySelector('[name=action]').value === 'edit') {
            var id = f.querySelector('[name=vehicle_id]').value;
            f.querySelector('[name=plate]').value = document.getElementById('edit-plate-'+id).value;
            f.querySelector('[name=vehicle_type]').value = document.getElementById('edit-vtype-'+id).value;
        }
    });
});
</script>
<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
