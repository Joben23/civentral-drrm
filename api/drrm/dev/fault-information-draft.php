<?php

declare(strict_types=1);

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmDraftFaultPreviewService;
use App\Services\SupabaseRestClient;

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
require_once __DIR__ . '/../../../src/Services/DrrmDraftFaultPreviewService.php';

drrmApiSendHeaders();
header('Allow: GET');
header('X-Robots-Tag: noindex, nofollow');

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

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($_GET !== []) {
    drrmApiRespond(false, null, 'Invalid query parameters.', 400);
}

try {
    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../../../.env');
    $service = new DrrmDraftFaultPreviewService(
        new SupabaseRestClient($config),
        $environmentAllowed
    );
    drrmApiRespond(true, $service->preview());
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to load the draft fault information.', 502);
}
