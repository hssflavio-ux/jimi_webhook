<?php
/**
 * JIMI IoT Hub - Handler de Consulta de Heartbeats
 * Endpoint: /hbdata  (via .htaccess → handlers/hbdata.php)
 * Versão: 2.0.0
 *
 * Retorna heartbeats do banco local. Parâmetros GET: ?imeis= (IMEIs separados por vírgula)
 * ou ?imei= (único), &limit=
 */
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'msg' => 'Método não permitido']);
    exit;
}

$validToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';
$sentToken  = $_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? ($_GET['_token'] ?? '');
if ($sentToken !== $validToken) {
    http_response_code(401);
    echo json_encode(['code' => 401, 'msg' => 'Não autorizado']);
    exit;
}

$imeisRaw = $_GET['imeis'] ?? ($_GET['imei'] ?? '');
$imeis    = array_filter(array_map('trim', explode(',', $imeisRaw)));
$limit    = min(max((int)($_GET['limit'] ?? 100), 1), 500);

if (empty($imeis)) {
    echo json_encode(['code' => 400, 'msg' => 'Parâmetro ?imeis= ou ?imei= obrigatório']);
    exit;
}

try {
    $db     = Database::getInstance()->getConnection();
    $placeholders = implode(',', array_fill(0, count($imeis), '?'));
    $stmt = $db->prepare("
        SELECT imei, heartbeat_time, battery, gsm_signal, temperature,
               voltage, status, acc, oil_ele, gps_pos, remote_lock,
               power_status, fortify
        FROM heartbeats
        WHERE imei IN ($placeholders)
        ORDER BY heartbeat_time DESC
        LIMIT $limit
    ");
    $stmt->execute(array_values($imeis));
    $heartbeats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'code'       => 0,
        'count'      => count($heartbeats),
        'heartbeats' => $heartbeats,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    Logger::error('Erro hbdata: ' . $e->getMessage(), ['source' => 'hbdata']);
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => 'Erro interno']);
}
