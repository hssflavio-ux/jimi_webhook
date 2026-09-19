<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Cadastrar os veículos da frota e controlar qual câmera está instalada em cada um. O veículo existe por conta própria, mesmo sem câmera: a câmera é cadastrada à parte, em <a href="#equipamentos" style="color:inherit">Equipamentos</a>, e depois instalada no veículo aqui. A lista mostra os veículos do cliente ativo (o que aparece no topo do menu lateral).</p>

<div class="callout info">
<strong>A ordem do cadastro: chip, câmera, veículo.</strong> Cada passo depende do anterior:
<ol style="margin:8px 0 0 18px;line-height:1.7">
<li><strong>Chip</strong> — cadastre o chip SIM em <a href="#chips" style="color:inherit">Chips</a> e deixe-o ativo e livre (sem câmera).</li>
<li><strong>Câmera</strong> — cadastre o equipamento em <a href="#equipamentos" style="color:inherit">Equipamentos</a>, escolhendo esse chip no campo <em>Chip (SIM)</em>.</li>
<li><strong>Veículo</strong> — cadastre o veículo aqui e, na <em>Visão Geral</em> dele, instale a câmera.</li>
</ol>
<br>
O veículo pode ser cadastrado a qualquer momento. A ordem pesa na instalação: só entra num veículo uma câmera <strong>ativa, livre e com chip já vinculado</strong>.
</div>

<div class="mockup">
<div class="mockup-header">Ativos — Lista de Veículos</div>
<div class="mockup-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div class="input-mock" style="width:260px;font-size:13px">Pesquisar placa ou IMEI da câmera...</div>
        <span class="btn-mock">+ Novo Veículo</span>
    </div>
    <table class="tbl-mock">
    <tr><th>Placa</th><th>Tipo</th><th>Câmera atual</th><th>Chip</th><th>Modelo</th><th>Status</th><th>Última Com.</th><th></th></tr>
    <tr>
        <td>Frota 07</td><td>Caminhão</td><td class="mono">860112070347838</td><td class="mono">5511999990001</td><td>JC400AD</td><td><span class="pill-mock green">Online</span></td><td>18/07/2026 14:35:12</td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Abrir</span> <span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span></td>
    </tr>
    <tr>
        <td>ABC1D23</td><td>Carro</td><td>Sem câmera</td><td>—</td><td>—</td><td><span class="pill-mock gray">Sem câmera</span></td><td>-</td>
        <td><span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Abrir</span> <span class="btn-mock ghost" style="font-size:12px;padding:2px 8px">Editar</span> <span class="btn-mock danger" style="font-size:12px;padding:2px 8px">Remover</span></td>
    </tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>+ Novo Veículo</td><td>Abre o formulário com <strong>Placa</strong> (obrigatória), <strong>Cliente</strong> (só administrador e revendedor escolhem; os demais cadastram no próprio cliente) e <strong>Tipo de Veículo</strong> (opcional — só desenha o ícone do veículo no mapa do Rastreamento). Ao salvar, aparece "Veículo cadastrado com sucesso" com o atalho <em>Instalar uma câmera nele agora</em></td></tr>
<tr><td>Pesquisar</td><td>Filtra por placa ou pelo IMEI da câmera instalada</td></tr>
<tr><td>Abrir</td><td>Abre a ficha do veículo, com as abas descritas mais abaixo</td></tr>
<tr><td>Editar</td><td>Placa e Tipo ficam editáveis na própria linha; <em>Salvar</em> grava ("Veículo atualizado") e <em>Cancelar</em> desfaz. Placa em branco é recusada</td></tr>
<tr><td>Remover</td><td>Só aparece em veículo <strong>sem câmera instalada</strong>. Pede confirmação e desativa o veículo: ele não é apagado, continua na lista esmaecido e marcado como <em>Inativo</em>. Para remover um veículo que tem câmera, desinstale a câmera antes</td></tr>
<tr><td>Exportar Excel / PDF</td><td>Baixa a lista (placa, IMEI da câmera, modelo, última comunicação e status), respeitando a pesquisa</td></tr>
</table>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Como ler a coluna Status</h4>
<table class="tbl-mock">
<tr><th>Status</th><th>Significa</th></tr>
<tr><td><span class="pill-mock gray">Sem câmera</span></td><td>O veículo ainda não tem câmera instalada</td></tr>
<tr><td><span class="pill-mock green">Online</span> / Offline</td><td>Online: a câmera se comunicou nos últimos 10 minutos. Offline: passou disso</td></tr>
<tr><td><span class="pill-mock yellow">Ligado</span></td><td>Aparece junto do status quando a ignição estava ligada na última posição recebida</td></tr>
<tr><td><span class="pill-mock red">Inativo</span></td><td>Veículo removido</td></tr>
</table>

