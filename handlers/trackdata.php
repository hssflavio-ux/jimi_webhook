<?php
/**
 * JIMI IoT Hub - Handler de Consulta de Tracks (GPS Histórico)
 * Endpoint: /trackdata  (via .htaccess → handlers/trackdata.php)
 * Versão: 2.0.0
 *
 * Retorna pontos GPS do banco local com filtro por IMEI e período.
 * Parâmetros GET: ?imei= (obrigatório), &start=, &end=, &limit=
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

$imei  = $_GET['imei'] ?? null;
$start = $_GET['start'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
$end   = $_GET['end']   ?? date('Y-m-d H:i:s');
$limit = min(max((int)($_GET['limit'] ?? 500), 1), 5000);

if (!$imei) {
    echo json_encode(['code' => 400, 'msg' => 'Parâmetro ?imei= obrigatório']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("
        SELECT imei, gps_time, latitude, longitude, speed, direction,
               satellites, acc, altitude, mileage, gps_mode,
               distance_from_previous, created_at AS server_time
        FROM gps_data
        WHERE imei = :imei
          AND gps_time BETWEEN :start AND :end
        ORDER BY gps_time ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':imei', $imei, PDO::PARAM_STR);
    $stmt->bindValue(':start', $start, PDO::PARAM_STR);
    $stmt->bindValue(':end', $end, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($tracks as $row) {
        $result[] = [
            'imei'       => $row['imei'],
            'gps_time'   => $row['gps_time'],
            'latitude'   => (float)$row['latitude'],
            'longitude'  => (float)$row['longitude'],
            'speed'      => (float)$row['speed'],
            'direction'  => (int)$row['direction'],
            'satellites' => (int)$row['satellites'],
            'acc'        => (int)$row['acc'],
            'altitude'   => (int)$row['altitude'],
            'mileage'    => (float)$row['mileage'],
            'gps_mode'   => (int)$row['gps_mode'],
            'distance'   => (float)$row['distance_from_previous'],
        ];
    }

    echo json_encode([
        'code'   => 0,
        'count'  => count($result),
        'imei'   => $imei,
        'start'  => $start,
        'end'    => $end,
        'tracks' => $result,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    Logger::error('Erro trackdata: ' . $e->getMessage(), ['source' => 'trackdata']);
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => 'Erro interno']);
}
