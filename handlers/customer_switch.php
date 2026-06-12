<?php
/**
 * JIMI Webhook System — Customer Switch API v3.1.0
 * Endpoint: POST /customer_switch
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$input = json_decode(file_get_contents('php://input'), true);
$customer_id = (int)($input['customer_id'] ?? 0);

if ($customer_id > 0) {
    $customers = get_available_customers($_SESSION['user_id']);
    $found = false;
    foreach ($customers as $c) {
        if ((int)$c['id'] === $customer_id) { $found = true; break; }
    }
    if ($found) {
        set_customer_context($customer_id);
        header('Content-Type: application/json');
        echo json_encode(['code' => 0, 'msg' => 'Cliente alterado.']);
        exit;
    }
}

header('Content-Type: application/json');
http_response_code(400);
echo json_encode(['code' => 1, 'msg' => 'Cliente inválido.']);
