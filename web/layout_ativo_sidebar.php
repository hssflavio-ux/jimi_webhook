<?php
/**
 * JIMI Webhook System — Asset Sidebar Component v3.1.0
 *
 * Sidebar secundária para navegação no detalhe do ativo.
 * Incluir dentro da main-content de layout_base.php.
 *
 * Variáveis esperadas:
 *   $asset           — Dados do dispositivo (imei, device_name, device_model)
 *   $current_tab     — Aba ativa (visao-geral, ao-vivo, trajetos, etc.)
 *   $asset_base_url  — URL base do ativo (ex: /ativos/868120246598152)
 */

if (!isset($asset))        $asset        = [];
if (!isset($current_tab))  $current_tab  = 'visao-geral';
if (!isset($asset_base_url)) $asset_base_url = '/ativos/' . ($asset['imei'] ?? '');

$tabs = [
    ['id' => 'visao-geral',    'label' => 'Visão Geral'],
    ['id' => 'ao-vivo',        'label' => 'Ao Vivo'],
    ['id' => 'trajetos',       'label' => 'Trajetos'],
    ['id' => 'alertas',        'label' => 'Alertas'],
    ['id' => 'log',            'label' => 'Log'],
    ['id' => 'relatorios',     'label' => 'Relatórios'],
    ['id' => 'video',          'label' => 'Vídeo'],
    ['id' => 'comandos',       'label' => 'Comandos'],
    ['id' => 'configuracoes',  'label' => 'Configurações'],
];

?>
<div class="asset-sidebar">
    <div class="asset-sidebar-header">
        <div class="asset-sidebar-name"><?= htmlspecialchars($asset['device_name'] ?? $asset['imei'] ?? 'Dispositivo') ?></div>
        <div class="asset-sidebar-imei"><?= htmlspecialchars($asset['imei'] ?? '-') ?></div>
        <?php if (!empty($asset['device_model'])): ?>
        <div class="asset-sidebar-imei" style="margin-top:2px;"><?= htmlspecialchars($asset['device_model']) ?></div>
        <?php endif; ?>
    </div>
    <nav class="asset-sidebar-nav">
        <?php foreach ($tabs as $tab): ?>
        <a href="<?= $asset_base_url ?>?tab=<?= $tab['id'] ?>" class="<?= $current_tab === $tab['id'] ? 'active' : '' ?>">
            <?= $tab['label'] ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
<div class="asset-content">
