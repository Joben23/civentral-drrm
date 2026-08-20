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

const FAULT_GEOJSON_INPUT = __DIR__ . '/../data/import/caloocan-nearby-phivolcs-active-faults.geojson';
const FAULT_REPORT_INPUT = __DIR__ . '/../data/import/caloocan-phivolcs-active-fault-report.json';
const CITY_BOUNDARY_INPUT = __DIR__ . '/../data/import/caloocan-city-boundary.geojson';
const EXPECTED_GEOJSON_SHA256 = 'F2B8DBBD4D6F60E3DE3BBD08A0328521030EE79318C187343CA11B8EF5532D7D';
const EXPECTED_REPORT_SHA256 = 'C1C8189FB4A72C3FACA32F1CEB43123641F221DC3D89FD327CA9C7BA279FF4EB';
const EXPECTED_CITY_SHA256 = '9647F3CAC1758A07CFDC6A5BB8767FE9E4F1EB70B4E7D2C14A99ABF2DE1F9D50';
const EXPECTED_SOURCE_FEATURE_COUNT = 254;
const EXPECTED_IMPORT_COUNT = 156;
const PROXIMITY_LIMIT_METERS = 10000.0;
const INSERT_BATCH_SIZE = 8;
const EARTH_RADIUS_METERS = 6371008.8;
const HAZARDHUNTER_LONGITUDE = 120.98951;
const HAZARDHUNTER_LATITUDE = 14.64953;
const EXPECTED_HAZARDHUNTER_DISTANCE_METERS = 9795.037962566026;
const EXPECTED_CITY_DISTANCE_METERS = 3758.382259782223;
const CITY_NEAREST_SOURCE_OBJECT_ID = 2438;
const HAZARDHUNTER_NEAREST_SOURCE_OBJECT_ID = 2577;
const HAZARD_TYPE_ID = 3;
const SOURCE_AGENCY = 'DOST-PHIVOLCS';
const SOURCE_ORGANIZATION = 'Department of Science and Technology - Philippine Institute of Volcanology and Seismology (DOST-PHIVOLCS)';
const SOURCE_ORGANIZATION_URL = 'https://www.phivolcs.dost.gov.ph/';
const SOURCE_SERVICE_URL = 'https://gisweb.phivolcs.dost.gov.ph/arcgis/rest/services/PHIVOLCS/ActiveFault/MapServer';
const SOURCE_VECTOR_ENDPOINT = 'https://gisweb.phivolcs.dost.gov.ph/arcgis/services/PHIVOLCS/ActiveFault/MapServer/KmlServer';
const DATASET_VERSION_TITLE = 'DOST-PHIVOLCS Active Faults - West Valley Fault near Caloocan draft';
const DATASET_VERSION_LABEL = 'dost-phivolcs-active-fault-west-valley-caloocan-10km-retrieved-2026-08-19-draft';

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

function assertFileHash(string $path, string $expected): void
{
    $actual = hash_file('sha256', $path);
    if ($actual === false || strtoupper($actual) !== $expected) {
        throw new RuntimeException(basename($path) . ' does not match the reviewed SHA-256.');
    }
}

function isUuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
}

