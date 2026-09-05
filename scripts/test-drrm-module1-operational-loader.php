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
$adminBarangayEndpoint = file_get_contents($root . '/api/drrm/admin-barangay-reference.php');
$adminBarangayService = file_get_contents($root . '/src/Services/DrrmAdminBarangayReferenceService.php');
$mapAuthorization = file_get_contents($root . '/src/Services/DrrmMapAuthorizationService.php');
$citizenReadService = file_get_contents($root . '/src/Services/DrrmCitizenHazardMapReadService.php');

foreach ([$page, $map, $adapter, $mgbReference, $phivolcsReference, $markup, $css, $readService,
    $adminCenterEndpoint, $adminCenterService, $adminBarangayEndpoint, $adminBarangayService,
    $mapAuthorization, $citizenReadService] as $source) {
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
    && str_contains($page, "adminHazardReference: Object.freeze")
    && str_contains($page, '$stagingAdminHazardReferenceEnabled')
    && substr_count($page, "enabled: <?php echo \$stagingReferenceModeEnabled ? 'true' : 'false'; ?>") === 1
    && str_contains($page, "enabled: <?php echo \$stagingAdminHazardReferenceEnabled ? 'false' : (\$stagingReferenceModeEnabled ? 'true' : 'false'); ?>")
    && str_contains($map, "runtimeConfig.dataMode !== 'operational'"));
assertModule1Loader('AdminReferenceEndpointIsSeparatelyPermissionGated',
    str_contains($page, '$stagingAdminCenterReferenceEnabled = $stagingReferenceModeEnabled')
    && str_contains($page, '$module1Authorization->canView()')
    && str_contains($page, 'api/drrm/admin-evacuation-center-reference.php')
    && str_contains($adminCenterEndpoint, 'AppEnvironment::isStaging')
    && str_contains($adminCenterEndpoint, 'isLoggedIn()')
    && str_contains($adminCenterEndpoint, 'canView()'));
assertModule1Loader('AdminBarangayReferenceEndpointIsSeparatelyPermissionGated',
    str_contains($page, '$stagingAdminBarangayReferenceEnabled')
    && str_contains($page, 'api/drrm/admin-barangay-reference.php')
    && str_contains($adminBarangayEndpoint, 'AppEnvironment::isStaging')
    && str_contains($adminBarangayEndpoint, 'isLoggedIn()')
    && str_contains($adminBarangayEndpoint, 'canView()')
    && str_contains($adminBarangayEndpoint, "REQUEST_METHOD")
    && str_contains($adminBarangayService, 'DrrmDraftBarangayPreviewService'));
assertModule1Loader('BarangayReferenceNeverUsesDevelopmentEndpoint',
    !str_contains($page, 'admin-barangay-reference.php') || !str_contains($page, 'api/drrm/dev/barangays-draft.php')
    || str_contains($page, '$draftBarangayPreviewEnabled ? $basePath'));
assertModule1Loader('OperationalBarangaysResolveReferenceOnlyWhenEmpty',
    str_contains($adapter, 'resolveBarangaySource')
    && str_contains($map, 'resolveBarangaySource')
    && str_contains($map, 'INCOMPLETE_ADMIN_REFERENCE')
    && str_contains($map, 'getAdminBarangayReferenceConfig'));
assertModule1Loader('MapBundlesUseContentVersioning',
    str_contains($page, '$operationalMapDataVersion = hash_file(\'sha256\', $operationalMapDataFile);')
    && str_contains($page, '$hazardMapJsVersion = hash_file(\'sha256\', $hazardMapJsFile);'));
assertModule1Loader('OperationalZeroRowsUseExplicitAdminReferenceResolver',
    str_contains($adapter, 'async function resolveEvacuationCenterSource')
    && str_contains($map, 'adapter.resolveEvacuationCenterSource')
    && str_contains($map, 'state.operationalEvacuationCenterFeatureCollection = operationalCollection')
    && str_contains($map, 'loadAdminReference'));
$sameOriginCredential = 'credentials: ' . chr(39) . 'same-origin' . chr(39);
assertModule1Loader('AdminReferenceRequestUsesAuthenticatedSameOriginSession',
    str_contains($map, 'window.fetch(adminReferenceConfig.endpoint')
    && str_contains($map, $sameOriginCredential));

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
$centerHandlerStart = strpos($map, 'async function handleEvacuationCenterControl');
$centerHandlerEnd = strpos($map, 'function pointSelectionToolsAvailable', $centerHandlerStart ?: 0);
$centerHandlerSource = $centerHandlerStart !== false && $centerHandlerEnd !== false
    ? substr($map, $centerHandlerStart, $centerHandlerEnd - $centerHandlerStart)
    : '';
