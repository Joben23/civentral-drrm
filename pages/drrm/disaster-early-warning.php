<?php
$basePath = '../../';

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningCsrfService.php';

$authService->requireAuth($basePath);

$earlyWarningAuthorization = \App\Services\DrrmEarlyWarningAuthorizationService::fromTrustedSession($headerUser);

if (!$earlyWarningAuthorization->canView()) {
    // Match the existing protected-page convention without exposing Module 4.
    header('Location: ../dashboard.php');
    exit;
}

$earlyWarningCsrf = new \App\Services\DrrmEarlyWarningCsrfService();
$earlyWarningCsrfToken = $earlyWarningCsrf->token();
$earlyWarningCapabilities = $earlyWarningAuthorization->capabilities();

$earlyWarningCssRelativePath = 'assets/css/disaster-early-warning.css';
$earlyWarningJsRelativePath = 'assets/js/drrm/disaster-early-warning.js';
$phivolcsJsRelativePath = 'assets/js/disaster-early-warning-phivolcs.js';
$earlyWarningCssFile = __DIR__ . '/../../' . $earlyWarningCssRelativePath;
$earlyWarningJsFile = __DIR__ . '/../../' . $earlyWarningJsRelativePath;
$phivolcsJsFile = __DIR__ . '/../../' . $phivolcsJsRelativePath;
$earlyWarningCssVersion = filemtime($earlyWarningCssFile);
$earlyWarningJsVersion = filemtime($earlyWarningJsFile);
$phivolcsJsVersion = filemtime($phivolcsJsFile);
$earlyWarningCssUrl = $basePath . $earlyWarningCssRelativePath . '?v=' . rawurlencode((string) $earlyWarningCssVersion);
$earlyWarningJsUrl = $basePath . $earlyWarningJsRelativePath . '?v=' . rawurlencode((string) $earlyWarningJsVersion);
$phivolcsJsUrl = $basePath . $phivolcsJsRelativePath . '?v=' . rawurlencode((string) $phivolcsJsVersion);
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars($earlyWarningCssUrl, ENT_QUOTES, 'UTF-8'); ?>">