function coordinateNumber(int|float $number): string
{
    try {
        return json_encode($number, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('A geometry coordinate could not be encoded safely.');
    }
}

/** @param array<mixed> $multiLineCoordinates */
function multiLineStringEwkt(array $multiLineCoordinates): string
{
    $lines = [];
    foreach ($multiLineCoordinates as $line) {
        $positions = [];
        foreach ($line as $position) {
            $positions[] = coordinateNumber($position[0]) . ' ' . coordinateNumber($position[1]);
        }
        $lines[] = '(' . implode(',', $positions) . ')';
    }
    return 'SRID=4326;MULTILINESTRING(' . implode(',', $lines) . ')';
}

/**
 * @param array<string, mixed> $geometry
 * @return array<mixed> MultiLineString coordinates
 */
function validateAndWrapLineGeometry(array $geometry, string $label): array
{
    $type = $geometry['type'] ?? null;
    $coordinates = $geometry['coordinates'] ?? null;
    if (!in_array($type, ['LineString', 'MultiLineString'], true) || !is_array($coordinates)) {
        throw new RuntimeException($label . ' is not a LineString or MultiLineString.');
    }

    $multiLine = $type === 'LineString' ? [$coordinates] : $coordinates;
    if (!array_is_list($multiLine) || $multiLine === []) {
        throw new RuntimeException($label . ' has empty geometry.');
    }

    foreach ($multiLine as $lineIndex => $line) {
        if (!is_array($line) || !array_is_list($line) || count($line) < 2) {
            throw new RuntimeException($label . ' has an invalid line at index ' . $lineIndex . '.');
        }
        $distinct = [];
        foreach ($line as $position) {
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
            $distinct[coordinateNumber($longitude) . ',' . coordinateNumber($latitude)] = true;
        }
        if (count($distinct) < 2) {
            throw new RuntimeException($label . ' does not contain two distinct positions.');
        }
    }

    return $multiLine;
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
            if (!is_array($leftValue) || !is_array($rightValue)
                || !coordinatesEqual($leftValue, $rightValue, $epsilon)) {
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
        try {
            $geometry = json_decode($geometry, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException($label . ' was not returned as GeoJSON-compatible geometry.');
        }
    }
    if (!is_array($geometry) || !is_string($geometry['type'] ?? null)
        || !is_array($geometry['coordinates'] ?? null)) {
        throw new RuntimeException($label . ' was not returned as GeoJSON-compatible geometry.');
    }
    return ['type' => $geometry['type'], 'coordinates' => $geometry['coordinates']];
}

/** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b */
function haversineMeters(array $a, array $b): float
{
    $lat1 = deg2rad($a[1]);
    $lat2 = deg2rad($b[1]);
    $deltaLatitude = $lat2 - $lat1;
    $deltaLongitude = deg2rad($b[0] - $a[0]);
    $sinLat = sin($deltaLatitude / 2.0);
    $sinLon = sin($deltaLongitude / 2.0);
    $h = ($sinLat * $sinLat) + cos($lat1) * cos($lat2) * ($sinLon * $sinLon);
    return EARTH_RADIUS_METERS * 2.0 * atan2(sqrt($h), sqrt(max(0.0, 1.0 - $h)));
}

/**
 * @param array{0: float, 1: float} $point
 * @param array{0: float, 1: float} $start
 * @param array{0: float, 1: float} $end
 */
function pointToSegmentDistanceMeters(array $point, array $start, array $end): float
{
    $referenceLatitude = deg2rad(($point[1] + $start[1] + $end[1]) / 3.0);
    $scaleX = EARTH_RADIUS_METERS * cos($referenceLatitude);
    $px = deg2rad($point[0]) * $scaleX;
    $py = deg2rad($point[1]) * EARTH_RADIUS_METERS;
    $sx = deg2rad($start[0]) * $scaleX;
    $sy = deg2rad($start[1]) * EARTH_RADIUS_METERS;
    $ex = deg2rad($end[0]) * $scaleX;
    $ey = deg2rad($end[1]) * EARTH_RADIUS_METERS;
    $dx = $ex - $sx;
    $dy = $ey - $sy;
    $lengthSquared = ($dx * $dx) + ($dy * $dy);
    $t = $lengthSquared > 0
        ? (($px - $sx) * $dx + ($py - $sy) * $dy) / $lengthSquared
        : 0.0;
    $t = max(0.0, min(1.0, $t));
    $nearest = [
        $start[0] + ($t * ($end[0] - $start[0])),
        $start[1] + ($t * ($end[1] - $start[1])),
    ];
    return haversineMeters($point, $nearest);
}

/** @param array<int, array<int, array{0: float, 1: float}>> $multiLine */
function pointToMultiLineDistanceMeters(array $point, array $multiLine): float
{
    $minimum = INF;
    foreach ($multiLine as $line) {
        for ($index = 1, $count = count($line); $index < $count; $index++) {
            $minimum = min($minimum, pointToSegmentDistanceMeters($point, $line[$index - 1], $line[$index]));
        }
    }
    return $minimum;
}

/** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b @param array{0: float, 1: float} $c */
function orientation(array $a, array $b, array $c): float
{
    return (($b[0] - $a[0]) * ($c[1] - $a[1])) - (($b[1] - $a[1]) * ($c[0] - $a[0]));
}

/** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b @param array{0: float, 1: float} $c */
function pointOnSegment(array $a, array $b, array $c): bool
{
    $epsilon = 1.0E-12;
    return abs(orientation($a, $b, $c)) <= $epsilon
        && $b[0] >= min($a[0], $c[0]) - $epsilon && $b[0] <= max($a[0], $c[0]) + $epsilon
        && $b[1] >= min($a[1], $c[1]) - $epsilon && $b[1] <= max($a[1], $c[1]) + $epsilon;
}

/** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b @param array{0: float, 1: float} $c @param array{0: float, 1: float} $d */
function segmentsIntersect(array $a, array $b, array $c, array $d): bool
{
    $o1 = orientation($a, $b, $c);
    $o2 = orientation($a, $b, $d);
    $o3 = orientation($c, $d, $a);
    $o4 = orientation($c, $d, $b);
    $epsilon = 1.0E-12;
    if ((($o1 > $epsilon && $o2 < -$epsilon) || ($o1 < -$epsilon && $o2 > $epsilon))
        && (($o3 > $epsilon && $o4 < -$epsilon) || ($o3 < -$epsilon && $o4 > $epsilon))) {
        return true;
    }
    return (abs($o1) <= $epsilon && pointOnSegment($a, $c, $b))
        || (abs($o2) <= $epsilon && pointOnSegment($a, $d, $b))
        || (abs($o3) <= $epsilon && pointOnSegment($c, $a, $d))
        || (abs($o4) <= $epsilon && pointOnSegment($c, $b, $d));
}

/** @param array{0: float, 1: float} $point @param array<int, array{0: float, 1: float}> $ring */
function pointInRing(array $point, array $ring): bool
{
    $inside = false;
    for ($index = 0, $previous = count($ring) - 1, $count = count($ring); $index < $count; $previous = $index++) {
        if (pointOnSegment($ring[$previous], $point, $ring[$index])) {
            return true;
        }
        if (($ring[$index][1] > $point[1]) !== ($ring[$previous][1] > $point[1])) {
            $crossingLongitude = ($ring[$previous][0] - $ring[$index][0])
                * ($point[1] - $ring[$index][1])
                / ($ring[$previous][1] - $ring[$index][1]) + $ring[$index][0];
            if ($point[0] < $crossingLongitude) {
                $inside = !$inside;
            }
        }
    }
    return $inside;
}

/** @param array{0: float, 1: float} $point @param array<int, mixed> $multiPolygon */
function pointInMultiPolygon(array $point, array $multiPolygon): bool
{
    foreach ($multiPolygon as $polygon) {
        if (!pointInRing($point, $polygon[0])) {
            continue;
        }
        $insideHole = false;
        for ($index = 1, $count = count($polygon); $index < $count; $index++) {
            if (pointInRing($point, $polygon[$index])) {
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

/** @param array<int, array<int, array{0: float, 1: float}>> $multiLine @param array<int, mixed> $multiPolygon */
function multiLineIntersectsMultiPolygon(array $multiLine, array $multiPolygon): bool
{
    $rings = [];
    foreach ($multiPolygon as $polygon) {
        foreach ($polygon as $ring) {
            $rings[] = $ring;
        }
    }
    foreach ($multiLine as $line) {
        foreach ($line as $point) {
            if (pointInMultiPolygon($point, $multiPolygon)) {
                return true;
            }
        }
        for ($lineIndex = 1, $lineCount = count($line); $lineIndex < $lineCount; $lineIndex++) {
            foreach ($rings as $ring) {
                for ($ringIndex = 1, $ringCount = count($ring); $ringIndex < $ringCount; $ringIndex++) {
                    if (segmentsIntersect($line[$lineIndex - 1], $line[$lineIndex], $ring[$ringIndex - 1], $ring[$ringIndex])) {
                        return true;
                    }
                }
            }
        }
    }
    return false;
}

/** @return array<string, mixed> */
function loadAndValidatePreparedData(): array
{
    assertFileHash(FAULT_GEOJSON_INPUT, EXPECTED_GEOJSON_SHA256);
    assertFileHash(FAULT_REPORT_INPUT, EXPECTED_REPORT_SHA256);
    assertFileHash(CITY_BOUNDARY_INPUT, EXPECTED_CITY_SHA256);
    $geoJson = readJsonObject(FAULT_GEOJSON_INPUT);
    $report = readJsonObject(FAULT_REPORT_INPUT);
    $city = readJsonObject(CITY_BOUNDARY_INPUT);

    if (($geoJson['type'] ?? null) !== 'FeatureCollection'
        || !is_array($geoJson['features'] ?? null)
        || count($geoJson['features']) !== EXPECTED_SOURCE_FEATURE_COUNT) {
        throw new RuntimeException('Prepared PHIVOLCS GeoJSON does not contain the reviewed 254 features.');
    }
    if (($report['official_source']['agency'] ?? null) !== SOURCE_AGENCY
        || ($report['official_source']['mapserver_url'] ?? null) !== SOURCE_SERVICE_URL
        || ($report['caloocan_intersection']['active_fault_intersects_caloocan'] ?? null) !== false
        || abs((float) ($report['caloocan_intersection']['nearest_active_fault']['distance_meters'] ?? INF) - EXPECTED_CITY_DISTANCE_METERS) > 0.01
        || abs((float) ($report['hazardhunter_independent_check']['calculated_nearest_active_fault']['distance_meters'] ?? INF) - EXPECTED_HAZARDHUNTER_DISTANCE_METERS) > 0.01) {
        throw new RuntimeException('Prepared PHIVOLCS report differs from the reviewed validation findings.');
    }
    $cityGeometry = $city['features'][0]['geometry'] ?? null;
    if (!is_array($cityGeometry) || ($cityGeometry['type'] ?? null) !== 'MultiPolygon'
        || !is_array($cityGeometry['coordinates'] ?? null)) {
        throw new RuntimeException('Validated Caloocan city boundary is unavailable.');
    }

    $rows = [];
    $rowsByObjectId = [];
    foreach ($geoJson['features'] as $feature) {
        $properties = is_array($feature) ? ($feature['properties'] ?? null) : null;
        $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;
        if (!is_array($properties) || !is_array($geometry)
            || ($properties['official_fault_name'] ?? null) !== 'West Valley Fault'
            || !is_numeric($properties['minimum_distance_to_caloocan_meters'] ?? null)
            || (float) $properties['minimum_distance_to_caloocan_meters'] > PROXIMITY_LIMIT_METERS) {
            continue;
        }
        $objectId = filter_var($properties['source_object_id'] ?? null, FILTER_VALIDATE_INT);
        if ($objectId === false || $objectId < 1 || isset($rowsByObjectId[$objectId])
            || ($properties['official_fault_system'] ?? null) !== 'Valley Fault System'
            || ($properties['feature_class'] ?? null) !== 'Active Fault'
            || ($properties['source_agency'] ?? null) !== SOURCE_AGENCY
            || ($properties['source_service'] ?? null) !== SOURCE_SERVICE_URL
            || ($properties['source_vector_endpoint'] ?? null) !== SOURCE_VECTOR_ENDPOINT
            || ($properties['intersects_caloocan'] ?? null) !== false) {
            throw new RuntimeException('A selected West Valley Fault feature has inconsistent source metadata.');
        }

        $multiCoordinates = validateAndWrapLineGeometry($geometry, 'PHIVOLCS OBJECTID ' . $objectId);
        if (multiLineIntersectsMultiPolygon($multiCoordinates, $cityGeometry['coordinates'])) {
            throw new RuntimeException('A selected West Valley Fault feature intersects Caloocan unexpectedly.');
        }
        $notes = [
            'source_agency' => SOURCE_AGENCY,
            'source_service_reference' => SOURCE_SERVICE_URL,
            'source_vector_endpoint' => SOURCE_VECTOR_ENDPOINT,
            'source_object_id' => $objectId,
            'source_active_fault_id' => $properties['source_active_fault_id'] ?? null,
            'source_global_id' => $properties['source_global_id'] ?? null,
            'official_fault_system' => 'Valley Fault System',
            'official_segment_name' => $properties['official_segment_name'] ?? null,
            'trace_type' => $properties['trace_type'] ?? null,
            'line_type' => $properties['line_type'] ?? null,
            'mapped_year' => $properties['mapped_year'] ?? null,
            'mapping_scale' => $properties['mapping_scale'] ?? null,
            'minimum_distance_to_caloocan_meters' => (float) $properties['minimum_distance_to_caloocan_meters'],
            'intersects_caloocan' => false,
            'presentation_subset_rule' => 'Official West Valley Fault source segments within 10 km of the Caloocan boundary',
        ];
        $row = [
            'source_object_id' => $objectId,
            'feature_class' => 'Active Fault',
            'multi_coordinates' => $multiCoordinates,
            'geometry_ewkt' => multiLineStringEwkt($multiCoordinates),
            'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'minimum_city_distance_meters' => (float) $properties['minimum_distance_to_caloocan_meters'],
        ];
        $rows[] = $row;
        $rowsByObjectId[$objectId] = $row;
    }

    if (count($rows) !== EXPECTED_IMPORT_COUNT
        || !isset($rowsByObjectId[CITY_NEAREST_SOURCE_OBJECT_ID])
        || !isset($rowsByObjectId[HAZARDHUNTER_NEAREST_SOURCE_OBJECT_ID])) {
        throw new RuntimeException('The 10 km West Valley Fault subset does not match the reviewed 156-feature selection.');
    }
    usort($rows, static fn (array $left, array $right): int => $left['source_object_id'] <=> $right['source_object_id']);

    $minimumHazardHunterDistance = INF;
    $nearestHazardHunterObjectId = null;
    foreach ($rows as $row) {
        $distance = pointToMultiLineDistanceMeters(
            [HAZARDHUNTER_LONGITUDE, HAZARDHUNTER_LATITUDE],
            $row['multi_coordinates']
        );
        if ($distance < $minimumHazardHunterDistance) {
            $minimumHazardHunterDistance = $distance;
            $nearestHazardHunterObjectId = $row['source_object_id'];
        }
    }
    if ($nearestHazardHunterObjectId !== HAZARDHUNTER_NEAREST_SOURCE_OBJECT_ID
        || abs($minimumHazardHunterDistance - EXPECTED_HAZARDHUNTER_DISTANCE_METERS) > 0.01) {
        throw new RuntimeException('The reduced subset does not preserve the reviewed HazardHunter point result.');
    }

    return [
        'rows' => $rows,
        'rows_by_object_id' => $rowsByObjectId,
        'city_coordinates' => $cityGeometry['coordinates'],
        'minimum_hazardhunter_distance_meters' => $minimumHazardHunterDistance,
        'minimum_city_distance_meters' => min(array_column($rows, 'minimum_city_distance_meters')),
    ];
}

/** @return array<string, mixed> */
function sourcePayload(): array
{
    return [
        'organization_name' => SOURCE_ORGANIZATION,
        'organization_url' => SOURCE_ORGANIZATION_URL,
        'default_license' => null,
        'notes' => 'Official active-fault geometry source: ' . SOURCE_SERVICE_URL . '. Vector geometry retrieved from the same ArcGIS service through ' . SOURCE_VECTOR_ENDPOINT . '. No source license was stated in the reviewed service metadata.',
        'record_status' => 'ACTIVE',
    ];
}

/** @return array<string, mixed> */
function versionPayload(string $sourceId): array
{
    return [
        'dataset_source_id' => $sourceId,
        'dataset_category' => 'FAULT_FEATURE',
        'hazard_type_id' => HAZARD_TYPE_ID,
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
        'notes' => 'Controlled DRAFT presentation subset of 156 official West Valley Fault source segments within 10 km of the validated Caloocan boundary. No mapped active fault intersects Caloocan. Nearest boundary distance is approximately 3.758 km. Input GeoJSON SHA-256: ' . EXPECTED_GEOJSON_SHA256 . '. Source LineStrings are wrapped as MultiLineString without changing coordinates. Keep DRAFT and associated fault features INACTIVE until reviewed.',
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
    $hazardTypes = $client->get('hazard_types', [
        'select' => 'hazard_type_id,code,name,is_active',
        'hazard_type_id' => 'eq.' . HAZARD_TYPE_ID,
        'limit' => 2,
    ]);
    if (count($hazardTypes) !== 1
        || ($hazardTypes[0]['code'] ?? null) !== 'EARTHQUAKE_FAULT'
        || ($hazardTypes[0]['name'] ?? null) !== 'Earthquake/Fault'
        || ($hazardTypes[0]['is_active'] ?? null) !== true) {
        throw new RuntimeException('EARTHQUAKE_FAULT lookup does not match the approved seed state.');
    }

    $sources = $client->get('dataset_sources', [
        'select' => 'dataset_source_id,organization_name,organization_url,default_license,notes,record_status',
        'order' => 'created_at.asc',
    ]);
    $versions = $client->get('dataset_versions', [
        'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,version_label,review_status',
        'order' => 'created_at.asc',
    ]);
    $faults = $client->get('fault_features', [
        'select' => 'fault_feature_id,hazard_type_id,dataset_version_id,feature_name,feature_class,notes,record_status',
        'order' => 'created_at.asc',
    ]);

    $exactSources = [];
    $partialConflicts = [];
    foreach ($sources as $source) {
        $exact = ($source['organization_name'] ?? null) === SOURCE_ORGANIZATION
            && ($source['organization_url'] ?? null) === SOURCE_ORGANIZATION_URL
            && ($source['default_license'] ?? null) === null;
        if ($exact) {
            $exactSources[] = $source;
        } elseif (($source['organization_name'] ?? null) === SOURCE_ORGANIZATION
            || ($source['organization_url'] ?? null) === SOURCE_ORGANIZATION_URL) {
            $partialConflicts[] = $source;
        }
    }
    if (count($exactSources) > 1 || $partialConflicts !== []) {
        throw new RuntimeException('Existing dataset-source records conflict with the reviewed DOST-PHIVOLCS provenance.');
    }
    $sourceRecord = $exactSources[0] ?? null;
    if ($sourceRecord !== null && ($sourceRecord['record_status'] ?? null) !== 'ACTIVE') {
        throw new RuntimeException('The matching DOST-PHIVOLCS source exists but is not ACTIVE.');
    }

    $matchingSourceId = $sourceRecord['dataset_source_id'] ?? null;
    foreach ($versions as $version) {
        $sameIdentity = ($version['source_title'] ?? null) === DATASET_VERSION_TITLE
            || (($version['source_reference'] ?? null) === SOURCE_SERVICE_URL
                && ($version['version_label'] ?? null) === DATASET_VERSION_LABEL)
            || ($matchingSourceId !== null
                && ($version['dataset_source_id'] ?? null) === $matchingSourceId
                && ($version['dataset_category'] ?? null) === 'FAULT_FEATURE'
                && ($version['hazard_type_id'] ?? null) === HAZARD_TYPE_ID
                && ($version['version_label'] ?? null) === DATASET_VERSION_LABEL);
        if ($sameIdentity) {
            throw new RuntimeException('The intended DOST-PHIVOLCS fault draft dataset version already exists; import stopped to preserve idempotency.');
        }
    }

    $targetIds = array_fill_keys(array_map('strval', array_keys($prepared['rows_by_object_id'])), true);
    $conflicts = [];
    foreach ($faults as $fault) {
        $notes = json_decode((string) ($fault['notes'] ?? ''), true);
        if (!is_array($notes) || ($notes['source_service_reference'] ?? null) !== SOURCE_SERVICE_URL) {
            continue;
        }
        $objectId = (string) ($notes['source_object_id'] ?? '');
        if (isset($targetIds[$objectId])) {
            $conflicts[] = $objectId;
        }
    }
    if ($conflicts !== []) {
        throw new RuntimeException('PHIVOLCS source OBJECTIDs already exist in fault_features: ' . implode(', ', $conflicts));
    }

    return [
        'source_record' => $sourceRecord,
        'existing_sources' => $sources,
        'existing_versions' => $versions,
        'existing_faults' => $faults,
        'counts' => [
            'dataset_sources' => count($sources),
            'dataset_versions' => count($versions),
            'fault_features' => count($faults),
        ],
    ];
}

/** @return array<string, mixed> */
function verifyImport(
    SupabaseRestClient $client,
    string $datasetVersionId,
    array $prepared,
    array $preexistingSources = [],
    array $preexistingVersions = [],
    array $preexistingFaults = []
): array {
    $versions = $client->get('dataset_versions', [
        'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,publication_date,effective_from,effective_to,version_label,license,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
        'dataset_version_id' => 'eq.' . $datasetVersionId,
    ]);
    if (count($versions) !== 1) {
        throw new RuntimeException('Imported fault dataset version is not uniquely available.');
    }
    assertFields($versions[0], [
        'dataset_category' => 'FAULT_FEATURE',
        'hazard_type_id' => HAZARD_TYPE_ID,
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
    ], 'Fault dataset version');

    $sourceId = (string) ($versions[0]['dataset_source_id'] ?? '');
    $sources = $client->get('dataset_sources', [
        'select' => 'dataset_source_id,organization_name,organization_url,default_license,record_status',
        'dataset_source_id' => 'eq.' . $sourceId,
    ]);
    if (count($sources) !== 1) {
        throw new RuntimeException('Fault dataset version has no unique DOST-PHIVOLCS source.');
    }
    assertFields($sources[0], [
        'organization_name' => SOURCE_ORGANIZATION,
        'organization_url' => SOURCE_ORGANIZATION_URL,
        'default_license' => null,
        'record_status' => 'ACTIVE',
    ], 'Fault dataset source');

    $faults = $client->get('fault_features', [
        'select' => 'fault_feature_id,hazard_type_id,dataset_version_id,feature_name,feature_class,geometry,effective_from,effective_to,notes,record_status',
        'dataset_version_id' => 'eq.' . $datasetVersionId,
        'order' => 'created_at.asc',
        'limit' => EXPECTED_IMPORT_COUNT + 1,
    ]);
    if (count($faults) !== EXPECTED_IMPORT_COUNT) {
        throw new RuntimeException('Imported fault dataset does not contain exactly 156 fault features.');
    }

    $seenIds = [];
    $geometryCount = 0;
    $intersectionCount = 0;
    $minimumCityDistance = INF;
    $minimumHazardHunterDistance = INF;
    $nearestHazardHunterObjectId = null;
    foreach ($faults as $fault) {
        $notes = json_decode((string) ($fault['notes'] ?? ''), true);
        $objectId = is_array($notes) ? filter_var($notes['source_object_id'] ?? null, FILTER_VALIDATE_INT) : false;
        $expected = $objectId === false ? null : ($prepared['rows_by_object_id'][$objectId] ?? null);
        if (!is_array($expected) || isset($seenIds[$objectId])) {
            throw new RuntimeException('Imported fault has an unknown or duplicate PHIVOLCS OBJECTID.');
        }
        assertFields($fault, [
            'hazard_type_id' => HAZARD_TYPE_ID,
            'dataset_version_id' => $datasetVersionId,
            'feature_name' => 'West Valley Fault',
            'feature_class' => 'Active Fault',
            'effective_from' => null,
            'effective_to' => null,
            'notes' => $expected['notes'],
            'record_status' => 'INACTIVE',
        ], 'PHIVOLCS OBJECTID ' . $objectId);

        $geometry = returnedGeoJsonGeometry($fault['geometry'] ?? null, 'PHIVOLCS OBJECTID ' . $objectId);
        if ($geometry['type'] !== 'MultiLineString') {
            throw new RuntimeException('PHIVOLCS OBJECTID ' . $objectId . ' was not stored as MultiLineString.');
        }
        $multiCoordinates = validateAndWrapLineGeometry($geometry, 'PHIVOLCS OBJECTID ' . $objectId . ' (Supabase)');
        if (!coordinatesEqual($expected['multi_coordinates'], $multiCoordinates)) {
            throw new RuntimeException('PHIVOLCS OBJECTID ' . $objectId . ' coordinates changed after the PostGIS round trip.');
        }
        if (multiLineIntersectsMultiPolygon($multiCoordinates, $prepared['city_coordinates'])) {
            $intersectionCount++;
        }
        $pointDistance = pointToMultiLineDistanceMeters(
            [HAZARDHUNTER_LONGITUDE, HAZARDHUNTER_LATITUDE],
            $multiCoordinates
        );
        if ($pointDistance < $minimumHazardHunterDistance) {
            $minimumHazardHunterDistance = $pointDistance;
            $nearestHazardHunterObjectId = $objectId;
        }
        $minimumCityDistance = min($minimumCityDistance, $expected['minimum_city_distance_meters']);
        $seenIds[$objectId] = true;
        $geometryCount++;
    }

    if (count($seenIds) !== EXPECTED_IMPORT_COUNT || $intersectionCount !== 0
        || abs($minimumCityDistance - EXPECTED_CITY_DISTANCE_METERS) > 0.01
        || $nearestHazardHunterObjectId !== HAZARDHUNTER_NEAREST_SOURCE_OBJECT_ID
        || abs($minimumHazardHunterDistance - EXPECTED_HAZARDHUNTER_DISTANCE_METERS) > 0.01) {
        throw new RuntimeException('Imported fault spatial validation differs from the reviewed source result.');
    }

    $allSources = $client->get('dataset_sources', [
        'select' => 'dataset_source_id,organization_name,organization_url,default_license,notes,record_status',
        'order' => 'created_at.asc',
    ]);
    $allVersions = $client->get('dataset_versions', [
        'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,version_label,review_status',
        'order' => 'created_at.asc',
    ]);
    $allFaults = $client->get('fault_features', [
        'select' => 'fault_feature_id,hazard_type_id,dataset_version_id,feature_name,feature_class,notes,record_status',
        'order' => 'created_at.asc',
    ]);
    foreach ([
        [$preexistingSources, $allSources, 'dataset source', 'dataset_source_id'],
        [$preexistingVersions, $allVersions, 'dataset version', 'dataset_version_id'],
        [$preexistingFaults, $allFaults, 'fault feature', 'fault_feature_id'],
    ] as [$before, $after, $label, $idField]) {
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

    if ((new DrrmMapReadService($client))->faultFeatures() !== []) {
        throw new RuntimeException('The production ACTIVE-only fault API exposed the inactive fault draft.');
    }

    return [
        'dataset_source_id' => $sourceId,
        'dataset_version_id' => $datasetVersionId,
        'review_status' => 'DRAFT',
        'fault_feature_count' => count($faults),
        'record_status' => 'INACTIVE',
        'feature_name' => 'West Valley Fault',
        'feature_class' => 'Active Fault',
        'geometry_type' => 'MultiLineString',
        'geometry_srid' => 4326,
        'non_empty_structurally_valid_geometries' => $geometryCount,
        'coordinates_preserved' => true,
        'intersects_caloocan' => false,
        'minimum_city_distance_meters' => $minimumCityDistance,
        'hazardhunter_point_distance_meters' => $minimumHazardHunterDistance,
        'hazardhunter_nearest_source_object_id' => $nearestHazardHunterObjectId,
        'active_production_fault_features' => 0,
        'unrelated_records_unchanged' => true,
    ];
}

/** @return array<string, mixed> */
function rollbackImport(SupabaseRestClient $client, ?string $versionId, ?string $sourceId, bool $sourceCreated): array
{
    $result = ['complete' => true, 'deleted_fault_features' => 0, 'deleted_version' => false, 'deleted_source' => false, 'errors' => []];
    $versionRemoved = $versionId === null;
    if ($versionId !== null) {
        try {
            $faults = $client->get('fault_features', [
                'select' => 'fault_feature_id,record_status',
                'dataset_version_id' => 'eq.' . $versionId,
            ]);
            foreach ($faults as $fault) {
                if (($fault['record_status'] ?? null) !== 'INACTIVE') {
                    throw new RuntimeException('Rollback refused to delete a non-INACTIVE fault feature.');
                }
            }
            if ($faults !== []) {
                $deleted = $client->delete('fault_features', [
                    'dataset_version_id' => 'eq.' . $versionId,
                    'select' => 'fault_feature_id',
                ]);
                $result['deleted_fault_features'] = count($deleted);
            }
            $deletedVersion = $client->delete('dataset_versions', [
                'dataset_version_id' => 'eq.' . $versionId,
                'review_status' => 'eq.DRAFT',
                'select' => 'dataset_version_id',
            ]);
            $result['deleted_version'] = count($deletedVersion) === 1;
            $versionRemoved = $result['deleted_version'];
            if (!$versionRemoved) {
                throw new RuntimeException('Rollback did not remove the exact draft dataset version.');
            }
        } catch (Throwable $exception) {
            $result['complete'] = false;
            $result['errors'][] = $exception->getMessage();
        }
    }
    if ($sourceCreated && $sourceId !== null && $versionRemoved) {
        try {
            $remaining = $client->get('dataset_versions', [
                'select' => 'dataset_version_id',
                'dataset_source_id' => 'eq.' . $sourceId,
            ]);
            if ($remaining === []) {
                $deleted = $client->delete('dataset_sources', [
                    'dataset_source_id' => 'eq.' . $sourceId,
                    'select' => 'dataset_source_id',
                ]);
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
    $summary = [
        'prepared_source_features' => EXPECTED_SOURCE_FEATURE_COUNT,
        'selected_west_valley_fault_segments' => count($prepared['rows']),
        'proximity_filter_meters' => (int) PROXIMITY_LIMIT_METERS,
        'geometry_conversion' => 'LineString wrapped as MultiLineString only; coordinates preserved',
        'intersects_caloocan' => false,
        'minimum_city_distance_meters' => $prepared['minimum_city_distance_meters'],
        'hazardhunter_point_distance_meters' => $prepared['minimum_hazardhunter_distance_meters'],
        'database_counts_before' => $preflight['counts'],
        'dataset_source_action' => $preflight['source_record'] === null ? 'CREATE' : 'REUSE',
        'dataset_version_action' => 'CREATE_DRAFT',
        'fault_feature_action' => 'INSERT_156_INACTIVE',
        'batch_size' => INSERT_BATCH_SIZE,
        'rollback_scope' => 'Only fault features and UUIDs created by this execution',
    ];

    if ($mode['mode'] === 'preflight') {
        echo json_encode(['success' => true, 'preflight' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }

    echo json_encode(['success' => true, 'preflight' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    $sourceId = null;
    $versionId = null;
    $sourceCreated = false;
    try {
        if ($preflight['source_record'] !== null) {
            $sourceId = (string) $preflight['source_record']['dataset_source_id'];
        } else {
            $created = $client->post('dataset_sources', sourcePayload(), [
                'select' => 'dataset_source_id,organization_name,organization_url,default_license,record_status',
            ]);
            if (count($created) !== 1 || !isUuid((string) ($created[0]['dataset_source_id'] ?? ''))) {
                throw new RuntimeException('Supabase did not return one created DOST-PHIVOLCS source.');
            }
            assertFields($created[0], [
                'organization_name' => SOURCE_ORGANIZATION,
                'organization_url' => SOURCE_ORGANIZATION_URL,
                'default_license' => null,
                'record_status' => 'ACTIVE',
            ], 'Created fault dataset source');
            $sourceId = (string) $created[0]['dataset_source_id'];
            $sourceCreated = true;
        }

        $createdVersions = $client->post('dataset_versions', versionPayload($sourceId), [
            'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_title,source_reference,version_label,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
        ]);
        if (count($createdVersions) !== 1 || !isUuid((string) ($createdVersions[0]['dataset_version_id'] ?? ''))) {
            throw new RuntimeException('Supabase did not return one created PHIVOLCS draft version.');
        }
        assertFields($createdVersions[0], [
            'dataset_source_id' => $sourceId,
            'dataset_category' => 'FAULT_FEATURE',
            'hazard_type_id' => HAZARD_TYPE_ID,
            'review_status' => 'DRAFT',
            'reviewed_by_civentral_user_id' => null,
            'reviewed_at' => null,
            'published_at' => null,
        ], 'Created fault dataset version');
        $versionId = (string) $createdVersions[0]['dataset_version_id'];

        $insertedCount = 0;
        foreach (array_chunk($prepared['rows'], INSERT_BATCH_SIZE) as $batchIndex => $batch) {
            $payload = [];
            foreach ($batch as $row) {
                $payload[] = [
                    'hazard_type_id' => HAZARD_TYPE_ID,
                    'dataset_version_id' => $versionId,
                    'feature_name' => 'West Valley Fault',
                    'feature_class' => $row['feature_class'],
                    'geometry' => $row['geometry_ewkt'],
                    'effective_from' => null,
                    'effective_to' => null,
                    'notes' => $row['notes'],
                    'record_status' => 'INACTIVE',
                ];
            }
            $inserted = $client->post('fault_features', $payload, [
                'select' => 'fault_feature_id,hazard_type_id,dataset_version_id,feature_name,feature_class,record_status',
            ]);
            if (count($inserted) !== count($payload)) {
                throw new RuntimeException('Fault batch ' . ($batchIndex + 1) . ' returned an unexpected record count.');
            }
            foreach ($inserted as $record) {
                if (($record['dataset_version_id'] ?? null) !== $versionId
                    || ($record['hazard_type_id'] ?? null) !== HAZARD_TYPE_ID
                    || ($record['feature_name'] ?? null) !== 'West Valley Fault'
                    || ($record['record_status'] ?? null) !== 'INACTIVE') {
                    throw new RuntimeException('Fault batch ' . ($batchIndex + 1) . ' returned an unsafe record.');
                }
            }
            $insertedCount += count($inserted);
            echo 'Imported inactive West Valley Fault segments: ' . $insertedCount . '/' . EXPECTED_IMPORT_COUNT . PHP_EOL;
        }
        if ($insertedCount !== EXPECTED_IMPORT_COUNT) {
            throw new RuntimeException('Controlled import did not insert exactly 156 fault features.');
        }

        $verification = verifyImport(
            $client,
            $versionId,
            $prepared,
            $preflight['existing_sources'],
            $preflight['existing_versions'],
            $preflight['existing_faults']
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
        $rollback = rollbackImport($client, $versionId, $sourceId, $sourceCreated);
        fwrite(STDERR, 'PHIVOLCS fault import failed safely: ' . $importException->getMessage() . PHP_EOL);
        fwrite(STDERR, json_encode([
            'dataset_source_id' => $sourceId,
            'dataset_version_id' => $versionId,
            'rollback' => $rollback,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Caloocan PHIVOLCS fault draft import stopped: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
