<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Não perder o prazo de uma revisão nem o vencimento de um documento do motorista. Você cadastra um <strong>lembrete</strong> com a regra (a cada tantos quilômetros, tantas horas ou numa data) e o sistema acompanha o veículo e avisa quando o vencimento se aproxima. A tela tem duas abas: <strong>Manutenção</strong> e <strong>Documentos</strong>. Ela fica no menu principal, junto de Painel e Rastreamento.</p>

<div class="mockup">
<div class="mockup-header">Manutenção — Lembretes</div>
<div class="mockup-body">
    <div style="display:flex;gap:16px;border-bottom:1px solid var(--hairline);margin-bottom:12px;font-size:13px;font-weight:600">
        <span style="color:var(--primary);border-bottom:2px solid var(--primary);padding:6px 2px;margin-bottom:-1px">Manutenção</span>
        <span style="color:var(--muted);padding:6px 2px">Documentos</span>
    </div>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div style="flex:2;min-width:300px">
            <table class="tbl-mock">
            <tr><th>Nome</th><th>Vínculo</th><th>Métrica</th><th>Atual</th><th>Vencimento</th><th>Status</th></tr>
            <tr><td>Troca de óleo</td><td>ABC1D23</td><td>Odômetro</td><td class="mono">48.900 km</td><td class="mono">49.000 km</td><td><span class="pill-mock yellow">Próximo do vencimento</span></td></tr>
            <tr><td>Revisão dos freios</td><td>Frota 07</td><td>Horas de Ignição</td><td class="mono">262,0 h desde a última</td><td class="mono">250,0 h</td><td><span class="pill-mock red">Vencido</span></td></tr>
            <tr><td>Licenciamento</td><td>Frota 03</td><td>Data</td><td class="mono">19/09/2026</td><td class="mono">30/11/2026</td><td><span class="pill-mock green">Em dia</span></td></tr>
            <tr><td>Troca de correia</td><td>Frota 11</td><td>Horímetro</td><td class="mono">—</td><td class="mono">—</td><td><span class="pill-mock gray">Sem dado suficiente</span></td></tr>
            </table>
        </div>
        <div style="flex:1;min-width:220px">
            <div class="form-mock" style="display:flex;flex-direction:column;gap:10px">
                <div class="form-mock-field"><label>Nome *</label><div class="input-mock">Troca de óleo</div></div>
                <div class="form-mock-field"><label>Métrica *</label><div class="input-mock">Odômetro</div></div>
                <div class="form-mock-field"><label>Ativo (veículo)</label><div class="input-mock">ABC1D23</div></div>
                <div class="form-mock-field"><label>Intervalo (km)</label><div class="input-mock">10000</div></div>
                <div class="form-mock-field"><label>E-mails (até 3)</label><div class="input-mock dim">oficina@empresa.com.br</div></div>
            </div>
            <div style="margin-top:10px"><span class="btn-mock">Criar Lembrete</span></div>
        </div>
    </div>
</div>
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Aba Manutenção: cadastrar um lembrete</h4>
<p>O formulário fica ao lado da lista. Os campos mudam conforme a métrica escolhida: só aparece o que aquela métrica usa.</p>

<table class="tbl-mock">
<tr><th>Campo</th><th>O que preencher</th></tr>
<tr><td>Nome *</td><td>Como o lembrete aparece na lista e no aviso. Ex.: Troca de óleo</td></tr>
<tr><td>Métrica *</td><td>Como o vencimento é medido: <strong>Odômetro</strong>, <strong>Horas de Ignição</strong>, <strong>Horímetro</strong> ou <strong>Data</strong> (veja a tabela abaixo)</td></tr>
<tr><td>Ativo (veículo)</td><td><strong>Obrigatório</strong> em Odômetro, Horas de Ignição e Horímetro. Não aparece na métrica Data</td></tr>
<tr><td>Motorista (opcional)</td><td>Associa o lembrete a um motorista. Na lista, a coluna <em>Vínculo</em> mostra o veículo; o motorista só aparece ali quando o lembrete não tem veículo</td></tr>
<tr><td>Intervalo (km)</td><td>Só para Odômetro: a cada quantos quilômetros o serviço se repete. Ex.: 10000</td></tr>
<tr><td>Intervalo (horas)</td><td>Só para Horas de Ignição e Horímetro: a cada quantas horas. Ex.: 250</td></tr>
<tr><td>Data de Vencimento</td><td>Só para a métrica Data. É obrigatória nela</td></tr>
<tr><td>Notificar pelo sino</td><td>Vem marcado ao criar. Veja <em>Como o aviso chega</em> mais abaixo</td></tr>
<tr><td>Notificar por e-mail</td><td>Até 3 endereços, separados por vírgula. Se a caixa estiver marcada, é preciso informar pelo menos um endereço válido; endereços inválidos e repetidos são descartados</td></tr>
<tr><td>Ativo</td><td>Vem marcado ao criar. Desmarcado, o lembrete fica esmaecido na lista e <strong>deixa de avisar</strong></td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">As quatro métricas</h4>

<table class="tbl-mock">
<tr><th>Métrica</th><th>Como o vencimento é calculado</th><th>Vira "Próximo do vencimento" quando faltam</th></tr>
<tr><td>Odômetro</td><td>Leitura do odômetro do veículo no último serviço somada ao intervalo em km. A coluna <em>Atual</em> mostra a leitura mais recente</td><td>200 km ou menos</td></tr>
<tr><td>Horas de Ignição</td><td>Soma do tempo com o <strong>motor ligado</strong> (em movimento ou parado) desde o último serviço, calculada pelo sistema a partir do histórico de ignição. Não depende de o equipamento informar horímetro</td><td>10 horas ou menos</td></tr>
<tr><td>Horímetro</td><td>Leitura de horímetro <strong>informada pelo próprio equipamento</strong> no último serviço somada ao intervalo em horas</td><td>10 horas ou menos</td></tr>
<tr><td>Data</td><td>Uma data de vencimento única, sem repetição</td><td>7 dias ou menos</td></tr>
</table>
<p>Em qualquer métrica, o item vira <span class="pill-mock red">Vencido</span> quando o que falta chega a zero ou passa dele. As linhas inativas aparecem esmaecidas, e a lista mostra primeiro os lembretes ativos, em ordem alfabética.</p>

