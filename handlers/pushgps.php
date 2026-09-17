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
require_once __DIR__ . '/../includes/maintenance.php'; // update_engine_hours()

class PushGPSHandler extends WebhookHandler {
    /** Cache por requisição: evita reconsultar o mesmo motorista a cada item do lote. */
    private array $driverCache = [];

    public function __construct() {
        parent::__construct(HANDLER_NAME);
    }

    /**
     * Casa o identificador de motorista enviado pelo equipamento com o cadastro
     * local (`drivers.identifier`). A resolução em si mora em
     * `resolve_driver_by_identifier()` (`includes/functions.php`, v4.19.0) —
     * ponto único compartilhado com `pushalarm.php`; este wrapper só mantém o
     * cache por request (evita reconsultar o mesmo motorista a cada item do lote).
     *
     * @param string|int|null $identificador Valor de driverId vindo do device
     * @param string|null     $nome          Valor de driverName (só para log)
     * @returns int|null drivers.id, ou null se não houver correspondência
     */
    private function resolveDriverId($identificador, ?string $nome): ?int
    {
        $chave = is_string($identificador) ? trim($identificador) : (string)($identificador ?? '');
        if (array_key_exists($chave, $this->driverCache)) {
            return $this->driverCache[$chave];
        }
        $id = resolve_driver_by_identifier($this->db, $identificador, $nome);
        $this->driverCache[$chave] = $id;
        return $id;
    }

