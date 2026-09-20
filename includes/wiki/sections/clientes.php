<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Administradores (revendedores) gerenciam os clientes da plataforma. Cada cliente tem sua própria frota isolada.</p>

<div class="mockup">
<div class="mockup-header">Clientes — Gestão Multi-tenant</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Nome</th><th>Documento</th><th>Dispositivos</th><th>Config. Ocorrências</th><th>FaceID</th><th>Ações</th></tr>
    <tr>
        <td>Frota Principal</td><td>12.345.678/0001-90</td><td>42</td><td>Padrão Sistema</td><td><span class="pill-mock gray">Desligado</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span> <span class="btn-mock outline" style="font-size:12px;padding:2px 8px">Entrar como</span></td>
    </tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar/Editar cliente</td><td>Formulário com: nome, documento, e-mail, telefone, endereço, perfil de ocorrências, FaceID, cor da marca e logo</td></tr>
<tr><td>Desativar</td><td>O cliente some das listas (o cliente principal do sistema não pode ser desativado)</td></tr>
<tr><td>Entrar como (impersonar)</td><td>Botão da linha do cliente, só para o <strong>administrador com perfil revendedor</strong> (o revendedor que não é administrador troca de cliente pelo seletor do menu lateral). Passa a ver o sistema como aquele cliente, e uma <strong>faixa amarela no topo</strong> avisa "Você está operando como &lt;cliente&gt; (impersonação ativa — auditada)", com o botão <strong>Voltar ao meu perfil</strong>, que encerra e volta ao primeiro cliente da sua lista. O início e o fim ficam registrados para auditoria</td></tr>
<tr><td>Cor da marca</td><td>A cor escolhida é aplicada ao menu lateral do cliente</td></tr>
<tr><td>FaceID</td><td>Habilita a identificação facial de motoristas para o cliente</td></tr>
<tr><td><strong>Limite de velocidade padrão</strong></td><td>Vale para toda a frota do cliente no relatório de <a href="#rel-velocidade" style="color:inherit">Excesso de Velocidade</a>. Um equipamento com limite próprio ignora este valor; sem nenhum dos dois, o sistema usa 80 km/h</td></tr>
</table>
