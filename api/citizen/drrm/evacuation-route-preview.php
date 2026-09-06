<?php

declare(strict_types=1);

use App\Config\AppEnvironment;
use App\Config\OsrmConfig;
use App\Config\SupabaseConfig;
use App\Services\DrrmDraftEvacuationCenterPreviewService;
use App\Services\DrrmEvacuationRoutePreviewService;
use App\Services\OsrmNoRouteException;
use App\Services\OsrmRoutingClient;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../../../config/app_environment.php';
ini_set('display_errors', '0');

$envFile = __DIR__ . '/../../../.env';
if (!AppEnvironment::isStaging($envFile)
    || !AppEnvironment::isPublicDrrmPreviewEnabled($envFile)) {
    citizenRoutePreviewRespond(false, 'Not found.', 404);
}

require_once __DIR__ . '/../../../config/osrm.php';
require_once __DIR__ . '/../../../config/supabase.php';
require_once __DIR__ . '/../../../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../../../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../../../src/Services/DrrmDraftBarangayPreviewService.php';
require_once __DIR__ . '/../../../src/Services/DrrmDraftEvacuationCenterPreviewService.php';
require_once __DIR__ . '/../../../src/Services/OsrmRoutingClient.php';
require_once __DIR__ . '/../../../src/Services/DrrmEvacuationRoutePreviewService.php';

citizenRoutePreviewSendHeaders();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    citizenRoutePreviewRespond(false, 'Method not allowed.', 405);
}
if ($_GET !== []) {
    citizenRoutePreviewRespond(false, 'Invalid route request.', 400);
}

$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (preg_match('/^application\/json(?:\s*;.*)?$/', $contentType) !== 1) {
    citizenRoutePreviewRespond(false, 'JSON request required.', 400);
}

$rawBody = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 4096) {
    citizenRoutePreviewRespond(false, 'Invalid route request.', 400);
}

try {
    $input = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    citizenRoutePreviewRespond(false, 'Invalid route request.', 400);
}

if (!is_array($input)
    || array_diff(array_keys($input), ['latitude', 'longitude', 'center_reference_id']) !== []
    || count($input) !== 3
    || !array_key_exists('latitude', $input)
    || !array_key_exists('longitude', $input)
    || !is_int($input['latitude']) && !is_float($input['latitude'])
    || !is_int($input['longitude']) && !is_float($input['longitude'])
    || !is_string($input['center_reference_id'])
    || trim($input['center_reference_id']) === '') {
    citizenRoutePreviewRespond(false, 'Invalid route request.', 400);
}

try {
    $centerService = new DrrmDraftEvacuationCenterPreviewService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment($envFile)),
        true
    );
    $routeService = new DrrmEvacuationRoutePreviewService(
        $centerService,
        new OsrmRoutingClient(OsrmConfig::fromEnvironment($envFile)),
        __DIR__ . '/../../../data/import/caloocan-city-boundary.geojson',
        true
    );
    $result = $routeService->citizenPlanningRoute(
        (float) $input['latitude'],
        (float) $input['longitude'],
        trim($input['center_reference_id'])
    );
    citizenRoutePreviewRespond(true, null, 200, $result);
} catch (InvalidArgumentException $error) {
    $message = $error->getMessage() === 'The starting location must be inside Caloocan City.'
        ? 'Starting location must be inside Caloocan City.'
        : 'Invalid route request.';
    citizenRoutePreviewRespond(false, $message, 422);
} catch (OsrmNoRouteException) {
    citizenRoutePreviewRespond(false, 'No routable road path was found for the selected locations.', 422);
} catch (Throwable) {
    citizenRoutePreviewRespond(false, 'Road routing service is temporarily unavailable.', 502);
}

function citizenRoutePreviewSendHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Allow: POST, OPTIONS');
    header('X-Robots-Tag: noindex, nofollow');
}

/** @param array<string, mixed>|null $data */
function citizenRoutePreviewRespond(
    bool $success,
    ?string $message = null,
    int $statusCode = 200,
    ?array $data = null
): never {
    citizenRoutePreviewSendHeaders();
    http_response_code($statusCode);
    $payload = $success ? ['success' => true] + ($data ?? []) : [
        'success' => false,
        'message' => $message ?? 'Unable to calculate the route preview.',
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}