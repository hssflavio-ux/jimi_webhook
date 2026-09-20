<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Enviar comandos remotos para os equipamentos. A tela oferece o <strong>catálogo completo de comandos</strong>, com busca por nome ou sintaxe, e acompanha a resposta de cada envio. Ela foi feita para quem já conhece a sintaxe de cada modelo: <strong>não trava</strong> a escolha do comando nem do equipamento — apenas avisa quando algo não consta na documentação.</p>

<div class="mockup">
<div class="mockup-header">Comandos — Envio e Monitoramento</div>
<div class="mockup-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div>
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Enviar comando</div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <div class="filter-mock">Equipamentos: CAM-001 (marcado) · CAM-002 · CAM-003</div>
                <div class="filter-mock">Comando: buscar por nome ou sintaxe…</div>
                <div class="filter-mock">Parâmetros: (texto livre)</div>
                <div class="filter-mock">Será enviado: STATUS#</div>
                <span class="btn-mock" style="align-self:flex-start">Enviar para 1 equipamento</span>
            </div>
        </div>
        <div>
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Histórico de envios</div>
            <table class="tbl-mock" style="font-size:12px">
            <tr><th>Quando</th><th>Placa</th><th>Comando</th><th>Desfecho</th><th>Espera</th></tr>
            <tr><td>14:32</td><td class="mono">CAM-001</td><td>Status</td><td><span class="pill-mock green">Executado</span></td><td>4 s</td></tr>
            <tr><td>14:20</td><td class="mono">CAM-002</td><td>Vídeo Ao Vivo</td><td><span class="pill-mock blue">Aguardando</span></td><td>—</td></tr>
            </table>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Marcar um ou mais equipamentos</td><td>A lista traz a placa, a situação de contato (online ou há quanto tempo falou), o modelo e o protocolo (JIMI ou JT/T). Os links <em>todos</em> e <em>limpar</em> marcam ou desmarcam a lista inteira. Marcando vários, o mesmo comando vai para todos e a tela mostra o resultado de cada um</td></tr>
<tr><td>Buscar e escolher o comando</td><td>A lista é <strong>única, com todos os comandos</strong>, agrupada por categoria, com a sintaxe e a descrição de cada um; a busca filtra por nome ou sintaxe. Os comandos universais, que a documentação não prende a um modelo, vêm marcados com uma estrela. Marcar equipamentos <strong>não esconde</strong> comandos</td></tr>
<tr><td>Parâmetros</td><td>Um único campo de <strong>texto livre</strong>, escrito do jeito que o modelo entende. O campo mostra um exemplo como modelo, os exemplos catalogados são clicáveis (para preencher), e onde o comando tem forma de consulta aparece <em>Ler o valor atual</em>. A caixa <strong>Será enviado</strong> mostra o texto exato que irá ao equipamento</td></tr>
<tr><td>Enviar</td><td>O comando é enviado e o sistema aguarda a resposta do equipamento. Equipamento online: resposta em poucos segundos</td></tr>
<tr><td>Acompanhamento</td><td>A tela verifica a resposta automaticamente por alguns minutos</td></tr>
<tr><td>Sem resposta</td><td>Se o equipamento não responder, a tela avisa que o comando ficou aguardando na fila</td></tr>
</table>

<div class="callout info">
<strong>Modelo que a documentação do comando não cita: só aviso.</strong> Se um equipamento marcado não está entre os modelos para os quais o comando é documentado, a tela mostra uma nota amarela dizendo isso e pedindo que você confirme a sintaxe do modelo — e <strong>o envio continua liberado</strong>. Nenhum equipamento fica desabilitado e nenhum comando some da lista. (Em <a href="#comandos-sms" style="color:inherit">Comandos por SMS</a> a regra é outra: lá o modelo incompatível é bloqueado, porque por SMS o comando não suportado é ignorado e o crédito já foi gasto.)
</div>

<div class="callout info">
<strong>Equipamento sem contato recente: também só aviso.</strong> A tela lista os marcados que estão sem contato recente e explica que o comando fica <strong>na fila</strong> e é entregue quando o equipamento reconectar; a resposta só aparece no histórico depois disso. O botão continua liberado.
</div>

<div class="callout warn">
<strong>Atualização de firmware (UPDATE): um modelo por vez.</strong> Ao escolher o comando <em>UPDATE</em>, a tela lista os pacotes cadastrados em <a href="#firmwares" style="color:inherit">Firmware</a> para o modelo marcado, e você clica no pacote para preencher o endereço. Como o envio manda o mesmo endereço a todos os marcados, com <strong>modelos diferentes marcados o botão fica bloqueado</strong> — o pacote de um modelo aplicado em outro não dá erro, o equipamento baixa e instala.
</div>

<h4 style="font-size:14px;font-weight:600;margin:20px 0 8px">Histórico de envios</h4>
<ul style="font-size:13px;line-height:1.8;color:var(--body)">
    <li><strong>Colunas:</strong> Quando, Placa (com o modelo), Comando (com o texto enviado), Desfecho e Espera. Clicar numa linha abre o detalhe do comando e da resposta</li>
    <li><strong>Números clicáveis</strong> no alto — executados, aguardando, com erro e informativos: cada um filtra a lista por aquele desfecho, e o mesmo número clicado de novo remove o filtro</li>
    <li><strong>Filtros:</strong> Placa, período (De e Até) e Desfecho; administradores e revendedores também escolhem o Cliente. Dá para <strong>exportar</strong> o resultado em Excel ou PDF</li>
    <li><strong>Atualizar a cada 30 s:</strong> caixa que vem <em>desligada</em>; a escolha fica guardada no seu navegador</li>
    <li><strong>Fila do comando:</strong> um comando enviado a equipamento sem contato aparece com a marca <em>na fila (a confirmar)</em>; em até 10 minutos o sistema confere e troca por <em>na fila (confirmado)</em> — ainda aguardando o equipamento — ou <em>saiu da fila</em> — pode ter sido entregue (aguarde o histórico) ou ter expirado sem confirmação</li>
</ul>

<div class="callout tip">
<strong>Comandos estruturados (JT/T).</strong> Quando os equipamentos marcados são todos JT/T, a lista também oferece alguns comandos estruturados, cujo conteúdo é um texto JSON — esse texto aparece num campo próprio, sempre visível, quando o comando é escolhido.
</div>
