<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Mandar um comando ao equipamento por mensagem de texto (SMS), para o número do chip dele. É o mesmo tipo de comando da tela <a href="#comandos">Comandos</a>, só que levado pela rede da operadora — um caminho independente da conexão normal do equipamento com o sistema, útil quando ele parou de se comunicar (por exemplo, por causa de uma configuração de rede errada).</p>

<div class="callout warn">
<strong>Cada SMS consome crédito.</strong> É cobrado <strong>1 crédito por equipamento marcado</strong>: enviar para 20 equipamentos gasta 20 créditos. A tela mostra o saldo no topo, soma os créditos da seleção antes do envio e pede confirmação — o envio <strong>não pode ser cancelado</strong> depois de confirmado. O crédito é gasto no envio, mesmo que o equipamento ignore o comando; por isso vale conferir o modelo e o texto antes de confirmar.
</div>

<div class="mockup">
<div class="mockup-header">Comandos por SMS</div>
<div class="mockup-body">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Saldo — SMS transacional</div>
            <div class="mono" style="font-size:24px;font-weight:600;color:var(--ink)">1.240</div>
            <div style="font-size:11px;color:var(--muted)">Consultado às 14:32 do dia 19/09/26</div>
        </div>
        <div style="font-size:12px;color:var(--muted);text-align:right;line-height:1.7">
            <strong style="color:var(--ink)">3</strong> equipamentos no escopo<br>
            <strong>1</strong> sem número de chip — corrigir
        </div>
    </div>

    <table class="tbl-mock" style="font-size:12px">
    <tr><th></th><th>Equipamento</th><th>Modelo</th><th>Número do chip</th><th>Contato</th></tr>
    <tr><td><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:var(--primary)"></span></td><td>CAM-001<br><span class="mono" style="font-size:11px;color:var(--muted)">860112070347838</span></td><td>JC400AD</td><td class="mono">31999990001</td><td><span class="pill-mock gray">online</span></td></tr>
    <tr><td><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:var(--primary)"></span></td><td>CAM-002<br><span class="mono" style="font-size:11px;color:var(--muted)">865478070003241</span></td><td>JC371</td><td class="mono">31999990002</td><td><span class="pill-mock gray">há 3h</span></td></tr>
    <tr style="opacity:.55"><td><span style="display:inline-block;width:12px;height:12px;border-radius:3px;border:1.5px solid var(--hairline)"></span></td><td>CAM-003<br><span class="mono" style="font-size:11px;color:var(--muted)">860112070399001</span></td><td>JC371</td><td style="color:var(--warning-text)">chip sem número cadastrado — cadastrar</td><td><span class="pill-mock gray">sem contato registrado</span></td></tr>
    </table>

    <div style="display:flex;gap:12px;align-items:center;margin:12px 0 16px">
        <span class="btn-mock">Enviar por SMS</span>
        <span style="font-size:12px;color:var(--muted)">2 selecionados · 2 créditos</span>
    </div>

    <div class="form-mock">
        <div class="form-mock-field"><label>Categoria</label><div class="input-mock">Todas</div></div>
        <div class="form-mock-field"><label>Comando</label><div class="input-mock">STATUS — Status do dispositivo</div></div>
        <div class="form-mock-field form-mock-full"><label>Parâmetros (à sua escolha)</label><div class="input-mock dim">Deixe em branco para consultar (STATUS#)</div></div>
        <div class="form-mock-field form-mock-full"><label>Texto que será enviado por SMS</label><div class="input-mock mono">STATUS#</div>
            <div style="font-size:12px;color:var(--muted)">consulta · 7/160 caracteres</div></div>
    </div>

    <div style="font-size:12px;font-weight:600;color:var(--muted);margin:20px 0 8px">Últimos envios por SMS</div>
    <table class="tbl-mock" style="font-size:12px">
    <tr><th>Quando</th><th>Equipamento</th><th>Comando</th><th>Envio</th><th>Entrega</th><th>Resposta do equipamento</th></tr>
    <tr><td>19/09 14:35</td><td>CAM-001<br><span class="mono" style="font-size:11px;color:var(--muted)">31999990001</span></td><td class="mono">STATUS#</td><td><span class="pill-mock green">aceito</span></td><td><span class="pill-mock gray">Entregue no aparelho</span></td><td class="mono" style="font-size:11px">Battery:12.4V; Mode:SLEEP<br><span style="color:var(--muted)">19/09 14:36</span></td></tr>
    <tr><td>19/09 14:35</td><td>CAM-002<br><span class="mono" style="font-size:11px;color:var(--muted)">31999990002</span></td><td class="mono">STATUS#</td><td><span class="pill-mock green">aceito</span></td><td><span class="pill-mock gray">aguardando retorno</span></td><td style="color:var(--muted)">—</td></tr>
    <tr><td>19/09 09:12</td><td>CAM-003<br><span class="mono" style="font-size:11px;color:var(--muted)">—</span></td><td class="mono">STATUS#</td><td><span class="pill-mock red">sem número</span></td><td><span class="pill-mock gray">aguardando retorno</span></td><td style="color:var(--muted)">—</td></tr>
    </table>
</div>
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Como enviar</h4>
<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Marcar os equipamentos</td><td>A lista mostra os equipamentos ativos do seu escopo, com modelo, número do chip e o último contato. Há busca por nome, IMEI, placa, modelo ou número. Administradores e revendedores também escolhem o cliente</td></tr>
<tr><td>Escolher o comando</td><td>O catálogo é o mesmo da tela Comandos. Filtre por categoria e escolha o comando; a descrição e os exemplos aparecem logo abaixo</td></tr>
<tr><td>Preencher os parâmetros</td><td>É um campo único, de texto livre. Quem escolhe os valores é você: a tela <strong>não confere quantos campos o modelo espera</strong>, então use os exemplos como guia</td></tr>
<tr><td>Deixar os parâmetros em branco</td><td>Envia a forma de <strong>consulta</strong> do comando, quando o catálogo conhece uma. Sem consulta conhecida, a tela avisa e pede que você digite os parâmetros</td></tr>
<tr><td>Conferir o texto</td><td>A caixa escura mostra exatamente o que será enviado, com o contador de caracteres. Acima de <strong>160 caracteres</strong> a tela recusa: a operadora partiria a mensagem e o equipamento receberia meio comando</td></tr>
<tr><td><strong>Enviar por SMS</strong></td><td>Pede confirmação mostrando o texto e quantos créditos serão gastos. Depois envia <strong>um SMS por equipamento</strong>, acompanhando "Enviando 2 de 5…". Se algum falhar, a tela lista quais e por quê; ao final a página recarrega e o histórico mostra os envios</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Equipamentos que não podem ser marcados</h4>
<table class="tbl-mock">
<tr><th>Situação</th><th>O que a tela faz</th></tr>
<tr><td>Chip sem número cadastrado, ou com número inválido</td><td>O equipamento aparece <strong>desabilitado, com o motivo escrito</strong> e um link para cadastrar o número em <a href="#chips">Chips SIM</a>. O SMS só vai para o número do chip cadastrado — não há outro destino</td></tr>
<tr><td>Comando que o modelo não documenta</td><td>Os equipamentos incompatíveis ficam esmaecidos e não podem ser marcados. É uma trava mais rígida que a da tela Comandos, porque por SMS não há aviso de "comando não suportado": o equipamento simplesmente ignora e o crédito já foi gasto</td></tr>
<tr><td>Equipamento sem contato recente</td><td><strong>Não trava.</strong> A coluna Contato (online, "há 3h", "há 2d"…) é só informativa — o SMS existe justamente para alcançar quem parou de se comunicar</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Acompanhar a entrega e a resposta</h4>
<p>O histórico <strong>Últimos envios por SMS</strong> mostra, para cada envio, três informações diferentes — e não devem ser confundidas:</p>
<table class="tbl-mock">
<tr><th>Coluna</th><th>O que responde</th></tr>
<tr><td>Envio</td><td>O provedor de SMS aceitou a mensagem? <strong>aceito</strong>, <strong>sem saldo</strong> (a conta ficou sem créditos), <strong>sem número</strong> (o chip não tem número utilizável) ou <strong>falhou</strong>. "Aceito" quer dizer só que a mensagem saiu do sistema — não que chegou</td></tr>
<tr><td>Entrega</td><td>Onde a mensagem está no caminho. <strong>aguardando retorno</strong> = o provedor ainda não informou nada. <strong>Enviado</strong> e <strong>Entregue à operadora</strong> = a caminho. <strong>Entregue no aparelho</strong> e <strong>Recebido</strong> = chegou. Já <strong>Cancelado</strong>, <strong>Duplicado</strong>, <strong>Saldo insuficiente</strong>, <strong>Número inválido</strong>, <strong>Expirado</strong> (aparelho indisponível), <strong>Lista negra</strong>, <strong>Bloqueado</strong> (restrição de operadora ou de conteúdo) e <strong>Não entregue</strong> = a mensagem não chegou. Um estado novo do provedor aparece escrito como veio</td></tr>
<tr><td>Resposta do equipamento</td><td>O texto que o equipamento devolveu por SMS, com a hora. Enquanto nada chegou, aparece um traço (—)</td></tr>
</table>

<div class="callout tip">
<strong>O histórico não atualiza sozinho.</strong> Diferente da tela Comandos, aqui não há acompanhamento automático: a entrega e a resposta aparecem quando chegarem, e para vê-las é preciso recarregar a página ou mexer em um filtro do histórico. Sem filtro, a lista mostra os <strong>10 envios mais recentes</strong>; com filtro (cliente, veículo/equipamento, data inicial e final) mostra até <strong>100</strong>. Todas as tentativas ficam registradas, inclusive as recusadas por falta de número ou de saldo.
</div>

<div class="callout info">
<strong>Permissão própria.</strong> Comandos por SMS tem a sua permissão em <a href="#grupos-permissao">Grupos de Permissão</a>, <strong>separada da tela Comandos</strong>: liberar uma não libera a outra. Não é uma tela exclusiva de administrador — quem recebe a permissão a usa. Quem não a tem não vê o item no menu.
</div>

<div class="callout info">
<strong>Saldo indisponível não bloqueia o envio.</strong> Se a consulta do saldo falhar, o cartão do topo mostra "saldo indisponível" e o motivo — a conta é corrigida por quem administra o sistema, em <a href="#config-sms">SMS (configuração)</a>. O envio continua liberado; se a conta realmente estiver sem créditos, o histórico registra o envio como <strong>sem saldo</strong>.
</div>

<table class="tbl-mock">
<tr><th></th><th>Comandos</th><th>Comandos por SMS</th></tr>
<tr><td><strong>Como chega</strong></td><td>Pela conexão normal do equipamento com o sistema</td><td>Pela rede da operadora, para o número do chip</td></tr>
<tr><td><strong>Custo</strong></td><td>Não consome crédito de SMS</td><td>1 crédito por equipamento, a cada envio</td></tr>
<tr><td><strong>Modelo incompatível</strong></td><td>Só avisa; o envio continua liberado</td><td>Bloqueia a seleção do equipamento</td></tr>
<tr><td><strong>Acompanhamento</strong></td><td>A tela verifica a resposta automaticamente por alguns minutos</td><td>Sem acompanhamento na tela; entrega e resposta entram no histórico quando chegarem</td></tr>
<tr><td><strong>Permissão</strong></td><td>Permissão de Comandos</td><td>Permissão própria de Comandos por SMS</td></tr>
</table>
