<?php
/**
 * JIMI IoT Hub - Handler de Resposta de Comandos
 * Endpoint: /pushcmd
 * Versão: 2.0.0
 * Referência: Processa respostas de comandos enviados aos dispositivos.
 */
define('HANDLER_NAME', 'pushcmd');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushCommandHandler extends WebhookHandler {
    public function __construct() { parent::__construct(HANDLER_NAME); }
    
    protected function processItem($item) {
        $imei = $this->validateRequired($item, 'imei', 'IMEI');
        
        $stmt = $this->db->prepare("SELECT id FROM commands WHERE imei = :imei AND status IN ('pending', 'sent', 'queued') ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':imei' => $imei]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $status = ($item['status'] ?? 'success') == 'success' ? 'executed' : 'failed';
            $stmt = $this->db->prepare("UPDATE commands SET status = :status, response_time = NOW(), response_payload = :payload, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':status' => $status, ':payload' => json_encode($item), ':id' => $existing['id']]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO commands (imei, command_content, command_type, status, response_time, response_payload) VALUES (:imei, :content, 'response', 'executed', NOW(), :payload)");
            $stmt->execute([':imei' => $imei, ':content' => 'Orphan Response', ':payload' => json_encode($item)]);
        }
        return true;
    }
}
$handler = new PushCommandHandler();
$handler->handle();
