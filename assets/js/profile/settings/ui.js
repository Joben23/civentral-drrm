// Settings UI
// Responsible for DOM manipulation and updating the user interface for settings

function populateProfileUI() {
  if (!userProfile) return;

  const defaultAvatar = `https://ui-avatars.com/api/?name=${encodeURIComponent(userProfile.full_name || 'User')}&background=EEF5FF&color=176B87&bold=true&size=128&font-size=0.42`;
  
  const avatarImg = document.getElementById('avatarPreview');
  if (avatarImg) {
    let pic = userProfile.profile_picture || 'default-avatar.png';
    if (pic !== 'default-avatar.png' && !pic.startsWith('http') && !pic.startsWith('data:')) {
      const bPath = window.civentralBasePath || '../../';
      pic = bPath + pic.replace(/^\/+/, '');
    } else if (pic === 'default-avatar.png') {
      pic = defaultAvatar;
    }
    avatarImg.src = pic;
  }

  // Sidebar card
  const sName = document.getElementById('sidebarName');
  if (sName) sName.innerText = userProfile.full_name || '';

  const sRole = document.getElementById('sidebarRole');
  if (sRole) sRole.innerText = userProfile.role_name || 'Not assigned';

  // Account summary
  const sumEmpId = document.getElementById('summaryEmpId');
  if (sumEmpId) sumEmpId.innerText = userProfile.employee_id || '';

  const sumRole = document.getElementById('summaryRole');
  if (sumRole) sumRole.innerText = userProfile.role_name || '';

  const sumDept = document.getElementById('summaryDept');
  if (sumDept) sumDept.innerText = userProfile.department_name || '';

  const sumStatus = document.getElementById('summaryStatus');
  if (sumStatus) sumStatus.innerText = userProfile.status || 'Not available';

  const sumMemberSince = document.getElementById('summaryMemberSince');
  if (sumMemberSince) sumMemberSince.innerText = userProfile.created_date || 'Not available';

  // Editable input fields
  const fName = document.getElementById('fieldFullName');
  if (fName) fName.value = userProfile.full_name || '';

  const fPhone = document.getElementById('fieldPhone');
  if (fPhone) fPhone.value = userProfile.mobile_number || '';

  const fEmail = document.getElementById('fieldEmail');
  if (fEmail) fEmail.value = userProfile.email || '';

  const fPosition = document.getElementById('fieldPosition');
  if (fPosition) fPosition.value = userProfile.position_name || '';
}

function discardChanges() {
  populateProfileUI();
  currentAvatarBase64 = null;
  const avatarInput = document.getElementById('avatarFileInput');
  if (avatarInput) avatarInput.value = '';
  showToast('Changes discarded.', 'info', 'All fields restored to last saved values.');
}


