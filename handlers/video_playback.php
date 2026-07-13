<?php
/**
 * JIMI Webhook System — Vídeo Playback v4.2.1
 * Rota: /video/playback
 *
 * Fluxo (JT/T — JC450/JC181/JC182/JC371):
 *   1. [Requisitar] → proNo 37381 (0x9205, consulta de gravações no cartão).
 *      A janela beginTime/endTime é GMT-0 compacta (yyMMddHHmmss) e NÃO pode
 *      cruzar o dia — o período é fatiado em segmentos por dia UTC.
 *      A câmera responde de forma assíncrona via /pushresourcelist → resource_lists.
 *   2. Timeline = resource_lists ("no cartão") ∪ media_files ("disponível").
 *      Item "no cartão" → [Extrair] dispara proNo 34818 (0x8802, upload de mídia
 *      armazenada) com a janela exata da gravação; o arquivo chega via
 *      /pushfileupload → media_files e o item vira reproduzível.
 *   3. Item "disponível" → play inline / download.
 *
 * Modelos de protocolo JIMI (JC400D/AD) não suportam 0x9205 — mantém o envio
 * direto de 34818 na janela do filtro (comportamento legado).
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

$recordings = [];
if ($requested && $selImei) {
    // Dias digitados são BRT; colunas do banco são UTC
    list($utcFrom, $utcTo) = brt_day_range_to_utc($dateFrom, $dateTo);
    $utcTz = new DateTimeZone('UTC');
    $toTs = function ($s) use ($utcTz) {
        if (!$s) return null;
        $dt = date_create($s, $utcTz);
        return $dt ? $dt->getTimestamp() : null;
    };

    // 1) Gravações que a câmera reportou no cartão (37381 → /pushresourcelist)
    $stmt = $db->prepare("
        SELECT id, resource_type, file_name, file_size, start_time, end_time, channel_id, alarm_type
        FROM resource_lists
        WHERE imei = :imei
          AND (channel_id = :ch OR channel_id = 0 OR channel_id IS NULL)
          AND start_time <= :dt
          AND COALESCE(end_time, start_time) >= :df
        ORDER BY start_time DESC
        LIMIT 300
    ");
    $stmt->execute([':imei' => $selImei, ':ch' => $selChannel, ':df' => $utcFrom, ':dt' => $utcTo]);
    $resources = $stmt->fetchAll();

    // 2) Arquivos já extraídos para o servidor (→ /pushfileupload)
    $stmt = $db->prepare("
        SELECT id, file_name, file_url, file_type, file_size, event_time, channel, download_status, created_at
        FROM media_files
        WHERE imei = :imei
          AND (channel = :ch OR channel IS NULL)
          AND event_time BETWEEN :df AND :dt
        ORDER BY event_time DESC
        LIMIT 200
    ");
    $stmt->execute([':imei' => $selImei, ':ch' => $selChannel, ':df' => $utcFrom, ':dt' => $utcTo]);
    $mediaFiles = $stmt->fetchAll();

    // 3) Unificação: media_file cujo horário cai na janela da gravação (±120s)
    //    torna aquela gravação reproduzível; os demais entram como itens próprios
    //    (ex.: vídeos de evento extraídos pelo motor de ocorrências).
    $mediaUsed = [];
    foreach ($resources as $r) {
        $rs = $toTs($r['start_time']);
        $re = $toTs($r['end_time']) ?: $rs;
        $match = null;
        if ($rs !== null) {
            foreach ($mediaFiles as $mi => $m) {
                if (isset($mediaUsed[$mi])) continue;
                $mt = $toTs($m['event_time'] ?? null);
                if ($mt !== null && $mt >= $rs - 120 && $mt <= $re + 120) {
                    $match = $m;
                    $mediaUsed[$mi] = true;
                    break;
                }
            }
        }
        $recordings[] = [
            'kind'       => $match ? 'available' : 'device',
            'media'      => $match,
            'file_name'  => $match['file_name'] ?? $r['file_name'],
            'file_size'  => (int)($r['file_size'] ?: ($match['file_size'] ?? 0)),
            'time_start' => $r['start_time'],
            'time_end'   => $r['end_time'],
            'channel'    => (int)($r['channel_id'] ?: $selChannel),
            'alarm_type' => $r['alarm_type'],
            // Janela exata da gravação em GMT-0 compacto, para o 34818 do [Extrair]
            'begin_c'    => $rs !== null ? gmdate('ymdHis', $rs) : '',
            'end_c'      => $re !== null ? gmdate('ymdHis', $re) : '',
        ];
    }
    foreach ($mediaFiles as $mi => $m) {
        if (isset($mediaUsed[$mi])) continue;
        $recordings[] = [
            'kind'       => 'available',
            'media'      => $m,
            'file_name'  => $m['file_name'],
            'file_size'  => (int)($m['file_size'] ?? 0),
            'time_start' => $m['event_time'] ?: $m['created_at'],
            'time_end'   => null,
            'channel'    => (int)($m['channel'] ?: $selChannel),
            'alarm_type' => null,
            'begin_c'    => '',
            'end_c'      => '',
        ];
    }
    usort($recordings, function ($a, $b) {
        return strcmp($b['time_start'] ?? '', $a['time_start'] ?? '');
    });
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
.timeline-dot.on-device{background:var(--muted-soft);}
.pb-badge{font-size:10px;font-weight:600;text-transform:uppercase;padding:2px 8px;border-radius:100px;white-space:nowrap;}
.pb-badge.available{background:var(--primary-soft);color:var(--primary);}
.pb-badge.on-device{background:var(--canvas-soft);color:var(--muted);border:1px solid var(--hairline);}
.pb-extract{font-size:11px;padding:4px 10px;white-space:nowrap;}
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
                        <option value="<?= $d['imei'] ?>" data-cam="<?= $d['camera_count']??1 ?>" data-proto="<?= htmlspecialchars($d['protocol'] ?? 'JTT') ?>" <?= $selImei===$d['imei']?'selected':'' ?>>
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
            <div class="empty-state" style="padding:24px 12px;" id="pb-empty">
                <p>Nenhuma gravação encontrada no período.</p>
                <p style="font-size:11px;margin-top:4px;">
                    A câmera responde à consulta em alguns segundos — esta página
                    atualiza sozinha. Se persistir, verifique se o equipamento está
                    online e se há cartão de memória.
                </p>
            </div>
            <?php else: ?>
            <?php foreach ($recordings as $rec): ?>
            <?php
                $isAvailable = $rec['kind'] === 'available';
                $media = $rec['media'];
                $durTxt = '';
                if ($rec['time_start'] && $rec['time_end']) {
                    $durSecs = strtotime($rec['time_end']) - strtotime($rec['time_start']);
                    if ($durSecs > 0) {
                        $durTxt = $durSecs >= 60 ? floor($durSecs / 60) . 'min' . ($durSecs % 60 ? str_pad($durSecs % 60, 2, '0', STR_PAD_LEFT) . 's' : '') : $durSecs . 's';
                    }
                }
            ?>
            <div class="timeline-item" <?= $isAvailable ? 'onclick="selectRecording(this, ' . htmlspecialchars(json_encode($media)) . ')"' : '' ?>>
                <div class="timeline-dot <?= $isAvailable ? '' : 'on-device' ?>"></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:12px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:6px;">
                        <span class="pb-badge <?= $isAvailable ? 'available' : 'on-device' ?>"><?= $isAvailable ? 'Disponível' : 'No cartão' ?></span>
                        <span style="overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($isAvailable ? ($rec['file_name'] ?? 'Gravação') : 'Gravação CH' . $rec['channel']) ?></span>
                    </div>
                    <div class="timeline-time">
                        <?= fmt_brt($rec['time_start'], 'd/m/Y H:i:s') ?>
                        <?php if ($durTxt): ?>
                        · <?= $durTxt ?>
                        <?php endif; ?>
                        <?php if ($rec['file_size']): ?>
                        · <?= number_format($rec['file_size']/1024/1024, 1) ?> MB
                        <?php endif; ?>
                        <?php if ($rec['channel']): ?>
                        · CH<?= $rec['channel'] ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!$isAvailable && $rec['begin_c']): ?>
                <button class="btn btn-outline btn-sm pb-extract"
                        onclick="requestExtract(event, this, <?= $rec['channel'] ?>, '<?= $rec['begin_c'] ?>', '<?= $rec['end_c'] ?>')">
                    &#8681; Extrair
                </button>
                <?php endif; ?>
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
var selProtocol = <?= json_encode($selProtocol) ?>;

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
    // Interação do usuário cancela o auto-refresh (não interromper o play)
    if (window.__pbPoll) { clearTimeout(window.__pbPoll); window.__pbPoll = null; }
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

// Dispara um comando ao device via /sendcommand (fire-and-forget; keepalive
// para sobreviver à navegação do form)
function pbSendCmd(imei, proNo, contentObj) {
    var serverFlagId = (selProtoOf(imei) === 'JIMI') ? 1 : 0;
    fetch('/sendcommand', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
        keepalive: true,
        body: JSON.stringify({
            imei: imei,
            proNo: proNo,
            serverFlagId: serverFlagId,
            content: JSON.stringify(contentObj)
        })
    }).catch(function(){});
}

// Protocolo do device escolhido no select (data-proto); fallback: o da página
function selProtoOf(imei) {
    var sel = document.getElementById('pb-imei');
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === imei) return sel.options[i].dataset.proto || 'JTT';
    }
    return selProtocol || 'JTT';
}

// Formato de data JT/T: yyMMddHHmmss em GMT 0 (dias digitados são BRT/-03)
function jttUtcCompact(dayStr, endOfDay) {
    var d = new Date(dayStr + 'T' + (endOfDay ? '23:59:59' : '00:00:00') + '-03:00');
    if (isNaN(d.getTime())) return '';
    return fmtCompactUTC(d);
}

function fmtCompactUTC(d) {
    function p(n) { return String(n).padStart(2, '0'); }
    return String(d.getUTCFullYear()).slice(2) + p(d.getUTCMonth() + 1) + p(d.getUTCDate()) +
           p(d.getUTCHours()) + p(d.getUTCMinutes()) + p(d.getUTCSeconds());
}

// A consulta 37381 (0x9205) não aceita janela que cruza o dia (GMT-0):
// fatia o período BRT em segmentos por dia UTC (máx. 15 = ~2 semanas)
function utcDaySegments(fromDay, toDay) {
    var start = new Date(fromDay + 'T00:00:00-03:00');
    var end = new Date(toDay + 'T23:59:59-03:00');
    if (isNaN(start.getTime()) || isNaN(end.getTime()) || end < start) return [];
    var segs = [];
    var cur = start;
    while (cur <= end && segs.length < 15) {
        var dayEnd = new Date(Date.UTC(cur.getUTCFullYear(), cur.getUTCMonth(), cur.getUTCDate(), 23, 59, 59));
        var segEnd = dayEnd < end ? dayEnd : end;
        segs.push([fmtCompactUTC(cur), fmtCompactUTC(segEnd)]);
        cur = new Date(dayEnd.getTime() + 1000);
    }
    return segs;
}

function onSubmitRequest(e) {
    var imei = document.getElementById('pb-imei').value;
    var channel = Number(document.querySelector('select[name=channel]').value) || 1;
    var from = document.querySelector('input[name=date_from]').value;
    var to = document.querySelector('input[name=date_to]').value;
    if (!imei || !from || !to) return true;

    if (selProtoOf(imei) === 'JIMI') {
        // Protocolo JIMI não tem 0x9205 — mantém 0x8802 na janela inteira (legado)
        pbSendCmd(imei, 34818, {
            mediaType: 2, channel: channel, channelId: channel, eventCode: 0,
            beginTime: jttUtcCompact(from, false),
            endTime: jttUtcCompact(to, true)
        });
    } else {
        // proNo 37381 (0x9205): lista as gravações do cartão; resposta assíncrona
        // via /pushresourcelist. channel+channelId: exemplos da doc divergem.
        utcDaySegments(from, to).forEach(function(seg, i) {
            pbSendCmd(imei, 37381, {
                channel: channel, channelId: channel,
                beginTime: seg[0], endTime: seg[1],
                alarmFlag: 0, resourceType: 0, codeType: 0, storageType: 0,
                instructionID: 'pb' + Date.now() + '_' + i
            });
        });
    }
    return true;
}

// [Extrair]: proNo 34818 (0x8802) com a janela exata da gravação escolhida —
// o arquivo chega depois via /pushfileupload e o item vira "Disponível"
function requestExtract(ev, btn, channel, beginC, endC) {
    ev.stopPropagation();
    pbSendCmd(selImei, 34818, {
        mediaType: 2, channel: channel, channelId: channel, eventCode: 0,
        beginTime: beginC, endTime: endC
    });
    btn.disabled = true;
    btn.innerHTML = '&#10003; Solicitado';
}

<?php if ($requested): ?>
// A câmera responde ao 37381 em segundos, mas de forma assíncrona: recarrega a
// página algumas vezes (o comando NÃO é reenviado — só o form o dispara)
(function() {
    var params = new URLSearchParams(location.search);
    var poll = parseInt(params.get('poll') || '0');
    if (poll < 6) {
        window.__pbPoll = setTimeout(function() {
            params.set('poll', poll + 1);
            location.replace(location.pathname + '?' + params.toString());
        }, 8000);
    }
})();
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
