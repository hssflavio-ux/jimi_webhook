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
<tr><td>Filtro de veículo</td><td>Lista suspensa com marcação: escolha <strong>um, vários ou todos</strong> os veículos (vazio = todos). Tem busca a partir de 8 opções.</td></tr>
<tr><td>Filtro de comportamento</td><td>Uma lista com duas seções, <strong>DMS</strong> (o que a câmera vê do motorista: celular, fadiga, cinto, distração…) e <strong>ADAS</strong> (o que ela vê da estrada: colisão, faixa, distância…). Cada seção tem <em>todos</em> e <em>nenhum</em> próprios — dá para marcar todos os DMS, todos os ADAS, alguns de cada ou qualquer mistura. Vazio = todos os comportamentos.</td></tr>
<tr><td>Comportamento que não aparece</td><td>A lista mostra só os comportamentos que o sistema <strong>já recebeu</strong>. Um que nenhuma câmera nunca enviou — hoje <em>Excesso em placa de trânsito</em> e <em>Obstáculo à frente</em>, que só existem no protocolo JT/T e dependem do recurso ADAS estar ligado na câmera — não é oferecido, e aparece sozinho no dia em que o primeiro alerta chegar. (A tela <a href="#rel-alarmes" style="color:inherit">Alertas Videomonitoramento</a> lista o catálogo inteiro de propósito.) Os pontos e o índice continuam calculados sobre <strong>todas</strong> as horas dirigidas dos veículos escolhidos, não só as dos comportamentos marcados.</td></tr>
<tr><td>Período</td><td>Até 90 dias. Os últimos 7 dias ainda podem mudar: a câmera descarrega alertas e posições guardados sem sinal até dias depois.</td></tr>
<tr><td>Exportar</td><td>Excel ou PDF com as tabelas da aba aberta.</td></tr>
<tr><td>Quem vê</td><td>Quem tem acesso ao BI.</td></tr>
</table>
