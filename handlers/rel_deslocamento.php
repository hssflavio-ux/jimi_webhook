<?php
/**
 * JIMI Webhook System — Relatório de Deslocamento v4.3.0
 * Rota: /relatorios/deslocamento
 *
 * Duas modalidades (toggle no filtro):
 *   - viagens: uma linha por deslocamento (intervalo ignição lig→desl) — tabela trips.
 *   - diario:  fechamento por dia (BRT): primeira ignição ligada → última desligada,
 *              agregando jornada, tempo em movimento, KM, vel. máx e alarmes.
 *
 * Filtro: Ativos + Período (teto global de 31 dias) + faixa horária opcional
 * + [Gerar] + Export. Cada linha tem link "Ver rota" para o mapa do percurso
 * (/relatorios/deslocamento/rota).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$mode     = ($_GET['mode'] ?? 'viagens') === 'diario' ? 'diario' : 'viagens';
$selImei  = $_GET['imei'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo   = $_GET['date_to'] ?? brt_today();
$timeFrom = $_GET['time_from'] ?? '';
$timeTo   = $_GET['time_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$generated = !empty($_GET['gerar']);

[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo);

// Ordenação por modalidade; ambas abrem crescente por data/hora
[$sort, $order] = $mode === 'diario'
    ? report_sort_params(['dia'], 'dia', 'ASC')
    : report_sort_params(['started_at', 'ended_at', 'distance_km', 'max_speed'], 'started_at', 'ASC');

$rows = [];
$totalRows = 0;
$totalPages = 1;

// Fechamento diário: agrega as viagens do dia BRT (primeira ignição ligada →
// última desligada). Viagem que cruza a meia-noite conta inteira no dia em que
// começou. CONVERT_TZ por offset fixo (BRT sem DST), convenção do projeto.
$dailySelect = "
    SELECT t.imei,
           MAX(COALESCE(d.device_name, t.imei)) AS device_name,
           DATE(CONVERT_TZ(t.started_at, '+00:00', '-03:00')) AS dia,
           MIN(t.started_at) AS primeira_on,
           MAX(t.ended_at) AS ultima_off,
           TIMESTAMPDIFF(SECOND, MIN(t.started_at), MAX(t.ended_at)) AS jornada_s,
           SUM(t.duration_s) AS movimento_s,
           SUM(t.distance_km) AS distance_km,
           MAX(t.max_speed) AS max_speed,
           SUM(t.alarm_count) AS alarm_count,
           COUNT(*) AS viagens
    FROM trips t
    LEFT JOIN devices d ON d.imei = t.imei";

if ($generated) {
    $where = 'WHERE t.started_at BETWEEN :df AND :dt';
    // Dias BRT (+ faixa horária opcional) → janela UTC
    [$utcFrom, $utcTo] = brt_datetime_range_to_utc($dateFrom, $dateTo, $timeFrom, $timeTo);
    $params = [':df' => $utcFrom, ':dt' => $utcTo];

    if ($customerId) {
        $where .= ' AND t.customer_id = :cid';
        $params[':cid'] = $customerId;
    }
    if ($selImei) {
        $where .= ' AND t.imei = :imei';
        $params[':imei'] = $selImei;
    }

    // Export síncrono (padrão YUV §9.2): mesma query da grade, sem paginação
    $export = $_GET['export'] ?? '';
    if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
        require_permission('relatorios', 'export');
        require_once __DIR__ . '/../includes/export_helper.php';
        $expRows = [];
        try {
            if ($mode === 'diario') {
                $expStmt = $db->prepare("$dailySelect $where
                    GROUP BY t.imei, dia ORDER BY dia $order, device_name
                    LIMIT " . SYNC_EXPORT_MAX_ROWS);
                $expStmt->execute($params);
                while ($r = $expStmt->fetch()) {
                    $expRows[] = [
                        fmt_brt($r['primeira_on'], 'd/m/Y'),
                        $r['imei'],
                        $r['device_name'],
                        fmt_brt($r['primeira_on'], 'd/m/Y H:i'),
                        $r['ultima_off'] ? fmt_brt($r['ultima_off'], 'd/m/Y H:i') : '—',
                        fmt_duration((int)($r['jornada_s'] ?? 0)),
                        fmt_duration((int)($r['movimento_s'] ?? 0)),
                        $r['distance_km'] ? number_format((float)$r['distance_km'], 1) : '—',
                        $r['max_speed'] ? number_format((float)$r['max_speed'], 1) : '—',
                        (int)($r['alarm_count'] ?? 0),
                        (int)$r['viagens'],
                    ];
                }
                stream_export($export, 'relatorio_deslocamento_diario',
                    ['Dia', 'IMEI', 'Dispositivo', 'Primeira Ignição', 'Última Ign. Deslig.', 'Jornada', 'Em Movimento', 'Distância (km)', 'Vel. Máx (km/h)', 'Alarmes', 'Viagens'],
                    $expRows, 'Relatório de Deslocamento — Fechamento Diário', "Período (BRT): $dateFrom a $dateTo");
            } else {
                $expStmt = $db->prepare("
                    SELECT t.*, COALESCE(d.device_name, t.imei) as device_name,
                           COALESCE(dr.name, '—') as driver_name
                    FROM trips t
                    LEFT JOIN devices d ON d.imei = t.imei
                    LEFT JOIN drivers dr ON dr.id = t.driver_id
                    $where
                    ORDER BY t.$sort $order
                    LIMIT " . SYNC_EXPORT_MAX_ROWS);
                $expStmt->execute($params);
                while ($r = $expStmt->fetch()) {
                    $expRows[] = [
                        $r['imei'],
                        $r['device_name'],
                        $r['driver_name'],
                        fmt_brt($r['started_at']),
                        $r['start_addr'] ?? '—',
                        $r['ended_at'] ? fmt_brt($r['ended_at']) : '—',
                        $r['end_addr'] ?? '—',
                        fmt_duration((int)($r['duration_s'] ?? 0)),
                        $r['max_speed'] ? number_format((float)$r['max_speed'], 1) : '—',
                        $r['distance_km'] ? number_format((float)$r['distance_km'], 1) : '—',
                        (int)($r['alarm_count'] ?? 0),
                    ];
                }
                stream_export($export, 'relatorio_deslocamento',
                    ['IMEI', 'Dispositivo', 'Motorista', 'Início', 'Local Início', 'Término', 'Local Fim', 'Duração', 'Vel. Máx (km/h)', 'Distância (km)', 'Alarmes'],
                    $expRows, 'Relatório de Deslocamento', "Período (BRT): $dateFrom a $dateTo");
            }
        } catch (Exception $e) { /* tabela trips ausente → export vazio */ }
    }

    try {
        if ($mode === 'diario') {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM (
                SELECT 1 FROM trips t $where
                GROUP BY t.imei, DATE(CONVERT_TZ(t.started_at, '+00:00', '-03:00'))) x");
        } else {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM trips t $where");
        }
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, ceil($totalRows / $perPage));
        $offset = ($page - 1) * $perPage;

        if ($mode === 'diario') {
            $stmt = $db->prepare("$dailySelect $where
                GROUP BY t.imei, dia ORDER BY dia $order, device_name
                LIMIT $perPage OFFSET $offset");
        } else {
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(d.device_name, t.imei) as device_name,
                       COALESCE(dr.name, '—') as driver_name
                FROM trips t
                LEFT JOIN devices d ON d.imei = t.imei
                LEFT JOIN drivers dr ON dr.id = t.driver_id
                $where
                ORDER BY t.$sort $order
                LIMIT $perPage OFFSET $offset
            ");
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        $totalRows = 0; $totalPages = 1; $rows = [];
    }
}

