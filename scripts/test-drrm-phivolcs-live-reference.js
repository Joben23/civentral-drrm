'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const reference = require('../assets/js/drrm/phivolcs-live-reference.js');

const root = path.resolve(__dirname, '..');
const referenceSource = fs.readFileSync(
  path.join(root, 'assets/js/drrm/phivolcs-live-reference.js'),
  'utf8'
);
const mapSource = fs.readFileSync(
  path.join(root, 'assets/js/drrm/hazard-evacuation-map.js'),
  'utf8'
);
const pageSource = fs.readFileSync(
  path.join(root, 'pages/drrm/hazard-evacuation-map.php'),
  'utf8'
);
const markupSource = fs.readFileSync(
  path.join(root, 'includes/dashboard/hazard-evacuation-map.php'),
  'utf8'
);

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

const officialMapServer = 'https://gisweb.phivolcs.dost.gov.ph/arcgis/rest/services/PHIVOLCSPublic/ActiveFault/MapServer';
const officialWms = 'https://gisweb.phivolcs.dost.gov.ph/arcgis/services/PHIVOLCSPublic/ActiveFault/MapServer/WMSServer';

check('ExactOfficialPhivolcsPublicActiveFaultService', function () {
  assert.equal(reference.MAP_SERVER_URL, officialMapServer);
  assert.equal(reference.WMS_URL, officialWms);
  assert.equal(reference.service().mapServerUrl, officialMapServer);
  assert.equal(reference.service().wmsUrl, officialWms);
  assert.equal(reference.service().layers, '0');
  assert.equal(reference.service().attribution, 'DOST-PHIVOLCS');
  [
    officialMapServer.replace('PHIVOLCSPublic', 'PHIVOLCS'),
    officialMapServer.replace('https:', 'http:'),
    officialMapServer + '/0/query'
  ].forEach(function (url) {
    assert.throws(function () {
      reference.assertOfficialMapServerUrl(url);
    }, /Untrusted|Unsafe/);
  });
});

check('OperationalFaultDataAlwaysHasPriority', function () {
  assert.equal(
    reference.selectOperationalOrReference(1, true),
    reference.SOURCE_MODES.OPERATIONAL
  );
  assert.equal(
    reference.selectOperationalOrReference(156, true),
    reference.SOURCE_MODES.OPERATIONAL
  );
  assert.equal(
    reference.selectOperationalOrReference(0, true),
    reference.SOURCE_MODES.PHIVOLCS_REFERENCE
  );
  assert.equal(
    reference.selectOperationalOrReference(0, false),
    reference.SOURCE_MODES.UNAVAILABLE
  );
  assert.throws(function () {
    reference.selectOperationalOrReference(-1, true);
  }, /non-negative integer/);
});

check('ZeroOperationalRowsEnableImageOnlyReference', function () {
  const handlerStart = mapSource.indexOf('async function handleFaultInformationControl');
  const handlerEnd = mapSource.indexOf('function assertDraftEvacuationCenterFeatureCollection', handlerStart);
  const handler = mapSource.slice(handlerStart, handlerEnd);
  assert.ok(handlerStart >= 0 && handlerEnd > handlerStart);
  assert.match(handler, /loadDraftFaultInformation\(\)/);
  assert.match(handler, /state\.faultPreviewFeatureCount === 0/);
  assert.match(handler, /selectOperationalOrReference\(0, true\)/);
  assert.match(handler, /activatePhivolcsReferenceLayer\(control\)/);
  assert.ok(
    handler.indexOf('loadDraftFaultInformation()')
      < handler.indexOf('activatePhivolcsReferenceLayer(control)')
  );
});

check('PhivolcsReferenceUsesWmsAndCannotPersistRawGeometry', function () {
  const activationStart = mapSource.indexOf('async function activatePhivolcsReferenceLayer');
  const activationEnd = mapSource.indexOf('function assertDraftFaultPreview', activationStart);
  const activation = mapSource.slice(activationStart, activationEnd);
  assert.ok(activationStart >= 0 && activationEnd > activationStart);
  assert.match(activation, /L\.tileLayer\.wms\(descriptor\.wmsUrl/);
  assert.doesNotMatch(activation, /fetch\s*\(|FeatureServer|\/query|geoJSON\s*\(|method\s*:\s*['"]POST/i);
  assert.doesNotMatch(referenceSource, /supabase|\.insert\s*\(|\.upsert\s*\(|method\s*:\s*['"]POST/i);
  assert.doesNotMatch(pageSource, /api\/drrm\/dev\/.*phivolcs/i);
});

check('TruthfulFindingFailureAndAttributionRemainVisible', function () {
  const summary = reference.referenceSummary();
  assert.equal(summary.crosses_caloocan, false);
  assert.equal(summary.advisory, 'No mapped active fault in this dataset intersects Caloocan.');
  assert.match(summary.nearest_fault_notice, /Approximate reference only/);
  assert.match(summary.nearest_fault_notice, /does not cross Caloocan/);
  assert.equal(
    reference.failureState().message,
    'Official PHIVOLCS fault reference is temporarily unavailable.'
  );
  assert.match(markupSource, /PHIVOLCS LIVE REFERENCE/);
  assert.match(markupSource, /DOST-PHIVOLCS/);
  assert.match(markupSource, /The West Valley Fault does not cross Caloocan\./);
  assert.match(mapSource, /Approximate nearest mapped fault reference/);
});

process.stdout.write('PhivolcsLiveReferenceAssertions=' + assertions + '\n');
process.stdout.write('DrrmPhivolcsLiveReference=PASS\n');
