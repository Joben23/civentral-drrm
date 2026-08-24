<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$module1Page = file_get_contents($root . '/pages/drrm/hazard-evacuation-map.php');
$module1Markup = file_get_contents($root . '/includes/dashboard/hazard-evacuation-map.php');
$module1Script = file_get_contents($root . '/assets/js/drrm/hazard-evacuation-map.js');
$module4Page = file_get_contents($root . '/pages/drrm/disaster-early-warning.php');
$module4Script = file_get_contents($root . '/assets/js/drrm/disaster-early-warning.js');
$statusEndpoint = file_get_contents($root . '/api/drrm/ai-status.php');
$predictionEndpoint = file_get_contents($root . '/api/drrm/flood-risk-prediction.php');

foreach ([
    $module1Page, $module1Markup, $module1Script, $module4Page, $module4Script,
    $statusEndpoint, $predictionEndpoint,
] as $source) {
    if (!is_string($source)) {
        fwrite(STDERR, "An AI UI contract source file could not be read.\n");
        exit(1);
    }
}

$failures = [];
$assertionCount = 0;

function assertAiUi(string $name, bool $condition): void
{
    global $assertionCount, $failures;
    $assertionCount++;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

$browserUi = $module1Page . "\n" . $module4Page;
$applicationUi = $browserUi . "\n" . $module1Markup . "\n" . $module1Script . "\n" . $module4Script;

assertAiUi('Module1UsesPhpStatusEndpoint', str_contains($module1Page, "api/drrm/ai-status.php"));
assertAiUi('Module1UsesPhpPredictionEndpoint', str_contains($module1Page, "api/drrm/flood-risk-prediction.php"));
assertAiUi('Module4UsesPhpStatusEndpoint', str_contains($module4Page, "api/drrm/ai-status.php"));
assertAiUi('NoPrivateFastApiPortInBrowserUi', !str_contains($browserUi, '127.0.0.1:8098'));
assertAiUi('NoInternalKeyNameInBrowserUi', !str_contains($browserUi, 'CIVENTRAL_AI_INTERNAL_KEY'));
assertAiUi('Module1StatusFieldsPresent', array_reduce([
    'AI Service', 'TensorFlow Runtime', 'Model', 'Risk Policy', 'Prediction Ready', 'Last Checked',
], static fn (bool $present, string $label): bool => $present && str_contains($module1Page, $label), true));
assertAiUi('Module4StatusFieldsPresent', array_reduce([
    'data-ai-service-status', 'data-ai-tensorflow-status', 'data-ai-model-status',
    'data-ai-risk-policy-status', 'data-ai-prediction-ready', 'data-ai-last-checked',
], static fn (bool $present, string $hook): bool => $present && str_contains($module4Page, $hook), true));
assertAiUi('Module1PredictionUsesPost', str_contains($module1Page, "method: 'POST'"));
assertAiUi('Module1PredictionUsesSameOrigin', str_contains($module1Page, "credentials: 'same-origin'"));
assertAiUi('Module1PredictionSendsCsrf', str_contains($module1Page, "'X-CSRF-Token': aiConfig.csrfToken"));
assertAiUi('Module1PredictionSendsOnlyOwnedContext',
    str_contains($module1Page, 'request_id: requestId()')
    && str_contains($module1Page, 'location: { barangay_id: state.selectedBarangayId }'));
assertAiUi('BrowserDoesNotSendModelFeatures', array_reduce([
    'forecast_rainfall_24h_mm', 'antecedent_rainfall_24h_mm',
    'antecedent_rainfall_72h_mm', 'mgb_flood_susceptibility_code',
    'probability:', 'civentrial_risk_level:', 'civentral_risk_level:',
], static fn (bool $absent, string $field): bool => $absent && !str_contains($browserUi, $field), true));
assertAiUi('InputUnavailableMessageIsSafe', str_contains(
    $module1Page,
    'AI prediction is currently unavailable because verified rainfall inputs are not available.'
));
assertAiUi('ModelUnavailableMessageIsSafe', str_contains(
    $applicationUi,
    'TensorFlow model is not currently available for inference.'
));
assertAiUi('MgbAndAiRemainSeparate',
    str_contains($module1Markup, 'Mapped Flood Susceptibility')
    && str_contains($module1Page, 'DENR-MGB mapped susceptibility, PAGASA information, and TensorFlow decision support remain separate'));
assertAiUi('PagasaLimitationRemains', str_contains($module1Markup, 'PAGASA detailed forecast requires API access.'));
assertAiUi('HumanReviewWordingPresent', str_contains($browserUi, 'require DRRM officer review'));
assertAiUi('NoAggressivePolling', !str_contains($browserUi, 'setInterval('));
assertAiUi('ManualRefreshPresent',
    str_contains($module1Page, 'Refresh Status')
    && str_contains($module4Page, 'Refresh AI Status'));
assertAiUi('WarningMutationScriptUnchangedByAiUi',
    !str_contains($module1Page, 'DrrmEarlyWarningWriteService')
    && !str_contains($module4Page, 'DrrmEarlyWarningWriteService'));
assertAiUi('StatusEndpointKeepsViewAuthorization', str_contains($statusEndpoint, 'if (!$authorization->canView())'));
assertAiUi('PredictionEndpointKeepsViewAuthorization', str_contains($predictionEndpoint, 'if (!$authorization->canView())'));
assertAiUi('PredictionEndpointKeepsCsrf', str_contains($predictionEndpoint, '$csrfService->validate($csrfToken)'));
assertAiUi('NoFakeDemoMode',
    !str_contains(strtolower($browserUi), 'demo probability')
    && !str_contains(strtolower($browserUi), 'sample prediction')
    && !str_contains(strtolower($browserUi), 'simulation mode'));

if ($failures !== []) {
    fwrite(STDERR, 'AI UI contract failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'AiUiContractAssertions=' . $assertionCount . PHP_EOL;
echo "DrrmAiUiContract=PASS\n";
