(function () {
  'use strict';

  const CONFIG = Object.freeze({
    containerId: 'civentralHazardMap',
    center: [14.706, 121.02],
    initialZoom: 11.5
  });

  const state = {
    map: null,
    layerGroups: null,
    resizeObserver: null,
    resizeFrame: null,
    draftPreviewLoaded: false,
    draftPreviewFeatureCount: 0,
    draftPreviewBounds: null,
    draftPreviewLayer: null,
    selectedBarangayLayer: null,
    cityBaseLayer: null,
    cityBoundaryLayer: null,
    cityBoundaryBounds: null,
    cityGeometryType: null,
    cityComponentCount: 0,
    cityComponentBounds: null,
    operationalMaxBounds: null,
    operationalMinZoom: null,
    focusControl: null,
    focusButtons: null
  };

  function createLayerGroups() {
    return Object.freeze({
      barangays: L.layerGroup(),
      floodHazards: L.layerGroup(),
      landslideHazards: L.layerGroup(),
      earthquakeFaults: L.layerGroup(),
      evacuationCenters: L.layerGroup(),
      evacuationRoutes: L.layerGroup(),
      riskPredictions: L.layerGroup()
    });
  }

  function setStatus(elementId, message) {
    const element = document.getElementById(elementId);
    if (element) element.textContent = message;
  }

  function showMapUnavailable() {
    const message = document.getElementById('mapUnavailableMessage');
    if (message) message.classList.remove('hidden');
  }

  function getDraftPreviewConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    const previewConfig = runtimeConfig && runtimeConfig.draftBarangayPreview;

    if (!previewConfig || previewConfig.enabled !== true || typeof previewConfig.endpoint !== 'string') {
      return null;
    }

    return previewConfig;
  }

  function getCityBoundaryConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    const boundaryConfig = runtimeConfig && runtimeConfig.cityBoundary;

    if (!boundaryConfig || typeof boundaryConfig.endpoint !== 'string') {
      return null;
    }

    return boundaryConfig;
  }

  function isDarkMode() {
    return document.documentElement.classList.contains('dark');
  }

  function cityBaseStyle() {
    return {
      stroke: false,
      fill: true,
      fillColor: isDarkMode() ? '#4c1d24' : '#fecaca',
      fillOpacity: isDarkMode() ? 0.34 : 0.3
    };
  }

  function cityBoundaryStyle() {
    return {
      color: isDarkMode() ? '#f87171' : '#dc2626',
      weight: 3.5,
      opacity: 1,
      fill: false,
      lineCap: 'round',
      lineJoin: 'round'
    };
  }

  function draftBarangayStyle() {
    return {
      color: isDarkMode() ? '#64748b' : '#94a3b8',
      weight: 0.9,
      opacity: 0.95,
      fill: true,
      fillColor: isDarkMode() ? '#1e293b' : '#f8fafc',
      fillOpacity: 0.88,
      lineCap: 'round',
      lineJoin: 'round'
    };
  }

  function draftBarangayHoverStyle() {
    return {
      color: isDarkMode() ? '#7dd3fc' : '#176b87',
      weight: 1.7,
      opacity: 1,
      fillColor: isDarkMode() ? '#176b87' : '#86b6f6',
      fillOpacity: 0.68
    };
  }

  function draftBarangaySelectedStyle() {
    return {
      color: isDarkMode() ? '#bae6fd' : '#0e5f78',
      weight: 2.4,
      opacity: 1,
      fillColor: isDarkMode() ? '#0284c7' : '#60a5fa',
      fillOpacity: 0.74
    };
  }

  function refreshThematicStyles() {
    if (state.cityBaseLayer) state.cityBaseLayer.setStyle(cityBaseStyle());
    if (state.cityBoundaryLayer) state.cityBoundaryLayer.setStyle(cityBoundaryStyle());
    if (state.draftPreviewLayer) state.draftPreviewLayer.setStyle(draftBarangayStyle());
    if (state.selectedBarangayLayer) state.selectedBarangayLayer.setStyle(draftBarangaySelectedStyle());
  }

  function createCityContextPanes() {
    const panes = [
      ['cityBasePane', 300, false],
      ['barangayPane', 320, true],
      ['hazardPolygonPane', 350, true],
      ['cityOutlinePane', 390, false],
      ['operationalLinePane', 420, true]
    ];

    panes.forEach(function (paneDefinition) {
      const pane = state.map.getPane(paneDefinition[0]) || state.map.createPane(paneDefinition[0]);
      pane.style.zIndex = String(paneDefinition[1]);
      pane.style.pointerEvents = paneDefinition[2] ? '' : 'none';
    });
  }

  function assertCaloocanCityBoundary(payload) {
    if (
      !payload || payload.type !== 'FeatureCollection' ||
      !Array.isArray(payload.features) || payload.features.length !== 1
    ) {
      throw new Error('Invalid Caloocan city boundary response.');
    }

    const feature = payload.features[0];
    const geometry = feature && feature.geometry;
    const properties = feature && feature.properties;

    if (
      !feature || feature.type !== 'Feature' ||
      !properties || properties.adm3_name !== 'Caloocan City' ||
      !geometry || !['Polygon', 'MultiPolygon'].includes(geometry.type) ||
      !Array.isArray(geometry.coordinates) || geometry.coordinates.length === 0
    ) {
      throw new Error('Unexpected Caloocan city boundary feature.');
    }

    const polygons = geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.coordinates;
    polygons.forEach(function (polygon) {
      if (!Array.isArray(polygon) || !Array.isArray(polygon[0]) || polygon[0].length < 4) {
        throw new Error('Invalid Caloocan city boundary polygon.');
      }
    });

    return payload;
  }

  function deriveCityComponentBounds(cityBoundary) {
    const geometry = cityBoundary.features[0].geometry;
    const polygons = geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.coordinates;

    if (polygons.length !== 2) {
      throw new Error('Expected separate North and South Caloocan boundary components.');
    }

    const componentBounds = polygons.map(function (polygon) {
      const bounds = L.latLngBounds([]);

      polygon.forEach(function (ring) {
        ring.forEach(function (position) {
          if (
            !Array.isArray(position) || position.length < 2 ||
            !Number.isFinite(position[0]) || !Number.isFinite(position[1])
          ) {
            throw new Error('Invalid Caloocan boundary coordinate.');
          }

          bounds.extend([position[1], position[0]]);
        });
      });

      if (!bounds.isValid()) {
        throw new Error('Invalid Caloocan component bounds.');
      }

      return bounds;
    });

    componentBounds.sort(function (first, second) {
      return second.getCenter().lat - first.getCenter().lat;
    });

    return Object.freeze({
      north: componentBounds[0],
      south: componentBounds[1]
    });
  }

  function setActiveMapFocus(focusKey) {
    if (!state.focusButtons) return;

    Object.keys(state.focusButtons).forEach(function (key) {
      state.focusButtons[key].setAttribute('aria-pressed', key === focusKey ? 'true' : 'false');
    });
  }

  function focusMapArea(focusKey) {
    if (!state.map || !state.cityBoundaryBounds || !state.cityComponentBounds) return false;

    const boundsByFocus = {
      whole: state.cityBoundaryBounds,
      north: state.cityComponentBounds.north,
      south: state.cityComponentBounds.south
    };
    const bounds = boundsByFocus[focusKey];

    if (!bounds || !bounds.isValid()) return false;

    state.map.fitBounds(bounds, {
      padding: focusKey === 'whole' ? [24, 24] : [30, 30],
      maxZoom: focusKey === 'whole' ? 13 : 15,
      animate: false
    });
    setActiveMapFocus(focusKey);
    return true;
  }

  function createMapFocusControl() {
    if (!state.map || !state.cityComponentBounds || state.focusControl) return;

    const FocusControl = L.Control.extend({
      options: { position: 'topright' },
      onAdd: function () {
        const container = L.DomUtil.create('div', 'civ-map-focus-control');
        const focusDefinitions = [
          ['whole', 'Whole Caloocan'],
          ['north', 'North'],
          ['south', 'South']
        ];

        container.setAttribute('role', 'group');
        container.setAttribute('aria-label', 'Caloocan map focus');
        state.focusButtons = {};

        focusDefinitions.forEach(function (definition) {
          const focusKey = definition[0];
          const button = L.DomUtil.create('button', 'civ-map-focus-button', container);
          button.type = 'button';
          button.textContent = definition[1];
          button.setAttribute('aria-pressed', focusKey === 'whole' ? 'true' : 'false');
          button.addEventListener('click', function () {
            focusMapArea(focusKey);
          });
          state.focusButtons[focusKey] = button;
        });

        L.DomEvent.disableClickPropagation(container);
        L.DomEvent.disableScrollPropagation(container);
        return container;
      }
    });

    state.focusControl = new FocusControl();
    state.focusControl.addTo(state.map);
  }

  function configureOperationalViewport(fitWholeCity) {
    if (!state.map || !state.cityBoundaryBounds || !state.cityBoundaryBounds.isValid()) return false;

    const maxBounds = state.cityBoundaryBounds.pad(0.08);
    const minimumZoom = state.map.getBoundsZoom(
      state.cityBoundaryBounds,
      false,
      L.point(24, 24)
    );

    state.operationalMaxBounds = maxBounds;
    state.operationalMinZoom = minimumZoom;
    state.map.options.maxBoundsViscosity = 1.0;
    state.map.setMaxBounds(maxBounds);
    state.map.setMinZoom(minimumZoom);

    if (fitWholeCity) {
      focusMapArea('whole');
    } else if (state.map.getZoom() < minimumZoom) {
      state.map.setZoom(minimumZoom, { animate: false });
    }

    return true;
  }

  function showCityBoundaryError() {
    const message = document.getElementById('mapUnavailableMessage');
    if (message) {
      const title = message.querySelector('strong');
      const description = message.querySelector('span');
      if (title) title.textContent = 'Caloocan boundary unavailable';
      if (description) description.textContent = 'Unable to load Caloocan boundary.';
      message.classList.remove('hidden');
    }
    setStatus('operationalMapSubtitle', 'Unable to load Caloocan boundary.');
  }

  async function loadCaloocanCityContext() {
    const boundaryConfig = getCityBoundaryConfig();
    if (!boundaryConfig || !state.map) {
      showCityBoundaryError();
      return false;
    }

    try {
      const response = await window.fetch(boundaryConfig.endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/geo+json, application/json' }
      });

      if (!response.ok) {
        throw new Error('Caloocan boundary request failed.');
      }

      const cityBoundary = assertCaloocanCityBoundary(await response.json());

      state.cityBaseLayer = L.geoJSON(cityBoundary, {
        pane: 'cityBasePane',
        interactive: false,
        style: cityBaseStyle
      }).addTo(state.map);

      state.cityBoundaryLayer = L.geoJSON(cityBoundary, {
        pane: 'cityOutlinePane',
        interactive: false,
        style: cityBoundaryStyle
      }).addTo(state.map);

      state.map.invalidateSize({ pan: false });
      state.cityBoundaryBounds = state.cityBoundaryLayer.getBounds();
      state.cityGeometryType = cityBoundary.features[0].geometry.type;
      state.cityComponentCount = state.cityGeometryType === 'Polygon'
        ? 1
        : cityBoundary.features[0].geometry.coordinates.length;
      state.cityComponentBounds = deriveCityComponentBounds(cityBoundary);

      if (!configureOperationalViewport(true)) {
        throw new Error('Caloocan boundary bounds are invalid.');
      }

      createMapFocusControl();

      setStatus('operationalMapSubtitle', 'Polygon-first Caloocan view. Use Whole, North, or South to change focus.');
      return true;
    } catch (error) {
      showCityBoundaryError();
      return false;
    }
  }

  function setDraftPreviewUiLoaded() {
    const baselineNotice = document.getElementById('polygonMapNotice');
    const previewNotice = document.getElementById('draftBarangayPreviewNotice');
    const statusBadge = document.getElementById('mapDataStatusBadge');

    if (baselineNotice) baselineNotice.classList.add('hidden');
    if (previewNotice) previewNotice.classList.remove('hidden');
    if (statusBadge) {
      statusBadge.title = 'Local development preview only. The barangay-boundary dataset remains incomplete and unpublished.';
    }

    setStatus('mapDataStatusText', 'Map Data Status: Draft Preview');
    if (state.cityBoundaryBounds) {
      setStatus('operationalMapSubtitle', 'Inspecting 187 validated draft barangay boundaries; the current city layer is incomplete.');
    }
    setStatus('barangaySearchStatus', '187 draft boundaries loaded. Search remains disconnected.');
  }

  function setDraftPreviewUiError() {
    const previewNotice = document.getElementById('draftBarangayPreviewNotice');

    if (previewNotice) {
      previewNotice.classList.remove('hidden');
      const message = previewNotice.querySelector('p');
      if (message) {
        message.textContent = 'Development Preview: Draft barangay boundaries could not be loaded.';
      }
    }
  }

  function scheduleMapResize() {
    if (!state.map) return;
    if (state.resizeFrame) window.cancelAnimationFrame(state.resizeFrame);

    state.resizeFrame = window.requestAnimationFrame(function () {
      state.map.invalidateSize({ pan: false });
      if (state.cityBoundaryBounds) configureOperationalViewport(false);
      state.resizeFrame = null;
    });
  }

  function bindResponsiveResize(container) {
    window.addEventListener('resize', scheduleMapResize, { passive: true });
    window.addEventListener('civentralThemeChanged', function () {
      refreshThematicStyles();
      scheduleMapResize();
    });

    if ('ResizeObserver' in window) {
      state.resizeObserver = new ResizeObserver(scheduleMapResize);
      state.resizeObserver.observe(container);
    }
  }

  function bindBarangaySearch() {
    const form = document.getElementById('barangaySearchForm');
    const input = document.getElementById('barangaySearchInput');
    if (!form || !input) return;

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      const query = input.value.trim();
      setStatus(
        'barangaySearchStatus',
        query ? 'Barangay search is not yet connected.' : 'Enter a barangay name to search.'
      );
    });

    input.addEventListener('input', function () {
      if (!input.value.trim()) setStatus('barangaySearchStatus', 'Barangay records are not yet connected.');
    });
  }

  function bindLayerControls() {
    const controls = document.querySelectorAll('[data-map-layer]');

    controls.forEach(function (control) {
      control.addEventListener('change', function () {
        const layerKey = control.dataset.mapLayer;
        const layerGroup = state.layerGroups ? state.layerGroups[layerKey] : null;

        if (state.map && layerGroup) {
          if (control.checked) {
            layerGroup.addTo(state.map);
          } else {
            state.map.removeLayer(layerGroup);
          }
        }

        setStatus(
          'hazardLayerStatus',
          control.checked
            ? 'Hazard dataset not yet connected.'
            : 'Hazard datasets are not yet connected.'
        );
      });
    });
  }

  function bindPreparednessTabs() {
    const tabs = Array.from(document.querySelectorAll('.civ-preparedness-tab[role="tab"]'));
    if (!tabs.length) return;
    if (tabs[0].dataset.preparednessTabsBound === 'true') return;

    tabs.forEach(function (tab) {
      tab.dataset.preparednessTabsBound = 'true';
    });

    function activateTab(activeTab, moveFocus) {
      tabs.forEach(function (tab) {
        const isActive = tab === activeTab;
        const panelId = tab.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : null;

        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        tab.tabIndex = isActive ? 0 : -1;
        if (panel) panel.hidden = !isActive;
      });

      if (moveFocus) activeTab.focus();
    }

    tabs.forEach(function (tab, index) {
      tab.addEventListener('click', function () {
        activateTab(tab, false);
      });

      tab.addEventListener('keydown', function (event) {
        let nextIndex = null;

        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
          nextIndex = (index + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
          nextIndex = (index - 1 + tabs.length) % tabs.length;
        } else if (event.key === 'Home') {
          nextIndex = 0;
        } else if (event.key === 'End') {
          nextIndex = tabs.length - 1;
        }

        if (nextIndex !== null) {
          event.preventDefault();
          activateTab(tabs[nextIndex], true);
        }
      });
    });
  }

  function createDraftPopup(properties) {
    const content = document.createElement('div');
    const name = document.createElement('strong');
    const code = document.createElement('div');
    const status = document.createElement('div');

    name.textContent = properties.name;
    code.textContent = 'PSGC code: ' + properties.barangay_code;
    status.textContent = 'Draft boundary preview';
    content.append(name, code, status);

    return content;
  }

  function showDraftLocationDetails(properties) {
    const details = document.getElementById('locationDetailsContent');
    if (!details) return;

    const name = document.createElement('strong');
    const code = document.createElement('div');
    const status = document.createElement('div');

    name.textContent = properties.name;
    code.textContent = 'PSGC code: ' + properties.barangay_code;
    status.textContent = 'Draft boundary preview';
    details.replaceChildren(name, code, status);
  }

  function selectDraftBarangay(layer, properties) {
    if (state.selectedBarangayLayer && state.selectedBarangayLayer !== layer) {
      state.selectedBarangayLayer.setStyle(draftBarangayStyle());
    }

    state.selectedBarangayLayer = layer;
    layer.setStyle(draftBarangaySelectedStyle());
    if (typeof layer.bringToFront === 'function') layer.bringToFront();
    showDraftLocationDetails(properties);
  }

  function assertDraftFeatureCollection(payload) {
    if (!payload || payload.type !== 'FeatureCollection' || !Array.isArray(payload.features)) {
      throw new Error('Invalid draft GeoJSON response.');
    }

    if (payload.features.length !== 187) {
      throw new Error('Unexpected draft feature count.');
    }

    payload.features.forEach(function (feature) {
      const properties = feature && feature.properties;
      const geometry = feature && feature.geometry;

      if (
        !feature || feature.type !== 'Feature' ||
        !properties || properties.preview_status !== 'DRAFT_INCOMPLETE' ||
        typeof properties.name !== 'string' || typeof properties.barangay_code !== 'string' ||
        !geometry || !['Polygon', 'MultiPolygon'].includes(geometry.type) ||
        !Array.isArray(geometry.coordinates) || geometry.coordinates.length === 0
      ) {
        throw new Error('Invalid draft GeoJSON feature.');
      }
    });

    return payload;
  }

  async function loadDraftBarangayPreview() {
    const previewConfig = getDraftPreviewConfig();
    if (!previewConfig || !state.map || !state.layerGroups || state.draftPreviewLoaded) return false;

    try {
      const response = await window.fetch(previewConfig.endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/geo+json, application/json' }
      });

      if (!response.ok) {
        throw new Error('Draft preview request failed.');
      }

      const featureCollection = assertDraftFeatureCollection(await response.json());
      const previewLayer = L.geoJSON(featureCollection, {
        pane: 'barangayPane',
        style: draftBarangayStyle,
        onEachFeature: function (feature, layer) {
          layer.bindPopup(createDraftPopup(feature.properties));
          layer.on('mouseover', function () {
            if (state.selectedBarangayLayer !== layer) layer.setStyle(draftBarangayHoverStyle());
            if (typeof layer.bringToFront === 'function') layer.bringToFront();
          });
          layer.on('mouseout', function () {
            layer.setStyle(
              state.selectedBarangayLayer === layer
                ? draftBarangaySelectedStyle()
                : draftBarangayStyle()
            );
          });
          layer.on('click', function () {
            selectDraftBarangay(layer, feature.properties);
          });
        }
      });

      state.layerGroups.barangays.clearLayers();
      previewLayer.addTo(state.layerGroups.barangays);

      const bounds = previewLayer.getBounds();
      if (!bounds.isValid()) {
        throw new Error('Draft preview bounds are invalid.');
      }

      state.draftPreviewBounds = bounds;
      state.draftPreviewLayer = previewLayer;
      state.draftPreviewLoaded = true;
      state.draftPreviewFeatureCount = featureCollection.features.length;
      setDraftPreviewUiLoaded();
      return true;
    } catch (error) {
      setDraftPreviewUiError();
      return false;
    }
  }

  async function initializeMapContext() {
    await loadCaloocanCityContext();
    await loadDraftBarangayPreview();
  }

  function paneForLayer(layerKey) {
    const paneByLayer = {
      barangays: 'barangayPane',
      floodHazards: 'hazardPolygonPane',
      landslideHazards: 'hazardPolygonPane',
      riskPredictions: 'hazardPolygonPane',
      earthquakeFaults: 'operationalLinePane',
      evacuationRoutes: 'operationalLinePane',
      evacuationCenters: 'markerPane'
    };

    return paneByLayer[layerKey] || 'hazardPolygonPane';
  }

  function replaceGeoJsonLayer(layerKey, featureCollection, options) {
    const layerGroup = state.layerGroups ? state.layerGroups[layerKey] : null;
    if (!state.map || !layerGroup || !featureCollection || featureCollection.type !== 'FeatureCollection') {
      return false;
    }

    const layerOptions = Object.assign({}, options || {}, { pane: paneForLayer(layerKey) });
    layerGroup.clearLayers();
    L.geoJSON(featureCollection, layerOptions).addTo(layerGroup);
    return true;
  }

  const dataHooks = Object.freeze({
    loadBarangays: function (featureCollection, options) {
      return replaceGeoJsonLayer('barangays', featureCollection, options);
    },
    loadGeoJSON: replaceGeoJsonLayer,
    loadHazardLayer: function (layerKey, featureCollection, options) {
      const supportedLayers = ['floodHazards', 'landslideHazards', 'earthquakeFaults'];
      return supportedLayers.includes(layerKey)
        ? replaceGeoJsonLayer(layerKey, featureCollection, options)
        : false;
    },
    loadEvacuationCenters: function (featureCollection, options) {
      return replaceGeoJsonLayer('evacuationCenters', featureCollection, options);
    },
    loadRoutes: function (featureCollection, options) {
      return replaceGeoJsonLayer('evacuationRoutes', featureCollection, options);
    },
    loadRiskOverlay: function (featureCollection, options) {
      return replaceGeoJsonLayer('riskPredictions', featureCollection, options);
    },
    displayFloodRiskPrediction: function (predictionText, modelStatus) {
      if (!predictionText) {
        setStatus('floodForecastContent', 'Risk prediction is not yet connected.');
        setStatus('floodModelStatus', 'Not Integrated');
        return false;
      }

      setStatus('floodForecastContent', String(predictionText));
      setStatus('floodModelStatus', modelStatus ? String(modelStatus) : 'Connected');
      return true;
    }
  });

  function getDiagnostics() {
    const container = document.getElementById(CONFIG.containerId);
    const attribution = container ? container.querySelector('.leaflet-control-attribution') : null;
    const tileLayerCount = container
      ? container.querySelectorAll('.leaflet-tile-pane > .leaflet-layer').length
      : 0;

    function paneZIndex(paneName) {
      const pane = state.map ? state.map.getPane(paneName) : null;
      return pane ? Number(pane.style.zIndex) : null;
    }

    return Object.freeze({
      mapInitialized: Boolean(state.map),
      mode: 'POLYGON_ONLY',
      tileLayerCount: tileLayerCount,
      tileImageCount: container ? container.querySelectorAll('.leaflet-tile-pane img').length : 0,
      osmAttributionPresent: Boolean(
        attribution &&
        (attribution.textContent || '').toLowerCase().includes(['open', 'street', 'map'].join(''))
      ),
      cityBoundaryLoaded: Boolean(state.cityBoundaryBounds),
      cityGeometryType: state.cityGeometryType,
      cityComponentCount: state.cityComponentCount,
      draftBarangayCount: state.draftPreviewFeatureCount,
      currentZoom: state.map ? state.map.getZoom() : null,
      minZoom: state.map ? state.map.getMinZoom() : state.operationalMinZoom,
      paneZIndexes: Object.freeze({
        cityBase: paneZIndex('cityBasePane'),
        barangay: paneZIndex('barangayPane'),
        hazardPolygon: paneZIndex('hazardPolygonPane'),
        cityOutline: paneZIndex('cityOutlinePane'),
        operationalLine: paneZIndex('operationalLinePane'),
        marker: 600
      }),
      maximumBoundsConfigured: Boolean(state.operationalMaxBounds),
      maxBoundsViscosity: state.map ? state.map.options.maxBoundsViscosity : null
    });
  }

  function init() {
    const container = document.getElementById(CONFIG.containerId);
    if (!container || state.map) return state.map;
    if (container._leaflet_id) return null;

    bindPreparednessTabs();

    if (typeof window.L === 'undefined') {
      showMapUnavailable();
      return null;
    }

    state.layerGroups = createLayerGroups();
    state.map = L.map(container, {
      center: CONFIG.center,
      zoom: CONFIG.initialZoom,
      zoomSnap: 0.5,
      zoomControl: true,
      attributionControl: true,
      maxBoundsViscosity: 1.0
    });

    createCityContextPanes();
    state.layerGroups.barangays.addTo(state.map);
    state.layerGroups.evacuationRoutes.addTo(state.map);
    state.layerGroups.riskPredictions.addTo(state.map);

    bindBarangaySearch();
    bindLayerControls();
    bindResponsiveResize(container);
    window.setTimeout(scheduleMapResize, 120);
    initializeMapContext();

    return state.map;
  }

  window.CiventralHazardMap = Object.freeze({
    init: init,
    refresh: scheduleMapResize,
    focus: focusMapArea,
    diagnostics: getDiagnostics,
    dataHooks: dataHooks
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
