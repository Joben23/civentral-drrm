<?php

declare(strict_types=1);

use App\Services\AuthService;
use App\Services\DrrmPhivolcsAdvisoryService;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmPhivolcsAdvisoryService.php';

ini_set('display_errors', '0');
drrmApiSendHeaders();
header('Allow: GET');

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

$earlyWarningAuthorization = \App\Services\DrrmEarlyWarningAuthorizationService::fromTrustedSession();
if (!$earlyWarningAuthorization->canView()) {
    drrmApiRespond(false, null, 'Access denied.', 403);
}

if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    drrmApiRespond(true, (new DrrmPhivolcsAdvisoryService())->overview());
} catch (Throwable) {
    drrmApiRespond(false, null, 'PHIVOLCS information is temporarily unavailable.', 502);
}
