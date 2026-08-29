<?php
$basePath = '../../';
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
        <span class="text-brand-dark">My Profile</span>
      </div>
      <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2 mt-4">
        <i class="fa-solid fa-user-circle text-brand-dark"></i>
        My Profile Overview
      </h1>
      <p class="text-xs text-slate-500 max-w-2xl leading-relaxed">
        View your centralized CIVENTRAL account information, security credentials, and assigned municipal scope.
      </p>
    </div>
  </div>

  <!-- Profile Workspace Split Grid -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
    
    <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-xs space-y-6 text-center">
      
      <!-- Profile Photo Avatar container -->
      <div class="relative w-32 h-32 mx-auto">
        <div class="w-full h-full rounded-full bg-brand-light border-2 border-brand-border flex items-center justify-center text-brand-dark font-black text-3xl select-none group cursor-pointer overflow-hidden relative">
          <span id="profileAvatarInitials">--</span>
          <img id="profileAvatarImg" src="" alt="Profile Photo" class="hidden w-full h-full object-cover">
          <!-- Subtle Hover Overlay -->
          <div class="absolute inset-0 bg-slate-900/60 opacity-0 group-hover:opacity-100 flex items-center justify-center text-white text-[10px] font-bold uppercase tracking-wider transition-opacity duration-300">
            <span>View Photo</span>
          </div>
        </div>
        <!-- Account status is populated from the external employee profile. -->
        <span id="profileStatusDot" class="absolute bottom-1 right-1 h-5 w-5 rounded-full bg-slate-300 border-4 border-white flex items-center justify-center" title="Account status unavailable"></span>
      </div>

      <!-- User Main Info details -->
      <div class="space-y-1.5">
        <h3 id="profileNameHeading" class="text-lg font-black text-slate-900 tracking-tight">Loading Profile...</h3>
        <div class="flex items-center justify-center gap-1.5">
          <span id="profileRoleHeading" class="bg-slate-900 text-white text-[9px] font-black uppercase px-2 py-0.5 rounded tracking-wide border border-slate-950">Loading...</span>
          <span class="text-slate-300">|</span>
          <span id="profileDepartmentHeading" class="text-[11px] font-bold text-slate-400">Loading...</span>
        </div>
      </div>

      <!-- Last Active Card -->
      <div class="bg-slate-50 border border-slate-200/40 rounded-xl p-4 text-left space-y-1">
        <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Last Active Session</span>
        <div class="flex items-center gap-2 text-xs font-extrabold text-slate-800">
          <i class="fa-solid fa-clock-rotate-left text-brand-dark text-xs"></i>
          <span id="profileLastLogin">Loading...</span>
        </div>
      </div>

      <!-- Total Logins Card -->
      <div class="bg-slate-50 border border-slate-200/40 rounded-xl p-4 text-left space-y-1">
        <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Total Logins</span>
        <div class="flex items-center gap-2 text-xs font-extrabold text-slate-800">
          <i class="fa-solid fa-right-to-bracket text-emerald-600 text-xs"></i>
          <span id="profileTotalLogins">Loading...</span>
        </div>
      </div>

    </div>

    <!-- Detailed Information Grid -->
    <div class="lg:col-span-2 bg-white border border-slate-200/80 rounded-2xl shadow-xs overflow-hidden">
      <div class="bg-slate-50 border-b border-slate-100 px-6 py-4">
        <h3 class="text-xs font-black uppercase tracking-wider text-slate-700">Account Credentials & Credentials Details</h3>
      </div>

      <div class="p-6 space-y-6">
        
        <!-- Personal Details -->
        <div class="space-y-4">
          <h4 class="text-[10px] font-black text-brand-dark uppercase tracking-wider border-b border-slate-100 pb-2">
            <i class="fa-solid fa-user-gear mr-1"></i> Personal Profile details
          </h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Full name -->
            <div class="bg-slate-50 border border-slate-200/40 rounded-xl p-3.5 space-y-1">
              <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Full Name</span>
              <span id="profileFullName" class="text-xs font-extrabold text-slate-800">Loading...</span>
            </div>
            <!-- Username -->
            <div class="bg-slate-50 border border-slate-200/40 rounded-xl p-3.5 space-y-1">
              <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Employee ID / Identifier</span>
              <span id="profileEmployeeId" class="text-xs font-mono font-bold text-slate-700">Loading...</span>
            </div>
            <!-- Email Address -->
            <div class="bg-slate-50 border border-slate-200/40 rounded-xl p-3.5 space-y-1">
              <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Authorized Email Address</span>
              <span id="profileEmail" class="text-xs font-extrabold text-slate-800">Loading...</span>
            </div>
            <!-- Contact Number -->
            <div class="bg-slate-50 border border-slate-200/40 rounded-xl p-3.5 space-y-1">
              <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Assigned Contact Mobile</span>
              <span id="profileMobile" class="text-xs font-extrabold text-slate-800">Loading...</span>
            </div>
          </div>
        </div>

        <!--  Administrative Bounds -->
        <div class="space-y-4">
          <h4 class="text-[10px] font-black text-brand-dark uppercase tracking-wider border-b border-slate-100 pb-2">
            <i class="fa-solid fa-lock mr-1"></i> Administrative Boundaries & Roles
          </h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Department -->
            <div class="bg-slate-50 border border-slate-200/40 rounded-xl p-3.5 space-y-1">
              <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Assigned Department</span>
              <span id="profileDepartment" class="text-xs font-extrabold text-slate-800">Loading...</span>
            </div>
            <!-- Position -->
            <div class="bg-slate-50 border border-slate-200/40 rounded-xl p-3.5 space-y-1">
              <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Position Rank</span>
              <span id="profilePosition" class="text-xs font-extrabold text-slate-800">Loading...</span>
            </div>
            <!-- Assigned System Role -->
            <div class="bg-slate-50 border border-slate-200/40 rounded-xl p-3.5 space-y-1">
              <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Assigned System Role</span>
              <span id="profileRole" class="text-xs font-extrabold text-slate-800">Loading...</span>
            </div>
            <!-- Account Status -->
            <div class="bg-slate-50 border border-slate-200/40 rounded-xl p-3.5 space-y-1">
              <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Account Status badge</span>
              <span id="profileStatusBadge" class="bg-slate-50 text-slate-600 px-2 py-0.5 rounded text-[10px] font-black border border-slate-200 inline-flex items-center gap-1">Loading...</span>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>

</main>

<!-- TOAST POPUP  -->
<div id="toast" class="fixed bottom-4 right-4 z-50 bg-slate-900 text-white text-xs font-bold px-4 py-3.5 rounded-xl shadow-lg flex items-center gap-3 transform translate-y-4 opacity-0 pointer-events-none transition-all duration-300">
  <div class="h-5 w-5 rounded-full bg-emerald-500 flex items-center justify-center text-white text-[10px]">
    <i class="fa-solid fa-check"></i>
  </div>
  <span id="toastMsg" class="tracking-wide">Action executed successfully.</span>
</div>



<?php include '../../includes/footer.php'; ?>
