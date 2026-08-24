<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AiServiceConfig;
use DateTimeImmutable;
use JsonException;
use RuntimeException;
use Throwable;

final class DrrmAiTransportException extends RuntimeException
{
    public const TIMEOUT = 'TIMEOUT';
    public const UNREACHABLE = 'UNREACHABLE';
    public const INVALID_RESPONSE = 'INVALID_RESPONSE';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('The private AI service transport failed.');
    }
}

final class DrrmAiHttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly float $latencyMs
    ) {
    }
}

interface DrrmAiHttpTransportInterface
{
    /** @param list<string> $headers @param array<string, mixed>|null $payload */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?array $payload,
        int $connectTimeoutMs,
        int $requestTimeoutMs
    ): DrrmAiHttpResponse;
}

/** cURL transport confined to the validated server-side AI base URL. */
final class DrrmCurlAiHttpTransport implements DrrmAiHttpTransportInterface
{
    private const MAX_RESPONSE_BYTES = 65536;

    public function request(
        string $method,
        string $url,
        array $headers,
        ?array $payload,
        int $connectTimeoutMs,
        int $requestTimeoutMs
    ): DrrmAiHttpResponse {
        if (!extension_loaded('curl')) {
            throw new DrrmAiTransportException(DrrmAiTransportException::UNREACHABLE);
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new DrrmAiTransportException(DrrmAiTransportException::INVALID_RESPONSE);
        }

        $encodedPayload = null;
        if ($payload !== null) {
            try {
                $encodedPayload = json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                throw new DrrmAiTransportException(DrrmAiTransportException::INVALID_RESPONSE);
            }
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new DrrmAiTransportException(DrrmAiTransportException::UNREACHABLE);
        }

        $responseBody = '';
        $responseTooLarge = false;
        $started = hrtime(true);
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $requestTimeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'CIVENTRAL-DRRM/1.0',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (
                &$responseBody,
                &$responseTooLarge
            ): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $responseTooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($encodedPayload !== null) {
            $options[CURLOPT_POSTFIELDS] = $encodedPayload;
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);
        $executed = curl_exec($handle);
        $latencyMs = (hrtime(true) - $started) / 1_000_000;
        if ($executed === false) {
            $curlCode = curl_errno($handle);
            curl_close($handle);
            if ($responseTooLarge) {
                throw new DrrmAiTransportException(DrrmAiTransportException::INVALID_RESPONSE);
            }
            throw new DrrmAiTransportException(
                $curlCode === CURLE_OPERATION_TIMEDOUT
                    ? DrrmAiTransportException::TIMEOUT
                    : DrrmAiTransportException::UNREACHABLE
            );
        }
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new DrrmAiHttpResponse($statusCode, $responseBody, $latencyMs);
    }
}

/**
 * Strict server-to-server client for the private FastAPI inference runtime.
 *
 * Returned arrays are allowlisted application states. Raw response fields,
 * internal headers, private URLs, and upstream exception details are omitted.
 */
final class DrrmFloodRiskAiClient
{
    public const REQUEST_SCHEMA_VERSION = '1.0';
    public const FEATURE_SCHEMA_VERSION = '1.0.0';
    public const PREDICTION_TYPE = 'FLOOD_WITHIN_24H';

    private const MODEL_STATES = [
        'MODEL_NOT_AVAILABLE',
        'MODEL_INVALID',
        'MODEL_AVAILABLE_NOT_OPERATIONALLY_VALIDATED',
        'MODEL_READY',
    ];
    private const RISK_POLICY_STATES = [
        'NOT_CONFIGURED', 'INVALID', 'AVAILABLE_NOT_APPROVED', 'READY',
    ];

    private readonly DrrmAiHttpTransportInterface $transport;

