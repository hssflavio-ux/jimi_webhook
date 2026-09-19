<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Histórico dos deslocamentos do veículo, com duração, velocidade máxima, distância percorrida e alarmes ocorridos no trajeto. Os deslocamentos são montados automaticamente pelo sistema alguns minutos depois de terminarem.</p>

<p>O relatório tem <strong>duas modalidades</strong>, escolhidas no primeiro campo do filtro:</p>

<table class="tbl-mock">
<tr><th>Modalidade</th><th>O que mostra</th></tr>
<tr><td><strong>Por deslocamento</strong></td><td>Uma linha por trajeto: início, local de partida, término, local de chegada, duração, velocidade máxima, distância e alarmes.</td></tr>
<tr><td><strong>Fechamento diário</strong></td><td>Uma linha por dia e por veículo: primeira ignição ligada, última desligada, <strong>jornada</strong> (do começo ao fim do dia, com as paradas), <strong>tempo em movimento</strong> (só rodando), distância total, velocidade máxima, alarmes e quantidade de deslocamentos do dia.</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar por ativo + período + Gerar</td><td>Tabela carrega na modalidade escolhida, do mais antigo para o mais recente</td></tr>
<tr><td>Faixa horária (opcional)</td><td>Restringe a consulta a um intervalo de horas dentro do período</td></tr>
<tr><td>Ordenar por coluna</td><td>Setinha no cabeçalho de Início, Término, Velocidade Máxima e Distância (ou Dia, no fechamento diário)</td></tr>
<tr><td><strong>Ver rota</strong></td><td>Abre em nova aba o trajeto desenhado no mapa: balão verde na partida, vermelho na chegada, um ponto por posição enviada pela câmera e <strong>as ocorrências em laranja</strong>, com tipo, horário e risco no balão. No fechamento diário, mostra o dia inteiro.</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF com os dados da consulta</td></tr>
</table>

<div class="callout info">
<strong>Como o sistema separa um deslocamento do outro:</strong> o trajeto termina quando a ignição desliga, quando o veículo fica parado por mais de 5 minutos, ou quando o equipamento passa esse mesmo tempo sem comunicar. É por isso que uma jornada com várias paradas aparece como vários deslocamentos, e não como um só — mesmo que o motorista não tenha desligado a ignição em nenhum momento.
</div>