<main class="flex-1 min-w-0 w-0 overflow-y-auto p-4 sm:p-6 md:p-8">
  <section class="mx-auto w-full max-w-[1600px] space-y-6" aria-labelledby="earlyWarningTitle">
    <header class="flex flex-col gap-4 border-b border-slate-200/70 pb-5 dark:border-slate-800 lg:flex-row lg:items-start lg:justify-between">
      <div class="space-y-1.5">
        <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.18em] text-brand-dark dark:text-brand-medium">
          <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
          <span>Disaster Risk Reduction &amp; Management</span>
        </div>
        <h1 id="earlyWarningTitle" class="text-xl font-black tracking-tight text-slate-900 dark:text-white sm:text-2xl">
          Disaster Early Warning System
        </h1>
        <p class="max-w-4xl text-xs font-medium leading-relaxed text-slate-500 dark:text-slate-400 sm:text-sm">
          Monitor disaster advisories, risk levels, and emergency warning information for Caloocan City.
        </p>
      </div>

      <div class="flex shrink-0 flex-wrap items-center gap-2 self-start">
        <?php if ($earlyWarningCapabilities['canCreateWarning']): ?>
          <button type="button" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-3.5 py-2 text-[10px] font-black uppercase tracking-wider text-white shadow-sm transition hover:bg-slate-800 dark:bg-brand-medium dark:text-slate-950" data-open-create-warning>
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
            Create Warning
          </button>
        <?php endif; ?>
        <div class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-400">
          <span class="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>
          <span>Development Preview</span>
        </div>
      </div>
    </header>

    <section class="space-y-3" aria-labelledby="warningSummaryTitle">
      <div>
        <h2 id="warningSummaryTitle" class="text-sm font-black text-slate-800 dark:text-white">Warning Summary</h2>
        <p class="mt-1 text-[10px] font-medium text-slate-500 dark:text-slate-400" data-early-warning-load-status role="status" aria-live="polite">Loading early-warning records...</p>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">Active Warnings</p>
              <p class="mt-2 text-2xl font-black text-slate-800 dark:text-white" data-summary-value="active-warnings">0</p>
            </div>
            <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-rose-100 bg-rose-50 text-rose-600 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-400"><i class="fa-solid fa-bell" aria-hidden="true"></i></span>
          </div>
          <p class="mt-2 text-[9px] font-bold text-slate-400" data-summary-note="active-warnings">Loading active warning count</p>
        </article>

        <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">High-Risk Areas</p>
              <p class="mt-2 text-2xl font-black text-slate-800 dark:text-white" data-summary-value="high-risk-areas">0</p>
            </div>
            <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-orange-100 bg-orange-50 text-orange-600 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-400"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
          </div>
          <p class="mt-2 text-[9px] font-bold text-slate-400" data-summary-note="high-risk-areas">Loading affected-area count</p>
        </article>

        <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">Weather Advisories</p>
              <p class="mt-2 text-2xl font-black text-slate-800 dark:text-white" data-summary-value="weather-advisories">0</p>
            </div>
            <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-sky-100 bg-sky-50 text-sky-600 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-400"><i class="fa-solid fa-cloud-sun-rain" aria-hidden="true"></i></span>
          </div>
          <p class="mt-2 text-[9px] font-bold text-slate-400" data-summary-note="weather-advisories">Loading weather advisory count</p>
        </article>

        <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-[9px] font-black uppercase tracking-wider text-slate-400">Alerts Sent Today</p>
              <p class="mt-2 text-2xl font-black text-slate-800 dark:text-white" data-summary-value="alerts-sent-today">0</p>
            </div>
            <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-violet-100 bg-violet-50 text-violet-600 dark:border-violet-900/50 dark:bg-violet-950/30 dark:text-violet-400"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></span>
          </div>
          <p class="mt-2 text-[9px] font-bold text-slate-400" data-summary-note="alerts-sent-today">Delivery tracking is not implemented</p>
        </article>
      </div>
    </section>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
      <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900" aria-labelledby="currentWarningStatusTitle">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p class="text-[9px] font-black uppercase tracking-widest text-brand-dark dark:text-brand-medium">Local Monitoring</p>
            <h2 id="currentWarningStatusTitle" class="mt-1 text-sm font-black text-slate-800 dark:text-white">Current Warning Status</h2>
          </div>
          <span class="inline-flex items-center gap-2 self-start rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-[9px] font-black uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400" data-current-warning-badge>
            <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
            <span data-current-warning-badge-text>No Active Local Warning</span>
          </span>
        </div>

        <div class="mt-5 rounded-xl border border-dashed border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/50">
          <p class="text-xs font-black text-slate-700 dark:text-slate-200" data-current-warning-title>No active local warning</p>
          <p class="mt-1 text-[10px] font-medium leading-relaxed text-slate-500 dark:text-slate-400" data-current-warning-summary>No ACTIVE warning records are currently stored.</p>
        </div>

        <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <?php
          $warningFields = [
              'warning_level' => 'Warning Level',
              'hazard_type' => 'Hazard Type',
              'affected_area' => 'Affected Area',
              'issued_at' => 'Issued At',
              'source' => 'Source',
              'valid_until' => 'Valid Until',
          ];
          foreach ($warningFields as $warningFieldKey => $warningField):
          ?>
            <div class="rounded-xl border border-slate-100 p-3 dark:border-slate-800">
              <dt class="text-[9px] font-black uppercase tracking-wider text-slate-400"><?php echo htmlspecialchars($warningField, ENT_QUOTES, 'UTF-8'); ?></dt>
              <dd class="mt-1.5 text-[11px] font-bold text-slate-500 dark:text-slate-400" data-current-warning-field="<?php echo htmlspecialchars($warningFieldKey, ENT_QUOTES, 'UTF-8'); ?>">Not available</dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </section>

      <aside class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900" aria-labelledby="warningLevelsTitle">
        <div class="flex items-center gap-3">
          <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-brand-border bg-brand-light text-brand-dark dark:border-slate-700 dark:bg-slate-800 dark:text-brand-medium"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i></span>
          <div>
            <h2 id="warningLevelsTitle" class="text-sm font-black text-slate-800 dark:text-white">Warning Levels</h2>
            <p class="mt-0.5 text-[9px] font-bold uppercase tracking-wider text-slate-400">CIVENTRAL development scale</p>
          </div>
        </div>

        <ul class="mt-4 grid grid-cols-2 gap-2" aria-label="CIVENTRAL development warning classifications">
          <li class="flex items-center gap-2 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-[10px] font-black text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-400"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>LOW</li>
          <li class="flex items-center gap-2 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-[10px] font-black text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-400"><span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>MODERATE</li>
          <li class="flex items-center gap-2 rounded-lg border border-orange-100 bg-orange-50 px-3 py-2 text-[10px] font-black text-orange-700 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-400"><span class="h-2.5 w-2.5 rounded-full bg-orange-500"></span>HIGH</li>
          <li class="flex items-center gap-2 rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-[10px] font-black text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-400"><span class="h-2.5 w-2.5 rounded-full bg-rose-600"></span>CRITICAL</li>
        </ul>

        <p class="mt-4 border-l-2 border-amber-400 pl-3 text-[9px] font-medium leading-relaxed text-slate-500 dark:text-slate-400">
          CIVENTRAL warning levels are development decision-support classifications and must not replace official government advisories.
        </p>
      </aside>
    </div>

    <section class="space-y-3" aria-labelledby="advisorySourcesTitle">
      <div>
        <h2 id="advisorySourcesTitle" class="text-sm font-black text-slate-800 dark:text-white">Advisory Sources</h2>
        <p class="mt-1 text-[10px] font-medium text-slate-500 dark:text-slate-400">Read-only official-source availability; external information remains separate from local CIVENTRAL warnings.</p>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900" data-source-card="PAGASA">
          <div class="flex items-start justify-between gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-sky-100 bg-sky-50 text-sky-600 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-400"><i class="fa-solid fa-cloud-sun-rain" aria-hidden="true"></i></span>
            <span class="rounded-lg bg-amber-50 px-2 py-1 text-[8px] font-black uppercase tracking-wider text-amber-700 dark:bg-amber-950/30 dark:text-amber-400" data-source-status>Integration Pending</span>
          </div>
          <h3 class="mt-3 text-xs font-black text-slate-800 dark:text-white" data-source-name>DOST-PAGASA</h3>
          <p class="mt-1 text-[10px] font-medium leading-relaxed text-slate-500 dark:text-slate-400">Weather / rainfall / tropical cyclone information</p>
          <div class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 text-[9px] font-bold dark:border-slate-800">
            <p class="text-slate-500 dark:text-slate-400" data-pagasa-public-feed-status>Official public feed: Checking</p>
            <p class="text-slate-500 dark:text-slate-400" data-pagasa-detailed-api-status>Detailed API: Checking</p>
          </div>
        </article>

        <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900" data-source-card="PHIVOLCS">
          <div class="flex items-start justify-between gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-violet-100 bg-violet-50 text-violet-600 dark:border-violet-900/50 dark:bg-violet-950/30 dark:text-violet-400"><i class="fa-solid fa-house-crack" aria-hidden="true"></i></span>
            <span class="rounded-lg bg-amber-50 px-2 py-1 text-[8px] font-black uppercase tracking-wider text-amber-700 dark:bg-amber-950/30 dark:text-amber-400" data-source-status>Integration Pending</span>
          </div>
          <h3 class="mt-3 text-xs font-black text-slate-800 dark:text-white" data-source-name>DOST-PHIVOLCS</h3>
          <p class="mt-1 text-[10px] font-medium leading-relaxed text-slate-500 dark:text-slate-400">Earthquake / volcanic advisory information</p>
          <div class="mt-3 border-t border-slate-100 pt-3 text-[9px] font-bold dark:border-slate-800">
            <p class="text-slate-500 dark:text-slate-400" data-phivolcs-feed-status>Official machine-readable event feed: Checking</p>
          </div>
        </article>

        <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900" data-source-card="NDRRMC">
          <div class="flex items-start justify-between gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-rose-100 bg-rose-50 text-rose-600 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-400"><i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i></span>
            <span class="rounded-lg bg-amber-50 px-2 py-1 text-[8px] font-black uppercase tracking-wider text-amber-700 dark:bg-amber-950/30 dark:text-amber-400" data-source-status>Integration Pending</span>
          </div>
          <h3 class="mt-3 text-xs font-black text-slate-800 dark:text-white" data-source-name>National Disaster Risk Reduction and Management Council</h3>
          <p class="mt-1 text-[10px] font-medium leading-relaxed text-slate-500 dark:text-slate-400">National disaster advisories</p>
          <div class="mt-3 border-t border-slate-100 pt-3 text-[9px] font-bold dark:border-slate-800">
            <p class="text-slate-500 dark:text-slate-400" data-ndrrmc-feed-status>Official machine-readable feed: Checking</p>
          </div>
        </article>

        <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900" data-source-card="CIVENTRAL">
          <div class="flex items-start justify-between gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-brand-border bg-brand-light text-brand-dark dark:border-slate-700 dark:bg-slate-800 dark:text-brand-medium"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
            <span class="rounded-lg bg-amber-50 px-2 py-1 text-[8px] font-black uppercase tracking-wider text-amber-700 dark:bg-amber-950/30 dark:text-amber-400" data-source-status>Integration Pending</span>
          </div>
          <h3 class="mt-3 text-xs font-black text-slate-800 dark:text-white" data-source-name>CIVENTRAL DRRM</h3>
          <p class="mt-1 text-[10px] font-medium leading-relaxed text-slate-500 dark:text-slate-400">Local CIVENTRAL warning records</p>
        </article>
      </div>
    </section>

    <section class="rounded-2xl border border-rose-200/80 bg-rose-50/30 p-5 shadow-xs dark:border-rose-900/50 dark:bg-rose-950/10" aria-labelledby="ndrrmcAdvisoryPreviewTitle">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p class="text-[9px] font-black uppercase tracking-widest text-rose-700 dark:text-rose-400">Official external information</p>
          <h2 id="ndrrmcAdvisoryPreviewTitle" class="mt-1 text-sm font-black text-slate-800 dark:text-white">NDRRMC Advisory Preview</h2>
          <p class="mt-1 text-[10px] font-medium text-slate-500 dark:text-slate-400">Read-only source availability; no website scraping or substitute feed is used.</p>
        </div>
        <span class="inline-flex self-start rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[8px] font-black uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400" data-ndrrmc-runtime-badge>Checking</span>
      </div>

      <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-white bg-white/80 p-3 dark:border-slate-800 dark:bg-slate-900/80">
          <dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Machine-Readable Source</dt>
          <dd class="mt-1.5 text-[10px] font-bold text-slate-600 dark:text-slate-300" data-ndrrmc-source-status>Checking availability</dd>
        </div>
        <div class="rounded-xl border border-white bg-white/80 p-3 dark:border-slate-800 dark:bg-slate-900/80">
          <dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Relevant Advisories</dt>
          <dd class="mt-1.5 text-[10px] font-bold text-slate-600 dark:text-slate-300" data-ndrrmc-advisory-count>Checking availability</dd>
        </div>
        <div class="rounded-xl border border-white bg-white/80 p-3 dark:border-slate-800 dark:bg-slate-900/80">
          <dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Relevance Filtering</dt>
          <dd class="mt-1.5 text-[10px] font-bold text-slate-600 dark:text-slate-300" data-ndrrmc-relevance-status>Checking availability</dd>
        </div>
      </dl>

      <div class="mt-4 rounded-xl border border-dashed border-rose-200 bg-white/70 p-4 dark:border-rose-900/60 dark:bg-slate-900/60">
        <p class="text-[11px] font-black text-slate-700 dark:text-slate-200" data-ndrrmc-advisory-title>No applicable NDRRMC advisory available.</p>
        <p class="mt-1 text-[9px] font-medium leading-relaxed text-slate-500 dark:text-slate-400" data-ndrrmc-advisory-message>Checking for a confirmed official machine-readable source.</p>
      </div>

      <p class="mt-3 border-l-2 border-rose-400 pl-3 text-[9px] font-medium leading-relaxed text-slate-500 dark:text-slate-400">
        NDRRMC information would remain an external official reference. It must not become a CIVENTRAL local early warning without future human review.
      </p>
    </section>

    <section class="rounded-2xl border border-violet-200/80 bg-violet-50/30 p-5 shadow-xs dark:border-violet-900/50 dark:bg-violet-950/10" aria-labelledby="phivolcsInformationPreviewTitle">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p class="text-[9px] font-black uppercase tracking-widest text-violet-700 dark:text-violet-400">Official external information</p>
          <h2 id="phivolcsInformationPreviewTitle" class="mt-1 text-sm font-black text-slate-800 dark:text-white">PHIVOLCS Information Preview</h2>
          <p class="mt-1 text-[10px] font-medium text-slate-500 dark:text-slate-400">Read-only source availability; bulletin pages and static hazard layers are not scraped into events.</p>
        </div>
        <span class="inline-flex self-start rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[8px] font-black uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400" data-phivolcs-runtime-badge>Checking</span>
      </div>

      <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-white bg-white/80 p-3 dark:border-slate-800 dark:bg-slate-900/80">
          <dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Machine-Readable Event Source</dt>
          <dd class="mt-1.5 text-[10px] font-bold text-slate-600 dark:text-slate-300" data-phivolcs-source-status>Checking availability</dd>
        </div>
        <div class="rounded-xl border border-white bg-white/80 p-3 dark:border-slate-800 dark:bg-slate-900/80">
          <dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Applicable Events</dt>
          <dd class="mt-1.5 text-[10px] font-bold text-slate-600 dark:text-slate-300" data-phivolcs-event-count>Checking availability</dd>
        </div>
        <div class="rounded-xl border border-white bg-white/80 p-3 dark:border-slate-800 dark:bg-slate-900/80">
          <dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Caloocan / NCR Relevance</dt>
          <dd class="mt-1.5 text-[10px] font-bold text-slate-600 dark:text-slate-300" data-phivolcs-relevance-status>Checking availability</dd>
        </div>
      </dl>

      <div class="mt-4 rounded-xl border border-dashed border-violet-200 bg-white/70 p-4 dark:border-violet-900/60 dark:bg-slate-900/60">
        <p class="text-[11px] font-black text-slate-700 dark:text-slate-200" data-phivolcs-information-title>No applicable PHIVOLCS information available.</p>
        <p class="mt-1 text-[9px] font-medium leading-relaxed text-slate-500 dark:text-slate-400" data-phivolcs-information-message>Checking for a confirmed official machine-readable operational source.</p>
      </div>

      <p class="mt-3 border-l-2 border-violet-400 pl-3 text-[9px] font-medium leading-relaxed text-slate-500 dark:text-slate-400">
        PHIVOLCS information remains external official context. Static fault proximity does not establish or remove earthquake risk, and no external item becomes a CIVENTRAL warning without human review.
      </p>
    </section>

    <section class="rounded-2xl border border-sky-200/80 bg-sky-50/40 p-5 shadow-xs dark:border-sky-900/50 dark:bg-sky-950/10" aria-labelledby="pagasaAdvisoryPreviewTitle">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p class="text-[9px] font-black uppercase tracking-widest text-sky-700 dark:text-sky-400">Official external information</p>
          <h2 id="pagasaAdvisoryPreviewTitle" class="mt-1 text-sm font-black text-slate-800 dark:text-white">PAGASA Information Preview</h2>
          <p class="mt-1 text-[10px] font-medium text-slate-500 dark:text-slate-400">Read-only DOST-PAGASA availability and issuance metadata.</p>
        </div>
        <span class="inline-flex self-start rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[8px] font-black uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400" data-pagasa-runtime-badge>Checking</span>
      </div>

      <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-white bg-white/80 p-3 dark:border-slate-800 dark:bg-slate-900/80">
          <dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Latest Issuance</dt>
          <dd class="mt-1.5 text-[10px] font-bold text-slate-600 dark:text-slate-300" data-pagasa-issued-at>Checking official source</dd>
        </div>
        <div class="rounded-xl border border-white bg-white/80 p-3 dark:border-slate-800 dark:bg-slate-900/80">
          <dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Forecast Period</dt>
          <dd class="mt-1.5 text-[10px] font-bold text-slate-600 dark:text-slate-300" data-pagasa-forecast-period>Checking official source</dd>
        </div>
        <div class="rounded-xl border border-white bg-white/80 p-3 dark:border-slate-800 dark:bg-slate-900/80">
          <dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Coverage</dt>
          <dd class="mt-1.5 text-[10px] font-bold text-slate-600 dark:text-slate-300" data-pagasa-coverage>National issuance metadata</dd>
        </div>
        <div class="rounded-xl border border-white bg-white/80 p-3 dark:border-slate-800 dark:bg-slate-900/80">
          <dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Detailed Caloocan API</dt>
          <dd class="mt-1.5 text-[10px] font-bold text-slate-600 dark:text-slate-300" data-pagasa-detailed-status>Checking access</dd>
        </div>
      </dl>

      <div class="mt-4 rounded-xl border border-dashed border-sky-200 bg-white/70 p-4 dark:border-sky-900/60 dark:bg-slate-900/60">
        <p class="text-[11px] font-black text-slate-700 dark:text-slate-200" data-pagasa-advisory-title>No applicable PAGASA advisory available.</p>
        <p class="mt-1 text-[9px] font-medium leading-relaxed text-slate-500 dark:text-slate-400" data-pagasa-advisory-message>Checking the configured official PAGASA source.</p>
      </div>

      <p class="mt-3 border-l-2 border-sky-400 pl-3 text-[9px] font-medium leading-relaxed text-slate-500 dark:text-slate-400">
        PAGASA information remains an official external reference. It is not automatically a CIVENTRAL local early warning and requires future human review before any warning record is created.
      </p>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(280px,2fr)]">
      <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900" aria-labelledby="deliveryChannelsTitle">
        <div>
          <h2 id="deliveryChannelsTitle" class="text-sm font-black text-slate-800 dark:text-white">Alert Delivery Channels</h2>
          <p class="mt-1 text-[10px] font-medium text-slate-500 dark:text-slate-400">Development placeholders only; no alert delivery action is available.</p>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <article class="rounded-xl border border-slate-100 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-800/50">
            <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300"><i class="fa-solid fa-bell text-brand-dark dark:text-brand-medium" aria-hidden="true"></i><h3 class="text-[10px] font-black">In-App Notification</h3></div>
            <p class="mt-2 text-[9px] font-black uppercase tracking-wider text-slate-400">Not Connected</p>
          </article>
          <article class="rounded-xl border border-slate-100 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-800/50">
            <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300"><i class="fa-solid fa-envelope text-brand-dark dark:text-brand-medium" aria-hidden="true"></i><h3 class="text-[10px] font-black">Email Alert</h3></div>
            <p class="mt-2 text-[9px] font-black uppercase tracking-wider text-slate-400">Not Connected</p>
          </article>
          <article class="rounded-xl border border-slate-100 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-800/50">
            <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300"><i class="fa-solid fa-comment-sms text-brand-dark dark:text-brand-medium" aria-hidden="true"></i><h3 class="text-[10px] font-black">SMS Alert</h3></div>
            <p class="mt-2 text-[9px] font-black uppercase tracking-wider text-slate-400">Not Connected</p>
          </article>
        </div>
      </section>

      <section class="rounded-2xl border border-brand-border/80 bg-brand-light/50 p-5 shadow-xs dark:border-slate-700 dark:bg-slate-900" aria-labelledby="aiRiskPredictionTitle">
        <div class="flex items-start justify-between gap-3">
          <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-brand-border bg-white text-brand-dark dark:border-slate-700 dark:bg-slate-800 dark:text-brand-medium"><i class="fa-solid fa-brain" aria-hidden="true"></i></span>
          <span class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-[8px] font-black uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400" data-ai-readiness-badge>Checking</span>
        </div>
        <h2 id="aiRiskPredictionTitle" class="mt-3 text-sm font-black text-slate-800 dark:text-white">AI Risk Prediction</h2>
        <p class="mt-1 text-[9px] font-black uppercase tracking-wider text-brand-dark dark:text-brand-medium">TensorFlow-Based Decision Support</p>
        <dl class="mt-3 grid grid-cols-2 gap-2" aria-label="AI infrastructure and prediction readiness">
          <div class="rounded-xl border border-slate-200/80 bg-white/80 p-2.5 dark:border-slate-700 dark:bg-slate-800/70"><dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">AI Service</dt><dd class="mt-1 text-[10px] font-black text-slate-700 dark:text-slate-200" data-ai-service-status>Checking</dd></div>
          <div class="rounded-xl border border-slate-200/80 bg-white/80 p-2.5 dark:border-slate-700 dark:bg-slate-800/70"><dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">TensorFlow Runtime</dt><dd class="mt-1 text-[10px] font-black text-slate-700 dark:text-slate-200" data-ai-tensorflow-status>Checking</dd></div>
          <div class="rounded-xl border border-slate-200/80 bg-white/80 p-2.5 dark:border-slate-700 dark:bg-slate-800/70"><dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Model</dt><dd class="mt-1 text-[10px] font-black text-slate-700 dark:text-slate-200" data-ai-model-status>Checking</dd></div>
          <div class="rounded-xl border border-slate-200/80 bg-white/80 p-2.5 dark:border-slate-700 dark:bg-slate-800/70"><dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Risk Policy</dt><dd class="mt-1 text-[10px] font-black text-slate-700 dark:text-slate-200" data-ai-risk-policy-status>Checking</dd></div>
          <div class="rounded-xl border border-slate-200/80 bg-white/80 p-2.5 dark:border-slate-700 dark:bg-slate-800/70"><dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Prediction Ready</dt><dd class="mt-1 text-[10px] font-black text-slate-700 dark:text-slate-200" data-ai-prediction-ready>Checking</dd></div>
          <div class="rounded-xl border border-slate-200/80 bg-white/80 p-2.5 dark:border-slate-700 dark:bg-slate-800/70"><dt class="text-[8px] font-black uppercase tracking-wider text-slate-400">Last Checked</dt><dd class="mt-1 text-[10px] font-black text-slate-700 dark:text-slate-200" data-ai-last-checked>Not checked</dd></div>
        </dl>
        <p class="mt-3 rounded-xl border border-slate-200 bg-white/70 px-3 py-2 text-[10px] font-semibold leading-relaxed text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300" role="status" aria-live="polite" data-ai-status-message>Loading sanitized AI service status...</p>
        <button type="button" class="mt-3 inline-flex items-center gap-2 rounded-lg border border-brand-border bg-white px-3 py-2 text-[9px] font-black uppercase tracking-wider text-brand-dark transition hover:bg-brand-light disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-brand-medium" data-refresh-ai-status>
          <i class="fa-solid fa-rotate" aria-hidden="true"></i>
          <span>Refresh AI Status</span>
        </button>
        <p class="mt-3 text-[9px] font-medium leading-relaxed text-slate-500 dark:text-slate-400">Prediction requires verified weather inputs and an approved TensorFlow model. AI results are decision-support information and require DRRM officer review.</p>
      </section>
    </div>

    <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900" aria-labelledby="recentWarningsTitle">
      <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800">
        <h2 id="recentWarningsTitle" class="text-sm font-black text-slate-800 dark:text-white">Recent Warnings</h2>
        <p class="mt-1 text-[10px] font-medium text-slate-500 dark:text-slate-400" data-recent-warnings-description>Loading warning records...</p>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-[850px] w-full text-left">
          <thead class="bg-slate-50 dark:bg-slate-800/70">
            <tr class="text-[9px] font-black uppercase tracking-wider text-slate-400">
              <th scope="col" class="px-5 py-3">Warning</th>
              <th scope="col" class="px-4 py-3">Hazard Type</th>
              <th scope="col" class="px-4 py-3">Affected Area</th>
              <th scope="col" class="px-4 py-3">Level</th>
              <th scope="col" class="px-4 py-3">Source</th>
              <th scope="col" class="px-4 py-3">Issued At</th>
              <th scope="col" class="px-5 py-3">Status</th>
              <th scope="col" class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody data-recent-warnings-body>
            <tr>
              <td colspan="8" class="px-5 py-10 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center">
                  <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500"><i class="fa-regular fa-bell-slash" aria-hidden="true"></i></span>
                  <p class="mt-3 text-[11px] font-black text-slate-600 dark:text-slate-300">No warning records available.</p>
                  <p class="mt-1 text-[9px] font-medium text-slate-400">No placeholder warning rows have been generated.</p>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</main>