    public function __construct(
        private readonly AiServiceConfig $config,
        ?DrrmAiHttpTransportInterface $transport = null
    ) {
        $this->transport = $transport ?? new DrrmCurlAiHttpTransport();
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $requestId = $this->requestId();
        $response = $this->send('GET', '/health', null, false, $requestId);
        if (!array_is_list($response)) {
            return $response;
        }
        [$http, $payload] = $response;
        if ($http->statusCode !== 200
            || ($payload['success'] ?? null) !== true
            || ($payload['service_status'] ?? null) !== 'HEALTHY'
            || !is_bool($payload['tensorflow_installed'] ?? null)
            || !$this->validModelState($payload['model_status'] ?? null)
            || !$this->validRiskPolicyState($payload['risk_policy_status'] ?? null)) {
            return $this->invalidResponse($requestId, $http->latencyMs);
        }

        $result = [
            'available' => true,
            'code' => 'HEALTHY',
            'message' => 'Private AI service runtime is healthy.',
            'runtime_reachable' => true,
            'service_healthy' => true,
            'tensorflow_installed' => $payload['tensorflow_installed'],
            'model_status' => $payload['model_status'],
            'risk_policy_status' => $payload['risk_policy_status'],
        ];
        $this->logOutcome($requestId, 'HEALTHY', $http->latencyMs);
        return $result;
    }

    /** @return array<string, mixed> */
    public function ready(): array
    {
        $requestId = $this->requestId();
        $response = $this->send('GET', '/ready', null, false, $requestId);
        if (!array_is_list($response)) {
            return $response;
        }
        [$http, $payload] = $response;

        if ($http->statusCode === 200
            && ($payload['success'] ?? null) === true
            && ($payload['ready'] ?? null) === true
            && ($payload['code'] ?? null) === 'READY'
            && $this->validModelState($payload['model_status'] ?? null)
            && $this->validRiskPolicyState($payload['risk_policy_status'] ?? null)) {
            $result = [
                'available' => true,
                'ready' => true,
                'code' => 'READY',
                'message' => 'Approved flood-risk inference is available.',
                'model_status' => $payload['model_status'],
                'risk_policy_status' => $payload['risk_policy_status'],
            ];
            $this->logOutcome($requestId, 'READY', $http->latencyMs);
            return $result;
        }

        if ($http->statusCode === 503
            && ($payload['success'] ?? null) === false
            && ($payload['ready'] ?? null) === false
            && is_string($payload['code'] ?? null)
            && $this->validModelState($payload['model_status'] ?? null)
            && $this->validRiskPolicyState($payload['risk_policy_status'] ?? null)) {
            $code = $this->normalizeUpstreamCode($payload['code']);
            $result = [
                'available' => false,
                'ready' => false,
                'code' => $code,
                'message' => $this->messageForCode($code),
                'model_status' => $payload['model_status'],
                'risk_policy_status' => $payload['risk_policy_status'],
            ];
            $this->logOutcome($requestId, $code, $http->latencyMs);
            return $result;
        }

        return $this->httpFailure($http, $payload, $requestId);
    }

