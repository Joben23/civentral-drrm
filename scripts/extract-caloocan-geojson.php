<?php

declare(strict_types=1);

/**
 * Local-only streaming utility for inspecting and extracting Caloocan
 * administrative features from very large GeoJSON FeatureCollections.
 *
 * Run from CLI only. Exact official hierarchy codes are based on the inspected
 * phl_admin3/phl_admin4 source schema; raw selected features are copied without
 * decoding and re-encoding their geometry coordinates.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const STREAM_CHUNK_BYTES = 1048576;
const DEFAULT_ADM4_SOURCE = 'D:\\phl_admin4.geojson';
const DEFAULT_ADM3_SOURCE = 'D:\\phl_admin3.geojson';
const TARGET_ADM0_PCODE = 'PH';
const TARGET_ADM1_PCODE = 'PH13';
const TARGET_ADM2_PCODE = 'PH13075';
const TARGET_ADM3_PCODE = 'PH1307501';
const EXPECTED_CALOOCAN_BARANGAYS = 188;

/** @return Generator<int, string> */
function streamGeoJsonFeatures(string $path): Generator
{
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException('Unable to open the GeoJSON source file.');
    }

    try {
        $searchBuffer = '';
        $chunk = '';
        $featuresFound = false;

        while (!feof($handle)) {
            $chunk = fread($handle, STREAM_CHUNK_BYTES);

            if ($chunk === false) {
                throw new RuntimeException('Unable to read the GeoJSON source file.');
            }

            $searchBuffer .= $chunk;

            if (preg_match('/"features"\s*:\s*\[/', $searchBuffer, $match, PREG_OFFSET_CAPTURE) === 1) {
                $matchOffset = $match[0][1];
                $matchLength = strlen($match[0][0]);
                $chunk = substr($searchBuffer, $matchOffset + $matchLength);
                $featuresFound = true;
                break;
            }

            $searchBuffer = substr($searchBuffer, -128);
        }

        if (!$featuresFound) {
            throw new RuntimeException('The source is not a GeoJSON FeatureCollection with a features array.');
        }

        $capturing = false;
        $featureBuffer = '';
        $depth = 0;
        $inString = false;
        $escaped = false;

        while (true) {
            $chunkLength = strlen($chunk);
            $captureStart = $capturing ? 0 : null;

            for ($index = 0; $index < $chunkLength; $index++) {
                $character = $chunk[$index];

                if (!$capturing) {
                    if ($character === ']') {
                        return;
                    }

                    if ($character === '{') {
                        $capturing = true;
                        $featureBuffer = '';
                        $depth = 1;
                        $inString = false;
                        $escaped = false;
                        $captureStart = $index;
                    }

                    continue;
                }

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
                        $featureBuffer .= substr($chunk, (int) $captureStart, $index - (int) $captureStart + 1);
                        yield $featureBuffer;

                        $capturing = false;
                        $featureBuffer = '';
                        $captureStart = null;
                    }
                }
            }

            if ($capturing && $captureStart !== null) {
                $featureBuffer .= substr($chunk, $captureStart);
            }

            if (feof($handle)) {
                break;
            }

            $chunk = fread($handle, STREAM_CHUNK_BYTES);

            if ($chunk === false) {
                throw new RuntimeException('Unable to read the GeoJSON source file.');
            }
        }

        if ($capturing) {
            throw new RuntimeException('The source contains an incomplete GeoJSON feature.');
        }
    } finally {
        fclose($handle);
    }
}

/** @return array<string, string|bool> */
function parseArguments(array $arguments): array
{
    $options = [];

    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--inspect') {
            $options['inspect'] = true;
            continue;
        }

        if ($argument === '--force') {
            $options['force'] = true;
            continue;
        }

        if (str_starts_with($argument, '--source=')) {
            $options['source'] = substr($argument, strlen('--source='));
            continue;
        }

        if (str_starts_with($argument, '--adm4=')) {
            $options['adm4'] = substr($argument, strlen('--adm4='));
            continue;
        }

        if (str_starts_with($argument, '--adm3=')) {
            $options['adm3'] = substr($argument, strlen('--adm3='));
            continue;
        }

        if (str_starts_with($argument, '--output-dir=')) {
            $options['output_dir'] = substr($argument, strlen('--output-dir='));
            continue;
        }

        throw new InvalidArgumentException('Unsupported command-line argument.');
    }

    return $options;
}

