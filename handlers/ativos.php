<?php
/**
 * JIMI Webhook System — Lista de Ativos v3.1.0
 * Endpoint: /ativos
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_current_customer_id();
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo');

$db = Database::getInstance()->getConnection();

$devices = $db->query("
    SELECT d.imei, d.device_name, d.device_model, d.last_communication, d.activation_date, d.camera_count,
           s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status, s.is_online,
           COALESCE(dm.model_name, d.device_model, '-') AS model_display,
           COALESCE(dm.protocol, '') AS protocol
    FROM devices d
    LEFT JOIN device_statistics s ON d.imei = s.imei
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    WHERE d.customer_id = $customer_id
    ORDER BY d.last_communication DESC
")->fetchAll(PDO::FETCH_ASSOC);

$page_title    = 'Ativos';
$current_route = 'ativos';
include __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-24">
    <div>
        <span style="font-size:13px;color:var(--muted)"><?= count($devices) ?> dispositivo(s) cadastrado(s)</span>
    </div>
    <a href="/ativos/novo" class="btn btn-primary">+ Novo Dispositivo</a>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Dispositivo</th>
                <th>IMEI</th>
                <th>Modelo</th>
                <th>Câmeras</th>
                <th>Status</th>
                <th>Velocidade</th>
                <th>Última Comunicação</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($devices as $dev):
                $dtLast = $dev['last_communication'] ? new DateTime($dev['last_communication'], $tz_utc) : null;
                $dtNow  = new DateTime('now', $tz_utc);
                $isOnline = $dtLast && ($dtNow->getTimestamp() - $dtLast->getTimestamp()) < 600;
                $hasGps = !empty($dev['last_latitude']) && $dev['last_latitude'] != 0;
            ?>
            <tr>
                <td style="font-weight:500;color:var(--ink)">
                    <a href="/ativos/<?= urlencode($dev['imei']) ?>" style="color:var(--ink);text-decoration:none">
                        <?= htmlspecialchars($dev['device_name'] ?? 'Sem Nome') ?>
                    </a>
                </td>
                <td class="text-mono"><?= htmlspecialchars($dev['imei']) ?></td>
                <td>
                    <?php if ($dev['protocol']): ?>
                    <span class="badge" style="background:<?= $dev['protocol']==='JIMI' ? '#e8f5ef' : '#eef4fa' ?>;color:<?= $dev['protocol']==='JIMI' ? 'var(--success)' : '#5a7fa8' ?>">
                        <?= htmlspecialchars($dev['model_display']) ?>
                    </span>
                    <?php else: ?>
                    <?= htmlspecialchars($dev['model_display']) ?>
                    <?php endif; ?>
                </td>
                <td><?= $dev['camera_count'] ?? 1 ?></td>
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
                <td>
                    <?php
                    if ($dtLast) {
                        $dtLast->setTimezone($tz_brt);
                        echo $dtLast->format('d/m/Y H:i:s');
                    } else { echo '-'; }
                    ?>
                </td>
                <td>
                    <a href="/ativos/<?= urlencode($dev['imei']) ?>" class="btn btn-outline btn-sm">Abrir</a>
                    <?php if ($hasGps): ?>
                    <a href="https://www.google.com/maps?q=<?= $dev['last_latitude'] ?>,<?= $dev['last_longitude'] ?>" target="_blank" class="btn btn-outline btn-sm">Mapa</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($devices)): ?>
            <tr>
                <td colspan="8">
                    <div class="empty-state">
                        <div class="empty-state-icon bi bi-camera-video"></div>
                        <h3>Nenhum dispositivo cadastrado</h3>
                        <p>Cadastre seu primeiro equipamento para começar a monitorar.</p>
                        <a href="/ativos/novo" class="btn btn-primary mt-16">Cadastrar Primeiro Dispositivo</a>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
