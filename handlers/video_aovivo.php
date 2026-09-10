<?php
/**
 * JIMI Webhook System — Vídeo Ao Vivo v4.0.0
 * Rota: /video/aovivo
 *
 * Player HTTP-FLV ao vivo com seleção Cliente → Ativo → Canal.
 * Envia proNo 37121 antes de iniciar, aplica streaming_rotation/watermark do device.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

// ── Escopo multi-tenant (v4.9.23) ──────────────────────────────────────────
// Antes a tela era presa ao cliente da sessão: o administrador precisava
// TROCAR de cliente no cabeçalho para ver a câmera de outra carteira.
// O `?customer_id` passa por `report_customer_scope()` — para quem não é admin
// o parâmetro é ignorado, não validado (CLAUDE.md).
$filtroCust  = $_GET['customer_id'] ?? null;
$scopeCust   = report_customer_scope($filtroCust, $isAdmin, $customerId);
$customers   = $isAdmin ? report_customer_options($db) : [];
$scopeSql    = $scopeCust !== null ? ' AND d.customer_id = :cid' : '';
$scopeParams = $scopeCust !== null ? [':cid' => $scopeCust] : [];
// Com "todos", a lista mistura carteiras e abrir a câmera do cliente errado é
// dano de privacidade: o nome do cliente entra no rótulo só nesse caso.
$mostrarCliente = ($scopeCust === null);

// flv_base = saída HTTP-FLV (navegador); ingest_ip/port = onde o DEVICE publica o RTP (37121)
$vsc = video_stream_config();
$streamUrl = $vsc['flv_base'];

require_once __DIR__ . '/../includes/fleet_state.php';   // OFFLINE_GAP_SECONDS

// ── Última comunicação: o MAIOR entre os sinais que o device emite ───────────
//
// A conta saiu daqui para `device_last_seen_sql()` (includes/fleet_state.php),
// junto com o limiar: a explicação de POR QUE não basta `last_communication`
// está lá. Ela vivia inline nesta tela e foi copiada errado para a tela de
// comandos na v4.9.21 — que é o motivo de virar ponto único.
//
// ⚠️ `UTC_TIMESTAMP()` e não `NOW()`: a conexão do app força `time_zone='+00:00'`,
// mas dizê-lo por extenso evita que a conta dependa dessa configuração.
//
// 🔴 `is_active = 1` NÃO é filtro cosmético. Equipamento desativado é baixa do
// cadastro (`ativos.php` faz soft delete pondo `is_active=0`), e pedir vídeo
// dele é gastar comando num aparelho que não deveria mais aparecer — pior, num
// que pode ter sido devolvido ou trocado de cliente. Esta tela listava os
// inativos e só os ORDENAVA por último (`ORDER BY d.is_active DESC`), o que
// deixava claro que o campo era conhecido e mesmo assim não filtrado. Toda tela
// OPERACIONAL do sistema filtra assim — `comandos.php`, `rastreamento.php`,
// `camerasdata.php`, `hbdata.php`; só o CADASTRO (`ativos.php`) mostra inativo,
// e lá com selo "Inativo" ao lado.
$devices = $db->prepare("
    SELECT d.imei, d.device_name, dm.model_name, dm.protocol,
           COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) AS camera_count,
           d.streaming_rotation, d.streaming_watermark,
           COALESCE(cu.name, '—') AS customer_name,
           " . device_last_seen_sql() . " AS last_seen_utc
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN device_statistics ds ON ds.imei = d.imei
    LEFT JOIN customers cu ON cu.id = d.customer_id
    WHERE d.is_active = 1 {$scopeSql}
    ORDER BY cu.name, d.device_name ASC
");
$devices->execute($scopeParams);
$devices = $devices->fetchAll();

// 🔴 v4.16.0 — equipamento SEM canal não entra numa tela de vídeo. Os
// rastreadores da linha JM-VL são os primeiros modelos com `camera_count = 0`,
// e o `COALESCE(NULLIF(d.camera_count,0), dm.camera_count, 1)` acima devolve
// 0 para eles (o `1` do fim só socorre quem não tem modelo nenhum). Sem este
// filtro a tela os listaria e desenharia zero botão de canal — um aparelho
// escolhível sem nada para tocar, que o operador lê como defeito.
//
// ⚠️ O filtro é em PHP, não no SQL, de propósito: assim não depende da coluna
// `device_models.family`, que só existe depois da migração v4.16.0 — e
// migração nova não roda no deploy que a traz (CLAUDE.md).
$devices = array_values(array_filter($devices, fn($d) => (int)($d['camera_count'] ?? 1) > 0));

// Formatação em PHP, não em SQL: `DATE_FORMAT()` imprimia o UTC cru como se
// fosse hora local e a tela mostrava a última comunicação **3 h no futuro**.
// `fmt_brt()` é o ponto único de conversão do projeto (ver CLAUDE.md).
$agoraUtc = time();
foreach ($devices as &$d) {
    $ts = ($d['last_seen_utc'] && $d['last_seen_utc'] > '1971-01-01')
        ? strtotime($d['last_seen_utc'] . ' UTC') : null;
    $d['last_com']  = $ts ? fmt_brt($d['last_seen_utc'], 'd/m/Y H:i') : 'Nunca';
    $d['is_online'] = $ts !== null && ($agoraUtc - $ts) <= OFFLINE_GAP_SECONDS;
}
unset($d);

// O `?imei=` da URL só vale se pertencer ao cliente da sessão (multi-tenant):
// `$devices` já vem filtrado por customer_id, então checar contra ele basta.
$selectedImei = '';
foreach ($devices as $d) {
    if ($d['imei'] === ($_GET['imei'] ?? '')) { $selectedImei = $d['imei']; break; }
}
if ($selectedImei === '') $selectedImei = $devices[0]['imei'] ?? '';

$page_title = 'Vídeo ao Vivo';
$current_route = 'video_aovivo';

$extra_head = '<script src="https://cdn.jsdelivr.net/npm/flv.js@1.6.2/dist/flv.min.js"></script>
<style>
/* ── Mosaico de canais ────────────────────────────────────────────────────
   Um player por canal, todos ao vivo ao mesmo tempo (v4.17.14). O número de
   colunas vem da quantidade de canais; abaixo de 1100px o mosaico vira coluna
   única, porque dois vídeos lado a lado num laptop ficam pequenos demais para
   se enxergar o que a câmera está mostrando — que é o único motivo da tela. */
