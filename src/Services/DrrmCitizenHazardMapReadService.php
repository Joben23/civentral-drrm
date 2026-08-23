<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** Public-safe projection of the prepared Module 1 GIS datasets. */
final class DrrmCitizenHazardMapReadService
{
    public const CITY_NAME = 'Caloocan City';
    public const SUPPORTED_LAYERS = ['boundary', 'barangays', 'flood', 'landslide', 'fault', 'evacuation-centers'];

    private const FILES = [
        'boundary' => 'caloocan-city-boundary.geojson',
        'barangays' => 'caloocan-barangays-current-unaffected.geojson',
        'flood' => 'caloocan-mgb-flood-susceptibility.geojson',
        'landslide' => 'caloocan-mgb-landslide-susceptibility.geojson',
        'fault' => 'caloocan-nearby-phivolcs-active-faults.geojson',
        'evacuation-centers' => 'caloocan-evacuation-centers-ready.json',
    ];

    private const EXPECTED_COUNTS = ['boundary' => 1, 'barangays' => 187, 'flood' => 15, 'landslide' => 13, 'fault' => 254];
    private const NEAREST_FAULT_SOURCE_OBJECT_ID = 2438;

    private const FLOOD_CLASSES = [
        'LF' => ['Low', 'Low Susceptibility to Flooding'],
        'MF' => ['Moderate', 'Moderate Susceptibility to Flooding'],
        'HF' => ['High', 'High Susceptibility to Flooding'],
        'VHF' => ['Very High', 'Very High Susceptibility to Flooding'],
    ];

    private const LANDSLIDE_CLASSES = [
        'LL' => ['Low', 'Low Susceptibility to Landslide'],
        'ML' => ['Moderate', 'Moderate Susceptibility to Landslide'],
        'HL' => ['High', 'High Susceptibility to Landslide'],
        'VHL' => ['Very High', 'Very High Susceptibility to Landslide'],
    ];

    public function __construct(private readonly string $preparedDataDirectory)
    {
    }

    /** @return array<string, mixed> */
    public function layer(string $layer): array
    {
        if (!in_array($layer, self::SUPPORTED_LAYERS, true)) {
            throw new InvalidArgumentException('Unsupported citizen hazard-map layer.');
        }

        return match ($layer) {
            'boundary' => $this->boundary(),
            'barangays' => $this->barangays(),
            'flood' => $this->susceptibility('flood'),
            'landslide' => $this->susceptibility('landslide'),
            'fault' => $this->fault(),
            'evacuation-centers' => $this->evacuationCenters(),
        };
    }

    /** @return array<string, mixed> */
    private function boundary(): array
    {
        $document = $this->featureCollection('boundary');
        $feature = $document['features'][0];
        $properties = $this->properties($feature);
        $geometry = $this->geometry($feature, ['MultiPolygon']);

        if (($properties['adm3_name'] ?? null) !== self::CITY_NAME || count($geometry['coordinates']) !== 2) {
            throw new RuntimeException('The prepared Caloocan city boundary is invalid.');
        }

        return $this->response(
            'boundary',
            (string) ($properties['valid_on'] ?? '2025-02-13'),
            ['agency' => 'CIVENTRAL DRRM', 'name' => 'Existing Module 1 Caloocan City administrative boundary'],
            'Prepared Caloocan City reference boundary for citizen map display; not a survey-grade boundary.',
            $this->collection([[
                'type' => 'Feature',
                'properties' => [
                    'name' => self::CITY_NAME,
                    'city_code' => (string) ($properties['adm3_pcode'] ?? 'PH1307501'),
                    'component_count' => 2,
                    'components' => ['North Caloocan', 'South Caloocan'],
                ],
                'geometry' => $geometry,
            ]])
        );
    }