<div class="callout tip">
<strong>Placa é texto livre.</strong> <code>ABC1D23</code>, <code>Frota 07</code> e <code>Câmera Veículo 01</code> são todos válidos — a tela não confere formato. Pode ser placa, número de frota ou apelido; é por esse texto que o veículo aparece em todas as telas do <strong>bycamera</strong>.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Instalar e desinstalar a câmera</h4>
<p>É na aba <strong>Visão Geral</strong> do veículo, no cartão <em>Câmera instalada</em>:</p>
<table class="tbl-mock">
<tr><th>Situação do veículo</th><th>O que aparece e o que fazer</th></tr>
<tr><td>Sem câmera</td><td>Campo <strong>Câmera</strong> com a lista das câmeras livres e o botão <strong>Instalar</strong>. Só entram na lista câmeras do mesmo cliente, ativas, sem instalação em outro veículo e com chip vinculado. Confirma com "Câmera instalada"</td></tr>
<tr><td>Nenhuma câmera na lista</td><td>Aparece o aviso "Nenhuma câmera livre com chip vinculado". Cadastre a câmera com um chip em <a href="#equipamentos" style="color:inherit">Equipamentos</a>; se ela estiver em outro veículo, abra a ficha desse veículo e use <strong>Desinstalar</strong></td></tr>
<tr><td>Com câmera</td><td>Mostra o IMEI e "Instalada desde" a data e hora. O botão <strong>Desinstalar</strong> pede confirmação e confirma com "Câmera desinstalada — livre para outro veículo"</td></tr>
</table>
<p>Cada veículo tem <strong>no máximo uma câmera</strong>, e cada câmera está em <strong>no máximo um veículo</strong> por vez. Para trocar a câmera de um veículo, desinstale a atual e depois instale a nova.</p>

<div class="callout info">
<strong>Ao instalar, a câmera passa a ser do cliente do veículo.</strong> Enquanto ela está instalada, o cliente dela é sempre o do veículo — a tela de Equipamentos mostra o campo <em>Cliente</em> só para leitura. Para mudar o cliente de uma câmera, desinstale-a primeiro: livre, ela pode ter o cliente editado em Equipamentos.
</div>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">Trocar a câmera não apaga o histórico</h4>
<p>A ficha do veículo guarda o <strong>Histórico de instalações</strong>: cada câmera que já esteve nele, com a data em que foi instalada e a data em que saiu (a câmera atual aparece com o selo <em>Atual</em>). O cartão aparece quando o veículo já teve mais de uma instalação, ou quando teve alguma e está sem câmera agora.</p>

<div class="mockup">
<div class="mockup-header">Ficha do veículo — Histórico de instalações</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Câmera</th><th>Instalada em</th><th>Desinstalada em</th></tr>
    <tr><td class="mono">860112070347838</td><td>02/09/2026 09:10:00</td><td><span class="pill-mock green">Atual</span></td></tr>
    <tr><td class="mono">860112070999114</td><td>11/03/2026 15:22:41</td><td>02/09/2026 09:05:12</td></tr>
    </table>
</div>
</div>

<p>Os registros também ficam com o veículo: Trajetos, Alertas, Log e Vídeo mostram o que foi registrado <em>naquele veículo</em>, mesmo que a câmera tenha sido trocada. E uma câmera que passa a ser usada em outro veículo não leva o passado dela junto — cada veículo guarda só o que aconteceu com ele.</p>

<h4 style="font-size:14px;font-weight:600;margin:24px 0 8px">As abas da ficha do veículo</h4>
<table class="tbl-mock">
<tr><th>Aba</th><th>O que mostra</th></tr>
<tr><td>Visão Geral</td><td>Câmera instalada (instalar e desinstalar), histórico de instalações, status, velocidade, distância total, odômetro, alarmes das últimas 24 horas, dados do equipamento e a última posição</td></tr>
<tr><td>Ao Vivo</td><td>Mapa com a última posição recebida. <strong>Exige câmera instalada</strong>: sem ela a aba mostra "Nenhuma câmera instalada", com o atalho para a Visão Geral</td></tr>
<tr><td>Trajetos</td><td>As 100 posições mais recentes registradas para o veículo, com endereço, velocidade e ignição</td></tr>
<tr><td>Alertas</td><td>Os 100 alarmes mais recentes do veículo, sem os registros técnicos de diagnóstico do equipamento</td></tr>
<tr><td>Log</td><td>Mistura das últimas posições, sinais de comunicação do equipamento (bateria, sinal) e eventos — 50 linhas</td></tr>
<tr><td>Relatórios</td><td>Contadores resumidos: posições, distância, alarmes e eventos. Os relatórios completos estão no menu <strong>Relatórios</strong></td></tr>
<tr><td>Vídeo</td><td>Arquivos de mídia do veículo (os 50 mais recentes) e os botões <em>Transmissão ao Vivo</em> e <em>Playback Histórico</em>, que ficam desabilitados enquanto não há câmera instalada</td></tr>
<tr><td>Comandos e Configurações</td><td>Atalhos para as telas de <a href="#comandos" style="color:inherit">Comandos</a> e de configuração remota do equipamento. A tela de configuração remota só abre para administradores</td></tr>
</table>
<p>Ou seja: <strong>Trajetos, Alertas, Log e Vídeo</strong> continuam mostrando o histórico do veículo mesmo sem câmera instalada agora. As abas que falam com o equipamento neste momento, como <strong>Ao Vivo</strong>, dependem de câmera instalada.</p>

<div class="callout warn">
<strong>"Cadastrei o equipamento e o relatório está vazio" — isso não é defeito.</strong>
Um equipamento só passa a aparecer nos relatórios e nas telas do cliente depois que o cadastro está completo: <strong>chip → equipamento → veículo</strong>, com a câmera <em>instalada</em>. Enquanto ela não está instalada num veículo, mesmo transmitindo e aparecendo como <em>Online</em> em Equipamentos, o que ela envia fica sem dono — é de propósito, para que posição de bancada, teste ou estoque não entre na operação de um cliente.
<br><br>
A solução é terminar o cadastro: instale a câmera no veículo. Dali em diante, o que ela enviar aparece nos relatórios do cliente. O que ela enviou <em>antes</em> da instalação continua sem dono.
</div>
