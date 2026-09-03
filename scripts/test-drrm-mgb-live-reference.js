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
});

check('LiveReferencesUseCachedTilesNotRawFeatures', function () {
  ['flood', 'landslide'].forEach(function (hazard) {
    const service = reference.serviceFor(hazard);
    assert.equal(service.tileUrlTemplate, service.mapServerUrl + '/tile/{z}/{y}/{x}');
    assert.deepEqual(service.nativeZoomRange, { minimum: 6, maximum: 14 });
    assert.doesNotMatch(service.tileUrlTemplate, /FeatureServer|\/query(?:[/?]|$)/i);
  });
  assert.equal((referenceSource.match(/FeatureServer/g) || []).length, 1);
  assert.match(referenceSource, /parsed\.pathname\.includes\('\/FeatureServer'\)/);
  assert.doesNotMatch(referenceSource, /mapServerUrl:\s*[^\n]*FeatureServer|\/query(?:[/?'"`]|$)/i);
});

check('LiveReferencePathCannotPersistMgbFeatures', function () {
  const activationStart = mapSource.indexOf('async function activateMgbReferenceLayer');
  const activationEnd = mapSource.indexOf('function createFloodTooltip', activationStart);
  const activationSource = mapSource.slice(activationStart, activationEnd);
  assert.ok(activationStart >= 0 && activationEnd > activationStart);
  assert.doesNotMatch(referenceSource, /supabase|fetch\s*\(|\.insert\s*\(|\.upsert\s*\(|method\s*:\s*['"]POST/i);
  assert.doesNotMatch(activationSource, /supabase|fetch\s*\(|geoJSON\s*\(|FeatureServer|method\s*:\s*['"]POST/i);
  assert.match(activationSource, /L\.tileLayer\(descriptor\.tileUrlTemplate/);
});

check('StagingGuardCannotUseDevelopmentEndpointsOrLocalhost', function () {
  assert.match(pageSource, /mgbLiveReference:[\s\S]*enabled:\s*<\?php echo \$draftBarangayPreviewEnabled \? 'false' : 'true'; \?>/);
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
  assert.match(mapSource, /tileLayer\.on\('tileerror'/);
  assert.match(mapSource, /control\.disabled = true/);
  assert.match(mapSource, /removeMgbReferenceLayer\(hazard, false\)/);
  assert.doesNotMatch(mapSource, /fake polygon|fallback.*draft/i);
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
