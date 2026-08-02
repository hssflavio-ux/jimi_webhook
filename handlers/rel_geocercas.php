<?php
/**
 * JIMI Webhook System — Relatório de Geocercas v4.5.0
 * Rota: /relatorios/geocercas
 *
 * Duas modalidades sobre a mesma tabela geofence_events:
 *
 *   eventos      — a lista crua de entradas e saídas
 *   permanencia  — cada entrada pareada com a saída seguinte (LEAD), com o
 *                  tempo dentro da cerca
 *
 * O pareamento usa função de janela (MySQL 8) particionada por cerca ×
 * equipamento. Uma entrada sem saída no período aparece como "em permanência"
 * — o que é a leitura correta tanto para o veículo que ainda está lá dentro
 * quanto para o que saiu depois do fim do filtro.
 *
 * Segue o molde de rel_alarmes.php: clamp_report_range(), report_sort_params(),
 * stream_export(), report_pagination(), report_back_button().
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/geocode.php';   // endereço no lugar de lat/lng
require_login();

require_once __DIR__ . '/../includes/report_templates.php';
// Salvar/aplicar/excluir modelo — antes de qualquer saída (as três ações redirecionam)
handle_template_actions('rel_geocercas', '/relatorios/geocercas');

$page_title = 'Relatório de Geocercas';
$current_route = 'rel_geocercas';
$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$view = ($_GET['view'] ?? 'eventos') === 'permanencia' ? 'permanencia' : 'eventos';

$dateFrom = $_GET['date_from'] ?? brt_today();
$dateTo   = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo);

$filterFence = !empty($_GET['geofence_id']) ? (int)$_GET['geofence_id'] : null;
$filterImei  = trim($_GET['imei'] ?? '');
$filterType  = in_array($_GET['event_type'] ?? '', ['entrada', 'saida'], true) ? $_GET['event_type'] : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$validSorts = ['event_time', 'imei', 'event_type'];
[$sort, $order] = report_sort_params($validSorts, 'event_time', 'ASC');

[$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo);
$where = 'WHERE e.event_time BETWEEN :df AND :dt';
$params = [':df' => $utcFrom, ':dt' => $utcTo];

// Escopo multi-tenant: o customer_id do evento vem da cerca que o gerou
if ($customerId) {
    $where .= ' AND e.customer_id = :cid';
    $params[':cid'] = $customerId;
}
if ($filterFence) {
    $where .= ' AND e.geofence_id = :gid';
    $params[':gid'] = $filterFence;
}
if ($filterImei !== '') {
    $where .= ' AND e.imei LIKE :imei';
    $params[':imei'] = "%$filterImei%";
}
// O filtro de tipo só faz sentido na lista de eventos; na permanência a
// linha É sempre uma entrada.
if ($filterType !== '' && $view === 'eventos') {
    $where .= ' AND e.event_type = :etype';
    $params[':etype'] = $filterType;
}

/**
 * Formata uma duração em segundos como "2h 14min" / "36min" / "48s".
 *
 * @param int|null $secs Duração em segundos
 * @returns string
 */
function fmt_dwell(?int $secs): string
{
    if ($secs === null || $secs < 0) {
        return '—';
    }
    if ($secs < 60) {
        return $secs . 's';
    }
    $h = intdiv($secs, 3600);
    $m = intdiv($secs % 3600, 60);
    return $h > 0 ? ($h . 'h ' . $m . 'min') : ($m . 'min');
}

// A tabela pode não existir ainda (migração v4.5.0 pendente)
$tableMissing = false;
$rows = [];
$totalRows = 0;
$totalPages = 1;
$kpi = ['entradas' => 0, 'saidas' => 0, 'devices' => 0, 'fences' => 0];

$sqlEvents = "
    FROM geofence_events e
    JOIN geofences g ON g.id = e.geofence_id
    LEFT JOIN devices d ON d.imei = e.imei
    $where";

