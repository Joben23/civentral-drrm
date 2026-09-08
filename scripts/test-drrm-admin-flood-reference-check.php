<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Config\SupabaseConfig;
use App\Services\DrrmAdminFloodReferenceCheckService;
use App\Services\DrrmCaloocanBoundaryService;
use App\Services\DrrmDraftFloodPreviewService;
use App\Services\DrrmMapAuthorizationService;
use App\Services\SupabaseRestClient;

$root = dirname(__DIR__);
require_once $root . '/config/supabase.php';
require_once $root . '/src/Services/SupabaseRestClient.php';
require_once $root . '/src/Services/DrrmMapAuthorizationService.php';
require_once $root . '/src/Services/DrrmCaloocanBoundaryService.php';
require_once $root . '/src/Services/DrrmDraftFloodPreviewService.php';
require_once $root . '/src/Services/DrrmAdminFloodReferenceCheckService.php';

$failures = [];
$assertions = 0;

function assertAdminFloodCheck(string $name, bool $condition): void
{
    global $failures, $assertions;
    $assertions++;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

/** @return array<string, mixed> */
function floodCheckFeature(string $code, float $longitude, float $latitude, bool $withHole = false): array
{
    $mapping = [
        'LF' => ['Low Susceptibility to Flooding', 'Low'],
        'MF' => ['Moderate Susceptibility to Flooding', 'Moderate'],
        'HF' => ['High Susceptibility to Flooding', 'High'],
        'VHF' => ['Very High Susceptibility to Flooding', 'Very High'],
    ][$code];
    $outerHalf = $withHole ? 0.0003 : 0.0001;
    $outer = [[
        [$longitude - $outerHalf, $latitude - $outerHalf],
        [$longitude + $outerHalf, $latitude - $outerHalf],
        [$longitude + $outerHalf, $latitude + $outerHalf],
        [$longitude - $outerHalf, $latitude + $outerHalf],
        [$longitude - $outerHalf, $latitude - $outerHalf],
    ]];
    if ($withHole) {
        $holeHalf = 0.0001;
        $outer[] = [
            [$longitude - $holeHalf, $latitude - $holeHalf],
            [$longitude - $holeHalf, $latitude + $holeHalf],
            [$longitude + $holeHalf, $latitude + $holeHalf],
            [$longitude + $holeHalf, $latitude - $holeHalf],
            [$longitude - $holeHalf, $latitude - $holeHalf],
        ];
    }

    return [
        'type' => 'Feature',
        'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [$outer]],
        'properties' => [
            'hazard' => 'Flood',
            'mgb_code' => $code,
            'mgb_label' => $mapping[0],
            'display_risk_label' => $mapping[1],
            'source_agency' => 'DENR-MGB',
        ],
    ];
}

/** @return array{type: string, features: list<array<string, mixed>>} */
function syntheticFloodReference(): array
{
    $specifications = [
        ['LF', 121.060, false],
        ['LF', 121.066, false],
        ['LF', 121.070, true],
        ['LF', 121.072, false],
        ['LF', 121.074, false],
        ['MF', 121.062, false],
        ['MF', 121.076, false],
        ['MF', 121.078, false],
        ['HF', 121.064, false],
        ['HF', 121.080, false],
        ['HF', 121.082, false],
        ['HF', 121.084, false],
        ['VHF', 121.066, false],
        ['VHF', 121.086, false],
        ['VHF', 121.088, false],
    ];
    return [
        'type' => 'FeatureCollection',
        'features' => array_map(
            static fn (array $specification): array => floodCheckFeature(
                $specification[0],
                $specification[1],
                14.766,
                $specification[2]
            ),
            $specifications
        ),
    ];
}

$endpointSource = file_get_contents($root . '/api/drrm/admin-flood-reference-check.php');
$serviceSource = file_get_contents($root . '/src/Services/DrrmAdminFloodReferenceCheckService.php');
$draftSource = file_get_contents($root . '/src/Services/DrrmDraftFloodPreviewService.php');
$pageSource = file_get_contents($root . '/pages/drrm/hazard-evacuation-map.php');
$mapSource = file_get_contents($root . '/assets/js/drrm/hazard-evacuation-map.js');
$markupSource = file_get_contents($root . '/includes/dashboard/hazard-evacuation-map.php');
foreach ([$endpointSource, $serviceSource, $draftSource, $pageSource, $mapSource, $markupSource] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('A flood reference test source could not be read.');
    }
}

