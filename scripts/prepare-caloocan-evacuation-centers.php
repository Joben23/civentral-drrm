<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const CITY_BOUNDARY_FILE = __DIR__ . '/../data/import/caloocan-city-boundary.geojson';
const BARANGAY_BOUNDARY_FILE = __DIR__ . '/../data/import/caloocan-barangays-current-unaffected.geojson';
const MASTER_OUTPUT_FILE = __DIR__ . '/../data/import/caloocan-evacuation-centers-source.json';
const READY_OUTPUT_FILE = __DIR__ . '/../data/import/caloocan-evacuation-centers-ready.json';
const REPORT_OUTPUT_FILE = __DIR__ . '/../data/import/caloocan-evacuation-centers-validation-report.json';
const SOURCE_AGENCY = 'City Government of Caloocan / Caloocan PIO';
const DESIGNATION = 'Evacuation Center';
const DISCOVERY_DATE = '2026-08-19';

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . basename($path) . '.');
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(basename($path) . ' is invalid JSON: ' . $exception->getMessage());
    }

    if (!is_array($decoded)) {
        throw new RuntimeException(basename($path) . ' is not a JSON object.');
    }

    return $decoded;
}

/** @param array<string, mixed> $value */
function writeJson(string $path, array $value): void
{
    $encoded = json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
        JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );

    if (file_put_contents($path, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write ' . basename($path) . '.');
    }
}

/** @return array<string, mixed> */
function osmSource(string $type, int $id, string $mappedName, ?string $corroboration = null): array
{
    $source = [
        'provider' => 'OpenStreetMap via Nominatim',
        'reference' => 'https://www.openstreetmap.org/' . $type . '/' . $id,
        'geocoder' => 'https://nominatim.openstreetmap.org/',
        'mapped_name' => $mappedName,
        'retrieved_on' => DISCOVERY_DATE,
        'license_note' => 'OpenStreetMap data is available under ODbL; the coordinate is the mapped feature representative point returned by Nominatim.',
    ];

    if ($corroboration !== null) {
        $source['corroboration_reference'] = $corroboration;
    }

    return $source;
}

/** @return array<string, mixed> */
function wazeSource(string $url, string $mappedName, string $mappedAddress, ?string $corroboration = null): array
{
    $source = [
        'provider' => 'Waze public place listing',
        'reference' => $url,
        'mapped_name' => $mappedName,
        'mapped_address' => $mappedAddress,
        'retrieved_on' => DISCOVERY_DATE,
        'method_note' => 'Coordinate read from the public place page embedded latitude/longitude data; no coordinate was inferred from an address or centroid.',
    ];

    if ($corroboration !== null) {
        $source['corroboration_reference'] = $corroboration;
    }

    return $source;
}

/**
 * @param list<string> $listedBarangays
 * @param array<string, mixed>|null $coordinateSource
 * @param list<string> $notes
 * @return array<string, mixed>
 */
function sourceRecord(
    int $number,
    string $sourceName,
    array $listedBarangays,
    string $normalizedName,
    string $coordinateStatus = 'NOT_FOUND',
    ?float $latitude = null,
    ?float $longitude = null,
    ?array $coordinateSource = null,
    array $notes = [],
    bool $identityConflict = false
): array {
    return [
        'record_id' => sprintf('EC-%03d', $number),
        'source_name' => $sourceName,
        'listed_barangays' => $listedBarangays,
        'normalized_name' => $normalizedName,
        'source_agency' => SOURCE_AGENCY,
        'designation' => DESIGNATION,
        'coordinate_status' => $coordinateStatus,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'coordinate_source' => $coordinateSource,
        'validation_notes' => $notes,
        '_identity_conflict' => $identityConflict,
    ];
}

