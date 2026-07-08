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
