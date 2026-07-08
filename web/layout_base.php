<?php
/**
 * JIMI Webhook System — Layout Base v4.0.0 (YUV Parity)
 *
 * Layout two-column com sidebar dark + accordion groups + header fleet counter.
 * Design system Coinbase (ver DESIGN.md / DESIGN-coinbase.md).
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

// ── YUV Navigation (v4.0.0): principal + accordion groups ──
$navPrincipal = [
    ['route' => 'resumo',        'label' => 'Resumo',        'icon' => 'grid',     'href' => '/'],
    ['route' => 'rastreamento',  'label' => 'Rastreamento',  'icon' => 'map',      'href' => '/rastreamento'],
    ['route' => 'bi',            'label' => 'BI',            'icon' => 'chart',    'href' => '/bi'],
    ['route' => 'ocorrencias',   'label' => 'Dashboard',     'icon' => 'alert',    'href' => '/ocorrencias/dashboard'],
];

$navGroups = [
    'videos' => [
        'label' => 'Vídeos',
        'icon'  => 'play',
        'items' => [
            ['route' => 'video_aovivo',    'label' => 'Ao Vivo',   'href' => '/video/aovivo'],
            ['route' => 'video_playback',  'label' => 'Playback',  'href' => '/video/playback'],
            ['route' => 'video_downloads', 'label' => 'Downloads', 'href' => '/video/downloads'],
        ],
    ],
    'relatorios' => [
        'label' => 'Relatórios',
        'icon'  => 'file',
        'items' => [
            ['route' => 'rel_posicoes',       'label' => 'Posições',       'href' => '/relatorios/posicoes'],
            ['route' => 'rel_deslocamento',   'label' => 'Deslocamento',    'href' => '/relatorios/deslocamento'],
            ['route' => 'rel_desatualizados', 'label' => 'Desatualizados',  'href' => '/relatorios/desatualizados'],
            ['route' => 'rel_alarmes',        'label' => 'Alarmes',         'href' => '/relatorios/alarmes'],
            ['route' => 'rel_ocorrencias',    'label' => 'Ocorrências',     'href' => '/relatorios/ocorrencias'],
        ],
    ],
    'cadastros' => [
        'label' => 'Cadastros',
        'icon'  => 'folder',
        'items' => [
            ['route' => 'ativos',              'label' => 'Ativos',              'href' => '/ativos'],
            ['route' => 'chips',               'label' => 'Chips',               'href' => '/chips'],
            ['route' => 'clientes',            'label' => 'Clientes',            'href' => '/clientes'],
            ['route' => 'equipamentos',        'label' => 'Equipamentos',        'href' => '/equipamentos'],
            ['route' => 'grupos-permissao',    'label' => 'Grupos de Permissão', 'href' => '/grupos-permissao'],
            ['route' => 'motoristas',          'label' => 'Motoristas',          'href' => '/motoristas'],
            ['route' => 'config-ocorrencias',  'label' => 'Config. Ocorrências', 'href' => '/config-ocorrencias'],
            ['route' => 'usuarios',            'label' => 'Usuários',            'href' => '/usuarios'],
        ],
    ],
];

// Bottom-level nav items (after groups)
$navBottom = [
    ['route' => 'comandos', 'label' => 'Comandos', 'icon' => 'terminal', 'href' => '/comandos'],
    ['route' => 'exportar', 'label' => 'Exportar', 'icon' => 'download', 'href' => '/exportar'],
];

// Legacy compatibility: also match 'dashboard' → 'resumo', 'live' → 'rastreamento'
if ($current_route === 'dashboard') $current_route = 'resumo';
if ($current_route === 'live') $current_route = 'rastreamento';
if ($current_route === 'video') $current_route = 'video_aovivo';
if ($current_route === 'relatorios') $current_route = 'rel_alarmes';

function brand_adjust_hex($hex, $percent) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = max(0, min(255, hexdec(substr($hex, 0, 2)) + $percent));
    $g = max(0, min(255, hexdec(substr($hex, 2, 2)) + $percent));
    $b = max(0, min(255, hexdec(substr($hex, 4, 2)) + $percent));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
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
        'file'    => '<path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/><polyline points="13 2 13 9 20 9"/>',
        'folder'  => '<path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>',
        'alert'   => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'download'=> '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
    ];
    return $icons[$name] ?? '';
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>JIMI — <?= htmlspecialchars($page_title) ?></title>
<!-- PWA -->
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0052ff">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="JIMI">
<link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* ═══════════════════════════════════════════════════════════
   JIMI Design System v4.0.0 — Coinbase (ver DESIGN.md / DESIGN-coinbase.md)
   Paleta: azul #0052ff (única voltagem), canvas branco, sidebar dark #0a0b0d, CTAs pill
   ═══════════════════════════════════════════════════════════ */
