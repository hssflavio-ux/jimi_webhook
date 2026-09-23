<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Mostrar dois conjuntos de dados que o equipamento já envia e o sistema já grava, mas que até a v4.24.0 não tinham tela nenhuma: o <strong>modo/tipo de transmissão do GPS</strong> (cada posição registrada) e a <strong>extensão de status do terminal</strong> (tensão, bateria, rede, tráfego de dados). É uma tela de <strong>diagnóstico</strong>, não de operação — não envia comando nenhum ao equipamento, só lê o que já chegou. A tela é <strong>exclusiva do administrador</strong>.</p>

<div class="mockup">
<div class="mockup-header">Dados Estendidos — Transmissão GPS</div>
<div class="mockup-body">
    <div class="filter-bar-mock">
        <div class="filter-mock">Cliente: Todos os clientes</div>
        <div class="filter-mock">Equipamento: CAM-001 (860112070347838)</div>
        <div class="filter-mock">22/09/2026 - 22/09/2026</div>
    </div>
    <table class="tbl-mock">
    <tr><th>Data/Hora</th><th>Modo</th><th>Posicionamento</th><th>Post Method</th><th>Velocidade</th></tr>
    <tr><td>22/09/2026 14:32</td><td><span class="pill-mock green">Tempo real</span></td><td><span class="pill-mock blue">GPS</span></td><td class="mono">0</td><td>42 km/h</td></tr>
    <tr><td>22/09/2026 14:31</td><td><span class="pill-mock yellow">Reenvio</span></td><td><span class="pill-mock blue">LBS</span></td><td class="mono">27</td><td>0 km/h</td></tr>
    </table>
</div>
</div>

<p>A tela tem <strong>duas abas</strong>, cada uma com o mesmo filtro (cliente, equipamento — obrigatório — e período, teto de 31 dias):</p>

<table class="tbl-mock">
<tr><th>Aba</th><th>O que mostra</th><th>Fonte</th></tr>
<tr><td>Transmissão GPS</td><td>Um registro por posição recebida: modo (tempo real ou reenvio), tecnologia usada para localizar (GPS/LBS/WiFi), o código bruto de "post method" e a velocidade</td><td><span class="mono">gps_data</span></td></tr>
<tr><td>Extensão do Terminal</td><td>Status que o equipamento reporta sobre si mesmo — não é posição — decodificado campo a campo quando o fabricante documenta o significado</td><td><span class="mono">device_events</span></td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Transmissão GPS — colunas</h4>
<table class="tbl-mock">
<tr><th>Coluna</th><th>Significa</th></tr>
<tr><td>Modo</td><td><strong>Tempo real</strong>: a posição chegou assim que foi capturada. <strong>Reenvio</strong>: o equipamento a guardou (por falta de sinal) e enviou depois, junto com o carimbo de hora ORIGINAL do momento em que foi capturada</td></tr>
<tr><td>Posicionamento</td><td>Tecnologia usada para calcular a posição daquele ponto: <strong>GPS</strong> (satélite), <strong>LBS</strong> (antena de celular) ou <strong>WiFi</strong> (rede próxima). LBS e WiFi são menos precisos que GPS — é comum aparecerem quando o veículo está numa garagem ou área fechada</td></tr>
<tr><td>Post Method</td><td>Um código que o fabricante envia mas <strong>nunca documentou</strong> o significado — mostrado do jeito que chegou, sem tradução. Valores já vistos em produção: 0, 2, 3, 4, 10, 14, 27, 28</td></tr>
</table>

<div class="callout info">
<strong>Post Method não tem legenda porque o fabricante não publicou uma.</strong> Diferente de Modo e Posicionamento — que têm tabela oficial —, este código só aparece em exemplos de payload da documentação, sem explicação. Nenhum significado foi inventado para ele.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Extensão do Terminal — o que é decodificado</h4>
<p>Cada linha do equipamento vem marcada com um <strong>tipo</strong> (o "Extension ID" do fabricante). Só dois tipos existem hoje:</p>
<table class="tbl-mock">
<tr><th>Tipo</th><th>Traz</th></tr>
<tr><td>Status do terminal</td><td>Tensão, tensão externa, % de bateria, se está carregando, tráfego de dados do dia, tipo de rede (WiFi/GSM/WCDMA/LTE), HDOP (precisão do GPS) e o ICCID do chip</td></tr>
<tr><td>Leitor serial (pass-through)</td><td>Dado bruto de um periférico ligado à porta serial do equipamento (ex.: leitor de cartão), repassado sem interpretação</td></tr>
</table>

<div class="callout warn">
<strong>Nem tudo é decodificado.</strong> O ICCID (chip do celular) e o conteúdo do leitor serial chegam num formato que exige decodificação própria (BCD e base64); esta versão os mostra <strong>truncados, sem traduzir</strong>, em vez de arriscar uma tradução errada. Se um relatório precisar desses dois campos decodificados, é trabalho futuro.
</div>

<div class="callout">
<strong>Quem pode abrir Dados Estendidos.</strong> Só o <strong>administrador</strong>. Não é concedida por grupo de permissão e não aparece no menu dos demais usuários; quem tentar abrir o endereço direto recebe o aviso de acesso restrito.
</div>
