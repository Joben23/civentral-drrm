<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/DrrmDataStoreInterface.php';
require_once __DIR__ . '/DrrmBarangayCatalogService.php';

/**
 * Read-only, public-map projection of DRRM Module 1 data.
 *
 * Query fields and filters are fixed here so browser input is never forwarded
 * directly to PostgREST and internal workflow metadata is not exposed.
 */
final class DrrmMapReadService
{
    public function __construct(private readonly DrrmDataStoreInterface $client)
    {
    }

    /** @return list<array<string, mixed>> */
    public function barangays(?string $search = null): array
    {
        $search = $this->validateBarangaySearch($search);
        $versionId = (new DrrmBarangayCatalogService($this->client))
            ->currentPublishedOperationalVersionId();
        if ($versionId === null) {
            return [];
        }
        $versions = $this->eligiblePublishedVersions('BARANGAY_BOUNDARY');
        if (!array_key_exists($versionId, $versions)) {
            throw new RuntimeException('The operational barangay catalog is not publication eligible.');
        }
        $versions = [$versionId => 0];

        $query = [
            'select' => 'barangay_id,barangay_code,name,district_code,boundary_geometry,boundary_dataset_version_id,record_status',
            'boundary_dataset_version_id' => 'in.(' . implode(',', array_keys($versions)) . ')',
            'record_status' => 'eq.ACTIVE',
            'order' => 'name.asc',
        ];

        if ($search !== null) {
            // PostgREST ilike with a trailing * is a case-insensitive prefix match.
            $query['name'] = 'ilike.' . $search . '*';
        }

        $records = array_values(array_filter(
            $this->fetchMapRecords('barangays', $query, ['boundary_geometry']),
            static fn (array $record): bool =>
                ($record['record_status'] ?? null) === 'ACTIVE'
                && array_key_exists(
                    (string) ($record['boundary_dataset_version_id'] ?? ''),
                    $versions
                )
        ));

        return $this->withoutFields($records, ['boundary_dataset_version_id']);
    }

    /** @return list<array<string, mixed>> */
    public function hazardZones(): array
    {
        $versions = $this->eligiblePublishedVersions('HAZARD_ZONE');
        if ($versions === []) {
            return [];
        }

        $records = array_values(array_filter($this->fetchMapRecords('hazard_zones', [
            'select' => 'hazard_zone_id,hazard_type_id,risk_level_id,dataset_version_id,geometry,classification_notes,record_status',
            'dataset_version_id' => 'in.(' . implode(',', array_keys($versions)) . ')',
            'record_status' => 'eq.ACTIVE',
            'order' => 'hazard_type_id.asc,risk_level_id.asc',
        ], ['geometry']), static function (array $record) use ($versions): bool {
            $versionId = (string) ($record['dataset_version_id'] ?? '');
            $hazardTypeId = $record['hazard_type_id'] ?? null;
            return ($record['record_status'] ?? null) === 'ACTIVE'
                && isset($versions[$versionId])
                && is_int($hazardTypeId)
                && $versions[$versionId] === $hazardTypeId;
        }));

        return $this->withoutFields($records, ['dataset_version_id']);
    }

    /** @return list<array<string, mixed>> */
    public function faultFeatures(): array
    {
        $versions = $this->eligiblePublishedVersions('FAULT_FEATURE');
        if ($versions === []) {
            return [];
        }

        $records = array_values(array_filter($this->fetchMapRecords('fault_features', [
            'select' => 'fault_feature_id,hazard_type_id,dataset_version_id,feature_name,feature_class,geometry,record_status',
            'dataset_version_id' => 'in.(' . implode(',', array_keys($versions)) . ')',
            'record_status' => 'eq.ACTIVE',
            'order' => 'feature_name.asc',
        ], ['geometry']), static function (array $record) use ($versions): bool {
            $versionId = (string) ($record['dataset_version_id'] ?? '');
            $hazardTypeId = $record['hazard_type_id'] ?? null;
            return ($record['record_status'] ?? null) === 'ACTIVE'
                && isset($versions[$versionId])
                && is_int($hazardTypeId)
                && $versions[$versionId] === $hazardTypeId;
        }));

        return $this->withoutFields($records, ['dataset_version_id']);
    }

    /** @return list<array<string, mixed>> */
    public function evacuationCenters(): array
    {
        $records = array_values(array_filter($this->fetchMapRecords('evacuation_centers', [
            'select' => 'evacuation_center_id,name,barangay_id,location,address,capacity,operational_status,publication_status,contact_phone,accessibility_notes,managing_office_name,verified_by_civentral_user_id,verified_at',
            'publication_status' => 'eq.PUBLISHED',
            'operational_status' => 'neq.INACTIVE',
            'order' => 'name.asc',
        ], ['location']), fn (array $record): bool => $this->isOperationalCenter($record)));

        return $this->withoutFields(
            $records,
            ['verified_by_civentral_user_id', 'verified_at']
        );
    }

