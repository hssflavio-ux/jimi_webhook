<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Saber qual versão de firmware cada câmera está rodando e atualizá-la à distância. São duas metades da mesma pergunta: <em>o que está instalado</em> e <em>para onde atualizar</em>.</p>

<div class="mockup">
<div class="mockup-header">Firmware — Frota</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Equipamento</th><th>Modelo</th><th>Firmware</th><th>Lido em</th><th>Referência</th><th>Situação</th></tr>
    <tr><td>400AD_3</td><td>JC400AD</td><td>V1.8.1.2_250904</td><td>19/08 14:02</td><td>V1.8.1.2_250904</td><td><span class="pill-mock green">Igual à de referência</span></td></tr>
    <tr><td>400AD_1</td><td>JC400AD</td><td>V1.8.0.9_250807</td><td>19/08 14:02</td><td>V1.8.1.2_250904</td><td><span class="pill-mock yellow">Diferente da de referência</span></td></tr>
    <tr><td>371_3241</td><td>JC371</td><td>—</td><td>nunca</td><td>—</td><td><span class="pill-mock gray">Firmware não lido</span></td></tr>
    </table>
</div>
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Ler a versão instalada</h4>
<p>O botão <strong>Ler versão</strong> pergunta ao equipamento qual firmware ele está rodando, e a resposta é gravada automaticamente. <strong>Ler versão de todos</strong> faz o mesmo para a lista inteira, um equipamento por vez.</p>

<div class="callout tip">
<strong>A resposta não é instantânea.</strong> O comando sai na hora; a versão aparece na tela quando o equipamento responder — recarregue depois de alguns segundos. Equipamento desligado recebe o comando quando voltar a se conectar, e a versão é gravada nesse momento.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">URLs de atualização</h4>
<p>A atualização funciona assim: o equipamento recebe um endereço, baixa o pacote de lá e se atualiza sozinho. <strong>O comando é o mesmo em todos os modelos de câmera — o que muda é o pacote.</strong> Por isso a URL é cadastrada com o modelo junto, e não digitada a cada envio.</p>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que é</th></tr>
<tr><td>Modelo</td><td>Para qual modelo de câmera este pacote serve</td></tr>
<tr><td>Versão</td><td>Exatamente como o equipamento a reporta (ex.: <code>V1.8.1.2_250904</code>) — é o que permite comparar</td></tr>
<tr><td>URL do pacote</td><td>Endereço de onde a câmera vai baixar o arquivo</td></tr>
<tr><td>Versão de referência</td><td>Marca a versão que a frota daquele modelo <em>deveria</em> estar rodando. Só uma por modelo</td></tr>
</table>

<div class="callout warn">
<strong>A URL errada não dá erro — a câmera aceita e aplica.</strong> Mandar para uma câmera o pacote de outro modelo não devolve mensagem de falha nenhuma: o equipamento baixa e instala, e o problema só aparece depois, na câmera que parou de funcionar. É exatamente por isso que a URL é cadastro com o modelo na chave, e por que a tela bloqueia enviar para dois modelos de uma vez.
</div>

<div class="callout warn">
<strong>A URL não pode ter vírgula nem <code>#</code>.</strong> São caracteres que o equipamento usa para separar as partes do comando: uma URL que os contenha chega partida ao meio, e a câmera tenta baixar um endereço incompleto. A tela recusa no cadastro.
</div>

<div class="callout tip">
<strong>Equipamento sem modelo cadastrado não pode ser atualizado.</strong> Sem saber o modelo não há como escolher o pacote certo. Cadastre o modelo em <strong>Cadastros → Equipamentos</strong> e o botão volta a funcionar.
</div>

<div class="callout">
<strong>Por que a tela nunca diz "desatualizado".</strong> Não existe uma regra publicada que diga qual de duas versões é mais nova — comparar <code>V1.8.0.9_250807</code> com <code>V4.3.2</code> seria chute. A tela informa apenas se a versão instalada é <strong>igual</strong> ou <strong>diferente</strong> da que você marcou como referência.
</div>
