<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Mapa ao vivo com a última posição de todos os dispositivos da frota. Cada veículo aparece com o ícone do seu tipo, na cor do estado em que está agora. Atualização automática a cada 30 segundos.</p>

<div class="mockup">
<div class="mockup-header">Rastreamento — Mapa ao Vivo</div>
<div class="mockup-body">
    <div style="display:flex;gap:16px">
        <div style="width:200px;flex-shrink:0">
            <div style="font-size:12px;font-weight:600;margin-bottom:8px">Cliente</div>
            <div style="padding:8px;background:var(--primary-soft);border-radius:var(--radius-sm);font-size:13px;color:var(--primary);font-weight:600;margin-bottom:4px">Todos os clientes</div>
            <div style="margin-top:12px;font-size:12px;font-weight:600;margin-bottom:2px">Ativos no mapa</div>
            <div style="font-size:11px;color:var(--muted);margin-bottom:6px">2 de 3 no mapa · 1 sem posição</div>
            <div style="display:flex;align-items:center;gap:8px;padding:6px;font-size:13px"><span style="width:10px;height:10px;border-radius:50%;background:#098551"></span> CAM-001<span class="mono" style="font-size:10px;color:var(--muted)">IGN: ON · 18/07 14:32</span></div>
            <div style="display:flex;align-items:center;gap:8px;padding:6px;font-size:13px"><span style="width:10px;height:10px;border-radius:50%;background:#c83532"></span> CAM-002<span class="mono" style="font-size:10px;color:var(--muted)">IGN: OFF · 18/07 09:10</span></div>
            <div style="display:flex;align-items:center;gap:8px;padding:6px;font-size:13px"><span style="width:10px;height:10px;border-radius:50%;background:#098551"></span> CAM-003</div>
            <div style="margin-top:8px"><div class="input-mock dim" style="font-size:12px;width:100%">Buscar ativo...</div></div>
        </div>
        <div class="map-mock" style="flex:1;height:320px;background:url('/assets/img/wiki_map_city.png') center/cover no-repeat">
            <div class="map-mock-dot" style="background:#098551;top:30%;left:30%;width:12px;height:12px;border:2px solid #fff"></div>
            <div class="map-mock-dot" style="background:#098551;top:45%;left:55%;width:12px;height:12px;border:2px solid #fff"></div>
            <div class="map-mock-dot" style="background:#c83532;top:55%;left:70%;width:12px;height:12px;border:2px solid #fff"></div>
            <span class="map-credit">© OpenStreetMap</span>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar cliente</td><td>O seletor <strong>Cliente</strong> só aparece para administradores e revendedores. Trocar recarrega o mapa e a lista com os dados do cliente escolhido; "Todos os clientes" mostra tudo o que o seu perfil enxerga</td></tr>
<tr><td>Clicar num ativo na lista</td><td>Centraliza o mapa na última posição daquele dispositivo</td></tr>
<tr><td>Caixa de seleção do ativo</td><td>Liga e desliga o veículo no mapa. A lista é o filtro do mapa. Ativo sem posição conhecida tem a caixa desabilitada. Os botões <strong>Todos</strong> e <strong>Nenhum</strong> marcam ou desmarcam a lista inteira, e o contador mostra "N de M no mapa"</td></tr>
<tr><td>Buscar ativo</td><td>Filtra a lista em tempo real, pela placa (ou pelo IMEI)</td></tr>
<tr><td>Auto-atualização</td><td>As posições, o estado e as informações da lista se atualizam sozinhos a cada 30 segundos</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:20px 0 8px">O que cada informação quer dizer</h4>
<ul style="font-size:13px;line-height:1.8;color:var(--body)">
    <li><strong>Cor do marcador e legenda do mapa:</strong> <em>Em movimento</em>, <em>Ocioso (motor ligado)</em>, <em>Parado (ignição desligada)</em>, <em>Excesso de velocidade</em> (acima do limite do equipamento ou do cliente, com a ignição ligada) e <em>Sem comunicação</em>. O estado vem da <strong>última posição</strong> do veículo; passou de 30 minutos sem posição nova, ele vira "Sem comunicação"</li>
    <li><strong>Bolinha ao lado da placa:</strong> verde quando o veículo tem posição recente, cinza quando está sem comunicação. Ela segue a posição, não a comunicação — um equipamento que fala com a bycamera mas está sem sinal de GPS aparece com bolinha cinza e horário recente</li>
    <li><strong>Sob a placa:</strong> <em>IGN: ON/OFF</em> é a ignição atual e o horário ao lado é o da <strong>última comunicação</strong> do equipamento (qualquer transmissão, não só posição). Quando há motorista identificado por reconhecimento facial, o nome dele aparece numa terceira linha e some sozinho quando a sessão termina (a câmera reconhece outro motorista ou a ignição desliga)</li>
    <li><strong>Balão do marcador:</strong> placa, estado, velocidade, ignição, motorista (quando há) e o horário da posição</li>
</ul>
