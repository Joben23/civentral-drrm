<?php

declare(strict_types=1);

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\DrrmCaloocanBoundaryService;
use App\Services\DrrmCitizenFloodReferenceCheckService;
use App\Services\DrrmDraftFloodPreviewService;
use App\Services\DrrmFloodReferenceEvaluatorService;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../../../config/app_environment.php';
ini_set('display_errors', '0');

$envFile = __DIR__ . '/../../../.env';
$publicPreviewAllowed = AppEnvironment::isStaging($envFile)
    && AppEnvironment::isPublicDrrmPreviewEnabled($envFile);
if (!$publicPreviewAllowed) {
    citizenFloodReferenceRespond(false, 'Not found.', 404);
}

require_once __DIR__ . '/../../../config/supabase.php';
require_once __DIR__ . '/../../../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../../../src/Services/DrrmCaloocanBoundaryService.php';
require_once __DIR__ . '/../../../src/Services/DrrmDraftFloodPreviewService.php';
require_once __DIR__ . '/../../../src/Services/DrrmFloodReferenceEvaluatorService.php';
require_once __DIR__ . '/../../../src/Services/DrrmCitizenFloodReferenceCheckService.php';

citizenFloodReferenceSendHeaders();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    citizenFloodReferenceRespond(false, 'Method not allowed.', 405);
}
if ($_GET !== []) {
    citizenFloodReferenceRespond(false, 'Invalid flood reference check request.', 400);
}

$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (preg_match('/^application\/json(?:\s*;.*)?$/', $contentType) !== 1) {
    citizenFloodReferenceRespond(false, 'JSON request required.', 415);
}

$bodyStream = PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input';
$rawBody = file_get_contents($bodyStream, false, null, 0, 1025);
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 1024) {
    citizenFloodReferenceRespond(false, 'Invalid flood reference check request.', 400);
}

try {
    $input = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    citizenFloodReferenceRespond(false, 'Invalid flood reference check request.', 400);
}

$isJsonNumber = static fn (mixed $value): bool => is_int($value) || is_float($value);
if (!is_array($input)
    || count($input) !== 2
    || array_diff(array_keys($input), ['latitude', 'longitude']) !== []
    || !$isJsonNumber($input['latitude'] ?? null)
    || !$isJsonNumber($input['longitude'] ?? null)) {
    citizenFloodReferenceRespond(false, 'Invalid flood reference check request.', 400);
}

$latitude = (float) $input['latitude'];
$longitude = (float) $input['longitude'];
if (!is_finite($latitude) || !is_finite($longitude)
    || $latitude < -90 || $latitude > 90
    || $longitude < -180 || $longitude > 180) {
    citizenFloodReferenceRespond(false, 'Please select a location inside Caloocan City.', 422);
}

try {
    $floodReference = new DrrmDraftFloodPreviewService(
        new SupabaseRestClient(SupabaseConfig::fromEnvironment($envFile)),
        $publicPreviewAllowed
    );
    $evaluator = new DrrmFloodReferenceEvaluatorService(
        static fn (): array => $floodReference->featureCollection(),
        new DrrmCaloocanBoundaryService(
            __DIR__ . '/../../../data/import/caloocan-city-boundary.geojson'
        )
    );
    $service = new DrrmCitizenFloodReferenceCheckService($evaluator, $publicPreviewAllowed);
    citizenFloodReferenceRespond(true, null, 200, $service->check($latitude, $longitude));
} catch (InvalidArgumentException) {
    citizenFloodReferenceRespond(false, 'Please select a location inside Caloocan City.', 422);
} catch (Throwable) {
    citizenFloodReferenceRespond(false, 'Flood reference information is temporarily unavailable.', 503);
}

function citizenFloodReferenceSendHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Allow: POST, OPTIONS');
    header('X-Robots-Tag: noindex, nofollow');
}

/** @param array<string, mixed>|null $data */
function citizenFloodReferenceRespond(
    bool $success,
    ?string $message = null,
    int $statusCode = 200,
    ?array $data = null
): never {
    citizenFloodReferenceSendHeaders();
    http_response_code($statusCode);
    $payload = $success ? ['success' => true] + ($data ?? []) : [
        'success' => false,
        'message' => $message ?? 'Flood reference information is temporarily unavailable.',
    ];
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    echo $json === false
        ? (string) json_encode(['success' => false, 'message' => 'Flood reference information is temporarily unavailable.'])
        : $json;
    exit;
}
