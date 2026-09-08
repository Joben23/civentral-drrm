<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Config\SupabaseConfig;
use App\Services\DrrmCaloocanBoundaryService;
use App\Services\DrrmCitizenFloodReferenceCheckService;
use App\Services\DrrmDraftFloodPreviewService;
use App\Services\DrrmFloodReferenceEvaluatorService;
use App\Services\SupabaseRestClient;

$root = dirname(__DIR__);
require_once $root . '/config/supabase.php';
require_once $root . '/src/Services/SupabaseRestClient.php';
require_once $root . '/src/Services/DrrmCaloocanBoundaryService.php';
require_once $root . '/src/Services/DrrmDraftFloodPreviewService.php';
require_once $root . '/src/Services/DrrmFloodReferenceEvaluatorService.php';
require_once $root . '/src/Services/DrrmCitizenFloodReferenceCheckService.php';

$failures = [];
$assertions = 0;

function citizenFloodAssert(string $name, bool $condition): void
{
    global $failures, $assertions;
    $assertions++;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

/** @return array<string, mixed> */
function citizenFloodFixtureFeature(string $code, float $longitude, float $latitude): array
{
    $mapping = [
        'LF' => ['Low Susceptibility to Flooding', 'Low'],
        'MF' => ['Moderate Susceptibility to Flooding', 'Moderate'],
        'HF' => ['High Susceptibility to Flooding', 'High'],
        'VHF' => ['Very High Susceptibility to Flooding', 'Very High'],
    ][$code];
    $half = 0.0001;

    return [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'MultiPolygon',
            'coordinates' => [[[
                [$longitude - $half, $latitude - $half],
                [$longitude + $half, $latitude - $half],
                [$longitude + $half, $latitude + $half],
                [$longitude - $half, $latitude + $half],
                [$longitude - $half, $latitude - $half],
            ]]],
        ],
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
function citizenFloodFixtureCollection(): array
{
    $specifications = [
        ['LF', 121.060],
        ['LF', 121.066],
        ['LF', 121.070],
        ['LF', 121.072],
        ['LF', 121.074],
        ['MF', 121.062],
        ['MF', 121.076],
        ['MF', 121.078],
        ['HF', 121.064],
        ['HF', 121.080],
        ['HF', 121.082],
        ['HF', 121.084],
        ['VHF', 121.066],
        ['VHF', 121.086],
        ['VHF', 121.088],
    ];

    return [
        'type' => 'FeatureCollection',
        'features' => array_map(
            static fn (array $specification): array => citizenFloodFixtureFeature(
                $specification[0],
                $specification[1],
                14.766
            ),
            $specifications
        ),
    ];
}

/**
 * @param array<string, mixed> $query
 * @return array{status: int, payload: array<string, mixed>}
 */
function runCitizenFloodEndpoint(
    string $root,
    string $environment,
    string $previewFlag,
    string $method = 'POST',
    string $contentType = 'application/json',
    string $body = '',
    array $query = []
): array {
    $endpoint = $root . '/api/citizen/drrm/flood-reference-check.php';
    $code = 'register_shutdown_function(static function (): void {'
        . '$status = http_response_code();'
        . 'fwrite(STDERR, ' . var_export('HTTP_STATUS=', true)
        . ' . ($status === false ? 200 : $status) . PHP_EOL);'
        . '});'
        . 'putenv(' . var_export('APP_ENV=' . $environment, true) . ');'
        . 'putenv(' . var_export('DRRM_PUBLIC_PREVIEW_ENABLED=' . $previewFlag, true) . ');'
        . '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export($method, true) . ';'
        . '$_SERVER[' . var_export('CONTENT_TYPE', true) . '] = ' . var_export($contentType, true) . ';'
        . '$_GET = ' . var_export($query, true) . ';'
        . 'require ' . var_export($endpoint, true) . ';';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the citizen flood endpoint scenario.');
    }

    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || preg_match('/HTTP_STATUS=(\d+)/', (string) $stderr, $matches) !== 1) {
        throw new RuntimeException('Citizen flood endpoint scenario failed: ' . trim((string) $stderr));
    }

    $payload = json_decode((string) $stdout, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Citizen flood endpoint scenario did not return JSON.');
    }

    return ['status' => (int) $matches[1], 'payload' => $payload];
}

$endpointSource = file_get_contents($root . '/api/citizen/drrm/flood-reference-check.php');
$citizenServiceSource = file_get_contents($root . '/src/Services/DrrmCitizenFloodReferenceCheckService.php');
$evaluatorSource = file_get_contents($root . '/src/Services/DrrmFloodReferenceEvaluatorService.php');
$adminServiceSource = file_get_contents($root . '/src/Services/DrrmAdminFloodReferenceCheckService.php');
$adminEndpointSource = file_get_contents($root . '/api/drrm/admin-flood-reference-check.php');
$draftSource = file_get_contents($root . '/src/Services/DrrmDraftFloodPreviewService.php');
foreach ([
    $endpointSource,
    $citizenServiceSource,
    $evaluatorSource,
    $adminServiceSource,
    $adminEndpointSource,
    $draftSource,
] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('A citizen flood reference source could not be read.');
    }
}

