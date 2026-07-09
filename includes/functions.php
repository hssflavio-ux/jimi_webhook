<?php
/**
 * JIMI IoT Hub — Funções Utilitárias
 * Versão: 2.0.0
 *
 * Biblioteca de funções compartilhadas por todos os handlers.
 * Fornece: normalização de dados, parsing de webhook, validação de
 * coordenadas, cálculo de distância (Haversine), sanitização de datas
 * e detecção de tipo de mídia.
 */

/**
 * Normaliza as chaves de um item do webhook de camelCase para snake_case.
 * Mapeia aliases comuns da API Jimi para nomes padronizados internos.
 *
 * A normalização é necessária porque a API oficial da Jimi usa camelCase
 * (ex: deviceImei, gpsTime) enquanto o banco de dados e código interno
 * utilizam snake_case (ex: imei, gps_time).
 *
 * @param array $item Dados brutos do item do webhook
 * @return array Item com chaves normalizadas
 *
 * @example
 * $item = ['deviceImei' => '123', 'gpsTime' => '2026-01-01 12:00:00'];
 * $normalized = normalize_data($item);
 * // Retorna: ['deviceImei' => '123', 'imei' => '123', 'gpsTime' => '...', 'gps_time' => '...', 'gateway_time' => '...']
 */
function normalize_data($item) {
    if (!is_array($item)) return [];
    $map = ['deviceImei' => 'imei', 'lat' => 'latitude', 'lng' => 'longitude', 'lon' => 'longitude', 'gpsSpeed' => 'speed', 'heading' => 'direction', 'gpsTime' => 'gps_time', 'gateTime' => 'gateway_time', 'alarmTime' => 'alarm_time', 'eventTime' => 'event_time', 'alarmType' => 'alarm_type', 'eventType' => 'event_type', 'satelliteNum' => 'satellites', 'gsmSignal' => 'gsm', 'power' => 'battery', 'msgId' => 'msg_id'];
    foreach ($map as $alias => $standard) {
        if (isset($item[$alias]) && !isset($item[$standard])) $item[$standard] = $item[$alias];
    }
    if (empty($item['gps_time'])) $item['gps_time'] = $item['time'] ?? date('Y-m-d H:i:s');
    if (empty($item['gateway_time'])) $item['gateway_time'] = date('Y-m-d H:i:s');
    return $item;
}

/**
 * Extrai os dados do webhook a partir da requisição HTTP recebida.
 * Aceita tanto JSON no corpo da requisição quanto POST form-urlencoded
 * com o parâmetro data_list codificado como string JSON.
 *
 * Preserva todos os campos POST (token, msgType, data_list, etc.)
 * para que handlers possam acessar metadados via $this->requestMeta.
 *
 * @return array Array associativo com 'token', 'data_list' e demais campos POST
 *
 * @example
 * // POST: token=abc&data_list=[{"deviceImei":"123"}]
 * $data = get_webhook_data();
 * // Retorna: ['token' => 'abc', 'data_list' => [['deviceImei' => '123']]]
 */
function get_webhook_data() {
    $rawInput = file_get_contents('php://input');
    $json = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) return $json;
    if (!empty($_POST)) {
        $data = $_POST;
        if (isset($_POST['data_list'])) {
            $decoded = json_decode($_POST['data_list'], true);
            $data['data_list'] = is_array($decoded) ? $decoded : [];
        }
        return $data;
    }
    return ['data_list' => [], 'raw_input' => $rawInput];
}

/**
 * Valida se as coordenadas GPS estão dentro dos limites geográficos válidos
 * e não são o ponto nulo (0,0). Rejeita coordenadas (0,0) pois são o valor
 * padrão enviado por dispositivos sem fix GPS.
 *
 * @param float $lat Latitude (-90 a 90)
 * @param float $lng Longitude (-180 a 180)
 * @return bool Verdadeiro se as coordenadas são válidas
 */
