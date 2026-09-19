<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Montar a sua própria tela de indicadores. O Painel reúne <strong>blocos</strong> (chamados de <em>widgets</em>) com os números e gráficos da frota, e <strong>você decide quais aparecem e em que ordem</strong>. A escolha vale só para o seu usuário: ninguém mais tem a tela alterada por causa dela.</p>

<div class="mockup">
<div class="mockup-header">Painel — Hoje</div>
<div class="mockup-body">
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:10px;font-size:12px">
        <span class="pill-mock blue">Hoje</span><span class="pill-mock gray">7 dias</span><span class="pill-mock gray">Mês</span>
        <span class="btn-mock outline">Editar painel</span>
    </div>
    <div class="kpi-row">
        <div class="kpi-box blue"><div class="kpi-label">Equipamentos</div><div class="kpi-val">12/15</div></div>
        <div class="kpi-box green"><div class="kpi-label">Conectividade</div><div class="kpi-val">On 9 / Off 3</div></div>
        <div class="kpi-box yellow"><div class="kpi-label">Ocorrências</div><div class="kpi-val">34 (5 aguardando)</div></div>
        <div class="kpi-box red"><div class="kpi-label">Desatualizados</div><div class="kpi-val">2</div></div>
    </div>
    <div style="margin-top:12px">
        <div style="font-size:12px;font-weight:600;color:var(--ink);margin-bottom:8px">Alarmes — Hoje, por hora (GMT-3)</div>
        <div class="chart-mock">
            <div class="chart-bar blue" style="height:30%"></div><div class="chart-bar blue" style="height:55%"></div><div class="chart-bar blue" style="height:80%"></div>
            <div class="chart-bar blue" style="height:45%"></div><div class="chart-bar blue" style="height:60%"></div><div class="chart-bar blue" style="height:25%"></div>
        </div>
    </div>
</div>
</div>

<div class="callout info">
<strong>O Painel convive com o Resumo — um não substitui o outro.</strong> O <a href="#resumo">Resumo</a> continua com os mesmos indicadores fixos de sempre, iguais para todo mundo, e segue sendo a tela que abre logo depois do login; ele apenas não tem mais item no menu. O Painel é a versão em blocos que você organiza, e é o primeiro item do menu.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Organizar o seu painel</h4>
<p>Clique em <strong>Editar painel</strong>. Abre a lista <em>Widgets do painel</em>, com todos os blocos disponíveis para o seu perfil:</p>

<div class="mockup">
<div class="mockup-header">Painel — Editar painel</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th style="width:40px"></th><th>Widget</th><th style="width:80px">Ordem</th></tr>
    <tr><td>☑</td><td>Equipamentos</td><td>↑ ↓</td></tr>
    <tr><td>☑</td><td>Conectividade</td><td>↑ ↓</td></tr>
    <tr><td>☑</td><td>Alarmes (série temporal)</td><td>↑ ↓</td></tr>
    <tr><td>☐</td><td>Top placas com mais alarmes</td><td>↑ ↓</td></tr>
    </table>
    <div style="margin-top:10px;display:flex;justify-content:flex-end"><span class="btn-mock">Salvar layout</span></div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Marcar / desmarcar a caixa</td><td>Mostra ou oculta o bloco no painel</td></tr>
<tr><td>Setas ↑ e ↓</td><td>Sobem ou descem o bloco na lista. Não há arrastar e soltar. Os blocos aparecem na tela na ordem em que estão marcados na lista</td></tr>
<tr><td>Salvar layout</td><td>Grava a sua escolha e mostra "Layout salvo." Você continua na tela de edição; para sair dela, use <strong>Concluir edição</strong></td></tr>
<tr><td>Concluir edição</td><td>Volta ao painel com o layout que estava salvo. Alterações que você não salvou são descartadas</td></tr>
</table>

<div class="callout tip">
<strong>Quem nunca editou vê o layout padrão do sistema, que de fábrica é este:</strong> Equipamentos, Conectividade, Ocorrências, Desatualizados, Mapa de Posições Recentes, Velocidade da Frota, Status de Equipamentos por Modelo e os dois gráficos de série temporal (Alarmes e Ocorrências). Ociosidade, Top placas e Top motoristas ficam <em>fora</em> do padrão — basta marcá-los. Não existe um botão "restaurar padrão": para voltar ao padrão, marque de novo esses nove blocos.
</div>

