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
 *      Item "no cartão" → [Extrair] dispara proNo 37382 ("FTP file upload
 *      command") com a janela exata da gravação; a CÂMERA sobe o arquivo por
 *      FTP para o destino configurado em VIDEO_FTP_* e o IoTHub avisa por
 *      /pushftpfileupload → media_files, e o item vira reproduzível.
 *   3. Item "disponível" → play inline / download.
 *
 * ⚠️ Até a v4.9.0 o passo 2 mandava **34818**, que a doc chama de "Multimedia
 * data retrieval" — uma CONSULTA da família de fotos do JT/T 808. O IoTHub
 * aceitava, marcava `executed`, e arquivo nenhum aparecia: em três dias de
 * homologação, /pushfileupload e /pushftpfileupload não foram chamados uma vez.
 *
 * Modelos de protocolo JIMI (JC400D/AD) não suportam 0x9205 — mantém o envio
 * direto de 34818 na janela do filtro (comportamento legado).
 */

require_once __DIR__ . '/../includes/auth.php';
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

$devices = $db->prepare("
    SELECT d.imei, d.device_name, dm.model_name, dm.protocol,
           COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) AS camera_count,
           COALESCE(cu.name, '—') AS customer_name
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN customers cu ON cu.id = d.customer_id
    WHERE 1=1 {$scopeSql}
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

$recordings = [];
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

    // 1) Gravações que a câmera reportou no cartão (37381 → /pushresourcelist)
    //
    // ⚠️ v4.9.17 — SÓ LISTAGEM DENTRO DA VALIDADE. O cartão é buffer circular:
    // até aqui a tela mostrava listas de até 32 dias como se fossem atuais, e o
    // usuário clicava em download de arquivo havia muito sobrescrito. A idade
    // vem junto para a tela poder dizer "capturada há X" em vez de fingir que
    // é agora. ($ttl já vem definido acima, para o template também enxergá-lo.)
    $stmt = $db->prepare("
        SELECT id, resource_type, file_name, file_size, start_time, end_time, channel_id, alarm_type,
               captured_at
        FROM resource_lists
        WHERE imei = :imei
          AND (channel_id = :ch OR channel_id = 0 OR channel_id IS NULL)
          AND start_time <= :dt
          AND COALESCE(end_time, start_time) >= :df
          AND captured_at IS NOT NULL
          AND captured_at >= (NOW() - INTERVAL {$ttl} MINUTE)
        ORDER BY start_time DESC
        LIMIT 300
    ");
    $stmt->execute([':imei' => $selImei, ':ch' => $selChannel, ':df' => $utcFrom, ':dt' => $utcTo]);
    $resources = $stmt->fetchAll();

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
        <div style="margin-top:10px;display:flex;justify-content:flex-end;">
            <!-- A tela não tinha como baixar: só o player, que nem toca .ts.
                 Quem precisa da prova em vídeo precisa do arquivo na mão. -->
            <a id="pb-download" class="btn btn-outline btn-sm" style="display:none;" target="_blank" download>
                &#8681; Baixar arquivo
            </a>
        </div>
    </div>

    <!-- Filters + Timeline -->
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
                    <select id="pb-cust" onchange="location.href='?customer_id='+this.value"
                            style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
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
                    <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Equipamento</label>
                    <select name="imei" id="pb-imei" onchange="pbRebuildChannels()" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                        <?php foreach ($devices as $d): ?>
                        <option value="<?= $d['imei'] ?>" data-cam="<?= $d['camera_count']??1 ?>" data-proto="<?= htmlspecialchars($d['protocol'] ?? 'JTT') ?>" <?= $selImei===$d['imei']?'selected':'' ?>>
                            <?= $mostrarCliente ? htmlspecialchars($d['customer_name']) . ' · ' : '' ?><?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?> (<?= htmlspecialchars($d['model_name']??'?') ?>)
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

            <?php
            // ── Idade da listagem (v4.9.17) ─────────────────────────────────
            // O cartão é buffer circular; a lista é um RETRATO com validade
            // curta. Dizer a idade é o que separa "informação velha rotulada"
            // (útil) de "informação velha disfarçada de atual" (armadilha) —
            // a segunda é o que esta tela fazia até aqui.
            $capMin     = $capturaInfo['minutos'] ?? null;
            $capVencida = $capturaInfo['ultima'] !== null && $capMin !== null && $capMin > $ttl;
            ?>
            <?php if ($capturaInfo['ultima'] === null): ?>
                <div class="callout info" style="font-size:11px;margin-bottom:8px">
                    Este equipamento <strong>nunca teve o cartão listado</strong>.
                    Use <strong>Consultar gravações</strong> para pedir a lista à câmera.
                </div>
            <?php elseif ($capVencida): ?>
                <div class="callout" style="font-size:11px;margin-bottom:8px;background:#fdf6e3;border-left:3px solid #b45309;color:#7c4a03">
                    A última listagem foi feita
                    <strong>há <?= $capMin >= 1440 ? intdiv($capMin,1440).' dia(s)' : ($capMin >= 60 ? intdiv($capMin,60).' h' : $capMin.' min') ?></strong>
                    e <strong>venceu</strong> (validade: <?= (int)$ttl ?> min).
                    O cartão grava em ciclo — o que estava lá já pode ter sido
                    sobrescrito. <strong>Consulte novamente</strong> antes de baixar.
                </div>
            <?php else: ?>
                <div style="font-size:11px;color:var(--muted);margin-bottom:8px">
                    Listagem de <strong><?= $capMin < 1 ? 'agora' : 'há ' . (int)$capMin . ' min' ?></strong>
                    · vence em <?= max(0, $ttl - (int)$capMin) ?> min
                </div>
            <?php endif; ?>

            <?php if (empty($recordings)): ?>
            <div class="empty-state" style="padding:24px 12px;" id="pb-empty">
                <?php if ($capVencida): ?>
                <p>A listagem anterior venceu.</p>
                <p style="font-size:11px;margin-top:4px;">
                    Ela não é exibida de propósito: ofereceria download de arquivo
                    que provavelmente não está mais no cartão. Peça uma nova.
                </p>
                <?php else: ?>
                <p>Nenhuma gravação encontrada no período.</p>
                <p style="font-size:11px;margin-top:4px;">
                    A câmera responde à consulta em alguns segundos — esta página
                    atualiza sozinha. Se persistir, verifique se o equipamento está
                    online e se há cartão de memória.
                </p>
                <?php endif; ?>
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
// Base do FILELIST (JIMI): montada no SERVIDOR, porque depende de VIDEO_INGEST_IP
// — o endereço que o EQUIPAMENTO alcança, que não é o mesmo que o navegador usa.
var filelistBase = <?= json_encode(filelist_url_base()) ?>;

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

var pbPlayer = null;   // instância mpegts.js ativa (precisa ser destruída)

/** Encerra o player de TS, se houver. Sem isto cada clique vaza uma instância. */
function pbDestroyPlayer() {
    if (!pbPlayer) return;
    try { pbPlayer.pause(); pbPlayer.unload(); pbPlayer.detachMediaElement(); pbPlayer.destroy(); }
    catch (e) { /* já desmontado */ }
    pbPlayer = null;
}

function selectRecording(el, rec) {
    // Interação do usuário cancela o auto-refresh (não interromper o play)
    if (window.__pbPoll) { clearTimeout(window.__pbPoll); window.__pbPoll = null; }
    document.querySelectorAll('.timeline-item').forEach(function(t) { t.classList.remove('selected'); });
    el.classList.add('selected');

    var v = document.getElementById('vid-player');
    var ph = document.getElementById('vid-placeholder');
    var dl = document.getElementById('pb-download');
    var url = fileStorageUrl + rec.file_url;
    var ehTs = /\.ts(\?|$)/i.test(rec.file_url || '');

    pbDestroyPlayer();
    v.removeAttribute('src');
    v.removeAttribute('poster');

    // Baixar sempre que houver arquivo — inclusive quando o navegador não
    // souber tocar o formato, que é o caso comum aqui (.ts).
    if (dl) {
        dl.href = url;
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
        pbPlayer.play().catch(function() {});
        pbPlayer.on(mpegts.Events.ERROR, function(tipo, detalhe) {
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
        v.play().catch(function() {});
    } else if (rec.file_type === 'image' || rec.file_type === 'jpg' || rec.file_type === 'png') {
        ph.style.display = 'none';
        v.style.display = 'block';
        v.poster = url;
    } else {
        ph.innerHTML = '<div style="text-align:center;color:var(--muted-soft);"><i style="font-size:36px;display:block;margin-bottom:8px;opacity:.2;">&#128196;</i>' + (rec.file_name || 'Arquivo') + '</div>';
        ph.style.display = '';
        v.style.display = 'none';
    }
}

// Dispara um comando ao device via /sendcommand. O `cb` é opcional: a consulta
// 37381 é fire-and-forget (a resposta vem pelo /pushresourcelist), mas a
// extração 37382 precisa saber se o servidor recusou — sem destino FTP
// configurado ele devolve 503, e o usuário tem de ver isso.
function pbSendCmd(imei, proNo, contentObj, cb) {
    var serverFlagId = (selProtoOf(imei) === 'JIMI') ? 1 : 0;
    fetch('/sendcommand', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
        keepalive: true,
        body: JSON.stringify({
            imei: imei,
            proNo: proNo,
            serverFlagId: serverFlagId,
            // Comando de texto (proNo 128, família JIMI) vai CRU. Passá-lo por
            // JSON.stringify mandaria `"FILELIST,http://..."` com aspas, e a
            // câmera receberia um comando que não existe.
            content: (typeof contentObj === 'string') ? contentObj : JSON.stringify(contentObj)
        })
    }).then(function (r) {
        if (!cb) return;
        return r.json().catch(function () { return {}; }).then(function (j) {
            cb(r.ok && (j.code === 0 || j.code === undefined), j.msg);
        });
    }).catch(function (e) {
        if (cb) cb(false, 'Falha de rede ao falar com o servidor.');
    });
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
        // ── FILELIST (v4.9.18) ──────────────────────────────────────────────
        //
        // 🔴 Aqui ia **34818** até a v4.9.17, e ele NUNCA funcionou para JIMI.
        // É comando JT/T (0x8802, "multimedia data retrieval"): o IoT Hub
        // aceitava, a tela dizia "consultando", e a câmera nem respondia —
        // os comandos ficavam em `sent`, nunca `executed`. 18 tentativas
        // registradas antes de alguém desconfiar, e `resource_lists` tem
        // 1.321 linhas, ZERO de JIMI.
        //
        // No JIMI a listagem é ao contrário: em vez de responder a uma
        // consulta com janela, a câmera SOBE um TXT com a lista inteira para
        // a URL que mandamos. Por isso não há beginTime/endTime aqui — o
        // comando não aceita intervalo, e o filtro de data continua valendo
        // só na exibição.
        //
        // Comando de TEXTO (proNo 128), não JSON.
        pbSendCmd(imei, 128, 'FILELIST,' + filelistBase + imei);
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

// [Extrair]: proNo 37382 — "FTP file upload command" (v4.9.1).
//
// Era 34818, que a doc oficial chama de "Multimedia data retrieval": uma
// CONSULTA, e da família de fotos do JT/T 808. Ela era aceita pelo IoTHub,
// marcada `executed`, e não produzia arquivo nenhum — nem callback. O comando
// que faz a câmera SUBIR o vídeo é o 37382, e a doc é explícita quanto à
// sequência: 37381 primeiro, para obter beginTime/endTime, depois 37382.
//
// Os campos de FTP (serverAddress/ftpPort/userName/password/fileUploadPath)
// NÃO vão daqui: `sendcommand.php` os injeta no servidor. Mandar a senha do
// FTP pelo JavaScript a exporia no código-fonte da página.
function requestExtract(ev, btn, channel, beginC, endC) {
    ev.stopPropagation();
    btn.disabled = true;
    btn.innerHTML = '&#8230; Enviando';

    pbSendCmd(selImei, 37382, {
        channel: channel, channelId: channel,
        beginTime: beginC, endTime: endC,
        alarmFlag: 0,
        resourceType: 2,   // 2 = vídeo
        codeType: 0,       // 0 = fluxo principal ou secundário
        storageType: 0     // 0 = principal ou de backup
    }, function (ok, msg) {
        // Ao contrário do resto da tela, este retorno é ESPERADO: sem destino
        // FTP configurado o servidor devolve 503, e o usuário precisa ver isso
        // em vez de ficar esperando um arquivo que nunca vem.
        if (ok) {
            btn.innerHTML = '&#10003; Solicitado';
        } else {
            btn.disabled = false;
            btn.innerHTML = '&#8681; Extrair';
            alert(msg || 'Não foi possível solicitar a extração.');
        }
    });
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
