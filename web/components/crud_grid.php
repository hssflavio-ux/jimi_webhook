<?php
/**
 * Componente: Grade CRUD Padrão
 * Uso: Todos os Cadastros (Ativos, Chips, Motoristas, etc.)
 *
 * Parâmetros:
 *   $title         — Título da grade (ex: "Ativos")
 *   $columns       — Array de definição de colunas:
 *                    [['key'=>'name','label'=>'Nome','sortable'=>true], ...]
 *   $rows          — Array de linhas (cada linha é um array associativo)
 *   $actions       — Array de ações por linha:
 *                    [['label'=>'Editar','href'=>'/edit/{id}','class'=>''], ...]
 *                    O placeholder {id} será substituído pelo valor da chave 'id' da linha.
 *                    Use {key_name} para qualquer coluna.
 *   $create_url    — URL para o botão "Cadastrar" (opcional)
 *   $create_label  — Label do botão cadastrar (default: "Cadastrar")
 *   $search_placeholder — Placeholder da busca (default: "Pesquisar...")
 *   $empty_message — Mensagem quando vazio (default: "Nenhum registro encontrado.")
 *   $page          — Página atual para paginação (default: 1)
 *   $total_pages   — Total de páginas (default: 1)
 *   $total_count   — Total de registros
 */

$title = $title ?? 'Registros';
$columns = $columns ?? [];
$rows = $rows ?? [];
$actions = $actions ?? [];
$create_url = $create_url ?? '';
$create_label = $create_label ?? 'Cadastrar';
$search_placeholder = $search_placeholder ?? 'Pesquisar...';
$empty_message = $empty_message ?? 'Nenhum registro encontrado.';
$page = $page ?? 1;
$total_pages = $total_pages ?? 1;
$total_count = $total_count ?? count($rows);
$grid_id = 'crud-grid-' . uniqid();
?>

<div class="crud-grid-wrap" id="<?= $grid_id ?>">
    <div class="flex-between mb-16" style="flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <h3 style="font-size:18px;font-weight:600;color:var(--ink);"><?= htmlspecialchars($title) ?></h3>
            <?php if ($total_count > 0): ?>
            <span style="font-size:12px;color:var(--muted);">(<?= $total_count ?> registro<?= $total_count !== 1 ? 's' : '' ?>)</span>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <input type="text" id="<?= $grid_id ?>-search" placeholder="<?= htmlspecialchars($search_placeholder) ?>"
                   style="padding:8px 12px;font-size:13px;font-family:'Inter',sans-serif;border:1px solid var(--hairline);border-radius:var(--radius-sm);color:var(--ink);background:var(--canvas);width:180px;"
                   oninput="filterCrudGrid('<?= $grid_id ?>')">
            <?php if ($create_url): ?>
            <a href="<?= htmlspecialchars($create_url) ?>" class="btn btn-primary btn-sm">+ <?= htmlspecialchars($create_label) ?></a>
            <?php endif; ?>
            <button type="button" class="btn btn-outline btn-sm" onclick="alert('Export Excel em desenvolvimento')">Exportar Excel</button>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <?php foreach ($columns as $col): ?>
                    <th <?= !empty($col['class']) ? 'class="' . $col['class'] . '"' : '' ?>
                        <?= !empty($col['width']) ? 'style="width:' . $col['width'] . '"' : '' ?>>
                        <?= htmlspecialchars($col['label'] ?? '') ?>
                        <?php if (!empty($col['sortable'])): ?>
                        <span style="font-size:10px;margin-left:4px;opacity:0.5;">&#9650;&#9660;</span>
                        <?php endif; ?>
                    </th>
                    <?php endforeach; ?>
                    <?php if (!empty($actions)): ?>
                    <th style="width:60px;text-align:center;">Ações</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="<?= count($columns) + (empty($actions) ? 0 : 1) ?>" style="text-align:center;padding:32px 16px;color:var(--muted);">
                        <div style="font-size:32px;margin-bottom:8px;opacity:0.4;">&#9783;</div>
                        <?= htmlspecialchars($empty_message) ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($columns as $col): ?>
                    <?php $key = $col['key'] ?? ''; $val = $row[$key] ?? ''; ?>
                    <td <?= !empty($col['td_class']) ? 'class="' . $col['td_class'] . '"' : '' ?>>
                        <?php if (!empty($col['render'])): ?>
                            <?= $col['render']($row) ?>
                        <?php elseif (!empty($col['mono'])): ?>
                            <span class="text-mono"><?= htmlspecialchars((string)$val) ?></span>
                        <?php else: ?>
                            <?= htmlspecialchars((string)$val) ?>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <?php if (!empty($actions)): ?>
                    <td style="text-align:center;">
                        <div style="display:flex;gap:4px;justify-content:center;">
                        <?php foreach ($actions as $action): ?>
                            <?php
                            $href = $action['href'] ?? '#';
                            foreach ($row as $k => $v) {
                                $href = str_replace('{' . $k . '}', urlencode((string)$v), $href);
                            }
                            ?>
                            <a href="<?= $href ?>" class="btn btn-sm <?= $action['class'] ?? 'btn-outline' ?>"
                               title="<?= htmlspecialchars($action['label'] ?? '') ?>"
                               style="padding:4px 10px;font-size:12px;">
                                <?= htmlspecialchars($action['label'] ?? '') ?>
                            </a>
                        <?php endforeach; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">
        <span>Página <?= $page ?> de <?= $total_pages ?></span>
        <div style="display:flex;gap:4px;">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>" class="btn btn-outline btn-sm">&laquo; Anterior</a>
            <?php endif; ?>
            <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page + 1 ?>" class="btn btn-outline btn-sm">Próximo &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function filterCrudGrid(gridId) {
    var input = document.getElementById(gridId + '-search');
    var grid = document.getElementById(gridId);
    if (!input || !grid) return;
    var term = input.value.toLowerCase();
    var rows = grid.querySelectorAll('tbody tr');
    rows.forEach(function(row) {
        var text = row.textContent.toLowerCase();
        row.style.display = term === '' || text.indexOf(term) >= 0 ? '' : 'none';
    });
}
</script>
