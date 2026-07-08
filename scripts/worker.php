<?php
/**
 * JIMI Webhook System — Worker de Jobs v4.0.0
 * Script: scripts/worker.php
 *
 * Cron job (cada 1 min) que processa a fila de jobs assíncronos:
 * report, video_download, rollup.
 *
 * Uso: php scripts/worker.php
 */

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Processar jobs pendentes (limite: 5 por execução)
$stmt = $db->prepare(
    "SELECT * FROM jobs
     WHERE status = 'pendente'
     ORDER BY created_at ASC
     LIMIT 5"
);
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
            $stmt = $db->prepare("UPDATE jobs SET status = 'concluido', result_path = :path, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':path' => $result['path'] ?? null, ':id' => $job['id']]);
        } else {
            $stmt = $db->prepare("UPDATE jobs SET status = 'falhou', error_message = :err, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':err' => $result['error'] ?? 'Erro desconhecido', ':id' => $job['id']]);
        }
    } catch (Exception $e) {
        $db->prepare("UPDATE jobs SET status = 'falhou', error_message = :err, updated_at = NOW() WHERE id = :id")
           ->execute([':err' => $e->getMessage(), ':id' => $job['id']]);
    }
}

echo 'Worker executado: ' . count($jobs) . " jobs processados.\n";

function processReportJob($db, $job): array {
    $params = json_decode($job['params'] ?? '{}', true);
    // Placeholder: simula geração de relatório
    $filename = 'storage/reports/report_' . $job['id'] . '_' . date('Ymd_His') . '.csv';
    $dir = __DIR__ . '/../storage/reports';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $fp = fopen(__DIR__ . '/../' . $filename, 'w');
    fputcsv($fp, ['ID', 'Tipo', 'Cliente', 'Data']);
    fputcsv($fp, [$job['id'], 'Relatório gerado', $job['customer_id'] ?? '—', date('Y-m-d H:i:s')]);
    fclose($fp);

    return ['status' => 'concluido', 'path' => $filename];
}

function processVideoJob($db, $job): array {
    return ['status' => 'concluido', 'path' => null, 'error' => null];
}

function processRollupJob($db, $job): array {
    return ['status' => 'concluido', 'path' => null, 'error' => null];
}
