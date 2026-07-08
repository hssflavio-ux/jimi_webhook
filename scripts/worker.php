<?php
/**
 * JIMI Webhook System — Worker de Jobs v4.1.0
 * Script: scripts/worker.php
 *
 * Cron (cada 1 min): processa fila de jobs pendentes.
 * Tipos: report (CSV/XLSX/PDF), video_download, rollup.
 *
 * Uso: php scripts/worker.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/export_helper.php';

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

    // Formato: coluna jobs.format (v4.1.0) com fallback para params (pré-migração)
    $format = $job['format'] ?? $params['format'] ?? 'csv';
    if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) $format = 'csv';

    $dir = __DIR__ . '/../storage/reports';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $source = buildReportSource($db, $reportType, $cid, $dateFrom, $dateTo);
    if (!$source) return ['status' => 'falhou', 'error' => 'Tipo de relatório não reconhecido'];
    [$headers, $stmt, $mapper] = $source;

    $filename = 'report_' . $job['id'] . '_' . date('Ymd_His') . '.' . $format;
    $filepath = $dir . '/' . $filename;

    switch ($format) {
        case 'xlsx':
            $writer = new XlsxWriter($filepath);
            $writer->writeHeader($headers);
            while ($row = $stmt->fetch()) {
                $writer->writeRow($mapper($row));
            }
            if (!$writer->close()) return ['status' => 'falhou', 'error' => 'Falha ao gerar XLSX'];
            break;

        case 'pdf':
            $subtitle = 'Período: ' . substr($dateFrom, 0, 10) . ' a ' . substr($dateTo, 0, 10)
                      . ' — gerado em ' . date('d/m/Y H:i') . ' UTC';
            $writer = new PdfWriter($filepath, $reportName, $headers, $subtitle);
            while ($row = $stmt->fetch()) {
                $writer->writeRow($mapper($row));
                if ($writer->isFull()) break;
            }
            if (!$writer->close()) return ['status' => 'falhou', 'error' => 'Falha ao gerar PDF'];
            break;

        default: // csv — UTF-8 BOM + ';' (Excel pt-BR abre em colunas)
            $fp = fopen($filepath, 'w');
            if (!$fp) return ['status' => 'falhou', 'error' => 'Não foi possível criar o arquivo'];
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, $headers, ';');
            while ($row = $stmt->fetch()) {
                fputcsv($fp, $mapper($row), ';');
            }
            fclose($fp);
    }

    return ['status' => 'concluido', 'path' => 'storage/reports/' . $filename];
}

/**
 * Fonte de dados de cada tipo de relatório.
 *
 * @param PDO    $db
 * @param string $type alarms|occurrences|positions|trips|devices
 * @param mixed  $cid  customer_id do job
 * @param string $from Data inicial (Y-m-d H:i:s)
 * @param string $to   Data final (Y-m-d H:i:s)
 * @returns array|null [headers, PDOStatement executado, fn(row): array] ou null se tipo desconhecido
 */
