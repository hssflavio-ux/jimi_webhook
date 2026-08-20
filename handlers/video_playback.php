<?php
/**
 * JIMI Webhook System — Vídeo Playback v4.9.34
 * Rota: /video/playback
 *
 * A tela é uma só; os DOIS protocolos chegam nela por caminhos opostos, e a
 * linha do tempo é a mesma tabela (`resource_lists`) nos dois casos.
 *
 * Fluxo JT/T (JC450/JC181/JC182/JC371) — pergunta e resposta:
 *   1. [Requisitar] → proNo 37381 (0x9205, consulta de gravações no cartão).
 *      A janela beginTime/endTime é GMT-0 compacta (yyMMddHHmmss) e NÃO pode
 *      cruzar o dia — o período é fatiado em segmentos por dia UTC.
 *      A câmera responde de forma assíncrona via /pushresourcelist.
 *   2. [Extrair] → proNo 37382 ("FTP file upload command") com a janela exata
 *      da gravação; a CÂMERA sobe o arquivo por FTP para o destino configurado
 *      em VIDEO_FTP_* e o IoTHub avisa por /pushftpfileupload → media_files.
 *
 * Fluxo JIMI (JC400D/AD) — a câmera é quem fala, e em hora LOCAL:
 *   1. [Requisitar] → `FILELIST,<url>` (grava o endereço) **e** `FILELIST` nu
 *      (dispara). São dois comandos: o primeiro sozinho não sobe nada. A
 *      câmera então faz POST da lista INTEIRA do cartão em /filelist/{imei},
 *      que a interpreta para `resource_lists` (v4.9.34). Não há janela de
 *      datas no comando — o filtro da tela vale só na exibição.
 *   2. [Extrair] → `HVIDEO,<carimbo>,<câmera>` (proNo 128), montado a partir do
 *      nome que veio na lista. 🔴 NÃO é o 37382: aquele é JT/T, e mandá-lo para
 *      uma câmera JIMI dá "enviado com sucesso" e arquivo nenhum.
 *
 * Em ambos: item "no cartão" vira "disponível" quando o arquivo chega, e aí
 * toca inline / baixa.
 *
 * ⚠️ O instante de um arquivo é o carimbo do NOME quando ele tem um — ver a
 * nota na unificação, mais abaixo. `event_time` de um bloco extraído por
 * `HVIDEO` é a hora em que o UPLOAD terminou.
 *
 * ⚠️ Até a v4.9.0 o [Extrair] do JT/T mandava **34818**, que a doc chama de
 * "Multimedia data retrieval" — uma CONSULTA da família de fotos do JT/T 808. O
 * IoTHub aceitava, marcava `executed`, e arquivo nenhum aparecia: em três dias
 * de homologação, /pushfileupload e /pushftpfileupload não foram chamados uma
 * vez. O mesmo erro, no dialeto errado, é o que o [Extrair] fazia com as JIMI.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/filelist.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();

// Mídia servida por /midia, na NOSSA origem — não mais direto pelo
// FILE_STORAGE_URL (porta 23010). Aquele endpoint não manda CORS (e o player
// de MPEG-TS precisa buscar os bytes por `fetch`), não aceita Range (sem o
// qual não há como arrastar a barra) e ainda responde
// `Content-Disposition: attachment`. Ver o cabeçalho de handlers/midia.php.
$fileStorageUrl = '/midia?f=';

// ── Escopo multi-tenant (v4.9.23) ──────────────────────────────────────────
// Antes a tela era presa ao cliente da sessão. O `?customer_id` passa por
// `report_customer_scope()`: para quem não é admin o parâmetro é ignorado, não
// validado (CLAUDE.md).
$user        = get_jimi_user();
$isAdmin     = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$filtroCust  = $_GET['customer_id'] ?? null;
$scopeCust   = report_customer_scope($filtroCust, $isAdmin, $customerId);
$customers   = $isAdmin ? report_customer_options($db) : [];
$scopeSql    = $scopeCust !== null ? ' AND d.customer_id = :cid' : '';
$scopeParams = $scopeCust !== null ? [':cid' => $scopeCust] : [];
$mostrarCliente = ($scopeCust === null);

// 🔴 `is_active = 1` pelo mesmo motivo do vídeo ao vivo: playback também PEDE
// gravação ao equipamento, então depende dele responder. Equipamento dado baixa
// no cadastro não deve aparecer aqui.
$devices = $db->prepare("
    SELECT d.imei, d.device_name, dm.model_name, dm.protocol,
           COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) AS camera_count,
           COALESCE(cu.name, '—') AS customer_name
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN customers cu ON cu.id = d.customer_id
    WHERE d.is_active = 1 {$scopeSql}
    ORDER BY cu.name, d.device_name ASC
");
$devices->execute($scopeParams);
$devices = $devices->fetchAll();

// IMEI do GET só vale se pertencer ao cliente da sessão (multi-tenant)
$selImei   = $_GET['imei'] ?? '';
$selDevice = null;
foreach ($devices as $d) {
    if ($d['imei'] === $selImei) { $selDevice = $d; break; }
}
if (!$selDevice && !empty($devices)) { $selDevice = $devices[0]; $selImei = $selDevice['imei']; }
if (!$selDevice) $selImei = '';

$selCam      = $selDevice ? max(1, (int)($selDevice['camera_count'] ?? 1)) : 1;
$selProtocol = $selDevice['protocol'] ?? 'JTT';
$selChannel  = (int)($_GET['channel'] ?? 1);
if ($selChannel < 1 || $selChannel > $selCam) $selChannel = 1;
$dateFrom   = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 day'));
$dateTo     = $_GET['date_to'] ?? date('Y-m-d');
$requested  = !empty($_GET['request']);

/**
 * Teto de gravações "no cartão" exibidas de uma vez.
 *
 * Era 300, herdado do JT/T. Uma câmera JIMI grava um bloco por minuto: 300
 * itens são 5 h de um canal, e o filtro padrão da tela pede DOIS DIAS. 500
 * cobrem um turno inteiro (8 h 20 de gravação contínua) e mantêm a página em
 * ~600 KB — no teto, cada item custa ~1,2 KB de HTML.
 */
const PB_LIMITE_CARTAO = 500;

