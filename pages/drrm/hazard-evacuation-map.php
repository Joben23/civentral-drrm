<?php
use App\Config\AppEnvironment;

$basePath = '../../';

require_once __DIR__ . '/../../config/app_environment.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmEarlyWarningCsrfService.php';
require_once __DIR__ . '/../../src/Services/DrrmMapAuthorizationService.php';

$aiAuthorization = \App\Services\DrrmEarlyWarningAuthorizationService::fromTrustedSession($headerUser);
$aiViewAuthorized = $aiAuthorization->canView();
$aiCsrfToken = $aiViewAuthorized
    ? (new \App\Services\DrrmEarlyWarningCsrfService())->token()
    : null;
$draftBarangayPreviewEnabled = AppEnvironment::allowsLocalDevelopmentRequest(
    __DIR__ . '/../../.env',
    $_SERVER
);
$stagingReferenceModeEnabled = AppEnvironment::isStaging(__DIR__ . '/../../.env');
$module1Authorization = \App\Services\DrrmMapAuthorizationService::fromTrustedSession($headerUser);
$stagingAdminCenterReferenceEnabled = $stagingReferenceModeEnabled
    && $module1Authorization->canView();
$stagingAdminBarangayReferenceEnabled = $stagingReferenceModeEnabled
    && $module1Authorization->canView();
$hazardMapCssRelativePath = 'assets/css/hazard-evacuation-map.css';
$operationalMapDataRelativePath = 'assets/js/drrm/operational-map-data.js';
$mgbLiveReferenceRelativePath = 'assets/js/drrm/mgb-live-reference.js';
$phivolcsLiveReferenceRelativePath = 'assets/js/drrm/phivolcs-live-reference.js';
$hazardMapJsRelativePath = 'assets/js/drrm/hazard-evacuation-map.js';
$hazardMapCssFile = __DIR__ . '/../../' . $hazardMapCssRelativePath;
$operationalMapDataFile = __DIR__ . '/../../' . $operationalMapDataRelativePath;
$mgbLiveReferenceFile = __DIR__ . '/../../' . $mgbLiveReferenceRelativePath;
$phivolcsLiveReferenceFile = __DIR__ . '/../../' . $phivolcsLiveReferenceRelativePath;
$hazardMapJsFile = __DIR__ . '/../../' . $hazardMapJsRelativePath;
$hazardMapCssVersion = filemtime($hazardMapCssFile);
$operationalMapDataVersion = hash_file('sha256', $operationalMapDataFile);
$mgbLiveReferenceVersion = filemtime($mgbLiveReferenceFile);
$phivolcsLiveReferenceVersion = filemtime($phivolcsLiveReferenceFile);
$hazardMapJsVersion = hash_file('sha256', $hazardMapJsFile);
$hazardMapCssUrl = $basePath . $hazardMapCssRelativePath . '?v=' . rawurlencode((string) $hazardMapCssVersion);
$operationalMapDataUrl = $basePath . $operationalMapDataRelativePath . '?v=' . rawurlencode((string) $operationalMapDataVersion);
$mgbLiveReferenceUrl = $basePath . $mgbLiveReferenceRelativePath . '?v=' . rawurlencode((string) $mgbLiveReferenceVersion);
$phivolcsLiveReferenceUrl = $basePath . $phivolcsLiveReferenceRelativePath . '?v=' . rawurlencode((string) $phivolcsLiveReferenceVersion);
$hazardMapJsUrl = $basePath . $hazardMapJsRelativePath . '?v=' . rawurlencode((string) $hazardMapJsVersion);
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<link
  rel="stylesheet"
  href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css"
  crossorigin=""
