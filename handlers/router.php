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
 *   /relatorios/parametros            → rel_parametros.php (config da frota)
 *   /agendamentos                     → agendamentos.php
 *   /geocercas                        → geocercas.php
 *   /perfil                           → perfil.php
 *   /camerasdata ...                  → AJAX endpoints
 *   /ocorrenciasdata                  → ocorrenciasdata.php (AJAX)
 *   /solicitarvideo                   → solicitarvideo.php (AJAX, POST)
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
                   'ocorrenciasdata','exportardata','notificacoesdata','solicitarvideo','dashboarddata',
                   // v4.14.0 — despacho de comando pelo canal SMS. Fica aqui, e
                   // não em $screenByHandler, pelo mesmo motivo do
                   // 'sendcommand': é ação AJAX, não tela. A permissão é
                   // conferida dentro do handler (require_permission).
                   'sendsms'];
    $webhookRoutes = ['pushgps','pushhb','pushalarm','pushfileupload','pushlbs','pushresourcelist',
                      'pushftpfileupload','pushiothubevent','pushTerminalTransInfo','pushinstructresponse',
                      'pushevent',
                      // v4.14.0 — retorno do provedor de SMS (Allcance): status
                      // de entrega e a RESPOSTA do equipamento ao comando.
                      // ⚠️ Não usa WEBHOOK_TOKEN: quem posta é um terceiro que
                      // não conhece o nosso protocolo. A defesa é o segredo `k`
                      // na query, conferido dentro do handler — mesmo desenho
                      // do /filelist.
                      'pushsms'];
    // NOTA: 'checklist' fica fora daqui de propósito — resolve via $subrouteMap
    // (fallback sem subrota → checklist.php; /checklist/inspecao → checklist_inspection.php)
    // 'download' (v4.7.3) NÃO exige login de propósito: a autorização é a
    // assinatura HMAC com prazo na própria URL, porque é este o link que vai
    // no e-mail do relatório grande. Ver includes/download_token.php.
    // 'midia' (v4.9.1) serve o vídeo extraído do cartão pela NOSSA origem —
    // exige login e checa o escopo do cliente dentro do handler. Não é tela:
    // fica fora de $screenByHandler, como 'download'.
    // 'parametros' (v4.9.16) é a área dedicada de configuração das câmeras
    // JT/T. Não colide com o diretório do docroot como `/config` colidiu
    // (v4.9.11): não existe `parametros/` na raiz.
    $simpleRoutes = ['login','logout','setup','dashboard','resumo','rastreamento','bi','comandos',
                     'exportar','ping','customer_switch','usuarios','perfil',
                     'chips','equipamentos','motoristas','geocercas','agendamentos','wiki','download',
                     'midia','parametros','firmwares','manutencoes','painel',
                     // v4.15.0 — consulta de audit_log. Resolve o arquivo
                     // (auditoria.php); a permissão em si entra em
                     // $screenByHandler logo abaixo E em $screens
                     // (grupos_permissao.php) — ver a nota lá.
                     'auditoria'];
    $renamedRoutes = [
        'config-ocorrencias'  => 'config_ocorrencias.php',
        'config-notificacoes' => 'config_notificacoes.php',
        'config-smtp'         => 'config_smtp.php',
        // v4.14.0 — canal de SMS (Allcance): credenciais da conta e o segredo
        // do webhook de retorno. `require_admin()` no handler, porque `can()`
        // é permissivo por omissão e esta tela guarda credencial de terceiro.
        'config-sms'          => 'config_sms.php',
        // v4.14.0 — os MESMOS comandos do proNo 128 do /comandos, despachados
        // pela rede da operadora em vez do IoT Hub (canal de resgate).
        'comandos-sms'        => 'comandos_sms.php',
        // 🔴 v4.9.11 — era a rota `/config`, e ela estava MORTA. Existe um
        // diretório `config/` no docroot (o do PDO singleton), então o mod_dir
        // do Apache redirecionava `/config` → `/config/` com 301 e servia o
        // DIRETÓRIO, que morre em 403 por `Options -Indexes`. O PHP nunca
        // rodava. Provado no log: "AH01276: Cannot serve directory
        // /var/www/jimi_webhook/config/". A linha `RewriteRule ^config$` do
        // .htaccess era a tentativa de contornar isso e não funciona — o
        // mod_dir se antecipa ao mod_rewrite. Renomear a rota resolve sem
        // brigar com o Apache, e alinha com as irmãs config-*.
        'config-dispositivos' => 'config_dispositivos.php',
        'config-parametros'   => 'config_parametros.php',
        // v4.13.0 — ADAS/DMS/velocidade saíram de /comandos e ganharam área
        // própria (catálogo próprio, reprocessado das planilhas do
        // fabricante). Ver includes/ia_config_catalog.php.
        'configuracoes-ia'    => 'configuracoes_ia.php',
        'grupos-permissao'    => 'grupos_permissao.php',
        // v4.13.21 — fluxo da senha temporária por e-mail. As duas ficam FORA
        // de `$screenByHandler` e da matriz de `grupos_permissao.php`, como
        // `login`/`logout`/`perfil`: não são telas de produto que um grupo
        // possa conceder ou negar. `/esqueci-senha` é pública por definição
        // (quem a usa não consegue entrar); `/trocar-senha` é a única saída de
        // quem está preso pela troca obrigatória — negá-la a um grupo
        // trancaria o usuário fora do sistema sem alternativa.
        'esqueci-senha'       => 'esqueci_senha.php',
        'trocar-senha'        => 'trocar_senha.php',
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
            'deslocamento/replay' => 'rel_deslocamento_replay.php',
            'desatualizados' => 'rel_desatualizados.php',
            'alarmes'      => 'rel_alarmes.php',
            'ocorrencias'  => 'rel_ocorrencias.php',
            'geocercas'    => 'rel_geocercas.php',
            'paradas'      => 'rel_paradas.php',
            'ociosidade'   => 'rel_ociosidade.php',
            'ignicao'      => 'rel_ignicao.php',
            'velocidade'   => 'rel_velocidade.php',
            'status-frota' => 'rel_status_frota.php',
            'parametros'   => 'rel_parametros.php',
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

    // /filelist/{imei} (v4.9.18) — a CÂMERA JIMI sobe aqui a lista de gravações
    // do cartão, em resposta ao comando de texto FILELIST. Não é tela e não
    // exige login, pelo mesmo motivo de `download` e `midia`: quem chama é o
    // equipamento, que não carrega sessão. Fica FORA de $screenByHandler de
    // propósito — a defesa é o IMEI conhecido, dentro do handler.
    } elseif ($first === 'filelist') {
        $handler = 'filelist.php';
        $params['imei'] = $second ?? '';

    } elseif ($first === 'ativos') {
        if ($second === 'novo') {
            $handler = 'ativos_novo.php';
        } elseif ($second) {
            // v4.11.0 — a URL passou a ser o ID do VEÍCULO (vehicles.id), não
            // mais o IMEI da câmera: um veículo pode existir sem câmera, ou
            // trocar de câmera ao longo do tempo, e o IMEI deixou de
            // identificar univocamente "qual ativo é esse" no cadastro.
            $handler = 'ativo_detalhe.php';
            $params['vehicle_id'] = $second;
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
    // v4.14.0 — tela nova entra nos DOIS lugares: aqui e em $screens de
    // grupos_permissao.php. Só aqui = impossível de conceder; só lá = o `view`
    // não é verificado por ninguém. Já aconteceu cinco vezes.
    'comandos_sms.php'          => 'comandos-sms',
    'config_sms.php'            => 'config-sms',
    // v4.9.16 — área dedicada de parâmetros. Entra aqui E em `$screens`
    // (grupos_permissao.php): só no router, o admin não tem o que marcar; só
    // na matriz, o `view` não é verificado por ninguém. O acesso efetivo é
    // `require_admin()` dentro do handler — `can()` é permissivo por omissão.
    'parametros.php'            => 'parametros',
    'exportar.php'              => 'exportar',
    'video_aovivo.php'          => 'video_aovivo',
    'video_playback.php'        => 'video_playback',
    'video_downloads.php'       => 'video_downloads',
    'rel_posicoes.php'          => 'relatorios',
    'rel_deslocamento.php'      => 'relatorios',
    'rel_deslocamento_rota.php' => 'relatorios',
    // v4.10.2 — item 6 do PLANO_IMPLEMENTACAO_v4.10.md. Sem tela nova na
    // matriz de propósito: é a mesma permissão de 'relatorios', igual a
    // rota estática que já existia.
    'rel_deslocamento_replay.php' => 'relatorios',
    'rel_desatualizados.php'    => 'relatorios',
    'rel_alarmes.php'           => 'relatorios',
    // Ação do relatório de alarmes (reenvio de vídeo), não tela nova: herda a
    // mesma chave, e por isso NÃO entra na matriz de grupos_permissao.php.
    'solicitarvideo.php'        => 'relatorios',
    'rel_ocorrencias.php'       => 'relatorios',
    'rel_geocercas.php'         => 'relatorios',
    'rel_paradas.php'           => 'relatorios',
    'rel_ociosidade.php'        => 'relatorios',
    'rel_ignicao.php'           => 'relatorios',
    'rel_velocidade.php'        => 'relatorios',
    'rel_status_frota.php'      => 'relatorios',
    'rel_parametros.php'        => 'relatorios',
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
    // v4.10.1 — item 3 do PLANO_IMPLEMENTACAO_v4.10.md. Entra aqui E em
    // `$screens` (grupos_permissao.php) — lição repetida quatro vezes no
    // CLAUDE.md (checklist/wiki v4.8.5, config-notificacoes/config-smtp
    // v4.8.9): só no router = tela impossível de conceder; só na matriz = o
    // view não é verificado por ninguém.
    'manutencoes.php'           => 'manutencoes',
    // v4.10.3 — item 7 do PLANO_IMPLEMENTACAO_v4.10.md. `dashboarddata.php` é
    // AJAX (entra em $ajaxRoutes, não aqui).
    'painel.php'                 => 'painel',
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
    // v4.9.11: QUINTA ocorrência do mesmo par de erros — a tela que consulta e
    // RECONFIGURA a câmera (proNo 33027/33028/33029/33030) estava em
    // $simpleRoutes e fora dos dois mapas, só com `require_login()`.
    //
    // ⚠️ A exposição, porém, NUNCA chegou a existir: a rota `/config` morria no
    // Apache antes do PHP (colisão com o diretório `config/` — ver o comentário
    // em $renamedRoutes). Ou seja, eram DOIS defeitos empilhados, e o segundo
    // escondia o primeiro. Com a rota consertada, a trava passa a ser o que
    // impede a exposição. Ver PROJETO_PARAMETROS.md §3.4.
    'config_dispositivos.php'   => 'config-dispositivos',
    // v4.9.32 — `/firmwares` entra aqui E em `$screens`
    // (grupos_permissao.php). A trava efetiva é o `require_admin()` dentro do
    // handler: `can()` é permissivo por omissão, e esta tela manda equipamento
    // em operação trocar o próprio firmware.
    'firmwares.php'             => 'firmwares',
    'config_parametros.php'     => 'config-parametros',
    // v4.13.0 — mesma trava efetiva de 'parametros'/'firmwares':
    // require_admin() dentro do handler, porque manda comando de texto pra
    // equipamento em operação.
    'configuracoes_ia.php'      => 'configuracoes-ia',
    'usuarios.php'              => 'usuarios',
    'wiki.php'                  => 'wiki',
    // v4.15.0 — entra aqui E em `$screens` (grupos_permissao.php): só aqui =
    // impossível de conceder; só lá = o `view` não é verificado por ninguém
    // (mesmo par de erros já cometido seis vezes, listado no CLAUDE.md).
    'auditoria.php'             => 'auditoria',
];
if (isset($screenByHandler[$handler])) {
    require_once __DIR__ . '/../includes/auth.php';
    require_permission($screenByHandler[$handler], 'view');
}

foreach ($params as $key => $value) {
    $_GET[$key] = $value;
}

require $handlerPath;
