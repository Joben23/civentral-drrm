<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('curl')) {
    fwrite(STDERR, 'PHP cURL is required.' . PHP_EOL);
    exit(1);
}

$baseUrl = rtrim($argv[1] ?? 'http://localhost/civentral-drrm', '/');
$results = [];
$cleanupSessionIds = [];

function logoutHttpTest(string $name, bool $passed): void
{
    global $results;
    $results[$name] = $passed;
}

/** @return array{status: int, headers: string, body: string} */
function logoutHttpRequest(string $url, string $method, ?string $body = null, array $headers = []): array
{
    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Unable to initialize HTTP test.');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
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

/** @return array<string, mixed>|null */
function logoutHttpPayload(array $response): ?array
{
    $payload = json_decode($response['body'], true);
    return is_array($payload) && !array_is_list($payload) ? $payload : null;
}

function seedCitizenSession(): string
{
    ini_set('session.use_strict_mode', '0');
    session_id('');
    session_start();
    $id = session_id();
    $_SESSION['remote_phpsessid'] = bin2hex(random_bytes(16));
    $_SESSION['citizen_user_id'] = 42;
    $_SESSION['citizen_email'] = 'logout-test@example.invalid';
    $_SESSION['citizen_test_marker'] = 'present';
    session_write_close();
    return $id;
}

/** @return array<string, mixed> */
function readCitizenSession(string $id): array
{
    ini_set('session.use_strict_mode', '0');
    session_id($id);
    session_start();
    $data = $_SESSION;
    session_write_close();
    return $data;
}

function cleanupCitizenSession(string $id): void
{
    if ($id === '' || preg_match('/^[A-Za-z0-9,-]+$/', $id) !== 1) return;
    ini_set('session.use_strict_mode', '0');
    session_id($id);
    session_start();
    $_SESSION = [];
    session_destroy();
}

function collectResponseSessionIds(array $response): void
{
    global $cleanupSessionIds;
    preg_match_all('/Set-Cookie:\s*PHPSESSID=([^;\r\n]+)/i', $response['headers'], $matches);
    foreach ($matches[1] ?? [] as $id) {
        if (strcasecmp($id, 'deleted') !== 0) $cleanupSessionIds[] = $id;
    }
}

$logoutUrl = $baseUrl . '/api/citizen/logout.php';
$sessionId = seedCitizenSession();
$cleanupSessionIds[] = $sessionId;
$cookieHeader = 'Cookie: PHPSESSID=' . $sessionId;
$jsonHeader = 'Content-Type: application/json';

$get = logoutHttpRequest($logoutUrl, 'GET', null, [$cookieHeader]);
logoutHttpTest('GetRejected', $get['status'] === 405);
logoutHttpTest('GetDoesNotLogout',
    (readCitizenSession($sessionId)['citizen_test_marker'] ?? null) === 'present');

$identityBody = json_encode(['citizen_id' => 999], JSON_THROW_ON_ERROR);
$identity = logoutHttpRequest($logoutUrl, 'POST', $identityBody, [$jsonHeader, $cookieHeader]);
$identityPayload = logoutHttpPayload($identity);
logoutHttpTest('ClientIdentityRejected',
    $identity['status'] === 400
    && ($identityPayload['error']['code'] ?? null) === 'INVALID_REQUEST');
logoutHttpTest('RejectedBodyDoesNotLogout',
    (readCitizenSession($sessionId)['citizen_test_marker'] ?? null) === 'present');

$success = logoutHttpRequest(
    $logoutUrl,
    'POST',
    json_encode(new stdClass(), JSON_THROW_ON_ERROR),
    [$jsonHeader, $cookieHeader]
);
$successPayload = logoutHttpPayload($success);
$sessionFile = rtrim(session_save_path(), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
logoutHttpTest('PostSucceedsWithoutIdentity',
    $success['status'] === 200 && $successPayload === ['success' => true]);
logoutHttpTest('ServerSessionRecordDeleted', !is_file($sessionFile));

$setCookie = $success['headers'];
logoutHttpTest('CookieExpiredWithMatchingAttributes',
    preg_match('/Set-Cookie:\s*PHPSESSID=(?:deleted)?;/i', $setCookie) === 1
    && stripos($setCookie, 'expires=') !== false
    && stripos($setCookie, 'Max-Age=0') !== false
    && stripos($setCookie, 'path=/') !== false
    && stripos($setCookie, 'HttpOnly') !== false
    && stripos($setCookie, 'SameSite=Lax') !== false
    && stripos($setCookie, 'domain=') === false
    && stripos($setCookie, 'Secure') === false);

$warning = logoutHttpRequest(
    $baseUrl . '/api/citizen/drrm/warning-notifications.php',
    'GET',
    null,
    [$cookieHeader]
);
$warningPayload = logoutHttpPayload($warning);
collectResponseSessionIds($warning);
logoutHttpTest('WarningFeedRejectedAfterLogout',
    $warning['status'] === 401
    && ($warningPayload['error']['code'] ?? null) === 'AUTHENTICATION_REQUIRED');

$readBody = json_encode([
    'notification_event_id' => '00000000-0000-4000-8000-000000000001',
], JSON_THROW_ON_ERROR);
$warningRead = logoutHttpRequest(
    $baseUrl . '/api/citizen/drrm/warning-notifications-read.php',
    'POST',
    $readBody,
    [$jsonHeader, $cookieHeader]
);
$warningReadPayload = logoutHttpPayload($warningRead);
collectResponseSessionIds($warningRead);
logoutHttpTest('WarningReadCannotMutateAfterLogout',
    $warningRead['status'] === 401
    && ($warningReadPayload['error']['code'] ?? null) === 'AUTHENTICATION_REQUIRED');

$incidents = logoutHttpRequest(
    $baseUrl . '/api/citizen/drrm/my-incidents.php',
    'GET',
    null,
    [$cookieHeader]
);
$incidentsPayload = logoutHttpPayload($incidents);
collectResponseSessionIds($incidents);
logoutHttpTest('IncidentRouteRejectedAfterLogout',
    $incidents['status'] === 401
    && ($incidentsPayload['error']['code'] ?? null) === 'AUTHENTICATION_REQUIRED');

$profile = logoutHttpRequest(
    $baseUrl . '/api/citizen/get-profile.php',
    'GET',
    null,
    [$cookieHeader]
);
collectResponseSessionIds($profile);
logoutHttpTest('ProfileRejectedAfterLogout', $profile['status'] === 401);

$idempotent = logoutHttpRequest(
    $logoutUrl,
    'POST',
    json_encode(new stdClass(), JSON_THROW_ON_ERROR),
    [$jsonHeader]
);
logoutHttpTest('AlreadyLoggedOutIsSafe',
    $idempotent['status'] === 200
    && logoutHttpPayload($idempotent) === ['success' => true]);

$allLogoutBodies = $success['body'] . $idempotent['body'];
logoutHttpTest('NoSessionOrInternalDetailsReturned',
    !str_contains($allLogoutBodies, $sessionId)
    && stripos($allLogoutBodies, 'PHPSESSID') === false
    && stripos($allLogoutBodies, 'remote_phpsessid') === false
    && stripos($allLogoutBodies, 'stack') === false
    && stripos($allLogoutBodies, 'curl') === false
    && stripos($allLogoutBodies, 'supabase') === false);

$preflight = logoutHttpRequest($logoutUrl, 'OPTIONS', null, [
    'Origin: http://localhost:19006',
    'Access-Control-Request-Method: POST',
]);
logoutHttpTest('CredentialedDevelopmentPreflightAllowed',
    $preflight['status'] === 204
    && stripos($preflight['headers'],
        'Access-Control-Allow-Origin: http://localhost:19006') !== false
    && stripos($preflight['headers'], 'Access-Control-Allow-Credentials: true') !== false
    && stripos($preflight['headers'], 'Access-Control-Allow-Origin: *') === false);

$untrusted = logoutHttpRequest($logoutUrl, 'OPTIONS', null, [
    'Origin: https://attacker.example',
    'Access-Control-Request-Method: POST',
]);
logoutHttpTest('UntrustedOriginRejected', $untrusted['status'] === 403);

$formPost = logoutHttpRequest($logoutUrl, 'POST', 'unused=value', [
    'Content-Type: application/x-www-form-urlencoded',
]);
logoutHttpTest('JsonOnlyPostEnforced', $formPost['status'] === 415);
$queryPost = logoutHttpRequest(
    $logoutUrl . '?citizen_id=999',
    'POST',
    json_encode(new stdClass(), JSON_THROW_ON_ERROR),
    [$jsonHeader]
);
logoutHttpTest('IdentityQueryRejected', $queryPost['status'] === 400);

$cleanupSessionIds = array_values(array_unique($cleanupSessionIds));
foreach ($cleanupSessionIds as $cleanupId) cleanupCitizenSession($cleanupId);

$failed = [];
foreach ($results as $name => $passed) {
    echo $name . '=' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
if ($failed !== []) {
    fwrite(STDERR, 'Citizen logout HTTP failures: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'CitizenLogoutHttpSecurity=PASS' . PHP_EOL;
