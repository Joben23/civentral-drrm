<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use Throwable;

/**
 * Trusted server-side orchestration for research-only rainfall inference.
 * It accepts history only from a provider and never accepts browser/model fields.
 */
final class DrrmRainfallPredictionService
{
    private const EXPECTED_SOURCE_SEMANTICS = [
        'measurement' => 'city_mean_precipitation_mm',
        'unit' => 'millimetres',
        'accumulation_interval_minutes' => 30,
        'geographic_scope' => 'Caloocan City mean',
        'timestamp_meaning' => 'observation_interval_start_utc',
    ];

    public function __construct(
        private readonly DrrmRainfallHistoryProviderInterface $historyProvider,
        private readonly DrrmRainfallInferenceClient $inferenceClient
    ) {
    }

    /** @return array<string, mixed> */
    public function predict(?string $requestId = null): array
    {
        $requestId = $this->safeRequestId($requestId);
        try {
            $provided = $this->historyProvider->history();
        } catch (Throwable) {
            return $this->failure('RAINFALL_HISTORY_UNAVAILABLE', $requestId, 'The trusted rainfall history source is unavailable.');
        }

        if (!$this->validProviderResult($provided)) {
            return $this->failure('RAINFALL_HISTORY_INVALID', $requestId, 'The trusted rainfall history response is invalid.');
        }
        if ($provided['status'] === 'UNAVAILABLE') {
            return $this->failure('RAINFALL_HISTORY_UNAVAILABLE', $requestId, 'The trusted rainfall history source is unavailable.');
        }
        if ($provided['status'] === 'INCOMPATIBLE' || !$this->compatibleSemantics($provided['source_semantics'])) {
            return $this->failure('RAINFALL_HISTORY_INCOMPATIBLE', $requestId, 'The trusted rainfall history semantics are incompatible with the rainfall model.');
        }
        if (!$this->validHistory($provided['history'])) {
            return $this->failure('RAINFALL_HISTORY_INVALID', $requestId, 'The trusted rainfall history is incomplete or malformed.');
        }

        $result = $this->inferenceClient->predict([
            'schema_version' => DrrmRainfallInferenceClient::REQUEST_SCHEMA_VERSION,
            'request_id' => $requestId,
            'history' => $provided['history'],
        ]);
        if (($result['request_id'] ?? null) !== $requestId) {
            $result['request_id'] = $requestId;
        }
        if (($result['available'] ?? false) !== true) {
            return $result;
        }

        $result['history_source'] = $provided['source_id'];
        return $result;
    }

    /** @param array<string, mixed> $provided */
    private function validProviderResult(array $provided): bool
    {
        $required = ['status', 'source_id', 'source_semantics', 'history'];
        $actual = array_keys($provided);
        sort($required);
        sort($actual);
        return $required === $actual
            && in_array($provided['status'], ['AVAILABLE', 'UNAVAILABLE', 'INCOMPATIBLE'], true)
            && is_string($provided['source_id'])
            && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $provided['source_id']) === 1
            && ($provided['source_semantics'] === null || is_array($provided['source_semantics']))
            && ($provided['history'] === null || (is_array($provided['history']) && array_is_list($provided['history'])));
    }

    /** @param array<string, mixed>|null $semantics */
    private function compatibleSemantics(?array $semantics): bool
    {
        if ($semantics === null) {
            return false;
        }
        $expected = self::EXPECTED_SOURCE_SEMANTICS;
        ksort($expected);
        ksort($semantics);
        return $semantics === $expected;
    }

    /** @param list<array<string, mixed>>|null $history */
    private function validHistory(?array $history): bool
    {
        if ($history === null || count($history) !== 48) {
            return false;
        }

        $previous = null;
        foreach ($history as $observation) {
            if (!is_array($observation)) {
                return false;
            }
            $required = ['timestamp_utc', 'city_mean_precipitation_mm'];
            $actual = array_keys($observation);
            sort($required);
            sort($actual);
            $rainfall = $observation['city_mean_precipitation_mm'] ?? null;
            if ($required !== $actual
                || !is_string($observation['timestamp_utc'] ?? null)
                || (!is_int($rainfall) && !is_float($rainfall))
                || !is_finite((float) $rainfall)
                || $rainfall < 0) {
                return false;
            }
            try {
                $timestamp = new DateTimeImmutable($observation['timestamp_utc']);
            } catch (Throwable) {
                return false;
            }
            if ($timestamp->getTimezone()->getOffset($timestamp) !== 0
                || ($previous !== null && $timestamp->getTimestamp() - $previous->getTimestamp() !== 1800)) {
                return false;
            }
            $previous = $timestamp;
        }
        return true;
    }

    private function safeRequestId(?string $requestId): string
    {
        if ($requestId !== null && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId) === 1) {
            return $requestId;
        }
        return 'php-rainfall-' . bin2hex(random_bytes(16));
    }

    /** @return array<string, mixed> */
    private function failure(string $code, string $requestId, string $message): array
    {
        error_log(json_encode([
            'event' => 'civentral_rainfall_prediction_workflow',
            'request_id' => $requestId,
            'result_code' => $code,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
        return [
            'available' => false,
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];
    }
}
