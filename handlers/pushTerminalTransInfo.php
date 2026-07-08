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
        $imei = $item['deviceImei'] ?? $item['imei'] ?? null;
        if (!$imei) {
            Logger::warning('TerminalTransInfo ignorado: IMEI ausente', [
                'source' => $this->handlerName, 'keys' => array_keys($item)
            ]);
            return false;
        }

        $extensionId = $item['extensionId'] ?? $item['extension_id'] ?? null;
        $content     = $item['content'] ?? $item['extensionData'] ?? null;

        $rawTime   = $item['postTime'] ?? $item['gpsTime'] ?? null;
        $eventTime = sanitize_date($rawTime);

        $description = $extensionId ? "extensionId:{$extensionId}" : 'terminal_trans_info';
        if ($content) {
            $contentShort = is_string($content) ? mb_strimwidth($content, 0, 200, '…') : json_encode($content, JSON_UNESCAPED_UNICODE);
            $description .= " | content:{$contentShort}";
        }

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
                'extension_id' => $extensionId,
                'has_content' => !empty($content)
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
