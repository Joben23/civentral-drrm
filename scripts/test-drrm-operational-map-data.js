'use strict';

const assert = require('node:assert/strict');
const adapter = require('../assets/js/drrm/operational-map-data.js');

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

async function checkAsync(name, callback) {
  try {
    await callback();
    assertions += 1;
    process.stdout.write(name + '=PASS\n');
  } catch (error) {
    process.stderr.write(name + '=FAIL\n');
    throw error;
  }
}

const polygon = {
  type: 'Polygon',
  coordinates: [[
    [120.98, 14.65],
    [120.99, 14.65],
    [120.99, 14.66],
    [120.98, 14.65]
  ]]
};
const line = {
  type: 'LineString',
  coordinates: [[120.98, 14.65], [120.99, 14.66]]
};
const point = { type: 'Point', coordinates: [120.985, 14.655] };

const lookups = {
  success: true,
  data: {
    hazard_types: [
      { hazard_type_id: 1, code: 'FLOOD', name: 'Flood' },
      { hazard_type_id: 2, code: 'LANDSLIDE', name: 'Landslide' }
    ],
    risk_levels: [
      { risk_level_id: 1, code: 'LOW', name: 'Low', severity_rank: 1 },
      { risk_level_id: 4, code: 'CRITICAL', name: 'Critical', severity_rank: 4 }
    ]
  }
};

check('ZeroRowsRemainSuccessfulEmpty', function () {
  const empty = { success: true, data: [] };
  assert.equal(adapter.mapBarangays(empty).features.length, 0);
  assert.equal(adapter.mapFaults(empty).features.length, 0);
  assert.equal(adapter.mapEvacuationCenters(empty, null).features.length, 0);
  assert.equal(adapter.mapEvacuationRoutes(empty, null).features.length, 0);
  const hazards = adapter.mapHazards(empty, lookups);
  assert.equal(hazards.flood.features.length, 0);
  assert.equal(hazards.landslide.features.length, 0);
});

check('EndpointFailureContractIsNotEmpty', function () {
  assert.throws(function () {
    adapter.mapBarangays({ success: false, message: 'Unavailable' });
  }, /Invalid operational endpoint response/);
});

const barangayPayload = {
  success: true,
  data: [{
    barangay_id: '11111111-1111-4111-8111-111111111111',
    barangay_code: '1380100001',
    name: 'Barangay 1',
    district_code: '1',
    boundary_geometry: polygon
  }]
};

check('BarangayGeometryAndLngLatPreserved', function () {
  const mapped = adapter.mapBarangays(barangayPayload);
  assert.strictEqual(mapped.features[0].geometry, polygon);
  assert.deepEqual(mapped.features[0].geometry.coordinates[0][0], [120.98, 14.65]);
  assert.equal(mapped.features[0].properties.name, 'Barangay 1');
});

check('InactiveBarangayFailsClosed', function () {
  assert.throws(function () {
    adapter.mapBarangays({
      success: true,
      data: [Object.assign({}, barangayPayload.data[0], { record_status: 'INACTIVE' })]
    });
  }, /unpublished operational record/);
});

const hazards = adapter.mapHazards({
  success: true,
  data: [
    {
      hazard_type_id: 1,
      risk_level_id: 1,
      geometry: polygon,
      classification_notes: JSON.stringify({
        mgb_flood_code: 'LF',
        mgb_flood_label: 'Low Susceptibility to Flooding',
        source_agency: 'DENR-MGB'
      })
    },
    {
      hazard_type_id: 2,
      risk_level_id: 4,
      geometry: polygon,
      classification_notes: JSON.stringify({
        mgb_landslide_code: 'VHL',
        source_agency: 'DENR-MGB'
      })
    }
  ]
}, lookups);

