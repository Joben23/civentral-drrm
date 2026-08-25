<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, 'PHP cURL is required.' . PHP_EOL);
    exit(1);
}

$baseUrl = rtrim(
    $argv[1] ?? 'http://localhost/civentral-drrm',
    '/'
);
$failures = [];

/** @return array{status: int, headers: string, body: string} */
function citizenTrackingHttpRequest(
    string $url,
    string $method,
    ?string $body = null,
    array $headers = []
): array {
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize HTTP test.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($handle);
    if (!is_string($response)) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException($error);
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    return [
        'status' => $status,
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function citizenTrackingHttpAssert(string $name, bool $condition): void
{
    global $failures;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

/** @return array<string, mixed>|null */
function citizenTrackingHttpPayload(array $response): ?array
{
    $payload = json_decode($response['body'], true);
    return is_array($payload) && !array_is_list($payload) ? $payload : null;
}

$myIncidentsUrl = $baseUrl . '/api/citizen/drrm/my-incidents.php';
$detailUrl = $baseUrl
    . '/api/citizen/drrm/my-incident.php?incident_number=INC-2026-000001';
$notificationsUrl = $baseUrl
    . '/api/citizen/drrm/incident-notifications.php';
$markReadUrl = $baseUrl
    . '/api/citizen/drrm/incident-notifications-read.php';

foreach ([
    'UnauthenticatedMyIncidentsRejected' => citizenTrackingHttpRequest(
        $myIncidentsUrl,
        'GET'
    ),
    'UnauthenticatedIncidentDetailRejected' => citizenTrackingHttpRequest(
        $detailUrl,
        'GET'
    ),
    'UnauthenticatedNotificationFeedRejected' => citizenTrackingHttpRequest(
        $notificationsUrl,
        'GET'
    ),
    'UnauthenticatedMarkReadRejected' => citizenTrackingHttpRequest(
        $markReadUrl,
        'POST',
        '{}',
        ['Content-Type: application/json']
    ),
] as $name => $response) {
    $payload = citizenTrackingHttpPayload($response);
    citizenTrackingHttpAssert(
        $name,
        $response['status'] === 401
            && ($payload['error']['code'] ?? null)
                === 'AUTHENTICATION_REQUIRED'
    );
}

citizenTrackingHttpAssert(
    'IncidentNumberAloneNeverAuthorizes',
    citizenTrackingHttpRequest(
        $baseUrl
            . '/api/citizen/drrm/my-incident.php?incident_number=INC-2026-999999',
        'GET'
    )['status'] === 401
);
citizenTrackingHttpAssert(
    'MyIncidentsPostRejected',
    citizenTrackingHttpRequest($myIncidentsUrl, 'POST', '{}', [
        'Content-Type: application/json',
    ])['status'] === 405
);
citizenTrackingHttpAssert(
    'MarkReadGetRejected',
    citizenTrackingHttpRequest($markReadUrl, 'GET')['status'] === 405
);

$identityBody = citizenTrackingHttpRequest(
    $markReadUrl,
    'POST',
    json_encode(['reporter_reference' => 'CITIZEN:999'], JSON_THROW_ON_ERROR),
    ['Content-Type: application/json']
);
$identityPayload = citizenTrackingHttpPayload($identityBody);
citizenTrackingHttpAssert(
    'ClientReporterReferenceBodyRejected',
    $identityBody['status'] === 400
        && ($identityPayload['error']['code'] ?? null) === 'INVALID_REQUEST'
);

$identityQuery = citizenTrackingHttpRequest(
    $markReadUrl . '?reporter_reference=CITIZEN%3A999',
    'POST',
    '{}',
    ['Content-Type: application/json']
);
$identityQueryPayload = citizenTrackingHttpPayload($identityQuery);
citizenTrackingHttpAssert(
    'ClientReporterReferenceQueryRejected',
    $identityQuery['status'] === 400
        && ($identityQueryPayload['error']['code'] ?? null) === 'INVALID_REQUEST'
);

$invalidDetail = citizenTrackingHttpRequest(
    $baseUrl . '/api/citizen/drrm/my-incident.php',
    'GET'
);
$invalidDetailPayload = citizenTrackingHttpPayload($invalidDetail);
citizenTrackingHttpAssert(
    'MissingIncidentNumberRejected',
    $invalidDetail['status'] === 400
        && ($invalidDetailPayload['error']['code'] ?? null) === 'INVALID_REQUEST'
);

$preflight = citizenTrackingHttpRequest(
    $notificationsUrl,
    'OPTIONS',
    null,
    [
        'Origin: http://localhost:19006',
        'Access-Control-Request-Method: GET',
    ]
);
citizenTrackingHttpAssert(
    'DevelopmentOriginPreflightAllowed',
    $preflight['status'] === 204
        && stripos(
            $preflight['headers'],
            'Access-Control-Allow-Origin: http://localhost:19006'
        ) !== false
        && stripos(
            $preflight['headers'],
            'Access-Control-Allow-Credentials: true'
        ) !== false
        && stripos(
            $preflight['headers'],
            'Access-Control-Allow-Origin: *'
        ) === false
);

$untrusted = citizenTrackingHttpRequest(
    $notificationsUrl,
    'OPTIONS',
    null,
    [
        'Origin: https://attacker.example',
        'Access-Control-Request-Method: GET',
    ]
);
$untrustedPayload = citizenTrackingHttpPayload($untrusted);
citizenTrackingHttpAssert(
    'UntrustedOriginRejected',
    $untrusted['status'] === 403
        && ($untrustedPayload['error']['code'] ?? null) === 'INVALID_REQUEST'
);

$unauthenticated = citizenTrackingHttpRequest($myIncidentsUrl, 'GET');
citizenTrackingHttpAssert(
    'TrackingResponsesAreNotCached',
    stripos($unauthenticated['headers'], 'Cache-Control: no-store') !== false
);
citizenTrackingHttpAssert(
    'SafeErrorsDoNotLeakInfrastructure',
    stripos($unauthenticated['body'], 'supabase') === false
        && stripos($unauthenticated['body'], 'sql') === false
        && stripos($unauthenticated['body'], 'stack') === false
        && stripos($unauthenticated['body'], 'sb_secret_') === false
);

if ($failures !== []) {
    fwrite(
        STDERR,
        'Citizen tracking HTTP failures: ' . implode(', ', $failures) . PHP_EOL
    );
    exit(1);
}

echo 'CitizenIncidentTrackingHttpSecurity=PASS' . PHP_EOL;
