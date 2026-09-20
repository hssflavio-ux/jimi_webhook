<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Histórico de posições de um ativo em um período. Mostra uma tabela paginada com placa, motorista, data/hora, endereço, hodômetro, velocidade e ignição, e um link para ver cada posição no mapa. Só entram transmissões que <strong>têm coordenada</strong> de GPS. Pode ser exportado em Excel ou PDF.</p>

<div class="mockup">
<div class="mockup-header">Relatório de Posições</div>
<div class="mockup-body">
    <div class="filter-bar-mock">
        <div class="filter-mock">Placa: CAM-001</div>
        <div class="filter-mock dim">dd/mm/aaaa - dd/mm/aaaa</div>
        <div class="filter-mock dim">08:00 - 10:00</div>
        <div class="filter-mock">Em cada dia do período</div>
        <span class="btn-mock">Gerar</span>
        <span class="btn-mock outline">Exportar</span>
        <span class="btn-mock outline">← Voltar</span>
    </div>
    <table class="tbl-mock">
    <tr><th>Placa</th><th>Motorista</th><th>Data/Hora ▲</th><th>Endereço</th><th>Mapa</th><th>Velocidade</th><th>Hodômetro</th><th>Ignição</th></tr>
    <tr><td class="mono">CAM-001</td><td>Carlos Souza</td><td>18/07/2026 08:00:10</td><td>Av. Rangel Pestana, 300 — São Paulo</td><td>Ver Mapa</td><td class="mono">38 km/h</td><td class="mono">12.408,3 km</td><td>Ligada</td></tr>
    <tr><td class="mono">CAM-001</td><td>Carlos Souza</td><td>18/07/2026 08:05:22</td><td>Av. Rangel Pestana, 812 — São Paulo</td><td>Ver Mapa</td><td class="mono">42 km/h</td><td class="mono">12.409,1 km</td><td>Ligada</td></tr>
    <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px">Página 12 de 14 (700 posições) — « 1 … 10 11 <strong>12</strong> 13 14 »</td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar placa + período + Gerar</td><td>A tabela carrega com os dados do período, do mais antigo para o mais recente. Administradores e revendedores escolhem antes o <strong>Cliente</strong>, e a lista de placas acompanha essa escolha</td></tr>
<tr><td><strong>Faixa horária</strong> (opcional)</td><td>Além das datas, você pode informar hora inicial e final. Deixando em branco, vale o dia inteiro (00:00 às 23:59)</td></tr>
<tr><td>Intervalo</td><td>"Todas as posições" ou "Amostrado (1:10)", que traz 1 a cada 10 posições — útil para períodos longos</td></tr>
<tr><td>Ver Mapa (na linha)</td><td>Abre o mapa em uma nova aba, no local exato daquela posição. Esta tela <strong>não</strong> desenha o trajeto do período inteiro; para ver o caminho percorrido, use <a href="#rel-deslocamento" style="color:inherit">Deslocamento</a> (Ver rota e Replay)</td></tr>
<tr><td>Hodômetro e Km rodado</td><td>A coluna <strong>Hodômetro</strong> mostra a leitura do contador do equipamento em cada posição, e a última linha da tabela, <strong>Km rodado no período</strong>, soma o que ele andou em <strong>todo o resultado filtrado</strong>, não só na página. Equipamento que não envia leitura de hodômetro mostra "—" (a distância não é estimada pelo GPS)</td></tr>
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
