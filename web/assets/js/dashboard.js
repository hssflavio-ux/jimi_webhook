/**
 * JIMI IoT Dashboard - Client JS v2.0.0
 *
 * Variáveis globais esperadas (definidas no PHP antes deste script):
 *   DASH_TOKEN  — token de autenticação do dashboard
 *
 * Endpoints AJAX (via .htaccess → handlers/):
 *   GET  ../camerasdata   — status API + dispositivos (JSON)
 *   GET  ../commandstatus — histórico de comandos (JSON)
 *   POST ../sendcommand   — envio de comando
 */

// ── Caminhos relativos ao diretório web/ ─────────────────────────────────────
const URL_SEND    = '../sendcommand';
const URL_STATUS  = '../commandstatus';
const URL_CAMERAS = '../camerasdata';

const hdrs = { 'X-Dashboard-Token': DASH_TOKEN };

// ═══════════════════════════════════════════════════════════════════════════════
// Utilitários
// ═══════════════════════════════════════════════════════════════════════════════
function esc(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function pulse() {
    const dot = document.getElementById('refreshDot');
    if (dot) {
        dot.classList.add('pulsing');
        setTimeout(() => dot.classList.remove('pulsing'), 600);
    }
}

function showFeedback(type, msg) {
    const el = document.getElementById('cmdFeedback');
    if (!el) return;
    el.className = `alert alert-${type} mb-3 py-2 small`;
    el.textContent = msg;
}

function showToast(type, msg, delay = 5000) {
    const toast = document.getElementById('videoToast');
    if (!toast) return;
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    document.getElementById('videoToastMsg').textContent = msg;
    new bootstrap.Toast(toast, { delay }).show();
}

/**
 * Formata JSON ou string para exibição legível.
 * Tenta parsear como JSON; se falhar, retorna a string original.
 */
function prettyJson(val) {
    if (!val || val === '—') return val;
    try {
        const obj = typeof val === 'object' ? val : JSON.parse(val);
        return JSON.stringify(obj, null, 2);
    } catch {
        return String(val);
    }
}

/**
 * Gera timestamp no formato JTT (yyMMddHHmmss) com offset de dias.
 * Ex: jttDateNow(-7) = data/hora UTC de 7 dias atrás no formato "260101000000"
 * O formato ISO "2024-01-01 00:00:00" é INVÁLIDO para comandos JTT.
 */
function jttDateNow(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    const yy = String(d.getUTCFullYear()).slice(2);
    const MM = String(d.getUTCMonth() + 1).padStart(2, '0');
    const dd = String(d.getUTCDate()).padStart(2, '0');
    const HH = String(d.getUTCHours()).padStart(2, '0');
    const mm = String(d.getUTCMinutes()).padStart(2, '0');
    const ss = String(d.getUTCSeconds()).padStart(2, '0');
    return `${yy}${MM}${dd}${HH}${mm}${ss}`;
}

// ═══════════════════════════════════════════════════════════════════════════════
// Refresh silencioso de Dispositivos + Status API (substitui location.reload)
// ═══════════════════════════════════════════════════════════════════════════════
async function refreshDevices() {
    pulse();
    try {
        const resp = await fetch(URL_CAMERAS, { headers: hdrs });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.code !== 0) return;

        // Atualiza badge de status da API na navbar
        const badge = document.getElementById('apiStatusBadge');
        if (badge) {
            badge.className = `badge bg-${data.apiStatus.color} d-flex align-items-center px-3 py-2`;
            const lbl = badge.querySelector('#apiStatusLabel');
            if (lbl) lbl.textContent = data.apiStatus.label;
        }
        const lastEl = document.getElementById('apiStatusLast');
        if (lastEl) lastEl.textContent = data.apiStatus.last;

        // Atualiza contador de dispositivos
        const countBadge = document.getElementById('devicesCount');
        if (countBadge) countBadge.textContent = data.count;

        // Reconstrói tabela de dispositivos
        const tbody = document.getElementById('devicesBody');
        if (!tbody) return;

        if (!data.devices || !data.devices.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">Nenhum dispositivo registrado.</td></tr>';
            return;
        }

        tbody.innerHTML = data.devices.map(d => {
            const mapBtn = d.has_gps
                ? `<a href="${esc(d.map_url)}" target="_blank" class="btn btn-sm btn-outline-primary py-0">
                       <i class="bi bi-geo-alt-fill"></i> Localizar
                   </a>`
                : `<span class="text-muted small"><i class="bi bi-geo-alt"></i> Sem GPS</span>`;
            return `<tr>
                <td>
                    <div class="fw-bold">${esc(d.name)}</div>
                    <small class="text-muted font-monospace">${esc(d.imei)}</small>
                </td>
                <td><span class="badge bg-${esc(d.ign_class)}">${esc(d.ign_status)}</span></td>
                <td><i class="bi bi-speedometer2 text-secondary"></i> ${d.speed} km/h</td>
                <td>${mapBtn}</td>
                <td class="text-end font-monospace small">${esc(d.last_comm)}</td>
            </tr>`;
        }).join('');

    } catch (e) {
        console.warn('refreshDevices:', e.message);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Toggle protocolo JIMI / JTT
// ═══════════════════════════════════════════════════════════════════════════════
document.querySelectorAll('input[name="proto"]').forEach(r => {
    r.addEventListener('change', function () {
        document.getElementById('secJimi').style.display = this.value === 'jimi' ? '' : 'none';
        document.getElementById('secJtt').style.display  = this.value === 'jtt'  ? '' : 'none';
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// Presets JIMI
// ═══════════════════════════════════════════════════════════════════════════════
function applyJimiPreset(val) {
    if (val) document.getElementById('jimiContent').value = val;
}

// ═══════════════════════════════════════════════════════════════════════════════
// Presets JTT (11 presets — alinhados com dashboard_template.php)
// BUG #5 FIX: dataType, codeStreamType e videoUDPPort devem ser int (0), não string
// BUG #6 FIX: proNo 37381/37377/37382 usam formato JTT = yyMMddHHmmss
// ═══════════════════════════════════════════════════════════════════════════════
const JTT_PRESETS = {
    // proNo 37121 — Streaming em tempo real
    '37121|ch1': [37121, JSON.stringify({
        dataType: 0, codeStreamType: 0, channel: "1",
        videoIP: "189.22.240.43", videoTCPPort: "10002", videoUDPPort: 0
    })],
    '37121|ch2': [37121, JSON.stringify({
        dataType: 0, codeStreamType: 0, channel: "2",
        videoIP: "189.22.240.43", videoTCPPort: "10002", videoUDPPort: 0
    })],
    '37121|ch12': [37121, JSON.stringify({
        dataType: 0, codeStreamType: 0, channel: "1-2",
        videoIP: "189.22.240.43", videoTCPPort: "10002", videoUDPPort: 0
    })],

    // proNo 128 — Comando texto para JTT (VIDEOUPLOAD)
    '128|videoupload': [128, 'VIDEOUPLOAD,189.22.240.43,23010,ALARM_LABEL_AQUI,1-2-3'],

    // proNo 37381 — Listar recursos de áudio/vídeo
    '37381|list': [37381, JSON.stringify({
        beginTime: jttDateNow(-7),
        endTime:   jttDateNow(0),
        mediaType: 0,
        channelId: 1,
        eventCode: 0
    })],

    // proNo 37377 — Playback de vídeo histórico
    '37377|playback': [37377, JSON.stringify({
        serverLen: 15,
        serverAddress: "189.22.240.43",
        tcpPort: 10003,
        udpPort: 0,
        channel: 1,
        resourceType: 0,
        codeType: 0,
        storageType: 0,
        playMethod: 0,
        forwardRewind: 0,
        beginTime: jttDateNow(-1),
        endTime: jttDateNow(0),
        instructionID: "playback_" + Date.now()
    })],

    // proNo 33283 — Ack manual de alarme
    '33283|ack': [33283, JSON.stringify({ alarmSerialNo: 0, type: 0 })],

    // proNo 37382 — Upload de arquivo por FTP
    '37382|ftp': [37382, JSON.stringify({
        serverAddress: "189.22.240.43",
        serverPort: 21,
        userName: "ftp_user",
        password: "Jimi@371##",
        path: "/",
        beginTime: jttDateNow(-1),
        endTime: jttDateNow(0),
        channelNo: 1,
        fileType: 0,
        storageType: 0,
        codeType: 0,
        instructionID: "ftp_" + Date.now()
    })],

    // proNo 33536 — Texto para Voz (TTS)
    '33536|tts': [33536, JSON.stringify({
        flag: 0,
        text: "Atenção, mensagem do sistema"
    })],

    // proNo 34817 — Foto instantânea da câmera
    '34817|foto': [34817, JSON.stringify({
        channel: 1,
        photoCmd: 1,
        timeInterval: 0,
        saveFlag: 0,
        resolution: 0x04,
        quality: 5,
        light: 128,
        contrast: 60,
        saturability: 60,
        chroma: 128
    })],

    // proNo 34818 — Consultar mídia armazenada no dispositivo
    '34818|midia': [34818, JSON.stringify({
        mediaType: 2,
        channel: 1,
        eventCode: 0,
        beginTime: jttDateNow(-7),
        endTime: jttDateNow(0)
    })],

    // proNo 33028 — Consultar todos os parâmetros do dispositivo
    '33028|params': [33028, '""'],

    // proNo 33030 — Consultar parâmetros específicos (ex: heartbeat, intervalo)
    '33030|params_esp': [33030, JSON.stringify({ "44": "", "41": "", "32": "", "1": "" })],

    // proNo 33031 — Consultar propriedades do dispositivo (modelo, firmware, ICCID)
    '33031|info': [33031, '{}'],

    // proNo 33029 — Controle do terminal (reset, factory reset, upgrade)
    '33029|reset': [33029, JSON.stringify({ cmd: 4, params: "" })],
};

function applyJttPreset() {
    const val = document.getElementById('jttPresetSel').value;
    if (!val || !JTT_PRESETS[val]) return;
    const [pron, content] = JTT_PRESETS[val];
    document.getElementById('jttProNo').value  = pron;
    document.getElementById('jttContent').value = content;
}

// ═══════════════════════════════════════════════════════════════════════════════
// Envio de comando
// ═══════════════════════════════════════════════════════════════════════════════
async function sendCommand() {
    const imei  = document.getElementById('cmdImei').value.trim();
    const proto = document.querySelector('input[name="proto"]:checked').value;

    if (!imei) { showFeedback('warning', 'Selecione um dispositivo.'); return; }

    let cmdContent, proNo, serverFlagId;

    if (proto === 'jimi') {
        cmdContent   = document.getElementById('jimiContent').value.trim();
        proNo        = 128;
        serverFlagId = 1;  // BUG #8 FIX: JIMI (JC400) → gateway 21100
        if (!cmdContent) { showFeedback('warning', 'Informe o conteúdo do comando.'); return; }
    } else {
        cmdContent   = document.getElementById('jttContent').value.trim();
        proNo        = parseInt(document.getElementById('jttProNo').value) || 37121;
        serverFlagId = 0;  // BUG #8 FIX: JTT (JC450) → gateway 21122
        if (!cmdContent) { showFeedback('warning', 'Informe os parâmetros JSON.'); return; }
        // proNo 128 para JTT (ex: VIDEOUPLOAD manual) não precisa ser JSON
        if (proNo !== 128) {
            try { JSON.parse(cmdContent); }
            catch (e) { showFeedback('danger', 'JSON inválido: ' + e.message); return; }
        }
    }

    const btn = document.getElementById('btnSend');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...';
    showFeedback('info', `Enviando proNo ${proNo} ao IoTHub (serverFlagId=${serverFlagId})...`);

    const body = new URLSearchParams({ imei, cmdContent, proNo, serverFlagId });

    try {
        const resp = await fetch(URL_SEND, {
            method: 'POST',
            headers: { ...hdrs, 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        const data = await resp.json();

        if (data.code === 0) {
            showFeedback('success',
                `Comando enviado! ID #${data.command_id ?? '—'} | IoTHub: ${data.msg}`);
            setTimeout(() => refreshCommands(), 1200);
        } else {
            showFeedback('danger', `Falha (${data.code}): ${data.msg}`);
        }
    } catch (e) {
        showFeedback('danger', 'Erro de rede: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send-fill me-1"></i> Enviar Comando';
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIDEOUPLOAD para alarmes JTT
// BUG #8 FIX: serverFlagId=0 obrigatório para comandos JTT
// ═══════════════════════════════════════════════════════════════════════════════
async function requestVideoUpload(imei, alarmLabel, alarmId, alarmName) {
    if (!alarmLabel) {
        showToast('danger', 'Este alarme não possui alarmLabel para solicitar vídeo.');
        return;
    }
    const cleanLabel = alarmLabel.replace(/,/g, '');
    const cmdContent = `VIDEOUPLOAD,189.22.240.43,23010,${cleanLabel},1-2-3`;
    const labelInfo  = alarmName ? ` (${alarmName})` : '';

    showToast('info', `Solicitando upload do vídeo${labelInfo} — IMEI: ${imei}`);

    const body = new URLSearchParams({ imei, cmdContent, proNo: '128', serverFlagId: '0' });

    try {
        const resp = await fetch(URL_SEND, {
            method: 'POST',
            headers: { ...hdrs, 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        const data = await resp.json();
        if (data.code === 0) {
            showToast('success', `VIDEOUPLOAD solicitado! ID #${data.command_id ?? '—'}`);
            setTimeout(() => refreshCommands(), 1200);
        } else {
            showToast('danger', `Falha: ${data.msg}`);
        }
    } catch (e) {
        showToast('danger', 'Erro de rede: ' + e.message);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Atualização do histórico de comandos (AJAX)
// ═══════════════════════════════════════════════════════════════════════════════
async function refreshCommands() {
    try {
        const resp = await fetch(`${URL_STATUS}?limit=30`, { headers: hdrs });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.code !== 0) return;

        // Badge respostas offline
        const oc = data.offline_count || 0;
        const offlineBadge = document.getElementById('offlineBadge');
        if (offlineBadge) {
            offlineBadge.innerHTML = oc > 0
                ? `<span class="badge bg-warning text-dark"><i class="bi bi-wifi-off me-1"></i>${oc} resp. offline</span>`
                : '';
        }

        const tbody = document.getElementById('cmdHistory');
        if (!tbody) return;

        if (!data.commands || !data.commands.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-3 text-muted">Nenhum comando enviado.</td></tr>';
            return;
        }

        tbody.innerHTML = data.commands.map(c => {
            const badgeClass = {
                pending:  'cs-pending',
                queued:   'cs-queued',
                sent:     'cs-sent',
                executed: 'cs-executed',
                failed:   'cs-failed',
            }[c.status] || 'bg-secondary';

            const resp = c.response ? String(c.response).substring(0, 100) : '—';
            const cmd  = (c.command || '').substring(0, 50);
            const rawResp = c.response ?? c.raw_response ?? '';
            const rawCmd  = c.command ?? '';

            // Badge de origem (alarme vs dashboard)
            const originBadge = c.origin === 'alarm'
                ? '<span class="badge src-alarm ms-1">Alarme</span>'
                : (c.origin ? `<span class="badge src-dashboard ms-1">${esc(c.origin)}</span>` : '');

            return `<tr onclick="showCommandDetail(${esc(JSON.stringify(rawCmd))}, ${esc(JSON.stringify(rawResp))}, '${esc(c.imei)}', '${esc(c.status)}', '${esc(c.created)}')" style="cursor:pointer">
                <td class="font-monospace small">${esc(c.imei)}</td>
                <td class="font-monospace small"
                    style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="${esc(c.command)}">${esc(cmd)}</td>
                <td><span class="badge ${badgeClass}">${esc(c.status.toUpperCase())}</span>${originBadge}</td>
                <td class="small">${esc(c.created)}</td>
                <td class="small text-muted"
                    style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    ${esc(resp)}
                </td>
            </tr>`;
        }).join('');

    } catch (e) {
        console.warn('refreshCommands:', e.message);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Modal de detalhes do comando
// ═══════════════════════════════════════════════════════════════════════════════
function showCommandDetail(command, response, imei, status, created) {
    const modalEl = document.getElementById('cmdDetailModal');
    if (!modalEl) return;

    document.getElementById('cmdDetailImei').textContent    = imei;
    document.getElementById('cmdDetailStatus').textContent  = status.toUpperCase();
    document.getElementById('cmdDetailCreated').textContent = created;
    document.getElementById('cmdDetailCommand').textContent = prettyJson(command);
    document.getElementById('cmdDetailResponse').textContent = prettyJson(response) || '—';

    const statusBadge = document.getElementById('cmdDetailStatus');
    const badgeClass = {
        PENDING:  'cs-pending',
        QUEUED:   'cs-queued',
        SENT:     'cs-sent',
        EXECUTED: 'cs-executed',
        FAILED:   'cs-failed',
    }[status.toUpperCase()] || 'bg-secondary';
    statusBadge.className = `badge ${badgeClass}`;

    new bootstrap.Modal(modalEl).show();
}

// ═══════════════════════════════════════════════════════════════════════════════
// Timers de refresh automático (sem location.reload)
// ═══════════════════════════════════════════════════════════════════════════════

// Countdown de comandos — 30s
let cmdCountdown = 30;
const cmdCdEl = document.getElementById('refreshCountdown');
const cmdCdEl2 = document.getElementById('camCountdown');
setInterval(() => {
    cmdCountdown--;
    if (cmdCdEl)  cmdCdEl.textContent  = cmdCountdown;
    if (cmdCdEl2) cmdCdEl2.textContent = cmdCountdown;
    if (cmdCountdown <= 0) {
        cmdCountdown = 30;
        refreshCommands();
    }
}, 1000);

// Refresh de dispositivos a cada 30s
let devCountdown = 30;
setInterval(() => {
    if (--devCountdown <= 0) {
        devCountdown = 30;
        refreshDevices();
    }
}, 1000);

// Atualiza ao abrir a aba de comandos
const tabCmdBtn = document.getElementById('tabCmdBtn');
if (tabCmdBtn) {
    tabCmdBtn.addEventListener('shown.bs.tab', () => refreshCommands());
}

// Atualiza ao voltar para a aba do navegador
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        refreshDevices();
        refreshCommands();
    }
});
