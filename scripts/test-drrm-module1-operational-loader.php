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
$mgbReference = file_get_contents($root . '/assets/js/drrm/mgb-live-reference.js');
$phivolcsReference = file_get_contents($root . '/assets/js/drrm/phivolcs-live-reference.js');
$markup = file_get_contents($root . '/includes/dashboard/hazard-evacuation-map.php');
$css = file_get_contents($root . '/assets/css/hazard-evacuation-map.css');
$readService = file_get_contents($root . '/src/Services/DrrmMapReadService.php');
$adminCenterEndpoint = file_get_contents($root . '/api/drrm/admin-evacuation-center-reference.php');
$adminCenterService = file_get_contents($root . '/src/Services/DrrmAdminEvacuationCenterReferenceService.php');
$mapAuthorization = file_get_contents($root . '/src/Services/DrrmMapAuthorizationService.php');
$citizenReadService = file_get_contents($root . '/src/Services/DrrmCitizenHazardMapReadService.php');

foreach ([$page, $map, $adapter, $mgbReference, $phivolcsReference, $markup, $css, $readService,
    $adminCenterEndpoint, $adminCenterService, $mapAuthorization, $citizenReadService] as $source) {
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
assertModule1Loader('MgbReferenceModuleLoadsBeforeMapRuntime',
    str_contains($page, 'assets/js/drrm/mgb-live-reference.js')
    && strpos($page, '$mgbLiveReferenceUrl') < strpos($page, '$hazardMapJsUrl'));
assertModule1Loader('ReferenceModulesLoadBeforeMapRuntime',
    str_contains($page, 'assets/js/drrm/phivolcs-live-reference.js')
    && strpos($page, '$phivolcsLiveReferenceUrl') < strpos($page, '$hazardMapJsUrl'));
assertModule1Loader('ExternalReferencesAreExplicitStagingOnly',
    str_contains($page, '$stagingReferenceModeEnabled = AppEnvironment::isStaging')
    && str_contains($page, "mgbLiveReference: Object.freeze")
    && str_contains($page, "phivolcsLiveReference: Object.freeze")
    && substr_count($page, "enabled: <?php echo \$stagingReferenceModeEnabled ? 'true' : 'false'; ?>") === 2
    && str_contains($map, "runtimeConfig.dataMode !== 'operational'"));
assertModule1Loader('AdminReferenceEndpointIsSeparatelyPermissionGated',
    str_contains($page, '$stagingAdminCenterReferenceEnabled = $stagingReferenceModeEnabled')
    && str_contains($page, '$module1Authorization->canView()')
    && str_contains($page, 'api/drrm/admin-evacuation-center-reference.php')
    && str_contains($adminCenterEndpoint, 'AppEnvironment::isStaging')
    && str_contains($adminCenterEndpoint, 'isLoggedIn()')
    && str_contains($adminCenterEndpoint, 'canView()'));

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
    substr_count(
        $readService,
        chr(39) . 'record_status' . chr(39) . ' => ' . chr(39) . 'eq.ACTIVE' . chr(39)
    ) >= 3
    && str_contains(
        $readService,
        chr(39) . 'review_status' . chr(39) . ' => ' . chr(39) . 'eq.PUBLISHED' . chr(39)
    )
    && str_contains($readService, 'dataset_sources')
    && str_contains($readService, 'eq.PUBLISHED')
    && str_contains($readService, 'neq.INACTIVE')
    && str_contains($readService, 'eq.APPROVED')
    && str_contains($readService, 'verified_by_civentral_user_id')
    && str_contains($readService, 'last_reviewed_at'));
assertModule1Loader('FrontendAdapterFailsClosedOnWorkflowFields',
    str_contains($adapter, 'An unpublished operational record was rejected.')
    && str_contains($adapter, 'An inactive operational record was rejected.')
    && str_contains($adapter, 'An unapproved operational route was rejected.'));
assertModule1Loader('RouteDistanceUsesPublishedMeters',
    str_contains($adapter, 'distance_meters: distanceMeters')
    && str_contains($adapter, 'distanceMeters <= 0')
    && str_contains($map, 'formatRouteDistance(properties.distance_meters)'));

assertModule1Loader('AllFourHazardControlsRemainVisible',
    substr_count($markup, 'data-map-layer=') === 4
    && array_reduce([
        'Flood-Prone Areas',
        'Landslide-Prone Areas',
        'Earthquake / Fault Information',
        'Evacuation Centers',
    ], static fn (bool $found, string $label): bool => $found && str_contains($markup, $label), true));
