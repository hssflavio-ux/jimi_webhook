<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Cadastrar os equipamentos da frota — câmeras e rastreadores — cada um com o seu chip SIM. É o <strong>segundo passo</strong> do cadastro: o equipamento nasce livre e só vai para um veículo quando for instalado, em <a href="#ativos" style="color:inherit">Ativos</a>. Também permite filtrar a lista, importar vários equipamentos de uma vez por CSV e conferir chip, bateria e firmware de cada um.</p>

<div class="callout info">
<strong>Onde este passo se encaixa: chip → câmera → veículo.</strong> O chip já precisa existir em <a href="#chips" style="color:inherit">Chips</a>, ativo e livre. Aqui você cadastra o equipamento escolhendo esse chip no campo <em>Chip (SIM)</em> — é o <strong>único lugar</strong> onde chip e câmera se vinculam. Só depois, em Ativos, a câmera é instalada num veículo, e a instalação exige que ela já tenha chip.
</div>

<div class="mockup">
<div class="mockup-header">Equipamentos — Cadastro</div>
<div class="mockup-body">
    <div class="form-mock">
        <div class="form-mock-field"><label>IMEI *</label><div class="input-mock" style="font-family:'JetBrains Mono',monospace">860112070347838</div></div>
        <div class="form-mock-field"><label>Nome (rótulo interno)</label><div class="input-mock dim">Câmera JC400AD #12</div></div>
        <div class="form-mock-field"><label>Chip (SIM)</label><div class="input-mock">Vivo — 5511999990001</div></div>
        <div class="form-mock-field"><label>Modelo</label><div class="input-mock">JC400AD (JIMI, 2 câm.)</div></div>
        <div class="form-mock-field"><label>Canais (Câmeras)</label><div class="input-mock">2</div></div>
        <div class="form-mock-field"><label>Filial</label><div class="input-mock dim">— Nenhuma —</div></div>
        <div class="form-mock-field form-mock-full"><label>Periféricos</label>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
                <span class="icon-feature" style="background:var(--primary-soft);color:var(--primary)">GPS</span>
                <span class="icon-feature" style="background:var(--primary-soft);color:var(--primary)">Câmera Interna</span>
                <span class="icon-feature" style="background:#f0f1f3;color:var(--muted)">Bluetooth</span>
                <span class="icon-feature" style="background:#f0f1f3;color:var(--muted)">Leitor RFID</span>
            </div>
        </div>
        <div class="form-mock-field"><label>Rotação do Streaming</label><div class="input-mock">0°</div></div>
        <div class="form-mock-field"><label>Cliente</label><div class="input-mock dim">Transportes Exemplo</div></div>
    </div>
    <div style="margin-top:12px"><span class="btn-mock">Cadastrar Equipamento</span></div>
</div>
</div>

<div class="mockup">
<div class="mockup-header">Equipamentos — Lista</div>
<div class="mockup-body">
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <span class="btn-mock">+ Cadastrar</span>
        <span class="btn-mock outline">Atualizar Firmware</span>
        <span class="btn-mock outline">Importar em Lote</span>
    </div>
    <table class="tbl-mock">
    <tr><th>IMEI</th><th>Nome</th><th>Modelo</th><th>Chip</th><th>Firmware</th><th>Situação</th><th>Status</th><th>Ações</th></tr>
    <tr>
        <td class="mono">860112070347838</td><td>Câmera JC400AD #12</td><td>JC400AD (2ch)</td><td class="mono">5511999990001</td><td class="mono">V1.8.1.2_250904</td><td><span class="pill-mock green">Online</span></td><td><span class="pill-mock green">Ativo</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span> <span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">FOTA</span></td>
    </tr>
    <tr>
        <td class="mono">860112070999001</td><td>Rastreador #3</td><td>JM-VL01 (rastreador)</td><td class="mono">5511999990002</td><td>—</td><td><span class="pill-mock red">Offline</span></td><td><span class="pill-mock green">Ativo</span></td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span></td>
    </tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>+ Cadastrar</td><td>Abre o formulário. O <strong>IMEI</strong> é obrigatório (e, para administrador e revendedor, também o <em>Cliente</em>); se o IMEI já existir, a tela avisa "IMEI já cadastrado". Confirma com "Equipamento cadastrado com sucesso"</td></tr>
