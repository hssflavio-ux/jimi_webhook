<?php
/**
 * JIMI Webhook System — Layout Base NavTrack-Inspired v3.1.0
 *
 * Layout two-column: sidebar esquerda + área de conteúdo principal.
 * Usa a paleta Cursor-inspired do DESIGN.md com adaptações NavTrack.
 *
 * Variáveis esperadas:
 *   $page_title      — Título da página (aparece no header)
 *   $current_route   — Rota ativa para highlight no menu
 *   $user            — Array do usuário atual (get_jimi_user())
 *   $customer        — Cliente ativo (get_customer())
 *   $customers       — Lista de clientes disponíveis (get_available_customers())
 *   $body_class      — Classe CSS extra no body (ex: 'ativo-detalhe')
 *   $extra_head      — HTML extra no <head> (CSS/JS específicos da página)
 *
 * O conteúdo da página é o HTML após o include deste arquivo.
 */

require_once __DIR__ . '/../includes/auth.php';

if (!isset($page_title))    $page_title    = 'Painel';
if (!isset($current_route)) $current_route = 'dashboard';
if (!isset($user))          $user          = get_jimi_user();
if (!isset($customer))      $customer      = get_customer();
if (!isset($customers))     $customers     = get_available_customers($user['id'] ?? 0);

$navLinks = [
    ['route' => 'dashboard',  'label' => 'Painel',      'icon' => 'grid',     'href' => '/dashboard'],
    ['route' => 'live',       'label' => 'Ao Vivo',     'icon' => 'map',      'href' => '/live'],
    ['route' => 'ativos',     'label' => 'Ativos',      'icon' => 'camera',   'href' => '/ativos'],
    ['route' => 'relatorios', 'label' => 'Relatórios',   'icon' => 'chart',    'href' => '/relatorios'],
    ['route' => 'video',      'label' => 'Vídeo',       'icon' => 'play',     'href' => '/video'],
    ['route' => 'comandos',   'label' => 'Comandos',     'icon' => 'terminal', 'href' => '/comandos'],
    ['route' => 'config',     'label' => 'Configuração', 'icon' => 'gear',     'href' => '/config'],
];
if (($user['role'] ?? '') === 'admin') {
    $navLinks[] = ['route' => 'clientes', 'label' => 'Clientes', 'icon' => 'people', 'href' => '/clientes'];
    $navLinks[] = ['route' => 'usuarios', 'label' => 'Usuários', 'icon' => 'user',   'href' => '/usuarios'];
}

