<?php defined('WIKI_SECTION') || exit; ?>
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
