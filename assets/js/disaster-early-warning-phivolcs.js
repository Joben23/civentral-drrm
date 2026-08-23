(function () {
  'use strict';

  const config = window.CiventralEarlyWarningConfig || {};
  const state = {
    initialized: false,
    loaded: false,
    fetchCount: 0,
    sourceStatus: 'NOT_LOADED',
    runtimeStatus: 'NOT_LOADED',
    eventCount: 0,
    lastResult: 'NOT_STARTED'
  };

  function setText(selector, value) {
    const element = document.querySelector(selector);
    if (element) {
      element.textContent = value;
    }
  }

  function requireObject(value, message) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      throw new Error(message);
    }
    return value;
  }

  function requireArray(value, message) {
    if (!Array.isArray(value)) {
      throw new Error(message);
    }
    return value;
  }

  function renderOverview(data) {
    const source = requireObject(data.source, 'The PHIVOLCS source is malformed.');
    const events = requireArray(data.events, 'The PHIVOLCS event list is malformed.');
    const relevance = requireObject(data.relevance, 'The PHIVOLCS relevance state is malformed.');
    const channels = requireArray(
      data.official_publication_channels,
      'The PHIVOLCS publication channels are malformed.'
    );
    const staticHazardReference = requireObject(
      data.static_hazard_reference,
      'The PHIVOLCS static-hazard reference is malformed.'
    );

    if (source.agency !== 'DOST-PHIVOLCS'
      || data.external_information_only !== true
      || data.upstream_request_attempted !== false
      || staticHazardReference.operational_event_feed !== false
      || channels.length === 0) {
      throw new Error('The PHIVOLCS response did not preserve the verified source state.');
    }

    state.sourceStatus = String(data.machine_readable_source_status || 'NOT_CONFIRMED');
    state.runtimeStatus = String(data.runtime_status || 'INTEGRATION_PENDING');
    state.eventCount = events.length;

    const sourceConfirmed = state.sourceStatus === 'CONFIRMED';
    setText('[data-phivolcs-feed-status]', `Official machine-readable event feed: ${sourceConfirmed ? 'Available' : 'Not Confirmed'}`);
    setText('[data-phivolcs-runtime-badge]', sourceConfirmed ? 'Official Feed Available' : 'Integration Pending');
    setText('[data-phivolcs-source-status]', sourceConfirmed ? 'Confirmed' : 'Not confirmed');
    setText('[data-phivolcs-event-count]', String(events.length));
    setText(
      '[data-phivolcs-relevance-status]',
      relevance.status === 'NOT_APPLIED_NO_FEED' ? 'Not applied — no feed' : String(relevance.status || 'Unknown')
    );

    if (events.length === 0) {
      setText('[data-phivolcs-information-title]', 'No applicable PHIVOLCS information available.');
      setText(
        '[data-phivolcs-information-message]',
        String(data.message || 'No confirmed official machine-readable event source is configured.')
      );
    }

    state.loaded = true;
    state.lastResult = 'SUCCESS';
  }

  function renderFailure() {
    state.loaded = false;
    state.sourceStatus = 'TEMPORARILY_UNAVAILABLE';
    state.runtimeStatus = 'TEMPORARILY_UNAVAILABLE';
    state.eventCount = 0;
    state.lastResult = 'ERROR';
    setText('[data-phivolcs-feed-status]', 'Official machine-readable event feed: Unavailable');
    setText('[data-phivolcs-runtime-badge]', 'Temporarily Unavailable');
    setText('[data-phivolcs-source-status]', 'Temporarily unavailable');
    setText('[data-phivolcs-event-count]', '0');
    setText('[data-phivolcs-relevance-status]', 'Not available');
    setText('[data-phivolcs-information-title]', 'PHIVOLCS information is temporarily unavailable.');
    setText('[data-phivolcs-information-message]', 'The warning dashboard, PAGASA information, and NDRRMC status remain available.');
  }

  async function loadOverview() {
    if (typeof config.phivolcsEndpoint !== 'string' || config.phivolcsEndpoint === '') {
      throw new Error('The PHIVOLCS endpoint is not configured.');
    }

    state.fetchCount += 1;
    const response = await window.fetch(config.phivolcsEndpoint, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    });
    const payload = await response.json();

    if (!response.ok || !payload || payload.success !== true) {
      throw new Error('The PHIVOLCS endpoint returned an error.');
    }

    renderOverview(requireObject(payload.data, 'The PHIVOLCS response data is malformed.'));
  }

  function initialize() {
    if (state.initialized) {
      return;
    }
    state.initialized = true;
    loadOverview().catch(renderFailure);
  }

  window.CiventralPhivolcsInformation = Object.freeze({
    diagnostics: function () {
      return {
        initialized: state.initialized,
        loaded: state.loaded,
        fetchCount: state.fetchCount,
        sourceStatus: state.sourceStatus,
        runtimeStatus: state.runtimeStatus,
        eventCount: state.eventCount,
        lastResult: state.lastResult
      };
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
