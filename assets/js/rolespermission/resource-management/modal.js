// MODAL CONTROLS - CREATE / EDIT / ARCHIVE
function showModalOverlay(modalId, cardId) {
  const modal = document.getElementById(modalId);
  const card = document.getElementById(cardId);
  if (!modal) return;

  modal.classList.remove('opacity-0', 'pointer-events-none');
  modal.classList.add('opacity-100', 'pointer-events-auto');

  if (card) {
    card.classList.remove('scale-95', 'opacity-0');
    card.classList.add('scale-100', 'opacity-100');
  }
}

function hideModalOverlay(modalId, cardId) {
  const modal = document.getElementById(modalId);
  const card = document.getElementById(cardId);
  if (!modal) return;

  modal.classList.remove('opacity-100', 'pointer-events-auto');
  modal.classList.add('opacity-0', 'pointer-events-none');

  if (card) {
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
  }
}

const actionBadgeStyleMap = {
  'VIEW': { bg: 'bg-blue-50', text: 'text-blue-700', border: 'border-blue-200', check: 'text-blue-600 focus:ring-blue-500' },
  'CREATE': { bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', check: 'text-emerald-600 focus:ring-emerald-500' },
  'EDIT': { bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200', check: 'text-amber-600 focus:ring-amber-500' },
  'DELETE': { bg: 'bg-rose-50', text: 'text-rose-700', border: 'border-rose-200', check: 'text-rose-600 focus:ring-rose-500' },
  'EXPORT': { bg: 'bg-purple-50', text: 'text-purple-700', border: 'border-purple-200', check: 'text-purple-600 focus:ring-purple-500' },
  'APPROVE': { bg: 'bg-teal-50', text: 'text-teal-700', border: 'border-teal-200', check: 'text-teal-600 focus:ring-teal-500' },
  'REJECT': { bg: 'bg-pink-50', text: 'text-pink-700', border: 'border-pink-200', check: 'text-pink-600 focus:ring-pink-500' },
  'ARCHIVE': { bg: 'bg-slate-100', text: 'text-slate-700', border: 'border-slate-200', check: 'text-slate-600 focus:ring-slate-500' }
};

function renderActionsCheckboxes(checkedActions = []) {
  const container = document.getElementById('applicableActionsContainer');
  if (!container) return;

  const activeCheckedList = (checkedActions || []).map(a => String(a).toUpperCase().trim());

  let actionsListToRender = (typeof systemActionsList !== 'undefined' && Array.isArray(systemActionsList) && systemActionsList.length > 0)
    ? systemActionsList
    : [
        { name: 'VIEW', desc: 'View resource data' },
        { name: 'CREATE', desc: 'Create new records' },
        { name: 'EDIT', desc: 'Modify existing records' },
        { name: 'DELETE', desc: 'Remove records' },
        { name: 'EXPORT', desc: 'Export report data' },
        { name: 'APPROVE', desc: 'Approve submissions' },
        { name: 'REJECT', desc: 'Reject submissions' },
        { name: 'ARCHIVE', desc: 'Archive records' }
      ];

  let html = '';
  actionsListToRender.forEach(act => {
    const actName = (act.name || act.action_name || act).toUpperCase().trim();
    const isChecked = activeCheckedList.includes(actName);
    const style = actionBadgeStyleMap[actName] || { bg: 'bg-slate-100', text: 'text-slate-700', border: 'border-slate-200', check: 'text-slate-600 focus:ring-slate-500' };

    html += `
      <label class="flex items-center justify-between p-2 rounded-xl bg-white border border-slate-200/80 hover:border-slate-300 transition cursor-pointer select-none">
        <div class="flex items-center gap-2">
          <input 
            type="checkbox" 
            name="applicable_action" 
            value="${actName}" 
            ${isChecked ? 'checked' : ''} 
            class="rounded border-slate-300 ${style.check} h-4 w-4"
          >
          <span class="px-2 py-0.5 rounded-md text-[10px] font-extrabold border ${style.bg} ${style.text} ${style.border}">
            ${actName}
          </span>
        </div>
        ${act.desc ? `<span class="text-[9px] text-slate-400 font-medium truncate max-w-[120px]" title="${act.desc}">${act.desc}</span>` : ''}
      </label>
    `;
  });

  container.innerHTML = html;
}

function selectAllApplicableActions(shouldSelectAll) {
  const checkboxes = document.querySelectorAll('#applicableActionsContainer input[name="applicable_action"]');
  checkboxes.forEach(cb => {
    cb.checked = !!shouldSelectAll;
  });
}

function openCreateResourceModal() {
  const isSuperAdmin = currentUserScope ? !!currentUserScope.is_superadmin : false;
  const grantedActions = currentUserScope ? (currentUserScope.granted_actions || []) : [];
  const canCreate = isSuperAdmin || grantedActions.includes('CREATE');

  if (!canCreate) {
    if (typeof showToast === 'function') showToast('Forbidden. View-only access level cannot create system resources.');
    return;
  }

  const formResourceId = document.getElementById('formResourceId');
  const resourceForm = document.getElementById('resourceForm');
  const modalHeaderTitle = document.getElementById('modalHeaderTitle');
  const resourceStatus = document.getElementById('resourceStatus');
  const resourceCreatedAt = document.getElementById('resourceCreatedAt');
  const resourceName = document.getElementById('resourceName');
  const resourceRoute = document.getElementById('resourceRoute');
  const resourceDesc = document.getElementById('resourceDesc');

  if (formResourceId) formResourceId.value = '';
  if (resourceForm) resourceForm.reset();
  if (resourceName) resourceName.value = '';
  if (resourceRoute) resourceRoute.value = '';
  if (resourceDesc) resourceDesc.value = '';
  if (modalHeaderTitle) modalHeaderTitle.textContent = 'Add New System Resource';
  if (resourceStatus) resourceStatus.value = 'Active';
  if (resourceCreatedAt) resourceCreatedAt.value = 'Auto-generated on save';
  
  // Render dynamic actions checkboxes loaded from System Actions API (Standard CRUD checked by default for new resources)
  const defaultActions = ['VIEW', 'CREATE', 'EDIT', 'DELETE'];
  renderActionsCheckboxes(defaultActions);

  showModalOverlay('resourceModal', 'resourceModalCard');
}

function openEditResourceModal(id) {
  const isSuperAdmin = currentUserScope ? !!currentUserScope.is_superadmin : false;
  const grantedActions = currentUserScope ? (currentUserScope.granted_actions || []) : [];
  const canEdit = isSuperAdmin || grantedActions.includes('EDIT');

  if (!canEdit) {
    if (typeof showToast === 'function') showToast('Forbidden. View-only access level cannot modify system resources.');
    return;
  }

  const res = systemResources.find(r => r.id === id);
  if (!res) return;

  const formResourceId = document.getElementById('formResourceId');
  const resourceParentModule = document.getElementById('resourceParentModule');
  const resourceName = document.getElementById('resourceName');
  const resourceStatus = document.getElementById('resourceStatus');
  const resourceCreatedAt = document.getElementById('resourceCreatedAt');
  const resourceRoute = document.getElementById('resourceRoute');
  const resourceDesc = document.getElementById('resourceDesc');
  const modalHeaderTitle = document.getElementById('modalHeaderTitle');

  if (formResourceId) formResourceId.value = res.id;
  if (resourceParentModule) resourceParentModule.value = res.module_id || '';
  if (resourceName) resourceName.value = res.name || '';
  if (resourceStatus) resourceStatus.value = res.status || 'Active';
  if (resourceCreatedAt) resourceCreatedAt.value = res.created_at || '';
  if (resourceRoute) resourceRoute.value = res.route || '';
  if (resourceDesc) resourceDesc.value = res.desc || '';

  // Render resource's applicable actions checkboxes from System Actions list
  const activeActions = Array.isArray(res.applicable_actions) 
    ? res.applicable_actions 
    : (typeof getDefaultApplicableActions === 'function' ? getDefaultApplicableActions(res.name) : ['VIEW', 'CREATE', 'EDIT', 'DELETE']);
  
  renderActionsCheckboxes(activeActions);

  if (modalHeaderTitle) modalHeaderTitle.textContent = `Edit Resource: ${res.name}`;

  showModalOverlay('resourceModal', 'resourceModalCard');
}

function closeResourceModal() {
  hideModalOverlay('resourceModal', 'resourceModalCard');
}

function openArchiveResourceModal(id) {
  const res = systemResources.find(r => r.id === id);
  if (!res) return;

  archiveTargetResourceId = id;
  const targetNameEl = document.getElementById('archiveTargetName');
  if (targetNameEl) targetNameEl.textContent = `Resource: ${res.name}`;

  showModalOverlay('archiveModal', 'archiveModalCard');
}

function closeArchiveModal() {
  archiveTargetResourceId = null;
  hideModalOverlay('archiveModal', 'archiveModalCard');
}

async function confirmArchiveResource() {
  if (!archiveTargetResourceId) return;

  const targetId = archiveTargetResourceId;
  closeArchiveModal();
  if (typeof updateResourceStatusInDb === 'function') await updateResourceStatusInDb(targetId, 'Archived');
}

async function toggleResourceStatus(id) {
  const res = systemResources.find(r => r.id === id);
  if (!res) return;

  const nextStatus = res.status === 'Active' ? 'Inactive' : 'Active';
  if (typeof updateResourceStatusInDb === 'function') await updateResourceStatusInDb(id, nextStatus);
}

window.renderActionsCheckboxes = renderActionsCheckboxes;
window.selectAllApplicableActions = selectAllApplicableActions;
window.openCreateResourceModal = openCreateResourceModal;
window.openEditResourceModal = openEditResourceModal;
window.closeResourceModal = closeResourceModal;
window.openArchiveResourceModal = openArchiveResourceModal;
window.closeArchiveModal = closeArchiveModal;
window.confirmArchiveResource = confirmArchiveResource;
window.toggleResourceStatus = toggleResourceStatus;
