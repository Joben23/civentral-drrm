'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const adapter = require('../assets/js/drrm/operational-map-data.js');

const root = path.resolve(__dirname, '..');
const mapPath = path.join(root, 'assets/js/drrm/hazard-evacuation-map.js');
const originalSource = fs.readFileSync(mapPath, 'utf8');
const exportPoint = 'window.CiventralHazardMap = Object.freeze(publicApi);';
const testExport = [
  'publicApi.__test = Object.freeze({',
  '  state: state,',
  '  loadEvacuationCenters: loadDraftEvacuationCenterPreview,',
  '  handleEvacuationCenterControl: handleEvacuationCenterControl,',
  '  evacuationCenterMarkerIcon: evacuationCenterMarkerIcon',
  '});',
  exportPoint
].join('\n');
assert.ok(originalSource.includes(exportPoint), 'Map test export point is missing.');

class FakeElement {
  constructor(tagName) {
    this.tagName = tagName || 'div';
    this.textContent = '';
    this.hidden = true;
    this.children = [];
    this.dataset = {};
    this.className = '';
    this.classList = {
      add: function () {},
      remove: function () {},
      toggle: function () {}
    };
  }

  append() {
    this.children.push.apply(this.children, arguments);
  }

  appendChild(child) {
    this.children.push(child);
    return child;
  }

  replaceChildren() {
    this.children = Array.from(arguments);
  }

  querySelector() {
    return null;
  }

  querySelectorAll() {
    return [];
  }

  setAttribute() {}
  removeAttribute() {}
  addEventListener() {}
}

const elements = new Map();
const document = {
  readyState: 'loading',
  addEventListener: function () {},
  querySelectorAll: function () { return []; },
  createElement: function (tagName) { return new FakeElement(tagName); },
  createTextNode: function (value) { return { textContent: String(value) }; },
  getElementById: function (id) {
    if (!elements.has(id)) elements.set(id, new FakeElement());
    return elements.get(id);
  }
};

