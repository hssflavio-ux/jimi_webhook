<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Visualizar gravações históricas do cartão de memória do equipamento. Ao clicar em <strong>Requisitar Gravações</strong>, o sistema consulta o cartão e monta a lista do período escolhido. A lista mostra gravações "No cartão" (com opção Extrair) e "Disponível" (já baixadas, prontas para reproduzir).</p>

<div class="mockup">
<div class="mockup-header">Playback — Timeline de Gravações</div>
<div class="mockup-body">
    <div class="filter-bar-mock" style="margin-bottom:16px">
        <div class="filter-mock">Equipamento: JC182 (869058070151343)</div>
        <div class="filter-mock">Canal: CH1</div>
        <div class="filter-mock dim">Período: dd/mm/aaaa - dd/mm/aaaa</div>
        <span class="btn-mock">Requisitar Gravações</span>
    </div>
    <div style="border:1px solid var(--hairline-soft);border-radius:var(--radius-lg);padding:16px">
        <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:10px">157 gravações encontradas</div>
        <div style="display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:auto">
            <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;background:#f8f9fb;border-radius:var(--radius-sm);font-size:13px">
                <span class="pill-mock green">Disponível</span>
                <span>18/07 14:00-14:05 (5 min)</span>
                <span class="btn-mock ghost" style="margin-left:auto;font-size:12px;padding:4px 12px">▶ Reproduzir</span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;font-size:13px">
                <span class="pill-mock gray">No cartão</span>
                <span>18/07 13:30-13:45 (15 min)</span>
                <span class="btn-mock outline" style="margin-left:auto;font-size:12px;padding:4px 12px">Extrair</span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;background:#f8f9fb;border-radius:var(--radius-sm);font-size:13px">
                <span class="pill-mock green">Disponível</span>
                <span>18/07 12:00-12:20 (20 min)</span>
                <span class="btn-mock ghost" style="margin-left:auto;font-size:12px;padding:4px 12px">▶ Reproduzir</span>
            </div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Requisitar Gravações</td><td>Consulta o equipamento e preenche a lista de gravações do período</td></tr>
<tr><td>Extrair (gravação "No cartão")</td><td>Pede o envio da gravação escolhida. A câmera envia o arquivo e o status muda sozinho para "Disponível" — um trecho de 30 segundos costuma levar poucos segundos; trechos longos, proporcionalmente mais</td></tr>
<tr><td>Reproduzir (gravação "Disponível")</td><td>Abre o player e reproduz a gravação na própria tela, com barra de progresso — dá para arrastar para um ponto específico do vídeo</td></tr>
<tr><td>Auto-atualização</td><td>Após requisitar, a lista se atualiza sozinha por alguns instantes</td></tr>
</table>

<div class="callout info">
<strong>O arquivo extraído aparece no dia que você filtrou.</strong> Uma gravação recém-extraída entra na linha do tempo na data e hora em que foi <em>gravada</em>, não na data em que você a extraiu. Se você filtrou o dia 05 e extraiu um trecho daquele dia, ele aparece ali mesmo — não é preciso trocar o filtro para encontrá-lo.
</div>

<div class="callout warn">
<strong>Extrair depende da câmera estar conectada.</strong> O pedido vai até o equipamento e é ele quem envia o arquivo. Com a câmera fora do ar, a gravação continua marcada como "No cartão" — ela não se perde, mas só chega quando o equipamento voltar a se comunicar.
</div>
