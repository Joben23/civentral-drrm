<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\OsrmConfig;
use JsonException;
use RuntimeException;

final class OsrmNoRouteException extends RuntimeException
{
}

final class OsrmRoutingException extends RuntimeException
{
}

/**
 * Minimal server-side client for a trusted OSRM-compatible route service.
 */
final class OsrmRoutingClient
{
    public function __construct(
        private readonly OsrmConfig $config,
        private readonly int $connectionTimeoutSeconds = 5,
        private readonly int $requestTimeoutSeconds = 25
    ) {
        if ($connectionTimeoutSeconds < 1 || $requestTimeoutSeconds < 1) {
            throw new OsrmRoutingException('Routing request timeouts must be positive.');
        }
    }

    /**
     * @param array{latitude: float, longitude: float} $origin
     * @param array{latitude: float, longitude: float} $destination
     * @return list<array{route_index: int, distance_meters: float, duration_seconds: float, geometry: array{type: string, coordinates: list<array{0: float, 1: float}>}}>
     */
    public function drivingAlternatives(array $origin, array $destination, int $alternatives = 3): array
    {
        if (!extension_loaded('curl')) {
            throw new OsrmRoutingException('The PHP cURL extension is required for routing requests.');
        }
        if ($alternatives < 1 || $alternatives > 3) {
            throw new OsrmRoutingException('The requested route alternative count is invalid.');
        }

        $coordinates = $this->coordinatePair($origin) . ';' . $this->coordinatePair($destination);
        $query = http_build_query([
            'alternatives' => $alternatives,
            'steps' => 'true',
            'geometries' => 'geojson',
            'overview' => 'full',
        ], '', '&', PHP_QUERY_RFC3986);
        $url = $this->config->baseUrl() . '/route/v1/driving/' . $coordinates . '?' . $query;

        $handle = curl_init();
        if ($handle === false) {
            throw new OsrmRoutingException('The routing request could not be initialized.');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectionTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'CIVENTRAL-DRRM/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HTTPGET => true,
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        if ($body === false) {
            $code = curl_errno($handle);
            curl_close($handle);
            throw new OsrmRoutingException('The routing request failed at the network layer (cURL code ' . $code . ').');
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OsrmRoutingException('The routing service returned invalid JSON.');
        }

        if (!is_array($payload)) {
            throw new OsrmRoutingException('The routing service returned an unexpected response.');
        }
        if (($payload['code'] ?? null) === 'NoRoute') {
            throw new OsrmNoRouteException('No route was found.');
        }
        if ($status < 200 || $status >= 300 || ($payload['code'] ?? null) !== 'Ok') {
            throw new OsrmRoutingException('The routing service request was unsuccessful.');
        }

        $sourceRoutes = $payload['routes'] ?? null;
        if (!is_array($sourceRoutes) || $sourceRoutes === [] || count($sourceRoutes) > $alternatives) {
            throw new OsrmRoutingException('The routing service returned an unexpected route count.');
        }

        $routes = [];
        foreach ($sourceRoutes as $index => $route) {
            if (!is_array($route) || !is_numeric($route['distance'] ?? null) || !is_numeric($route['duration'] ?? null)) {
                throw new OsrmRoutingException('The routing service returned invalid route metrics.');
            }

            $distance = (float) $route['distance'];
            $duration = (float) $route['duration'];
            $geometry = $this->normalizeLineString($route['geometry'] ?? null);
            if (!is_finite($distance) || !is_finite($duration) || $distance < 0 || $duration < 0) {
                throw new OsrmRoutingException('The routing service returned invalid route metrics.');
            }

            $routes[] = [
                'route_index' => (int) $index,
                'distance_meters' => $distance,
                'duration_seconds' => $duration,
                'geometry' => $geometry,
            ];
        }

        return $routes;
    }

    /** @param array{latitude: float, longitude: float} $coordinate */
    private function coordinatePair(array $coordinate): string
    {
        $latitude = (float) ($coordinate['latitude'] ?? NAN);
        $longitude = (float) ($coordinate['longitude'] ?? NAN);
        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new OsrmRoutingException('A routing coordinate is invalid.');
        }

        return sprintf('%.7F,%.7F', $longitude, $latitude);
    }

    /** @return array{type: string, coordinates: list<array{0: float, 1: float}>} */
    private function normalizeLineString(mixed $geometry): array
    {
        if (!is_array($geometry) || ($geometry['type'] ?? null) !== 'LineString'
            || !is_array($geometry['coordinates'] ?? null) || count($geometry['coordinates']) < 2) {
            throw new OsrmRoutingException('The routing service did not return a valid road geometry.');
        }

        $coordinates = [];
        foreach ($geometry['coordinates'] as $position) {
            if (!is_array($position) || count($position) < 2
                || !is_numeric($position[0]) || !is_numeric($position[1])) {
                throw new OsrmRoutingException('The routing service returned an invalid road coordinate.');
            }
            $longitude = (float) $position[0];
            $latitude = (float) $position[1];
            if (!is_finite($longitude) || !is_finite($latitude)
                || $longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90) {
                throw new OsrmRoutingException('The routing service returned an invalid road coordinate.');
            }
            $coordinates[] = [$longitude, $latitude];
        }

        return ['type' => 'LineString', 'coordinates' => $coordinates];
    }
}
