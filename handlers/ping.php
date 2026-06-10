<?php
/**
 * JIMI IoT Hub - Verificação de Saúde
 * Endpoint: /ping
 * Versão: 2.0.0
 */
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['code' => 0, 'message' => 'pong', 'version' => '2.0.0', 'timestamp' => date('Y-m-d H:i:s')]);
