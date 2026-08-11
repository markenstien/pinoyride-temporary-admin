<?php
declare(strict_types=1);

// Google Maps Platform helpers used by booking_create.php to turn free-text
// addresses into coordinates and to compute driving distance/duration —
// pickup_location strings already stored in public.booking (e.g. "40 Falcon,
// Quezon City, Metro Manila, Philippines") match Google's formatted_address
// output, so the real app is already on this stack; this keeps admin-created
// bookings consistent with it.

function google_maps_api_key(): string
{
    return getenv('GOOGLE_MAPS_API_KEY') ?: '';
}

function google_maps_configured(): bool
{
    return google_maps_api_key() !== '';
}

function google_maps_http_get(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) return null;

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

// Geocode a free-text address, biased to the Philippines. Returns
// ['lat' => string, 'lng' => string, 'formatted_address' => string] or null
// if the API isn't configured, the address is empty, or nothing was found.
function geocode_address(string $address): ?array
{
    $address = trim($address);
    if ($address === '' || !google_maps_configured()) return null;

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address'    => $address,
        'region'     => 'ph',
        'components' => 'country:PH',
        'key'        => google_maps_api_key(),
    ]);

    $json = google_maps_http_get($url);
    if (!$json || ($json['status'] ?? '') !== 'OK' || empty($json['results'][0])) {
        return null;
    }

    $result = $json['results'][0];
    return [
        'lat'               => (string)$result['geometry']['location']['lat'],
        'lng'               => (string)$result['geometry']['location']['lng'],
        'formatted_address' => (string)$result['formatted_address'],
    ];
}

// Driving distance/duration between two coordinates. Returns
// ['distance_km' => float, 'distance_text' => string,
//  'duration_minutes' => float, 'duration_text' => string] or null if the
// API isn't configured or no route could be found.
function distance_matrix(float $originLat, float $originLng, float $destLat, float $destLng): ?array
{
    if (!google_maps_configured()) return null;

    $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?' . http_build_query([
        'origins'      => "{$originLat},{$originLng}",
        'destinations' => "{$destLat},{$destLng}",
        'mode'         => 'driving',
        'units'        => 'metric',
        'key'          => google_maps_api_key(),
    ]);

    $json = google_maps_http_get($url);
    $element = $json['rows'][0]['elements'][0] ?? null;
    if (!$element || ($element['status'] ?? '') !== 'OK') {
        return null;
    }

    return [
        'distance_km'      => $element['distance']['value'] / 1000,
        'distance_text'    => (string)$element['distance']['text'],
        'duration_minutes' => $element['duration']['value'] / 60,
        'duration_text'    => (string)$element['duration']['text'],
    ];
}

// Same as distance_matrix() but for many origins -> one destination in as
// few API calls as possible (Distance Matrix caps at 25 origins per
// request, so this chunks transparently). $origins is keyed however the
// caller likes (e.g. rider id) — each value is [lat, lng]; the return array
// uses the same keys, omitting any origin the API couldn't route. Used by
// nearby_drivers.php to get ETA for a whole page of candidate drivers
// without one HTTP round-trip per driver.
function distance_matrix_batch(array $origins, float $destLat, float $destLng): array
{
    if (!google_maps_configured() || empty($origins)) return [];

    $results = [];
    foreach (array_chunk($origins, 25, true) as $chunk) {
        $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?' . http_build_query([
            'origins'      => implode('|', array_map(fn($o) => "{$o[0]},{$o[1]}", $chunk)),
            'destinations' => "{$destLat},{$destLng}",
            'mode'         => 'driving',
            'units'        => 'metric',
            'key'          => google_maps_api_key(),
        ]);

        $json = google_maps_http_get($url);
        if (!$json || ($json['status'] ?? '') !== 'OK' || empty($json['rows'])) {
            continue;
        }

        $keys = array_keys($chunk);
        foreach ($json['rows'] as $i => $row) {
            $key = $keys[$i] ?? null;
            $element = $row['elements'][0] ?? null;
            if ($key === null || !$element || ($element['status'] ?? '') !== 'OK') {
                continue;
            }
            $results[$key] = [
                'distance_km'      => $element['distance']['value'] / 1000,
                'distance_text'    => (string)$element['distance']['text'],
                'duration_minutes' => $element['duration']['value'] / 60,
                'duration_text'    => (string)$element['duration']['text'],
            ];
        }
    }
    return $results;
}

// Straight-line fallback (km) for when Distance Matrix is unavailable but we
// still have both coordinates — used to keep the flow usable rather than
// hard-blocking on a single API call failure. Always an underestimate of
// real driving distance.
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadiusKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusKm * $c;
}
