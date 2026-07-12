<?php
/**
 * Geocodificação Reversa com Cache v4.0.0
 *
 * Obtém endereço a partir de coordenadas lat/lng.
 * Usa a tabela geocode_cache para evitar consultas repetidas à API externa.
 *
 * Uso:
 *   $address = reverse_geocode($lat, $lng);
 *   // Retorna string do endereço ou null
 *
 * API externa: Nominatim (OpenStreetMap) — gratuito, respeitar rate limit de 1 req/s
 */

require_once __DIR__ . '/../config/database.php';

function reverse_geocode(float $lat, float $lng): ?string
{
    if ($lat == 0 && $lng == 0) {
        return null;
    }

    // geocode_cache usa DECIMAL(9,6): sem arredondar, um lat/lng de 8 casas
    // nunca casa com a linha cacheada (6 casas) e a API era rechamada sempre
    $lat = round($lat, 6);
    $lng = round($lng, 6);

    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("SELECT address FROM geocode_cache WHERE lat = :lat AND lng = :lng LIMIT 1");
    $stmt->execute([':lat' => $lat, ':lng' => $lng]);
    $row = $stmt->fetch();

    if ($row) {
        return $row['address'];
    }

    $url = sprintf(
        'https://nominatim.openstreetmap.org/reverse?lat=%.6f&lon=%.6f&format=json&accept-language=pt-BR&zoom=18',
        $lat,
        $lng
    );

    $ctx = stream_context_create([
        'http' => [
            'header' => "User-Agent: JimiWebhook/4.0\r\n",
            'timeout' => 5,
        ],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);
    $address = $data['display_name'] ?? null;

    if ($address) {
        $stmt = $db->prepare("INSERT IGNORE INTO geocode_cache (lat, lng, address) VALUES (:lat, :lng, :addr)");
        $stmt->execute([':lat' => $lat, ':lng' => $lng, ':addr' => $address]);
    }

    return $address;
}

/**
 * Lookup em lote SOMENTE no cache (nenhuma chamada HTTP) — para grades.
 *
 * @param array $points Lista de pares [lat, lng]
 * @returns array Mapa "lat,lng" (6 casas decimais) → endereço
 */
function geocode_cache_lookup(array $points): array
{
    if (empty($points)) return [];
    $db = Database::getInstance()->getConnection();

    $conds = [];
    $params = [];
    $seen = [];
    foreach ($points as $p) {
        $lat = round((float)$p[0], 6);
        $lng = round((float)$p[1], 6);
        $key = $lat . ',' . $lng;
        if ($lat == 0 || isset($seen[$key])) continue;
        $seen[$key] = true;
        $conds[] = '(lat = ? AND lng = ?)';
        $params[] = $lat;
        $params[] = $lng;
    }
    if (empty($conds)) return [];

    $map = [];
    try {
        $stmt = $db->prepare("SELECT lat, lng, address FROM geocode_cache WHERE " . implode(' OR ', $conds));
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $map[round((float)$row['lat'], 6) . ',' . round((float)$row['lng'], 6)] = $row['address'];
        }
    } catch (Exception $e) {}
    return $map;
}
