(function () {
  'use strict';

  const config = window.CiventralIncidentConfig || {};
  const security = config.security && typeof config.security === 'object' ? config.security : {};
  const capabilities = security.capabilities && typeof security.capabilities === 'object'
    ? security.capabilities
    : {};
  const state = {
    initialized: false,
    listRequest: 0,
    selectedIncidentId: null,
    selectedIncident: null,
    pendingAction: null,
    mutationInFlight: false,
    toastTimer: null,
    searchTimer: null,
    assignmentReferences: {
      departments: [],
      users: [],
      loaded: false,
      loading: false,
      failed: false
    }
  };

  const actionDefinitions = Object.freeze({
    REVIEW: {
      label: 'Start Review', capability: 'canReview', kind: 'status',
      description: 'Move this submitted report into formal DRRM review.', noteLabel: 'Review note (optional)'
    },
    VERIFY: {
      label: 'Verify Incident', capability: 'canVerify', kind: 'status',
      description: 'Confirm that the reviewed report is a verified incident. This does not create an early warning.', noteLabel: 'Verification note (optional)'
    },
    ASSIGN: {
      label: 'Assign Response', capability: 'canAssign', kind: 'status',
      description: 'Assign the verified incident using stable CIVENTRAL department and/or employee references.', noteLabel: 'Assignment note (optional)'
    },
    DISPATCH_NOTE: {
      label: 'Record Dispatch', capability: 'canUpdateResponse', kind: 'response',
      description: 'Record the first dispatch action and move this assigned incident into active response.', noteLabel: 'Dispatch note'
    },
    RESPONSE_UPDATE: {
      label: 'Add Response Update', capability: 'canUpdateResponse', kind: 'response',
      description: 'Append a plain-text operational update without changing the controlled response state.', noteLabel: 'Response update'
    },
    RESOLVE: {
      label: 'Resolve Incident', capability: 'canResolve', kind: 'status',
      description: 'Mark active response work as resolved. A resolution note is required.', noteLabel: 'Resolution note'
    },
    CLOSE: {
      label: 'Close Incident', capability: 'canClose', kind: 'status',
      description: 'Close an incident only after it has been resolved.', noteLabel: 'Closure note (optional)'
    },
    REJECT: {
      label: 'Reject Report', capability: 'canReject', kind: 'status', tone: 'danger',
      description: 'Reject an unverified report. A reason is required and the report cannot return to review.', noteLabel: 'Rejection reason'
    }
  });

  const statusActions = Object.freeze({
    SUBMITTED: ['REVIEW', 'REJECT'],
    UNDER_REVIEW: ['VERIFY', 'REJECT'],
    VERIFIED: ['ASSIGN'],
    ASSIGNED: ['DISPATCH_NOTE'],
    RESPONDING: ['RESPONSE_UPDATE', 'RESOLVE'],
    RESOLVED: ['CLOSE'],
    CLOSED: [],
    REJECTED: []
  });

  function query(selector, root = document) {
    return root.querySelector(selector);
  }

  function queryAll(selector, root = document) {
    return Array.from(root.querySelectorAll(selector));
  }

  function codeLabel(value) {
    return typeof value === 'string' && value !== ''
      ? value.toLowerCase().split('_').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join(' ')
      : 'Not available';
  }

  function formatDateTime(value) {
    if (typeof value !== 'string' || value.trim() === '') return 'Not available';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Not available';
    return new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  function nullableText(value, fallback = 'Not available') {
    return typeof value === 'string' && value.trim() !== '' ? value : fallback;
  }

  function requireObject(value, message) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(message);
    return value;
  }

  function requireArray(value, message) {
    if (!Array.isArray(value)) throw new Error(message);
    return value;
  }

  function normalizeStatus(value) {
    return typeof value === 'string' ? value.trim().toLowerCase() : '';
  }

  function fetchReferenceList(url) {
    return window.fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    }).then(async (response) => {
      let payload;
      try {
        payload = await response.json();
      } catch (error) {
        throw new Error('Assignment reference data is unavailable.');
      }
      if (!response.ok) {
        throw new Error('Assignment reference data is unavailable.');
      }
      if (Array.isArray(payload)) return payload;
      if (payload && typeof payload === 'object' && Array.isArray(payload.data)) return payload.data;
      if (payload && typeof payload === 'object' && payload.status === 'success' && Array.isArray(payload.data)) return payload.data;
      return [];
    });
  }

  function toSafeInteger(value) {
    const number = Number(value);
    return Number.isInteger(number) ? number : null;
  }

  function departmentDisplayLabel(row) {
    if (!row || typeof row !== 'object') return 'Unassigned department';
    const code = typeof row.department_code === 'string' && row.department_code.trim() !== '' ? row.department_code.trim() : 'N/A';
    const name = typeof row.department_name === 'string' && row.department_name.trim() !== '' ? row.department_name.trim() : 'Department';
    return `${code} — ${name}`;
  }

  function employeeDisplayLabel(row) {
    if (!row || typeof row !== 'object') return 'Employee';
    const fullName = typeof row.full_name === 'string' && row.full_name.trim() !== '' ? row.full_name.trim() : 'Employee';
    const position = row.positions && row.positions.position && typeof row.positions.position.name === 'string'
      ? row.positions.position.name.trim()
      : '';
    if (!position) return fullName;
    return `${fullName} — ${position}`;
  }

  function resolveDepartmentName(reference) {
    if (typeof reference !== 'string' || reference.trim() === '') return 'Unassigned';
    const match = /^DEPARTMENT:(\d+)$/i.exec(reference.trim());
    if (!match) return reference;
    const id = Number(match[1]);
    const row = state.assignmentReferences.departments.find((item) => Number(item.department_id) === id);
    return row ? departmentDisplayLabel(row) : reference;
  }

  function resolveUserName(reference) {
    if (typeof reference !== 'string' || reference.trim() === '') return 'Unassigned';
    const match = /^USER:(\d+)$/i.exec(reference.trim());
    if (!match) return reference;
    const id = Number(match[1]);
    const row = state.assignmentReferences.users.find((item) => Number(item.user_id) === id);
    return row ? employeeDisplayLabel(row) : reference;
  }

  async function fetchJson(url, options = {}) {
    const response = await window.fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json', ...(options.headers || {}) },
      ...options
    });
    let payload;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error('The incident service returned an invalid response.');
    }
    if (!response.ok || !payload || payload.success !== true) {
      throw new Error(payload && typeof payload.message === 'string'
        ? payload.message
        : 'The incident request could not be completed.');
    }
    return payload.data;
  }

  function mutationHeaders() {
    const token = typeof security.csrfToken === 'string' ? security.csrfToken : '';
    if (token === '') throw new Error('Module 3 CSRF protection is unavailable.');
    return {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-Token': token
    };
  }

  function setLoadStatus(message, tone = 'neutral') {
    const element = query('[data-incident-load-status]');
    if (!element) return;
    element.textContent = message;
    element.classList.toggle('text-rose-600', tone === 'error');
    element.classList.toggle('dark:text-rose-400', tone === 'error');
  }

  async function loadSummary() {
    try {
      const summary = requireObject(await fetchJson(config.summaryEndpoint), 'Incident summary data is malformed.');
      ['submitted', 'under_review', 'active_response', 'resolved_today'].forEach((key) => {
        const value = summary[key];
        if (!Number.isInteger(value) || value < 0) throw new Error('Incident summary data is malformed.');
        const element = query(`[data-incident-summary="${key}"]`);
        if (element) element.textContent = String(value);
      });
      setLoadStatus(`Live operational counts loaded. ${Number.isInteger(summary.total) ? summary.total : 0} total incident report(s).`);
      return true;
    } catch (error) {
      setLoadStatus(error instanceof Error ? error.message : 'Unable to load incident summary.', 'error');
      return false;
    }
  }

  function filterParameters() {
    const parameters = new URLSearchParams();
    const values = {
      search: query('[data-filter-search]')?.value.trim() || '',
      status: query('[data-filter-status]')?.value || '',
      type: query('[data-filter-type]')?.value || '',
      severity: query('[data-filter-severity]')?.value || ''
    };
    Object.entries(values).forEach(([key, value]) => {
      if (value !== '') parameters.set(key, value);
    });
    return parameters;
  }

  function tableCell(row, text, className = '') {
    const cell = document.createElement('td');
    cell.className = `px-4 py-3 align-top ${className}`.trim();
    cell.textContent = text;
    row.appendChild(cell);
    return cell;
  }

  function codeBadge(code) {
    const badge = document.createElement('span');
    badge.className = 'incident-code-badge';
    badge.dataset.code = code;
    badge.textContent = codeLabel(code);
    return badge;
  }

  function assignmentText(incident) {
    const userReference = nullableText(incident.assigned_user_reference, '');
    const departmentReference = nullableText(incident.assigned_department_reference, '');
    const userDisplay = userReference ? resolveUserName(userReference) : '';
    const departmentDisplay = departmentReference ? resolveDepartmentName(departmentReference) : '';
    if (userDisplay && departmentDisplay) return `${departmentDisplay} / ${userDisplay}`;
    return userDisplay || departmentDisplay || 'Unassigned';
  }

  function locationText(incident) {
    const barangay = incident.barangay && typeof incident.barangay.name === 'string'
      ? incident.barangay.name
      : '';
    return barangay ? `${barangay} — ${incident.location_description}` : incident.location_description;
  }

  function renderList(incidents) {
    const body = query('[data-incident-table-body]');
    if (!body) return;
    body.replaceChildren();

    if (incidents.length === 0) {
      const row = document.createElement('tr');
      const cell = tableCell(row, '', 'text-center');
      cell.colSpan = 9;
      cell.className = 'px-4 py-14 text-center';
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-clipboard-list text-2xl text-slate-300 dark:text-slate-600';
      const title = document.createElement('p');
      title.className = 'mt-3 text-xs font-black text-slate-600 dark:text-slate-300';
      title.textContent = 'No incident reports have been recorded yet.';
      const note = document.createElement('p');
      note.className = 'mt-1 text-[10px] font-medium text-slate-400';
      note.textContent = 'No production fixtures or fabricated incidents are shown.';
      cell.append(icon, title, note);
      body.appendChild(row);
      return;
    }

    incidents.forEach((incident) => {
      const row = document.createElement('tr');
      row.className = 'transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40';
      tableCell(row, incident.incident_number, 'font-black text-brand-dark dark:text-brand-medium');
      tableCell(row, incident.incident_type.label, 'font-bold text-slate-600 dark:text-slate-300');
      const titleCell = tableCell(row, '', 'max-w-[260px]');
      const title = document.createElement('p');
      title.className = 'font-black text-slate-700 dark:text-slate-200';
      title.textContent = incident.title;
      titleCell.appendChild(title);
      tableCell(row, locationText(incident), 'max-w-[260px] font-medium leading-relaxed text-slate-500 dark:text-slate-400');
      const severityCell = tableCell(row, '');
      severityCell.appendChild(codeBadge(incident.severity.code));
      tableCell(row, formatDateTime(incident.reported_at), 'whitespace-nowrap font-medium text-slate-500 dark:text-slate-400');
      const statusCell = tableCell(row, '');
      statusCell.appendChild(codeBadge(incident.status));
      tableCell(row, assignmentText(incident), 'max-w-[180px] break-words font-medium text-slate-500 dark:text-slate-400');
      const actionCell = tableCell(row, '', 'text-right');
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'incident-secondary-button';
      button.dataset.viewIncident = incident.id;
      button.textContent = 'Review Details';
      actionCell.appendChild(button);
      body.appendChild(row);
    });
  }

  function renderListError(message) {
    const body = query('[data-incident-table-body]');
    if (!body) return;
    body.replaceChildren();
    const row = document.createElement('tr');
    const cell = tableCell(row, message, 'text-center font-bold text-rose-600 dark:text-rose-400');
    cell.colSpan = 9;
    cell.className = 'px-4 py-12 text-center text-xs font-bold text-rose-600 dark:text-rose-400';
    body.appendChild(row);
  }

  async function loadList() {
    const requestNumber = ++state.listRequest;
    const count = query('[data-incident-list-count]');
    if (count) count.textContent = 'Loading matching incident records...';
    try {
      const parameters = filterParameters();
      const endpoint = `${config.listEndpoint}${parameters.size ? `?${parameters.toString()}` : ''}`;
      const data = requireObject(await fetchJson(endpoint), 'Incident list data is malformed.');
      const incidents = requireArray(data.incidents, 'Incident list data is malformed.');
      if (requestNumber !== state.listRequest) return false;
      renderList(incidents);
      if (count) count.textContent = incidents.length === 0
        ? 'No matching incident records.'
        : `Showing ${incidents.length} most recent matching incident report(s).`;
      return true;
    } catch (error) {
      if (requestNumber !== state.listRequest) return false;
      const message = error instanceof Error ? error.message : 'Unable to load incident records.';
      renderListError(message);
      if (count) count.textContent = 'Incident list unavailable.';
      return false;
    }
  }

  function setModalVisible(modal, visible) {
    if (!modal) return;
    modal.hidden = !visible;
    document.body.style.overflow = queryAll('.incident-modal').some((item) => !item.hidden) ? 'hidden' : '';
  }

  function setDetailField(name, value) {
    const element = query(`[data-detail-field="${name}"]`);
    if (element) element.textContent = value;
  }

  function timelineEmpty(container, message) {
    const empty = document.createElement('p');
    empty.className = 'text-[10px] font-medium text-slate-400';
    empty.textContent = message;
    container.appendChild(empty);
  }

  function renderHistory(records) {
    const container = query('[data-detail-history]');
    if (!container) return;
    container.replaceChildren();
    if (records.length === 0) {
      timelineEmpty(container, 'No status history is available.');
      return;
    }
    records.forEach((record) => {
      const item = document.createElement('div');
      item.className = 'incident-timeline-item';
      const heading = document.createElement('strong');
      heading.textContent = record.from_status
        ? `${codeLabel(record.from_status)} → ${codeLabel(record.to_status)}`
        : codeLabel(record.to_status);
      const metadata = document.createElement('span');
      metadata.textContent = `${formatDateTime(record.changed_at)} • ${nullableText(record.changed_by_reference)}`;
      item.append(heading, metadata);
      if (record.notes) {
        const note = document.createElement('p');
        note.textContent = record.notes;
        item.appendChild(note);
      }
      container.appendChild(item);
    });
  }

  function renderResponseLogs(records) {
    const container = query('[data-detail-response-logs]');
    if (!container) return;
    container.replaceChildren();
    if (records.length === 0) {
      timelineEmpty(container, 'No response activity has been recorded yet.');
      return;
    }
    records.forEach((record) => {
      const item = document.createElement('div');
      item.className = 'incident-timeline-item';
      const heading = document.createElement('strong');
      heading.textContent = codeLabel(record.action_type);
      const metadata = document.createElement('span');
      metadata.textContent = `${formatDateTime(record.created_at)} • ${nullableText(record.created_by_reference)}`;
      const note = document.createElement('p');
      note.textContent = nullableText(record.message);
      item.append(heading, metadata, note);
      container.appendChild(item);
    });
  }

  function renderAssignments(records) {
    const container = query('[data-detail-assignments]');
    if (!container) return;
    container.replaceChildren();
    if (records.length === 0) {
      timelineEmpty(container, 'This incident has not been assigned.');
      return;
    }
    records.forEach((record) => {
      const card = document.createElement('div');
      card.className = 'incident-assignment-card';
      const target = document.createElement('strong');
      target.className = 'block text-slate-700 dark:text-slate-200';
      const userDisplay = record.user_reference ? resolveUserName(record.user_reference) : '';
      const departmentDisplay = record.department_reference ? resolveDepartmentName(record.department_reference) : '';
      target.textContent = [departmentDisplay, userDisplay].filter(Boolean).join(' / ');
      const metadata = document.createElement('span');
      metadata.className = 'mt-1 block text-[9px] text-slate-400';
      metadata.textContent = `${formatDateTime(record.assigned_at)} • ${nullableText(record.assigned_by_reference)}`;
      card.append(target, metadata);
      if (record.notes) {
        const note = document.createElement('p');
        note.className = 'mt-2 text-[10px] leading-relaxed text-slate-500 dark:text-slate-400';
        note.textContent = record.notes;
        card.appendChild(note);
      }
      container.appendChild(card);
    });
  }

  function renderActions(status) {
    const container = query('[data-detail-actions]');
    if (!container) return;
    container.replaceChildren();
    (statusActions[status] || []).forEach((actionCode) => {
      const definition = actionDefinitions[actionCode];
      if (!definition || capabilities[definition.capability] !== true) return;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'incident-action-button';
      if (definition.tone) button.dataset.tone = definition.tone;
      button.dataset.incidentAction = actionCode;
      button.textContent = definition.label;
      container.appendChild(button);
    });
  }

  function renderDetails(incident) {
    state.selectedIncident = incident;
    const number = query('[data-detail-number]');
    const title = query('[data-detail-title]');
    const status = query('[data-detail-status]');
    const description = query('[data-detail-description]');
    const loadStatus = query('[data-detail-load-status]');
    if (number) number.textContent = incident.incident_number;
    if (title) title.textContent = incident.title;
    if (status) {
      status.textContent = codeLabel(incident.status);
      status.dataset.code = incident.status;
    }
    if (description) description.textContent = incident.description;
    if (loadStatus) loadStatus.hidden = true;

    setDetailField('type', incident.incident_type.label);
    setDetailField('severity', incident.severity.label);
    setDetailField('reported_at', formatDateTime(incident.reported_at));
    setDetailField('source', codeLabel(incident.source));
    setDetailField('barangay', incident.barangay ? nullableText(incident.barangay.name) : 'Not specified');
    setDetailField('location', incident.location_description);
    setDetailField('coordinates', incident.latitude === null || incident.longitude === null
      ? 'Not provided'
      : `${Number(incident.latitude).toFixed(6)}, ${Number(incident.longitude).toFixed(6)} (WGS84)`);
    setDetailField('assignment', assignmentText(incident));
    setDetailField('verification', codeLabel(incident.verification_status));
    setDetailField('resolved_at', formatDateTime(incident.resolved_at));
    setDetailField('closed_at', formatDateTime(incident.closed_at));
    setDetailField('reporter_type', codeLabel(incident.reporter_type));
    setDetailField('reporter_reference', nullableText(incident.reporter_reference));
    renderHistory(requireArray(incident.status_history, 'Incident history is malformed.'));
    renderResponseLogs(requireArray(incident.response_logs, 'Incident response log is malformed.'));
    renderAssignments(requireArray(incident.assignments, 'Incident assignments are malformed.'));
    renderActions(incident.status);
  }

  async function openDetails(incidentId) {
    state.selectedIncidentId = incidentId;
    state.selectedIncident = null;
    const modal = query('[data-incident-details-modal]');
    const loadStatus = query('[data-detail-load-status]');
    if (loadStatus) {
      loadStatus.hidden = false;
      loadStatus.dataset.tone = '';
      loadStatus.textContent = 'Loading full incident record...';
    }
    setModalVisible(modal, true);
    try {
      const data = requireObject(
        await fetchJson(`${config.detailsEndpoint}?id=${encodeURIComponent(incidentId)}`),
        'Incident details are malformed.'
      );
      if (state.selectedIncidentId !== incidentId) return;
      renderDetails(data);
    } catch (error) {
      if (loadStatus) {
        loadStatus.hidden = false;
        loadStatus.dataset.tone = 'error';
        loadStatus.textContent = error instanceof Error ? error.message : 'Unable to load incident details.';
      }
    }
  }

  function closeDetails() {
    state.selectedIncidentId = null;
    state.selectedIncident = null;
    setModalVisible(query('[data-incident-details-modal]'), false);
  }

  function setAssignmentReferenceStatus(message, tone = 'error') {
    const element = query('[data-assignment-loader-status]');
    if (!element) return;
    element.hidden = false;
    element.dataset.tone = tone;
    element.textContent = message;
  }

  function syncAssignmentSubmitState() {
    const submitButton = query('[data-submit-incident-action]');
    if (!submitButton || !state.pendingAction || state.pendingAction.code !== 'ASSIGN') return;
    const departmentSelect = query('[data-assignment-department-select]');
    const hasNoDepartments = !departmentSelect || departmentSelect.disabled || departmentSelect.options.length <= 1;
    if (state.assignmentReferences.failed || hasNoDepartments) {
      submitButton.disabled = true;
      return;
    }
    submitButton.disabled = false;
  }

  function populateDepartmentSelect() {
    const select = query('[data-assignment-department-select]');
    if (!select) return;
    const activeDepartments = state.assignmentReferences.departments.filter((item) => normalizeStatus(item.status) === 'active');
    select.innerHTML = '<option value="">Select department</option>';
    activeDepartments.forEach((item) => {
      const option = document.createElement('option');
      const departmentId = toSafeInteger(item.department_id);
      option.value = departmentId === null ? '' : String(departmentId);
      option.textContent = departmentDisplayLabel(item);
      select.appendChild(option);
    });
    if (activeDepartments.length === 0) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = 'No active departments available';
      option.disabled = true;
      select.appendChild(option);
    }
    select.disabled = activeDepartments.length === 0;
    select.value = '';
    syncAssignmentSubmitState();
  }

  function updateResponderSelect() {
    const departmentSelect = query('[data-assignment-department-select]');
    const responderSelect = query('[data-assignment-user-select]');
    if (!departmentSelect || !responderSelect) return;
    const selectedDepartmentId = toSafeInteger(departmentSelect.value);
    responderSelect.innerHTML = '<option value="">Select responder</option>';
    if (selectedDepartmentId === null) {
      responderSelect.disabled = true;
      return;
    }
    const activeUsers = state.assignmentReferences.users.filter((item) => {
      const departmentMatch = item && item.positions && item.positions.departments && item.positions.departments.department_id;
      return normalizeStatus(item.status) === 'active' && Number(departmentMatch) === selectedDepartmentId;
    });
    activeUsers.forEach((item) => {
      const option = document.createElement('option');
      const userId = toSafeInteger(item.user_id);
      option.value = userId === null ? '' : String(userId);
      option.textContent = employeeDisplayLabel(item);
      responderSelect.appendChild(option);
    });
    responderSelect.disabled = activeUsers.length === 0;
    if (responderSelect.value && !activeUsers.some((item) => String(item.user_id) === responderSelect.value)) {
      responderSelect.value = '';
    }
    syncAssignmentSubmitState();
  }

  async function loadAssignmentReferences(force = false) {
    if (!force && state.assignmentReferences.loaded) {
      populateDepartmentSelect();
      updateResponderSelect();
      return state.assignmentReferences;
    }
    if (state.assignmentReferences.loading) {
      return state.assignmentReferences;
    }

    const loader = query('[data-assignment-loader-status]');
    const submitButton = query('[data-submit-incident-action]');
    if (loader) {
      loader.hidden = false;
      loader.dataset.tone = 'neutral';
      loader.textContent = 'Loading assignment options...';
    }
    if (submitButton) submitButton.disabled = true;
    state.assignmentReferences.loading = true;
    state.assignmentReferences.failed = false;

    try {
      const [departmentPayload, userPayload] = await Promise.all([
        fetchReferenceList('../../api/employee/departments.php'),
        fetchReferenceList('../../api/employee/users.php')
      ]);
      state.assignmentReferences = {
        departments: Array.isArray(departmentPayload) ? departmentPayload : [],
        users: Array.isArray(userPayload) ? userPayload : [],
        loaded: true,
        loading: false,
        failed: false
      };
      populateDepartmentSelect();
      updateResponderSelect();
      if (loader) loader.hidden = true;
      syncAssignmentSubmitState();
      return state.assignmentReferences;
    } catch (error) {
      state.assignmentReferences = {
        departments: [],
        users: [],
        loaded: false,
        loading: false,
        failed: true
      };
      populateDepartmentSelect();
      updateResponderSelect();
      const message = 'Assignment reference data could not be loaded. Department and responder options are unavailable.';
      setAssignmentReferenceStatus(message, 'error');
      if (submitButton) submitButton.disabled = true;
      return state.assignmentReferences;
    }
  }

  function openAction(actionCode) {
    const definition = actionDefinitions[actionCode];
    if (!definition || !state.selectedIncident || capabilities[definition.capability] !== true) return;
    state.pendingAction = { code: actionCode, definition };
    const form = query('[data-incident-action-form]');
    if (form) form.reset();
    const title = query('[data-action-title]');
    const description = query('[data-action-description]');
    const noteLabel = query('[data-action-note-label]');
    const note = query('[data-action-note]');
    const assignmentFields = query('[data-assignment-fields]');
    const responseTypeField = query('[data-response-type-field]');
    const responseType = query('[data-response-action-readonly]');
    const status = query('[data-action-status]');
    if (title) title.textContent = definition.label;
    if (description) description.textContent = definition.description;
    if (noteLabel) noteLabel.textContent = definition.noteLabel;
    if (note) {
      note.required = definition.kind === 'response' || ['RESOLVE', 'REJECT'].includes(actionCode);
      note.maxLength = definition.kind === 'response' ? 5000 : 2000;
    }
    if (assignmentFields) assignmentFields.hidden = actionCode !== 'ASSIGN';
    if (responseTypeField) responseTypeField.hidden = definition.kind !== 'response';
    if (responseType) {
      responseType.textContent = actionCode === 'DISPATCH_NOTE' ? 'Dispatch Note' : 'Response Update';
    }
    if (status) status.hidden = true;
    if (actionCode === 'ASSIGN') {
      void loadAssignmentReferences();
      const responderSelect = query('[data-assignment-user-select]');
      if (responderSelect) responderSelect.disabled = true;
      syncAssignmentSubmitState();
    }
    setModalVisible(query('[data-incident-action-modal]'), true);
    window.setTimeout(() => note?.focus(), 20);
  }

  function closeAction() {
    if (state.mutationInFlight) return;
    state.pendingAction = null;
    setModalVisible(query('[data-incident-action-modal]'), false);
  }

  function setActionStatus(message, tone = 'error') {
    const element = query('[data-action-status]');
    if (!element) return;
    element.hidden = false;
    element.dataset.tone = tone;
    element.textContent = message;
  }

  function showToast(message) {
    const toast = query('[data-incident-toast]');
    if (!toast) return;
    window.clearTimeout(state.toastTimer);
    toast.textContent = message;
    toast.hidden = false;
    state.toastTimer = window.setTimeout(() => { toast.hidden = true; }, 4200);
  }

  async function submitAction(event) {
    event.preventDefault();
    if (state.mutationInFlight || !state.pendingAction || !state.selectedIncident) return;

    const { code, definition } = state.pendingAction;
    const note = query('[data-action-note]')?.value.trim() || '';
    const departmentSelect = query('[data-assignment-department-select]');
    const userSelect = query('[data-assignment-user-select]');
    const departmentId = departmentSelect ? toSafeInteger(departmentSelect.value) : null;
    const userId = userSelect ? toSafeInteger(userSelect.value) : null;
    const departmentReference = departmentId !== null ? `DEPARTMENT:${departmentId}` : null;
    const userReference = userId !== null ? `USER:${userId}` : null;

    if (code === 'ASSIGN' && departmentReference === null && userReference === null) {
      setActionStatus('Select a department or responder for this assignment.');
      return;
    }
    if (code === 'ASSIGN' && departmentReference === null && userReference !== null) {
      setActionStatus('Choose a department before assigning a responder.');
      return;
    }
    if ((definition.kind === 'response' || ['RESOLVE', 'REJECT'].includes(code)) && note === '') {
      setActionStatus('This action requires a plain-text note.');
      return;
    }
    if (code === 'ASSIGN' && state.assignmentReferences.failed) {
      setActionStatus('Assignment reference data is unavailable. Please reload the incident screen and try again.');
      return;
    }

    const body = definition.kind === 'response'
      ? { incident_id: state.selectedIncident.id, action_type: code, message: note }
      : {
          incident_id: state.selectedIncident.id,
          action: code,
          notes: note === '' ? null : note,
          assigned_department_reference: code === 'ASSIGN' ? departmentReference : null,
          assigned_user_reference: code === 'ASSIGN' ? userReference : null
        };
    const endpoint = definition.kind === 'response' ? config.responseEndpoint : config.statusEndpoint;
    const submit = query('[data-submit-incident-action]');
    state.mutationInFlight = true;
    if (submit) submit.disabled = true;
    setActionStatus('Recording the controlled incident action...', 'neutral');

    try {
      await fetchJson(endpoint, {
        method: 'POST',
        headers: mutationHeaders(),
        body: JSON.stringify(body)
      });
      state.mutationInFlight = false;
      if (submit) submit.disabled = false;
      setModalVisible(query('[data-incident-action-modal]'), false);
      state.pendingAction = null;
      showToast(`${definition.label} was recorded successfully.`);
      await Promise.all([loadSummary(), loadList()]);
      if (state.selectedIncidentId) await openDetails(state.selectedIncidentId);
    } catch (error) {
      state.mutationInFlight = false;
      if (submit) submit.disabled = false;
      setActionStatus(error instanceof Error ? error.message : 'Unable to record the incident action.');
    }
  }

  async function refreshAll() {
    const button = query('[data-refresh-incidents]');
    if (button) button.disabled = true;
    await Promise.all([loadSummary(), loadList()]);
    if (button) button.disabled = false;
  }

  function initialize() {
    if (state.initialized) return;
    state.initialized = true;
    query('[data-incident-filters]')?.addEventListener('submit', (event) => event.preventDefault());
    queryAll('[data-filter-status], [data-filter-type], [data-filter-severity]').forEach((element) => {
      element.addEventListener('change', () => { void loadList(); });
    });
    query('[data-filter-search]')?.addEventListener('input', () => {
      window.clearTimeout(state.searchTimer);
      state.searchTimer = window.setTimeout(() => { void loadList(); }, 350);
    });
    query('[data-refresh-incidents]')?.addEventListener('click', () => { void refreshAll(); });
    query('[data-incident-table-body]')?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-view-incident]');
      if (button) void openDetails(button.dataset.viewIncident);
    });
    queryAll('[data-close-incident-details]').forEach((element) => element.addEventListener('click', closeDetails));
    queryAll('[data-close-incident-action]').forEach((element) => element.addEventListener('click', closeAction));
    query('[data-detail-actions]')?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-incident-action]');
      if (button) openAction(button.dataset.incidentAction);
    });
    query('[data-assignment-department-select]')?.addEventListener('change', updateResponderSelect);
    query('[data-assignment-user-select]')?.addEventListener('change', () => {
      const responder = query('[data-assignment-user-select]');
      if (responder && responder.value !== '' && query('[data-assignment-department-select]')?.value === '') {
        responder.value = '';
      }
    });
    query('[data-incident-action-form]')?.addEventListener('submit', (event) => { void submitAction(event); });
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      if (!query('[data-incident-action-modal]')?.hidden) closeAction();
      else if (!query('[data-incident-details-modal]')?.hidden) closeDetails();
    });
    void refreshAll();
  }

  window.CiventralIncidentModule = Object.freeze({
    refresh: refreshAll,
    diagnostics: function () {
      return Object.freeze({
        initialized: state.initialized,
        selectedIncidentId: state.selectedIncidentId,
        mutationInFlight: state.mutationInFlight,
        listRequest: state.listRequest
      });
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
