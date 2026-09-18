<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmWarningNotificationReadinessService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmWarningNotificationReadinessService.php';

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
if (!(new AuthService())->isLoggedIn()) {
    drrmApiRespond(false, null, 'Authentication required.', 401);
}
if (!DrrmEarlyWarningAuthorizationService::fromTrustedSession()->canView()) {
    drrmApiRespond(false, null, 'Access denied.', 403);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}

$available = false;
try {
    $client = new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env'));
    $available = (new DrrmWarningNotificationReadinessService($client))->isAvailable();
} catch (Throwable) {
    // An unapplied migration or unavailable catalog must never look connected.
}
drrmApiRespond(true, [
    'in_app_available' => $available,
    'delivery_type' => 'AUTHENTICATED_PULL_FEED',
    'email_connected' => false,
    'sms_connected' => false,
    'push_connected' => false,
]);
