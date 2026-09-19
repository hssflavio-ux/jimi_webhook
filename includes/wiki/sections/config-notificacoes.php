<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Definir o que gera aviso, por qual canal e para quem. Cada regra combina um tipo de alarme com os canais desejados — <a href="#notificacoes" style="color:inherit">sino, pop-up, som e e-mail</a>.</p>

<div class="mockup">
<div class="mockup-header">Config. Notificações — Regras</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Alarme</th><th>Escopo</th><th>Risco mínimo</th><th>Canais</th><th>Destinatários</th><th>Status</th></tr>
    <tr><td>Categoria: DMS</td><td>Frota Principal</td><td>Alto</td><td>Sino, Pop-up, Som, E-mail</td><td>seguranca@empresa.com.br</td><td><span class="pill-mock green">Ativa</span></td></tr>
    <tr><td>SOS / Pânico</td><td><span class="pill-mock gray">Global</span></td><td>Qualquer</td><td>Sino, Pop-up</td><td>—</td><td><span class="pill-mock green">Ativa</span></td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que preencher</th></tr>
<tr><td>Tipo de alarme</td><td>Um alarme específico ou uma <strong>categoria inteira</strong>. Escolher a categoria cobre todos os alarmes dela de uma vez — bem mais simples do que cadastrar um por um</td></tr>
<tr><td>Risco mínimo</td><td>Qualquer risco, "baixo ou maior", "médio ou maior" ou "somente alto". Filtra pelo risco que o perfil de ocorrências atribuiu ao alarme</td></tr>
<tr><td>Canais</td><td>Sino, pop-up em tempo real, som e e-mail — marque os que quiser combinar</td></tr>
<tr><td>Destinatários</td><td>Até 3 endereços, usados quando o canal E-mail está marcado</td></tr>
<tr><td>Regra ativa</td><td>Desmarcada, a regra fica guardada mas não vale</td></tr>
</table>

<div class="callout info">
<strong>A regra do cliente vence a regra global.</strong> Regras <em>globais</em> (escopo "Global", só administrador cria) valem para todos os clientes que não tenham regra própria para aquele alarme. Assim que um cliente cadastra a sua, é ela que passa a valer para ele — sem precisar apagar a global.
</div>

<div class="callout warn">
<strong>E-mail não funciona sem servidor cadastrado.</strong> Marcar o canal E-mail aqui não basta: é preciso cadastrar as credenciais em <a href="#config-smtp" style="color:inherit">Cadastros › Servidor de E-mail</a>. Sem isso, sino, pop-up e som continuam funcionando normalmente — só o e-mail não sai.
</div>
