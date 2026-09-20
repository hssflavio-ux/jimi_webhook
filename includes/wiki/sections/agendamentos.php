<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Configurar um relatório uma única vez e recebê-lo por e-mail na frequência escolhida, sem precisar entrar no sistema para gerá-lo.</p>

<div class="mockup">
<div class="mockup-header">Agendamentos</div>
<div class="mockup-body">
    <div style="margin-bottom:16px"><span class="btn-mock">+ Novo Agendamento</span></div>
    <table class="tbl-mock">
    <tr><th>Nome</th><th>Relatório</th><th>Recorrência</th><th>Destinatários</th><th>Próximo envio</th><th>Status</th><th></th></tr>
    <tr>
        <td>Velocidade semanal</td><td>Excesso de Velocidade <span class="pill-mock gray">XLSX</span></td>
        <td>Toda segunda às 07:00 (BRT)</td><td>gestor@empresa.com.br</td>
        <td class="mono">03/08 07:00</td><td><span class="pill-mock green">Ativo</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span></td>
    </tr>
    <tr>
        <td>Alarmes do dia</td><td>Alarmes <span class="pill-mock gray">PDF</span></td>
        <td>Todo dia às 06:00 (BRT)</td><td>operacao@empresa.com.br +1</td>
        <td class="mono">31/07 06:00</td>
        <td><span class="pill-mock gray">Inativo</span> <span class="pill-mock yellow">3/3 falhas</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Ativar</span></td>
    </tr>
    </table>
    <div style="font-size:12px;font-weight:600;color:var(--muted);margin:18px 0 8px">Histórico de execuções</div>
    <table class="tbl-mock">
    <tr><th>Quando</th><th>Agendamento</th><th>Período coberto</th><th>Registros</th><th>Situação</th></tr>
    <tr><td>28/07 07:00</td><td>Velocidade semanal</td><td>21/07 a 27/07</td><td class="mono">34</td><td><span class="pill-mock green">Enviado</span></td></tr>
    <tr><td>27/07 06:00</td><td>Alarmes do dia</td><td>26/07</td><td class="mono">0</td><td><span class="pill-mock gray">Vazio</span></td></tr>
    <tr><td>26/07 06:00</td><td>Alarmes do dia</td><td>25/07</td><td class="mono">18</td><td><span class="pill-mock red">Falhou</span></td></tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que preencher</th></tr>
<tr><td>Nome</td><td>Como o agendamento aparece na lista e no assunto do e-mail</td></tr>
<tr><td>Relatório e formato</td><td>Qual relatório enviar, em Excel, CSV ou PDF. Os tipos são: Alertas Videomonitoramento, Alertas Dirigibilidade, Ocorrências, Posições GPS, Viagens (Deslocamento), Equipamentos, Paradas, Ociosidade, Ignição e Excesso de Velocidade — o Status da Frota não pode ser agendado, porque é uma foto do momento. A distância de Viagens vem do hodômetro do equipamento, como na tela de Deslocamento</td></tr>
<tr><td>Frequência</td><td><strong>Diária</strong>, <strong>semanal</strong> (com o dia da semana) ou <strong>mensal</strong> (com o dia do mês)</td></tr>
<tr><td>Hora do envio</td><td>Hora cheia, no <strong>horário de Brasília</strong></td></tr>
<tr><td>Destinatários</td><td>Até 3 endereços, separados por vírgula</td></tr>
<tr><td>Não enviar quando não houver registro</td><td>Marcado, o envio é pulado nos períodos sem nada a relatar (o histórico registra "Vazio"). Desmarcado, o e-mail sai mesmo assim, dizendo que não houve registro</td></tr>
<tr><td>Agendamento ativo</td><td>Desmarcado, o agendamento fica guardado mas não dispara</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Editar</td><td>Abre o formulário com os dados atuais; salvar recalcula o próximo envio</td></tr>
<tr><td>Desativar / Ativar</td><td>Suspende ou retoma os envios. Ativar recalcula o próximo envio e zera o contador de falhas</td></tr>
<tr><td>Excluir</td><td>Remove o agendamento e todo o seu histórico (pede confirmação)</td></tr>
<tr><td>Histórico de execuções</td><td>Mostra cada disparo: quando, período coberto, quantos registros e se foi enviado, ficou vazio ou falhou — com a mensagem de erro do provedor de e-mail quando falha</td></tr>
</table>

<div class="callout info">
<strong>O período é sempre o anterior fechado</strong>, nunca o que está em curso: <strong>ontem</strong> na frequência diária, <strong>a semana passada</strong> (segunda a domingo) na semanal e <strong>o mês passado</strong> na mensal. Um relatório do dia que ainda não terminou chegaria pela metade.
</div>

<div class="callout warn">
<strong>Relatório grande chega como link, não como anexo.</strong> Acima do tamanho máximo de anexo configurado no sistema, o e-mail traz um botão para baixar o arquivo em vez do arquivo em si — provedores de e-mail recusam anexos grandes, e um e-mail recusado é pior do que um link. <strong>Esse link vale por tempo limitado</strong>: por padrão o arquivo é apagado do servidor depois de 30 dias, e a partir daí a tela Exportar passa a mostrá-lo como <em>Expirado</em>. Guarde o arquivo se precisar dele por mais tempo — ou apenas gere o relatório de novo.
</div>

<div class="callout warn">
<strong>Três falhas seguidas desativam o agendamento.</strong> Se o e-mail não puder ser entregue três vezes consecutivas — endereço inexistente, servidor de e-mail fora do ar —, o agendamento é desativado e quem o criou recebe um aviso no sino. Corrija o destinatário e clique em <strong>Ativar</strong>: o contador zera. Um envio bem-sucedido no meio do caminho também zera a contagem, porque a regra é <em>três seguidas</em>.
</div>

<div class="callout tip">
<strong>Dia do mês vai até 28</strong> na frequência mensal, de propósito: 29, 30 e 31 não existem em todos os meses, e pular fevereiro nunca é o que se quis dizer.
</div>
