<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\AuthService;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../../src/Services/DrrmMapReadService.php';

/**
 * @param callable(DrrmMapReadService): array<mixed> $loader
 * @param list<string> $allowedQueryParameters
 */
function drrmApiRun(callable $loader, array $allowedQueryParameters = []): never
{
    ini_set('display_errors', '0');
    drrmApiSendHeaders();

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if ($method !== 'GET') {
        drrmApiRespond(false, null, 'Method not allowed.', 405);
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $authService = new AuthService();

    if (!$authService->isLoggedIn()) {
        drrmApiRespond(false, null, 'Authentication required.', 401);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    foreach (array_keys($_GET) as $parameter) {
        if (!is_string($parameter) || !in_array($parameter, $allowedQueryParameters, true)) {
            drrmApiRespond(false, null, 'Invalid query parameters.', 400);
        }
    }

    try {
        $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../../.env');
        $service = new DrrmMapReadService(new SupabaseRestClient($config));
        $data = $loader($service);

        drrmApiRespond(true, $data);
    } catch (InvalidArgumentException) {
        drrmApiRespond(false, null, 'Invalid query parameters.', 400);
    } catch (Throwable) {
        drrmApiRespond(false, null, 'Unable to load DRRM map data.', 502);
    }
}

function drrmApiBarangaySearch(): ?string
{
    if (!array_key_exists('search', $_GET)) {
        return null;
    }

    if (!is_string($_GET['search'])) {
        throw new InvalidArgumentException('Invalid search parameter type.');
    }

    return $_GET['search'];
}

function drrmApiSendHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Allow: GET, OPTIONS');
}

function drrmApiRespond(
    bool $success,
    mixed $data = null,
    ?string $message = null,
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    $payload = ['success' => $success];

    if ($success) {
        $payload['data'] = $data ?? [];
    } else {
        $payload['message'] = $message ?? 'Unable to load DRRM map data.';
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    echo $json === false
        ? '{"success":false,"message":"Unable to encode DRRM map data."}'
        : $json;
    exit;
}
