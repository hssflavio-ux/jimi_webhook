<?php
/**
 * JIMI Webhook System — Geometria de Geocercas v4.5.0
 *
 * Funções puras (sem I/O, sem banco) que respondem a uma única pergunta:
 * este ponto está dentro desta cerca? Ficam separadas do worker justamente
 * para poderem ser exercitadas isoladamente — o teste do ray casting em
 * polígono côncavo é critério de aceite da Fase 2.
 *
 * Duas geometrias:
 *   circulo  — center_lat/center_lng + radius_m, testado por haversine_km()
 *   poligono — array [[lat,lng],...], testado por ray casting (PNPOLY)
 *
 * Toda medida de distância usa haversine_km() de includes/functions.php.
 * NUNCA calculate_distance(): aquela devolve 0 quando uma latitude é 0, o que
 * transformaria qualquer ponto na linha do Equador em "dentro de tudo".
 */

require_once __DIR__ . '/functions.php';

/**
 * Folga anti-flapping, em metros.
 *
 * Um veículo estacionado exatamente sobre a borda oscila dentro/fora a cada
 * ponto de GPS (a precisão típica já é de 5–15 m) e geraria dezenas de pares
 * entrada/saída em meia hora. Com a histerese, ENTRAR exige cruzar a borda
 * real, mas SAIR exige afastar-se mais de 50 m dela — a oscilação natural
 * deixa de produzir evento.
 */
const GEOFENCE_HYSTERESIS_M = 50.0;

/** Metros por grau de latitude (constante o bastante nesta escala). */
const GEO_M_PER_DEG_LAT = 111320.0;

/**
 * Normaliza o polígono vindo do banco (JSON) ou do formulário.
 *
 * Aceita `[[lat,lng],...]` e `[{"lat":…,"lng":…},...]`. Descarta vértices
 * malformados e devolve `[]` se sobrarem menos de 3 — um polígono com 2
 * vértices é uma linha, e nada está "dentro" de uma linha.
 *
 * @param mixed $raw JSON string ou array já decodificado
 * @returns array Lista de pares [lat, lng] (float), ou [] se inválido
 */
function geofence_normalize_polygon($raw): array
{
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    foreach ($raw as $vertex) {
        if (is_array($vertex) && isset($vertex['lat'], $vertex['lng'])) {
            $lat = (float)$vertex['lat'];
            $lng = (float)$vertex['lng'];
        } elseif (is_array($vertex) && array_key_exists(0, $vertex) && array_key_exists(1, $vertex)) {
            $lat = (float)$vertex[0];
            $lng = (float)$vertex[1];
        } else {
            continue;
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            continue;
        }
        $out[] = [$lat, $lng];
    }

    // Vértice de fechamento repetido (padrão GeoJSON) é redundante aqui: o
    // ray casting já trata o polígono como fechado.
    $n = count($out);
    if ($n > 1 && $out[0][0] === $out[$n - 1][0] && $out[0][1] === $out[$n - 1][1]) {
        array_pop($out);
    }

    return count($out) >= 3 ? $out : [];
}

/**
 * Calcula a bounding box da cerca.
 *
 * Gravada em geofences ao salvar (e não recalculada a cada avaliação): é o
 * pré-filtro que descarta a maioria dos pares ponto×cerca com quatro
 * comparações de float, antes de qualquer trigonometria.
 *
 * @param array $fence Campos shape, center_lat, center_lng, radius_m, polygon
 * @returns array|null [min_lat, max_lat, min_lng, max_lng], ou null se a
 *                     geometria estiver incompleta
 */
function geofence_bbox(array $fence): ?array
{
    $shape = $fence['shape'] ?? 'circulo';

    if ($shape === 'poligono') {
        $poly = geofence_normalize_polygon($fence['polygon'] ?? null);
        if (!$poly) {
            return null;
        }
        $lats = array_column($poly, 0);
        $lngs = array_column($poly, 1);
        return [min($lats), max($lats), min($lngs), max($lngs)];
    }

    $lat = $fence['center_lat'] ?? null;
    $lng = $fence['center_lng'] ?? null;
    $rad = (float)($fence['radius_m'] ?? 0);
    if ($lat === null || $lng === null || $rad <= 0) {
        return null;
    }
    $lat = (float)$lat;
    $lng = (float)$lng;

    $dLat = $rad / GEO_M_PER_DEG_LAT;
    // O grau de longitude encurta com o cosseno da latitude; sem isso a bbox
    // ficaria estreita demais longe do Equador e cortaria pontos válidos.
    $cos  = max(cos(deg2rad($lat)), 0.000001);
    $dLng = $rad / (GEO_M_PER_DEG_LAT * $cos);

    return [
        max(-90.0,  $lat - $dLat),
        min(90.0,   $lat + $dLat),
        max(-180.0, $lng - $dLng),
        min(180.0,  $lng + $dLng),
    ];
}