>
<link rel="stylesheet" href="<?php echo htmlspecialchars($hazardMapCssUrl, ENT_QUOTES, 'UTF-8'); ?>">
<style>
  .civ-hazard-map-module .civ-ai-status-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .45rem;
  }
  .civ-hazard-map-module .civ-ai-status-grid > div {
    min-width: 0;
    border: 1px solid #e2e8f0;
    border-radius: .55rem;
    background: #fff;
    padding: .45rem .5rem;
  }
  .civ-hazard-map-module .civ-ai-status-grid dt {
    color: #94a3b8;
    font-size: .5rem;
    font-weight: 900;
    letter-spacing: .06em;
    text-transform: uppercase;
  }
  .civ-hazard-map-module .civ-ai-status-grid dd {
    margin-top: .15rem;
    color: #334155;
    font-size: .58rem;
    font-weight: 800;
    overflow-wrap: anywhere;
  }
  .civ-hazard-map-module .civ-ai-action-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .45rem;
  }
  .civ-hazard-map-module .civ-ai-action-row button {
    width: 100%;
  }
  .civ-hazard-map-module .civ-ai-result {
    border: 1px solid #cbd5e1;
    border-radius: .65rem;
    background: #f8fafc;
    padding: .55rem .6rem;
    color: #475569;
    font-size: .6rem;
    font-weight: 700;
    line-height: 1.45;
  }
  .civ-hazard-map-module .civ-ai-result[data-tone="notice"] {
    border-color: #fde68a;
    background: #fffbeb;
    color: #92400e;
  }
  .dark .civ-hazard-map-module .civ-ai-status-grid > div {
    border-color: #334155;
    background: #0f172a;
  }
  .dark .civ-hazard-map-module .civ-ai-status-grid dd {
    color: #cbd5e1;
  }
  .dark .civ-hazard-map-module .civ-ai-result {
    border-color: #334155;
    background: #0f172a;
    color: #cbd5e1;
  }
  .dark .civ-hazard-map-module .civ-ai-result[data-tone="notice"] {
    border-color: #92400e;
    background: rgba(120, 53, 15, .2);
    color: #fcd34d;
  }
</style>

<main class="flex-1 min-w-0 w-full p-4 sm:p-6 md:p-8 overflow-y-auto">
  <?php include '../../includes/dashboard/hazard-evacuation-map.php'; ?>
</main>

<script
  src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"
  crossorigin=""
