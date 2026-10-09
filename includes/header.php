<?php
require_once __DIR__ . '/../src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Civentral</title>
  <link rel="icon" type="image/png" href="<?php echo $basePath ?? '../'; ?>assets/images/logo.png">
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
  <style type="text/tailwindcss">
    @custom-variant dark (&:where(.dark, .dark *));
    @theme {
      --color-brand-light: #EEF5FF;
      --color-brand-border: #B4D4FF;
      --color-brand-medium: #86B6F6;
      --color-brand-dark: #176B87;
    }
  </style>
  <link rel="stylesheet" href="<?php echo $basePath ?? '../'; ?>assets/css/header.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
    window.civentralBasePath = '<?php echo $basePath ?? '../'; ?>';
    window.civentralSessionExpiresAt = <?php echo json_encode(
      $adminSessionExpiresAt !== null ? $adminSessionExpiresAt * 1000 : null
    ); ?>;
    (function() {
      const savedTheme = localStorage.getItem('civentral_theme');
      if (savedTheme === 'dark') {
        document.documentElement.classList.add('dark');
      } else {
        document.documentElement.classList.remove('dark');
      }
    })();
  </script>
</head>
<body class="bg-slate-50 font-sans antialiased text-slate-800 min-h-screen flex flex-col">

  <header class="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 h-20 px-6 flex items-center justify-between sticky top-0 z-[90] shadow-xs shrink-0">
    <div class="flex items-center space-x-4 text-brand-dark">
        <div class="shrink-0 flex items-center justify-center">
          <img src="<?php echo $basePath ?? '../'; ?>assets/images/logo.png" alt="Logo" class="h-16 w-auto object-contain">
        </div>
    <div class="flex flex-col">
      <span class="text-base font-black tracking-[0.15em] uppercase leading-none dark:text-white">CIVENTRAL</span>
      <span class="text-[9px] font-bold text-brand-medium tracking-widest uppercase mt-1">Caloocan Portal</span>
    </div>
  </div>

    <div class="flex items-center space-x-4">
      
      <div class="hidden md:flex items-center space-x-2 text-slate-500 dark:text-slate-400 font-mono text-xs font-semibold">
        <i class="fa-solid fa-calendar-day text-brand-medium"></i>
        <span id="headerClock">Loading System Time...</span>
      </div>
      
      <div class="hidden md:block h-6 w-px bg-slate-200 dark:bg-slate-800"></div>

      <!-- Inactivity Countdown -->
      <div class="hidden md:flex items-center space-x-2 text-slate-500 dark:text-slate-400 font-mono text-xs font-semibold" title="Session Timeout">
        <i class="fa-solid fa-hourglass-half text-brand-medium"></i>
        <span id="inactivityCountdown">03:00</span>
      </div>
      
      <div class="hidden md:block h-6 w-px bg-slate-200 dark:bg-slate-800"></div>

      <button id="themeToggleBtn" onclick="toggleAppTheme()" class="header-action-btn p-2 text-slate-400 hover:text-brand-dark dark:hover:text-amber-400 cursor-pointer" title="Toggle Light / Dark Mode">
        <i id="themeToggleIcon" class="fa-solid fa-moon text-lg"></i>
      </button>

      <button class="header-action-btn p-2 text-slate-400 hover:text-brand-dark dark:hover:text-brand-medium cursor-pointer relative">
        <i class="fa-solid fa-bell text-lg"></i>
        <span class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-red-500 ring-2 ring-white dark:ring-slate-900"></span>
      </button>
      
      <div class="h-6 w-px bg-slate-200 dark:bg-slate-800"></div>
 
       <!-- Interactive Profile Dropdown -->
       <div class="relative">
         <?php 
           $headerAvatarSrc = $headerUser['profile_picture'] ?? 'default-avatar.png';
           if ($headerAvatarSrc !== 'default-avatar.png' && !preg_match('/^(http|https|data:)/i', $headerAvatarSrc)) {
               $headerAvatarSrc = ($basePath ?? '../') . ltrim($headerAvatarSrc, '/');
           }
         ?>
         <button id="profileDropdownBtn" onclick="toggleProfileDropdown(event)" class="flex items-center space-x-2.5 p-1.5 border border-transparent hover:border-slate-200/50 rounded-lg text-left select-none cursor-pointer focus:outline-none transition">
            <?php if (!empty($headerUser['profile_picture']) && $headerUser['profile_picture'] !== 'default-avatar.png'): ?>
              <img src="<?php echo htmlspecialchars($headerAvatarSrc); ?>" alt="User Avatar" class="h-8 w-8 rounded-lg object-cover border border-brand-border shrink-0">
            <?php else: ?>
              <div class="h-8 w-8 rounded-lg bg-brand-light border border-brand-border flex items-center justify-center text-brand-dark font-extrabold text-xs shrink-0">
                <?php echo htmlspecialchars($headerUser['initials']); ?>
              </div>
            <?php endif; ?>
           <div class="hidden sm:flex flex-col">
             <span class="text-xs font-bold text-slate-700 dark:text-slate-200 leading-none"><?php echo htmlspecialchars($headerUser['full_name']); ?></span>
             <span class="text-[9px] font-bold text-slate-400 uppercase mt-0.5 tracking-wider"><?php echo htmlspecialchars($headerUser['role']); ?></span>
           </div>
           <i class="fa-solid fa-chevron-down text-[9px] text-slate-400"></i>
         </button>
 
         <!-- Dropdown Card -->
         <div id="profileDropdownMenu" class="hidden absolute right-0 mt-2 w-56 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-2xl py-1 z-[100] text-xs text-slate-600 dark:text-slate-300 transition-all transform scale-95 origin-top-right">
           <!-- User Header -->
           <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center gap-2.5">
             <?php if (!empty($headerUser['profile_picture']) && $headerUser['profile_picture'] !== 'default-avatar.png'): ?>
               <img src="<?php echo htmlspecialchars($headerAvatarSrc); ?>" alt="User Avatar" class="h-8 w-8 rounded-lg object-cover border border-brand-border shrink-0">
             <?php else: ?>
               <div class="h-8 w-8 rounded-lg bg-brand-light border border-brand-border flex items-center justify-center text-brand-dark font-extrabold text-xs shrink-0">
                 <?php echo htmlspecialchars($headerUser['initials']); ?>
               </div>
             <?php endif; ?>
             <div class="flex flex-col overflow-hidden">
               <span class="font-bold text-slate-800 dark:text-white truncate"><?php echo htmlspecialchars($headerUser['full_name']); ?></span>
               <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider"><?php echo htmlspecialchars($headerUser['role']); ?></span>
             </div>
           </div>
           
           <!-- Dropdown Items -->
           <div class="py-1">
             <a href="<?php echo $basePath ?? '../'; ?>pages/profile/profile.php" class="flex items-center gap-2.5 px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 transition font-medium">
               <i class="fa-solid fa-user-circle text-slate-400 text-[13px]"></i>
               <span>My Profile</span>
             </a>
             <a href="<?php echo $basePath ?? '../'; ?>pages/profile/settings.php" class="flex items-center gap-2.5 px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 transition font-medium">
               <i class="fa-solid fa-gears text-slate-400 text-[13px]"></i>
               <span>System Settings</span>
             </a>
             <a href="<?php echo $basePath ?? '../'; ?>pages/profile/change-password.php" class="flex items-center gap-2.5 px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 transition font-medium">
               <i class="fa-solid fa-lock text-slate-400 text-[13px]"></i>
               <span>Change Password</span>
             </a>
             <a href="#" onclick="openLogoutModal(event)" class="flex items-center gap-2.5 px-4 py-2 hover:bg-rose-50 dark:hover:bg-rose-950/40 text-rose-600 dark:text-rose-400 transition font-medium border-t border-slate-100 dark:border-slate-800 cursor-pointer">
               <i class="fa-solid fa-right-from-bracket text-rose-500 text-[13px]"></i>
               <span>Sign out</span>
             </a>
           </div>
         </div>
       </div>
    </div>
  </header>

  <div
    id="sessionExpiryWarning"
    class="hidden fixed top-24 right-6 z-[110] max-w-sm rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-xs font-bold text-amber-900 shadow-lg"
    role="status"
    aria-live="polite"
  >
    Your session will expire soon due to inactivity.
  </div>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 backdrop-blur-md transition-opacity opacity-0">
      <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl w-full max-w-sm p-6 transform scale-95 transition-transform duration-200 text-slate-800 dark:text-slate-100">
        <div class="flex items-center justify-center w-12 h-12 mx-auto bg-rose-100 dark:bg-rose-950/60 border border-rose-200 dark:border-rose-800/50 rounded-full mb-4">
          <i class="fa-solid fa-right-from-bracket text-rose-600 dark:text-rose-400 text-xl"></i>
        </div>
        <h3 class="text-base font-black text-slate-900 dark:text-white text-center mb-1">Confirm Sign Out</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400 text-center mb-6 leading-relaxed">Are you sure you want to end your active session and sign out?</p>
        
        <div class="flex gap-3">
          <button onclick="closeLogoutModal()" class="flex-1 px-4 py-2.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold text-xs rounded-xl transition focus:outline-none cursor-pointer border border-slate-200 dark:border-slate-700">
            Cancel
          </button>
          <a href="<?php echo $basePath ?? '../'; ?>pages/logout.php" class="flex-1 px-4 py-2.5 bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs rounded-xl text-center transition focus:outline-none cursor-pointer shadow-xs">
            Yes, Sign Out
          </a>
        </div>
      </div>
    </div>

  <script src="<?php echo $basePath ?? '../'; ?>assets/js/app.js"></script>

  <div class="flex-1 flex relative">
