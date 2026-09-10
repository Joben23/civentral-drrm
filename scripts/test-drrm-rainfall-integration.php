<?php

declare(strict_types=1);

use App\Config\AiServiceConfig;
use App\Services\DrrmAiHttpResponse;
use App\Services\DrrmAiHttpTransportInterface;
use App\Services\DrrmRainfallInferenceClient;
use App\Services\DrrmAiTransportException;

require_once __DIR__ . '/../config/ai.php';
require_once __DIR__ . '/../src/Services/DrrmFloodRiskAiClient.php';
require_once __DIR__ . '/../src/Services/DrrmRainfallInferenceClient.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must run from the command line.\n");
    exit(1);
}

final class RainfallTestTransport implements DrrmAiHttpTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @param Closure(): DrrmAiHttpResponse $handler */
    public function __construct(private readonly Closure $handler)
    {
    }

    public function request(string $method, string $url, array $headers, ?array $payload, int $connectTimeoutMs, int $requestTimeoutMs): DrrmAiHttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'payload', 'connectTimeoutMs', 'requestTimeoutMs');
        return ($this->handler)();
    }
}

$failures = [];
$assertions = 0;
$assert = static function (string $name, bool $passed) use (&$failures, &$assertions): void {
    $assertions++;
    echo $name . '=' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
};

$history = [];
$start = new DateTimeImmutable('2024-01-01T00:00:00Z');
for ($index = 0; $index < 48; $index++) {
    $history[] = [
        'timestamp_utc' => $start->modify('+' . ($index * 30) . ' minutes')->format('Y-m-d\TH:i:s\Z'),
        'city_mean_precipitation_mm' => 0.25,
    ];
}
$request = [
    'schema_version' => '1.0',
    'request_id' => 'php-rainfall-contract-test',
    'history' => $history,
];

$key = 'test-only-internal-key-32-characters';
putenv('CIVENTRAL_AI_BASE_URL=http://127.0.0.1:8098');
putenv('CIVENTRAL_AI_INTERNAL_KEY=' . $key);
putenv('CIVENTRAL_AI_CONNECT_TIMEOUT_MS=900');
putenv('CIVENTRAL_AI_REQUEST_TIMEOUT_MS=2500');
$config = AiServiceConfig::fromEnvironment(null);

$readyTransport = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(
    200,
    json_encode([
        'success' => true,
        'ready' => true,
        'code' => 'RAINFALL_RESEARCH_READY',
        'message' => 'ready',
        'model_problem' => 'RAINFALL_REGRESSION',
        'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
        'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
        'authorization_status' => 'APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY',
    ], JSON_THROW_ON_ERROR),
    1.2
));
$readyClient = new DrrmRainfallInferenceClient($config, $readyTransport);
$ready = $readyClient->ready();
$assert('RainfallReadyParsed', ($ready['code'] ?? null) === 'RAINFALL_RESEARCH_READY');
$assert('RainfallReadyPrivateKeyAttached', count(array_filter($readyTransport->calls[0]['headers'], static fn ($header): bool => is_string($header) && str_starts_with($header, 'X-CIVENTRAL-AI-Key: '))) === 1);
$assert('RainfallReadyKeyNotReturned', !str_contains(json_encode($ready), $key));

$predictionTransport = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(
    200,
    json_encode([
        'schema_version' => '1.0',
        'request_id' => 'php-rainfall-contract-test',
        'model_problem' => 'RAINFALL_REGRESSION',
        'forecast_origin_utc' => '2024-01-02T00:00:00Z',
        'forecast_horizon_hours' => 3,
        'target' => 'NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM',
        'raw_prediction_mm' => 1.5,
        'final_prediction_mm' => 1.5,
        'output_policy' => 'MODEL_NONNEGATIVE_SOFTPLUS',
        'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
        'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
        'operational' => false,
    ], JSON_THROW_ON_ERROR),
    2.4
));
$client = new DrrmRainfallInferenceClient($config, $predictionTransport);
$result = $client->predict($request);
$assert('RainfallPredictionParsed', ($result['code'] ?? null) === 'RAINFALL_PREDICTION_AVAILABLE');
$assert('RainfallPredictionRequestIdPreserved', ($result['request_id'] ?? null) === $request['request_id']);
$assert('RainfallPredictionNonnegative', ($result['final_prediction_mm'] ?? -1) >= 0);
$assert('RainfallPredictionHasNoFloodOutput', !array_key_exists('flood_probability', $result) && !array_key_exists('risk_level', $result));
$assert('RainfallPredictionOperationalFalse', ($result['operational'] ?? null) === false);
$assert('RainfallPredictionCorrectEndpoint', ($predictionTransport->calls[0]['url'] ?? null) === 'http://127.0.0.1:8098/rainfall/predict');

