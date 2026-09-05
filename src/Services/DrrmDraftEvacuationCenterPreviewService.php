<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/DrrmDataStoreInterface.php';

/**
 * Read-only projection for the controlled 15-center development subset.
 *
 * The exact UUIDs and workflow filters are fixed server-side. The coordinates
 * are public-map candidates and must never be represented as LGU-verified.
 */
final class DrrmDraftEvacuationCenterPreviewService
{
    public const EXPECTED_FEATURE_COUNT = 15;
    public const SOURCE_AGENCY = 'City Government of Caloocan / Caloocan PIO';
    public const LOCATION_STATUS = 'Location pending LGU verification';

    /** @var list<string> */
    private const CENTER_IDS = [
        '72983eab-ab39-4b3f-8fea-4fc00dd01f64',
        '8f7971f2-f211-4b07-a8fb-78c46eb3a61c',
        'dd3969bc-61ca-4438-bc7a-0539966be563',
        'b5b38b3e-34f1-4698-aaf8-34e17cd38225',
        '02ac7a7a-9217-4960-9c32-8b7cb0cada72',
        'e3536932-b058-4a3b-a77c-81d9562313ac',
        '9adfc0e0-9da3-4ca3-8178-f94283fb15a9',
        'c39d7430-c71c-42db-a001-a3455ce099e2',
        '20872767-3ac2-445e-9399-8b83469b0e61',
        '37e2995d-598f-49c6-8101-c834da18b478',
        '806786d2-5de5-4387-bfd3-70f74e059c55',
        'f4271e62-4f94-4f1c-94cd-90c291867a0f',
        'b1f948f5-ed31-4e0a-9b5c-bb618c6d3f37',
        '179c7438-c5c8-48d1-a5c7-2b810dcf5342',
        '8ef632de-92d3-4558-8304-1f8b21ba7bb7',
    ];

    public function __construct(
        private readonly DrrmDataStoreInterface $client,
        bool $localDevelopmentPreviewAllowed
    ) {
        if (!$localDevelopmentPreviewAllowed) {
            throw new RuntimeException('The draft evacuation-center preview is unavailable.');
        }
    }

