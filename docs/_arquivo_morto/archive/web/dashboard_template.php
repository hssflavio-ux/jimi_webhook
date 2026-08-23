<?php
/**
 * JIMI IoT Dashboard - Template v3.0.0
 * Design System: Cursor-inspired editorial (DESIGN.md)
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
 */
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jimi IoT Dashboard</title>

    <!-- Fonts: Inter (CursorGothic substitute) + JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 — apenas grid + utilitários + tabs + modais -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

    <style>
        /* =========================================================================
           DESIGN SYSTEM TOKENS — Cursor-inspired (DESIGN.md)
           ========================================================================= */
        :root {
            --ds-canvas:         #f7f7f4;
            --ds-canvas-soft:    #fafaf7;
            --ds-surface:        #ffffff;
            --ds-surface-strong: #e6e5e0;
            --ds-hairline:       #e6e5e0;
            --ds-hairline-soft:  #efeee8;
            --ds-hairline-strong:#cfcdc4;
            --ds-ink:            #26251e;
            --ds-body:           #5a5852;
            --ds-muted:          #807d72;
            --ds-muted-soft:     #a09c92;
            --ds-primary:        #f54e00;
            --ds-primary-active: #d04200;
            --ds-on-primary:     #ffffff;
            --ds-thinking:       #dfa88f;
            --ds-grep:           #9fc9a2;
            --ds-read:           #9fbbe0;
            --ds-edit:           #c0a8dd;
            --ds-done:           #c08532;
            --ds-success:        #1f8a65;
            --ds-error:          #cf2d56;
            --font-sans: 'Inter', system-ui, -apple-system, 'Helvetica Neue', sans-serif;
            --font-mono: 'JetBrains Mono', 'Fira Code', monospace;
            --r-xs:   4px;
            --r-sm:   6px;
            --r-md:   8px;
            --r-lg:   12px;
            --r-xl:   16px;
            --r-pill: 9999px;
            --s-xs:   8px;
            --s-sm:   12px;
            --s-md:   16px;
            --s-lg:   24px;
            --s-xl:   32px;
            --s-xxl:  48px;
        }

        /* ── Reset ───────────────────────────────────────────────────────── */
        body {
            background: var(--ds-canvas);
            font-family: var(--font-sans);
            font-weight: 400;
            color: var(--ds-body);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        a { color: var(--ds-ink); text-decoration: none; }
        a:hover { color: var(--ds-primary); }
        .text-muted { color: var(--ds-muted) !important; }
        .shadow, .shadow-sm, .shadow-lg { box-shadow: none !important; }
        .bg-dark { background-color: var(--ds-ink) !important; }
        .bg-black { background-color: #111 !important; }

        /* ── Typography scale ────────────────────────────────────────────── */
        .ds-display-sm  { font-size:22px; font-weight:400; line-height:1.3; letter-spacing:-0.11px; color:var(--ds-ink); }
        .ds-title-md    { font-size:18px; font-weight:600; line-height:1.4; color:var(--ds-ink); }
        .ds-title-sm    { font-size:16px; font-weight:600; line-height:1.4; color:var(--ds-ink); }
        .ds-body        { font-size:16px; font-weight:400; line-height:1.5; }
        .ds-body-sm     { font-size:14px; font-weight:400; line-height:1.5; }
        .ds-caption     { font-size:13px; font-weight:400; line-height:1.4; color:var(--ds-muted); }
        .ds-caption-caps{ font-size:11px; font-weight:600; line-height:1.4; letter-spacing:0.88px; text-transform:uppercase; color:var(--ds-muted); margin-bottom:0; }
        .ds-mono        { font-family:var(--font-mono); font-size:13px; font-weight:400; }
        .ds-mono-sm     { font-family:var(--font-mono); font-size:12px; font-weight:400; }
        .ds-text-ink    { color:var(--ds-ink) !important; }
        .ds-text-muted  { color:var(--ds-muted) !important; }

        /* ── Navbar ──────────────────────────────────────────────────────── */
        .ds-navbar {
            background: var(--ds-canvas);
            border-bottom: 1px solid var(--ds-hairline);
            height: 64px;
        }
        .ds-navbar-brand {
            font-family: var(--font-sans);
            font-size: 16px;
            font-weight: 500;
            color: var(--ds-ink);
            letter-spacing: -0.16px;
        }
        .ds-navbar-brand:hover { color: var(--ds-ink); }
        .ds-navbar-brand .d1 { color: var(--ds-primary); }
        .ds-navbar-brand .d2 { color: var(--ds-thinking); }
        .ds-navbar-brand .d3 { color: var(--ds-grep); }

        /* ── Pills ───────────────────────────────────────────────────────── */
        .ds-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: var(--r-pill);
            font-size: 11px; font-weight: 600; letter-spacing: 0.88px;
            text-transform: uppercase; line-height: 1.4;
        }
        .ds-pill-neutral  { background: var(--ds-surface-strong); color: var(--ds-ink); }
        .ds-pill-success  { background: #d4ede4; color: var(--ds-success); }
        .ds-pill-error    { background: #fce4e9; color: var(--ds-error); }
        .ds-pill-thinking { background: var(--ds-thinking); color: var(--ds-ink); }
        .ds-pill-grep     { background: var(--ds-grep); color: var(--ds-ink); }
        .ds-pill-read     { background: var(--ds-read); color: var(--ds-ink); }
        .ds-pill-edit     { background: var(--ds-edit); color: var(--ds-ink); }
        .ds-pill-done     { background: var(--ds-done); color: var(--ds-on-primary); }
        .ds-pill-sm       { padding: 2px 8px; font-size: 10px; }

        .ds-status-dot {
            width: 6px; height: 6px; border-radius: 50%; display: inline-block; flex-shrink: 0;
        }
        .ds-status-dot.online   { background: var(--ds-success); }
        .ds-status-dot.offline  { background: var(--ds-muted-soft); }
        .ds-status-dot.thinking { background: var(--ds-thinking); }

        .ds-pill-critical { border-left: 3px solid var(--ds-error); background: #fef5f7; border-radius: var(--r-sm); padding: 8px 12px; }
        .ds-pill-warning  { border-left: 3px solid var(--ds-done); background: #fdf8f0; border-radius: var(--r-sm); padding: 8px 12px; }
        .ds-pill-info     { border-left: 3px solid var(--ds-read); background: #f5f8fc; border-radius: var(--r-sm); padding: 8px 12px; }

        /* ── Buttons ─────────────────────────────────────────────────────── */
        .ds-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            font-family: var(--font-sans); font-size: 14px; font-weight: 500;
            padding: 10px 18px; height: 40px; border-radius: var(--r-md);
            border: none; cursor: pointer; transition: background 0.15s, opacity 0.15s;
            text-decoration: none; white-space: nowrap;
        }
        .ds-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .ds-btn-primary { background: var(--ds-primary); color: var(--ds-on-primary); }
        .ds-btn-primary:hover { background: var(--ds-primary-active); color: var(--ds-on-primary); }
        .ds-btn-secondary { background: var(--ds-surface); color: var(--ds-ink); border: 1px solid var(--ds-hairline-strong); }
        .ds-btn-secondary:hover { background: var(--ds-canvas-soft); }
        .ds-btn-ghost { background: transparent; color: var(--ds-ink); }
        .ds-btn-ghost:hover { background: var(--ds-surface-strong); }
        .ds-btn-sm { font-size: 13px; padding: 6px 14px; height: 32px; }
        .ds-btn-xs { font-size: 12px; padding: 4px 10px; height: 28px; }
        .ds-btn-w100 { width: 100%; }

        /* ── Tabs ────────────────────────────────────────────────────────── */
        .ds-tabs {
            display: flex; gap: 0; border-bottom: 1px solid var(--ds-hairline);
            padding: 0; margin: 0 0 var(--s-lg) 0; list-style: none;
        }
        .ds-tab-btn {
            position: relative; padding: var(--s-sm) var(--s-lg);
            font-family: var(--font-sans); font-size: 14px; font-weight: 500; line-height: 1.4;
            color: var(--ds-muted); background: none; border: none; cursor: pointer;
            transition: color 0.15s;
        }
        .ds-tab-btn:hover { color: var(--ds-ink); }
        .ds-tab-btn.active { color: var(--ds-ink); font-weight: 600; }
        .ds-tab-btn.active::after {
            content: ''; position: absolute; bottom: -1px; left: var(--s-lg); right: var(--s-lg);
            height: 2px; background: var(--ds-primary); border-radius: 1px;
        }
        .ds-tab-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 20px; height: 20px; padding: 0 6px;
            border-radius: var(--r-pill); background: var(--ds-surface-strong);
            font-size: 11px; font-weight: 600; color: var(--ds-ink);
            margin-left: 6px;
        }

        /* ── Cards ───────────────────────────────────────────────────────── */
        .ds-card {
            background: var(--ds-surface);
            border: 1px solid var(--ds-hairline);
            border-radius: var(--r-lg);
        }
        .ds-card-header {
            padding: var(--s-md) var(--s-lg);
            border-bottom: 1px solid var(--ds-hairline);
            font-size: 14px; font-weight: 600; color: var(--ds-ink);
        }
        .ds-card-body { padding: var(--s-lg); }
        .ds-card-footer {
            padding: var(--s-sm) var(--s-lg);
            border-top: 1px solid var(--ds-hairline);
            font-size: 13px; color: var(--ds-muted);
        }

        /* ── Table ───────────────────────────────────────────────────────── */
        .ds-table {
            width: 100%; border-collapse: collapse; font-size: 14px;
        }
        .ds-table th {
            font-size: 11px; font-weight: 600; letter-spacing: 0.88px; text-transform: uppercase;
            color: var(--ds-muted); text-align: left; padding: var(--s-sm) var(--s-md);
            border-bottom: 1px solid var(--ds-hairline);
        }
        .ds-table td {
            padding: var(--s-sm) var(--s-md); color: var(--ds-ink);
            border-bottom: 1px solid var(--ds-hairline-soft);
        }
        .ds-table tbody tr:hover td { background: var(--ds-canvas-soft); }
        .ds-table tbody tr:last-child td { border-bottom: none; }
        .ds-cell-speed { font-size: 22px; font-weight: 400; letter-spacing: -0.11px; color: var(--ds-ink); }
        .ds-cell-speed-u { font-size: 11px; font-weight: 600; color: var(--ds-muted); text-transform: uppercase; letter-spacing: 0.88px; }

        /* ── Forms ───────────────────────────────────────────────────────── */
        .ds-input {
            background: var(--ds-surface); border: 1px solid var(--ds-hairline-strong);
            border-radius: var(--r-md); padding: 10px 14px; height: 44px;
            font-family: var(--font-sans); font-size: 14px; color: var(--ds-ink);
            transition: border-color 0.15s; width: 100%;
        }
        .ds-input:focus { border-color: var(--ds-primary); outline: none; box-shadow: 0 0 0 3px rgba(245,78,0,0.1); }
        .ds-input::placeholder { color: var(--ds-muted-soft); }
        .ds-input-sm { height: 36px; padding: 6px 12px; font-size: 13px; }
        .ds-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23807d72' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat; background-position: right 12px center; background-size: 14px;
            padding-right: 36px;
        }
        .ds-textarea {
            min-height: 120px; resize: vertical;
            font-family: var(--font-mono); font-size: 13px;
        }
        .ds-label {
            display: block; font-size: 13px; font-weight: 600; color: var(--ds-ink); margin-bottom: 6px;
        }
        .ds-label-hint { font-weight: 400; color: var(--ds-muted); font-size: 12px; margin-left: 4px; }

        /* ── Protocol toggle ─────────────────────────────────────────────── */
        .ds-proto-toggle {
            display: flex; gap: 0; background: var(--ds-surface-strong);
            border-radius: var(--r-md); padding: 3px;
        }
        .ds-proto-option {
            flex: 1; text-align: center; padding: 7px 12px; border-radius: var(--r-sm);
            font-size: 13px; font-weight: 500; color: var(--ds-muted);
            background: transparent; border: none; cursor: pointer; transition: all 0.15s;
        }
        .ds-proto-option.active { background: var(--ds-surface); color: var(--ds-ink); font-weight: 600; }

        /* ── Code block ──────────────────────────────────────────────────── */
        .ds-code-block {
            background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline);
            border-radius: var(--r-lg); padding: var(--s-md);
            font-family: var(--font-mono); font-size: 13px; line-height: 1.5;
            color: var(--ds-ink); overflow: auto; max-height: 400px;
            white-space: pre-wrap; word-break: break-all;
        }

        /* ── Alarm card ──────────────────────────────────────────────────── */
        .ds-alarm-card {
            display: flex; align-items: center; gap: var(--s-md); padding: var(--s-md);
            background: var(--ds-surface); border: 1px solid var(--ds-hairline);
            border-radius: var(--r-lg);
        }
        .ds-alarm-card:hover { background: var(--ds-canvas-soft); }
        .ds-alarm-sev {
            width: 4px; min-height: 48px; border-radius: 2px; flex-shrink: 0; align-self: stretch;
        }
        .ds-alarm-sev.critical { background: var(--ds-error); }
        .ds-alarm-sev.warning  { background: var(--ds-done); }
        .ds-alarm-sev.info     { background: var(--ds-read); }

        /* ── Media card ──────────────────────────────────────────────────── */
        .ds-media-card {
            background: var(--ds-surface); border: 1px solid var(--ds-hairline);
            border-radius: var(--r-lg); overflow: hidden; transition: border-color 0.15s;
        }
        .ds-media-card:hover { border-color: var(--ds-hairline-strong); }
        .ds-media-thumb { height: 140px; display: flex; align-items: center; justify-content: center; }
        .ds-media-thumb.img  { background: #fdf2ec; }
        .ds-media-thumb.vid  { background: #f3eff8; }
        .ds-media-thumb.aud  { background: #edf5ee; }
        .ds-media-thumb img  { width: 100%; height: 100%; object-fit: cover; }
        .ds-media-thumb-icon { font-size: 40px; }
        .ds-media-thumb.img .ds-media-thumb-icon { color: var(--ds-thinking); }
        .ds-media-thumb.vid .ds-media-thumb-icon { color: var(--ds-edit); }
        .ds-media-thumb.aud .ds-media-thumb-icon { color: var(--ds-grep); }
        .ds-media-info  { padding: var(--s-sm) var(--s-md) var(--s-xs); }
        .ds-media-actions { display: flex; gap: var(--s-xs); padding: 0 var(--s-md) var(--s-md); }

        /* ── Command status pills ────────────────────────────────────────── */
        .ds-cmd-pending  { background: var(--ds-thinking); color: var(--ds-ink); }
        .ds-cmd-queued   { background: var(--ds-surface-strong); color: var(--ds-ink); }
        .ds-cmd-sent     { background: var(--ds-read); color: var(--ds-ink); }
        .ds-cmd-executed { background: var(--ds-done); color: var(--ds-on-primary); }
        .ds-cmd-failed   { background: #fce4e9; color: var(--ds-error); }

        /* ── Origin badges ───────────────────────────────────────────────── */
        .ds-origin-alarm     { background: #f0ebf7; color: #6b4f9a; }
        .ds-origin-dashboard { background: var(--ds-surface-strong); color: var(--ds-ink); }

        /* ── Refresh indicator ───────────────────────────────────────────── */
        .ds-refresh-dot {
            width: 8px; height: 8px; border-radius: 50%; display: inline-block;
            background: var(--ds-muted-soft); transition: background 0.4s;
        }
        .ds-refresh-dot.pulsing { background: var(--ds-thinking); }

        /* ── Feedback ────────────────────────────────────────────────────── */
        .ds-feedback {
            padding: var(--s-sm) var(--s-md); border-radius: var(--r-md);
            font-size: 13px; font-weight: 500;
        }
        .ds-feedback-success { background: #d4ede4; color: var(--ds-success); }
        .ds-feedback-warning { background: #fdf5e6; color: var(--ds-done); }
        .ds-feedback-danger  { background: #fce4e9; color: var(--ds-error); }
        .ds-feedback-info    { background: #e8f0fb; color: #3b6cb4; }

        /* ── Modal overrides ─────────────────────────────────────────────── */
        .modal-content { border: 1px solid var(--ds-hairline); border-radius: var(--r-lg); }
        .modal-header   { border-bottom: 1px solid var(--ds-hairline); padding: var(--s-lg); }
        .modal-body     { padding: var(--s-lg); }

        /* ── Empty state ─────────────────────────────────────────────────── */
        .ds-empty {
            text-align: center; padding: var(--s-xxl) var(--s-lg);
            color: var(--ds-muted); font-size: 14px;
        }
        .ds-empty-icon { font-size: 32px; margin-bottom: var(--s-sm); display: block; }

        /* ── Section header ──────────────────────────────────────────────── */
        .ds-section-hdr {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: var(--s-md);
        }

        /* ── Misc Bootstrap overrides ────────────────────────────────────── */
        .tooltip-inner { font-family: var(--font-sans); font-size: 12px; border-radius: var(--r-sm); }
        .dropdown-menu { border: 1px solid var(--ds-hairline); border-radius: var(--r-md); box-shadow: none; }
        .tab-content > .tab-pane { display: none; }
        .tab-content > .active { display: block; }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════════
     NAVBAR
     ═══════════════════════════════════════════════════════════════ -->
<nav class="ds-navbar d-flex align-items-center px-4">
    <a href="/dashboard" class="ds-navbar-brand text-decoration-none">
        <span class="d1">●</span><span class="d2">●</span><span class="d3">●</span> Jimi IoT Hub
    </a>
    <div class="ms-auto d-flex align-items-center gap-3">
        <div class="d-flex align-items-center gap-2">
            <span class="ds-caption-caps">STATUS API</span>
            <span id="apiStatusBadge"
                  class="ds-pill ds-pill-<?php echo $apiStatus['color'] === 'success' ? 'success' : 'error'; ?>">
                <span class="ds-status-dot <?php echo $apiStatus['color'] === 'success' ? 'online' : 'offline'; ?>"></span>
                <span id="apiStatusLabel"><?php echo htmlspecialchars($apiStatus['label']); ?></span>
            </span>
        </div>
        <span id="apiStatusLast" class="ds-mono-sm ds-text-muted" title="Última comunicação (GMT-3)">
            <?php echo htmlspecialchars($apiStatus['last']); ?>
        </span>
    </div>
</nav>

<div class="container" style="max-width:1200px; padding-top:var(--s-xl); padding-bottom:var(--s-xxl)">

    <!-- ══════════════════════════════════════════════════════════════
         TABS
         ═══════════════════════════════════════════════════════════ -->
    <div class="ds-tabs" id="mainTab" role="tablist">
        <button class="ds-tab-btn active" id="tabCamerasBtn"
                data-bs-toggle="tab" data-bs-target="#tabCameras" type="button" role="tab">
            Câmeras<span class="ds-tab-badge" id="camerasCount"><?php echo count($devices); ?></span>
        </button>
        <button class="ds-tab-btn" id="tabAlarmsBtn"
                data-bs-toggle="tab" data-bs-target="#tabAlarms" type="button" role="tab">
            Alarmes<span class="ds-tab-badge"><?php echo count($alarms); ?></span>
        </button>
        <button class="ds-tab-btn" id="tabCmdBtn"
                data-bs-toggle="tab" data-bs-target="#tabCommands" type="button" role="tab">
            Comandos
        </button>
        <button class="ds-tab-btn" id="tabMediaBtn"
                data-bs-toggle="tab" data-bs-target="#tabMedia" type="button" role="tab">
            Mídia
        </button>
        <button class="ds-tab-btn" id="tabConfigBtn"
                data-bs-toggle="tab" data-bs-target="#tabConfig" type="button" role="tab">
            Configuração
        </button>
    </div>

    <div class="tab-content">

        <!-- ══════════════════════════════════════════════════════════
             TAB 1 — Câmeras
             ═══════════════════════════════════════════════════════ -->
        <div class="tab-pane fade show active" id="tabCameras" role="tabpanel">
            <div class="ds-section-hdr">
                <span class="ds-caption">
                    Atualizado: <span id="lastCamRefresh"><?php echo $serverTimeBrt; ?></span>
                    <span id="refreshDot" class="ds-refresh-dot ms-2" title="Atualizando..."></span>
                </span>
                <span class="ds-caption">Próximo refresh em <strong id="camCountdown">30</strong>s</span>
            </div>
            <div class="ds-card">
                <div class="table-responsive">
                    <table class="ds-table" id="camerasTable">
                        <thead>
                            <tr>
                                <th>Dispositivo</th>
                                <th>Ignição</th>
                                <th>Velocidade</th>
                                <th>Mapa</th>
                                <th style="text-align:right">Última Com. (GMT-3)</th>
                            </tr>
                        </thead>
                        <tbody id="camerasBody">
<?php if (empty($devices)): ?>
                            <tr><td colspan="5"><div class="ds-empty"><i class="bi bi-camera-video-off ds-empty-icon"></i>Nenhuma câmera conectada.</div></td></tr>
<?php else: foreach ($devices as $dev): ?>
                            <tr>
                                <td>
                                    <div class="ds-title-sm" style="margin-bottom:2px"><?php echo htmlspecialchars($dev['name']); ?></div>
                                    <span class="ds-mono-sm ds-text-muted"><?php echo htmlspecialchars($dev['imei']); ?></span>
                                </td>
                                <td>
                                    <span class="ds-pill ds-pill-sm <?php echo $dev['ign_status'] === 'ACC ON' ? 'ds-pill-grep' : 'ds-pill-neutral'; ?>">
                                        <?php echo $dev['ign_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="ds-cell-speed"><?php echo $dev['speed']; ?></span>
                                    <span class="ds-cell-speed-u">km/h</span>
                                </td>
                                <td>
                                    <?php if ($dev['has_gps']): ?>
                                    <a href="<?php echo htmlspecialchars($dev['map_url']); ?>"
                                       target="_blank" class="ds-btn ds-btn-primary ds-btn-sm">
                                        <i class="bi bi-geo-alt-fill"></i>Localizar
                                    </a>
                                    <?php else: ?>
                                    <span class="ds-caption">Sem GPS</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ds-mono-sm ds-text-muted" style="text-align:right"><?php echo $dev['last_comm']; ?></td>
                            </tr>
<?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             TAB 2 — Alarmes
             ═══════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabAlarms" role="tabpanel">
            <div class="d-flex flex-column gap-2">
<?php if (empty($alarms)): ?>
                <div class="ds-empty"><i class="bi bi-check-circle ds-empty-icon"></i>Nenhum alarme recente.</div>
<?php else: foreach ($alarms as $alarm):
    $severity = $alarm['severity'] ?? 'info';
    $isJtt   = ($alarm['msg_class'] === 1);
    $hasGps  = ($alarm['latitude'] && $alarm['longitude'] && $alarm['latitude'] != 0 && $alarm['longitude'] != 0);
    $mapUrl  = $hasGps ? "https://www.google.com/maps?q={$alarm['latitude']},{$alarm['longitude']}" : '';
    $jsImei  = json_encode($alarm['imei']);
    $jsLabel = json_encode($alarm['alarm_label']);
    $jsId    = (int)$alarm['id'];
    $jsName  = json_encode($alarm['name']);
?>
                <div class="ds-alarm-card">
                    <div class="ds-alarm-sev <?php echo $severity; ?>"></div>
                    <div class="flex-grow-1">
                        <div class="ds-title-sm"><?php echo htmlspecialchars($alarm['name']); ?></div>
                        <div class="ds-body-sm">
                            <span><?php echo htmlspecialchars($alarm['device_name'] ?: $alarm['imei']); ?></span>
                            <span class="ds-mono-sm ds-text-muted ms-2"><?php echo htmlspecialchars($alarm['imei']); ?></span>
                        </div>
                        <div class="ds-caption mt-1"><?php echo $alarm['occurred_at']; ?> &rarr; <?php echo $alarm['received_at']; ?></div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="ds-pill ds-pill-sm <?php echo $isJtt ? 'ds-pill-read' : 'ds-pill-grep'; ?>">
                            <?php echo $isJtt ? 'JT/T' : 'JIMI'; ?>
                        </span>
                        <?php if ($hasGps): ?>
                        <a href="<?php echo htmlspecialchars($mapUrl); ?>" target="_blank"
                           class="ds-btn ds-btn-ghost ds-btn-sm" title="Localizar no mapa">
                            <i class="bi bi-geo-alt-fill ds-text-muted"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($alarm['file_url'])): ?>
                        <a href="<?php echo htmlspecialchars($alarm['file_url']); ?>" target="_blank"
                           class="ds-btn ds-btn-ghost ds-btn-sm" title="Ver arquivo">
                            <i class="bi bi-file-play-fill ds-text-muted"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($isJtt): ?>
                        <button class="ds-btn ds-btn-primary ds-btn-xs"
                                <?php echo empty($alarm['alarm_label']) ? 'disabled title="Sem alarmLabel"' : ''; ?>
                                onclick="requestVideoUpload(<?php echo $jsImei; ?>, <?php echo $jsLabel; ?>, <?php echo $jsId; ?>, <?php echo $jsName; ?>)">
                            <i class="bi bi-cloud-upload"></i>Solicitar
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
<?php endforeach; endif; ?>
            </div>

            <div class="position-fixed bottom-0 end-0 p-3" style="z-index:1055">
                <div id="videoToast" class="toast align-items-center text-white border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body" id="videoToastMsg"></div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             TAB 3 — Comandos
             ═══════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabCommands" role="tabpanel">
            <div class="row g-4">
                <div class="col-xl-5 col-lg-6">
                    <div class="ds-card">
                        <div class="ds-card-header"><i class="bi bi-send me-2"></i>Enviar Comando</div>
                        <div class="ds-card-body d-flex flex-column gap-3">
                            <div>
                                <label class="ds-label">Dispositivo (IMEI)</label>
                                <select class="ds-input ds-select" id="cmdImei">
                                    <option value="">— Selecione —</option>
<?php foreach ($cmdDevices as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d['imei']); ?>">
                                        <?php echo htmlspecialchars($d['imei']); ?>
                                        <?php if (!empty($d['device_name'])): ?> — <?php echo htmlspecialchars($d['device_name']); ?><?php endif; ?>
                                    </option>
<?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="ds-label">Protocolo</label>
                                <div class="ds-proto-toggle" id="protoToggle">
                                    <button class="ds-proto-option active" data-proto="jimi" type="button">
                                        <i class="bi bi-camera-video me-1"></i>JIMI (JC400)
                                    </button>
                                    <button class="ds-proto-option" data-proto="jtt" type="button">
                                        <i class="bi bi-camera-reels me-1"></i>JT/T 808 (JC450)
                                    </button>
                                </div>
                            </div>
                            <div id="secJimi">
                                <div class="mb-2">
                                    <label class="ds-label">Preset</label>
                                    <select class="ds-input ds-input-sm ds-select" onchange="applyJimiPreset(this.value)">
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
                                <div>
                                    <label class="ds-label">Conteúdo <span class="ds-label-hint">proNo 128</span></label>
                                    <input type="text" class="ds-input" id="jimiContent"
                                           style="font-family:var(--font-mono);font-size:13px" placeholder="Ex: STATUS">
                                </div>
                            </div>
                            <div id="secJtt" style="display:none">
                                <div class="mb-2">
                                    <label class="ds-label">Preset JT/T</label>
                                    <select class="ds-input ds-input-sm ds-select" id="jttPresetSel" onchange="applyJttPreset()">
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
                                            <option value="37381|list">Listar recursos A/V</option>
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
                                            <option value="34817|foto">Foto instantânea</option>
                                            <option value="34818|midia">Consultar mídia armazenada</option>
                                        </optgroup>
                                        <optgroup label="Configuração (33028-33031)">
                                            <option value="33028|params">Consultar todos os parâmetros</option>
                                            <option value="33030|params_esp">Consultar params específicos</option>
                                            <option value="33031|info">Info do dispositivo</option>
                                        </optgroup>
                                        <optgroup label="Controle (33029)">
                                            <option value="33029|reset">Reset do terminal</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="ds-label">proNo</label>
                                    <input type="number" class="ds-input ds-input-sm" id="jttProNo" value="37121">
                                </div>
                                <div>
                                    <label class="ds-label">
                                        Parâmetros (JSON)
                                        <a href="https://docs.jimicloud.com/integration/integration.html" target="_blank" class="ds-label-hint">docs ↗</a>
                                    </label>
                                    <textarea class="ds-input ds-textarea" id="jttContent" rows="5"
                                              placeholder='{"dataType":0,"codeStreamType":0,"channel":"1",...}'></textarea>
                                </div>
                            </div>
                            <div id="cmdFeedback" class="d-none ds-feedback"></div>
                            <button class="ds-btn ds-btn-primary ds-btn-w100" id="btnSend" onclick="sendCommand()">
                                <i class="bi bi-send-fill me-1"></i>Enviar Comando
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-xl-7 col-lg-6">
                    <div class="ds-card">
                        <div class="ds-card-header d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-journal-text me-2"></i>Log de Comandos</span>
                            <div class="d-flex gap-2 align-items-center">
                                <span id="offlineBadge" class="ds-body-sm"></span>
                                <button class="ds-btn ds-btn-ghost ds-btn-sm" onclick="refreshCommands()" title="Atualizar">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>
                        <div class="table-responsive" style="max-height:440px;overflow-y:auto">
                            <table class="ds-table" style="font-size:13px">
                                <thead>
                                    <tr><th>IMEI</th><th>Comando</th><th>Origem</th><th>Status</th><th>Enviado</th><th>Resposta</th></tr>
                                </thead>
                                <tbody id="cmdHistory">
<?php if (empty($commands)): ?>
                                    <tr><td colspan="6"><div class="ds-empty"><i class="bi bi-terminal ds-empty-icon"></i>Nenhum comando no log.</div></td></tr>
<?php else: foreach ($commands as $cmd): ?>
                                    <tr>
                                        <td class="ds-mono-sm"><?php echo htmlspecialchars($cmd['imei']); ?></td>
                                        <td class="ds-mono-sm" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                            title="<?php echo htmlspecialchars($cmd['command']); ?>"><?php echo htmlspecialchars($cmd['command']); ?></td>
                                        <td>
                                            <?php
                                            $isVU = str_starts_with($cmd['command'] ?? '', 'VIDEOUPLOAD');
                                            $oCls = $isVU ? 'ds-origin-alarm' : 'ds-origin-dashboard';
                                            $oLbl = $isVU ? 'Alarme' : 'Dashboard';
                                            ?>
                                            <span class="ds-pill ds-pill-sm <?php echo $oCls; ?>"><?php echo $oLbl; ?></span>
                                        </td>
                                        <td><span class="ds-pill ds-pill-sm ds-cmd-<?php echo htmlspecialchars($cmd['status']); ?>"><?php echo strtoupper(htmlspecialchars($cmd['status'])); ?></span></td>
                                        <td class="ds-mono-sm ds-text-muted"><?php echo $cmd['created']; ?></td>
                                        <td class="ds-mono-sm ds-text-muted" style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($cmd['resp'] ?? '—'); ?></td>
                                    </tr>
<?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="ds-card-footer d-flex justify-content-between align-items-center">
                            <span class="ds-body-sm">
                                <span class="ds-pill ds-pill-sm ds-origin-alarm">Alarme</span> = VIDEOUPLOAD &nbsp;
                                <span class="ds-pill ds-pill-sm ds-origin-dashboard">Dashboard</span> = formulário
                            </span>
                            <span class="ds-caption">Refresh em <strong id="cmdCountdown">30</strong>s</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             TAB 4 — Mídia
             ═══════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabMedia" role="tabpanel">
            <div class="ds-section-hdr">
                <div>
                    <select id="mediaImeiFilter" class="ds-input ds-input-sm ds-select d-inline-block"
                            style="width:auto;min-width:240px" onchange="refreshMedia()">
                        <option value="">Todos os dispositivos</option>
<?php foreach ($cmdDevices as $d): ?>
                        <option value="<?php echo htmlspecialchars($d['imei']); ?>"><?php echo htmlspecialchars($d['imei']); ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <span class="ds-caption">Exibindo <strong id="mediaCount">0</strong> arquivo(s)</span>
            </div>
            <div id="mediaGallery" class="row g-3">
                <div class="col-12"><div class="ds-empty"><i class="bi bi-hourglass-split ds-empty-icon"></i>Carregando galeria...</div></div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             TAB 5 — Configuração
             ═══════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tabConfig" role="tabpanel">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="ds-card">
                        <div class="ds-card-header"><i class="bi bi-search me-2"></i>Consultar Dispositivo</div>
                        <div class="ds-card-body d-flex flex-column gap-3">
                            <select id="configImei" class="ds-input ds-select" style="font-size:13px">
                                <option value="">Selecione um dispositivo...</option>
<?php foreach ($cmdDevices as $d): ?>
                                <option value="<?php echo htmlspecialchars($d['imei']); ?>"><?php echo htmlspecialchars($d['imei']); ?></option>
<?php endforeach; ?>
                            </select>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="ds-btn ds-btn-secondary ds-btn-sm" onclick="queryDeviceInfo()"><i class="bi bi-info-circle me-1"></i>Info (33031)</button>
                                <button class="ds-btn ds-btn-secondary ds-btn-sm" onclick="queryAllParams()"><i class="bi bi-list-ul me-1"></i>Parâmetros (33028)</button>
                                <button class="ds-btn ds-btn-secondary ds-btn-sm" onclick="querySpecificParams()"><i class="bi bi-filter me-1"></i>Específicos (33030)</button>
                            </div>
                            <div id="configResult"><div class="ds-code-block">Selecione um dispositivo e uma ação.</div></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="ds-card">
                        <div class="ds-card-header"><i class="bi bi-pencil-square me-2"></i>Alterar Parâmetros (33027)</div>
                        <div class="ds-card-body d-flex flex-column gap-3">
                            <div>
                                <label class="ds-label">ID do Parâmetro</label>
                                <select id="paramId" class="ds-input ds-input-sm ds-select" onchange="updateParamHelp()">
                                    <option value="">Escolha um parâmetro...</option>
                                    <option value="1">1 — Intervalo de Heartbeat (s)</option>
                                    <option value="32">32 — Estratégia de envio</option>
                                    <option value="41">41 — Intervalo de envio padrão (s)</option>
                                    <option value="44">44 — Intervalo por distância (m)</option>
                                    <option value="85">85 — Velocidade máxima (km/h)</option>
                                    <option value="86">86 — Duração excesso velocidade (s)</option>
                                    <option value="87">87 — Tempo máx. condução (s)</option>
                                    <option value="19">19 — Endereço servidor principal</option>
                                    <option value="24">24 — Porta TCP servidor</option>
                                    <option value="49">49 — Raio cerca eletrônica (m)</option>
                                </select>
                            </div>
                            <div>
                                <label class="ds-label">Valor</label>
                                <input type="text" id="paramValue" class="ds-input" placeholder="Ex: 30">
                                <small id="paramHelp" class="d-block mt-1 ds-caption"></small>
                            </div>
                            <button class="ds-btn ds-btn-primary ds-btn-w100" onclick="setParam()">
                                <i class="bi bi-check-lg me-1"></i>Alterar Parâmetro
                            </button>
                            <div id="setParamResult" class="ds-body-sm"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->

    <!-- ══════════════════════════════════════════════════════════════
         MODAL — Detalhes do Comando
         ═══════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="cmdDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title ds-title-md" style="margin:0"><i class="bi bi-terminal me-2"></i>Detalhes do Comando</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <div class="d-flex gap-4">
                        <div><span class="ds-caption-caps">IMEI</span><br><span id="cmdDetailImei" class="ds-mono"></span></div>
                        <div><span class="ds-caption-caps">Status</span><br><span id="cmdDetailStatus"></span></div>
                        <div><span class="ds-caption-caps">Data</span><br><span id="cmdDetailCreated" class="ds-body-sm ds-text-muted"></span></div>
                    </div>
                    <div><label class="ds-label">Comando Enviado</label><pre id="cmdDetailCommand" class="ds-code-block"></pre></div>
                    <div><label class="ds-label">Resposta</label><pre id="cmdDetailResponse" class="ds-code-block"></pre></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL — Player de Vídeo
         ═══════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="videoPlayerModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="background:var(--ds-ink);color:var(--ds-canvas)">
                <div class="modal-header" style="border-bottom-color:rgba(255,255,255,0.1)">
                    <h5 class="modal-title" style="margin:0;color:inherit"><i class="bi bi-play-circle me-2"></i><span id="videoPlayerTitle">Player</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" style="background:#000">
                    <video id="videoPlayer" controls autoplay muted style="width:100%;max-height:70vh;background:#000"></video>
                    <div id="videoPlayerLoading" class="text-center py-5" style="color:var(--ds-canvas)">
                        <div class="spinner-border mb-2"></div>
                        <div class="ds-body-sm">Conectando ao stream...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         FOOTER
         ═══════════════════════════════════════════════════════════ -->
    <div class="text-center ds-caption mt-4" id="footerTime">
        Jimi Webhook System v2.0.0 &mdash; <span id="serverClock"><?php echo htmlspecialchars($serverTimeBrt); ?></span> GMT-3
    </div>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/flv.js@1.6.2/dist/flv.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ═══════════════════════════════════════════════════════════════════════
// Config
// ═══════════════════════════════════════════════════════════════════════
const DASH_TOKEN = <?php echo json_encode($dashToken); ?>;
const URL_CAMERAS = '/camerasdata';
const URL_SEND    = '/sendcommand';
const URL_STATUS  = '/commandstatus';
const hdrs = { 'X-Dashboard-Token': DASH_TOKEN };

// ═══════════════════════════════════════════════════════════════════════
// Utils
// ═══════════════════════════════════════════════════════════════════════
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
function pulse() { const d = document.getElementById('refreshDot'); if(d){d.classList.add('pulsing');setTimeout(()=>d.classList.remove('pulsing'),600);} }

// ═══════════════════════════════════════════════════════════════════════
// Protocol toggle (pill selector)
// ═══════════════════════════════════════════════════════════════════════
document.querySelectorAll('.ds-proto-option').forEach(b => {
    b.addEventListener('click', function() {
        document.querySelectorAll('.ds-proto-option').forEach(x => x.classList.remove('active'));
        this.classList.add('active');
        const p = this.dataset.proto;
        document.getElementById('secJimi').style.display = p === 'jimi' ? '' : 'none';
        document.getElementById('secJtt').style.display  = p === 'jtt'  ? '' : 'none';
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Presets
// ═══════════════════════════════════════════════════════════════════════
function applyJimiPreset(v) { if(v) document.getElementById('jimiContent').value = v; }

const jttDateNow = (o=0) => { const d=new Date(); d.setDate(d.getDate()+o); return String(d.getUTCFullYear()).slice(2)+String(d.getUTCMonth()+1).padStart(2,'0')+String(d.getUTCDate()).padStart(2,'0')+String(d.getUTCHours()).padStart(2,'0')+String(d.getUTCMinutes()).padStart(2,'0')+String(d.getUTCSeconds()).padStart(2,'0'); };

const JTT_PRESETS = {
    '37121|ch1':[37121,JSON.stringify({dataType:0,codeStreamType:0,channel:"1",videoIP:"189.22.240.43",videoTCPPort:"10002",videoUDPPort:0})],
    '37121|ch2':[37121,JSON.stringify({dataType:0,codeStreamType:0,channel:"2",videoIP:"189.22.240.43",videoTCPPort:"10002",videoUDPPort:0})],
    '37121|ch12':[37121,JSON.stringify({dataType:0,codeStreamType:0,channel:"1-2",videoIP:"189.22.240.43",videoTCPPort:"10002",videoUDPPort:0})],
    '128|videoupload':[128,'VIDEOUPLOAD,189.22.240.43,23010,ALARM_LABEL_AQUI,1-2-3'],
    '37381|list':[37381,JSON.stringify({beginTime:jttDateNow(-7),endTime:jttDateNow(0),mediaType:0,channelId:1,eventCode:0})],
    '37377|playback':[37377,JSON.stringify({serverLen:15,serverAddress:"189.22.240.43",tcpPort:10003,udpPort:0,channel:1,resourceType:0,codeType:0,storageType:0,playMethod:0,forwardRewind:0,beginTime:jttDateNow(-1),endTime:jttDateNow(0),instructionID:"playback_"+Date.now()})],
    '33283|ack':[33283,JSON.stringify({alarmSerialNo:0,type:0})],
    '37382|ftp':[37382,JSON.stringify({serverAddress:"189.22.240.43",serverPort:21,userName:"ftp_user",password:"Jimi@371##",path:"/",beginTime:jttDateNow(-1),endTime:jttDateNow(0),channelNo:1,fileType:0,storageType:0,codeType:0,instructionID:"ftp_"+Date.now()})],
    '33536|tts':[33536,JSON.stringify({flag:0,text:"Atenção, mensagem do sistema"})],
    '34817|foto':[34817,JSON.stringify({channel:1,photoCmd:1,timeInterval:0,saveFlag:0,resolution:0x04,quality:5,light:128,contrast:60,saturability:60,chroma:128})],
    '34818|midia':[34818,JSON.stringify({mediaType:2,channel:1,eventCode:0,beginTime:jttDateNow(-7),endTime:jttDateNow(0)})],
    '33028|params':[33028,'""'],
    '33030|params_esp':[33030,JSON.stringify({"44":"","41":"","32":"","1":""})],
    '33031|info':[33031,'{}'],
    '33029|reset':[33029,JSON.stringify({cmd:4,params:""})],
};

function applyJttPreset() {
    const v = document.getElementById('jttPresetSel').value;
    if (!v || !JTT_PRESETS[v]) return;
    const [p,c] = JTT_PRESETS[v];
    document.getElementById('jttProNo').value = p;
    document.getElementById('jttContent').value = c;
}

// ═══════════════════════════════════════════════════════════════════════
// Feedback
// ═══════════════════════════════════════════════════════════════════════
function showFeedback(type, msg) {
    const el = document.getElementById('cmdFeedback'); if(!el) return;
    el.className = 'ds-feedback ds-feedback-'+type; el.textContent = msg; el.classList.remove('d-none');
}
function showToast(type, msg, delay=5000) {
    const t = document.getElementById('videoToast'); if(!t) return;
    const m = {success:'bg-success',danger:'bg-danger',warning:'bg-warning',info:'bg-info'};
    t.className = 'toast align-items-center text-white border-0 '+(m[type]||'bg-secondary');
    document.getElementById('videoToastMsg').textContent = msg;
    new bootstrap.Toast(t,{delay}).show();
}

// ═══════════════════════════════════════════════════════════════════════
// Envio de comando
// ═══════════════════════════════════════════════════════════════════════
async function sendCommand() {
    const imei = document.getElementById('cmdImei').value.trim();
    const proto = document.querySelector('.ds-proto-option.active')?.dataset?.proto || 'jimi';
    if (!imei) { showFeedback('warning','Selecione um dispositivo.'); return; }

    let cmdContent, proNo, serverFlagId;
    if (proto === 'jimi') {
        cmdContent = document.getElementById('jimiContent').value.trim();
        proNo = 128; serverFlagId = 1;
        if (!cmdContent) { showFeedback('warning','Informe o conteúdo do comando.'); return; }
    } else {
        cmdContent = document.getElementById('jttContent').value.trim();
        proNo = parseInt(document.getElementById('jttProNo').value) || 37121;
        serverFlagId = 0;
        if (!cmdContent) { showFeedback('warning','Informe os parâmetros JSON.'); return; }
        if (proNo !== 128) { try { JSON.parse(cmdContent); } catch(e) { showFeedback('danger','JSON inválido: '+e.message); return; } }
    }

    const btn = document.getElementById('btnSend');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...';
    showFeedback('info','Enviando proNo '+proNo+' (serverFlagId='+serverFlagId+')...');

    try {
        const body = new URLSearchParams({imei,cmdContent,proNo,serverFlagId});
        const resp = await fetch(URL_SEND,{method:'POST',headers:{...hdrs,'Content-Type':'application/x-www-form-urlencoded'},body});
        const data = await resp.json();
        if (data.code === 0) { showFeedback('success','Comando enviado! ID #'+(data.command_id??'—')+' | '+data.msg); setTimeout(()=>refreshCommands(),1200); }
        else { showFeedback('danger','Falha ('+data.code+'): '+data.msg); }
    } catch(e) { showFeedback('danger','Erro de rede: '+e.message); }
    finally { btn.disabled=false; btn.innerHTML='<i class="bi bi-send-fill me-1"></i>Enviar Comando'; }
}

// ═══════════════════════════════════════════════════════════════════════
// VIDEOUPLOAD para alarmes JTT
// ═══════════════════════════════════════════════════════════════════════
async function requestVideoUpload(imei, alarmLabel, alarmId, alarmName) {
    if (!alarmLabel) { showToast('danger','Este alarme não possui alarmLabel.'); return; }
    const clean = alarmLabel.replace(/,/g,'');
    const cmdContent = 'VIDEOUPLOAD,189.22.240.43,23010,'+clean+',1-2-3';
    showToast('info','Solicitando upload do vídeo'+(alarmName?' ('+alarmName+')':'')+' — IMEI: '+imei);
    try {
        const body = new URLSearchParams({imei,cmdContent,proNo:'128',serverFlagId:'0'});
        const resp = await fetch(URL_SEND,{method:'POST',headers:{...hdrs,'Content-Type':'application/x-www-form-urlencoded'},body});
        const data = await resp.json();
        if (data.code === 0) { showToast('success','VIDEOUPLOAD solicitado! ID #'+(data.command_id??'—')); setTimeout(()=>refreshCommands(),1200); }
        else { showToast('danger','Falha: '+data.msg); }
    } catch(e) { showToast('danger','Erro de rede: '+e.message); }
}

// ═══════════════════════════════════════════════════════════════════════
// Histórico de comandos
// ═══════════════════════════════════════════════════════════════════════
async function refreshCommands() {
    try {
        const resp = await fetch(URL_STATUS+'?limit=30',{headers:hdrs}); if(!resp.ok) return;
        const data = await resp.json(); if(data.code !== 0) return;
        const oc = data.offline_count||0;
        const ob = document.getElementById('offlineBadge');
        if(ob) ob.innerHTML = oc>0 ? '<span class="ds-pill ds-pill-sm ds-pill-thinking"><i class="bi bi-wifi-off me-1"></i>'+oc+' resp. offline</span>' : '';
        const tbody = document.getElementById('cmdHistory'); if(!tbody) return;
        if(!data.commands||!data.commands.length) { tbody.innerHTML='<tr><td colspan="6"><div class="ds-empty"><i class="bi bi-terminal ds-empty-icon"></i>Nenhum comando no log.</div></td></tr>'; return; }
        tbody.innerHTML = data.commands.map(c => {
            const sc = {pending:'ds-cmd-pending',queued:'ds-cmd-queued',sent:'ds-cmd-sent',executed:'ds-cmd-executed',failed:'ds-cmd-failed'}[c.status]||'ds-pill-neutral';
            const resp = c.response ? String(c.response).substring(0,100) : '—';
            const cmd = (c.command||'').substring(0,50);
            const rawR = c.response??c.raw_response??''; const rawC = c.command??'';
            const obadge = c.origin==='alarm' ? '<span class="ds-pill ds-pill-sm ds-origin-alarm ms-1">Alarme</span>' : (c.origin?'<span class="ds-pill ds-pill-sm ds-origin-dashboard ms-1">'+esc(c.origin)+'</span>':'');
            return '<tr onclick="showCommandDetail('+esc(JSON.stringify(rawC))+','+esc(JSON.stringify(rawR))+',\''+esc(c.imei)+'\',\''+esc(c.status)+'\',\''+esc(c.created)+'\')" style="cursor:pointer"><td class="ds-mono-sm">'+esc(c.imei)+'</td><td class="ds-mono-sm" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+esc(c.command)+'">'+esc(cmd)+'</td><td><span class="ds-pill ds-pill-sm '+sc+'">'+esc(c.status.toUpperCase())+'</span>'+obadge+'</td><td class="ds-mono-sm ds-text-muted">'+esc(c.created)+'</td><td class="ds-mono-sm ds-text-muted" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(resp)+'</td></tr>';
        }).join('');
    } catch(e) { console.warn('refreshCommands:',e.message); }
}

// ═══════════════════════════════════════════════════════════════════════
// Modal de detalhes
// ═══════════════════════════════════════════════════════════════════════
function prettyJson(v) { if(!v||v==='—') return v; try{return JSON.stringify(typeof v==='object'?v:JSON.parse(v),null,2)}catch{return String(v)} }
function showCommandDetail(command, response, imei, status, created) {
    const m = document.getElementById('cmdDetailModal'); if(!m) return;
    document.getElementById('cmdDetailImei').textContent = imei;
    document.getElementById('cmdDetailCreated').textContent = created;
    document.getElementById('cmdDetailCommand').textContent = prettyJson(command);
    document.getElementById('cmdDetailResponse').textContent = prettyJson(response)||'—';
    const sc = {pending:'ds-cmd-pending',queued:'ds-cmd-queued',sent:'ds-cmd-sent',executed:'ds-cmd-executed',failed:'ds-cmd-failed'}[status.toLowerCase()]||'ds-pill-neutral';
    document.getElementById('cmdDetailStatus').innerHTML = '<span class="ds-pill ds-pill-sm '+sc+'">'+status.toUpperCase()+'</span>';
    new bootstrap.Modal(m).show();
}

// ═══════════════════════════════════════════════════════════════════════
// Refresh de câmeras
// ═══════════════════════════════════════════════════════════════════════
let camCountdown = 30;
async function refreshCameras() {
    pulse();
    try {
        const resp = await fetch(URL_CAMERAS,{headers:hdrs}); if(!resp.ok) return;
        const data = await resp.json(); if(data.code !== 0) return;
        const badge = document.getElementById('apiStatusBadge');
        badge.className = 'ds-pill ds-pill-'+(data.apiStatus.color==='success'?'success':'error');
        const dot = badge.querySelector('.ds-status-dot');
        if(dot) dot.className = 'ds-status-dot '+(data.apiStatus.color==='success'?'online':'offline');
        document.getElementById('apiStatusLabel').textContent = data.apiStatus.label;
        document.getElementById('apiStatusLast').textContent = data.apiStatus.last;
        document.getElementById('camerasCount').textContent = data.count;
        const tbody = document.getElementById('camerasBody');
        if(!data.devices||!data.devices.length) {
            tbody.innerHTML = '<tr><td colspan="5"><div class="ds-empty"><i class="bi bi-camera-video-off ds-empty-icon"></i>Nenhuma câmera conectada.</div></td></tr>';
        } else {
            tbody.innerHTML = data.devices.map(d => {
                const ip = d.ign_status==='ACC ON'?'ds-pill-grep':'ds-pill-neutral';
                const mb = d.has_gps ? '<a href="'+esc(d.map_url)+'" target="_blank" class="ds-btn ds-btn-primary ds-btn-sm"><i class="bi bi-geo-alt-fill"></i>Localizar</a>' : '<span class="ds-caption">Sem GPS</span>';
                return '<tr><td><div class="ds-title-sm" style="margin-bottom:2px">'+esc(d.name)+'</div><span class="ds-mono-sm ds-text-muted">'+esc(d.imei)+'</span></td><td><span class="ds-pill ds-pill-sm '+ip+'">'+esc(d.ign_status)+'</span></td><td><span class="ds-cell-speed">'+d.speed+'</span><span class="ds-cell-speed-u">km/h</span></td><td>'+mb+'</td><td class="ds-mono-sm ds-text-muted" style="text-align:right">'+esc(d.last_comm)+'</td></tr>';
            }).join('');
        }
        document.getElementById('lastCamRefresh').textContent = data.serverTime;
        document.getElementById('serverClock').textContent = data.serverTime;
    } catch(e) { console.warn('refreshCameras:',e.message); }
}
const camCdEl = document.getElementById('camCountdown');
setInterval(() => { if(--camCountdown<=0){camCountdown=30;refreshCameras();} if(camCdEl) camCdEl.textContent = camCountdown; }, 1000);
document.addEventListener('visibilitychange', () => { if(!document.hidden) refreshCameras(); });

// ═══════════════════════════════════════════════════════════════════════
// Timers de comando
// ═══════════════════════════════════════════════════════════════════════
let cmdCountdown = 30;
const cmdCdEl = document.getElementById('cmdCountdown');
setInterval(() => { cmdCountdown--; if(cmdCdEl) cmdCdEl.textContent = cmdCountdown; if(cmdCountdown<=0){cmdCountdown=30;refreshCommands();} }, 1000);
const tabCmdBtn = document.getElementById('tabCmdBtn');
if(tabCmdBtn) tabCmdBtn.addEventListener('shown.bs.tab', () => refreshCommands());
document.addEventListener('visibilitychange', () => { if(!document.hidden){refreshCameras();refreshCommands();} });

// ═══════════════════════════════════════════════════════════════════════
// Configuração
// ═══════════════════════════════════════════════════════════════════════
async function queryConfig(proNo, payload) {
    const imei = document.getElementById('configImei').value; if(!imei) return;
    const result = document.getElementById('configResult');
    result.innerHTML = '<div class="ds-code-block">Consultando...</div>';
    try {
        const body = new URLSearchParams({imei,cmdContent:payload,proNo:String(proNo),serverFlagId:'0'});
        const resp = await fetch(URL_SEND,{method:'POST',headers:{...hdrs,'Content-Type':'application/x-www-form-urlencoded'},body});
        const data = await resp.json();
        result.innerHTML = '<div class="ds-code-block">'+esc(prettyJson(data))+'</div>';
    } catch(e) { result.innerHTML = '<div class="ds-code-block">Erro: '+esc(e.message)+'</div>'; }
}
async function queryDeviceInfo() { await queryConfig(33031,'{}'); }
async function queryAllParams() { await queryConfig(33028,'""'); }
async function querySpecificParams() { await queryConfig(33030,JSON.stringify({"44":"","41":"","32":"","1":""})); }

function updateParamHelp() {
    const sel = document.getElementById('paramId'); const help = document.getElementById('paramHelp'); if(!help) return;
    const t = {'1':'Intervalo em segundos entre heartbeats.','32':'0=Tempo, 1=Distância, 2=Tempo+Distância.','41':'Intervalo de envio por tempo (segundos).','44':'Intervalo de envio por distância (metros).','85':'Velocidade máxima. Exceder gera alarme.','86':'Duração acima do limite para alarme (s).','87':'Tempo máximo de condução contínua (s).','19':'Endereço IP/domínio do servidor principal.','24':'Porta TCP do servidor principal.','49':'Raio da cerca eletrônica em metros.'};
    help.textContent = t[sel.value]||'';
}

async function setParam() {
    const imei = document.getElementById('configImei').value;
    const pid = document.getElementById('paramId').value;
    const pval = document.getElementById('paramValue').value.trim();
    const result = document.getElementById('setParamResult');
    if(!imei||!pid||!pval) { result.innerHTML='<span class="ds-feedback ds-feedback-warning d-inline-block">Preencha IMEI, parâmetro e valor.</span>'; return; }
    result.innerHTML = '<span class="ds-caption">Enviando alteração...</span>';
    try {
        const payload = JSON.stringify({[pid]:pval});
        const body = new URLSearchParams({imei,cmdContent:payload,proNo:'33027',serverFlagId:'0'});
        const resp = await fetch(URL_SEND,{method:'POST',headers:{...hdrs,'Content-Type':'application/x-www-form-urlencoded'},body});
        const data = await resp.json();
        result.innerHTML = data.code===0 ? '<span class="ds-feedback ds-feedback-success d-inline-block"><i class="bi bi-check-circle me-1"></i>Parâmetro '+pid+' alterado com sucesso.</span>' : '<span class="ds-feedback ds-feedback-danger d-inline-block">Falha ('+data.code+'): '+data.msg+'</span>';
    } catch(e) { result.innerHTML = '<span class="ds-feedback ds-feedback-danger d-inline-block">Erro: '+esc(e.message)+'</span>'; }
}

// ═══════════════════════════════════════════════════════════════════════
// Galeria de Mídia
// ═══════════════════════════════════════════════════════════════════════
async function refreshMedia() {
    const imei = document.getElementById('mediaImeiFilter')?.value||'';
    const gallery = document.getElementById('mediaGallery'); if(!gallery) return;
    gallery.innerHTML = '<div class="col-12"><div class="ds-empty"><i class="bi bi-hourglass-split ds-empty-icon"></i>Carregando...</div></div>';
    try {
        const params = new URLSearchParams(); if(imei) params.set('imei',imei);
        const resp = await fetch('/mediadata?'+params.toString(),{headers:hdrs}); if(!resp.ok) throw new Error('HTTP '+resp.status);
        const data = await resp.json();
        document.getElementById('mediaCount').textContent = data.files?data.files.length:0;
        if(!data.files||!data.files.length) { gallery.innerHTML='<div class="col-12"><div class="ds-empty"><i class="bi bi-film ds-empty-icon"></i>Nenhum arquivo de mídia encontrado.</div></div>'; return; }
        gallery.innerHTML = data.files.map(f => {
            const type = f.media_type||'other';
            const icon = type==='image'?'bi-file-image':type==='video'?'bi-file-play':type==='audio'?'bi-file-music':'bi-file';
            const isImg = type==='image'&&f.url;
            const thumb = isImg ? '<img src="'+esc(f.url)+'" alt="'+esc(f.file_name)+'" loading="lazy">' : '<i class="bi '+icon+' ds-media-thumb-icon"></i>';
            const dlUrl = f.url||f.download_url||'';
            const tcls = type==='image'?'img':type==='video'?'vid':'aud';
            return '<div class="col-xl-4 col-md-6"><div class="ds-media-card"><div class="ds-media-thumb '+tcls+'">'+thumb+'</div><div class="ds-media-info"><div class="ds-mono-sm ds-text-ink" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+esc(f.file_name)+'">'+esc(f.file_name)+'</div><div class="ds-caption mt-1">'+esc(f.gateway_time||'—')+' · '+esc(f.imei||'—')+'</div></div><div class="ds-media-actions">'+(dlUrl?'<a href="'+esc(dlUrl)+'" target="_blank" class="ds-btn ds-btn-secondary ds-btn-xs"><i class="bi bi-download me-1"></i>Download</a>':'')+(type==='video'&&dlUrl?'<button class="ds-btn ds-btn-ghost ds-btn-xs" onclick="playVideo(\''+esc(dlUrl)+'\',\''+esc(f.file_name)+'\')"><i class="bi bi-play-fill me-1"></i>Play</button>':'')+'</div></div></div>';
        }).join('');
    } catch(e) { gallery.innerHTML='<div class="col-12"><div class="ds-empty"><span class="ds-empty-icon"><i class="bi bi-exclamation-triangle"></i></span>Erro ao carregar: '+esc(e.message)+'</div></div>'; }
}

function playVideo(url, title) {
    const modal = document.getElementById('videoPlayerModal');
    const video = document.getElementById('videoPlayer');
    const loading = document.getElementById('videoPlayerLoading');
    document.getElementById('videoPlayerTitle').textContent = title||'Player';
    if(loading) loading.style.display='block';
    if(video){video.style.display='none';video.src=url;video.style.display='';video.play();if(loading)loading.style.display='none';}
    new bootstrap.Modal(modal).show();
    if(modal) modal.addEventListener('hidden.bs.modal',()=>{if(video){video.pause();video.src='';}},{once:true});
}

const tabMediaBtn = document.getElementById('tabMediaBtn');
if(tabMediaBtn) tabMediaBtn.addEventListener('shown.bs.tab',()=>refreshMedia());
</script>
</body>
</html>
