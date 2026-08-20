<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app_environment.php';
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../src/Services/DrrmMapReadService.php';
require_once __DIR__ . '/../src/Services/DrrmDraftFaultPreviewService.php';

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\DrrmDraftFaultPreviewService;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

function assertFaultPreview(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $originalEnvironment = getenv('APP_ENV');
    putenv('APP_ENV=development');
    assertFaultPreview(AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
    ]), 'Development loopback requests should be allowed.');
    assertFaultPreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_HOST' => 'localhost',
    ]), 'Non-loopback requests must be denied.');
    putenv('APP_ENV=production');
    assertFaultPreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
    ]), 'Non-development environments must be denied.');
    if ($originalEnvironment === false) {
        putenv('APP_ENV');
    } else {
        putenv('APP_ENV=' . $originalEnvironment);
    }

    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
    $client = new SupabaseRestClient($config, 5, 120);
    $preview = (new DrrmDraftFaultPreviewService($client, true))->preview();
    $summary = $preview['summary'] ?? null;
    $faults = $preview['faults'] ?? null;
    assertFaultPreview(is_array($summary), 'The fault preview summary is invalid.');
    assertFaultPreview($summary === [
        'crosses_caloocan' => false,
        'nearest_fault_name' => 'West Valley Fault',
        'minimum_city_distance_km' => 3.76,
        'source_agency' => 'DOST-PHIVOLCS',
        'display_mode' => 'INFORMATION_ONLY',
        'advisory' => 'No mapped active fault intersects Caloocan City based on the current PHIVOLCS dataset.',
    ], 'The fault preview summary changed.');
    assertFaultPreview(is_array($faults) && ($faults['type'] ?? null) === 'FeatureCollection', 'The fault geometry is not a FeatureCollection.');
    assertFaultPreview(count($faults['features'] ?? []) === 156, 'The fault preview did not return 156 features.');

    foreach ($faults['features'] as $feature) {
        $geometry = $feature['geometry'] ?? null;
        $properties = $feature['properties'] ?? null;
        assertFaultPreview(is_array($geometry) && ($geometry['type'] ?? null) === 'MultiLineString', 'A fault preview geometry is not MultiLineString.');
        assertFaultPreview(is_array($geometry['coordinates'] ?? null) && $geometry['coordinates'] !== [], 'A fault preview geometry is empty.');
        assertFaultPreview(is_array($properties) && array_keys($properties) === [
            'fault_name', 'feature_class', 'source_agency', 'crosses_caloocan', 'location_context',
        ], 'The fault preview exposed unexpected properties.');
        assertFaultPreview($properties['fault_name'] === 'West Valley Fault', 'A non-West Valley Fault feature was exposed.');
        assertFaultPreview($properties['feature_class'] === 'Active Fault', 'A feature classification changed.');
        assertFaultPreview($properties['source_agency'] === 'DOST-PHIVOLCS', 'The source agency changed.');
        assertFaultPreview($properties['crosses_caloocan'] === false, 'A feature falsely indicates a Caloocan crossing.');
    }

    assertFaultPreview((new DrrmMapReadService($client))->faultFeatures() === [], 'The normal ACTIVE-only fault service exposed the draft.');
    $encoded = json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    assertFaultPreview(!str_contains($encoded, $config->serverApiKey()), 'A credential appeared in the fault preview.');
    assertFaultPreview(!str_contains($encoded, DrrmDraftFaultPreviewService::DATASET_VERSION_ID), 'The internal dataset UUID appeared in the fault preview.');
    assertFaultPreview(!str_contains($encoded, 'risk_level'), 'A fabricated risk classification appeared in the fault preview.');

    echo 'Draft fault preview service: OK' . PHP_EOL;
    echo 'features: 156' . PHP_EOL;
    echo 'geometry: MultiLineString GeoJSON' . PHP_EOL;
    echo 'crosses_caloocan: no' . PHP_EOL;
    echo 'nearest_fault: West Valley Fault' . PHP_EOL;
    echo 'minimum_city_distance_km: 3.76' . PHP_EOL;
    echo 'display_mode: INFORMATION_ONLY' . PHP_EOL;
    echo 'normal_active_fault_features: 0' . PHP_EOL;
    echo 'local_development_guard: OK' . PHP_EOL;
    echo 'credentials_exposed: no' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Draft fault preview test: FAILED - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
