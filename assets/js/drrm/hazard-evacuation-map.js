(function () {
  'use strict';

  const CONFIG = Object.freeze({
    containerId: 'civentralHazardMap',
    center: [14.706, 121.02],
    initialZoom: 11.5,
    maximumZoom: 18,
    maximumSearchSuggestions: 8,
    routeSampleIntervalMeters: 50,
    referenceLoadTimeoutMilliseconds: 12000
  });

  const DEFAULT_LOCATION_DETAILS = 'Select a barangay, hazard area, or evacuation center from the map to view information.';
  const FLOOD_CLASSIFICATIONS = Object.freeze({
    LF: Object.freeze({ label: 'Low Susceptibility to Flooding', displayLabel: 'Low', color: '#16a34a' }),
    MF: Object.freeze({ label: 'Moderate Susceptibility to Flooding', displayLabel: 'Moderate', color: '#eab308' }),
    HF: Object.freeze({ label: 'High Susceptibility to Flooding', displayLabel: 'High', color: '#f97316' }),
    VHF: Object.freeze({ label: 'Very High Susceptibility to Flooding', displayLabel: 'Very High', color: '#dc2626' })
  });
  const LANDSLIDE_CLASSIFICATIONS = Object.freeze({
    LL: Object.freeze({ label: 'Low Susceptibility to Landslide', displayLabel: 'Low', color: '#65a30d' }),
    ML: Object.freeze({ label: 'Moderate Susceptibility to Landslide', displayLabel: 'Moderate', color: '#ca8a04' }),
    HL: Object.freeze({ label: 'High Susceptibility to Landslide', displayLabel: 'High', color: '#ea580c' }),
    VHL: Object.freeze({ label: 'Very High Susceptibility to Landslide', displayLabel: 'Very High', color: '#991b1b' })
  });
  // CIVENTRAL development-only decision-support weights. These are not
  // DENR-MGB risk standards and must not be presented as official scores.
  const ROUTE_HAZARD_WEIGHTS = Object.freeze({
    Low: 1,
    Moderate: 3,
    High: 6,
    'Very High': 10
  });

  const state = {
    map: null,
    layerGroups: null,
    resizeObserver: null,
    resizeFrame: null,
    draftPreviewLoaded: false,
    barangayDataStatus: 'NOT_LOADED',
    barangaySourceMode: 'NOT_ACTIVE',
    operationalBarangayCollection: null,
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
    landslidePreviewLoaded: false,
    landslidePreviewFeatureCount: 0,
    landslidePreviewLayer: null,
    landslidePreviewFeatureCollection: null,
    landslidePreviewClassCounts: null,
    landslideLoadPromise: null,
    landslideFetchCount: 0,
    operationalHazardCollections: null,
    operationalHazardLoadPromise: null,
    hazardSourceModes: { flood: 'NOT_ACTIVE', landslide: 'NOT_ACTIVE' },
    mgbReferenceTileLayers: { flood: null, landslide: null },
    mgbReferenceImageOverlays: { flood: null, landslide: null },
    mgbReferenceRequestIds: { flood: 0, landslide: 0 },
    mgbReferenceTileLoadCounts: { flood: 0, landslide: 0 },
    mgbReferenceTileErrorCounts: { flood: 0, landslide: 0 },
    mgbReferenceRefreshTimer: null,
    selectedLandslideLayer: null,
    faultPreviewLoaded: false,
    faultPreviewFeatureCount: 0,
    faultPreviewResponse: null,
    faultLoadPromise: null,
    faultFetchCount: 0,
    faultInformationActive: false,
    faultSourceMode: 'NOT_ACTIVE',
    phivolcsReferenceTileLayer: null,
    phivolcsReferenceRequestId: 0,
    phivolcsReferenceTileLoadCount: 0,
    phivolcsReferenceTileErrorCount: 0,
    selectedFaultLayer: null,
    evacuationCenterPreviewLoaded: false,
    evacuationCenterPreviewFeatureCount: 0,
    evacuationCenterPreviewLayer: null,
    evacuationCenterFeatureCollection: null,
    operationalEvacuationCenterFeatureCollection: null,
    evacuationCenterLoadPromise: null,
    evacuationCenterFetchCount: 0,
    evacuationCenterSourceMode: 'NOT_ACTIVE',
    evacuationCenterLoadedSourceMode: null,
    selectedEvacuationCenterLayer: null,
    operationalRouteStatus: 'NOT_LOADED',
    operationalRouteFeatureCount: 0,
    operationalRouteFetchCount: 0,
    cityBaseLayer: null,
    cityMaskLayer: null,
    cityBoundaryLayer: null,
    cityBoundaryFeatureCollection: null,
    cityBoundaryBounds: null,
    cityGeometryType: null,
    cityComponentCount: 0,
    cityComponentBounds: null,
    operationalMaxBounds: null,
    operationalMinZoom: null,
    focusControl: null,
    focusButtons: null,
    mapPointSelectionHandlerBound: false,
    routeOriginSetButtonHandlerBound: false,
    routeOriginSetButtonClickCount: 0,
    routeOriginMapClickCount: 0,
    routeOriginFeatureClickCount: 0,
    routeOriginSelectionAttemptCount: 0,
    routeOriginLastAttemptLat: null,
    routeOriginLastAttemptLng: null,
    routeOriginLastResult: 'NOT_USED',
    routeOriginSelectionActive: false,
    routeOrigin: null,
    routeOriginMarker: null,
    routeDestinationMarker: null,
    routeGeometryLayer: null,
    routeCentersById: new Map(),
    routeCenterOptionsLoaded: false,
    selectedEvacuationCenterId: null,
    routeRequestPending: false,
    routingFetchCount: 0,
    routeAlternativesReceived: 0,
    recommendedRouteIndex: null,
    routeDistanceMeters: null,
    routeDurationSeconds: null,
    routeHazardScore: null,
    routeFloodExposure: null,
    routeLandslideExposure: null,
    routeGeometryRendered: false,
    forecastLocationSelectionActive: false,
    forecastLocation: null,
    forecastLocationMarker: null,
    forecastUsesRouteOrigin: false,
    useRouteOriginHandlerBound: false,
    useRouteOriginClickCount: 0,
    useRouteOriginLastResult: 'NOT_USED',
    pagasaForecastStatus: 'NOT_LOADED',
    pagasaForecastFetchCount: 0,
    pagasaForecastEntries: 0,
    pagasaForecastResponse: null,
    pagasaForecastLoadPromise: null,
    mappedFloodSusceptibility: null,
    tensorflowPredictionAvailable: false
  };

  function createLayerGroups() {
    return Object.freeze({
      barangays: L.layerGroup(),
      floodHazards: L.layerGroup(),
      landslideHazards: L.layerGroup(),
      mgbFloodReference: L.layerGroup(),
      mgbLandslideReference: L.layerGroup(),
      phivolcsFaultReference: L.layerGroup(),
      earthquakeFaults: L.layerGroup(),
      evacuationCenters: L.layerGroup(),
      evacuationRoutes: L.layerGroup(),
      forecastLocations: L.layerGroup(),
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

  function getOperationalDataConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    const config = runtimeConfig && runtimeConfig.operationalData;
    if (!runtimeConfig || runtimeConfig.dataMode !== 'operational'
      || !config || config.enabled !== true) {
      return null;
    }
    return config;
  }

  function isOperationalMode() {
    return Boolean(getOperationalDataConfig());
  }

  function initializeOperationalShell() {
    if (!isOperationalMode()) return;
    const baselineNotice = document.getElementById('polygonMapNotice');
    const message = baselineNotice ? baselineNotice.querySelector('p') : null;
    if (message) message.textContent = 'Caloocan polygon view. Loading server-filtered operational map records.';
    setStatus('barangaySearchStatus', 'Loading operational barangay data...');
    setStatus('hazardLayerStatus', 'Operational layers load on demand.');
    setStatus('floodForecastConnectionStatus', 'Unavailable');
    setStatus('mappedFloodSusceptibilityContent', 'Published mapped flood susceptibility loads from the operational hazard layer.');
  }

  function updateMapDataStatus() {
    const runtimeConfig = window.CiventralDrrmMapConfig || {};
    const badge = document.getElementById('mapDataStatusBadge');
    if (runtimeConfig.dataMode === 'development-preview') {
      setStatus('mapDataStatusText', 'Map Data Status: Development Preview');
      if (badge) badge.title = 'Local development preview; records remain unpublished.';
      return;
    }
    const hasReferenceMode = Boolean(
      (runtimeConfig.mgbLiveReference && runtimeConfig.mgbLiveReference.enabled === true) ||
      (runtimeConfig.phivolcsLiveReference && runtimeConfig.phivolcsLiveReference.enabled === true) ||
      (runtimeConfig.adminEvacuationCenterReference
        && runtimeConfig.adminEvacuationCenterReference.enabled === true)
      || (runtimeConfig.adminBarangayReference
        && runtimeConfig.adminBarangayReference.enabled === true)
    );
    setStatus('mapDataStatusText', hasReferenceMode
      ? 'Map Data Status: Operational + Reference'
      : 'Map Data Status: Operational');
    if (badge) {
      badge.title = hasReferenceMode
        ? 'Published operational records retain priority; reference layers are explicitly labeled.'
        : 'Published CIVENTRAL operational data only.';
    }
  }

  function getOperationalAdapter() {
    const adapter = window.CiventralDrrmOperationalData;
    return adapter && typeof adapter === 'object' ? adapter : null;
  }

  function getMgbReferenceApi() {
    const api = window.CiventralMgbLiveReference;
    return api && typeof api === 'object' ? api : null;
  }

  function getPhivolcsReferenceApi() {
    const api = window.CiventralPhivolcsLiveReference;
    return api && typeof api === 'object' ? api : null;
  }

  function getPhivolcsLiveReferenceConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    const config = runtimeConfig && runtimeConfig.phivolcsLiveReference;
    const api = getPhivolcsReferenceApi();
    if (
      !runtimeConfig || runtimeConfig.dataMode !== 'operational' ||
      !config || config.enabled !== true || !api ||
      typeof api.service !== 'function' ||
      typeof api.selectOperationalOrReference !== 'function'
    ) {
      return null;
    }
    return Object.freeze({ enabled: true, api: api });
  }

  function getAdminEvacuationCenterReferenceConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    const config = runtimeConfig && runtimeConfig.adminEvacuationCenterReference;
    if (
      !runtimeConfig || runtimeConfig.dataMode !== 'operational' ||
      !config || config.enabled !== true ||
      typeof config.endpoint !== 'string' || config.endpoint === '' ||
      config.endpoint.includes('/api/drrm/dev/')
    ) {
      return null;
    }

    return config;
  }

  function getAdminBarangayReferenceConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    const config = runtimeConfig && runtimeConfig.adminBarangayReference;
    if (
      !runtimeConfig || runtimeConfig.dataMode !== 'operational' ||
      !config || config.enabled !== true ||
      typeof config.endpoint !== 'string' || config.endpoint === '' ||
      config.endpoint.includes('/api/drrm/dev/')
    ) {
      return null;
    }
    return config;
  }

  function getMgbLiveReferenceConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    const config = runtimeConfig && runtimeConfig.mgbLiveReference;
    const api = getMgbReferenceApi();
    if (
      !runtimeConfig || runtimeConfig.dataMode !== 'operational' ||
      !config || config.enabled !== true || !api ||
      typeof api.serviceFor !== 'function' ||
      typeof api.selectOperationalOrReference !== 'function'
    ) {
      return null;
    }
    return Object.freeze({ enabled: true, api: api });
  }

  function operationalEndpoint(key) {
    const config = getOperationalDataConfig();
    return config && typeof config[key] === 'string' && config[key] !== ''
      ? config[key]
      : null;
  }

  async function fetchOperationalJson(endpoint, label) {
    const response = await window.fetch(endpoint, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    });
    if (!response.ok) throw new Error(label + ' request failed.');
    return response.json();
  }

  function getDraftPreviewConfig() {
    const operational = operationalEndpoint('barangaysEndpoint');
    if (operational) return Object.freeze({ endpoint: operational, operational: true });
    const runtimeConfig = window.CiventralDrrmMapConfig;
    if (runtimeConfig && runtimeConfig.dataMode === 'operational') return null;
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
    const operational = operationalEndpoint('hazardsEndpoint');
    if (operational) return Object.freeze({ endpoint: operational, operational: true });
    const runtimeConfig = window.CiventralDrrmMapConfig;
    if (runtimeConfig && runtimeConfig.dataMode === 'operational') return null;
    const previewConfig = runtimeConfig && runtimeConfig.draftFloodPreview;

    if (!previewConfig || previewConfig.enabled !== true || typeof previewConfig.endpoint !== 'string') {
      return null;
    }

    return previewConfig;
  }

  function getLandslidePreviewConfig() {
    const operational = operationalEndpoint('hazardsEndpoint');
    if (operational) return Object.freeze({ endpoint: operational, operational: true });
    const runtimeConfig = window.CiventralDrrmMapConfig;
    if (runtimeConfig && runtimeConfig.dataMode === 'operational') return null;
    const previewConfig = runtimeConfig && runtimeConfig.draftLandslidePreview;

    if (!previewConfig || previewConfig.enabled !== true || typeof previewConfig.endpoint !== 'string') {
      return null;
    }

    return previewConfig;
  }

  function getEvacuationCenterPreviewConfig() {
    const operational = operationalEndpoint('evacuationCentersEndpoint');
    if (operational) return Object.freeze({ endpoint: operational, operational: true });
    const runtimeConfig = window.CiventralDrrmMapConfig;
    if (runtimeConfig && runtimeConfig.dataMode === 'operational') return null;
    const previewConfig = runtimeConfig && runtimeConfig.draftEvacuationCenterPreview;

    if (!previewConfig || previewConfig.enabled !== true || typeof previewConfig.endpoint !== 'string') {
      return null;
    }

    return previewConfig;
  }

  function getFaultInformationPreviewConfig() {
    const operational = operationalEndpoint('faultsEndpoint');
    if (operational) return Object.freeze({ endpoint: operational, operational: true });
    const runtimeConfig = window.CiventralDrrmMapConfig;
    if (runtimeConfig && runtimeConfig.dataMode === 'operational') return null;
    const previewConfig = runtimeConfig && runtimeConfig.draftFaultInformationPreview;

    if (!previewConfig || previewConfig.enabled !== true || typeof previewConfig.endpoint !== 'string') {
      return null;
    }

    return previewConfig;
  }

  function getEvacuationRoutePreviewConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    if (runtimeConfig && runtimeConfig.dataMode === 'operational') return null;
    const previewConfig = runtimeConfig && runtimeConfig.developmentEvacuationRoute;

    if (!previewConfig || previewConfig.enabled !== true || typeof previewConfig.endpoint !== 'string') {
      return null;
    }

    return previewConfig;
  }

  function getOperationalEvacuationRouteConfig() {
    const endpoint = operationalEndpoint('evacuationRoutesEndpoint');
    return endpoint ? Object.freeze({ endpoint: endpoint, operational: true }) : null;
  }

  function getFloodForecastPreviewConfig() {
    const runtimeConfig = window.CiventralDrrmMapConfig;
    if (runtimeConfig && runtimeConfig.dataMode === 'operational') return null;
    const previewConfig = runtimeConfig && runtimeConfig.developmentFloodForecast;

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

  function cityMaskStyle() {
    return {
      stroke: false,
      fill: true,
      fillColor: isDarkMode() ? '#0f172a' : '#f8fafc',
      fillOpacity: 1,
      fillRule: 'evenodd'
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

  function landslideFeatureStyle(feature) {
    const code = feature && feature.properties ? feature.properties.mgb_code : '';
    const classification = LANDSLIDE_CLASSIFICATIONS[code] || LANDSLIDE_CLASSIFICATIONS.LL;

    return {
      color: classification.color,
      weight: 1.4,
      opacity: 0.98,
      fill: true,
      fillColor: classification.color,
      fillOpacity: isDarkMode() ? 0.38 : 0.3,
      dashArray: '5 3',
      lineCap: 'round',
      lineJoin: 'round'
    };
  }

  function landslideFeatureHoverStyle(feature) {
    const style = landslideFeatureStyle(feature);
    style.weight = 2.4;
    style.fillOpacity = isDarkMode() ? 0.52 : 0.44;
    return style;
  }

  function landslideFeatureSelectedStyle(feature) {
    const style = landslideFeatureStyle(feature);
    style.weight = 3;
    style.fillOpacity = isDarkMode() ? 0.58 : 0.5;
    return style;
  }

  function developmentRouteStyle() {
    return {
      color: isDarkMode() ? '#67e8f9' : '#0f766e',
      weight: 5,
      opacity: 0.95,
      lineCap: 'round',
      lineJoin: 'round'
    };
  }

  function operationalFaultStyle() {
    return {
      color: isDarkMode() ? '#fda4af' : '#be123c',
      weight: 3,
      opacity: 0.95,
      dashArray: '7 4',
      lineCap: 'round',
      lineJoin: 'round'
    };
  }

  function approvedRouteStyle() {
    return {
      color: isDarkMode() ? '#5eead4' : '#0f766e',
      weight: 5,
      opacity: 0.95,
      lineCap: 'round',
      lineJoin: 'round'
    };
  }

  function refreshThematicStyles() {
    if (state.cityBaseLayer) state.cityBaseLayer.setStyle(cityBaseStyle());
    if (state.cityMaskLayer) state.cityMaskLayer.setStyle(cityMaskStyle());
    if (state.cityBoundaryLayer) state.cityBoundaryLayer.setStyle(cityBoundaryStyle());
    if (state.draftPreviewLayer) state.draftPreviewLayer.setStyle(draftBarangayStyle());
    if (state.selectedBarangayLayer) state.selectedBarangayLayer.setStyle(draftBarangaySelectedStyle());
    if (state.floodPreviewLayer) state.floodPreviewLayer.setStyle(floodFeatureStyle);
    if (state.selectedFloodLayer) {
      state.selectedFloodLayer.setStyle(floodFeatureSelectedStyle(state.selectedFloodLayer.feature));
    }
    if (state.landslidePreviewLayer) state.landslidePreviewLayer.setStyle(landslideFeatureStyle);
    if (state.selectedLandslideLayer) {
      state.selectedLandslideLayer.setStyle(landslideFeatureSelectedStyle(state.selectedLandslideLayer.feature));
    }
    if (state.routeGeometryLayer) {
      state.routeGeometryLayer.setStyle(isOperationalMode() ? approvedRouteStyle() : developmentRouteStyle());
    }
  }

  function createCityContextPanes() {
    const panes = [
      ['cityBasePane', 300, false],
      ['mgbReferencePane', 330, false],
      ['cityMaskPane', 340, false],
      ['hazardPolygonPane', 360, true],
      ['barangayPane', 370, true],
      ['phivolcsReferencePane', 330, false],
      ['operationalLinePane', 390, true],
      ['cityOutlinePane', 410, false],
      ['markerPane', 600, true],
      ['routeOverlayPane', 620, true],
      ['selectionOverlayPane', 640, true]
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

    const maxBounds = state.cityBoundaryBounds.pad(0.04);
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
      state.cityBoundaryFeatureCollection = cityBoundary;

      state.cityBaseLayer = L.geoJSON(cityBoundary, {
        pane: 'cityBasePane',
        interactive: false,
        style: cityBaseStyle
      }).addTo(state.map);

      const mgbReferenceApi = getMgbReferenceApi();
      if (mgbReferenceApi && typeof mgbReferenceApi.createOutsideCityMask === 'function') {
        state.cityMaskLayer = L.geoJSON(
          mgbReferenceApi.createOutsideCityMask(cityBoundary),
          {
            pane: 'cityMaskPane',
            interactive: false,
            style: cityMaskStyle
          }
        );
      }

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

    setStatus('mapDataStatusText', 'Map Data Status: Development Preview');
    if (state.cityBoundaryBounds) {
      setStatus('operationalMapSubtitle', 'Inspecting 187 validated draft barangay boundaries; the current city layer is incomplete.');
    }
    setStatus('barangaySearchStatus', '187 validated draft barangays available for search.');
    state.barangayDataStatus = 'LOADED';
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
    state.barangayDataStatus = 'ERROR';
  }

  function setOperationalBarangayUi(status, count) {
    const baselineNotice = document.getElementById('polygonMapNotice');
    const previewNotice = document.getElementById('draftBarangayPreviewNotice');
    const statusBadge = document.getElementById('mapDataStatusBadge');
    if (previewNotice) previewNotice.classList.add('hidden');
    renderAdminBarangayReferenceNotice();
    if (baselineNotice) baselineNotice.classList.remove('hidden');

    state.barangayDataStatus = status;
    if (status === 'REFERENCE') {
      const message = '187 validated reference barangays available for search. Source: INCOMPLETE ADMIN REFERENCE.';
      if (statusBadge) {
        statusBadge.title = 'Published operational barangays are unavailable; this incomplete reference is restricted to authorized staging administrators.';
      }
      setStatus('mapDataStatusText', 'Map Data Status: Operational + Reference');
      setStatus('barangaySearchStatus', message);
      if (baselineNotice) {
        const baselineMessage = baselineNotice.querySelector('p');
        if (baselineMessage) baselineMessage.textContent = message;
      }
      renderAdminBarangayReferenceNotice();
      return;
    }
    if (status === 'ERROR') {
      if (statusBadge) statusBadge.title = 'The operational barangay endpoint could not be reached.';
      setStatus('mapDataStatusText', 'Map Data Status: Operational Endpoint Unavailable');
      setStatus('barangaySearchStatus', 'Barangay operational data could not be loaded.');
      return;
    }

    updateMapDataStatus();
    if (status === 'EMPTY') {
      setStatus('barangaySearchStatus', 'Barangay operational data is not yet published.');
      if (baselineNotice) {
        const message = baselineNotice.querySelector('p');
        if (message) message.textContent = 'The operational barangay endpoint is available, but no ACTIVE barangay boundaries are currently published.';
      }
      return;
    }

    setStatus('barangaySearchStatus', count + (count === 1
      ? ' published barangay available for search.'
      : ' published barangays available for search.'));
    if (baselineNotice) {
      const message = baselineNotice.querySelector('p');
      if (message) message.textContent = 'Operational view: only server-filtered ACTIVE, PUBLISHED, or APPROVED records are displayed.';
    }
  }

  function barangayAvailabilityMessage() {
    if (isOperationalMode()) {
      if (state.barangayDataStatus === 'ERROR') return 'Barangay operational data could not be loaded.';
      if (state.barangaySourceMode === 'INCOMPLETE_ADMIN_REFERENCE') {
        return '187 validated reference barangays available for search. Source: INCOMPLETE ADMIN REFERENCE.';
      }
      if (state.barangayDataStatus === 'EMPTY') return 'Barangay operational data is not yet published.';
      return state.searchableBarangays.length + (state.searchableBarangays.length === 1
        ? ' published barangay available for search.'
        : ' published barangays available for search.');
    }
    return state.draftPreviewLoaded
      ? '187 validated draft barangays available for search.'
      : 'Validated draft barangays are not available in this environment.';
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
      setStatus('barangaySearchStatus', barangayAvailabilityMessage());
      return [];
    }

    if (!query) {
      hideBarangaySuggestions();
      setStatus('barangaySearchStatus', barangayAvailabilityMessage());
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
      setStatus('barangaySearchStatus', state.barangaySourceMode === 'INCOMPLETE_ADMIN_REFERENCE'
        ? 'No reference barangay matches that search. Source: INCOMPLETE ADMIN REFERENCE.'
        : (isOperationalMode()
          ? 'No published barangay matches that search.'
          : 'No validated draft barangay matches that search.'));
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

  function updateHazardLegend() {
    const context = document.getElementById('riskLegendContext');
    const legendItems = document.getElementById('riskLegendItems');
    const highestLabel = document.getElementById('highestRiskLegendLabel');
    const helper = document.getElementById('riskLegendHelper');
    const floodOperationalActive = Boolean(
      state.map && state.layerGroups && state.map.hasLayer(state.layerGroups.floodHazards)
    );
    const landslideOperationalActive = Boolean(
      state.map && state.layerGroups && state.map.hasLayer(state.layerGroups.landslideHazards)
    );
    const floodReferenceActive = Boolean(
      state.map && state.layerGroups &&
      state.map.hasLayer(state.layerGroups.mgbFloodReference) &&
      state.hazardSourceModes.flood === 'MGB_LIVE_REFERENCE'
    );
    const landslideReferenceActive = Boolean(
      state.map && state.layerGroups &&
      state.map.hasLayer(state.layerGroups.mgbLandslideReference) &&
      state.hazardSourceModes.landslide === 'MGB_LIVE_REFERENCE'
    );
    const floodDevelopmentActive = floodOperationalActive &&
      state.hazardSourceModes.flood === 'DEVELOPMENT_PREVIEW';
    const landslideDevelopmentActive = landslideOperationalActive &&
      state.hazardSourceModes.landslide === 'DEVELOPMENT_PREVIEW';
    const floodActive = floodOperationalActive || floodReferenceActive;
    const landslideActive = landslideOperationalActive || landslideReferenceActive;
    const sourceLayerActive = floodActive || landslideActive;
    let contextLabel = 'Project risk classifications';
    let ariaLabel = 'Project risk classifications';
    let helperLabel = 'Legend only. No risk level has been assigned to any location.';

    if (floodActive && landslideActive && floodReferenceActive && landslideReferenceActive) {
      contextLabel = 'MGB LIVE REFERENCE \u2014 FLOOD + LANDSLIDE';
      ariaLabel = 'Combined DENR-MGB flood and landslide susceptibility classifications';
      helperLabel = 'Official MGB terminology and source-rendered symbology. The landslide service also renders "Debris flow path/Possible accumulation zone" where present.';
    } else if (floodActive && landslideActive && (floodReferenceActive || landslideReferenceActive)) {
      contextLabel = 'FLOOD + LANDSLIDE \u2014 EXPLICIT MIXED SOURCES';
      ariaLabel = 'Flood and landslide susceptibility classifications with separate source modes';
      helperLabel = 'Each hazard keeps its separately labeled CIVENTRAL operational or MGB live-reference source mode.';
      if (landslideReferenceActive) {
        helperLabel += ' The landslide service also renders "Debris flow path/Possible accumulation zone" where present.';
      }
    } else if (floodReferenceActive) {
      contextLabel = 'MGB LIVE REFERENCE DATA \u2014 FLOOD';
      ariaLabel = 'DENR-MGB flood susceptibility classifications';
      helperLabel = 'Official MGB source terminology and source-rendered flood symbology.';
    } else if (landslideReferenceActive) {
      contextLabel = 'MGB LIVE REFERENCE DATA \u2014 LANDSLIDE';
      ariaLabel = 'DENR-MGB landslide susceptibility classifications';
      helperLabel = 'Official MGB source terminology and source-rendered landslide symbology, including "Debris flow path/Possible accumulation zone" where present.';
    } else if (floodActive && landslideActive && (floodDevelopmentActive || landslideDevelopmentActive)) {
      contextLabel = 'LOCAL DEVELOPMENT PREVIEW \u2014 FLOOD + LANDSLIDE';
      ariaLabel = 'Local development-preview flood and landslide susceptibility classifications';
      helperLabel = 'Draft preview geometry remains local-development-only and is not published operational data.';
    } else if (floodDevelopmentActive) {
      contextLabel = 'LOCAL DEVELOPMENT PREVIEW \u2014 FLOOD';
      ariaLabel = 'Local development-preview flood susceptibility classifications';
      helperLabel = 'Draft preview geometry remains local-development-only and is not published operational data.';
    } else if (landslideDevelopmentActive) {
      contextLabel = 'LOCAL DEVELOPMENT PREVIEW \u2014 LANDSLIDE';
      ariaLabel = 'Local development-preview landslide susceptibility classifications';
      helperLabel = 'Draft preview geometry remains local-development-only and is not published operational data.';
    } else if (floodActive && landslideActive) {
      contextLabel = 'CIVENTRAL OPERATIONAL DATA \u2014 FLOOD + LANDSLIDE';
      ariaLabel = 'CIVENTRAL operational flood and landslide susceptibility classifications';
      helperLabel = 'Published CIVENTRAL geometry with MGB source terminology; the two hazard geometries remain separate.';
    } else if (floodActive) {
      contextLabel = 'CIVENTRAL OPERATIONAL DATA \u2014 FLOOD';
      ariaLabel = 'CIVENTRAL operational flood susceptibility classifications';
      helperLabel = 'Published CIVENTRAL geometry with MGB source terminology.';
    } else if (landslideActive) {
      contextLabel = 'CIVENTRAL OPERATIONAL DATA \u2014 LANDSLIDE';
      ariaLabel = 'CIVENTRAL operational landslide susceptibility classifications';
      helperLabel = 'Published CIVENTRAL geometry with MGB source terminology.';
    }

    if (context) context.textContent = contextLabel;
    if (legendItems) {
      legendItems.setAttribute('aria-label', ariaLabel);
      if (floodReferenceActive && landslideReferenceActive) {
        legendItems.dataset.palette = 'mgb-mixed';
      } else if (floodActive && landslideActive && (floodReferenceActive || landslideReferenceActive)) {
        legendItems.dataset.palette = 'source-mixed';
      } else if (floodReferenceActive) {
        legendItems.dataset.palette = 'mgb-flood';
      } else if (landslideReferenceActive) {
        legendItems.dataset.palette = 'mgb-landslide';
      } else {
        delete legendItems.dataset.palette;
      }
    }
    if (highestLabel) highestLabel.textContent = sourceLayerActive ? 'Very High' : 'Critical';
    if (helper) helper.textContent = helperLabel;
  }

  function developmentLayerStatus() {
    if (isOperationalMode()) {
      const activeSources = [];
      if (state.hazardSourceModes.flood === 'CIVENTRAL_OPERATIONAL') {
        activeSources.push('Flood: CIVENTRAL operational data.');
      } else if (state.hazardSourceModes.flood === 'MGB_LIVE_REFERENCE') {
        activeSources.push('Flood: live MGB reference data.');
      }
      if (state.hazardSourceModes.landslide === 'CIVENTRAL_OPERATIONAL') {
        activeSources.push('Landslide: CIVENTRAL operational data.');
      } else if (state.hazardSourceModes.landslide === 'MGB_LIVE_REFERENCE') {
        activeSources.push('Landslide: live MGB reference data.');
      }
      if (state.faultSourceMode === 'CIVENTRAL_OPERATIONAL') {
        activeSources.push('Faults: CIVENTRAL operational data.');
      } else if (state.faultSourceMode === 'PHIVOLCS_LIVE_REFERENCE') {
        activeSources.push('Faults: live PHIVOLCS reference data.');
      }
      if (state.evacuationCenterSourceMode === 'CIVENTRAL_OPERATIONAL') {
        activeSources.push('Centers: CIVENTRAL operational data.');
      } else if (state.evacuationCenterSourceMode === 'UNVERIFIED_ADMIN_REFERENCE') {
        activeSources.push('Centers: unverified admin reference.');
      }
      return activeSources.length > 0
        ? activeSources.join(' ')
        : 'Operational layers load on demand and display only published server-filtered records.';
    }
    const floodAvailable = Boolean(getFloodPreviewConfig());
    const landslideAvailable = Boolean(getLandslidePreviewConfig());
    const faultAvailable = Boolean(getFaultInformationPreviewConfig());
    const centersAvailable = Boolean(getEvacuationCenterPreviewConfig());
    const connected = [];
    const missing = [];

    (floodAvailable ? connected : missing).push('flood susceptibility');
    (landslideAvailable ? connected : missing).push('landslide susceptibility');
    (faultAvailable ? connected : missing).push('earthquake/fault information');
    (centersAvailable ? connected : missing).push('evacuation centers');

    if (connected.length === 4) {
      return 'Hazard and evacuation layers are connected in development preview.';
    }
    if (connected.length === 0) {
      return 'Hazard and evacuation datasets are not yet connected.';
    }

    return connected.join(', ') + ' connected in development preview. '
      + missing.join(', ') + ' not yet connected.';
  }

  function hazardControl(hazard) {
    const layerKey = hazard === 'flood' ? 'floodHazards' : 'landslideHazards';
    return document.querySelector('[data-map-layer="' + layerKey + '"]');
  }

  function mgbReferenceGroup(hazard) {
    if (!state.layerGroups) return null;
    return hazard === 'flood'
      ? state.layerGroups.mgbFloodReference
      : state.layerGroups.mgbLandslideReference;
  }

  function setHazardSourceMode(hazard, mode) {
    if (!['flood', 'landslide'].includes(hazard)) return;
    state.hazardSourceModes[hazard] = mode;
    const element = document.getElementById(hazard + 'HazardSourceMode');
    if (!element) return;
    const labels = {
      NOT_ACTIVE: 'Source mode: not active',
      LOADING: 'Checking operational/reference source',
      CIVENTRAL_OPERATIONAL: 'CIVENTRAL OPERATIONAL DATA',
      DEVELOPMENT_PREVIEW: 'LOCAL DEVELOPMENT PREVIEW',
      MGB_LIVE_REFERENCE: 'MGB LIVE REFERENCE DATA',
      MGB_REFERENCE_UNAVAILABLE: 'MGB REFERENCE UNAVAILABLE'
    };
    element.textContent = labels[mode] || labels.NOT_ACTIVE;
    element.dataset.mode = mode;
  }

  function setFaultSourceMode(mode) {
    state.faultSourceMode = mode;
    const element = document.getElementById('faultSourceMode');
    if (!element) return;
    const labels = {
      NOT_ACTIVE: 'Source mode: not active',
      LOADING: 'Checking operational/reference source',
      CIVENTRAL_OPERATIONAL: 'CIVENTRAL OPERATIONAL DATA',
      DEVELOPMENT_PREVIEW: 'LOCAL DEVELOPMENT PREVIEW',
      PHIVOLCS_LIVE_REFERENCE: 'PHIVOLCS LIVE REFERENCE DATA',
      PHIVOLCS_REFERENCE_UNAVAILABLE: 'PHIVOLCS REFERENCE UNAVAILABLE'
    };
    element.textContent = labels[mode] || labels.NOT_ACTIVE;
    element.dataset.mode = mode;
  }

  function setEvacuationCenterSourceMode(mode) {
    state.evacuationCenterSourceMode = mode;
    const element = document.getElementById('evacuationCenterSourceMode');
    if (!element) return;
    const labels = {
      NOT_ACTIVE: 'Source mode: not active',
      LOADING: 'Checking operational/reference source',
      CIVENTRAL_OPERATIONAL: 'CIVENTRAL OPERATIONAL DATA',
      DEVELOPMENT_PREVIEW: 'LOCAL DEVELOPMENT PREVIEW',
      UNVERIFIED_ADMIN_REFERENCE: 'UNVERIFIED ADMIN REFERENCE',
      NO_OPERATIONAL_CENTERS: 'NO PUBLISHED OPERATIONAL CENTERS',
      CENTER_REFERENCE_UNAVAILABLE: 'CENTER REFERENCE UNAVAILABLE'
    };
    element.textContent = labels[mode] || labels.NOT_ACTIVE;
    element.dataset.mode = mode;
  }

  function setBarangaySourceMode(mode) {
    state.barangaySourceMode = mode;
    const element = document.getElementById('barangaySearchStatus');
    if (element) element.dataset.sourceMode = mode;
  }

  function phivolcsReferenceIsActive() {
    return Boolean(
      state.map && state.layerGroups &&
      state.map.hasLayer(state.layerGroups.phivolcsFaultReference) &&
      state.faultSourceMode === 'PHIVOLCS_LIVE_REFERENCE'
    );
  }

  function renderPhivolcsReferenceNotice() {
    const notice = document.getElementById('phivolcsLiveReferenceNotice');
    if (notice) notice.hidden = !phivolcsReferenceIsActive();
  }

  function renderAdminCenterReferenceNotice() {
    const notice = document.getElementById('adminCenterReferenceNotice');
    if (!notice) return;
    notice.hidden = !(
      state.map && state.layerGroups &&
      state.map.hasLayer(state.layerGroups.evacuationCenters) &&
      state.evacuationCenterSourceMode === 'UNVERIFIED_ADMIN_REFERENCE'
    );
  }

  function renderAdminBarangayReferenceNotice() {
    const notice = document.getElementById('adminBarangayReferenceNotice');
    if (!notice) return;
    notice.hidden = state.barangaySourceMode !== 'INCOMPLETE_ADMIN_REFERENCE';
  }

  function liveReferenceIsActive(hazard) {
    const group = mgbReferenceGroup(hazard);
    return Boolean(
      state.map && group && state.map.hasLayer(group) &&
      state.hazardSourceModes[hazard] === 'MGB_LIVE_REFERENCE'
    );
  }

  function renderMgbLiveReferenceNotice() {
    const notice = document.getElementById('mgbLiveReferenceNotice');
    if (!notice) return;
    const floodActive = liveReferenceIsActive('flood');
    const landslideActive = liveReferenceIsActive('landslide');
    const referenceApi = getMgbReferenceApi();
    const activeLayers = [];
    if (floodActive) {
      activeLayers.push(referenceApi ? referenceApi.serviceFor('flood').label : 'MGB Live Flood Susceptibility');
    }
    if (landslideActive) {
      activeLayers.push(referenceApi
        ? referenceApi.serviceFor('landslide').label
        : 'MGB Live Rain-induced Landslide Susceptibility');
    }
    notice.hidden = activeLayers.length === 0;

    const activeLabel = document.getElementById('mgbLiveReferenceActiveLayers');
    const floodLink = document.getElementById('mgbFloodReferenceLink');
    const landslideLink = document.getElementById('mgbLandslideReferenceLink');
    const landslideNote = document.getElementById('mgbLandslideReferenceNote');
    if (activeLabel) activeLabel.textContent = activeLayers.join(' + ');
    if (floodLink) floodLink.hidden = !floodActive;
    if (landslideLink) landslideLink.hidden = !landslideActive;
    if (landslideNote) landslideNote.hidden = !landslideActive;
  }

  function syncMgbOutsideCityMask() {
    if (!state.map || !state.cityMaskLayer || !state.layerGroups) return;
    const referenceVisible = state.map.hasLayer(state.layerGroups.mgbFloodReference)
      || state.map.hasLayer(state.layerGroups.mgbLandslideReference);
    if (referenceVisible) {
      state.cityMaskLayer.addTo(state.map);
    } else {
      state.map.removeLayer(state.cityMaskLayer);
    }
  }

  function renderHazardSourceUi() {
    syncMgbOutsideCityMask();
    renderMgbLiveReferenceNotice();
    updateHazardLegend();
  }

  function removeMgbReferenceLayer(hazard, resetMode) {
    const group = mgbReferenceGroup(hazard);
    const imageOverlay = state.mgbReferenceImageOverlays[hazard];
    state.mgbReferenceRequestIds[hazard] += 1;
    if (imageOverlay && typeof imageOverlay.off === 'function') imageOverlay.off();
    if (imageOverlay && group) group.removeLayer(imageOverlay);
    if (group) {
      group.clearLayers();
      if (state.map) state.map.removeLayer(group);
    }
    state.mgbReferenceTileLayers[hazard] = null;
    state.mgbReferenceImageOverlays[hazard] = null;
    if (resetMode !== false) setHazardSourceMode(hazard, 'NOT_ACTIVE');
  }

  function failMgbReferenceLayer(hazard, control, message) {
    control = control || hazardControl(hazard);
    removeMgbReferenceLayer(hazard, false);
    if (control) {
      control.checked = false;
      control.disabled = true;
    }
    setHazardSourceMode(hazard, 'MGB_REFERENCE_UNAVAILABLE');
    renderHazardSourceUi();
    setStatus('hazardLayerStatus', message || 'Official MGB reference layer is temporarily unavailable.');
    if (hazard === 'flood') {
      state.mappedFloodSusceptibility = 'UNAVAILABLE';
      setStatus('mappedFloodSusceptibilityContent', 'The live MGB flood reference is temporarily unavailable.');
    }
  }

  function mgbReferenceImageOpacity(hazard) {
    const otherHazard = hazard === 'flood' ? 'landslide' : 'flood';
    const otherGroup = mgbReferenceGroup(otherHazard);
    const otherVisible = Boolean(otherGroup && state.map && state.map.hasLayer(otherGroup));
    return otherVisible ? 0.7 : 0.9;
  }

  function getMgbReferenceExportUrl(descriptor, bounds, size) {
    const bbox = bounds.toBBoxString();
    const params = new URLSearchParams({
      bbox: bbox,
      bboxSR: '4326',
      imageSR: '4326',
      size: size.width + ',' + size.height,
      transparent: 'true',
      format: descriptor.exportImageFormat,
      f: 'image'
    });
    return descriptor.exportUrl + '?' + params.toString();
  }

  function requestMgbReferenceImage(hazard, control) {
    const referenceConfig = getMgbLiveReferenceConfig();
    const group = mgbReferenceGroup(hazard);
    if (!referenceConfig || !group || !state.map || !control || !control.checked) return Promise.resolve(false);

    let descriptor;
    try {
      descriptor = referenceConfig.api.serviceFor(hazard);
    } catch (error) {
      return Promise.resolve(false);
    }

    const requestId = ++state.mgbReferenceRequestIds[hazard];
    const bounds = state.map.getBounds();
    const container = state.map.getContainer();
    const size = {
      width: Math.max(256, Math.min(2048, Math.round(container.clientWidth || 1024))),
      height: Math.max(256, Math.min(2048, Math.round(container.clientHeight || 768)))
    };
    const imageOverlay = L.imageOverlay(
      getMgbReferenceExportUrl(descriptor, bounds, size),
      bounds,
      {
        pane: 'mgbReferencePane',
        opacity: mgbReferenceImageOpacity(hazard),
        interactive: false,
        attribution: descriptor.attribution
      }
    );

    return new Promise(function (resolve) {
      let settled = false;
      const timeoutId = window.setTimeout(function () {
        imageOverlay.remove();
        settle(false);
      }, CONFIG.referenceLoadTimeoutMilliseconds);
      const settle = function (value) {
        if (settled) return;
        settled = true;
        window.clearTimeout(timeoutId);
        resolve(value);
      };
      imageOverlay.once('load', function () {
        const current = requestId === state.mgbReferenceRequestIds[hazard] && control.checked;
        if (!current) {
          imageOverlay.remove();
          settle(false);
          return;
        }
        const previous = state.mgbReferenceImageOverlays[hazard];
        imageOverlay.setOpacity(mgbReferenceImageOpacity(hazard));
        state.mgbReferenceImageOverlays[hazard] = imageOverlay;
        state.mgbReferenceTileLayers[hazard] = null;
        if (previous && previous !== imageOverlay) group.removeLayer(previous);
        setHazardSourceMode(hazard, referenceConfig.api.SOURCE_MODES.MGB_REFERENCE);
        renderHazardSourceUi();
        setStatus('hazardLayerStatus', developmentLayerStatus());
        if (hazard === 'flood') {
          state.mappedFloodSusceptibility = null;
          setStatus(
            'mappedFloodSusceptibilityContent',
            'The live MGB reference is display-only; CIVENTRAL does not receive feature geometry for point assessment.'
          );
        }
        settle(true);
      });
      imageOverlay.once('error', function () {
        imageOverlay.remove();
        settle(false);
      });
      imageOverlay.setOpacity(0);
      imageOverlay.addTo(group);
    });
  }

  function scheduleMgbReferenceRefresh() {
    if (state.mgbReferenceRefreshTimer) window.clearTimeout(state.mgbReferenceRefreshTimer);
    state.mgbReferenceRefreshTimer = window.setTimeout(function () {
      state.mgbReferenceRefreshTimer = null;
      ['flood', 'landslide'].forEach(function (hazard) {
        const control = hazardControl(hazard);
        const group = mgbReferenceGroup(hazard);
        if (control && control.checked && group && state.map && state.map.hasLayer(group)) {
          requestMgbReferenceImage(hazard, control);
        }
      });
    }, 180);
  }

  async function activateMgbReferenceLayer(hazard, control) {
    const referenceConfig = getMgbLiveReferenceConfig();
    const group = mgbReferenceGroup(hazard);
    if (!referenceConfig || !group || !state.map || !control || !control.checked) return false;

    let descriptor;
    try {
      descriptor = referenceConfig.api.serviceFor(hazard);
    } catch (error) {
      failMgbReferenceLayer(hazard, control, 'Official MGB reference layer is temporarily unavailable.');
      return false;
    }

    removeMgbReferenceLayer(hazard, false);
    setHazardSourceMode(hazard, 'LOADING');
    renderHazardSourceUi();
    group.addTo(state.map);
    syncMgbOutsideCityMask();
    const loaded = await requestMgbReferenceImage(hazard, control);
    if (!loaded && control.checked && state.mgbReferenceRequestIds[hazard] > 0) {
      failMgbReferenceLayer(hazard, control, referenceConfig.api.UNAVAILABLE_MESSAGE);
    }
    return loaded;
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

    if (state.selectedLandslideLayer) {
      state.selectedLandslideLayer.setStyle(landslideFeatureStyle(state.selectedLandslideLayer.feature));
      state.selectedLandslideLayer = null;
    }
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

    if (state.selectedLandslideLayer && state.selectedLandslideLayer.feature) {
      showLandslideLocationDetails(state.selectedLandslideLayer.feature.properties);
    } else if (state.selectedEvacuationCenterLayer && state.selectedEvacuationCenterLayer.feature) {
      showEvacuationCenterLocationDetails(state.selectedEvacuationCenterLayer.feature.properties);
    } else if (state.selectedFaultLayer && state.selectedFaultLayer.feature) {
      showOperationalFaultDetails(state.selectedFaultLayer.feature.properties);
    } else if (state.faultInformationActive && state.faultPreviewResponse && state.faultPreviewResponse.summary) {
      showFaultInformationDetails(state.faultPreviewResponse.summary);
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

  async function loadOperationalHazardCollections() {
    if (state.operationalHazardCollections) return state.operationalHazardCollections;
    if (state.operationalHazardLoadPromise) return state.operationalHazardLoadPromise;
    const hazardsEndpoint = operationalEndpoint('hazardsEndpoint');
    const lookupsEndpoint = operationalEndpoint('lookupsEndpoint');
    const adapter = getOperationalAdapter();
    if (!hazardsEndpoint || !lookupsEndpoint || !adapter || typeof adapter.mapHazards !== 'function') {
      throw new Error('Operational hazard configuration is unavailable.');
    }

    state.operationalHazardLoadPromise = (async function () {
      try {
        const payloads = await Promise.all([
          fetchOperationalJson(hazardsEndpoint, 'Hazard zones'),
          fetchOperationalJson(lookupsEndpoint, 'DRRM lookups')
        ]);
        state.operationalHazardCollections = adapter.mapHazards(payloads[0], payloads[1]);
        return state.operationalHazardCollections;
      } finally {
        state.operationalHazardLoadPromise = null;
      }
    })();
    return state.operationalHazardLoadPromise;
  }

  async function loadDraftFloodPreview() {
    if (state.floodPreviewLoaded) return true;
    if (state.floodLoadPromise) return state.floodLoadPromise;

    const previewConfig = getFloodPreviewConfig();
    if (!previewConfig || !state.map || !state.layerGroups) return false;

    state.floodLoadPromise = (async function () {
      try {
        state.floodFetchCount += 1;
        let featureCollection;
        if (previewConfig.operational === true) {
          featureCollection = (await loadOperationalHazardCollections()).flood;
          const counts = { LF: 0, MF: 0, HF: 0, VHF: 0 };
          featureCollection.features.forEach(function (feature) {
            counts[feature.properties.mgb_code] += 1;
          });
          state.floodPreviewClassCounts = Object.freeze(counts);
        } else {
          const response = await window.fetch(previewConfig.endpoint, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/geo+json, application/json' }
          });
          if (!response.ok) throw new Error('Flood preview request failed.');
          featureCollection = assertDraftFloodFeatureCollection(await response.json());
        }
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
            layer.on('click', function (event) {
              if (delegateFeatureClickToMapPointSelection(event)) return;
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
      removeMgbReferenceLayer('flood');
      clearFloodSelection();
      renderHazardSourceUi();
      setStatus('hazardLayerStatus', developmentLayerStatus());
      return;
    }

    if (!getFloodPreviewConfig()) {
      control.checked = false;
      setHazardSourceMode('flood', 'NOT_ACTIVE');
      renderHazardSourceUi();
      setStatus('hazardLayerStatus', 'Flood hazard data is not available in this environment.');
      return;
    }

    setHazardSourceMode('flood', 'LOADING');
    renderHazardSourceUi();
    setStatus('hazardLayerStatus', 'Checking for CIVENTRAL operational flood data...');
    const loaded = await loadDraftFloodPreview();

    if (!loaded) {
      control.checked = false;
      state.map.removeLayer(layerGroup);
      removeMgbReferenceLayer('flood');
      renderHazardSourceUi();
      setStatus('hazardLayerStatus', 'Flood hazard data could not be loaded.');
      return;
    }

    if (isOperationalMode() && state.floodPreviewFeatureCount === 0) {
      state.map.removeLayer(layerGroup);
      const liveReference = getMgbLiveReferenceConfig();
      const sourceMode = liveReference
        ? liveReference.api.selectOperationalOrReference(0, true)
        : 'MGB_REFERENCE_UNAVAILABLE';
      if (liveReference && sourceMode === liveReference.api.SOURCE_MODES.MGB_REFERENCE) {
        setStatus('hazardLayerStatus', 'Loading official MGB flood reference layer...');
        await activateMgbReferenceLayer('flood', control);
        return;
      }
      control.checked = false;
      setHazardSourceMode('flood', 'NOT_ACTIVE');
      renderHazardSourceUi();
      setStatus('hazardLayerStatus', 'Flood hazard operational data is not yet published.');
      return;
    }

    if (control.checked) {
      removeMgbReferenceLayer('flood', false);
      setHazardSourceMode('flood', isOperationalMode() ? 'CIVENTRAL_OPERATIONAL' : 'DEVELOPMENT_PREVIEW');
      layerGroup.addTo(state.map);
      renderHazardSourceUi();
      setStatus('hazardLayerStatus', developmentLayerStatus());
    }
  }

  function createLandslideTooltip(properties) {
    const content = document.createElement('div');
    const title = document.createElement('strong');
    const classification = document.createElement('div');

    title.textContent = 'Landslide-Prone Area';
    classification.textContent = properties.display_risk_label + ' (' + properties.mgb_code + ')';
    content.append(title, classification);
    return content;
  }

  function showLandslideLocationDetails(properties) {
    const details = document.getElementById('locationDetailsContent');
    if (!details) return;

    const title = document.createElement('strong');
    const susceptibility = document.createElement('div');
    const classification = document.createElement('div');
    const source = document.createElement('div');

    title.textContent = 'Landslide-Prone Area';
    susceptibility.textContent = 'Susceptibility: ' + properties.display_risk_label;
    classification.textContent = 'MGB Classification: ' + properties.mgb_code;
    source.textContent = 'Source: ' + properties.source_agency;
    details.replaceChildren(title, susceptibility, classification, source);
  }

  function selectLandslideFeature(layer, properties) {
    if (!layer || !properties) return false;

    if (state.selectedFloodLayer) {
      state.selectedFloodLayer.setStyle(floodFeatureStyle(state.selectedFloodLayer.feature));
      state.selectedFloodLayer = null;
    }
    if (state.selectedLandslideLayer && state.selectedLandslideLayer !== layer) {
      state.selectedLandslideLayer.setStyle(landslideFeatureStyle(state.selectedLandslideLayer.feature));
    }

    state.selectedLandslideLayer = layer;
    layer.setStyle(landslideFeatureSelectedStyle(layer.feature));
    if (typeof layer.bringToFront === 'function') layer.bringToFront();
    showLandslideLocationDetails(properties);
    return true;
  }

  function clearLandslideSelection() {
    if (state.selectedLandslideLayer) {
      state.selectedLandslideLayer.setStyle(landslideFeatureStyle(state.selectedLandslideLayer.feature));
    }
    state.selectedLandslideLayer = null;

    if (state.selectedFloodLayer && state.selectedFloodLayer.feature) {
      showFloodLocationDetails(state.selectedFloodLayer.feature.properties);
    } else if (state.selectedEvacuationCenterLayer && state.selectedEvacuationCenterLayer.feature) {
      showEvacuationCenterLocationDetails(state.selectedEvacuationCenterLayer.feature.properties);
    } else if (state.selectedFaultLayer && state.selectedFaultLayer.feature) {
      showOperationalFaultDetails(state.selectedFaultLayer.feature.properties);
    } else if (state.faultInformationActive && state.faultPreviewResponse && state.faultPreviewResponse.summary) {
      showFaultInformationDetails(state.faultPreviewResponse.summary);
    } else if (state.selectedBarangayRecord) {
      showDraftLocationDetails(state.selectedBarangayRecord.properties);
    } else {
      showDefaultLocationDetails();
    }
  }

  function assertDraftLandslideFeatureCollection(payload) {
    if (!payload || payload.type !== 'FeatureCollection' || !Array.isArray(payload.features)) {
      throw new Error('Invalid landslide GeoJSON response.');
    }
    if (payload.features.length !== 13) {
      throw new Error('Unexpected landslide feature count.');
    }

    const classCounts = { LL: 0, ML: 0, HL: 0, VHL: 0 };
    payload.features.forEach(function (feature) {
      const properties = feature && feature.properties;
      const geometry = feature && feature.geometry;
      const classification = properties ? LANDSLIDE_CLASSIFICATIONS[properties.mgb_code] : null;

      if (
        !feature || feature.type !== 'Feature' || !properties || !classification ||
        properties.hazard !== 'Landslide' ||
        properties.mgb_label !== classification.label ||
        properties.display_risk_label !== classification.displayLabel ||
        properties.source_agency !== 'DENR-MGB' ||
        !geometry || !['Polygon', 'MultiPolygon'].includes(geometry.type) ||
        !Array.isArray(geometry.coordinates) || geometry.coordinates.length === 0
      ) {
        throw new Error('Invalid landslide GeoJSON feature.');
      }

      classCounts[properties.mgb_code] += 1;
    });

    if (classCounts.LL !== 7 || classCounts.ML !== 2 || classCounts.HL !== 2 || classCounts.VHL !== 2) {
      throw new Error('Unexpected landslide classification counts.');
    }

    state.landslidePreviewClassCounts = Object.freeze(classCounts);
    return payload;
  }

  async function loadDraftLandslidePreview() {
    if (state.landslidePreviewLoaded) return true;
    if (state.landslideLoadPromise) return state.landslideLoadPromise;

    const previewConfig = getLandslidePreviewConfig();
    if (!previewConfig || !state.map || !state.layerGroups) return false;

    state.landslideLoadPromise = (async function () {
      try {
        state.landslideFetchCount += 1;
        let featureCollection;
        if (previewConfig.operational === true) {
          featureCollection = (await loadOperationalHazardCollections()).landslide;
          const counts = { LL: 0, ML: 0, HL: 0, VHL: 0 };
          featureCollection.features.forEach(function (feature) {
            counts[feature.properties.mgb_code] += 1;
          });
          state.landslidePreviewClassCounts = Object.freeze(counts);
        } else {
          const response = await window.fetch(previewConfig.endpoint, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/geo+json, application/json' }
          });
          if (!response.ok) throw new Error('Landslide preview request failed.');
          featureCollection = assertDraftLandslideFeatureCollection(await response.json());
        }
        const previewLayer = L.geoJSON(featureCollection, {
          pane: 'hazardPolygonPane',
          style: landslideFeatureStyle,
          onEachFeature: function (feature, layer) {
            layer.bindTooltip(createLandslideTooltip(feature.properties), {
              direction: 'top',
              sticky: true,
              opacity: 0.96
            });
            layer.on('mouseover', function () {
              if (state.selectedLandslideLayer !== layer) {
                layer.setStyle(landslideFeatureHoverStyle(feature));
              }
              if (typeof layer.bringToFront === 'function') layer.bringToFront();
            });
            layer.on('mouseout', function () {
              layer.setStyle(
                state.selectedLandslideLayer === layer
                  ? landslideFeatureSelectedStyle(feature)
                  : landslideFeatureStyle(feature)
              );
              if (
                state.selectedLandslideLayer && state.selectedLandslideLayer !== layer &&
                typeof state.selectedLandslideLayer.bringToFront === 'function'
              ) {
                state.selectedLandslideLayer.bringToFront();
              }
            });
            layer.on('click', function (event) {
              if (delegateFeatureClickToMapPointSelection(event)) return;
              selectLandslideFeature(layer, feature.properties);
            });
          }
        });

        state.layerGroups.landslideHazards.clearLayers();
        previewLayer.addTo(state.layerGroups.landslideHazards);
        state.landslidePreviewFeatureCollection = featureCollection;
        state.landslidePreviewLayer = previewLayer;
        state.landslidePreviewFeatureCount = featureCollection.features.length;
        state.landslidePreviewLoaded = true;
        return true;
      } catch (error) {
        state.layerGroups.landslideHazards.clearLayers();
        state.landslidePreviewFeatureCollection = null;
        state.landslidePreviewLayer = null;
        state.landslidePreviewFeatureCount = 0;
        state.landslidePreviewClassCounts = null;
        state.landslidePreviewLoaded = false;
        return false;
      } finally {
        state.landslideLoadPromise = null;
      }
    })();

    return state.landslideLoadPromise;
  }

  async function handleLandslideControl(control) {
    const layerGroup = state.layerGroups ? state.layerGroups.landslideHazards : null;
    if (!state.map || !layerGroup) return;

    if (!control.checked) {
      state.map.removeLayer(layerGroup);
      removeMgbReferenceLayer('landslide');
      clearLandslideSelection();
      renderHazardSourceUi();
      setStatus('hazardLayerStatus', developmentLayerStatus());
      return;
    }

    if (!getLandslidePreviewConfig()) {
      control.checked = false;
      setHazardSourceMode('landslide', 'NOT_ACTIVE');
      renderHazardSourceUi();
      setStatus('hazardLayerStatus', 'Landslide hazard data is not available in this environment.');
      return;
    }

    setHazardSourceMode('landslide', 'LOADING');
    renderHazardSourceUi();
    setStatus('hazardLayerStatus', 'Checking for CIVENTRAL operational landslide data...');
    const loaded = await loadDraftLandslidePreview();

    if (!loaded) {
      control.checked = false;
      state.map.removeLayer(layerGroup);
      removeMgbReferenceLayer('landslide');
      renderHazardSourceUi();
      setStatus('hazardLayerStatus', 'Landslide hazard data could not be loaded.');
      return;
    }

    if (isOperationalMode() && state.landslidePreviewFeatureCount === 0) {
      state.map.removeLayer(layerGroup);
      const liveReference = getMgbLiveReferenceConfig();
      const sourceMode = liveReference
        ? liveReference.api.selectOperationalOrReference(0, true)
        : 'MGB_REFERENCE_UNAVAILABLE';
      if (liveReference && sourceMode === liveReference.api.SOURCE_MODES.MGB_REFERENCE) {
        setStatus('hazardLayerStatus', 'Loading official MGB rain-induced landslide reference layer...');
        await activateMgbReferenceLayer('landslide', control);
        return;
      }
      control.checked = false;
      setHazardSourceMode('landslide', 'NOT_ACTIVE');
      renderHazardSourceUi();
      setStatus('hazardLayerStatus', 'Landslide hazard operational data is not yet published.');
      return;
    }

    if (control.checked) {
      removeMgbReferenceLayer('landslide', false);
      setHazardSourceMode('landslide', isOperationalMode() ? 'CIVENTRAL_OPERATIONAL' : 'DEVELOPMENT_PREVIEW');
      layerGroup.addTo(state.map);
      renderHazardSourceUi();
      setStatus('hazardLayerStatus', developmentLayerStatus());
    }
  }

  function removePhivolcsReferenceLayer(resetMode) {
    const group = state.layerGroups ? state.layerGroups.phivolcsFaultReference : null;
    state.phivolcsReferenceRequestId += 1;
    if (state.phivolcsReferenceTileLayer
      && typeof state.phivolcsReferenceTileLayer.off === 'function') {
      state.phivolcsReferenceTileLayer.off();
    }
    if (group) {
      group.clearLayers();
      if (state.map) state.map.removeLayer(group);
    }
    state.phivolcsReferenceTileLayer = null;
    if (resetMode !== false) setFaultSourceMode('NOT_ACTIVE');
    renderPhivolcsReferenceNotice();
  }

  function failPhivolcsReferenceLayer(control, message) {
    removePhivolcsReferenceLayer(false);
    if (control) {
      control.checked = false;
      control.disabled = true;
    }
    state.faultInformationActive = false;
    setFaultSourceMode('PHIVOLCS_REFERENCE_UNAVAILABLE');
    renderPhivolcsReferenceNotice();
    setStatus(
      'hazardLayerStatus',
      message || 'Official PHIVOLCS fault reference is temporarily unavailable.'
    );
  }

  async function activatePhivolcsReferenceLayer(control) {
    const referenceConfig = getPhivolcsLiveReferenceConfig();
    const group = state.layerGroups ? state.layerGroups.phivolcsFaultReference : null;
    if (!referenceConfig || !group || !state.map || !control || !control.checked) return false;

    let descriptor;
    try {
      descriptor = referenceConfig.api.service();
    } catch (error) {
      failPhivolcsReferenceLayer(control, referenceConfig.api.UNAVAILABLE_MESSAGE);
      return false;
    }

    removePhivolcsReferenceLayer(false);
    const requestId = ++state.phivolcsReferenceRequestId;
    state.phivolcsReferenceTileLoadCount = 0;
    state.phivolcsReferenceTileErrorCount = 0;
    setFaultSourceMode('LOADING');

    const tileOptions = {
      pane: 'phivolcsReferencePane',
      layers: descriptor.layers,
      format: descriptor.format,
      transparent: descriptor.transparent,
      version: '1.3.0',
      maxZoom: CONFIG.maximumZoom,
      noWrap: true,
      updateWhenIdle: true,
      keepBuffer: 1,
      attribution: descriptor.attribution
    };
    if (state.cityBoundaryBounds) tileOptions.bounds = state.cityBoundaryBounds.pad(0.25);

    const tileLayer = L.tileLayer.wms(descriptor.wmsUrl, tileOptions);
    state.phivolcsReferenceTileLayer = tileLayer;
    tileLayer.addTo(group);
    group.addTo(state.map);

    return new Promise(function (resolve) {
      let activationSettled = false;
      const timeoutId = window.setTimeout(function () {
        if (requestId !== state.phivolcsReferenceRequestId || !control.checked) {
          if (!activationSettled) {
            activationSettled = true;
            resolve(false);
          }
          return;
        }
        failPhivolcsReferenceLayer(control, referenceConfig.api.UNAVAILABLE_MESSAGE);
        if (!activationSettled) {
          activationSettled = true;
          resolve(false);
        }
      }, CONFIG.referenceLoadTimeoutMilliseconds);

      tileLayer.on('tileload', function () {
        if (requestId !== state.phivolcsReferenceRequestId || !control.checked) return;
        state.phivolcsReferenceTileLoadCount += 1;
        if (activationSettled) return;
        activationSettled = true;
        window.clearTimeout(timeoutId);
        state.faultInformationActive = true;
        setFaultSourceMode(referenceConfig.api.SOURCE_MODES.PHIVOLCS_REFERENCE);
        state.faultPreviewResponse = {
          summary: descriptor.summary,
          faults: { type: 'FeatureCollection', features: [] }
        };
        showFaultInformationDetails(descriptor.summary);
        renderPhivolcsReferenceNotice();
        setStatus('hazardLayerStatus', developmentLayerStatus());
        resolve(true);
      });

      tileLayer.on('tileerror', function () {
        if (requestId !== state.phivolcsReferenceRequestId || !control.checked) return;
        state.phivolcsReferenceTileErrorCount += 1;
      });
    });
  }

  function assertDraftFaultPreview(payload) {
    const summary = payload && payload.summary;
    const faults = payload && payload.faults;
    if (
      !summary || summary.crosses_caloocan !== false ||
      summary.nearest_fault_name !== 'West Valley Fault' ||
      Number(summary.minimum_city_distance_km) !== 3.76 ||
      summary.source_agency !== 'DOST-PHIVOLCS' ||
      summary.display_mode !== 'INFORMATION_ONLY' ||
      summary.advisory !== 'No mapped active fault intersects Caloocan City based on the current PHIVOLCS dataset.' ||
      !faults || faults.type !== 'FeatureCollection' || !Array.isArray(faults.features) ||
      faults.features.length !== 156
    ) {
      throw new Error('Invalid fault-information response.');
    }

    faults.features.forEach(function (feature) {
      const properties = feature && feature.properties;
      const geometry = feature && feature.geometry;
      if (
        !feature || feature.type !== 'Feature' || !properties ||
        properties.fault_name !== 'West Valley Fault' ||
        properties.feature_class !== 'Active Fault' ||
        properties.source_agency !== 'DOST-PHIVOLCS' ||
        properties.crosses_caloocan !== false ||
        properties.location_context !== 'Nearby mapped active fault outside Caloocan City' ||
        !geometry || geometry.type !== 'MultiLineString' ||
        !Array.isArray(geometry.coordinates) || geometry.coordinates.length === 0
      ) {
        throw new Error('Invalid fault-information feature.');
      }

      geometry.coordinates.forEach(function (line) {
        if (!Array.isArray(line) || line.length < 2) {
          throw new Error('Invalid fault-information line.');
        }
        line.forEach(function (position) {
          if (
            !Array.isArray(position) || position.length !== 2 ||
            !Number.isFinite(Number(position[0])) || !Number.isFinite(Number(position[1])) ||
            Number(position[0]) < -180 || Number(position[0]) > 180 ||
            Number(position[1]) < -90 || Number(position[1]) > 90
          ) {
            throw new Error('Invalid fault-information coordinate.');
          }
        });
      });
    });

    return payload;
  }

  function showFaultInformationDetails(summary) {
    const details = document.getElementById('locationDetailsContent');
    if (!details || !summary) return;

    const title = document.createElement('strong');
    const crossing = document.createElement('div');
    const nearest = document.createElement('div');
    const distance = document.createElement('div');
    const source = document.createElement('div');
    const advisory = document.createElement('p');
    const riskCaveat = document.createElement('p');

    title.textContent = 'Earthquake / Fault Information';
    crossing.textContent = 'Mapped active fault crossing Caloocan: None';
    nearest.textContent = 'Approximate nearest mapped fault reference: ' + summary.nearest_fault_name;
    distance.textContent = 'Approximate reference distance from city boundary: '
      + Number(summary.minimum_city_distance_km).toFixed(2) + ' km';
    source.textContent = 'Source: ' + summary.source_agency;
    advisory.textContent = summary.advisory;
    advisory.className = 'civ-map-helper mt-2';
    riskCaveat.textContent = 'Fault distance does not indicate an absence of earthquake risk.';
    riskCaveat.className = 'civ-map-helper mt-1';
    details.replaceChildren(title, crossing, nearest, distance, source, advisory, riskCaveat);
  }

  function showOperationalFaultDetails(properties) {
    const details = document.getElementById('locationDetailsContent');
    if (!details || !properties) return;
    const title = document.createElement('strong');
    const classification = document.createElement('div');
    const source = document.createElement('div');
    const caveat = document.createElement('p');
    title.textContent = properties.fault_name;
    classification.textContent = 'Feature class: ' + properties.feature_class;
    source.textContent = 'Source: ' + properties.source_agency;
    caveat.className = 'civ-map-helper mt-1';
    caveat.textContent = 'Published fault information is contextual and does not indicate an absence of earthquake risk.';
    details.replaceChildren(title, classification, source, caveat);
  }

  function selectOperationalFault(layer, properties) {
    if (!layer || !properties) return false;
    if (state.selectedFaultLayer && state.selectedFaultLayer !== layer) {
      state.selectedFaultLayer.setStyle(operationalFaultStyle());
    }
    state.selectedFaultLayer = layer;
    layer.setStyle(Object.assign({}, operationalFaultStyle(), { weight: 5, opacity: 1 }));
    if (typeof layer.bringToFront === 'function') layer.bringToFront();
    showOperationalFaultDetails(properties);
    return true;
  }

  function restoreLocationDetailsAfterFault() {
    if (state.selectedFaultLayer) state.selectedFaultLayer.setStyle(operationalFaultStyle());
    state.selectedFaultLayer = null;
    if (state.selectedEvacuationCenterLayer && state.selectedEvacuationCenterLayer.feature) {
      showEvacuationCenterLocationDetails(state.selectedEvacuationCenterLayer.feature.properties);
    } else if (state.selectedFloodLayer && state.selectedFloodLayer.feature) {
      showFloodLocationDetails(state.selectedFloodLayer.feature.properties);
    } else if (state.selectedBarangayRecord) {
      showDraftLocationDetails(state.selectedBarangayRecord.properties);
    } else {
      showDefaultLocationDetails();
    }
  }

  async function loadDraftFaultInformation() {
    if (state.faultPreviewLoaded) return true;
    if (state.faultLoadPromise) return state.faultLoadPromise;

    const previewConfig = getFaultInformationPreviewConfig();
    if (!previewConfig || !state.map || !state.layerGroups) return false;

    state.faultLoadPromise = (async function () {
      try {
        state.faultFetchCount += 1;
        const response = await window.fetch(previewConfig.endpoint, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' }
        });
        if (!response.ok) throw new Error('Fault-information preview request failed.');

        const body = await response.json();
        const adapter = getOperationalAdapter();
        const faultCollection = previewConfig.operational === true
          ? (adapter && typeof adapter.mapFaults === 'function' ? adapter.mapFaults(body) : null)
          : null;
        const preview = previewConfig.operational === true
          ? (faultCollection ? { summary: null, faults: faultCollection } : null)
          : assertDraftFaultPreview(body && body.success === true ? body.data : null);
        if (!preview) throw new Error('Operational fault adapter is unavailable.');

        state.layerGroups.earthquakeFaults.clearLayers();
        if (previewConfig.operational === true) {
          L.geoJSON(preview.faults, {
            pane: 'operationalLinePane',
            style: operationalFaultStyle,
            onEachFeature: function (feature, layer) {
              layer.bindTooltip(feature.properties.fault_name, { direction: 'top', sticky: true, opacity: 0.96 });
              layer.on('click', function (event) {
                if (delegateFeatureClickToMapPointSelection(event)) return;
                selectOperationalFault(layer, feature.properties);
              });
            }
          }).addTo(state.layerGroups.earthquakeFaults);
        }
        state.faultPreviewResponse = preview;
        state.faultPreviewFeatureCount = preview.faults.features.length;
        state.faultPreviewLoaded = true;
        return true;
      } catch (error) {
        state.layerGroups.earthquakeFaults.clearLayers();
        state.faultPreviewResponse = null;
        state.faultPreviewFeatureCount = 0;
        state.faultPreviewLoaded = false;
        return false;
      } finally {
        state.faultLoadPromise = null;
      }
    })();

    return state.faultLoadPromise;
  }

  async function handleFaultInformationControl(control) {
    const layerGroup = state.layerGroups ? state.layerGroups.earthquakeFaults : null;
    if (!state.map || !layerGroup) return;

    if (!control.checked) {
      state.faultInformationActive = false;
      state.map.removeLayer(layerGroup);
      removePhivolcsReferenceLayer();
      restoreLocationDetailsAfterFault();
      setStatus('hazardLayerStatus', developmentLayerStatus());
      return;
    }

    if (!getFaultInformationPreviewConfig()) {
      control.checked = false;
      state.faultInformationActive = false;
      setStatus('hazardLayerStatus', 'Earthquake/fault information is not available in this environment.');
      return;
    }

    setStatus('hazardLayerStatus', 'Loading DOST-PHIVOLCS fault information...');
    setFaultSourceMode('LOADING');
    const loaded = await loadDraftFaultInformation();
    if (!loaded) {
      control.checked = false;
      state.faultInformationActive = false;
      state.map.removeLayer(layerGroup);
      removePhivolcsReferenceLayer();
      setStatus('hazardLayerStatus', 'Earthquake/fault information could not be loaded.');
      return;
    }

    if (isOperationalMode() && state.faultPreviewFeatureCount === 0) {
      state.map.removeLayer(layerGroup);
      const liveReference = getPhivolcsLiveReferenceConfig();
      const sourceMode = liveReference
        ? liveReference.api.selectOperationalOrReference(0, true)
        : 'PHIVOLCS_REFERENCE_UNAVAILABLE';
      if (liveReference
        && sourceMode === liveReference.api.SOURCE_MODES.PHIVOLCS_REFERENCE) {
        setStatus('hazardLayerStatus', 'Loading official PHIVOLCS fault reference...');
        await activatePhivolcsReferenceLayer(control);
        return;
      }
      control.checked = false;
      state.faultInformationActive = false;
      setFaultSourceMode('NOT_ACTIVE');
      setStatus('hazardLayerStatus', 'Fault operational data is not yet published.');
      return;
    }

    if (control.checked) {
      state.faultInformationActive = true;
      if (isOperationalMode()) {
        removePhivolcsReferenceLayer(false);
        setFaultSourceMode('CIVENTRAL_OPERATIONAL');
        layerGroup.addTo(state.map);
        renderPhivolcsReferenceNotice();
        setStatus('hazardLayerStatus', state.faultPreviewFeatureCount + ' published fault feature'
          + (state.faultPreviewFeatureCount === 1 ? ' is' : 's are') + ' displayed.');
      } else {
        setFaultSourceMode('DEVELOPMENT_PREVIEW');
        showFaultInformationDetails(state.faultPreviewResponse.summary);
        setStatus('hazardLayerStatus', developmentLayerStatus());
      }
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

  function assertAdminCenterReferenceFeatureCollection(payload) {
    if (!payload || payload.type !== 'FeatureCollection' || !Array.isArray(payload.features)
      || payload.features.length !== 15) {
      throw new Error('Invalid admin center reference response.');
    }
    const seenIds = new Set();
    payload.features.forEach(function (feature) {
      const properties = feature && feature.properties;
      const geometry = feature && feature.geometry;
      const coordinates = geometry && geometry.coordinates;
      const keys = properties ? Object.keys(properties).sort() : [];
      const expectedKeys = [
        'barangay_display_location',
        'display_status',
        'location_status',
        'managing_office',
        'name',
        'reference_id',
        'verification_status'
      ].sort();
      if (
        !feature || feature.type !== 'Feature' || !properties ||
        JSON.stringify(keys) !== JSON.stringify(expectedKeys) ||
        typeof properties.reference_id !== 'string' ||
        !/^[0-9a-f-]{36}$/i.test(properties.reference_id) ||
        seenIds.has(properties.reference_id) ||
        typeof properties.name !== 'string' || properties.name.trim() === '' ||
        !/^Barangay (?:[1-9]|[1-9]\d|1\d\d)$/.test(properties.barangay_display_location) ||
        properties.location_status !== 'APPROXIMATE_REFERENCE_LOCATION' ||
        properties.managing_office !== 'City Government of Caloocan' ||
        properties.verification_status !== 'PENDING_LGU_VERIFICATION' ||
        properties.display_status !== 'UNVERIFIED CENTER REFERENCE' ||
        !geometry || geometry.type !== 'Point' || !Array.isArray(coordinates) ||
        coordinates.length !== 2 || !Number.isFinite(Number(coordinates[0])) ||
        !Number.isFinite(Number(coordinates[1]))
      ) {
        throw new Error('Invalid unverified center reference feature.');
      }
      seenIds.add(properties.reference_id);
    });
    return payload;
  }

  function evacuationCenterMarkerIcon(referenceOnly) {
    return L.divIcon({
      className: 'civ-evacuation-marker-wrap' + (referenceOnly ? ' is-reference' : ''),
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
    const status = document.createElement('div');
    const location = document.createElement('div');
    const source = document.createElement('div');
    const referenceOnly = properties.display_status === 'UNVERIFIED CENTER REFERENCE';

    heading.textContent = referenceOnly ? 'Unverified Center Reference' : 'Evacuation Center';
    name.textContent = properties.name;
    status.textContent = 'Status: ' + properties.display_status;
    if (referenceOnly) {
      const barangay = document.createElement('div');
      const managingOffice = document.createElement('div');
      const disclosure = document.createElement('p');
      barangay.textContent = 'Barangay/display location: ' + properties.barangay_display_location;
      location.textContent = 'Location: approximate reference location';
      managingOffice.textContent = 'Managing office: ' + properties.managing_office;
      disclosure.className = 'civ-map-helper mt-1';
      disclosure.textContent = 'Reference locations are shown for administrative planning only and remain pending LGU verification.';
      if (includeHeading) content.appendChild(heading);
      content.append(name, status, barangay, location, managingOffice, disclosure);
      return content;
    }

    location.textContent = properties.address
      ? 'Address: ' + properties.address
      : 'Location: ' + properties.location_verification_status;
    source.textContent = 'Source: ' + properties.source_agency;
    if (includeHeading) content.appendChild(heading);
    content.appendChild(name);
    if (properties.barangay_name) {
      const barangay = document.createElement('div');
      barangay.textContent = 'Barangay: ' + properties.barangay_name.replace(/^Barangay\s+/i, '');
      content.appendChild(barangay);
    }
    content.append(status, location);
    if (Number.isFinite(properties.capacity)) {
      const capacity = document.createElement('div');
      capacity.textContent = 'Capacity: ' + properties.capacity.toLocaleString('en-PH');
      content.appendChild(capacity);
    }
    if (properties.contact_phone) {
      const contact = document.createElement('div');
      contact.textContent = 'Contact: ' + properties.contact_phone;
      content.appendChild(contact);
    }
    content.appendChild(source);
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
    } else if (state.selectedFaultLayer && state.selectedFaultLayer.feature) {
      showOperationalFaultDetails(state.selectedFaultLayer.feature.properties);
    } else if (state.faultInformationActive && state.faultPreviewResponse && state.faultPreviewResponse.summary) {
      showFaultInformationDetails(state.faultPreviewResponse.summary);
    } else if (state.selectedBarangayRecord) {
      showDraftLocationDetails(state.selectedBarangayRecord.properties);
    } else {
      showDefaultLocationDetails();
    }
  }

  async function loadDraftEvacuationCenterPreview() {
    if (state.evacuationCenterPreviewLoaded) {
      setEvacuationCenterSourceMode(
        state.evacuationCenterLoadedSourceMode || 'NOT_ACTIVE'
      );
      return true;
    }
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
        if (!response.ok) throw new Error('Evacuation-center data request failed.');
        const payload = await response.json();
        const adapter = getOperationalAdapter();
        let featureCollection;
        let sourceMode;
        if (previewConfig.operational === true) {
          if (!adapter || typeof adapter.mapEvacuationCenters !== 'function'
            || typeof adapter.resolveEvacuationCenterSource !== 'function') {
            throw new Error('Operational evacuation-center adapter is unavailable.');
          }
          const operationalCollection = adapter.mapEvacuationCenters(
            payload,
            state.operationalBarangayCollection
          );
          state.operationalEvacuationCenterFeatureCollection = operationalCollection;
          const adminReferenceConfig = getAdminEvacuationCenterReferenceConfig();
          const loadAdminReference = adminReferenceConfig
            ? async function () {
                state.evacuationCenterFetchCount += 1;
                const referenceResponse = await window.fetch(adminReferenceConfig.endpoint, {
                  method: 'GET',
                  credentials: 'same-origin',
                  cache: 'no-store',
                  headers: { Accept: 'application/geo+json, application/json' }
                });
                if (!referenceResponse.ok) {
                  throw new Error('Admin center reference request failed.');
                }
                return assertAdminCenterReferenceFeatureCollection(await referenceResponse.json());
              }
            : null;
          const selection = await adapter.resolveEvacuationCenterSource(
            operationalCollection,
            loadAdminReference
          );
          featureCollection = selection.featureCollection;
          sourceMode = selection.sourceMode;
        } else {
          featureCollection = assertDraftEvacuationCenterFeatureCollection(payload);
          sourceMode = 'DEVELOPMENT_PREVIEW';
        }
        if (!featureCollection) throw new Error('Operational evacuation-center adapter is unavailable.');
        const referenceOnly = sourceMode === 'UNVERIFIED_ADMIN_REFERENCE';
        const previewLayer = L.geoJSON(featureCollection, {
          pane: 'markerPane',
          pointToLayer: function (feature, latlng) {
            return L.marker(latlng, {
              pane: 'markerPane',
              icon: evacuationCenterMarkerIcon(referenceOnly)
            });
          },
          onEachFeature: function (feature, layer) {
            layer.bindTooltip(feature.properties.name, { direction: 'top', opacity: 0.96 });
            layer.bindPopup(createEvacuationCenterContent(feature.properties, false));
            layer.on('click', function (event) {
              if (delegateFeatureClickToMapPointSelection(event)) return;
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
        state.evacuationCenterLoadedSourceMode = sourceMode;
        setEvacuationCenterSourceMode(sourceMode);
        return true;
      } catch (error) {
        state.layerGroups.evacuationCenters.clearLayers();
        state.evacuationCenterFeatureCollection = null;
        state.evacuationCenterPreviewLayer = null;
        state.evacuationCenterPreviewFeatureCount = 0;
        state.evacuationCenterPreviewLoaded = false;
        state.evacuationCenterLoadedSourceMode = null;
        setEvacuationCenterSourceMode('CENTER_REFERENCE_UNAVAILABLE');
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
      setEvacuationCenterSourceMode(
        state.evacuationCenterLoadedSourceMode || 'NOT_ACTIVE'
      );
      renderAdminCenterReferenceNotice();
      setStatus('hazardLayerStatus', developmentLayerStatus());
      return;
    }

    if (!getEvacuationCenterPreviewConfig()) {
      control.checked = false;
      control.disabled = true;
      setStatus('hazardLayerStatus', 'Evacuation-center data is not available in this environment.');
      return;
    }

    setStatus('hazardLayerStatus', 'Loading evacuation centers...');
    setEvacuationCenterSourceMode('LOADING');
    const loaded = await loadDraftEvacuationCenterPreview();
    if (!loaded) {
      control.checked = false;
      control.disabled = true;
      state.map.removeLayer(layerGroup);
      renderAdminCenterReferenceNotice();
      setStatus('hazardLayerStatus', 'Evacuation-center data could not be loaded.');
      return;
    }

    if (isOperationalMode() && state.evacuationCenterPreviewFeatureCount === 0) {
      control.checked = false;
      control.disabled = true;
      state.map.removeLayer(layerGroup);
      setEvacuationCenterSourceMode('NO_OPERATIONAL_CENTERS');
      renderAdminCenterReferenceNotice();
      setStatus('hazardLayerStatus', 'No published evacuation centers are currently available.');
      return;
    }

    if (control.checked) {
      control.disabled = false;
      layerGroup.addTo(state.map);
      renderAdminCenterReferenceNotice();
      setStatus('hazardLayerStatus', developmentLayerStatus());
    }
  }

  function pointSelectionToolsAvailable() {
    return Boolean(
      window.turf &&
      typeof window.turf.point === 'function' &&
      typeof window.turf.booleanPointInPolygon === 'function'
    );
  }

  function routeGeospatialToolsAvailable() {
    return Boolean(
      pointSelectionToolsAvailable() &&
      typeof window.turf.lineString === 'function' &&
      typeof window.turf.length === 'function' &&
      typeof window.turf.along === 'function'
    );
  }

  function routePointIsInsideCaloocan(latitude, longitude) {
    if (!pointSelectionToolsAvailable() || !state.cityBoundaryFeatureCollection) return false;

    const point = window.turf.point([longitude, latitude]);
    return state.cityBoundaryFeatureCollection.features.some(function (feature) {
      return window.turf.booleanPointInPolygon(point, feature, { ignoreBoundary: false });
    });
  }

  function mapPointSelectionActive() {
    return state.routeOriginSelectionActive || state.forecastLocationSelectionActive;
  }

  function handleMapPointSelection(event, clickSource) {
    if (!mapPointSelectionActive() || !event || !event.latlng) return false;

    if (state.routeOriginSelectionActive) {
      return handleRouteOriginPoint(event.latlng, clickSource || 'MAP');
    }

    if (state.forecastLocationSelectionActive) {
      const latitude = Number(event.latlng.lat);
      const longitude = Number(event.latlng.lng);
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return false;
      void setForecastLocation(latitude, longitude, 'MAP_CLICK');
      return true;
    }

    return false;
  }

  function delegateFeatureClickToMapPointSelection(event) {
    if (!mapPointSelectionActive()) return false;

    handleMapPointSelection(event, 'FEATURE');
    if (event && event.originalEvent && L.DomEvent) {
      L.DomEvent.stopPropagation(event.originalEvent);
    }
    if (state.map && typeof state.map.closePopup === 'function') {
      state.map.closePopup();
    }
    return true;
  }

  function routeOriginMarkerIcon() {
    return L.divIcon({
      className: 'civ-route-origin-marker-wrap',
      html: '<span class="civ-route-origin-marker" aria-hidden="true"><i class="fa-solid fa-location-crosshairs"></i></span>',
      iconSize: [28, 28],
      iconAnchor: [14, 14],
      tooltipAnchor: [0, -16]
    });
  }

  function routeDestinationMarkerIcon() {
    return L.divIcon({
      className: 'civ-route-destination-marker-wrap',
      html: '<span class="civ-route-destination-marker" aria-hidden="true"><i class="fa-solid fa-house-medical"></i></span>',
      iconSize: [28, 28],
      iconAnchor: [14, 14],
      tooltipAnchor: [0, -16]
    });
  }

  function setRouteOriginSelectionActive(active) {
    if (active === true && state.forecastLocationSelectionActive) {
      setForecastLocationSelectionActive(false);
      setStatus(
        'forecastLocationStatus',
        state.forecastLocation
          ? 'Flood Risk Check evaluates the selected exact location inside Caloocan City.'
          : 'Select an exact point inside Caloocan City.'
      );
    }
    state.routeOriginSelectionActive = active === true;
    const container = document.getElementById(CONFIG.containerId);
    const button = document.getElementById('setRouteOriginButton');

    if (container) container.classList.toggle('is-selecting-route-origin', state.routeOriginSelectionActive);
    if (button) {
      button.setAttribute('aria-pressed', state.routeOriginSelectionActive ? 'true' : 'false');
      const label = button.querySelector('span');
      if (label) label.textContent = state.routeOriginSelectionActive ? 'Click Inside Caloocan' : 'Set Location on Map';
    }
  }

  function activateRouteOriginSelectionMode() {
    state.routeOriginSetButtonClickCount += 1;

    if (
      !getEvacuationRoutePreviewConfig() ||
      !state.map ||
      !state.layerGroups ||
      !pointSelectionToolsAvailable() ||
      !state.cityBoundaryFeatureCollection
    ) {
      state.routeOriginLastResult = 'MODE_NOT_ACTIVE';
      setStatus('routeOriginStatus', 'Route-origin map selection is not available yet.');
      return false;
    }

    setRouteOriginSelectionActive(true);
    state.routeOriginLastResult = 'MODE_ACTIVATED';
    setStatus('routeOriginStatus', 'Click an exact point inside the Caloocan boundary.');
    return true;
  }

  function resetRouteDiagnostics() {
    state.routeAlternativesReceived = 0;
    state.recommendedRouteIndex = null;
    state.routeDistanceMeters = null;
    state.routeDurationSeconds = null;
    state.routeHazardScore = null;
    state.routeFloodExposure = null;
    state.routeLandslideExposure = null;
    state.routeGeometryRendered = false;
  }

  function clearRenderedRoute(updateStatus) {
    const routeGroup = state.layerGroups ? state.layerGroups.evacuationRoutes : null;
    if (routeGroup && state.routeGeometryLayer) routeGroup.removeLayer(state.routeGeometryLayer);
    if (routeGroup && state.routeDestinationMarker) routeGroup.removeLayer(state.routeDestinationMarker);

    state.routeGeometryLayer = null;
    state.routeDestinationMarker = null;
    resetRouteDiagnostics();

    const result = document.getElementById('routeResultContent');
    const clearButton = document.getElementById('clearRouteButton');
    if (result) {
      result.replaceChildren();
      result.hidden = true;
    }
    if (clearButton) clearButton.hidden = true;
    if (updateStatus) {
      setStatus(
        'routeRequestStatus',
        state.routeOrigin && state.selectedEvacuationCenterId
          ? 'Ready to request route alternatives.'
          : 'Select a starting point and evacuation center.'
      );
    }
  }

  function updateFindRouteButton() {
    const button = document.getElementById('findSafeRouteButton');
    if (!button) return;

    button.disabled = Boolean(
      state.routeRequestPending ||
      !state.routeOrigin ||
      !state.selectedEvacuationCenterId ||
      !state.routeCenterOptionsLoaded ||
      !getEvacuationRoutePreviewConfig() ||
      !routeGeospatialToolsAvailable()
    );
  }

  function applyRouteOrigin(latlng) {
    const latitude = Number(latlng && latlng.lat);
    const longitude = Number(latlng && latlng.lng);
    state.routeOriginLastAttemptLat = Number.isFinite(latitude) ? latitude : null;
    state.routeOriginLastAttemptLng = Number.isFinite(longitude) ? longitude : null;

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
      state.routeOriginLastResult = 'INVALID_COORDINATES';
      setStatus('routeOriginStatus', 'Choose a valid point inside the validated Caloocan boundary.');
      return false;
    }

    if (!state.map || !state.layerGroups || !routePointIsInsideCaloocan(latitude, longitude)) {
      state.routeOriginLastResult = 'OUTSIDE_CALOOCAN';
      setStatus('routeOriginStatus', 'Choose a point inside the validated Caloocan boundary.');
      return false;
    }

    clearRenderedRoute(false);
    const routeGroup = state.layerGroups.evacuationRoutes;
    if (state.routeOriginMarker) routeGroup.removeLayer(state.routeOriginMarker);

    state.routeOrigin = Object.freeze({ latitude: latitude, longitude: longitude });
    state.routeOriginMarker = L.marker([latitude, longitude], {
      pane: 'routeOverlayPane',
      icon: routeOriginMarkerIcon(),
      keyboard: true,
      title: 'Selected route starting location'
    }).bindTooltip('Selected starting location', { direction: 'top', opacity: 0.96 });
    state.routeOriginMarker.addTo(routeGroup);

    const input = document.getElementById('routeStartInput');
    const clearButton = document.getElementById('clearRouteOriginButton');
    if (input) input.value = latitude.toFixed(6) + ', ' + longitude.toFixed(6);
    if (clearButton) clearButton.hidden = false;
    setStatus('routeOriginStatus', 'Exact map location selected inside Caloocan City.');
    setStatus(
      'routeRequestStatus',
      state.selectedEvacuationCenterId
        ? 'Ready to request route alternatives.'
        : 'Select an evacuation center, then request route alternatives.'
    );
    setRouteOriginSelectionActive(false);
    updateFindRouteButton();
    updateForecastRouteOriginButton();
    state.routeOriginLastResult = 'POINT_ACCEPTED';
    return true;
  }

  function handleRouteOriginPoint(latlng, clickSource) {
    if (clickSource === 'FEATURE') {
      state.routeOriginFeatureClickCount += 1;
    } else {
      state.routeOriginMapClickCount += 1;
    }

    if (!state.routeOriginSelectionActive) {
      state.routeOriginLastResult = 'MODE_NOT_ACTIVE';
      return false;
    }

    state.routeOriginSelectionAttemptCount += 1;
    return applyRouteOrigin(latlng);
  }

  function clearRouteOrigin() {
    setRouteOriginSelectionActive(false);
    clearRenderedRoute(false);
    if (state.layerGroups && state.routeOriginMarker) {
      state.layerGroups.evacuationRoutes.removeLayer(state.routeOriginMarker);
    }
    state.routeOrigin = null;
    state.routeOriginMarker = null;

    const input = document.getElementById('routeStartInput');
    const clearButton = document.getElementById('clearRouteOriginButton');
    if (input) input.value = 'No starting point selected';
    if (clearButton) clearButton.hidden = true;
    setStatus('routeOriginStatus', 'Choose an exact point inside Caloocan City.');
    setStatus('routeRequestStatus', 'Select a starting point and evacuation center.');
    updateFindRouteButton();
    updateForecastRouteOriginButton();
  }

  function populateRouteCenterOptions() {
    const select = document.getElementById('routeCenterSelect');
    if (isOperationalMode() || !getEvacuationRoutePreviewConfig()
      || !select || !state.evacuationCenterFeatureCollection) return false;

    const features = state.evacuationCenterFeatureCollection.features.slice().sort(function (first, second) {
      return first.properties.name.localeCompare(second.properties.name);
    });
    if (features.length !== 15) return false;

    state.routeCentersById = new Map();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select a development center';
    select.replaceChildren(placeholder);

    features.forEach(function (feature) {
      const id = feature.properties.evacuation_center_id;
      const option = document.createElement('option');
      option.value = id;
      option.textContent = feature.properties.name + ' \u2014 ' + feature.properties.barangay_name;
      state.routeCentersById.set(id, feature);
      select.appendChild(option);
    });

    select.disabled = false;
    state.routeCenterOptionsLoaded = true;
    updateFindRouteButton();
    return true;
  }

  function highestHazardAtPoint(point, featureCollection) {
    let highest = null;

    featureCollection.features.forEach(function (feature) {
      const displayLabel = feature.properties && feature.properties.display_risk_label;
      const weight = ROUTE_HAZARD_WEIGHTS[displayLabel];
      if (!Number.isFinite(weight) || (highest && highest.weight >= weight)) return;

      if (window.turf.booleanPointInPolygon(point, feature, { ignoreBoundary: false })) {
        highest = { label: displayLabel, weight: weight };
      }
    });

    return highest;
  }

  function highestRouteSusceptibility(first, second) {
    if (!first) return second;
    if (!second) return first;
    return ROUTE_HAZARD_WEIGHTS[first] >= ROUTE_HAZARD_WEIGHTS[second] ? first : second;
  }

  function routeExposureCategory(scoreDensity, exposedSamplePercentage, highestEncountered) {
    // CIVENTRAL development categories based on weighted score per route
    // sample: 0=MINIMAL, <=1.5=LOW, <=4=MODERATE, >4=HIGH.
    const exposureFraction = Math.max(0, Math.min(1, exposedSamplePercentage / 100));
    const highestExposureScore = highestEncountered
      ? ROUTE_HAZARD_WEIGHTS[highestEncountered] * exposureFraction
      : 0;
    const effectiveScore = Math.max(scoreDensity, highestExposureScore);
    if (effectiveScore === 0) return 'MINIMAL';
    if (effectiveScore <= 1.5) return 'LOW';
    if (effectiveScore <= 4) return 'MODERATE';
    return 'HIGH';
  }

  function scoreRouteAlternative(route) {
    const line = window.turf.lineString(route.geometry.coordinates);
    const lineLengthKilometers = window.turf.length(line, { units: 'kilometers' });
    if (!Number.isFinite(lineLengthKilometers) || lineLengthKilometers <= 0) {
      throw new Error('Invalid route geometry length.');
    }

    const segmentCount = Math.max(1, Math.ceil(route.distance_meters / CONFIG.routeSampleIntervalMeters));
    const segmentDistanceMeters = route.distance_meters / segmentCount;
    let hazardScore = 0;
    let exposedSamples = 0;
    let exposedDistanceMeters = 0;
    const flood = { sampleCount: 0, distanceMeters: 0, highest: null };
    const landslide = { sampleCount: 0, distanceMeters: 0, highest: null };
    let highestEncountered = null;

    for (let index = 0; index < segmentCount; index += 1) {
      const midpointKilometers = lineLengthKilometers * ((index + 0.5) / segmentCount);
      const point = window.turf.along(line, midpointKilometers, { units: 'kilometers' });
      const floodHazard = highestHazardAtPoint(point, state.floodPreviewFeatureCollection);
      const landslideHazard = highestHazardAtPoint(point, state.landslidePreviewFeatureCollection);

      if (floodHazard) {
        hazardScore += floodHazard.weight;
        flood.sampleCount += 1;
        flood.distanceMeters += segmentDistanceMeters;
        flood.highest = highestRouteSusceptibility(flood.highest, floodHazard.label);
        highestEncountered = highestRouteSusceptibility(highestEncountered, floodHazard.label);
      }
      if (landslideHazard) {
        hazardScore += landslideHazard.weight;
        landslide.sampleCount += 1;
        landslide.distanceMeters += segmentDistanceMeters;
        landslide.highest = highestRouteSusceptibility(landslide.highest, landslideHazard.label);
        highestEncountered = highestRouteSusceptibility(highestEncountered, landslideHazard.label);
      }
      if (floodHazard || landslideHazard) {
        exposedSamples += 1;
        exposedDistanceMeters += segmentDistanceMeters;
      }
    }

    const scoreDensity = hazardScore / segmentCount;
    return Object.freeze({
      sampleCount: segmentCount,
      samplingIntervalMeters: CONFIG.routeSampleIntervalMeters,
      exposedSampleCount: exposedSamples,
      exposedSamplePercentage: (exposedSamples / segmentCount) * 100,
      exposedDistanceMeters: exposedDistanceMeters,
      hazardScore: hazardScore,
      scoreDensity: scoreDensity,
      category: routeExposureCategory(scoreDensity, (exposedSamples / segmentCount) * 100, highestEncountered),
      highestEncountered: highestEncountered,
      flood: Object.freeze(flood),
      landslide: Object.freeze(landslide)
    });
  }

  function assertRoutePreviewResponse(payload, selectedCenterId) {
    if (!payload || payload.status !== 'Development route alternatives'
      || payload.routing_profile !== 'driving'
      || payload.requested_alternatives !== 3
      || !Number.isInteger(payload.returned_alternatives)
      || payload.returned_alternatives < 1 || payload.returned_alternatives > 3
      || !payload.destination || payload.destination.evacuation_center_id !== selectedCenterId
      || typeof payload.destination.name !== 'string' || payload.destination.name.trim() === ''
      || payload.destination.location_verification_status !== 'Location pending LGU verification'
      || !payload.destination.geometry || payload.destination.geometry.type !== 'Point'
      || !Array.isArray(payload.destination.geometry.coordinates)
      || payload.destination.geometry.coordinates.length !== 2
      || !Array.isArray(payload.routes) || payload.routes.length !== payload.returned_alternatives) {
      throw new Error('Invalid route response.');
    }

    payload.routes.forEach(function (route, index) {
      if (!route || route.route_index !== index
        || !Number.isFinite(Number(route.distance_meters)) || Number(route.distance_meters) < 0
        || !Number.isFinite(Number(route.duration_seconds)) || Number(route.duration_seconds) < 0
        || !route.geometry || route.geometry.type !== 'LineString'
        || !Array.isArray(route.geometry.coordinates) || route.geometry.coordinates.length < 2) {
        throw new Error('Invalid route alternative.');
      }
      route.geometry.coordinates.forEach(function (position) {
        if (!Array.isArray(position) || position.length < 2
          || !Number.isFinite(Number(position[0])) || !Number.isFinite(Number(position[1]))
          || Number(position[0]) < -180 || Number(position[0]) > 180
          || Number(position[1]) < -90 || Number(position[1]) > 90) {
          throw new Error('Invalid route coordinate.');
        }
      });
    });

    return payload;
  }

  function formatRouteDistance(distanceMeters) {
    return (distanceMeters / 1000).toFixed(distanceMeters >= 10000 ? 1 : 2) + ' km';
  }

  function formatRouteDuration(durationSeconds) {
    return Math.max(1, Math.round(durationSeconds / 60)) + ' min';
  }

  function formatHazardExposure(exposure) {
    if (!exposure || exposure.sampleCount === 0) return 'None mapped';
    return formatRouteDistance(exposure.distanceMeters) + ' ' + exposure.highest;
  }

  function appendRouteResultRow(container, label, value) {
    const row = document.createElement('div');
    const labelElement = document.createElement('span');
    labelElement.className = 'civ-route-result-label';
    labelElement.textContent = label + ': ';
    row.append(labelElement, document.createTextNode(value));
    container.appendChild(row);
  }

  function renderRouteResult(route, analysis, destination, alternativeCount, allAlternativesExposed) {
    const result = document.getElementById('routeResultContent');
    if (!result) return;

    const title = document.createElement('strong');
    title.textContent = 'Development Recommended Route';
    const reason = document.createElement('p');
    reason.textContent = 'Reason: Lowest mapped flood/landslide exposure among ' + alternativeCount
      + (alternativeCount === 1 ? ' available road alternative.' : ' available road alternatives.');
    const disclaimer = document.createElement('p');
    disclaimer.className = 'civ-route-result-warning';
    disclaimer.textContent = 'Pending LGU verification. Road and hazard conditions may change during an actual emergency.';

    result.replaceChildren(title);
    appendRouteResultRow(result, 'From', 'Selected map location');
    appendRouteResultRow(result, 'To', destination.name);
    appendRouteResultRow(result, 'Destination location', destination.location_verification_status);
    appendRouteResultRow(result, 'Distance', formatRouteDistance(route.distance_meters));
    appendRouteResultRow(result, 'Estimated road travel', formatRouteDuration(route.duration_seconds));
    appendRouteResultRow(result, 'Mapped Hazard Exposure', analysis.category);
    appendRouteResultRow(result, 'Approx. mapped exposure distance', formatRouteDistance(analysis.exposedDistanceMeters));
    appendRouteResultRow(result, 'Highest mapped susceptibility', analysis.highestEncountered || 'None mapped');
    appendRouteResultRow(result, 'Flood Exposure', formatHazardExposure(analysis.flood));
    appendRouteResultRow(result, 'Landslide Exposure', formatHazardExposure(analysis.landslide));
    appendRouteResultRow(result, 'Sampled route points exposed', analysis.exposedSampleCount + ' of '
      + analysis.sampleCount + ' (' + analysis.exposedSamplePercentage.toFixed(1) + '%)');
    result.appendChild(reason);
    if (allAlternativesExposed) {
      const warning = document.createElement('p');
      warning.className = 'civ-route-result-warning';
      warning.textContent = 'All available road alternatives have mapped hazard exposure.';
      result.appendChild(warning);
    }
    result.appendChild(disclaimer);
    result.hidden = false;
  }

  function renderRecommendedRoute(route, destination) {
    const routeGroup = state.layerGroups.evacuationRoutes;
    const destinationCoordinates = destination.geometry.coordinates;

    state.routeGeometryLayer = L.geoJSON({
      type: 'Feature',
      properties: { presentation: 'Development Recommended Route' },
      geometry: route.geometry
    }, {
      pane: 'routeOverlayPane',
      interactive: false,
      style: developmentRouteStyle
    }).addTo(routeGroup);

    state.routeDestinationMarker = L.marker(
      [Number(destinationCoordinates[1]), Number(destinationCoordinates[0])],
      {
        pane: 'routeOverlayPane',
        icon: routeDestinationMarkerIcon(),
        keyboard: true,
        title: destination.name
      }
    ).bindTooltip(destination.name + ' — destination', { direction: 'top', opacity: 0.96 });
    state.routeDestinationMarker.addTo(routeGroup);
    state.routeGeometryRendered = true;

    const bounds = state.routeGeometryLayer.getBounds();
    if (bounds.isValid()) {
      state.map.fitBounds(bounds, { padding: [32, 32], maxZoom: 15, animate: false });
      setActiveMapFocus(null);
    }
  }

  async function findDevelopmentEvacuationRoute() {
    const previewConfig = getEvacuationRoutePreviewConfig();
    const selectedCenterId = state.selectedEvacuationCenterId;
    if (!previewConfig || !state.routeOrigin || !selectedCenterId || state.routeRequestPending) return;

    setRouteOriginSelectionActive(false);
    state.routeRequestPending = true;
    clearRenderedRoute(false);
    updateFindRouteButton();
    const button = document.getElementById('findSafeRouteButton');
    const buttonLabel = button ? button.querySelector('span') : null;
    const setOriginButton = document.getElementById('setRouteOriginButton');
    const clearOriginButton = document.getElementById('clearRouteOriginButton');
    const centerSelect = document.getElementById('routeCenterSelect');
    if (button) button.classList.add('is-loading');
    if (buttonLabel) buttonLabel.textContent = 'Evaluating Routes...';
    if (setOriginButton) setOriginButton.disabled = true;
    if (clearOriginButton) clearOriginButton.disabled = true;
    if (centerSelect) centerSelect.disabled = true;
    setStatus('routeRequestStatus', 'Loading mapped hazards and road-route alternatives...');

    try {
      const hazardAvailability = await Promise.all([
        loadDraftFloodPreview(),
        loadDraftLandslidePreview()
      ]);
      if (!hazardAvailability[0] || !hazardAvailability[1]
        || !state.floodPreviewFeatureCollection || !state.landslidePreviewFeatureCollection) {
        throw new Error('Hazard-aware route analysis is unavailable.');
      }

      state.routingFetchCount += 1;
      const response = await window.fetch(previewConfig.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          origin: state.routeOrigin,
          evacuation_center_id: selectedCenterId
        })
      });
      const body = await response.json().catch(function () { return null; });
      if (!response.ok || !body || body.success !== true) {
        const safeMessages = [
          'Starting location must be inside Caloocan City.',
          'No routable road path was found for the selected locations.',
          'Road routing service is temporarily unavailable.'
        ];
        const message = body && safeMessages.includes(body.message)
          ? body.message
          : 'Road routing service is temporarily unavailable.';
        throw new Error(message);
      }

      const routeResponse = assertRoutePreviewResponse(body.data, selectedCenterId);
      const evaluated = routeResponse.routes.map(function (route) {
        return { route: route, analysis: scoreRouteAlternative(route) };
      });
      evaluated.sort(function (first, second) {
        return first.analysis.hazardScore - second.analysis.hazardScore
          || first.route.duration_seconds - second.route.duration_seconds
          || first.route.distance_meters - second.route.distance_meters
          || first.route.route_index - second.route.route_index;
      });

      const recommended = evaluated[0];
      const allAlternativesExposed = evaluated.every(function (candidate) {
        return candidate.analysis.exposedSampleCount > 0;
      });
      state.routeAlternativesReceived = routeResponse.returned_alternatives;
      state.recommendedRouteIndex = recommended.route.route_index;
      state.routeDistanceMeters = recommended.route.distance_meters;
      state.routeDurationSeconds = recommended.route.duration_seconds;
      state.routeHazardScore = recommended.analysis.hazardScore;
      state.routeFloodExposure = recommended.analysis.flood;
      state.routeLandslideExposure = recommended.analysis.landslide;

      renderRecommendedRoute(recommended.route, routeResponse.destination);
      renderRouteResult(
        recommended.route,
        recommended.analysis,
        routeResponse.destination,
        routeResponse.returned_alternatives,
        allAlternativesExposed
      );
      const clearButton = document.getElementById('clearRouteButton');
      if (clearButton) clearButton.hidden = false;
      setStatus('routeRequestStatus', 'Development recommended route calculated from real road alternatives.');
    } catch (error) {
      clearRenderedRoute(false);
      const message = error && typeof error.message === 'string'
        ? error.message
        : 'Road routing service is temporarily unavailable.';
      setStatus('routeRequestStatus', message);
    } finally {
      state.routeRequestPending = false;
      if (button) button.classList.remove('is-loading');
      if (buttonLabel) buttonLabel.textContent = 'Find Safe Route';
      if (setOriginButton) setOriginButton.disabled = false;
      if (clearOriginButton) clearOriginButton.disabled = false;
      if (centerSelect) centerSelect.disabled = !state.routeCenterOptionsLoaded;
      updateFindRouteButton();
    }
  }

  function createApprovedRouteContent(properties) {
    const content = document.createElement('div');
    const title = document.createElement('strong');
    const origin = document.createElement('div');
    const destination = document.createElement('div');
    const distance = document.createElement('div');
    title.textContent = properties.route_name;
    origin.textContent = 'From: ' + properties.origin_name;
    destination.textContent = 'To: ' + properties.destination_name;
    distance.textContent = 'Published distance: ' + formatRouteDistance(properties.distance_meters);
    content.append(title, origin, destination, distance);
    if (properties.safety_notes) {
      const notes = document.createElement('p');
      notes.className = 'civ-map-helper mt-1';
      notes.textContent = properties.safety_notes;
      content.appendChild(notes);
    }
    return content;
  }

  async function loadOperationalEvacuationRoutes() {
    const config = getOperationalEvacuationRouteConfig();
    const adapter = getOperationalAdapter();
    const routeGroup = state.layerGroups ? state.layerGroups.evacuationRoutes : null;
    if (!config || !adapter || typeof adapter.mapEvacuationRoutes !== 'function' || !routeGroup) return false;

    state.operationalRouteStatus = 'LOADING';
    state.operationalRouteFetchCount += 1;
    try {
      const payload = await fetchOperationalJson(config.endpoint, 'Approved evacuation routes');
      const collection = adapter.mapEvacuationRoutes(
        payload,
        state.operationalEvacuationCenterFeatureCollection
      );
      routeGroup.clearLayers();
      state.routeGeometryLayer = L.geoJSON(collection, {
        pane: 'routeOverlayPane',
        style: approvedRouteStyle,
        onEachFeature: function (feature, layer) {
          layer.bindTooltip(feature.properties.route_name, { direction: 'top', sticky: true, opacity: 0.96 });
          layer.bindPopup(createApprovedRouteContent(feature.properties));
          layer.on('click', function () {
            const details = document.getElementById('locationDetailsContent');
            if (details) details.replaceChildren(createApprovedRouteContent(feature.properties));
          });
        }
      }).addTo(routeGroup);
      state.operationalRouteFeatureCount = collection.features.length;
      state.operationalRouteStatus = collection.features.length === 0 ? 'EMPTY' : 'LOADED';
      state.routeGeometryRendered = collection.features.length > 0;
      return true;
    } catch (error) {
      routeGroup.clearLayers();
      state.routeGeometryLayer = null;
      state.operationalRouteFeatureCount = 0;
      state.operationalRouteStatus = 'ERROR';
      state.routeGeometryRendered = false;
      return false;
    }
  }

  async function initializeOperationalEvacuationRoutes() {
    const setOriginButton = document.getElementById('setRouteOriginButton');
    const clearOriginButton = document.getElementById('clearRouteOriginButton');
    const centerSelect = document.getElementById('routeCenterSelect');
    const findButton = document.getElementById('findSafeRouteButton');
    const startInput = document.getElementById('routeStartInput');
    const routePanel = document.getElementById('evacuationRoutePanel');
    if (!setOriginButton || !centerSelect || !findButton || !state.map) return false;

    setOriginButton.disabled = true;
    if (clearOriginButton) clearOriginButton.hidden = true;
    centerSelect.disabled = true;
    findButton.disabled = true;
    if (startInput) startInput.value = 'Stored approved routes only';
    setStatus('routeOriginStatus', 'Operational mode displays stored approved routes; route generation is unavailable.');
    centerSelect.replaceChildren(new Option('Loading approved routes...', ''));

    const centerHelper = centerSelect.parentElement
      ? centerSelect.parentElement.querySelector('p.civ-map-helper')
      : null;
    if (centerHelper) centerHelper.textContent = 'Only stored APPROVED routes returned by the operational API are shown.';
    const disclaimer = routePanel
      ? routePanel.querySelector('.space-y-3 > p.civ-map-helper')
      : null;
    if (disclaimer) {
      disclaimer.textContent = 'Published routes remain subject to current road, hazard, and official emergency instructions.';
    }

    // Center names improve route labels, but a center endpoint failure must not
    // cause approved route geometry to be fabricated or replaced.
    await loadDraftEvacuationCenterPreview();
    const loaded = await loadOperationalEvacuationRoutes();
    const connectionStatus = document.getElementById('preparednessConnectionStatus');
    if (connectionStatus) {
      const icon = document.createElement('i');
      icon.className = loaded ? 'fa-solid fa-route' : 'fa-solid fa-plug-circle-xmark';
      icon.setAttribute('aria-hidden', 'true');
      connectionStatus.replaceChildren(icon, document.createTextNode(
        loaded
          ? (state.operationalRouteFeatureCount > 0
              ? ' Operational Data'
              : ' No Published Operational Routes')
          : ' Route Data Unavailable'
      ));
    }

    if (!loaded) {
      centerSelect.replaceChildren(new Option('Approved routes unavailable', ''));
      setStatus('routeRequestStatus', 'Approved evacuation routes could not be loaded.');
      return false;
    }
    if (state.operationalRouteFeatureCount === 0) {
      centerSelect.replaceChildren(new Option('No approved routes published', ''));
      setStatus('routeRequestStatus', 'No approved evacuation routes are currently published.');
      return true;
    }

    centerSelect.replaceChildren(new Option(
      state.operationalRouteFeatureCount + (state.operationalRouteFeatureCount === 1
        ? ' approved route displayed'
        : ' approved routes displayed'),
      ''
    ));
    setStatus('routeRequestStatus', state.operationalRouteFeatureCount + (state.operationalRouteFeatureCount === 1
      ? ' approved evacuation route is displayed on the map.'
      : ' approved evacuation routes are displayed on the map.'));
    return true;
  }

  async function initializeEvacuationRouteTool() {
    if (getOperationalEvacuationRouteConfig()) return initializeOperationalEvacuationRoutes();
    const config = getEvacuationRoutePreviewConfig();
    const setOriginButton = document.getElementById('setRouteOriginButton');
    const clearOriginButton = document.getElementById('clearRouteOriginButton');
    const clearRouteButton = document.getElementById('clearRouteButton');
    const centerSelect = document.getElementById('routeCenterSelect');
    const findButton = document.getElementById('findSafeRouteButton');
    if (!setOriginButton || !clearOriginButton || !centerSelect || !findButton || !state.map) return false;
    if (setOriginButton.dataset.routeToolBound === 'true') return state.routeCenterOptionsLoaded;
    setOriginButton.dataset.routeToolBound = 'true';

    clearOriginButton.addEventListener('click', clearRouteOrigin);
    if (clearRouteButton) clearRouteButton.addEventListener('click', function () {
      clearRenderedRoute(true);
      updateFindRouteButton();
    });
    centerSelect.addEventListener('change', function () {
      const value = centerSelect.value;
      state.selectedEvacuationCenterId = state.routeCentersById.has(value) ? value : null;
      clearRenderedRoute(false);
      setStatus(
        'routeRequestStatus',
        state.selectedEvacuationCenterId
          ? (state.routeOrigin ? 'Ready to request route alternatives.' : 'Select an exact starting point on the map.')
          : 'Select a starting point and evacuation center.'
      );
      updateFindRouteButton();
    });
    findButton.addEventListener('click', findDevelopmentEvacuationRoute);
    if (!config || !pointSelectionToolsAvailable() || !state.cityBoundaryFeatureCollection) {
      setOriginButton.disabled = true;
      centerSelect.disabled = true;
      centerSelect.replaceChildren(new Option('Route preview unavailable', ''));
      setStatus('routeRequestStatus', 'Hazard-aware route analysis is unavailable.');
      return false;
    }

    const centersLoaded = await loadDraftEvacuationCenterPreview();
    if (!centersLoaded || !populateRouteCenterOptions()) {
      setOriginButton.disabled = true;
      centerSelect.disabled = true;
      centerSelect.replaceChildren(new Option('Development centers unavailable', ''));
      setStatus('routeRequestStatus', 'Evacuation-center options could not be loaded.');
      return false;
    }

    const connectionStatus = document.getElementById('preparednessConnectionStatus');
    if (connectionStatus) {
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-route';
      icon.setAttribute('aria-hidden', 'true');
      connectionStatus.replaceChildren(icon, document.createTextNode(' Route Preview'));
    }
    setStatus('routeRequestStatus', 'Select a starting point and evacuation center.');
    return true;
  }

  function forecastLocationMarkerIcon() {
    return L.divIcon({
      className: 'civ-forecast-location-marker-wrap',
      html: '<span class="civ-forecast-location-marker" aria-hidden="true"><i class="fa-solid fa-cloud-rain"></i></span>',
      iconSize: [28, 28],
      iconAnchor: [14, 28],
      tooltipAnchor: [0, -25]
    });
  }

  function setForecastLocationSelectionActive(active) {
    if (active === true && state.routeOriginSelectionActive) {
      setRouteOriginSelectionActive(false);
      setStatus(
        'routeOriginStatus',
        state.routeOrigin
          ? 'Exact map location selected inside Caloocan City.'
          : 'Choose an exact point inside Caloocan City.'
      );
    }
    state.forecastLocationSelectionActive = active === true;
    const container = document.getElementById(CONFIG.containerId);
    const button = document.getElementById('setForecastLocationButton');

    if (container) {
      container.classList.toggle('is-selecting-forecast-location', state.forecastLocationSelectionActive);
    }
    if (button) {
      button.setAttribute('aria-pressed', state.forecastLocationSelectionActive ? 'true' : 'false');
      const label = button.querySelector('span');
      if (label) label.textContent = state.forecastLocationSelectionActive ? 'Click Inside Caloocan' : 'Choose Assessment Location';
    }
  }

  function getCurrentRouteOrigin() {
    let candidate = state.routeOrigin;

    if (!candidate && state.routeOriginMarker && typeof state.routeOriginMarker.getLatLng === 'function') {
      const markerPosition = state.routeOriginMarker.getLatLng();
      if (markerPosition) {
        candidate = {
          latitude: Number(markerPosition.lat),
          longitude: Number(markerPosition.lng)
        };
      }
    }

    if (!candidate) return null;
    const latitude = Number(candidate.latitude);
    const longitude = Number(candidate.longitude);
    if (
      !Number.isFinite(latitude) ||
      !Number.isFinite(longitude) ||
      !routePointIsInsideCaloocan(latitude, longitude)
    ) {
      return null;
    }

    if (
      !state.routeOrigin ||
      state.routeOrigin.latitude !== latitude ||
      state.routeOrigin.longitude !== longitude
    ) {
      state.routeOrigin = Object.freeze({ latitude: latitude, longitude: longitude });
    }

    return state.routeOrigin;
  }

  function updateForecastRouteOriginButton() {
    const button = document.getElementById('useRouteOriginForForecastButton');
    if (!button) return false;

    const enabled = Boolean(getCurrentRouteOrigin() && getFloodForecastPreviewConfig());
    button.disabled = false;
    button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
    button.classList.toggle('is-disabled', !enabled);
    return enabled;
  }

  function findBarangayAtPoint(latitude, longitude) {
    if (!pointSelectionToolsAvailable()) return null;
    const point = window.turf.point([longitude, latitude]);

    return state.searchableBarangays.find(function (record) {
      return record.feature && window.turf.booleanPointInPolygon(point, record.feature, { ignoreBoundary: false });
    }) || null;
  }

  function clearForecastLocation() {
    setForecastLocationSelectionActive(false);
    if (state.layerGroups && state.forecastLocationMarker) {
      state.layerGroups.forecastLocations.removeLayer(state.forecastLocationMarker);
    }
    state.forecastLocation = null;
    state.forecastLocationMarker = null;
    state.forecastUsesRouteOrigin = false;
    state.mappedFloodSusceptibility = null;

    const input = document.getElementById('forecastLocationInput');
    const clearButton = document.getElementById('clearForecastLocationButton');
    if (input) input.value = 'No assessment location selected';
    if (clearButton) clearButton.hidden = true;
    setStatus('forecastLocationStatus', 'Select an exact point inside Caloocan City.');
    setStatus(
      'mappedFloodSusceptibilityContent',
      'Flood Risk Check evaluates the selected exact location against DENR-MGB mapped flood susceptibility.'
    );
  }

  async function evaluateMappedFloodSusceptibility() {
    const content = document.getElementById('mappedFloodSusceptibilityContent');
    if (!state.forecastLocation || !content) return false;

    content.textContent = 'Checking the selected point against DENR-MGB flood susceptibility...';
    const loaded = await loadDraftFloodPreview();
    if (!loaded || !state.floodPreviewFeatureCollection || !pointSelectionToolsAvailable()) {
      state.mappedFloodSusceptibility = 'UNAVAILABLE';
      content.textContent = 'Mapped flood susceptibility is unavailable for this development preview.';
      return false;
    }

    const point = window.turf.point([
      state.forecastLocation.longitude,
      state.forecastLocation.latitude
    ]);
    let highest = null;

    state.floodPreviewFeatureCollection.features.forEach(function (feature) {
      const properties = feature.properties || {};
      const weight = ROUTE_HAZARD_WEIGHTS[properties.display_risk_label];
      if (!Number.isFinite(weight)) return;
      if (!window.turf.booleanPointInPolygon(point, feature, { ignoreBoundary: false })) return;
      if (!highest || weight > highest.weight) {
        highest = {
          weight: weight,
          displayLabel: properties.display_risk_label,
          code: properties.mgb_code,
          source: properties.source_agency
        };
      }
    });

    if (!highest) {
      state.mappedFloodSusceptibility = 'NONE_MAPPED';
      const heading = document.createElement('strong');
      const description = document.createElement('span');
      const source = document.createElement('span');
      heading.textContent = 'No mapped susceptibility polygon';
      description.textContent = 'No mapped flood susceptibility polygon at the selected point in the current development dataset.';
      source.textContent = 'Source checked: DENR-MGB';
      content.replaceChildren(heading, description, source);
      return true;
    }

    state.mappedFloodSusceptibility = highest.displayLabel;
    const heading = document.createElement('strong');
    const classification = document.createElement('span');
    const source = document.createElement('span');
    heading.textContent = highest.displayLabel;
    classification.textContent = 'MGB Classification: ' + highest.code;
    source.textContent = 'Source: ' + highest.source;
    content.replaceChildren(heading, classification, source);
    return true;
  }

  async function setForecastLocation(latitude, longitude, sourceLabel) {
    if (!state.map || !state.layerGroups || !routePointIsInsideCaloocan(latitude, longitude)) {
      setStatus('forecastLocationStatus', 'Choose a point inside the validated Caloocan boundary.');
      return false;
    }

    if (state.forecastLocationMarker) {
      state.layerGroups.forecastLocations.removeLayer(state.forecastLocationMarker);
    }

    const barangay = findBarangayAtPoint(latitude, longitude);
    state.forecastLocation = Object.freeze({
      latitude: latitude,
      longitude: longitude,
      barangayName: barangay ? barangay.properties.name : null
    });
    state.forecastUsesRouteOrigin = sourceLabel === 'ROUTE_ORIGIN';
    state.forecastLocationMarker = L.marker([latitude, longitude], {
      pane: 'selectionOverlayPane',
      icon: forecastLocationMarkerIcon(),
      keyboard: true,
      title: 'Selected flood risk check location'
    }).bindTooltip('Flood risk check location', { direction: 'top', opacity: 0.96 });
    state.forecastLocationMarker.addTo(state.layerGroups.forecastLocations);

    const input = document.getElementById('forecastLocationInput');
    const clearButton = document.getElementById('clearForecastLocationButton');
    const coordinateLabel = latitude.toFixed(6) + ', ' + longitude.toFixed(6);
    if (input) input.value = barangay ? barangay.properties.name + ' — ' + coordinateLabel : coordinateLabel;
    if (clearButton) clearButton.hidden = false;
    setStatus(
      'forecastLocationStatus',
      sourceLabel === 'ROUTE_ORIGIN'
        ? 'Using the current Evacuation Route starting location in the Flood Risk Check.'
        : 'Flood Risk Check evaluates this exact location inside Caloocan City.'
    );
    setForecastLocationSelectionActive(false);
    await evaluateMappedFloodSusceptibility();
    return true;
  }

  function createUseRouteOriginResult(success, reason, routeOrigin, forecastLocation) {
    const routeCoordinates = routeOrigin
      ? Object.freeze({ latitude: routeOrigin.latitude, longitude: routeOrigin.longitude })
      : null;
    const forecastCoordinates = forecastLocation
      ? Object.freeze({
          latitude: forecastLocation.latitude,
          longitude: forecastLocation.longitude
        })
      : null;
    const exactCoordinateMatch = Boolean(
      routeCoordinates &&
      forecastCoordinates &&
      routeCoordinates.latitude === forecastCoordinates.latitude &&
      routeCoordinates.longitude === forecastCoordinates.longitude
    );

    return Object.freeze({
      success: success,
      reason: reason,
      routeOrigin: routeCoordinates,
      forecastLocation: forecastCoordinates,
      exactCoordinateMatch: exactCoordinateMatch
    });
  }

  async function handleUseRouteOrigin() {
    state.useRouteOriginClickCount += 1;
    const hadOriginCandidate = Boolean(state.routeOrigin || state.routeOriginMarker);
    const origin = getCurrentRouteOrigin();

    if (!origin) {
      state.useRouteOriginLastResult = hadOriginCandidate ? 'INVALID_ORIGIN' : 'NO_ORIGIN';
      updateForecastRouteOriginButton();
      setStatus('forecastLocationStatus', 'Set an Evacuation Route starting location first.');
      return createUseRouteOriginResult(
        false,
        state.useRouteOriginLastResult,
        null,
        state.forecastLocation
      );
    }

    try {
      const applied = await setForecastLocation(
        origin.latitude,
        origin.longitude,
        'ROUTE_ORIGIN'
      );
      const exactCoordinateMatch = Boolean(
        applied &&
        state.forecastLocation &&
        state.forecastLocation.latitude === origin.latitude &&
        state.forecastLocation.longitude === origin.longitude
      );

      state.useRouteOriginLastResult = exactCoordinateMatch ? 'SUCCESS' : 'APPLY_FAILED';
      if (!exactCoordinateMatch) {
        setStatus('forecastLocationStatus', 'The Evacuation Route starting location could not be reused.');
      }
      return createUseRouteOriginResult(
        exactCoordinateMatch,
        state.useRouteOriginLastResult,
        origin,
        state.forecastLocation
      );
    } catch (error) {
      state.useRouteOriginLastResult = 'APPLY_FAILED';
      setStatus('forecastLocationStatus', 'The Evacuation Route starting location could not be reused.');
      return createUseRouteOriginResult(false, 'APPLY_FAILED', origin, state.forecastLocation);
    }
  }

  function nullableForecastString(value) {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
  }

  function assertPagasaForecastEntry(entry) {
    if (!entry || typeof entry !== 'object' || typeof entry.date !== 'string' || entry.date.trim() === '') {
      throw new Error('Invalid PAGASA forecast record.');
    }

    const stringFields = ['province', 'municity', 'rainfall_desc', 'cloud_cover', 'wind_direction'];
    const numericFields = ['rainfall_total', 'tmean', 'tmin', 'tmax', 'humidity', 'wind_speed'];
    stringFields.forEach(function (field) {
      if (entry[field] !== null && typeof entry[field] !== 'string') {
        throw new Error('Invalid PAGASA forecast field.');
      }
    });
    numericFields.forEach(function (field) {
      if (entry[field] !== null && !Number.isFinite(entry[field])) {
        throw new Error('Invalid PAGASA numeric field.');
      }
    });
    return entry;
  }

  function assertFloodForecastPreview(payload) {
    if (!payload || payload.success !== true || !payload.data || typeof payload.data !== 'object') {
      throw new Error('Invalid flood forecast preview response.');
    }
    const data = payload.data;
    const statuses = ['AVAILABLE', 'NOT_CONFIGURED', 'ACCESS_REQUIRED', 'TEMPORARILY_UNAVAILABLE'];
    if (
      !statuses.includes(data.api_status) ||
      !data.weather_source || data.weather_source.agency !== 'DOST-PAGASA' ||
      data.weather_source.product !== 'TenDay Weather Forecast' ||
      !data.forecast_location || data.forecast_location.requested_name !== 'City of Caloocan' ||
      data.forecast_location.requested_psgc_10_digit !== '1380100000' ||
      !Array.isArray(data.forecast) || data.forecast.length > 20 ||
      typeof data.message !== 'string'
    ) {
      throw new Error('Invalid flood forecast preview response.');
    }
    if (data.api_status !== 'AVAILABLE' && data.forecast.length !== 0) {
      throw new Error('Unavailable PAGASA response contained forecast records.');
    }
    data.forecast.forEach(assertPagasaForecastEntry);
    return data;
  }

  function appendForecastValue(container, label, value) {
    if (value === null || value === undefined || value === '') return;
    const line = document.createElement('span');
    line.textContent = label + ': ' + value;
    container.appendChild(line);
  }

  function createPagasaForecastEntry(entry) {
    const card = document.createElement('article');
    const date = document.createElement('strong');
    card.className = 'civ-forecast-entry';
    date.textContent = entry.date;
    card.appendChild(date);

    appendForecastValue(card, 'Rainfall outlook', nullableForecastString(entry.rainfall_desc));
    if (entry.rainfall_total !== null) {
      appendForecastValue(
        card,
        'Rainfall total',
        String(entry.rainfall_total) + ' (unit/accumulation period not specified by the current API documentation)'
      );
    }
    appendForecastValue(card, 'Cloud cover', nullableForecastString(entry.cloud_cover));
    if (entry.tmin !== null || entry.tmean !== null || entry.tmax !== null) {
      const parts = [];
      if (entry.tmin !== null) parts.push('min ' + entry.tmin);
      if (entry.tmean !== null) parts.push('mean ' + entry.tmean);
      if (entry.tmax !== null) parts.push('max ' + entry.tmax);
      appendForecastValue(card, 'Temperature values (source units)', parts.join(', '));
    }
    return card;
  }

  function renderPagasaIssuance(container, issuance) {
    if (!container || !issuance) return;
    const details = document.createElement('span');
    details.textContent = 'Latest published forecast window: ' + issuance.start_date + ' to '
      + issuance.end_date + '; issuance ' + issuance.latest_date + ' ' + issuance.latest_time + '.';
    container.appendChild(details);
  }

  function renderPagasaForecastPreview(data) {
    const badge = document.getElementById('pagasaForecastStatusBadge');
    const content = document.getElementById('pagasaForecastContent');
    const entries = document.getElementById('pagasaForecastEntries');
    const fullDetails = document.getElementById('pagasaFullForecastDetails');
    const fullEntries = document.getElementById('pagasaFullForecastEntries');
    if (!badge || !content || !entries || !fullDetails || !fullEntries) return;

    entries.replaceChildren();
    fullEntries.replaceChildren();
    entries.hidden = true;
    fullDetails.hidden = true;
    content.replaceChildren();

    if (data.api_status !== 'AVAILABLE') {
      badge.textContent = data.api_status === 'TEMPORARILY_UNAVAILABLE' ? 'Unavailable' : 'API Access Required';
      const message = document.createElement('strong');
      message.textContent = data.message;
      content.appendChild(message);
      renderPagasaIssuance(content, data.issuance);
      return;
    }

    badge.textContent = 'Available';
    const scope = document.createElement('strong');
    const updated = document.createElement('span');
    scope.textContent = 'Forecast scope: ' + data.forecast_location.reported_name;
    content.appendChild(scope);
    if (data.issuance) {
      updated.textContent = 'Issued ' + data.issuance.latest_date + ' ' + data.issuance.latest_time
        + '; forecast window ' + data.issuance.start_date + ' to ' + data.issuance.end_date + '.';
      content.appendChild(updated);
    }

    data.forecast.slice(0, 3).forEach(function (entry) {
      entries.appendChild(createPagasaForecastEntry(entry));
    });
    data.forecast.forEach(function (entry) {
      fullEntries.appendChild(createPagasaForecastEntry(entry));
    });
    entries.hidden = data.forecast.length === 0;
    fullDetails.hidden = data.forecast.length <= 3;
  }

  async function loadPagasaForecastPreview() {
    if (state.pagasaForecastResponse) return true;
    if (state.pagasaForecastLoadPromise) return state.pagasaForecastLoadPromise;
    const config = getFloodForecastPreviewConfig();
    if (!config) return false;

    state.pagasaForecastLoadPromise = (async function () {
      try {
        state.pagasaForecastFetchCount += 1;
        const response = await window.fetch(config.endpoint, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' }
        });
        if (!response.ok) throw new Error('Forecast preview request failed.');

        const data = assertFloodForecastPreview(await response.json());
        state.pagasaForecastResponse = data;
        state.pagasaForecastStatus = data.api_status;
        state.pagasaForecastEntries = data.forecast.length;
        renderPagasaForecastPreview(data);
        return true;
      } catch (error) {
        state.pagasaForecastResponse = null;
        state.pagasaForecastStatus = 'TEMPORARILY_UNAVAILABLE';
        state.pagasaForecastEntries = 0;
        const badge = document.getElementById('pagasaForecastStatusBadge');
        if (badge) badge.textContent = 'Unavailable';
        setStatus('pagasaForecastContent', 'PAGASA weather forecast is temporarily unavailable.');
        return false;
      } finally {
        state.pagasaForecastLoadPromise = null;
      }
    })();

    return state.pagasaForecastLoadPromise;
  }

  async function initializeFloodForecastTool() {
    const config = getFloodForecastPreviewConfig();
    const setButton = document.getElementById('setForecastLocationButton');
    const useRouteButton = document.getElementById('useRouteOriginForForecastButton');
    const clearButton = document.getElementById('clearForecastLocationButton');
    if (!setButton || !useRouteButton || !clearButton || !state.map) return false;
    if (setButton.dataset.forecastToolBound === 'true') return Boolean(config);
    setButton.dataset.forecastToolBound = 'true';

    setButton.addEventListener('click', function () {
      if (!config || !pointSelectionToolsAvailable() || !state.cityBoundaryFeatureCollection) return;
      setForecastLocationSelectionActive(!state.forecastLocationSelectionActive);
      setStatus(
        'forecastLocationStatus',
        state.forecastLocationSelectionActive
          ? 'Click an exact point inside the Caloocan boundary.'
          : (state.forecastLocation ? 'Exact forecast point selected inside Caloocan City.' : 'Select an exact point inside Caloocan City.')
      );
    });
    clearButton.addEventListener('click', clearForecastLocation);
    if (!config || !pointSelectionToolsAvailable() || !state.cityBoundaryFeatureCollection) {
      setButton.disabled = true;
      useRouteButton.disabled = true;
      setStatus('floodForecastConnectionStatus', 'Unavailable');
      setStatus('pagasaForecastContent', 'PAGASA detailed forecast requires API access.');
      setStatus('mappedFloodSusceptibilityContent', 'Mapped flood susceptibility is not available in this environment.');
      return false;
    }

    updateForecastRouteOriginButton();
    await loadPagasaForecastPreview();
    const connectionStatus = document.getElementById('preparednessConnectionStatus');
    if (connectionStatus) {
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-flask';
      icon.setAttribute('aria-hidden', 'true');
      connectionStatus.replaceChildren(icon, document.createTextNode(' Development Preview'));
    }
    setStatus('floodForecastConnectionStatus', 'Development Preview');
    return true;
  }

  function bindLayerControls() {
    const controls = document.querySelectorAll('[data-map-layer]');

    setHazardSourceMode('flood', 'NOT_ACTIVE');
    setHazardSourceMode('landslide', 'NOT_ACTIVE');
    setFaultSourceMode('NOT_ACTIVE');
    setEvacuationCenterSourceMode('NOT_ACTIVE');
    setBarangaySourceMode('NOT_ACTIVE');
    renderAdminBarangayReferenceNotice();
    renderHazardSourceUi();
    renderPhivolcsReferenceNotice();
    renderAdminCenterReferenceNotice();
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

        if (layerKey === 'landslideHazards') {
          handleLandslideControl(control);
          return;
        }

        if (layerKey === 'evacuationCenters') {
          handleEvacuationCenterControl(control);
          return;
        }

        if (layerKey === 'earthquakeFaults') {
          handleFaultInformationControl(control);
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

  function bindPreparednessActionDelegation() {
    const container = document.getElementById('preparednessToolsContainer');
    if (!container) return false;
    if (container.dataset.preparednessActionsBound === 'true') {
      state.routeOriginSetButtonHandlerBound = Boolean(
        document.getElementById('setRouteOriginButton')
      );
      state.useRouteOriginHandlerBound = true;
      return true;
    }

    container.dataset.preparednessActionsBound = 'true';
    container.addEventListener('click', function (event) {
      const target = event && event.target;
      const setRouteOriginButton = target && typeof target.closest === 'function'
        ? target.closest('[data-action="set-route-origin"]')
        : null;
      if (setRouteOriginButton && container.contains(setRouteOriginButton)) {
        event.preventDefault();
        activateRouteOriginSelectionMode();
        return;
      }

      const useRouteOriginButton = target && typeof target.closest === 'function'
        ? target.closest('[data-action="use-route-origin"]')
        : null;
      if (!useRouteOriginButton || !container.contains(useRouteOriginButton)) return;

      event.preventDefault();
      void handleUseRouteOrigin();
    });
    state.routeOriginSetButtonHandlerBound = Boolean(
      document.getElementById('setRouteOriginButton')
    );
    state.useRouteOriginHandlerBound = true;
    updateForecastRouteOriginButton();
    return true;
  }

  function createDraftPopup(properties) {
    const content = document.createElement('div');
    const name = document.createElement('strong');
    const code = document.createElement('div');
    const status = document.createElement('div');

    name.textContent = properties.name;
    code.textContent = 'PSGC code: ' + properties.barangay_code;
    status.textContent = properties.reference_status || properties.display_status || 'Draft boundary preview';
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
    status.textContent = properties.reference_status || properties.display_status || 'Draft boundary preview';
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
      barangayAvailabilityMessage()
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
        !properties ||
        (properties.preview_status !== 'DRAFT_INCOMPLETE'
          && properties.reference_status !== 'INCOMPLETE ADMIN REFERENCE') ||
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

  function assertAdminBarangayReferenceFeatureCollection(payload) {
    const collection = assertDraftFeatureCollection(payload);
    collection.features.forEach(function (feature) {
      if (feature.properties.reference_status !== 'INCOMPLETE ADMIN REFERENCE') {
        throw new Error('Invalid admin barangay reference status.');
      }
    });
    return collection;
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

      if (!response.ok) throw new Error('Barangay data request failed.');
      const payload = await response.json();
      const adapter = getOperationalAdapter();
      let featureCollection;
      let sourceMode;
      if (previewConfig.operational === true) {
        if (!adapter || typeof adapter.mapBarangays !== 'function'
          || typeof adapter.resolveBarangaySource !== 'function') {
          throw new Error('Operational barangay adapter is unavailable.');
        }
        const operationalCollection = adapter.mapBarangays(payload);
        state.operationalBarangayCollection = operationalCollection;
        const adminReferenceConfig = getAdminBarangayReferenceConfig();
        const loadAdminReference = adminReferenceConfig
          ? async function () {
              const referenceResponse = await window.fetch(adminReferenceConfig.endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/geo+json, application/json' }
              });
              if (!referenceResponse.ok) {
                throw new Error('Admin barangay reference request failed.');
              }
              return assertAdminBarangayReferenceFeatureCollection(await referenceResponse.json());
            }
          : null;
        const selection = await adapter.resolveBarangaySource(
          operationalCollection,
          loadAdminReference
        );
        featureCollection = selection.featureCollection;
        sourceMode = selection.sourceMode;
      } else {
        featureCollection = assertDraftFeatureCollection(payload);
        sourceMode = 'DEVELOPMENT_PREVIEW';
      }
      if (!featureCollection) throw new Error('Operational barangay adapter is unavailable.');
      setBarangaySourceMode(sourceMode);
      state.searchableBarangays = [];
      clearDraftBarangaySelection();
      const previewLayer = L.geoJSON(featureCollection, {
        pane: 'barangayPane',
        style: draftBarangayStyle,
        onEachFeature: function (feature, layer) {
          const record = Object.freeze({
            layer: layer,
            feature: feature,
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
          layer.on('click', function (event) {
            if (delegateFeatureClickToMapPointSelection(event)) return;
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
      if (uniqueNames.size !== state.searchableBarangays.length
        || (previewConfig.operational !== true && state.searchableBarangays.length !== 187)) {
        throw new Error('Barangay search index is incomplete or contains duplicate names.');
      }

      state.layerGroups.barangays.clearLayers();
      previewLayer.addTo(state.layerGroups.barangays);

      const bounds = previewLayer.getBounds();
      if (featureCollection.features.length > 0 && !bounds.isValid()) {
        throw new Error('Barangay layer bounds are invalid.');
      }

      state.draftPreviewBounds = bounds.isValid() ? bounds : null;
      state.draftPreviewLayer = previewLayer;
      state.draftPreviewLoaded = true;
      state.draftPreviewFeatureCount = featureCollection.features.length;
      if (previewConfig.operational === true) {
        setOperationalBarangayUi(
          sourceMode === 'INCOMPLETE_ADMIN_REFERENCE'
            ? 'REFERENCE'
            : (featureCollection.features.length === 0 ? 'EMPTY' : 'LOADED'),
          featureCollection.features.length
        );
      } else {
        setDraftPreviewUiLoaded();
      }
      return true;
    } catch (error) {
      state.layerGroups.barangays.clearLayers();
      state.searchableBarangays = [];
      state.operationalBarangayCollection = null;
      state.draftPreviewLayer = null;
      state.draftPreviewBounds = null;
      state.draftPreviewLoaded = false;
      state.draftPreviewFeatureCount = 0;
      setBarangaySourceMode('BARANGAY_REFERENCE_UNAVAILABLE');
      renderAdminBarangayReferenceNotice();
      if (previewConfig.operational === true) {
        setOperationalBarangayUi('ERROR', 0);
      } else {
        setDraftPreviewUiError();
      }
      return false;
    }
  }

  async function initializeMapContext() {
    const operationalRouteInitialization = getOperationalEvacuationRouteConfig()
      ? initializeEvacuationRouteTool()
      : null;
    await loadCaloocanCityContext();
    await loadDraftBarangayPreview();
    if (operationalRouteInitialization) {
      await operationalRouteInitialization;
    } else {
      await initializeEvacuationRouteTool();
    }
    await initializeFloodForecastTool();
  }

  function paneForLayer(layerKey) {
    const paneByLayer = {
      barangays: 'barangayPane',
      floodHazards: 'hazardPolygonPane',
      landslideHazards: 'hazardPolygonPane',
      riskPredictions: 'hazardPolygonPane',
      earthquakeFaults: 'operationalLinePane',
      evacuationRoutes: 'routeOverlayPane',
      evacuationCenters: 'markerPane',
      forecastLocations: 'selectionOverlayPane'
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
    displayFloodRiskPrediction: function () {
      setStatus('floodForecastContent', 'TensorFlow AI prediction is not yet connected.');
      setStatus('floodModelStatus', 'Pending');
      state.tensorflowPredictionAvailable = false;
      return false;
    }
  });

  function getDiagnostics() {
    const container = document.getElementById(CONFIG.containerId);
    const routeOriginSetButton = document.getElementById('setRouteOriginButton');
    const useRouteOriginButton = document.getElementById('useRouteOriginForForecastButton');
    const currentRouteOrigin = getCurrentRouteOrigin();
    const attribution = container ? container.querySelector('.leaflet-control-attribution') : null;
    const layerControls = Array.from(document.querySelectorAll('[data-map-layer]'));
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
      dataMode: isOperationalMode() ? 'OPERATIONAL' : 'DEVELOPMENT_PREVIEW',
      tileLayerCount: tileLayerCount,
      tileImageCount: container ? container.querySelectorAll('.leaflet-tile-pane img').length : 0,
      osmAttributionPresent: Boolean(
        attribution &&
        (attribution.textContent || '').toLowerCase().includes(['open', 'street', 'map'].join(''))
      ),
      cityBoundaryLoaded: Boolean(state.cityBoundaryBounds),
      cityMaskReady: Boolean(state.cityMaskLayer),
      cityMaskActive: Boolean(state.map && state.cityMaskLayer && state.map.hasLayer(state.cityMaskLayer)),
      cityGeometryType: state.cityGeometryType,
      cityComponentCount: state.cityComponentCount,
      draftBarangayCount: state.draftPreviewFeatureCount,
      barangayDataStatus: state.barangayDataStatus,
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
      floodSourceMode: state.hazardSourceModes.flood,
      floodMgbReferenceActive: liveReferenceIsActive('flood'),
      floodMgbTileLoadCount: state.mgbReferenceTileLoadCounts.flood,
      floodMgbTileErrorCount: state.mgbReferenceTileErrorCounts.flood,
      landslidePreviewAvailable: Boolean(getLandslidePreviewConfig()),
      landslidePreviewLoaded: state.landslidePreviewLoaded,
      landslidePreviewActive: Boolean(
        state.map && state.layerGroups && state.map.hasLayer(state.layerGroups.landslideHazards)
      ),
      landslidePreviewFeatureCount: state.landslidePreviewFeatureCount,
      landslidePreviewClassCounts: state.landslidePreviewClassCounts,
      landslideFetchCount: state.landslideFetchCount,
      landslideSourceMode: state.hazardSourceModes.landslide,
      landslideMgbReferenceActive: liveReferenceIsActive('landslide'),
      landslideMgbTileLoadCount: state.mgbReferenceTileLoadCounts.landslide,
      landslideMgbTileErrorCount: state.mgbReferenceTileErrorCounts.landslide,
      faultPreviewAvailable: Boolean(getFaultInformationPreviewConfig()),
      faultPreviewLoaded: state.faultPreviewLoaded,
      faultInformationActive: state.faultInformationActive,
      faultPreviewFeatureCount: state.faultPreviewFeatureCount,
      faultFetchCount: state.faultFetchCount,
      faultSourceMode: state.faultSourceMode,
      phivolcsReferenceActive: phivolcsReferenceIsActive(),
      phivolcsTileLoadCount: state.phivolcsReferenceTileLoadCount,
      phivolcsTileErrorCount: state.phivolcsReferenceTileErrorCount,
      faultDisplayMode: state.faultPreviewResponse && state.faultPreviewResponse.summary
        ? state.faultPreviewResponse.summary.display_mode
        : (isOperationalMode() ? 'OPERATIONAL_GEOMETRY' : null),
      faultGeometryRendered: Boolean(
        state.layerGroups && state.layerGroups.earthquakeFaults.getLayers().length
      ),
      evacuationCenterPreviewAvailable: Boolean(getEvacuationCenterPreviewConfig()),
      evacuationCenterPreviewLoaded: state.evacuationCenterPreviewLoaded,
      evacuationCenterPreviewActive: Boolean(
        state.map && state.layerGroups && state.map.hasLayer(state.layerGroups.evacuationCenters)
      ),
      evacuationCenterPreviewFeatureCount: state.evacuationCenterPreviewFeatureCount,
      evacuationCenterFetchCount: state.evacuationCenterFetchCount,
      evacuationCenterSourceMode: state.evacuationCenterSourceMode,
      operationalEvacuationCenterCount: state.operationalEvacuationCenterFeatureCollection
        ? state.operationalEvacuationCenterFeatureCollection.features.length
        : 0,
      evacuationCenterMarkerCount: container
        ? container.querySelectorAll('.civ-evacuation-marker-wrap').length
        : 0,
      routeOriginSetButtonFound: Boolean(routeOriginSetButton),
      routeOriginSetButtonHandlerBound: state.routeOriginSetButtonHandlerBound,
      routeOriginSetButtonClickCount: state.routeOriginSetButtonClickCount,
      routeOriginSelected: Boolean(currentRouteOrigin),
      routeOriginSelectionMode: state.routeOriginSelectionActive,
      routeOriginMapClickCount: state.routeOriginMapClickCount,
      routeOriginFeatureClickCount: state.routeOriginFeatureClickCount,
      routeOriginSelectionAttemptCount: state.routeOriginSelectionAttemptCount,
      routeOriginLastAttemptLat: state.routeOriginLastAttemptLat,
      routeOriginLastAttemptLng: state.routeOriginLastAttemptLng,
      routeOriginLastResult: state.routeOriginLastResult,
      routeOriginLat: currentRouteOrigin ? currentRouteOrigin.latitude : null,
      routeOriginLng: currentRouteOrigin ? currentRouteOrigin.longitude : null,
      routeOriginMarkerExists: Boolean(state.routeOriginMarker),
      selectedEvacuationCenterId: state.selectedEvacuationCenterId,
      routingFetchCount: state.routingFetchCount,
      routeAlternativesReceived: state.routeAlternativesReceived,
      recommendedRouteIndex: state.recommendedRouteIndex,
      routeDistanceMeters: state.routeDistanceMeters,
      routeDurationSeconds: state.routeDurationSeconds,
      routeHazardScore: state.routeHazardScore,
      routeFloodExposure: state.routeFloodExposure,
      routeLandslideExposure: state.routeLandslideExposure,
      routeGeometryRendered: state.routeGeometryRendered,
      operationalRouteStatus: state.operationalRouteStatus,
      operationalRouteFeatureCount: state.operationalRouteFeatureCount,
      operationalRouteFetchCount: state.operationalRouteFetchCount,
      routeSampleIntervalMeters: CONFIG.routeSampleIntervalMeters,
      forecastLocationSelected: Boolean(state.forecastLocation),
      forecastLocationSelectionMode: state.forecastLocationSelectionActive,
      forecastLocationLat: state.forecastLocation ? state.forecastLocation.latitude : null,
      forecastLocationLng: state.forecastLocation ? state.forecastLocation.longitude : null,
      forecastUsesRouteOrigin: state.forecastUsesRouteOrigin,
      useRouteOriginButtonFound: Boolean(useRouteOriginButton),
      useRouteOriginButtonEnabled: Boolean(
        useRouteOriginButton &&
        !useRouteOriginButton.disabled &&
        useRouteOriginButton.getAttribute('aria-disabled') !== 'true'
      ),
      useRouteOriginHandlerBound: state.useRouteOriginHandlerBound,
      useRouteOriginClickCount: state.useRouteOriginClickCount,
      useRouteOriginLastResult: state.useRouteOriginLastResult,
      pagasaForecastStatus: state.pagasaForecastStatus,
      pagasaForecastFetchCount: state.pagasaForecastFetchCount,
      pagasaForecastEntries: state.pagasaForecastEntries,
      mappedFloodSusceptibility: state.mappedFloodSusceptibility,
      tensorflowPredictionAvailable: state.tensorflowPredictionAvailable,
      floodLegendHighestLabel: document.getElementById('highestRiskLegendLabel')
        ? document.getElementById('highestRiskLegendLabel').textContent
        : null,
      hazardLegendContext: document.getElementById('riskLegendContext')
        ? document.getElementById('riskLegendContext').textContent
        : null,
      currentZoom: state.map ? state.map.getZoom() : null,
      minZoom: state.map ? state.map.getMinZoom() : state.operationalMinZoom,
      maxZoom: state.map ? state.map.getMaxZoom() : CONFIG.maximumZoom,
      hazardControlCount: layerControls.length,
      visibleHazardControlCount: layerControls.filter(function (control) {
        return control.getClientRects().length > 0;
      }).length,
      paneZIndexes: Object.freeze({
        cityBase: paneZIndex('cityBasePane'),
        mgbReference: paneZIndex('mgbReferencePane'),
        cityMask: paneZIndex('cityMaskPane'),
        hazardPolygon: paneZIndex('hazardPolygonPane'),
        barangay: paneZIndex('barangayPane'),
        phivolcsReference: paneZIndex('phivolcsReferencePane'),
        operationalLine: paneZIndex('operationalLinePane'),
        cityOutline: paneZIndex('cityOutlinePane'),
        marker: paneZIndex('markerPane'),
        routeOverlay: paneZIndex('routeOverlayPane'),
        selectionOverlay: paneZIndex('selectionOverlayPane')
      }),
      maximumBoundsConfigured: Boolean(state.operationalMaxBounds),
      maxBoundsViscosity: state.map ? state.map.options.maxBoundsViscosity : null,
      mapPointSelectionHandlerBound: state.mapPointSelectionHandlerBound
    });
  }

  function init() {
    const container = document.getElementById(CONFIG.containerId);
    if (!container || state.map) return state.map;
    if (container._leaflet_id) return null;

    bindPreparednessTabs();
    bindPreparednessActionDelegation();
    initializeOperationalShell();
    updateMapDataStatus();

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
      maxZoom: CONFIG.maximumZoom,
      maxBoundsViscosity: 1.0
    });
    state.map.on('zoomend moveend', scheduleMgbReferenceRefresh);

    createCityContextPanes();
    state.map.on('click', function (event) {
      handleMapPointSelection(event, 'MAP');
    });
    state.mapPointSelectionHandlerBound = true;
    state.layerGroups.barangays.addTo(state.map);
    state.layerGroups.evacuationRoutes.addTo(state.map);
    state.layerGroups.forecastLocations.addTo(state.map);
    state.layerGroups.riskPredictions.addTo(state.map);

    bindBarangaySearch();
    bindLayerControls();
    bindResponsiveResize(container);
    window.setTimeout(scheduleMapResize, 120);
    initializeMapContext();

    return state.map;
  }

  const publicApi = {
    init: init,
    refresh: scheduleMapResize,
    focus: focusMapArea,
    diagnostics: getDiagnostics,
    dataHooks: dataHooks
  };
  if (getEvacuationRoutePreviewConfig()) {
    publicApi.getRouteOriginDebugState = function () {
      const origin = getCurrentRouteOrigin();
      return Object.freeze({
        buttonFound: Boolean(document.getElementById('setRouteOriginButton')),
        handlerBound: state.routeOriginSetButtonHandlerBound,
        buttonClickCount: state.routeOriginSetButtonClickCount,
        selectionMode: state.routeOriginSelectionActive,
        mapClickCount: state.routeOriginMapClickCount,
        featureClickCount: state.routeOriginFeatureClickCount,
        selectionAttemptCount: state.routeOriginSelectionAttemptCount,
        routeOriginSelected: Boolean(origin),
        latitude: origin ? origin.latitude : null,
        longitude: origin ? origin.longitude : null,
        markerExists: Boolean(state.routeOriginMarker),
        lastAttemptLat: state.routeOriginLastAttemptLat,
        lastAttemptLng: state.routeOriginLastAttemptLng,
        lastResult: state.routeOriginLastResult
      });
    };
  }
  if (getFloodForecastPreviewConfig()) {
    publicApi.testUseRouteOrigin = handleUseRouteOrigin;
  }
  window.CiventralHazardMap = Object.freeze(publicApi);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
