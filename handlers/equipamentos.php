<?php
/**
 * JIMI Webhook System — Equipamentos v4.0.0
 * Rota: /equipamentos
 *
 * Grade CRUD + filtros + ações: Exportar, Cadastrar, Firmware, Importar.
 * Form com modelo*, IMEI*, chip, periféricos multi, rotação, watermark.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$message = '';
$messageType = '';

// ── POST: Create/Update ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Batch Import ─────────────────────────────────────────
    if ($action === 'import_batch') {
        csrf_verify();
        $devicesJson = $_POST['devices'] ?? '';
        $devicesData = json_decode($devicesJson, true);
        if (!is_array($devicesData) || empty($devicesData)) {
            $message = 'Nenhum dispositivo válido no arquivo.';
            $messageType = 'error';
        } else {
            $imported = 0; $skipped = 0;
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM devices WHERE imei = :imei");
            $insertStmt = $db->prepare("
                INSERT INTO devices (imei, device_name, customer_id, is_active, streaming_rotation, streaming_watermark, firmware_version, camera_count)
                VALUES (:imei, :name, :cid, 1, 0, 0, :fw, 1)
            ");
            foreach ($devicesData as $d) {
                $imei = trim($d['imei'] ?? '');
                $name = trim($d['name'] ?? '');
                if (!$imei) { $skipped++; continue; }
                $checkStmt->execute([':imei' => $imei]);
                if ($checkStmt->fetchColumn() > 0) { $skipped++; continue; }
                $insertStmt->execute([
                    ':imei' => $imei,
                    ':name' => $name ?: $imei,
                    ':cid'  => $customerId ?? 1,
                    ':fw'   => trim($d['firmware'] ?? '') ?: null,
                ]);
                $imported++;
            }
            $message = "$imported importado(s), $skipped ignorado(s) (IMEI duplicado ou inválido).";
            $messageType = $imported > 0 ? 'success' : 'warning';
        }
    }
    // ── Single Create/Update ─────────────────────────────────
    else {
    csrf_verify();
    $imei = trim($_POST['imei'] ?? '');
    $deviceName = trim($_POST['device_name'] ?? '');
    $modelId = !empty($_POST['device_model_id']) ? (int)$_POST['device_model_id'] : null;
    $simImei = trim($_POST['sim_imei'] ?? '');
    $peripherals = $_POST['peripherals'] ?? [];
    $rotation = (int)($_POST['streaming_rotation'] ?? 0);
    $watermark = !empty($_POST['streaming_watermark']) ? 1 : 0;
    $firmware = trim($_POST['firmware_version'] ?? '');
    $branchId = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    $isActive = !empty($_POST['is_active']) ? 1 : ((($_POST['action'] ?? '') === 'create') ? 1 : 0);
    $cameraCount = (int)($_POST['camera_count'] ?? 1);

    if (empty($imei) || empty($deviceName)) {
        $message = 'IMEI e Nome do dispositivo são obrigatórios.';
        $messageType = 'error';
    } else {
        try {
            $isNew = ($_POST['action'] ?? '') === 'create';
            if ($isNew) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM devices WHERE imei = :imei");
                $stmt->execute([':imei' => $imei]);
                if ($stmt->fetchColumn() > 0) {
                    $message = 'IMEI já cadastrado.';
                    $messageType = 'error';
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO devices (imei, device_name, customer_id, device_model_id, camera_count,
                            streaming_rotation, streaming_watermark, firmware_version, branch_id, is_active, peripherals)
                        VALUES (:imei, :name, :cid, :mid, :cc, :rot, :wm, :fw, :bid, :act, :per)
                    ");
                    $stmt->execute([
                        ':imei' => $imei, ':name' => $deviceName, ':cid' => $customerId ?? 1,
                        ':mid' => $modelId, ':cc' => $cameraCount, ':rot' => $rotation,
                        ':wm' => $watermark, ':fw' => $firmware ?: null, ':bid' => $branchId,
                        ':act' => $isActive, ':per' => !empty($peripherals) ? json_encode($peripherals) : null,
                    ]);
                    $message = 'Equipamento cadastrado com sucesso.';
                    $messageType = 'success';
                }
            } else {
                $editImei = $_POST['edit_imei'] ?? $imei;
                $stmt = $db->prepare("
                    UPDATE devices SET device_name = :name, device_model_id = :mid, camera_count = :cc,
                        streaming_rotation = :rot, streaming_watermark = :wm,
                        firmware_version = :fw, branch_id = :bid, is_active = :act,
                        peripherals = :per
                    WHERE imei = :imei
                ");
                $stmt->execute([
                    ':name' => $deviceName, ':mid' => $modelId, ':cc' => $cameraCount,
                    ':rot' => $rotation, ':wm' => $watermark, ':fw' => $firmware ?: null,
                    ':bid' => $branchId, ':act' => $isActive, ':per' => !empty($peripherals) ? json_encode($peripherals) : null,
                    ':imei' => $editImei,
                ]);
                $message = 'Equipamento atualizado.';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'Erro: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    } // fi import_batch else
}

// ── Filters ─────────────────────────────────────────────────────
$filterCust   = $_GET['customer_id'] ?? null;
$filterModel  = $_GET['model_id'] ?? null;
$filterStatus = $_GET['filter_status'] ?? null;
$filterOnline = $_GET['filter_online'] ?? null;
$filterSearch = $_GET['search'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = 'WHERE 1=1';
$params = [];

if (!$isAdmin && !$filterCust) {
    if ($customerId) {
        $where .= ' AND d.customer_id = :cid';
        $params[':cid'] = $customerId;
    }
} elseif ($filterCust) {
    $where .= ' AND d.customer_id = :fcid';
    $params[':fcid'] = (int)$filterCust;
}
if ($filterModel) {
    $where .= ' AND d.device_model_id = :mid';
    $params[':mid'] = (int)$filterModel;
}
if ($filterStatus !== null && $filterStatus !== '') {
    $where .= ' AND d.is_active = :st';
    $params[':st'] = (int)$filterStatus;
}
if ($filterOnline === '1') {
    $where .= ' AND TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) <= 5';
} elseif ($filterOnline === '0') {
    $where .= ' AND (TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) > 5 OR d.last_communication IS NULL)';
}
if ($filterSearch) {
    $where .= ' AND (d.imei LIKE :q OR d.device_name LIKE :q2)';
    $params[':q'] = "%$filterSearch%";
    $params[':q2'] = "%$filterSearch%";
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM devices d $where");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

$devicesStmt = $db->prepare("
    SELECT d.imei, d.device_name, d.device_model, d.is_active,
           d.last_communication, d.peripherals, d.streaming_rotation,
           d.streaming_watermark, d.firmware_version, d.branch_id,
           d.created_at,
           dm.model_name, dm.camera_count, dm.protocol,
           c.name as customer_name,
           CASE WHEN TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) <= 5 THEN 1 ELSE 0 END as is_online
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN customers c ON c.id = d.customer_id
    $where
    ORDER BY d.is_active DESC, d.device_name ASC
    LIMIT $perPage OFFSET $offset
");
$devicesStmt->execute($params);
$devices = $devicesStmt->fetchAll();

// Dropdowns
$customers = $db->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();
$models = $db->query("SELECT id, model_name, protocol, camera_count FROM device_models ORDER BY model_name")->fetchAll();
$branches = [];
try {
    $branches = $db->query("SELECT id, name, customer_id FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();
} catch (Exception $e) {}

// ── Edit mode ───────────────────────────────────────────────────
$editDevice = null;
$action = $_GET['action'] ?? '';
$editImei = $_GET['imei'] ?? '';
if ($action === 'editar' && $editImei) {
    $stmt = $db->prepare("SELECT * FROM devices WHERE imei = :imei");
    $stmt->execute([':imei' => $editImei]);
    $editDevice = $stmt->fetch();
}

$isForm = ($action === 'novo' || ($action === 'editar' && $editDevice));

$page_title = 'Equipamentos';
$current_route = 'equipamentos';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($message): ?>
<div class="toast toast-<?= $messageType ?> toast-show" style="position:fixed;bottom:24px;right:24px;z-index:9999;">
    <?= htmlspecialchars($message) ?>
</div>
<script>setTimeout(function(){var t=document.querySelector('.toast');if(t)t.style.display='none';},4000);</script>
<?php endif; ?>

<?php if ($isForm): ?>
<!-- ═══════════ FORM ═══════════ -->
<div class="card" style="max-width:800px;">
    <div class="flex-between mb-24">
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">
            <?= $editDevice ? 'Editar Equipamento' : 'Cadastrar Equipamento' ?>
        </h2>
        <a href="/equipamentos" class="btn btn-outline btn-sm">Voltar</a>
    </div>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editDevice ? 'update' : 'create' ?>">
        <?php if ($editDevice): ?>
        <input type="hidden" name="edit_imei" value="<?= htmlspecialchars($editDevice['imei']) ?>">
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label>IMEI *</label>
                <input type="text" name="imei" value="<?= htmlspecialchars($editDevice['imei'] ?? '') ?>"
                       <?= $editDevice ? 'readonly' : 'required' ?>
                       placeholder="IMEI do dispositivo"
                       class="text-mono" style="font-family:'JetBrains Mono',monospace;">
            </div>
            <div class="form-group">
                <label>Nome do Dispositivo *</label>
                <input type="text" name="device_name" value="<?= htmlspecialchars($editDevice['device_name'] ?? '') ?>" required
                       placeholder="Ex: Câmera Frontal Ônibus 12">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Modelo</label>
                <select name="device_model_id" onchange="onModelChange(this)">
                    <option value="">— Selecione —</option>
                    <?php foreach ($models as $m):
                        $sel = ($editDevice['device_model_id'] ?? '') == $m['id'] ? 'selected' : ''; ?>
                    <option value="<?= $m['id'] ?>" data-cam="<?= $m['camera_count'] ?>" <?= $sel ?>>
                        <?= htmlspecialchars($m['model_name']) ?> (<?= $m['protocol'] ?>, <?= $m['camera_count'] ?> câm.)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Canais (Câmeras)</label>
                <input type="number" name="camera_count" id="camera_count" min="1" max="8"
                       value="<?= $editDevice['camera_count'] ?? 1 ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Firmware</label>
                <input type="text" name="firmware_version" value="<?= htmlspecialchars($editDevice['firmware_version'] ?? '') ?>"
                       placeholder="Versão do firmware" class="text-mono" style="font-family:'JetBrains Mono',monospace;">
            </div>
            <div class="form-group">
                <label>Filial</label>
                <select name="branch_id">
                    <option value="">— Nenhuma —</option>
                    <?php foreach ($branches as $b):
                        $sel = ($editDevice['branch_id'] ?? '') == $b['id'] ? 'selected' : ''; ?>
                    <option value="<?= $b['id'] ?>" <?= $sel ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Periféricos -->
        <div class="form-group">
            <label>Periféricos</label>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php
                $periOptions = ['GPS','WiFi','4G','Bluetooth','Sensor Temperatura','Sensor Combustível',
                    'Leitor RFID','Câmera Interna','Câmera Externa','Áudio','Display LED','Alarme Sonoro'];
                $currentPeri = $editDevice['peripherals'] ? json_decode($editDevice['peripherals'], true) : [];
                if (!is_array($currentPeri)) $currentPeri = [];
                foreach ($periOptions as $po):
                    $checked = in_array($po, $currentPeri) ? 'checked' : '';
                ?>
                <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;padding:4px 10px;border:1px solid var(--hairline);border-radius:20px;<?= $checked?'background:var(--primary-soft);border-color:var(--primary);':'' ?>">
                    <input type="checkbox" name="peripherals[]" value="<?= htmlspecialchars($po) ?>" <?= $checked ?> style="width:auto;">
                    <?= htmlspecialchars($po) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Streaming -->
        <div class="form-row">
            <div class="form-group">
                <label>Rotação do Streaming</label>
                <select name="streaming_rotation">
                    <?php foreach ([0, 90, 180, 270] as $deg):
                        $sel = (int)($editDevice['streaming_rotation'] ?? 0) === $deg ? 'selected' : ''; ?>
                    <option value="<?= $deg ?>" <?= $sel ?>><?= $deg ?>°</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="streaming_watermark" value="1"
                           <?= ($editDevice['streaming_watermark'] ?? 0) ? 'checked' : '' ?> style="width:auto;">
                    Marca d'água no streaming
                </label>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Cliente</label>
                <input type="text" value="<?= htmlspecialchars($editDevice['customer_name'] ?? get_customer()['name'] ?? '—') ?>" readonly
                       style="background:var(--canvas-soft);">
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ($editDevice ? ($editDevice['is_active'] ?? 1) : 1) ? 'checked' : '' ?> style="width:auto;">
                    Equipamento Ativo
                </label>
            </div>
        </div>

        <div class="mt-16">
            <button type="submit" class="btn btn-primary"><?= $editDevice ? 'Salvar Alterações' : 'Cadastrar Equipamento' ?></button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- ═══════════ GRADE PRINCIPAL ═══════════ -->
<div class="flex-between mb-16" style="flex-wrap:wrap;gap:12px;">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">
        Equipamentos
        <span style="font-size:12px;color:var(--muted);font-weight:400;">(<?= $totalRows ?>)</span>
    </h2>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <button class="btn btn-outline btn-sm" onclick="alert('Export Excel em desenvolvimento')">Exportar Excel</button>
        <a href="?action=novo" class="btn btn-primary btn-sm">+ Cadastrar</a>
        <button class="btn btn-outline btn-sm" onclick="showFirmwareModal()">Atualizar Firmware</button>
        <button class="btn btn-outline btn-sm" onclick="showImportModal()">Importar em Lote</button>
    </div>
</div>

<!-- Filters -->
<div class="card mb-16" style="padding:12px 16px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:8px;">
        <?php if ($isAdmin): ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
            <select name="customer_id" style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filterCust == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Modelo</label>
            <select name="model_id" style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <?php foreach ($models as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $filterModel == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['model_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Situação</label>
            <select name="filter_online" style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="1" <?= $filterOnline==='1'?'selected':'' ?>>Online</option>
                <option value="0" <?= $filterOnline==='0'?'selected':'' ?>>Offline</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Status</label>
            <select name="filter_status" style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="1" <?= $filterStatus==='1'?'selected':'' ?>>Ativo</option>
                <option value="0" <?= $filterStatus==='0'?'selected':'' ?>>Inativo</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Busca</label>
            <input type="text" name="search" value="<?= htmlspecialchars($filterSearch ?? '') ?>" placeholder="IMEI ou nome..."
                   style="padding:6px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:160px;">
        </div>
        <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
        <a href="/equipamentos" class="btn btn-outline btn-sm" style="color:var(--muted);">Limpar</a>
    </form>
</div>

<!-- Grade -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>IMEI</th>
                <th>Nome</th>
                <th>Modelo</th>
                <th>Cliente</th>
                <th>Último Heartbeat</th>
                <th>Situação</th>
                <th>Status</th>
                <th style="text-align:center;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($devices)): ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted);">Nenhum equipamento encontrado</td></tr>
            <?php else: ?>
            <?php foreach ($devices as $d): ?>
            <tr>
                <td><span class="text-mono"><?= htmlspecialchars($d['imei']) ?></span></td>
                <td><?= htmlspecialchars($d['device_name'] ?? '—') ?></td>
                <td>
                    <?= htmlspecialchars($d['model_name'] ?? $d['device_model'] ?? '—') ?>
                    <?php if ($d['camera_count']): ?>
                    <span style="font-size:10px;color:var(--muted);">(<?= $d['camera_count'] ?>ch)</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($d['customer_name'] ?? '—') ?></td>
                <td class="text-mono" style="font-size:12px;">
                    <?= $d['last_communication'] ? fmt_brt($d['last_communication']) : 'Nunca' ?>
                </td>
                <td>
                    <?php if ($d['is_online']): ?>
                    <span class="badge badge-success">Online</span>
                    <?php else: ?>
                    <span class="badge badge-error">Offline</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($d['is_active']): ?>
                    <span class="badge badge-success">Ativo</span>
                    <?php else: ?>
                    <span class="badge">Inativo</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <div style="display:flex;gap:4px;justify-content:center;">
                        <a href="?action=editar&imei=<?= urlencode($d['imei']) ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">Editar</a>
                        <?php if ($d['is_online']): ?>
                        <button onclick="sendFirmware('<?= htmlspecialchars($d['imei']) ?>')" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">FOTA</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">
    <span>Página <?= $page ?> de <?= $totalPages ?></span>
    <div style="display:flex;gap:4px;">
        <?php
        $queryStr = $_GET; unset($queryStr['page']);
        $base = http_build_query($queryStr);
        if ($page > 1): ?><a href="?<?= $base ?>&page=<?= $page-1 ?>" class="btn btn-outline btn-sm">&laquo;</a><?php endif;
        for ($i = 1; $i <= min($totalPages, 10); $i++):
            if ($i === $page): ?><span class="btn btn-primary btn-sm" style="pointer-events:none;"><?= $i ?></span>
            <?php else: ?><a href="?<?= $base ?>&page=<?= $i ?>" class="btn btn-outline btn-sm"><?= $i ?></a><?php endif;
        endfor;
        if ($page < $totalPages): ?><a href="?<?= $base ?>&page=<?= $page+1 ?>" class="btn btn-outline btn-sm">&raquo;</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Firmware Modal -->
<div id="firmware-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:999;align-items:center;justify-content:center;">
    <div class="card" style="width:400px;">
        <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Atualização de Firmware (OTA)</h3>
        <p class="text-muted" style="font-size:12px;margin-bottom:16px;">Envia comando de atualização de firmware para o dispositivo.</p>
        <div class="form-group">
            <label>IMEI do Dispositivo</label>
            <input type="text" id="fota-imei" class="text-mono" style="font-family:'JetBrains Mono',monospace;" placeholder="IMEI">
        </div>
        <div class="form-group">
            <label>URL do Firmware</label>
            <input type="text" id="fota-url" placeholder="https://...">
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn btn-outline btn-sm" onclick="closeFirmwareModal()">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="submitFirmware()">Enviar</button>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div id="import-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:999;align-items:center;justify-content:center;">
    <div class="card" style="width:500px;">
        <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Importar Equipamentos em Lote</h3>
        <p class="text-muted" style="font-size:12px;margin-bottom:16px;">
            Faça upload de um arquivo CSV com as colunas: IMEI, Nome, Modelo, Canais, Firmware
        </p>
        <div class="form-group">
            <label>Arquivo CSV</label>
            <input type="file" id="import-file" accept=".csv">
        </div>
        <?= csrf_field() ?>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn btn-outline btn-sm" onclick="closeImportModal()">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="submitImport()">Importar</button>
        </div>
        <div id="import-result" style="margin-top:12px;font-size:12px;"></div>
    </div>
</div>

<script>
function onModelChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    var cam = parseInt(opt.dataset.cam) || 1;
    document.getElementById('camera_count').value = cam;
}

function sendFirmware(imei) {
    document.getElementById('fota-imei').value = imei;
    showFirmwareModal();
}

function showFirmwareModal() { document.getElementById('firmware-modal').style.display = 'flex'; }
function closeFirmwareModal() { document.getElementById('firmware-modal').style.display = 'none'; }

function submitFirmware() {
    var imei = document.getElementById('fota-imei').value;
    var url = document.getElementById('fota-url').value;
    if (!imei) { alert('Informe o IMEI'); return; }
    fetch('/sendcommand', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({imei: imei, proNo: 33027, content: JSON.stringify({firmware_url: url || ''})})
    }).then(function(r) { return r.json(); }).then(function(d) {
        alert(d.code === 0 ? 'Comando de firmware enviado.' : 'Erro: ' + (d.msg || d.iothub_msg));
        closeFirmwareModal();
    }).catch(function() { alert('Erro de rede.'); });
}

function showImportModal() { document.getElementById('import-modal').style.display = 'flex'; }
function closeImportModal() { document.getElementById('import-modal').style.display = 'none'; }

function submitImport() {
    var file = document.getElementById('import-file').files[0];
    if (!file) { alert('Selecione um arquivo CSV'); return; }
    var reader = new FileReader();
    reader.onload = function(e) {
        var lines = e.target.result.split('\n');
        var results = [];
        for (var i = 1; i < lines.length; i++) {
            var cols = lines[i].split(',');
            if (cols.length < 2 || !cols[0].trim()) continue;
            results.push({
                imei: cols[0].trim(),
                name: (cols[1] || '').trim(),
                model: (cols[2] || '').trim(),
                firmware: (cols[4] || '').trim()
            });
        }
        if (results.length === 0) {
            document.getElementById('import-result').innerHTML =
                '<div class="badge badge-error">Nenhuma linha válida encontrada.</div>';
            return;
        }
        document.getElementById('import-result').innerHTML =
            '<div class="badge badge-info"><span class="spinner-inline"></span>Importando ' + results.length + ' dispositivo(s)...</div>';

        var formData = new FormData();
        formData.append('_csrf_token', document.querySelector('input[name="_csrf_token"]').value);
        formData.append('action', 'import_batch');
        formData.append('devices', JSON.stringify(results));

        fetch('', { method: 'POST', body: formData })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                document.getElementById('import-result').innerHTML =
                    '<div class="badge badge-success">Importação concluída. Recarregando...</div>';
                setTimeout(function() { location.reload(); }, 1500);
            })
            .catch(function() {
                document.getElementById('import-result').innerHTML =
                    '<div class="badge badge-error">Erro de rede.</div>';
            });
    };
    reader.readAsText(file);
}
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
