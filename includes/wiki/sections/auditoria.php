<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Saber quem fez o quê, quando e em qual registro — quem excluiu um chip, quem mudou um cadastro, quem tentou abrir uma tela sem permissão, quem entrou no sistema e quando. A Auditoria é <strong>somente consulta</strong>: mostra o histórico e não altera nada. Os registros nascem sozinhos, no momento em que a ação acontece, e não há botão para editar ou apagar nenhum deles. A Auditoria é <strong>exclusiva do administrador</strong> do sistema.</p>

<div class="mockup">
<div class="mockup-header">Auditoria — Tudo</div>
<div class="mockup-body">
    <div style="display:flex;gap:16px;border-bottom:1px solid var(--hairline);margin-bottom:12px;font-size:13px;font-weight:600;flex-wrap:wrap">
        <span style="color:var(--primary);border-bottom:2px solid var(--primary);padding:6px 2px;margin-bottom:-1px">Tudo</span>
        <span style="color:var(--muted);padding:6px 2px">Acessos Negados</span>
        <span style="color:var(--muted);padding:6px 2px">Alterações de Cadastro</span>
        <span style="color:var(--muted);padding:6px 2px">Login e Sessão</span>
    </div>
    <div class="filter-bar-mock">
        <div class="filter-mock">Usuário: Todos</div>
        <div class="filter-mock dim">Ação contém…</div>
        <div class="filter-mock dim">Entidade…</div>
        <div class="filter-mock">19/09/2026 - 19/09/2026</div>
    </div>
    <table class="tbl-mock">
    <tr><th>Quando</th><th>Autor</th><th>Ação</th><th>Entidade</th><th>Status</th><th>Detalhe</th></tr>
    <tr><td>19/09/2026 14:32</td><td>Ana Souza<br><span class="mono">ana@empresa.com.br</span></td><td class="mono">sim_card.update</td><td>sim_card #12</td><td><span class="pill-mock green">success</span></td><td>ver</td></tr>
    <tr><td>19/09/2026 14:10</td><td>Carlos Lima<br><span class="mono">carlos@empresa.com.br</span></td><td class="mono">permission.denied</td><td>—</td><td><span class="pill-mock red">denied</span></td><td>ver</td></tr>
    <tr><td>19/09/2026 09:03</td><td>Ana Souza<br><span class="mono">ana@empresa.com.br</span></td><td class="mono">vehicle.create</td><td>vehicle #57</td><td><span class="pill-mock green">success</span></td><td>ver</td></tr>
    </table>
</div>
</div>

<p>A tela tem <strong>quatro abas</strong>. Todas têm a mesma estrutura: filtros no alto, a lista embaixo (50 registros por página, do mais recente para o mais antigo) e o horário sempre no horário de Brasília. A primeira mostra tudo; as outras três são recortes já prontos, para não precisar montar o filtro toda vez.</p>

<table class="tbl-mock">
<tr><th>Aba</th><th>O que mostra</th><th>Filtros</th><th>Exporta?</th></tr>
<tr><td>Tudo</td><td>Todas as fontes juntas: alterações de cadastro, acessos negados, login e sessão, e comandos enviados aos equipamentos</td><td>Cliente, Usuário, Ação contém, Entidade, De, Até</td><td>Não</td></tr>
<tr><td>Acessos Negados</td><td>Toda vez que alguém tentou abrir uma tela sem permissão e toda tentativa de login que falhou</td><td>Cliente, Usuário, De, Até</td><td>Sim</td></tr>
<tr><td>Alterações de Cadastro</td><td>Todo cadastro criado, alterado ou excluído (chips, motoristas, geocercas, usuários, clientes, equipamentos, veículos, grupos de permissão e outros), com o valor <strong>antes</strong> e <strong>depois</strong> quando existe</td><td>Cliente, Usuário, Entidade (lista das que já aparecem no histórico), De, Até</td><td>Sim</td></tr>
<tr><td>Login e Sessão</td><td>Entradas e saídas do sistema (com sucesso ou falha), troca de cliente, o início e o fim de quando um revendedor passa a atuar como um cliente e as ações sobre o cadastro do próprio cliente (criar, alterar, desativar)</td><td>Cliente, Usuário, De, Até</td><td>Sim</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Ler a lista</h4>

