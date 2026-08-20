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
require_once __DIR__ . '/../src/Services/DrrmDraftLandslidePreviewService.php';

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\DrrmDraftLandslidePreviewService;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

function assertLandslidePreview(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $originalEnvironment = getenv('APP_ENV');
    putenv('APP_ENV=development');
    assertLandslidePreview(AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost',
    ]), 'Development loopback requests should be allowed.');
    assertLandslidePreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '203.0.113.10', 'HTTP_HOST' => 'localhost',
    ]), 'Non-loopback requests must be denied.');
    putenv('APP_ENV=production');
    assertLandslidePreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost',
    ]), 'Non-development environments must be denied.');
    $originalEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $originalEnvironment);

    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
    $client = new SupabaseRestClient($config, 5, 120);
    $featureCollection = (new DrrmDraftLandslidePreviewService($client, true))->featureCollection();
    assertLandslidePreview($featureCollection['type'] === 'FeatureCollection', 'The landslide preview is not a FeatureCollection.');
    assertLandslidePreview(count($featureCollection['features']) === 13, 'The landslide preview did not return 13 features.');

    $counts = ['LL' => 0, 'ML' => 0, 'HL' => 0, 'VHL' => 0];
    $labels = [
        'LL' => ['Low Susceptibility to Landslide', 'Low'],
        'ML' => ['Moderate Susceptibility to Landslide', 'Moderate'],
        'HL' => ['High Susceptibility to Landslide', 'High'],
        'VHL' => ['Very High Susceptibility to Landslide', 'Very High'],
    ];
    foreach ($featureCollection['features'] as $feature) {
        $geometry = $feature['geometry'] ?? null;
        $properties = $feature['properties'] ?? null;
        assertLandslidePreview(is_array($geometry) && ($geometry['type'] ?? null) === 'MultiPolygon', 'A landslide geometry is not MultiPolygon.');
        assertLandslidePreview(is_array($geometry['coordinates'] ?? null) && $geometry['coordinates'] !== [], 'A landslide geometry is empty.');
        assertLandslidePreview(is_array($properties) && array_keys($properties) === [
            'hazard', 'mgb_code', 'mgb_label', 'display_risk_label', 'source_agency',
        ], 'The landslide preview exposed unexpected properties.');
        $code = (string) ($properties['mgb_code'] ?? '');
        assertLandslidePreview(isset($counts[$code]), 'The landslide preview returned an unknown MGB code.');
        assertLandslidePreview(($properties['hazard'] ?? null) === 'Landslide', 'The landslide hazard label changed.');
        assertLandslidePreview(($properties['source_agency'] ?? null) === 'DENR-MGB', 'The landslide source agency changed.');
        assertLandslidePreview(($properties['mgb_label'] ?? null) === $labels[$code][0], 'The landslide MGB label changed.');
        assertLandslidePreview(($properties['display_risk_label'] ?? null) === $labels[$code][1], 'The landslide display label changed.');
        $counts[$code]++;
    }
    assertLandslidePreview($counts === ['LL' => 7, 'ML' => 2, 'HL' => 2, 'VHL' => 2], 'Landslide class counts changed.');
    assertLandslidePreview((new DrrmMapReadService($client))->hazardZones() === [], 'The normal ACTIVE-only hazard service exposed draft data.');

    $encoded = json_encode($featureCollection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    assertLandslidePreview(!str_contains($encoded, $config->serverApiKey()), 'A credential appeared in the landslide preview.');
    assertLandslidePreview(!str_contains($encoded, DrrmDraftLandslidePreviewService::DATASET_VERSION_ID), 'The internal dataset UUID appeared in the landslide preview.');
    assertLandslidePreview(!str_contains($encoded, 'CRITICAL'), 'The internal CRITICAL code was exposed as MGB terminology.');

    echo 'Draft landslide preview service: OK' . PHP_EOL;
    echo 'features: 13' . PHP_EOL;
    echo 'class_counts: LL=7, ML=2, HL=2, VHL=2' . PHP_EOL;
    echo 'geometry: MultiPolygon GeoJSON' . PHP_EOL;
    echo 'normal_active_hazard_zones: 0' . PHP_EOL;
    echo 'local_development_guard: OK' . PHP_EOL;
    echo 'credentials_exposed: no' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Draft landslide preview test: FAILED - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
