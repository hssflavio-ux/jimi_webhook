<?php
/**
 * JIMI Webhook System — Vídeo Playback v4.0.0
 * Rota: /video/playback
 *
 * 2 passos:
 *   1. Selecionar Equipamento + canal + Período → [Requisitar]
 *   2. Escolher arquivo na timeline → Play ou Download
 *
 * Requisitar envia proNo 34818 (upload de mídia armazenada) ao device e
 * consulta media_files já recebidos para o período — os novos arquivos
 * chegam de forma assíncrona via /pushfileupload.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$fileStorageUrl = rtrim(getenv('FILE_STORAGE_URL') ?: 'http://localhost:23010/download/', '/') . '/';

$devices = $db->prepare("
    SELECT d.imei, d.device_name, dm.model_name, dm.protocol,
           COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) AS camera_count
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    WHERE d.customer_id = :cid
    ORDER BY d.device_name ASC
");
$devices->execute([':cid' => $customerId]);
$devices = $devices->fetchAll();

$selImei    = $_GET['imei'] ?? ($devices[0]['imei'] ?? '');
$selChannel = (int)($_GET['channel'] ?? 1);

// Quantidade de canais do equipamento selecionado (cadastro; fallback modelo)
$selCam = 1;
foreach ($devices as $d) {
    if ($d['imei'] === $selImei) { $selCam = max(1, (int)($d['camera_count'] ?? 1)); break; }
}
if ($selChannel < 1 || $selChannel > $selCam) $selChannel = 1;
$dateFrom   = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 day'));
$dateTo     = $_GET['date_to'] ?? date('Y-m-d');
$requested  = !empty($_GET['request']);

$recordings = [];
if ($requested && $selImei) {
    // Dias digitados são BRT; colunas do banco são UTC
    list($utcFrom, $utcTo) = brt_day_range_to_utc($dateFrom, $dateTo);
    $stmt = $db->prepare("
        SELECT id, file_name, file_url, file_type, file_size, event_time, channel, download_status, created_at
        FROM media_files
        WHERE imei = :imei
          AND (channel = :ch OR channel IS NULL)
          AND event_time BETWEEN :df AND :dt
        ORDER BY event_time DESC
        LIMIT 200
    ");
    $stmt->execute([
        ':imei' => $selImei,
        ':ch'   => $selChannel,
        ':df'   => $utcFrom,
        ':dt'   => $utcTo,
    ]);
    $recordings = $stmt->fetchAll();
}

$page_title = 'Vídeo Playback';
$current_route = 'video_playback';

$extra_head = '<style>
.vid-bg{background:#0a0b0d;border-radius:var(--radius-lg);overflow:hidden;min-height:360px;display:flex;align-items:center;justify-content:center;}
.vid-bg video{width:100%;display:block;max-height:460px;}
.timeline-item{cursor:pointer;padding:10px 14px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:12px;transition:background .1s;}
.timeline-item:hover{background:var(--canvas-soft);}
.timeline-item.selected{background:var(--primary-soft);border-left:3px solid var(--primary);}
.timeline-time{font-family:"JetBrains Mono",monospace;font-size:12px;color:var(--muted);white-space:nowrap;}
.timeline-dot{width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;">
    <!-- Player / Preview -->
    <div>
        <div class="vid-bg" id="vid-container">
            <div id="vid-placeholder" style="text-align:center;color:var(--muted-soft);">
                <i style="font-size:48px;display:block;margin-bottom:10px;opacity:.2;">&#9654;</i>
                Selecione equipamento, canal e período e clique em Requisitar
            </div>
            <video id="vid-player" controls playsinline style="display:none;width:100%;max-height:460px;"></video>
        </div>
    </div>

    <!-- Filters + Timeline -->
    <div>
        <div class="card" style="margin-bottom:12px;padding:14px 16px;">
            <form method="GET" id="playback-form" style="display:flex;flex-direction:column;gap:10px;" onsubmit="return onSubmitRequest(event)">
                <div>
                    <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Equipamento</label>
                    <select name="imei" id="pb-imei" onchange="pbRebuildChannels()" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                        <?php foreach ($devices as $d): ?>
                        <option value="<?= $d['imei'] ?>" data-cam="<?= $d['camera_count']??1 ?>" <?= $selImei===$d['imei']?'selected':'' ?>>
                            <?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?> (<?= htmlspecialchars($d['model_name']??'?') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:flex;gap:8px;">
                    <div style="flex:1;">
                        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Canal</label>
                        <select name="channel" id="pb-channel" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                            <?php for ($c=1;$c<=$selCam;$c++): ?>
                            <option value="<?= $c ?>" <?= $selChannel===$c?'selected':'' ?>>CH<?= $c ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div style="display:flex;gap:6px;">
                    <div style="flex:1;">
                        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">De</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Até</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    </div>
                </div>

                <button type="submit" name="request" value="1" class="btn btn-primary btn-sm" style="width:100%;">
                    &#128269; Requisitar Gravações
                </button>
            </form>
        </div>

        <?php if ($requested): ?>
        <div class="card" style="max-height:calc(100vh - 440px);overflow-y:auto;">
            <div style="font-size:12px;font-weight:600;color:var(--ink);padding-bottom:8px;border-bottom:1px solid var(--hairline);margin-bottom:8px;">
                <?= count($recordings) ?> gravação<?= count($recordings) !== 1 ? 'ões' : '' ?>
            </div>

            <?php if (empty($recordings)): ?>
            <div class="empty-state" style="padding:24px 12px;">
                <p>Nenhuma gravação encontrada no período.</p>
                <p style="font-size:11px;margin-top:4px;">O dispositivo pode ainda não ter enviado os arquivos.</p>
            </div>
            <?php else: ?>
            <?php foreach ($recordings as $rec): ?>
            <?php
                $ft = $rec['file_type'] ?? '';
                $isVideo = in_array($ft, ['video', 'mp4', 'flv', 'avi']);
                $icon = $isVideo ? '&#9654;' : '&#128196;';
            ?>
            <div class="timeline-item" onclick="selectRecording(this, <?= htmlspecialchars(json_encode($rec)) ?>)">
                <div class="timeline-dot"></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:12px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($rec['file_name'] ?? 'Gravação') ?>
                    </div>
                    <div class="timeline-time">
                        <?= fmt_brt($rec['event_time'] ?? $rec['created_at'], 'd/m/Y H:i:s') ?>
                        <?php if ($rec['file_size']): ?>
                        · <?= number_format($rec['file_size']/1024/1024, 1) ?> MB
                        <?php endif; ?>
                        <?php if ($rec['channel']): ?>
                        · CH<?= $rec['channel'] ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
var fileStorageUrl = <?= json_encode($fileStorageUrl) ?>;
var selImei = <?= json_encode($selImei) ?>;
var selChannel = <?= $selChannel ?>;

// Reconstrói as opções de canal conforme o cadastro do equipamento escolhido
// (devices.camera_count, fallback máximo do modelo — via data-cam da option)
function pbRebuildChannels() {
    var sel = document.getElementById('pb-imei');
    if (!sel.options.length || sel.selectedIndex < 0) return;
    var cam = parseInt(sel.options[sel.selectedIndex].dataset.cam) || 1;
    var chSel = document.getElementById('pb-channel');
    var cur = parseInt(chSel.value) || 1;
    if (cur > cam) cur = 1;
    var html = '';
    for (var c = 1; c <= cam; c++) {
        html += '<option value="' + c + '"' + (c === cur ? ' selected' : '') + '>CH' + c + '</option>';
    }
    chSel.innerHTML = html;
}

function selectRecording(el, rec) {
    document.querySelectorAll('.timeline-item').forEach(function(t) { t.classList.remove('selected'); });
    el.classList.add('selected');

    var v = document.getElementById('vid-player');
    var ph = document.getElementById('vid-placeholder');

    if (rec.file_type === 'video' || rec.file_type === 'mp4' || rec.file_type === 'flv') {
        var url = fileStorageUrl + rec.file_url;
        ph.style.display = 'none';
        v.style.display = 'block';
        v.src = url;
        v.play().catch(function() {});
    } else if (rec.file_type === 'image' || rec.file_type === 'jpg' || rec.file_type === 'png') {
        var url = fileStorageUrl + rec.file_url;
        ph.style.display = 'none';
        v.style.display = 'block';
        v.poster = url;
        v.removeAttribute('src');
    } else {
        ph.innerHTML = '<div style="text-align:center;color:var(--muted-soft);"><i style="font-size:36px;display:block;margin-bottom:8px;opacity:.2;">&#128196;</i>' + (rec.file_name || 'Arquivo') + '</div>';
        ph.style.display = '';
        v.style.display = 'none';
    }
}

// Formato de data JT/T: yyMMddHHmmss em GMT 0 (dias digitados são BRT/-03)
function jttUtcCompact(dayStr, endOfDay) {
    var d = new Date(dayStr + 'T' + (endOfDay ? '23:59:59' : '00:00:00') + '-03:00');
    if (isNaN(d.getTime())) return '';
    function p(n) { return String(n).padStart(2, '0'); }
    return String(d.getUTCFullYear()).slice(2) + p(d.getUTCMonth() + 1) + p(d.getUTCDate()) +
           p(d.getUTCHours()) + p(d.getUTCMinutes()) + p(d.getUTCSeconds());
}

function onSubmitRequest(e) {
    var imei = document.getElementById('pb-imei').value;
    var channel = document.querySelector('select[name=channel]').value;
    var from = document.querySelector('input[name=date_from]').value;
    var to = document.querySelector('input[name=date_to]').value;
    if (imei && from && to) {
        // proNo 34818 (0x8802 — upload de mídia armazenada): mediaType 2 = vídeo.
        // keepalive: o form navega logo em seguida e cancelaria o fetch.
        fetch('/sendcommand', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
            keepalive: true,
            body: JSON.stringify({
                imei: imei,
                proNo: 34818,
                serverFlagId: 0,
                content: JSON.stringify({
                    mediaType: 2,
                    channel: Number(channel) || 1,
                    eventCode: 0,
                    beginTime: jttUtcCompact(from, false),
                    endTime: jttUtcCompact(to, true)
                })
            })
        }).catch(function(){});
    }
    return true;
}
</script>
<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
