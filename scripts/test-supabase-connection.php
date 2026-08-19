<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';

use App\Config\SupabaseConfig;
use App\Services\SupabaseRestClient;

/**
 * @param array<mixed> $actualRecords
 * @param list<array<string, int|string>> $expectedRecords
 */
function assertExpectedRecords(array $actualRecords, array $expectedRecords, string $resourceName): void
{
    if ($actualRecords !== $expectedRecords) {
        throw new RuntimeException($resourceName . ' did not contain the expected controlled lookup records.');
    }
}

try {
    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
    $client = new SupabaseRestClient($config);

    $hazardTypes = $client->get('hazard_types', [
        'select' => 'hazard_type_id,code,name',
        'order' => 'hazard_type_id.asc',
    ]);

    $riskLevels = $client->get('risk_levels', [
        'select' => 'risk_level_id,code,name,severity_rank',
        'order' => 'risk_level_id.asc',
    ]);

    assertExpectedRecords($hazardTypes, [
        ['hazard_type_id' => 1, 'code' => 'FLOOD', 'name' => 'Flood'],
        ['hazard_type_id' => 2, 'code' => 'LANDSLIDE', 'name' => 'Landslide'],
        ['hazard_type_id' => 3, 'code' => 'EARTHQUAKE_FAULT', 'name' => 'Earthquake/Fault'],
    ], 'hazard_types');

    assertExpectedRecords($riskLevels, [
        ['risk_level_id' => 1, 'code' => 'LOW', 'name' => 'Low', 'severity_rank' => 1],
        ['risk_level_id' => 2, 'code' => 'MODERATE', 'name' => 'Moderate', 'severity_rank' => 2],
        ['risk_level_id' => 3, 'code' => 'HIGH', 'name' => 'High', 'severity_rank' => 3],
        ['risk_level_id' => 4, 'code' => 'CRITICAL', 'name' => 'Critical', 'severity_rank' => 4],
    ], 'risk_levels');

    echo "Supabase connection: OK\n";
    echo 'hazard_types: ' . count($hazardTypes) . " records\n";
    echo 'risk_levels: ' . count($riskLevels) . " records\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Supabase connection: FAILED\n");
    fwrite(STDERR, 'Reason: ' . $exception->getMessage() . "\n");
    exit(1);
}
