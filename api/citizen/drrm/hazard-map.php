<?php

declare(strict_types=1);

use App\Services\DrrmCitizenHazardMapReadService;

require_once __DIR__ . '/../../../src/Services/DrrmCitizenHazardMapReadService.php';

ini_set('display_errors', '0');

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    header('Cache-Control: public, max-age=300, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Allow: GET, OPTIONS');
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    citizenHazardMapRespond(false, null, 'Method not allowed.', 405);
}
if (count($_GET) !== 1 || !array_key_exists('layer', $_GET) || !is_string($_GET['layer'])) {
    citizenHazardMapRespond(false, null, 'Unsupported query parameters.', 400);
}

$layer = trim($_GET['layer']);
if (!in_array($layer, DrrmCitizenHazardMapReadService::SUPPORTED_LAYERS, true)) {
    citizenHazardMapRespond(false, $layer, 'Unsupported hazard-map layer.', 400);
}

try {
    $service = new DrrmCitizenHazardMapReadService(__DIR__ . '/../../../data/import');
    citizenHazardMapRespond(true, $layer, null, 200, $service->layer($layer));
} catch (Throwable) {
    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }
    citizenHazardMapRespond(false, $layer, 'Hazard map information is temporarily unavailable.', 503);
}

/** @param array<string, mixed>|null $data */
function citizenHazardMapRespond(
    bool $success,
    ?string $layer,
    ?string $message = null,
    int $statusCode = 200,
    ?array $data = null
): never {
    http_response_code($statusCode);
    $payload = $success && $data !== null
        ? ['success' => true] + $data
        : [
            'success' => false,
            'city' => DrrmCitizenHazardMapReadService::CITY_NAME,
            'layer' => $layer,
            'message' => $message ?? 'Hazard map information is temporarily unavailable.',
        ];

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json === false
        ? (string) json_encode(['success' => false, 'message' => 'Hazard map information is temporarily unavailable.'])
        : $json;
    exit;
}