.vid-grid{display:grid;gap:10px;align-items:start;}
.vid-grid.cols-1{grid-template-columns:minmax(0,1fr);}
.vid-grid.cols-2{grid-template-columns:repeat(2,minmax(0,1fr));}
.vid-grid.cols-3{grid-template-columns:repeat(3,minmax(0,1fr));}
@media (max-width:1100px){.vid-grid.cols-2,.vid-grid.cols-3{grid-template-columns:minmax(0,1fr);}}
/* Foco: um canal ocupa a largura toda e os demais saem da vista — sem
   desmontar o player, para que voltar seja instantâneo e não repita o comando. */
.vid-grid.foco .vid-tile{display:none;}
.vid-grid.foco .vid-tile.em-foco{display:block;grid-column:1/-1;}
.vid-tile{min-width:0;}

.vid-bg{background:#0a0b0d;border-radius:var(--radius-lg);overflow:hidden;display:flex;align-items:center;justify-content:center;position:relative;}
/* `aspect-ratio` mantém os quadros do mosaico do mesmo tamanho antes de o
   vídeo chegar — sem ele as células pulam de altura quando o primeiro canal
   conecta. Com um canal só, a tela volta ao formato alto de antes. */
.vid-grid.cols-1 .vid-bg{min-height:400px;}
.vid-grid.cols-2 .vid-bg,.vid-grid.cols-3 .vid-bg{aspect-ratio:16/9;}
.vid-grid.foco .vid-tile.em-foco .vid-bg{aspect-ratio:auto;min-height:400px;}
.vid-bg video{width:100%;height:100%;display:block;object-fit:contain;background:#0a0b0d;}
.vid-grid.cols-1 .vid-bg video{max-height:520px;}
.vid-placeholder{text-align:center;color:var(--muted-soft);padding:16px;}
.vid-placeholder i{font-size:40px;display:block;margin-bottom:10px;opacity:.25;font-style:normal;}
.vid-grid.cols-1 .vid-placeholder i{font-size:56px;}

/* Etiqueta do canal sobre o vídeo: identifica o quadro sem roubar altura
   dele — num mosaico, saber QUAL câmera é cada quadro é a informação que
   não pode faltar. */
.vid-chip{position:absolute;top:8px;left:8px;z-index:6;display:flex;align-items:center;gap:6px;
          padding:3px 9px;border-radius:100px;background:rgba(10,11,13,.72);color:#fff;
          font-size:10.5px;font-weight:600;letter-spacing:.3px;pointer-events:none;}
.vid-chip .pt{width:6px;height:6px;border-radius:50%;background:var(--muted-soft);flex:0 0 auto;}
.vid-chip.no-ar .pt{background:#0f9d58;box-shadow:0 0 0 3px rgba(15,157,88,.25);}
.vid-chip.erro .pt{background:#e02d3c;}
.vid-foco-btn{position:absolute;top:8px;right:8px;z-index:6;border:0;cursor:pointer;
              padding:4px 9px;border-radius:100px;background:rgba(10,11,13,.72);color:#fff;
              font-size:10.5px;font-weight:600;letter-spacing:.3px;}
.vid-foco-btn:hover{background:rgba(10,11,13,.92);}

.stream-bar{display:none;margin-top:8px;padding:10px 14px;border-radius:var(--radius-sm);font-size:12px;font-weight:500;}
.stream-bar.sending{display:flex;align-items:center;gap:8px;background:var(--primary-soft);color:var(--primary);}
.stream-bar.playing{display:flex;align-items:center;gap:8px;background:#e4f7ee;color:var(--success);}
.stream-bar.error{display:flex;align-items:center;gap:8px;background:#fdeaec;color:var(--error);}
/* Linha de estado POR CANAL. No mosaico a barra única não serve: um canal pode
   estar no ar enquanto o outro ainda tenta, e uma frase só teria de mentir
   sobre um dos dois. */
.tile-bar{font-size:11px;line-height:1.5;margin-top:5px;min-height:16px;color:var(--muted);
          display:flex;align-items:center;gap:6px;}
.tile-bar.ok{color:var(--success);}
.tile-bar.err{color:var(--error);}
@keyframes spin{to{transform:rotate(360deg);}}
.spinner{width:14px;height:14px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;flex:0 0 auto;}
.tile-bar .spinner{width:11px;height:11px;border-width:1.6px;}
.watermark-overlay{position:absolute;top:10px;right:10px;padding:3px 8px;background:rgba(0,0,0,.6);color:#fff;font-size:11px;border-radius:4px;font-family:\'JetBrains Mono\',monospace;display:none;pointer-events:none;z-index:5;}
.vid-grid.cols-2 .watermark-overlay,.vid-grid.cols-3 .watermark-overlay{top:38px;}
/* Chips de canal do rodapé: seleção MÚLTIPLA — cada um ligado abre um player. */
.ch-chip{cursor:pointer;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="list-with-panel" style="--panel-w:300px">
    <!-- Player(es) -->
    <div>
        <?php /* 🔴 UM PLAYER POR CANAL (v4.17.14). A JC400AD e as JT/T de mais
                 de um canal publicam os canais SIMULTANEAMENTE — a JIMI com um
                 único `RTMP,ON,INOUT` (que registra `live/0` e `live/1` de uma
                 vez, medido em 18/08/2026), a JT/T com um `37121` por canal.
                 A tela abria um player só e obrigava a alternar entre canais
                 para ver o que já estava no ar ao mesmo tempo. O mosaico é
                 montado por montarMosaico(), a partir dos canais marcados. */ ?>
        <div class="vid-grid cols-1" id="vid-grid"></div>

        <div class="stream-bar" id="stream-bar"><span id="stream-bar-text"></span></div>

        <!-- Controls -->
        <div style="margin-top:16px;display:flex;flex-wrap:wrap;align-items:center;gap:10px;">
            <?php if ($isAdmin): ?>
            <?php /* Trocar o cliente recarrega a tela: a lista de equipamentos,
                     os canais e o player dependem do device escolhido, e manter
                     tudo em memória para depois descartar não paga. O `imei` sai
                     da URL de propósito — o da carteira anterior não existe na
                     nova, e mantê-lo selecionaria um equipamento alheio. */ ?>
            <select id="cust-sel" onchange="location.href='?customer_id='+this.value"
                    style="padding:8px 12px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:170px;">
                <option value="">Todos os clientes</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (string)$scopeCust === (string)$c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select id="dev-sel" onchange="onDeviceChange()" style="padding:8px 12px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:200px;">
                <?php foreach ($devices as $d): ?>
                <?php // Os data-* alimentam o painel lateral pelo JS: ele precisa
                      // acompanhar a troca de equipamento, e antes não acompanhava. ?>
                <option value="<?= $d['imei'] ?>"
                        data-cam="<?= (int)($d['camera_count'] ?? 1) ?>"
                        data-proto="<?= htmlspecialchars((string)($d['protocol'] ?? ''), ENT_QUOTES) ?>"
                        data-rotation="<?= (int)($d['streaming_rotation'] ?? 0) ?>"
                        data-watermark="<?= (int)($d['streaming_watermark'] ?? 0) ?>"
                        data-placa="<?= htmlspecialchars($d['device_name'] ?: $d['imei'], ENT_QUOTES) ?>"
                        data-last="<?= htmlspecialchars($d['last_com'], ENT_QUOTES) ?>"
                        data-online="<?= $d['is_online'] ? 1 : 0 ?>"
                        <?= $selectedImei === $d['imei'] ? 'selected' : '' ?>>
                    <?php // Com "todos os clientes" a lista mistura carteiras e
                          // abrir a câmera errada é dano de privacidade, não
                          // engano cosmético: o cliente entra no rótulo. ?>
                    <?= $mostrarCliente ? htmlspecialchars($d['customer_name']) . ' · ' : '' ?><?= htmlspecialchars($d['device_name'] ?: $d['imei']) ?>
                    (<?= $d['is_online'] ? 'Online' : 'Offline' ?>)
                </option>
                <?php endforeach; ?>
            </select>

            <?php /* Os chips deixaram de ser "qual canal ver" para ser "quais
                     canais abrir": seleção múltipla, todos ligados por padrão.
                     Desligar um deixa de gastar franquia do SIM com um canal
                     que ninguém está olhando. */ ?>
            <span style="font-size:12px;color:var(--muted);">Canais:</span>
            <div id="chan-sel" style="display:flex;gap:4px;"></div>

            <button class="btn btn-primary btn-sm" id="btn-start" onclick="startLive()">&#9654; Iniciar Transmissão</button>
            <button class="btn btn-outline btn-sm" id="btn-stop" style="display:none;" onclick="pararAoVivo()">&#9632; Parar</button>
        </div>
    </div>

    <!-- Sidebar Info -->
    <div>
        <div class="card" style="margin-bottom:12px;">
            <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:6px;">Informações do Dispositivo</h4>
            <?php // O conteúdo é reescrito por atualizarInfoDispositivo() a cada
                  // troca no seletor. Antes era HTML fixo do PRIMEIRO equipamento:
                  // trocar de câmera no dropdown não mexia neste painel, e ele
                  // seguia mostrando canais, data e status de outro aparelho. ?>
            <div id="device-info" style="font-size:13px;color:var(--body);line-height:1.8;"></div>
        </div>

        <div class="card">
            <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:6px;">Como usar</h4>
            <ol style="font-size:12px;color:var(--muted);padding-left:16px;line-height:1.8;">
                <li>Verifique se a câmera está online</li>
                <li>Escolha o canal</li>
                <li>Clique em "Iniciar Transmissão"</li>
                <li>O sistema envia o comando ao dispositivo</li>
                <li>O stream abre automaticamente</li>
            </ol>
        </div>
    </div>
</div>

<script>
var streamUrl = <?= json_encode($streamUrl) ?>;
var ingestIp = <?= json_encode($vsc['ingest_ip']) ?>;
var ingestPort = <?= json_encode($vsc['ingest_port']) ?>;
var selImei = <?= json_encode($selectedImei) ?>;
// 🔴 JIMI e JT/T pedem vídeo ao vivo de formas DIFERENTES, e a tela mandava só
// a do JT/T em todo equipamento. Ver startLive()/urlDoStream().
var selProto = <?= json_encode(strtoupper((string)($devices[0]['protocol'] ?? ''))) ?>;
var maxCams = 1;
var rotation = 0;
var watermark = 0;

// ── Estado do mosaico ───────────────────────────────────────────────────────
//
// `canais[ch]` diz se o canal está MARCADO (abre player); `players[ch]` guarda
// a instância flv.js e os timers daquele canal. Antes havia uma variável só
// (`selCh`/`curPlayer`), o que é a razão de a tela só conseguir um player de
// cada vez: não havia onde pôr o segundo.
var canais  = {};
var players = {};
var focoCh  = 0;            // 0 = mosaico inteiro; N = só o canal N à vista
var mudoPorPadrao = false;  // com mais de um quadro, o áudio de todos juntos é ruído

// Controle das tentativas de conexão ao FLV (o device leva alguns
// segundos entre aceitar o comando e publicar o stream no media server)
var MAX_ATTEMPTS = 8;
var RETRY_MS = 3000;
var WATCHDOG_MS = 8000;
var playSession = 0; // invalida callbacks de sessões de play antigas

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];
    });
}

/** Rótulo do canal — mesma convenção da tela de playback. */
function rotuloCanal(ch) {
    return ch === 1 ? 'CH1 · frontal' : (ch === 2 ? 'CH2 · interna' : 'CH' + ch);
}

/**
 * Teto de canais que o protocolo consegue publicar.
 *
 * 🔴 O JIMI SÓ TEM DOIS. `RTMP,ON,<B>` aceita `IN`, `OUT`, `INOUT` e `PIP` — o
 * device recusa qualquer outra coisa ("parameter B error"), e não existe forma
 * de pedir um terceiro canal. Um cadastro com `camera_count = 3` numa câmera
 * JIMI desenharia um quadro que nunca receberia vídeo; melhor não desenhá-lo.
 * No JT/T o 37121 leva o número do canal e não tem esse teto.
 */
function tetoDeCanais() {
    var n = Math.max(1, maxCams);
    return selProto === 'JIMI' ? Math.min(2, n) : n;
}

/** Canais marcados agora, em ordem. */
function canaisAtivos() {
    var out = [];
    for (var c = 1; c <= tetoDeCanais(); c++) if (canais[c]) out.push(c);
    return out;
}

function onDeviceChange() {
    var sel = document.getElementById('dev-sel');
    selImei = sel.value;
    var opt = sel.options[sel.selectedIndex];
    maxCams = parseInt(opt.dataset.cam) || 1;
    selProto = (opt.dataset.proto || '').toUpperCase();
    rotation = parseInt(opt.dataset.rotation) || 0;
    watermark = parseInt(opt.dataset.watermark) || 0;
    stopPlayer();
    marcarTodosOsCanais();
    renderChannels();
    montarMosaico();
    atualizarInfoDispositivo();
}

/** Escreve o painel lateral com os dados do equipamento ESCOLHIDO agora. */
function atualizarInfoDispositivo() {
    var sel = document.getElementById('dev-sel');
    var box = document.getElementById('device-info');
    if (!box) return;
    if (!sel || sel.selectedIndex < 0) { box.innerHTML = '<div>Nenhum equipamento.</div>'; return; }

    var o = sel.options[sel.selectedIndex];
    var online = o.dataset.online === '1';
    var cams = parseInt(o.dataset.cam) || 1;
    var teto = tetoDeCanais();
    box.innerHTML =
        '<div>Placa: <span class="text-mono">' + esc(o.dataset.placa) + '</span></div>' +
        '<div>Canais: ' + cams +
            (teto < cams ? ' <span style="color:var(--muted);font-size:11px;">(o protocolo JIMI transmite 2)</span>' : '') +
            (teto > 1 ? ' <span style="color:var(--muted);font-size:11px;">— simultâneos</span>' : '') + '</div>' +
        '<div>Última comunicação: <span class="text-mono">' + esc(o.dataset.last) + '</span></div>' +
        '<div>Status: <span class="badge ' + (online ? 'badge-success">Online' : 'badge-error">Offline') +
        '</span></div>';
}

function marcarTodosOsCanais() {
    canais = {};
    for (var c = 1; c <= tetoDeCanais(); c++) canais[c] = true;
    focoCh = 0;
}

// ── Chips de canal: seleção MÚLTIPLA ────────────────────────────────────────
function renderChannels() {
    var container = document.getElementById('chan-sel');
    if (!container) return;
    var teto = tetoDeCanais();
    var html = '';
    for (var c = 1; c <= teto; c++) {
        var on = !!canais[c];
        html += '<button type="button" class="btn btn-sm ch-chip ' + (on ? 'btn-primary' : 'btn-outline') + '"'
              + ' data-ch="' + c + '" aria-pressed="' + (on ? 'true' : 'false') + '"'
              + ' title="' + (on ? 'Abrir' : 'Não abrir') + ' o canal ' + c + '"'
              + ' onclick="toggleCanal(' + c + ')">CH' + c + '</button>';
    }
    container.innerHTML = html;
}

/**
 * Liga/desliga um canal. O último marcado não se desmarca: um mosaico vazio
 * seria uma tela sem nada e sem explicação — a saída para "não quero ver
 * nenhum" é o botão Parar.
 */
function toggleCanal(ch) {
    if (canais[ch] && canaisAtivos().length === 1) return;
    canais[ch] = !canais[ch];
    if (!canais[ch]) { pararCanal(ch); if (focoCh === ch) focoCh = 0; }
    renderChannels();
    montarMosaico();
}

// ── Mosaico ─────────────────────────────────────────────────────────────────
function montarMosaico() {
    var grid = document.getElementById('vid-grid');
    if (!grid) return;
    var chs = canaisAtivos();
    // Quem já está tocando NÃO é remontado: recriar o <video> mataria o stream
    // no ar só porque o usuário marcou outro canal.
    var vivos = {};
    chs.forEach(function (c) { if (players[c] && players[c].noAr) vivos[c] = true; });

    grid.className = 'vid-grid cols-' + Math.min(3, chs.length) + (focoCh ? ' foco' : '');
    var html = [];
    chs.forEach(function (c) {
        html.push(
            '<div class="vid-tile' + (focoCh === c ? ' em-foco' : '') + '" data-ch="' + c + '" id="tile-' + c + '">'
          +   '<div class="vid-bg">'
          +     '<span class="vid-chip" id="chip-' + c + '"><span class="pt"></span>' + rotuloCanal(c) + '</span>'
          +     (chs.length > 1
                  ? '<button type="button" class="vid-foco-btn" id="foco-' + c + '" onclick="toggleFoco(' + c + ')">'
                    + (focoCh === c ? 'Recolher' : 'Expandir') + '</button>'
                  : '')
          +     '<div class="vid-placeholder" id="ph-' + c + '">'
          +       '<i>&#9654;</i>'
          +       '<div style="font-size:13px;">' + rotuloCanal(c) + '</div>'
          +       '<div style="font-size:11px;margin-top:4px;opacity:.7;">Clique em "Iniciar Transmissão"</div>'
          +     '</div>'
          +     '<div class="watermark-overlay" id="wm-' + c + '">bycamera</div>'
          +     '<video id="v-' + c + '" controls playsinline style="display:none;"></video>'
          +   '</div>'
          +   '<div class="tile-bar" id="bar-' + c + '"></div>'
          + '</div>');
    });
    grid.innerHTML = html.join('');

    // Reata os players que continuavam no ar ao <video> recém-criado.
    Object.keys(vivos).forEach(function (c) { reatarPlayer(+c); });
}

/**
 * Devolve um player vivo ao seu <video> depois de o mosaico ser redesenhado.
 * Sem isto, marcar um canal novo apagaria a imagem dos que já estavam no ar
 * (o objeto flv.js sobrevive, mas ficaria preso a um elemento fora do DOM).
 */
function reatarPlayer(ch) {
    var p = players[ch];
    var v = document.getElementById('v-' + ch);
    if (!p || !p.flv || !v) return;
    try {
        p.flv.detachMediaElement();
        p.flv.attachMediaElement(v);
        v.muted = p.mudo;
        v.style.display = 'block';
        var ph = document.getElementById('ph-' + ch);
        if (ph) ph.style.display = 'none';
        if (rotation !== 0) v.style.transform = 'rotate(' + rotation + 'deg)';
        if (watermark) { var wm = document.getElementById('wm-' + ch); if (wm) wm.style.display = 'block'; }
        marcarChip(ch, 'no-ar');
        v.play().catch(function () {});
    } catch (e) { /* player já desmontado */ }
}

function toggleFoco(ch) {
    focoCh = (focoCh === ch) ? 0 : ch;
    montarMosaico();
}

// ── Estado visível de cada quadro ───────────────────────────────────────────
function marcarChip(ch, estado) {
    var el = document.getElementById('chip-' + ch);
    if (el) el.className = 'vid-chip' + (estado ? ' ' + estado : '');
}
function barraCanal(ch, cls, html) {
    var el = document.getElementById('bar-' + ch);
    if (el) { el.className = 'tile-bar' + (cls ? ' ' + cls : ''); el.innerHTML = html; }
}
function barraGeral(cls, html) {
    var bar = document.getElementById('stream-bar');
    var txt = document.getElementById('stream-bar-text');
    bar.className = 'stream-bar' + (cls ? ' ' + cls : '');
    txt.innerHTML = html;
}

/**
 * Câmera JIMI do canal escolhido. Medido em 18/08/2026 numa JC400AD:
 * `RTMP,ON,INOUT` registra `live/0/<imei>` E `live/1/<imei>`, e `RTMP,ON,OUT`
 * registra só o `0` — logo CH1=OUT (frontal) e CH2=IN (cabine). O device
 * recusa outra coisa: "parameter B error. options:[IN,OUT,INOUT,PIP]".
 */
function cameraJimi(ch) { return ch >= 2 ? 'IN' : 'OUT'; }

/**
 * Modo do `RTMP,ON` para o CONJUNTO de canais pedidos.
 *
 * 🔴 É UM COMANDO SÓ para os dois canais. `INOUT` registra `live/0` e `live/1`
 * na mesma tacada (medido) — mandar `RTMP,ON,OUT` seguido de `RTMP,ON,IN`
 * reconfigura o push e derruba o primeiro, que é como um mosaico ingênuo
 * quebraria a JC400AD sem dar erro nenhum.
 */
function modoJimi(chs) {
    var temFrontal = chs.indexOf(1) >= 0, temInterna = chs.indexOf(2) >= 0;
    if (temFrontal && temInterna) return 'INOUT';
    return temInterna ? 'IN' : 'OUT';
}

/**
 * URL HTTP-FLV do stream, que também difere por protocolo.
 *
 * JIMI publica em `live/<canal>/<imei>`, com o canal em base ZERO — o que a
 * tela chama de CH1 é o canal 0. JT/T publica em `<canal>/<imei>`, base um.
 * Medido: com a câmera publicando, `/live/0/<imei>.flv` devolveu 200 com
 * assinatura FLV e `/1/<imei>.flv` não devolveu nada.
 */
function urlDoStream(ch) {
    return selProto === 'JIMI'
        ? streamUrl + '/live/' + (ch - 1) + '/' + selImei + '.flv'
        : streamUrl + '/' + ch + '/' + selImei + '.flv';
}

/** `RTMP,OFF` encerra o push da JIMI — sem isso ele só cai pelo timeout. */
function pararStreamJimi(imei) {
    if (selProto !== 'JIMI' || !imei) return;
    fetch('/sendcommand', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
        body: JSON.stringify({imei: imei, proNo: 128, serverFlagId: 1, content: 'RTMP,OFF'})
    }).catch(function () {});
}

/**
 * Parada pedida pelo usuário: além de desmontar os players, avisa a câmera JIMI.
 *
 * Não fica dentro de `stopPlayer()` porque ele também roda no começo do
 * `startLive()` e na troca de equipamento — ali um `RTMP,OFF` desligaria o que
 * acabou de ser pedido, ou o equipamento errado.
 */
function pararAoVivo() {
    pararStreamJimi(selImei);
    stopPlayer();
}

function destroyFlv(ch) {
    var p = players[ch];
    if (!p || !p.flv) return;
    try { p.flv.unload(); p.flv.detachMediaElement(); } catch (e) {}
    try { p.flv.destroy(); } catch (e) {}
    p.flv = null;
}

/** Desmonta UM canal, sem tocar nos outros — usado ao desmarcar um chip. */
function pararCanal(ch) {
    var p = players[ch];
    if (p) {
        if (p.attemptTimer) clearTimeout(p.attemptTimer);
        if (p.watchdogTimer) clearTimeout(p.watchdogTimer);
        destroyFlv(ch);
    }
    delete players[ch];
    var v = document.getElementById('v-' + ch);
    if (v) { try { v.pause(); } catch (e) {} v.removeAttribute('src'); v.style.display = 'none'; v.style.transform = ''; }
    var ph = document.getElementById('ph-' + ch); if (ph) ph.style.display = '';
    var wm = document.getElementById('wm-' + ch); if (wm) wm.style.display = 'none';
    marcarChip(ch, '');
    barraCanal(ch, '', '');
}

function stopPlayer() {
    playSession++;
    Object.keys(players).forEach(function (c) { pararCanal(+c); });
    players = {};
    document.querySelectorAll('.vid-tile').forEach(function (t) { pararCanal(+t.dataset.ch); });
    barraGeral('', '');
    document.getElementById('btn-start').style.display = '';
    document.getElementById('btn-stop').style.display = 'none';
}

// ── Início da transmissão ───────────────────────────────────────────────────
function startLive() {
    stopPlayer();
    var mySession = playSession;
    var chs = canaisAtivos();
    if (!chs.length) return;

    if (typeof flvjs === 'undefined' || !flvjs.isSupported()) {
        barraGeral('error', 'Navegador não suporta flv.js. Use Chrome ou Firefox.');
        return;
    }

    // 🔴 CADA PROTOCOLO PEDE O VÍDEO DE UM JEITO. Até a v4.9.28 esta tela
    // mandava `37121` em TODO equipamento — inclusive nas JIMI, que não
    // entendem esse comando: no banco, todo 37121 para JC400AD ficava `sent`
    // (device não respondeu), enquanto nas JC371/JC181 ficava `executed`.
    //
    //   JT/T 1078 → proNo 37121 (0x9101), serverFlagId 0: o device publica RTP
    //               no ingest do media server (10002). UM COMANDO POR CANAL.
    //   JIMI      → comando de texto `RTMP,ON,<CÂMERA>` (proNo 128,
    //               serverFlagId 1): o device faz PUSH RTMP para o endereço já
    //               gravado nele em `RSERVICE` (rtmp://<ip>:1936/live). UM
    //               COMANDO SÓ para os dois canais — ver modoJimi().
    //
    // Não há duração no `RTMP,ON`: o `<C>` da planilha só existe em firmware
    // V4.3+ e não é o tempo do stream — tempo é do `Video,<cam>,<seg>`, que é
    // captura de clipe. Sem leitor o media server derruba em ~20 s, e é assim
    // que a transmissão termina sozinha.
    // 🔴 Sem MODELO cadastrado não há protocolo, e sem protocolo não dá para
    // escolher o comando. O default silencioso 'JTT' seria repetir o defeito que
    // esta ramificação corrige — só que ao contrário. Melhor recusar e dizer o
    // que falta: são 1 de 11 equipamentos em produção (18/08/2026).
    if (selProto !== 'JIMI' && selProto !== 'JTT') {
        barraGeral('error', 'Equipamento sem modelo cadastrado: não dá para saber se ele fala JIMI ou JT/T, '
                          + 'e cada um pede o vídeo de um jeito. Defina o modelo em Equipamentos e tente de novo.');
        return;
    }

    // Com mais de um quadro, todos começam mudos: quatro trilhas de áudio
    // sobrepostas não são informação, e o navegador bloquearia o autoplay de
    // qualquer forma. O controle de volume de cada player religa a que
    // interessa.
    mudoPorPadrao = chs.length > 1;

    document.getElementById('btn-start').style.display = 'none';
    document.getElementById('btn-stop').style.display = '';
    chs.forEach(function (c) {
        barraCanal(c, '', '<span class="spinner"></span> Pedindo o canal à câmera…');
    });
    barraGeral('sending', '<span class="spinner"></span> Enviando comando de streaming ao dispositivo'
        + (chs.length > 1 ? ' — ' + chs.length + ' canais' : '') + '…');

    enviarComandos(mySession, chs, function (okChs, erro) {
        if (playSession !== mySession) return;
        if (!okChs.length) {
            barraGeral('error', esc(erro || 'Falha ao enviar comando'));
            document.getElementById('btn-start').style.display = '';
            document.getElementById('btn-stop').style.display = 'none';
            return;
        }
        barraGeral('sending', '<span class="spinner"></span> Comando aceito — aguardando a câmera publicar '
            + (okChs.length > 1 ? 'os ' + okChs.length + ' canais' : 'o vídeo') + '…');
        okChs.forEach(function (c) { connectAttempt(mySession, c, 1); });
    });
}

/**
 * Despacha os comandos de início e devolve os canais aceitos.
 *
 * 🔴 SERIALIZADO no JT/T, nunca em paralelo — é a mesma lição da tela de
 * playback com o 37381: a câmera ainda está processando o primeiro pedido
 * quando o próximo chega, não responde ao segundo, e o comando volta como
 * falha. Com um canal só o laço tem um passo e o custo é zero.
 */
function enviarComandos(mySession, chs, cb) {
    var ok = [], ultimoErro = null;

    function despachar(cmd, canaisDoCmd, seguir) {
        fetch('/sendcommand', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
            body: JSON.stringify(cmd)
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (playSession !== mySession) return;
            if (d.offline_queued) {
                ultimoErro = 'Dispositivo offline: o comando foi enfileirado e será entregue na reconexão — '
                           + 'a transmissão não vai iniciar agora.';
                canaisDoCmd.forEach(function (c) { marcarChip(c, 'erro'); barraCanal(c, 'err', 'Dispositivo offline.'); });
            } else if (d.code === 0) {
                canaisDoCmd.forEach(function (c) { ok.push(c); });
            } else {
                ultimoErro = 'Erro: ' + (d.iothub_msg || d.msg || 'Falha ao enviar comando');
                canaisDoCmd.forEach(function (c) { marcarChip(c, 'erro'); barraCanal(c, 'err', esc(ultimoErro)); });
            }
            seguir();
        }).catch(function () {
            if (playSession !== mySession) return;
            ultimoErro = 'Erro de rede ao enviar comando.';
            canaisDoCmd.forEach(function (c) { marcarChip(c, 'erro'); barraCanal(c, 'err', ultimoErro); });
            seguir();
        });
    }

    if (selProto === 'JIMI') {
        // Um comando só cobre os dois canais (ver modoJimi()).
        despachar({imei: selImei, proNo: 128, serverFlagId: 1, content: 'RTMP,ON,' + modoJimi(chs)},
                  chs, function () { cb(ok, ultimoErro); });
        return;
    }

    var fila = chs.slice();
    (function proximo() {
        if (!fila.length) { cb(ok, ultimoErro); return; }
        var c = fila.shift();
        despachar({imei: selImei, proNo: 37121, serverFlagId: 0,
                   content: JSON.stringify({
                        dataType: 0,
                        codeStreamType: 0,
                        channel: String(c),
                        videoIP: ingestIp,
                        videoTCPPort: ingestPort,
                        videoUDPPort: 0
                   })}, [c], proximo);
    })();
}

function connectAttempt(mySession, ch, attempt) {
    if (playSession !== mySession || !canais[ch]) return;
    var url = urlDoStream(ch);
    var v = document.getElementById('v-' + ch);
    var ph = document.getElementById('ph-' + ch);
    if (!v) return;
    var p = players[ch] = players[ch] || {};
    var settled = false;

    marcarChip(ch, '');
    barraCanal(ch, '', '<span class="spinner"></span> Conectando (tentativa ' + attempt + '/' + MAX_ATTEMPTS + ')…');

    function fail() {
        if (playSession !== mySession || settled) return;
        settled = true;
        if (p.watchdogTimer) { clearTimeout(p.watchdogTimer); p.watchdogTimer = null; }
        destroyFlv(ch);
        if (attempt < MAX_ATTEMPTS) {
            p.attemptTimer = setTimeout(function () { connectAttempt(mySession, ch, attempt + 1); }, RETRY_MS);
        } else {
            marcarChip(ch, 'erro');
            barraCanal(ch, 'err', 'O stream do canal ' + ch + ' não ficou disponível. Verifique se a câmera '
                + 'deste canal está habilitada no equipamento e tente novamente.');
            resumoGeral();
        }
    }

    function success() {
        if (playSession !== mySession || settled) return;
        settled = true;
        p.noAr = true;
        p.mudo = v.muted;
        if (p.watchdogTimer) { clearTimeout(p.watchdogTimer); p.watchdogTimer = null; }
        if (ph) ph.style.display = 'none';
        v.style.display = 'block';
        marcarChip(ch, 'no-ar');
        barraCanal(ch, 'ok', 'Ao vivo' + (v.muted ? ' · sem áudio (ative no controle de volume)' : ''));

        if (rotation !== 0) v.style.transform = 'rotate(' + rotation + 'deg)';
        if (watermark) { var wm = document.getElementById('wm-' + ch); if (wm) wm.style.display = 'block'; }
        resumoGeral();
    }

    destroyFlv(ch);
    v.muted = mudoPorPadrao;
    p.mudo = v.muted;
    p.flv = flvjs.createPlayer({type: 'flv', url: url, isLive: true}, {enableStashBuffer: false});
    p.flv.on(flvjs.Events.ERROR, fail); // 404/conexão recusada enquanto o device não publica
    p.flv.attachMediaElement(v);
    p.flv.load();
    p.watchdogTimer = setTimeout(fail, WATCHDOG_MS); // sem dados nem erro → tenta de novo

    var pr = p.flv.play();
    if (pr && pr.then) {
        pr.then(success).catch(function (err) {
            // Autoplay bloqueado pelo navegador: repete sem áudio
            if (err && err.name === 'NotAllowedError' && p.flv) {
                v.muted = true;
                p.mudo = true;
                var p2 = p.flv.play();
                if (p2 && p2.then) p2.then(success).catch(function () { fail(); });
            }
            // Demais erros: Events.ERROR ou o watchdog decidem o retry
        });
    }
}

/** Uma frase para o conjunto — o detalhe de cada canal fica na barra do quadro. */
function resumoGeral() {
    var chs = canaisAtivos();
    var noAr = chs.filter(function (c) { return players[c] && players[c].noAr; });
    if (!noAr.length) {
        barraGeral('error', 'Nenhum canal entrou no ar. Veja o aviso em cada quadro.');
    } else if (noAr.length === chs.length) {
        barraGeral('playing', chs.length > 1
            ? 'Ao vivo — ' + noAr.length + ' canais simultâneos (' + noAr.map(function (c) { return 'CH' + c; }).join(', ') + ')'
            : 'Ao vivo — CH' + noAr[0]);
    } else {
        barraGeral('sending', 'Ao vivo em ' + noAr.length + ' de ' + chs.length + ' canais — '
            + 'os demais ainda tentam ou falharam (veja cada quadro).');
    }
}

// Estado inicial: lê o data-cam do device já selecionado (antes o load
// renderizava com maxCams=1 e só o CH1 ficava habilitado até trocar o select)
(function initChannels() {
    var sel = document.getElementById('dev-sel');
    if (sel && sel.options.length && sel.selectedIndex >= 0) {
        maxCams = parseInt(sel.options[sel.selectedIndex].dataset.cam) || 1;
        rotation = parseInt(sel.options[sel.selectedIndex].dataset.rotation) || 0;
        watermark = parseInt(sel.options[sel.selectedIndex].dataset.watermark) || 0;
        // O protocolo entra aqui junto dos demais: a inicialização em PHP olha
        // o PRIMEIRO equipamento da lista, que não é necessariamente o
        // selecionado quando a tela abre com `?imei=`.
        selProto = (sel.options[sel.selectedIndex].dataset.proto || '').toUpperCase();
    }
    marcarTodosOsCanais();
    renderChannels();
    montarMosaico();
    atualizarInfoDispositivo();
})();
</script>
<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
