<?php
/**
 * JIMI IoT Hub - Handler de Dados de Mídia
 * Endpoint: /mediadata  (via .htaccess → handlers/mediadata.php)
 * Versão: 2.0.0
 *
 * Retorna lista de arquivos de mídia (media_files + resource_lists) em JSON
 * para a galeria do painel. Aceita filtro opcional por ?imei= e ?limit=.
 */
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'msg' => 'Método não permitido']);
    exit;
}

// ── Autorização: sessão de dashboard obrigatória (R02 — antes bastava o token
// compartilhado, que expunha mídia de qualquer IMEI de qualquer cliente)
require_ajax_session();
$customerId = (int)get_customer_id();
if (!$customerId) {
    http_response_code(403);
    echo json_encode(['code' => 403, 'msg' => 'Contexto de cliente não definido']);
    exit;
}

try {
    $db   = Database::getInstance()->getConnection();
    $imei = $_GET['imei'] ?? null;
    $limit = min(max((int)($_GET['limit'] ?? 50), 1), 200);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    // ── 1. Arquivos de mídia (pushfileupload / pushftpfileupload) ─────────────
    // Multi-tenant: sempre restrito aos IMEIs do cliente da sessão
    $whereMedia = 'WHERE imei IN (SELECT imei FROM devices WHERE customer_id = :cid)';
    $paramsMedia = [':limit' => $limit, ':offset' => $offset, ':cid' => $customerId];
    if ($imei) {
        $whereMedia .= ' AND imei = :imei';
        $paramsMedia[':imei'] = $imei;
    }

    $stmtMedia = $db->prepare("
        SELECT imei, file_name, file_type, file_size, file_url, source_type,
               event_time, created_at
        FROM media_files
        $whereMedia
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($paramsMedia as $k => $v) {
        $stmtMedia->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmtMedia->execute();
    $mediaFiles = $stmtMedia->fetchAll(PDO::FETCH_ASSOC);

    // ── 2. Lista de recursos (pushresourcelist) ────────────────────────────────
    // Multi-tenant: sempre restrito aos IMEIs do cliente da sessão
    $whereRes = 'WHERE imei IN (SELECT imei FROM devices WHERE customer_id = :cid)';
    $paramsRes = [':limit' => $limit, ':offset' => $offset, ':cid' => $customerId];
    if ($imei) {
        $whereRes .= ' AND imei = :imei';
        $paramsRes[':imei'] = $imei;
    }

    $stmtRes = $db->prepare("
        SELECT imei, resource_type, file_name, file_size, start_time, end_time,
               channel_id, alarm_type, created_at
        FROM resource_lists
        $whereRes
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($paramsRes as $k => $v) {
        $stmtRes->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmtRes->execute();
    $resourceLists = $stmtRes->fetchAll(PDO::FETCH_ASSOC);

    // ── 3. Montar resposta ────────────────────────────────────────────────────
    $media = [];
    foreach ($mediaFiles as $row) {
        $media[] = [
            'imei'       => $row['imei'],
            'file_name'  => $row['file_name'],
            'file_type'  => $row['file_type'],
            'file_size'  => (int)$row['file_size'],
            'file_url'   => $row['file_url'],
            'source'     => $row['source_type'],
            'event_time' => $row['event_time'],
            'created_at' => $row['created_at'],
            'kind'       => 'upload',
        ];
    }

    foreach ($resourceLists as $row) {
        $media[] = [
            'imei'          => $row['imei'],
            'file_name'     => $row['file_name'],
            'file_type'     => $row['resource_type'] === 'video' ? 'video' :
                              ($row['resource_type'] === 'image' ? 'image' :
                              ($row['resource_type'] === 'audio' ? 'audio' : 'other')),
            'file_size'     => (int)$row['file_size'],
            'channel_id'    => (int)$row['channel_id'],
            'alarm_type'    => $row['alarm_type'],
            'start_time'    => $row['start_time'],
            'end_time'      => $row['end_time'],
            'created_at'    => $row['created_at'],
            'kind'          => 'resource',
        ];
    }

    echo json_encode([
        'code'   => 0,
        'count'  => count($media),
        'limit'  => $limit,
        'offset' => $offset,
        'media'  => $media,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    Logger::error('Erro mediadata: ' . $e->getMessage(), ['source' => 'mediadata']);
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => 'Erro interno']);
}
