<?php
/**
 * Componente: Cartão KPI Colorido (gradiente)
 * Uso: Resumo, Dashboard de Ocorrências, BI
 *
 * Parâmetros:
 *   $label        — Rótulo (ex: "Equipamentos Ativos")
 *   $value        — Valor principal (ex: "142/200")
 *   $variant      — Cor: 'blue', 'green', 'yellow', 'red' (default: 'blue')
 *   $icon         — Nome do ícone (opcional, SVG inline)
 *   $sub_value    — Linha extra abaixo do valor (ex: "+12%", opcional)
 *   $card_class   — Classe CSS extra (opcional)
 */
$variant = $variant ?? 'blue';
$gradients = [
    'blue'   => 'linear-gradient(135deg, #eaf0ff 0%, #d0e0ff 100%)',
    'green'  => 'linear-gradient(135deg, #e4f7ee 0%, #c8f0dd 100%)',
    'yellow' => 'linear-gradient(135deg, #fff8e6 0%, #fef0cc 100%)',
    'red'    => 'linear-gradient(135deg, #fdeaec 0%, #fbd5d9 100%)',
];
$colors = [
    'blue'   => 'var(--primary)',
    'green'  => 'var(--success)',
    'yellow' => 'var(--warning)',
    'red'    => 'var(--error)',
];
$bg = $gradients[$variant] ?? $gradients['blue'];
$fg = $colors[$variant] ?? $colors['blue'];
?>
<div class="kpi-card-gradient <?= $card_class ?? '' ?>" style="background:<?= $bg ?>;border-radius:var(--radius-lg);padding:20px;border:1px solid var(--hairline-soft);">
    <div class="kpi-card-label" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);margin-bottom:4px;">
        <?= htmlspecialchars($label ?? '') ?>
    </div>
    <div class="kpi-card-value" style="font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:500;color:<?= $fg ?>;line-height:1.1;letter-spacing:-0.5px;">
        <?= htmlspecialchars((string)($value ?? '--')) ?>
    </div>
    <?php if (!empty($sub_value)): ?>
    <div class="kpi-card-sub" style="font-size:12px;color:var(--muted);margin-top:4px;">
        <?= htmlspecialchars($sub_value) ?>
    </div>
    <?php endif; ?>
</div>
