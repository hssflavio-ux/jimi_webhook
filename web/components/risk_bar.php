<?php
/**
 * Componente: Barra de Distribuição de Risco (3 faixas %)
 * Uso: Dashboard de Ocorrências, Resumo
 *
 * Parâmetros:
 *   $low_pct     — Percentual baixo (0-100)
 *   $med_pct     — Percentual médio (0-100)
 *   $high_pct    — Percentual alto (0-100)
 *   $low_label   — Rótulo baixo (default: 'Baixo')
 *   $med_label   — Rótulo médio (default: 'Médio')
 *   $high_label  — Rótulo alto (default: 'Alto')
 *   $title       — Título da barra (opcional)
 *
 * As 3 faixas devem somar aproximadamente 100%. Se não somarem 100,
 * o componente normaliza automaticamente.
 */
$low_pct  = $low_pct ?? 0;
$med_pct  = $med_pct ?? 0;
$high_pct = $high_pct ?? 0;
$total = $low_pct + $med_pct + $high_pct;
if ($total > 0 && $total != 100) {
    $low_pct = round($low_pct / $total * 100);
    $med_pct = round($med_pct / $total * 100);
    $high_pct = 100 - $low_pct - $med_pct;
}

$low_label  = $low_label ?? 'Baixo';
$med_label  = $med_label ?? 'Médio';
$high_label = $high_label ?? 'Alto';
?>
<div class="risk-bar-wrap" style="margin:8px 0;">
    <?php if (!empty($title)): ?>
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);margin-bottom:6px;">
        <?= htmlspecialchars($title) ?>
    </div>
    <?php endif; ?>
    <div class="risk-bar" style="display:flex;height:8px;border-radius:4px;overflow:hidden;margin-bottom:8px;">
        <div style="width:<?= $low_pct ?>%;background:var(--primary);" title="<?= $low_label ?>: <?= $low_pct ?>%"></div>
        <div style="width:<?= $med_pct ?>%;background:var(--warning);" title="<?= $med_label ?>: <?= $med_pct ?>%"></div>
        <div style="width:<?= $high_pct ?>%;background:var(--error);" title="<?= $high_label ?>: <?= $high_pct ?>%"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:500;">
        <span style="color:var(--primary);display:flex;align-items:center;gap:4px;">
            <span style="width:8px;height:8px;border-radius:2px;background:var(--primary);display:inline-block;"></span>
            <?= $low_label ?> <?= $low_pct ?>%
        </span>
        <span style="color:var(--warning);display:flex;align-items:center;gap:4px;">
            <span style="width:8px;height:8px;border-radius:2px;background:var(--warning);display:inline-block;"></span>
            <?= $med_label ?> <?= $med_pct ?>%
        </span>
        <span style="color:var(--error);display:flex;align-items:center;gap:4px;">
            <span style="width:8px;height:8px;border-radius:2px;background:var(--error);display:inline-block;"></span>
            <?= $high_label ?> <?= $high_pct ?>%
        </span>
    </div>
</div>
