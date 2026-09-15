// USER DIRECTORY MODALS

// MODAL CONTROL HELPERS
function openModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.remove('opacity-0', 'pointer-events-none');
  modal.classList.add('opacity-100', 'pointer-events-auto');
  const card = modal.querySelector('.transform');
  if (card) {
    card.classList.remove('scale-95');
    card.classList.add('scale-100');
  }
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.remove('opacity-100', 'pointer-events-auto');
  modal.classList.add('opacity-0', 'pointer-events-none');
  const card = modal.querySelector('.transform');
  if (card) {
    card.classList.remove('scale-100');
    card.classList.add('scale-95');
  }
}

// VIEW PROFILE MODAL
function openViewModal(userId) {
  const user = systemUsers.find(u => u.user_id === userId);
  if (!user) return;

  const fullName = typeof getUserFullName === 'function' ? getUserFullName(user) : '';
  const initials = typeof getUserInitials === 'function' ? getUserInitials(user) : '';
  const roleName = user.roles ? user.roles.role_name : 'Staff';
  const posName = user.positions ? user.positions.position_name : 'Unassigned';
  const deptName = (user.positions && user.positions.departments) ? user.positions.departments.department_name : 'Unassigned';

  document.getElementById('viewName').innerText = fullName;
  document.getElementById('viewEmail').innerText = user.email;
  document.getElementById('viewEmpId').innerText = user.employee_id;
  document.getElementById('viewPhone').innerText = user.mobile_number || 'N/A';
  document.getElementById('viewDept').innerText = deptName;
  document.getElementById('viewPosition').innerText = posName;
  document.getElementById('viewCreated').innerText = user.created_at ? user.created_at.split('T')[0] : 'N/A';

  const imgEl = document.getElementById('viewAvatarImg');
  const textEl = document.getElementById('viewInitialsText');

  if (user.profile_picture && user.profile_picture !== 'default-avatar.png') {
    if (imgEl && textEl) {
      imgEl.src = user.profile_picture;
      imgEl.classList.remove('hidden');
      textEl.classList.add('hidden');
    }
  } else {
    if (imgEl && textEl) {
      imgEl.classList.add('hidden');
      textEl.classList.remove('hidden');
      textEl.innerText = initials;
    }
  }

  const roleBadge = document.getElementById('viewRoleBadge');
  if (roleBadge) {
    roleBadge.innerText = roleName;
    roleBadge.className = "text-[9px] font-black uppercase px-2 py-0.5 rounded-full border ";
    if (roleName === 'Super Admin' || roleName === 'Superadmin') roleBadge.classList.add('bg-purple-50', 'text-purple-700', 'border-purple-100');
    else if (roleName.includes('Admin')) roleBadge.classList.add('bg-blue-50', 'text-blue-700', 'border-blue-100');
    else roleBadge.classList.add('bg-slate-50', 'text-slate-600', 'border-slate-200');
  }

  const statusBadge = document.getElementById('viewStatusBadge');
  if (statusBadge) {
    statusBadge.innerText = user.status;
    statusBadge.className = "text-[9px] font-black uppercase px-2 py-0.5 rounded-full border ";
    if (user.status === 'Active') statusBadge.classList.add('bg-emerald-50', 'text-emerald-700', 'border-emerald-100');
    else statusBadge.classList.add('bg-rose-50', 'text-rose-600', 'border-rose-100');
  }

  const permList = document.getElementById('viewPermissionsList');
  if (permList) {
    permList.innerHTML = `
      <div class="flex items-center space-x-2 bg-slate-50 p-2 border border-slate-100 rounded-lg">
        <i class="fa-solid fa-circle-check text-emerald-500 shrink-0 text-[10px]"></i>
        <span class="text-[11px] font-semibold text-slate-600">Employee Role Access Granted</span>
      </div>
      <div class="flex items-center space-x-2 bg-slate-50 p-2 border border-slate-100 rounded-lg">
        <i class="fa-solid fa-circle-check text-emerald-500 shrink-0 text-[10px]"></i>
        <span class="text-[11px] font-semibold text-slate-600">${user.roles && user.roles.is_global_access ? 'Global Access Scope' : 'Department Access Scope'}</span>
      </div>
    `;
  }

  openModal('viewModal');
}

// EDIT STAFF ACCOUNT MODAL
function openEditModal(userId) {
  const isSuperAdmin = currentUserScope ? !!currentUserScope.is_superadmin : false;
  const grantedActions = currentUserScope ? (currentUserScope.granted_actions || []) : [];
  const canEdit = isSuperAdmin || grantedActions.includes('EDIT');

  if (!canEdit) {
    if (typeof showToast === 'function') showToast('Forbidden. View-only access level cannot modify user accounts.', true);
    return;
  }

  const user = systemUsers.find(u => u.user_id === userId);
  if (!user) return;

  const fullName = typeof getUserFullName === 'function' ? getUserFullName(user) : '';

  document.getElementById('editEmpIdRef').value = user.user_id;
  document.getElementById('editName').value = fullName;
  document.getElementById('editEmail').value = user.email;
  document.getElementById('editPhone').value = user.mobile_number || '';
  
  const currentDeptId = (user.positions && user.positions.department_id) ? user.positions.department_id : '';
  const currentPosId = user.position_id || '';

  const editDept = document.getElementById('editDept');
  if (editDept) editDept.value = currentDeptId;

  if (typeof updatePositionDropdown === 'function') updatePositionDropdown(currentDeptId, currentPosId);

  const editRole = document.getElementById('editRole');
  if (editRole) editRole.value = user.role_id || '';

  const editStatus = document.getElementById('editStatus');
  if (editStatus) editStatus.value = user.status || 'Active';

  if (editDept && editDept.options.length === 1) editDept.selectedIndex = 0;

  openModal('editModal');
}

// SECURITY LOGIN AUDIT LOGS MODAL
function openHistoryModal(userId) {
  const user = systemUsers.find(u => u.user_id === userId);
  if (!user) return;

  const fullName = typeof getUserFullName === 'function' ? getUserFullName(user) : '';
  document.getElementById('historyStaffName').innerText = `${fullName} (${user.employee_id})`;
  
  const hBody = document.getElementById('historyTableBody');
  hBody.innerHTML = '';

  if (user.login_history && user.login_history.length > 0) {
    user.login_history.forEach(log => {
      const loginTime = log.login_time ? log.login_time.replace('T', ' ').substring(0, 19) : '—';
      hBody.innerHTML += `
        <tr class="hover:bg-slate-50/50 transition">
          <td class="px-4 py-3 font-medium text-slate-700">${loginTime}</td>
          <td class="px-4 py-3 font-mono font-bold text-slate-500">${log.ip_address || '—'}</td>
          <td class="px-4 py-3 text-slate-500">${log.browser || log.device_info || 'Browser Terminal'}</td>
          <td class="px-4 py-3 text-right">
            <span class="text-[9px] font-black uppercase ${log.login_status === 'Failed' ? 'bg-rose-50 border-rose-100 text-rose-700' : 'bg-emerald-50 border-emerald-100 text-emerald-700'} border px-1.5 py-0.5 rounded-md">
              ${log.login_status || 'Success'}
            </span>
          </td>
        </tr>
      `;
    });
  } else {
    hBody.innerHTML = `
      <tr>
        <td colspan="4" class="px-4 py-6 text-center text-slate-400">
          No security login history records found IN DATABASE.
        </td>
      </tr>
    `;
  }

  openModal('historyModal');
}
