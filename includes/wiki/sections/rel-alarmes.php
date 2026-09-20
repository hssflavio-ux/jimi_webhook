<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Histórico dos alarmes das <strong>câmeras</strong> — tudo o que não é evento de condução —, na ordem em que aconteceram. Filtros por cliente, placa, tipos de alarme (pode marcar vários), situação e período (a tela abre no dia de hoje; o máximo é de 31 dias). Cada alarme tem um atalho para ver o local no mapa e, quando o equipamento anexou vídeo, um botão para assistir.</p>

<p>A grade traz, para cada alarme: <strong>Placa</strong>, <strong>Data/Hora</strong>, <strong>Nome do Alarme</strong>, <strong>Status</strong> (Ativo ou Resolvido), <strong>Velocidade</strong>, <strong>Motorista</strong>, <strong>Endereço</strong>, o atalho do <strong>Mapa</strong> e o <strong>Vídeo</strong>.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Filtrar + Gerar</td><td>Tabela atualiza com os filtros aplicados, do alarme mais antigo para o mais recente</td></tr>
<tr><td>Ordenar por coluna</td><td>Setinha no cabeçalho de Placa, Data/Hora e Nome do Alarme</td></tr>
<tr><td>Tipos de Alarme</td><td>Clique nos tipos para incluí-los na consulta — dá para selecionar vários de uma vez</td></tr>
<tr><td>Ver no Mapa</td><td>Abre um mapa com os alarmes da <strong>página que está na tela</strong>. Cada marcador traz a <strong>placa, a data/hora e o nome do alarme</strong> — aqui cada ponto é de um veículo diferente</td></tr>
<tr><td>Ver Mapa (na linha)</td><td>Abre o mapa em uma nova aba, no local exato daquele alarme</td></tr>
<tr><td>Ver Vídeo (na linha)</td><td>Abre uma janela sobre a tela com o vídeo do evento, já posicionado num quadro do <strong>meio</strong>. Quando a câmera tem duas lentes, os dois vídeos (câmera 1 e câmera 2) tocam lado a lado ao mesmo tempo. Toca ali mesmo — baixar é opcional. Quando o equipamento mandou só foto, o botão vira <strong>Ver Foto</strong>. Só aparece nos alarmes cujo arquivo já chegou ao sistema</td></tr>
<tr><td>Pedir vídeo (na linha)</td><td>Aparece no lugar de Ver Vídeo quando a câmera <strong>anunciou</strong> um arquivo para o alarme, mas ele não chegou. O clique pede o vídeo de novo: a câmera gera o trecho a partir do cartão de memória e envia depois — não é instantâneo, então volte à tela mais tarde</td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF com os dados filtrados</td></tr>
</table>

<div class="callout info">
<strong>Nem todo alarme tem vídeo.</strong> A coluna Vídeo mostra o arquivo que o próprio equipamento anexou ao alarme: <em>Ver Vídeo</em> (ou <em>Ver Foto</em>) quando o arquivo já está no sistema, <em>Pedir vídeo</em> quando a câmera o anunciou e ele não chegou, e um traço quando o alarme não tem anexo. Câmeras que não anexam vídeo ao evento aparecem com traço; para elas, o caminho é pedir a gravação em <strong>Vídeos → Playback</strong>.
</div>

<div class="callout info">
<strong>O que esta tela mostra — e o que não mostra.</strong> Ela lista os alarmes de <strong>equipamentos com câmera</strong> e deixa de fora dois grupos: os <strong>eventos de condução</strong> (arrancada, freada, curva, excesso de velocidade, colisão, capotamento, impacto e inclinação), que ficam em <strong>Alertas Dirigibilidade</strong> — venham de câmera ou de rastreador —, e os alarmes de <strong>rastreador</strong> que não são de condução (roubo, partida ilegal, desmontado), que ficam só na aba Alertas da ficha do veículo. Por isso o filtro de Placa desta tela não lista rastreadores, e o filtro de Tipos de Alarme oferece só os tipos de câmera com IA (DMS e ADAS).
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
