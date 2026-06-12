<?php
/**
 * JIMI Webhook System — Setup Inicial v3.1.0
 * Endpoint: /setup
 *
 * Cria o primeiro usuário administrador.
 * Só funciona se a tabela users estiver vazia.
 */
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$hasUsers = (bool) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($hasUsers) {
    header('Location: /login');
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!$name || !$email || !$password) {
        $error = 'Todos os campos são obrigatórios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'E-mail inválido.';
    } elseif (strlen($password) < 6) {
        $error = 'A senha deve ter no mínimo 6 caracteres.';
    } elseif ($password !== $confirm) {
        $error = 'As senhas não conferem.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, 'admin')");
            $stmt->execute([$email, $hash, $name]);
            $userId = $db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO customer_users (customer_id, user_id, role) VALUES (1, ?, 'admin')");
            $stmt->execute([$userId]);

            $db->commit();
            $success = 'Administrador criado com sucesso. Redirecionando para o login...';
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Erro ao criar usuário: ' . $e->getMessage();
        }
    }
}

if ($success) {
    header("Refresh: 2; url=/login");
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JIMI — Configuração Inicial</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
    --primary:#f54e00;--primary-active:#d04200;--ink:#26251e;--body:#5a5852;
    --muted:#807d72;--canvas:#f7f7f4;--surface:#ffffff;--hairline:#e6e5e0;
    --error:#cf2d56;--success:#1f8a65;--read:#9fbbe0;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--canvas);color:var(--body);min-height:100vh;display:flex;align-items:center;justify-content:center}
.card{background:var(--surface);border:1px solid var(--hairline);border-radius:12px;padding:40px;width:100%;max-width:420px}
h1{font-size:20px;font-weight:600;color:var(--ink);margin-bottom:8px}
.sub{font-size:13px;color:var(--muted);margin-bottom:28px}
.fg{margin-bottom:18px}
label{display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}
input[type="text"],input[type="email"],input[type="password"]{
    width:100%;padding:10px 12px;font-size:14px;font-family:'Inter',sans-serif;
    border:1px solid var(--hairline);border-radius:6px;color:var(--ink);background:var(--canvas);
    transition:border-color .15s
}
input:focus{outline:none;border-color:var(--primary)}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 24px;font-size:14px;font-weight:500;
    font-family:'Inter',sans-serif;border:none;border-radius:8px;cursor:pointer;transition:background .15s;width:100%}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-active)}
.alert{padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:20px}
.alert-error{background:#fef2f5;color:var(--error);border:1px solid #fce4eb}
.alert-success{background:#f0faf5;color:var(--success);border:1px solid #d4f0e2}
.icon{width:32px;height:32px;background:var(--read);border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:16px}
.icon svg{width:18px;height:18px;color:var(--ink)}
</style>
</head>
<body>
<div class="card">
    <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
    </div>
    <h1>Configuração Inicial</h1>
    <p class="sub">Crie o primeiro administrador do sistema JIMI.</p>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php else: ?>
    <form method="post">
        <div class="fg">
            <label for="name">Nome</label>
            <input type="text" id="name" name="name" required autofocus>
        </div>
        <div class="fg">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required>
        </div>
        <div class="fg">
            <label for="password">Senha</label>
            <input type="password" id="password" name="password" required minlength="6">
        </div>
        <div class="fg">
            <label for="confirm">Confirmar Senha</label>
            <input type="password" id="confirm" name="confirm" required minlength="6">
        </div>
        <button type="submit" class="btn btn-primary">Criar Administrador</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
