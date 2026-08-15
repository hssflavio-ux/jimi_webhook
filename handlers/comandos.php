<?php
/**
 * JIMI Webhook System — Comandos v4.9.7
 * Rota: /comandos
 *
 * Envio de comandos com a lista SENSÍVEL AO MODELO do equipamento.
 *
 * O catálogo (`includes/command_catalog.php`) é gerado da wiki Foco na Via e
 * diz, por comando, quais modelos o documentam. Daí saem as duas regras da
 * tela:
 *
 *   1. **Trava de modelo.** Marcar um JC371 desabilita os equipamentos de outro
 *      modelo, porque um comando específico (`DMSSP`, `EVENTALERT`…) só existe
 *      naquele modelo e mandá-lo para outro devolve "comando não suportado" —
 *      um erro que só aparece minutos depois, no callback.
 *   2. **Exceção do proNo 128 universal.** Comando presente em 5+ das 6 páginas
 *      da wiki (`STATUS#`, `VERSION#`, `REBOOT#`, `SERVER`…) é o núcleo comum do
 *      protocolo de texto: com um desses escolhido a trava solta e dá para
 *      mandar para a frota inteira de uma vez.
 *
 * O envio em lote NÃO virou um endpoint novo: o frontend chama `/sendcommand`
 * uma vez por equipamento. Assim a checagem de posse por IMEI, o log e o
 * registro em `commands` continuam exatamente como estavam — um endpoint de
 * lote teria de reimplementar tudo isso e é caminho crítico de despacho.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/command_response.php';
// A permissão de tela é aplicada pelo router (`$screenByHandler`); repetir aqui
// só duplicaria a checagem.
require_login();

$db          = Database::getInstance()->getConnection();
$customer_id = get_customer_id();

// ── Escopo multi-tenant ────────────────────────────────────────────────────
//
// Até a v4.9.21 esta tela era presa ao cliente da sessão: o administrador só
// enxergava comandos do cliente em que estivesse posicionado, sem como olhar
// a base inteira nem recortar por um cliente específico.
//
// O `?customer_id` passa OBRIGATORIAMENTE por `report_customer_scope()` (ver
// CLAUDE.md): para quem não é admin o parâmetro é **ignorado**, não validado —
// obedecê-lo seria deixar qualquer usuário ler comandos de outro cliente
// trocando um número na URL. Para revendedor, o helper restringe à carteira
// dele. NULL só acontece para admin de plataforma e significa "todos".
$user       = get_jimi_user();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$filtroCust = $_GET['customer_id'] ?? null;
$scopeCust  = report_customer_scope($filtroCust, $isAdmin, $customer_id);
$customers  = $isAdmin ? report_customer_options($db) : [];

// Cláusula reaproveitada pelas três consultas da tela. Com escopo resolvido,
// filtra; sem escopo (admin vendo tudo), não filtra — mas a lista de clientes
// continua vindo de `report_customer_options()`, que já respeita a carteira.
$scopeSql    = $scopeCust !== null ? ' AND d.customer_id = :cid' : '';
$scopeParams = $scopeCust !== null ? [':cid' => $scopeCust] : [];

/** Data/hora curta em BRT para as grades desta tela. */
function fmt_brt_cmd($dt) { return fmt_brt($dt, 'd/m H:i:s'); }

$vsc    = video_stream_config();
$fsUrl  = getenv('FILE_STORAGE_URL') ?: 'http://localhost:23010/download/';
$fsHost = parse_url($fsUrl, PHP_URL_HOST) ?: 'localhost';
$fsPort = parse_url($fsUrl, PHP_URL_PORT) ?: 23010;

// ── Equipamentos do cliente ────────────────────────────────────────────────
$devices = $db->prepare("
    SELECT d.imei,
           COALESCE(NULLIF(d.device_name,''), d.imei) AS device_name,
           COALESCE(dm.model_name, d.device_model, '-') AS model_display,
           COALESCE(dm.protocol, 'JIMI') AS protocol,
           COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) AS camera_count,
           d.last_communication,
           TIMESTAMPDIFF(MINUTE, d.last_communication, UTC_TIMESTAMP()) AS mudo_min,
           COALESCE(cu.name, '—') AS customer_name
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN customers cu ON cu.id = d.customer_id
    WHERE d.is_active = 1 {$scopeSql}
    ORDER BY cu.name, d.device_name
");
$devices->execute($scopeParams);
$devices = $devices->fetchAll(PDO::FETCH_ASSOC);

// Com "todos os clientes" a lista de envio mistura carteiras, e disparar
// comando no veículo do cliente errado é dano real. O nome do cliente entra
// na linha só nesse caso — repeti-lo com escopo fixo seria ruído.
$mostrarCliente = ($scopeCust === null);

/**
 * Presença do equipamento a partir do último contato.
 *
 * A fonte é `devices.last_communication`, que o gateway atualiza a cada
 * heartbeat — conferido em produção contra `MAX(heartbeats.created_at)`: bate
 * equipamento a equipamento.
 *
 * ⚠️ Isto informa, **não bloqueia**. Comando para equipamento offline é um
 * fluxo legítimo e suportado: o IoT Hub converte em comando de fila
 * ("converted to an offline command") e entrega quando o device reconecta.
 * Desabilitar o envio quebraria esse uso.
 *
 * @param int|null $min Minutos desde o último contato
 * @returns array{nivel:string, rotulo:string}
 */
function presenca_device(?int $min): array
{
    if ($min === null)  return ['nivel' => 'neutro',     'rotulo' => 'sem contato registrado'];
    if ($min < 0)       return ['nivel' => 'ok',         'rotulo' => 'agora'];
    if ($min <= 15)     return ['nivel' => 'ok',         'rotulo' => 'online'];
    if ($min <= 60)     return ['nivel' => 'aguardando', 'rotulo' => 'há ' . $min . ' min'];
    if ($min <= 1440)   return ['nivel' => 'aguardando', 'rotulo' => 'há ' . intdiv($min, 60) . 'h'];
    return ['nivel' => 'erro', 'rotulo' => 'há ' . intdiv($min, 1440) . 'd'];
}
foreach ($devices as &$d) { $d['presenca'] = presenca_device(isset($d['mudo_min']) ? (int)$d['mudo_min'] : null); }
unset($d);

// ── Catálogo de comandos de texto (proNo 128) ──────────────────────────────
$catalogo = require __DIR__ . '/../includes/command_catalog.php';

$rotuloCat = [
    'ia' => 'Inteligência artificial (DMS/ADAS)', 'video' => 'Vídeo e gravação',
    'rede' => 'Rede e servidor', 'posicao' => 'Posição e telemetria',
    'audio' => 'Áudio e voz', 'energia' => 'Energia e relé',
    'alarme' => 'Alarmes e eventos', 'manutencao' => 'Manutenção e diagnóstico',
    'outros' => 'Outros',
];

// Enxuga o catálogo para o JS: só o que a tela usa.
$catJs = [];
foreach ($catalogo as $syn => $d) {
    $catJs[] = [
        's' => $syn, 'c' => $d['cmd'], 'n' => $d['nome'],
        'd' => $d['desc'], 'k' => $d['categoria'],
        'm' => $d['modelos'], 'u' => (bool)$d['universal'], 't' => (bool)$d['template'],
        'p' => array_map(fn($p) => ['p' => $p['p'], 'd' => $p['desc'],
                                    'f' => $p['format'], 'v' => $p['default']], $d['params']),
        'e' => array_map(fn($e) => ['c' => $e['cmd'], 'd' => $e['desc']], $d['exemplos']),
    ];
}

// ── Comandos JT/T estruturados (proNo próprio, só para modelos JT/T) ───────
$jttCmds = [
    ['pro' => 37121, 'n' => 'Vídeo ao vivo',       'k' => 'video',
     'c' => json_encode(['dataType'=>0,'codeStreamType'=>0,'channel'=>'1','videoIP'=>$vsc['ingest_ip'],'videoPort'=>$vsc['ingest_port']])],
    ['pro' => 37377, 'n' => 'Playback de gravação', 'k' => 'video',
     'c' => json_encode(['channel'=>1,'channelId'=>1,'beginTime'=>'','endTime'=>'','videoIP'=>$vsc['ingest_ip'],'videoPort'=>$vsc['playback_port']])],
    ['pro' => 37381, 'n' => 'Lista de gravações',   'k' => 'video',
     'c' => json_encode(['channel'=>1,'beginTime'=>'','endTime'=>'','alarmFlag'=>0,'resourceType'=>0,'codeType'=>0,'storageType'=>0])],
    ['pro' => 37382, 'n' => 'Upload FTP da gravação','k' => 'video',
     'c' => '{"serverAddress":"","ftpPort":21,"userName":"","password":"","fileUploadPath":"/","channel":1,"beginTime":"","endTime":"","alarmFlag":0,"resourceType":0,"codeType":0,"storageType":0,"condition":7,"instructionID":""}'],
    ['pro' => 33536, 'n' => 'TTS (mensagem de voz)','k' => 'audio', 'c' => '{"text":"","volume":5}'],
    ['pro' => 34817, 'n' => 'Foto',                 'k' => 'video', 'c' => '{"channelId":1,"photoType":0}'],
    ['pro' => 33283, 'n' => 'Confirmar alarme',     'k' => 'alarme', 'c' => '{"alarmSerialNo":0}'],
    ['pro' => 33028, 'n' => 'Consultar parâmetros', 'k' => 'manutencao', 'c' => ''],
    ['pro' => 33031, 'n' => 'Info do dispositivo',  'k' => 'manutencao', 'c' => '{}'],
];

