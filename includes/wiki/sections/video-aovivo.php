<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Assistir ao vivo às câmeras dos veículos. Escolha o equipamento, marque os canais da câmera que quer abrir, clique em <strong>Iniciar Transmissão</strong> e aguarde alguns segundos até a imagem aparecer. Equipamentos sem câmera (rastreadores) não aparecem na lista.</p>

<div class="mockup">
<div class="mockup-header">Vídeo ao Vivo</div>
<div class="mockup-body">
    <div style="margin-bottom:12px;display:flex;gap:10px;align-items:center">
        <div class="filter-mock">Placa: CAM-001 (Online)</div>
        <div class="filter-mock">Canais: CH1 CH2</div>
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
        <span class="filter-mock" style="font-size:12px">CH1 · frontal</span>
        <span class="filter-mock" style="font-size:12px">CH2 · interna</span>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar equipamento + canais + Iniciar</td><td>A câmera é acionada e o vídeo abre em alguns segundos (normalmente entre 5 e 30). Administradores e revendedores escolhem antes o <strong>Cliente</strong>; com "Todos os clientes" o nome do cliente aparece na lista de equipamentos</td></tr>
<tr><td>Canais (CH1, CH2…)</td><td>Botões de seleção <strong>múltipla</strong>: todos vêm ligados, e cada canal ligado abre um quadro, lado a lado. Desligar um canal que ninguém está olhando poupa a franquia de dados do SIM. O último canal ligado não pode ser desligado — para encerrar tudo use <strong>Parar</strong>. Câmeras da linha JIMI oferecem no máximo dois canais</td></tr>
<tr><td>Expandir / Recolher</td><td>Com mais de um canal aberto, cada quadro ganha esse botão no canto: mostra só aquele canal ocupando toda a área do player, e recolhe de volta ao mosaico</td></tr>
<tr><td>Parar</td><td>Encerra a transmissão de todos os canais</td></tr>
<tr><td>Áudio</td><td>Com mais de um quadro aberto, todos começam <strong>sem áudio</strong> (quatro trilhas juntas seriam só ruído); ative o som no controle de volume do quadro que quiser ouvir</td></tr>
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
