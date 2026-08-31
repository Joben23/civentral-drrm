<?php

declare(strict_types=1);

use App\Config\AppEnvironment;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config/app_environment.php';

$page = file_get_contents($root . '/pages/drrm/hazard-evacuation-map.php');
$map = file_get_contents($root . '/assets/js/drrm/hazard-evacuation-map.js');
$adapter = file_get_contents($root . '/assets/js/drrm/operational-map-data.js');
$markup = file_get_contents($root . '/includes/dashboard/hazard-evacuation-map.php');
$readService = file_get_contents($root . '/src/Services/DrrmMapReadService.php');

foreach ([$page, $map, $adapter, $markup, $readService] as $source) {
    if (!is_string($source)) {
        fwrite(STDERR, 'A Module 1 loader contract source could not be read.' . PHP_EOL);
        exit(1);
    }
}

$failures = [];
$assertions = 0;

function assertModule1Loader(string $name, bool $condition): void
{
    global $assertions, $failures;
    $assertions++;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) $failures[] = $name;
}

$originalAppEnv = getenv('APP_ENV');
try {
    putenv('APP_ENV=development');
    assertModule1Loader('LocalLoopbackKeepsDevelopmentPreview', AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
    ]));
    assertModule1Loader('NonLocalHostDisablesDevelopmentPreview', !AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '172.20.0.5',
        'HTTP_HOST' => 'drrm-staging.civentral.tech',
    ]));
} finally {
    putenv($originalAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $originalAppEnv);
}

$operationalEndpoints = [
    'api/drrm/barangays.php',
    'api/drrm/hazard-zones.php',
    'api/drrm/fault-features.php',
    'api/drrm/evacuation-centers.php',
    'api/drrm/evacuation-routes.php',
    'api/drrm/lookups.php',
];
assertModule1Loader('AllOperationalEndpointsConfigured', array_reduce(
    $operationalEndpoints,
    static fn (bool $found, string $endpoint): bool => $found && str_contains($page, $endpoint),
    true
));
assertModule1Loader('OperationalEndpointsDisabledLocally',
    substr_count($page, '$draftBarangayPreviewEnabled ? null : $basePath') === 6);
assertModule1Loader('PreviewEndpointsRemainDevelopmentGated',
    substr_count($page, '$draftBarangayPreviewEnabled ? $basePath') >= 7);
assertModule1Loader('InlineBarangayMappingIsModeGated',
    str_contains($page, "runtimeConfig.dataMode === 'development-preview'")
    && str_contains($page, "runtimeConfig.dataMode === 'operational'"));

$operationalGuard = 'dataMode === ' . chr(39) . 'operational' . chr(39);
assertModule1Loader('OperationalModeHasExplicitPreviewFallbackGuards',
    substr_count($map, $operationalGuard) >= 7);
assertModule1Loader('OperationalRouteDoesNotUseDevelopmentOsrm',
    str_contains($map, 'getOperationalEvacuationRouteConfig')
    && str_contains($map, 'initializeOperationalEvacuationRoutes')
    && !str_contains($adapter, '/api/drrm/dev/'));

assertModule1Loader('TruthfulOperationalEmptyStatesPresent', array_reduce([
    'Barangay operational data is not yet published.',
    'Flood hazard operational data is not yet published.',
    'Landslide hazard operational data is not yet published.',
    'Fault operational data is not yet published.',
    'No published evacuation centers are currently available.',
    'No approved evacuation routes are currently published.',
], static fn (bool $found, string $message): bool => $found && str_contains($map, $message), true));
assertModule1Loader('EndpointFailuresRemainDistinctFromEmpty', array_reduce([
    'Barangay operational data could not be loaded.',
    'Flood hazard data could not be loaded.',
    'Landslide hazard data could not be loaded.',
    'Earthquake/fault information could not be loaded.',
    'Evacuation-center data could not be loaded.',
    'Approved evacuation routes could not be loaded.',
], static fn (bool $found, string $message): bool => $found && str_contains($map, $message), true));

assertModule1Loader('ServerReadServiceFiltersWorkflowStates',
    substr_count($readService, 'eq.ACTIVE') === 3
    && str_contains($readService, 'eq.PUBLISHED')
    && str_contains($readService, 'neq.INACTIVE')
    && str_contains($readService, 'eq.APPROVED'));
assertModule1Loader('FrontendAdapterFailsClosedOnWorkflowFields',
    str_contains($adapter, 'An unpublished operational record was rejected.')
    && str_contains($adapter, 'An inactive operational record was rejected.')
    && str_contains($adapter, 'An unapproved operational route was rejected.'));
assertModule1Loader('RouteDistanceUsesPublishedMeters',
    str_contains($adapter, 'distance_meters: distanceMeters')
    && str_contains($map, 'formatRouteDistance(properties.distance_meters)'));

function module1EnvValue(string $path, string $name): ?string
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$candidate, $value] = array_map('trim', explode('=', $line, 2));
        if ($candidate !== $name) continue;
        if (strlen($value) >= 2 && (($value[0] === chr(39) && $value[strlen($value) - 1] === chr(39))
            || ($value[0] === chr(34) && $value[strlen($value) - 1] === chr(34)))) {
            $value = substr($value, 1, -1);
        }
        return $value;
    }
    return null;
}

$browserBundle = implode(PHP_EOL, [$page, $map, $adapter, $markup]);
$secretNames = ['SUPABASE_SECRET_KEY', 'CIVENTRAL_AI_INTERNAL_KEY'];
assertModule1Loader('BrowserBundleContainsNoSecretVariableNames', array_reduce(
    $secretNames,
    static fn (bool $absent, string $name): bool => $absent && !str_contains($browserBundle, $name),
    true
));
$secretValuesAbsent = true;
foreach ($secretNames as $secretName) {
    $value = module1EnvValue($root . '/.env', $secretName);
    if (is_string($value) && strlen($value) >= 8 && str_contains($browserBundle, $value)) {
        $secretValuesAbsent = false;
    }
}
assertModule1Loader('BrowserBundleContainsNoConfiguredSecretValues', $secretValuesAbsent);

if ($failures !== []) {
    fwrite(STDERR, 'Module 1 loader test failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'Module1OperationalLoaderAssertions=' . $assertions . PHP_EOL;
echo 'DrrmModule1OperationalLoader=PASS' . PHP_EOL;
