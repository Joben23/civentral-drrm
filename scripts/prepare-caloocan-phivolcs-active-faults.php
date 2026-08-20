<?php

declare(strict_types=1);

/**
 * Prepare official DOST-PHIVOLCS active-fault geometry near Caloocan City.
 *
 * This local-only utility downloads a bounded KMZ vector response from the
 * official PHIVOLCS ActiveFault ArcGIS KML service, preserves complete source
 * fault features, and keeps only features within 20 km of the validated
 * Caloocan city boundary. It does not access or modify any database.
 *
 * Usage:
 *   D:\xampp\php\php.exe scripts\prepare-caloocan-phivolcs-active-faults.php
 */

const CITY_BOUNDARY_PATH = __DIR__ . '/../data/import/caloocan-city-boundary.geojson';
const OUTPUT_GEOJSON_PATH = __DIR__ . '/../data/import/caloocan-nearby-phivolcs-active-faults.geojson';
const OUTPUT_REPORT_PATH = __DIR__ . '/../data/import/caloocan-phivolcs-active-fault-report.json';

const MAPSERVER_URL = 'https://gisweb.phivolcs.dost.gov.ph/arcgis/rest/services/PHIVOLCS/ActiveFault/MapServer';
const KMLSERVER_URL = 'https://gisweb.phivolcs.dost.gov.ph/arcgis/services/PHIVOLCS/ActiveFault/MapServer/KmlServer';
const WMS_CAPABILITIES_URL = 'https://gisweb.phivolcs.dost.gov.ph/arcgis/services/PHIVOLCS/ActiveFault/MapServer/WMSServer?service=WMS&request=GetCapabilities';
const SOURCE_AGENCY = 'DOST-PHIVOLCS';
const SEARCH_RADIUS_METERS = 20000.0;
const EARTH_RADIUS_METERS = 6371008.8;
const HAZARDHUNTER_LONGITUDE = 120.98951;
const HAZARDHUNTER_LATITUDE = 14.64953;
const HAZARDHUNTER_REPORTED_DISTANCE_METERS = 9800.0;

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}" . PHP_EOL);
    exit(1);
}

/** @return array<string, mixed> */
function decodeJsonFile(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        fail("Required file is unavailable: {$path}");
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        fail("Unable to read required file: {$path}");
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("Invalid JSON in {$path}: {$exception->getMessage()}");
    }

    if (!is_array($decoded)) {
        fail("Expected a JSON object in {$path}.");
    }

    return $decoded;
}

/** @return array{body: string, status: int, content_type: string} */
function httpGet(string $url, string $accept): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        fail('Unable to initialize cURL.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            "Accept: {$accept}",
            'User-Agent: CIVENTRAL-DRRM-Capstone-Data-Preparation/1.0',
        ],
    ]);

    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    curl_close($handle);

    if (!is_string($body) || $body === '') {
        fail("Official PHIVOLCS request failed: {$error}");
    }

    if ($status < 200 || $status >= 300) {
        fail("Official PHIVOLCS request returned HTTP {$status}.");
    }

    return ['body' => $body, 'status' => $status, 'content_type' => $contentType];
}

/** @param mixed $coordinates */
function assertCoordinateTree($coordinates): void
{
    if (!is_array($coordinates) || $coordinates === []) {
        fail('Caloocan boundary contains an empty coordinate collection.');
    }

    if (isset($coordinates[0], $coordinates[1]) && is_numeric($coordinates[0]) && is_numeric($coordinates[1])) {
        $longitude = (float) $coordinates[0];
        $latitude = (float) $coordinates[1];
        if (!is_finite($longitude) || !is_finite($latitude)
            || $longitude < -180.0 || $longitude > 180.0
            || $latitude < -90.0 || $latitude > 90.0) {
            fail('Caloocan boundary contains an invalid longitude/latitude coordinate.');
        }
        return;
    }

    foreach ($coordinates as $child) {
        assertCoordinateTree($child);
    }
}

/**
 * @param array<int, mixed> $coordinates
 * @return array{min_lon: float, min_lat: float, max_lon: float, max_lat: float}
 */
function coordinateBounds(array $coordinates): array
{
    $bounds = [
        'min_lon' => INF,
        'min_lat' => INF,
        'max_lon' => -INF,
        'max_lat' => -INF,
    ];

    $walk = static function (array $node) use (&$walk, &$bounds): void {
        if (isset($node[0], $node[1]) && is_numeric($node[0]) && is_numeric($node[1])) {
            $longitude = (float) $node[0];
            $latitude = (float) $node[1];
            $bounds['min_lon'] = min($bounds['min_lon'], $longitude);
            $bounds['min_lat'] = min($bounds['min_lat'], $latitude);
            $bounds['max_lon'] = max($bounds['max_lon'], $longitude);
            $bounds['max_lat'] = max($bounds['max_lat'], $latitude);
            return;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $walk($child);
            }
        }
    };

    $walk($coordinates);
    return $bounds;
}

