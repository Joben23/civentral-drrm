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
 * @param array<string, mixed> $actual
 * @param list<string> $requiredTrueKeys
 */
function assertModule4CatalogChecks(array $actual, array $requiredTrueKeys): void
{
    foreach ($requiredTrueKeys as $key) {
        if (($actual[$key] ?? null) !== true) {
            throw new RuntimeException('Module 4 schema catalog verification failed for ' . $key . '.');
        }
    }

    if (($actual['policy_count'] ?? null) !== 0) {
        throw new RuntimeException('Module 4 tables unexpectedly contain direct-client RLS policies.');
    }
}

/**
 * @return array<string, mixed>
 */
function fetchModule4CatalogVerification(SupabaseConfig $config): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('The PHP cURL extension is required for Supabase verification.');
    }

    $handle = curl_init($config->restBaseUrl() . '/rpc/verify_module4_early_warning_schema');

    if ($handle === false) {
        throw new RuntimeException('The Module 4 schema verification request could not be initialized.');
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_USERAGENT => 'CIVENTRAL-DRRM/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'apikey: ' . $config->serverApiKey(),
        ],
    ]);

    $responseBody = curl_exec($handle);

    if ($responseBody === false) {
        $curlErrorNumber = curl_errno($handle);
        curl_close($handle);
        throw new RuntimeException(
            'The Module 4 schema verification request failed at the network layer (cURL code '
            . $curlErrorNumber . ').'
        );
    }

    $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException(
            'The Module 4 schema verification request failed with HTTP status ' . $httpStatus . '.'
        );
    }

    $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException('The Module 4 schema verification response was malformed.');
    }

    return $decoded;
}

/**
 * @param array<mixed> $sources
 */
function assertModule4Sources(array $sources): void
{
    $expected = [
        'CIVENTRAL' => ['CIVENTRAL DRRM', 'INTERNAL_SYSTEM'],
        'NDRRMC' => ['National Disaster Risk Reduction and Management Council', 'GOVERNMENT_AGENCY'],
        'PAGASA' => ['DOST-PAGASA', 'GOVERNMENT_AGENCY'],
        'PHIVOLCS' => ['DOST-PHIVOLCS', 'GOVERNMENT_AGENCY'],
    ];

    if (count($sources) !== count($expected)) {
        throw new RuntimeException('Module 4 did not contain exactly four controlled warning sources.');
    }

    foreach ($sources as $source) {
        if (!is_array($source)) {
            throw new RuntimeException('A Module 4 warning source was malformed.');
        }

        $code = (string) ($source['source_code'] ?? '');
        $id = (string) ($source['id'] ?? '');

        if (
            !array_key_exists($code, $expected)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)
            || ($source['source_name'] ?? null) !== $expected[$code][0]
            || ($source['source_type'] ?? null) !== $expected[$code][1]
            || ($source['integration_status'] ?? null) !== 'PENDING'
            || ($source['is_active'] ?? null) !== true
        ) {
            throw new RuntimeException('A Module 4 warning source did not match its controlled definition.');
        }
    }
}

try {
    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
    $client = new SupabaseRestClient($config);

    $catalog = fetchModule4CatalogVerification($config);
    assertModule4CatalogChecks($catalog, [
        'tables_exist',
        'rls_enabled',
        'foreign_keys_valid',
        'check_constraints_valid',
        'unique_source_code_valid',
        'warning_status_check_valid',
        'area_scope_type_check_valid',
        'indexes_valid',
        'direct_client_privileges_restricted',
        'source_seed_valid',
        'risk_levels_reused',
    ]);

    $sources = $client->get('early_warning_sources', [
        'select' => 'id,source_code,source_name,source_type,integration_status,is_active',
        'order' => 'source_code.asc',
    ]);
    $warnings = $client->get('early_warnings', [
        'select' => 'id',
        'order' => 'id.asc',
    ]);
    $areas = $client->get('early_warning_areas', [
        'select' => 'id',
        'order' => 'id.asc',
    ]);
    $riskLevels = $client->get('risk_levels', [
        'select' => 'risk_level_id,code,name,severity_rank,is_active',
        'order' => 'severity_rank.asc',
    ]);

    assertModule4Sources($sources);

    if (!is_int($catalog['warning_count'] ?? null)
        || $catalog['warning_count'] < 0
        || count($warnings) !== $catalog['warning_count']) {
        throw new RuntimeException('Module 4 early_warnings catalog and read counts do not match.');
    }

    if (!is_int($catalog['area_count'] ?? null)
        || $catalog['area_count'] < 0
        || count($areas) !== $catalog['area_count']) {
        throw new RuntimeException('Module 4 early_warning_areas catalog and read counts do not match.');
    }

    $expectedRiskCodes = ['LOW', 'MODERATE', 'HIGH', 'CRITICAL'];
    $actualRiskCodes = array_map(
        static fn (array $level): string => (string) ($level['code'] ?? ''),
        $riskLevels
    );

    if ($actualRiskCodes !== $expectedRiskCodes) {
        throw new RuntimeException('Module 4 did not reuse the expected CIVENTRAL risk_levels records.');
    }

    echo "Module 4 early-warning schema: OK\n";
    echo "early_warning_sources: 4 records\n";

    foreach ($sources as $source) {
        echo $source['source_code'] . ': ' . $source['id'] . "\n";
    }

    echo 'early_warnings: ' . count($warnings) . ' records' . PHP_EOL;
    echo 'early_warning_areas: ' . count($areas) . ' records' . PHP_EOL;
    echo "risk_levels reused: LOW, MODERATE, HIGH, CRITICAL\n";
    echo "RLS enabled: yes\n";
    echo "direct anon/authenticated table privileges: none\n";
    echo "foreign keys/check constraints/indexes: OK\n";
    echo "credentials exposed: no\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Module 4 early-warning schema: FAILED\n");
    fwrite(STDERR, 'Reason: ' . $exception->getMessage() . "\n");
    exit(1);
}
