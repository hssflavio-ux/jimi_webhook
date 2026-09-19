<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Cadastrar os chips SIM (a linha de dados) que ficam dentro dos equipamentos. O chip é o <strong>primeiro passo</strong> do cadastro: primeiro o chip, depois a câmera que o usa (em <a href="#equipamentos" style="color:inherit">Equipamentos</a>) e por fim o veículo em que ela é instalada (em <a href="#ativos" style="color:inherit">Ativos</a>). O chip é cadastrado no cliente ativo, o que aparece no topo do menu lateral.</p>

<div class="mockup">
<div class="mockup-header">Chips SIM — Lista e Formulário</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Operadora</th><th>Número (MSISDN)</th><th>ICCID</th><th>IMEI (vinculado)</th><th>Status</th><th></th></tr>
    <tr>
        <td>Vivo</td><td class="mono">5511999990001</td><td class="mono">89551012345678901234</td><td class="mono">860112070347838<br><span style="font-size:11px;color:var(--muted)">Câmera JC400AD #12</span></td><td><span class="pill-mock green">Ativo</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span> <span class="btn-mock danger" style="font-size:12px;padding:2px 8px">Remover</span></td>
    </tr>
    <tr>
        <td>TIM</td><td class="mono">5511999990002</td><td class="mono">89550212345678904567</td><td>-</td><td><span class="pill-mock green">Ativo</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span> <span class="btn-mock danger" style="font-size:12px;padding:2px 8px">Remover</span></td>
    </tr>
    </table>
    <div class="form-mock" style="margin-top:16px">
        <div class="form-mock-field"><label>Operadora</label><div class="input-mock dim">Vivo, Claro, TIM...</div></div>
        <div class="form-mock-field"><label>Número (MSISDN)</label><div class="input-mock dim" style="font-family:'JetBrains Mono',monospace">5511999999999</div></div>
        <div class="form-mock-field"><label>ICCID</label><div class="input-mock dim" style="font-family:'JetBrains Mono',monospace">8955...</div></div>
        <div class="form-mock-field"><label>Câmera vinculada <span style="font-weight:400">(só na edição)</span></label><div class="input-mock dim">Nenhuma — chip livre</div></div>
    </div>
    <div style="margin-top:12px"><span class="btn-mock">Criar Chip</span></div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar Chip</td><td>Preencha <strong>Operadora</strong>, <strong>Número (MSISDN)</strong> e/ou <strong>ICCID</strong> — pelo menos um dos três — e deixe <em>Ativo</em> marcado. Confirma com "Chip criado com sucesso". O chip nasce sempre <strong>livre</strong>, sem câmera</td></tr>
<tr><td>Editar</td><td>Traz o chip para o formulário ao lado; <em>Salvar</em> grava ("Chip atualizado"). Operadora, número, ICCID e a marca <em>Ativo</em> podem ser alterados</td></tr>
<tr><td>Câmera vinculada</td><td>Aparece só ao editar, em <strong>somente leitura</strong>: mostra o IMEI da câmera que usa o chip, ou "Nenhuma — chip livre". Não é um campo para escolher câmera (veja o aviso abaixo)</td></tr>
<tr><td>Desativar (desmarcar <em>Ativo</em>)</td><td>Só é permitido com o chip <strong>livre</strong>. Se ele estiver numa câmera, a tela recusa e informa o IMEI dela: desvincule antes, em Equipamentos</td></tr>
<tr><td>Remover</td><td>Pede confirmação ("Remover este chip?") e exclui o registro definitivamente</td></tr>
<tr><td>Clicar no IMEI</td><td>Abre a ficha do veículo em que essa câmera está instalada agora. Se a câmera ainda não foi instalada num veículo, não há ficha para abrir</td></tr>
<tr><td>Pesquisar</td><td>Filtra por operadora, número, ICCID ou IMEI</td></tr>
<tr><td>Exportar Excel / PDF</td><td>Baixa a lista (operadora, número, ICCID, IMEI, equipamento e status), respeitando a pesquisa</td></tr>
</table>

<div class="callout warn">
<strong>Quem escolhe o chip é a câmera, nunca o contrário.</strong> O vínculo entre chip e câmera é feito <strong>só em Equipamentos</strong>: ao cadastrar ou editar a câmera, você escolhe o chip no campo <em>Chip (SIM)</em>. Aqui em Chips o formulário não tem campo para isso — apenas mostra a câmera vinculada, e o texto de ajuda traz o caminho: <em>"Para trocar ou desvincular, edite a câmera em Equipamentos"</em>. Para vincular um chip livre, escolha-o ao cadastrar ou editar uma câmera.
</div>

<div class="callout info">
<strong>Quando um chip fica livre de novo.</strong> O chip é liberado quando a câmera é <em>desvinculada dele</em> (em Equipamentos, escolhendo <em>— Nenhum —</em> no campo Chip) ou quando a câmera é <em>desativada</em> — desativar a câmera libera o chip automaticamente. Já <strong>desinstalar</strong> a câmera de um veículo <em>não</em> libera o chip: ele continua com ela. Só chips <strong>ativos e livres</strong> aparecem na lista de Equipamentos.
</div>
