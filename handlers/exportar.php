<?php
/**
 * JIMI Webhook System — Exportar Relatórios v4.0.0
 * Rota: /exportar
 *
 * Fila de geração assíncrona de relatórios pesados.
 * Grade: Nome, Tipo PDF/Excel, Status, Data Criação, download.
 * Polling via /exportardata.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$countStmt = $db->prepare("SELECT COUNT(*) FROM jobs j WHERE j.customer_id = :cid OR j.customer_id IS NULL");
$countStmt->execute([':cid' => $customerId]);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

$jobs = $db->prepare("
    SELECT j.*, u.name as requested_by_name
    FROM jobs j
    LEFT JOIN users u ON u.id = j.requested_by
    WHERE j.customer_id = :cid OR j.customer_id IS NULL
    ORDER BY j.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$jobs->execute([':cid' => $customerId]);
$jobs = $jobs->fetchAll();

$page_title = 'Exportar Relatórios';
$current_route = 'exportar';

$extra_head = '<style>
.spinner-inline{display:inline-block;width:10px;height:10px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite;margin-right:4px;vertical-align:middle;}
@keyframes spin{to{transform:rotate(360deg)}}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Exportar Relatórios</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">
            Geração assíncrona de relatórios. O arquivo fica disponível para download quando concluído.
        </p>
    </div>
    <label style="font-size:12px;display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--muted);">
        <input type="checkbox" id="auto-refresh" checked onchange="togglePoll()" style="width:auto;">
        Atualização automática
    </label>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Tipo</th>
                <th>Solicitante</th>
                <th>Status</th>
                <th>Criado em</th>
                <th>Atualizado em</th>
                <th style="text-align:center;">Download</th>
            </tr>
        </thead>
        <tbody id="export-tbody">
            <?php if (empty($jobs)): ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted);">Nenhum relatório na fila</td></tr>
            <?php else: ?>
            <?php foreach ($jobs as $j):
                $statusBadge = ['pendente'=>'badge-warning','processando'=>'badge-info','concluido'=>'badge-success','falhou'=>'badge-error'];
                $statusLabel = ['pendente'=>'Pendente','processando'=>'Processando','concluido'=>'Concluído','falhou'=>'Falhou'];
                $typeLabel = ['report'=>'Relatório','video_download'=>'Vídeo','rollup'=>'Agregação'];
            ?>
            <tr>
                <td>#<?= $j['id'] ?></td>
                <td><?= $typeLabel[$j['type']] ?? $j['type'] ?></td>
                <td><?= htmlspecialchars($j['requested_by_name'] ?? '—') ?></td>
                <td><span class="badge <?= $statusBadge[$j['status']] ?? 'badge' ?>"><?= $statusLabel[$j['status']] ?? $j['status'] ?></span></td>
                <td class="text-mono"><?= date('d/m/Y H:i', strtotime($j['created_at'])) ?></td>
                <td class="text-mono"><?= date('d/m/Y H:i', strtotime($j['updated_at'])) ?></td>
                <td style="text-align:center;">
                    <?php if ($j['status'] === 'concluido' && $j['result_path']): ?>
                    <a href="<?= htmlspecialchars($j['result_path']) ?>" class="btn btn-primary btn-sm" style="padding:4px 12px;font-size:12px;">Baixar</a>
                    <?php elseif ($j['status'] === 'falhou'): ?>
                    <span class="badge badge-error" style="font-size:11px;">Falhou</span>
                    <?php else: ?>
                    <span class="badge">Aguardando</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">
    <span>Página <?= $page ?> de <?= $totalPages ?></span>
    <div style="display:flex;gap:4px;">
        <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>" class="btn btn-outline btn-sm">&laquo;</a><?php endif;
        for ($i = 1; $i <= min($totalPages, 8); $i++):
            if ($i === $page): ?><span class="btn btn-primary btn-sm"><?= $i ?></span>
            <?php else: ?><a href="?page=<?= $i ?>" class="btn btn-outline btn-sm"><?= $i ?></a><?php endif;
        endfor;
        if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>" class="btn btn-outline btn-sm">&raquo;</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
var pollTimer = null;
function togglePoll() {
    var on = document.getElementById('auto-refresh').checked;
    if (on) startPoll(); else stopPoll();
}
function startPoll() {
    stopPoll();
    pollTimer = setInterval(function() { location.reload(); }, 30000);
}
function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
startPoll();
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