$short = $request;
array_pop($short['history']);
$assert('ShortHistoryRejected', ($client->predict($short)['code'] ?? null) === 'AI_REQUEST_INVALID');

$gap = $request;
$gap['history'][10]['timestamp_utc'] = '2024-01-01T06:15:00Z';
$assert('TimestampGapRejected', ($client->predict($gap)['code'] ?? null) === 'AI_REQUEST_INVALID');

$negative = $request;
$negative['history'][0]['city_mean_precipitation_mm'] = -0.1;
$assert('NegativeRainfallRejected', ($client->predict($negative)['code'] ?? null) === 'AI_REQUEST_INVALID');

$duplicate = $request;
$duplicate['history'][5]['timestamp_utc'] = $duplicate['history'][4]['timestamp_utc'];
$assert('DuplicateTimestampRejected', ($client->predict($duplicate)['code'] ?? null) === 'AI_REQUEST_INVALID');

$badUtc = $request;
$badUtc['history'][0]['timestamp_utc'] = '2024-01-01T00:00:00+08:00';
$assert('NonUtcTimestampRejected', ($client->predict($badUtc)['code'] ?? null) === 'AI_REQUEST_INVALID');

$withInf = $request;
$withInf['history'][0]['city_mean_precipitation_mm'] = INF;
$assert('InfinityRainfallRejected', ($client->predict($withInf)['code'] ?? null) === 'AI_REQUEST_INVALID');

$withNan = $request;
$withNan['history'][0]['city_mean_precipitation_mm'] = NAN;
$assert('NanRainfallRejected', ($client->predict($withNan)['code'] ?? null) === 'AI_REQUEST_INVALID');

$reorderedKeys = [
    'history' => $request['history'],
    'schema_version' => '1.0',
    'request_id' => 'php-rainfall-contract-test',
];
$assert('ReorderedRequestKeysAccepted', ($client->predict($reorderedKeys)['code'] ?? null) === 'RAINFALL_PREDICTION_AVAILABLE');

$reorderedObservation = $request;
$reorderedObservation['history'][0] = [
    'city_mean_precipitation_mm' => 0.25,
    'timestamp_utc' => $reorderedObservation['history'][0]['timestamp_utc'],
];
$assert('ReorderedObservationKeysAccepted', ($client->predict($reorderedObservation)['code'] ?? null) === 'RAINFALL_PREDICTION_AVAILABLE');

$unexpectedReqKey = $request;
$unexpectedReqKey['extra_field'] = 'should-fail';
$assert('UnexpectedRequestKeyRejected', ($client->predict($unexpectedReqKey)['code'] ?? null) === 'AI_REQUEST_INVALID');

$unexpectedObsKey = $request;
$unexpectedObsKey['history'][0]['unexpected'] = 'should-fail';
$assert('UnexpectedObservationKeyRejected', ($client->predict($unexpectedObsKey)['code'] ?? null) === 'AI_REQUEST_INVALID');

foreach ([401 => 'UNAUTHORIZED', 422 => 'AI_REQUEST_INVALID', 500 => 'AI_SERVICE_ERROR', 503 => 'MODEL_UNAVAILABLE'] as $status => $expected) {
    $transport = new RainfallTestTransport(static function () use ($status): DrrmAiHttpResponse {
        return new DrrmAiHttpResponse($status, json_encode(['success' => false, 'code' => $status === 503 ? 'MODEL_UNAVAILABLE' : 'ERROR'], JSON_THROW_ON_ERROR), 1.0);
    });
    $failure = (new DrrmRainfallInferenceClient($config, $transport))->predict($request);
    $assert('Http' . $status . 'Mapped', ($failure['code'] ?? null) === $expected);
}

$timeout = new RainfallTestTransport(static function (): DrrmAiHttpResponse {
    throw new DrrmAiTransportException(DrrmAiTransportException::TIMEOUT);
});
$assert('TimeoutMappedToAiServiceTimeout', ((new DrrmRainfallInferenceClient($config, $timeout))->predict($request)['code'] ?? null) === 'AI_SERVICE_TIMEOUT');

$unreachable = new RainfallTestTransport(static function (): DrrmAiHttpResponse {
    throw new DrrmAiTransportException(DrrmAiTransportException::UNREACHABLE);
});
$assert('UnreachableMapped', ((new DrrmRainfallInferenceClient($config, $unreachable))->predict($request)['code'] ?? null) === 'AI_SERVICE_UNREACHABLE');

