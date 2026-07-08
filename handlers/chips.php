<?php
/**
 * JIMI Webhook System — Gestão de Chips SIM v4.0.0
 * Endpoint: /chips
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$db = Database::getInstance()->getConnection();
$user = get_jimi_user();
$customer_id = get_customer_id();
$is_admin = ($user['role'] ?? '') === 'admin';
$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action  = $_POST['action'] ?? '';
    $id      = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $stmt = $db->prepare("DELETE FROM sim_cards WHERE id = ?" . ($is_admin ? '' : ' AND customer_id = ?'));
        $params = [$id];
        if (!$is_admin) $params[] = $customer_id;
        $stmt->execute($params);
        $success = 'Chip removido.';
    } elseif ($action === 'save') {
        $carrier = trim($_POST['carrier'] ?? '');
        $msisdn  = trim($_POST['msisdn'] ?? '');
        $iccid   = trim($_POST['iccid'] ?? '');
        $imei    = trim($_POST['imei'] ?? '');
        $active  = isset($_POST['is_active']) ? 1 : 0;

        if (empty($carrier) && empty($msisdn) && empty($iccid)) {
            $error = 'Preencha ao menos um campo (Operadora, Número ou ICCID).';
        } else {
            try {
                if ($id > 0) {
                    $sql = "UPDATE sim_cards SET carrier=?, msisdn=?, iccid=?, imei=?, is_active=? WHERE id=?" . ($is_admin ? '' : ' AND customer_id=?');
                    $params = [$carrier, $msisdn, $iccid, $imei ?: null, $active, $id];
                    if (!$is_admin) $params[] = $customer_id;
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $success = 'Chip atualizado.';
                } else {
                    $stmt = $db->prepare("INSERT INTO sim_cards (customer_id, carrier, msisdn, iccid, imei, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$customer_id, $carrier, $msisdn, $iccid, $imei ?: null, $active]);
                    $success = 'Chip criado com sucesso.';
                }
            } catch (PDOException $e) {
                $error = 'Erro ao salvar chip: ' . $e->getMessage();
            }
        }
    }
}

$params = [];
$where = $is_admin ? '1=1' : "s.customer_id = :cid";
if (!$is_admin) $params[':cid'] = $customer_id;
$simCardsStmt = $db->prepare("
    SELECT s.*, d.device_name
    FROM sim_cards s
    LEFT JOIN devices d ON d.imei = s.imei
    WHERE $where
    ORDER BY s.created_at DESC
");
$simCardsStmt->execute($params);
$sim_cards = $simCardsStmt->fetchAll(PDO::FETCH_ASSOC);

$devStmt = $db->prepare("SELECT imei, device_name FROM devices WHERE customer_id = :cid AND is_active = 1 ORDER BY device_name");
$devStmt->execute([':cid' => $customer_id]);
$devices = $devStmt->fetchAll(PDO::FETCH_ASSOC);

$editChip = null;
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM sim_cards WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editChip = $stmt->fetch(PDO::FETCH_ASSOC);
}

$page_title    = 'Chips';
$current_route = 'chips';
include __DIR__ . '/../web/layout_base.php';
?>

<?php if ($error): ?>
<div class="card mb-16" style="border-color:#fce4eb;background:#fef2f5;color:var(--error);font-size:13px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="card mb-16" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:16px">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Operadora</th><th>Número (MSISDN)</th><th>ICCID</th><th>IMEI (vinculado)</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($sim_cards as $c): ?>
                <tr>
                    <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($c['carrier'] ?? '-') ?></td>
                    <td class="text-mono"><?= htmlspecialchars($c['msisdn'] ?? '-') ?></td>
                    <td class="text-mono"><?= htmlspecialchars($c['iccid'] ?? '-') ?></td>
                    <td class="text-mono">
                        <?php if (!empty($c['imei'])): ?>
                        <a href="/ativos/<?= urlencode($c['imei']) ?>" style="color:var(--primary);text-decoration:none"><?= htmlspecialchars($c['imei']) ?></a>
                        <span style="font-size:11px;color:var(--muted);display:block"><?= htmlspecialchars($c['device_name'] ?? '') ?></span>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['is_active']): ?><span class="badge badge-success">Ativo</span>
                        <?php else: ?><span class="badge badge-error">Inativo</span><?php endif; ?>
                    </td>
                    <td>
                        <a href="?edit=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Remover este chip?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button class="btn btn-outline btn-sm" style="color:var(--error)">Remover</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sim_cards)): ?>
                <tr><td colspan="6"><div class="empty-state"><h3>Nenhum chip</h3><p>Cadastre um chip SIM para começar.</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">
            <?= $editChip ? 'Editar Chip' : 'Novo Chip' ?>
        </h4>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editChip): ?><input type="hidden" name="id" value="<?= $editChip['id'] ?>"><?php endif; ?>
            <div class="form-group">
                <label>Operadora</label>
                <input type="text" name="carrier" value="<?= htmlspecialchars($editChip['carrier'] ?? '') ?>" placeholder="Vivo, Claro, TIM...">
            </div>
            <div class="form-group">
                <label>Número (MSISDN)</label>
                <input type="text" name="msisdn" value="<?= htmlspecialchars($editChip['msisdn'] ?? '') ?>" placeholder="5511999999999">
            </div>
            <div class="form-group">
                <label>ICCID</label>
                <input type="text" name="iccid" value="<?= htmlspecialchars($editChip['iccid'] ?? '') ?>" placeholder="8955...">
            </div>
            <div class="form-group">
                <label>IMEI (vinculado)</label>
                <select name="imei">
                    <option value="">Nenhum</option>
                    <?php foreach ($devices as $dev): ?>
                    <option value="<?= htmlspecialchars($dev['imei']) ?>" <?= (($editChip['imei'] ?? '') === $dev['imei']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dev['device_name'] ?: $dev['imei']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="is_active" id="chip-active" value="1" <?= (!isset($editChip) || ($editChip['is_active'] ?? 1)) ? 'checked' : '' ?> style="width:auto">
                <label for="chip-active" style="margin:0;text-transform:none;font-size:14px;cursor:pointer">Ativo</label>
            </div>
            <div class="flex-between mt-16">
                <?php if ($editChip): ?>
                <a href="?" class="btn btn-outline btn-sm">Cancelar</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editChip ? 'Salvar' : 'Criar Chip' ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
