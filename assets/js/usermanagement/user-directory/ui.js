// USER DIRECTORY UI

// POPULATE DYNAMIC FILTER DROPDOWNS
function populateFilterOptions() {
  const roleFilter = document.getElementById('roleFilter');
  const deptFilter = document.getElementById('deptFilter');

  if (roleFilter) {
    const currentVal = roleFilter.value;
    roleFilter.innerHTML = '<option value="">All Roles</option>';
    availableRoles.forEach(r => {
      const opt = document.createElement('option');
      opt.value = r.role_name;
      opt.textContent = r.role_name;
      roleFilter.appendChild(opt);
    });
    roleFilter.value = currentVal;
  }

  if (deptFilter) {
    const currentVal = deptFilter.value;
    deptFilter.innerHTML = '<option value="">All Departments</option>';
    availableDepartments.forEach(d => {
      const opt = document.createElement('option');
      opt.value = d.department_name;
      opt.textContent = d.department_name;
      deptFilter.appendChild(opt);
    });
    deptFilter.value = currentVal;
  }
}

// POPULATE EDIT MODAL SELECT DROPDOWNS
function populateEditFormOptions() {
  const editRole = document.getElementById('editRole');
  const editDept = document.getElementById('editDept');
  const editPosition = document.getElementById('editPosition');
  const drrmDepartment = availableDepartments.find(d =>
    String(d.department_id) === '9' &&
    String(d.department_name).trim() === 'Disaster Risk Reduction & Emergency Response'
  );
  const drrmRole = availableRoles.find(r =>
    String(r.role_id) === '18' &&
    String(r.role_name).trim() === 'DRRM Administrator' &&
    String(r.role_prefix).trim().toUpperCase() === 'DA' &&
    (!Object.prototype.hasOwnProperty.call(r, 'department_id') || String(r.department_id) === '9')
  );

  if (editRole) {
    editRole.innerHTML = '';
    if (drrmRole) {
      const opt = document.createElement('option');
      opt.value = drrmRole.role_id;
      opt.textContent = `${drrmRole.role_name} (${drrmRole.role_prefix})`;
      opt.selected = true;
      editRole.appendChild(opt);
    }
  }

  if (editDept) {
    editDept.innerHTML = '';
    if (drrmDepartment) {
      const opt = document.createElement('option');
      opt.value = drrmDepartment.department_id;
      opt.textContent = drrmDepartment.department_name;
      opt.selected = true;
      editDept.appendChild(opt);
    }
  }

  // Handle department change to filter position options
  if (editDept && editPosition) {
    editDept.onchange = function() {
      updatePositionDropdown(this.value);
    };
  }
}

function updatePositionDropdown(deptId, selectedPositionId = '') {
  const editPosition = document.getElementById('editPosition');
  if (!editPosition) return;

  const isGlobalScope = currentUserScope
    ? (!!currentUserScope.is_superadmin || !!currentUserScope.is_global_access)
    : false;
  const userDeptId = currentUserScope ? currentUserScope.department_id : null;
  const effectiveDeptId = !isGlobalScope && userDeptId ? userDeptId : deptId;

  editPosition.innerHTML = '<option value="">Select Position...</option>';
  const filteredPositions = effectiveDeptId
    ? availablePositions.filter(p => p.department_id == effectiveDeptId)
    : availablePositions;

  filteredPositions.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p.position_id;
    opt.textContent = p.position_name;
    editPosition.appendChild(opt);
  });

  if (selectedPositionId) {
    editPosition.value = selectedPositionId;
  }
}

// HELPER: GET USER FULL NAME
function getUserFullName(user) {
  const mid = user.middle_name ? user.middle_name.trim() + ' ' : '';
  return `${user.first_name || ''} ${mid}${user.last_name || ''}`.trim() || 'Unnamed User';
}

// HELPER: GET USER INITIALS
function getUserInitials(user) {
  const f = (user.first_name || 'U').charAt(0).toUpperCase();
  const l = (user.last_name || 'S').charAt(0).toUpperCase();
  return f + l;
}

