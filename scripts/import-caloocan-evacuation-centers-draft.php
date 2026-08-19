<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../src/Services/DrrmMapReadService.php';
require_once __DIR__ . '/../src/Services/DrrmDraftBarangayPreviewService.php';

use App\Config\SupabaseConfig;
use App\Services\DrrmDraftBarangayPreviewService;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

const CENTER_SOURCE_INPUT = __DIR__ . '/../data/import/caloocan-evacuation-centers-source.json';
const CENTER_READY_INPUT = __DIR__ . '/../data/import/caloocan-evacuation-centers-ready.json';
const CENTER_REPORT_INPUT = __DIR__ . '/../data/import/caloocan-evacuation-centers-validation-report.json';
const CITY_BOUNDARY_INPUT = __DIR__ . '/../data/import/caloocan-city-boundary.geojson';
const BARANGAY_BOUNDARY_INPUT = __DIR__ . '/../data/import/caloocan-barangays-current-unaffected.geojson';
const EXPECTED_SOURCE_SHA256 = 'C3023A298504E91D9F5DAEDD10CCAA75D6484782DE0D4FCBE8B9366A4E83B851';
const EXPECTED_READY_SHA256 = 'E9E6875FE394A891B35C38B67895107B70E9F7B45D266232093D75907CAAADEF';
const EXPECTED_REPORT_SHA256 = 'E31A1577CDCF760F8F0034E2D70A4C2EF29E087975C9E51C507F52164B2F3B9E';
const EXPECTED_CITY_SHA256 = '9647F3CAC1758A07CFDC6A5BB8767FE9E4F1EB70B4E7D2C14A99ABF2DE1F9D50';
const EXPECTED_BARANGAYS_SHA256 = '09CA37029157EA8347EB571A760CF712637F6BF1938DCCDF49964553DDD3093D';
const EXPECTED_CENTER_COUNT = 15;
const MANAGING_OFFICE = 'City Government of Caloocan';
const SOURCE_AGENCY = 'City Government of Caloocan / Caloocan PIO';

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

