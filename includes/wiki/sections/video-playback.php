<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Visualizar gravações históricas do cartão de memória do equipamento. Ao clicar em <strong>Requisitar Gravações</strong>, o sistema consulta a câmera e monta a linha do tempo do período escolhido. Cada trecho aparece como <strong>"Na câmera"</strong> (ainda só no cartão) ou <strong>"Upload efetuado"</strong> (o arquivo já está no servidor e pode ser reproduzido). Equipamentos sem câmera (rastreadores) não aparecem nesta tela.</p>

<div class="mockup">
<div class="mockup-header">Playback — Timeline de Gravações</div>
<div class="mockup-body">
    <div class="filter-bar-mock" style="margin-bottom:16px">
        <div class="filter-mock">Placa: CAM-001 (JC182)</div>
        <div class="filter-mock dim">De/Até: dd/mm/aaaa - dd/mm/aaaa</div>
        <span class="btn-mock">Requisitar Gravações</span>
    </div>
    <div style="border:1px solid var(--hairline-soft);border-radius:var(--radius-lg);padding:16px">
        <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:10px">157 gravações disponíveis.</div>
        <div style="display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:auto">
            <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;background:#f8f9fb;border-radius:var(--radius-sm);font-size:13px">
                <span class="pill-mock green">Upload efetuado</span>
                <span>18/07 14:00-14:05 (5 min)</span>
                <span class="btn-mock ghost" style="margin-left:auto;font-size:12px;padding:4px 12px">▶ Reproduzir</span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;font-size:13px">
                <span class="pill-mock gray">Na câmera</span>
                <span>18/07 13:30-13:45 (15 min)</span>
                <span class="btn-mock outline" style="margin-left:auto;font-size:12px;padding:4px 12px">Ações</span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;background:#f8f9fb;border-radius:var(--radius-sm);font-size:13px">
                <span class="pill-mock green">Upload efetuado</span>
                <span>18/07 12:00-12:20 (20 min)</span>
                <span class="btn-mock ghost" style="margin-left:auto;font-size:12px;padding:4px 12px">▶ Reproduzir</span>
            </div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Requisitar Gravações</td><td>Consulta a câmera e monta a linha do tempo e a lista do período (De/Até). <strong>Não há seletor de canal</strong>: a consulta traz todos os canais, e o canal se escolhe clicando na faixa dele. Administradores e revendedores escolhem antes o <strong>Cliente</strong></td></tr>
<tr><td>Barra "Gravações na câmera"</td><td>Uma faixa por canal, com o que a câmera tem gravado. Azul = "Na câmera"; verde = "Upload efetuado". Use a roda do mouse para aproximar e afastar, arraste para andar no tempo, ou os botões <strong>-</strong>, <strong>+</strong> e <strong>Tudo</strong>. Clicar num trecho verde reproduz; clicar num trecho azul abre a escolha da ação</td></tr>
<tr><td>Lista de gravações</td><td>Acompanha o trecho que está na barra, agrupada por dia, com hora, canal (CH1, CH2), duração e tamanho. Trecho já no servidor traz o selo <strong>Upload efetuado</strong> (ou <strong>Baixado</strong>) e reproduz ao clique; trecho só na câmera traz um botão de três pontos verticais. Passando de um certo limite, a lista avisa quantas mostra e pede para aproximar a barra</td></tr>
<tr><td>Ver na câmera</td><td>Transmite o trecho <strong>direto da câmera</strong> para a tela, sem baixar nada — não deixa arquivo no servidor</td></tr>
<tr><td>Subir para o storage</td><td>Pede à câmera que envie o arquivo para o servidor. <strong>Consome a franquia de dados do SIM</strong> e cria o arquivo; quando ele chega, o trecho vira "Upload efetuado" e passa a poder ser reproduzido e baixado. Nada sobe sozinho: só com o seu pedido</td></tr>
<tr><td>Reproduzir (Upload efetuado)</td><td>Abre o player e reproduz na própria tela, com barra de progresso — dá para arrastar para um ponto do vídeo. O botão <strong>Baixar arquivo</strong>, abaixo do player, salva o arquivo</td></tr>
<tr><td>Auto-atualização</td><td>Após requisitar, a lista se atualiza sozinha por alguns instantes</td></tr>
</table>

<div class="callout warn">
<strong>A listagem tem validade.</strong> A tela diz "Listagem efetuada às HH:MM de dd/mm/aa — validade de 30 min". Passado esse prazo, o aviso muda para <strong>"Listagem expirada, faça nova requisição"</strong> e a gravação contínua some da tela; o que sobra são só os vídeos de eventos (alarmes), que não têm validade. Se aparecem só vídeos de alarme, o primeiro passo é requisitar de novo — a câmera responde em alguns segundos. Um equipamento que nunca foi consultado mostra "nunca teve as gravações da câmera listadas".
</div>

<div class="callout info">
<strong>Buracos na gravação são normais.</strong> A câmera só grava quando o veículo roda, então muitos períodos não têm nada. Quando o trecho em tela está vazio, a lista diz "Nenhuma gravação neste trecho" e oferece <strong>Ir para a gravação mais próxima</strong>.
</div>

<div class="callout info">
<strong>O arquivo enviado aparece no dia que você filtrou.</strong> Uma gravação recém-enviada entra na linha do tempo na data e hora em que foi <em>gravada</em>, não na data em que você pediu o envio. Se você filtrou o dia 05 e subiu um trecho daquele dia, ele aparece ali mesmo — não é preciso trocar o filtro para encontrá-lo. Esta tela trata só <strong>vídeo</strong>: fotos de alarme não aparecem aqui.
</div>

<div class="callout warn">
<strong>Ver na câmera e Subir para o storage dependem da câmera estar conectada.</strong> O pedido vai até o equipamento e é ele quem transmite ou envia o arquivo. Com a câmera fora do ar, o trecho continua marcado como "Na câmera" — a gravação não se perde, mas só chega quando o equipamento voltar a se comunicar.
</div>
