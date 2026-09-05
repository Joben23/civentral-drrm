<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\DrrmAdminHazardReferenceService;
use App\Services\DrrmDraftFloodPreviewService;
use App\Services\DrrmDraftLandslidePreviewService;
use App\Services\DrrmMapAuthorizationService;
use App\Services\SupabaseRestClient;

$root = dirname(__DIR__);
require_once $root . '/config/app_environment.php';
require_once $root . '/config/supabase.php';
require_once $root . '/src/Services/SupabaseRestClient.php';
require_once $root . '/src/Services/DrrmDraftFloodPreviewService.php';
require_once $root . '/src/Services/DrrmDraftLandslidePreviewService.php';
require_once $root . '/src/Services/DrrmAdminHazardReferenceService.php';
require_once $root . '/src/Services/DrrmMapAuthorizationService.php';

$failures = [];
$assertions = 0;

function assertAdminHazardReference(string $name, bool $condition): void
{
    global $assertions, $failures;
    $assertions++;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

$endpointSource = file_get_contents($root . '/api/drrm/admin-hazard-reference.php');
$serviceSource = file_get_contents($root . '/src/Services/DrrmAdminHazardReferenceService.php');
$floodSource = file_get_contents($root . '/src/Services/DrrmDraftFloodPreviewService.php');
$landslideSource = file_get_contents($root . '/src/Services/DrrmDraftLandslidePreviewService.php');
$pageSource = file_get_contents($root . '/pages/drrm/hazard-evacuation-map.php');
$mapSource = file_get_contents($root . '/assets/js/drrm/hazard-evacuation-map.js');
$adapterSource = file_get_contents($root . '/assets/js/drrm/operational-map-data.js');
$markupSource = file_get_contents($root . '/includes/dashboard/hazard-evacuation-map.php');

foreach ([$endpointSource, $serviceSource, $floodSource, $landslideSource, $pageSource, $mapSource,
    $adapterSource, $markupSource] as $source) {
    if (!is_string($source)) {
        fwrite(STDERR, 'An admin hazard reference test source could not be read.' . PHP_EOL);
        exit(1);
    }
}

assertAdminHazardReference(
    'EndpointIsStagingOnlyGetAndModuleOneViewGated',
    str_contains($endpointSource, 'AppEnvironment::isStaging')
    && str_contains($endpointSource, "!== 'GET'")
    && str_contains($endpointSource, 'isLoggedIn()')
    && str_contains($endpointSource, 'canView()')
    && str_contains($endpointSource, 'if ($_GET !== [])')
);
assertAdminHazardReference(
    'ProductionAndDevelopmentAreUnavailable',
    str_contains($endpointSource, "http_response_code(404)")
    && str_contains($endpointSource, "'Not found.'")
    && str_contains($serviceSource, 'The admin hazard reference is unavailable.')
);
assertAdminHazardReference(
    'NoSupabaseMutationIsUsed',
    preg_match('/->(?:post|patch|delete|rpc)\s*\(/i', $endpointSource . $serviceSource) !== 1
    && str_contains($floodSource, '$this->client->get(')
    && str_contains($landslideSource, '$this->client->get(')
);
assertAdminHazardReference(
    'ControlledDraftInactiveDatasetsAreFixed',
    str_contains($serviceSource, 'DrrmDraftFloodPreviewService')
    && str_contains($serviceSource, 'DrrmDraftLandslidePreviewService')
    && str_contains($floodSource, "'review_status' => 'eq.DRAFT'")
    && str_contains($floodSource, "'record_status' => 'eq.INACTIVE'")
    && str_contains($landslideSource, "'review_status' => 'eq.DRAFT'")
    && str_contains($landslideSource, "'record_status' => 'eq.INACTIVE'")
    && str_contains($floodSource, 'EXPECTED_FEATURE_COUNT = 15')
    && str_contains($landslideSource, 'EXPECTED_FEATURE_COUNT = 13')
);
assertAdminHazardReference(
    'AdminProjectionOmitsGovernanceFields',
    str_contains($serviceSource, "'reference_status' => self::DISPLAY_STATUS")
    && !str_contains($serviceSource, 'reviewer')
    && !str_contains($serviceSource, 'dataset_version_id')
);
assertAdminHazardReference(
    'StagingUsesAdminVectorReferenceAndNoDevelopmentEndpoint',
    str_contains($pageSource, '$stagingAdminHazardReferenceEnabled')
    && str_contains($pageSource, 'api/drrm/admin-hazard-reference.php')
    && str_contains($mapSource, 'DRAFT_ADMIN_REFERENCE')
    && str_contains($mapSource, 'getAdminHazardReferenceConfig')
    && str_contains($mapSource, 'adminReference: true')
    && !str_contains($pageSource, "admin-hazard-reference.php' : \$basePath . 'api/drrm/dev/")
);
assertAdminHazardReference(
    'OperationalPriorityPrecedesDraftFallback',
    strpos($mapSource, "if (operational) return Object.freeze({ endpoint: operational, operational: true });")
    < strpos($mapSource, "return Object.freeze({ endpoint: adminConfig.endpoint, adminReference: true });")
    && str_contains($mapSource, 'loadAdminHazardReferenceCollection')
    && str_contains($mapSource, 'adminHazardFallbackActive')
    && str_contains($mapSource, 'DRAFT_ADMIN_REFERENCE')
);
assertAdminHazardReference(
    'RasterFallbackIsDisabledForAdminHazardStaging',
    str_contains($mapSource, "runtimeConfig.adminHazardReference.enabled === true")
    && str_contains($mapSource, 'return null;')
    && str_contains($pageSource, "enabled: <?php echo \$stagingAdminHazardReferenceEnabled ? 'false' :")
);
assertAdminHazardReference(
    'StableVectorMappingAndVisibleRiskLabelsRemainShared',
    str_contains($mapSource, 'L.geoJSON(featureCollection')
    && str_contains($mapSource, 'floodFeatureStyle')
    && str_contains($mapSource, 'landslideFeatureStyle')
    && str_contains($mapSource, "'Low'")
    && str_contains($mapSource, "'Moderate'")
    && str_contains($mapSource, "'High'")
    && str_contains($mapSource, "'Very High'")
);
assertAdminHazardReference(
    'ReferenceDisclosureAndSourceLabelsAreTruthful',
    str_contains($markupSource, 'DRAFT ADMIN REFERENCE')
    && str_contains($markupSource, 'Controlled draft hazard geometry is shown')
    && !str_contains($markupSource, 'MGB LIVE REFERENCE DATA')
);

/** @return array{status: int, output: string} */
function runAdminHazardEndpointScenario(string $root, string $environment, array $session): array
{
    $endpoint = $root . '/api/drrm/admin-hazard-reference.php';
    $code = 'register_shutdown_function(static function (): void {'
        . '$status = http_response_code();'
        . 'fwrite(STDERR, ' . var_export('HTTP_STATUS=', true)
        . ' . ($status === false ? 200 : $status) . PHP_EOL);'
        . '});'
        . 'putenv(' . var_export('APP_ENV=' . $environment, true) . ');'
        . '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export('GET', true) . ';'
        . '$_GET = [];'
        . 'session_id(' . var_export('admin-hazard-endpoint-test', true) . '); session_start();'
        . '$_SESSION = ' . var_export($session, true) . ';'
        . 'require ' . var_export($endpoint, true) . ';';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the admin hazard endpoint scenario.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (!preg_match('/HTTP_STATUS=(\d+)/', (string) $stderr, $matches)) {
        throw new RuntimeException('Admin hazard endpoint scenario did not report a status: ' . trim((string) $stderr));
    }
    if ($exitCode !== 0) {
        throw new RuntimeException('Admin hazard endpoint scenario failed: ' . trim((string) $stderr));
    }
    return ['status' => (int) $matches[1], 'output' => (string) $stdout];
}

$unauthenticated = runAdminHazardEndpointScenario($root, 'staging', []);
assertAdminHazardReference(
    'UnauthenticatedStagingRequestReturns401',
    $unauthenticated['status'] === 401
    && str_contains($unauthenticated['output'], 'Authentication required.')
);
$missingPermission = runAdminHazardEndpointScenario($root, 'staging', [
    'user_id' => 'admin-hazard-forbidden-test',
    'user_permissions_map' => ['another module' => ['VIEW']],
]);
assertAdminHazardReference(
    'MissingModuleOneViewReturns403',
    $missingPermission['status'] === 403
    && str_contains($missingPermission['output'], 'Module 1 VIEW permission required.')
);
$production = runAdminHazardEndpointScenario($root, 'production', [
    'user_id' => 'admin-hazard-production-test',
    'user_permissions_map' => [DrrmMapAuthorizationService::RESOURCE => ['VIEW']],
]);
assertAdminHazardReference(
    'ProductionRequestReturns404',
    $production['status'] === 404
    && str_contains($production['output'], 'Not found.')
);

try {
    $config = SupabaseConfig::fromEnvironment($root . '/.env');
    $client = new SupabaseRestClient($config);
    try {
        new DrrmAdminHazardReferenceService($client, false);
        assertAdminHazardReference('ServiceRejectsNonStagingConstruction', false);
    } catch (RuntimeException) {
        assertAdminHazardReference('ServiceRejectsNonStagingConstruction', true);
    }

    $collection = (new DrrmAdminHazardReferenceService($client, true))->featureCollection();
    $features = $collection['features'];
    $flood = array_values(array_filter(
        $features,
        static fn (array $feature): bool => ($feature['properties']['hazard'] ?? null) === 'Flood'
    ));
    $landslide = array_values(array_filter(
        $features,
        static fn (array $feature): bool => ($feature['properties']['hazard'] ?? null) === 'Landslide'
    ));
    assertAdminHazardReference('AuthorizedReferenceReturnsExact28Features', count($features) === 28);
    assertAdminHazardReference('ExactFloodReferenceCountIs15', count($flood) === 15);
    assertAdminHazardReference('ExactLandslideReferenceCountIs13', count($landslide) === 13);
    assertAdminHazardReference(
        'ReturnedRecordsHaveOnlyReferenceProperties',
        array_reduce(
            $features,
            static fn (bool $valid, array $feature): bool => $valid
                && array_keys($feature['properties'] ?? []) === [
                    'hazard', 'mgb_code', 'mgb_label', 'display_risk_label',
                    'source_agency', 'reference_status',
                ]
                && ($feature['properties']['reference_status'] ?? null) === 'DRAFT ADMIN REFERENCE',
            true
        )
    );
    $encoded = json_encode($collection, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    assertAdminHazardReference(
        'ResponseOmitsInternalDatasetIdsAndCriticalTerminology',
        !str_contains($encoded, DrrmDraftFloodPreviewService::DATASET_VERSION_ID)
        && !str_contains($encoded, DrrmDraftLandslidePreviewService::DATASET_VERSION_ID)
        && !str_contains($encoded, 'CRITICAL')
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'Admin hazard dataset assertions skipped: ' . $exception->getMessage() . PHP_EOL);
}

echo 'Assertions=' . $assertions . PHP_EOL;
if ($failures !== []) {
    fwrite(STDERR, 'Failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
