document.addEventListener('DOMContentLoaded', () => {
  const config = window.CiventralBarangayCoordinationConfig || {};
  const endpoint = config.endpoint || 'api/drrm/barangay-coordination.php';
  const csrfToken = config.csrfToken || '';
  const displayName = config.displayName || 'Authenticated user';
  const canCreate = Boolean(config.canCreate);
  const canEdit = Boolean(config.canEdit);

  const state = {
    barangays: [],
    assignmentBarangays: []
  };

  function buildTableRow(data, type) {
    return type === 'request' ? `
      <tr><td class="px-2 py-3 font-bold text-slate-700">${data.barangay || 'Unknown'}</td>
      <td class="px-2 py-3 text-slate-600">${data.request_category || ''}</td>
      <td class="px-2 py-3"><span class="rounded-full px-2 py-1 text-[9px] font-black uppercase ${badgeClass(data.priority || 'NORMAL')}">${data.priority || 'NORMAL'}</span></td>
      <td class="px-2 py-3 text-slate-600">${escapeHtml(data.description || '')}</td>
      <td class="px-2 py-3"><span class="rounded-full px-2 py-1 text-[9px] font-black uppercase ${badgeClass(data.status || 'PENDING')}">${data.status || 'PENDING'}</span></td>
      <td class="px-2 py-3 text-slate-500">${formatDate(data.requested_at || data.created_at)}</td>
      <td class="px-2 py-3 text-slate-500">${escapeHtml(data.requested_by_reference || '')}</td></tr>
    ` : `
      <tr><td class="px-2 py-3 font-bold text-slate-700">${data.barangay || 'Unknown'}</td>
      <td class="px-2 py-3 text-slate-600">${formatDate(data.reported_at)}</td>
      <td class="px-2 py-3"><span class="rounded-full px-2 py-1 text-[9px] font-black uppercase ${badgeClass(data.situation_level || 'NORMAL')}">${data.situation_level || ''}</span></td>
      <td class="px-2 py-3 text-slate-600">${data.evacuees ?? 0}</td>
      <td class="px-2 py-3 text-slate-600">${data.access_condition || ''}</td>
      <td class="px-2 py-3 text-slate-500">${escapeHtml(data.situation_summary || '')}</td>
      <td class="px-2 py-3 text-slate-500">${escapeHtml(data.reported_by_reference || '')}</td></tr>
    `;
  }

  function badgeClass(value) {
    if (['URGENT', 'HIGH', 'CRITICAL', 'BLOCKED'].includes(value)) return 'border bg-rose-50 text-rose-700 border-rose-200';
    if (['NORMAL', 'ACCESSIBLE', 'RELIEF_GOODS', 'MONITORING'].includes(value)) return 'border bg-emerald-50 text-emerald-700 border-emerald-200';
    return 'border bg-sky-50 text-sky-700 border-sky-200';
  }

  function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
  }

  function escapeHtml(value) {
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function fillBarangays(barangays) {
    const ids = Array.from(document.querySelectorAll('select[name="barangay_id"]:not([data-assignment-barangay])'));
    const rows = Array.isArray(barangays) ? barangays : [];
    state.barangays = rows;

    for (const select of ids) {
      const selected = select.getAttribute('data-selected') || '';
      select.disabled = false;
      select.innerHTML = '';

      if (!rows.length) {
        const option = document.createElement('option');
        option.value = '';
        option.disabled = true;
        option.selected = true;
        option.textContent = 'No barangays available';
        select.appendChild(option);
        select.disabled = true;
        continue;
      }

      if (rows.length === 1) {
        const row = rows[0];
        const option = document.createElement('option');
        option.value = row.barangay_id;
        option.textContent = row.name || row.barangay_code || 'Barangay';
        option.selected = true;
        select.appendChild(option);
        select.disabled = true;
        continue;
      }

      const defaultOption = document.createElement('option');
      defaultOption.value = '';
      defaultOption.textContent = 'Select barangay';
      select.appendChild(defaultOption);

      rows.forEach((row) => {
        const option = document.createElement('option');
        option.value = row.barangay_id;
        option.textContent = row.name || row.barangay_code || 'Barangay';
        if (selected === row.barangay_id) option.selected = true;
        select.appendChild(option);
      });
    }
  }

  function renderSummary(summary) {
    for (const key of Object.keys(summary)) {
      const node = document.getElementById('summary-' + key);
      if (node) node.textContent = summary[key] ?? 0;
    }
  }

  function renderCurrentSituations(rows) {
    const body = document.getElementById('currentSituationsBody');
    if (!body) return;
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="7" class="px-2 py-3 text-slate-500">No current barangay situation reports.</td></tr>';
      return;
    }
    body.innerHTML = rows.map((row) => `
      <tr>
        <td class="px-2 py-3 font-black text-slate-700">${escapeHtml(row.barangay || row.barangay_id)}</td>
        <td class="px-2 py-3"><span class="rounded-full px-2 py-1 text-[9px] font-black uppercase ${badgeClass(row.situation_level || 'NORMAL')}">${escapeHtml(row.situation_level || '')}</span></td>
        <td class="px-2 py-3 text-slate-600">${row.affected_households ?? 0}</td>
        <td class="px-2 py-3 text-slate-600">${row.evacuees ?? 0}</td>
        <td class="px-2 py-3 text-slate-600">${escapeHtml(row.access_condition || '')}</td>
        <td class="px-2 py-3 text-slate-500">${formatDate(row.reported_at)}</td>
        <td class="px-2 py-3 text-slate-500">${escapeHtml(row.reported_by_reference || '')}</td>
      </tr>
    `).join('');
  }

  function renderRequests(rows) {
    const body = document.getElementById('assistanceRequestsBody');
    if (!body) return;
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="' + (canEdit ? 8 : 7) + '" class="px-2 py-3 text-slate-500">No assistance requests.</td></tr>';
      return;
    }

    body.innerHTML = rows.map((row) => {
      const actionCells = canEdit ? renderRequestActions(row) : '';
      const match = buildTableRow({
        barangay: row.barangay || row.barangay_id,
        request_category: row.request_category,
        priority: row.priority,
        description: row.description,
        status: row.status,
        requested_at: row.requested_at,
        requested_by_reference: row.requested_by_reference,
      }, 'request');
      return match.replace('</tr>', actionCells + '</tr>');
    }).join('');
  }

  function renderRequestActions(row) {
    const status = String(row.status || 'PENDING').toUpperCase();
    const requestId = row.id || '';
    let buttons = '';
    const common = 'class="rounded-lg border border-slate-200 px-2 py-1 text-[9px] font-black uppercase tracking-wide text-slate-700 hover:bg-slate-50"';
    if (status === 'PENDING') {
      buttons += `<button type="button" data-action="transition" data-transition="ACKNOWLEDGED" data-request-id="${escapeHtml(requestId)}" data-expected="${escapeHtml(status)}" data-status="ACKNOWLEDGED" ${common}>Acknowledge</button> <button type="button" data-action="transition" data-transition="CANCELLED" data-request-id="${escapeHtml(requestId)}" data-expected="${escapeHtml(status)}" data-status="CANCELLED" ${common}>Cancel</button>`;
    } else if (status === 'ACKNOWLEDGED') {
      buttons += `<button type="button" data-action="transition" data-transition="IN_PROGRESS" data-request-id="${escapeHtml(requestId)}" data-expected="${escapeHtml(status)}" data-status="IN_PROGRESS" ${common}>Start</button> <button type="button" data-action="transition" data-transition="CANCELLED" data-request-id="${escapeHtml(requestId)}" data-expected="${escapeHtml(status)}" data-status="CANCELLED" ${common}>Cancel</button>`;
    } else if (status === 'IN_PROGRESS') {
      buttons += `<button type="button" data-action="transition" data-transition="COMPLETED" data-request-id="${escapeHtml(requestId)}" data-expected="${escapeHtml(status)}" data-status="COMPLETED" ${common}>Complete</button> <button type="button" data-action="transition" data-transition="CANCELLED" data-request-id="${escapeHtml(requestId)}" data-expected="${escapeHtml(status)}" data-status="CANCELLED" ${common}>Cancel</button>`;
    }
    return `<td class="px-2 py-3 text-slate-500">${buttons}</td>`;
  }

  function renderHistory(rows) {
    const body = document.getElementById('historyBody');
    if (!body) return;
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="7" class="px-2 py-3 text-slate-500">No historical situation reports.</td></tr>';
      return;
    }
    body.innerHTML = rows.map((row) => buildTableRow({
      barangay: row.barangay || row.barangay_id,
      reported_at: row.reported_at,
      situation_level: row.situation_level,
      evacuees: row.evacuees,
      access_condition: row.access_condition,
      situation_summary: row.situation_summary,
      reported_by_reference: row.reported_by_reference,
    }, 'history')).join('');
  }

  function renderAssistanceRequestHistory(rows, requests = []) {
    const body = document.getElementById('assistanceRequestHistoryBody');
    if (!body) return;
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="7" class="px-2 py-3 text-slate-500">No assistance response history.</td></tr>';
      return;
    }

    const lookup = new Map((requests || []).map((row) => [String(row.id), row]));
    body.innerHTML = rows.map((row) => {
      const request = lookup.get(String(row.request_id)) || {};
      const barangay = request.barangay || request.barangay_id || 'Unknown Barangay';
      const category = request.request_category || 'ASSISTANCE_REQUEST';
      const fromStatus = row.from_status || 'UNKNOWN';
      const toStatus = row.to_status || 'UNKNOWN';
      return `<tr>
        <td class="px-2 py-3 font-black text-slate-700">${escapeHtml(barangay)}</td>
        <td class="px-2 py-3 text-slate-800">${escapeHtml(category)}<br><span class="text-[9px] text-slate-500">${escapeHtml(String(row.request_id || ''))}</span></td>
        <td class="px-2 py-3 text-slate-600">${escapeHtml(fromStatus)}</td>
        <td class="px-2 py-3 text-slate-600">${escapeHtml(toStatus)}</td>
        <td class="px-2 py-3 text-slate-500">${escapeHtml(row.response_note || '')}</td>
        <td class="px-2 py-3 text-slate-500">${escapeHtml(row.handled_by_reference || '')}</td>
        <td class="px-2 py-3 text-slate-500">${formatDate(row.created_at)}</td>
      </tr>`;
    }).join('');
  }

  function renderFromApi(payload) {
    const data = payload && payload.data ? payload.data : payload;
    if (!data) return;
    const summary = data.summary || {};
    renderSummary(summary);
    const barangays = data.barangays || [];
    state.barangays = barangays;
    window.__coordinationBarangays = barangays;
    fillBarangays(state.barangays);
    const map = new Map();
    barangays.forEach((b) => map.set(b.barangay_id, b.name));

    const situations = Array.isArray(data.current_situations) ? data.current_situations : [];
    const enrichedSituations = situations.map((row) => ({...row, barangay: map.get(row.barangay_id) || row.barangay_id}));
    renderCurrentSituations(enrichedSituations);

    const requests = Array.isArray(data.assistance_requests) ? data.assistance_requests : [];
    const enrichedRequests = requests.map((row) => ({...row, barangay: map.get(row.barangay_id) || row.barangay_id}));
    window.__coordinationRequests = enrichedRequests;
    renderRequests(enrichedRequests);

    const history = Array.isArray(data.situation_history) ? data.situation_history : [];
    const enrichedHistory = history.map((row) => ({...row, barangay: map.get(row.barangay_id) || row.barangay_id}));
    renderHistory(enrichedHistory);

    const requestHistory = Array.isArray(data.assistance_request_history) ? data.assistance_request_history : [];
    renderAssistanceRequestHistory(requestHistory, enrichedRequests);
  }

  function showLoadError(message) {
    const status = document.getElementById('statusReportFeedback') || null;
    if (status) {
      status.classList.remove('hidden');
      status.textContent = message || 'Unable to load coordination data.';
    }
  }

  function fetchData() {
    fetch(endpoint, {headers: {'Accept': 'application/json'}})
      .then((response) => {
        if (!response.ok) {
          throw new Error('API status ' + response.status);
        }
        return response.json();
      })
      .then((payload) => {
        if (!payload.success) {
          showLoadError(payload.message || 'Unable to load coordination data.');
          return;
        }
        renderFromApi(payload);
      })
      .catch(() => {
        showLoadError('Unable to load coordination data.');
      });
  }

  function sendJson(action, payload, feedbackEl, buttonEl, label) {
    if (!buttonEl || !feedbackEl) return;
    buttonEl.disabled = true;
    feedbackEl.classList.remove('hidden');
    feedbackEl.textContent = label;
    fetch(endpoint, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken},
      body: JSON.stringify({action, ...payload})
    }).then((response) => response.json()).then((payload) => {
      buttonEl.disabled = false;
      if (!payload.success) {
        feedbackEl.textContent = payload.message || 'Unable to complete the request.';
        return;
      }
      feedbackEl.textContent = 'Saved successfully.';
      fetchData();
      document.getElementById('statusReportForm')?.reset();
      document.getElementById('assistanceRequestForm')?.reset();
    }).catch(() => {
      buttonEl.disabled = false;
      feedbackEl.textContent = 'Request could not be completed.';
    });
  }

  function openTransitionModal(row, expectedStatus, targetStatus) {
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40';
    const requiredNote = targetStatus === 'COMPLETED' || targetStatus === 'CANCELLED';
    modal.innerHTML = `
      <div class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-black uppercase tracking-wide text-slate-800">Assistance Request Response</h3>
          <button type="button" id="closeTransitionModal" class="rounded-lg border border-slate-200 px-2 py-1 text-xs font-black text-slate-600">×</button>
        </div>
        <div class="mt-4 grid gap-3 text-[11px]">
          <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2"><span class="font-black text-slate-500">Barangay:</span> <span class="text-slate-800">${escapeHtml(row.barangay || row.barangay_id || 'Unknown')}</span></div>
          <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2"><span class="font-black text-slate-500">Category:</span> <span class="text-slate-800">${escapeHtml(row.request_category || '')}</span></div>
          <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2"><span class="font-black text-slate-500">Current Status:</span> <span class="text-slate-800">${escapeHtml(expectedStatus)}</span></div>
          <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2"><span class="font-black text-slate-500">Target Status:</span> <span class="text-slate-800">${escapeHtml(targetStatus)}</span></div>
          <label class="block text-[10px] font-black uppercase tracking-wide text-slate-500">Response Note
            <textarea id="transitionResponseNote" maxlength="1000" rows="4" class="mt-2 w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" ${requiredNote ? 'required' : ''} placeholder="${requiredNote ? 'Required response note' : 'Optional response note'}"></textarea>
          </label>
          <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-[10px] font-black text-slate-600">
            <span class="text-slate-500">Handling as:</span> <span class="text-slate-800">${escapeHtml(displayName)}</span>
          </div>
          <div class="flex items-center justify-end gap-2">
            <button type="button" id="cancelTransitionModal" class="rounded-lg border border-slate-200 px-3 py-2 text-[10px] font-black uppercase tracking-wide text-slate-600">Cancel</button>
            <button type="button" id="confirmTransition" class="rounded-lg bg-slate-900 px-3 py-2 text-[10px] font-black uppercase tracking-wide text-white">Confirm Update</button>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    const close = () => modal.remove();
    modal.querySelector('#closeTransitionModal')?.addEventListener('click', close);
    modal.querySelector('#cancelTransitionModal')?.addEventListener('click', close);

    modal.querySelector('#confirmTransition')?.addEventListener('click', () => {
      const note = modal.querySelector('#transitionResponseNote')?.value || '';
      if (requiredNote && note.trim() === '') {
        modal.querySelector('#transitionResponseNote')?.focus();
        return;
      }
      const confirmButton = modal.querySelector('#confirmTransition');
      if (confirmButton) {
        confirmButton.disabled = true;
        confirmButton.textContent = 'UPDATING...';
      }
      const payload = {
        request_id: row.id,
        expected_status: expectedStatus,
        target_status: targetStatus,
        response_note: note.trim()
      };

      fetch(endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify({action: 'transition_assistance_request', ...payload})
      }).then((response) => response.json()).then((payload) => {
        if (!payload.success) {
          if (confirmButton) {
            confirmButton.disabled = false;
            confirmButton.textContent = 'Confirm Update';
          }
          showLoadError(payload.message || 'Unable to update assistance request.');
          return;
        }
        close();
        fetchData();
      }).catch(() => {
        if (confirmButton) {
          confirmButton.disabled = false;
          confirmButton.textContent = 'Confirm Update';
        }
        showLoadError('Request could not be completed.');
      });
    });
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLButtonElement)) return;
    if (target.getAttribute('data-action') !== 'transition') return;
    const rowId = target.getAttribute('data-request-id');
    const expectedStatus = target.getAttribute('data-expected');
    const targetStatus = target.getAttribute('data-status');
    if (!rowId || !expectedStatus || !targetStatus) return;
    const request = (Array.isArray(window.__coordinationRequests) ? window.__coordinationRequests : []).find((r) => String(r.id) === String(rowId));
    if (!request) return;
    openTransitionModal(request, expectedStatus, targetStatus);
  });

  if (canCreate) {
    const statusForm = document.getElementById('statusReportForm');
    const statusFeedback = document.getElementById('statusReportFeedback');
    const statusButton = document.getElementById('submitStatusReport');
    if (statusForm && statusButton && statusFeedback) {
      statusForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const payload = Object.fromEntries(new FormData(statusForm).entries());
        sendJson('create_situation_report', payload, statusFeedback, statusButton, 'Submitting report...');
      });
    }

    const requestForm = document.getElementById('assistanceRequestForm');
    const requestFeedback = document.getElementById('assistanceRequestFeedback');
    const requestButton = document.getElementById('submitAssistanceRequest');
    if (requestForm && requestButton && requestFeedback) {
      requestForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const payload = Object.fromEntries(new FormData(requestForm).entries());
        sendJson('create_assistance_request', payload, requestFeedback, requestButton, 'Requesting assistance...');
      });
    }
  }

  function wireAssignmentAdmin() {
    if (!canEdit) return;
    const adminSection = document.querySelector('[data-assignment-current]')?.closest('section');
    if (!adminSection) return;

    const userReferenceInput = adminSection.querySelector('input[name="user_reference"]');
    const barangaySelect = adminSection.querySelector('[data-assignment-barangay]');
    const currentAssignment = adminSection.querySelector('[data-assignment-current]');
    const setButton = adminSection.querySelector('[data-assignment-set]');
    const deactivateButton = adminSection.querySelector('[data-assignment-deactivate]');
    const feedback = adminSection.querySelector('[data-assignment-feedback]');

    if (!userReferenceInput || !barangaySelect || !currentAssignment || !setButton || !deactivateButton || !feedback) return;

    function showAssignmentFeedback(message) {
      feedback.textContent = message || '';
      feedback.classList.remove('hidden');
    }

    function hideAssignmentFeedback() {
      feedback.textContent = '';
      feedback.classList.add('hidden');
    }

    function barangayNameLookup(id) {
      const rows = Array.isArray(state.assignmentBarangays) ? state.assignmentBarangays : [];
      const found = rows.find((row) => String(row.barangay_id) === String(id));
      return found ? (found.name || found.barangay_code || 'Barangay') : 'Unknown Barangay';
    }

    function loadCatalog() {
      fetch(endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({action: 'get_assignment_barangays'})
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload.success) {
            state.assignmentBarangays = [];
            window.__coordinationAssignmentBarangays = [];
            barangaySelect.innerHTML = '';
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No barangays available';
            option.disabled = true;
            option.selected = true;
            barangaySelect.appendChild(option);
            showAssignmentFeedback(payload.message || 'Unable to load assignment catalog.');
            return;
          }
          const rows = Array.isArray(payload.data) ? payload.data : [];
          state.assignmentBarangays = rows;
          window.__coordinationAssignmentBarangays = rows;
          barangaySelect.innerHTML = '';
          if (!rows.length) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No barangays available';
            option.disabled = true;
            option.selected = true;
            barangaySelect.appendChild(option);
            hideAssignmentFeedback();
            return;
          }
          const defaultOption = document.createElement('option');
          defaultOption.value = '';
          defaultOption.textContent = 'Select barangay';
          barangaySelect.appendChild(defaultOption);
          rows.forEach((row) => {
            const option = document.createElement('option');
            option.value = row.barangay_id;
            option.textContent = row.name || row.barangay_code || 'Barangay';
            barangaySelect.appendChild(option);
          });
          hideAssignmentFeedback();
        })
        .catch(() => {
          state.assignmentBarangays = [];
          window.__coordinationAssignmentBarangays = [];
          barangaySelect.innerHTML = '';
          const option = document.createElement('option');
          option.value = '';
          option.textContent = 'No barangays available';
          option.disabled = true;
          option.selected = true;
          barangaySelect.appendChild(option);
          showAssignmentFeedback('Unable to load assignment catalog.');
        });
    }

    function loadCurrentAssignment(reference) {
      const target = String(reference || '').trim();
      currentAssignment.textContent = 'None';

      if (target === '') {
        hideAssignmentFeedback();
        return;
      }

      fetch(endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify({action: 'get_barangay_assignment', user_reference: target})
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload.success) {
            currentAssignment.textContent = 'None';
            showAssignmentFeedback(payload.message || 'Unable to load current assignment.');
            return;
          }
          const rows = Array.isArray(payload.data) ? payload.data : [];
          const active = rows.find((row) => Boolean(row.is_active) && String(row.user_reference) === target) || rows.find((row) => String(row.user_reference) === target);
          if (!active || !active.barangay_id) {
            currentAssignment.textContent = 'None';
            hideAssignmentFeedback();
            return;
          }
          currentAssignment.textContent = barangayNameLookup(active.barangay_id);
          hideAssignmentFeedback();
        })
        .catch(() => {
          currentAssignment.textContent = 'None';
          showAssignmentFeedback('Unable to load current assignment.');
        });
    }

    loadCatalog();

    userReferenceInput.addEventListener('change', () => loadCurrentAssignment(userReferenceInput.value));
    userReferenceInput.addEventListener('input', () => {
      const value = String(userReferenceInput.value || '').trim();
      if (value === '') {
        currentAssignment.textContent = 'None';
        hideAssignmentFeedback();
      }
    });

    setButton.addEventListener('click', () => {
      const targetReference = String(userReferenceInput.value || '').trim();
      const selectedBarangay = String(barangaySelect.value || '').trim();
      if (targetReference === '') {
        showAssignmentFeedback('User reference is required.');
        return;
      }
      if (selectedBarangay === '') {
        showAssignmentFeedback('Barangay is required.');
        return;
      }
      fetch(endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify({action: 'set_barangay_assignment', user_reference: targetReference, barangay_id: selectedBarangay})
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload.success) {
            showAssignmentFeedback(payload.message || 'Unable to assign barangay.');
            return;
          }
          showAssignmentFeedback('Assignment saved.');
          loadCurrentAssignment(targetReference);
        })
        .catch(() => {
          showAssignmentFeedback('Unable to assign barangay.');
        });
    });

    deactivateButton.addEventListener('click', () => {
      const targetReference = String(userReferenceInput.value || '').trim();
      if (targetReference === '') {
        showAssignmentFeedback('User reference is required.');
        return;
      }
      fetch(endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken},
        body: JSON.stringify({action: 'deactivate_barangay_assignment', user_reference: targetReference})
      })
        .then((response) => response.json())
        .then((payload) => {
          if (!payload.success) {
            showAssignmentFeedback(payload.message || 'Unable to deactivate assignment.');
            return;
          }
          showAssignmentFeedback('Assignment deactivated.');
          currentAssignment.textContent = 'None';
          loadCurrentAssignment(targetReference);
        })
        .catch(() => {
          showAssignmentFeedback('Unable to deactivate assignment.');
        });
    });
  }

  wireAssignmentAdmin();

  const refresh = document.getElementById('refreshCoordination');
  if (refresh) refresh.addEventListener('click', fetchData);

  fetchData();
});