/**
 * Formata segundos como duração legível (ex.: 3h05m).
 *
 * @param int $s Duração em segundos
 * @returns string
 */
function fmt_duration(int $s): string {
    if ($s <= 0) return '—';
    return sprintf('%dh%02dm', floor($s / 3600), floor(($s % 3600) / 60));
}

$devices = $db->prepare("SELECT d.imei, d.device_name FROM devices d WHERE d.customer_id = :cid ORDER BY d.device_name");
$devices->execute([':cid' => $customerId]);
$devices = $devices->fetchAll();

$page_title = 'Relatório de Deslocamento';
$current_route = 'rel_deslocamento';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Deslocamento</h2>
    <?php if ($generated): ?>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?= report_back_button('/relatorios/deslocamento') ?>
    </div>
    <?php endif; ?>
</div>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <input type="hidden" name="gerar" value="1">
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Modalidade</label>
            <select name="mode" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:170px;">
                <option value="viagens" <?= $mode==='viagens'?'selected':'' ?>>Por deslocamento</option>
                <option value="diario" <?= $mode==='diario'?'selected':'' ?>>Fechamento diário</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Ativo</label>
            <select name="imei" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:180px;">
                <option value="">Todos</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>" <?= $selImei===$d['imei']?'selected':'' ?>><?= htmlspecialchars($d['device_name']??$d['imei']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Período (máx. <?= REPORT_RANGE_MAX_DAYS ?> dias)</label>
            <div style="display:flex;gap:4px;">
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
            </div>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Faixa horária (opcional)</label>
            <div style="display:flex;gap:4px;">
                <input type="time" name="time_from" value="<?= htmlspecialchars($timeFrom) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:100px;">
                <input type="time" name="time_to" value="<?= htmlspecialchars($timeTo) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:100px;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
    </form>
