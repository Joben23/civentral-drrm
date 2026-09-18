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
    canEditDraft: configuredCapabilities.canEditDraft === true,
    canActivateWarning: configuredCapabilities.canActivateWarning === true,
    canCancelWarning: configuredCapabilities.canCancelWarning === true,
    canReviewExternalAdvisories: configuredCapabilities.canReviewExternalAdvisories === true,
    canDismissExternalAdvisory: configuredCapabilities.canDismissExternalAdvisory === true,
    canConvertExternalAdvisoryToDraft: configuredCapabilities.canConvertExternalAdvisoryToDraft === true
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
    recentWarnings: [],
    barangays: null,
    selectedWarningId: null,
    editingWarningId: null,
    editingExpectedRevision: null,
    editingBarangayIds: [],
    externalAdvisories: [],
    externalAdvisoryStatus: 'PENDING_REVIEW',
    selectedExternalAdvisory: null,
    externalDetailRequestSerial: 0,
    convertingExternalAdvisoryId: null,
    convertingExternalPayloadVersion: null,
    convertingExternalSourceCode: null,
    mutationInFlight: false,
    lastResult: 'NOT_STARTED'
  };

  function setText(selector, value) {
    const element = document.querySelector(selector);
    if (element) {
      element.textContent = value;
    }
  }

  async function loadInAppReadiness() {
    const section = document.querySelector('#deliveryChannelsTitle')?.closest('section');
    const description = section?.querySelector('h2 + p');
    const inAppStatus = section?.querySelector('article p');
    if (description) {
      description.textContent = 'The citizen in-app warning feed is pull-only. Email, SMS, and push delivery are not connected.';
    }
    if (!inAppStatus) return;
    inAppStatus.textContent = 'Checking';
    if (typeof config.notificationReadinessEndpoint !== 'string' || !config.notificationReadinessEndpoint) {
      inAppStatus.textContent = 'Not Connected';
      return;
    }
    try {
      const response = await window.fetch(config.notificationReadinessEndpoint, {
        method: 'GET', credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json' }
      });
      const payload = await response.json();
      const readiness = payload && payload.data;
      const available = response.ok && payload && payload.success === true
        && readiness && readiness.in_app_available === true
        && readiness.delivery_type === 'AUTHENTICATED_PULL_FEED';
      inAppStatus.textContent = available ? 'Available - Pull Feed' : 'Not Connected';
    } catch (_) {
      inAppStatus.textContent = 'Not Connected';
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
   * Module 4 mutations use this helper so the session-bound token is carried
   * only in the X-CSRF-Token header, never in a URL.
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

  function setElementVisible(element, visible, flex = false) {
    if (!element) {
      return;
    }

    element.hidden = !visible;
    element.classList.toggle('hidden', !visible);
    if (flex) {
      element.classList.toggle('flex', visible);
    }
  }

  function setWorkflowStatus(element, message, type = 'info') {
    if (!element) {
      return;
    }

    element.textContent = message;
    element.className = 'rounded-xl border px-3 py-2 text-[10px] font-bold';
    element.classList.add(
      type === 'error' ? 'border-rose-200' : 'border-emerald-200',
      type === 'error' ? 'bg-rose-50' : 'bg-emerald-50',
      type === 'error' ? 'text-rose-700' : 'text-emerald-700'
    );
    element.hidden = false;
    element.classList.remove('hidden');
  }

  function clearWorkflowStatus(element) {
    if (!element) {
      return;
    }
    element.textContent = '';
    element.hidden = true;
    element.classList.add('hidden');
  }

  async function mutationRequest(endpoint, body) {
    if (typeof endpoint !== 'string' || endpoint === '') {
      throw new Error('The warning-management endpoint is unavailable.');
    }

    const response = await window.fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: window.CiventralEarlyWarningSecurity.buildMutationHeaders(),
      body: JSON.stringify(body)
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error('The warning-management response was invalid.');
    }

    if (!response.ok || !payload || payload.success !== true) {
      const message = payload && typeof payload.message === 'string'
        ? payload.message
        : 'The warning-management request failed.';
      throw new Error(message);
    }

    return requireObject(payload.data, 'The warning-management response data is malformed.');
  }

  function localDateTimeValue(date = new Date()) {
    const localTime = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
    return localTime.toISOString().slice(0, 16);
  }

  function dateTimeInputToIso(value, required) {
    if (typeof value !== 'string' || value.trim() === '') {
      if (required) {
        throw new Error('Issued At is required.');
      }
      return null;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      throw new Error('Enter a valid warning date and time.');
    }
    return date.toISOString();
  }

  function isoToLocalDateTimeInput(value) {
    if (typeof value !== 'string' || value.trim() === '') {
      return '';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : localDateTimeValue(date);
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

  function warningDisplayStatus(warning) {
    if (warning && typeof warning.effective_status === 'string' && warning.effective_status !== '') {
      return warning.effective_status;
    }
    return warning && typeof warning.status === 'string' ? warning.status : '';
  }

  function warningStoredStatus(warning) {
    if (warning && typeof warning.stored_status === 'string' && warning.stored_status !== '') {
      return warning.stored_status;
    }
    return warning && typeof warning.status === 'string' ? warning.status : '';
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
      case 'PARTIAL':
        return 'Partial';
      case 'UNAVAILABLE':
        return 'Unavailable';
      case 'DISABLED':
        return 'Disabled';
      case 'PENDING':
        return 'Pending';
      default:
        return 'Unknown';
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
    const classification = requireObject(
      data.source_classification,
      'The PAGASA source classification is malformed.'
    );
    const detailedApi = requireObject(data.detailed_api, 'The PAGASA API status is malformed.');
    const advisories = requireArray(data.advisories, 'The PAGASA advisory list is malformed.');

    if (source.agency !== 'DOST-PAGASA' || source.product !== 'TenDay Weather Forecast') {
      throw new Error('The PAGASA source identity is invalid.');
    }
    if (classification.source_code !== 'PAGASA'
      || classification.integration_status !== 'PARTIAL'
      || classification.source_kind !== 'FORECAST_METADATA_API'
      || classification.operational_advisory_status !== 'PENDING'
      || classification.supports_operational_advisory_fetch !== false) {
      throw new Error('The PAGASA source capability classification is invalid.');
    }
    if (data.external_information_only !== true) {
      throw new Error('The PAGASA response did not preserve external-information separation.');
    }

    state.pagasaPublicStatus = String(data.public_information_status || 'TEMPORARILY_UNAVAILABLE');
    state.pagasaDetailedStatus = String(detailedApi.status || 'TEMPORARILY_UNAVAILABLE');
    state.pagasaAdvisoryCount = advisories.length;

    const metadataStatus = state.pagasaPublicStatus === 'AVAILABLE'
      ? 'Connected'
      : pagasaStatusLabel(state.pagasaPublicStatus);
    setText('[data-pagasa-public-feed-status]', `PAGASA forecast issuance metadata: ${metadataStatus}`);
    setText('[data-pagasa-detailed-api-status]', `Detailed Caloocan forecast API: ${pagasaStatusLabel(state.pagasaDetailedStatus)}`);
    setText(
      '[data-pagasa-operational-advisory-status]',
      'PAGASA operational advisories: Pending verified machine-readable source'
    );
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
    setText(
      '[data-ndrrmc-advisory-count]',
      sourceConfirmed ? String(advisories.length) : 'Not available - no verified feed'
    );
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
      setText('[data-current-warning-summary]', 'No warning is currently within its effective issue and validity window.');

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

  function createReviewButton(warningId) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'rounded-lg border border-slate-200 px-2.5 py-1.5 text-[9px] font-black text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
    button.dataset.reviewWarningId = warningId;
    button.textContent = 'Review';
    return button;
  }

  function appendActionsCell(row, warningId) {
    const cell = document.createElement('td');
    cell.className = 'px-5 py-3 text-right';
    cell.appendChild(createReviewButton(warningId));
    row.appendChild(cell);
  }

  function appendExternalAdvisoryEmptyRow(body, status) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = 8;
    cell.className = 'px-5 py-10 text-center text-[10px] font-medium text-slate-400';
    cell.textContent = status === 'PENDING_REVIEW'
      ? 'No staged external advisories awaiting review.'
      : `No staged external advisories with status ${formatCode(status)}.`;
    row.appendChild(cell);
    body.appendChild(row);
  }

  function renderExternalAdvisories(advisories, status) {
    const body = document.querySelector('[data-external-advisory-body]');
    if (!body) {
      return;
    }
    body.replaceChildren();
    state.externalAdvisories = advisories;
    state.externalAdvisoryStatus = status;

    if (advisories.length === 0) {
      appendExternalAdvisoryEmptyRow(body, status);
      setText('[data-external-advisory-status]', status === 'PENDING_REVIEW'
        ? 'No staged external advisories awaiting review.'
        : `No ${formatCode(status).toLowerCase()} staged advisories.`);
      return;
    }

    advisories.forEach((advisory) => {
      const source = requireObject(advisory.source, 'An external advisory source is malformed.');
      if (typeof advisory.id !== 'string' || !Number.isInteger(advisory.payload_version)) {
        throw new Error('A staged external advisory is malformed.');
      }
      const row = document.createElement('tr');
      row.className = 'border-t border-slate-100 text-[10px] text-slate-600 dark:border-slate-800 dark:text-slate-300';
      appendCell(row, String(advisory.title || 'Untitled advisory'), 'px-5 py-3 font-bold');
      appendCell(row, String(source.name || source.code || 'Not available'), 'px-4 py-3');
      appendCell(row, `${formatCode(advisory.advisory_type)} / ${formatCode(advisory.hazard_type)}`, 'px-4 py-3');
      appendCell(row, formatDateTime(advisory.issued_at), 'px-4 py-3');
      appendCell(row, formatDateTime(advisory.fetched_at), 'px-4 py-3');
      appendCell(row, String(advisory.payload_version), 'px-4 py-3 font-bold');
      appendCell(row, formatCode(advisory.review_status), 'px-4 py-3 font-bold');
      const action = document.createElement('td');
      const button = document.createElement('button');
      action.className = 'px-5 py-3 text-right';
      button.type = 'button';
      button.className = 'rounded-lg border border-slate-200 px-2.5 py-1.5 text-[9px] font-black dark:border-slate-700';
      button.dataset.externalAdvisoryId = advisory.id;
      button.textContent = 'View Details';
      action.appendChild(button);
      row.appendChild(action);
      body.appendChild(row);
    });
    setText('[data-external-advisory-status]', `${advisories.length} ${formatCode(status).toLowerCase()} staged advisor${advisories.length === 1 ? 'y' : 'ies'}.`);
  }

  async function loadExternalAdvisories(status = 'PENDING_REVIEW') {
    if (!securityCapabilities.canReviewExternalAdvisories
      || typeof config.externalAdvisoriesEndpoint !== 'string') {
      return;
    }
    const response = await window.fetch(
      `${config.externalAdvisoriesEndpoint}?status=${encodeURIComponent(status)}`,
      { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }
    );
    const payload = await response.json();
    if (!response.ok || !payload || payload.success !== true) {
      throw new Error('Staged external advisories could not be loaded.');
    }
    const data = requireObject(payload.data, 'The staged advisory response is malformed.');
    renderExternalAdvisories(requireArray(data.advisories, 'The staged advisory list is malformed.'), status);
  }

  function setExternalField(name, value) {
    setText(`[data-external-field="${name}"]`, value);
  }

  function renderExternalReviewHistory(events) {
    const container = document.querySelector('[data-external-review-history]');
    if (!container) {
      return;
    }
    container.replaceChildren();
    if (events.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'text-[10px] font-medium text-slate-400';
      empty.textContent = 'No review decision has been recorded.';
      container.appendChild(empty);
      return;
    }
    events.forEach((event) => {
      const item = document.createElement('p');
      item.className = 'rounded-lg bg-slate-50 px-3 py-2 text-[10px] font-medium text-slate-600 dark:bg-slate-950 dark:text-slate-300';
      item.textContent = `${formatCode(event.event_type)} by ${String(event.actor_reference || 'unknown actor')} at ${formatDateTime(event.occurred_at)} (payload v${Number(event.payload_version) || '?'})`;
      container.appendChild(item);
    });
  }

  function updateExternalReviewActions(advisory) {
    const pending = advisory.review_status === 'PENDING_REVIEW';
    setElementVisible(
      document.querySelector('[data-dismiss-external-advisory]'),
      pending && securityCapabilities.canDismissExternalAdvisory
    );
    setElementVisible(
      document.querySelector('[data-convert-external-advisory]'),
      pending && securityCapabilities.canConvertExternalAdvisoryToDraft
    );
  }

  function renderExternalAdvisoryDetail(advisory) {
    const source = requireObject(advisory.source, 'The external advisory source is malformed.');
    const provenance = requireObject(advisory.provenance, 'The advisory provenance is malformed.');
    const firstFetch = requireObject(provenance.first_fetch, 'The first fetch metadata is malformed.');
    const latestFetch = requireObject(provenance.latest_fetch, 'The latest fetch metadata is malformed.');
    setExternalField('title', String(advisory.title || 'Not available'));
    setExternalField('source', String(source.name || source.code || 'Not available'));
    setExternalField('status', formatCode(advisory.review_status));
    setExternalField('type', `${formatCode(advisory.advisory_type)} / ${formatCode(advisory.hazard_type)}`);
    setExternalField('version', String(advisory.payload_version));
    setExternalField('issued_at', formatDateTime(advisory.issued_at));
    setExternalField('valid_until', advisory.valid_until ? formatDateTime(advisory.valid_until) : 'Not provided');
    setExternalField('source_reference', String(advisory.source_reference || 'Not provided'));
    setExternalField('summary', String(advisory.summary || 'Not provided'));
    setText(
      '[data-external-provenance]',
      `First fetch: ${formatDateTime(firstFetch.started_at)} (${formatCode(firstFetch.result)}). Latest fetch: ${formatDateTime(latestFetch.finished_at)} (${formatCode(latestFetch.result)}; ${Number(latestFetch.staged_item_count) || 0} staged).`
    );
    renderExternalReviewHistory(requireArray(advisory.review_history, 'The review audit is malformed.'));
    updateExternalReviewActions(advisory);
  }

  async function openExternalAdvisory(advisoryId) {
    const modal = document.querySelector('[data-external-advisory-modal]');
    if (!modal || typeof config.externalAdvisoriesEndpoint !== 'string') {
      return;
    }
    const requestSerial = ++state.externalDetailRequestSerial;
    state.selectedExternalAdvisory = null;
    updateExternalReviewActions({ review_status: '' });
    setElementVisible(modal, true, true);
    document.body.classList.add('module4-modal-open');
    clearWorkflowStatus(modal.querySelector('[data-external-review-workflow-status]'));
    setText('[data-external-provenance]', 'Loading safe fetch metadata...');
    try {
      const response = await window.fetch(
        `${config.externalAdvisoriesEndpoint}?advisory_id=${encodeURIComponent(advisoryId)}`,
        { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }
      );
      const payload = await response.json();
      if (!response.ok || !payload || payload.success !== true) {
        throw new Error('The staged advisory details could not be loaded.');
      }
      const data = requireObject(payload.data, 'The advisory detail response is malformed.');
      const advisory = requireObject(data.advisory, 'The advisory detail is malformed.');
      if (requestSerial !== state.externalDetailRequestSerial || modal.hidden) {
        return;
      }
      renderExternalAdvisoryDetail(advisory);
      state.selectedExternalAdvisory = advisory;
    } catch (error) {
      if (requestSerial === state.externalDetailRequestSerial && !modal.hidden) {
        setWorkflowStatus(modal.querySelector('[data-external-review-workflow-status]'), error.message, 'error');
      }
    }
  }

  function closeExternalAdvisory() {
    const modal = document.querySelector('[data-external-advisory-modal]');
    state.externalDetailRequestSerial += 1;
    setElementVisible(modal, false, true);
    state.selectedExternalAdvisory = null;
    document.body.classList.remove('module4-modal-open');
  }

  async function dismissExternalAdvisory() {
    const advisory = state.selectedExternalAdvisory;
    const modal = document.querySelector('[data-external-advisory-modal]');
    const status = modal ? modal.querySelector('[data-external-review-workflow-status]') : null;
    if (state.mutationInFlight || !advisory
      || advisory.review_status !== 'PENDING_REVIEW'
      || !securityCapabilities.canDismissExternalAdvisory) {
      return;
    }
    if (!window.confirm('Dismiss this staged advisory? This review decision is terminal in Phase 4B.2.')) {
      return;
    }
    state.mutationInFlight = true;
    clearWorkflowStatus(status);
    let reviewSaved = false;
    try {
      await mutationRequest(config.externalAdvisoryReviewEndpoint, {
        action: 'DISMISS',
        external_advisory_id: advisory.id,
        expected_payload_version: advisory.payload_version
      });
      reviewSaved = true;
      advisory.review_status = 'DISMISSED';
      updateExternalReviewActions(advisory);
      setWorkflowStatus(status, 'The staged advisory was dismissed. No warning was created.', 'success');
      await loadExternalAdvisories(state.externalAdvisoryStatus);
      window.setTimeout(closeExternalAdvisory, 500);
    } catch (error) {
      setWorkflowStatus(
        status,
        reviewSaved
          ? 'The advisory was dismissed, but the list could not be refreshed. Reload the page before continuing.'
          : (error.message || 'Unable to dismiss the staged advisory.'),
        reviewSaved ? 'success' : 'error'
      );
    } finally {
      state.mutationInFlight = false;
    }
  }

  function beginExternalDraftConversion() {
    const advisory = state.selectedExternalAdvisory;
    if (!advisory || advisory.review_status !== 'PENDING_REVIEW'
      || !securityCapabilities.canConvertExternalAdvisoryToDraft) {
      return;
    }
    closeExternalAdvisory();
    toggleCreateWarningModal(true, null, advisory);
  }

  async function loadBarangays() {
    if (Array.isArray(state.barangays)) {
      return state.barangays;
    }
    if (typeof config.barangaysEndpoint !== 'string' || config.barangaysEndpoint === '') {
      throw new Error('The validated barangay list is unavailable.');
    }

    const response = await window.fetch(config.barangaysEndpoint, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    });
    const payload = await response.json();

    if (!response.ok || !payload || payload.success !== true) {
      throw new Error('The validated barangay list could not be loaded.');
    }

    const data = requireObject(payload.data, 'The barangay response is malformed.');
    const barangays = requireArray(data.barangays, 'The barangay list is malformed.');
    if (data.count !== 187 || barangays.length !== 187) {
      throw new Error('The validated barangay list is incomplete.');
    }

    state.barangays = barangays;
    return barangays;
  }

  function renderBarangayOptions(barangays) {
    const container = document.querySelector('[data-barangay-options]');
    if (!container) {
      return;
    }
    container.replaceChildren();

    barangays.forEach((barangay) => {
      if (!barangay || typeof barangay.barangay_id !== 'string' || typeof barangay.name !== 'string') {
        throw new Error('A validated barangay option is malformed.');
      }
      const label = document.createElement('label');
      const checkbox = document.createElement('input');
      const text = document.createElement('span');
      label.className = 'flex items-center gap-2 rounded-lg border border-slate-100 px-2.5 py-2 text-[10px] font-bold text-slate-600 hover:bg-slate-50 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800';
      label.dataset.barangaySearchValue = barangay.name.toLowerCase();
      checkbox.type = 'checkbox';
      checkbox.value = barangay.barangay_id;
      checkbox.dataset.barangayId = barangay.barangay_id;
      checkbox.checked = state.editingBarangayIds.includes(barangay.barangay_id);
      checkbox.className = 'h-4 w-4 accent-slate-900';
      text.textContent = barangay.name;
      label.append(checkbox, text);
      container.appendChild(label);
    });
  }

  async function ensureBarangaysVisible() {
    const status = document.querySelector('[data-barangay-load-status]');
    if (status) {
      status.textContent = 'Loading 187 validated development-preview barangays...';
    }
    try {
      const barangays = await loadBarangays();
      renderBarangayOptions(barangays);
      if (status) {
        status.textContent = '187 validated barangays available. Barangay 176 and 176-A through 176-F are unavailable.';
      }
    } catch (error) {
      if (status) {
        status.textContent = 'Validated barangays could not be loaded.';
      }
    }
  }

  function updateSourceReferenceRequirement() {
    const source = document.querySelector('[data-warning-source]');
    const reference = document.querySelector('[data-source-reference]');
    if (!source || !reference) {
      return;
    }
    reference.required = ['PAGASA', 'PHIVOLCS', 'NDRRMC'].includes(source.value);
  }

  function updateAffectedAreaSelector() {
    const selected = document.querySelector('input[name="scope_type"]:checked');
    const selector = document.querySelector('[data-barangay-selector]');
    const showBarangays = selected && selected.value === 'BARANGAY';
    setElementVisible(selector, Boolean(showBarangays));
    if (showBarangays) {
      ensureBarangaysVisible();
    }
  }

  function populateWarningForm(form, warning) {
    const level = warning.warning_level && typeof warning.warning_level === 'object'
      ? warning.warning_level
      : {};
    const source = warning.source && typeof warning.source === 'object' ? warning.source : {};
    const areas = Array.isArray(warning.affected_areas) ? warning.affected_areas : [];
    const scopeType = areas.some((area) => area && area.scope_type === 'BARANGAY')
      ? 'BARANGAY'
      : 'CITY';
    const values = {
      title: warning.title || '',
      hazard_type: warning.hazard_type || '',
      warning_level: level.code || '',
      source_code: source.code || '',
      source_reference: warning.source_reference || '',
      issued_at: isoToLocalDateTimeInput(warning.issued_at),
      valid_until: isoToLocalDateTimeInput(warning.valid_until),
      summary: warning.summary || ''
    };

    Object.entries(values).forEach(([name, value]) => {
      const control = form.elements.namedItem(name);
      if (control && 'value' in control) {
        control.value = String(value);
      }
    });
    const scope = form.querySelector(`input[name="scope_type"][value="${scopeType}"]`);
    if (scope) {
      scope.checked = true;
    }

    if (scopeType === 'BARANGAY') {
      state.editingBarangayIds = areas
        .map((area) => (area && typeof area.barangay_id === 'string' ? area.barangay_id : ''))
        .filter(Boolean);
    } else {
      state.editingBarangayIds = [];
    }
  }

  function populateExternalDraftForm(form, advisory) {
    const source = requireObject(advisory.source, 'The external advisory source is unavailable.');
    const values = {
      title: advisory.title || '',
      hazard_type: advisory.hazard_type || '',
      warning_level: '',
      source_code: source.code || '',
      source_reference: advisory.source_reference || '',
      issued_at: isoToLocalDateTimeInput(advisory.issued_at),
      valid_until: isoToLocalDateTimeInput(advisory.valid_until),
      summary: advisory.summary || ''
    };
    Object.entries(values).forEach(([name, value]) => {
      const control = form.elements.namedItem(name);
      if (control && 'value' in control) {
        control.value = String(value);
      }
    });
    form.querySelectorAll('input[name="scope_type"]').forEach((input) => {
      input.checked = false;
    });
    const sourceControl = form.elements.namedItem('source_code');
    if (sourceControl) {
      sourceControl.disabled = true;
    }
    state.editingBarangayIds = [];
  }

  function toggleCreateWarningModal(show, warning = null, externalAdvisory = null) {
    const modal = document.querySelector('[data-create-warning-modal]');
    if (!modal) {
      return;
    }
    setElementVisible(modal, show, true);
    document.body.classList.toggle('module4-modal-open', show);

    if (!show) {
      state.editingWarningId = null;
      state.editingExpectedRevision = null;
      state.editingBarangayIds = [];
      state.convertingExternalAdvisoryId = null;
      state.convertingExternalPayloadVersion = null;
      state.convertingExternalSourceCode = null;
      const sourceControl = modal.querySelector('[data-warning-source]');
      if (sourceControl) {
        sourceControl.disabled = false;
      }
      return;
    }

    if (show) {
      const form = modal.querySelector('[data-create-warning-form]');
      if (form) {
        form.reset();
        const sourceControl = form.elements.namedItem('source_code');
        if (sourceControl) {
          sourceControl.disabled = false;
        }
        if (externalAdvisory) {
          state.editingWarningId = null;
          state.editingExpectedRevision = null;
          state.convertingExternalAdvisoryId = externalAdvisory.id;
          state.convertingExternalPayloadVersion = externalAdvisory.payload_version;
          state.convertingExternalSourceCode = externalAdvisory.source.code;
          populateExternalDraftForm(form, externalAdvisory);
        } else if (warning) {
          state.editingWarningId = warning.id;
          state.editingExpectedRevision = warning.revision;
          populateWarningForm(form, warning);
        } else {
          state.editingWarningId = null;
          state.editingExpectedRevision = null;
          state.editingBarangayIds = [];
          state.convertingExternalAdvisoryId = null;
          state.convertingExternalPayloadVersion = null;
          state.convertingExternalSourceCode = null;
          const issuedAt = form.elements.namedItem('issued_at');
          if (issuedAt instanceof HTMLInputElement) {
            issuedAt.value = localDateTimeValue();
          }
        }
      }
      setText('[data-warning-form-title]', externalAdvisory
        ? 'Create CIVENTRAL Draft from Advisory'
        : (warning ? 'Edit Warning Draft' : 'Create Warning Draft'));
      setText(
        '[data-warning-form-description]',
        externalAdvisory
          ? 'Confirm every CIVENTRAL field. The external advisory is only a source suggestion and this action never activates the warning.'
          : warning
          ? 'Only this DRAFT definition is editable. Saving records a new immutable history event.'
          : 'Saving creates a DRAFT only. No alert is delivered or activated automatically.'
      );
      const saveButton = modal.querySelector('[data-save-warning-draft]');
      if (saveButton) {
        saveButton.disabled = false;
        saveButton.textContent = externalAdvisory
          ? 'Create CIVENTRAL Draft'
          : (warning ? 'Save Draft Changes' : 'Save as Draft');
      }
      clearWorkflowStatus(modal.querySelector('[data-warning-workflow-status]'));
      updateSourceReferenceRequirement();
      updateAffectedAreaSelector();
      const title = modal.querySelector('input[name="title"]');
      if (title) {
        window.setTimeout(() => title.focus(), 0);
      }
    }
  }

  function warningById(warningId) {
    return state.recentWarnings.find((warning) => warning && warning.id === warningId) || null;
  }

  function setReviewField(name, value) {
    setText(`[data-review-field="${name}"]`, value);
  }

  function renderWarningHistory(events) {
    const list = document.querySelector('[data-warning-history-list]');
    if (!list) {
      return;
    }
    list.replaceChildren();
    if (events.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'text-[10px] font-medium text-slate-400';
      empty.textContent = 'No recorded lifecycle events (legacy warnings were not backfilled).';
      list.appendChild(empty);
      setText('[data-warning-history-status]', 'No recorded events');
      return;
    }

    events.forEach((event) => {
      const item = document.createElement('article');
      const heading = document.createElement('div');
      const action = document.createElement('p');
      const time = document.createElement('time');
      const detail = document.createElement('p');
      item.className = 'rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950';
      heading.className = 'flex flex-wrap items-center justify-between gap-2';
      action.className = 'text-[10px] font-black text-slate-700 dark:text-slate-200';
      time.className = 'text-[9px] font-medium text-slate-400';
      detail.className = 'mt-1 text-[9px] font-medium text-slate-500 dark:text-slate-400';
      action.textContent = formatCode(event.event_type);
      time.textContent = formatDateTime(event.occurred_at);
      detail.textContent = `${String(event.actor_reference || 'Actor unavailable')} · Result: ${formatCode(event.resulting_status)} · Revision ${Number(event.resulting_revision) || '?'}`;
      heading.append(action, time);
      item.append(heading, detail);
      list.appendChild(item);
    });
    setText('[data-warning-history-status]', `${events.length} recorded event${events.length === 1 ? '' : 's'}`);
  }

  async function loadWarningHistory(warningId) {
    if (typeof config.historyEndpoint !== 'string' || config.historyEndpoint === '') {
      throw new Error('Warning history is unavailable.');
    }
    const response = await window.fetch(
      `${config.historyEndpoint}?warning_id=${encodeURIComponent(warningId)}`,
      { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }
    );
    const payload = await response.json();
    if (!response.ok || !payload || payload.success !== true) {
      throw new Error('Warning history could not be loaded.');
    }
    const data = requireObject(payload.data, 'The warning-history response is malformed.');
    const events = requireArray(data.events, 'The warning-history event list is malformed.');
    if (state.selectedWarningId === warningId) {
      renderWarningHistory(events);
    }
  }

  function updateReviewActions(warning) {
    const edit = document.querySelector('[data-edit-warning-draft]');
    const activate = document.querySelector('[data-activate-warning]');
    const cancel = document.querySelector('[data-cancel-warning]');
    const storedStatus = warningStoredStatus(warning);
    const canEdit = storedStatus === 'DRAFT' && securityCapabilities.canEditDraft;
    const canActivate = storedStatus === 'DRAFT' && securityCapabilities.canActivateWarning;
    const canCancel = ['DRAFT', 'ACTIVE'].includes(storedStatus) && securityCapabilities.canCancelWarning;
    setElementVisible(edit, canEdit);
    setElementVisible(activate, canActivate);
    setElementVisible(cancel, canCancel);
  }

  function openReviewWarning(warningId) {
    const warning = warningById(warningId);
    const modal = document.querySelector('[data-review-warning-modal]');
    if (!warning || !modal) {
      return;
    }

    const level = warning.warning_level && typeof warning.warning_level === 'object'
      ? warning.warning_level
      : {};
    const source = warning.source && typeof warning.source === 'object' ? warning.source : {};
    state.selectedWarningId = warningId;
    setReviewField('title', String(warning.title || 'Not available'));
    setReviewField('hazard', formatCode(warning.hazard_type));
    setReviewField('level', String(level.name || level.code || 'Not available'));
    setReviewField('areas', affectedAreaText(warning));
    setReviewField('source', String(source.name || source.code || 'Not available'));
    setReviewField('status', formatCode(warningDisplayStatus(warning)));
    setReviewField('source_reference', String(warning.source_reference || 'Not provided'));
    setReviewField('issued_at', formatDateTime(warning.issued_at));
    setReviewField('valid_until', warning.valid_until ? formatDateTime(warning.valid_until) : 'No expiry specified');
    setReviewField('summary', String(warning.summary || 'Not available'));
    clearWorkflowStatus(modal.querySelector('[data-review-workflow-status]'));
    const historyList = modal.querySelector('[data-warning-history-list]');
    if (historyList) {
      historyList.replaceChildren();
    }
    setText('[data-warning-history-status]', 'Loading recorded events...');
    updateReviewActions(warning);
    setElementVisible(modal, true, true);
    document.body.classList.add('module4-modal-open');
    loadWarningHistory(warningId).catch(() => {
      if (state.selectedWarningId === warningId) {
        setText('[data-warning-history-status]', 'History unavailable');
      }
    });
  }

  function closeReviewWarning() {
    const modal = document.querySelector('[data-review-warning-modal]');
    setElementVisible(modal, false, true);
    state.selectedWarningId = null;
    document.body.classList.remove('module4-modal-open');
  }

  async function submitCreateWarning(event) {
    event.preventDefault();
    const isEditing = typeof state.editingWarningId === 'string';
    const isConverting = typeof state.convertingExternalAdvisoryId === 'string';
    if (state.mutationInFlight
      || (isEditing && !securityCapabilities.canEditDraft)
      || (isConverting && !securityCapabilities.canConvertExternalAdvisoryToDraft)
      || (!isEditing && !isConverting && !securityCapabilities.canCreateWarning)) {
      return;
    }

    const form = event.currentTarget;
    const status = form.querySelector('[data-warning-workflow-status]');
    if (!form.reportValidity()) {
      return;
    }

    const data = new FormData(form);
    const scopeType = String(data.get('scope_type') || '');
    const barangayIds = scopeType === 'BARANGAY'
      ? Array.from(form.querySelectorAll('[data-barangay-id]:checked')).map((input) => input.value)
      : [];

    if (scopeType === 'BARANGAY' && barangayIds.length === 0) {
      setWorkflowStatus(status, 'Select at least one validated barangay.', 'error');
      return;
    }

    let payload;
    try {
      payload = {
        title: String(data.get('title') || '').trim(),
        hazard_type: String(data.get('hazard_type') || ''),
        warning_level: String(data.get('warning_level') || ''),
        source_code: isConverting
          ? String(state.convertingExternalSourceCode || '')
          : String(data.get('source_code') || ''),
        summary: String(data.get('summary') || '').trim(),
        issued_at: dateTimeInputToIso(String(data.get('issued_at') || ''), true),
        valid_until: dateTimeInputToIso(String(data.get('valid_until') || ''), false),
        source_reference: String(data.get('source_reference') || '').trim() || null,
        scope_type: scopeType,
        barangay_ids: barangayIds
      };
      if (isEditing) {
        if (!Number.isInteger(state.editingExpectedRevision) || state.editingExpectedRevision < 1) {
          throw new Error('The loaded warning revision is invalid. Refresh the warning list.');
        }
        payload.warning_id = state.editingWarningId;
        payload.expected_revision = state.editingExpectedRevision;
      } else if (isConverting) {
        if (!Number.isInteger(state.convertingExternalPayloadVersion)
          || state.convertingExternalPayloadVersion < 1) {
          throw new Error('The loaded advisory version is invalid. Refresh the advisory before converting it.');
        }
        payload = {
          action: 'CREATE_DRAFT',
          external_advisory_id: state.convertingExternalAdvisoryId,
          expected_payload_version: state.convertingExternalPayloadVersion,
          ...payload
        };
      }
    } catch (error) {
      setWorkflowStatus(status, error.message, 'error');
      return;
    }

    const submitButton = form.querySelector('[data-save-warning-draft]');
    state.mutationInFlight = true;
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = isEditing
        ? 'Saving Changes...'
        : (isConverting ? 'Creating Draft...' : 'Saving Draft...');
    }
    clearWorkflowStatus(status);

    let conversionSaved = false;
    let conversionRefreshFailed = false;
    try {
      const editedWarningId = isEditing ? state.editingWarningId : null;
      await mutationRequest(
        isEditing
          ? config.updateEndpoint
          : (isConverting ? config.externalAdvisoryReviewEndpoint : config.createEndpoint),
        payload
      );
      conversionSaved = isConverting;
      setWorkflowStatus(
        status,
        isEditing
          ? 'Draft changes saved and recorded in history.'
          : (isConverting
            ? 'CIVENTRAL warning created as DRAFT and linked to the reviewed advisory. It was not activated.'
            : 'Warning saved as DRAFT. No alert was delivered.'),
        'success'
      );
      try {
        await loadDashboard();
        if (isConverting) {
          await loadExternalAdvisories(state.externalAdvisoryStatus);
        }
      } catch (error) {
        if (!isConverting) {
          throw error;
        }
        conversionRefreshFailed = true;
        setWorkflowStatus(
          status,
          'The CIVENTRAL draft was created, but the dashboard could not refresh. Reload the page before continuing; do not submit again.',
          'success'
        );
      }
      if (conversionRefreshFailed) {
        return;
      }
      if (editedWarningId) {
        toggleCreateWarningModal(false);
        openReviewWarning(editedWarningId);
      } else {
        window.setTimeout(() => toggleCreateWarningModal(false), 650);
      }
    } catch (error) {
      setWorkflowStatus(
        status,
        conversionSaved
          ? 'The CIVENTRAL draft was created, but its display could not refresh. Reload the page before continuing; do not submit again.'
          : (error.message || 'Unable to save warning.'),
        conversionSaved ? 'success' : 'error'
      );
      conversionRefreshFailed = conversionSaved;
    } finally {
      state.mutationInFlight = false;
      if (submitButton) {
        submitButton.disabled = conversionRefreshFailed;
        submitButton.textContent = isEditing
          ? 'Save Draft Changes'
          : (isConverting ? 'Create CIVENTRAL Draft' : 'Save as Draft');
      }
    }
  }

  async function submitWarningAction(action) {
    if (state.mutationInFlight || !state.selectedWarningId) {
      return;
    }
    const warning = warningById(state.selectedWarningId);
    const modal = document.querySelector('[data-review-warning-modal]');
    const status = modal ? modal.querySelector('[data-review-workflow-status]') : null;
    if (!warning) {
      return;
    }

    const confirmation = action === 'ACTIVATE'
      ? 'Activate this CIVENTRAL warning? Alert delivery is not connected.'
      : 'Cancel this warning? This action cannot be reversed in this phase.';
    if (!window.confirm(confirmation)) {
      return;
    }

    state.mutationInFlight = true;
    clearWorkflowStatus(status);
    try {
      await mutationRequest(config.statusEndpoint, {
        warning_id: warning.id,
        expected_revision: warning.revision,
        action
      });
      setWorkflowStatus(status, action === 'ACTIVATE'
        ? 'Warning activated. No alert was delivered.'
        : 'Warning cancelled.', 'success');
      await loadDashboard();
      window.setTimeout(closeReviewWarning, 500);
    } catch (error) {
      setWorkflowStatus(status, error.message || 'Unable to update warning status.', 'error');
    } finally {
      state.mutationInFlight = false;
    }
  }

  function appendEmptyRecentWarningsRow(body) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    const wrapper = document.createElement('div');
    const title = document.createElement('p');
    const note = document.createElement('p');

    cell.colSpan = 8;
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

      if (typeof warning.id !== 'string' || warning.id === '') {
        throw new Error('A recent warning is missing its identifier.');
      }

      appendCell(row, String(warning.title || 'Untitled warning'), 'px-5 py-3 font-bold');
      appendCell(row, formatCode(warning.hazard_type), 'px-4 py-3');
      appendCell(row, affectedAreaText(warning), 'px-4 py-3');
      appendCell(row, String(level.name || level.code || 'Not available'), 'px-4 py-3 font-bold');
      appendCell(row, String(source.name || source.code || 'Not available'), 'px-4 py-3');
      appendCell(row, formatDateTime(warning.issued_at), 'px-4 py-3');
      appendCell(row, formatCode(warningDisplayStatus(warning)), 'px-5 py-3 font-bold');
      appendActionsCell(row, warning.id);
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
    state.recentWarnings = recentWarnings;

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
    setText('[data-pagasa-public-feed-status]', 'PAGASA forecast issuance metadata: Temporarily Unavailable');
    setText('[data-pagasa-detailed-api-status]', 'Detailed Caloocan forecast API: Temporarily Unavailable');
    setText(
      '[data-pagasa-operational-advisory-status]',
      'PAGASA operational advisories: Pending verified machine-readable source'
    );
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
    setText('[data-ndrrmc-advisory-count]', 'Unavailable');
    setText('[data-ndrrmc-relevance-status]', 'Not available');
    setText('[data-ndrrmc-advisory-title]', 'NDRRMC advisory information is temporarily unavailable.');
    setText('[data-ndrrmc-advisory-message]', 'The PAGASA and Module 4 summary sections remain available.');
  }

  function bindWarningWorkflow() {
    const createButton = document.querySelector('[data-open-create-warning]');
    const createModal = document.querySelector('[data-create-warning-modal]');
    const createForm = document.querySelector('[data-create-warning-form]');
    const reviewModal = document.querySelector('[data-review-warning-modal]');
    const recentBody = document.querySelector('[data-recent-warnings-body]');
    const externalModal = document.querySelector('[data-external-advisory-modal]');
    const externalBody = document.querySelector('[data-external-advisory-body]');
    const externalFilter = document.querySelector('[data-external-advisory-filter]');
    const source = document.querySelector('[data-warning-source]');
    const barangaySearch = document.querySelector('[data-barangay-search]');

    if (createButton) {
      createButton.addEventListener('click', () => toggleCreateWarningModal(true));
    }
    if (createForm) {
      createForm.addEventListener('submit', submitCreateWarning);
      createForm.addEventListener('change', (event) => {
        if (event.target && event.target.name === 'scope_type') {
          updateAffectedAreaSelector();
        }
        if (event.target && event.target.matches('[data-barangay-id]')) {
          state.editingBarangayIds = Array.from(
            createForm.querySelectorAll('[data-barangay-id]:checked')
          ).map((input) => input.value);
        }
      });
    }
    if (source) {
      source.addEventListener('change', updateSourceReferenceRequirement);
    }
    if (barangaySearch) {
      barangaySearch.addEventListener('input', () => {
        const query = barangaySearch.value.trim().toLowerCase();
        document.querySelectorAll('[data-barangay-search-value]').forEach((option) => {
          option.hidden = query !== '' && !option.dataset.barangaySearchValue.includes(query);
        });
      });
    }

    document.querySelectorAll('[data-close-create-warning]').forEach((button) => {
      button.addEventListener('click', () => toggleCreateWarningModal(false));
    });
    document.querySelectorAll('[data-close-review-warning]').forEach((button) => {
      button.addEventListener('click', closeReviewWarning);
    });
    if (recentBody) {
      recentBody.addEventListener('click', (event) => {
        const button = event.target.closest('[data-review-warning-id]');
        if (button) {
          openReviewWarning(button.dataset.reviewWarningId);
        }
      });
    }
    if (externalBody) {
      externalBody.addEventListener('click', (event) => {
        const button = event.target.closest('[data-external-advisory-id]');
        if (button) {
          openExternalAdvisory(button.dataset.externalAdvisoryId);
        }
      });
    }
    if (externalFilter) {
      externalFilter.addEventListener('change', () => {
        loadExternalAdvisories(externalFilter.value).catch(() => {
          setText('[data-external-advisory-status]', 'Staged external advisories could not be loaded.');
        });
      });
    }
    document.querySelectorAll('[data-close-external-advisory]').forEach((button) => {
      button.addEventListener('click', closeExternalAdvisory);
    });
    const dismissExternal = document.querySelector('[data-dismiss-external-advisory]');
    const convertExternal = document.querySelector('[data-convert-external-advisory]');
    if (dismissExternal) {
      dismissExternal.addEventListener('click', dismissExternalAdvisory);
    }
    if (convertExternal) {
      convertExternal.addEventListener('click', beginExternalDraftConversion);
    }

    const activateButton = document.querySelector('[data-activate-warning]');
    const cancelButton = document.querySelector('[data-cancel-warning]');
    const editButton = document.querySelector('[data-edit-warning-draft]');
    if (editButton) {
      editButton.addEventListener('click', () => {
        const warning = warningById(state.selectedWarningId);
        if (!warning || warningStoredStatus(warning) !== 'DRAFT' || !securityCapabilities.canEditDraft) {
          return;
        }
        closeReviewWarning();
        toggleCreateWarningModal(true, warning);
      });
    }
    if (activateButton) {
      activateButton.addEventListener('click', () => submitWarningAction('ACTIVATE'));
    }
    if (cancelButton) {
      cancelButton.addEventListener('click', () => submitWarningAction('CANCEL'));
    }

    [createModal, reviewModal, externalModal].forEach((modal) => {
      if (modal) {
        modal.addEventListener('click', (event) => {
          if (event.target === modal) {
            if (modal === createModal) {
              toggleCreateWarningModal(false);
            } else if (modal === reviewModal) {
              closeReviewWarning();
            } else {
              closeExternalAdvisory();
            }
          }
        });
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') {
        return;
      }
      if (createModal && !createModal.hidden) {
        toggleCreateWarningModal(false);
      } else if (reviewModal && !reviewModal.hidden) {
        closeReviewWarning();
      } else if (externalModal && !externalModal.hidden) {
        closeExternalAdvisory();
      }
    });
  }

  function initialize() {
    if (state.initialized) {
      return;
    }

    state.initialized = true;
    void loadInAppReadiness();
    bindWarningWorkflow();
    loadDashboard().catch(handleLoadFailure);
    loadExternalAdvisories().catch(() => {
      setText('[data-external-advisory-status]', 'Staged external advisories could not be loaded.');
    });
    loadPagasaOverview().catch(handlePagasaFailure);
    loadNdrrmcOverview().catch(handleNdrrmcFailure);
  }

  window.CiventralEarlyWarningDashboard = Object.freeze({
    refreshInAppReadiness: loadInAppReadiness,
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
        warningManagementEnabled: securityCapabilities.canCreateWarning
          || securityCapabilities.canEditDraft
          || securityCapabilities.canActivateWarning
          || securityCapabilities.canCancelWarning,
        barangayCount: Array.isArray(state.barangays) ? state.barangays.length : 0,
        mutationInFlight: state.mutationInFlight,
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
