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

const GEOJSON_INPUT = __DIR__ . '/../data/import/caloocan-barangays-current-unaffected.geojson';
const PSGC_INPUT = __DIR__ . '/../data/import/caloocan-current-psgc.json';
const RECONCILIATION_INPUT = __DIR__ . '/../data/import/caloocan-barangay-reconciliation.json';
const CITY_BOUNDARY_INPUT = __DIR__ . '/../data/import/caloocan-city-boundary.geojson';
const EXPECTED_FEATURE_COUNT = 187;
const EXPECTED_CURRENT_COUNT = 193;
const INSERT_BATCH_SIZE = 10;

const GEOMETRY_SOURCE_ORGANIZATION = 'OCHA Field Information Services Section (FISS)';
const GEOMETRY_SOURCE_ORGANIZATION_URL = 'https://data.humdata.org/organization/ocha-fiss';
const GEOMETRY_SOURCE_LICENSE = 'Creative Commons Attribution for Intergovernmental Organisations (CC BY-IGO)';
const GEOMETRY_DATASET_URL = 'https://data.humdata.org/dataset/cod-ab-phl';
const GEOMETRY_RESOURCE_ID = '0120c30e-ba8b-487d-83f5-a664eddd3a8e';
const GEOMETRY_RESOURCE_NAME = 'phl_admin_boundaries.geojson.zip';
const GEOMETRY_RESOURCE_DATE = '2026-05-28';
const GEOMETRY_SOURCE_VERSION = 'v03';
const GEOMETRY_EFFECTIVE_FROM = '2025-02-13';

const PSA_SOURCE_URL = 'https://psa.gov.ph/classification/psgc/barangays/1380100000?vcode=6';
const PSA_RELEASE_LABEL = 'Philippine Standard Geographic Code as of 30 June 2026';
const DATASET_VERSION_TITLE = 'Caloocan unaffected barangay boundaries (187 of 193) - HDX cod-ab-phl v03 draft';
const DATASET_VERSION_LABEL = 'v03-caloocan-187-of-193-draft-psgc-2026-06-30';

const REPLACEMENT_BARANGAYS = [
    'Barangay 176-A' => '1380100189',
    'Barangay 176-B' => '1380100190',
    'Barangay 176-C' => '1380100191',
    'Barangay 176-D' => '1380100192',
    'Barangay 176-E' => '1380100193',
    'Barangay 176-F' => '1380100194',
];

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

function normalizedName(string $name): string
{
    return strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
}

/** @param list<string> $values @return list<string> */
function duplicateValues(array $values): array
{
    return array_keys(array_filter(array_count_values($values), static fn (int $count): bool => $count > 1));
}

function isListArray(mixed $value): bool
{
    return is_array($value) && array_is_list($value);
}

/** @param array{0: float|int, 1: float|int} $first @param array{0: float|int, 1: float|int} $second */
function pointsEqual(array $first, array $second, float $epsilon = 0.0): bool
{
    return abs((float) $first[0] - (float) $second[0]) <= $epsilon
        && abs((float) $first[1] - (float) $second[1]) <= $epsilon;
}

/** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b @param array{0: float, 1: float} $c */
function orientation(array $a, array $b, array $c): float
{
    return (($b[0] - $a[0]) * ($c[1] - $a[1])) - (($b[1] - $a[1]) * ($c[0] - $a[0]));
}

/** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b @param array{0: float, 1: float} $point */
function pointOnSegment(array $a, array $b, array $point, float $epsilon = 1.0E-12): bool
{
    return abs(orientation($a, $b, $point)) <= $epsilon
        && $point[0] >= min($a[0], $b[0]) - $epsilon
        && $point[0] <= max($a[0], $b[0]) + $epsilon
        && $point[1] >= min($a[1], $b[1]) - $epsilon
        && $point[1] <= max($a[1], $b[1]) + $epsilon;
}

/**
 * @param array{0: float, 1: float} $a
 * @param array{0: float, 1: float} $b
 * @param array{0: float, 1: float} $c
 * @param array{0: float, 1: float} $d
 */
function segmentsIntersect(array $a, array $b, array $c, array $d): bool
{
    $epsilon = 1.0E-12;
    $o1 = orientation($a, $b, $c);
    $o2 = orientation($a, $b, $d);
    $o3 = orientation($c, $d, $a);
    $o4 = orientation($c, $d, $b);

    if ((($o1 > $epsilon && $o2 < -$epsilon) || ($o1 < -$epsilon && $o2 > $epsilon))
        && (($o3 > $epsilon && $o4 < -$epsilon) || ($o3 < -$epsilon && $o4 > $epsilon))) {
        return true;
    }

    return (abs($o1) <= $epsilon && pointOnSegment($a, $b, $c))
        || (abs($o2) <= $epsilon && pointOnSegment($a, $b, $d))
        || (abs($o3) <= $epsilon && pointOnSegment($c, $d, $a))
        || (abs($o4) <= $epsilon && pointOnSegment($c, $d, $b));
}

