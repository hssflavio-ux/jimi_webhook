<?php
/**
 * JIMI Webhook System — Wiki / Central de Ajuda v4.9.32
 * Rota: /wiki
 *
 * Documentação do sistema para o USUÁRIO FINAL: mockups visuais das telas,
 * ações disponíveis e resultados esperados. Sem jargão técnico, sem caminhos
 * de URL e sem seções de integração/infra (webhooks, motor, segurança).
 *
 * Atualizada na v4.9.16 com o que mudou desde a v4.7.1 para quem USA:
 * - Endereço no lugar de latitude/longitude, filtro por PLACA, coluna Mapa.
 * - Painel de informações do vídeo ao vivo, extração de gravação ponta a ponta.
 * - Player de vídeo com snapshot no detalhe de ocorrência e coluna Vídeo nos alarmes.
 * - Eventos de diagnóstico separados dos alarmes operacionais (modo admin).
 * - Parâmetros: área de administrador reunindo leitura, relatório e perfis de
 *   configuração remota das câmeras JT/T.
 * - Remetente dos e-mails como `bycamera`.
 *
 * Atualizada na v4.9.32:
 * - Firmware: a versão instalada em cada câmera e as URLs de atualização por
 *   modelo. O texto insiste no ponto que não é óbvio para quem usa — a URL do
 *   modelo errado NÃO dá erro, a câmera baixa e aplica.
 *
 * Duas regras de negócio que o usuário PRECISA entender e que só existem aqui:
 * o sistema notifica por OCORRÊNCIA e não por alarme (12 alarmes em rajada =
 * 1 aviso, e isso é o desenho funcionando), e o link do relatório grande é
 * secreto mas não exige login — ambas com callout próprio.
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
.map-credit {
    position: absolute;
    right: 6px; bottom: 4px;
    font-size: 9px;
    color: rgba(0,0,0,.5);
    background: rgba(255,255,255,.75);
    padding: 1px 6px;
    border-radius: 3px;
    z-index: 2;
}
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
        <a href="#notificacoes">Notificações</a>
        <a href="#videos">Vídeos</a>
        <a href="#video-aovivo" style="padding-left:20px;font-size:12px">Ao Vivo</a>
        <a href="#video-playback" style="padding-left:20px;font-size:12px">Playback</a>
        <a href="#video-downloads" style="padding-left:20px;font-size:12px">Downloads</a>
        <a href="#relatorios">Relatórios</a>
        <a href="#rel-comum" style="padding-left:20px;font-size:12px">Comum a todos</a>
        <a href="#rel-modelos" style="padding-left:20px;font-size:12px">Modelos salvos</a>
        <a href="#rel-posicoes" style="padding-left:20px;font-size:12px">Posições</a>
        <a href="#rel-deslocamento" style="padding-left:20px;font-size:12px">Deslocamento</a>
        <a href="#rel-desatualizados" style="padding-left:20px;font-size:12px">Desatualizados</a>
        <a href="#rel-alarmes" style="padding-left:20px;font-size:12px">Alarmes</a>
        <a href="#rel-ocorrencias" style="padding-left:20px;font-size:12px">Ocorrências</a>
        <a href="#rel-geocercas" style="padding-left:20px;font-size:12px">Geocercas</a>
        <a href="#rel-status-frota" style="padding-left:20px;font-size:12px">Status da Frota</a>
        <a href="#rel-paradas" style="padding-left:20px;font-size:12px">Paradas</a>
        <a href="#rel-ociosidade" style="padding-left:20px;font-size:12px">Ociosidade</a>
        <a href="#rel-ignicao" style="padding-left:20px;font-size:12px">Ignição</a>
        <a href="#rel-velocidade" style="padding-left:20px;font-size:12px">Excesso de Velocidade</a>
        <a href="#agendamentos" style="padding-left:20px;font-size:12px">Agendamentos</a>
        <a href="#cadastros">Cadastros</a>
        <a href="#ativos" style="padding-left:20px;font-size:12px">Ativos</a>
        <a href="#chips" style="padding-left:20px;font-size:12px">Chips</a>
        <a href="#clientes" style="padding-left:20px;font-size:12px">Clientes</a>
        <a href="#equipamentos" style="padding-left:20px;font-size:12px">Equipamentos</a>
        <a href="#geocercas" style="padding-left:20px;font-size:12px">Geocercas</a>
        <a href="#grupos-permissao" style="padding-left:20px;font-size:12px">Grupos de Permissão</a>
        <a href="#motoristas" style="padding-left:20px;font-size:12px">Motoristas</a>
        <a href="#config-ocorrencias" style="padding-left:20px;font-size:12px">Config. Ocorrências</a>
        <a href="#config-notificacoes" style="padding-left:20px;font-size:12px">Config. Notificações</a>
        <a href="#config-smtp" style="padding-left:20px;font-size:12px">Servidor de E-mail</a>
        <a href="#usuarios" style="padding-left:20px;font-size:12px">Usuários</a>
        <a href="#operacoes">Operações</a>
        <a href="#comandos" style="padding-left:20px;font-size:12px">Comandos</a>
        <a href="#parametros" style="padding-left:20px;font-size:12px">Parâmetros</a>
        <a href="#firmwares" style="padding-left:20px;font-size:12px">Firmware</a>
        <a href="#exportar" style="padding-left:20px;font-size:12px">Exportar</a>
        <a href="#checklist" style="padding-left:20px;font-size:12px">Checklist</a>
    </nav>

    <!-- ── Content ──────────────────────────────────── -->
    <div class="wiki-content" id="wikiContent">

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="intro">Visão Geral do Sistema</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<div class="intro">
<p>A <strong>bycamera</strong> é uma plataforma de rastreamento e telemetria de vídeo para frotas de veículos. Ela acompanha os rastreadores e câmeras inteligentes instalados nos veículos e oferece um painel completo para monitoramento ao vivo, vídeos, relatórios e gestão de ocorrências de comportamento do motorista.</p>

<p><strong>O que o sistema faz:</strong></p>
<ul style="font-size:14px;line-height:1.8;color:var(--body)">
    <li>Acompanha em tempo real posições, alertas e vídeos enviados pelos veículos</li>
    <li>Exibe no mapa a posição ao vivo de todos os veículos da frota</li>
    <li>Detecta automaticamente ocorrências de comportamento (distração, celular, fadiga, sem cinto...) pelas câmeras inteligentes</li>
    <li>Permite tratar cada ocorrência com vídeo, notas e classificação de risco</li>
    <li>Oferece vídeo ao vivo e gravações históricas das câmeras</li>
    <li>Gera relatórios de posição, deslocamento, alarmes e ocorrências</li>
    <li>Permite enviar comandos remotamente para os dispositivos</li>
    <li>Cada cliente vê apenas a sua própria frota</li>
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
<p>Ao acessar o sistema pela primeira vez (sem usuários cadastrados), a tela de <strong>Configuração Inicial</strong> permite criar o primeiro administrador. Informe nome, e-mail e senha (mínimo 6 caracteres). Após a criação, você será levado ao login.</p>

<div class="callout warn">
<strong>Atenção:</strong> A tela de configuração inicial só aparece enquanto nenhum usuário foi cadastrado. Depois do primeiro cadastro, ela deixa de existir.
</div>

<div class="mockup">
<div class="mockup-header">Tela de Setup — Primeiro Acesso</div>
<div class="mockup-body">
    <div style="max-width:420px;margin:0 auto;text-align:center">
        <img src="/web/assets/logo-login.png" alt="bycamera" style="width:240px;max-width:100%;height:auto;margin-bottom:10px">
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
<p>Tela de entrada do sistema. Informe e-mail e senha para acessar. Em caso de erro, a mensagem aparece em vermelho acima do formulário.</p>

<div class="mockup">
<div class="mockup-header">Tela de Login</div>
<div class="mockup-body">
    <div style="max-width:400px;margin:0 auto;text-align:center">
        <img src="/web/assets/logo-login.png" alt="bycamera" style="width:280px;max-width:100%;height:auto;margin-bottom:14px">
        <?php /* O descritor já faz parte da arte do login (v4.8.2) — repeti-lo
                 abaixo do logo faria o mockup descrever uma tela que não existe. */ ?>
        <div style="font-size:20px;color:var(--ink);margin-bottom:24px">Entrar no sistema</div>
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
<tr><td>Login com credenciais corretas</td><td>Abre a tela de Resumo</td></tr>
<tr><td>Login com credenciais erradas</td><td>Mensagem de erro. Após 5 tentativas em 15 min, conta bloqueada temporariamente</td></tr>
<tr><td>Esqueceu a senha</td><td>Contate o administrador do sistema (não há recuperação automática)</td></tr>
<tr><td>Acessar sem login</td><td>O sistema pede e-mail e senha antes de mostrar qualquer tela</td></tr>
</table>

<!-- ── Trocar Cliente ───────────────────────────────── -->
<h3>Trocar Cliente</h3>
<p>No topo do menu lateral há um seletor de cliente. Usuários revendedores podem alternar entre os clientes que gerenciam para visualizar os dados de cada um.</p>
<div class="callout tip">
<strong>Dica:</strong> O cliente ativo aparece no topo do menu lateral. Ao trocar, todas as telas passam a mostrar dados do cliente selecionado.
</div>

<!-- ── Perfil ───────────────────────────────────────── -->
<h3>Meu Perfil</h3>
<p>Tela acessível pelo avatar no rodapé do menu lateral. Exibe os dados do usuário logado (nome, e-mail, função, grupo de permissão) e permite <strong>alterar a própria senha</strong>.</p>
<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Alterar senha (atual + nova + confirmação)</td><td>Senha atualizada. É necessário usar a nova senha no próximo login</td></tr>
<tr><td>Senha atual incorreta</td><td>Mensagem de erro "Senha atual incorreta"</td></tr>
<tr><td>Nova senha com menos de 6 caracteres</td><td>Mensagem de erro</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="resumo">Resumo <span class="badge">tela inicial</span></h2>
<!-- ═══════════════════════════════════════════════════════════════ -->
<p><strong>Objetivo:</strong> Visão executiva 360° da frota. É a tela inicial após o login. Mostra indicadores, mapa de calor, velocidade da frota, dispositivos desatualizados e gráficos de alarmes/ocorrências. As informações se atualizam sozinhas a cada 30 segundos.</p>

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
        <div class="map-mock" style="height:180px;background:url('/assets/img/wiki_map_city.png') center/cover no-repeat">
            <div style="position:absolute;inset:0;background:radial-gradient(circle at 32% 46%, rgba(230,80,30,.5), rgba(240,160,32,.25) 12%, transparent 24%),radial-gradient(circle at 60% 36%, rgba(230,80,30,.4), rgba(240,160,32,.2) 10%, transparent 20%),radial-gradient(circle at 72% 64%, rgba(240,160,32,.35), transparent 16%),radial-gradient(circle at 45% 68%, rgba(240,160,32,.3), transparent 14%)"></div>
            <span class="map-credit">© OpenStreetMap</span>
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
<tr><td>Indicadores (4 cartões)</td><td>Total de dispositivos, Online, Ocorrências em tratativa, Desatualizados</td><td>Automática (30s)</td></tr>
<tr><td>Mapa de Calor</td><td>Concentração das posições dos veículos nos últimos 30 minutos</td><td>Automática (30s)</td></tr>
<tr><td>Velocidade da Frota</td><td>Velocidade média dos veículos em movimento</td><td>Automática (30s)</td></tr>
<tr><td>Desatualizados</td><td>Dispositivos sem comunicação recente</td><td>Automática (30s)</td></tr>
<tr><td>Gráficos (Alarmes/Ocorrências)</td><td>Volume hora a hora do dia</td><td>Automática (30s)</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="rastreamento">Rastreamento</h2>
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
        <div class="map-mock" style="flex:1;height:320px;background:url('/assets/img/wiki_map_city.png') center/cover no-repeat">
            <div class="map-mock-dot" style="background:#098551;top:30%;left:30%;width:12px;height:12px;border:2px solid #fff"></div>
            <div class="map-mock-dot" style="background:#098551;top:45%;left:55%;width:12px;height:12px;border:2px solid #fff"></div>
            <div class="map-mock-dot" style="background:#c83532;top:55%;left:70%;width:12px;height:12px;border:2px solid #fff"></div>
            <span class="map-credit">© OpenStreetMap</span>
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
<tr><td>Auto-atualização</td><td>As posições se atualizam sozinhas a cada 60 segundos</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="bi">BI — Business Intelligence</h2>
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
<tr><td>Filtro Alarmes</td><td>Seleciona um ou mais tipos de alarme para análise</td></tr>
<tr><td>Sem filtros preenchidos</td><td>Usa padrão: últimos 30 dias, todos os alarmes</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="ocorrencias-dashboard">Dashboard de Ocorrências</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->
<p><strong>Objetivo:</strong> Painel operacional de gestão das ocorrências de monitoramento do motorista. É <strong>o coração do produto</strong>. As câmeras inteligentes detectam comportamentos de risco (distração, uso de celular, fadiga, sem cinto) e geram ocorrências automaticamente. O operador visualiza a fila em tempo real e trata cada caso.</p>

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
    <tr><th>Data/Hora</th><th>Placa</th><th>Tipo</th><th>Risco</th><th>Status</th></tr>
    <tr><td>18/07 14:32</td><td class="mono">FJR7B59</td><td>Distração</td><td><span class="pill-mock red">Alto</span></td><td><span class="pill-mock red">Aguardando</span></td></tr>
    <tr><td>18/07 14:28</td><td class="mono">RQP2A41</td><td>Uso de Celular</td><td><span class="pill-mock yellow">Médio</span></td><td><span class="pill-mock yellow">Em Tratativa</span></td></tr>
    <tr><td>18/07 14:15</td><td class="mono">GHT5C08</td><td>Sem Cinto</td><td><span class="pill-mock green">Baixo</span></td><td><span class="pill-mock green">Resolvida</span></td></tr>
    </table>
