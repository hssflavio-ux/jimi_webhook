<?php
/**
 * JIMI IoT Hub - Handler de Status de Comandos
 * Endpoint: /commandstatus  (via .htaccess → handlers/commandstatus.php)
 * Versão: 2.0.0
 *
 * Propósito: Retorna histórico de comandos e respostas offline para o
 *            painel atualizar a tabela de histórico via AJAX (polling).
 *
 * NÃO é um endpoint de push do IoTHub. Não herda WebhookHandler.
 * Aceita GET. Filtragem opcional por ?imei= e ?limit=
 *
 * Inclui contagem de respostas offline recentes (command_responses)
 * para indicar atividade do tracker-instruction-server.
 */

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

// ── Apenas GET ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'msg' => 'Method Not Allowed']);
    exit;
}

// ── Validação de token interno ────────────────────────────────────────────────
$validToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';
$sentToken  = $_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? ($_GET['_token'] ?? '');
if ($sentToken !== $validToken) {
    http_response_code(401);
    echo json_encode(['code' => 401, 'msg' => 'Unauthorized']);
    exit;
}

// ── Parâmetros ────────────────────────────────────────────────────────────────
$imei  = trim($_GET['imei']  ?? '');
$limit = min(max(intval($_GET['limit'] ?? 30), 1), 100);

// ── Funções auxiliares ────────────────────────────────────────────────────────
$tzUTC = new DateTimeZone('UTC');
$tzBRT = new DateTimeZone('America/Sao_Paulo');

$fmtDate = function ($d) use ($tzUTC, $tzBRT) {
    if (!$d) return '-';
    try {
        $dt = new DateTime($d, $tzUTC);
        $dt->setTimezone($tzBRT);
        return $dt->format('d/m/Y H:i:s');
    } catch (Exception $e) {
        return $d;
    }
};

try {
    $db = Database::getInstance()->getConnection();

    // ── Histórico de comandos ─────────────────────────────────────────────────
    $whereSql = $imei ? 'WHERE imei = :imei' : '';
    $sql = "
        SELECT id, imei, command_content, command_type, status, operator,
               response_payload, created_at, updated_at
        FROM commands
        {$whereSql}
        ORDER BY created_at DESC
        LIMIT :limit
    ";
    $stmt = $db->prepare($sql);
    if ($imei) $stmt->bindValue(':imei', $imei, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $commands = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Extrai mensagem de resposta legível do JSON armazenado
        $respDisplay = null;
        if ($row['response_payload']) {
            $decoded = json_decode($row['response_payload'], true);
            if (is_array($decoded)) {
                $respDisplay = $decoded['resultContent']
                    ?? $decoded['content']
                    ?? $decoded['msg']
                    ?? $decoded['message']
                    ?? json_encode($decoded, JSON_UNESCAPED_UNICODE);
            } else {
                $respDisplay = (string)$row['response_payload'];
            }
        }

        $commands[] = [
            'id'       => (int)$row['id'],
            'imei'     => $row['imei'],
            'command'  => $row['command_content'],
            'type'     => $row['command_type'],
            'status'   => $row['status'],
            'operator' => $row['operator'],
            'response' => $respDisplay,
            'created'  => $fmtDate($row['created_at']),
            'updated'  => $fmtDate($row['updated_at']),
        ];
    }

    // ── Respostas offline recentes (command_responses, se a tabela existir) ──
    $offlineCount   = 0;
    $offlineRecent  = [];
    try {
        $offStmt = $db->prepare("
            SELECT imei, instruct_id, response_content, status, execute_time
            FROM command_responses
            WHERE created_at > NOW() - INTERVAL 1 HOUR
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $offStmt->execute();
        $offlineRecent = $offStmt->fetchAll(PDO::FETCH_ASSOC);
        $offlineCount  = count($offlineRecent);
    } catch (Exception $e) {
        // Tabela pode não existir em todas as instalações — silencia
    }

    echo json_encode([
        'code'           => 0,
        'commands'       => $commands,
        'offline_count'  => $offlineCount,
        'offline_recent' => $offlineRecent,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    Logger::error('commandstatus: erro', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => 'Erro interno: ' . $e->getMessage()]);
}