/**
 * Validate the controlled dataset's simple closed exterior ring and return
 * normalized numeric points for topology checks only. Payload coordinates are
 * kept in their original decoded structure.
 *
 * @param array<mixed> $ring
 * @return list<array{0: float, 1: float}>
 */
function validateRing(array $ring, string $label): array
{
    if (!array_is_list($ring) || count($ring) < 4) {
        throw new RuntimeException($label . ' must contain a closed ring with at least four positions.');
    }

    $points = [];
    foreach ($ring as $positionIndex => $position) {
        if (!isListArray($position) || count($position) !== 2 || !is_numeric($position[0]) || !is_numeric($position[1])) {
            throw new RuntimeException($label . ' contains a non-2D coordinate at position ' . $positionIndex . '.');
        }

        $longitude = (float) $position[0];
        $latitude = (float) $position[1];
        if (!is_finite($longitude) || !is_finite($latitude) || $longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90) {
            throw new RuntimeException($label . ' contains a coordinate outside valid longitude/latitude bounds.');
        }

        $points[] = [$longitude, $latitude];
    }

    if (!pointsEqual($points[0], $points[count($points) - 1])) {
        throw new RuntimeException($label . ' is not closed.');
    }

    for ($index = 1, $count = count($points); $index < $count; $index++) {
        if (pointsEqual($points[$index - 1], $points[$index])) {
            throw new RuntimeException($label . ' contains a zero-length edge.');
        }
    }

    $twiceArea = 0.0;
    for ($index = 0, $edgeCount = count($points) - 1; $index < $edgeCount; $index++) {
        $twiceArea += ($points[$index][0] * $points[$index + 1][1])
            - ($points[$index + 1][0] * $points[$index][1]);
    }
    if (abs($twiceArea) <= 1.0E-14) {
        throw new RuntimeException($label . ' has zero area.');
    }

    $edgeCount = count($points) - 1;
    for ($firstEdge = 0; $firstEdge < $edgeCount; $firstEdge++) {
        for ($secondEdge = $firstEdge + 1; $secondEdge < $edgeCount; $secondEdge++) {
            $adjacent = abs($firstEdge - $secondEdge) <= 1
                || ($firstEdge === 0 && $secondEdge === $edgeCount - 1);
            if ($adjacent) {
                continue;
            }

            if (segmentsIntersect(
                $points[$firstEdge],
                $points[$firstEdge + 1],
                $points[$secondEdge],
                $points[$secondEdge + 1]
            )) {
                throw new RuntimeException($label . ' self-intersects.');
            }
        }
    }

    return $points;
}

/** @param array<mixed> $coordinates */
function validateControlledMultiPolygon(array $coordinates, string $label): void
{
    if (!array_is_list($coordinates) || count($coordinates) !== 1) {
        throw new RuntimeException($label . ' must contain exactly one polygon part for this controlled Polygon-to-MultiPolygon import.');
    }

    $polygon = $coordinates[0];
    if (!isListArray($polygon) || count($polygon) !== 1 || !is_array($polygon[0])) {
        throw new RuntimeException($label . ' must contain exactly one exterior ring and no inferred holes.');
    }

    validateRing($polygon[0], $label);
}