assertModule1Loader('CenterCheckboxHideRetainsLoadedSourceAndCollection',
    str_contains($centerHandlerSource, 'state.evacuationCenterLoadedSourceMode')
    && !str_contains($centerHandlerSource, 'clearLayers')
    && !str_contains($centerHandlerSource, 'evacuationCenterFeatureCollection = null')
    && str_contains($centerHandlerSource, 'control.disabled = false'));
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
    && str_contains($map, "['phivolcsReferencePane', 330, false]")
    && str_contains($map, 'fillOpacity: 1')
    && str_contains($map, "['cityOutlinePane', 410, false]")
    && str_contains($map, "pane: 'cityOutlinePane'"));
assertModule1Loader('NoPersistentCityOrBarangayFillObscuresHazards',
    str_contains($map, "function cityBaseStyle()")
    && str_contains($map, "fill: false,\n      fillOpacity: 0")
    && str_contains($map, "function draftBarangayStyle()")
    && str_contains($map, "fill: false,\n      fillOpacity: 0")
    && str_contains($map, "['hazardPolygonPane', 360, true]")
    && str_contains($map, "['barangayPane', 370, true]")
    && str_contains($map, "['cityOutlinePane', 410, false]"));
assertModule1Loader('OutsideMaskUsesEvenOddInteriorHole',
    str_contains($map, "style: cityMaskStyle")
    && str_contains($map, "fillRule: 'evenodd'")
    && str_contains($mgbReference, "Object.freeze([-180, -85])")
    && str_contains($mgbReference, '].concat(cityExteriorRings)'));
assertModule1Loader('BarangaySelectionLifecycleClearsHighlight',
    str_contains($map, 'state.selectedBarangayLayer = null;')
    && str_contains($map, 'state.selectedBarangayRecord = null;')
    && str_contains($map, 'clearDraftBarangaySelection();')
    && str_contains($map, 'state.map.removeLayer(layerGroup)'));
$floodHandlerStart = strpos($map, 'async function handleFloodControl');
$floodHandlerEnd = strpos($map, 'async function handleLandslideControl', $floodHandlerStart ?: 0);
$floodHandlerSource = $floodHandlerStart !== false && $floodHandlerEnd !== false
    ? substr($map, $floodHandlerStart, $floodHandlerEnd - $floodHandlerStart)
    : '';
$landslideHandlerStart = strpos($map, 'async function handleLandslideControl');
$landslideHandlerEnd = strpos($map, 'function removePhivolcsReferenceLayer', $landslideHandlerStart ?: 0);
$landslideHandlerSource = $landslideHandlerStart !== false && $landslideHandlerEnd !== false
    ? substr($map, $landslideHandlerStart, $landslideHandlerEnd - $landslideHandlerStart)
    : '';
assertModule1Loader('IndependentDraftHazardCollectionsRemainExact',
    str_contains($map, 'if (featureCollection.features.length !== 15)')
    && str_contains($map, 'if (featureCollection.features.length !== 13)')
    && str_contains($map, 'state.layerGroups.floodHazards.clearLayers()')
    && str_contains($map, 'state.layerGroups.landslideHazards.clearLayers()'));
assertModule1Loader('HazardTogglesOnlyRemoveTheirOwnGroups',
    $floodHandlerSource !== ''
    && $landslideHandlerSource !== ''
    && str_contains($floodHandlerSource, 'const layerGroup = state.layerGroups ? state.layerGroups.floodHazards : null')
    && str_contains($landslideHandlerSource, 'const layerGroup = state.layerGroups ? state.layerGroups.landslideHazards : null')
    && substr_count($map, 'state.layerGroups.floodHazards.clearLayers()') >= 2
    && substr_count($map, 'state.layerGroups.landslideHazards.clearLayers()') >= 2);
assertModule1Loader('BarangayInteractionLayerPreservesTransparentClickTarget',
    str_contains($map, "['barangayInteractionPane', 440, true]")
    && str_contains($map, 'function barangayInteractionStyle()')
    && str_contains($map, 'fillOpacity: 0.001')
    && str_contains($map, 'state.barangayInteractionLayer = L.geoJSON')
    && str_contains($map, 'interactive: true'));
assertModule1Loader('EvacuationMarkersStayAboveBarangayInteraction',
    strpos($map, "['barangayInteractionPane', 440, true]") < strpos($map, "['markerPane', 600, true]")
    && str_contains($map, "pane: 'markerPane'")
    && str_contains($map, 'L.marker(latlng'));