function buildReportSource($db, string $type, $cid, string $from, string $to): ?array {
    switch ($type) {
        case 'alarms':
            $stmt = $db->prepare("
                SELECT a.imei, COALESCE(d.device_name, a.imei) as device_name, a.alarm_type,
                       a.alarm_time, a.latitude, a.longitude, a.speed
                FROM alarms a
                JOIN devices d ON d.imei = a.imei AND d.customer_id = :cid
                WHERE a.alarm_time BETWEEN :df AND :dt
                ORDER BY a.alarm_time DESC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            return [
                ['IMEI', 'Dispositivo', 'Tipo Alarme', 'Data/Hora', 'Latitude', 'Longitude', 'Velocidade (km/h)'],
                $stmt,
                fn($r) => [$r['imei'], $r['device_name'], $r['alarm_type'], $r['alarm_time'], $r['latitude'], $r['longitude'], $r['speed']],
            ];

        case 'occurrences':
            $stmt = $db->prepare("
                SELECT o.id, o.imei, o.alarm_type, o.risk, o.status, o.alarm_count,
                       o.first_alarm_at, o.last_alarm_at, u.name as treated_by, o.treatment_notes
                FROM occurrences o
                LEFT JOIN users u ON u.id = o.treated_by
                WHERE o.customer_id = :cid AND o.first_alarm_at BETWEEN :df AND :dt
                ORDER BY o.first_alarm_at DESC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            return [
                ['ID', 'IMEI', 'Tipo Alarme', 'Risco', 'Status', 'Qtd Alarmes', 'Primeiro Alarme', 'Último Alarme', 'Tratado por', 'Notas'],
                $stmt,
                fn($r) => [$r['id'], $r['imei'], $r['alarm_type'], $r['risk'], $r['status'], $r['alarm_count'],
                           $r['first_alarm_at'], $r['last_alarm_at'], $r['treated_by'] ?? '—', $r['treatment_notes'] ?? ''],
            ];

        case 'positions':
            $stmt = $db->prepare("
                SELECT g.imei, COALESCE(d.device_name, g.imei) as device_name,
                       g.gps_time, g.latitude, g.longitude, g.speed, g.ignition, g.battery
                FROM gps_data g
                JOIN devices d ON d.imei = g.imei AND d.customer_id = :cid
                WHERE g.gps_time BETWEEN :df AND :dt AND ABS(g.latitude) > 0.0001 AND ABS(g.longitude) > 0.0001
                ORDER BY g.gps_time DESC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            return [
                ['IMEI', 'Dispositivo', 'Data/Hora', 'Latitude', 'Longitude', 'Velocidade (km/h)', 'Ignição', 'Bateria (%)'],
                $stmt,
                fn($r) => [$r['imei'], $r['device_name'], $r['gps_time'], $r['latitude'], $r['longitude'],
                           $r['speed'], $r['ignition'], $r['battery']],
            ];

        case 'trips':
            $stmt = $db->prepare("
                SELECT id, imei, started_at, ended_at, duration_s, distance_km, max_speed, alarm_count, start_addr, end_addr
                FROM trips
                WHERE customer_id = :cid AND started_at BETWEEN :df AND :dt
                ORDER BY started_at DESC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            return [
                ['ID', 'IMEI', 'Início', 'Fim', 'Duração', 'Distância (km)', 'Vel. Máx (km/h)', 'Qtd Alarmes', 'Origem', 'Destino'],
                $stmt,
                function($r) {
                    $duration = $r['duration_s'] ? gmdate('H:i:s', $r['duration_s']) : '';
                    return [$r['id'], $r['imei'], $r['started_at'], $r['ended_at'] ?? '', $duration,
                            $r['distance_km'] ?? '', $r['max_speed'] ?? '', $r['alarm_count'],
                            $r['start_addr'] ?? '', $r['end_addr'] ?? ''];
                },
            ];

        case 'devices':
            // Última posição via device_statistics (devices.last_position_at não existe)
            $stmt = $db->prepare("
                SELECT d.imei, d.device_name, dm.model_name, d.is_active, d.last_communication,
                       ds.last_gps_time AS last_position_at, d.camera_count, d.firmware_version
                FROM devices d
                LEFT JOIN device_models dm ON dm.id = d.device_model_id
                LEFT JOIN device_statistics ds ON ds.imei = d.imei
                WHERE d.customer_id = :cid
                ORDER BY d.device_name
            ");
            $stmt->execute([':cid' => $cid]);
            return [
                ['IMEI', 'Nome', 'Modelo', 'Ativo', 'Última Comunicação', 'Última Posição', 'Câmeras', 'Firmware'],
                $stmt,
                fn($r) => [$r['imei'], $r['device_name'] ?? $r['imei'], $r['model_name'] ?? '',
                           $r['is_active'] ? 'Sim' : 'Não', $r['last_communication'] ?? '',
                           $r['last_position_at'] ?? '', $r['camera_count'] ?? 0, $r['firmware_version'] ?? ''],
            ];
    }
    return null;
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