// ── Histórico, já interpretado do lado do servidor ─────────────────────────
// O filtro de equipamento passou a aceitar VÁRIOS (lista separada por vírgula).
// Um valor só continua funcionando — links antigos e favoritos não quebram.
// Os IMEIs são conferidos contra a lista visível ao usuário: assim o parâmetro
// da URL não escolhe equipamento fora do escopo, mesmo que o JOIN já barrasse.
$imeisVisiveis = array_column($devices, 'imei');
$filtroImeis   = array_values(array_intersect(
    array_filter(array_map('trim', explode(',', (string)($_GET['imei'] ?? '')))),
    $imeisVisiveis
));
$filtroImei   = $filtroImeis[0] ?? '';        // compatibilidade com o que já lia um só
$filtroDesf   = trim($_GET['desfecho'] ?? '');

// Período em dias BRT → janela UTC (o banco é todo UTC). Padrão: 7 dias.
$filtroDe  = trim($_GET['de']  ?? '') ?: brt_today('Y-m-d', '-6 days');
$filtroAte = trim($_GET['ate'] ?? '') ?: brt_today('Y-m-d');
if ($filtroDe > $filtroAte) [$filtroDe, $filtroAte] = [$filtroAte, $filtroDe];
[$janelaDe, $janelaAte] = brt_day_range_to_utc($filtroDe, $filtroAte);

// 🔴 Teto de segurança. A paginação NÃO pode ser feita no SQL: o filtro de
// desfecho depende de interpretar o payload em PHP, então um `LIMIT 25` no
// banco devolveria páginas de tamanho variável e contagens erradas nos chips
// do resumo. Lê-se a janela inteira, interpreta-se, e a paginação acontece
// depois — por isso o período tem um teto, e por isso ele é avisado na tela
// quando estoura, em vez de truncar em silêncio.
const CMD_HIST_TETO = 2000;
const CMD_POR_PAGINA = 25;

$imeiSql    = '';
$imeiParams = [];
if ($filtroImeis) {
    $ph = [];
    foreach ($filtroImeis as $i => $im) { $ph[] = ":im$i"; $imeiParams[":im$i"] = $im; }
    $imeiSql = ' AND c.imei IN (' . implode(',', $ph) . ')';
}

