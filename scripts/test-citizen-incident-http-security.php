<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('curl')) { fwrite(STDERR, "PHP cURL is required.\n"); exit(1); }

$endpoint = rtrim($argv[1] ?? 'http://localhost/civentral-drrm', '/') . '/api/citizen/drrm/incidents.php';
$failures = [];

function cihr(string $url, string $method, ?string $body, array $headers = []): array
{
    $handle = curl_init($url);
    if ($handle === false) { throw new RuntimeException('Unable to initialize HTTP test.'); }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($body !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, $body); }
    $response = curl_exec($handle);
    if (!is_string($response)) { $error = curl_error($handle); curl_close($handle); throw new RuntimeException($error); }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    return ['status' => $status, 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
}
function ciha(string $name, bool $condition): void
{
    global $failures;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) { $failures[] = $name; }
}

$validBody = json_encode([
    'request_id' => '11111111-1111-4111-8111-111111111111',
    'incident_type' => 'FLOOD', 'title' => 'Floodwater rising on Samson Road',
    'description' => 'Floodwater is rising near the intersection and is affecting vehicle access.',
    'location_description' => 'Samson Road near the public market',
], JSON_THROW_ON_ERROR);

$response = cihr($endpoint, 'POST', $validBody, ['Content-Type: application/json']);
$payload = json_decode($response['body'], true);
ciha('UnauthenticatedCitizenSubmissionRejected', $response['status'] === 401 && ($payload['error']['code'] ?? null) === 'AUTHENTICATION_REQUIRED');
ciha('CitizenMutationGetRejected', cihr($endpoint, 'GET', null)['status'] === 405);
ciha('NonJsonContentTypeRejected', cihr($endpoint, 'POST', $validBody, ['Content-Type: text/plain'])['status'] === 415);
ciha('MalformedJsonRejected', cihr($endpoint, 'POST', '{', ['Content-Type: application/json'])['status'] === 400);
ciha('OversizedPayloadRejected', cihr($endpoint, 'POST', str_repeat('x', 16385), ['Content-Type: application/json'])['status'] === 413);

$preflight = cihr($endpoint, 'OPTIONS', null, ['Origin: http://localhost:19006', 'Access-Control-Request-Method: POST']);
ciha('DevelopmentOriginPreflightAllowed', $preflight['status'] === 204
    && stripos($preflight['headers'], 'Access-Control-Allow-Origin: http://localhost:19006') !== false
    && stripos($preflight['headers'], 'Access-Control-Allow-Origin: *') === false);
ciha('UntrustedOriginRejected', cihr($endpoint, 'OPTIONS', null, [
    'Origin: https://attacker.example', 'Access-Control-Request-Method: POST',
])['status'] === 403);

if ($failures !== []) { fwrite(STDERR, 'Failures: ' . implode(', ', $failures) . PHP_EOL); exit(1); }
echo "CitizenIncidentHttpSecurity=PASS\n";
