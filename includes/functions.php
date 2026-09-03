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
 * Link "Ver Mapa" — abre o ponto num serviço de mapa externo, em nova aba.
 *
 * Ponto ÚNICO desta URL: até 26/08/2026 os 9 arquivos que oferecem "Ver Mapa"
 * (`rel_alarmes`, `rel_desatualizados`, `rel_geocercas`, `rel_ignicao`,
 * `rel_posicoes`, `rel_status_frota`, `rel_velocidade`, `report_segments.php`,
 * `export_helper.php`) montavam a MESMA string do OpenStreetMap
 * (`https://www.openstreetmap.org/?mlat=…`) cada um por conta própria.
 * Trocar de provedor — como pedido aqui, para Google Maps — exigia lembrar
 * dos 9 lugares. A URL usada é a API pública "Universal" do Google Maps
 * (`/maps/search/?api=1&query=lat,lng`), que não exige chave e é a forma
 * oficialmente documentada para linkar um ponto sem precisar de um Maps
 * Embed/JavaScript API key.
 *
 * @param float|string $lat
 * @param float|string $lng
 * @returns string URL pronta para `href`
 */
function map_link_url($lat, $lng): string
{
    return sprintf('https://www.google.com/maps/search/?api=1&query=%s,%s', $lat, $lng);
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
 * Distância em quilômetros entre dois pontos, pela fórmula de Haversine.
 *
 * Existe separada de calculate_distance() de propósito. Aquela usa lei dos
 * cossenos esférica e **retorna 0 quando qualquer latitude é 0** — guarda
 * pensada para descartar GPS inválido, mas que sabota qualquer teste de raio
 * (a linha do Equador passa a ter distância zero para tudo). Como há
 * chamadores legados dependendo daquele comportamento (pushgps.php), a função
 * antiga fica intocada e as medições novas usam esta.
 *
 * Promovida de scripts/trip_builder.php na v4.5.0, onde era privada do script:
 * o worker de geocercas precisa exatamente da mesma medida para o teste de
 * raio, e duas implementações de haversine no repositório é uma a mais.
 *
 * @param float $lat1 Latitude do ponto de origem
 * @param float $lng1 Longitude do ponto de origem
 * @param float $lat2 Latitude do ponto de destino
 * @param float $lng2 Longitude do ponto de destino
 * @returns float Distância em quilômetros
 */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earth = 6371; // raio médio da Terra em km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
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

/* ── Marca nos relatórios ───────────────────────────────────────────────── */

/*
 * A marca tem TRÊS artes, uma por superfície (v4.8.2) — a diferença não é
 * capricho, é o que cada contexto consegue exibir:
 *   `logo-login.png`  lockup completo, com o descritor. Só no login, onde há
 *                     largura para o descritor ser legível.
 *   `logo-dark.png`   texto claro sobre transparente. Sidebar e qualquer
 *                     superfície escura — a arte clara sumiria no near-black.
 *   `logo-report.png` sem descritor, fundo branco sólido. Relatórios (PDF).
 */

/** Caminho do logo servido por HTTP (web/assets é servido como estático). */
const REPORT_LOGO_URL  = '/web/assets/logo-report.png';
/** Caminho em disco, para embutir no PDF. */
const REPORT_LOGO_PATH = __DIR__ . '/../web/assets/logo-report.png';

/**
 * Marca + separador, para abrir o cabeçalho de uma tela de relatório.
 *
 * Usado dentro do `<div class="flex-between">` já existente em cada tela,
 * envolvendo a marca e o `<h2>` num único filho flex — sem isso o
 * `space-between` distribuiria logo, título e botões em três pontos da linha,
 * jogando o título para o meio.
 *
 * Centralizado para que trocar a marca seja uma edição, não catorze.
 *
 * @returns string HTML de abertura (precisa ser fechado com report_brand_end())
 */
function report_brand(): string
{
    return '<div style="display:flex;align-items:center;gap:12px;min-width:0;">'
         . '<img src="' . REPORT_LOGO_URL . '" alt="bycamera" '
         . 'style="height:30px;width:auto;flex:0 0 auto;">'
         . '<span style="width:1px;height:24px;background:var(--hairline);flex:0 0 auto;"></span>';
}

/**
 * Fecha o bloco aberto por report_brand().
 *
 * @returns string
 */
function report_brand_end(): string
{
    return '</div>';
}

/* ── Escopo multi-tenant dos relatórios ─────────────────────────────────── */

/**
 * Resolve por qual cliente um relatório deve filtrar (v4.7.3).
 *
 * ⚠️ CORRIGE UM VAZAMENTO CROSS-TENANT REAL. Até a v4.7.2, nove telas
 * repetiam este padrão:
 *
 *     if (!$isAdmin && !$filterCust) { ...filtra pelo cliente da sessão... }
 *     elseif ($filterCust)          { ...filtra pelo cliente PEDIDO NA URL... }
 *
 * Um usuário **não-admin** que acrescentasse `?customer_id=N` caía no
 * `elseif`: o primeiro ramo exige `!$filterCust`, que passa a ser falso. Ou
 * seja, o parâmetro que deveria ser um filtro do admin virava um **seletor de
 * cliente para qualquer um**, sem nenhuma verificação de posse. Confirmado
 * empiricamente em 01/08/2026: um `operator` do cliente B leu alarmes,
 * equipamentos e status de frota do cliente A só mudando a URL.
 *
 * Regras agora:
 *  - **admin de plataforma** (`role === 'admin'`): honra o pedido; sem pedido,
 *    `null` = todos os clientes.
 *  - **revendedor** (v4.8.5): honra o pedido **só se o cliente for dele**;
 *    fora disso o parâmetro é ignorado e vale o cliente da sessão. Nunca
 *    `null` — revendedor não tem visão "todos os clientes da base".
 *  - **demais**: SEMPRE o cliente da sessão. O `?customer_id` é ignorado por
 *    completo, não validado — não há resposta diferente entre "cliente que não
 *    existe" e "cliente que não é seu", então nem a existência vaza.
 *  - **sem cliente na sessão**: devolve `0`, que não casa com nenhuma linha.
 *    Falha FECHADA de propósito: antes, `if ($customerId)` simplesmente não
 *    acrescentava filtro nenhum, e um usuário mal provisionado via **tudo**.
 *
 * A v4.7.3 deixou o revendedor de fora de propósito, para não mudar semântica
 * de perfil no mesmo passe que fechava a falha. Fechado na v4.8.5: `$isAdmin`
 * é `role==='admin' || user_type==='revendedor'` em ~10 handlers, e enquanto
 * isso valesse, um revendedor lia os clientes de OUTRO revendedor com um
 * `?customer_id=N` na URL — a mesma escalada da v4.7.3, um perfil acima.
 *
 * @param mixed    $requested          Valor cru de $_GET['customer_id']
 * @param bool     $isAdmin            role === 'admin' || user_type === 'revendedor'
 * @param int|null $sessionCustomerId  get_customer_id()
 * @returns int|null  ID para filtrar, ou null para "sem filtro" (só admin de plataforma)
 */
function report_customer_scope($requested, bool $isAdmin, $sessionCustomerId): ?int {
    $req      = ($requested !== null && $requested !== '') ? (int)$requested : null;
    $sessionId = ($sessionCustomerId !== null && $sessionCustomerId !== '') ? (int)$sessionCustomerId : 0;

    if ($isAdmin) {
        $allowed = reseller_scope_ids();
        if ($allowed !== null) {
            // É revendedor, não admin de plataforma.
            if ($req !== null && in_array($req, $allowed, true)) return $req;
            return $sessionId;
        }
        return $req;
    }
    return $sessionId;
}

/**
 * Opções do seletor de cliente das telas que filtram por `?customer_id`.
 *
 * Companheira obrigatória de `report_customer_scope()`. Restringir o FILTRO
 * sem restringir a LISTA deixaria o revendedor lendo os **nomes** de todos os
 * clientes da base — de outros revendedores inclusive — num `<select>` cujas
 * opções, além do mais, não teriam efeito nenhum ao serem escolhidas. Eram 12
 * handlers repetindo `SELECT id, name FROM customers WHERE is_active=1`.
 *
 * Admin de plataforma continua vendo todos. Em erro, lista vazia: falha
 * fechada — o seletor some em vez de aparecer completo.
 *
 * @param PDO $db Conexão ativa
 * @returns array<array{id:int,name:string}>
 */
function report_customer_options(PDO $db): array {
    try {
        $allowed = reseller_scope_ids();
        if ($allowed === null) {
            return $db->query("SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name")->fetchAll();
        }
        if (!$allowed) return [];
        // Os ids vêm de reseller_scope_ids(), que já os passou por (int) —
        // interpolar aqui é seguro e evita montar N placeholders.
        $in = implode(',', array_map('intval', $allowed));
        return $db->query("SELECT id, name FROM customers WHERE is_active = 1 AND id IN ($in) ORDER BY name")->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Cliente-dono a gravar num cadastro novo — o espelho de ESCRITA do
 * `report_customer_scope()`.
 *
 * Devolve `null` quando não dá para resolver com segurança, e nesse caso o
 * chamador **tem de recusar o cadastro**. Nunca devolve um id "de consolo":
 * eram exatamente os dois consolos que corrompiam o cadastro de equipamento,
 * os dois com HTTP 200 e mensagem de sucesso na tela —
 *   - gravar `NULL` (as colunas `devices.customer_id` e `sim_cards.customer_id`
 *     são nullable, então o INSERT passa) cria registro órfão, que some de toda
 *     tela com escopo de cliente e nunca mais é achado pelo usuário;
 *   - o fallback fixo `?? 1` gravava no cliente de **id 1**, quase sempre de
 *     outro tenant.
 * As colunas NOT NULL (`drivers`, `geofences`, `report_schedules`) escapavam da
 * corrupção só porque o banco recusava — com um erro de SQL cru na tela.
 *
 * A semântica de quem pode gravar em quem é deliberadamente a MESMA de
 * `report_customer_scope()`/`reseller_scope_ids()`, para não existirem duas
 * respostas para "que clientes são dele":
 *   - **não-admin**: sempre o cliente da sessão; o `$requested` do formulário é
 *     ignorado, não validado (idem escopo de leitura);
 *   - **revendedor**: `$requested` só vale dentro do escopo dele; senão cai no
 *     cliente da sessão, e só se este também estiver no escopo;
 *   - **admin de plataforma**: `$requested` vale; sem ele, o da sessão.
 *
 * Id inexistente escapa daqui e morre na FK (`fk_dev_customer`) — backstop de
 * propósito: o `<select>` da tela já sai de `report_customer_options()`, então
 * valor fora da lista é adulteração, não uso normal.
 *
 * @param mixed $requested         `customer_id` vindo do formulário ('' / null quando ausente)
 * @param bool  $isAdmin           admin de plataforma OU revendedor
 * @param mixed $sessionCustomerId contexto de cliente da sessão
 * @returns int|null  id a gravar, ou null se o chamador deve RECUSAR o cadastro
 */
function resolve_owner_customer_id($requested, bool $isAdmin, $sessionCustomerId): ?int {
    $req     = ($requested !== null && $requested !== '') ? (int)$requested : null;
    $session = ($sessionCustomerId !== null && $sessionCustomerId !== '') ? (int)$sessionCustomerId : null;
    if ($req !== null && $req <= 0)         $req = null;
    if ($session !== null && $session <= 0) $session = null;

    if (!$isAdmin) return $session;

    $allowed = reseller_scope_ids();
    if ($allowed === null) {
        // Admin de plataforma: escolhe qualquer um; sem escolha, o da sessão.
        return $req ?? $session;
    }
    if ($req !== null && in_array($req, $allowed, true))         return $req;
    if ($session !== null && in_array($session, $allowed, true)) return $session;
    return null;
}

/**
 * Vincula (ou desvincula) um chip a um equipamento — ponto ÚNICO de escrita
 * do relacionamento 1:1 chip↔equipamento (v4.10.4).
 *
 * O vínculo mora em `sim_cards.imei` — `devices.sim_card_id`, a FK que
 * existia até a v4.10.x, nunca foi lida nem escrita por código nenhum e foi
 * removida na migração v4.11.0 (legado morto).
 *
 * 🔴 Chamada SÓ por `handlers/equipamentos.php` (v4.11.1). O cadastro de chip
 * (`handlers/chips.php`) NÃO tem mais campo pra isso, de propósito — o dono
 * do produto pediu para o vínculo só existir na direção câmera→chip (a
 * câmera escolhe um chip livre; nunca o chip escolhendo uma câmera). Antes
 * disso, `chips.php` também tinha um `<select>` de equipamento, e escolher
 * por ali era o caminho errado só desencorajado por um texto de ajuda — dava
 * pra vincular dos dois lados. A `UNIQUE KEY uk_sim_imei`
 * (`mysql/migration_v4.10.4.sql`) continua sendo o backstop: mesmo um bug
 * futuro nesta função não consegue gravar dois chips no mesmo IMEI.
 *
 * @param PDO      $db        Conexão ativa
 * @param int|null $simCardId `sim_cards.id` a vincular; null/0 = só desvincula o atual
 * @param string   $imei      Equipamento
 * @param int      $ownerId   `customer_id` dono do chip (escopo da checagem de posse)
 * @returns string|null Aviso a mostrar ao usuário, ou null se tudo correu bem
 */
function link_sim_card_to_device(PDO $db, ?int $simCardId, string $imei, int $ownerId): ?string
{
    $simCardId = $simCardId ?: null;

    $stmt = $db->prepare("SELECT id FROM sim_cards WHERE imei = ? LIMIT 1");
    $stmt->execute([$imei]);
    $current = $stmt->fetchColumn();
    $currentId = $current !== false ? (int)$current : null;

    if ($currentId === $simCardId) {
        return null; // já é o vínculo pedido — nada a fazer
    }

    // Desvincula o chip atual ANTES de tentar o novo — mesmo que o novo falhe
    // (perdeu a corrida de outro cadastro simultâneo), o equipamento não fica
    // preso a um chip que o usuário explicitamente trocou.
    if ($currentId !== null) {
        $db->prepare("UPDATE sim_cards SET imei = NULL WHERE id = ?")->execute([$currentId]);
    }

    if ($simCardId === null) {
        return null;
    }

    // `AND (imei IS NULL OR imei='')` é a checagem de corrida: se outro
    // cadastro vinculou este chip entre o carregamento do formulário e este
    // POST, a linha não casa e rowCount() vem 0 — sem isso o segundo POST
    // simplesmente roubaria o chip do primeiro, driblando a UNIQUE key (ela
    // impediria um UPDATE para um IMEI já ocupado, não um chip já ocupado
    // recebendo outro IMEI).
    $upd = $db->prepare("
        UPDATE sim_cards SET imei = ?
        WHERE id = ? AND customer_id = ? AND (imei IS NULL OR imei = '')
    ");
    $upd->execute([$imei, $simCardId, $ownerId]);
    if ($upd->rowCount() === 0) {
        return 'O chip selecionado não estava mais livre (outro cadastro pode tê-lo vinculado enquanto você preenchia o formulário) — o equipamento foi salvo sem chip.';
    }
    return null;
}

/**
 * Instalação corrente (aberta) de um veículo, se houver.
 *
 * @param PDO $db
 * @param int $vehicleId
 * @returns array|null Linha de `device_installations` + `devices.imei`/`device_name`, ou null se o veículo não tem câmera instalada
 */
function get_open_installation_for_vehicle(PDO $db, int $vehicleId): ?array
{
    $stmt = $db->prepare("
        SELECT di.*, d.imei, d.device_name AS device_label
        FROM device_installations di
        JOIN devices d ON d.id = di.device_id
        WHERE di.vehicle_id = ? AND di.removed_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$vehicleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Instalação corrente (aberta) de uma câmera, se houver.
 *
 * @param PDO $db
 * @param int $deviceId
 * @returns array|null Linha de `device_installations` + `vehicles.plate`, ou null se a câmera está livre
 */
function get_open_installation_for_device(PDO $db, int $deviceId): ?array
{
    $stmt = $db->prepare("
        SELECT di.*, v.plate
        FROM device_installations di
        JOIN vehicles v ON v.id = di.vehicle_id
        WHERE di.device_id = ? AND di.removed_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Instala uma câmera livre (com chip) num veículo sem câmera. Ponto único de
 * escrita em `device_installations` — nenhum handler insere nela direto.
 *
 * Exige câmera com chip vinculado (`sim_cards.imei` apontando pra ela) porque
 * essa é a ordem do fluxo: chip → câmera → veículo, nunca ao contrário.
 * Sincroniza `devices.customer_id` com o dono do veículo — é o que mantém as
 * dezenas de telas hoje escopadas por `devices.customer_id` funcionando sem
 * reescrita nesta Fase 1 (ver PLANO — Fase 2 troca isso por escopo por
 * período, ainda não implementada).
 *
 * @param PDO      $db
 * @param int      $deviceId
 * @param int      $vehicleId
 * @param int|null $actorUserId
 * @returns string|null Mensagem de erro, ou null em sucesso
 */
function install_device_on_vehicle(PDO $db, int $deviceId, int $vehicleId, ?int $actorUserId): ?string
{
    $db->beginTransaction();
    try {
        $dev = $db->prepare("SELECT d.id, d.imei, d.is_active, s.imei AS chip_imei
                              FROM devices d
                              LEFT JOIN sim_cards s ON s.imei = d.imei
                              WHERE d.id = ? FOR UPDATE");
        $dev->execute([$deviceId]);
        $dev = $dev->fetch(PDO::FETCH_ASSOC);
        if (!$dev || !$dev['is_active']) {
            $db->rollBack();
            return 'Câmera não encontrada ou inativa.';
        }
        if (empty($dev['chip_imei'])) {
            $db->rollBack();
            return 'Esta câmera ainda não tem chip vinculado. Vincule um chip em /equipamentos antes de instalar num veículo.';
        }

        $vehicle = $db->prepare("SELECT id, customer_id, vehicle_type, is_active FROM vehicles WHERE id = ? FOR UPDATE");
        $vehicle->execute([$vehicleId]);
        $vehicle = $vehicle->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle || !$vehicle['is_active']) {
            $db->rollBack();
            return 'Veículo não encontrado ou inativo.';
        }

        $openDev = $db->prepare("SELECT id FROM device_installations WHERE device_id = ? AND removed_at IS NULL FOR UPDATE");
        $openDev->execute([$deviceId]);
        if ($openDev->fetch()) {
            $db->rollBack();
            return 'Esta câmera já está instalada em outro veículo — desinstale-a primeiro.';
        }

        $openVeh = $db->prepare("SELECT id FROM device_installations WHERE vehicle_id = ? AND removed_at IS NULL FOR UPDATE");
        $openVeh->execute([$vehicleId]);
        if ($openVeh->fetch()) {
            $db->rollBack();
            return 'Este veículo já tem uma câmera instalada — troque ou desinstale primeiro.';
        }

        $ins = $db->prepare("
            INSERT INTO device_installations (device_id, vehicle_id, customer_id, installed_at, installed_by)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        $ins->execute([$deviceId, $vehicleId, $vehicle['customer_id'], $actorUserId]);

        // `devices.vehicle_type` continua sendo a fonte que /rastreamento e o
        // relatório de deslocamento leem (não reescritos nesta fase) — sem
        // sincronizar aqui, todo veículo cadastrado a partir da v4.11.0
        // perderia o ícone no mapa em silêncio assim que instalasse uma
        // câmera, porque o tipo passou a morar em `vehicles`.
        $db->prepare("UPDATE devices SET customer_id = ?, vehicle_type = ? WHERE id = ?")
           ->execute([$vehicle['customer_id'], $vehicle['vehicle_type'], $deviceId]);

        $db->commit();
        if (function_exists('audit_log')) {
            audit_log('device.install', 'vehicle', $vehicleId, null,
                ['device_id' => $deviceId, 'imei' => $dev['imei'], 'customer_id' => $vehicle['customer_id']]);
        }
        return null;
    } catch (Exception $e) {
        $db->rollBack();
        return 'Erro ao instalar câmera: ' . $e->getMessage();
    }
}

/**
 * Desinstala a câmera corrente de um veículo (fecha a instalação aberta). A
 * câmera fica livre para outro veículo; o chip continua com ela — só a
 * desativação da câmera libera o chip (ver `equipamentos.php`).
 *
 * @param PDO      $db
 * @param int      $vehicleId
 * @param int|null $actorUserId
 * @returns string|null Mensagem de erro, ou null em sucesso
 */
function uninstall_device_from_vehicle(PDO $db, int $vehicleId, ?int $actorUserId): ?string
{
    // v4.15.0 — "antes" lido ANTES do UPDATE que fecha a instalação: depois
    // dele `removed_at` já não é mais NULL e a linha não casaria de novo.
    $before = $db->prepare("SELECT device_id FROM device_installations WHERE vehicle_id = ? AND removed_at IS NULL");
    $before->execute([$vehicleId]);
    $deviceId = $before->fetchColumn();

    $stmt = $db->prepare("
        UPDATE device_installations
        SET removed_at = NOW(), removed_by = ?
        WHERE vehicle_id = ? AND removed_at IS NULL
    ");
    $stmt->execute([$actorUserId, $vehicleId]);
    if ($stmt->rowCount() === 0) {
        return 'Este veículo não tem câmera instalada.';
    }
    if (function_exists('audit_log')) {
        audit_log('device.uninstall', 'vehicle', $vehicleId, ['device_id' => $deviceId], null);
    }
    return null;
}

/**
 * Resolve o dono (cliente + veículo) de um IMEI NO MOMENTO EM QUE É CHAMADA —
 * ponto único usado tanto na ingestão dos webhooks (para gravar como
 * snapshot em `gps_data`/`alarms`/`events`/`heartbeats`/`media_files`/
 * `occurrences.vehicle_id`) quanto no backfill da migração v4.12.0. A leitura
 * (relatórios, painel, `/ativos/{id}`) NUNCA chama esta função — ela lê o
 * valor já gravado na linha, para não reabrir o mesmo buraco que existia
 * antes da Fase 2 (dono corrente ≠ dono de quando o evento aconteceu).
 *
 * @param PDO    $db
 * @param string $imei
 * @returns array{customer_id:int|null, vehicle_id:int|null} Ambos null se a câmera não tem instalação aberta (livre ou nunca instalada)
 */
function resolve_installation_for_imei(PDO $db, string $imei): array
{
    $stmt = $db->prepare("
        SELECT di.customer_id, di.vehicle_id
        FROM device_installations di
        JOIN devices d ON d.id = di.device_id
        WHERE d.imei = ? AND di.removed_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$imei]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'customer_id' => $row['customer_id'] ?? null,
        'vehicle_id'  => $row['vehicle_id'] ?? null,
    ];
}

/**
 * Rótulo de exibição de um chip no seletor "Chip (SIM)" de `/equipamentos` —
 * único lugar do cadastro onde o vínculo chip↔câmera é escolhido.
 *
 * @param array $chip Linha de `sim_cards` (carrier, msisdn, iccid)
 * @returns string
 */
function chip_label(array $chip): string
{
    return trim(($chip['carrier'] ?: '—') . ' · ' . ($chip['msisdn'] ?: ($chip['iccid'] ?: 'sem número')));
}

/**
 * SQL que resolve o NOME do alarme na leitura — joins + expressão.
 *
 * `pushalarm.php` resolve o nome UMA vez, quando o webhook chega, e grava o
 * resultado em `alarms.alarm_name`. Código ausente de `alarm_types` naquele
 * instante vira o rótulo `Código NNNN (JTT)` e fica gravado assim **para
 * sempre**, mesmo depois de o código entrar no catálogo.
 *
 * A resolução é DELIBERADAMENTE um remendo, não uma substituição: o nome
 * gravado continua vencendo sempre que for um nome de verdade. Preferir o
 * catálogo cegamente apagaria o prefixo `Fim de Alarme: ` (evento de FIM de
 * alarme) e o bitmask decodificado do JT/T 256 ("Excesso de Velocidade +
 * Fadiga…"), que o catálogo não sabe reproduzir. Só o rótulo genérico é
 * trocado.
 *
 * Mora aqui, e não no handler, porque a MESMA resolução é usada pelo Relatório
 * de Alarmes na tela e pelo relatório agendado do `scripts/worker.php`. Foram
 * cópias divergentes por tempo demais: o worker imprimia `alarm_type` cru — o
 * código numérico, sem nem o rótulo genérico.
 *
 * ⚠️ O alias da tabela de alarmes é fixo em `a`; as duas consultas que usam
 * isto já o adotam.
 *
 * @returns array{joins:string, expr:string}
 */
/**
 * O alarme é um evento de DIAGNÓSTICO (técnico, do equipamento para o sistema)?
 *
 * Versão em PHP do flag que `alarm_label_sql()['diag']` resolve em SQL, para
 * quem decide UMA linha por vez — o motor de ocorrências. Mesma precedência do
 * rótulo: código COMPOSTO primeiro (`256-2048`), código base depois (`256`).
 *
 * Falha para o lado de NÃO classificar, em dois casos:
 *   - código fora do catálogo → não é diagnóstico (um alarme novo tem de
 *     aparecer, nunca sumir em silêncio);
 *   - coluna `is_diagnostic` ausente (migração v4.9.9 não aplicada) → o `catch`
 *     devolve o comportamento anterior em vez de derrubar o webhook.
 *
 * @param PDO         $db            Conexão ativa
 * @param string      $alarmType     Código base como chega do device ('259')
 * @param string|null $compositeCode Código composto quando há subtipo ('256-2048')
 * @param int         $msgClass      0 = JIMI, 1 = JT/T 808 (ADR-001)
 * @returns bool
 */
function is_diagnostic_alarm(PDO $db, string $alarmType, ?string $compositeCode, int $msgClass): bool {
    $protocol = $msgClass === 1 ? 'JTT' : 'JIMI';
    try {
        $stmt = $db->prepare(
            "SELECT is_diagnostic FROM alarm_types
              WHERE protocol = :p AND alarm_code = :code LIMIT 1"
        );
        if ($compositeCode !== null && $compositeCode !== '') {
            $stmt->execute([':p' => $protocol, ':code' => $compositeCode]);
            $achado = $stmt->fetchColumn();
            if ($achado !== false) {
                return (bool)$achado;
            }
        }
        $stmt->execute([':p' => $protocol, ':code' => $alarmType]);
        $achado = $stmt->fetchColumn();
        return $achado === false ? false : (bool)$achado;
    } catch (Throwable $e) {
        return false;
    }
}

function alarm_label_sql(): array {
    return [
        'joins' => "
            LEFT JOIN alarm_types atc ON a.msg_class = 1 AND a.alarm_subtype IS NOT NULL
                                     AND atc.protocol = 'JTT'
                                     AND atc.alarm_code = CONCAT(a.alarm_type, '-', a.alarm_subtype)
            LEFT JOIN alarm_types atb ON atb.protocol = IF(a.msg_class = 1, 'JTT', 'JIMI')
                                     AND atb.alarm_code = a.alarm_type",
        // Evento de DIAGNÓSTICO (v4.9.9): o que o equipamento diz ao SISTEMA —
        // handshake de upload, sono/despertar, defeito de hardware — e não o
        // que o veículo diz ao operador. Some das telas de alarme e ocorrência;
        // a linha continua inteira em `alarms`, visível ao administrador.
        //
        // Vem dos MESMOS joins do rótulo, e isso não é economia: é o que faz o
        // filtro pegar as variantes `Fim de Alarme: …` sem conhecê-las. Elas
        // carregam o mesmo código do alarme de abertura (259, 257) e são 845
        // linhas no homolog; um filtro por nome precisaria enumerar cada uma.
        //
        // 🔴 `COALESCE(..., 0)` no fim é FALHA PARA O LADO DE MOSTRAR. Código
        // que não está no catálogo — `Código 1047 (JTT)` e `Código 146 (JIMI)`
        // eram os casos reais até a v4.9.10 cadastrá-los; o próximo código novo
        // do fabricante recria a situação — dá NULL nos dois joins. Sem o zero
        // final, a comparação com NULL
        // eliminaria a linha e um alarme novo desapareceria da tela em
        // silêncio, que é o modo de falha que o CLAUDE.md documenta três vezes.
        // Composto na frente da base, na mesma ordem do rótulo.
        'diag'  => "COALESCE(atc.is_diagnostic, atb.is_diagnostic, 0)",
        'expr' => "
            CASE WHEN COALESCE(atc.alarm_name_pt, atb.alarm_name_pt) IS NULL
                      THEN COALESCE(NULLIF(a.alarm_name, ''), a.alarm_type)
                 WHEN a.alarm_name LIKE 'Fim de Alarme: Código %'
                      THEN CONCAT('Fim de Alarme: ', COALESCE(atc.alarm_name_pt, atb.alarm_name_pt))
                 WHEN a.alarm_name IS NULL OR a.alarm_name = '' OR a.alarm_name LIKE 'Código %'
                      THEN COALESCE(atc.alarm_name_pt, atb.alarm_name_pt)
                 ELSE a.alarm_name
            END",
    ];
}

/**
 * Rótulo em pt-BR da CATEGORIA do alarme.
 *
 * A coluna `alarm_types.category` guarda um identificador estável (`conducao`,
 * `veiculo`, `seguranca`…), não um texto de tela. Ela é **chave de junção**:
 * `notification_engine.php` casa a regra por `at.category = nr.alarm_type`, e
 * as regras do homolog casam TODAS por categoria. Por isso a tradução é feita
 * aqui, na exibição, e nunca gravando o texto traduzido na coluna — trocar o
 * valor gravado faria a notificação parar de disparar em silêncio (v4.9.5
 * normalizou os valores e precisou remapear as regras junto; ver CLAUDE.md).
 *
 * `DMS` e `ADAS` são siglas técnicas do setor, não palavras em inglês, e
 * `rel_alarmes.php` filtra por `category IN ('DMS','ADAS')`. Ficam como estão,
 * com o significado expandido só no rótulo.
 *
 * Categoria desconhecida volta capitalizada em vez de sumir: rótulo feio é
 * melhor do que `<optgroup>` vazio, e denuncia a categoria nova que ninguém
 * traduziu.
 *
 * @param string|null $cat Valor cru de `alarm_types.category`
 * @returns string         Texto pronto para a tela
 */
function alarm_category_label(?string $cat): string {
    $map = [
        'DMS'         => 'DMS — Monitoramento do Motorista',
        'ADAS'        => 'ADAS — Assistência à Condução',
        'acidente'    => 'Acidente',
        'cerca'       => 'Cerca Eletrônica',
        'conducao'    => 'Condução',
        'dispositivo' => 'Dispositivo',
        'emergencia'  => 'Emergência',
        'energia'     => 'Energia',
        'pessoal'     => 'Pessoal',
        'seguranca'   => 'Segurança',
        'sensor'      => 'Sensor',
        'veiculo'     => 'Veículo',
        'video'       => 'Vídeo',
    ];
    $key = trim((string)$cat);
    if ($key === '') return 'Sem categoria';
    // Busca sem diferenciar caixa: a collation da coluna é `_ci` e bases
    // antigas podem ter 'Video' onde a v4.9.5 gravou 'video'.
    foreach ($map as $k => $label) {
        if (strcasecmp($k, $key) === 0) return $label;
    }
    return mb_convert_case($key, MB_CASE_TITLE, 'UTF-8');
}

/**
 * Rótulo em pt-BR da SEVERIDADE do alarme.
 *
 * O enum de `alarm_types.severity` é em inglês e é usado em comparação SQL
 * (`relatorios.php` filtra por ele), então o VALOR não muda — só o texto que
 * chega à tela. Antes disso, a badge do Detalhe do Ativo e o filtro do
 * Relatório de Alarmes imprimiam `critical` / `warning` crus para o usuário.
 *
 * @param string|null $sev Valor cru de `alarm_types.severity`
 * @returns string         Texto pronto para a tela
 */
function alarm_severity_label(?string $sev): string {
    $map = [
        'critical' => 'Crítica',
        'high'     => 'Alta',
        'warning'  => 'Atenção',
        'medium'   => 'Média',
        'low'      => 'Baixa',
        'info'     => 'Informativa',
    ];
    $key = strtolower(trim((string)$sev));
    return $map[$key] ?? ($key === '' ? 'Informativa' : mb_convert_case($key, MB_CASE_TITLE, 'UTF-8'));
}

/**
 * Rótulo em pt-BR do RISCO da ocorrência.
 *
 * O enum é `baixo|medio|alto` — sem acento, porque é valor de banco. Quatro
 * telas imprimiam `ucfirst()` direto e mostravam **"Medio"** ao usuário, uma
 * delas no EXPORT do Relatório de Ocorrências, ou seja, no PDF/Excel que chega
 * ao cliente. O componente `web/components/status_pill.php` já acentuava certo;
 * o resultado era a mesma informação escrita de dois jeitos no mesmo sistema.
 *
 * @param string|null $risk baixo|medio|alto
 * @returns string
 */
function occurrence_risk_label(?string $risk): string {
    $map = ['baixo' => 'Baixo', 'medio' => 'Médio', 'alto' => 'Alto'];
    $key = strtolower(trim((string)$risk));
    if ($key === '') return '—';
    return $map[$key] ?? mb_convert_case($key, MB_CASE_TITLE, 'UTF-8');
}

/**
 * Rótulo de protocolo para a tela.
 *
 * `alarm_types.protocol` guarda `JTT`, mas o usuário lê **JT/T** em todo o
 * resto do sistema (badge do Detalhe do Ativo, Relatório de Alarmes). Duas
 * grafias para a mesma coisa fazem parecer que são protocolos diferentes.
 *
 * @param string|null $proto `JIMI` ou `JTT`
 * @returns string
 */
function protocol_label(?string $proto): string {
    return strcasecmp(trim((string)$proto), 'JTT') === 0 ? 'JT/T' : strtoupper(trim((string)$proto));
}

/**
 * Placas do escopo corrente, para o `<select>` de PLACA dos relatórios.
 *
 * Companheira de `report_customer_scope()`, pelo mesmo motivo de
 * `report_customer_options()`: o filtro tem de listar só o que o usuário pode
 * consultar. `$scopeCust` é o valor que aquela função devolveu — `null` só
 * acontece para admin de plataforma ("todos os clientes"), e aí a lista segue o
 * escopo de revendedor, se houver.
 *
 * 🔴 Filtra por `is_active = 1` desde 25/08/2026. Até então esta função de
 * propósito NÃO filtrava ("relatório é histórico, esconder tornaria dados
 * antigos inalcançáveis") — mas a v4.12.7 já tinha decidido o contrário para
 * o mesmo dropdown em `rel_deslocamento.php`/`rel_alarmes.php`/
 * `rel_posicoes.php`/`relatorios.php`/`exportar.php` (cada um com a consulta
 * copiada inline, com `is_active = 1`; `rel_deslocamento.php` e
 * `rel_posicoes.php` passaram a usar ESTA função na v4.17.1, junto com o
 * seletor de cliente que lhes faltava), e essa função COMPARTILHADA — usada
 * por `rel_ocorrencias.php`, `rel_geocercas.php`, `rel_velocidade.php`,
 * `rel_ignicao.php`, `rel_status_frota.php`, `report_segments.php` — ficou
 * de fora da varredura porque não é uma cópia da mesma query, é uma função à
 * parte. Câmera desativada continuou aparecendo nesses seis até o dono do
 * produto reportar de novo, especificamente em Ocorrências. O filtro não tira
 * acesso ao HISTÓRICO do equipamento — só ao dropdown; um relatório já aberto
 * com `?imei=` de um equipamento desativado continua funcionando.
 *
 * @param PDO      $db        Conexão ativa
 * @param int|null $scopeCust Cliente do escopo (null = todos os permitidos)
 * @param int      $limit     Teto de linhas (um `<select>` maior que isto é inusável)
 * @returns array<array{imei:string,device_name:?string}>
 */
function report_device_options(PDO $db, ?int $scopeCust = null, int $limit = 2000): array {
    $limit = max(1, $limit);
    try {
        if ($scopeCust !== null) {
            $stmt = $db->prepare("SELECT imei, device_name FROM devices WHERE customer_id = :cid AND is_active = 1
                                  ORDER BY device_name, imei LIMIT $limit");
            $stmt->execute([':cid' => $scopeCust]);
            return $stmt->fetchAll();
        }
        $allowed = reseller_scope_ids();
        if ($allowed === null) {
            return $db->query("SELECT imei, device_name FROM devices WHERE is_active = 1 ORDER BY device_name, imei LIMIT $limit")->fetchAll();
        }
        if (!$allowed) return [];
        // Ids já passaram por (int) em reseller_scope_ids() — ver report_customer_options()
        $in = implode(',', array_map('intval', $allowed));
        return $db->query("SELECT imei, device_name FROM devices WHERE customer_id IN ($in) AND is_active = 1
                           ORDER BY device_name, imei LIMIT $limit")->fetchAll();
    } catch (Throwable $e) {
        return [];   // falha fechada: o seletor fica vazio, não completo
    }
}

/**
 * `<select>` de placa dos filtros de relatório.
 *
 * O parâmetro continua se chamando `imei` na URL por retrocompatibilidade —
 * links antigos, modelos salvos em `report_templates` e o e-mail dos
 * agendamentos carregam essa chave. O que muda é só o que o usuário vê e
 * escolhe: a PLACA.
 *
 * @param array  $devices  Saída de report_device_options()
 * @param string $selected IMEI atualmente escolhido ('' = nenhum)
 * @param string $allLabel Rótulo da opção vazia
 * @param string $name     Nome do campo
 * @returns string HTML do <select>
 */
function report_device_select(array $devices, string $selected = '', string $allLabel = 'Todas', string $name = 'imei'): string {
    $html = '<select name="' . htmlspecialchars($name, ENT_QUOTES) . '"'
          . ' style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:170px;">'
          . '<option value="">' . htmlspecialchars($allLabel) . '</option>';
    foreach ($devices as $d) {
        $imei = (string)($d['imei'] ?? '');
        $html .= '<option value="' . htmlspecialchars($imei, ENT_QUOTES) . '"'
               . ($selected === $imei ? ' selected' : '') . '>'
               . htmlspecialchars(($d['device_name'] ?? '') ?: $imei) . '</option>';
    }
    return $html . '</select>';
}

/**
 * Clientes que o usuário logado pode enxergar QUANDO ele é revendedor.
 *
 * Devolve `null` — "não se aplica, não restrinja" — para admin de plataforma
 * (`role === 'admin'`) e para quem não é revendedor. Só nesses dois casos o
 * chamador mantém o comportamento antigo.
 *
 * O conjunto é a UNIÃO de dois vínculos, e os dois são necessários:
 *  - `customers.reseller_id = <user>` — carimbado por `handlers/clientes.php`
 *    quando um revendedor cria um cliente. Era escrito e **nunca lido**: esta
 *    função é o primeiro leitor da coluna.
 *  - `customer_users` — o vínculo explícito. Sem ele, todo revendedor de base
 *    já existente (onde `reseller_id` é NULL em 100% das linhas) passaria a
 *    não enxergar cliente nenhum, que é trocar um vazamento por um apagão.
 *
 * Em erro de banco devolve lista **vazia**, não `null`: falha fechada.
 *
 * @returns int[]|null  IDs permitidos, ou null se a restrição não se aplica
 */
function reseller_scope_ids(): ?array {
    if (!function_exists('get_jimi_user')) return null;
    $user = get_jimi_user();
    if (!$user) return null;
    if (($user['role'] ?? '') === 'admin') return null;
    if (($user['user_type'] ?? '') !== 'revendedor') return null;

    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT c.id FROM customers c WHERE c.reseller_id = :uid
             UNION
             SELECT cu.customer_id FROM customer_users cu WHERE cu.user_id = :uid2"
        );
        $stmt->execute([':uid' => $user['id'], ':uid2' => $user['id']]);
        $cache = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        $cache = [];
    }
    return $cache;
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
 * Rótulo do período para o cabeçalho dos relatórios exportados (v4.8.3).
 *
 * Ponto único do texto "Período: …" que sai no subtítulo do PDF. Três decisões,
 * todas vindas de como o documento é lido fora da tela que o gerou:
 *
 *   1. SEM o sufixo "(BRT)". O sistema inteiro exibe em horário de Brasília e
 *      nunca oferece outro fuso; anotar o fuso em cada relatório só levantava a
 *      dúvida de que pudesse haver outro.
 *   2. Data em DD/MM/AAAA, não no ISO `Y-m-d` que vem do `<input type="date">`.
 *      "2026-08-01 a 2026-08-02" é o valor cru do formulário vazando no papel.
 *   3. A HORA É SEMPRE ESCRITA, inclusive quando o filtro de faixa horária ficou
 *      vazio — aí ela vira `00:00:00`/`23:59:59`, que é exatamente a janela
 *      consultada. Omitir a hora deixava ambíguo se o dia final entrava inteiro.
 *
 * Os segundos (`:00` no início, `:59` no fim) espelham brt_datetime_range_to_utc():
 * o rótulo descreve a janela REAL da consulta, não uma aproximação dela.
 *
 * @param string $dateFrom Dia inicial (BRT, 'Y-m-d')
 * @param string $dateTo   Dia final (BRT, 'Y-m-d')
 * @param string $timeFrom Hora inicial ('H:i'; vazio = 00:00)
 * @param string $timeTo   Hora final ('H:i'; vazio = 23:59)
 * @param string $timeMode 'continua' (janela única) | 'diaria' (faixa repetida a cada dia)
 * @returns string Ex.: "Período: 01/08/2026 00:00:00 a 02/08/2026 23:59:59"
 */
function report_period_label(string $dateFrom, string $dateTo, string $timeFrom = '', string $timeTo = '', string $timeMode = 'continua'): string {
    $dia = function (string $d): string {
        $ts = strtotime($d);
        return $ts ? date('d/m/Y', $ts) : $d;
    };
    $hi = preg_match('/^\d{2}:\d{2}$/', $timeFrom) ? $timeFrom : '00:00';
    $hf = preg_match('/^\d{2}:\d{2}$/', $timeTo)   ? $timeTo   : '23:59';

    $txt = 'Período: ' . $dia($dateFrom) . " $hi:00 a " . $dia($dateTo) . " $hf:59";

    // No modo diário a faixa horária NÃO é uma janela contínua: ela se repete
    // dentro de cada dia. Sem esta nota o mesmo texto descreveria duas
    // consultas diferentes.
    if ($timeMode === 'diaria' && ($timeFrom !== '' || $timeTo !== '')) {
        $txt = 'Período: ' . $dia($dateFrom) . ' a ' . $dia($dateTo)
             . " — faixa de $hi:00 a $hf:59 em cada dia";
    }
    return $txt;
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
/**
 * Rótulo do veículo nas telas de operação — o que este projeto chama de PLACA.
 *
 * ── A CONVENÇÃO (decisão do dono do produto, 20/08/2026) ────────────────────
 * **"Placa" é o que estiver cadastrado em `devices.device_name`, e pronto.** É
 * texto LIVRE: não é validado, não é normalizado e **não precisa parecer uma
 * placa**. `ABC1D23`, `Câmera Frontal Ônibus 12` e `Frota 07` são todos válidos
 * — o campo identifica o veículo do jeito que o cliente identifica, e o sistema
 * não tem opinião sobre isso.
 *
 * ⚠️ Não escreva validação de formato aqui nem em cadastro: recusar
 * `Ônibus 12` porque "não é placa" quebraria clientes que nomeiam a frota assim.
 * O campo se chama **Placa** em toda tela — cadastro, grade, filtro, coluna de
 * relatório e export. Ele já teve três nomes ("Nome do Dispositivo",
 * "Dispositivo", "Placa"), o que fazia parecer campos diferentes.
 *
 * 🔴 POR QUE NÃO É SÓ `$device_name ?: $imei`. Sem nada cadastrado, o operador
 * lê 15 dígitos numa coluna chamada "Placa" e vai procurar um veículo que não
 * existe. E a armadilha é dupla: várias consultas do sistema já trazem
 * `COALESCE(NULLIF(device_name,''), imei)`, então o campo vazio chega como o
 * PRÓPRIO IMEI e nunca como vazio — um `?:` no template não dispara, e o número
 * passa como se fosse identificação. Estava assim em três telas ao mesmo tempo.
 *
 * O VALOR do filtro continua sendo o IMEI: é por ele que as consultas casam, e
 * como o campo é livre, dois veículos podem perfeitamente ter o mesmo texto.
 * Isto aqui é só o que a pessoa LÊ.
 *
 * @param string|null $deviceName `devices.device_name` — cru ou já coalescido
 * @param string      $imei       IMEI do equipamento
 * @returns string O texto cadastrado, ou `(sem placa) <imei>` quando não há
 */
function placa_do_device(?string $deviceName, string $imei): string
{
    $nome = trim((string)$deviceName);
    return ($nome === '' || $nome === $imei) ? '(sem placa) ' . $imei : $nome;
}

/**
 * Base da URL que a câmera JIMI usa para subir a lista do cartão (v4.9.18).
 *
 * Termina com barra; o IMEI é concatenado pelo chamador, formando
 * `http://<host>/filelist/<imei>` — o caminho de handlers/filelist.php.
 *
 * ⚠️ HTTP, NUNCA HTTPS: a câmera JIMI não faz TLS. O redirect 80→443 do site
 * tem exceção só para `/filelist/` (ver docs/apache/bycamera.conf).
 *
 * ⚠️ IP, NÃO DOMÍNIO — por isso reaproveita `VIDEO_INGEST_IP`, que já é, por
 * definição, "o endereço que o EQUIPAMENTO alcança". Muitos firmwares JIMI não
 * resolvem DNS: com um nome, aceitam o comando e nunca conectam. É a mesma
 * razão pela qual o `UPLOAD` das câmeras sempre foi configurado por IP.
 *
 * 🔴 **DEVOLVE VAZIO EM VEZ DE `localhost`** (v4.9.35). O fallback anterior era
 * `localhost`, e `localhost` para a câmera é *ela mesma*: o equipamento aceita
 * o comando (`FILELIST:OK!`), guarda o endereço e nunca alcança nada. Aconteceu
 * de verdade num teste de campo — só o construtor do `Database` carrega o
 * `.env` (`config/database.php`), então qualquer chamador que não tenha
 * instanciado a conexão antes recebia o fallback e envenenava a configuração do
 * device em silêncio. Vazio obriga o chamador a recusar, com motivo na tela.
 *
 * @returns string URL base com barra final, ou '' quando não há endereço que o
 *                 EQUIPAMENTO consiga alcançar
 */
function filelist_url_base(): string
{
    $cfg = trim((string)getenv('FILELIST_URL'));
    if ($cfg !== '') {
        return rtrim($cfg, '/') . '/';
    }
    $host = trim((string)getenv('VIDEO_INGEST_IP'));
    if ($host === '') {
        $host = (string)(parse_url((string)(getenv('STREAM_URL') ?: ''), PHP_URL_HOST) ?: '');
    }
    // Endereço de loopback nunca é alcançável pelo equipamento — é o mesmo
    // motivo pelo qual esta função existe em vez de um literal no template.
    if ($host === '' || in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
        return '';
    }
    return 'http://' . $host . '/filelist/';
}

/**
 * Validade da listagem de gravações do cartão, em minutos (v4.9.17).
 *
 * O cartão é buffer circular e a retenção real depende de quantas horas o
 * veículo roda — não há número fixo de dias. Por isso a lista não tenta ser um
 * acervo: é um RETRATO com hora de validade curta. Vencida, ela deixa de ser
 * acionável e a tela pede uma nova, em vez de oferecer arquivo que muito
 * provavelmente já foi sobrescrito.
 *
 * 30 min é a decisão do dono do produto (14/08/2026): longo o bastante para
 * trabalhar a lista, curto o bastante para não enganar.
 *
 * @returns int Minutos
 */
function resource_list_ttl_minutes(): int
{
    $v = (int)(getenv('RESOURCE_LIST_TTL_MINUTES') ?: 30);
    return $v > 0 ? $v : 30;
}

/**
 * Família de cada modelo do catálogo: `camera` ou `tracker` (v4.16.0).
 *
 * 🔴 POR QUE ISTO EXISTE. O flag `universal` do `command_catalog.php` quer
 * dizer "não trava a seleção por modelo", e foi DERIVADO de "presente em >= 5
 * das 6 páginas de CÂMERA da wiki". Enquanto a frota inteira era câmera, "não
 * trava" e "vale para todo mundo" eram a mesma frase. Com os rastreadores da
 * linha JM-VL deixaram de ser: soltar a trava passou a oferecer `RECORDSW`,
 * `VOLUME`, `SSID` e `WIFIAP` a um aparelho que não os entende. A família é o
 * que devolve a `universal` o sentido que ele sempre teve.
 *
 * ⚠️ "Rastreador não tem vídeo" é verdade; "não tem WiFi" NÃO é. O JM-VL01 é
 * hotspot WiFi e também entra como cliente numa rede pelo Android dele — só
 * que a forma dele é o comando `HOTSPOT`, não `WIFIAP`/`SSID`.
 *
 * 🔴 PONTO ÚNICO de propósito: DUAS telas travam a seleção pelo MESMO catálogo
 * — `/comandos` (proNo 128 pelo IoT Hub) e `/comandos-sms` (o mesmo catálogo
 * por SMS). Corrigir só uma deixaria a outra oferecendo comando de câmera para
 * rastreador, que é o defeito inteiro, por um caminho diferente.
 *
 * ⚠️ try/catch com default 'camera': a coluna vem da migração v4.16.0, e
 * migração nova NÃO roda no deploy que a traz (CLAUDE.md). No intervalo entre
 * os dois deploys as telas continuam funcionando exatamente como funcionavam
 * antes — que é o que "todo mundo é câmera" quer dizer.
 *
 * @param  PDO $db Conexão
 * @returns array<string,string> model_name => 'camera'|'tracker'
 */
function device_model_families(PDO $db): array
{
    $mapa = [];
    try {
        foreach ($db->query("SELECT model_name, family FROM device_models") as $m) {
            $mapa[$m['model_name']] = $m['family'] ?: 'camera';
        }
    } catch (Throwable $e) {
        // `class_exists` porque este arquivo é o ÚNICO de `includes/` que não
        // depende do Logger — ele é carregado por `auth.php` ANTES do
        // `core/Logger.php`, e por scripts de CLI que não carregam nenhum dos
        // dois. Um "Class Logger not found" aqui trocaria um aviso por um fatal.
        if (class_exists('Logger')) {
            Logger::warning('device_model_families: coluna `family` indisponível — aplique a migração v4.16.0',
                            ['erro' => $e->getMessage()]);
        }
    }
    return $mapa;
}

/**
 * Famílias que um comando do catálogo documenta, derivadas de `modelos`.
 *
 * Comando sem modelo nenhum cai em `['camera']` — o comportamento anterior à
 * v4.16.0, e a resposta conservadora: quem não sabe a que família pertence não
 * ganha rastreador de brinde.
 *
 * @param  array<int,string>    $modelos  `modelos` da entrada do catálogo
 * @param  array<string,string> $familias Saída de `device_model_families()`
 * @returns array<int,string> Famílias distintas
 */
function command_families(array $modelos, array $familias): array
{
    if (!$modelos) return ['camera'];
    $f = [];
    foreach ($modelos as $m) $f[$familias[$m] ?? 'camera'] = 1;
    return array_keys($f);
}

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
