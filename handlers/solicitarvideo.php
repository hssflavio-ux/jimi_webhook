<?php
/**
 * JIMI Webhook System — Reenvio de vídeo de alarme v4.9.31
 * Endpoint: POST /solicitarvideo   (AJAX, JSON)
 *
 * Pede à câmera o vídeo de um alarme que ficou sem ele. A lógica — escolha do
 * comando por tentativa e registro do pedido — vive em
 * `includes/alarm_video_request.php`; aqui só entram autenticação, escopo de
 * cliente e a resposta JSON.
 *
 * ⚠️ O ESCOPO DE CLIENTE É OBRIGATÓRIO. Sem ele, qualquer usuário dispararia
 * comando na câmera de qualquer tenant informando um `alarm_id` — é comando em
 * equipamento real, não leitura de tela.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/alarm_video_request.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
csrf_verify();
require_permission('relatorios', 'view');   // mesma tela do rel_alarmes.php

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método não permitido.']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$alarmId = (int)($input['alarm_id'] ?? $_POST['alarm_id'] ?? 0);
if ($alarmId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Alarme não informado.']);
    exit;
}

// ── Escopo: o alarme tem de ser de um equipamento que o usuário enxerga ──────
$db         = Database::getInstance()->getConnection();
$user       = get_jimi_user();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$customerId = get_customer_id();
$scope      = report_customer_scope(null, $isAdmin, $customerId);

// Fase 2 do fluxo chip→câmera→veículo: dono GRAVADO no alarme (snapshot do
// momento), não o dono ATUAL da câmera.
$sql    = "SELECT a.id FROM alarms a WHERE a.id = :id";
$params = [':id' => $alarmId];
if ($scope !== null) {
    $sql .= " AND a.customer_id = :cid";
    $params[':cid'] = $scope;
}
$st = $db->prepare($sql);
$st->execute($params);
if (!$st->fetchColumn()) {
    // 404 e não 403: para quem sonda, "não existe" diz menos que "existe mas
    // não é seu" — mesma postura do handlers/filelist.php.
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Alarme não encontrado no seu escopo de cliente.']);
    exit;
}

$r = request_alarm_video($alarmId, (int)($_SESSION['user_id'] ?? 0) ?: null);
if (!$r['ok']) {
    http_response_code(409);
}
echo json_encode($r, JSON_UNESCAPED_UNICODE);
