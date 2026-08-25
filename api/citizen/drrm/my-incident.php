<?php

declare(strict_types=1);

use App\Services\DrrmCitizenIncidentInvalidRequestException;
use App\Services\DrrmCitizenIncidentNotFoundException;

require_once __DIR__ . '/_incident-tracking-bootstrap.php';

$config = drrmCitizenTrackingInitialize('GET');
if (count($_GET) !== 1 || !array_key_exists('incident_number', $_GET)
    || !is_string($_GET['incident_number'])) {
    drrmCitizenTrackingError('INVALID_REQUEST', 'A valid incident number is required.', 400);
}
$identity = drrmCitizenTrackingIdentity($config);

try {
    $incident = drrmCitizenTrackingService()->incidentDetails(
        $_GET['incident_number'],
        $identity
    );
} catch (DrrmCitizenIncidentInvalidRequestException $exception) {
    drrmCitizenTrackingError('INVALID_REQUEST', $exception->getMessage(), 400);
} catch (DrrmCitizenIncidentNotFoundException) {
    drrmCitizenTrackingError(
        'INCIDENT_NOT_FOUND',
        'The incident could not be found.',
        404
    );
} catch (Throwable) {
    drrmCitizenTrackingError(
        'INCIDENT_TRACKING_UNAVAILABLE',
        'Incident details are temporarily unavailable.',
        503
    );
}

drrmCitizenTrackingSuccess(['incident' => $incident]);
