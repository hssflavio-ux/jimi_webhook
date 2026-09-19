<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Gestão de usuários do sistema com duas abas: <strong>Minha Empresa</strong> (usuários internos) e <strong>Meus Clientes</strong> (usuários dos clientes). Campos: nome, e-mail, senha, função (admin/operador/visualizador), tipo (revendedor/cliente), cliente vinculado, grupo de permissão, foto.</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar usuário</td><td>Nome, e-mail, senha (mín. 6 caracteres), função, tipo de usuário, cliente, grupo de permissão</td></tr>
<tr><td>Editar usuário</td><td>Alterar dados. Senha só é alterada se preenchida</td></tr>
<tr><td>Ativar/Desativar</td><td>Ativa ou desativa o acesso. Não é possível desativar o próprio usuário</td></tr>
<tr><td>Vincular grupo de permissão</td><td>Usuário herda as permissões do grupo selecionado</td></tr>
<tr><td>Foto do usuário</td><td>Link de uma imagem na internet para usar como foto de perfil</td></tr>
</table>
