<?php
/**
 * JIMI Webhook System — Customer Switch API v3.1.0
 * Endpoint: POST /customer_switch
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();
csrf_verify(); // endpoint cookie-autenticado → exige X-CSRF-Token (v4.2.0)

$input = json_decode(file_get_contents('php://input'), true);

// D4 (v4.2.0): sair da impersonação — fecha o log aberto e volta ao 1º cliente próprio
if (!empty($input['exit_impersonation'])) {
    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE impersonation_log SET ended_at = NOW() WHERE reseller_user_id = ? AND ended_at IS NULL")
           ->execute([$_SESSION['user_id']]);
    } catch (Exception $e) {}
    $own = get_available_customers($_SESSION['user_id']);
    if (!empty($own)) set_customer_context((int)$own[0]['id']);
    header('Content-Type: application/json');
    echo json_encode(['code' => 0, 'msg' => 'Impersonação encerrada.']);
    exit;
}

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