/** @param array<mixed> $coordinates @return array{min_lon: float, min_lat: float, max_lon: float, max_lat: float} */
function coordinateBounds(array $coordinates): array
{
    $bounds = [
        'min_lon' => INF,
        'min_lat' => INF,
        'max_lon' => -INF,
        'max_lat' => -INF,
    ];

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

/** @param list<array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}> $bounds */
function mergeBounds(array $bounds): array
{
    if ($bounds === []) {
        throw new RuntimeException('Cannot merge an empty bounds list.');
    }

    return [
        'min_lon' => min(array_column($bounds, 'min_lon')),
        'min_lat' => min(array_column($bounds, 'min_lat')),
        'max_lon' => max(array_column($bounds, 'max_lon')),
        'max_lat' => max(array_column($bounds, 'max_lat')),
    ];
}

/** @param array{min_lon: float, min_lat: float, max_lon: float, max_lat: float} $inner @param array{min_lon: float, min_lat: float, max_lon: float, max_lat: float} $outer */
function boundsInside(array $inner, array $outer, float $tolerance = 1.0E-8): bool
{
    return $inner['min_lon'] >= $outer['min_lon'] - $tolerance
        && $inner['min_lat'] >= $outer['min_lat'] - $tolerance
        && $inner['max_lon'] <= $outer['max_lon'] + $tolerance
        && $inner['max_lat'] <= $outer['max_lat'] + $tolerance;
}

function coordinateNumber(int|float $number): string
{
    try {
        return json_encode($number, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('A geometry coordinate could not be encoded safely.');
    }
}

/** @param array<mixed> $multiPolygonCoordinates */
function multiPolygonEwkt(array $multiPolygonCoordinates): string
{
    $polygonTexts = [];
    foreach ($multiPolygonCoordinates as $polygon) {
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
            continue;
        }

        if (!is_numeric($leftValue) || !is_numeric($rightValue) || abs((float) $leftValue - (float) $rightValue) > $epsilon) {
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
        throw new RuntimeException($label . ' was not returned by Supabase as GeoJSON-compatible geometry.');
    }

    return [
        'type' => $geometry['type'],
        'coordinates' => $geometry['coordinates'],
    ];
}

/**
 * @return array{
 *   rows: list<array{barangay_code: string, name: string, district_code: null, boundary_geometry: string, multi_coordinates: array<mixed>}>,
 *   rows_by_code: array<string, array{barangay_code: string, name: string, district_code: null, boundary_geometry: string, multi_coordinates: array<mixed>}>,
 *   bounds: array{min_lon: float, min_lat: float, max_lon: float, max_lat: float},
 *   city_bounds: array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}
 * }
 */
function loadAndValidatePreparedData(): array
{
    foreach ([GEOJSON_INPUT, PSGC_INPUT, RECONCILIATION_INPUT, CITY_BOUNDARY_INPUT] as $path) {
        if (!is_file($path)) {
            throw new RuntimeException('Required prepared input is missing: ' . basename($path));
        }
    }

    $geoJson = readJsonObject(GEOJSON_INPUT);
    $master = readJsonObject(PSGC_INPUT);
    $reconciliation = readJsonObject(RECONCILIATION_INPUT);
    $cityBoundary = readJsonObject(CITY_BOUNDARY_INPUT);

    $features = $geoJson['features'] ?? null;
    if (($geoJson['type'] ?? null) !== 'FeatureCollection' || !is_array($features) || count($features) !== EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Prepared GeoJSON must contain exactly 187 features.');
    }

    $masterBarangays = $master['barangays'] ?? null;
    if (!is_array($masterBarangays) || count($masterBarangays) !== EXPECTED_CURRENT_COUNT) {
        throw new RuntimeException('Current PSGC master must contain exactly 193 barangays.');
    }
    if (($master['metadata']['authority'] ?? null) !== 'Philippine Statistics Authority (PSA)'
        || ($master['metadata']['release_label'] ?? null) !== PSA_RELEASE_LABEL
        || ($master['metadata']['source_reference'] ?? null) !== PSA_SOURCE_URL) {
        throw new RuntimeException('Current PSGC master provenance does not match the reviewed PSA source.');
    }

    if (($reconciliation['counts']['unaffected_current_barangays_with_source_geometry'] ?? null) !== EXPECTED_FEATURE_COUNT
        || ($reconciliation['counts']['current_expected_barangays'] ?? null) !== EXPECTED_CURRENT_COUNT
        || ($reconciliation['geometry_readiness']['complete_current_193_barangay_dataset_safe_for_import'] ?? null) !== false) {
        throw new RuntimeException('Reconciliation report does not describe the approved incomplete 187-of-193 staging state.');
    }
    if (($reconciliation['source_dataset']['version'] ?? null) !== GEOMETRY_SOURCE_VERSION
        || ($reconciliation['source_dataset']['valid_on'] ?? null) !== GEOMETRY_EFFECTIVE_FROM) {
        throw new RuntimeException('Reconciliation geometry version/date does not match the reviewed source metadata.');
    }

    $masterByName = [];
    $masterNames = [];
    $masterCodes = [];
    foreach ($masterBarangays as $record) {
        if (!is_array($record)) {
            throw new RuntimeException('Current PSGC master contains an invalid record.');
        }
        $name = trim((string) ($record['barangay_name'] ?? ''));
        $code = trim((string) ($record['current_psgc_10_digit'] ?? ''));
        if ($name === '' || preg_match('/^\d{10}$/', $code) !== 1) {
            throw new RuntimeException('Current PSGC master contains an invalid name or code.');
        }
        $normalized = normalizedName($name);
        $masterByName[$normalized] = $record;
        $masterNames[] = $normalized;
        $masterCodes[] = $code;
    }
    if (duplicateValues($masterNames) !== [] || duplicateValues($masterCodes) !== []) {
        throw new RuntimeException('Current PSGC master contains duplicate names or codes.');
    }
    if (isset($masterByName[normalizedName('Barangay 176')])) {
        throw new RuntimeException('Current PSGC master incorrectly contains retired Barangay 176.');
    }

    foreach (REPLACEMENT_BARANGAYS as $name => $code) {
        $record = $masterByName[normalizedName($name)] ?? null;
        if (!is_array($record)
            || ($record['current_psgc_10_digit'] ?? null) !== $code
            || ($record['geometry_status'] ?? null) !== 'PENDING_VALIDATED_SOURCE') {
            throw new RuntimeException($name . ' is missing or not marked as pending validated geometry in the current master.');
        }
    }

    $rows = [];
    $rowsByCode = [];
    $names = [];
    $codes = [];
    $featureBounds = [];

    foreach ($features as $index => $feature) {
        if (!is_array($feature) || ($feature['type'] ?? null) !== 'Feature') {
            throw new RuntimeException('Prepared GeoJSON feature ' . $index . ' is invalid.');
        }

        $properties = $feature['properties'] ?? null;
        $geometry = $feature['geometry'] ?? null;
        if (!is_array($properties) || !is_array($geometry)) {
            throw new RuntimeException('Prepared GeoJSON feature ' . $index . ' lacks properties or geometry.');
        }

        $name = trim((string) ($properties['current_barangay_name'] ?? ''));
        $code = trim((string) ($properties['current_psgc_10_digit'] ?? ''));
        $legacyCode = trim((string) ($properties['legacy_source_code'] ?? ''));
        if ($name === '' || preg_match('/^\d{10}$/', $code) !== 1 || $legacyCode === '') {
            throw new RuntimeException('Prepared feature ' . $index . ' lacks reviewed current or legacy metadata.');
        }
        if ($name === 'Barangay 176' || array_key_exists($name, REPLACEMENT_BARANGAYS)) {
            throw new RuntimeException('Excluded Barangay 176 geometry appeared in the import input: ' . $name . '.');
        }

        $masterRecord = $masterByName[normalizedName($name)] ?? null;
        if (!is_array($masterRecord)
            || ($masterRecord['barangay_name'] ?? null) !== $name
            || ($masterRecord['current_psgc_10_digit'] ?? null) !== $code
            || ($masterRecord['geometry_status'] ?? null) === 'PENDING_VALIDATED_SOURCE') {
            throw new RuntimeException('Prepared feature does not have exactly one approved PSA master match: ' . $name . '.');
        }

        $geometryType = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;
        if (!in_array($geometryType, ['Polygon', 'MultiPolygon'], true) || !is_array($coordinates)) {
            throw new RuntimeException($name . ' does not contain Polygon/MultiPolygon coordinates.');
        }
        $multiCoordinates = $geometryType === 'Polygon' ? [$coordinates] : $coordinates;
        validateControlledMultiPolygon($multiCoordinates, $name);

        $row = [
            'barangay_code' => $code,
            'name' => $name,
            'district_code' => null,
            'boundary_geometry' => multiPolygonEwkt($multiCoordinates),
            'multi_coordinates' => $multiCoordinates,
        ];
        $rows[] = $row;
        $rowsByCode[$code] = $row;
        $names[] = normalizedName($name);
        $codes[] = $code;
        $featureBounds[] = coordinateBounds($multiCoordinates);
    }

    if (count($rows) !== EXPECTED_FEATURE_COUNT
        || duplicateValues($names) !== []
        || duplicateValues($codes) !== []
        || count($rowsByCode) !== EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Prepared GeoJSON failed exact count/name/code uniqueness validation.');
    }

    $expectedNames = array_map(
        static fn (int $number): string => normalizedName('Barangay ' . $number),
        array_values(array_filter(range(1, 188), static fn (int $number): bool => $number !== 176))
    );
    $actualNames = $names;
    sort($expectedNames);
    sort($actualNames);
    if ($actualNames !== $expectedNames) {
        throw new RuntimeException('Prepared GeoJSON is not the exact unaffected Barangay 1-175 and 177-188 set.');
    }

    $cityFeatures = $cityBoundary['features'] ?? null;
    if (($cityBoundary['type'] ?? null) !== 'FeatureCollection' || !is_array($cityFeatures) || count($cityFeatures) !== 1) {
        throw new RuntimeException('Caloocan city-boundary verification file is invalid.');
    }
    $cityGeometry = $cityFeatures[0]['geometry'] ?? null;
    if (!is_array($cityGeometry) || !is_array($cityGeometry['coordinates'] ?? null)) {
        throw new RuntimeException('Caloocan city-boundary verification geometry is missing.');
    }
    $cityCoordinates = $cityGeometry['type'] === 'Polygon'
        ? [$cityGeometry['coordinates']]
        : $cityGeometry['coordinates'];
    $cityBounds = coordinateBounds($cityCoordinates);
    $dataBounds = mergeBounds($featureBounds);
    if (!boundsInside($dataBounds, $cityBounds)) {
        throw new RuntimeException('Prepared barangay extent is inconsistent with the reviewed Caloocan city boundary.');
    }

    return [
        'rows' => $rows,
        'rows_by_code' => $rowsByCode,
        'bounds' => $dataBounds,
        'city_bounds' => $cityBounds,
    ];
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

/** @return array<string, mixed> */
function sourcePayload(): array
{
    return [
        'organization_name' => GEOMETRY_SOURCE_ORGANIZATION,
        'organization_url' => GEOMETRY_SOURCE_ORGANIZATION_URL,
        'default_license' => GEOMETRY_SOURCE_LICENSE,
        'notes' => 'HDX dataset cod-ab-phl, resource ' . GEOMETRY_RESOURCE_ID . ' (' . GEOMETRY_RESOURCE_NAME . '). '
            . 'Published by OCHA FISS and prepared by OCHA. HDX attributes the underlying dataset source to NAMRIA and PSA. '
            . 'This record represents geometry provenance; current barangay names/codes are separately sourced from PSA PSGC.',
        'record_status' => 'ACTIVE',
    ];
}

/** @return array<string, mixed> */
function versionPayload(string $sourceId): array
{
    return [
        'dataset_source_id' => $sourceId,
        'dataset_category' => 'BARANGAY_BOUNDARY',
        'hazard_type_id' => null,
        'source_title' => DATASET_VERSION_TITLE,
        'source_reference' => GEOMETRY_DATASET_URL,
        'publication_date' => GEOMETRY_RESOURCE_DATE,
        'effective_from' => GEOMETRY_EFFECTIVE_FROM,
        'effective_to' => null,
        'version_label' => DATASET_VERSION_LABEL,
        'license' => GEOMETRY_SOURCE_LICENSE,
        'review_status' => 'DRAFT',
        'reviewed_by_civentral_user_id' => null,
        'reviewed_at' => null,
        'published_at' => null,
        'notes' => 'Incomplete staging subset: 187 unaffected current Caloocan barangays only. '
            . 'Old Barangay 176 and Barangays 176-A through 176-F are excluded because validated replacement polygons are unavailable. '
            . 'Geometry: HDX cod-ab-phl resource ' . GEOMETRY_RESOURCE_ID . ', source feature version v03, valid_on 2025-02-13. '
            . 'Current names/codes: PSA, ' . PSA_RELEASE_LABEL . ', ' . PSA_SOURCE_URL . '. '
            . 'Keep DRAFT and keep associated barangays INACTIVE until all 193 current boundaries are validated.',
    ];
}

function isUuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
}

/**
 * @param array<string, mixed> $prepared
 * @return array{
 *   source_record: array<string, mixed>|null,
 *   existing_barangays: list<array<string, mixed>>,
 *   hazard_types: list<array<string, mixed>>,
 *   risk_levels: list<array<string, mixed>>,
 *   counts: array<string, int>
 * }
 */
function databasePreflight(SupabaseRestClient $client, array $prepared): array
{
    $sources = $client->get('dataset_sources', [
        'select' => 'dataset_source_id,organization_name,organization_url,default_license,record_status',
        'order' => 'created_at.asc',
    ]);
    $versions = $client->get('dataset_versions', [
        'select' => 'dataset_version_id,dataset_source_id,dataset_category,source_title,source_reference,version_label,review_status',
        'order' => 'created_at.asc',
    ]);
    $barangays = $client->get('barangays', [
        'select' => 'barangay_id,barangay_code,name,boundary_dataset_version_id,record_status',
        'order' => 'barangay_code.asc',
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

    $sourceTemplate = sourcePayload();
    $exactSources = [];
    $partialSourceConflicts = [];
    foreach ($sources as $source) {
        $exact = ($source['organization_name'] ?? null) === $sourceTemplate['organization_name']
            && ($source['organization_url'] ?? null) === $sourceTemplate['organization_url']
            && ($source['default_license'] ?? null) === $sourceTemplate['default_license'];
        if ($exact) {
            $exactSources[] = $source;
        } elseif (($source['organization_name'] ?? null) === $sourceTemplate['organization_name']
            || ($source['organization_url'] ?? null) === $sourceTemplate['organization_url']) {
            $partialSourceConflicts[] = $source;
        }
    }
    if (count($exactSources) > 1 || $partialSourceConflicts !== []) {
        throw new RuntimeException('Existing dataset-source records conflict with the reviewed HDX provenance.');
    }
    $sourceRecord = $exactSources[0] ?? null;
    if ($sourceRecord !== null && ($sourceRecord['record_status'] ?? null) !== 'ACTIVE') {
        throw new RuntimeException('The matching geometry dataset source exists but is not ACTIVE.');
    }

    $matchingSourceId = $sourceRecord['dataset_source_id'] ?? null;
    foreach ($versions as $version) {
        $sameIdentity = ($version['source_title'] ?? null) === DATASET_VERSION_TITLE
            || ($version['source_reference'] ?? null) === GEOMETRY_DATASET_URL
                && ($version['version_label'] ?? null) === DATASET_VERSION_LABEL
            || ($matchingSourceId !== null
                && ($version['dataset_source_id'] ?? null) === $matchingSourceId
                && ($version['dataset_category'] ?? null) === 'BARANGAY_BOUNDARY'
                && ($version['version_label'] ?? null) === DATASET_VERSION_LABEL);
        if ($sameIdentity) {
            throw new RuntimeException('The intended draft dataset version already exists; import stopped to preserve idempotency.');
        }
    }

    $targetCodes = array_fill_keys(array_keys($prepared['rows_by_code']), true);
    $targetNames = [];
    foreach ($prepared['rows'] as $row) {
        $targetNames[normalizedName($row['name'])] = true;
    }

    $conflicts = [];
    foreach ($barangays as $barangay) {
        if (isset($targetCodes[(string) ($barangay['barangay_code'] ?? '')])
            || isset($targetNames[normalizedName((string) ($barangay['name'] ?? ''))])) {
            $conflicts[] = (string) ($barangay['barangay_code'] ?? $barangay['name'] ?? 'unknown');
        }
    }
    if ($conflicts !== []) {
        throw new RuntimeException('Existing barangay records conflict with the controlled import: ' . implode(', ', $conflicts));
    }

    return [
        'source_record' => $sourceRecord,
        'existing_barangays' => $barangays,
        'hazard_types' => $hazardTypes,
        'risk_levels' => $riskLevels,
        'counts' => [
            'dataset_sources' => count($sources),
            'dataset_versions' => count($versions),
            'barangays' => count($barangays),
        ],
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

/**
 * @param array<string, mixed> $prepared
 * @param list<array<string, mixed>> $preexistingBarangays
 * @return array<string, mixed>
 */
function verifyImport(
    SupabaseRestClient $client,
    string $datasetVersionId,
    array $prepared,
    array $preexistingBarangays = []
): array {
    if (!isUuid($datasetVersionId)) {
        throw new RuntimeException('Dataset version identifier is not a valid UUID.');
    }

    $versions = $client->get('dataset_versions', [
        'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,publication_date,effective_from,effective_to,version_label,license,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
        'dataset_version_id' => 'eq.' . $datasetVersionId,
    ]);
    if (count($versions) !== 1) {
        throw new RuntimeException('Expected exactly one imported dataset version.');
    }
    $version = $versions[0];
    assertFields($version, [
        'dataset_category' => 'BARANGAY_BOUNDARY',
        'hazard_type_id' => null,
        'source_title' => DATASET_VERSION_TITLE,
        'source_reference' => GEOMETRY_DATASET_URL,
        'publication_date' => GEOMETRY_RESOURCE_DATE,
        'effective_from' => GEOMETRY_EFFECTIVE_FROM,
        'effective_to' => null,
        'version_label' => DATASET_VERSION_LABEL,
        'license' => GEOMETRY_SOURCE_LICENSE,
        'review_status' => 'DRAFT',
        'reviewed_by_civentral_user_id' => null,
        'reviewed_at' => null,
        'published_at' => null,
    ], 'Dataset version');

    $sameVersions = $client->get('dataset_versions', [
        'select' => 'dataset_version_id',
        'source_title' => 'eq.' . DATASET_VERSION_TITLE,
        'version_label' => 'eq.' . DATASET_VERSION_LABEL,
    ]);
    if (count($sameVersions) !== 1 || ($sameVersions[0]['dataset_version_id'] ?? null) !== $datasetVersionId) {
        throw new RuntimeException('The controlled draft version is not unique.');
    }

    $sourceId = (string) ($version['dataset_source_id'] ?? '');
    $sources = $client->get('dataset_sources', [
        'select' => 'dataset_source_id,organization_name,organization_url,default_license,record_status',
        'dataset_source_id' => 'eq.' . $sourceId,
    ]);
    if (count($sources) !== 1) {
        throw new RuntimeException('Imported dataset version has no unique geometry source.');
    }
    assertFields($sources[0], [
        'organization_name' => GEOMETRY_SOURCE_ORGANIZATION,
        'organization_url' => GEOMETRY_SOURCE_ORGANIZATION_URL,
        'default_license' => GEOMETRY_SOURCE_LICENSE,
        'record_status' => 'ACTIVE',
    ], 'Dataset source');

    $barangays = $client->get('barangays', [
        'select' => 'barangay_id,barangay_code,name,district_code,boundary_geometry,boundary_dataset_version_id,record_status',
        'boundary_dataset_version_id' => 'eq.' . $datasetVersionId,
        'order' => 'barangay_code.asc',
    ]);
    if (count($barangays) !== EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Imported dataset version does not have exactly 187 barangays.');
    }

    $codes = [];
    $names = [];
    $returnedBounds = [];
    $validGeometryCount = 0;
    foreach ($barangays as $barangay) {
        $code = (string) ($barangay['barangay_code'] ?? '');
        $name = (string) ($barangay['name'] ?? '');
        $expected = $prepared['rows_by_code'][$code] ?? null;
        if (!is_array($expected) || $expected['name'] !== $name) {
            throw new RuntimeException('Supabase returned an unexpected imported barangay code/name.');
        }
        assertFields($barangay, [
            'district_code' => null,
            'boundary_dataset_version_id' => $datasetVersionId,
            'record_status' => 'INACTIVE',
        ], $name);
        if ($name === 'Barangay 176' || array_key_exists($name, REPLACEMENT_BARANGAYS)) {
            throw new RuntimeException('An excluded Barangay 176 record was imported.');
        }

        $geometry = returnedGeoJsonGeometry($barangay['boundary_geometry'] ?? null, $name);
        if ($geometry['type'] !== 'MultiPolygon') {
            throw new RuntimeException($name . ' was not stored as MultiPolygon.');
        }
        validateControlledMultiPolygon($geometry['coordinates'], $name . ' (Supabase)');
        if (!coordinatesEqual($expected['multi_coordinates'], $geometry['coordinates'])) {
            throw new RuntimeException($name . ' coordinates changed after the PostGIS round trip.');
        }

        $validGeometryCount++;
        $returnedBounds[] = coordinateBounds($geometry['coordinates']);
        $codes[] = $code;
        $names[] = normalizedName($name);
    }

    if (duplicateValues($codes) !== [] || duplicateValues($names) !== [] || count(array_unique($codes)) !== EXPECTED_FEATURE_COUNT) {
        throw new RuntimeException('Imported barangay code/name uniqueness validation failed.');
    }

    $returnedExtent = mergeBounds($returnedBounds);
    if (!boundsInside($returnedExtent, $prepared['city_bounds'])
        || !coordinatesEqual(array_values($prepared['bounds']), array_values($returnedExtent))) {
        throw new RuntimeException('Imported spatial extent changed or falls outside the reviewed Caloocan extent.');
    }

    $allBarangays = $client->get('barangays', [
        'select' => 'barangay_id,barangay_code,name,boundary_dataset_version_id,record_status',
        'order' => 'barangay_code.asc',
    ]);
    $allById = [];
    foreach ($allBarangays as $record) {
        $allById[(string) $record['barangay_id']] = $record;
    }
    foreach ($preexistingBarangays as $record) {
        $id = (string) ($record['barangay_id'] ?? '');
        if ($id === '' || !isset($allById[$id]) || $allById[$id] !== $record) {
            throw new RuntimeException('A pre-existing unrelated barangay record changed during import.');
        }
    }

    $hazardTypes = $client->get('hazard_types', [
        'select' => 'hazard_type_id,code,name',
        'order' => 'hazard_type_id.asc',
    ]);
    $riskLevels = $client->get('risk_levels', [
        'select' => 'risk_level_id,code,name,severity_rank',
        'order' => 'risk_level_id.asc',
    ]);
    if ($hazardTypes !== expectedHazardTypes() || $riskLevels !== expectedRiskLevels()) {
        throw new RuntimeException('Controlled hazard/risk lookup records changed during import.');
    }

    $mapService = new DrrmMapReadService($client);
    $activeBarangays = $mapService->barangays();
    if ($activeBarangays !== []) {
        throw new RuntimeException('The read-only DRRM map service exposed barangays after the draft import.');
    }

    return [
        'dataset_source_id' => $sourceId,
        'dataset_version_id' => $datasetVersionId,
        'dataset_review_status' => 'DRAFT',
        'barangay_count' => count($barangays),
        'barangay_record_status' => 'INACTIVE',
        'unique_codes' => count(array_unique($codes)),
        'unique_names' => count(array_unique($names)),
        'geometry_type' => 'MultiPolygon',
        'geometry_srid' => 4326,
        'non_empty_valid_geometries' => $validGeometryCount,
        'old_barangay_176_present' => false,
        'replacement_barangays_present' => false,
        'active_map_api_barangays' => count($activeBarangays),
        'spatial_extent' => $returnedExtent,
        'spatial_extent_consistent_with_caloocan' => true,
        'hazard_types_unchanged' => true,
        'risk_levels_unchanged' => true,
        'unrelated_barangays_unchanged' => true,
    ];
}

/** @return array{complete: bool, deleted_barangays: int, deleted_version: bool, deleted_source: bool, errors: list<string>} */
function rollbackImport(
    SupabaseRestClient $client,
    ?string $datasetVersionId,
    ?string $datasetSourceId,
    bool $sourceCreated
): array {
    $result = [
        'complete' => true,
        'deleted_barangays' => 0,
        'deleted_version' => false,
        'deleted_source' => false,
        'errors' => [],
    ];

    if ($datasetVersionId !== null) {
        try {
            $rows = $client->get('barangays', [
                'select' => 'barangay_id,barangay_code,record_status',
                'boundary_dataset_version_id' => 'eq.' . $datasetVersionId,
            ]);
            foreach ($rows as $row) {
                if (($row['record_status'] ?? null) !== 'INACTIVE') {
                    throw new RuntimeException('Rollback refused to delete a non-INACTIVE barangay.');
                }
            }

            if ($rows !== []) {
                $deleted = $client->delete('barangays', [
                    'boundary_dataset_version_id' => 'eq.' . $datasetVersionId,
                    'select' => 'barangay_id,barangay_code',
                ]);
                $result['deleted_barangays'] = count($deleted);
            }

            $remaining = $client->get('barangays', [
                'select' => 'barangay_id',
                'boundary_dataset_version_id' => 'eq.' . $datasetVersionId,
            ]);
            if ($remaining !== []) {
                throw new RuntimeException('Rollback could not remove all version-scoped barangays.');
            }

            $deletedVersions = $client->delete('dataset_versions', [
                'dataset_version_id' => 'eq.' . $datasetVersionId,
                'select' => 'dataset_version_id',
            ]);
            $result['deleted_version'] = count($deletedVersions) === 1;
            if (!$result['deleted_version']) {
                throw new RuntimeException('Rollback did not delete the exact draft dataset version.');
            }
        } catch (Throwable $exception) {
            $result['complete'] = false;
            $result['errors'][] = $exception->getMessage();
        }
    }

    if ($sourceCreated && $datasetSourceId !== null && $result['deleted_version']) {
        try {
            $remainingVersions = $client->get('dataset_versions', [
                'select' => 'dataset_version_id',
                'dataset_source_id' => 'eq.' . $datasetSourceId,
            ]);
            if ($remainingVersions === []) {
                $deletedSources = $client->delete('dataset_sources', [
                    'dataset_source_id' => 'eq.' . $datasetSourceId,
                    'select' => 'dataset_source_id',
                ]);
                $result['deleted_source'] = count($deletedSources) === 1;
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

    throw new InvalidArgumentException('Unsupported mode. Use --preflight, --execute, or --verify=<dataset-version-uuid>.');
}

try {
    $mode = parseMode($argv);
    $prepared = loadAndValidatePreparedData();
    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
    $client = new SupabaseRestClient($config, 5, 60);

    if ($mode['mode'] === 'verify') {
        $verification = verifyImport($client, $mode['dataset_version_id'], $prepared);
        echo json_encode(['success' => true, 'verification' => $verification], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }

    $preflight = databasePreflight($client, $prepared);
    $preflightSummary = [
        'local_features' => count($prepared['rows']),
        'local_geometry_type' => 'Polygon converted only by MultiPolygon wrapper',
        'source_extent' => $prepared['bounds'],
        'extent_consistent_with_caloocan' => true,
        'database_counts_before' => $preflight['counts'],
        'dataset_source_action' => $preflight['source_record'] === null ? 'CREATE' : 'REUSE',
        'dataset_version_action' => 'CREATE_DRAFT',
        'barangay_action' => 'INSERT_187_INACTIVE',
        'batch_size' => INSERT_BATCH_SIZE,
        'rollback_scope' => 'Only records tied to UUIDs created by this execution',
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
                throw new RuntimeException('Supabase did not return one created dataset source.');
            }
            assertFields($createdSources[0], [
                'organization_name' => GEOMETRY_SOURCE_ORGANIZATION,
                'organization_url' => GEOMETRY_SOURCE_ORGANIZATION_URL,
                'default_license' => GEOMETRY_SOURCE_LICENSE,
                'record_status' => 'ACTIVE',
            ], 'Created dataset source');
            $datasetSourceId = (string) $createdSources[0]['dataset_source_id'];
            $sourceCreated = true;
        }

        $createdVersions = $client->post('dataset_versions', versionPayload($datasetSourceId), [
            'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,publication_date,effective_from,effective_to,version_label,license,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
        ]);
        if (count($createdVersions) !== 1 || !isUuid((string) ($createdVersions[0]['dataset_version_id'] ?? ''))) {
            throw new RuntimeException('Supabase did not return one created draft dataset version.');
        }
        assertFields($createdVersions[0], [
            'dataset_source_id' => $datasetSourceId,
            'dataset_category' => 'BARANGAY_BOUNDARY',
            'hazard_type_id' => null,
            'review_status' => 'DRAFT',
            'reviewed_by_civentral_user_id' => null,
            'reviewed_at' => null,
            'published_at' => null,
        ], 'Created dataset version');
        $datasetVersionId = (string) $createdVersions[0]['dataset_version_id'];

        $insertedCount = 0;
        foreach (array_chunk($prepared['rows'], INSERT_BATCH_SIZE) as $batchIndex => $batch) {
            $payload = [];
            foreach ($batch as $row) {
                $payload[] = [
                    'barangay_code' => $row['barangay_code'],
                    'name' => $row['name'],
                    'district_code' => null,
                    'boundary_geometry' => $row['boundary_geometry'],
                    'boundary_dataset_version_id' => $datasetVersionId,
                    'record_status' => 'INACTIVE',
                ];
            }

            $inserted = $client->post('barangays', $payload, [
                'select' => 'barangay_id,barangay_code,name,boundary_dataset_version_id,record_status',
            ]);
            if (count($inserted) !== count($payload)) {
                throw new RuntimeException('Barangay batch ' . ($batchIndex + 1) . ' returned an unexpected record count.');
            }
            foreach ($inserted as $record) {
                if (($record['boundary_dataset_version_id'] ?? null) !== $datasetVersionId
                    || ($record['record_status'] ?? null) !== 'INACTIVE'
                    || !isset($prepared['rows_by_code'][(string) ($record['barangay_code'] ?? '')])) {
                    throw new RuntimeException('Barangay batch ' . ($batchIndex + 1) . ' returned an unsafe record.');
                }
            }
            $insertedCount += count($inserted);
            echo 'Imported inactive barangays: ' . $insertedCount . '/' . EXPECTED_FEATURE_COUNT . PHP_EOL;
        }

        if ($insertedCount !== EXPECTED_FEATURE_COUNT) {
            throw new RuntimeException('Controlled import did not insert exactly 187 barangays.');
        }

        $verification = verifyImport(
            $client,
            $datasetVersionId,
            $prepared,
            $preflight['existing_barangays']
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
        fwrite(STDERR, 'Import failed safely: ' . $importException->getMessage() . PHP_EOL);
        fwrite(STDERR, json_encode([
            'dataset_source_id' => $datasetSourceId,
            'dataset_version_id' => $datasetVersionId,
            'rollback' => $rollback,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Caloocan draft import stopped: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

