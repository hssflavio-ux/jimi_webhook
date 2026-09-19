<?php defined('WIKI_SECTION') || exit; ?>
<p>Um alarme importante não depende mais de alguém estar com o dashboard aberto na tela certa. Quando uma ocorrência nova é criada, o sistema avisa pelos canais que você escolher: <strong>sino</strong>, <strong>pop-up</strong>, <strong>som</strong> e <strong>e-mail</strong>.</p>

<div class="mockup">
<div class="mockup-header">Sino no topo da tela</div>
<div class="mockup-body">
    <div style="display:flex;justify-content:flex-end;margin-bottom:10px">
        <div style="position:relative;display:inline-block">
            <span style="font-size:18px">🔔</span>
            <span style="position:absolute;top:-6px;right:-8px;background:#cf202f;color:#fff;font-size:10px;font-weight:600;padding:1px 5px;border-radius:100px">3</span>
        </div>
    </div>
    <div style="max-width:340px;margin-left:auto;border:1px solid var(--hairline);border-radius:var(--radius-lg);overflow:hidden">
        <div style="padding:10px 14px;background:#f5f6f8;border-bottom:1px solid var(--hairline);display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:12px;font-weight:600">Notificações</span>
            <span style="font-size:11px;color:var(--primary)">Marcar todas como lidas</span>
        </div>
        <div style="padding:10px 14px;border-bottom:1px solid var(--hairline-soft);background:var(--primary-soft)">
            <div style="font-size:12px;font-weight:600">Uso de celular — CAM-001</div>
            <div style="font-size:11px;color:var(--muted)">Risco alto · há 2 minutos</div>
        </div>
        <div style="padding:10px 14px;border-bottom:1px solid var(--hairline-soft)">
            <div style="font-size:12px;font-weight:600">Saída de cerca: Pátio Central</div>
            <div style="font-size:11px;color:var(--muted)">FJR7B59 · há 18 minutos</div>
        </div>
        <div style="padding:10px 14px">
            <div style="font-size:12px;font-weight:600">Sonolência — CAM-004</div>
            <div style="font-size:11px;color:var(--muted)">Risco alto · há 40 minutos</div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Canal</th><th>Como se comporta</th></tr>
<tr><td><strong>Sino</strong></td><td>O número vermelho ao lado do sino conta as não lidas. Clicar abre o painel com as mais recentes; clicar em uma delas leva à tela do evento e marca como lida. <strong>Marcar todas como lidas</strong> zera o contador.</td></tr>
<tr><td><strong>Pop-up</strong></td><td>Um aviso aparece na tela no momento em que o evento chega, sem precisar recarregar a página.</td></tr>
<tr><td><strong>Som</strong></td><td>Um alerta sonoro acompanha o pop-up. Útil para quem monitora vários veículos e não fica olhando o tempo todo para a tela.</td></tr>
<tr><td><strong>E-mail</strong></td><td>Mensagem para até 3 endereços por regra. Sai <strong>desligado</strong> por padrão — ninguém recebe e-mail sem ter pedido.</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Detalhe</th><th>Como funciona</th></tr>
<tr><td>Atualização</td><td>O sino se atualiza sozinho a cada 30 segundos. Com a aba em segundo plano ele pausa e volta a atualizar quando você retorna.</td></tr>
<tr><td>Quem vê o quê</td><td>Cada usuário vê apenas as notificações do cliente em que está posicionado. Trocar de cliente troca a caixa de notificações.</td></tr>
<tr><td>Quais eventos notificam</td><td>Ocorrências de comportamento (conforme as regras em <em>Cadastros › Config. Notificações</em>), entradas e saídas de geocerca e avisos do próprio sistema — por exemplo, um agendamento de relatório desativado por falhas.</td></tr>
<tr><td>Limpeza automática</td><td>Notificações lidas somem depois de 30 dias; as não lidas, depois de 90. Não é preciso limpar a caixa manualmente.</td></tr>
</table>

<div class="callout warn">
<strong>O sistema avisa por ocorrência, não por alarme.</strong> Um motorista distraído por meio minuto pode gerar uma dezena de alarmes seguidos da câmera. O sistema agrupa esses alarmes em <strong>uma</strong> ocorrência e envia <strong>um</strong> aviso — o comportamento é esse de propósito. Se você espera 12 avisos e recebe 1, o agrupamento está funcionando, não falhando. (A janela de agrupamento é configurada em <em>Cadastros › Config. Ocorrências</em>.)
</div>

<div class="callout info">
<strong>Freio contra enxurrada:</strong> se um mesmo cliente passar de 60 notificações em uma hora, o sistema grava uma única notificação-resumo dizendo quantos avisos foram suprimidos e para de notificar até a hora virar. É o que impede um equipamento com defeito de encher a caixa de todo mundo.
</div>

<p>As regras — o que notifica, por qual canal e para quem — ficam em <strong>Cadastros › Config. Notificações</strong>. O envio de e-mail depende também do <strong>Cadastros › Servidor de E-mail</strong>.</p>
