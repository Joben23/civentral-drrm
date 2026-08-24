// RESOURCE MANAGEMENT API

var systemResources = [];
var systemModulesList = [];
var systemActionsList = [];
var currentUserScope = null;
var archiveTargetResourceId = null;

// FETCH SYSTEM ACTIONS FROM DATABASE API
async function fetchSystemActionsList() {
  try {
    const response = await fetch('../../api/employee/actions.php');
    const result = await response.json();
    if (result.status === 'success' && Array.isArray(result.data) && result.data.length > 0) {
      systemActionsList = result.data.filter(a => a.status === 'Active' || !a.status).map(a => ({
        id: a.action_id,
        name: (a.action_name || '').toUpperCase().trim(),
        desc: a.description || ''
      }));
    }
  } catch (err) {
    console.warn('Could not fetch actions list from API, using defaults:', err);
  }

  // Fallback defaults if API returned empty
  if (systemActionsList.length === 0) {
    systemActionsList = [
      { id: 1, name: 'VIEW', desc: 'View resource data' },
      { id: 2, name: 'CREATE', desc: 'Create new records' },
      { id: 3, name: 'EDIT', desc: 'Modify existing records' },
      { id: 4, name: 'DELETE', desc: 'Remove records' },
      { id: 5, name: 'EXPORT', desc: 'Export report data' },
      { id: 6, name: 'APPROVE', desc: 'Approve submissions' },
      { id: 7, name: 'REJECT', desc: 'Reject submissions' },
      { id: 8, name: 'ARCHIVE', desc: 'Archive records' }
    ];
  }
}

// ACTION SUPPORT MAPPING & STORAGE FOR RESOURCES
const defaultResourceActionsMap = {
  'users account': ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT'],
  'user directory': ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT'],
  'create staff accounts': ['VIEW', 'CREATE'],
  'account status': ['VIEW', 'EDIT'],
  'roles': ['VIEW', 'CREATE', 'EDIT', 'DELETE'],
  'module management': ['VIEW', 'CREATE', 'EDIT', 'DELETE'],
  'resource management': ['VIEW', 'CREATE', 'EDIT', 'DELETE'],
  'action management': ['VIEW', 'CREATE', 'EDIT', 'DELETE'],
  'permission builder': ['VIEW', 'EDIT'],
  'role permission matrix': ['VIEW', 'EDIT'],
  'department management': ['VIEW', 'CREATE', 'EDIT', 'DELETE'],
  'citizen management': ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT', 'APPROVE'],
  'citizen directory': ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT', 'APPROVE'],
  'citizen account': ['VIEW', 'CREATE', 'EDIT', 'DELETE'],
  'audit logs system': ['VIEW', 'EXPORT'],
  'user activities': ['VIEW', 'EXPORT'],
  'login history': ['VIEW', 'EXPORT'],
  'data changes': ['VIEW', 'EXPORT'],
  'hazard & evacuation map system': ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT'],
  'hazard & evacuation map': ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT'],
  'incident reporting & response log': ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT', 'REVIEW_INCIDENT', 'VERIFY_INCIDENT', 'ASSIGN_INCIDENT', 'UPDATE_RESPONSE', 'RESOLVE_INCIDENT', 'CLOSE_INCIDENT', 'REJECT_INCIDENT'],
  'disaster early warning system': ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT', 'CREATE_WARNING', 'ACTIVATE_WARNING', 'CANCEL_WARNING'],
  'scholarship types': ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT']
};

function saveResourceActionsLocally(resourceId, resourceName, actionsArray) {
  try {
    const storeKey = 'civentral_user_custom_actions_map';
    const store = JSON.parse(localStorage.getItem(storeKey) || '{}');
    if (resourceId) store['id_' + resourceId] = actionsArray;
    if (resourceName) store['name_' + resourceName.toLowerCase().trim()] = actionsArray;
    localStorage.setItem(storeKey, JSON.stringify(store));
  } catch (e) {
    console.warn('LocalStorage write warning:', e);
  }
}

function getSavedResourceActions(resourceId, resourceName) {
  try {
    const storeKey = 'civentral_user_custom_actions_map';
    const store = JSON.parse(localStorage.getItem(storeKey) || '{}');
    if (resourceId && store['id_' + resourceId]) return store['id_' + resourceId];
    if (resourceName && store['name_' + resourceName.toLowerCase().trim()]) return store['name_' + resourceName.toLowerCase().trim()];
  } catch (e) {
    console.warn('LocalStorage read warning:', e);
  }
  return null;
}

