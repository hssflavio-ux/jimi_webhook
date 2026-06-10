<?php
/**
 * JIMI IoT Hub - Push GPS Handler
 * Endpoint: /pushgps
 * Versão: 2.0.0 (Extração completa de campos alinhada com spec oficial)
 * Referência: Seção 1.3 - Push GPS Data
 */
define('HANDLER_NAME', 'pushgps');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushGPSHandler extends WebhookHandler {
    public function __construct() { 
        parent::__construct(HANDLER_NAME); 
    }
    
    protected function processItem($item) {
        $imei = $this->validateRequired($item, 'imei', 'IMEI');
        
        // Extrair campos documentados com fallback para múltiplos formatos
        $gpsTime        = $item['gps_time']    ?? $item['gpsTime']     ?? date('Y-m-d H:i:s');
        $gatewayTime    = $item['gateway_time'] ?? $item['gateTime']   ?? $item['gate_time'] ?? date('Y-m-d H:i:s');
        $latitude       = $item['latitude']    ?? $item['lat']         ?? null;
        $longitude      = $item['longitude']   ?? $item['lng']         ?? $item['lon'] ?? null;
        $speed          = $item['speed']       ?? $item['gpsSpeed']    ?? 0;
        $direction      = $item['direction']   ?? $item['heading']     ?? 0;
        $satellites     = $item['satellites']  ?? $item['satelliteNum'] ?? 0;
        $gpsMode        = $item['gps_mode']    ?? $item['gpsMode']     ?? 0;
        $gsm            = $item['gsm']         ?? $item['gsmSignal']   ?? 0;
        $mileage        = $item['mileage']     ?? $item['distance']    ?? 0;
        $battery        = $item['battery']     ?? $item['power']       ?? 0;
        $altitude       = $item['altitude']    ?? 0;
        
        // acc: documentado como campo principal; accStatus como alternativo
        $acc            = $item['acc']         ?? $item['accStatus']   ?? 0;
        $deviceStatusCode = $item['device_status_code'] ?? $item['deviceStatusCode'] ?? 0;
        
        // Campos documentados não extraídos anteriormente (novos em v2.0.0)
        $postType       = $item['postType']    ?? null;
        $postMethod     = $item['postMethod']  ?? null;
        $undecodedAddInfo = $item['undecodedGpsAddInfo'] ?? null;
        $driverLicenseStatus = $item['driverLicenseStatus'] ?? null;
        $driverLicense  = $item['driverLicense'] ?? null;
        $buzzerAlarmStatus  = $item['buzzerAlarmStatus']  ?? null;
        $creditCardStatus   = $item['creditCardStatus']   ?? null;
        $doorStatus     = $item['doorStatus']     ?? null;
        $sosStatus      = $item['sosStatus']      ?? $item['sos'] ?? null;
        $temperature    = $item['temperature']    ?? null;
        $transparentData = $item['transparentData'] ?? null;
        
        // Validar coordenadas
        if (!is_valid_coordinate($latitude, $longitude)) {
            Logger::warning('Invalid GPS coordinates', [
                'source' => $this->handlerName,
                'imei' => $imei, 'lat' => $latitude, 'lng' => $longitude
            ]);
            return false;
        }
        
        // Calcular distância desde último ponto GPS
        $distance = $this->calculateDistance($imei, $latitude, $longitude);
        
        // Inserir GPS no banco
        $stmt = $this->db->prepare("
            INSERT INTO gps_data (
                imei, gps_time, gateway_time, 
                latitude, longitude, speed, direction,
                satellites, gps_mode, gsm_signal, mileage,
                battery, distance_from_previous, acc,
                device_status_code, altitude,
                post_type, post_method, undecoded_gps_add_info,
                driver_license_status, driver_license,
                buzzer_alarm_status, credit_card_status,
                door_status, sos_status, temperature, transparent_data,
                raw_data
            ) VALUES (
                :imei, :gps_time, :gateway_time,
                :latitude, :longitude, :speed, :direction,
                :satellites, :gps_mode, :gsm_signal, :mileage,
                :battery, :distance, :acc,
                :device_status_code, :altitude,
                :post_type, :post_method, :undecoded_add_info,
                :driver_license_status, :driver_license,
                :buzzer_alarm_status, :credit_card_status,
                :door_status, :sos_status, :temperature, :transparent_data,
                :raw_data
            )
        ");
        
        $stmt->execute([
            ':imei' => $imei, ':gps_time' => $gpsTime, ':gateway_time' => $gatewayTime,
            ':latitude' => $latitude, ':longitude' => $longitude,
            ':speed' => $speed, ':direction' => $direction,
            ':satellites' => $satellites, ':gps_mode' => $gpsMode,
            ':gsm_signal' => $gsm, ':mileage' => $mileage,
            ':battery' => $battery, ':distance' => $distance, ':acc' => $acc,
            ':device_status_code' => $deviceStatusCode, ':altitude' => $altitude,
            ':post_type' => $postType, ':post_method' => $postMethod,
            ':undecoded_add_info' => $undecodedAddInfo,
            ':driver_license_status' => $driverLicenseStatus,
            ':driver_license' => $driverLicense,
            ':buzzer_alarm_status' => $buzzerAlarmStatus,
            ':credit_card_status' => $creditCardStatus,
            ':door_status' => $doorStatus, ':sos_status' => $sosStatus,
            ':temperature' => $temperature, ':transparent_data' => $transparentData,
            ':raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE)
        ]);
        
        $this->callProcedure('update_device_stats_after_gps', [
            $imei, $gpsTime, $latitude, $longitude,
            $speed, $distance, $gsm, $acc
        ]);
        
        return true;
    }
    
    private function calculateDistance($imei, $lat, $lon) {
        try {
            $stmt = $this->db->prepare("
                SELECT last_latitude, last_longitude 
                FROM device_statistics 
                WHERE imei = :imei LIMIT 1
            ");
            $stmt->execute([':imei' => $imei]);
            $last = $stmt->fetch();
            
            if (!$last || !$last['last_latitude'] || !$last['last_longitude']) return 0;
            if ($last['last_latitude'] == 0 || $last['last_longitude'] == 0) return 0;
            
            $distKm = calculate_distance(
                $last['last_latitude'], $last['last_longitude'], $lat, $lon
            );
            
            // Cutoff de 100km: previne que falhas de GPS (ex: coordenadas 0,0 após
            // reinicialização do dispositivo) contaminem a distância total acumulada.
            // Nenhum veículo terrestre percorre >100km entre pontos consecutivos
            // (intervalo típico de 10-30s entre envios GPS).
            if ($distKm > 100) {
                Logger::warning('GPS jump detected (distance > 100km)', [
                    'source' => $this->handlerName,
                    'imei' => $imei, 'distance_km' => $distKm,
                    'last_lat' => $last['last_latitude'], 'last_lon' => $last['last_longitude'],
                    'curr_lat' => $lat, 'curr_lon' => $lon
                ]);
                return 0;
            }
            
            return round($distKm, 3);
        } catch (Exception $e) {
            Logger::warning('Distance calculation failed', [
                'source' => $this->handlerName,
                'imei' => $imei, 'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}

$handler = new PushGPSHandler();
$handler->handle();
