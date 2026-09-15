<?php
$basePath = '../../';
require_once __DIR__ . '/../../src/bootstrap.php';

$canCreateAccount = !empty($headerUser['is_superadmin']) || 
                          !empty($headerUser['is_global_access']) || 
                          (in_array('CREATE', $headerUser['granted_actions'] ?? []) && 
                           !empty($headerUser['granted_resources']) && array_filter($headerUser['granted_resources'], function($r) {
                               $rl = strtolower($r);
                               return strpos($rl, 'users account') !== false || strpos($rl, 'user account') !== false || strpos($rl, 'create account') !== false;
                           }));

if (!$canCreateAccount) {
    header("Location: user-directory.php");
    exit;
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<main class="flex-1 p-6 md:p-8 w-full space-y-6 overflow-y-auto">

  <!-- Breadcrumb & Page Header -->
  <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-200/60 pb-5">
    <div class="space-y-1">
      <div class="flex items-center space-x-2 text-xs font-bold uppercase tracking-wider text-slate-400">
        <span>User Management</span>
        <i class="fa-solid fa-chevron-right text-[8px] opacity-60"></i>
        <a href="user-directory.php" class="hover:text-brand-dark transition">User Directory</a>
        <i class="fa-solid fa-chevron-right text-[8px] opacity-60"></i>
        <span class="text-brand-dark">Register User</span>
      </div>
      <div class="flex items-center space-x-3 mt-4">
        <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
          <i class="fa-solid fa-user-plus text-brand-dark"></i>
          Register New System User
        </h1>
      </div>
      <p class="text-xs text-slate-500 max-w-2xl leading-relaxed pl-11">
        Create a new centralized system account and assign appropriate organizational access privileges.
      </p>
    </div>
  </div>

  <!-- Form Wrapper -->
  <form id="createUserForm" onsubmit="handleCreateUser(event)" class="space-y-6">
    
    <!-- Role Notification Box -->
    <div id="roleAlertBox" class="bg-blue-50 border border-blue-200 text-blue-800 rounded-2xl p-4 flex items-start gap-3 shadow-xs transition duration-300">
      <i class="fa-solid fa-circle-info text-blue-500 text-base mt-0.5"></i>
      <div class="space-y-1 text-xs">
        <p class="font-bold">System Role Scope Clearance Notice</p>
        <p class="leading-relaxed text-blue-600">As a Superadmin, you can register users for all departments. Department Admins will be locked to their specific department scope.</p>
      </div>
    </div>

    <!-- Two-Column Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- Section A: Personal & Contact Information -->
      <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-xs space-y-4">
        <div class="flex items-center space-x-2 border-b border-slate-100 pb-3">
          <div class="h-6 w-6 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-500">
            <i class="fa-solid fa-address-card text-xs"></i>
          </div>
          <h2 class="text-xs font-black uppercase tracking-wider text-slate-700">Personal & Contact Info</h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- First Name -->
          <div class="space-y-1.5">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">First Name</label>
            <input type="text" id="firstName" required placeholder="e.g. Juan" class="border border-slate-200 rounded-xl px-3 py-2.5 text-xs w-full focus:outline-none focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/10 transition">
          </div>
          <!-- Last Name -->
          <div class="space-y-1.5">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Last Name</label>
            <input type="text" id="lastName" required placeholder="e.g. Dela Cruz" class="border border-slate-200 rounded-xl px-3 py-2.5 text-xs w-full focus:outline-none focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/10 transition">
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- Middle Name -->
          <div class="space-y-1.5">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Middle Name <span class="text-[9px] font-medium text-slate-400">(Optional)</span></label>
            <input type="text" id="middleName" placeholder="e.g. Santos" class="border border-slate-200 rounded-xl px-3 py-2.5 text-xs w-full focus:outline-none focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/10 transition">
          </div>
          <!-- Employee ID -->
          <div class="space-y-1.5">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Employee ID</label>
            <div class="relative">
              <input type="text" id="empId" readonly required placeholder="Auto-generated when required fields are filled..." class="border border-slate-200 bg-slate-50/80 rounded-xl pl-3 pr-10 py-2.5 text-xs w-full focus:outline-none transition font-mono font-bold text-slate-700 cursor-not-allowed">
              <button type="button" id="btnGenerateEmpId" disabled onclick="autoGenerateEmpId()" class="absolute right-2 top-1/2 -translate-y-1/2 h-7 w-7 rounded-lg border border-slate-200 bg-slate-50 opacity-40 cursor-not-allowed flex items-center justify-center text-slate-400 transition" title="Fill all required fields to auto-generate Employee ID">
                <i class="fa-solid fa-wand-magic-sparkles text-[10px]"></i>
              </button>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- Email Address -->
          <div class="space-y-1.5">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Professional Email</label>
            <div class="relative">
              <i class="fa-solid fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
              <input type="email" id="email" required placeholder="e.g. juan.delacruz@caloocancity.gov.ph" class="border border-slate-200 rounded-xl pl-9 pr-3 py-2.5 text-xs w-full focus:outline-none focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/10 transition">
            </div>
          </div>
          <!-- Mobile Number -->
          <div class="space-y-1.5">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Mobile Contact Number</label>
            <div class="relative">
              <i class="fa-solid fa-phone absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
              <input type="text" id="mobileNumber" required placeholder="e.g. +63 917 123 4567" class="border border-slate-200 rounded-xl pl-9 pr-3 py-2.5 text-xs w-full focus:outline-none focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/10 transition">
            </div>
          </div>
        </div>
      </div>

      <!-- Section B: Department, Position, & Role -->
      <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-xs space-y-4">
        <div class="flex items-center space-x-2 border-b border-slate-100 pb-3">
          <div class="h-6 w-6 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-500">
            <i class="fa-solid fa-briefcase text-xs"></i>
          </div>
          <h2 class="text-xs font-black uppercase tracking-wider text-slate-700">Assignment & Role settings</h2>
        </div>

        <!-- Department Select -->
        <div class="space-y-1.5">
          <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Assign Department</label>
          <select id="department" required class="border border-slate-200 rounded-xl px-3 py-2.5 text-xs w-full bg-white focus:outline-none focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/10 transition cursor-pointer font-semibold text-slate-700">
            <option value="">Choose department...</option>
          </select>
        </div>

        <!-- Position Input -->
        <div class="space-y-1.5">
          <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Assign Designation / Position</label>
          <select id="position" required class="border border-slate-200 rounded-xl px-3 py-2.5 text-xs w-full bg-white focus:outline-none focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/10 transition cursor-pointer font-medium text-slate-800">
            <option value="">Choose position...</option>
          </select>
        </div>

        <!-- System Role Select -->
        <div class="space-y-1.5">
          <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">System Access Role</label>
          <select id="role" required onchange="handleRoleChange()" class="border border-slate-200 rounded-xl px-3 py-2.5 text-xs w-full bg-white focus:outline-none focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/10 transition cursor-pointer font-semibold text-slate-700">
            <option value="">Choose system role...</option>
          </select>
        </div>
      </div>

    </div>

    <!-- Registration Note Banner -->
    <div class="bg-slate-50 border border-slate-200 text-slate-500 rounded-2xl p-4 flex items-start gap-3 shadow-xs">
      <i class="fa-solid fa-circle-info text-slate-400 text-sm mt-0.5"></i>
      <span class="text-xs leading-relaxed"><strong>Note:</strong> Upon account creation, a temporary password and setup link will be automatically generated and sent to the registered email address.</span>
    </div>

    <!-- Action Footer buttons -->
    <div class="border-t border-slate-200 pt-5 flex items-center justify-end space-x-3">
      <a href="user-directory.php" class="border border-slate-200 bg-white hover:bg-slate-50 text-slate-600 px-5 py-2.5 rounded-xl text-xs font-bold cursor-pointer transition shadow-xs">Cancel</a>
      
      <button type="submit" id="submitBtn" class="bg-[#0f172a] hover:bg-slate-800 text-white font-bold px-5 py-2.5 rounded-xl text-xs flex items-center gap-2 cursor-pointer transition shadow-xs">
        <i id="spinnerIcon" class="fa-solid fa-circle-notch animate-spin hidden"></i>
        <span>Create Account & Save</span>
      </button>
    </div>

  </form>

</main>

<!-- ACCOUNT CREATION SUCCESS MODAL -->
<div id="successModal" class="fixed inset-0 z-50 flex items-center justify-center opacity-0 pointer-events-none transition-all duration-300 bg-slate-900/60 backdrop-blur-xs">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden transform scale-95 transition-all duration-300">
    <div class="bg-emerald-600 text-white px-6 py-4 flex items-center justify-between">
      <div class="flex items-center space-x-2">
        <i class="fa-solid fa-circle-check text-white text-base"></i>
        <h3 class="font-extrabold text-sm tracking-tight uppercase">Account Successfully Created</h3>
      </div>
      <button onclick="closeSuccessModal()" class="text-emerald-100 hover:text-white transition cursor-pointer text-sm">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
    <div class="p-6 space-y-4 text-xs">
      <div class="bg-emerald-50 border border-emerald-100 text-emerald-800 p-4 rounded-xl space-y-1">
        <p class="font-bold text-sm" id="modalUserName">John Doe</p>
        <p class="text-[11px] text-emerald-700" id="modalUserEmail">john.doe@caloocancity.gov.ph</p>
      </div>

      <div class="grid grid-cols-2 gap-3 border-y border-slate-100 py-3">
        <div>
          <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Assigned Employee ID</span>
          <span id="modalEmpId" class="font-mono font-extrabold text-slate-800 text-sm">SDA-2026-002</span>
        </div>
        <div>
          <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">System Access Role</span>
          <span id="modalRole" class="font-bold text-slate-700">Department Admin</span>
        </div>
      </div>

      <div class="space-y-1.5 bg-slate-50 border border-slate-200/80 p-3.5 rounded-xl">
        <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Generated Temporary Password</span>
        <div class="flex items-center justify-between font-mono font-bold text-slate-900 bg-white border border-slate-200 px-3 py-2 rounded-lg text-sm">
          <span id="modalTempPass">Civentral@1234</span>
          <button type="button" onclick="copyModalPassword()" class="text-brand-dark hover:text-brand-medium text-xs font-sans font-bold flex items-center gap-1 cursor-pointer">
            <i class="fa-regular fa-copy"></i> Copy
          </button>
        </div>
      </div>

      <p class="text-[11px] text-slate-400 text-center leading-relaxed">
        <i class="fa-solid fa-paper-plane text-brand-dark mr-1"></i> An email notification containing these credentials has been sent to the user's mailbox.
      </p>
    </div>
    <div class="bg-slate-50 border-t border-slate-100 px-6 py-4 flex items-center justify-between gap-3">
      <button type="button" onclick="resetCreateForm()" class="border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-4 py-2 rounded-xl text-xs font-bold transition cursor-pointer flex-1">Create Another</button>
      <a href="user-directory.php" class="bg-slate-900 hover:bg-slate-800 text-white px-4 py-2 rounded-xl text-xs font-bold transition cursor-pointer text-center flex-1">User Directory</a>
    </div>
  </div>
</div>

<div id="toast" class="fixed bottom-4 right-4 z-50 bg-slate-900 text-white text-xs font-bold px-4 py-3.5 rounded-xl shadow-lg flex items-center gap-3 transform translate-y-4 opacity-0 pointer-events-none transition-all duration-300">
  <div id="toastIconBox" class="h-5 w-5 rounded-full bg-emerald-500 flex items-center justify-center text-white text-[10px]">
    <i id="toastIconSymbol" class="fa-solid fa-check"></i>
  </div>
  <span id="toastMsg" class="tracking-wide">Profile updated successfully.</span>
</div>

<script src="<?php echo $basePath ?? '../'; ?>assets/js/usermanagement/create-account.js"></script>

<?php include '../../includes/footer.php'; ?>
