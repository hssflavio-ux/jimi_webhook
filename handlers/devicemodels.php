<?php
/**
 * JIMI Webhook System — Lista de Modelos de Dispositivo v3.1.0
 * Endpoint: GET /devicemodels
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$db = Database::getInstance()->getConnection();
$models = $db->query("SELECT id, model_name, protocol, camera_count, description FROM device_models ORDER BY protocol, model_name")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['code' => 0, 'models' => $models]);