function nav_icon($name) {
    $icons = [
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'map'      => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
        'camera'   => '<path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/>',
        'chart'    => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'play'     => '<polygon points="5 3 19 12 5 21 5 3" fill="currentColor"/>',
        'terminal' => '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
        'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/>',
        'people'  => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>',
        'user'    => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'logout'  => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    ];
    return $icons[$name] ?? '';
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JIMI — <?= htmlspecialchars($page_title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* ═══════════════════════════════════════════════════════════
   JIMI Design System v3.1.0 — NavTrack-inspired Layout
   Paleta: Cursor-inspired (#f54e00 primary, #f7f7f4 canvas)
   ═══════════════════════════════════════════════════════════ */
:root {
    --primary: #f54e00;
    --primary-active: #d04200;
    --primary-soft: #fff3ed;
    --ink: #26251e;
    --body: #5a5852;
    --body-strong: #26251e;
    --muted: #807d72;
    --muted-soft: #a09c92;
    --canvas: #f7f7f4;
    --canvas-soft: #fafaf7;
    --surface: #ffffff;
    --surface-strong: #e6e5e0;
    --hairline: #e6e5e0;
    --hairline-soft: #efeee8;
    --hairline-strong: #cfcdc4;
    --error: #cf2d56;
    --success: #1f8a65;
    --warning: #c08532;
    --info: #9fbbe0;
    --peach: #dfa88f;
    --mint: #9fc9a2;
    --blue: #9fbbe0;
    --lavender: #c0a8dd;
    --gold: #c08532;
    --radius-sm: 6px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --sidebar-w: 240px;
    --asset-sidebar-w: 200px;
    --header-h: 56px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    font-size: 14px;
    font-weight: 400;
    line-height: 1.5;
    color: var(--body);
    background: var(--canvas);
    display: flex;
    min-height: 100vh;
}

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar {
    width: var(--sidebar-w);
    min-width: var(--sidebar-w);
    background: var(--surface);
    border-right: 1px solid var(--hairline);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0; bottom: 0;
    z-index: 100;
    overflow-y: auto;
}

.sidebar-brand {
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.sidebar-brand-dots { display: flex; gap: 5px; }
.sidebar-brand-dots span { width: 8px; height: 8px; border-radius: 50%; }
.sb-p1 { background: var(--primary); }
.sb-p2 { background: var(--peach); }
.sb-p3 { background: var(--mint); }

.sidebar-brand-name {
    font-size: 16px;
    font-weight: 600;
    color: var(--ink);
    letter-spacing: -0.3px;
}

.sidebar-brand-version {
    font-size: 11px;
    color: var(--muted-soft);
    font-family: 'JetBrains Mono', monospace;
    margin-left: auto;
}

/* Customer Selector */
.sidebar-customer {
    padding: 8px 16px 14px;
}

.sidebar-customer select {
    width: 100%;
    padding: 8px 10px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    border: 1px solid var(--hairline);
    border-radius: var(--radius-sm);
    background: var(--canvas);
    color: var(--ink);
    cursor: pointer;
}
.sidebar-customer select:focus { outline: none; border-color: var(--primary); }

/* Nav */
.sidebar-nav {
    flex: 1;
    padding: 4px 12px;
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    font-size: 13px;
    font-weight: 500;
    color: var(--body);
    text-decoration: none;
    border-radius: var(--radius-sm);
    transition: background .1s, color .1s;
}
.sidebar-nav a:hover { background: var(--canvas); color: var(--ink); }
.sidebar-nav a.active { background: var(--primary-soft); color: var(--primary); }

.sidebar-nav a svg {
    width: 18px; height: 18px;
    stroke: currentColor; stroke-width: 2; fill: none;
    stroke-linecap: round; stroke-linejoin: round;
    flex-shrink: 0;
}

.sidebar-nav-divider {
    height: 1px;
    background: var(--hairline);
    margin: 6px 12px;
}

/* Sidebar Footer */
.sidebar-footer {
    border-top: 1px solid var(--hairline);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.sidebar-footer-avatar {
    width: 30px; height: 30px;
    border-radius: 50%;
    background: var(--blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    color: var(--ink);
    flex-shrink: 0;
}

.sidebar-footer-info { flex: 1; min-width: 0; }
.sidebar-footer-name { font-size: 13px; font-weight: 500; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sidebar-footer-role { font-size: 11px; color: var(--muted); }

.sidebar-footer-logout {
    color: var(--muted);
    text-decoration: none;
    display: flex; align-items: center; justify-content: center;
    width: 30px; height: 30px;
    border-radius: var(--radius-sm);
    transition: background .1s, color .1s;
}
.sidebar-footer-logout:hover { background: #fef2f5; color: var(--error); }
.sidebar-footer-logout svg { width: 16px; height: 16px; stroke: currentColor; stroke-width: 2; fill: none; stroke-linecap: round; stroke-linejoin: round; }

/* ── Main ────────────────────────────────────────────── */
.main {
    flex: 1;
    margin-left: var(--sidebar-w);
    min-width: 0;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

.main-header {
    background: var(--surface);
    border-bottom: 1px solid var(--hairline);
    padding: 0 28px;
    height: var(--header-h);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 50;
}

.main-header-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--ink);
    letter-spacing: -0.2px;
}

.main-header-meta {
    font-size: 12px;
    color: var(--muted);
    display: flex; align-items: center; gap: 16px;
}

.main-content {
    flex: 1;
    padding: 28px;
}

/* ── When asset detail sidebar is shown ──────────────── */
.with-asset-sidebar .main-content-inner {
    display: flex;
    gap: 0;
}

/* secondary sidebar inside main content */
.asset-sidebar {
    width: var(--asset-sidebar-w);
    min-width: var(--asset-sidebar-w);
    border-right: 1px solid var(--hairline-soft);
    display: flex;
    flex-direction: column;
    padding: 12px 0;
}

.asset-sidebar-header {
    padding: 8px 16px 14px;
    border-bottom: 1px solid var(--hairline-soft);
    margin-bottom: 8px;
}

.asset-sidebar-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
    margin-bottom: 2px;
}

.asset-sidebar-imei {
    font-size: 11px;
    color: var(--muted);
    font-family: 'JetBrains Mono', monospace;
}

.asset-sidebar-nav {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 0 8px;
    gap: 1px;
}

.asset-sidebar-nav a {
    display: block;
    padding: 7px 12px;
    font-size: 13px;
    font-weight: 500;
    color: var(--body);
    text-decoration: none;
    border-radius: var(--radius-sm);
    transition: background .1s, color .1s;
}
.asset-sidebar-nav a:hover { background: var(--canvas); color: var(--ink); }
.asset-sidebar-nav a.active { background: var(--primary-soft); color: var(--primary); }

.asset-content {
    flex: 1;
    min-width: 0;
    padding: 24px 28px;
}

.asset-content-full {
    flex: 1;
    min-width: 0;
}

/* ── Cards ───────────────────────────────────────────── */
.card {
    background: var(--surface);
    border: 1px solid var(--hairline);
    border-radius: var(--radius-lg);
    padding: 20px;
}

/* ── KPI Grid ────────────────────────────────────────── */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.kpi-item {
    background: var(--surface);
    border: 1px solid var(--hairline);
    border-radius: var(--radius-lg);
    padding: 20px;
}

.kpi-item-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--muted);
    margin-bottom: 6px;
}

.kpi-item-value {
    font-size: 28px;
    font-weight: 600;
    color: var(--ink);
    letter-spacing: -0.5px;
    line-height: 1.1;
}

.kpi-item-delta {
    font-size: 12px;
    color: var(--muted);
    margin-top: 4px;
}

/* ── Table ───────────────────────────────────────────── */
.table-wrap {
    background: var(--surface);
    border: 1px solid var(--hairline);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

thead th {
    text-align: left;
    padding: 10px 16px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--muted);
    background: var(--canvas-soft);
    border-bottom: 1px solid var(--hairline);
}

tbody td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--hairline-soft);
    color: var(--body);
}

tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: var(--canvas-soft); }

/* ── Badges / Pills ──────────────────────────────────── */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    font-size: 11px;
    font-weight: 500;
    border-radius: 9999px;
    background: var(--surface-strong);
    color: var(--body);
}
.badge-success { background: #e8f5ef; color: var(--success); }
.badge-error   { background: #fef2f5; color: var(--error); }
.badge-warning { background: #fdf3e8; color: var(--warning); }
.badge-info    { background: #eef4fa; color: #5a7fa8; }
.badge-primary { background: var(--primary-soft); color: var(--primary); }

/* ── Buttons ─────────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    font-size: 13px;
    font-weight: 500;
    font-family: 'Inter', sans-serif;
    border: none;
    border-radius: var(--radius-md);
    cursor: pointer;
    text-decoration: none;
    transition: background .15s, opacity .15s;
    white-space: nowrap;
}

.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-active); }

.btn-outline {
    background: transparent;
    color: var(--body);
    border: 1px solid var(--hairline);
}
.btn-outline:hover { background: var(--canvas); color: var(--ink); }

.btn-sm { padding: 5px 12px; font-size: 12px; }

/* ── Forms ───────────────────────────────────────────── */
.form-group { margin-bottom: 16px; }
.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--ink);
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 8px 12px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    border: 1px solid var(--hairline);
    border-radius: var(--radius-sm);
    color: var(--ink);
    background: var(--canvas);
    transition: border-color .15s;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline: none; border-color: var(--primary); }
.form-group textarea { resize: vertical; font-family: 'JetBrains Mono', monospace; font-size: 13px; }

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/* ── Empty State ─────────────────────────────────────── */
.empty-state {
    text-align: center;
    padding: 48px 24px;
    color: var(--muted);
}
.empty-state-icon { font-size: 36px; margin-bottom: 12px; opacity: 0.5; }
.empty-state h3 { font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px; }
.empty-state p { font-size: 13px; }

/* ── Utilities ───────────────────────────────────────── */
.text-muted { color: var(--muted); }
.text-mono { font-family: 'JetBrains Mono', monospace; font-size: 12px; }
.text-right { text-align: right; }
.mt-16 { margin-top: 16px; }
.mt-24 { margin-top: 24px; }
.mb-16 { margin-bottom: 16px; }
.mb-24 { margin-bottom: 24px; }
.flex { display: flex; }
.flex-between { display: flex; justify-content: space-between; align-items: center; }
.flex-gap { gap: 8px; }
.w-full { width: 100%; }

/* ── Toast / Feedback ────────────────────────────────── */
.toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 12px 20px;
    border-radius: var(--radius-md);
    font-size: 13px;
    font-weight: 500;
    z-index: 9999;
    box-shadow: none;
    border: 1px solid var(--hairline);
    background: var(--surface);
    color: var(--ink);
    display: none;
}
.toast-show { display: block; }
.toast-success { border-color: var(--mint); background: #f0faf5; color: var(--success); }
.toast-error   { border-color: #fce4eb; background: #fef2f5; color: var(--error); }

/* ── Scrollbar ───────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--hairline-strong); border-radius: 3px; }

/* ── Date Filter ─────────────────────────────────────── */
.date-filter {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 0;
}
.date-filter input[type="date"] {
    padding: 6px 10px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    border: 1px solid var(--hairline);
    border-radius: var(--radius-sm);
    color: var(--ink);
    background: var(--surface);
}
.date-filter input[type="date"]:focus { outline: none; border-color: var(--primary); }
.date-filter-label { font-size: 12px; color: var(--muted); font-weight: 500; }

</style>
<?= $extra_head ?? '' ?>
</head>
<body class="<?= $body_class ?? '' ?>">
<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-dots">
            <span class="sb-p1"></span><span class="sb-p2"></span><span class="sb-p3"></span>
        </div>
        <div class="sidebar-brand-name">JIMI</div>
        <div class="sidebar-brand-version">v<?= getenv('SYSTEM_VERSION') ?: '3.1' ?></div>
    </div>

    <div class="sidebar-customer">
        <select onchange="switchCustomer(this.value)" title="Selecionar cliente">
            <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($customer['id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($navLinks as $link): ?>
        <a href="<?= $link['href'] ?>" class="<?= $current_route === $link['route'] ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><?= nav_icon($link['icon']) ?></svg>
            <?= $link['label'] ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="/perfil" style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;text-decoration:none" title="Meu perfil">
            <div class="sidebar-footer-avatar"><?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?></div>
            <div class="sidebar-footer-info">
                <div class="sidebar-footer-name"><?= htmlspecialchars($user['name'] ?? 'Usuário') ?></div>
                <div class="sidebar-footer-role"><?= $user['role'] ?? '' ?></div>
            </div>
        </a>
        <a href="/logout" class="sidebar-footer-logout" title="Sair">
            <svg viewBox="0 0 24 24"><?= nav_icon('logout') ?></svg>
        </a>
    </div>
</aside>

<!-- Main -->
<main class="main">
    <header class="main-header">
        <div class="main-header-title"><?= htmlspecialchars($page_title) ?></div>
        <div class="main-header-meta">
            <?php if ($customer): ?>
            <span><?= htmlspecialchars($customer['name']) ?></span>
            <?php endif; ?>
            <span id="server-clock" class="text-mono">--</span>
        </div>
    </header>

    <div class="main-content">

<script>
function switchCustomer(id) {
    fetch('/customer_switch', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ customer_id: parseInt(id) })
    }).then(r => r.json()).then(data => {
        if (data.code === 0) location.reload();
    });
}
function updateClock() {
    const el = document.getElementById('server-clock');
    if (el) el.textContent = new Date().toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });
}
updateClock();
setInterval(updateClock, 30000);
</script>
