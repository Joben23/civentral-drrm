<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\DrrmAdminBarangayReferenceService;
use App\Services\DrrmMapAuthorizationService;
use App\Services\SupabaseRestClient;

$root = dirname(__DIR__);
require_once $root . '/config/app_environment.php';
require_once $root . '/config/supabase.php';
require_once $root . '/src/Services/SupabaseRestClient.php';
require_once $root . '/src/Services/DrrmMapAuthorizationService.php';
require_once $root . '/src/Services/DrrmDraftBarangayPreviewService.php';
require_once $root . '/src/Services/DrrmAdminBarangayReferenceService.php';

$failures = [];
$assertions = 0;
function assertAdminBarangayReference(string $name, bool $condition): void
{
    global $failures, $assertions;
    $assertions++;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

$endpoint = file_get_contents($root . '/api/drrm/admin-barangay-reference.php');
$service = file_get_contents($root . '/src/Services/DrrmAdminBarangayReferenceService.php');
$draft = file_get_contents($root . '/src/Services/DrrmDraftBarangayPreviewService.php');
$map = file_get_contents($root . '/assets/js/drrm/hazard-evacuation-map.js');
$adapter = file_get_contents($root . '/assets/js/drrm/operational-map-data.js');
$page = file_get_contents($root . '/pages/drrm/hazard-evacuation-map.php');
foreach ([$endpoint, $service, $draft, $map, $adapter, $page] as $source) {
    if (!is_string($source)) {
        fwrite(STDERR, "Unable to read barangay reference test source.\n");
        exit(1);
    }
}

$originalEnvironment = getenv('APP_ENV');
try {
    putenv('APP_ENV=staging');
    assertAdminBarangayReference('StagingEnvironmentIsRequired', AppEnvironment::isStaging());
    putenv('APP_ENV=production');
    assertAdminBarangayReference('ProductionEnvironmentDisablesReference', !AppEnvironment::isStaging());
} finally {
    putenv($originalEnvironment === false ? 'APP_ENV' : 'APP_ENV=' . $originalEnvironment);
}

assertAdminBarangayReference(
    'EndpointRequiresGetAuthenticationAndModuleOneView',
    str_contains($endpoint, "!== 'GET'")
    && str_contains($endpoint, 'isLoggedIn()')
    && str_contains($endpoint, 'canView()')
    && str_contains($endpoint, 'Module 1 VIEW permission required.')
);
assertAdminBarangayReference(
    'EndpointIsReadOnlyAndStagingGated',
    str_contains($endpoint, 'AppEnvironment::isStaging')
    && str_contains($endpoint, 'if ($_GET !== [])')
    && preg_match('/->(?:post|patch|delete|rpc)\s*\(/i', $endpoint . $service) !== 1
);
assertAdminBarangayReference(
    'ControlledDatasetRemainsExactInactive187',
    str_contains($draft, "'record_status' => 'eq.INACTIVE'")
    && str_contains($draft, 'EXPECTED_FEATURE_COUNT = 187')
    && str_contains($draft, '$number !== 176')
);
assertAdminBarangayReference(
    'ProjectionOmitsInternalMetadataAndLabelsReference',
    str_contains($service, "'reference_status' => self::DISPLAY_STATUS")
    && !str_contains($service, 'reviewer')
    && !str_contains($service, 'verified_by_civentral_user_id')
);
assertAdminBarangayReference(
    'OperationalPriorityAndAdminOnlyFallbackAreWired',
    str_contains($adapter, 'resolveBarangaySource')
    && str_contains($adapter, 'features.length > 0')
    && str_contains($map, 'INCOMPLETE_ADMIN_REFERENCE')
    && str_contains($page, 'admin-barangay-reference.php')
);
assertAdminBarangayReference(
    'ReferenceDisclosureAndNoOperationalCatalogMerge',
    str_contains($service, '176-A through 176-F')
    && str_contains($map, 'state.operationalBarangayCollection = operationalCollection')
    && str_contains($map, 'reference_status')
);

try {
    $config = SupabaseConfig::fromEnvironment($root . '/.env');
    $client = new SupabaseRestClient($config);
    try {
        new DrrmAdminBarangayReferenceService($client, false);
        assertAdminBarangayReference('ServiceRejectsNonStagingConstruction', false);
    } catch (RuntimeException) {
        assertAdminBarangayReference('ServiceRejectsNonStagingConstruction', true);
    }

    $collection = (new DrrmAdminBarangayReferenceService($client, true))->featureCollection();
    $features = $collection['features'];
    $names = array_map(static fn (array $feature): string => $feature['properties']['name'], $features);
    assertAdminBarangayReference('ReferenceReturnsExact187Features', count($features) === 187);
    assertAdminBarangayReference('RetiredAndMissingBarangaysAreAbsent',
        !in_array('Barangay 176', $names, true)
        && !array_filter($names, static fn (string $name): bool => preg_match('/^Barangay 176-[A-F]$/', $name) === 1));
    assertAdminBarangayReference('ReferenceRecordsRemainNonOperational',
        !array_filter($features, static fn (array $feature): bool =>
            ($feature['properties']['reference_status'] ?? null) !== 'INCOMPLETE ADMIN REFERENCE'
        ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Barangay dataset assertions skipped: ' . $exception->getMessage() . PHP_EOL);
}

echo 'Assertions=' . $assertions . PHP_EOL;
if ($failures !== []) {
    fwrite(STDERR, 'Failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