:root {
    /* ── Coinbase Design System (v4.0.0) ───────────────────
       Azul #0052ff = única voltagem de marca (CTAs, links, foco).
       Canvas branco, sidebar dark near-black, geometria pill. */
    --primary: #0052ff;          /* Coinbase Blue */
    --primary-active: #003ecc;
    --primary-disabled: #a8b8cc;
    --primary-soft: #eaf0ff;
    --ink: #0a0b0d;
    --body: #5b616e;
    --body-strong: #0a0b0d;
    --muted: #7c828a;
    --muted-soft: #a8acb3;
    --canvas: #ffffff;
    --canvas-soft: #f7f7f7;       /* surface-soft (bandas alternadas) */
    --surface: #ffffff;
    --surface-strong: #eef0f3;
    --surface-dark: #0a0b0d;       /* sidebar / heros escuros */
    --surface-dark-elevated: #16181c;
    --on-dark: #ffffff;
    --on-dark-soft: #a8acb3;
    --hairline: #dee1e6;
    --hairline-soft: #eef0f3;
    --hairline-strong: #cbd0d8;
    --error: #cf202f;              /* semantic-down (só texto/borda) */
    --success: #05b169;            /* semantic-up */
    --warning: #f4b000;            /* accent-yellow (ilustrativo) */
    --info: #0052ff;
    --accent-yellow: #f4b000;
    /* aliases legados (compat) mapeados para a paleta Coinbase */
    --peach: #a8acb3;
    --mint: #05b169;
    --blue: #0052ff;
    --lavender: #7c828a;
    --gold: #f4b000;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --radius-xl: 24px;
    --radius-pill: 100px;
    --shadow-soft: 0 4px 12px rgba(0,0,0,.04);
    --sidebar-w: 244px;
    --asset-sidebar-w: 200px;
    --header-h: 64px;
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
    background: var(--surface-dark);
    border-right: 1px solid #22252b;
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
    color: var(--on-dark);
    letter-spacing: -0.3px;
}

