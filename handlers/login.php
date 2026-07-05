<?php
/**
 * JIMI Webhook System — Login v3.1.0
 * Endpoint: /login
 */
require_once __DIR__ . '/../config/database.php';

/**
 * Sanitiza o destino pós-login: aceita apenas paths locais (R05 — open redirect).
 * Rejeita URLs absolutas (https://evil.com), protocol-relative (//evil.com),
 * variações com backslash aceitas por browsers (/\evil.com) e CR/LF.
 *
 * @param mixed $path Valor bruto vindo de $_GET/$_POST
 * @returns string Path local seguro (fallback: /dashboard)
 */
function safe_redirect_path($path) {
    if (!is_string($path) || $path === '' || $path[0] !== '/') return '/dashboard';
    if (isset($path[1]) && $path[1] === '/') return '/dashboard';
    if (strpbrk($path, "\\\r\n") !== false) return '/dashboard';
    return $path;
}

$error = null;
$redirect = safe_redirect_path(isset($_GET['redirect']) ? $_GET['redirect'] : '/dashboard');

try {
    $db = Database::getInstance()->getConnection();
    $hasUsers = (bool) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (Exception $e) {
    $hasUsers = false;
    $error = 'Erro de conexão com o banco de dados.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/auth.php';

    $email    = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $redirect = safe_redirect_path(isset($_POST['redirect']) ? $_POST['redirect'] : '/dashboard');

    if ($email && $password) {
        $result = login_user($email, $password);
        if (isset($result['success']) && $result['success']) {
            header('Location: ' . $redirect);
            exit;
        }
        $error = isset($result['error']) ? $result['error'] : 'Falha na autenticação.';
    } else {
        $error = 'Informe e-mail e senha.';
    }
} else {
    if (!$hasUsers) {
        header('Location: /setup');
        exit;
    }
}

include __DIR__ . '/../web/login_template.php';
