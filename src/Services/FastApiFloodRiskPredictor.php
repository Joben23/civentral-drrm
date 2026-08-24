<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use JsonException;
use Throwable;

/**
 * FloodRiskPredictorInterface implementation backed only by private FastAPI.
 *
 * Inputs are expected from trusted PHP weather/GIS resolvers, never directly
 * from browser model fields. This class derives temporal features and emits no
 * fallback prediction when an input or upstream model is unavailable.
 */
final class FastApiFloodRiskPredictor implements FloodRiskPredictorInterface
{
    private const EXPECTED_FEATURE_ORDER = [
        'forecast_rainfall_24h_mm',
        'antecedent_rainfall_24h_mm',
        'antecedent_rainfall_72h_mm',
        'mgb_susceptibility_LF',
        'mgb_susceptibility_MF',
        'mgb_susceptibility_HF',
        'mgb_susceptibility_VHF',
        'mgb_susceptibility_NONE',
        'month_sin',
        'month_cos',
    ];

    private readonly string $featureSchemaPath;

    public function __construct(
        private readonly DrrmFloodRiskAiClient $client,
        ?string $featureSchemaPath = null
    ) {
        $this->featureSchemaPath = $featureSchemaPath
            ?? __DIR__ . '/../../ml/flood-risk/schemas/flood-feature-schema-v1.json';
    }

    public function isAvailable(): bool
    {
        $status = $this->client->ready();
        return ($status['available'] ?? false) === true
            && ($status['ready'] ?? false) === true
            && ($status['code'] ?? null) === 'READY';
    }

    public function predict(
        array $weatherInputs,
        array $locationContext,
        array $historicalInputs
    ): array {
        try {
            $this->assertSharedFeatureContract();
            $request = $this->buildRequest($weatherInputs, $locationContext, $historicalInputs);
        } catch (Throwable) {
            return [
                'available' => false,
                'code' => 'AI_REQUEST_INVALID',
                'message' => 'The flood-risk inference request is invalid.',
            ];
        }

        return $this->client->predictFloodRisk($request);
    }

    /**
     * @param array<string, mixed> $weather
     * @param array<string, mixed> $location
     * @param array<string, mixed> $history
     * @return array<string, mixed>
     */
    private function buildRequest(array $weather, array $location, array $history): array
    {
        $weatherKeys = [
            'forecast_rainfall_24h_mm', 'weather_issued_at', 'valid_from', 'valid_until',
        ];
        if (array_key_exists('request_id', $weather)) {
            $weatherKeys[] = 'request_id';
        }
        sort($weatherKeys);
        $actualWeatherKeys = array_keys($weather);
        sort($actualWeatherKeys);
        $locationKeys = array_keys($location);
        sort($locationKeys);
        $historyKeys = array_keys($history);
        sort($historyKeys);
        if ($actualWeatherKeys !== $weatherKeys
            || $locationKeys !== ['barangay_id', 'latitude', 'longitude', 'mapped_susceptibility']
            || $historyKeys !== ['antecedent_rainfall_24h_mm', 'antecedent_rainfall_72h_mm']) {
            throw new JsonException('Unexpected inference context fields.');
        }

        foreach ([
            $weather['forecast_rainfall_24h_mm'] ?? null,
            $history['antecedent_rainfall_24h_mm'] ?? null,
            $history['antecedent_rainfall_72h_mm'] ?? null,
        ] as $rainfall) {
            if ((!is_int($rainfall) && !is_float($rainfall))
                || !is_finite((float) $rainfall) || $rainfall < 0) {
                throw new JsonException('Invalid rainfall input.');
            }
        }

        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;
        if ((!is_int($latitude) && !is_float($latitude))
            || (!is_int($longitude) && !is_float($longitude))
            || !is_finite((float) $latitude) || !is_finite((float) $longitude)
            || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180
            || !is_string($location['barangay_id'] ?? null)
            || preg_match('/^\d{10}$/', $location['barangay_id']) !== 1) {
            throw new JsonException('Invalid location context.');
        }

        $susceptibility = is_string($location['mapped_susceptibility'] ?? null)
            ? strtoupper(trim($location['mapped_susceptibility']))
            : null;
        if (!in_array($susceptibility, ['LF', 'MF', 'HF', 'VHF', 'NONE'], true)) {
            throw new JsonException('Invalid MGB susceptibility context.');
        }

        $timestampPattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}'
            . '(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/';
        foreach (['weather_issued_at', 'valid_from', 'valid_until'] as $field) {
            if (!is_string($weather[$field] ?? null)
                || preg_match($timestampPattern, $weather[$field]) !== 1) {
                throw new JsonException('Invalid inference timestamp.');
            }
        }
        $issuedAt = new DateTimeImmutable($weather['weather_issued_at']);
        $validFrom = new DateTimeImmutable($weather['valid_from']);
        $validUntil = new DateTimeImmutable($weather['valid_until']);
        if ($validUntil->getTimestamp() - $validFrom->getTimestamp() !== 86400
            || $issuedAt > $validFrom) {
            throw new JsonException('Invalid inference validity window.');
        }

        $requestId = $weather['request_id'] ?? ('php-' . bin2hex(random_bytes(16)));
        if (!is_string($requestId)
            || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId) !== 1) {
            throw new JsonException('Invalid request identifier.');
        }
        $angle = 2.0 * M_PI * ($validFrom->format('n') - 1) / 12.0;

        return [
            'schema_version' => DrrmFloodRiskAiClient::REQUEST_SCHEMA_VERSION,
            'request_id' => $requestId,
            'prediction_type' => DrrmFloodRiskAiClient::PREDICTION_TYPE,
            'valid_from' => $weather['valid_from'],
            'valid_until' => $weather['valid_until'],
            'location' => ['barangay_id' => $location['barangay_id']],
            'features' => [
                'forecast_rainfall_24h_mm' => (float) $weather['forecast_rainfall_24h_mm'],
                'antecedent_rainfall_24h_mm' => (float) $history['antecedent_rainfall_24h_mm'],
                'antecedent_rainfall_72h_mm' => (float) $history['antecedent_rainfall_72h_mm'],
                'mgb_flood_susceptibility_code' => $susceptibility,
                'month_sin' => sin($angle),
                'month_cos' => cos($angle),
            ],
            'source_context' => [
                'weather_issued_at' => $weather['weather_issued_at'],
                'feature_schema_version' => DrrmFloodRiskAiClient::FEATURE_SCHEMA_VERSION,
            ],
        ];
    }

    private function assertSharedFeatureContract(): void
    {
        $raw = file_get_contents($this->featureSchemaPath);
        if (!is_string($raw) || $raw === '') {
            throw new JsonException('The shared feature schema is unavailable.');
        }
        $schema = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($schema)
            || ($schema['schema_version'] ?? null) !== DrrmFloodRiskAiClient::FEATURE_SCHEMA_VERSION
            || ($schema['input_shape'] ?? null) !== 10
            || !is_array($schema['features_in_order'] ?? null)) {
            throw new JsonException('The shared feature schema is incompatible.');
        }
        $names = [];
        foreach ($schema['features_in_order'] as $feature) {
            if (!is_array($feature) || !is_string($feature['name'] ?? null)) {
                throw new JsonException('The shared feature schema is incompatible.');
            }
            $names[] = $feature['name'];
        }
        if ($names !== self::EXPECTED_FEATURE_ORDER) {
            throw new JsonException('The shared feature order is incompatible.');
        }
    }
}
