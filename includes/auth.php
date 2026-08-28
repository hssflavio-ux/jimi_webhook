<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php'; // fmt_brt() e helpers de data para todo o dashboard
require_once __DIR__ . '/../core/Logger.php';

define('AUTH_COOKIE', 'jimi_token');
define('AUTH_LIFETIME', 86400);

// ── Handler global de erros do dashboard ─────────────────────────────────────
// Páginas e endpoints AJAX incluem este arquivo; os webhooks (push*) não — eles
// têm o try/catch próprio do WebhookHandler. Sem isto, exceção não tratada ou
// fatal em página vira tela branca sem rastro no log da aplicação (só no error
// log do FPM). Registra apenas exceção não capturada + erro fatal — warnings e
// notices continuam fora para não poluir.
set_exception_handler(function ($e) {
    Logger::error('Exceção não tratada no dashboard', [
        'class'   => get_class($e),
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    if (!headers_sent()) http_response_code(500);
    echo 'Erro interno — o detalhe foi registrado no log.';
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Logger::critical('Erro fatal PHP no dashboard', $err);
    }
});

function auth_init() {
    // Retorna se há usuário autenticado (endpoints AJAX usam `if (!auth_init())`)
    if (isset($GLOBALS['_auth_initialized'])) return !empty($_SESSION['user_id']);

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
    return !empty($_SESSION['user_id']);
}

function auth_cleanup() {
    try {
        $db = Database::getInstance()->getConnection();
        $db->exec("DELETE FROM sessions WHERE expires_at < NOW()");
        $db->exec("DELETE FROM request_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        // v4.4.0: notificações crescem rápido (1 por ocorrência nova).
        // Lidas duram 30 dias; não lidas, 90 — depois disso ninguém mais vai ler.
        // try/catch próprio: base sem a migração v4.4.0 não pode quebrar o login.
        try {
            $db->exec("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $db->exec("DELETE FROM notifications WHERE is_read = 0 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        } catch (Exception $e) {}
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
    require_password_change_gate();
}

/**
 * Indica se o usuário logado está com senha TEMPORÁRIA pendente de troca.
 *
 * Resultado cacheado no request: `require_login()` roda uma vez por página, mas
 * handlers que chamam `require_admin()` (que chama `require_login()`) e depois
 * `require_permission()` não pagam a consulta de novo.
 *
 * @returns bool
 */
function user_must_change_password() {
    if (empty($_SESSION['user_id'])) return false;
    if (array_key_exists('_jimi_must_change_pw', $GLOBALS)) return $GLOBALS['_jimi_must_change_pw'];

    $GLOBALS['_jimi_must_change_pw'] = false;
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT must_change_password FROM users WHERE id = ?");
        $stmt->execute(array($_SESSION['user_id']));
        $GLOBALS['_jimi_must_change_pw'] = (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        // Coluna ausente (banco antes da v4.13.21) NÃO pode trancar ninguém
        // fora do sistema: falha em aberto, registrando.
        error_log('user_must_change_password: ' . $e->getMessage());
    }
    return $GLOBALS['_jimi_must_change_pw'];
}

/**
 * Prende em `/trocar-senha` quem entrou com senha temporária (v4.13.21).
 *
 * 🔴 Mora aqui, no `require_login()`, e não no `login.php`. Redirecionar só na
 * tela de login deixa a trava valendo por convenção: bastava digitar
 * `/rastreamento` na barra de endereço para seguir usando o sistema inteiro com
 * a senha que veio por e-mail — que é justamente a senha que mais gente já viu.
 *
 * Exceções: a própria tela de troca, o logout (a saída tem de continuar
 * possível) e as duas rotas que já existem fora da sessão (`/login`,
 * `/setup`). Tudo o mais — inclusive `/perfil` e a troca de cliente — cai na
 * trava: enquanto a senha for temporária não há nada a fazer no sistema.
 *
 * @returns void
 */
function require_password_change_gate() {
    $uri  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
    if ($path === '') $path = '/';
    $livres = array('/trocar-senha', '/logout', '/login', '/setup');
    if (in_array($path, $livres, true)) return;
    if (!user_must_change_password()) return;

    header('Location: /trocar-senha');
    exit;
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
        // user_type/permission_group_id/photo_url (v4.0.0) entram no SELECT — vários
        // handlers testam $user['user_type'] === 'revendedor' (visões de revendedor)
        $stmt = $db->prepare("SELECT id, email, name, role, is_active, user_type, permission_group_id, photo_url FROM users WHERE id = ?");
        $stmt->execute(array($_SESSION['user_id']));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) {
        // Colunas v4 podem não existir em bancos antigos — fallback ao SELECT legado
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id, email, name, role, is_active FROM users WHERE id = ?");
            $stmt->execute(array($_SESSION['user_id']));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (Exception $e2) {
            return null;
        }
    }
}

/**
 * RBAC efetivo (v4.2.0 — Fase B2 do PLANO_ADERENCIA_YUV).
 *
 * Lê a matriz JSON de permission_groups.permissions do grupo do usuário logado:
 *   { "<tela>": ["view","create","edit","delete","export"], ... }
 * (chaves de tela conforme handlers/grupos_permissao.php — relatórios agrupados
 * na chave única 'relatorios').
 *
 * @returns array|null Matriz decodificada, ou NULL se o usuário não tem grupo
 *                     (sem grupo → sem restrição; vale o role legado admin/operator/viewer)
 */
function get_user_permissions() {
    auth_init();
    if (empty($_SESSION['user_id'])) return null;
    if (array_key_exists('_jimi_permissions', $GLOBALS)) return $GLOBALS['_jimi_permissions'];
    $GLOBALS['_jimi_permissions'] = null;
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT pg.permissions FROM users u
                              JOIN permission_groups pg ON pg.id = u.permission_group_id
                              WHERE u.id = ?");
        $stmt->execute(array($_SESSION['user_id']));
        $json = $stmt->fetchColumn();
        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) $GLOBALS['_jimi_permissions'] = $decoded;
        }
    } catch (Exception $e) {}
    return $GLOBALS['_jimi_permissions'];
}

