<?php defined('WIKI_SECTION') || exit; ?>
<p><strong>Objetivo:</strong> Gerador de análises sob demanda. Selecione filtros (cliente, ativos, motoristas, tipos de alarme, período) e clique em <strong>Gerar</strong> para visualizar gráficos de barras, pizza e linha.</p>

<div class="mockup">
<div class="mockup-header">BI — Análises sob Demanda</div>
<div class="mockup-body">
    <div class="filter-bar-mock">
        <div class="filter-mock">Cliente: Todos</div>
        <div class="filter-mock">Ativo: Qualquer</div>
        <div class="filter-mock">Motorista: Todos</div>
        <div class="filter-mock dim">dd/mm/aaaa - dd/mm/aaaa</div>
        <span class="btn-mock">Gerar</span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
        <div style="background:#fafbfc;border:1px solid var(--hairline-soft);border-radius:var(--radius-lg);padding:16px;text-align:center">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Alarmes por Tipo (Barras)</div>
            <div class="chart-mock" style="height:140px">
                <div class="chart-bar blue" style="height:30%"></div><div class="chart-bar blue" style="height:55%"></div>
                <div class="chart-bar blue" style="height:80%"></div><div class="chart-bar blue" style="height:45%"></div>
                <div class="chart-bar blue" style="height:60%"></div>
            </div>
        </div>
        <div style="background:#fafbfc;border:1px solid var(--hairline-soft);border-radius:var(--radius-lg);padding:16px;text-align:center">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Distribuição (Pizza)</div>
            <div style="width:100px;height:100px;border-radius:50%;background:conic-gradient(var(--primary) 0% 45%, #098551 45% 70%, #c83532 70% 100%);margin:10px auto"></div>
        </div>
        <div style="background:#fafbfc;border:1px solid var(--hairline-soft);border-radius:var(--radius-lg);padding:16px;text-align:center">
            <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px">Tendência (Linha)</div>
            <svg width="160" height="100" viewBox="0 0 160 100" style="margin-top:8px">
                <polyline fill="none" stroke="var(--primary)" stroke-width="2" points="5,80 30,60 55,70 80,30 105,40 130,20 155,35"/>
                <circle cx="80" cy="30" r="3" fill="var(--primary)"/>
            </svg>
        </div>
    </div>
</div>
</div>

<table class="tbl-mock">
<tr><th>Ação</th><th>Resultado</th></tr>
<tr><td>Preencher filtros + Gerar</td><td>Gráficos são carregados com dados do período/filtros selecionados</td></tr>
<tr><td>Filtro Motoristas</td><td>Filtra dados de ocorrências por motorista específico</td></tr>
<tr><td>Filtro Alarmes</td><td>Seleciona um ou mais tipos de alarme para análise</td></tr>
<tr><td>Sem filtros preenchidos</td><td>Usa padrão: últimos 30 dias, todos os alarmes</td></tr>
</table>
