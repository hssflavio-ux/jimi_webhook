<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Criar e gerenciar perfis de regras que controlam <strong>como o sistema transforma cada tipo de alarme em ocorrência</strong>. Cada perfil define, para cada tipo de alarme, se gera ocorrência, qual o nível de risco e a janela de agrupamento.</p>

<div class="callout info">
<strong>Regra de negócio:</strong> Quando um alarme chega do dispositivo, o sistema consulta o perfil de ocorrências do cliente para decidir: este alarme vira uma ocorrência? Com qual risco? Se já existe uma ocorrência similar nos últimos X minutos, agrupa ou cria nova?
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar perfil</td><td>Nome + flag "Padrão do Sistema" (um perfil padrão por vez)</td></tr>
<tr><td>Editar perfil</td><td>Alterar nome + rows dinâmicas de parâmetros por tipo de alarme</td></tr>
<tr><td>Configurar parâmetro</td><td>Para cada tipo de alarme: gera ocorrência? (Sim/Não), nível de risco (Baixo/Médio/Alto), janela de agrupamento (minutos)</td></tr>
<tr><td>Excluir perfil</td><td>Só permitido se nenhum cliente estiver usando o perfil</td></tr>
<tr><td>Vincular ao cliente</td><td>No cadastro do cliente, selecionar o perfil de ocorrências</td></tr>
</table>
