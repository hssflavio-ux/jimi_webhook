<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Assistir ao vivo às câmeras dos veículos. Escolha o equipamento e o canal da câmera, clique em <strong>Iniciar Transmissão</strong> e aguarde alguns segundos até a imagem aparecer.</p>

<div class="mockup">
<div class="mockup-header">Vídeo ao Vivo</div>
<div class="mockup-body">
    <div style="margin-bottom:12px;display:flex;gap:10px;align-items:center">
        <div class="filter-mock">Equipamento: CAM-001 (860112070347838)</div>
        <div class="filter-mock">Canal: CH1</div>
        <span class="btn-mock">Iniciar Transmissão</span>
        <span style="font-size:11px;color:var(--muted)">Rotação: 0° | Marca d'água: Desligado</span>
    </div>
    <div class="video-mock">
        <div style="position:relative;z-index:1;display:flex;align-items:center;gap:8px;flex-direction:column">
            <span>Transmissão ao vivo — Canal 1</span>
            <span style="font-size:11px;opacity:.6">Conectando à câmera...</span>
        </div>
    </div>
    <div style="margin-top:8px;display:flex;gap:16px">
        <span class="filter-mock" style="font-size:12px">CH1</span>
        <span class="filter-mock" style="font-size:12px;opacity:.5">CH2</span>
        <span class="filter-mock" style="font-size:12px;opacity:.5">CH3</span>
        <span class="filter-mock" style="font-size:12px;opacity:.5">CH4</span>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar equipamento + canal + Iniciar</td><td>A câmera é acionada e o vídeo abre em alguns segundos (normalmente entre 5 e 30)</td></tr>
<tr><td>Trocar de canal</td><td>Encerra a transmissão atual e abre a imagem do canal escolhido</td></tr>
<tr><td>Dispositivo offline</td><td>O pedido fica agendado e a barra de status avisa que será executado quando o equipamento voltar a se conectar</td></tr>
<tr><td>Rotação de tela</td><td>A imagem aparece girada conforme configurado no cadastro do equipamento</td></tr>
<tr><td>Marca d'água</td><td>Se habilitada no cadastro, o vídeo exibe o texto de marca d'água</td></tr>
</table>

<div class="callout warn">
<strong>Aguarde a imagem:</strong> Entre clicar em "Iniciar" e o vídeo aparecer, a câmera precisa ser ativada e começar a transmitir. Isso leva de 5 a 30 segundos — a tela mostra o progresso enquanto isso.
</div>

<p>Ao lado do player, um <strong>painel de informações</strong> descreve o equipamento selecionado: a <strong>placa</strong>, os <strong>canais de câmera</strong> que aquele modelo tem, a <strong>última comunicação</strong> (no horário de Brasília) e o <strong>status</strong> de conexão.</p>

<div class="callout info">
<strong>O painel acompanha a lista.</strong> Trocar o equipamento na lista troca também os dados do painel. Se a placa ou os canais exibidos não corresponderem ao veículo que você escolheu, o dado está errado — não é uma defasagem esperada.
</div>
