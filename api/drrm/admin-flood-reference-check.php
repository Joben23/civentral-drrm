<?php

declare(strict_types=1);

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmAdminFloodReferenceCheckService;
use App\Services\DrrmCaloocanBoundaryService;
use App\Services\DrrmDraftFloodPreviewService;
use App\Services\DrrmMapAuthorizationService;
use App\Services\DrrmMapCsrfException;
use App\Services\DrrmMapCsrfService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../../config/app_environment.php';
ini_set('display_errors', '0');

$stagingAllowed = AppEnvironment::isStaging(__DIR__ . '/../../.env');
if (!$stagingAllowed) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found.']);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmMapAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmMapCsrfService.php';
require_once __DIR__ . '/../../src/Services/DrrmCaloocanBoundaryService.php';
require_once __DIR__ . '/../../src/Services/DrrmDraftFloodPreviewService.php';
require_once __DIR__ . '/../../src/Services/DrrmFloodReferenceEvaluatorService.php';
require_once __DIR__ . '/../../src/Services/DrrmAdminFloodReferenceCheckService.php';

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

$authorization = DrrmMapAuthorizationService::fromTrustedSession();
if (!$authorization->canView()) {
    drrmApiRespond(false, null, 'Module 1 VIEW permission required.', 403);
}

try {
    (new DrrmMapCsrfService())->requireValidHeader($_SERVER);
} catch (DrrmMapCsrfException) {
    drrmApiRespond(false, null, 'CSRF validation failed.', 403);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid flood reference check request.', 400);
}

$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (preg_match('/^application\/json(?:\s*;.*)?$/', $contentType) !== 1) {
    drrmApiRespond(false, null, 'JSON request required.', 415);
}

$bodyStream = PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input';
$rawBody = file_get_contents($bodyStream, false, null, 0, 1025);
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 1024) {
    drrmApiRespond(false, null, 'Invalid flood reference check request.', 400);
}

try {
    $input = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Invalid flood reference check request.', 400);
}

$isJsonNumber = static fn (mixed $value): bool => is_int($value) || is_float($value);
if (!is_array($input) || !array_is_list(array_keys($input))
    || count($input) !== 2
    || array_diff(array_keys($input), ['latitude', 'longitude']) !== []
    || !$isJsonNumber($input['latitude'] ?? null)
    || !$isJsonNumber($input['longitude'] ?? null)) {
    drrmApiRespond(false, null, 'Invalid flood reference check request.', 400);
}

$latitude = (float) $input['latitude'];
$longitude = (float) $input['longitude'];
if (!is_finite($latitude) || !is_finite($longitude)
    || $latitude < -90 || $latitude > 90
    || $longitude < -180 || $longitude > 180) {
    drrmApiRespond(false, null, 'Please select a location inside Caloocan City.', 422);
}

try {
    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env');
    $floodReference = new DrrmDraftFloodPreviewService(
        new SupabaseRestClient($config),
        $stagingAllowed
    );
    $service = new DrrmAdminFloodReferenceCheckService(
        static fn (): array => $floodReference->featureCollection(),
        new DrrmCaloocanBoundaryService(
            __DIR__ . '/../../data/import/caloocan-city-boundary.geojson'
        ),
        $stagingAllowed
    );
    $result = $service->check($latitude, $longitude);
    $json = json_encode(
        ['success' => true] + $result,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );
    echo $json;
    exit;
} catch (InvalidArgumentException) {
    drrmApiRespond(false, null, 'Please select a location inside Caloocan City.', 422);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to check the controlled flood reference.', 502);
}
