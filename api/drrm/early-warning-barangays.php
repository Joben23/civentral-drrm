<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmEarlyWarningWriteService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningWriteService.php';

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

$authorization = DrrmEarlyWarningAuthorizationService::fromTrustedSession();
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
    $service = new DrrmEarlyWarningWriteService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env'))
    );
    $barangays = $service->availableBarangays();
    drrmApiRespond(true, [
        'count' => count($barangays),
        'barangays' => $barangays,
        'development_preview' => true,
    ]);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to load validated barangays.', 502);
}
