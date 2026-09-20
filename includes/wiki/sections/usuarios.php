<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Gestão de usuários do sistema com duas abas: <strong>Minha Empresa</strong> (usuários internos) e <strong>Meus Clientes</strong> (usuários dos clientes). Campos: nome, e-mail, senha, função (admin/operador/visualizador), tipo (revendedor/cliente), cliente vinculado, grupo de permissão, foto.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar usuário</td><td>Nome, e-mail, função, tipo de usuário, cliente (obrigatório) e grupo de permissão. A <strong>senha é opcional</strong>: em branco, o sistema gera uma <strong>senha temporária</strong> de 6 caracteres e a envia para o e-mail do usuário, que será obrigado a trocá-la no primeiro acesso. Preenchida (mín. 6 caracteres), a senha vale direto e nada é enviado</td></tr>
<tr><td>Editar usuário</td><td>Alterar dados. Senha só é alterada se preenchida — e, quando o administrador define uma senha à mão, a troca obrigatória no primeiro acesso deixa de valer</td></tr>
<tr><td>Reenviar senha</td><td>Botão na linha de cada usuário ativo: gera <strong>outra</strong> senha temporária e a envia por e-mail. A senha atual deixa de valer (não existe "reenviar a mesma", o sistema não guarda a senha). Se o envio falhar, a tela mostra uma contagem de 30 segundos e tenta de novo sozinha; dá para cancelar a contagem e usar o botão</td></tr>
<tr><td>Selos na coluna Status</td><td><strong>senha temporária</strong>: enviada, troca pendente no primeiro acesso. <strong>senha não entregue</strong>: o usuário existe, mas o e-mail com a senha não saiu — use <em>Reenviar senha</em></td></tr>
<tr><td>Pesquisar e exportar</td><td>A busca filtra por nome ou e-mail; dá para exportar a lista em Excel ou PDF</td></tr>
<tr><td>Ativar/Desativar</td><td>Ativa ou desativa o acesso. Não é possível desativar o próprio usuário</td></tr>
<tr><td>Vincular grupo de permissão</td><td>Usuário herda as permissões do grupo selecionado</td></tr>
<tr><td>Foto do usuário</td><td>Link de uma imagem na internet para usar como foto de perfil</td></tr>
</table>

<div class="callout info">
<strong>Senha temporária vale 24 horas e só existe no e-mail.</strong> O sistema não mostra a senha temporária em tela nenhuma; se o e-mail se perder, gere outra com <em>Reenviar senha</em>. O usuário também pode pedir a própria senha temporária pelo link <em>Esqueci minha senha</em> na tela de login — veja <a href="#primeiros-passos" style="color:inherit">Primeiros Passos</a>.
</div>
