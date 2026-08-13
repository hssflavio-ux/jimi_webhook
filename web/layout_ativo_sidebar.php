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

// Parâmetros (v4.9.12) — SÓ para equipamento JT/T. Os comandos 33027/33028/
// 33030 são da seção 2 da doc (msgClass=1) e câmera JIMI não os entende
// (ADR-001). A aba não aparece vazia para JIMI: ela não aparece, porque tela
// vazia o usuário lê como defeito do sistema, não como "não se aplica".
if (($asset['protocol'] ?? '') === 'JTT') {
    $tabs[] = ['id' => 'parametros', 'label' => 'Parâmetros'];
}

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
        <?php
        // 🔴 O nome da variável do laço NÃO é indiferente (v4.9.8).
        //
        // Enquanto era `$tab`, este `foreach` sobrescrevia a variável do
        // CHAMADOR: `ativo_detalhe.php` guarda a aba pedida em `$tab`, inclui
        // este arquivo e só então roda `switch ($tab)`. Depois do laço, `$tab`
        // valia o ÚLTIMO item do array — `['id'=>'configuracoes',…]` —, e em
        // PHP 8 array comparado com string é sempre falso: nenhum `case`
        // casava e **as 9 abas do ativo apareciam em branco**, com a sidebar
        // funcionando e destacando a aba certa (ela usa `$current_tab`).
        // Um include parcial não pode escrever em nome genérico do chamador.
        ?>
        <?php foreach ($tabs as $abaItem): ?>
        <a href="<?= $asset_base_url ?>?tab=<?= $abaItem['id'] ?>" class="<?= $current_tab === $abaItem['id'] ? 'active' : '' ?>">
            <?= $abaItem['label'] ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
<div class="asset-content">