</div>
</div>

<h4 style="font-size:14px;font-weight:600;margin:20px 0 8px">Tela de Tratativa (Detalhe da Ocorrência)</h4>
<p>Ao clicar em uma ocorrência, abre-se a tela de detalhe com:</p>
<ul style="font-size:13px;line-height:1.8;color:var(--body)">
    <li><strong>Player de vídeo</strong> do momento do evento, aberto num quadro do <em>meio</em>
        do vídeo (o snapshot). O play roda na própria tela — baixar é opcional.
        Funciona com vídeos MP4 e com gravações em formato <code>.ts</code> das câmeras JT/T</li>
    <li><strong>Alarmes agrupados</strong> (todos os alarmes que compõem a ocorrência, com dados de GPS e velocidade)</li>
    <li><strong>Mini-mapa</strong> da localização do evento</li>
    <li><strong>Transições de status:</strong> Iniciar Tratativa → Resolver → Descartar</li>
    <li><strong>Campo de notas</strong> para o operador registrar observações</li>
    <li><strong>Marcação de Falso Positivo</strong> para sinalizar alarmes incorretos</li>
</ul>

<div class="callout tip">
<strong>Ocorrência identificada pela placa.</strong> O cabeçalho do detalhe mostra a <strong>placa</strong> do veículo — não o IMEI. É a mesma placa cadastrada em <em>Cadastros › Equipamentos</em>. Se a placa exibida não estiver certa, o cadastro do equipamento é o lugar para corrigir.
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Clicar em uma ocorrência</td><td>Abre tela de detalhe com vídeo, alarmes agrupados e mapa</td></tr>
<tr><td>Iniciar Tratativa</td><td>Status muda para "Em Tratativa", registra operador e data/hora</td></tr>
<tr><td>Resolver</td><td>Status muda para "Resolvida"</td></tr>
<tr><td>Descartar / Falso Positivo</td><td>Status muda para "Descartada". Se marcado como falso positivo, não conta nas estatísticas</td></tr>
<tr><td>Adicionar nota</td><td>Nota de texto salva junto com a transição de status</td></tr>
<tr><td>Auto-atualização</td><td>Grade e indicadores se atualizam sozinhos a cada 15 segundos</td></tr>
<tr><td>Filtro de período</td><td>Filtra ocorrências por intervalo de datas</td></tr>
</table>

<div class="callout info">
<strong>Fluxo completo:</strong> Câmera detecta o evento → o sistema registra a ocorrência → o operador vê na fila → trata (vê o vídeo, classifica, resolve). Do evento no veículo até aparecer na tela, leva poucos segundos.
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="notificacoes">Notificações</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<p>Um alarme importante não depende mais de alguém estar com o dashboard aberto na tela certa. Quando uma ocorrência nova é criada, o sistema avisa pelos canais que você escolher: <strong>sino</strong>, <strong>pop-up</strong>, <strong>som</strong> e <strong>e-mail</strong>.</p>

<div class="mockup">
<div class="mockup-header">Sino no topo da tela</div>
<div class="mockup-body">
    <div style="display:flex;justify-content:flex-end;margin-bottom:10px">
        <div style="position:relative;display:inline-block">
            <span style="font-size:18px">🔔</span>
            <span style="position:absolute;top:-6px;right:-8px;background:#cf202f;color:#fff;font-size:10px;font-weight:600;padding:1px 5px;border-radius:100px">3</span>
        </div>
    </div>
    <div style="max-width:340px;margin-left:auto;border:1px solid var(--hairline);border-radius:var(--radius);overflow:hidden">
        <div style="padding:10px 14px;background:#f5f6f8;border-bottom:1px solid var(--hairline);display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:12px;font-weight:600">Notificações</span>
            <span style="font-size:11px;color:var(--primary)">Marcar todas como lidas</span>
        </div>
        <div style="padding:10px 14px;border-bottom:1px solid var(--hairline-soft);background:var(--primary-soft)">
            <div style="font-size:12px;font-weight:600">Uso de celular — CAM-001</div>
            <div style="font-size:11px;color:var(--muted)">Risco alto · há 2 minutos</div>
        </div>
        <div style="padding:10px 14px;border-bottom:1px solid var(--hairline-soft)">
            <div style="font-size:12px;font-weight:600">Saída de cerca: Pátio Central</div>
            <div style="font-size:11px;color:var(--muted)">FJR7B59 · há 18 minutos</div>
        </div>
        <div style="padding:10px 14px">
            <div style="font-size:12px;font-weight:600">Sonolência — CAM-004</div>
            <div style="font-size:11px;color:var(--muted)">Risco alto · há 40 minutos</div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Canal</th><th>Como se comporta</th></tr>
<tr><td><strong>Sino</strong></td><td>O número vermelho ao lado do sino conta as não lidas. Clicar abre o painel com as mais recentes; clicar em uma delas leva à tela do evento e marca como lida. <strong>Marcar todas como lidas</strong> zera o contador.</td></tr>
<tr><td><strong>Pop-up</strong></td><td>Um aviso aparece na tela no momento em que o evento chega, sem precisar recarregar a página.</td></tr>
<tr><td><strong>Som</strong></td><td>Um alerta sonoro acompanha o pop-up. Útil para quem monitora vários veículos e não fica olhando o tempo todo para a tela.</td></tr>
<tr><td><strong>E-mail</strong></td><td>Mensagem para até 3 endereços por regra. Sai <strong>desligado</strong> por padrão — ninguém recebe e-mail sem ter pedido.</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Detalhe</th><th>Como funciona</th></tr>
<tr><td>Atualização</td><td>O sino se atualiza sozinho a cada 30 segundos. Com a aba em segundo plano ele pausa e volta a atualizar quando você retorna.</td></tr>
<tr><td>Quem vê o quê</td><td>Cada usuário vê apenas as notificações do cliente em que está posicionado. Trocar de cliente troca a caixa de notificações.</td></tr>
<tr><td>Quais eventos notificam</td><td>Ocorrências de comportamento (conforme as regras em <em>Cadastros › Config. Notificações</em>), entradas e saídas de geocerca e avisos do próprio sistema — por exemplo, um agendamento de relatório desativado por falhas.</td></tr>
<tr><td>Limpeza automática</td><td>Notificações lidas somem depois de 30 dias; as não lidas, depois de 90. Não é preciso limpar a caixa manualmente.</td></tr>
</table>

<div class="callout warn">
<strong>O sistema avisa por ocorrência, não por alarme.</strong> Um motorista distraído por meio minuto pode gerar uma dezena de alarmes seguidos da câmera. O sistema agrupa esses alarmes em <strong>uma</strong> ocorrência e envia <strong>um</strong> aviso — o comportamento é esse de propósito. Se você espera 12 avisos e recebe 1, o agrupamento está funcionando, não falhando. (A janela de agrupamento é configurada em <em>Cadastros › Config. Ocorrências</em>.)
</div>

<div class="callout info">
<strong>Freio contra enxurrada:</strong> se um mesmo cliente passar de 60 notificações em uma hora, o sistema grava uma única notificação-resumo dizendo quantos avisos foram suprimidos e para de notificar até a hora virar. É o que impede um equipamento com defeito de encher a caixa de todo mundo.
</div>

<p>As regras — o que notifica, por qual canal e para quem — ficam em <strong>Cadastros › Config. Notificações</strong>. O envio de e-mail depende também do <strong>Cadastros › Servidor de E-mail</strong>.</p>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="videos">Vídeos</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<h3 id="video-aovivo">Ao Vivo</h3>
<p><strong>Objetivo:</strong> Assistir ao vivo às câmeras dos veículos. Escolha o equipamento e o canal da câmera, clique em <strong>Iniciar Transmissão</strong> e aguarde alguns segundos até a imagem aparecer.</p>

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
            <span>Transmissão ao vivo — Canal 1</span>
            <span style="font-size:11px;opacity:.6">Conectando à câmera...</span>
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
<tr><td>Selecionar equipamento + canal + Iniciar</td><td>A câmera é acionada e o vídeo abre em alguns segundos (normalmente entre 5 e 30)</td></tr>
<tr><td>Trocar de canal</td><td>Encerra a transmissão atual e abre a imagem do canal escolhido</td></tr>
<tr><td>Dispositivo offline</td><td>O pedido fica agendado e a barra de status avisa que será executado quando o equipamento voltar a se conectar</td></tr>
<tr><td>Rotação de tela</td><td>A imagem aparece girada conforme configurado no cadastro do equipamento</td></tr>
<tr><td>Marca d'água</td><td>Se habilitada no cadastro, o vídeo exibe o texto de marca d'água</td></tr>
</table>

<div class="callout warn">
<strong>Aguarde a imagem:</strong> Entre clicar em "Iniciar" e o vídeo aparecer, a câmera precisa ser ativada e começar a transmitir. Isso leva de 5 a 30 segundos — a tela mostra o progresso enquanto isso.
</div>

<p>Ao lado do player, um <strong>painel de informações</strong> descreve o equipamento selecionado: a <strong>placa</strong>, os <strong>canais de câmera</strong> que aquele modelo tem, a <strong>última comunicação</strong> (no horário de Brasília) e o <strong>status</strong> de conexão.</p>

<div class="callout info">
<strong>O painel acompanha a lista.</strong> Trocar o equipamento na lista troca também os dados do painel. Se a placa ou os canais exibidos não corresponderem ao veículo que você escolheu, o dado está errado — não é uma defasagem esperada.
</div>

