<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Mapa ao vivo com a última posição de todos os dispositivos da frota. Atualização automática a cada 60 segundos.</p>

<div class="mockup">
<div class="mockup-header">Rastreamento — Mapa ao Vivo</div>
<div class="mockup-body">
    <div style="display:flex;gap:16px">
        <div style="width:200px;flex-shrink:0">
            <div style="font-size:12px;font-weight:600;margin-bottom:8px">Clientes</div>
            <div style="padding:8px;background:var(--primary-soft);border-radius:var(--radius-sm);font-size:13px;color:var(--primary);font-weight:600;margin-bottom:4px">Frota Principal</div>
            <div style="padding:8px;font-size:13px;color:var(--muted);margin-bottom:4px">Construtora ABC</div>
            <div style="margin-top:12px;font-size:12px;font-weight:600;margin-bottom:8px">Ativos</div>
            <div style="display:flex;align-items:center;gap:8px;padding:6px;font-size:13px"><span style="width:10px;height:10px;border-radius:50%;background:#098551"></span> CAM-001 JC182</div>
            <div style="display:flex;align-items:center;gap:8px;padding:6px;font-size:13px"><span style="width:10px;height:10px;border-radius:50%;background:#c83532"></span> CAM-002 JC371</div>
            <div style="display:flex;align-items:center;gap:8px;padding:6px;font-size:13px"><span style="width:10px;height:10px;border-radius:50%;background:#098551"></span> CAM-003 JC450</div>
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
<tr><td>Selecionar cliente</td><td>Mapa e lista de ativos são recarregados com dados do cliente escolhido</td></tr>
<tr><td>Clicar em um ativo na lista</td><td>Centraliza o mapa na última posição daquele dispositivo</td></tr>
<tr><td>Buscar por nome/IMEI</td><td>Filtra a lista de ativos em tempo real</td></tr>
<tr><td>Marcador verde</td><td>Dispositivo online (última comunicação &le; 5 min)</td></tr>
<tr><td>Marcador vermelho</td><td>Dispositivo offline (última comunicação > 5 min)</td></tr>
<tr><td>Auto-atualização</td><td>As posições se atualizam sozinhas a cada 60 segundos</td></tr>
</table>