/**
 * O usuário logado pode executar a ação na tela?
 *
 * @param string $screen Chave da tela (ex.: 'ativos', 'relatorios', 'usuarios')
 * @param string $action view|create|edit|delete|export
 * @returns bool
 */
function can($screen, $action = 'view') {
    $perms = get_user_permissions();
    if ($perms === null) return true; // sem grupo de permissão → sem restrição
    // Wildcard do seed "Administrador" (migration v4.0.0): {"*": ["view",...]}
    if (!empty($perms['*']) && in_array($action, (array)$perms['*'], true)) return true;
    return !empty($perms[$screen]) && in_array($action, (array)$perms[$screen], true);
}

/**
 * Bloqueia a request (403) se o usuário não tiver a permissão. Usar no topo
 * dos handlers de tela (view) e antes de processar POSTs (create/edit/delete).
 */
function require_permission($screen, $action = 'view') {
    require_login();
    if (!can($screen, $action)) {
        http_response_code(403);
        echo '<h1>403 — Acesso negado</h1><p>Seu grupo de permissão não autoriza esta ação. Contate o administrador.</p>';
        exit;
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
        if (!$row) return null;
        try {
            $extStmt = $db->prepare("SELECT brand_color, logo_url, occurrence_config_id, faceid_enabled FROM customers WHERE id = ?");
            $extStmt->execute(array($cid));
            $ext = $extStmt->fetch(PDO::FETCH_ASSOC);
            if ($ext) $row = array_merge($row, $ext);
        } catch (Exception $e) {
            // v4 columns may not exist yet — silent fallback
        }
        return $row;
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

/**
 * Clientes que o usuário pode assumir como contexto de sessão.
 *
 * NÃO é só a lista do seletor do cabeçalho: `customer_switch.php` usa o
 * retorno desta função como **autorização** para trocar de cliente, e
 * `login_user()` usa o primeiro item como contexto inicial. O que sair daqui
 * é, na prática, o que o usuário pode abrir.
 *
 * ⚠️ POR QUE O FALLBACK ANTIGO ERA UMA FALHA (v4.8.9). Quando o usuário não
 * tinha vínculo em `customer_users`, a função devolvia o **primeiro cliente
 * ativo da base** (`ORDER BY name LIMIT 1`) com role 'viewer'. Para um
 * revendedor ou operador sem vínculo isso entregava o cliente de **outro
 * tenant** — e não só o nome: o `customer_switch` aceitava a troca, porque a
 * checagem de autorização dele é a presença do id nesta lista.
 *
 * A regra agora espelha `reseller_scope_ids()` (`includes/functions.php`),
 * que é o ponto único de escopo multi-tenant do sistema — deliberadamente a
 * MESMA semântica, para não existirem duas respostas para "que clientes são
 * dele":
 *   - vínculo explícito em `customer_users` manda, para qualquer perfil;
 *   - sem vínculo, só o **admin de plataforma** (`role = 'admin'`) enxerga
 *     cliente — e, se enxerga, enxerga todos, como no seletor dos relatórios;
 *   - **revendedor** sem vínculo cai nos clientes que ele mesmo criou
 *     (`customers.reseller_id`), o outro termo da união de `reseller_scope_ids()`.
 *     Sem este termo, revendedor de base existente (onde `reseller_id` é NULL)
 *     ficaria sem cliente algum — trocar vazamento por apagão;
 *   - qualquer outro perfil sem vínculo recebe lista **vazia**: falha fechada.
 *
 * @param int $user_id Usuário cujo escopo se quer (não necessariamente o da sessão)
 * @returns array<array{id:int,name:string,role:string}>
 */
function get_available_customers($user_id) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT c.id, c.name, cu.role FROM customers c JOIN customer_users cu ON c.id = cu.customer_id WHERE cu.user_id = ? AND c.is_active = 1 ORDER BY c.name");
        $stmt->execute(array($user_id));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) return $rows;

        // Sem vínculo explícito: o perfil decide, não a ordem alfabética da base.
        $stmt = $db->prepare("SELECT role, user_type FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute(array($user_id));
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) return array();

        // role='admin' é admin de plataforma — mesmo teste, e na mesma ordem,
        // que reseller_scope_ids() faz antes de olhar user_type.
        if (($u['role'] ?? '') === 'admin') {
            $stmt = $db->query("SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name");
            $out = array();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[] = array('id' => $row['id'], 'name' => $row['name'], 'role' => 'admin');
            }
            return $out;
        }

        if (($u['user_type'] ?? '') === 'revendedor') {
            $stmt = $db->prepare("SELECT id, name FROM customers WHERE reseller_id = ? AND is_active = 1 ORDER BY name");
            $stmt->execute(array($user_id));
            $out = array();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[] = array('id' => $row['id'], 'name' => $row['name'], 'role' => 'viewer');
            }
            // Revendedor sem vínculo E sem cliente próprio também fica sem nada:
            // cai no log abaixo em vez de devolver vazio calado.
            if ($out) return $out;
        }

        // Fecha fechado, mas NÃO em silêncio: sem cliente o painel abre vazio e
        // sem explicação, e diagnosticar isso pelo sintoma custa caro (é a lição
        // da v4.8.6). Uma linha no log nomeia a causa: falta o vínculo.
        Logger::warning('Usuário sem cliente disponível — falta vínculo em customer_users', [
            'user_id'   => (int)$user_id,
            'role'      => $u['role'] ?? null,
            'user_type' => $u['user_type'] ?? null,
        ]);
        return array();
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