assertAdminFloodCheck(
    'EndpointIsStagingAdminPostJsonCsrfAndModuleOneViewOnly',
    str_contains($endpointSource, 'AppEnvironment::isStaging')
    && str_contains($endpointSource, "!== 'POST'")
    && str_contains($endpointSource, 'isLoggedIn()')
    && str_contains($endpointSource, 'DrrmMapAuthorizationService::fromTrustedSession()')
    && str_contains($endpointSource, 'if (!$authorization->canView())')
    && str_contains($endpointSource, 'requireValidHeader($_SERVER)')
    && str_contains($endpointSource, 'application\/json')
);

assertAdminFloodCheck(
    'EndpointAcceptsOnlyServerClassifiedCoordinates',
    str_contains($endpointSource, "array_diff(array_keys(\$input), ['latitude', 'longitude'])")
    && !str_contains($endpointSource, "['classification']")
    && !str_contains($endpointSource, "['polygon']")
    && str_contains($endpointSource, 'DrrmDraftFloodPreviewService')
);
assertAdminFloodCheck(
    'ExactControlledFloodSelectorIsReused',
    str_contains($draftSource, "DATASET_VERSION_ID = '1e9c6b5d-dad3-4a5f-bb4b-4c33ac5c30b8'")
    && str_contains($draftSource, 'EXPECTED_FEATURE_COUNT = 15')
    && str_contains($draftSource, "'review_status' => 'eq.DRAFT'")
    && str_contains($draftSource, "'record_status' => 'eq.INACTIVE'")
    && str_contains($serviceSource, 'DrrmDraftFloodPreviewService::EXPECTED_FEATURE_COUNT')
);
assertAdminFloodCheck(
    'CheckIsReadOnlyAndHasNoPersistenceOrPublication',
    preg_match('/->(?:post|patch|delete|rpc)\s*\(/i', $endpointSource . $serviceSource . $draftSource) !== 1
    && !str_contains($endpointSource . $serviceSource, 'publication_status')
    && !str_contains($endpointSource . $serviceSource, 'INSERT')
    && !str_contains($endpointSource . $serviceSource, 'UPDATE')
);
assertAdminFloodCheck(
    'TensorFlowAndForecastInputsAreNotUsed',
    !str_contains($endpointSource . $serviceSource, 'DrrmFloodRiskAi')
    && !str_contains($endpointSource . $serviceSource, 'probability')
    && !str_contains($endpointSource . $serviceSource, 'confidence')
    && !str_contains($endpointSource . $serviceSource, 'rainfall')
    && str_contains($markupSource, 'TensorFlow prediction is unavailable until a governed model and validated forecast inputs are ready.')
);
assertAdminFloodCheck(
    'StagingPageUsesFocusedAdminEndpointOnly',
    str_contains($pageSource, 'api/drrm/admin-flood-reference-check.php')
    && str_contains($pageSource, '$stagingAdminFloodReferenceCheckEnabled')
    && !str_contains($endpointSource, '/api/drrm/dev/')
);
assertAdminFloodCheck(
    'UiUsesExplicitSeparateSelectionModes',
    str_contains($mapSource, "ROUTE_ORIGIN_SELECTION: 'ROUTE_ORIGIN_SELECTION'")
    && str_contains($mapSource, "FLOOD_CHECK_LOCATION_SELECTION: 'FLOOD_CHECK_LOCATION_SELECTION'")
    && str_contains($mapSource, 'floodCheckLocations: L.layerGroup()')
);

$boundary = new DrrmCaloocanBoundaryService(
    $root . '/data/import/caloocan-city-boundary.geojson'
);
$reference = syntheticFloodReference();
$loaderCalls = 0;
$service = new DrrmAdminFloodReferenceCheckService(
    static function () use (&$loaderCalls, $reference): array {
        $loaderCalls++;
        return $reference;
    },
    $boundary,
    true
);

$outsideRejected = false;
try {
    $service->check(14.5995, 120.9842);
} catch (InvalidArgumentException $error) {
    $outsideRejected = $error->getMessage() === 'Please select a location inside Caloocan City.';
}
assertAdminFloodCheck('OutsideCaloocanIsRejectedBeforeReferenceRead', $outsideRejected && $loaderCalls === 0);