// RENDER DATATABLE FROM Database records
function renderTable(usersList = systemUsers) {
  const tbody = document.getElementById('directoryTableBody');
  if (!tbody) return;
  tbody.innerHTML = '';
  
  if (usersList.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="px-6 py-12 text-center text-slate-400 font-semibold">
          <i class="fa-solid fa-user-xmark text-3xl mb-3 block opacity-60"></i>
          No staff records matched your current query criteria.
        </td>
      </tr>
    `;
    const pagEl = document.getElementById('paginationText');
    if (pagEl) pagEl.innerText = "Showing 0 to 0 of 0 profiles";
    return;
  }

  const isSuperAdmin = currentUserScope ? !!currentUserScope.is_superadmin : false;
  const grantedActions = currentUserScope ? (currentUserScope.granted_actions || []) : [];
  const canEdit = isSuperAdmin || grantedActions.includes('EDIT');

  usersList.forEach(user => {
    const fullName = getUserFullName(user);
    const initials = getUserInitials(user);

    const roleObj = user.roles || {};
    const roleName = roleObj.role_name || 'Staff';
    const rolePrefix = roleObj.role_prefix || 'STF';

    const posObj = user.positions || {};
    const positionName = posObj.position_name || 'Staff Member';
    const deptObj = posObj.departments || {};
    const deptName = deptObj.department_name || 'Unassigned';

    // Role style binding
    let roleStyles = 'bg-slate-100 text-slate-600 border-slate-200';
    if (roleName === 'Super Admin' || rolePrefix === 'SADM') roleStyles = 'bg-purple-50 text-purple-700 border-purple-100/60';
    else if (roleName.includes('Admin') || rolePrefix.includes('ADM')) roleStyles = 'bg-blue-50 text-blue-700 border-blue-100/60';

    // Status style binding
    let statusStyles = 'bg-emerald-50 text-emerald-700 border-emerald-100/60';
    let dotStatus = '<span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>';
    if (user.status === 'Inactive' || user.status === 'Archived' || user.status === 'Deactivated') {
      statusStyles = 'bg-rose-50 text-rose-600 border-rose-100/60';
      dotStatus = '<span class="h-1.5 w-1.5 rounded-full bg-rose-400"></span>';
    } else if (user.status === 'Pending' || user.status === 'Locked') {
      statusStyles = 'bg-amber-50 text-amber-700 border-amber-100/60';
      dotStatus = '<span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>';
    }

    // Dynamic colors for avatar initial background
    const colors = ['bg-blue-500', 'bg-indigo-500', 'bg-purple-500', 'bg-rose-500', 'bg-emerald-500', 'bg-slate-500', 'bg-amber-600'];
    let colorIndex = (fullName.charCodeAt(0) + fullName.charCodeAt(fullName.length - 1)) % colors.length;
    let avatarBg = colors[colorIndex];

    let avatarHTML = `
      <div class="h-9 w-9 rounded-xl ${avatarBg} text-white font-extrabold flex items-center justify-center text-xs shadow-xs border border-white/20 shrink-0">
        ${initials}
      </div>
    `;
    if (user.profile_picture && user.profile_picture !== 'default-avatar.png') {
      let pic = user.profile_picture;
      if (!pic.startsWith('http') && !pic.startsWith('data:')) {
        const bPath = window.civentralBasePath || '../../';
        pic = bPath + pic.replace(/^\/+/, '');
      }
      avatarHTML = `<img src="${pic}" alt="${fullName}" class="h-9 w-9 rounded-xl object-cover border border-slate-200 shadow-xs shrink-0">`;
    }

    const tr = document.createElement('tr');
    tr.className = 'hover:bg-slate-50/50 transition';
    tr.innerHTML = `
      <td class="px-6 py-3.5 flex items-center space-x-3">
        ${avatarHTML}
        <div class="flex flex-col">
          <span class="font-extrabold text-slate-800 tracking-tight leading-snug">${fullName}</span>
          <span class="text-[10px] text-slate-400 font-medium">${user.email}</span>
        </div>
      </td>
      <td class="px-6 py-3.5 font-mono text-[11px] font-bold text-slate-500">
        ${user.employee_id}
      </td>
      <td class="px-6 py-3.5">
        <div class="flex flex-col">
          <span class="font-semibold text-slate-700 leading-snug">${deptName}</span>
          <span class="text-[10px] text-slate-400 font-medium">${positionName}</span>
        </div>
      </td>
      <td class="px-6 py-3.5">
        <span class="text-[10px] font-black uppercase px-2 py-0.5 rounded-full border ${roleStyles}">
          ${roleName}
        </span>
      </td>
      <td class="px-6 py-3.5">
        <span class="text-[10px] font-black uppercase px-2.5 py-0.5 rounded-full border ${statusStyles} flex items-center gap-1.5 w-fit">
          ${dotStatus} ${user.status}
        </span>
      </td>
      <td class="px-6 py-3.5 text-right whitespace-nowrap">
        <div class="inline-flex items-center space-x-1">
          <button onclick="if(typeof openViewModal === 'function') openViewModal(${user.user_id})" class="text-slate-400 hover:text-brand-dark hover:bg-brand-light hover:border-brand-border/40 p-1.5 rounded-lg border border-transparent transition cursor-pointer" title="View Profile">
            <i class="fa-solid fa-eye text-xs"></i>
          </button>
          ${canEdit ? `
          <button onclick="if(typeof openEditModal === 'function') openEditModal(${user.user_id})" class="text-slate-400 hover:text-amber-600 hover:bg-amber-50 hover:border-amber-100 p-1.5 rounded-lg border border-transparent transition cursor-pointer" title="Edit Profile">
            <i class="fa-solid fa-pen text-xs"></i>
          </button>` : ''}
          <button onclick="if(typeof openHistoryModal === 'function') openHistoryModal(${user.user_id})" class="text-slate-400 hover:text-blue-600 hover:bg-blue-50 hover:border-blue-100 p-1.5 rounded-lg border border-transparent transition cursor-pointer" title="Security Audit Log">
            <i class="fa-solid fa-clock-rotate-left text-xs"></i>
          </button>
        </div>
      </td>
    `;
    tbody.appendChild(tr);
  });

  const pagEl = document.getElementById('paginationText');
  if (pagEl) {
    pagEl.innerText = `Showing 1 to ${usersList.length} of ${systemUsers.length} profiles`;
  }
}

// UPDATE METRICS BOARD
function updateMetrics() {
  const total = systemUsers.length;
  const active = systemUsers.filter(u => u.status === 'Active').length;
  const inactive = systemUsers.filter(u => u.status === 'Inactive' || u.status === 'Archived' || u.status === 'Deactivated').length;

  const uniqueDepts = new Set();
  systemUsers.forEach(u => {
    if (u.positions && u.positions.departments && u.positions.departments.department_name) {
      uniqueDepts.add(u.positions.departments.department_name);
    }
  });

  const statTotal = document.getElementById('statTotalUsers');
  const statActive = document.getElementById('statActiveUsers');
  const statDeactive = document.getElementById('statDeactivatedUsers');
  const statDepts = document.getElementById('statDepts');
  const statBadge = document.getElementById('statActiveBadge');

  if (statTotal) statTotal.innerText = total.toLocaleString();
  if (statActive) statActive.innerText = active.toLocaleString();
  if (statDeactive) statDeactive.innerText = inactive.toLocaleString();
  if (statDepts) statDepts.innerText = uniqueDepts.size.toLocaleString();

  const activePct = total > 0 ? Math.round((active / total) * 100) : 0;
  if (statBadge) {
    statBadge.innerHTML = `
      <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span> ${activePct}%
    `;
  }
}

// EXPORT TO CSV FROM REAL DATABASE RECORDS
function exportToCSV() {
  let csvContent = "data:text/csv;charset=utf-8,";
  csvContent += "Employee ID,First Name,Last Name,Email Address,Mobile Number,Department,Position,Role,Status,Created At\r\n";

  const searchInput = document.getElementById('searchInput');
  const roleFilter = document.getElementById('roleFilter');
  const deptFilter = document.getElementById('deptFilter');

  const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
  const roleVal = roleFilter ? roleFilter.value : '';
  const deptVal = deptFilter ? deptFilter.value : '';

  const filtered = systemUsers.filter(user => {
    const fullName = getUserFullName(user).toLowerCase();
    const email = (user.email || '').toLowerCase();
    const empId = (user.employee_id || '').toLowerCase();
    const matchQuery = fullName.includes(query) || email.includes(query) || empId.includes(query);

    const userRole = user.roles ? user.roles.role_name : '';
    const matchRole = roleVal === '' || userRole === roleVal;

    const userDept = (user.positions && user.positions.departments) ? user.positions.departments.department_name : '';
    const matchDept = deptVal === '' || userDept === deptVal;

    return matchQuery && matchRole && matchDept;
  });

  filtered.forEach(user => {
    const dept = (user.positions && user.positions.departments) ? user.positions.departments.department_name : '';
    const pos = user.positions ? user.positions.position_name : '';
    const role = user.roles ? user.roles.role_name : '';
    const created = user.created_at ? user.created_at.split('T')[0] : '';
    const row = `"${user.employee_id}","${user.first_name}","${user.last_name}","${user.email}","${user.mobile_number}","${dept}","${pos}","${role}","${user.status}","${created}"`;
    csvContent += row + "\r\n";
  });

  const encodedUri = encodeURI(csvContent);
  const link = document.createElement("a");
  link.setAttribute("href", encodedUri);
  link.setAttribute("download", `Civentral_User_Directory_${new Date().toISOString().split('T')[0]}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  if (typeof showToast === 'function') showToast(`Exported ${filtered.length} staff records to CSV.`);
}
