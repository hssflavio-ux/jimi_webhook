<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Ler e ajustar, sem ir até o veículo, a configuração da inteligência artificial das câmeras: a sensibilidade e o aviso de cada tipo de alerta de comportamento do motorista (DMS) e de assistência à condução (ADAS), além dos ajustes de velocidade. A tela mostra o que a câmera está usando hoje, deixa você mudar valor por valor e guarda uma leitura completa para repetir a mesma configuração em outras câmeras do mesmo modelo.</p>

<div class="callout warn">
<strong>Age em equipamento em operação — só para administrador.</strong> Cada <strong>Aplicar</strong> envia a configuração diretamente à câmera, que passa a usá-la assim que a receber. <strong>Aplicar em outras câmeras</strong> sobrescreve a configuração de IA atual de cada câmera de destino. A tela não tem botão de desfazer: para poder voltar atrás, leia o valor com <em>Ler agora</em> antes de mudar. Os demais usuários não veem o item no menu e recebem "acesso restrito ao administrador" ao tentar abrir o endereço direto.
</div>

<div class="mockup">
<div class="mockup-header">Configurações IA</div>
<div class="mockup-body">
    <div style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:14px">
        <div style="flex:1;min-width:240px">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);margin-bottom:4px">Equipamento</div>
            <div class="filter-mock">CAM-001 — JC371 (860112070347838)</div>
        </div>
        <span class="btn-mock">Ler tudo agora</span>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:var(--primary-soft);border:1px solid var(--hairline);border-radius:12px;padding:12px 16px;margin-bottom:14px;font-size:12px">
        <span>Última leitura completa em <strong>19/09/2026 14:02</strong> — 38 de 40 comando(s) capturado(s).</span>
        <span style="display:flex;gap:8px;flex-wrap:wrap">
            <span class="btn-mock outline" style="font-size:12px;padding:4px 12px">Baixar writeconfig.txt</span>
            <span class="btn-mock outline" style="font-size:12px;padding:4px 12px">Aplicar em outras câmeras deste modelo</span>
        </span>
    </div>

    <div style="border:1px solid var(--hairline);border-radius:12px;padding:14px 16px;background:#fff">
        <div style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:10px">Uso de telefone (DMS)</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px">
            <div>
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--primary);margin-bottom:6px">Sensibilidade</div>
                <div style="font-size:11px;background:var(--canvas-soft);border-radius:6px;padding:6px 8px;margin-bottom:8px">Última leitura em 19/09/2026 14:02: <span class="mono">EVENTSET,ACPW#,2,5</span></div>
                <div style="font-size:11px;margin-bottom:3px"><span class="pill-mock blue">P1</span> Sensibilidade</div>
                <div class="input-mock" style="border:1px solid var(--hairline);border-radius:6px;padding:6px 10px;font-size:12px;color:var(--muted);background:#fff">padrão: 2</div>
                <div style="font-size:10px;color:var(--muted);margin:3px 0 8px"><strong>Máscara:</strong> OFF / 1=Baixo / 2=Médio / 3=Alto</div>
                <div style="font-size:11px;margin-bottom:3px"><span class="pill-mock blue">P2</span> Duração mínima de uso do telefone</div>
                <div class="input-mock" style="border:1px solid var(--hairline);border-radius:6px;padding:6px 10px;font-size:12px;color:var(--muted);background:#fff">padrão: 5</div>
                <div style="font-size:10px;color:var(--muted);margin:3px 0 8px"><strong>Máscara:</strong> 1–255 (segundos)</div>
                <div style="display:flex;gap:6px;justify-content:flex-end"><span class="btn-mock outline" style="font-size:12px;padding:4px 12px">Ler agora</span><span class="btn-mock" style="font-size:12px;padding:4px 12px">Aplicar</span></div>
            </div>
            <div style="border-left:1px solid var(--hairline);padding-left:24px">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--primary);margin-bottom:6px">Alerta</div>
                <div style="font-size:11px;margin-bottom:3px"><span class="pill-mock blue">P1</span> Alerta na plataforma</div>
                <div class="input-mock" style="border:1px solid var(--hairline);border-radius:6px;padding:6px 10px;font-size:12px;color:var(--muted);background:#fff">padrão: 0</div>
                <div style="font-size:10px;color:var(--muted);margin:3px 0 8px"><strong>Máscara:</strong> 0 (fixo)</div>
                <div style="font-size:11px;margin-bottom:3px"><span class="pill-mock blue">P2</span> Intervalo de envio</div>
                <div class="input-mock" style="border:1px solid var(--hairline);border-radius:6px;padding:6px 10px;font-size:12px;color:var(--muted);background:#fff">padrão: 120</div>
                <div style="font-size:10px;color:var(--muted);margin:3px 0 8px"><strong>Máscara:</strong> 0=não reportar / 1=imediato / 2–64800 (segundos)</div>
                <div style="font-size:11px;color:var(--muted);margin-bottom:8px">mais campos, no mesmo formato…</div>
                <div style="display:flex;gap:6px;justify-content:flex-end"><span class="btn-mock outline" style="font-size:12px;padding:4px 12px">Ler agora</span><span class="btn-mock" style="font-size:12px;padding:4px 12px">Aplicar</span></div>
            </div>
        </div>
    </div>