</div>

<?php if ($generated && $rangeClamped): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid #f5a623;font-size:13px;color:var(--muted);">
    O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias: <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
</div>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <?php if ($mode === 'diario'): ?>
            <tr>
                <th><?= report_sort_link('dia', 'Dia', $sort, $order) ?></th>
                <th>Dispositivo</th>
                <th>Primeira Ignição</th>
                <th>Última Ign. Deslig.</th>
                <th>Jornada</th>
                <th>Em Movimento</th>
                <th>Distância</th>
                <th>Vel. Máx</th>
                <th>Alarmes</th>
                <th>Viagens</th>
                <th>Rota</th>
            </tr>
            <?php else: ?>
            <tr>
                <th>IMEI</th>
                <th>Dispositivo</th>
                <th>Motorista</th>
                <th><?= report_sort_link('started_at', 'Início', $sort, $order) ?></th>
                <th><?= report_sort_link('ended_at', 'Término', $sort, $order) ?></th>
                <th>Duração</th>
                <th><?= report_sort_link('max_speed', 'Vel. Máx', $sort, $order, 'DESC') ?></th>
                <th><?= report_sort_link('distance_km', 'Distância', $sort, $order, 'DESC') ?></th>
                <th>Alarmes</th>
                <th>Rota</th>
            </tr>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="11" style="text-align:center;padding:32px;color:var(--muted);">
                <?= $generated ? 'Nenhuma viagem encontrada no período.' : 'Selecione os filtros e clique em Gerar.' ?>
            </td></tr>
            <?php elseif ($mode === 'diario'): ?>
            <?php foreach ($rows as $r):
                // Horários do dia em H:i; se a última desligada caiu no dia BRT
                // seguinte (viagem cruzou a meia-noite), mostra a data junto.
                $diaBrt = fmt_brt($r['primeira_on'], 'd/m/Y');
                $offFmt = $r['ultima_off'] && fmt_brt($r['ultima_off'], 'd/m/Y') !== $diaBrt ? 'd/m H:i' : 'H:i';
            ?>
            <tr>
                <td class="text-mono"><?= $diaBrt ?></td>
                <td><?= htmlspecialchars($r['device_name']) ?><br><span class="text-mono" style="font-size:10px;color:var(--muted);"><?= htmlspecialchars($r['imei']) ?></span></td>
                <td class="text-mono"><?= fmt_brt($r['primeira_on'], 'H:i') ?></td>
                <td class="text-mono"><?= $r['ultima_off'] ? fmt_brt($r['ultima_off'], $offFmt) : '—' ?></td>
                <td><?= fmt_duration((int)($r['jornada_s'] ?? 0)) ?></td>
                <td><?= fmt_duration((int)($r['movimento_s'] ?? 0)) ?></td>
                <td><?= $r['distance_km'] ? number_format((float)$r['distance_km'], 1) . ' km' : '—' ?></td>
                <td><?= $r['max_speed'] ? number_format((float)$r['max_speed'], 1) . ' km/h' : '—' ?></td>
                <td><?= (int)($r['alarm_count'] ?? 0) ?></td>
                <td><?= (int)$r['viagens'] ?></td>
                <td><a href="/relatorios/deslocamento/rota?imei=<?= urlencode($r['imei']) ?>&dia=<?= urlencode($r['dia']) ?>" target="_blank" class="btn btn-outline btn-sm">Ver rota</a></td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><span class="text-mono"><?= htmlspecialchars($r['imei']) ?></span></td>
                <td><?= htmlspecialchars($r['device_name']) ?></td>
                <td><?= htmlspecialchars($r['driver_name']) ?></td>
                <td class="text-mono"><?= fmt_brt($r['started_at']) ?><br><span style="font-size:10px;color:var(--muted);"><?= htmlspecialchars(substr($r['start_addr']??'—', 0, 40)) ?></span></td>
                <td class="text-mono"><?= $r['ended_at'] ? fmt_brt($r['ended_at']) : '—' ?><br><span style="font-size:10px;color:var(--muted);"><?= htmlspecialchars(substr($r['end_addr']??'—', 0, 40)) ?></span></td>
                <td><?= fmt_duration((int)($r['duration_s'] ?? 0)) ?></td>
                <td><?= $r['max_speed'] ? number_format((float)$r['max_speed'], 1) . ' km/h' : '—' ?></td>
                <td><?= $r['distance_km'] ? number_format((float)$r['distance_km'], 1) . ' km' : '—' ?></td>
                <td><?= (int)($r['alarm_count'] ?? 0) ?></td>
                <td><a href="/relatorios/deslocamento/rota?trip_id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-outline btn-sm">Ver rota</a></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, $mode === 'diario' ? 'dias' : 'viagens') ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