<h3 id="video-playback">Playback</h3>
<p><strong>Objetivo:</strong> Visualizar gravações históricas do cartão de memória do equipamento. Ao clicar em <strong>Requisitar Gravações</strong>, o sistema consulta o cartão e monta a lista do período escolhido. A lista mostra gravações "No cartão" (com opção Extrair) e "Disponível" (já baixadas, prontas para reproduzir).</p>

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
<tr><td>Requisitar Gravações</td><td>Consulta o equipamento e preenche a lista de gravações do período</td></tr>
<tr><td>Extrair (gravação "No cartão")</td><td>Pede o envio da gravação escolhida. A câmera envia o arquivo e o status muda sozinho para "Disponível" — um trecho de 30 segundos costuma levar poucos segundos; trechos longos, proporcionalmente mais</td></tr>
<tr><td>Reproduzir (gravação "Disponível")</td><td>Abre o player e reproduz a gravação na própria tela, com barra de progresso — dá para arrastar para um ponto específico do vídeo</td></tr>
<tr><td>Auto-atualização</td><td>Após requisitar, a lista se atualiza sozinha por alguns instantes</td></tr>
</table>

<div class="callout info">
<strong>O arquivo extraído aparece no dia que você filtrou.</strong> Uma gravação recém-extraída entra na linha do tempo na data e hora em que foi <em>gravada</em>, não na data em que você a extraiu. Se você filtrou o dia 05 e extraiu um trecho daquele dia, ele aparece ali mesmo — não é preciso trocar o filtro para encontrá-lo.
</div>

<div class="callout warn">
<strong>Extrair depende da câmera estar conectada.</strong> O pedido vai até o equipamento e é ele quem envia o arquivo. Com a câmera fora do ar, a gravação continua marcada como "No cartão" — ela não se perde, mas só chega quando o equipamento voltar a se comunicar.
</div>

<h3 id="video-downloads">Downloads</h3>
<p><strong>Objetivo:</strong> Grade com todos os arquivos de mídia disponíveis para download. Filtros por equipamento e status (disponível, solicitado, erro). Clique no nome do arquivo para baixar.</p>

<div class="mockup">
<div class="mockup-header">Downloads — Arquivos de Mídia</div>
<div class="mockup-body">
    <div class="filter-bar-mock" style="margin-bottom:0">
        <div class="filter-mock">Equipamento: Todos</div>
        <div class="filter-mock">Status: Disponível</div>
    </div>
    <table class="tbl-mock" style="margin-top:12px">
    <tr><th>Arquivo</th><th>Placa</th><th>Tipo</th><th>Tamanho</th><th>Data</th><th>Status</th></tr>
    <tr><td style="color:var(--primary);cursor:default">86011207_20260718_01.mp4</td><td class="mono">FJR7B59</td><td>vídeo</td><td class="mono">21.4 MB</td><td>18/07 14:32</td><td><span class="pill-mock green">Disponível</span></td></tr>
    <tr><td style="color:var(--primary);cursor:default">86905807_20260718_02.jpg</td><td class="mono">RQP2A41</td><td>imagem</td><td class="mono">245 KB</td><td>18/07 14:28</td><td><span class="pill-mock green">Disponível</span></td></tr>
    <tr><td>86547807_20260718_03.mp4</td><td class="mono">GHT5C08</td><td>vídeo</td><td class="mono">—</td><td>18/07 13:55</td><td><span class="pill-mock yellow">Solicitado</span></td></tr>
    </table>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="relatorios">Relatórios</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<h3 id="rel-comum">O que vale para todos os relatórios</h3>
<p>Estes comportamentos são iguais em todas as telas de relatório. O que muda de um para outro são os filtros e as colunas.</p>

<table class="tbl-mock">
<tr><th>Recurso</th><th>Como funciona</th></tr>
<tr>
    <td><strong>Escolher o veículo</strong></td>
    <td>O filtro de equipamento é uma <strong>lista de placas</strong>, não um campo de digitação. Abra a lista e escolha — só aparecem os veículos que você tem permissão de ver. A placa também é a <strong>primeira coluna</strong> da maioria dos relatórios, na tela e nos arquivos exportados.</td>
</tr>
<tr>
    <td><strong>Endereço, não coordenada</strong></td>
    <td>Onde antes apareciam números de latitude e longitude, hoje aparece o <strong>endereço</strong> no formato <em>rua, cidade, estado</em>. Em rodovia ou zona rural o endereço pode sair mais curto (às vezes só cidade e estado) — é o que existe de referência naquele ponto.</td>
</tr>
<tr>
    <td><strong>Coluna Mapa</strong></td>
    <td>Os relatórios com posição trazem uma coluna <strong>Mapa</strong>. Na tela, no Excel e no PDF ela é um link que abre aquele ponto no mapa, e <strong>funciona para quem recebe o arquivo</strong>, mesmo sem conta no sistema.</td>
</tr>
<tr>
    <td><strong>Ordem dos resultados</strong></td>
    <td>Todo relatório com data abre em <strong>ordem crescente</strong>: o registro mais antigo no topo e o mais recente no fim da página — a leitura acompanha a linha do tempo.</td>
</tr>
<tr>
    <td><strong>Setinha de ordenação</strong></td>
    <td>As colunas ordenáveis têm uma seta no cabeçalho. A coluna em uso mostra <strong>▲</strong> (crescente) ou <strong>▼</strong> (decrescente) em azul; as demais mostram <strong>⇅</strong> em cinza. Um clique inverte a ordem, outro clique volta. Os filtros são mantidos e a listagem volta para a página 1.</td>
</tr>
<tr>
    <td><strong>Botão Voltar</strong></td>
    <td>Depois que o resultado aparece, surge o botão <strong>← Voltar</strong> no canto superior direito, ao lado dos botões de exportar. Ele limpa os filtros e devolve a tela em branco do mesmo relatório — não é preciso ir de novo ao menu lateral.</td>
</tr>
<tr>
    <td><strong>Paginação</strong></td>
    <td>Os números acompanham a página em que você está: a primeira e a última ficam sempre visíveis e as reticências indicam o salto. Estando na página 12 de 14, por exemplo, aparece <span class="mono">« 1 … 10 11 12 13 14 »</span>. As setas « e » avançam de uma em uma.</td>
</tr>
<tr>
    <td><strong>Exportar</strong></td>
    <td>Excel ou PDF, sempre com <strong>os mesmos filtros e a mesma ordenação</strong> que estão na tela. O arquivo baixa na hora (até 10.000 linhas). Para volumes maiores, use a tela Exportar, que processa em segundo plano.</td>
</tr>
</table>

<div class="callout info">
<strong>Período máximo de 31 dias:</strong> Todo relatório com filtro de data aceita no máximo 31 dias por consulta. Se você pedir um intervalo maior, o sistema encurta a data final e avisa na tela com uma tarja amarela. Para períodos longos, faça a consulta em partes ou use a tela Exportar.
</div>

<div class="callout tip">
<strong>Horários sempre em Brasília:</strong> Todas as datas e horas exibidas nos relatórios — e as que você digita nos filtros — estão no horário de Brasília. Os equipamentos transmitem em outro fuso, e o sistema faz a conversão sozinho.
</div>

<h3 id="rel-modelos">Modelos salvos</h3>
<p><strong>Objetivo:</strong> Guardar uma combinação de filtros que você usa com frequência e reaplicá-la em um clique, em vez de preencher tudo de novo toda vez.</p>

<div class="mockup">
<div class="mockup-header">Barra de modelos — aparece acima dos filtros</div>
<div class="mockup-body">
    <div class="filter-bar-mock">
        <span style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted)">Modelos</span>
        <div class="filter-mock">Frota SP — risco alto</div>
        <span class="btn-mock outline">Excluir</span>
        <span class="btn-mock">Salvar filtros atuais como modelo</span>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Salvar filtros atuais como modelo</td><td>Pede um nome e guarda os filtros que estão na tela naquele momento</td></tr>
<tr><td>Escolher um modelo na lista</td><td>A tela recarrega já com aqueles filtros preenchidos e o resultado gerado</td></tr>
<tr><td>Excluir</td><td>Apaga o modelo selecionado</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Detalhe</th><th>Como funciona</th></tr>
<tr><td>Onde aparece</td><td>Em todas as telas de relatório. A barra fica escondida enquanto não há modelo salvo nem filtro aplicado — numa tela recém-aberta não há o que guardar.</td></tr>
<tr><td>De quem é o modelo</td><td>Seu. Cada usuário vê apenas os próprios modelos; ninguém enxerga nem apaga o modelo de outra pessoa.</td></tr>
<tr><td>Não se misturam</td><td>Um modelo pertence à tela em que foi criado. Um modelo de Alarmes não aparece na lista de Posições.</td></tr>
<tr><td>Limite</td><td>Até 20 modelos por relatório, por usuário.</td></tr>
<tr><td>O que <em>não</em> é guardado</td><td>Página, ordenação e formato de exportação ficam de fora — senão o modelo abriria sempre na página 7 ou baixaria um arquivo em vez de mostrar a tela.</td></tr>
</table>

<div class="callout tip">
<strong>O período faz parte do modelo.</strong> Se o modelo foi salvo com um intervalo de datas fixo, ele volta com aquelas mesmas datas — ajuste o período depois de aplicar. Para receber o relatório pronto e recorrente, o caminho é <a href="#agendamentos" style="color:inherit">Agendamentos</a>.
</div>

<h3 id="rel-posicoes">Posições</h3>
<p><strong>Objetivo:</strong> Histórico de posições de um ativo em um período. Mostra o trajeto percorrido no mapa + tabela paginada com data/hora, endereço, velocidade e ignição. Pode ser exportado em Excel ou PDF.</p>

<div class="mockup">
<div class="mockup-header">Relatório de Posições</div>
<div class="mockup-body">
    <div class="filter-bar-mock">
        <div class="filter-mock">Ativo: CAM-001</div>
        <div class="filter-mock dim">dd/mm/aaaa - dd/mm/aaaa</div>
        <div class="filter-mock dim">08:00 - 10:00</div>
        <div class="filter-mock">Em cada dia do período</div>
        <span class="btn-mock">Gerar</span>
        <span class="btn-mock outline">Exportar</span>
        <span class="btn-mock outline">← Voltar</span>
    </div>
    <div class="map-mock" style="height:180px;background:url('/assets/img/wiki_map_streets.png') center/cover no-repeat">
        <svg style="position:absolute;inset:0;width:100%;height:100%" viewBox="0 0 100 100" preserveAspectRatio="none">
            <polyline points="20,52 40,47 60,37 80,27" fill="none" stroke="#0052ff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" opacity=".85"/>
        </svg>
        <div class="map-mock-dot" style="top:50%;left:20%"></div>
        <div class="map-mock-dot" style="top:45%;left:40%"></div>
        <div class="map-mock-dot" style="top:35%;left:60%"></div>
        <div class="map-mock-dot" style="top:25%;left:80%"></div>
        <span class="map-credit">© OpenStreetMap</span>
    </div>
    <table class="tbl-mock">
    <tr><th>Data/Hora ▲</th><th>Endereço</th><th>Velocidade</th><th>Ignição</th></tr>
    <tr><td>18/07 08:00:10</td><td>Av. Rangel Pestana, 300 — São Paulo</td><td class="mono">38 km/h</td><td>Ligada</td></tr>
    <tr><td>18/07 08:05:22</td><td>Av. Rangel Pestana, 812 — São Paulo</td><td class="mono">42 km/h</td><td>Ligada</td></tr>
    <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:20px">Página 12 de 14 (700 posições) — « 1 … 10 11 <strong>12</strong> 13 14 »</td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar ativo + período + Gerar</td><td>Mapa e tabela carregam com os dados do período, do mais antigo para o mais recente</td></tr>
