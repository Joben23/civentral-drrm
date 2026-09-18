<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\DrrmCitizenWarningNotificationService;
use App\Services\SupabaseRestClient;

// Reuse the existing citizen session bridge and credentialed CORS policy,
// never the Module 3 incident notification data model.
require_once __DIR__ . '/_incident-tracking-bootstrap.php';
require_once __DIR__ . '/../../../src/Services/DrrmCitizenWarningNotificationService.php';

$config = drrmCitizenTrackingInitialize('GET');
if ($_GET !== []) {
    drrmCitizenTrackingError('INVALID_REQUEST', 'Query parameters are not supported.', 400);
}
$identity = drrmCitizenTrackingIdentity($config);

try {
    $service = new DrrmCitizenWarningNotificationService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../../.env'))
    );
    drrmCitizenTrackingSuccess($service->feed($identity));
} catch (Throwable) {
    drrmCitizenTrackingError(
        'WARNING_NOTIFICATIONS_UNAVAILABLE',
        'Warning notifications are temporarily unavailable.',
        503
    );
}
