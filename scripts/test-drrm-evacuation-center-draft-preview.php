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
require_once __DIR__ . '/../src/Services/DrrmDraftEvacuationCenterPreviewService.php';

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\DrrmDraftEvacuationCenterPreviewService;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

function assertCenterPreview(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $originalEnvironment = getenv('APP_ENV');
    putenv('APP_ENV=development');
    assertCenterPreview(AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
    ]), 'Development loopback requests should be allowed.');
    assertCenterPreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_HOST' => 'localhost',
    ]), 'Non-loopback requests must be denied.');
    putenv('APP_ENV=production');
    assertCenterPreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
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
    $features = (new DrrmDraftEvacuationCenterPreviewService($client, true))->featureCollection();
    assertCenterPreview($features['type'] === 'FeatureCollection', 'The center preview is not a FeatureCollection.');
    assertCenterPreview(count($features['features']) === 15, 'The center preview did not return 15 features.');

    $ids = [];
    $names = [];
    $barangayHallBarangay = null;
    foreach ($features['features'] as $feature) {
        assertCenterPreview(is_array($feature), 'A center feature is invalid.');
        $geometry = $feature['geometry'] ?? null;
        $properties = $feature['properties'] ?? null;
        assertCenterPreview(is_array($geometry) && ($geometry['type'] ?? null) === 'Point', 'A center geometry is not a Point.');
        assertCenterPreview(is_array($geometry['coordinates'] ?? null) && count($geometry['coordinates']) === 2, 'A center coordinate is invalid.');
        assertCenterPreview(is_array($properties), 'A center property object is invalid.');
        assertCenterPreview(array_keys($properties) === [
            'evacuation_center_id', 'name', 'barangay_name', 'designation',
            'location_verification_status', 'display_status', 'source_agency',
        ], 'The center preview exposed unexpected properties.');
        assertCenterPreview($properties['designation'] === 'Evacuation Center', 'A designation changed.');
        assertCenterPreview($properties['location_verification_status'] === 'Location pending LGU verification', 'The location caveat changed.');
        assertCenterPreview($properties['display_status'] === 'Development Preview', 'The preview label changed.');
        assertCenterPreview($properties['source_agency'] === 'City Government of Caloocan / Caloocan PIO', 'The designation source changed.');
        assertCenterPreview(!preg_match('/^Barangay 176-[A-F]$/', $properties['barangay_name']), 'A 176 split center was exposed.');
        if ($properties['name'] === 'Barangay Hall') {
            $barangayHallBarangay = $properties['barangay_name'];
        }
        $ids[] = $properties['evacuation_center_id'];
        $names[] = $properties['name'];
    }
    assertCenterPreview(count(array_unique($ids)) === 15, 'Center UUIDs are not unique.');
    assertCenterPreview(count(array_unique($names)) === 15, 'Center names are not unique.');
    assertCenterPreview($barangayHallBarangay === 'Barangay 28', 'The ambiguous Barangay 29 hall candidate was exposed.');
    foreach ([
        'Gregoria De Jesus Elem. School',
        'Libis Baesa Elem. School',
        'Brgy. Evacuation Center',
        'San Lorenzo Court/Phase 4',
        'Bagong Silang High School',
        'Pag-Asa Elem. School/Phase 7',
        'Rene Cayetano Elem. School',
        'Kalayaan National High School',
        'Kalayaan Elementary School',
    ] as $excludedName) {
        assertCenterPreview(!in_array($excludedName, $names, true), $excludedName . ' must remain excluded.');
    }

    $rawCenters = $client->get('evacuation_centers', [
        'select' => 'evacuation_center_id,capacity,operational_status,publication_status,contact_phone,accessibility_notes,verified_by_civentral_user_id,verified_at',
        'order' => 'created_at.asc',
    ]);
    assertCenterPreview(count($rawCenters) === 15, 'The database does not contain exactly the intended 15 staged centers.');
    foreach ($rawCenters as $rawCenter) {
        assertCenterPreview(in_array($rawCenter['evacuation_center_id'] ?? null, $ids, true), 'An unrelated center exists in the staging set.');
        assertCenterPreview(($rawCenter['capacity'] ?? null) === 0, 'A center capacity is not the controlled unknown sentinel.');
        assertCenterPreview(($rawCenter['operational_status'] ?? null) === 'INACTIVE', 'A center is not INACTIVE.');
        assertCenterPreview(($rawCenter['publication_status'] ?? null) === 'DRAFT', 'A center is not DRAFT.');
        assertCenterPreview(($rawCenter['contact_phone'] ?? null) === null, 'A contact phone was fabricated.');
        assertCenterPreview(($rawCenter['accessibility_notes'] ?? null) === null, 'Accessibility information was fabricated.');
        assertCenterPreview(($rawCenter['verified_by_civentral_user_id'] ?? null) === null, 'Verifier metadata was fabricated.');
        assertCenterPreview(($rawCenter['verified_at'] ?? null) === null, 'Verification time was fabricated.');
    }
    assertCenterPreview((new DrrmMapReadService($client))->evacuationCenters() === [], 'The normal center service exposed the draft.');

    $encoded = json_encode($features, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    assertCenterPreview(!str_contains($encoded, $config->serverApiKey()), 'A credential appeared in the center preview.');
    assertCenterPreview(!str_contains($encoded, 'capacity'), 'Capacity leaked into the center preview.');
    assertCenterPreview(!str_contains($encoded, 'AVAILABLE'), 'An unsupported availability status appeared.');

    echo 'Draft evacuation-center preview service: OK' . PHP_EOL;
    echo 'features: 15' . PHP_EOL;
    echo 'geometry: Point GeoJSON' . PHP_EOL;
    echo 'normal_production_centers: 0' . PHP_EOL;
    echo 'local_development_guard: OK' . PHP_EOL;
    echo 'credentials_exposed: no' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Draft evacuation-center preview test: FAILED - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
