<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Config\AppEnvironment;
use App\Config\OsrmConfig;
use App\Config\SupabaseConfig;
use App\Services\DrrmDraftEvacuationCenterPreviewService;
use App\Services\DrrmEvacuationRoutePreviewService;
use App\Services\DrrmMapAuthorizationService;
use App\Services\OsrmRoutingClient;
use App\Services\SupabaseRestClient;

$root = dirname(__DIR__);
require_once $root . '/config/app_environment.php';
require_once $root . '/config/osrm.php';
require_once $root . '/config/supabase.php';
require_once $root . '/src/Services/SupabaseRestClient.php';
require_once $root . '/src/Services/DrrmMapAuthorizationService.php';
require_once $root . '/src/Services/DrrmDraftBarangayPreviewService.php';
require_once $root . '/src/Services/DrrmDraftEvacuationCenterPreviewService.php';
require_once $root . '/src/Services/OsrmRoutingClient.php';
require_once $root . '/src/Services/DrrmEvacuationRoutePreviewService.php';

$failures = [];
$assertions = 0;

function assertAdminRoutePreview(string $name, bool $condition): void
{
    global $failures, $assertions;
    $assertions++;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) $failures[] = $name;
}

/** @return array{status: int, payload: array<string, mixed>} */
function runAdminRouteEndpointScenario(
    string $root,
    string $environment,
    array $session,
    string $method = 'POST'
): array {
    $endpoint = $root . '/api/drrm/admin-evacuation-route-preview.php';
    $sessionId = 'admin-route-' . substr(hash('sha256', $environment . serialize($session) . $method), 0, 20);
    $code = 'register_shutdown_function(static function (): void {'
        . '$status = http_response_code();'
        . 'fwrite(STDERR, ' . var_export('HTTP_STATUS=', true)
        . ' . ($status === false ? 200 : $status) . PHP_EOL);'
        . '});'
        . 'putenv(' . var_export('APP_ENV=' . $environment, true) . ');'
        . '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export($method, true) . ';'
        . '$_SERVER[' . var_export('CONTENT_TYPE', true) . '] = ' . var_export('application/json', true) . ';'
        . '$_GET = [];'
        . 'session_id(' . var_export($sessionId, true) . '); session_start();'
        . '$_SESSION = ' . var_export($session, true) . ';'
        . 'require ' . var_export($endpoint, true) . ';';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) throw new RuntimeException('Unable to start endpoint scenario.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || preg_match('/HTTP_STATUS=(\d+)/', (string) $stderr, $matches) !== 1) {
        throw new RuntimeException('Endpoint scenario failed: ' . trim((string) $stderr));
    }
    $payload = json_decode((string) $stdout, true);
    if (!is_array($payload)) throw new RuntimeException('Endpoint scenario did not return JSON.');
    return ['status' => (int) $matches[1], 'payload' => $payload];
}

$endpointSource = file_get_contents($root . '/api/drrm/admin-evacuation-route-preview.php');
$serviceSource = file_get_contents($root . '/src/Services/DrrmEvacuationRoutePreviewService.php');
$csrfSource = file_get_contents($root . '/src/Services/DrrmMapCsrfService.php');
$centerSource = file_get_contents($root . '/src/Services/DrrmDraftEvacuationCenterPreviewService.php');
$osrmSource = file_get_contents($root . '/src/Services/OsrmRoutingClient.php');
$pageSource = file_get_contents($root . '/pages/drrm/hazard-evacuation-map.php');
$mapSource = file_get_contents($root . '/assets/js/drrm/hazard-evacuation-map.js');
$markupSource = file_get_contents($root . '/includes/dashboard/hazard-evacuation-map.php');
foreach ([$endpointSource, $serviceSource, $csrfSource, $centerSource, $osrmSource,
    $pageSource, $mapSource, $markupSource] as $source) {
    if (!is_string($source)) throw new RuntimeException('A route preview test source could not be read.');
}

