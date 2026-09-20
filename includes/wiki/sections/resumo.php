<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Visão executiva 360° da frota. É a tela inicial após o login. Mostra indicadores em tempo real, mapa de calor das posições recentes, velocidade da frota, dispositivos desatualizados, ociosidade, status por modelo e gráficos de alarmes e ocorrências. <strong>Os quatro indicadores do topo e a Ociosidade</strong> se atualizam sozinhos, a cada 30 segundos; o restante da tela muda quando você a recarrega.</p>

<div class="mockup">
<div class="mockup-header">Resumo — Visão 360°</div>
<div class="mockup-body">
    <!-- KPIs -->
    <div class="kpi-row">
        <div class="kpi-box blue"><div class="kpi-label">Equipamentos (ativos)</div><div class="kpi-val">38/42</div></div>
        <div class="kpi-box green"><div class="kpi-label">Conectividade</div><div class="kpi-val">On 36 / Off 2</div></div>
        <div class="kpi-box yellow"><div class="kpi-label">Ocorrências</div><div class="kpi-val">87 (5 aguardando)</div></div>
        <div class="kpi-box red"><div class="kpi-label">Desatualizados</div><div class="kpi-val">3</div></div>
    </div>
    <!-- Heatmap + Velocidade -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
        <div class="map-mock" style="height:180px;background:url('/assets/img/wiki_map_city.png') center/cover no-repeat">
            <div style="position:absolute;inset:0;background:radial-gradient(circle at 32% 46%, rgba(230,80,30,.5), rgba(240,160,32,.25) 12%, transparent 24%),radial-gradient(circle at 60% 36%, rgba(230,80,30,.4), rgba(240,160,32,.2) 10%, transparent 20%),radial-gradient(circle at 72% 64%, rgba(240,160,32,.35), transparent 16%),radial-gradient(circle at 45% 68%, rgba(240,160,32,.3), transparent 14%)"></div>
            <span class="map-credit">© OpenStreetMap</span>
        </div>
        <div class="kpi-box" style="background:#fafbfc">
            <div class="kpi-label">Velocidade da Frota</div>
            <div style="font-size:12px;color:var(--body);margin-top:6px;line-height:1.7">Parados 6<br>Até 20 km/h 4<br>Até 60 km/h 10<br>Acima de 60 km/h 4</div>
        </div>
    </div>
    <!-- Chart -->
    <div style="margin-top:12px">
        <div style="font-size:12px;font-weight:600;color:var(--ink);margin-bottom:8px">Alarmes — Hoje</div>
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
<tr><td>Indicadores (4 cartões)</td><td><strong>Equipamentos</strong>: ativos sobre o total cadastrado. <strong>Conectividade</strong>: quantos equipamentos ativos estão On (comunicaram nos últimos 5 minutos) e quantos Off. <strong>Ocorrências</strong>: total e quantas aguardam tratativa. <strong>Desatualizados</strong>: quantos estão fora da tolerância de comunicação e, abaixo, quantos deles estão com a ignição ligada</td><td>Automática (30s)</td></tr>
<tr><td>Mapa de Posições Recentes</td><td>Posições dos veículos nas <strong>últimas 2 horas</strong>, com camada de calor e um ponto por posição — clique no ponto para ver a placa e a velocidade</td><td>Ao carregar a tela</td></tr>
<tr><td>Velocidade da Frota</td><td>Quantos equipamentos com ignição ligada, nos últimos 30 minutos, estão parados, até 20 km/h, até 60 km/h e acima de 60 km/h. Um equipamento que mudou de faixa nesse período (por exemplo, andava e depois parou) pode aparecer em mais de uma faixa, então a soma das faixas pode passar do número de equipamentos</td><td>Ao carregar a tela</td></tr>
<tr><td>Desatualizados</td><td>Barra que separa os equipamentos desatualizados por ignição ligada (mais urgente) e desligada. O critério é o mesmo do relatório <a href="#rel-desatualizados" style="color:inherit">Desatualizados</a>: último sinal recebido além de 5 minutos com a ignição ligada ou 30 minutos com ela desligada</td><td>Ao carregar a tela</td></tr>
<tr><td>Ociosidade</td><td>Veículos com ignição ligada e parados nos últimos 30 minutos</td><td>Automática (30s)</td></tr>
<tr><td>Status de Equipamentos por Modelo</td><td>Para cada modelo (até 6), quantos equipamentos ativos estão On e Off e a porcentagem online</td><td>Ao carregar a tela</td></tr>
<tr><td>Alarmes e Ocorrências (gráficos)</td><td>Volume de alarmes e de ocorrências no período escolhido nos botões <strong>Hoje</strong> (hora a hora), <strong>Últimos 7 dias</strong> e <strong>Último mês</strong> (dia a dia). Eventos técnicos do equipamento (diagnóstico) não entram na contagem de alarmes</td><td>Ao carregar a tela</td></tr>
<tr><td>Top 3 placas e Top 3 motoristas</td><td>Os três veículos com mais alarmes e os três motoristas com mais ocorrências no mesmo período. O ranking de motoristas só aparece com o <strong>FaceID</strong> habilitado para o cliente (em <a href="#clientes" style="color:inherit">Clientes</a>); sem ele a tela avisa como habilitar</td><td>Ao carregar a tela</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:20px 0 8px">Visão por Clientes <span class="badge">revendedor</span></h4>
<p>Só quem tem o perfil <strong>revendedor</strong> vê o bloco <strong>Visão por Clientes</strong>, logo abaixo de Ociosidade e Status por Modelo, com o selo "Perfil revendedor". São <strong>três rankings</strong> de três clientes cada, com a posição, o nome do cliente e a contagem:</p>
<ul style="font-size:13px;line-height:1.8;color:var(--body)">
    <li><strong>Top 3 por equipamentos ativos</strong></li>
    <li><strong>Top 3 por ocorrências</strong> — total de ocorrências de cada cliente; não muda com os botões de período</li>
    <li><strong>Top 3 por desatualizados</strong> — mesmo critério do indicador Desatualizados</li>
</ul>
<p>Para olhar a operação de um desses clientes por dentro, use o seletor de cliente do menu lateral. O administrador com perfil revendedor tem ainda o botão <strong>Entrar como</strong> — veja <em>Trocar Cliente</em> em <a href="#primeiros-passos" style="color:inherit">Primeiros Passos</a>.</p>

<div class="callout info">
<strong>Números recentes:</strong> os indicadores do topo normalmente refletem os últimos minutos de operação e se atualizam sozinhos enquanto a tela está aberta.
</div>
