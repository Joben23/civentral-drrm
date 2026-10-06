<?php

declare(strict_types=1);

use App\Config\CitizenApiConfig;
use App\Services\CitizenSessionManager;

require_once __DIR__ . '/../../config/citizen_api.php';
require_once __DIR__ . '/../../src/Services/CitizenSessionManager.php';

ini_set('display_errors', '0');

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

try {
    $config = CitizenApiConfig::fromEnvironment(__DIR__ . '/../../.env');
} catch (Throwable) {
    citizenLogoutError('LOGOUT_UNAVAILABLE', 'Logout is temporarily unavailable.', 503);
}

$origin = isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])
    ? rtrim(trim($_SERVER['HTTP_ORIGIN']), '/')
    : null;
if ($origin !== null && ($origin === '' || !$config->isAllowedOrigin($origin))) {
    citizenLogoutError('INVALID_REQUEST', 'The request origin is not allowed.', 403);
}
if ($origin !== null && !headers_sent()) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    citizenLogoutError('INVALID_REQUEST', 'Method not allowed.', 405);
}
if ($_GET !== []) {
    citizenLogoutError('INVALID_REQUEST', 'Query parameters are not supported.', 400);
}

citizenLogoutRequireEmptyJsonBody();

if (session_status() === PHP_SESSION_ACTIVE || CitizenSessionManager::hasSessionCookie()) {
    try {
        CitizenSessionManager::start();
        $remoteSessionId = CitizenSessionManager::remoteSessionId();

        try {
            if ($remoteSessionId !== null) {
                require_once __DIR__ . '/../../config/proxy.php';
                proxyRequest($config->logoutUrl(), 'POST', '{}');
            }
        } catch (Throwable) {
            // Local invalidation must still complete when upstream is unavailable.
        } finally {
            CitizenSessionManager::invalidate();
        }
    } catch (Throwable) {
        citizenLogoutError('LOGOUT_UNAVAILABLE', 'Logout is temporarily unavailable.', 503);
    }
}

citizenLogoutRespond(['success' => true], 200);

/** Require a small empty JSON object so no client identity can be accepted. */
function citizenLogoutRequireEmptyJsonBody(): void
{
    $maximumBytes = 1024;
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
    if (is_string($contentLength) && ctype_digit($contentLength)
        && (int) $contentLength > $maximumBytes) {
        citizenLogoutError('INVALID_REQUEST', 'The request payload is too large.', 413);
    }

    $contentType = strtolower(trim(explode(
        ';',
        (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
        2
    )[0]));
    if ($contentType !== 'application/json') {
        citizenLogoutError(
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
        citizenLogoutError(
            'INVALID_REQUEST',
            'The request body must be an empty JSON object.',
            400
        );
    }

    try {
        $input = json_decode($rawBody, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        citizenLogoutError(
            'INVALID_REQUEST',
            'The request body must contain valid JSON.',
            400
        );
    }
    if (!$input instanceof stdClass || get_object_vars($input) !== []) {
        citizenLogoutError(
            'INVALID_REQUEST',
            'The request body must be an empty JSON object.',
            400
        );
    }
}

function citizenLogoutError(string $code, string $message, int $statusCode): never
{
    citizenLogoutRespond([
        'success' => false,
        'error' => ['code' => $code, 'message' => $message],
    ], $statusCode);
}

/** @param array<string, mixed> $payload */
function citizenLogoutRespond(array $payload, int $statusCode): never
{
    http_response_code($statusCode);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
    );
    echo is_string($json)
        ? $json
        : chr(123) . chr(34) . 'success' . chr(34) . ':false' . chr(125);
    exit;
}
