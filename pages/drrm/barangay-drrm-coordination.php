<?php

declare(strict_types=1);

$basePath = '../../';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmBarangayCoordinationAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmBarangayCoordinationCsrfService.php';

$authorization = \App\Services\DrrmBarangayCoordinationAuthorizationService::fromTrustedSession($headerUser);
if (!$authorization->canView()) {
    header('Location: ../dashboard.php');
    exit;
}

$canCreate = $authorization->canCreate();
$canEdit = $authorization->canEdit();
$csrfToken = $canCreate || $canEdit ? (new \App\Services\DrrmBarangayCoordinationCsrfService())->token() : null;

$currentDetails = is_array($_SESSION['current_user_details'] ?? null) ? $_SESSION['current_user_details'] : [];
$firstName = trim((string) ($currentDetails['first_name'] ?? ''));
$middleName = trim((string) ($currentDetails['middle_name'] ?? ''));
$lastName = trim((string) ($currentDetails['last_name'] ?? ''));
$displayName = trim((string) ($currentDetails['full_name'] ?? ''));
if ($displayName === '') {
    $displayName = trim($firstName . ' ' . ($middleName !== '' ? $middleName . ' ' : '') . $lastName);
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/drrm/relief-goods.css?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/../../assets/css/drrm/relief-goods.css')); ?>">
<main class="flex-1 min-w-0 w-full overflow-y-auto p-4 sm:p-6 md:p-8">
  <section class="mx-auto w-full max-w-[1500px] space-y-6" aria-labelledby="coordinationTitle">
    <header class="flex flex-col gap-4 border-b border-slate-200/70 pb-5 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-brand-dark">Disaster Risk Reduction &amp; Management</p>
        <h1 id="coordinationTitle" class="mt-1 text-xl font-black tracking-tight text-slate-900 sm:text-2xl">Barangay DRRM Coordination Tool</h1>
        <p class="mt-1 max-w-3xl text-xs font-medium leading-relaxed text-slate-500">Barangay situation status, assistance requests, and coordination history for emergency response coordination.</p>
      </div>
      <?php if ($canCreate): ?>
        <span class="inline-flex h-fit items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-emerald-700">
          <i class="fa-solid fa-lock-open" aria-hidden="true"></i>Coordination enabled
        </span>
      <?php endif; ?>
    </header>

    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Coordination summary cards">
      <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
        <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">Barangays Reporting</p>
        <p id="summary-barangays_reporting" class="mt-2 text-2xl font-black text-slate-800">0</p>
      </article>
      <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
        <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">Active Requests</p>
        <p id="summary-active_requests" class="mt-2 text-2xl font-black text-slate-800">0</p>
      </article>
      <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
        <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">Urgent Requests</p>
        <p id="summary-urgent_requests" class="mt-2 text-2xl font-black text-slate-800">0</p>
      </article>
      <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
        <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">Total Evacuees</p>
        <p id="summary-total_evacuees" class="mt-2 text-2xl font-black text-slate-800">0</p>
      </article>
    </section>

    <section class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(340px,1fr)]">
      <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-black text-slate-800">Current Barangay Situation</h2>
          <button type="button" id="refreshCoordination" class="rounded-lg border border-slate-200 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-slate-600">
            <i class="fa-solid fa-rotate" aria-hidden="true"></i> Refresh
          </button>
        </div>
        <div class="mt-4 overflow-x-auto">
          <table class="w-full min-w-[720px] text-left text-[11px]">
            <thead class="border-b border-slate-100 text-[9px] font-black uppercase tracking-wider text-slate-400">
              <tr>
                <th class="px-2 py-3">Barangay</th>
                <th class="px-2 py-3">Situation Level</th>
                <th class="px-2 py-3">Affected Households</th>
                <th class="px-2 py-3">Evacuees</th>
                <th class="px-2 py-3">Road/Access</th>
                <th class="px-2 py-3">Last Reported</th>
                <th class="px-2 py-3">Reported By</th>
              </tr>
            </thead>
            <tbody id="currentSituationsBody" class="divide-y divide-slate-100"></tbody>
          </table>
        </div>
      </section>

      <?php if ($canCreate): ?>
      <aside class="space-y-4">
        <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
          <h2 class="text-sm font-black text-slate-800">Create Situation Report</h2>
          <form id="statusReportForm" class="mt-4 space-y-3">
            <select name="barangay_id" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
              <option value="">Select barangay</option>
            </select>
            <select name="situation_level" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
              <option value="">Situation level</option>
              <option value="NORMAL">NORMAL</option>
              <option value="MONITORING">MONITORING</option>
              <option value="ELEVATED">ELEVATED</option>
              <option value="CRITICAL">CRITICAL</option>
            </select>
            <input name="affected_households" type="number" min="0" step="1" required placeholder="Affected households" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
            <input name="evacuees" type="number" min="0" step="1" required placeholder="Evacuees" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
            <select name="access_condition" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
              <option value="">Road / access condition</option>
              <option value="ACCESSIBLE">ACCESSIBLE</option>
              <option value="PARTIALLY_BLOCKED">PARTIALLY_BLOCKED</option>
              <option value="BLOCKED">BLOCKED</option>
              <option value="UNKNOWN">UNKNOWN</option>
            </select>
            <textarea name="situation_summary" required maxlength="250" rows="2" placeholder="Situation summary" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"></textarea>
            <textarea name="notes" maxlength="2000" rows="2" placeholder="Additional notes (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"></textarea>
            <input name="reported_at" type="datetime-local" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
            <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-[10px] font-black text-slate-600">
              <span class="text-slate-500">Submitting as:</span>
              <span class="text-slate-800"><?= htmlspecialchars($displayName !== '' ? $displayName : 'Authenticated user') ?></span>
            </div>
            <button type="submit" id="submitStatusReport" class="w-full rounded-lg bg-slate-900 px-3 py-2.5 text-[10px] font-black uppercase tracking-wider text-white">
              <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i> Submit report
            </button>
          </form>
          <div id="statusReportFeedback" class="mt-3 hidden rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[10px] font-black text-slate-600"></div>
        </section>

        <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
          <h2 class="text-sm font-black text-slate-800">Create Assistance Request</h2>
          <form id="assistanceRequestForm" class="mt-4 space-y-3">
            <select name="barangay_id" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
              <option value="">Select barangay</option>
            </select>
            <select name="request_category" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
              <option value="">Request category</option>
              <option value="RELIEF_GOODS">RELIEF_GOODS</option>
              <option value="RESCUE">RESCUE</option>
              <option value="MEDICAL">MEDICAL</option>
              <option value="EVACUATION">EVACUATION</option>
              <option value="EQUIPMENT">EQUIPMENT</option>
              <option value="ROAD_ACCESS">ROAD_ACCESS</option>
              <option value="INFORMATION">INFORMATION</option>
              <option value="OTHER">OTHER</option>
            </select>
            <select name="priority" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
              <option value="">Priority</option>
              <option value="NORMAL">NORMAL</option>
              <option value="HIGH">HIGH</option>
              <option value="URGENT">URGENT</option>
            </select>
            <textarea name="description" required maxlength="1000" rows="3" placeholder="Request description" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"></textarea>
            <input name="requested_at" type="datetime-local" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
            <div class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-[10px] font-black text-slate-600">
              <span class="text-slate-500">Submitting as:</span>
              <span class="text-slate-800"><?= htmlspecialchars($displayName !== '' ? $displayName : 'Authenticated user') ?></span>
            </div>
            <button type="submit" id="submitAssistanceRequest" class="w-full rounded-lg bg-brand-dark px-3 py-2.5 text-[10px] font-black uppercase tracking-wider text-white">
              <i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i> Request assistance
            </button>
          </form>
          <div id="assistanceRequestFeedback" class="mt-3 hidden rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[10px] font-black text-slate-600"></div>
        </section>
      </aside>
      <?php endif; ?>
    </section>

    <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-black text-slate-800">Assistance Requests</h2>
      </div>
      <div class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[900px] text-left text-[11px]">
          <thead class="border-b border-slate-100 text-[9px] font-black uppercase tracking-wider text-slate-400">
            <tr>
              <th class="px-2 py-3">Barangay</th>
              <th class="px-2 py-3">Category</th>
              <th class="px-2 py-3">Priority</th>
              <th class="px-2 py-3">Description</th>
              <th class="px-2 py-3">Status</th>
              <th class="px-2 py-3">Requested At</th>
              <th class="px-2 py-3">Requested By</th>
              <?php if ($canEdit): ?><th class="px-2 py-3">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="assistanceRequestsBody" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>
    </section>

    <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-black text-slate-800">Coordination History</h2>
      </div>
      <div class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[900px] text-left text-[11px]">
          <thead class="border-b border-slate-100 text-[9px] font-black uppercase tracking-wider text-slate-400">
            <tr>
              <th class="px-2 py-3">Barangay</th>
              <th class="px-2 py-3">Reported At</th>
              <th class="px-2 py-3">Situation Level</th>
              <th class="px-2 py-3">Evacuees</th>
              <th class="px-2 py-3">Road/Access</th>
              <th class="px-2 py-3">Summary</th>
              <th class="px-2 py-3">Reported By</th>
            </tr>
          </thead>
          <tbody id="historyBody" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>
    </section>

    <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-black text-slate-800">Assistance Request Response History</h2>
      </div>
      <div class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[900px] text-left text-[11px]">
          <thead class="border-b border-slate-100 text-[9px] font-black uppercase tracking-wider text-slate-400">
            <tr>
              <th class="px-2 py-3">Barangay / Request</th>
              <th class="px-2 py-3">Request Context</th>
              <th class="px-2 py-3">From Status</th>
              <th class="px-2 py-3">To Status</th>
              <th class="px-2 py-3">Response Note</th>
              <th class="px-2 py-3">Handled By</th>
              <th class="px-2 py-3">Timestamp</th>
            </tr>
          </thead>
          <tbody id="assistanceRequestHistoryBody" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>
    </section>
  </section>
</main>
<script>window.CiventralBarangayCoordinationConfig = <?php echo json_encode(['endpoint' => $basePath . 'api/drrm/barangay-coordination.php', 'csrfToken' => $csrfToken, 'canCreate' => $canCreate, 'canEdit' => $canEdit, 'displayName' => $displayName !== '' ? $displayName : 'Authenticated user'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo $basePath; ?>assets/js/drrm/barangay-coordination.js?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/../../assets/js/drrm/barangay-coordination.js')); ?>"></script>
