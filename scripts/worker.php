<?php
/**
 * JIMI Webhook System — Worker de Jobs v4.0.0
 * Script: scripts/worker.php
 *
 * Cron (cada 1 min): processa fila de jobs pendentes.
 * Tipos: report (CSV real), video_download, rollup.
 *
 * Uso: php scripts/worker.php
 */

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM jobs WHERE status = 'pendente' ORDER BY created_at ASC LIMIT 5");
$stmt->execute();
$jobs = $stmt->fetchAll();

foreach ($jobs as $job) {
    try {
        $db->prepare("UPDATE jobs SET status = 'processando' WHERE id = :id")
           ->execute([':id' => $job['id']]);

        switch ($job['type']) {
            case 'report':
                $result = processReportJob($db, $job);
                break;
            case 'video_download':
                $result = processVideoJob($db, $job);
                break;
            case 'rollup':
                $result = processRollupJob($db, $job);
                break;
            default:
                $result = ['status' => 'falhou', 'error' => 'Tipo desconhecido'];
        }

        if ($result['status'] === 'concluido') {
            $db->prepare("UPDATE jobs SET status = 'concluido', result_path = :path, updated_at = NOW() WHERE id = :id")
               ->execute([':path' => $result['path'] ?? null, ':id' => $job['id']]);
        } else {
            $db->prepare("UPDATE jobs SET status = 'falhou', error_message = :err, updated_at = NOW() WHERE id = :id")
               ->execute([':err' => $result['error'] ?? 'Erro desconhecido', ':id' => $job['id']]);
        }
    } catch (Exception $e) {
        $db->prepare("UPDATE jobs SET status = 'falhou', error_message = :err, updated_at = NOW() WHERE id = :id")
           ->execute([':err' => $e->getMessage(), ':id' => $job['id']]);
    }
}

echo 'Worker executado: ' . count($jobs) . " jobs processados.\n";

function processReportJob($db, $job): array {
    $params = json_decode($job['params'] ?? '{}', true);
    $reportType = $params['report_type'] ?? 'alarms';
    $dateFrom   = ($params['date_from'] ?? date('Y-m-d', strtotime('-30 days'))) . ' 00:00:00';
    $dateTo     = ($params['date_to'] ?? date('Y-m-d')) . ' 23:59:59';
    $cid        = $job['customer_id'];
    $reportName = $params['report_name'] ?? 'Relatório';

    $dir = __DIR__ . '/../storage/reports';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'report_' . $job['id'] . '_' . date('Ymd_His') . '.csv';
    $filepath = $dir . '/' . $filename;
    $fp = fopen($filepath, 'w');
    if (!$fp) return ['status' => 'falhou', 'error' => 'Não foi possível criar o arquivo'];

    fwrite($fp, "\xEF\xBB\xBF"); // BOM for Excel UTF-8

    switch ($reportType) {
        case 'alarms':
            generateAlarmsCSV($db, $fp, $cid, $dateFrom, $dateTo);
            break;
        case 'occurrences':
            generateOccurrencesCSV($db, $fp, $cid, $dateFrom, $dateTo);
            break;
        case 'positions':
            generatePositionsCSV($db, $fp, $cid, $dateFrom, $dateTo);
            break;
        case 'trips':
            generateTripsCSV($db, $fp, $cid, $dateFrom, $dateTo);
            break;
        case 'devices':
            generateDevicesCSV($db, $fp, $cid);
            break;
        default:
            fputcsv($fp, ['Tipo de relatório não reconhecido']);
    }

    fclose($fp);

    return ['status' => 'concluido', 'path' => 'storage/reports/' . $filename];
}

