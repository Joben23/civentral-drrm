'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const mapPath = path.join(root, 'assets/js/drrm/hazard-evacuation-map.js');
const originalSource = fs.readFileSync(mapPath, 'utf8');
const pageSource = fs.readFileSync(path.join(root, 'pages/drrm/hazard-evacuation-map.php'), 'utf8');
const markupSource = fs.readFileSync(path.join(root, 'includes/dashboard/hazard-evacuation-map.php'), 'utf8');
const exportPoint = 'window.CiventralHazardMap = Object.freeze(publicApi);';
const testExport = [
  'publicApi.__test = Object.freeze({',
  '  state: state,',
  '  getEvacuationRoutePreviewConfig: getEvacuationRoutePreviewConfig,',
  '  populateRouteCenterOptions: populateRouteCenterOptions,',
  '  activateRouteOriginSelectionMode: activateRouteOriginSelectionMode,',
  '  handleMapPointSelection: handleMapPointSelection,',
  '  findDevelopmentEvacuationRoute: findDevelopmentEvacuationRoute,',
  '  initializeEvacuationRouteTool: initializeEvacuationRouteTool,',
  '  configureAdminPlanningRouteUi: configureAdminPlanningRouteUi,',
  '  getAdminFloodReferenceCheckConfig: getAdminFloodReferenceCheckConfig,',
  '  initializeFloodForecastTool: initializeFloodForecastTool,',
  '  setForecastLocationSelectionActive: setForecastLocationSelectionActive,',
  '  setForecastLocation: setForecastLocation,',
  '  checkFloodReference: checkFloodReference,',
  '  clearForecastLocation: clearForecastLocation',
  '});',
  exportPoint
].join('\n');
assert.ok(originalSource.includes(exportPoint), 'Map test export point is missing.');

class FakeElement {
  constructor(tagName) {
    this.tagName = tagName || 'div';
    this._text = '';
    this.children = [];
    this.dataset = {};
    this.hidden = false;
    this.disabled = false;
    this.value = '';
    this.attributes = new Map();
    this.listeners = new Map();
    this.className = '';
    const classes = new Set();
    this.classList = {
      add: function (value) { classes.add(value); },
      remove: function (value) { classes.delete(value); },
      toggle: function (value, active) {
        if (active) classes.add(value);
        else classes.delete(value);
      },
      contains: function (value) { return classes.has(value); }
    };
  }

  get textContent() {
    return this._text + this.children.map(function (child) {
      return child && typeof child.textContent === 'string' ? child.textContent : '';
    }).join('');
  }

  set textContent(value) {
    this._text = String(value);
    this.children = [];
  }

  append() {
    this.children.push.apply(this.children, arguments);
  }

  appendChild(child) {
    this.children.push(child);
    return child;
  }

  replaceChildren() {
    this._text = '';
    this.children = Array.from(arguments);
  }

  querySelector(selector) {
    if (selector === 'span' && this.labelElement) return this.labelElement;
    return null;
  }

  querySelectorAll() { return []; }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  getAttribute(name) { return this.attributes.get(name) || null; }
  removeAttribute(name) { this.attributes.delete(name); }
  addEventListener(type, handler) { this.listeners.set(type, handler); }
  contains(child) { return child === this || this.children.includes(child); }
  focus() {}
}

const elements = new Map();
function element(id) {
  if (!elements.has(id)) elements.set(id, new FakeElement());
  return elements.get(id);
}

const previewButton = element('findSafeRouteButton');
previewButton.labelElement = new FakeElement('span');
element('setForecastLocationButton').labelElement = new FakeElement('span');
element('checkFloodReferenceButton').labelElement = new FakeElement('span');
element('routeCenterSelect').parentElement = new FakeElement();

const document = {
  readyState: 'loading',
  addEventListener: function () {},
  querySelectorAll: function () { return []; },
  createElement: function (tagName) { return new FakeElement(tagName); },
  createTextNode: function (value) {
    const node = new FakeElement('#text');
    node.textContent = value;
    return node;
  },
  getElementById: element
};

function createLayerGroup() {
  const layers = [];
  return {
    addTo: function () { return this; },
    addLayer: function (layer) { layers.push(layer); return this; },
    removeLayer: function (layer) {
      const index = layers.indexOf(layer);
      if (index >= 0) layers.splice(index, 1);
      return this;
    },
    clearLayers: function () { layers.length = 0; },
    getLayers: function () { return layers.slice(); }
  };
}