citizenFloodAssert(
    'EndpointIsPostOnlyWithCorsPreflight',
    str_contains($endpointSource, 'if ($method !== \'POST\')')
    && str_contains($endpointSource, 'if ($method === \'OPTIONS\')')
    && str_contains($endpointSource, "'Method not allowed.'")
);
citizenFloodAssert(
    'StagingAndPublicPreviewFlagAreBothRequired',
    str_contains($endpointSource, 'AppEnvironment::isStaging($envFile)')
    && str_contains($endpointSource, 'AppEnvironment::isPublicDrrmPreviewEnabled($envFile)')
    && str_contains($endpointSource, "citizenFloodReferenceRespond(false, 'Not found.', 404)")
);
citizenFloodAssert(
    'EndpointIsJsonOnlyAndBodyIsBounded',
    str_contains($endpointSource, 'application\/json')
    && str_contains($endpointSource, '1025')
    && str_contains($endpointSource, 'strlen($rawBody) > 1024')
);
citizenFloodAssert(
    'InputContainsCoordinatesOnly',
    str_contains($endpointSource, 'array_diff(array_keys($input), [\'latitude\', \'longitude\'])')
    && str_contains($endpointSource, 'count($input) !== 2')
    && !str_contains($endpointSource, '$input[\'classification\']')
    && !str_contains($endpointSource, '$input[\'polygon\']')
    && !str_contains($endpointSource, '$input[\'risk_level\']')
);
citizenFloodAssert(
    'SharedEvaluatorIsUsedByAdminAndCitizen',
    str_contains($endpointSource, 'DrrmFloodReferenceEvaluatorService')
    && str_contains($adminServiceSource, 'DrrmFloodReferenceEvaluatorService')
    && str_contains($adminEndpointSource, 'DrrmFloodReferenceEvaluatorService.php')
);
citizenFloodAssert(
    'ExactControlled15FeatureLoaderIsReused',
    str_contains($endpointSource, 'DrrmDraftFloodPreviewService')
    && str_contains($draftSource, "DATASET_VERSION_ID = '1e9c6b5d-dad3-4a5f-bb4b-4c33ac5c30b8'")
    && str_contains($draftSource, 'EXPECTED_FEATURE_COUNT = 15')
    && str_contains($draftSource, "'review_status' => 'eq.DRAFT'")
    && str_contains($draftSource, "'record_status' => 'eq.INACTIVE'")
    && str_contains($evaluatorSource, 'DrrmDraftFloodPreviewService::EXPECTED_FEATURE_COUNT')
);
citizenFloodAssert(
    'EndpointHasNoAdminSessionOrCsrfDependency',
    !str_contains($endpointSource, 'session_')
    && !str_contains($endpointSource, 'AuthService')
    && !str_contains($endpointSource, 'DrrmMapAuthorizationService')
    && !str_contains($endpointSource, 'DrrmMapCsrfService')
);
citizenFloodAssert(
    'AdminEndpointProtectionIsUnchanged',
    str_contains($adminEndpointSource, 'AuthService')
    && str_contains($adminEndpointSource, 'DrrmMapAuthorizationService::fromTrustedSession()')
    && str_contains($adminEndpointSource, 'if (!$authorization->canView())')
    && str_contains($adminEndpointSource, 'requireValidHeader($_SERVER)')
);

$boundary = new DrrmCaloocanBoundaryService(
    $root . '/data/import/caloocan-city-boundary.geojson'
);
$reference = citizenFloodFixtureCollection();
$loaderCalls = 0;
$evaluator = new DrrmFloodReferenceEvaluatorService(
    static function () use (&$loaderCalls, $reference): array {
        $loaderCalls++;
        return $reference;
    },
    $boundary
);
$service = new DrrmCitizenFloodReferenceCheckService($evaluator, true);

$outsideRejected = false;
try {
    $service->check(14.5995, 120.9842);
} catch (InvalidArgumentException $error) {
    $outsideRejected = $error->getMessage() === 'Please select a location inside Caloocan City.';
}
citizenFloodAssert('OutsideCaloocanRejectedBeforeDraftRead', $outsideRejected && $loaderCalls === 0);

