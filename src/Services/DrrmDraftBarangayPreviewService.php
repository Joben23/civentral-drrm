<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;

/**
 * Read-only projection for one controlled, incomplete barangay-boundary draft.
 *
 * The dataset identifier and record states are fixed server-side. No browser
 * input can select another dataset or expose arbitrary inactive records.
 */
final class DrrmDraftBarangayPreviewService
{
    public const DATASET_VERSION_ID = 'b386cd54-2288-423f-9b92-2092333333c1';
    public const EXPECTED_FEATURE_COUNT = 187;

    public function __construct(
        private readonly SupabaseRestClient $client,
        bool $localDevelopmentPreviewAllowed
    ) {
        if (!$localDevelopmentPreviewAllowed) {
            throw new RuntimeException('The draft barangay preview is unavailable.');
        }
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    public function featureCollection(): array
    {
        $versions = $this->client->get('dataset_versions', [
            'select' => 'dataset_version_id',
            'dataset_version_id' => 'eq.' . self::DATASET_VERSION_ID,
            'dataset_category' => 'eq.BARANGAY_BOUNDARY',
            'review_status' => 'eq.DRAFT',
            'limit' => 2,
        ]);

        if (count($versions) !== 1) {
            throw new RuntimeException('The controlled draft dataset is unavailable.');
        }

        $records = $this->client->get('barangays', [
            'select' => 'barangay_id,barangay_code,name,district_code,boundary_geometry',
            'boundary_dataset_version_id' => 'eq.' . self::DATASET_VERSION_ID,
            'record_status' => 'eq.INACTIVE',
            'order' => 'barangay_code.asc',
            'limit' => self::EXPECTED_FEATURE_COUNT + 1,
        ]);

        if (count($records) !== self::EXPECTED_FEATURE_COUNT) {
            throw new RuntimeException('The controlled draft dataset does not contain the expected feature count.');
        }

        $expectedNames = [];
        for ($number = 1; $number <= 188; $number++) {
            if ($number !== 176) {
                $expectedNames['Barangay ' . $number] = true;
            }
        }

        $seenIds = [];
        $seenCodes = [];
        $seenNames = [];
        $features = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new RuntimeException('The draft dataset returned an unexpected record structure.');
            }

            $barangayId = (string) ($record['barangay_id'] ?? '');
            $barangayCode = (string) ($record['barangay_code'] ?? '');
            $name = (string) ($record['name'] ?? '');
            $districtCode = $record['district_code'] ?? null;

            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $barangayId) !== 1) {
                throw new RuntimeException('The draft dataset contains an invalid barangay identifier.');
            }

            if (preg_match('/^\d{10}$/', $barangayCode) !== 1 || !isset($expectedNames[$name])) {
                throw new RuntimeException('The draft dataset contains an unexpected barangay code or name.');
            }

            if ($districtCode !== null && !is_string($districtCode)) {
                throw new RuntimeException('The draft dataset contains an invalid district code.');
            }

            if (isset($seenIds[$barangayId]) || isset($seenCodes[$barangayCode]) || isset($seenNames[$name])) {
                throw new RuntimeException('The draft dataset contains duplicate barangay records.');
            }

            $seenIds[$barangayId] = true;
            $seenCodes[$barangayCode] = true;
            $seenNames[$name] = true;

            $properties = [
                'barangay_id' => $barangayId,
                'barangay_code' => $barangayCode,
                'name' => $name,
                'preview_status' => 'DRAFT_INCOMPLETE',
            ];

            if ($districtCode !== null && trim($districtCode) !== '') {
                $properties['district_code'] = $districtCode;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => $this->normalizeGeometry($record['boundary_geometry'] ?? null, $name),
                'properties' => $properties,
            ];
        }

        if (array_diff_key($expectedNames, $seenNames) !== []) {
            throw new RuntimeException('The draft dataset is missing an expected unaffected barangay.');
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    /** @return array{type: string, coordinates: array<mixed>} */
    private function normalizeGeometry(mixed $geometry, string $label): array
    {
        if (is_string($geometry)) {
            try {
                $geometry = json_decode($geometry, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException($label . ' geometry was not valid GeoJSON.');
            }
        }

        if (!is_array($geometry)) {
            throw new RuntimeException($label . ' geometry is missing.');
        }

        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if (!in_array($type, ['Polygon', 'MultiPolygon'], true) || !is_array($coordinates)) {
            throw new RuntimeException($label . ' geometry is not a Polygon or MultiPolygon.');
        }

        $polygons = $type === 'Polygon' ? [$coordinates] : $coordinates;

        if (!array_is_list($polygons) || $polygons === []) {
            throw new RuntimeException($label . ' geometry is empty.');
        }

        foreach ($polygons as $polygon) {
            $this->validatePolygon($polygon, $label);
        }

        return [
            'type' => $type,
            'coordinates' => $coordinates,
        ];
    }

    private function validatePolygon(mixed $polygon, string $label): void
    {
        if (!is_array($polygon) || !array_is_list($polygon) || $polygon === []) {
            throw new RuntimeException($label . ' geometry contains an empty polygon.');
        }

        foreach ($polygon as $ring) {
            if (!is_array($ring) || !array_is_list($ring) || count($ring) < 4) {
                throw new RuntimeException($label . ' geometry contains an invalid ring.');
            }

            foreach ($ring as $position) {
                if (!is_array($position) || !array_is_list($position) || count($position) !== 2) {
                    throw new RuntimeException($label . ' geometry contains a non-2D position.');
                }

                $longitude = $position[0] ?? null;
                $latitude = $position[1] ?? null;

                if (!is_numeric($longitude) || !is_numeric($latitude)) {
                    throw new RuntimeException($label . ' geometry contains a non-numeric position.');
                }

                $longitude = (float) $longitude;
                $latitude = (float) $latitude;

                if (!is_finite($longitude) || !is_finite($latitude)
                    || $longitude < -180 || $longitude > 180
                    || $latitude < -90 || $latitude > 90) {
                    throw new RuntimeException($label . ' geometry contains an out-of-range position.');
                }
            }

            $first = $ring[0];
            $last = $ring[count($ring) - 1];
            if ((float) $first[0] !== (float) $last[0] || (float) $first[1] !== (float) $last[1]) {
                throw new RuntimeException($label . ' geometry contains an open ring.');
            }
        }
    }
}
