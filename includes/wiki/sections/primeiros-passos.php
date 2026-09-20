<?php defined('WIKI_SECTION') || exit; ?>
<h3>Setup Inicial <span class="badge">admin</span></h3>
<p>Ao acessar o sistema pela primeira vez (sem usuários cadastrados), a tela de <strong>Configuração Inicial</strong> permite criar o primeiro administrador. Informe nome, e-mail e senha (mínimo 6 caracteres). Após a criação, você será levado ao login.</p>

<div class="callout warn">
<strong>Atenção:</strong> A tela de configuração inicial só aparece enquanto nenhum usuário foi cadastrado. Depois do primeiro cadastro, ela deixa de existir.
</div>

<div class="mockup">
<div class="mockup-header">Tela de Setup — Primeiro Acesso</div>
<div class="mockup-body">
    <div style="max-width:420px;margin:0 auto;text-align:center">
        <img src="/web/assets/logo-login.png" alt="bycamera" style="width:240px;max-width:100%;height:auto;margin-bottom:10px">
        <div style="font-size:12px;color:var(--muted);margin-bottom:24px">Configuração Inicial</div>
        <div class="form-mock" style="text-align:left;display:flex;flex-direction:column;gap:12px">
            <div class="form-mock-field"><label>Nome Completo</label><div class="input-mock">Administrador</div></div>
            <div class="form-mock-field"><label>E-mail</label><div class="input-mock">admin@exemplo.com</div></div>
            <div class="form-mock-field"><label>Senha</label><div class="input-mock dim">••••••••</div></div>
        </div>
        <div style="margin-top:20px"><span class="btn-mock">Criar Administrador</span></div>
    </div>
</div>
</div>

<!-- ── Login ────────────────────────────────────────── -->
<h3>Login <span class="badge" style="background:#e6f4ea;color:#098551">público</span></h3>
<p>Tela de entrada do sistema. Informe e-mail e senha para acessar. Em caso de erro, a mensagem aparece em vermelho acima do formulário.</p>

<div class="mockup">
<div class="mockup-header">Tela de Login</div>
<div class="mockup-body">
    <div style="max-width:400px;margin:0 auto;text-align:center">
        <img src="/web/assets/logo-login.png" alt="bycamera" style="width:280px;max-width:100%;height:auto;margin-bottom:14px">
        <?php /* O descritor já faz parte da arte do login (v4.8.2) — repeti-lo
                 abaixo do logo faria o mockup descrever uma tela que não existe. */ ?>
        <div style="font-size:20px;color:var(--ink);margin-bottom:24px">Entrar no sistema</div>
        <div style="display:flex;flex-direction:column;gap:12px;text-align:left">
            <div><label style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase">E-mail</label><div class="input-mock" style="width:100%;margin-top:4px">usuario@exemplo.com</div></div>
            <div><label style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase">Senha</label><div class="input-mock dim" style="width:100%;margin-top:4px">••••••••</div></div>
            <div style="margin-top:8px"><span class="btn-mock" style="display:block;text-align:center">Entrar</span></div>
            <div style="text-align:center;font-size:12px;color:var(--primary)">Esqueci minha senha</div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock" style="margin-top:10px">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Login com credenciais corretas</td><td>Abre a tela de Resumo</td></tr>
<tr><td>Login com credenciais erradas</td><td>Mensagem de erro. Após 5 tentativas em 15 min, conta bloqueada temporariamente</td></tr>
<tr><td>Esqueceu a senha</td><td>Clique em <strong>Esqueci minha senha</strong>, abaixo do formulário, e siga o passo <strong>Recuperar acesso</strong>, logo abaixo: a bycamera envia uma senha temporária para o seu e-mail</td></tr>
<tr><td>Entrar com a senha temporária</td><td>O sistema leva direto para a tela <strong>Defina sua senha</strong>, onde você escolhe a senha definitiva</td></tr>
<tr><td>Entrar com a senha temporária vencida</td><td>Mensagem "Senha temporária expirada. Use 'Esqueci minha senha' para receber outra."</td></tr>
<tr><td>Acessar sem login</td><td>O sistema pede e-mail e senha antes de mostrar qualquer tela</td></tr>
</table>

<!-- ── Recuperar acesso ─────────────────────────────── -->
<h3>Recuperar acesso <span class="badge" style="background:#e6f4ea;color:#098551">público</span></h3>
<p>Na tela de login, o link <strong>Esqueci minha senha</strong> abre a tela <strong>Recuperar acesso</strong>. Informe o e-mail cadastrado e clique em <strong>Enviar senha temporária</strong>. A bycamera envia para esse e-mail uma senha temporária de <strong>6 caracteres</strong> — letras maiúsculas e números, sem as letras I e O nem os números 0 e 1, para não haver dúvida na hora de digitar.</p>

