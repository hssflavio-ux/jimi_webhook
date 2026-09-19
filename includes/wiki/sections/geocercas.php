<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Desenhar áreas no mapa e ser avisado quando um veículo entra ou sai delas — pátio, base do cliente, região proibida, ponto de coleta.</p>

<div class="mockup">
<div class="mockup-header">Geocercas — Desenho no mapa</div>
<div class="mockup-body">
    <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div style="flex:1;min-width:220px">
            <div class="form-mock" style="display:flex;flex-direction:column;gap:10px">
                <div class="form-mock-field"><label>Nome</label><div class="input-mock">Pátio Central</div></div>
                <div class="form-mock-field"><label>Tipo</label><div class="input-mock">Geocerca</div></div>
                <div class="form-mock-field"><label>Formato</label>
                    <div style="display:flex;gap:10px;margin-top:4px;font-size:13px">
                        <span class="pill-mock blue">● Círculo</span><span class="pill-mock gray">○ Polígono</span>
                    </div>
                </div>
                <div class="form-mock-field"><label>Raio (metros)</label><div class="input-mock">200</div></div>
                <div class="form-mock-field"><label>Alertar em</label><div class="input-mock">Entrada e saída</div></div>
                <div class="form-mock-field"><label>E-mails de alerta (até 3)</label><div class="input-mock dim">operacao@empresa.com.br</div></div>
            </div>
        </div>
        <div style="flex:1;min-width:220px">
            <div class="map-mock" style="height:200px;background:url('/assets/img/wiki_map_streets.png') center/cover no-repeat">
                <svg style="position:absolute;inset:0;width:100%;height:100%" viewBox="0 0 100 100" preserveAspectRatio="none">
                    <circle cx="50" cy="50" r="26" fill="#0052ff" fill-opacity=".12" stroke="#0052ff" stroke-width="2" vector-effect="non-scaling-stroke"/>
                </svg>
                <div class="map-mock-dot" style="top:50%;left:50%"></div>
                <span class="map-credit">© OpenStreetMap</span>
            </div>
            <div style="margin-top:10px;font-size:12px;color:var(--muted)">Equipamentos vinculados: <strong style="color:var(--ink)">CAM-001, FJR7B59</strong></div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que preencher</th></tr>
<tr><td>Tipo</td><td><strong>Geocerca</strong> (área que gera eventos e alertas) ou <strong>Ponto de Interesse</strong> (referência no mapa)</td></tr>
<tr><td>Formato</td><td><strong>Círculo</strong> — clique no mapa define o centro e o campo Raio define o tamanho. <strong>Polígono</strong> — cada clique adiciona um vértice e o botão fecha a área (mínimo de 3 vértices)</td></tr>
<tr><td>Alertar em</td><td>Entrada e saída, somente entrada, somente saída ou não alertar. <strong>O evento é sempre registrado no relatório</strong>; esta opção decide apenas se o sistema avisa</td></tr>
<tr><td>E-mails de alerta</td><td>Até 3 endereços que recebem aviso das travessias desta cerca (além do sino e do pop-up)</td></tr>
<tr><td>Cor</td><td>Como a cerca aparece desenhada no mapa</td></tr>
<tr><td>Equipamentos</td><td>Quais veículos são avaliados contra esta cerca. <strong>Sem nenhum vinculado, a cerca não gera evento nenhum</strong></td></tr>
</table>

<div class="callout tip">
<strong>Desenhar uma cerca não gera entradas retroativas.</strong> Ao criar uma cerca sobre a garagem, os veículos que já estão lá dentro não disparam uma enxurrada de "entradas": o sistema apenas anota onde cada um estava. O primeiro evento só sai numa travessia de verdade. Editar a geometria de uma cerca existente reinicia essa contagem pelo mesmo motivo.
</div>

<div class="callout info">
<strong>Veículo parado na borda não gera dezenas de eventos.</strong> A fronteira funciona como uma faixa: para registrar a saída, o veículo precisa se afastar cerca de 50 metros da borda. Um caminhão estacionado exatamente em cima da linha produz um par de eventos, não trinta.
</div>
