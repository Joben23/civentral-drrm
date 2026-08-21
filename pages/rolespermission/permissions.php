<?php
$basePath = '../../';
include '../../includes/header.php';
include '../../includes/sidebar.php';

$canManagePermissions = !empty($headerUser['is_superadmin'])
  || !empty($headerUser['is_global_access'])
  || in_array('EDIT', $headerUser['granted_actions'] ?? [], true)
  || in_array('CREATE', $headerUser['granted_actions'] ?? [], true);
?>
<script>
  window.currentUserRoleId = <?php echo json_encode($_SESSION['current_user_details']['role_id'] ?? null); ?>;
  window.currentUserDeptId = <?php echo json_encode($headerUser['department_id'] ?? null); ?>;
  window.currentUserDeptName = <?php echo json_encode($headerUser['department_name'] ?? ''); ?>;
  window.currentUserDeptCode = <?php echo json_encode($headerUser['department_code'] ?? ''); ?>;
  window.currentUserIsSuperAdmin = <?php echo json_encode(!empty($headerUser['is_superadmin']) || !empty($headerUser['is_global_access'])); ?>;
  window.currentUserCanManagePermissions = <?php echo json_encode($canManagePermissions); ?>;
</script>


<main class="flex-1 p-6 md:p-8 w-full space-y-6 overflow-y-auto relative pb-24">

  <!-- Breadcrumb & Page Header -->
  <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-200/60 pb-5">
    <div class="space-y-1">
      <div class="flex items-center space-x-2 text-xs font-bold uppercase tracking-wider text-slate-400">
        <span>Role & Permissions</span>
        <i class="fa-solid fa-chevron-right text-[8px] opacity-60"></i>
        <span class="text-brand-dark">Permissions Matrix</span>
      </div>
      <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2 mt-4">
        <i class="fa-solid fa-user-shield text-brand-dark"></i>
        Role Permissions Matrix
      </h1>
      <p class="text-xs text-slate-500 max-w-2xl leading-relaxed">
        Configure granular module access, action privileges, and security restrictions for each system role.
      </p>
    </div>
  </div>

  <!-- Two Column Layout Workspace -->
  <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

    <!-- Column 1: Role Selector Sidebar (1/4 width) -->
    <div class="space-y-4">
      <div class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs space-y-3">
        <h3 class="text-xs font-black uppercase tracking-wider text-slate-700">Access Roles</h3>
        
        <!-- Search Input -->
        <div class="relative">
          <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
          <input type="text" id="roleSearchInput" placeholder="Search roles..." class="pl-8 pr-3 py-2 border border-slate-200 rounded-xl text-xs w-full bg-slate-50/50 focus:bg-white focus:outline-none focus:border-brand-medium transition">
        </div>

        <!-- Role List Container -->
        <div id="roleSelectorList" class="space-y-1.5 max-h-[50vh] overflow-y-auto">
          <!-- Dynamically populated by JS -->
        </div>
      </div>
    </div>

    <!-- Column 2: Permission Assignment Section (3/4 width) -->
    <div class="lg:col-span-3 space-y-6">
      
      <!-- Panel Header Controls -->
      <div class="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="space-y-0.5">
          <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Access Parameters Configuration</p>
          <h2 id="editingRoleHeader" class="text-sm font-black text-slate-900 tracking-tight">Editing Permissions for: Loading...</h2>
        </div>
        <?php if ($canManagePermissions): ?>
        <div class="flex items-center gap-2">
          <button type="button" onclick="resetToDefaults()" class="border border-slate-200 hover:bg-slate-50 text-slate-650 font-bold px-4 py-2 rounded-xl text-xs transition cursor-pointer">
            Reset to Defaults
          </button>
          <button type="button" onclick="saveChanges()" class="bg-[#0f172a] hover:bg-slate-800 text-white font-bold px-4 py-2 rounded-xl text-xs transition shadow-xs cursor-pointer">
            Save Changes
          </button>
        </div>
        <?php endif; ?>
      </div>

      <!-- Search Bar for Modules/Resources -->
      <div class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-xs">
        <div class="relative">
          <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
          <input type="text" id="moduleSearchInput" placeholder="Search operational modules or resources (e.g. Scholarship Applications)..." class="pl-9 pr-3 py-2.5 border border-slate-200 rounded-xl text-xs w-full bg-slate-50/50 focus:bg-white focus:outline-none focus:border-brand-medium transition">
        </div>
      </div>

      <!-- Hierarchical Collapsible Modules list -->
      <div id="moduleAccordionList" class="space-y-4">
        <!-- Dynamically populated by JS -->
      </div>

      <!-- Special System Restrictions Checklists -->
      <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-xs space-y-4">
        <div class="border-b border-slate-100 pb-3 flex items-center space-x-2">
          <div class="h-6 w-6 rounded-lg bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-500">
            <i class="fa-solid fa-shield-halved text-xs"></i>
          </div>
          <h3 class="text-xs font-black uppercase tracking-wider text-slate-700">Security Clearance Constraints</h3>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Allowed Scopes -->
          <div class="space-y-3">
            <h4 class="text-[10px] font-black text-emerald-600 uppercase tracking-wider flex items-center gap-1.5">
              <i class="fa-solid fa-circle-check text-xs"></i>
              Explicitly Allowed Operations
            </h4>
            <ul id="allowedRestrictionsList" class="space-y-2.5 text-xs text-slate-650">
              <!-- JS Populated -->
            </ul>
          </div>

          <!-- Forbidden Scopes -->
          <div class="space-y-3">
            <h4 class="text-[10px] font-black text-rose-600 uppercase tracking-wider flex items-center gap-1.5">
              <i class="fa-solid fa-circle-xmark text-xs"></i>
              Strictly Forbidden Operations
            </h4>
            <ul id="forbiddenRestrictionsList" class="space-y-2.5 text-xs text-slate-650">
              <!-- JS Populated -->
            </ul>
          </div>
        </div>
      </div>

    </div>

  </div>

  <!-- Unsaved Changes Floating Banner -->
  <div id="dirtyBanner" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 bg-slate-900 border border-slate-800 text-white rounded-2xl px-6 py-4 flex items-center justify-between gap-6 shadow-xl w-[90%] max-w-xl opacity-0 pointer-events-none translate-y-4 transition-all duration-300">
    <div class="flex items-center space-x-2.5">
      <i class="fa-solid fa-circle-exclamation text-amber-500 animate-pulse text-sm"></i>
      <span class="text-xs font-bold tracking-wide">You have unsaved changes in the permission assignments.</span>
    </div>
    <div class="flex items-center space-x-2">
      <button type="button" onclick="discardChanges()" class="hover:bg-slate-800 text-slate-350 px-3 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer">Discard</button>
      <button type="button" onclick="saveChanges()" class="bg-brand-medium hover:bg-brand-medium/90 text-white px-4 py-1.5 rounded-xl text-xs font-bold transition shadow-xs cursor-pointer">Save Changes</button>
    </div>
  </div>

  <!-- TOAST POPUP NOTIFICATION CONTAINER -->
  <div id="toast" class="fixed bottom-4 right-4 z-50 bg-slate-900 text-white text-xs font-bold px-4 py-3.5 rounded-xl shadow-lg flex items-center gap-3 transform translate-y-4 opacity-0 pointer-events-none transition-all duration-300">
    <div class="h-5 w-5 rounded-full bg-emerald-500 flex items-center justify-center text-white text-[10px]">
      <i class="fa-solid fa-check"></i>
    </div>
    <span id="toastMsg" class="tracking-wide">Action executed successfully.</span>
  </div>

</main>

<script src="<?php echo $basePath ?? '../'; ?>assets/js/rolespermission/permissions.js"></script>

<?php include '../../includes/footer.php'; ?>
