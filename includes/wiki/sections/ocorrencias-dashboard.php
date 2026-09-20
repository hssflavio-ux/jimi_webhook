<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Painel operacional de gestão das ocorrências de monitoramento do motorista. É <strong>o coração do produto</strong>. As câmeras inteligentes detectam comportamentos de risco (distração, uso de celular, fadiga, sem cinto) e geram ocorrências automaticamente. O operador visualiza a fila em tempo real e trata cada caso.</p>

<div class="mockup">
<div class="mockup-header">Dashboard de Ocorrências — Fila de Tratativa</div>
<div class="mockup-body">
    <!-- KPIs -->
    <div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">
        <div class="kpi-box"><div class="kpi-label">Ocorrências</div><div class="kpi-val">87</div></div>
        <div class="kpi-box yellow"><div class="kpi-label">Aguardando Tratativa</div><div class="kpi-val">5</div></div>
        <div class="kpi-box green"><div class="kpi-label">Dispositivos Online</div><div class="kpi-val">18</div></div>
        <div class="kpi-box red"><div class="kpi-label">Dispositivos Offline</div><div class="kpi-val">2</div></div>
    </div>
    <!-- Risk Bar -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:10px 16px;background:#fafbfc;border-radius:var(--radius-lg)">
        <div style="font-size:12px;font-weight:600;color:var(--muted)">Distribuição de Risco:</div>
        <div style="flex:1;height:8px;background:#eee;border-radius:4px;display:flex;overflow:hidden">
            <div style="width:45%;background:#098551"></div>
            <div style="width:30%;background:#f0a020"></div>
            <div style="width:25%;background:#c83532"></div>
        </div>
        <div style="font-size:11px;display:flex;gap:12px"><span style="color:#098551">Baixo 45%</span><span style="color:#f0a020">Médio 30%</span><span style="color:#c83532">Alto 25%</span></div>
    </div>
    <!-- Grade -->
    <table class="tbl-mock">
    <tr><th>Placa</th><th>Motorista</th><th>Tipo</th><th>Último Alarme</th><th>Risco</th><th>Status</th><th>Qtd</th><th>Vídeo</th></tr>
    <tr><td class="mono">FJR7B59</td><td>Não identificado</td><td>Distração</td><td>18/07/2026 14:32</td><td><span class="pill-mock red">Alto</span></td><td><span class="pill-mock yellow">Aguardando</span></td><td>2</td><td><span class="pill-mock green">Disponível</span></td></tr>
    <tr><td class="mono">RQP2A41</td><td>Carlos Souza</td><td>Uso de Celular</td><td>18/07/2026 14:28</td><td><span class="pill-mock yellow">Médio</span></td><td><span class="pill-mock blue">Em Tratativa</span></td><td>1</td><td>Pedir vídeo</td></tr>
    <tr><td class="mono">GHT5C08</td><td>Não identificado</td><td>Sem Cinto</td><td>18/07/2026 14:15</td><td><span class="pill-mock green">Baixo</span></td><td><span class="pill-mock green">Resolvida</span></td><td>1</td><td><span class="pill-mock green">Disponível</span></td></tr>
    </table>
</div>
</div>

<h4 style="font-size:14px;font-weight:600;margin:20px 0 8px">A fila</h4>
<ul style="font-size:13px;line-height:1.8;color:var(--body)">
    <li><strong>Período:</strong> a tela abre mostrando <strong>só as ocorrências de hoje</strong> (pela data do último alarme). Mude <em>De</em> e <em>Até</em> para olhar outros dias; o período máximo é de 31 dias</li>
    <li><strong>Filtros:</strong> Status, Risco, período e uma <strong>Busca</strong> por placa ou motorista. Os indicadores e a barra de distribuição de risco acompanham os filtros que você escolheu</li>
    <li><strong>Dispositivos Online / Offline:</strong> contam os equipamentos <strong>ativos</strong> do cliente pelo último sinal recebido — Online é quem se comunicou nos últimos 5 minutos. É o mesmo número do contador On/Off do topo da tela</li>
    <li><strong>Atualização automática:</strong> a grade e os indicadores se atualizam sozinhos a cada 15 segundos; desmarque a caixa "Atualização automática" para congelar a tela enquanto você trabalha, e use <em>Atualizar</em> quando quiser</li>
    <li><strong>Vídeo:</strong> a coluna mostra <em>Disponível</em> quando a ocorrência já tem vídeo, o botão <em>Pedir vídeo</em> quando ainda não tem, e "—" quando a ocorrência não tem função de vídeo (veja adiante)</li>
</ul>

