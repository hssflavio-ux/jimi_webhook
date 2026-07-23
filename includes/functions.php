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

/** Teto global de período dos relatórios do sistema, em dias. */
const REPORT_RANGE_MAX_DAYS = 31;

/**
 * Aplica o teto global de período dos relatórios (REPORT_RANGE_MAX_DAYS).
 *
 * Recebe os dias BRT digitados no filtro; datas invertidas são trocadas e,
 * se o intervalo exceder o teto, date_to é encurtado para caber. O terceiro
 * elemento indica se houve ajuste (para a tela avisar o usuário).
 *
 * @param string $dateFrom Dia inicial local ('Y-m-d')
 * @param string $dateTo   Dia final local ('Y-m-d')
 * @returns array [date_from, date_to, foi_limitado(bool)]
 */
function clamp_report_range($dateFrom, $dateTo) {
    try {
        $from = new DateTime($dateFrom);
        $to   = new DateTime($dateTo);
    } catch (Exception $e) {
        return [brt_today(), brt_today(), false];
    }
    $clamped = false;
    if ($to < $from) { [$from, $to] = [$to, $from]; $clamped = true; }
    $maxTo = (clone $from)->modify('+' . (REPORT_RANGE_MAX_DAYS - 1) . ' days');
    if ($to > $maxTo) { $to = $maxTo; $clamped = true; }
    return [$from->format('Y-m-d'), $to->format('Y-m-d'), $clamped];
}

/**
 * Converte um intervalo local (BRT) com data E hora, como digitado nos
 * filtros com faixa horária, para a janela UTC equivalente.
 *
 * @param string $dateFrom Dia inicial local ('Y-m-d')
 * @param string $dateTo   Dia final local ('Y-m-d')
 * @param string $timeFrom Hora inicial local ('H:i'; default '00:00')
 * @param string $timeTo   Hora final local ('H:i'; default '23:59')
 * @returns array [utc_from 'Y-m-d H:i:s', utc_to 'Y-m-d H:i:s']
 */
