<?php
/**
 * JIMI Webhook System — Wiki / Central de Ajuda v4.2.1
 * Rota: /wiki
 *
 * Documentação completa do sistema com mockups visuais de todas as telas,
 * ações disponíveis e resultados esperados. Linguagem acessível para o operador.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title    = 'Central de Ajuda';
$current_route = 'wiki';

$extra_head = <<<'HEAD'
<style>
/* ── Wiki Layout ──────────────────────────────────── */
.wiki-wrap {
    display: flex;
    gap: 0;
    max-width: 1200px;
    margin: -28px;
    min-height: calc(100vh - 130px);
}
.wiki-toc {
    width: 260px;
    min-width: 260px;
    background: #f8f9fb;
    border-right: 1px solid var(--hairline-soft);
    padding: 28px 20px;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
}
.wiki-toc h4 {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    margin-bottom: 12px;
}
.wiki-toc a {
    display: block;
    padding: 6px 10px;
    font-size: 13px;
    color: var(--body);
    text-decoration: none;
    border-radius: var(--radius-sm);
    margin-bottom: 2px;
    transition: background .1s, color .1s;
    line-height: 1.4;
}
.wiki-toc a:hover, .wiki-toc a.active {
    background: var(--primary-soft);
    color: var(--primary);
}
.wiki-content {
    flex: 1;
    min-width: 0;
    padding: 28px 36px;
    overflow-y: auto;
}
.wiki-content h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--ink);
    margin: 0 0 8px 0;
    letter-spacing: -0.3px;
    padding-top: 20px;
    border-top: 1px solid var(--hairline-soft);
}
.wiki-content h2:first-of-type { border-top: 0; padding-top: 0; }
.wiki-content h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--ink);
    margin: 32px 0 10px 0;
}
.wiki-content h3 .badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 100px;
    background: var(--primary-soft);
    color: var(--primary);
    margin-left: 8px;
    vertical-align: middle;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.wiki-content p {
    font-size: 14px;
    line-height: 1.7;
    color: var(--body);
    margin: 0 0 14px 0;
}
.wiki-content .intro {
    font-size: 15px;
    line-height: 1.8;
    color: var(--body);
    margin-bottom: 24px;
}
/* ── Mockup Card ──────────────────────────────────── */
.mockup {
    background: #fff;
    border: 1px solid var(--hairline);
    border-radius: var(--radius);
    overflow: hidden;
    margin: 16px 0 24px 0;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.mockup-header {
    padding: 12px 16px;
    background: #f5f6f8;
    border-bottom: 1px solid var(--hairline);
    font-size: 11px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    display: flex; align-items: center; gap: 8px;
}
.mockup-header::before {
    content: '';
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--primary);
}
.mockup-body {
    padding: 20px 24px;
}
/* ── KPI Cards (mockup) ───────────────────────────── */
.kpi-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}
.kpi-box {
    background: #f8f9fb;
    border: 1px solid var(--hairline-soft);
    border-radius: var(--radius);
    padding: 14px 18px;
}
.kpi-box .kpi-label {
    font-size: 11px;
    font-weight: 500;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 6px;
}
.kpi-box .kpi-val {
    font-family: 'JetBrains Mono', monospace;
    font-size: 26px;
    font-weight: 600;
    color: var(--ink);
}
.kpi-box.blue  .kpi-val { color: var(--primary); }
.kpi-box.green .kpi-val { color: #098551; }
.kpi-box.yellow .kpi-val { color: #b25000; }
.kpi-box.red   .kpi-val { color: #c83532; }
/* ── Table Mockup ──────────────────────────────────── */
.tbl-mock {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.tbl-mock th {
    text-align: left;
    padding: 10px 12px;
    font-size: 11px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    border-bottom: 1px solid var(--hairline);
    background: #fafbfc;
}
.tbl-mock td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--hairline-soft);
    color: var(--ink);
    font-size: 13px;
}
.tbl-mock td code, .tbl-mock td .mono {
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
    color: var(--muted);
}
/* ── Filter Bar Mockup ────────────────────────────── */
.filter-bar-mock {
    display: flex; gap: 10px; align-items: center;
    padding: 12px 18px; background: #fafbfc;
    border: 1px solid var(--hairline-soft);
    border-radius: var(--radius);
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.filter-mock {
    background: #fff;
    border: 1px solid var(--hairline);
    border-radius: var(--radius-sm);
    padding: 7px 12px;
    font-size: 13px;
    color: var(--ink);
    min-width: 120px;
}
.filter-mock.dim { color: var(--muted); }
.btn-mock {
    padding: 7px 18px;
    border-radius: 100px;
    background: var(--primary);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    border: 0;
    cursor: default;
}
.btn-mock.outline {
    background: #fff;
    color: var(--primary);
    border: 1px solid var(--primary);
}
.btn-mock.danger {
    background: #fff;
    color: var(--error);
    border: 1px solid var(--error);
}
.btn-mock.ghost {
    background: transparent;
    color: var(--body);
    border: 0;
}
/* ── Map Mockup ────────────────────────────────────── */
.map-mock {
    background: linear-gradient(135deg, #e8edf2 0%, #dce3e9 50%, #e2e7ed 100%);
    border: 1px solid var(--hairline);
    border-radius: var(--radius);
    height: 200px;
    display: flex; align-items: center; justify-content: center;
    position: relative;
    overflow: hidden;
    margin: 8px 0 16px 0;
}
.map-mock-inner {
    text-align: center;
    color: var(--muted);
    font-size: 12px;
    font-weight: 500;
}
.map-mock-inner svg { display: block; margin: 0 auto 8px; opacity: .35; }
.map-mock-dot {
    position: absolute;
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,82,255,.2);
}
.map-mock-dot:nth-child(2) { top: 35%; left: 25%; }
.map-mock-dot:nth-child(3) { top: 50%; left: 55%; }
.map-mock-dot:nth-child(4) { top: 60%; left: 70%; }
.map-mock-dot:nth-child(5) { top: 30%; left: 65%; }
/* ── Chart Mockup ──────────────────────────────────── */
.chart-mock {
    background: #fafbfc;
    border: 1px solid var(--hairline-soft);
    border-radius: var(--radius);
    height: 180px;
    display: flex; align-items: flex-end; gap: 8px;
    padding: 16px 20px 24px;
}
.chart-bar {
    flex: 1;
    border-radius: 4px 4px 0 0;
    min-width: 16px;
}
.chart-bar.blue { background: var(--primary); opacity: .7; }
.chart-bar.blue:nth-child(odd) { opacity: .55; }
.chart-bar.green { background: #098551; opacity: .6; }
.chart-bar.green:nth-child(odd) { opacity: .4; }
/* ── Pill (status) Mockup ─────────────────────────── */
.pill-mock {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 100px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .3px;
}
.pill-mock.green { background: #e6f4ea; color: #0d652d; }
.pill-mock.yellow { background: #fef3e1; color: #b25000; }
.pill-mock.red { background: #fce4eb; color: #c83532; }
.pill-mock.blue { background: var(--primary-soft); color: var(--primary); }
.pill-mock.gray { background: #f0f1f3; color: var(--muted); }
/* ── Icon block ────────────────────────────────────── */
.icon-feature {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 500; color: var(--ink);
    padding: 6px 12px; background: #f8f9fb;
    border-radius: 100px; margin: 0 4px 8px 0;
}
/* ── Note callout ──────────────────────────────────── */
.callout {
    padding: 12px 16px;
    border-radius: var(--radius);
    font-size: 13px;
    line-height: 1.6;
    margin: 16px 0;
    border-left: 3px solid;
}
.callout.info  { background: #e8f0fe; border-color: var(--primary); color: #1a3a6b; }
.callout.warn  { background: #fef3e1; border-color: #f0a020; color: #6b3a00; }
.callout.tip   { background: #e6f4ea; border-color: #098551; color: #0d4d2d; }
/* ── Sidebar Mockup (compact) ──────────────────────── */
.sidebar-mock {
    background: var(--sidebar-bg);
    color: #fff;
    padding: 16px 12px;
    border-radius: var(--radius);
    font-size: 12px;
    min-width: 180px;
    flex-shrink: 0;
}
.sidebar-mock .sm-brand {
    font-weight: 700; font-size: 14px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 8px;
}
.sidebar-mock .sm-item {
    padding: 7px 10px; border-radius: var(--radius-sm); margin-bottom: 2px;
    color: rgba(255,255,255,.6); cursor: default;
}
.sidebar-mock .sm-item.active { background: rgba(0,82,255,.2); color: #fff; }
.sidebar-mock .sm-group {
    font-size: 10px; text-transform: uppercase; letter-spacing: 1px;
    color: rgba(255,255,255,.35); padding: 12px 10px 4px; font-weight: 600;
}
/* ── Video player mockup ───────────────────────────── */
.video-mock {
    background: #000;
    border-radius: var(--radius);
    height: 220px;
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,.4);
    font-size: 13px;
    margin: 8px 0 16px 0;
    position: relative;
}
.video-mock::after {
    content: '\25B6'; font-size: 40px; position: absolute;
}
/* ── Form mockup ───────────────────────────────────── */
.form-mock {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.form-mock-field {
    display: flex; flex-direction: column; gap: 4px;
}
.form-mock-field label {
    font-size: 11px; font-weight: 600; color: var(--muted);
    text-transform: uppercase; letter-spacing: .5px;
}
.form-mock-field .input-mock {
    padding: 8px 12px; border: 1px solid var(--hairline);
    border-radius: var(--radius-sm); font-size: 13px; background: #fff;
    color: var(--ink);
}
.form-mock-field .input-mock.dim { color: var(--muted); }
.form-mock-full { grid-column: 1 / -1; }
/* ── Responsive ────────────────────────────────────── */
@media (max-width: 860px) {
    .wiki-toc { display: none; }
    .wiki-wrap { margin: -16px; }
    .wiki-content { padding: 20px 16px; }
    .kpi-row { grid-template-columns: 1fr 1fr; }
    .form-mock { grid-template-columns: 1fr; }
}
</style>
HEAD;

require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="wiki-wrap">
    <!-- ── TOC Sidebar ──────────────────────────────── -->
    <nav class="wiki-toc" id="wikiToc">
        <h4>Central de Ajuda</h4>
        <a href="#intro">Visão Geral</a>
        <a href="#primeiros-passos">Primeiros Passos</a>
        <a href="#resumo" style="padding-left:20px;font-size:12px">Resumo</a>
        <a href="#rastreamento" style="padding-left:20px;font-size:12px">Rastreamento</a>
        <a href="#bi" style="padding-left:20px;font-size:12px">BI</a>
        <a href="#ocorrencias-dashboard" style="padding-left:20px;font-size:12px">Dashboard Ocorrências</a>
        <a href="#videos">Vídeos</a>
        <a href="#video-aovivo" style="padding-left:20px;font-size:12px">Ao Vivo</a>
        <a href="#video-playback" style="padding-left:20px;font-size:12px">Playback</a>
        <a href="#video-downloads" style="padding-left:20px;font-size:12px">Downloads</a>
        <a href="#relatorios">Relatórios</a>
        <a href="#rel-posicoes" style="padding-left:20px;font-size:12px">Posições</a>
        <a href="#rel-deslocamento" style="padding-left:20px;font-size:12px">Deslocamento</a>
        <a href="#rel-desatualizados" style="padding-left:20px;font-size:12px">Desatualizados</a>
        <a href="#rel-alarmes" style="padding-left:20px;font-size:12px">Alarmes</a>
        <a href="#rel-ocorrencias" style="padding-left:20px;font-size:12px">Ocorrências</a>
        <a href="#cadastros">Cadastros</a>
        <a href="#ativos" style="padding-left:20px;font-size:12px">Ativos</a>
        <a href="#chips" style="padding-left:20px;font-size:12px">Chips</a>
        <a href="#clientes" style="padding-left:20px;font-size:12px">Clientes</a>
        <a href="#equipamentos" style="padding-left:20px;font-size:12px">Equipamentos</a>
        <a href="#grupos-permissao" style="padding-left:20px;font-size:12px">Grupos de Permissão</a>
        <a href="#motoristas" style="padding-left:20px;font-size:12px">Motoristas</a>
        <a href="#config-ocorrencias" style="padding-left:20px;font-size:12px">Config. Ocorrências</a>
        <a href="#usuarios" style="padding-left:20px;font-size:12px">Usuários</a>
        <a href="#operacoes">Operações</a>
        <a href="#comandos" style="padding-left:20px;font-size:12px">Comandos</a>
        <a href="#exportar" style="padding-left:20px;font-size:12px">Exportar</a>
        <a href="#checklist" style="padding-left:20px;font-size:12px">Checklist</a>
        <a href="#webhooks">Webhooks</a>
        <a href="#seguranca">Segurança</a>
    </nav>

    <!-- ── Content ──────────────────────────────────── -->
    <div class="wiki-content" id="wikiContent">

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="intro">Visão Geral do Sistema</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<div class="intro">
<p>O <strong>JIMI Webhook System</strong> é uma plataforma de rastreamento e telemetria de vídeo para frotas de veículos. Ele recebe dados de dispositivos GPS e câmeras com IA (MDVR/ADAS/DMS) instalados nos veículos, armazena tudo em banco de dados e oferece um painel completo para monitoramento ao vivo, geração de relatórios e gestão de ocorrências de comportamento do motorista.</p>

<p><strong>O que o sistema faz:</strong></p>
<ul style="font-size:14px;line-height:1.8;color:var(--body)">
    <li>Recebe GPS, alarmes, batimentos e mídia dos dispositivos Jimi em tempo real</li>
    <li>Exibe no mapa a posição ao vivo de todos os veículos da frota</li>
    <li>Detecta automaticamente ocorrências de comportamento (distração, celular, fadiga, sem cinto...) via câmeras com IA</li>
    <li>Permite tratar cada ocorrência com vídeo, notas e classificação de risco</li>
    <li>Oferece vídeo ao vivo e gravações históricas das câmeras</li>
    <li>Gera relatórios de posição, deslocamento, alarmes e ocorrências</li>
    <li>Permite enviar comandos remotamente para os dispositivos</li>
    <li>É multi-tenant: cada cliente vê apenas sua própria frota</li>
</ul>

<div class="callout info">
<strong>Hierarquia:</strong> Revendedor (dono da plataforma) → Cliente (empresa dona da frota) → Filial → Veículo/Dispositivo.<br>
Usuários podem ser do tipo <strong>revendedor</strong> (vê todos os clientes) ou <strong>cliente</strong> (vê apenas sua frota).
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="primeiros-passos">Primeiros Passos</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<!-- ── Setup ────────────────────────────────────────── -->
<h3>Setup Inicial <span class="badge">admin</span></h3>
<p>Ao acessar o sistema pela primeira vez (sem usuários cadastrados), a tela de <strong>/setup</strong> permite criar o primeiro administrador. Informe nome, e-mail e senha (mínimo 6 caracteres). Após a criação, você será redirecionado ao login.</p>

<div class="callout warn">
<strong>Atenção:</strong> A tela de setup só aparece quando NÃO há usuários no banco. Depois do primeiro cadastro, ela fica inacessível.
</div>

<div class="mockup">
<div class="mockup-header">Tela de Setup — Primeiro Acesso</div>
<div class="mockup-body">
    <div style="max-width:420px;margin:0 auto;text-align:center">
        <div style="font-size:22px;font-weight:700;margin-bottom:4px">JIMI</div>
        <div style="font-size:12px;color:var(--muted);margin-bottom:24px">Configuração Inicial</div>
        <div class="form-mock" style="text-align:left;display:flex;flex-direction:column;gap:12px">
            <div class="form-mock-field"><label>Nome Completo</label><div class="input-mock">Administrador</div></div>
            <div class="form-mock-field"><label>E-mail</label><div class="input-mock">admin@exemplo.com</div></div>
            <div class="form-mock-field"><label>Senha</label><div class="input-mock dim">••••••••</div></div>
        </div>
        <div style="margin-top:20px"><span class="btn-mock">Criar Administrador</span></div>
    </div>
</div>
</div>

<!-- ── Login ────────────────────────────────────────── -->
<h3>Login <span class="badge" style="background:#e6f4ea;color:#098551">público</span></h3>
<p>Tela de autenticação. Usuários informam e-mail e senha para acessar o sistema. O login cria um cookie seguro <code>jimi_token</code> com validade de sessão. Em caso de erro, a mensagem aparece em vermelho acima do formulário.</p>

<div class="mockup">
<div class="mockup-header">Tela de Login</div>
<div class="mockup-body">
    <div style="max-width:400px;margin:0 auto;text-align:center">
        <div style="font-size:24px;font-weight:700;margin-bottom:6px">JIMI</div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:28px">Sistema de Rastreamento</div>
        <div style="display:flex;flex-direction:column;gap:12px;text-align:left">
            <div><label style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase">E-mail</label><div class="input-mock" style="width:100%;margin-top:4px">usuario@exemplo.com</div></div>
            <div><label style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase">Senha</label><div class="input-mock dim" style="width:100%;margin-top:4px">••••••••</div></div>
            <div style="margin-top:8px"><span class="btn-mock" style="display:block;text-align:center">Entrar</span></div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock" style="margin-top:10px">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Login com credenciais corretas</td><td>Redireciona para o Resumo (/)</td></tr>
<tr><td>Login com credenciais erradas</td><td>Mensagem de erro. Após 5 tentativas em 15 min, conta bloqueada temporariamente</td></tr>
<tr><td>Esqueceu a senha</td><td>Contate o administrador do sistema (não há recuperação automática)</td></tr>
<tr><td>Acessar sem login</td><td>Redirecionado para /login</td></tr>
</table>

<!-- ── Trocar Cliente ───────────────────────────────── -->
<h3>Trocar Cliente (Customer Switch)</h3>
<p>No topo da sidebar há um seletor de cliente. Usuários revendedores podem alternar entre os clientes que gerenciam para visualizar os dados de cada um. A troca é instantânea via AJAX e atualiza todo o dashboard.</p>
<div class="callout tip">
<strong>Dica:</strong> O cliente ativo aparece no topo da sidebar. Ao trocar, todas as telas passam a mostrar dados do cliente selecionado.
</div>

<!-- ── Perfil ───────────────────────────────────────── -->
<h3>Meu Perfil <span class="badge">/perfil</span></h3>
<p>Tela acessível pelo avatar no rodapé da sidebar. Exibe os dados do usuário logado (nome, e-mail, função, grupo de permissão) e permite <strong>alterar a própria senha</strong>.</p>
<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Alterar senha (atual + nova + confirmação)</td><td>Senha atualizada. É necessário usar a nova senha no próximo login</td></tr>
<tr><td>Senha atual incorreta</td><td>Mensagem de erro "Senha atual incorreta"</td></tr>
<tr><td>Nova senha com menos de 6 caracteres</td><td>Mensagem de erro</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="resumo">Resumo <span class="badge">/</span></h2>
<!-- ═══════════════════════════════════════════════════════════════ -->
<p><strong>Objetivo:</strong> Visão executiva 360° da frota. É a tela inicial após o login. Mostra KPIs, mapa de calor, velocidade da frota, dispositivos desatualizados e séries temporais de alarmes/ocorrências. Dados vêm de cache pré-computado a cada 5 minutos (com fallback em tempo real), com atualização automática a cada 30 segundos.</p>

<div class="mockup">
<div class="mockup-header">Resumo — Visão 360°</div>
<div class="mockup-body">
    <!-- KPIs -->
    <div class="kpi-row">
        <div class="kpi-box blue"><div class="kpi-label">Total Dispositivos</div><div class="kpi-val">42</div></div>
        <div class="kpi-box green"><div class="kpi-label">Online</div><div class="kpi-val">38</div></div>
        <div class="kpi-box yellow"><div class="kpi-label">Em Tratativa</div><div class="kpi-val">5</div></div>
        <div class="kpi-box red"><div class="kpi-label">Desatualizados</div><div class="kpi-val">12</div></div>
    </div>
    <!-- Heatmap + Velocidade -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
        <div class="map-mock" style="height:180px">
            <div class="map-mock-inner"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>Mapa de Calor (GPS recentes)</div>
            <div class="map-mock-dot"></div><div class="map-mock-dot"></div><div class="map-mock-dot"></div><div class="map-mock-dot"></div>
        </div>
        <div class="kpi-box" style="background:#fafbfc">
            <div class="kpi-label">Velocidade da Frota</div>
            <div class="kpi-val" style="font-size:20px">68 km/h</div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Média de 24 veículos em movimento</div>
        </div>
    </div>
    <!-- Chart -->
    <div style="margin-top:12px">
        <div style="font-size:12px;font-weight:600;color:var(--ink);margin-bottom:8px">Alarmes por Hora (Hoje)</div>
        <div class="chart-mock">
            <div class="chart-bar blue" style="height:40%"></div><div class="chart-bar blue" style="height:25%"></div>
            <div class="chart-bar blue" style="height:60%"></div><div class="chart-bar blue" style="height:35%"></div>
            <div class="chart-bar blue" style="height:80%"></div><div class="chart-bar blue" style="height:55%"></div>
            <div class="chart-bar blue" style="height:70%"></div><div class="chart-bar blue" style="height:45%"></div>
            <div class="chart-bar blue" style="height:90%"></div><div class="chart-bar blue" style="height:65%"></div>
            <div class="chart-bar blue" style="height:50%"></div><div class="chart-bar blue" style="height:30%"></div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Bloco</th><th>O que mostra</th><th>Atualização</th></tr>
<tr><td>KPIs (4 cards)</td><td>Total de dispositivos, Online, Ocorrências em tratativa, Desatualizados</td><td>30s (cache 5min)</td></tr>
<tr><td>Mapa de Calor</td><td>Concentração de GPS dos últimos 30 minutos (Leaflet heatmap)</td><td>30s</td></tr>
<tr><td>Velocidade da Frota</td><td>Velocidade média dos veículos em movimento</td><td>30s</td></tr>
<tr><td>Desatualizados</td><td>Top dispositivos sem comunicação recente</td><td>30s</td></tr>
<tr><td>Séries (Alarmes/Ocorrências)</td><td>Gráfico de barras com volume hora a hora (Chart.js)</td><td>30s</td></tr>
</table>

<div class="callout info">
<strong>Tour de boas-vindas:</strong> Na primeira visita, um tour de 5 passos destaca as principais áreas da tela. Ele não aparece novamente após ser concluído (salvo no navegador via localStorage).
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="rastreamento">Rastreamento <span class="badge">/rastreamento</span></h2>
<!-- ═══════════════════════════════════════════════════════════════ -->
<p><strong>Objetivo:</strong> Mapa ao vivo com a última posição de todos os dispositivos da frota. Atualização automática a cada 60 segundos.</p>

<div class="mockup">
<div class="mockup-header">Rastreamento — Mapa ao Vivo</div>
<div class="mockup-body">
    <div style="display:flex;gap:16px">
        <div style="width:200px;flex-shrink:0">
            <div style="font-size:12px;font-weight:600;margin-bottom:8px">Clientes</div>
            <div style="padding:8px;background:var(--primary-soft);border-radius:var(--radius-sm);font-size:13px;color:var(--primary);font-weight:600;margin-bottom:4px">Frota Principal</div>
            <div style="padding:8px;font-size:13px;color:var(--muted);margin-bottom:4px">Construtora ABC</div>
            <div style="margin-top:12px;font-size:12px;font-weight:600;margin-bottom:8px">Ativos</div>
            <div style="display:flex;align-items:center;gap:8px;padding:6px;font-size:13px"><span style="width:10px;height:10px;border-radius:50%;background:#098551"></span> CAM-001 JC182</div>
            <div style="display:flex;align-items:center;gap:8px;padding:6px;font-size:13px"><span style="width:10px;height:10px;border-radius:50%;background:#c83532"></span> CAM-002 JC371</div>
            <div style="display:flex;align-items:center;gap:8px;padding:6px;font-size:13px"><span style="width:10px;height:10px;border-radius:50%;background:#098551"></span> CAM-003 JC450</div>
            <div style="margin-top:8px"><div class="input-mock dim" style="font-size:12px;width:100%">Buscar ativo...</div></div>
        </div>
        <div class="map-mock" style="flex:1;height:320px">
            <div class="map-mock-inner"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/></svg>Mapa Leaflet — Posições ao Vivo</div>
            <div class="map-mock-dot" style="background:#098551;top:30%;left:30%;width:10px;height:10px"></div>
            <div class="map-mock-dot" style="background:#098551;top:45%;left:55%;width:10px;height:10px"></div>
            <div class="map-mock-dot" style="background:#c83532;top:55%;left:70%;width:10px;height:10px"></div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar cliente</td><td>Mapa e lista de ativos são recarregados com dados do cliente escolhido</td></tr>
<tr><td>Clicar em um ativo na lista</td><td>Centraliza o mapa na última posição daquele dispositivo</td></tr>
<tr><td>Buscar por nome/IMEI</td><td>Filtra a lista de ativos em tempo real</td></tr>
<tr><td>Marcador verde</td><td>Dispositivo online (última comunicação &le; 5 min)</td></tr>
<tr><td>Marcador vermelho</td><td>Dispositivo offline (última comunicação > 5 min)</td></tr>
<tr><td>Auto-refresh (60s)</td><td>Posições são atualizadas automaticamente</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="bi">BI — Business Intelligence <span class="badge">/bi</span></h2>
<!-- ═══════════════════════════════════════════════════════════════ -->
<p><strong>Objetivo:</strong> Gerador de análises sob demanda. Selecione filtros (cliente, ativos, motoristas, tipos de alarme, período) e clique em <strong>Gerar</strong> para visualizar gráficos de barras, pizza e linha.</p>

<div class="mockup">
<div class="mockup-header">BI — Análises sob Demanda</div>
<div class="mockup-body">
    <div class="filter-bar-mock">
        <div class="filter-mock">Cliente: Todos</div>
        <div class="filter-mock">Ativo: Qualquer</div>
        <div class="filter-mock">Motorista: Todos</div>
        <div class="filter-mock dim">dd/mm/aaaa - dd/mm/aaaa</div>
        <span class="btn-mock">Gerar</span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
        <div style="background:#fafbfc;border:1px solid var(--hairline-soft);border-radius:var(--radius);padding:16px;text-align:center">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Alarmes por Tipo (Barras)</div>
            <div class="chart-mock" style="height:140px">
                <div class="chart-bar blue" style="height:30%"></div><div class="chart-bar blue" style="height:55%"></div>
                <div class="chart-bar blue" style="height:80%"></div><div class="chart-bar blue" style="height:45%"></div>
                <div class="chart-bar blue" style="height:60%"></div>
            </div>
        </div>
        <div style="background:#fafbfc;border:1px solid var(--hairline-soft);border-radius:var(--radius);padding:16px;text-align:center">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Distribuição (Pizza)</div>
            <div style="width:100px;height:100px;border-radius:50%;background:conic-gradient(var(--primary) 0% 45%, #098551 45% 70%, #c83532 70% 100%);margin:10px auto"></div>
        </div>
        <div style="background:#fafbfc;border:1px solid var(--hairline-soft);border-radius:var(--radius);padding:16px;text-align:center">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Tendência (Linha)</div>
            <svg width="160" height="100" viewBox="0 0 160 100" style="margin-top:8px">
                <polyline fill="none" stroke="var(--primary)" stroke-width="2" points="5,80 30,60 55,70 80,30 105,40 130,20 155,35"/>
                <circle cx="80" cy="30" r="3" fill="var(--primary)"/>
            </svg>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Preencher filtros + Gerar</td><td>Gráficos são carregados com dados do período/filtros selecionados</td></tr>
<tr><td>Filtro Motoristas</td><td>Filtra dados de ocorrências por motorista específico</td></tr>
<tr><td>Filtro Alarmes (multi-select)</td><td>Seleciona um ou mais tipos de alarme para análise (chips com +N se houver muitos selecionados)</td></tr>
<tr><td>Sem filtros preenchidos</td><td>Usa padrão: últimos 30 dias, todos os alarmes</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="ocorrencias-dashboard">Dashboard de Ocorrências <span class="badge">/ocorrencias/dashboard</span></h2>
<!-- ═══════════════════════════════════════════════════════════════ -->
<p><strong>Objetivo:</strong> Painel operacional de gestão de eventos DMS (Driver Monitoring System). É <strong>o coração do produto</strong>. Câmeras com IA detectam comportamentos de risco (distração, uso de celular, fadiga, sem cinto) e geram ocorrências automaticamente. O operador visualiza a fila em tempo real (polling a cada 15s) e trata cada caso.</p>

<div class="mockup">
<div class="mockup-header">Dashboard de Ocorrências — Fila de Tratativa</div>
<div class="mockup-body">
    <!-- KPIs -->
    <div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">
        <div class="kpi-box red"><div class="kpi-label">Aguardando</div><div class="kpi-val">5</div></div>
        <div class="kpi-box yellow"><div class="kpi-label">Em Tratativa</div><div class="kpi-val">3</div></div>
        <div class="kpi-box green"><div class="kpi-label">Resolvidas (Hoje)</div><div class="kpi-val">12</div></div>
        <div class="kpi-box" style="background:#fafbfc"><div class="kpi-label">Total (Mês)</div><div class="kpi-val">87</div></div>
    </div>
    <!-- Risk Bar -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:10px 16px;background:#fafbfc;border-radius:var(--radius)">
        <div style="font-size:12px;font-weight:600;color:var(--muted)">Distribuição de Risco:</div>
        <div style="flex:1;height:8px;background:#eee;border-radius:4px;display:flex;overflow:hidden">
            <div style="width:45%;background:#098551"></div>
            <div style="width:30%;background:#f0a020"></div>
            <div style="width:25%;background:#c83532"></div>
        </div>
        <div style="font-size:11px;display:flex;gap:12px"><span style="color:#098551">Baixo 45%</span><span style="color:#f0a020">Médio 30%</span><span style="color:#c83532">Alto 25%</span></div>
    </div>
    <!-- Grade -->
    <table class="tbl-mock">
    <tr><th>Data/Hora</th><th>IMEI</th><th>Tipo</th><th>Risco</th><th>Status</th></tr>
    <tr><td>18/07 14:32</td><td class="mono">860112070347838</td><td>Distração</td><td><span class="pill-mock red">Alto</span></td><td><span class="pill-mock red">Aguardando</span></td></tr>
    <tr><td>18/07 14:28</td><td class="mono">869058070151343</td><td>Uso de Celular</td><td><span class="pill-mock yellow">Médio</span></td><td><span class="pill-mock yellow">Em Tratativa</span></td></tr>
    <tr><td>18/07 14:15</td><td class="mono">865478070003241</td><td>Sem Cinto</td><td><span class="pill-mock green">Baixo</span></td><td><span class="pill-mock green">Resolvida</span></td></tr>
    </table>
</div>
</div>

<h4 style="font-size:14px;font-weight:600;margin:20px 0 8px">Tela de Tratativa (Detalhe da Ocorrência)</h4>
<p>Ao clicar em uma ocorrência, abre-se a tela de detalhe com:</p>
<ul style="font-size:13px;line-height:1.8;color:var(--body)">
    <li><strong>Player de vídeo</strong> do momento do evento (com download via FILE_STORAGE_URL)</li>
    <li><strong>Alarmes agrupados</strong> (todos os alarmes que compõem a ocorrência, com dados de GPS e velocidade)</li>
    <li><strong>Mini-mapa</strong> da localização do evento</li>
    <li><strong>Transições de status:</strong> Iniciar Tratativa → Resolver → Descartar</li>
    <li><strong>Campo de notas</strong> para o operador registrar observações</li>
    <li><strong>Flag de Falso Positivo</strong> para marcar alarmes incorretos</li>
</ul>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Clicar em uma ocorrência</td><td>Abre tela de detalhe com vídeo, alarmes agrupados e mapa</td></tr>
<tr><td>Iniciar Tratativa</td><td>Status muda para "Em Tratativa", registra operador e data/hora</td></tr>
<tr><td>Resolver</td><td>Status muda para "Resolvida"</td></tr>
<tr><td>Descartar / Falso Positivo</td><td>Status muda para "Descartada". Se marcado como falso positivo, não conta nas estatísticas</td></tr>
<tr><td>Adicionar nota</td><td>Nota de texto salva junto com a transição de status</td></tr>
<tr><td>Dashboard (polling 15s)</td><td>Grade e KPIs atualizam automaticamente</td></tr>
<tr><td>Filtro de período</td><td>Filtra ocorrências por intervalo de datas</td></tr>
</table>

<div class="callout info">
<strong>Fluxo completo:</strong> Câmera detecta evento → envia alarme via webhook → motor de ocorrências cria/agrupa ocorrência → operador vê na fila → trata (vê vídeo, classifica, resolve). Todo o processo leva segundos do alarme até aparecer no dashboard.
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="videos">Vídeos</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<h3 id="video-aovivo">Ao Vivo <span class="badge">/video/aovivo</span></h3>
<p><strong>Objetivo:</strong> Assistir ao vivo as câmeras dos dispositivos. O sistema envia um comando (proNo 37121) para o dispositivo publicar o stream de vídeo no servidor de mídia. O player FLV (flv.js) então reproduz o stream.</p>

<div class="mockup">
<div class="mockup-header">Vídeo ao Vivo</div>
<div class="mockup-body">
    <div style="margin-bottom:12px;display:flex;gap:10px;align-items:center">
        <div class="filter-mock">Equipamento: CAM-001 (860112070347838)</div>
        <div class="filter-mock">Canal: CH1</div>
        <span class="btn-mock">Iniciar Transmissão</span>
        <span style="font-size:11px;color:var(--muted)">Rotação: 0° | Marca d'água: Desligado</span>
    </div>
    <div class="video-mock">
        <div style="position:relative;z-index:1;display:flex;align-items:center;gap:8px;flex-direction:column">
            <span>Streaming FLV — Canal 1</span>
            <span style="font-size:11px;opacity:.6">Tentativa 3/8 — aguardando dispositivo publicar...</span>
        </div>
    </div>
    <div style="margin-top:8px;display:flex;gap:16px">
        <span class="filter-mock" style="font-size:12px">CH1</span>
        <span class="filter-mock" style="font-size:12px;opacity:.5">CH2</span>
        <span class="filter-mock" style="font-size:12px;opacity:.5">CH3</span>
        <span class="filter-mock" style="font-size:12px;opacity:.5">CH4</span>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar equipamento + canal + Iniciar</td><td>Comando 37121 enviado. Player tenta conectar (8 tentativas de 3s). Vídeo abre quando o dispositivo publica o stream (5-30s)</td></tr>
<tr><td>Trocar de canal</td><td>Para o stream atual e inicia novo comando para o canal selecionado</td></tr>
<tr><td>Dispositivo offline</td><td>Comando entra na fila offline. Barra de status avisa "Comando na fila — será executado quando o dispositivo conectar"</td></tr>
<tr><td>Rotação de tela</td><td>Player aplica rotação CSS configurada no cadastro do equipamento</td></tr>
<tr><td>Marca d'água</td><td>Se habilitado no cadastro, sobrepõe texto de marca d'água ao vídeo</td></tr>
</table>

<div class="callout warn">
<strong>Aguarde o stream:</strong> Entre clicar "Iniciar" e o vídeo aparecer, o dispositivo precisa ligar a câmera e negociar o protocolo RTP com o servidor. Isso leva de 5 a 30 segundos. O player mostra o progresso das tentativas.
</div>

<h3 id="video-playback">Playback <span class="badge">/video/playback</span></h3>
<p><strong>Objetivo:</strong> Visualizar gravações históricas do cartão SD do dispositivo. Ao clicar em <strong>Requisitar Gravações</strong>, o sistema envia o comando 37381 (0x9205) consultando a lista de arquivos no cartão. A timeline mostra gravações "No cartão" (com opção Extrair) e "Disponível" (já baixadas, prontas para reproduzir).</p>

<div class="mockup">
<div class="mockup-header">Playback — Timeline de Gravações</div>
<div class="mockup-body">
    <div class="filter-bar-mock" style="margin-bottom:16px">
        <div class="filter-mock">Equipamento: JC182 (869058070151343)</div>
        <div class="filter-mock">Canal: CH1</div>
        <div class="filter-mock dim">Período: dd/mm/aaaa - dd/mm/aaaa</div>
        <span class="btn-mock">Requisitar Gravações</span>
    </div>
    <div style="border:1px solid var(--hairline-soft);border-radius:var(--radius);padding:16px">
        <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:10px">157 gravações encontradas</div>
        <div style="display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:auto">
            <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;background:#f8f9fb;border-radius:var(--radius-sm);font-size:13px">
                <span class="pill-mock green">Disponível</span>
                <span>18/07 14:00-14:05 (5 min)</span>
                <span class="btn-mock ghost" style="margin-left:auto;font-size:12px;padding:4px 12px">▶ Reproduzir</span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;font-size:13px">
                <span class="pill-mock gray">No cartão</span>
                <span>18/07 13:30-13:45 (15 min)</span>
                <span class="btn-mock outline" style="margin-left:auto;font-size:12px;padding:4px 12px">Extrair</span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;background:#f8f9fb;border-radius:var(--radius-sm);font-size:13px">
                <span class="pill-mock green">Disponível</span>
                <span>18/07 12:00-12:20 (20 min)</span>
                <span class="btn-mock ghost" style="margin-left:auto;font-size:12px;padding:4px 12px">▶ Reproduzir</span>
            </div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Requisitar Gravações</td><td>Envia 37381. A resposta chega via push e preenche a timeline</td></tr>
<tr><td>Extrair (gravação "No cartão")</td><td>Envia 34818 solicitando upload da janela exata. Arquivo baixado → status muda para "Disponível"</td></tr>
<tr><td>Reproduzir (gravação "Disponível")</td><td>Abre player de vídeo inline com o arquivo do storage</td></tr>
<tr><td>Auto-refresh pós-requisição</td><td>6 verificações a cada 8s após Requisitar (cancela ao interagir)</td></tr>
</table>

<h3 id="video-downloads">Downloads <span class="badge">/video/downloads</span></h3>
<p><strong>Objetivo:</strong> Grade com todos os arquivos de mídia disponíveis para download. Filtros por equipamento e status (disponível, solicitado, erro). Clique no nome do arquivo para baixar diretamente do file storage.</p>

<div class="mockup">
<div class="mockup-header">Downloads — Arquivos de Mídia</div>
<div class="mockup-body">
    <div class="filter-bar-mock" style="margin-bottom:0">
        <div class="filter-mock">Equipamento: Todos</div>
        <div class="filter-mock">Status: Disponível</div>
    </div>
    <table class="tbl-mock" style="margin-top:12px">
    <tr><th>Arquivo</th><th>IMEI</th><th>Tipo</th><th>Tamanho</th><th>Data</th><th>Status</th></tr>
    <tr><td style="color:var(--primary);cursor:default">86011207_20260718_01.mp4</td><td class="mono">860112070347838</td><td>vídeo</td><td class="mono">21.4 MB</td><td>18/07 14:32</td><td><span class="pill-mock green">Disponível</span></td></tr>
    <tr><td style="color:var(--primary);cursor:default">86905807_20260718_02.jpg</td><td class="mono">869058070151343</td><td>imagem</td><td class="mono">245 KB</td><td>18/07 14:28</td><td><span class="pill-mock green">Disponível</span></td></tr>
    <tr><td>86547807_20260718_03.mp4</td><td class="mono">865478070003241</td><td>vídeo</td><td class="mono">—</td><td>18/07 13:55</td><td><span class="pill-mock yellow">Solicitado</span></td></tr>
    </table>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="relatorios">Relatórios</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<h3 id="rel-posicoes">Posições <span class="badge">/relatorios/posicoes</span></h3>
<p><strong>Objetivo:</strong> Histórico de posições GPS de um ativo em um período. Mostra mapa Leaflet com trajetória + tabela paginada com data/hora, latitude, longitude, velocidade e ignição. Suporte a exportação CSV/XLSX/PDF.</p>

<div class="mockup">
<div class="mockup-header">Relatório de Posições</div>
<div class="mockup-body">
    <div class="filter-bar-mock">
        <div class="filter-mock">Ativo: CAM-001</div>
        <div class="filter-mock dim">dd/mm/aaaa - dd/mm/aaaa</div>
        <span class="btn-mock">Filtrar</span>
        <span class="btn-mock outline">Exportar</span>
    </div>
    <div class="map-mock" style="height:180px">
        <div class="map-mock-inner"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="3 12 8 7 13 12 18 7 21 12"/></svg>Trajetória no Mapa</div>
        <div class="map-mock-dot" style="top:50%;left:20%"></div>
        <div class="map-mock-dot" style="top:45%;left:40%"></div>
        <div class="map-mock-dot" style="top:35%;left:60%"></div>
        <div class="map-mock-dot" style="top:25%;left:80%"></div>
    </div>
    <table class="tbl-mock">
    <tr><th>Data/Hora</th><th>Latitude</th><th>Longitude</th><th>Velocidade</th><th>Ignição</th></tr>
    <tr><td>18/07 14:35:22</td><td class="mono">-23.5505</td><td class="mono">-46.6333</td><td class="mono">42 km/h</td><td>Ligada</td></tr>
    <tr><td>18/07 14:30:10</td><td class="mono">-23.5489</td><td class="mono">-46.6311</td><td class="mono">38 km/h</td><td>Ligada</td></tr>
    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">Página 1 de 12 — 1-25 de 287 registros</td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar ativo + período + Filtrar</td><td>Mapa e tabela carregam com os dados do período</td></tr>
<tr><td>Exportar (CSV/XLSX/PDF)</td><td>Download do arquivo com todos os registros (limitado a 10.000 linhas na exportação síncrona)</td></tr>
<tr><td>Navegar páginas</td><td>Paginação de 25 em 25 registros</td></tr>
</table>

<h3 id="rel-deslocamento">Deslocamento <span class="badge">/relatorios/deslocamento</span></h3>
<p><strong>Objetivo:</strong> Histórico de viagens (trips) detectadas por ignição do veículo (liga → desliga). Mostra duração, velocidade máxima, distância percorrida (cálculo Haversine) e alarmes ocorridos durante a viagem. Os dados vêm da tabela <code>trips</code>, preenchida pelo worker <code>trip_builder.php</code>.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar por ativo + período</td><td>Tabela mostra todas as viagens do período: início, fim, duração, vel. máx, distância, alarmes</td></tr>
<tr><td>Ordenar por coluna</td><td>Clique no cabeçalho para ordenar (data, duração, distância)</td></tr>
<tr><td>Exportar</td><td>CSV/XLSX/PDF com os dados da consulta</td></tr>
</table>

<h3 id="rel-desatualizados">Desatualizados <span class="badge">/relatorios/desatualizados</span></h3>
<p><strong>Objetivo:</strong> Identificar dispositivos que não se comunicam há muito tempo. 5 buckets de KPI (1h, 6h, 12h, 24h, >24h) clicáveis para drill-down na listagem de dispositivos daquela faixa.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Clicar em um bucket</td><td>Lista filtra para mostrar apenas dispositivos naquela faixa de inatividade</td></tr>
<tr><td>Visualizar dispositivo</td><td>Link para o detalhe do ativo (/ativos/{imei})</td></tr>
</table>

<h3 id="rel-alarmes">Alarmes <span class="badge">/relatorios/alarmes</span></h3>
<p><strong>Objetivo:</strong> Histórico completo de alarmes recebidos. 5 filtros: dispositivo, tipo de alarme, período, cliente e busca textual. Ordenação clicável por qualquer coluna. Link para mapa OSM (OpenStreetMap) na coordenada do alarme.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar + ordenar</td><td>Tabela atualiza com filtros aplicados e ordenação escolhida</td></tr>
<tr><td>Clicar no link do mapa</td><td>Abre OSM em nova aba na coordenada do alarme</td></tr>
<tr><td>Exportar</td><td>CSV/XLSX/PDF com os dados filtrados</td></tr>
</table>

<h3 id="rel-ocorrencias">Ocorrências <span class="badge">/relatorios/ocorrencias</span></h3>
<p><strong>Objetivo:</strong> Histórico de ocorrências DMS com 6 filtros: cliente, IMEI, tipo de alarme, status, nível de risco e flag de falso positivo. Visão complementar ao Dashboard de Ocorrências, focada em auditoria e análise histórica.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Aplicar filtros</td><td>Tabela mostra ocorrências que atendem a todos os critérios</td></tr>
<tr><td>Clicar em uma ocorrência</td><td>Redireciona para o Dashboard de Ocorrências com o detalhe aberto (?id=N)</td></tr>
<tr><td>Exportar</td><td>CSV/XLSX/PDF</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="cadastros">Cadastros</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<h3 id="ativos">Ativos <span class="badge">/ativos</span></h3>
<p><strong>Objetivo:</strong> Gerenciar dispositivos (veículos/câmeras) da frota. Lista paginada com busca, edição inline (nome, modelo, número de câmeras) e remoção (soft-delete).</p>

<div class="mockup">
<div class="mockup-header">Ativos — Lista de Dispositivos</div>
<div class="mockup-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div class="input-mock" style="width:260px;font-size:13px">Buscar por nome ou IMEI...</div>
        <span class="btn-mock">+ Novo Ativo</span>
    </div>
    <table class="tbl-mock">
    <tr><th>Nome</th><th>IMEI</th><th>Modelo</th><th>Câmeras</th><th>Última Com.</th><th>Status</th><th></th></tr>
    <tr>
        <td>CAM-001 Frente</td><td class="mono">860112070347838</td><td>JC181</td><td>2</td><td>18/07 14:35</td><td><span class="pill-mock green">Online</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span> <span class="btn-mock danger" style="font-size:12px;padding:2px 8px">Remover</span></td>
    </tr>
    <tr>
        <td>CAM-002 Lateral</td><td class="mono">869058070151343</td><td>JC182</td><td>1</td><td>18/07 14:20</td><td><span class="pill-mock green">Online</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span> <span class="btn-mock danger" style="font-size:12px;padding:2px 8px">Remover</span></td>
    </tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>+ Novo Ativo</td><td>Abre /ativos/novo — formulário de cadastro com campos: IMEI, nome, modelo (dropdown de device_models), número de câmeras instaladas</td></tr>
<tr><td>Editar inline</td><td>Campos de nome, modelo e câmeras tornam-se editáveis na linha</td></tr>
<tr><td>Remover</td><td>Soft-delete (is_active=0). Dispositivo não aparece mais nas listas mas dados históricos são preservados</td></tr>
<tr><td>Clicar no IMEI</td><td>Abre /ativos/{imei} — tela de detalhe com 9 abas</td></tr>
<tr><td>Buscar</td><td>Filtra por nome ou IMEI (busca parcial)</td></tr>
<tr><td>Exportar</td><td>CSV/XLSX/PDF com todos os dispositivos ativos</td></tr>
</table>

<div class="callout tip">
<strong>Adoção de órfãos:</strong> Quando um dispositivo envia telemetria pela primeira vez, o gateway cria uma linha órfã (customer_id NULL). Ao cadastrar esse IMEI em /ativos/novo, o sistema adota a linha existente, preservando todo o histórico de telemetria.
</div>

<h3 id="chips">Chips SIM <span class="badge">/chips</span></h3>
<p><strong>Objetivo:</strong> Gerenciar chips SIM usados nos dispositivos. CRUD com campos: operadora, número (MSISDN), ICCID, IMEI vinculado, status ativo/inativo.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar chip</td><td>Preencher operadora, número e/ou ICCID. Opcionalmente vincular a um IMEI</td></tr>
<tr><td>Editar</td><td>Alterar qualquer campo do chip</td></tr>
<tr><td>Remover</td><td>Exclui o registro (DELETE físico)</td></tr>
<tr><td>Buscar</td><td>Filtra por qualquer campo textual</td></tr>
</table>

<h3 id="clientes">Clientes <span class="badge">/clientes</span> <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Gestão multi-tenant. Administradores (revendedores) gerenciam os clientes da plataforma. Cada cliente tem sua própria frota isolada.</p>

<div class="mockup">
<div class="mockup-header">Clientes — Gestão Multi-tenant</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Nome</th><th>Documento</th><th>Dispositivos</th><th>Config. Ocorrências</th><th>FaceID</th><th>Ações</th></tr>
    <tr>
        <td>Frota Principal</td><td>12.345.678/0001-90</td><td>42</td><td>Padrão Sistema</td><td><span class="pill-mock gray">Desligado</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span> <span class="btn-mock outline" style="font-size:12px;padding:2px 8px">Entrar como</span></td>
    </tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar/Editar cliente</td><td>Formulário com: nome, documento, e-mail, telefone, endereço, perfil de ocorrências, FaceID, cor da marca (white-label), URL do logo</td></tr>
<tr><td>Desativar</td><td>Soft-delete (is_active=0). Cliente ID 1 não pode ser desativado</td></tr>
<tr><td>Entrar como (impersonar)</td><td>Revendedor assume o contexto do cliente. Registrado em <code>impersonation_log</code> para auditoria</td></tr>
<tr><td>White-label</td><td>Cor da marca (<code>brand_color</code>) aplicada na sidebar CSS do cliente</td></tr>
<tr><td>FaceID</td><td>Flag que habilita identificação facial de motoristas para o cliente</td></tr>
</table>

<h3 id="equipamentos">Equipamentos <span class="badge">/equipamentos</span></h3>
<p><strong>Objetivo:</strong> Cadastro completo de equipamentos com periféricos (câmeras, sensores), configurações de streaming (rotação, marca d'água), firmware, filial e chip SIM vinculado. Suporte a importação em lote via CSV.</p>

<div class="mockup">
<div class="mockup-header">Equipamentos — Cadastro Completo</div>
<div class="mockup-body">
    <div style="display:flex;gap:8px;margin-bottom:16px">
        <span class="btn-mock">+ Cadastrar</span>
        <span class="btn-mock outline">Atualizar Firmware</span>
        <span class="btn-mock outline">Importar CSV</span>
    </div>
    <div class="form-mock">
        <div class="form-mock-field"><label>Modelo *</label><div class="input-mock">JC450</div></div>
        <div class="form-mock-field"><label>IMEI *</label><div class="input-mock dim" style="font-family:'JetBrains Mono',monospace">860112070347838</div></div>
        <div class="form-mock-field"><label>Chip SIM</label><div class="input-mock dim">Selecione...</div></div>
        <div class="form-mock-field"><label>Filial</label><div class="input-mock dim">Matriz</div></div>
        <div class="form-mock-field form-mock-full"><label>Periféricos</label>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
                <span class="icon-feature" style="background:var(--primary-soft);color:var(--primary)">Câmera Frontal</span>
                <span class="icon-feature" style="background:var(--primary-soft);color:var(--primary)">Câmera Lateral</span>
                <span class="icon-feature" style="background:#f0f1f3;color:var(--muted)">+ Adicionar</span>
            </div>
        </div>
        <div class="form-mock-field"><label>Rotação do Stream</label><div class="input-mock">0°</div></div>
        <div class="form-mock-field"><label>Marca d'Água</label><div class="input-mock dim">Texto opcional...</div></div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Cadastrar equipamento</td><td>Cria novo device com modelo, IMEI, chip, periféricos, rotação, marca d'água e firmware</td></tr>
<tr><td>Importar CSV</td><td>Upload de arquivo CSV com colunas: imei, name, model, camera_count. Parseado no frontend e enviado como JSON batch</td></tr>
<tr><td>Atualizar Firmware (FOTA)</td><td>Abre modal para enviar comando proNo 33027 (OTA firmware update)</td></tr>
<tr><td>Selecionar periféricos</td><td>Tags clicáveis (estilo chip) para adicionar/remover periféricos do dispositivo</td></tr>
</table>

<h3 id="grupos-permissao">Grupos de Permissão <span class="badge">/grupos-permissao</span> <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Matriz de permissões RBAC (Role-Based Access Control). Cada grupo define quais telas (18) e ações (Ver, Criar, Editar, Excluir, Exportar) um usuário pode acessar. Mostra contagem de usuários vinculados a cada grupo.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar grupo</td><td>Nome + matriz de checkboxes (18 telas x 5 ações) salva como JSON em <code>permissions</code></td></tr>
<tr><td>Editar grupo</td><td>Alterar nome e checkboxes da matriz</td></tr>
<tr><td>Excluir grupo</td><td>Só permitido se não houver usuários vinculados ao grupo</td></tr>
<tr><td>Ver contagem de usuários</td><td>Cada grupo mostra quantos usuários estão vinculados a ele</td></tr>
</table>

<h3 id="motoristas">Motoristas <span class="badge">/motoristas</span></h3>
<p><strong>Objetivo:</strong> Cadastro de motoristas com dados de compliance: CNH (número, categoria, vencimento) e exame toxicológico (vencimento). Alertas visuais para documentos vencidos ou próximos do vencimento.</p>

<div class="mockup">
<div class="mockup-header">Motoristas — Cadastro com Compliance</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Nome</th><th>CNH</th><th>Categoria</th><th>Validade CNH</th><th>Toxicológico</th><th>Status</th></tr>
    <tr>
        <td>João Silva</td><td class="mono">12345678900</td><td>D</td><td>15/03/2027</td><td>10/01/2027</td><td><span class="pill-mock green">Ativo</span></td>
    </tr>
    <tr>
        <td>Maria Santos</td><td class="mono">98765432100</td><td>C</td><td style="color:#c83532;font-weight:600">20/06/2026</td><td>05/12/2026</td><td><span class="pill-mock green">Ativo</span></td>
    </tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar/Editar motorista</td><td>Nome, nascimento, CNH (número, categoria, vencimento), toxicológico (vencimento), identificador FaceID, status ativo/inativo</td></tr>
<tr><td>Remover</td><td>DELETE físico do registro</td></tr>
<tr><td>Alertas de vencimento</td><td>CNH ou toxicológico vencidos aparecem em vermelho na tabela</td></tr>
</table>

<h3 id="config-ocorrencias">Configuração de Ocorrências <span class="badge">/config-ocorrencias</span> <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Criar e gerenciar perfis de regras que controlam <strong>como o motor de ocorrências processa cada tipo de alarme</strong>. Cada perfil define, para cada tipo de alarme, se gera ocorrência, qual o nível de risco, a janela de deduplicação e outras configurações.</p>

<div class="callout info">
<strong>Regra de negócio:</strong> Quando um alarme chega do dispositivo, o sistema consulta o perfil de ocorrências do cliente para decidir: este alarme vira uma ocorrência? Com qual risco? Se já existe uma ocorrência similar nos últimos X minutos, agrupa ou cria nova?
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar perfil</td><td>Nome + flag "Padrão do Sistema" (um perfil padrão por vez)</td></tr>
<tr><td>Editar perfil</td><td>Alterar nome + rows dinâmicas de parâmetros por tipo de alarme</td></tr>
<tr><td>Configurar parâmetro</td><td>Para cada alarm_type: gera ocorrência? (Sim/Não), nível de risco (Baixo/Médio/Alto), janela de dedup (minutos)</td></tr>
<tr><td>Excluir perfil</td><td>Só permitido se nenhum cliente estiver usando o perfil</td></tr>
<tr><td>Vincular ao cliente</td><td>No cadastro do cliente (/clientes), selecionar o perfil de ocorrências</td></tr>
</table>

<h3 id="usuarios">Usuários <span class="badge">/usuarios</span> <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Gestão de usuários do sistema com duas abas: <strong>Minha Empresa</strong> (usuários internos) e <strong>Meus Clientes</strong> (usuários dos clientes). Campos: nome, e-mail, senha, função (admin/operador/visualizador), tipo (revendedor/cliente), cliente vinculado, grupo de permissão, foto.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar usuário</td><td>Nome, e-mail, senha (mín. 6 caracteres), função, tipo de usuário, cliente, grupo de permissão</td></tr>
<tr><td>Editar usuário</td><td>Alterar dados. Senha só é alterada se preenchida</td></tr>
<tr><td>Ativar/Desativar</td><td>Toggle is_active. Não pode desativar o próprio usuário</td></tr>
<tr><td>Vincular grupo de permissão</td><td>Usuário herda as permissões do grupo selecionado</td></tr>
<tr><td>Foto do usuário</td><td>URL externa para foto de perfil (ex: avatar do Google)</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="operacoes">Operações</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<h3 id="comandos">Comandos <span class="badge">/comandos</span></h3>
<p><strong>Objetivo:</strong> Enviar comandos remotos para dispositivos. A tela oferece <strong>presets</strong> para comandos comuns (Status, Reiniciar, Streaming, Playback, Upload) e suporte a comandos JIMI (proNos 128-34818) e JT/T (0x8802, 0x9101, 0x9205, 0x9208).</p>

<div class="mockup">
<div class="mockup-header">Comandos — Envio e Monitoramento</div>
<div class="mockup-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div>
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Enviar Comando</div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <div class="filter-mock">Equipamento: CAM-001 (860112070347838)</div>
                <div class="filter-mock">Preset: Status do Dispositivo</div>
                <span class="btn-mock" style="align-self:flex-start">Enviar</span>
            </div>
        </div>
        <div>
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Histórico Recente</div>
            <table class="tbl-mock" style="font-size:12px">
            <tr><th>Comando</th><th>IMEI</th><th>Status</th><th>Resposta</th></tr>
            <tr><td>Status</td><td class="mono">860112...</td><td><span class="pill-mock green">Executado</span></td><td style="font-size:11px">Battery:12.4V; Mode:SLEEP</td></tr>
            <tr><td>Streaming</td><td class="mono">869058...</td><td><span class="pill-mock blue">Enviado</span></td><td style="font-size:11px;color:var(--muted)">Aguardando dispositivo...</td></tr>
            </table>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar equipamento + preset + Enviar</td><td>Comando enviado via IoTHub (:10088). Timeout de 35s aguardando resposta</td></tr>
<tr><td>Resposta síncrona (device online)</td><td>Status muda para "Executado" com a resposta do dispositivo em ~1-3s</td></tr>
<tr><td>Resposta offline</td><td>Status "Enviado". Callback chega via /pushinstructresponse quando o dispositivo conectar</td></tr>
<tr><td>Polling de status</td><td>Fase rápida: 3s por 30s. Fase lenta: 10s por 5 min. Timeout: "Comando em fila offline"</td></tr>
<tr><td>Timeout (dispositivo não responde)</td><td>Após 35s sem resposta e sem callback: "Sem resposta do dispositivo"</td></tr>
</table>

<div class="callout tip">
<strong>Presets disponíveis:</strong> Status (128), Informações do Dispositivo, Reiniciar, Streaming Ao Vivo (37121), Reprodução de Vídeo, Upload de Vídeo, Alarm Attachment Upload (37384), Configuração (33027-33031).
</div>

<h3 id="exportar">Exportar <span class="badge">/exportar</span></h3>
<p><strong>Objetivo:</strong> Fila de geração assíncrona de relatórios. Para relatórios pesados, o sistema cria um job em background processado pelo worker (cron a cada 1 min). Formatos: CSV, XLSX (Excel) e PDF.</p>

<div class="mockup">
<div class="mockup-header">Exportar — Fila de Jobs</div>
<div class="mockup-body">
    <div style="margin-bottom:16px">
        <span class="btn-mock">+ Novo Relatório</span>
    </div>
    <table class="tbl-mock">
    <tr><th>Relatório</th><th>Tipo</th><th>Formato</th><th>Status</th><th>Criado em</th><th></th></tr>
    <tr>
        <td>Alarmes Julho 2026</td><td>Alarmes</td><td>XLSX</td><td><span class="pill-mock green">Concluído</span></td><td>18/07 14:00</td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Baixar</span></td>
    </tr>
    <tr>
        <td>Posições Semanal</td><td>Posições</td><td>PDF</td><td><span class="pill-mock yellow">Processando</span></td><td>18/07 13:50</td>
        <td><span style="font-size:11px;color:var(--muted)">Aguarde...</span></td>
    </tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Novo Relatório</td><td>Formulário: nome, tipo (alarms/occurrences/positions/trips/devices), período, formato (CSV/XLSX/PDF)</td></tr>
<tr><td>Job criado</td><td>Entra na fila com status "pendente". Worker processa em até 1 minuto</td></tr>
<tr><td>Baixar (status "concluído")</td><td>Download do arquivo gerado</td></tr>
<tr><td>Auto-refresh</td><td>Polling a cada 30s atualiza o status dos jobs</td></tr>
<tr><td>Tipos disponíveis</td><td>Alarmes, Ocorrências, Posições, Viagens, Dispositivos</td></tr>
</table>

<h3 id="checklist">Checklist e Inspeção <span class="badge">/checklist</span></h3>
<p><strong>Objetivo:</strong> Criar checklists de inspeção veicular (ex: checklist diário de pneus, freios, iluminação) e preenchê-los para dispositivos específicos. Cada checklist tem itens configuráveis com tipos: booleano (OK/Não OK), texto, número e foto.</p>

<div class="mockup">
<div class="mockup-header">Checklist — Preenchimento de Inspeção</div>
<div class="mockup-body">
    <div style="display:flex;gap:20px">
        <div style="flex:1">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Inspeção Diária — CAM-001</div>
            <div style="display:flex;flex-direction:column;gap:10px">
                <div style="padding:10px;background:#fafbfc;border-radius:var(--radius-sm)">
                    <div style="font-size:13px;font-weight:500">Pneus em bom estado?</div>
                    <div style="display:flex;gap:8px;margin-top:6px">
                        <span class="pill-mock green">OK</span>
                        <span class="pill-mock gray" style="opacity:.5">Não OK</span>
                    </div>
                </div>
                <div style="padding:10px;background:#fafbfc;border-radius:var(--radius-sm)">
                    <div style="font-size:13px;font-weight:500">Faróis funcionando?</div>
                    <div style="display:flex;gap:8px;margin-top:6px">
                        <span class="pill-mock green">OK</span>
                        <span class="pill-mock gray" style="opacity:.5">Não OK</span>
                    </div>
                </div>
                <div style="padding:10px;background:#fafbfc;border-radius:var(--radius-sm)">
                    <div style="font-size:13px;font-weight:500">Observações</div>
                    <div class="input-mock" style="margin-top:4px;font-size:12px;width:100%">Nenhuma observação</div>
                </div>
                <span class="btn-mock">Registrar Inspeção</span>
            </div>
        </div>
        <div style="width:220px;flex-shrink:0">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Histórico</div>
            <div style="font-size:12px;color:var(--body);padding:6px;border-bottom:1px solid var(--hairline-soft)">18/07 08:15 — Aprovado</div>
            <div style="font-size:12px;color:var(--body);padding:6px;border-bottom:1px solid var(--hairline-soft)">17/07 08:00 — Aprovado</div>
            <div style="font-size:12px;color:var(--body);padding:6px">16/07 07:50 — Aprovado</div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar checklist</td><td>Nome + vinculação ao cliente. Adicionar itens: pergunta, tipo (bool/texto/número/foto), obrigatório</td></tr>
<tr><td>Preencher inspeção</td><td>Selecionar checklist, dispositivo e motorista. Responder cada item. Salvar</td></tr>
<tr><td>Ver histórico</td><td>Lista de inspeções anteriores para o dispositivo</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="webhooks">Webhooks e Integração</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->
<p><strong>Objetivo:</strong> Referência técnica sobre como o sistema recebe dados dos dispositivos Jimi. Esta seção é voltada para administradores e equipe técnica.</p>

<h3>Como os dados chegam</h3>
<div class="callout info">
<strong>Fluxo:</strong> Dispositivo → Jimi IoT Hub (jimicloud.com) → POST HTTP → Apache (este sistema) → .htaccess → router.php → handler push*.php → MySQL.<br>
Todos os webhooks usam token de autenticação (<code>WEBHOOK_TOKEN</code> no .env) e respondem HTTP 200 antes de processar (fastcgi_finish_request), garantindo que o Jimi Hub não espere.
</div>

<h3>Tipos de Webhook</h3>
<table class="tbl-mock">
<tr><th>Endpoint</th><th>Handler</th><th>O que recebe</th></tr>
<tr><td><code>/pushgps</code></td><td>pushgps.php</td><td>Coordenadas GPS (latitude, longitude, velocidade, ignição)</td></tr>
<tr><td><code>/pushhb</code></td><td>pushhb.php</td><td>Heartbeats (batimentos de conexão) a cada ~60s</td></tr>
<tr><td><code>/pushalarm</code></td><td>pushalarm.php</td><td>Alarmes de eventos (DMS, ADAS, ignição, velocidade, cerca...). <strong>Dispara o motor de ocorrências</strong></td></tr>
<tr><td><code>/pushfileupload</code></td><td>pushfileupload.php</td><td>Notificação de arquivo de mídia disponível (vídeo/imagem). Vincula à ocorrência ativa</td></tr>
<tr><td><code>/pushresourcelist</code></td><td>pushresourcelist.php</td><td>Lista de gravações disponíveis no cartão SD (resposta ao comando 37381)</td></tr>
<tr><td><code>/pushinstructresponse</code></td><td>pushinstructresponse.php</td><td>Resposta de comandos enviados (callback offline do IoTHub)</td></tr>
<tr><td><code>/pushTerminalTransInfo</code></td><td>pushTerminalTransInfo.php</td><td>Dados de transmissão do terminal (JT/T 1078)</td></tr>
<tr><td><code>/pushlbs</code></td><td>pushlbs.php</td><td>Posição por triangulação de torres (LBS)</td></tr>
<tr><td><code>/pushevent</code></td><td>pushevent.php</td><td>Eventos genéricos do dispositivo</td></tr>
<tr><td><code>/pushftpfileupload</code></td><td>pushftpfileupload.php</td><td>Notificação de upload via FTP</td></tr>
<tr><td><code>/pushiothubevent</code></td><td>pushiothubevent.php</td><td>Eventos internos do IoTHub</td></tr>
</table>

<h3>Motor de Ocorrências (DMS)</h3>
<div class="callout tip">
<strong>Fluxo completo:</strong><br>
1. Device gera alarme → <code>/pushalarm</code> → INSERT alarms<br>
2. Motor (<code>occurrence_engine.php</code>) resolve o perfil do cliente → busca regra do tipo de alarme → se gera ocorrência, verifica janela de dedup (padrão 10 min) → agrupa em ocorrência existente ou cria nova<br>
3. <code>/pushfileupload</code> vincula mídia à ocorrência por alarm_label ou janela ±3 min<br>
4. Dashboard mostra ocorrência em tempo real (polling 15s)<br>
5. Para alarmes de vídeo (JT/T DMS/ADAS), o sistema automaticamente agenda o comando 37384 (Alarm Attachment Upload) para baixar o vídeo do evento
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="seguranca">Segurança</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<table class="tbl-mock">
<tr><th>Mecanismo</th><th>Descrição</th></tr>
<tr><td>Token de sessão</td><td>Cookie <code>jimi_token</code> (64 caracteres hex) armazenado na tabela <code>sessions</code>. HTTP Only, Secure, SameSite=Lax</td></tr>
<tr><td>CSRF</td><td>Token por sessão derivado por HMAC-SHA256. Verificado em todos os formulários POST (8 handlers protegidos)</td></tr>
<tr><td>Rate limiting</td><td>Login: máximo 5 tentativas em 15 minutos. Excedido → conta bloqueada temporariamente</td></tr>
<tr><td>RBAC</td><td>Matriz de permissões (18 telas x 5 ações). Verificação dupla: view no router + ação fina no handler</td></tr>
<tr><td>Multi-tenant</td><td>Cada query filtra por <code>customer_id</code> da sessão. Usuário cliente não vê dados de outros clientes</td></tr>
<tr><td>Prepared statements</td><td>Todas as queries usam PDO prepared statements — sem concatenação de strings SQL</td></tr>
<tr><td>Sanitização de entrada</td><td>Router sanitiza rotas. Login sanitiza redirect (anti open-redirect). GPS (0,0) é filtrado</td></tr>
<tr><td>Limpeza automática</td><td><code>auth_cleanup()</code> remove sessões expiradas e logs antigos (~1% das requests)</td></tr>
<tr><td>Impersonação auditada</td><td>Toda impersonação (revendedor → cliente) é registrada em <code>impersonation_log</code></td></tr>
<tr><td>Senhas</td><td>Hash BCRYPT. Nunca armazenadas em texto plano</td></tr>
</table>

<div class="callout info" style="margin-top:32px">
<strong>Workers (cron):</strong> O sistema possui 3 workers que rodam em background:<br>
• <code>worker.php</code> (a cada 1 min) — processa fila de jobs (relatórios CSV/XLSX/PDF)<br>
• <code>trip_builder.php</code> (a cada 15 min) — segmenta GPS em viagens por ignição (cálculo Haversine de distância)<br>
• <code>metrics_rollup.php</code> (a cada 5 min) — pré-computa KPIs para o Resumo e BI<br>
• <code>log_cleanup.php</code> (diário 03:10) — rotação e purga de logs por tamanho e idade
</div>

<p style="text-align:center;margin-top:48px;font-size:12px;color:var(--muted);padding-bottom:40px">
JIMI Webhook System v4.2.1 — Central de Ajuda — Última atualização: Julho 2026
</p>

    </div><!-- /.wiki-content -->
</div><!-- /.wiki-wrap -->

<script>
// ── Scroll spy: destaca item ativo no TOC ──
(function () {
    var toc = document.getElementById('wikiToc');
    var links = toc.querySelectorAll('a');
    var headings = [];
    links.forEach(function (a) {
        var id = a.getAttribute('href').replace('#', '');
        var el = document.getElementById(id);
        if (el) headings.push({ el: el, link: a });
    });
    var content = document.getElementById('wikiContent');
    content.addEventListener('scroll', function () {
        var scrollTop = content.scrollTop + 60;
        var active = null;
        headings.forEach(function (h) {
            if (h.el.offsetTop <= scrollTop) active = h;
        });
        links.forEach(function (l) { l.classList.remove('active'); });
        if (active) active.link.classList.add('active');
    });
    // Smooth scroll from TOC
    links.forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var id = this.getAttribute('href').replace('#', '');
            var target = document.getElementById(id);
            if (target) {
                content.scrollTo({ top: target.offsetTop - 20, behavior: 'smooth' });
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php';