<div class="callout warn">
<strong>"Sem dado suficiente" quer dizer que o sistema não consegue calcular.</strong> Acontece quando falta o intervalo, quando o equipamento não informa a leitura de que a métrica precisa (odômetro ausente ou zerado, ou horímetro que ele não envia) ou quando, na criação, ainda não havia nenhuma leitura para servir de ponto de partida. Se for o caso do horímetro, use a métrica <strong>Horas de Ignição</strong>, que o sistema calcula sozinho. Ao <strong>Registrar concluído</strong> com uma leitura já disponível, o ponto de partida passa a existir e o cálculo começa.
</div>

<div class="callout info">
<strong>A contagem começa no cadastro.</strong> Ao criar um lembrete de Odômetro ou Horímetro, o sistema assume que o serviço foi feito <em>agora</em> e guarda a leitura desse momento como ponto de partida. <strong>Editar não zera a contagem</strong> — mudar o intervalo apenas recalcula o vencimento a partir do mesmo ponto de partida. Só o botão <strong>Registrar concluído</strong> reinicia.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Botões da lista</h4>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Pesquisar</td><td>Procura pelo nome do lembrete, pelo veículo ou pelo motorista. A lista tem 25 lembretes por página</td></tr>
<tr><td>Editar</td><td>Carrega o lembrete no painel à direita ("Editar Lembrete"). <strong>Cancelar</strong> volta ao cadastro de um novo</td></tr>
<tr><td>Registrar concluído</td><td>Marca o serviço como feito e reinicia a contagem: Odômetro e Horímetro passam a partir da leitura atual, Horas de Ignição volta a contar de agora. Na métrica <strong>Data</strong>, o lembrete é <strong>desativado</strong> — o sistema não inventa uma próxima data; cadastre outro lembrete se ela se repetir</td></tr>
<tr><td>Remover</td><td>Pede confirmação e exclui o lembrete definitivamente</td></tr>
</table>
<p>Criar, editar, remover e registrar como concluído ficam registrados na <a href="#auditoria">Auditoria</a>, com quem fez e quando.</p>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Como o aviso chega</h4>
<p>O sistema confere os lembretes ativos <strong>uma vez por dia</strong>. Quando um item está <em>Próximo do vencimento</em>, sai o aviso "Manutenção próxima: <em>nome</em>"; quando está <em>Vencido</em>, sai "Manutenção vencida: <em>nome</em>", como alerta crítico e com pop-up. Enquanto o item continuar próximo ou vencido, <strong>o aviso se repete todos os dias</strong>, até você registrar como concluído (ou desativar o lembrete). Como a conferência é diária, a situação na tela pode mudar horas antes de o aviso sair. Sobre o sino e o pop-up, veja <a href="#notificacoes">Notificações</a>.</p>

<div class="callout tip">
<strong>O sino é o canal padrão.</strong> O aviso sempre é gravado no sino; o e-mail é um acréscimo para os endereços informados. Por isso, com "Notificar por e-mail" marcado, o aviso também aparece no sino <em>mesmo com a caixa do sino desmarcada</em>. Desmarcar o sino sem informar e-mail nenhum faz o lembrete não avisar por canal algum.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Aba Documentos: CNH e exame toxicológico</h4>
<p>A aba lista os motoristas ativos com o vencimento da <strong>CNH</strong> e do <strong>exame toxicológico</strong>, cada um com uma caixa de <em>Lembrete</em>. Marcar ou desmarcar a caixa <strong>grava na hora</strong>, sem botão de salvar. Documento com a data já passada aparece em vermelho, com a marca <span class="pill-mock red">Vencida</span> (CNH) ou <span class="pill-mock red">Vencido</span> (toxicológico).</p>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Caixa Lembrete CNH / Lembrete Tox.</td><td>Liga ou desliga o aviso daquele documento para aquele motorista</td></tr>
<tr><td>Data de vencimento</td><td><strong>Não se edita aqui.</strong> Ela é cadastrada na tela de <a href="#motoristas">Motoristas</a>; nesta aba só se liga ou desliga o aviso</td></tr>
</table>

<p>Com a caixa marcada e a data cadastrada, o aviso sai quando o vencimento estiver a <strong>7 dias ou menos</strong> — e continua saindo todos os dias depois de vencido, até a data ser atualizada em Motoristas ou a caixa ser desmarcada. Os avisos de documento vão <strong>só para o sino</strong>: esta aba não tem campo de e-mail.</p>

<div class="callout info">
<strong>Quem vê o quê.</strong> Cada cliente vê apenas os próprios lembretes e motoristas. O administrador da plataforma vê os de todos os clientes e escolhe o cliente no formulário ao criar um lembrete.
</div>

<div class="callout tip">
<strong>Veículo aparece com o nome gravado no equipamento.</strong> A lista e o seletor <em>Ativo (veículo)</em> mostram o nome cadastrado no <a href="#equipamentos">equipamento</a>; quando ele está em branco, o veículo aparece como <span class="mono">(sem placa)</span> seguido do IMEI. Se aparecer assim, ajuste o nome do equipamento para reconhecer o veículo na hora de escolher.
</div>
