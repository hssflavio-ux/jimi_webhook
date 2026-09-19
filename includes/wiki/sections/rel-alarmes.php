<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Histórico dos alarmes das <strong>câmeras</strong> — tudo o que não é evento de condução —, na ordem em que aconteceram. Filtros por cliente, placa, tipos de alarme (pode marcar vários), situação e período. Cada alarme tem um atalho para ver o local no mapa e, quando o equipamento anexou vídeo, um botão para assistir.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar + Gerar</td><td>Tabela atualiza com os filtros aplicados, do alarme mais antigo para o mais recente</td></tr>
<tr><td>Ordenar por coluna</td><td>Setinha no cabeçalho de Data/Hora, Placa, Código e Nome do Alarme</td></tr>
<tr><td>Tipos de Alarme</td><td>Clique nos tipos para incluí-los na consulta — dá para selecionar vários de uma vez</td></tr>
<tr><td>Ver posições no mapa</td><td>Abre um mapa com todos os alarmes da consulta. Cada marcador traz a <strong>placa, a data/hora e o nome do alarme</strong> — aqui cada ponto é de um veículo diferente</td></tr>
<tr><td>Ver Mapa (na linha)</td><td>Abre o mapa em uma nova aba, no local exato daquele alarme</td></tr>
<tr><td>Ver Vídeo (na linha)</td><td>Abre uma janela sobre a tela com o vídeo do evento, já posicionado num quadro do <strong>meio</strong>. Quando a câmera tem duas lentes, os dois vídeos (câmera 1 e câmera 2) tocam lado a lado ao mesmo tempo. Toca ali mesmo — baixar é opcional. Só aparece nos alarmes cujo equipamento anexou vídeo</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF com os dados filtrados</td></tr>
</table>

<div class="callout info">
<strong>Nem todo alarme tem vídeo.</strong> A coluna Vídeo mostra o arquivo que o próprio equipamento anexou ao alarme. Câmeras que não anexam vídeo ao evento aparecem com um traço. Os eventos de condução, como excesso de velocidade, ficam em <strong>Alertas Dirigibilidade</strong>. Para essas, o caminho é pedir a gravação em <strong>Vídeos → Playback</strong>.
</div>

<div class="callout info">
<strong>Alarme sem nome, só com número.</strong> Raramente um alarme aparece como <span class="mono">Código 1234 (JTT)</span> em vez de um nome. Isso significa que o equipamento enviou um código que ainda não está no catálogo do sistema — que mostra o número em vez de inventar um rótulo que poderia estar errado. O registro é válido: data, hora e local estão corretos. Assim que o código é cadastrado, o nome aparece também nos alarmes antigos, sem precisar refazer nada. Hoje não há nenhum nesta situação: os dois últimos (<span class="mono">1047</span>, capotamento, e <span class="mono">146</span>, curva brusca) foram cadastrados.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Eventos de diagnóstico <span class="badge" style="background:#fce4eb;color:#c83532">admin</span></h4>
<p>O sistema distingue <strong>alarmes operacionais</strong> (o que o veículo diz ao operador — distração, celular, fadiga, excesso de velocidade) de <strong>eventos de diagnóstico</strong> (o que o equipamento diz ao sistema — defeito de câmera, perda de sinal de vídeo, falha de armazenamento, handshake de upload, entrada e saída de repouso).</p>

<p>Por padrão a tela mostra apenas os alarmes operacionais — os que são relevantes para quem monitora a frota. Administradores têm, abaixo dos filtros, uma caixa <strong>"Eventos de diagnóstico"</strong> que troca a visão para os eventos técnicos; ela não aparece para outros perfis de usuário.</p>

<div class="callout warn">
<strong>Os dois modos nunca se misturam.</strong> Com a caixa marcada, a tela mostra <em>só</em> os diagnósticos; desmarcada, <em>só</em> os operacionais. Isso é proposital — um operador com centenas de "Falha de Câmera" na mesma lista de "Uso de Celular" perderia os eventos que precisa tratar no meio do ruído de infraestrutura.
</div>

<div class="callout info">
<strong>Diagnóstico não gera ocorrência nem notificação.</strong> Eventos técnicos são internos ao equipamento e não representam comportamento do motorista. Eles não aparecem no Dashboard de Ocorrências, no Resumo, no BI nem nos filtros de alarme dos outros relatórios — ficam restritos ao modo de diagnóstico desta tela.
</div>
