<?php
/**
 * JIMI Webhook System — Dashboard de Ocorrências v4.0.0
 * Rota: /ocorrencias/dashboard
 *
 * Painel operacional de gestão de eventos DMS.
 * Layout: KPI cards + risk bar + data grid + auto-refresh polling.
 *
 * GET ?id=N abre o detalhe/tratativa de uma ocorrência específica.
 * POST atualiza status da ocorrência (tratativa).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/csrf.php';

$page_title = 'Dashboard de Ocorrências';
$current_route = 'ocorrencias';
$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();

// ── POST: Transição de status da tratativa ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['occurrence_id'])) {
    csrf_verify();
    $occId = (int)$_POST['occurrence_id'];
    $newStatus = $_POST['new_status'] ?? '';
    $notes = trim($_POST['treatment_notes'] ?? '');
    $falsePositive = !empty($_POST['false_positive']) ? 1 : 0;

    $validStatuses = ['em_tratativa', 'resolvida', 'descartada'];
    if (in_array($newStatus, $validStatuses)) {
        try {
            $stmt = $db->prepare(
                "UPDATE occurrences
                 SET status = :st, treated_by = :uid, treated_at = NOW(),
                     treatment_notes = :notes, false_positive = :fp
                 WHERE id = :id"
            );
            $stmt->execute([
                ':st'    => $newStatus,
                ':uid'   => $user['id'] ?? null,
                ':notes' => $notes,
                ':fp'    => $falsePositive,
                ':id'    => $occId,
            ]);
        } catch (Exception $e) {}
    }
}

$extra_head = '<script src="https://cdn.jsdelivr.net/npm/uplot@1.6.30/dist/uPlot.iife.min.js"></script>';

// ── Detalhe de uma ocorrência específica ──────────────────────
$detailOcc = null;
$detailEvents = [];
$detailMedia = null;
if (!empty($_GET['id'])) {
    $stmt = $db->prepare(
        "SELECT o.*, c.name as customer_name, d.name as driver_name
         FROM occurrences o
         LEFT JOIN customers c ON c.id = o.customer_id
         LEFT JOIN drivers d ON d.id = o.driver_id
         WHERE o.id = :id"
    );
    $stmt->execute([':id' => (int)$_GET['id']]);
    $detailOcc = $stmt->fetch();

    if ($detailOcc) {
        $stmt = $db->prepare(
            "SELECT e.id as event_id, a.id as alarm_id, a.alarm_name, a.alarm_time,
                    a.latitude, a.longitude, a.speed, a.file_url
             FROM occurrence_events e
             JOIN alarms a ON a.id = e.alarm_id
             WHERE e.occurrence_id = :oid
             ORDER BY a.alarm_time DESC"
        );
        $stmt->execute([':oid' => $detailOcc['id']]);
        $detailEvents = $stmt->fetchAll();

        if (!empty($detailOcc['media_file_id'])) {
            $stmt = $db->prepare("SELECT * FROM media_files WHERE id = :mid");
            $stmt->execute([':mid' => $detailOcc['media_file_id']]);
            $detailMedia = $stmt->fetch();
        }
    }
}

require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($detailOcc): ?>
<!-- ═══════════ DETALHE / TRATATIVA ═══════════ -->
<div class="card mb-24">
    <div class="flex-between mb-16">
        <div>
            <h2 style="font-size:18px;font-weight:600;color:var(--ink);">
                Ocorrência #<?= $detailOcc['id'] ?>
                <?php
                require_once __DIR__ . '/../web/components/status_pill.php';
                $status = $detailOcc['status'];
                $type = 'status';
                ?>
            </h2>
            <p class="text-muted" style="font-size:12px;">
                <?= htmlspecialchars($detailOcc['customer_name']) ?> ·
                IMEI: <span class="text-mono"><?= htmlspecialchars($detailOcc['imei']) ?></span> ·
                <?= date('d/m/Y H:i', strtotime($detailOcc['last_alarm_at'])) ?>
            </p>
        </div>
        <a href="/ocorrencias/dashboard" class="btn btn-outline btn-sm">Fechar</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
        <!-- Info -->
        <div>
            <table style="font-size:13px;">
                <tr><td style="padding:4px 12px 4px 0;color:var(--muted);">Tipo:</td><td><?= htmlspecialchars($detailOcc['alarm_type']) ?></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:var(--muted);">Risco:</td><td>
                    <?php $status=$detailOcc['risk']; $type='risk'; require __DIR__.'/../web/components/status_pill.php'; ?>
                </td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:var(--muted);">Motorista:</td><td><?= htmlspecialchars($detailOcc['driver_name'] ?? 'Não identificado') ?></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:var(--muted);">Primeiro alarme:</td><td><?= date('d/m/Y H:i:s', strtotime($detailOcc['first_alarm_at'])) ?></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:var(--muted);">Último alarme:</td><td><?= date('d/m/Y H:i:s', strtotime($detailOcc['last_alarm_at'])) ?></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:var(--muted);">Alarmes agrupados:</td><td><?= (int)$detailOcc['alarm_count'] ?></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:var(--muted);">Falso positivo:</td><td><?= $detailOcc['false_positive'] ? 'Sim' : 'Não' ?></td></tr>
            </table>
        </div>

        <!-- Mídia -->
        <div>
            <?php if ($detailMedia): ?>
            <div style="background:var(--ink);border-radius:var(--radius-md);overflow:hidden;min-height:200px;display:flex;align-items:center;justify-content:center;">
                <?php if (in_array($detailMedia['file_type'] ?? '', ['video', 'mp4', 'flv'])): ?>
                <video controls style="width:100%;max-height:300px;" poster="">
                    <source src="<?= htmlspecialchars($detailMedia['file_url']) ?>" type="video/mp4">
                </video>
                <?php elseif (($detailMedia['file_type'] ?? '') === 'image'): ?>
                <img src="<?= htmlspecialchars($detailMedia['file_url']) ?>" style="max-width:100%;max-height:300px;" alt="Mídia da ocorrência">
                <?php else: ?>
                <p style="color:var(--muted-soft);">Mídia: <?= htmlspecialchars($detailMedia['file_name'] ?? $detailMedia['file_url'] ?? '—') ?></p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="background:var(--canvas-soft);border-radius:var(--radius-md);padding:40px;text-align:center;color:var(--muted);">
                Sem vídeo vinculado
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Eventos agrupados -->
    <?php if (!empty($detailEvents)): ?>
    <h3 style="font-size:14px;font-weight:600;color:var(--ink);margin:20px 0 10px;">Alarmes Agrupados</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Alarme</th><th>Data/Hora</th><th>Canal</th></tr></thead>
            <tbody>
            <?php foreach ($detailEvents as $ev): ?>
            <tr>
                <td><?= htmlspecialchars($ev['alarm_name'] ?? '—') ?></td>
                <td class="text-mono"><?= date('d/m/Y H:i:s', strtotime($ev['alarm_time'])) ?></td>
                <td>
                    <?php if ($ev['file_url']): ?>
                    <a href="<?= htmlspecialchars($ev['file_url']) ?>" target="_blank" class="badge badge-primary">Ver</a>
                    <?php else: echo '—'; endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Ações de Tratativa -->
    <?php if ($detailOcc['status'] !== 'resolvida' && $detailOcc['status'] !== 'descartada'): ?>
    <div style="margin-top:20px;padding:16px;background:var(--canvas-soft);border-radius:var(--radius-md);">
        <form method="POST" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="occurrence_id" value="<?= $detailOcc['id'] ?>">
            <div class="form-group" style="margin:0;flex:1;min-width:200px;">
                <label>Notas de Tratativa</label>
                <textarea name="treatment_notes" rows="2" placeholder="Descreva a tratativa..."><?= htmlspecialchars($detailOcc['treatment_notes'] ?? '') ?></textarea>
            </div>
            <div style="display:flex;align-items:center;gap:6px;padding-bottom:2px;">
                <label style="font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;">
                    <input type="checkbox" name="false_positive" value="1" style="width:auto;"> Falso positivo
                </label>
            </div>
            <?php if ($detailOcc['status'] === 'aguardando'): ?>
            <button type="submit" name="new_status" value="em_tratativa" class="btn btn-primary btn-sm">Iniciar Tratativa</button>
            <?php endif; ?>
            <?php if ($detailOcc['status'] === 'aguardando' || $detailOcc['status'] === 'em_tratativa'): ?>
            <button type="submit" name="new_status" value="resolvida" class="btn btn-success btn-sm">Resolver</button>
            <button type="submit" name="new_status" value="descartada" class="btn btn-outline btn-sm" style="color:var(--error);">Descartar</button>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!empty($detailOcc['treatment_notes'])): ?>
    <div class="mt-24">
        <h4 style="font-size:13px;font-weight:600;color:var(--muted);">Histórico de Tratativa</h4>
        <p style="font-size:13px;color:var(--body);margin-top:4px;background:var(--canvas-soft);padding:12px;border-radius:var(--radius-sm);">
            <?= nl2br(htmlspecialchars($detailOcc['treatment_notes'])) ?>
        </p>
        <?php if (!empty($detailOcc['treated_by'])): ?>
        <p style="font-size:11px;color:var(--muted);margin-top:4px;">
            Tratado em <?= date('d/m/Y H:i', strtotime($detailOcc['treated_at'] ?? '')) ?>
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ═══════════ DASHBOARD PRINCIPAL ═══════════ -->
<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Dashboard de Ocorrências</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">
            Gestão de eventos DMS em tempo real
        </p>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <label style="font-size:12px;display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--muted);">
            <input type="checkbox" id="auto-refresh-toggle" checked onchange="toggleAutoRefresh()" style="width:auto;">
            Atualização automática
        </label>
        <span id="refresh-indicator" style="font-size:11px;color:var(--muted);display:none;"></span>
    </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid" id="kpi-cards">
    <div class="kpi-item"><div class="kpi-item-label">Ocorrências</div><div class="kpi-item-value" id="kpi-total">--</div></div>
    <div class="kpi-item"><div class="kpi-item-label">Aguardando Tratativa</div><div class="kpi-item-value" id="kpi-aguardando" style="color:var(--warning);">--</div></div>
    <div class="kpi-item"><div class="kpi-item-label">Dispositivos Online</div><div class="kpi-item-value" id="kpi-online" style="color:var(--success);">--</div></div>
    <div class="kpi-item"><div class="kpi-item-label">Dispositivos Offline</div><div class="kpi-item-value" id="kpi-offline" style="color:var(--error);">--</div></div>
</div>

<!-- Risk Bar -->
<div class="card mb-24" style="padding:16px 20px;">
    <div id="risk-bar-container"></div>
</div>

<!-- Filters -->
<div class="flex" style="gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end;">
    <div>
        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Status</label>
        <select id="filter-status" onchange="refreshData()" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
            <option value="">Todos</option>
            <option value="aguardando">Aguardando</option>
            <option value="em_tratativa">Em Tratativa</option>
            <option value="resolvida">Resolvida</option>
            <option value="descartada">Descartada</option>
        </select>
    </div>
    <div>
        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Risco</label>
        <select id="filter-risk" onchange="refreshData()" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
            <option value="">Todos</option>
            <option value="baixo">Baixo</option>
            <option value="medio">Médio</option>
            <option value="alto">Alto</option>
        </select>
    </div>
    <div>
        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">De</label>
        <input type="date" id="filter-date-from" onchange="refreshData()" value="<?= date('Y-m-d') ?>"
               style="padding:7px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
    </div>
    <div>
        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Até</label>
        <input type="date" id="filter-date-to" onchange="refreshData()" value="<?= date('Y-m-d') ?>"
               style="padding:7px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
    </div>
    <div>
        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Busca</label>
        <input type="text" id="filter-search" placeholder="IMEI ou motorista..." oninput="debounceSearch()"
               style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:180px;">
    </div>
    <button onclick="refreshData()" class="btn btn-outline btn-sm">Atualizar</button>
</div>

<!-- Table -->
<div class="table-wrap" id="occurrence-table-wrap">
    <table>
        <thead>
            <tr>
                <th>Cliente</th>
                <th>IMEI</th>
                <th>Motorista</th>
                <th>Tipo</th>
                <th>Último Alarme</th>
                <th>Risco</th>
                <th>Status</th>
                <th>Qtd</th>
                <th style="text-align:center;">Ação</th>
            </tr>
        </thead>
        <tbody id="occurrence-tbody">
            <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted);">Carregando...</td></tr>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<div class="flex-between mt-16" id="pagination" style="font-size:13px;color:var(--muted);display:none;">
    <span id="page-info"></span>
    <div id="page-buttons" style="display:flex;gap:4px;"></div>
</div>

<script>
var currentPage = 1;
var totalPages = 1;
var autoRefresh = true;
var refreshTimer = null;
var searchTimeout = null;
var currentData = null;

function toggleAutoRefresh() {
    autoRefresh = document.getElementById('auto-refresh-toggle').checked;
    if (autoRefresh) { startPolling(); }
    else { stopPolling(); }
}

function startPolling() {
    stopPolling();
    if (autoRefresh) {
        refreshTimer = setInterval(refreshData, 15000);
    }
}

function stopPolling() {
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
}

function debounceSearch() {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(function() { currentPage = 1; refreshData(); }, 400);
}

function goToPage(p) {
    currentPage = p;
    refreshData();
}

function refreshData() {
    var status = document.getElementById('filter-status').value;
    var risk = document.getElementById('filter-risk').value;
    var dateFrom = document.getElementById('filter-date-from').value;
    var dateTo = document.getElementById('filter-date-to').value;
    var search = document.getElementById('filter-search').value;
    var params = 'page=' + currentPage + '&per_page=20';
    if (status) params += '&status=' + encodeURIComponent(status);
    if (risk) params += '&risk=' + encodeURIComponent(risk);
    if (dateFrom) params += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) params += '&date_to=' + encodeURIComponent(dateTo);
    if (search) params += '&search=' + encodeURIComponent(search);

    fetch('/ocorrenciasdata?' + params)
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.code !== 0) return;
            currentData = resp.data;
            updateKPIs(resp.data);
            updateRiskBar(resp.data);
            updateTable(resp.data);
            updatePagination(resp.data);
        })
        .catch(function() {});

    var ind = document.getElementById('refresh-indicator');
    if (ind) { ind.style.display = 'inline'; ind.textContent = new Date().toLocaleTimeString('pt-BR'); setTimeout(function(){ind.style.display='none';},2000); }
}

function updateKPIs(data) {
    document.getElementById('kpi-total').textContent = data.kpis.total;
    document.getElementById('kpi-aguardando').textContent = data.kpis.aguardando;
    document.getElementById('kpi-online').textContent = data.devices.online;
    document.getElementById('kpi-offline').textContent = data.devices.offline;
}

function updateRiskBar(data) {
    var d = data.risk_distribution;
    var container = document.getElementById('risk-bar-container');
    container.innerHTML =
        '<div class="risk-bar-wrap">' +
        '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);margin-bottom:6px;">Distribuição de Risco</div>' +
        '<div style="display:flex;height:8px;border-radius:4px;overflow:hidden;margin-bottom:8px;">' +
            '<div style="width:' + d.baixo + '%;background:var(--primary);"></div>' +
            '<div style="width:' + d.medio + '%;background:var(--warning);"></div>' +
            '<div style="width:' + d.alto + '%;background:var(--error);"></div>' +
        '</div>' +
        '<div style="display:flex;justify-content:space-between;font-size:11px;font-weight:500;">' +
            '<span style="color:var(--primary);">Baixo ' + d.baixo + '%</span>' +
            '<span style="color:var(--warning);">Médio ' + d.medio + '%</span>' +
            '<span style="color:var(--error);">Alto ' + d.alto + '%</span>' +
        '</div></div>';
}

function updateTable(data) {
    var tbody = document.getElementById('occurrence-tbody');
    if (!data.rows || data.rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted);">Nenhuma ocorrência encontrada</td></tr>';
        return;
    }
    var html = '';
    var riskClass = {baixo:'badge-primary', medio:'badge-warning', alto:'badge-error'};
    var statusClass = {aguardando:'badge-warning', em_tratativa:'badge-info', resolvida:'badge-success', descartada:'badge'};
    var statusLabel = {aguardando:'Aguardando', em_tratativa:'Em Tratativa', resolvida:'Resolvida', descartada:'Descartada'};

    data.rows.forEach(function(r) {
        var date = new Date(r.last_alarm_at.replace(' ', 'T') + 'Z');
        var dateStr = date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
        html += '<tr>' +
            '<td>' + esc(r.customer_name) + '</td>' +
            '<td><span class="text-mono">' + esc(r.imei) + '</span></td>' +
            '<td>' + esc(r.driver_name) + '</td>' +
            '<td>' + esc(r.alarm_type) + '</td>' +
            '<td>' + dateStr + '</td>' +
            '<td><span class="badge ' + (riskClass[r.risk]||'badge') + '">' + esc(r.risk) + '</span></td>' +
            '<td><span class="badge ' + (statusClass[r.status]||'badge') + '">' + esc(statusLabel[r.status]||r.status) + '</span></td>' +
            '<td>' + r.alarm_count + '</td>' +
            '<td style="text-align:center;"><a href="?id=' + r.id + '" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">Abrir</a></td>' +
            '</tr>';
    });
    tbody.innerHTML = html;
}

function updatePagination(data) {
    currentPage = data.page;
    totalPages = data.total_pages;
    var pg = document.getElementById('pagination');
    if (totalPages <= 1) { pg.style.display = 'none'; return; }
    pg.style.display = 'flex';
    document.getElementById('page-info').textContent = 'Página ' + currentPage + ' de ' + totalPages + ' (' + data.total_rows + ' registros)';
    var btns = document.getElementById('page-buttons');
    var bhtml = '';
    if (currentPage > 1) bhtml += '<button onclick="goToPage(' + (currentPage-1) + ')" class="btn btn-outline btn-sm">&laquo;</button>';
    for (var i = 1; i <= totalPages; i++) {
        if (i === currentPage) bhtml += '<button class="btn btn-primary btn-sm" style="pointer-events:none;">' + i + '</button>';
        else if (i <= 3 || i >= totalPages - 2 || Math.abs(i - currentPage) <= 1) {
            bhtml += '<button onclick="goToPage(' + i + ')" class="btn btn-outline btn-sm">' + i + '</button>';
        } else if (i === 4 || i === totalPages - 3) {
            bhtml += '<span style="padding:4px 2px;">...</span>';
        }
    }
    if (currentPage < totalPages) bhtml += '<button onclick="goToPage(' + (currentPage+1) + ')" class="btn btn-outline btn-sm">&raquo;</button>';
    btns.innerHTML = bhtml;
}

function esc(s) {
    var d = document.createElement('div');
    d.textContent = s || '—';
    return d.innerHTML;
}

refreshData();
startPolling();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