$malformed = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, '{invalid', 1.0));
$assert('MalformedJsonRejected', ((new DrrmRainfallInferenceClient($config, $malformed))->predict($request)['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$incompatible = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode(['schema_version' => '1.0', 'request_id' => 'php-rainfall-contract-test'], JSON_THROW_ON_ERROR), 1.0));
$assert('IncompatibleResponseRejected', ((new DrrmRainfallInferenceClient($config, $incompatible))->predict($request)['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$badForecastOrigin = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'schema_version' => '1.0',
    'request_id' => 'php-rainfall-contract-test',
    'model_problem' => 'RAINFALL_REGRESSION',
    'forecast_origin_utc' => 'not-a-timestamp',
    'forecast_horizon_hours' => 3,
    'target' => 'NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM',
    'raw_prediction_mm' => 1.5,
    'final_prediction_mm' => 1.5,
    'output_policy' => 'MODEL_NONNEGATIVE_SOFTPLUS',
    'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'operational' => false,
], JSON_THROW_ON_ERROR), 1.0));
$assert('InvalidForecastOriginRejected', ((new DrrmRainfallInferenceClient($config, $badForecastOrigin))->predict($request)['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$nonUtcForecastOrigin = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'schema_version' => '1.0',
    'request_id' => 'php-rainfall-contract-test',
    'model_problem' => 'RAINFALL_REGRESSION',
    'forecast_origin_utc' => '2024-01-02T00:00:00+08:00',
    'forecast_horizon_hours' => 3,
    'target' => 'NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM',
    'raw_prediction_mm' => 1.5,
    'final_prediction_mm' => 1.5,
    'output_policy' => 'MODEL_NONNEGATIVE_SOFTPLUS',
    'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'operational' => false,
], JSON_THROW_ON_ERROR), 1.0));
$assert('NonUtcForecastOriginRejected', ((new DrrmRainfallInferenceClient($config, $nonUtcForecastOrigin))->predict($request)['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$wrongModelVersion = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'schema_version' => '1.0',
    'request_id' => 'php-rainfall-contract-test',
    'model_problem' => 'RAINFALL_REGRESSION',
    'forecast_origin_utc' => '2024-01-02T00:00:00Z',
    'forecast_horizon_hours' => 3,
    'target' => 'NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM',
    'raw_prediction_mm' => 1.5,
    'final_prediction_mm' => 1.5,
    'output_policy' => 'MODEL_NONNEGATIVE_SOFTPLUS',
    'model_version' => 'rainfall-regression-wrong-version',
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'operational' => false,
], JSON_THROW_ON_ERROR), 1.0));
$assert('WrongModelVersionRejected', ((new DrrmRainfallInferenceClient($config, $wrongModelVersion))->predict($request)['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$wrongModelStatus = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'schema_version' => '1.0',
    'request_id' => 'php-rainfall-contract-test',
    'model_problem' => 'RAINFALL_REGRESSION',
    'forecast_origin_utc' => '2024-01-02T00:00:00Z',
    'forecast_horizon_hours' => 3,
    'target' => 'NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM',
    'raw_prediction_mm' => 1.5,
    'final_prediction_mm' => 1.5,
    'output_policy' => 'MODEL_NONNEGATIVE_SOFTPLUS',
    'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
    'model_status' => 'OPERATIONALLY_VALIDATED',
    'operational' => false,
], JSON_THROW_ON_ERROR), 1.0));
$assert('WrongModelStatusRejected', ((new DrrmRainfallInferenceClient($config, $wrongModelStatus))->predict($request)['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$operationalTrue = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'schema_version' => '1.0',
    'request_id' => 'php-rainfall-contract-test',
    'model_problem' => 'RAINFALL_REGRESSION',
    'forecast_origin_utc' => '2024-01-02T00:00:00Z',
    'forecast_horizon_hours' => 3,
    'target' => 'NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM',
    'raw_prediction_mm' => 1.5,
    'final_prediction_mm' => 1.5,
    'output_policy' => 'MODEL_NONNEGATIVE_SOFTPLUS',
    'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'operational' => true,
], JSON_THROW_ON_ERROR), 1.0));
$assert('OperationalTrueRejected', ((new DrrmRainfallInferenceClient($config, $operationalTrue))->predict($request)['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$onlyFloodProb = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'schema_version' => '1.0',
    'request_id' => 'php-rainfall-contract-test',
    'model_problem' => 'RAINFALL_REGRESSION',
    'forecast_origin_utc' => '2024-01-02T00:00:00Z',
    'forecast_horizon_hours' => 3,
    'target' => 'NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM',
    'raw_prediction_mm' => 1.5,
    'final_prediction_mm' => 1.5,
    'output_policy' => 'MODEL_NONNEGATIVE_SOFTPLUS',
    'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'operational' => false,
    'flood_probability' => 0.5,
], JSON_THROW_ON_ERROR), 1.0));
$assert('FloodProbabilityFieldRejected', ((new DrrmRainfallInferenceClient($config, $onlyFloodProb))->predict($request)['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$floodProbNull = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'schema_version' => '1.0',
    'request_id' => 'php-rainfall-contract-test',
    'model_problem' => 'RAINFALL_REGRESSION',
    'forecast_origin_utc' => '2024-01-02T00:00:00Z',
    'forecast_horizon_hours' => 3,
    'target' => 'NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM',
    'raw_prediction_mm' => 1.5,
    'final_prediction_mm' => 1.5,
    'output_policy' => 'MODEL_NONNEGATIVE_SOFTPLUS',
    'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'operational' => false,
    'flood_probability' => null,
], JSON_THROW_ON_ERROR), 1.0));
$assert('FloodProbabilityNullRejected', ((new DrrmRainfallInferenceClient($config, $floodProbNull))->predict($request)['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$readyMissingVersion = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'success' => true,
    'ready' => true,
    'code' => 'RAINFALL_RESEARCH_READY',
    'message' => 'ready',
    'model_problem' => 'RAINFALL_REGRESSION',
    'authorization_status' => 'APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY',
], JSON_THROW_ON_ERROR), 1.0));
$assert('ReadyMissingModelVersionRejected', ((new DrrmRainfallInferenceClient($config, $readyMissingVersion))->ready()['available'] ?? null) === false);