/**
 * @param array<int, mixed> $cityCoordinates
 * @return array<int, array<int, array{0: float, 1: float}>>
 */
function flattenPolygonRings(array $cityCoordinates): array
{
    $rings = [];
    foreach ($cityCoordinates as $polygon) {
        if (!is_array($polygon)) {
            fail('Invalid polygon in Caloocan MultiPolygon.');
        }
        foreach ($polygon as $ring) {
            if (!is_array($ring) || count($ring) < 4) {
                fail('Invalid ring in Caloocan MultiPolygon.');
            }
            $normalized = [];
            foreach ($ring as $coordinate) {
                if (!is_array($coordinate) || count($coordinate) < 2) {
                    fail('Invalid coordinate in Caloocan MultiPolygon.');
                }
                $normalized[] = [(float) $coordinate[0], (float) $coordinate[1]];
            }
            $rings[] = $normalized;
        }
    }
    return $rings;
}

/** @return array<string, string|null> */
function parseDescriptionFields(string $description): array
{
    $fields = [];
    if (preg_match_all(
        '/<tr[^>]*>\s*<td[^>]*>\s*([^<]+?)\s*<\/td>\s*<td[^>]*>\s*(.*?)\s*<\/td>\s*<\/tr>/is',
        $description,
        $matches,
        PREG_SET_ORDER
    ) === false) {
        return $fields;
    }

    foreach ($matches as $match) {
        $key = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($key === '') {
            continue;
        }
        $fields[$key] = $value === '' || strcasecmp($value, '<Null>') === 0 ? null : $value;
    }

    return $fields;
}

/**
 * @return array<int, array<string, mixed>>
 */
function parseFaultKml(string $kml): array
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($kml, LIBXML_NONET | LIBXML_NOBLANKS);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        $message = $errors !== [] ? trim($errors[0]->message) : 'unknown XML error';
        fail("Invalid KML returned by PHIVOLCS: {$message}");
    }

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('k', 'http://www.opengis.net/kml/2.2');
    $placemarks = $xpath->query('//k:Placemark[k:LineString or k:MultiGeometry//k:LineString]');
    if ($placemarks === false || $placemarks->length === 0) {
        fail('The PHIVOLCS KML response contains no vector LineString placemarks.');
    }

    $features = [];
    $seenObjectIds = [];

    foreach ($placemarks as $placemark) {
        $nameNode = $xpath->query('./k:name', $placemark)?->item(0);
        $descriptionNode = $xpath->query('./k:description', $placemark)?->item(0);
        $placemarkName = $nameNode instanceof DOMNode ? trim($nameNode->textContent) : '';
        $description = $descriptionNode instanceof DOMNode ? $descriptionNode->textContent : '';
        $sourceFields = parseDescriptionFields($description);

        $lineNodes = $xpath->query('.//k:LineString/k:coordinates', $placemark);
        if ($lineNodes === false || $lineNodes->length === 0) {
            continue;
        }

        $lines = [];
        foreach ($lineNodes as $coordinateNode) {
            $tuples = preg_split('/\s+/', trim($coordinateNode->textContent));
            $line = [];
            foreach ($tuples ?: [] as $tuple) {
                if ($tuple === '') {
                    continue;
                }
                $parts = explode(',', $tuple);
                if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                    fail('PHIVOLCS KML contains a malformed coordinate tuple.');
                }
                $longitude = (float) $parts[0];
                $latitude = (float) $parts[1];
                if (!is_finite($longitude) || !is_finite($latitude)
                    || $longitude < -180.0 || $longitude > 180.0
                    || $latitude < -90.0 || $latitude > 90.0) {
                    fail('PHIVOLCS KML contains an out-of-range coordinate.');
                }
                $line[] = [$longitude, $latitude];
            }
            if (count($line) < 2) {
                fail('PHIVOLCS KML contains an empty or one-point fault line.');
            }
            $lines[] = $line;
        }

        $objectIdValue = $sourceFields['objectid'] ?? null;
        if ($objectIdValue === null || !ctype_digit((string) $objectIdValue)) {
            fail('A PHIVOLCS fault feature is missing its numeric objectid.');
        }
        $objectId = (int) $objectIdValue;
        if (isset($seenObjectIds[$objectId])) {
            fail("Duplicate PHIVOLCS objectid {$objectId} in vector response.");
        }
        $seenObjectIds[$objectId] = true;

        $faultSystem = $sourceFields['fname'] ?? null;
        $officialName = $placemarkName !== '' && $placemarkName !== '00'
            ? $placemarkName
            : ($faultSystem ?: null);

        $geometry = count($lines) === 1
            ? ['type' => 'LineString', 'coordinates' => $lines[0]]
            : ['type' => 'MultiLineString', 'coordinates' => $lines];

        $features[] = [
            'type' => 'Feature',
            'properties' => [
                'official_fault_name' => $officialName,
                'official_fault_system' => $faultSystem,
                'official_segment_name' => $sourceFields['segname'] ?? null,
                'feature_class' => $sourceFields['Fault Category'] ?? null,
                'trace_type' => $sourceFields['Trace type'] ?? null,
                'line_type' => $sourceFields['Line Type'] ?? null,
                'mapped_year' => $sourceFields['datemapped'] ?? null,
                'mapping_scale' => $sourceFields['Mapping Scale'] ?? null,
                'source_object_id' => $objectId,
                'source_active_fault_id' => $sourceFields['Active Fault ID'] ?? null,
                'source_global_id' => $sourceFields['globalid'] ?? null,
                'source_agency' => SOURCE_AGENCY,
                'source_service' => MAPSERVER_URL,
                'source_vector_endpoint' => KMLSERVER_URL,
            ],
            'geometry' => $geometry,
        ];
    }

    return $features;
}

