<?php
/**
 * JIMI Webhook System — Exportar Relatórios v4.0.0
 * Rota: /exportar
 *
 * Fila de geração assíncrona de relatórios pesados.
 * Grade: Nome, Tipo, Status, Data Criação, download.
 * Novo: formulário para solicitar geração de relatório.
 * Polling via /exportardata.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$msg = '';
$msgType = '';

// ── Create export job ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $reportName = trim($_POST['report_name'] ?? '');
    $reportType = $_POST['report_type'] ?? 'alarms';
    $dateFrom   = $_POST['date_from'] ?? brt_today('Y-m-d', '-30 days');
    $dateTo     = $_POST['date_to'] ?? brt_today();
    [$dateFrom, $dateTo] = clamp_report_range($dateFrom, $dateTo); // teto global 31 dias
    $format     = in_array($_POST['format'] ?? 'csv', ['csv', 'xlsx', 'pdf'], true) ? $_POST['format'] : 'csv';

    if ($reportName) {
        // format também vai no params: fallback do worker antes da migration v4.1.0
        $params = json_encode([
            'report_name' => $reportName,
            'report_type' => $reportType,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'format'      => $format,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $insert = $db->prepare("INSERT INTO jobs (type, format, customer_id, params, status, requested_by) VALUES ('report', :fmt, :cid, :params, 'pendente', :uid)");
            $insert->execute([':fmt' => $format, ':cid' => $customerId, ':params' => $params, ':uid' => $user['id']]);
        } catch (Exception $e) {
            // Coluna jobs.format ainda não existe (pré-migração v4.1.0)
            $insert = $db->prepare("INSERT INTO jobs (type, customer_id, params, status, requested_by) VALUES ('report', :cid, :params, 'pendente', :uid)");
            $insert->execute([':cid' => $customerId, ':params' => $params, ':uid' => $user['id']]);
        }
        $msg = 'Relatório "' . htmlspecialchars($reportName) . '" adicionado à fila de geração.';
        $msgType = 'success';
    } else {
        $msg = 'Informe um nome para o relatório.';
        $msgType = 'error';
    }
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM jobs j WHERE j.customer_id = :cid OR j.customer_id IS NULL");
$countStmt->execute([':cid' => $customerId]);
$totalRows = 0;
try {
    $totalRows = (int)$countStmt->fetchColumn();
} catch (Exception $e) {}
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

$jobs = [];
try {
    $jobsStmt = $db->prepare("
        SELECT j.*, u.name as requested_by_name
        FROM jobs j
        LEFT JOIN users u ON u.id = j.requested_by
        WHERE j.customer_id = :cid OR j.customer_id IS NULL
        ORDER BY j.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $jobsStmt->execute([':cid' => $customerId]);
    $jobs = $jobsStmt->fetchAll();
} catch (Exception $e) {}

// Device list for export filter
$devices = $db->prepare("SELECT imei, COALESCE(device_name, imei) as label FROM devices WHERE customer_id = :cid ORDER BY label");
$devices->execute([':cid' => $customerId ?? 1]);
$devices = $devices->fetchAll();

$page_title = 'Exportar Relatórios';
$current_route = 'exportar';

$extra_head = '<style>
.spinner-inline{display:inline-block;width:10px;height:10px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite;margin-right:4px;vertical-align:middle;}
@keyframes spin{to{transform:rotate(360deg)}}
.export-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
@media(max-width:600px){.export-form-grid{grid-template-columns:1fr;}}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>" style="margin-bottom:16px;padding:10px 14px;border-radius:var(--radius-sm);font-size:13px;<?= $msgType==='success'?'background:#e8f5e9;color:#05b169;border:1px solid #a5d6a7;':'background:#fdecea;color:#cf202f;border:1px solid #f5c6cb;' ?>">
    <?= $msg ?>
</div>
<?php endif; ?>

<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Exportar Relatórios</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">
            Geração assíncrona de relatórios. Os arquivos ficam disponíveis para download quando concluídos.
        </p>
    </div>
    <label style="font-size:12px;display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--muted);">
        <input type="checkbox" id="auto-refresh" checked onchange="togglePoll()" style="width:auto;">
        Atualização automática
    </label>
</div>

<!-- ═══════ New Export Form ═══════ -->
<div class="card mb-24" style="padding:16px 20px;">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px;">Novo Relatório</h4>
    <form method="POST">
        <?= csrf_field() ?>
        <div class="export-form-grid">
            <div>
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Nome do Relatório</label>
                <input type="text" name="report_name" required placeholder="Ex: Alarmes Julho 2026" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
            </div>
            <div>
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Tipo</label>
                <select name="report_type" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    <option value="alarms">Alarmes</option>
                    <option value="occurrences">Ocorrências</option>
                    <option value="positions">Posições GPS</option>
                    <option value="trips">Viagens (Deslocamento)</option>
                    <option value="devices">Equipamentos</option>
                </select>
            </div>
            <div>
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Data Início</label>
                <input type="date" name="date_from" value="<?= brt_today('Y-m-d', '-30 days') ?>" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
            </div>
            <div>
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Data Fim</label>
                <input type="date" name="date_to" value="<?= brt_today() ?>" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
            </div>
            <div>
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Formato</label>
                <select name="format" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    <option value="csv">CSV (.csv)</option>
                    <option value="xlsx">Excel (.xlsx)</option>
                    <option value="pdf">PDF (.pdf)</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:12px;padding:8px 24px;">Gerar Relatório</button>
    </form>
</div>

<!-- ═══════ Jobs Grid ═══════ -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nome</th>
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
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted);">Nenhum relatório na fila</td></tr>
            <?php else: ?>
            <?php foreach ($jobs as $j):
                $statusBadge = ['pendente'=>'badge-warning','processando'=>'badge-info','concluido'=>'badge-success','falhou'=>'badge-error'];
                $statusLabel = ['pendente'=>'Pendente','processando'=>'Processando','concluido'=>'Concluído','falhou'=>'Falhou'];
                $typeLabel = ['report'=>'Relatório','video_download'=>'Vídeo','rollup'=>'Agregação'];
                $jParams = json_decode($j['params'] ?? '{}', true) ?: [];
                $jFormat = strtoupper($j['format'] ?? $jParams['format'] ?? 'csv');
            ?>
            <tr data-job-id="<?= $j['id'] ?>" data-status="<?= htmlspecialchars($j['status']) ?>">
                <td>#<?= $j['id'] ?></td>
                <td><?= htmlspecialchars($jParams['report_name'] ?? '—') ?></td>
                <td>
                    <?= $typeLabel[$j['type']] ?? $j['type'] ?>
                    <?php if ($j['type'] === 'report'): ?>
                    <span class="badge" style="font-size:10px;margin-left:4px;"><?= htmlspecialchars($jFormat) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($j['requested_by_name'] ?? '—') ?></td>
                <td><span class="badge <?= $statusBadge[$j['status']] ?? 'badge' ?>"><?= $statusLabel[$j['status']] ?? $j['status'] ?></span></td>
                <td class="text-mono"><?= fmt_brt($j['created_at']) ?></td>
                <td class="text-mono"><?= fmt_brt($j['updated_at']) ?></td>
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
    // D3 (v4.2.0): polling real via /exportardata — só recarrega quando algum
    // status mudou (sem flicker de reload cego a cada 30s)
    pollTimer = setInterval(function() {
        fetch('/exportardata').then(function(r) { return r.json(); }).then(function(resp) {
            if (!resp || resp.code !== 0) return;
            var changed = false;
            (resp.data.jobs || []).forEach(function(job) {
                var tr = document.querySelector('tr[data-job-id="' + job.id + '"]');
                if (tr && tr.dataset.status !== job.status) changed = true;
                if (!tr) changed = true; // job novo na fila
            });
            if (changed) location.reload();
        }).catch(function() {});
    }, 10000);
}
function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
startPoll();
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
