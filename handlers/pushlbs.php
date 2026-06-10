<?php
/**
 * JIMI IoT Hub - Push LBS Handler
 * Endpoint: /pushlbs
 * Versão: 2.0.0 (Parse de lbsJson + cellList alinhado com spec oficial)
 * Referência: Seção 1.10 - Push LBS Data
 */
define('HANDLER_NAME', 'pushlbs');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushLBSHandler extends WebhookHandler {
    
    public function __construct() {
        parent::__construct(HANDLER_NAME);
    }

    protected function processItem($item) {
        $imei = $item['deviceImei'] ?? $item['imei'] ?? null;
        if (!$imei) {
            Logger::warning('LBS ignorado: IMEI ausente', [
                'source' => $this->handlerName, 'keys' => array_keys($item)
            ]);
            return false;
        }

        // Parse lbsJson (pode vir como string JSON ou array já decodificado)
        $lbsJson = $item['lbsJson'] ?? null;
        if (is_string($lbsJson)) {
            $lbsJson = json_decode($lbsJson, true);
        }
        if (!is_array($lbsJson)) $lbsJson = [];

        $mcc = $lbsJson['mcc'] ?? $item['mcc'] ?? null;
        $mnc = $lbsJson['mnc'] ?? $item['mnc'] ?? null;

        // cellList: "LAC1,CI1,RSSI1;LAC2,CI2,RSSI2;..."
        $cellListRaw = $lbsJson['cellList'] ?? $item['cellList'] ?? '';
        $towers = $this->parseCellList($cellListRaw);

        // Fallback: se não há cellList parseado, tenta os campos top-level (firmwares antigos)
        if (empty($towers) && ($item['lac'] || $item['cellId'])) {
            $towers[] = [
                'lac'    => $item['lac']    ?? null,
                'cellId' => $item['cellId'] ?? null,
                'signal' => $item['signal'] ?? $item['rssi'] ?? null
            ];
        }

        // Coordenadas (resolvidas pela Jimi ou do próprio item)
        $lat = $lbsJson['lat'] ?? $item['lat'] ?? $item['latitude'] ?? null;
        $lng = $lbsJson['lng'] ?? $item['lng'] ?? $item['longitude'] ?? null;
        $address = $lbsJson['address'] ?? $item['address'] ?? null;

        // Tempos
        $lbsTime  = $item['lbsTime']  ?? $item['gpsTime'] ?? $item['time'] ?? date('Y-m-d H:i:s');
        $gateTime = $item['gateTime'] ?? $item['gateway_time'] ?? date('Y-m-d H:i:s');

        $savedCount = 0;
        foreach ($towers as $tower) {
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO lbs_data 
                    (imei, mcc, mnc, lac, cell_id, signal_strength, 
                     lbs_time, gateway_time, latitude, longitude, address, raw_data)
                    VALUES 
                    (:imei, :mcc, :mnc, :lac, :cid, :sig,
                     :ltime, :gtime, :lat, :lng, :addr, :raw)
                ");
                $stmt->execute([
                    ':imei'  => $imei,
                    ':mcc'   => $mcc,
                    ':mnc'   => $mnc,
                    ':lac'   => $tower['lac'],
                    ':cid'   => $tower['cellId'],
                    ':sig'   => $tower['signal'],
                    ':ltime' => $lbsTime,
                    ':gtime' => $gateTime,
                    ':lat'   => $lat,
                    ':lng'   => $lng,
                    ':addr'  => $address,
                    ':raw'   => json_encode($item, JSON_UNESCAPED_UNICODE)
                ]);
                $savedCount++;
            } catch (PDOException $e) {
                Logger::error('Erro SQL LBS: ' . $e->getMessage(), [
                    'source' => $this->handlerName, 'imei' => $imei
                ]);
            }
        }

        // Atualizar última posição se houver coordenadas
        if ($lat && $lng && is_valid_coordinate($lat, $lng)) {
            $this->updateDeviceLocation($imei, $lat, $lng, $lbsTime);
        } else {
            $this->updateDeviceHeartbeat($imei);
        }

        Logger::info('LBS processado', [
            'source' => $this->handlerName, 'imei' => $imei,
            'towers' => $savedCount, 'has_coords' => ($lat && $lng)
        ]);

        return $savedCount > 0;
    }

    /**
     * Parse cellList string: "LAC1,CI1,RSSI1;LAC2,CI2,RSSI2;..."
     */
    private function parseCellList($cellListRaw) {
        $towers = [];
        if (empty($cellListRaw) || !is_string($cellListRaw)) return $towers;

        $entries = explode(';', $cellListRaw);
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if (empty($entry)) continue;

            $parts = explode(',', $entry);
            if (count($parts) >= 3) {
                $towers[] = [
                    'lac'    => trim($parts[0]) !== '' ? (int)$parts[0] : null,
                    'cellId' => trim($parts[1]) !== '' ? (int)$parts[1] : null,
                    'signal' => trim($parts[2]) !== '' ? (int)$parts[2] : null
                ];
            }
        }
        return $towers;
    }

    private function updateDeviceLocation($imei, $lat, $lng, $time) {
        $this->db->prepare("
            INSERT INTO device_statistics (imei, last_latitude, last_longitude, last_gps_time, updated_at)
            VALUES (:imei, :lat, :lng, :time, NOW())
            ON DUPLICATE KEY UPDATE 
                last_latitude = VALUES(last_latitude),
                last_longitude = VALUES(last_longitude),
                last_gps_time = VALUES(last_gps_time),
                updated_at = NOW()
        ")->execute([':imei' => $imei, ':lat' => $lat, ':lng' => $lng, ':time' => $time]);
        $this->updateDeviceHeartbeat($imei);
    }

    private function updateDeviceHeartbeat($imei) {
        $this->db->prepare("
            INSERT INTO devices (imei, last_communication) VALUES (?, NOW()) 
            ON DUPLICATE KEY UPDATE last_communication = NOW()
        ")->execute([$imei]);
    }
}

$handler = new PushLBSHandler();
$handler->handle();
