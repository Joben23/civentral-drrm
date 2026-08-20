<?php

declare(strict_types=1);

use App\Config\AppEnvironment;
use App\Config\OsrmConfig;
use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmDraftEvacuationCenterPreviewService;
use App\Services\DrrmEvacuationRoutePreviewService;
use App\Services\OsrmNoRouteException;
use App\Services\OsrmRoutingClient;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../../../config/app_environment.php';

ini_set('display_errors', '0');

$environmentAllowed = AppEnvironment::allowsLocalDevelopmentRequest(
    __DIR__ . '/../../../.env',
    $_SERVER
);

if (!$environmentAllowed) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    http_response_code(404);
    echo '{"success":false,"message":"Not found."}';
    exit;
}

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../config/osrm.php';
require_once __DIR__ . '/../../../src/Services/DrrmDraftBarangayPreviewService.php';
require_once __DIR__ . '/../../../src/Services/DrrmDraftEvacuationCenterPreviewService.php';
require_once __DIR__ . '/../../../src/Services/OsrmRoutingClient.php';
require_once __DIR__ . '/../../../src/Services/DrrmEvacuationRoutePreviewService.php';

drrmApiSendHeaders();
header('Allow: POST');
header('X-Robots-Tag: noindex, nofollow');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    drrmApiRespond(false, null, 'Method not allowed.', 405);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$authService = new AuthService();
if (!$authService->isLoggedIn()) {
    drrmApiRespond(false, null, 'Authentication required.', 401);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid route request.', 400);
}

$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (preg_match('/^application\/json(?:\s*;.*)?$/', $contentType) !== 1) {
    drrmApiRespond(false, null, 'JSON request required.', 415);
}

$rawBody = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 4096) {
    drrmApiRespond(false, null, 'Invalid route request.', 400);
}

try {
    $input = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Invalid route request.', 400);
}

if (!is_array($input) || array_diff(array_keys($input), ['origin', 'evacuation_center_id']) !== []
    || !isset($input['origin']) || !is_array($input['origin'])
    || array_diff(array_keys($input['origin']), ['latitude', 'longitude']) !== []
    || !is_numeric($input['origin']['latitude'] ?? null)
    || !is_numeric($input['origin']['longitude'] ?? null)
    || !is_string($input['evacuation_center_id'] ?? null)) {
    drrmApiRespond(false, null, 'Invalid route request.', 400);
}

try {
    $supabaseConfig = SupabaseConfig::fromEnvironment(__DIR__ . '/../../../.env');
    $centerService = new DrrmDraftEvacuationCenterPreviewService(
        new SupabaseRestClient($supabaseConfig),
        $environmentAllowed
    );
    $service = new DrrmEvacuationRoutePreviewService(
        $centerService,
        new OsrmRoutingClient(OsrmConfig::fromEnvironment(__DIR__ . '/../../../.env')),
        __DIR__ . '/../../../data/import/caloocan-city-boundary.geojson',
        $environmentAllowed
    );

    $result = $service->route(
        (float) $input['origin']['latitude'],
        (float) $input['origin']['longitude'],
        trim($input['evacuation_center_id'])
    );
    drrmApiRespond(true, $result);
} catch (InvalidArgumentException $error) {
    $message = $error->getMessage() === 'The starting location must be inside Caloocan City.'
        ? 'Starting location must be inside Caloocan City.'
        : 'Invalid route request.';
    drrmApiRespond(false, null, $message, 422);
} catch (OsrmNoRouteException) {
    drrmApiRespond(false, null, 'No routable road path was found for the selected locations.', 422);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Road routing service is temporarily unavailable.', 502);
}
