<?php
/**
 * Componente: Barra de Filtros "Gerar"
 * Uso: Todos os Relatórios/BI
 *
 * Parâmetros:
 *   $filters       — Array de filtros: [['label'=>'Clientes','name'=>'clientes','options'=>[id=>name],'selected'=>[]], ...]
 *   $show_period   — Mostrar seletor de período? (default: true)
 *   $show_export   — Mostrar botões exportar? (default: true)
 *   $generate_url  — URL para onde o Gerar submete (default: '' — usa página atual)
 *   $extra_fields  — HTML extra antes do botão Gerar (opcional)
 *
 * O componente renderiza multiselects com chips (+N) + date range + botão [Gerar]
 * A funcionalidade dos multiselects é feita via JS inline; cada um vira um <select multiple>
 * com chips visíveis mostrando selecionados + "+N" para collapse.
 */
$filters = $filters ?? [];
$show_period = $show_period ?? true;
$show_export = $show_export ?? true;
$generate_url = $generate_url ?? '';
$filter_id = 'filter-bar-' . uniqid();
?>
<div class="filter-bar" id="<?= $filter_id ?>"
     style="background:var(--surface);border:1px solid var(--hairline);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:20px;">
    <form method="GET" action="<?= htmlspecialchars($generate_url ?: '') ?>" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;">
        <?php foreach ($filters as $f): ?>
        <div class="filter-group" style="flex:0 1 180px;min-width:140px;">
            <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);margin-bottom:4px;">
                <?= htmlspecialchars($f['label'] ?? '') ?>
            </label>
            <select name="<?= htmlspecialchars($f['name'] ?? '') ?>[]" multiple
                    class="multi-select"
                    data-label="<?= htmlspecialchars($f['label'] ?? '') ?>"
                    style="width:100%;padding:8px 10px;font-size:13px;font-family:'Inter',sans-serif;border:1px solid var(--hairline);border-radius:var(--radius-sm);color:var(--ink);background:var(--canvas);min-height:38px;"
                    onchange="updateMultiSelect(this)">
                <?php foreach ($f['options'] ?? [] as $value => $label_text): ?>
                <option value="<?= htmlspecialchars((string)$value) ?>"
                    <?= in_array((string)$value, array_map('strval', $f['selected'] ?? [])) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label_text) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="multi-select-chips" style="display:none;flex-wrap:wrap;gap:4px;padding:4px 0;cursor:pointer;" onclick="toggleMultiSelect(this)"></div>
        </div>
        <?php endforeach; ?>

        <?php if ($show_period): ?>
        <div class="filter-group" style="flex:0 0 auto;">
            <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);margin-bottom:4px;">Período</label>
            <div style="display:flex;gap:6px;">
                <input type="date" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? date('Y-m-d')) ?>"
                       style="padding:8px 10px;font-size:13px;font-family:'Inter',sans-serif;border:1px solid var(--hairline);border-radius:var(--radius-sm);color:var(--ink);background:var(--canvas);width:130px;">
                <input type="date" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? date('Y-m-d')) ?>"
                       style="padding:8px 10px;font-size:13px;font-family:'Inter',sans-serif;border:1px solid var(--hairline);border-radius:var(--radius-sm);color:var(--ink);background:var(--canvas);width:130px;">
            </div>
        </div>
        <?php endif; ?>

        <?= $extra_fields ?? '' ?>

        <div style="display:flex;gap:8px;align-items:flex-end;">
            <button type="submit" class="btn btn-primary btn-sm" style="padding:10px 24px;">Gerar</button>
            <?php if ($show_export): ?>
            <button type="button" class="btn btn-outline btn-sm" onclick="alert('Export Excel em desenvolvimento')">Excel</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="alert('Export PDF em desenvolvimento')">PDF</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
(function() {
    var bar = document.getElementById('<?= $filter_id ?>');
    if (!bar) return;
    bar.querySelectorAll('select.multi-select').forEach(function(sel) {
        var chipsDiv = sel.nextElementSibling;
        if (!chipsDiv || !chipsDiv.classList.contains('multi-select-chips')) return;
        updateMultiSelectChips(sel, chipsDiv);
    });
})();

function updateMultiSelect(sel) {
    var chipsDiv = sel.nextElementSibling;
    if (!chipsDiv || !chipsDiv.classList.contains('multi-select-chips')) return;
    updateMultiSelectChips(sel, chipsDiv);
}

function toggleMultiSelect(chipsDiv) {
    var sel = chipsDiv.previousElementSibling;
    if (!sel) return;
    sel.focus();
    sel.size = sel.size === 4 ? 1 : 4;
}

function updateMultiSelectChips(sel, chipsDiv) {
    var selected = [];
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].selected) selected.push(sel.options[i].text);
    }
    if (selected.length === 0) {
        chipsDiv.style.display = 'none';
        chipsDiv.innerHTML = '';
        return;
    }
    var maxShow = 2;
    var html = '';
    for (var i = 0; i < Math.min(selected.length, maxShow); i++) {
        html += '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;font-size:11px;background:var(--primary-soft);color:var(--primary);border-radius:9999px;">'
            + selected[i] + ' <span onclick="event.stopPropagation();removeMultiSelectOption(this)" data-idx="' + i + '" style="cursor:pointer;font-weight:700;">&times;</span></span>';
    }
    var remaining = selected.length - maxShow;
    if (remaining > 0) {
        html += '<span style="padding:2px 6px;font-size:11px;color:var(--muted);">+' + remaining + '</span>';
    }
    chipsDiv.innerHTML = html;
    chipsDiv.style.display = 'flex';
}

function removeMultiSelectOption(el) {
    // Simplified; full implementation would deselect the option from the select
}
</script>
