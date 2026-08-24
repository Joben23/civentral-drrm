<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;

/**
 * Owns browser-to-PHP prediction input acquisition.
 *
 * Phase 7E validates only a current Caloocan barangay selection. It does not
 * accept rainfall, susceptibility, probability, model metadata, or risk level
 * from the browser. Until verified forecast and antecedent rainfall providers
 * exist, it stops before TensorFlow inference with INPUT_DATA_UNAVAILABLE.
 */
final class DrrmFloodRiskPredictionService
{
    private readonly string $barangayReferencePath;

    public function __construct(?string $barangayReferencePath = null)
    {
        $this->barangayReferencePath = $barangayReferencePath
            ?? __DIR__ . '/../../data/import/caloocan-barangays-current-unaffected.geojson';
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function requestPrediction(array $request): array
    {
        $allowed = ['location'];
        if (array_key_exists('request_id', $request)) {
            $allowed[] = 'request_id';
        }
        $actual = array_keys($request);
        sort($allowed);
        sort($actual);
        if ($actual !== $allowed || !is_array($request['location'] ?? null)
            || array_keys($request['location']) !== ['barangay_id']) {
            return $this->invalidRequest();
        }

        $requestId = $request['request_id'] ?? ('php-' . bin2hex(random_bytes(16)));
        $barangayId = $request['location']['barangay_id'] ?? null;
        if (!is_string($requestId)
            || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId) !== 1
            || !is_string($barangayId)
            || preg_match('/^\d{10}$/', $barangayId) !== 1) {
            return $this->invalidRequest();
        }

        try {
            $barangays = $this->loadValidatedBarangays();
        } catch (RuntimeException) {
            return [
                'available' => false,
                'code' => 'INPUT_DATA_UNAVAILABLE',
                'message' => 'The validated Caloocan location reference is unavailable.',
                'request_id' => $requestId,
                'location_status' => 'UNAVAILABLE',
                'weather_input_status' => 'UNAVAILABLE',
                'mgb_feature_status' => 'NOT_RESOLVED',
            ];
        }
        if (!array_key_exists($barangayId, $barangays)) {
            return $this->invalidRequest();
        }

        return [
            'available' => false,
            'code' => 'INPUT_DATA_UNAVAILABLE',
            'message' => 'Verified forecast and antecedent rainfall inputs are currently unavailable.',
            'request_id' => $requestId,
            'location_status' => 'VALIDATED_CURRENT_CALOOCAN_BARANGAY',
            'weather_input_status' => 'UNAVAILABLE',
            'mgb_feature_status' => 'PENDING_EXACT_LOCATION_RESOLUTION',
        ];
    }

    /** @return array<string, string> */
    private function loadValidatedBarangays(): array
    {
        $raw = file_get_contents($this->barangayReferencePath);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Barangay reference unavailable.');
        }
        try {
            $geojson = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Barangay reference invalid.', 0, $exception);
        }
        if (!is_array($geojson) || ($geojson['type'] ?? null) !== 'FeatureCollection'
            || !is_array($geojson['features'] ?? null) || count($geojson['features']) !== 187) {
            throw new RuntimeException('Barangay reference invalid.');
        }

        $barangays = [];
        foreach ($geojson['features'] as $feature) {
            $properties = is_array($feature) && is_array($feature['properties'] ?? null)
                ? $feature['properties']
                : null;
            $psgc = $properties['current_psgc_10_digit'] ?? null;
            $name = $properties['current_barangay_name'] ?? null;
            if (!is_string($psgc) || preg_match('/^\d{10}$/', $psgc) !== 1
                || !is_string($name) || trim($name) === '' || isset($barangays[$psgc])
                || preg_match('/^Barangay 176(?:-[A-F])?$/i', trim($name)) === 1) {
                throw new RuntimeException('Barangay reference invalid.');
            }
            $barangays[$psgc] = trim($name);
        }
        if (count($barangays) !== 187) {
            throw new RuntimeException('Barangay reference invalid.');
        }
        return $barangays;
    }

    /** @return array<string, mixed> */
    private function invalidRequest(): array
    {
        return [
            'available' => false,
            'code' => 'AI_REQUEST_INVALID',
            'message' => 'The flood-risk prediction request is invalid.',
        ];
    }
}
