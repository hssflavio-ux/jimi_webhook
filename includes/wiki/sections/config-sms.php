<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Cadastrar a conta do provedor de SMS que a tela <a href="#comandos-sms">Comandos por SMS</a> usa para mandar as mensagens, conferir se a conta funciona e escolher como o sistema fica sabendo da entrega e da resposta de cada SMS. Sem uma conta cadastrada, ativa e com créditos, a tela de envio não consegue mandar nada.</p>

<div class="callout warn">
<strong>Área de administrador.</strong> Só usuários administradores abrem esta tela: ela grava a senha de uma conta de terceiro, que gasta crédito a cada SMS enviado. Os demais usuários não veem o item no menu e recebem "acesso restrito ao administrador" ao tentar abrir o endereço direto. Existe <strong>uma única conta para a plataforma inteira</strong> — o custo dos SMS é da operação, não é dividido por cliente.
</div>

<div class="mockup">
<div class="mockup-header">SMS — Credenciais da conta</div>
<div class="mockup-body">
    <div style="font-size:12px;color:var(--muted);margin-bottom:14px">
        <strong style="color:var(--ink)">Em uso agora:</strong> <span class="mono">conta@exemplo.com</span> (origem: cadastro nesta tela), serviço <span class="mono">SMS TRANSACIONAL</span> — ativo.
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">
        <span style="font-size:15px;font-weight:600;color:var(--ink)">Credenciais da API</span>
        <span class="pill-mock green">Último teste: OK em 19/09/2026 14:20</span>
    </div>
    <div class="form-mock">
        <div class="form-mock-field"><label>Usuário da API</label><div class="input-mock">conta@exemplo.com</div></div>
        <div class="form-mock-field"><label>Senha</label><div class="input-mock dim">•••••••• (gravada — deixe em branco para manter)</div></div>
        <div class="form-mock-field form-mock-full"><label>Como receber a resposta do equipamento</label>
            <div style="display:flex;flex-direction:column;gap:6px;margin-top:4px;font-size:13px">
                <span><span class="pill-mock blue">● Busca periódica</span> recomendado agora</span>
                <span><span class="pill-mock gray">○ Webhook</span> evento empurrado pelo provedor</span>
            </div>
        </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
        <span class="btn-mock">Salvar</span>
        <span class="btn-mock outline">Testar credencial e ler saldo</span>
    </div>

    <div style="font-size:15px;font-weight:600;color:var(--ink);margin:22px 0 8px">Webhook de retorno</div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:10px">O endereço para cadastrar no painel do provedor aparece aqui, pronto para copiar.</div>
    <span class="btn-mock outline">Gerar segredo novo</span>
</div>
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">O que preencher</h4>
<table class="tbl-mock">
<tr><th>Campo</th><th>O que é</th></tr>
<tr><td>Usuário da API</td><td>O <strong>e-mail</strong> da conta no provedor de SMS — a API não tem um login separado do painel. É obrigatório</td></tr>
<tr><td>Senha</td><td>A senha da mesma conta; <strong>diferencia maiúsculas de minúsculas</strong>. É guardada cifrada e <strong>nunca volta para a tela</strong>: o campo aparece vazio e, deixado em branco ao salvar, mantém a senha atual — dá para trocar só o usuário ou o botão de ativo sem digitá-la de novo</td></tr>
<tr><td>Canal de SMS ativo</td><td>Desmarcado, o canal fica desligado e os envios da tela Comandos por SMS são recusados com o aviso de que o canal está desativado</td></tr>
<tr><td>Como receber a resposta do equipamento</td><td><strong>Busca periódica</strong> (o sistema consulta o provedor a cada 2 minutos) ou <strong>Webhook</strong> (o provedor avisa assim que a resposta chega). As duas vias são redundância uma da outra — <strong>nunca ficam ativas ao mesmo tempo</strong>. A busca periódica vem marcada e é a recomendada agora</td></tr>
</table>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Salvar</td><td>Grava o usuário, o estado (ativo ou não) e o método de resposta. Se uma senha nova foi digitada, ela substitui a anterior e o acesso já aberto com a conta antiga deixa de valer. Mostra "Credenciais gravadas."</td></tr>
<tr><td><strong>Testar credencial e ler saldo</strong></td><td>Entra na conta do provedor de novo, sem reaproveitar acesso anterior, e consulta os créditos. <strong>Não envia nenhum SMS.</strong> Dando certo, mostra "Autenticou" com o saldo do serviço de SMS transacional e a lista de saldos da conta; dando errado, mostra o motivo. O resultado fica registrado no selo <strong>Último teste</strong> (OK ou falhou, com data e hora)</td></tr>
</table>

<div class="callout tip">
<strong>Testar é o primeiro passo depois de cadastrar.</strong> É a forma mais rápida de descobrir usuário ou senha errados (lembre que a senha diferencia maiúsculas) antes de alguém tentar enviar um comando de verdade.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Retorno de entrega (Webhook de retorno)</h4>
<p>É por este caminho que o provedor avisa o <strong>status de entrega</strong> de cada SMS — a coluna <em>Entrega</em> do histórico em <a href="#comandos-sms">Comandos por SMS</a>. <strong>Sem ele, o envio aparece como aceito e a entrega nunca sai de "aguardando retorno".</strong></p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Copiar o endereço exibido</td><td>O endereço é montado pelo sistema e <strong>tem de ser cadastrado, à mão, no painel do provedor</strong> — não há como configurá-lo por aqui. Se a conta do provedor for recriada, o cadastro some sem aviso e é preciso repeti-lo. O endereço leva um código secreto embutido: trate-o como uma senha e não o compartilhe</td></tr>
<tr><td>Gerar segredo (ou segredo novo)</td><td>Cria um novo código e, com ele, um endereço novo. A tela pede confirmação antes, porque o <strong>endereço anterior, já cadastrado no provedor, deixa de valer</strong> — depois de gerar é preciso atualizar o cadastro no painel do provedor. Enquanto nenhum segredo foi gerado, a tela avisa e não mostra endereço</td></tr>
</table>

<div class="callout info">
<strong>Duas coisas diferentes chegam de volta.</strong> O <em>status de entrega</em> depende sempre do retorno de entrega acima. Já a <em>resposta do equipamento</em> depende do método escolhido: com <strong>Busca periódica</strong>, ela é buscada pelo próprio sistema e não precisa do endereço cadastrado; com <strong>Webhook</strong>, ela também é esperada por ele.
</div>

<div class="callout info">
<strong>O que a faixa "Em uso agora" e os avisos mostram.</strong> "Em uso agora", no alto, informa a conta em uso, de onde ela vem e se o canal está ativo — ou que nenhuma conta está configurada. Uma faixa vermelha no topo avisa quando falta uma atualização do sistema ou a chave que cifra a senha: nos dois casos o cadastro não funciona e é preciso acionar quem cuida do servidor.
</div>