    /** @return array<string, mixed> */
    public function modelStatus(): array
    {
        $requestId = $this->requestId();
        if (!$this->config->hasInternalKey()) {
            return $this->notConfigured($requestId);
        }
        $response = $this->send('GET', '/v1/model/status', null, true, $requestId);
        if (!array_is_list($response)) {
            return $response;
        }
        [$http, $payload] = $response;
        if ($http->statusCode !== 200
            || ($payload['success'] ?? null) !== true
            || !is_bool($payload['model_available'] ?? null)
            || !is_bool($payload['approved_for_inference'] ?? null)
            || !is_bool($payload['tensorflow_installed'] ?? null)
            || ($payload['feature_schema_version'] ?? null) !== self::FEATURE_SCHEMA_VERSION
            || !$this->validModelState($payload['model_status'] ?? null)) {
            return $this->httpFailure($http, $payload, $requestId);
        }

        $modelVersion = $this->nullableIdentifier($payload['model_version'] ?? null);
        $policyVersion = $this->nullableIdentifier($payload['threshold_policy_version'] ?? null);
        if (
            (($payload['model_version'] ?? null) !== null && $modelVersion === null)
            || (($payload['threshold_policy_version'] ?? null) !== null && $policyVersion === null)
        ) {
            return $this->invalidResponse($requestId, $http->latencyMs);
        }

        $code = (string) $payload['model_status'];
        $result = [
            'available' => $payload['model_available'],
            'code' => $code,
            'message' => $this->messageForCode($code),
            'model_status' => $code,
            'approved_for_inference' => $payload['approved_for_inference'],
            'tensorflow_installed' => $payload['tensorflow_installed'],
            'model_version' => $modelVersion,
            'threshold_policy_version' => $policyVersion,
            'feature_schema_version' => self::FEATURE_SCHEMA_VERSION,
        ];
        $this->logOutcome($requestId, $code, $http->latencyMs);
        return $result;
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function predictFloodRisk(array $request): array
    {
        $requestId = is_string($request['request_id'] ?? null)
            ? trim($request['request_id'])
            : $this->requestId();
        if (!$this->validPredictionRequest($request)) {
            return [
                'available' => false,
                'code' => 'AI_REQUEST_INVALID',
                'message' => $this->messageForCode('AI_REQUEST_INVALID'),
            ];
        }
        if (!$this->config->hasInternalKey()) {
            return $this->notConfigured($requestId);
        }

        $response = $this->send(
            'POST',
            '/v1/predictions/flood-risk',
            $request,
            true,
            $requestId
        );
        if (!array_is_list($response)) {
            return $response;
        }
        [$http, $payload] = $response;

        if ($http->statusCode === 503 && ($payload['success'] ?? null) === false
            && is_string($payload['code'] ?? null)) {
            $code = $this->normalizeUpstreamCode($payload['code']);
            if (in_array($code, [
                'MODEL_NOT_AVAILABLE', 'MODEL_INVALID', 'RISK_POLICY_NOT_CONFIGURED',
                'AI_SERVICE_NOT_CONFIGURED',
            ], true)) {
                $result = [
                    'available' => false,
                    'code' => $code,
                    'message' => $this->messageForCode($code),
                ];
                $this->logOutcome($requestId, $code, $http->latencyMs);
                return $result;
            }
        }
        if ($http->statusCode !== 200 || ($payload['success'] ?? null) !== true) {
            return $this->httpFailure($http, $payload, $requestId);
        }

        $result = $this->normalizePredictionSuccess($payload);
        if ($result === null) {
            return $this->invalidResponse($requestId, $http->latencyMs);
        }
        $this->logOutcome($requestId, 'PREDICTION_AVAILABLE', $http->latencyMs);
        return $result;
    }

    /**
     * @return array{0: DrrmAiHttpResponse, 1: array<string, mixed>}|array<string, mixed>
     */
    private function send(
        string $method,
        string $path,
        ?array $payload,
        bool $authenticated,
        string $requestId
    ): array {
        $headers = ['Accept: application/json', 'X-Request-ID: ' . $requestId];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($authenticated) {
            $key = $this->config->internalKey();
            if ($key === null) {
                return $this->notConfigured($requestId);
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
            $code = $exception->reason === DrrmAiTransportException::INVALID_RESPONSE
                ? 'AI_SERVICE_INVALID_RESPONSE'
                : 'AI_SERVICE_UNREACHABLE';
            $this->logOutcome($requestId, $code, null);
            return [
                'available' => false,
                'code' => $code,
                'message' => $this->messageForCode($code),
            ];
        } catch (Throwable) {
            $this->logOutcome($requestId, 'AI_SERVICE_ERROR', null);
            return [
                'available' => false,
                'code' => 'AI_SERVICE_ERROR',
                'message' => $this->messageForCode('AI_SERVICE_ERROR'),
            ];
        }

        if ($response->body === '') {
            return $this->invalidResponse($requestId, $response->latencyMs);
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

    /** @param array<string, mixed> $payload @return array<string, mixed>|null */
    private function normalizePredictionSuccess(array $payload): ?array
    {
        $requiredStrings = [
            'schema_version', 'request_id', 'prediction_type', 'model_version',
            'model_status', 'predicted_outcome', 'threshold_policy_version',
            'civentral_risk_level', 'predicted_at', 'valid_from', 'valid_until',
        ];
        foreach ($requiredStrings as $field) {
            if (!is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
                return null;
            }
        }
        $probability = $payload['probability'] ?? null;
        if ((!is_int($probability) && !is_float($probability))
            || !is_finite((float) $probability) || $probability < 0 || $probability > 1
            || $payload['schema_version'] !== self::REQUEST_SCHEMA_VERSION
            || $payload['prediction_type'] !== self::PREDICTION_TYPE
            || $payload['model_status'] !== 'MODEL_READY'
            || !in_array($payload['predicted_outcome'], ['FLOOD', 'NO_FLOOD'], true)
            || !in_array($payload['civentral_risk_level'], ['LOW', 'MODERATE', 'HIGH', 'CRITICAL'], true)
            || !is_array($payload['limitations'] ?? null)
            || !array_is_list($payload['limitations'])) {
            return null;
        }
        foreach ($payload['limitations'] as $limitation) {
            if (!is_string($limitation) || trim($limitation) === '' || strlen($limitation) > 500) {
                return null;
            }
        }

        return [
            'available' => true,
            'code' => 'PREDICTION_AVAILABLE',
            'message' => 'TensorFlow flood-risk decision support is available for officer review.',
            'schema_version' => self::REQUEST_SCHEMA_VERSION,
            'request_id' => $payload['request_id'],
            'prediction_type' => self::PREDICTION_TYPE,
            'model_version' => $payload['model_version'],
            'model_status' => 'MODEL_READY',
            'probability' => (float) $probability,
            'predicted_outcome' => $payload['predicted_outcome'],
            'threshold_policy_version' => $payload['threshold_policy_version'],
            'civentral_risk_level' => $payload['civentral_risk_level'],
            'predicted_at' => $payload['predicted_at'],
            'valid_from' => $payload['valid_from'],
            'valid_until' => $payload['valid_until'],
            'limitations' => array_values($payload['limitations']),
        ];
    }

    /** @param array<string, mixed> $request */
    private function validPredictionRequest(array $request): bool
    {
        $required = [
            'schema_version', 'request_id', 'prediction_type', 'valid_from',
            'valid_until', 'location', 'features', 'source_context',
        ];
        if (array_keys($request) !== $required
            || $request['schema_version'] !== self::REQUEST_SCHEMA_VERSION
            || $request['prediction_type'] !== self::PREDICTION_TYPE
            || !is_string($request['request_id'])
            || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $request['request_id']) !== 1
            || !is_string($request['valid_from']) || !is_string($request['valid_until'])) {
            return false;
        }

        $location = $request['location'];
        $features = $request['features'];
        $source = $request['source_context'];
        if (!is_array($location) || array_keys($location) !== ['barangay_id']
            || !is_string($location['barangay_id'])
            || preg_match('/^\d{10}$/', $location['barangay_id']) !== 1
            || !is_array($features) || array_keys($features) !== [
                'forecast_rainfall_24h_mm', 'antecedent_rainfall_24h_mm',
                'antecedent_rainfall_72h_mm', 'mgb_flood_susceptibility_code',
                'month_sin', 'month_cos',
            ]
            || !is_array($source) || array_keys($source) !== [
                'weather_issued_at', 'feature_schema_version',
            ]
            || $source['feature_schema_version'] !== self::FEATURE_SCHEMA_VERSION
            || !is_string($source['weather_issued_at'])) {
            return false;
        }

        foreach ([
            'forecast_rainfall_24h_mm', 'antecedent_rainfall_24h_mm',
            'antecedent_rainfall_72h_mm',
        ] as $field) {
            $value = $features[$field] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
                return false;
            }
        }
        foreach (['month_sin', 'month_cos'] as $field) {
            $value = $features[$field] ?? null;
            if ((!is_int($value) && !is_float($value))
                || !is_finite((float) $value) || $value < -1 || $value > 1) {
                return false;
            }
        }
        if (!in_array($features['mgb_flood_susceptibility_code'] ?? null, [
            'LF', 'MF', 'HF', 'VHF', 'NONE',
        ], true)) {
            return false;
        }

        if (
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $request['valid_from']) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $request['valid_until']) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $source['weather_issued_at']) !== 1
        ) {
            return false;
        }