/**
 * @param array<string, mixed> $feature
 * @return array<int, array<int, array{0: float, 1: float}>>
 */
function featureLines(array $feature): array
{
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry) || !isset($geometry['type'], $geometry['coordinates'])) {
        fail('Fault feature has no valid geometry object.');
    }

    if ($geometry['type'] === 'LineString') {
        return [$geometry['coordinates']];
    }
    if ($geometry['type'] === 'MultiLineString') {
        return $geometry['coordinates'];
    }
    fail('Fault geometry must be LineString or MultiLineString.');
}

/** @param array{0: float, 1: float} $point */
function projectPoint(array $point, float $referenceLatitudeRadians): array
{
    return [
        deg2rad($point[0]) * EARTH_RADIUS_METERS * cos($referenceLatitudeRadians),
        deg2rad($point[1]) * EARTH_RADIUS_METERS,
    ];
}

/**
 * @param array{0: float, 1: float} $point
 * @param array{0: float, 1: float} $segmentStart
 * @param array{0: float, 1: float} $segmentEnd
 * @return array{distance: float, t: float, nearest: array{0: float, 1: float}}
 */
function pointToSegmentPlanar(array $point, array $segmentStart, array $segmentEnd): array
{
    $dx = $segmentEnd[0] - $segmentStart[0];
    $dy = $segmentEnd[1] - $segmentStart[1];
    $lengthSquared = ($dx * $dx) + ($dy * $dy);
    $t = $lengthSquared > 0.0
        ? (($point[0] - $segmentStart[0]) * $dx + ($point[1] - $segmentStart[1]) * $dy) / $lengthSquared
        : 0.0;
    $t = max(0.0, min(1.0, $t));
    $nearest = [$segmentStart[0] + ($t * $dx), $segmentStart[1] + ($t * $dy)];
    $distance = hypot($point[0] - $nearest[0], $point[1] - $nearest[1]);
    return ['distance' => $distance, 't' => $t, 'nearest' => $nearest];
}

/**
 * @param array{0: float, 1: float} $a
 * @param array{0: float, 1: float} $b
 */
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
 * @param array{0: float, 1: float} $pointLonLat
 * @param array{0: float, 1: float} $segmentStartLonLat
 * @param array{0: float, 1: float} $segmentEndLonLat
 * @return array{distance: float, nearest: array{0: float, 1: float}}
 */
function pointToSegmentGeodesicApprox(array $pointLonLat, array $segmentStartLonLat, array $segmentEndLonLat): array
{
    $referenceLatitude = deg2rad(($pointLonLat[1] + $segmentStartLonLat[1] + $segmentEndLonLat[1]) / 3.0);
    $point = projectPoint($pointLonLat, $referenceLatitude);
    $start = projectPoint($segmentStartLonLat, $referenceLatitude);
    $end = projectPoint($segmentEndLonLat, $referenceLatitude);
    $candidate = pointToSegmentPlanar($point, $start, $end);
    $nearestLonLat = [
        $segmentStartLonLat[0] + ($candidate['t'] * ($segmentEndLonLat[0] - $segmentStartLonLat[0])),
        $segmentStartLonLat[1] + ($candidate['t'] * ($segmentEndLonLat[1] - $segmentStartLonLat[1])),
    ];
    return ['distance' => haversineMeters($pointLonLat, $nearestLonLat), 'nearest' => $nearestLonLat];
}

