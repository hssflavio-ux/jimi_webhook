<?php
/**
 * JIMI Webhook System — Gestão de Motoristas v4.0.0
 * Endpoint: /motoristas
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$db = Database::getInstance()->getConnection();
$user = get_jimi_user();
$customer_id = get_customer_id();
$is_admin = ($user['role'] ?? '') === 'admin';
$today = date('Y-m-d');
$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $stmt = $db->prepare("DELETE FROM drivers WHERE id = ?" . ($is_admin ? '' : ' AND customer_id = ?'));
        $params = [$id];
        if (!$is_admin) $params[] = $customer_id;
        $stmt->execute($params);
        $success = 'Motorista removido.';
    } elseif ($action === 'save') {
        $name           = trim($_POST['name'] ?? '');
        $birth_date     = trim($_POST['birth_date'] ?? '') ?: null;
        $cnh_number     = trim($_POST['cnh_number'] ?? '') ?: null;
        $cnh_category   = trim($_POST['cnh_category'] ?? '') ?: null;
        $cnh_expires    = trim($_POST['cnh_expires_at'] ?? '') ?: null;
        $tox_expires    = trim($_POST['tox_exam_expires_at'] ?? '') ?: null;
        $identifier     = trim($_POST['identifier'] ?? '') ?: null;
        $active         = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name)) {
            $error = 'Nome do motorista é obrigatório.';
        } else {
            try {
                if ($id > 0) {
                    $sql = "UPDATE drivers SET name=?, birth_date=?, cnh_number=?, cnh_category=?, cnh_expires_at=?, tox_exam_expires_at=?, identifier=?, is_active=? WHERE id=?" . ($is_admin ? '' : ' AND customer_id=?');
                    $params = [$name, $birth_date, $cnh_number, $cnh_category, $cnh_expires, $tox_expires, $identifier, $active, $id];
                    if (!$is_admin) $params[] = $customer_id;
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $success = 'Motorista atualizado.';
                } else {
                    $stmt = $db->prepare("INSERT INTO drivers (customer_id, name, birth_date, cnh_number, cnh_category, cnh_expires_at, tox_exam_expires_at, identifier, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$customer_id, $name, $birth_date, $cnh_number, $cnh_category, $cnh_expires, $tox_expires, $identifier, $active]);
                    $success = 'Motorista criado com sucesso.';
                }
            } catch (PDOException $e) {
                $error = 'Erro ao salvar motorista: ' . $e->getMessage();
            }
        }
    }
}

$params = [];
$where = $is_admin ? '1=1' : "d.customer_id = :cid";
if (!$is_admin) $params[':cid'] = $customer_id;
$drvStmt = $db->prepare("
    SELECT d.*
    FROM drivers d
    WHERE $where
    ORDER BY d.name
");
$drvStmt->execute($params);
$drivers = $drvStmt->fetchAll(PDO::FETCH_ASSOC);

$cnh_categories = ['A', 'B', 'AB', 'C', 'D', 'E'];

$editDriver = null;
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM drivers WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editDriver = $stmt->fetch(PDO::FETCH_ASSOC);
}

$page_title    = 'Motoristas';
$current_route = 'motoristas';
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
            <thead><tr><th>Nome</th><th>CNH</th><th>CNH Expira</th><th>Toxicológico Expira</th><th>Identificador</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($drivers as $d):
                    $cnhExpired = $d['cnh_expires_at'] && $d['cnh_expires_at'] < $today;
                    $toxExpired = $d['tox_exam_expires_at'] && $d['tox_exam_expires_at'] < $today;
                ?>
                <tr>
                    <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($d['name']) ?></td>
                    <td class="text-mono"><?= htmlspecialchars($d['cnh_number'] ?? '-') ?><?= $d['cnh_category'] ? ' (' . htmlspecialchars($d['cnh_category']) . ')' : '' ?></td>
                    <td style="<?= $cnhExpired ? 'color:var(--error);font-weight:600' : '' ?>">
                        <?php if ($d['cnh_expires_at']): ?>
                        <?= date('d/m/Y', strtotime($d['cnh_expires_at'])) ?>
                        <?php if ($cnhExpired): ?><span class="badge badge-error" style="margin-left:4px">Vencida</span><?php endif; ?>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td style="<?= $toxExpired ? 'color:var(--error);font-weight:600' : '' ?>">
                        <?php if ($d['tox_exam_expires_at']): ?>
                        <?= date('d/m/Y', strtotime($d['tox_exam_expires_at'])) ?>
                        <?php if ($toxExpired): ?><span class="badge badge-error" style="margin-left:4px">Vencido</span><?php endif; ?>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td class="text-mono"><?= htmlspecialchars($d['identifier'] ?? '-') ?></td>
                    <td>
                        <?php if ($d['is_active']): ?><span class="badge badge-success">Ativo</span>
                        <?php else: ?><span class="badge badge-error">Inativo</span><?php endif; ?>
                    </td>
                    <td>
                        <a href="?edit=<?= $d['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Remover este motorista?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button class="btn btn-outline btn-sm" style="color:var(--error)">Remover</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($drivers)): ?>
                <tr><td colspan="7"><div class="empty-state"><h3>Nenhum motorista</h3><p>Cadastre o primeiro motorista.</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">
            <?= $editDriver ? 'Editar Motorista' : 'Novo Motorista' ?>
        </h4>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editDriver): ?><input type="hidden" name="id" value="<?= $editDriver['id'] ?>"><?php endif; ?>
            <div class="form-group">
                <label>Nome *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editDriver['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Data de Nascimento</label>
                <input type="date" name="birth_date" value="<?= htmlspecialchars($editDriver['birth_date'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>CNH</label>
                <input type="text" name="cnh_number" value="<?= htmlspecialchars($editDriver['cnh_number'] ?? '') ?>" placeholder="Número da CNH">
            </div>
            <div class="form-group">
                <label>Categoria CNH</label>
                <select name="cnh_category">
                    <option value="">Nenhuma</option>
                    <?php foreach ($cnh_categories as $cat): ?>
                    <option value="<?= $cat ?>" <?= (($editDriver['cnh_category'] ?? '') === $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>CNH Expira</label>
                <input type="date" name="cnh_expires_at" value="<?= htmlspecialchars($editDriver['cnh_expires_at'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Toxicológico Expira</label>
                <input type="date" name="tox_exam_expires_at" value="<?= htmlspecialchars($editDriver['tox_exam_expires_at'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Identificador (FaceID/RFID)</label>
                <input type="text" name="identifier" value="<?= htmlspecialchars($editDriver['identifier'] ?? '') ?>" placeholder="ID de vínculo FaceID/RFID">
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="is_active" id="driver-active" value="1" <?= (!isset($editDriver) || ($editDriver['is_active'] ?? 1)) ? 'checked' : '' ?> style="width:auto">
                <label for="driver-active" style="margin:0;text-transform:none;font-size:14px;cursor:pointer">Ativo</label>
            </div>
            <div class="flex-between mt-16">
                <?php if ($editDriver): ?>
                <a href="?" class="btn btn-outline btn-sm">Cancelar</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editDriver ? 'Salvar' : 'Criar Motorista' ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
