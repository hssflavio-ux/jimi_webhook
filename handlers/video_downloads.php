<?php
/**
 * JIMI Webhook System — Vídeo Downloads v4.0.0
 * Rota: /video/downloads
 *
 * Fila de extração device→servidor. Mostra arquivos com status de download:
 * solicitado → disponivel (pushfileupload fecha) → download funcionando.
 *
 * Grade: Nome, Identificador, Equipamento, Modelo, Canal, Requisitado em, Status.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$fileStorageUrl = rtrim(getenv('FILE_STORAGE_URL') ?: 'http://localhost:23010/download/', '/') . '/';

$selStatus = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$where = 'WHERE 1=1';
$params = [];
if ($customerId) {
    $where .= ' AND d.customer_id = :cid';
    $params[':cid'] = $customerId;
}
if ($selStatus === 'disponivel') {
    $where .= " AND mf.download_status = 'disponivel'";
} elseif ($selStatus === 'solicitado') {
    $where .= " AND (mf.download_status = 'solicitado' OR mf.download_status IS NULL)";
} elseif ($selStatus === 'erro') {
    $where .= " AND mf.download_status = 'erro'";
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM media_files mf LEFT JOIN devices d ON d.imei = mf.imei $where");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

$filesStmt = $db->prepare("
    SELECT mf.id, mf.imei, mf.file_name, mf.file_url, mf.file_type, mf.file_size,
           mf.event_time, mf.created_at, mf.channel, mf.download_status,
           d.device_name, COALESCE(dm.model_name, '—') as model_name
    FROM media_files mf
    LEFT JOIN devices d ON d.imei = mf.imei
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    $where
    ORDER BY mf.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$filesStmt->execute($params);
$files = $filesStmt->fetchAll();

$page_title = 'Vídeo Downloads';
$current_route = 'video_downloads';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Downloads de Vídeo</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">
            Fila de extração device → servidor. O status atualiza quando o dispositivo envia o arquivo.
        </p>
    </div>
</div>

<div class="flex" style="gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end;">
    <div>
        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Status</label>
        <select onchange="location.href='?status='+this.value" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
            <option value="">Todos</option>
            <option value="disponivel" <?= $selStatus==='disponivel'?'selected':'' ?>>Disponível</option>
            <option value="solicitado" <?= $selStatus==='solicitado'?'selected':'' ?>>Solicitado</option>
            <option value="erro" <?= $selStatus==='erro'?'selected':'' ?>>Erro</option>
        </select>
    </div>
    <button onclick="location.reload()" class="btn btn-outline btn-sm">Atualizar</button>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Arquivo</th>
                <th>IMEI</th>
                <th>Equipamento</th>
                <th>Modelo</th>
                <th>Canal</th>
                <th>Tipo</th>
                <th>Tamanho</th>
                <th>Requisitado em</th>
                <th>Status</th>
                <th style="text-align:center;">Download</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($files)): ?>
            <tr><td colspan="10" style="text-align:center;padding:32px;color:var(--muted);">Nenhum arquivo encontrado</td></tr>
            <?php else: ?>
            <?php foreach ($files as $f):
                $ds = $f['download_status'] ?? null;
                $isAvailable = $ds === 'disponivel';
                $isError = $ds === 'erro';
                $isRequested = $ds === 'solicitado' || $ds === null;
            ?>
            <tr>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= htmlspecialchars($f['file_name'] ?? '—') ?>
                </td>
                <td><span class="text-mono"><?= htmlspecialchars($f['imei']) ?></span></td>
                <td><?= htmlspecialchars($f['device_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($f['model_name']) ?></td>
                <td><?= $f['channel'] ? 'CH' . $f['channel'] : '—' ?></td>
                <td><span class="badge"><?= htmlspecialchars($f['file_type'] ?? '—') ?></span></td>
                <td><?= $f['file_size'] ? number_format($f['file_size']/1024/1024, 1) . ' MB' : '—' ?></td>
                <td class="text-mono"><?= date('d/m/Y H:i', strtotime($f['created_at'] ?? $f['event_time'])) ?></td>
                <td>
                    <?php if ($isAvailable): ?>
                    <span class="badge badge-success">Disponível</span>
                    <?php elseif ($isError): ?>
                    <span class="badge badge-error">Erro</span>
                    <?php else: ?>
                    <span class="badge badge-warning"><span class="spinner-inline"></span> Solicitado</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <?php if ($isAvailable): ?>
                    <a href="<?= htmlspecialchars($fileStorageUrl . $f['file_url']) ?>"
                       class="btn btn-primary btn-sm" style="padding:4px 12px;font-size:12px;"
                       target="_blank" download>Baixar</a>
                    <?php elseif ($isRequested): ?>
                    <span class="badge" style="font-size:11px;">Aguardando</span>
                    <?php else: ?>
                    <span class="badge badge-error" style="font-size:11px;">Falhou</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">
    <span>Página <?= $page ?> de <?= $totalPages ?> (<?= $totalRows ?> arquivos)</span>
    <div style="display:flex;gap:4px;">
        <?php
        $statusQ = $selStatus ? '&status=' . urlencode($selStatus) : '';
        if ($page > 1): ?>
        <a href="?page=<?= $page-1 . $statusQ ?>" class="btn btn-outline btn-sm">&laquo;</a>
        <?php endif;
        for ($i = 1; $i <= min($totalPages, 10); $i++):
            if ($i === $page): ?>
            <span class="btn btn-primary btn-sm" style="pointer-events:none;"><?= $i ?></span>
            <?php else: ?>
            <a href="?page=<?= $i . $statusQ ?>" class="btn btn-outline btn-sm"><?= $i ?></a>
            <?php endif;
        endfor;
        if ($page < $totalPages): ?>
        <a href="?page=<?= $page+1 . $statusQ ?>" class="btn btn-outline btn-sm">&raquo;</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<style>
.spinner-inline{display:inline-block;width:10px;height:10px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite;margin-right:4px;vertical-align:middle;}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
