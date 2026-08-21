<?php

declare(strict_types=1);

use App\Config\PagasaConfig;
use App\Services\AuthService;
use App\Services\DrrmPagasaAdvisoryService;
use App\Services\PagasaTenDayClient;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../config/pagasa.php';
require_once __DIR__ . '/../../src/Services/PagasaTenDayClient.php';
require_once __DIR__ . '/../../src/Services/DrrmPagasaAdvisoryService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';

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
    $service = new DrrmPagasaAdvisoryService(
        new PagasaTenDayClient(PagasaConfig::fromEnvironment(__DIR__ . '/../../.env'))
    );
    drrmApiRespond(true, $service->overview());
} catch (Throwable) {
    drrmApiRespond(false, null, 'PAGASA advisory information is temporarily unavailable.', 502);
}
