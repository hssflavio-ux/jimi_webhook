<?php
/**
 * JIMI Webhook System — Gestão de Clientes v4.0.0
 * Rota: /clientes
 *
 * Evolução YUV: occurrence_config_id, faceid_enabled, brand_color,
 * logo_url, reseller_id, impersonação.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_admin();

$db = Database::getInstance()->getConnection();
$user = get_jimi_user();
$isReseller = ($user['user_type'] ?? '') === 'revendedor';
$error = null;
$success = null;

// ── POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    // RBAC ação fina (v4.2.0 — Fase B2); impersonate já é gated por $isReseller
    if ($action === 'delete') {
        require_permission('clientes', 'delete');
    } elseif ($action !== 'impersonate') {
        require_permission('clientes', $id > 0 ? 'edit' : 'create');
    }

    if ($action === 'delete' && $id > 1) {
        $stmt = $db->prepare("UPDATE customers SET is_active = 0 WHERE id = ? AND id > 1");
        $stmt->execute([$id]);
        $success = 'Cliente desativado.';
    } elseif ($action === 'impersonate' && $id > 0 && $isReseller) {
        set_customer_context($id);
        $stmt = $db->prepare("INSERT INTO impersonation_log (reseller_user_id, customer_id) VALUES (?, ?)");
        $stmt->execute([$user['id'], $id]);
        header('Location: /');
        exit;
    } elseif ($name) {
        $doc    = trim($_POST['document'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $phone  = trim($_POST['phone'] ?? '');
        $addr   = trim($_POST['address'] ?? '');
        $occCfg = !empty($_POST['occurrence_config_id']) ? (int)$_POST['occurrence_config_id'] : null;
        $faceId = !empty($_POST['faceid_enabled']) ? 1 : 0;
        $brand  = trim($_POST['brand_color'] ?? '');
        $logo   = trim($_POST['logo_url'] ?? '');

        if ($id > 0) {
            $stmt = $db->prepare("UPDATE customers SET name=?, document=?, email=?, phone=?, address=?,
                occurrence_config_id=?, faceid_enabled=?, brand_color=?, logo_url=? WHERE id=?");
            $stmt->execute([$name, $doc, $email, $phone, $addr, $occCfg, $faceId, $brand ?: null, $logo ?: null, $id]);
            $success = 'Cliente atualizado.';
        } else {
            $stmt = $db->prepare("INSERT INTO customers (name, document, email, phone, address,
                occurrence_config_id, faceid_enabled, brand_color, logo_url, reseller_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $doc, $email, $phone, $addr, $occCfg, $faceId, $brand ?: null, $logo ?: null, $isReseller ? $user['id'] : null]);
            $success = 'Cliente criado.';
        }
    } else {
        $error = 'Nome do cliente é obrigatório.';
    }
}

// ── List (Fase C: busca + export) ────────────────────────────
$q = trim($_GET['q'] ?? '');
$listWhere = 'WHERE c.is_active = 1';
$listParams = [];
if ($q !== '') {
    $listWhere .= ' AND (c.name LIKE :q1 OR c.email LIKE :q2 OR c.document LIKE :q3)';
    foreach (['q1', 'q2', 'q3'] as $k) $listParams[":$k"] = "%$q%";
}

$listSql = "
    SELECT c.*, COUNT(d.id) AS device_count,
           oc.name as occ_config_name,
           cc.name as checklist_config_name
    FROM customers c
    LEFT JOIN devices d ON c.id = d.customer_id
    LEFT JOIN occurrence_configs oc ON oc.id = c.occurrence_config_id
    LEFT JOIN checklist_configs cc ON cc.id = c.checklist_config_id
    $listWhere
    GROUP BY c.id
    ORDER BY c.name";

// Export síncrono
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('clientes', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expRows = [];
    try {
        $expStmt = $db->prepare($listSql . ' LIMIT ' . SYNC_EXPORT_MAX_ROWS);
        $expStmt->execute($listParams);
        while ($c = $expStmt->fetch()) {
            $expRows[] = [
                $c['name'], $c['email'] ?? '—', $c['document'] ?? '—',
                $c['occ_config_name'] ?? 'Padrão', $c['checklist_config_name'] ?? '—',
                $c['faceid_enabled'] ? 'Sim' : 'Não', (int)$c['device_count'],
            ];
        }
    } catch (Exception $e) {}
    stream_export($export, 'clientes',
        ['Nome', 'E-mail', 'Documento', 'Config. Ocorrência', 'Config. Checklist', 'FaceID', 'Dispositivos'],
        $expRows, 'Clientes');
}

$customers = [];
try {
    $listStmt = $db->prepare($listSql);
    $listStmt->execute($listParams);
    $customers = $listStmt->fetchAll();
} catch (Exception $e) {
    // checklist_configs pode não existir (schema antigo) → query sem o JOIN
    try {
        $listStmt = $db->prepare(str_replace(
            ["cc.name as checklist_config_name", "LEFT JOIN checklist_configs cc ON cc.id = c.checklist_config_id"],
            ["NULL as checklist_config_name", ""],
            $listSql));
        $listStmt->execute($listParams);
        $customers = $listStmt->fetchAll();
    } catch (Exception $e2) {}
}

$occConfigs = [];
try {
    $occConfigs = $db->query("SELECT id, name FROM occurrence_configs ORDER BY name")->fetchAll();
} catch (Exception $e) {}

$editCustomer = null;
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editCustomer = $stmt->fetch();
}

$page_title    = 'Clientes';
$current_route = 'clientes';
include __DIR__ . '/../web/layout_base.php';
?>

<?php if ($error): ?>
<div class="card mb-16" style="border-color:#fce4eb;background:#fef2f5;color:var(--error);font-size:13px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="card mb-16" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div style="display:grid;grid-template-columns:1fr 400px;gap:16px">
    <div>
    <div class="flex-between mb-12" style="gap:8px;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:6px;">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Pesquisar nome, e-mail, documento..."
                   style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:280px;">
            <button type="submit" class="btn btn-outline btn-sm">Pesquisar</button>
        </form>
        <div style="display:flex;gap:6px;">
            <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
            <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Cliente</th><th>E-mail</th><th>Config. Ocorr.</th><th>Config. Checklist</th><th>FaceID</th><th>Dispositivos</th><th style="text-align:center;">Ações</th></tr></thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td style="font-weight:500;color:var(--ink);">
                        <?= htmlspecialchars($c['name']) ?>
                        <?php if ($c['brand_color']): ?>
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($c['brand_color']) ?>;margin-left:6px;vertical-align:middle;"></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?= htmlspecialchars($c['email'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($c['occ_config_name'] ?? 'Padrão') ?></td>
                    <td><?= htmlspecialchars($c['checklist_config_name'] ?? '—') ?></td>
                    <td><?= $c['faceid_enabled'] ? '<span class="badge badge-primary">Ativo</span>' : '<span class="badge">Desativado</span>' ?></td>
                    <td><?= $c['device_count'] ?></td>
                    <td style="text-align:center;">
                        <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                            <a href="?edit=<?= $c['id'] ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">Editar</a>
                            <?php if ($isReseller): ?>
                            <form method="post" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="impersonate">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;color:var(--primary);">Entrar como</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($c['id'] > 1): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Desativar cliente?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;color:var(--error);">Desativar</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>

    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">
            <?= $editCustomer ? 'Editar Cliente' : 'Novo Cliente' ?>
        </h4>
        <form method="post">
            <?= csrf_field() ?>
            <?php if ($editCustomer): ?><input type="hidden" name="id" value="<?= $editCustomer['id'] ?>"><?php endif; ?>
            <div class="form-group"><label>Nome *</label><input type="text" name="name" required value="<?= htmlspecialchars($editCustomer['name'] ?? '') ?>"></div>
            <div class="form-group"><label>CPF/CNPJ</label><input type="text" name="document" value="<?= htmlspecialchars($editCustomer['document'] ?? '') ?>"></div>
            <div class="form-group"><label>E-mail</label><input type="email" name="email" value="<?= htmlspecialchars($editCustomer['email'] ?? '') ?>"></div>
            <div class="form-group"><label>Telefone</label><input type="text" name="phone" value="<?= htmlspecialchars($editCustomer['phone'] ?? '') ?>"></div>
            <div class="form-group"><label>Endereço</label><textarea name="address" rows="2"><?= htmlspecialchars($editCustomer['address'] ?? '') ?></textarea></div>
            <div class="form-group">
                <label>Configuração de Ocorrência</label>
                <select name="occurrence_config_id">
                    <option value="">— Padrão do Sistema —</option>
                    <?php foreach ($occConfigs as $oc):
                        $sel = ($editCustomer['occurrence_config_id'] ?? '') == $oc['id'] ? 'selected' : ''; ?>
                    <option value="<?= $oc['id'] ?>" <?= $sel ?>><?= htmlspecialchars($oc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Cor da Marca</label>
                <input type="text" name="brand_color" value="<?= htmlspecialchars($editCustomer['brand_color'] ?? '') ?>" placeholder="#0052ff">
            </div>
            <div class="form-group">
                <label>URL do Logo</label>
                <input type="text" name="logo_url" value="<?= htmlspecialchars($editCustomer['logo_url'] ?? '') ?>" placeholder="https://...">
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="faceid_enabled" value="1" <?= ($editCustomer['faceid_enabled'] ?? 0) ? 'checked' : '' ?> style="width:auto;">
                    FaceID Habilitado
                </label>
            </div>
            <div class="flex-between mt-16">
                <?php if ($editCustomer): ?><a href="?" class="btn btn-outline btn-sm">Cancelar</a><?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editCustomer ? 'Salvar' : 'Criar Cliente' ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