<tr><td>Nome (rótulo interno)</td><td>Opcional. Identifica o equipamento no estoque — <strong>não é a placa do veículo</strong>, que é cadastrada em Ativos. Em branco, vale o IMEI</td></tr>
<tr><td><strong>Chip (SIM)</strong></td><td>Lista só os chips <strong>ativos e ainda sem câmera</strong> (e o que já está vinculado a este equipamento, na edição). Sem chip livre, o texto de ajuda manda cadastrar um em Chips. Escolher <em>— Nenhum —</em> desvincula o chip. Se outro cadastro pegar o chip enquanto você preenche, o equipamento é salvo sem chip e a tela avisa</td></tr>
<tr><td>Modelo e Canais (Câmeras)</td><td>O <strong>modelo</strong> decide se o equipamento é câmera ou rastreador: ao lado de cada modelo a lista mostra quantas câmeras ele tem, ou "rastreador, sem câmera". Escolher o modelo já preenche <em>Canais</em> (o máximo daquele modelo)</td></tr>
<tr><td>Editar</td><td>Abre "Editar Equipamento" (o IMEI não muda); <em>Salvar Alterações</em> grava ("Equipamento atualizado"). Trocar só o chip já basta para salvar</td></tr>
<tr><td>Equipamento Ativo</td><td>Desmarcar tira o equipamento de operação, e <strong>só é permitido com a câmera livre</strong>. Desativar <strong>libera o chip automaticamente</strong>. Câmera instalada num veículo não pode ser desativada: a tela avisa para desinstalá-la antes, em Ativos. Equipamentos não são apagados — só desativados</td></tr>
<tr><td>Cliente</td><td>Administrador e revendedor escolhem o cliente (obrigatório); os demais veem o próprio cliente, sem poder trocar. <strong>Com a câmera instalada, o campo vira somente leitura</strong> e mostra o cliente do veículo. Câmera livre pode ter o cliente editado aqui</td></tr>
<tr><td>Importar em Lote</td><td>Envie um arquivo CSV com as colunas <strong>IMEI, Nome, Modelo, Canais, Firmware</strong> (a primeira linha é o cabeçalho e é ignorada). Administrador e revendedor escolhem o cliente, e todos os equipamentos do arquivo vão para ele. Ao terminar, a janela mostra o resultado — "N importado(s), M ignorado(s)" — e a lista é recarregada quando você a fecha. Linhas com IMEI inválido (fora de 15 a 17 dígitos) ou já cadastrado são ignoradas, e o resultado <strong>lista quais</strong>, com o número da linha (contado a partir do cabeçalho; linhas em branco do arquivo não entram na conta) e o motivo. São mostrados até 10 avisos; passando disso, aparece só quantos ficaram de fora da lista. Modelo desconhecido entra sem modelo e também gera um aviso</td></tr>
<tr><td>Atualizar Firmware / FOTA</td><td>Levam à tela <a href="#firmwares" style="color:inherit">Firmware</a> (só para administradores). O botão FOTA da linha aparece quando o equipamento está online</td></tr>
<tr><td>Filtros</td><td><strong>Cliente</strong> (administrador e revendedor), <strong>Modelo</strong>, <strong>Situação</strong> (Online/Offline), <strong>Status</strong> (Ativo/Inativo) e <strong>Busca</strong> por IMEI ou nome; <em>Filtrar</em> aplica e <em>Limpar</em> volta ao padrão</td></tr>
<tr><td>Situação</td><td>Online quando o equipamento se comunicou nos últimos 5 minutos. Equipamento desativado mostra "—": está fora de operação, não "fora do ar"</td></tr>
<tr><td>Exportar Excel / PDF</td><td>Baixa a lista com os filtros aplicados (IMEI, nome, modelo, cliente, chip, último heartbeat, bateria, firmware, periféricos, situação e status)</td></tr>
<tr><td>Periféricos, Firmware, Filial</td><td>Marque os periféricos que o equipamento tem (GPS, WiFi, Bluetooth, Leitor RFID, câmeras, sensores…); <em>Firmware</em> guarda a versão instalada (a tela Firmware também a preenche ao ler a versão do equipamento); <em>Filial</em> associa o equipamento a uma filial</td></tr>
<tr><td>Rotação do Streaming e Marca d'água</td><td>Aplicadas ao vídeo ao vivo do equipamento: rotação de 0°, 90°, 180° ou 270° e marca d'água no streaming</td></tr>
<tr><td><strong>Limite de velocidade</strong></td><td>Limite em km/h só deste equipamento, usado no relatório de <a href="#rel-velocidade" style="color:inherit">Excesso de Velocidade</a>. Em branco, vale o limite padrão do cliente</td></tr>
</table>

