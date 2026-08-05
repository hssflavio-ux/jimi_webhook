<?php
/**
 * JIMI Webhook System — URL Router v4.0.0 (YUV Parity)
 *
 * Front controller que interpreta URLs multi-segmento e despacha
 * para o handler PHP correto. Suporte a subrotas de 2 segmentos
 * por prefixo (video/*, relatorios/*, ocorrencias/*, etc.).
 *
 * Rotas suportadas:
 *   /                                 → resumo.php
 *   /login                            → login.php
 *   /logout                           → logout.php
 *   /setup                            → setup.php
 *   /dashboard                        → dashboard.php (legacy, redireciona para /)
 *   /resumo ou /                      → resumo.php
 *   /rastreamento                     → rastreamento.php
 *   /bi                               → bi.php
 *   /ocorrencias/dashboard            → ocorrencias_dashboard.php
 *   /comandos                         → comandos.php
 *   /exportar                         → exportar.php
 *   /ativos [/novo | /{imei}]        → ativos.php / ativos_novo.php / ativo_detalhe.php
 *   /chips                            → chips.php
 *   /clientes                         → clientes.php
 *   /equipamentos                     → equipamentos.php
 *   /grupos-permissao                 → grupos_permissao.php
 *   /motoristas                       → motoristas.php
 *   /config-ocorrencias               → config_ocorrencias.php
 *   /usuarios                         → usuarios.php
 *   /video/aovivo                     → video_aovivo.php
 *   /video/playback                   → video_playback.php
 *   /video/downloads                  → video_downloads.php
 *   /relatorios/posicoes              → rel_posicoes.php
 *   /relatorios/deslocamento           → rel_deslocamento.php
 *   /relatorios/deslocamento/rota      → rel_deslocamento_rota.php (mapa do percurso)
 *   /relatorios/desatualizados        → rel_desatualizados.php
 *   /relatorios/alarmes               → rel_alarmes.php
 *   /relatorios/ocorrencias           → rel_ocorrencias.php
 *   /relatorios/geocercas             → rel_geocercas.php
 *   /relatorios/paradas               → rel_paradas.php
 *   /relatorios/ociosidade            → rel_ociosidade.php
 *   /relatorios/ignicao               → rel_ignicao.php
 *   /relatorios/velocidade            → rel_velocidade.php
 *   /relatorios/status-frota          → rel_status_frota.php
 *   /agendamentos                     → agendamentos.php
 *   /geocercas                        → geocercas.php
 *   /perfil                           → perfil.php
 *   /camerasdata ...                  → AJAX endpoints
 *   /ocorrenciasdata                  → ocorrenciasdata.php (AJAX)
 *   /exportardata                     → exportardata.php (AJAX)
 *   /pushgps, /pushhb, ...            → webhook receivers
 *   /ping                             → ping.php
 */

$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = strtok($requestUri, '?');
$requestUri = rtrim($requestUri, '/');
if ($requestUri === '') $requestUri = '/';

$segments = array_values(array_filter(explode('/', $requestUri)));

$handlerDir = __DIR__;
$params = [];

