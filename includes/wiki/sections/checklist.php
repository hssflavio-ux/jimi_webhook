<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Criar checklists de inspeção veicular (ex: checklist diário de pneus, freios, iluminação) e preenchê-los para veículos específicos. Cada checklist tem itens configuráveis: OK/Não OK, texto, número e foto.</p>

<div class="mockup">
<div class="mockup-header">Checklist — Preenchimento de Inspeção</div>
<div class="mockup-body">
    <div style="display:flex;gap:20px">
        <div style="flex:1">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Inspeção Diária — CAM-001</div>
            <div style="display:flex;flex-direction:column;gap:10px">
                <div style="padding:10px;background:#fafbfc;border-radius:var(--radius-sm)">
                    <div style="font-size:13px;font-weight:500">Pneus em bom estado?</div>
                    <div style="display:flex;gap:8px;margin-top:6px">
                        <span class="pill-mock green">OK</span>
                        <span class="pill-mock gray" style="opacity:.5">Não OK</span>
                    </div>
                </div>
                <div style="padding:10px;background:#fafbfc;border-radius:var(--radius-sm)">
                    <div style="font-size:13px;font-weight:500">Faróis funcionando?</div>
                    <div style="display:flex;gap:8px;margin-top:6px">
                        <span class="pill-mock green">OK</span>
                        <span class="pill-mock gray" style="opacity:.5">Não OK</span>
                    </div>
                </div>
                <div style="padding:10px;background:#fafbfc;border-radius:var(--radius-sm)">
                    <div style="font-size:13px;font-weight:500">Observações</div>
                    <div class="input-mock" style="margin-top:4px;font-size:12px;width:100%">Nenhuma observação</div>
                </div>
                <span class="btn-mock">Registrar Inspeção</span>
            </div>
        </div>
        <div style="width:220px;flex-shrink:0">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Histórico</div>
            <div style="font-size:12px;color:var(--body);padding:6px;border-bottom:1px solid var(--hairline-soft)">18/07 08:15 — Aprovado</div>
            <div style="font-size:12px;color:var(--body);padding:6px;border-bottom:1px solid var(--hairline-soft)">17/07 08:00 — Aprovado</div>
            <div style="font-size:12px;color:var(--body);padding:6px">16/07 07:50 — Aprovado</div>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Criar checklist</td><td>Nome + vinculação ao cliente. Adicionar itens: pergunta, tipo de resposta (OK-Não OK/texto/número/foto) e se é obrigatório</td></tr>
<tr><td>Preencher inspeção</td><td>Selecionar checklist, dispositivo e motorista. Responder cada item. Salvar</td></tr>
<tr><td>Ver histórico</td><td>Lista de inspeções anteriores para o dispositivo</td></tr>
</table>