check('HazardLookupsMapToExistingLayerProperties', function () {
  assert.equal(hazards.flood.features[0].properties.mgb_code, 'LF');
  assert.equal(hazards.flood.features[0].properties.display_risk_label, 'Low');
  assert.equal(hazards.landslide.features[0].properties.mgb_code, 'VHL');
  assert.equal(hazards.landslide.features[0].properties.display_risk_label, 'Very High');
});

const barangays = adapter.mapBarangays(barangayPayload);
const centers = adapter.mapEvacuationCenters({
  success: true,
  data: [{
    evacuation_center_id: '22222222-2222-4222-8222-222222222222',
    name: 'North Center',
    barangay_id: '11111111-1111-4111-8111-111111111111',
    location: point,
    address: 'Caloocan City',
    capacity: 250,
    operational_status: 'AVAILABLE',
    contact_phone: null,
    accessibility_notes: null,
    managing_office_name: 'Caloocan DRRMO'
  }]
}, barangays);

check('PublishedCenterMapsWithoutVisibleUuidFields', function () {
  const properties = centers.features[0].properties;
  assert.equal(properties.name, 'North Center');
  assert.equal(properties.barangay_name, 'Barangay 1');
  assert.equal(properties.display_status, 'AVAILABLE');
  assert.equal(Object.prototype.hasOwnProperty.call(properties, 'evacuation_center_id'), false);
});

check('DraftCenterFailsClosed', function () {
  assert.throws(function () {
    adapter.mapEvacuationCenters({
      success: true,
      data: [Object.assign({}, {
        evacuation_center_id: '22222222-2222-4222-8222-222222222222',
        name: 'Draft Center',
        barangay_id: '11111111-1111-4111-8111-111111111111',
        location: point,
        operational_status: 'AVAILABLE',
        publication_status: 'DRAFT'
      })]
    }, barangays);
  }, /unpublished operational record/);
});

const routes = adapter.mapEvacuationRoutes({
  success: true,
  data: [{
    evacuation_route_id: '33333333-3333-4333-8333-333333333333',
    route_name: 'Approved North Route',
    origin_barangay_id: '11111111-1111-4111-8111-111111111111',
    origin_name: 'Barangay 1 Hall',
    origin_location: point,
    destination_center_id: '22222222-2222-4222-8222-222222222222',
    route_geometry: line,
    distance_meters: 1234,
    safety_notes: 'Follow current officer instructions.'
  }]
}, centers);

check('ApprovedRouteUsesStoredMeterDistance', function () {
  assert.strictEqual(routes.features[0].geometry, line);
  assert.equal(routes.features[0].properties.distance_meters, 1234);
  assert.equal(routes.features[0].properties.destination_name, 'North Center');
});

check('UnapprovedRouteFailsClosed', function () {
  assert.throws(function () {
    adapter.mapEvacuationRoutes({
      success: true,
      data: [{
        route_name: 'Draft Route',
        destination_center_id: '22222222-2222-4222-8222-222222222222',
        route_geometry: line,
        distance_meters: 1,
        route_status: 'DRAFT'
      }]
    }, centers);
  }, /unapproved operational route/);
});

[null, 0, -1].forEach(function (invalidDistance) {
  check('NonPositiveOrNullRouteDistanceFailsClosed_' + String(invalidDistance), function () {
    assert.throws(function () {
      adapter.mapEvacuationRoutes({
        success: true,
        data: [{
          route_name: 'Invalid Distance Route',
          destination_center_id: '22222222-2222-4222-8222-222222222222',
          route_geometry: line,
          distance_meters: invalidDistance
        }]
      }, centers);
    }, /Published route distance is invalid/);
  });
});

check('FaultGeometryMapsToOperationalLines', function () {
  const faults = adapter.mapFaults({
    success: true,
    data: [{ feature_name: 'Mapped Fault', feature_class: 'Active Fault', geometry: line }]
  });
  assert.strictEqual(faults.features[0].geometry, line);
  assert.equal(faults.features[0].properties.fault_name, 'Mapped Fault');
});

