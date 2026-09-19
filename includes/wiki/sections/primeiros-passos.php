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
        </div>
    </div>
</div>
</div>

<table class="tbl-mock" style="margin-top:10px">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Login com credenciais corretas</td><td>Abre a tela de Resumo</td></tr>
<tr><td>Login com credenciais erradas</td><td>Mensagem de erro. Após 5 tentativas em 15 min, conta bloqueada temporariamente</td></tr>
<tr><td>Esqueceu a senha</td><td>Contate o administrador do sistema (não há recuperação automática)</td></tr>
<tr><td>Acessar sem login</td><td>O sistema pede e-mail e senha antes de mostrar qualquer tela</td></tr>
</table>

<!-- ── Trocar Cliente ───────────────────────────────── -->
<h3>Trocar Cliente</h3>
<p>No topo do menu lateral há um seletor de cliente. Usuários revendedores podem alternar entre os clientes que gerenciam para visualizar os dados de cada um.</p>
<div class="callout tip">
<strong>Dica:</strong> O cliente ativo aparece no topo do menu lateral. Ao trocar, todas as telas passam a mostrar dados do cliente selecionado.
</div>

<!-- ── Perfil ───────────────────────────────────────── -->
<h3>Meu Perfil</h3>
<p>Tela acessível pelo avatar no rodapé do menu lateral. Exibe os dados do usuário logado (nome, e-mail, função, grupo de permissão) e permite <strong>alterar a própria senha</strong>.</p>
<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Alterar senha (atual + nova + confirmação)</td><td>Senha atualizada. É necessário usar a nova senha no próximo login</td></tr>
<tr><td>Senha atual incorreta</td><td>Mensagem de erro "Senha atual incorreta"</td></tr>
<tr><td>Nova senha com menos de 6 caracteres</td><td>Mensagem de erro</td></tr>
</table>