    /** @return array<string, mixed> */
    private function barangays(): array
    {
        $document = $this->featureCollection('barangays');
        $features = [];
        $names = [];

        foreach ($document['features'] as $feature) {
            $properties = $this->properties($feature);
            $name = (string) ($properties['current_barangay_name'] ?? '');
            if (($properties['adm3_name'] ?? null) !== self::CITY_NAME
                || !preg_match('/^Barangay ([1-9]|[1-9][0-9]|1[0-8][0-9])$/', $name, $matches)
            ) {
                throw new RuntimeException('A prepared barangay feature is outside Caloocan City.');
            }

            if ((int) $matches[1] === 176 || isset($names[$name])) {
                throw new RuntimeException('The prepared barangay boundary set contains an unsupported feature.');
            }
            $names[$name] = true;

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'name' => $name,
                    'psgc_code' => (string) ($properties['current_psgc_10_digit'] ?? ''),
                    'boundary_status' => 'Validated development boundary',
                ],
                'geometry' => $this->geometry($feature, ['Polygon', 'MultiPolygon']),
            ];
        }

        $pending = ['Barangay 176-A', 'Barangay 176-B', 'Barangay 176-C', 'Barangay 176-D', 'Barangay 176-E', 'Barangay 176-F'];
        foreach ($pending as $pendingName) {
            if (isset($names[$pendingName])) {
                throw new RuntimeException('Pending split-barangay geometry must not be published.');
            }
        }

        return $this->response(
            'barangays',
            '2025-02-13',
            ['agency' => 'CIVENTRAL DRRM', 'name' => 'Existing Module 1 validated development barangay boundaries'],
            '187 validated development boundaries are available. Barangays 176-A through 176-F remain pending validated GIS boundaries; no split polygons are fabricated.',
            $this->collection($features),
            $pending
        );
    }

    /** @return array<string, mixed> */
    private function susceptibility(string $layer): array
    {
        $isFlood = $layer === 'flood';
        $document = $this->featureCollection($layer);
        $classes = $isFlood ? self::FLOOD_CLASSES : self::LANDSLIDE_CLASSES;
        $codeKey = $isFlood ? 'mgb_flood_code' : 'mgb_landslide_code';
        $labelKey = $isFlood ? 'mgb_flood_label' : 'mgb_landslide_label';
        $features = [];

        foreach ($document['features'] as $feature) {
            $properties = $this->properties($feature);
            $code = (string) ($properties[$codeKey] ?? '');
            $classification = $classes[$code] ?? null;
            if ($classification === null
                || ($properties[$labelKey] ?? null) !== $classification[1]
                || ($properties['source_agency'] ?? null) !== 'DENR-MGB'
            ) {
                throw new RuntimeException('The prepared DENR-MGB classification is invalid.');
            }

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'hazard' => $isFlood ? 'Flood' : 'Rain-induced landslide',
                    'susceptibility' => $classification[0],
                    'source_classification' => $classification[1],
                ],
                'geometry' => $this->geometry($feature, ['Polygon', 'MultiPolygon']),
            ];
        }

        return $this->response(
            $layer,
            $isFlood ? '2026-08-19T13:24:53.491Z' : '2026-08-19T15:52:55.136Z',
            [
                'agency' => 'DENR-MGB',
                'name' => $isFlood ? 'Detailed Flood Susceptibility' : 'Detailed Rain-induced Landslide Susceptibility',
                'classification_scale' => ['Low', 'Moderate', 'High', 'Very High'],
            ],
            $isFlood
                ? 'Official DENR-MGB susceptibility terminology is preserved. Prepared polygons are clipped to Caloocan City for development map use.'
                : 'Official DENR-MGB susceptibility terminology is preserved. No debris-flow layer is included.',
            $this->collection($features)
        );
    }

    /** @return array<string, mixed> */
    private function fault(): array
    {
        $document = $this->featureCollection('fault');
        $nearest = null;

        foreach ($document['features'] as $feature) {
            $properties = $this->properties($feature);
            if (($properties['intersects_caloocan'] ?? true) === true) {
                throw new RuntimeException('The prepared fault context conflicts with Module 1.');
            }
            if (($properties['source_object_id'] ?? null) === self::NEAREST_FAULT_SOURCE_OBJECT_ID) {
                $nearest = $feature;
            }
        }

        if ($nearest === null) {
            throw new RuntimeException('The prepared nearest-fault context is unavailable.');
        }
        $properties = $this->properties($nearest);
        $distanceMeters = (float) ($properties['minimum_distance_to_caloocan_meters'] ?? 0);
        if (($properties['official_fault_name'] ?? null) !== 'West Valley Fault' || $distanceMeters <= 0) {
            throw new RuntimeException('The prepared nearest-fault context is invalid.');
        }

        return $this->response(
            'fault',
            (string) ($document['metadata']['retrieved_at'] ?? '2026-08-19T15:23:20+00:00'),
            ['agency' => 'DOST-PHIVOLCS', 'name' => 'Active Faults and Trenches'],
            'Compact contextual geometry is provided for citizen display; this is not a site-specific earthquake hazard assessment.',
            [
                'context' => [
                    'active_fault_intersects_caloocan' => false,
                    'nearest_known_active_fault' => 'West Valley Fault',
                    'minimum_distance_km' => round($distanceMeters / 1000, 2),
                    'advisory' => 'No mapped active fault intersects Caloocan City. The nearest known active fault context is the West Valley Fault. Proximity does not mean absence of earthquake risk.',
                ],
                'geometry' => $this->collection([[
                    'type' => 'Feature',
                    'properties' => [
                        'name' => 'West Valley Fault',
                        'fault_system' => (string) ($properties['official_fault_system'] ?? 'Valley Fault System'),
                        'feature_class' => (string) ($properties['feature_class'] ?? 'Active Fault'),
                        'trace_type' => (string) ($properties['trace_type'] ?? ''),
                        'intersects_caloocan' => false,
                    ],
                    'geometry' => $this->geometry($nearest, ['LineString', 'MultiLineString']),
                ]]),
            ]
        );
    }

    /** @return array<string, mixed> */
    private function evacuationCenters(): array
    {
        $document = $this->jsonFile(self::FILES['evacuation-centers']);
        $records = $document['records'] ?? null;
        if (!is_array($records) || ($document['record_count'] ?? null) !== 15 || count($records) !== 15) {
            throw new RuntimeException('The prepared evacuation-center subset is invalid.');
        }

        $features = [];
        foreach ($records as $record) {
            if (!is_array($record)
                || ($record['inside_caloocan'] ?? false) !== true
                || ($record['ready_for_staging'] ?? false) !== true
                || ($record['coordinate_status'] ?? null) !== 'HIGH_CONFIDENCE'
            ) {
                throw new RuntimeException('An evacuation center is not in the validated development subset.');
            }

            $latitude = $this->coordinate($record['latitude'] ?? null, -90, 90);
            $longitude = $this->coordinate($record['longitude'] ?? null, -180, 180);
            $barangay = (string) ($record['spatial_barangay_name'] ?? '');
            if (!preg_match('/^Barangay (?:[1-9]|[1-9][0-9]|1[0-8][0-9])$/', $barangay)) {
                throw new RuntimeException('An evacuation center is outside the supported Caloocan scope.');
            }

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'id' => (string) ($record['record_id'] ?? ''),
                    'name' => (string) ($record['normalized_name'] ?? ''),
                    'barangay' => $barangay,
                    'location' => $barangay . ', ' . self::CITY_NAME,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'verification_status' => 'Development-preview location pending LGU verification',
                    'source_context' => (string) ($record['source_agency'] ?? 'City Government of Caloocan / Caloocan PIO'),
                ],
                'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, $latitude]],
            ];
        }

        return $this->response(
            'evacuation-centers',
            (string) ($document['generated_at'] ?? '2026-08-19T14:42:56+00:00'),
            ['agency' => 'City Government of Caloocan / Caloocan PIO', 'name' => 'Existing Module 1 validated development evacuation-center subset'],
            '15 development-preview locations are published pending LGU verification. Capacity is not published.',
            $this->collection($features)
        );
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $data
     * @param list<string> $pendingBoundaries
     * @return array<string, mixed>
     */
    private function response(string $layer, string $dataAsOf, array $source, string $disclaimer, array $data, array $pendingBoundaries = []): array
    {
        $status = ['code' => 'DEVELOPMENT_PREVIEW', 'label' => 'Development preview', 'disclaimer' => $disclaimer];
        if ($pendingBoundaries !== []) {
            $status['pending_boundaries'] = $pendingBoundaries;
        }

        return [
            'city' => self::CITY_NAME,
            'layer' => $layer,
            'data_as_of' => $dataAsOf,
            'source' => $source,
            'development_status' => $status,
            'data' => $data,
        ];
    }

    /** @return array<string, mixed> */
    private function featureCollection(string $layer): array
    {
        $document = $this->jsonFile(self::FILES[$layer]);
        $features = $document['features'] ?? null;
        if (($document['type'] ?? null) !== 'FeatureCollection'
            || !is_array($features)
            || count($features) !== self::EXPECTED_COUNTS[$layer]
        ) {
            throw new RuntimeException('A prepared GIS dataset has an unexpected structure or count.');
        }
        return $document;
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $filename): array
    {
        $directory = realpath($this->preparedDataDirectory);
        $path = realpath($this->preparedDataDirectory . DIRECTORY_SEPARATOR . $filename);
        if ($directory === false || $path === false || dirname($path) !== $directory || !is_file($path)) {
            throw new RuntimeException('A prepared GIS dataset is unavailable.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('A prepared GIS dataset could not be read.');
        }

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('A prepared GIS dataset contains invalid JSON.', 0, $exception);
        }

        if (!is_array($document)) {
            throw new RuntimeException('A prepared GIS dataset has an invalid root value.');
        }
        return $document;
    }

    /** @param array<string, mixed> $feature @return array<string, mixed> */
    private function properties(array $feature): array
    {
        $properties = $feature['properties'] ?? null;
        if (($feature['type'] ?? null) !== 'Feature' || !is_array($properties)) {
            throw new RuntimeException('A prepared GIS feature has invalid properties.');
        }
        return $properties;
    }

    /** @param array<string, mixed> $feature @param list<string> $allowedTypes @return array<string, mixed> */
    private function geometry(array $feature, array $allowedTypes): array
    {
        $geometry = $feature['geometry'] ?? null;
        if (!is_array($geometry)
            || !in_array($geometry['type'] ?? null, $allowedTypes, true)
            || !is_array($geometry['coordinates'] ?? null)
            || $geometry['coordinates'] === []
        ) {
            throw new RuntimeException('A prepared GIS feature has invalid geometry.');
        }
        return ['type' => $geometry['type'], 'coordinates' => $geometry['coordinates']];
    }

    /** @param list<array<string, mixed>> $features @return array<string, mixed> */
    private function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            throw new RuntimeException('A prepared location has an invalid coordinate.');
        }
        $coordinate = (float) $value;
        if ($coordinate < $minimum || $coordinate > $maximum) {
            throw new RuntimeException('A prepared location coordinate is outside the valid range.');
        }
        return $coordinate;
    }
}