async function runEvacuationCenterSourceTests() {
  const emptyOperational = adapter.mapEvacuationCenters({ success: true, data: [] }, null);
  const references = {
    type: 'FeatureCollection',
    features: Array.from({ length: 15 }, function (_, index) {
      return { type: 'Feature', geometry: point, properties: { reference_id: 'reference-' + index } };
    })
  };

  await checkAsync('OperationalZeroRowsTriggerAdminReferenceFallback', async function () {
    let requests = 0;
    const selection = await adapter.resolveEvacuationCenterSource(emptyOperational, async function () {
      requests += 1;
      return references;
    });
    assert.equal(requests, 1);
    assert.equal(selection.sourceMode, 'UNVERIFIED_ADMIN_REFERENCE');
    assert.strictEqual(selection.featureCollection, references);
    assert.equal(selection.featureCollection.features.length, 15);
    assert.notStrictEqual(selection.featureCollection, emptyOperational);
  });

  await checkAsync('PublishedOperationalCentersRetainPriority', async function () {
    let requests = 0;
    const selection = await adapter.resolveEvacuationCenterSource(centers, async function () {
      requests += 1;
      return references;
    });
    assert.equal(requests, 0);
    assert.equal(selection.sourceMode, 'CIVENTRAL_OPERATIONAL');
    assert.strictEqual(selection.featureCollection, centers);
  });

  await checkAsync('MissingAuthorizedReferenceKeepsTruthfulOperationalEmpty', async function () {
    const selection = await adapter.resolveEvacuationCenterSource(emptyOperational, null);
    assert.equal(selection.sourceMode, 'CIVENTRAL_OPERATIONAL');
    assert.strictEqual(selection.featureCollection, emptyOperational);
    assert.equal(selection.adminReferenceAttempted, false);
  });

  await checkAsync('ReferenceEndpointFailureRemainsUnavailable', async function () {
    await assert.rejects(
      adapter.resolveEvacuationCenterSource(emptyOperational, async function () {
        throw new Error('403');
      }),
      /403/
    );
  });
}

async function runBarangaySourceTests() {
  const emptyOperational = adapter.mapBarangays({ success: true, data: [] });
  const operational = adapter.mapBarangays(barangayPayload);
  const references = {
    type: 'FeatureCollection',
    features: Array.from({ length: 187 }, function (_, index) {
      return {
        type: 'Feature',
        geometry: polygon,
        properties: {
          name: 'Barangay ' + (index + 1),
          barangay_code: '1380100' + String(index + 1).padStart(3, '0'),
          reference_status: 'INCOMPLETE ADMIN REFERENCE'
        }
      };
    })
  };

  await checkAsync('OperationalZeroRowsTriggerBarangayReferenceFallback', async function () {
    let requests = 0;
    const selection = await adapter.resolveBarangaySource(emptyOperational, async function () {
      requests += 1;
      return references;
    });
    assert.equal(requests, 1);
    assert.equal(selection.sourceMode, 'INCOMPLETE_ADMIN_REFERENCE');
    assert.strictEqual(selection.featureCollection, references);
    assert.equal(selection.featureCollection.features.length, 187);
  });

  await checkAsync('PublishedOperationalBarangaysRetainPriority', async function () {
    let requests = 0;
    const selection = await adapter.resolveBarangaySource(operational, async function () {
      requests += 1;
      return references;
    });
    assert.equal(requests, 0);
    assert.equal(selection.sourceMode, 'CIVENTRAL_OPERATIONAL');
    assert.strictEqual(selection.featureCollection, operational);
  });
}

runEvacuationCenterSourceTests()
  .then(runBarangaySourceTests).then(function () {
  process.stdout.write('OperationalMapDataAssertions=' + assertions + '\n');
  process.stdout.write('DrrmOperationalMapData=PASS\n');
}).catch(function (error) {
  process.stderr.write(String(error && error.stack ? error.stack : error) + '\n');
  process.exitCode = 1;
});
