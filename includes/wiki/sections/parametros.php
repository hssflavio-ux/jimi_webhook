<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Ler, comparar e alterar a configuração remota das câmeras JT/T (resolução de vídeo, sensibilidade de alarme, intervalos de envio, servidores, etc.) — tudo sem acesso físico ao equipamento. O menu <strong>Parâmetros</strong> fica logo abaixo de Comandos e reúne três funções que antes estavam espalhadas.</p>

<div class="callout warn">
<strong>Área de administrador.</strong> As três funções abaixo só aparecem no menu e só respondem para usuários com perfil de administrador. Operadores e visualizadores não veem o item no menu e recebem "acesso negado" ao tentar acessar diretamente.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Leitura de Parâmetros</h4>
<p>A tela principal do menu lista todos os equipamentos JT/T do cliente com o status de sincronização de cada um. Ao clicar em um equipamento, abre-se a aba de parâmetros com todos os valores lidos da câmera, organizados por categoria (vídeo, rede, alarme, etc.).</p>

<div class="mockup">
<div class="mockup-header">Parâmetros — Visão por Equipamento</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Equipamento</th><th>Modelo</th><th>Parâmetros lidos</th><th>Última leitura</th><th></th></tr>
    <tr><td>FJR7B59</td><td>JC371</td><td class="mono">49</td><td>14/08 08:30</td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Ver</span></td></tr>
    <tr><td>RQP2A41</td><td>JC182</td><td class="mono">47</td><td>14/08 08:31</td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Ver</span></td></tr>
    <tr><td>GHT5C08</td><td>JC181</td><td class="mono">6</td><td>13/08 22:10</td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Ver</span></td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Ver</td><td>Abre a lista de parâmetros lidos da câmera, organizados por categoria. Cada linha mostra o número do parâmetro, o nome, o valor atual e a última leitura</td></tr>
<tr><td>Solicitar Leitura</td><td>Manda o comando de leitura para a câmera. Se estiver online, os valores chegam em segundos; se estiver offline, a leitura é feita quando o equipamento voltar a se comunicar</td></tr>
<tr><td>Alterar Parâmetro</td><td>Abre formulário para modificar o valor de um parâmetro específico. O sistema grava o valor anterior antes de enviar o novo — se der errado, você sabe o que restaurar</td></tr>
</table>

<div class="callout warn">
<strong>Parâmetros de rede são bloqueados.</strong> Endereço do servidor principal e porta de comunicação não podem ser alterados pela tela — mudar esses valores desconectaria o equipamento do sistema. São mostrados para consulta, mas o botão de edição não aparece.
</div>

<div class="callout info">
<strong>Cada modelo tem parâmetros diferentes.</strong> Um JC371 pode ter 49 parâmetros lidos, um JC182 ter 47 e um JC181 ter 6 — e isso é normal: modelos diferentes suportam conjuntos de configuração diferentes. Não compare os números entre modelos.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Relatório de Parâmetros da Frota</h4>
<p>Responde à pergunta: <strong>quais câmeras estão configuradas fora do padrão?</strong> Para cada modelo, o sistema calcula o valor mais comum de cada parâmetro na frota e destaca as câmeras que divergem.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Gerar relatório</td><td>Mostra, agrupado por modelo, cada câmera que tem pelo menos um parâmetro diferente do que a maioria da frota usa</td></tr>
<tr><td>Ver divergências</td><td>Cada câmera fora do padrão lista os parâmetros que diferem, com o valor do equipamento e o valor que a maioria usa</td></tr>
</table>

<div class="callout info">
<strong>O padrão é a própria frota.</strong> O valor de referência é o mais comum entre os equipamentos do mesmo modelo — não um valor "ideal" cadastrado manualmente. Isso significa que o relatório já funciona sem nenhuma configuração prévia. Modelo com um único equipamento aparece separado, como "sem base de comparação".
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Perfis de Parâmetros</h4>
<p>Permite criar conjuntos nomeados de configuração por modelo de câmera e compará-los com os equipamentos da frota. Útil para padronizar a configuração de vários equipamentos.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar perfil</td><td>Nome + modelo. Os parâmetros do perfil começam com os valores mais comuns da frota e podem ser ajustados</td></tr>
<tr><td>Simular aplicação</td><td>Mostra quais equipamentos teriam parâmetros alterados e quais valores mudariam — <strong>sem enviar nada</strong></td></tr>
<tr><td>Editar perfil</td><td>Alterar os valores de qualquer parâmetro do perfil</td></tr>
<tr><td>Excluir perfil</td><td>Remove o perfil. Os equipamentos não são afetados</td></tr>
</table>

<div class="callout warn">
<strong>A aplicação é por equipamento.</strong> O perfil serve para definir o padrão desejado e <em>ver quem diverge</em>. A escrita em câmera real acontece na tela de leitura, equipamento a equipamento — de propósito: escrever configuração em operação pede conferência individual.
</div>

<div class="callout tip">
<strong>Só equipamentos JT/T aparecem aqui.</strong> Os comandos de parâmetro são do protocolo JT/T; câmeras JIMI não os entendem. Por isso a tela lista apenas os equipamentos cujo modelo é JT/T — mostrar os outros ofereceria uma ação que falharia sempre.
</div>