/**
 * @param array{0: float, 1: float} $a
 * @param array{0: float, 1: float} $b
 * @param array{0: float, 1: float} $c
 * @return float
 */
function orientation(array $a, array $b, array $c): float
{
    return (($b[0] - $a[0]) * ($c[1] - $a[1])) - (($b[1] - $a[1]) * ($c[0] - $a[0]));
}

/**
 * @param array{0: float, 1: float} $a
 * @param array{0: float, 1: float} $b
 * @param array{0: float, 1: float} $c
 */
function pointOnSegment(array $a, array $b, array $c): bool
{
    $epsilon = 1.0e-12;
    return abs(orientation($a, $b, $c)) <= $epsilon
        && $b[0] >= min($a[0], $c[0]) - $epsilon
        && $b[0] <= max($a[0], $c[0]) + $epsilon
        && $b[1] >= min($a[1], $c[1]) - $epsilon
        && $b[1] <= max($a[1], $c[1]) + $epsilon;
}

/**
 * @param array{0: float, 1: float} $a
 * @param array{0: float, 1: float} $b
 * @param array{0: float, 1: float} $c
 * @param array{0: float, 1: float} $d
 */
function segmentsIntersect(array $a, array $b, array $c, array $d): bool
{
    $o1 = orientation($a, $b, $c);
    $o2 = orientation($a, $b, $d);
    $o3 = orientation($c, $d, $a);
    $o4 = orientation($c, $d, $b);
    $epsilon = 1.0e-12;

    if ((($o1 > $epsilon && $o2 < -$epsilon) || ($o1 < -$epsilon && $o2 > $epsilon))
        && (($o3 > $epsilon && $o4 < -$epsilon) || ($o3 < -$epsilon && $o4 > $epsilon))) {
        return true;
    }

    return (abs($o1) <= $epsilon && pointOnSegment($a, $c, $b))
        || (abs($o2) <= $epsilon && pointOnSegment($a, $d, $b))
        || (abs($o3) <= $epsilon && pointOnSegment($c, $a, $d))
        || (abs($o4) <= $epsilon && pointOnSegment($c, $b, $d));
}

/**
 * @param array{0: float, 1: float} $point
 * @param array<int, array{0: float, 1: float}> $ring
 */
function pointInRing(array $point, array $ring): bool
{
    $inside = false;
    $count = count($ring);
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        if (pointOnSegment($ring[$j], $point, $ring[$i])) {
            return true;
        }
        $yiAbove = $ring[$i][1] > $point[1];
        $yjAbove = $ring[$j][1] > $point[1];
        if ($yiAbove !== $yjAbove) {
            $crossingLongitude = ($ring[$j][0] - $ring[$i][0])
                * ($point[1] - $ring[$i][1])
                / ($ring[$j][1] - $ring[$i][1])
                + $ring[$i][0];
            if ($point[0] < $crossingLongitude) {
                $inside = !$inside;
            }
        }
    }
    return $inside;
}

/**
 * @param array{0: float, 1: float} $point
 * @param array<int, mixed> $multiPolygon
 */
