<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Controlled read-only route request coordinator.
 *
 * Destination coordinates are resolved from the fixed 15-center projection;
 * they are never accepted from the browser. The origin is independently
 * validated against the actual Caloocan MultiPolygon before OSRM is called.
 */
final class DrrmEvacuationRoutePreviewService
{
    public const REQUESTED_ALTERNATIVES = 3;

    public function __construct(
        private readonly DrrmDraftEvacuationCenterPreviewService $centerService,
        private readonly OsrmRoutingClient $routingClient,
        private readonly string $cityBoundaryPath,
        bool $routePreviewAllowed
    ) {
        if (!$routePreviewAllowed) {
            throw new RuntimeException('The route preview is unavailable.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function route(float $latitude, float $longitude, string $evacuationCenterId): array
    {
        $center = $this->validatedCenter($latitude, $longitude, $evacuationCenterId);
        $coordinates = $center['geometry']['coordinates'];
        $routes = $this->routingClient->drivingAlternatives(
            ['latitude' => $latitude, 'longitude' => $longitude],
            ['latitude' => (float) $coordinates[1], 'longitude' => (float) $coordinates[0]],
            self::REQUESTED_ALTERNATIVES
        );

        return [
            'status' => 'Development route alternatives',
            'routing_profile' => 'driving',
            'requested_alternatives' => self::REQUESTED_ALTERNATIVES,
            'returned_alternatives' => count($routes),
            'origin' => ['latitude' => $latitude, 'longitude' => $longitude],
            'destination' => [
                'evacuation_center_id' => $center['properties']['evacuation_center_id'],
                'name' => $center['properties']['name'],
                'barangay_name' => $center['properties']['barangay_name'],
                'location_verification_status' => $center['properties']['location_verification_status'],
                'geometry' => $center['geometry'],
            ],
            'routes' => $routes,
        ];
    }

    /**
     * Return the single road route needed by the staging admin planning UI.
     *
     * Destination coordinates are resolved from the controlled center set and
     * deliberately omitted from this response. No route is persisted.
     *
     * @return array<string, mixed>
     */
    public function adminPlanningRoute(
        float $latitude,
        float $longitude,
        string $evacuationCenterReferenceId
    ): array {
        $center = $this->validatedCenter($latitude, $longitude, $evacuationCenterReferenceId);
        $route = $this->singleRoute($latitude, $longitude, $center);

        $result = [
            'status' => 'ADMIN_PLANNING_PREVIEW',
            'geometry' => $route['geometry'],
            'distance_meters' => $route['distance_meters'],
        ];
        if (isset($route['duration_seconds'])) {
            $result['duration_seconds'] = $route['duration_seconds'];
        }
        $result['destination_name'] = $center['properties']['name'];
        return $result;
    }

    /**
     * Return the minimized public staging planning preview projection.
     *
     * @return array<string, mixed>
     */
    public function citizenPlanningRoute(
        float $latitude,
        float $longitude,
        string $centerReferenceId
    ): array {
        $center = $this->validatedCenter($latitude, $longitude, $centerReferenceId);
        $route = $this->singleRoute($latitude, $longitude, $center);

        $result = [
            'status' => 'DEVELOPMENT_PLANNING_PREVIEW',
            'route' => $route['geometry'],
            'distance_meters' => $route['distance_meters'],
            'destination' => [
                'reference_id' => $center['properties']['evacuation_center_id'],
                'name' => $center['properties']['name'],
                'barangay_name' => $center['properties']['barangay_name'],
            ],
            'disclaimer' => 'Planning preview only. The selected evacuation center is an unverified reference and this road route is not an approved evacuation route. Actual road and hazard conditions may differ during an emergency.',
        ];
        if (isset($route['duration_seconds'])) {
            $result['duration_seconds'] = $route['duration_seconds'];
        }

        return $result;
    }

    /** @param array<string, mixed> $center @return array<string, mixed> */
    private function singleRoute(float $latitude, float $longitude, array $center): array
    {
        $coordinates = $center['geometry']['coordinates'];
        $routes = $this->routingClient->drivingAlternatives(
            ['latitude' => $latitude, 'longitude' => $longitude],
            ['latitude' => (float) $coordinates[1], 'longitude' => (float) $coordinates[0]],
            1
        );
        $route = $routes[0] ?? null;
        if (!is_array($route)) {
            throw new RuntimeException('The routing service did not return a road route.');
        }

        return $route;
    }

    /** @return array<string, mixed> */
    private function validatedCenter(float $latitude, float $longitude, string $evacuationCenterId): array
    {
        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('The origin coordinate is invalid.');
        }

        $cityGeometry = $this->loadCityGeometry();
        if (!$this->pointInGeometry($longitude, $latitude, $cityGeometry)) {
            throw new InvalidArgumentException('The starting location must be inside Caloocan City.');
        }

        return $this->findCenter($evacuationCenterId);
    }

    /** @return array<string, mixed> */
    private function findCenter(string $evacuationCenterId): array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $evacuationCenterId) !== 1) {
            throw new InvalidArgumentException('The evacuation center is invalid.');
        }