// A permanência pareia cada entrada com o evento seguinte da MESMA cerca e
// do MESMO equipamento. next_type é lido junto para não tratar como saída um
// evento que, por qualquer descontinuidade, seja outra entrada.
$sqlDwell = "
    SELECT * FROM (
        SELECT e.id, e.geofence_id, e.imei, e.event_type, e.event_time,
               e.latitude, e.longitude,
               g.name AS fence_name, g.color, g.kind,
               COALESCE(d.device_name, e.imei) AS device_label,
               LEAD(e.event_time) OVER w AS exit_time,
               LEAD(e.event_type) OVER w AS next_type
        $sqlEvents
        WINDOW w AS (PARTITION BY e.geofence_id, e.imei ORDER BY e.event_time)
    ) t
    WHERE t.event_type = 'entrada'";

// ── Export síncrono ────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';

    try {
        if ($view === 'permanencia') {
            $stmt = $db->prepare("$sqlDwell ORDER BY t.event_time $order LIMIT " . SYNC_EXPORT_MAX_ROWS);
            $stmt->execute($params);
            $expRows = [];
            while ($r = $stmt->fetch()) {
                $exit = ($r['next_type'] === 'saida') ? $r['exit_time'] : null;
                $dwell = $exit ? (strtotime($exit) - strtotime($r['event_time'])) : null;
                $expRows[] = [
                    $r['fence_name'],
                    $r['device_label'],
                    $r['imei'],
                    fmt_brt($r['event_time'], 'd/m/Y H:i:s'),
                    $exit ? fmt_brt($exit, 'd/m/Y H:i:s') : 'Em permanência',
                    fmt_dwell($dwell),
                ];
            }
            stream_export($export, 'relatorio_geocercas_permanencia',
                ['Geocerca', 'Equipamento', 'IMEI', 'Entrada', 'Saída', 'Permanência'],
                $expRows, 'Relatório de Geocercas — Permanência', "Período (BRT): $dateFrom a $dateTo");
        } else {
            $stmt = $db->prepare("
                SELECT e.imei, e.event_type, e.event_time, e.latitude, e.longitude, e.speed,
                       g.name AS fence_name, COALESCE(d.device_name, e.imei) AS device_label
                $sqlEvents
                ORDER BY e.$sort $order
                LIMIT " . SYNC_EXPORT_MAX_ROWS);
            $stmt->execute($params);
            // fetchAll antes do laço: endereço resolvido em UM lote paralelo
            $src = $stmt->fetchAll();
            $geoExp = geocode_map_rows($src, 'latitude', 'longitude', 2000);
            $expRows = [];
            foreach ($src as $r) {
                $expRows[] = [
                    fmt_brt($r['event_time'], 'd/m/Y H:i:s'),
                    $r['fence_name'],
                    $r['device_label'],
                    $r['imei'],
                    $r['event_type'] === 'entrada' ? 'Entrada' : 'Saída',
                    $r['speed'] !== null ? number_format((float)$r['speed'], 1) : '—',
                    geocode_cell($geoExp, $r['latitude'], $r['longitude']),
                ];
            }
            stream_export($export, 'relatorio_geocercas',
                ['Data/Hora', 'Geocerca', 'Equipamento', 'IMEI', 'Evento', 'Velocidade (km/h)', 'Endereço'],
                $expRows, 'Relatório de Geocercas', "Período (BRT): $dateFrom a $dateTo");
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Erro ao exportar: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

// ── Dados da grade ─────────────────────────────────────────────
try {
    if ($view === 'permanencia') {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM ($sqlDwell) c");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $offset = ($page - 1) * $perPage;

        $dataStmt = $db->prepare("$sqlDwell ORDER BY t.event_time $order LIMIT $perPage OFFSET $offset");
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll();
    } else {
        $countStmt = $db->prepare("SELECT COUNT(*) $sqlEvents");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $offset = ($page - 1) * $perPage;

        $dataStmt = $db->prepare("
            SELECT e.id, e.imei, e.event_type, e.event_time, e.latitude, e.longitude, e.speed,
                   g.name AS fence_name, g.color, g.kind,
                   COALESCE(d.device_name, e.imei) AS device_label
            $sqlEvents
            ORDER BY e.$sort $order
            LIMIT $perPage OFFSET $offset");
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll();
    }

    $kpiStmt = $db->prepare("
        SELECT SUM(e.event_type = 'entrada') AS entradas,
               SUM(e.event_type = 'saida')   AS saidas,
               COUNT(DISTINCT e.imei)        AS devices,
               COUNT(DISTINCT e.geofence_id) AS fences
        $sqlEvents");
    $kpiStmt->execute($params);
    if ($k = $kpiStmt->fetch()) {
        $kpi = [
            'entradas' => (int)$k['entradas'],
            'saidas'   => (int)$k['saidas'],
            'devices'  => (int)$k['devices'],
            'fences'   => (int)$k['fences'],
        ];
    }
} catch (Throwable $e) {
    $tableMissing = true;
}

// ── Dropdown de cercas ─────────────────────────────────────────
$fenceList = [];
try {
    if ($customerId) {
        $stmt = $db->prepare("SELECT id, name FROM geofences WHERE customer_id = :cid ORDER BY name");
        $stmt->execute([':cid' => $customerId]);
        $fenceList = $stmt->fetchAll();
    } else {
        $fenceList = $db->query("SELECT id, name FROM geofences ORDER BY name")->fetchAll();
    }
} catch (Throwable $e) {}

require_once __DIR__ . '/../web/layout_base.php';
?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-16">
    <?= report_brand() ?><h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Geocercas</h2><?= report_brand_end() ?>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?php if (report_has_query()) echo report_back_button('/relatorios/geocercas'); ?>
    </div>
</div>

<?php if ($tableMissing): ?>
<div class="card mb-16" style="border-left:3px solid var(--error);">
    <div style="font-size:13px;color:var(--muted);">
        <strong>Tabelas de geocerca indisponíveis.</strong> Aplique a migração <code>v4.5.0</code>.
    </div>
</div>
<?php endif; ?>

<?php render_template_bar('rel_geocercas', '/relatorios/geocercas'); ?>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Modalidade</label>
            <select name="view" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="eventos"     <?= $view === 'eventos' ? 'selected' : '' ?>>Entradas e saídas</option>
                <option value="permanencia" <?= $view === 'permanencia' ? 'selected' : '' ?>>Permanência</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Geocerca</label>
            <select name="geofence_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:160px;">
                <option value="">Todas</option>
                <?php foreach ($fenceList as $f): ?>
                <option value="<?= (int)$f['id'] ?>" <?= $filterFence === (int)$f['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($f['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">IMEI</label>
            <input type="text" name="imei" value="<?= htmlspecialchars($filterImei) ?>" placeholder="Buscar..."
                   style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:140px;">
        </div>
        <?php if ($view === 'eventos'): ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Evento</label>
            <select name="event_type" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="entrada" <?= $filterType === 'entrada' ? 'selected' : '' ?>>Entrada</option>
                <option value="saida"   <?= $filterType === 'saida'   ? 'selected' : '' ?>>Saída</option>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Período (máx. <?= REPORT_RANGE_MAX_DAYS ?> dias)</label>
            <div style="display:flex;gap:4px;">
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
    </form>
</div>

<?php if ($rangeClamped): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid #f5a623;font-size:13px;color:var(--muted);">
    O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias:
    <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px;">
    <?php
    $kpiCards = [
        ['Entradas',      $kpi['entradas'], 'blue'],
        ['Saídas',        $kpi['saidas'],   'yellow'],
        ['Equipamentos',  $kpi['devices'],  'green'],
        ['Geocercas',     $kpi['fences'],   'blue'],
    ];
    foreach ($kpiCards as [$label, $value, $variant]):
    ?>
    <div class="card" style="padding:14px 18px;">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);"><?= $label ?></div>
        <div class="text-mono" style="font-size:26px;font-weight:500;color:var(--ink);margin-top:4px;"><?= (int)$value ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($view === 'permanencia'): ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Geocerca</th>
                <th>Equipamento</th>
                <th><?= report_sort_link('event_time', 'Entrada', $sort, $order) ?></th>
                <th>Saída</th>
                <th>Permanência</th>
                <th>Mapa</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted);">Nenhuma permanência no período</td></tr>
            <?php else: foreach ($rows as $r):
                $exit  = ($r['next_type'] === 'saida') ? $r['exit_time'] : null;
                $dwell = $exit ? (strtotime($exit) - strtotime($r['event_time'])) : null;
            ?>
            <tr>
                <td>
                    <span style="display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:7px;vertical-align:middle;background:<?= htmlspecialchars($r['color'] ?: '#0052ff') ?>;"></span>
                    <?= htmlspecialchars($r['fence_name']) ?>
                </td>
                <td>
                    <?= htmlspecialchars($r['device_label']) ?>
                    <div class="text-mono text-muted" style="font-size:11px;"><?= htmlspecialchars($r['imei']) ?></div>
                </td>
                <td class="text-mono"><?= fmt_brt($r['event_time'], 'd/m/Y H:i:s') ?></td>
                <td class="text-mono">
                    <?php if ($exit): ?>
                        <?= fmt_brt($exit, 'd/m/Y H:i:s') ?>
                    <?php else: ?>
                        <span class="badge badge-info">Em permanência</span>
                    <?php endif; ?>
                </td>
                <td class="text-mono"><?= htmlspecialchars(fmt_dwell($dwell)) ?></td>
                <td>
                    <a href="https://www.openstreetmap.org/?mlat=<?= $r['latitude'] ?>&mlon=<?= $r['longitude'] ?>&zoom=16"
                       target="_blank" class="badge badge-primary">Ver Mapa</a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<p class="text-muted" style="font-size:11px;margin-top:8px;">
    A saída é o evento seguinte da mesma cerca e do mesmo equipamento dentro do período filtrado.
    Uma entrada cuja saída ficou fora do período aparece como “Em permanência”.
</p>

<?php else: ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th><?= report_sort_link('event_time', 'Data/Hora', $sort, $order) ?></th>
                <th>Geocerca</th>
                <th><?= report_sort_link('imei', 'Equipamento', $sort, $order) ?></th>
                <th><?= report_sort_link('event_type', 'Evento', $sort, $order) ?></th>
                <th>Velocidade</th>
                <th>Mapa</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted);">Nenhum evento de geocerca no período</td></tr>
            <?php else: foreach ($rows as $r): ?>
            <tr>
                <td class="text-mono"><?= fmt_brt($r['event_time'], 'd/m/Y H:i:s') ?></td>
                <td>
                    <span style="display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:7px;vertical-align:middle;background:<?= htmlspecialchars($r['color'] ?: '#0052ff') ?>;"></span>
                    <?= htmlspecialchars($r['fence_name']) ?>
                </td>
                <td>
                    <?= htmlspecialchars($r['device_label']) ?>
                    <div class="text-mono text-muted" style="font-size:11px;"><?= htmlspecialchars($r['imei']) ?></div>
                </td>
                <td>
                    <?php if ($r['event_type'] === 'entrada'): ?>
                        <span class="badge badge-success">Entrada</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Saída</span>
                    <?php endif; ?>
                </td>
                <td><?= $r['speed'] !== null ? number_format((float)$r['speed'], 1) . ' km/h' : '—' ?></td>
                <td>
                    <a href="https://www.openstreetmap.org/?mlat=<?= $r['latitude'] ?>&mlon=<?= $r['longitude'] ?>&zoom=16"
                       target="_blank" class="badge badge-primary">Ver Mapa</a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?= report_pagination($page, $totalPages, $totalRows, $view === 'permanencia' ? 'permanências' : 'eventos') ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
