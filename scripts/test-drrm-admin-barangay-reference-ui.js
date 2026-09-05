'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const adapter = require('../assets/js/drrm/operational-map-data.js');

const root = path.resolve(__dirname, '..');
const mapPath = path.join(root, 'assets/js/drrm/hazard-evacuation-map.js');
const source = fs.readFileSync(mapPath, 'utf8');
const exportPoint = 'window.CiventralHazardMap = Object.freeze(publicApi);';
const testExport = [
  'publicApi.__test = Object.freeze({',
  '  state: state,',
  '  loadBarangays: loadDraftBarangayPreview,',
  '  renderSearch: renderBarangaySuggestions,',
  '  selectBarangay: selectDraftBarangay',
  '});',
  exportPoint
].join('\n');

class FakeElement {
  constructor() {
    this.textContent = '';
    this.hidden = false;
    this.children = [];
    this.dataset = {};
    this.value = '';
    this.classList = { add() {}, remove() {}, toggle() {} };
  }
  append() { this.children.push(...arguments); }
  appendChild(child) { this.children.push(child); return child; }
  replaceChildren() { this.children = Array.from(arguments); }
  querySelector() { return null; }
  querySelectorAll() { return []; }
  setAttribute() {}
  removeAttribute() {}
  addEventListener() {}
}

const elements = new Map();
const document = {
  readyState: 'loading',
  documentElement: { classList: { contains() { return false; } } },
  addEventListener() {},
  querySelectorAll() { return []; },
  createElement() { return new FakeElement(); },
  createTextNode(value) { return { textContent: String(value) }; },
  getElementById(id) {
    if (!elements.has(id)) elements.set(id, new FakeElement());
    return elements.get(id);
  }
};

function layerGroup() {
  const layers = [];
  return {
    addLayer(layer) { layers.push(layer); return this; },
    clearLayers() { layers.length = 0; },
    getLayers() { return layers.slice(); }
  };
}

const map = {
  closePopup() {},
  fitBounds() {},
  hasLayer() { return false; },
  addLayer() {},
  removeLayer() {}
};

let tooltipCount = 0;
const L = {
  geoJSON(collection, options) {
    const layer = {
      features: collection.features,
      featureLayers: [],
      addTo(target) { target.addLayer(layer); return layer; },
      getBounds() { return { isValid() { return true; } }; }
    };
    collection.features.forEach(function (feature) {
      const featureLayer = {
        feature,
        setStyle() {},
        bringToFront() {},
        getBounds() { return { isValid() { return true; } }; },
        bindPopup() { return featureLayer; },
        bindTooltip() { tooltipCount += 1; return featureLayer; },
        on(event, handler) {
          featureLayer.handlers = featureLayer.handlers || {};
          featureLayer.handlers[event] = handler;
          return featureLayer;
        }
      };
      options.onEachFeature(feature, featureLayer);
      layer.featureLayers.push(featureLayer);
    });
    return layer;
  }
};

const window = {
  document,
  L,
  CiventralDrrmOperationalData: adapter,
  CiventralDrrmMapConfig: {
    dataMode: 'operational',
    operationalData: {
      enabled: true,
      barangaysEndpoint: '/api/drrm/barangays.php'
    },
    adminBarangayReference: {
      enabled: true,
      endpoint: '/api/drrm/admin-barangay-reference.php'
    }
  },
  addEventListener() {},
  requestAnimationFrame() { return 1; },
  cancelAnimationFrame() {}
};
const context = vm.createContext({
  window,
  document,
  L,
  Option: function () {},
  ResizeObserver: function () {},
  console,
  setTimeout,
  clearTimeout
});
context.globalThis = context;
vm.runInContext(source.replace(exportPoint, testExport), context, { filename: mapPath });

const hooks = window.CiventralHazardMap.__test;
const state = hooks.state;
state.map = map;
state.layerGroups = { barangays: layerGroup() };

const references = {
  type: 'FeatureCollection',
  features: Array.from({ length: 188 }, function (_, index) {
    const number = index + 1;
    if (number === 176) return null;
    return {
      type: 'Feature',
      geometry: { type: 'Polygon', coordinates: [[[121, 14.6], [121.001, 14.6], [121.001, 14.601], [121, 14.6]]] },
      properties: {
        name: 'Barangay ' + number,
        barangay_code: '138010' + String(number).padStart(4, '0'),
        reference_status: 'INCOMPLETE ADMIN REFERENCE'
      }
    };
  }).filter(Boolean)
};

async function run() {
  window.fetch = async function (url) {
    return String(url).includes('admin-barangay-reference')
      ? { ok: true, json: async function () { return references; } }
      : { ok: true, json: async function () { return { success: true, data: [] }; } };
  };

  const loaded = await hooks.loadBarangays();
  assert.equal(loaded, true);
  assert.equal(state.draftPreviewFeatureCount, 187);
  assert.equal(state.searchableBarangays.length, 187);
  assert.equal(state.layerGroups.barangays.getLayers().length, 1);
  assert.equal(tooltipCount, 187);
  assert.equal(state.searchableBarangays.some(record => record.properties.name === 'Barangay 131'), true);

  const matches = hooks.renderSearch('Barangay 131');
  assert.equal(matches.length, 1);
  hooks.selectBarangay(matches[0]);
  assert.equal(state.selectedBarangayRecord.properties.name, 'Barangay 131');
  assert.equal(elements.get('locationDetailsContent').children.length, 3);
  assert.equal(elements.get('locationDetailsContent').children[0].textContent, 'Barangay 131');
  assert.equal(elements.get('locationDetailsContent').children[1].textContent.startsWith('PSGC code:'), true);
  assert.equal(elements.get('locationDetailsContent').children[2].textContent, 'INCOMPLETE ADMIN REFERENCE');

  const clickedLayer = state.draftPreviewLayer.featureLayers.find(function (layer) {
    return layer.feature.properties.name === 'Barangay 131';
  });
  clickedLayer.handlers.click({});
  assert.equal(state.selectedBarangayRecord.properties.name, 'Barangay 131');
  assert.equal(elements.get('locationDetailsContent').children[0].textContent, 'Barangay 131');

  process.stdout.write('AdminBarangayReferenceUiAssertions=PASS\n');
  process.stdout.write('DrrmAdminBarangayReferenceUi=PASS\n');
}

run().catch(function (error) {
  process.stderr.write(String(error && error.stack ? error.stack : error) + '\n');
  process.exitCode = 1;
});
