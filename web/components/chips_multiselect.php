<?php
/**
 * Componente: Multiselect com chips e overflow "+N" (padrão YUV §9.2)
 *
 * Extraído do bi.php (v4.2.0 — Fase B4 do PLANO_ADERENCIA_YUV) para reuso nos
 * relatórios. Serializa a seleção num hidden input (valores separados por vírgula).
 *
 * Variáveis esperadas antes do include:
 *   $chips_id       string  Prefixo único no DOM (ex.: 'alarmtypes')
 *   $chips_label    string  Rótulo do campo
 *   $chips_param    string  Nome do parâmetro GET (ex.: 'alarm_types')
 *   $chips_options  array   Lista de opções (strings)
 *   $chips_selected array   Opções pré-selecionadas
 *   $chips_visible  int     Quantos chips mostrar antes do "+N" (default 15)
 */
$chips_visible = $chips_visible ?? 15;
$_shown = array_slice($chips_options, 0, $chips_visible);
$_hiddenCount = max(0, count($chips_options) - $chips_visible);
?>
<?php if (empty($GLOBALS['_chips_css_emitted'])): $GLOBALS['_chips_css_emitted'] = true; ?>
<style>
.yuv-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:12px;font-size:11px;cursor:pointer;border:1px solid var(--hairline);background:#fff;color:var(--body);transition:all .15s;}
.yuv-chip.selected{background:var(--primary);color:#fff;border-color:var(--primary);}
.yuv-chips-wrapper{display:flex;flex-wrap:wrap;gap:4px;max-height:80px;overflow:hidden;}
.yuv-chips-wrapper.expanded{max-height:none;}
.yuv-chips-overflow{display:inline-flex;align-items:center;padding:3px 8px;border-radius:12px;font-size:11px;cursor:pointer;background:var(--surface-strong);color:var(--muted);border:none;}
.yuv-chip-count{display:none;font-size:11px;color:var(--muted);margin-top:4px;}
</style>
<script>
function yuvChipToggle(el, id) {
    el.classList.toggle('selected');
    var sel = [];
    document.querySelectorAll('#chips-' + id + ' .yuv-chip.selected').forEach(function (c) { sel.push(c.dataset.value); });
    document.getElementById('chips-hidden-' + id).value = sel.join(',');
    var cnt = document.getElementById('chips-count-' + id);
    cnt.style.display = sel.length ? 'block' : 'none';
    cnt.textContent = sel.length + ' selecionado(s)';
}
function yuvChipsExpand(id) {
    document.getElementById('chips-' + id).classList.add('expanded');
    document.getElementById('chips-expand-' + id).style.display = 'none';
    document.querySelectorAll('#chips-' + id + ' .yuv-chip').forEach(function (c) { c.style.display = 'inline-flex'; });
}
</script>
<?php endif; ?>
<div>
    <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;margin-bottom:6px;"><?= htmlspecialchars($chips_label) ?></label>
    <div class="yuv-chips-wrapper" id="chips-<?= $chips_id ?>">
        <?php foreach ($chips_options as $_opt):
            $_sel = in_array($_opt, $chips_selected, true);
            $_hidden = !in_array($_opt, $_shown, true);
        ?>
        <span class="yuv-chip<?= $_sel ? ' selected' : '' ?>" data-value="<?= htmlspecialchars($_opt) ?>"
              onclick="yuvChipToggle(this, '<?= $chips_id ?>')" style="<?= $_hidden ? 'display:none;' : '' ?>">
            <?= htmlspecialchars($_opt) ?>
        </span>
        <?php endforeach; ?>
        <?php if ($_hiddenCount > 0): ?>
        <button type="button" class="yuv-chips-overflow" id="chips-expand-<?= $chips_id ?>" onclick="yuvChipsExpand('<?= $chips_id ?>')">+<?= $_hiddenCount ?></button>
        <?php endif; ?>
    </div>
    <input type="hidden" name="<?= htmlspecialchars($chips_param) ?>" id="chips-hidden-<?= $chips_id ?>" value="<?= htmlspecialchars(implode(',', $chips_selected)) ?>">
    <div class="yuv-chip-count" id="chips-count-<?= $chips_id ?>" style="<?= $chips_selected ? 'display:block;' : '' ?>"><?= count($chips_selected) ?> selecionado(s)</div>
</div>