<?php if ($earlyWarningCapabilities['canCreateWarning']): ?>
<div class="fixed inset-0 z-[80] hidden items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm sm:p-6" data-create-warning-modal hidden>
  <section class="max-h-[94vh] w-full max-w-4xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900" role="dialog" aria-modal="true" aria-labelledby="createWarningModalTitle">
    <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-100 bg-white px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
      <div>
        <p class="text-[9px] font-black uppercase tracking-widest text-brand-dark dark:text-brand-medium">Human-reviewed workflow</p>
        <h2 id="createWarningModalTitle" class="mt-1 text-base font-black text-slate-900 dark:text-white">Create Warning Draft</h2>
        <p class="mt-1 text-[10px] font-medium text-slate-500 dark:text-slate-400">Saving creates a DRAFT only. No alert is delivered or activated automatically.</p>
      </div>
      <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" data-close-create-warning aria-label="Close Create Warning">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </header>

    <form class="space-y-5 p-5" data-create-warning-form novalidate>
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <label class="space-y-1.5 md:col-span-2">
          <span class="text-[10px] font-black uppercase tracking-wider text-slate-500">Warning Title</span>
          <input type="text" name="title" maxlength="180" required class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-800 outline-none focus:border-brand-medium dark:border-slate-700 dark:bg-slate-950 dark:text-white">
        </label>

        <label class="space-y-1.5">
          <span class="text-[10px] font-black uppercase tracking-wider text-slate-500">Hazard Type</span>
          <select name="hazard_type" required class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-800 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
            <option value="">Select hazard type</option>
            <option value="FLOOD">Flood</option>
            <option value="HEAVY_RAINFALL">Heavy Rainfall</option>
            <option value="TROPICAL_CYCLONE">Tropical Cyclone</option>
            <option value="LANDSLIDE">Landslide</option>
            <option value="EARTHQUAKE">Earthquake</option>
            <option value="VOLCANIC_ACTIVITY">Volcanic Activity</option>
            <option value="OTHER">Other</option>
          </select>
        </label>

        <label class="space-y-1.5">
          <span class="text-[10px] font-black uppercase tracking-wider text-slate-500">CIVENTRAL Warning Level</span>
          <select name="warning_level" required class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-800 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
            <option value="">Select warning level</option>
            <option value="LOW">Low</option>
            <option value="MODERATE">Moderate</option>
            <option value="HIGH">High</option>
            <option value="CRITICAL">Critical</option>
          </select>
          <span class="block text-[9px] font-medium text-slate-400">CIVENTRAL decision-support classification, not an agency-authored scale.</span>
        </label>

        <label class="space-y-1.5">
          <span class="text-[10px] font-black uppercase tracking-wider text-slate-500">Source</span>
          <select name="source_code" required class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-800 dark:border-slate-700 dark:bg-slate-950 dark:text-white" data-warning-source>
            <option value="">Select source</option>
            <option value="CIVENTRAL">CIVENTRAL DRRM</option>
            <option value="PAGASA">DOST-PAGASA</option>
            <option value="PHIVOLCS">DOST-PHIVOLCS</option>
            <option value="NDRRMC">NDRRMC</option>
          </select>
        </label>

        <label class="space-y-1.5">
          <span class="text-[10px] font-black uppercase tracking-wider text-slate-500">Source Reference</span>
          <input type="text" name="source_reference" maxlength="1000" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-800 outline-none focus:border-brand-medium dark:border-slate-700 dark:bg-slate-950 dark:text-white" data-source-reference>
          <span class="block text-[9px] font-medium text-slate-400" data-source-reference-help>Required for manually encoded PAGASA, PHIVOLCS, or NDRRMC information.</span>
        </label>

        <label class="space-y-1.5">
          <span class="text-[10px] font-black uppercase tracking-wider text-slate-500">Issued At</span>
          <input type="datetime-local" name="issued_at" required class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-800 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
        </label>

        <label class="space-y-1.5">
          <span class="text-[10px] font-black uppercase tracking-wider text-slate-500">Valid Until</span>
          <input type="datetime-local" name="valid_until" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-800 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
        </label>

        <label class="space-y-1.5 md:col-span-2">
          <span class="text-[10px] font-black uppercase tracking-wider text-slate-500">Summary</span>
          <textarea name="summary" maxlength="5000" rows="4" required class="w-full resize-y rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold leading-relaxed text-slate-800 outline-none focus:border-brand-medium dark:border-slate-700 dark:bg-slate-950 dark:text-white"></textarea>
        </label>
      </div>

      <fieldset class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
        <legend class="px-1 text-[10px] font-black uppercase tracking-wider text-slate-500">Affected Area</legend>
        <div class="flex flex-wrap gap-4 text-xs font-bold text-slate-700 dark:text-slate-200">
          <label class="inline-flex items-center gap-2"><input type="radio" name="scope_type" value="CITY" checked class="h-4 w-4 accent-slate-900"> Entire Caloocan City</label>
          <label class="inline-flex items-center gap-2"><input type="radio" name="scope_type" value="BARANGAY" class="h-4 w-4 accent-slate-900"> Selected Barangays</label>
        </div>

        <div class="hidden space-y-3" data-barangay-selector hidden>
          <input type="search" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-800 dark:border-slate-700 dark:bg-slate-950 dark:text-white" placeholder="Search Barangay" data-barangay-search>
          <p class="text-[9px] font-medium text-slate-400" data-barangay-load-status>Validated barangays load when needed.</p>
          <div class="grid max-h-52 grid-cols-1 gap-2 overflow-y-auto rounded-xl border border-slate-100 p-2 sm:grid-cols-2 lg:grid-cols-3 dark:border-slate-800" data-barangay-options></div>
        </div>
      </fieldset>

      <p class="hidden rounded-xl border px-3 py-2 text-[10px] font-bold" data-warning-workflow-status role="status" aria-live="polite"></p>

      <footer class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end dark:border-slate-800">
        <button type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" data-close-create-warning>Cancel</button>
        <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-black text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-brand-medium dark:text-slate-950" data-save-warning-draft>Save as Draft</button>
      </footer>
    </form>
  </section>
