(function (root, factory) {
  'use strict';

  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.CiventralMgbLiveReference = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';

  const SOURCE_MODES = Object.freeze({
    NOT_ACTIVE: 'NOT_ACTIVE',
    LOADING: 'LOADING',
    OPERATIONAL: 'CIVENTRAL_OPERATIONAL',
    DEVELOPMENT_PREVIEW: 'DEVELOPMENT_PREVIEW',
    MGB_REFERENCE: 'MGB_LIVE_REFERENCE',
    UNAVAILABLE: 'MGB_REFERENCE_UNAVAILABLE'
  });
  const SOURCE_AGENCY = 'Department of Environment and Natural Resources - Mines and Geosciences Bureau (DENR-MGB)';
  const UNAVAILABLE_MESSAGE = 'Official MGB reference layer is temporarily unavailable.';
  const DISCLOSURE = 'Displayed directly from the official DENR-MGB public map service. This layer is not stored or republished by CIVENTRAL.';
  const SITE_ASSESSMENT_NOTICE = 'Reference display only. Consult DENR-MGB and qualified authorities for an official site assessment.';
  const SERVICE_ROOT = 'https://controlmap.mgb.gov.ph/arcgis/rest/services/GeospatialDataInventory_Public/';

  const serviceDefinitions = Object.freeze({
    flood: Object.freeze({
      hazard: 'flood',
      label: 'MGB Live Flood Susceptibility',
      serviceName: 'GDI_Detailed_Flood_Susceptibility_Public',
      mapServerUrl: SERVICE_ROOT + 'GDI_Detailed_Flood_Susceptibility_Public/MapServer',
      classifications: Object.freeze(['Low', 'Moderate', 'High', 'Very High']),
      officialLegendLabels: Object.freeze([
        'Low Susceptibility to Flooding',
        'Moderate Susceptibility to Flooding',
        'High Susceptibility to Flooding',
        'Very High Susceptibility to Flooding'
      ])
    }),
    landslide: Object.freeze({
      hazard: 'landslide',
      label: 'MGB Live Rain-induced Landslide Susceptibility',
      serviceName: 'GDI_Detailed_Rain_induced_Landslide_Susceptibility_Public',
      mapServerUrl: SERVICE_ROOT + 'GDI_Detailed_Rain_induced_Landslide_Susceptibility_Public/MapServer',
      classifications: Object.freeze(['Low', 'Moderate', 'High', 'Very High']),
      officialLegendLabels: Object.freeze([
        'Low Susceptibility to Landslide',
        'Moderate Susceptibility to Landslide',
        'High Susceptibility to Landslide',
        'Very High Susceptibility to Landslide'
      ]),
      additionalSourceSymbol: 'Debris flow path/Possible accumulation zone'
    })
  });

  const trustedMapServerUrls = Object.freeze(Object.keys(serviceDefinitions).map(function (hazard) {
    return serviceDefinitions[hazard].mapServerUrl;
  }));

  function assertTrustedMapServerUrl(url) {
    if (typeof url !== 'string' || !trustedMapServerUrls.includes(url)) {
      throw new Error('Untrusted MGB live-reference service URL.');
    }
    const parsed = new URL(url);
    if (
      parsed.protocol !== 'https:' ||
      parsed.hostname !== 'controlmap.mgb.gov.ph' ||
      parsed.port !== '' ||
      parsed.username !== '' ||
      parsed.password !== '' ||
      parsed.search !== '' ||
      parsed.hash !== '' ||
      !parsed.pathname.startsWith('/arcgis/rest/services/GeospatialDataInventory_Public/') ||
      !parsed.pathname.endsWith('_Public/MapServer') ||
      parsed.pathname.includes('/FeatureServer')
    ) {
      throw new Error('Unsafe MGB live-reference service URL.');
    }
    return url;
  }

  function serviceFor(hazard) {
    const service = serviceDefinitions[hazard];
    if (!service) throw new Error('Unsupported MGB live-reference hazard.');
    const mapServerUrl = assertTrustedMapServerUrl(service.mapServerUrl);
    return Object.freeze(Object.assign({}, service, {
      mapServerUrl: mapServerUrl,
      exportUrl: assertTrustedExportUrl(mapServerUrl + '/export'),
      attribution: SOURCE_AGENCY,
      disclosure: DISCLOSURE,
      siteAssessmentNotice: SITE_ASSESSMENT_NOTICE,
      displayZoomRange: Object.freeze({ minimum: 6, maximum: 18 }),
      exportImageFormat: 'png32'
    }));
  }

  function assertTrustedExportUrl(url) {
    if (typeof url !== 'string') {
      throw new Error('Untrusted MGB export URL.');
    }
    const parsed = new URL(url);
    const trusted = trustedMapServerUrls.some(function (mapServerUrl) {
      return url === mapServerUrl + '/export';
    });
    if (
      !trusted ||
      parsed.protocol !== 'https:' ||
      parsed.hostname !== 'controlmap.mgb.gov.ph' ||
      parsed.port !== '' ||
      parsed.username !== '' ||
      parsed.password !== '' ||
      parsed.search !== '' ||
      parsed.hash !== '' ||
      !parsed.pathname.endsWith('_Public/MapServer/export')
    ) {
      throw new Error('Unsafe MGB export URL.');
    }
    return url;
  }

  function createOutsideCityMask(cityBoundary) {
    const feature = cityBoundary && cityBoundary.type === 'FeatureCollection'
      && Array.isArray(cityBoundary.features) && cityBoundary.features.length === 1
      ? cityBoundary.features[0]
      : null;
    const geometry = feature && feature.geometry;
    if (!geometry || !['Polygon', 'MultiPolygon'].includes(geometry.type)
      || !Array.isArray(geometry.coordinates) || geometry.coordinates.length === 0) {
      throw new Error('A valid city polygon is required for the presentation mask.');
    }

    const polygons = geometry.type === 'Polygon'
      ? [geometry.coordinates]
      : geometry.coordinates;
    const cityExteriorRings = polygons.map(function (polygon) {
      const ring = Array.isArray(polygon) ? polygon[0] : null;
      if (!Array.isArray(ring) || ring.length < 4) {
        throw new Error('The city presentation mask has an invalid exterior ring.');
      }
      return ring.map(function (position) {
        if (!Array.isArray(position) || position.length < 2
          || !Number.isFinite(Number(position[0])) || !Number.isFinite(Number(position[1]))) {
          throw new Error('The city presentation mask has an invalid coordinate.');
        }
        return [Number(position[0]), Number(position[1])];
      });
    });

    return Object.freeze({
      type: 'Feature',
      properties: Object.freeze({
        presentation_only: true,
        source_imagery_modified: false
      }),
      geometry: Object.freeze({
        type: 'Polygon',
        coordinates: Object.freeze([
          Object.freeze([
            Object.freeze([-180, -85]),
            Object.freeze([-180, 85]),
            Object.freeze([180, 85]),
            Object.freeze([180, -85]),
            Object.freeze([-180, -85])
          ])
        ].concat(cityExteriorRings))
      })
    });
  }

  function selectOperationalOrReference(operationalRowCount, referenceEnabled) {
    if (!Number.isInteger(operationalRowCount) || operationalRowCount < 0) {
      throw new Error('Operational hazard row count must be a non-negative integer.');
    }
    if (operationalRowCount > 0) return SOURCE_MODES.OPERATIONAL;
    return referenceEnabled === true ? SOURCE_MODES.MGB_REFERENCE : SOURCE_MODES.UNAVAILABLE;
  }

  function selectHazardSourceModes(counts, referenceEnabled) {
    if (!counts || typeof counts !== 'object') {
      throw new Error('Hazard row counts are required.');
    }
    return Object.freeze({
      flood: selectOperationalOrReference(counts.flood, referenceEnabled === true),
      landslide: selectOperationalOrReference(counts.landslide, referenceEnabled === true)
    });
  }

  function failureState(hazard) {
    serviceFor(hazard);
    return Object.freeze({
      hazard: hazard,
      mode: SOURCE_MODES.UNAVAILABLE,
      message: UNAVAILABLE_MESSAGE
    });
  }

  return Object.freeze({
    SOURCE_MODES: SOURCE_MODES,
    SOURCE_AGENCY: SOURCE_AGENCY,
    UNAVAILABLE_MESSAGE: UNAVAILABLE_MESSAGE,
    DISCLOSURE: DISCLOSURE,
    SITE_ASSESSMENT_NOTICE: SITE_ASSESSMENT_NOTICE,
    trustedMapServerUrls: trustedMapServerUrls,
    assertTrustedMapServerUrl: assertTrustedMapServerUrl,
    assertTrustedExportUrl: assertTrustedExportUrl,
    serviceFor: serviceFor,
    createOutsideCityMask: createOutsideCityMask,
    selectOperationalOrReference: selectOperationalOrReference,
    selectHazardSourceModes: selectHazardSourceModes,
    failureState: failureState
  });
});