/** @return list<array<string, mixed>> */
function officialSourceRecords(): array
{
    $depedDirectory = 'https://www.depedncr.com.ph/directory-of-public-schools/';
    $depedAllocation = 'https://www.deped.gov.ph/wp-content/uploads/2020/10/PBD_Annex_E_Recipients_Advanced_Copies1.pdf';
    $comelecPolling = 'https://www.comelec.gov.ph/php-tpls-attachments/2022NLE/POP/NCR_THIRD.pdf';

    return [
        sourceRecord(1, 'Camarin D. Elem. School', ['178'], 'Camarin D Elementary School', 'HIGH_CONFIDENCE', 14.7566528, 121.0566968,
            osmSource('relation', 11906421, 'Camarin D Elementary School', $depedAllocation),
            ['Exact normalized school-name match in Caloocan; DepEd material identifies Camarin D ES in Barangay 178.']),
        sourceRecord(2, 'Caloocan North Elem. School', ['178'], 'Caloocan North Elementary School', 'HIGH_CONFIDENCE', 14.7598833, 121.0611572,
            osmSource('way', 529684257, 'Caloocan North Elementary School', $depedDirectory),
            ['Exact normalized school-name match; the public map address identifies Barangay 178.']),
        sourceRecord(3, 'Barangay Hall (3rd Floor)', ['179'], 'Barangay Hall (Third Floor)', 'HIGH_CONFIDENCE', 14.7466561, 121.0787742,
            osmSource('way', 316043793, 'Bulwagang Pambarangay ng Barangay 179'),
            ['Mapped building is explicitly named as the Barangay 179 hall. Floor-level designation comes only from the official source list.']),
        sourceRecord(4, 'Barangay Hall Evacuation Center', ['180'], 'Barangay Hall Evacuation Center'),
        sourceRecord(5, 'Pangarap Central Court', ['181', '182'], 'Pangarap Central Court'),
        sourceRecord(6, 'Midway Covered Court', ['183'], 'Midway Covered Court', 'HIGH_CONFIDENCE', 14.7486915, 121.0820275,
            wazeSource(
                'https://www.waze.com/live-map/directions/ph/ncr/caloocan/midway-covered-court?to=place.ChIJ9ZPrriW7lzMRYW34DhLGd_c',
                'Midway Covered Court',
                'Pechayan Street, Purok 7, Sitio 4, Area D, Barangay 183, Caloocan'
            ),
            ['Exact facility-name public place match with a Barangay 183 address.']),
        sourceRecord(7, 'Mt. Heights High School', ['183'], 'Mountain Heights High School', 'HIGH_CONFIDENCE', 14.7627781, 121.0814926,
            osmSource('way', 237918968, 'Mountain Heights High School', $depedDirectory),
            ['Official DepEd NCR directory identifies Mountain Heights High School on Sierra Madre Street in Barangay 183.']),
        sourceRecord(8, 'Malaria Court', ['185'], 'Malaria Court', 'HIGH_CONFIDENCE', 14.7696792, 121.0793696,
            osmSource('way', 250879819, 'Malaria Basketball Court'),
            ['Mapped court name and Barangay 185 address are consistent with the abbreviated official-list name.']),
        sourceRecord(9, 'Te Court', ['186'], 'Te Court', 'NOT_FOUND', null, null, null,
            ['No sufficiently specific public map feature could be tied to the exact source wording. Tala Estate court references were not treated as an automatic identity match.']),
        sourceRecord(10, 'A. Mabini Elem. School', ['187'], 'A. Mabini Elementary School', 'HIGH_CONFIDENCE', 14.7663938, 121.0607398,
            osmSource('way', 568327423, 'A. Mabini Elementary School', $depedDirectory),
            ['Exact normalized school-name match; the public map address identifies Barangay 187.']),
        sourceRecord(11, 'Phase 12 Covered Court', ['188'], 'Phase 12 Covered Court'),
        sourceRecord(12, 'Barangay 4 Multi Purpose Hall', ['2', '4'], 'Barangay 4 Multi-Purpose Hall'),
        sourceRecord(13, 'Barangay Hall', ['20'], 'Barangay 20 Hall'),
        sourceRecord(14, 'Barangay Hall', ['28'], 'Barangay 28 Hall', 'HIGH_CONFIDENCE', 14.6434069, 120.9709047,
            osmSource('way', 409488613, 'Bulwagang Pambarangay ng Barangay 28'),
            ['Mapped building is explicitly named as the Barangay 28 hall.']),
        sourceRecord(15, 'Barangay Hall', ['29'], 'Barangay 29 Hall', 'AMBIGUOUS', 14.6390523, 120.9765865,
            osmSource('way', 685079010, 'Bulwagang Pambarangay ng Barangay 32 Zone 3'),
            ['The public map address text mentions Barangay 29, but the mapped facility name identifies Barangay 32; it is retained only as an ambiguous candidate.'], true),
        sourceRecord(16, 'Barangay Bulwagan (2nd Floor)', ['35'], 'Barangay 35 Bulwagan (Second Floor)', 'HIGH_CONFIDENCE', 14.6380380, 120.9725443,
            osmSource('way', 581422936, 'Bulwagang Pambarangay ng Barangay 35'),
            ['Exact barangay-hall identity; spatial barangay agreement is evaluated separately. Floor-level designation comes only from the source list.']),
        sourceRecord(17, 'Bagong Silang Elem. School Evacuation Center', ['118', '119'], 'Bagong Silang Elementary School Evacuation Center', 'HIGH_CONFIDENCE', 14.6425546, 120.9851030,
            osmSource('way', 369030349, 'Bagong Silang Elementary School', $depedDirectory),
            ['The southern Caloocan school candidate was selected; a different same-name school in the former Barangay 176 area was explicitly rejected for this record.']),
        sourceRecord(18, 'Gregoria De Jesus Elem. School', ['120'], 'Gregoria De Jesus Elementary School', 'HIGH_CONFIDENCE', 14.6506256, 120.9805885,
            osmSource('relation', 11038748, 'Gregoria de Jesus Elementary School', $depedDirectory),
            ['Exact school identity; public sources place the school outside source-listed Barangay 120, so spatial agreement must be reported rather than forced.']),
        sourceRecord(19, 'Barangay Covered Court', ['159'], 'Barangay 159 Covered Court'),
        sourceRecord(20, 'Libis Baesa Elem. School', ['160'], 'Libis Baesa Elementary School', 'AMBIGUOUS', 14.6821386, 121.0000705,
            osmSource('way', 1126831362, 'Libis Baesa Elementary School – Baesa Annex', $depedDirectory),
            ['Only an explicitly named Baesa Annex candidate was found; the official list does not say Annex, so the identity is not treated as resolved.'], true),
        sourceRecord(21, 'Multi-Purpose Hall', ['160'], 'Barangay 160 Multi-Purpose Hall', 'NOT_FOUND', null, null, null,
            ['A generic hall result in another barangay was rejected.']),
        sourceRecord(22, 'Sta. Quiteria Elem. School', ['163'], 'Santa Quiteria Elementary School', 'HIGH_CONFIDENCE', 14.6804244, 121.0099243,
            osmSource('way', 677535585, 'Santa Quiteria Elementary School', $depedDirectory),
            ['Exact normalized school-name match; the public map address and polygon result are compared with source-listed Barangay 163.']),
        sourceRecord(23, "Saint Luke's Child Daycare, Lucas Cuadra St.", ['163'], "Saint Luke's Child Daycare, Lucas Cuadra Street", 'NOT_FOUND', null, null, null,
            ['Lucas Cuadra Street was corroborated as a real Caloocan street reference, but no matching daycare coordinate was found.']),
        sourceRecord(24, 'Brgy. Evacuation Center', ['164'], 'Barangay 164 Evacuation Center', 'AMBIGUOUS', 14.6899870, 121.0171928,
            osmSource('way', 569063975, 'Bulwagang Pambarangay ng Barangay 164'),
            ['The mapped Barangay 164 hall is only a candidate; the source calls the facility an evacuation center and does not establish that it is the hall.'], true),
        sourceRecord(25, 'Talipapa High School', ['164'], 'Talipapa High School', 'HIGH_CONFIDENCE', 14.6900734, 121.0166702,
            osmSource('way', 151816021, 'Talipapa High School', $depedDirectory),
            ['Exact school-name match in Caloocan.']),
        sourceRecord(26, 'Sukaban Evacuation Center', ['165'], 'Sukaban Evacuation Center'),
        sourceRecord(27, 'Barangay Function Hall', ['166'], 'Barangay 166 Function Hall'),
        sourceRecord(28, 'Llano Elem. School', ['167'], 'Llano Elementary School', 'HIGH_CONFIDENCE', 14.7318171, 121.0141422,
            osmSource('way', 985679852, 'Llano Elementary School', $depedDirectory),
            ['Exact normalized school-name match; spatial agreement is evaluated against the project polygons.']),
        sourceRecord(29, 'Maranao', ['168'], 'Maranao'),
        sourceRecord(30, 'Barangay Hall 2nd Floor (Daycare Center)', ['169'], 'Barangay 169 Hall Second Floor (Daycare Center)'),
        sourceRecord(31, 'Barangay Hall & Daycare Center', ['170'], 'Barangay 170 Hall and Daycare Center'),
        sourceRecord(32, 'Barangay Satellite, Shelterville (3rd Floor)', ['171'], 'Barangay Satellite, Shelterville (Third Floor)'),
        sourceRecord(33, 'Congress Elem. School', ['173'], 'Congress Elementary School', 'HIGH_CONFIDENCE', 14.7537780, 121.0326738,
            osmSource('way', 873379679, 'Congress Elementary School', $depedAllocation),
            ['Exact normalized school-name match; DepEd material identifies Congress ES in Barangay 173.']),
        sourceRecord(34, 'MRF Area', ['175'], 'MRF Area'),
        sourceRecord(35, 'San Lorenzo Court/Phase 4', ['176-A'], 'San Lorenzo Court / Phase 4', 'NOT_FOUND', null, null, null,
            ['No exact facility coordinate was found. Validated Barangay 176-A polygon geometry is also unavailable.']),
        sourceRecord(36, 'Bagong Silang High School', ['176-B'], 'Bagong Silang High School', 'HIGH_CONFIDENCE', 14.7709108, 121.0487872,
            osmSource('relation', 12949412, 'Bagong Silang High School', $depedDirectory),
            ['Exact school-name match, but the project has no validated Barangay 176-B polygon.']),
        sourceRecord(37, 'Pag-Asa Elem. School/Phase 7', ['176-C'], 'Pag-Asa Elementary School / Phase 7', 'HIGH_CONFIDENCE', 14.7740854, 121.0585607,
            osmSource('way', 771056968, 'Pag-asa Elementary School', $depedDirectory),
            ['Exact normalized school-name match in the split area, but the project has no validated Barangay 176-C polygon.']),
        sourceRecord(38, 'Rene Cayetano Elem. School', ['176-D'], 'Rene Cayetano Elementary School', 'HIGH_CONFIDENCE', 14.7778122, 121.0518109,
            osmSource('way', 571943238, 'Rene Cayetano Elementary School', $depedDirectory),
            ['Exact normalized school-name match, but the project has no validated Barangay 176-D polygon.']),
        sourceRecord(39, 'Kalayaan National High School', ['176-E'], 'Kalayaan National High School', 'HIGH_CONFIDENCE', 14.7805973, 121.0319181,
            osmSource('way', 563503014, 'Kalayaan National High School', $depedDirectory),
            ['Exact school-name match. The public map address identifies 176-F while the official evacuation list says 176-E; this cannot be spatially resolved until validated split polygons exist.']),
        sourceRecord(40, 'Kalayaan Elementary School', ['176-F'], 'Kalayaan Elementary School', 'HIGH_CONFIDENCE', 14.7808181, 121.0313008,
            osmSource('way', 792098002, 'Kalayaan Elementary School', $depedDirectory),
            ['Exact school-name match, but the project has no validated Barangay 176-F polygon.']),
        sourceRecord(41, 'Barangay Function Hall', ['177'], 'Barangay 177 Function Hall'),
        sourceRecord(42, 'Cielito Junior High School', ['177'], 'Cielito Zamora Junior High School', 'HIGH_CONFIDENCE', 14.745135320422165, 121.05171748993084,
            wazeSource(
                'https://www.waze.com/live-map/directions/cielito-zamora-junior-high-school-mahogany-caloocan?to=place.w.79364243.793314755.997284',
                'Cielito Zamora Junior High School',
                'Mahogany Street, Caloocan, Metro Manila',
                $comelecPolling
            ),
            ['COMELEC material identifies Cielito Zamora Junior High School in Barangay 177; the Waze place supplies the mapped coordinate.']),
    ];
}