$hist = $db->prepare("
    SELECT c.id, c.imei, c.command_content, c.status, c.response_payload,
           c.created_at, c.response_time,
           COALESCE(NULLIF(d.device_name,''), c.imei) AS device_name,
           COALESCE(dm.model_name, d.device_model, '-') AS model_display,
           COALESCE(cu.name, '—') AS customer_name
    FROM commands c
    JOIN devices d ON c.imei = d.imei
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN customers cu ON cu.id = d.customer_id
    WHERE c.created_at BETWEEN :de AND :ate
      {$scopeSql}{$imeiSql}
    ORDER BY c.created_at DESC LIMIT " . CMD_HIST_TETO . "
");
$hist->execute(array_merge(
    [':de' => $janelaDe, ':ate' => $janelaAte],
    $scopeParams, $imeiParams
));
$hist = $hist->fetchAll(PDO::FETCH_ASSOC);
$tetoEstourado = count($hist) >= CMD_HIST_TETO;

// Rótulo de cada nível de desfecho. Fica aqui, e não só no HTML, porque a
// exportação também precisa dele para escrever o filtro no subtítulo — o PDF
// circula fora da tela e "erro" cru não diz de que recorte ele saiu.
$chipsRotulos = ['ok' => 'executados', 'aguardando' => 'aguardando',
                 'erro' => 'com erro', 'neutro' => 'informativos'];

$linhas = [];
$resumo = ['ok' => 0, 'aguardando' => 0, 'erro' => 0, 'neutro' => 0];
foreach ($hist as $h) {
    $env  = command_response_extract($h['response_payload']);
    $desf = command_response_interpret($env['texto'], $env['codigo'], $env['conteudo']);

    // 🔴 Contar ANTES de aplicar o filtro. Enquanto o resumo era contado
    // depois, filtrar por "com erro" zerava os outros três números — o painel
    // afirmava "0 executados" para um histórico cheio deles. Passava
    // despercebido porque os números eram decorativos; agora eles são o
    // filtro, e um zero falso levaria o operador a concluir que não há nada.
    $resumo[$desf['nivel']] = ($resumo[$desf['nivel']] ?? 0) + 1;
    if ($filtroDesf !== '' && $desf['nivel'] !== $filtroDesf) continue;

    // Tempo até a resposta — o que diz se o device está respondendo rápido
    $espera = '';
    if (!empty($h['response_time']) && !empty($h['created_at'])) {
        $s = strtotime($h['response_time']) - strtotime($h['created_at']);
        if ($s >= 0) $espera = $s < 60 ? "{$s}s" : floor($s / 60) . 'min';
    }

    $linhas[] = [
        'id' => $h['id'], 'imei' => $h['imei'], 'placa' => $h['device_name'],
        'modelo' => $h['model_display'], 'cliente' => $h['customer_name'],
        'rotulo' => command_label((string)$h['command_content']),
        'enviado' => (string)$h['command_content'],
        'quando' => fmt_brt_cmd($h['created_at']),
        'espera' => $espera, 'status' => $h['status'], 'desfecho' => $desf,
        // Comando na FILA OFFLINE: o gateway aceitou, o equipamento não estava
        // conectado e a entrega fica pendente até ele voltar. É um estado
        // legítimo e de espera — diferente de "falhou" —, e sem rótulo próprio
        // era lido como erro por quem opera.
        'fila' => ($h['status'] === 'sent' && $desf['nivel'] === 'aguardando'),
        'kv' => command_response_kv($desf['detalhe']),
        // Envelope cru, para o detalhe. Quando a interpretação erra — e ela
        // errou por meses lendo o campo errado — sem isto ninguém consegue ver
        // o que o gateway realmente mandou sem abrir o banco. Truncado porque
        // este array inteiro é serializado para dentro da página.
        'bruto' => mb_substr((string)$h['response_payload'], 0, 1200),
    ];
}

// ── Paginação, depois da interpretação (ver o comentário do teto) ──────────
$totalFiltrado = count($linhas);
$paginas       = max(1, (int)ceil($totalFiltrado / CMD_POR_PAGINA));
$pagina        = min(max((int)($_GET['p'] ?? 1), 1), $paginas);
$linhasPagina  = array_slice($linhas, ($pagina - 1) * CMD_POR_PAGINA, CMD_POR_PAGINA);

/** Monta um link do histórico preservando os filtros ativos. */
function link_hist(array $troca = []): string
{
    $base = array_filter([
        'customer_id' => $_GET['customer_id'] ?? '',
        'imei'        => $_GET['imei']        ?? '',
        'desfecho'    => $_GET['desfecho']    ?? '',
        'de'          => $_GET['de']          ?? '',
        'ate'         => $_GET['ate']         ?? '',
        'p'           => $_GET['p']           ?? '',
    ], fn($v) => $v !== '' && $v !== null);
    $q = array_filter(array_merge($base, $troca), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
}

// ── Respostas offline sem comando correlacionado ───────────────────────────
//
// 🔴 O `tracker-instruction-server` grava TODA resposta em `command_responses`,
// e `pushinstructresponse.php` tenta casá-la com a linha de `commands`. Quando
// a correlação falha, a resposta existe no banco e some da interface: medido
// em produção, **14 de 23** respostas offline nunca chegaram a `commands`,
// incluindo conteúdo real de equipamento (`ext Battery:12.1V; GPRS:Link Up`).
// Este bloco mostra o que existe, SEM inventar correlação — a associação aqui
// é por equipamento e horário, e a tela diz isso com todas as letras.
$offline = $db->prepare("
    SELECT cr.imei, cr.instruct_id, cr.command_content, cr.response_content,
           cr.status, cr.created_at,
           COALESCE(NULLIF(d.device_name,''), cr.imei) AS device_name
    FROM command_responses cr
    JOIN devices d ON cr.imei = d.imei
    WHERE cr.created_at BETWEEN :de AND :ate
      {$scopeSql}
      AND NOT EXISTS (
          SELECT 1 FROM commands c
          WHERE c.imei = cr.imei AND c.response_time IS NOT NULL
            AND ABS(TIMESTAMPDIFF(SECOND, c.response_time, cr.created_at)) < 300
      )
    ORDER BY cr.created_at DESC LIMIT 20
");
$offline->execute(array_merge([':de' => $janelaDe, ':ate' => $janelaAte], $scopeParams));
$offline = $offline->fetchAll(PDO::FETCH_ASSOC);
foreach ($offline as &$o) {
    $oEnv = command_response_extract($o['response_content']);
    $o['desfecho'] = command_response_interpret($oEnv['texto'], $oEnv['codigo'], $oEnv['conteudo']);
}
unset($o);

// ── Exportação (XLSX / PDF), sensível aos MESMOS filtros da tela ───────────
//
// Sai daqui, antes de qualquer HTML: `stream_export()` manda cabeçalhos e
// encerra o processo. Exporta o conjunto filtrado INTEIRO (`$linhas`), não a
// página visível — quem pede relatório quer o recorte, não o pedaço que coube
// na tela. O teto de leitura continua valendo e vai declarado no subtítulo,
// para o arquivo nunca dar a entender que é completo quando não é.
$export = strtolower(trim($_GET['export'] ?? ''));
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_once __DIR__ . '/../includes/export_helper.php';

    $cabecalho = ['Quando', 'Cliente', 'Equipamento', 'Modelo', 'Comando',
                  'Conteúdo enviado', 'Desfecho', 'Resposta do equipamento', 'Espera'];
    $expRows = [];
    foreach ($linhas as $l) {
        $expRows[] = [
            $l['quando'],
            $l['cliente'],
            $l['placa'],
            $l['modelo'],
            $l['rotulo'] . ($l['fila'] ? ' (na fila)' : ''),
            $l['enviado'],
            $l['desfecho']['titulo'],
            $l['desfecho']['detalhe'] !== '' ? $l['desfecho']['detalhe'] : '—',
            $l['espera'] ?: '—',
        ];
    }

    // Subtítulo com o recorte inteiro: o PDF circula fora da tela, e sem isso
    // ninguém sabe de qual cliente, período ou filtro aquele número saiu.
    $rotCliente = 'Todos os clientes';
    if ($scopeCust !== null) {
        foreach ($customers as $c) if ((int)$c['id'] === (int)$scopeCust) $rotCliente = 'Cliente: ' . $c['name'];
        if ($rotCliente === 'Todos os clientes') $rotCliente = 'Cliente ' . (int)$scopeCust;
    }
    $rotEquip = $filtroImeis
        ? count($filtroImeis) . ' equipamento(s) selecionado(s)'
        : 'Todos os equipamentos';
    $rotDesf  = $filtroDesf !== ''
        ? 'Desfecho: ' . ($chipsRotulos[$filtroDesf] ?? $filtroDesf)
        : 'Todos os desfechos';
    $subtitulo = $rotCliente . '  |  ' . $rotEquip . '  |  ' . $rotDesf
               . '  |  ' . $filtroDe . ' a ' . $filtroAte
               . ($tetoEstourado ? '  |  ATENÇÃO: cortado nos ' . CMD_HIST_TETO . ' mais recentes' : '');

    stream_export($export, 'historico_comandos', $cabecalho, $expRows,
        'Histórico de Comandos', $subtitulo,
        // Conteúdo enviado e resposta são as colunas longas; as demais são
        // curtas e de largura previsível.
        [1.2, 1.2, 1.1, 0.9, 1.2, 2.6, 1.2, 2.6, 0.6]);
}

$deviceJson = json_encode($devices, JSON_UNESCAPED_UNICODE);
$page_title    = 'Comandos';
$current_route = 'comandos';

$extra_head = '<style>
/* 🔴 Este layout era `style="display:grid;grid-template-columns:1fr 1fr"` no
   próprio elemento. Estilo inline não pode ser sobreposto por media query —
   não existe media query dentro do atributo `style`, e na cascata ele vence a
   folha — então a tela ficava com DUAS colunas densas em qualquer largura,
   inclusive em telefone, espremendo o painel de envio e o histórico. Virou
   classe justamente para poder colapsar. */
.cmd-layout { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:16px; }
@media (max-width: 1100px) { .cmd-layout { grid-template-columns:minmax(0,1fr); } }
.resumo-chip { display:inline-flex; align-items:center; gap:5px; text-decoration:none; color:inherit;
               padding:2px 8px; border-radius:100px; border:1px solid transparent; }
.resumo-chip:hover { border-color:var(--hairline); background:var(--canvas-soft); }
.resumo-chip.ativo { border-color:var(--brand); color:var(--brand); }
.dev-list { max-height:230px; overflow-y:auto; border:1px solid var(--hairline); border-radius:var(--radius-sm); }
.dev-row { display:flex; align-items:center; gap:10px; padding:8px 10px; border-bottom:1px solid var(--hairline-soft); font-size:13px; cursor:pointer; }
.dev-row:last-child { border-bottom:0; }
.dev-row:hover:not(.dev-off) { background:var(--canvas-soft); }
.dev-row.dev-off { opacity:.42; cursor:not-allowed; }
.dev-row input { width:auto; margin:0; }
.dev-model { font-size:11px; color:var(--muted); font-family:"JetBrains Mono",monospace; }
.dev-presenca { display:inline-flex; align-items:center; gap:4px; font-size:11px; color:var(--muted); white-space:nowrap; }
/* O <select size="9"> sai da vista mas continua no fluxo (não é display:none):
   segue focável, continua sendo o estado lido por aoEscolherComando() e
   permanece manipulável pelo Playwright, que exige elemento com caixa. */
.cmd-sel-oculto { position:absolute; left:-10000px; width:280px; height:120px; }
.cmd-lista { max-height:280px; overflow-y:auto; border:1px solid var(--hairline); border-radius:var(--radius-sm); }
.cmd-cat { font-size:10px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted);
           background:var(--canvas-soft); padding:5px 10px; position:sticky; top:0; z-index:1;
           border-bottom:1px solid var(--hairline-soft); }
.cmd-item { padding:7px 10px; border-bottom:1px solid var(--hairline-soft); cursor:pointer; }
.cmd-item:last-child { border-bottom:0; }
.cmd-item:hover { background:var(--canvas-soft); }
.cmd-item.sel { background:#e8f0ff; box-shadow:inset 3px 0 0 var(--brand); }
.cmd-item-top { display:flex; gap:8px; align-items:baseline; flex-wrap:wrap; }
.cmd-item-nome { font-size:13px; color:var(--ink); font-weight:500; }
.cmd-item-syn { font-family:"JetBrains Mono",monospace; font-size:11px; color:var(--brand); }
.cmd-item-desc { font-size:11px; color:var(--muted); margin-top:2px; line-height:1.45;
                 display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.hist-paginacao { display:flex; gap:8px; align-items:center; justify-content:center; margin-top:10px; font-size:12px; }
.lock-note { font-size:12px; padding:8px 10px; border-radius:var(--radius-sm); background:#fdf3e8; border:1px solid #fce8d0; color:#8a5a20; margin-top:8px; }
.lock-note.free { background:#f0faf5; border-color:#d4f0e2; color:#0a7a52; }
.param-grid { display:flex; flex-direction:column; gap:10px; }
.param-item { border:1px solid var(--hairline); border-radius:var(--radius-sm); padding:10px; background:var(--canvas-soft); }
.param-head { display:flex; align-items:baseline; gap:8px; margin-bottom:5px; }
.param-tag { font-family:"JetBrains Mono",monospace; font-size:11px; font-weight:700; color:var(--brand); background:#e8f0ff; padding:1px 6px; border-radius:4px; }
.param-desc { font-size:12px; color:var(--ink); font-weight:500; }
.param-fmt { font-size:11px; color:var(--muted); margin-top:4px; line-height:1.5; }
.param-item input { margin-top:6px; font-family:"JetBrains Mono",monospace; font-size:12px; }
.cmd-preview { font-family:"JetBrains Mono",monospace; font-size:13px; background:#0a0b0d; color:#7fe3a8; padding:10px 12px; border-radius:var(--radius-sm); word-break:break-all; }
.ex-chip { display:inline-block; font-family:"JetBrains Mono",monospace; font-size:11px; background:var(--canvas-soft); border:1px solid var(--hairline); border-radius:100px; padding:3px 10px; margin:3px 4px 3px 0; cursor:pointer; }
.ex-chip:hover { border-color:var(--brand); color:var(--brand); }
.res-row { display:flex; gap:8px; align-items:flex-start; padding:6px 0; font-size:12px; border-bottom:1px solid var(--hairline-soft); }
/* .res-dot, .dot-* e .res-msg vivem no layout_base.php — são compartilhados
   com a aba de comandos do ativo. Duplicar aqui faria as duas telas divergirem
   na cor do mesmo desfecho. */
.kv-grid { display:grid; grid-template-columns:auto 1fr; gap:2px 10px; font-size:11px; margin-top:4px; }
.kv-k { color:var(--muted); }
.kv-v { font-family:"JetBrains Mono",monospace; color:var(--ink); }
.res-raw { font-family:"JetBrains Mono",monospace; font-size:11px; line-height:1.5;
           background:#0a0b0d; color:#9fb3c8; padding:10px; border-radius:var(--radius-sm);
           max-height:200px; overflow:auto; white-space:pre-wrap; word-break:break-all; margin-top:6px; }
tr.cmd-row:focus-visible { outline:2px solid var(--brand); outline-offset:-2px; }
</style>';

include __DIR__ . '/../web/layout_base.php';
?>

<div class="cmd-layout">

  <!-- ══════════ ENVIO ══════════ -->
  <div class="card">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px">Enviar comando</h4>

    <!-- 1. Equipamentos -->
    <div class="form-group">
      <label style="display:flex;justify-content:space-between;align-items:baseline">
        <span>Equipamentos</span>
        <span style="font-size:11px;font-weight:400;color:var(--muted)">
          <a href="#" onclick="marcarTodos(true);return false">todos compatíveis</a> ·
          <a href="#" onclick="marcarTodos(false);return false">limpar</a>
        </span>
      </label>
      <div class="dev-list" id="dev-list">
        <?php foreach ($devices as $d): ?>
        <label class="dev-row" data-imei="<?= htmlspecialchars($d['imei']) ?>"
               data-modelo="<?= htmlspecialchars($d['model_display']) ?>"
               data-proto="<?= htmlspecialchars($d['protocol']) ?>"
               data-cams="<?= (int)$d['camera_count'] ?>"
               data-presenca="<?= htmlspecialchars($d['presenca']['nivel']) ?>">
          <input type="checkbox" class="dev-chk" value="<?= htmlspecialchars($d['imei']) ?>" onchange="aoMarcarDevice()">
          <span style="flex:1"><?= htmlspecialchars($d['device_name']) ?></span>
          <?php /* Presença INFORMA, não bloqueia: enviar para equipamento
                   offline é fluxo suportado — o IoT Hub enfileira e entrega no
                   reconecte. O título traz o horário exato do último contato. */ ?>
          <span class="dev-presenca" title="Último contato: <?= $d['last_communication'] ? htmlspecialchars(fmt_brt_cmd($d['last_communication'])) : 'nunca' ?>">
            <span class="res-dot dot-<?= htmlspecialchars($d['presenca']['nivel']) ?>" style="margin-top:0"></span>
            <?= htmlspecialchars($d['presenca']['rotulo']) ?>
          </span>
          <?php if ($mostrarCliente): ?>
            <span class="badge" style="font-size:10px" title="Cliente do equipamento"><?= htmlspecialchars($d['customer_name']) ?></span>
          <?php endif; ?>
          <span class="dev-model"><?= htmlspecialchars($d['model_display']) ?></span>
          <span class="badge" style="font-size:10px"><?= $d['protocol'] === 'JIMI' ? 'JIMI' : 'JT/T' ?></span>
        </label>
        <?php endforeach; ?>
        <?php if (empty($devices)): ?>
        <div style="padding:16px;text-align:center;color:var(--muted);font-size:13px">Nenhum equipamento ativo.</div>
        <?php endif; ?>
      </div>
      <div id="lock-note" class="lock-note" style="display:none"></div>
      <?php /* Aviso de fila: aparece quando algum marcado está sem contato
               recente. É explicação, não impedimento — o botão continua
               liberado, porque o comando offline é enfileirado e entregue
               quando o equipamento reconecta. */ ?>
      <div id="fila-note" class="lock-note free" style="display:none"></div>
    </div>

    <!-- 2. Comando -->
    <div class="form-group">
      <label for="cmd-busca">Comando</label>
      <input type="text" id="cmd-busca" placeholder="Buscar por nome ou sintaxe (ex.: volume, DMSSP, reiniciar)"
             oninput="montarListaComandos()" style="margin-bottom:6px">
      <?php /* O <select size="9"> continua aqui, fora da vista, como ESTADO:
               `aoEscolherComando()` lê dele, ele dá um controle nativo de
               teclado/leitor de tela, e é o que a suíte Playwright manipula
               (`selectOption('#cmd-sel', …)`). O que o operador vê é a lista
               abaixo, onde cabe a sintaxe e a descrição — num <option> só o
               rótulo aparece, e era preciso escolher no escuro. */ ?>
      <select id="cmd-sel" size="9" onchange="aoEscolherComando();marcarItemLista()"
              class="cmd-sel-oculto" aria-label="Comando (lista nativa)"></select>
      <div id="cmd-lista" class="cmd-lista"></div>
      <div id="cmd-conta" style="font-size:11px;color:var(--muted);margin-top:4px"></div>
    </div>

    <!-- 3. Painel do comando -->
    <div id="cmd-painel" style="display:none">
      <div style="padding:10px;border-left:3px solid var(--brand);background:var(--canvas-soft);border-radius:var(--radius-sm);margin-bottom:12px">
        <div id="p-nome" style="font-size:13px;font-weight:600;color:var(--ink)"></div>
        <div id="p-desc" style="font-size:12px;color:var(--muted);margin-top:3px;line-height:1.5"></div>
        <div id="p-modelos" style="font-size:11px;margin-top:6px"></div>
      </div>

      <div id="p-params-wrap" style="display:none;margin-bottom:12px">
        <label style="display:block;margin-bottom:6px">Parâmetros</label>
        <div class="param-grid" id="p-params"></div>
      </div>

      <div id="p-ex-wrap" style="display:none;margin-bottom:12px">
        <label style="display:block;margin-bottom:4px">Exemplos da documentação <span style="font-weight:400;color:var(--muted);font-size:11px">(clique para preencher)</span></label>
        <div id="p-ex"></div>
      </div>

      <div class="form-group">
        <label>Será enviado</label>
        <div class="cmd-preview" id="p-preview">—</div>
        <label style="display:flex;align-items:center;gap:7px;font-size:12px;margin-top:8px;cursor:pointer;font-weight:400">
          <input type="checkbox" id="p-livre" style="width:auto" onchange="alternarLivre()">
          Editar manualmente (modo livre)
        </label>
        <input type="text" id="p-manual" style="display:none;margin-top:6px;font-family:'JetBrains Mono',monospace"
               oninput="atualizarPreview()" placeholder="Digite o comando exatamente como será enviado">
      </div>
    </div>

    <div id="envio-feedback" style="font-size:13px;margin:8px 0"></div>
    <div id="envio-resultados" style="display:none;margin:10px 0"></div>

    <button type="button" id="btn-enviar" class="btn btn-primary" disabled onclick="enviarLote()">
      Selecione equipamento e comando
    </button>
  </div>

  <!-- ══════════ HISTÓRICO ══════════ -->
  <div class="card">
    <div class="flex-between" style="margin-bottom:10px">
      <h4 style="font-size:14px;font-weight:600;color:var(--ink)">Histórico de envios</h4>
      <span style="font-size:12px;color:var(--muted)">
        <?= (int)$totalFiltrado ?> registro<?= $totalFiltrado === 1 ? '' : 's' ?>
        <?php if ($paginas > 1): ?> · página <?= (int)$pagina ?> de <?= (int)$paginas ?><?php endif; ?>
      </span>
    </div>

    <?php if ($tetoEstourado): ?>
      <div class="lock-note" style="margin-bottom:10px">
        O período escolhido tem mais de <?= CMD_HIST_TETO ?> comandos e foi cortado nos mais recentes.
        Estreite as datas para que as contagens abaixo voltem a valer para o período inteiro.
      </div>
    <?php endif; ?>

    <form method="get" style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap;align-items:flex-end">
      <?php if ($isAdmin): ?>
        <?php /* Só para admin/revendedor. A LISTA vem de
                 report_customer_options() e o FILTRO de report_customer_scope():
                 restringir um sem o outro deixaria o revendedor lendo os nomes
                 de clientes que não são dele num seletor sem efeito. */ ?>
        <select name="customer_id" style="min-width:150px;font-size:12px;padding:6px 8px" title="Cliente">
          <option value="">Todos os clientes</option>
          <?php foreach ($customers as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (string)$scopeCust === (string)$c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <?php /* Datas são dias BRT; a conversão para a janela UTC do banco é
               feita por brt_day_range_to_utc(), nunca comparando direto. */ ?>
      <input type="date" name="de"  value="<?= htmlspecialchars($filtroDe) ?>"  style="font-size:12px;padding:5px 8px" title="De (dia BRT)">
      <input type="date" name="ate" value="<?= htmlspecialchars($filtroAte) ?>" style="font-size:12px;padding:5px 8px" title="Até (dia BRT)">
      <select name="desfecho" style="min-width:120px;font-size:12px;padding:6px 8px">
        <option value="">Todos os desfechos</option>
        <option value="ok"         <?= $filtroDesf==='ok'?'selected':'' ?>>Executado</option>
        <option value="aguardando" <?= $filtroDesf==='aguardando'?'selected':'' ?>>Aguardando</option>
        <option value="erro"       <?= $filtroDesf==='erro'?'selected':'' ?>>Com erro</option>
        <option value="neutro"     <?= $filtroDesf==='neutro'?'selected':'' ?>>Informativo</option>
      </select>
      <button class="btn btn-outline btn-sm" type="submit">Filtrar</button>

      <?php
      /* Equipamentos em multisseleção. Fica DENTRO do mesmo form: o hidden do
         componente carrega os IMEIs separados por vírgula no `imei`, que é o
         mesmo parâmetro de antes — link antigo com um IMEI só continua valendo. */
      $chips_id       = 'cmddev';
      $chips_label    = 'Equipamentos (nenhum = todos)';
      $chips_param    = 'imei';
      $chips_options  = array_column($devices, 'imei');
      $chips_labels   = array_column($devices, 'device_name', 'imei');
      $chips_selected = $filtroImeis;
      $chips_visible  = 12;
      include __DIR__ . '/../web/components/chips_multiselect.php';
      ?>
    </form>

    <?php /* Exportação sensível ao filtro: os mesmos parâmetros da URL atual,
             mais `export=`. Sem a página — o arquivo leva o recorte inteiro. */ ?>
    <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap">
      <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars(link_hist(['p' => '', 'export' => 'xlsx'])) ?>">Exportar Excel</a>
      <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars(link_hist(['p' => '', 'export' => 'pdf'])) ?>">Exportar PDF</a>
      <span style="font-size:11px;color:var(--muted);align-self:center">
        <?= (int)$totalFiltrado ?> linha<?= $totalFiltrado === 1 ? '' : 's' ?> no filtro atual
      </span>
    </div>

    <?php /* Auto-atualização opcional. Fica DESLIGADA por padrão e a escolha
             persiste em localStorage — recarregar a página sozinha é aceitável
             quando o operador pediu, e intolerável quando não pediu. As
             travas (envio em curso, modal aberto, foco em campo) estão no JS,
             porque recarregar por baixo de quem está digitando um comando é
             pior do que não atualizar. */ ?>
    <label style="display:flex;align-items:center;gap:7px;font-size:12px;font-weight:400;margin-bottom:8px;cursor:pointer">
      <input type="checkbox" id="auto-atualiza" style="width:auto;margin:0" onchange="alternarAuto()">
      Atualizar a cada 30 s
      <span id="auto-contador" style="color:var(--muted);font-size:11px"></span>
    </label>

    <?php
    /* Os números viram FILTRO. Eles já respondiam "quantos deram erro?", mas
       para ver quais era preciso descer até o `<select>` de desfecho e
       submeter o formulário — dois passos para uma pergunta que o próprio
       número acabou de levantar. O nível `neutro` também passou a aparecer:
       era contado e filtrável, mas invisível, e a soma dos três não batia
       com o total de registros. */
    $chips = $chipsRotulos;   // definido junto com a interpretação, ver acima
    ?>
    <div style="display:flex;gap:6px;font-size:11px;color:var(--muted);margin-bottom:8px;flex-wrap:wrap">
      <?php foreach ($chips as $nivel => $rotulo): ?>
      <?php /* O clique preserva equipamento e período e volta para a página 1 —
               manter `p=3` ao trocar de filtro cairia numa página que talvez
               nem exista no novo recorte. */ ?>
      <a class="resumo-chip <?= $filtroDesf === $nivel ? 'ativo' : '' ?>"
         href="<?= htmlspecialchars(link_hist(['desfecho' => $filtroDesf === $nivel ? '' : $nivel, 'p' => ''])) ?>"
         title="<?= $filtroDesf === $nivel ? 'Remover o filtro' : 'Filtrar por este desfecho' ?>">
        <span class="res-dot dot-<?= $nivel ?>" style="display:inline-block;margin-top:0"></span>
        <?= (int)$resumo[$nivel] ?> <?= $rotulo ?>
      </a>
      <?php endforeach; ?>
    </div>

    <div style="max-height:520px;overflow-y:auto">
      <table style="font-size:12px;width:100%">
        <thead><tr>
          <th>Quando</th><?php if ($mostrarCliente): ?><th>Cliente</th><?php endif; ?>
          <th>Placa</th><th>Comando</th><th>Desfecho</th><th>Espera</th>
        </tr></thead>
        <tbody>
        <?php foreach ($linhasPagina as $l): ?>
          <?php /* tabindex + Enter/Espaço: a linha abre um modal, então é um
                   controle. Com `onclick` sozinho ela não existia para quem
                   navega por teclado. */ ?>
          <tr class="cmd-row" style="cursor:pointer" tabindex="0" role="button"
              aria-label="Detalhe do comando <?= htmlspecialchars($l['rotulo']) ?>"
              onclick="verDetalhe(<?= (int)$l['id'] ?>)"
              onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();verDetalhe(<?= (int)$l['id'] ?>)}">
            <td style="white-space:nowrap"><?= htmlspecialchars($l['quando']) ?></td>
            <?php if ($mostrarCliente): ?>
              <td style="font-size:11px"><?= htmlspecialchars($l['cliente']) ?></td>
            <?php endif; ?>
            <td>
              <?= htmlspecialchars($l['placa']) ?>
              <div class="dev-model"><?= htmlspecialchars($l['modelo']) ?></div>
            </td>
            <td>
              <?= htmlspecialchars($l['rotulo']) ?>
              <div class="dev-model" style="font-size:10px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($l['enviado']) ?></div>
            </td>
            <td>
              <div style="display:flex;gap:6px;align-items:flex-start">
                <span class="res-dot dot-<?= htmlspecialchars($l['desfecho']['nivel']) ?>" style="margin-top:4px"></span>
                <span><?= htmlspecialchars($l['desfecho']['titulo']) ?></span>
                <?php if ($l['fila']): ?>
                  <span class="badge" style="font-size:10px" title="O gateway aceitou e guardou o comando; a entrega acontece quando o equipamento reconectar.">na fila</span>
                <?php endif; ?>
              </div>
              <?php if ($l['kv']): ?>
                <div class="kv-grid">
                  <?php foreach (array_slice($l['kv'], 0, 3) as $k => $v): ?>
                    <span class="kv-k"><?= htmlspecialchars($k) ?></span><span class="kv-v"><?= htmlspecialchars($v) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php elseif ($l['desfecho']['detalhe'] !== ''): ?>
                <?php /* A RESPOSTA do equipamento. Sem esta linha a coluna
                        mostrava só a classificação ("Executado") e o que o
                        device respondeu não aparecia em lugar nenhum da lista
                        — era preciso abrir o detalhe de cada linha para ver. */ ?>
                <div class="res-msg" title="<?= htmlspecialchars($l['desfecho']['detalhe']) ?>"><?= htmlspecialchars($l['desfecho']['detalhe']) ?></div>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;color:var(--muted)"><?= htmlspecialchars($l['espera'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($linhasPagina)): ?>
          <tr><td colspan="<?= $mostrarCliente ? 6 : 5 ?>"><div class="empty-state"><p>Nenhum comando no período e filtro atuais.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
    <div class="hist-paginacao">
      <?php if ($pagina > 1): ?>
        <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars(link_hist(['p' => $pagina - 1])) ?>">← anterior</a>
      <?php else: ?>
        <span class="btn btn-outline btn-sm" style="opacity:.4;pointer-events:none">← anterior</span>
      <?php endif; ?>
      <span style="color:var(--muted)"><?= (int)$pagina ?> / <?= (int)$paginas ?></span>
      <?php if ($pagina < $paginas): ?>
        <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars(link_hist(['p' => $pagina + 1])) ?>">próxima →</a>
      <?php else: ?>
        <span class="btn btn-outline btn-sm" style="opacity:.4;pointer-events:none">próxima →</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($offline): ?>
    <?php /* 🔴 Respostas que chegaram e não acharam o comando. Medido em
             produção: 14 de 23 respostas offline nunca chegaram a `commands`,
             e a tela nunca as mostrou — inclusive conteúdo real de
             equipamento. O texto abaixo é explícito quanto ao fato de a
             associação ser por equipamento e horário: inventar correlação aqui
             seria repetir, na interface, o erro que já existe no motor. */ ?>
    <details style="margin-top:14px">
      <summary style="font-size:12px;cursor:pointer;color:var(--muted)">
        <?= count($offline) ?> resposta(s) recebida(s) sem comando correlacionado no período
      </summary>
      <div style="font-size:11px;color:var(--muted);margin:6px 0 8px;line-height:1.5">
        O equipamento respondeu, mas o sistema não conseguiu ligar a resposta a um envio específico.
        Estão listadas por equipamento e horário — a associação com o que você enviou é sua, não do sistema.
      </div>
      <div style="max-height:240px;overflow-y:auto">
        <table style="font-size:12px;width:100%">
          <thead><tr><th>Quando</th><th>Equipamento</th><th>Resposta</th></tr></thead>
          <tbody>
          <?php foreach ($offline as $o): ?>
            <tr>
              <td style="white-space:nowrap"><?= htmlspecialchars(fmt_brt_cmd($o['created_at'])) ?></td>
              <td><?= htmlspecialchars($o['device_name']) ?></td>
              <td>
                <div style="display:flex;gap:6px;align-items:flex-start">
                  <span class="res-dot dot-<?= htmlspecialchars($o['desfecho']['nivel']) ?>" style="margin-top:4px"></span>
                  <span><?= htmlspecialchars($o['desfecho']['titulo']) ?></span>
                </div>
                <?php if ($o['desfecho']['detalhe'] !== ''): ?>
                  <div class="res-msg" title="<?= htmlspecialchars($o['desfecho']['detalhe']) ?>"><?= htmlspecialchars($o['desfecho']['detalhe']) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <?php endif; ?>
  </div>
</div>

<!-- Modal detalhe -->
<div id="detail-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1000;align-items:center;justify-content:center" onclick="this.style.display='none'">
  <div class="card" style="max-width:640px;width:92%;max-height:82vh;overflow-y:auto" onclick="event.stopPropagation()">
    <div class="flex-between" style="margin-bottom:12px">
      <h4 style="font-size:14px;font-weight:600;color:var(--ink)">Detalhe do comando</h4>
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('detail-modal').style.display='none'">Fechar</button>
    </div>
    <div id="detail-content"></div>
  </div>
</div>

<script>
var DEVICES  = <?= $deviceJson ?>;
var CATALOGO = <?= json_encode($catJs, JSON_UNESCAPED_UNICODE) ?>;
var JTT      = <?= json_encode($jttCmds, JSON_UNESCAPED_UNICODE) ?>;
var ROTCAT   = <?= json_encode($rotuloCat, JSON_UNESCAPED_UNICODE) ?>;
// Só as linhas da PÁGINA: o modal abre a partir da tabela visível, e serializar
// as 2000 do teto para dentro do HTML pesaria alguns MB por carregamento.
var LINHAS   = <?= json_encode($linhasPagina, JSON_UNESCAPED_UNICODE) ?>;

var cmdAtual = null;   // entrada do catálogo escolhida
var proAtual = 128;

/** Modelos atualmente marcados (Set). */
function modelosMarcados() {
    var s = {};
    document.querySelectorAll('.dev-chk:checked').forEach(function (c) {
        s[c.closest('.dev-row').dataset.modelo] = 1;
    });
    return Object.keys(s);
}
function imeisMarcados() {
    return Array.prototype.map.call(document.querySelectorAll('.dev-chk:checked'), function (c) { return c.value; });
}

/**
 * Aplica a trava: com um comando NÃO universal escolhido, só ficam habilitados
 * os equipamentos cujo modelo a documentação cobre. Sem comando escolhido, o
 * primeiro modelo marcado define a trava.
 */
function aplicarTrava() {
    var nota = document.getElementById('lock-note');
    var marcados = modelosMarcados();
    var permitidos = null, motivo = '';

    if (cmdAtual && !cmdAtual.u) {
        permitidos = cmdAtual.m;
        motivo = '<strong>' + cmdAtual.c + '</strong> é documentado só para ' + cmdAtual.m.join(', ') +
                 '. Equipamentos de outro modelo estão desabilitados — enviar para eles devolveria ' +
                 '“comando não suportado” minutos depois, no callback.';
    } else if (cmdAtual && cmdAtual.u) {
        permitidos = null;
        motivo = '<strong>' + cmdAtual.c + '</strong> é do núcleo comum do proNo 128 (documentado em ' +
                 cmdAtual.m.length + ' modelos). Trava liberada: dá para enviar para a frota inteira.';
    } else if (marcados.length === 1) {
        permitidos = null;
        motivo = '';
    }

    document.querySelectorAll('.dev-row').forEach(function (row) {
        var chk = row.querySelector('.dev-chk');
        var ok = !permitidos || permitidos.indexOf(row.dataset.modelo) >= 0;
        chk.disabled = !ok;
        row.classList.toggle('dev-off', !ok);
        if (!ok && chk.checked) chk.checked = false;
    });

    if (motivo) {
        nota.style.display = 'block';
        nota.className = 'lock-note' + (cmdAtual && cmdAtual.u ? ' free' : '');
        nota.innerHTML = motivo;
    } else {
        nota.style.display = 'none';
    }
    atualizarBotao();
}

function aoMarcarDevice() { montarListaComandos(); aplicarTrava(); avisarFila(); }

/**
 * Avisa que há equipamento sem contato recente entre os marcados.
 *
 * ⚠️ Avisa e SÓ. O envio para equipamento offline é suportado de ponta a
 * ponta: o IoT Hub responde "converted to an offline command", guarda o
 * comando e entrega no reconecte. Desabilitar o botão aqui quebraria um fluxo
 * legítimo — quem programa manutenção manda comando de madrugada justamente
 * para o veículo que está com a ignição desligada.
 */
function avisarFila() {
    var nota = document.getElementById('fila-note');
    var fora = [];
    document.querySelectorAll('.dev-chk:checked').forEach(function (c) {
        var row = c.closest('.dev-row');
        if (row.dataset.presenca !== 'ok') {
            fora.push(row.querySelector('span').textContent.trim() + ' (' +
                      row.querySelector('.dev-presenca').textContent.trim() + ')');
        }
    });
    if (!fora.length) { nota.style.display = 'none'; return; }
    nota.style.display = 'block';
    nota.innerHTML = '<strong>' + fora.length + ' sem contato recente:</strong> ' + esc(fora.join(' · ')) +
        '.<br>O envio continua liberado — o comando fica <strong>na fila</strong> e é entregue quando o equipamento reconectar. ' +
        'A resposta só aparece no histórico depois disso.';
}

function marcarTodos(v) {
    document.querySelectorAll('.dev-chk').forEach(function (c) { if (!c.disabled) c.checked = v; });
    aoMarcarDevice();
}

/**
 * Lista de comandos filtrada pelos modelos marcados. Comando que nenhum
 * equipamento marcado suporta simplesmente não aparece — mostrar e desabilitar
 * encheria a lista de 119 itens de ruído.
 */
function montarListaComandos() {
    var busca = (document.getElementById('cmd-busca').value || '').toLowerCase().trim();
    var mods  = modelosMarcados();
    var protos = {};
    document.querySelectorAll('.dev-chk:checked').forEach(function (c) {
        protos[c.closest('.dev-row').dataset.proto] = 1;
    });

    var itens = CATALOGO.filter(function (x) {
        if (mods.length && !x.u && !mods.some(function (m) { return x.m.indexOf(m) >= 0; })) return false;
        if (!busca) return true;
        return (x.n + ' ' + x.s + ' ' + x.c + ' ' + x.d).toLowerCase().indexOf(busca) >= 0;
    }).map(function (x) {
        return { tipo: 'txt', k: x.k, rot: x.n + '  [' + x.s + ']', val: 'T:' + x.s, u: x.u,
                 nome: x.n, syn: x.s, desc: x.d };
    });

    // JT/T estruturado só entra se todos os marcados forem JT/T
    var soJtt = mods.length > 0 && !protos['JIMI'];
    if (soJtt || mods.length === 0) {
        JTT.forEach(function (j) {
            if (busca && (j.n + ' ' + j.pro).toLowerCase().indexOf(busca) < 0) return;
            itens.push({ tipo: 'jtt', k: j.k, rot: j.n + '  [proNo ' + j.pro + ']', val: 'J:' + j.pro, u: false,
                         nome: j.n, syn: 'proNo ' + j.pro,
                         desc: 'Comando estruturado JT/T 808 — o conteúdo é JSON.' });
        });
    }

    var porCat = {};
    itens.forEach(function (i) { (porCat[i.k] = porCat[i.k] || []).push(i); });

    var sel = document.getElementById('cmd-sel');
    var antes = sel.value;
    sel.innerHTML = '';
    Object.keys(porCat).sort().forEach(function (k) {
        var g = document.createElement('optgroup');
        g.label = ROTCAT[k] || k;
        porCat[k].sort(function (a, b) { return a.rot.localeCompare(b.rot); }).forEach(function (i) {
            var o = document.createElement('option');
            o.value = i.val;
            o.textContent = (i.u ? '★ ' : '') + i.rot;
            if (i.u) o.title = 'Universal — vale para todos os modelos (proNo 128)';
            g.appendChild(o);
        });
        sel.appendChild(g);
    });
    if (antes) sel.value = antes;

    // Lista visível: mesma ordenação do <select>, com a sintaxe e a descrição
    // que não cabem num <option>.
    var lista = document.getElementById('cmd-lista');
    lista.innerHTML = '';
    Object.keys(porCat).sort().forEach(function (k) {
        var cab = document.createElement('div');
        cab.className = 'cmd-cat';
        cab.textContent = ROTCAT[k] || k;
        lista.appendChild(cab);
        porCat[k].sort(function (a, b) { return a.rot.localeCompare(b.rot); }).forEach(function (i) {
            var row = document.createElement('div');
            row.className = 'cmd-item';
            row.dataset.val = i.val;
            row.onclick = function () { escolherDaLista(i.val); };
            row.innerHTML =
                '<div class="cmd-item-top">' +
                  '<span class="cmd-item-nome">' + (i.u ? '★ ' : '') + esc(i.nome) + '</span>' +
                  '<span class="cmd-item-syn">' + esc(i.syn) + '</span>' +
                '</div>' +
                (i.desc ? '<div class="cmd-item-desc">' + esc(i.desc) + '</div>' : '');
            if (i.u) row.title = 'Universal — vale para todos os modelos (proNo 128)';
            lista.appendChild(row);
        });
    });
    marcarItemLista();

    document.getElementById('cmd-conta').textContent =
        itens.length + ' comando(s) disponível(is)' +
        (mods.length ? ' para ' + mods.join(', ') : '') + '. ★ = universal (proNo 128).';
}

/** Clique na lista: o <select> continua sendo a fonte da verdade. */
function escolherDaLista(val) {
    var sel = document.getElementById('cmd-sel');
    sel.value = val;
    aoEscolherComando();
    marcarItemLista();
}

/** Espelha no visual a opção escolhida (por clique OU pelo teclado no select). */
function marcarItemLista() {
    var v = document.getElementById('cmd-sel').value;
    document.querySelectorAll('#cmd-lista .cmd-item').forEach(function (r) {
        var ativo = r.dataset.val === v;
        r.classList.toggle('sel', ativo);
        if (ativo) r.scrollIntoView({ block: 'nearest' });
    });
}

function aoEscolherComando() {
    var v = document.getElementById('cmd-sel').value;
    var painel = document.getElementById('cmd-painel');
    if (!v) { painel.style.display = 'none'; cmdAtual = null; aplicarTrava(); return; }

    if (v.charAt(0) === 'J') {
        var pro = parseInt(v.slice(2), 10);
        var j = JTT.filter(function (x) { return x.pro === pro; })[0];
        cmdAtual = null; proAtual = pro;
        painel.style.display = 'block';
        document.getElementById('p-nome').textContent = j.n + ' (proNo ' + pro + ')';
        document.getElementById('p-desc').textContent = 'Comando estruturado JT/T 808. O conteúdo é JSON — ajuste os campos no modo livre.';
        document.getElementById('p-modelos').innerHTML = '<span class="badge badge-info">só modelos JT/T</span>';
        document.getElementById('p-params-wrap').style.display = 'none';
        document.getElementById('p-ex-wrap').style.display = 'none';
        document.getElementById('p-livre').checked = true;
        alternarLivre();
        document.getElementById('p-manual').value = j.c;
        atualizarPreview();
        aplicarTrava();
        return;
    }

    var syn = v.slice(2);
    cmdAtual = CATALOGO.filter(function (x) { return x.s === syn; })[0];
    proAtual = 128;
    if (!cmdAtual) { painel.style.display = 'none'; return; }

    painel.style.display = 'block';
    document.getElementById('p-nome').textContent = cmdAtual.n + '  —  ' + cmdAtual.s;
    document.getElementById('p-desc').textContent = cmdAtual.d || '';
    document.getElementById('p-modelos').innerHTML =
        (cmdAtual.u ? '<span class="badge badge-success">universal (proNo 128)</span> ' : '') +
        cmdAtual.m.map(function (m) { return '<span class="badge" style="font-size:10px">' + m + '</span>'; }).join(' ');

    // Campos de parâmetro
    var wrap = document.getElementById('p-params-wrap');
    var box  = document.getElementById('p-params');
    box.innerHTML = '';
    if (cmdAtual.p && cmdAtual.p.length) {
        cmdAtual.p.forEach(function (p, i) {
            var d = document.createElement('div');
            d.className = 'param-item';
            d.innerHTML =
                '<div class="param-head"><span class="param-tag">' + esc(p.p) + '</span>' +
                '<span class="param-desc">' + esc(p.d || '') + '</span></div>' +
                (p.f ? '<div class="param-fmt"><strong>Formato aceito:</strong> ' + esc(p.f) + '</div>' : '') +
                (p.v ? '<div class="param-fmt"><strong>Padrão de fábrica:</strong> ' + esc(p.v) + '</div>' : '') +
                '<input type="text" class="p-in" data-i="' + i + '" oninput="atualizarPreview()" placeholder="valor de ' + esc(p.p) + '">';
            box.appendChild(d);
        });
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
    }

    // Exemplos clicáveis
    var exw = document.getElementById('p-ex-wrap'), exb = document.getElementById('p-ex');
    exb.innerHTML = '';
    if (cmdAtual.e && cmdAtual.e.length) {
        cmdAtual.e.forEach(function (e) {
            var c = document.createElement('span');
            c.className = 'ex-chip';
            c.textContent = e.c;
            c.title = e.d || '';
            c.onclick = function () { usarExemplo(e.c); };
            exb.appendChild(c);
        });
        exw.style.display = 'block';
    } else { exw.style.display = 'none'; }

    document.getElementById('p-livre').checked = false;
    alternarLivre();
    atualizarPreview();
    aplicarTrava();
}

/** Preenche os campos a partir de um exemplo da wiki. */
function usarExemplo(exemplo) {
    var corpo = exemplo.replace(/#$/, '');
    var toks  = corpo.split(',');
    var ins   = document.querySelectorAll('.p-in');
    // O 1º token é o nome do comando; sub-comandos fixos também são pulados,
    // então alinha pelo FIM: os N últimos tokens são os N parâmetros.
    var vals = toks.slice(Math.max(1, toks.length - ins.length));
    ins.forEach(function (inp, i) { inp.value = vals[i] !== undefined ? vals[i] : ''; });
    document.getElementById('p-livre').checked = false;
    alternarLivre();
    atualizarPreview();
}

function alternarLivre() {
    var livre = document.getElementById('p-livre').checked;
    document.getElementById('p-manual').style.display = livre ? 'block' : 'none';
    document.getElementById('p-params-wrap').style.opacity = livre ? .45 : 1;
    if (livre && !document.getElementById('p-manual').value) {
        document.getElementById('p-manual').value = montarComando();
    }
    atualizarPreview();
}

/** Monta a string final substituindo os placeholders pelos valores digitados. */
function montarComando() {
    if (!cmdAtual) return '';
    var corpo = cmdAtual.s.replace(/#$/, '');
    var toks  = corpo.split(',');
    var ins   = document.querySelectorAll('.p-in');
    if (!ins.length) return cmdAtual.s;

    var vals = Array.prototype.map.call(ins, function (i) { return i.value.trim(); });
    var idx  = 0;
    var saida = toks.map(function (t, pos) {
        if (pos === 0) return t;
        // placeholder = P1..Pn ou letra única maiúscula
        if (/^(P\d+|[A-Z])$/.test(t)) { var v = vals[idx]; idx++; return v !== undefined && v !== '' ? v : t; }
        return t;
    });
    return saida.join(',') + '#';
}

function atualizarPreview() {
    var livre = document.getElementById('p-livre').checked;
    var txt = livre ? document.getElementById('p-manual').value : montarComando();
    document.getElementById('p-preview').textContent = txt || '—';
    atualizarBotao();
}

function atualizarBotao() {
    var b = document.getElementById('btn-enviar');
    var n = imeisMarcados().length;
    var txt = document.getElementById('p-preview').textContent;
    var pronto = n > 0 && txt && txt !== '—';
    b.disabled = !pronto;
    b.textContent = !n ? 'Selecione equipamento e comando'
                  : (!pronto ? 'Escolha um comando'
                  : 'Enviar para ' + n + ' equipamento' + (n > 1 ? 's' : ''));
}

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/** Envia um comando por equipamento — /sendcommand continua sendo 1 IMEI por chamada. */
function enviarLote() {
    var imeis = imeisMarcados();
    var livre = document.getElementById('p-livre').checked;
    var conteudo = livre ? document.getElementById('p-manual').value.trim() : montarComando();
    if (!imeis.length || !conteudo) return;

    // Placeholder não substituído é erro de preenchimento, não comando válido
    if (!livre && /(,P\d+|,[A-Z])(,|#)/.test(conteudo)) {
        document.getElementById('envio-feedback').innerHTML =
            '<span style="color:var(--error)">Preencha todos os parâmetros — ainda há placeholders em <code>' + esc(conteudo) + '</code>.</span>';
        return;
    }

    var btn = document.getElementById('btn-enviar');
    btn.disabled = true; btn.textContent = 'Enviando…';
    document.getElementById('envio-feedback').innerHTML = '';
    var cx = document.getElementById('envio-resultados');
    cx.style.display = 'block';
    cx.innerHTML = '<div style="font-size:12px;color:var(--muted);margin-bottom:6px">Resultado por equipamento</div>';

    var nomes = {};
    document.querySelectorAll('.dev-row').forEach(function (r) { nomes[r.dataset.imei] = r.querySelector('span').textContent.trim(); });

    var feitos = 0;
    // Contador de trabalho em voo: sobe no despacho e só zera quando o
    // acompanhamento de cada comando termina. É o que impede a atualização
    // automática de recarregar a página no meio de um envio.
    window.ENVIO_EM_CURSO = (window.ENVIO_EM_CURSO || 0) + imeis.length;
    imeis.forEach(function (imei) {
        var linha = document.createElement('div');
        linha.className = 'res-row';
        linha.innerHTML = '<span class="res-dot dot-aguardando"></span><span style="flex:1">' +
                          esc(nomes[imei] || imei) + '</span><span style="color:var(--muted)">enviando…</span>';
        cx.appendChild(linha);

        fetch('/sendcommand', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify({
                imei: imei, content: conteudo, proNo: proAtual,
                serverFlagId: proAtual === 128 ? 1 : 0
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            var ok = j && (j.code === 0 || j.code === 200);
            linha.querySelector('.res-dot').className = 'res-dot ' + (ok ? 'dot-ok' : 'dot-erro');
            linha.lastChild.textContent = ok ? ('enfileirado' + (j.command_id ? ' #' + j.command_id : ''))
                                             : (j && j.msg ? j.msg : 'falhou');
            if (ok && j.command_id) acompanhar(j.command_id, linha);
            else window.ENVIO_EM_CURSO--;   // sem acompanhamento, o trabalho acabou aqui
        })
        .catch(function (e) {
            linha.querySelector('.res-dot').className = 'res-dot dot-erro';
            linha.lastChild.textContent = 'erro de rede';
            window.ENVIO_EM_CURSO--;
        })
        .finally(function () {
            if (++feitos !== imeis.length) return;
            btn.disabled = false; atualizarBotao();
            // O histórico ao lado é renderizado no servidor: os envios que
            // acabaram de sair não estão nele até a página recarregar. Sem
            // dizer isso, a tela parece ter engolido o comando.
            if (!document.getElementById('recarregar-hist')) {
                var aviso = document.createElement('div');
                aviso.id = 'recarregar-hist';
                aviso.style.cssText = 'font-size:11px;color:var(--muted);margin-top:8px';
                aviso.innerHTML = 'O histórico ao lado ainda não inclui estes envios · ' +
                                  '<a href="#" onclick="location.reload();return false">atualizar</a>';
                cx.appendChild(aviso);
            }
        });
    });
}

/**
 * Acompanha a resposta de um comando e escreve o desfecho na linha dele.
 *
 * 🔴 Até 15/08/2026 esta função lia `j.response` — um campo de PRIMEIRO NÍVEL
 * que o `/commandstatus` nunca emitiu: o endpoint devolve
 * `{code, commands:[{...response}], offline_count, offline_recent}`. A condição
 * era falsa sempre, então o painel parava em "enfileirado #N" e, um minuto
 * depois, afirmava "sem resposta (fila offline)" mesmo quando o equipamento já
 * tinha respondido — 119 dos 120 comandos dos últimos 30 dias em produção
 * tinham resposta gravada quando o defeito foi encontrado. Quebra de contrato
 * silenciosa: nenhum erro no console, nenhum 500, só uma tela que mente.
 */
function acompanhar(id, linha) {
    var t = 0;
    var alvo = linha.lastChild;
    var ultimo = null;

    var pinta = function (nivel, titulo, resposta) {
        linha.querySelector('.res-dot').className = 'res-dot dot-' + (nivel || 'neutro');
        var html = '<span>' + esc(titulo || '') + '</span>';
        if (resposta) {
            html += '<div class="res-msg" title="' + esc(resposta) + '">' + esc(resposta) + '</div>';
        }
        alvo.innerHTML = html;
    };

    var tick = function () {
        fetch('/commandstatus?command_id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var c = (j && j.commands && j.commands[0]) || null;
                if (c && (c.response || (c.titulo && c.titulo !== 'Sem resposta ainda'))) {
                    ultimo = c;
                    pinta(c.nivel, c.titulo, c.response);
                    // 'aguardando' não é desfecho: o equipamento ainda pode
                    // responder (offline entra em fila). Segue consultando até
                    // o orçamento acabar, mas já mostrando o que se sabe.
                    if (c.nivel !== 'aguardando') { window.ENVIO_EM_CURSO--; return; }
                }
                if (++t < 12) { setTimeout(tick, t < 8 ? 3000 : 10000); return; }
                window.ENVIO_EM_CURSO--;
                if (!ultimo) {
                    pinta('aguardando', 'Sem resposta ainda',
                          'o equipamento não respondeu; o comando fica na fila até ele reconectar');
                } else if (ultimo.nivel === 'aguardando') {
                    // Fim do acompanhamento, não fim do comando: a entrega
                    // continua pendente no gateway. Dizer "sem resposta" aqui
                    // seria repetir a mentira que esta tela contava antes.
                    pinta(ultimo.nivel, ultimo.titulo,
                          (ultimo.response ? ultimo.response + ' · ' : '') +
                          'na fila — a resposta aparece no histórico quando o equipamento reconectar');
                }
            })
            .catch(function () {
                if (++t < 12) { setTimeout(tick, 5000); return; }
                window.ENVIO_EM_CURSO--;
                if (!ultimo) pinta('erro', 'Falha ao consultar o status', '');
            });
    };
    setTimeout(tick, 3000);
}

function verDetalhe(id) {
    var l = LINHAS.filter(function (x) { return x.id === id; })[0];
    if (!l) return;
    var d = l.desfecho;
    var kv = '';
    if (l.kv && Object.keys(l.kv).length) {
        kv = '<div class="kv-grid" style="margin-top:8px">' +
             Object.keys(l.kv).map(function (k) {
                 return '<span class="kv-k">' + esc(k) + '</span><span class="kv-v">' + esc(l.kv[k]) + '</span>';
             }).join('') + '</div>';
    }
    document.getElementById('detail-content').innerHTML =
        '<div style="font-size:12px;line-height:1.9">' +
          '<div><strong>Equipamento:</strong> ' + esc(l.placa) + ' <span class="dev-model">' + esc(l.modelo) + ' · ' + esc(l.imei) + '</span></div>' +
          '<div><strong>Comando:</strong> ' + esc(l.rotulo) + '</div>' +
          '<div><strong>Enviado em:</strong> ' + esc(l.quando) + (l.espera ? ' · respondeu em ' + esc(l.espera) : '') + '</div>' +
          '<div><strong>Status no banco:</strong> <span class="badge">' + esc(l.status) + '</span></div>' +
        '</div>' +
        '<div style="margin-top:10px"><label style="font-size:11px">Conteúdo enviado</label>' +
          '<div class="cmd-preview">' + esc(l.enviado) + '</div></div>' +
        '<div style="margin-top:10px;padding:10px;border-radius:var(--radius-sm);background:var(--canvas-soft)">' +
          '<div style="display:flex;gap:8px;align-items:center">' +
            '<span class="res-dot dot-' + esc(d.nivel) + '"></span>' +
            '<strong style="flex:1">' + esc(d.titulo) + '</strong>' +
            (d.detalhe ? '<button type="button" class="btn btn-outline btn-sm" onclick="copiarResposta(this)">Copiar</button>' : '') +
          '</div>' +
          (d.detalhe ? '<div id="resp-texto" style="font-family:\'JetBrains Mono\',monospace;font-size:11px;margin-top:6px;color:var(--muted);word-break:break-all">' + esc(d.detalhe) + '</div>' : '') +
          kv +
          (d.dica ? '<div style="font-size:12px;margin-top:8px;line-height:1.6">' + esc(d.dica) + '</div>' : '') +
        '</div>' +
        // Envelope cru sob demanda: fechado por padrão para não poluir, mas
        // disponível — é o que permite ver que a leitura interpretada está
        // certa (ou provar que não está) sem precisar de acesso ao banco.
        (l.bruto ? '<details style="margin-top:10px">' +
            '<summary style="font-size:12px;cursor:pointer;color:var(--muted)">Resposta bruta do gateway</summary>' +
            '<div class="res-raw">' + esc(l.bruto) + '</div></details>' : '');
    document.getElementById('detail-modal').style.display = 'flex';
    document.querySelector('#detail-modal .btn').focus();
}

/** Copia a resposta do equipamento — payload de parâmetros não se transcreve à mão. */
function copiarResposta(btn) {
    var el = document.getElementById('resp-texto');
    if (!el) return;
    var txt = el.textContent;
    var fim = function (ok) {
        btn.textContent = ok ? 'Copiado' : 'Falhou';
        setTimeout(function () { btn.textContent = 'Copiar'; }, 1600);
    };
    // navigator.clipboard exige contexto seguro; em HTTP puro (homolog) ele
    // nem existe, e sem o fallback o botão não faria nada, calado.
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(txt).then(function () { fim(true); }, function () { fim(false); });
        return;
    }
    var ta = document.createElement('textarea');
    ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    fim(ok);
}

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var m = document.getElementById('detail-modal');
    if (m && m.style.display === 'flex') m.style.display = 'none';
});

/* ══════════ Auto-atualização (opcional, 30 s) ══════════
 *
 * Recarrega a página inteira, porque o histórico é renderizado no servidor —
 * é o caminho honesto sem inventar um endpoint de fragmento só para isto.
 *
 * 🔴 As travas abaixo são o ponto todo. Recarregar por baixo de quem está
 * digitando um comando, esperando o resultado de um envio ou lendo um detalhe
 * destrói trabalho e faz a tela parecer possuída. Auto-atualização que atrapalha
 * é pior do que nenhuma: o operador desliga e nunca mais liga.
 */
var AUTO_SEG = 30;
var autoRestante = AUTO_SEG;
var autoTimer = null;

function autoBloqueado() {
    if (window.ENVIO_EM_CURSO) return 'envio em curso';
    var m = document.getElementById('detail-modal');
    if (m && m.style.display === 'flex') return 'detalhe aberto';
    var a = document.activeElement;
    if (a && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName) && a.id !== 'auto-atualiza') return 'digitando';
    // Campo EDITADO, não campo existente. `defaultValue` é o atributo `value`
    // do HTML, então isto compara contra o padrão do próprio parâmetro — pausar
    // só porque há um comando escolhido deixaria a atualização em pausa
    // permanente, que é o estado normal de quem está usando a tela.
    var editados = Array.prototype.slice
        .call(document.querySelectorAll('#p-params input, #p-manual'))
        .some(function (i) { return i.value !== i.defaultValue; });
    if (editados) return 'comando em edição';
    return '';
}

/* A recarga não pode custar a seleção: sem isto, quem liga a atualização
   automática perde equipamento marcado e comando escolhido a cada 30 s. */
function salvarSelecao() {
    try {
        localStorage.setItem('cmd_sel_estado', JSON.stringify({
            busca: document.getElementById('cmd-busca').value,
            cmd:   document.getElementById('cmd-sel').value,
            imeis: Array.prototype.slice.call(document.querySelectorAll('.dev-chk:checked')).map(function (c) { return c.value; })
        }));
    } catch (e) {}
}

function restaurarSelecao() {
    var s;
    try { s = JSON.parse(localStorage.getItem('cmd_sel_estado') || 'null'); } catch (e) { return; }
    if (!s) return;
    if (s.imeis && s.imeis.length) {
        document.querySelectorAll('.dev-chk').forEach(function (c) {
            if (s.imeis.indexOf(c.value) >= 0 && !c.disabled) c.checked = true;
        });
    }
    if (s.busca) document.getElementById('cmd-busca').value = s.busca;
    montarListaComandos();
    aplicarTrava();
    avisarFila();
    if (s.cmd) {
        var sel = document.getElementById('cmd-sel');
        sel.value = s.cmd;
        if (sel.value === s.cmd) { aoEscolherComando(); marcarItemLista(); }
    }
}

function alternarAuto() {
    var on = document.getElementById('auto-atualiza').checked;
    try { localStorage.setItem('cmd_auto', on ? '1' : '0'); } catch (e) {}
    if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
    autoRestante = AUTO_SEG;
    if (!on) { document.getElementById('auto-contador').textContent = ''; return; }
    autoTimer = setInterval(function () {
        var motivo = autoBloqueado();
        if (motivo) {
            autoRestante = AUTO_SEG;
            document.getElementById('auto-contador').textContent = '(em pausa: ' + motivo + ')';
            return;
        }
        if (--autoRestante <= 0) { salvarSelecao(); location.reload(); return; }
        document.getElementById('auto-contador').textContent = '(em ' + autoRestante + 's)';
    }, 1000);
}

montarListaComandos();
atualizarBotao();
avisarFila();
try {
    if (localStorage.getItem('cmd_auto') === '1') {
        document.getElementById('auto-atualiza').checked = true;
        restaurarSelecao();   // só faz sentido no modo automático — é ele que recarrega
        atualizarBotao();
        alternarAuto();
    }
} catch (e) {}
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
