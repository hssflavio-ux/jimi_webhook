<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Cadastro completo de equipamentos com periféricos (câmeras, sensores), configurações de vídeo (rotação, marca d'água), firmware, filial e chip SIM vinculado. Suporte a importação em lote via CSV.</p>

<div class="mockup">
<div class="mockup-header">Equipamentos — Cadastro Completo</div>
<div class="mockup-body">
    <div style="display:flex;gap:8px;margin-bottom:16px">
        <span class="btn-mock">+ Cadastrar</span>
        <span class="btn-mock outline">Atualizar Firmware</span>
        <span class="btn-mock outline">Importar CSV</span>
    </div>
    <div class="form-mock">
        <div class="form-mock-field"><label>Modelo *</label><div class="input-mock">JC450</div></div>
        <div class="form-mock-field"><label>IMEI *</label><div class="input-mock dim" style="font-family:'JetBrains Mono',monospace">860112070347838</div></div>
        <div class="form-mock-field"><label>Chip SIM</label><div class="input-mock dim">Selecione...</div></div>
        <div class="form-mock-field"><label>Filial</label><div class="input-mock dim">Matriz</div></div>
        <div class="form-mock-field form-mock-full"><label>Periféricos</label>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
                <span class="icon-feature" style="background:var(--primary-soft);color:var(--primary)">Câmera Frontal</span>
                <span class="icon-feature" style="background:var(--primary-soft);color:var(--primary)">Câmera Lateral</span>
                <span class="icon-feature" style="background:#f0f1f3;color:var(--muted)">+ Adicionar</span>
            </div>
        </div>
        <div class="form-mock-field"><label>Rotação do Vídeo</label><div class="input-mock">0°</div></div>
        <div class="form-mock-field"><label>Marca d'Água</label><div class="input-mock dim">Texto opcional...</div></div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Cadastrar equipamento</td><td>Cria novo device com modelo, IMEI, chip, periféricos, rotação, marca d'água e firmware</td></tr>
<tr><td>Importar CSV</td><td>Envie um arquivo CSV com as colunas imei, nome, modelo e nº de câmeras — os equipamentos são cadastrados em lote</td></tr>
<tr><td>Atualizar Firmware</td><td>Abre janela para enviar a atualização de software ao equipamento</td></tr>
<tr><td>Selecionar periféricos</td><td>Tags clicáveis (estilo chip) para adicionar/remover periféricos do dispositivo</td></tr>
<tr><td><strong>Limite de velocidade</strong></td><td>Limite em km/h só deste equipamento, usado no relatório de <a href="#rel-velocidade" style="color:inherit">Excesso de Velocidade</a>. Em branco, vale o limite padrão do cliente</td></tr>
</table>

<div class="callout">
<strong>Nem todo equipamento é câmera: os rastreadores JM-VL01 e JM-VL02.</strong>
Os dois modelos da linha <strong>JM</strong> são <em>rastreadores</em> — têm GPS, ignição,
botão de pânico, bloqueio por relé e alarmes, mas <strong>nenhuma câmera</strong>.
Ao escolher um deles no campo <em>Modelo</em>, o campo <em>Canais</em> vai para
<strong>0</strong> e trava: é o valor certo, não um cadastro incompleto.
<br><br>
Um veículo com rastreador continua aparecendo no Rastreamento, nos Trajetos, nos
Alertas e nos Relatórios como qualquer outro — o que não aparece são as telas de
vídeo (<em>Ao Vivo</em>, <em>Playback</em> e as abas de vídeo do ativo) e as
<em>Configurações IA</em>, porque dependem de câmera. Os <a href="#comandos" style="color:inherit">Comandos</a>
funcionam normalmente: a linha JM tem comandos próprios (cerca no equipamento,
hotspot WiFi, alarme de movimento, corte de energia), e a tela mostra só os que
valem para o modelo marcado.
<br><br>
⚠️ <strong>Não confunda a linha JM com a linha JC.</strong> <em>JC</em> (JC400,
JC371, JC450, JC181, JC182) é a linha de <strong>câmeras</strong>; <em>JM</em> é a
de rastreadores. Vários comandos existem nas duas com o mesmo nome e um número
diferente de campos — por isso a tela de Comandos só oferece a forma certa depois
que você marca o equipamento.
<br><br>
<strong>Sem câmera não quer dizer sem recurso.</strong> O <strong>JM-VL01</strong>
é <strong>hotspot WiFi</strong> — dá para abrir a rede pelo comando
<code>HOTSPOT</code>, em Comandos. Ele também tem bloqueio por relé, botão de
pânico, cerca gravada no próprio equipamento, alarme de movimento e aviso de corte
de energia. O <strong>JM-VL02</strong> não tem rádio WiFi (é Cat-M1/NB2), mas
ganha uma segunda saída (<code>OUT2</code>), sensor de porta e alarme de colisão.
</div>
