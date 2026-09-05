'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const reference = require('../assets/js/drrm/mgb-live-reference.js');

const root = path.resolve(__dirname, '..');
const referenceSource = fs.readFileSync(path.join(root, 'assets/js/drrm/mgb-live-reference.js'), 'utf8');
const mapSource = fs.readFileSync(path.join(root, 'assets/js/drrm/hazard-evacuation-map.js'), 'utf8');
const adapterSource = fs.readFileSync(path.join(root, 'assets/js/drrm/operational-map-data.js'), 'utf8');
const pageSource = fs.readFileSync(path.join(root, 'pages/drrm/hazard-evacuation-map.php'), 'utf8');
const markupSource = fs.readFileSync(path.join(root, 'includes/dashboard/hazard-evacuation-map.php'), 'utf8');
const cssSource = fs.readFileSync(path.join(root, 'assets/css/hazard-evacuation-map.css'), 'utf8');

let assertions = 0;

function check(name, callback) {
  try {
    callback();
    assertions += 1;
    process.stdout.write(name + '=PASS\n');
  } catch (error) {
    process.stderr.write(name + '=FAIL\n');
    throw error;
  }
}

const expectedServices = [
  'https://controlmap.mgb.gov.ph/arcgis/rest/services/GeospatialDataInventory_Public/GDI_Detailed_Flood_Susceptibility_Public/MapServer',
  'https://controlmap.mgb.gov.ph/arcgis/rest/services/GeospatialDataInventory_Public/GDI_Detailed_Rain_induced_Landslide_Susceptibility_Public/MapServer'
];

check('OnlyExactOfficialPublicMapServersAreTrusted', function () {
  assert.deepEqual(reference.trustedMapServerUrls, expectedServices);
  reference.trustedMapServerUrls.forEach(function (url) {
    assert.equal(reference.assertTrustedMapServerUrl(url), url);
    assert.equal(reference.assertTrustedExportUrl(url + '/export'), url + '/export');
    const parsed = new URL(url);
    assert.equal(parsed.protocol, 'https:');
    assert.equal(parsed.hostname, 'controlmap.mgb.gov.ph');
    assert.match(parsed.pathname, /\/GeospatialDataInventory_Public\/.*_Public\/MapServer$/);
  });
});

check('UntrustedDowngradedAndFeatureServerUrlsAreRejected', function () {
  [
    expectedServices[0].replace('https:', 'http:'),
    expectedServices[0].replace('/MapServer', '/FeatureServer'),
    expectedServices[0].replace('GeospatialDataInventory_Public', 'GeospatialDataInventory'),
    expectedServices[0] + '?token=invented',
    'https://localhost/arcgis/rest/services/GeospatialDataInventory_Public/Fake_Public/MapServer'
  ].forEach(function (url) {
    assert.throws(function () {
      reference.assertTrustedMapServerUrl(url);
    }, /Untrusted|Unsafe/);
  });
  assert.throws(function () {
    reference.assertTrustedExportUrl(expectedServices[0] + '/export?bbox=unsafe');
  }, /Untrusted|Unsafe/);
});