.sidebar-brand-version {
    font-size: 11px;
    color: var(--on-dark-soft);
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
    border: 1px solid #2a2d33;
    border-radius: var(--radius-sm);
    background: var(--surface-dark-elevated);
    color: var(--on-dark);
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
    padding: 9px 12px;
    font-size: 13px;
    font-weight: 500;
    color: var(--on-dark-soft);
    text-decoration: none;
    border-radius: var(--radius-sm);
    transition: background .1s, color .1s;
}
.sidebar-nav a:hover { background: var(--surface-dark-elevated); color: var(--on-dark); }
.sidebar-nav a.active { background: var(--primary); color: #fff; }

.sidebar-nav a svg {
    width: 18px; height: 18px;
    stroke: currentColor; stroke-width: 2; fill: none;
    stroke-linecap: round; stroke-linejoin: round;
    flex-shrink: 0;
}

.sidebar-nav-divider {
    height: 1px;
    background: #22252b;
    margin: 6px 12px;
}

/* Sidebar Footer */
.sidebar-footer {
    border-top: 1px solid #22252b;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.sidebar-footer-avatar {
    width: 30px; height: 30px;
    border-radius: 9999px;
    background: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    flex-shrink: 0;
}

.sidebar-footer-info { flex: 1; min-width: 0; }
.sidebar-footer-name { font-size: 13px; font-weight: 500; color: var(--on-dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sidebar-footer-role { font-size: 11px; color: var(--on-dark-soft); }

.sidebar-footer-logout {
    color: var(--on-dark-soft);
    text-decoration: none;
    display: flex; align-items: center; justify-content: center;
    width: 30px; height: 30px;
    border-radius: var(--radius-sm);
    transition: background .1s, color .1s;
}
.sidebar-footer-logout:hover { background: var(--surface-dark-elevated); color: #ff6b7a; }
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
/* Coinbase: superfícies planas + hairline; um único nível de sombra no hover */
.card {
    background: var(--surface);
    border: 1px solid var(--hairline);
    border-radius: var(--radius-lg);
    padding: 20px;
    transition: box-shadow .15s;
}
.card:hover { box-shadow: var(--shadow-soft); }

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
    font-family: 'JetBrains Mono', monospace;   /* Coinbase: números em mono */
    font-size: 28px;
    font-weight: 500;
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
    overflow-x: auto;              /* tabela larga rola dentro do card, nunca a página */
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
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
.badge-success { background: #e4f7ee; color: #05914f; }
.badge-error   { background: #fdeaec; color: var(--error); }
.badge-warning { background: #fdf3d6; color: #a97a00; }
.badge-info    { background: var(--primary-soft); color: var(--primary); }
.badge-primary { background: var(--primary-soft); color: var(--primary); }

/* ── Buttons ─────────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 600;
    font-family: 'Inter', sans-serif;
    border: none;
    border-radius: var(--radius-pill);   /* Coinbase: CTA sempre pill */
    cursor: pointer;
    text-decoration: none;
    transition: background .15s, opacity .15s;
    white-space: nowrap;
}

.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-active); }
.btn-primary:disabled, .btn-primary.is-disabled { background: var(--primary-disabled); cursor: not-allowed; }

/* Secundário: pill cinza sobre branco (surface-strong) */
.btn-outline {
    background: var(--surface-strong);
    color: var(--ink);
    border: none;
}
.btn-outline:hover { background: var(--hairline); color: var(--ink); }

/* Escuro: pill near-black (ex.: ações em bandas claras) */
.btn-dark { background: var(--ink); color: #fff; }
.btn-dark:hover { background: var(--surface-dark-elevated); }

.btn-sm { padding: 6px 14px; font-size: 13px; }

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
    padding: 11px 14px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    border: 1px solid var(--hairline);
    border-radius: var(--radius-md);
    color: var(--ink);
    background: var(--canvas);
    transition: border-color .15s, box-shadow .15s;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary); }
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
.text-mono { font-family: 'JetBrains Mono', monospace; font-size: 12px; white-space: nowrap; }
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

/* ── Sidebar Accordion Groups (v4.0.0) ──────────────── */
.sidebar-accordion { }
.sidebar-accordion-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    font-size: 13px;
    font-weight: 500;
    color: var(--on-dark-soft);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background .1s, color .1s;
    user-select: none;
}
.sidebar-accordion-header:hover { background: var(--surface-dark-elevated); color: var(--on-dark); }
.sidebar-accordion-header svg {
    width: 18px; height: 18px;
    stroke: currentColor; stroke-width: 2; fill: none;
    stroke-linecap: round; stroke-linejoin: round;
    flex-shrink: 0;
}
.sidebar-accordion-header .chevron {
    margin-left: auto;
    transition: transform .2s;
    width: 14px; height: 14px;
}
.sidebar-accordion.open .sidebar-accordion-header .chevron { transform: rotate(180deg); }
.sidebar-accordion-body {
    display: none;
    padding-left: 28px;
}
.sidebar-accordion.open .sidebar-accordion-body { display: block; }

/* Group divider */
.sidebar-nav-divider {
    height: 1px;
    background: #22252b;
    margin: 6px 12px;
}

/* ── Header Fleet Counter ────────────────────────────── */
.fleet-counter {
    display: inline-flex;
    align-items: center;
    gap: 14px;
    font-size: 12px;
    font-weight: 500;
}
.fleet-counter-item {
    display: flex;
    align-items: center;
    gap: 5px;
}
.fleet-counter-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.fleet-counter-dot.on { background: var(--success); }
.fleet-counter-dot.off { background: var(--muted-soft); }
.fleet-counter-val {
    font-family: 'JetBrains Mono', monospace;
    font-weight: 500;
    color: var(--ink);
}

/* ── Sidebar Collapse Button ──────────────────────────── */
.sidebar-collapse-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px; height: 28px;
    border-radius: var(--radius-sm);
    border: none;
    background: transparent;
    color: var(--on-dark-soft);
    cursor: pointer;
    transition: background .1s;
}
.sidebar-collapse-btn:hover { background: var(--surface-dark-elevated); color: var(--on-dark); }
.sidebar-collapse-btn svg { width: 16px; height: 16px; }

/* Sidebar collapsed state */
.sidebar.collapsed {
    width: 64px; min-width: 64px;
}
.sidebar.collapsed .sidebar-brand-name,
.sidebar.collapsed .sidebar-brand-version,
.sidebar.collapsed .sidebar-customer,
.sidebar.collapsed .sidebar-nav a span,
.sidebar.collapsed .sidebar-accordion-header span,
.sidebar.collapsed .sidebar-accordion-body,
.sidebar.collapsed .sidebar-footer-info,
.sidebar.collapsed .sidebar-nav-divider { display: none; }
.sidebar.collapsed .sidebar-nav a,
.sidebar.collapsed .sidebar-accordion-header { justify-content: center; padding: 10px; }
.sidebar.collapsed .sidebar-footer { justify-content: center; }
.sidebar.collapsed + .main { margin-left: 64px; }

/* ── Responsive / PWA off-canvas ─────────────────────── */
.sidebar-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(10, 11, 13, 0.55);
    z-index: 99;
    opacity: 0;
    transition: opacity .25s ease;
}
.sidebar-backdrop.show { opacity: 1; }

