<?php
/**
 * JIMI Webhook System — Módulo de Autenticação v3.1.0
 *
 * Gerencia sessões de usuário via cookie + tabela `sessions`.
 * Todas as páginas do dashboard devem chamar require_login().
 *
 * Uso:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_login();              // Redireciona para /login se não autenticado
 *   $user  = get_current_user();  // Array com dados do usuário
 *   $cust  = get_current_customer(); // Cliente ativo no contexto
 */

require_once __DIR__ . '/../config/database.php';

define('SESSION_COOKIE', 'jimi_session');
define('SESSION_LIFETIME', 86400); // 24 horas

function start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(SESSION_COOKIE);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function require_login() {
    start_session();
    if (empty($_SESSION['user_id'])) {
        $redirect = '/login';
        $current = $_SERVER['REQUEST_URI'];
        if ($current !== '/login' && $current !== '/setup') {
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
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die('Acesso restrito ao administrador.');
    }
}

function refresh_session() {
    if (empty($_SESSION['user_id'])) return;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("UPDATE sessions SET expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ? AND user_id = ?");
    $stmt->execute([session_id(), $_SESSION['user_id']]);
}

function get_current_user() {
    if (empty($_SESSION['user_id'])) return null;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id, email, name, role, is_active FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function get_current_customer_id() {
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
    start_session();
    $_SESSION['customer_id'] = (int)$customer_id;

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("UPDATE sessions SET customer_id = ? WHERE id = ?");
    $stmt->execute([$customer_id, session_id()]);

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
                'id' => $row['id'],
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

    start_session();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];

    $sid = session_id();
    $stmt = $db->prepare("INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
    $stmt->execute([$sid, $user['id']]);

    $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);

    $customers = get_available_customers($user['id']);
    if (!empty($customers)) {
        set_customer_context($customers[0]['id']);
    }

    return ['success' => true, 'user' => $user];
}

function logout_user() {
    start_session();
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("DELETE FROM sessions WHERE id = ?");
    $stmt->execute([session_id()]);
    session_destroy();
    setcookie(SESSION_COOKIE, '', time() - 3600, '/');
}
