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
require_once __DIR__ . '/../includes/maintenance.php'; // update_engine_hours()

class PushHeartbeatHandler extends WebhookHandler {
    public function __construct() { parent::__construct(HANDLER_NAME); }
    
    protected function processItem($item) {
        $imei = $this->validateRequired($item, 'imei', 'IMEI');
        
        // Tempo: gateTime é o campo primário documentado; fallback para heartbeat_time / gps_time
        $heartbeatTime = $item['gateway_time'] 
                      ?? $item['heartbeat_time'] 
                      ?? $item['gps_time'] 
                      ?? gmdate('Y-m-d H:i:s');
        
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

        // Horímetro (item 3, v4.10.1) — mesma ressalva de pushgps.php: campo
        // ainda não confirmado contra device real.
        $engineHours = $item['horimetro'] ?? $item['engineHours'] ?? $item['engine_hours'] ?? $item['hourmeter'] ?? null;

        // Snapshot do dono no momento do evento (Fase 2 do fluxo
        // chip→câmera→veículo) — ver resolve_installation_for_imei().
        $ownership = resolve_installation_for_imei($this->db, $imei);

        $stmt = $this->db->prepare("
            INSERT INTO heartbeats
            (imei, customer_id, vehicle_id, heartbeat_time, battery, gsm_signal, acc, oil_ele, gps_pos,
             remote_lock, power_status, fortify, temperature, voltage, status, extra_data)
            VALUES
            (:imei, :customer_id, :vehicle_id, :time, :bat, :gsm, :acc, :oil, :gps,
             :lock, :pwr, :fort, :temp, :volt, :stat, :extra)
        ");
        $stmt->execute([
            ':imei' => $imei,
            ':customer_id' => $ownership['customer_id'],
            ':vehicle_id' => $ownership['vehicle_id'],
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
        
        // 🔴 `$acc` vai junto desde a v4.17.8. Ele era extraído acima, gravado
        // em `heartbeats.acc` e descartado aqui — `device_statistics.last_acc_status`
        // só era atualizado por GPS, então a ignição exibida nas telas tinha a
        // idade do último PONTO, não a da última leitura. Medido em produção:
        // `heartbeats.acc` 100% preenchido (6806/6806 em 2 dias) e a ignição do
        // `400D` atrasada 382 minutos com o valor certo chegando o tempo todo.
        $this->callProcedure('update_device_stats_after_heartbeat', [
            $imei, $heartbeatTime, $battery, $gsmSignal, $acc
        ]);

        update_engine_hours($this->db, $imei, $engineHours);

        return true;
    }
}
$handler = new PushHeartbeatHandler();
$handler->handle();