<table class="tbl-mock">
<tr><th>Coluna</th><th>O que significa</th></tr>
<tr><td>Quando</td><td>Data e hora da ação, no horário de Brasília</td></tr>
<tr><td>Autor</td><td>Nome e e-mail de quem fez. O nome e o e-mail ficam <strong>congelados no momento do registro</strong>: se o usuário for renomeado ou removido depois, o histórico continua mostrando quem ele era na hora</td></tr>
<tr><td>Cliente</td><td>A qual cliente a ação pertence. Esta coluna só aparece quando nenhum cliente está filtrado</td></tr>
<tr><td>Ação</td><td>O que foi feito, no formato <span class="mono">entidade.verbo</span> (veja a tabela abaixo)</td></tr>
<tr><td>Entidade</td><td>O tipo de registro afetado e o seu número (ex.: <span class="mono">sim_card #12</span>)</td></tr>
<tr><td>Status</td><td><strong>success</strong>: feito. <strong>denied</strong>: negado (acesso sem permissão ou login que falhou). <strong>error</strong>: comando que falhou. <strong>aguardando</strong>: comando enviado que ainda não teve resposta</td></tr>
<tr><td>Detalhe</td><td>O link <strong>ver</strong> abre o valor <strong>antes</strong> e <strong>depois</strong> da alteração, quando a ação tinha. Em acesso negado, mostra a tela que foi barrada; em comando, o IMEI do equipamento e o comando enviado (ou o código dele)</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Nomes de ação que você vai encontrar</h4>

<table class="tbl-mock">
<tr><th>Ação</th><th>Significa</th></tr>
<tr><td><span class="mono">…create</span> / <span class="mono">…update</span> / <span class="mono">…delete</span></td><td>Criou, alterou ou excluiu um registro. O começo do nome diz qual: <span class="mono">sim_card</span> (chip), <span class="mono">device</span> (equipamento), <span class="mono">vehicle</span> (veículo), <span class="mono">driver</span> (motorista), <span class="mono">geofence</span> (geocerca), <span class="mono">user</span> (usuário), <span class="mono">customer</span> (cliente), <span class="mono">permission_group</span> (grupo de permissão), <span class="mono">notification_rule</span> (regra de notificação), <span class="mono">report_schedule</span> (agendamento) e <span class="mono">maintenance_reminder</span> (lembrete de manutenção)</td></tr>
<tr><td><span class="mono">session.login</span> / <span class="mono">session.login_failed</span> / <span class="mono">session.logout</span></td><td>Entrada no sistema, tentativa de entrada que falhou e saída</td></tr>
<tr><td><span class="mono">permission.denied</span></td><td>Alguém tentou abrir uma tela para a qual não tem permissão. O Detalhe mostra qual</td></tr>
<tr><td><span class="mono">customer.switch</span></td><td>O usuário trocou o cliente selecionado no menu lateral</td></tr>
<tr><td><span class="mono">customer.impersonate_start</span> / <span class="mono">…_end</span></td><td>Um revendedor passou a atuar como um cliente e depois encerrou</td></tr>
<tr><td><span class="mono">command.dispatch</span> / <span class="mono">sms_command.dispatch</span></td><td>Comando enviado a um equipamento (pela plataforma ou por SMS)</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Filtros e período</h4>

<table class="tbl-mock">
<tr><th>Filtro</th><th>Como funciona</th></tr>
<tr><td>De / Até</td><td>Vem preenchido com <strong>hoje</strong>. O teto é de <strong>31 dias por consulta</strong>; se você pedir mais, o período é ajustado e a tela avisa</td></tr>
<tr><td>Usuário</td><td>Lista os usuários do cliente escolhido no filtro Cliente (todos, quando nenhum cliente está filtrado). Filtrar por usuário deixa de fora as tentativas de login e os comandos enviados (pela plataforma ou por SMS), porque esses registros não guardam o usuário</td></tr>
<tr><td>Ação contém</td><td>Só na aba Tudo. Procura o texto em qualquer parte do nome da ação. Ex.: <span class="mono">delete</span> traz todas as exclusões; <span class="mono">sim_card</span> traz tudo que envolve chips</td></tr>
<tr><td>Entidade</td><td>Restringe a um tipo de registro (na aba Tudo, digitado; em Alterações de Cadastro, escolhido numa lista). Ex.: <span class="mono">sim_card</span></td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Exportar</h4>
<p>As abas <strong>Acessos Negados</strong>, <strong>Alterações de Cadastro</strong> e <strong>Login e Sessão</strong> têm os botões <strong>Exportar Excel</strong>, <strong>Exportar PDF</strong> e <strong>Exportar CSV</strong>. O arquivo sai na hora, com os mesmos filtros da tela (e não só a página que você está vendo), até <strong>10.000 linhas</strong>. O administrador que pertence a um grupo de permissão que não marca a <strong>exportação</strong> da Auditoria vê os botões, mas a exportação é recusada; quem não pertence a nenhum grupo exporta sem restrição. A aba <strong>Tudo</strong> não exporta.</p>

<div class="callout info">
<strong>O administrador vê todos os clientes.</strong> Sem filtro, a lista traz os registros de todos os clientes, e a coluna <strong>Cliente</strong> diz a qual cada um pertence. Para olhar um cliente de cada vez, escolha-o no filtro <strong>Cliente</strong>.
</div>

<div class="callout warn">
<strong>Tentativas de login e comandos da plataforma só aparecem sem filtro de cliente.</strong> Uma tentativa de login guarda apenas o e-mail digitado — ainda não se sabe quem é o usuário nem a qual cliente pertence — e os comandos enviados pela plataforma não guardam o cliente. Por isso esses registros só aparecem com o filtro Cliente em <em>Todos os clientes</em>. Nas abas <strong>Acessos Negados</strong> e <strong>Login e Sessão</strong>, quem consulta um cliente específico vê os acessos negados por falta de permissão e as saídas do sistema, mas não os logins. Os comandos por SMS guardam o cliente e aparecem normalmente.
</div>

<div class="callout info">
<strong>A aba Alterações de Cadastro só traz as ações terminadas em create, update ou delete.</strong> Outras ações registradas — instalar ou desinstalar uma câmera, desativar um veículo, trocar a senha de um usuário, registrar uma manutenção como concluída — aparecem na aba <strong>Tudo</strong>.
</div>

<div class="callout tip">
<strong>Um número que cresce de repente em Acessos Negados merece atenção.</strong> Pode ser tentativa de acesso indevido — ou um grupo de permissão configurado errado, barrando quem deveria entrar. Confira em <a href="#grupos-permissao">Grupos de Permissão</a>.
</div>

<div class="callout">
<strong>Quem pode abrir a Auditoria.</strong> Só o <strong>administrador</strong>. A tela não é concedida por grupo de permissão — marcá-la num grupo não a abre para quem não é administrador — e o item nem aparece no menu lateral dos demais usuários. Quem não é administrador e digitar o endereço recebe o aviso de acesso restrito, e a tentativa fica registrada na aba <strong>Acessos Negados</strong>.
</div>