<tr><td><strong>Faixa horária</strong> (opcional)</td><td>Além das datas, você pode informar hora inicial e final. Deixando em branco, vale o dia inteiro (00:00 às 23:59)</td></tr>
<tr><td>Intervalo</td><td>"Todas as posições" ou "Amostrado (1:10)", que traz 1 a cada 10 posições — útil para períodos longos</td></tr>
<tr><td>Ver Posições no Mapa</td><td>Abre o mapa com os pontos da página atual</td></tr>
<tr><td>Exportar</td><td>Baixa o arquivo em Excel ou PDF, com os mesmos filtros e a mesma ordenação da tela</td></tr>
<tr><td>Navegar páginas</td><td>Paginação de 50 em 50 posições</td></tr>
</table>

<div class="callout info">
<strong>Faixa horária: as duas maneiras de usar.</strong> Ao informar hora inicial e final, escolha ao lado como o sistema deve aplicá-las ao período:
<ul style="margin:8px 0 0 18px;line-height:1.7">
    <li><strong>Contínua (início → fim)</strong> — uma única janela, do primeiro dia na hora inicial até o último dia na hora final. Pedindo 01/07 a 05/07 das 08:00 às 10:00, você recebe <em>tudo</em> entre 01/07 08:00 e 05/07 10:00, madrugadas incluídas. Use para acompanhar um trajeto que atravessa dias.</li>
    <li><strong>Em cada dia do período</strong> — a faixa se repete em todos os dias. O mesmo pedido traz apenas as manhãs de 08:00 às 10:00 de cada um dos 5 dias. Use para comparar o mesmo horário dia após dia (saída da garagem, horário de almoço, turno da tarde).</li>
</ul>
</div>

<div class="callout tip">
<strong>Turno da noite:</strong> No modo "Em cada dia do período", informe a hora inicial <em>maior</em> que a final para pegar a jornada que vira o dia — <span class="mono">22:00</span> às <span class="mono">06:00</span> traz, de cada dia, o fim da noite e a madrugada seguinte.
</div>

<h3 id="rel-deslocamento">Deslocamento</h3>
<p><strong>Objetivo:</strong> Histórico dos deslocamentos do veículo, com duração, velocidade máxima, distância percorrida e alarmes ocorridos no trajeto. Os deslocamentos são montados automaticamente pelo sistema alguns minutos depois de terminarem.</p>

<p>O relatório tem <strong>duas modalidades</strong>, escolhidas no primeiro campo do filtro:</p>

<table class="tbl-mock">
<tr><th>Modalidade</th><th>O que mostra</th></tr>
<tr><td><strong>Por deslocamento</strong></td><td>Uma linha por trajeto: início, local de partida, término, local de chegada, duração, velocidade máxima, distância e alarmes.</td></tr>
<tr><td><strong>Fechamento diário</strong></td><td>Uma linha por dia e por veículo: primeira ignição ligada, última desligada, <strong>jornada</strong> (do começo ao fim do dia, com as paradas), <strong>tempo em movimento</strong> (só rodando), distância total, velocidade máxima, alarmes e quantidade de deslocamentos do dia.</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar por ativo + período + Gerar</td><td>Tabela carrega na modalidade escolhida, do mais antigo para o mais recente</td></tr>
<tr><td>Faixa horária (opcional)</td><td>Restringe a consulta a um intervalo de horas dentro do período</td></tr>
<tr><td>Ordenar por coluna</td><td>Setinha no cabeçalho de Início, Término, Velocidade Máxima e Distância (ou Dia, no fechamento diário)</td></tr>
<tr><td><strong>Ver rota</strong></td><td>Abre em nova aba o trajeto desenhado no mapa: balão verde na partida, vermelho na chegada, um ponto por posição enviada pela câmera e <strong>as ocorrências em laranja</strong>, com tipo, horário e risco no balão. No fechamento diário, mostra o dia inteiro.</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF com os dados da consulta</td></tr>
</table>

<div class="callout info">
<strong>Como o sistema separa um deslocamento do outro:</strong> o trajeto termina quando a ignição desliga, quando o veículo fica parado por mais de 5 minutos, ou quando o equipamento passa esse mesmo tempo sem comunicar. É por isso que uma jornada com várias paradas aparece como vários deslocamentos, e não como um só — mesmo que o motorista não tenha desligado a ignição em nenhum momento.
</div>

<h3 id="rel-desatualizados">Desatualizados</h3>
<p><strong>Objetivo:</strong> Identificar equipamentos que estão há muito tempo sem enviar posição. A tela abre com cinco faixas — <strong>menos de 24 horas</strong>, <strong>mais de 1 dia</strong>, <strong>mais de 7 dias</strong>, <strong>mais de 30 dias</strong> e <strong>nunca posicionados</strong> — com a quantidade de equipamentos em cada uma e uma barra mostrando a proporção.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Clicar em uma faixa</td><td>Abre a lista dos equipamentos daquela faixa: IMEI, nome, modelo, cliente, última posição e há quantas horas</td></tr>
<tr><td>Ordenar por Última Posição</td><td>Setinha no cabeçalho. Em ordem crescente, os mais desatualizados vêm primeiro — os "nunca posicionados" encabeçam a lista</td></tr>
<tr><td>← Voltar</td><td>Fecha a lista e devolve o resumo com as cinco faixas</td></tr>
<tr><td>Ver posições no mapa</td><td>Abre um mapa com os equipamentos da consulta; cada marcador traz a placa e a data/hora da última posição conhecida</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF da <strong>frota completa</strong> — a lista inteira ordenada por tempo sem transmitir, que é a que se leva para a reunião. A grade principal traz placa, há quanto tempo sem transmitir, data/hora, endereço, mapa, ignição e status do GPS</td></tr>
</table>

<div class="callout info">
<strong>O arquivo exportado é sempre da frota inteira</strong>, mesmo que você tenha aberto uma faixa antes de clicar em Exportar. Isso é proposital: a faixa serve para investigar na tela, e o arquivo serve para levar o quadro completo.
</div>

<h3 id="rel-alarmes">Alarmes</h3>
<p><strong>Objetivo:</strong> Histórico completo dos alarmes recebidos, na ordem em que aconteceram. Filtros por cliente, placa, filial, tipos de alarme (pode marcar vários), situação e período. Cada alarme tem um atalho para ver o local no mapa e, quando o equipamento anexou vídeo, um botão para assistir.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar + Gerar</td><td>Tabela atualiza com os filtros aplicados, do alarme mais antigo para o mais recente</td></tr>
<tr><td>Ordenar por coluna</td><td>Setinha no cabeçalho de Data/Hora, Placa, Código e Nome do Alarme</td></tr>
<tr><td>Tipos de Alarme</td><td>Clique nos tipos para incluí-los na consulta — dá para selecionar vários de uma vez</td></tr>
<tr><td>Ver posições no mapa</td><td>Abre um mapa com todos os alarmes da consulta. Cada marcador traz a <strong>placa, a data/hora e o nome do alarme</strong> — aqui cada ponto é de um veículo diferente</td></tr>
<tr><td>Ver Mapa (na linha)</td><td>Abre o mapa em uma nova aba, no local exato daquele alarme</td></tr>
<tr><td>Ver Vídeo (na linha)</td><td>Abre o vídeo do evento numa janela sobre a tela, já posicionado num quadro do <strong>meio</strong> do vídeo. Toca ali mesmo — baixar é opcional. Só aparece nos alarmes cujo equipamento anexou vídeo</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF com os dados filtrados</td></tr>
</table>

<div class="callout info">
<strong>Nem todo alarme tem vídeo.</strong> A coluna Vídeo mostra o arquivo que o próprio equipamento anexou ao alarme. Câmeras que não anexam vídeo ao evento — ou alarmes que não são de câmera, como excesso de velocidade — aparecem com um traço. Para essas, o caminho é pedir a gravação em <strong>Vídeos → Playback</strong>.
</div>

<div class="callout info">
<strong>Alarme sem nome, só com número.</strong> Raramente um alarme aparece como <span class="mono">Código 1234 (JTT)</span> em vez de um nome. Isso significa que o equipamento enviou um código que ainda não está no catálogo do sistema — que mostra o número em vez de inventar um rótulo que poderia estar errado. O registro é válido: data, hora e local estão corretos. Assim que o código é cadastrado, o nome aparece também nos alarmes antigos, sem precisar refazer nada. Hoje não há nenhum nesta situação: os dois últimos (<span class="mono">1047</span>, capotamento, e <span class="mono">146</span>, curva brusca) foram cadastrados.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Eventos de diagnóstico <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h4>
<p>O sistema distingue <strong>alarmes operacionais</strong> (o que o veículo diz ao operador — distração, celular, fadiga, excesso de velocidade) de <strong>eventos de diagnóstico</strong> (o que o equipamento diz ao sistema — defeito de câmera, perda de sinal de vídeo, falha de armazenamento, handshake de upload, entrada e saída de repouso).</p>

<p>Por padrão a tela mostra apenas os alarmes operacionais — os que são relevantes para quem monitora a frota. Administradores têm, abaixo dos filtros, uma caixa <strong>"Eventos de diagnóstico"</strong> que troca a visão para os eventos técnicos; ela não aparece para outros perfis de usuário.</p>

<div class="callout warn">
<strong>Os dois modos nunca se misturam.</strong> Com a caixa marcada, a tela mostra <em>só</em> os diagnósticos; desmarcada, <em>só</em> os operacionais. Isso é proposital — um operador com centenas de "Falha de Câmera" na mesma lista de "Uso de Celular" perderia os eventos que precisa tratar no meio do ruído de infraestrutura.
</div>

<div class="callout info">
<strong>Diagnóstico não gera ocorrência nem notificação.</strong> Eventos técnicos são internos ao equipamento e não representam comportamento do motorista. Eles não aparecem no Dashboard de Ocorrências, no Resumo, no BI nem nos filtros de alarme dos outros relatórios — ficam restritos ao modo de diagnóstico desta tela.
</div>

<h3 id="rel-ocorrencias">Ocorrências</h3>
<p><strong>Objetivo:</strong> Histórico de ocorrências com filtros por cliente, placa, tipo de alarme, situação, risco, falso positivo, filial e motorista. Visão complementar ao Dashboard de Ocorrências, voltada a auditoria e análise histórica.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Aplicar filtros + Gerar</td><td>Tabela mostra as ocorrências que atendem a todos os critérios, da mais antiga para a mais recente</td></tr>
<tr><td>Ordenar por coluna</td><td>Setinha no cabeçalho de Último Alarme, Placa e Qtd (quantidade de alarmes agrupados)</td></tr>
<tr><td>Abrir</td><td>Abre o detalhe da ocorrência no Dashboard de Ocorrências, com vídeo e histórico de tratativa</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF</td></tr>
</table>

<h3 id="rel-geocercas">Geocercas</h3>
<p><strong>Objetivo:</strong> Saber quando cada veículo entrou e saiu das áreas desenhadas em <em>Cadastros › Geocercas</em>, e quanto tempo ficou dentro de cada uma.</p>

<p>O relatório tem <strong>duas modalidades</strong>, escolhidas no primeiro campo do filtro:</p>

<table class="tbl-mock">
<tr><th>Modalidade</th><th>O que mostra</th></tr>
<tr><td><strong>Entradas e saídas</strong></td><td>Uma linha por travessia: data/hora, geocerca, equipamento, se foi entrada ou saída, a velocidade no instante da travessia e o local no mapa.</td></tr>
<tr><td><strong>Permanência</strong></td><td>Uma linha por visita: geocerca, equipamento, hora de entrada, hora de saída e quanto tempo ficou dentro. Quem entrou e ainda não saiu aparece como <strong>"em permanência"</strong>.</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar por geocerca</td><td>Restringe a uma cerca específica ou mostra todas</td></tr>
<tr><td>Filtrar por tipo</td><td>Só entradas, só saídas ou ambas (na modalidade Entradas e saídas)</td></tr>
<tr><td>Ver Mapa</td><td>Abre em nova aba o ponto exato onde a travessia aconteceu</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF com a modalidade e os filtros da tela</td></tr>
</table>