function pointInMultiPolygon(array $point, array $multiPolygon): bool
{
    foreach ($multiPolygon as $polygon) {
        if (!is_array($polygon) || $polygon === [] || !pointInRing($point, $polygon[0])) {
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

/**
 * @param array<int, array<int, array{0: float, 1: float}>> $lines
 * @param array<int, mixed> $cityMultiPolygon
 * @param array<int, array<int, array{0: float, 1: float}>> $cityRings
 * @return array{intersects: bool, distance: float, nearest_fault_point: array{0: float, 1: float}|null, nearest_city_point: array{0: float, 1: float}|null}
 */
function distanceLinesToCity(array $lines, array $cityMultiPolygon, array $cityRings): array
{
    $minimum = INF;
    $nearestFault = null;
    $nearestCity = null;

    foreach ($lines as $line) {
        foreach ($line as $point) {
            if (pointInMultiPolygon($point, $cityMultiPolygon)) {
                return ['intersects' => true, 'distance' => 0.0, 'nearest_fault_point' => $point, 'nearest_city_point' => $point];
            }
        }
    }

    foreach ($lines as $line) {
        for ($lineIndex = 1, $lineCount = count($line); $lineIndex < $lineCount; $lineIndex++) {
            $faultStart = $line[$lineIndex - 1];
            $faultEnd = $line[$lineIndex];
            foreach ($cityRings as $ring) {
                for ($ringIndex = 1, $ringCount = count($ring); $ringIndex < $ringCount; $ringIndex++) {
                    $cityStart = $ring[$ringIndex - 1];
                    $cityEnd = $ring[$ringIndex];
                    if (segmentsIntersect($faultStart, $faultEnd, $cityStart, $cityEnd)) {
                        return ['intersects' => true, 'distance' => 0.0, 'nearest_fault_point' => null, 'nearest_city_point' => null];
                    }

                    foreach ([[$faultStart, $cityStart, $cityEnd, true], [$faultEnd, $cityStart, $cityEnd, true], [$cityStart, $faultStart, $faultEnd, false], [$cityEnd, $faultStart, $faultEnd, false]] as $candidate) {
                        [$point, $segmentStart, $segmentEnd, $pointIsFault] = $candidate;
                        $distance = pointToSegmentGeodesicApprox($point, $segmentStart, $segmentEnd);
                        if ($distance['distance'] < $minimum) {
                            $minimum = $distance['distance'];
                            if ($pointIsFault) {
                                $nearestFault = $point;
                                $nearestCity = $distance['nearest'];
                            } else {
                                $nearestFault = $distance['nearest'];
                                $nearestCity = $point;
                            }
                        }
                    }
                }
            }
        }
    }

    return [
        'intersects' => false,
        'distance' => $minimum,
        'nearest_fault_point' => $nearestFault,
        'nearest_city_point' => $nearestCity,
    ];
}

/**
 * @param array{0: float, 1: float} $point
 * @param array<int, array<int, array{0: float, 1: float}>> $lines
 * @return array{distance: float, nearest_fault_point: array{0: float, 1: float}|null}
 */
function distancePointToLines(array $point, array $lines): array
{
    $minimum = INF;
    $nearest = null;
    foreach ($lines as $line) {
        for ($index = 1, $count = count($line); $index < $count; $index++) {
            $candidate = pointToSegmentGeodesicApprox($point, $line[$index - 1], $line[$index]);
            if ($candidate['distance'] < $minimum) {
                $minimum = $candidate['distance'];
                $nearest = $candidate['nearest'];
            }
        }
    }
    return ['distance' => $minimum, 'nearest_fault_point' => $nearest];
}

function extractDocKml(string $kmz): string
{
    $tempRoot = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'civentral-phivolcs-' . bin2hex(random_bytes(8));
    if (!mkdir($tempRoot, 0700, true) && !is_dir($tempRoot)) {
        fail('Unable to create a temporary directory for PHIVOLCS data.');
    }
    $zipPath = $tempRoot . DIRECTORY_SEPARATOR . 'active-fault.zip';

    try {
        if (file_put_contents($zipPath, $kmz, LOCK_EX) === false) {
            fail('Unable to write the temporary PHIVOLCS KMZ archive.');
        }
        $archive = new PharData($zipPath);
        if (!isset($archive['doc.kml'])) {
            fail('The official PHIVOLCS KMZ response does not contain doc.kml.');
        }
        $entry = $archive['doc.kml'];
        $kml = $entry->getContent();
        if (!is_string($kml) || $kml === '') {
            fail('The official PHIVOLCS doc.kml is empty.');
        }
        unset($entry, $archive);
        return $kml;
    } catch (UnexpectedValueException $exception) {
        fail("Unable to open the official PHIVOLCS KMZ archive: {$exception->getMessage()}");
    } finally {
        if (is_file($zipPath)) {
            unlink($zipPath);
        }
        if (is_dir($tempRoot)) {
            rmdir($tempRoot);
        }
    }
}

/** @param array<string, mixed> $value */
function writeJsonFile(string $path, array $value): void
{
    try {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("Unable to encode {$path}: {$exception->getMessage()}");
    }

    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        fail("Unable to write {$path}.");
    }
}

$cityHashBefore = hash_file('sha256', CITY_BOUNDARY_PATH);
if ($cityHashBefore === false) {
    fail('Unable to hash the Caloocan city boundary.');
}

$cityGeoJson = decodeJsonFile(CITY_BOUNDARY_PATH);
if (($cityGeoJson['type'] ?? null) !== 'FeatureCollection'
    || !isset($cityGeoJson['features'])
    || !is_array($cityGeoJson['features'])
    || count($cityGeoJson['features']) !== 1) {
    fail('Expected exactly one Caloocan city feature.');
}
$cityFeature = $cityGeoJson['features'][0];
$cityGeometry = is_array($cityFeature) ? ($cityFeature['geometry'] ?? null) : null;
if (!is_array($cityGeometry) || ($cityGeometry['type'] ?? null) !== 'MultiPolygon' || !is_array($cityGeometry['coordinates'] ?? null)) {
    fail('Caloocan city boundary must be a GeoJSON MultiPolygon.');
}
$cityCoordinates = $cityGeometry['coordinates'];
assertCoordinateTree($cityCoordinates);
$cityBounds = coordinateBounds($cityCoordinates);
$cityRings = flattenPolygonRings($cityCoordinates);

$metadataResponse = httpGet(MAPSERVER_URL . '?f=pjson', 'application/json');
try {
    $metadata = json_decode($metadataResponse['body'], true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fail("Official PHIVOLCS MapServer metadata is not valid JSON: {$exception->getMessage()}");
}
if (!is_array($metadata)
    || stripos((string) ($metadata['copyrightText'] ?? ''), 'PHIVOLCS') === false
    || (int) ($metadata['spatialReference']['wkid'] ?? 0) !== 4326) {
    fail('Official PHIVOLCS MapServer metadata differs materially from the expected DOST-PHIVOLCS EPSG:4326 service.');
}
$supportedFormats = (string) ($metadata['supportedQueryFormats'] ?? '');
$advertisesGeoJson = stripos($supportedFormats, 'geoJSON') !== false;

$wmsResponse = httpGet(WMS_CAPABILITIES_URL, 'application/xml,text/xml');
if (stripos($wmsResponse['body'], 'Active Fault (Fault System Information)') === false
    || stripos($wmsResponse['body'], 'EPSG:4326') === false) {
    fail('Official PHIVOLCS WMS capabilities do not expose the expected active-fault EPSG:4326 layer.');
}

$midLatitude = ($cityBounds['min_lat'] + $cityBounds['max_lat']) / 2.0;
$latitudePadding = rad2deg(SEARCH_RADIUS_METERS / EARTH_RADIUS_METERS);
$longitudePadding = rad2deg(SEARCH_RADIUS_METERS / (EARTH_RADIUS_METERS * cos(deg2rad($midLatitude))));
$queryBounds = [
    $cityBounds['min_lon'] - $longitudePadding,
    $cityBounds['min_lat'] - $latitudePadding,
    $cityBounds['max_lon'] + $longitudePadding,
    $cityBounds['max_lat'] + $latitudePadding,
];
$queryCenterLongitude = ($queryBounds[0] + $queryBounds[2]) / 2.0;
$queryCenterLatitude = ($queryBounds[1] + $queryBounds[3]) / 2.0;
$queryUrl = KMLSERVER_URL . '?' . http_build_query([
    'Composite' => 'false',
    'LayerIDs' => '0',
    'BBOX' => implode(',', $queryBounds),
    'CAMERA' => implode(',', [$queryCenterLongitude, $queryCenterLatitude, 60000, 0, 0]),
    'VIEW' => '60,45,1600,1200,false',
], '', '&', PHP_QUERY_RFC3986);

$kmzResponse = httpGet($queryUrl, 'application/vnd.google-earth.kmz,application/zip');
if (!str_starts_with($kmzResponse['body'], "PK")) {
    fail('Official PHIVOLCS KML service did not return a valid KMZ/ZIP signature.');
}
$faultFeatures = parseFaultKml(extractDocKml($kmzResponse['body']));
$sourceFeatureCount = count($faultFeatures);

$nearbyFeatures = [];
$intersectingFeatures = [];
$nearestCityFeature = null;
$nearestCityDistance = INF;
$nearestPointFeature = null;
$nearestPointDistance = INF;
$geometryTypes = [];
$faultNames = [];
$sourceFeatureExtent = [INF, INF, -INF, -INF];
$nearbyExtent = [INF, INF, -INF, -INF];

foreach ($faultFeatures as $feature) {
    $lines = featureLines($feature);
    $distanceToCity = distanceLinesToCity($lines, $cityCoordinates, $cityRings);
    $distanceToPoint = distancePointToLines([HAZARDHUNTER_LONGITUDE, HAZARDHUNTER_LATITUDE], $lines);
    $geometryBounds = coordinateBounds($feature['geometry']['coordinates']);
    $sourceFeatureExtent = [
        min($sourceFeatureExtent[0], $geometryBounds['min_lon']),
        min($sourceFeatureExtent[1], $geometryBounds['min_lat']),
        max($sourceFeatureExtent[2], $geometryBounds['max_lon']),
        max($sourceFeatureExtent[3], $geometryBounds['max_lat']),
    ];

    if ($distanceToCity['distance'] < $nearestCityDistance) {
        $nearestCityDistance = $distanceToCity['distance'];
        $nearestCityFeature = [
            'source_object_id' => $feature['properties']['source_object_id'],
            'official_fault_name' => $feature['properties']['official_fault_name'],
            'official_fault_system' => $feature['properties']['official_fault_system'],
            'distance_meters' => $distanceToCity['distance'],
            'nearest_fault_point' => $distanceToCity['nearest_fault_point'],
            'nearest_city_boundary_point' => $distanceToCity['nearest_city_point'],
        ];
    }
    if ($distanceToPoint['distance'] < $nearestPointDistance) {
        $nearestPointDistance = $distanceToPoint['distance'];
        $nearestPointFeature = [
            'source_object_id' => $feature['properties']['source_object_id'],
            'official_fault_name' => $feature['properties']['official_fault_name'],
            'official_fault_system' => $feature['properties']['official_fault_system'],
            'distance_meters' => $distanceToPoint['distance'],
            'nearest_fault_point' => $distanceToPoint['nearest_fault_point'],
        ];
    }

    if ($distanceToCity['intersects']) {
        $intersectingFeatures[] = [
            'source_object_id' => $feature['properties']['source_object_id'],
            'official_fault_name' => $feature['properties']['official_fault_name'],
            'official_fault_system' => $feature['properties']['official_fault_system'],
        ];
    }

    if ($distanceToCity['distance'] > SEARCH_RADIUS_METERS) {
        continue;
    }

    $feature['properties']['minimum_distance_to_caloocan_meters'] = round($distanceToCity['distance'], 2);
    $feature['properties']['intersects_caloocan'] = $distanceToCity['intersects'];
    $nearbyFeatures[] = $feature;
    $geometryTypes[$feature['geometry']['type']] = true;
    $name = $feature['properties']['official_fault_name'] ?? null;
    if (is_string($name) && $name !== '') {
        $faultNames[$name] = ($faultNames[$name] ?? 0) + 1;
    }
    $nearbyExtent = [
        min($nearbyExtent[0], $geometryBounds['min_lon']),
        min($nearbyExtent[1], $geometryBounds['min_lat']),
        max($nearbyExtent[2], $geometryBounds['max_lon']),
        max($nearbyExtent[3], $geometryBounds['max_lat']),
    ];
}

if ($nearbyFeatures === []) {
    fail('No official active-fault geometry was found within 20 km of Caloocan.');
}

usort($nearbyFeatures, static fn (array $left, array $right): int =>
    $left['properties']['source_object_id'] <=> $right['properties']['source_object_id']
);
ksort($faultNames, SORT_NATURAL | SORT_FLAG_CASE);

$retrievalTimestamp = gmdate('c');
$outputGeoJson = [
    'type' => 'FeatureCollection',
    'name' => 'Caloocan nearby DOST-PHIVOLCS active faults (20 km search area)',
    'source' => [
        'agency' => SOURCE_AGENCY,
        'service' => MAPSERVER_URL,
        'vector_endpoint' => KMLSERVER_URL,
        'retrieved_at' => $retrievalTimestamp,
        'search_radius_meters' => (int) SEARCH_RADIUS_METERS,
        'note' => 'Complete official source features are preserved; nearby faults are not clipped to Caloocan.',
    ],
    'features' => $nearbyFeatures,
];

$cityHashAfter = hash_file('sha256', CITY_BOUNDARY_PATH);
if ($cityHashAfter === false || $cityHashAfter !== $cityHashBefore) {
    fail('Caloocan city boundary changed while the preparation utility was running.');
}

$restLayerCount = is_array($metadata['layers'] ?? null) ? count($metadata['layers']) : null;
$intersectionCase = $intersectingFeatures !== [] ? 'CASE_A_INTERSECTS_CALOOCAN' : 'CASE_B_NEARBY_NO_INTERSECTION';
$report = [
    'report_type' => 'CIVENTRAL DRRM Module 1 PHIVOLCS Active Fault Validation',
    'retrieval_timestamp' => $retrievalTimestamp,
    'official_source' => [
        'agency' => SOURCE_AGENCY,
        'mapserver_url' => MAPSERVER_URL,
        'vector_endpoint_used' => KMLSERVER_URL,
        'wms_capabilities_url' => WMS_CAPABILITIES_URL,
        'layer_name' => 'Active Fault (Fault System Information)',
        'spatial_reference' => 'EPSG:4326',
        'rest_advertised_query_formats' => $supportedFormats,
        'rest_advertises_geojson' => $advertisesGeoJson,
        'rest_layer_count_at_retrieval' => $restLayerCount,
        'vector_access_note' => 'The MapServer REST root advertised GeoJSON but exposed no live queryable child layer at retrieval time. Official vector geometry was retrieved from the same PHIVOLCS ArcGIS service through its KMLServer endpoint using a bounded map view.',
    ],
    'caloocan_area_of_interest' => [
        'source_file' => 'data/import/caloocan-city-boundary.geojson',
        'geometry_type' => 'MultiPolygon',
        'source_sha256_before_and_after' => $cityHashBefore,
        'source_unchanged' => true,
        'bounding_box' => [$cityBounds['min_lon'], $cityBounds['min_lat'], $cityBounds['max_lon'], $cityBounds['max_lat']],
    ],
    'retrieval' => [
        'requested_search_radius_meters' => (int) SEARCH_RADIUS_METERS,
        'bounded_query_bbox' => $queryBounds,
        'source_vector_feature_count' => $sourceFeatureCount,
        'source_returned_geometry_extent' => $sourceFeatureExtent,
        'nearby_feature_count' => count($nearbyFeatures),
        'nearby_output_extent' => $nearbyExtent,
        'complete_source_features_preserved' => true,
        'clipped_to_caloocan' => false,
    ],
    'fault_features' => [
        'geometry_types' => array_keys($geometryTypes),
        'geometry_validity' => [
            'valid_geojson_structure' => true,
            'coordinates_in_longitude_latitude_range' => true,
            'all_lines_have_at_least_two_points' => true,
            'empty_geometries' => 0,
            'validation_scope' => 'Structural LineString/MultiLineString and coordinate validation; no geometry repair or modification was performed.',
        ],
        'fault_name_counts' => $faultNames,
    ],
    'caloocan_intersection' => [
        'result' => $intersectionCase,
        'active_fault_intersects_caloocan' => $intersectingFeatures !== [],
        'intersecting_feature_count' => count($intersectingFeatures),
        'intersecting_features' => $intersectingFeatures,
        'nearest_active_fault' => $nearestCityFeature,
    ],
    'hazardhunter_independent_check' => [
        'assessment_point' => [
            'longitude' => HAZARDHUNTER_LONGITUDE,
            'latitude' => HAZARDHUNTER_LATITUDE,
        ],
        'reported_nearest_fault' => 'West Valley Fault',
        'reported_approximate_distance_meters' => HAZARDHUNTER_REPORTED_DISTANCE_METERS,
        'calculated_nearest_active_fault' => $nearestPointFeature,
        'absolute_difference_from_reported_meters' => round(abs($nearestPointDistance - HAZARDHUNTER_REPORTED_DISTANCE_METERS), 2),
        'comparison_note' => 'The calculated value uses the retrieved official vector version and a nearest-segment great-circle distance. Differences may reflect PHIVOLCS dataset version, line segmentation, coordinate precision, and HazardHunter methodology.',
    ],
    'distance_method' => [
        'method' => 'Nearest point-to-segment search in a local equirectangular metric frame, with final distances calculated by the great-circle haversine formula on longitude/latitude coordinates.',
        'earth_radius_meters' => EARTH_RADIUS_METERS,
        'decimal_degrees_treated_as_meters' => false,
        'distance_units' => 'meters',
        'limitation' => 'This local-area spherical geodesic method is not an ellipsoidal survey measurement; it is appropriate for screening and should not replace a formal PHIVOLCS site assessment.',
    ],
    'source_limitations' => [
        'The REST service advertised GeoJSON but did not expose a queryable child feature layer at retrieval time.',
        'The official KML endpoint returns map-view-selected vector features and may preserve portions of those source features beyond the requested view.',
        'Fault traces are segmented into multiple official source records; multiple rows may share the same official fault name.',
        'Results describe proximity to mapped active-fault traces and do not constitute a site-specific PHIVOLCS assessment.',
    ],
    'frontend_display_recommendation' => $intersectingFeatures !== []
        ? 'CASE A: An official active-fault feature intersects Caloocan. A future frontend may display the official line with its source and limitations.'
        : 'CASE B: No official active-fault feature intersects Caloocan. A future frontend should state the nearest fault and distance, and show nearby geometry only with clear context that it does not cross Caloocan.',
];

writeJsonFile(OUTPUT_GEOJSON_PATH, $outputGeoJson);
writeJsonFile(OUTPUT_REPORT_PATH, $report);

echo 'PHIVOLCS active-fault preparation: OK' . PHP_EOL;
echo "Source vector features: {$sourceFeatureCount}" . PHP_EOL;
echo 'Nearby features (<= 20 km): ' . count($nearbyFeatures) . PHP_EOL;
echo 'Intersects Caloocan: ' . ($intersectingFeatures !== [] ? 'YES' : 'NO') . PHP_EOL;
echo 'Nearest fault: ' . ($nearestCityFeature['official_fault_name'] ?? 'Unnamed') . PHP_EOL;
echo 'Nearest distance from Caloocan: ' . number_format($nearestCityDistance / 1000.0, 3) . ' km' . PHP_EOL;
echo 'HazardHunter point nearest distance: ' . number_format($nearestPointDistance / 1000.0, 3) . ' km' . PHP_EOL;
echo 'GeoJSON: ' . realpath(OUTPUT_GEOJSON_PATH) . PHP_EOL;
echo 'Report: ' . realpath(OUTPUT_REPORT_PATH) . PHP_EOL;