function brt_datetime_range_to_utc($dateFrom, $dateTo, $timeFrom = '', $timeTo = '') {
    $timeFrom = preg_match('/^\d{2}:\d{2}$/', $timeFrom) ? $timeFrom : '00:00';
    $timeTo   = preg_match('/^\d{2}:\d{2}$/', $timeTo)   ? $timeTo   : '23:59';
    $tzBrt = new DateTimeZone('America/Sao_Paulo');
    $tzUtc = new DateTimeZone('UTC');
    try {
        $from = new DateTime("$dateFrom $timeFrom:00", $tzBrt);
        $to   = new DateTime("$dateTo $timeTo:59", $tzBrt);
        $from->setTimezone($tzUtc);
        $to->setTimezone($tzUtc);
        return [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
    } catch (Exception $e) {
        return ["$dateFrom 00:00:00", "$dateTo 23:59:59"];
    }
}

/* ── UI comum dos relatórios (ordenação + voltar) ───────────────────────── */

/**
 * Lê e valida os parâmetros de ordenação (?sort=&order=) de um relatório.
 *
 * A whitelist é obrigatória: a coluna volta interpolada no SQL (PDO não
 * parametriza identificadores), então nada fora de $validSorts pode passar.
 *
 * CONVENÇÃO DO SISTEMA: relatórios com data/hora abrem em ordem CRESCENTE
 * (mais antigo no topo, mais recente no fim) — por isso o default é 'ASC'.
 *
 * @param array  $validSorts   Colunas ordenáveis permitidas
 * @param string $defaultSort  Coluna padrão (deve estar em $validSorts)
 * @param string $defaultOrder Direção padrão ('ASC' | 'DESC')
 * @returns array [sort, order]
 */
function report_sort_params(array $validSorts, string $defaultSort, string $defaultOrder = 'ASC'): array {
    $sort = $_GET['sort'] ?? $defaultSort;
    if (!in_array($sort, $validSorts, true)) $sort = $defaultSort;
    $order = strtoupper((string)($_GET['order'] ?? $defaultOrder));
    if ($order !== 'ASC' && $order !== 'DESC') {
        $order = strtoupper($defaultOrder) === 'DESC' ? 'DESC' : 'ASC';
    }
    return [$sort, $order];
}

/**
 * Cabeçalho de coluna clicável com seta de ordenação (▲ crescente / ▼
 * decrescente). A coluna ativa mostra a seta cheia e o clique inverte a
 * direção; as demais mostram a seta neutra (⇅) e o primeiro clique aplica
 * $firstOrder. Preserva os demais filtros da URL e volta para a página 1.
 *
 * @param string $col        Chave da coluna (mesma da whitelist de report_sort_params)
 * @param string $label      Rótulo exibido no <th>
 * @param string $sort       Coluna atualmente ordenada
 * @param string $order      Direção atual ('ASC' | 'DESC')
 * @param string $firstOrder Direção do primeiro clique nesta coluna
 * @returns string HTML do link
 */
function report_sort_link(string $col, string $label, string $sort, string $order, string $firstOrder = 'ASC'): string {
    $active = ($sort === $col);
    $newOrder = $active ? ($order === 'ASC' ? 'DESC' : 'ASC') : strtoupper($firstOrder);
    $q = $_GET;
    $q['sort'] = $col;
    $q['order'] = $newOrder;
    unset($q['page'], $q['export']);   // nova ordenação sempre reinicia a paginação
    $arrow = $active
        ? '<span class="sort-arrow is-active">' . ($order === 'ASC' ? '&#9650;' : '&#9660;') . '</span>'
        : '<span class="sort-arrow">&#8645;</span>';
    $title = $active
        ? ($order === 'ASC' ? 'Ordenado crescente — clique para inverter' : 'Ordenado decrescente — clique para inverter')
        : 'Clique para ordenar por ' . $label;
    return '<a class="sort-link" href="?' . htmlspecialchars(http_build_query($q), ENT_QUOTES)
         . '" title="' . htmlspecialchars($title, ENT_QUOTES) . '">'
         . htmlspecialchars($label) . $arrow . '</a>';
}

/**
 * Botão "Voltar" dos relatórios: devolve o usuário à tela inicial (filtros
 * limpos) do próprio relatório depois de ver o resultado, sem obrigá-lo a
 * reabrir a tela pelo menu lateral.
 *
 * @param string $baseUrl Rota do relatório (ex.: '/relatorios/alarmes')
 * @param string $label   Rótulo do botão
 * @returns string HTML do botão
 */
function report_back_button(string $baseUrl, string $label = 'Voltar'): string {
    return '<a href="' . htmlspecialchars($baseUrl, ENT_QUOTES) . '" class="btn btn-outline btn-sm">&larr; '
         . htmlspecialchars($label) . '</a>';
}

/**
 * Traduz período (dias BRT) + faixa horária opcional na janela UTC do relatório
 * e, quando for o caso, no predicado extra de hora local.
 *
 * Dois modos, escolhidos pelo usuário na tela:
 *  - 'continua' (default): UMA janela só, de `date_from time_from` até
 *    `date_to time_to`. Ex.: 01/07 08:00 → 05/07 10:00 traz tudo no meio,
 *    inclusive as madrugadas.
 *  - 'diaria': dias inteiros no BETWEEN + a faixa horária aplicada a CADA dia
 *    do intervalo. Ex.: 08:00–10:00 traz só as manhãs de 01/07 a 05/07.
 *    Faixa invertida (time_from > time_to) é lida como janela que cruza a
 *    meia-noite — ex.: 22:00–06:00 = turno da noite — e vira OR.
 *
 * Sem faixa horária os dois modos são idênticos (dias inteiros).
 *
 * Nota de performance: no modo diário o predicado de hora usa
 * TIME(CONVERT_TZ(col)) e portanto não é indexável, mas o BETWEEN da janela
 * continua servido pelo índice (imei, tempo) e limitado pelo teto de 31 dias.
 *
 * @param string $col      Coluna de tempo qualificada (ex.: 'g.gps_time')
 * @param string $dateFrom Dia inicial local ('Y-m-d')
 * @param string $dateTo   Dia final local ('Y-m-d')
 * @param string $timeFrom Hora inicial local ('H:i'; vazio = 00:00)
 * @param string $timeTo   Hora final local ('H:i'; vazio = 23:59)
 * @param string $mode     'continua' | 'diaria'
 * @returns array{0:string,1:string,2:string,3:array} [utc_from, utc_to, sql_extra, params_extra]
 */
function report_time_window(string $col, string $dateFrom, string $dateTo, string $timeFrom, string $timeTo, string $mode = 'continua'): array {
    $hasTime = $timeFrom !== '' || $timeTo !== '';

    if ($mode !== 'diaria' || !$hasTime) {
        [$utcFrom, $utcTo] = brt_datetime_range_to_utc($dateFrom, $dateTo, $timeFrom, $timeTo);
        return [$utcFrom, $utcTo, '', []];
    }

    // Modo diário: a janela cobre os dias inteiros; a hora filtra dentro de cada um
    [$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo);
    $tf = preg_match('/^\d{2}:\d{2}$/', $timeFrom) ? $timeFrom : '00:00';
    $tt = preg_match('/^\d{2}:\d{2}$/', $timeTo)   ? $timeTo   : '23:59';
    $localTime = "TIME(CONVERT_TZ($col, '+00:00', '-03:00'))";
    $sql = $tf <= $tt
        ? " AND $localTime BETWEEN :tw_from AND :tw_to"
        : " AND ($localTime >= :tw_from OR $localTime <= :tw_to)";  // cruza a meia-noite

    return [$utcFrom, $utcTo, $sql, [':tw_from' => $tf . ':00', ':tw_to' => $tt . ':59']];
}

/**
 * Paginação padrão das grades: rótulo "Página X de Y (N unidades)" + « + janela
 * deslizante de páginas + ».
 *
 * A janela acompanha a página atual (primeira e última sempre visíveis, com
 * reticências nos saltos) — o laço fixo `1..min($totalPages,10)` que existia
 * antes nunca mostrava a página 11+ nem a atual quando o usuário passava do
 * décimo bloco.
 *
 * @param int    $page       Página atual (1-based)
 * @param int    $totalPages Total de páginas
 * @param int    $totalRows  Total de registros (rótulo)
 * @param string $unit       Unidade no rótulo (ex.: 'posições', 'viagens')
 * @param int    $window     Páginas exibidas de cada lado da atual
 * @returns string HTML da paginação ('' quando há só uma página)
 */
function report_pagination(int $page, int $totalPages, int $totalRows, string $unit = 'registros', int $window = 2): string {
    if ($totalPages <= 1) return '';

    $q = $_GET;
    unset($q['page'], $q['export']);
    $base = http_build_query($q);
    $href = function (int $n) use ($base) {
        return htmlspecialchars('?' . ($base !== '' ? $base . '&' : '') . 'page=' . $n, ENT_QUOTES);
    };

    // Primeira, última e as vizinhas da atual
    $pages = [1, $totalPages];
    for ($i = $page - $window; $i <= $page + $window; $i++) {
        if ($i >= 1 && $i <= $totalPages) $pages[] = $i;
    }
    $pages = array_unique($pages);
    sort($pages);

    $out = '<div class="flex-between mt-16" style="font-size:13px;color:var(--muted);">'
         . '<span>Página ' . $page . ' de ' . $totalPages
         . ' (' . number_format($totalRows, 0, ',', '.') . ' ' . htmlspecialchars($unit) . ')</span>'
         . '<div style="display:flex;gap:4px;align-items:center;">';

    if ($page > 1) {
        $out .= '<a href="' . $href($page - 1) . '" class="btn btn-outline btn-sm" title="Página anterior">&laquo;</a>';
    }
    $prev = 0;
    foreach ($pages as $n) {
        if ($prev && $n > $prev + 1) $out .= '<span style="padding:0 2px;">…</span>';
        $out .= $n === $page
            ? '<span class="btn btn-primary btn-sm" style="pointer-events:none;">' . $n . '</span>'
            : '<a href="' . $href($n) . '" class="btn btn-outline btn-sm">' . $n . '</a>';
        $prev = $n;
    }
    if ($page < $totalPages) {
        $out .= '<a href="' . $href($page + 1) . '" class="btn btn-outline btn-sm" title="Próxima página">&raquo;</a>';
    }

    return $out . '</div></div>';
}

/**
 * Indica se o relatório foi acionado com algum parâmetro (filtro, ordenação,
 * paginação) — usado para só exibir o "Voltar" quando há resultado na tela.
 *
 * @returns bool
 */
function report_has_query(): bool {
    $q = $_GET;
    unset($q['export']);
    return !empty($q);
}

/**
 * Configuração de streaming de vídeo ao vivo/playback (JT/T 1078 via IoTHub).
 *
 * O comando 37121 (0x9101) instrui o DEVICE a publicar o stream RTP no
 * media server do IoTHub — portanto videoIP/videoTCPPort devem ser o
 * endereço que o DEVICE alcança (IP público do servidor), nunca o
 * hostname visto pelo navegador. Portas padrão do iothub-media:
 * 10002 (ingest ao vivo), 10003 (ingest playback 0x9201), 8881 (saída HTTP-FLV).
 * Ref: docs.jimicloud.com/test/test.html §2.2.
 *
 * Overrides via .env: VIDEO_INGEST_IP, VIDEO_INGEST_PORT, VIDEO_PLAYBACK_PORT.
 * Sem override, o IP é extraído do host de STREAM_URL.
 *
 * @return array{flv_base:string, ingest_ip:string, ingest_port:string, playback_port:string}
 */
function video_stream_config() {
    $flvBase = rtrim(getenv('STREAM_URL') ?: 'http://localhost:8881', '/');
    $host = parse_url($flvBase, PHP_URL_HOST) ?: 'localhost';
    return [
        'flv_base'      => $flvBase,
        'ingest_ip'     => getenv('VIDEO_INGEST_IP') ?: $host,
        'ingest_port'   => (string)(getenv('VIDEO_INGEST_PORT') ?: '10002'),
        'playback_port' => (string)(getenv('VIDEO_PLAYBACK_PORT') ?: '10003'),
    ];
}