<div class="callout warn">
<strong>Importar em lote não vincula chip.</strong> Todo equipamento importado por CSV nasce ativo e <strong>sem chip</strong>. Antes de instalá-lo num veículo, edite cada um e escolha o chip no campo <em>Chip (SIM)</em> — sem isso a instalação em Ativos é recusada.
</div>

<div class="callout info">
<strong>Enquanto a câmera está instalada, ela pertence ao veículo.</strong> O cliente dela é o do veículo, e ela não pode ser desativada. Para mudar o cliente ou desativar, desinstale-a primeiro na ficha do veículo, em <a href="#ativos" style="color:inherit">Ativos</a>. Desinstalar libera a câmera para outro veículo; o chip continua com ela.
</div>

<div class="callout warn">
<strong>Cadastrei o equipamento e o relatório está vazio.</strong> Não é defeito: o equipamento só passa a aparecer nos relatórios depois que o cadastro está completo — <strong>chip → equipamento → veículo</strong>. Aparecer em Equipamentos e ficar <em>Online</em> não basta; falta instalar a câmera num veículo, em <a href="#ativos" style="color:inherit">Ativos</a>. Até lá, o que ela transmite fica sem dono, de propósito, para que posição de bancada ou de estoque não entre na operação do cliente.
</div>

<div class="callout">
<strong>Nem todo equipamento é câmera: os rastreadores JM-VL01 e JM-VL02.</strong>
Os dois modelos da linha <strong>JM</strong> são <em>rastreadores</em> — têm GPS, ignição,
botão de pânico, bloqueio por relé e alarmes, mas <strong>nenhuma câmera</strong>: sem vídeo
e sem os alertas de inteligência artificial da câmera (como distração, uso de celular e
sem cinto). Quem define se o equipamento é câmera ou rastreador é o <strong>modelo</strong>
escolhido no cadastro. Ao escolher um JM, o campo <em>Canais</em> vai para
<strong>0</strong> e trava: é o valor certo, não um cadastro incompleto. Na lista, o modelo
aparece com a marca <em>(rastreador)</em>.
<br><br>
Um veículo com rastreador continua aparecendo no <a href="#rastreamento" style="color:inherit">Rastreamento</a>,
nos Trajetos e nas Posições como qualquer outro, e os alarmes de condução dele entram em
<a href="#rel-dirigibilidade" style="color:inherit">Alertas Dirigibilidade</a>. O que não aparece são as telas de
vídeo (<em>Ao Vivo</em>, <em>Playback</em> e as abas Ao Vivo e Vídeo da ficha do veículo) e as
<em>Configurações IA</em>, porque dependem de câmera; e o relatório
<a href="#rel-alarmes" style="color:inherit">Alertas Videomonitoramento</a> não lista rastreador — os demais alarmes dele ficam na aba
<em>Alertas</em> da ficha do veículo. Os <a href="#comandos" style="color:inherit">Comandos</a>
funcionam normalmente, e a tela mostra só os que valem para o modelo marcado.
<br><br>
<strong>Não confunda a linha JM com a linha JC.</strong> <em>JC</em> (JC400,
JC371, JC450, JC181, JC182) é a linha de <strong>câmeras</strong>; <em>JM</em> é a
de rastreadores. Vários comandos existem nas duas com o mesmo nome e um número
diferente de campos — por isso a tela de Comandos só oferece a forma certa depois
que você marca o equipamento.
<br><br>
<strong>Sem câmera não quer dizer sem recurso.</strong> O <strong>JM-VL01</strong>
tem <strong>hotspot WiFi</strong>: dá para abrir a rede pelo comando <em>Hotspot WiFi</em>,
em Comandos. O <strong>JM-VL02</strong> não tem rádio WiFi.
</div>
