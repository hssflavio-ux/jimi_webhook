<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Matriz de permissões de acesso. Cada grupo define quais telas e ações (Ver, Criar, Editar, Excluir, Exportar) um usuário pode acessar. A lista de grupos mostra o tipo de usuário (Cliente ou Revendedor) e a contagem de usuários vinculados a cada um.</p>

<div class="callout warn">
<strong>Tela nova nasce fechada.</strong> Quando o sistema ganha uma tela — Geocercas, Agendamentos, Config. Notificações e Servidor de E-mail são as mais recentes —, ela não vem marcada nos grupos já existentes. Quem está em um grupo restrito não a vê no menu e recebe "acesso negado" ao abrir o endereço direto, até que um administrador marque a linha correspondente aqui. Administradores não são afetados. Os relatórios são exceção: todos compartilham a mesma linha <strong>Relatórios</strong>, então quem já via Alarmes passa a ver os relatórios novos automaticamente.
</div>

<div class="callout info">
<strong>Nem toda linha da matriz pode ser liberada a um grupo.</strong> Algumas telas são <strong>exclusivas do administrador</strong>, e marcar a linha delas aqui <strong>não abre a tela</strong> para quem não é administrador. Na matriz, as linhas <em>SMS — Allcance</em>, <em>Parâmetros</em>, <em>Configurações IA</em>, <em>Firmware</em> e <em>Auditoria</em> trazem o aviso "(só admin)". <em>Clientes</em> e <em>Usuários</em> seguem a mesma regra, embora a linha não tenha o aviso. Em <em>Comandos por SMS</em>, ao contrário, o acesso é uma permissão à parte, justamente porque cada disparo gasta crédito de SMS.
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar grupo</td><td>Nome, tipo (Cliente ou Revendedor) e a matriz de marcações: uma linha por tela, com as cinco ações (Ver, Criar, Editar, Excluir, Exportar)</td></tr>
<tr><td>Editar grupo</td><td>Alterar nome, tipo e checkboxes da matriz</td></tr>
<tr><td>Excluir grupo</td><td>Só permitido se não houver usuários vinculados ao grupo</td></tr>
<tr><td>Ver contagem de usuários</td><td>Cada grupo mostra quantos usuários estão vinculados a ele</td></tr>
<tr><td>Pesquisar grupo</td><td>Campo acima da lista, que filtra pelo nome</td></tr>
</table>

<div class="callout tip">
<strong>Como a matriz é lida.</strong> Para quem está em um grupo, uma ação que não está marcada é uma ação negada. Já o usuário que <strong>não pertence a nenhum grupo</strong> não tem restrição pela matriz — por isso convém vincular cada usuário a um grupo. As telas exclusivas do administrador continuam fechadas a quem não é administrador, com ou sem grupo.
</div>
