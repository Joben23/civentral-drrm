<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only, public-map projection of DRRM Module 1 data.
 *
 * Query fields and filters are fixed here so browser input is never forwarded
 * directly to PostgREST and internal workflow metadata is not exposed.
 */
final class DrrmMapReadService
{
    public function __construct(private readonly SupabaseRestClient $client)
    {
    }

    /** @return list<array<string, mixed>> */
    public function barangays(?string $search = null): array
    {
        $query = [
            'select' => 'barangay_id,barangay_code,name,district_code,boundary_geometry',
            'record_status' => 'eq.ACTIVE',
            'order' => 'name.asc',
        ];

        $search = $this->validateBarangaySearch($search);

        if ($search !== null) {
            // PostgREST ilike with a trailing * is a case-insensitive prefix match.
            $query['name'] = 'ilike.' . $search . '*';
        }

        return $this->fetchMapRecords('barangays', $query, ['boundary_geometry']);
    }

    /** @return list<array<string, mixed>> */
    public function hazardZones(): array
    {
        return $this->fetchMapRecords('hazard_zones', [
            'select' => 'hazard_zone_id,hazard_type_id,risk_level_id,geometry,classification_notes',
            'record_status' => 'eq.ACTIVE',
            'order' => 'hazard_type_id.asc,risk_level_id.asc',
        ], ['geometry']);
    }

    /** @return list<array<string, mixed>> */
    public function faultFeatures(): array
    {
        return $this->fetchMapRecords('fault_features', [
            'select' => 'fault_feature_id,feature_name,feature_class,geometry',
            'record_status' => 'eq.ACTIVE',
            'order' => 'feature_name.asc',
        ], ['geometry']);
    }

    /** @return list<array<string, mixed>> */
    public function evacuationCenters(): array
    {
        return $this->fetchMapRecords('evacuation_centers', [
            'select' => 'evacuation_center_id,name,barangay_id,location,address,capacity,operational_status,contact_phone,accessibility_notes,managing_office_name',
            'publication_status' => 'eq.PUBLISHED',
            'operational_status' => 'neq.INACTIVE',
            'order' => 'name.asc',
        ], ['location']);
    }

    /** @return list<array<string, mixed>> */
    public function evacuationRoutes(): array
    {
        return $this->fetchMapRecords('evacuation_routes', [
            'select' => 'evacuation_route_id,route_name,origin_barangay_id,origin_name,origin_location,destination_center_id,route_geometry,distance_meters,safety_notes',
            'route_status' => 'eq.APPROVED',
            'order' => 'route_name.asc',
        ], ['origin_location', 'route_geometry']);
    }

    /**
     * @return array{
     *     hazard_types: list<array<string, mixed>>,
     *     risk_levels: list<array<string, mixed>>
     * }
     */
    public function lookups(): array
    {
        $hazardTypes = $this->client->get('hazard_types', [
            'select' => 'hazard_type_id,code,name',
            'is_active' => 'eq.true',
            'order' => 'hazard_type_id.asc',
        ]);

        $riskLevels = $this->client->get('risk_levels', [
            'select' => 'risk_level_id,code,name,severity_rank',
            'is_active' => 'eq.true',
            'order' => 'severity_rank.asc',
        ]);

        return [
            'hazard_types' => $this->assertRecordList($hazardTypes),
            'risk_levels' => $this->assertRecordList($riskLevels),
        ];
    }

    /**
     * @param array<string, scalar> $query
     * @param list<string> $geometryFields
     * @return list<array<string, mixed>>
     */
    private function fetchMapRecords(string $resource, array $query, array $geometryFields): array
    {
        $records = $this->assertRecordList($this->client->get($resource, $query));

        foreach ($records as &$record) {
            foreach ($geometryFields as $field) {
                if (array_key_exists($field, $record)) {
                    $record[$field] = $this->preserveGeoJsonGeometry($record[$field]);
                }
            }
        }
        unset($record);

        return $records;
    }

    /**
     * Decode a geometry only when PostgREST supplies a GeoJSON-compatible JSON
     * string. Other representations remain untouched until real GIS records can
     * be used to validate the project's PostGIS serialization behavior.
     */
    private function preserveGeoJsonGeometry(mixed $geometry): mixed
    {
        if (!is_string($geometry) || $geometry === '') {
            return $geometry;
        }

        $decoded = json_decode($geometry, true);

        if (
            json_last_error() === JSON_ERROR_NONE
            && is_array($decoded)
            && isset($decoded['type'])
            && array_key_exists('coordinates', $decoded)
        ) {
            return $decoded;
        }

        return $geometry;
    }

    /**
     * @param array<mixed> $records
     * @return list<array<string, mixed>>
     */
    private function assertRecordList(array $records): array
    {
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new RuntimeException('The DRRM data source returned an unexpected record structure.');
            }
        }

        /** @var list<array<string, mixed>> $records */
        return array_values($records);
    }

    private function validateBarangaySearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $search = trim($search);

        if ($search === '') {
            return null;
        }

        if (strlen($search) > 80 || preg_match("/^[\p{L}\p{N} .'-]+$/u", $search) !== 1) {
            throw new InvalidArgumentException('The barangay search value is invalid.');
        }

        return $search;
    }
}
