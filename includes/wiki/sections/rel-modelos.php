<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Guardar uma combinação de filtros que você usa com frequência e reaplicá-la em um clique, em vez de preencher tudo de novo toda vez.</p>

<div class="mockup">
<div class="mockup-header">Barra de modelos — aparece acima dos filtros</div>
<div class="mockup-body">
    <div class="filter-bar-mock">
        <span style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted)">Modelos</span>
        <div class="filter-mock">Frota SP — risco alto</div>
        <span class="btn-mock outline">Excluir</span>
        <span class="btn-mock">Salvar filtros atuais como modelo</span>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Salvar filtros atuais como modelo</td><td>Pede um nome e guarda os filtros que estão na tela naquele momento</td></tr>
<tr><td>Escolher um modelo na lista</td><td>A tela recarrega já com aqueles filtros preenchidos e o resultado gerado</td></tr>
<tr><td>Excluir</td><td>Apaga o modelo selecionado</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Detalhe</th><th>Como funciona</th></tr>
<tr><td>Onde aparece</td><td>Em todas as telas de relatório. A barra fica escondida enquanto não há modelo salvo nem filtro aplicado — numa tela recém-aberta não há o que guardar.</td></tr>
<tr><td>De quem é o modelo</td><td>Seu. Cada usuário vê apenas os próprios modelos; ninguém enxerga nem apaga o modelo de outra pessoa.</td></tr>
<tr><td>Não se misturam</td><td>Um modelo pertence à tela em que foi criado. Um modelo de Alarmes não aparece na lista de Posições.</td></tr>
<tr><td>Limite</td><td>Até 20 modelos por relatório, por usuário.</td></tr>
<tr><td>O que <em>não</em> é guardado</td><td>Página, ordenação e formato de exportação ficam de fora — senão o modelo abriria sempre na página 7 ou baixaria um arquivo em vez de mostrar a tela.</td></tr>
</table>

<div class="callout tip">
<strong>O período faz parte do modelo.</strong> Se o modelo foi salvo com um intervalo de datas fixo, ele volta com aquelas mesmas datas — ajuste o período depois de aplicar. Para receber o relatório pronto e recorrente, o caminho é <a href="#agendamentos" style="color:inherit">Agendamentos</a>.
</div>
