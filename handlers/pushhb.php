<?php
/**
 * JIMI IoT Hub - Push Heartbeat Handler
 * Endpoint: /pushhb
 * Versão: 2.0.0 (Extração completa de campos alinhada com spec oficial)
 * Referência: Seção 1.2 - Push Heartbeat Data
 */
define('HANDLER_NAME', 'pushhb');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushHeartbeatHandler extends WebhookHandler {
    public function __construct() { parent::__construct(HANDLER_NAME); }
    
    protected function processItem($item) {
        $imei = $this->validateRequired($item, 'imei', 'IMEI');
        
        // Tempo: gateTime é o campo primário documentado; fallback para heartbeat_time / gps_time
        $heartbeatTime = $item['gateway_time'] 
                      ?? $item['heartbeat_time'] 
                      ?? $item['gps_time'] 
                      ?? date('Y-m-d H:i:s');
        
        // Campos básicos: bateria e sinal GSM
        $battery    = $item['battery']     ?? $item['powerLevel'] ?? null;
        $gsmSignal  = $item['gsm']         ?? $item['gsmSign']    ?? null;
        
        // Status operacionais documentados (Seção 1.2)
        $acc        = $item['acc']         ?? null;   // 0=ACC_OFF, 1=ACC_ON
        $oilEle     = $item['oilEle']      ?? null;   // 0=Conectado, 1=Desconectado
        $gpsPos     = $item['gpsPos']      ?? null;   // 0=Não posicionando, 1=Posicionando
        $remoteLock = $item['remoteLock']  ?? null;   // 0=Sem bloqueio, 1=Bloqueio remoto
        $powerStatus= $item['powerStatus'] ?? null;   // 0=Sem carga, 1=Carregando
        $fortify    = $item['fortify']     ?? null;   // 0=Defesa desativada, 1=Defesa ativada
        
        // Sensores e tensão
        $temperature= $item['temperature'] ?? null;
        $voltage    = $item['voltage']     ?? null;
        $status     = $item['status']      ?? 'NORMAL';
        
        $stmt = $this->db->prepare("
            INSERT INTO heartbeats 
            (imei, heartbeat_time, battery, gsm_signal, acc, oil_ele, gps_pos, 
             remote_lock, power_status, fortify, temperature, voltage, status, extra_data)
            VALUES 
            (:imei, :time, :bat, :gsm, :acc, :oil, :gps, 
             :lock, :pwr, :fort, :temp, :volt, :stat, :extra)
        ");
        $stmt->execute([
            ':imei' => $imei,
            ':time' => $heartbeatTime,
            ':bat'  => $battery,
            ':gsm'  => $gsmSignal,
            ':acc'  => $acc,
            ':oil'  => $oilEle,
            ':gps'  => $gpsPos,
            ':lock' => $remoteLock,
            ':pwr'  => $powerStatus,
            ':fort' => $fortify,
            ':temp' => $temperature,
            ':volt' => $voltage,
            ':stat' => $status,
            ':extra'=> json_encode($item, JSON_UNESCAPED_UNICODE)
        ]);
        
        $this->callProcedure('update_device_stats_after_heartbeat', [
            $imei, $heartbeatTime, $battery, $gsmSignal
        ]);
        
        return true;
    }
}
$handler = new PushHeartbeatHandler();
$handler->handle();
