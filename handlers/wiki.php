<?php
/**
 * JIMI Webhook System — Wiki / Central de Ajuda v4.22.0
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
 * Atualizada na v4.13.16:
 * - Player de vídeo do evento (detalhe de Ocorrência e coluna Vídeo de
 *   Alarmes) passou a mostrar os DOIS vídeos — câmera 1 e câmera 2 — lado a
 *   lado, tocando ao mesmo tempo, quando o equipamento tem duas lentes.
 *
 * Atualizada na v4.22.0 — a wiki passa a ser SENSÍVEL AO PERFIL:
 * - O conteúdo saiu daqui: cada seção é um parcial em includes/wiki/sections/,
 *   descrita por includes/wiki_registry.php (tela, admin_only, ações, resumo).
 * - Seção que o usuário não pode abrir continua no índice, com cadeado e o
 *   motivo, mas o texto dela NÃO é enviado ao navegador. Seção liberada ganha
 *   a faixa "Seu acesso" (o que ele pode e o que não pode fazer nela).
 * - O rodapé mostra a versão do sistema em vez de uma data escrita à mão.
 * - Tela nova entra em TRÊS lugares: $screenByHandler, $screens e o registro
 *   (tests/helpers/wiki_registry.test.php trava).
 *

 * Duas regras de negócio que o usuário PRECISA entender e que só existem aqui:
 * o sistema notifica por OCORRÊNCIA e não por alarme (12 alarmes em rajada =
 * 1 aviso, e isso é o desenho funcionando), e o link do relatório grande é
 * secreto mas não exige login — ambas com callout próprio.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wiki_registry.php';
require_once __DIR__ . '/../includes/wiki_access.php';
require_once __DIR__ . '/../includes/wiki_render.php';
require_login();

$user     = get_jimi_user() ?: [];
$registry = wiki_registry();
$access   = wiki_compute_access($registry, (string)($user['role'] ?? ''), 'can');

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
    border-radius: var(--radius-lg);
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
    border-radius: var(--radius-lg);
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
    border-radius: var(--radius-lg);
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
    border-radius: var(--radius-lg);
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
    border-radius: var(--radius-lg);
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
/* .callout agora é global — ver web/layout_base.php */
/* ── Sidebar Mockup (compact) ──────────────────────── */
.sidebar-mock {
    background: var(--surface-dark);
    color: #fff;
    padding: 16px 12px;
    border-radius: var(--radius-lg);
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
    border-radius: var(--radius-lg);
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

/* ── Acesso por perfil (v4.22.0) ─────────────────── */
.wiki-toc a.locked { color: var(--muted-soft); }
.wiki-toc a.locked:hover, .wiki-toc a.locked.active { color: var(--muted); background: var(--canvas-soft); }
.wiki-toc a .wiki-lock-ico { margin-left: 4px; vertical-align: -1px; }
.wiki-lock-ico { vertical-align: -1px; margin-right: 4px; }
.wiki-lock {
    display: inline-flex; align-items: center; margin-left: 8px; padding: 2px 8px;
    border-radius: 100px; background: var(--surface-strong); color: var(--muted);
    font-size: 11px; font-weight: 600; vertical-align: middle;
}
.wiki-locked {
    border: 1px dashed var(--hairline-strong); border-radius: 12px;
    background: var(--canvas-soft); padding: 14px 16px;
}
.wiki-locked p { margin: 0 0 6px; color: var(--body); }
.wiki-locked .wiki-locked-why { margin: 0; font-size: 13px; color: var(--muted); }
.wiki-access {
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px;
    margin: 0 0 14px; padding: 8px 12px; font-size: 12px; color: var(--body);
    border: 1px solid var(--hairline-soft); border-radius: 8px; background: var(--canvas-soft);
}
.wiki-access-label { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--ink); }
.wiki-access-no { color: var(--muted); }
.wiki-access-extra { color: var(--warning-text-strong); }
</style>
HEAD;

require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="wiki-wrap">
    <!-- ── TOC Sidebar (gerado do registro) ─────────── -->
    <nav class="wiki-toc" id="wikiToc">
        <h4>Central de Ajuda</h4>
<?= wiki_render_toc($registry, $access) ?>
    </nav>

    <!-- ── Content ──────────────────────────────────── -->
    <div class="wiki-content" id="wikiContent">
<?= wiki_render_body($registry, $access) ?>

<p style="text-align:center;margin-top:48px;font-size:12px;color:var(--muted);padding-bottom:40px">
bycamera — Central de Ajuda — versão <?= htmlspecialchars(getenv('SYSTEM_VERSION') ?: '4.0', ENT_QUOTES, 'UTF-8') ?>
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
