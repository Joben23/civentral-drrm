<?php

declare(strict_types=1);

use App\Config\CitizenApiConfig;
use App\Config\SupabaseConfig;
use App\Services\CitizenAuthenticationRequiredException;
use App\Services\CitizenAuthenticationUnavailableException;
use App\Services\CitizenSessionIdentityVerifier;
use App\Services\DrrmCaloocanBoundaryService;
use App\Services\DrrmCitizenIncidentSubmissionException;
use App\Services\DrrmCitizenIncidentSubmissionService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../../../config/citizen_api.php';
require_once __DIR__ . '/../../../config/supabase.php';
require_once __DIR__ . '/../../../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../../../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../../../src/Services/CitizenSessionIdentityVerifier.php';
require_once __DIR__ . '/../../../src/Services/DrrmCaloocanBoundaryService.php';
require_once __DIR__ . '/../../../src/Services/DrrmCitizenIncidentSubmissionService.php';

const CITIZEN_INCIDENT_MAX_REQUEST_BYTES = 16384;

ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

try {
    $citizenConfig = CitizenApiConfig::fromEnvironment(__DIR__ . '/../../../.env');
} catch (Throwable) {
    citizenIncidentRespondError(
        'INCIDENT_SERVICE_UNAVAILABLE',
        'Incident submission is temporarily unavailable.',
        503
    );
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    header('Access-Control-Allow-Credentials: true');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Vary: Origin');
    header('Allow: POST, OPTIONS');
}

$origin = isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])
    ? rtrim(trim($_SERVER['HTTP_ORIGIN']), '/')
    : null;
if ($origin !== null && ($origin === '' || !$citizenConfig->isAllowedOrigin($origin))) {
    citizenIncidentRespondError('INVALID_REQUEST', 'The request origin is not allowed.', 403);
}
if ($origin !== null) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    citizenIncidentRespondError('INVALID_REQUEST', 'Method not allowed.', 405);
}
if ($_GET !== []) {
    citizenIncidentRespondError('INVALID_REQUEST', 'Query parameters are not supported.', 400);
}

$contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
if (is_string($contentLength) && ctype_digit($contentLength)
    && (int) $contentLength > CITIZEN_INCIDENT_MAX_REQUEST_BYTES) {
    citizenIncidentRespondError('INVALID_REQUEST', 'The request payload is too large.', 413);
}
$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
if ($contentType !== 'application/json') {
    citizenIncidentRespondError('INVALID_REQUEST', 'Content-Type must be application/json.', 415);
}

$stream = fopen('php://input', 'rb');
$rawBody = is_resource($stream) ? stream_get_contents($stream, CITIZEN_INCIDENT_MAX_REQUEST_BYTES + 1) : false;
if (is_resource($stream)) {
    fclose($stream);
}
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > CITIZEN_INCIDENT_MAX_REQUEST_BYTES) {
    citizenIncidentRespondError('INVALID_REQUEST', 'The request payload is empty or too large.', 413);
}

try {
    $input = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    citizenIncidentRespondError('INVALID_REQUEST', 'The request body must contain valid JSON.', 400);
}
if (!is_array($input) || array_is_list($input)) {
    citizenIncidentRespondError('INVALID_REQUEST', 'The request body must be a JSON object.', 400);
}

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'
        && (string) $_SERVER['HTTPS'] !== '';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
$remoteSessionId = isset($_SESSION['remote_phpsessid']) && is_string($_SESSION['remote_phpsessid'])
    ? $_SESSION['remote_phpsessid']
    : null;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $identity = (new CitizenSessionIdentityVerifier(
        $citizenConfig->profileUrl(),
        $remoteSessionId
    ))->verify();
} catch (CitizenAuthenticationRequiredException $exception) {
    citizenIncidentRespondError('AUTHENTICATION_REQUIRED', $exception->getMessage(), 401);
} catch (CitizenAuthenticationUnavailableException) {
    citizenIncidentRespondError(
        'INCIDENT_SERVICE_UNAVAILABLE',
        'Citizen identity verification is temporarily unavailable.',
        503
    );
} catch (Throwable) {
    citizenIncidentRespondError(
        'INCIDENT_SERVICE_UNAVAILABLE',
        'Citizen identity verification is temporarily unavailable.',
        503
    );
}

try {
    $service = new DrrmCitizenIncidentSubmissionService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../../.env')),
        new DrrmCaloocanBoundaryService(
            __DIR__ . '/../../../data/import/caloocan-city-boundary.geojson'
        )
    );
    $result = $service->submit($input, $identity);
} catch (DrrmCitizenIncidentSubmissionException $exception) {
    if ($exception->errorCode === 'RATE_LIMITED') {
        header('Retry-After: 900');
    }
    citizenIncidentRespondError($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
} catch (Throwable) {
    citizenIncidentRespondError(
        'INCIDENT_SUBMISSION_FAILED',
        'The incident report could not be submitted.',
        500
    );
}

citizenIncidentRespond([
    'success' => true,
    'incident_number' => $result['incident_number'],
    'status' => 'SUBMITTED',
    'submitted_at' => $result['submitted_at'],
    'message' => $result['idempotent_replay']
        ? 'This incident report was already received.'
        : 'Your incident report was submitted for DRRM review.',
], 201);

/** @param array<string, mixed> $payload */
function citizenIncidentRespond(array $payload, int $statusCode): never
{
    http_response_code($statusCode);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    echo is_string($json)
        ? $json
        : '{"success":false,"error":{"code":"INCIDENT_SUBMISSION_FAILED","message":"The incident report could not be submitted."}}';
    exit;
}

function citizenIncidentRespondError(string $code, string $message, int $statusCode): never
{
    citizenIncidentRespond([
        'success' => false,
        'error' => ['code' => $code, 'message' => $message],
    ], $statusCode);
}