$sessoes    = [];    // canal => sessões (só para o resumo do cabeçalho)
$pbBlocos   = [];    // [inicio, duração, canal] — o que o navegador desenha
$pbArquivos = [];    // o que já está no servidor, para marcar sobre a barra
$barraIni   = null;  // janela pedida, em epoch UTC
$barraFim   = null;
// Defaults ANTES do bloco condicional: o template renderiza sob `$requested`,
// mas a consulta só roda com `$requested && $selImei`. Sem isto, pedir a tela
// sem equipamento selecionado usaria variável indefinida.
$ttl         = resource_list_ttl_minutes();
$capturaInfo = ['ultima' => null, 'minutos' => null];
if ($requested && $selImei) {
    // Dias digitados são BRT; colunas do banco são UTC
    list($utcFrom, $utcTo) = brt_day_range_to_utc($dateFrom, $dateTo);
    $utcTz = new DateTimeZone('UTC');
    $toTs = function ($s) use ($utcTz) {
        if (!$s) return null;
        $dt = date_create($s, $utcTz);
        return $dt ? $dt->getTimestamp() : null;
    };
    $barraIni = $toTs($utcFrom);
    $barraFim = $toTs($utcTo);

    // ── 1) Tudo que a câmera reportou no cartão, TODOS OS CANAIS ────────────
    //
    // 🔴 SEM FILTRO DE CANAL E SEM TETO (v4.9.37). A resposta do equipamento
    // sempre traz os dois canais — não havia sentido em pedir um: o canal era
    // um filtro de EXIBIÇÃO disfarçado de parâmetro de requisição, e obrigava
    // a consultar duas vezes o que veio de uma vez só.
    //
    // O teto de itens também sumiu, e por uma razão de arquitetura: quem
    // desenha e quem lista agora é o NAVEGADOR, a partir destes blocos, e ele
    // só materializa a janela de zoom visível. 2.795 blocos são ~50 KB de JSON
    // — menos que a página de 500 linhas que isto substitui.
    //
    // ⚠️ A validade de 30 min continua valendo (v4.9.17): o cartão é buffer
    // circular e a lista é um retrato com hora.
    $stmtBloc = $db->prepare("
        SELECT channel_id, start_time, end_time, file_name, file_size
        FROM resource_lists
        WHERE imei = :imei
          AND start_time <= :dt
          AND COALESCE(end_time, start_time) >= :df
          AND captured_at IS NOT NULL
          AND captured_at >= (NOW() - INTERVAL {$ttl} MINUTE)
        ORDER BY start_time
    ");
    $stmtBloc->execute([':imei' => $selImei, ':df' => $utcFrom, ':dt' => $utcTo]);
    $blocos = $stmtBloc->fetchAll();

    // Resumo do cabeçalho. A agregação em sessões existe nos DOIS lados — aqui
    // para o número que a tela mostra sem depender de JS, e no navegador para
    // redesenhar a cada zoom. A REGRA (a folga) tem uma casa só: a constante
    // abaixo viaja para o JS, então não há dois limiares para divergirem.
    $sessoes = filelist_sessoes($blocos);

    // ── 2) O que já está no servidor ────────────────────────────────────────
    //
    // ⚠️ Janela ALARGADA de propósito: `event_time` de um bloco trazido por
    // `HVIDEO` é a hora em que o UPLOAD terminou, não a da gravação — extrair
    // uma gravação de ontem produz arquivo carimbado hoje. O instante REAL sai
    // do NOME, logo abaixo, e o que cair fora da janela pedida é descartado.
    $margem = 2 * 86400;
    $stmtMedia = $db->prepare("
        SELECT id, file_name, file_url, file_type, file_size, event_time, channel,
               download_status, downloaded_at, created_at
        FROM media_files
        WHERE imei = :imei
          AND event_time BETWEEN :df AND :dt
        ORDER BY event_time DESC
        LIMIT 800
    ");
    $stmtMedia->execute([
        ':imei' => $selImei,
        ':df'   => gmdate('Y-m-d H:i:s', strtotime($utcFrom . ' UTC') - $margem),
        ':dt'   => gmdate('Y-m-d H:i:s', strtotime($utcTo . ' UTC') + $margem),
    ]);
    $mediaFiles = $stmtMedia->fetchAll();

    // ── 3) Blocos e arquivos, prontos para o navegador ──────────────────────
    //
    // Formato deliberadamente curto: `[inicio, duracao, canal]`. Com milhares
    // de blocos, nomes de chave repetidos por linha custariam mais que o dado.
    // O nome do arquivo no cartão é RECONSTRUÍDO no cliente a partir do
    // instante e do canal (é sempre `AAAA_MM_DD_HH_MM_SS_0C.ts`, hora local da
    // câmera) — ver pbNomeDoBloco().
    foreach ($blocos as $b) {
        $ini = $toTs($b['start_time']);
        if ($ini === null) continue;
        $fim = $toTs($b['end_time']) ?: ($ini + FILELIST_BLOCO_SEGUNDOS);
        $pbBlocos[] = [$ini, max(1, $fim - $ini), (int)$b['channel_id']];
    }

    foreach ($mediaFiles as $m) {
        $doNome = filelist_ts_do_nome_utc((string)$m['file_name']);
        $t = $toTs($doNome ?: ($m['event_time'] ?: $m['created_at']));
        if ($t === null || $t < $barraIni - $margem || $t > $barraFim + $margem) continue;
        $pbArquivos[] = [
            't'   => $t,
            'c'   => (int)($m['channel'] ?: 0),
            'u'   => (string)$m['file_url'],
            'n'   => (string)$m['file_name'],
            'mb'  => $m['file_size'] ? round($m['file_size'] / 1048576, 1) : 0,
            'st'  => (string)$m['download_status'],
            'dl'  => $m['downloaded_at'] ? 1 : 0,
            'tp'  => (string)$m['file_type'],
        ];
    }

    // Idade da ÚLTIMA listagem deste equipamento, mesmo vencida — é o que
    // permite distinguir "nunca listado" de "listado há 3 h", que para o
    // operador são situações completamente diferentes.
    $stmtCap = $db->prepare("
        SELECT MAX(captured_at) AS ultima,
               TIMESTAMPDIFF(MINUTE, MAX(captured_at), NOW()) AS minutos
          FROM resource_lists WHERE imei = :imei
    ");
    $stmtCap->execute([':imei' => $selImei]);
    $capturaInfo = $stmtCap->fetch(PDO::FETCH_ASSOC) ?: ['ultima' => null, 'minutos' => null];
}

$page_title = 'Vídeo Playback';
$current_route = 'video_playback';
$streamCfg = video_stream_config();

// mpegts.js: as gravações do cartão são MPEG-TS (.ts), e NENHUM browser toca
// isso no <video> nativo — Chrome, Firefox e Safari só decodificam MP4/WebM.
// Sem esta biblioteca o player recebia a URL, não reclamava e ficava preto.
// Ela remuxa TS→fMP4 em JavaScript e entrega por Media Source Extensions.
// Vem de CDN como o Leaflet e o Chart.js do resto do sistema (o projeto não
// tem build step — ver CLAUDE.md).
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/mpegts.js@1.7.3/dist/mpegts.js"></script>
<style>
.vid-bg{background:#0a0b0d;border-radius:var(--radius-lg);overflow:hidden;min-height:360px;display:flex;align-items:center;justify-content:center;}
.vid-bg video{width:100%;display:block;max-height:460px;}

/* ── Item da linha do tempo ──────────────────────────────────────────────
   🔴 `min-width:0` + `overflow:hidden` no .tl-meta é a correção de um defeito
   real: sem eles, um texto `nowrap` transborda do flex e é pintado POR BAIXO
   do botão. Quem encolhe é o texto; a hora e a ação nunca (`flex:0 0 auto`) —
   a chave de leitura e a ação não somem para caber descrição. */
.timeline-item{padding:9px 14px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:10px;transition:background .1s;}
.timeline-item.clicavel{cursor:pointer;}
.timeline-item:hover{background:var(--canvas-soft);}
.timeline-item.selected{background:var(--primary-soft);box-shadow:inset 3px 0 0 var(--primary);}
.timeline-item.alvo{outline:2px solid var(--primary);outline-offset:-2px;}
.timeline-dot{width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0;}
.timeline-dot.on-device{background:var(--muted-soft);}
.tl-hora{flex:0 0 auto;font-family:"JetBrains Mono",monospace;font-size:12.5px;color:var(--ink);
         font-variant-numeric:tabular-nums;letter-spacing:-.2px;}
.tl-canal{flex:0 0 auto;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.3px;}
.tl-meta{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
         font-size:11px;color:var(--muted);}
.tl-dia{position:sticky;top:0;z-index:1;background:var(--canvas);padding:6px 14px 4px;
        font-size:10px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;
        color:var(--muted);border-bottom:1px solid var(--hairline);}
.pb-badge{flex:0 0 auto;font-size:10px;font-weight:600;text-transform:uppercase;padding:2px 8px;border-radius:100px;white-space:nowrap;}
.pb-badge.available{background:var(--primary-soft);color:var(--primary);}
.pb-badge.baixado{background:#e6f4ea;color:#0f7b3d;}
.pb-extract{flex:0 0 auto;font-size:11px;padding:4px 10px;white-space:nowrap;}

/* ── Barra do período, com zoom ───────────────────────────────────────────
   Desenhada pelo NAVEGADOR: o zoom precisa redesenhar a cada gesto, e ir ao
   servidor a cada passo tornaria a interação inutilizável. */
.pb-barra{position:relative;}
.pb-barra svg{display:block;width:100%;cursor:crosshair;touch-action:none;user-select:none;}
.pb-barra svg.arrastando{cursor:grabbing;}
.pb-trilho{fill:var(--canvas-soft);stroke:var(--hairline);stroke-width:.6;}
.pb-sessao{fill:var(--primary);fill-opacity:.68;cursor:pointer;}
.pb-sessao:hover{fill-opacity:1;}
.pb-bloco{fill:var(--primary);fill-opacity:.8;cursor:pointer;}
.pb-bloco:hover{fill-opacity:1;}
.pb-bloco.tem-arquivo{fill:#0f9d58;}
.pb-bloco.alvo,.pb-sessao.alvo{stroke:var(--ink);stroke-width:1.4;}
.pb-grid{stroke:var(--hairline);stroke-width:.6;stroke-dasharray:2 3;}
.pb-eixo{font-family:"JetBrains Mono",monospace;font-size:9px;fill:var(--muted);}
.pb-canal{font-size:9.5px;fill:var(--muted);}
.pb-zoom{display:flex;align-items:center;gap:6px;}
.pb-zoom button{font-size:11px;padding:3px 9px;line-height:1.4;}
.pb-dica{position:absolute;pointer-events:none;z-index:20;background:var(--ink);color:#fff;
         font-size:11px;line-height:1.5;padding:6px 9px;border-radius:6px;white-space:nowrap;
         box-shadow:0 4px 14px rgba(0,0,0,.22);opacity:0;transition:opacity .08s;}
.pb-dica.on{opacity:1;}
.pb-dica b{font-family:"JetBrains Mono",monospace;font-weight:600;}
.pb-dica i{display:block;font-style:normal;opacity:.7;margin-top:2px;}
/* `fixed`, não `absolute`: o popover é aberto tanto pela barra quanto pela
   lista, que ficam em colunas diferentes — âncora em coordenadas de VIEWPORT
   é a única que vale nos dois lugares. Com `absolute` ele se posicionava
   contra um offsetParent que não era a barra, e aparecia no canto da tela. */
.pb-pop{position:fixed;z-index:30;background:var(--canvas);border:1px solid var(--hairline);
        border-radius:var(--radius-sm);box-shadow:0 8px 26px rgba(0,0,0,.16);padding:10px;min-width:220px;}
.pb-pop h4{margin:0 0 2px;font-size:12px;font-weight:600;color:var(--ink);}
.pb-pop .q{font-family:"JetBrains Mono",monospace;font-size:11px;color:var(--muted);margin-bottom:8px;}
.pb-pop button{display:block;width:100%;margin-top:5px;font-size:11.5px;padding:6px 10px;text-align:left;}
.pb-pop .fechar{position:absolute;top:4px;right:6px;width:auto;margin:0;padding:2px 6px;
                background:none;border:0;color:var(--muted);cursor:pointer;font-size:14px;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;">
    <!-- Player / Preview -->
    <div>
        <div class="vid-bg" id="vid-container">
            <div id="vid-placeholder" style="text-align:center;color:var(--muted-soft);">
                <i style="font-size:48px;display:block;margin-bottom:10px;opacity:.2;">&#9654;</i>
                Selecione o equipamento e o período e clique em Requisitar
            </div>
            <video id="vid-player" controls playsinline style="display:none;width:100%;max-height:460px;"></video>
        </div>
        <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <div id="pb-fonte" style="font-size:11px;color:var(--muted);"></div>
            <a id="pb-download" class="btn btn-outline btn-sm" style="display:none;" target="_blank" download>
                &#8681; Baixar arquivo
            </a>
        </div>

        <?php if ($requested && $barraIni !== null && $barraFim > $barraIni): ?>
        <?php
        // ═══ Barra do período com ZOOM (v4.9.37) ══════════════════════════
        //
        // 🔴 POR QUE ELA É O CONTROLE PRINCIPAL. O cartão é picado em blocos de
        // um minuto: 2.795 numa câmera, 3,7 dias. Como lista são milhares de
        // linhas idênticas; como informação são ~47 sessões por canal, e dois
        // terços do período simplesmente NÃO existem no cartão. A barra
        // responde de relance a pergunta que a lista não responde — "a câmera
        // estava gravando às 14h de terça?" — e o zoom leva do panorama até o
        // minuto exato, que é a unidade que o equipamento sabe entregar.
        //
        // ⚠️ O CANAL SAIU DA REQUISIÇÃO. A resposta do equipamento sempre traz
        // os dois; pedir um era filtrar exibição fingindo ser parâmetro. Agora
        // se escolhe o canal CLICANDO na faixa dele.
        ?>
        <div class="card pb-barra" id="pb-barra" style="margin-top:12px;padding:12px 14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
                <div style="font-size:12px;font-weight:600;color:var(--ink);">
                    Gravações no cartão
                    <span id="pb-resumo" style="font-weight:400;color:var(--muted);margin-left:6px;"></span>
                </div>
                <div class="pb-zoom">
                    <span id="pb-vista" style="font-size:10px;color:var(--muted);font-family:'JetBrains Mono',monospace;"></span>
                    <button type="button" class="btn btn-outline btn-sm" onclick="pbZoom(1/1.8)" title="Afastar">&minus;</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="pbZoom(1.8)" title="Aproximar">+</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="pbTudo()">Tudo</button>
                </div>
            </div>
            <svg id="pb-svg" role="img" aria-label="Linha do tempo das gravações do cartão, por canal"></svg>
            <div class="pb-dica" id="pb-dica"></div>
            <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:10px;color:var(--muted);margin-top:6px;">
                <span><span class="pb-leg" style="display:inline-block;width:9px;height:9px;border-radius:2px;background:var(--primary);opacity:.68;vertical-align:-1px;margin-right:3px;"></span> no cartão</span>
                <span><span class="pb-leg" style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#0f9d58;vertical-align:-1px;margin-right:3px;"></span> já no servidor</span>
                <span>roda do mouse aproxima · arraste para deslocar · clique para agir</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filtros + lista -->
    <div>
        <div class="card" style="margin-bottom:12px;padding:14px 16px;">
            <form method="GET" id="playback-form" style="display:flex;flex-direction:column;gap:10px;" onsubmit="return onSubmitRequest(event)">
                <?php if ($isAdmin): ?>
                <div>
                    <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
                    <?php /* Trocar de cliente recarrega sem `imei`: o equipamento
                             da carteira anterior não existe na nova, e mantê-lo
                             deixaria o formulário apontando para um device que a
                             lista não oferece mais. */ ?>
                    <select id="pb-cust" class="filtro-campo" style="width:100%"
                            onchange="location.href='?customer_id='+this.value">
                        <option value="">Todos os clientes</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (string)$scopeCust === (string)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div>
                    <?php /* O modelo fica junto da placa de propósito: é ele que
                             decide o protocolo, e portanto quais comandos a tela
                             vai despachar para este veículo. */ ?>
                    <label class="filtro-rotulo" for="pb-imei">Placa</label>
                    <select name="imei" id="pb-imei" class="filtro-campo" style="width:100%">
                        <?php foreach ($devices as $d): ?>
                        <option value="<?= $d['imei'] ?>" data-cam="<?= $d['camera_count']??1 ?>" data-proto="<?= htmlspecialchars($d['protocol'] ?? 'JTT') ?>" <?= $selImei===$d['imei']?'selected':'' ?>>
                            <?= $mostrarCliente ? htmlspecialchars($d['customer_name']) . ' · ' : '' ?><?= htmlspecialchars($d['device_name'] ?: '(sem placa) ' . $d['imei']) ?> (<?= htmlspecialchars($d['model_name']??'?') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($isAdmin): ?>
                <?php /* O form é GET: sem este hidden, submeter o filtro de
                         playback perderia o cliente escolhido e a tela voltaria
                         para "todos" a cada consulta. */ ?>
                <input type="hidden" name="customer_id" value="<?= $scopeCust !== null ? (int)$scopeCust : '' ?>">
                <?php endif; ?>

                <?php /* ⚠️ NÃO HÁ SELETOR DE CANAL. A requisição é uma só e traz
                         todos os canais; escolher canal é clicar na faixa da
                         barra. Ver o cabeçalho da barra. */ ?>

                <div style="display:flex;gap:6px;">
                    <div style="flex:1;">
                        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">De</label>
                        <input type="date" name="date_from" class="filtro-campo" style="width:100%" value="<?= htmlspecialchars($dateFrom) ?>">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Até</label>
                        <input type="date" name="date_to" class="filtro-campo" style="width:100%" value="<?= htmlspecialchars($dateTo) ?>">
                    </div>
                </div>

                <button type="submit" name="request" value="1" class="btn btn-primary btn-sm" style="width:100%;">
                    &#128269; Requisitar Gravações
                </button>
            </form>
        </div>

        <?php if ($requested): ?>
        <div class="card" style="max-height:calc(100vh - 300px);overflow-y:auto;" id="pb-lista-card">
            <div style="font-size:12px;font-weight:600;color:var(--ink);padding-bottom:8px;border-bottom:1px solid var(--hairline);margin-bottom:8px;">
                <span id="pb-lista-titulo">Gravações</span>
            </div>

            <?php
            // ── Idade da listagem (v4.9.17) ─────────────────────────────────
            // O cartão é buffer circular; a lista é um RETRATO com validade
            // curta. Dizer a idade é o que separa "informação velha rotulada"
            // (útil) de "informação velha disfarçada de atual" (armadilha).
            $capMin     = $capturaInfo['minutos'] ?? null;
            $capVencida = $capturaInfo['ultima'] !== null && $capMin !== null && $capMin > $ttl;
            ?>
            <?php if ($capturaInfo['ultima'] === null): ?>
                <div class="callout info" style="font-size:11px;margin-bottom:8px">
                    Este equipamento <strong>nunca teve o cartão listado</strong>.
                    Clique em <strong>Requisitar Gravações</strong> — a câmera responde em alguns segundos.
                </div>
            <?php elseif ($capVencida): ?>
                <?php /* 🔴 É AQUI QUE "só aparecem alarmes" NASCE. Vencida a
                         listagem, a gravação CONTÍNUA some da tela e sobram só
                         os vídeos de evento, que não têm validade. O aviso
                         precisa dizer isso com todas as letras — antes ele
                         falava de "download de arquivo sobrescrito", que é
                         verdade mas não explica o buraco na tela. */ ?>
                <div class="callout" style="font-size:11px;margin-bottom:8px;background:#fdf6e3;border-left:3px solid #b45309;color:#7c4a03">
                    A listagem do cartão foi feita
                    <strong>há <?= $capMin >= 1440 ? intdiv($capMin,1440).' dia(s)' : ($capMin >= 60 ? intdiv($capMin,60).' h' : $capMin.' min') ?></strong>
                    e <strong>venceu</strong> (validade: <?= (int)$ttl ?> min).
                    Sem ela a <strong>gravação contínua não aparece</strong> — o que sobra na tela
                    são só os vídeos de evento, que ficam no servidor.
                    <strong>Requisite novamente</strong> para ver o cartão inteiro.
                </div>
            <?php else: ?>
                <div style="font-size:11px;color:var(--muted);margin-bottom:8px">
                    Listagem de <strong><?= $capMin < 1 ? 'agora' : 'há ' . (int)$capMin . ' min' ?></strong>
                    · vence em <?= max(0, $ttl - (int)$capMin) ?> min
                </div>
            <?php endif; ?>

            <div id="pb-lista"></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="pb-pop" id="pb-pop" style="display:none;"></div>

<script>
var fileStorageUrl = <?= json_encode($fileStorageUrl) ?>;
var selImei     = <?= json_encode($selImei) ?>;
var selProtocol = <?= json_encode($selProtocol) ?>;
var selCam      = <?= (int)$selCam ?>;
// Base do FILELIST (JIMI): montada no SERVIDOR, porque depende de VIDEO_INGEST_IP
// — o endereço que o EQUIPAMENTO alcança, que não é o mesmo que o navegador usa.
var filelistBase = <?= json_encode(filelist_url_base()) ?>;
var streamBase   = <?= json_encode($streamCfg['flv_base']) ?>;
var ingestIp     = <?= json_encode($streamCfg['ingest_ip']) ?>;
var playbackPort = <?= (int)($streamCfg['playback_port'] ?? 0) ?>;

/**
 * Estado da barra. `blocos` são triplas [início, duração, canal] em epoch UTC;
 * `arquivos`, o que já está no servidor.
 *
 * ⚠️ `gap` e `bloco` VÊM DO PHP. A agregação em sessões existe nos dois lados
 * (servidor para o resumo, navegador para redesenhar a cada zoom), e duas
 * cópias de um algoritmo com dois limiares diferentes divergem em silêncio.
 * O algoritmo se repete; a REGRA tem uma casa só — `includes/filelist.php`.
 */
var PB = {
    blocos:   <?= json_encode($pbBlocos) ?>,
    arquivos: <?= json_encode($pbArquivos) ?>,
    janela:   [<?= (int)$barraIni ?>, <?= (int)$barraFim ?>],
    vista:    [<?= (int)$barraIni ?>, <?= (int)$barraFim ?>],
    gap:      <?= FILELIST_SESSAO_GAP_SEGUNDOS ?>,
    bloco:    <?= FILELIST_BLOCO_SEGUNDOS ?>,
    offset:   <?= FILELIST_OFFSET_SEGUNDOS ?>,
    canais:   [],
    alvo:     null
};

// ── Hora local da CÂMERA ────────────────────────────────────────────────────
//
// 🔴 O equipamento nomeia o arquivo pelo relógio DELE (UTC−3), não em GMT 0 —
// medido em 29 de 29 amostras. Todo carimbo que volta para o device (`HVIDEO`,
// `REPLAYLIST`) tem de sair daqui, e não de uma conversão de fuso do navegador,
// que depende do relógio de quem está olhando a tela.
function pbLocal(t) { return new Date((t - PB.offset) * 1000); }
function pb2(n) { return (n < 10 ? '0' : '') + n; }
function pbHora(t) { var d = pbLocal(t); return pb2(d.getUTCHours()) + ':' + pb2(d.getUTCMinutes()) + ':' + pb2(d.getUTCSeconds()); }
function pbData(t) { var d = pbLocal(t); return pb2(d.getUTCDate()) + '/' + pb2(d.getUTCMonth() + 1) + '/' + d.getUTCFullYear(); }
function pbDataCurta(t) { var d = pbLocal(t); return pb2(d.getUTCDate()) + '/' + pb2(d.getUTCMonth() + 1); }

/** Carimbo que o equipamento entende: `AAAA_MM_DD_HH_MM_SS`. */
function pbCarimbo(t) {
    var d = pbLocal(t);
    return d.getUTCFullYear() + '_' + pb2(d.getUTCMonth() + 1) + '_' + pb2(d.getUTCDate()) + '_'
         + pb2(d.getUTCHours()) + '_' + pb2(d.getUTCMinutes()) + '_' + pb2(d.getUTCSeconds());
}
/** Nome do arquivo no cartão, como a câmera o listou. */
function pbNomeBloco(t, canal) { return pbCarimbo(t) + '_0' + canal + '.ts'; }

function pbDur(s) {
    s = Math.round(s);   // o zoom produz fração de segundo; ninguém lê "2,287 s"
    if (s >= 3600) return Math.floor(s / 3600) + ' h ' + pb2(Math.floor((s % 3600) / 60)) + ' min';
    if (s >= 60) { var m = Math.floor(s / 60), r = s % 60; return m + ' min' + (r ? ' ' + r + ' s' : ''); }
    return s + ' s';
}

/**
 * Funde blocos contíguos em SESSÕES, por canal — o mesmo critério do
 * `filelist_sessoes()` do PHP.
 */
function pbSessoes(blocos, gap) {
    var porCanal = {}, i, b;
    for (i = 0; i < blocos.length; i++) {
        b = blocos[i];
        (porCanal[b[2]] = porCanal[b[2]] || []).push([b[0], b[0] + b[1]]);
    }
    var saida = {};
    Object.keys(porCanal).forEach(function (c) {
        var l = porCanal[c].sort(function (x, y) { return x[0] - y[0]; });
        var s = [], ini = l[0][0], fim = l[0][1], n = 1;
        for (var j = 1; j < l.length; j++) {
            if (l[j][0] - fim > gap) { s.push({ ini: ini, fim: fim, n: n }); ini = l[j][0]; fim = l[j][1]; n = 1; continue; }
            fim = Math.max(fim, l[j][1]); n++;
        }
        s.push({ ini: ini, fim: fim, n: n });
        saida[c] = s;
    });
    return saida;
}

/** Arquivo já no servidor cujo instante cai DENTRO deste bloco. */
function pbArquivoDoBloco(t, dur, canal) {
    for (var i = 0; i < PB.arquivos.length; i++) {
        var a = PB.arquivos[i];
        if (a.c && canal && a.c !== canal) continue;
        if (a.t >= t && a.t < t + dur) return a;
    }
    return null;
}

// ── Desenho ─────────────────────────────────────────────────────────────────
var PB_LINHA = 26, PB_GAPL = 8, PB_TOPO = 22, PB_ESQ = 58, PB_DIR = 8;

function pbDesenhar() {
    var svg = document.getElementById('pb-svg');
    if (!svg) return;
    var L = svg.clientWidth || 900;
    var canais = PB.canais;
    var alt = PB_TOPO + canais.length * (PB_LINHA + PB_GAPL) + 22;
    svg.setAttribute('viewBox', '0 0 ' + L + ' ' + alt);
    svg.setAttribute('height', alt);

    var t0 = PB.vista[0], t1 = PB.vista[1], span = Math.max(1, t1 - t0);
    var larg = L - PB_ESQ - PB_DIR;
    var pps = larg / span;                     // pixels por segundo
    var x = function (t) { return PB_ESQ + (t - t0) * pps; };
    // Um bloco visível o bastante para ser clicado individualmente? É isto que
    // decide entre panorama (sessões) e detalhe (blocos) — o zoom "chega ao
    // intervalo de 1 vídeo" exatamente aqui.
    var modoBloco = PB.bloco * pps >= 3;
    var partes = [];

    // Grade: dias ou horas, conforme a amplitude
    var passo = span > 3 * 86400 ? 86400 : (span > 8 * 3600 ? 3600 * 6 : (span > 3600 ? 3600 : 600));
    var marca = Math.ceil((t0 - PB.offset) / passo) * passo + PB.offset;
    for (var g = marca; g <= t1 && partes.length < 400; g += passo) {
        var gx = x(g).toFixed(1);
        var rot = passo >= 86400 ? pbDataCurta(g) : pbHora(g).slice(0, 5);
        partes.push('<line class="pb-grid" x1="' + gx + '" y1="' + (PB_TOPO - 10) + '" x2="' + gx + '" y2="' + (alt - 20) + '"/>');
        partes.push('<text class="pb-eixo" x="' + (x(g) + 3).toFixed(1) + '" y="' + (PB_TOPO - 4) + '">' + rot + '</text>');
    }

    var sessoes = modoBloco ? null : pbSessoes(PB.blocos, PB.gap);

    canais.forEach(function (canal, i) {
        var y = PB_TOPO + i * (PB_LINHA + PB_GAPL);
        var rotulo = canal === 1 ? 'CH1 frontal' : (canal === 2 ? 'CH2 interna' : 'CH' + canal);
        partes.push('<text class="pb-canal" x="0" y="' + (y + 17) + '">' + rotulo + '</text>');
        partes.push('<rect class="pb-trilho" x="' + PB_ESQ + '" y="' + y + '" width="' + larg + '" height="' + PB_LINHA + '" rx="3"/>');

        if (modoBloco) {
            for (var k = 0; k < PB.blocos.length; k++) {
                var b = PB.blocos[k];
                if (b[2] !== canal || b[0] + b[1] < t0 || b[0] > t1) continue;
                var bx = x(b[0]), bw = Math.max(1.5, b[1] * pps);
                var arq = pbArquivoDoBloco(b[0], b[1], canal);
                partes.push('<rect class="pb-bloco' + (arq ? ' tem-arquivo' : '')
                    + (PB.alvo === b[0] + ':' + canal ? ' alvo' : '') + '" x="' + bx.toFixed(2) + '" y="' + (y + 3)
                    + '" width="' + bw.toFixed(2) + '" height="' + (PB_LINHA - 6)
                    + '" rx="1.5" data-t="' + b[0] + '" data-d="' + b[1] + '" data-c="' + canal + '"/>');
            }
        } else {
            (sessoes[canal] || []).forEach(function (s) {
                if (s.fim < t0 || s.ini > t1) return;
                var sx = x(s.ini), sw = Math.max(1.6, (s.fim - s.ini) * pps);
                partes.push('<rect class="pb-sessao" x="' + sx.toFixed(2) + '" y="' + (y + 3)
                    + '" width="' + sw.toFixed(2) + '" height="' + (PB_LINHA - 6)
                    + '" rx="1.5" data-t="' + s.ini + '" data-d="' + (s.fim - s.ini) + '" data-c="' + canal + '" data-n="' + s.n + '"/>');
            });
        }
        // Marca do que já está no servidor, quando os blocos não são visíveis
        if (!modoBloco) {
            PB.arquivos.forEach(function (a) {
                if ((a.c || canal) !== canal || a.t < t0 || a.t > t1) return;
                var ax = x(a.t);
                partes.push('<polygon class="pb-baixado" points="' + (ax - 3.5) + ',' + (y - 6) + ' '
                    + (ax + 3.5) + ',' + (y - 6) + ' ' + ax + ',' + (y + 1) + '" style="fill:#0f9d58"/>');
            });
        }
    });

    partes.push('<text class="pb-eixo" x="' + PB_ESQ + '" y="' + (alt - 6) + '">' + pbDataCurta(t0) + ' ' + pbHora(t0).slice(0, 5) + '</text>');
    partes.push('<text class="pb-eixo" text-anchor="end" x="' + (L - PB_DIR) + '" y="' + (alt - 6) + '">' + pbDataCurta(t1) + ' ' + pbHora(t1).slice(0, 5) + '</text>');
    svg.innerHTML = partes.join('');

    var el = document.getElementById('pb-vista');
    if (el) el.textContent = pbDur(span) + (modoBloco ? ' · blocos' : ' · sessões');
    pbListar();
}

/** Cabeçalho: o que existe no período pedido, não no zoom. */
function pbResumo() {
    var s = pbSessoes(PB.blocos, PB.gap), n = 0, seg = 0;
    Object.keys(s).forEach(function (c) {
        n += s[c].length;
        s[c].forEach(function (x) { seg += x.fim - x.ini; });
    });
    var el = document.getElementById('pb-resumo');
    if (!el) return;
    el.textContent = PB.blocos.length
        ? n + ' sessõe' + (n === 1 ? '' : 's') + ' · ' + (seg / 3600).toFixed(1).replace('.', ',') + ' h gravadas'
        : 'nada listado neste período';
}

// ── Zoom e deslocamento ─────────────────────────────────────────────────────
function pbAplicarVista(a, b) {
    var minSpan = PB.bloco * 2;                        // não faz sentido passar do bloco
    var maxSpan = PB.janela[1] - PB.janela[0];
    var span = Math.round(Math.max(minSpan, Math.min(maxSpan, b - a)));
    var meio = (a + b) / 2;
    a = Math.round(meio - span / 2); b = a + span;
    if (a < PB.janela[0]) { a = PB.janela[0]; b = a + span; }
    if (b > PB.janela[1]) { b = PB.janela[1]; a = b - span; }
    PB.vista = [a, b];
    pbDesenhar();
}
function pbZoom(fator, ancora) {
    var a = PB.vista[0], b = PB.vista[1];
    if (ancora === undefined) ancora = (a + b) / 2;
    var novo = (b - a) / fator;
    var p = (ancora - a) / (b - a);
    pbAplicarVista(ancora - novo * p, ancora + novo * (1 - p));
}
function pbTudo() { PB.alvo = null; pbAplicarVista(PB.janela[0], PB.janela[1]); }
/** Centra a vista num instante, preservando a amplitude atual. */
function pbIrPara(t) {
    var meia = (PB.vista[1] - PB.vista[0]) / 2;
    pbAplicarVista(t - meia, t + meia);
}

// ── Lista: espelha a janela de ZOOM ─────────────────────────────────────────
//
// 🔴 É isto que elimina o teto de itens. Antes a tela renderizava as 500 mais
// recentes do período inteiro e avisava que cortou; agora ela mostra o que
// está na vista — e a vista é o usuário quem escolhe. Aproximar É filtrar.
var PB_LISTA_MAX = 300;

function pbListar() {
    var alvo = document.getElementById('pb-lista');
    if (!alvo) return;
    var t0 = PB.vista[0], t1 = PB.vista[1];
    var itens = PB.blocos.filter(function (b) { return b[0] + b[1] >= t0 && b[0] <= t1; })
                         .sort(function (a, b) { return b[0] - a[0]; });
    var total = itens.length;
    var corte = itens.slice(0, PB_LISTA_MAX);

    var tit = document.getElementById('pb-lista-titulo');
    if (tit) {
        tit.textContent = total
            ? total + ' gravaç' + (total === 1 ? 'ão' : 'ões') + ' na vista'
            : (PB.blocos.length ? 'Nada gravado neste trecho' : 'Nada listado');
    }

    // ⚠️ Vazio ACIONÁVEL. Dois terços do período não existem no cartão, então
    // cair num buraco é o caso NORMAL — e "nada aqui" sem saída deixa o usuário
    // arrastando às cegas atrás de gravação.
    if (!total && PB.blocos.length) {
        var meio = (t0 + t1) / 2, perto = null, dist = Infinity;
        PB.blocos.forEach(function (b) {
            var d = Math.abs(b[0] - meio);
            if (d < dist) { dist = d; perto = b; }
        });
        alvo.innerHTML = '<div class="empty-state" style="padding:20px 12px;">'
            + '<p>Nenhuma gravação neste trecho.</p>'
            + '<p style="font-size:11px;margin-top:4px;">O cartão tem buracos — a câmera só grava quando o veículo roda.</p>'
            + (perto ? '<button class="btn btn-outline btn-sm" style="margin-top:10px"'
                     + ' onclick="pbIrPara(' + perto[0] + ')">Ir para a gravação mais próxima ('
                     + pbDataCurta(perto[0]) + ' ' + pbHora(perto[0]).slice(0, 5) + ')</button>' : '')
            + '</div>';
        return;
    }

    var html = [], diaAnt = null;
    corte.forEach(function (b) {
        var dia = pbData(b[0]);
        if (dia !== diaAnt) { diaAnt = dia; html.push('<div class="tl-dia">' + dia + '</div>'); }
        var arq = pbArquivoDoBloco(b[0], b[1], b[2]);
        var meta = [pbDur(b[1])];
        if (arq && arq.mb) meta.push(String(arq.mb).replace('.', ',') + ' MB');
        var badge = '';
        if (arq) {
            badge = arq.dl
                ? '<span class="pb-badge baixado">Baixado</span>'
                : '<span class="pb-badge available">No servidor</span>';
        }
        html.push('<div class="timeline-item' + (arq ? ' clicavel' : '')
            + '" data-ts="' + b[0] + '" data-c="' + b[2] + '"'
            + ' title="' + (arq ? 'Clique para reproduzir · ' + arq.n : 'Gravação no cartão · CH' + b[2]) + '"'
            + (arq ? ' onclick="pbTocar(' + b[0] + ',' + b[2] + ')"' : '') + '>'
            + '<span class="timeline-dot' + (arq ? '' : ' on-device') + '"></span>'
            + '<span class="tl-hora">' + pbHora(b[0]) + '</span>'
            + '<span class="tl-canal">CH' + b[2] + '</span>'
            + '<span class="tl-meta">' + meta.join(' · ') + '</span>'
            + badge
            + (arq ? '' : '<button class="btn btn-outline btn-sm pb-extract" title="O que fazer com este minuto"'
                        + ' onclick="event.stopPropagation();pbAbrirAcoes(' + b[0] + ',' + b[1] + ',' + b[2] + ',this)">&#8942;</button>')
            + '</div>');
    });
    if (total > PB_LISTA_MAX) {
        html.push('<div style="padding:8px 14px;font-size:11px;color:var(--muted)">'
            + 'Mostrando ' + PB_LISTA_MAX + ' de ' + total + ' — <strong>aproxime a barra</strong> para reduzir o trecho.</div>');
    }
    alvo.innerHTML = html.join('');
}

// ── Dica ao passar o mouse ──────────────────────────────────────────────────
function pbDica(ev, alvo) {
    var d = document.getElementById('pb-dica'), barra = document.getElementById('pb-barra');
    if (!d || !barra) return;
    if (!alvo) { d.classList.remove('on'); return; }
    var t = +alvo.getAttribute('data-t'), dur = +alvo.getAttribute('data-d'),
        c = +alvo.getAttribute('data-c'), n = alvo.getAttribute('data-n');
    var arq = n ? null : pbArquivoDoBloco(t, dur, c);
    d.innerHTML = '<b>' + pbHora(t) + ' — ' + pbHora(t + dur) + '</b> · ' + pbDur(dur)
        + '<i>' + pbDataCurta(t) + ' · CH' + c
        + (n ? ' · ' + n + ' bloco' + (n === '1' ? '' : 's') + ' — clique para aproximar'
             : (arq ? ' · já no servidor — clique para reproduzir' : ' — clique para escolher a ação')) + '</i>';
    var r = barra.getBoundingClientRect();
    d.style.left = Math.min(r.width - 230, Math.max(4, ev.clientX - r.left + 12)) + 'px';
    d.style.top  = (ev.clientY - r.top + 14) + 'px';
    d.classList.add('on');
}

// ── Ações de um bloco ───────────────────────────────────────────────────────
//
// 🔴 NADA SOBE PARA O STORAGE SEM PEDIDO EXPLÍCITO. São dois cenários
// diferentes, e o usuário escolhe qual: VER NA CÂMERA transmite direto do
// equipamento e não deixa arquivo nenhum no servidor; SUBIR PARA O STORAGE
// gasta franquia do SIM e cria o arquivo. Por isso o clique abre esta escolha
// em vez de disparar a mais cara delas.
function pbAbrirAcoes(t, dur, canal, ancoraEl) {
    var pop = document.getElementById('pb-pop');
    var arq = pbArquivoDoBloco(t, dur, canal);
    pop.innerHTML =
        '<button class="fechar" onclick="pbFecharAcoes()" aria-label="Fechar">&times;</button>'
        + '<h4>CH' + canal + ' · ' + pbDataCurta(t) + '</h4>'
        + '<div class="q">' + pbHora(t) + ' — ' + pbHora(t + dur) + ' · ' + pbDur(dur) + '</div>'
        + (arq
            ? '<button class="btn btn-primary btn-sm" onclick="pbTocar(' + t + ',' + canal + ');pbFecharAcoes()">&#9654; Reproduzir (já no servidor)</button>'
            : '<button class="btn btn-primary btn-sm" onclick="pbVerNaCamera(' + t + ',' + dur + ',' + canal + ')">&#9654; Ver na câmera <small style="opacity:.75">(não baixa)</small></button>'
              + '<button class="btn btn-outline btn-sm" onclick="pbSubirStorage(' + t + ',' + dur + ',' + canal + ',this)">&#8681; Subir para o storage</button>')
        + '<div style="font-size:10px;color:var(--muted);margin-top:7px;line-height:1.45;">'
        + (arq ? 'Arquivo: ' + arq.n : 'Subir consome franquia do SIM. Ver na câmera não deixa arquivo no servidor.')
        + '</div>';
    // Coordenadas de viewport, presas dentro da janela: o popover nasce colado
    // no que foi clicado, venha da barra ou da lista.
    pop.style.display = 'block';
    var a = ancoraEl.getBoundingClientRect();
    var lp = pop.getBoundingClientRect();
    pop.style.left = Math.max(6, Math.min(window.innerWidth - lp.width - 6, a.left)) + 'px';
    pop.style.top  = Math.max(6, Math.min(window.innerHeight - lp.height - 6, a.bottom + 6)) + 'px';
    PB.alvo = t + ':' + canal;
    pbDesenhar();
}
function pbFecharAcoes() {
    var pop = document.getElementById('pb-pop');
    if (pop) pop.style.display = 'none';
}

/** Reproduz o arquivo que JÁ está no servidor. */
function pbTocar(t, canal) {
    var arq = pbArquivoDoBloco(t, PB.bloco, canal);
    if (!arq) { alert('Este trecho ainda não está no servidor.'); return; }
    selectRecording(null, { file_url: arq.u, file_name: arq.n, file_type: arq.tp });
    var f = document.getElementById('pb-fonte');
    if (f) f.textContent = 'Arquivo do servidor · ' + pbDataCurta(t) + ' ' + pbHora(t) + ' · CH' + canal;
}

// ── Despacho ao equipamento ─────────────────────────────────────────────────
//
// ⚠️ `serverFlagId` é SELETOR DE GATEWAY aqui: 0 = JT/T, 1 = JIMI. Ele sai do
// `data-proto` da opção do equipamento — um IMEI fora da lista cairia no
// fallback e o comando sairia pela porta errada, em silêncio.
function selProtoOf(imei) {
    var sel = document.getElementById('pb-imei');
    for (var i = 0; sel && i < sel.options.length; i++) {
        if (sel.options[i].value === imei) return sel.options[i].dataset.proto || 'JTT';
    }
    return selProtocol || 'JTT';
}

function pbSendCmd(imei, proNo, contentObj, cb) {
    var serverFlagId = (selProtoOf(imei) === 'JIMI') ? 1 : 0;
    fetch('/sendcommand', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
        keepalive: true,
        body: JSON.stringify({
            imei: imei, proNo: proNo, serverFlagId: serverFlagId,
            // Comando de texto (proNo 128, família JIMI) vai CRU. Passá-lo por
            // JSON.stringify mandaria `"FILELIST,..."` com aspas, e a câmera
            // receberia um comando que não existe.
            content: (typeof contentObj === 'string') ? contentObj : JSON.stringify(contentObj)
        })
    }).then(function (r) {
        if (!cb) return;
        return r.json().catch(function () { return {}; }).then(function (j) {
            cb(r.ok && (j.code === 0 || j.code === undefined), j.msg);
        });
    }).catch(function () { if (cb) cb(false, 'Falha de rede ao falar com o servidor.'); });
}

function fmtCompactUTC(d) {
    function p(n) { return String(n).padStart(2, '0'); }
    return String(d.getUTCFullYear()).slice(2) + p(d.getUTCMonth() + 1) + p(d.getUTCDate()) +
           p(d.getUTCHours()) + p(d.getUTCMinutes()) + p(d.getUTCSeconds());
}

/**
 * VER NA CÂMERA — transmite o trecho direto do equipamento, sem gravar nada.
 *
 * JIMI: `REPLAYLIST,<nome>` (planilha A008) empurra o vídeo do cartão para o
 * servidor RTMP — o MESMO caminho do vídeo ao vivo, que já está provado.
 * JT/T: `37377` (0x9201), o playback do JT/T 1078, para o mesmo media server.
 *
 * ⚠️ O CAMINHO DE PUBLICAÇÃO DO PLAYBACK NÃO FOI MEDIDO EM CÂMERA REAL. Para o
 * ao vivo ele é `live/<canal-base-0>/<imei>.flv` (JIMI) e `<canal>/<imei>.flv`
 * (JT/T), medidos em 18/08/2026; supor que o playback publica no mesmo lugar é
 * a hipótese mais provável, não um fato. Por isso existe o timeout abaixo com
 * mensagem explícita: se o stream não vier, a tela DIZ, em vez de deixar um
 * player preto — que é o modo de falhar que este módulo passou semanas caçando.
 */
function pbVerNaCamera(t, dur, canal) {
    pbFecharAcoes();
    var ph = document.getElementById('vid-placeholder');
    var v  = document.getElementById('vid-player');
    var f  = document.getElementById('pb-fonte');
    pbDestroyPlayer();
    v.style.display = 'none';
    ph.style.display = '';
    ph.innerHTML = '<div style="text-align:center;color:var(--muted-soft);padding:16px;">'
        + 'Pedindo o trecho à câmera…<br><span style="font-size:12px;">'
        + pbHora(t) + ' — ' + pbHora(t + dur) + ' · CH' + canal + '</span></div>';
    if (f) f.textContent = 'Transmitindo da câmera · nada é gravado no servidor';

    var ehJimi = selProtoOf(selImei) === 'JIMI';
    var cmd, proNo;
    if (ehJimi) {
        proNo = 128;
        cmd = 'REPLAYLIST,' + pbNomeBloco(t, canal);
    } else {
        proNo = 37377;
        cmd = {
            serverLen: ingestIp.length, serverAddress: ingestIp,
            tcpPort: playbackPort, udpPort: 0,
            channel: canal, channelId: canal,
            resourceType: 0, codeType: 0, storageType: 0,
            playMethod: 0, forwardRewind: 0,
            beginTime: fmtCompactUTC(new Date(t * 1000)),
            endTime:   fmtCompactUTC(new Date((t + dur) * 1000)),
            instructionID: 'pb' + Date.now()
        };
    }

    pbSendCmd(selImei, proNo, cmd, function (ok, msg) {
        if (!ok) {
            ph.innerHTML = '<div style="text-align:center;color:var(--muted-soft);padding:16px;">'
                + 'A câmera recusou o pedido.<br><span style="font-size:12px;">' + (msg || 'sem resposta') + '</span></div>';
            return;
        }
        var url = ehJimi
            ? streamBase + '/live/' + (canal - 1) + '/' + selImei + '.flv'
            : streamBase + '/' + canal + '/' + selImei + '.flv';
        pbAbrirStream(url, ph, v, msg);
    });
}

/** Abre o stream no player, com prazo — sem prazo, falha vira tela preta. */
function pbAbrirStream(url, ph, v, msgDevice) {
    if (!window.mpegts || !mpegts.isSupported()) {
        ph.innerHTML = '<div style="text-align:center;padding:16px;">Este navegador não toca o stream.</div>';
        return;
    }
    var chegou = false;
    pbPlayer = mpegts.createPlayer({ type: 'flv', isLive: true, url: url });
    pbPlayer.attachMediaElement(v);
    pbPlayer.load();
    v.play().catch(function () {});
    v.addEventListener('playing', function () { chegou = true; ph.style.display = 'none'; v.style.display = 'block'; }, { once: true });
    setTimeout(function () {
        if (chegou) return;
        pbDestroyPlayer();
        v.style.display = 'none';
        ph.style.display = '';
        ph.innerHTML = '<div style="text-align:center;color:var(--muted-soft);padding:16px;">'
            + 'A câmera aceitou o comando' + (msgDevice ? ' (“' + msgDevice + '”)' : '')
            + ', mas o stream não chegou em 20 s.<br>'
            + '<span style="font-size:12px;">Use <strong>Subir para o storage</strong> para receber o arquivo, '
            + 'ou verifique o servidor de mídia.</span></div>';
    }, 20000);
}

/**
 * SUBIR PARA O STORAGE — o equipamento envia o arquivo, que fica no servidor.
 *
 * JIMI: `HVIDEO,<carimbo>,<câmera>` — o carimbo é o do NOME do bloco, devolvido
 * ao equipamento sem conversão de fuso.
 * JT/T: `37382` ("FTP file upload command") com a janela exata; as credenciais
 * de FTP são injetadas NO SERVIDOR (`sendcommand.php`) — mandá-las daqui as
 * exporia no código-fonte da página.
 */
function pbSubirStorage(t, dur, canal, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '&#8230; Enviando'; }
    var ehJimi = selProtoOf(selImei) === 'JIMI';
    var proNo = ehJimi ? 128 : 37382;
    var cmd = ehJimi
        ? 'HVIDEO,' + pbCarimbo(t) + ',' + canal
        : { channel: canal, channelId: canal,
            beginTime: fmtCompactUTC(new Date(t * 1000)),
            endTime:   fmtCompactUTC(new Date((t + dur) * 1000)),
            alarmFlag: 0, resourceType: 2, codeType: 0, storageType: 0 };

    pbSendCmd(selImei, proNo, cmd, function (ok, msg) {
        if (ok) {
            if (btn) btn.innerHTML = '&#10003; Solicitado';
            var f = document.getElementById('pb-fonte');
            if (f) f.textContent = 'Pedido enviado — o vídeo aparece em Downloads quando a câmera terminar de subir.';
            setTimeout(pbFecharAcoes, 900);
        } else {
            if (btn) { btn.disabled = false; btn.innerHTML = '&#8681; Subir para o storage'; }
            alert('A câmera recusou o pedido: ' + (msg || 'sem resposta.'));
        }
    });
}

// ── Player de arquivo do servidor ───────────────────────────────────────────
var pbPlayer = null;

/** Encerra o player, se houver. Sem isto cada clique vaza uma instância. */
function pbDestroyPlayer() {
    if (!pbPlayer) return;
    try { pbPlayer.pause(); pbPlayer.unload(); pbPlayer.detachMediaElement(); pbPlayer.destroy(); }
    catch (e) { /* já desmontado */ }
    pbPlayer = null;
}

function selectRecording(el, rec) {
    if (window.__pbPoll) { clearTimeout(window.__pbPoll); window.__pbPoll = null; }
    document.querySelectorAll('.timeline-item').forEach(function (t) { t.classList.remove('selected'); });
    if (el) el.classList.add('selected');

    var v = document.getElementById('vid-player');
    var ph = document.getElementById('vid-placeholder');
    var dl = document.getElementById('pb-download');
    var url = fileStorageUrl + rec.file_url;
    var ehTs = /\.ts(\?|$)/i.test(rec.file_url || '');

    pbDestroyPlayer();
    v.removeAttribute('src');
    v.removeAttribute('poster');

    // ⚠️ `&dl=1` marca o download EXPLÍCITO. O player busca a mesma URL por
    // `fetch` para remuxar; sem essa distinção, assistir viraria "baixado" e a
    // fila de Downloads mentiria na coluna que ela existe para responder.
    if (dl) {
        dl.href = url + '&dl=1';
        dl.style.display = rec.file_url ? '' : 'none';
        dl.setAttribute('download', rec.file_name || '');
    }

    var ehVideo = ehTs || rec.file_type === 'video' || rec.file_type === 'mp4' || rec.file_type === 'flv';

    if (ehVideo && ehTs && window.mpegts && mpegts.isSupported()) {
        // MPEG-TS remuxado para fMP4 em JS (o <video> não decodifica TS)
        ph.style.display = 'none';
        v.style.display = 'block';
        pbPlayer = mpegts.createPlayer({ type: 'mpegts', isLive: false, url: url });
        pbPlayer.attachMediaElement(v);
        pbPlayer.load();
        pbPlayer.play().catch(function () {});
        pbPlayer.on(mpegts.Events.ERROR, function (tipo) {
            ph.innerHTML = '<div style="text-align:center;color:var(--muted-soft);padding:16px;">'
                + 'Não foi possível reproduzir aqui (' + tipo + ').<br>'
                + '<span style="font-size:12px;">Use o botão Baixar — o arquivo está íntegro no servidor.</span></div>';
            ph.style.display = '';
            v.style.display = 'none';
        });
    } else if (ehVideo) {
        ph.style.display = 'none';
        v.style.display = 'block';
        v.src = url;
        v.play().catch(function () {});
    } else {
        ph.innerHTML = '<div style="text-align:center;color:var(--muted-soft);">' + (rec.file_name || 'Arquivo') + '</div>';
        ph.style.display = '';
        v.style.display = 'none';
    }
}

// ── Requisição: UMA só, sem canal ───────────────────────────────────────────
//
// 🔴 O CANAL SAIU DAQUI. A resposta do equipamento sempre traz todos os canais;
// pedir um era filtrar exibição fingindo ser parâmetro de requisição — e
// obrigava a consultar duas vezes o que vem de uma vez. Agora o JT/T também
// pede todos os canais numa tacada, para que as duas famílias se comportem
// igual na tela (a dinâmica difere; a experiência não deve).

/** Fatia o período em segmentos por dia UTC — o 37381 não cruza o dia. */
function utcDaySegments(fromDay, toDay) {
    var start = new Date(fromDay + 'T00:00:00-03:00');
    var end = new Date(toDay + 'T23:59:59-03:00');
    if (isNaN(start.getTime()) || isNaN(end.getTime()) || end < start) return [];
    var segs = [], cur = start;
    while (cur <= end && segs.length < 15) {
        var dayEnd = new Date(Date.UTC(cur.getUTCFullYear(), cur.getUTCMonth(), cur.getUTCDate(), 23, 59, 59));
        var segEnd = dayEnd < end ? dayEnd : end;
        segs.push([fmtCompactUTC(cur), fmtCompactUTC(segEnd)]);
        cur = new Date(dayEnd.getTime() + 1000);
    }
    return segs;
}

/**
 * Pede a lista do cartão a uma câmera JIMI.
 *
 * 🔴 SÃO DOIS COMANDOS. `FILELIST,<url>` (A006) apenas GRAVA o endereço; quem
 * dispara o upload é a forma NUA `FILELIST` (A007). Está nos dados de produção:
 * sete comandos com URL sem uma única captura; o nu produziu a captura no mesmo
 * segundo. Em sequência, e o segundo não sai se o primeiro for recusado.
 */
function pbRequestJimi(imei, cb) {
    if (!filelistBase) {
        alert('Não há endereço configurado para a câmera enviar a lista. '
            + 'Defina VIDEO_INGEST_IP (ou FILELIST_URL) no .env do servidor: '
            + 'precisa ser um IP que o EQUIPAMENTO alcance, nunca localhost.');
        if (cb) cb(false, 'sem VIDEO_INGEST_IP');
        return;
    }
    pbSendCmd(imei, 128, 'FILELIST,' + filelistBase + imei, function (ok, msg) {
        if (!ok) {
            alert('A câmera não aceitou o endereço da lista: ' + (msg || 'sem resposta.'));
            if (cb) cb(false, msg);
            return;
        }
        pbSendCmd(imei, 128, 'FILELIST', function (ok2, msg2) { if (cb) cb(ok2, msg2); });
    });
}

function onSubmitRequest(e) {
    var imei = document.getElementById('pb-imei').value;
    var from = document.querySelector('input[name=date_from]').value;
    var to = document.querySelector('input[name=date_to]').value;
    if (!imei || !from || !to) return true;

    if (selProtoOf(imei) === 'JIMI') {
        pbRequestJimi(imei);
    } else {
        // 37381 (0x9205): uma consulta por canal e por dia UTC. O laço é aqui
        // porque o comando não aceita "todos" — mas a TELA não pede canal.
        var cams = Number(document.getElementById('pb-imei').selectedOptions[0].dataset.cam) || 1;
        utcDaySegments(from, to).forEach(function (seg, i) {
            for (var c = 1; c <= cams; c++) {
                pbSendCmd(imei, 37381, {
                    channel: c, channelId: c,
                    beginTime: seg[0], endTime: seg[1],
                    alarmFlag: 0, resourceType: 0, codeType: 0, storageType: 0,
                    instructionID: 'pb' + Date.now() + '_' + i + '_' + c
                });
            }
        });
    }
    return true;
}

// ── Ligações da barra ───────────────────────────────────────────────────────
(function () {
    var svg = document.getElementById('pb-svg');
    if (!svg) return;

    // Canais: os cadastrados no equipamento, mais qualquer um que apareça no
    // dado (um cartão pode trazer canal que o cadastro não conhece).
    var vistos = {};
    PB.blocos.forEach(function (b) { vistos[b[2]] = 1; });
    for (var c = 1; c <= Math.max(1, selCam); c++) vistos[c] = 1;
    PB.canais = Object.keys(vistos).map(Number).sort(function (a, b) { return a - b; });

    // 🔴 `alvoDown` existe por causa do `setPointerCapture` do arraste: com a
    // captura ativa, o `click` chega com `target` = o próprio <svg>, não o
    // <rect> clicado — e o clique simplesmente não fazia nada. Guardar quem
    // estava sob o ponteiro no `pointerdown` é o que preserva o alvo real.
    var arrastando = false, xIni = 0, vIni = null, moveu = false, alvoDown = null;

    svg.addEventListener('wheel', function (ev) {
        ev.preventDefault();
        var r = svg.getBoundingClientRect();
        var p = (ev.clientX - r.left - PB_ESQ) / Math.max(1, r.width - PB_ESQ - PB_DIR);
        var ancora = PB.vista[0] + Math.max(0, Math.min(1, p)) * (PB.vista[1] - PB.vista[0]);
        pbZoom(ev.deltaY < 0 ? 1.35 : 1 / 1.35, ancora);
    }, { passive: false });

    svg.addEventListener('pointerdown', function (ev) {
        arrastando = true; moveu = false; xIni = ev.clientX; vIni = PB.vista.slice();
        alvoDown = (ev.target && ev.target.getAttribute && ev.target.getAttribute('data-t')) ? ev.target : null;
        svg.classList.add('arrastando');
        svg.setPointerCapture(ev.pointerId);
    });
    svg.addEventListener('pointermove', function (ev) {
        if (arrastando) {
            var r = svg.getBoundingClientRect();
            var pps = Math.max(1, r.width - PB_ESQ - PB_DIR) / (vIni[1] - vIni[0]);
            var dt = Math.round((ev.clientX - xIni) / pps);
            if (Math.abs(ev.clientX - xIni) > 3) moveu = true;
            pbAplicarVista(vIni[0] - dt, vIni[1] - dt);
            return;
        }
        var alvo = ev.target && ev.target.getAttribute && ev.target.getAttribute('data-t') ? ev.target : null;
        pbDica(ev, alvo);
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (n) {
        svg.addEventListener(n, function () { arrastando = false; svg.classList.remove('arrastando'); });
    });
    svg.addEventListener('pointerleave', function () { pbDica(null, null); });

    svg.addEventListener('click', function (ev) {
        if (moveu) return;                     // arrastar não é clicar
        var el = alvoDown;                     // ver a nota do `alvoDown` acima
        if (!el || !el.getAttribute || !el.getAttribute('data-t')) return;
        var t = +el.getAttribute('data-t'), d = +el.getAttribute('data-d'), c = +el.getAttribute('data-c');
        if (el.classList.contains('pb-sessao')) {
            // Panorama: clicar numa sessão APROXIMA nela. É o passo natural até
            // o bloco, e evita agir sobre meia hora de vídeo por engano.
            pbAplicarVista(t - Math.max(30, d * 0.08), t + d + Math.max(30, d * 0.08));
            return;
        }
        var arq = pbArquivoDoBloco(t, d, c);
        if (arq) { pbTocar(t, c); return; }
        pbAbrirAcoes(t, d, c, el);
    });

    document.addEventListener('click', function (ev) {
        var pop = document.getElementById('pb-pop');
        if (!pop || pop.style.display === 'none') return;
        var dentroBarra = ev.target && ev.target.closest && ev.target.closest('#pb-barra');
        if (!pop.contains(ev.target) && !dentroBarra
            && !(ev.target.classList && ev.target.classList.contains('pb-extract'))) pbFecharAcoes();
    });

    var t;
    window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(pbDesenhar, 120); });

    pbResumo();
    pbDesenhar();
})();

<?php if ($requested): ?>
// A câmera responde em segundos, de forma assíncrona: recarrega algumas vezes
// (o comando NÃO é reenviado — só o formulário o dispara). Qualquer interação
// cancela, para não recarregar por baixo de quem está lendo.
(function () {
    var params = new URLSearchParams(location.search);
    var poll = parseInt(params.get('poll') || '0');
    if (poll < 6) {
        window.__pbPoll = setTimeout(function () {
            params.set('poll', poll + 1);
            location.replace(location.pathname + '?' + params.toString());
        }, 8000);
    }
    ['pointerdown', 'wheel', 'keydown'].forEach(function (n) {
        document.addEventListener(n, function () {
            if (window.__pbPoll) { clearTimeout(window.__pbPoll); window.__pbPoll = null; }
        }, { once: true, passive: true });
    });
})();
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