<div class="callout info">
<strong>A velocidade da travessia é a primeira pergunta de quem audita uma saída não autorizada</strong> — por isso ela aparece na própria linha do evento, sem precisar abrir o mapa.
</div>

<h3 id="rel-status-frota">Status da Frota</h3>
<p><strong>Objetivo:</strong> Uma foto de <em>agora</em>: quantos veículos estão em movimento, ociosos, parados e sem comunicação — com o percentual de cada estado e a lista por trás de cada número.</p>

<div class="mockup">
<div class="mockup-header">Status da Frota</div>
<div class="mockup-body">
    <div class="kpi-row">
        <div class="kpi-box blue"><div class="kpi-label">Em movimento</div><div class="kpi-val">12</div></div>
        <div class="kpi-box yellow"><div class="kpi-label">Ocioso</div><div class="kpi-val">3</div></div>
        <div class="kpi-box"><div class="kpi-label">Parado</div><div class="kpi-val">28</div></div>
        <div class="kpi-box red"><div class="kpi-label">Sem comunicação</div><div class="kpi-val">5</div></div>
    </div>
    <table class="tbl-mock">
    <tr><th>Equipamento</th><th>Cliente</th><th>Estado</th><th>Tempo no estado</th><th>Última posição</th><th>Velocidade</th></tr>
    <tr><td>CAM-001</td><td>Frota Principal</td><td><span class="pill-mock blue">Em movimento</span></td><td>1h 12min</td><td>18/07 14:22:10</td><td class="mono">54 km/h</td></tr>
    <tr><td>FJR7B59</td><td>Frota Principal</td><td><span class="pill-mock yellow">Ocioso</span></td><td>22min</td><td>18/07 14:21:58</td><td class="mono">0 km/h</td></tr>
    <tr><td>CAM-004</td><td>Frota Principal</td><td><span class="pill-mock red">Sem comunicação</span></td><td>6h 40min</td><td>18/07 07:41:03</td><td class="mono">—</td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Clicar num estado</td><td>Filtra a lista para mostrar só os equipamentos naquele estado</td></tr>
<tr><td>Filtrar por cliente ou equipamento</td><td>Recalcula os quatro números e a lista</td></tr>
<tr><td>Ver Mapa</td><td>Abre a última posição conhecida do equipamento</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF com a foto atual</td></tr>
</table>

<div class="callout info">
<strong>A soma dos quatro estados é sempre o total de equipamentos ativos do cliente</strong> — nenhum veículo fica de fora e nenhum é contado duas vezes. Se o total não bater com o que você espera, o que está diferente é a quantidade de equipamentos ativos, não a conta.
</div>

<div class="callout warn">
<strong>Esta tela não tem filtro de período</strong>, e é a única assim: ela responde "como está a frota agora". Para o histórico, use Paradas, Ociosidade ou Ignição. Pelo mesmo motivo, o Status da Frota não pode ser agendado por e-mail — "o estado da frota de ontem às 7h" não significa nada.
</div>

<h3 id="rel-paradas">Paradas</h3>
<p><strong>Objetivo:</strong> Todos os períodos em que cada veículo esteve com a <strong>ignição desligada</strong>, com início, fim, duração e local.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar por cliente, equipamento e período + Gerar</td><td>Lista as paradas da mais antiga para a mais recente</td></tr>
<tr><td>Duração mínima</td><td>Esconde paradas curtas — útil para separar "parou no semáforo" de "ficou a tarde toda parado"</td></tr>
<tr><td>Ordenar por coluna</td><td>Setinha no cabeçalho de Início, Duração e Equipamento</td></tr>
<tr><td>Ver Mapa</td><td>Mostra onde o veículo estava parado</td></tr>
<tr><td>Indicadores no topo</td><td>Quantidade de paradas, tempo total parado, duração média, maior parada e número de equipamentos</td></tr>
</table>

<h3 id="rel-ociosidade">Ociosidade</h3>
<p><strong>Objetivo:</strong> Os períodos em que o veículo ficou <strong>com o motor ligado e imóvel</strong> — combustível queimado sem deslocamento.</p>

<div class="callout warn">
<strong>Ociosidade não é o mesmo que Parada.</strong> As duas telas somam tempos diferentes e respondem perguntas diferentes: ociosidade é <em>motor ligado sem sair do lugar</em> (desperdício de combustível); parada é <em>ignição desligada</em> (veículo fora de operação). Um veículo não pode estar nos dois estados ao mesmo tempo.
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar + Gerar</td><td>Mesmos filtros, colunas e indicadores do relatório de Paradas</td></tr>
<tr><td>Duração mínima</td><td>Descarta as ociosidades curtas e deixa à vista as que custam combustível</td></tr>
</table>

<h3 id="rel-ignicao">Ignição</h3>
<p><strong>Objetivo:</strong> Cada vez que a ignição foi <strong>ligada</strong> ou <strong>desligada</strong>, com o horário e quanto tempo o veículo permaneceu no estado que aquele acionamento abriu.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar por evento</td><td>Só "Ignição ligada", só "Ignição desligada" ou os dois</td></tr>
<tr><td>Indicadores no topo</td><td>Quantas vezes ligou, quantas desligou, tempo total de motor ligado, tempo de motor desligado e número de equipamentos</td></tr>
<tr><td>Ver Mapa</td><td>Mostra onde o acionamento aconteceu</td></tr>
</table>

<div class="callout info">
<strong>Sair do movimento e ficar ocioso não é acionamento de ignição.</strong> O motor continua ligado; o que mudou foi a velocidade. Só a passagem de ligada para desligada (e vice-versa) entra neste relatório — é o que faz o número de "ignições desligadas" bater exatamente com a quantidade de paradas do mesmo período.
</div>

<div class="callout warn">
<strong>Períodos sem comunicação não geram acionamento.</strong> Se o equipamento passou horas sem transmitir, ninguém sabe o que a ignição fez nesse intervalo — e o relatório não inventa um "desligou" no começo do silêncio nem um "ligou" no fim.
</div>

<h3 id="rel-velocidade">Excesso de Velocidade</h3>
<p><strong>Objetivo:</strong> Os trechos em que o veículo rodou acima do limite configurado, com velocidade máxima atingida, limite vigente, excedente e duração da infração.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar + Gerar</td><td>Lista as infrações do período, da mais antiga para a mais recente</td></tr>
<tr><td>Excedente mínimo</td><td>Mostra só quem passou mais de X km/h do limite — separa os 2 km/h de margem de medição dos 30 km/h que exigem conversa com o motorista</td></tr>
<tr><td>Ordenar por coluna</td><td>Setinha no cabeçalho de Início, Duração, Equipamento e Velocidade máxima</td></tr>
<tr><td>Ver Mapa</td><td>Abre o ponto onde a <strong>velocidade máxima</strong> foi registrada — não onde a infração começou</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Regra</th><th>Como funciona</th></tr>
<tr><td>Qual limite vale</td><td>O do equipamento, se ele tiver um. Sem isso, o limite padrão do cliente. Sem nenhum dos dois, <strong>80 km/h</strong>.</td></tr>
<tr><td>Onde configurar</td><td>Por equipamento em <em>Cadastros › Equipamentos</em>; por frota em <em>Cadastros › Clientes</em>.</td></tr>
<tr><td>Um pico não é infração</td><td>Um único ponto acima do limite é descartado — é indistinguível de erro de leitura do GPS. São necessários pelo menos dois envios seguidos acima do limite.</td></tr>
<tr><td>Velocidade igual ao limite</td><td>Não é infração. A apuração é <em>acima</em> do limite.</td></tr>
<tr><td>Limite mostrado</td><td>É o que estava vigente quando a infração foi apurada. Mudar o limite hoje não reescreve o histórico.</td></tr>
</table>

<div class="callout tip">
<strong>Como os quatro estados são definidos</strong> — vale para Status da Frota, Paradas, Ociosidade e Ignição:
<ul style="margin:8px 0 0 18px;line-height:1.7">
    <li><strong>Em movimento</strong> — ignição ligada e velocidade acima de 3 km/h.</li>
    <li><strong>Ocioso</strong> — ignição ligada e velocidade de até 3 km/h (o veículo está imóvel; abaixo disso o que se mede é oscilação do GPS, não deslocamento).</li>
    <li><strong>Parado</strong> — ignição desligada.</li>
    <li><strong>Sem comunicação</strong> — mais de 30 minutos sem nenhuma posição. É ausência de dado, não um estado do veículo: durante o silêncio, ninguém sabe o que o veículo fez.</li>
</ul>
</div>

<div class="callout warn">
<strong>Estes cinco relatórios são montados em segundo plano</strong>, a cada 15 minutos, e não no instante da consulta. Duas consequências: (1) o que aconteceu nos últimos minutos pode ainda não aparecer; (2) o histórico começa na data em que o recurso foi ativado no seu ambiente — períodos anteriores só aparecem se o administrador tiver pedido a recuperação do histórico na implantação.
</div>

<h3 id="agendamentos">Agendamentos</h3>
<p><strong>Objetivo:</strong> Configurar um relatório uma única vez e recebê-lo por e-mail na frequência escolhida, sem precisar entrar no sistema para gerá-lo.</p>

<div class="mockup">
<div class="mockup-header">Agendamentos</div>
<div class="mockup-body">
    <div style="margin-bottom:16px"><span class="btn-mock">+ Novo Agendamento</span></div>
    <table class="tbl-mock">
    <tr><th>Nome</th><th>Relatório</th><th>Recorrência</th><th>Destinatários</th><th>Próximo envio</th><th>Status</th><th></th></tr>
    <tr>
        <td>Velocidade semanal</td><td>Excesso de Velocidade <span class="pill-mock gray">XLSX</span></td>
        <td>Toda segunda às 07:00 (BRT)</td><td>gestor@empresa.com.br</td>
        <td class="mono">03/08 07:00</td><td><span class="pill-mock green">Ativo</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span></td>
    </tr>
    <tr>
        <td>Alarmes do dia</td><td>Alarmes <span class="pill-mock gray">PDF</span></td>
        <td>Todo dia às 06:00 (BRT)</td><td>operacao@empresa.com.br +1</td>
        <td class="mono">31/07 06:00</td>
        <td><span class="pill-mock gray">Inativo</span> <span class="pill-mock yellow">3/3 falhas</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Ativar</span></td>
    </tr>
    </table>
    <div style="font-size:12px;font-weight:600;color:var(--muted);margin:18px 0 8px">Histórico de execuções</div>
    <table class="tbl-mock">
    <tr><th>Quando</th><th>Agendamento</th><th>Período coberto</th><th>Registros</th><th>Situação</th></tr>
    <tr><td>28/07 07:00</td><td>Velocidade semanal</td><td>21/07 a 27/07</td><td class="mono">34</td><td><span class="pill-mock green">Enviado</span></td></tr>
    <tr><td>27/07 06:00</td><td>Alarmes do dia</td><td>26/07</td><td class="mono">0</td><td><span class="pill-mock gray">Vazio</span></td></tr>
    <tr><td>26/07 06:00</td><td>Alarmes do dia</td><td>25/07</td><td class="mono">18</td><td><span class="pill-mock red">Falhou</span></td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que preencher</th></tr>