$stagingGate = strpos($endpointSource, 'AppEnvironment::isStaging');
$bootstrap = strpos($endpointSource, 'require_once __DIR__ . \'/_bootstrap.php\'');
assertAdminRoutePreview(
    'EndpointIsExactStagingOnly',
    $stagingGate !== false && $bootstrap !== false && $stagingGate < $bootstrap
);
assertAdminRoutePreview(
    'EndpointRequiresAuthenticationAndExactModuleOneView',
    str_contains($endpointSource, 'if (!$authService->isLoggedIn())')
    && str_contains($endpointSource, 'DrrmMapAuthorizationService::fromTrustedSession()')
    && str_contains($endpointSource, 'if (!$authorization->canView())')
);
assertAdminRoutePreview(
    'EndpointIsPostOnlyJsonAndRejectsQueryParameters',
    str_contains($endpointSource, '!== \'POST\'')
    && str_contains($endpointSource, 'application\/json')
    && str_contains($endpointSource, 'if ($_GET !== [])')
);
assertAdminRoutePreview(
    'EndpointAndServiceAreReadOnly',
    preg_match('/->(?:post|patch|delete|rpc)\s*\(/i', $endpointSource . $serviceSource) !== 1
);
assertAdminRoutePreview(
    'EndpointRequiresSessionBoundModuleOneCsrf',
    str_contains($endpointSource, 'DrrmMapCsrfService')
    && str_contains($endpointSource, 'requireValidHeader($_SERVER)')
    && str_contains($endpointSource, 'CSRF validation failed.')
    && str_contains($csrfSource, 'hash_equals')
    && str_contains($pageSource, '$stagingAdminRoutePreviewCsrfToken')
    && str_contains($mapSource, '\'X-CSRF-Token\'')
);

$unauthenticated = runAdminRouteEndpointScenario($root, 'staging', []);
assertAdminRoutePreview(
    'UnauthenticatedStagingRequestFails',
    $unauthenticated['status'] === 401
    && ($unauthenticated['payload']['success'] ?? null) === false
);
$forbidden = runAdminRouteEndpointScenario($root, 'staging', [
    'user_id' => 'route-preview-forbidden',
    'user_permissions_map' => ['another module' => ['VIEW']],
]);
assertAdminRoutePreview(
    'MissingModuleOneViewFails',
    $forbidden['status'] === 403
    && ($forbidden['payload']['success'] ?? null) === false
);
$missingCsrf = runAdminRouteEndpointScenario($root, 'staging', [
    'user_id' => 'route-preview-missing-csrf',
    'user_permissions_map' => [DrrmMapAuthorizationService::RESOURCE => ['VIEW']],
]);
assertAdminRoutePreview(
    'AuthorizedRequestWithoutCsrfFails',
    $missingCsrf['status'] === 403
    && ($missingCsrf['payload']['message'] ?? null) === 'CSRF validation failed.'
);
$production = runAdminRouteEndpointScenario($root, 'production', [
    'user_id' => 'route-preview-production',
    'user_permissions_map' => [DrrmMapAuthorizationService::RESOURCE => ['VIEW']],
]);
assertAdminRoutePreview(
    'ProductionEndpointIsUnavailable',
    $production['status'] === 404
    && ($production['payload']['success'] ?? null) === false
);
$development = runAdminRouteEndpointScenario($root, 'development', [
    'user_id' => 'route-preview-development',
    'user_permissions_map' => [DrrmMapAuthorizationService::RESOURCE => ['VIEW']],
]);
assertAdminRoutePreview(
    'DevelopmentCannotUseStagingEndpoint',
    $development['status'] === 404
    && ($development['payload']['success'] ?? null) === false
);
$wrongMethod = runAdminRouteEndpointScenario($root, 'staging', [
    'user_id' => 'route-preview-method',
    'user_permissions_map' => [DrrmMapAuthorizationService::RESOURCE => ['VIEW']],
], 'GET');
assertAdminRoutePreview(
    'EndpointRejectsNonPost',
    $wrongMethod['status'] === 405
    && ($wrongMethod['payload']['success'] ?? null) === false
);

