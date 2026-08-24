<?php

$basePath = '../../';

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmIncidentAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmIncidentCsrfService.php';

$authService->requireAuth($basePath);

$incidentAuthorization = \App\Services\DrrmIncidentAuthorizationService::fromTrustedSession($headerUser);
if (!$incidentAuthorization->canView()) {
    header('Location: ../dashboard.php');
    exit;
}

$incidentCsrfToken = (new \App\Services\DrrmIncidentCsrfService())->token();
$incidentCapabilities = $incidentAuthorization->capabilities();
$incidentCssRelativePath = 'assets/css/incident-reporting-response.css';
$incidentJsRelativePath = 'assets/js/incident-response.js';
$incidentCssVersion = filemtime(__DIR__ . '/../../' . $incidentCssRelativePath);
$incidentJsVersion = filemtime(__DIR__ . '/../../' . $incidentJsRelativePath);
$incidentCssUrl = $basePath . $incidentCssRelativePath . '?v=' . rawurlencode((string) $incidentCssVersion);
$incidentJsUrl = $basePath . $incidentJsRelativePath . '?v=' . rawurlencode((string) $incidentJsVersion);

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars($incidentCssUrl, ENT_QUOTES, 'UTF-8'); ?>">

<main class="flex-1 min-w-0 w-0 overflow-y-auto p-4 sm:p-6 md:p-8">
  <section class="mx-auto w-full max-w-[1600px] space-y-6" aria-labelledby="incidentModuleTitle">
    <header class="flex flex-col gap-4 border-b border-slate-200/70 pb-5 dark:border-slate-800 lg:flex-row lg:items-start lg:justify-between">
      <div class="space-y-1.5">
        <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.18em] text-brand-dark dark:text-brand-medium">
          <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
          <span>Disaster Risk Reduction &amp; Management</span>
        </div>
        <h1 id="incidentModuleTitle" class="text-xl font-black tracking-tight text-slate-900 dark:text-white sm:text-2xl">
          Incident Reporting &amp; Response Log
        </h1>
        <p class="max-w-4xl text-xs font-medium leading-relaxed text-slate-500 dark:text-slate-400 sm:text-sm">
          Review reported events, control verification and assignment, and maintain an auditable operational response record for Caloocan City.
        </p>
      </div>

      <div class="inline-flex shrink-0 items-center gap-2 self-start rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-400">
        <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
        <span>Admin Operations Foundation</span>
      </div>
    </header>

    <section class="space-y-3" aria-labelledby="incidentSummaryTitle">
      <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 id="incidentSummaryTitle" class="text-sm font-black text-slate-800 dark:text-white">Operational Summary</h2>
          <p class="mt-1 text-[10px] font-medium text-slate-500 dark:text-slate-400" data-incident-load-status role="status" aria-live="polite">Loading incident records...</p>
        </div>
        <button type="button" class="inline-flex self-start items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-[10px] font-black uppercase tracking-wider text-slate-600 shadow-xs transition hover:border-brand-border hover:text-brand-dark dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" data-refresh-incidents>
          <i class="fa-solid fa-rotate" aria-hidden="true"></i>
          Refresh
        </button>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <?php
        $summaryCards = [
            ['submitted', 'New / Submitted', 'fa-inbox', 'blue'],
            ['under_review', 'Under Review', 'fa-magnifying-glass', 'amber'],
            ['active_response', 'Active Response', 'fa-truck-medical', 'rose'],
            ['resolved_today', 'Resolved Today', 'fa-circle-check', 'emerald'],
        ];
        foreach ($summaryCards as [$key, $label, $icon, $tone]):
        ?>
          <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-[9px] font-black uppercase tracking-wider text-slate-400"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="mt-2 text-2xl font-black text-slate-800 dark:text-white" data-incident-summary="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">&mdash;</p>
              </div>
              <span class="incident-summary-icon" data-tone="<?php echo htmlspecialchars($tone, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid <?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i></span>
            </div>
            <p class="mt-2 text-[9px] font-bold text-slate-400">Live Supabase operational count</p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="rounded-2xl border border-slate-200/80 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900" aria-labelledby="incidentListTitle">
      <div class="border-b border-slate-100 p-4 dark:border-slate-800 sm:p-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
          <div>
            <h2 id="incidentListTitle" class="text-sm font-black text-slate-800 dark:text-white">Incident Records</h2>
            <p class="mt-1 text-[10px] font-medium text-slate-500 dark:text-slate-400">Search and filter up to the 200 most recent matching reports.</p>
          </div>
          <form class="grid w-full grid-cols-1 gap-2 sm:grid-cols-2 xl:w-auto xl:grid-cols-[minmax(220px,1fr)_repeat(3,150px)]" data-incident-filters>
            <label class="incident-filter-field">
              <span class="sr-only">Search incidents</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" maxlength="80" autocomplete="off" placeholder="Number, title, or location" data-filter-search>
            </label>
            <select class="incident-filter-select" aria-label="Filter by status" data-filter-status>
              <option value="">All statuses</option>
              <option value="SUBMITTED">Submitted</option>
              <option value="UNDER_REVIEW">Under Review</option>
              <option value="VERIFIED">Verified</option>
              <option value="ASSIGNED">Assigned</option>
              <option value="RESPONDING">Responding</option>
              <option value="RESOLVED">Resolved</option>
              <option value="CLOSED">Closed</option>
              <option value="REJECTED">Rejected</option>
            </select>
            <select class="incident-filter-select" aria-label="Filter by incident type" data-filter-type>
              <option value="">All types</option>
              <option value="FLOOD">Flood</option>
              <option value="FIRE">Fire</option>
              <option value="LANDSLIDE">Landslide</option>
              <option value="EARTHQUAKE">Earthquake</option>
              <option value="ROAD_BLOCKAGE">Road Blockage</option>
              <option value="FALLEN_TREE">Fallen Tree</option>
              <option value="STRUCTURAL_DAMAGE">Structural Damage</option>
              <option value="MEDICAL_EMERGENCY">Medical Emergency</option>
              <option value="UTILITY_HAZARD">Utility Hazard</option>
              <option value="OTHER">Other</option>
            </select>
            <select class="incident-filter-select" aria-label="Filter by severity" data-filter-severity>
              <option value="">All severities</option>
              <option value="LOW">Low</option>
              <option value="MODERATE">Moderate</option>
              <option value="HIGH">High</option>
              <option value="CRITICAL">Critical</option>
            </select>
          </form>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[1120px] text-left">
          <thead class="border-b border-slate-100 bg-slate-50/80 text-[9px] font-black uppercase tracking-wider text-slate-400 dark:border-slate-800 dark:bg-slate-950/40">
            <tr>
              <th class="px-4 py-3">Incident Number</th>
              <th class="px-4 py-3">Type</th>
              <th class="px-4 py-3">Title / Summary</th>
              <th class="px-4 py-3">Barangay / Location</th>
              <th class="px-4 py-3">Severity</th>
              <th class="px-4 py-3">Reported At</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Assigned To</th>
              <th class="px-4 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-xs dark:divide-slate-800" data-incident-table-body>
            <tr><td colspan="9" class="px-4 py-12 text-center text-xs font-bold text-slate-400">Loading incident reports...</td></tr>
          </tbody>
        </table>
      </div>
      <div class="border-t border-slate-100 px-4 py-3 text-[9px] font-bold text-slate-400 dark:border-slate-800" data-incident-list-count>Waiting for incident data.</div>
    </section>

    <aside class="rounded-2xl border border-sky-100 bg-sky-50/70 p-4 text-[10px] font-medium leading-relaxed text-sky-800 dark:border-sky-900/50 dark:bg-sky-950/20 dark:text-sky-300">
      <div class="flex items-start gap-3">
        <i class="fa-solid fa-circle-info mt-0.5" aria-hidden="true"></i>
        <p>An incident is an observed or reported event. It does not activate an early warning, feed TensorFlow, or enter an AI training dataset. Any warning decision remains a separate human workflow.</p>
      </div>
    </aside>
  </section>
