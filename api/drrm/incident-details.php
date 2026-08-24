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
if (array_keys($_GET) !== ['id'] || !is_string($_GET['id'] ?? null)) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}
$incidentId = $_GET['id'];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $service = new \App\Services\DrrmIncidentReadService(
        new \App\Services\SupabaseRestClient(
            \App\Config\SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env')
        )
    );
    drrmApiRespond(true, $service->details($incidentId));
} catch (InvalidArgumentException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 400);
} catch (\App\Services\DrrmIncidentNotFoundException) {
    drrmApiRespond(false, null, 'The incident could not be found.', 404);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to load incident details.', 502);
}
