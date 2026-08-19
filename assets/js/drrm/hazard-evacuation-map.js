(function () {
  'use strict';

  const CONFIG = Object.freeze({
    containerId: 'civentralHazardMap',
    center: [14.706, 121.02],
    initialZoom: 11.5,
    maximumSearchSuggestions: 8
  });

  const DEFAULT_LOCATION_DETAILS = 'Select a barangay, hazard area, or evacuation center from the map to view information.';
  const FLOOD_CLASSIFICATIONS = Object.freeze({
    LF: Object.freeze({ label: 'Low Susceptibility to Flooding', displayLabel: 'Low', color: '#16a34a' }),
    MF: Object.freeze({ label: 'Moderate Susceptibility to Flooding', displayLabel: 'Moderate', color: '#eab308' }),
    HF: Object.freeze({ label: 'High Susceptibility to Flooding', displayLabel: 'High', color: '#f97316' }),
    VHF: Object.freeze({ label: 'Very High Susceptibility to Flooding', displayLabel: 'Very High', color: '#dc2626' })
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
    selectedBarangayRecord: null,
    searchableBarangays: [],
    searchMatches: [],
    activeSuggestionIndex: -1,
    floodPreviewLoaded: false,
    floodPreviewFeatureCount: 0,
    floodPreviewLayer: null,
    floodPreviewFeatureCollection: null,
    floodPreviewClassCounts: null,
    floodLoadPromise: null,
    floodFetchCount: 0,
    selectedFloodLayer: null,
    evacuationCenterPreviewLoaded: false,
    evacuationCenterPreviewFeatureCount: 0,
    evacuationCenterPreviewLayer: null,
    evacuationCenterFeatureCollection: null,
    evacuationCenterLoadPromise: null,
    evacuationCenterFetchCount: 0,
    selectedEvacuationCenterLayer: null,
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

  function getFloodPreviewConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    const previewConfig = runtimeConfig && runtimeConfig.draftFloodPreview;

    if (!previewConfig || previewConfig.enabled !== true || typeof previewConfig.endpoint !== 'string') {
      return null;
    }

    return previewConfig;
  }

  function getEvacuationCenterPreviewConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    const previewConfig = runtimeConfig && runtimeConfig.draftEvacuationCenterPreview;

    if (!previewConfig || previewConfig.enabled !== true || typeof previewConfig.endpoint !== 'string') {
      return null;
    }

    return previewConfig;
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

  function floodFeatureStyle(feature) {
    const code = feature && feature.properties ? feature.properties.mgb_code : '';
    const classification = FLOOD_CLASSIFICATIONS[code] || FLOOD_CLASSIFICATIONS.LF;

    return {
      color: classification.color,
      weight: 1.25,
      opacity: 0.95,
      fill: true,
      fillColor: classification.color,
      fillOpacity: isDarkMode() ? 0.46 : 0.4,
      lineCap: 'round',
      lineJoin: 'round'
    };
  }

  function floodFeatureHoverStyle(feature) {
    const style = floodFeatureStyle(feature);
    style.weight = 2.25;
    style.opacity = 1;
    style.fillOpacity = isDarkMode() ? 0.62 : 0.56;
    return style;
  }

  function floodFeatureSelectedStyle(feature) {
    const style = floodFeatureStyle(feature);
    style.weight = 2.75;
    style.opacity = 1;
    style.fillOpacity = isDarkMode() ? 0.68 : 0.62;
    return style;
  }

  function refreshThematicStyles() {
    if (state.cityBaseLayer) state.cityBaseLayer.setStyle(cityBaseStyle());
    if (state.cityBoundaryLayer) state.cityBoundaryLayer.setStyle(cityBoundaryStyle());
    if (state.draftPreviewLayer) state.draftPreviewLayer.setStyle(draftBarangayStyle());
    if (state.selectedBarangayLayer) state.selectedBarangayLayer.setStyle(draftBarangaySelectedStyle());
    if (state.floodPreviewLayer) state.floodPreviewLayer.setStyle(floodFeatureStyle);
    if (state.selectedFloodLayer) {
      state.selectedFloodLayer.setStyle(floodFeatureSelectedStyle(state.selectedFloodLayer.feature));
    }
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
    setStatus('barangaySearchStatus', '187 validated draft barangays available for search.');
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

  function normalizeBarangayName(value) {
    return String(value || '')
      .trim()
      .replace(/\s+/g, ' ')
      .toLocaleLowerCase('en-US');
  }

  function barangayNumber(name) {
    const match = /^barangay\s+(\d+)$/i.exec(String(name || '').trim());
    return match ? Number(match[1]) : Number.MAX_SAFE_INTEGER;
  }

  function hideBarangaySuggestions() {
    const input = document.getElementById('barangaySearchInput');
    const suggestions = document.getElementById('barangaySearchSuggestions');

    state.searchMatches = [];
    state.activeSuggestionIndex = -1;
    if (suggestions) {
      suggestions.replaceChildren();
      suggestions.hidden = true;
    }
    if (input) {
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }
  }

  function setActiveBarangaySuggestion(index) {
    const input = document.getElementById('barangaySearchInput');
    const suggestions = document.getElementById('barangaySearchSuggestions');
    if (!input || !suggestions || !state.searchMatches.length) return false;

    const optionElements = Array.from(suggestions.querySelectorAll('[role="option"]'));
    if (!optionElements.length) return false;

    const normalizedIndex = (index + optionElements.length) % optionElements.length;
    state.activeSuggestionIndex = normalizedIndex;

    optionElements.forEach(function (option, optionIndex) {
      const isActive = optionIndex === normalizedIndex;
      option.classList.toggle('is-active', isActive);
      option.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    const activeOption = optionElements[normalizedIndex];
    input.setAttribute('aria-activedescendant', activeOption.id);
    activeOption.scrollIntoView({ block: 'nearest' });
    return true;
  }

  function renderBarangaySuggestions(rawQuery) {
    const input = document.getElementById('barangaySearchInput');
    const suggestions = document.getElementById('barangaySearchSuggestions');
    const query = normalizeBarangayName(rawQuery);

    if (!input || !suggestions) return [];

    if (!state.draftPreviewLoaded || !state.searchableBarangays.length) {
      hideBarangaySuggestions();
      setStatus('barangaySearchStatus', 'Validated draft barangays are not available in this environment.');
      return [];
    }

    if (!query) {
      hideBarangaySuggestions();
      setStatus('barangaySearchStatus', '187 validated draft barangays available for search.');
      return [];
    }

    const allMatches = state.searchableBarangays.filter(function (record) {
      return record.normalizedName.startsWith(query);
    });
    const visibleMatches = allMatches.slice(0, CONFIG.maximumSearchSuggestions);

    suggestions.replaceChildren();
    state.searchMatches = visibleMatches;
    state.activeSuggestionIndex = -1;

    if (!visibleMatches.length) {
      hideBarangaySuggestions();
      setStatus('barangaySearchStatus', 'No validated draft barangay matches that search.');
      return [];
    }

    visibleMatches.forEach(function (record, index) {
      const option = document.createElement('button');
      const name = document.createElement('span');
      const code = document.createElement('span');

      option.type = 'button';
      option.id = 'barangaySearchOption-' + index;
      option.className = 'civ-barangay-suggestion';
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');
      name.className = 'civ-barangay-suggestion-name';
      code.className = 'civ-barangay-suggestion-code';
      name.textContent = record.properties.name;
      code.textContent = record.properties.barangay_code;
      option.append(name, code);
      option.addEventListener('mousedown', function (event) {
        event.preventDefault();
      });
      option.addEventListener('click', function () {
        selectDraftBarangay(record);
      });
      suggestions.appendChild(option);
    });

    suggestions.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    const exactIndex = visibleMatches.findIndex(function (record) {
      return record.normalizedName === query;
    });
    if (exactIndex >= 0) setActiveBarangaySuggestion(exactIndex);

    const hiddenMatchCount = allMatches.length - visibleMatches.length;
    setStatus(
      'barangaySearchStatus',
      hiddenMatchCount > 0
        ? visibleMatches.length + ' of ' + allMatches.length + ' matching barangays shown.'
        : allMatches.length + (allMatches.length === 1 ? ' matching barangay.' : ' matching barangays.')
    );
    return visibleMatches;
  }

  function bindBarangaySearch() {
    const form = document.getElementById('barangaySearchForm');
    const input = document.getElementById('barangaySearchInput');
    const clearButton = document.getElementById('clearBarangaySelectionButton');
    if (!form || !input) return;

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      const query = normalizeBarangayName(input.value);

      if (!query) {
        hideBarangaySuggestions();
        setStatus('barangaySearchStatus', 'Enter a barangay name to search.');
        return;
      }

      const activeRecord = state.activeSuggestionIndex >= 0
        ? state.searchMatches[state.activeSuggestionIndex]
        : null;
      const exactRecord = state.searchableBarangays.find(function (record) {
        return record.normalizedName === query;
      });
      const record = activeRecord || exactRecord;

      if (record) {
        selectDraftBarangay(record);
      } else {
        renderBarangaySuggestions(input.value);
        setStatus('barangaySearchStatus', 'Choose a listed barangay or enter its exact name.');
      }
    });

    input.addEventListener('input', function () {
      renderBarangaySuggestions(input.value);
    });

    input.addEventListener('focus', function () {
      if (input.value.trim()) renderBarangaySuggestions(input.value);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' && state.searchMatches.length) {
        event.preventDefault();
        setActiveBarangaySuggestion(state.activeSuggestionIndex + 1);
      } else if (event.key === 'ArrowUp' && state.searchMatches.length) {
        event.preventDefault();
        setActiveBarangaySuggestion(
          state.activeSuggestionIndex < 0
            ? state.searchMatches.length - 1
            : state.activeSuggestionIndex - 1
        );
      } else if (event.key === 'Escape') {
        event.preventDefault();
        hideBarangaySuggestions();
      } else if (event.key === 'Tab') {
        hideBarangaySuggestions();
      }
    });

    if (clearButton) clearButton.addEventListener('click', clearDraftBarangaySelection);

    document.addEventListener('click', function (event) {
      if (!form.contains(event.target)) hideBarangaySuggestions();
    });
  }

  function setFloodLegendActive(active) {
    const context = document.getElementById('riskLegendContext');
    const legendItems = document.getElementById('riskLegendItems');
    const highestLabel = document.getElementById('highestRiskLegendLabel');
    const helper = document.getElementById('riskLegendHelper');

    if (context) {
      context.textContent = active
        ? 'Flood Susceptibility — DENR-MGB'
        : 'Project risk classifications';
    }
    if (legendItems) {
      legendItems.setAttribute(
        'aria-label',
        active ? 'DENR-MGB flood susceptibility classifications' : 'Project risk classifications'
      );
    }
    if (highestLabel) highestLabel.textContent = active ? 'Very High' : 'Critical';
    if (helper) {
      helper.textContent = active
        ? 'Official MGB source terminology. Colors identify the visible flood susceptibility classes.'
        : 'Legend only. No risk level has been assigned to any location.';
    }
  }

  function developmentLayerStatus() {
    const floodAvailable = Boolean(getFloodPreviewConfig());
    const centersAvailable = Boolean(getEvacuationCenterPreviewConfig());

    if (floodAvailable && centersAvailable) {
      return 'Flood susceptibility and evacuation centers are connected in development preview. Other hazard datasets are not yet connected.';
    }
    if (floodAvailable) {
      return 'Flood susceptibility is connected in development preview. Other hazard datasets are not yet connected.';
    }
    if (centersAvailable) {
      return 'Evacuation centers are connected in development preview. Hazard datasets are not yet connected.';
    }
    return 'Hazard and evacuation datasets are not yet connected.';
  }

  function createFloodTooltip(properties) {
    const content = document.createElement('div');
    const title = document.createElement('strong');
    const classification = document.createElement('div');

    title.textContent = 'Flood-Prone Area';
    classification.textContent = properties.display_risk_label + ' (' + properties.mgb_code + ')';
    content.append(title, classification);
    return content;
  }

  function showFloodLocationDetails(properties) {
    const details = document.getElementById('locationDetailsContent');
    if (!details) return;

    const title = document.createElement('strong');
    const susceptibility = document.createElement('div');
    const classification = document.createElement('div');
    const source = document.createElement('div');

    title.textContent = 'Flood-Prone Area';
    susceptibility.textContent = 'Susceptibility: ' + properties.display_risk_label;
    classification.textContent = 'MGB Classification: ' + properties.mgb_code;
    source.textContent = 'Source: ' + properties.source_agency;
    details.replaceChildren(title, susceptibility, classification, source);
  }

  function selectFloodFeature(layer, properties) {
    if (!layer || !properties) return false;

    if (state.selectedFloodLayer && state.selectedFloodLayer !== layer) {
      state.selectedFloodLayer.setStyle(floodFeatureStyle(state.selectedFloodLayer.feature));
    }

    state.selectedFloodLayer = layer;
    layer.setStyle(floodFeatureSelectedStyle(layer.feature));
    if (typeof layer.bringToFront === 'function') layer.bringToFront();
    showFloodLocationDetails(properties);
    return true;
  }

  function clearFloodSelection() {
    if (state.selectedFloodLayer) {
      state.selectedFloodLayer.setStyle(floodFeatureStyle(state.selectedFloodLayer.feature));
    }
    state.selectedFloodLayer = null;

    if (state.selectedEvacuationCenterLayer && state.selectedEvacuationCenterLayer.feature) {
      showEvacuationCenterLocationDetails(state.selectedEvacuationCenterLayer.feature.properties);
    } else if (state.selectedBarangayRecord) {
      showDraftLocationDetails(state.selectedBarangayRecord.properties);
    } else {
      showDefaultLocationDetails();
    }
  }

  function assertDraftFloodFeatureCollection(payload) {
    if (!payload || payload.type !== 'FeatureCollection' || !Array.isArray(payload.features)) {
      throw new Error('Invalid flood GeoJSON response.');
    }
    if (payload.features.length !== 15) {
      throw new Error('Unexpected flood feature count.');
    }

    const classCounts = { LF: 0, MF: 0, HF: 0, VHF: 0 };
    payload.features.forEach(function (feature) {
      const properties = feature && feature.properties;
      const geometry = feature && feature.geometry;
      const classification = properties ? FLOOD_CLASSIFICATIONS[properties.mgb_code] : null;

      if (
        !feature || feature.type !== 'Feature' || !properties || !classification ||
        properties.hazard !== 'Flood' ||
        properties.mgb_label !== classification.label ||
        properties.display_risk_label !== classification.displayLabel ||
        properties.source_agency !== 'DENR-MGB' ||
        !geometry || !['Polygon', 'MultiPolygon'].includes(geometry.type) ||
        !Array.isArray(geometry.coordinates) || geometry.coordinates.length === 0
      ) {
        throw new Error('Invalid flood GeoJSON feature.');
      }

      classCounts[properties.mgb_code] += 1;
    });

    if (classCounts.LF !== 5 || classCounts.MF !== 3 || classCounts.HF !== 4 || classCounts.VHF !== 3) {
      throw new Error('Unexpected flood classification counts.');
    }

    state.floodPreviewClassCounts = Object.freeze(classCounts);
    return payload;
  }

  async function loadDraftFloodPreview() {
    if (state.floodPreviewLoaded) return true;
    if (state.floodLoadPromise) return state.floodLoadPromise;

    const previewConfig = getFloodPreviewConfig();
    if (!previewConfig || !state.map || !state.layerGroups) return false;

    state.floodLoadPromise = (async function () {
      try {
        state.floodFetchCount += 1;
        const response = await window.fetch(previewConfig.endpoint, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/geo+json, application/json' }
        });

        if (!response.ok) throw new Error('Flood preview request failed.');

        const featureCollection = assertDraftFloodFeatureCollection(await response.json());
        const previewLayer = L.geoJSON(featureCollection, {
          pane: 'hazardPolygonPane',
          style: floodFeatureStyle,
          onEachFeature: function (feature, layer) {
            layer.bindTooltip(createFloodTooltip(feature.properties), {
              direction: 'top',
              sticky: true,
              opacity: 0.96
            });
            layer.on('mouseover', function () {
              if (state.selectedFloodLayer !== layer) {
                layer.setStyle(floodFeatureHoverStyle(feature));
              }
              if (typeof layer.bringToFront === 'function') layer.bringToFront();
            });
            layer.on('mouseout', function () {
              layer.setStyle(
                state.selectedFloodLayer === layer
                  ? floodFeatureSelectedStyle(feature)
                  : floodFeatureStyle(feature)
              );
              if (
                state.selectedFloodLayer && state.selectedFloodLayer !== layer &&
                typeof state.selectedFloodLayer.bringToFront === 'function'
              ) {
                state.selectedFloodLayer.bringToFront();
              }
            });
            layer.on('click', function () {
              selectFloodFeature(layer, feature.properties);
            });
          }
        });

        state.layerGroups.floodHazards.clearLayers();
        previewLayer.addTo(state.layerGroups.floodHazards);
        state.floodPreviewFeatureCollection = featureCollection;
        state.floodPreviewLayer = previewLayer;
        state.floodPreviewFeatureCount = featureCollection.features.length;
        state.floodPreviewLoaded = true;
        return true;
      } catch (error) {
        state.layerGroups.floodHazards.clearLayers();
        state.floodPreviewFeatureCollection = null;
        state.floodPreviewLayer = null;
        state.floodPreviewFeatureCount = 0;
        state.floodPreviewClassCounts = null;
        state.floodPreviewLoaded = false;
        return false;
      } finally {
        state.floodLoadPromise = null;
      }
    })();

    return state.floodLoadPromise;
  }

  async function handleFloodControl(control) {
    const layerGroup = state.layerGroups ? state.layerGroups.floodHazards : null;
    if (!state.map || !layerGroup) return;

    if (!control.checked) {
      state.map.removeLayer(layerGroup);
      clearFloodSelection();
      setFloodLegendActive(false);
      setStatus('hazardLayerStatus', developmentLayerStatus());
      return;
    }

    if (!getFloodPreviewConfig()) {
      control.checked = false;
      setFloodLegendActive(false);
      setStatus('hazardLayerStatus', 'Flood hazard data is not available in this environment.');
      return;
    }

    setStatus('hazardLayerStatus', 'Loading DENR-MGB flood susceptibility...');
    const loaded = await loadDraftFloodPreview();

    if (!loaded) {
      control.checked = false;
      state.map.removeLayer(layerGroup);
      setFloodLegendActive(false);
      setStatus('hazardLayerStatus', 'Flood hazard data could not be loaded.');
      return;
    }

    if (control.checked) {
      layerGroup.addTo(state.map);
      setFloodLegendActive(true);
      setStatus('hazardLayerStatus', developmentLayerStatus());
    }
  }

  function assertDraftEvacuationCenterFeatureCollection(payload) {
    if (!payload || payload.type !== 'FeatureCollection' || !Array.isArray(payload.features)) {
      throw new Error('Invalid evacuation-center GeoJSON response.');
    }
    if (payload.features.length !== 15) {
      throw new Error('Unexpected evacuation-center feature count.');
    }

    const seenIds = new Set();
    const seenNames = new Set();
    payload.features.forEach(function (feature) {
      const properties = feature && feature.properties;
      const geometry = feature && feature.geometry;
      const coordinates = geometry && geometry.coordinates;
      const nameKey = properties && typeof properties.name === 'string'
        ? properties.name.trim().toLocaleLowerCase()
        : '';

      if (
        !feature || feature.type !== 'Feature' || !properties ||
        typeof properties.evacuation_center_id !== 'string' ||
        !/^[0-9a-f-]{36}$/i.test(properties.evacuation_center_id) ||
        !nameKey || seenIds.has(properties.evacuation_center_id) || seenNames.has(nameKey) ||
        !/^Barangay (?:[1-9]|[1-9]\d|1\d\d)$/.test(properties.barangay_name) ||
        properties.designation !== 'Evacuation Center' ||
        properties.location_verification_status !== 'Location pending LGU verification' ||
        properties.display_status !== 'Development Preview' ||
        properties.source_agency !== 'City Government of Caloocan / Caloocan PIO' ||
        !geometry || geometry.type !== 'Point' || !Array.isArray(coordinates) || coordinates.length !== 2 ||
        !Number.isFinite(Number(coordinates[0])) || !Number.isFinite(Number(coordinates[1])) ||
        Number(coordinates[0]) < -180 || Number(coordinates[0]) > 180 ||
        Number(coordinates[1]) < -90 || Number(coordinates[1]) > 90
      ) {
        throw new Error('Invalid evacuation-center GeoJSON feature.');
      }

      seenIds.add(properties.evacuation_center_id);
      seenNames.add(nameKey);
    });

    return payload;
  }

  function evacuationCenterMarkerIcon() {
    return L.divIcon({
      className: 'civ-evacuation-marker-wrap',
      html: '<span class="civ-evacuation-marker" aria-hidden="true"><i class="fa-solid fa-house-medical"></i></span>',
      iconSize: [32, 38],
      iconAnchor: [16, 36],
      popupAnchor: [0, -34],
      tooltipAnchor: [0, -30]
    });
  }

  function createEvacuationCenterContent(properties, includeHeading) {
    const content = document.createElement('div');
    const heading = document.createElement('strong');
    const name = document.createElement('div');
    const barangay = document.createElement('div');
    const status = document.createElement('div');
    const location = document.createElement('div');
    const source = document.createElement('div');
    const barangayNumber = properties.barangay_name.replace(/^Barangay\s+/i, '');

    heading.textContent = 'Evacuation Center';
    name.textContent = properties.name;
    barangay.textContent = 'Barangay: ' + barangayNumber;
    status.textContent = 'Status: ' + properties.display_status;
    location.textContent = 'Location: Pending LGU verification';
    source.textContent = 'Source: ' + properties.source_agency;
    if (includeHeading) content.appendChild(heading);
    content.append(name, barangay, status, location, source);
    return content;
  }

  function showEvacuationCenterLocationDetails(properties) {
    const details = document.getElementById('locationDetailsContent');
    if (details) details.replaceChildren(createEvacuationCenterContent(properties, true));
  }

  function selectEvacuationCenter(layer, properties) {
    if (!layer || !properties) return false;

    if (state.selectedEvacuationCenterLayer && state.selectedEvacuationCenterLayer !== layer) {
      const previousElement = state.selectedEvacuationCenterLayer.getElement();
      if (previousElement) previousElement.classList.remove('is-selected');
    }
    state.selectedEvacuationCenterLayer = layer;
    const markerElement = layer.getElement();
    if (markerElement) markerElement.classList.add('is-selected');
    showEvacuationCenterLocationDetails(properties);
    return true;
  }

  function clearEvacuationCenterSelection() {
    if (state.selectedEvacuationCenterLayer) {
      const markerElement = state.selectedEvacuationCenterLayer.getElement();
      if (markerElement) markerElement.classList.remove('is-selected');
    }
    state.selectedEvacuationCenterLayer = null;

    if (state.selectedFloodLayer) {
      showFloodLocationDetails(state.selectedFloodLayer.feature.properties);
    } else if (state.selectedBarangayRecord) {
      showDraftLocationDetails(state.selectedBarangayRecord.properties);
    } else {
      showDefaultLocationDetails();
    }
  }

  async function loadDraftEvacuationCenterPreview() {
    if (state.evacuationCenterPreviewLoaded) return true;
    if (state.evacuationCenterLoadPromise) return state.evacuationCenterLoadPromise;

    const previewConfig = getEvacuationCenterPreviewConfig();
    if (!previewConfig || !state.map || !state.layerGroups) return false;

    state.evacuationCenterLoadPromise = (async function () {
      try {
        state.evacuationCenterFetchCount += 1;
        const response = await window.fetch(previewConfig.endpoint, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/geo+json, application/json' }
        });
        if (!response.ok) throw new Error('Evacuation-center preview request failed.');

        const featureCollection = assertDraftEvacuationCenterFeatureCollection(await response.json());
        const previewLayer = L.geoJSON(featureCollection, {
          pane: 'markerPane',
          pointToLayer: function (feature, latlng) {
            return L.marker(latlng, { pane: 'markerPane', icon: evacuationCenterMarkerIcon() });
          },
          onEachFeature: function (feature, layer) {
            layer.bindTooltip(feature.properties.name, { direction: 'top', opacity: 0.96 });
            layer.bindPopup(createEvacuationCenterContent(feature.properties, false));
            layer.on('click', function () {
              selectEvacuationCenter(layer, feature.properties);
            });
          }
        });

        state.layerGroups.evacuationCenters.clearLayers();
        previewLayer.addTo(state.layerGroups.evacuationCenters);
        state.evacuationCenterFeatureCollection = featureCollection;
        state.evacuationCenterPreviewLayer = previewLayer;
        state.evacuationCenterPreviewFeatureCount = featureCollection.features.length;
        state.evacuationCenterPreviewLoaded = true;
        return true;
      } catch (error) {
        state.layerGroups.evacuationCenters.clearLayers();
        state.evacuationCenterFeatureCollection = null;
        state.evacuationCenterPreviewLayer = null;
        state.evacuationCenterPreviewFeatureCount = 0;
        state.evacuationCenterPreviewLoaded = false;
        return false;
      } finally {
        state.evacuationCenterLoadPromise = null;
      }
    })();

    return state.evacuationCenterLoadPromise;
  }

  async function handleEvacuationCenterControl(control) {
    const layerGroup = state.layerGroups ? state.layerGroups.evacuationCenters : null;
    if (!state.map || !layerGroup) return;

    if (!control.checked) {
      state.map.removeLayer(layerGroup);
      clearEvacuationCenterSelection();
      setStatus('hazardLayerStatus', developmentLayerStatus());
      return;
    }

    if (!getEvacuationCenterPreviewConfig()) {
      control.checked = false;
      setStatus('hazardLayerStatus', 'Evacuation-center data is not available in this environment.');
      return;
    }

    setStatus('hazardLayerStatus', 'Loading evacuation centers...');
    const loaded = await loadDraftEvacuationCenterPreview();
    if (!loaded) {
      control.checked = false;
      state.map.removeLayer(layerGroup);
      setStatus('hazardLayerStatus', 'Evacuation-center data could not be loaded.');
      return;
    }

    if (control.checked) {
      layerGroup.addTo(state.map);
      setStatus('hazardLayerStatus', developmentLayerStatus());
    }
  }

  function bindLayerControls() {
    const controls = document.querySelectorAll('[data-map-layer]');

    setFloodLegendActive(false);
    setStatus('hazardLayerStatus', developmentLayerStatus());

    controls.forEach(function (control) {
      control.checked = false;
      control.addEventListener('change', function () {
        const layerKey = control.dataset.mapLayer;
        const layerGroup = state.layerGroups ? state.layerGroups[layerKey] : null;

        if (layerKey === 'floodHazards') {
          handleFloodControl(control);
          return;
        }

        if (layerKey === 'evacuationCenters') {
          handleEvacuationCenterControl(control);
          return;
        }

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
            ? 'The selected hazard dataset is not yet connected.'
            : developmentLayerStatus()
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

  function showDefaultLocationDetails() {
    const details = document.getElementById('locationDetailsContent');
    if (details) details.textContent = DEFAULT_LOCATION_DETAILS;
  }

  function selectDraftBarangay(record) {
    if (!record || !record.layer || !record.properties) return false;

    if (state.selectedBarangayLayer && state.selectedBarangayLayer !== record.layer) {
      state.selectedBarangayLayer.setStyle(draftBarangayStyle());
    }

    state.selectedBarangayLayer = record.layer;
    state.selectedBarangayRecord = record;
    record.layer.setStyle(draftBarangaySelectedStyle());
    if (typeof record.layer.bringToFront === 'function') record.layer.bringToFront();

    const input = document.getElementById('barangaySearchInput');
    const clearButton = document.getElementById('clearBarangaySelectionButton');
    if (input) input.value = record.properties.name;
    if (clearButton) clearButton.hidden = false;
    hideBarangaySuggestions();
    showDraftLocationDetails(record.properties);
    setStatus('barangaySearchStatus', record.properties.name + ' selected.');

    const bounds = typeof record.layer.getBounds === 'function' ? record.layer.getBounds() : null;
    if (state.map && bounds && bounds.isValid()) {
      state.map.fitBounds(bounds, {
        padding: [36, 36],
        maxZoom: 15,
        animate: false
      });
      setActiveMapFocus(null);
    }

    return true;
  }

  function clearDraftBarangaySelection() {
    if (state.selectedBarangayLayer) {
      state.selectedBarangayLayer.setStyle(draftBarangayStyle());
    }

    state.selectedBarangayLayer = null;
    state.selectedBarangayRecord = null;

    const input = document.getElementById('barangaySearchInput');
    const clearButton = document.getElementById('clearBarangaySelectionButton');
    if (input) input.value = '';
    if (clearButton) clearButton.hidden = true;
    if (state.map) state.map.closePopup();
    hideBarangaySuggestions();
    showDefaultLocationDetails();
    setStatus(
      'barangaySearchStatus',
      state.draftPreviewLoaded
        ? '187 validated draft barangays available for search.'
        : 'Barangay records are not yet connected.'
    );
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

      if (/^barangay\s+176(?:-[a-f])?$/i.test(properties.name.trim())) {
        throw new Error('Unavailable Barangay 176 geometry was included in the draft response.');
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
      state.searchableBarangays = [];
      clearDraftBarangaySelection();
      const previewLayer = L.geoJSON(featureCollection, {
        pane: 'barangayPane',
        style: draftBarangayStyle,
        onEachFeature: function (feature, layer) {
          const record = Object.freeze({
            layer: layer,
            properties: feature.properties,
            normalizedName: normalizeBarangayName(feature.properties.name),
            barangayNumber: barangayNumber(feature.properties.name)
          });
          state.searchableBarangays.push(record);
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
            if (
              state.selectedBarangayLayer &&
              state.selectedBarangayLayer !== layer &&
              typeof state.selectedBarangayLayer.bringToFront === 'function'
            ) {
              state.selectedBarangayLayer.bringToFront();
            }
          });
          layer.on('click', function () {
            selectDraftBarangay(record);
          });
        }
      });

      state.searchableBarangays.sort(function (first, second) {
        return first.barangayNumber - second.barangayNumber ||
          first.properties.name.localeCompare(second.properties.name);
      });

      const uniqueNames = new Set(state.searchableBarangays.map(function (record) {
        return record.normalizedName;
      }));
      if (state.searchableBarangays.length !== 187 || uniqueNames.size !== 187) {
        throw new Error('Draft barangay search index is incomplete or contains duplicate names.');
      }

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
      searchableBarangayCount: state.searchableBarangays.length,
      selectedBarangayName: state.selectedBarangayRecord
        ? state.selectedBarangayRecord.properties.name
        : null,
      floodPreviewAvailable: Boolean(getFloodPreviewConfig()),
      floodPreviewLoaded: state.floodPreviewLoaded,
      floodPreviewActive: Boolean(
        state.map && state.layerGroups && state.map.hasLayer(state.layerGroups.floodHazards)
      ),
      floodPreviewFeatureCount: state.floodPreviewFeatureCount,
      floodPreviewClassCounts: state.floodPreviewClassCounts,
      floodFetchCount: state.floodFetchCount,
      evacuationCenterPreviewAvailable: Boolean(getEvacuationCenterPreviewConfig()),
      evacuationCenterPreviewLoaded: state.evacuationCenterPreviewLoaded,
      evacuationCenterPreviewActive: Boolean(
        state.map && state.layerGroups && state.map.hasLayer(state.layerGroups.evacuationCenters)
      ),
      evacuationCenterPreviewFeatureCount: state.evacuationCenterPreviewFeatureCount,
      evacuationCenterFetchCount: state.evacuationCenterFetchCount,
      evacuationCenterMarkerCount: container
        ? container.querySelectorAll('.civ-evacuation-marker-wrap').length
        : 0,
      floodLegendHighestLabel: document.getElementById('highestRiskLegendLabel')
        ? document.getElementById('highestRiskLegendLabel').textContent
        : null,
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
