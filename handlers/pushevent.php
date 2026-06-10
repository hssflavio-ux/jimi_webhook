<?php
/**
 * JIMI IoT Hub - Push Event Handler
 * Endpoint: /pushevent
 * Versão: 2.0.0 (Prioriza gateTime, extrai timezone)
 * Referência: Seção 1.1 - Notificação de Login/Logout
 */
define('HANDLER_NAME', 'pushevent');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushEventHandler extends WebhookHandler {
    public function __construct() { 
        parent::__construct(HANDLER_NAME); 
    }
    
    protected function processItem($item) {
        // Validar IMEI
        $imei = $this->validateRequired($item, 'imei', 'IMEI');
        
        // Extrair event_type com múltiplos fallbacks
        $eventType = $item['event_type'] 
                  ?? $item['eventType'] 
                  ?? $item['type'] 
                  ?? $item['event_code']
                  ?? null;
        
        if (!$eventType) {
            $msgId = $item['msgId'] ?? $item['msg_id'] ?? null;
            $status = $item['status'] ?? null;
            
            if ($msgId) {
                $eventType = "MSG_{$msgId}";
            } elseif ($status) {
                $eventType = "STATUS_{$status}";
            } else {
                $eventType = 'UNKNOWN_EVENT';
            }
            
            Logger::warning('event_type não encontrado, usando fallback', [
                'source' => $this->handlerName, 'imei' => $imei,
                'event_type_fallback' => $eventType,
                'available_keys' => array_keys($item)
            ]);
        }
        
        // Extrair event_code
        $eventCode = $item['event_code'] 
                  ?? $item['eventCode'] 
                  ?? $item['msgId'] 
                  ?? $item['msg_id']
                  ?? null;
        
        // Extrair timezone (documentado na spec)
        $timezone = $item['timezone'] ?? null;
        
        // Extrair horário: gateTime é o campo primário documentado
        $eventTime = $item['gateway_time'] 
                  ?? $item['gateTime']
                  ?? $item['event_time'] 
                  ?? $item['eventTime'] 
                  ?? $item['gps_time']
                  ?? $item['gpsTime']
                  ?? date('Y-m-d H:i:s');
        
        // Coordenadas
        $latitude = $item['latitude'] ?? $item['lat'] ?? null;
        $longitude = $item['longitude'] ?? $item['lng'] ?? $item['lon'] ?? null;
        
        // Descrição
        $description = $item['description']
                    ?? $item['desc']
                    ?? get_event_name($eventCode ?? $eventType);
        
        // Salvar no banco
        try {
            $stmt = $this->db->prepare("
                INSERT INTO events (
                    imei, event_type, event_code, event_time, 
                    latitude, longitude, event_data, description
                ) VALUES (
                    :imei, :type, :code, :time, 
                    :lat, :lon, :data, :desc
                )
            ");
            
            $stmt->execute([
                ':imei' => $imei,
                ':type' => $eventType,
                ':code' => $eventCode,
                ':time' => $eventTime,
                ':lat' => $latitude,
                ':lon' => $longitude,
                ':data' => json_encode($item, JSON_UNESCAPED_UNICODE),
                ':desc' => $description
            ]);
            
            $this->callProcedure('update_device_stats_after_event', [$imei, $eventTime]);
            
            Logger::info('Event saved', [
                'source' => $this->handlerName, 'imei' => $imei,
                'event_type' => $eventType, 'event_code' => $eventCode,
                'timezone' => $timezone
            ]);
            
            return true;
            
        } catch (PDOException $e) {
            Logger::error('Failed to save event', [
                'source' => $this->handlerName, 'imei' => $imei,
                'error' => $e->getMessage(), 'event_type' => $eventType
            ]);
            return false;
        }
    }
}

$handler = new PushEventHandler();
$handler->handle();