$readyWrongVersion = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'success' => true,
    'ready' => true,
    'code' => 'RAINFALL_RESEARCH_READY',
    'message' => 'ready',
    'model_problem' => 'RAINFALL_REGRESSION',
    'model_version' => 'rainfall-regression-old-version',
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'authorization_status' => 'APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY',
], JSON_THROW_ON_ERROR), 1.0));
$assert('ReadyWrongModelVersionRejected', ((new DrrmRainfallInferenceClient($config, $readyWrongVersion))->ready()['available'] ?? null) === false);

$readyInvalidAuth = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'success' => true,
    'ready' => true,
    'code' => 'RAINFALL_RESEARCH_READY',
    'message' => 'ready',
    'model_problem' => 'RAINFALL_REGRESSION',
    'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'authorization_status' => 'OPERATIONAL_APPROVAL',
], JSON_THROW_ON_ERROR), 1.0));
$assert('ReadyInvalidAuthorizationRejected', ((new DrrmRainfallInferenceClient($config, $readyInvalidAuth))->ready()['available'] ?? null) === false);

$readyFalseSuccess = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'success' => false,
    'ready' => true,
    'code' => 'RAINFALL_RESEARCH_READY',
    'message' => 'ready',
    'model_problem' => 'RAINFALL_REGRESSION',
    'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'authorization_status' => 'APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY',
], JSON_THROW_ON_ERROR), 1.0));
$assert('ReadyFalseSuccessRejected', ((new DrrmRainfallInferenceClient($config, $readyFalseSuccess))->ready()['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$readyWrongSuccessCode = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'success' => true,
    'ready' => true,
    'code' => 'MODEL_UNAVAILABLE',
    'message' => 'ready',
    'model_problem' => 'RAINFALL_REGRESSION',
    'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'authorization_status' => 'APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY',
], JSON_THROW_ON_ERROR), 1.0));
$assert('ReadyWrongSuccessCodeRejected', ((new DrrmRainfallInferenceClient($config, $readyWrongSuccessCode))->ready()['code'] ?? null) === 'AI_SERVICE_INVALID_RESPONSE');

$readyValid = new RainfallTestTransport(static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, json_encode([
    'success' => true,
    'ready' => true,
    'code' => 'RAINFALL_RESEARCH_READY',
    'message' => 'ready',
    'model_problem' => 'RAINFALL_REGRESSION',
    'model_version' => DrrmRainfallInferenceClient::MODEL_VERSION,
    'model_status' => 'VALIDATED_RESEARCH_CANDIDATE',
    'authorization_status' => 'APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY',
], JSON_THROW_ON_ERROR), 1.0));
$assert('ValidReadyResponseAccepted', ((new DrrmRainfallInferenceClient($config, $readyValid))->ready()['available'] ?? null) === true);

if ($failures !== []) {
    fwrite(STDERR, 'Rainfall integration failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'RainfallIntegrationAssertions=' . $assertions . PHP_EOL;
echo "DrrmRainfallIntegrationHardened=PASS\n";
