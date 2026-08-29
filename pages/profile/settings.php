<?php
$basePath = '../../';
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<main class="flex-1 p-6 md:p-8 w-full space-y-6 overflow-y-auto">

  <!-- BREADCRUMB -->
  <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-200/60 pb-5">
    <div class="space-y-1">
      <div class="flex items-center space-x-2 text-xs font-bold uppercase tracking-wider text-slate-400">
        <span>My Account</span>
        <i class="fa-solid fa-chevron-right text-[8px] opacity-60"></i>
        <span class="text-brand-dark">Account Settings</span>
      </div>
      <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2 mt-4">
        <i class="fa-solid fa-sliders text-brand-dark"></i>
        Account Settings
      </h1>
      <p class="text-xs text-slate-500 max-w-2xl leading-relaxed">
        Manage your editable profile details, contact preferences, and profile avatar.
      </p>
    </div>
    <div class="shrink-0 flex items-center gap-2 bg-emerald-50 border border-emerald-200/80 text-emerald-700 text-[10px] font-black uppercase tracking-wider px-3 py-2 rounded-xl">
      <i class="fa-solid fa-circle-check text-emerald-500"></i>
      <span>Profile Synced</span>
    </div>
  </div>

  
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

    <div class="lg:col-span-1 space-y-4">

      <div class="bg-white border border-slate-200/80 rounded-2xl shadow-xs overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-200/60 px-5 py-3.5 flex items-center gap-2">
          <i class="fa-solid fa-circle-user text-brand-medium text-sm"></i>
          <span class="text-[10px] font-black text-slate-500 uppercase tracking-wider">Profile Avatar</span>
        </div>
        <div class="p-6 flex flex-col items-center gap-5">

          <div id="avatarWrapper"
               class="relative group cursor-pointer w-32 h-32 shrink-0"
               onclick="document.getElementById('avatarFileInput').click()"
               title="Click to change photo">
            <img
              id="avatarPreview"
              src="https://ui-avatars.com/api/?name=User&background=EEF5FF&color=176B87&bold=true&size=128&font-size=0.42"
              alt="Profile Avatar"
              class="w-32 h-32 rounded-full object-cover border-4 border-white shadow-lg ring-2 ring-brand-border transition-all duration-300 group-hover:ring-brand-medium"
            >
            <div class="absolute inset-0 rounded-full bg-slate-900/55 opacity-0 group-hover:opacity-100 transition-all duration-300 flex flex-col items-center justify-center gap-1.5 backdrop-blur-sm">
              <i class="fa-solid fa-camera text-white text-xl drop-shadow"></i>
              <span class="text-white text-[9px] font-black uppercase tracking-widest leading-none">Change Photo</span>
            </div>
          </div>

          <input type="file" id="avatarFileInput" accept="image/jpeg, image/png" class="hidden" onchange="previewAvatar(event)">

          <div class="text-center space-y-0.5">
            <p id="sidebarName" class="text-sm font-black text-slate-800 tracking-tight">...</p>
            <span id="sidebarRole" class="inline-block text-[9px] font-black uppercase tracking-widest text-brand-dark bg-brand-light border border-brand-border/60 px-2 py-0.5 rounded-full">...</span>
          </div>

          <div class="w-full space-y-2">
            <button onclick="document.getElementById('avatarFileInput').click()" class="w-full bg-[#0f172a] hover:bg-slate-800 text-white font-bold px-4 py-2.5 rounded-xl text-xs flex items-center justify-center gap-2 cursor-pointer transition shadow-xs">
              <i class="fa-solid fa-arrow-up-from-bracket text-[10px]"></i>
              Upload New Image
            </button>
            <button onclick="removePhoto()" class="w-full border border-red-200 hover:bg-red-50 text-red-500 hover:text-red-600 font-bold px-4 py-2.5 rounded-xl text-xs flex items-center justify-center gap-2 cursor-pointer transition">
              <i class="fa-solid fa-trash-can text-[10px]"></i>
              Remove Photo
            </button>
          </div>

          <p class="text-[10px] text-slate-400 text-center leading-relaxed font-medium">
            Supported formats: <span class="font-black text-slate-500">JPG, PNG</span>.<br>
            Max file size: <span class="font-black text-slate-500">2MB</span>.
          </p>
        </div>
      </div>

      <div class="bg-white border border-slate-200/80 rounded-2xl shadow-xs overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-200/60 px-5 py-3.5 flex items-center gap-2">
          <i class="fa-solid fa-id-badge text-brand-medium text-sm"></i>
          <span class="text-[10px] font-black text-slate-500 uppercase tracking-wider">Account Summary</span>
        </div>
        <div class="p-5 space-y-3.5">
          <div class="flex items-center justify-between text-xs">
            <span class="text-slate-400 font-semibold">Employee ID</span>
            <span id="summaryEmpId" class="font-black text-slate-700 font-mono tracking-wider">...</span>
          </div>
          <div class="border-t border-slate-100"></div>
          <div class="flex items-center justify-between text-xs">
            <span class="text-slate-400 font-semibold">Role</span>
            <span id="summaryRole" class="font-black text-slate-700">...</span>
          </div>
          <div class="border-t border-slate-100"></div>
          <div class="flex items-center justify-between text-xs">
            <span class="text-slate-400 font-semibold">Department</span>
            <span id="summaryDept" class="font-black text-slate-700">...</span>
          </div>
          <div class="border-t border-slate-100"></div>
          <div class="flex items-center justify-between text-xs">
            <span class="text-slate-400 font-semibold">Account Status</span>
            <span id="summaryStatus" class="inline-flex items-center gap-1 text-slate-600 bg-slate-50 border border-slate-200 font-black px-2 py-0.5 rounded-full text-[9px] uppercase tracking-wider">
              Loading...
            </span>
          </div>
          <div class="border-t border-slate-100"></div>
          <div class="flex items-center justify-between text-xs">
            <span class="text-slate-400 font-semibold">Member Since</span>
            <span id="summaryMemberSince" class="font-black text-slate-700">...</span>
          </div>
        </div>
      </div>

    </div>

    <div class="lg:col-span-2 space-y-5">
      <div class="bg-white border border-slate-200/80 rounded-2xl shadow-xs overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-200/60 px-6 py-4 flex items-center justify-between">
          <div class="flex items-center gap-2.5">
            <div class="h-7 w-7 rounded-lg bg-brand-light border border-brand-border flex items-center justify-center">
              <i class="fa-solid fa-user text-brand-dark text-xs"></i>
            </div>
            <div>
              <span class="text-xs font-black text-slate-700 block leading-none tracking-tight">Profile Details</span>
              <span class="text-[9px] text-slate-400 font-semibold uppercase tracking-wider">Identity &amp; Contact Information</span>
            </div>
          </div>
          <span class="text-[9px] font-black uppercase tracking-wider text-slate-400 bg-slate-100 border border-slate-200 px-2.5 py-1 rounded-lg">Profile Details</span>
        </div>

        <div class="p-6 space-y-6">

          <div class="space-y-2">
            <div class="flex items-center gap-2">
              <label for="fieldFullName" class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Full Name</label>
              <div class="relative group/tooltip">
                <span class="cursor-help"><i class="fa-solid fa-lock text-amber-500 text-[10px]"></i></span>
                <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-72 bg-slate-900 text-white text-[10px] font-semibold leading-relaxed px-3.5 py-2.5 rounded-xl shadow-xl opacity-0 group-hover/tooltip:opacity-100 transition-opacity duration-200 z-20">
                  <div class="flex items-start gap-2">
                    <i class="fa-solid fa-triangle-exclamation text-amber-400 shrink-0 mt-0.5"></i>
                    <span>Name modifications require <strong class="text-amber-300">Superadmin validation</strong>. Please contact the IT Division for any official name change requests.</span>
                  </div>
                  <div class="absolute top-full left-1/2 -translate-x-1/2 w-0 h-0 border-l-[6px] border-l-transparent border-r-[6px] border-r-transparent border-t-[6px] border-t-slate-900"></div>
                </div>
              </div>
              <span class="text-[9px] font-black text-amber-600 bg-amber-50 border border-amber-200/80 px-2 py-0.5 rounded-full uppercase tracking-wider">Read-Only</span>
            </div>
            <div class="relative">
              <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-300"><i class="fa-solid fa-user-tie text-sm"></i></span>
              <input type="text" id="fieldFullName" value="" placeholder="Loading..." readonly disabled
                class="pl-10 pr-10 py-3 border border-slate-200 bg-slate-50/70 rounded-xl text-xs w-full font-bold text-slate-400 cursor-not-allowed select-none">
              <span class="absolute right-3.5 top-1/2 -translate-y-1/2"><i class="fa-solid fa-lock text-slate-300 text-xs"></i></span>
            </div>
            <p class="text-[10px] text-slate-400 font-medium leading-relaxed">
              This field is managed by system administrators. Submit a request to <span class="font-black text-brand-dark">it-support@caloocan.gov.ph</span> for name corrections.
            </p>
          </div>

          <div class="border-t border-slate-100"></div>

          <div class="space-y-2">
            <label for="fieldPhone" class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Contact Number</label>
            <div class="relative">
              <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"><i class="fa-solid fa-phone text-sm"></i></span>
              <input type="tel" id="fieldPhone" value="+63 917 123 4567" placeholder="+63 9XX XXX XXXX"
                class="pl-10 pr-4 py-3 border border-slate-200 bg-white rounded-xl text-xs w-full font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-900/10 focus:border-slate-900 transition placeholder:text-slate-300">
            </div>
            <p class="text-[10px] text-slate-400 font-medium">Enter your active mobile number for official system notifications and recovery.</p>
          </div>

          <div class="border-t border-slate-100"></div>

          <div class="space-y-2">
            <div class="flex items-center gap-2">
              <label for="fieldEmail" class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Email Address</label>
              <span class="text-[9px] font-black text-blue-600 bg-blue-50 border border-blue-200/80 px-2 py-0.5 rounded-full uppercase tracking-wider">Government Email</span>
            </div>
            <div class="relative">
              <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"><i class="fa-solid fa-envelope text-sm"></i></span>
              <input type="email" id="fieldEmail" value="joshua.suruiz@caloocan.gov.ph" placeholder="name@caloocan.gov.ph"
                class="pl-10 pr-12 py-3 border border-slate-200 bg-white rounded-xl text-xs w-full font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-900/10 focus:border-slate-900 transition placeholder:text-slate-300">
              <span class="absolute right-3.5 top-1/2 -translate-y-1/2"><i class="fa-solid fa-shield-halved text-blue-400 text-xs"></i></span>
            </div>
            <div class="flex items-start gap-2.5 bg-blue-50/70 border border-blue-100 rounded-xl px-3.5 py-2.5">
              <i class="fa-solid fa-circle-info text-blue-400 text-xs shrink-0 mt-0.5"></i>
              <p class="text-[10px] text-blue-700 font-semibold leading-relaxed">
                Updates to official municipal email addresses will trigger an <strong>activation link</strong> to the new address before applying. Your current address remains active until confirmed.
              </p>
            </div>
          </div>

          <div class="border-t border-slate-100"></div>

          <div class="space-y-2">
            <label for="fieldPosition" class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Job Title / Position</label>
            <div class="relative">
              <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"><i class="fa-solid fa-briefcase text-sm"></i></span>
              <input type="text" id="fieldPosition" value="IT Systems Administrator" placeholder="e.g. Department Head, IT Officer"
                class="pl-10 pr-4 py-3 border border-slate-200 bg-white rounded-xl text-xs w-full font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-900/10 focus:border-slate-900 transition placeholder:text-slate-300">
            </div>
          </div>

        </div>
      </div>

      <!-- APPEARANCE & SYSTEM THEME CARD -->
      <div class="bg-white border border-slate-200/80 rounded-2xl shadow-xs overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-200/60 px-6 py-4 flex items-center justify-between">
          <div class="flex items-center gap-2.5">
            <div class="h-7 w-7 rounded-lg bg-brand-light border border-brand-border flex items-center justify-center">
              <i class="fa-solid fa-palette text-brand-dark text-xs"></i>
            </div>
            <div>
              <span class="text-xs font-black text-slate-700 block leading-none tracking-tight">System Theme & Appearance</span>
              <span class="text-[9px] text-slate-400 font-semibold uppercase tracking-wider">Choose your preferred portal visual display mode</span>
            </div>
          </div>
          <span class="text-[9px] font-black uppercase tracking-wider text-slate-400 bg-slate-100 border border-slate-200 px-2.5 py-1 rounded-lg">Personalization</span>
        </div>

        <div class="p-6">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Light Mode Choice -->
            <div id="themeCardLight" onclick="selectSystemTheme('light')" class="border-2 border-brand-medium bg-brand-light/30 rounded-2xl p-4 flex items-start gap-3.5 cursor-pointer transition select-none shadow-xs">
              <div class="h-10 w-10 rounded-xl bg-amber-100 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 shadow-xs border border-amber-200/80 dark:border-amber-700/50">
                <i class="fa-solid fa-sun text-lg"></i>
              </div>
              <div class="flex-1 space-y-1">
                <div class="flex items-center justify-between">
                  <span class="text-xs font-black text-slate-900 dark:text-white">Light Mode (Default)</span>
                  <span id="themeBadgeLight" class="text-[9px] font-black text-brand-dark bg-brand-light px-2 py-0.5 rounded-full uppercase border border-brand-border">Active</span>
                </div>
                <p class="text-[10px] text-slate-500 dark:text-slate-400 font-medium leading-relaxed">Clean municipal light theme with slate and blue color accents.</p>
              </div>
            </div>

            <!-- Dark Mode Choice -->
            <div id="themeCardDark" onclick="selectSystemTheme('dark')" class="border-2 border-slate-200 dark:border-slate-800 bg-slate-900 text-white rounded-2xl p-4 flex items-start gap-3.5 cursor-pointer transition select-none hover:border-slate-700">
              <div class="h-10 w-10 rounded-xl bg-indigo-950/80 text-indigo-400 flex items-center justify-center shrink-0 shadow-xs border border-indigo-700/50">
                <i class="fa-solid fa-moon text-lg"></i>
              </div>
              <div class="flex-1 space-y-1">
                <div class="flex items-center justify-between">
                  <span class="text-xs font-black text-white">Dark Mode</span>
                  <span id="themeBadgeDark" class="hidden text-[9px] font-black text-indigo-300 bg-indigo-950/80 px-2 py-0.5 rounded-full uppercase border border-indigo-700/50">Active</span>
                </div>
                <p class="text-[10px] text-slate-400 font-medium leading-relaxed">Sleek dark theme reducing eye strain during low-light sessions.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white border border-slate-200/80 rounded-2xl shadow-xs overflow-hidden">
        <div class="px-6 py-4 flex items-center justify-between gap-4">
          <div class="flex items-start gap-3">
            <div class="h-9 w-9 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center shrink-0">
              <i class="fa-solid fa-key text-slate-400 text-sm"></i>
            </div>
            <div class="space-y-0.5">
              <p class="text-xs font-bold text-slate-700 leading-none">Account Password</p>
              <p class="text-[10px] text-slate-400 font-medium leading-relaxed">Last changed <strong class="text-slate-600">30 days ago</strong>. It is recommended to update every 90 days per security policy.</p>
            </div>
          </div>
          <a href="<?php echo $basePath; ?>pages/profile/change-password.php"
            class="shrink-0 bg-[#0f172a] hover:bg-slate-800 text-white font-bold px-4 py-2.5 rounded-xl text-[10px] flex items-center gap-2 cursor-pointer transition shadow-xs whitespace-nowrap">
            <i class="fa-solid fa-lock-open text-[9px]"></i>
            Change Password
          </a>
        </div>
      </div>

      <div class="bg-white border border-slate-200/80 rounded-2xl shadow-xs px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-3">
        <p class="text-[10px] text-slate-400 font-semibold leading-relaxed">
          <i class="fa-solid fa-circle-info text-slate-300 mr-1"></i>
          Changes are saved to your profile upon clicking <strong class="text-slate-600">Save Settings</strong>. Unsaved inputs will be discarded.
        </p>
        <div class="flex items-center gap-2.5 shrink-0">
          <button onclick="discardChanges()" class="border border-slate-200 bg-white hover:bg-slate-50 text-slate-600 font-bold px-4 py-2.5 rounded-xl text-xs flex items-center gap-2 cursor-pointer transition">
            <i class="fa-solid fa-rotate-left text-[10px]"></i>
            Discard Changes
          </button>
          <button onclick="saveSettings()" id="saveSettingsBtn" class="bg-[#0f172a] hover:bg-slate-800 text-white font-bold px-5 py-2.5 rounded-xl text-xs flex items-center gap-2 cursor-pointer transition shadow-xs">
            <i class="fa-solid fa-floppy-disk text-[10px]"></i>
            Save Settings
          </button>
        </div>
      </div>

    </div>
  </div>

</main>

<div id="settingsToast" class="fixed bottom-5 right-5 z-50 flex items-center gap-3 bg-slate-900 text-white text-xs font-bold px-5 py-3.5 rounded-xl shadow-2xl transform translate-y-4 opacity-0 pointer-events-none transition-all duration-300 max-w-xs">
  <div id="settingsToastIcon" class="h-6 w-6 rounded-full bg-emerald-500 flex items-center justify-center text-white text-[11px] shrink-0">
    <i class="fa-solid fa-check"></i>
  </div>
  <div class="flex flex-col leading-tight">
    <span id="settingsToastMsg" class="tracking-wide">Settings updated successfully!</span>
    <span id="settingsToastSub" class="text-[9px] text-slate-400 font-semibold mt-0.5"></span>
  </div>
</div>


<?php include '../../includes/footer.php'; ?>
