<?php

declare(strict_types=1);

use App\Services\AuthService;

require_once __DIR__ . '/_bootstrap.php';
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

/*
 * No documented official NDRRMC API, RSS, Atom, CAP, JSON, or XML advisory
 * feed has been confirmed for server-side consumption. The two references
 * below are official human-facing publication channels, not ingestion APIs.
 * Keep this response explicit rather than scraping pages or inventing data.
 */
drrmApiRespond(true, [
    'source' => [
        'agency' => 'NDRRMC',
        'organization' => 'National Disaster Risk Reduction and Management Council',
    ],
    'machine_readable_source_status' => 'NOT_CONFIRMED',
    'runtime_status' => 'INTEGRATION_PENDING',
    'official_publication_channels' => [
        [
            'type' => 'HUMAN_READABLE_WEB_PAGE',
            'reference' => 'https://ndrrmc.gov.ph/9-ndrrmc-advisory.html',
        ],
        [
            'type' => 'HUMAN_READABLE_MONITORING_DASHBOARD',
            'reference' => 'https://monitoring-dashboard.ndrrmc.gov.ph/page/situations',
        ],
    ],
    'advisories' => [],
    'relevance' => [
        'status' => 'NOT_APPLIED_NO_FEED',
        'supported_classifications' => ['CALOOCAN', 'NCR', 'NATIONWIDE', 'OTHER', 'UNKNOWN'],
    ],
    'message' => 'No applicable NDRRMC advisory is available through a confirmed official machine-readable source.',
    'upstream_request_attempted' => false,
    'external_information_only' => true,
]);
