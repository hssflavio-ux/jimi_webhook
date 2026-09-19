<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Matriz de permissões de acesso. Cada grupo define quais telas (22) e ações (Ver, Criar, Editar, Excluir, Exportar) um usuário pode acessar. Mostra contagem de usuários vinculados a cada grupo.</p>

<div class="callout warn">
<strong>Tela nova nasce fechada.</strong> Quando o sistema ganha uma tela — Geocercas, Agendamentos, Config. Notificações e Servidor de E-mail são as mais recentes —, ela não vem marcada nos grupos já existentes. Quem está em um grupo restrito não a vê no menu e recebe "acesso negado" ao abrir o endereço direto, até que um administrador marque a linha correspondente aqui. Administradores não são afetados. Os relatórios são exceção: todos compartilham a mesma linha <strong>Relatórios</strong>, então quem já via Alarmes passa a ver os relatórios novos automaticamente.
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar grupo</td><td>Nome + matriz de marcações (18 telas x 5 ações)</td></tr>
<tr><td>Editar grupo</td><td>Alterar nome e checkboxes da matriz</td></tr>
<tr><td>Excluir grupo</td><td>Só permitido se não houver usuários vinculados ao grupo</td></tr>
<tr><td>Ver contagem de usuários</td><td>Cada grupo mostra quantos usuários estão vinculados a ele</td></tr>
</table>