</main>

<div class="incident-modal" data-incident-details-modal hidden>
  <div class="incident-modal-backdrop" data-close-incident-details></div>
  <section class="incident-modal-panel incident-details-panel" role="dialog" aria-modal="true" aria-labelledby="incidentDetailsTitle">
    <header class="incident-modal-header">
      <div class="min-w-0">
        <p class="text-[9px] font-black uppercase tracking-widest text-brand-dark dark:text-brand-medium" data-detail-number>Incident details</p>
        <h2 id="incidentDetailsTitle" class="mt-1 truncate text-base font-black text-slate-900 dark:text-white" data-detail-title>Loading incident...</h2>
      </div>
      <div class="flex items-center gap-2">
        <span class="incident-code-badge" data-detail-status data-code="SUBMITTED">Submitted</span>
        <button type="button" class="incident-icon-button" aria-label="Close incident details" data-close-incident-details><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
    </header>

    <div class="incident-modal-body space-y-5">
      <p class="incident-inline-status" data-detail-load-status role="status" aria-live="polite">Loading full incident record...</p>

      <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Incident metadata">
        <?php
        $detailFields = [
            'type' => 'Incident Type', 'severity' => 'Severity', 'reported_at' => 'Reported At',
            'source' => 'Source', 'barangay' => 'Barangay', 'location' => 'Location',
            'coordinates' => 'Coordinates', 'assignment' => 'Current Assignment',
            'verification' => 'Verification', 'resolved_at' => 'Resolved At',
            'closed_at' => 'Closed At', 'reporter_type' => 'Reporter Type',
        ];
        foreach ($detailFields as $key => $label):
        ?>
          <div class="incident-detail-field">
            <dt><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></dt>
            <dd data-detail-field="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">Not available</dd>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="incident-detail-section" aria-labelledby="incidentDescriptionTitle">
        <h3 id="incidentDescriptionTitle">Description</h3>
        <p class="whitespace-pre-wrap" data-detail-description>No description available.</p>
      </section>

      <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <section class="incident-detail-section" aria-labelledby="incidentHistoryTitle">
          <h3 id="incidentHistoryTitle">Status History</h3>
          <div class="incident-timeline" data-detail-history></div>
        </section>
        <section class="incident-detail-section" aria-labelledby="incidentResponseLogTitle">
          <h3 id="incidentResponseLogTitle">Response &amp; Activity Log</h3>
          <div class="incident-timeline" data-detail-response-logs></div>
        </section>
      </div>

      <section class="incident-detail-section" aria-labelledby="incidentAssignmentHistoryTitle">
        <h3 id="incidentAssignmentHistoryTitle">Assignment History</h3>
        <div class="incident-assignment-grid" data-detail-assignments></div>
      </section>
    </div>

    <footer class="incident-modal-footer">
      <div class="flex flex-wrap gap-2" data-detail-actions></div>
      <button type="button" class="incident-secondary-button" data-close-incident-details>Close</button>
    </footer>
  </section>
