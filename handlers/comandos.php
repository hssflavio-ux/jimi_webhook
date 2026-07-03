<?php
/**
 * JIMI Webhook System — Comandos v3.1.0
 * Endpoint: /comandos
 *
 * Tela dedicada de envio de comandos com:
 * - Seletor de dispositivo que auto-detecta protocolo/modelo
 * - Presets sensíveis ao modelo (JIMI vs JT/T)
 * - Polling ativo pós-envio (3s rápido → 10s lento → timeout 5min)
 * - Histórico de comandos com status
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_customer_id();
$db = Database::getInstance()->getConnection();
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo');

function fmt_brt_cmd($dt) {
    global $tz_utc, $tz_brt;
    if (!$dt) return '-';
    $d = new DateTime($dt, $tz_utc);
    $d->setTimezone($tz_brt);
    return $d->format('d/m/Y H:i:s');
}

$dashToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';

$devices = $db->query("
    SELECT d.imei, d.device_name, COALESCE(dm.model_name, d.device_model, '-') AS model_display,
           COALESCE(dm.protocol, 'JIMI') AS protocol, COALESCE(dm.camera_count, 1) AS camera_count
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    WHERE d.customer_id = $customer_id AND d.is_active = 1
    ORDER BY d.device_name
")->fetchAll(PDO::FETCH_ASSOC);

$commands = $db->query("
    SELECT c.id, c.imei, c.command_content, c.status, c.response_payload, c.created_at, c.updated_at,
           d.device_name
    FROM commands c
    JOIN devices d ON c.imei = d.imei
    WHERE d.customer_id = $customer_id
    ORDER BY c.created_at DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$deviceJson = json_encode($devices);
$page_title    = 'Comandos';
$current_route = 'comandos';

$extra_head = '<style>
.poll-bar { display: none; padding: 10px 14px; border-radius: var(--radius-sm); margin: 12px 0; font-size: 13px; gap: 10px; flex-direction: column; }
.poll-bar.active { display: flex; border: 1px solid #d0dff0; color: #5a7fa8; }
.poll-bar.success { display: flex; background: #f0faf5; border: 1px solid #d4f0e2; color: var(--success); }
.poll-bar.failed { display: flex; background: #fef2f5; border: 1px solid #fce4eb; color: var(--error); }
.poll-bar.timeout { display: flex; background: #fdf3e8; border: 1px solid #fce8d0; color: var(--warning); }
.poll-bar-header { display: flex; align-items: center; gap: 10px; }
.poll-spinner { width: 16px; height: 16px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: spin .6s linear infinite; flex-shrink: 0; }
@keyframes spin { to { transform: rotate(360deg); } }
.poll-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.poll-response { margin-top: 8px; padding: 8px 10px; border-radius: var(--radius-sm); background: rgba(0,0,0,.04); font-family: "JetBrains Mono", monospace; font-size: 12px; white-space: pre-wrap; word-break: break-all; max-height: 200px; overflow-y: auto; color: var(--ink); }
</style>';

include __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <!-- Envio de Comando -->
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">Enviar Comando</h4>
        <form id="cmd-form" onsubmit="sendCommand(event)">
            <div class="form-group">
                <label for="cmd-imei">Dispositivo</label>
                <select id="cmd-imei" name="imei" onchange="onDeviceSelect()" required>
                    <option value="">Selecione o dispositivo</option>
                    <?php foreach ($devices as $d): ?>
                    <option value="<?= $d['imei'] ?>" data-protocol="<?= $d['protocol'] ?>" data-cameras="<?= $d['camera_count'] ?>" data-model="<?= htmlspecialchars($d['model_display']) ?>">
                        <?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?> (<?= htmlspecialchars($d['model_display']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="protocol-group" style="display:none">
                <label>Protocolo Detectado</label>
                <span id="protocol-badge" style="font-size:13px;font-weight:500"></span>
            </div>

            <!-- Controles dinâmicos (preenchidos por JS) -->
            <div id="dynamic-controls"></div>

            <input type="hidden" name="token" value="<?= htmlspecialchars($dashToken) ?>">
            <input type="hidden" id="cmd-proNo" name="proNo" value="128">
            <input type="hidden" id="cmd-serverFlagId" name="serverFlagId" value="1">

            <div id="cmd-feedback" style="font-size:13px;margin:8px 0"></div>

            <!-- Polling Bar -->
            <div id="poll-bar" class="poll-bar">
                <div class="poll-bar-header">
                    <div id="poll-spinner"></div>
                    <span id="poll-text">Comando enviado. Aguardando resposta...</span>
                </div>
                <div id="poll-response" class="poll-response" style="display:none"></div>
            </div>

            <button type="submit" id="cmd-submit" class="btn btn-primary" disabled>Selecione um dispositivo</button>
        </form>
    </div>

    <!-- Histórico -->
    <div class="card">
        <div class="flex-between" style="margin-bottom:12px">
            <h4 style="font-size:14px;font-weight:600;color:var(--ink)">Histórico de Comandos</h4>
            <span style="font-size:12px;color:var(--muted)">Últimos 50</span>
        </div>
        <div style="max-height:500px;overflow-y:auto" id="cmd-history">
            <table style="font-size:12px;width:100%">
                <thead><tr><th>Data</th><th>IMEI</th><th>Comando</th><th>Status</th><th>Resposta</th></tr></thead>
                <tbody>
                    <?php foreach ($commands as $c):
                        $statusBadge = $c['status'] === 'executed' ? 'badge-success' :
                            ($c['status'] === 'failed' ? 'badge-error' :
                            ($c['status'] === 'sent' ? 'badge-info' : ''));
                        $cmdPreview = json_decode($c['command_content'], true);
                        if (is_array($cmdPreview)) $cmdPreview = json_encode($cmdPreview, JSON_UNESCAPED_UNICODE);
                        else $cmdPreview = $c['command_content'];
                        // Extrair preview da resposta
                        $respPreview = '-';
                        if (!empty($c['response_payload'])) {
                            $respDecoded = json_decode($c['response_payload'], true);
                            if (is_array($respDecoded)) {
                                if (isset($respDecoded['resultContent'])) {
                                    $respPreview = $respDecoded['resultContent'];
                                } elseif (isset($respDecoded['content'])) {
                                    $respPreview = $respDecoded['content'];
                                } elseif (isset($respDecoded['msg'])) {
                                    $respPreview = $respDecoded['msg'];
                                } elseif (isset($respDecoded['message'])) {
                                    $respPreview = $respDecoded['message'];
                                } else {
                                    $respPreview = json_encode($respDecoded, JSON_UNESCAPED_UNICODE);
                                }
                            } else {
                                $respPreview = (string)$c['response_payload'];
                            }
                        }
                    ?>
                    <tr style="cursor:pointer" onclick="showDetail(<?= $c['id'] ?>)">
                        <td style="white-space:nowrap"><?= fmt_brt_cmd($c['created_at']) ?></td>
                        <td class="text-mono" style="font-size:10px"><?= htmlspecialchars($c['imei']) ?></td>
                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px"><?= htmlspecialchars(is_string($cmdPreview) ? $cmdPreview : '') ?></td>
                        <td><span class="badge <?= $statusBadge ?>"><?= $c['status'] ?></span></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px" title="<?= htmlspecialchars($respPreview) ?>"><?= htmlspecialchars(substr($respPreview, 0, 60)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($commands)): ?>
                    <tr><td colspan="5"><div class="empty-state"><p>Nenhum comando enviado.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Detalhe -->
<div id="detail-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);z-index:1000;align-items:center;justify-content:center" onclick="this.style.display='none'">
    <div class="card" style="max-width:600px;width:90%;max-height:80vh;overflow-y:auto" onclick="event.stopPropagation()">
        <div class="flex-between" style="margin-bottom:12px">
            <h4 style="font-size:14px;font-weight:600;color:var(--ink)">Detalhe do Comando</h4>
            <button class="btn btn-outline btn-sm" onclick="document.getElementById('detail-modal').style.display='none'">Fechar</button>
        </div>
        <div id="detail-content"></div>
    </div>
</div>

<script>
var devices = <?= $deviceJson ?>;
var dashToken = <?= json_encode($dashToken) ?>;
var pollTimer = null;
var pollPhase = 0;
var pollCount = 0;

var jimiPresets = {
    '': 'Selecione um comando...',
    'STATUS': 'STATUS',
    'VERSION#': 'VERSION#',
    'IMEI': 'IMEI',
    'GPSON': 'GPSON',
    'GPSOFF': 'GPSOFF',
    'RTMP,ON,OUT': 'RTMP,ON,OUT',
    'RTMP,ON,IN': 'RTMP,ON,IN',
    'RTMP,ON,INOUT': 'RTMP,ON,INOUT',
    'RTMP,OFF': 'RTMP,OFF',
    'RESET': 'RESET',
    'FORMAT': 'FORMAT',
    'APN': 'APN'
};

var jttPresets = {
    'streaming':     { proNo: 37121, content: '{"channelId":1,"mediaType":0,"streamType":0}', label: 'Streaming (CH1)' },
    'video_upload':  { proNo: 128,   content: '{"channelId":1,"beginTime":"","endTime":"","mediaType":0,"eventCode":0}', label: 'Upload de Vídeo' },
    'resources':     { proNo: 37381, content: '{"channelId":1,"beginTime":"","endTime":"","mediaType":0,"eventCode":0}', label: 'Listar Recursos' },
    'playback':      { proNo: 37377, content: '{"channelId":1,"beginTime":"","endTime":"","mediaType":0,"eventCode":0,"playbackType":0,"speed":1}', label: 'Playback' },
    'ftp_upload':    { proNo: 37382, content: '{"channelId":1,"beginTime":"","endTime":"","mediaType":0,"eventCode":0}', label: 'Upload FTP' },
    'alarm_ack':     { proNo: 33283, content: '{"alarmSerialNo":0}', label: 'Confirmar Alarme' },
    'tts':           { proNo: 33536, content: '{"text":"","volume":5}', label: 'TTS (Voz)' },
    'photo':         { proNo: 34817, content: '{"channelId":1,"photoType":0}', label: 'Foto' },
    'query_all':     { proNo: 33028, content: '{}', label: 'Consultar Parâmetros' },
    'set_param':     { proNo: 33027, content: '{"paramId":0,"paramValue":""}', label: 'Definir Parâmetro' },
    'device_info':   { proNo: 33031, content: '{}', label: 'Info Dispositivo' }
};

function onDeviceSelect() {
    var sel = document.getElementById('cmd-imei');
    var opt = sel.options[sel.selectedIndex];
    var protocol = opt.getAttribute('data-protocol') || 'JIMI';
    var cameras = parseInt(opt.getAttribute('data-cameras')) || 1;
    var submitBtn = document.getElementById('cmd-submit');

    document.getElementById('protocol-group').style.display = 'block';
    var badge = document.getElementById('protocol-badge');
    if (protocol === 'JIMI') {
        badge.innerHTML = '<span style="background:#e8f5ef;color:var(--success);padding:3px 8px;border-radius:9999px;font-size:12px">JIMI</span> ' +
                         '<span style="font-size:12px;color:var(--muted)">(proNo 128)</span>';
        document.getElementById('cmd-proNo').value = '128';
        document.getElementById('cmd-serverFlagId').value = '1';
        buildJimiControls(cameras);
    } else {
        badge.innerHTML = '<span style="background:#eef4fa;color:#5a7fa8;padding:3px 8px;border-radius:9999px;font-size:12px">JT/T</span> ' +
                         '<span style="font-size:12px;color:var(--muted)">(' + cameras + ' câmera' + (cameras > 1 ? 's' : '') + ')</span>';
        document.getElementById('cmd-serverFlagId').value = '0';
        buildJttControls(cameras);
    }
    submitBtn.disabled = false;
    submitBtn.textContent = 'Enviar Comando';
}

function buildJimiControls(cameras) {
    var html = '<div class="form-group"><label>Comando Predefinido</label><select id="jimi-preset" onchange="fillJimiPreset()">';
    for (var key in jimiPresets) {
        html += '<option value="' + key + '">' + jimiPresets[key] + '</option>';
    }
    html += '</select></div>';
    html += '<div class="form-group"><label>Conteúdo do Comando</label>';
    html += '<textarea id="cmd-content" name="content" rows="2" placeholder="Ex: STATUS" required></textarea></div>';
    document.getElementById('dynamic-controls').innerHTML = html;
}

function buildJttControls(cameras) {
    var html = '<div class="form-group"><label>Comando Predefinido</label><select id="jtt-preset" onchange="fillJttPreset()">';
    html += '<optgroup label="JT/T (JSON)">';
    for (var key in jttPresets) {
        html += '<option value="' + key + '">' + jttPresets[key].label + '</option>';
    }
    html += '</optgroup>';
    // Câmeras JT/T também aceitam comandos de texto via proNo 128 (docs test.html §2.2.2)
    html += '<optgroup label="Texto (proNo 128)">';
    for (var txt in jimiPresets) {
        if (txt === '') continue;
        html += '<option value="txt:' + txt + '">' + jimiPresets[txt] + '</option>';
    }
    html += '</optgroup></select></div>';
    html += '<div class="form-group"><label>proNo</label>';
    html += '<input type="number" id="cmd-proNo-jtt" value="37121" onchange="document.getElementById(\'cmd-proNo\').value=this.value"></div>';
    html += '<div class="form-group"><label id="jtt-content-label">Parâmetros (JSON)</label>';
    html += '<textarea id="cmd-content-jtt" name="content" rows="3" placeholder=\'{"channelId":1,"mediaType":0}\' style="font-family:\'JetBrains Mono\',monospace;font-size:13px" required></textarea></div>';
    document.getElementById('dynamic-controls').innerHTML = html;
    document.getElementById('cmd-proNo').value = '37121';
}

function fillJimiPreset() {
    document.getElementById('cmd-content').value = document.getElementById('jimi-preset').value;
}
function fillJttPreset() {
    var val = document.getElementById('jtt-preset').value;
    var label = document.getElementById('jtt-content-label');
    if (val.indexOf('txt:') === 0) {
        // Comando de texto (proNo 128): conteúdo plano, sem validação JSON no backend
        document.getElementById('cmd-proNo-jtt').value = '128';
        document.getElementById('cmd-proNo').value = '128';
        document.getElementById('cmd-content-jtt').value = val.substring(4);
        if (label) label.textContent = 'Conteúdo do Comando (texto)';
        return;
    }
    var p = jttPresets[val];
    if (p) {
        document.getElementById('cmd-proNo-jtt').value = p.proNo;
        document.getElementById('cmd-proNo').value = p.proNo;
        // p.content já é uma string JSON — apenas formata (stringify direto geraria string quotada: "{}")
        var pretty;
        try { pretty = JSON.stringify(JSON.parse(p.content), null, 2); }
        catch (e) { pretty = p.content; }
        document.getElementById('cmd-content-jtt').value = pretty;
        if (label) label.textContent = 'Parâmetros (JSON)';
    }
}

function sendCommand(e) {
    e.preventDefault();
    clearPolling();

    var imei = document.getElementById('cmd-imei').value;
    var proNo = document.getElementById('cmd-proNo').value;
    var serverFlagId = document.getElementById('cmd-serverFlagId').value;
    var content = document.getElementById('cmd-content') ? document.getElementById('cmd-content').value :
                  (document.getElementById('cmd-content-jtt') ? document.getElementById('cmd-content-jtt').value : '');

    if (!imei || !content) return;

    document.getElementById('cmd-feedback').innerHTML = '<span style="color:var(--muted)">Enviando...</span>';
    document.getElementById('cmd-submit').disabled = true;

    fetch('/sendcommand', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Dashboard-Token': dashToken },
        body: JSON.stringify({ imei: imei, proNo: parseInt(proNo), content: content, serverFlagId: parseInt(serverFlagId) })
    }).then(function(r) { return r.json(); })
    .then(function(data) {
        document.getElementById('cmd-submit').disabled = false;
        if (data.code === 0) {
            document.getElementById('cmd-feedback').innerHTML = '<span style="color:var(--success)">Comando enviado! ID #' + data.command_id + '</span>';
            startPolling(data.command_id);
        } else {
            // Mostrar mensagem detalhada do IoTHub ou erro genérico
            var errMsg = data.iothub_msg || data.msg || 'Erro desconhecido';
            var errDetail = '';
            if (data.iothub_code !== undefined && data.iothub_code !== 0) {
                errDetail = ' (código IoTHub: ' + data.iothub_code + ')';
            }
            if (data.http_status === 0 || data.http_status === undefined) {
                errDetail += ' — IoTHub inacessível ou equipamento offline';
            }
            document.getElementById('cmd-feedback').innerHTML = '<span style="color:var(--error)">Erro: ' + errMsg + errDetail + '</span>';
            // Se tiver command_id mesmo com erro, permitir ver no histórico
            if (data.command_id) {
                document.getElementById('cmd-feedback').innerHTML += '<br><span style="font-size:12px;color:var(--muted)">Comando registrado como #' + data.command_id + ' — status: falha</span>';
            }
        }
    }).catch(function(err) {
        document.getElementById('cmd-submit').disabled = false;
        document.getElementById('cmd-feedback').innerHTML = '<span style="color:var(--error)">Erro de rede: não foi possível contactar o servidor</span>';
    });
}

function startPolling(commandId) {
    clearPolling();
    pollCount = 0;
    pollPhase = 0; // 0 = 3s, 1 = 10s
    var bar = document.getElementById('poll-bar');
    var text = document.getElementById('poll-text');
    var spinner = document.getElementById('poll-spinner');
    var respDiv = document.getElementById('poll-response');

    bar.className = 'poll-bar active';
    spinner.innerHTML = '<div class="poll-spinner"></div>';
    text.textContent = 'Comando #' + commandId + ' enviado. Aguardando resposta do dispositivo...';
    respDiv.style.display = 'none';
    respDiv.textContent = '';

    function showResponse(responseText) {
        if (responseText && responseText !== '-' && responseText !== 'null') {
            respDiv.textContent = responseText;
            respDiv.style.display = 'block';
        }
    }

    function poll() {
        pollCount++;
        fetch('/commandstatus?command_id=' + commandId, { headers: { 'X-Dashboard-Token': dashToken } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var cmd = null;
            if (data.commands) {
                for (var i = 0; i < data.commands.length; i++) {
                    if (data.commands[i].id == commandId) { cmd = data.commands[i]; break; }
                }
            }
            if (!cmd) {
                if (pollPhase === 2) {
                    bar.className = 'poll-bar timeout';
                    spinner.innerHTML = '<div class="poll-dot" style="background:var(--warning)"></div>';
                    text.textContent = '⏱ Timeout (5 min). Comando #' + commandId + ' em fila offline. Resposta chegará quando o dispositivo conectar.';
                } else {
                    pollTimer = setTimeout(poll, pollPhase === 0 ? 3000 : 10000);
                    if (pollPhase === 0 && pollCount >= 10) pollPhase = 1;
                    else if (pollPhase === 1 && pollCount >= 10 + 30) pollPhase = 2;
                }
                return;
            }

            if (cmd.status === 'executed') {
                bar.className = 'poll-bar success';
                spinner.innerHTML = '<div class="poll-dot" style="background:var(--success)"></div>';
                text.textContent = '✓ Resposta recebida! Comando #' + commandId + ' executado com sucesso.';
                showResponse(cmd.response);
            } else if (cmd.status === 'failed') {
                bar.className = 'poll-bar failed';
                spinner.innerHTML = '<div class="poll-dot" style="background:var(--error)"></div>';
                text.textContent = '✗ Falha no comando #' + commandId + '.';
                // Mostrar a resposta de erro (pode conter detalhes do IoTHub ou do dispositivo)
                var failResp = cmd.response;
                if (!failResp || failResp === '-') {
                    // Tentar montar mensagem a partir do status
                    failResp = 'O comando foi rejeitado pelo IoTHub ou o equipamento está offline.';
                }
                showResponse(failResp);
            } else if (pollPhase === 2) {
                bar.className = 'poll-bar timeout';
                spinner.innerHTML = '<div class="poll-dot" style="background:var(--warning)"></div>';
                text.textContent = '⏱ Timeout (5 min). Comando #' + commandId + ' em fila offline. Resposta chegará quando o dispositivo conectar.';
            } else {
                // continue polling
                text.textContent = 'Aguardando resposta #' + commandId + '... (' + pollCount + ' tentativa' + (pollCount>1?'s':'') + ')';
                pollTimer = setTimeout(poll, pollPhase === 0 ? 3000 : 10000);
                // phase transition
                if (pollPhase === 0 && pollCount >= 10) pollPhase = 1; // after 30s switch to 10s
                else if (pollPhase === 1 && pollCount >= 10 + 30) pollPhase = 2; // after 5min, timeout
            }
        })
        .catch(function() {
            // Falha de rede transitória: re-agenda sem matar o polling
            if (pollPhase < 2) pollTimer = setTimeout(poll, 10000);
        });
    }
    pollTimer = setTimeout(poll, 3000);
}

function clearPolling() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    document.getElementById('poll-bar').className = 'poll-bar';
    document.getElementById('poll-text').textContent = '';
    document.getElementById('poll-response').style.display = 'none';
    document.getElementById('poll-response').textContent = '';
}

function showDetail(cmdId) {
    fetch('/commandstatus?command_id=' + cmdId, { headers: { 'X-Dashboard-Token': dashToken } })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var cmds = data.commands || [];
        if (!cmds.length) return;
        var c = cmds[0];
        var html = '<table style="font-size:13px;width:100%">';
        html += '<tr><td style="color:var(--muted);padding:4px 0;width:80px">ID</td><td style="font-family:JetBrains Mono,monospace;font-size:12px">' + c.id + '</td></tr>';
        html += '<tr><td style="color:var(--muted);padding:4px 0">IMEI</td><td style="font-family:JetBrains Mono,monospace;font-size:12px">' + c.imei + '</td></tr>';
        html += '<tr><td style="color:var(--muted);padding:4px 0">Status</td><td>' + c.status + '</td></tr>';
        html += '<tr><td style="color:var(--muted);padding:4px 0">Enviado</td><td>' + (c.created || '-') + '</td></tr>';
        html += '<tr><td style="color:var(--muted);padding:4px 0">Atualizado</td><td>' + (c.updated || '-') + '</td></tr>';
        html += '<tr><td style="color:var(--muted);padding:4px 0">Comando</td><td><pre style="font-family:JetBrains Mono,monospace;font-size:11px;background:var(--canvas);padding:8px;border-radius:var(--radius-sm);max-height:200px;overflow:auto">' + (typeof c.command === 'string' ? c.command : JSON.stringify(c.command, null, 2)) + '</pre></td></tr>';
        html += '<tr><td style="color:var(--muted);padding:4px 0">Resposta</td><td><pre style="font-family:JetBrains Mono,monospace;font-size:11px;background:var(--canvas);padding:8px;border-radius:var(--radius-sm);max-height:200px;overflow:auto">' + (c.response ? (typeof c.response === 'string' ? c.response : JSON.stringify(c.response, null, 2)) : '-') + '</pre></td></tr>';
        html += '</table>';
        document.getElementById('detail-content').innerHTML = html;
        document.getElementById('detail-modal').style.display = 'flex';
    });
}
</script>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
