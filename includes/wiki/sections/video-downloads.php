<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Grade com todos os arquivos de mídia que a câmera enviou ao servidor — trechos pedidos em Playback e vídeos de alarme. Filtros por cliente, placa e status (pronto para baixar, já baixado, pendente na câmera, erro). Cada linha tem o botão de <strong>Baixar</strong>. Equipamentos sem câmera (rastreadores) não aparecem no filtro de placa.</p>

<div class="mockup">
<div class="mockup-header">Downloads — Arquivos de Mídia</div>
<div class="mockup-body">
    <div class="filter-bar-mock" style="margin-bottom:0">
        <div class="filter-mock">Placa: Todas as placas</div>
        <div class="filter-mock">Status: Todos</div>
    </div>
    <table class="tbl-mock" style="margin-top:12px">
    <tr><th>Placa</th><th>Canal</th><th>Alarme</th><th>Início do vídeo</th><th>Requisitado em</th><th>Arquivo</th><th>Status</th><th>Download</th></tr>
    <tr><td class="mono">FJR7B59</td><td>CH1</td><td>Distração<br><span class="mono" style="font-size:10px">18/07/2026 14:32:05</span></td><td>18/07/2026 14:31:50</td><td>18/07/2026 14:32:20</td><td class="mono" style="font-size:11px">..._F_39.mp4</td><td><span class="pill-mock green">Pronto</span></td><td><span class="btn-mock" style="font-size:12px;padding:2px 8px">Baixar CH1</span></td></tr>
    <tr><td class="mono">RQP2A41</td><td>CH2</td><td><span class="pill-mock gray">On demand</span></td><td>18/07/2026 13:30:00</td><td>18/07/2026 14:28:10</td><td class="mono" style="font-size:11px">..._I_40.mp4</td><td><span class="pill-mock green">Já baixado</span></td><td><span class="btn-mock outline" style="font-size:12px;padding:2px 8px">Baixar novamente CH2</span></td></tr>
    <tr><td class="mono">GHT5C08</td><td>CH1</td><td>—</td><td>18/07/2026 13:55:00</td><td>18/07/2026 13:55:40</td><td class="mono" style="font-size:11px">..._F_12.mp4</td><td><span class="pill-mock yellow">Pendente na câmera</span></td><td>Aguardando câmera</td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Coluna / ação</th><th>O que significa</th></tr>
<tr><td><strong>Alarme</strong></td><td>Por que o arquivo existe: o nome e a hora do alarme que o originou, ou o selo <strong>On demand</strong> quando foi um trecho extraído a pedido do operador (por exemplo, "Subir para o storage" em Playback). Um traço quer dizer que não há alarme vinculado nem origem registrada</td></tr>
<tr><td><strong>Início do vídeo</strong> e <strong>Requisitado em</strong></td><td>Início do vídeo é o instante em que o trecho foi <em>gravado</em>; Requisitado em é quando o pedido foi feito — os dois podem estar dias distantes quando se extrai algo antigo</td></tr>
<tr><td><strong>Arquivo</strong></td><td>O nome inteiro do arquivo. Câmera com duas lentes envia dois arquivos (frontal e interna) para o mesmo pedido, e os dois aparecem separados, com o canal (CH1, CH2)</td></tr>
<tr><td><strong>Status</strong></td><td><strong>Pronto</strong>: o arquivo chegou e ainda não foi baixado. <strong>Já baixado</strong>: alguém já baixou (o selo mostra quando e quantas vezes). <strong>Pendente na câmera</strong>: a câmera ainda não terminou de enviar. <strong>Erro</strong>: o envio falhou</td></tr>
<tr><td><strong>Baixar</strong></td><td>Baixa o arquivo — um botão por arquivo, com o canal quando há dois. Depois do primeiro download o botão vira <strong>Baixar novamente</strong>. Só <em>assistir</em> em outra tela não conta como baixado. Se a câmera anunciou o arquivo mas ele não está no servidor, aparece <strong>Arquivo ausente</strong></td></tr>
<tr><td>Exportar</td><td>Baixa Excel ou PDF com a mesma lista e a mesma disposição de colunas da tela, respeitando os filtros</td></tr>
</table>

<div class="callout tip">
<strong>Vindo de outra tela.</strong> Ao abrir a lista de um único equipamento (por exemplo, a partir de Playback), a tela mostra "Vídeos de &lt;placa&gt; que já estão no storage" e esconde as colunas de Cliente e Placa. Se ainda não há nenhum, ela sugere pedir um trecho em Playback — ele aparece aqui quando a câmera terminar de enviar.
</div>
