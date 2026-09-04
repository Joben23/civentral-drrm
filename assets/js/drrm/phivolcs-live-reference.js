(function (root, factory) {
  'use strict';

  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.CiventralPhivolcsLiveReference = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';

  const SOURCE_MODES = Object.freeze({
    NOT_ACTIVE: 'NOT_ACTIVE',
    LOADING: 'LOADING',
    OPERATIONAL: 'CIVENTRAL_OPERATIONAL',
    DEVELOPMENT_PREVIEW: 'DEVELOPMENT_PREVIEW',
    PHIVOLCS_REFERENCE: 'PHIVOLCS_LIVE_REFERENCE',
    UNAVAILABLE: 'PHIVOLCS_REFERENCE_UNAVAILABLE'
  });
  const SOURCE_AGENCY = 'DOST-PHIVOLCS';
  const MAP_SERVER_URL = 'https://gisweb.phivolcs.dost.gov.ph/arcgis/rest/services/PHIVOLCSPublic/ActiveFault/MapServer';
  const WMS_URL = 'https://gisweb.phivolcs.dost.gov.ph/arcgis/services/PHIVOLCSPublic/ActiveFault/MapServer/WMSServer';
  const UNAVAILABLE_MESSAGE = 'Official PHIVOLCS fault reference is temporarily unavailable.';
  const ADVISORY = 'No mapped active fault in this dataset intersects Caloocan.';

  function assertOfficialMapServerUrl(url) {
    if (url !== MAP_SERVER_URL) {
      throw new Error('Untrusted PHIVOLCS active-fault MapServer URL.');
    }
    const parsed = new URL(url);
    if (
      parsed.protocol !== 'https:' ||
      parsed.hostname !== 'gisweb.phivolcs.dost.gov.ph' ||
      parsed.pathname !== '/arcgis/rest/services/PHIVOLCSPublic/ActiveFault/MapServer' ||
      parsed.search !== '' || parsed.hash !== '' ||
      parsed.username !== '' || parsed.password !== ''
    ) {
      throw new Error('Unsafe PHIVOLCS active-fault MapServer URL.');
    }
    return url;
  }

  function assertOfficialWmsUrl(url) {
    if (url !== WMS_URL) {
      throw new Error('Untrusted PHIVOLCS active-fault WMS URL.');
    }
    const parsed = new URL(url);
    if (
      parsed.protocol !== 'https:' ||
      parsed.hostname !== 'gisweb.phivolcs.dost.gov.ph' ||
      parsed.pathname !== '/arcgis/services/PHIVOLCSPublic/ActiveFault/MapServer/WMSServer' ||
      parsed.search !== '' || parsed.hash !== '' ||
      parsed.username !== '' || parsed.password !== ''
    ) {
      throw new Error('Unsafe PHIVOLCS active-fault WMS URL.');
    }
    return url;
  }

  function referenceSummary() {
    return Object.freeze({
      crosses_caloocan: false,
      nearest_fault_name: 'West Valley Fault',
      minimum_city_distance_km: 3.76,
      source_agency: SOURCE_AGENCY,
      display_mode: 'LIVE_REFERENCE_IMAGE_ONLY',
      advisory: ADVISORY,
      nearest_fault_notice: 'Approximate reference only; the mapped West Valley Fault does not cross Caloocan.'
    });
  }

  function service() {
    return Object.freeze({
      label: 'PHIVOLCS Active Fault',
      mapServerUrl: assertOfficialMapServerUrl(MAP_SERVER_URL),
      wmsUrl: assertOfficialWmsUrl(WMS_URL),
      layers: '0',
      format: 'image/png',
      transparent: true,
      attribution: SOURCE_AGENCY,
      summary: referenceSummary()
    });
  }

  function selectOperationalOrReference(operationalRowCount, referenceEnabled) {
    if (!Number.isInteger(operationalRowCount) || operationalRowCount < 0) {
      throw new Error('Operational fault row count must be a non-negative integer.');
    }
    if (operationalRowCount > 0) return SOURCE_MODES.OPERATIONAL;
    return referenceEnabled === true
      ? SOURCE_MODES.PHIVOLCS_REFERENCE
      : SOURCE_MODES.UNAVAILABLE;
  }

  function failureState() {
    return Object.freeze({
      mode: SOURCE_MODES.UNAVAILABLE,
      message: UNAVAILABLE_MESSAGE
    });
  }

  return Object.freeze({
    SOURCE_MODES: SOURCE_MODES,
    SOURCE_AGENCY: SOURCE_AGENCY,
    MAP_SERVER_URL: MAP_SERVER_URL,
    WMS_URL: WMS_URL,
    UNAVAILABLE_MESSAGE: UNAVAILABLE_MESSAGE,
    ADVISORY: ADVISORY,
    assertOfficialMapServerUrl: assertOfficialMapServerUrl,
    assertOfficialWmsUrl: assertOfficialWmsUrl,
    referenceSummary: referenceSummary,
    service: service,
    selectOperationalOrReference: selectOperationalOrReference,
    failureState: failureState
  });
});
