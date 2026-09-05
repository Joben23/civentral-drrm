<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\DrrmAdminEvacuationCenterReferenceService;
use App\Services\DrrmCitizenHazardMapReadService;
use App\Services\DrrmMapAuthorizationService;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

$root = dirname(__DIR__);
require_once $root . '/config/app_environment.php';
require_once $root . '/config/supabase.php';
require_once $root . '/src/Services/SupabaseRestClient.php';
require_once $root . '/src/Services/DrrmMapReadService.php';
require_once $root . '/src/Services/DrrmDraftBarangayPreviewService.php';
require_once $root . '/src/Services/DrrmDraftEvacuationCenterPreviewService.php';
require_once $root . '/src/Services/DrrmAdminEvacuationCenterReferenceService.php';
require_once $root . '/src/Services/DrrmMapAuthorizationService.php';
require_once $root . '/src/Services/DrrmCitizenHazardMapReadService.php';

$failures = [];
$assertions = 0;

function assertAdminCenterReference(string $name, bool $condition): void
{
    global $assertions, $failures;
    $assertions++;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

$endpointSource = file_get_contents($root . '/api/drrm/admin-evacuation-center-reference.php');
$serviceSource = file_get_contents($root . '/src/Services/DrrmAdminEvacuationCenterReferenceService.php');
$controlledSource = file_get_contents($root . '/src/Services/DrrmDraftEvacuationCenterPreviewService.php');
$citizenSource = file_get_contents($root . '/src/Services/DrrmCitizenHazardMapReadService.php');
$mapSource = file_get_contents($root . '/assets/js/drrm/hazard-evacuation-map.js');
$pageSource = file_get_contents($root . '/pages/drrm/hazard-evacuation-map.php');
$resourceManagementSource = file_get_contents($root . '/assets/js/rolespermission/resource-management/api.js');
$permissionUiSource = file_get_contents($root . '/assets/js/rolespermission/permissions/ui.js');

foreach ([$endpointSource, $serviceSource, $controlledSource, $citizenSource, $mapSource, $pageSource,
    $resourceManagementSource, $permissionUiSource] as $source) {
    if (!is_string($source)) {
        fwrite(STDERR, 'An admin center reference test source could not be read.' . PHP_EOL);
        exit(1);
    }
}

$originalEnvironment = getenv('APP_ENV');
try {
    putenv('APP_ENV=staging');
    assertAdminCenterReference('ExactStagingEnvironmentEnablesReference', AppEnvironment::isStaging());
    putenv('APP_ENV=development');
    assertAdminCenterReference('DevelopmentEnvironmentCannotEnableStagingReference', !AppEnvironment::isStaging());
    putenv('APP_ENV=production');
    assertAdminCenterReference('ProductionEnvironmentCannotEnableStagingReference', !AppEnvironment::isStaging());
} finally {
    putenv($originalEnvironment === false ? 'APP_ENV' : 'APP_ENV=' . $originalEnvironment);
}

$_SESSION = [];
assertAdminCenterReference(
    'UnauthenticatedPermissionContextIsDenied',
    !DrrmMapAuthorizationService::fromTrustedSession()->canView()
);
$_SESSION = [
    'user_id' => 'admin-reference-test',
    'user_permissions_map' => ['another module' => ['VIEW']],
    'user_granted_actions' => ['VIEW'],
    'user_granted_resources' => [DrrmMapAuthorizationService::RESOURCE],
];
assertAdminCenterReference(
    'FlatOrWrongResourcePermissionsAreDenied',
    !DrrmMapAuthorizationService::fromTrustedSession()->canView()
);
$_SESSION['user_permissions_map'] = [DrrmMapAuthorizationService::RESOURCE => ['VIEW']];
assertAdminCenterReference(
    'CurrentModuleOneViewPermissionIsAccepted',
    DrrmMapAuthorizationService::fromTrustedSession()->canView()
);
$_SESSION['user_permissions_map'] = [DrrmMapAuthorizationService::LEGACY_RESOURCE => ['VIEW']];
assertAdminCenterReference(
    'LegacyModuleOneViewPermissionRemainsAccepted',
    DrrmMapAuthorizationService::fromTrustedSession()->canView()
);
$_SESSION['user_permissions_map'] = ['  HAZARD &   EVACUATION  MAP  ' => [' view ']];
assertAdminCenterReference(
    'ModuleOneViewPermissionNormalizesCaseAndSpacing',
    DrrmMapAuthorizationService::fromTrustedSession()->canView()
);
$_SESSION = [
    'user_id' => 'admin-reference-test',
    'current_user_details' => ['is_superadmin' => true],
    'user_permissions_map' => [],
];
assertAdminCenterReference(
    'SuperadminFlagDoesNotBypassModuleOneView',
    !DrrmMapAuthorizationService::fromTrustedSession(['is_superadmin' => true])->canView()
);
$_SESSION = [];

$currentResourceKey = chr(39) . DrrmMapAuthorizationService::RESOURCE . chr(39) . ':';
$legacyResourceKey = chr(39) . DrrmMapAuthorizationService::LEGACY_RESOURCE . chr(39) . ':';
assertAdminCenterReference(
    'AuthorizationUsesAuthoritativeModuleOneResourceNames',
    DrrmMapAuthorizationService::RESOURCE === 'hazard & evacuation map'
    && DrrmMapAuthorizationService::LEGACY_RESOURCE === 'hazard & evacuation map system'
    && str_contains($resourceManagementSource, $currentResourceKey)
    && str_contains($resourceManagementSource, $legacyResourceKey)
    && str_contains($permissionUiSource, $currentResourceKey)
    && str_contains($permissionUiSource, $legacyResourceKey)
);
assertAdminCenterReference(
    'StagingAdminPageEmitsAuthorizedReferenceEndpoint',
    str_contains($pageSource, '$stagingReferenceModeEnabled = AppEnvironment::isStaging')
    && str_contains($pageSource, '$module1Authorization->canView()')
    && str_contains($pageSource, '$stagingAdminCenterReferenceEnabled')
    && str_contains($pageSource, 'api/drrm/admin-evacuation-center-reference.php')
);

$stagingGatePosition = strpos($endpointSource, 'AppEnvironment::isStaging');
$bootstrapPosition = strpos($endpointSource, "require_once __DIR__ . '/_bootstrap.php'");
assertAdminCenterReference(
    'StagingGateRunsBeforeProtectedEndpointBootstrap',
    $stagingGatePosition !== false && $bootstrapPosition !== false && $stagingGatePosition < $bootstrapPosition
);
assertAdminCenterReference(
    'EndpointRequiresAuthenticationAndModuleOneView',
    str_contains($endpointSource, 'if (!$authService->isLoggedIn())')
    && str_contains($endpointSource, 'if (!$authorization->canView())')
    && str_contains($endpointSource, 'Authentication required.')
    && str_contains($endpointSource, 'Module 1 VIEW permission required.')
);
assertAdminCenterReference(
    'EndpointIsReadOnlyAndRejectsQueryParameters',
    str_contains($endpointSource, "REQUEST_METHOD")
    && str_contains($endpointSource, "!== 'GET'")
    && str_contains($endpointSource, 'if ($_GET !== [])')
    && preg_match('/->(?:post|patch|delete|rpc)\s*\(/i', $endpointSource . $serviceSource) !== 1
);

/** @return array{status: int, payload: array<string, mixed>} */
function runAdminCenterEndpointScenario(string $root, string $environment, array $session, string $method = 'GET'): array
{
    $endpoint = $root . '/api/drrm/admin-evacuation-center-reference.php';
    $code = 'register_shutdown_function(static function (): void {'
        . '$status = http_response_code();'
        . 'fwrite(STDERR, ' . var_export('HTTP_STATUS=', true)
        . ' . ($status === false ? 200 : $status) . PHP_EOL);'
        . '});'
        . 'putenv(' . var_export('APP_ENV=' . $environment, true) . ');'
        . '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export($method, true) . ';'
        . '$_GET = [];'
        . 'session_id(' . var_export('admin-center-endpoint-test', true) . '); session_start();'
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
        throw new RuntimeException('Unable to start the endpoint scenario.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (!preg_match('/HTTP_STATUS=(\d+)/', (string) $stderr, $matches)) {
        throw new RuntimeException(
            'Endpoint scenario did not report an HTTP status (exit '
            . $exitCode . '): ' . trim((string) $stderr)
        );
    }
    if ($exitCode !== 0) {
        throw new RuntimeException(
            'Endpoint scenario failed with HTTP ' . $matches[1]
            . ' (exit ' . $exitCode . '): ' . trim((string) $stderr)
        );
    }
    $payload = json_decode((string) $stdout, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Endpoint scenario did not return JSON.');
    }
    return ['status' => (int) $matches[1], 'payload' => $payload];
}

$unauthenticatedResponse = runAdminCenterEndpointScenario($root, 'staging', []);
assertAdminCenterReference(
    'StagingUnauthenticatedEndpointRequestReturns401',
    $unauthenticatedResponse['status'] === 401
    && ($unauthenticatedResponse['payload']['success'] ?? null) === false
);
$forbiddenResponse = runAdminCenterEndpointScenario($root, 'staging', [
    'user_id' => 'admin-center-forbidden-test',
    'user_permissions_map' => ['another module' => ['VIEW']],
]);
assertAdminCenterReference(
    'StagingMissingModuleOneViewReturns403',
    $forbiddenResponse['status'] === 403
    && ($forbiddenResponse['payload']['success'] ?? null) === false
);
$authorizedResponse = runAdminCenterEndpointScenario($root, 'staging', [
    'user_id' => 'admin-center-authorized-test',
    'user_permissions_map' => [DrrmMapAuthorizationService::RESOURCE => ['VIEW']],
]);
assertAdminCenterReference(
    'StagingModuleOneViewEndpointReturnsExact15',
    $authorizedResponse['status'] === 200
    && ($authorizedResponse['payload']['type'] ?? null) === 'FeatureCollection'
    && count($authorizedResponse['payload']['features'] ?? []) === 15
);
$productionResponse = runAdminCenterEndpointScenario($root, 'production', [
    'user_id' => 'admin-center-production-test',
    'user_permissions_map' => [DrrmMapAuthorizationService::RESOURCE => ['VIEW']],
]);
assertAdminCenterReference(
    'ProductionAdminReferenceEndpointReturns404',
    $productionResponse['status'] === 404
    && ($productionResponse['payload']['success'] ?? null) === false
);
$methodResponse = runAdminCenterEndpointScenario($root, 'staging', [], 'POST');
assertAdminCenterReference(
    'AdminReferenceEndpointRejectsNonGetWith405',
    $methodResponse['status'] === 405
    && ($methodResponse['payload']['success'] ?? null) === false
);

assertAdminCenterReference(
    'ControlledReadUsesExactDraftInactiveFilters',
    str_contains($serviceSource, 'DrrmDraftEvacuationCenterPreviewService')
    && substr_count($controlledSource, "'publication_status' => 'eq.DRAFT'") === 1
    && substr_count($controlledSource, "'operational_status' => 'eq.INACTIVE'") === 1
    && str_contains($controlledSource, 'EXPECTED_FEATURE_COUNT = 15')
    && substr_count($controlledSource, "'72983eab-") === 1
);

assertAdminCenterReference(
    'CitizenApiCannotRequestReferenceCenters',
    in_array('evacuation-centers', DrrmCitizenHazardMapReadService::SUPPORTED_LAYERS, true)
    && !str_contains($citizenSource, 'caloocan-evacuation-centers-ready.json')
);
try {
    (new DrrmCitizenHazardMapReadService($root . '/data/import'))->layer('evacuation-centers');
    assertAdminCenterReference('CitizenServiceRequiresPublicCenterAdapter', false);
} catch (RuntimeException) {
    assertAdminCenterReference('CitizenServiceRequiresPublicCenterAdapter', true);
}

$routeSelectorStart = strpos($mapSource, 'function populateRouteCenterOptions');
$routeSelectorEnd = strpos($mapSource, 'function highestHazardAtPoint', $routeSelectorStart ?: 0);
$routeLoaderStart = strpos($mapSource, 'async function loadOperationalEvacuationRoutes');
$routeLoaderEnd = strpos($mapSource, 'async function initializeOperationalEvacuationRoutes', $routeLoaderStart ?: 0);
$routeSelectorSource = $routeSelectorStart !== false && $routeSelectorEnd !== false
    ? substr($mapSource, $routeSelectorStart, $routeSelectorEnd - $routeSelectorStart)
    : '';
$routeLoaderSource = $routeLoaderStart !== false && $routeLoaderEnd !== false
    ? substr($mapSource, $routeLoaderStart, $routeLoaderEnd - $routeLoaderStart)
    : '';
assertAdminCenterReference(
    'ReferenceCentersRemainSeparateForAdminPlanningSelector',
    str_contains($routeSelectorSource, 'state.adminEvacuationCenterReferenceFeatureCollection')
    && str_contains($routeSelectorSource, 'previewConfig.adminPlanning === true')
    && str_contains($routeLoaderSource, 'state.operationalEvacuationCenterFeatureCollection')
    && !str_contains($routeLoaderSource, 'state.evacuationCenterFeatureCollection')
);

try {
    $config = SupabaseConfig::fromEnvironment($root . '/.env');
    $client = new SupabaseRestClient($config);
    try {
        new DrrmAdminEvacuationCenterReferenceService($client, false);
        assertAdminCenterReference('ServiceRejectsNonStagingConstruction', false);
    } catch (RuntimeException) {
        assertAdminCenterReference('ServiceRejectsNonStagingConstruction', true);
    }

    $collection = (new DrrmAdminEvacuationCenterReferenceService($client, true))->featureCollection();
    assertAdminCenterReference(
        'AdminReferenceReturnsExactControlledFeatureCount',
        ($collection['type'] ?? null) === 'FeatureCollection'
        && is_array($collection['features'] ?? null)
        && count($collection['features']) === 15
    );

    $expectedKeys = [
        'reference_id',
        'name',
        'location_status',
        'barangay_display_location',
        'managing_office',
        'verification_status',
        'display_status',
    ];
    $ids = [];
    $minimalProjection = true;
    foreach ($collection['features'] as $feature) {
        $properties = $feature['properties'] ?? null;
        $geometry = $feature['geometry'] ?? null;
        if (!is_array($properties) || array_keys($properties) !== $expectedKeys
            || ($properties['location_status'] ?? null) !== 'APPROXIMATE_REFERENCE_LOCATION'
            || ($properties['verification_status'] ?? null) !== 'PENDING_LGU_VERIFICATION'
            || ($properties['display_status'] ?? null) !== 'UNVERIFIED CENTER REFERENCE'
            || !is_array($geometry) || ($geometry['type'] ?? null) !== 'Point') {
            $minimalProjection = false;
            break;
        }
        $ids[] = $properties['reference_id'];
    }
    assertAdminCenterReference(
        'ReferenceProjectionIsMinimalTruthfulAndUnique',
        $minimalProjection && count(array_unique($ids)) === 15
    );

    $encoded = json_encode($collection, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    assertAdminCenterReference(
        'ReferenceProjectionExcludesOperationalAndSensitiveClaims',
        !str_contains($encoded, 'capacity')
        && !str_contains($encoded, 'contact_phone')
        && !str_contains($encoded, 'verified_by_civentral_user_id')
        && !str_contains($encoded, 'AVAILABLE')
        && !str_contains($encoded, 'route')
        && !str_contains($encoded, $config->serverApiKey())
    );
    assertAdminCenterReference(
        'OperationalCenterEndpointStillReturnsNoDraftRows',
        (new DrrmMapReadService($client))->evacuationCenters() === []
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'Admin center reference live-read test failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

if ($failures !== []) {
    fwrite(STDERR, 'Admin center reference failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'AdminCenterReferenceAssertions=' . $assertions . PHP_EOL;
echo 'DrrmAdminCenterReference=PASS' . PHP_EOL;