</div>

<div class="incident-modal" data-incident-action-modal hidden>
  <div class="incident-modal-backdrop" data-close-incident-action></div>
  <section class="incident-modal-panel incident-action-panel" role="dialog" aria-modal="true" aria-labelledby="incidentActionTitle">
    <form data-incident-action-form>
      <header class="incident-modal-header">
        <div>
          <p class="text-[9px] font-black uppercase tracking-widest text-brand-dark dark:text-brand-medium">Controlled Workflow Action</p>
          <h2 id="incidentActionTitle" class="mt-1 text-base font-black text-slate-900 dark:text-white" data-action-title>Update incident</h2>
        </div>
        <button type="button" class="incident-icon-button" aria-label="Close action" data-close-incident-action><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </header>

      <div class="incident-modal-body space-y-4">
        <p class="text-[10px] font-medium leading-relaxed text-slate-500 dark:text-slate-400" data-action-description></p>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" data-assignment-fields hidden>
          <label class="incident-form-field">
            <span>Department Reference</span>
            <input type="text" maxlength="200" autocomplete="off" placeholder="e.g. DEPARTMENT:12" data-assignment-department>
          </label>
          <label class="incident-form-field">
            <span>User Reference</span>
            <input type="text" maxlength="200" autocomplete="off" placeholder="e.g. USER:34" data-assignment-user>
          </label>
          <p class="sm:col-span-2 text-[9px] font-medium text-slate-400">Enter at least one stable identifier from the MySQL-owned CIVENTRAL employee/department system. No identity records are copied to Supabase.</p>
        </div>

        <label class="incident-form-field" data-response-type-field hidden>
          <span>Activity Type</span>
          <select data-response-action-type>
            <option value="DISPATCH_NOTE">Dispatch Note</option>
            <option value="RESPONSE_UPDATE">Response Update</option>
          </select>
        </label>

        <label class="incident-form-field">
          <span data-action-note-label>Operational Note</span>
          <textarea rows="5" maxlength="5000" placeholder="Enter a plain-text operational note" data-action-note></textarea>
        </label>

        <p class="incident-inline-status" data-action-status role="status" aria-live="polite" hidden></p>
      </div>

      <footer class="incident-modal-footer">
        <button type="button" class="incident-secondary-button" data-close-incident-action>Cancel</button>
        <button type="submit" class="incident-primary-button" data-submit-incident-action>Confirm Action</button>
      </footer>
    </form>
  </section>
</div>

<div class="incident-toast" data-incident-toast role="status" aria-live="polite" hidden></div>

<script>
window.CiventralIncidentConfig = Object.freeze({
  summaryEndpoint: <?php echo json_encode($basePath . 'api/drrm/incidents-summary.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
  listEndpoint: <?php echo json_encode($basePath . 'api/drrm/incidents.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
  detailsEndpoint: <?php echo json_encode($basePath . 'api/drrm/incident-details.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
  statusEndpoint: <?php echo json_encode($basePath . 'api/drrm/incident-status.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
  responseEndpoint: <?php echo json_encode($basePath . 'api/drrm/incident-response.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
  security: Object.freeze({
    csrfToken: <?php echo json_encode($incidentCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    capabilities: Object.freeze(<?php echo json_encode($incidentCapabilities, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)
  })
});
</script>
<script src="<?php echo htmlspecialchars($incidentJsUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>

<?php include '../../includes/footer.php'; ?>
