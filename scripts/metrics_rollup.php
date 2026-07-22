<?php
/**
 * JIMI Webhook System — Metrics Rollup v4.0.0
 * Script: scripts/metrics_rollup.php
 *
 * Pré-computa KPIs do Resumo/BI por cliente a cada 5 min.
 * Uso: php scripts/metrics_rollup.php
 *
 * Métricas computadas:
 *   devices_total, devices_active, devices_online, devices_offline
 *   occurrences_total, occurrences_waiting
 *   alarms_today, alarms_yesterday, alarms_7d, alarms_30d
 *   ocurrences_today, ocurrences_yesterday, ocurrences_7d, ocurrences_30d
 *   speed_parados, speed_ate20, speed_ate60, speed_acima60
 *   outdated_lt7d, outdated_gt7d, outdated_gt30d, outdated_never
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance()->getConnection();
$snapTime = date('Y-m-d H:i:s');

$customers = $db->query("SELECT id FROM customers WHERE is_active = 1")->fetchAll();

// Fronteiras de dia em BRT (banco em UTC)
[$todayUtc, ]     = brt_day_range_to_utc(brt_today(), brt_today());
[$yesterdayUtc, ] = brt_day_range_to_utc(brt_today('Y-m-d', '-1 day'), brt_today('Y-m-d', '-1 day'));
[$d7Utc, ]        = brt_day_range_to_utc(brt_today('Y-m-d', '-7 days'), brt_today('Y-m-d', '-7 days'));
[$d30Utc, ]       = brt_day_range_to_utc(brt_today('Y-m-d', '-30 days'), brt_today('Y-m-d', '-30 days'));

foreach ($customers as $cust) {
    $cid = (int)$cust['id'];
    $metrics = [];

    // Devices
    $dev = $db->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
               SUM(CASE WHEN is_active = 1 AND TIMESTAMPDIFF(MINUTE, last_communication, NOW()) <= 5 THEN 1 ELSE 0 END) as online,
               SUM(CASE WHEN is_active = 1 AND TIMESTAMPDIFF(MINUTE, last_communication, NOW()) > 5 THEN 1 ELSE 0 END) as offline
        FROM devices WHERE customer_id = :cid
    ");
    $dev->execute([':cid' => $cid]);
    $dev = $dev->fetch();
    $metrics['devices_total']   = $dev['total'] ?? 0;
    $metrics['devices_active']  = $dev['active'] ?? 0;
    $metrics['devices_online']  = $dev['online'] ?? 0;
    $metrics['devices_offline'] = $dev['offline'] ?? 0;

    // Occurrences
    $occ = $db->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'aguardando' THEN 1 ELSE 0 END) as waiting
        FROM occurrences WHERE customer_id = :cid
    ");
    $occ->execute([':cid' => $cid]);
    $occ = $occ->fetch();
    $metrics['occurrences_total']   = $occ['total'] ?? 0;
    $metrics['occurrences_waiting'] = $occ['waiting'] ?? 0;

    // Alarms: today, yesterday, 7d, 30d
    $alarms = $db->prepare("
        SELECT
            SUM(CASE WHEN alarm_time >= :t0 THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN alarm_time >= :y0 AND alarm_time < :t0b THEN 1 ELSE 0 END) as yesterday,
            SUM(CASE WHEN alarm_time >= :d7 THEN 1 ELSE 0 END) as d7,
            SUM(CASE WHEN alarm_time >= :d30 THEN 1 ELSE 0 END) as d30
        FROM alarms a
        JOIN devices d ON d.imei = a.imei AND d.customer_id = :cid
    ");
    $alarms->execute([':cid' => $cid, ':t0' => $todayUtc, ':t0b' => $todayUtc, ':y0' => $yesterdayUtc, ':d7' => $d7Utc, ':d30' => $d30Utc]);
    $alarms = $alarms->fetch();
    $metrics['alarms_today']     = $alarms['today'] ?? 0;
    $metrics['alarms_yesterday'] = $alarms['yesterday'] ?? 0;
    $metrics['alarms_7d']        = $alarms['d7'] ?? 0;
    $metrics['alarms_30d']       = $alarms['d30'] ?? 0;

    // Ocurrences: today, yesterday, 7d, 30d
    $o = $db->prepare("
        SELECT
            SUM(CASE WHEN first_alarm_at >= :t0 THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN first_alarm_at >= :y0 AND first_alarm_at < :t0b THEN 1 ELSE 0 END) as yesterday,
            SUM(CASE WHEN first_alarm_at >= :d7 THEN 1 ELSE 0 END) as d7,
            SUM(CASE WHEN first_alarm_at >= :d30 THEN 1 ELSE 0 END) as d30
        FROM occurrences WHERE customer_id = :cid
    ");
    $o->execute([':cid' => $cid, ':t0' => $todayUtc, ':t0b' => $todayUtc, ':y0' => $yesterdayUtc, ':d7' => $d7Utc, ':d30' => $d30Utc]);
    $o = $o->fetch();
    $metrics['occurrences_today']     = $o['today'] ?? 0;
    $metrics['occurrences_yesterday'] = $o['yesterday'] ?? 0;
    $metrics['occurrences_7d']        = $o['d7'] ?? 0;
    $metrics['occurrences_30d']       = $o['d30'] ?? 0;

    // Speed distribution (last 30 min, ignition=1)
    $spd = $db->prepare("
        SELECT
            SUM(CASE WHEN speed = 0 THEN 1 ELSE 0 END) as parados,
            SUM(CASE WHEN speed > 0 AND speed <= 20 THEN 1 ELSE 0 END) as ate20,
            SUM(CASE WHEN speed > 20 AND speed <= 60 THEN 1 ELSE 0 END) as ate60,
            SUM(CASE WHEN speed > 60 THEN 1 ELSE 0 END) as acima60
        FROM gps_data g
        JOIN devices d ON d.imei = g.imei AND d.customer_id = :cid
        WHERE g.gps_time >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
          AND g.acc = 1
    ");
    $spd->execute([':cid' => $cid]);
    $spd = $spd->fetch();
    $metrics['speed_parados']   = $spd['parados'] ?? 0;
    $metrics['speed_ate20']     = $spd['ate20'] ?? 0;
    $metrics['speed_ate60']     = $spd['ate60'] ?? 0;
    $metrics['speed_acima60']   = $spd['acima60'] ?? 0;

    // Outdated — última posição via device_statistics (devices.last_position_at não existe)
    $out = $db->prepare("
        SELECT
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, ds.last_gps_time, NOW()) BETWEEN 0 AND 6 THEN 1 ELSE 0 END) as lt7d,
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, ds.last_gps_time, NOW()) BETWEEN 7 AND 29 THEN 1 ELSE 0 END) as gt7d,
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, ds.last_gps_time, NOW()) >= 30 THEN 1 ELSE 0 END) as gt30d,
            SUM(CASE WHEN ds.last_gps_time IS NULL THEN 1 ELSE 0 END) as never
        FROM devices d
        LEFT JOIN device_statistics ds ON ds.imei = d.imei
        WHERE d.customer_id = :cid AND d.is_active = 1
    ");
    $out->execute([':cid' => $cid]);
    $out = $out->fetch();
    $metrics['outdated_lt7d']  = $out['lt7d'] ?? 0;
    $metrics['outdated_gt7d']  = $out['gt7d'] ?? 0;
    $metrics['outdated_gt30d'] = $out['gt30d'] ?? 0;
    $metrics['outdated_never'] = $out['never'] ?? 0;

    // Delete old snapshots for this customer (keep last 24h)
    $db->prepare("DELETE FROM metrics_snapshots WHERE customer_id = :cid AND snapshot_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)")
       ->execute([':cid' => $cid]);

    // Insert new snapshots
    $insert = $db->prepare("INSERT INTO metrics_snapshots (customer_id, metric_key, metric_value, snapshot_at) VALUES (:cid, :key, :val, :snap)");
    foreach ($metrics as $key => $val) {
        $insert->execute([':cid' => $cid, ':key' => $key, ':val' => $val, ':snap' => $snapTime]);
    }
}

echo 'Metrics Rollup: ' . count($customers) . " customers processed at {$snapTime}.\n";
