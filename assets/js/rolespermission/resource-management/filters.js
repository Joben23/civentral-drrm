// FILTER RESOURCES REAL TIME
function filterResources() {
  const searchInput = document.getElementById('resourceSearchInput');
  const parentFilter = document.getElementById('parentModuleFilter');
  const statusFilter = document.getElementById('statusFilterSelect');

  const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
  const selectedModule = parentFilter ? parentFilter.value : 'ALL';
  const selectedStatus = statusFilter ? statusFilter.value : 'ALL';

  const filtered = systemResources.filter(res => {
    const actionsText = (res.applicable_actions || []).join(' ').toLowerCase();
    const matchesQuery = res.name.toLowerCase().includes(query) || 
                         res.route.toLowerCase().includes(query) ||
                         res.module.toLowerCase().includes(query) ||
                         actionsText.includes(query) ||
                         (res.desc && res.desc.toLowerCase().includes(query));

    const matchesModule = selectedModule === 'ALL' || 
                          String(res.module_id) === String(selectedModule) || 
                          res.module === selectedModule;

    const matchesStatus = selectedStatus === 'ALL' || res.status === selectedStatus;

    return matchesQuery && matchesModule && matchesStatus;
  });

  if (typeof renderResourcesTable === 'function') renderResourcesTable(filtered);
}

window.filterResources = filterResources;