function coordinateNumber(int|float $number): string
{
    return json_encode($number, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}

function pointEwkt(float $longitude, float $latitude): string
{
    return 'SRID=4326;POINT(' . coordinateNumber($longitude) . ' ' . coordinateNumber($latitude) . ')';
}

/** @return array{type: string, coordinates: array{0: float, 1: float}} */
function returnedPoint(mixed $geometry, string $label): array
{
    if (is_string($geometry)) {
        try {
            $geometry = json_decode($geometry, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException($label . ' location is not valid GeoJSON.');
        }
    }

    if (!is_array($geometry) || ($geometry['type'] ?? null) !== 'Point'
        || !is_array($geometry['coordinates'] ?? null) || count($geometry['coordinates']) !== 2
        || !is_numeric($geometry['coordinates'][0]) || !is_numeric($geometry['coordinates'][1])) {
        throw new RuntimeException($label . ' location is not a GeoJSON Point.');
    }

    $longitude = (float) $geometry['coordinates'][0];
    $latitude = (float) $geometry['coordinates'][1];
    if (!is_finite($longitude) || !is_finite($latitude)
        || $longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90) {
        throw new RuntimeException($label . ' location contains invalid coordinates.');
    }

    return ['type' => 'Point', 'coordinates' => [$longitude, $latitude]];
}

/** @return array<mixed> */
function multiPolygonCoordinates(mixed $geometry, string $label): array
{
    if (is_string($geometry)) {
        try {
            $geometry = json_decode($geometry, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException($label . ' geometry is not valid GeoJSON.');
        }
    }

    if (!is_array($geometry) || !in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)
        || !is_array($geometry['coordinates'] ?? null) || $geometry['coordinates'] === []) {
        throw new RuntimeException($label . ' geometry is not a non-empty Polygon or MultiPolygon.');
    }

    return $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
}

/** @return int 0=outside, 1=inside, 2=boundary */
function pointInRing(float $longitude, float $latitude, array $ring): int
{
    $inside = false;
    $count = count($ring);
    if ($count < 4) {
        throw new RuntimeException('A validation polygon contains an invalid ring.');
    }

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
function multiPolygonContainsPoint(array $multiPolygon, float $longitude, float $latitude): bool
{
    foreach ($multiPolygon as $polygon) {
        if (!is_array($polygon) || $polygon === [] || !is_array($polygon[0] ?? null)) {
            throw new RuntimeException('A validation geometry contains an invalid polygon.');
        }

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
function loadAndValidatePreparedCenters(): array
{
    $checksums = [
        CENTER_SOURCE_INPUT => EXPECTED_SOURCE_SHA256,
        CENTER_READY_INPUT => EXPECTED_READY_SHA256,
        CENTER_REPORT_INPUT => EXPECTED_REPORT_SHA256,
        CITY_BOUNDARY_INPUT => EXPECTED_CITY_SHA256,
        BARANGAY_BOUNDARY_INPUT => EXPECTED_BARANGAYS_SHA256,
    ];
    foreach ($checksums as $path => $expected) {
        if (strtoupper((string) hash_file('sha256', $path)) !== $expected) {
            throw new RuntimeException(basename($path) . ' does not match the reviewed checksum.');
        }
    }

    $source = readJsonObject(CENTER_SOURCE_INPUT);
    $ready = readJsonObject(CENTER_READY_INPUT);
    $report = readJsonObject(CENTER_REPORT_INPUT);
    $city = readJsonObject(CITY_BOUNDARY_INPUT);
    $barangayGeoJson = readJsonObject(BARANGAY_BOUNDARY_INPUT);

    if (!is_array($source['records'] ?? null) || count($source['records']) !== 42
        || !is_array($ready['records'] ?? null) || count($ready['records']) !== EXPECTED_CENTER_COUNT
        || ($ready['record_count'] ?? null) !== EXPECTED_CENTER_COUNT
        || ($report['counts']['total_official_listed_facilities'] ?? null) !== 42
        || ($report['counts']['ready_for_initial_database_staging'] ?? null) !== EXPECTED_CENTER_COUNT
        || ($report['integrity']['coordinates_fabricated'] ?? null) !== false
        || ($report['integrity']['database_write_performed'] ?? null) !== false) {
        throw new RuntimeException('Prepared evacuation-center counts or integrity metadata are invalid.');
    }

    $sourceById = [];
    foreach ($source['records'] as $record) {
        if (!is_array($record) || !is_string($record['record_id'] ?? null) || isset($sourceById[$record['record_id']])) {
            throw new RuntimeException('The source master contains an invalid or duplicate record ID.');
        }
        $sourceById[$record['record_id']] = $record;
    }

    $reportReadyIds = $report['ready_record_ids'] ?? null;
    if (!is_array($reportReadyIds) || count($reportReadyIds) !== EXPECTED_CENTER_COUNT) {
        throw new RuntimeException('The validation report does not identify exactly 15 ready records.');
    }

    if (($city['type'] ?? null) !== 'FeatureCollection' || count($city['features'] ?? []) !== 1) {
        throw new RuntimeException('The Caloocan city boundary input is invalid.');
    }
    $cityCoordinates = multiPolygonCoordinates($city['features'][0]['geometry'] ?? null, 'Caloocan city boundary');

    if (($barangayGeoJson['type'] ?? null) !== 'FeatureCollection'
        || !is_array($barangayGeoJson['features'] ?? null) || count($barangayGeoJson['features']) !== 187) {
        throw new RuntimeException('The current unaffected barangay boundary input is invalid.');
    }
    $localBarangays = [];
    foreach ($barangayGeoJson['features'] as $feature) {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $code = (string) ($properties['current_psgc_10_digit'] ?? '');
        if (preg_match('/^\d{10}$/', $code) !== 1 || isset($localBarangays[$code])) {
            throw new RuntimeException('The current barangay boundary input contains an invalid or duplicate PSGC code.');
        }
        $localBarangays[$code] = [
            'name' => (string) ($properties['current_barangay_name'] ?? ''),
            'coordinates' => multiPolygonCoordinates($feature['geometry'] ?? null, 'Barangay ' . $code),
        ];
    }

    $rows = [];
    $seenIds = [];
    $seenNames = [];
    $seenCoordinates = [];
    foreach ($ready['records'] as $record) {
        if (!is_array($record)) {
            throw new RuntimeException('A ready evacuation-center record is invalid.');
        }

        $recordId = (string) ($record['record_id'] ?? '');
        $name = (string) ($record['source_name'] ?? '');
        $barangayName = (string) ($record['spatial_barangay_name'] ?? '');
        $barangayCode = (string) ($record['spatial_barangay_psgc'] ?? '');
        $latitude = $record['latitude'] ?? null;
        $longitude = $record['longitude'] ?? null;
        $sourceRecord = $sourceById[$recordId] ?? null;

        if (!is_array($sourceRecord) || !in_array($recordId, $reportReadyIds, true)
            || $name === '' || ($sourceRecord['source_name'] ?? null) !== $name
            || ($record['source_agency'] ?? null) !== SOURCE_AGENCY
            || ($record['designation'] ?? null) !== 'Evacuation Center'
            || ($record['coordinate_status'] ?? null) !== 'HIGH_CONFIDENCE'
            || ($record['inside_caloocan'] ?? null) !== true
            || !in_array($record['barangay_match_status'] ?? null, ['MATCH', 'SHARED_LIST_RESOLVED'], true)
            || preg_match('/^Barangay (?:[1-9]|[1-9]\d|1\d\d)$/', $barangayName) !== 1
            || preg_match('/^\d{10}$/', $barangayCode) !== 1
            || !is_numeric($latitude) || !is_numeric($longitude)
            || !is_array($record['coordinate_source'] ?? null)) {
            throw new RuntimeException('Ready record ' . ($recordId !== '' ? $recordId : '(unknown)') . ' failed the controlled staging criteria.');
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        $coordinateKey = sprintf('%.12F,%.12F', $longitude, $latitude);
        if (isset($seenIds[$recordId]) || isset($seenNames[strtolower($name)]) || isset($seenCoordinates[$coordinateKey])) {
            throw new RuntimeException('The ready subset contains a duplicate ID, name, or coordinate.');
        }
        if (!multiPolygonContainsPoint($cityCoordinates, $longitude, $latitude)) {
            throw new RuntimeException($recordId . ' is outside the validated Caloocan city boundary.');
        }

        $localBarangay = $localBarangays[$barangayCode] ?? null;
        if (!is_array($localBarangay) || $localBarangay['name'] !== $barangayName
            || !multiPolygonContainsPoint($localBarangay['coordinates'], $longitude, $latitude)) {
            throw new RuntimeException($recordId . ' does not match its validated local barangay polygon.');
        }

        $seenIds[$recordId] = true;
        $seenNames[strtolower($name)] = true;
        $seenCoordinates[$coordinateKey] = true;
        $rows[] = [
            'record_id' => $recordId,
            'name' => $name,
            'barangay_code' => $barangayCode,
            'barangay_name' => $barangayName,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $barangayName . ', Caloocan City',
        ];
    }

    sort($reportReadyIds);
    $loadedIds = array_column($rows, 'record_id');
    sort($loadedIds);
    if ($loadedIds !== $reportReadyIds || count($rows) !== EXPECTED_CENTER_COUNT) {
        throw new RuntimeException('The ready subset does not match the reviewed ready-record manifest.');
    }

    return ['rows' => $rows];
}

/** @return array<string, mixed> */
function databasePreflight(
    SupabaseRestClient $client,
    array $prepared,
    bool $enforceNoExistingTargetConflicts = true
): array
{
    $barangays = $client->get('barangays', [
        'select' => 'barangay_id,barangay_code,name,boundary_dataset_version_id,record_status,boundary_geometry',
        'order' => 'barangay_code.asc',
    ]);
    $centers = $client->get('evacuation_centers', [
        'select' => 'evacuation_center_id,name,barangay_id,location,address,capacity,operational_status,publication_status,contact_phone,accessibility_notes,managing_office_name,verified_by_civentral_user_id,verified_at',
        'order' => 'created_at.asc',
    ]);
    $hazardTypes = $client->get('hazard_types', [
        'select' => 'hazard_type_id,code,name,is_active',
        'order' => 'hazard_type_id.asc',
    ]);
    $riskLevels = $client->get('risk_levels', [
        'select' => 'risk_level_id,code,name,severity_rank,is_active',
        'order' => 'risk_level_id.asc',
    ]);

    $barangaysByCode = [];
    foreach ($barangays as $barangay) {
        $code = (string) ($barangay['barangay_code'] ?? '');
        if ($code !== '') {
            $barangaysByCode[$code][] = $barangay;
        }
    }

    $targetNames = [];
    $targetCoordinates = [];
    $resolvedBarangays = [];
    foreach ($prepared['rows'] as $row) {
        $matches = $barangaysByCode[$row['barangay_code']] ?? [];
        if (count($matches) !== 1) {
            throw new RuntimeException($row['record_id'] . ' does not resolve to exactly one database barangay.');
        }
        $barangay = $matches[0];
        if (($barangay['name'] ?? null) !== $row['barangay_name']
            || ($barangay['boundary_dataset_version_id'] ?? null) !== DrrmDraftBarangayPreviewService::DATASET_VERSION_ID
            || ($barangay['record_status'] ?? null) !== 'INACTIVE'
            || !isUuid((string) ($barangay['barangay_id'] ?? ''))) {
            throw new RuntimeException($row['record_id'] . ' database barangay is not the controlled validated draft.');
        }
        $dbGeometry = multiPolygonCoordinates($barangay['boundary_geometry'] ?? null, $row['barangay_name'] . ' database boundary');
        if (!multiPolygonContainsPoint($dbGeometry, $row['longitude'], $row['latitude'])) {
            throw new RuntimeException($row['record_id'] . ' coordinate is outside its stored PostGIS barangay boundary.');
        }

        $resolvedBarangays[$row['record_id']] = $barangay;
        $targetNames[strtolower($row['name'])] = $row['record_id'];
        $targetCoordinates[sprintf('%.9F,%.9F', $row['longitude'], $row['latitude'])] = $row['record_id'];
    }

    $conflicts = [];
    foreach ($centers as $center) {
        $centerId = (string) ($center['evacuation_center_id'] ?? 'unknown');
        $nameKey = strtolower(trim((string) ($center['name'] ?? '')));
        if (isset($targetNames[$nameKey])) {
            $conflicts[] = $targetNames[$nameKey] . ' name conflict with ' . $centerId;
            continue;
        }
        $location = returnedPoint($center['location'] ?? null, 'Existing evacuation center ' . $centerId);
        $coordinateKey = sprintf('%.9F,%.9F', $location['coordinates'][0], $location['coordinates'][1]);
        if (isset($targetCoordinates[$coordinateKey])) {
            $conflicts[] = $targetCoordinates[$coordinateKey] . ' coordinate conflict with ' . $centerId;
        }
    }
    if ($enforceNoExistingTargetConflicts && $conflicts !== []) {
        throw new RuntimeException('Evacuation-center staging conflicts found: ' . implode('; ', $conflicts));
    }

    return [
        'resolved_barangays' => $resolvedBarangays,
        'existing_centers' => $centers,
        'existing_barangays' => $barangays,
        'existing_hazard_types' => $hazardTypes,
        'existing_risk_levels' => $riskLevels,
    ];
}

/** @return array<string, mixed> */
function centerPayload(array $row, array $barangay): array
{
    return [
        'name' => $row['name'],
        'barangay_id' => $barangay['barangay_id'],
        'location' => pointEwkt($row['longitude'], $row['latitude']),
        'address' => $row['address'],
        'capacity' => 0,
        'operational_status' => 'INACTIVE',
        'publication_status' => 'DRAFT',
        'contact_phone' => null,
        'accessibility_notes' => null,
        'managing_office_name' => MANAGING_OFFICE,
        'verified_by_civentral_user_id' => null,
        'verified_at' => null,
    ];
}

/** @return array<string, mixed> */
function verifyImport(
    SupabaseRestClient $client,
    array $prepared,
    array $resolvedBarangays,
    array $preexistingCenters = [],
    array $preexistingBarangays = [],
    array $preexistingHazardTypes = [],
    array $preexistingRiskLevels = []
): array {
    $centers = $client->get('evacuation_centers', [
        'select' => 'evacuation_center_id,name,barangay_id,location,address,capacity,operational_status,publication_status,contact_phone,accessibility_notes,managing_office_name,verified_by_civentral_user_id,verified_at',
        'order' => 'created_at.asc',
    ]);
    $centerIdsByRecord = [];
    foreach ($prepared['rows'] as $row) {
        $barangay = $resolvedBarangays[$row['record_id']] ?? null;
        $matches = array_values(array_filter($centers, static fn (array $center): bool =>
            ($center['name'] ?? null) === $row['name']
            && ($center['barangay_id'] ?? null) === ($barangay['barangay_id'] ?? null)
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException($row['record_id'] . ' is not uniquely present after staging.');
        }
        $center = $matches[0];
        $centerId = (string) ($center['evacuation_center_id'] ?? '');
        if (!isUuid($centerId)) {
            throw new RuntimeException($row['record_id'] . ' has an invalid database UUID.');
        }
        assertFields($center, [
            'name' => $row['name'],
            'barangay_id' => $barangay['barangay_id'],
            'address' => $row['address'],
            'capacity' => 0,
            'operational_status' => 'INACTIVE',
            'publication_status' => 'DRAFT',
            'contact_phone' => null,
            'accessibility_notes' => null,
            'managing_office_name' => MANAGING_OFFICE,
            'verified_by_civentral_user_id' => null,
            'verified_at' => null,
        ], $row['record_id']);

        $point = returnedPoint($center['location'] ?? null, $row['record_id']);
        if (abs($point['coordinates'][0] - $row['longitude']) > 1.0E-12
            || abs($point['coordinates'][1] - $row['latitude']) > 1.0E-12) {
            throw new RuntimeException($row['record_id'] . ' coordinates changed during the PostGIS round trip.');
        }
        if (!multiPolygonContainsPoint(
            multiPolygonCoordinates($barangay['boundary_geometry'] ?? null, $row['barangay_name']),
            $point['coordinates'][0],
            $point['coordinates'][1]
        )) {
            throw new RuntimeException($row['record_id'] . ' stored point is outside its associated barangay.');
        }
        $centerIdsByRecord[$row['record_id']] = $centerId;
    }

    if (count($centerIdsByRecord) !== EXPECTED_CENTER_COUNT || count(array_unique($centerIdsByRecord)) !== EXPECTED_CENTER_COUNT) {
        throw new RuntimeException('The staged evacuation-center UUIDs are not unique.');
    }

    $afterById = [];
    foreach ($centers as $center) {
        $afterById[(string) ($center['evacuation_center_id'] ?? '')] = $center;
    }
    foreach ($preexistingCenters as $center) {
        $id = (string) ($center['evacuation_center_id'] ?? '');
        if ($id === '' || !isset($afterById[$id]) || $afterById[$id] !== $center) {
            throw new RuntimeException('A pre-existing evacuation center changed during staging.');
        }
    }

    $currentBarangays = $client->get('barangays', [
        'select' => 'barangay_id,barangay_code,name,boundary_dataset_version_id,record_status,boundary_geometry',
        'order' => 'barangay_code.asc',
    ]);
    $currentHazardTypes = $client->get('hazard_types', [
        'select' => 'hazard_type_id,code,name,is_active',
        'order' => 'hazard_type_id.asc',
    ]);
    $currentRiskLevels = $client->get('risk_levels', [
        'select' => 'risk_level_id,code,name,severity_rank,is_active',
        'order' => 'risk_level_id.asc',
    ]);
    if ($preexistingBarangays !== [] && $currentBarangays !== $preexistingBarangays) {
        throw new RuntimeException('Barangay records changed during evacuation-center staging.');
    }
    if ($preexistingHazardTypes !== [] && $currentHazardTypes !== $preexistingHazardTypes) {
        throw new RuntimeException('Hazard-type records changed during evacuation-center staging.');
    }
    if ($preexistingRiskLevels !== [] && $currentRiskLevels !== $preexistingRiskLevels) {
        throw new RuntimeException('Risk-level records changed during evacuation-center staging.');
    }
    if ((new DrrmMapReadService($client))->evacuationCenters() !== []) {
        throw new RuntimeException('The production PUBLISHED/non-INACTIVE service exposed development centers.');
    }

    ksort($centerIdsByRecord);
    return [
        'staged_count' => count($centerIdsByRecord),
        'publication_status' => 'DRAFT',
        'operational_status' => 'INACTIVE',
        'capacity_semantics' => '0 means not recorded; it must not be displayed as an actual capacity.',
        'address_semantics' => 'Validated administrative barangay and Caloocan City only; no street address was inferred.',
        'coordinate_provenance' => 'Development Preview - location pending LGU verification',
        'normal_production_center_count' => 0,
        'center_ids_by_source_record' => $centerIdsByRecord,
    ];
}

/** @param list<string> $createdIds @return array<string, mixed> */
function rollbackCreatedCenters(SupabaseRestClient $client, array $createdIds): array
{
    $removed = [];
    $errors = [];
    foreach (array_reverse($createdIds) as $centerId) {
        try {
            $deleted = $client->delete('evacuation_centers', [
                'evacuation_center_id' => 'eq.' . $centerId,
                'publication_status' => 'eq.DRAFT',
                'operational_status' => 'eq.INACTIVE',
                'select' => 'evacuation_center_id',
            ]);
            if (count($deleted) !== 1 || ($deleted[0]['evacuation_center_id'] ?? null) !== $centerId) {
                throw new RuntimeException('Exact rollback target was not deleted.');
            }
            $removed[] = $centerId;
        } catch (Throwable $exception) {
            $errors[] = ['evacuation_center_id' => $centerId, 'message' => $exception->getMessage()];
        }
    }

    return ['removed_ids' => $removed, 'errors' => $errors];
}

function parseMode(array $arguments): string
{
    $modes = array_values(array_intersect($arguments, ['--preflight', '--execute', '--verify']));
    if (count($modes) !== 1 || count($arguments) !== 2) {
        throw new InvalidArgumentException('Use exactly one of --preflight, --execute, or --verify.');
    }
    return substr($modes[0], 2);
}

try {
    $mode = parseMode($argv);
    $prepared = loadAndValidatePreparedCenters();
    $config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
    $client = new SupabaseRestClient($config, 5, 60);
    $preflight = databasePreflight($client, $prepared, $mode !== 'verify');

    if ($mode === 'preflight') {
        echo json_encode([
            'success' => true,
            'mode' => 'preflight',
            'ready_count' => count($prepared['rows']),
            'existing_evacuation_centers' => count($preflight['existing_centers']),
            'resolved_validated_barangays' => count($preflight['resolved_barangays']),
            'planned_publication_status' => 'DRAFT',
            'planned_operational_status' => 'INACTIVE',
            'planned_capacity_semantics' => '0 means not recorded',
            'conflicts' => 0,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit;
    }

    if ($mode === 'verify') {
        $verification = verifyImport($client, $prepared, $preflight['resolved_barangays']);
        echo json_encode(['success' => true, 'mode' => 'verify', 'verification' => $verification], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit;
    }

    $createdIds = [];
    try {
        foreach ($prepared['rows'] as $row) {
            $barangay = $preflight['resolved_barangays'][$row['record_id']];
            $created = $client->post('evacuation_centers', centerPayload($row, $barangay), [
                'select' => 'evacuation_center_id,name,barangay_id,location,address,capacity,operational_status,publication_status,contact_phone,accessibility_notes,managing_office_name,verified_by_civentral_user_id,verified_at',
            ]);
            $createdId = (string) ($created[0]['evacuation_center_id'] ?? '');
            if (count($created) !== 1 || !isUuid($createdId)
                || ($created[0]['name'] ?? null) !== $row['name']
                || ($created[0]['publication_status'] ?? null) !== 'DRAFT'
                || ($created[0]['operational_status'] ?? null) !== 'INACTIVE') {
                throw new RuntimeException($row['record_id'] . ' insert returned an unsafe representation.');
            }
            $createdIds[] = $createdId;
        }

        $verification = verifyImport(
            $client,
            $prepared,
            $preflight['resolved_barangays'],
            $preflight['existing_centers'],
            $preflight['existing_barangays'],
            $preflight['existing_hazard_types'],
            $preflight['existing_risk_levels']
        );

        echo json_encode([
            'success' => true,
            'mode' => 'execute',
            'verification' => $verification,
            'rollback_needed' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } catch (Throwable $exception) {
        $rollback = rollbackCreatedCenters($client, $createdIds);
        fwrite(STDERR, json_encode([
            'success' => false,
            'message' => $exception->getMessage(),
            'rollback' => $rollback,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Evacuation-center draft import: FAILED - ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
