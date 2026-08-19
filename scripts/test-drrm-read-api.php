<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../src/Services/DrrmMapReadService.php';

use App\Config\SupabaseConfig;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

/** @param array<mixed> $records */
function assertRecordCount(array $records, int $expectedCount, string $resource): void
{
    if (count($records) !== $expectedCount) {
        throw new RuntimeException($resource . ' returned an unexpected record count.');
    }
}

try {
    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
    $service = new DrrmMapReadService(new SupabaseRestClient($config));
    $lookups = $service->lookups();

    $spatialResources = [
        'barangays' => $service->barangays(),
        'hazard_zones' => $service->hazardZones(),
        'fault_features' => $service->faultFeatures(),
        'evacuation_centers' => $service->evacuationCenters(),
        'evacuation_routes' => $service->evacuationRoutes(),
    ];

    assertRecordCount($lookups['hazard_types'], 3, 'hazard_types');
    assertRecordCount($lookups['risk_levels'], 4, 'risk_levels');

    foreach ($spatialResources as $resource => $records) {
        assertRecordCount($records, 0, $resource);
    }

    $invalidSearchRejected = false;

    try {
        $service->barangays('*');
    } catch (InvalidArgumentException) {
        $invalidSearchRejected = true;
    }

    if (!$invalidSearchRejected) {
        throw new RuntimeException('Invalid barangay search input was not rejected.');
    }

    echo "DRRM read service: OK\n";
    echo 'hazard_types: ' . count($lookups['hazard_types']) . " records\n";
    echo 'risk_levels: ' . count($lookups['risk_levels']) . " records\n";

    foreach ($spatialResources as $resource => $records) {
        echo $resource . ': ' . count($records) . " records\n";
    }

    echo "invalid barangay search: rejected\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "DRRM read service: FAILED\n");
    fwrite(STDERR, 'Reason: ' . $exception->getMessage() . "\n");
    exit(1);
}
