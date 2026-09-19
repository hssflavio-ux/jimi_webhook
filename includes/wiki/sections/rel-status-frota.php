<?php defined('WIKI_SECTION') || exit; ?>
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
