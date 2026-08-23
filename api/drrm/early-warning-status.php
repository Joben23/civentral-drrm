<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmEarlyWarningCsrfException;
use App\Services\DrrmEarlyWarningCsrfService;
use App\Services\DrrmEarlyWarningLifecycleException;
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

if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}

$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (preg_match('/^application\/json(?:\s*;.*)?$/', $contentType) !== 1) {
    drrmApiRespond(false, null, 'JSON request required.', 415);
}

$rawBody = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 4096) {
    drrmApiRespond(false, null, 'Invalid warning status request.', 400);
}

try {
    $input = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Invalid warning status request.', 400);
}

if (!is_array($input) || array_is_list($input)
    || array_diff(array_keys($input), ['warning_id', 'action']) !== []
    || !is_string($input['warning_id'] ?? null)
    || !is_string($input['action'] ?? null)) {
    drrmApiRespond(false, null, 'Invalid warning status request.', 400);
}

$action = strtoupper(trim($input['action']));
$authorization = DrrmEarlyWarningAuthorizationService::fromTrustedSession();

if ($action === 'ACTIVATE') {
    if (!$authorization->canActivateWarning()) {
        drrmApiRespond(false, null, 'You are not authorized to activate warnings.', 403);
    }
} elseif ($action === 'CANCEL') {
    if (!$authorization->canCancelWarning()) {
        drrmApiRespond(false, null, 'You are not authorized to cancel warnings.', 403);
    }
} else {
    drrmApiRespond(false, null, 'Invalid warning lifecycle action.', 400);
}

try {
    (new DrrmEarlyWarningCsrfService())->requireValidHeader($_SERVER);
} catch (DrrmEarlyWarningCsrfException) {
    drrmApiRespond(false, null, 'CSRF validation failed.', 403);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $service = new DrrmEarlyWarningWriteService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env'))
    );
    $result = $action === 'ACTIVATE'
        ? $service->activate($input['warning_id'])
        : $service->cancel($input['warning_id']);
    drrmApiRespond(true, $result);
} catch (DrrmEarlyWarningValidationException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 422);
} catch (DrrmEarlyWarningLifecycleException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 409);
} catch (DrrmEarlyWarningWriteException) {
    drrmApiRespond(false, null, 'Unable to update warning status.', 502);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to update warning status.', 502);
}