assertAdminRoutePreview(
    'DestinationCoordinatesResolveOnlyOnServer',
    str_contains($endpointSource, 'evacuation_center_reference_id')
    && !str_contains($endpointSource, 'destination_latitude')
    && !str_contains($endpointSource, 'destination_longitude')
    && str_contains($serviceSource, '$center = $this->validatedCenter')
    && str_contains($serviceSource, '$coordinates = $center[\'geometry\'][\'coordinates\']')
);
assertAdminRoutePreview(
    'ControlledCenterSetRemainsExactDraftInactive15',
    str_contains($centerSource, 'EXPECTED_FEATURE_COUNT = 15')
    && substr_count($centerSource, '            \'') >= 15
    && str_contains($centerSource, '\'publication_status\' => \'eq.DRAFT\'')
    && str_contains($centerSource, '\'operational_status\' => \'eq.INACTIVE\'')
);
assertAdminRoutePreview(
    'OsrmUsesDrivingAndLongitudeLatitudeOrder',
    str_contains($osrmSource, '/route/v1/driving/')
    && str_contains($osrmSource, 'sprintf(\'%.7F,%.7F\', $longitude, $latitude)')
    && str_contains($serviceSource, 'drivingAlternatives(')
);
assertAdminRoutePreview(
    'StagingResponseProjectionIsMinimal',
    str_contains($serviceSource, '\'status\' => \'ADMIN_PLANNING_PREVIEW\'')
    && str_contains($serviceSource, '\'geometry\' => $route[\'geometry\']')
    && str_contains($serviceSource, '\'distance_meters\' => $route[\'distance_meters\']')
    && str_contains($serviceSource, '\'destination_name\' => $center[\'properties\'][\'name\']')
    && !str_contains($endpointSource, '\'routes\' =>')
);
assertAdminRoutePreview(
    'StagingPageNeverEnablesDevelopmentApi',
    str_contains($pageSource, 'api/drrm/admin-evacuation-route-preview.php')
    && str_contains($pageSource, '$stagingAdminRoutePreviewEnabled')
    && !str_contains($endpointSource, '/api/drrm/dev/')
);
assertAdminRoutePreview(
    'UiUsesAdminPlanningPreviewLanguage',
    str_contains($mapSource, 'ADMIN PLANNING PREVIEW')
    && str_contains($markupSource, 'Preview Route')
    && str_contains($markupSource, 'Planning preview only.')
);

$planningResultStart = strpos($mapSource, 'if (adminPlanning === true)');
$planningResultEnd = strpos($mapSource, 'title.textContent = \'Development Recommended Route\'', $planningResultStart ?: 0);
$planningResultSource = $planningResultStart !== false && $planningResultEnd !== false
    ? substr($mapSource, $planningResultStart, $planningResultEnd - $planningResultStart)
    : '';
assertAdminRoutePreview(
    'PlanningResultNeverClaimsSafeOrApproved',
    $planningResultSource !== ''
    && preg_match('/\b(?:SAFE|APPROVED)\b/i', str_replace('not an approved', '', $planningResultSource)) !== 1
);
assertAdminRoutePreview(
    'PlanningRequestDoesNotLoadHazardLayers',
    str_contains($mapSource, 'if (!adminPlanning)')
    && str_contains($mapSource, 'loadDraftFloodPreview()')
    && str_contains($mapSource, 'loadDraftLandslidePreview()')
);
assertAdminRoutePreview(
    'OperationalRoutesRetainPriorityOverPlanningFallback',
    str_contains($mapSource, 'state.operationalRouteFeatureCount > 0')
    && str_contains($mapSource, 'configureAdminPlanningRouteUi();')
    && str_contains($mapSource, 'state.operationalEvacuationCenterFeatureCollection')
    && str_contains($mapSource, 'state.adminEvacuationCenterReferenceFeatureCollection')
);

