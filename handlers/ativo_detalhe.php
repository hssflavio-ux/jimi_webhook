<?php
/**
 * JIMI Webhook System — Detalhe do Ativo v3.1.0
 * Endpoint: /ativos/{imei}
 *
 * 9 abas com sidebar lateral: Visão Geral, Ao Vivo, Trajetos,
 * Alertas, Log, Relatórios, Vídeo, Comandos, Configurações.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_customer_id();
$imei = $_GET['imei'] ?? '';
$tab  = $_GET['tab'] ?? 'visao-geral';

$db = Database::getInstance()->getConnection();
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo');

function fmt_brt_dt($dt) {
    global $tz_utc, $tz_brt;
    if (!$dt) return '-';
    $d = new DateTime($dt, $tz_utc);
    $d->setTimezone($tz_brt);
    return $d->format('d/m/Y H:i:s');
}

// Carregar dados do ativo
try {
    $asset = $db->prepare("
        SELECT d.*, s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status,
               s.is_online, s.total_distance, s.total_gps_count, s.total_alarm_count,
               COALESCE(dm.model_name, d.device_model, '-') AS model_display,
               COALESCE(dm.protocol, '') AS protocol
        FROM devices d
        LEFT JOIN device_statistics s ON d.imei = s.imei
        LEFT JOIN device_models dm ON d.device_model_id = dm.id
        WHERE d.imei = ? AND d.customer_id = ?
    ");
    $asset->execute([$imei, $customer_id]);
    $asset = $asset->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $asset = $db->prepare("
        SELECT d.*, NULL as last_latitude, NULL as last_longitude, NULL as last_speed, NULL as last_acc_status,
               NULL as is_online, NULL as total_distance, NULL as total_gps_count, NULL as total_alarm_count,
               COALESCE(dm.model_name, d.device_model, '-') AS model_display,
               COALESCE(dm.protocol, '') AS protocol
        FROM devices d
        LEFT JOIN device_models dm ON d.device_model_id = dm.id
        WHERE d.imei = ? AND d.customer_id = ?
    ");
    $asset->execute([$imei, $customer_id]);
    $asset = $asset->fetch(PDO::FETCH_ASSOC);
}

if (!$asset) {
    http_response_code(404);
    die('<h1>Dispositivo não encontrado</h1>');
}

$hasGps = !empty($asset['last_latitude']) && $asset['last_latitude'] != 0;
$dtLast = $asset['last_communication'] ? new DateTime($asset['last_communication'], $tz_utc) : null;
$dtNow  = new DateTime('now', $tz_utc);
$isOnline = $dtLast && ($dtNow->getTimestamp() - $dtLast->getTimestamp()) < 600;

$dashToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';
$streamUrl = getenv('STREAM_URL') ?: 'http://localhost:8881';
$fileStorageUrl = getenv('FILE_STORAGE_URL') ?: 'http://localhost:23010/download/';

// ── Dados por aba ──────────────────────────────────────

// aba: visao-geral
$alarms24h = $db->prepare("SELECT COUNT(*) FROM alarms WHERE imei = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$alarms24h->execute([$imei]);
$alarms24h = $alarms24h->fetchColumn();

$events24h = $db->prepare("SELECT COUNT(*) FROM events WHERE imei = ? AND event_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$events24h->execute([$imei]);
$events24h = $events24h->fetchColumn();

// aba: trajetos
$tracks = [];
if ($tab === 'trajetos') {
    $trackStmt = $db->prepare("
        SELECT gps_time, latitude, longitude, speed, direction, altitude, satellites, acc
        FROM gps_data WHERE imei = ? ORDER BY gps_time DESC LIMIT 100
    ");
    $trackStmt->execute([$imei]);
    $tracks = $trackStmt->fetchAll(PDO::FETCH_ASSOC);
}

// aba: alertas
$alarms = [];
if ($tab === 'alertas') {
    $alarmStmt = $db->prepare("
        SELECT a.id, a.alarm_name, a.alarm_time, a.created_at, a.msg_class,
               a.alarm_label, a.latitude, a.longitude, a.file_url, a.speed,
               COALESCE(at.severity, 'info') AS severity
        FROM alarms a
        LEFT JOIN alarm_types at ON (
            (a.msg_class=1 AND at.protocol='JTT' AND at.alarm_code=IF(a.alarm_subtype IS NOT NULL,
                CONCAT(a.alarm_type, '-', a.alarm_subtype), a.alarm_type))
            OR (a.msg_class=0 AND at.protocol='JIMI' AND at.alarm_code=a.alarm_type)
        )
        WHERE a.imei = ?
        ORDER BY a.created_at DESC LIMIT 100
    ");
    $alarmStmt->execute([$imei]);
    $alarms = $alarmStmt->fetchAll(PDO::FETCH_ASSOC);
}

// aba: log
$logs = [];
if ($tab === 'log') {
    $logStmt = $db->prepare("
        (SELECT 'GPS' AS source, gps_time AS event_time, CONCAT('Lat:', latitude, ' Lng:', longitude, ' Spd:', speed, 'km/h') AS detail FROM gps_data WHERE imei = ? ORDER BY gps_time DESC LIMIT 50)
        UNION ALL
        (SELECT 'Heartbeat' AS source, heartbeat_time AS event_time, CONCAT('Bateria:', battery, '% GSM:', gsm_signal, ' Temp:', temperature, '°C') AS detail FROM heartbeats WHERE imei = ? ORDER BY heartbeat_time DESC LIMIT 50)
        UNION ALL
        (SELECT 'Evento' AS source, event_time, CONCAT(event_type, IF(description IS NOT NULL, CONCAT(' — ', description), '')) AS detail FROM events WHERE imei = ? ORDER BY event_time DESC LIMIT 50)
        ORDER BY event_time DESC LIMIT 50
    ");
    $logStmt->execute([$imei, $imei, $imei]);
    $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
}

// aba: comandos (dados)
$commands = [];
if ($tab === 'comandos') {
    $cmdStmt = $db->prepare("SELECT id, command_content, status, response_payload, created_at FROM commands WHERE imei = ? ORDER BY created_at DESC LIMIT 30");
    $cmdStmt->execute([$imei]);
    $commands = $cmdStmt->fetchAll(PDO::FETCH_ASSOC);
}

// aba: video (media files)
$mediaFiles = [];
if ($tab === 'video') {
    $mediaStmt = $db->prepare("SELECT id, file_type, file_name, file_url, file_size, created_at FROM media_files WHERE imei = ? ORDER BY created_at DESC LIMIT 50");
    $mediaStmt->execute([$imei]);
    $mediaFiles = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Layout ─────────────────────────────────────────────
$page_title    = htmlspecialchars($asset['device_name'] ?? $asset['imei']);
$current_route = 'ativos';
$body_class    = 'with-asset-sidebar';

$asset_base_url = '/ativos/' . urlencode($imei);
include __DIR__ . '/../web/layout_base.php';

// Asset sidebar (secondary nav)
$current_tab = $tab;
include __DIR__ . '/../web/layout_ativo_sidebar.php';

// ── Renderizar aba ─────────────────────────────────────
switch ($tab):

// ═══ VISÃO GERAL ═══════════════════════════════════════
case 'visao-geral':
?>
<div class="kpi-grid">
    <div class="kpi-item">
        <div class="kpi-item-label">Status</div>
        <div class="kpi-item-value" style="color:<?= $isOnline ? 'var(--success)' : 'var(--muted)' ?>">
            <?= $isOnline ? 'Online' : 'Offline' ?>
        </div>
        <div class="kpi-item-delta"><?= $asset['last_acc_status'] == 1 ? 'Ignição Ligada' : 'Ignição Desligada' ?></div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Velocidade</div>
        <div class="kpi-item-value"><?= round($asset['last_speed'] ?? 0) ?></div>
        <div class="kpi-item-delta">km/h</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Distância Total</div>
        <div class="kpi-item-value"><?= number_format(($asset['total_distance'] ?? 0), 0) ?></div>
        <div class="kpi-item-delta">km acumulados</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Alarmes (24h)</div>
        <div class="kpi-item-value"><?= $alarms24h ?></div>
        <div class="kpi-item-delta"><?= $events24h ?> eventos</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px">Informações do Dispositivo</h4>
        <table style="font-size:13px">
            <tr><td style="padding:4px 0;color:var(--muted);width:100px">IMEI</td><td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?= htmlspecialchars($asset['imei']) ?></td></tr>
            <tr><td style="padding:4px 0;color:var(--muted)">Modelo</td><td><?= htmlspecialchars($asset['model_display']) ?></td></tr>
            <tr><td style="padding:4px 0;color:var(--muted)">Protocolo</td><td><span class="badge" style="background:<?= $asset['protocol']==='JIMI'?'#e8f5ef':'#eef4fa' ?>;color:<?= $asset['protocol']==='JIMI'?'var(--success)':'#5a7fa8' ?>"><?= $asset['protocol'] ?: '-' ?></span></td></tr>
            <tr><td style="padding:4px 0;color:var(--muted)">Câmeras</td><td><?= $asset['camera_count'] ?? 1 ?></td></tr>
            <tr><td style="padding:4px 0;color:var(--muted)">Ativação</td><td><?= $asset['activation_date'] ? date('d/m/Y', strtotime($asset['activation_date'])) : '-' ?></td></tr>
            <tr><td style="padding:4px 0;color:var(--muted)">Última Com.</td><td><?= fmt_brt_dt($asset['last_communication']) ?></td></tr>
        </table>
    </div>
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px">Última Posição</h4>
        <?php if ($hasGps): ?>
        <table style="font-size:13px">
            <tr><td style="padding:4px 0;color:var(--muted);width:80px">Latitude</td><td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?= number_format($asset['last_latitude'], 6) ?></td></tr>
            <tr><td style="padding:4px 0;color:var(--muted)">Longitude</td><td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?= number_format($asset['last_longitude'], 6) ?></td></tr>
            <tr><td style="padding:4px 0;color:var(--muted)">Velocidade</td><td><?= round($asset['last_speed'] ?? 0) ?> km/h</td></tr>
        </table>
        <a href="https://www.google.com/maps?q=<?= $asset['last_latitude'] ?>,<?= $asset['last_longitude'] ?>" target="_blank" class="btn btn-outline btn-sm mt-16">Abrir no Google Maps</a>
        <?php else: ?>
        <div class="empty-state">
            <p>Sem dados de localização.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php break; ?>

<?php // ═══ AO VIVO ════════════════════════════════════════════
case 'ao-vivo':
?>
<?php if ($hasGps): ?>
<div class="card" style="padding:0;overflow:hidden">
    <div id="live-map" style="width:100%;height:500px"></div>
</div>
<div style="display:flex;gap:16px;margin-top:12px;font-size:13px;color:var(--muted)">
    <span>Lat: <strong style="color:var(--ink);font-family:'JetBrains Mono',monospace"><?= number_format($asset['last_latitude'], 6) ?></strong></span>
    <span>Lng: <strong style="color:var(--ink);font-family:'JetBrains Mono',monospace"><?= number_format($asset['last_longitude'], 6) ?></strong></span>
    <span>Velocidade: <strong style="color:var(--ink)"><?= round($asset['last_speed'] ?? 0) ?> km/h</strong></span>
</div>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
var map = L.map('live-map').setView([<?= $asset['last_latitude'] ?>, <?= $asset['last_longitude'] ?>], 15);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
}).addTo(map);
L.marker([<?= $asset['last_latitude'] ?>, <?= $asset['last_longitude'] ?>]).addTo(map)
    .bindPopup('<?= htmlspecialchars($asset['device_name'] ?? $asset['imei']) ?><br><?= fmt_brt_dt($asset['last_communication']) ?>');
</script>
<?php else: ?>
<div class="empty-state">
    <div class="empty-state-icon bi bi-map"></div>
    <h3>Sem dados de localização</h3>
    <p>O dispositivo ainda não enviou coordenadas GPS.</p>
</div>
<?php endif; ?>
<?php break; ?>

<?php // ═══ TRAJETOS ═══════════════════════════════════════════
case 'trajetos':
?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Data/Hora</th><th>Latitude</th><th>Longitude</th><th>Velocidade</th><th>Direção</th><th>Altitude</th><th>Satélites</th><th>Ignição</th></tr></thead>
        <tbody>
            <?php foreach ($tracks as $t): ?>
            <tr>
                <td><?= fmt_brt_dt($t['gps_time']) ?></td>
                <td class="text-mono"><?= number_format($t['latitude'], 6) ?></td>
                <td class="text-mono"><?= number_format($t['longitude'], 6) ?></td>
                <td><?= round($t['speed'] ?? 0) ?> km/h</td>
                <td><?= $t['direction'] ?? '-' ?>°</td>
                <td><?= $t['altitude'] ?? '-' ?> m</td>
                <td><?= $t['satellites'] ?? '-' ?></td>
                <td><span class="badge <?= ($t['acc'] ?? 0) == 1 ? 'badge-warning' : '' ?>"><?= ($t['acc'] ?? 0) == 1 ? 'Ligada' : 'Desligada' ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($tracks)): ?>
            <tr><td colspan="8"><div class="empty-state"><p>Nenhum registro de GPS encontrado.</p></div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php break; ?>

<?php // ═══ ALERTAS ════════════════════════════════════════════
case 'alertas':
?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Data/Hora</th><th>Alarme</th><th>Protocolo</th><th>Severidade</th><th>Velocidade</th><th>Mapa</th><th>Arquivo</th></tr></thead>
        <tbody>
            <?php foreach ($alarms as $a):
                $sevBorder = ($a['severity'] === 'critical') ? '#cf2d56' : (($a['severity'] === 'warning') ? '#c08532' : '#9fbbe0');
                $hasAlarmGps = !empty($a['latitude']) && $a['latitude'] != 0;
            ?>
            <tr>
                <td><?= fmt_brt_dt($a['created_at']) ?></td>
                <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($a['alarm_name'] ?? 'Desconhecido') ?></td>
                <td><span class="badge" style="background:<?= $a['msg_class'] == 1 ? '#eef4fa' : '#e8f5ef' ?>;color:<?= $a['msg_class'] == 1 ? '#5a7fa8' : 'var(--success)' ?>"><?= $a['msg_class'] == 1 ? 'JT/T' : 'JIMI' ?></span></td>
                <td><span class="badge" style="background:<?= $sevBorder ?>15;color:<?= $sevBorder ?>;border:1px solid <?= $sevBorder ?>40"><?= htmlspecialchars($a['severity']) ?></span></td>
                <td><?= round($a['speed'] ?? 0) ?> km/h</td>
                <td><?php if ($hasAlarmGps): ?><a href="https://www.google.com/maps?q=<?= $a['latitude'] ?>,<?= $a['longitude'] ?>" target="_blank" class="btn btn-outline btn-sm">Mapa</a><?php endif; ?></td>
                <td><?php if ($a['file_url']): ?><a href="<?= htmlspecialchars($fileStorageUrl . $a['file_url']) ?>" target="_blank" class="btn btn-outline btn-sm">Arquivo</a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($alarms)): ?>
            <tr><td colspan="7"><div class="empty-state"><p>Nenhum alarme registrado para este dispositivo.</p></div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php break; ?>

<?php // ═══ LOG ════════════════════════════════════════════════
case 'log':
?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Data/Hora</th><th>Origem</th><th>Detalhes</th></tr></thead>
        <tbody>
            <?php foreach ($logs as $l): ?>
            <tr>
                <td><?= fmt_brt_dt($l['event_time']) ?></td>
                <td><span class="badge" style="background:<?= $l['source']==='GPS'?'#fdf3e8':($l['source']==='Heartbeat'?'#e8f5ef':'#eef4fa') ?>;color:<?= $l['source']==='GPS'?'var(--warning)':($l['source']==='Heartbeat'?'var(--success)':'#5a7fa8') ?>"><?= $l['source'] ?></span></td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--body)"><?= htmlspecialchars($l['detail']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
            <tr><td colspan="3"><div class="empty-state"><p>Nenhum log registrado.</p></div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php break; ?>

<?php // ═══ RELATÓRIOS ═════════════════════════════════════════
case 'relatorios':
?>
<div class="card mb-16">
    <p style="color:var(--muted);font-size:13px">Relatórios detalhados por período serão implementados na Fase 7 (Relatórios cross-device). Por enquanto, acesse os dados via as abas <strong>Trajetos</strong>, <strong>Alertas</strong> e <strong>Comandos</strong>.</p>
</div>
<div class="kpi-grid">
    <div class="kpi-item">
        <div class="kpi-item-label">Trajetos de GPS</div>
        <div class="kpi-item-value"><?= $asset['total_gps_count'] ?? 0 ?></div>
        <div class="kpi-item-delta">registros</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Distância Total</div>
        <div class="kpi-item-value"><?= number_format(($asset['total_distance'] ?? 0), 0) ?></div>
        <div class="kpi-item-delta">km</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Total de Alarmes</div>
        <div class="kpi-item-value"><?= $asset['total_alarm_count'] ?? 0 ?></div>
        <div class="kpi-item-delta"><?= $alarms24h ?> nas últimas 24h</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Eventos</div>
        <div class="kpi-item-value"><?= $events24h ?></div>
        <div class="kpi-item-delta">últimas 24h</div>
    </div>
</div>
<?php break; ?>

<?php // ═══ VÍDEO ══════════════════════════════════════════════
case 'video':
?>
<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
    <div>
        <div class="card" style="padding:0;overflow:hidden;margin-bottom:16px">
            <div style="background:#1a1a1a;display:flex;align-items:center;justify-content:center;height:360px;color:var(--muted);font-size:13px">
                <div style="text-align:center">
                    <i class="bi bi-camera-video" style="font-size:48px;display:block;margin-bottom:12px;opacity:.3"></i>
                    Player de Vídeo — Selecione uma transmissão ou arquivo
                </div>
            </div>
        </div>
        <div class="flex flex-gap">
            <button class="btn btn-primary btn-sm" disabled>Transmissão ao Vivo</button>
            <button class="btn btn-outline btn-sm" disabled>Playback Histórico</button>
        </div>
    </div>
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px">Arquivos de Mídia</h4>
        <?php if (!empty($mediaFiles)): ?>
        <?php foreach ($mediaFiles as $mf): ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:8px">
            <i class="bi bi-<?= $mf['file_type'] === 'video' ? 'play-btn' : ($mf['file_type'] === 'image' ? 'image' : 'music-note') ?>" style="color:var(--muted);font-size:18px"></i>
            <div style="flex:1;min-width:0">
                <div style="font-size:12px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($mf['file_name'] ?? 'Sem nome') ?></div>
                <div style="font-size:11px;color:var(--muted)"><?= $mf['file_type'] ?> &middot; <?= number_format(($mf['file_size'] ?? 0) / 1024 / 1024, 1) ?> MB</div>
            </div>
            <?php if ($mf['file_url']): ?>
            <a href="<?= htmlspecialchars($fileStorageUrl . $mf['file_url']) ?>" target="_blank" class="btn btn-outline btn-sm">Download</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state"><p>Nenhum arquivo de mídia.</p></div>
        <?php endif; ?>
    </div>
</div>
<?php break; ?>

<?php // ═══ COMANDOS ═══════════════════════════════════════════
case 'comandos':
    $jimiPresets = [
        '' => 'Selecione um comando...',
        'STATUS' => 'STATUS — Status do dispositivo',
        'VERSION#' => 'VERSION# — Versão do firmware',
        'IMEI' => 'IMEI — Consultar IMEI',
        'GPSON' => 'GPSON — Ligar GPS',
        'GPSOFF' => 'GPSOFF — Desligar GPS',
        'RTMP,ON,OUT' => 'RTMP,ON,OUT — Stream saída',
        'RTMP,OFF' => 'RTMP,OFF — Desligar stream',
        'RESET' => 'RESET — Reiniciar dispositivo',
    ];
    $jttPresets = [
        '' => 'Selecione um comando...',
        'streaming' => 'Streaming de Vídeo (ch1/ch2/ch12)',
        'video_upload' => 'Upload de Vídeo (VIDEOUPLOAD)',
        'resources' => 'Listar Recursos A/V',
        'playback' => 'Playback Histórico',
        'ftp_upload' => 'Upload FTP',
        'alarm_ack' => 'Confirmação de Alarme',
        'tts' => 'TTS — Aviso por Voz',
        'photo' => 'Captura de Foto',
        'query_params' => 'Consultar Parâmetros',
        'set_param' => 'Definir Parâmetro',
        'device_info' => 'Informações do Dispositivo',
    ];
?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">Enviar Comando</h4>
        <form id="cmd-form">
            <input type="hidden" name="imei" value="<?= htmlspecialchars($imei) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($dashToken) ?>">
            <input type="hidden" id="cmd-protocol" name="protocol" value="<?= $asset['protocol'] ?>">

            <div class="form-group">
                <label>Protocolo</label>
                <div style="font-size:13px;color:var(--ink)"><?= $asset['protocol'] ?: 'Desconhecido' ?></div>
            </div>

            <?php if ($asset['protocol'] === 'JIMI'): ?>
            <div class="form-group">
                <label>Comando Predefinido</label>
                <select id="jimi-preset" onchange="document.getElementById('cmd-content').value = this.value">
                    <?php foreach ($jimiPresets as $val => $label): ?>
                    <option value="<?= $val ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Conteúdo do Comando</label>
                <textarea id="cmd-content" name="content" rows="3" placeholder="Ex: STATUS"></textarea>
            </div>
            <input type="hidden" name="proNo" value="128">
            <input type="hidden" name="serverFlagId" value="1">

            <?php elseif ($asset['protocol'] === 'JTT'): ?>
            <div class="form-group">
                <label>Comando Predefinido</label>
                <select id="jtt-preset" onchange="fillJttPreset(this.value)">
                    <?php foreach ($jttPresets as $val => $label): ?>
                    <option value="<?= $val ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>proNo</label>
                <input type="number" id="cmd-proNo" name="proNo" value="37121">
            </div>
            <div class="form-group">
                <label>Parâmetros (JSON)</label>
                <textarea id="cmd-content-jtt" name="content" rows="4" placeholder='{"channelId":1,"mediaType":0}'></textarea>
            </div>
            <input type="hidden" name="serverFlagId" value="0">
            <?php else: ?>
            <div class="form-group">
                <label>Conteúdo do Comando</label>
                <textarea name="content" rows="3" placeholder="Conteúdo do comando..."></textarea>
            </div>
            <div class="form-group">
                <label>proNo</label>
                <input type="number" name="proNo" value="128">
            </div>
            <div class="form-group">
                <label>serverFlagId (1=JIMI, 0=JT/T)</label>
                <input type="number" name="serverFlagId" value="1" min="0" max="1">
            </div>
            <?php endif; ?>

            <div id="cmd-feedback" style="font-size:13px;margin:8px 0"></div>
            <button type="submit" class="btn btn-primary">Enviar Comando</button>
        </form>
    </div>
    <div>
        <div class="card" style="margin-bottom:16px">
            <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px">Histórico de Comandos</h4>
            <div style="max-height:400px;overflow-y:auto">
                <table style="font-size:12px">
                    <thead><tr><th>Data</th><th>Comando</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($commands as $c):
                            $statusBadge = $c['status'] === 'executed' ? 'badge-success' : ($c['status'] === 'failed' ? 'badge-error' : ($c['status'] === 'sent' ? 'badge-info' : ''));
                        ?>
                        <tr>
                            <td style="white-space:nowrap"><?= fmt_brt_dt($c['created_at']) ?></td>
                            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:'JetBrains Mono',monospace;font-size:11px"><?= htmlspecialchars($c['command_content']) ?></td>
                            <td><span class="badge <?= $statusBadge ?>"><?= $c['status'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($commands)): ?>
                        <tr><td colspan="3"><div class="empty-state"><p>Nenhum comando enviado.</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
<?php
// Endereços que o DEVICE alcança (streams e upload) — mesmos presets de comandos.php
$vsc = video_stream_config();
$fsUrl  = getenv('FILE_STORAGE_URL') ?: 'http://localhost:23010/download/';
$fsHost = parse_url($fsUrl, PHP_URL_HOST) ?: 'localhost';
$fsPort = parse_url($fsUrl, PHP_URL_PORT) ?: 23010;
?>
var jttPresets = {
    // 37121 (0x9101): device publica o RTP no media server do IoTHub — IP/porta do .env
    'streaming':     { proNo: 37121, content: <?= json_encode(json_encode([
                           'dataType' => 0, 'codeStreamType' => 0, 'channel' => '1',
                           'videoIP' => $vsc['ingest_ip'], 'videoTCPPort' => $vsc['ingest_port'], 'videoUDPPort' => 0,
                       ], JSON_UNESCAPED_SLASHES)) ?> },
    'video_upload':  { proNo: 128,   content: <?= json_encode('VIDEOUPLOAD,' . $fsHost . ',' . $fsPort . ',ALARM_LABEL,1-2-3') ?> },
    // 37381 (0x9205): lista gravações do cartão — janela GMT-0 compacta que NÃO pode cruzar o dia
    'resources':     { proNo: 37381, content: <?= json_encode(json_encode([
                           'channel' => 1, 'channelId' => 1,
                           'beginTime' => gmdate('ymd') . '000000', 'endTime' => gmdate('ymd') . '235959',
                           'alarmFlag' => 0, 'resourceType' => 0, 'codeType' => 0, 'storageType' => 0,
                           'instructionID' => 'manual_' . time(),
                       ], JSON_UNESCAPED_SLASHES)) ?> },
    'playback':      { proNo: 37377, content: <?= json_encode(json_encode([
                           'serverLen' => strlen($vsc['ingest_ip']), 'serverAddress' => $vsc['ingest_ip'],
                           'tcpPort' => (int)$vsc['playback_port'], 'udpPort' => 0, 'channel' => 1,
                           'resourceType' => 0, 'codeType' => 0, 'storageType' => 0,
                           'playMethod' => 0, 'forwardRewind' => 0,
                           'beginTime' => '', 'endTime' => '', 'instructionID' => '',
                       ], JSON_UNESCAPED_SLASHES)) ?> },
    'ftp_upload':    { proNo: 37382, content: '{"channelId":1,"beginTime":"","endTime":"","mediaType":0,"eventCode":0}' },
    'alarm_ack':     { proNo: 33283, content: '{"alarmSerialNo":0}' },
    'tts':           { proNo: 33536, content: '{"text":"","volume":5}' },
    'photo':         { proNo: 34817, content: '{"channelId":1,"photoType":0}' },
    'query_params':  { proNo: 33028, content: '{}' },
    'set_param':     { proNo: 33027, content: '{"paramId":0,"paramValue":""}' },
    'device_info':   { proNo: 33031, content: '{}' }
};
function fillJttPreset(key) {
    var p = jttPresets[key];
    if (p) {
        document.getElementById('cmd-proNo').value = p.proNo;
        // p.content já é uma string JSON — stringify direto geraria string quotada
        var pretty;
        try { pretty = JSON.stringify(JSON.parse(p.content), null, 2); }
        catch (e) { pretty = p.content; }
        document.getElementById('cmd-content-jtt').value = pretty;
    }
}
</script>
<?php break; ?>

<?php // ═══ CONFIGURAÇÕES ═══════════════════════════════════════
case 'configuracoes':
?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px">Consultar Parâmetros</h4>
        <div class="form-group">
            <label>Tipo de Consulta</label>
            <select id="query-type">
                <option value="33031">Informações do Dispositivo</option>
                <option value="33028">Todos os Parâmetros</option>
                <option value="33030">Parâmetros Específicos</option>
            </select>
        </div>
        <div class="form-group" id="specific-params" style="display:none">
            <label>IDs dos Parâmetros (separados por vírgula)</label>
            <input type="text" id="param-ids" placeholder="Ex: 1,2,3">
        </div>
        <button class="btn btn-outline" onclick="queryParams()">Consultar</button>
        <div id="query-result" style="margin-top:12px;font-size:13px"></div>
    </div>
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px">Definir Parâmetro</h4>
        <div class="form-group">
            <label>ID do Parâmetro</label>
            <input type="number" id="set-param-id" placeholder="Ex: 1">
        </div>
        <div class="form-group">
            <label>Valor</label>
            <input type="text" id="set-param-value" placeholder="Valor do parâmetro">
        </div>
        <button class="btn btn-outline" onclick="setParam()">Definir</button>
        <div id="set-result" style="margin-top:12px;font-size:13px"></div>
    </div>
</div>
<script>
function queryParams() {
    var proNo = document.getElementById('query-type').value;
    var content = '{}';
    if (proNo === '33030') {
        var ids = document.getElementById('param-ids').value;
        content = JSON.stringify({ paramIds: ids.split(',').map(Number) });
    }
    sendConfigCmd(proNo, content);
}
function setParam() {
    var id = document.getElementById('set-param-id').value;
    var val = document.getElementById('set-param-value').value;
    sendConfigCmd(33027, JSON.stringify({ paramId: parseInt(id), paramValue: val }));
}
function sendConfigCmd(proNo, content) {
    fetch('/sendcommand', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Dashboard-Token': '<?= $dashToken ?>', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: JSON.stringify({ imei: '<?= $imei ?>', proNo: proNo, content: content, serverFlagId: 0 })
    }).then(r => r.json()).then(d => {
        document.getElementById('query-result').innerHTML = '<pre style="font-size:11px">' + JSON.stringify(d, null, 2) + '</pre>';
    });
}
document.getElementById('query-type').addEventListener('change', function() {
    document.getElementById('specific-params').style.display = this.value === '33030' ? 'block' : 'none';
});
</script>
<?php break; ?>

<?php endswitch; ?>

</div><!-- close asset-content -->
<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
