<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmEarlyWarningConflictException;
use App\Services\DrrmEarlyWarningCsrfException;
use App\Services\DrrmEarlyWarningCsrfService;
use App\Services\DrrmEarlyWarningLifecycleException;
use App\Services\DrrmEarlyWarningValidationException;
use App\Services\DrrmEarlyWarningWriteException;
use App\Services\DrrmEarlyWarningWriteService;
use App\Services\DrrmExternalAdvisoryReviewService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningCsrfService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningWriteService.php';
require_once __DIR__ . '/../../src/Services/DrrmExternalAdvisoryReviewService.php';

ini_set('display_errors', '0');
drrmApiSendHeaders();
header('Allow: POST');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    drrmApiRespond(false, null, 'Method not allowed.', 405);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!(new AuthService())->isLoggedIn()) {
    drrmApiRespond(false, null, 'Authentication required.', 401);
}

$authorization = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
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
    drrmApiRespond(false, null, 'Invalid external advisory review request.', 400);
}
try {
    $input = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Invalid external advisory review request.', 400);
}
if (!is_array($input) || array_is_list($input) || !is_string($input['action'] ?? null)) {
    drrmApiRespond(false, null, 'Invalid external advisory review request.', 400);
}

$action = strtoupper(trim($input['action']));
unset($input['action']);
if ($action === 'DISMISS') {
    if (!$authorization->canDismissExternalAdvisory()) {
        drrmApiRespond(false, null, 'You are not authorized to dismiss external advisories.', 403);
    }
} elseif ($action === 'CREATE_DRAFT') {
    if (!$authorization->canConvertExternalAdvisoryToDraft()) {
        drrmApiRespond(false, null, 'You are not authorized to create warning drafts.', 403);
    }
} else {
    drrmApiRespond(false, null, 'Unsupported external advisory review action.', 422);
}

try {
    $actorReference = DrrmEarlyWarningWriteService::actorReferenceFromSession();
} catch (DrrmEarlyWarningValidationException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 422);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $service = new DrrmExternalAdvisoryReviewService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env'))
    );
    $result = $action === 'DISMISS'
        ? $service->dismiss($input, $actorReference)
        : $service->convertToDraft($input, $actorReference);
    drrmApiRespond(true, $result, null, $action === 'CREATE_DRAFT' ? 201 : 200);
} catch (DrrmEarlyWarningConflictException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 409);
} catch (DrrmEarlyWarningLifecycleException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 404);
} catch (DrrmEarlyWarningValidationException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 422);
} catch (DrrmEarlyWarningWriteException) {
    drrmApiRespond(false, null, 'Unable to save external advisory review.', 502);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to save external advisory review.', 502);
}