try {
    $supabaseConfig = SupabaseConfig::fromEnvironment($root . '/.env');
    $client = new SupabaseRestClient($supabaseConfig);
    $centerService = new DrrmDraftEvacuationCenterPreviewService($client, true);
    $centers = $centerService->featureCollection();
    $features = $centers['features'] ?? [];
    assertAdminRoutePreview(
        'LiveControlledSelectorContainsExact15',
        is_array($features) && count($features) === 15
    );

    $centerIds = array_map(
        static fn (array $feature): string => (string) $feature['properties']['evacuation_center_id'],
        $features
    );
    $centerStateBefore = $client->get('evacuation_centers', [
        'select' => 'evacuation_center_id,publication_status,operational_status,verified_by_civentral_user_id,verified_at',
        'evacuation_center_id' => 'in.(' . implode(',', $centerIds) . ')',
        'order' => 'evacuation_center_id.asc',
    ]);
    $routesBefore = $client->get('evacuation_routes', [
        'select' => 'evacuation_route_id',
        'order' => 'created_at.asc',
    ]);
    assertAdminRoutePreview('EvacuationRoutesCountStartsAtZero', count($routesBefore) === 0);

    $service = new DrrmEvacuationRoutePreviewService(
        $centerService,
        new OsrmRoutingClient(OsrmConfig::fromEnvironment($root . '/.env')),
        $root . '/data/import/caloocan-city-boundary.geojson',
        true
    );

    $outsideRejected = false;
    try {
        $service->adminPlanningRoute(14.5995, 120.9842, $centerIds[0]);
    } catch (InvalidArgumentException $error) {
        $outsideRejected = $error->getMessage() === 'The starting location must be inside Caloocan City.';
    }
    assertAdminRoutePreview('StartingPointOutsideCaloocanIsRejected', $outsideRejected);

    $unknownCenterRejected = false;
    try {
        $service->adminPlanningRoute(
            14.7663938,
            121.0607398,
            '00000000-0000-4000-8000-000000000000'
        );
    } catch (InvalidArgumentException) {
        $unknownCenterRejected = true;
    }
    assertAdminRoutePreview('OnlyExactControlledCenterIdsAreAccepted', $unknownCenterRejected);

    $selectedFeature = $features[0];
    $selectedId = (string) $selectedFeature['properties']['evacuation_center_id'];
    $result = $service->adminPlanningRoute(14.7663938, 121.0607398, $selectedId);
    assertAdminRoutePreview(
        'DestinationNameAndRouteResolveServerSide',
        array_keys($result) === [
            'status',
            'geometry',
            'distance_meters',
            'duration_seconds',
            'destination_name',
        ]
        && $result['status'] === 'ADMIN_PLANNING_PREVIEW'
        && $result['destination_name'] === $selectedFeature['properties']['name']
        && ($result['geometry']['type'] ?? null) === 'LineString'
        && count($result['geometry']['coordinates'] ?? []) >= 2
        && is_numeric($result['distance_meters'])
        && is_numeric($result['duration_seconds'])
    );

    $centerStateAfter = $client->get('evacuation_centers', [
        'select' => 'evacuation_center_id,publication_status,operational_status,verified_by_civentral_user_id,verified_at',
        'evacuation_center_id' => 'in.(' . implode(',', $centerIds) . ')',
        'order' => 'evacuation_center_id.asc',
    ]);
    $routesAfter = $client->get('evacuation_routes', [
        'select' => 'evacuation_route_id',
        'order' => 'created_at.asc',
    ]);
    assertAdminRoutePreview(
        'PreviewRouteIsNeverPersisted',
        $routesBefore === $routesAfter && count($routesAfter) === 0
    );
    assertAdminRoutePreview(
        'CenterPublicationStateRemainsUnchanged',
        $centerStateBefore === $centerStateAfter
        && count($centerStateAfter) === 15
        && array_reduce(
            $centerStateAfter,
            static fn (bool $valid, array $center): bool => $valid
                && ($center['publication_status'] ?? null) === 'DRAFT'
                && ($center['operational_status'] ?? null) === 'INACTIVE'
                && ($center['verified_by_civentral_user_id'] ?? null) === null
                && ($center['verified_at'] ?? null) === null,
            true
        )
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Admin route preview live test failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

if ($failures !== []) {
    fwrite(STDERR, 'Admin route preview failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'AdminEvacuationRoutePreviewAssertions=' . $assertions . PHP_EOL;
echo 'DrrmAdminEvacuationRoutePreview=PASS' . PHP_EOL;
