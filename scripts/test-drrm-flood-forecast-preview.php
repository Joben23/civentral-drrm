<?php

declare(strict_types=1);

use App\Config\AppEnvironment;
use App\Config\PagasaConfig;
use App\Services\DrrmFloodForecastPreviewService;
use App\Services\PagasaNotConfiguredException;
use App\Services\PagasaTenDayClient;

require_once __DIR__ . '/../config/app_environment.php';
require_once __DIR__ . '/../config/pagasa.php';
require_once __DIR__ . '/../src/Services/PagasaTenDayClient.php';
require_once __DIR__ . '/../src/Services/DrrmFloodForecastPreviewService.php';

function assertFloodForecastPreview(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $originalEnvironment = getenv('APP_ENV');
    putenv('APP_ENV=development');
    assertFloodForecastPreview(AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost',
    ]), 'Development loopback requests should be allowed.');
    putenv('APP_ENV=production');
    assertFloodForecastPreview(!AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost',
    ]), 'Non-development requests should be denied.');
    $originalEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $originalEnvironment);

    $config = PagasaConfig::fromEnvironment(__DIR__ . '/../.env');
    assertFloodForecastPreview(parse_url($config->baseUrl(), PHP_URL_HOST) === PagasaConfig::OFFICIAL_HOST, 'The official PAGASA host changed.');

    $client = new PagasaTenDayClient($config);
    $issuance = $client->issuance();
    foreach (['latest_date', 'latest_time', 'start_date', 'end_date'] as $field) {
        assertFloodForecastPreview(isset($issuance[$field]) && is_string($issuance[$field]) && $issuance[$field] !== '', 'The issuance response is incomplete.');
    }

    $preview = (new DrrmFloodForecastPreviewService($client, true))->preview();
    if ($config->hasApiToken()) {
        assertFloodForecastPreview(($preview['api_status'] ?? null) === 'AVAILABLE', 'Configured PAGASA access did not return an available forecast.');
        assertFloodForecastPreview(count($preview['forecast'] ?? []) > 0, 'Configured PAGASA access returned no forecast records.');
    } else {
        $fullForecastBlocked = false;
        try {
            $client->fullForecastForCaloocan();
        } catch (PagasaNotConfiguredException) {
            $fullForecastBlocked = true;
        }
        assertFloodForecastPreview($fullForecastBlocked, 'Detailed forecast access was not blocked without a token.');
        assertFloodForecastPreview(($preview['api_status'] ?? null) === 'NOT_CONFIGURED', 'The missing-token status is incorrect.');
        assertFloodForecastPreview(($preview['forecast'] ?? null) === [], 'The unavailable forecast must remain empty.');
    }
    assertFloodForecastPreview(($preview['forecast_location']['requested_psgc_10_digit'] ?? null) === '1380100000', 'The Caloocan PSGC lookup changed.');
    assertFloodForecastPreview(($preview['weather_source']['agency'] ?? null) === 'DOST-PAGASA', 'The source agency changed.');

    $encoded = json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    assertFloodForecastPreview(!str_contains(strtolower($encoded), 'tensorflow prediction available'), 'A fake TensorFlow result appeared.');

    echo "Official PAGASA issuance endpoint: OK\n";
    echo 'latest issuance: ' . $issuance['latest_date'] . ' ' . $issuance['latest_time'] . "\n";
    echo 'forecast period: ' . $issuance['start_date'] . ' to ' . $issuance['end_date'] . "\n";
    echo 'detailed Caloocan forecast: ' . ($config->hasApiToken() ? 'available' : 'PAGASA API token required') . "\n";
    echo 'preview status: ' . $preview['api_status'] . "\n";
    echo 'forecast records returned: ' . count($preview['forecast']) . "\n";
    echo "local development guard: OK\n";
    echo "credentials exposed: no\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Flood forecast preview test: FAILED - ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
