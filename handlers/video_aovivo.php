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

// flv_base = saída HTTP-FLV (navegador); ingest_ip/port = onde o DEVICE publica o RTP (37121)
$vsc = video_stream_config();
$streamUrl = $vsc['flv_base'];

// Devices with model info and streaming config
$devices = $db->prepare("
    SELECT d.imei, d.device_name, dm.model_name, dm.camera_count, dm.protocol,
           d.streaming_rotation, d.streaming_watermark,
           DATE_FORMAT(d.last_communication, '%d/%m/%Y %H:%i') as last_com,
           CASE WHEN TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) <= 5 THEN 1 ELSE 0 END as is_online
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    WHERE d.customer_id = :cid
    ORDER BY d.is_active DESC, d.device_name ASC
");
$devices->execute([':cid' => $customerId]);
$devices = $devices->fetchAll();

$selectedImei = $_GET['imei'] ?? ($devices[0]['imei'] ?? '');

// Find selected device info
$selDevice = null;
foreach ($devices as $d) {
    if ($d['imei'] === $selectedImei) { $selDevice = $d; break; }
}

$page_title = 'Vídeo ao Vivo';
$current_route = 'video_aovivo';

$extra_head = '<script src="https://cdn.jsdelivr.net/npm/flv.js@1.6.2/dist/flv.min.js"></script>
<style>
.vid-bg{background:#0a0b0d;border-radius:var(--radius-lg);overflow:hidden;min-height:400px;display:flex;align-items:center;justify-content:center;position:relative;}
.vid-bg video{width:100%;display:block;max-height:520px;object-fit:contain;}
.vid-placeholder{text-align:center;color:var(--muted-soft);}
.vid-placeholder i{font-size:56px;display:block;margin-bottom:12px;opacity:.25;}
.stream-bar{display:none;margin-top:8px;padding:10px 14px;border-radius:var(--radius-sm);font-size:12px;font-weight:500;}
.stream-bar.sending{display:flex;align-items:center;gap:8px;background:var(--primary-soft);color:var(--primary);}
.stream-bar.playing{display:flex;align-items:center;gap:8px;background:#e4f7ee;color:var(--success);}
.stream-bar.error{display:flex;align-items:center;gap:8px;background:#fdeaec;color:var(--error);}
@keyframes spin{to{transform:rotate(360deg);}}
.spinner{width:14px;height:14px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;}
.watermark-overlay{position:absolute;top:10px;right:10px;padding:3px 8px;background:rgba(0,0,0,.6);color:#fff;font-size:11px;border-radius:4px;font-family:\'JetBrains Mono\',monospace;display:none;pointer-events:none;z-index:5;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:1fr 300px;gap:16px;">
    <!-- Player -->
    <div>
        <div class="vid-bg" id="vid-container">
            <div id="vid-placeholder" class="vid-placeholder">
                <i>&#9654;</i>
                <div style="font-size:14px;">Selecione um dispositivo e canal</div>
                <div style="font-size:12px;margin-top:4px;opacity:.7;">Clique em "Iniciar Transmissão" para começar</div>
            </div>
            <div class="watermark-overlay" id="watermark">JIMI</div>
            <video id="vid-player" controls playsinline style="display:none;width:100%;max-height:520px;"></video>
        </div>

        <div class="stream-bar" id="stream-bar"><span id="stream-bar-text"></span></div>

        <!-- Controls -->
        <div style="margin-top:16px;display:flex;flex-wrap:wrap;align-items:center;gap:10px;">
            <select id="dev-sel" onchange="onDeviceChange()" style="padding:8px 12px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:200px;">
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>"
                        data-cam="<?= $d['camera_count'] ?? 1 ?>"
                        data-rotation="<?= (int)($d['streaming_rotation'] ?? 0) ?>"
                        data-watermark="<?= (int)($d['streaming_watermark'] ?? 0) ?>"
                        <?= $selectedImei === $d['imei'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?> —
                    <?= htmlspecialchars($d['model_name'] ?? '?') ?>
                    (<?= $d['is_online'] ? 'Online' : 'Offline' ?>)
                </option>
                <?php endforeach; ?>
            </select>

            <span style="font-size:12px;color:var(--muted);">Canal:</span>
            <div id="chan-sel" style="display:flex;gap:4px;"></div>

            <button class="btn btn-primary btn-sm" id="btn-start" onclick="startLive()">&#9654; Iniciar Transmissão</button>
            <button class="btn btn-outline btn-sm" id="btn-stop" style="display:none;" onclick="stopPlayer()">&#9632; Parar</button>
        </div>
    </div>

    <!-- Sidebar Info -->
    <div>
        <div class="card" style="margin-bottom:12px;">
            <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:6px;">Informações do Dispositivo</h4>
            <div id="device-info" style="font-size:13px;color:var(--body);line-height:1.8;">
                <div>IMEI: <span class="text-mono"><?= htmlspecialchars($selectedImei) ?></span></div>
                <div>Modelo: <?= htmlspecialchars($selDevice['model_name'] ?? '—') ?></div>
                <div>Canais: <?= (int)($selDevice['camera_count'] ?? 1) ?></div>
                <div>Última comunicação: <?= htmlspecialchars($selDevice['last_com'] ?? '—') ?></div>
                <div>Status:
                    <?php if ($selDevice['is_online'] ?? false): ?>
                    <span class="badge badge-success">Online</span>
                    <?php else: ?>
                    <span class="badge badge-error">Offline</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:6px;">Como usar</h4>
            <ol style="font-size:12px;color:var(--muted);padding-left:16px;line-height:1.8;">
                <li>Selecione o dispositivo online</li>
                <li>Escolha o canal (CH1-CH4)</li>
                <li>Clique em "Iniciar Transmissão"</li>
                <li>O sistema envia o comando ao dispositivo</li>
                <li>O stream HTTP-FLV abre automaticamente</li>
            </ol>
        </div>
    </div>
</div>

<script>
var streamUrl = <?= json_encode($streamUrl) ?>;
var ingestIp = <?= json_encode($vsc['ingest_ip']) ?>;
var ingestPort = <?= json_encode($vsc['ingest_port']) ?>;
var selImei = <?= json_encode($selectedImei) ?>;
var selCh = 1;
var curPlayer = null;
var maxCams = 1;
var rotation = 0;
var watermark = 0;

// Controle das tentativas de conexão ao FLV (o device leva alguns
// segundos entre aceitar o 37121 e publicar o stream no media server)
var MAX_ATTEMPTS = 8;
var RETRY_MS = 3000;
var WATCHDOG_MS = 8000;
var attemptTimer = null;
var watchdogTimer = null;
var playSession = 0; // invalida callbacks de sessões de play antigas

function onDeviceChange() {
    var sel = document.getElementById('dev-sel');
    selImei = sel.value;
    var opt = sel.options[sel.selectedIndex];
    maxCams = parseInt(opt.dataset.cam) || 1;
    rotation = parseInt(opt.dataset.rotation) || 0;
    watermark = parseInt(opt.dataset.watermark) || 0;
    renderChannels();
    stopPlayer();
}

function renderChannels() {
    var container = document.getElementById('chan-sel');
    var html = '';
    for (var c = 1; c <= 4; c++) {
        var disabled = c > maxCams ? ' disabled' : '';
        var active = c === selCh ? ' btn-primary' : ' btn-outline';
        html += '<button class="btn btn-sm' + active + '"' + disabled + ' data-ch="' + c + '" onclick="selChannel(' + c + ')">CH' + c + '</button>';
    }
    container.innerHTML = html;
}

function selChannel(ch) {
    selCh = ch;
    var btns = document.querySelectorAll('#chan-sel button');
    btns.forEach(function(b) {
        b.className = 'btn btn-sm ' + (parseInt(b.dataset.ch) === ch ? 'btn-primary' : 'btn-outline');
    });
}

function destroyFlv() {
    if (curPlayer) {
        try { curPlayer.unload(); curPlayer.detachMediaElement(); } catch(e) {}
        try { curPlayer.destroy(); } catch(e) {}
        curPlayer = null;
    }
}

function stopPlayer() {
    playSession++;
    if (attemptTimer)  { clearTimeout(attemptTimer);  attemptTimer = null; }
    if (watchdogTimer) { clearTimeout(watchdogTimer); watchdogTimer = null; }
    destroyFlv();
    var v = document.getElementById('vid-player');
    v.pause(); v.removeAttribute('src'); v.style.display = 'none';
    v.style.transform = ''; v.muted = false;
    document.getElementById('vid-placeholder').style.display = '';
    document.getElementById('stream-bar').className = 'stream-bar';
    document.getElementById('btn-start').style.display = '';
    document.getElementById('btn-stop').style.display = 'none';
    document.getElementById('watermark').style.display = 'none';
}

function startLive() {
    stopPlayer();
    var mySession = playSession;
    var bar = document.getElementById('stream-bar');
    var txt = document.getElementById('stream-bar-text');

    if (typeof flvjs === 'undefined' || !flvjs.isSupported()) {
        bar.className = 'stream-bar error';
        txt.textContent = 'Navegador não suporta flv.js. Use Chrome ou Firefox.';
        return;
    }

    bar.className = 'stream-bar sending';
    txt.innerHTML = '<span class="spinner"></span> Enviando comando de streaming ao dispositivo...';

    // proNo 37121 (0x9101): manda o device publicar o RTP no media server
    // do IoTHub (ingest 10002). videoIP/porta vêm do servidor (.env), pois é
    // o DEVICE quem precisa alcançar esse endereço, não o navegador.
    fetch('/sendcommand', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
        body: JSON.stringify({
            imei: selImei,
            proNo: 37121,
            serverFlagId: 0,
            content: JSON.stringify({
                dataType: 0,
                codeStreamType: 0,
                channel: String(selCh),
                videoIP: ingestIp,
                videoTCPPort: ingestPort,
                videoUDPPort: 0
            })
        })
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (playSession !== mySession) return;
        if (d.offline_queued) {
            bar.className = 'stream-bar error';
            txt.textContent = 'Dispositivo offline: o comando foi enfileirado e será entregue na reconexão — a transmissão não vai iniciar agora.';
        } else if (d.code === 0) {
            connectAttempt(mySession, 1);
        } else {
            bar.className = 'stream-bar error';
            txt.textContent = 'Erro: ' + (d.iothub_msg || d.msg || 'Falha ao enviar comando');
        }
    }).catch(function(e) {
        if (playSession !== mySession) return;
        bar.className = 'stream-bar error';
        txt.textContent = 'Erro de rede ao enviar comando.';
    });
}

function connectAttempt(mySession, attempt) {
    if (playSession !== mySession) return;
    var url = streamUrl + '/' + selCh + '/' + selImei + '.flv';
    var bar = document.getElementById('stream-bar');
    var txt = document.getElementById('stream-bar-text');
    var v = document.getElementById('vid-player');
    var settled = false;

    bar.className = 'stream-bar sending';
    txt.innerHTML = '<span class="spinner"></span> Conectando ao stream (tentativa ' + attempt + '/' + MAX_ATTEMPTS +
                    ')... o dispositivo leva alguns segundos para publicar o vídeo.';

    function fail() {
        if (playSession !== mySession || settled) return;
        settled = true;
        if (watchdogTimer) { clearTimeout(watchdogTimer); watchdogTimer = null; }
        destroyFlv();
        if (attempt < MAX_ATTEMPTS) {
            attemptTimer = setTimeout(function() { connectAttempt(mySession, attempt + 1); }, RETRY_MS);
        } else {
            bar.className = 'stream-bar error';
            txt.textContent = 'Stream não ficou disponível em ' + url +
                              '. Verifique se o dispositivo está online, com sinal de dados e com a câmera do canal CH' + selCh + ' habilitada, e tente novamente.';
        }
    }

    function success() {
        if (playSession !== mySession || settled) return;
        settled = true;
        if (watchdogTimer) { clearTimeout(watchdogTimer); watchdogTimer = null; }
        document.getElementById('vid-placeholder').style.display = 'none';
        v.style.display = 'block';
        bar.className = 'stream-bar playing';
        txt.textContent = 'Ao Vivo — CH' + selCh + ': ' + url +
                          (v.muted ? ' (sem áudio — ative no controle de volume do player)' : '');

        if (rotation !== 0) v.style.transform = 'rotate(' + rotation + 'deg)';
        if (watermark) document.getElementById('watermark').style.display = 'block';

        document.getElementById('btn-start').style.display = 'none';
        document.getElementById('btn-stop').style.display = '';
    }

    destroyFlv();
    curPlayer = flvjs.createPlayer({type: 'flv', url: url, isLive: true}, {enableStashBuffer: false});
    curPlayer.on(flvjs.Events.ERROR, fail); // 404/conexão recusada enquanto o device não publica
    curPlayer.attachMediaElement(v);
    curPlayer.load();
    watchdogTimer = setTimeout(fail, WATCHDOG_MS); // sem dados nem erro → tenta de novo

    var p = curPlayer.play();
    if (p && p.then) {
        p.then(success).catch(function(err) {
            // Autoplay bloqueado pelo navegador: repete sem áudio
            if (err && err.name === 'NotAllowedError' && curPlayer) {
                v.muted = true;
                var p2 = curPlayer.play();
                if (p2 && p2.then) p2.then(success).catch(function() { fail(); });
            }
            // Demais erros: Events.ERROR ou o watchdog decidem o retry
        });
    }
}

renderChannels();
</script>
<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
