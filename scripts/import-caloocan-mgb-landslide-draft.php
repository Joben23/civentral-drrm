<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../src/Services/DrrmMapReadService.php';

use App\Config\SupabaseConfig;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

const LANDSLIDE_GEOJSON_INPUT = __DIR__ . '/../data/import/caloocan-mgb-landslide-susceptibility.geojson';
const LANDSLIDE_REPORT_INPUT = __DIR__ . '/../data/import/caloocan-mgb-landslide-susceptibility-report.json';
const LANDSLIDE_EXPECTED_FEATURE_COUNT = 13;
const LANDSLIDE_INSERT_BATCH_SIZE = 1;
const LANDSLIDE_GEOJSON_SHA256 = '6F393AFEAB9D567171021B0D8093D168B34824DD3B629283CDE34A956813F317';
const LANDSLIDE_REPORT_SHA256 = '332EBE54C2A7E07BF30189FACB3EC79173E6AD6A4BA65EE471E1A5E1CA3A9A96';
const LANDSLIDE_SOURCE_ORGANIZATION = 'Department of Environment and Natural Resources - Mines and Geosciences Bureau (DENR-MGB)';
const LANDSLIDE_SOURCE_ORGANIZATION_URL = 'https://mgb.gov.ph/';
const LANDSLIDE_SOURCE_SERVICE_URL = 'https://controlmap.mgb.gov.ph/arcgis/rest/services/GeospatialDataInventory/GDI_Detailed_Rain_induced_Landslide_Susceptibility/FeatureServer/0';
const LANDSLIDE_SOURCE_LAYER_NAME = 'Detailed Rain-induced Landslide Susceptibility';
const LANDSLIDE_SOURCE_RETRIEVED_AT = '2026-08-19T15:52:55.136Z';
const LANDSLIDE_DATASET_TITLE = 'DENR-MGB Detailed Rain-induced Landslide Susceptibility - Caloocan City clipped draft';
const LANDSLIDE_DATASET_VERSION_LABEL = 'denr-mgb-detailed-rain-induced-landslide-caloocan-retrieved-2026-08-19-draft';

/** @return array<string, mixed> */
function landslideReadJson(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read required input: ' . basename($path));
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Invalid JSON in ' . basename($path) . ': ' . $exception->getMessage());
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('Expected a JSON object in ' . basename($path) . '.');
    }

    return $decoded;
}

function landslideIsUuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
}

