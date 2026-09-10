<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AiServiceConfig;
use JsonException;
use Throwable;

/**
 * Server-only client for the private research rainfall-regression endpoint.
 * It deliberately has no flood, MGB, warning, or risk-category semantics.
 */
final class DrrmRainfallInferenceClient
{
    public const REQUEST_SCHEMA_VERSION = '1.0';
    public const MODEL_VERSION = 'rainfall-regression-dense-57-v0.1.1-softplus-candidate';
    public const MODEL_PROBLEM = 'RAINFALL_REGRESSION';
    public const TARGET = 'NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM';
    public const OUTPUT_POLICY = 'MODEL_NONNEGATIVE_SOFTPLUS';

    public function __construct(
        private readonly AiServiceConfig $config,
        ?DrrmAiHttpTransportInterface $transport = null
    ) {
        $this->transport = $transport ?? new DrrmCurlAiHttpTransport();
    }

    private readonly DrrmAiHttpTransportInterface $transport;

    /** @return array<string, mixed> */
    public function ready(): array
    {
        $requestId = $this->requestId();
        $response = $this->send('GET', '/rainfall/ready', null, true, $requestId);
        if (!array_is_list($response)) {
            return $response;
        }
        [$http, $payload] = $response;
        if (!is_bool($payload['success'] ?? null)
            || !is_bool($payload['ready'] ?? null)
            || ($payload['model_problem'] ?? null) !== self::MODEL_PROBLEM
            || !is_string($payload['code'] ?? null)
            || !is_string($payload['authorization_status'] ?? null)) {
            return $this->invalidResponse($requestId, $http->latencyMs);
        }
        if ($http->statusCode === 200 && $payload['ready'] === true) {
            if ($payload['success'] !== true
                || $payload['code'] !== 'RAINFALL_RESEARCH_READY'
                || ($payload['model_version'] ?? null) !== self::MODEL_VERSION
                || ($payload['model_status'] ?? null) !== 'VALIDATED_RESEARCH_CANDIDATE'
                || ($payload['authorization_status'] ?? null) !== 'APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY') {
                return $this->invalidResponse($requestId, $http->latencyMs);
            }
        }

        $code = $this->safeCode($payload['code']);
        $result = [
            'available' => $http->statusCode === 200 && $payload['ready'] === true,
            'ready' => $payload['ready'],
            'code' => $code,
            'message' => $this->messageForCode($code),
            'model_problem' => self::MODEL_PROBLEM,
            'model_version' => $this->identifier($payload['model_version'] ?? null),
            'model_status' => $this->identifier($payload['model_status'] ?? null),
            'authorization_status' => $payload['authorization_status'],
        ];
        $this->logOutcome($requestId, $code, $http->latencyMs);
        return $result;
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function predict(array $request): array
    {
        $requestId = is_string($request['request_id'] ?? null)
            ? trim($request['request_id'])
            : $this->requestId();
        if (!$this->validRequest($request)) {
            return $this->failure('AI_REQUEST_INVALID', $requestId);
        }
        if (!$this->config->hasInternalKey()) {
            return $this->failure('AI_SERVICE_NOT_CONFIGURED', $requestId);
        }

        $response = $this->send('POST', '/rainfall/predict', $request, true, $requestId);
        if (!array_is_list($response)) {
            return $response;
        }
        [$http, $payload] = $response;
        if ($http->statusCode !== 200 || ($payload['request_id'] ?? null) !== $requestId) {
            return $this->httpFailure($http, $payload, $requestId);
        }
        if (!$this->validResponse($payload)) {
            return $this->invalidResponse($requestId, $http->latencyMs);
        }

        $result = [
            'available' => true,
            'code' => 'RAINFALL_PREDICTION_AVAILABLE',
            'message' => 'Private research rainfall regression result is available.',
            'schema_version' => self::REQUEST_SCHEMA_VERSION,
            'request_id' => $payload['request_id'],
            'model_problem' => self::MODEL_PROBLEM,
            'forecast_origin_utc' => $payload['forecast_origin_utc'],
            'forecast_horizon_hours' => 3,
            'target' => self::TARGET,
            'raw_prediction_mm' => (float) $payload['raw_prediction_mm'],
            'final_prediction_mm' => (float) $payload['final_prediction_mm'],
            'output_policy' => self::OUTPUT_POLICY,
            'model_version' => self::MODEL_VERSION,
            'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
            'operational' => false,
        ];
        $this->logOutcome($requestId, 'RAINFALL_PREDICTION_AVAILABLE', $http->latencyMs);
        return $result;
    }

    /** @return array{0: DrrmAiHttpResponse, 1: array<string, mixed>}|array<string, mixed> */
    private function send(string $method, string $path, ?array $payload, bool $authenticated, string $requestId): array
    {
        $headers = ['Accept: application/json', 'X-Request-ID: ' . $requestId];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($authenticated) {
            $key = $this->config->internalKey();
            if ($key === null) {
                return $this->failure('AI_SERVICE_NOT_CONFIGURED', $requestId);
            }
            $headers[] = 'X-CIVENTRAL-AI-Key: ' . $key;
        }

        try {
            $response = $this->transport->request(
                $method,
                $this->config->baseUrl() . $path,
                $headers,
                $payload,
                $this->config->connectTimeoutMs(),
                $this->config->requestTimeoutMs()
            );
        } catch (DrrmAiTransportException $exception) {
            $code = $exception->reason === DrrmAiTransportException::TIMEOUT
                ? 'AI_SERVICE_TIMEOUT'
                : 'AI_SERVICE_UNREACHABLE';
            return $this->failure($code, $requestId);
        } catch (Throwable) {
            return $this->failure('AI_SERVICE_ERROR', $requestId);
        }

        try {
            $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->invalidResponse($requestId, $response->latencyMs);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return $this->invalidResponse($requestId, $response->latencyMs);
        }
        return [$response, $decoded];
    }

    /** @param array<string, mixed> $request */
    private function validRequest(array $request): bool
    {
        $requiredKeys = ['schema_version', 'request_id', 'history'];
        $providedKeys = array_keys($request);
        sort($requiredKeys);
        sort($providedKeys);
        if ($requiredKeys !== $providedKeys
            || $request['schema_version'] !== self::REQUEST_SCHEMA_VERSION
            || !is_string($request['request_id'])
            || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $request['request_id']) !== 1
            || !is_array($request['history'])
            || count($request['history']) !== 48) {
            return false;
        }
        $previous = null;
        foreach ($request['history'] as $observation) {
            if (!is_array($observation)) {
                return false;
            }
            $observationKeys = array_keys($observation);
            $expectedKeys = ['timestamp_utc', 'city_mean_precipitation_mm'];
            sort($observationKeys);
            sort($expectedKeys);
            if ($observationKeys !== $expectedKeys
                || !is_string($observation['timestamp_utc'])
                || (!is_int($observation['city_mean_precipitation_mm']) && !is_float($observation['city_mean_precipitation_mm']))
                || !is_finite((float) $observation['city_mean_precipitation_mm'])
                || $observation['city_mean_precipitation_mm'] < 0) {
                return false;
            }
            try {
                $timestamp = new \DateTimeImmutable($observation['timestamp_utc']);
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

    /** @param array<string, mixed> $payload */
    private function validResponse(array $payload): bool
    {
        $required = ['schema_version', 'request_id', 'model_problem', 'forecast_origin_utc', 'forecast_horizon_hours', 'target', 'raw_prediction_mm', 'final_prediction_mm', 'output_policy', 'model_version', 'model_status', 'operational'];
        $providedKeys = array_keys($payload);
        sort($required);
        sort($providedKeys);
        if ($required !== $providedKeys) {
            return false;
        }
        foreach (['raw_prediction_mm', 'final_prediction_mm'] as $field) {
            if ((!is_int($payload[$field]) && !is_float($payload[$field])) || !is_finite((float) $payload[$field]) || $payload[$field] < 0) {
                return false;
            }
        }
        if (!is_string($payload['forecast_origin_utc'])) {
            return false;
        }
        try {
            $forecastOrigin = new \DateTimeImmutable($payload['forecast_origin_utc']);
            if ($forecastOrigin->getTimezone()->getOffset($forecastOrigin) !== 0) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }
        return $payload['schema_version'] === self::REQUEST_SCHEMA_VERSION
            && $payload['model_problem'] === self::MODEL_PROBLEM
            && $payload['forecast_horizon_hours'] === 3
            && $payload['target'] === self::TARGET
            && $payload['output_policy'] === self::OUTPUT_POLICY
            && $payload['model_version'] === self::MODEL_VERSION
            && $payload['model_status'] === 'VALIDATED_RESEARCH_CANDIDATE'
            && $payload['operational'] === false;
    }

    /** @param array<string, mixed> $payload */
    private function httpFailure(DrrmAiHttpResponse $http, array $payload, string $requestId): array
    {
        $code = match ($http->statusCode) {
            401, 403 => 'UNAUTHORIZED',
            422 => 'AI_REQUEST_INVALID',
            503 => $this->safeCode($payload['code'] ?? 'MODEL_UNAVAILABLE'),
            500 => 'AI_SERVICE_ERROR',
            default => 'AI_SERVICE_ERROR',
        };
        return $this->failure($code, $requestId);
    }

    /** @return array<string, mixed> */
    private function invalidResponse(string $requestId, ?float $latencyMs = null): array
    {
        return $this->failure('AI_SERVICE_INVALID_RESPONSE', $requestId);
    }

    /** @return array<string, mixed> */
    private function failure(string $code, string $requestId): array
    {
        $this->logOutcome($requestId, $code, null);
        return ['available' => false, 'code' => $code, 'message' => $this->messageForCode($code), 'request_id' => $requestId];
    }

    private function safeCode(mixed $code): string
    {
        return is_string($code) && preg_match('/^[A-Z0-9_]{2,80}$/', $code) === 1 ? $code : 'AI_SERVICE_ERROR';
    }

    private function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) === 1 ? $value : null;
    }

    private function messageForCode(string $code): string
    {
        return match ($code) {
            'UNAUTHORIZED' => 'Private AI service authentication failed.',
            'AI_SERVICE_NOT_CONFIGURED' => 'The private AI service is not configured.',
            'AI_SERVICE_TIMEOUT' => 'The private AI service timed out.',
            'AI_SERVICE_UNREACHABLE' => 'The private AI service is unavailable.',
            'AI_REQUEST_INVALID' => 'The rainfall inference request is invalid.',
            'MODEL_INFERENCE_NOT_APPROVED' => 'Private rainfall inference is not approved.',
            'MODEL_UNAVAILABLE' => 'Private rainfall inference is unavailable.',
            default => 'The private rainfall inference service could not complete the request.',
        };
    }

    private function requestId(): string
    {
        return 'php-rainfall-' . bin2hex(random_bytes(16));
    }

    private function logOutcome(string $requestId, string $code, ?float $latencyMs): void
    {
        $record = ['event' => 'civentral_rainfall_inference_request', 'request_id' => $requestId, 'result_code' => $code];
        if ($latencyMs !== null) {
            $record['latency_ms'] = round($latencyMs, 3);
        }
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($encoded)) {
            error_log($encoded);
        }
    }
}