body.sidebar-locked { overflow: hidden; }

@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform .25s cubic-bezier(0.4, 0, 0.2, 1);
        width: min(300px, 84vw);
        min-width: 0;
        box-shadow: none;
        touch-action: pan-y;
        overscroll-behavior: contain;
    }
    .sidebar.mobile-open {
        transform: translateX(0);
        box-shadow: 8px 0 32px rgba(0, 0, 0, 0.35);
    }
    .sidebar.collapsed { width: min(300px, 84vw); min-width: 0; } /* colapso não se aplica em mobile */
    .sidebar-backdrop { display: block; pointer-events: none; }
    .sidebar-backdrop.show { pointer-events: auto; }
    .sidebar-collapse-btn { display: none; }
    .main { margin-left: 0 !important; }
    .main-header { padding: 0 12px; }
    .main-content { padding: 16px 12px; }

    /* Header compacto: sem relógio, nome do cliente truncado */
    #server-clock { display: none; }
    .main-header-meta { min-width: 0; gap: 10px; }
    .main-header-meta > span:first-of-type {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 100px;
    }

    /* Touch targets ≥ 44×44px (sidebar + header) */
    .sidebar-nav a,
    .sidebar-accordion-header { min-height: 44px; padding: 12px; font-size: 14px; }
    .sidebar-accordion-body a { min-height: 44px; }
    .sidebar-footer-logout { width: 44px; height: 44px; }
    .sidebar-footer { padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); }
    .sidebar-customer select { min-height: 44px; }
    .btn { min-height: 44px; }
    .btn-sm { min-height: 40px; }

    /* Tabelas: scroll horizontal no container, nunca na página */
    thead th, tbody td { white-space: nowrap; }
    .form-row { grid-template-columns: 1fr; }
    .kpi-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
    .kpi-item { padding: 14px; }
    .kpi-item-value { font-size: 22px; }
}