<div class="callout warn">
<strong>Desmarcar tudo e salvar deixa o painel vazio.</strong> A tela passa a mostrar "Nenhum widget selecionado" até você marcar algum bloco de novo em <strong>Editar painel</strong>.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">O que cada bloco mostra</h4>

<table class="tbl-mock">
<tr><th>Bloco</th><th>O que mostra</th></tr>
<tr><td>Equipamentos</td><td>Quantos equipamentos estão ativos em relação ao total cadastrado (ex.: <span class="mono">12/15</span>)</td></tr>
<tr><td>Conectividade</td><td><strong>On</strong> (comunicaram nos últimos 5 minutos) e <strong>Off</strong> (o restante dos equipamentos ativos)</td></tr>
<tr><td>Ocorrências</td><td>Total de ocorrências e quantas estão aguardando tratativa</td></tr>
<tr><td>Desatualizados</td><td>Quantos equipamentos estão desatualizados e, abaixo, quantos deles estão com a ignição ligada. O critério é o mesmo do relatório <a href="#rel-desatualizados">Desatualizados</a></td></tr>
<tr><td>Mapa de Posições Recentes</td><td>Mapa com as posições recebidas nas últimas 2 horas (até 500 pontos), com camada de calor. Clicar num ponto mostra o veículo e a velocidade</td></tr>
<tr><td>Velocidade da Frota</td><td>Barra com a proporção de veículos com a ignição ligada nos últimos 30 minutos, separados em <strong>parados</strong>, <strong>até 20 km/h</strong>, <strong>até 60 km/h</strong> e <strong>acima de 60 km/h</strong>, com a contagem de cada faixa</td></tr>
<tr><td>Ociosidade</td><td>Quantos veículos estão com a ignição ligada e parados, nos últimos 30 minutos</td></tr>
<tr><td>Status de Equipamentos por Modelo</td><td>Para cada modelo (até 6, do mais numeroso para o menos): quantos estão On, quantos estão Off e a porcentagem On</td></tr>
<tr><td>Alarmes (série temporal)</td><td>Total de alarmes do período e um gráfico de barras, por hora (Hoje) ou por dia (7 dias e Mês). Eventos de diagnóstico do equipamento não entram na conta</td></tr>
<tr><td>Ocorrências (série temporal)</td><td>O mesmo gráfico, para as ocorrências abertas no período</td></tr>
<tr><td>Top placas com mais alarmes</td><td>Os 3 veículos com mais alarmes no período</td></tr>
<tr><td>Top motoristas com mais alarmes</td><td>Os 3 motoristas com mais ocorrências atribuídas no período. <strong>Só funciona se o FaceID estiver habilitado para o cliente</strong> (em <a href="#clientes">Clientes</a>); sem ele o bloco explica isso em vez de mostrar a lista</td></tr>
<tr><td>Visão por Clientes (revendedor)</td><td>Só aparece para o perfil <strong>revendedor</strong>. Três rankings dos 3 primeiros clientes sob a sua gestão: por equipamentos ativos, por ocorrências no período e por equipamentos desatualizados</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Período: Hoje, 7 dias e Mês</h4>
<p>Os botões no alto mudam a janela dos gráficos e rankings: <strong>Hoje</strong> (por hora, horário de Brasília), <strong>7 dias</strong> e <strong>Mês</strong> (os últimos 30 dias, por dia). Os blocos de situação atual — Equipamentos, Conectividade, Ocorrências, Desatualizados, Ociosidade, Velocidade, Status por Modelo e o Mapa — <strong>não mudam com o período</strong>. Os botões de período ficam ocultos enquanto você está em <strong>Editar painel</strong>.</p>

<div class="callout info">
<strong>Os números são do cliente selecionado</strong> no seletor de cliente do menu lateral, como no restante do sistema. O Painel <strong>não se atualiza sozinho</strong>: recarregue a página para ver os dados mais novos. Qualquer usuário que enxerga o Painel pode organizá-lo; não existe permissão separada para editar o layout.
</div>
