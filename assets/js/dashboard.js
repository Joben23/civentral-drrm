// CALENDAR/CLOCK FUNCTION
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
      document.getElementById('headerClock').innerText = now.toLocaleDateString('en-US', options);
    }
    setInterval(updateClock, 1000);
    updateClock();

    // DROPDOWN
    function toggleDropdown(id, chevronId) {
      if (typeof isCollapsed !== 'undefined' && isCollapsed) return; 

      const dropdown = document.getElementById(id);
      const chevron = document.getElementById(chevronId);
      if (!dropdown) return;
      
      const dropdowns = ['userDropdown', 'roleDropdown', 'deptDropdown', 'citizenDropdown', 'scholarshipDropdown', 'auditDropdown'];
      const chevrons = ['userChevron', 'roleChevron', 'deptChevron', 'citizenChevron', 'scholarshipChevron', 'auditChevron'];
      
      dropdowns.forEach((d, i) => {
        if (d !== id) {
          const otherEl = document.getElementById(d);
          if (otherEl) otherEl.classList.add('hidden');
          const otherChevron = document.getElementById(chevrons[i]);
          if (otherChevron) otherChevron.classList.remove('rotate-180');
        }
      });

      if (dropdown.classList.contains('hidden')) {
        dropdown.classList.remove('hidden');
        if (chevron) chevron.classList.add('rotate-180');
      } else {
        dropdown.classList.add('hidden');
        if (chevron) chevron.classList.remove('rotate-180');
      }
    }

    // SIDEBAR RESPONSIVE
  let isCollapsed = false;

function toggleSidebar() {

    const sidebar = document.getElementById('sidebar');
    const arrow = document.getElementById('toggleArrow');

    const sideLabels = document.querySelectorAll('.sidebar-text');
    const dropdownButtons = document.querySelectorAll('.dropdown-btn');
    const dropdownRights = document.querySelectorAll('.dropdown-right');

    const dropdowns = [
        'userDropdown',
        'roleDropdown',
        'deptDropdown',
        'citizenDropdown',
        'auditDropdown'
    ];

    isCollapsed = !isCollapsed;

    if (isCollapsed) {

        // CLOSE
        dropdowns.forEach(id => {
            const menu = document.getElementById(id);

            if (menu) {
                menu.classList.add('hidden');
            }
        });

        // COLLAPSE
        sidebar.classList.remove('w-72');
        sidebar.classList.add('w-20');

        arrow.className = "fa-solid fa-chevron-right text-xs";

        // HIDE
        sideLabels.forEach(label => {
            label.classList.add('hidden');
        });

        // HIDE DROPDOWN
        dropdownRights.forEach(right => {
            right.classList.add('hidden');
        });

        // CENTER ICON
        dropdownButtons.forEach(btn => {
            btn.classList.remove('justify-between');
            btn.classList.add('justify-center');
        });

    } else {

        // EXPAND
        sidebar.classList.remove('w-20');
        sidebar.classList.add('w-72');

        arrow.className = "fa-solid fa-chevron-left text-xs";

        // SHOW TEXT
        sideLabels.forEach(label => {
            label.classList.remove('hidden');
        });

        // SHOW DROP DOWN BUTTON
        dropdownRights.forEach(right => {
            right.classList.remove('hidden');
        });

        // RESTORE
        dropdownButtons.forEach(btn => {
            btn.classList.remove('justify-center');
            btn.classList.add('justify-between');
        });

        // RESET
        document.querySelectorAll('.dropdown-chevron').forEach(chv => {
            chv.classList.remove('rotate-180');
        });

    }
}

// Module 3 readiness is enhanced here because the DRRM overview remains a
// shared, presentation-only include. Modules 2 and 5 are intentionally left
// in their existing unavailable state.
(function enableIncidentModuleOnDrrmOverview() {
    const initialize = function () {
        const moduleTitle = Array.from(document.querySelectorAll('h3')).find(function (heading) {
            return heading.textContent.trim() === 'Incident Reporting & Response';
        });
        const moduleCard = moduleTitle ? moduleTitle.closest('article') : null;

        if (moduleCard) {
            moduleCard.classList.remove('border-slate-200/80', 'dark:border-slate-800');
            moduleCard.classList.add('border-brand-border', 'dark:border-slate-700');

            const badge = moduleCard.querySelector('div > span:last-child');
            if (badge) {
                badge.textContent = 'Module Available';
                badge.className = 'inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-[9px] font-black uppercase tracking-wider text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-400';
            }

            const unavailableButton = moduleCard.querySelector('button[disabled]');
            if (unavailableButton) {
                const openLink = document.createElement('a');
                const basePath = typeof window.civentralBasePath === 'string' ? window.civentralBasePath : '../';
                openLink.href = basePath + 'pages/drrm/incident-reporting-response.php';
                openLink.className = 'mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-brand-dark px-4 py-2.5 text-xs font-black text-white shadow-xs transition hover:bg-[#12566d] focus:outline-none focus:ring-2 focus:ring-brand-medium/40 sm:w-auto';
                openLink.textContent = 'Open Module';
                unavailableButton.replaceWith(openLink);
            }
        }

        const readinessTitle = document.getElementById('moduleReadinessTitle');
        const readinessSection = readinessTitle ? readinessTitle.closest('section') : null;
        const readinessLabel = Array.from(readinessSection ? readinessSection.querySelectorAll('.mt-2 > div > span:first-child') : []).find(function (label) {
            return label.textContent.trim() === 'Incident Reporting & Response';
        });
        const readinessStatus = readinessLabel ? readinessLabel.parentElement.querySelector('span:last-child') : null;
        if (readinessStatus) {
            readinessStatus.textContent = 'Available';
            readinessStatus.className = 'inline-flex items-center gap-1.5 text-[9px] font-black uppercase tracking-wider text-emerald-600 dark:text-emerald-400';
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