function getDefaultApplicableActions(resourceName) {
  const nameLower = (resourceName || '').toLowerCase().trim();
  if (defaultResourceActionsMap[nameLower]) {
    return [...defaultResourceActionsMap[nameLower]];
  }
  if (nameLower.includes('incident') || nameLower.includes('response')) {
    return ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT', 'REVIEW_INCIDENT', 'VERIFY_INCIDENT', 'ASSIGN_INCIDENT', 'UPDATE_RESPONSE', 'RESOLVE_INCIDENT', 'CLOSE_INCIDENT'];
  }
  if (nameLower.includes('warning') || nameLower.includes('early')) {
    return ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT', 'CREATE_WARNING', 'ACTIVATE_WARNING', 'CANCEL_WARNING'];
  }
  return ['VIEW', 'CREATE', 'EDIT', 'DELETE'];
}

// FETCH RESOURCES AND MODULES FROM Database API
async function fetchResources() {
  try {
    if (systemActionsList.length === 0) {
      await fetchSystemActionsList();
    }

    const response = await fetch('../../api/employee/resources.php');
    const result = await response.json();
    if (result.status === 'success') {
      currentUserScope = result.current_user || null;
      if (Array.isArray(result.modules)) {
        systemModulesList = result.modules;
        populateModuleSelects();
      }

      if (Array.isArray(result.data)) {
        systemResources = result.data.map(r => {
          let resolvedActions = null;

          if (r.applicable_actions) {
            if (Array.isArray(r.applicable_actions) && r.applicable_actions.length > 0) {
              resolvedActions = r.applicable_actions;
            } else if (typeof r.applicable_actions === 'string') {
              try { resolvedActions = JSON.parse(r.applicable_actions); } catch(e){}
            }
          }

          if (!resolvedActions || resolvedActions.length === 0) {
            resolvedActions = getSavedResourceActions(r.resource_id, r.resource_name);
          }

          if (!resolvedActions || resolvedActions.length === 0) {
            resolvedActions = getDefaultApplicableActions(r.resource_name);
          }

          return {
            id: r.resource_id,
            module_id: r.module_id,
            module: r.modules ? r.modules.module_name : (systemModulesList.find(m => m.module_id === r.module_id)?.module_name || 'Unassigned'),
            name: r.resource_name,
            route: r.resource_route || '',
            desc: r.description || '',
            applicable_actions: resolvedActions,
            status: r.status || 'Active',
            created_at: r.created_at ? r.created_at.replace('T', ' ').substring(0, 19) : '',
            updated_at: r.updated_at ? r.updated_at.replace('T', ' ').substring(0, 19) : ''
          };
        });
        if (typeof filterResources === 'function') filterResources();
      }
    } else {
      console.warn('Resources fetch notice:', result.message);
    }
  } catch (err) {
    console.error('Error fetching resources FROM DATABASE:', err);
    if (typeof showToast === 'function') showToast('Network error connecting to Database.');
  }
}

// DYNAMICALLY POPULATE PARENT MODULE SELECT DROPDOWNS
function populateModuleSelects() {
  const filterSelect = document.getElementById('parentModuleFilter');
  const modalSelect = document.getElementById('resourceParentModule');

  if (filterSelect) {
    const curVal = filterSelect.value || 'ALL';
    let optionsHtml = '<option value="ALL">All Parent Modules</option>';
    systemModulesList.forEach(m => {
      optionsHtml += `<option value="${m.module_id}">${m.module_name}</option>`;
    });
    filterSelect.innerHTML = optionsHtml;
    filterSelect.value = curVal;
  }

  if (modalSelect) {
    const curVal = modalSelect.value;
    let optionsHtml = '';
    systemModulesList.forEach(m => {
      optionsHtml += `<option value="${m.module_id}">${m.module_name}</option>`;
    });
    modalSelect.innerHTML = optionsHtml;
    if (curVal) modalSelect.value = curVal;
  }
}

// UPDATE RESOURCE STATUS IN DATABASE
async function updateResourceStatusInDb(resourceId, newStatus) {
  try {
    const response = await fetch('../../api/employee/resources.php', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ resource_id: resourceId, status: newStatus })
    });
    const result = await response.json();
    if (result.status === 'success') {
      if (typeof showToast === 'function') showToast(`Resource status updated to ${newStatus}.`);
      await fetchResources();
    } else {
      if (typeof showToast === 'function') showToast(result.message || 'Failed to update resource status.');
    }
  } catch (err) {
    console.error('Error updating status:', err);
    if (typeof showToast === 'function') showToast('Error updating status IN DATABASE.');
  }
}

window.getDefaultApplicableActions = getDefaultApplicableActions;
window.fetchSystemActionsList = fetchSystemActionsList;
window.saveResourceActionsLocally = saveResourceActionsLocally;
window.getSavedResourceActions = getSavedResourceActions;
window.fetchResources = fetchResources;
window.updateResourceStatusInDb = updateResourceStatusInDb;