function is_valid_coordinate($lat, $lng) {
    $lat = floatval($lat);
    $lng = floatval($lng);
    return ($lat >= -90 && $lat <= 90) && ($lng >= -180 && $lng <= 180) && ($lat != 0 || $lng != 0);
}

/**
 * Calcula a distância em quilômetros entre dois pontos geográficos
 * utilizando a fórmula de Haversine.
 *
 * Retorna 0 quando: coordenadas de origem ou destino são (0,0),
 * ou quando os pontos são idênticos. O resultado é estabilizado
 * com clamp de acos() para evitar NaN em pontos muito próximos.
 *
 * @param float $lat1 Latitude do ponto de origem
 * @param float $lon1 Longitude do ponto de origem
 * @param float $lat2 Latitude do ponto de destino
 * @param float $lon2 Longitude do ponto de destino
 * @return float Distância em quilômetros (0 se pontos iguais ou inválidos)
 */
function calculate_distance($lat1, $lon1, $lat2, $lon2) {
    $lat1 = floatval($lat1);
    $lon1 = floatval($lon1);
    $lat2 = floatval($lat2);
    $lon2 = floatval($lon2);
    if ($lat1 == 0 || $lat2 == 0 || ($lat1 == $lat2 && $lon1 == $lon2)) return 0;
    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    if ($dist > 1) $dist = 1;
    if ($dist < -1) $dist = -1;
    $dist = acos($dist);
    $dist = rad2deg($dist);
    return $dist * 60 * 1.1515 * 1.609344;
}

/**
 * Obtém o nome legível de um código de alarme (fallback simples).
 * Para resolução completa, utilize a tabela alarm_types no banco de dados.
 *
 * @param string|int $code Código do alarme
 * @return string Nome do alarme ou código formatado se não encontrado
 */
function get_alarm_name($code) {
    $map = ['1' => 'SOS Emergency', '2' => 'Power Cut', '3' => 'Vibration', '6' => 'Overspeed', '7' => 'Removing Alarm', '8' => 'Low Battery'];
    return $map[(string)$code] ?? "Alarm Code: {$code}";
}

/**
 * Obtém o nome legível de um código de evento (fallback simples).
 *
 * @param string $code Código do evento (ex: ACC_ON, DEVICE_ONLINE)
 * @return string Nome do evento ou código formatado se não encontrado
 */
function get_event_name($code) {
    $map = ['ACC_ON' => 'Ignition On', 'ACC_OFF' => 'Ignition Off', 'DEVICE_ONLINE' => 'Device Online', 'DEVICE_OFFLINE' => 'Device Offline'];
    return $map[(string)$code] ?? "Event: {$code}";
}

/**
 * Converte uma data em formato variado para o formato padrão MySQL (Y-m-d H:i:s).
 * Aceita três formatos de entrada:
 *   - string de data (ex: "2026-01-23 12:00:00")
 *   - timestamp Unix em segundos (ex: 1737619200)
 *   - timestamp Unix em milissegundos (ex: 1737619200000, detectado pelo comprimento >11)
 *   - null → retorna data/hora atual
 *
 * Valores que não puderem ser interpretados retornam a data/hora atual
 * silenciosamente (não lança exceção).
 *
 * @param mixed $rawTime Data de entrada (string, int ou null)
 * @return string Data formatada em Y-m-d H:i:s (UTC)
 *
 * @example
 * sanitize_date('2026-01-23 12:00:00');  // "2026-01-23 12:00:00"
 * sanitize_date(1737619200);              // "2026-01-23 12:00:00"
 * sanitize_date(1737619200000);           // "2026-01-23 12:00:00" (ms detectado)
 * sanitize_date(null);                    // data/hora atual
 */
