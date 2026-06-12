<?php
/**
 * JIMI Webhook System — Módulo de Autenticação v3.1.0
 *
 * Autenticação via token em cookie + tabela `sessions` (MySQL).
 * NÃO depende de session_start() / arquivos de sessão PHP.
 *
 * Uso:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_login();              // Redireciona para /login se não autenticado
 *   $user  = get_current_user();  // Array com dados do usuário
 *   $cust  = get_current_customer(); // Cliente ativo no contexto
 */

require_once __DIR__ . '/../config/database.php';

define('AUTH_COOKIE', 'jimi_token');
define('AUTH_LIFETIME', 86400); // 24 horas

/**
 * Lê o cookie jimi_token e popula o contexto de autenticação
 * a partir da tabela `sessions`. Não usa session_start().
 */
function auth_init() {
    if (!empty($GLOBALS['_auth_initialized'])) return;

    $token = $_COOKIE[AUTH_COOKIE] ?? '';
    if ($token && strlen($token) === 64 && ctype_xdigit($token)) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT user_id, customer_id FROM sessions WHERE id = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $_SESSION['user_id']     = (int)$row['user_id'];
            $_SESSION['customer_id'] = $row['customer_id'] ? (int)$row['customer_id'] : null;
        }
    }

    if (empty($_SESSION['user_id'])) {
        $_SESSION['user_id']     = null;
        $_SESSION['customer_id'] = null;
    }

    $GLOBALS['_auth_initialized'] = true;
}

function require_login() {
    auth_init();
    if (empty($_SESSION['user_id'])) {
        $current = $_SERVER['REQUEST_URI'];
        $redirect = '/login';
        if ($current !== '/login' && $current !== '/setup' && $current !== '/') {
            $redirect .= '?redirect=' . urlencode($current);
        }
        header('Location: ' . $redirect);
        exit;
    }
    refresh_session();
}

function require_admin() {
    require_login();
    $user = get_current_user();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('Acesso restrito ao administrador.');
    }
}

function refresh_session() {
    $token = $_COOKIE[AUTH_COOKIE] ?? '';
    if (!$token) return;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("UPDATE sessions SET expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?");
    $stmt->execute([$token]);
}

function get_current_user() {
    auth_init();
    if (empty($_SESSION['user_id'])) return null;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id, email, name, role, is_active FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function get_current_customer_id() {
    auth_init();
    return $_SESSION['customer_id'] ?? null;
}

function get_current_customer() {
    $cid = get_current_customer_id();
    if (!$cid) return null;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id, name, document, email, phone, is_active FROM customers WHERE id = ?");
    $stmt->execute([$cid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function set_customer_context($customer_id) {
    $_SESSION['customer_id'] = (int)$customer_id;

    $token = $GLOBALS['_auth_token'] ?? ($_COOKIE[AUTH_COOKIE] ?? '');
    if (!$token) return;

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("UPDATE sessions SET customer_id = ? WHERE id = ?");
    $stmt->execute([$customer_id, $token]);

    $stmt = $db->prepare("SELECT role FROM customer_users WHERE customer_id = ? AND user_id = ?");
    $stmt->execute([$customer_id, $_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $_SESSION['customer_role'] = $row['role'] ?? 'viewer';
}

function get_available_customers($user_id) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT c.id, c.name, cu.role
        FROM customers c
        JOIN customer_users cu ON c.id = cu.customer_id
        WHERE cu.user_id = ? AND c.is_active = 1
        ORDER BY c.name
    ");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $stmt = $db->query("SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $rows = [[
                'id'   => $row['id'],
                'name' => $row['name'],
                'role' => 'viewer'
            ]];
        }
    }
    return $rows;
}

function login_user($email, $password) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id, email, name, role, password_hash, is_active FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$user['is_active']) return ['success' => false, 'error' => 'Usuário não encontrado ou inativo.'];
    if (!password_verify($password, $user['password_hash'])) return ['success' => false, 'error' => 'Senha incorreta.'];

    $token = bin2hex(random_bytes(32));
    $GLOBALS['_auth_token'] = $token;

    if (PHP_VERSION_ID >= 70300) {
        setcookie(AUTH_COOKIE, $token, [
            'expires'  => time() + AUTH_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie(AUTH_COOKIE, $token, time() + AUTH_LIFETIME, '/', '', false, true);
    }

    $_SESSION['user_id'] = (int)$user['id'];

    $stmt = $db->prepare("INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
    $stmt->execute([$token, $user['id']]);

    $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);

    $customers = get_available_customers($user['id']);
    if (!empty($customers)) {
        set_customer_context($customers[0]['id']);
    }

    return ['success' => true, 'user' => $user];
}

function logout_user() {
    $token = $_COOKIE[AUTH_COOKIE] ?? '';
    if ($token) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM sessions WHERE id = ?");
        $stmt->execute([$token]);
    }
    if (PHP_VERSION_ID >= 70300) {
        setcookie(AUTH_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie(AUTH_COOKIE, '', time() - 3600, '/', '', false, true);
    }
    $_SESSION = [];
}
