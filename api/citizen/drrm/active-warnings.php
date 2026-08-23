<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\DrrmCitizenWarningReadService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../../../config/supabase.php';
require_once __DIR__ . '/../../../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../../../src/Services/DrrmCitizenWarningReadService.php';

ini_set('display_errors', '0');

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    header('Cache-Control: public, max-age=30, must-revalidate');
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
    citizenWarningRespond(false, null, 'Method not allowed.', 405);
}

if ($_GET !== []) {
    citizenWarningRespond(false, null, 'Unsupported query parameters.', 400);
}

try {
    $service = new DrrmCitizenWarningReadService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../../.env'))
    );

    citizenWarningRespond(true, $service->activeWarnings());
} catch (Throwable) {
    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }
    citizenWarningRespond(false, null, 'Active warnings are temporarily unavailable.', 503);
}

/** @param array<string, mixed>|null $data */
function citizenWarningRespond(
    bool $success,
    ?array $data,
    ?string $message = null,
    int $statusCode = 200
): never
{
    http_response_code($statusCode);

    if ($success && $data !== null) {
        $payload = ['success' => true] + $data;
    } else {
        $payload = [
            'success' => false,
            'city' => DrrmCitizenWarningReadService::CITY_NAME,
            'active_warning_count' => 0,
            'warnings' => [],
            'message' => $message ?? 'Active warnings are temporarily unavailable.',
        ];
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    echo $json === false
        ? '{"success":false,"city":"Caloocan City","active_warning_count":0,"warnings":[],"message":"Active warnings are temporarily unavailable."}'
        : $json;
    exit;
}