$centerHandlerStart = strpos($map, 'onEachFeature: function (feature, layer) {', strpos($map, 'async function loadDraftEvacuationCenterPreview'));
$centerHandlerEnd = strpos($map, '}', $centerHandlerStart ?: 0);
$centerHandlerSource = $centerHandlerStart !== false && $centerHandlerEnd !== false
    ? substr($map, $centerHandlerStart, 900)
    : '';
assertModule1Loader('EvacuationMarkerClickStopsBarangayPropagation',
    str_contains($centerHandlerSource, 'L.DomEvent.stopPropagation(event.originalEvent)')
    && str_contains($centerHandlerSource, 'selectEvacuationCenter(layer, feature.properties)')
    && strpos($centerHandlerSource, 'L.DomEvent.stopPropagation') < strpos($centerHandlerSource, 'selectEvacuationCenter'));
assertModule1Loader('CenterDetailsRetainReferenceDisclosure',
    str_contains($map, 'function showEvacuationCenterLocationDetails(properties)')
    && str_contains($map, 'UNVERIFIED CENTER REFERENCE')
    && str_contains($map, 'approximate reference location')
    && str_contains($map, 'Managing office: ')
    && str_contains($map, 'administrative planning only'));
assertModule1Loader('BarangayClicksAndSearchShareSelectionPath',
    substr_count($map, 'selectDraftBarangay(record);') >= 3
    && str_contains($map, 'const recordsByCode = new Map')
    && str_contains($map, 'recordsByCode.get(feature.properties.barangay_code)')
    && str_contains($map, 'state.barangayInteractionLayer'));
$interactionStart = strpos($map, 'state.barangayInteractionLayer = L.geoJSON');
$interactionEnd = strpos($map, '}).addTo(state.map);', $interactionStart ?: 0);
$interactionSource = $interactionStart !== false && $interactionEnd !== false
    ? substr($map, $interactionStart, $interactionEnd - $interactionStart)
    : '';
assertModule1Loader('BarangayHoverTooltipIsTemporaryAndNameOnly',
    str_contains($interactionSource, 'layer.bindTooltip(feature.properties.name')
    && str_contains($interactionSource, "permanent: false")
    && str_contains($interactionSource, "sticky: true")
    && str_contains($interactionSource, "layer.closeTooltip();")
    && !str_contains($interactionSource, 'permanent: true')
    && !str_contains($interactionSource, 'bindTooltip(createDraftPopup')
    && !str_contains($interactionSource, 'bindTooltip(feature.properties.name +'));
assertModule1Loader('BarangayHoverTooltipUsesExistingInteractionLayer',
    str_contains($interactionSource, "pane: 'barangayInteractionPane'")
    && str_contains($interactionSource, 'layer.on(\'mouseover\'')
    && str_contains($interactionSource, 'selectDraftBarangay(record);'));
assertModule1Loader('HazardVectorsDoNotInferWholeBarangays',
    str_contains($floodHandlerSource, 'state.layerGroups.floodHazards')
    && str_contains($landslideHandlerSource, 'state.layerGroups.landslideHazards')
    && !str_contains($floodHandlerSource . $landslideHandlerSource, 'fillEntireBarangay'));
assertModule1Loader('RiskLegendFollowsHazardLayers',
    strpos($markup, 'aria-labelledby="hazardLayersTitle"') < strpos($markup, 'aria-labelledby="riskLegendTitle"')
    && strpos($markup, 'aria-labelledby="riskLegendTitle"') < strpos($markup, 'civ-reference-disclosures'));
assertModule1Loader('MapUsesTightCaloocanBounds',
    str_contains($map, 'state.cityBoundaryBounds.pad(0.04)')
    && str_contains($map, 'maxBoundsViscosity: 1.0'));
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
    str_contains($map, 'state.adminEvacuationCenterReferenceFeatureCollection')
    && str_contains($map, 'previewConfig.adminPlanning === true')
    && str_contains($map, 'state.operationalEvacuationCenterFeatureCollection')
    && !str_contains($adminCenterService, 'evacuation_center_id\' =>'));
assertModule1Loader('PreparednessOperationalLabelRequiresPublishedRoute',
    str_contains($map, 'state.operationalRouteFeatureCount > 0')
    && str_contains($map, "' CIVENTRAL OPERATIONAL DATA'")
    && str_contains($map, "routePanel.dataset.routeMode = 'CIVENTRAL_OPERATIONAL_DATA'")
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
