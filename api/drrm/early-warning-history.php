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
if (!(new \App\Services\AuthService())->isLoggedIn()) {
    drrmApiRespond(false, null, 'Authentication required.', 401);
}
if (!(\App\Services\DrrmEarlyWarningAuthorizationService::fromTrustedSession())->canView()) {
    drrmApiRespond(false, null, 'Access denied.', 403);
}

if (array_diff(array_keys($_GET), ['warning_id']) !== []
    || count($_GET) !== 1
    || !is_string($_GET['warning_id'] ?? null)
    || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $_GET['warning_id']) !== 1) {
    drrmApiRespond(false, null, 'Invalid warning-history request.', 400);
}
$warningId = $_GET['warning_id'];

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
try {
    $config = \App\Config\SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env');
    $service = new \App\Services\DrrmEarlyWarningReadService(
        new \App\Services\SupabaseRestClient($config)
    );
    drrmApiRespond(true, ['events' => $service->warningHistory($warningId)]);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to load warning history.', 502);
}
