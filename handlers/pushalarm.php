<?php
/**
 * JIMI IoT Hub - Handler de Alarmes
 * Endpoint: /pushalarm
 * Versão: 2.0.0
 * Referência: Seção 1.4 - Dados de Alarme (JIMI + JT/T)
 * 
 * HERITAGE: Baseado em v6.2 (Strict Protocol Isolation) e v7.0 (Full Column Population)
 * 
 * CHANGELOG v7.0 (histórico):
 *   1. INSERT agora popula TODAS as 45 colunas da tabela alarms
 *   2. decodeStandardAlarm() expandido para 32 bits (era apenas 6)
 *   3. removeAlarmType agora grava alarm_type correto (não mais "removeAlarmType")
 *   4. Extração completa de campos JTT: alarmLabel, alarmLevel, alarmStatus,
 *      fatigueLevel, alarmId, alarmSerialNo, standardAlarmValue,
 *      signalDropChannel, signalCoverChannel, storageFaultChannel,
 *      drivingAlarmFlag, carSpeed, carStatus, direction, altitude
 *   5. Mantém 100% compatibilidade com isolamento de protocolo v6.2
 *   6. Suporte a campos fenceId, driverId, driverName
 */

define('HANDLER_NAME', 'pushalarm');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';
require_once __DIR__ . '/../includes/occurrence_engine.php';

class PushAlarmHandler extends WebhookHandler {
    
    public function __construct() { 
        parent::__construct(HANDLER_NAME);
    }
    
    /**
     * Roteia o item para o processador adequado conforme o tipo (DEVICE, ICCID, VIN).
     *
     * @param array $item Dados normalizados do webhook
     * @return bool Verdadeiro se o item foi processado com sucesso
     */
    protected function processItem($item) {
        $imei = $item['imei'] ?? null;
        
        if (!$imei) {
            Logger::warning('Ignorado: IMEI ausente', ['source' => $this->handlerName, 'payload' => $item]);
            return false;
        }

        // --- ROTEAMENTO ---
        $type = strtoupper($item['type'] ?? 'DEVICE');
        if ($type === 'ICCID') return $this->processUpdate($imei, 'iccid', $item);
        if ($type === 'VIN')   return $this->processUpdate($imei, 'vin', $item);

        return $this->processAlarm($imei, $item);
    }