></script>
<?php if ($draftBarangayPreviewEnabled): ?>
<script src="https://cdn.jsdelivr.net/npm/@turf/turf@7.2.0/turf.min.js"></script>
<?php endif; ?>
<script src='<?php echo htmlspecialchars($operationalMapDataUrl, ENT_QUOTES, 'UTF-8'); ?>'></script>
<script src='<?php echo htmlspecialchars($mgbLiveReferenceUrl, ENT_QUOTES, 'UTF-8'); ?>'></script>
<script src='<?php echo htmlspecialchars($phivolcsLiveReferenceUrl, ENT_QUOTES, 'UTF-8'); ?>'></script>
<script>
  window.CiventralDrrmMapConfig = Object.freeze({
    dataMode: <?php echo json_encode(
        $draftBarangayPreviewEnabled ? 'development-preview' : 'operational',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>,
    operationalData: Object.freeze({
      enabled: <?php echo $draftBarangayPreviewEnabled ? 'false' : 'true'; ?>,
      barangaysEndpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? null : $basePath . 'api/drrm/barangays.php',
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>,
      hazardsEndpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? null : $basePath . 'api/drrm/hazard-zones.php',
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>,
      faultsEndpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? null : $basePath . 'api/drrm/fault-features.php',
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>,
      evacuationCentersEndpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? null : $basePath . 'api/drrm/evacuation-centers.php',
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>,
      evacuationRoutesEndpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? null : $basePath . 'api/drrm/evacuation-routes.php',
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>,
      lookupsEndpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? null : $basePath . 'api/drrm/lookups.php',
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    mgbLiveReference: Object.freeze({
      enabled: <?php echo $stagingReferenceModeEnabled ? 'true' : 'false'; ?>
    }),
    phivolcsLiveReference: Object.freeze({
      enabled: <?php echo $stagingReferenceModeEnabled ? 'true' : 'false'; ?>
    }),
    adminEvacuationCenterReference: Object.freeze({
      enabled: <?php echo $stagingAdminCenterReferenceEnabled ? 'true' : 'false'; ?>,
      endpoint: <?php echo json_encode(
          $stagingAdminCenterReferenceEnabled
              ? $basePath . 'api/drrm/admin-evacuation-center-reference.php'
              : null,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    adminBarangayReference: Object.freeze({
      enabled: <?php echo $stagingAdminBarangayReferenceEnabled ? 'true' : 'false'; ?>,
      endpoint: <?php echo json_encode(
          $stagingAdminBarangayReferenceEnabled
              ? $basePath . 'api/drrm/admin-barangay-reference.php'
              : null,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    draftBarangayPreview: Object.freeze({
      enabled: <?php echo $draftBarangayPreviewEnabled ? 'true' : 'false'; ?>,
      endpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? $basePath . 'api/drrm/dev/barangays-draft.php' : null,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    draftFloodPreview: Object.freeze({
      enabled: <?php echo $draftBarangayPreviewEnabled ? 'true' : 'false'; ?>,
      endpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? $basePath . 'api/drrm/dev/flood-zones-draft.php' : null,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    draftLandslidePreview: Object.freeze({
      enabled: <?php echo $draftBarangayPreviewEnabled ? 'true' : 'false'; ?>,
      endpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? $basePath . 'api/drrm/dev/landslide-zones-draft.php' : null,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    draftEvacuationCenterPreview: Object.freeze({
      enabled: <?php echo $draftBarangayPreviewEnabled ? 'true' : 'false'; ?>,
      endpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? $basePath . 'api/drrm/dev/evacuation-centers-draft.php' : null,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    draftFaultInformationPreview: Object.freeze({
      enabled: <?php echo $draftBarangayPreviewEnabled ? 'true' : 'false'; ?>,
      endpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? $basePath . 'api/drrm/dev/fault-information-draft.php' : null,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    developmentEvacuationRoute: Object.freeze({
      enabled: <?php echo $draftBarangayPreviewEnabled ? 'true' : 'false'; ?>,
      endpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? $basePath . 'api/drrm/dev/evacuation-route-preview.php' : null,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    developmentFloodForecast: Object.freeze({
      enabled: <?php echo $draftBarangayPreviewEnabled ? 'true' : 'false'; ?>,
      endpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? $basePath . 'api/drrm/dev/flood-forecast-preview.php' : null,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    cityBoundary: Object.freeze({
      endpoint: <?php echo json_encode(
          $basePath . 'data/import/caloocan-city-boundary.geojson',
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    aiIntegration: Object.freeze({
      authorized: <?php echo $aiViewAuthorized ? 'true' : 'false'; ?>,
      statusEndpoint: <?php echo json_encode(
          $basePath . 'api/drrm/ai-status.php',
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>,
      predictionEndpoint: <?php echo json_encode(
          $basePath . 'api/drrm/flood-risk-prediction.php',
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>,
      csrfToken: <?php echo json_encode(
          $aiCsrfToken,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    })
  });
</script>
<script src="<?php echo htmlspecialchars($hazardMapJsUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
  'use strict';

  const runtimeConfig = window.CiventralDrrmMapConfig || {};
  const aiConfig = runtimeConfig.aiIntegration && typeof runtimeConfig.aiIntegration === 'object'
    ? runtimeConfig.aiIntegration
    : {};
  const state = {
    initialized: false,
    statusLoading: false,
    statusFetchCount: 0,
    statusCode: 'NOT_LOADED',
    predictionPending: false,
    predictionFetchCount: 0,
    predictionAttempted: false,
    predictionResultCode: 'NOT_REQUESTED',
    barangaysByName: new Map(),
    selectedBarangayId: null
  };

  const safeMessages = Object.freeze({
    INPUT_DATA_UNAVAILABLE: 'AI prediction is currently unavailable because verified rainfall inputs are not available.',
    MODEL_NOT_AVAILABLE: 'TensorFlow model is not currently available for inference.',
    MODEL_INVALID: 'The configured TensorFlow model is not valid for inference.',
    RISK_POLICY_NOT_CONFIGURED: 'The CIVENTRAL risk policy is not configured for AI decision support.',
    AI_SERVICE_NOT_CONFIGURED: 'AI service access is not configured.',
    AI_SERVICE_UNREACHABLE: 'AI service is currently unavailable.',
    AI_AUTHENTICATION_FAILED: 'AI service authentication could not be completed.',
    AI_SERVICE_INVALID_RESPONSE: 'AI service status could not be verified safely.',
    AI_REQUEST_INVALID: 'The flood-risk request could not be validated.',
    AI_SERVICE_ERROR: 'AI decision support is temporarily unavailable.'
  });

  function createStatusCell(label, id) {
    const wrapper = document.createElement('div');
    const term = document.createElement('dt');
    const value = document.createElement('dd');
    term.textContent = label;
    value.id = id;
    value.textContent = 'Checking';
    wrapper.append(term, value);
    return wrapper;
  }

  function createActionButton(id, iconClass, label, className) {
    const button = document.createElement('button');
    const icon = document.createElement('i');
    const text = document.createElement('span');
    button.id = id;
    button.type = 'button';
    button.className = className;
    icon.className = iconClass;
    icon.setAttribute('aria-hidden', 'true');
    text.textContent = label;
    button.append(icon, text);
    return button;
  }

  function enhancePredictionSection() {
    const heading = document.getElementById('aiFloodPredictionTitle');
    const badge = document.getElementById('floodModelStatus');
    const content = document.getElementById('floodForecastContent');
    const section = heading ? heading.closest('section') : null;
    if (!heading || !badge || !content || !section || section.dataset.aiUiEnhanced === 'true') {
      return false;
    }

    section.dataset.aiUiEnhanced = 'true';
    heading.textContent = 'TensorFlow Flood-Risk Decision Support';
    badge.textContent = 'Checking';
    badge.dataset.tone = 'neutral';

    const grid = document.createElement('dl');
    grid.className = 'civ-ai-status-grid mt-2';
    grid.setAttribute('aria-label', 'AI prediction readiness');
    grid.append(
      createStatusCell('AI Service', 'floodAiServiceStatus'),
      createStatusCell('TensorFlow Runtime', 'floodTensorFlowStatus'),
      createStatusCell('Model', 'floodAiModelStatus'),
      createStatusCell('Risk Policy', 'floodAiRiskPolicyStatus'),
      createStatusCell('Prediction Ready', 'floodAiReadinessStatus'),
      createStatusCell('Last Checked', 'floodAiLastChecked')
    );

    const actions = document.createElement('div');
    actions.className = 'civ-ai-action-row mt-2';
    const refresh = createActionButton(
      'refreshFloodAiStatusButton',
      'fa-solid fa-rotate',
      'Refresh Status',
      'civ-route-secondary-button'
    );
    const predict = createActionButton(
      'runFloodAiPredictionButton',
      'fa-solid fa-brain',
      'Run AI Risk Check',
      'civ-route-button'
    );
    predict.disabled = true;
    actions.append(refresh, predict);

    content.className = 'civ-ai-result mt-2';
    content.dataset.tone = 'neutral';
    content.setAttribute('role', 'status');
    content.setAttribute('aria-live', 'polite');
    content.textContent = 'Select an exact location with a validated barangay before requesting AI decision support.';

    const limitation = document.createElement('p');
    limitation.className = 'civ-map-helper mt-2';
    limitation.textContent = 'Prediction requires verified weather inputs and an approved TensorFlow model. AI results require DRRM officer review and are not official PAGASA or DENR-MGB warnings.';

    section.insertBefore(grid, content);
    section.insertBefore(actions, content);
    section.appendChild(limitation);

    const footerNote = Array.from(section.parentElement ? section.parentElement.children : [])
      .find(function (element) {
        return element.matches && element.matches('p.civ-map-helper')
          && element.textContent.includes('TensorFlow AI prediction is not yet connected');
      });
    if (footerNote) {
      footerNote.textContent = 'Flood Risk Check evaluates the selected exact location. DENR-MGB mapped susceptibility, PAGASA information, and TensorFlow decision support remain separate evidence sources.';
    }
    return true;
  }

  function setText(id, value) {
    const element = document.getElementById(id);
    if (element) element.textContent = value;
  }

  function setResult(message, tone) {
    const content = document.getElementById('floodForecastContent');
    if (!content) return;
    content.textContent = message;
    content.dataset.tone = tone === 'notice' ? 'notice' : 'neutral';
  }

  function labelForModel(value) {
    return Object.freeze({
      MODEL_NOT_AVAILABLE: 'Not Available',
      MODEL_INVALID: 'Invalid',
      MODEL_AVAILABLE_NOT_OPERATIONALLY_VALIDATED: 'Not Operationally Validated',
      MODEL_READY: 'Ready',
      UNKNOWN: 'Unknown'
    })[value] || 'Unknown';
  }

  function labelForRiskPolicy(value) {
    return Object.freeze({
      NOT_CONFIGURED: 'Not Configured',
      INVALID: 'Invalid',
      AVAILABLE_NOT_APPROVED: 'Not Approved',
      READY: 'Ready',
      UNKNOWN: 'Unknown'
    })[value] || 'Unknown';
  }

  function statusMessage(code) {
    return safeMessages[code] || safeMessages.AI_SERVICE_ERROR;
  }

  function checkedTime() {
    return new Intl.DateTimeFormat('en-PH', {
      hour: 'numeric',
      minute: '2-digit',
      second: '2-digit'
    }).format(new Date());
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
    const badge = document.getElementById('floodModelStatus');
    const serviceHealthy = status.runtimeReachable && status.serviceHealth === 'HEALTHY';
    setText('floodAiServiceStatus', serviceHealthy ? 'Connected / Healthy' : 'Unavailable');
    setText(
      'floodTensorFlowStatus',
      status.tensorflowInstalled === true ? 'Available' : (status.tensorflowInstalled === false ? 'Not Installed' : 'Unknown')
    );
    setText('floodAiModelStatus', labelForModel(status.modelStatus));
    setText('floodAiRiskPolicyStatus', labelForRiskPolicy(status.riskPolicyStatus));
    setText('floodAiReadinessStatus', status.predictionReady ? 'Yes' : 'No');
    setText('floodAiLastChecked', checkedTime());
    if (badge) {
      badge.textContent = status.predictionReady ? 'Ready' : (serviceHealthy ? 'Not Ready' : 'Unavailable');
      badge.dataset.tone = status.predictionReady ? 'positive' : 'neutral';
    }
    state.statusCode = status.code;
  }

  function renderStatusUnavailable(code, authorized) {
    const badge = document.getElementById('floodModelStatus');
    setText('floodAiServiceStatus', authorized ? 'Unavailable' : 'Access Restricted');
    setText('floodTensorFlowStatus', 'Unknown');
    setText('floodAiModelStatus', 'Unknown');
    setText('floodAiRiskPolicyStatus', 'Unknown');
    setText('floodAiReadinessStatus', 'No');
    setText('floodAiLastChecked', checkedTime());
    if (badge) badge.textContent = authorized ? 'Unavailable' : 'Restricted';
    state.statusCode = code;
    if (!state.predictionAttempted) {
      setResult(
        authorized ? statusMessage(code) : 'AI decision support requires Disaster Early Warning VIEW permission.',
        'notice'
      );
    }
  }

  async function loadAiStatus() {
    const refresh = document.getElementById('refreshFloodAiStatusButton');
    if (state.statusLoading) return false;
    if (aiConfig.authorized !== true) {
      renderStatusUnavailable('AI_AUTHENTICATION_FAILED', false);
      updatePredictionButton();
      return false;
    }
    if (typeof aiConfig.statusEndpoint !== 'string' || aiConfig.statusEndpoint === '') {
      renderStatusUnavailable('AI_SERVICE_NOT_CONFIGURED', true);
      return false;
    }

    state.statusLoading = true;
    state.statusFetchCount += 1;
    if (refresh) refresh.disabled = true;
    setText('floodModelStatus', 'Checking');
    try {
      const response = await window.fetch(aiConfig.statusEndpoint, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });
      if (!response.ok) throw new Error('AI status request failed.');
      renderStatus(assertStatusPayload(await response.json()));
      return true;
    } catch (error) {
      renderStatusUnavailable('AI_SERVICE_UNREACHABLE', true);
      return false;
    } finally {
      state.statusLoading = false;
      if (refresh) refresh.disabled = false;
      updatePredictionButton();
    }
  }

  function normalizeBarangayName(value) {
    return String(value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('en-US');
  }

  async function loadValidatedBarangayMapping() {
    const preview = runtimeConfig.draftBarangayPreview;
    const operational = runtimeConfig.operationalData;
    const usePreview = runtimeConfig.dataMode === 'development-preview'
      && preview && preview.enabled === true && typeof preview.endpoint === 'string';
    const endpoint = usePreview
      ? preview.endpoint
      : (runtimeConfig.dataMode === 'operational'
        && operational && operational.enabled === true
        && typeof operational.barangaysEndpoint === 'string'
        ? operational.barangaysEndpoint
        : null);
    if (!endpoint) return false;
    try {
      const response = await window.fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/geo+json, application/json' }
      });
      if (!response.ok) throw new Error('Barangay mapping request failed.');
      const payload = await response.json();
      const records = usePreview
        ? (payload && payload.type === 'FeatureCollection' && Array.isArray(payload.features)
          ? payload.features.map(function (feature) { return feature && feature.properties; })
          : null)
        : (payload && payload.success === true && Array.isArray(payload.data) ? payload.data : null);
      if (!records || (usePreview && records.length !== 187)) {
        throw new Error('Barangay mapping response was invalid.');
      }
      const mapping = new Map();
      records.forEach(function (record) {
        if (!record || typeof record.name !== 'string'
          || typeof record.barangay_code !== 'string'
          || !/^\d{10}$/.test(record.barangay_code)
          || (usePreview && /^Barangay 176(?:-[A-F])?$/i.test(record.name.trim()))
          || (!usePreview && Object.prototype.hasOwnProperty.call(record, 'record_status')
            && record.record_status !== 'ACTIVE')) {
          throw new Error('Barangay mapping record was invalid.');
        }
        mapping.set(normalizeBarangayName(record.name), record.barangay_code);
      });
      if (mapping.size !== records.length || (usePreview && mapping.size !== 187)) {
        throw new Error('Barangay mapping was incomplete.');
      }
      state.barangaysByName = mapping;
      syncSelectedBarangay();
      return true;
    } catch (error) {
      state.barangaysByName = new Map();
      syncSelectedBarangay();
      return false;
    }
  }

  function syncSelectedBarangay() {
    const input = document.getElementById('forecastLocationInput');
    const match = input ? /^(Barangay\s+(?:[1-9]|[1-9]\d|1\d\d))\b/i.exec(input.value) : null;
    const name = match ? normalizeBarangayName(match[1]) : '';
    state.selectedBarangayId = name ? (state.barangaysByName.get(name) || null) : null;
    updatePredictionButton();
    if (!state.predictionAttempted) {
      setResult(
        state.selectedBarangayId
          ? 'Selected location has a validated barangay. Run the AI risk check to verify current availability.'
          : 'Select an exact location with a validated barangay before requesting AI decision support.',
        'neutral'
      );
    }
  }

  function updatePredictionButton() {
    const button = document.getElementById('runFloodAiPredictionButton');
    if (!button) return;
    button.disabled = aiConfig.authorized !== true || state.predictionPending || !state.selectedBarangayId;
  }

  function requestId() {
    if (globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function') {
      return 'ui-' + globalThis.crypto.randomUUID();
    }
    return 'ui-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
  }

  async function requestPrediction() {
    if (state.predictionPending || aiConfig.authorized !== true || !state.selectedBarangayId) return false;
    if (typeof aiConfig.predictionEndpoint !== 'string' || aiConfig.predictionEndpoint === ''
      || typeof aiConfig.csrfToken !== 'string' || aiConfig.csrfToken === '') {
      state.predictionAttempted = true;
      state.predictionResultCode = 'AI_SERVICE_NOT_CONFIGURED';
      setResult(statusMessage(state.predictionResultCode), 'notice');
      return false;
    }

    const button = document.getElementById('runFloodAiPredictionButton');
    const buttonLabel = button ? button.querySelector('span') : null;
    state.predictionPending = true;
    state.predictionAttempted = true;
    state.predictionFetchCount += 1;
    updatePredictionButton();
    if (buttonLabel) buttonLabel.textContent = 'Checking';
    setResult('Checking current AI prediction availability...', 'neutral');

    try {
      const response = await window.fetch(aiConfig.predictionEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': aiConfig.csrfToken
        },
        body: JSON.stringify({
          request_id: requestId(),
          location: { barangay_id: state.selectedBarangayId }
        })
      });
      let payload = null;
      try {
        payload = await response.json();
      } catch (error) {
        throw new Error('Invalid prediction response.');
      }
      const code = payload && typeof payload.code === 'string'
        ? payload.code
        : (payload && payload.data && typeof payload.data.code === 'string' ? payload.data.code : null);
      if (response.ok && payload && payload.success === true) {
        state.predictionResultCode = 'AVAILABLE_FOR_OFFICER_REVIEW';
        setResult('AI decision-support information is available for DRRM officer review.', 'neutral');
        return true;
      }
      state.predictionResultCode = code || 'AI_SERVICE_ERROR';
      setResult(statusMessage(state.predictionResultCode), 'notice');
      return false;
    } catch (error) {
      state.predictionResultCode = 'AI_SERVICE_UNREACHABLE';
      setResult(statusMessage(state.predictionResultCode), 'notice');
      return false;
    } finally {
      state.predictionPending = false;
      if (buttonLabel) buttonLabel.textContent = 'Run AI Risk Check';
      updatePredictionButton();
    }
  }

  function scheduleBarangaySync() {
    window.setTimeout(syncSelectedBarangay, 180);
  }

  function initialize() {
    if (state.initialized || !enhancePredictionSection()) return;
    state.initialized = true;
    const refresh = document.getElementById('refreshFloodAiStatusButton');
    const predict = document.getElementById('runFloodAiPredictionButton');
    const map = document.getElementById('civentralHazardMap');
    if (refresh) refresh.addEventListener('click', function () { void loadAiStatus(); });
    if (predict) predict.addEventListener('click', function () { void requestPrediction(); });
    if (map) map.addEventListener('click', scheduleBarangaySync);
    ['useRouteOriginForForecastButton', 'clearForecastLocationButton'].forEach(function (id) {
      const button = document.getElementById(id);
      if (button) button.addEventListener('click', scheduleBarangaySync);
    });
    updatePredictionButton();
    void loadAiStatus();
    void loadValidatedBarangayMapping();
  }

  window.CiventralFloodAiUi = Object.freeze({
    refreshStatus: loadAiStatus,
    diagnostics: function () {
      return Object.freeze({
        initialized: state.initialized,
        statusFetchCount: state.statusFetchCount,
        statusCode: state.statusCode,
        predictionFetchCount: state.predictionFetchCount,
        predictionResultCode: state.predictionResultCode,
        predictionPending: state.predictionPending,
        selectedBarangayValidated: Boolean(state.selectedBarangayId),
        authorized: aiConfig.authorized === true
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
