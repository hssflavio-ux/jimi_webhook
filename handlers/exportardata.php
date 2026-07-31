<?php
/**
 * JIMI Webhook System — Export Data AJAX v4.0.0
 * Endpoint: /exportardata
 *
 * Polling da fila de exportação. Retorna status dos jobs para o dashboard.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/export_helper.php';

if (!auth_init()) {
    echo json_encode(['code' => 401, 'message' => 'Não autenticado']);
    exit;
}

$customerId = get_customer_id();
$db = Database::getInstance()->getConnection();

$jobsStmt = $db->prepare("
    SELECT id, type, status, params, result_path, error_message, created_at, updated_at
    FROM jobs
    WHERE customer_id = :cid OR customer_id IS NULL
    ORDER BY created_at DESC
    LIMIT 20
");
$jobsStmt->execute([':cid' => $customerId]);
$jobs = $jobsStmt->fetchAll();

$data = array_map(function($j) {
    // Formato: extensão do arquivo gerado > params.format > csv (legado)
    $params = json_decode($j['params'] ?? '{}', true) ?: [];
    $format = $j['result_path']
        ? strtolower(pathinfo($j['result_path'], PATHINFO_EXTENSION))
        : ($params['format'] ?? 'csv');
    if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) $format = 'csv';

    // Purgado por REPORT_RETENTION_DAYS (v4.7.1): o job segue 'concluido' e o
    // result_path segue gravado, mas o arquivo não existe mais em disco. O
    // campo é informado em vez de o result_path virar null — quem já guardou o
    // caminho continua enxergando o mesmo valor, e quem desenha o botão sabe
    // que ele levaria a um 404.
    $expired = $j['status'] === 'concluido' && $j['result_path']
        && !is_file(__DIR__ . '/../' . $j['result_path']);

    return [
        'id'           => (int)$j['id'],
        'name'         => $params['report_name'] ?? null,
        'type'         => $j['type'],
        'status'       => $j['status'],
        'format'       => $format,
        'mime_type'    => export_mime_type($format),
        'result_path'  => $j['result_path'],
        'expired'      => $expired,
        'error_message' => $j['error_message'],
        'created_at'   => $j['created_at'],
        'updated_at'   => $j['updated_at'],
    ];
}, $jobs);

echo json_encode([
    'code' => 0,
    'data' => [
        'jobs' => $data,
        'pending' => count(array_filter($data, fn($j) => $j['status'] === 'pendente' || $j['status'] === 'processando')),
    ],
    'message' => 'ok',
], JSON_UNESCAPED_UNICODE);
