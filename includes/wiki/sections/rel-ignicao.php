<?php defined('WIKI_SECTION') || exit; ?>
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
