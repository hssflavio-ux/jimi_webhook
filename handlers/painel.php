<?php
/**
 * JIMI Webhook System — Painel (dashboard widgetizado) v4.10.3
 * Rota: /painel
 *
 * Item 7 do docs/PLANO_IMPLEMENTACAO_v4.10.md: segunda tela de dashboard,
 * EM PARALELO a `/` (handlers/resumo.php — intocado). Layout por usuário,
 * editável (mostrar/ocultar + reordenar), com fallback padrão global →
 * catálogo hardcoded. Ver includes/dashboard_widgets.php para os widgets.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard_widgets.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = (int)get_customer_id();
$user = get_jimi_user();
$userId = (int)($user['id'] ?? 0);
$isReseller = ($user['user_type'] ?? '') === 'revendedor';

$periodo = $_GET['periodo'] ?? 'hoje';
if (!in_array($periodo, ['hoje', '7d', 'mes'], true)) $periodo = 'hoje';
$editMode = !empty($_GET['edit']);

$layout = dashboard_resolve_layout($db, $userId);
// Widgets do catálogo ainda fora do layout do usuário — só para o picker.
$hidden = array_values(array_diff(array_keys(DASHBOARD_WIDGETS), $layout));

$page_title = 'Painel';
$current_route = 'painel';
$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.velocity-bar{display:flex;height:24px;border-radius:12px;overflow:hidden;margin:8px 0;}
.velocity-bar div{display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:#fff;}
.widget-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px;}
.widget-card{grid-column:span 12;padding:16px;position:relative;}
.widget-card.size-sm{grid-column:span 3;}
.widget-card.size-md{grid-column:span 6;}
.widget-card.size-lg{grid-column:span 12;}
@media (max-width: 900px){.widget-card.size-sm,.widget-card.size-md{grid-column:span 12;}}
.widget-title{font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);margin-bottom:8px;letter-spacing:.03em;}
#wp-picker{padding:16px;margin-bottom:20px;}
#wp-picker ul{list-style:none;margin:0;padding:0;}
#wp-picker li{display:flex;align-items:center;gap:10px;padding:6px 4px;border-bottom:1px solid var(--hairline-soft);}
#wp-picker li label{flex:1;font-size:13px;cursor:pointer;}
#wp-picker .wp-arrows{display:flex;gap:2px;}
#wp-picker .wp-arrows button{width:22px;height:22px;padding:0;font-size:11px;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Painel</h2>
    <div style="display:flex;align-items:center;gap:8px;">
        <?php if (!$editMode): ?>
        <div class="flex" style="gap:0;">
            <a href="?periodo=hoje<?= $editMode ? '&edit=1' : '' ?>" class="btn btn-sm <?= $periodo==='hoje'?'btn-primary':'btn-outline' ?>" style="border-radius:var(--radius-pill) 0 0 var(--radius-pill);">Hoje</a>
            <a href="?periodo=7d<?= $editMode ? '&edit=1' : '' ?>" class="btn btn-sm <?= $periodo==='7d'?'btn-primary':'btn-outline' ?>" style="border-radius:0;">7 dias</a>
            <a href="?periodo=mes<?= $editMode ? '&edit=1' : '' ?>" class="btn btn-sm <?= $periodo==='mes'?'btn-primary':'btn-outline' ?>" style="border-radius:0 var(--radius-pill) var(--radius-pill) 0;">Mês</a>
        </div>
        <a href="?edit=1&periodo=<?= $periodo ?>" class="btn btn-outline btn-sm">Editar painel</a>
        <?php else: ?>
        <a href="?periodo=<?= $periodo ?>" class="btn btn-outline btn-sm">Concluir edição</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($_GET['saved'])): ?>
<div class="card mb-16" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px">Layout salvo.</div>
<?php endif; ?>

<?php if ($editMode): ?>
<div class="card" id="wp-picker">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;">Widgets do painel</h4>
    <p style="font-size:12px;color:var(--muted);margin-bottom:12px;">Marque para exibir; use as setas para reordenar. Salvar aplica para o seu usuário.</p>
    <ul id="wp-list">
        <?php foreach ($layout as $key):
            if (!empty(DASHBOARD_WIDGETS[$key]['reseller_only']) && !$isReseller) continue;
        ?>
        <li data-key="<?= htmlspecialchars($key) ?>">
            <input type="checkbox" checked style="width:auto;">
            <label><?= htmlspecialchars(dashboard_widget_label($key)) ?></label>
            <div class="wp-arrows">
                <button type="button" class="btn btn-outline btn-sm" onclick="wpMove(this,-1)">↑</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="wpMove(this,1)">↓</button>
            </div>
        </li>
        <?php endforeach; ?>
        <?php foreach ($hidden as $key):
            if (!empty(DASHBOARD_WIDGETS[$key]['reseller_only']) && !$isReseller) continue;
        ?>
        <li data-key="<?= htmlspecialchars($key) ?>">
            <input type="checkbox" style="width:auto;">
            <label><?= htmlspecialchars(dashboard_widget_label($key)) ?></label>
            <div class="wp-arrows">
                <button type="button" class="btn btn-outline btn-sm" onclick="wpMove(this,-1)">↑</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="wpMove(this,1)">↓</button>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="mt-16" style="text-align:right;">
        <button type="button" class="btn btn-primary btn-sm" id="wp-save">Salvar layout</button>
    </div>
</div>
<?php endif; ?>

<div class="widget-grid">
    <?php foreach ($layout as $key):
        if (!empty(DASHBOARD_WIDGETS[$key]['reseller_only']) && !$isReseller) continue;
        $size = DASHBOARD_WIDGETS[$key]['size'] ?? 'md';
    ?>
    <div class="card widget-card size-<?= $size ?>">
        <div class="widget-title"><?= htmlspecialchars(dashboard_widget_label($key)) ?></div>
        <?= render_widget($key, $db, $customerId, $isReseller, $periodo) ?>
    </div>
    <?php endforeach; ?>
    <?php if (empty($layout)): ?>
    <div class="card widget-card size-lg">
        <div class="empty-state"><h3>Nenhum widget selecionado</h3><p>Use "Editar painel" para escolher o que exibir aqui.</p></div>
    </div>
    <?php endif; ?>
</div>

<?php if ($editMode): ?>
<script>
function wpMove(btn, dir) {
    var li = btn.closest('li');
    var sib = dir < 0 ? li.previousElementSibling : li.nextElementSibling;
    if (!sib) return;
    if (dir < 0) li.parentNode.insertBefore(li, sib);
    else li.parentNode.insertBefore(sib, li);
}
document.getElementById('wp-save').addEventListener('click', function () {
    var keys = [];
    document.querySelectorAll('#wp-list li').forEach(function (li) {
        var cb = li.querySelector('input[type=checkbox]');
        if (cb && cb.checked) keys.push(li.dataset.key);
    });
    fetch('/dashboarddata', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
        body: JSON.stringify({ layout: keys })
    }).then(function (r) { return r.json(); }).then(function (resp) {
        if (resp && resp.code === 0) {
            location.href = '/painel?edit=1&periodo=<?= $periodo ?>&saved=1';
        } else {
            alert('Não foi possível salvar: ' + (resp && resp.msg ? resp.msg : 'erro desconhecido'));
        }
    }).catch(function () { alert('Não foi possível salvar (falha de rede).'); });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
