<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmEarlyWarningLifecycleException;
use App\Services\DrrmEarlyWarningValidationException;
use App\Services\DrrmExternalAdvisoryReviewService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmExternalAdvisoryReviewService.php';

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
if (!(DrrmEarlyWarningAuthorizationService::fromTrustedSession())->canView()) {
    drrmApiRespond(false, null, 'Access denied.', 403);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (array_diff(array_keys($_GET), ['status', 'advisory_id']) !== []
    || (isset($_GET['status']) && isset($_GET['advisory_id']))) {
    drrmApiRespond(false, null, 'Invalid external advisory request.', 400);
}

try {
    $service = new DrrmExternalAdvisoryReviewService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env'))
    );

    if (isset($_GET['advisory_id'])) {
        if (!is_string($_GET['advisory_id'])) {
            throw new DrrmEarlyWarningValidationException('Invalid external advisory identifier.');
        }
        drrmApiRespond(true, ['advisory' => $service->advisoryDetail($_GET['advisory_id'])]);
    }

    $status = isset($_GET['status']) && is_string($_GET['status'])
        ? $_GET['status']
        : 'PENDING_REVIEW';
    drrmApiRespond(true, [
        'status_filter' => strtoupper(trim($status)),
        'advisories' => $service->listAdvisories($status),
    ]);
} catch (DrrmEarlyWarningLifecycleException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 404);
} catch (DrrmEarlyWarningValidationException $exception) {
    drrmApiRespond(false, null, $exception->getMessage(), 400);
} catch (Throwable) {
    drrmApiRespond(false, null, 'Unable to load staged external advisories.', 502);
}