const mapState = { fitBoundsCount: 0, popupCloseCount: 0 };
const fakeMap = {
  fitBounds: function () { mapState.fitBoundsCount += 1; },
  closePopup: function () { mapState.popupCloseCount += 1; }
};

function validBounds() {
  return { isValid: function () { return true; } };
}

const renderedMarkers = [];
const L = {
  divIcon: function (options) { return options; },
  marker: function (latlng, options) {
    const marker = {
      latlng: latlng,
      options: options,
      bindTooltip: function () { return marker; },
      addTo: function (group) { group.addLayer(marker); return marker; },
      getLatLng: function () { return { lat: latlng[0], lng: latlng[1] }; }
    };
    renderedMarkers.push(marker);
    return marker;
  },
  geoJSON: function (feature, options) {
    const layer = {
      feature: feature,
      options: options,
      addTo: function (group) { group.addLayer(layer); return layer; },
      getBounds: validBounds,
      setStyle: function () {}
    };
    return layer;
  },
  DomEvent: { stopPropagation: function () {} }
};

document.documentElement = new FakeElement('html');

const window = {
  document: document,
  L: L,
  turf: {
    point: function (coordinates) { return { geometry: { coordinates: coordinates } }; },
    booleanPointInPolygon: function (point) {
      const coordinates = point.geometry.coordinates;
      return coordinates[0] >= 120.9 && coordinates[0] <= 121.2
        && coordinates[1] >= 14.6 && coordinates[1] <= 14.9;
    }
  },
  CiventralDrrmMapConfig: {
    dataMode: 'operational',
    operationalData: {
      enabled: true,
      evacuationCentersEndpoint: '../../api/drrm/evacuation-centers.php',
      evacuationRoutesEndpoint: '../../api/drrm/evacuation-routes.php'
    },
    adminEvacuationCenterReference: {
      enabled: true,
      endpoint: '../../api/drrm/admin-evacuation-center-reference.php'
    },
    adminEvacuationRoutePreview: {
      enabled: true,
      endpoint: '../../api/drrm/admin-evacuation-route-preview.php',
      csrfToken: 'module-1-route-csrf-test-token'
    },
    adminFloodReferenceCheck: {
      enabled: true,
      endpoint: '../../api/drrm/admin-flood-reference-check.php',
      csrfToken: 'module-1-flood-csrf-test-token'
    }
  },
  CiventralDrrmOperationalData: {
    mapEvacuationCenters: function () {
      return { type: 'FeatureCollection', features: [] };
    },
    resolveEvacuationCenterSource: async function (operational, loadReference) {
      if (operational.features.length > 0) {
        return { featureCollection: operational, sourceMode: 'CIVENTRAL_OPERATIONAL' };
      }
      return {
        featureCollection: await loadReference(),
        sourceMode: 'UNVERIFIED_ADMIN_REFERENCE'
      };
    },
    mapEvacuationRoutes: function () {
      return { type: 'FeatureCollection', features: [] };
    }
  },
  addEventListener: function () {},
  setTimeout: function () {},
  requestAnimationFrame: function () { return 1; },
  cancelAnimationFrame: function () {}
};

const context = vm.createContext({
  window: window,
  document: document,
  L: L,
  Option: function Option(label, value) {
    const option = new FakeElement('option');
    option.textContent = label;
    option.value = value;
    return option;
  },
  ResizeObserver: function ResizeObserver() {},
  console: console,
  setTimeout: setTimeout,
  clearTimeout: clearTimeout
});
context.globalThis = context;
vm.runInContext(originalSource.replace(exportPoint, testExport), context, { filename: mapPath });

const hooks = window.CiventralHazardMap.__test;
const state = hooks.state;
state.map = fakeMap;
state.layerGroups = {
  evacuationCenters: createLayerGroup(),
  evacuationRoutes: createLayerGroup(),
  floodCheckLocations: createLayerGroup()
};
state.cityBoundaryFeatureCollection = {
  type: 'FeatureCollection',
  features: [{
    type: 'Feature',
    properties: {},
    geometry: {
      type: 'Polygon',
      coordinates: [[[120.9, 14.6], [121.2, 14.6], [121.2, 14.9], [120.9, 14.9], [120.9, 14.6]]]
    }
  }]
};

