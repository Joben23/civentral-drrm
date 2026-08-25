<?php

declare(strict_types=1);

require_once __DIR__ . '/_incident-tracking-bootstrap.php';

$config = drrmCitizenTrackingInitialize('GET');
if ($_GET !== []) {
    drrmCitizenTrackingError('INVALID_REQUEST', 'Query parameters are not supported.', 400);
}
$identity = drrmCitizenTrackingIdentity($config);

try {
    $result = drrmCitizenTrackingService()->myIncidents($identity);
} catch (Throwable) {
    drrmCitizenTrackingError(
        'INCIDENT_TRACKING_UNAVAILABLE',
        'Incident reports are temporarily unavailable.',
        503
    );
}

drrmCitizenTrackingSuccess($result);
