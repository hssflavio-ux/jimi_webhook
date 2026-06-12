<?php
/**
 * JIMI Webhook System — Player de Vídeo v3.1.0
 * Endpoint: /video
 *
 * Player unificado: streams ao vivo (HTTP-FLV) + arquivos gravados (MP4/HLS).
 * Suporte a FLV (flv.js), HLS (hls.js), e MP4 nativo.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_current_customer_id();
$db = Database::getInstance()->getConnection();

$streamUrl = getenv('STREAM_URL') ?: 'http://localhost:8881';
$fileStorageUrl = getenv('FILE_STORAGE_URL') ?: 'http://localhost:23010/download/';
$dashToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';

// Dispositivos com câmera
$devices = $db->query("
    SELECT d.imei, d.device_name, dm.model_name, dm.camera_count, dm.protocol
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    WHERE d.customer_id = $customer_id
    ORDER BY d.device_name
")->fetchAll(PDO::FETCH_ASSOC);

// Arquivos de mídia (todos os dispositivos do cliente)
$mediaFiles = $db->query("
    SELECT mf.id, mf.imei, mf.file_type, mf.file_name, mf.file_url, mf.file_size, mf.created_at,
           d.device_name
    FROM media_files mf
    JOIN devices d ON mf.imei = d.imei
    WHERE d.customer_id = $customer_id
      AND mf.file_type IN ('video', 'image', 'audio')
    ORDER BY mf.created_at DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$selectedImei = $_GET['imei'] ?? ($devices[0]['imei'] ?? '');
$selectedMode = $_GET['mode'] ?? 'live';

$fileStorageUrl = rtrim($fileStorageUrl, '/') . '/';

$page_title    = 'Vídeo';
$current_route = 'video';

$extra_head = '<script src="https://cdn.jsdelivr.net/npm/flv.js@1.6.2/dist/flv.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.8/dist/hls.min.js"></script>
<style>
.video-container { background: #1a1a1a; border-radius: var(--radius-lg); overflow: hidden; position: relative; }
.video-container video { width: 100%; display: block; max-height: 500px; }
.video-placeholder { display: flex; align-items: center; justify-content: center; height: 360px; color: var(--muted); font-size: 14px; text-align: center; }
.video-placeholder i { font-size: 56px; display: block; margin-bottom: 12px; opacity: 0.2; }
.media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.media-card { background: var(--surface); border: 1px solid var(--hairline); border-radius: var(--radius-md); padding: 12px; cursor: pointer; transition: border-color .15s; }
.media-card:hover { border-color: var(--primary); }
.media-card.selected { border-color: var(--primary); background: var(--primary-soft); }
.media-card-icon { font-size: 28px; margin-bottom: 8px; }
.media-card-name { font-size: 12px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 4px; }
.media-card-meta { font-size: 11px; color: var(--muted); }
.media-card-badge { position: absolute; top: 8px; right: 8px; font-size: 10px; padding: 2px 6px; border-radius: 9999px; }
</style>';

include __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px">
    <!-- Player -->
    <div>
        <div class="video-container" id="video-container">
            <div class="video-placeholder" id="video-placeholder">
                <div>
                    <i class="bi bi-camera-video"></i>
                    Selecione um dispositivo e uma fonte de vídeo
                </div>
            </div>
            <video id="video-player" controls style="display:none;width:100%;max-height:500px" playsinline></video>
        </div>

        <!-- Seletor de Modo -->
        <div class="flex-between mt-16">
            <div class="flex flex-gap">
                <button class="btn <?= $selectedMode === 'live' ? 'btn-primary' : 'btn-outline' ?> btn-sm" onclick="switchMode('live')">Ao Vivo</button>
                <button class="btn <?= $selectedMode === 'recorded' ? 'btn-primary' : 'btn-outline' ?> btn-sm" onclick="switchMode('recorded')">Gravações</button>
            </div>
            <div>
                <select id="device-select" onchange="onDeviceChange()" style="padding:6px 10px;font-size:13px;font-family:'Inter',sans-serif;border:1px solid var(--hairline);border-radius:var(--radius-sm)">
                    <?php foreach ($devices as $d): ?>
                    <option value="<?= $d['imei'] ?>" data-cameras="<?= $d['camera_count'] ?? 1 ?>" data-protocol="<?= $d['protocol'] ?>" <?= $selectedImei === $d['imei'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?> (<?= htmlspecialchars($d['model_name'] ?? '-') ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Canal selector (live mode) -->
        <div id="channel-selector" style="margin-top:12px;<?= $selectedMode !== 'live' ? 'display:none' : '' ?>">
            <span style="font-size:12px;color:var(--muted);margin-right:8px">Canal:</span>
            <?php $maxCam = $devices[0]['camera_count'] ?? 1; ?>
            <?php for ($ch = 1; $ch <= 4; $ch++): ?>
            <button class="btn btn-sm <?= $ch === 1 ? 'btn-primary' : 'btn-outline' ?>" id="ch-btn-<?= $ch ?>" onclick="selectChannel(<?= $ch ?>)" <?= $ch > ($maxCam) ? 'disabled' : '' ?>>
                CH<?= $ch ?>
            </button>
            <?php endfor; ?>
        </div>

        <!-- Info -->
        <div id="stream-info" style="margin-top:8px;font-size:11px;color:var(--muted);font-family:'JetBrains Mono',monospace"></div>
    </div>

    <!-- Sidebar: Lista de Arquivos -->
    <div>
        <div class="card" style="margin-bottom:12px">
            <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px"><?= $selectedMode === 'live' ? 'Streams Disponíveis' : 'Arquivos de Mídia' ?></h4>
            <span style="font-size:12px;color:var(--muted)"><?= $selectedMode === 'live' ? 'Selecione um canal para iniciar' : 'Clique para reproduzir' ?></span>
        </div>

        <?php if ($selectedMode === 'live'): ?>
        <div class="card">
            <p style="font-size:13px;color:var(--body)">Streams HTTP-FLV ao vivo do dispositivo selecionado.</p>
            <p style="font-size:12px;color:var(--muted);margin-top:8px">URL: <span class="text-mono"><?= htmlspecialchars($streamUrl) ?>/live/{imei}_{channel}.flv</span></p>
        </div>
        <?php endif; ?>

        <?php if ($selectedMode === 'recorded'): ?>
        <div style="max-height:calc(100vh - 280px);overflow-y:auto">
            <div class="media-grid" style="grid-template-columns:1fr">
                <?php foreach ($mediaFiles as $mf): ?>
                <div class="media-card <?= isset($_GET['play']) && $_GET['play'] == $mf['id'] ? 'selected' : '' ?>"
                     onclick="playFile('<?= htmlspecialchars($fileStorageUrl . $mf['file_url']) ?>', '<?= htmlspecialchars($mf['file_name']) ?>', '<?= $mf['file_type'] ?>')">
                    <div style="display:flex;align-items:flex-start;gap:8px">
                        <div class="media-card-icon" style="color:<?= $mf['file_type']==='video' ? 'var(--peach)' : ($mf['file_type']==='image' ? 'var(--blue)' : 'var(--mint)') ?>">
                            <i class="bi bi-<?= $mf['file_type']==='video' ? 'play-btn-fill' : ($mf['file_type']==='image' ? 'image-fill' : 'music-note') ?>"></i>
                        </div>
                        <div style="flex:1;min-width:0">
                            <div class="media-card-name"><?= htmlspecialchars($mf['file_name'] ?? 'Arquivo #'.$mf['id']) ?></div>
                            <div class="media-card-meta"><?= htmlspecialchars($mf['device_name'] ?? $mf['imei']) ?></div>
                            <div class="media-card-meta"><?= $mf['file_type'] ?> &middot; <?= number_format(($mf['file_size'] ?? 0) / 1024 / 1024, 1) ?> MB &middot; <?= date('d/m/Y H:i', strtotime($mf['created_at'])) ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($mediaFiles)): ?>
                <div class="empty-state" style="grid-column:1/-1"><p>Nenhum arquivo de mídia encontrado.</p></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
var streamUrl = <?= json_encode($streamUrl) ?>;
var selectedImei = <?= json_encode($selectedImei) ?>;
var selectedChannel = 1;
var currentMode = <?= json_encode($selectedMode) ?>;
var videoEl = document.getElementById('video-player');
var placeholder = document.getElementById('video-placeholder');
var currentPlayer = null;

function switchMode(mode) {
    var url = new URL(window.location);
    url.searchParams.set('mode', mode);
    url.searchParams.delete('play');
    window.location = url.toString();
}

function onDeviceChange() {
    var sel = document.getElementById('device-select');
    var opt = sel.options[sel.selectedIndex];
    selectedImei = sel.value;
    var url = new URL(window.location);
    url.searchParams.set('imei', selectedImei);
    url.searchParams.delete('play');
    window.location = url.toString();
}

function selectChannel(ch) {
    selectedChannel = ch;
    document.querySelectorAll('[id^="ch-btn-"]').forEach(function(b) { b.className = 'btn btn-sm btn-outline'; });
    document.getElementById('ch-btn-' + ch).className = 'btn btn-sm btn-primary';
    if (currentMode === 'live') startLiveStream();
}

function startLiveStream() {
    destroyPlayer();
    var url = streamUrl + '/live/' + selectedImei + '_' + selectedChannel + '.flv';
    document.getElementById('stream-info').textContent = 'Conectando: ' + url;

    if (flvjs.isSupported()) {
        var flvPlayer = flvjs.createPlayer({ type: 'flv', url: url, isLive: true });
        flvPlayer.attachMediaElement(videoEl);
        flvPlayer.load();
        flvPlayer.play().then(function() {
            placeholder.style.display = 'none';
            videoEl.style.display = 'block';
            document.getElementById('stream-info').textContent = 'Stream: ' + url + ' | Formato: HTTP-FLV';
        }).catch(function(e) {
            document.getElementById('stream-info').textContent = 'Erro: ' + e.message;
        });
        currentPlayer = { type: 'flv', instance: flvPlayer };
    } else {
        document.getElementById('stream-info').textContent = 'Erro: Navegador não suporta flv.js';
    }
}

function playFile(url, name, type) {
    document.querySelectorAll('.media-card').forEach(function(c) { c.classList.remove('selected'); });
    event.currentTarget.classList.add('selected');

    destroyPlayer();
    var ext = url.split('.').pop().toLowerCase().split('?')[0];

    if (ext === 'm3u8' || url.indexOf('.m3u8') > -1) {
        if (Hls.isSupported()) {
            var hls = new Hls();
            hls.loadSource(url);
            hls.attachMedia(videoEl);
            hls.on(Hls.Events.MANIFEST_PARSED, function() { videoEl.play(); });
            currentPlayer = { type: 'hls', instance: hls };
            document.getElementById('stream-info').textContent = 'Reproduzindo: ' + name + ' | Formato: HLS';
        } else if (videoEl.canPlayType('application/vnd.apple.mpegurl')) {
            videoEl.src = url;
            videoEl.play();
            document.getElementById('stream-info').textContent = 'Reproduzindo: ' + name + ' | Formato: HLS (nativo)';
        }
    } else if (ext === 'flv') {
        if (flvjs.isSupported()) {
            var flvPlayer = flvjs.createPlayer({ type: 'flv', url: url, isLive: false });
            flvPlayer.attachMediaElement(videoEl);
            flvPlayer.load();
            flvPlayer.play();
            currentPlayer = { type: 'flv', instance: flvPlayer };
            document.getElementById('stream-info').textContent = 'Reproduzindo: ' + name + ' | Formato: FLV';
        }
    } else {
        videoEl.src = url;
        videoEl.play();
        document.getElementById('stream-info').textContent = 'Reproduzindo: ' + name + ' | Formato: ' + ext.toUpperCase();
    }

    placeholder.style.display = 'none';
    videoEl.style.display = 'block';
}

function destroyPlayer() {
    if (currentPlayer) {
        if (currentPlayer.type === 'flv') { currentPlayer.instance.destroy(); }
        if (currentPlayer.type === 'hls') { currentPlayer.instance.destroy(); }
        currentPlayer = null;
    }
    videoEl.pause();
    videoEl.removeAttribute('src');
    videoEl.style.display = 'none';
    placeholder.style.display = 'flex';
}

// Auto-start live stream if mode is live and device is selected
if (currentMode === 'live' && selectedImei) {
    startLiveStream();
}

// Auto-play recorded file if ?play= is set
<?php if (!empty($_GET['play'])): ?>
(function() {
    var fileUrl = '<?= htmlspecialchars($fileStorageUrl . ($mediaFiles[0]['file_url'] ?? '')) ?>';
    var fileName = '<?= htmlspecialchars($mediaFiles[0]['file_name'] ?? '') ?>';
    if (fileUrl) playFile(fileUrl, fileName, 'video');
})();
<?php endif; ?>
</script>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