    /** @return array{type: string, features: list<array<string, mixed>>} */
    public function featureCollection(): array
    {
        $centerFilter = 'in.(' . implode(',', self::CENTER_IDS) . ')';
        $records = $this->client->get('evacuation_centers', [
            'select' => 'evacuation_center_id,name,barangay_id,location,address,capacity,operational_status,publication_status,contact_phone,accessibility_notes,managing_office_name,verified_by_civentral_user_id,verified_at',
            'evacuation_center_id' => $centerFilter,
            'publication_status' => 'eq.DRAFT',
            'operational_status' => 'eq.INACTIVE',
            'order' => 'name.asc',
            'limit' => self::EXPECTED_FEATURE_COUNT + 1,
        ]);

        if (count($records) !== self::EXPECTED_FEATURE_COUNT) {
            throw new RuntimeException('The controlled evacuation-center draft does not contain the expected record count.');
        }

        $barangayIds = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new RuntimeException('The evacuation-center draft returned an invalid record.');
            }
            $barangayId = (string) ($record['barangay_id'] ?? '');
            if (!$this->isUuid($barangayId)) {
                throw new RuntimeException('An evacuation-center draft has an invalid barangay reference.');
            }
            $barangayIds[$barangayId] = true;
        }

        $barangays = $this->client->get('barangays', [
            'select' => 'barangay_id,name,record_status,boundary_dataset_version_id',
            'barangay_id' => 'in.(' . implode(',', array_keys($barangayIds)) . ')',
            'record_status' => 'eq.INACTIVE',
            'order' => 'name.asc',
        ]);
        $barangaysById = [];
        foreach ($barangays as $barangay) {
            $id = (string) ($barangay['barangay_id'] ?? '');
            if (!$this->isUuid($id) || isset($barangaysById[$id])
                || ($barangay['boundary_dataset_version_id'] ?? null) !== DrrmDraftBarangayPreviewService::DATASET_VERSION_ID
                || preg_match('/^Barangay (?:[1-9]|[1-9]\d|1\d\d)$/', (string) ($barangay['name'] ?? '')) !== 1) {
                throw new RuntimeException('The evacuation-center draft has an invalid barangay relationship.');
            }
            $barangaysById[$id] = $barangay;
        }
        if (count($barangaysById) !== count($barangayIds)) {
            throw new RuntimeException('The evacuation-center draft has an unresolved barangay relationship.');
        }

        $expectedIds = array_fill_keys(self::CENTER_IDS, true);
        $seenIds = [];
        $seenNames = [];
        $features = [];
        foreach ($records as $record) {
            $centerId = (string) ($record['evacuation_center_id'] ?? '');
            $name = trim((string) ($record['name'] ?? ''));
            $barangayId = (string) ($record['barangay_id'] ?? '');
            $barangay = $barangaysById[$barangayId] ?? null;

            if (!isset($expectedIds[$centerId]) || isset($seenIds[$centerId]) || $name === ''
                || isset($seenNames[strtolower($name)]) || !is_array($barangay)
                || ($record['address'] ?? null) !== $barangay['name'] . ', Caloocan City'
                || ($record['capacity'] ?? null) !== 0
                || ($record['operational_status'] ?? null) !== 'INACTIVE'
                || ($record['publication_status'] ?? null) !== 'DRAFT'
                || ($record['contact_phone'] ?? null) !== null
                || ($record['accessibility_notes'] ?? null) !== null
                || ($record['managing_office_name'] ?? null) !== 'City Government of Caloocan'
                || ($record['verified_by_civentral_user_id'] ?? null) !== null
                || ($record['verified_at'] ?? null) !== null) {
                throw new RuntimeException('The controlled evacuation-center draft contains inconsistent staging data.');
            }

            $seenIds[$centerId] = true;
            $seenNames[strtolower($name)] = true;
            $features[] = [
                'type' => 'Feature',
                'geometry' => $this->normalizePoint($record['location'] ?? null),
                'properties' => [
                    'evacuation_center_id' => $centerId,
                    'name' => $name,
                    'barangay_name' => $barangay['name'],
                    'designation' => 'Evacuation Center',
                    'location_verification_status' => self::LOCATION_STATUS,
                    'display_status' => 'Development Preview',
                    'source_agency' => self::SOURCE_AGENCY,
                ],
            ];
        }

        if (array_diff_key($expectedIds, $seenIds) !== [] || count($seenIds) !== self::EXPECTED_FEATURE_COUNT) {
            throw new RuntimeException('The controlled evacuation-center draft UUID set changed.');
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /** @return array{type: string, coordinates: array{0: float, 1: float}} */
    private function normalizePoint(mixed $geometry): array
    {
        if (is_string($geometry)) {
            try {
                $geometry = json_decode($geometry, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('An evacuation-center location is not valid GeoJSON.');
            }
        }

        if (!is_array($geometry) || ($geometry['type'] ?? null) !== 'Point'
            || !is_array($geometry['coordinates'] ?? null) || count($geometry['coordinates']) !== 2
            || !is_numeric($geometry['coordinates'][0]) || !is_numeric($geometry['coordinates'][1])) {
            throw new RuntimeException('An evacuation-center location is not a GeoJSON Point.');
        }

        $longitude = (float) $geometry['coordinates'][0];
        $latitude = (float) $geometry['coordinates'][1];
        if (!is_finite($longitude) || !is_finite($latitude)
            || $longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90) {
            throw new RuntimeException('An evacuation-center location is outside valid coordinate ranges.');
        }

        return ['type' => 'Point', 'coordinates' => [$longitude, $latitude]];
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
