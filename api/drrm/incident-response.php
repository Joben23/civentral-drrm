<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmIncidentAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmIncidentCsrfService.php';
require_once __DIR__ . '/../../src/Services/DrrmIncidentWriteService.php';

ini_set('display_errors', '0');
drrmApiSendHeaders();
header('Allow: POST, OPTIONS');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    drrmApiRespond(false, null, 'Method not allowed.', 405);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$authService = new \App\Services\AuthService();
if (!$authService->isLoggedIn()) {
    drrmApiRespond(false, null, 'Authentication required.', 401);
}
$authorization = \App\Services\DrrmIncidentAuthorizationService::fromTrustedSession();
if (!$authorization->canUpdateResponse()) {
    drrmApiRespond(false, null, 'You are not authorized to update incident responses.', 403);
}
if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}
$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (preg_match('/^application\/json(?:\s*;.*)?$/', $contentType) !== 1) {
    drrmApiRespond(false, null, 'JSON request required.', 415);
}
try {
    (new \App\Services\DrrmIncidentCsrfService())->requireValidHeader($_SERVER);
} catch (\App\Services\DrrmIncidentCsrfException) {
    drrmApiRespond(false, null, 'CSRF validation failed.', 403);
}
$rawBody = file_get_contents('php://input', false, null, 0, 8193);
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 8192) {
    drrmApiRespond(false, null, 'Invalid incident response request.', 400);
}
try {
    $input = json_decode($rawBody, true, 12, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Invalid incident response request.', 400);
}
if (!is_array($input) || array_is_list($input)) {
    drrmApiRespond(false, null, 'Invalid incident response request.', 400);
}

try {
    $actorReference = \App\Services\DrrmIncidentWriteService::actorReferenceFromSession();
} catch (\App\Services\DrrmIncidentValidationException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 422);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $service = new \App\Services\DrrmIncidentWriteService(
        new \App\Services\SupabaseRestClient(
            \App\Config\SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env')
        )
    );
    drrmApiRespond(true, $service->addResponse($input, $actorReference));
} catch (\App\Services\DrrmIncidentValidationException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 422);
} catch (\App\Services\DrrmIncidentLifecycleException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 409);
} catch (\App\Services\DrrmIncidentWriteException) {
    drrmApiRespond(false, null, 'Unable to record the incident response.', 502);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to record the incident response.', 502);
}