        try {
            $validFrom = new DateTimeImmutable($request['valid_from']);
            $validUntil = new DateTimeImmutable($request['valid_until']);
            $issuedAt = new DateTimeImmutable($source['weather_issued_at']);
        } catch (Throwable) {
            return false;
        }
        return $validUntil->getTimestamp() - $validFrom->getTimestamp() === 86400
            && $issuedAt <= $validFrom;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function httpFailure(
        DrrmAiHttpResponse $http,
        array $payload,
        string $requestId
    ): array {
        if (in_array($http->statusCode, [401, 403], true)) {
            $code = 'AI_AUTHENTICATION_FAILED';
        } elseif ($http->statusCode === 422) {
            $code = 'AI_REQUEST_INVALID';
        } elseif ($http->statusCode >= 500 && $http->statusCode <= 599) {
            $code = is_string($payload['code'] ?? null)
                ? $this->normalizeUpstreamCode($payload['code'])
                : 'AI_SERVICE_ERROR';
        } else {
            $code = 'AI_SERVICE_INVALID_RESPONSE';
        }
        if (!in_array($code, [
            'AI_SERVICE_NOT_CONFIGURED', 'MODEL_NOT_AVAILABLE', 'MODEL_INVALID',
            'RISK_POLICY_NOT_CONFIGURED', 'AI_REQUEST_INVALID',
            'AI_AUTHENTICATION_FAILED', 'AI_SERVICE_ERROR',
        ], true)) {
            $code = 'AI_SERVICE_ERROR';
        }
        $this->logOutcome($requestId, $code, $http->latencyMs);
        return [
            'available' => false,
            'code' => $code,
            'message' => $this->messageForCode($code),
        ];
    }

