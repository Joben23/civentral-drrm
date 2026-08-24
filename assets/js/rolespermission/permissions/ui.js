// ACTION SUPPORT MAPPING PER RESOURCE
const resourceSupportedActionsMap = {
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

function getSavedResourceActionsForPermissions(resourceId, resourceName) {
  try {
    const storeKey = 'civentral_user_custom_actions_map';
    const store = JSON.parse(localStorage.getItem(storeKey) || '{}');
    if (resourceId && store['id_' + resourceId]) return store['id_' + resourceId];
    if (resourceName && store['name_' + resourceName.toLowerCase().trim()]) return store['name_' + resourceName.toLowerCase().trim()];
  } catch (e) {}
  return null;
}

function isActionSupportedForResource(resObj, actObj) {
  if (!resObj || !actObj) return true;
  const resName = (resObj.name || '').toLowerCase().trim();
  const actName = (actObj.action_name || '').toUpperCase().trim();

  // 1. Check custom configured applicable actions from Resource Management
  let customActions = (resObj.applicable_actions && Array.isArray(resObj.applicable_actions) && resObj.applicable_actions.length > 0)
    ? resObj.applicable_actions
    : getSavedResourceActionsForPermissions(resObj.id, resObj.name);

  if (customActions && Array.isArray(customActions)) {
    const upperCustom = customActions.map(a => String(a).toUpperCase().trim());
    return upperCustom.includes(actName);
  }

  // 2. Check default resource supported actions map
  if (resourceSupportedActionsMap[resName]) {
    return resourceSupportedActionsMap[resName].includes(actName);
  }

  // 3. Keyword domain fallbacks
  if (resName.includes('incident') || resName.includes('response')) {
    return ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT', 'REVIEW_INCIDENT', 'VERIFY_INCIDENT', 'ASSIGN_INCIDENT', 'UPDATE_RESPONSE', 'RESOLVE_INCIDENT', 'CLOSE_INCIDENT', 'REJECT_INCIDENT'].includes(actName);
  }
  if (resName.includes('warning') || resName.includes('early')) {
    return ['VIEW', 'CREATE', 'EDIT', 'DELETE', 'EXPORT', 'CREATE_WARNING', 'ACTIVATE_WARNING', 'CANCEL_WARNING'].includes(actName);
  }

  return ['VIEW', 'CREATE', 'EDIT', 'DELETE'].includes(actName);
}

// RENDER ROLE SELECTION SIDEBAR
function renderRoleSelector(filterQuery = '') {
  const roleListContainer = document.getElementById('roleSelectorList');
  if (!roleListContainer) return;
  roleListContainer.innerHTML = '';
  const query = filterQuery.toLowerCase().trim();

  const isSuperAdmin = (typeof window.currentUserIsSuperAdmin !== 'undefined')
    ? window.currentUserIsSuperAdmin
    : (currentUserScope ? (!!currentUserScope.is_superadmin || !!currentUserScope.is_global_access) : false);

  const userDeptId = (typeof window.currentUserDeptId !== 'undefined' && window.currentUserDeptId !== null)
    ? window.currentUserDeptId
    : (currentUserScope ? (currentUserScope.department_id || currentUserScope.role_dept_id) : null);

  const filteredRoles = rolesData.filter(r => {
    const nameMatches = (r.role_name || '').toLowerCase().includes(query);
    if (!nameMatches) return false;
    
    if (isSuperAdmin || !userDeptId) return true;
    
    const roleDeptId = r.department_id || r.role_dept_id;
    if (roleDeptId) {
      return String(roleDeptId) === String(userDeptId);
    }
    return true;
  });

  if (filteredRoles.length === 0) {
    roleListContainer.innerHTML = '<p class="text-[11px] text-slate-400 text-center py-2 font-semibold">No roles found</p>';
    return;
  }

  filteredRoles.forEach(roleObj => {
    const isActive = roleObj.role_id === window.selectedRoleId;
    
    const activeClasses = isActive 
      ? 'border-l-4 border-brand-dark bg-brand-light font-black text-brand-dark shadow-xs' 
      : 'border-l-4 border-transparent text-slate-500 hover:text-slate-800 hover:bg-slate-50 font-semibold';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `w-full text-left px-3 py-2.5 rounded-lg text-xs transition cursor-pointer flex items-center justify-between ${activeClasses}`;
    btn.innerHTML = `
      <span>${roleObj.role_name}</span>
      <i class="fa-solid fa-chevron-right text-[8px] opacity-60"></i>
    `;
    btn.onclick = () => { if (typeof selectRole === 'function') selectRole(roleObj.role_id); };
    roleListContainer.appendChild(btn);
  });
}

function isModuleAllowedForDepartment(mod, userDeptId, userDeptName, isSuperAdmin) {
  if (isSuperAdmin) return true;
  
  // 1. Check explicit DB department_id if present on module
  if (mod.department_id && userDeptId) {
    return String(mod.department_id) === String(userDeptId);
  }

  const deptNameLower = (userDeptName || '').toLowerCase();
  const modNameLower = (mod.name || '').toLowerCase();
  const modDescLower = (mod.desc || '').toLowerCase();
  const resText = (mod.resources || []).map(r => (r.name || '') + ' ' + (r.desc || '')).join(' ').toLowerCase();
  const fullModText = modNameLower + ' ' + modDescLower + ' ' + resText;

  // Department-specific operational modules (restricted to their respective departments)
  const domainRules = [
    {
      domainKeywords: ['scholarship', 'student registry', 'education monitoring'],
      deptKeywords: ['scholarship', 'education', 'school', 'student']
    },
    {
      domainKeywords: ['sanitation inspection', 'clinic management', 'medical records'],
      deptKeywords: ['health', 'sanitation', 'medical', 'doctor', 'clinic', 'hospital']
    }
  ];

  for (const rule of domainRules) {
    const isDomainModule = rule.domainKeywords.some(kw => fullModText.includes(kw));
    if (isDomainModule) {
      const isUserInDomainDept = rule.deptKeywords.some(kw => deptNameLower.includes(kw));
      if (!isUserInDomainDept) {
        return false; // Hide operational module of other department
      }
    }
  }

  return true;
}

// RENDER COLLAPSIBLE MODULES AND NESTED RESOURCES
function renderAccordions() {
  const accordionsContainer = document.getElementById('moduleAccordionList');
  const moduleSearchInput = document.getElementById('moduleSearchInput');
  if (!accordionsContainer) return;
  accordionsContainer.innerHTML = '';
  const roleId = window.selectedRoleId;
  const rolePermissions = (window.currentPermissions && window.currentPermissions[roleId]) ? window.currentPermissions[roleId] : {};
  const query = moduleSearchInput ? moduleSearchInput.value.toLowerCase().trim() : '';

  const isSuperAdmin = (typeof window.currentUserIsSuperAdmin !== 'undefined')
    ? window.currentUserIsSuperAdmin
    : (currentUserScope ? (!!currentUserScope.is_superadmin || !!currentUserScope.is_global_access) : false);

  const userDeptId = (typeof window.currentUserDeptId !== 'undefined' && window.currentUserDeptId !== null)
    ? window.currentUserDeptId
    : (currentUserScope ? (currentUserScope.department_id || currentUserScope.role_dept_id) : null);

  const userDeptName = (typeof window.currentUserDeptName !== 'undefined')
    ? window.currentUserDeptName
    : (currentUserScope ? (currentUserScope.department_name || '') : '');

  const grantedActions = currentUserScope ? (currentUserScope.granted_actions || []) : [];
  let canEditUser = isSuperAdmin || grantedActions.includes('EDIT') || grantedActions.includes('CREATE');

  if (window.selectedRoleId && window.currentUserRoleId && parseInt(window.selectedRoleId) === parseInt(window.currentUserRoleId)) {
    canEditUser = false;
  }

  modulesData.forEach(mod => {
    // Check if module is allowed for the user's department
    if (!isModuleAllowedForDepartment(mod, userDeptId, userDeptName, isSuperAdmin)) {
      return;
    }

    const moduleMatches = mod.name.toLowerCase().includes(query) || mod.desc.toLowerCase().includes(query);
    const matchingResources = mod.resources.filter(res => 
      res.name.toLowerCase().includes(query) || res.desc.toLowerCase().includes(query)
    );

    if (query !== '' && !moduleMatches && matchingResources.length === 0) {
      return;
    }

    const isExpanded = query !== '' ? true : !!window.expandedModules[mod.name];
    const masterChecked = (typeof isModuleMasterChecked === 'function') ? isModuleMasterChecked(mod, rolePermissions) : false;

    const card = document.createElement('div');
    card.className = 'bg-white border border-slate-200/80 rounded-2xl shadow-xs overflow-hidden transition-all duration-200';

    const header = document.createElement('div');
    header.className = 'px-5 py-4 flex items-center justify-between gap-4 bg-slate-50 border-b border-slate-100 hover:bg-slate-100/40 transition select-none cursor-pointer';
    
    header.addEventListener('click', (e) => {
      if (e.target.closest('.checkbox-container')) return;
      if (typeof toggleModuleCollapse === 'function') toggleModuleCollapse(mod.name);
    });

    header.innerHTML = `
      <div class="flex items-center gap-3 flex-1 min-w-0">
        <div class="h-8 w-8 rounded-lg bg-white border border-slate-200/60 flex items-center justify-center text-slate-500 shrink-0">
          <i class="fa-solid ${mod.icon || 'fa-folder-tree'} text-xs"></i>
        </div>
        <div class="space-y-0.5 min-w-0">
          <h4 class="font-extrabold text-slate-800 tracking-tight text-xs">${mod.name}</h4>
          <p class="text-[10px] text-slate-400 font-medium leading-relaxed truncate">${mod.desc}</p>
        </div>
      </div>

      <div class="flex items-center gap-5 shrink-0">
        <label class="checkbox-container flex items-center gap-1.5 ${!canEditUser ? 'opacity-30 cursor-not-allowed pointer-events-none' : 'cursor-pointer'} text-[10px] font-black text-slate-500 uppercase tracking-wide" title="${!canEditUser ? 'Cannot modify permissions for this role' : ''}">
          <input type="checkbox" data-module-master="${mod.name}" ${masterChecked ? 'checked' : ''} ${!canEditUser ? 'disabled' : ''} onchange="if(typeof toggleModuleMaster === 'function') toggleModuleMaster(this)" class="h-4.5 w-4.5 rounded border-slate-350 text-brand-dark focus:ring-brand-dark/20 ${!canEditUser ? 'cursor-not-allowed bg-slate-100 opacity-40' : 'cursor-pointer'}">
          <span class="hidden sm:inline">Master Access</span>
        </label>
        
        <div class="text-slate-400 text-xs">
          <i class="fa-solid ${isExpanded ? 'fa-chevron-up' : 'fa-chevron-down'} transition-transform duration-200"></i>
        </div>
      </div>
    `;

    card.appendChild(header);

    if (isExpanded) {
      const body = document.createElement('div');
      body.className = 'divide-y divide-slate-100 text-xs';

      const resourcesToRender = query !== '' && !moduleMatches ? matchingResources : mod.resources;

      if (resourcesToRender.length === 0) {
        body.innerHTML = `
          <div class="px-5 py-6 text-center text-[10px] text-slate-400 font-semibold italic">
            No resources match the active search.
          </div>
        `;
      } else {
        resourcesToRender.forEach(res => {
          const resourcePerms = rolePermissions[res.id] || [];
          const isFeatureEnabled = resourcePerms.length > 0;

          const row = document.createElement('div');
          row.className = `px-5 py-4 flex flex-col md:flex-row md:items-center justify-between border-t border-slate-100 gap-4 transition ${isFeatureEnabled ? 'bg-white' : 'bg-slate-50/70 opacity-75'}`;

          // Feature Enable Switch & Details
          const detailsDiv = document.createElement('div');
          detailsDiv.className = 'flex items-start gap-3.5 max-w-md';
          detailsDiv.innerHTML = `
            <label class="relative inline-flex items-center cursor-pointer mt-0.5 shrink-0 ${!canEditUser ? 'opacity-50 pointer-events-none' : ''}" title="${!canEditUser ? 'View-only access level cannot modify permissions' : 'Check to enable this feature for this role. Unchecking hides it from the sidebar.'}">
              <input type="checkbox" data-resource-toggle="${res.id}" ${isFeatureEnabled ? 'checked' : ''} ${!canEditUser ? 'disabled' : ''} onchange="if(typeof toggleFeatureEnable === 'function') toggleFeatureEnable(this)" class="sr-only peer">
              <div class="w-8 h-4.5 bg-slate-300 dark:bg-slate-700 rounded-full peer peer-focus:ring-2 peer-focus:ring-brand-medium/20 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-3.5 after:w-3.5 after:transition-all peer-checked:bg-brand-dark"></div>
            </label>
            <div class="space-y-0.5 min-w-0">
              <div class="flex items-center gap-2">
                <h5 class="font-extrabold text-slate-800 tracking-tight text-[11px] leading-snug">${res.name}</h5>
                <span class="text-[9px] font-extrabold px-2 py-0.5 rounded-full border ${isFeatureEnabled ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200'}">
                  ${isFeatureEnabled ? 'Feature Enabled' : 'Disabled (Hidden in Sidebar)'}
                </span>
              </div>
              <p class="text-[9px] text-slate-400 font-medium leading-relaxed">${res.desc}</p>
            </div>
          `;
          row.appendChild(detailsDiv);

          // Action Checkboxes Group (View, Create, Edit, Delete)
          const checkboxesDiv = document.createElement('div');
          checkboxesDiv.className = 'flex items-center gap-5 flex-wrap';

          actionsData.forEach(act => {
            const isSupported = isActionSupportedForResource(res, act);
            const isChecked = resourcePerms.includes(act.action_id) && isSupported;

            const isClickable = canEditUser && isFeatureEnabled && isSupported;
            const disabledAttr = !isClickable ? 'disabled' : '';
            const labelStyle = !isClickable ? 'opacity-30 cursor-not-allowed pointer-events-none' : 'cursor-pointer';
            const hintTitle = !canEditUser ? 'View-only access level cannot modify permissions.' : (!isSupported ? `The ${act.action_name} feature is not applicable to ${res.name} in the system UI.` : '');

            const label = document.createElement('label');
            label.className = `flex items-center gap-1.5 select-none ${labelStyle}`;
            if (hintTitle) label.title = hintTitle;

            label.innerHTML = `
              <input type="checkbox" data-resource="${res.id}" data-action="${act.action_id}" ${isChecked ? 'checked' : ''} ${disabledAttr} onchange="if(typeof toggleResourcePermission === 'function') toggleResourcePermission(this)" class="h-4 w-4 rounded border-slate-300 text-brand-dark focus:ring-brand-dark/20 ${!isClickable ? 'cursor-not-allowed bg-slate-100 opacity-40' : 'cursor-pointer'}">
              <span class="text-[9px] ${!isSupported ? 'text-slate-350 font-bold line-through' : 'text-slate-450 uppercase font-black'} tracking-wide">${act.action_name}</span>
            `;
            checkboxesDiv.appendChild(label);
          });

          row.appendChild(checkboxesDiv);
          body.appendChild(row);
        });
      }
      card.appendChild(body);
    }

    accordionsContainer.appendChild(card);
  });

  if (accordionsContainer.innerHTML === '') {
    accordionsContainer.innerHTML = `
      <div class="bg-white border border-slate-200/80 rounded-2xl p-8 text-center shadow-xs">
        <i class="fa-solid fa-folder-open text-slate-300 text-2xl mb-2"></i>
        <p class="text-xs text-slate-400 font-bold">No system modules or resources matched your search filter</p>
      </div>
    `;
  }
}

// RENDER ALLOWED & FORBIDDEN SECURITY LABELS
function renderRestrictions() {
  const allowedList = document.getElementById('allowedRestrictionsList');
  const forbiddenList = document.getElementById('forbiddenRestrictionsList');
  if (!allowedList || !forbiddenList) return;
  allowedList.innerHTML = '';
  forbiddenList.innerHTML = '';
  
  const roleId = window.selectedRoleId;
  const currentRoleObj = rolesData.find(r => r.role_id === roleId);

  let allowedRules = [];
  let forbiddenRules = [];

  if (currentRoleObj) {
    if (currentRoleObj.is_global_access) {
      allowedRules = ["City-wide cross-departmental access granted", "Execute administrative report exports", "Manage operational resource configurations"];
    } else {
      allowedRules = ["Department-scoped resource access", "Submit localized unit transactions", "Export department report summaries"];
      forbiddenRules = ["Global city-wide system configuration override", "Bypass department data boundaries"];
    }
    if (currentRoleObj.is_system_role) {
      allowedRules.push("Protected system core designation");
    }
  }

  if (allowedRules.length === 0) {
    allowedList.innerHTML = '<li class="text-slate-400 italic">No specific privileges assigned</li>';
  } else {
    allowedRules.forEach(rule => {
      allowedList.innerHTML += `
        <li class="flex items-center gap-2 font-semibold">
          <i class="fa-solid fa-circle-check text-emerald-500 text-xs"></i>
          <span>${rule}</span>
        </li>
      `;
    });
  }

  if (forbiddenRules.length === 0) {
    forbiddenList.innerHTML = '<li class="text-slate-400 italic">No explicit security restrictions</li>';
  } else {
    forbiddenRules.forEach(rule => {
      forbiddenList.innerHTML += `
        <li class="flex items-center gap-2 font-semibold">
          <i class="fa-solid fa-circle-xmark text-rose-500 text-xs"></i>
          <span>${rule}</span>
        </li>
      `;
    });
  }
}
