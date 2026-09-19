<?php defined('WIKI_SECTION') || exit; ?>
<p>Estes comportamentos são iguais em todas as telas de relatório. O que muda de um para outro são os filtros e as colunas.</p>

<table class="tbl-mock">
<tr><th>Recurso</th><th>Como funciona</th></tr>
<tr>
    <td><strong>Escolher o veículo</strong></td>
    <td>O filtro de equipamento é uma <strong>lista de placas</strong>, não um campo de digitação. Abra a lista e escolha — só aparecem os veículos que você tem permissão de ver. A placa também é a <strong>primeira coluna</strong> da maioria dos relatórios, na tela e nos arquivos exportados.</td>
</tr>
<tr>
    <td><strong>Endereço, não coordenada</strong></td>
    <td>Onde antes apareciam números de latitude e longitude, hoje aparece o <strong>endereço</strong> no formato <em>rua, cidade, estado</em>. Em rodovia ou zona rural o endereço pode sair mais curto (às vezes só cidade e estado) — é o que existe de referência naquele ponto.</td>
</tr>
<tr>
    <td><strong>Coluna Mapa</strong></td>
    <td>Os relatórios com posição trazem uma coluna <strong>Mapa</strong>. Na tela, no Excel e no PDF ela é um link que abre aquele ponto no mapa, e <strong>funciona para quem recebe o arquivo</strong>, mesmo sem conta no sistema.</td>
</tr>
<tr>
    <td><strong>Ordem dos resultados</strong></td>
    <td>Todo relatório com data abre em <strong>ordem crescente</strong>: o registro mais antigo no topo e o mais recente no fim da página — a leitura acompanha a linha do tempo.</td>
</tr>
<tr>
    <td><strong>Setinha de ordenação</strong></td>
    <td>As colunas ordenáveis têm uma seta no cabeçalho. A coluna em uso mostra <strong>▲</strong> (crescente) ou <strong>▼</strong> (decrescente) em azul; as demais mostram <strong>⇅</strong> em cinza. Um clique inverte a ordem, outro clique volta. Os filtros são mantidos e a listagem volta para a página 1.</td>
</tr>
<tr>
    <td><strong>Botão Voltar</strong></td>
    <td>Depois que o resultado aparece, surge o botão <strong>← Voltar</strong> no canto superior direito, ao lado dos botões de exportar. Ele limpa os filtros e devolve a tela em branco do mesmo relatório — não é preciso ir de novo ao menu lateral.</td>
</tr>
<tr>
    <td><strong>Paginação</strong></td>
    <td>Os números acompanham a página em que você está: a primeira e a última ficam sempre visíveis e as reticências indicam o salto. Estando na página 12 de 14, por exemplo, aparece <span class="mono">« 1 … 10 11 12 13 14 »</span>. As setas « e » avançam de uma em uma.</td>
</tr>
<tr>
    <td><strong>Exportar</strong></td>
    <td>Excel ou PDF, sempre com <strong>os mesmos filtros e a mesma ordenação</strong> que estão na tela. O arquivo baixa na hora (até 10.000 linhas). Para volumes maiores, use a tela Exportar, que processa em segundo plano.</td>
</tr>
</table>

<div class="callout info">
<strong>Período máximo de 31 dias:</strong> Todo relatório com filtro de data aceita no máximo 31 dias por consulta. Se você pedir um intervalo maior, o sistema encurta a data final e avisa na tela com uma tarja amarela. Para períodos longos, faça a consulta em partes ou use a tela Exportar.
</div>

<div class="callout tip">
<strong>Horários sempre em Brasília:</strong> Todas as datas e horas exibidas nos relatórios — e as que você digita nos filtros — estão no horário de Brasília. Os equipamentos transmitem em outro fuso, e o sistema faz a conversão sozinho.
</div>