function pointOnSegment(float $x, float $y, array $first, array $second): bool
{
    $x1 = (float) $first[0];
    $y1 = (float) $first[1];
    $x2 = (float) $second[0];
    $y2 = (float) $second[1];
    $cross = ($x - $x1) * ($y2 - $y1) - ($y - $y1) * ($x2 - $x1);
    if (abs($cross) > 1.0E-11) {
        return false;
    }

    return $x >= min($x1, $x2) - 1.0E-11 && $x <= max($x1, $x2) + 1.0E-11
        && $y >= min($y1, $y2) - 1.0E-11 && $y <= max($y1, $y2) + 1.0E-11;
}

/** Returns 1 inside, 0 on boundary, -1 outside. */
function pointInRing(float $longitude, float $latitude, array $ring): int
{
    $inside = false;
    $count = count($ring);
    for ($index = 0, $previous = $count - 1; $index < $count; $previous = $index++) {
        $first = $ring[$previous];
        $second = $ring[$index];
        if (pointOnSegment($longitude, $latitude, $first, $second)) {
            return 0;
        }

        $y1 = (float) $first[1];
        $y2 = (float) $second[1];
        $intersects = (($y1 > $latitude) !== ($y2 > $latitude))
            && ($longitude < ((float) $second[0] - (float) $first[0]) * ($latitude - $y1) / ($y2 - $y1) + (float) $first[0]);
        if ($intersects) {
            $inside = !$inside;
        }
    }

    return $inside ? 1 : -1;
}