/**
 * Pré-filtro: o ponto cai na bounding box da cerca (com folga opcional)?
 *
 * Cerca sem bbox gravada (registro antigo ou geometria incompleta) devolve
 * true — melhor pagar o cálculo exato do que perder o evento em silêncio.
 *
 * @param array $fence Cerca com bbox_min_lat/bbox_max_lat/bbox_min_lng/bbox_max_lng
 * @param float $lat   Latitude do ponto
 * @param float $lng   Longitude do ponto
 * @param float $padM  Folga em metros aplicada aos quatro lados
 * @returns bool
 */
function geofence_bbox_contains(array $fence, float $lat, float $lng, float $padM = 0.0): bool
{
    if (!isset($fence['bbox_min_lat'], $fence['bbox_max_lat'], $fence['bbox_min_lng'], $fence['bbox_max_lng'])
        || $fence['bbox_min_lat'] === null || $fence['bbox_max_lat'] === null
        || $fence['bbox_min_lng'] === null || $fence['bbox_max_lng'] === null) {
        return true;
    }

    $padLat = $padM / GEO_M_PER_DEG_LAT;
    $cos    = max(cos(deg2rad($lat)), 0.000001);
    $padLng = $padM / (GEO_M_PER_DEG_LAT * $cos);

    return $lat >= (float)$fence['bbox_min_lat'] - $padLat
        && $lat <= (float)$fence['bbox_max_lat'] + $padLat
        && $lng >= (float)$fence['bbox_min_lng'] - $padLng
        && $lng <= (float)$fence['bbox_max_lng'] + $padLng;
}

/**
 * Ray casting (PNPOLY): o ponto está dentro do polígono?
 *
 * Dispara um raio no sentido +longitude e conta cruzamentos com as arestas —
 * ímpar = dentro. Lida corretamente com polígonos côncavos (o "vão" de um
 * formato em L acumula 2 cruzamentos e classifica como fora), que é
 * exatamente onde uma checagem por bounding box erraria.
 *
 * @param float $lat  Latitude do ponto
 * @param float $lng  Longitude do ponto
 * @param array $poly Lista de vértices [lat, lng] já normalizada
 * @returns bool
 */
function point_in_polygon(float $lat, float $lng, array $poly): bool
{
    $n = count($poly);
    if ($n < 3) {
        return false;
    }

    $inside = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $latI = (float)$poly[$i][0];
        $lngI = (float)$poly[$i][1];
        $latJ = (float)$poly[$j][0];
        $lngJ = (float)$poly[$j][1];

        // A aresta cruza a latitude do ponto? (a comparação assimétrica > / >
        // é o que evita contar duas vezes um vértice exatamente na horizontal)
        if (($latI > $lat) !== ($latJ > $lat)) {
            $lngAtLat = ($lngJ - $lngI) * ($lat - $latI) / ($latJ - $latI) + $lngI;
            if ($lng < $lngAtLat) {
                $inside = !$inside;
            }
        }
    }

    return $inside;
}

/**
 * Distância, em metros, do ponto à borda do polígono (aresta mais próxima).
 *
 * Usada só pela histerese, sobre distâncias de dezenas de metros, então uma
 * projeção equirretangular local é mais que suficiente — e evita chamar
 * haversine em cada aresta.
 *
 * @param float $lat  Latitude do ponto
 * @param float $lng  Longitude do ponto
 * @param array $poly Vértices [lat, lng] normalizados
 * @returns float Metros até a aresta mais próxima (INF se polígono inválido)
 */
