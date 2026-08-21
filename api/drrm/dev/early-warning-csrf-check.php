<?php

declare(strict_types=1);

use App\Config\AppEnvironment;
use App\Services\AuthService;
use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmEarlyWarningCsrfException;
use App\Services\DrrmEarlyWarningCsrfService;

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
require_once __DIR__ . '/../../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../../src/Services/DrrmEarlyWarningCsrfService.php';

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

if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}

$authorization = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
if (!$authorization->canView()) {
    drrmApiRespond(false, null, 'Access denied.', 403);
}

try {
    $csrf = new DrrmEarlyWarningCsrfService();
    $csrf->requireValidHeader($_SERVER);
} catch (DrrmEarlyWarningCsrfException) {
    drrmApiRespond(false, null, 'CSRF validation failed.', 403);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

drrmApiRespond(true, [
    'authenticated' => true,
    'authorized_view' => true,
    'csrf_valid' => true,
    'database_write_performed' => false,
]);
