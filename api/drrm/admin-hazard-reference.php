<?php

declare(strict_types=1);

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmAdminHazardReferenceService;
use App\Services\DrrmMapAuthorizationService;
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
require_once __DIR__ . '/../../src/Services/DrrmDraftFloodPreviewService.php';
require_once __DIR__ . '/../../src/Services/DrrmDraftLandslidePreviewService.php';
require_once __DIR__ . '/../../src/Services/DrrmAdminHazardReferenceService.php';
require_once __DIR__ . '/../../src/Services/DrrmMapAuthorizationService.php';

drrmApiSendHeaders();
header('Allow: GET');
header('X-Robots-Tag: noindex, nofollow');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
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

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}

try {
    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env');
    $featureCollection = (new DrrmAdminHazardReferenceService(
        new SupabaseRestClient($config),
        $stagingAllowed
    ))->featureCollection();
    $json = json_encode(
        $featureCollection,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        throw new RuntimeException('The admin hazard reference could not be encoded.');
    }

    header('Content-Type: application/geo+json; charset=utf-8');
    echo $json;
    exit;
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to load the admin hazard reference.', 502);
}