/* ── Hamburger (mobile) ──────────────────────────────── */
.hamburger {
    display: none;
    width: 32px; height: 32px;
    align-items: center; justify-content: center;
    border: none; background: transparent;
    cursor: pointer; color: var(--ink);
}
@media (max-width: 768px) { .hamburger { display: inline-flex; width: 44px; height: 44px; } }

</style>
<?php
if (!empty($customer['brand_color'])) {
    $brd = $customer['brand_color'];
    $brd_soft  = brand_adjust_hex($brd, 200);
    $brd_act   = brand_adjust_hex($brd, -30);
    echo "<style>:root{--primary:{$brd};--primary-active:{$brd_act};--primary-soft:{$brd_soft};--info:{$brd};--blue:{$brd};}</style>";
}
?>
<?= $extra_head ?? '' ?>
</head>
<body class="<?= $body_class ?? '' ?>">
<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-dots">
            <span class="sb-p1"></span><span class="sb-p2"></span><span class="sb-p3"></span>
        </div>
        <div class="sidebar-brand-name">JIMI</div>
        <div class="sidebar-brand-version">v<?= getenv('SYSTEM_VERSION') ?: '4.0' ?></div>
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
        <?php
        // Principal items
        foreach ($navPrincipal as $link):
            $active = ($current_route === $link['route']) ? 'active' : '';
        ?>
        <a href="<?= $link['href'] ?>" class="<?= $active ?>">
            <svg viewBox="0 0 24 24"><?= nav_icon($link['icon']) ?></svg>
            <span><?= $link['label'] ?></span>
        </a>
        <?php endforeach; ?>

        <!-- Accordion Groups -->
        <?php
        $accordionIdx = 0;
        foreach ($navGroups as $groupKey => $group):
            $accordionIdx++;
            $itemRoutes = array_column($group['items'], 'route');
            $isOpen = in_array($current_route, $itemRoutes);
            $groupId = 'accordion-' . $groupKey;
        ?>
        <div class="sidebar-nav-divider"></div>
        <div class="sidebar-accordion <?= $isOpen ? 'open' : '' ?>" id="<?= $groupId ?>">
            <div class="sidebar-accordion-header" onclick="toggleAccordion('<?= $groupId ?>')">
                <svg viewBox="0 0 24 24"><?= nav_icon($group['icon']) ?></svg>
                <span><?= $group['label'] ?></span>
                <svg class="chevron" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <?= nav_icon('chevron-down') ?>
                </svg>
            </div>
            <div class="sidebar-accordion-body">
                <?php foreach ($group['items'] as $item):
                    $active = ($current_route === $item['route']) ? 'active' : '';
                ?>
                <a href="<?= $item['href'] ?>" class="<?= $active ?>">
                    <span><?= $item['label'] ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="sidebar-nav-divider"></div>

        <?php
        // Bottom items
        foreach ($navBottom as $link):
            $active = ($current_route === $link['route']) ? 'active' : '';
        ?>
        <a href="<?= $link['href'] ?>" class="<?= $active ?>">
            <svg viewBox="0 0 24 24"><?= nav_icon($link['icon']) ?></svg>
            <span><?= $link['label'] ?></span>
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

<!-- Backdrop mobile (fecha a sidebar off-canvas ao tocar fora) -->
<div class="sidebar-backdrop" id="sidebar-backdrop" onclick="closeMobileSidebar()"></div>

<!-- Main -->
<main class="main">
    <header class="main-header">
        <div class="flex" style="align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleMobileSidebar()" title="Menu">
                <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round">
                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
            <button class="sidebar-collapse-btn" onclick="toggleSidebar()" title="Colapsar sidebar">
                <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/>
                </svg>
            </button>
            <div class="main-header-title"><?= htmlspecialchars($page_title) ?></div>
        </div>
        <div class="main-header-meta">
            <?php if ($customer): ?>
            <span><?= htmlspecialchars($customer['name']) ?></span>
            <?php endif; ?>
            <div class="fleet-counter" id="fleet-counter">
                <div class="fleet-counter-item">
                    <div class="fleet-counter-dot on"></div>
                    <span>On</span>
                    <span class="fleet-counter-val" id="fleet-on">--</span>
                </div>
                <div class="fleet-counter-item">
                    <div class="fleet-counter-dot off"></div>
                    <span>Off</span>
                    <span class="fleet-counter-val" id="fleet-off">--</span>
                </div>
            </div>
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

