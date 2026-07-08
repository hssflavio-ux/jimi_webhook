<?php
require_once __DIR__ . '/../config/database.php';

define('AUTH_COOKIE', 'jimi_token');
define('AUTH_LIFETIME', 86400);

function auth_init() {
    if (isset($GLOBALS['_auth_initialized'])) return;

    if (isset($_COOKIE[AUTH_COOKIE])) {
        $token = $_COOKIE[AUTH_COOKIE];
        if (strlen($token) === 64) {
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT user_id, customer_id FROM sessions WHERE id = ? AND expires_at > NOW()");
                $stmt->execute(array($token));
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $_SESSION['user_id'] = (int)$row['user_id'];
                    $_SESSION['customer_id'] = isset($row['customer_id']) ? (int)$row['customer_id'] : null;
                }
            } catch (Exception $e) {
                error_log('auth_init: ' . $e->getMessage());
            }
        }
    }

    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = null;
        $_SESSION['customer_id'] = null;
    }

    // Periodic cleanup: ~1% of requests
    if (mt_rand(1, 100) === 1) {
        auth_cleanup();
    }

    $GLOBALS['_auth_initialized'] = true;
}

function auth_cleanup() {
    try {
        $db = Database::getInstance()->getConnection();
        $db->exec("DELETE FROM sessions WHERE expires_at < NOW()");
        $db->exec("DELETE FROM request_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } catch (Exception $e) {
        error_log('auth_cleanup: ' . $e->getMessage());
    }
}

function require_login() {
    auth_init();
    if (empty($_SESSION['user_id'])) {
        $current = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $redirect = '/login';
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
    $user = get_jimi_user();
    if (isset($user['role']) && $user['role'] !== 'admin') {
        http_response_code(403);
        die('Acesso restrito ao administrador.');
    }
}

/**
 * Exige sessão de dashboard ativa para endpoints AJAX (resposta JSON).
 * Ao contrário de require_login(), não redireciona: responde 401 em JSON e encerra.
 * O chamador deve ter definido o header Content-Type: application/json.
 *
 * @returns int ID do usuário autenticado
 */
function require_ajax_session() {
    auth_init();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(array('code' => 401, 'msg' => 'Unauthorized'));
        exit;
    }
    return (int)$_SESSION['user_id'];
}

function refresh_session() {
    if (!isset($_COOKIE[AUTH_COOKIE])) return;
    $token = $_COOKIE[AUTH_COOKIE];
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE sessions SET expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?");
        $stmt->execute(array($token));
    } catch (Exception $e) {}
}

function get_jimi_user() {
    auth_init();
    if (empty($_SESSION['user_id'])) return null;
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, email, name, role, is_active FROM users WHERE id = ?");
        $stmt->execute(array($_SESSION['user_id']));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}

function get_customer_id() {
    auth_init();
    return isset($_SESSION['customer_id']) ? $_SESSION['customer_id'] : null;
}

function get_customer() {
    $cid = get_customer_id();
    if (!$cid) return null;
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, name, document, email, phone, is_active FROM customers WHERE id = ?");
        $stmt->execute(array($cid));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}

function set_customer_context($customer_id) {
    $_SESSION['customer_id'] = (int)$customer_id;

    $token = '';
    if (isset($GLOBALS['_auth_token'])) {
        $token = $GLOBALS['_auth_token'];
    } elseif (isset($_COOKIE[AUTH_COOKIE])) {
        $token = $_COOKIE[AUTH_COOKIE];
    }
    if ($token === '') return;

    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE sessions SET customer_id = ? WHERE id = ?");
        $stmt->execute(array($customer_id, $token));

        $stmt = $db->prepare("SELECT role FROM customer_users WHERE customer_id = ? AND user_id = ?");
        $stmt->execute(array($customer_id, $_SESSION['user_id']));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['customer_role'] = isset($row['role']) ? $row['role'] : 'viewer';
    } catch (Exception $e) {}
}

function get_available_customers($user_id) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT c.id, c.name, cu.role FROM customers c JOIN customer_users cu ON c.id = cu.customer_id WHERE cu.user_id = ? AND c.is_active = 1 ORDER BY c.name");
        $stmt->execute(array($user_id));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $stmt = $db->query("SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name LIMIT 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $rows = array(array('id' => $row['id'], 'name' => $row['name'], 'role' => 'viewer'));
            }
        }
        return $rows;
    } catch (Exception $e) {
        return array();
    }
}

function _gen_token() {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(32));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes(32));
    }
    return md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true));
}

function login_user($email, $password) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

    try {
        $db = Database::getInstance()->getConnection();

        // Rate limiting: max 5 failed attempts per IP in 15 minutes
        // Graceful fallback if login_log table doesn't exist yet
        try {
            $rateStmt = $db->prepare("
                SELECT COUNT(*) FROM login_log
                WHERE ip_address = :ip AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $rateStmt->execute([':ip' => $ip]);
            $failedCount = (int)$rateStmt->fetchColumn();

            if ($failedCount >= 5) {
                return array('success' => false, 'error' => 'Muitas tentativas. Tente novamente em 15 minutos.');
            }
        } catch (Exception $e) {
            error_log('login_user rate-limit skip: ' . $e->getMessage());
        }

        $stmt = $db->prepare("SELECT id, email, name, role, password_hash, is_active FROM users WHERE email = ?");
        $stmt->execute(array($email));
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['is_active'])) {
            _log_login($db, $email, $ip, $ua, false);
            return array('success' => false, 'error' => 'Usuário não encontrado ou inativo.');
        }
        if (!password_verify($password, $user['password_hash'])) {
            _log_login($db, $email, $ip, $ua, false);
            return array('success' => false, 'error' => 'Senha incorreta.');
        }

        _log_login($db, $email, $ip, $ua, true);

        $token = _gen_token();
        $GLOBALS['_auth_token'] = $token;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(AUTH_COOKIE, $token, [
            'expires'  => time() + AUTH_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_SESSION['user_id'] = (int)$user['id'];

        $stmt = $db->prepare("INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
        $stmt->execute(array($token, $user['id']));

        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute(array($user['id']));

        $customers = get_available_customers($user['id']);
        if (!empty($customers)) {
            set_customer_context($customers[0]['id']);
        }

        return array('success' => true, 'user' => $user);
    } catch (Exception $e) {
        error_log('login_user: ' . $e->getMessage());
        return array('success' => false, 'error' => 'Erro interno: ' . $e->getMessage());
    }
}

function _log_login($db, $email, $ip, $ua, $success) {
    try {
        $stmt = $db->prepare("INSERT INTO login_log (email, ip_address, success, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([$email, $ip, $success ? 1 : 0, mb_substr($ua ?? '', 0, 500)]);
    } catch (Exception $e) {
        error_log('_log_login: ' . $e->getMessage());
    }
}

function logout_user() {
    if (isset($_COOKIE[AUTH_COOKIE])) {
        $token = $_COOKIE[AUTH_COOKIE];
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("DELETE FROM sessions WHERE id = ?");
            $stmt->execute(array($token));
        } catch (Exception $e) {}
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(AUTH_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_SESSION = array();
}
