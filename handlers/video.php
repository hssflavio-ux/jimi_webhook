<?php
/**
 * JIMI Webhook System — Player de Vídeo v3.1.0
 * Endpoint: /video
 *
 * Player unificado: streams ao vivo (HTTP-FLV via flv.js) + gravações (MP4).
 * URL correta: http://{IP}:8881/{CANAL}/{IMEI}.flv
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_customer_id();
$db = Database::getInstance()->getConnection();

$streamUrl = getenv('STREAM_URL') ?: 'http://localhost:8881';
$fileStorageUrl = rtrim(getenv('FILE_STORAGE_URL') ?: 'http://localhost:23010/download/', '/') . '/';
$dashToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';

$devices = $db->query("
    SELECT d.imei, d.device_name, dm.model_name, dm.camera_count, dm.protocol
    FROM devices d LEFT JOIN device_models dm ON d.device_model_id=dm.id
    WHERE d.customer_id=$customer_id ORDER BY d.device_name
")->fetchAll(PDO::FETCH_ASSOC);

$selectedImei = $_GET['imei'] ?? ($devices[0]['imei'] ?? '');
$selectedMode = $_GET['mode'] ?? 'live';

$mediaFiles = $db->query("
    SELECT mf.id, mf.imei, mf.file_type, mf.file_name, mf.file_url, mf.file_size, mf.created_at, d.device_name
    FROM media_files mf JOIN devices d ON mf.imei=d.imei
    WHERE d.customer_id=$customer_id AND mf.file_type IN ('video','image','audio')
    ORDER BY mf.created_at DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$page_title='Vídeo'; $current_route='video';
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/flv.js@1.6.2/dist/flv.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.8/dist/hls.min.js"></script>
<style>.vid-bg{background:#1a1a1a;border-radius:var(--radius-lg);overflow:hidden;min-height:360px;display:flex;align-items:center;justify-content:center}
.vid-bg video{width:100%;display:block;max-height:500px}
.stream-bar{display:none;margin-top:8px;padding:8px 12px;border-radius:6px;font-size:12px}
.stream-bar.sending{display:flex;align-items:center;gap:8px;background:#eef4fa;color:#5a7fa8}
.stream-bar.playing{display:flex;align-items:center;gap:8px;background:#f0faf5;color:var(--success)}
.stream-bar.error{display:flex;align-items:center;gap:8px;background:#fef2f5;color:var(--error)}
@keyframes spin{to{transform:rotate(360deg)}}.spinner{width:14px;height:14px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite}
.media-list-item{cursor:pointer;padding:10px 12px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:10px;transition:background .1s}
.media-list-item:hover{background:var(--canvas)}
.media-list-item.selected{background:var(--primary-soft)}
</style>';

include __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px">
    <div>
        <div class="vid-bg" id="vid-container">
            <div id="vid-placeholder" style="text-align:center;color:var(--muted)">
                <i class="bi bi-camera-video" style="font-size:48px;display:block;margin-bottom:10px;opacity:.2"></i>
                Selecione um dispositivo e uma fonte
            </div>
            <video id="vid-player" controls style="display:none;width:100%;max-height:500px" playsinline></video>
        </div>

        <div class="stream-bar" id="stream-bar"><span id="stream-bar-text"></span></div>

        <div class="flex-between mt-16">
            <div class="flex flex-gap">
                <button class="btn <?= $selectedMode==='live'?'btn-primary':'btn-outline' ?> btn-sm" onclick="switchMode('live')">Ao Vivo</button>
                <button class="btn <?= $selectedMode==='recorded'?'btn-primary':'btn-outline' ?> btn-sm" onclick="switchMode('recorded')">Gravações</button>
            </div>
            <select id="dev-sel" onchange="location.href='?mode=<?= $selectedMode ?>&imei='+this.value" style="padding:6px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm)">
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>" data-cam="<?= $d['camera_count']??1 ?>" <?= $selectedImei===$d['imei']?'selected':'' ?>><?= htmlspecialchars($d['device_name']??$d['imei']) ?> (<?= htmlspecialchars($d['model_name']??'-') ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="chan-sel" style="margin-top:12px;display:<?= $selectedMode==='live'?'block':'none' ?>">
            <span style="font-size:12px;color:var(--muted);margin-right:8px">Canal:</span>
            <?php $maxC = $devices[0]['camera_count'] ?? 1; for ($c=1;$c<=4;$c++): ?>
            <button class="btn btn-sm ch-btn <?= $c===1?'btn-primary':'btn-outline' ?>" data-ch="<?= $c ?>" onclick="selCh(<?= $c ?>)" <?= $c>$maxC?'disabled':'' ?>>CH<?= $c ?></button>
            <?php endfor; ?>
            <button class="btn btn-primary btn-sm" id="btn-start" style="margin-left:8px" onclick="startLive()">Iniciar Transmissão</button>
            <button class="btn btn-outline btn-sm" id="btn-stop" style="display:none" onclick="stopPlayer()">Parar</button>
        </div>
    </div>

    <div>
        <div class="card" style="margin-bottom:10px">
            <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px"><?= $selectedMode==='live'?'Transmissão Ao Vivo':'Arquivos Gravados' ?></h4>
            <span style="font-size:12px;color:var(--muted)"><?= $selectedMode==='live'?'HTTP-FLV em tempo real':'Clique para reproduzir' ?></span>
        </div>
        <div style="max-height:calc(100vh - 280px);overflow-y:auto">
            <?php if ($selectedMode==='recorded'): foreach ($mediaFiles as $mf): ?>
            <div class="media-list-item" onclick="playFile('<?= htmlspecialchars($fileStorageUrl.$mf['file_url']) ?>','<?= htmlspecialchars($mf['file_name']??'') ?>','<?= $mf['file_type'] ?>')">
                <i class="bi bi-<?= $mf['file_type']==='video'?'play-btn-fill':($mf['file_type']==='image'?'image-fill':'music-note') ?>" style="font-size:20px;color:var(--muted)"></i>
                <div style="flex:1;min-width:0"><div style="font-size:12px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($mf['file_name']??'Arquivo') ?></div><div style="font-size:11px;color:var(--muted)"><?= $mf['file_type'] ?> &middot; <?= number_format(($mf['file_size']??0)/1024/1024,1) ?> MB</div></div>
            </div>
            <?php endforeach; if(empty($mediaFiles)): ?><div class="empty-state"><p>Nenhum arquivo de mídia.</p></div><?php endif; endif; ?>
        </div>
    </div>
</div>

<script>
var streamUrl = <?= json_encode(rtrim($streamUrl,'/')) ?>;
var dashToken = <?= json_encode($dashToken) ?>;
var selImei = <?= json_encode($selectedImei) ?>;
var selCh = 1;
var curPlayer = null;

function switchMode(m) { location.href = '?mode=' + m + '&imei=' + selImei; }

function selCh(ch) {
    selCh = ch;
    document.querySelectorAll('.ch-btn').forEach(function(b){b.className='btn btn-sm btn-outline'});
    document.querySelector('.ch-btn[data-ch="'+ch+'"]').className='btn btn-sm btn-primary';
}

function stopPlayer() {
    if (curPlayer) {
        try { if (curPlayer.destroy) curPlayer.destroy(); } catch(e){}
        curPlayer = null;
    }
    var v = document.getElementById('vid-player');
    v.pause(); v.removeAttribute('src'); v.style.display = 'none';
    document.getElementById('vid-placeholder').style.display = '';
    document.getElementById('stream-bar').className = 'stream-bar';
    document.getElementById('btn-start').style.display = '';
    document.getElementById('btn-stop').style.display = 'none';
}

function startLive() {
    stopPlayer();
    var bar = document.getElementById('stream-bar');
    var txt = document.getElementById('stream-bar-text');
    bar.className = 'stream-bar sending';
    txt.innerHTML = '<span class="spinner"></span> Enviando comando de streaming...';

    // Enviar proNo 37121 (iniciar stream)
    fetch('/sendcommand', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-Dashboard-Token':dashToken},
        body: JSON.stringify({imei:selImei, proNo:37121, serverFlagId:0, content:JSON.stringify({dataType:"1",codeStreamType:"0",channel:String(selCh),videoIP:window.location.hostname,videoTCPPort:"0",videoUDPPort:"0"})})
    }).then(function(r){return r.json();}).then(function(d){
        if (d.code === 0) {
            var flvUrl = streamUrl + '/' + selCh + '/' + selImei + '.flv';
            txt.textContent = 'Conectando: ' + flvUrl;
            playFlvUrl(flvUrl);
        } else {
            bar.className = 'stream-bar error';
            txt.textContent = 'Erro ao iniciar stream: ' + (d.iothub_msg || d.msg);
        }
    }).catch(function(e){
        bar.className = 'stream-bar error';
        txt.textContent = 'Erro de rede ao enviar comando.';
    });
}

function playFlvUrl(url) {
    var bar = document.getElementById('stream-bar');
    var txt = document.getElementById('stream-bar-text');
    var v = document.getElementById('vid-player');

    if (typeof flvjs !== 'undefined' && flvjs.isSupported()) {
        curPlayer = flvjs.createPlayer({type:'flv',url:url,isLive:true});
        curPlayer.attachMediaElement(v);
        curPlayer.load();
        curPlayer.play().then(function(){
            document.getElementById('vid-placeholder').style.display = 'none';
            v.style.display = 'block';
            bar.className = 'stream-bar playing';
            txt.textContent = 'Stream: ' + url;
            document.getElementById('btn-start').style.display = 'none';
            document.getElementById('btn-stop').style.display = '';
        }).catch(function(e){
            bar.className = 'stream-bar error';
            txt.textContent = 'Erro ao iniciar player: ' + e.message;
        });
    } else {
        bar.className = 'stream-bar error';
        txt.textContent = 'Navegador não suporta flv.js. Use Chrome ou Firefox.';
    }
}

function playFile(url, name, type) {
    stopPlayer();
    document.querySelectorAll('.media-list-item').forEach(function(c){c.classList.remove('selected')});
    if (event && event.currentTarget) event.currentTarget.classList.add('selected');

    var v = document.getElementById('vid-player');
    var bar = document.getElementById('stream-bar');
    var txt = document.getElementById('stream-bar-text');

    document.getElementById('vid-placeholder').style.display = 'none';
    v.style.display = 'block';
    v.src = url;
    v.play().catch(function(){});

    bar.className = 'stream-bar playing';
    txt.textContent = 'Reproduzindo: ' + (name || 'Arquivo');
    document.getElementById('btn-start').style.display = 'none';
    document.getElementById('btn-stop').style.display = '';
}
</script>
<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