    /** @return array<string, mixed> */
    private function invalidResponse(string $requestId, ?float $latencyMs): array
    {
        $this->logOutcome($requestId, 'AI_SERVICE_INVALID_RESPONSE', $latencyMs);
        return [
            'available' => false,
            'code' => 'AI_SERVICE_INVALID_RESPONSE',
            'message' => $this->messageForCode('AI_SERVICE_INVALID_RESPONSE'),
        ];
    }

    /** @return array<string, mixed> */
    private function notConfigured(string $requestId): array
    {
        $this->logOutcome($requestId, 'AI_SERVICE_NOT_CONFIGURED', null);
        return [
            'available' => false,
            'code' => 'AI_SERVICE_NOT_CONFIGURED',
            'message' => $this->messageForCode('AI_SERVICE_NOT_CONFIGURED'),
        ];
    }

    private function normalizeUpstreamCode(string $code): string
    {
        return match ($code) {
            'MODEL_NOT_AVAILABLE' => 'MODEL_NOT_AVAILABLE',
            'MODEL_INVALID' => 'MODEL_INVALID',
            'RISK_POLICY_NOT_READY', 'RISK_POLICY_NOT_CONFIGURED' => 'RISK_POLICY_NOT_CONFIGURED',
            'INTERNAL_AUTH_NOT_CONFIGURED' => 'AI_SERVICE_NOT_CONFIGURED',
            'INVALID_REQUEST' => 'AI_REQUEST_INVALID',
            default => 'AI_SERVICE_ERROR',
        };
    }

    private function messageForCode(string $code): string
    {
        return match ($code) {
            'MODEL_NOT_AVAILABLE' => 'TensorFlow flood-risk prediction is currently unavailable.',
            'MODEL_INVALID' => 'The configured TensorFlow flood-risk model is invalid.',
            'RISK_POLICY_NOT_CONFIGURED' => 'The CIVENTRAL AI risk policy is not configured.',
            'AI_SERVICE_NOT_CONFIGURED' => 'The private AI service is not configured.',
            'AI_SERVICE_UNREACHABLE' => 'The private AI service is currently unreachable.',
            'AI_AUTHENTICATION_FAILED' => 'Private AI service authentication failed.',
            'AI_SERVICE_INVALID_RESPONSE' => 'The private AI service returned an invalid response.',
            'AI_REQUEST_INVALID' => 'The flood-risk inference request is invalid.',
            default => 'The private AI service could not complete the request.',
        };
    }

    private function validModelState(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::MODEL_STATES, true);
    }

    private function validRiskPolicyState(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::RISK_POLICY_STATES, true);
    }

    private function nullableIdentifier(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) === 1 ? $value : null;
    }

    private function requestId(): string
    {
        return 'php-' . bin2hex(random_bytes(16));
    }

    private function logOutcome(string $requestId, string $code, ?float $latencyMs): void
    {
        $record = [
            'event' => 'civentral_ai_service_request',
            'request_id' => $requestId,
            'result_code' => $code,
        ];
        if ($latencyMs !== null) {
            $record['latency_ms'] = round($latencyMs, 3);
        }
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($encoded)) {
            error_log($encoded);
        }
    }
}
