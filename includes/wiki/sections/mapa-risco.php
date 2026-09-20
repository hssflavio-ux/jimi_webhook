<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> mostrar onde, quando, em que ponto da jornada e com quem os comportamentos de risco (ADAS/DMS) acontecem. O risco é medido por <strong>índice</strong> — pontos por hora dirigida —, e não por contagem: um veículo que roda 10 h tem naturalmente mais alertas do que um que roda 1 h.</p>

<table class="tbl-mock">
<tr><th>Item</th><th>Como funciona</th></tr>
<tr><td>Pontos</td><td>Cada alerta vale o risco que o cliente deu ao tipo em Config. Ocorrências: baixo 1, médio 3, alto 5 (tipo sem configuração vale 3). Alerta marcado como falso positivo não conta. Alertas de equipamento (câmera obstruída, óculos escuros, falha de comunicação) ficam de fora.</td></tr>
<tr><td>Índice (pts/h)</td><td>Soma dos pontos ÷ horas em movimento. Pts/100 km é a mesma conta pela distância.</td></tr>
<tr><td>Direção contínua</td><td>Tempo com ignição ligada desde a última parada de 30 min ou mais com ignição desligada. Falta de sinal não interrompe a contagem.</td></tr>
<tr><td>Aba Onde</td><td>Mapa em quadrados de 1 km coloridos pelo índice (terços) e a lista dos locais mais críticos. Quadrado cinza: pouca exposição (menos de 30 min ou menos de 3 alertas) — aparece, mas não entra no ranking.</td></tr>
<tr><td>Aba Quando</td><td>Faixa do dia (madrugada, manhã, tarde, noite), dia da semana e grade hora × dia.</td></tr>
<tr><td>Aba Jornada</td><td>Índice por faixa de direção contínua e por faixa de velocidade no momento do alerta.</td></tr>
<tr><td>Aba Quem</td><td>Veículos, tipo de veículo, motoristas e comportamentos, e a reincidência (o mesmo comportamento 3 vezes ou mais no período). Veículo e motorista só entram no ranking com 5 h de exposição.</td></tr>
<tr><td>Aba Tendência</td><td>Últimas 12 semanas e últimos 12 meses, e o mês atual contra o anterior nos mesmos dias corridos.</td></tr>
<tr><td>Rastreadores</td><td>Equipamentos sem câmera (rastreadores) não entram no Mapa de Risco: não aparecem no seletor de veículos e as horas deles não diluem o índice. Os eventos de condução deles ficam em <a href="#rel-dirigibilidade" style="color:inherit">Alertas Dirigibilidade</a>.</td></tr>
<tr><td>Período</td><td>Até 90 dias. Os últimos 7 dias ainda podem mudar: a câmera descarrega alertas e posições guardados sem sinal até dias depois.</td></tr>
<tr><td>Exportar</td><td>Excel ou PDF com as tabelas da aba aberta.</td></tr>
<tr><td>Quem vê</td><td>Quem tem acesso ao BI.</td></tr>
</table>
