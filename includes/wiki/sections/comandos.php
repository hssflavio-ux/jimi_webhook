<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Enviar comandos remotos para os equipamentos. A tela oferece <strong>atalhos prontos</strong> para os comandos mais comuns (status, reiniciar, vídeo ao vivo, envio de gravações) e acompanha a resposta de cada envio.</p>

<div class="mockup">
<div class="mockup-header">Comandos — Envio e Monitoramento</div>
<div class="mockup-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div>
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Enviar Comando</div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <div class="filter-mock">Equipamento: CAM-001 (860112070347838)</div>
                <div class="filter-mock">Comando: Status do Dispositivo</div>
                <span class="btn-mock" style="align-self:flex-start">Enviar</span>
            </div>
        </div>
        <div>
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Histórico Recente</div>
            <table class="tbl-mock" style="font-size:12px">
            <tr><th>Comando</th><th>IMEI</th><th>Status</th><th>Resposta</th></tr>
            <tr><td>Status</td><td class="mono">860112...</td><td><span class="pill-mock green">Executado</span></td><td style="font-size:11px">Battery:12.4V; Mode:SLEEP</td></tr>
            <tr><td>Vídeo Ao Vivo</td><td class="mono">869058...</td><td><span class="pill-mock blue">Enviado</span></td><td style="font-size:11px;color:var(--muted)">Aguardando dispositivo...</td></tr>
            </table>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Selecionar equipamento + comando + Enviar</td><td>O comando é enviado e o sistema aguarda a resposta do equipamento</td></tr>
<tr><td>Equipamento online</td><td>Status muda para "Executado" e a resposta aparece em poucos segundos</td></tr>
<tr><td>Equipamento offline</td><td>Status fica "Enviado" — a resposta chega quando o equipamento voltar a se conectar</td></tr>
<tr><td>Acompanhamento</td><td>A tela verifica a resposta automaticamente por alguns minutos</td></tr>
<tr><td>Sem resposta</td><td>Se o equipamento não responder, a tela avisa que o comando ficou aguardando na fila</td></tr>
</table>

<div class="callout tip">
<strong>Atalhos disponíveis:</strong> Status, Informações do Dispositivo, Reiniciar, Vídeo Ao Vivo, Reprodução de Vídeo, Envio de Vídeo e Configuração.
</div>

<div class="callout info">
<strong>Comandos específicos de modelo.</strong> Alguns comandos só se aplicam a determinados modelos de câmera. A tela desabilita os equipamentos incompatíveis automaticamente — se um comando pedir parâmetros, os campos aparecem junto do atalho. Comandos universais (que funcionam em todos os modelos) não têm essa restrição.
</div>