function createLayerGroup() {
  const layers = [];
  return {
    addTo: function (map) { map.addLayer(this); return this; },
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

function createMap() {
  const visible = new Set();
  return {
    addLayer: function (layer) { visible.add(layer); return this; },
    removeLayer: function (layer) { visible.delete(layer); return this; },
    hasLayer: function (layer) { return visible.has(layer); }
  };
}

const renderedMarkers = [];
const L = {
  divIcon: function (options) { return options; },
  marker: function (_latlng, options) {
    const element = new FakeElement();
    const marker = {
      options: options,
      bindTooltip: function () { return marker; },
      bindPopup: function () { return marker; },
      on: function () { return marker; },
      getElement: function () { return element; }
    };
    renderedMarkers.push(marker);
    return marker;
  },
  geoJSON: function (collection, options) {
    const layer = {
      markers: [],
      addTo: function (target) { target.addLayer(layer); return layer; }
    };
    collection.features.forEach(function (feature) {
      const marker = options.pointToLayer(feature, {
        lat: feature.geometry.coordinates[1],
        lng: feature.geometry.coordinates[0]
      });
      marker.feature = feature;
      options.onEachFeature(feature, marker);
      layer.markers.push(marker);
    });
    return layer;
  }
};

const window = {
  document: document,
  L: L,
  CiventralDrrmOperationalData: adapter,
  CiventralDrrmMapConfig: {
    dataMode: 'operational',
    operationalData: {
      enabled: true,
      evacuationCentersEndpoint: '../../api/drrm/evacuation-centers.php'
    },
    adminEvacuationCenterReference: {
      enabled: true,
      endpoint: '../../api/drrm/admin-evacuation-center-reference.php'
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
  Option: function Option(label, value) { this.textContent = label; this.value = value; },
  ResizeObserver: function ResizeObserver() {},
  console: console,
  setTimeout: setTimeout,
  clearTimeout: clearTimeout
});
context.globalThis = context;
vm.runInContext(originalSource.replace(exportPoint, testExport), context, { filename: mapPath });

const hooks = window.CiventralHazardMap.__test;
const state = hooks.state;

function resetCenterState() {
  renderedMarkers.length = 0;
  state.map = createMap();
  state.layerGroups = { evacuationCenters: createLayerGroup() };
  state.evacuationCenterPreviewLoaded = false;
  state.evacuationCenterPreviewFeatureCount = 0;
  state.evacuationCenterPreviewLayer = null;
  state.evacuationCenterFeatureCollection = null;
  state.operationalEvacuationCenterFeatureCollection = null;
  state.evacuationCenterLoadPromise = null;
  state.evacuationCenterFetchCount = 0;
  state.evacuationCenterSourceMode = 'NOT_ACTIVE';
  state.evacuationCenterLoadedSourceMode = null;
  state.selectedEvacuationCenterLayer = null;
}

const references = {
  type: 'FeatureCollection',
  features: Array.from({ length: 15 }, function (_, index) {
    return {
      type: 'Feature',
      geometry: { type: 'Point', coordinates: [121.01 + (index / 1000), 14.65 + (index / 1000)] },
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
  resetCenterState();
  const requests = [];
  window.fetch = async function (url, options) {
    requests.push({ url: url, options: options });
    return String(url).includes('admin-evacuation-center-reference')
      ? { ok: true, json: async function () { return references; } }
      : { ok: true, json: async function () { return { success: true, data: [] }; } };
  };

  assert.equal(await hooks.loadEvacuationCenters(), true);
  assert.deepEqual(requests.map(function (request) { return request.url; }), [
    '../../api/drrm/evacuation-centers.php',
    '../../api/drrm/admin-evacuation-center-reference.php'
  ]);
  requests.forEach(function (request) {
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(String(request.url).includes('/api/drrm/dev/'), false);
  });
  assert.equal(state.evacuationCenterSourceMode, 'UNVERIFIED_ADMIN_REFERENCE');
  assert.equal(state.evacuationCenterPreviewFeatureCount, 15);
  assert.equal(state.operationalEvacuationCenterFeatureCollection.features.length, 0);
  assert.notStrictEqual(state.evacuationCenterFeatureCollection, state.operationalEvacuationCenterFeatureCollection);
  assert.equal(renderedMarkers.length, 15);
  renderedMarkers.forEach(function (marker) {
    assert.match(marker.options.icon.className, /is-reference/);
  });

  const checkbox = { checked: true, disabled: false };
  await hooks.handleEvacuationCenterControl(checkbox);
  assert.equal(checkbox.disabled, false);
  assert.equal(state.map.hasLayer(state.layerGroups.evacuationCenters), true);
  const retainedCollection = state.evacuationCenterFeatureCollection;
  checkbox.checked = false;
  await hooks.handleEvacuationCenterControl(checkbox);
  assert.equal(state.map.hasLayer(state.layerGroups.evacuationCenters), false);
  assert.strictEqual(state.evacuationCenterFeatureCollection, retainedCollection);
  assert.equal(state.layerGroups.evacuationCenters.getLayers().length, 1);
  assert.equal(state.evacuationCenterSourceMode, 'UNVERIFIED_ADMIN_REFERENCE');

  resetCenterState();
  let referenceRequests = 0;
  window.fetch = async function (url) {
    if (String(url).includes('admin-evacuation-center-reference')) referenceRequests += 1;
    return {
      ok: true,
      json: async function () {
        return {
          success: true,
          data: [{
            evacuation_center_id: '22222222-2222-4222-8222-222222222222',
            name: 'Published Center',
            barangay_id: '11111111-1111-4111-8111-111111111111',
            location: { type: 'Point', coordinates: [121.02, 14.66] },
            publication_status: 'PUBLISHED',
            operational_status: 'AVAILABLE'
          }]
        };
      }
    };
  };
  assert.equal(await hooks.loadEvacuationCenters(), true);
  assert.equal(referenceRequests, 0);
  assert.equal(state.evacuationCenterSourceMode, 'CIVENTRAL_OPERATIONAL');
  assert.equal(state.evacuationCenterPreviewFeatureCount, 1);

  resetCenterState();
  window.fetch = async function (url) {
    return String(url).includes('admin-evacuation-center-reference')
      ? { ok: false, json: async function () { return {}; } }
      : { ok: true, json: async function () { return { success: true, data: [] }; } };
  };
  const unavailableCheckbox = { checked: true, disabled: false };
  await hooks.handleEvacuationCenterControl(unavailableCheckbox);
  assert.equal(unavailableCheckbox.checked, false);
  assert.equal(unavailableCheckbox.disabled, true);
  assert.equal(state.evacuationCenterSourceMode, 'CENTER_REFERENCE_UNAVAILABLE');
  assert.equal(state.operationalEvacuationCenterFeatureCollection.features.length, 0);

  process.stdout.write('AdminCenterReferenceUiAssertions=PASS\n');
  process.stdout.write('DrrmAdminCenterReferenceUi=PASS\n');
}

run().catch(function (error) {
  process.stderr.write(String(error && error.stack ? error.stack : error) + '\n');
  process.exitCode = 1;
});