// ── Accordion Toggle ──────────────────────────────────
function toggleAccordion(groupId) {
    var el = document.getElementById(groupId);
    if (!el) return;
    el.classList.toggle('open');
    // Persist state
    var state = JSON.parse(localStorage.getItem('jimi_accordion') || '{}');
    state[groupId] = el.classList.contains('open');
    localStorage.setItem('jimi_accordion', JSON.stringify(state));
}

// Restore accordion state from localStorage
(function() {
    var state = JSON.parse(localStorage.getItem('jimi_accordion') || '{}');
    Object.keys(state).forEach(function(k) {
        var el = document.getElementById(k);
        if (el) {
            if (state[k]) el.classList.add('open');
            else el.classList.remove('open');
        }
    });
})();

// ── Sidebar Collapse ──────────────────────────────────
function toggleSidebar() {
    var sb = document.getElementById('sidebar');
    if (!sb) return;
    sb.classList.toggle('collapsed');
    localStorage.setItem('jimi_sidebar_collapsed', sb.classList.contains('collapsed'));
}
(function() {
    if (localStorage.getItem('jimi_sidebar_collapsed') === 'true') {
        var sb = document.getElementById('sidebar');
        if (sb) sb.classList.add('collapsed');
    }
})();

// ── Mobile Sidebar (off-canvas + backdrop + scroll lock + swipe) ──
function openMobileSidebar() {
    var sb = document.getElementById('sidebar');
    var bd = document.getElementById('sidebar-backdrop');
    if (!sb) return;
    sb.classList.add('mobile-open');
    if (bd) bd.classList.add('show');
    document.body.classList.add('sidebar-locked');
}
function closeMobileSidebar() {
    var sb = document.getElementById('sidebar');
    var bd = document.getElementById('sidebar-backdrop');
    if (!sb) return;
    sb.classList.remove('mobile-open');
    if (bd) bd.classList.remove('show');
    document.body.classList.remove('sidebar-locked');
}
function toggleMobileSidebar() {
    var sb = document.getElementById('sidebar');
    if (!sb) return;
    if (sb.classList.contains('mobile-open')) closeMobileSidebar();
    else openMobileSidebar();
}

// Swipe para a esquerda fecha a sidebar (touch)
(function() {
    var sb = document.getElementById('sidebar');
    if (!sb) return;
    var startX = null, startY = null;
    sb.addEventListener('touchstart', function(e) {
        if (!sb.classList.contains('mobile-open')) return;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
    }, { passive: true });
    sb.addEventListener('touchend', function(e) {
        if (startX === null) return;
        var dx = e.changedTouches[0].clientX - startX;
        var dy = Math.abs(e.changedTouches[0].clientY - startY);
        if (dx < -50 && dy < 60) closeMobileSidebar();
        startX = startY = null;
    }, { passive: true });
})();

// ── Fleet Counter Polling ─────────────────────────────
(function() {
    var elOn = document.getElementById('fleet-on');
    var elOff = document.getElementById('fleet-off');
    var container = document.getElementById('fleet-counter');
    if (!elOn || !elOff || !container) return;

    function fetchFleet() {
        fetch('/camerasdata')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var devices = data.data || data.devices || [];
                var onCount = 0, offCount = 0;
                devices.forEach(function(d) {
                    if (d.online || d.status === 'online' || d.is_online) onCount++;
                    else offCount++;
                });
                elOn.textContent = onCount;
                elOff.textContent = offCount;
            })
            .catch(function() {});
    }
    fetchFleet();
    setInterval(fetchFleet, 30000);
})();

// ── Clock ─────────────────────────────────────────────
function updateClock() {
    const el = document.getElementById('server-clock');
    if (el) el.textContent = new Date().toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });
}
updateClock();
setInterval(updateClock, 30000);
</script>