if (empty($segments)) {
    $handler = 'resumo.php';
} else {
    $first = $segments[0];
    $second = $segments[1] ?? null;
    $third = $segments[2] ?? null;

    $ajaxRoutes = ['camerasdata','commandstatus','sendcommand','mediadata','trackdata','hbdata','devicemodels',
                   'ocorrenciasdata','exportardata','notificacoesdata'];
    $webhookRoutes = ['pushgps','pushhb','pushalarm','pushfileupload','pushlbs','pushresourcelist',
                      'pushftpfileupload','pushiothubevent','pushTerminalTransInfo','pushinstructresponse',
                      'pushevent'];
    // NOTA: 'checklist' fica fora daqui de propósito — resolve via $subrouteMap
    // (fallback sem subrota → checklist.php; /checklist/inspecao → checklist_inspection.php)
    // 'download' (v4.7.3) NÃO exige login de propósito: a autorização é a
    // assinatura HMAC com prazo na própria URL, porque é este o link que vai
    // no e-mail do relatório grande. Ver includes/download_token.php.
    $simpleRoutes = ['login','logout','setup','dashboard','resumo','rastreamento','bi','comandos',
                     'exportar','config','ping','customer_switch','usuarios','perfil',
                     'chips','equipamentos','motoristas','geocercas','agendamentos','wiki','download'];
    $renamedRoutes = [
        'config-ocorrencias'  => 'config_ocorrencias.php',
        'config-notificacoes' => 'config_notificacoes.php',
        'config-smtp'         => 'config_smtp.php',
        'grupos-permissao'    => 'grupos_permissao.php',
        // IoTHub pode postar o callback offline em camelCase (doc §2.4)
        'pushInstructResponse' => 'pushinstructresponse.php',
    ];

    // Subrotas de 2 segmentos por prefixo
    $subrouteMap = [
        'video' => [
            'aovivo'     => 'video_aovivo.php',
            'playback'   => 'video_playback.php',
            'downloads'  => 'video_downloads.php',
        ],
        'relatorios' => [
            'posicoes'     => 'rel_posicoes.php',
            'deslocamento' => 'rel_deslocamento.php',
            'deslocamento/rota' => 'rel_deslocamento_rota.php',
            'desatualizados' => 'rel_desatualizados.php',
            'alarmes'      => 'rel_alarmes.php',
            'ocorrencias'  => 'rel_ocorrencias.php',
            'geocercas'    => 'rel_geocercas.php',
            'paradas'      => 'rel_paradas.php',
            'ociosidade'   => 'rel_ociosidade.php',
            'ignicao'      => 'rel_ignicao.php',
            'velocidade'   => 'rel_velocidade.php',
            'status-frota' => 'rel_status_frota.php',
        ],
        'ocorrencias' => [
            'dashboard' => 'ocorrencias_dashboard.php',
        ],
        'checklist' => [
            'inspecao' => 'checklist_inspection.php',
        ],
    ];

    if (in_array($first, $simpleRoutes)) {
        $handler = $first . '.php';

    } elseif (isset($renamedRoutes[$first])) {
        $handler = $renamedRoutes[$first];

    } elseif (in_array($first, $ajaxRoutes) || in_array($first, $webhookRoutes)) {
        $handler = $first . '.php';

    } elseif (isset($subrouteMap[$first])) {
        $sub = $subrouteMap[$first];
        // Subrota de 3 segmentos (chave 'segundo/terceiro') tem precedência
        if ($second && $third && isset($sub[$second . '/' . $third])) {
            $handler = $sub[$second . '/' . $third];
        } elseif ($second && isset($sub[$second])) {
            $handler = $sub[$second];
        } elseif ($second) {
            http_response_code(404);
            echo '<h1>404 — Subrota não encontrada</h1>';
            exit;
        } else {
            // Sem subrota — fallback: usa handler principal se existir
            $fallback = $first . '.php';
            if (file_exists($handlerDir . '/' . $fallback)) {
                $handler = $fallback;
            } else {
                http_response_code(404);
                echo '<h1>404 — Página não encontrada</h1>';
                exit;
            }
        }

    } elseif ($first === 'ativos') {
        if ($second === 'novo') {
            $handler = 'ativos_novo.php';
        } elseif ($second) {
            $handler = 'ativo_detalhe.php';
            $params['imei'] = $second;
        } else {
            $handler = 'ativos.php';
        }

    } elseif ($first === 'clientes') {
        if ($second) {
            // Rota morta removida na v4.2.0: cliente_detalhe.php nunca existiu (R08 residual)
            http_response_code(404);
            echo '<h1>404 — Página não encontrada</h1>';
            exit;
        }
        $handler = 'clientes.php';

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

// ── RBAC central (v4.2.0 — Fase B2 do PLANO_ADERENCIA_YUV) ──
// Telas do dashboard exigem permissão 'view' do grupo do usuário (matriz de
// grupos_permissao.php). Usuário sem grupo → sem restrição (role legado).
// Webhooks, AJAX, login/logout/setup/ping ficam de fora deste mapa.
// Ações finas (create/edit/delete/export) são verificadas nos handlers.
$screenByHandler = [
    'resumo.php'                => 'resumo',
    'rastreamento.php'          => 'rastreamento',
    'bi.php'                    => 'bi',
    'ocorrencias_dashboard.php' => 'ocorrencias_dashboard',
    'comandos.php'              => 'comandos',
    'exportar.php'              => 'exportar',
    'video_aovivo.php'          => 'video_aovivo',
    'video_playback.php'        => 'video_playback',
    'video_downloads.php'       => 'video_downloads',
    'rel_posicoes.php'          => 'relatorios',
    'rel_deslocamento.php'      => 'relatorios',
    'rel_deslocamento_rota.php' => 'relatorios',
    'rel_desatualizados.php'    => 'relatorios',
    'rel_alarmes.php'           => 'relatorios',
    'rel_ocorrencias.php'       => 'relatorios',
    'rel_geocercas.php'         => 'relatorios',
    'rel_paradas.php'           => 'relatorios',
    'rel_ociosidade.php'        => 'relatorios',
    'rel_ignicao.php'           => 'relatorios',
    'rel_velocidade.php'        => 'relatorios',
    'rel_status_frota.php'      => 'relatorios',
    'geocercas.php'             => 'geocercas',
    'agendamentos.php'          => 'agendamentos',
    'checklist.php'             => 'checklist',
    'checklist_inspection.php'  => 'checklist',
    'ativos.php'                => 'ativos',
    'ativos_novo.php'           => 'ativos',
    'ativo_detalhe.php'         => 'ativos',
    'chips.php'                 => 'chips',
    'clientes.php'              => 'clientes',
    'equipamentos.php'          => 'equipamentos',
    'grupos_permissao.php'      => 'grupos-permissao',
    'motoristas.php'            => 'motoristas',
    'config_ocorrencias.php'    => 'config-ocorrencias',
    // v4.8.9: as duas telas de config de notificação estavam na matriz de
    // grupos_permissao.php e FORA daqui. `config_smtp.php` se protegia sozinho
    // (require_permission no topo do handler), mas `config_notificacoes.php` só
    // chamava require_login(): create/edit/delete davam 403 e o **view não era
    // verificado em lugar nenhum** — negar a tela na matriz não impedia abri-la
    // e ler todas as regras do cliente. É o mesmo par de erros da v4.8.5
    // (`checklist` no router e fora da matriz; `wiki` o inverso). A regra vale
    // nos dois sentidos: toda tela entra nos DOIS lugares.
    'config_notificacoes.php'   => 'config-notificacoes',
    'config_smtp.php'           => 'config-smtp',
    'usuarios.php'              => 'usuarios',
    'wiki.php'                  => 'wiki',
];
if (isset($screenByHandler[$handler])) {
    require_once __DIR__ . '/../includes/auth.php';
    require_permission($screenByHandler[$handler], 'view');
}

foreach ($params as $key => $value) {
    $_GET[$key] = $value;
}

require $handlerPath;
