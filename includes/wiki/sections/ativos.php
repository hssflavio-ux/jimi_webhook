<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Gerenciar dispositivos (veículos/câmeras) da frota. Lista paginada com busca, edição inline (nome, modelo, número de câmeras) e remoção.</p>

<div class="mockup">
<div class="mockup-header">Ativos — Lista de Dispositivos</div>
<div class="mockup-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div class="input-mock" style="width:260px;font-size:13px">Buscar por nome ou IMEI...</div>
        <span class="btn-mock">+ Novo Ativo</span>
    </div>
    <table class="tbl-mock">
    <tr><th>Nome</th><th>IMEI</th><th>Modelo</th><th>Câmeras</th><th>Última Com.</th><th>Status</th><th></th></tr>
    <tr>
        <td>CAM-001 Frente</td><td class="mono">860112070347838</td><td>JC181</td><td>2</td><td>18/07 14:35</td><td><span class="pill-mock green">Online</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span> <span class="btn-mock danger" style="font-size:12px;padding:2px 8px">Remover</span></td>
    </tr>
    <tr>
        <td>CAM-002 Lateral</td><td class="mono">869058070151343</td><td>JC182</td><td>1</td><td>18/07 14:20</td><td><span class="pill-mock green">Online</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span> <span class="btn-mock danger" style="font-size:12px;padding:2px 8px">Remover</span></td>
    </tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>+ Novo Ativo</td><td>Abre o formulário de cadastro: IMEI, nome, modelo e número de câmeras instaladas</td></tr>
<tr><td>Editar inline</td><td>Campos de nome, modelo e câmeras tornam-se editáveis na própria linha</td></tr>
<tr><td>Remover</td><td>O dispositivo sai das listas, mas o histórico dele é preservado</td></tr>
<tr><td>Clicar no IMEI</td><td>Abre a tela de detalhe do ativo, com 9 abas</td></tr>
<tr><td>Buscar</td><td>Filtra por nome ou IMEI (busca parcial)</td></tr>
<tr><td>Exportar</td><td>Baixa CSV, Excel ou PDF com todos os dispositivos ativos</td></tr>
</table>

<div class="callout tip">
<strong>Equipamento já conhecido:</strong> Se um equipamento novo já estava enviando localização antes de ser cadastrado, ao cadastrar o IMEI o sistema aproveita o que já foi recebido — nada do histórico se perde.
</div>
