// CREATE ACCOUNT UI

function applyUserScopeRules() {
  const deptSelect = document.getElementById('department');
  const roleAlertBox = document.getElementById('roleAlertBox');
  if (!currentUserScope) return;

  const isGlobalScope = !!currentUserScope.is_superadmin || !!currentUserScope.is_global_access;
  const userDeptId = currentUserScope.department_id;
  const userDeptName = currentUserScope.department_name || 'your department';

  if (!isGlobalScope && userDeptId && deptSelect) {
    deptSelect.value = userDeptId;
    deptSelect.disabled = true;
    deptSelect.classList.add('bg-slate-100', 'cursor-not-allowed', 'text-slate-500');

    if (roleAlertBox) {
      roleAlertBox.innerHTML = `
        <i class="fa-solid fa-lock text-amber-500 text-base mt-0.5"></i>
        <div class="space-y-1 text-xs">
          <p class="font-bold text-amber-900">Department Scope Clearance Notice</p>
          <p class="leading-relaxed text-amber-700">As a Department Administrator for <strong>${userDeptName}</strong>, your account creation scope is locked to your department. Available system access roles are restricted to roles for your department.</p>
        </div>
      `;
      roleAlertBox.className = "bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl p-4 flex items-start gap-3 shadow-xs transition duration-300";
    }

    if (typeof fetchRolesForDepartment === 'function') {
      fetchRolesForDepartment(userDeptId);
    }
    if (typeof populatePositions === 'function') populatePositions(userDeptId);
  } else if (isGlobalScope && deptSelect) {
    deptSelect.disabled = false;
    deptSelect.onchange = function() {
      const selectedDeptId = this.value;
      if (typeof fetchRolesForDepartment === 'function') {
        fetchRolesForDepartment(selectedDeptId);
      }
      if (typeof populatePositions === 'function') populatePositions(selectedDeptId);
    };
  }

  if (deptSelect.options.length === 1) deptSelect.selectedIndex = 0;
}

// POPULATE POSITIONS SELECT
function populatePositions(deptId = '') {
  const positionSelect = document.getElementById('position');
  if (!positionSelect) return;

  const isGlobalScope = currentUserScope
    ? (!!currentUserScope.is_superadmin || !!currentUserScope.is_global_access)
    : false;
  const userDeptId = currentUserScope ? currentUserScope.department_id : null;
  const effectiveDeptId = !isGlobalScope && userDeptId ? userDeptId : deptId;
  const currentVal = positionSelect.value;

  positionSelect.innerHTML = '<option value="">Choose position...</option>';
  systemPositions
    .filter(p => !effectiveDeptId || String(p.department_id) === String(effectiveDeptId))
    .forEach(p => {
      const opt = document.createElement('option');
      opt.value = p.position_name;
      opt.textContent = p.position_name;
      positionSelect.appendChild(opt);
    });

  if (currentVal) positionSelect.value = currentVal;
}

// POPULATE ROLES SELECT
function populateRoles() {
  const roleSelect = document.getElementById('role');
  if (!roleSelect) return;
  const drrmRole = systemRoles.find(r =>
    String(r.role_id) === '18' &&
    String(r.role_name).trim() === 'DRRM Administrator' &&
    String(r.role_prefix).trim().toUpperCase() === 'DA' &&
    (!Object.prototype.hasOwnProperty.call(r, 'department_id') || String(r.department_id) === '9')
  );

  roleSelect.innerHTML = '';
  if (!drrmRole) return;

  const opt = document.createElement('option');
  opt.value = drrmRole.role_id;
  opt.dataset.prefix = drrmRole.role_prefix;
  opt.dataset.name = drrmRole.role_name;
  opt.textContent = `${drrmRole.role_name} (${drrmRole.role_prefix})`;
  opt.selected = true;
  roleSelect.appendChild(opt);
}

// POPULATE DEPARTMENTS SELECT
function populateDepartments() {
  const deptSelect = document.getElementById('department');
  if (!deptSelect) return;
  deptSelect.innerHTML = '';

  const drrmDepartment = systemDepartments.find(d =>
    String(d.department_id) === '9' &&
    String(d.department_name).trim() === 'Disaster Risk Reduction & Emergency Response'
  );
  if (!drrmDepartment) return;

  const opt = document.createElement('option');
  opt.value = drrmDepartment.department_id;
  opt.textContent = drrmDepartment.department_name;
  opt.selected = true;
  deptSelect.appendChild(opt);
}

// AUTO-GENERATE EMPLOYEE ID BASED ON ROLE PREFIX, YEAR & SEQUENCE (e.g. SDA-2026-002)
async function autoGenerateEmpId() {
  const roleSelect = document.getElementById('role');
  const empIdInput = document.getElementById('empId');
  const roleId = roleSelect ? roleSelect.value : '';

  if (!roleId) {
    if (typeof showToast === 'function') showToast('Please select a System Access Role first.', true);
    return;
  }

  try {
    const response = await fetch(`../../api/employee/users.php?action=generate_emp_id&role_id=${roleId}`);
    const data = await response.json();

    if (data.status === 'success' && data.employee_id) {
      if (empIdInput) empIdInput.value = data.employee_id;
      if (typeof showToast === 'function') showToast(`Auto-generated Employee ID: ${data.employee_id}`);
    } else {
      if (typeof showToast === 'function') showToast('Failed to generate Employee ID.', true);
    }
  } catch (err) {
    console.error('Error generating Employee ID:', err);
    if (typeof showToast === 'function') showToast('Network error calculating next Employee ID.', true);
  }
}