    protected function processItem($item) {
        $imei = $this->validateRequired($item, 'imei', 'IMEI');
        
        // Extrair campos documentados com fallback para múltiplos formatos
        $gpsTime        = $item['gps_time']    ?? $item['gpsTime']     ?? gmdate('Y-m-d H:i:s');
        $gatewayTime    = $item['gateway_time'] ?? $item['gateTime']   ?? $item['gate_time'] ?? gmdate('Y-m-d H:i:s');
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
        // Bitmask de status (doc oficial §1.3, "Description of status
        // Parameters") — grava CRU, sem decodificar bit a bit, mesmo padrão
        // já usado para device_status_code (nenhum decoder em uso ainda;
        // tabela de bits completa documentada na migração v4.21.5).
        $statusBits     = $item['status']      ?? null;
        $undecodedAddInfo = $item['undecodedGpsAddInfo'] ?? null;
        $driverLicenseStatus = $item['driverLicenseStatus'] ?? null;
        $driverLicense  = $item['driverLicense'] ?? null;

        // Snapshot do dono no momento do evento (Fase 2 do fluxo
        // chip→câmera→veículo) — ver resolve_installation_for_imei(). A
        // LEITURA nunca reconsulta isto; lê a coluna gravada aqui. Subiu pra
        // antes do bloco de motorista (v4.19.0) porque o fallback de sessão
        // logo abaixo precisa de `vehicle_id`.
        $ownership = resolve_installation_for_imei($this->db, $imei);

        // ── Motorista junto com a posição (v4.8.0) ──────────────
        // A doc oficial da Jimi confirma `driverId`/`driverName` viajando ao
        // lado das coordenadas nos dois protocolos, e o pushalarm.php já os
        // consome. Aqui o caminho fica PRÉ-PROGRAMADO: se o equipamento mandar,
        // grava.
        $driverIdRaw = $item['driverId']   ?? $item['driver_id']   ?? null;
        $driverName  = $item['driverName'] ?? $item['driver_name'] ?? null;
        $driverId    = $this->resolveDriverId($driverIdRaw, $driverName);

        // v4.19.0 — na prática esta família de câmera NUNCA manda driverId no
        // GPS (só no alarme de reconhecimento facial, alertType 6 do JT/T).
        // Sem isto, `gps_data.driver_id` ficava sempre NULL e o nível 1 do
        // COALESCE de rel_posicoes.php nunca resolvia nada de verdade. Herda o
        // motorista da SESSÃO corrente do veículo — a mesma que o
        // reconhecimento mantém aberta até trocar de motorista ou a ignição
        // desligar. `driver_name` continua null neste caminho: não é o que o
        // device mandou.
        if ($driverId === null && $ownership['vehicle_id'] !== null) {
            $session = get_open_driver_session_for_vehicle($this->db, $ownership['vehicle_id']);
            if ($session !== null) $driverId = (int)$session['driver_id'];
        }

        $buzzerAlarmStatus  = $item['buzzerAlarmStatus']  ?? null;
        $creditCardStatus   = $item['creditCardStatus']   ?? null;
        $doorStatus     = $item['doorStatus']     ?? null;
        $sosStatus      = $item['sosStatus']      ?? $item['sos'] ?? null;
        $temperature    = $item['temperature']    ?? null;
        $transparentData = $item['transparentData'] ?? null;

        // Horímetro (item 3, v4.10.1) — nome de campo NÃO confirmado contra
        // device real ainda (docs/PLANO_IMPLEMENTACAO_v4.10.md §Pendências).
        // Tenta os nomes mais prováveis; se nenhum vier, update_engine_hours()
        // é chamado com null e não faz nada — sem risco para a ingestão.
        $engineHours = $item['horimetro'] ?? $item['engineHours'] ?? $item['engine_hours'] ?? $item['hourmeter'] ?? null;
        
        // Validar coordenadas
        if (!is_valid_coordinate($latitude, $longitude)) {
            Logger::warning('Invalid GPS coordinates', [
                'source' => $this->handlerName,
                'imei' => $imei, 'lat' => $latitude, 'lng' => $longitude
            ]);
            return false;
        }
        
        // Calcular distância desde último ponto GPS (hodômetro — v4.21.7)
        $distance = $this->calculateDistance($imei, $mileage, $gpsTime);

        // Inserir GPS no banco
        $stmt = $this->db->prepare("
            INSERT INTO gps_data (
                imei, customer_id, vehicle_id, gps_time, gateway_time,
                latitude, longitude, speed, direction,
                satellites, gps_mode, gsm_signal, mileage,
                battery, distance_from_previous, acc,
                device_status_code, status_bits, altitude,
                post_type, post_method, undecoded_gps_add_info,
                driver_license_status, driver_license,
                driver_id, driver_name,
                buzzer_alarm_status, credit_card_status,
                door_status, sos_status, temperature, transparent_data,
                raw_data
            ) VALUES (
                :imei, :customer_id, :vehicle_id, :gps_time, :gateway_time,
                :latitude, :longitude, :speed, :direction,
                :satellites, :gps_mode, :gsm_signal, :mileage,
                :battery, :distance, :acc,
                :device_status_code, :status_bits, :altitude,
                :post_type, :post_method, :undecoded_add_info,
                :driver_license_status, :driver_license,
                :driver_id, :driver_name,
                :buzzer_alarm_status, :credit_card_status,
                :door_status, :sos_status, :temperature, :transparent_data,
                :raw_data
            )
        ");

        $stmt->execute([
            ':imei' => $imei,
            ':customer_id' => $ownership['customer_id'], ':vehicle_id' => $ownership['vehicle_id'],
            ':gps_time' => $gpsTime, ':gateway_time' => $gatewayTime,
            ':latitude' => $latitude, ':longitude' => $longitude,
            ':speed' => $speed, ':direction' => $direction,
            ':satellites' => $satellites, ':gps_mode' => $gpsMode,
            ':gsm_signal' => $gsm, ':mileage' => $mileage,
            ':battery' => $battery, ':distance' => $distance, ':acc' => $acc,
            ':device_status_code' => $deviceStatusCode, ':status_bits' => $statusBits,
            ':altitude' => $altitude,
            ':post_type' => $postType, ':post_method' => $postMethod,
            ':undecoded_add_info' => $undecodedAddInfo,
            ':driver_license_status' => $driverLicenseStatus,
            ':driver_license' => $driverLicense,
            ':driver_id' => $driverId,
            ':driver_name' => $driverName,
            ':buzzer_alarm_status' => $buzzerAlarmStatus,
            ':credit_card_status' => $creditCardStatus,
            ':door_status' => $doorStatus, ':sos_status' => $sosStatus,
            ':temperature' => $temperature, ':transparent_data' => $transparentData,
            ':raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE)
        ]);
        
        // v4.19.0 — ANTES da procedure de propósito: precisa ler o
        // `last_acc_status` ANTIGO (a procedure abaixo sobrescreve) pra
        // detectar a transição 1→0 e fechar a sessão de motorista do veículo.
        driver_session_handle_acc_reading($this->db, $imei, $gpsTime, $acc);

        $this->callProcedure('update_device_stats_after_gps', [
            $imei, $gpsTime, $latitude, $longitude,
            $speed, $distance, $gsm, $acc
        ]);

        update_engine_hours($this->db, $imei, $engineHours);

        return true;
    }
    
    /**
     * Distância deste ponto desde o anterior — hodômetro real do equipamento
     * (`gps_data.mileage`), não GPS (v4.21.7, pedido do dono do produto:
     * basear todo cálculo de deslocamento no hodômetro). Alimenta tanto
     * `gps_data.distance_from_previous` quanto o acumulador
     * `device_statistics.total_distance_km` (stored procedure
     * `update_device_stats_after_gps`, inalterada — só o valor que ela
     * recebe mudou de fonte).
     *
     * Busca a última leitura de mileage > 0 ANTERIOR (por gps_time, não por
     * ordem de chegada — pontos atrasados existem, ver CLAUDE.md) a este
     * ponto; sem base de comparação ou sem leitura válida (mileage <= 0,
     * modelo que sempre manda zerado), devolve 0 — sem fallback para GPS.
     * `mileage` só cresce: delta negativo (reset do sensor) também vira 0.
     *
     * @param string $imei
     * @param mixed  $mileage Leitura crua (metros) deste ponto
     * @param string $gpsTime Timestamp deste ponto (UTC)
     * @returns float Km percorridos desde o ponto anterior válido, ou 0
     */
    private function calculateDistance($imei, $mileage, $gpsTime) {
        try {
            $mileage = (float)$mileage;
            if ($mileage <= 0) return 0;

            $stmt = $this->db->prepare("
                SELECT mileage FROM gps_data
                WHERE imei = :imei AND gps_time < :gps_time AND mileage > 0
                ORDER BY gps_time DESC LIMIT 1
            ");
            $stmt->execute([':imei' => $imei, ':gps_time' => $gpsTime]);
            $lastMileage = $stmt->fetchColumn();

            if ($lastMileage === false) return 0;

            $deltaKm = odometer_delta_km($lastMileage, $mileage);
            return $deltaKm ?? 0;
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
