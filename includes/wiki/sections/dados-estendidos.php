<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Mostrar, numa <strong>lista única ordenada por data/hora</strong>, dois conjuntos de dados que o equipamento já envia e o sistema já grava, mas que até a v4.24.0 não tinham tela nenhuma: o <strong>modo, o tipo e o motivo de cada transmissão de GPS</strong> (cada posição registrada) e a <strong>extensão de status do terminal</strong> (tensão, bateria, rede, tráfego de dados). É uma tela de <strong>diagnóstico</strong>, não de operação — não envia comando nenhum ao equipamento, só lê o que já chegou. A tela é <strong>exclusiva do administrador</strong>.</p>

<div class="mockup">
<div class="mockup-header">Dados Estendidos</div>
<div class="mockup-body">
    <div class="filter-bar-mock">
        <div class="filter-mock">Cliente: Todos</div>
        <div class="filter-mock">Placa: ABC1D23</div>
        <div class="filter-mock">22/09/2026 - 22/09/2026</div>
    </div>
    <table class="tbl-mock">
    <tr><th>Data/Hora</th><th>Tipo</th><th>Motivo da transmissão</th><th>Modo</th><th>Posicionamento</th><th>Velocidade</th><th>Dados da extensão</th></tr>
    <tr><td>22/09/2026 14:32:10</td><td><span class="pill-mock blue">Posição GPS</span></td><td>0 — Envio por intervalo de tempo</td><td><span class="pill-mock green">Tempo real</span></td><td>GPS</td><td>42.5 km/h</td><td>—</td></tr>
    <tr><td>22/09/2026 14:31:40</td><td><span class="pill-mock">Status do terminal</span></td><td>—</td><td>—</td><td>—</td><td>—</td><td>Tensão: 12.4 V · Bateria: 87 %</td></tr>
    <tr><td>22/09/2026 14:31:00</td><td><span class="pill-mock blue">Posição GPS</span></td><td>3 — Envio por mudança de status do ACC</td><td><span class="pill-mock yellow">Reenvio</span></td><td>LBS</td><td>0.0 km/h</td><td>—</td></tr>
    </table>
</div>
</div>

<p>O filtro segue o padrão das telas de relatório: <strong>Cliente</strong> (só para quem enxerga mais de um), <strong>Placa</strong> (obrigatória) e <strong>Período</strong> (teto de 31 dias). Clique em <strong>Gerar</strong> para listar. Cada linha da grade é <strong>uma coisa que o equipamento enviou</strong> — uma posição de GPS ou um status do terminal —, na ordem em que aconteceu; o cabeçalho <strong>Data/Hora</strong> inverte a ordem (padrão: mais recente primeiro).</p>

<table class="tbl-mock">
<tr><th>Tipo da linha</th><th>Preenche as colunas</th><th>Fonte</th></tr>
<tr><td>Posição GPS</td><td>Motivo da transmissão, Modo, Posicionamento e Velocidade</td><td><span class="mono">gps_data</span></td></tr>
<tr><td>Status do terminal · Leitor serial</td><td>Dados da extensão — as demais colunas ficam com “—”, porque não se aplicam a esse tipo de mensagem</td><td><span class="mono">device_events</span></td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Colunas de uma posição GPS</h4>
<table class="tbl-mock">
<tr><th>Coluna</th><th>Significa</th></tr>
<tr><td>Motivo da transmissão</td><td>Por que o equipamento enviou <strong>aquela</strong> posição. Aparece como <strong>código — nome</strong> (ex.: <span class="mono">3 — Envio por mudança de status do ACC</span>). O código é o número que o equipamento manda; o nome vem da tabela do fabricante, que a documentação publica em hexadecimal (<span class="mono">0x00</span> a <span class="mono">0x0F</span>) e o sistema já converte</td></tr>
<tr><td>Modo</td><td><strong>Tempo real</strong>: a posição chegou assim que foi capturada. <strong>Reenvio</strong>: o equipamento a guardou (por falta de sinal) e enviou depois, junto com o carimbo de hora ORIGINAL do momento em que foi capturada</td></tr>
<tr><td>Posicionamento</td><td>Tecnologia usada para calcular a posição daquele ponto: <strong>GPS</strong> (satélite), <strong>LBS</strong> (antena de celular) ou <strong>WiFi</strong> (rede próxima). LBS e WiFi são menos precisos que GPS — é comum aparecerem quando o veículo está numa garagem ou área fechada</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Motivos da transmissão (tabela do fabricante)</h4>
<table class="tbl-mock">
<tr><th>Código</th><th>Hex</th><th>Motivo</th></tr>
<tr><td class="mono">0</td><td class="mono">0x00</td><td>Envio por intervalo de tempo</td></tr>
<tr><td class="mono">1</td><td class="mono">0x01</td><td>Envio por intervalo de distância</td></tr>
<tr><td class="mono">2</td><td class="mono">0x02</td><td>Envio por ponto de inflexão</td></tr>
<tr><td class="mono">3</td><td class="mono">0x03</td><td>Envio por mudança de status do ACC</td></tr>
<tr><td class="mono">4</td><td class="mono">0x04</td><td>Reenvio do último ponto GPS ao voltar a ficar parado</td></tr>
<tr><td class="mono">5</td><td class="mono">0x05</td><td>Envio do último ponto válido ao recuperar a rede</td></tr>
<tr><td class="mono">6</td><td class="mono">0x06</td><td>Atualização de efemérides com envio forçado de GPS</td></tr>
<tr><td class="mono">7</td><td class="mono">0x07</td><td>Envio por acionamento da tecla lateral</td></tr>
<tr><td class="mono">8</td><td class="mono">0x08</td><td>Envio após ligar o equipamento</td></tr>
<tr><td class="mono">9</td><td class="mono">0x09</td><td>Envio por comando GPSON</td></tr>
<tr><td class="mono">10</td><td class="mono">0x0A</td><td>Envio da última posição com o equipamento parado (hora atualizada)</td></tr>
<tr><td class="mono">11</td><td class="mono">0x0B</td><td>Envio após consulta de dados WiFi</td></tr>
<tr><td class="mono">12</td><td class="mono">0x0C</td><td>Envio por comando LJDW (localizar imediatamente)</td></tr>
<tr><td class="mono">13</td><td class="mono">0x0D</td><td>Envio da última posição com o equipamento parado</td></tr>
<tr><td class="mono">14</td><td class="mono">0x0E</td><td>Envio Gpsdup (periódico com o equipamento parado)</td></tr>
<tr><td class="mono">15</td><td class="mono">0x0F</td><td>Envio após sair do modo de rastreamento</td></tr>
</table>

<div class="callout warn">
<strong>Código fora dessa tabela aparece como “Sem descrição do fabricante”.</strong> Alguns equipamentos mandam códigos acima de 15 (já vistos em produção: 27 e 28), que a documentação do fabricante não explica. O sistema mostra o número como chegou e <strong>não inventa um significado</strong> — se a Jimi publicar o que eles querem dizer, a tabela é ampliada.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Linhas de extensão do terminal — o que é decodificado</h4>
<p>Cada mensagem do equipamento vem marcada com um <strong>tipo</strong> (o "Extension ID" do fabricante), mostrado na coluna Tipo. Só dois tipos existem hoje:</p>
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
