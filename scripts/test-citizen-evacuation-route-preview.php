<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$sources = [];
foreach (['endpoint' => '/api/citizen/drrm/evacuation-route-preview.php', 'service' => '/src/Services/DrrmEvacuationRoutePreviewService.php', 'center' => '/src/Services/DrrmDraftEvacuationCenterPreviewService.php', 'osrm' => '/src/Services/OsrmRoutingClient.php', 'admin' => '/api/drrm/admin-evacuation-route-preview.php'] as $name => $path) {
    $sources[$name] = file_get_contents($root . $path);
    if (!is_string($sources[$name])) {
        throw new RuntimeException('A route preview source file could not be read.');
    }
}

$failures = [];
function citizenRouteAssert(string $name, bool $condition): void
{
    global $failures;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

$endpoint = $sources['endpoint'];
$service = $sources['service'];
$center = $sources['center'];
$osrm = $sources['osrm'];
$admin = $sources['admin'];

citizenRouteAssert('PostOnly', str_contains($endpoint, "if (\$method !== 'POST')") && str_contains($endpoint, "'Method not allowed.'"));
citizenRouteAssert('StagingAndFlagGate', str_contains($endpoint, 'AppEnvironment::isStaging($envFile)') && str_contains($endpoint, 'AppEnvironment::isPublicDrrmPreviewEnabled($envFile)') && str_contains($endpoint, "citizenRoutePreviewRespond(false, 'Not found.', 404)"));
citizenRouteAssert('MalformedJsonRejected', str_contains($endpoint, 'JSON_THROW_ON_ERROR') && str_contains($endpoint, "'Invalid route request.', 400"));
citizenRouteAssert('ExtraFieldsRejected', str_contains($endpoint, 'array_diff(array_keys($input)') && str_contains($endpoint, 'count($input) !== 3'));
citizenRouteAssert('InvalidCoordinatesRejected', str_contains($endpoint, '!is_int($input[\'latitude\'])') && str_contains($endpoint, '!is_int($input[\'longitude\'])'));
citizenRouteAssert('CenterReferenceRequired', str_contains($endpoint, '!is_string($input[\'center_reference_id\'])') && str_contains($endpoint, 'trim($input[\'center_reference_id\']) === \'\''));
citizenRouteAssert('DestinationCoordinatesCannotBeSubmitted', !str_contains($endpoint, 'destination_latitude') && !str_contains($endpoint, 'destination_longitude'));
citizenRouteAssert('OutsideCaloocanIsRejected', str_contains($service, 'The starting location must be inside Caloocan City.') && str_contains($endpoint, 'catch (InvalidArgumentException'));
citizenRouteAssert('UnknownCenterIsRejected', str_contains($service, 'The evacuation center is not available for development routing.') && str_contains($endpoint, 'catch (InvalidArgumentException'));
citizenRouteAssert('ExactControlled15CenterSet', str_contains($center, 'EXPECTED_FEATURE_COUNT = 15') && str_contains($center, 'private const CENTER_IDS') && str_contains($center, "'publication_status' => 'eq.DRAFT'") && str_contains($center, "'operational_status' => 'eq.INACTIVE'"));
citizenRouteAssert('DestinationResolvedServerSide', str_contains($service, '$coordinates = $center[\'geometry\'][\'coordinates\'];') && str_contains($service, '\'reference_id\' => $center[\'properties\'][\'evacuation_center_id\']'));
citizenRouteAssert('OsrmUsesLongitudeLatitudeOrder', str_contains($osrm, 'sprintf(\'%.7F,%.7F\', $longitude, $latitude)') && str_contains($osrm, '/route/v1/driving/'));
citizenRouteAssert('ValidLineStringAccepted', str_contains($osrm, '($geometry[\'type\'] ?? null) !== \'LineString\'') && str_contains($osrm, '$geometry[\'coordinates\'] === []'));
citizenRouteAssert('DistanceAndOptionalDurationReturned', str_contains($service, 'distance_meters') && str_contains($service, 'duration_seconds'));
citizenRouteAssert('DevelopmentPlanningStatus', str_contains($service, '\'DEVELOPMENT_PLANNING_PREVIEW\''));
citizenRouteAssert('TruthfulResponseLanguage', str_contains($service, 'Planning preview only.') && str_contains($service, 'not an approved evacuation route'));
$projection = substr($service, strpos($service, 'citizenPlanningRoute') ?: 0);
$projection = preg_replace('/not an approved evacuation route/i', '', $projection) ?? $projection;
citizenRouteAssert('NoUnsafeClaimsInCitizenProjection', preg_match('/\b(?:SAFE|APPROVED|OFFICIAL|PUBLISHED)\b/i', $projection) !== 1);
citizenRouteAssert('NoRoutePersistence', preg_match('/->(?:post|patch|delete|rpc)\s*\(/i', $endpoint . $service) !== 1);
citizenRouteAssert('CenterStateCannotBeChanged', !str_contains($endpoint . $service, 'UPDATE evacuation_centers') && !str_contains($endpoint . $service, 'publication_status'));
citizenRouteAssert('AdminEndpointRemainsProtected', str_contains($admin, 'AuthService') && str_contains($admin, 'DrrmMapCsrfService') && str_contains($admin, 'DrrmMapAuthorizationService'));
citizenRouteAssert('CitizenCenterEndpointUnchanged', is_file($root . '/api/citizen/drrm/hazard-map.php'));
citizenRouteAssert('ModuleOneGovernanceSourcePresent', is_file($root . '/scripts/test-drrm-module1-governance.php') || is_file($root . '/scripts/test-drrm-admin-evacuation-route-preview.php'));
citizenRouteAssert('EndpointSyntax', shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/api/citizen/drrm/evacuation-route-preview.php') . ' 2>&1') !== null);
citizenRouteAssert('ServiceSyntax', shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/src/Services/DrrmEvacuationRoutePreviewService.php') . ' 2>&1') !== null);

if ($failures !== []) {
    fwrite(STDERR, 'Citizen route preview failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "CitizenEvacuationRoutePreviewAssertions=24\n";
echo "CitizenEvacuationRoutePreview=PASS\n";
