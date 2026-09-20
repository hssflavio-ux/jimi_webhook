<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Fila de geração de relatórios grandes. O pedido entra na fila, é processado em segundo plano e fica disponível para download quando concluído. Formatos: CSV, Excel e PDF.</p>

<div class="mockup">
<div class="mockup-header">Exportar — Fila de Jobs</div>
<div class="mockup-body">
    <div style="margin-bottom:16px">
        <span class="btn-mock">+ Novo Relatório</span>
    </div>
    <table class="tbl-mock">
    <tr><th>Relatório</th><th>Tipo</th><th>Formato</th><th>Status</th><th>Criado em</th><th></th></tr>
    <tr>
        <td>Alarmes Julho 2026</td><td>Alarmes</td><td>Excel</td><td><span class="pill-mock green">Concluído</span></td><td>18/07 14:00</td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Baixar</span></td>
    </tr>
    <tr>
        <td>Posições Semanal</td><td>Posições</td><td>PDF</td><td><span class="pill-mock yellow">Processando</span></td><td>18/07 13:50</td>
        <td><span style="font-size:11px;color:var(--muted)">Aguarde...</span></td>
    </tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Novo Relatório</td><td>Formulário: nome, tipo de relatório, período e formato (CSV, Excel ou PDF)</td></tr>
<tr><td>Pedido criado</td><td>Entra na fila com status "pendente" e é processado em até 1 minuto</td></tr>
<tr><td>Baixar (status "concluído")</td><td>Download do arquivo gerado</td></tr>
<tr><td>Auto-atualização</td><td>O status dos relatórios se atualiza sozinho na tela</td></tr>
<tr><td>Tipos disponíveis</td><td>Alertas Videomonitoramento, Alertas Dirigibilidade, Ocorrências, Posições GPS, Viagens (Deslocamento), Equipamentos, Paradas, Ociosidade, Ignição, Excesso de Velocidade e Status da Frota (foto do agora). Os dois tipos de alertas seguem o mesmo recorte das telas de mesmo nome: condução vai só em Dirigibilidade, e Videomonitoramento não inclui rastreadores nem eventos técnicos de diagnóstico</td></tr>
<tr><td>Agendados</td><td>A tela também resume os <a href="#agendamentos" style="color:inherit">agendamentos</a> ativos e quando cada um envia da próxima vez</td></tr>
</table>

<div class="callout warn">
<strong>"Expirado" no lugar do botão Baixar.</strong> Os arquivos gerados são apagados do servidor depois de 30 dias — cada um é uma cópia de dados da sua frota parada em disco, e guardá-los para sempre não é seguro nem necessário. Passado esse prazo, a linha continua na lista, mas com a marca <em>Expirado</em> em vez do botão. Para ter o arquivo de novo, basta pedir o relatório outra vez.
</div>

<div class="callout tip">
<strong>O endereço do arquivo é secreto, não protegido por login.</strong> Cada relatório recebe um nome longo e aleatório justamente para que o link enviado por e-mail funcione sem exigir que a pessoa entre no sistema. A contrapartida é que <strong>qualquer um com o link consegue baixar o arquivo</strong> — trate-o como você trataria o próprio relatório em anexo e evite encaminhá-lo para fora de quem deve vê-lo.
</div>
