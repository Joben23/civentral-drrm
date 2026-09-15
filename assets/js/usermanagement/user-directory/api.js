// USER DIRECTORY DATA API

var systemUsers = [];
var availableRoles = [];
var availablePositions = [];
var availableDepartments = [];
var currentUserScope = null;

// FETCH ALL USERS, ROLES, POSITIONS & DEPARTMENTS FROM Database API
async function fetchUsersData() {
  try {
    const response = await fetch('../../api/employee/users.php');
    const result = await response.json();

    if (result.status === 'success') {
      systemUsers = result.data || [];
      availableRoles = result.roles || [];
      availablePositions = result.positions || [];
      availableDepartments = result.departments || [];
      currentUserScope = result.current_user || null;

      if (typeof populateFilterOptions === 'function') populateFilterOptions();
      if (typeof populateEditFormOptions === 'function') populateEditFormOptions();
      if (typeof filterAndSearch === 'function') filterAndSearch();
      if (typeof updateMetrics === 'function') updateMetrics();
    } else {
      console.warn('Fetch users notice:', result.message);
      if (typeof showToast === 'function') showToast('Notice loading user records.', true);
    }
  } catch (err) {
    console.error('Error fetching users from Database API:', err);
    if (typeof showToast === 'function') showToast('Network error connecting to Database.', true);
  }
}

// EDIT STAFF API CALL
async function handleEditStaff(e) {
  e.preventDefault();

  const userId = parseInt(document.getElementById('editEmpIdRef').value);
  if (!userId) return;

  const nameInput = document.getElementById('editName').value.trim();
  const email = document.getElementById('editEmail').value.trim();
  const phone = document.getElementById('editPhone').value.trim();
  const positionId = parseInt(document.getElementById('editPosition').value) || 0;
  const roleId = parseInt(document.getElementById('editRole').value) || 0;
  const departmentId = parseInt(document.getElementById('editDept').value) || 0;
  const status = document.getElementById('editStatus').value;

  const nameParts = nameInput.split(/\s+/);
  const firstName = nameParts[0] || '';
  const lastName = nameParts.length > 1 ? nameParts.slice(1).join(' ') : '';

  const payload = {
    user_id: userId,
    first_name: firstName,
    last_name: lastName,
    email: email,
    mobile_number: phone,
    department_id: departmentId,
    role_id: roleId,
    position_id: positionId,
    status: status
  };

  try {
    const response = await fetch('../../api/employee/users.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const result = await response.json();

    if (result.status === 'success') {
      if (typeof showToast === 'function') showToast(`Successfully updated user profile for ${firstName} ${lastName}`);
      if (typeof closeModal === 'function') closeModal('editModal');
      await fetchUsersData();
    } else {
      if (typeof showToast === 'function') showToast(result.message || 'Error updating user profile.', true);
    }
  } catch (err) {
    console.error('Update user error:', err);
    if (typeof showToast === 'function') showToast('Failed to update user profile TO DATABASE.', true);
  }
}