check('LiveReferencesUseSingleExportImagesNotCachedTiles', function () {
  ['flood', 'landslide'].forEach(function (hazard) {
    const service = reference.serviceFor(hazard);
    assert.equal(service.exportUrl, service.mapServerUrl + '/export');
    assert.deepEqual(service.displayZoomRange, { minimum: 6, maximum: 18 });
    assert.doesNotMatch(service.exportUrl, /FeatureServer|\/query|\/tile(?:[/?]|$)/i);
  });
  assert.equal((referenceSource.match(/FeatureServer/g) || []).length, 1);
  assert.match(referenceSource, /parsed\.pathname\.includes\('\/FeatureServer'\)/);
  assert.doesNotMatch(referenceSource, /\/tile\/\{z\}\/\{y\}\/\{x\}/);
  assert.doesNotMatch(referenceSource, /mapServerUrl:\s*[^\n]*FeatureServer|\/query(?:[/?'"`]|$)/i);
  assert.match(mapSource, /L\.imageOverlay\(/);
  assert.doesNotMatch(mapSource, /L\.tileLayer\(descriptor\.tileUrlTemplate/);
});

check('HigherDisplayZoomReusesNativeZoomFourteen', function () {
  assert.doesNotMatch(referenceSource, /resolveNativeTileZoom/);
  assert.match(mapSource, /snapshotMgbView/);
  assert.match(mapSource, /bboxSR: '3857'/);
  assert.match(mapSource, /imageSR: '3857'/);
  assert.match(mapSource, /view\.leafletBounds/);
  assert.match(mapSource, /view\.bbox/);
  assert.match(mapSource, /state\.map\.on\('zoomend moveend', scheduleMgbReferenceRefresh\)/);
  assert.doesNotMatch(mapSource, /zoom(?:anim|start)/);
});

check('OutsideCityMaskIsPresentationOnlyAndDoesNotMutateSource', function () {
  const city = {
    type: 'FeatureCollection',
    features: [{
      type: 'Feature',
      properties: { adm3_name: 'Caloocan City' },
      geometry: {
        type: 'MultiPolygon',
        coordinates: [
          [[[120.9, 14.7], [121, 14.7], [121, 14.8], [120.9, 14.7]]],
          [[[120.95, 14.6], [121.05, 14.6], [121.05, 14.65], [120.95, 14.6]]]
        ]
      }
    }]
  };
  const before = JSON.stringify(city);
  const mask = reference.createOutsideCityMask(city);
  assert.equal(JSON.stringify(city), before);
  assert.equal(mask.properties.presentation_only, true);
  assert.equal(mask.properties.source_imagery_modified, false);
  assert.equal(mask.geometry.type, 'Polygon');
  assert.equal(mask.geometry.coordinates.length, 3);
  assert.match(mapSource, /pane:\s*'cityMaskPane'/);
  assert.match(mapSource, /fillRule:\s*'evenodd'/);
  assert.match(mapSource, /opacity:\s*1/);
});

check('LiveReferencePathCannotPersistMgbFeatures', function () {
  const activationStart = mapSource.indexOf('async function activateMgbReferenceLayer');
  const activationEnd = mapSource.indexOf('function createFloodTooltip', activationStart);
  const activationSource = mapSource.slice(activationStart, activationEnd);
  assert.ok(activationStart >= 0 && activationEnd > activationStart);
  assert.doesNotMatch(referenceSource, /supabase|fetch\s*\(|\.insert\s*\(|\.upsert\s*\(|method\s*:\s*['"]POST/i);
  assert.doesNotMatch(activationSource, /supabase|fetch\s*\(|geoJSON\s*\(|FeatureServer|method\s*:\s*['"]POST/i);
  assert.match(activationSource, /requestMgbReferenceImage\(hazard, control\)/);
});

check('StagingGuardCannotUseDevelopmentEndpointsOrLocalhost', function () {
  assert.match(pageSource, /mgbLiveReference:[\s\S]*enabled:\s*<\?php echo \$stagingReferenceModeEnabled \? 'true' : 'false'; \?>/);
  assert.match(mapSource, /runtimeConfig\.dataMode !== 'operational'/);
  assert.doesNotMatch(referenceSource, /localhost|\/api\/drrm\/dev\//i);
});

check('OperationalPublishedRowsAlwaysTakePriority', function () {
  assert.equal(reference.selectOperationalOrReference(1, true), reference.SOURCE_MODES.OPERATIONAL);
  assert.equal(reference.selectOperationalOrReference(500, true), reference.SOURCE_MODES.OPERATIONAL);
  assert.equal(reference.selectOperationalOrReference(1, false), reference.SOURCE_MODES.OPERATIONAL);
  assert.match(mapSource, /selectOperationalOrReference\(0, true\)/);
});

check('ReferenceModeRequiresSuccessfulZeroOperationalRows', function () {
  assert.equal(reference.selectOperationalOrReference(0, true), reference.SOURCE_MODES.MGB_REFERENCE);
  assert.equal(reference.selectOperationalOrReference(0, false), reference.SOURCE_MODES.UNAVAILABLE);
  assert.throws(function () {
    reference.selectOperationalOrReference(-1, true);
  }, /non-negative integer/);
  assert.match(mapSource, /if \(!loaded\)[\s\S]*Flood hazard data could not be loaded\./);
  assert.match(mapSource, /if \(!loaded\)[\s\S]*Landslide hazard data could not be loaded\./);
});

check('FloodAndLandslideModesRemainSeparate', function () {
  assert.deepEqual(reference.selectHazardSourceModes({ flood: 3, landslide: 0 }, true), {
    flood: reference.SOURCE_MODES.OPERATIONAL,
    landslide: reference.SOURCE_MODES.MGB_REFERENCE
  });
  assert.deepEqual(reference.selectHazardSourceModes({ flood: 0, landslide: 2 }, true), {
    flood: reference.SOURCE_MODES.MGB_REFERENCE,
    landslide: reference.SOURCE_MODES.OPERATIONAL
  });
  assert.match(mapSource, /mgbFloodReference: L\.layerGroup\(\)/);
  assert.match(mapSource, /mgbLandslideReference: L\.layerGroup\(\)/);
});

check('ExternalFailureHasTruthfulIsolatedUnavailableState', function () {
  const failure = reference.failureState('flood');
  assert.equal(failure.mode, reference.SOURCE_MODES.UNAVAILABLE);
  assert.equal(failure.message, 'Official MGB reference layer is temporarily unavailable.');
  assert.match(mapSource, /imageOverlay\.once\('error'/);
  assert.match(mapSource, /control\.disabled = true/);
  assert.match(mapSource, /removeMgbReferenceLayer\(hazard, false\)/);
  assert.doesNotMatch(mapSource, /fake polygon|fallback.*draft/i);
});

check('CaloocanOutlineAndMaskHavePredictablePaneOrder', function () {
  assert.match(mapSource, /\['mgbReferencePane', 330, false\]/);
  assert.match(mapSource, /\['cityMaskPane', 340, false\]/);
  assert.match(mapSource, /\['hazardPolygonPane', 360, true\]/);
  assert.match(mapSource, /\['cityOutlinePane', 410, false\]/);
  assert.match(mapSource, /\['markerPane', 600, true\]/);
  assert.match(mapSource, /\['routeOverlayPane', 620, true\]/);
  assert.match(mapSource, /pane:\s*'cityOutlinePane'/);
  assert.match(mapSource, /pane:\s*'mgbReferencePane'/);
});

check('StaleMgbImagesCannotRemainStacked', function () {
  assert.match(mapSource, /mgbReferenceRequestIds/);
  assert.match(mapSource, /previous && previous !== imageOverlay/);
  assert.match(mapSource, /imageOverlay\.remove\(\)/);
  assert.match(mapSource, /mgbReferenceImageOpacity/);
  assert.match(mapSource, /return otherVisible \? 0\.75 : 0\.92/);
  assert.match(mapSource, /overlay\.setOpacity\(mgbReferenceImageOpacity\(hazard\)\)/);
  assert.match(mapSource, /mgbReferenceImageOverlays: \{ flood: null, landslide: null \}/);
  assert.match(mapSource, /group\.removeLayer\(imageOverlay\)/);
  assert.match(cssSource, /max-height: calc\(100vh - 7\.5rem\)/);
});

check('UiContainsMgbAttributionAndNonRepublicationDisclosure', function () {
  assert.equal(reference.SOURCE_AGENCY, 'Department of Environment and Natural Resources - Mines and Geosciences Bureau (DENR-MGB)');
  assert.match(markupSource, /LIVE MGB REFERENCE/);
  assert.match(markupSource, /This layer is not stored or republished by CIVENTRAL\./);
  assert.match(markupSource, /Department of Environment and Natural Resources - Mines and Geosciences Bureau \(DENR-MGB\)/);
  assert.match(markupSource, /official site assessment/);
  assert.match(mapSource, /attribution: descriptor\.attribution/);
});

check('MgbVeryHighTerminologyRemainsVisibleAndNotCritical', function () {
  ['flood', 'landslide'].forEach(function (hazard) {
    assert.deepEqual(reference.serviceFor(hazard).classifications, ['Low', 'Moderate', 'High', 'Very High']);
  });
  assert.doesNotMatch(referenceSource, /Critical/i);
  assert.match(markupSource, /id="highestRiskLegendLabel">Critical/);
  assert.match(mapSource, /highestLabel\.textContent = sourceLayerActive \? 'Very High' : 'Critical'/);
  assert.match(mapSource, /fillOpacity: 1/);
  assert.match(mapSource, /\['phivolcsReferencePane', 330, false\]/);
  assert.match(markupSource, /Debris flow path\/Possible accumulation zone/);
});

check('BrowserBundleContainsNoServerSecrets', function () {
  const browserBundle = [referenceSource, mapSource, adapterSource, pageSource, markupSource, cssSource].join('\n');
  ['SUPABASE_SECRET_KEY', 'CIVENTRAL_AI_INTERNAL_KEY'].forEach(function (secretName) {
    assert.doesNotMatch(browserBundle, new RegExp(secretName));
  });
  assert.doesNotMatch(browserBundle, /(?:eyJ[A-Za-z0-9_-]{40,}|sb_(?:secret|publishable)_[A-Za-z0-9_-]{20,}|sk-[A-Za-z0-9_-]{40,})/);
});

process.stdout.write('MgbLiveReferenceAssertions=' + assertions + '\n');
process.stdout.write('DrrmMgbLiveReference=PASS\n');
