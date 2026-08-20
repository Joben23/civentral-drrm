<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;

/**
 * Read-only projection for the single reviewed DENR-MGB landslide draft.
 *
 * The dataset UUID and workflow filters are fixed server-side so this service
 * cannot enumerate arbitrary draft or inactive records.
 */
final class DrrmDraftLandslidePreviewService
{
    public const DATASET_VERSION_ID = '865bc866-acf8-4bdc-9538-32531624ee9f';
    public const EXPECTED_FEATURE_COUNT = 13;
    public const SOURCE_SERVICE_URL = 'https://controlmap.mgb.gov.ph/arcgis/rest/services/GeospatialDataInventory/GDI_Detailed_Rain_induced_Landslide_Susceptibility/FeatureServer/0';

    /** @var array<string, array{risk_level_id: int, mgb_label: string, display_risk_label: string}> */
    private const CLASSIFICATIONS = [
        'LL' => [
            'risk_level_id' => 1,
            'mgb_label' => 'Low Susceptibility to Landslide',
            'display_risk_label' => 'Low',
        ],
        'ML' => [
            'risk_level_id' => 2,
            'mgb_label' => 'Moderate Susceptibility to Landslide',
            'display_risk_label' => 'Moderate',
        ],
        'HL' => [
            'risk_level_id' => 3,
            'mgb_label' => 'High Susceptibility to Landslide',
            'display_risk_label' => 'High',
        ],
        'VHL' => [
            'risk_level_id' => 4,
            'mgb_label' => 'Very High Susceptibility to Landslide',
            'display_risk_label' => 'Very High',
        ],
    ];

    public function __construct(
        private readonly SupabaseRestClient $client,
        bool $localDevelopmentPreviewAllowed
    ) {
        if (!$localDevelopmentPreviewAllowed) {
            throw new RuntimeException('The draft landslide preview is unavailable.');
        }
    }

    /** @return array{type: string, features: list<array<string, mixed>>} */
    public function featureCollection(): array
    {
        $versions = $this->client->get('dataset_versions', [
            'select' => 'dataset_version_id,dataset_source_id',
            'dataset_version_id' => 'eq.' . self::DATASET_VERSION_ID,
            'dataset_category' => 'eq.HAZARD_ZONE',
            'hazard_type_id' => 'eq.2',
            'review_status' => 'eq.DRAFT',
            'limit' => 2,
        ]);
        if (count($versions) !== 1) {
            throw new RuntimeException('The controlled landslide draft is unavailable.');
        }

        $sourceId = (string) ($versions[0]['dataset_source_id'] ?? '');
        if (!$this->isUuid($sourceId)) {
            throw new RuntimeException('The controlled landslide draft has invalid provenance.');
        }
        $sources = $this->client->get('dataset_sources', [
            'select' => 'dataset_source_id',
            'dataset_source_id' => 'eq.' . $sourceId,
            'organization_url' => 'eq.https://mgb.gov.ph/',
            'record_status' => 'eq.ACTIVE',
            'limit' => 2,
        ]);
        if (count($sources) !== 1) {
            throw new RuntimeException('The controlled landslide source is unavailable.');
        }

        $records = $this->client->get('hazard_zones', [
            'select' => 'risk_level_id,geometry,classification_notes',
            'dataset_version_id' => 'eq.' . self::DATASET_VERSION_ID,
            'hazard_type_id' => 'eq.2',
            'record_status' => 'eq.INACTIVE',
            'order' => 'hazard_zone_id.asc',
            'limit' => self::EXPECTED_FEATURE_COUNT + 1,
        ]);
        if (count($records) !== self::EXPECTED_FEATURE_COUNT) {
            throw new RuntimeException('The controlled landslide draft does not contain the expected feature count.');
        }

        $seenObjectIds = [];
        $counts = array_fill_keys(array_keys(self::CLASSIFICATIONS), 0);
        $features = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new RuntimeException('The landslide draft returned an unexpected record structure.');
            }
            $notes = $this->decodeNotes($record['classification_notes'] ?? null);
            $code = strtoupper(trim((string) ($notes['mgb_landslide_code'] ?? '')));
            $classification = self::CLASSIFICATIONS[$code] ?? null;
            $objectId = filter_var($notes['source_object_id'] ?? null, FILTER_VALIDATE_INT);
            if ($classification === null || $objectId === false || $objectId < 1
                || isset($seenObjectIds[$objectId])
                || ($record['risk_level_id'] ?? null) !== $classification['risk_level_id']
                || ($notes['mgb_landslide_label'] ?? null) !== $classification['mgb_label']
                || ($notes['display_risk_label'] ?? null) !== $classification['display_risk_label']
                || ($notes['source_agency'] ?? null) !== 'DENR-MGB'
                || ($notes['source_service_reference'] ?? null) !== self::SOURCE_SERVICE_URL) {
                throw new RuntimeException('The landslide draft contains inconsistent source or classification metadata.');
            }