$invalidRejected = false;
try {
    $service->check(INF, 121.060);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
assertAdminFloodCheck('InvalidCoordinateIsRejectedBeforeReferenceRead', $invalidRejected && $loaderCalls === 0);

$low = $service->check(14.766, 121.060);
$moderate = $service->check(14.766, 121.062);
$high = $service->check(14.766, 121.064);
$veryHighOverlap = $service->check(14.766, 121.066);
assertAdminFloodCheck('IntersectingLowReturnsLow', $low['classification'] === 'LOW' && $low['risk_rank'] === 1);
assertAdminFloodCheck('IntersectingModerateReturnsModerate', $moderate['classification'] === 'MODERATE' && $moderate['risk_rank'] === 2);
assertAdminFloodCheck('IntersectingHighReturnsHigh', $high['classification'] === 'HIGH' && $high['risk_rank'] === 3);
assertAdminFloodCheck('IntersectingVeryHighReturnsVeryHigh', $veryHighOverlap['classification'] === 'VERY HIGH' && $veryHighOverlap['risk_rank'] === 4);
assertAdminFloodCheck(
    'OverlapUsesHighestExistingSeverityWithoutAveraging',
    $veryHighOverlap['overlap_count'] === 2
    && $veryHighOverlap['multiple_reference_polygons'] === true
);

$noIntersection = $service->check(14.770, 121.06074);
$insideHole = $service->check(14.766, 121.070);
$holeBoundary = $service->check(14.766, 121.0699);
assertAdminFloodCheck(
    'NoIntersectionDoesNotReturnLow',
    $noIntersection['intersection'] === false
    && $noIntersection['status'] === 'NO_MAPPED_REFERENCE_INTERSECTION'
    && !array_key_exists('classification', $noIntersection)
);
assertAdminFloodCheck('PolygonHoleIsNotClassifiedByOuterRing', $insideHole['intersection'] === false);
assertAdminFloodCheck(
    'PolygonBoundaryUsesCoversSemantics',
    $holeBoundary['intersection'] === true && $holeBoundary['classification'] === 'LOW'
);

$encodedResults = json_encode(
    [$low, $moderate, $high, $veryHighOverlap, $noIntersection],
    JSON_THROW_ON_ERROR
);
assertAdminFloodCheck(
    'ResponseContainsNoFabricatedPredictionFields',
    !str_contains($encodedResults, 'probability')
    && !str_contains($encodedResults, 'confidence')
    && !str_contains($encodedResults, 'forecast')
    && !str_contains($encodedResults, 'rainfall')
);
assertAdminFloodCheck(
    'WholeBarangayInferenceIsNotUsed',
    !str_contains(strtolower($serviceSource), 'barangay')
    && str_contains($serviceSource, 'geometryCoversPoint')
);

$wrongCount = $reference;
array_pop($wrongCount['features']);
$wrongCountRejected = false;
try {
    (new DrrmAdminFloodReferenceCheckService(static fn (): array => $wrongCount, $boundary, true))
        ->check(14.766, 121.060);
} catch (RuntimeException) {
    $wrongCountRejected = true;
}
assertAdminFloodCheck('AnythingOtherThanExact15IsRejected', $wrongCountRejected);

$nonStagingRejected = false;
try {
    new DrrmAdminFloodReferenceCheckService(static fn (): array => $reference, $boundary, false);
} catch (RuntimeException) {
    $nonStagingRejected = true;
}
assertAdminFloodCheck('ServiceRejectsNonStagingConstruction', $nonStagingRejected);
/**
 * @return array{status: int, payload: array<string, mixed>}
 */
function runAdminFloodCheckEndpoint(
    string $root,
    string $environment,
    array $session,
    string $method = 'POST',
    string $contentType = 'application/json',
    ?string $csrfToken = null,
    string $body = ''
): array {
    $endpoint = $root . '/api/drrm/admin-flood-reference-check.php';
    $sessionId = 'admin-flood-' . substr(
        hash('sha256', $environment . serialize($session) . $method . $contentType . $body),
        0,
        20
    );
    $code = 'register_shutdown_function(static function (): void {'
        . '$status = http_response_code();'
        . 'fwrite(STDERR, ' . var_export('HTTP_STATUS=', true)
        . ' . ($status === false ? 200 : $status) . PHP_EOL);'
        . '});'
        . 'putenv(' . var_export('APP_ENV=' . $environment, true) . ');'
        . '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export($method, true) . ';'
        . '$_SERVER[' . var_export('CONTENT_TYPE', true) . '] = ' . var_export($contentType, true) . ';'
        . '$_SERVER[' . var_export('HTTP_X_CSRF_TOKEN', true) . '] = ' . var_export($csrfToken, true) . ';'
        . '$_GET = [];'
        . 'session_id(' . var_export($sessionId, true) . '); session_start();'
        . '$_SESSION = ' . var_export($session, true) . ';'
        . 'require ' . var_export($endpoint, true) . ';';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the flood endpoint scenario.');
    }
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || preg_match('/HTTP_STATUS=(\d+)/', (string) $stderr, $matches) !== 1) {
        throw new RuntimeException('Flood endpoint scenario failed: ' . trim((string) $stderr));
    }
    $payload = json_decode((string) $stdout, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Flood endpoint scenario did not return JSON.');
    }
    return ['status' => (int) $matches[1], 'payload' => $payload];
}

