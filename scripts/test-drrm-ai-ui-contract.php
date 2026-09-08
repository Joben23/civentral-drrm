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

assertAiUi('Module1DoesNotConfigureAiStatusEndpoint', !str_contains($module1Page, 'api/drrm/ai-status.php'));
assertAiUi('Module1DoesNotConfigureAiPredictionEndpoint', !str_contains($module1Page, 'api/drrm/flood-risk-prediction.php'));
assertAiUi('Module4UsesPhpStatusEndpoint', str_contains($module4Page, "api/drrm/ai-status.php"));
assertAiUi('NoPrivateFastApiPortInBrowserUi', !str_contains($browserUi, '127.0.0.1:8098'));
assertAiUi('NoInternalKeyNameInBrowserUi', !str_contains($browserUi, 'CIVENTRAL_AI_INTERNAL_KEY'));
assertAiUi(
    'Module1ShowsStaticAiUnavailableBoundary',
    str_contains($module1Markup, 'AI Flood Prediction')
    && str_contains($module1Markup, 'Not available')
    && str_contains($module1Markup, 'until a governed model and validated forecast inputs are ready')
    && !str_contains($module1Markup, 'runFloodAiPredictionButton')
);
assertAiUi('Module4StatusFieldsPresent', array_reduce([
    'data-ai-service-status', 'data-ai-tensorflow-status', 'data-ai-model-status',
    'data-ai-risk-policy-status', 'data-ai-prediction-ready', 'data-ai-last-checked',
], static fn (bool $present, string $hook): bool => $present && str_contains($module4Page, $hook), true));
assertAiUi('Module1GisCheckUsesPost', str_contains($module1Script, "method: 'POST'"));
assertAiUi('Module1GisCheckUsesSameOrigin', str_contains($module1Script, "credentials: 'same-origin'"));
assertAiUi('Module1GisCheckSendsModuleOneCsrf', str_contains($module1Script, "'X-CSRF-Token': config.csrfToken"));
assertAiUi('Module1GisCheckSendsOnlyCoordinates',
    str_contains($module1Script, 'latitude: location.latitude')
    && str_contains($module1Script, 'longitude: location.longitude')
    && !str_contains($module1Script, 'mgb_flood_susceptibility_code:'));
assertAiUi('BrowserDoesNotSendModelFeatures', array_reduce([
    'forecast_rainfall_24h_mm', 'antecedent_rainfall_24h_mm',
    'antecedent_rainfall_72h_mm', 'mgb_flood_susceptibility_code',
    'probability:', 'civentrial_risk_level:', 'civentral_risk_level:',
], static fn (bool $absent, string $field): bool => $absent && !str_contains($browserUi, $field), true));
assertAiUi('InputUnavailableMessageIsSafe', str_contains(
    $module1Markup,
    'validated forecast inputs are ready'
));
assertAiUi('ModelUnavailableMessageIsSafe', str_contains(
    $applicationUi,
    'TensorFlow prediction is unavailable until a governed model'
));
assertAiUi('MgbAndAiRemainSeparate',
    str_contains($module1Markup, 'controlled draft GIS polygons')
    && str_contains($module1Markup, 'It is not an AI prediction, real-time flood forecast, or official emergency guidance.'));
assertAiUi(
    'Module1DoesNotPresentForecastAsReferenceCheckInput',
    !str_contains($module1Markup, 'PAGASA Weather Outlook')
    && !str_contains($module1Markup, 'PAGASA detailed forecast requires API access.')
);
assertAiUi('HumanReviewWordingPresent', str_contains($browserUi, 'require DRRM officer review'));
assertAiUi('NoAggressivePolling', !str_contains($browserUi, 'setInterval('));
assertAiUi('ManualRefreshPresent',
    str_contains($module4Page, 'Refresh AI Status'));
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