function inspectGeoJson(string $sourcePath): void
{
    $propertyValues = [];
    $propertyTypes = [];
    $geometryTypes = [];
    $caloocanCandidates = 0;
    $candidateGeometryTypes = [];
    $featureCount = 0;

    foreach (streamGeoJsonFeatures($sourcePath) as $rawFeature) {
        $feature = json_decode($rawFeature, true, 512, JSON_THROW_ON_ERROR);
        $properties = $feature['properties'] ?? [];
        $geometryType = $feature['geometry']['type'] ?? 'NULL';
        $geometryTypes[$geometryType] = ($geometryTypes[$geometryType] ?? 0) + 1;
        $featureCount++;

        if (!is_array($properties)) {
            throw new RuntimeException('A feature contains invalid properties.');
        }

        $isCaloocanCandidate = false;

        foreach ($properties as $field => $value) {
            $propertyTypes[$field][get_debug_type($value)] = true;

            if (is_scalar($value) || $value === null) {
                $stringValue = $value === null ? 'NULL' : (string) $value;

                if (stripos($stringValue, 'caloocan') !== false) {
                    $isCaloocanCandidate = true;
                }
            }
        }

        if ($isCaloocanCandidate) {
            $caloocanCandidates++;
            $candidateGeometryTypes[$geometryType] = ($candidateGeometryTypes[$geometryType] ?? 0) + 1;

            foreach ($properties as $field => $value) {
                if (
                    (is_scalar($value) || $value === null)
                    && preg_match('/(adm|region|province|city|municip|psgc|pcode|code|name)/i', (string) $field) === 1
                ) {
                    $normalized = $value === null ? 'NULL' : (string) $value;
                    $propertyValues[$field][$normalized] = true;
                }
            }
        }

        if ($featureCount % 5000 === 0) {
            fwrite(STDERR, 'Inspected ' . $featureCount . " features...\n");
        }
    }

    ksort($propertyTypes);
    ksort($propertyValues);
    ksort($geometryTypes);
    ksort($candidateGeometryTypes);

    echo 'Source: ' . $sourcePath . "\n";
    echo 'Features inspected: ' . $featureCount . "\n";
    echo 'Property fields:' . "\n";

    foreach ($propertyTypes as $field => $types) {
        echo '  ' . $field . ' [' . implode('|', array_keys($types)) . "]\n";
    }

    echo 'All geometry types: ' . json_encode($geometryTypes, JSON_UNESCAPED_SLASHES) . "\n";
    echo 'Features containing Caloocan in any property: ' . $caloocanCandidates . "\n";
    echo 'Candidate geometry types: ' . json_encode($candidateGeometryTypes, JSON_UNESCAPED_SLASHES) . "\n";
    echo 'Candidate administrative values:' . "\n";

    foreach ($propertyValues as $field => $values) {
        $distinctValues = array_keys($values);
        $sample = array_slice($distinctValues, 0, 12);
        echo '  ' . $field . ' (' . count($distinctValues) . ' distinct): '
            . implode(' | ', $sample)
            . (count($distinctValues) > count($sample) ? ' | ...' : '')
            . "\n";
    }
}

/** @param resource $handle */
function writeAll($handle, string $bytes): void
{
    $remaining = strlen($bytes);
    $offset = 0;

    while ($remaining > 0) {
        $written = fwrite($handle, substr($bytes, $offset));

        if ($written === false || $written === 0) {
            throw new RuntimeException('Unable to write the GeoJSON output file.');
        }

        $offset += $written;
        $remaining -= $written;
    }
}

/** @param array<string, mixed> $properties */
function matchesCaloocanHierarchy(array $properties): bool
{
    return strtoupper(trim((string) ($properties['adm0_pcode'] ?? ''))) === TARGET_ADM0_PCODE
        && strtoupper(trim((string) ($properties['adm1_pcode'] ?? ''))) === TARGET_ADM1_PCODE
        && strtoupper(trim((string) ($properties['adm2_pcode'] ?? ''))) === TARGET_ADM2_PCODE
        && strtoupper(trim((string) ($properties['adm3_pcode'] ?? ''))) === TARGET_ADM3_PCODE;
}