function generateAlarmsCSV($db, $fp, $cid, $from, $to): void {
    fputcsv($fp, ['IMEI', 'Dispositivo', 'Tipo Alarme', 'Data/Hora', 'Latitude', 'Longitude', 'Velocidade (km/h)']);
    $stmt = $db->prepare("
        SELECT a.imei, COALESCE(d.device_name, a.imei) as device_name, a.alarm_type,
               a.alarm_time, a.latitude, a.longitude, a.speed
        FROM alarms a
        JOIN devices d ON d.imei = a.imei AND d.customer_id = :cid
        WHERE a.alarm_time BETWEEN :df AND :dt
        ORDER BY a.alarm_time DESC
    ");
    $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
    while ($row = $stmt->fetch()) {
        fputcsv($fp, [
            $row['imei'], $row['device_name'], $row['alarm_type'],
            $row['alarm_time'], $row['latitude'], $row['longitude'], $row['speed']
        ]);
    }
}

function generateOccurrencesCSV($db, $fp, $cid, $from, $to): void {
    fputcsv($fp, ['ID', 'IMEI', 'Tipo Alarme', 'Risco', 'Status', 'Qtd Alarmes', 'Primeiro Alarme', 'Último Alarme', 'Tratado por', 'Notas']);
    $stmt = $db->prepare("
        SELECT o.id, o.imei, o.alarm_type, o.risk, o.status, o.alarm_count,
               o.first_alarm_at, o.last_alarm_at, u.name as treated_by, o.treatment_notes
        FROM occurrences o
        LEFT JOIN users u ON u.id = o.treated_by
        WHERE o.customer_id = :cid AND o.first_alarm_at BETWEEN :df AND :dt
        ORDER BY o.first_alarm_at DESC
    ");
    $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
    while ($row = $stmt->fetch()) {
        fputcsv($fp, [
            $row['id'], $row['imei'], $row['alarm_type'], $row['risk'], $row['status'],
            $row['alarm_count'], $row['first_alarm_at'], $row['last_alarm_at'],
            $row['treated_by'] ?? '—', $row['treatment_notes'] ?? ''
        ]);
    }
}

function generatePositionsCSV($db, $fp, $cid, $from, $to): void {
    fputcsv($fp, ['IMEI', 'Dispositivo', 'Data/Hora', 'Latitude', 'Longitude', 'Velocidade (km/h)', 'Ignição', 'Bateria (%)']);
    $stmt = $db->prepare("
        SELECT g.imei, COALESCE(d.device_name, g.imei) as device_name,
               g.gps_time, g.latitude, g.longitude, g.speed, g.ignition, g.battery
        FROM gps_data g
        JOIN devices d ON d.imei = g.imei AND d.customer_id = :cid
        WHERE g.gps_time BETWEEN :df AND :dt AND ABS(g.latitude) > 0.0001 AND ABS(g.longitude) > 0.0001
        ORDER BY g.gps_time DESC
    ");
    $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
    while ($row = $stmt->fetch()) {
        fputcsv($fp, [
            $row['imei'], $row['device_name'], $row['gps_time'],
            $row['latitude'], $row['longitude'], $row['speed'],
            $row['ignition'], $row['battery']
        ]);
    }
}

function generateTripsCSV($db, $fp, $cid, $from, $to): void {
    fputcsv($fp, ['ID', 'IMEI', 'Início', 'Fim', 'Duração', 'Distância (km)', 'Vel. Máx (km/h)', 'Qtd Alarmes', 'Origem', 'Destino']);
    $stmt = $db->prepare("
        SELECT id, imei, started_at, ended_at, duration_s, distance_km, max_speed, alarm_count, start_addr, end_addr
        FROM trips
        WHERE customer_id = :cid AND started_at BETWEEN :df AND :dt
        ORDER BY started_at DESC
    ");
    $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
    while ($row = $stmt->fetch()) {
        $duration = $row['duration_s'] ? gmdate('H:i:s', $row['duration_s']) : '';
        fputcsv($fp, [
            $row['id'], $row['imei'], $row['started_at'], $row['ended_at'] ?? '',
            $duration, $row['distance_km'] ?? '', $row['max_speed'] ?? '',
            $row['alarm_count'], $row['start_addr'] ?? '', $row['end_addr'] ?? ''
        ]);
    }
}

function generateDevicesCSV($db, $fp, $cid): void {
    fputcsv($fp, ['IMEI', 'Nome', 'Modelo', 'Ativo', 'Última Comunicação', 'Última Posição', 'Câmeras', 'Firmware']);
    $stmt = $db->prepare("
        SELECT d.imei, d.device_name, dm.model_name, d.is_active, d.last_communication,
               d.last_position_at, d.camera_count, d.firmware_version
        FROM devices d
        LEFT JOIN device_models dm ON dm.id = d.device_model_id
        WHERE d.customer_id = :cid
        ORDER BY d.device_name
    ");
    $stmt->execute([':cid' => $cid]);
    while ($row = $stmt->fetch()) {
        fputcsv($fp, [
            $row['imei'], $row['device_name'] ?? $row['imei'], $row['model_name'] ?? '',
            $row['is_active'] ? 'Sim' : 'Não', $row['last_communication'] ?? '',
            $row['last_position_at'] ?? '', $row['camera_count'] ?? 0, $row['firmware_version'] ?? ''
        ]);
    }
}

function processVideoJob($db, $job): array {
    $params = json_decode($job['params'] ?? '{}', true);
    $dir = __DIR__ . '/../storage/media';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => ($params['url'] ?? ''),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $data = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['status' => 'falhou', 'error' => $err];

    $filename = 'video_' . $job['id'] . '_' . date('Ymd_His') . '.mp4';
    file_put_contents($dir . '/' . $filename, $data);

    return ['status' => 'concluido', 'path' => 'storage/media/' . $filename];
}

function processRollupJob($db, $job): array {
    return ['status' => 'concluido', 'path' => null, 'error' => null];
}