</div>
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">O que é lido e o que é enviado ao equipamento</h4>
<table class="tbl-mock">
<tr><th>Botão</th><th>O que faz na câmera</th></tr>
<tr><td><strong>Ler agora</strong></td><td><strong>Só lê.</strong> Manda a pergunta ao equipamento e mostra a resposta no próprio quadro. Nada é alterado na câmera</td></tr>
<tr><td><strong>Ler tudo agora</strong></td><td><strong>Só lê.</strong> Faz a pergunta de cada ajuste do modelo que tem forma de consulta, uma de cada vez, com um pequeno intervalo entre elas. Ao terminar, monta o perfil de leitura completa (abaixo)</td></tr>
<tr><td><strong>Aplicar</strong></td><td><strong>Altera a câmera.</strong> Exige todos os campos do quadro preenchidos (senão avisa "Preencha todos os parâmetros antes de aplicar."), mostra o comando final e pede confirmação antes de enviar</td></tr>
<tr><td><strong>Aplicar em outras câmeras deste modelo</strong></td><td><strong>Altera várias câmeras.</strong> Envia o perfil salvo, comando a comando, para as câmeras marcadas (detalhes abaixo)</td></tr>
<tr><td>Baixar writeconfig.txt</td><td>Só baixa o arquivo do perfil salvo — não envia nada a nenhuma câmera</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Como a tela se organiza</h4>
<p>Escolha o <strong>equipamento</strong> na lista. Aparecem os equipamentos ativos que têm câmera — rastreadores, que não têm câmera, não entram na lista. A grade mostra <strong>só os ajustes que o modelo escolhido entende</strong>: cada modelo tem a própria lista, e para um modelo sem ajustes disponíveis a tela diz "O modelo não tem configuração disponível". Antes de escolher, o aviso é "Selecione o equipamento para verificar e configurar sua IA".</p>
<table class="tbl-mock">
<tr><th>No quadro</th><th>O que mostra</th></tr>
<tr><td>Título e descrição</td><td>O ajuste (por exemplo, "Sensibilidade — distração"). A <strong>sensibilidade</strong> e o <strong>alerta</strong> do mesmo evento aparecem juntos, num quadro só com duas colunas, porque na prática são mexidos juntos</td></tr>
<tr><td>Campos numerados (P1, P2…)</td><td>Um por valor do ajuste, com a descrição do que é. O campo mostra o valor de fábrica ("padrão: 2") e, abaixo, a <strong>máscara</strong>: os valores aceitos, como "OFF / 1=Baixo / 2=Médio / 3=Alto" ou "1–255 (segundos)"</td></tr>
<tr><td>Comando final</td><td>Sob os campos, o texto exato que será enviado. Enquanto faltar campo, aparece "preencha os campos para ver o comando final"</td></tr>
<tr><td>Última leitura</td><td>O último valor que a câmera informou e quando. Se você já aplicou um valor novo e ele ainda não foi confirmado, aparece também "Pedido pendente" — <strong>só se confirma lendo de novo</strong></td></tr>
<tr><td>Resultado</td><td>Fica embaixo do quadro: "enviando…", "enfileirado — aguardando…" e, quando chega, a resposta da câmera</td></tr>
</table>

