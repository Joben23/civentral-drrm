<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\DrrmCitizenWarningNotificationNotEligibleException;
use App\Services\DrrmCitizenWarningNotificationRequest;
use App\Services\DrrmCitizenWarningNotificationService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/_incident-tracking-bootstrap.php';
require_once __DIR__ . '/../../../src/Services/DrrmCitizenWarningNotificationService.php';
require_once __DIR__ . '/../../../src/Services/DrrmCitizenWarningNotificationRequest.php';

$config = drrmCitizenTrackingInitialize('POST');
if ($_GET !== []) {
    drrmCitizenTrackingError('INVALID_REQUEST', 'Query parameters are not supported.', 400);
}
$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
if ($contentType !== 'application/json') {
    drrmCitizenTrackingError('INVALID_REQUEST', 'Content-Type must be application/json.', 415);
}
if (isset($_SERVER['CONTENT_LENGTH']) && ctype_digit((string) $_SERVER['CONTENT_LENGTH'])
    && (int) $_SERVER['CONTENT_LENGTH'] > 1024) {
    drrmCitizenTrackingError('INVALID_REQUEST', 'The request payload is too large.', 413);
}
$stream = fopen('php://input', 'rb');
$body = is_resource($stream) ? stream_get_contents($stream, 1025) : false;
if (is_resource($stream)) {
    fclose($stream);
}
try {
    $eventId = DrrmCitizenWarningNotificationRequest::eventId(is_string($body) ? $body : '');
} catch (InvalidArgumentException $exception) {
    drrmCitizenTrackingError('INVALID_REQUEST', $exception->getMessage(), 400);
}
$identity = drrmCitizenTrackingIdentity($config);

try {
    $service = new DrrmCitizenWarningNotificationService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../../.env'))
    );
    drrmCitizenTrackingSuccess($service->markRead($identity, $eventId));
} catch (DrrmCitizenWarningNotificationNotEligibleException) {
    drrmCitizenTrackingError('NOT_ELIGIBLE', 'The warning notification is unavailable.', 409);
} catch (Throwable) {
    drrmCitizenTrackingError(
        'WARNING_NOTIFICATIONS_UNAVAILABLE',
        'Warning read state is temporarily unavailable.',
        503
    );
}