$permissionSession = [
    'user_id' => 'admin-flood-reference-test',
    'user_permissions_map' => [DrrmMapAuthorizationService::RESOURCE => ['VIEW']],
];
$validBody = json_encode(['latitude' => 14.766, 'longitude' => 121.060], JSON_THROW_ON_ERROR);

$production = runAdminFloodCheckEndpoint($root, 'production', $permissionSession);
assertAdminFloodCheck(
    'EndpointRequiresExactStagingEnvironment',
    $production['status'] === 404 && $production['payload']['success'] === false
);

$unauthenticated = runAdminFloodCheckEndpoint($root, 'staging', []);
assertAdminFloodCheck(
    'EndpointRejectsUnauthenticatedAccess',
    $unauthenticated['status'] === 401 && $unauthenticated['payload']['success'] === false
);

$forbidden = runAdminFloodCheckEndpoint($root, 'staging', [
    'user_id' => 'admin-flood-forbidden-test',
    'user_permissions_map' => ['another module' => ['VIEW']],
]);
assertAdminFloodCheck(
    'EndpointRequiresExactModuleOneView',
    $forbidden['status'] === 403
    && $forbidden['payload']['message'] === 'Module 1 VIEW permission required.'
);

$wrongMethod = runAdminFloodCheckEndpoint($root, 'staging', $permissionSession, 'GET');
assertAdminFloodCheck(
    'EndpointIsPostOnly',
    $wrongMethod['status'] === 405
    && $wrongMethod['payload']['message'] === 'Method not allowed.'
);

$csrfToken = 'admin-flood-reference-csrf-test-token';
$csrfSession = $permissionSession + [
    'drrm_map_csrf' => ['token' => $csrfToken, 'issued_at' => time()],
];

$missingCsrf = runAdminFloodCheckEndpoint(
    $root,
    'staging',
    $permissionSession,
    'POST',
    'application/json',
    null,
    $validBody
);
assertAdminFloodCheck(
    'EndpointRequiresSessionBoundCsrf',
    $missingCsrf['status'] === 403
    && $missingCsrf['payload']['message'] === 'CSRF validation failed.'
);

$wrongContent = runAdminFloodCheckEndpoint(
    $root,
    'staging',
    $csrfSession,
    'POST',
    'text/plain',
    $csrfToken,
    $validBody
);
assertAdminFloodCheck(
    'EndpointIsJsonOnly',
    $wrongContent['status'] === 415
    && $wrongContent['payload']['message'] === 'JSON request required.'
);

$stringCoordinates = runAdminFloodCheckEndpoint(
    $root,
    'staging',
    $csrfSession,
    'POST',
    'application/json',
    $csrfToken,
    '{"latitude":"14.766","longitude":121.06}'
);
assertAdminFloodCheck('EndpointRejectsNonNumericJsonTypes', $stringCoordinates['status'] === 400);

$invalidRange = runAdminFloodCheckEndpoint(
    $root,
    'staging',
    $csrfSession,
    'POST',
    'application/json',
    $csrfToken,
    '{"latitude":91,"longitude":121.06}'
);
assertAdminFloodCheck(
    'EndpointRejectsIllegalCoordinateRanges',
    $invalidRange['status'] === 422
    && $invalidRange['payload']['message'] === 'Please select a location inside Caloocan City.'
);