<h4 style="font-size:14px;font-weight:600;margin:20px 0 8px">Tela de Tratativa (Detalhe da Ocorrência)</h4>
<p>Ao clicar em <em>Abrir</em> em uma ocorrência, abre-se a tela de detalhe com:</p>
<ul style="font-size:13px;line-height:1.8;color:var(--body)">
    <li><strong>Player de vídeo duplo</strong> — quando a câmera tem duas lentes, os vídeos da
        <strong>câmera 1</strong> e da <strong>câmera 2</strong> aparecem lado a lado e tocam ao
        mesmo tempo, cada um aberto num quadro do <em>meio</em> do vídeo (o snapshot). O play roda
        na própria tela — baixar é opcional. Câmera de lente única mostra só um player.
        Funciona com vídeos MP4 e com gravações em formato <code>.ts</code> das câmeras JT/T.
        Se um dos canais não tem vídeo, o quadro dele diz "Sem mídia neste canal"</li>
    <li><strong>Alarmes agrupados</strong> — todos os alarmes que compõem a ocorrência, numa tabela com <strong>Nº</strong>, nome do alarme, data/hora, <strong>Velocidade</strong> e o vídeo de cada um (<em>Ver</em> quando já existe, <em>Pedir vídeo</em> quando não)</li>
    <li><strong>Mapa de localização</strong> com um marcador para <strong>cada alarme</strong> que tem posição de GPS. O balão traz o número do alarme ("Alarme 2 de 5"), o nome, o horário e a velocidade</li>
    <li><strong>Notas de Tratativa</strong> para o operador registrar observações, e a caixa <strong>Falso positivo</strong> para sinalizar alarmes incorretos</li>
    <li><strong>Transições de status:</strong> Iniciar Tratativa → Resolver ou Descartar</li>
</ul>

<div class="callout tip">
<strong>Número e velocidade de cada alarme.</strong> O <strong>Nº</strong> da tabela é o mesmo que aparece no balão do mapa: a linha 2 da tabela é o "Alarme 2" do mapa, mesmo que algum alarme do grupo não tenha marcador por estar sem posição de GPS. A <strong>Velocidade</strong> é a que o equipamento informou junto com o alarme e vale para <strong>qualquer tipo</strong> de alarme (distração, uso de celular, ADAS, excesso de velocidade...); no Excesso de Velocidade é a velocidade que disparou o alarme. Aparece "—" quando o equipamento não informou nenhuma velocidade naquele alarme.
</div>

<div class="callout tip">
<strong>Como o veículo é identificado.</strong> A coluna <em>Placa</em> e o cabeçalho do detalhe mostram o <strong>nome cadastrado no equipamento</strong> (campo <em>Nome</em>, em <em>Cadastros › Equipamentos</em>) — não o IMEI, que só aparece quando o Nome está em branco. Se o texto exibido não estiver certo, o cadastro do equipamento é o lugar para corrigir.
</div>

<div class="callout info">
<strong>Nem toda ocorrência tem vídeo.</strong> Ocorrência de <strong>dirigibilidade</strong> (arrancada, freada, curva, velocidade, colisão, capotamento) e ocorrência de <strong>equipamento sem câmera</strong> (rastreador) não têm coluna de vídeo, player nem botão de pedir vídeo — na grade a coluna mostra "—". Esses eventos aparecem em <em>Relatórios › Alertas Dirigibilidade</em>.
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Abrir uma ocorrência</td><td>Abre a tela de detalhe com vídeo, alarmes agrupados e mapa. <em>Fechar</em> volta para a lista de onde você veio (o dashboard ou o Relatório de Ocorrências, com os filtros que estavam)</td></tr>
<tr><td>Iniciar Tratativa</td><td>Só aparece para ocorrência "Aguardando". O status muda para "Em Tratativa" e o sistema registra o usuário e a data/hora</td></tr>
<tr><td>Resolver</td><td>Status muda para "Resolvida"</td></tr>
<tr><td>Descartar</td><td>Status muda para "Descartada"</td></tr>
<tr><td>Falso positivo (caixa)</td><td>Vale junto com qualquer um dos botões acima. A ocorrência fica marcada como falso positivo — dá para filtrá-la no Relatório de Ocorrências, e ela <strong>não entra no Mapa de Risco</strong>. Os indicadores deste painel continuam contando a ocorrência</td></tr>
<tr><td>Notas de Tratativa</td><td>Texto salvo junto com a transição de status. Depois de Resolvida ou Descartada, o formulário some e o bloco <strong>Histórico de Tratativa</strong> mostra a nota e o "Tratado em"</td></tr>
<tr><td>Pedir vídeo / Solicitar vídeo</td><td>Pede à câmera o vídeo daquele alarme. O botão passa a "Solicitado", mas o vídeo <strong>não chega na hora</strong>: a câmera precisa gerar e enviar, então volte à ocorrência depois</td></tr>
</table>

<div class="callout info">
<strong>Fluxo completo:</strong> Câmera detecta o evento → o sistema registra a ocorrência → o operador vê na fila → trata (vê o vídeo, classifica, resolve). Do evento no veículo até aparecer na tela, leva poucos segundos.
</div>