</div>
<?php endif; ?>

<div class="fixed inset-0 z-[80] hidden items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm sm:p-6" data-review-warning-modal hidden>
  <section class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900" role="dialog" aria-modal="true" aria-labelledby="reviewWarningModalTitle">
    <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
      <div>
        <p class="text-[9px] font-black uppercase tracking-widest text-brand-dark dark:text-brand-medium">Human review required</p>
        <h2 id="reviewWarningModalTitle" class="mt-1 text-base font-black text-slate-900 dark:text-white">Review Warning</h2>
      </div>
      <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" data-close-review-warning aria-label="Close Review Warning"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </header>
    <div class="space-y-4 p-5">
      <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2"><dt class="text-[9px] font-black uppercase tracking-wider text-slate-400">Title</dt><dd class="mt-1 text-sm font-black text-slate-800 dark:text-white" data-review-field="title">Not available</dd></div>
        <div><dt class="text-[9px] font-black uppercase tracking-wider text-slate-400">Hazard</dt><dd class="mt-1 text-xs font-bold text-slate-700 dark:text-slate-300" data-review-field="hazard">Not available</dd></div>
        <div><dt class="text-[9px] font-black uppercase tracking-wider text-slate-400">CIVENTRAL Warning Level</dt><dd class="mt-1 text-xs font-bold text-slate-700 dark:text-slate-300" data-review-field="level">Not available</dd></div>
        <div class="sm:col-span-2"><dt class="text-[9px] font-black uppercase tracking-wider text-slate-400">Affected Area(s)</dt><dd class="mt-1 text-xs font-bold text-slate-700 dark:text-slate-300" data-review-field="areas">Not available</dd></div>
        <div><dt class="text-[9px] font-black uppercase tracking-wider text-slate-400">Source</dt><dd class="mt-1 text-xs font-bold text-slate-700 dark:text-slate-300" data-review-field="source">Not available</dd></div>
        <div><dt class="text-[9px] font-black uppercase tracking-wider text-slate-400">Status</dt><dd class="mt-1 text-xs font-bold text-slate-700 dark:text-slate-300" data-review-field="status">Not available</dd></div>
        <div class="sm:col-span-2"><dt class="text-[9px] font-black uppercase tracking-wider text-slate-400">Source Reference</dt><dd class="mt-1 break-words text-xs font-bold text-slate-700 dark:text-slate-300" data-review-field="source_reference">Not provided</dd></div>
        <div><dt class="text-[9px] font-black uppercase tracking-wider text-slate-400">Issued At</dt><dd class="mt-1 text-xs font-bold text-slate-700 dark:text-slate-300" data-review-field="issued_at">Not available</dd></div>
        <div><dt class="text-[9px] font-black uppercase tracking-wider text-slate-400">Valid Until</dt><dd class="mt-1 text-xs font-bold text-slate-700 dark:text-slate-300" data-review-field="valid_until">Not available</dd></div>
        <div class="sm:col-span-2 rounded-xl border border-slate-100 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-950"><dt class="text-[9px] font-black uppercase tracking-wider text-slate-400">Summary</dt><dd class="mt-1 whitespace-pre-wrap text-xs font-medium leading-relaxed text-slate-700 dark:text-slate-300" data-review-field="summary">Not available</dd></div>
      </dl>

      <p class="rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-[9px] font-bold leading-relaxed text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-400">Activation changes only the CIVENTRAL warning status. Alert delivery is not connected yet.</p>
      <p class="hidden rounded-xl border px-3 py-2 text-[10px] font-bold" data-review-workflow-status role="status" aria-live="polite"></p>

      <footer class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
        <button type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300" data-close-review-warning>Close</button>
        <button type="button" class="hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-xs font-black text-rose-700 hover:bg-rose-100 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-400" data-cancel-warning hidden>Cancel Warning</button>
        <button type="button" class="hidden rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-black text-white hover:bg-slate-800 dark:bg-brand-medium dark:text-slate-950" data-activate-warning hidden>Activate Warning</button>
      </footer>
    </div>
  </section>