function sanitize_date($rawTime) {
    if (!$rawTime) return date('Y-m-d H:i:s');
    if (is_numeric($rawTime)) {
        $ts = (strlen((string)$rawTime) > 11) ? $rawTime / 1000 : $rawTime;
        return date('Y-m-d H:i:s', (int)$ts);
    }
    $ts = strtotime($rawTime);
    return ($ts && $ts > 0) ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

/**
 * Detecta o tipo de mídia (imagem, vídeo, áudio) com base na extensão do arquivo.
 * Usado pelos handlers pushfileupload e pushftpfileupload para classificar
 * arquivos de mídia recebidos dos dispositivos.
 *
 * @param string $fileName Nome do arquivo com extensão
 * @return string Tipo de mídia: 'image', 'video', 'audio' ou 'other'
 *
 * @example
 * detect_media_type('EVENT_123_001.jpg');  // 'image'
 * detect_media_type('REC_456_0.ts');        // 'video'
 * detect_media_type('unknown.bin');         // 'other'
 */
function detect_media_type($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','gif','bmp','webp'])) return 'image';
    if (in_array($ext, ['mp4','avi','ts','mov','h264','h265','dav','mkv','flv','wmv'])) return 'video';
    if (in_array($ext, ['mp3','amr','wav','aac','ogg','wma','flac'])) return 'audio';
    return 'other';
}

/**
 * Converte um datetime UTC (formato do banco — a conexão PDO força
 * time_zone '+00:00' e os devices transmitem em GMT 0) para o horário
 * local de exibição do sistema (America/Sao_Paulo, GMT-3).
 *
 * REGRA DO SISTEMA: armazenar SEMPRE UTC; converter para BRT SOMENTE
 * na exibição — sempre através deste helper.
 * Atenção: colunas DATE puras (activation_date, cnh_expires_at…) NÃO
 * devem passar por aqui (a conversão deslocaria o dia).
 *
 * @param string|null $utcDatetime Datetime UTC ('Y-m-d H:i:s' ou parseável)
 * @param string      $format      Formato de saída (default 'd/m/Y H:i')
 * @param string      $fallback    Retorno quando vazio/inválido (default '—')
 * @return string
 *
 * @example fmt_brt('2026-07-09 02:15:00')            // '08/07/2026 23:15'
 * @example fmt_brt($row['gps_time'], 'd/m/Y H:i:s')  // com segundos
 */
function fmt_brt($utcDatetime, $format = 'd/m/Y H:i', $fallback = '—') {
    if (!$utcDatetime || $utcDatetime === '0000-00-00 00:00:00') return $fallback;
    try {
        $d = new DateTime($utcDatetime, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        return $d->format($format);
    } catch (Exception $e) {
        return (string)$utcDatetime;
    }
}

/**
 * Converte um intervalo de DIAS locais (BRT), como digitado nos filtros
 * de data do dashboard, para o intervalo UTC equivalente a ser comparado
 * com as colunas do banco (que estão em UTC).
 *
 * @param string $dateFrom Dia inicial local ('Y-m-d')
 * @param string $dateTo   Dia final local ('Y-m-d')
 * @return array [utc_from 'Y-m-d H:i:s', utc_to 'Y-m-d H:i:s']
 *
 * @example brt_day_range_to_utc('2026-07-08', '2026-07-08')
 *          // ['2026-07-08 03:00:00', '2026-07-09 02:59:59']
 */
function brt_day_range_to_utc($dateFrom, $dateTo) {
    $tzBrt = new DateTimeZone('America/Sao_Paulo');
    $tzUtc = new DateTimeZone('UTC');
    try {
        $from = new DateTime($dateFrom . ' 00:00:00', $tzBrt);
        $to   = new DateTime($dateTo . ' 23:59:59', $tzBrt);
        $from->setTimezone($tzUtc);
        $to->setTimezone($tzUtc);
        return [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
    } catch (Exception $e) {
        return [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
    }
}

/**
 * Dia de "hoje" no fuso local de exibição (para defaults de filtros de data).
 *
 * @param string      $format Formato (default 'Y-m-d')
 * @param string|null $modify Modificador relativo opcional (ex: '-30 days')
 * @return string
 */
function brt_today($format = 'Y-m-d', $modify = null) {
    $d = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    if ($modify) $d->modify($modify);
    return $d->format($format);
}