    /** @return list<array<string, mixed>> */
    public function evacuationRoutes(): array
    {
        $routes = array_values(array_filter($this->fetchMapRecords('evacuation_routes', [
            'select' => 'evacuation_route_id,route_name,origin_barangay_id,origin_name,origin_location,destination_center_id,route_geometry,distance_meters,safety_notes,route_status,approved_by_civentral_user_id,approved_at,last_reviewed_at,supersedes_route_id',
            'route_status' => 'eq.APPROVED',
            'order' => 'route_name.asc',
        ], ['origin_location', 'route_geometry']), fn (array $record): bool => $this->isApprovedRoute($record)));

        if ($routes === []) {
            return [];
        }

        $supersededRouteIds = [];
        foreach ($routes as $route) {
            $predecessorId = $route['supersedes_route_id'] ?? null;
            if ($predecessorId !== null) {
                if (!$this->isUuid((string) $predecessorId)
                    || $predecessorId === ($route['evacuation_route_id'] ?? null)) {
                    throw new RuntimeException('An approved route has invalid successor lineage.');
                }
                $supersededRouteIds[(string) $predecessorId] = true;
            }
        }
        $routes = array_values(array_filter(
            $routes,
            static fn (array $route): bool =>
                !isset($supersededRouteIds[(string) ($route['evacuation_route_id'] ?? '')])
        ));

        $centerIds = [];
        foreach ($routes as $route) {
            $centerId = (string) ($route['destination_center_id'] ?? '');
            if ($this->isUuid($centerId)) {
                $centerIds[$centerId] = true;
            }
        }
        if ($centerIds === []) {
            return [];
        }

        $centers = $this->fetchMapRecords('evacuation_centers', [
            'select' => 'evacuation_center_id,publication_status,operational_status,verified_by_civentral_user_id,verified_at',
            'evacuation_center_id' => 'in.(' . implode(',', array_keys($centerIds)) . ')',
            'publication_status' => 'eq.PUBLISHED',
            'operational_status' => 'neq.INACTIVE',
            'limit' => count($centerIds) + 1,
        ], []);
        $eligibleCenterIds = [];
        foreach ($centers as $center) {
            if ($this->isOperationalCenter($center)) {
                $eligibleCenterIds[(string) $center['evacuation_center_id']] = true;
            }
        }

        $routes = array_values(array_filter(
            $routes,
            static fn (array $route): bool =>
                isset($eligibleCenterIds[(string) ($route['destination_center_id'] ?? '')])
        ));

        return $this->withoutFields(
            $routes,
            ['approved_by_civentral_user_id', 'approved_at', 'last_reviewed_at', 'supersedes_route_id']
        );
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

    /** @return array<string, int> dataset version ID to hazard type ID (zero when not applicable) */
    private function eligiblePublishedVersions(string $category): array
    {
        $versions = $this->assertRecordList($this->client->get('dataset_versions', [
            'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_reference,publication_date,effective_from,license,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
            'dataset_category' => 'eq.' . $category,
            'review_status' => 'eq.PUBLISHED',
            'order' => 'published_at.desc',
            'limit' => 20,
        ]));

        if ($versions === []) {
            return [];
        }

        $eligibleHazardTypeIds = $category === 'BARANGAY_BOUNDARY'
            ? []
            : $this->eligibleHazardTypeIds($category);

        $sourceIds = [];
        $eligible = [];
        $scopes = [];
        foreach ($versions as $version) {
            $versionId = (string) ($version['dataset_version_id'] ?? '');
            $sourceId = (string) ($version['dataset_source_id'] ?? '');
            $hazardTypeId = $version['hazard_type_id'] ?? null;

            if (!$this->isUuid($versionId) || !$this->isUuid($sourceId)
                || ($version['dataset_category'] ?? null) !== $category
                || ($version['review_status'] ?? null) !== 'PUBLISHED'
                || !$this->nonEmptyString($version['source_reference'] ?? null)
                || !$this->nonEmptyString($version['license'] ?? null)
                || !$this->nonEmptyString($version['reviewed_by_civentral_user_id'] ?? null)
                || !$this->nonEmptyString($version['publication_date'] ?? null)
                || !$this->nonEmptyString($version['effective_from'] ?? null)
                || !$this->nonEmptyString($version['reviewed_at'] ?? null)
                || !$this->nonEmptyString($version['published_at'] ?? null)
                || ($category === 'BARANGAY_BOUNDARY' && $hazardTypeId !== null)
                || ($category !== 'BARANGAY_BOUNDARY'
                    && (!is_int($hazardTypeId) || !isset($eligibleHazardTypeIds[$hazardTypeId])))) {
                throw new RuntimeException('A published GIS dataset version is missing governance metadata.');
            }

            $scope = $category . ':' . ($hazardTypeId ?? 'NONE');
            if (isset($scopes[$scope])) {
                throw new RuntimeException('More than one GIS dataset version is published for one scope.');
            }
            $scopes[$scope] = true;
            $sourceIds[$sourceId] = true;
            $eligible[$versionId] = is_int($hazardTypeId) ? $hazardTypeId : 0;
        }

        $sources = $this->assertRecordList($this->client->get('dataset_sources', [
            'select' => 'dataset_source_id,record_status',
            'dataset_source_id' => 'in.(' . implode(',', array_keys($sourceIds)) . ')',
            'record_status' => 'eq.ACTIVE',
            'limit' => count($sourceIds) + 1,
        ]));
        $activeSourceIds = [];
        foreach ($sources as $source) {
            $sourceId = (string) ($source['dataset_source_id'] ?? '');
            if ($this->isUuid($sourceId) && ($source['record_status'] ?? null) === 'ACTIVE') {
                $activeSourceIds[$sourceId] = true;
            }
        }
        if (count($activeSourceIds) !== count($sourceIds)) {
            throw new RuntimeException('A published GIS dataset source is inactive or unavailable.');
        }

        return $eligible;
    }

    /** @return array<int, true> */
    private function eligibleHazardTypeIds(string $category): array
    {
        $hazardTypes = $this->assertRecordList($this->client->get('hazard_types', [
            'select' => 'hazard_type_id,code',
            'order' => 'hazard_type_id.asc',
            'limit' => 100,
        ]));
        $eligible = [];
        foreach ($hazardTypes as $hazardType) {
            $id = $hazardType['hazard_type_id'] ?? null;
            $code = $hazardType['code'] ?? null;
            if (!is_int($id) || !$this->nonEmptyString($code)) {
                throw new RuntimeException('The hazard-type catalog is malformed.');
            }
            if (($category === 'FAULT_FEATURE' && $code === 'EARTHQUAKE_FAULT')
                || ($category === 'HAZARD_ZONE' && $code !== 'EARTHQUAKE_FAULT')) {
                $eligible[$id] = true;
            }
        }
        if ($eligible === []) {
            throw new RuntimeException('No hazard type is eligible for the requested dataset category.');
        }
        return $eligible;
    }

    /** @param array<string, mixed> $record */
    private function isOperationalCenter(array $record): bool
    {
        return $this->isUuid((string) ($record['evacuation_center_id'] ?? ''))
            && ($record['publication_status'] ?? null) === 'PUBLISHED'
            && $this->nonEmptyString($record['operational_status'] ?? null)
            && ($record['operational_status'] ?? null) !== 'INACTIVE'
            && $this->nonEmptyString($record['verified_by_civentral_user_id'] ?? null)
            && $this->nonEmptyString($record['verified_at'] ?? null);
    }

    /** @param array<string, mixed> $record */
    private function isApprovedRoute(array $record): bool
    {
        $distance = $record['distance_meters'] ?? null;

        return ($record['route_status'] ?? null) === 'APPROVED'
            && $this->isUuid((string) ($record['destination_center_id'] ?? ''))
            && $this->isGeoJsonLineString($record['route_geometry'] ?? null)
            && $this->nonEmptyString($record['approved_by_civentral_user_id'] ?? null)
            && $this->nonEmptyString($record['approved_at'] ?? null)
            && $this->nonEmptyString($record['last_reviewed_at'] ?? null)
            && $this->nonEmptyString($record['safety_notes'] ?? null)
            && (is_int($distance) || is_float($distance))
            && (float) $distance > 0;
    }

    private function isGeoJsonLineString(mixed $geometry): bool
    {
        if (!is_array($geometry)
            || ($geometry['type'] ?? null) !== 'LineString'
            || !is_array($geometry['coordinates'] ?? null)
            || count($geometry['coordinates']) < 2) {
            return false;
        }

        foreach ($geometry['coordinates'] as $position) {
            if (!is_array($position) || count($position) < 2
                || !(is_int($position[0]) || is_float($position[0]))
                || !(is_int($position[1]) || is_float($position[1]))
                || !is_finite((float) $position[0])
                || !is_finite((float) $position[1])
                || (float) $position[0] < -180
                || (float) $position[0] > 180
                || (float) $position[1] < -90
                || (float) $position[1] > 90) {
                return false;
            }
        }

        return true;
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
     * @param list<array<string, mixed>> $records
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function withoutFields(array $records, array $fields): array
    {
        foreach ($records as &$record) {
            foreach ($fields as $field) {
                unset($record[$field]);
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

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
