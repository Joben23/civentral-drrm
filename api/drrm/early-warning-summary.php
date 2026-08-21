<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningReadService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';

ini_set('display_errors', '0');
drrmApiSendHeaders();
header('Allow: GET, OPTIONS');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'GET') {
    drrmApiRespond(false, null, 'Method not allowed.', 405);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$authService = new \App\Services\AuthService();

if (!$authService->isLoggedIn()) {
    drrmApiRespond(false, null, 'Authentication required.', 401);
}

$earlyWarningAuthorization = \App\Services\DrrmEarlyWarningAuthorizationService::fromTrustedSession();
if (!$earlyWarningAuthorization->canView()) {
    drrmApiRespond(false, null, 'Access denied.', 403);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}

try {
    $config = \App\Config\SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env');
    $service = new \App\Services\DrrmEarlyWarningReadService(
        new \App\Services\SupabaseRestClient($config)
    );

    drrmApiRespond(true, $service->dashboardSummary());
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to load early-warning data.', 502);
}
