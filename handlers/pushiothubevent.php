<?php
/**
 * JIMI IoT Hub - Handler de Eventos do IoTHub
 * Endpoint: /pushiothubevent
 * Versão: 2.0.0 (Migrado para Arquitetura WebhookHandler)
 * Referência: Seção 1.13 - Notificações de lista de arquivos de alarme, início/fim de upload
 */
define('HANDLER_NAME', 'pushiothubevent');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushIotHubEventHandler extends WebhookHandler {

    public function __construct() {
        parent::__construct(HANDLER_NAME);
    }

    protected function processItem($item) {
        // Extrair IMEI (deviceImei é o campo documentado, imei é o normalizado)
        $imei = $item['deviceImei'] ?? $item['imei'] ?? null;
        if (!$imei) {
            Logger::warning('IoTHub Event ignorado: IMEI ausente', [
                'source' => $this->handlerName, 'keys' => array_keys($item)
            ]);
            return false;
        }

        // Campos documentados: eventType, eventContent, gateTime
        // eventType: UploadAlarmFileList | UploadAlarmFileBegin | UploadAlarmFileEnd | UploadMediaFileBegin | UploadMediaFileEnd
        $eventType    = $item['eventType']    ?? $item['notifyType'] ?? 'unknown';
        $eventContent = $item['eventContent'] ?? $item['remark']     ?? null;

        // gateTime é um timestamp Unix (long); fallback para notifyTime (legado)
        $rawTime  = $item['gateTime']   ?? $item['notifyTime'] ?? null;
        $eventTime = sanitize_date($rawTime);

        // Origem do evento (padrão: platform)
        $source = $item['source'] ?? 'platform';

        // Persistir na tabela iothub_events
        try {
            $stmt = $this->db->prepare("
                INSERT INTO iothub_events (imei, event_type, event_time, source, payload)
                VALUES (:imei, :type, :time, :source, :payload)
            ");
            $stmt->execute([
                ':imei'    => $imei,
                ':type'    => $eventType,
                ':time'    => $eventTime,
                ':source'  => $source,
                ':payload' => json_encode($item, JSON_UNESCAPED_UNICODE)
            ]);

            Logger::info('IoTHub Event registrado', [
                'source' => $this->handlerName,
                'imei' => $imei,
                'event_type' => $eventType
            ]);

            return true;

        } catch (PDOException $e) {
            Logger::error('Erro SQL PushIotHubEvent: ' . $e->getMessage(), [
                'source' => $this->handlerName,
                'imei' => $imei
            ]);
            return false;
        }
    }
}

$handler = new PushIotHubEventHandler();
$handler->handle();