<tr><td>Nome</td><td>Como o agendamento aparece na lista e no assunto do e-mail</td></tr>
<tr><td>Relatório e formato</td><td>Qual relatório enviar, em Excel, CSV ou PDF</td></tr>
<tr><td>Frequência</td><td><strong>Diária</strong>, <strong>semanal</strong> (com o dia da semana) ou <strong>mensal</strong> (com o dia do mês)</td></tr>
<tr><td>Hora do envio</td><td>Hora cheia, no <strong>horário de Brasília</strong></td></tr>
<tr><td>Destinatários</td><td>Até 3 endereços, separados por vírgula</td></tr>
<tr><td>Não enviar quando não houver registro</td><td>Marcado, o envio é pulado nos períodos sem nada a relatar (o histórico registra "Vazio"). Desmarcado, o e-mail sai mesmo assim, dizendo que não houve registro</td></tr>
<tr><td>Agendamento ativo</td><td>Desmarcado, o agendamento fica guardado mas não dispara</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Editar</td><td>Abre o formulário com os dados atuais; salvar recalcula o próximo envio</td></tr>
<tr><td>Desativar / Ativar</td><td>Suspende ou retoma os envios. Ativar recalcula o próximo envio e zera o contador de falhas</td></tr>
<tr><td>Excluir</td><td>Remove o agendamento e todo o seu histórico (pede confirmação)</td></tr>
<tr><td>Histórico de execuções</td><td>Mostra cada disparo: quando, período coberto, quantos registros e se foi enviado, ficou vazio ou falhou — com a mensagem de erro do provedor de e-mail quando falha</td></tr>
</table>

<div class="callout info">
<strong>O período é sempre o anterior fechado</strong>, nunca o que está em curso: <strong>ontem</strong> na frequência diária, <strong>a semana passada</strong> (segunda a domingo) na semanal e <strong>o mês passado</strong> na mensal. Um relatório do dia que ainda não terminou chegaria pela metade.
</div>

<div class="callout warn">
<strong>Relatório grande chega como link, não como anexo.</strong> Acima do tamanho máximo de anexo configurado no sistema, o e-mail traz um botão para baixar o arquivo em vez do arquivo em si — provedores de e-mail recusam anexos grandes, e um e-mail recusado é pior do que um link. <strong>Esse link vale por tempo limitado</strong>: por padrão o arquivo é apagado do servidor depois de 30 dias, e a partir daí a tela Exportar passa a mostrá-lo como <em>Expirado</em>. Guarde o arquivo se precisar dele por mais tempo — ou apenas gere o relatório de novo.
</div>

<div class="callout warn">
<strong>Três falhas seguidas desativam o agendamento.</strong> Se o e-mail não puder ser entregue três vezes consecutivas — endereço inexistente, servidor de e-mail fora do ar —, o agendamento é desativado e quem o criou recebe um aviso no sino. Corrija o destinatário e clique em <strong>Ativar</strong>: o contador zera. Um envio bem-sucedido no meio do caminho também zera a contagem, porque a regra é <em>três seguidas</em>.
</div>

<div class="callout tip">
<strong>Dia do mês vai até 28</strong> na frequência mensal, de propósito: 29, 30 e 31 não existem em todos os meses, e pular fevereiro nunca é o que se quis dizer.
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="cadastros">Cadastros</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<h3 id="ativos">Ativos</h3>
<p><strong>Objetivo:</strong> Gerenciar dispositivos (veículos/câmeras) da frota. Lista paginada com busca, edição inline (nome, modelo, número de câmeras) e remoção.</p>

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
<tr><td>+ Novo Ativo</td><td>Abre o formulário de cadastro: IMEI, nome, modelo e número de câmeras instaladas</td></tr>
<tr><td>Editar inline</td><td>Campos de nome, modelo e câmeras tornam-se editáveis na própria linha</td></tr>
<tr><td>Remover</td><td>O dispositivo sai das listas, mas o histórico dele é preservado</td></tr>
<tr><td>Clicar no IMEI</td><td>Abre a tela de detalhe do ativo, com 9 abas</td></tr>
<tr><td>Buscar</td><td>Filtra por nome ou IMEI (busca parcial)</td></tr>
<tr><td>Exportar</td><td>Baixa CSV, Excel ou PDF com todos os dispositivos ativos</td></tr>
</table>

<div class="callout tip">
<strong>Equipamento já conhecido:</strong> Se um equipamento novo já estava enviando localização antes de ser cadastrado, ao cadastrar o IMEI o sistema aproveita o que já foi recebido — nada do histórico se perde.
</div>

<h3 id="chips">Chips SIM</h3>
<p><strong>Objetivo:</strong> Gerenciar os chips SIM usados nos equipamentos. Cadastro com: operadora, número da linha, ICCID (código do chip), IMEI vinculado e status ativo/inativo.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar chip</td><td>Preencher operadora, número e/ou ICCID. Opcionalmente vincular a um IMEI</td></tr>
<tr><td>Editar</td><td>Alterar qualquer campo do chip</td></tr>
<tr><td>Remover</td><td>Exclui o registro definitivamente</td></tr>
<tr><td>Buscar</td><td>Filtra por qualquer campo textual</td></tr>
</table>

<h3 id="clientes">Clientes <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Administradores (revendedores) gerenciam os clientes da plataforma. Cada cliente tem sua própria frota isolada.</p>

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
<tr><td>Criar/Editar cliente</td><td>Formulário com: nome, documento, e-mail, telefone, endereço, perfil de ocorrências, FaceID, cor da marca e logo</td></tr>
<tr><td>Desativar</td><td>O cliente some das listas (o cliente principal do sistema não pode ser desativado)</td></tr>
<tr><td>Entrar como (impersonar)</td><td>Revendedor passa a ver o sistema como aquele cliente. A ação fica registrada para auditoria</td></tr>
<tr><td>Cor da marca</td><td>A cor escolhida é aplicada ao menu lateral do cliente</td></tr>
<tr><td>FaceID</td><td>Habilita a identificação facial de motoristas para o cliente</td></tr>
<tr><td><strong>Limite de velocidade padrão</strong></td><td>Vale para toda a frota do cliente no relatório de <a href="#rel-velocidade" style="color:inherit">Excesso de Velocidade</a>. Um equipamento com limite próprio ignora este valor; sem nenhum dos dois, o sistema usa 80 km/h</td></tr>
</table>