/**
 * @return array{
 *     source: string,
 *     output: string,
 *     count: int,
 *     geometry_types: array<string, int>,
 *     all_polygonal: bool,
 *     duplicate_codes: list<string>,
 *     duplicate_names: list<string>,
 *     output_size: int
 * }
 */
function extractCaloocanFeatures(
    string $sourcePath,
    string $outputPath,
    string $level,
    int $expectedCount,
    bool $force
): array {
    if (!is_file($sourcePath)) {
        throw new RuntimeException(strtoupper($level) . ' source file does not exist.');
    }

    if (is_file($outputPath) && !$force) {
        throw new RuntimeException('Output already exists; use --force only after reviewing the existing file.');
    }

    $requiredFields = [
        'adm0_name', 'adm0_pcode',
        'adm1_name', 'adm1_pcode',
        'adm2_name', 'adm2_pcode',
        'adm3_name', 'adm3_pcode',
    ];
    $codeField = $level === 'adm4' ? 'adm4_pcode' : 'adm3_pcode';
    $nameField = $level === 'adm4' ? 'adm4_name' : 'adm3_name';

    if ($level === 'adm4') {
        $requiredFields[] = 'adm4_name';
        $requiredFields[] = 'adm4_pcode';
    }

    $temporaryPath = $outputPath . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $outputHandle = fopen($temporaryPath, 'xb');

    if ($outputHandle === false) {
        throw new RuntimeException('Unable to create the temporary GeoJSON output file.');
    }

    $selectedCount = 0;
    $sourceFeatureCount = 0;
    $geometryTypes = [];
    $seenCodes = [];
    $seenNames = [];
    $duplicateCodes = [];
    $duplicateNames = [];
    $schemaChecked = false;

    try {
        writeAll($outputHandle, '{"type":"FeatureCollection","features":[');

        foreach (streamGeoJsonFeatures($sourcePath) as $rawFeature) {
            $feature = json_decode($rawFeature, true, 512, JSON_THROW_ON_ERROR);
            $properties = $feature['properties'] ?? null;
            $sourceFeatureCount++;

            if (!is_array($properties)) {
                throw new RuntimeException('A source feature contains invalid properties.');
            }

            if (!$schemaChecked) {
                foreach ($requiredFields as $requiredField) {
                    if (!array_key_exists($requiredField, $properties)) {
                        throw new RuntimeException('The source is missing the inspected field: ' . $requiredField);
                    }
                }

                $schemaChecked = true;
            }

            if (!matchesCaloocanHierarchy($properties)) {
                continue;
            }

            $geometryType = (string) ($feature['geometry']['type'] ?? 'NULL');
            $geometryTypes[$geometryType] = ($geometryTypes[$geometryType] ?? 0) + 1;
            $code = trim((string) ($properties[$codeField] ?? ''));
            $name = trim((string) ($properties[$nameField] ?? ''));

            if ($code === '' || $name === '') {
                throw new RuntimeException('A selected feature is missing its official code or name.');
            }

            if (isset($seenCodes[$code])) {
                $duplicateCodes[$code] = true;
            }
            $seenCodes[$code] = true;

            $normalizedName = strtolower($name);
            if (isset($seenNames[$normalizedName])) {
                $duplicateNames[$name] = true;
            }
            $seenNames[$normalizedName] = true;

            if ($selectedCount > 0) {
                writeAll($outputHandle, ',');
            }

            // Copy the raw source feature so coordinate tokens remain unchanged.
            writeAll($outputHandle, $rawFeature);
            $selectedCount++;
        }

        writeAll($outputHandle, ']}');

        if (!fflush($outputHandle)) {
            throw new RuntimeException('Unable to flush the GeoJSON output file.');
        }

        fclose($outputHandle);
        $outputHandle = null;

        $allPolygonal = $geometryTypes !== []
            && count(array_diff(array_keys($geometryTypes), ['Polygon', 'MultiPolygon'])) === 0;

        if ($selectedCount !== $expectedCount) {
            throw new RuntimeException(
                'Expected ' . $expectedCount . ' ' . strtoupper($level)
                . ' feature(s), but selected ' . $selectedCount . '.'
            );
        }

        if (!$allPolygonal) {
            throw new RuntimeException('The selected output contains a non-polygonal geometry.');
        }

        if ($duplicateCodes !== []) {
            throw new RuntimeException('The selected output contains duplicate official administrative codes.');
        }

        if (is_file($outputPath) && !unlink($outputPath)) {
            throw new RuntimeException('Unable to replace the existing output file.');
        }

        if (!rename($temporaryPath, $outputPath)) {
            throw new RuntimeException('Unable to finalize the GeoJSON output file.');
        }

        $outputSize = filesize($outputPath);

        if ($outputSize === false) {
            throw new RuntimeException('Unable to determine the GeoJSON output size.');
        }

        ksort($geometryTypes);

        return [
            'source' => $sourcePath,
            'output' => $outputPath,
            'count' => $selectedCount,
            'geometry_types' => $geometryTypes,
            'all_polygonal' => $allPolygonal,
            'duplicate_codes' => array_keys($duplicateCodes),
            'duplicate_names' => array_keys($duplicateNames),
            'output_size' => $outputSize,
        ];
    } finally {
        if (is_resource($outputHandle)) {
            fclose($outputHandle);
        }

        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

/** @param array<string, mixed> $summary */
function printExtractionSummary(string $label, array $summary): void
{
    echo $label . "\n";
    echo '  Source: ' . $summary['source'] . "\n";
    echo '  Output: ' . $summary['output'] . "\n";
    echo '  Features: ' . $summary['count'] . "\n";
    echo '  Geometry types: ' . json_encode($summary['geometry_types'], JSON_UNESCAPED_SLASHES) . "\n";
    echo '  All Polygon/MultiPolygon: ' . ($summary['all_polygonal'] ? 'yes' : 'no') . "\n";
    echo '  Duplicate codes: ' . (count($summary['duplicate_codes']) === 0 ? 'none' : implode(', ', $summary['duplicate_codes'])) . "\n";
    echo '  Duplicate names: ' . (count($summary['duplicate_names']) === 0 ? 'none' : implode(', ', $summary['duplicate_names'])) . "\n";
    echo '  Output size: ' . $summary['output_size'] . " bytes\n";
}

try {
    $options = parseArguments($argv);

    if (($options['inspect'] ?? false) === true) {
        if (empty($options['source'])) {
            fwrite(STDERR, "Usage: php scripts/extract-caloocan-geojson.php --inspect --source=<path>\n");
            exit(2);
        }

        $sourcePath = (string) $options['source'];

        if (!is_file($sourcePath)) {
            throw new RuntimeException('The specified GeoJSON source file does not exist.');
        }

        inspectGeoJson($sourcePath);
        exit(0);
    }

    $adm4Source = (string) ($options['adm4'] ?? DEFAULT_ADM4_SOURCE);
    $adm3Source = (string) ($options['adm3'] ?? DEFAULT_ADM3_SOURCE);
    $outputDirectory = (string) ($options['output_dir'] ?? dirname(__DIR__) . '/data/import');
    $force = ($options['force'] ?? false) === true;

    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('Unable to create the output directory.');
    }

    $barangaySummary = extractCaloocanFeatures(
        $adm4Source,
        rtrim($outputDirectory, '/\\') . '/caloocan-barangays.geojson',
        'adm4',
        EXPECTED_CALOOCAN_BARANGAYS,
        $force
    );
    printExtractionSummary('Caloocan barangay extraction:', $barangaySummary);

    if (is_file($adm3Source)) {
        $citySummary = extractCaloocanFeatures(
            $adm3Source,
            rtrim($outputDirectory, '/\\') . '/caloocan-city-boundary.geojson',
            'adm3',
            1,
            $force
        );
        printExtractionSummary('Caloocan city-boundary extraction:', $citySummary);
    } else {
        echo "ADM3 source not found; optional city-boundary output skipped.\n";
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'GeoJSON utility failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
