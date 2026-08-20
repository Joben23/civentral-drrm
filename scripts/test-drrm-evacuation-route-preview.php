<?php

declare(strict_types=1);

use App\Config\OsrmConfig;
use App\Config\AppEnvironment;
use App\Config\SupabaseConfig;
use App\Services\DrrmDraftEvacuationCenterPreviewService;
use App\Services\DrrmEvacuationRoutePreviewService;
use App\Services\OsrmRoutingClient;
use App\Services\SupabaseRestClient;

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../config/osrm.php';
require_once __DIR__ . '/../config/app_environment.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../src/Services/DrrmDraftBarangayPreviewService.php';
require_once __DIR__ . '/../src/Services/DrrmDraftEvacuationCenterPreviewService.php';
require_once __DIR__ . '/../src/Services/OsrmRoutingClient.php';
require_once __DIR__ . '/../src/Services/DrrmEvacuationRoutePreviewService.php';

$exitCode = 0;

try {
    $originalEnvironment = getenv('APP_ENV');
    putenv('APP_ENV=development');
    $developmentAllowed = AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost',
    ]);
    putenv('APP_ENV=production');
    $productionDenied = !AppEnvironment::allowsLocalDevelopmentRequest(null, [
        'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost',
    ]);
    $originalEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $originalEnvironment);
    if (!$developmentAllowed || !$productionDenied) {
        throw new RuntimeException('The local development environment guard failed.');
    }

    $supabaseConfig = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
    $supabaseClient = new SupabaseRestClient($supabaseConfig);
    $routesBefore = $supabaseClient->get('evacuation_routes', [
        'select' => 'evacuation_route_id',
        'order' => 'created_at.asc',
    ]);
    $centers = new DrrmDraftEvacuationCenterPreviewService($supabaseClient, true);
    $service = new DrrmEvacuationRoutePreviewService(
        $centers,
        new OsrmRoutingClient(OsrmConfig::fromEnvironment(__DIR__ . '/../.env')),
        __DIR__ . '/../data/import/caloocan-city-boundary.geojson',
        true
    );

    // Two independently staged coordinates inside North Caloocan provide a
    // deterministic development connectivity check without inventing points.
    $result = $service->route(
        14.7663938,
        121.0607398,
        '72983eab-ab39-4b3f-8fea-4fc00dd01f64'
    );

    $routeCount = count($result['routes'] ?? []);
    $firstRoute = $result['routes'][0] ?? null;
    $coordinateCount = is_array($firstRoute)
        ? count($firstRoute['geometry']['coordinates'] ?? [])
        : 0;
    if ($routeCount < 1 || $routeCount > 3 || $coordinateCount < 3) {
        throw new RuntimeException('OSRM did not return the expected real road-route geometry.');
    }

    $outsideRejected = false;
    try {
        $service->route(14.5995, 120.9842, '72983eab-ab39-4b3f-8fea-4fc00dd01f64');
    } catch (InvalidArgumentException) {
        $outsideRejected = true;
    }

    $unknownCenterRejected = false;
    try {
        $service->route(14.7663938, 121.0607398, '00000000-0000-4000-8000-000000000000');
    } catch (InvalidArgumentException) {
        $unknownCenterRejected = true;
    }

    $routesAfter = $supabaseClient->get('evacuation_routes', [
        'select' => 'evacuation_route_id',
        'order' => 'created_at.asc',
    ]);
    if (!$outsideRejected || !$unknownCenterRejected || $routesBefore !== $routesAfter) {
        throw new RuntimeException('A route-preview safety check failed.');
    }
    $encodedResult = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (str_contains($encodedResult, $supabaseConfig->serverApiKey())) {
        throw new RuntimeException('A credential appeared in the route-preview response.');
    }

    echo "OSRM connection: OK\n";
    echo 'route alternatives returned: ' . $routeCount . "\n";
    echo 'first route distance meters: ' . round((float) $firstRoute['distance_meters'], 1) . "\n";
    echo 'first route duration seconds: ' . round((float) $firstRoute['duration_seconds'], 1) . "\n";
    echo 'first route coordinate count: ' . $coordinateCount . "\n";
    echo "outside Caloocan origin rejected: yes\n";
    echo "unknown center rejected: yes\n";
    echo "local development guard: OK\n";
    echo "credentials exposed: no\n";
    echo 'evacuation_routes records unchanged: ' . count($routesAfter) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Route preview test: FAILED - ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
}

exit($exitCode);