assertModule1Loader('CheckboxHandlersNeverRemoveControlElements',
    !preg_match('/control\.(?:remove|replaceWith)\s*\(/', $map)
    && str_contains($map, 'if (!control.checked)')
    && str_contains($map, 'state.map.removeLayer'));
assertModule1Loader('TruthfulModeAndSourceLabelsArePresent', array_reduce([
    'Map Data Status: Development Preview',
    'Map Data Status: Operational + Reference',
    'Map Data Status: Operational',
    'LOCAL DEVELOPMENT PREVIEW',
    'MGB LIVE REFERENCE DATA',
    'PHIVOLCS LIVE REFERENCE DATA',
    'UNVERIFIED ADMIN REFERENCE',
    'CIVENTRAL OPERATIONAL DATA',
], static fn (bool $found, string $label): bool => $found
    && (str_contains($map, $label) || str_contains($markup, $label)), true));
assertModule1Loader('OperationalBarangayLoadPreservesModeAwareHeader',
    str_contains($map, 'function setOperationalBarangayUi')
    && str_contains($map, 'updateMapDataStatus();')
    && !str_contains($map, "setStatus('mapDataStatusText', 'Map Data Status: Operational');"));
assertModule1Loader('MgbLegendKeepsOfficialVeryHighTerminology',
    str_contains($map, 'MGB LIVE REFERENCE \u2014 FLOOD + LANDSLIDE')
    && str_contains($map, "sourceLayerActive ? 'Very High' : 'Critical'"));
assertModule1Loader('CaloocanOutlineStaysAboveReferenceRaster',
    str_contains($map, "['mgbReferencePane', 330, false]")
    && str_contains($map, "['cityMaskPane', 340, false]")
    && str_contains($map, "['cityOutlinePane', 410, false]")
    && str_contains($map, "pane: 'cityOutlinePane'"));
assertModule1Loader('CityFitFocusControlsAndMapDimensionsRemainStable',
    str_contains($map, "['whole', 'Whole Caloocan']")
    && str_contains($map, "['north', 'North']")
    && str_contains($map, "['south', 'South']")
    && str_contains($map, "focusMapArea('whole')")
    && str_contains($map, 'state.map.fitBounds(bounds')
    && str_contains($css, 'height: clamp(32rem, calc(100vh - 14.5rem), 43rem)')
    && str_contains($css, 'height: 34rem')
    && str_contains($css, 'height: 27rem')
    && str_contains($css, 'height: 23rem'));
assertModule1Loader('ReferenceCentersAreIsolatedFromOperationalRouting',
    str_contains($map, 'if (isOperationalMode() || !getEvacuationRoutePreviewConfig()')
    && str_contains($map, 'state.operationalEvacuationCenterFeatureCollection')
    && !str_contains($adminCenterService, 'evacuation_center_id\' =>'));
assertModule1Loader('PreparednessOperationalLabelRequiresPublishedRoute',
    str_contains($map, 'state.operationalRouteFeatureCount > 0')
    && str_contains($map, "' Operational Data'")
    && str_contains($map, "' No Published Operational Routes'"));
assertModule1Loader('CitizenApiCannotExposeAdminCenterReference',
    !str_contains($citizenReadService, "'evacuation-centers'")
    && !str_contains($citizenReadService, 'caloocan-evacuation-centers-ready.json'));
assertModule1Loader('NoStagingReferenceUsesDevelopmentApiPath',
    !str_contains($mgbReference, '/api/drrm/dev/')
    && !str_contains($phivolcsReference, '/api/drrm/dev/')
    && !str_contains($adminCenterEndpoint, '/api/drrm/dev/')
    && str_contains($map, "config.endpoint.includes('/api/drrm/dev/')"));

$browserBundle = implode(PHP_EOL, [$page, $map, $adapter, $mgbReference, $phivolcsReference, $markup]);
$secretNames = ['SUPABASE_SECRET_KEY', 'CIVENTRAL_AI_INTERNAL_KEY'];
assertModule1Loader('BrowserBundleContainsNoSecretVariableNames', array_reduce(
    $secretNames,
    static fn (bool $absent, string $name): bool => $absent && !str_contains($browserBundle, $name),
    true
));
assertModule1Loader(
    'BrowserBundleContainsNoCredentialLiterals',
    preg_match(
        '/(?:eyJ[A-Za-z0-9_-]{40,}|sb_(?:secret|publishable)_[A-Za-z0-9_-]{20,}|sk-[A-Za-z0-9_-]{40,})/',
        $browserBundle
    ) !== 1
);

if ($failures !== []) {
    fwrite(STDERR, 'Module 1 loader test failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'Module1OperationalLoaderAssertions=' . $assertions . PHP_EOL;
echo 'DrrmModule1OperationalLoader=PASS' . PHP_EOL;