function landslideCoordinateNumber(int|float $number): string
{
    try {
        return json_encode($number, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('A landslide geometry coordinate could not be encoded safely.');
    }
}

/** @param array<mixed> $coordinates */
function landslideMultiPolygonEwkt(array $coordinates): string
{
    $polygonTexts = [];
    foreach ($coordinates as $polygon) {
        $ringTexts = [];
        foreach ($polygon as $ring) {
            $positions = [];
            foreach ($ring as $position) {
                $positions[] = landslideCoordinateNumber($position[0]) . ' ' . landslideCoordinateNumber($position[1]);
            }
            $ringTexts[] = '(' . implode(',', $positions) . ')';
        }
        $polygonTexts[] = '(' . implode(',', $ringTexts) . ')';
    }

    return 'SRID=4326;MULTIPOLYGON(' . implode(',', $polygonTexts) . ')';
}

/**
 * Validate a prepared Polygon/MultiPolygon without changing coordinates, and
 * return its coordinates in the physical MultiPolygon shape required by PostGIS.
 *
 * @param array<string, mixed> $geometry
 * @return array<mixed>
 */
function landslideValidateAndWrapGeometry(array $geometry, string $label): array
{
    $type = $geometry['type'] ?? null;
    $coordinates = $geometry['coordinates'] ?? null;
    if (!in_array($type, ['Polygon', 'MultiPolygon'], true) || !is_array($coordinates)) {
        throw new RuntimeException($label . ' is not a Polygon or MultiPolygon.');
    }

    $multiPolygon = $type === 'Polygon' ? [$coordinates] : $coordinates;
    if (!array_is_list($multiPolygon) || $multiPolygon === []) {
        throw new RuntimeException($label . ' has empty geometry.');
    }

    foreach ($multiPolygon as $polygonIndex => $polygon) {
        if (!is_array($polygon) || !array_is_list($polygon) || $polygon === []) {
            throw new RuntimeException($label . ' has an empty polygon at index ' . $polygonIndex . '.');
        }
        foreach ($polygon as $ringIndex => $ring) {
            if (!is_array($ring) || !array_is_list($ring) || count($ring) < 4) {
                throw new RuntimeException($label . ' has an invalid ring at index ' . $ringIndex . '.');
            }
            foreach ($ring as $position) {
                if (!is_array($position) || !array_is_list($position) || count($position) !== 2
                    || !is_numeric($position[0]) || !is_numeric($position[1])) {
                    throw new RuntimeException($label . ' contains a non-2D numeric position.');
                }
                $longitude = (float) $position[0];
                $latitude = (float) $position[1];
                if (!is_finite($longitude) || !is_finite($latitude)
                    || $longitude < -180 || $longitude > 180
                    || $latitude < -90 || $latitude > 90) {
                    throw new RuntimeException($label . ' contains an out-of-range position.');
                }
            }
            $first = $ring[0];
            $last = $ring[count($ring) - 1];
            if ((float) $first[0] !== (float) $last[0] || (float) $first[1] !== (float) $last[1]) {
                throw new RuntimeException($label . ' contains an open ring.');
            }
        }
    }

    return $multiPolygon;
}

/** @param array<mixed> $left @param array<mixed> $right */
function landslideCoordinatesEqual(array $left, array $right, float $epsilon = 1.0E-12): bool
{
    if (count($left) !== count($right) || array_keys($left) !== array_keys($right)) {
        return false;
    }
    foreach ($left as $key => $leftValue) {
        $rightValue = $right[$key];
        if (is_array($leftValue) || is_array($rightValue)) {
            if (!is_array($leftValue) || !is_array($rightValue)
                || !landslideCoordinatesEqual($leftValue, $rightValue, $epsilon)) {
                return false;
            }
        } elseif (!is_numeric($leftValue) || !is_numeric($rightValue)
            || abs((float) $leftValue - (float) $rightValue) > $epsilon) {
            return false;
        }
    }
    return true;
}

/** @return array{type: string, coordinates: array<mixed>} */
function landslideReturnedGeometry(mixed $geometry, string $label): array
{
    if (is_string($geometry)) {
        try {
            $geometry = json_decode($geometry, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException($label . ' was not returned as GeoJSON geometry.');
        }
    }
    if (!is_array($geometry) || !is_string($geometry['type'] ?? null)
        || !is_array($geometry['coordinates'] ?? null)) {
        throw new RuntimeException($label . ' was not returned as GeoJSON-compatible geometry.');
    }
    return ['type' => $geometry['type'], 'coordinates' => $geometry['coordinates']];
}

/** @return array<string, array{risk_level_id: int, risk_level_code: string, display_risk_label: string, mgb_label: string}> */
function landslideClassificationMapping(): array
{
    return [
        'LL' => ['risk_level_id' => 1, 'risk_level_code' => 'LOW', 'display_risk_label' => 'Low', 'mgb_label' => 'Low Susceptibility to Landslide'],
        'ML' => ['risk_level_id' => 2, 'risk_level_code' => 'MODERATE', 'display_risk_label' => 'Moderate', 'mgb_label' => 'Moderate Susceptibility to Landslide'],
        'HL' => ['risk_level_id' => 3, 'risk_level_code' => 'HIGH', 'display_risk_label' => 'High', 'mgb_label' => 'High Susceptibility to Landslide'],
        'VHL' => ['risk_level_id' => 4, 'risk_level_code' => 'CRITICAL', 'display_risk_label' => 'Very High', 'mgb_label' => 'Very High Susceptibility to Landslide'],
    ];
}

/** @return list<array<string, int|string>> */
function landslideExpectedHazardTypes(): array
{
    return [
        ['hazard_type_id' => 1, 'code' => 'FLOOD', 'name' => 'Flood'],
        ['hazard_type_id' => 2, 'code' => 'LANDSLIDE', 'name' => 'Landslide'],
        ['hazard_type_id' => 3, 'code' => 'EARTHQUAKE_FAULT', 'name' => 'Earthquake/Fault'],
    ];
}

/** @return list<array<string, int|string>> */
function landslideExpectedRiskLevels(): array
{
    return [
        ['risk_level_id' => 1, 'code' => 'LOW', 'name' => 'Low', 'severity_rank' => 1],
        ['risk_level_id' => 2, 'code' => 'MODERATE', 'name' => 'Moderate', 'severity_rank' => 2],
        ['risk_level_id' => 3, 'code' => 'HIGH', 'name' => 'High', 'severity_rank' => 3],
        ['risk_level_id' => 4, 'code' => 'CRITICAL', 'name' => 'Critical', 'severity_rank' => 4],
    ];
}

/** @return array{rows: list<array<string, mixed>>, rows_by_object_id: array<int, array<string, mixed>>, class_counts: array<string, int>} */
function landslideLoadPreparedData(): array
{
    if (strtoupper((string) hash_file('sha256', LANDSLIDE_GEOJSON_INPUT)) !== LANDSLIDE_GEOJSON_SHA256
        || strtoupper((string) hash_file('sha256', LANDSLIDE_REPORT_INPUT)) !== LANDSLIDE_REPORT_SHA256) {
        throw new RuntimeException('Prepared landslide input checksums do not match the reviewed artifacts.');
    }

    $geoJson = landslideReadJson(LANDSLIDE_GEOJSON_INPUT);
    $report = landslideReadJson(LANDSLIDE_REPORT_INPUT);
    if (($geoJson['type'] ?? null) !== 'FeatureCollection' || !is_array($geoJson['features'] ?? null)
        || count($geoJson['features']) !== LANDSLIDE_EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Landslide input must contain exactly 13 GeoJSON features.');
    }
    if (($report['official_source']['service_url'] ?? null) !== LANDSLIDE_SOURCE_SERVICE_URL
        || ($report['official_source']['layer_name'] ?? null) !== LANDSLIDE_SOURCE_LAYER_NAME
        || ($report['retrieval_timestamp'] ?? null) !== LANDSLIDE_SOURCE_RETRIEVED_AT
        || ($report['retrieval']['source_feature_count_retrieved'] ?? null) !== LANDSLIDE_EXPECTED_FEATURE_COUNT
        || ($report['clipping']['final_clipped_feature_count'] ?? null) !== LANDSLIDE_EXPECTED_FEATURE_COUNT
        || ($report['clipping']['all_output_inside_caloocan'] ?? null) !== true
        || ($report['geometry_validation']['invalid_geometry_count'] ?? null) !== 0) {
        throw new RuntimeException('Landslide provenance report does not match the controlled import contract.');
    }

    $mapping = landslideClassificationMapping();
    $rows = [];
    $rowsByObjectId = [];
    $counts = array_fill_keys(array_keys($mapping), 0);
    foreach ($geoJson['features'] as $featureIndex => $feature) {
        if (!is_array($feature) || ($feature['type'] ?? null) !== 'Feature'
            || !is_array($feature['properties'] ?? null) || !is_array($feature['geometry'] ?? null)) {
            throw new RuntimeException('Landslide feature ' . $featureIndex . ' has an invalid structure.');
        }
        $properties = $feature['properties'];
        $code = strtoupper(trim((string) ($properties['mgb_landslide_code'] ?? '')));
        $mapped = $mapping[$code] ?? null;
        $objectId = filter_var($properties['source_object_id'] ?? null, FILTER_VALIDATE_INT);
        if ($mapped === null || $objectId === false || $objectId < 1 || isset($rowsByObjectId[$objectId])
            || ($properties['mgb_landslide_label'] ?? null) !== $mapped['mgb_label']
            || ($properties['source_agency'] ?? null) !== 'DENR-MGB'
            || ($properties['source_service'] ?? null) !== LANDSLIDE_SOURCE_SERVICE_URL) {
            throw new RuntimeException('Landslide feature ' . $featureIndex . ' has invalid source or classification metadata.');
        }

        $multiCoordinates = landslideValidateAndWrapGeometry($feature['geometry'], 'MGB OBJECTID ' . $objectId);
        $notes = [
            'source_agency' => 'DENR-MGB',
            'source_layer' => LANDSLIDE_SOURCE_LAYER_NAME,
            'source_service_reference' => LANDSLIDE_SOURCE_SERVICE_URL,
            'source_object_id' => $objectId,
            'mgb_landslide_code' => $code,
            'mgb_landslide_label' => $mapped['mgb_label'],
            'display_risk_label' => $mapped['display_risk_label'],
            'civentral_internal_risk_code' => $mapped['risk_level_code'],
            'terminology_note' => $code === 'VHL'
                ? 'MGB terminology is Very High Susceptibility to Landslide; CRITICAL is only the internal CIVENTRAL compatibility mapping.'
                : 'The CIVENTRAL risk code is an internal compatibility mapping; the MGB source terminology remains authoritative.',
        ];
        $row = [
            'source_object_id' => $objectId,
            'mgb_code' => $code,
            'mgb_label' => $mapped['mgb_label'],
            'display_risk_label' => $mapped['display_risk_label'],
            'risk_level_id' => $mapped['risk_level_id'],
            'risk_level_code' => $mapped['risk_level_code'],
            'multi_coordinates' => $multiCoordinates,
            'geometry_ewkt' => landslideMultiPolygonEwkt($multiCoordinates),
            'classification_notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
        $rows[] = $row;
        $rowsByObjectId[$objectId] = $row;
        $counts[$code]++;
    }

    if ($counts !== ['LL' => 7, 'ML' => 2, 'HL' => 2, 'VHL' => 2]
        || count($rowsByObjectId) !== LANDSLIDE_EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Prepared landslide classification counts do not match the reviewed LL/ML/HL/VHL counts.');
    }

    return ['rows' => $rows, 'rows_by_object_id' => $rowsByObjectId, 'class_counts' => $counts];
}

/** @return array<string, mixed> */
function landslideSourcePayload(): array
{
    return [
        'organization_name' => LANDSLIDE_SOURCE_ORGANIZATION,
        'organization_url' => LANDSLIDE_SOURCE_ORGANIZATION_URL,
        'default_license' => null,
        'notes' => 'Official DENR-MGB geographic dataset source. Individual dataset versions preserve their ArcGIS layer references and retrieval metadata.',
        'record_status' => 'ACTIVE',
    ];
}

/** @return array<string, mixed> */
function landslideVersionPayload(string $sourceId): array
{
    return [
        'dataset_source_id' => $sourceId,
        'dataset_category' => 'HAZARD_ZONE',
        'hazard_type_id' => 2,
        'source_title' => LANDSLIDE_DATASET_TITLE,
        'source_reference' => LANDSLIDE_SOURCE_SERVICE_URL,
        'publication_date' => null,
        'effective_from' => null,
        'effective_to' => null,
        'version_label' => LANDSLIDE_DATASET_VERSION_LABEL,
        'license' => null,
        'review_status' => 'DRAFT',
        'reviewed_by_civentral_user_id' => null,
        'reviewed_at' => null,
        'published_at' => null,
        'notes' => 'Controlled DRAFT snapshot of 13 DENR-MGB rain-induced landslide susceptibility features clipped to the validated Caloocan City MultiPolygon. '
            . 'Source retrieval timestamp: ' . LANDSLIDE_SOURCE_RETRIEVED_AT . '. GeoJSON SHA-256: ' . LANDSLIDE_GEOJSON_SHA256 . '. '
            . 'Original MGB classes remain in classification_notes. Internal compatibility only: LL=LOW, ML=MODERATE, HL=HIGH, VHL=CRITICAL. '
            . 'MGB VHL must be displayed as Very High and must not be described as an MGB Critical classification. Keep DRAFT and zones INACTIVE until formally reviewed.',
    ];
}

/** @param array<string, mixed> $record @param array<string, mixed> $expected */
function landslideAssertFields(array $record, array $expected, string $label): void
{
    foreach ($expected as $field => $value) {
        if (!array_key_exists($field, $record) || $record[$field] !== $value) {
            throw new RuntimeException($label . ' field ' . $field . ' does not match the controlled value.');
        }
    }
}

/** @return array<string, mixed> */
function landslidePreflight(SupabaseRestClient $client, array $prepared): array
{
    $sources = $client->get('dataset_sources', [
        'select' => 'dataset_source_id,organization_name,organization_url,default_license,notes,record_status',
        'order' => 'created_at.asc',
    ]);
    $versions = $client->get('dataset_versions', [
        'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,version_label,review_status',
        'order' => 'created_at.asc',
    ]);
    $zones = $client->get('hazard_zones', [
        'select' => 'hazard_zone_id,hazard_type_id,risk_level_id,dataset_version_id,classification_notes,record_status',
        'order' => 'hazard_zone_id.asc',
    ]);
    $hazardTypes = $client->get('hazard_types', ['select' => 'hazard_type_id,code,name', 'order' => 'hazard_type_id.asc']);
    $riskLevels = $client->get('risk_levels', ['select' => 'risk_level_id,code,name,severity_rank', 'order' => 'risk_level_id.asc']);
    if ($hazardTypes !== landslideExpectedHazardTypes() || $riskLevels !== landslideExpectedRiskLevels()) {
        throw new RuntimeException('Controlled hazard/risk lookup records do not match the approved seed state.');
    }

    $exactSources = [];
    $partialConflicts = [];
    foreach ($sources as $source) {
        $exact = ($source['organization_name'] ?? null) === LANDSLIDE_SOURCE_ORGANIZATION
            && ($source['organization_url'] ?? null) === LANDSLIDE_SOURCE_ORGANIZATION_URL
            && ($source['default_license'] ?? null) === null;
        if ($exact) {
            $exactSources[] = $source;
        } elseif (($source['organization_name'] ?? null) === LANDSLIDE_SOURCE_ORGANIZATION
            || ($source['organization_url'] ?? null) === LANDSLIDE_SOURCE_ORGANIZATION_URL) {
            $partialConflicts[] = $source;
        }
    }
    if (count($exactSources) > 1 || $partialConflicts !== []) {
        throw new RuntimeException('Existing dataset-source records conflict with the reviewed DENR-MGB provenance.');
    }
    $sourceRecord = $exactSources[0] ?? null;
    if ($sourceRecord !== null && ($sourceRecord['record_status'] ?? null) !== 'ACTIVE') {
        throw new RuntimeException('The matching DENR-MGB dataset source exists but is not ACTIVE.');
    }

    $sourceId = $sourceRecord['dataset_source_id'] ?? null;
    foreach ($versions as $version) {
        if (($version['source_title'] ?? null) === LANDSLIDE_DATASET_TITLE
            || (($version['source_reference'] ?? null) === LANDSLIDE_SOURCE_SERVICE_URL
                && ($version['version_label'] ?? null) === LANDSLIDE_DATASET_VERSION_LABEL)
            || ($sourceId !== null && ($version['dataset_source_id'] ?? null) === $sourceId
                && ($version['dataset_category'] ?? null) === 'HAZARD_ZONE'
                && ($version['hazard_type_id'] ?? null) === 2
                && ($version['version_label'] ?? null) === LANDSLIDE_DATASET_VERSION_LABEL)) {
            throw new RuntimeException('The intended DENR-MGB landslide draft dataset version already exists; import stopped to preserve idempotency.');
        }
    }

    $targetIds = array_fill_keys(array_map('strval', array_keys($prepared['rows_by_object_id'])), true);
    foreach ($zones as $zone) {
        $notes = json_decode((string) ($zone['classification_notes'] ?? ''), true);
        if (is_array($notes) && ($notes['source_service_reference'] ?? null) === LANDSLIDE_SOURCE_SERVICE_URL
            && isset($targetIds[(string) ($notes['source_object_id'] ?? '')])) {
            throw new RuntimeException('A reviewed MGB landslide source OBJECTID already exists in hazard_zones.');
        }
    }

    return [
        'source_record' => $sourceRecord,
        'existing_sources' => $sources,
        'existing_versions' => $versions,
        'existing_zones' => $zones,
        'counts' => ['dataset_sources' => count($sources), 'dataset_versions' => count($versions), 'hazard_zones' => count($zones)],
    ];
}

/** @return array<string, mixed> */
function landslideVerifyImport(
    SupabaseRestClient $client,
    string $datasetVersionId,
    array $prepared,
    array $preexistingSources = [],
    array $preexistingVersions = [],
    array $preexistingZones = []
): array {
    $versions = $client->get('dataset_versions', [
        'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,publication_date,effective_from,effective_to,version_label,license,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
        'dataset_version_id' => 'eq.' . $datasetVersionId,
    ]);
    if (count($versions) !== 1) {
        throw new RuntimeException('Imported landslide dataset version is not uniquely available.');
    }
    $version = $versions[0];
    landslideAssertFields($version, [
        'dataset_category' => 'HAZARD_ZONE', 'hazard_type_id' => 2,
        'source_title' => LANDSLIDE_DATASET_TITLE, 'source_reference' => LANDSLIDE_SOURCE_SERVICE_URL,
        'publication_date' => null, 'effective_from' => null, 'effective_to' => null,
        'version_label' => LANDSLIDE_DATASET_VERSION_LABEL, 'license' => null,
        'review_status' => 'DRAFT', 'reviewed_by_civentral_user_id' => null,
        'reviewed_at' => null, 'published_at' => null,
    ], 'Landslide dataset version');

    $sourceId = (string) ($version['dataset_source_id'] ?? '');
    $sources = $client->get('dataset_sources', [
        'select' => 'dataset_source_id,organization_name,organization_url,default_license,record_status',
        'dataset_source_id' => 'eq.' . $sourceId,
    ]);
    if (count($sources) !== 1) {
        throw new RuntimeException('Landslide dataset version has no unique DENR-MGB source.');
    }
    landslideAssertFields($sources[0], [
        'organization_name' => LANDSLIDE_SOURCE_ORGANIZATION,
        'organization_url' => LANDSLIDE_SOURCE_ORGANIZATION_URL,
        'default_license' => null,
        'record_status' => 'ACTIVE',
    ], 'Landslide dataset source');

    $zones = $client->get('hazard_zones', [
        'select' => 'hazard_zone_id,hazard_type_id,risk_level_id,dataset_version_id,geometry,effective_from,effective_to,classification_notes,record_status',
        'dataset_version_id' => 'eq.' . $datasetVersionId,
        'order' => 'hazard_zone_id.asc',
        'limit' => LANDSLIDE_EXPECTED_FEATURE_COUNT + 1,
    ]);
    if (count($zones) !== LANDSLIDE_EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Imported landslide dataset does not contain exactly 13 hazard zones.');
    }

    $classCounts = array_fill_keys(array_keys(landslideClassificationMapping()), 0);
    $riskCounts = ['LOW' => 0, 'MODERATE' => 0, 'HIGH' => 0, 'CRITICAL' => 0];
    $seenObjectIds = [];
    foreach ($zones as $zone) {
        $notes = json_decode((string) ($zone['classification_notes'] ?? ''), true);
        $objectId = is_array($notes) ? filter_var($notes['source_object_id'] ?? null, FILTER_VALIDATE_INT) : false;
        $expected = $objectId === false ? null : ($prepared['rows_by_object_id'][$objectId] ?? null);
        if (!is_array($expected) || isset($seenObjectIds[$objectId])) {
            throw new RuntimeException('Imported landslide zone has an unknown or duplicate MGB OBJECTID.');
        }
        landslideAssertFields($zone, [
            'hazard_type_id' => 2,
            'risk_level_id' => $expected['risk_level_id'],
            'dataset_version_id' => $datasetVersionId,
            'effective_from' => null,
            'effective_to' => null,
            'classification_notes' => $expected['classification_notes'],
            'record_status' => 'INACTIVE',
        ], 'MGB landslide OBJECTID ' . $objectId);
        $geometry = landslideReturnedGeometry($zone['geometry'] ?? null, 'MGB landslide OBJECTID ' . $objectId);
        if ($geometry['type'] !== 'MultiPolygon') {
            throw new RuntimeException('MGB landslide OBJECTID ' . $objectId . ' was not stored as MultiPolygon.');
        }
        $returnedCoordinates = landslideValidateAndWrapGeometry($geometry, 'MGB landslide OBJECTID ' . $objectId . ' (Supabase)');
        if (!landslideCoordinatesEqual($expected['multi_coordinates'], $returnedCoordinates)) {
            throw new RuntimeException('MGB landslide OBJECTID ' . $objectId . ' coordinates changed after the PostGIS round trip.');
        }
        $seenObjectIds[$objectId] = true;
        $classCounts[$expected['mgb_code']]++;
        $riskCounts[$expected['risk_level_code']]++;
    }
    if ($classCounts !== ['LL' => 7, 'ML' => 2, 'HL' => 2, 'VHL' => 2]
        || $riskCounts !== ['LOW' => 7, 'MODERATE' => 2, 'HIGH' => 2, 'CRITICAL' => 2]) {
        throw new RuntimeException('Imported landslide source/internal classification counts changed.');
    }

    $afterSets = [
        ['dataset_source_id', $preexistingSources, $client->get('dataset_sources', [
            'select' => 'dataset_source_id,organization_name,organization_url,default_license,notes,record_status', 'order' => 'created_at.asc',
        ])],
        ['dataset_version_id', $preexistingVersions, $client->get('dataset_versions', [
            'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,version_label,review_status', 'order' => 'created_at.asc',
        ])],
        ['hazard_zone_id', $preexistingZones, $client->get('hazard_zones', [
            'select' => 'hazard_zone_id,hazard_type_id,risk_level_id,dataset_version_id,classification_notes,record_status', 'order' => 'hazard_zone_id.asc',
        ])],
    ];
    foreach ($afterSets as [$idField, $before, $after]) {
        $afterById = [];
        foreach ($after as $record) {
            $afterById[(string) ($record[$idField] ?? '')] = $record;
        }
        foreach ($before as $record) {
            $id = (string) ($record[$idField] ?? '');
            if ($id === '' || !isset($afterById[$id]) || $afterById[$id] !== $record) {
                throw new RuntimeException('A pre-existing unrelated Module 1 record changed during landslide import.');
            }
        }
    }

    if ((new DrrmMapReadService($client))->hazardZones() !== []) {
        throw new RuntimeException('The production ACTIVE-only hazard service exposed inactive draft data.');
    }

    return [
        'dataset_source_id' => $sourceId,
        'dataset_version_id' => $datasetVersionId,
        'review_status' => 'DRAFT',
        'hazard_zone_count' => count($zones),
        'zone_record_status' => 'INACTIVE',
        'hazard_type' => 'LANDSLIDE',
        'source_class_counts' => $classCounts,
        'internal_risk_counts' => $riskCounts,
        'geometry_type' => 'MultiPolygon',
        'geometry_srid' => 4326,
        'prepared_geometry_valid_count' => LANDSLIDE_EXPECTED_FEATURE_COUNT,
        'postgis_round_trip_geometry_count' => count($zones),
        'coordinates_preserved' => true,
        'active_production_hazard_zones' => 0,
        'unrelated_records_unchanged' => true,
    ];
}

/** @return array<string, mixed> */
function landslideRollback(SupabaseRestClient $client, ?string $versionId, ?string $sourceId, bool $sourceCreated): array
{
    $result = ['complete' => true, 'deleted_hazard_zones' => 0, 'deleted_version' => false, 'deleted_source' => false, 'errors' => []];
    $versionRemoved = $versionId === null;
    if ($versionId !== null) {
        try {
            $zones = $client->get('hazard_zones', ['select' => 'hazard_zone_id,record_status', 'dataset_version_id' => 'eq.' . $versionId]);
            foreach ($zones as $zone) {
                if (($zone['record_status'] ?? null) !== 'INACTIVE') {
                    throw new RuntimeException('Rollback refused to delete a non-INACTIVE hazard zone.');
                }
            }
            if ($zones !== []) {
                $result['deleted_hazard_zones'] = count($client->delete('hazard_zones', [
                    'dataset_version_id' => 'eq.' . $versionId, 'select' => 'hazard_zone_id',
                ]));
            }
            $deletedVersion = $client->delete('dataset_versions', [
                'dataset_version_id' => 'eq.' . $versionId, 'review_status' => 'eq.DRAFT', 'select' => 'dataset_version_id',
            ]);
            $result['deleted_version'] = count($deletedVersion) === 1;
            $versionRemoved = $result['deleted_version'];
            if (!$versionRemoved) {
                throw new RuntimeException('Rollback did not delete the exact landslide draft version.');
            }
        } catch (Throwable $exception) {
            $result['complete'] = false;
            $result['errors'][] = $exception->getMessage();
        }
    }
    if ($sourceCreated && $sourceId !== null && $versionRemoved) {
        try {
            if ($client->get('dataset_versions', ['select' => 'dataset_version_id', 'dataset_source_id' => 'eq.' . $sourceId]) === []) {
                $deleted = $client->delete('dataset_sources', ['dataset_source_id' => 'eq.' . $sourceId, 'select' => 'dataset_source_id']);
                $result['deleted_source'] = count($deleted) === 1;
            }
        } catch (Throwable $exception) {
            $result['complete'] = false;
            $result['errors'][] = $exception->getMessage();
        }
    }
    return $result;
}

/** @return array{mode: string, dataset_version_id?: string} */
function landslideParseMode(array $arguments): array
{
    $arguments = array_slice($arguments, 1);
    if (count($arguments) !== 1) {
        throw new InvalidArgumentException('Use exactly one of --preflight, --execute, or --verify=<dataset-version-uuid>.');
    }
    if ($arguments[0] === '--preflight') return ['mode' => 'preflight'];
    if ($arguments[0] === '--execute') return ['mode' => 'execute'];
    if (str_starts_with($arguments[0], '--verify=')) {
        $id = substr($arguments[0], strlen('--verify='));
        if (!landslideIsUuid($id)) throw new InvalidArgumentException('The --verify value must be a dataset-version UUID.');
        return ['mode' => 'verify', 'dataset_version_id' => $id];
    }
    throw new InvalidArgumentException('Unsupported mode.');
}

try {
    $mode = landslideParseMode($argv);
    $prepared = landslideLoadPreparedData();
    $client = new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../.env'), 5, 120);
    if ($mode['mode'] === 'verify') {
        echo json_encode(['success' => true, 'verification' => landslideVerifyImport($client, $mode['dataset_version_id'], $prepared)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }

    $preflight = landslidePreflight($client, $prepared);
    $summary = [
        'local_features' => count($prepared['rows']),
        'source_class_counts' => $prepared['class_counts'],
        'geometry_conversion' => 'Polygon wrapped as MultiPolygon only; MultiPolygon preserved',
        'database_counts_before' => $preflight['counts'],
        'dataset_source_action' => $preflight['source_record'] === null ? 'CREATE' : 'REUSE',
        'dataset_version_action' => 'CREATE_DRAFT',
        'hazard_zone_action' => 'INSERT_13_INACTIVE',
        'batch_size' => LANDSLIDE_INSERT_BATCH_SIZE,
        'rollback_scope' => 'Only hazard zones and UUIDs created by this execution',
    ];
    if ($mode['mode'] === 'preflight') {
        echo json_encode(['success' => true, 'preflight' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }

    echo json_encode(['success' => true, 'preflight' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    $datasetSourceId = null;
    $datasetVersionId = null;
    $sourceCreated = false;
    try {
        if ($preflight['source_record'] !== null) {
            $datasetSourceId = (string) $preflight['source_record']['dataset_source_id'];
        } else {
            $createdSources = $client->post('dataset_sources', landslideSourcePayload(), [
                'select' => 'dataset_source_id,organization_name,organization_url,default_license,record_status',
            ]);
            if (count($createdSources) !== 1 || !landslideIsUuid((string) ($createdSources[0]['dataset_source_id'] ?? ''))) {
                throw new RuntimeException('Supabase did not return one created DENR-MGB source.');
            }
            $datasetSourceId = (string) $createdSources[0]['dataset_source_id'];
            $sourceCreated = true;
        }

        $createdVersions = $client->post('dataset_versions', landslideVersionPayload($datasetSourceId), [
            'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
        ]);
        if (count($createdVersions) !== 1 || !landslideIsUuid((string) ($createdVersions[0]['dataset_version_id'] ?? ''))) {
            throw new RuntimeException('Supabase did not return one created landslide draft version.');
        }
        landslideAssertFields($createdVersions[0], [
            'dataset_source_id' => $datasetSourceId, 'dataset_category' => 'HAZARD_ZONE',
            'hazard_type_id' => 2, 'review_status' => 'DRAFT',
            'reviewed_by_civentral_user_id' => null, 'reviewed_at' => null, 'published_at' => null,
        ], 'Created landslide dataset version');
        $datasetVersionId = (string) $createdVersions[0]['dataset_version_id'];

        $insertedCount = 0;
        foreach (array_chunk($prepared['rows'], LANDSLIDE_INSERT_BATCH_SIZE) as $batchIndex => $batch) {
            $payload = array_map(static fn (array $row): array => [
                'hazard_type_id' => 2,
                'risk_level_id' => $row['risk_level_id'],
                'dataset_version_id' => $datasetVersionId,
                'geometry' => $row['geometry_ewkt'],
                'effective_from' => null,
                'effective_to' => null,
                'classification_notes' => $row['classification_notes'],
                'record_status' => 'INACTIVE',
            ], $batch);
            $inserted = $client->post('hazard_zones', $payload, [
                'select' => 'hazard_zone_id,hazard_type_id,risk_level_id,dataset_version_id,record_status',
            ]);
            if (count($inserted) !== count($payload)) {
                throw new RuntimeException('Landslide batch ' . ($batchIndex + 1) . ' returned an unexpected record count.');
            }
            foreach ($inserted as $record) {
                if (($record['dataset_version_id'] ?? null) !== $datasetVersionId
                    || ($record['hazard_type_id'] ?? null) !== 2
                    || ($record['record_status'] ?? null) !== 'INACTIVE') {
                    throw new RuntimeException('Landslide batch ' . ($batchIndex + 1) . ' returned an unsafe record.');
                }
            }
            $insertedCount += count($inserted);
            echo 'Imported inactive landslide zones: ' . $insertedCount . '/' . LANDSLIDE_EXPECTED_FEATURE_COUNT . PHP_EOL;
        }
        if ($insertedCount !== LANDSLIDE_EXPECTED_FEATURE_COUNT) {
            throw new RuntimeException('Controlled import did not insert exactly 13 landslide zones.');
        }

        $verification = landslideVerifyImport(
            $client,
            $datasetVersionId,
            $prepared,
            $preflight['existing_sources'],
            $preflight['existing_versions'],
            $preflight['existing_zones']
        );
        echo json_encode([
            'success' => true,
            'import_completed' => true,
            'dataset_source_created' => $sourceCreated,
            'verification' => $verification,
            'rollback_needed' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        $rollback = landslideRollback($client, $datasetVersionId, $datasetSourceId, $sourceCreated);
        fwrite(STDERR, 'Landslide import failed safely: ' . $exception->getMessage() . PHP_EOL);
        fwrite(STDERR, json_encode(['dataset_source_id' => $datasetSourceId, 'dataset_version_id' => $datasetVersionId, 'rollback' => $rollback], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Caloocan MGB landslide draft import stopped: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
