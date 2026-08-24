<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmIncidentAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmIncidentReadService.php';

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
$authorization = \App\Services\DrrmIncidentAuthorizationService::fromTrustedSession();
if (!$authorization->canView()) {
    drrmApiRespond(false, null, 'Access denied.', 403);
}
if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $service = new \App\Services\DrrmIncidentReadService(
        new \App\Services\SupabaseRestClient(
            \App\Config\SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env')
        )
    );
    drrmApiRespond(true, $service->summary());
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to load incident summary data.', 502);
}