$outside = runAdminFloodCheckEndpoint(
    $root,
    'staging',
    $csrfSession,
    'POST',
    'application/json',
    $csrfToken,
    '{"latitude":14.5995,"longitude":120.9842}'
);
assertAdminFloodCheck(
    'EndpointRejectsOutsideCaloocanPoint',
    $outside['status'] === 422
    && $outside['payload']['message'] === 'Please select a location inside Caloocan City.'
);

$validEndpoint = runAdminFloodCheckEndpoint(
    $root,
    'staging',
    $csrfSession,
    'POST',
    'application/json',
    $csrfToken,
    $validBody
);
assertAdminFloodCheck(
    'AuthorizedEndpointReturnsGovernedReferenceShape',
    $validEndpoint['status'] === 200
    && $validEndpoint['payload']['success'] === true
    && in_array(
        $validEndpoint['payload']['status'] ?? null,
        ['DRAFT_ADMIN_REFERENCE', 'NO_MAPPED_REFERENCE_INTERSECTION'],
        true
    )
    && is_bool($validEndpoint['payload']['intersection'] ?? null)
    && ($validEndpoint['payload']['reference']['hazard_type'] ?? null) === 'FLOOD'
    && !array_key_exists('probability', $validEndpoint['payload'])
    && !array_key_exists('confidence', $validEndpoint['payload'])
);
try {
    $client = new SupabaseRestClient(SupabaseConfig::fromEnvironment($root . '/.env'));
    $zoneQuery = [
        'select' => 'hazard_zone_id,risk_level_id,dataset_version_id,geometry,classification_notes,record_status',
        'dataset_version_id' => 'eq.' . DrrmDraftFloodPreviewService::DATASET_VERSION_ID,
        'hazard_type_id' => 'eq.1',
        'order' => 'hazard_zone_id.asc',
        'limit' => 16,
    ];
    $versionQuery = [
        'select' => 'dataset_version_id,review_status',
        'dataset_version_id' => 'eq.' . DrrmDraftFloodPreviewService::DATASET_VERSION_ID,
        'limit' => 2,
    ];
    $zonesBefore = $client->get('hazard_zones', $zoneQuery);
    $versionBefore = $client->get('dataset_versions', $versionQuery);
    $liveCollection = (new DrrmDraftFloodPreviewService($client, true))->featureCollection();
    $liveService = new DrrmAdminFloodReferenceCheckService(
        static fn (): array => $liveCollection,
        $boundary,
        true
    );
    $liveResult = $liveService->check(14.770, 121.06074);
    $zonesAfter = $client->get('hazard_zones', $zoneQuery);
    $versionAfter = $client->get('dataset_versions', $versionQuery);

    assertAdminFloodCheck(
        'LiveControlledReferenceContainsExact15Only',
        count($zonesBefore) === 15
        && count($liveCollection['features']) === 15
        && count($zonesAfter) === 15
    );
    assertAdminFloodCheck(
        'LiveGeometryAndWorkflowStateRemainUnchanged',
        hash('sha256', serialize($zonesBefore)) === hash('sha256', serialize($zonesAfter))
        && hash('sha256', serialize($versionBefore)) === hash('sha256', serialize($versionAfter))
        && ($versionAfter[0]['review_status'] ?? null) === 'DRAFT'
        && array_reduce(
            $zonesAfter,
            static fn (bool $valid, array $zone): bool => $valid
                && ($zone['record_status'] ?? null) === 'INACTIVE',
            true
        )
    );
    assertAdminFloodCheck(
        'LiveCheckResponseRemainsPlanningReferenceOnly',
        isset($liveResult['status'], $liveResult['intersection'], $liveResult['reference'])
        && $liveResult['reference']['source_status'] === 'DRAFT_ADMIN_REFERENCE'
        && !array_key_exists('probability', $liveResult)
        && !array_key_exists('confidence', $liveResult)
    );
} catch (Throwable $error) {
    echo 'LiveControlledReferenceAssertions=SKIP (' . $error->getMessage() . ')' . PHP_EOL;
}

echo 'Assertions=' . $assertions . PHP_EOL;
if ($failures !== []) {
    fwrite(STDERR, 'Failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
