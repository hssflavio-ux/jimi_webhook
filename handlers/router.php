<?php
/**
 * JIMI Webhook System — URL Router v3.1.0
 *
 * Front controller que interpreta URLs multi-segmento e despacha
 * para o handler PHP correto. Substitui o rewrite single-segment anterior.
 *
 * Rotas suportadas:
 *   /                          → dashboard.php
 *   /login                     → login.php
 *   /logout                    → logout.php
 *   /setup                     → setup.php
 *   /dashboard                 → dashboard.php
 *   /ativos                    → ativos.php
 *   /ativos/novo               → ativos_novo.php
 *   /ativos/{imei}             → ativo_detalhe.php
 *   /live                      → live.php
 *   /relatorios                → relatorios.php
 *   /video                     → video.php
 *   /comandos                  → comandos.php
 *   /config                    → config.php
 *   /clientes                  → clientes.php
 *   /clientes/novo             → clientes_novo.php
 *   /clientes/{id}             → cliente_dashboard.php
 *   /camerasdata               → camerasdata.php (AJAX)
 *   /commandstatus             → commandstatus.php (AJAX)
 *   /sendcommand               → sendcommand.php (AJAX)
 *   /mediadata                 → mediadata.php (AJAX)
 *   /trackdata                 → trackdata.php (AJAX)
 *   /hbdata                    → hbdata.php (AJAX)
 *   /devicemodels              → devicemodels.php (AJAX)
 *   /pushgps, /pushhb, ...     → webhook receivers
 *   /ping                      → ping.php
 */

$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = strtok($requestUri, '?');
$requestUri = rtrim($requestUri, '/');
if ($requestUri === '') $requestUri = '/';

$segments = array_values(array_filter(explode('/', $requestUri)));

$handlerDir = __DIR__;
$params = [];

if (empty($segments)) {
    $handler = 'dashboard.php';
} else {
    $first = $segments[0];

    $ajaxRoutes = ['camerasdata','commandstatus','sendcommand','mediadata','trackdata','hbdata','devicemodels'];
    $webhookRoutes = ['pushgps','pushhb','pushalarm','pushfileupload','pushlbs','pushresourcelist',
                      'pushftpfileupload','pushiothubevent','pushTerminalTransInfo','pushinstructresponse',
                      'pushcmd','pushevent'];
    $simpleRoutes = ['login','logout','setup','dashboard','live','relatorios','video','comandos','config','ping','customer_switch'];

    if (in_array($first, $simpleRoutes)) {
        $handler = $first . '.php';

    } elseif (in_array($first, $ajaxRoutes) || in_array($first, $webhookRoutes)) {
        $handler = $first . '.php';

    } elseif ($first === 'ativos') {
        if (isset($segments[1]) && $segments[1] === 'novo') {
            $handler = 'ativos_novo.php';
        } elseif (isset($segments[1])) {
            $handler = 'ativo_detalhe.php';
            $params['imei'] = $segments[1];
        } else {
            $handler = 'ativos.php';
        }

    } elseif ($first === 'clientes') {
        if (isset($segments[1]) && $segments[1] === 'novo') {
            $handler = 'clientes_novo.php';
        } elseif (isset($segments[1])) {
            $handler = 'cliente_dashboard.php';
            $params['customer_id'] = $segments[1];
        } else {
            $handler = 'clientes.php';
        }

    } else {
        http_response_code(404);
        echo '<h1>404 — Página não encontrada</h1>';
        exit;
    }
}

$handlerPath = $handlerDir . '/' . $handler;

if (!file_exists($handlerPath)) {
    http_response_code(404);
    echo '<h1>404 — Handler não encontrado</h1>';
    exit;
}

foreach ($params as $key => $value) {
    $_GET[$key] = $value;
}

require $handlerPath;
