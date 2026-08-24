<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "The PHP cURL extension is required.\n");
    exit(1);
}

ob_start();

$baseUrl = rtrim($argv[1] ?? 'http://localhost/civentral-drrm', '/');
$sessionId = 'module3-http-' . bin2hex(random_bytes(8));
$csrfToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$failures = [];

/** @return array{status: int, body: string} */
function incidentHttpRequest(
    string $url,
    string $sessionId,
    string $method = 'GET',
    ?string $body = null,
    array $headers = []
): array {
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the HTTP test.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sessionId,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($handle);
    if (!is_string($response)) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('Local HTTP test failed: ' . $error);
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return ['status' => $status, 'body' => $response];
}

function incidentHttpAssert(string $name, bool $condition): void
{
    global $failures;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

session_id($sessionId);
session_start();
$_SESSION = [
    'user_id' => 'module3-http-test',
    'current_user_details' => ['is_superadmin' => false, 'is_global_access' => true],
    'user_permissions_map' => [],
];
session_write_close();

try {
    $unauthorized = incidentHttpRequest(
        $baseUrl . '/api/drrm/incidents-summary.php',
        $sessionId
    );
    incidentHttpAssert('UnauthorizedSummaryRejected', $unauthorized['status'] === 403);
    $unauthorizedPage = incidentHttpRequest(
        $baseUrl . '/pages/drrm/incident-reporting-response.php',
        $sessionId
    );
    incidentHttpAssert('UnauthorizedModule3PageRedirected', $unauthorizedPage['status'] === 302);
    $unauthorizedTransition = incidentHttpRequest(
        $baseUrl . '/api/drrm/incident-status.php',
        $sessionId,
        'POST',
        json_encode([
            'incident_id' => '11111111-1111-4111-8111-111111111111',
            'action' => 'REVIEW',
        ], JSON_THROW_ON_ERROR),
        ['Content-Type: application/json']
    );
    incidentHttpAssert('UnauthorizedTransitionRejected', $unauthorizedTransition['status'] === 403);

    session_id($sessionId);
    session_start();
    $_SESSION['current_user_details']['is_superadmin'] = true;
    $_SESSION['drrm_incident_csrf'] = ['token' => $csrfToken, 'issued_at' => time()];
    session_write_close();

    $validBody = json_encode([
        'incident_id' => '11111111-1111-4111-8111-111111111111',
        'action' => 'REVIEW',
    ], JSON_THROW_ON_ERROR);
    $invalidCsrf = incidentHttpRequest(
        $baseUrl . '/api/drrm/incident-status.php',
        $sessionId,
        'POST',
        $validBody,
        ['Content-Type: application/json', 'X-CSRF-Token: invalid']
    );
    incidentHttpAssert('InvalidCsrfRejected', $invalidCsrf['status'] === 403);

    $malformed = incidentHttpRequest(
        $baseUrl . '/api/drrm/incident-status.php',
        $sessionId,
        'POST',
        '{',
        ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrfToken]
    );
    incidentHttpAssert('MalformedJsonRejected', $malformed['status'] === 400);

    $arbitraryAction = incidentHttpRequest(
        $baseUrl . '/api/drrm/incident-status.php',
        $sessionId,
        'POST',
        json_encode([
            'incident_id' => '11111111-1111-4111-8111-111111111111',
            'action' => 'FORCE_CLOSE',
        ], JSON_THROW_ON_ERROR),
        ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrfToken]
    );
    incidentHttpAssert('ArbitraryActionRejected', $arbitraryAction['status'] === 400);

    $wrongMethod = incidentHttpRequest(
        $baseUrl . '/api/drrm/incident-status.php',
        $sessionId
    );
    incidentHttpAssert('MutationGetRejected', $wrongMethod['status'] === 405);

    $module3Summary = incidentHttpRequest(
        $baseUrl . '/api/drrm/incidents-summary.php',
        $sessionId
    );
    $module3SummaryPayload = json_decode($module3Summary['body'], true);
    $module3SummaryControlled = $module3Summary['status'] === 502
        && is_array($module3SummaryPayload)
        && ($module3SummaryPayload['success'] ?? null) === false;
    if ($module3Summary['status'] === 200 && is_array($module3SummaryPayload)) {
        $counts = $module3SummaryPayload['data'] ?? null;
        $module3SummaryControlled = is_array($counts)
            && ($module3SummaryPayload['success'] ?? null) === true
            && count(array_filter(
                ['submitted', 'under_review', 'active_response', 'resolved_today', 'total'],
                static fn (string $key): bool => is_int($counts[$key] ?? null)
                    && $counts[$key] >= 0
            )) === 5;
    }
    incidentHttpAssert('Module3SummaryBoundaryControlled', $module3SummaryControlled);

    $module3Page = incidentHttpRequest(
        $baseUrl . '/pages/drrm/incident-reporting-response.php',
        $sessionId
    );
    incidentHttpAssert('Module3PageLoads', $module3Page['status'] === 200);
    $incidentScript = file_get_contents(__DIR__ . '/../assets/js/incident-response.js');
    incidentHttpAssert('Module3EmptyStatePresent',
        str_contains($module3Page['body'], 'data-incident-table-body')
        && str_contains($module3Page['body'], 'Incident Reporting &amp; Response Log')
        && is_string($incidentScript)
        && str_contains($incidentScript, 'No incident reports have been recorded yet.')
    );

    $module1Page = incidentHttpRequest(
        $baseUrl . '/pages/drrm/hazard-evacuation-map.php',
        $sessionId
    );
    incidentHttpAssert('Module1StillAccessible', $module1Page['status'] === 200);

    $module4Page = incidentHttpRequest(
        $baseUrl . '/pages/drrm/disaster-early-warning.php',
        $sessionId
    );
    incidentHttpAssert('Module4StillAccessible', $module4Page['status'] === 200);

    $module4Summary = incidentHttpRequest(
        $baseUrl . '/api/drrm/early-warning-summary.php',
        $sessionId
    );
    $module4SummaryPayload = json_decode($module4Summary['body'], true);
    incidentHttpAssert('Module4ReadApiStillWorks',
        $module4Summary['status'] === 200
        && is_array($module4SummaryPayload)
        && ($module4SummaryPayload['success'] ?? null) === true
        && is_array($module4SummaryPayload['data']['metrics'] ?? null)
    );
} finally {
    session_id($sessionId);
    session_start();
    $_SESSION = [];
    session_destroy();
}

if ($failures !== []) {
    fwrite(STDERR, 'Module 3 HTTP failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Module3HttpSecurity=PASS\n";
