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

const FLOOD_GEOJSON_INPUT = __DIR__ . '/../data/import/caloocan-mgb-flood-susceptibility.geojson';
const FLOOD_REPORT_INPUT = __DIR__ . '/../data/import/caloocan-mgb-flood-susceptibility-report.json';
const EXPECTED_FEATURE_COUNT = 15;
const INSERT_BATCH_SIZE = 1;
const EXPECTED_GEOJSON_SHA256 = 'BF41154CF0BF408FABA1B1072375800D2C507C225931C8E271283C5E53AE57BA';
const EXPECTED_REPORT_SHA256 = '0E6B9F43C70DE6AD2B003A8A86BD35A7FAA152FBF76C7CDB8A3670CA7D153314';
const SOURCE_ORGANIZATION = 'Department of Environment and Natural Resources - Mines and Geosciences Bureau (DENR-MGB)';
const SOURCE_ORGANIZATION_URL = 'https://mgb.gov.ph/';
const SOURCE_SERVICE_URL = 'https://controlmap.mgb.gov.ph/arcgis/rest/services/GeospatialDataInventory/GDI_Detailed_Flood_Susceptibility/FeatureServer/0';
const SOURCE_LAYER_NAME = 'Detailed Flood Susceptibility';
const SOURCE_RETRIEVED_AT = '2026-08-19T13:24:53.491Z';
const DATASET_VERSION_TITLE = 'DENR-MGB Detailed Flood Susceptibility - Caloocan City clipped draft';
const DATASET_VERSION_LABEL = 'denr-mgb-detailed-flood-caloocan-retrieved-2026-08-19-draft';
const VALIDATION_LONGITUDE = 120.98951;
const VALIDATION_LATITUDE = 14.64953;

/** @return array<string, mixed> */
function readJsonObject(string $path): array
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

function isUuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
}

/** @param list<string> $values @return list<string> */
function duplicateValues(array $values): array
{
    return array_keys(array_filter(array_count_values($values), static fn (int $count): bool => $count > 1));
}

function coordinateNumber(int|float $number): string
{
    try {
        return json_encode($number, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('A geometry coordinate could not be encoded safely.');
    }
}

/** @param array<mixed> $coordinates */
function multiPolygonEwkt(array $coordinates): string
{
    $polygonTexts = [];
    foreach ($coordinates as $polygon) {
        $ringTexts = [];
        foreach ($polygon as $ring) {
            $positions = [];
            foreach ($ring as $position) {
                $positions[] = coordinateNumber($position[0]) . ' ' . coordinateNumber($position[1]);
            }
            $ringTexts[] = '(' . implode(',', $positions) . ')';
        }
        $polygonTexts[] = '(' . implode(',', $ringTexts) . ')';
    }

    return 'SRID=4326;MULTIPOLYGON(' . implode(',', $polygonTexts) . ')';
}

/**
 * Validate Polygon/MultiPolygon structure without modifying coordinates.
 *
 * @param array<string, mixed> $geometry
 * @return array<mixed> MultiPolygon coordinates
 */
function validateAndWrapGeometry(array $geometry, string $label): array
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

/** @param array<mixed> $coordinates @return array{min_lon: float, min_lat: float, max_lon: float, max_lat: float} */
function coordinateBounds(array $coordinates): array
{
    $bounds = ['min_lon' => INF, 'min_lat' => INF, 'max_lon' => -INF, 'max_lat' => -INF];
    $visit = static function (array $value) use (&$visit, &$bounds): void {
        if (count($value) === 2 && is_numeric($value[0]) && is_numeric($value[1])) {
            $longitude = (float) $value[0];
            $latitude = (float) $value[1];
            $bounds['min_lon'] = min($bounds['min_lon'], $longitude);
            $bounds['min_lat'] = min($bounds['min_lat'], $latitude);
            $bounds['max_lon'] = max($bounds['max_lon'], $longitude);
            $bounds['max_lat'] = max($bounds['max_lat'], $latitude);
            return;
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $visit($child);
            }
        }
    };
    $visit($coordinates);

    if (!is_finite($bounds['min_lon']) || !is_finite($bounds['min_lat'])) {
        throw new RuntimeException('Unable to calculate geometry bounds.');
    }

    return $bounds;
}

