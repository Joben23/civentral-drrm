<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningCsrfService.php';
require_once __DIR__ . '/../../src/Services/DrrmFloodRiskPredictionService.php';

use App\Services\AuthService;
use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmEarlyWarningCsrfService;
use App\Services\DrrmFloodRiskPredictionService;

/** @param array<string, mixed> $state */
function drrmFloodRiskPredictionRespond(array $state, int $status): never
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'code' => (string) ($state['code'] ?? 'AI_SERVICE_ERROR'),
        'message' => (string) ($state['message'] ?? 'Flood-risk prediction is unavailable.'),
        'data' => $state,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

ini_set('display_errors', '0');
drrmApiSendHeaders();
header('Allow: POST');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    drrmApiRespond(false, null, 'Method not allowed.', 405);
}

if (!empty($_GET)) {
    drrmApiRespond(false, null, 'Query parameters are not accepted.', 400);
}

$authService = new AuthService();
if (!$authService->isLoggedIn()) {
    drrmApiRespond(false, null, 'Authentication is required.', 401);
}

$authorization = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
if (!$authorization->canView()) {
    drrmApiRespond(false, null, 'You are not authorized to request flood-risk decision support.', 403);
}

$csrfService = new DrrmEarlyWarningCsrfService();
$csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!$csrfService->validate($csrfToken)) {
    drrmApiRespond(false, null, 'Invalid or expired CSRF token.', 403);
}

$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (preg_match('/^application\/json(?:\s*;.*)?$/', $contentType) !== 1) {
    drrmApiRespond(false, null, 'Content-Type must be application/json.', 415);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 4096) {
    drrmApiRespond(false, null, 'Request body is too large.', 413);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 4096) {
    drrmApiRespond(false, null, 'Unable to read a valid request body.', 400);
}

try {
    $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    drrmApiRespond(false, null, 'Request body must contain valid JSON.', 400);
}

if (!is_array($payload) || array_is_list($payload)) {
    drrmApiRespond(false, null, 'Request body must be a JSON object.', 400);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $service = new DrrmFloodRiskPredictionService(
        __DIR__ . '/../../data/import/caloocan-barangays-current-unaffected.geojson'
    );
    $state = $service->requestPrediction($payload);
} catch (InvalidArgumentException $exception) {
    drrmFloodRiskPredictionRespond([
        'available' => false,
        'code' => 'AI_REQUEST_INVALID',
        'message' => $exception->getMessage(),
    ], 422);
} catch (Throwable $exception) {
    drrmFloodRiskPredictionRespond([
        'available' => false,
        'code' => 'AI_SERVICE_ERROR',
        'message' => 'Flood-risk prediction could not be evaluated safely.',
    ], 500);
}

$status = match ($state['code'] ?? null) {
    'INPUT_DATA_UNAVAILABLE' => 503,
    'AI_REQUEST_INVALID' => 422,
    default => 502,
};
drrmFloodRiskPredictionRespond($state, $status);
