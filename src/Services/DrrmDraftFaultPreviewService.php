<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;

/**
 * Read-only projection for the controlled PHIVOLCS West Valley Fault draft.
 *
 * The fixed dataset UUID and workflow filters prevent this service from
 * becoming a general reader for arbitrary draft or inactive fault records.
 */
final class DrrmDraftFaultPreviewService
{
    public const DATASET_VERSION_ID = 'be44be50-e837-4936-93dc-e0d4b12272f8';
    public const EXPECTED_FEATURE_COUNT = 156;
    public const SOURCE_AGENCY = 'DOST-PHIVOLCS';
    public const SOURCE_SERVICE_URL = 'https://gisweb.phivolcs.dost.gov.ph/arcgis/rest/services/PHIVOLCS/ActiveFault/MapServer';
    public const MINIMUM_CITY_DISTANCE_KM = 3.76;

    public function __construct(
        private readonly SupabaseRestClient $client,
        bool $localDevelopmentPreviewAllowed
    ) {
        if (!$localDevelopmentPreviewAllowed) {
            throw new RuntimeException('The draft fault preview is unavailable.');
        }
    }

    /**
     * @return array{
     *   summary: array{crosses_caloocan: bool, nearest_fault_name: string, minimum_city_distance_km: float, source_agency: string, display_mode: string, advisory: string},
     *   faults: array{type: string, features: list<array<string, mixed>>}
     * }
     */
    public function preview(): array
    {
        $versions = $this->client->get('dataset_versions', [
            'select' => 'dataset_version_id,dataset_source_id',
            'dataset_version_id' => 'eq.' . self::DATASET_VERSION_ID,
            'dataset_category' => 'eq.FAULT_FEATURE',
            'hazard_type_id' => 'eq.3',
            'review_status' => 'eq.DRAFT',
            'limit' => 2,
        ]);
        if (count($versions) !== 1) {
            throw new RuntimeException('The controlled fault draft is unavailable.');
        }

        $sourceId = (string) ($versions[0]['dataset_source_id'] ?? '');
        if (!$this->isUuid($sourceId)) {
            throw new RuntimeException('The controlled fault draft has invalid provenance.');
        }
        $sources = $this->client->get('dataset_sources', [
            'select' => 'dataset_source_id',
            'dataset_source_id' => 'eq.' . $sourceId,
            'organization_name' => 'eq.Department of Science and Technology - Philippine Institute of Volcanology and Seismology (DOST-PHIVOLCS)',
            'organization_url' => 'eq.https://www.phivolcs.dost.gov.ph/',
            'record_status' => 'eq.ACTIVE',
            'limit' => 2,
        ]);
        if (count($sources) !== 1) {
            throw new RuntimeException('The controlled PHIVOLCS source is unavailable.');
        }

        $records = $this->client->get('fault_features', [
            'select' => 'feature_name,feature_class,geometry,notes',
            'dataset_version_id' => 'eq.' . self::DATASET_VERSION_ID,
            'hazard_type_id' => 'eq.3',
            'feature_name' => 'eq.West Valley Fault',
            'record_status' => 'eq.INACTIVE',
            'order' => 'created_at.asc',
            'limit' => self::EXPECTED_FEATURE_COUNT + 1,
        ]);
        if (count($records) !== self::EXPECTED_FEATURE_COUNT) {
            throw new RuntimeException('The controlled fault draft does not contain the expected feature count.');
        }

        $features = [];
        $seenObjectIds = [];
        $minimumDistanceMeters = INF;
        foreach ($records as $record) {
            if (!is_array($record) || ($record['feature_name'] ?? null) !== 'West Valley Fault'
                || ($record['feature_class'] ?? null) !== 'Active Fault') {
                throw new RuntimeException('The controlled fault draft contains inconsistent classification data.');
            }
            $notes = $this->decodeNotes($record['notes'] ?? null);
            $objectId = filter_var($notes['source_object_id'] ?? null, FILTER_VALIDATE_INT);
            $distance = $notes['minimum_distance_to_caloocan_meters'] ?? null;
            if ($objectId === false || $objectId < 1 || isset($seenObjectIds[$objectId])
                || !is_numeric($distance) || (float) $distance < 0 || (float) $distance > 10000
                || ($notes['source_agency'] ?? null) !== self::SOURCE_AGENCY
                || ($notes['source_service_reference'] ?? null) !== self::SOURCE_SERVICE_URL
                || ($notes['official_fault_system'] ?? null) !== 'Valley Fault System'
                || ($notes['intersects_caloocan'] ?? null) !== false) {
                throw new RuntimeException('The controlled fault draft contains inconsistent source metadata.');
            }

            $seenObjectIds[$objectId] = true;
            $minimumDistanceMeters = min($minimumDistanceMeters, (float) $distance);
            $features[] = [
                'type' => 'Feature',
                'geometry' => $this->normalizeGeometry($record['geometry'] ?? null),
                'properties' => [
                    'fault_name' => 'West Valley Fault',
                    'feature_class' => 'Active Fault',
                    'source_agency' => self::SOURCE_AGENCY,
                    'crosses_caloocan' => false,
                    'location_context' => 'Nearby mapped active fault outside Caloocan City',
                ],
            ];
        }

        if (count($seenObjectIds) !== self::EXPECTED_FEATURE_COUNT
            || abs($minimumDistanceMeters - 3758.38) > 0.01) {
            throw new RuntimeException('The controlled fault draft no longer matches the reviewed proximity result.');
        }

        return [
            'summary' => [
                'crosses_caloocan' => false,
                'nearest_fault_name' => 'West Valley Fault',
                'minimum_city_distance_km' => self::MINIMUM_CITY_DISTANCE_KM,
                'source_agency' => self::SOURCE_AGENCY,
                'display_mode' => 'INFORMATION_ONLY',
                'advisory' => 'No mapped active fault intersects Caloocan City based on the current PHIVOLCS dataset.',
            ],
            'faults' => [
                'type' => 'FeatureCollection',
                'features' => $features,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function decodeNotes(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('The fault draft is missing source metadata.');
        }
        try {
            $notes = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The fault draft source metadata is invalid.');
        }
        if (!is_array($notes)) {
            throw new RuntimeException('The fault draft source metadata is invalid.');
        }
        return $notes;
    }

    /** @return array{type: string, coordinates: array<mixed>} */
    private function normalizeGeometry(mixed $geometry): array
    {
        if (is_string($geometry)) {
            try {
                $geometry = json_decode($geometry, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('A fault draft geometry is not valid GeoJSON.');
            }
        }
        if (!is_array($geometry) || ($geometry['type'] ?? null) !== 'MultiLineString'
            || !is_array($geometry['coordinates'] ?? null)
            || !array_is_list($geometry['coordinates']) || $geometry['coordinates'] === []) {
            throw new RuntimeException('A fault draft geometry is not a non-empty MultiLineString.');
        }
        foreach ($geometry['coordinates'] as $line) {
            if (!is_array($line) || !array_is_list($line) || count($line) < 2) {
                throw new RuntimeException('A fault draft geometry contains an invalid line.');
            }
            foreach ($line as $position) {
                if (!is_array($position) || !array_is_list($position) || count($position) !== 2
                    || !is_numeric($position[0] ?? null) || !is_numeric($position[1] ?? null)) {
                    throw new RuntimeException('A fault draft geometry contains an invalid position.');
                }
                $longitude = (float) $position[0];
                $latitude = (float) $position[1];
                if (!is_finite($longitude) || !is_finite($latitude)
                    || $longitude < -180 || $longitude > 180
                    || $latitude < -90 || $latitude > 90) {
                    throw new RuntimeException('A fault draft geometry contains an out-of-range position.');
                }
            }
        }
        return ['type' => 'MultiLineString', 'coordinates' => $geometry['coordinates']];
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