<h3 id="equipamentos">Equipamentos</h3>
<p><strong>Objetivo:</strong> Cadastro completo de equipamentos com periféricos (câmeras, sensores), configurações de vídeo (rotação, marca d'água), firmware, filial e chip SIM vinculado. Suporte a importação em lote via CSV.</p>

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
        <div class="form-mock-field"><label>Rotação do Vídeo</label><div class="input-mock">0°</div></div>
        <div class="form-mock-field"><label>Marca d'Água</label><div class="input-mock dim">Texto opcional...</div></div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Cadastrar equipamento</td><td>Cria novo device com modelo, IMEI, chip, periféricos, rotação, marca d'água e firmware</td></tr>
<tr><td>Importar CSV</td><td>Envie um arquivo CSV com as colunas imei, nome, modelo e nº de câmeras — os equipamentos são cadastrados em lote</td></tr>
<tr><td>Atualizar Firmware</td><td>Abre janela para enviar a atualização de software ao equipamento</td></tr>
<tr><td>Selecionar periféricos</td><td>Tags clicáveis (estilo chip) para adicionar/remover periféricos do dispositivo</td></tr>
<tr><td><strong>Limite de velocidade</strong></td><td>Limite em km/h só deste equipamento, usado no relatório de <a href="#rel-velocidade" style="color:inherit">Excesso de Velocidade</a>. Em branco, vale o limite padrão do cliente</td></tr>
</table>

<h3 id="geocercas">Geocercas</h3>
<p><strong>Objetivo:</strong> Desenhar áreas no mapa e ser avisado quando um veículo entra ou sai delas — pátio, base do cliente, região proibida, ponto de coleta.</p>

<div class="mockup">
<div class="mockup-header">Geocercas — Desenho no mapa</div>
<div class="mockup-body">
    <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div style="flex:1;min-width:220px">
            <div class="form-mock" style="display:flex;flex-direction:column;gap:10px">
                <div class="form-mock-field"><label>Nome</label><div class="input-mock">Pátio Central</div></div>
                <div class="form-mock-field"><label>Tipo</label><div class="input-mock">Geocerca</div></div>
                <div class="form-mock-field"><label>Formato</label>
                    <div style="display:flex;gap:10px;margin-top:4px;font-size:13px">
                        <span class="pill-mock blue">● Círculo</span><span class="pill-mock gray">○ Polígono</span>
                    </div>
                </div>
                <div class="form-mock-field"><label>Raio (metros)</label><div class="input-mock">200</div></div>
                <div class="form-mock-field"><label>Alertar em</label><div class="input-mock">Entrada e saída</div></div>
                <div class="form-mock-field"><label>E-mails de alerta (até 3)</label><div class="input-mock dim">operacao@empresa.com.br</div></div>
            </div>
        </div>
        <div style="flex:1;min-width:220px">
            <div class="map-mock" style="height:200px;background:url('/assets/img/wiki_map_streets.png') center/cover no-repeat">
                <svg style="position:absolute;inset:0;width:100%;height:100%" viewBox="0 0 100 100" preserveAspectRatio="none">
                    <circle cx="50" cy="50" r="26" fill="#0052ff" fill-opacity=".12" stroke="#0052ff" stroke-width="2" vector-effect="non-scaling-stroke"/>
                </svg>
                <div class="map-mock-dot" style="top:50%;left:50%"></div>
                <span class="map-credit">© OpenStreetMap</span>
            </div>
            <div style="margin-top:10px;font-size:12px;color:var(--muted)">Equipamentos vinculados: <strong style="color:var(--ink)">CAM-001, FJR7B59</strong></div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que preencher</th></tr>
<tr><td>Tipo</td><td><strong>Geocerca</strong> (área que gera eventos e alertas) ou <strong>Ponto de Interesse</strong> (referência no mapa)</td></tr>
<tr><td>Formato</td><td><strong>Círculo</strong> — clique no mapa define o centro e o campo Raio define o tamanho. <strong>Polígono</strong> — cada clique adiciona um vértice e o botão fecha a área (mínimo de 3 vértices)</td></tr>
<tr><td>Alertar em</td><td>Entrada e saída, somente entrada, somente saída ou não alertar. <strong>O evento é sempre registrado no relatório</strong>; esta opção decide apenas se o sistema avisa</td></tr>
<tr><td>E-mails de alerta</td><td>Até 3 endereços que recebem aviso das travessias desta cerca (além do sino e do pop-up)</td></tr>
<tr><td>Cor</td><td>Como a cerca aparece desenhada no mapa</td></tr>
<tr><td>Equipamentos</td><td>Quais veículos são avaliados contra esta cerca. <strong>Sem nenhum vinculado, a cerca não gera evento nenhum</strong></td></tr>
</table>

<div class="callout tip">
<strong>Desenhar uma cerca não gera entradas retroativas.</strong> Ao criar uma cerca sobre a garagem, os veículos que já estão lá dentro não disparam uma enxurrada de "entradas": o sistema apenas anota onde cada um estava. O primeiro evento só sai numa travessia de verdade. Editar a geometria de uma cerca existente reinicia essa contagem pelo mesmo motivo.
</div>

<div class="callout info">
<strong>Veículo parado na borda não gera dezenas de eventos.</strong> A fronteira funciona como uma faixa: para registrar a saída, o veículo precisa se afastar cerca de 50 metros da borda. Um caminhão estacionado exatamente em cima da linha produz um par de eventos, não trinta.
</div>

<h3 id="grupos-permissao">Grupos de Permissão <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Matriz de permissões de acesso. Cada grupo define quais telas (22) e ações (Ver, Criar, Editar, Excluir, Exportar) um usuário pode acessar. Mostra contagem de usuários vinculados a cada grupo.</p>

<div class="callout warn">
<strong>Tela nova nasce fechada.</strong> Quando o sistema ganha uma tela — Geocercas, Agendamentos, Config. Notificações e Servidor de E-mail são as mais recentes —, ela não vem marcada nos grupos já existentes. Quem está em um grupo restrito não a vê no menu e recebe "acesso negado" ao abrir o endereço direto, até que um administrador marque a linha correspondente aqui. Administradores não são afetados. Os relatórios são exceção: todos compartilham a mesma linha <strong>Relatórios</strong>, então quem já via Alarmes passa a ver os relatórios novos automaticamente.
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar grupo</td><td>Nome + matriz de marcações (18 telas x 5 ações)</td></tr>
<tr><td>Editar grupo</td><td>Alterar nome e checkboxes da matriz</td></tr>
<tr><td>Excluir grupo</td><td>Só permitido se não houver usuários vinculados ao grupo</td></tr>
<tr><td>Ver contagem de usuários</td><td>Cada grupo mostra quantos usuários estão vinculados a ele</td></tr>
</table>

<h3 id="motoristas">Motoristas</h3>
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
<tr><td>Remover</td><td>Exclui o registro definitivamente</td></tr>
<tr><td>Alertas de vencimento</td><td>CNH ou toxicológico vencidos aparecem em vermelho na tabela</td></tr>
</table>

<h3 id="config-ocorrencias">Configuração de Ocorrências <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Criar e gerenciar perfis de regras que controlam <strong>como o sistema transforma cada tipo de alarme em ocorrência</strong>. Cada perfil define, para cada tipo de alarme, se gera ocorrência, qual o nível de risco e a janela de agrupamento.</p>

<div class="callout info">
<strong>Regra de negócio:</strong> Quando um alarme chega do dispositivo, o sistema consulta o perfil de ocorrências do cliente para decidir: este alarme vira uma ocorrência? Com qual risco? Se já existe uma ocorrência similar nos últimos X minutos, agrupa ou cria nova?
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar perfil</td><td>Nome + flag "Padrão do Sistema" (um perfil padrão por vez)</td></tr>
<tr><td>Editar perfil</td><td>Alterar nome + rows dinâmicas de parâmetros por tipo de alarme</td></tr>
<tr><td>Configurar parâmetro</td><td>Para cada tipo de alarme: gera ocorrência? (Sim/Não), nível de risco (Baixo/Médio/Alto), janela de agrupamento (minutos)</td></tr>
<tr><td>Excluir perfil</td><td>Só permitido se nenhum cliente estiver usando o perfil</td></tr>
<tr><td>Vincular ao cliente</td><td>No cadastro do cliente, selecionar o perfil de ocorrências</td></tr>
</table>

<h3 id="config-notificacoes">Configuração de Notificações <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Definir o que gera aviso, por qual canal e para quem. Cada regra combina um tipo de alarme com os canais desejados — <a href="#notificacoes" style="color:inherit">sino, pop-up, som e e-mail</a>.</p>

<div class="mockup">
<div class="mockup-header">Config. Notificações — Regras</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Alarme</th><th>Escopo</th><th>Risco mínimo</th><th>Canais</th><th>Destinatários</th><th>Status</th></tr>
    <tr><td>Categoria: DMS</td><td>Frota Principal</td><td>Alto</td><td>Sino, Pop-up, Som, E-mail</td><td>seguranca@empresa.com.br</td><td><span class="pill-mock green">Ativa</span></td></tr>
    <tr><td>SOS / Pânico</td><td><span class="pill-mock gray">Global</span></td><td>Qualquer</td><td>Sino, Pop-up</td><td>—</td><td><span class="pill-mock green">Ativa</span></td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que preencher</th></tr>
<tr><td>Tipo de alarme</td><td>Um alarme específico ou uma <strong>categoria inteira</strong>. Escolher a categoria cobre todos os alarmes dela de uma vez — bem mais simples do que cadastrar um por um</td></tr>
<tr><td>Risco mínimo</td><td>Qualquer risco, "baixo ou maior", "médio ou maior" ou "somente alto". Filtra pelo risco que o perfil de ocorrências atribuiu ao alarme</td></tr>
<tr><td>Canais</td><td>Sino, pop-up em tempo real, som e e-mail — marque os que quiser combinar</td></tr>
<tr><td>Destinatários</td><td>Até 3 endereços, usados quando o canal E-mail está marcado</td></tr>
<tr><td>Regra ativa</td><td>Desmarcada, a regra fica guardada mas não vale</td></tr>
</table>

<div class="callout info">
<strong>A regra do cliente vence a regra global.</strong> Regras <em>globais</em> (escopo "Global", só administrador cria) valem para todos os clientes que não tenham regra própria para aquele alarme. Assim que um cliente cadastra a sua, é ela que passa a valer para ele — sem precisar apagar a global.
</div>

<div class="callout warn">
<strong>E-mail não funciona sem servidor cadastrado.</strong> Marcar o canal E-mail aqui não basta: é preciso cadastrar as credenciais em <a href="#config-smtp" style="color:inherit">Cadastros › Servidor de E-mail</a>. Sem isso, sino, pop-up e som continuam funcionando normalmente — só o e-mail não sai.
</div>

<h3 id="config-smtp">Servidor de E-mail <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Cadastrar as credenciais do servidor que envia os e-mails do sistema — alertas de notificação e relatórios agendados.</p>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que preencher</th></tr>
<tr><td>Servidor e porta</td><td>Endereço do provedor e a porta de envio</td></tr>
<tr><td>Segurança</td><td><strong>STARTTLS</strong> (normalmente porta 587), <strong>SSL/TLS implícito</strong> (porta 465) ou sem criptografia</td></tr>
<tr><td>Usuário e senha</td><td>Credenciais da conta de envio</td></tr>
<tr><td>E-mail e nome do remetente</td><td>Como a mensagem aparece na caixa de quem recebe. O nome vem preenchido como <strong>bycamera</strong>; troque-o se quiser que os e-mails saiam com o nome da sua empresa</td></tr>
<tr><td>Ativo</td><td>Desmarcado, a configuração é ignorada</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Salvar</td><td>Grava as credenciais. A senha é guardada cifrada e <strong>nunca é reexibida</strong> no formulário — deixar o campo em branco ao editar mantém a senha atual</td></tr>
<tr><td><strong>Enviar e-mail de teste</strong></td><td>Dispara uma mensagem para o endereço informado e mostra na tela o resultado. Falhando, exibe o erro que o provedor devolveu — é a forma mais rápida de descobrir porta errada, senha errada ou bloqueio do provedor</td></tr>
<tr><td>Excluir</td><td>Remove a configuração</td></tr>
</table>

<div class="callout info">
<strong>Escopo global ou por cliente.</strong> A configuração <em>global</em> atende todo o sistema. Um cliente pode ter a sua própria — útil quando ele quer que os e-mails saiam do próprio domínio. Vale, nesta ordem: a configuração do cliente, depois a global.
</div>

<div class="callout tip">
<strong>Se os e-mails caírem na caixa de spam</strong>, o ajuste não é no sistema: é preciso que o domínio do remetente autorize o servidor de envio (registros SPF/DKIM no DNS). Fale com quem administra o domínio.
</div>

<div class="callout info">
<strong>É este cadastro que decide o nome do remetente</strong> — inclusive nos relatórios agendados e nos alertas de notificação. Se os e-mails estiverem chegando com um nome antigo ou errado, é o campo <em>nome do remetente</em> desta tela que precisa ser corrigido, e a correção vale para os próximos envios.
</div>

<h3 id="usuarios">Usuários <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Gestão de usuários do sistema com duas abas: <strong>Minha Empresa</strong> (usuários internos) e <strong>Meus Clientes</strong> (usuários dos clientes). Campos: nome, e-mail, senha, função (admin/operador/visualizador), tipo (revendedor/cliente), cliente vinculado, grupo de permissão, foto.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar usuário</td><td>Nome, e-mail, senha (mín. 6 caracteres), função, tipo de usuário, cliente, grupo de permissão</td></tr>
<tr><td>Editar usuário</td><td>Alterar dados. Senha só é alterada se preenchida</td></tr>
<tr><td>Ativar/Desativar</td><td>Ativa ou desativa o acesso. Não é possível desativar o próprio usuário</td></tr>
<tr><td>Vincular grupo de permissão</td><td>Usuário herda as permissões do grupo selecionado</td></tr>
<tr><td>Foto do usuário</td><td>Link de uma imagem na internet para usar como foto de perfil</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════ -->
<h2 id="operacoes">Operações</h2>
<!-- ═══════════════════════════════════════════════════════════════ -->

<h3 id="comandos">Comandos</h3>
<p><strong>Objetivo:</strong> Enviar comandos remotos para os equipamentos. A tela oferece <strong>atalhos prontos</strong> para os comandos mais comuns (status, reiniciar, vídeo ao vivo, envio de gravações) e acompanha a resposta de cada envio.</p>

<div class="mockup">
<div class="mockup-header">Comandos — Envio e Monitoramento</div>
<div class="mockup-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div>
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Enviar Comando</div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <div class="filter-mock">Equipamento: CAM-001 (860112070347838)</div>
                <div class="filter-mock">Comando: Status do Dispositivo</div>
                <span class="btn-mock" style="align-self:flex-start">Enviar</span>
            </div>
        </div>
        <div>
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Histórico Recente</div>
            <table class="tbl-mock" style="font-size:12px">
            <tr><th>Comando</th><th>IMEI</th><th>Status</th><th>Resposta</th></tr>
            <tr><td>Status</td><td class="mono">860112...</td><td><span class="pill-mock green">Executado</span></td><td style="font-size:11px">Battery:12.4V; Mode:SLEEP</td></tr>
            <tr><td>Vídeo Ao Vivo</td><td class="mono">869058...</td><td><span class="pill-mock blue">Enviado</span></td><td style="font-size:11px;color:var(--muted)">Aguardando dispositivo...</td></tr>
            </table>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar equipamento + comando + Enviar</td><td>O comando é enviado e o sistema aguarda a resposta do equipamento</td></tr>
<tr><td>Equipamento online</td><td>Status muda para "Executado" e a resposta aparece em poucos segundos</td></tr>
<tr><td>Equipamento offline</td><td>Status fica "Enviado" — a resposta chega quando o equipamento voltar a se conectar</td></tr>
<tr><td>Acompanhamento</td><td>A tela verifica a resposta automaticamente por alguns minutos</td></tr>
<tr><td>Sem resposta</td><td>Se o equipamento não responder, a tela avisa que o comando ficou aguardando na fila</td></tr>
</table>

<div class="callout tip">
<strong>Atalhos disponíveis:</strong> Status, Informações do Dispositivo, Reiniciar, Vídeo Ao Vivo, Reprodução de Vídeo, Envio de Vídeo e Configuração.
</div>

<div class="callout info">
<strong>Comandos específicos de modelo.</strong> Alguns comandos só se aplicam a determinados modelos de câmera. A tela desabilita os equipamentos incompatíveis automaticamente — se um comando pedir parâmetros, os campos aparecem junto do atalho. Comandos universais (que funcionam em todos os modelos) não têm essa restrição.
</div>

<!-- ── Parâmetros ──────────────────────────────────── -->
<h3 id="parametros">Parâmetros <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Ler, comparar e alterar a configuração remota das câmeras JT/T (resolução de vídeo, sensibilidade de alarme, intervalos de envio, servidores, etc.) — tudo sem acesso físico ao equipamento. O menu <strong>Parâmetros</strong> fica logo abaixo de Comandos e reúne três funções que antes estavam espalhadas.</p>

<div class="callout warn">
<strong>Área de administrador.</strong> As três funções abaixo só aparecem no menu e só respondem para usuários com perfil de administrador. Operadores e visualizadores não veem o item no menu e recebem "acesso negado" ao tentar acessar diretamente.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Leitura de Parâmetros</h4>
<p>A tela principal do menu lista todos os equipamentos JT/T do cliente com o status de sincronização de cada um. Ao clicar em um equipamento, abre-se a aba de parâmetros com todos os valores lidos da câmera, organizados por categoria (vídeo, rede, alarme, etc.).</p>

<div class="mockup">
<div class="mockup-header">Parâmetros — Visão por Equipamento</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Equipamento</th><th>Modelo</th><th>Parâmetros lidos</th><th>Última leitura</th><th></th></tr>
    <tr><td>FJR7B59</td><td>JC371</td><td class="mono">49</td><td>14/08 08:30</td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Ver</span></td></tr>
    <tr><td>RQP2A41</td><td>JC182</td><td class="mono">47</td><td>14/08 08:31</td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Ver</span></td></tr>
    <tr><td>GHT5C08</td><td>JC181</td><td class="mono">6</td><td>13/08 22:10</td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Ver</span></td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Ver</td><td>Abre a lista de parâmetros lidos da câmera, organizados por categoria. Cada linha mostra o número do parâmetro, o nome, o valor atual e a última leitura</td></tr>
<tr><td>Solicitar Leitura</td><td>Manda o comando de leitura para a câmera. Se estiver online, os valores chegam em segundos; se estiver offline, a leitura é feita quando o equipamento voltar a se comunicar</td></tr>
<tr><td>Alterar Parâmetro</td><td>Abre formulário para modificar o valor de um parâmetro específico. O sistema grava o valor anterior antes de enviar o novo — se der errado, você sabe o que restaurar</td></tr>
</table>

<div class="callout warn">
<strong>Parâmetros de rede são bloqueados.</strong> Endereço do servidor principal e porta de comunicação não podem ser alterados pela tela — mudar esses valores desconectaria o equipamento do sistema. São mostrados para consulta, mas o botão de edição não aparece.
</div>

<div class="callout info">
<strong>Cada modelo tem parâmetros diferentes.</strong> Um JC371 pode ter 49 parâmetros lidos, um JC182 ter 47 e um JC181 ter 6 — e isso é normal: modelos diferentes suportam conjuntos de configuração diferentes. Não compare os números entre modelos.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Relatório de Parâmetros da Frota</h4>
<p>Responde à pergunta: <strong>quais câmeras estão configuradas fora do padrão?</strong> Para cada modelo, o sistema calcula o valor mais comum de cada parâmetro na frota e destaca as câmeras que divergem.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Gerar relatório</td><td>Mostra, agrupado por modelo, cada câmera que tem pelo menos um parâmetro diferente do que a maioria da frota usa</td></tr>
<tr><td>Ver divergências</td><td>Cada câmera fora do padrão lista os parâmetros que diferem, com o valor do equipamento e o valor que a maioria usa</td></tr>
</table>

<div class="callout info">
<strong>O padrão é a própria frota.</strong> O valor de referência é o mais comum entre os equipamentos do mesmo modelo — não um valor "ideal" cadastrado manualmente. Isso significa que o relatório já funciona sem nenhuma configuração prévia. Modelo com um único equipamento aparece separado, como "sem base de comparação".
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Perfis de Parâmetros</h4>
<p>Permite criar conjuntos nomeados de configuração por modelo de câmera e compará-los com os equipamentos da frota. Útil para padronizar a configuração de vários equipamentos.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar perfil</td><td>Nome + modelo. Os parâmetros do perfil começam com os valores mais comuns da frota e podem ser ajustados</td></tr>
<tr><td>Simular aplicação</td><td>Mostra quais equipamentos teriam parâmetros alterados e quais valores mudariam — <strong>sem enviar nada</strong></td></tr>
<tr><td>Editar perfil</td><td>Alterar os valores de qualquer parâmetro do perfil</td></tr>
<tr><td>Excluir perfil</td><td>Remove o perfil. Os equipamentos não são afetados</td></tr>
</table>

<div class="callout warn">
<strong>A aplicação é por equipamento.</strong> O perfil serve para definir o padrão desejado e <em>ver quem diverge</em>. A escrita em câmera real acontece na tela de leitura, equipamento a equipamento — de propósito: escrever configuração em operação pede conferência individual.
</div>

<div class="callout tip">
<strong>Só equipamentos JT/T aparecem aqui.</strong> Os comandos de parâmetro são do protocolo JT/T; câmeras JIMI não os entendem. Por isso a tela lista apenas os equipamentos cujo modelo é JT/T — mostrar os outros ofereceria uma ação que falharia sempre.
</div>

<!-- ── Firmware ─────────────────────────────────────── -->
<h3 id="firmwares">Firmware <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h3>
<p><strong>Objetivo:</strong> Saber qual versão de firmware cada câmera está rodando e atualizá-la à distância. São duas metades da mesma pergunta: <em>o que está instalado</em> e <em>para onde atualizar</em>.</p>

<div class="mockup">
<div class="mockup-header">Firmware — Frota</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Equipamento</th><th>Modelo</th><th>Firmware</th><th>Lido em</th><th>Referência</th><th>Situação</th></tr>
    <tr><td>400AD_3</td><td>JC400AD</td><td>V1.8.1.2_250904</td><td>19/08 14:02</td><td>V1.8.1.2_250904</td><td><span class="pill-mock green">Igual à de referência</span></td></tr>
    <tr><td>400AD_1</td><td>JC400AD</td><td>V1.8.0.9_250807</td><td>19/08 14:02</td><td>V1.8.1.2_250904</td><td><span class="pill-mock yellow">Diferente da de referência</span></td></tr>
    <tr><td>371_3241</td><td>JC371</td><td>—</td><td>nunca</td><td>—</td><td><span class="pill-mock gray">Firmware não lido</span></td></tr>
    </table>
</div>
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Ler a versão instalada</h4>
<p>O botão <strong>Ler versão</strong> pergunta ao equipamento qual firmware ele está rodando, e a resposta é gravada automaticamente. <strong>Ler versão de todos</strong> faz o mesmo para a lista inteira, um equipamento por vez.</p>

<div class="callout tip">
<strong>A resposta não é instantânea.</strong> O comando sai na hora; a versão aparece na tela quando o equipamento responder — recarregue depois de alguns segundos. Equipamento desligado recebe o comando quando voltar a se conectar, e a versão é gravada nesse momento.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">URLs de atualização</h4>
<p>A atualização funciona assim: o equipamento recebe um endereço, baixa o pacote de lá e se atualiza sozinho. <strong>O comando é o mesmo em todos os modelos de câmera — o que muda é o pacote.</strong> Por isso a URL é cadastrada com o modelo junto, e não digitada a cada envio.</p>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que é</th></tr>
<tr><td>Modelo</td><td>Para qual modelo de câmera este pacote serve</td></tr>
<tr><td>Versão</td><td>Exatamente como o equipamento a reporta (ex.: <code>V1.8.1.2_250904</code>) — é o que permite comparar</td></tr>
<tr><td>URL do pacote</td><td>Endereço de onde a câmera vai baixar o arquivo</td></tr>
<tr><td>Versão de referência</td><td>Marca a versão que a frota daquele modelo <em>deveria</em> estar rodando. Só uma por modelo</td></tr>
</table>

<div class="callout warn">
<strong>A URL errada não dá erro — a câmera aceita e aplica.</strong> Mandar para uma câmera o pacote de outro modelo não devolve mensagem de falha nenhuma: o equipamento baixa e instala, e o problema só aparece depois, na câmera que parou de funcionar. É exatamente por isso que a URL é cadastro com o modelo na chave, e por que a tela bloqueia enviar para dois modelos de uma vez.
</div>

<div class="callout warn">
<strong>A URL não pode ter vírgula nem <code>#</code>.</strong> São caracteres que o equipamento usa para separar as partes do comando: uma URL que os contenha chega partida ao meio, e a câmera tenta baixar um endereço incompleto. A tela recusa no cadastro.
</div>

<div class="callout tip">
<strong>Equipamento sem modelo cadastrado não pode ser atualizado.</strong> Sem saber o modelo não há como escolher o pacote certo. Cadastre o modelo em <strong>Cadastros → Equipamentos</strong> e o botão volta a funcionar.
</div>

<div class="callout">
<strong>Por que a tela nunca diz "desatualizado".</strong> Não existe uma regra publicada que diga qual de duas versões é mais nova — comparar <code>V1.8.0.9_250807</code> com <code>V4.3.2</code> seria chute. A tela informa apenas se a versão instalada é <strong>igual</strong> ou <strong>diferente</strong> da que você marcou como referência.
</div>
<h3 id="exportar">Exportar</h3>
<p><strong>Objetivo:</strong> Fila de geração de relatórios grandes. O pedido entra na fila, é processado em segundo plano e fica disponível para download quando concluído. Formatos: CSV, Excel e PDF.</p>

<div class="mockup">
<div class="mockup-header">Exportar — Fila de Jobs</div>
<div class="mockup-body">
    <div style="margin-bottom:16px">
        <span class="btn-mock">+ Novo Relatório</span>
    </div>
    <table class="tbl-mock">
    <tr><th>Relatório</th><th>Tipo</th><th>Formato</th><th>Status</th><th>Criado em</th><th></th></tr>
    <tr>
        <td>Alarmes Julho 2026</td><td>Alarmes</td><td>Excel</td><td><span class="pill-mock green">Concluído</span></td><td>18/07 14:00</td>
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
<tr><td>Novo Relatório</td><td>Formulário: nome, tipo de relatório, período e formato (CSV, Excel ou PDF)</td></tr>
<tr><td>Pedido criado</td><td>Entra na fila com status "pendente" e é processado em até 1 minuto</td></tr>
<tr><td>Baixar (status "concluído")</td><td>Download do arquivo gerado</td></tr>
<tr><td>Auto-atualização</td><td>O status dos relatórios se atualiza sozinho na tela</td></tr>
<tr><td>Tipos disponíveis</td><td>Alarmes, Ocorrências, Posições, Viagens, Equipamentos, Paradas, Ociosidade, Ignição, Excesso de Velocidade e Status da Frota</td></tr>
<tr><td>Agendados</td><td>A tela também resume os <a href="#agendamentos" style="color:inherit">agendamentos</a> ativos e quando cada um envia da próxima vez</td></tr>
</table>

<div class="callout warn">
<strong>"Expirado" no lugar do botão Baixar.</strong> Os arquivos gerados são apagados do servidor depois de 30 dias — cada um é uma cópia de dados da sua frota parada em disco, e guardá-los para sempre não é seguro nem necessário. Passado esse prazo, a linha continua na lista, mas com a marca <em>Expirado</em> em vez do botão. Para ter o arquivo de novo, basta pedir o relatório outra vez.
</div>

<div class="callout tip">
<strong>O endereço do arquivo é secreto, não protegido por login.</strong> Cada relatório recebe um nome longo e aleatório justamente para que o link enviado por e-mail funcione sem exigir que a pessoa entre no sistema. A contrapartida é que <strong>qualquer um com o link consegue baixar o arquivo</strong> — trate-o como você trataria o próprio relatório em anexo e evite encaminhá-lo para fora de quem deve vê-lo.
</div>

<h3 id="checklist">Checklist e Inspeção</h3>
<p><strong>Objetivo:</strong> Criar checklists de inspeção veicular (ex: checklist diário de pneus, freios, iluminação) e preenchê-los para veículos específicos. Cada checklist tem itens configuráveis: OK/Não OK, texto, número e foto.</p>

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
<tr><td>Criar checklist</td><td>Nome + vinculação ao cliente. Adicionar itens: pergunta, tipo de resposta (OK-Não OK/texto/número/foto) e se é obrigatório</td></tr>
<tr><td>Preencher inspeção</td><td>Selecionar checklist, dispositivo e motorista. Responder cada item. Salvar</td></tr>
<tr><td>Ver histórico</td><td>Lista de inspeções anteriores para o dispositivo</td></tr>
</table>

<p style="text-align:center;margin-top:48px;font-size:12px;color:var(--muted);padding-bottom:40px">
bycamera — Central de Ajuda — Última atualização: 14/08/2026
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
