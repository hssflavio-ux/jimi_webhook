<?php
/**
 * JIMI Webhook System — Gestão de Usuários v3.2.0
 * Endpoint: /usuarios
 *
 * Acesso restrito a administradores.
 * Lista, cria e edita usuários, vinculando-os a um cliente (customer_users).
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$db = Database::getInstance()->getConnection();
$currentUser = get_jimi_user();
$error   = null;
$success = null;

// ── Criar/Editar/Ativar-Desativar Usuário ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle') {
        if ($id === (int)$currentUser['id']) {
            $error = 'Você não pode desativar seu próprio usuário.';
        } else {
            $newStatus = (int)($_POST['is_active'] ?? 0);
            $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            $success = $newStatus ? 'Usuário ativado.' : 'Usuário desativado.';
        }
    } elseif ($action === 'save') {
        $name       = trim($_POST['name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';
        $role       = $_POST['role'] ?? 'operator';
        $customerId = (int)($_POST['customer_id'] ?? 0);

        $validRoles = ['admin', 'operator', 'viewer'];
        if (!in_array($role, $validRoles, true)) $role = 'operator';

        if (!$name || !$email || !$customerId) {
            $error = 'Nome, e-mail e cliente são obrigatórios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'E-mail inválido.';
        } elseif ($id === 0 && strlen($password) < 6) {
            $error = 'A senha deve ter no mínimo 6 caracteres.';
        } elseif ($password !== '' && strlen($password) < 6) {
            $error = 'A senha deve ter no mínimo 6 caracteres.';
        } else {
            try {
                $db->beginTransaction();

                if ($id > 0) {
                    if ($password !== '') {
                        $stmt = $db->prepare("UPDATE users SET name=?, email=?, role=?, password_hash=? WHERE id=?");
                        $stmt->execute([$name, $email, $role, password_hash($password, PASSWORD_BCRYPT), $id]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET name=?, email=?, role=? WHERE id=?");
                        $stmt->execute([$name, $email, $role, $id]);
                    }
                    $userId = $id;
                } else {
                    $stmt = $db->prepare("INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$email, password_hash($password, PASSWORD_BCRYPT), $name, $role]);
                    $userId = (int)$db->lastInsertId();
                }

                $stmt = $db->prepare("DELETE FROM customer_users WHERE user_id = ?");
                $stmt->execute([$userId]);
                $stmt = $db->prepare("INSERT INTO customer_users (customer_id, user_id, role) VALUES (?, ?, ?)");
                $stmt->execute([$customerId, $userId, $role]);

                $db->commit();
                $success = $id > 0 ? 'Usuário atualizado.' : 'Usuário criado com sucesso.';
            } catch (PDOException $e) {
                $db->rollBack();
                $error = ($e->getCode() === '23000')
                    ? 'Já existe um usuário com esse e-mail.'
                    : 'Erro ao salvar usuário: ' . $e->getMessage();
            }
        }
    }
}

// ── Listar Usuários ─────────────────────────────────────────────────────────
$users = $db->query("
    SELECT u.id, u.name, u.email, u.role, u.is_active, u.last_login,
           GROUP_CONCAT(c.name SEPARATOR ', ') AS customer_names
    FROM users u
    LEFT JOIN customer_users cu ON cu.user_id = u.id
    LEFT JOIN customers c ON c.id = cu.customer_id
    GROUP BY u.id
    ORDER BY u.name
")->fetchAll(PDO::FETCH_ASSOC);

$customers = $db->query("SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$editUser = null;
$editCustomerId = null;
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editUser) {
        $stmt = $db->prepare("SELECT customer_id FROM customer_users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$editUser['id']]);
        $editCustomerId = $stmt->fetchColumn();
    }
}

$roleLabels = ['admin' => 'Administrador', 'operator' => 'Operador', 'viewer' => 'Visualizador'];

$page_title    = 'Usuários';
$current_route = 'usuarios';
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
            <thead><tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Cliente(s)</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($u['name']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($roleLabels[$u['role']] ?? $u['role']) ?></td>
                    <td><?= htmlspecialchars($u['customer_names'] ?? '-') ?></td>
                    <td>
                        <?php if ($u['is_active']): ?><span class="badge badge-success">Ativo</span>
                        <?php else: ?><span class="badge" style="background:var(--surface-strong);color:var(--muted)">Inativo</span><?php endif; ?>
                    </td>
                    <td>
                        <a href="?edit=<?= $u['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <?php if ((int)$u['id'] !== (int)$currentUser['id']): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('<?= $u['is_active'] ? 'Desativar' : 'Ativar' ?> usuário?')">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= $u['is_active'] ? 0 : 1 ?>">
                            <button class="btn btn-outline btn-sm" style="color:var(--error)"><?= $u['is_active'] ? 'Desativar' : 'Ativar' ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                <tr><td colspan="6"><div class="empty-state"><h3>Nenhum usuário</h3></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Formulário -->
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">
            <?= $editUser ? 'Editar Usuário' : 'Novo Usuário' ?>
        </h4>
        <form method="post">
            <input type="hidden" name="action" value="save">
            <?php if ($editUser): ?><input type="hidden" name="id" value="<?= $editUser['id'] ?>"><?php endif; ?>
            <div class="form-group">
                <label>Nome *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editUser['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>E-mail *</label>
                <input type="email" name="email" required value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Senha <?= $editUser ? '(deixe em branco para manter)' : '*' ?></label>
                <input type="password" name="password" minlength="6" <?= $editUser ? '' : 'required' ?>>
            </div>
            <div class="form-group">
                <label>Papel *</label>
                <select name="role" required>
                    <?php foreach ($roleLabels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= (($editUser['role'] ?? 'operator') === $val) ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Cliente *</label>
                <select name="customer_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ((string)$editCustomerId === (string)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-between mt-16">
                <?php if ($editUser): ?>
                <a href="?" class="btn btn-outline btn-sm">Cancelar</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editUser ? 'Salvar' : 'Criar Usuário' ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