/** @param array<string, mixed> $geometry */
function geometryContainsPoint(array $geometry, float $longitude, float $latitude): bool
{
    $type = $geometry['type'] ?? null;
    $coordinates = $geometry['coordinates'] ?? null;
    if (!in_array($type, ['Polygon', 'MultiPolygon'], true) || !is_array($coordinates)) {
        throw new RuntimeException('A validation boundary has invalid polygon geometry.');
    }

    $polygons = $type === 'Polygon' ? [$coordinates] : $coordinates;
    foreach ($polygons as $polygon) {
        if (!is_array($polygon) || $polygon === [] || !is_array($polygon[0] ?? null)) {
            throw new RuntimeException('A validation boundary contains an invalid polygon.');
        }

        $exterior = pointInRing($longitude, $latitude, $polygon[0]);
        if ($exterior === -1) {
            continue;
        }

        $insideHole = false;
        for ($ringIndex = 1; $ringIndex < count($polygon); $ringIndex++) {
            if (pointInRing($longitude, $latitude, $polygon[$ringIndex]) >= 0) {
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

/** @return list<array{name: string, psgc: string, geometry: array<string, mixed>}> */
function loadBarangays(): array
{
    $geoJson = readJson(BARANGAY_BOUNDARY_FILE);
    if (($geoJson['type'] ?? null) !== 'FeatureCollection' || !is_array($geoJson['features'] ?? null)
        || count($geoJson['features']) !== 187) {
        throw new RuntimeException('The current unaffected barangay file must contain exactly 187 features.');
    }

    $barangays = [];
    foreach ($geoJson['features'] as $feature) {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : null;
        $name = (string) ($properties['current_barangay_name'] ?? '');
        $psgc = (string) ($properties['current_psgc_10_digit'] ?? '');
        if (!preg_match('/^Barangay (?:[1-9]|[1-9][0-9]|1[0-8][0-9])$/', $name)
            || $name === 'Barangay 176' || preg_match('/^\d{10}$/', $psgc) !== 1 || !is_array($geometry)) {
            throw new RuntimeException('The current unaffected barangay file contains an invalid feature.');
        }
        $barangays[] = ['name' => $name, 'psgc' => $psgc, 'geometry' => $geometry];
    }

    return $barangays;
}

/** @return array<string, mixed> */
function loadCityGeometry(): array
{
    $geoJson = readJson(CITY_BOUNDARY_FILE);
    if (($geoJson['type'] ?? null) !== 'FeatureCollection' || !is_array($geoJson['features'] ?? null)
        || count($geoJson['features']) !== 1 || !is_array($geoJson['features'][0]['geometry'] ?? null)) {
        throw new RuntimeException('The Caloocan city boundary file is invalid.');
    }

    return $geoJson['features'][0]['geometry'];
}

try {
    $records = officialSourceRecords();
    if (count($records) !== 42) {
        throw new RuntimeException('The official source transcription must contain exactly 42 facilities.');
    }

    $ids = array_column($records, 'record_id');
    if (count(array_unique($ids)) !== 42) {
        throw new RuntimeException('Evacuation-center record IDs are not unique.');
    }

    $allowedCoordinateStatuses = ['VERIFIED', 'HIGH_CONFIDENCE', 'AMBIGUOUS', 'NOT_FOUND'];
    $cityGeometry = loadCityGeometry();
    $barangays = loadBarangays();
    $readyRecords = [];

    foreach ($records as &$record) {
        $status = $record['coordinate_status'];
        if (!in_array($status, $allowedCoordinateStatuses, true)) {
            throw new RuntimeException($record['record_id'] . ' has an invalid coordinate status.');
        }

        $latitude = $record['latitude'];
        $longitude = $record['longitude'];
        $hasCoordinate = is_float($latitude) && is_float($longitude);
        if (($status === 'NOT_FOUND') !== !$hasCoordinate || ($hasCoordinate && $record['coordinate_source'] === null)) {
            throw new RuntimeException($record['record_id'] . ' has inconsistent coordinate fields.');
        }
        if ($hasCoordinate && ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180)) {
            throw new RuntimeException($record['record_id'] . ' has an out-of-range coordinate.');
        }

        $specialSplit = count(array_filter(
            $record['listed_barangays'],
            static fn (string $barangay): bool => preg_match('/^176-[A-F]$/', $barangay) === 1
        )) > 0;
        $insideCity = $hasCoordinate ? geometryContainsPoint($cityGeometry, $longitude, $latitude) : null;
        $spatialMatches = [];

        if ($hasCoordinate && $insideCity) {
            foreach ($barangays as $barangay) {
                if (geometryContainsPoint($barangay['geometry'], $longitude, $latitude)) {
                    $spatialMatches[] = $barangay;
                }
            }
        }

        $resolved = count($spatialMatches) === 1 ? $spatialMatches[0] : null;
        if ($specialSplit) {
            $matchStatus = 'UNVALIDATED_176_SPLIT';
            $record['validation_notes'][] = 'No old Barangay 176 polygon or inferred 176-A–F geometry was used.';
        } elseif (!$hasCoordinate || !$insideCity || $resolved === null) {
            $matchStatus = 'NO_MATCH';
        } else {
            $spatialNumber = substr($resolved['name'], strlen('Barangay '));
            if (in_array($spatialNumber, $record['listed_barangays'], true)) {
                $matchStatus = count($record['listed_barangays']) > 1 ? 'SHARED_LIST_RESOLVED' : 'MATCH';
            } else {
                $matchStatus = 'MISMATCH';
                $record['validation_notes'][] = 'Spatial validation resolved to ' . $resolved['name']
                    . ', not the source-listed barangay ' . implode(' / ', $record['listed_barangays']) . '.';
            }
        }

        if ($hasCoordinate && !$insideCity) {
            $record['validation_notes'][] = 'The candidate coordinate is outside the validated Caloocan city boundary.';
        } elseif ($hasCoordinate && $insideCity && $resolved === null && !$specialSplit) {
            $record['validation_notes'][] = count($spatialMatches) > 1
                ? 'The point touched multiple barangay polygons and was not resolved automatically.'
                : 'The point did not resolve to one of the 187 available barangay polygons.';
        }

        $record['inside_caloocan'] = $insideCity;
        $record['spatial_barangay_name'] = $specialSplit ? null : ($resolved['name'] ?? null);
        $record['spatial_barangay_psgc'] = $specialSplit ? null : ($resolved['psgc'] ?? null);
        $record['barangay_match_status'] = $matchStatus;
        $record['ready_for_staging'] = in_array($status, ['VERIFIED', 'HIGH_CONFIDENCE'], true)
            && $insideCity === true
            && in_array($matchStatus, ['MATCH', 'SHARED_LIST_RESOLVED'], true)
            && $record['_identity_conflict'] === false
            && !$specialSplit;

        unset($record['_identity_conflict']);
        if ($record['ready_for_staging']) {
            $readyRecords[] = $record;
        }
    }
    unset($record);

    $coordinateCounts = array_fill_keys($allowedCoordinateStatuses, 0);
    $matchCounts = array_fill_keys(['MATCH', 'SHARED_LIST_RESOLVED', 'MISMATCH', 'UNVALIDATED_176_SPLIT', 'NO_MATCH'], 0);
    foreach ($records as $record) {
        $coordinateCounts[$record['coordinate_status']]++;
        $matchCounts[$record['barangay_match_status']]++;
    }

    $generatedAt = gmdate('c');
    $master = [
        'type' => 'CIVENTRAL_DRRM_EVACUATION_CENTER_SOURCE_MASTER',
        'generated_at' => $generatedAt,
        'source' => [
            'agency' => SOURCE_AGENCY,
            'description' => 'Official/latest evacuation-center list supplied to the CIVENTRAL DRRM project for this validation task.',
            'original_publication_reference' => null,
            'publication_reference_note' => 'The task supplied the authoritative list but did not include its original post/document URL or publication date.',
            'facility_count' => count($records),
        ],
        'coordinate_discovery' => [
            'performed_on' => DISCOVERY_DATE,
            'primary_public_map_source' => 'OpenStreetMap via Nominatim',
            'secondary_public_map_source' => 'Waze public place pages for exact named facilities not returned by Nominatim',
            'official_corroboration_sources' => [
                'https://www.depedncr.com.ph/directory-of-public-schools/',
                'https://www.deped.gov.ph/wp-content/uploads/2020/10/PBD_Annex_E_Recipients_Advanced_Copies1.pdf',
                'https://www.comelec.gov.ph/php-tpls-attachments/2022NLE/POP/NCR_THIRD.pdf',
            ],
            'policy' => 'No barangay centroid, random point, inferred address coordinate, or manually estimated map point was used.',
        ],
        'spatial_validation_sources' => [
            'city_boundary' => 'data/import/caloocan-city-boundary.geojson',
            'barangay_boundaries' => 'data/import/caloocan-barangays-current-unaffected.geojson',
            'validated_barangay_polygon_count' => 187,
        ],
        'records' => $records,
    ];

    $ready = [
        'type' => 'CIVENTRAL_DRRM_EVACUATION_CENTERS_READY_SUBSET',
        'generated_at' => $generatedAt,
        'status' => 'READY_FOR_MANUAL_REVIEW_BEFORE_DATABASE_STAGING',
        'selection_rule' => 'VERIFIED/HIGH_CONFIDENCE coordinate, inside Caloocan, exact current barangay match or resolved shared listing, no identity conflict, and not Barangay 176-A through 176-F.',
        'record_count' => count($readyRecords),
        'records' => $readyRecords,
    ];

    $report = [
        'type' => 'CIVENTRAL_DRRM_EVACUATION_CENTER_VALIDATION_REPORT',
        'generated_at' => $generatedAt,
        'source_agency' => SOURCE_AGENCY,
        'counts' => [
            'total_official_listed_facilities' => count($records),
            'coordinates_discovered' => count(array_filter($records, static fn (array $record): bool => $record['latitude'] !== null)),
            'coordinate_confidence' => $coordinateCounts,
            'inside_caloocan' => count(array_filter($records, static fn (array $record): bool => $record['inside_caloocan'] === true)),
            'outside_caloocan' => count(array_filter($records, static fn (array $record): bool => $record['inside_caloocan'] === false)),
            'inside_caloocan_not_evaluated' => count(array_filter($records, static fn (array $record): bool => $record['inside_caloocan'] === null)),
            'barangay_match_status' => $matchCounts,
            'ready_for_initial_database_staging' => count($readyRecords),
        ],
        'mismatches' => array_values(array_filter($records, static fn (array $record): bool => $record['barangay_match_status'] === 'MISMATCH')),
        'ambiguous_coordinate_records' => array_values(array_filter($records, static fn (array $record): bool => $record['coordinate_status'] === 'AMBIGUOUS')),
        'not_found_records' => array_values(array_filter($records, static fn (array $record): bool => $record['coordinate_status'] === 'NOT_FOUND')),
        'pending_176_split_records' => array_values(array_filter(
            $records,
            static fn (array $record): bool => $record['barangay_match_status'] === 'UNVALIDATED_176_SPLIT'
        )),
        'ready_record_ids' => array_column($readyRecords, 'record_id'),
        'integrity' => [
            'city_boundary_sha256' => strtoupper((string) hash_file('sha256', CITY_BOUNDARY_FILE)),
            'barangay_boundary_sha256' => strtoupper((string) hash_file('sha256', BARANGAY_BOUNDARY_FILE)),
            'coordinates_fabricated' => false,
            'source_geometry_modified' => false,
            'database_write_performed' => false,
        ],
        'limitations' => [
            'Public map coordinates are suitable for a carefully labeled development subset but are not a substitute for an LGU survey or facility-owner confirmation.',
            'The original Caloocan PIO publication URL/date was not supplied and should be attached during manual provenance review.',
            'Barangays 176-A through 176-F remain excluded from readiness until validated split polygons are obtained.',
            'Generic halls, courts, and other facilities without a facility-specific public map match remain NOT_FOUND or AMBIGUOUS.',
        ],
    ];

    if (count($report['pending_176_split_records']) !== 6) {
        throw new RuntimeException('Exactly six 176-A through 176-F records must remain pending.');
    }

    writeJson(MASTER_OUTPUT_FILE, $master);
    writeJson(READY_OUTPUT_FILE, $ready);
    writeJson(REPORT_OUTPUT_FILE, $report);

    echo 'Evacuation-center source records: ' . count($records) . PHP_EOL;
    echo 'Coordinates discovered: ' . $report['counts']['coordinates_discovered'] . PHP_EOL;
    echo 'Coordinate status: ' . json_encode($coordinateCounts, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo 'Barangay matches: ' . json_encode($matchCounts, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo 'Ready for manual staging review: ' . count($readyRecords) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Evacuation-center preparation stopped: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
