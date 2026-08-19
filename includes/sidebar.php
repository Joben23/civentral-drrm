    <?php
      $currentPage = basename($_SERVER['PHP_SELF']);

      $usermanagementPages = [
        'user-directory.php',
        'create-account.php',
        'account-status.php'
      ];

      $rolesmanagementPages = [
        'roles-management.php',
        'permissions.php',
        'module-management.php',
        'resource-management.php',
        'resourcemanagement.php',
        'action-management.php',
        'actionmanagement.php',
        'access-control.php'
      ];

      $departmentmanagementPages = [
        'departments.php'
      ];
      $citizenPages = [
        'citizen-directory.php',
        'citizen-account.php'
      ];
      $scholarshipPages = [
        'scholarship-types.php'
      ];
      $auditPages = [
        'user-activities.php',
        'login-history.php',
        'data-changes.php'
      ];

      $isSuperAdmin = !empty($headerUser['is_superadmin']) || !empty($headerUser['is_global_access']);
      $userGrantedRes = $headerUser['granted_resources'] ?? [];

      // Dynamic RBAC Permission Checker
      $hasResourceAccess = function($keywords) use ($isSuperAdmin, $userGrantedRes) {
          if ($isSuperAdmin) return true;
          if (empty($userGrantedRes)) return false;
          if (is_string($keywords)) $keywords = [$keywords];
          foreach ($userGrantedRes as $resName) {
              $resLower = strtolower($resName);
              foreach ($keywords as $kw) {
                  if (strpos($resLower, strtolower($kw)) !== false) return true;
              }
          }
          return false;
      };
    ?>
    
    <aside id="sidebar" class="bg-brand-light text-slate-600 w-72 min-h-[calc(100vh-5rem)] flex flex-col justify-between transition-all duration-300 border-r border-brand-border/60 sticky top-20 h-[calc(100vh-5rem)] z-30 shrink-0 shadow-sm">
      
      <div class="flex flex-col h-full overflow-hidden">
        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto custom-scrollbar">
          
          <div class="sidebar-divider px-1 pb-3 mb-2 border-b">
            <button onclick="toggleSidebar()" class="sidebar-collapse-btn w-full py-2 rounded-xl border flex items-center justify-center focus:outline-none transition cursor-pointer shadow-xs" title="Collapse Menu Panel">
              <i id="toggleArrow" class="fa-solid fa-chevron-left text-xs"></i>
            </button>
          </div>

          <span class="sidebar-text text-[9px] font-bold tracking-widest text-slate-400 uppercase block px-3 mb-2">Main Controls</span>
          
          <a href="<?php echo $basePath ?? '../'; ?>pages/dashboard.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs tracking-wide transition cursor-pointer <?php echo $currentPage == 'dashboard.php' ? 'bg-white text-brand-dark border border-brand-border font-bold shadow-xs' : 'hover:bg-white dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-brand-dark dark:hover:text-[#86B6F6] border border-transparent font-semibold'; ?>">
            <i class="fa-solid fa-table-columns text-sm <?php echo $currentPage == 'dashboard.php' ? 'text-brand-medium' : 'text-slate-400'; ?>"></i>
            <span class="sidebar-text truncate">Dashboard Overview</span>
          </a>

          <span class="sidebar-text text-[9px] font-bold tracking-widest text-slate-400 uppercase block px-3 pt-4 pb-1">DRRM Modules</span>

          <a href="<?php echo $basePath ?? '../'; ?>pages/drrm/hazard-evacuation-map.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs tracking-wide transition cursor-pointer <?php echo $currentPage == 'hazard-evacuation-map.php' ? 'bg-white text-brand-dark border border-brand-border font-bold shadow-xs' : 'hover:bg-white dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-brand-dark dark:hover:text-[#86B6F6] border border-transparent font-semibold'; ?>">
            <i class="fa-solid fa-map-location-dot text-sm <?php echo $currentPage == 'hazard-evacuation-map.php' ? 'text-brand-medium' : 'text-slate-400'; ?>"></i>
            <span class="sidebar-text truncate">Hazard & Evacuation Map System</span>
          </a>

          <button type="button" disabled aria-disabled="true" title="Not yet implemented" class="w-full flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs tracking-wide border border-transparent font-semibold text-slate-400 dark:text-slate-500 opacity-70 cursor-not-allowed">
            <i class="fa-solid fa-box-open text-sm"></i>
            <span class="sidebar-text flex min-w-0 flex-1 items-center justify-between gap-2">
              <span class="truncate text-left">Relief Goods Distribution Tracker</span>
              <span class="shrink-0 rounded-md bg-slate-200/70 dark:bg-slate-700/70 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wider">Soon</span>
            </span>
          </button>

          <button type="button" disabled aria-disabled="true" title="Not yet implemented" class="w-full flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs tracking-wide border border-transparent font-semibold text-slate-400 dark:text-slate-500 opacity-70 cursor-not-allowed">
            <i class="fa-solid fa-triangle-exclamation text-sm"></i>
            <span class="sidebar-text flex min-w-0 flex-1 items-center justify-between gap-2">
              <span class="truncate text-left">Incident Reporting & Response Log</span>
              <span class="shrink-0 rounded-md bg-slate-200/70 dark:bg-slate-700/70 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wider">Soon</span>
            </span>
          </button>

          <button type="button" disabled aria-disabled="true" title="Not yet implemented" class="w-full flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs tracking-wide border border-transparent font-semibold text-slate-400 dark:text-slate-500 opacity-70 cursor-not-allowed">
            <i class="fa-solid fa-bell text-sm"></i>
            <span class="sidebar-text flex min-w-0 flex-1 items-center justify-between gap-2">
              <span class="truncate text-left">Disaster Early Warning System</span>
              <span class="shrink-0 rounded-md bg-slate-200/70 dark:bg-slate-700/70 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wider">Soon</span>
            </span>
          </button>

          <button type="button" disabled aria-disabled="true" title="Not yet implemented" class="w-full flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs tracking-wide border border-transparent font-semibold text-slate-400 dark:text-slate-500 opacity-70 cursor-not-allowed">
            <i class="fa-solid fa-people-group text-sm"></i>
            <span class="sidebar-text flex min-w-0 flex-1 items-center justify-between gap-2">
              <span class="truncate text-left">Barangay DRRM Coordination Tool</span>
              <span class="shrink-0 rounded-md bg-slate-200/70 dark:bg-slate-700/70 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wider">Soon</span>
            </span>
          </button>

          <?php 
          $canAccessUserMgmt = $isSuperAdmin || $hasResourceAccess(['user directory', 'user account', 'users account', 'account status', 'user', 'account', 'employee']);
          if ($canAccessUserMgmt): 
          ?>
          <div class="space-y-1">
           <button onclick="toggleDropdown('userDropdown', 'userChevron')" class="dropdown-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-xs tracking-wide transition group cursor-pointer <?php echo in_array($currentPage, $usermanagementPages) ? 'bg-white text-brand-dark border border-brand-border font-bold shadow-xs' : 'hover:bg-white dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-brand-dark dark:hover:text-[#86B6F6] border border-transparent font-semibold'; ?>">
             <div class="flex items-center space-x-3">
                <i class="fa-solid fa-users-gear text-sm <?php echo in_array($currentPage, $usermanagementPages) ? 'text-brand-medium' : 'text-slate-400'; ?> group-hover:text-brand-medium transition"></i>
                <span class="sidebar-text truncate">User Management</span>
             </div>
             <div class="dropdown-right">
                <i id="userChevron"
                   class="fa-solid fa-chevron-down text-[10px] opacity-60 dropdown-chevron transition-transform duration-200 <?php echo in_array($currentPage, $usermanagementPages) ? 'rotate-180' : ''; ?>"></i>
             </div>
            </button>
            <div id="userDropdown" class="<?php echo in_array($currentPage, $usermanagementPages) ? '' : 'hidden'; ?> pl-8 pr-2 space-y-0.5 font-medium sidebar-text">
              <a href="<?php echo $basePath ?? '../'; ?>pages/usermanagement/user-directory.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'user-directory.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-user-pen text-[10px] <?php echo $currentPage == 'user-directory.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>User Directory</span></a>

              <?php if ($isSuperAdmin || $hasResourceAccess(['users account', 'user account', 'create account'])): ?>
              <a href="<?php echo $basePath ?? '../'; ?>pages/usermanagement/create-account.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'create-account.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-user-plus text-[10px] <?php echo $currentPage == 'create-account.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Create Staff Accounts</span></a>
              <?php endif; ?>

              <?php if ($isSuperAdmin || $hasResourceAccess(['account status', 'status control', 'status'])): ?>
              <a href="<?php echo $basePath ?? '../'; ?>pages/usermanagement/account-status.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'account-status.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-user-check text-[10px] <?php echo $currentPage == 'account-status.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Activate/Deactivate</span></a>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php 
          $canAccessRoleMgmt = $isSuperAdmin || $hasResourceAccess(['role', 'permission', 'module', 'resource', 'access control']);
          if ($canAccessRoleMgmt): 
          ?>
          <div class="space-y-1">
            <button onclick="toggleDropdown('roleDropdown', 'roleChevron')" class="dropdown-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-xs tracking-wide transition group cursor-pointer <?php echo in_array($currentPage, $rolesmanagementPages) ? 'bg-white text-brand-dark border border-brand-border font-bold shadow-xs' : 'hover:bg-white dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-brand-dark dark:hover:text-[#86B6F6] border border-transparent font-semibold'; ?>">
              <div class="flex items-center space-x-3">
                  <i class="fa-solid fa-user-shield text-sm <?php echo in_array($currentPage, $rolesmanagementPages) ? 'text-brand-medium' : 'text-slate-400'; ?> group-hover:text-brand-medium transition"></i>
                  <span class="sidebar-text truncate">Role & Permissions</span>
              </div>
              <div class="dropdown-right">
                  <i id="roleChevron"
                    class="fa-solid fa-chevron-down text-[10px] opacity-60 dropdown-chevron transition-transform duration-200 <?php echo in_array($currentPage, $rolesmanagementPages) ? 'rotate-180' : ''; ?>"></i>
              </div>
            </button>
            <div id="roleDropdown" class="<?php echo in_array($currentPage, $rolesmanagementPages) ? '' : 'hidden'; ?> pl-8 pr-2 space-y-0.5 font-medium sidebar-text">
              <?php if ($isSuperAdmin || $hasResourceAccess(['roles'])): ?>
              <a href="<?php echo $basePath ?? '../'; ?>pages/rolespermission/roles-management.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'roles-management.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-users text-[10px] <?php echo $currentPage == 'roles-management.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Roles</span></a>
              <?php endif; ?>

              <?php if ($isSuperAdmin || $hasResourceAccess(['module management'])): ?>
              <a href="<?php echo $basePath ?? '../'; ?>pages/rolespermission/module-management.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'module-management.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-cubes text-[10px] <?php echo $currentPage == 'module-management.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Module Management</span></a>
              <?php endif; ?>

              <?php if ($isSuperAdmin || $hasResourceAccess(['resource management'])): ?>
              <a href="<?php echo $basePath ?? '../'; ?>pages/rolespermission/resource-management.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo (in_array($currentPage, ['resource-management.php', 'resourcemanagement.php'])) ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-file-lines text-[10px] <?php echo (in_array($currentPage, ['resource-management.php', 'resourcemanagement.php'])) ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Resource Management</span></a>
              <?php endif; ?>

              <?php if ($isSuperAdmin || $hasResourceAccess(['action management'])): ?>
              <a href="<?php echo $basePath ?? '../'; ?>pages/rolespermission/action-management.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo (in_array($currentPage, ['action-management.php', 'actionmanagement.php'])) ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-bolt text-[10px] <?php echo (in_array($currentPage, ['action-management.php', 'actionmanagement.php'])) ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Action Management</span></a>
              <?php endif; ?>

              <?php if ($isSuperAdmin || $hasResourceAccess(['permission builder'])): ?>
              <a href="<?php echo $basePath ?? '../'; ?>pages/rolespermission/permissions.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'permissions.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-key text-[10px] <?php echo $currentPage == 'permissions.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Permission Builder</span></a>
              <?php endif; ?>

              <?php if ($isSuperAdmin || $hasResourceAccess(['role permission matrix'])): ?>
              <a href="<?php echo $basePath ?? '../'; ?>pages/rolespermission/access-control.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'access-control.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-shield-halved text-[10px] <?php echo $currentPage == 'access-control.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Role Permission Matrix</span></a>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php 
          $canAccessDeptMgmt = $hasResourceAccess(['department', 'position', 'sitemap', 'department management']);
          if ($canAccessDeptMgmt): 
          ?>
          <div class="space-y-1">
            <button onclick="toggleDropdown('deptDropdown', 'deptChevron')" class="dropdown-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-xs tracking-wide transition group cursor-pointer <?php echo in_array($currentPage, $departmentmanagementPages) ? 'bg-white text-brand-dark border border-brand-border font-bold shadow-xs' : 'hover:bg-white dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-brand-dark dark:hover:text-[#86B6F6] border border-transparent font-semibold'; ?>">
                <div class="flex items-center space-x-3">
                    <i class="fa-solid fa-sitemap text-sm <?php echo in_array($currentPage, $departmentmanagementPages) ? 'text-brand-medium' : 'text-slate-400'; ?> group-hover:text-brand-medium transition"></i>
                    <span class="sidebar-text truncate">Department Management</span>
              </div>
                <div class="dropdown-right">
                    <i id="deptChevron"
                       class="fa-solid fa-chevron-down text-[10px] opacity-60 dropdown-chevron transition-transform duration-200 <?php echo in_array($currentPage, $departmentmanagementPages) ? 'rotate-180' : ''; ?>"></i>
                </div>
            </button>

            <div id="deptDropdown" class="<?php echo in_array($currentPage, $departmentmanagementPages) ? '' : 'hidden'; ?> pl-8 pr-2 space-y-0.5 font-medium sidebar-text">
              <a href="<?php echo $basePath ?? '../'; ?>pages/department/departments.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'departments.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-building text-[10px] <?php echo $currentPage == 'departments.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Departments</span></a>
            </div>
          </div>
          <?php endif; ?>

          <?php 
          $canAccessCitizenMgmt = $hasResourceAccess(['citizen', 'kyc', 'verification']);
          if ($canAccessCitizenMgmt): 
          ?>
          <div class="space-y-1">
            <button onclick="toggleDropdown('citizenDropdown', 'citizenChevron')" class="dropdown-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-xs tracking-wide transition group cursor-pointer <?php echo in_array($currentPage, $citizenPages) ? 'bg-white text-brand-dark border border-brand-border font-bold shadow-xs' : 'hover:bg-white dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-brand-dark dark:hover:text-[#86B6F6] border border-transparent font-semibold'; ?>">
                <div class="flex items-center space-x-3">
                    <i class="fa-solid fa-address-book text-sm <?php echo in_array($currentPage, $citizenPages) ? 'text-brand-medium' : 'text-slate-400'; ?> group-hover:text-brand-medium transition"></i>
                    <span class="sidebar-text truncate">Citizen Management</span>
              </div>
                <div class="dropdown-right">
                    <i id="citizenChevron"
                       class="fa-solid fa-chevron-down text-[10px] opacity-60 dropdown-chevron transition-transform duration-200 <?php echo in_array($currentPage, $citizenPages) ? 'rotate-180' : ''; ?>"></i>
                </div>
            </button>

            <div id="citizenDropdown" class="<?php echo in_array($currentPage, $citizenPages) ? '' : 'hidden'; ?> pl-8 pr-2 space-y-0.5 font-medium sidebar-text">
              <?php if ($hasResourceAccess('citizen directory')): ?>
              <a href="<?php echo $basePath ?? '../'; ?>pages/citizen/citizen-directory.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'citizen-directory.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-id-card text-[10px] <?php echo $currentPage == 'citizen-directory.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Citizen Directory</span></a>
              <?php endif; ?>

              <?php if ($hasResourceAccess(['citizen account', 'kyc', 'verification'])): ?>
              <a href="<?php echo $basePath ?? '../'; ?>pages/citizen/citizen-account.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'citizen-account.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-database text-[10px] <?php echo $currentPage == 'citizen-account.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Citizen Account</span></a>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php
            $canAccessScholarshipModule = $hasResourceAccess(['scholarship', 'education', 'student']);
            if ($canAccessScholarshipModule):
          ?>
          <div class="space-y-1">
            <button onclick="toggleDropdown('scholarshipDropdown', 'scholarshipChevron')" class="dropdown-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-xs tracking-wide transition group cursor-pointer <?php echo in_array($currentPage, $scholarshipPages) ? 'bg-white text-brand-dark border border-brand-border font-bold shadow-xs' : 'hover:bg-white dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-brand-dark dark:hover:text-[#86B6F6] border border-transparent font-semibold'; ?>">
                <div class="flex items-center space-x-3">
                    <i class="fa-solid fa-graduation-cap text-sm <?php echo in_array($currentPage, $scholarshipPages) ? 'text-brand-medium' : 'text-slate-400'; ?> group-hover:text-brand-medium transition"></i>
                    <span class="sidebar-text truncate">Education & Scholarship</span>
              </div>
                <div class="dropdown-right">
                    <i id="scholarshipChevron"
                       class="fa-solid fa-chevron-down text-[10px] opacity-60 dropdown-chevron transition-transform duration-200 <?php echo in_array($currentPage, $scholarshipPages) ? 'rotate-180' : ''; ?>"></i>
                </div>
            </button>

            <div id="scholarshipDropdown" class="<?php echo in_array($currentPage, $scholarshipPages) ? '' : 'hidden'; ?> pl-8 pr-2 space-y-0.5 font-medium sidebar-text">
              <a href="<?php echo $basePath ?? '../'; ?>pages/education-scholarship/scholarship-program/scholarship-types.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'scholarship-types.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-graduation-cap text-[10px] <?php echo $currentPage == 'scholarship-types.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Scholarship Types</span></a>
            </div>
          </div>
          <?php endif; ?>

          <?php 
          $canAccessAuditLogs = $hasResourceAccess(['audit', 'activity', 'log', 'change', 'history']);
          if ($canAccessAuditLogs): 
          ?>
          <div class="space-y-1">
           <button
                  onclick="toggleDropdown('auditDropdown', 'auditChevron')"
                  class="dropdown-btn w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-xs tracking-wide transition group cursor-pointer <?php echo in_array($currentPage, $auditPages) ? 'bg-white text-brand-dark border border-brand-border font-bold shadow-xs' : 'hover:bg-white dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-brand-dark dark:hover:text-[#86B6F6] border border-transparent font-semibold'; ?>">

                  <div class="flex items-center space-x-3">
                      <i class="fa-solid fa-clock-rotate-left text-sm <?php echo in_array($currentPage, $auditPages) ? 'text-brand-medium' : 'text-slate-400'; ?> group-hover:text-brand-medium transition"></i>
                      <span class="sidebar-text truncate">Audit Logs System</span>
              </div>

              <div class="dropdown-right">
                  <i id="auditChevron"
                    class="fa-solid fa-chevron-down text-[10px] opacity-60 dropdown-chevron transition-transform duration-200 <?php echo in_array($currentPage, $auditPages) ? 'rotate-180' : ''; ?>"></i>
              </div>
            </button>

            <div id="auditDropdown" class="<?php echo in_array($currentPage, $auditPages) ? '' : 'hidden'; ?> pl-8 pr-2 space-y-0.5 font-medium sidebar-text">
              <a href="<?php echo $basePath ?? '../'; ?>pages/audit/user-activities.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'user-activities.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-chart-line text-[10px] <?php echo $currentPage == 'user-activities.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>User Activities</span></a>
              <a href="<?php echo $basePath ?? '../'; ?>pages/audit/login-history.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'login-history.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-history text-[10px] <?php echo $currentPage == 'login-history.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Login History</span></a>
              <a href="<?php echo $basePath ?? '../'; ?>pages/audit/data-changes.php" class="flex items-center space-x-2 px-3 py-2 text-[11px] rounded-md transition <?php echo $currentPage == 'data-changes.php' ? 'text-brand-medium font-black bg-white border border-brand-border/40 shadow-xs' : 'text-slate-500 hover:text-brand-dark'; ?>"><i class="fa-solid fa-pen-to-square text-[10px] <?php echo $currentPage == 'data-changes.php' ? 'text-brand-medium' : 'opacity-50'; ?>"></i> <span>Data Changes</span></a>
            </div>
          </div>
          <?php endif; ?>
        </nav>
        
        <div class="p-4 border-t shrink-0 sidebar-footer">
          <a href="#" onclick="openLogoutModal(event)" class="sidebar-logout-btn flex items-center space-x-3 px-3 py-2.5 rounded-xl text-xs font-bold tracking-wide transition group cursor-pointer">
            <i class="fa-solid fa-arrow-right-from-bracket text-sm"></i>
            <span class="sidebar-text truncate">Logout</span>
          </a>
        </div>
      </div>
    </aside>
