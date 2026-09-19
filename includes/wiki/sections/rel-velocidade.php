<?php defined('WIKI_SECTION') || exit; ?>
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
