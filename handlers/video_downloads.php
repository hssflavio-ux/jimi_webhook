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

// Mídia servida por /midia (nossa origem, com login e escopo de cliente) em
// vez do FILE_STORAGE_URL público da porta 23010 — ver handlers/midia.php.
$fileStorageUrl = '/midia?f=';

// ── Escopo multi-tenant (v4.9.23) ──────────────────────────────────────────
//
// 🔴 O filtro anterior era `if ($customerId)`: com a sessão SEM cliente no
// contexto — que é o estado do admin de plataforma antes de escolher um — a
// cláusula simplesmente não entrava e a fila aparecia com os arquivos de
// TODOS os clientes, sem que a tela dissesse isso em lugar nenhum. Agora o
// escopo é explícito: `report_customer_scope()` decide, e quando o resultado é
// "todos" a tela declara de quem é cada arquivo.
$user        = get_jimi_user();
$isAdmin     = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$filtroCust  = $_GET['customer_id'] ?? null;
$scopeCust   = report_customer_scope($filtroCust, $isAdmin, $customerId);
$customers   = $isAdmin ? report_customer_options($db) : [];
$mostrarCliente = ($scopeCust === null);

// Equipamentos oferecidos no filtro, já no escopo resolvido.
$devStmt = $db->prepare("
    SELECT d.imei, COALESCE(NULLIF(d.device_name,''), d.imei) AS device_name
    FROM devices d
    WHERE 1=1 " . ($scopeCust !== null ? ' AND d.customer_id = :cid' : '') . "
    ORDER BY d.device_name
");
$devStmt->execute($scopeCust !== null ? [':cid' => $scopeCust] : []);
$devicesFiltro = $devStmt->fetchAll(PDO::FETCH_ASSOC);

$selStatus = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// Vários equipamentos, lista separada por vírgula no mesmo parâmetro `imei`.
// Conferidos contra a lista visível: o parâmetro da URL não alcança
// equipamento fora do escopo.
$imeisVisiveis = array_column($devicesFiltro, 'imei');
$filtroImeis   = array_values(array_intersect(
    array_filter(array_map('trim', explode(',', (string)($_GET['imei'] ?? '')))),
    $imeisVisiveis
));

$where = 'WHERE 1=1';
$params = [];
if ($scopeCust !== null) {
    $where .= ' AND d.customer_id = :cid';
    $params[':cid'] = $scopeCust;
}
if ($filtroImeis) {
    $ph = [];
    foreach ($filtroImeis as $i => $im) { $ph[] = ":im$i"; $params[":im$i"] = $im; }
    $where .= ' AND mf.imei IN (' . implode(',', $ph) . ')';
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
           COALESCE(NULLIF(d.device_name, ''), mf.imei) AS device_name,
           COALESCE(dm.model_name, '—') as model_name,
           COALESCE(cu.name, '—') AS customer_name
    FROM media_files mf
    LEFT JOIN devices d ON d.imei = mf.imei
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN customers cu ON cu.id = d.customer_id
    $where
    ORDER BY mf.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$filesStmt->execute($params);
$files = $filesStmt->fetchAll();

// ── Exportação (XLSX / PDF / CSV), sensível aos MESMOS filtros ─────────────
//
// Consulta própria, SEM o LIMIT da paginação: quem pede relatório quer o
// recorte inteiro, não os 25 que couberam na tela. Teto explícito para o
// arquivo não virar um dump da tabela inteira, declarado no subtítulo quando
// atingido — arquivo cortado sem aviso passa por completo.
const DOWNLOADS_EXPORT_TETO = 5000;
$export = strtolower(trim($_GET['export'] ?? ''));
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_once __DIR__ . '/../includes/export_helper.php';

    $expStmt = $db->prepare("
        SELECT mf.file_name, mf.imei, mf.file_type, mf.file_size, mf.channel,
               mf.created_at, mf.event_time, mf.download_status,
               COALESCE(NULLIF(d.device_name, ''), mf.imei) AS device_name,
               COALESCE(dm.model_name, '—') AS model_name,
               COALESCE(cu.name, '—') AS customer_name
        FROM media_files mf
        LEFT JOIN devices d ON d.imei = mf.imei
        LEFT JOIN device_models dm ON d.device_model_id = dm.id
        LEFT JOIN customers cu ON cu.id = d.customer_id
        $where
        ORDER BY mf.created_at DESC
        LIMIT " . DOWNLOADS_EXPORT_TETO);
    $expStmt->execute($params);

    $rotStatus = ['disponivel' => 'Disponível', 'solicitado' => 'Solicitado', 'erro' => 'Erro'];
    $expRows = [];
    foreach ($expStmt as $r) {
        $st = $r['download_status'] ?? null;
        $expRows[] = [
            fmt_brt($r['created_at'] ?? $r['event_time'], 'd/m/Y H:i:s'),
            $r['customer_name'],
            $r['device_name'],
            $r['imei'],
            $r['model_name'],
            $r['channel'] ? 'CH' . $r['channel'] : '—',
            $r['file_name'] ?: '—',
            $r['file_type'] ?: '—',
            $r['file_size'] ? number_format($r['file_size'] / 1024 / 1024, 1, ',', '.') : '—',
            $rotStatus[$st] ?? 'Solicitado',
        ];
    }

    $rotCliente = 'Todos os clientes';
    if ($scopeCust !== null) {
        foreach ($customers as $c) if ((int)$c['id'] === (int)$scopeCust) $rotCliente = 'Cliente: ' . $c['name'];
        if ($rotCliente === 'Todos os clientes') $rotCliente = 'Cliente ' . (int)$scopeCust;
    }
    $subtitulo = $rotCliente
        . '  |  ' . ($filtroImeis ? count($filtroImeis) . ' equipamento(s)' : 'Todos os equipamentos')
        . '  |  ' . ($selStatus !== '' ? 'Status: ' . ($rotStatus[$selStatus] ?? $selStatus) : 'Todos os status')
        . '  |  ' . $totalRows . ' arquivo(s)'
        . (count($expRows) >= DOWNLOADS_EXPORT_TETO ? '  |  ATENÇÃO: cortado nos ' . DOWNLOADS_EXPORT_TETO . ' mais recentes' : '');

    stream_export($export, 'downloads_video',
        ['Requisitado em', 'Cliente', 'Placa', 'IMEI', 'Modelo', 'Canal',
         'Arquivo', 'Tipo', 'Tamanho (MB)', 'Status'],
        $expRows, 'Downloads de Vídeo', $subtitulo,
        // Nome do arquivo é a coluna longa (`EVENT_<imei>_..._I_15.mp4`); as
        // demais são curtas e de largura previsível.
        [1.3, 1.2, 1.0, 1.4, 0.9, 0.5, 3.4, 0.6, 0.8, 0.9]);
}

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

<?php
/* Os filtros viraram um form GET. Antes o status recarregava por
   `location.href='?status='+…`, o que APAGAVA qualquer outro parâmetro —
   com cliente e equipamentos na URL, trocar o status jogaria os dois fora. */
$qsExport = function (string $fmt) use ($scopeCust, $filtroImeis, $selStatus): string {
    return '?' . http_build_query(array_filter([
        'customer_id' => $scopeCust !== null ? (int)$scopeCust : '',
        'imei'        => implode(',', $filtroImeis),
        'status'      => $selStatus,
        'export'      => $fmt,
    ], fn($v) => $v !== '' && $v !== null));
};
?>
<form method="get" class="flex" style="gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end;">
    <?php if ($isAdmin): ?>
    <div>
        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
        <select name="customer_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:160px;">
            <option value="">Todos os clientes</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (string)$scopeCust === (string)$c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div>
        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Status</label>
        <select name="status" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
            <option value="">Todos</option>
            <option value="disponivel" <?= $selStatus==='disponivel'?'selected':'' ?>>Disponível</option>
            <option value="solicitado" <?= $selStatus==='solicitado'?'selected':'' ?>>Solicitado</option>
            <option value="erro" <?= $selStatus==='erro'?'selected':'' ?>>Erro</option>
        </select>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
    <?php
    $chips_id       = 'dlvdev';
    $chips_label    = 'Equipamentos (nenhum = todos)';
    $chips_param    = 'imei';
    $chips_options  = array_column($devicesFiltro, 'imei');
    $chips_labels   = array_column($devicesFiltro, 'device_name', 'imei');
    $chips_selected = $filtroImeis;
    $chips_visible  = 12;
    include __DIR__ . '/../web/components/chips_multiselect.php';
    ?>
</form>

<div class="flex" style="gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
    <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($qsExport('xlsx')) ?>">Exportar Excel</a>
    <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($qsExport('pdf')) ?>">Exportar PDF</a>
    <span style="font-size:11px;color:var(--muted);"><?= (int)$totalRows ?> arquivo(s) no filtro atual</span>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Arquivo</th>
                <?php if ($mostrarCliente): ?><th>Cliente</th><?php endif; ?>
                <th>Placa</th>
                <th>IMEI</th>
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
            <tr><td colspan="<?= $mostrarCliente ? 11 : 10 ?>" style="text-align:center;padding:32px;color:var(--muted);">Nenhum arquivo encontrado</td></tr>
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
                <?php if ($mostrarCliente): ?>
                <td style="font-size:11px;"><?= htmlspecialchars($f['customer_name']) ?></td>
                <?php endif; ?>
                <td><span class="text-mono"><?= htmlspecialchars($f['device_name']) ?></span></td>
                <td><span class="text-mono" style="font-size:11px;color:var(--muted);"><?= htmlspecialchars($f['imei']) ?></span></td>
                <td><?= htmlspecialchars($f['model_name']) ?></td>
                <td><?= $f['channel'] ? 'CH' . $f['channel'] : '—' ?></td>
                <td><span class="badge"><?= htmlspecialchars($f['file_type'] ?? '—') ?></span></td>
                <td><?= $f['file_size'] ? number_format($f['file_size']/1024/1024, 1) . ' MB' : '—' ?></td>
                <td class="text-mono"><?= fmt_brt($f['created_at'] ?? $f['event_time']) ?></td>
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

<?= report_pagination($page, $totalPages, $totalRows, 'arquivos') ?>

<style>
.spinner-inline{display:inline-block;width:10px;height:10px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite;margin-right:4px;vertical-align:middle;}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
