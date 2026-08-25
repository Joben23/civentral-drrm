<?php

declare(strict_types=1);

require_once __DIR__ . '/_incident-tracking-bootstrap.php';

$config = drrmCitizenTrackingInitialize('POST');
if ($_GET !== []) {
    drrmCitizenTrackingError('INVALID_REQUEST', 'Query parameters are not supported.', 400);
}
drrmCitizenTrackingRequireEmptyJsonBody();
$identity = drrmCitizenTrackingIdentity($config);

try {
    $result = drrmCitizenTrackingService()->markNotificationsRead($identity);
} catch (Throwable) {
    drrmCitizenTrackingError(
        'INCIDENT_TRACKING_UNAVAILABLE',
        'Incident notifications could not be marked as read.',
        503
    );
}

drrmCitizenTrackingSuccess($result + [
    'message' => 'Incident notifications were marked as read.',
]);
