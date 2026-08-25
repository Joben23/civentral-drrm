<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\DrrmEarlyWarningWriteService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../../../config/supabase.php';
require_once __DIR__ . '/../../../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../../../src/Services/DrrmEarlyWarningWriteService.php';

ini_set('display_errors', '0');

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    header('Cache-Control: public, max-age=300, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Allow: GET, OPTIONS');
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    citizenBarangayRespond(false, null, 'Method not allowed.', 405);
}
if ($_GET !== []) {
    citizenBarangayRespond(false, null, 'Unsupported query parameters.', 400);
}

try {
    $service = new DrrmEarlyWarningWriteService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../../../.env'))
    );
    $barangays = array_map(
        static fn (array $barangay): array => [
            'barangay_id' => $barangay['barangay_id'],
            'name' => $barangay['name'],
        ],
        $service->availableBarangays()
    );
    citizenBarangayRespond(true, $barangays);
} catch (Throwable) {
    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }
    citizenBarangayRespond(false, null, 'Barangay information is temporarily unavailable.', 503);
}

/** @param list<array{barangay_id: string, name: string}>|null $barangays */
function citizenBarangayRespond(
    bool $success,
    ?array $barangays,
    ?string $message = null,
    int $statusCode = 200
): never {
    http_response_code($statusCode);
    $payload = $success && $barangays !== null
        ? ['success' => true, 'count' => count($barangays), 'barangays' => $barangays]
        : [
            'success' => false,
            'count' => 0,
            'barangays' => [],
            'message' => $message ?? 'Barangay information is temporarily unavailable.',
        ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    echo is_string($json)
        ? $json
        : '{"success":false,"count":0,"barangays":[],"message":"Barangay information is temporarily unavailable."}';
    exit;
}
