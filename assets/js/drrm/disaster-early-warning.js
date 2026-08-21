(function () {
  'use strict';

  const config = window.CiventralEarlyWarningConfig || {};
  const configuredSecurity = config.security && typeof config.security === 'object'
    ? config.security
    : {};
  const configuredCapabilities = configuredSecurity.capabilities
    && typeof configuredSecurity.capabilities === 'object'
    ? configuredSecurity.capabilities
    : {};
  const securityCapabilities = Object.freeze({
    canView: configuredCapabilities.canView === true,
    canCreateWarning: configuredCapabilities.canCreateWarning === true,
    canActivateWarning: configuredCapabilities.canActivateWarning === true,
    canCancelWarning: configuredCapabilities.canCancelWarning === true
  });
  const state = {
    initialized: false,
    loaded: false,
    fetchCount: 0,
    sourceCount: 0,
    activeWarningCount: null,
    recentWarningCount: null,
    pagasaLoaded: false,
    pagasaFetchCount: 0,
    pagasaPublicStatus: 'NOT_LOADED',
    pagasaDetailedStatus: 'NOT_LOADED',
    pagasaAdvisoryCount: 0,
    ndrrmcLoaded: false,
    ndrrmcFetchCount: 0,
    ndrrmcSourceStatus: 'NOT_LOADED',
    ndrrmcAdvisoryCount: 0,
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

  function requireCount(value, message) {
    if (!Number.isInteger(value) || value < 0) {
      throw new Error(message);
    }
    return value;
  }

  /**
   * Future Module 4 mutations must use this helper so the session-bound token
   * is carried only in the X-CSRF-Token header, never in a URL. This phase does
   * not issue any mutation requests.
   */
  function buildMutationHeaders(additionalHeaders = {}) {
    const csrfToken = typeof configuredSecurity.csrfToken === 'string'
      ? configuredSecurity.csrfToken
      : '';

    if (csrfToken === '') {
      throw new Error('Module 4 CSRF protection is not available.');
    }

    return {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
      ...additionalHeaders
    };
  }

  function formatDateTime(value) {
    if (typeof value !== 'string' || value.trim() === '') {
      return 'Not available';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return 'Not available';
    }

    return new Intl.DateTimeFormat('en-PH', {
      dateStyle: 'medium',
      timeStyle: 'short'
    }).format(date);
  }

  function formatCode(value) {
    return typeof value === 'string' && value.trim() !== ''
      ? value.replaceAll('_', ' ')
      : 'Not available';
  }

  function affectedAreaText(warning) {
    const areas = Array.isArray(warning.affected_areas) ? warning.affected_areas : [];
    const names = areas
      .map((area) => (area && typeof area.area_name === 'string' ? area.area_name.trim() : ''))
      .filter(Boolean);

    return names.length > 0 ? names.join(', ') : 'Not specified';
  }

  function renderMetrics(metrics, metadata) {
    const definitions = [
      ['active-warnings', 'active_warnings'],
      ['high-risk-areas', 'high_risk_areas'],
      ['weather-advisories', 'weather_advisories'],
      ['alerts-sent-today', 'alerts_sent_today']
    ];

    definitions.forEach(([domKey, dataKey]) => {
      const count = requireCount(metrics[dataKey], `Invalid ${dataKey} metric.`);
      setText(`[data-summary-value="${domKey}"]`, String(count));

      const metricMetadata = metadata[dataKey];
      if (metricMetadata && typeof metricMetadata.definition === 'string') {
        setText(`[data-summary-note="${domKey}"]`, metricMetadata.definition);
      }
    });

    state.activeWarningCount = metrics.active_warnings;
  }

  function integrationStatusLabel(status) {
    switch (status) {
      case 'CONNECTED':
        return 'Connected';
      case 'DISABLED':
        return 'Disabled';
      case 'PENDING':
      default:
        return 'Integration Pending';
    }
  }

  function renderSources(sources) {
    const cards = Array.from(document.querySelectorAll('[data-source-card]'));
    const cardsByCode = new Map(cards.map((card) => [card.dataset.sourceCard, card]));

    sources.forEach((source) => {
      if (!source || typeof source.source_code !== 'string') {
        throw new Error('An advisory source is malformed.');
      }

      const card = cardsByCode.get(source.source_code);
      if (!card) {
        return;
      }

      const name = card.querySelector('[data-source-name]');
      const status = card.querySelector('[data-source-status]');

      if (name && typeof source.source_name === 'string') {
        name.textContent = source.source_name;
      }

      if (status) {
        status.textContent = integrationStatusLabel(source.integration_status);
        status.dataset.integrationStatus = source.integration_status;
      }
    });

    state.sourceCount = sources.length;
  }

  function pagasaStatusLabel(status) {
    switch (status) {
      case 'AVAILABLE':
        return 'Available';
      case 'ACCESS_PENDING':
        return 'Access Pending';
      case 'TEMPORARILY_UNAVAILABLE':
        return 'Temporarily Unavailable';
      default:
        return 'Unavailable';
    }
  }

  function renderPagasaOverview(data) {
    const source = requireObject(data.source, 'The PAGASA source is malformed.');
    const detailedApi = requireObject(data.detailed_api, 'The PAGASA API status is malformed.');
    const advisories = requireArray(data.advisories, 'The PAGASA advisory list is malformed.');

    if (source.agency !== 'DOST-PAGASA' || source.product !== 'TenDay Weather Forecast') {
      throw new Error('The PAGASA source identity is invalid.');
    }
    if (data.external_information_only !== true) {
      throw new Error('The PAGASA response did not preserve external-information separation.');
    }

    state.pagasaPublicStatus = String(data.public_information_status || 'TEMPORARILY_UNAVAILABLE');
    state.pagasaDetailedStatus = String(detailedApi.status || 'TEMPORARILY_UNAVAILABLE');
    state.pagasaAdvisoryCount = advisories.length;

    setText('[data-pagasa-public-feed-status]', `Official public feed: ${pagasaStatusLabel(state.pagasaPublicStatus)}`);
    setText('[data-pagasa-detailed-api-status]', `Detailed API: ${pagasaStatusLabel(state.pagasaDetailedStatus)}`);
    setText('[data-pagasa-detailed-status]', pagasaStatusLabel(state.pagasaDetailedStatus));
    setText('[data-pagasa-runtime-badge]', state.pagasaPublicStatus === 'AVAILABLE' ? 'Official Info Available' : 'Temporarily Unavailable');

    const information = data.public_information;
    if (information && typeof information === 'object' && !Array.isArray(information)) {
      const latestDate = typeof information.latest_date === 'string' ? information.latest_date : '';
      const latestTime = typeof information.latest_time === 'string' ? information.latest_time : '';
      const periodStart = typeof information.forecast_period_start === 'string' ? information.forecast_period_start : '';
      const periodEnd = typeof information.forecast_period_end === 'string' ? information.forecast_period_end : '';

      setText('[data-pagasa-issued-at]', [latestDate, latestTime].filter(Boolean).join(' ') || 'Not available');
      setText('[data-pagasa-forecast-period]', periodStart && periodEnd ? `${periodStart} to ${periodEnd}` : 'Not available');
      setText('[data-pagasa-coverage]', String(information.coverage || 'National issuance metadata'));
    } else {
      setText('[data-pagasa-issued-at]', 'Temporarily unavailable');
      setText('[data-pagasa-forecast-period]', 'Temporarily unavailable');
      setText('[data-pagasa-coverage]', 'Official source unavailable');
    }

    if (advisories.length === 0) {
      setText('[data-pagasa-advisory-title]', 'No applicable PAGASA advisory available.');
      setText('[data-pagasa-advisory-message]', String(data.advisory_message || 'No advisory was returned by the configured official source.'));
    } else {
      const advisory = requireObject(advisories[0], 'A PAGASA advisory is malformed.');
      setText('[data-pagasa-advisory-title]', String(advisory.title || 'PAGASA Advisory'));
      setText('[data-pagasa-advisory-message]', String(advisory.summary || 'Official advisory information is available.'));
    }

    state.pagasaLoaded = true;
  }

  function renderNdrrmcOverview(data) {
    const source = requireObject(data.source, 'The NDRRMC source is malformed.');
    const advisories = requireArray(data.advisories, 'The NDRRMC advisory list is malformed.');
    const relevance = requireObject(data.relevance, 'The NDRRMC relevance status is malformed.');

    if (source.agency !== 'NDRRMC') {
      throw new Error('The NDRRMC source identity is invalid.');
    }
    if (data.external_information_only !== true || data.upstream_request_attempted !== false) {
      throw new Error('The NDRRMC response did not preserve the unsupported external-source state.');
    }

    state.ndrrmcSourceStatus = String(data.machine_readable_source_status || 'NOT_CONFIRMED');
    state.ndrrmcAdvisoryCount = advisories.length;

    const sourceConfirmed = state.ndrrmcSourceStatus === 'CONFIRMED';
    setText('[data-ndrrmc-feed-status]', `Official machine-readable feed: ${sourceConfirmed ? 'Available' : 'Not Confirmed'}`);
    setText('[data-ndrrmc-runtime-badge]', sourceConfirmed ? 'Official Feed Available' : 'Integration Pending');
    setText('[data-ndrrmc-source-status]', sourceConfirmed ? 'Confirmed' : 'Not confirmed');
    setText('[data-ndrrmc-advisory-count]', String(advisories.length));
    setText('[data-ndrrmc-relevance-status]', relevance.status === 'NOT_APPLIED_NO_FEED' ? 'Not applied — no feed' : formatCode(relevance.status));

    if (advisories.length === 0) {
      setText('[data-ndrrmc-advisory-title]', 'No applicable NDRRMC advisory available.');
      setText('[data-ndrrmc-advisory-message]', String(data.message || 'No official machine-readable advisory source is configured.'));
    }

    state.ndrrmcLoaded = true;
  }

  function setCurrentWarningField(field, value) {
    setText(`[data-current-warning-field="${field}"]`, value);
  }

  function renderCurrentWarning(warning) {
    if (warning === null) {
      setText('[data-current-warning-badge-text]', 'No Active Local Warning');
      setText('[data-current-warning-title]', 'No active local warning');
      setText('[data-current-warning-summary]', 'No ACTIVE warning records are currently stored.');

      document.querySelectorAll('[data-current-warning-field]').forEach((field) => {
        field.textContent = 'Not available';
      });
      return;
    }

    const current = requireObject(warning, 'The current warning is malformed.');
    const level = requireObject(current.warning_level, 'The current warning level is malformed.');
    const source = requireObject(current.source, 'The current warning source is malformed.');

    setText('[data-current-warning-badge-text]', 'Active Local Warning');
    setText('[data-current-warning-title]', String(current.title || 'Active warning'));
    setText('[data-current-warning-summary]', String(current.summary || 'No summary available.'));
    setCurrentWarningField('warning_level', String(level.name || level.code || 'Not available'));
    setCurrentWarningField('hazard_type', formatCode(current.hazard_type));
    setCurrentWarningField('affected_area', affectedAreaText(current));
    setCurrentWarningField('issued_at', formatDateTime(current.issued_at));
    setCurrentWarningField('source', String(source.name || source.code || 'Not available'));
    setCurrentWarningField('valid_until', current.valid_until ? formatDateTime(current.valid_until) : 'No expiry specified');
  }

  function appendCell(row, value, className) {
    const cell = document.createElement('td');
    cell.className = className;
    cell.textContent = value;
    row.appendChild(cell);
  }

  function appendEmptyRecentWarningsRow(body) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    const wrapper = document.createElement('div');
    const title = document.createElement('p');
    const note = document.createElement('p');

    cell.colSpan = 7;
    cell.className = 'px-5 py-10 text-center';
    wrapper.className = 'mx-auto flex max-w-sm flex-col items-center';
    title.className = 'text-[11px] font-black text-slate-600 dark:text-slate-300';
    note.className = 'mt-1 text-[9px] font-medium text-slate-400';
    title.textContent = 'No warning records available.';
    note.textContent = 'No warning records are currently stored.';

    wrapper.append(title, note);
    cell.appendChild(wrapper);
    row.appendChild(cell);
    body.appendChild(row);
  }

  function renderRecentWarnings(warnings) {
    const body = document.querySelector('[data-recent-warnings-body]');
    if (!body) {
      return;
    }

    body.replaceChildren();

    if (warnings.length === 0) {
      appendEmptyRecentWarningsRow(body);
      setText('[data-recent-warnings-description]', 'No warning records are currently stored.');
      state.recentWarningCount = 0;
      return;
    }

    warnings.forEach((warning) => {
      const level = warning && warning.warning_level && typeof warning.warning_level === 'object'
        ? warning.warning_level
        : {};
      const source = warning && warning.source && typeof warning.source === 'object'
        ? warning.source
        : {};
      const row = document.createElement('tr');
      row.className = 'border-t border-slate-100 text-[10px] text-slate-600 dark:border-slate-800 dark:text-slate-300';

      appendCell(row, String(warning.title || 'Untitled warning'), 'px-5 py-3 font-bold');
      appendCell(row, formatCode(warning.hazard_type), 'px-4 py-3');
      appendCell(row, affectedAreaText(warning), 'px-4 py-3');
      appendCell(row, String(level.name || level.code || 'Not available'), 'px-4 py-3 font-bold');
      appendCell(row, String(source.name || source.code || 'Not available'), 'px-4 py-3');
      appendCell(row, formatDateTime(warning.issued_at), 'px-4 py-3');
      appendCell(row, formatCode(warning.status), 'px-5 py-3 font-bold');
      body.appendChild(row);
    });

    setText('[data-recent-warnings-description]', `Showing ${warnings.length} most recent warning record${warnings.length === 1 ? '' : 's'}.`);
    state.recentWarningCount = warnings.length;
  }

  async function loadDashboard() {
    if (typeof config.endpoint !== 'string' || config.endpoint === '') {
      throw new Error('The early-warning endpoint is not configured.');
    }

    state.fetchCount += 1;
    const response = await window.fetch(config.endpoint, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json'
      }
    });

    if (!response.ok) {
      throw new Error('The early-warning endpoint returned an error.');
    }

    const payload = await response.json();
    if (!payload || payload.success !== true) {
      throw new Error('The early-warning endpoint response was unsuccessful.');
    }

    const data = requireObject(payload.data, 'The early-warning response data is malformed.');
    const metrics = requireObject(data.metrics, 'The early-warning metrics are malformed.');
    const metadata = requireObject(data.metric_metadata, 'The early-warning metric metadata is malformed.');
    const sources = requireArray(data.sources, 'The advisory source list is malformed.');
    const recentWarnings = requireArray(data.recent_warnings, 'The recent warning list is malformed.');

    renderMetrics(metrics, metadata);
    renderSources(sources);
    renderCurrentWarning(data.current_warning ?? null);
    renderRecentWarnings(recentWarnings);

    state.loaded = true;
    state.lastResult = 'SUCCESS';
    setText('[data-early-warning-load-status]', 'Live read-only Module 4 records loaded from CIVENTRAL.');
  }

  async function loadPagasaOverview() {
    if (typeof config.pagasaEndpoint !== 'string' || config.pagasaEndpoint === '') {
      throw new Error('The PAGASA endpoint is not configured.');
    }

    state.pagasaFetchCount += 1;
    const response = await window.fetch(config.pagasaEndpoint, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json'
      }
    });

    if (!response.ok) {
      throw new Error('The PAGASA endpoint returned an error.');
    }

    const payload = await response.json();
    if (!payload || payload.success !== true) {
      throw new Error('The PAGASA endpoint response was unsuccessful.');
    }

    renderPagasaOverview(requireObject(payload.data, 'The PAGASA response data is malformed.'));
  }

  async function loadNdrrmcOverview() {
    if (typeof config.ndrrmcEndpoint !== 'string' || config.ndrrmcEndpoint === '') {
      throw new Error('The NDRRMC endpoint is not configured.');
    }

    state.ndrrmcFetchCount += 1;
    const response = await window.fetch(config.ndrrmcEndpoint, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json'
      }
    });

    if (!response.ok) {
      throw new Error('The NDRRMC endpoint returned an error.');
    }

    const payload = await response.json();
    if (!payload || payload.success !== true) {
      throw new Error('The NDRRMC endpoint response was unsuccessful.');
    }

    renderNdrrmcOverview(requireObject(payload.data, 'The NDRRMC response data is malformed.'));
  }

  function handleLoadFailure() {
    state.loaded = false;
    state.lastResult = 'ERROR';
    setText('[data-early-warning-load-status]', 'Early-warning data could not be loaded.');
  }

  function handlePagasaFailure() {
    state.pagasaLoaded = false;
    state.pagasaPublicStatus = 'TEMPORARILY_UNAVAILABLE';
    state.pagasaDetailedStatus = 'TEMPORARILY_UNAVAILABLE';
    setText('[data-pagasa-public-feed-status]', 'Official public feed: Temporarily Unavailable');
    setText('[data-pagasa-detailed-api-status]', 'Detailed API: Temporarily Unavailable');
    setText('[data-pagasa-runtime-badge]', 'Temporarily Unavailable');
    setText('[data-pagasa-issued-at]', 'Temporarily unavailable');
    setText('[data-pagasa-forecast-period]', 'Temporarily unavailable');
    setText('[data-pagasa-coverage]', 'Official source unavailable');
    setText('[data-pagasa-detailed-status]', 'Temporarily Unavailable');
    setText('[data-pagasa-advisory-title]', 'PAGASA advisory information is temporarily unavailable.');
    setText('[data-pagasa-advisory-message]', 'The rest of the Module 4 dashboard remains available.');
  }

  function handleNdrrmcFailure() {
    state.ndrrmcLoaded = false;
    state.ndrrmcSourceStatus = 'TEMPORARILY_UNAVAILABLE';
    state.ndrrmcAdvisoryCount = 0;
    setText('[data-ndrrmc-feed-status]', 'Official machine-readable feed: Unavailable');
    setText('[data-ndrrmc-runtime-badge]', 'Official Feed Unavailable');
    setText('[data-ndrrmc-source-status]', 'Temporarily unavailable');
    setText('[data-ndrrmc-advisory-count]', '0');
    setText('[data-ndrrmc-relevance-status]', 'Not available');
    setText('[data-ndrrmc-advisory-title]', 'NDRRMC advisory information is temporarily unavailable.');
    setText('[data-ndrrmc-advisory-message]', 'The PAGASA and Module 4 summary sections remain available.');
  }

  function initialize() {
    if (state.initialized) {
      return;
    }

    state.initialized = true;
    loadDashboard().catch(handleLoadFailure);
    loadPagasaOverview().catch(handlePagasaFailure);
    loadNdrrmcOverview().catch(handleNdrrmcFailure);
  }

  window.CiventralEarlyWarningDashboard = Object.freeze({
    diagnostics: function () {
      return {
        initialized: state.initialized,
        loaded: state.loaded,
        fetchCount: state.fetchCount,
        sourceCount: state.sourceCount,
        activeWarningCount: state.activeWarningCount,
        recentWarningCount: state.recentWarningCount,
        pagasaLoaded: state.pagasaLoaded,
        pagasaFetchCount: state.pagasaFetchCount,
        pagasaPublicStatus: state.pagasaPublicStatus,
        pagasaDetailedStatus: state.pagasaDetailedStatus,
        pagasaAdvisoryCount: state.pagasaAdvisoryCount,
        ndrrmcLoaded: state.ndrrmcLoaded,
        ndrrmcFetchCount: state.ndrrmcFetchCount,
        ndrrmcSourceStatus: state.ndrrmcSourceStatus,
        ndrrmcAdvisoryCount: state.ndrrmcAdvisoryCount,
        lastResult: state.lastResult
      };
    }
  });

  window.CiventralEarlyWarningSecurity = Object.freeze({
    capabilities: securityCapabilities,
    buildMutationHeaders
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
