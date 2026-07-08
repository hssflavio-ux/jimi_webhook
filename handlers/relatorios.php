<?php
/**
 * JIMI Webhook System — Relatórios v3.1.0
 * Endpoint: /relatorios
 *
 * Hub de relatórios cross-device: Alarmes, Trajetos, Comandos.
 * Substitui a antiga aba dedicada de Alarmes.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_customer_id();
$db = Database::getInstance()->getConnection();
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo');

function fmt_brt_rpt($dt) {
    global $tz_utc, $tz_brt;
    if (!$dt) return '-';
    $d = new DateTime($dt, $tz_utc);
    $d->setTimezone($tz_brt);
    return $d->format('d/m/Y H:i:s');
}

$reportType  = $_GET['tipo'] ?? 'alarmes';
$dateFrom    = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo      = $_GET['to'] ?? date('Y-m-d');
$imeiFilter  = $_GET['imei'] ?? '';
$alarmSev    = $_GET['severity'] ?? '';
$alarmCat    = $_GET['category'] ?? '';

// Tipos de alarme para filtro
$alarmCategories = $db->query("SELECT DISTINCT category FROM alarm_types WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$alarmSeverities = ['critical', 'warning', 'info'];

// Dispositivos do cliente para dropdown
$devStmt = $db->prepare("SELECT imei, device_name FROM devices WHERE customer_id = :cid ORDER BY device_name");
$devStmt->execute([':cid' => $customer_id]);
$devices = $devStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Dados por tipo de relatório ─────────────────────────
$rows = [];
$total = 0;
$columns = [];

if ($reportType === 'alarmes') {
    $where = "d.customer_id = :cid";
    $params = [':cid' => $customer_id, ':df' => $dateFrom . ' 00:00:00', ':dt' => $dateTo . ' 23:59:59'];
    $where .= " AND a.created_at >= :df AND a.created_at <= :dt";
    if ($imeiFilter) { $where .= " AND a.imei = :imei"; $params[':imei'] = $imeiFilter; }
    if ($alarmSev) { $where .= " AND COALESCE(at.severity,'info') = :sev"; $params[':sev'] = $alarmSev; }
    if ($alarmCat) { $where .= " AND at.category = :cat"; $params[':cat'] = $alarmCat; }

    $stmt = $db->prepare("
        SELECT a.id, a.imei, a.alarm_name, a.alarm_time, a.created_at, a.msg_class,
               a.alarm_label, a.latitude, a.longitude, a.speed, a.file_url,
               COALESCE(at.severity, 'info') AS severity,
               d.device_name
        FROM alarms a
        JOIN devices d ON a.imei = d.imei
        LEFT JOIN alarm_types at ON (
            (a.msg_class=1 AND at.protocol='JTT' AND at.alarm_code=IF(a.alarm_subtype IS NOT NULL,
                CONCAT(a.alarm_type, '-', a.alarm_subtype), a.alarm_type))
            OR (a.msg_class=0 AND at.protocol='JIMI' AND at.alarm_code=a.alarm_type)
        )
        WHERE $where
        ORDER BY a.created_at DESC LIMIT 200
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($rows);
    $columns = ['Data/Hora', 'Dispositivo', 'Alarme', 'Protocolo', 'Severidade', 'Velocidade', 'Mapa'];

} elseif ($reportType === 'trajetos') {
    $where = "d.customer_id = :cid";
    $params = [':cid' => $customer_id, ':df' => $dateFrom . ' 00:00:00', ':dt' => $dateTo . ' 23:59:59'];
    $where .= " AND g.gps_time >= :df AND g.gps_time <= :dt";
    if ($imeiFilter) { $where .= " AND g.imei = :imei"; $params[':imei'] = $imeiFilter; }

    $stmt = $db->prepare("
        SELECT g.gps_time, g.imei, g.latitude, g.longitude, g.speed, g.direction, g.altitude, g.satellites,
               d.device_name
        FROM gps_data g
        JOIN devices d ON g.imei = d.imei
        WHERE $where
        ORDER BY g.gps_time DESC LIMIT 200
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($rows);
    $columns = ['Data/Hora', 'Dispositivo', 'Latitude', 'Longitude', 'Velocidade', 'Direção', 'Mapa'];

} elseif ($reportType === 'comandos') {
    $where = "d.customer_id = :cid";
    $params = [':cid' => $customer_id, ':df' => $dateFrom . ' 00:00:00', ':dt' => $dateTo . ' 23:59:59'];
    $where .= " AND c.created_at >= :df AND c.created_at <= :dt";
    if ($imeiFilter) { $where .= " AND c.imei = :imei"; $params[':imei'] = $imeiFilter; }

    $stmt = $db->prepare("
        SELECT c.id, c.imei, c.command_content, c.command_type, c.status,
               c.response_payload, c.created_at, d.device_name
        FROM commands c
        JOIN devices d ON c.imei = d.imei
        WHERE $where
        ORDER BY c.created_at DESC LIMIT 200
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($rows);
    $columns = ['Data/Hora', 'Dispositivo', 'Comando', 'Status', 'Resposta'];
}

$page_title    = 'Relatórios';
$current_route = 'relatorios';
include __DIR__ . '/../web/layout_base.php';
?>

<!-- Seletor de Tipo de Relatório -->
<div class="flex-between mb-24">
    <div class="flex flex-gap">
        <a href="?tipo=alarmes<?= $imeiFilter ? '&imei='.urlencode($imeiFilter) : '' ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn <?= $reportType === 'alarmes' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Alarmes</a>
        <a href="?tipo=trajetos<?= $imeiFilter ? '&imei='.urlencode($imeiFilter) : '' ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn <?= $reportType === 'trajetos' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Trajetos</a>
        <a href="?tipo=comandos<?= $imeiFilter ? '&imei='.urlencode($imeiFilter) : '' ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" class="btn <?= $reportType === 'comandos' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Comandos</a>
    </div>
    <div style="font-size:12px;color:var(--muted)"><?= $total ?> registro(s)</div>
</div>

<!-- Filtros -->
<div class="card mb-24">
    <form method="get" class="flex flex-gap" style="flex-wrap:wrap;align-items:end">
        <input type="hidden" name="tipo" value="<?= $reportType ?>">
        <div>
            <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:4px">Dispositivo</label>
            <select name="imei" style="padding:6px 10px;font-size:13px;font-family:'Inter',sans-serif;border:1px solid var(--hairline);border-radius:var(--radius-sm);background:var(--surface);min-width:180px">
                <option value="">Todos os dispositivos</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>" <?= $imeiFilter === $d['imei'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:4px">De</label>
            <input type="date" name="from" value="<?= $dateFrom ?>" style="padding:6px 10px;font-size:13px;font-family:'Inter',sans-serif;border:1px solid var(--hairline);border-radius:var(--radius-sm);background:var(--surface)">
        </div>
        <div>
            <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:4px">Até</label>
            <input type="date" name="to" value="<?= $dateTo ?>" style="padding:6px 10px;font-size:13px;font-family:'Inter',sans-serif;border:1px solid var(--hairline);border-radius:var(--radius-sm);background:var(--surface)">
        </div>
        <?php if ($reportType === 'alarmes'): ?>
        <div>
            <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:4px">Severidade</label>
            <select name="severity" style="padding:6px 10px;font-size:13px;font-family:'Inter',sans-serif;border:1px solid var(--hairline);border-radius:var(--radius-sm);background:var(--surface)">
                <option value="">Todas</option>
                <?php foreach ($alarmSeverities as $s): ?>
                <option value="<?= $s ?>" <?= $alarmSev===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:4px">Categoria</label>
            <select name="category" style="padding:6px 10px;font-size:13px;font-family:'Inter',sans-serif;border:1px solid var(--hairline);border-radius:var(--radius-sm);background:var(--surface);max-width:160px">
                <option value="">Todas</option>
                <?php foreach ($alarmCategories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $alarmCat===$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-outline btn-sm" style="height:34px">Filtrar</button>
    </form>
</div>

<!-- Tabela de Resultados -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <?php foreach ($columns as $col): ?>
                <th><?= $col ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <?php if ($reportType === 'alarmes'): ?>
                <td><?= fmt_brt_rpt($r['created_at']) ?></td>
                <td>
                    <a href="/ativos/<?= urlencode($r['imei']) ?>" style="color:var(--ink);text-decoration:none;font-weight:500">
                        <?= htmlspecialchars($r['device_name'] ?? $r['imei']) ?>
                    </a>
                </td>
                <td><?= htmlspecialchars($r['alarm_name'] ?? 'Desconhecido') ?></td>
                <td><span class="badge" style="background:<?= $r['msg_class']==1 ? '#eef4fa' : '#e8f5ef' ?>;color:<?= $r['msg_class']==1 ? '#5a7fa8' : 'var(--success)' ?>"><?= $r['msg_class']==1 ? 'JT/T' : 'JIMI' ?></span></td>
                <td><span class="badge" style="background:<?= ($r['severity']==='critical'?'#cf2d56':($r['severity']==='warning'?'#c08532':'#9fbbe0')) ?>15;color:<?= ($r['severity']==='critical'?'#cf2d56':($r['severity']==='warning'?'#c08532':'#5a7fa8')) ?>"><?= $r['severity'] ?></span></td>
                <td><?= round($r['speed'] ?? 0) ?> km/h</td>
                <td>
                    <?php if (!empty($r['latitude']) && $r['latitude'] != 0): ?>
                    <a href="https://www.google.com/maps?q=<?= $r['latitude'] ?>,<?= $r['longitude'] ?>" target="_blank" class="btn btn-outline btn-sm">Mapa</a>
                    <?php endif; ?>
                </td>

                <?php elseif ($reportType === 'trajetos'): ?>
                <td><?= fmt_brt_rpt($r['gps_time']) ?></td>
                <td>
                    <a href="/ativos/<?= urlencode($r['imei']) ?>" style="color:var(--ink);text-decoration:none;font-weight:500">
                        <?= htmlspecialchars($r['device_name'] ?? $r['imei']) ?>
                    </a>
                </td>
                <td class="text-mono"><?= number_format($r['latitude'], 6) ?></td>
                <td class="text-mono"><?= number_format($r['longitude'], 6) ?></td>
                <td><?= round($r['speed'] ?? 0) ?> km/h</td>
                <td><?= $r['direction'] ?? '-' ?>°</td>
                <td>
                    <a href="https://www.google.com/maps?q=<?= $r['latitude'] ?>,<?= $r['longitude'] ?>" target="_blank" class="btn btn-outline btn-sm">Mapa</a>
                </td>

                <?php elseif ($reportType === 'comandos'): ?>
                <td><?= fmt_brt_rpt($r['created_at']) ?></td>
                <td>
                    <a href="/ativos/<?= urlencode($r['imei']) ?>" style="color:var(--ink);text-decoration:none;font-weight:500">
                        <?= htmlspecialchars($r['device_name'] ?? $r['imei']) ?>
                    </a>
                </td>
                <td class="text-mono" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px"><?= htmlspecialchars($r['command_content']) ?></td>
                <td>
                    <?php
                    $cs = $r['status'];
                    $cb = $cs === 'executed' ? 'badge-success' : ($cs === 'failed' ? 'badge-error' : ($cs === 'sent' ? 'badge-info' : ''));
                    ?>
                    <span class="badge <?= $cb ?>"><?= $cs ?></span>
                </td>
                <td class="text-mono" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px">
                    <?php
                    if ($r['response_payload']) {
                        $resp = json_decode($r['response_payload'], true);
                        if (is_array($resp)) {
                            echo htmlspecialchars($resp['resultContent'] ?? $resp['content'] ?? $resp['msg'] ?? $r['response_payload']);
                        } else {
                            echo htmlspecialchars($r['response_payload']);
                        }
                    } else {
                        echo '-';
                    }
                    ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr>
                <td colspan="<?= count($columns) ?>">
                    <div class="empty-state">
                        <p>Nenhum registro encontrado para os filtros selecionados.</p>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
