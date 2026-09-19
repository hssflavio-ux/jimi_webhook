<?php defined('WIKI_SECTION') || exit; ?>
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
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:10px 16px;background:#fafbfc;border-radius:var(--radius-lg)">
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
    <li><strong>Player de vídeo duplo</strong> — quando a câmera tem duas lentes, os vídeos da
        <strong>câmera 1</strong> e da <strong>câmera 2</strong> aparecem lado a lado e tocam ao
        mesmo tempo, cada um aberto num quadro do <em>meio</em> do vídeo (o snapshot). O play roda
        na própria tela — baixar é opcional. Câmera de lente única mostra só um player.
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
