<?php
/**
 * JIMI Webhook System — Polling de Notificações v4.4.0
 * Endpoint: /notificacoesdata
 *
 * GET  → { code, unread, items[], popups[] }
 *        items:  últimas notificações do escopo (sino)
 *        popups: apenas as novas desde `last_id` que pedem toast/som —
 *                assim o alerta em tempo real aparece uma única vez.
 * POST → { action: 'read', id: N } | { action: 'read_all' }
 *
 * Escopo multi-tenant: customer_id da sessão + notificações destinadas a
 * todos (user_id NULL) ou ao usuário logado.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!auth_init()) {
    echo json_encode(['code' => 401, 'message' => 'Não autenticado']);
    exit;
}

$customerId = get_customer_id();
$userId     = $_SESSION['user_id'] ?? null;
$db         = Database::getInstance()->getConnection();

// Escopo comum a todas as consultas: cliente da sessão + destinatário
$scope  = 'WHERE (n.user_id IS NULL OR n.user_id = :uid)';
$params = [':uid' => $userId];
if ($customerId) {
    $scope .= ' AND n.customer_id = :cid';
    $params[':cid'] = $customerId;
}

try {
    // ── POST: marcar como lida ──────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();

        $raw    = file_get_contents('php://input');
        $input  = json_decode($raw ?: '{}', true) ?: [];
        $action = $input['action'] ?? ($_POST['action'] ?? '');

        if ($action === 'read_all') {
            $stmt = $db->prepare(
                "UPDATE notifications n SET n.is_read = 1, n.read_at = NOW()
                 $scope AND n.is_read = 0"
            );
            $stmt->execute($params);
            echo json_encode(['code' => 0, 'updated' => $stmt->rowCount()]);
            exit;
        }

        if ($action === 'read' && !empty($input['id'])) {
            $p = $params;
            $p[':id'] = (int)$input['id'];
            $stmt = $db->prepare(
                "UPDATE notifications n SET n.is_read = 1, n.read_at = NOW()
                 $scope AND n.id = :id"
            );
            $stmt->execute($p);
            echo json_encode(['code' => 0, 'updated' => $stmt->rowCount()]);
            exit;
        }

        echo json_encode(['code' => 400, 'message' => 'Ação inválida']);
        exit;
    }

    // ── GET: contador + lista + popups ──────────────────────────
    $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications n $scope AND n.is_read = 0");
    $countStmt->execute($params);
    $unread = (int)$countStmt->fetchColumn();

    $listStmt = $db->prepare(
        "SELECT n.id, n.kind, n.severity, n.title, n.body, n.link_url,
                n.want_popup, n.want_sound, n.is_read, n.created_at
         FROM notifications n
         $scope
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT 20"
    );
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'       => (int)$r['id'],
            'kind'     => $r['kind'],
            'severity' => $r['severity'],
            'title'    => $r['title'],
            'body'     => $r['body'],
            'link'     => $r['link_url'],
            'is_read'  => (int)$r['is_read'],
            'when'     => fmt_brt($r['created_at'], 'd/m H:i'),
        ];
    }

    // Popups: só o que chegou DEPOIS do último id que o cliente já viu.
    // Sem isso o mesmo alarme abriria um toast a cada polling de 30s.
    $popups = [];
    $lastId = (int)($_GET['last_id'] ?? 0);
    if ($lastId > 0) {
        foreach ($rows as $r) {
            if ((int)$r['id'] > $lastId && !empty($r['want_popup']) && empty($r['is_read'])) {
                $popups[] = [
                    'id'       => (int)$r['id'],
                    'severity' => $r['severity'],
                    'title'    => $r['title'],
                    'body'     => $r['body'],
                    'link'     => $r['link_url'],
                    'sound'    => (int)$r['want_sound'],
                ];
            }
        }
    }

    echo json_encode([
        'code'    => 0,
        'unread'  => $unread,
        'max_id'  => $rows ? (int)$rows[0]['id'] : $lastId,
        'items'   => $items,
        'popups'  => array_reverse($popups), // mais antigo primeiro
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Tabela ausente (migração v4.4.0 não aplicada) não pode quebrar o
    // layout: o sino simplesmente não aparece.
    echo json_encode(['code' => 0, 'unread' => 0, 'items' => [], 'popups' => [], 'max_id' => 0]);
}
