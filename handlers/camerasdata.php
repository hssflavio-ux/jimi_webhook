<?php
/**
 * JIMI IoT Hub - Handler de Dados das Câmeras
 * Endpoint: /camerasdata  (via .htaccess → handlers/camerasdata.php)
 * Versão: 2.0.0
 *
 * Retorna dados de dispositivos, status da API e últimos alarmes em JSON
 * para atualização em segundo plano no painel sem recarregar a página.
 *
 * MySQL retorna UTC (SET time_zone=+00:00 na conexão) → conversão correta para BRT.
 */

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'msg' => 'Method Not Allowed']);
    exit;
}

$validToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';
$sentToken  = $_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? ($_GET['_token'] ?? '');
if ($sentToken !== $validToken) {
    http_response_code(401);
    echo json_encode(['code' => 401, 'msg' => 'Unauthorized']);
    exit;
}

// ── Helpers de timezone (MySQL retorna UTC via conexão) ───────────────────────
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo');

function fmtBrt($dateStr, $tz_utc, $tz_brt) {
    if (!$dateStr || $dateStr === '0000-00-00 00:00:00') return '-';
    try {
        $dt = new DateTime($dateStr, $tz_utc);
        $dt->setTimezone($tz_brt);
        return $dt->format('d/m/Y H:i:s');
    } catch (Exception $e) {
        return $dateStr;
    }
}

try {
    $db = Database::getInstance()->getConnection();

    // ── 1. Status da API ──────────────────────────────────────────────────────
    $row = $db->query("
        SELECT GREATEST(
            COALESCE(MAX(last_communication), '2000-01-01 00:00:00'),
            COALESCE((SELECT MAX(created_at) FROM alarms), '2000-01-01 00:00:00')
        ) AS last_hit
        FROM devices
    ")->fetchColumn();

    $apiStatus = ['label' => 'OFFLINE', 'color' => 'danger', 'last' => '-'];
    if ($row) {
        $dtLast      = new DateTime($row, $tz_utc);
        $dtNow       = new DateTime('now', $tz_utc);
        $diffMinutes = ($dtNow->getTimestamp() - $dtLast->getTimestamp()) / 60;
        $apiStatus['last'] = fmtBrt($row, $tz_utc, $tz_brt);
        if ($diffMinutes <= 10) {
            $apiStatus['label'] = 'ONLINE';  $apiStatus['color'] = 'success';
        } elseif ($diffMinutes <= 60) {
            $apiStatus['label'] = 'OCIOSO'; $apiStatus['color'] = 'warning';
        }
    }

    // ── 2. Dispositivos ───────────────────────────────────────────────────────
    $stmt = $db->query("
        SELECT d.imei, d.device_name, d.last_communication,
               s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status
        FROM devices d
        LEFT JOIN device_statistics s ON d.imei = s.imei
        ORDER BY d.last_communication DESC
    ");

    $devices = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hasGps = ($r['last_latitude'] != 0 && $r['last_longitude'] != 0);
        $devices[] = [
            'imei'       => $r['imei'],
            'name'       => $r['device_name'] ?? 'Sem Nome',
            'last_comm'  => fmtBrt($r['last_communication'], $tz_utc, $tz_brt),
            'ign_status' => ($r['last_acc_status'] == 1) ? 'Ligada' : 'Desligada',
            'ign_class'  => ($r['last_acc_status'] == 1) ? 'success' : 'secondary',
            'speed'      => (int)round($r['last_speed'] ?? 0),
            'has_gps'    => $hasGps,
            'map_url'    => $hasGps
                ? "https://www.google.com/maps?q={$r['last_latitude']},{$r['last_longitude']}"
                : null,
        ];
    }

    // ── 3. Hora atual do servidor em BRT (para o footer) ──────────────────────
    $dtServer = new DateTime('now', $tz_brt);

    echo json_encode([
        'code'       => 0,
        'apiStatus'  => $apiStatus,
        'devices'    => $devices,
        'serverTime' => $dtServer->format('d/m/Y H:i:s') . ' GMT-3',
        'count'      => count($devices),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    Logger::error('camerasdata: erro', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => $e->getMessage()]);
}
