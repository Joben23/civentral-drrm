<?php
use App\Config\AppEnvironment;

require_once __DIR__ . '/../../config/app_environment.php';

$basePath = '../../';
$draftBarangayPreviewEnabled = AppEnvironment::allowsLocalDevelopmentRequest(
    __DIR__ . '/../../.env',
    $_SERVER
);
$hazardMapCssRelativePath = 'assets/css/hazard-evacuation-map.css';
$hazardMapJsRelativePath = 'assets/js/drrm/hazard-evacuation-map.js';
$hazardMapCssFile = __DIR__ . '/../../' . $hazardMapCssRelativePath;
$hazardMapJsFile = __DIR__ . '/../../' . $hazardMapJsRelativePath;
$hazardMapCssVersion = filemtime($hazardMapCssFile);
$hazardMapJsVersion = filemtime($hazardMapJsFile);
$hazardMapCssUrl = $basePath . $hazardMapCssRelativePath . '?v=' . rawurlencode((string) $hazardMapCssVersion);
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

<main class="flex-1 min-w-0 w-full p-4 sm:p-6 md:p-8 overflow-y-auto">
  <?php include '../../includes/dashboard/hazard-evacuation-map.php'; ?>
</main>

<script
  src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"
  crossorigin=""
></script>
<script>
  window.CiventralDrrmMapConfig = Object.freeze({
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
    draftEvacuationCenterPreview: Object.freeze({
      enabled: <?php echo $draftBarangayPreviewEnabled ? 'true' : 'false'; ?>,
      endpoint: <?php echo json_encode(
          $draftBarangayPreviewEnabled ? $basePath . 'api/drrm/dev/evacuation-centers-draft.php' : null,
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    }),
    cityBoundary: Object.freeze({
      endpoint: <?php echo json_encode(
          $basePath . 'data/import/caloocan-city-boundary.geojson',
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ); ?>
    })
  });
</script>
<script src="<?php echo htmlspecialchars($hazardMapJsUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>

<?php include '../../includes/footer.php'; ?>
