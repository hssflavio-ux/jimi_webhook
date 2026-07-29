<?php
/**
 * JIMI Webhook System — Worker de Jobs v4.4.0
 * Script: scripts/worker.php
 *
 * Cron (cada 1 min): processa fila de jobs pendentes.
 * Tipos: report (CSV/XLSX/PDF), video_download, rollup, notification (e-mail).
 *
 * É aqui que a saída SMTP acontece — nunca no webhook (ver
 * includes/notification_engine.php).
 *
 * Uso: php scripts/worker.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/export_helper.php';
require_once __DIR__ . '/../includes/mailer.php';

/** Máximo de tentativas de um job com falha transitória (só 'notification'). */
const JOB_MAX_ATTEMPTS = 3;

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
            case 'notification':
                $result = processNotificationJob($db, $job);
                break;
            default:
                $result = ['status' => 'falhou', 'error' => 'Tipo desconhecido'];
        }

        if ($result['status'] === 'concluido') {
            $db->prepare("UPDATE jobs SET status = 'concluido', result_path = :path, updated_at = NOW() WHERE id = :id")
               ->execute([':path' => $result['path'] ?? null, ':id' => $job['id']]);
        } else {
            markJobFailed($db, $job, $result['error'] ?? 'Erro desconhecido');
        }
    } catch (Exception $e) {
        markJobFailed($db, $job, $e->getMessage());
    }
}

echo 'Worker executado: ' . count($jobs) . " jobs processados.\n";

/**
 * Encerra um job com falha, aplicando retry quando a falha é transitória.
 *
 * Só 'notification' faz retry: SMTP fora do ar por dois minutos é condição
 * passageira e perder o aviso seria pior. Report e video_download continuam
 * falhando de primeira — reexecutá-los repetiria trabalho pesado e o usuário
 * pode simplesmente pedir de novo em /exportar.
 *
 * @param PDO    $db    Conexão ativa
 * @param array  $job   Linha da fila
 * @param string $error Mensagem de erro
 * @returns void
 */
function markJobFailed($db, array $job, string $error): void {
    $attempts = (int)($job['attempts'] ?? 0) + 1;

    if ($job['type'] === 'notification' && $attempts < JOB_MAX_ATTEMPTS) {
        $db->prepare(
            "UPDATE jobs SET status = 'pendente', attempts = :att, error_message = :err, updated_at = NOW()
             WHERE id = :id"
        )->execute([':att' => $attempts, ':err' => $error, ':id' => $job['id']]);
        echo "Job {$job['id']}: falha transitória (tentativa {$attempts}/" . JOB_MAX_ATTEMPTS . ") — {$error}\n";
        return;
    }

    $db->prepare(
        "UPDATE jobs SET status = 'falhou', attempts = :att, error_message = :err, updated_at = NOW()
         WHERE id = :id"
    )->execute([':att' => $attempts, ':err' => $error, ':id' => $job['id']]);
}