const references = {
  type: 'FeatureCollection',
  features: Array.from({ length: 15 }, function (_, index) {
    return {
      type: 'Feature',
      geometry: {
        type: 'Point',
        coordinates: [121.01 + (index / 1000), 14.65 + (index / 1000)]
      },
      properties: {
        reference_id: '72983eab-0000-4000-8000-' + String(index + 1).padStart(12, '0'),
        name: 'Reference Center ' + (index + 1),
        location_status: 'APPROXIMATE_REFERENCE_LOCATION',
        barangay_display_location: 'Barangay ' + (index + 1),
        managing_office: 'City Government of Caloocan',
        verification_status: 'PENDING_LGU_VERIFICATION',
        display_status: 'UNVERIFIED CENTER REFERENCE'
      }
    };
  })
};
async function run() {
  const config = hooks.getEvacuationRoutePreviewConfig();
  assert.equal(config.adminPlanning, true);
  assert.equal(config.endpoint, '../../api/drrm/admin-evacuation-route-preview.php');
  assert.equal(config.endpoint.includes('/api/drrm/dev/'), false);

  const initializationRequests = [];
  window.fetch = async function (url, options) {
    initializationRequests.push({ url: url, options: options });
    if (String(url).includes('admin-evacuation-center-reference')) {
      return { ok: true, json: async function () { return references; } };
    }
    return { ok: true, json: async function () { return { success: true, data: [] }; } };
  };
  assert.equal(await hooks.initializeEvacuationRouteTool(), true);
  assert.deepEqual(initializationRequests.map(function (request) { return request.url; }), [
    '../../api/drrm/evacuation-centers.php',
    '../../api/drrm/admin-evacuation-center-reference.php',
    '../../api/drrm/evacuation-routes.php'
  ]);
  assert.equal(state.operationalRouteStatus, 'EMPTY');
  assert.equal(state.operationalRouteFeatureCount, 0);
  assert.strictEqual(state.adminEvacuationCenterReferenceFeatureCollection, references);
  assert.notStrictEqual(
    state.adminEvacuationCenterReferenceFeatureCollection,
    state.operationalEvacuationCenterFeatureCollection
  );
  assert.equal(element('evacuationRoutePanel').dataset.routeMode, 'ADMIN_PLANNING_PREVIEW');
  assert.match(element('preparednessConnectionStatus').textContent, /ADMIN PLANNING PREVIEW/);
  assert.equal(previewButton.labelElement.textContent, 'Preview Route');
  assert.match(element('routeDisclosure').textContent, /Planning preview only/);

  const centerSelect = element('routeCenterSelect');
  assert.equal(centerSelect.children.length, 16);
  assert.equal(centerSelect.children[0].textContent, 'Select an unverified center reference');
  assert.equal(state.routeCentersById.size, 15);
  assert.equal(state.routeCentersById.has(references.features[0].properties.reference_id), true);

  assert.equal(hooks.activateRouteOriginSelectionMode(), true);
  assert.equal(state.mapSelectionMode, 'ROUTE_ORIGIN_SELECTION');
  assert.equal(hooks.handleMapPointSelection({ latlng: { lat: 100, lng: 121.02 } }, 'MAP'), false);
  assert.equal(state.routeOriginLastResult, 'INVALID_COORDINATES');
  assert.equal(state.mapSelectionMode, 'ROUTE_ORIGIN_SELECTION');

  assert.equal(
    hooks.handleMapPointSelection({ latlng: { lat: 14.7663938, lng: 121.0607398 } }, 'MAP'),
    true
  );
  assert.equal(state.mapSelectionMode, 'NONE');
  assert.equal(state.routeOriginLastResult, 'POINT_ACCEPTED');
  assert.equal(state.routeOriginMarker !== null, true);
  assert.equal(element('routeStartInput').value, '14.766394, 121.060740');
  const mapClickCount = state.routeOriginMapClickCount;
  assert.equal(
    hooks.handleMapPointSelection({ latlng: { lat: 14.75, lng: 121.03 } }, 'MAP'),
    false
  );
  assert.equal(state.routeOriginMapClickCount, mapClickCount);

  const selected = references.features[0];
  const selectedId = selected.properties.reference_id;
  state.selectedEvacuationCenterId = selectedId;
  centerSelect.value = selectedId;
  previewButton.disabled = false;
  const requests = [];
  window.fetch = async function (url, options) {
    requests.push({ url: url, options: options });
    return {
      ok: true,
      json: async function () {
        return {
          success: true,
          data: {
            status: 'ADMIN_PLANNING_PREVIEW',
            geometry: {
              type: 'LineString',
              coordinates: [
                [121.0607398, 14.7663938],
                selected.geometry.coordinates
              ]
            },
            distance_meters: 4250,
            duration_seconds: 540,
            destination_name: selected.properties.name
          }
        };
      }
    };
  };

  await hooks.findDevelopmentEvacuationRoute();
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, '../../api/drrm/admin-evacuation-route-preview.php');
  assert.equal(requests[0].options.method, 'POST');
  assert.equal(requests[0].options.credentials, 'same-origin');
  assert.equal(requests[0].options.headers['Content-Type'], 'application/json');
  assert.equal(
    requests[0].options.headers['X-CSRF-Token'],
    'module-1-route-csrf-test-token'
  );
  const submitted = JSON.parse(requests[0].options.body);
  assert.deepEqual(Object.keys(submitted).sort(), ['evacuation_center_reference_id', 'origin']);
  assert.equal(submitted.evacuation_center_reference_id, selectedId);
  assert.deepEqual(Object.keys(submitted.origin).sort(), ['latitude', 'longitude']);
  assert.equal(Object.prototype.hasOwnProperty.call(submitted, 'destination'), false);
  assert.equal(requests.some(function (request) {
    return /flood|landslide/i.test(String(request.url));
  }), false);

  assert.equal(state.routeOriginMarker !== null, true);
  assert.equal(state.routeDestinationMarker !== null, true);
  assert.equal(state.routeGeometryRendered, true);
  assert.equal(state.routePresentationMode, 'ADMIN_PLANNING_PREVIEW');
  assert.equal(state.routeDistanceMeters, 4250);
  assert.equal(state.routeDurationSeconds, 540);
  assert.equal(state.routeHazardScore, null);
  assert.equal(state.routeFloodExposure, null);
  assert.equal(state.routeLandslideExposure, null);
  assert.equal(state.layerGroups.evacuationRoutes.getLayers().length, 3);
  assert.equal(mapState.fitBoundsCount, 1);
  assert.equal(previewButton.labelElement.textContent, 'Preview Route');
  assert.equal(element('routeRequestStatus').textContent, 'Admin planning route preview displayed.');

  const resultText = element('routeResultContent').textContent;
  assert.match(resultText, /Admin Planning Preview/);
  assert.match(resultText, /Distance: 4.25 km/);
  assert.match(resultText, /Estimated road travel: 9 min/);
  assert.match(resultText, /selected center is unverified/);
  assert.doesNotMatch(resultText, /Find Safe Route|Recommended Official Route|Development Recommended Route/);

  const floodConfig = hooks.getAdminFloodReferenceCheckConfig();
  assert.equal(floodConfig.endpoint, '../../api/drrm/admin-flood-reference-check.php');
  assert.equal(floodConfig.csrfToken, 'module-1-flood-csrf-test-token');
  assert.equal(floodConfig.endpoint.includes('/api/drrm/dev/'), false);
  assert.equal(await hooks.initializeFloodForecastTool(), true);

  const floodSetButton = element('setForecastLocationButton');
  floodSetButton.listeners.get('click')({ preventDefault: function () {} });
  assert.equal(state.mapSelectionMode, 'FLOOD_CHECK_LOCATION_SELECTION');
  assert.equal(floodSetButton.labelElement.textContent, 'Click Inside Caloocan');

  assert.equal(
    hooks.handleMapPointSelection({ latlng: { lat: 100, lng: 121.06 } }, 'MAP'),
    true
  );
  assert.equal(state.mapSelectionMode, 'FLOOD_CHECK_LOCATION_SELECTION');
  assert.equal(
    element('forecastLocationStatus').textContent,
    'Please select a location inside Caloocan City.'
  );

  assert.equal(
    hooks.handleMapPointSelection({ latlng: { lat: 14.766, lng: 121.064 } }, 'MAP'),
    true
  );
  assert.equal(state.mapSelectionMode, 'NONE');
  assert.equal(state.forecastLocation.latitude, 14.766);
  assert.equal(state.forecastLocation.longitude, 121.064);
  assert.equal(state.forecastLocationMarker !== null, true);
  assert.equal(state.layerGroups.floodCheckLocations.getLayers().length, 1);
  assert.equal(element('forecastLocationInput').value, 'Location selected');
  assert.equal(element('checkFloodReferenceButton').disabled, false);
  assert.equal(
    hooks.handleMapPointSelection({ latlng: { lat: 14.767, lng: 121.065 } }, 'MAP'),
    false
  );

  const routeLayerCountBeforeFloodCheck = state.layerGroups.evacuationRoutes.getLayers().length;
  const routeOriginBeforeFloodCheck = state.routeOrigin;
  const floodRequests = [];
  window.fetch = async function (url, options) {
    floodRequests.push({ url: url, options: options });
    return {
      ok: true,
      status: 200,
      json: async function () {
        return {
          success: true,
          status: 'DRAFT_ADMIN_REFERENCE',
          intersection: true,
          classification: 'HIGH',
          risk_rank: 3,
          overlap_count: 2,
          multiple_reference_polygons: true,
          location: { latitude: 14.766, longitude: 121.064 },
          reference: {
            hazard_type: 'FLOOD',
            source_status: 'DRAFT_ADMIN_REFERENCE',
            source_organization: 'DENR-MGB'
          }
        };
      }
    };
  };

  assert.equal(await hooks.checkFloodReference(), true);
  assert.equal(floodRequests.length, 1);
  assert.equal(floodRequests[0].url, '../../api/drrm/admin-flood-reference-check.php');
  assert.equal(floodRequests[0].options.method, 'POST');
  assert.equal(floodRequests[0].options.credentials, 'same-origin');
  assert.equal(floodRequests[0].options.headers['Content-Type'], 'application/json');
  assert.equal(
    floodRequests[0].options.headers['X-CSRF-Token'],
    'module-1-flood-csrf-test-token'
  );
  const floodBody = JSON.parse(floodRequests[0].options.body);
  assert.deepEqual(Object.keys(floodBody).sort(), ['latitude', 'longitude']);
  assert.equal(floodBody.latitude, 14.766);
  assert.equal(floodBody.longitude, 121.064);
  assert.equal(Object.prototype.hasOwnProperty.call(floodBody, 'classification'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(floodBody, 'polygon'), false);

  const floodResult = element('floodReferenceCheckResult');
  assert.equal(floodResult.hidden, false);
  assert.equal(floodResult.dataset.classification, 'HIGH');
  assert.match(floodResult.textContent, /Flood Reference Check/);
  assert.match(floodResult.textContent, /Reference ClassificationHIGH/);
  assert.match(floodResult.textContent, /DRAFT ADMIN REFERENCE/);
  assert.match(floodResult.textContent, /DENR-MGB/);
  assert.match(floodResult.textContent, /2 controlled reference polygons overlap/);
  assert.match(floodResult.textContent, /not a TensorFlow prediction/);
  assert.strictEqual(state.routeOrigin, routeOriginBeforeFloodCheck);
  assert.equal(state.layerGroups.evacuationRoutes.getLayers().length, routeLayerCountBeforeFloodCheck);

  await hooks.setForecastLocation(14.770, 121.06074, 'MAP_CLICK');
  assert.equal(floodResult.hidden, true);
  assert.equal(state.floodReferenceCheckResult, null);
  assert.strictEqual(state.routeOrigin, routeOriginBeforeFloodCheck);
  window.fetch = async function () {
    return {
      ok: true,
      status: 200,
      json: async function () {
        return {
          success: true,
          status: 'NO_MAPPED_REFERENCE_INTERSECTION',
          intersection: false,
          location: { latitude: 14.770, longitude: 121.06074 },
          reference: {
            hazard_type: 'FLOOD',
            source_status: 'DRAFT_ADMIN_REFERENCE',
            source_organization: 'DENR-MGB'
          }
        };
      }
    };
  };
  assert.equal(await hooks.checkFloodReference(), true);
  assert.match(floodResult.textContent, /NO MAPPED REFERENCE INTERSECTION/);
  assert.match(floodResult.textContent, /No mapped flood susceptibility polygon intersects/);
  assert.match(floodResult.textContent, /does not mean the location is flood-safe/);
  assert.doesNotMatch(floodResult.textContent, /LOW RISK/);

  const selectedBarangaySentinel = { properties: { name: 'Barangay sentinel' } };
  state.selectedBarangayRecord = selectedBarangaySentinel;
  hooks.clearForecastLocation();
  assert.equal(state.forecastLocation, null);
  assert.equal(state.forecastLocationMarker, null);
  assert.equal(state.layerGroups.floodCheckLocations.getLayers().length, 0);
  assert.equal(floodResult.hidden, true);
  assert.equal(element('forecastLocationInput').value, 'No location selected');
  assert.equal(element('checkFloodReferenceButton').disabled, true);
  assert.strictEqual(state.routeOrigin, routeOriginBeforeFloodCheck);
  assert.strictEqual(state.selectedBarangayRecord, selectedBarangaySentinel);
  assert.equal(state.layerGroups.evacuationRoutes.getLayers().length, routeLayerCountBeforeFloodCheck);

  const centerHandlerStart = originalSource.indexOf(
    'onEachFeature: function (feature, layer) {',
    originalSource.indexOf('async function loadDraftEvacuationCenterPreview')
  );
  const centerHandler = originalSource.slice(centerHandlerStart, centerHandlerStart + 900);
  assert.ok(centerHandler.indexOf('L.DomEvent.stopPropagation') >= 0);
  assert.ok(centerHandler.indexOf('selectEvacuationCenter') > centerHandler.indexOf('L.DomEvent.stopPropagation'));
  assert.equal(centerHandler.includes('delegateFeatureClickToMapPointSelection'), false);

  const barangayHandlerStart = originalSource.indexOf('state.barangayInteractionLayer = L.geoJSON');
  const barangayHandler = originalSource.slice(barangayHandlerStart, barangayHandlerStart + 2500);
  assert.ok(barangayHandler.includes('if (delegateFeatureClickToMapPointSelection(event)) return;'));
  assert.ok(barangayHandler.includes('selectDraftBarangay(record);'));
  assert.ok(originalSource.includes('layer.bindTooltip(feature.properties.name'));
  assert.ok(originalSource.includes('state.map.on(\'click\''));
  const floodRequestStart = originalSource.indexOf('async function checkFloodReference()');
  const floodRequestEnd = originalSource.indexOf('async function setForecastLocation', floodRequestStart);
  const floodRequestSource = originalSource.slice(floodRequestStart, floodRequestEnd);
  assert.ok(floodRequestSource.includes('admin-flood-reference-check.php') === false);
  assert.ok(floodRequestSource.includes('booleanPointInPolygon') === false);
  assert.ok(floodRequestSource.includes("'X-CSRF-Token'"));
  assert.ok(originalSource.includes('FLOOD_CHECK_LOCATION_SELECTION'));
  assert.ok(originalSource.includes('handleFloodControl(control)'));
  assert.ok(originalSource.includes('handleLandslideControl(control)'));
  assert.ok(originalSource.includes('handleFaultInformationControl(control)'));
  assert.ok(markupSource.includes('Check Flood Reference'));
  assert.ok(markupSource.includes('NO MAPPED REFERENCE INTERSECTION') === false);
  assert.ok(markupSource.includes('AI Flood Prediction'));
  assert.ok(markupSource.includes('Not available'));
  assert.ok(markupSource.includes('runFloodAiPredictionButton') === false);
  assert.ok(pageSource.includes('runtimeConfig.adminFloodReferenceCheck.enabled === true'));
  assert.ok(pageSource.indexOf('runtimeConfig.adminFloodReferenceCheck.enabled === true')
    < pageSource.indexOf('if (state.initialized || !enhancePredictionSection()) return;'));

  process.stdout.write('AdminEvacuationRoutePreviewUiAssertions=PASS\n');
  process.stdout.write('AdminFloodReferenceCheckUiAssertions=PASS\n');
  process.stdout.write('DrrmAdminEvacuationRoutePreviewUi=PASS\n');
}

run().catch(function (error) {
  process.stderr.write(String(error && error.stack ? error.stack : error) + '\n');
  process.exitCode = 1;
});
