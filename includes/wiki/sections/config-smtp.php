<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Cadastrar as credenciais do servidor que envia os e-mails do sistema — alertas de notificação e relatórios agendados.</p>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que preencher</th></tr>
<tr><td>Servidor e porta</td><td>Endereço do provedor e a porta de envio</td></tr>
<tr><td>Segurança</td><td><strong>STARTTLS</strong> (normalmente porta 587), <strong>SSL/TLS implícito</strong> (porta 465) ou sem criptografia</td></tr>
<tr><td>Usuário e senha</td><td>Credenciais da conta de envio</td></tr>
<tr><td>E-mail e nome do remetente</td><td>Como a mensagem aparece na caixa de quem recebe. O nome vem preenchido como <strong>bycamera</strong>; troque-o se quiser que os e-mails saiam com o nome da sua empresa</td></tr>
<tr><td>Ativo</td><td>Desmarcado, a configuração é ignorada</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Salvar</td><td>Grava as credenciais. A senha é guardada cifrada e <strong>nunca é reexibida</strong> no formulário — deixar o campo em branco ao editar mantém a senha atual</td></tr>
<tr><td><strong>Enviar e-mail de teste</strong></td><td>Dispara uma mensagem para o endereço informado e mostra na tela o resultado. Falhando, exibe o erro que o provedor devolveu — é a forma mais rápida de descobrir porta errada, senha errada ou bloqueio do provedor</td></tr>
<tr><td>Excluir</td><td>Remove a configuração</td></tr>
</table>

<div class="callout info">
<strong>Escopo global ou por cliente.</strong> A configuração <em>global</em> atende todo o sistema. Um cliente pode ter a sua própria — útil quando ele quer que os e-mails saiam do próprio domínio. Vale, nesta ordem: a configuração do cliente, depois a global.
</div>

<div class="callout tip">
<strong>Se os e-mails caírem na caixa de spam</strong>, o ajuste não é no sistema: é preciso que o domínio do remetente autorize o servidor de envio (registros SPF/DKIM no DNS). Fale com quem administra o domínio.
</div>

<div class="callout info">
<strong>É este cadastro que decide o nome do remetente</strong> — inclusive nos relatórios agendados e nos alertas de notificação. Se os e-mails estiverem chegando com um nome antigo ou errado, é o campo <em>nome do remetente</em> desta tela que precisa ser corrigido, e a correção vale para os próximos envios.
</div>
