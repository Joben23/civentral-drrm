<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmEarlyWarningCsrfException;
use App\Services\DrrmEarlyWarningCsrfService;
use App\Services\DrrmEarlyWarningValidationException;
use App\Services\DrrmEarlyWarningWriteException;
use App\Services\DrrmEarlyWarningWriteService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningCsrfService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningWriteService.php';

ini_set('display_errors', '0');
drrmApiSendHeaders();
header('Allow: POST');

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

$authorization = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
if (!$authorization->canCreateWarning()) {
    drrmApiRespond(false, null, 'You are not authorized to create warnings.', 403);
}

if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}

$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (preg_match('/^application\/json(?:\s*;.*)?$/', $contentType) !== 1) {
    drrmApiRespond(false, null, 'JSON request required.', 415);
}

try {
    (new DrrmEarlyWarningCsrfService())->requireValidHeader($_SERVER);
} catch (DrrmEarlyWarningCsrfException) {
    drrmApiRespond(false, null, 'CSRF validation failed.', 403);
}

$rawBody = file_get_contents('php://input', false, null, 0, 32769);
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 32768) {
    drrmApiRespond(false, null, 'Invalid warning request.', 400);
}

try {
    $input = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Invalid warning request.', 400);
}

if (!is_array($input) || array_is_list($input)) {
    drrmApiRespond(false, null, 'Invalid warning request.', 400);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $service = new DrrmEarlyWarningWriteService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env'))
    );
    drrmApiRespond(true, $service->createDraft($input), null, 201);
} catch (DrrmEarlyWarningValidationException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 422);
} catch (DrrmEarlyWarningWriteException) {
    drrmApiRespond(false, null, 'Unable to save warning.', 502);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to save warning.', 502);
}