function processReportJob($db, $job): array {
    $params = json_decode($job['params'] ?? '{}', true);
    $reportType = $params['report_type'] ?? 'alarms';
    // Dias do form são BRT; colunas do banco são UTC
    [$dateFrom, $dateTo] = brt_day_range_to_utc(
        $params['date_from'] ?? brt_today('Y-m-d', '-30 days'),
        $params['date_to'] ?? brt_today()
    );
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
                      . ' — gerado em ' . brt_today('d/m/Y H:i') . ' BRT';
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
                fn($r) => [$r['imei'], $r['device_name'], $r['alarm_type'], fmt_brt($r['alarm_time'], 'd/m/Y H:i:s'), $r['latitude'], $r['longitude'], $r['speed']],
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
                           fmt_brt($r['first_alarm_at'], 'd/m/Y H:i:s'), fmt_brt($r['last_alarm_at'], 'd/m/Y H:i:s'), $r['treated_by'] ?? '—', $r['treatment_notes'] ?? ''],
            ];

        case 'positions':
            $stmt = $db->prepare("
                SELECT g.imei, COALESCE(d.device_name, g.imei) as device_name,
                       g.gps_time, g.latitude, g.longitude, g.speed, g.acc AS ignition, g.battery
                FROM gps_data g
                JOIN devices d ON d.imei = g.imei AND d.customer_id = :cid
                WHERE g.gps_time BETWEEN :df AND :dt AND ABS(g.latitude) > 0.0001 AND ABS(g.longitude) > 0.0001
                ORDER BY g.gps_time DESC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            return [
                ['IMEI', 'Dispositivo', 'Data/Hora', 'Latitude', 'Longitude', 'Velocidade (km/h)', 'Ignição', 'Bateria (%)'],
                $stmt,
                fn($r) => [$r['imei'], $r['device_name'], fmt_brt($r['gps_time'], 'd/m/Y H:i:s'), $r['latitude'], $r['longitude'],
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
                    return [$r['id'], $r['imei'], fmt_brt($r['started_at']), $r['ended_at'] ? fmt_brt($r['ended_at']) : '', $duration,
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
                           $r['is_active'] ? 'Sim' : 'Não', fmt_brt($r['last_communication'], 'd/m/Y H:i', ''),
                           fmt_brt($r['last_position_at'], 'd/m/Y H:i', ''), $r['camera_count'] ?? 0, $r['firmware_version'] ?? ''],
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

/**
 * Envia por e-mail uma notificação enfileirada pelo notification_engine.
 *
 * @param PDO   $db  Conexão ativa
 * @param array $job Linha da fila (params com to/subject/title/body/link_url)
 * @returns array{status:string,path:?string,error:?string}
 */
function processNotificationJob($db, $job): array {
    $p  = json_decode($job['params'] ?? '{}', true) ?: [];
    $to = $p['to'] ?? [];
    // Cliente do job decide quais credenciais SMTP valem (cliente → global → .env)
    $cid = isset($job['customer_id']) ? (int)$job['customer_id'] : null;

    if (!is_array($to) || empty($to)) {
        return ['status' => 'falhou', 'error' => 'Sem destinatários'];
    }
    if (!mail_is_configured($cid)) {
        return ['status' => 'falhou', 'error' => 'Servidor SMTP não cadastrado (Cadastros › Servidor de E-mail)'];
    }

    $result = send_mail(
        $to,
        (string)($p['subject'] ?? 'Notificação'),
        buildNotificationEmailHtml($p),
        [],
        $cid
    );

    if ($result['ok']) {
        return ['status' => 'concluido', 'path' => null, 'error' => null];
    }
    return ['status' => 'falhou', 'error' => $result['error']];
}

/**
 * Monta o HTML do e-mail de notificação.
 *
 * HTML inline e tabela simples de propósito: cliente de e-mail não tem
 * cascata de CSS confiável nem suporte a flexbox.
 *
 * @param array $p Params do job
 * @returns string HTML completo
 */
function buildNotificationEmailHtml(array $p): string {
    $accent = [
        'critical' => '#cf202f',
        'warning'  => '#a97a00',
        'info'     => '#0052ff',
    ][$p['severity'] ?? 'info'] ?? '#0052ff';

    $title = htmlspecialchars((string)($p['title'] ?? 'Notificação'), ENT_QUOTES, 'UTF-8');
    $body  = htmlspecialchars((string)($p['body'] ?? ''), ENT_QUOTES, 'UTF-8');

    $link = '';
    if (!empty($p['link_url'])) {
        $base = rtrim((string)(getenv('APP_URL') ?: ''), '/');
        $url  = htmlspecialchars($base . $p['link_url'], ENT_QUOTES, 'UTF-8');
        $link = '<p style="margin:24px 0 0;">'
              . '<a href="' . $url . '" style="display:inline-block;background:' . $accent . ';color:#fff;'
              . 'text-decoration:none;padding:12px 24px;border-radius:100px;font-weight:600;font-size:14px;">'
              . 'Abrir no sistema</a></p>';
    }

    return '<!doctype html><html lang="pt-BR"><body style="margin:0;padding:24px;'
         . 'background:#f5f6f8;font-family:Helvetica,Arial,sans-serif;color:#0a0b0d;">'
         . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
         . 'style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e6e8eb;border-radius:12px;">'
         . '<tr><td style="height:4px;background:' . $accent . ';border-radius:12px 12px 0 0;"></td></tr>'
         . '<tr><td style="padding:28px;">'
         . '<h1 style="margin:0 0 12px;font-size:18px;font-weight:600;line-height:1.4;">' . $title . '</h1>'
         . '<p style="margin:0;font-size:14px;line-height:1.6;color:#5b616e;">' . $body . '</p>'
         . $link
         . '</td></tr>'
         . '<tr><td style="padding:16px 28px;border-top:1px solid #e6e8eb;font-size:12px;color:#8a919e;">'
         . 'Mensagem automática do JIMI Tracker. Para deixar de receber, ajuste as regras em '
         . 'Cadastros &rsaquo; Config. Notificações.'
         . '</td></tr></table></body></html>';
}
