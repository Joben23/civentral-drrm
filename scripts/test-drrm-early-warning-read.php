<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../src/Services/DrrmEarlyWarningReadService.php';

use App\Config\SupabaseConfig;
use App\Services\DrrmEarlyWarningReadService;
use App\Services\SupabaseRestClient;

function assertEarlyWarningRead(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
    $client = new SupabaseRestClient($config);
    $service = new DrrmEarlyWarningReadService($client);

    $warningRowsBefore = $client->get('early_warnings', ['select' => 'id', 'order' => 'id.asc']);
    $areaRowsBefore = $client->get('early_warning_areas', ['select' => 'id', 'order' => 'id.asc']);
    $summary = $service->dashboardSummary();

    $expectedMetrics = [
        'active_warnings' => $service->activeWarningCount(),
        'high_risk_areas' => $service->highRiskActiveAreaCount(),
        'weather_advisories' => $service->weatherAdvisoryCount(),
        'alerts_sent_today' => 0,
    ];
    assertEarlyWarningRead($summary['metrics'] === $expectedMetrics, 'The Module 4 dashboard metrics changed unexpectedly.');
    assertEarlyWarningRead(
        ($expectedMetrics['active_warnings'] === 0) === ($summary['current_warning'] === null),
        'The current-warning projection does not match the active-warning state.'
    );
    assertEarlyWarningRead(
        count($summary['recent_warnings']) === min(count($warningRowsBefore), 10),
        'The recent-warning projection returned an unexpected row count.'
    );
    assertEarlyWarningRead(
        ($summary['metric_metadata']['alerts_sent_today']['implemented'] ?? null) === false,
        'Alert delivery tracking was incorrectly reported as implemented.'
    );

    $sources = $summary['sources'];
    assertEarlyWarningRead(count($sources) === 4, 'The dashboard did not return four warning sources.');

    $actualSourceCodes = array_column($sources, 'source_code');
    assertEarlyWarningRead(
        $actualSourceCodes === ['CIVENTRAL', 'NDRRMC', 'PAGASA', 'PHIVOLCS'],
        'The dashboard warning source identities changed unexpectedly.'
    );

    foreach ($sources as $source) {
        assertEarlyWarningRead(
            ($source['integration_status'] ?? null) === 'PENDING',
            'A warning source was incorrectly reported as connected.'
        );
    }

    $invalidLimitRejected = false;
    try {
        $service->recentWarnings(0);
    } catch (RuntimeException) {
        $invalidLimitRejected = true;
    }
    assertEarlyWarningRead($invalidLimitRejected, 'The service accepted an unsafe recent-warning limit.');

    $warningRowsAfter = $client->get('early_warnings', ['select' => 'id', 'order' => 'id.asc']);
    $areaRowsAfter = $client->get('early_warning_areas', ['select' => 'id', 'order' => 'id.asc']);
    assertEarlyWarningRead($warningRowsAfter === $warningRowsBefore, 'The read test changed warning records.');
    assertEarlyWarningRead($areaRowsAfter === $areaRowsBefore, 'The read test changed warning-area records.');

    $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    assertEarlyWarningRead(!str_contains($encoded, $config->serverApiKey()), 'A Supabase credential appeared in the dashboard response.');

    echo "DRRM early-warning read service: OK\n";
    echo 'sources: ' . count($sources) . " records\n";
    echo "source_statuses: PENDING=4\n";
    echo 'active_warnings: ' . $expectedMetrics['active_warnings'] . PHP_EOL;
    echo 'high_risk_areas: ' . $expectedMetrics['high_risk_areas'] . PHP_EOL;
    echo 'weather_advisories: ' . $expectedMetrics['weather_advisories'] . PHP_EOL;
    echo "alerts_sent_today: 0 (delivery tracking not implemented)\n";
    echo 'recent_warnings: ' . count($summary['recent_warnings']) . PHP_EOL;
    echo 'current_warning: ' . ($summary['current_warning'] === null ? 'null' : 'present') . PHP_EOL;
    echo "database_writes: none\n";
    echo "credentials_exposed: no\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "DRRM early-warning read service: FAILED\n");
    fwrite(STDERR, 'Reason: ' . $exception->getMessage() . "\n");
    exit(1);
}
