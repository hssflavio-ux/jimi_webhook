<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Histórico dos deslocamentos do veículo, com duração, velocidade máxima, distância percorrida e alarmes ocorridos no trajeto. Os deslocamentos são montados automaticamente pelo sistema alguns minutos depois de terminarem.</p>

<p>O relatório tem <strong>duas modalidades</strong>, escolhidas no primeiro campo do filtro:</p>

<table class="tbl-mock">
<tr><th>Modalidade</th><th>O que mostra</th></tr>
<tr><td><strong>Por deslocamento</strong></td><td>Uma linha por trajeto: placa, motorista, início, local de partida, término, local de chegada, duração, horímetro, velocidade máxima, distância, hodômetro e alarmes.</td></tr>
<tr><td><strong>Fechamento diário</strong></td><td>Uma linha por dia e por veículo: primeira ignição ligada, última desligada, <strong>jornada</strong> (do começo ao fim do dia, com as paradas), <strong>horímetro</strong>, <strong>tempo em movimento</strong> (só rodando), distância, hodômetro, velocidade máxima, alarmes e quantidade de deslocamentos do dia.</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar por ativo + período + Gerar</td><td>Tabela carrega na modalidade escolhida, do mais antigo para o mais recente</td></tr>
<tr><td>Faixa horária (opcional)</td><td>Restringe a consulta a um intervalo de horas dentro do período</td></tr>
<tr><td>Ordenar por coluna</td><td>Setinha no cabeçalho de Início, Término, Velocidade Máxima e Distância (ou Dia, no fechamento diário)</td></tr>
<tr><td><strong>Ver rota</strong></td><td>Abre em nova aba o trajeto desenhado no mapa: balão verde na partida, vermelho na chegada, um ponto por posição enviada pela câmera e <strong>as ocorrências em laranja</strong>, com tipo, horário e risco no balão. No fechamento diário, mostra o dia inteiro.</td></tr>
<tr><td><strong>Replay</strong> (só na modalidade Por deslocamento)</td><td>Reproduz o trajeto no tempo: um marcador percorre o mapa com <strong>Play</strong> e <strong>Reiniciar</strong>, velocidade de 0.5×, 1×, 2× ou 4×, e uma linha do tempo em que você clica para saltar para um instante. Mostra a hora, a velocidade e a distância percorrida no ponto em que o marcador está</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF com os dados da consulta</td></tr>
</table>

<h3>Distância, hodômetro e horímetro</h3>

<table class="tbl-mock">
<tr><th>Coluna</th><th>O que é</th></tr>
<tr><td><strong>Distância</strong></td><td>O que o <strong>hodômetro do equipamento</strong> andou no trecho: leitura no fim menos leitura no começo do deslocamento (ou do dia). A distância <strong>não é calculada pelo GPS</strong></td></tr>
<tr><td><strong>Hodômetro</strong></td><td>A leitura do contador do equipamento no <strong>fim</strong> do trecho, em km</td></tr>
<tr><td><strong>Horímetro</strong></td><td>Tempo com a <strong>ignição ligada</strong> no trecho, somando o tempo rodando e o tempo parado com a ignição ligada. É calculado pela bycamera a partir dos estados do veículo, não é lido do equipamento</td></tr>
</table>

<p>Na última linha da tabela, <strong>Total do período</strong> soma horímetro, alarmes e distância de <strong>todo o período filtrado</strong> — não só da página que está na tela. No fechamento diário soma também o tempo em movimento e a quantidade de deslocamentos. O hodômetro não tem total, porque somar leituras de um contador de dias diferentes não faz sentido.</p>

<div class="callout warn">
<strong>Equipamento sem leitura de hodômetro mostra "—" em Distância e Hodômetro.</strong> A tela <strong>não</strong> estima a distância pelo GPS no lugar: se o equipamento não enviou leitura de hodômetro válida naquele trecho, a célula fica com "—", e o total do período também, quando nenhum trecho tem leitura. Uma leitura zerada é tratada como "sem leitura". O mesmo vale para a distância exibida no mapa da rota e no Replay. Na tabela, quando o equipamento mandou só uma leitura válida no trecho, a distância aparece como 0,0 km.
</div>

<div class="callout info">
<strong>Como o sistema separa um deslocamento do outro:</strong> o trajeto termina quando a ignição desliga, quando o veículo fica parado por mais de 5 minutos, ou quando o equipamento passa esse mesmo tempo sem comunicar. É por isso que uma jornada com várias paradas aparece como vários deslocamentos, e não como um só — mesmo que o motorista não tenha desligado a ignição em nenhum momento.
</div>
