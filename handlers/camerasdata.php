<?php
/**
 * JIMI IoT Hub - Handler de Dados das Câmeras
 * Endpoint: /camerasdata  (via .htaccess → handlers/router.php → camerasdata.php)
 * Versão: 3.2.0
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
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'msg' => 'Method Not Allowed']);
    exit;
}

// ── Autorização: sessão de dashboard ativa (cookie jimi_token) OU token compartilhado ──
auth_init();
$hasSession = !empty($_SESSION['user_id']);

$validToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';
$sentToken  = $_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? ($_GET['token'] ?? ($_GET['_token'] ?? ''));
$hasToken   = ($sentToken !== '' && $sentToken === $validToken);

if (!$hasSession && !$hasToken) {
    http_response_code(401);
    echo json_encode(['code' => 401, 'msg' => 'Unauthorized']);
    exit;
}

// Multi-tenant: só filtra por cliente quando a chamada vem de uma sessão de dashboard logada
$customerId = $hasSession ? get_customer_id() : null;

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

    // ── 2. Dispositivos (filtrado por customer_id quando há sessão ativa, apenas ativos) ─────
    $sql = "
        SELECT d.imei, d.device_name, d.last_communication,
               s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status, s.is_online
        FROM devices d
        LEFT JOIN device_statistics s ON d.imei = s.imei
        WHERE d.is_active = 1
    ";
    $queryParams = [];
    if ($customerId) {
        $sql .= " AND d.customer_id = ?";
        $queryParams[] = $customerId;
    }
    $sql .= " ORDER BY d.last_communication DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);

    $devices = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hasGps = ($r['last_latitude'] != 0 && $r['last_longitude'] != 0);
        $devices[] = [
            'imei'       => $r['imei'],
            'name'       => $r['device_name'] ?? 'Sem Nome',
            'lat'        => $hasGps ? (float)$r['last_latitude'] : null,
            'lng'        => $hasGps ? (float)$r['last_longitude'] : null,
            'speed'      => (int)round($r['last_speed'] ?? 0),
            'acc'        => (int)($r['last_acc_status'] ?? 0),
            'is_online'  => (bool)($r['is_online'] ?? 0),
            'last'       => fmtBrt($r['last_communication'], $tz_utc, $tz_brt),
            'last_comm'  => fmtBrt($r['last_communication'], $tz_utc, $tz_brt),
            'ign_status' => ($r['last_acc_status'] == 1) ? 'Ligada' : 'Desligada',
            'ign_class'  => ($r['last_acc_status'] == 1) ? 'success' : 'secondary',
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
