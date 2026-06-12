<?php
/**
 * JIMI Webhook System — Painel Principal v3.1.0
 * Endpoint: /dashboard
 *
 * NavTrack-inspired dashboard com KPI cards, lista de ativos,
 * e atividade recente. Contexto do cliente via sessão.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_current_customer_id();
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo');

function fmt_brt($dt, $tz_utc, $tz_brt) {
    if (!$dt) return '-';
    $d = new DateTime($dt, $tz_utc);
    $d->setTimezone($tz_brt);
    return $d->format('d/m/Y H:i:s');
}

$db = Database::getInstance()->getConnection();

// ── KPI: Dispositivos ────────────────────────────────────
$totalDevices  = $db->query("SELECT COUNT(*) FROM devices WHERE customer_id = $customer_id")->fetchColumn();
$onlineDevices = $db->query("
    SELECT COUNT(*) FROM devices d
    JOIN device_statistics s ON d.imei = s.imei
    WHERE d.customer_id = $customer_id AND s.is_online = 1
")->fetchColumn();
$ignitionOn = $db->query("
    SELECT COUNT(*) FROM device_statistics s
    JOIN devices d ON d.imei = s.imei
    WHERE d.customer_id = $customer_id AND s.last_acc_status = 1
")->fetchColumn();

// ── KPI: Alarmes Hoje ───────────────────────────────────
$alarmsToday = $db->query("
    SELECT COUNT(*) FROM alarms a
    JOIN devices d ON a.imei = d.imei
    WHERE d.customer_id = $customer_id
      AND a.created_at >= DATE(NOW())
")->fetchColumn();

// ── KPI: Comandos Hoje ──────────────────────────────────
$cmdsToday = $db->query("
    SELECT COUNT(*) FROM commands c
    JOIN devices d ON c.imei = d.imei
    WHERE d.customer_id = $customer_id
      AND c.created_at >= DATE(NOW())
")->fetchColumn();

// ── Lista de Ativos ─────────────────────────────────────
$devices = $db->query("
    SELECT d.imei, d.device_name, d.device_model, d.last_communication,
           s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status,
           COALESCE(dm.model_name, d.device_model, '-') AS model_display,
           COALESCE(dm.protocol, '') AS protocol
    FROM devices d
    LEFT JOIN device_statistics s ON d.imei = s.imei
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    WHERE d.customer_id = $customer_id
    ORDER BY d.last_communication DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Atividade Recente (últimos 20 alarmes + eventos) ────
$recentActivity = $db->query("
    (SELECT 'alarm' AS tipo, a.id, a.alarm_name AS descricao, a.created_at, a.imei,
            a.latitude, a.longitude, at.severity
     FROM alarms a
     JOIN devices d ON a.imei = d.imei
     LEFT JOIN alarm_types at ON (
        (a.msg_class=1 AND at.protocol='JTT' AND at.alarm_code=IF(a.alarm_subtype IS NOT NULL,
            CONCAT(a.alarm_type, '-', a.alarm_subtype), a.alarm_type))
        OR (a.msg_class=0 AND at.protocol='JIMI' AND at.alarm_code=a.alarm_type)
     )
     WHERE d.customer_id = $customer_id
     ORDER BY a.created_at DESC LIMIT 20
    )
    UNION ALL
    (SELECT 'event' AS tipo, e.id, e.event_type AS descricao, e.event_time AS created_at, e.imei,
            e.latitude, e.longitude, NULL AS severity
     FROM events e
     JOIN devices d ON e.imei = d.imei
     WHERE d.customer_id = $customer_id
     ORDER BY e.event_time DESC LIMIT 20
    )
    ORDER BY created_at DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// ── Layout ──────────────────────────────────────────────
$page_title    = 'Painel';
$current_route = 'dashboard';
include __DIR__ . '/../web/layout_base.php';
?>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-item">
        <div class="kpi-item-label">Dispositivos</div>
        <div class="kpi-item-value"><?= $totalDevices ?></div>
        <div class="kpi-item-delta"><?= $onlineDevices ?> online</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Ignição Ligada</div>
        <div class="kpi-item-value"><?= $ignitionOn ?></div>
        <div class="kpi-item-delta">dispositivos em movimento</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Alarmes Hoje</div>
        <div class="kpi-item-value"><?= $alarmsToday ?></div>
        <div class="kpi-item-delta">últimas 24 horas</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-item-label">Comandos Hoje</div>
        <div class="kpi-item-value"><?= $cmdsToday ?></div>
        <div class="kpi-item-delta">enviados hoje</div>
    </div>
</div>

<!-- Ativos -->
<div class="table-wrap mb-24">
    <table>
        <thead>
            <tr>
                <th>Dispositivo</th>
                <th>IMEI</th>
                <th>Modelo</th>
                <th>Status</th>
                <th>Velocidade</th>
                <th>Última Comunicação</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($devices as $dev):
                $isOnline = false;
                if ($dev['last_communication']) {
                    $dtLast = new DateTime($dev['last_communication'], $tz_utc);
                    $dtNow  = new DateTime('now', $tz_utc);
                    $isOnline = ($dtNow->getTimestamp() - $dtLast->getTimestamp()) < 600;
                }
                $hasGps = !empty($dev['last_latitude']) && $dev['last_latitude'] != 0;
            ?>
            <tr>
                <td style="font-weight:500;color:var(--ink)">
                    <?= htmlspecialchars($dev['device_name'] ?? 'Sem Nome') ?>
                </td>
                <td class="text-mono"><?= htmlspecialchars($dev['imei']) ?></td>
                <td>
                    <?php if ($dev['protocol']): ?>
                    <span class="badge badge-info"><?= htmlspecialchars($dev['model_display']) ?></span>
                    <?php else: ?>
                    <?= htmlspecialchars($dev['model_display']) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isOnline): ?>
                    <span class="badge badge-success">Online</span>
                    <?php else: ?>
                    <span class="badge" style="background:var(--surface-strong);color:var(--muted)">Offline</span>
                    <?php endif; ?>
                    <?php if ($dev['last_acc_status'] == 1): ?>
                    <span class="badge badge-warning" style="margin-left:4px">Ligado</span>
                    <?php endif; ?>
                </td>
                <td><?= round($dev['last_speed'] ?? 0) ?> km/h</td>
                <td><?= fmt_brt($dev['last_communication'], $tz_utc, $tz_brt) ?></td>
                <td>
                    <a href="/ativos/<?= urlencode($dev['imei']) ?>" class="btn btn-outline btn-sm">Detalhes</a>
                    <?php if ($hasGps): ?>
                    <a href="https://www.google.com/maps?q=<?= $dev['last_latitude'] ?>,<?= $dev['last_longitude'] ?>" target="_blank" class="btn btn-outline btn-sm">Mapa</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($devices)): ?>
            <tr>
                <td colspan="7">
                    <div class="empty-state">
                        <div class="empty-state-icon bi bi-camera-video"></div>
                        <h3>Nenhum dispositivo cadastrado</h3>
                        <p>Cadastre seu primeiro equipamento para começar.</p>
                        <a href="/ativos/novo" class="btn btn-primary mt-16">Cadastrar Dispositivo</a>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Atividade Recente -->
<h3 style="font-size:16px;font-weight:600;color:var(--ink);margin-bottom:14px">Atividade Recente</h3>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Tipo</th>
                <th>Descrição</th>
                <th>IMEI</th>
                <th>Data/Hora</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentActivity as $act):
                $severityClass = ($act['severity'] ?? '') === 'critical' ? 'badge-error' :
                    (($act['severity'] ?? '') === 'warning' ? 'badge-warning' : 'badge-success');
            ?>
            <tr>
                <td>
                    <?php if ($act['tipo'] === 'alarm'): ?>
                    <span class="badge <?= $severityClass ?>">Alarme</span>
                    <?php else: ?>
                    <span class="badge badge-info">Evento</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--ink)"><?= htmlspecialchars($act['descricao'] ?? '-') ?></td>
                <td class="text-mono"><?= htmlspecialchars($act['imei']) ?></td>
                <td><?= fmt_brt($act['created_at'], $tz_utc, $tz_brt) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentActivity)): ?>
            <tr>
                <td colspan="4">
                    <div class="empty-state">
                        <p>Nenhuma atividade registrada para este cliente.</p>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