function point_distance_to_polygon_m(float $lat, float $lng, array $poly): float
{
    $n = count($poly);
    if ($n < 3) {
        return INF;
    }

    $mPerLat = GEO_M_PER_DEG_LAT;
    $mPerLng = GEO_M_PER_DEG_LAT * max(cos(deg2rad($lat)), 0.000001);

    // Origem no próprio ponto: o alvo passa a ser a distância até a origem.
    $px = 0.0;
    $py = 0.0;
    $best = INF;

    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $ax = ((float)$poly[$j][1] - $lng) * $mPerLng;
        $ay = ((float)$poly[$j][0] - $lat) * $mPerLat;
        $bx = ((float)$poly[$i][1] - $lng) * $mPerLng;
        $by = ((float)$poly[$i][0] - $lat) * $mPerLat;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len2 = $dx * $dx + $dy * $dy;

        if ($len2 <= 0.0) {
            $dist = sqrt($ax * $ax + $ay * $ay); // aresta degenerada (vértice duplicado)
        } else {
            // Projeção escalar do ponto sobre a aresta, presa ao segmento
            $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2;
            $t = max(0.0, min(1.0, $t));
            $cx = $ax + $t * $dx;
            $cy = $ay + $t * $dy;
            $dist = sqrt($cx * $cx + $cy * $cy);
        }

        if ($dist < $best) {
            $best = $dist;
        }
    }

    return $best;
}

/**
 * O ponto está dentro da cerca, considerando a histerese anti-flapping?
 *
 * A resposta depende do estado anterior de propósito. A borda não é uma
 * linha, é uma faixa de GEOFENCE_HYSTERESIS_M metros:
 *   - estava FORA  → só entra cruzando a borda real;
 *   - estava DENTRO → só sai passando 50 m além da borda.
 * Dentro da faixa o estado anterior é mantido, e o veículo parado sobre o
 * portão deixa de gerar uma enxurrada de entradas e saídas.
 *
 * @param float $lat        Latitude do ponto
 * @param float $lng        Longitude do ponto
 * @param array $fence      Cerca (shape, geometria, bbox)
 * @param bool  $wasInside  Estado anterior do equipamento nesta cerca
 * @returns bool Novo estado (true = dentro)
 */
function point_in_geofence(float $lat, float $lng, array $fence, bool $wasInside = false): bool
{
    $margin = $wasInside ? GEOFENCE_HYSTERESIS_M : 0.0;

    // Pré-filtro barato. Com margem, para não descartar um ponto que a
    // histerese ainda considera dentro.
    if (!geofence_bbox_contains($fence, $lat, $lng, $margin)) {
        return false;
    }

    if (($fence['shape'] ?? 'circulo') === 'poligono') {
        $poly = geofence_normalize_polygon($fence['polygon'] ?? null);
        if (!$poly) {
            return false;
        }
        $inside = point_in_polygon($lat, $lng, $poly);
        if ($inside) {
            return true;
        }
        // Fora do polígono, mas estava dentro: só sai depois da faixa de folga.
        // A distância é medida até a ARESTA (e não até a bbox) — numa cerca em
        // "L", a bbox expandida manteria "dentro" um veículo parado no vão da
        // concavidade, a centenas de metros da área real.
        return $wasInside && point_distance_to_polygon_m($lat, $lng, $poly) <= GEOFENCE_HYSTERESIS_M;
    }

    // Círculo
    $cLat = $fence['center_lat'] ?? null;
    $cLng = $fence['center_lng'] ?? null;
    $rad  = (float)($fence['radius_m'] ?? 0);
    if ($cLat === null || $cLng === null || $rad <= 0) {
        return false;
    }

    $distM = haversine_km((float)$cLat, (float)$cLng, $lat, $lng) * 1000.0;
    return $distM <= ($rad + $margin);
}

/**
 * Rótulo legível da geometria, para grades e relatórios.
 *
 * @param array $fence Cerca
 * @returns string Ex.: "Círculo · 200 m" ou "Polígono · 6 vértices"
 */
function geofence_shape_label(array $fence): string
{
    if (($fence['shape'] ?? '') === 'poligono') {
        $n = count(geofence_normalize_polygon($fence['polygon'] ?? null));
        return 'Polígono · ' . $n . ' vértices';
    }
    $r = (int)($fence['radius_m'] ?? 0);
    return 'Círculo · ' . ($r >= 1000 ? number_format($r / 1000, 1, ',', '.') . ' km' : $r . ' m');
}