/**
 * Troca o token da sessão corrente por um novo, preservando o login (v4.13.21).
 *
 * Usado depois da troca de senha: a credencial mudou, então o identificador de
 * sessão emitido sob a credencial antiga não continua valendo. Preserva a linha
 * de `sessions` (com o `customer_id` do contexto ativo) — quem trocou a senha
 * não é deslogado, só passa a carregar outro token.
 *
 * ⚠️ Limpa também o token CSRF em cache: `csrf_generate()` o deriva por HMAC do
 * cookie de sessão, então sem esta linha os formulários renderizados no resto
 * do request sairiam assinados com o token ANTIGO e o POST seguinte tomaria 403.
 *
 * @returns string|null Novo token, ou null se não havia sessão em cookie
 */
function rotate_session_token() {
    if (!isset($_COOKIE[AUTH_COOKIE])) return null;
    $old = $_COOKIE[AUTH_COOKIE];
    $new = _gen_token();
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE sessions SET id = ? WHERE id = ?");
        $stmt->execute(array($new, $old));
    } catch (Exception $e) {
        error_log('rotate_session_token: ' . $e->getMessage());
        return null;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(AUTH_COOKIE, $new, [
        'expires'  => time() + AUTH_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[AUTH_COOKIE] = $new;
    if (defined('CSRF_TOKEN_KEY')) unset($_SESSION[CSRF_TOKEN_KEY]);
    return $new;
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

        // `temp_expired` é calculado no SQL, não em PHP: o prazo está gravado em
        // UTC e a comparação com NOW() acontece na MESMA conexão que já força
        // time_zone '+00:00' — não há fuso a supor. O try/catch cobre a janela
        // do deploy em que o código novo já está no ar e a migração v4.13.21
        // ainda não rodou (mesmo padrão defensivo de get_jimi_user()).
        try {
            $stmt = $db->prepare(
                "SELECT id, email, name, role, password_hash, is_active,
                        must_change_password,
                        (temp_password_expires_at IS NOT NULL AND temp_password_expires_at < NOW()) AS temp_expired
                   FROM users WHERE email = ?"
            );
            $stmt->execute(array($email));
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $stmt = $db->prepare("SELECT id, email, name, role, password_hash, is_active FROM users WHERE email = ?");
            $stmt->execute(array($email));
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$user || empty($user['is_active'])) {
            _log_login($db, $email, $ip, $ua, false);
            return array('success' => false, 'error' => 'Usuário não encontrado ou inativo.');
        }
        if (!password_verify($password, $user['password_hash'])) {
            _log_login($db, $email, $ip, $ua, false);
            return array('success' => false, 'error' => 'Senha incorreta.');
        }
        // Senha temporária vencida (v4.13.21). A mensagem NÃO pode ser "senha
        // incorreta": a pessoa digitou a senha certa, e mandá-la tentar de novo
        // só a faz queimar o rate limit de 5 tentativas por IP.
        if (!empty($user['must_change_password']) && !empty($user['temp_expired'])) {
            _log_login($db, $email, $ip, $ua, false);
            return array('success' => false, 'error' => 'Senha temporária expirada. Use "Esqueci minha senha" para receber outra.');
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
