/**
 * JIMI IoT Dashboard - Client JS v3.0.0
 * Design System: Cursor-inspired editorial (DESIGN.md)
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
const esc = s => String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');

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
    el.className = 'ds-feedback ds-feedback-' + type;
    el.textContent = msg;
    el.classList.remove('d-none');
}

function showToast(type, msg, delay = 5000) {
    const toast = document.getElementById('videoToast');
    if (!toast) return;
    const bgMap = { success:'bg-success', danger:'bg-danger', warning:'bg-warning', info:'bg-info' };
    toast.className = 'toast align-items-center text-white border-0 ' + (bgMap[type] || 'bg-secondary');
    document.getElementById('videoToastMsg').textContent = msg;
    new bootstrap.Toast(toast, { delay }).show();
}

function prettyJson(val) {
    if (!val || val === '—') return val;
    try {
        const obj = typeof val === 'object' ? val : JSON.parse(val);
        return JSON.stringify(obj, null, 2);
    } catch {
        return String(val);
    }
}

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
// Refresh silencioso de Dispositivos + Status API
// ═══════════════════════════════════════════════════════════════════════════════
async function refreshDevices() {
    pulse();
    try {
        const resp = await fetch(URL_CAMERAS, { headers: hdrs });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.code !== 0) return;

        const badge = document.getElementById('apiStatusBadge');
        if (badge) {
            badge.className = 'ds-pill ds-pill-' + (data.apiStatus.color === 'success' ? 'success' : 'error');
            const dot = badge.querySelector('.ds-status-dot');
            if (dot) dot.className = 'ds-status-dot ' + (data.apiStatus.color === 'success' ? 'online' : 'offline');
            const lbl = badge.querySelector('#apiStatusLabel');
            if (lbl) lbl.textContent = data.apiStatus.label;
        }
        const lastEl = document.getElementById('apiStatusLast');
        if (lastEl) lastEl.textContent = data.apiStatus.last;

        const countBadge = document.getElementById('camerasCount');
        if (countBadge) countBadge.textContent = data.count;

        const tbody = document.getElementById('camerasBody');
        if (!tbody) return;

        if (!data.devices || !data.devices.length) {
            tbody.innerHTML = '<tr><td colspan="5"><div class="ds-empty"><i class="bi bi-camera-video-off ds-empty-icon"></i>Nenhuma câmera conectada.</div></td></tr>';
            return;
        }

        tbody.innerHTML = data.devices.map(d => {
            const ignPill = d.ign_status === 'ACC ON' ? 'ds-pill-grep' : 'ds-pill-neutral';
            const mapBtn = d.has_gps
                ? `<a href="${esc(d.map_url)}" target="_blank" class="ds-btn ds-btn-primary ds-btn-sm"><i class="bi bi-geo-alt-fill"></i>Localizar</a>`
                : `<span class="ds-caption">Sem GPS</span>`;
            return `<tr>
                <td><div class="ds-title-sm" style="margin-bottom:2px">${esc(d.name)}</div><span class="ds-mono-sm ds-text-muted">${esc(d.imei)}</span></td>
                <td><span class="ds-pill ds-pill-sm ${ignPill}">${esc(d.ign_status)}</span></td>
                <td><span class="ds-cell-speed">${d.speed}</span><span class="ds-cell-speed-u">km/h</span></td>
                <td>${mapBtn}</td>
                <td class="ds-mono-sm ds-text-muted" style="text-align:right">${esc(d.last_comm)}</td>
            </tr>`;
        }).join('');

    } catch (e) {
        console.warn('refreshDevices:', e.message);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Toggle protocolo JIMI / JTT (pill selector)
// ═══════════════════════════════════════════════════════════════════════════════
document.querySelectorAll('.ds-proto-option').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.ds-proto-option').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const proto = this.dataset.proto;
        document.getElementById('secJimi').style.display = proto === 'jimi' ? '' : 'none';
        document.getElementById('secJtt').style.display  = proto === 'jtt'  ? '' : 'none';
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// Presets JIMI
// ═══════════════════════════════════════════════════════════════════════════════
function applyJimiPreset(val) {
    if (val) document.getElementById('jimiContent').value = val;
}

// ═══════════════════════════════════════════════════════════════════════════════
// Presets JTT
// ═══════════════════════════════════════════════════════════════════════════════
const JTT_PRESETS = {
    '37121|ch1': [37121, JSON.stringify({ dataType:0, codeStreamType:0, channel:"1", videoIP:"189.22.240.43", videoTCPPort:"10002", videoUDPPort:0 })],
    '37121|ch2': [37121, JSON.stringify({ dataType:0, codeStreamType:0, channel:"2", videoIP:"189.22.240.43", videoTCPPort:"10002", videoUDPPort:0 })],
    '37121|ch12':[37121, JSON.stringify({ dataType:0, codeStreamType:0, channel:"1-2", videoIP:"189.22.240.43", videoTCPPort:"10002", videoUDPPort:0 })],
    '128|videoupload':[128, 'VIDEOUPLOAD,189.22.240.43,23010,ALARM_LABEL_AQUI,1-2-3'],
    '37381|list':[37381, JSON.stringify({ beginTime:jttDateNow(-7), endTime:jttDateNow(0), mediaType:0, channelId:1, eventCode:0 })],
    '37377|playback':[37377, JSON.stringify({ serverLen:15, serverAddress:"189.22.240.43", tcpPort:10003, udpPort:0, channel:1, resourceType:0, codeType:0, storageType:0, playMethod:0, forwardRewind:0, beginTime:jttDateNow(-1), endTime:jttDateNow(0), instructionID:"playback_"+Date.now() })],
    '33283|ack':[33283, JSON.stringify({ alarmSerialNo:0, type:0 })],
    '37382|ftp':[37382, JSON.stringify({ serverAddress:"189.22.240.43", serverPort:21, userName:"ftp_user", password:"Jimi@371##", path:"/", beginTime:jttDateNow(-1), endTime:jttDateNow(0), channelNo:1, fileType:0, storageType:0, codeType:0, instructionID:"ftp_"+Date.now() })],
    '33536|tts':[33536, JSON.stringify({ flag:0, text:"Atenção, mensagem do sistema" })],
    '34817|foto':[34817, JSON.stringify({ channel:1, photoCmd:1, timeInterval:0, saveFlag:0, resolution:0x04, quality:5, light:128, contrast:60, saturability:60, chroma:128 })],
    '34818|midia':[34818, JSON.stringify({ mediaType:2, channel:1, eventCode:0, beginTime:jttDateNow(-7), endTime:jttDateNow(0) })],
    '33028|params':[33028, '""'],
    '33030|params_esp':[33030, JSON.stringify({"44":"","41":"","32":"","1":""})],
    '33031|info':[33031, '{}'],
    '33029|reset':[33029, JSON.stringify({ cmd:4, params:"" })],
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
    const proto = document.querySelector('.ds-proto-option.active')?.dataset?.proto || 'jimi';

    if (!imei) { showFeedback('warning', 'Selecione um dispositivo.'); return; }

    let cmdContent, proNo, serverFlagId;

    if (proto === 'jimi') {
        cmdContent   = document.getElementById('jimiContent').value.trim();
        proNo        = 128;
        serverFlagId = 1;
        if (!cmdContent) { showFeedback('warning', 'Informe o conteúdo do comando.'); return; }
    } else {
        cmdContent   = document.getElementById('jttContent').value.trim();
        proNo        = parseInt(document.getElementById('jttProNo').value) || 37121;
        serverFlagId = 0;
        if (!cmdContent) { showFeedback('warning', 'Informe os parâmetros JSON.'); return; }
        if (proNo !== 128) {
            try { JSON.parse(cmdContent); }
            catch (e) { showFeedback('danger', 'JSON inválido: ' + e.message); return; }
        }
    }

    const btn = document.getElementById('btnSend');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...';
    showFeedback('info', `Enviando proNo ${proNo} (serverFlagId=${serverFlagId})...`);

    const body = new URLSearchParams({ imei, cmdContent, proNo, serverFlagId });

    try {
        const resp = await fetch(URL_SEND, {
            method: 'POST',
            headers: { ...hdrs, 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        const data = await resp.json();

        if (data.code === 0) {
            showFeedback('success', `Comando enviado! ID #${data.command_id ?? '—'} | ${data.msg}`);
            setTimeout(() => refreshCommands(), 1200);
        } else {
            showFeedback('danger', `Falha (${data.code}): ${data.msg}`);
        }
    } catch (e) {
        showFeedback('danger', 'Erro de rede: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Enviar Comando';
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIDEOUPLOAD para alarmes JTT
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

        const oc = data.offline_count || 0;
        const offlineBadge = document.getElementById('offlineBadge');
        if (offlineBadge) {
            offlineBadge.innerHTML = oc > 0
                ? `<span class="ds-pill ds-pill-sm ds-pill-thinking"><i class="bi bi-wifi-off me-1"></i>${oc} resp. offline</span>`
                : '';
        }

        const tbody = document.getElementById('cmdHistory');
        if (!tbody) return;

        if (!data.commands || !data.commands.length) {
            tbody.innerHTML = '<tr><td colspan="6"><div class="ds-empty"><i class="bi bi-terminal ds-empty-icon"></i>Nenhum comando no log.</div></td></tr>';
            return;
        }

        tbody.innerHTML = data.commands.map(c => {
            const statusClass = {
                pending:  'ds-cmd-pending',
                queued:   'ds-cmd-queued',
                sent:     'ds-cmd-sent',
                executed: 'ds-cmd-executed',
                failed:   'ds-cmd-failed',
            }[c.status] || 'ds-pill-neutral';

            const resp = c.response ? String(c.response).substring(0, 100) : '—';
            const cmd  = (c.command || '').substring(0, 50);
            const rawResp = c.response ?? c.raw_response ?? '';
            const rawCmd  = c.command ?? '';

            const originBadge = c.origin === 'alarm'
                ? '<span class="ds-pill ds-pill-sm ds-origin-alarm ms-1">Alarme</span>'
                : (c.origin ? `<span class="ds-pill ds-pill-sm ds-origin-dashboard ms-1">${esc(c.origin)}</span>` : '');

            return `<tr onclick="showCommandDetail(${esc(JSON.stringify(rawCmd))}, ${esc(JSON.stringify(rawResp))}, '${esc(c.imei)}', '${esc(c.status)}', '${esc(c.created)}')" style="cursor:pointer">
                <td class="ds-mono-sm">${esc(c.imei)}</td>
                <td class="ds-mono-sm" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(c.command)}">${esc(cmd)}</td>
                <td><span class="ds-pill ds-pill-sm ${statusClass}">${esc(c.status.toUpperCase())}</span>${originBadge}</td>
                <td class="ds-mono-sm ds-text-muted">${esc(c.created)}</td>
                <td class="ds-mono-sm ds-text-muted" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(resp)}</td>
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
    document.getElementById('cmdDetailCreated').textContent = created;
    document.getElementById('cmdDetailCommand').textContent = prettyJson(command);
    document.getElementById('cmdDetailResponse').textContent = prettyJson(response) || '—';

    const statusEl = document.getElementById('cmdDetailStatus');
    const statusClass = {
        pending:'ds-cmd-pending', queued:'ds-cmd-queued', sent:'ds-cmd-sent',
        executed:'ds-cmd-executed', failed:'ds-cmd-failed',
    }[status.toLowerCase()] || 'ds-pill-neutral';
    statusEl.innerHTML = `<span class="ds-pill ds-pill-sm ${statusClass}">${status.toUpperCase()}</span>`;

    new bootstrap.Modal(modalEl).show();
}

// ═══════════════════════════════════════════════════════════════════════════════
// Timers de refresh automático
// ═══════════════════════════════════════════════════════════════════════════════

// Countdown de comandos — 30s
let cmdCountdown = 30;
const cmdCdEl = document.getElementById('cmdCountdown');
setInterval(() => {
    cmdCountdown--;
    if (cmdCdEl) cmdCdEl.textContent = cmdCountdown;
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

// ═══════════════════════════════════════════════════════════════════════════════
// Configuração (queries)
// ═══════════════════════════════════════════════════════════════════════════════
async function queryConfig(proNo, payload) {
    const imei = document.getElementById('configImei').value;
    if (!imei) return;
    const result = document.getElementById('configResult');
    result.innerHTML = '<div class="ds-code-block">Consultando...</div>';
    try {
        const body = new URLSearchParams({ imei, cmdContent: payload, proNo: String(proNo), serverFlagId: '0' });
        const resp = await fetch(URL_SEND, { method:'POST', headers:{ ...hdrs, 'Content-Type':'application/x-www-form-urlencoded' }, body });
        const data = await resp.json();
        result.innerHTML = '<div class="ds-code-block">' + esc(prettyJson(data)) + '</div>';
    } catch(e) {
        result.innerHTML = '<div class="ds-code-block">Erro: ' + esc(e.message) + '</div>';
    }
}

async function queryDeviceInfo() { await queryConfig(33031, '{}'); }
async function queryAllParams() { await queryConfig(33028, '""'); }
async function querySpecificParams() { await queryConfig(33030, JSON.stringify({"44":"","41":"","32":"","1":""})); }

function updateParamHelp() {
    const sel = document.getElementById('paramId');
    const help = document.getElementById('paramHelp');
    if (!help) return;
    const texts = {
        '1': 'Intervalo em segundos entre heartbeats.',
        '32':'0=Tempo, 1=Distância, 2=Tempo+Distância.',
        '41':'Intervalo de envio por tempo (segundos).',
        '44':'Intervalo de envio por distância (metros).',
        '85':'Velocidade máxima. Exceder gera alarme.',
        '86':'Duração acima do limite para alarme (s).',
        '87':'Tempo máximo de condução contínua (s).',
        '19':'Endereço IP/domínio do servidor principal.',
        '24':'Porta TCP do servidor principal.',
        '49':'Raio da cerca eletrônica em metros.',
    };
    help.textContent = texts[sel.value] || '';
}

async function setParam() {
    const imei = document.getElementById('configImei').value;
    const pid = document.getElementById('paramId').value;
    const pval = document.getElementById('paramValue').value.trim();
    const result = document.getElementById('setParamResult');
    if (!imei || !pid || !pval) {
        result.innerHTML = '<span class="ds-feedback ds-feedback-warning d-inline-block">Preencha IMEI, parâmetro e valor.</span>';
        return;
    }
    result.innerHTML = '<span class="ds-caption">Enviando alteração...</span>';
    try {
        const payload = JSON.stringify({ [pid]: pval });
        const body = new URLSearchParams({ imei, cmdContent: payload, proNo: '33027', serverFlagId: '0' });
        const resp = await fetch(URL_SEND, { method:'POST', headers:{ ...hdrs, 'Content-Type':'application/x-www-form-urlencoded' }, body });
        const data = await resp.json();
        result.innerHTML = data.code === 0
            ? '<span class="ds-feedback ds-feedback-success d-inline-block"><i class="bi bi-check-circle me-1"></i>Parâmetro ' + pid + ' alterado com sucesso.</span>'
            : '<span class="ds-feedback ds-feedback-danger d-inline-block">Falha (' + data.code + '): ' + data.msg + '</span>';
    } catch(e) {
        result.innerHTML = '<span class="ds-feedback ds-feedback-danger d-inline-block">Erro: ' + esc(e.message) + '</span>';
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Galeria de Mídia
// ═══════════════════════════════════════════════════════════════════════════════
async function refreshMedia() {
    const imei = document.getElementById('mediaImeiFilter')?.value || '';
    const gallery = document.getElementById('mediaGallery');
    if (!gallery) return;
    gallery.innerHTML = '<div class="col-12"><div class="ds-empty"><i class="bi bi-hourglass-split ds-empty-icon"></i>Carregando...</div></div>';

    try {
        const params = new URLSearchParams();
        if (imei) params.set('imei', imei);
        const resp = await fetch('../mediadata?' + params.toString(), { headers: hdrs });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();

        const countEl = document.getElementById('mediaCount');
        if (countEl) countEl.textContent = data.files ? data.files.length : 0;

        if (!data.files || !data.files.length) {
            gallery.innerHTML = '<div class="col-12"><div class="ds-empty"><i class="bi bi-film ds-empty-icon"></i>Nenhum arquivo de mídia encontrado.</div></div>';
            return;
        }

        gallery.innerHTML = data.files.map(f => {
            const type = f.media_type || 'other';
            const icon = type === 'image' ? 'bi-file-image' : type === 'video' ? 'bi-file-play' : type === 'audio' ? 'bi-file-music' : 'bi-file';
            const isImg = type === 'image' && f.url;
            const thumb = isImg
                ? `<img src="${esc(f.url)}" alt="${esc(f.file_name)}" loading="lazy">`
                : `<i class="bi ${icon} ds-media-thumb-icon"></i>`;
            const dlUrl = f.url || f.download_url || '';
            const tcls = type === 'image' ? 'img' : type === 'video' ? 'vid' : 'aud';

            return `<div class="col-xl-4 col-md-6">
                <div class="ds-media-card">
                    <div class="ds-media-thumb ${tcls}">${thumb}</div>
                    <div class="ds-media-info">
                        <div class="ds-mono-sm ds-text-ink" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(f.file_name)}">${esc(f.file_name)}</div>
                        <div class="ds-caption mt-1">${esc(f.gateway_time || '—')} · ${esc(f.imei || '—')}</div>
                    </div>
                    <div class="ds-media-actions">
                        ${dlUrl ? `<a href="${esc(dlUrl)}" target="_blank" class="ds-btn ds-btn-secondary ds-btn-xs"><i class="bi bi-download me-1"></i>Download</a>` : ''}
                        ${type === 'video' && dlUrl ? `<button class="ds-btn ds-btn-ghost ds-btn-xs" onclick="playVideo('${esc(dlUrl)}','${esc(f.file_name)}')"><i class="bi bi-play-fill me-1"></i>Play</button>` : ''}
                    </div>
                </div>
            </div>`;
        }).join('');

    } catch (e) {
        gallery.innerHTML = `<div class="col-12"><div class="ds-empty"><span class="ds-empty-icon"><i class="bi bi-exclamation-triangle"></i></span>Erro ao carregar: ${esc(e.message)}</div></div>`;
    }
}

function playVideo(url, title) {
    const modal = document.getElementById('videoPlayerModal');
    const video = document.getElementById('videoPlayer');
    const loading = document.getElementById('videoPlayerLoading');
    document.getElementById('videoPlayerTitle').textContent = title || 'Player';
    if (loading) loading.style.display = 'block';
    if (video) { video.style.display = 'none'; video.src = url; video.style.display = ''; video.play(); if (loading) loading.style.display = 'none'; }
    new bootstrap.Modal(modal).show();
    if (modal) modal.addEventListener('hidden.bs.modal', () => { if (video) { video.pause(); video.src = ''; } }, { once: true });
}

const tabMediaBtn = document.getElementById('tabMediaBtn');
if (tabMediaBtn) tabMediaBtn.addEventListener('shown.bs.tab', () => refreshMedia());
