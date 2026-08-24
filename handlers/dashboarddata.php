<?php
/**
 * JIMI Webhook System — Layout do Painel (AJAX) v4.10.3
 * Endpoint: /dashboarddata
 *
 * GET  → devolve o layout efetivo do usuário (dashboard_resolve_layout()).
 * POST → grava o layout do PRÓPRIO usuário (nunca de outro — não há
 *        parâmetro de usuário no corpo). JSON: {"layout": ["kpi_devices", ...]}.
 *
 * Item 7 do docs/PLANO_IMPLEMENTACAO_v4.10.md.
 */

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/dashboard_widgets.php';

$userId = require_ajax_session();
$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['code' => 0, 'layout' => dashboard_resolve_layout($db, $userId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // AJAX em JSON não manda o token via campo de formulário — csrf_verify()
    // também aceita o header X-CSRF-Token (includes/csrf.php), que é o que
    // handlers/painel.php envia.
    csrf_verify();

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $layout = dashboard_sanitize_layout(is_array($input['layout'] ?? null) ? $input['layout'] : []);

    try {
        $stmt = $db->prepare("
            INSERT INTO dashboard_layouts (customer_id, user_id, layout, updated_by)
            VALUES (:cid, :uid, :layout, :uid)
            ON DUPLICATE KEY UPDATE layout = VALUES(layout), updated_by = VALUES(updated_by)
        ");
        $stmt->execute([
            ':cid'    => get_customer_id(),
            ':uid'    => $userId,
            ':layout' => json_encode($layout, JSON_UNESCAPED_UNICODE),
        ]);
        echo json_encode(['code' => 0, 'layout' => $layout], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        Logger::error('dashboarddata: falha ao gravar layout', ['error' => $e->getMessage(), 'user_id' => $userId]);
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => 'Não foi possível salvar o layout. Aplique a migração v4.10.3.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['code' => 405, 'msg' => 'Method Not Allowed']);
