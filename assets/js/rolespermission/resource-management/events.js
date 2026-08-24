// EVENTS & FORM SUBMISSION

async function handleSaveResource(event) {
  event.preventDefault();

  const idVal = document.getElementById('formResourceId').value;
  const moduleId = document.getElementById('resourceParentModule').value;
  const name = document.getElementById('resourceName').value.trim();
  const status = document.getElementById('resourceStatus').value;
  const route = document.getElementById('resourceRoute').value.trim();
  const desc = document.getElementById('resourceDesc').value.trim();

  // Collect checked applicable actions
  const checkedActions = Array.from(
    document.querySelectorAll('#applicableActionsContainer input[name="applicable_action"]:checked')
  ).map(cb => cb.value);

  const payload = {
    module_id: parseInt(moduleId),
    resource_name: name,
    resource_route: route,
    description: desc,
    applicable_actions: checkedActions,
    status: status
  };

  if (idVal !== '') {
    payload.resource_id = parseInt(idVal);
  }

  const method = idVal === '' ? 'POST' : 'PUT';

  try {
    const response = await fetch('../../api/employee/resources.php', {
      method: method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await response.json();

    if (result.status === 'success') {
      const savedResId = payload.resource_id || (result.data ? (result.data.resource_id || result.data.id) : null);
      if (typeof saveResourceActionsLocally === 'function') {
        saveResourceActionsLocally(savedResId, name, checkedActions);
      }

      if (typeof systemResources !== 'undefined' && Array.isArray(systemResources)) {
        const existing = systemResources.find(r => (savedResId && r.id === savedResId) || r.name.toLowerCase().trim() === name.toLowerCase().trim());
        if (existing) {
          existing.applicable_actions = checkedActions;
        }
      }

      if (typeof showToast === 'function') showToast(result.message || 'Resource saved successfully.');
      if (typeof closeResourceModal === 'function') closeResourceModal();
      if (typeof fetchResources === 'function') await fetchResources();
    } else {
      if (typeof showToast === 'function') showToast(result.message || 'Failed to save resource.');
    }
  } catch (err) {
    console.error('Error saving resource:', err);
    if (typeof showToast === 'function') showToast('Failed to save resource TO DATABASE.');
  }
}

window.handleSaveResource = handleSaveResource;

// DISMISS MODALS ON BACKDROP CLICK & ESCAPE KEY
document.addEventListener('click', (e) => {
  if (e.target.id === 'resourceModal' && typeof closeResourceModal === 'function') closeResourceModal();
  if (e.target.id === 'archiveModal' && typeof closeArchiveModal === 'function') closeArchiveModal();
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    if (typeof closeResourceModal === 'function') closeResourceModal();
    if (typeof closeArchiveModal === 'function') closeArchiveModal();
  }
});
