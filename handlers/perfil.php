<?php
/**
 * JIMI Webhook System — Perfil do Usuário v3.2.0
 * Endpoint: /perfil
 *
 * Qualquer usuário logado: visualiza dados pessoais e troca a própria senha.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$db      = Database::getInstance()->getConnection();
$user    = get_jimi_user();
$customer = get_customer();
$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($currentPassword, $hash)) {
        $error = 'Senha atual incorreta.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'A nova senha deve ter no mínimo 6 caracteres.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'As senhas não conferem.';
    } else {
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([password_hash($newPassword, PASSWORD_BCRYPT), $user['id']]);
        $success = 'Senha alterada com sucesso.';
    }
}

$roleLabels = ['admin' => 'Administrador', 'operator' => 'Operador', 'viewer' => 'Visualizador'];

$page_title    = 'Meu Perfil';
$current_route = 'perfil';
include __DIR__ . '/../web/layout_base.php';
?>

<?php if ($error): ?>
<div class="card mb-16" style="border-color:#fce4eb;background:#fef2f5;color:var(--error);font-size:13px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="card mb-16" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:800px">
    <!-- Dados pessoais -->
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">Dados Pessoais</h4>
        <div class="form-group">
            <label>Nome</label>
            <div style="color:var(--ink)"><?= htmlspecialchars($user['name'] ?? '-') ?></div>
        </div>
        <div class="form-group">
            <label>E-mail</label>
            <div style="color:var(--ink)"><?= htmlspecialchars($user['email'] ?? '-') ?></div>
        </div>
        <div class="form-group">
            <label>Cliente Ativo</label>
            <div style="color:var(--ink)"><?= htmlspecialchars($customer['name'] ?? '-') ?></div>
        </div>
        <div class="form-group">
            <label>Função</label>
            <div style="color:var(--ink)"><?= htmlspecialchars($roleLabels[$user['role'] ?? ''] ?? ($user['role'] ?? '-')) ?></div>
        </div>
    </div>

    <!-- Troca de senha -->
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">Alterar Senha</h4>
        <form method="post">
            <div class="form-group">
                <label>Senha Atual *</label>
                <input type="password" name="current_password" required>
            </div>
            <div class="form-group">
                <label>Nova Senha *</label>
                <input type="password" name="new_password" required minlength="6">
            </div>
            <div class="form-group">
                <label>Confirmar Nova Senha *</label>
                <input type="password" name="confirm_password" required minlength="6">
            </div>
            <button type="submit" class="btn btn-primary w-full">Alterar Senha</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
