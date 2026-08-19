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
require_once __DIR__ . '/../src/Services/DrrmDraftFloodPreviewService.php';

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\DrrmDraftFloodPreviewService;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

function assertFloodPreview(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $originalEnvironment = getenv('APP_ENV');

    putenv('APP_ENV=development');
    assertFloodPreview(AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
    ]), 'Development loopback requests should be allowed.');
    assertFloodPreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_HOST' => 'localhost',
    ]), 'Non-loopback requests must be denied.');

    putenv('APP_ENV=production');
    assertFloodPreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
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
    $featureCollection = (new DrrmDraftFloodPreviewService($client, true))->featureCollection();

    assertFloodPreview($featureCollection['type'] === 'FeatureCollection', 'The flood preview is not a FeatureCollection.');
    assertFloodPreview(count($featureCollection['features']) === 15, 'The flood preview did not return 15 features.');

    $counts = ['LF' => 0, 'MF' => 0, 'HF' => 0, 'VHF' => 0];
    $expectedLabels = [
        'LF' => ['Low Susceptibility to Flooding', 'Low'],
        'MF' => ['Moderate Susceptibility to Flooding', 'Moderate'],
        'HF' => ['High Susceptibility to Flooding', 'High'],
        'VHF' => ['Very High Susceptibility to Flooding', 'Very High'],
    ];

    foreach ($featureCollection['features'] as $feature) {
        assertFloodPreview(is_array($feature), 'A flood preview feature is invalid.');
        $geometry = $feature['geometry'] ?? null;
        $properties = $feature['properties'] ?? null;
        assertFloodPreview(is_array($geometry) && ($geometry['type'] ?? null) === 'MultiPolygon', 'A flood preview geometry is not MultiPolygon.');
        assertFloodPreview(is_array($geometry['coordinates'] ?? null) && $geometry['coordinates'] !== [], 'A flood preview geometry is empty.');
        assertFloodPreview(is_array($properties), 'A flood preview property object is invalid.');
        assertFloodPreview(array_keys($properties) === [
            'hazard', 'mgb_code', 'mgb_label', 'display_risk_label', 'source_agency',
        ], 'The flood preview exposed unexpected properties.');

        $code = (string) ($properties['mgb_code'] ?? '');
        assertFloodPreview(isset($counts[$code]), 'The flood preview returned an unknown MGB code.');
        assertFloodPreview(($properties['hazard'] ?? null) === 'Flood', 'The flood preview hazard label changed.');
        assertFloodPreview(($properties['source_agency'] ?? null) === 'DENR-MGB', 'The flood preview source agency changed.');
        assertFloodPreview(($properties['mgb_label'] ?? null) === $expectedLabels[$code][0], 'The MGB classification label changed.');
        assertFloodPreview(($properties['display_risk_label'] ?? null) === $expectedLabels[$code][1], 'The display risk label changed.');
        $counts[$code]++;
    }

    assertFloodPreview($counts === ['LF' => 5, 'MF' => 3, 'HF' => 4, 'VHF' => 3], 'Flood preview class counts changed.');
    assertFloodPreview((new DrrmMapReadService($client))->hazardZones() === [], 'The normal ACTIVE-only hazard service exposed the draft.');

    $encoded = json_encode($featureCollection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    assertFloodPreview(!str_contains($encoded, $config->serverApiKey()), 'A credential appeared in the flood preview.');
    assertFloodPreview(!str_contains($encoded, DrrmDraftFloodPreviewService::DATASET_VERSION_ID), 'The internal dataset UUID appeared in the flood preview.');
    assertFloodPreview(!str_contains($encoded, 'CRITICAL'), 'The internal CRITICAL code was exposed as MGB terminology.');

    echo 'Draft flood preview service: OK' . PHP_EOL;
    echo 'features: 15' . PHP_EOL;
    echo 'class_counts: LF=5, MF=3, HF=4, VHF=3' . PHP_EOL;
    echo 'geometry: MultiPolygon GeoJSON' . PHP_EOL;
    echo 'normal_active_hazard_zones: 0' . PHP_EOL;
    echo 'local_development_guard: OK' . PHP_EOL;
    echo 'credentials_exposed: no' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Draft flood preview test: FAILED - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