    /**
     * Processa um alarme de dispositivo com isolamento estrito de protocolo (JIMI ou JT/T).
     *
     * Extrai todos os 45 campos da tabela alarms, tratando variações de chave entre
     * versões de firmware. Suporta remoção de alarme (removeAlarmType) e decodificação
     * de bitmask JT/T 808 para alarmes do tipo 256.
     *
     * @param string $imei IMEI do dispositivo
     * @param array  $item Dados normalizados do webhook
     * @return bool Verdadeiro se o alarme foi persistido com sucesso
     * @throws PDOException Em caso de falha na inserção no banco (capturada internamente)
     */
    private function processAlarm($imei, $item) {
        // =====================================================================
        // DEFINIÇÃO ESTRITA DE PROTOCOLO (Heritage v6.2)
        // =====================================================================
        $msgClass = isset($item['msgClass']) ? (int)$item['msgClass'] : 0;
        $protocol = ($msgClass == 0) ? 'JIMI' : 'JTT';
        $msg = $item['msg'] ?? [];
        
        // =====================================================================
        // 1. EXTRAÇÃO DE TIPO DE ALARME
        // =====================================================================
        $rawAlertType = $msg['alertType'] ?? null;
        $rawAlarmType = $msg['alarmType'] ?? null;
        $rawType = $rawAlertType ?? $rawAlarmType ?? null;
        
        // Tratamento 'removeAlarmType' - CORREÇÃO v7.0: Grava código correto
        $isRemoval = false;
        if ($rawType === 'removeAlarmType') {
            $isRemoval = true;
            // O código real está em msg.removeAlarmType
            $mainType = $msg['removeAlarmType'] ?? null;
            if ($mainType === null || $mainType === '') {
                Logger::warning('removeAlarmType sem código real', ['source' => $this->handlerName, 'imei' => $imei, 'msg' => $msg]);
                $mainType = '0';
            }
        } else {
            $mainType = $rawType;
        }

        if ($mainType === null || $mainType === '') {
            Logger::warning('Ignorado: Pacote sem alertType', ['source' => $this->handlerName, 'imei' => $imei]);
            return false;
        }

        // =====================================================================
        // 2. EXTRAÇÃO DE CAMPOS DETALHADOS
        // =====================================================================
        $subType        = null;
        $alarmLabel     = null;
        $alarmId        = null;
        $alarmSerialNo  = null;
        $standardAlmVal = null;
        $alarmLevel     = null;
        $alarmStatus    = null;
        $fatigueLevel   = null;
        $alertValue     = $msg['alertValue'] ?? '0';
        $fenceId        = $msg['fenceId'] ?? $msg['fence_id'] ?? null;
        $driverId       = $msg['driverId'] ?? $msg['driver_id'] ?? null;
        $driverName     = $msg['driverName'] ?? $msg['driver_name'] ?? null;

        // Campos de vídeo JTT
        $signalDropChannel    = null;
        $signalCoverChannel   = null;
        $storageFaultChannel  = null;
        $drivingAlarmFlag     = null;

        // Campos GPS/Veículo
        $carSpeed    = $msg['carSpeed'] ?? null;
        $carStatus   = $msg['carStatus'] ?? null;
        $direction   = $msg['direction'] ?? $msg['heading'] ?? null;
        $altitude    = $msg['altitude'] ?? null;
        $satNum      = $msg['satelliteNum'] ?? $msg['satellite_num'] ?? null;
        $gpsNum      = $msg['gpsNum'] ?? $msg['gps_num'] ?? null;
        $gpsMode     = $msg['gpsMode'] ?? $msg['gps_mode'] ?? null;
        $voltage     = $msg['voltage'] ?? $msg['batteryVoltage'] ?? null;
        $version     = $msg['version'] ?? null;
        $description = $msg['description'] ?? null;

        // Tempos
        $alarmTime   = $msg['alarmTime'] ?? date('Y-m-d H:i:s');
        $gpsTime     = $item['gps_time'] ?? $msg['gpsTime'] ?? null;
        $gatewayTime = $item['gateway_time'] ?? $item['gateTime'] ?? null;

        // GPS
        $lat   = $msg['lat'] ?? $msg['latitude'] ?? null;
        $lng   = $msg['lng'] ?? $msg['longitude'] ?? null;
        $speed = $msg['gpsSpeed'] ?? $msg['speed'] ?? 0;

        // Arquivo/Mídia
        $fileUrl  = $msg['file'] ?? $msg['fileUrl'] ?? $msg['mediaUrl'] ?? null;
        $fileType = null;
        if ($fileUrl) {
            if (preg_match('/\.(mp4|avi|flv|mkv)/i', $fileUrl)) $fileType = 'video';
            elseif (preg_match('/\.(jpg|jpeg|png|bmp)/i', $fileUrl)) $fileType = 'image';
            elseif (preg_match('/\.(mp3|wav|aac)/i', $fileUrl)) $fileType = 'audio';
        }

        // =====================================================================
        // 3. CAMPOS ESPECÍFICOS JTT (Heritage v6.2 + Expansão v7.0)
        // =====================================================================
        if ($msgClass == 1) {
            $alarmLabel    = $msg['alarmLabel'] ?? $msg['alarmId'] ?? null;
            $alarmId       = $msg['alarmId'] ?? null;
            $alarmSerialNo = isset($msg['alarmSerialNo']) ? (int)$msg['alarmSerialNo'] : null;
            $standardAlmVal = isset($msg['standardAlarmValue']) ? (int)$msg['standardAlarmValue'] : null;
            $alarmLevel    = isset($msg['alarmLevel']) ? (int)$msg['alarmLevel'] : null;
            $alarmStatus   = isset($msg['alarmStatus']) ? (int)$msg['alarmStatus'] : null;
            $fatigueLevel  = isset($msg['fatigueLevel']) ? (int)$msg['fatigueLevel'] : null;

            // Campos de canais de vídeo (alertType 257, 258, 259)
            $signalDropChannel   = $msg['signalDropChannel'] ?? $msg['signalLossChannel'] ?? null;
            $signalCoverChannel  = $msg['signalCoverChannel'] ?? $msg['signalBlockChannel'] ?? null;
            $storageFaultChannel = $msg['storageFaultChannel'] ?? $msg['storageErrorChannel'] ?? null;
            $drivingAlarmFlag    = isset($msg['drivingAlarmFlag']) ? (int)$msg['drivingAlarmFlag'] : null;

            // Extração de subtipo para alarmes compostos (264-X, 265-X, 266-X)
            if (!$isRemoval && $rawAlarmType !== null && strval($rawAlarmType) !== strval($mainType)) {
                $subType = intval($rawAlarmType);
            }
        }

        // =====================================================================
        // 4. RESOLUÇÃO DE NOME (BLINDADA - Heritage v6.2)
        // =====================================================================
        $resolved = $this->resolveAlarmName($mainType, $subType, $msgClass, $standardAlmVal);
        $finalName = $resolved['name'];
        
        if ($resolved['virtual_subtype'] !== null) {
            $subType = $resolved['virtual_subtype'];
        }

        $alarmName = $isRemoval ? "Fim de Alarme: $finalName" : $finalName;

        // Standard alarm bitmask para coluna dedicada
        $standardBitmask = null;
        if ($msgClass == 1 && strval($mainType) === '256' && $standardAlmVal !== null) {
            $standardBitmask = (int)$standardAlmVal;
        }
        
        // =====================================================================
        // 5. PERSISTÊNCIA COMPLETA (TODAS as 45 colunas)
        // =====================================================================
        try {
            $sql = "INSERT INTO alarms (
                        imei, alarm_type, alert_type, alarm_subtype, 
                        standard_alarm_bitmask, alarm_name, alert_value,
                        alarm_serial_no, msg_class, fence_id, 
                        driver_id, driver_name,
                        alarm_time, gps_time, gateway_time,
                        latitude, longitude, speed, car_speed, car_status,
                        satellite_num, gps_num, gps_mode, direction, altitude,
                        status, file_url, file_type, alarm_data,
                        alarm_level, alarm_status, fatigue_level,
                        alarm_id, alarm_label, standard_alarm_value,
                        signal_drop_channel, signal_cover_channel, 
                        storage_fault_channel, driving_alarm_flag,
                        version, description, voltage,
                        raw_data, created_at
                    ) VALUES (
                        :imei, :alarm_type, :alert_type, :alarm_subtype,
                        :std_bitmask, :alarm_name, :alert_value,
                        :alarm_serial_no, :msg_class, :fence_id,
                        :driver_id, :driver_name,
                        :alarm_time, :gps_time, :gateway_time,
                        :lat, :lng, :speed, :car_speed, :car_status,
                        :sat_num, :gps_num, :gps_mode, :direction, :altitude,
                        :status, :file_url, :file_type, :alarm_data,
                        :alarm_level, :alarm_status, :fatigue_level,
                        :alarm_id, :alarm_label, :std_alarm_value,
                        :sig_drop, :sig_cover,
                        :storage_fault, :driving_flag,
                        :version, :description, :voltage,
                        :raw_data, NOW()
                    )";
            
            // Alarm data JSON (campos extras não mapeados diretamente)
            $alarmData = null;
            $extraFields = array_diff_key($msg, array_flip([
                'lat','lng','latitude','longitude','gpsSpeed','speed',
                'alarmTime','alertType','alarmType','alertValue',
                'deviceImei','alarmLabel','alarmId','alarmSerialNo',
                'standardAlarmValue','alarmLevel','alarmStatus','fatigueLevel',
                'carSpeed','carStatus','direction','heading','altitude',
                'satelliteNum','satellite_num','gpsNum','gps_num',
                'gpsMode','gps_mode','voltage','batteryVoltage',
                'version','description','file','fileUrl','mediaUrl',
                'fenceId','fence_id','driverId','driver_id','driverName','driver_name',
                'signalDropChannel','signalLossChannel','signalCoverChannel',
                'signalBlockChannel','storageFaultChannel','storageErrorChannel',
                'drivingAlarmFlag','removeAlarmType'
            ]));
            if (!empty($extraFields)) {
                $alarmData = json_encode($extraFields, JSON_UNESCAPED_UNICODE);
            }

            $stmt = $this->db->prepare($sql);
            $insertedAlarmId = 0; // capturado logo após o INSERT — CALL de procedure reseta lastInsertId()
            $stmt->execute([
                ':imei'            => $imei,
                ':alarm_type'      => (string)$mainType,
                ':alert_type'      => $rawAlertType !== null ? (string)$rawAlertType : null,
                ':alarm_subtype'   => $subType,
                ':std_bitmask'     => $standardBitmask,
                ':alarm_name'      => $alarmName,
                ':alert_value'     => (string)$alertValue,
                ':alarm_serial_no' => $alarmSerialNo,
                ':msg_class'       => $msgClass,
                ':fence_id'        => $fenceId,
                ':driver_id'       => $driverId,
                ':driver_name'     => $driverName,
                ':alarm_time'      => $alarmTime,
                ':gps_time'        => $gpsTime,
                ':gateway_time'    => $gatewayTime,
                ':lat'             => $lat,
                ':lng'             => $lng,
                ':speed'           => $speed,
                ':car_speed'       => $carSpeed,
                ':car_status'      => $carStatus,
                ':sat_num'         => $satNum,
                ':gps_num'         => $gpsNum,
                ':gps_mode'        => $gpsMode,
                ':direction'       => $direction,
                ':altitude'        => $altitude,
                ':status'          => $isRemoval ? 'resolved' : 'active',
                ':file_url'        => $fileUrl,
                ':file_type'       => $fileType,
                ':alarm_data'      => $alarmData,
                ':alarm_level'     => $alarmLevel,
                ':alarm_status'    => $alarmStatus,
                ':fatigue_level'   => $fatigueLevel,
                ':alarm_id'        => $alarmId,
                ':alarm_label'     => $alarmLabel,
                ':std_alarm_value' => $standardAlmVal,
                ':sig_drop'        => $signalDropChannel,
                ':sig_cover'       => $signalCoverChannel,
                ':storage_fault'   => $storageFaultChannel,
                ':driving_flag'    => $drivingAlarmFlag,
                ':version'         => $version,
                ':description'     => $description,
                ':voltage'         => $voltage,
                ':raw_data'        => json_encode($item, JSON_UNESCAPED_UNICODE)
            ]);
            $insertedAlarmId = (int)$this->db->lastInsertId();

            // Atualizar estatísticas do dispositivo via stored procedure
            $hasCoords = ($lat && $lng && $lat != 0 && $lng != 0 && !$isRemoval);
            $this->callProcedure('update_device_stats_after_alarm', [
                $imei, $alarmTime,
                $hasCoords ? $lat : null,
                $hasCoords ? $lng : null
            ]);

            if (!$isRemoval && $insertedAlarmId > 0) {
                try {
                    process_alarm_to_occurrence([
                        'id'          => $insertedAlarmId,
                        'imei'        => $imei,
                        'alarm_type'  => (string)$mainType,
                        'alarm_time'  => $alarmTime,
                        'alarm_name'  => $finalName,
                        'driver_id'   => $driverId,
                        'driver_name' => $driverName,
                        'lat'         => $lat,
                        'lng'         => $lng,
                        'file_url'    => $fileUrl,
                    ]);
                } catch (Exception $e) {
                    Logger::error('Occurrence Engine Error: ' . $e->getMessage(), [
                        'source' => $this->handlerName,
                        'imei' => $imei,
                        'alarm_id' => $insertedAlarmId,
                    ]);
                }
            }

            Logger::info("Alarme Gravado: $alarmName", [
                'source'   => $this->handlerName,
                'imei'     => $imei, 
                'protocol' => $protocol,
                'code'     => $mainType,
                'subtype'  => $subType,
                'removal'  => $isRemoval
            ]);
            
            return true;

        } catch (PDOException $e) {
            Logger::error("Erro SQL PushAlarm: " . $e->getMessage(), [
                'source' => $this->handlerName,
                'imei' => $imei,
                'type' => $mainType
            ]);
            return false;
        }
    }

    // ==========================================================================
    // RESOLUÇÃO DE NOME (Heritage v6.2 - Isolamento Total de Protocolo)
    // ==========================================================================
    /**
     * Resolve o nome legível de um alarme consultando a tabela alarm_types com
     * isolamento estrito de protocolo. Trata casos especiais: bitmask JT/T 256,
     * subtipos compostos (264-X, 265-X, 266-X) e fallback para código não mapeado.
     *
     * @param string   $mainType    Código principal do alarme
     * @param int|null $subType     Subtipo hierárquico (ADAS, DMS, BSD)
     * @param int      $msgClass    0=JIMI, 1=JT/T
     * @param int|null $standardVal Valor do bitmask para alarmes JT/T 256
     * @return array ['name' => string, 'virtual_subtype' => int|null]
     */
    private function resolveAlarmName($mainType, $subType, $msgClass, $standardVal = null) {
        $protocol = ($msgClass == 0) ? 'JIMI' : 'JTT';
        $searchCode = strval($mainType);
        
        // Regra Especial JTT 256: Decodificar bitmask
        if ($msgClass == 1 && $searchCode === '256' && $standardVal !== null) {
            $bitName = $this->decodeStandardAlarm($standardVal);
            if ($bitName) {
                return ['name' => $bitName, 'virtual_subtype' => (int)$standardVal];
            }
        }

        // Regra Especial JTT Subtipos (264-X, 265-X, 266-X)
        if ($msgClass == 1 && $subType !== null) {
            $composite = "{$mainType}-{$subType}";
            $name = $this->dbQueryName($composite, 'JTT');
            if ($name) return ['name' => $name, 'virtual_subtype' => null];
        }
        
        // Busca Simples com Isolamento de Protocolo
        $name = $this->dbQueryName($searchCode, $protocol);
        if ($name) return ['name' => $name, 'virtual_subtype' => null];

        // Fallback: Nome não resolvido
        $suffix = ($subType !== null) ? "-$subType" : "";
        return [
            'name' => "Código {$searchCode}{$suffix} ($protocol)", 
            'virtual_subtype' => null
        ];
    }

    // ==========================================================================
    // DECODIFICAÇÃO BITMASK JT/T 808 STANDARD ALARM (32 bits completos)
    // CORREÇÃO v7.0: Expandido de 6 para 30 bits
    // ==========================================================================
    /**
     * Decodifica o bitmask de 32 bits do alarme padrão JT/T 808.
     * Cada bit representa um tipo de alarme específico (emergência, velocidade, fadiga, etc.).
     * Bits ativos são concatenados com " + ".
     *
     * @param int $val Valor do bitmask (0 a 2^32-1)
     * @return string Nome legível dos alarmes ativos ou descrição com os bits brutos
     */
    private function decodeStandardAlarm($val) {
        $val = intval($val);
        
        // Mapa completo de bits JT/T 808-2019 Standard Alarm
        $bitMap = [
            0  => 'Emergência / SOS',
            1  => 'Excesso de Velocidade',
            2  => 'Fadiga de Condução',
            3  => 'Pré-aviso de Perigo',
            4  => 'Falha Módulo GNSS',
            5  => 'Antena GNSS Desconectada',
            6  => 'Antena GNSS Curto-circuito',
            7  => 'Subtensão Alimentação Principal',
            8  => 'Alimentação Principal Cortada',
            9  => 'Falha Display LCD',
            10 => 'Falha Módulo TTS',
            11 => 'Falha de Câmera',
            15 => 'Condução Acumulada Excedida (Dia)',
            18 => 'Pré-aviso de Velocidade',
            19 => 'Entrada/Saída de Geocerca',
            20 => 'Desvio de Rota',
            21 => 'Tempo de Condução em Via Excedido',
            22 => 'Falha VSS do Veículo',
            23 => 'Anomalia de Combustível',
            24 => 'Furto de Veículo',
            25 => 'Ignição Não Autorizada',
            26 => 'Deslocamento Não Autorizado',
            27 => 'Pré-aviso de Colisão',
            28 => 'Pré-aviso de Capotamento',
            29 => 'Abertura Irregular de Porta',
        ];

        // Decodificar todos os bits ativos
        $activeAlarms = [];
        foreach ($bitMap as $bit => $name) {
            if ($val & (1 << $bit)) {
                $activeAlarms[] = $name;
            }
        }

        if (!empty($activeAlarms)) {
            return implode(' + ', $activeAlarms);
        }

        // Bits não mapeados
        return "Alarme Standard (Bits: $val)";
    }

    // ==========================================================================
    // CONSULTA AO BANCO COM ISOLAMENTO DE PROTOCOLO (Heritage v6.2)
    // ==========================================================================
    /**
     * Consulta o nome em português de um código de alarme na tabela alarm_types,
     * filtrando por protocolo (JIMI ou JTT).
     *
     * @param string $code     Código do alarme
     * @param string $protocol 'JIMI' ou 'JTT'
     * @return string|false Nome do alarme ou false se não encontrado
     */
    private function dbQueryName($code, $protocol) {
        $stmt = $this->db->prepare(
            "SELECT alarm_name_pt FROM alarm_types WHERE alarm_code = ? AND protocol = ? LIMIT 1"
        );
        $stmt->execute([$code, $protocol]);
        return $stmt->fetchColumn();
    }
    
    // ==========================================================================
    // PROCESSAMENTO DE ATUALIZAÇÕES (ICCID, VIN)
    // ==========================================================================
    /**
     * Processa atualizações de ICCID ou VIN do dispositivo, registrando a
     * comunicação na tabela devices.
     *
     * @param string $imei  IMEI do dispositivo
     * @param string $field 'iccid' ou 'vin'
     * @param array  $item  Dados normalizados do webhook
     * @return bool Verdadeiro se o valor foi encontrado e o dispositivo atualizado
     */
    private function processUpdate($imei, $field, $item) {
        $val = $item['msg'][strtoupper($field)] ?? $item['msg'][$field] ?? null;
        if ($val) {
            $this->db->prepare(
                "INSERT INTO devices (imei, last_communication) 
                 VALUES (?, NOW()) 
                 ON DUPLICATE KEY UPDATE last_communication=NOW()"
            )->execute([$imei]);
            return true;
        }
        return false;
    }
}

$handler = new PushAlarmHandler();
$handler->handle();

// Pós-commit (ainda em background do FPM): despacha as solicitações
// automáticas de vídeo de evento agendadas pelo motor de ocorrências.
// Fora da transação — o IoTHub pode segurar a resposta por até 35s.
flush_pending_video_requests();