            $seenObjectIds[$objectId] = true;
            $counts[$code]++;
            $features[] = [
                'type' => 'Feature',
                'geometry' => $this->normalizeGeometry($record['geometry'] ?? null),
                'properties' => [
                    'hazard' => 'Landslide',
                    'mgb_code' => $code,
                    'mgb_label' => $classification['mgb_label'],
                    'display_risk_label' => $classification['display_risk_label'],
                    'source_agency' => 'DENR-MGB',
                ],
            ];
        }

        if ($counts !== ['LL' => 7, 'ML' => 2, 'HL' => 2, 'VHL' => 2]
            || count($seenObjectIds) !== self::EXPECTED_FEATURE_COUNT) {
            throw new RuntimeException('The landslide draft classification counts are inconsistent.');
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /** @return array<string, mixed> */
    private function decodeNotes(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('The landslide draft is missing classification metadata.');
        }
        try {
            $notes = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The landslide draft classification metadata is invalid.');
        }
        if (!is_array($notes)) {
            throw new RuntimeException('The landslide draft classification metadata is invalid.');
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
                throw new RuntimeException('A landslide draft geometry is not valid GeoJSON.');
            }
        }
        if (!is_array($geometry) || ($geometry['type'] ?? null) !== 'MultiPolygon'
            || !is_array($geometry['coordinates'] ?? null)
            || !array_is_list($geometry['coordinates']) || $geometry['coordinates'] === []) {
            throw new RuntimeException('A landslide draft geometry is not a non-empty MultiPolygon.');
        }
        foreach ($geometry['coordinates'] as $polygon) {
            $this->validatePolygon($polygon);
        }
        return ['type' => 'MultiPolygon', 'coordinates' => $geometry['coordinates']];
    }

    private function validatePolygon(mixed $polygon): void
    {
        if (!is_array($polygon) || !array_is_list($polygon) || $polygon === []) {
            throw new RuntimeException('A landslide draft geometry contains an empty polygon.');
        }
        foreach ($polygon as $ring) {
            if (!is_array($ring) || !array_is_list($ring) || count($ring) < 4) {
                throw new RuntimeException('A landslide draft geometry contains an invalid ring.');
            }
            foreach ($ring as $position) {
                if (!is_array($position) || !array_is_list($position) || count($position) !== 2
                    || !is_numeric($position[0] ?? null) || !is_numeric($position[1] ?? null)) {
                    throw new RuntimeException('A landslide draft geometry contains an invalid position.');
                }
                $longitude = (float) $position[0];
                $latitude = (float) $position[1];
                if (!is_finite($longitude) || !is_finite($latitude)
                    || $longitude < -180 || $longitude > 180
                    || $latitude < -90 || $latitude > 90) {
                    throw new RuntimeException('A landslide draft geometry contains an out-of-range position.');
                }
            }
            $first = $ring[0];
            $last = $ring[count($ring) - 1];
            if ((float) $first[0] !== (float) $last[0] || (float) $first[1] !== (float) $last[1]) {
                throw new RuntimeException('A landslide draft geometry contains an open ring.');
            }
        }
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