        $centers = $this->centerService->featureCollection();
        foreach ($centers['features'] as $feature) {
            if (($feature['properties']['evacuation_center_id'] ?? null) === $evacuationCenterId) {
                return $feature;
            }
        }

        throw new InvalidArgumentException('The evacuation center is not available for development routing.');
    }

    /** @return array<string, mixed> */
    private function loadCityGeometry(): array
    {
        $body = is_file($this->cityBoundaryPath) ? file_get_contents($this->cityBoundaryPath) : false;
        if ($body === false) {
            throw new RuntimeException('The Caloocan boundary could not be loaded.');
        }

        try {
            $featureCollection = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The Caloocan boundary is invalid.');
        }

        $geometry = $featureCollection['features'][0]['geometry'] ?? null;
        if (!is_array($geometry) || !in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)
            || !is_array($geometry['coordinates'] ?? null) || $geometry['coordinates'] === []) {
            throw new RuntimeException('The Caloocan boundary geometry is invalid.');
        }

        return $geometry;
    }

    /** @param array<string, mixed> $geometry */
    private function pointInGeometry(float $longitude, float $latitude, array $geometry): bool
    {
        $polygons = $geometry['type'] === 'Polygon'
            ? [$geometry['coordinates']]
            : $geometry['coordinates'];

        foreach ($polygons as $polygon) {
            if (!is_array($polygon) || $polygon === []) {
                continue;
            }
            if (!$this->pointInRing($longitude, $latitude, $polygon[0])) {
                continue;
            }

            $insideHole = false;
            foreach (array_slice($polygon, 1) as $hole) {
                if ($this->pointInRing($longitude, $latitude, $hole)) {
                    $insideHole = true;
                    break;
                }
            }
            if (!$insideHole) {
                return true;
            }
        }

        return false;
    }

    /** @param mixed $ring */
    private function pointInRing(float $x, float $y, mixed $ring): bool
    {
        if (!is_array($ring) || count($ring) < 4) {
            return false;
        }

        $inside = false;
        $previous = $ring[count($ring) - 1];
        foreach ($ring as $current) {
            if (!is_array($current) || !is_array($previous)
                || !is_numeric($current[0] ?? null) || !is_numeric($current[1] ?? null)
                || !is_numeric($previous[0] ?? null) || !is_numeric($previous[1] ?? null)) {
                throw new RuntimeException('The Caloocan boundary contains an invalid coordinate.');
            }

            $x1 = (float) $previous[0];
            $y1 = (float) $previous[1];
            $x2 = (float) $current[0];
            $y2 = (float) $current[1];

            if ($this->pointOnSegment($x, $y, $x1, $y1, $x2, $y2)) {
                return true;
            }

            if ((($y1 > $y) !== ($y2 > $y))
                && $x < (($x2 - $x1) * ($y - $y1) / ($y2 - $y1)) + $x1) {
                $inside = !$inside;
            }

            $previous = $current;
        }

        return $inside;
    }

    private function pointOnSegment(float $x, float $y, float $x1, float $y1, float $x2, float $y2): bool
    {
        $cross = ($x - $x1) * ($y2 - $y1) - ($y - $y1) * ($x2 - $x1);
        if (abs($cross) > 1.0e-10) {
            return false;
        }

        return $x >= min($x1, $x2) - 1.0e-10 && $x <= max($x1, $x2) + 1.0e-10
            && $y >= min($y1, $y2) - 1.0e-10 && $y <= max($y1, $y2) + 1.0e-10;
    }
}
