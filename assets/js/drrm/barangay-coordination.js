document.addEventListener('DOMContentLoaded', () => {
  const config = window.CiventralBarangayCoordinationConfig || {};
  const endpoint = config.endpoint || 'api/drrm/barangay-coordination.php';
  const csrfToken = config.csrfToken || '';
  const canCreate = Boolean(config.canCreate);

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
    const ids = Array.from(document.querySelectorAll('select[name="barangay_id"]'));
    const rows = Array.isArray(barangays) ? barangays : [];
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
      body.innerHTML = '<tr><td colspan="7" class="px-2 py-3 text-slate-500">No assistance requests.</td></tr>';
      return;
    }
    body.innerHTML = rows.map((row) => buildTableRow({
      barangay: row.barangay || row.barangay_id,
      request_category: row.request_category,
      priority: row.priority,
      description: row.description,
      status: row.status,
      requested_at: row.requested_at,
      requested_by_reference: row.requested_by_reference,
    }, 'request')).join('');
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

  function renderFromApi(payload) {
    const data = payload && payload.data ? payload.data : payload;
    if (!data) return;
    const summary = data.summary || {};
    renderSummary(summary);
    const barangays = data.barangays || [];
    fillBarangays(barangays);
    const map = new Map();
    barangays.forEach((b) => map.set(b.barangay_id, b.name));

    const situations = Array.isArray(data.current_situations) ? data.current_situations : [];
    const enrichedSituations = situations.map((row) => ({...row, barangay: map.get(row.barangay_id) || row.barangay_id}));
    renderCurrentSituations(enrichedSituations);

    const requests = Array.isArray(data.assistance_requests) ? data.assistance_requests : [];
    const enrichedRequests = requests.map((row) => ({...row, barangay: map.get(row.barangay_id) || row.barangay_id}));
    renderRequests(enrichedRequests);

    const history = Array.isArray(data.situation_history) ? data.situation_history : [];
    const enrichedHistory = history.map((row) => ({...row, barangay: map.get(row.barangay_id) || row.barangay_id}));
    renderHistory(enrichedHistory);
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

  const refresh = document.getElementById('refreshCoordination');
  if (refresh) refresh.addEventListener('click', fetchData);

  fetchData();
});
