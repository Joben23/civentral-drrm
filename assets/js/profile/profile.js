// Profile Page Main Script - With Bootstrap logic to load shared resources
window.loadCiventralScript('assets/js/profile/shared/toast.js');
window.loadCiventralScript('assets/js/profile/shared/profile-api.js', () => {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fetchUserProfile);
  } else {
    fetchUserProfile();
  }
});

async function fetchUserProfile() {
  const result = await civentralFetchProfile('../../api/employee/get-profile.php');

  if (result.status === 'success' && result.data) {
    const u = result.data;

    // Avatar & Headings
    const imgEl = document.getElementById('profileAvatarImg');
    const initialsEl = document.getElementById('profileAvatarInitials');
    if (u.profile_picture && u.profile_picture !== 'default-avatar.png') {
      if (imgEl && initialsEl) {
        let pic = u.profile_picture;
        if (!pic.startsWith('http') && !pic.startsWith('data:')) {
          const bPath = window.civentralBasePath || '../../';
          pic = bPath + pic.replace(/^\/+/, '');
        }
        imgEl.src = pic;
        imgEl.classList.remove('hidden');
        initialsEl.classList.add('hidden');
      }
    } else {
      if (imgEl && initialsEl) {
        imgEl.classList.add('hidden');
        initialsEl.classList.remove('hidden');
        initialsEl.textContent = u.initials || '--';
      }
    }

    if (document.getElementById('profileNameHeading')) {
      document.getElementById('profileNameHeading').textContent = u.full_name || 'Not available';
    }
    if (document.getElementById('profileRoleHeading')) {
      document.getElementById('profileRoleHeading').textContent = u.role_name || 'Not assigned';
    }
    if (document.getElementById('profileDepartmentHeading')) {
      document.getElementById('profileDepartmentHeading').textContent = u.department_name || 'Not assigned';
    }

    // Metrics & Sessions
    if (document.getElementById('profileLastLogin')) {
      document.getElementById('profileLastLogin').textContent = u.last_login || 'Not available';
    }
    if (document.getElementById('profileTotalLogins')) {
      document.getElementById('profileTotalLogins').textContent = (u.total_logins ?? 0) + ' Logins';
    }

    // Detailed Info Grid
    if (document.getElementById('profileFullName')) {
      document.getElementById('profileFullName').textContent = u.full_name || 'Not available';
    }
    if (document.getElementById('profileEmployeeId')) {
      document.getElementById('profileEmployeeId').textContent = u.employee_id || 'Not available';
    }
    if (document.getElementById('profileEmail')) {
      document.getElementById('profileEmail').textContent = u.email || 'Not available';
    }
    if (document.getElementById('profileMobile')) {
      document.getElementById('profileMobile').textContent = u.mobile_number || 'Not available';
    }
    if (document.getElementById('profileDepartment')) {
      document.getElementById('profileDepartment').textContent = u.department_name || 'Not assigned';
    }
    if (document.getElementById('profilePosition')) {
      document.getElementById('profilePosition').textContent = u.position_name || 'Not assigned';
    }
    if (document.getElementById('profileRole')) {
      document.getElementById('profileRole').textContent = u.role_name || 'Not assigned';
    }
    if (document.getElementById('profileStatusBadge')) {
      document.getElementById('profileStatusBadge').textContent = u.status || 'Not available';
    }
  } else if (result.message) {
    if (typeof showToast === 'function') {
      showToast(result.message, 'error');
    }
  }
}