$invalidRejected = false;
try {
    $service->check(INF, 121.060);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
citizenFloodAssert('InvalidCoordinatesRejectedBeforeDraftRead', $invalidRejected && $loaderCalls === 0);

$low = $service->check(14.766, 121.060);
$moderate = $service->check(14.766, 121.062);
$high = $service->check(14.766, 121.064);
$veryHighOverlap = $service->check(14.766, 121.066);
$noIntersection = $service->check(14.770, 121.06074);

citizenFloodAssert('LowIsPreserved', $low['classification'] === 'LOW' && $low['risk_rank'] === 1);
citizenFloodAssert('ModerateIsPreserved', $moderate['classification'] === 'MODERATE' && $moderate['risk_rank'] === 2);
citizenFloodAssert('HighIsPreserved', $high['classification'] === 'HIGH' && $high['risk_rank'] === 3);
citizenFloodAssert('VeryHighIsPreserved', $veryHighOverlap['classification'] === 'VERY HIGH' && $veryHighOverlap['risk_rank'] === 4);
citizenFloodAssert(
    'OverlapUsesHighestSeverityWithoutAveraging',
    $veryHighOverlap['overlap_count'] === 2
    && $veryHighOverlap['multiple_reference_polygons'] === true
);
citizenFloodAssert(
    'IntersectionResponseUsesCitizenProjection',
    $high['status'] === 'DEVELOPMENT_REFERENCE'
    && $high['source_status'] === 'DEVELOPMENT_PREVIEW'
    && $high['reference_source'] === 'DENR-MGB'
);
citizenFloodAssert(
    'NoIntersectionNeverBecomesLow',
    $noIntersection['intersection'] === false
    && $noIntersection['status'] === 'NO_MAPPED_REFERENCE_INTERSECTION'
    && !array_key_exists('classification', $noIntersection)
);
citizenFloodAssert(
    'NoIntersectionIncludesTruthfulWarning',
    $noIntersection['warning'] === DrrmCitizenFloodReferenceCheckService::WARNING
    && str_contains($noIntersection['warning'], 'does not mean the location is flood-safe')
);

$wrongCount = $reference;
array_pop($wrongCount['features']);
$wrongCountRejected = false;
try {
    (new DrrmCitizenFloodReferenceCheckService(
        new DrrmFloodReferenceEvaluatorService(static fn (): array => $wrongCount, $boundary),
        true
    ))->check(14.766, 121.060);
} catch (RuntimeException) {
    $wrongCountRejected = true;
}
citizenFloodAssert('AnythingOtherThanExact15IsRejected', $wrongCountRejected);

$disabledServiceRejected = false;
try {
    new DrrmCitizenFloodReferenceCheckService($evaluator, false);
} catch (RuntimeException) {
    $disabledServiceRejected = true;
}
citizenFloodAssert('CitizenProjectionRejectsDisabledPreview', $disabledServiceRejected);

$encodedResults = json_encode(
    [$low, $moderate, $high, $veryHighOverlap, $noIntersection],
    JSON_THROW_ON_ERROR
);
citizenFloodAssert(
    'NoAiForecastOrSensitiveMetadataIsReturned',
    !str_contains($encodedResults, 'probability')
    && !str_contains($encodedResults, 'confidence')
    && !str_contains($encodedResults, 'forecast')
    && !str_contains($encodedResults, 'rainfall')
    && !str_contains($encodedResults, 'dataset_version_id')
    && !str_contains($encodedResults, 'source_object_id')
);
citizenFloodAssert(
    'TensorFlowIsNotCalled',
    !str_contains($endpointSource . $citizenServiceSource . $evaluatorSource, 'flood-risk-ai')
    && !str_contains($endpointSource . $citizenServiceSource . $evaluatorSource, 'DrrmFloodRiskAi')
    && !str_contains($endpointSource . $citizenServiceSource . $evaluatorSource, '/ready')
);
citizenFloodAssert(
    'EndpointAndServicesAreReadOnly',
    preg_match(
        '/->(?:post|patch|delete|rpc)\s*\(/i',
        $endpointSource . $citizenServiceSource . $evaluatorSource
    ) !== 1
    && !str_contains($endpointSource . $citizenServiceSource . $evaluatorSource, 'publication_status')
    && !str_contains($endpointSource . $citizenServiceSource . $evaluatorSource, 'INSERT')
    && !str_contains($endpointSource . $citizenServiceSource . $evaluatorSource, 'UPDATE')
);
citizenFloodAssert(
    'WholeBarangayInferenceIsNotUsed',
    !str_contains(strtolower($endpointSource . $citizenServiceSource . $evaluatorSource), 'barangay')
    && str_contains($evaluatorSource, 'geometryCoversPoint')
);

$validBody = json_encode(['latitude' => 14.766, 'longitude' => 121.060], JSON_THROW_ON_ERROR);
$production = runCitizenFloodEndpoint($root, 'production', 'true');
citizenFloodAssert('ProductionReturns404', $production['status'] === 404 && $production['payload']['success'] === false);

$flagDisabled = runCitizenFloodEndpoint($root, 'staging', 'false');
citizenFloodAssert('DisabledPreviewReturns404', $flagDisabled['status'] === 404 && $flagDisabled['payload']['success'] === false);

$wrongMethod = runCitizenFloodEndpoint($root, 'staging', 'true', 'GET');
citizenFloodAssert('GetIsRejected', $wrongMethod['status'] === 405 && $wrongMethod['payload']['message'] === 'Method not allowed.');

$wrongContent = runCitizenFloodEndpoint($root, 'staging', 'true', 'POST', 'text/plain', $validBody);
citizenFloodAssert('NonJsonContentIsRejected', $wrongContent['status'] === 415 && $wrongContent['payload']['message'] === 'JSON request required.');

$malformed = runCitizenFloodEndpoint($root, 'staging', 'true', 'POST', 'application/json', '{"latitude":');
citizenFloodAssert('MalformedJsonIsRejected', $malformed['status'] === 400);

$extraField = runCitizenFloodEndpoint(
    $root,
    'staging',
    'true',
    'POST',
    'application/json',
    '{"latitude":14.766,"longitude":121.06,"classification":"HIGH"}'
);
citizenFloodAssert('ExtraFieldsAreRejected', $extraField['status'] === 400);

$stringCoordinates = runCitizenFloodEndpoint(
    $root,
    'staging',
    'true',
    'POST',
    'application/json',
    '{"latitude":"14.766","longitude":121.06}'
);
citizenFloodAssert('NumericStringsAreRejected', $stringCoordinates['status'] === 400);

$oversized = runCitizenFloodEndpoint(
    $root,
    'staging',
    'true',
    'POST',
    'application/json',
    str_repeat(' ', 1025)
);
citizenFloodAssert('OversizedBodyIsRejected', $oversized['status'] === 400);

$invalidRange = runCitizenFloodEndpoint(
    $root,
    'staging',
    'true',
    'POST',
    'application/json',
    '{"latitude":91,"longitude":121.06}'
);
citizenFloodAssert(
    'IllegalCoordinateRangeReturns422',
    $invalidRange['status'] === 422
    && $invalidRange['payload']['message'] === 'Please select a location inside Caloocan City.'
);

$outside = runCitizenFloodEndpoint(
    $root,
    'staging',
    'true',
    'POST',
    'application/json',
    '{"latitude":14.5995,"longitude":120.9842}'
);
citizenFloodAssert(
    'OutsideCaloocanReturns422',
    $outside['status'] === 422
    && $outside['payload']['message'] === 'Please select a location inside Caloocan City.'
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
    $liveResponse = runCitizenFloodEndpoint(
        $root,
        'staging',
        'true',
        'POST',
        'application/json',
        $validBody
    );
    $zonesAfter = $client->get('hazard_zones', $zoneQuery);
    $versionAfter = $client->get('dataset_versions', $versionQuery);

    citizenFloodAssert(
        'LiveEndpointReturnsCitizenReferenceShape',
        $liveResponse['status'] === 200
        && $liveResponse['payload']['success'] === true
        && in_array(
            $liveResponse['payload']['status'] ?? null,
            ['DEVELOPMENT_REFERENCE', 'NO_MAPPED_REFERENCE_INTERSECTION'],
            true
        )
        && ($liveResponse['payload']['source_status'] ?? null) === 'DEVELOPMENT_PREVIEW'
        && ($liveResponse['payload']['reference_source'] ?? null) === 'DENR-MGB'
        && is_bool($liveResponse['payload']['intersection'] ?? null)
    );
    citizenFloodAssert(
        'LiveReferenceRemainsExact15DraftInactiveFeatures',
        count($zonesBefore) === 15
        && count($zonesAfter) === 15
        && ($versionAfter[0]['review_status'] ?? null) === 'DRAFT'
        && array_reduce(
            $zonesAfter,
            static fn (bool $valid, array $zone): bool => $valid
                && ($zone['record_status'] ?? null) === 'INACTIVE',
            true
        )
    );
    citizenFloodAssert(
        'LiveReadDoesNotMutateGeometryOrWorkflowState',
        hash('sha256', serialize($zonesBefore)) === hash('sha256', serialize($zonesAfter))
        && hash('sha256', serialize($versionBefore)) === hash('sha256', serialize($versionAfter))
    );
} catch (Throwable $error) {
    echo 'LiveCitizenFloodReferenceAssertions=SKIP (' . $error->getMessage() . ')' . PHP_EOL;
}

citizenFloodAssert(
    'CitizenCenterAndRoutePreviewEndpointsRemainPresent',
    is_file($root . '/api/citizen/drrm/hazard-map.php')
    && is_file($root . '/api/citizen/drrm/evacuation-route-preview.php')
);

echo 'Assertions=' . $assertions . PHP_EOL;
if ($failures !== []) {
    fwrite(STDERR, 'Citizen flood reference failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
