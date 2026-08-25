<?php

declare(strict_types=1);

use App\Config\CitizenApiConfig;
use App\Config\SupabaseConfig;
use App\Services\CitizenAuthenticationRequiredException;
use App\Services\CitizenAuthenticationUnavailableException;
use App\Services\CitizenIdentity;
use App\Services\CitizenSessionIdentityVerifier;
use App\Services\DrrmCitizenIncidentTrackingService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../../../config/citizen_api.php';
require_once __DIR__ . '/../../../config/supabase.php';
require_once __DIR__ . '/../../../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../../../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../../../src/Services/CitizenSessionIdentityVerifier.php';
require_once __DIR__ . '/../../../src/Services/DrrmCitizenIncidentTrackingService.php';

/**
 * Applies the same credentialed, allowlisted CORS and upstream PHP-session
 * bridge used by citizen incident submission.
 */
function drrmCitizenTrackingInitialize(string $requiredMethod): CitizenApiConfig
{
    ini_set('display_errors', '0');
    ini_set('session.use_strict_mode', '1');

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Methods: ' . $requiredMethod . ', OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Content-Type');
        header('Access-Control-Allow-Credentials: true');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Vary: Origin');
        header('Allow: ' . $requiredMethod . ', OPTIONS');
    }

    try {
        $config = CitizenApiConfig::fromEnvironment(__DIR__ . '/../../../.env');
    } catch (Throwable) {
        drrmCitizenTrackingError(
            'INCIDENT_TRACKING_UNAVAILABLE',
            'Incident tracking is temporarily unavailable.',
            503
        );
    }

    $origin = isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])
        ? rtrim(trim($_SERVER['HTTP_ORIGIN']), '/')
        : null;
    if ($origin !== null && ($origin === '' || !$config->isAllowedOrigin($origin))) {
        drrmCitizenTrackingError('INVALID_REQUEST', 'The request origin is not allowed.', 403);
    }
    if ($origin !== null && !headers_sent()) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($method !== $requiredMethod) {
        drrmCitizenTrackingError('INVALID_REQUEST', 'Method not allowed.', 405);
    }

    return $config;
}

function drrmCitizenTrackingIdentity(CitizenApiConfig $config): CitizenIdentity
{
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = isset($_SERVER['HTTPS'])
            && strtolower((string) $_SERVER['HTTPS']) !== 'off'
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

    $remoteSessionId = isset($_SESSION['remote_phpsessid'])
        && is_string($_SESSION['remote_phpsessid'])
        ? $_SESSION['remote_phpsessid']
        : null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    try {
        return (new CitizenSessionIdentityVerifier(
            $config->profileUrl(),
            $remoteSessionId
        ))->verify();
    } catch (CitizenAuthenticationRequiredException $exception) {
        drrmCitizenTrackingError('AUTHENTICATION_REQUIRED', $exception->getMessage(), 401);
    } catch (CitizenAuthenticationUnavailableException) {
        drrmCitizenTrackingError(
            'INCIDENT_TRACKING_UNAVAILABLE',
            'Citizen identity verification is temporarily unavailable.',
            503
        );
    } catch (Throwable) {
        drrmCitizenTrackingError(
            'INCIDENT_TRACKING_UNAVAILABLE',
            'Citizen identity verification is temporarily unavailable.',
            503
        );
    }
}

function drrmCitizenTrackingService(): DrrmCitizenIncidentTrackingService
{
    return new DrrmCitizenIncidentTrackingService(
        new SupabaseRestClient(
            SupabaseConfig::fromEnvironment(__DIR__ . '/../../../.env')
        )
    );
}

/** POST read-state requests must be a small JSON object with no client fields. */
function drrmCitizenTrackingRequireEmptyJsonBody(): void
{
    $maximumBytes = 1024;
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
    if (is_string($contentLength) && ctype_digit($contentLength)
        && (int) $contentLength > $maximumBytes) {
        drrmCitizenTrackingError('INVALID_REQUEST', 'The request payload is too large.', 413);
    }

    $contentType = strtolower(trim(explode(
        ';',
        (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
        2
    )[0]));
    if ($contentType !== 'application/json') {
        drrmCitizenTrackingError(
            'INVALID_REQUEST',
            'Content-Type must be application/json.',
            415
        );
    }

    $stream = fopen('php://input', 'rb');
    $rawBody = is_resource($stream)
        ? stream_get_contents($stream, $maximumBytes + 1)
        : false;
    if (is_resource($stream)) {
        fclose($stream);
    }
    if (!is_string($rawBody) || $rawBody === ''
        || strlen($rawBody) > $maximumBytes) {
        drrmCitizenTrackingError(
            'INVALID_REQUEST',
            'The request body must be an empty JSON object.',
            400
        );
    }

    try {
        $input = json_decode($rawBody, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        drrmCitizenTrackingError(
            'INVALID_REQUEST',
            'The request body must contain valid JSON.',
            400
        );
    }
    if (!$input instanceof stdClass || get_object_vars($input) !== []) {
        drrmCitizenTrackingError(
            'INVALID_REQUEST',
            'The request body must be an empty JSON object.',
            400
        );
    }
}

/** @param array<string, mixed> $data */
function drrmCitizenTrackingSuccess(array $data, int $statusCode = 200): never
{
    drrmCitizenTrackingRespond(['success' => true] + $data, $statusCode);
}

function drrmCitizenTrackingError(string $code, string $message, int $statusCode): never
{
    drrmCitizenTrackingRespond([
        'success' => false,
        'error' => ['code' => $code, 'message' => $message],
    ], $statusCode);
}

/** @param array<string, mixed> $payload */
function drrmCitizenTrackingRespond(array $payload, int $statusCode): never
{
    http_response_code($statusCode);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($json)) {
        $json = (string) json_encode([
            'success' => false,
            'error' => [
                'code' => 'INCIDENT_TRACKING_UNAVAILABLE',
                'message' => 'Incident tracking is temporarily unavailable.',
            ],
        ]);
    }
    echo $json;
    exit;
}
