<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Saber quando cada veículo entrou e saiu das áreas desenhadas em <em>Cadastros › Geocercas</em>, e quanto tempo ficou dentro de cada uma.</p>

<p>O relatório tem <strong>duas modalidades</strong>, escolhidas no primeiro campo do filtro:</p>

<table class="tbl-mock">
<tr><th>Modalidade</th><th>O que mostra</th></tr>
<tr><td><strong>Entradas e saídas</strong></td><td>Uma linha por travessia: data/hora, geocerca, equipamento, se foi entrada ou saída, a velocidade no instante da travessia e o local no mapa.</td></tr>
<tr><td><strong>Permanência</strong></td><td>Uma linha por visita: geocerca, equipamento, hora de entrada, hora de saída e quanto tempo ficou dentro. Quem entrou e ainda não saiu aparece como <strong>"em permanência"</strong>.</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Escolher o cliente</td><td>Administradores e revendedores têm o seletor <strong>Cliente</strong>; a lista de geocercas e de placas acompanha o cliente escolhido</td></tr>
<tr><td>Filtrar por geocerca</td><td>Restringe a uma cerca específica ou mostra todas</td></tr>
<tr><td>Filtrar por tipo</td><td>Só entradas, só saídas ou ambas (na modalidade Entradas e saídas)</td></tr>
<tr><td>Ver Mapa</td><td>Abre em nova aba o ponto exato onde a travessia aconteceu</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF com a modalidade e os filtros da tela</td></tr>
</table>

<div class="callout info">
<strong>A velocidade da travessia é a primeira pergunta de quem audita uma saída não autorizada</strong> — por isso ela aparece na própria linha do evento, sem precisar abrir o mapa.
</div>