/** @param array<mixed> $left @param array<mixed> $right */
function coordinatesEqual(array $left, array $right, float $epsilon = 1.0E-12): bool
{
    if (count($left) !== count($right) || array_keys($left) !== array_keys($right)) {
        return false;
    }

    foreach ($left as $key => $leftValue) {
        $rightValue = $right[$key];
        if (is_array($leftValue) || is_array($rightValue)) {
            if (!is_array($leftValue) || !is_array($rightValue) || !coordinatesEqual($leftValue, $rightValue, $epsilon)) {
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
function returnedGeoJsonGeometry(mixed $geometry, string $label): array
{
    if (is_string($geometry)) {
        $decoded = json_decode($geometry, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $geometry = $decoded;
        }
    }

    if (!is_array($geometry) || !is_string($geometry['type'] ?? null) || !is_array($geometry['coordinates'] ?? null)) {
        throw new RuntimeException($label . ' was not returned as GeoJSON-compatible geometry.');
    }

    return ['type' => $geometry['type'], 'coordinates' => $geometry['coordinates']];
}

/** @return list<array<string, int|string>> */
function expectedHazardTypes(): array
{
    return [
        ['hazard_type_id' => 1, 'code' => 'FLOOD', 'name' => 'Flood'],
        ['hazard_type_id' => 2, 'code' => 'LANDSLIDE', 'name' => 'Landslide'],
        ['hazard_type_id' => 3, 'code' => 'EARTHQUAKE_FAULT', 'name' => 'Earthquake/Fault'],
    ];
}

/** @return list<array<string, int|string>> */
function expectedRiskLevels(): array
{
    return [
        ['risk_level_id' => 1, 'code' => 'LOW', 'name' => 'Low', 'severity_rank' => 1],
        ['risk_level_id' => 2, 'code' => 'MODERATE', 'name' => 'Moderate', 'severity_rank' => 2],
        ['risk_level_id' => 3, 'code' => 'HIGH', 'name' => 'High', 'severity_rank' => 3],
        ['risk_level_id' => 4, 'code' => 'CRITICAL', 'name' => 'Critical', 'severity_rank' => 4],
    ];
}

/** @return array<string, array{risk_level_id: int, risk_level_code: string, display_risk_label: string, mgb_label: string}> */
function classificationMapping(): array
{
    return [
        'LF' => ['risk_level_id' => 1, 'risk_level_code' => 'LOW', 'display_risk_label' => 'Low', 'mgb_label' => 'Low Susceptibility to Flooding'],
        'MF' => ['risk_level_id' => 2, 'risk_level_code' => 'MODERATE', 'display_risk_label' => 'Moderate', 'mgb_label' => 'Moderate Susceptibility to Flooding'],
        'HF' => ['risk_level_id' => 3, 'risk_level_code' => 'HIGH', 'display_risk_label' => 'High', 'mgb_label' => 'High Susceptibility to Flooding'],
        'VHF' => ['risk_level_id' => 4, 'risk_level_code' => 'CRITICAL', 'display_risk_label' => 'Very High', 'mgb_label' => 'Very High Susceptibility to Flooding'],
    ];
}

/**
 * @return array{
 *   rows: list<array<string, mixed>>,
 *   rows_by_object_id: array<int, array<string, mixed>>,
 *   class_counts: array<string, int>,
 *   bounds: array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}
 * }
 */
function loadAndValidatePreparedData(): array
{
    if (strtoupper((string) hash_file('sha256', FLOOD_GEOJSON_INPUT)) !== EXPECTED_GEOJSON_SHA256
        || strtoupper((string) hash_file('sha256', FLOOD_REPORT_INPUT)) !== EXPECTED_REPORT_SHA256) {
        throw new RuntimeException('Prepared flood input checksums do not match the reviewed artifacts.');
    }

    $geoJson = readJsonObject(FLOOD_GEOJSON_INPUT);
    $report = readJsonObject(FLOOD_REPORT_INPUT);
    if (($geoJson['type'] ?? null) !== 'FeatureCollection' || !is_array($geoJson['features'] ?? null)
        || count($geoJson['features']) !== EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Flood input must contain exactly 15 GeoJSON features.');
    }

    if (($report['official_service_url'] ?? null) !== SOURCE_SERVICE_URL
        || ($report['service_layer_name'] ?? null) !== SOURCE_LAYER_NAME
        || ($report['retrieval_timestamp'] ?? null) !== SOURCE_RETRIEVED_AT
        || ($report['final_clipped_feature_count'] ?? null) !== EXPECTED_FEATURE_COUNT
        || ($report['geometry_validation']['passed'] ?? null) !== true
        || ($report['processing']['simplification_applied'] ?? null) !== false) {
        throw new RuntimeException('Flood provenance report does not match the controlled import contract.');
    }

    $mapping = classificationMapping();
    $rows = [];
    $rowsByObjectId = [];
    $classCounts = array_fill_keys(array_keys($mapping), 0);
    $bounds = ['min_lon' => INF, 'min_lat' => INF, 'max_lon' => -INF, 'max_lat' => -INF];

    foreach ($geoJson['features'] as $featureIndex => $feature) {
        if (!is_array($feature) || ($feature['type'] ?? null) !== 'Feature'
            || !is_array($feature['properties'] ?? null) || !is_array($feature['geometry'] ?? null)) {
            throw new RuntimeException('Flood feature ' . $featureIndex . ' has an invalid structure.');
        }

        $properties = $feature['properties'];
        $code = strtoupper(trim((string) ($properties['mgb_flood_code'] ?? '')));
        $mappingRow = $mapping[$code] ?? null;
        $objectId = filter_var($properties['source_object_id'] ?? null, FILTER_VALIDATE_INT);
        if ($mappingRow === null || $objectId === false || $objectId < 1
            || ($properties['mgb_flood_label'] ?? null) !== $mappingRow['mgb_label']
            || ($properties['source_agency'] ?? null) !== 'DENR-MGB'
            || ($properties['source_service_reference'] ?? null) !== SOURCE_SERVICE_URL) {
            throw new RuntimeException('Flood feature ' . $featureIndex . ' has invalid provenance or classification metadata.');
        }
        if (isset($rowsByObjectId[$objectId])) {
            throw new RuntimeException('Duplicate MGB source OBJECTID ' . $objectId . '.');
        }

        $multiCoordinates = validateAndWrapGeometry($feature['geometry'], 'MGB OBJECTID ' . $objectId);
        $featureBounds = coordinateBounds($multiCoordinates);
        $bounds['min_lon'] = min($bounds['min_lon'], $featureBounds['min_lon']);
        $bounds['min_lat'] = min($bounds['min_lat'], $featureBounds['min_lat']);
        $bounds['max_lon'] = max($bounds['max_lon'], $featureBounds['max_lon']);
        $bounds['max_lat'] = max($bounds['max_lat'], $featureBounds['max_lat']);

        $notes = [
            'source_agency' => 'DENR-MGB',
            'source_layer' => SOURCE_LAYER_NAME,
            'source_service_reference' => SOURCE_SERVICE_URL,
            'source_object_id' => $objectId,
            'mgb_flood_code' => $code,
            'mgb_flood_label' => $mappingRow['mgb_label'],
            'display_risk_label' => $mappingRow['display_risk_label'],
            'civentral_internal_risk_code' => $mappingRow['risk_level_code'],
            'terminology_note' => $code === 'VHF'
                ? 'MGB terminology is Very High Susceptibility to Flooding; CRITICAL is only the internal CIVENTRAL compatibility mapping.'
                : 'The CIVENTRAL risk code is an internal compatibility mapping; the MGB source terminology remains authoritative.',
        ];
        $classificationNotes = json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $row = [
            'source_object_id' => $objectId,
            'mgb_code' => $code,
            'mgb_label' => $mappingRow['mgb_label'],
            'display_risk_label' => $mappingRow['display_risk_label'],
            'risk_level_id' => $mappingRow['risk_level_id'],
            'risk_level_code' => $mappingRow['risk_level_code'],
            'multi_coordinates' => $multiCoordinates,
            'geometry_ewkt' => multiPolygonEwkt($multiCoordinates),
            'classification_notes' => $classificationNotes,
        ];
        $rows[] = $row;
        $rowsByObjectId[$objectId] = $row;
        $classCounts[$code]++;
    }

    $expectedCounts = ['LF' => 5, 'MF' => 3, 'HF' => 4, 'VHF' => 3];
    if ($classCounts !== $expectedCounts || count($rowsByObjectId) !== EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Prepared flood classification counts do not match the reviewed LF/MF/HF/VHF counts.');
    }

    $reportBounds = $report['final_bounding_box'] ?? null;
    if (!is_array($reportBounds) || count($reportBounds) !== 4
        || !coordinatesEqual(array_values($bounds), array_values($reportBounds))) {
        throw new RuntimeException('Prepared flood extent does not match the reviewed provenance report.');
    }

    return [
        'rows' => $rows,
        'rows_by_object_id' => $rowsByObjectId,
        'class_counts' => $classCounts,
        'bounds' => $bounds,
    ];
}

/** @return array<string, mixed> */
function sourcePayload(): array
{
    return [
        'organization_name' => SOURCE_ORGANIZATION,
        'organization_url' => SOURCE_ORGANIZATION_URL,
        'default_license' => null,
        'notes' => 'Official flood polygon geometry source: ' . SOURCE_SERVICE_URL . '. '
            . 'Layer: ' . SOURCE_LAYER_NAME . '. Retrieved ' . SOURCE_RETRIEVED_AT . '. '
            . 'No source license was stated in the reviewed ArcGIS layer metadata. HazardHunterPH is not the geometry provider.',
        'record_status' => 'ACTIVE',
    ];
}

/** @return array<string, mixed> */
function versionPayload(string $sourceId): array
{
    return [
        'dataset_source_id' => $sourceId,
        'dataset_category' => 'HAZARD_ZONE',
        'hazard_type_id' => 1,
        'source_title' => DATASET_VERSION_TITLE,
        'source_reference' => SOURCE_SERVICE_URL,
        'publication_date' => null,
        'effective_from' => null,
        'effective_to' => null,
        'version_label' => DATASET_VERSION_LABEL,
        'license' => null,
        'review_status' => 'DRAFT',
        'reviewed_by_civentral_user_id' => null,
        'reviewed_at' => null,
        'published_at' => null,
        'notes' => 'Controlled DRAFT snapshot of 15 DENR-MGB flood susceptibility features clipped to the validated Caloocan City MultiPolygon. '
            . 'Source retrieval timestamp: ' . SOURCE_RETRIEVED_AT . '. GeoJSON SHA-256: ' . EXPECTED_GEOJSON_SHA256 . '. '
            . 'Original MGB classes are preserved in classification_notes. Internal compatibility only: LF=LOW, MF=MODERATE, HF=HIGH, VHF=CRITICAL. '
            . 'MGB VHF must be displayed as Very High and must not be described as an MGB Critical classification. Keep DRAFT and associated zones INACTIVE until reviewed.',
    ];
}

/** @param array<string, mixed> $record @param array<string, mixed> $expected */
function assertFields(array $record, array $expected, string $label): void
{
    foreach ($expected as $field => $value) {
        if (!array_key_exists($field, $record) || $record[$field] !== $value) {
            throw new RuntimeException($label . ' field ' . $field . ' does not match the controlled value.');
        }
    }
}

/** @return array<string, mixed> */
function databasePreflight(SupabaseRestClient $client, array $prepared): array
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
    $hazardTypes = $client->get('hazard_types', [
        'select' => 'hazard_type_id,code,name',
        'order' => 'hazard_type_id.asc',
    ]);
    $riskLevels = $client->get('risk_levels', [
        'select' => 'risk_level_id,code,name,severity_rank',
        'order' => 'risk_level_id.asc',
    ]);

    if ($hazardTypes !== expectedHazardTypes() || $riskLevels !== expectedRiskLevels()) {
        throw new RuntimeException('Controlled hazard/risk lookup records do not match the approved seed state.');
    }

    $template = sourcePayload();
    $exactSources = [];
    $partialConflicts = [];
    foreach ($sources as $source) {
        $exact = ($source['organization_name'] ?? null) === $template['organization_name']
            && ($source['organization_url'] ?? null) === $template['organization_url']
            && ($source['default_license'] ?? null) === null;
        if ($exact) {
            $exactSources[] = $source;
        } elseif (($source['organization_name'] ?? null) === $template['organization_name']
            || ($source['organization_url'] ?? null) === $template['organization_url']) {
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

    $matchingSourceId = $sourceRecord['dataset_source_id'] ?? null;
    foreach ($versions as $version) {
        $sameIdentity = ($version['source_title'] ?? null) === DATASET_VERSION_TITLE
            || (($version['source_reference'] ?? null) === SOURCE_SERVICE_URL
                && ($version['version_label'] ?? null) === DATASET_VERSION_LABEL)
            || ($matchingSourceId !== null
                && ($version['dataset_source_id'] ?? null) === $matchingSourceId
                && ($version['dataset_category'] ?? null) === 'HAZARD_ZONE'
                && ($version['hazard_type_id'] ?? null) === 1
                && ($version['version_label'] ?? null) === DATASET_VERSION_LABEL);
        if ($sameIdentity) {
            throw new RuntimeException('The intended DENR-MGB flood draft dataset version already exists; import stopped to preserve idempotency.');
        }
    }

    $targetObjectIds = array_fill_keys(array_map('strval', array_keys($prepared['rows_by_object_id'])), true);
    $sourceObjectConflicts = [];
    foreach ($zones as $zone) {
        $notes = json_decode((string) ($zone['classification_notes'] ?? ''), true);
        if (!is_array($notes) || ($notes['source_service_reference'] ?? null) !== SOURCE_SERVICE_URL) {
            continue;
        }
        $objectId = (string) ($notes['source_object_id'] ?? '');
        if (isset($targetObjectIds[$objectId])) {
            $sourceObjectConflicts[] = $objectId;
        }
    }
    if ($sourceObjectConflicts !== []) {
        throw new RuntimeException('MGB source OBJECTIDs already exist in hazard_zones: ' . implode(', ', $sourceObjectConflicts));
    }

    return [
        'source_record' => $sourceRecord,
        'existing_sources' => $sources,
        'existing_versions' => $versions,
        'existing_zones' => $zones,
        'counts' => [
            'dataset_sources' => count($sources),
            'dataset_versions' => count($versions),
            'hazard_zones' => count($zones),
        ],
    ];
}

/** @return int 0=outside, 1=inside, 2=boundary */
function pointInRing(float $longitude, float $latitude, array $ring): int
{
    $inside = false;
    $count = count($ring);
    for ($index = 0, $previous = $count - 1; $index < $count; $previous = $index++) {
        $x1 = (float) $ring[$previous][0];
        $y1 = (float) $ring[$previous][1];
        $x2 = (float) $ring[$index][0];
        $y2 = (float) $ring[$index][1];
        $cross = (($x2 - $x1) * ($latitude - $y1)) - (($y2 - $y1) * ($longitude - $x1));
        if (abs($cross) <= 1.0E-12
            && $longitude >= min($x1, $x2) - 1.0E-12 && $longitude <= max($x1, $x2) + 1.0E-12
            && $latitude >= min($y1, $y2) - 1.0E-12 && $latitude <= max($y1, $y2) + 1.0E-12) {
            return 2;
        }
        if (($y1 > $latitude) !== ($y2 > $latitude)) {
            $intersectionLongitude = (($x2 - $x1) * ($latitude - $y1) / ($y2 - $y1)) + $x1;
            if ($longitude < $intersectionLongitude) {
                $inside = !$inside;
            }
        }
    }
    return $inside ? 1 : 0;
}

/** @param array<mixed> $multiPolygon */
function multiPolygonIntersectsPoint(array $multiPolygon, float $longitude, float $latitude): bool
{
    foreach ($multiPolygon as $polygon) {
        $exterior = pointInRing($longitude, $latitude, $polygon[0]);
        if ($exterior === 0) {
            continue;
        }
        if ($exterior === 2) {
            return true;
        }
        $insideHole = false;
        for ($ringIndex = 1, $ringCount = count($polygon); $ringIndex < $ringCount; $ringIndex++) {
            $hole = pointInRing($longitude, $latitude, $polygon[$ringIndex]);
            if ($hole === 2) {
                return true;
            }
            if ($hole === 1) {
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

/** @return array<string, mixed> */
function verifyImport(
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
        throw new RuntimeException('Imported flood dataset version is not uniquely available.');
    }
    $version = $versions[0];
    assertFields($version, [
        'dataset_category' => 'HAZARD_ZONE',
        'hazard_type_id' => 1,
        'source_title' => DATASET_VERSION_TITLE,
        'source_reference' => SOURCE_SERVICE_URL,
        'publication_date' => null,
        'effective_from' => null,
        'effective_to' => null,
        'version_label' => DATASET_VERSION_LABEL,
        'license' => null,
        'review_status' => 'DRAFT',
        'reviewed_by_civentral_user_id' => null,
        'reviewed_at' => null,
        'published_at' => null,
    ], 'Flood dataset version');

    $sourceId = (string) ($version['dataset_source_id'] ?? '');
    $sources = $client->get('dataset_sources', [
        'select' => 'dataset_source_id,organization_name,organization_url,default_license,record_status',
        'dataset_source_id' => 'eq.' . $sourceId,
    ]);
    if (count($sources) !== 1) {
        throw new RuntimeException('Flood dataset version has no unique DENR-MGB source.');
    }
    assertFields($sources[0], [
        'organization_name' => SOURCE_ORGANIZATION,
        'organization_url' => SOURCE_ORGANIZATION_URL,
        'default_license' => null,
        'record_status' => 'ACTIVE',
    ], 'Flood dataset source');

    $zones = $client->get('hazard_zones', [
        'select' => 'hazard_zone_id,hazard_type_id,risk_level_id,dataset_version_id,geometry,effective_from,effective_to,classification_notes,record_status',
        'dataset_version_id' => 'eq.' . $datasetVersionId,
        'order' => 'hazard_zone_id.asc',
        'limit' => EXPECTED_FEATURE_COUNT + 1,
    ]);
    if (count($zones) !== EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Imported flood dataset does not contain exactly 15 hazard zones.');
    }

    $classCounts = array_fill_keys(array_keys(classificationMapping()), 0);
    $riskCounts = ['LOW' => 0, 'MODERATE' => 0, 'HIGH' => 0, 'CRITICAL' => 0];
    $seenObjectIds = [];
    $validGeometryCount = 0;
    $pointMatches = [];

    foreach ($zones as $zone) {
        $notes = json_decode((string) ($zone['classification_notes'] ?? ''), true);
        if (!is_array($notes)) {
            throw new RuntimeException('Imported flood classification_notes is not structured JSON.');
        }
        $objectId = filter_var($notes['source_object_id'] ?? null, FILTER_VALIDATE_INT);
        $expected = $objectId === false ? null : ($prepared['rows_by_object_id'][$objectId] ?? null);
        if (!is_array($expected) || isset($seenObjectIds[$objectId])) {
            throw new RuntimeException('Imported flood zone has an unknown or duplicate MGB OBJECTID.');
        }
        assertFields($zone, [
            'hazard_type_id' => 1,
            'risk_level_id' => $expected['risk_level_id'],
            'dataset_version_id' => $datasetVersionId,
            'effective_from' => null,
            'effective_to' => null,
            'classification_notes' => $expected['classification_notes'],
            'record_status' => 'INACTIVE',
        ], 'MGB OBJECTID ' . $objectId);

        $geometry = returnedGeoJsonGeometry($zone['geometry'] ?? null, 'MGB OBJECTID ' . $objectId);
        if ($geometry['type'] !== 'MultiPolygon') {
            throw new RuntimeException('MGB OBJECTID ' . $objectId . ' was not stored as MultiPolygon.');
        }
        $multiCoordinates = validateAndWrapGeometry($geometry, 'MGB OBJECTID ' . $objectId . ' (Supabase)');
        if (!coordinatesEqual($expected['multi_coordinates'], $multiCoordinates)) {
            throw new RuntimeException('MGB OBJECTID ' . $objectId . ' coordinates changed after the PostGIS round trip.');
        }

        if (multiPolygonIntersectsPoint($multiCoordinates, VALIDATION_LONGITUDE, VALIDATION_LATITUDE)) {
            $pointMatches[] = [
                'source_object_id' => $objectId,
                'mgb_code' => $expected['mgb_code'],
                'mgb_label' => $expected['mgb_label'],
                'internal_risk_code' => $expected['risk_level_code'],
            ];
        }

        $seenObjectIds[$objectId] = true;
        $classCounts[$expected['mgb_code']]++;
        $riskCounts[$expected['risk_level_code']]++;
        $validGeometryCount++;
    }

    if ($classCounts !== $prepared['class_counts']
        || $riskCounts !== ['LOW' => 5, 'MODERATE' => 3, 'HIGH' => 4, 'CRITICAL' => 3]
        || count($seenObjectIds) !== EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Imported flood source/internal classification counts changed.');
    }

    $allSources = $client->get('dataset_sources', [
        'select' => 'dataset_source_id,organization_name,organization_url,default_license,notes,record_status',
        'order' => 'created_at.asc',
    ]);
    $allVersions = $client->get('dataset_versions', [
        'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,version_label,review_status',
        'order' => 'created_at.asc',
    ]);
    $allZones = $client->get('hazard_zones', [
        'select' => 'hazard_zone_id,hazard_type_id,risk_level_id,dataset_version_id,classification_notes,record_status',
        'order' => 'hazard_zone_id.asc',
    ]);
    foreach ([
        [$preexistingSources, $allSources, 'dataset source'],
        [$preexistingVersions, $allVersions, 'dataset version'],
        [$preexistingZones, $allZones, 'hazard zone'],
    ] as [$before, $after, $label]) {
        $idField = match ($label) {
            'dataset source' => 'dataset_source_id',
            'dataset version' => 'dataset_version_id',
            default => 'hazard_zone_id',
        };
        $afterById = [];
        foreach ($after as $record) {
            $afterById[(string) ($record[$idField] ?? '')] = $record;
        }
        foreach ($before as $record) {
            $id = (string) ($record[$idField] ?? '');
            if ($id === '' || !isset($afterById[$id]) || $afterById[$id] !== $record) {
                throw new RuntimeException('A pre-existing unrelated ' . $label . ' changed during import.');
            }
        }
    }

    if ((new DrrmMapReadService($client))->hazardZones() !== []) {
        throw new RuntimeException('The production ACTIVE-only hazard API exposed the inactive flood draft.');
    }

    $pointConsistent = count($pointMatches) === 1 && ($pointMatches[0]['mgb_code'] ?? null) === 'LF';

    return [
        'dataset_source_id' => $sourceId,
        'dataset_version_id' => $datasetVersionId,
        'review_status' => 'DRAFT',
        'hazard_zone_count' => count($zones),
        'zone_record_status' => 'INACTIVE',
        'hazard_type' => 'FLOOD',
        'source_class_counts' => $classCounts,
        'internal_risk_counts' => $riskCounts,
        'geometry_type' => 'MultiPolygon',
        'geometry_srid' => 4326,
        'non_empty_structurally_valid_geometries' => $validGeometryCount,
        'coordinates_preserved' => true,
        'active_production_hazard_zones' => 0,
        'unrelated_records_unchanged' => true,
        'validation_point' => [
            'longitude' => VALIDATION_LONGITUDE,
            'latitude' => VALIDATION_LATITUDE,
            'expected' => 'LF / Low Susceptibility to Flooding',
            'local_exact_intersection_matches_from_postgis_round_trip' => $pointMatches,
            'consistent' => $pointConsistent,
            'postgis_st_intersects_executed' => false,
            'postgis_limitation' => 'No spatial RPC is exposed by the current PostgREST schema; exact ST_Intersects requires a reviewed database function or SQL Editor query.',
        ],
    ];
}

/** @return array<string, mixed> */
function rollbackImport(
    SupabaseRestClient $client,
    ?string $datasetVersionId,
    ?string $datasetSourceId,
    bool $sourceCreated
): array {
    $result = [
        'complete' => true,
        'deleted_hazard_zones' => 0,
        'deleted_version' => false,
        'deleted_source' => false,
        'errors' => [],
    ];
    $versionRemoved = $datasetVersionId === null;

    if ($datasetVersionId !== null) {
        try {
            $zones = $client->get('hazard_zones', [
                'select' => 'hazard_zone_id,record_status',
                'dataset_version_id' => 'eq.' . $datasetVersionId,
            ]);
            foreach ($zones as $zone) {
                if (($zone['record_status'] ?? null) !== 'INACTIVE') {
                    throw new RuntimeException('Rollback refused to delete a non-INACTIVE hazard zone.');
                }
            }
            if ($zones !== []) {
                $deleted = $client->delete('hazard_zones', [
                    'dataset_version_id' => 'eq.' . $datasetVersionId,
                    'select' => 'hazard_zone_id',
                ]);
                $result['deleted_hazard_zones'] = count($deleted);
            }
            if ($client->get('hazard_zones', [
                'select' => 'hazard_zone_id',
                'dataset_version_id' => 'eq.' . $datasetVersionId,
            ]) !== []) {
                throw new RuntimeException('Rollback could not remove all version-scoped hazard zones.');
            }
            $deletedVersion = $client->delete('dataset_versions', [
                'dataset_version_id' => 'eq.' . $datasetVersionId,
                'review_status' => 'eq.DRAFT',
                'select' => 'dataset_version_id',
            ]);
            $result['deleted_version'] = count($deletedVersion) === 1;
            $versionRemoved = $result['deleted_version'];
            if (!$versionRemoved) {
                throw new RuntimeException('Rollback did not delete the exact draft dataset version.');
            }
        } catch (Throwable $exception) {
            $result['complete'] = false;
            $result['errors'][] = $exception->getMessage();
        }
    }

    if ($sourceCreated && $datasetSourceId !== null && $versionRemoved) {
        try {
            $remainingVersions = $client->get('dataset_versions', [
                'select' => 'dataset_version_id',
                'dataset_source_id' => 'eq.' . $datasetSourceId,
            ]);
            if ($remainingVersions === []) {
                $deletedSource = $client->delete('dataset_sources', [
                    'dataset_source_id' => 'eq.' . $datasetSourceId,
                    'select' => 'dataset_source_id',
                ]);
                $result['deleted_source'] = count($deletedSource) === 1;
                if (!$result['deleted_source']) {
                    throw new RuntimeException('Rollback did not delete the exact newly created dataset source.');
                }
            }
        } catch (Throwable $exception) {
            $result['complete'] = false;
            $result['errors'][] = $exception->getMessage();
        }
    }

    return $result;
}

/** @return array{mode: string, dataset_version_id?: string} */
function parseMode(array $arguments): array
{
    $arguments = array_slice($arguments, 1);
    if (count($arguments) !== 1) {
        throw new InvalidArgumentException('Use exactly one of --preflight, --execute, or --verify=<dataset-version-uuid>.');
    }
    if ($arguments[0] === '--preflight') {
        return ['mode' => 'preflight'];
    }
    if ($arguments[0] === '--execute') {
        return ['mode' => 'execute'];
    }
    if (str_starts_with($arguments[0], '--verify=')) {
        $id = substr($arguments[0], strlen('--verify='));
        if (!isUuid($id)) {
            throw new InvalidArgumentException('The --verify value must be a dataset-version UUID.');
        }
        return ['mode' => 'verify', 'dataset_version_id' => $id];
    }
    throw new InvalidArgumentException('Unsupported mode.');
}

try {
    $mode = parseMode($argv);
    $prepared = loadAndValidatePreparedData();
    $client = new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../.env'), 5, 120);

    if ($mode['mode'] === 'verify') {
        $verification = verifyImport($client, $mode['dataset_version_id'], $prepared);
        echo json_encode(['success' => true, 'verification' => $verification], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }

    $preflight = databasePreflight($client, $prepared);
    $preflightSummary = [
        'local_features' => count($prepared['rows']),
        'source_class_counts' => $prepared['class_counts'],
        'geometry_conversion' => 'Polygon wrapped as MultiPolygon only; MultiPolygon preserved',
        'source_extent' => $prepared['bounds'],
        'database_counts_before' => $preflight['counts'],
        'dataset_source_action' => $preflight['source_record'] === null ? 'CREATE' : 'REUSE',
        'dataset_version_action' => 'CREATE_DRAFT',
        'hazard_zone_action' => 'INSERT_15_INACTIVE',
        'batch_size' => INSERT_BATCH_SIZE,
        'rollback_scope' => 'Only hazard zones and UUIDs created by this execution',
    ];

    if ($mode['mode'] === 'preflight') {
        echo json_encode(['success' => true, 'preflight' => $preflightSummary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }

    echo json_encode(['success' => true, 'preflight' => $preflightSummary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    $datasetSourceId = null;
    $datasetVersionId = null;
    $sourceCreated = false;

    try {
        if ($preflight['source_record'] !== null) {
            $datasetSourceId = (string) $preflight['source_record']['dataset_source_id'];
        } else {
            $createdSources = $client->post('dataset_sources', sourcePayload(), [
                'select' => 'dataset_source_id,organization_name,organization_url,default_license,record_status',
            ]);
            if (count($createdSources) !== 1 || !isUuid((string) ($createdSources[0]['dataset_source_id'] ?? ''))) {
                throw new RuntimeException('Supabase did not return one created DENR-MGB source.');
            }
            assertFields($createdSources[0], [
                'organization_name' => SOURCE_ORGANIZATION,
                'organization_url' => SOURCE_ORGANIZATION_URL,
                'default_license' => null,
                'record_status' => 'ACTIVE',
            ], 'Created flood dataset source');
            $datasetSourceId = (string) $createdSources[0]['dataset_source_id'];
            $sourceCreated = true;
        }

        $createdVersions = $client->post('dataset_versions', versionPayload($datasetSourceId), [
            'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,publication_date,effective_from,effective_to,version_label,license,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
        ]);
        if (count($createdVersions) !== 1 || !isUuid((string) ($createdVersions[0]['dataset_version_id'] ?? ''))) {
            throw new RuntimeException('Supabase did not return one created flood draft version.');
        }
        assertFields($createdVersions[0], [
            'dataset_source_id' => $datasetSourceId,
            'dataset_category' => 'HAZARD_ZONE',
            'hazard_type_id' => 1,
            'review_status' => 'DRAFT',
            'reviewed_by_civentral_user_id' => null,
            'reviewed_at' => null,
            'published_at' => null,
        ], 'Created flood dataset version');
        $datasetVersionId = (string) $createdVersions[0]['dataset_version_id'];

        $insertedCount = 0;
        foreach (array_chunk($prepared['rows'], INSERT_BATCH_SIZE) as $batchIndex => $batch) {
            $payload = [];
            foreach ($batch as $row) {
                $payload[] = [
                    'hazard_type_id' => 1,
                    'risk_level_id' => $row['risk_level_id'],
                    'dataset_version_id' => $datasetVersionId,
                    'geometry' => $row['geometry_ewkt'],
                    'effective_from' => null,
                    'effective_to' => null,
                    'classification_notes' => $row['classification_notes'],
                    'record_status' => 'INACTIVE',
                ];
            }
            $inserted = $client->post('hazard_zones', $payload, [
                'select' => 'hazard_zone_id,hazard_type_id,risk_level_id,dataset_version_id,classification_notes,record_status',
            ]);
            if (count($inserted) !== count($payload)) {
                throw new RuntimeException('Flood batch ' . ($batchIndex + 1) . ' returned an unexpected record count.');
            }
            foreach ($inserted as $record) {
                if (($record['dataset_version_id'] ?? null) !== $datasetVersionId
                    || ($record['hazard_type_id'] ?? null) !== 1
                    || ($record['record_status'] ?? null) !== 'INACTIVE') {
                    throw new RuntimeException('Flood batch ' . ($batchIndex + 1) . ' returned an unsafe record.');
                }
            }
            $insertedCount += count($inserted);
            echo 'Imported inactive flood zones: ' . $insertedCount . '/' . EXPECTED_FEATURE_COUNT . PHP_EOL;
        }

        if ($insertedCount !== EXPECTED_FEATURE_COUNT) {
            throw new RuntimeException('Controlled import did not insert exactly 15 flood zones.');
        }

        $verification = verifyImport(
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
    } catch (Throwable $importException) {
        $rollback = rollbackImport($client, $datasetVersionId, $datasetSourceId, $sourceCreated);
        fwrite(STDERR, 'Flood import failed safely: ' . $importException->getMessage() . PHP_EOL);
        fwrite(STDERR, json_encode([
            'dataset_source_id' => $datasetSourceId,
            'dataset_version_id' => $datasetVersionId,
            'rollback' => $rollback,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Caloocan MGB flood draft import stopped: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
