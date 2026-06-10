<?php
/**
 * JIMI IoT Hub - Handler de Dados de Transmissão do Terminal
 * Endpoint: /pushTerminalTransInfo
 * Versão: 2.0.0 (Migrado para Arquitetura WebhookHandler)
 * Referência: Seção 1.15 - Dados de Extensão (status em tempo real do terminal)
 */
define('HANDLER_NAME', 'pushTerminalTransInfo');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushTerminalTransInfoHandler extends WebhookHandler {

    public function __construct() {
        parent::__construct(HANDLER_NAME);
    }

    protected function processItem($item) {
        // Extrair IMEI
        $imei = $item['deviceImei'] ?? $item['imei'] ?? null;
        if (!$imei) {
            Logger::warning('TerminalTransInfo ignorado: IMEI ausente', [
                'source' => $this->handlerName, 'keys' => array_keys($item)
            ]);
            return false;
        }

        // Campos documentados: postTime, gpsTime, extensionId, lat, lng, content
        $extensionId = $item['extensionId'] ?? $item['extension_id'] ?? null;
        $description = $extensionId ? "extensionId:{$extensionId}" : null;

        // postTime é o campo primário de tempo documentado
        $rawTime   = $item['postTime'] ?? $item['gpsTime'] ?? null;
        $eventTime = sanitize_date($rawTime);

        // Persistir na tabela device_events como evento genérico
        try {
            $stmt = $this->db->prepare("
                INSERT INTO device_events (imei, event_type, event_time, description, raw_data)
                VALUES (:imei, :type, :time, :desc, :raw)
            ");
            $stmt->execute([
                ':imei' => $imei,
                ':type' => 'terminal_trans_info',
                ':time' => $eventTime,
                ':desc' => $description,
                ':raw'  => json_encode($item, JSON_UNESCAPED_UNICODE)
            ]);

            Logger::info('TerminalTransInfo registrado', [
                'source' => $this->handlerName,
                'imei' => $imei,
                'extension_id' => $extensionId
            ]);

            return true;

        } catch (PDOException $e) {
            Logger::error('Erro SQL PushTerminalTransInfo: ' . $e->getMessage(), [
                'source' => $this->handlerName,
                'imei' => $imei
            ]);
            return false;
        }
    }
}

$handler = new PushTerminalTransInfoHandler();
$handler->handle();