</div>

<script>
  window.CiventralEarlyWarningConfig = Object.freeze({
    endpoint: <?php echo json_encode(
        $basePath . 'api/drrm/early-warning-summary.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>,
    pagasaEndpoint: <?php echo json_encode(
        $basePath . 'api/drrm/pagasa-advisories.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>,
    ndrrmcEndpoint: <?php echo json_encode(
        $basePath . 'api/drrm/ndrrmc-advisories.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>,
    phivolcsEndpoint: <?php echo json_encode(
        $basePath . 'api/drrm/phivolcs-advisories.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>,
    barangaysEndpoint: <?php echo json_encode(
        $basePath . 'api/drrm/early-warning-barangays.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>,
    createEndpoint: <?php echo json_encode(
        $basePath . 'api/drrm/early-warning-create.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>,
    statusEndpoint: <?php echo json_encode(
        $basePath . 'api/drrm/early-warning-status.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>,
    aiStatusEndpoint: <?php echo json_encode(
        $basePath . 'api/drrm/ai-status.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>,
    security: Object.freeze({
      csrfToken: <?php echo json_encode(
          $earlyWarningCsrfToken,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>,
      capabilities: Object.freeze(<?php echo json_encode(
          $earlyWarningCapabilities,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>)
    })
  });
</script>
<script src="<?php echo htmlspecialchars($earlyWarningJsUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($phivolcsJsUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
  'use strict';

  const config = window.CiventralEarlyWarningConfig || {};
  const state = {
    initialized: false,
    loading: false,
    fetchCount: 0,
    code: 'NOT_LOADED',
    runtimeReachable: false,
    predictionReady: false
  };
  const safeMessages = Object.freeze({
    READY: 'Approved AI flood-risk decision support is ready for officer review.',
    MODEL_NOT_AVAILABLE: 'TensorFlow model is not currently available for inference.',
    MODEL_INVALID: 'The configured TensorFlow model is not valid for inference.',
    MODEL_AVAILABLE_NOT_OPERATIONALLY_VALIDATED: 'The available TensorFlow model is not operationally validated.',
    RISK_POLICY_NOT_CONFIGURED: 'The CIVENTRAL risk policy is not configured for AI decision support.',
    AI_SERVICE_NOT_CONFIGURED: 'AI service access is not configured.',
    AI_SERVICE_UNREACHABLE: 'AI service is currently unavailable. Warning management remains available.',
    AI_AUTHENTICATION_FAILED: 'AI service authentication could not be completed.',
    AI_SERVICE_INVALID_RESPONSE: 'AI service status could not be verified safely.',
    AI_SERVICE_ERROR: 'AI service status is temporarily unavailable.'
  });

  function setText(selector, value) {
    const element = document.querySelector(selector);
    if (element) element.textContent = value;
  }

  function modelLabel(value) {
    return Object.freeze({
      MODEL_NOT_AVAILABLE: 'Not Available',
      MODEL_INVALID: 'Invalid',
      MODEL_AVAILABLE_NOT_OPERATIONALLY_VALIDATED: 'Not Operationally Validated',
      MODEL_READY: 'Ready',
      UNKNOWN: 'Unknown'
    })[value] || 'Unknown';
  }

  function riskPolicyLabel(value) {
    return Object.freeze({
      NOT_CONFIGURED: 'Not Configured',
      INVALID: 'Invalid',
      AVAILABLE_NOT_APPROVED: 'Not Approved',
      READY: 'Ready',
      UNKNOWN: 'Unknown'
    })[value] || 'Unknown';
  }

  function checkedTime() {
    return new Intl.DateTimeFormat('en-PH', {
      hour: 'numeric',
      minute: '2-digit',
      second: '2-digit'
    }).format(new Date());
  }

  function setBadge(label, tone) {
    const badge = document.querySelector('[data-ai-readiness-badge]');
    if (!badge) return;
    badge.textContent = label;
    badge.className = 'rounded-lg border px-2 py-1 text-[8px] font-black uppercase tracking-wider';
    if (tone === 'positive') {
      badge.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-700', 'dark:border-emerald-900/50', 'dark:bg-emerald-950/30', 'dark:text-emerald-400');
    } else if (tone === 'unavailable') {
      badge.classList.add('border-amber-200', 'bg-amber-50', 'text-amber-700', 'dark:border-amber-900/50', 'dark:bg-amber-950/30', 'dark:text-amber-400');
    } else {
      badge.classList.add('border-slate-200', 'bg-white', 'text-slate-500', 'dark:border-slate-700', 'dark:bg-slate-800', 'dark:text-slate-400');
    }
  }

  function assertStatusPayload(payload) {
    const data = payload && payload.success === true ? payload.data : null;
    if (!data || typeof data !== 'object' || Array.isArray(data)
      || typeof data.runtime_reachable !== 'boolean'
      || typeof data.service_health !== 'string'
      || !(typeof data.tensorflow_installed === 'boolean' || data.tensorflow_installed === null)
      || typeof data.model_status !== 'string'
      || typeof data.risk_policy_status !== 'string'
      || typeof data.prediction_ready !== 'boolean'
      || typeof data.code !== 'string') {
      throw new Error('Invalid AI status response.');
    }
    return Object.freeze({
      runtimeReachable: data.runtime_reachable,
      serviceHealth: data.service_health,
      tensorflowInstalled: data.tensorflow_installed,
      modelStatus: data.model_status,
      riskPolicyStatus: data.risk_policy_status,
      predictionReady: data.prediction_ready,
      code: data.code
    });
  }

  function renderStatus(status) {
    const serviceHealthy = status.runtimeReachable && status.serviceHealth === 'HEALTHY';
    setText('[data-ai-service-status]', serviceHealthy ? 'Connected / Healthy' : 'Unavailable');
    setText(
      '[data-ai-tensorflow-status]',
      status.tensorflowInstalled === true ? 'Available' : (status.tensorflowInstalled === false ? 'Not Installed' : 'Unknown')
    );
    setText('[data-ai-model-status]', modelLabel(status.modelStatus));
    setText('[data-ai-risk-policy-status]', riskPolicyLabel(status.riskPolicyStatus));
    setText('[data-ai-prediction-ready]', status.predictionReady ? 'Yes' : 'No');
    setText('[data-ai-last-checked]', checkedTime());
    setText('[data-ai-status-message]', safeMessages[status.code] || safeMessages.AI_SERVICE_ERROR);
    setBadge(
      status.predictionReady ? 'Ready' : (serviceHealthy ? 'Not Ready' : 'Service Unavailable'),
      status.predictionReady ? 'positive' : (serviceHealthy ? 'neutral' : 'unavailable')
    );
    state.code = status.code;
    state.runtimeReachable = status.runtimeReachable;
    state.predictionReady = status.predictionReady;
  }

  function renderUnavailable(code) {
    setText('[data-ai-service-status]', 'Unavailable');
    setText('[data-ai-tensorflow-status]', 'Unknown');
    setText('[data-ai-model-status]', 'Unknown');
    setText('[data-ai-risk-policy-status]', 'Unknown');
    setText('[data-ai-prediction-ready]', 'No');
    setText('[data-ai-last-checked]', checkedTime());
    setText('[data-ai-status-message]', safeMessages[code] || safeMessages.AI_SERVICE_ERROR);
    setBadge('Service Unavailable', 'unavailable');
    state.code = code;
    state.runtimeReachable = false;
    state.predictionReady = false;
  }

  async function loadStatus() {
    const refresh = document.querySelector('[data-refresh-ai-status]');
    if (state.loading) return false;
    if (typeof config.aiStatusEndpoint !== 'string' || config.aiStatusEndpoint === '') {
      renderUnavailable('AI_SERVICE_NOT_CONFIGURED');
      return false;
    }

    state.loading = true;
    state.fetchCount += 1;
    if (refresh) refresh.disabled = true;
    setBadge('Checking', 'neutral');
    try {
      const response = await window.fetch(config.aiStatusEndpoint, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });
      if (!response.ok) throw new Error('AI status request failed.');
      renderStatus(assertStatusPayload(await response.json()));
      return true;
    } catch (error) {
      renderUnavailable('AI_SERVICE_UNREACHABLE');
      return false;
    } finally {
      state.loading = false;
      if (refresh) refresh.disabled = false;
    }
  }

  function initialize() {
    if (state.initialized) return;
    state.initialized = true;
    const refresh = document.querySelector('[data-refresh-ai-status]');
    if (refresh) refresh.addEventListener('click', function () { void loadStatus(); });
    void loadStatus();
  }

  window.CiventralEarlyWarningAiStatus = Object.freeze({
    refresh: loadStatus,
    diagnostics: function () {
      return Object.freeze({
        initialized: state.initialized,
        loading: state.loading,
        fetchCount: state.fetchCount,
        code: state.code,
        runtimeReachable: state.runtimeReachable,
        predictionReady: state.predictionReady
      });
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
</script>

<?php include '../../includes/footer.php'; ?>