<div class="callout tip">
<strong>Câmera desligada ou fora de contato.</strong> O comando entra na fila e é entregue quando ela reconectar. Se a resposta não vier em cerca de um minuto, o quadro passa a "na fila — a resposta aparece quando o equipamento reconectar (recarregue a tela mais tarde)". A resposta é guardada mesmo que você já tenha fechado a tela, então basta abrir de novo mais tarde. Em <strong>Ler tudo agora</strong>, se o equipamento não estiver online, a tela informa o último contato dele e pergunta se você quer continuar mesmo assim.
</div>

<div class="callout info">
<strong>"Ler agora" nem sempre foi conferido em equipamento real.</strong> Passe o mouse sobre o botão: a dica diz se a forma de consulta daquele ajuste foi confirmada em uma câmera real ou se ainda não. Quando não foi, usar o botão é justamente o que mede se ela funciona.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Perfil de leitura completa</h4>
<p>Quando <strong>Ler tudo agora</strong> termina — isto é, depois que <em>todas</em> as respostas chegaram ou desistiram, e não apenas quando o último pedido foi disparado — a tela reconstrói, a partir da resposta de cada ajuste, o comando de <em>configuração</em> equivalente e salva o conjunto como o <strong>perfil daquela câmera</strong>. Cada câmera tem um perfil só: uma nova leitura completa substitui a anterior. Ao selecionar a câmera, o quadro azul mostra <strong>"Última leitura completa em (data e hora) — N de M comando(s) capturado(s)"</strong>: M é quantos ajustes o modelo tem com consulta e N é quantos deles viraram comando. Quando nenhuma resposta pode ser aproveitada, o perfil não é salvo e a tela avisa.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Baixar writeconfig.txt</td><td>Baixa o perfil como texto, <strong>um comando por linha, sem comentários</strong> — o formato do arquivo de configuração que a câmera pode ler do cartão de memória. Só aparece quando há uma leitura completa salva</td></tr>
<tr><td>Aplicar em outras câmeras deste modelo</td><td>Abre um painel com as <strong>outras câmeras do mesmo modelo</strong> da lista e a relação dos comandos que serão enviados. Marque as câmeras de destino: sem nenhuma marcada, a tela pede "Selecione ao menos uma câmera de destino". Sem outra câmera do modelo, o painel diz que não há nenhuma</td></tr>
<tr><td>Enviar para as câmeras selecionadas</td><td>Pede confirmação dizendo quantos comandos vão para quantas câmeras e que a configuração de IA atual delas será <strong>sobrescrita</strong>. Confirmado, envia comando a comando, com um pequeno intervalo, e lista cada envio com o seu resultado</td></tr>
</table>

<div class="callout warn">
<strong>Confira a lista antes de enviar.</strong> O valor de cada ajuste é extraído da resposta da câmera por uma regra única para toda a tela — não por um leitor conferido comando a comando. Por isso o painel mostra a relação completa antes do envio: leia-a e só então confirme. Um ajuste cuja resposta não pôde ser lida simplesmente fica fora do perfil (é a diferença entre M e N no quadro azul).
</div>

<div class="callout info">
<strong>Só câmeras do mesmo modelo.</strong> Como cada modelo entende comandos diferentes, o perfil só pode ser aplicado a câmeras do modelo de onde ele foi lido. Para os outros modelos, faça uma leitura completa em uma câmera de cada.
</div>
