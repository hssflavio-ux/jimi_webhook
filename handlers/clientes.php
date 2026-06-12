<?php
/**
 * JIMI Webhook System — Gestão de Clientes v3.1.0
 * Endpoint: /clientes
 *
 * Acesso restrito a administradores.
 * Lista, cria e edita clientes (multi-tenant).
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$db = Database::getInstance()->getConnection();
$error   = null;
$success = null;

// ── Criar/Editar Cliente ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $doc    = trim($_POST['document'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $addr   = trim($_POST['address'] ?? '');

    if ($action === 'delete' && $id > 1) {
        $stmt = $db->prepare("UPDATE customers SET is_active = 0 WHERE id = ? AND id > 1");
        $stmt->execute([$id]);
        $success = 'Cliente desativado.';
    } elseif ($name) {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE customers SET name=?, document=?, email=?, phone=?, address=? WHERE id=?");
            $stmt->execute([$name, $doc, $email, $phone, $addr, $id]);
            $success = 'Cliente atualizado.';
        } else {
            $stmt = $db->prepare("INSERT INTO customers (name, document, email, phone, address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $doc, $email, $phone, $addr]);
            $success = 'Cliente criado com sucesso.';
        }
    } else {
        $error = 'Nome do cliente é obrigatório.';
    }
}

// ── Listar Clientes ────────────────────────────────────────
$customers = $db->query("
    SELECT c.*, COUNT(d.id) AS device_count
    FROM customers c
    LEFT JOIN devices d ON c.id = d.customer_id
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

$editCustomer = null;
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editCustomer = $stmt->fetch(PDO::FETCH_ASSOC);
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

<div style="display:grid;grid-template-columns:1fr 380px;gap:16px">
    <!-- Lista -->
    <div class="table-wrap">
        <table>
            <thead><tr><th>Cliente</th><th>Documento</th><th>E-mail</th><th>Telefone</th><th>Dispositivos</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($c['name']) ?></td>
                    <td><?= htmlspecialchars($c['document'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($c['email'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($c['phone'] ?? '-') ?></td>
                    <td><?= $c['device_count'] ?></td>
                    <td>
                        <a href="?edit=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <?php if ($c['id'] > 1): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Desativar cliente?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-outline btn-sm" style="color:var(--error)">Desativar</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Formulário -->
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">
            <?= $editCustomer ? 'Editar Cliente' : 'Novo Cliente' ?>
        </h4>
        <form method="post">
            <?php if ($editCustomer): ?><input type="hidden" name="id" value="<?= $editCustomer['id'] ?>"><?php endif; ?>
            <div class="form-group">
                <label>Nome *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editCustomer['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>CPF/CNPJ</label>
                <input type="text" name="document" value="<?= htmlspecialchars($editCustomer['document'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>E-mail</label>
                <input type="email" name="email" value="<?= htmlspecialchars($editCustomer['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Telefone</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($editCustomer['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Endereço</label>
                <textarea name="address" rows="2"><?= htmlspecialchars($editCustomer['address'] ?? '') ?></textarea>
            </div>
            <div class="flex-between mt-16">
                <?php if ($editCustomer): ?>
                <a href="?" class="btn btn-outline btn-sm">Cancelar</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editCustomer ? 'Salvar' : 'Criar Cliente' ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
