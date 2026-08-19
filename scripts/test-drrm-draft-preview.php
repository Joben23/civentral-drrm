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
require_once __DIR__ . '/../src/Services/DrrmDraftBarangayPreviewService.php';

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\DrrmDraftBarangayPreviewService;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

const SOURCE_GEOJSON = __DIR__ . '/../data/import/caloocan-barangays-current-unaffected.geojson';

function assertPreview(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function coordinateArraysEqual(mixed $left, mixed $right): bool
{
    if (is_array($left) || is_array($right)) {
        if (!is_array($left) || !is_array($right) || array_keys($left) !== array_keys($right)) {
            return false;
        }

        foreach ($left as $key => $value) {
            if (!coordinateArraysEqual($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }

    return is_numeric($left) && is_numeric($right) && (float) $left === (float) $right;
}

/** @return array<string, array<mixed>> */
function sourceCoordinatesByCode(): array
{
    $contents = file_get_contents(SOURCE_GEOJSON);
    assertPreview($contents !== false, 'Unable to read the prepared source GeoJSON.');

    try {
        $source = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('The prepared source GeoJSON is invalid.');
    }

    assertPreview(
        is_array($source) && ($source['type'] ?? null) === 'FeatureCollection' && is_array($source['features'] ?? null),
        'The prepared source is not a GeoJSON FeatureCollection.'
    );
    assertPreview(count($source['features']) === DrrmDraftBarangayPreviewService::EXPECTED_FEATURE_COUNT, 'The prepared source does not contain 187 features.');

    $coordinatesByCode = [];
    foreach ($source['features'] as $feature) {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
        $code = (string) ($properties['current_psgc_10_digit'] ?? '');
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        assertPreview(preg_match('/^\d{10}$/', $code) === 1, 'A prepared feature is missing its current PSGC code.');
        assertPreview(!isset($coordinatesByCode[$code]), 'The prepared source contains a duplicate PSGC code.');
        assertPreview(in_array($type, ['Polygon', 'MultiPolygon'], true) && is_array($coordinates), 'A prepared feature has invalid geometry.');

        $coordinatesByCode[$code] = $type === 'Polygon' ? [$coordinates] : $coordinates;
    }

    return $coordinatesByCode;
}

try {
    $originalEnvironment = getenv('APP_ENV');

    putenv('APP_ENV=development');
    assertPreview(AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
    ]), 'Development loopback requests should be allowed.');
    assertPreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_HOST' => 'localhost',
    ]), 'Non-loopback requests must be denied.');

    putenv('APP_ENV=production');
    assertPreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
    ]), 'Non-development environments must be denied.');

    if ($originalEnvironment === false) {
        putenv('APP_ENV');
    } else {
        putenv('APP_ENV=' . $originalEnvironment);
    }

    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
    $client = new SupabaseRestClient($config);
    $previewService = new DrrmDraftBarangayPreviewService($client, true);
    $featureCollection = $previewService->featureCollection();
    $sourceCoordinates = sourceCoordinatesByCode();

    assertPreview($featureCollection['type'] === 'FeatureCollection', 'The preview response is not a FeatureCollection.');
    assertPreview(count($featureCollection['features']) === DrrmDraftBarangayPreviewService::EXPECTED_FEATURE_COUNT, 'The preview did not return 187 features.');

    $codes = [];
    $names = [];
    foreach ($featureCollection['features'] as $feature) {
        $properties = $feature['properties'];
        $geometry = $feature['geometry'];
        $code = $properties['barangay_code'];
        $name = $properties['name'];
        $allowedProperties = ['barangay_id', 'barangay_code', 'name', 'district_code', 'preview_status'];

        assertPreview(array_diff(array_keys($properties), $allowedProperties) === [], 'The preview exposed an unexpected property.');
        assertPreview($properties['preview_status'] === 'DRAFT_INCOMPLETE', 'A feature is missing the preview status.');
        assertPreview($geometry['type'] === 'MultiPolygon', 'A stored draft geometry is not MultiPolygon.');
        assertPreview(isset($sourceCoordinates[$code]), 'The preview returned an unexpected PSGC code.');
        assertPreview(coordinateArraysEqual($sourceCoordinates[$code], $geometry['coordinates']), 'A preview geometry differs from the prepared source coordinates.');
        assertPreview($name !== 'Barangay 176' && !preg_match('/^Barangay 176-[A-F]$/', $name), 'The preview exposed an excluded Barangay 176 record.');

        $codes[] = $code;
        $names[] = $name;
    }

    assertPreview(count(array_unique($codes)) === 187, 'Preview PSGC codes are not unique.');
    assertPreview(count(array_unique($names)) === 187, 'Preview names are not unique.');

    $rawGeometryRows = $client->get('barangays', [
        'select' => 'boundary_geometry',
        'boundary_dataset_version_id' => 'eq.' . DrrmDraftBarangayPreviewService::DATASET_VERSION_ID,
        'record_status' => 'eq.INACTIVE',
        'limit' => 1,
    ]);
    $rawGeometry = $rawGeometryRows[0]['boundary_geometry'] ?? null;
    assertPreview(is_array($rawGeometry) && ($rawGeometry['type'] ?? null) === 'MultiPolygon', 'PostgREST geometry serialization is not the expected decoded GeoJSON object.');

    $normalBarangays = (new DrrmMapReadService($client))->barangays();
    assertPreview($normalBarangays === [], 'The normal ACTIVE-only barangay service exposed draft records.');

    $encoded = json_encode($featureCollection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    assertPreview(!str_contains($encoded, $config->serverApiKey()), 'A credential appeared in the preview response.');
    assertPreview(!str_contains($encoded, DrrmDraftBarangayPreviewService::DATASET_VERSION_ID), 'The internal dataset version appeared in the preview response.');

    echo 'Draft preview service: OK' . PHP_EOL;
    echo 'features: 187' . PHP_EOL;
    echo 'unique_codes: 187' . PHP_EOL;
    echo 'unique_names: 187' . PHP_EOL;
    echo 'geometry: MultiPolygon GeoJSON' . PHP_EOL;
    echo 'postgrest_geometry_php_type: array' . PHP_EOL;
    echo 'coordinates_unchanged: yes' . PHP_EOL;
    echo 'normal_active_barangays: 0' . PHP_EOL;
    echo 'local_development_guard: OK' . PHP_EOL;
    echo 'credentials_exposed: no' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Draft preview test: FAILED - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
