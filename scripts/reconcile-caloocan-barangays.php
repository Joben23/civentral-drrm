<?php

declare(strict_types=1);

/**
 * Local-only reconciliation of the historical 188-feature Caloocan ADM4
 * extract with the current 193-barangay administrative structure.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const HISTORICAL_INPUT = __DIR__ . '/../data/import/caloocan-barangays.geojson';
const CITY_BOUNDARY_INPUT = __DIR__ . '/../data/import/caloocan-city-boundary.geojson';
const UNAFFECTED_OUTPUT = __DIR__ . '/../data/import/caloocan-barangays-unaffected.geojson';
const REPORT_OUTPUT = __DIR__ . '/../data/import/caloocan-barangay-reconciliation.json';
const HISTORICAL_COUNT = 188;
const CURRENT_EXPECTED_COUNT = 193;
const RETIRED_NAME = 'Barangay 176';

/** @return list<string> */
function extractRawFeatures(string $geoJson): array
{
    if (preg_match('/"features"\s*:\s*\[/', $geoJson, $match, PREG_OFFSET_CAPTURE) !== 1) {
        throw new RuntimeException('GeoJSON does not contain a features array.');
    }

    $offset = $match[0][1] + strlen($match[0][0]);
    $length = strlen($geoJson);
    $features = [];

    while ($offset < $length) {
        while ($offset < $length && (ctype_space($geoJson[$offset]) || $geoJson[$offset] === ',')) {
            $offset++;
        }

        if ($offset >= $length || $geoJson[$offset] === ']') {
            break;
        }

        if ($geoJson[$offset] !== '{') {
            throw new RuntimeException('Unexpected value in the GeoJSON features array.');
        }

        $start = $offset;
        $depth = 0;
        $inString = false;
        $escaped = false;

        for (; $offset < $length; $offset++) {
            $character = $geoJson[$offset];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{' || $character === '[') {
                $depth++;
            } elseif ($character === '}' || $character === ']') {
                $depth--;

                if ($depth === 0) {
                    $features[] = substr($geoJson, $start, $offset - $start + 1);
                    $offset++;
                    break;
                }
            }
        }
    }

    return $features;
}

function writeNewFile(string $path, string $content, bool $force): void
{
    if (is_file($path) && !$force) {
        throw new RuntimeException('Output already exists: ' . basename($path) . '. Use --force only after review.');
    }

    $temporaryPath = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

    try {
        if (file_put_contents($temporaryPath, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary output: ' . basename($path));
        }

        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Unable to replace existing output: ' . basename($path));
        }

        if (!rename($temporaryPath, $path)) {
            throw new RuntimeException('Unable to finalize output: ' . basename($path));
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

/** @param list<string> $values @return list<string> */
function duplicates(array $values): array
{
    $counts = array_count_values(array_map('strtolower', $values));
    return array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
}

try {
    $force = in_array('--force', array_slice($argv, 1), true);

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument !== '--force') {
            throw new InvalidArgumentException('Only --force is supported.');
        }
    }

    if (!is_file(HISTORICAL_INPUT) || !is_file(CITY_BOUNDARY_INPUT)) {
        throw new RuntimeException('Required historical GeoJSON input is missing.');
    }

    if ((is_file(UNAFFECTED_OUTPUT) || is_file(REPORT_OUTPUT)) && !$force) {
        throw new RuntimeException('Reconciliation outputs already exist. Review them before using --force.');
    }

    $historicalHashBefore = hash_file('sha256', HISTORICAL_INPUT);
    $cityHashBefore = hash_file('sha256', CITY_BOUNDARY_INPUT);
    $historicalJson = file_get_contents(HISTORICAL_INPUT);
    $cityJson = file_get_contents(CITY_BOUNDARY_INPUT);

    if ($historicalJson === false || $cityJson === false) {
        throw new RuntimeException('Unable to read a required GeoJSON input.');
    }

    $historicalData = json_decode($historicalJson, true, 512, JSON_THROW_ON_ERROR);
    $cityData = json_decode($cityJson, true, 512, JSON_THROW_ON_ERROR);
    $rawFeatures = extractRawFeatures($historicalJson);
    $features = $historicalData['features'] ?? null;

    if (($historicalData['type'] ?? null) !== 'FeatureCollection' || !is_array($features)) {
        throw new RuntimeException('Historical input is not a valid GeoJSON FeatureCollection.');
    }

    if (($cityData['type'] ?? null) !== 'FeatureCollection' || count($cityData['features'] ?? []) !== 1) {
        throw new RuntimeException('City-boundary input is not the expected one-feature FeatureCollection.');
    }

    if (count($features) !== HISTORICAL_COUNT || count($rawFeatures) !== HISTORICAL_COUNT) {
        throw new RuntimeException('Historical input does not contain exactly 188 features.');
    }

    $unaffectedRaw = [];
    $unaffectedRecords = [];
    $retiredFeature = null;
    $geometryTypes = [];
    $sourceNames = [];
    $sourceCodes = [];
    $sourceMetadata = [];

    foreach ($features as $index => $feature) {
        $properties = $feature['properties'] ?? [];
        $name = trim((string) ($properties['adm4_name'] ?? ''));
        $code = trim((string) ($properties['adm4_pcode'] ?? ''));
        $geometryType = (string) ($feature['geometry']['type'] ?? 'NULL');
        $sourceNames[] = $name;
        $sourceCodes[] = $code;
        $sourceMetadata[json_encode([
            'version' => $properties['version'] ?? null,
            'valid_on' => $properties['valid_on'] ?? null,
            'valid_to' => $properties['valid_to'] ?? null,
        ])] = true;

        if ($name === RETIRED_NAME) {
            if ($retiredFeature !== null) {
                throw new RuntimeException('Historical input contains multiple Barangay 176 features.');
            }
            $retiredFeature = $feature;
            continue;
        }

        $unaffectedRaw[] = $rawFeatures[$index];
        $geometryTypes[$geometryType] = ($geometryTypes[$geometryType] ?? 0) + 1;
        $unaffectedRecords[] = [
            'name' => $name,
            'source_adm4_pcode' => $code,
            'geometry_type' => $geometryType,
            'geometry_status' => 'source_geometry_retained_and_structurally_validated',
        ];
    }

    if ($retiredFeature === null || count($unaffectedRaw) !== 187) {
        throw new RuntimeException('Unable to isolate one retired Barangay 176 and 187 unaffected features.');
    }

    if (duplicates($sourceNames) !== [] || duplicates($sourceCodes) !== []) {
        throw new RuntimeException('Historical input contains duplicate barangay names or codes.');
    }

    if (count(array_diff(array_keys($geometryTypes), ['Polygon', 'MultiPolygon'])) > 0) {
        throw new RuntimeException('Unaffected features contain a non-polygonal geometry.');
    }

    if (count($sourceMetadata) !== 1) {
        throw new RuntimeException('Historical features do not share one source version/date.');
    }

    $numberedNames = array_column($unaffectedRecords, 'name');
    $expectedUnaffectedNames = array_map(
        static fn (int $number): string => 'Barangay ' . $number,
        array_values(array_filter(range(1, 188), static fn (int $number): bool => $number !== 176))
    );

    if ($numberedNames !== $expectedUnaffectedNames) {
        throw new RuntimeException('Unaffected barangay names are not the expected ordered set.');
    }

    $unaffectedJson = '{"type":"FeatureCollection","features":[' . implode(',', $unaffectedRaw) . ']}';
    $unaffectedData = json_decode($unaffectedJson, true, 512, JSON_THROW_ON_ERROR);

    if (count($unaffectedData['features'] ?? []) !== 187) {
        throw new RuntimeException('Generated unaffected GeoJSON failed feature-count validation.');
    }

    if (array_map('hash', array_fill(0, 187, 'sha256'), $unaffectedRaw)
        !== array_map('hash', array_fill(0, 187, 'sha256'), extractRawFeatures($unaffectedJson))) {
        throw new RuntimeException('Raw feature preservation validation failed.');
    }

    writeNewFile(UNAFFECTED_OUTPUT, $unaffectedJson, $force);

    $currentReplacements = [
        ['name' => 'Barangay 176-A', 'psgc_code_10_digit' => '1380100189'],
        ['name' => 'Barangay 176-B', 'psgc_code_10_digit' => '1380100190'],
        ['name' => 'Barangay 176-C', 'psgc_code_10_digit' => '1380100191'],
        ['name' => 'Barangay 176-D', 'psgc_code_10_digit' => '1380100192'],
        ['name' => 'Barangay 176-E', 'psgc_code_10_digit' => '1380100193'],
        ['name' => 'Barangay 176-F', 'psgc_code_10_digit' => '1380100194'],
    ];

    foreach ($currentReplacements as &$replacement) {
        $replacement['supersedes'] = RETIRED_NAME;
        $replacement['validated_geometry_available_locally'] = false;
        $replacement['geometry_status'] = 'required_from_validated_current_source';
    }
    unset($replacement);

    $retiredProperties = $retiredFeature['properties'];
    $metadata = json_decode((string) array_key_first($sourceMetadata), true, 512, JSON_THROW_ON_ERROR);
    $historicalHashAfter = hash_file('sha256', HISTORICAL_INPUT);
    $cityHashAfter = hash_file('sha256', CITY_BOUNDARY_INPUT);

    $report = [
        'report_type' => 'Caloocan barangay administrative reconciliation',
        'source_dataset' => [
            'historical_barangay_file' => 'data/import/caloocan-barangays.geojson',
            'historical_barangay_sha256' => $historicalHashAfter,
            'city_boundary_file' => 'data/import/caloocan-city-boundary.geojson',
            'city_boundary_sha256' => $cityHashAfter,
            'version' => $metadata['version'],
            'valid_on' => $metadata['valid_on'],
            'valid_to' => $metadata['valid_to'],
            'legacy_code_format_note' => 'Source ADM4 codes use the historical PH-prefixed hierarchy (for example PH1307501176). They are preserved and are not silently replaced by current PSA 10-digit PSGC codes.',
            'administrative_currency_warning' => 'The source valid_on date does not prove current administrative alignment: this layer still contains unsplit Barangay 176 with valid_to set to null.',
        ],
        'counts' => [
            'historical_features' => HISTORICAL_COUNT,
            'unaffected_current_barangays_with_source_geometry' => 187,
            'retired_historical_features' => 1,
            'current_replacement_barangays_requiring_geometry' => 6,
            'current_expected_barangays' => CURRENT_EXPECTED_COUNT,
        ],
        'retired_barangay' => [
            'name' => RETIRED_NAME,
            'source_adm4_pcode' => $retiredProperties['adm4_pcode'],
            'source_version' => $retiredProperties['version'],
            'source_valid_on' => $retiredProperties['valid_on'],
            'source_valid_to' => $retiredProperties['valid_to'],
            'geometry_type' => $retiredFeature['geometry']['type'],
            'area_sqkm' => $retiredProperties['area_sqkm'],
            'status' => 'retired_or_superseded_for_current_structure',
            'import_as_current_barangay' => false,
            'source_metadata_warning' => 'The source valid_to value is null even though this feature is superseded in the current structure.',
        ],
        'unaffected_barangays' => $unaffectedRecords,
        'current_replacement_barangays' => $currentReplacements,
        'geometry_readiness' => [
            'current_barangays_with_available_source_geometry' => 187,
            'current_barangays_requiring_validated_geometry' => array_column($currentReplacements, 'name'),
            'local_validated_geometry_found_for_replacements' => false,
            'unaffected_subset_geometry_usable' => true,
            'unaffected_geometry_validation_scope' => 'The 187 features are unique, polygonal, and byte-preserved from the inspected source. This is structural/data-integrity validation, not an independent legal boundary survey.',
            'complete_current_193_barangay_dataset_safe_for_import' => false,
            'blocking_reason' => 'Validated polygon boundaries for Barangay 176-A through Barangay 176-F are not available locally. The retired Barangay 176 polygon must not be split, guessed, duplicated, or imported as a current barangay.',
        ],
        'validation' => [
            'unaffected_feature_count' => 187,
            'retired_barangay_176_excluded' => true,
            'duplicate_names' => [],
            'duplicate_administrative_codes' => [],
            'geometry_types' => $geometryTypes,
            'all_geometries_polygon_or_multipolygon' => true,
            'coordinates_preserved' => true,
            'coordinate_preservation_method' => 'The 187 raw source feature JSON objects were copied byte-for-byte; no geometry was decoded, simplified, split, or re-encoded.',
            'historical_source_unchanged' => $historicalHashBefore === $historicalHashAfter,
            'city_boundary_unchanged' => $cityHashBefore === $cityHashAfter,
        ],
        'current_code_reference' => [
            'coding_system' => 'Current PSA PSGC 10-digit barangay codes supplied for this reconciliation task',
            'geometry_source' => null,
            'note' => 'These current codes are recorded for reconciliation only and are not assigned to historical geometries.',
        ],
    ];

    $reportJson = json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n";
    writeNewFile(REPORT_OUTPUT, $reportJson, $force);
    json_decode((string) file_get_contents(REPORT_OUTPUT), true, 512, JSON_THROW_ON_ERROR);

    echo "Caloocan reconciliation: OK\n";
    echo "historical features: 188\n";
    echo "unaffected features: 187\n";
    echo "retired feature: Barangay 176 (" . $retiredProperties['adm4_pcode'] . ")\n";
    echo "replacement geometries available locally: 0 of 6\n";
    echo 'unaffected output: ' . UNAFFECTED_OUTPUT . "\n";
    echo 'report output: ' . REPORT_OUTPUT . "\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Reconciliation failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
