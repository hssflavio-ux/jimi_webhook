<?php
/**
 * JIMI IoT Dashboard - Template v2.0.0
 * Incluído por handlers/dashboard.php
 *
 * Variáveis do controller:
 *   $apiStatus    — ['label','color','last']
 *   $devices      — lista de câmeras
 *   $alarms       — últimos 50 alarmes (inclui msg_class, alarm_label)
 *   $cmdDevices   — seletor do form de comandos
 *   $commands     — últimos 30 comandos
 *   $dashToken    — token para AJAX
 *   $serverTimeBrt — hora do servidor em GMT-3
 *
 * Endpoints AJAX (via .htaccess → handlers/):
 *   GET  /camerasdata   — status API + grid câmeras (refresh background)
 *   GET  /commandstatus — histórico de comandos
 *   POST /sendcommand   — envio de comando
 */
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jimi IoT Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f0f2f5; }
        .nav-tabs .nav-link        { font-weight: 600; color: #495057; }
        .nav-tabs .nav-link.active { color: #0d6efd; border-bottom: 3px solid #0d6efd; }
        .border-critical { border-left: 5px solid #dc3545 !important; }
        .border-warning  { border-left: 5px solid #ffc107 !important; }
        .border-info     { border-left: 5px solid #0dcaf0 !important; }
        /* Status de comando */
        .cs-pending  { background:#ffc107;color:#000; }
        .cs-queued   { background:#6c757d;color:#fff; }
        .cs-sent     { background:#0dcaf0;color:#000; }
        .cs-executed { background:#198754;color:#fff; }
        .cs-failed   { background:#dc3545;color:#fff; }
        /* Origem do comando */
        .src-alarm   { background:#6f42c1;color:#fff; }
        .src-dashboard { background:#495057;color:#fff; }
        .table-sm td, .table-sm th { font-size:.82rem; }
        /* Indicador de refresh silencioso */
        #refreshDot { width:8px;height:8px;border-radius:50%;display:inline-block;
                      background:#6c757d;transition:background .4s; }
        #refreshDot.pulsing { background:#0d6efd; }
    </style>
</head>
<body>

<!-- ── Navbar ──────────────────────────────────────────────────────────────── -->
<nav class="navbar navbar-dark bg-dark shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand" href="/dashboard">
            <i class="bi bi-cpu-fill me-2"></i>Jimi IoT Hub
        </a>
        <div class="d-flex align-items-center gap-3 text-white">
            <span class="small text-secondary">STATUS API:</span>
            <span id="apiStatusBadge"
                  class="badge bg-<?php echo htmlspecialchars($apiStatus['color']); ?> d-flex align-items-center px-3 py-2">
                <i class="bi bi-circle-fill me-2 small"></i>
                <span id="apiStatusLabel"><?php echo htmlspecialchars($apiStatus['label']); ?></span>
            </span>
            <small id="apiStatusLast" class="text-muted"
                   title="Última comunicação (GMT-3)">
                <?php echo htmlspecialchars($apiStatus['last']); ?>
            </small>
        </div>
    </div>
</nav>

<div class="container mt-4">

    <!-- ── Tabs ─────────────────────────────────────────────────────────────── -->
    <ul class="nav nav-tabs" id="mainTab" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-bold" id="tabCamerasBtn"
                    data-bs-toggle="tab" data-bs-target="#tabCameras" type="button">
                <i class="bi bi-camera-video me-1"></i>
                Câmeras
                <span class="badge bg-primary ms-1" id="camerasCount">
                    <?php echo count($devices); ?>
                </span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold" id="tabAlarmsBtn"
                    data-bs-toggle="tab" data-bs-target="#tabAlarms" type="button">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Alarmes
                <span class="badge bg-danger ms-1"><?php echo count($alarms); ?></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold" id="tabCmdBtn"
                    data-bs-toggle="tab" data-bs-target="#tabCommands" type="button">
                <i class="bi bi-terminal me-1"></i>
                Comandos
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold" id="tabMediaBtn"
                    data-bs-toggle="tab" data-bs-target="#tabMedia" type="button">
                <i class="bi bi-film me-1"></i>
                Mídia
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold" id="tabConfigBtn"
                    data-bs-toggle="tab" data-bs-target="#tabConfig" type="button">
                <i class="bi bi-sliders me-1"></i>
                Configuração
            </button>
        </li>
    </ul>

    <div class="tab-content bg-white p-4 border border-top-0 rounded-bottom shadow-sm">

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 1 — Câmeras (atualiza via AJAX em background)
        ══════════════════════════════════════════════════════════════════ -->
        <div class="tab-pane fade show active" id="tabCameras">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small text-muted">
                    Atualizado: <span id="lastCamRefresh"><?php echo $serverTimeBrt; ?></span>
                    <span id="refreshDot" class="ms-2" title="Atualizando..."></span>
                </span>
                <span class="small text-muted">
                    Próximo refresh em <strong id="camCountdown">30</strong>s
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="camerasTable">
                    <thead class="table-light">
                        <tr>
                            <th>Dispositivo</th>
                            <th class="text-center">Ignição</th>
                            <th class="text-center">Velocidade</th>
                            <th class="text-center">Mapa</th>
                            <th class="text-end">Última Comunicação (GMT-3)</th>
                        </tr>
                    </thead>
                    <tbody id="camerasBody">
<?php if (empty($devices)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                Nenhuma câmera conectada.
                            </td>
                        </tr>
<?php else: foreach ($devices as $dev): ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($dev['name']); ?></div>
                                <div class="small text-muted font-monospace"><?php echo htmlspecialchars($dev['imei']); ?></div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $dev['ign_class']; ?> rounded-pill px-3">
                                    <?php echo $dev['ign_status']; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="fs-5 fw-bold text-secondary"><?php echo $dev['speed']; ?></span>
                                <small>km/h</small>
                            </td>
                            <td class="text-center">
                                <?php if ($dev['has_gps']): ?>
                                <a href="<?php echo htmlspecialchars($dev['map_url']); ?>"
                                   target="_blank" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                                    <i class="bi bi-geo-alt-fill me-1"></i>Localizar
                                </a>
                                <?php else: ?>
                                <span class="badge bg-light text-secondary border">Sem GPS</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end small text-secondary font-monospace">
                                <?php echo $dev['last_comm']; ?>
                            </td>
                        </tr>
<?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 2 — Alarmes  (+botão VIDEOUPLOAD para JTT)
        ══════════════════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabAlarms">

            <!-- Toast feedback VIDEOUPLOAD -->
            <div class="position-fixed bottom-0 end-0 p-3" style="z-index:1055">
                <div id="videoToast" class="toast align-items-center text-white border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body" id="videoToastMsg"></div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto"
                                data-bs-dismiss="toast"></button>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 4 — Mídia (galeria de arquivos)
        ══════════════════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabMedia">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <select id="mediaImeiFilter" class="form-select form-select-sm d-inline-block w-auto me-2"
                            onchange="refreshMedia()">
                        <option value="">Todos os dispositivos</option>
                        <?php foreach ($cmdDevices as $d): ?>
                            <option value="<?php echo htmlspecialchars($d['imei']); ?>">
                                <?php echo htmlspecialchars($d['imei']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="small text-muted">
                    Exibindo <strong id="mediaCount">0</strong> arquivo(s)
                </span>
            </div>
            <div id="mediaGallery" class="row g-3">
                <div class="col-12 text-center py-5 text-muted">
                    <i class="bi bi-hourglass-split"></i> Carregando galeria...
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             MODAL — Player de Vídeo
        ══════════════════════════════════════════════════════════════════ -->
        <div class="modal fade" id="videoPlayerModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title text-white"><i class="bi bi-play-circle me-2"></i><span id="videoPlayerTitle">Player</span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0 bg-black">
                        <video id="videoPlayer" controls autoplay muted
                               style="width:100%; max-height:70vh; background:#000"></video>
                        <div id="videoPlayerLoading" class="text-center text-white py-5">
                            <div class="spinner-border mb-2"></div>
                            <div class="small">Conectando ao stream...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 5 — Configuração (parâmetros do dispositivo)
        ══════════════════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabConfig">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-light">
                            <i class="bi bi-search me-2"></i>Consultar Dispositivo
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <select id="configImei" class="form-select form-select-sm">
                                    <option value="">Selecione um dispositivo...</option>
                                    <?php foreach ($cmdDevices as $d): ?>
                                        <option value="<?php echo htmlspecialchars($d['imei']); ?>">
                                            <?php echo htmlspecialchars($d['imei']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-sm btn-outline-primary" onclick="queryDeviceInfo()">
                                    <i class="bi bi-info-circle me-1"></i>Info (33031)
                                </button>
                                <button class="btn btn-sm btn-outline-info" onclick="queryAllParams()">
                                    <i class="bi bi-list-ul me-1"></i>Parâmetros (33028)
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="querySpecificParams()">
                                    <i class="bi bi-filter me-1"></i>Específicos (33030)
                                </button>
                            </div>
                            <div id="configResult" class="mt-3 small" style="max-height:400px;overflow:auto">
                                <pre class="bg-dark text-light p-3 rounded">Selecione um dispositivo e uma ação.</pre>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-light">
                            <i class="bi bi-pencil-square me-2"></i>Alterar Parâmetros (33027)
                        </div>
                        <div class="card-body">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">ID do Parâmetro</label>
                                <select id="paramId" class="form-select form-select-sm" onchange="updateParamHelp()">
                                    <option value="">Escolha um parâmetro...</option>
                                    <option value="1">1 — Intervalo de Heartbeat (s)</option>
                                    <option value="32">32 — Estratégia de envio (0=tempo, 1=distância, 2=ambos)</option>
                                    <option value="41">41 — Intervalo de envio padrão (s)</option>
                                    <option value="44">44 — Intervalo por distância (m)</option>
                                    <option value="85">85 — Velocidade máxima (km/h)</option>
                                    <option value="86">86 — Duração do excesso de velocidade (s)</option>
                                    <option value="87">87 — Tempo máximo de condução contínua (s)</option>
                                    <option value="19">19 — Endereço do servidor principal</option>
                                    <option value="24">24 — Porta TCP do servidor</option>
                                    <option value="49">49 — Raio da cerca eletrônica (m)</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Valor</label>
                                <input type="text" id="paramValue" class="form-control form-control-sm" placeholder="Ex: 30">
                            </div>
                            <small id="paramHelp" class="text-muted d-block mb-2"></small>
                            <button class="btn btn-sm btn-warning" onclick="setParam()">
                                <i class="bi bi-check-lg me-1"></i>Alterar Parâmetro
                            </button>
                            <div id="setParamResult" class="mt-2 small"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Evento</th>
                            <th>Veículo / IMEI</th>
                            <th>Protocolo</th>
                            <th>Ocorrência (GMT-3)</th>
                            <th>Recepção (GMT-3)</th>
                            <th class="text-center">Local / Mídia</th>
                            <th class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
<?php if (empty($alarms)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                Nenhum alarme recente.
                            </td>
                        </tr>
<?php else: foreach ($alarms as $alarm):
    $borderCss = match($alarm['severity']) {
        'critical' => 'border-critical',
        'warning'  => 'border-warning',
        default    => 'border-info'
    };
    $isJtt   = ($alarm['msg_class'] === 1);
    $hasGps  = ($alarm['latitude'] && $alarm['longitude'] && $alarm['latitude'] != 0 && $alarm['longitude'] != 0);
    $mapUrl  = $hasGps ? "https://www.google.com/maps?q={$alarm['latitude']},{$alarm['longitude']}" : '';
    $jsImei  = json_encode($alarm['imei']);
    $jsLabel = json_encode($alarm['alarm_label']);
    $jsId    = (int)$alarm['id'];
    $jsName  = json_encode($alarm['name']);
?>
                        <tr>
                            <td class="<?php echo $borderCss; ?> ps-3">
                                <span class="fw-bold"><?php echo htmlspecialchars($alarm['name']); ?></span>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($alarm['device_name']); ?></div>
                                <small class="text-muted font-monospace">
                                    <?php echo htmlspecialchars($alarm['imei']); ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge <?php echo $isJtt ? 'bg-info text-dark' : 'bg-secondary'; ?>">
                                    <?php echo $isJtt ? 'JTT' : 'JIMI'; ?>
                                </span>
                            </td>
                            <td class="small"><?php echo $alarm['occurred_at']; ?></td>
                            <td class="small text-muted"><?php echo $alarm['received_at']; ?></td>
                            <td class="text-center">
<?php if ($hasGps): ?>
                                <a href="<?php echo htmlspecialchars($mapUrl); ?>" target="_blank"
                                   class="btn btn-sm btn-outline-primary py-0" title="Ver no mapa">
                                    <i class="bi bi-geo-alt-fill"></i>
                                </a>
<?php endif; ?>
<?php if (!empty($alarm['file_url'])): ?>
                                <a href="<?php echo htmlspecialchars($alarm['file_url']); ?>" target="_blank"
                                   class="btn btn-sm btn-outline-info py-0 ms-1" title="Ver arquivo">
                                    <i class="bi bi-file-play"></i>
                                </a>
<?php endif; ?>
<?php if (!$hasGps && empty($alarm['file_url'])): ?>
                                <span class="text-muted small">—</span>
<?php endif; ?>
                            </td>
                            <td class="text-center">
<?php if ($isJtt): ?>
                                <button class="btn btn-sm btn-warning"
                                        <?php echo empty($alarm['alarm_label']) ? 'disabled title="Sem alarmLabel"' : ''; ?>
                                        onclick="requestVideoUpload(<?php echo $jsImei; ?>, <?php echo $jsLabel; ?>, <?php echo $jsId; ?>, <?php echo $jsName; ?>)">
                                    <i class="bi bi-cloud-upload me-1"></i>Solicitar Vídeo
                                </button>
<?php else: ?>
                                <span class="text-muted small">—</span>
<?php endif; ?>
                            </td>
                        </tr>
<?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 3 — Comandos (envio + log completo incluindo VIDEOUPLOAD)
        ══════════════════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabCommands">
            <div class="row g-3">

                <!-- ── Formulário de envio ───────────────────────────────── -->
                <div class="col-xl-5 col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-dark text-white fw-bold">
                            <i class="bi bi-send me-1"></i> Enviar Comando
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Dispositivo (IMEI)</label>
                                <select class="form-select" id="cmdImei">
                                    <option value="">— Selecione —</option>
                                    <?php foreach ($cmdDevices as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d['imei']); ?>">
                                        <?php echo htmlspecialchars($d['imei']); ?>
                                        <?php if (!empty($d['device_name'])): ?> — <?php echo htmlspecialchars($d['device_name']); ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Protocolo</label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="proto" id="protoJimi" value="jimi" checked>
                                    <label class="btn btn-outline-secondary" for="protoJimi">
                                        <i class="bi bi-camera-video me-1"></i>JIMI (JC400)
                                    </label>
                                    <input type="radio" class="btn-check" name="proto" id="protoJtt" value="jtt">
                                    <label class="btn btn-outline-info" for="protoJtt">
                                        <i class="bi bi-camera-reels me-1"></i>JT/T 808 (JC450)
                                    </label>
                                </div>
                            </div>

                            <!-- Seção JIMI -->
                            <div id="secJimi">
                                <div class="mb-2">
                                    <label class="form-label fw-semibold">Preset</label>
                                    <select class="form-select form-select-sm" onchange="applyJimiPreset(this.value)">
                                        <option value="">— Preset —</option>
                                        <optgroup label="Diagnóstico">
                                            <option value="STATUS">STATUS</option>
                                            <option value="VERSION#">VERSION#</option>
                                            <option value="IMEI">IMEI</option>
                                            <option value="ICCID">ICCID</option>
                                        </optgroup>
                                        <optgroup label="GPS">
                                            <option value="GPSON">GPSON — Ativar GPS</option>
                                            <option value="GPSOFF">GPSOFF — Desativar GPS</option>
                                            <option value="LJDW">LJDW — Localizar agora</option>
                                            <option value="TRACK,30S,10">TRACK,30S,10</option>
                                        </optgroup>
                                        <optgroup label="Streaming">
                                            <option value="RTMP,ON,OUT">RTMP,ON,OUT — Canal externo</option>
                                            <option value="RTMP,ON,IN">RTMP,ON,IN — Canal interno</option>
                                            <option value="RTMP,ON,INOUT">RTMP,ON,INOUT — Ambos</option>
                                            <option value="RTMP,OFF">RTMP,OFF — Parar</option>
                                            <option value="FILELIST">FILELIST — Listar SD</option>
                                        </optgroup>
                                        <optgroup label="Controle">
                                            <option value="RESET">RESET</option>
                                            <option value="FORMAT">FORMAT — Formatar SD</option>
                                            <option value="APN">APN — Consultar</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">
                                        Conteúdo <span class="badge bg-secondary fw-normal ms-1">proNo 128</span>
                                    </label>
                                    <input type="text" class="form-control font-monospace"
                                           id="jimiContent" placeholder="Ex: STATUS">
                                </div>
                            </div>

                            <!-- Seção JTT -->
                            <div id="secJtt" style="display:none">
                                <div class="mb-2">
                                    <label class="form-label fw-semibold">Preset JT/T</label>
                                    <select class="form-select form-select-sm" id="jttPresetSel"
                                            onchange="applyJttPreset()">
                                        <option value="">— Preset —</option>
                                        <optgroup label="Streaming (37121)">
                                            <option value="37121|ch1">Streaming canal 1</option>
                                            <option value="37121|ch2">Streaming canal 2</option>
                                            <option value="37121|ch12">Streaming canais 1+2</option>
                                        </optgroup>
                                        <optgroup label="Upload (128)">
                                            <option value="128|videoupload">VIDEOUPLOAD — solicitar vídeo</option>
                                        </optgroup>
                                        <optgroup label="Recursos (37381)">
                                            <option value="37381|list">Listar recursos A/V (últimos 7 dias)</option>
                                        </optgroup>
                                        <optgroup label="Playback (37377)">
                                            <option value="37377|playback">Playback histórico</option>
                                        </optgroup>
                                        <optgroup label="FTP Upload (37382)">
                                            <option value="37382|ftp">Upload por FTP</option>
                                        </optgroup>
                                        <optgroup label="Alarme (33283)">
                                            <option value="33283|ack">Ack manual de alarme</option>
                                        </optgroup>
                                        <optgroup label="TTS (33536)">
                                            <option value="33536|tts">Texto para voz</option>
                                        </optgroup>
                                        <optgroup label="Câmera (34817/34818)">
                                            <option value="34817|foto">Foto instantânea da câmera</option>
                                            <option value="34818|midia">Consultar mídia armazenada</option>
                                        </optgroup>
                                        <optgroup label="Configuração (33028-33031)">
                                            <option value="33028|params">Consultar todos os parâmetros</option>
                                            <option value="33030|params_esp">Consultar params específicos</option>
                                            <option value="33031|info">Info do dispositivo (FW/ICCID)</option>
                                        </optgroup>
                                        <optgroup label="Controle (33029)">
                                            <option value="33029|reset">Reset do terminal</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label fw-semibold">proNo</label>
                                    <input type="number" class="form-control form-control-sm"
                                           id="jttProNo" value="37121">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">
                                        Parâmetros (JSON)
                                        <a href="https://docs.jimicloud.com/integration/integration.html"
                                           target="_blank" class="ms-1 small">docs ↗</a>
                                    </label>
                                    <textarea class="form-control font-monospace form-control-sm"
                                              id="jttContent" rows="5"
                                              placeholder='{"dataType":0,"codeStreamType":0,"channel":"1","videoIP":"189.22.240.43","videoTCPPort":"10002","videoUDPPort":0}'></textarea>
                                </div>
                            </div>

                            <div id="cmdFeedback" class="alert d-none mb-3 py-2 small"></div>
                            <button class="btn btn-primary w-100" id="btnSend" onclick="sendCommand()">
                                <i class="bi bi-send-fill me-1"></i> Enviar Comando
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Log de comandos ───────────────────────────────────── -->
                <div class="col-xl-7 col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-journal-text me-1"></i> Log de Comandos</span>
                            <div class="d-flex gap-2 align-items-center">
                                <span id="offlineBadge" class="small"></span>
                                <button class="btn btn-sm btn-outline-light py-0"
                                        onclick="refreshCommands()" title="Atualizar agora">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height:440px;overflow-y:auto">
                                <table class="table table-sm table-hover mb-0 align-middle">
                                    <thead class="table-dark sticky-top">
                                        <tr>
                                            <th>IMEI</th>
                                            <th>Comando</th>
                                            <th>Origem</th>
                                            <th>Status</th>
                                            <th>Enviado (GMT-3)</th>
                                            <th>Resposta</th>
                                        </tr>
                                    </thead>
                                    <tbody id="cmdHistory">
<?php if (empty($commands)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-3 text-muted">
                                                Nenhum comando no log.
                                            </td>
                                        </tr>
<?php else: foreach ($commands as $cmd): ?>
                                        <tr>
                                            <td class="font-monospace small">
                                                <?php echo htmlspecialchars($cmd['imei']); ?>
                                            </td>
                                            <td class="font-monospace small"
                                                style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                                title="<?php echo htmlspecialchars($cmd['command']); ?>">
                                                <?php echo htmlspecialchars($cmd['command']); ?>
                                            </td>
                                            <td>
                                                <?php
                                                $isVideoUpload = str_starts_with($cmd['command'] ?? '', 'VIDEOUPLOAD');
                                                $srcClass = $isVideoUpload ? 'src-alarm' : 'src-dashboard';
                                                $srcLabel = $isVideoUpload ? 'Alarme' : 'Dashboard';
                                                ?>
                                                <span class="badge <?php echo $srcClass; ?>">
                                                    <?php echo $srcLabel; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge cs-<?php echo htmlspecialchars($cmd['status']); ?>">
                                                    <?php echo strtoupper(htmlspecialchars($cmd['status'])); ?>
                                                </span>
                                            </td>
                                            <td class="small"><?php echo $cmd['created']; ?></td>
                                            <td class="small text-muted"
                                                style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                                <?php echo htmlspecialchars($cmd['resp'] ?? '—'); ?>
                                            </td>
                                        </tr>
<?php endforeach; endif; ?>
                                    </tbody>
                </table>
            </div>

            <!-- Player de Vídeo ao Vivo / Playback -->
            <div class="card border-0 shadow-sm mt-3" id="livePlayerCard">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
                    <span><i class="bi bi-broadcast me-2"></i><span id="livePlayerLabel">Stream ao Vivo</span></span>
                    <button class="btn btn-sm btn-outline-light" onclick="closeLivePlayer()">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="card-body p-0 bg-black" style="min-height:360px">
                    <div class="d-flex justify-content-center align-items-center text-muted" style="height:360px" id="livePlayerPlaceholder">
                        <div class="text-center">
                            <i class="bi bi-camera-video-off fs-1 d-block mb-2"></i>
                            <span>Selecione um dispositivo e canal para iniciar o stream ao vivo.</span>
                            <div class="mt-3">
                                <select id="liveChannelSel" class="form-select form-select-sm d-inline-block w-auto me-2">
                                    <option value="1">Canal 1 (ADAS)</option>
                                    <option value="2">Canal 2 (USB)</option>
                                    <option value="3">Canal 3 (DMS)</option>
                                </select>
                                <button class="btn btn-sm btn-success" onclick="startLiveStream()">
                                    <i class="bi bi-play-fill me-1"></i>Iniciar Live
                                </button>
                            </div>
                        </div>
                    </div>
                    <video id="liveVideoPlayer" controls muted
                           style="width:100%; display:none; background:#000"></video>
                    <div id="liveLoading" class="text-center text-white py-5" style="display:none">
                        <div class="spinner-border mb-2"></div>
                        <div class="small">Conectando ao stream...</div>
                    </div>
                </div>
            </div>
        </div>
                        <div class="card-footer small text-muted d-flex justify-content-between align-items-center">
                            <span>
                                <span class="badge src-alarm">Alarme</span> = VIDEOUPLOAD da aba Alarmes &nbsp;
                                <span class="badge src-dashboard">Dashboard</span> = formulário acima
                            </span>
                            <span>Refresh em <strong id="cmdCountdown">30</strong>s</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="text-center text-muted small my-3" id="footerTime">
        Jimi Webhook System v2.0.0 &mdash; <span id="serverClock"><?php echo htmlspecialchars($serverTimeBrt); ?></span> GMT-3
    </div>
</div>

<!-- Modal de Detalhes do Comando -->
<div class="modal fade" id="cmdDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-terminal me-2"></i>Detalhes do Comando</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><strong>IMEI:</strong> <span id="cmdDetailImei" class="font-monospace"></span></div>
                <div class="mb-3"><strong>Status:</strong> <span id="cmdDetailStatus"></span></div>
                <div class="mb-3"><strong>Data:</strong> <span id="cmdDetailCreated"></span></div>
                <div class="mb-3">
                    <strong>Comando Enviado:</strong>
                    <pre id="cmdDetailCommand" class="bg-dark text-light p-3 rounded small"></pre>
                </div>
                <div class="mb-0">
                    <strong>Resposta:</strong>
                    <pre id="cmdDetailResponse" class="bg-dark text-light p-3 rounded small"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flv.js@1.6.2/dist/flv.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ═══════════════════════════════════════════════════════════════════════════════
// Configuração
// ═══════════════════════════════════════════════════════════════════════════════
const DASH_TOKEN  = <?php echo json_encode($dashToken); ?>;
const URL_CAMERAS = '/camerasdata';   // → handlers/camerasdata.php
const URL_SEND    = '/sendcommand';   // → handlers/sendcommand.php
const URL_STATUS  = '/commandstatus';// → handlers/commandstatus.php

const hdrs = { 'X-Dashboard-Token': DASH_TOKEN };

// ═══════════════════════════════════════════════════════════════════════════════
// Utilitários
// ═══════════════════════════════════════════════════════════════════════════════
const esc = s => String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function pulse() {
    const dot = document.getElementById('refreshDot');
    dot.classList.add('pulsing');
    setTimeout(() => dot.classList.remove('pulsing'), 600);
}

// ═══════════════════════════════════════════════════════════════════════════════
// Refresh de câmeras em background (não recarrega a página)
// Executa independente da aba ativa — Page Visibility API pausa quando
// o browser throttle em background, mas o setInterval continua quando visível.
// ═══════════════════════════════════════════════════════════════════════════════
let camCountdown = 30;

async function refreshCameras() {
    pulse();
    try {
        const resp = await fetch(URL_CAMERAS, { headers: hdrs });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.code !== 0) return;

        // Atualiza badge de status da API na navbar
        const badge = document.getElementById('apiStatusBadge');
        badge.className = `badge bg-${data.apiStatus.color} d-flex align-items-center px-3 py-2`;
        document.getElementById('apiStatusLabel').textContent = data.apiStatus.label;
        document.getElementById('apiStatusLast').textContent  = data.apiStatus.last;

        // Atualiza contador de câmeras
        document.getElementById('camerasCount').textContent = data.count;

        // Reconstrói grid de câmeras
        const tbody = document.getElementById('camerasBody');
        if (!data.devices || !data.devices.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Nenhuma câmera conectada.</td></tr>';
        } else {
            tbody.innerHTML = data.devices.map(d => {
                const mapBtn = d.has_gps
                    ? `<a href="${esc(d.map_url)}" target="_blank"
                          class="btn btn-outline-primary btn-sm rounded-pill px-3">
                           <i class="bi bi-geo-alt-fill me-1"></i>Localizar
                       </a>`
                    : `<span class="badge bg-light text-secondary border">Sem GPS</span>`;
                return `<tr>
                    <td>
                        <div class="fw-bold text-dark">${esc(d.name)}</div>
                        <div class="small text-muted font-monospace">${esc(d.imei)}</div>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-${esc(d.ign_class)} rounded-pill px-3">
                            ${esc(d.ign_status)}
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="fs-5 fw-bold text-secondary">${d.speed}</span>
                        <small>km/h</small>
                    </td>
                    <td class="text-center">${mapBtn}</td>
                    <td class="text-end small text-secondary font-monospace">${esc(d.last_comm)}</td>
                </tr>`;
            }).join('');
        }

        // Atualiza timestamps de exibição
        document.getElementById('lastCamRefresh').textContent = data.serverTime;
        document.getElementById('serverClock').textContent    = data.serverTime;

    } catch (e) {
        console.warn('refreshCameras:', e.message);
    }
}

// Countdown + execução do refresh
const camCdEl = document.getElementById('camCountdown');
setInterval(() => {
    if (--camCountdown <= 0) {
        camCountdown = 30;
        refreshCameras();
    }
    if (camCdEl) camCdEl.textContent = camCountdown;
}, 1000);

// Também atualiza ao voltar para a aba do navegador após ausência
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshCameras();
});

// ═══════════════════════════════════════════════════════════════════════════════
// Toggle protocolo
// ═══════════════════════════════════════════════════════════════════════════════
document.querySelectorAll('input[name="proto"]').forEach(r => {
    r.addEventListener('change', function () {
        document.getElementById('secJimi').style.display = this.value === 'jimi' ? '' : 'none';
        document.getElementById('secJtt').style.display  = this.value === 'jtt'  ? '' : 'none';
    });
});

function applyJimiPreset(val) {
    if (val) document.getElementById('jimiContent').value = val;
}

// ── Presets JTT corrigidos ────────────────────────────────────────────────────
// BUG #5 FIX: dataType, codeStreamType e videoUDPPort devem ser int (0), não
//             string ("0"). IoTHub rejeita tipos incorretos na deserialização.
// BUG #6 FIX: proNo 37381 usa formato de data JTT = yyMMddHHmmss (sem hifens
//             ou separadores). Ex: "260101000000" = 2026-01-01 00:00:00.
//             O formato ISO "2024-01-01 00:00:00" é INVÁLIDO para JTT.
// Nota: channel é string para 37121 (aceita "1", "2", "1-2"). Os demais
//       campos numéricos devem ser int literal, não string.
const JTT_PRESETS = {
    // proNo 37121 — Streaming em tempo real (Real-time A/V Transmission)
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
    // proNo 128 — Comando texto para JTT (ex: VIDEOUPLOAD)
    '128|videoupload': [128, 'VIDEOUPLOAD,189.22.240.43,23010,ALARM_LABEL_AQUI,1-2-3'],
    // proNo 37381 — Listar recursos de áudio/vídeo (Query A/V Resource List)
    // BUG #6: formato yyMMddHHmmss (ex: "260101000000" = 2026-01-01 00:00:00 UTC)
    '37381|list': [37381, JSON.stringify({
        beginTime: jttDateNow(-7),  // últimos 7 dias
        endTime:   jttDateNow(0),   // agora
        mediaType: 0,               // 0=áudio+vídeo, 1=áudio, 2=vídeo, 3=foto
        channelId: 1,
        eventCode: 0
    })],
    // proNo 37377 — Playback de vídeo histórico
    '37377|playback': [37377, JSON.stringify({
        serverLen:     15,
        serverAddress: "189.22.240.43",
        tcpPort:       10003,
        udpPort:       0,
        channel:       1,
        resourceType:  0,
        codeType:      0,
        storageType:   0,
        playMethod:    0,
        forwardRewind: 0,
        beginTime:     jttDateNow(-1),  // 24h atrás
        endTime:       jttDateNow(0),
        instructionID: "playback_" + Date.now()
    })],
    // proNo 33283 — Ack manual de alarme (para parar alarmes repetitivos)
    // alarmSerialNo: obrigatório — copiar do campo alarmSerialNo recebido no /pushalarm
    '33283|ack': [33283, JSON.stringify({ alarmSerialNo: 0, type: 0 })],

    // proNo 37382 — Upload de arquivo por FTP (File FTP Upload Command)
    // Ref: docs.jimicloud.com/integration/integration.html#_2-7-file-ftp-upload-prono-37382
    // beginTime/endTime: yyMMddHHmmss (mesmo formato JTT dos demais)
    // channelNo/fileType/storageType/codeType: ints
    // Pré-requisito: ter servidor FTP configurado (ftp-server no docker-compose, porta 21)
    '37382|ftp': [37382, JSON.stringify({
        serverAddress: "189.22.240.43",
        serverPort:    21,
        userName:      "ftp_user",
        password:      "Jimi@371##",
        path:          "/",
        beginTime:     jttDateNow(-1),  // 24h atrás — ajuste conforme necessário
        endTime:       jttDateNow(0),
        channelNo:     1,
        fileType:      0,               // 0=áudio+vídeo, 1=áudio, 2=vídeo, 3=foto
        storageType:   0,               // 0=principal
        codeType:      0,               // 0=stream principal
        instructionID: "ftp_" + Date.now()
    })],

    // proNo 33536 — Texto para voz / Text-to-Speech (TTS)
    // Ref: docs.jimicloud.com/integration/integration.html#_2-19-text-distribution-prono-33536
    // Suporta apenas Português/Inglês/Chinês. flag: 0=síntese, 1=sinal sonoro
    '33536|tts': [33536, JSON.stringify({
        flag: 0,
        text: "Atenção, mensagem do sistema"
    })],

    // proNo 34817 — Foto instantânea da câmera
    '34817|foto': [34817, JSON.stringify({
        channel: 1, photoCmd: 1, timeInterval: 0, saveFlag: 0,
        resolution: 0x04, quality: 5, light: 128, contrast: 60,
        saturability: 60, chroma: 128
    })],

    // proNo 34818 — Consultar mídia armazenada no dispositivo
    '34818|midia': [34818, JSON.stringify({
        mediaType: 2, channel: 1, eventCode: 0,
        beginTime: jttDateNow(-7), endTime: jttDateNow(0)
    })],

    // proNo 33028 — Consultar todos os parâmetros
    '33028|params': [33028, '""'],

    // proNo 33030 — Consultar parâmetros específicos
    '33030|params_esp': [33030, JSON.stringify({ "44": "", "41": "", "32": "", "1": "" })],

    // proNo 33031 — Propriedades do dispositivo (modelo, firmware, ICCID)
    '33031|info': [33031, '{}'],

    // proNo 33029 — Controle do terminal
    '33029|reset': [33029, JSON.stringify({ cmd: 4, params: "" })],
};

/**
 * Gera timestamp no formato JTT (yyMMddHHmmss) com offset de dias.
 * Ex: jttDateNow(-7) = data/hora UTC de 7 dias atrás no formato "yyMMddHHmmss"
 * BUG #6 FIX: formato JTT não aceita ISO 8601 — usa 12 dígitos sem separadores
 */
function jttDateNow(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    const yy  = String(d.getUTCFullYear()).slice(2);
    const MM  = String(d.getUTCMonth() + 1).padStart(2, '0');
    const dd  = String(d.getUTCDate()).padStart(2, '0');
    const HH  = String(d.getUTCHours()).padStart(2, '0');
    const mm  = String(d.getUTCMinutes()).padStart(2, '0');
    const ss  = String(d.getUTCSeconds()).padStart(2, '0');
    return `${yy}${MM}${dd}${HH}${mm}${ss}`;
}
function applyJttPreset() {
    const val = document.getElementById('jttPresetSel').value;
    if (!val || !JTT_PRESETS[val]) return;
    const [pron, content] = JTT_PRESETS[val];
    document.getElementById('jttProNo').value   = pron;
    document.getElementById('jttContent').value = content;
}

// ═══════════════════════════════════════════════════════════════════════════════
// Envio de comando (form da aba Comandos)
// ═══════════════════════════════════════════════════════════════════════════════
async function sendCommand() {
    const imei  = document.getElementById('cmdImei').value.trim();
    const proto = document.querySelector('input[name="proto"]:checked').value;
    if (!imei) { showFeedback('warning', 'Selecione um dispositivo.'); return; }

    let cmdContent, proNo, serverFlagId;

    if (proto === 'jimi') {
        cmdContent    = document.getElementById('jimiContent').value.trim();
        proNo         = 128;
        serverFlagId  = 1;  // JIMI (JC400) → gateway 21100
        if (!cmdContent) { showFeedback('warning', 'Informe o conteúdo do comando.'); return; }
    } else {
        cmdContent    = document.getElementById('jttContent').value.trim();
        proNo         = parseInt(document.getElementById('jttProNo').value) || 37121;
        serverFlagId  = 0;  // JTT (JC450) → gateway 21122
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

    try {
        const resp = await fetch(URL_SEND, {
            method: 'POST',
            headers: { ...hdrs, 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ imei, cmdContent, proNo, serverFlagId }),
        });
        const data = await resp.json();
        if (data.code === 0) {
            showFeedback('success',
                `✓ Enviado! ID #${data.command_id ?? '—'} | ${data.msg} ` +
                `[${data.endpoint?.includes('10088') ? 'porta 10088' : 'porta 9080'}]`
            );
            setTimeout(refreshCommands, 1000);
        } else {
            showFeedback('danger',
                `✗ Falha (IoTHub code=${data.iothub_code ?? data.code}): ${data.msg} ` +
                `— Req: ${data.request_id ?? ''}`
            );
        }
    } catch (e) {
        showFeedback('danger', 'Erro de rede: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send-fill me-1"></i> Enviar Comando';
    }
}

function showFeedback(type, msg) {
    const el = document.getElementById('cmdFeedback');
    el.className = `alert alert-${type} mb-3 py-2 small`;
    el.textContent = msg;
}

// ═══════════════════════════════════════════════════════════════════════════════
// VIDEOUPLOAD — aba Alarmes
// Registra no log com origem "Alarme" (badge roxo) via sendcommand.php
// ═══════════════════════════════════════════════════════════════════════════════
async function requestVideoUpload(imei, alarmLabel, alarmId, alarmName) {
    if (!alarmLabel) {
        showToast('danger', 'Este alarme não possui alarmLabel para solicitar vídeo.');
        return;
    }
    // Remove vírgulas do alarmLabel conforme especificação JIMI IoTHub
    const cleanLabel = alarmLabel.replace(/,/g, '');
    const cmdContent = `VIDEOUPLOAD,189.22.240.43,23010,${cleanLabel},1-2-3`;

    showToast('info', `Solicitando vídeo — ${alarmName ?? imei}`);

    try {
        const resp = await fetch(URL_SEND, {
            method: 'POST',
            headers: { ...hdrs, 'Content-Type': 'application/x-www-form-urlencoded' },
            // BUG #8 FIX: serverFlagId=0 pois VIDEOUPLOAD é enviado a dispositivos
            // JTT (JC450) — gateway 21122, não gateway JIMI 21100 (serverFlagId=1).
            body: new URLSearchParams({
                imei,
                cmdContent,
                proNo: '128',
                serverFlagId: '0',
            }),
        });
        const data = await resp.json();
        showToast(
            data.code === 0 ? 'success' : 'danger',
            data.code === 0
                ? `✓ VIDEOUPLOAD solicitado! ID #${data.command_id ?? '—'} → ${data.endpoint?.includes('10088') ? 'porta 10088' : 'porta 9080'}`
                : `✗ Falha (code=${data.iothub_code ?? data.code}): ${data.msg}`
        );
        if (data.code === 0) setTimeout(refreshCommands, 1000);
    } catch (e) {
        showToast('danger', 'Erro de rede: ' + e.message);
    }
}

function showToast(type, msg) {
    const toast = document.getElementById('videoToast');
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    document.getElementById('videoToastMsg').textContent = msg;
    new bootstrap.Toast(toast, { delay: 6000 }).show();
}

// ═══════════════════════════════════════════════════════════════════════════════
// Log de comandos — polling AJAX
// ═══════════════════════════════════════════════════════════════════════════════
async function refreshCommands() {
    try {
        const resp = await fetch(URL_STATUS + '?limit=50', { headers: hdrs });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.code !== 0) return;

        // Badge respostas offline
        const oc = data.offline_count || 0;
        document.getElementById('offlineBadge').innerHTML = oc > 0
            ? `<span class="badge bg-warning text-dark"><i class="bi bi-wifi-off me-1"></i>${oc} offline</span>`
            : '';

        const tbody = document.getElementById('cmdHistory');
        if (!data.commands || !data.commands.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-3 text-muted">Nenhum comando no log.</td></tr>';
            return;
        }

        tbody.innerHTML = data.commands.map(c => {
            const isVU  = (c.command || '').startsWith('VIDEOUPLOAD');
            const srcBadge = isVU
                ? `<span class="badge src-alarm">Alarme</span>`
                : `<span class="badge src-dashboard">Dashboard</span>`;
            const rawResp = c.response ?? '';
            const rawCmd  = c.command ?? '';
            return `<tr onclick="showCommandDetail(${esc(JSON.stringify(rawCmd))}, ${esc(JSON.stringify(rawResp))}, '${esc(c.imei)}', '${esc(c.status)}', '${esc(c.created)}')" style="cursor:pointer">
                <td class="font-monospace small">${esc(c.imei)}</td>
                <td class="font-monospace small"
                    style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="${esc(c.command)}">${esc((c.command||'').substring(0,50))}</td>
                <td>${srcBadge}</td>
                <td><span class="badge cs-${esc(c.status)}">${esc(c.status.toUpperCase())}</span></td>
                <td class="small">${esc(c.created)}</td>
                <td class="small text-muted"
                    style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    ${esc((c.response || '—').toString().substring(0,100))}
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
function prettyJson(val) {
    if (!val || val === '—') return val;
    try {
        const obj = typeof val === 'object' ? val : JSON.parse(val);
        return JSON.stringify(obj, null, 2);
    } catch {
        return String(val);
    }
}

function showCommandDetail(command, response, imei, status, created) {
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

    new bootstrap.Modal(document.getElementById('cmdDetailModal')).show();
}

// Countdown do log de comandos
let cmdCountdown = 30;
const cmdCdEl = document.getElementById('cmdCountdown');
setInterval(() => {
    if (--cmdCountdown <= 0) { cmdCountdown = 30; refreshCommands(); }
    if (cmdCdEl) cmdCdEl.textContent = cmdCountdown;
}, 1000);

// Atualiza log ao abrir a aba pela primeira vez
document.getElementById('tabCmdBtn')
    .addEventListener('shown.bs.tab', refreshCommands);

// ═══════════════════════════════════════════════════════════════════════════════
// Galeria de Mídia (aba Mídia)
// ═══════════════════════════════════════════════════════════════════════════════
const FILE_STORAGE_URL = '<?php echo getenv("FILE_STORAGE_URL") ?: "http://189.22.240.43:23010/download/"; ?>';

async function refreshMedia() {
    const imei  = document.getElementById('mediaImeiFilter')?.value || '';
    const gallery = document.getElementById('mediaGallery');
    if (!gallery) return;

    try {
        const params = new URLSearchParams({ limit: 100, offset: 0 });
        if (imei) params.set('imei', imei);
        const resp = await fetch('/mediadata?' + params, { headers: hdrs });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.code !== 0) return;

        document.getElementById('mediaCount').textContent = data.count;

        if (!data.media || !data.media.length) {
            gallery.innerHTML = '<div class="col-12 text-center py-5 text-muted">Nenhum arquivo de mídia encontrado.</div>';
            return;
        }

        gallery.innerHTML = data.media.map(m => {
            const isVideo = m.file_type === 'video';
            const isImage = m.file_type === 'image';
            const thumbIcon = isVideo ? 'bi-film' : (isImage ? 'bi-image' : 'bi-file-earmark');
            const sizeMB   = m.file_size ? (m.file_size / 1024 / 1024).toFixed(1) + ' MB' : '—';
            const time     = m.event_time || m.start_time || m.created_at || '';
            const downloadUrl = FILE_STORAGE_URL + encodeURIComponent(m.file_name || '');
            const playBtn = isVideo
                ? `<button class="btn btn-sm btn-outline-light mt-2"
                        onclick="event.stopPropagation(); playVideoUrl('${esc(m.file_name)}')">
                     <i class="bi bi-play-fill me-1"></i>Reproduzir
                   </button>`
                : '';
            return `<div class="col-md-3 col-sm-4 col-6">
                <div class="card bg-dark text-white h-100" style="cursor:pointer"
                     onclick="window.open('${esc(downloadUrl)}','_blank')">
                    <div class="card-body text-center d-flex flex-column justify-content-center align-items-center"
                         style="min-height:140px">
                        <i class="bi ${thumbIcon} fs-1 mb-2 ${isVideo ? 'text-info' : (isImage ? 'text-success' : 'text-secondary')}"></i>
                        <small class="text-truncate w-100 fw-bold">${esc(m.file_name || '—')}</small>
                        <small class="text-muted">${esc(m.imei)} · ${esc(m.channel_id ? 'Ch '+m.channel_id : '')}</small>
                        <small class="text-muted">${sizeMB} · ${esc(time ? time.substring(0,16) : '')}</small>
                        ${playBtn}
                    </div>
                </div>
            </div>`;
        }).join('');

    } catch (e) {
        console.warn('refreshMedia:', e.message);
    }
}

document.getElementById('tabMediaBtn')
    .addEventListener('shown.bs.tab', refreshMedia);

// ═══════════════════════════════════════════════════════════════════════════════
// Player de Vídeo (HTTP-FLV via flv.js)
// ═══════════════════════════════════════════════════════════════════════════════
let flvPlayer = null;
const STREAM_BASE = '<?php echo getenv("STREAM_URL") ?: "http://189.22.240.43:8881"; ?>';

async function startLiveStream() {
    const imeiSel = document.getElementById('cmdImei');
    const imei = imeiSel?.value?.trim();
    if (!imei) { showToast('warning', 'Selecione um dispositivo primeiro.'); return; }

    const channel = document.getElementById('liveChannelSel')?.value || '1';
    const url = STREAM_BASE + '/' + channel + '/' + imei + '.flv';
    playFlvStream(url, imei + ' — Canal ' + channel + ' (Live)');
}

function playVideoUrl(fileName) {
    const url = STREAM_BASE + '/download/' + encodeURIComponent(fileName);
    // Para vídeos, usamos o player modal
    const modal = new bootstrap.Modal(document.getElementById('videoPlayerModal'));
    const player = document.getElementById('videoPlayer');
    const loading = document.getElementById('videoPlayerLoading');

    document.getElementById('videoPlayerTitle').textContent = fileName;
    player.style.display = 'block';
    loading.style.display = 'block';
    player.src = url;
    player.load();
    player.oncanplay = () => { loading.style.display = 'none'; };
    player.onerror = () => {
        loading.innerHTML = '<span class="text-danger">Erro ao carregar vídeo. O arquivo pode não estar disponível.</span>';
    };
    modal.show();
}

function playFlvStream(url, title) {
    const playerCard = document.getElementById('livePlayerCard');
    const placeholder = document.getElementById('livePlayerPlaceholder');
    const video = document.getElementById('liveVideoPlayer');
    const loading = document.getElementById('liveLoading');
    const label = document.getElementById('livePlayerLabel');

    // Destruir player existente
    if (flvPlayer) { flvPlayer.destroy(); flvPlayer = null; }

    try {
        if (flvjs.isSupported()) {
            flvPlayer = flvjs.createPlayer({ type: 'flv', url: url, isLive: true });
            flvPlayer.attachMediaElement(video);
            flvPlayer.load();

            video.style.display = 'block';
            loading.style.display = 'block';
            placeholder.style.display = 'none';
            label.textContent = title;
            playerCard.scrollIntoView({ behavior: 'smooth' });

            flvPlayer.on(flvjs.Events.LOADING_COMPLETE, () => { loading.style.display = 'none'; });
            flvPlayer.on(flvjs.Events.ERROR, (e) => {
                loading.innerHTML = '<span class="text-danger">Erro no stream. Verifique se o dispositivo está transmitindo.</span>';
                console.warn('FLV error:', e);
            });
            flvPlayer.play();
        } else {
            showToast('danger', 'Seu navegador não suporta reprodução FLV.');
        }
    } catch (e) {
        showToast('danger', 'Erro ao iniciar player: ' + e.message);
    }
}

function closeLivePlayer() {
    if (flvPlayer) { flvPlayer.destroy(); flvPlayer = null; }
    const video = document.getElementById('liveVideoPlayer');
    video.style.display = 'none';
    video.src = '';
    document.getElementById('livePlayerPlaceholder').style.display = '';
    document.getElementById('liveLoading').style.display = 'none';
}

// ═══════════════════════════════════════════════════════════════════════════════
// Configuração do Dispositivo (proNo 33027/33028/33030/33031)
// ═══════════════════════════════════════════════════════════════════════════════
function getConfigImei() {
    const sel = document.getElementById('configImei');
    return sel ? sel.value.trim() : '';
}

function updateParamHelp() {
    const id = document.getElementById('paramId').value;
    const help = document.getElementById('paramHelp');
    const hints = {
        '1':  'Unidade: segundos. Ex: 30 = dispositivo reporta heartbeat a cada 30s.',
        '32': '0=por tempo, 1=por distância, 2=ambos. Define quando o dispositivo envia posição.',
        '41': 'Unidade: segundos. Intervalo padrão entre envios de posição.',
        '44': 'Unidade: metros. Distância mínima entre envios consecutivos.',
        '85': 'Unidade: km/h. Dispositivo gera alarme se exceder esta velocidade.',
        '86': 'Unidade: segundos. Tempo acima da velocidade máxima para gerar alarme.',
        '87': 'Unidade: segundos. Tempo máximo de condução sem pausa.',
        '19': 'IP ou domínio do servidor principal que o dispositivo deve reportar.',
        '24': 'Número da porta TCP do servidor.',
        '49': 'Unidade: metros. Dispositivo gera alarme se deslocar além deste raio sem ignição.',
    };
    help.textContent = hints[id] || '';
}

async function queryDeviceInfo() {
    const imei = getConfigImei();
    if (!imei) { alert('Selecione um dispositivo.'); return; }
    const result = document.getElementById('configResult');
    result.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Consultando...</div>';

    const body = new URLSearchParams({
        imei, proNo: '33031',
        cmdContent: '{}',
        serverFlagId: '0'
    });
    try {
        const resp = await fetch(URL_SEND, { method: 'POST', headers: { ...hdrs, 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await resp.json();
        result.innerHTML = `<pre class="bg-dark text-light p-3 rounded small" style="max-height:400px;overflow:auto">${esc(prettyJson(data))}</pre>`;
    } catch (e) {
        result.innerHTML = `<span class="text-danger">Erro: ${esc(e.message)}</span>`;
    }
}

async function queryAllParams() {
    const imei = getConfigImei();
    if (!imei) { alert('Selecione um dispositivo.'); return; }
    const result = document.getElementById('configResult');
    result.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Consultando todos os parâmetros...</div>';

    const body = new URLSearchParams({
        imei, proNo: '33028',
        cmdContent: '""',
        serverFlagId: '0'
    });
    try {
        const resp = await fetch(URL_SEND, { method: 'POST', headers: { ...hdrs, 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await resp.json();
        result.innerHTML = `<pre class="bg-dark text-light p-3 rounded small" style="max-height:400px;overflow:auto">${esc(prettyJson(data))}</pre>`;
    } catch (e) {
        result.innerHTML = `<span class="text-danger">Erro: ${esc(e.message)}</span>`;
    }
}

async function querySpecificParams() {
    const imei = getConfigImei();
    if (!imei) { alert('Selecione um dispositivo.'); return; }
    const ids = prompt('IDs dos parâmetros (separados por vírgula):', '44,41,32,1');
    if (!ids) return;

    const paramObj = {};
    ids.split(',').forEach(id => { paramObj[id.trim()] = ''; });
    const result = document.getElementById('configResult');
    result.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Consultando parâmetros específicos...</div>';

    const body = new URLSearchParams({
        imei, proNo: '33030',
        cmdContent: JSON.stringify(paramObj),
        serverFlagId: '0'
    });
    try {
        const resp = await fetch(URL_SEND, { method: 'POST', headers: { ...hdrs, 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await resp.json();
        result.innerHTML = `<pre class="bg-dark text-light p-3 rounded small" style="max-height:400px;overflow:auto">${esc(prettyJson(data))}</pre>`;
    } catch (e) {
        result.innerHTML = `<span class="text-danger">Erro: ${esc(e.message)}</span>`;
    }
}

async function setParam() {
    const imei = getConfigImei();
    const id   = document.getElementById('paramId').value;
    const val  = document.getElementById('paramValue').value.trim();
    if (!imei || !id || val === '') { alert('Preencha dispositivo, parâmetro e valor.'); return; }

    const result = document.getElementById('setParamResult');
    result.innerHTML = '<div class="spinner-border spinner-border-sm"></div> Alterando...';

    const paramObj = { [id]: val };
    const body = new URLSearchParams({
        imei, proNo: '33027',
        cmdContent: JSON.stringify(paramObj),
        serverFlagId: '0'
    });
    try {
        const resp = await fetch(URL_SEND, { method: 'POST', headers: { ...hdrs, 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await resp.json();
        result.innerHTML = data.code === 0
            ? `<span class="text-success"><i class="bi bi-check-circle me-1"></i>Parâmetro ${id} alterado com sucesso!</span>`
            : `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Falha: ${esc(data.msg)}</span>`;
    } catch (e) {
        result.innerHTML = `<span class="text-danger">Erro: ${esc(e.message)}</span>`;
    }
}
</script>
</body>
</html>