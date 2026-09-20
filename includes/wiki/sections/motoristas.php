<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Cadastro de motoristas com dados de compliance: CNH (número, categoria, vencimento) e exame toxicológico (vencimento). Alertas visuais para documentos vencidos ou próximos do vencimento.</p>

<div class="mockup">
<div class="mockup-header">Motoristas — Cadastro com Compliance</div>
<div class="mockup-body">
    <table class="tbl-mock">
    <tr><th>Nome</th><th>CNH</th><th>Categoria</th><th>Validade CNH</th><th>Toxicológico</th><th>Status</th></tr>
    <tr>
        <td>João Silva</td><td class="mono">12345678900</td><td>D</td><td>15/03/2027</td><td>10/01/2027</td><td><span class="pill-mock green">Ativo</span></td>
    </tr>
    <tr>
        <td>Maria Santos</td><td class="mono">98765432100</td><td>C</td><td style="color:#c83532;font-weight:600">20/06/2026</td><td>05/12/2026</td><td><span class="pill-mock green">Ativo</span></td>
    </tr>
    </table>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar/Editar motorista</td><td>Nome, nascimento, CNH (número, categoria, vencimento), toxicológico (vencimento), identificador FaceID, status ativo/inativo</td></tr>
<tr><td>Remover</td><td>Exclui o registro definitivamente</td></tr>
<tr><td>Alertas de vencimento</td><td>CNH ou toxicológico vencidos aparecem em vermelho na tabela</td></tr>
</table>

<div class="callout info">
<strong>Como o motorista aparece nas outras telas.</strong> O <strong>identificador</strong> cadastrado aqui é o que a câmera envia quando reconhece um rosto: quando ele bate com o de um motorista <em>ativo</em>, aquele motorista passa a ser o <strong>motorista corrente do veículo</strong>. Ele continua sendo o corrente até a câmera reconhecer outro motorista ou a ignição desligar, e alarmes e posições recebidos nesse período ficam associados a ele. É o nome que você vê em <a href="#rastreamento" style="color:inherit">Rastreamento</a> (lista e balão do mapa), na coluna <strong>Motorista</strong> dos relatórios de <a href="#rel-posicoes" style="color:inherit">Posições</a>, <a href="#rel-deslocamento" style="color:inherit">Deslocamento</a> e <a href="#rel-alarmes" style="color:inherit">Alertas Videomonitoramento</a>, e nas ocorrências. Sem o identificador cadastrado, o motorista não é reconhecido nessas telas.
</div>
