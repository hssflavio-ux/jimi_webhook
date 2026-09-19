<?php defined('WIKI_SECTION') || exit; ?>
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