<table class="tbl-mock">
<tr><th>Situação</th><th>O que acontece</th></tr>
<tr><td>Pedir a senha temporária</td><td>A tela responde <strong>sempre a mesma mensagem</strong>, exista ou não uma conta com aquele e-mail: "Se este e-mail estiver cadastrado e ativo, enviamos uma senha temporária para ele". É proposital — a tela é pública e não pode revelar quem tem conta. Usuário desativado não recebe</td></tr>
<tr><td>E-mail digitado com erro de formato</td><td>Mensagem "Informe um e-mail válido."</td></tr>
<tr><td>Pedir de novo em menos de 5 minutos</td><td>Nenhum e-mail novo é enviado: vale a senha do e-mail anterior. O sistema manda no máximo uma senha a cada 5 minutos para o mesmo e-mail</td></tr>
<tr><td>Muitos pedidos da mesma rede</td><td>Mensagem "Muitos pedidos deste endereço. Tente novamente mais tarde." O limite é de 5 pedidos por hora</td></tr>
<tr><td>Entrar com a temporária</td><td>Vale por <strong>24 horas</strong>. Depois disso, o login recusa e pede para usar "Esqueci minha senha" de novo</td></tr>
</table>

<div class="callout warn">
<strong>A senha atual deixa de valer assim que o e-mail com a temporária sai.</strong> Quem pede a recuperação fica com a temporária como única senha, e as sessões que estivessem abertas naquela conta são encerradas. Se o e-mail não chegar, confira a pasta de spam; se mesmo assim não chegar, avise o administrador do sistema.
</div>

<h4 style="font-size:14px;font-weight:600;margin:20px 0 8px">Definir a senha definitiva</h4>
<p>Ao entrar com a senha temporária, abre a tela <strong>Defina sua senha</strong>, sem menu lateral. Enquanto a troca não for feita, nenhuma outra tela do sistema abre — só essa ou o link <strong>Sair</strong>. A mesma tela aparece para quem teve a conta criada por um administrador sem senha digitada: nesse caso a temporária vai no e-mail de boas-vindas.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Informar nova senha + confirmação e clicar em Definir senha</td><td>"Senha alterada. Bem-vindo!" e o botão <strong>Ir para o sistema</strong>. A senha temporária deixa de valer</td></tr>
<tr><td>Nova senha com menos de 6 caracteres</td><td>Mensagem "A nova senha deve ter no mínimo 6 caracteres."</td></tr>
<tr><td>Confirmação diferente da nova senha</td><td>Mensagem "As senhas não conferem."</td></tr>
<tr><td>Nova senha igual à temporária</td><td>Recusada: a senha que circulou por e-mail não pode virar a definitiva</td></tr>
</table>

<div class="callout info">
Esta tela <strong>não pede a senha atual</strong>, porque você acabou de digitá-la no login. Já a troca voluntária de senha, em <em>Meu Perfil</em>, pede a senha atual.
</div>

<!-- ── Trocar Cliente ───────────────────────────────── -->
<h3>Trocar Cliente</h3>
<p>No topo do menu lateral há um seletor de cliente. Ele lista os clientes que o seu usuário pode acessar: um usuário comum costuma ter só um, e administradores e revendedores alternam entre vários para visualizar os dados de cada um. Cada troca fica registrada na auditoria.</p>
<div class="callout tip">
<strong>Dica:</strong> O cliente ativo aparece no topo do menu lateral. Ao trocar, todas as telas passam a mostrar dados do cliente selecionado.
</div>

<h4 style="font-size:14px;font-weight:600;margin:20px 0 8px">Revendedor: operar como cliente</h4>
<p>Além do seletor, o perfil <strong>revendedor</strong> tem, em <a href="#clientes" style="color:inherit">Clientes</a>, o botão <strong>Entrar como</strong>. Ele faz a bycamera mostrar o sistema como se você fosse aquele cliente, e abre uma <strong>faixa amarela no topo da tela</strong>: "Você está operando como &lt;cliente&gt; (impersonação ativa — auditada)", com o botão <strong>Voltar ao meu perfil</strong>. Ao clicar nele, a operação como cliente termina e você volta ao primeiro cliente da sua lista. O início e o fim ficam registrados para auditoria. O revendedor também vê a <em>Visão por Clientes</em> no <a href="#resumo" style="color:inherit">Resumo</a>.</p>

<!-- ── Perfil ───────────────────────────────────────── -->
<h3>Meu Perfil</h3>
<p>Tela acessível pelo avatar no rodapé do menu lateral. Exibe os dados do usuário logado (nome, e-mail, função, grupo de permissão) e permite <strong>alterar a própria senha</strong>.</p>
<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Alterar senha (atual + nova + confirmação)</td><td>Senha atualizada. É necessário usar a nova senha no próximo login</td></tr>
<tr><td>Senha atual incorreta</td><td>Mensagem de erro "Senha atual incorreta"</td></tr>
<tr><td>Nova senha com menos de 6 caracteres</td><td>Mensagem de erro</td></tr>
</table>
