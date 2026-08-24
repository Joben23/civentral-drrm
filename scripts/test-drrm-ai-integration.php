<?php

declare(strict_types=1);

use App\Config\AiServiceConfig;
use App\Services\DrrmAiHttpResponse;
use App\Services\DrrmAiHttpTransportInterface;
use App\Services\DrrmAiTransportException;
use App\Services\DrrmAiStatusService;
use App\Services\DrrmFloodRiskAiClient;
use App\Services\DrrmFloodRiskPredictionService;
use App\Services\FastApiFloodRiskPredictor;

require_once __DIR__ . '/../config/ai.php';
require_once __DIR__ . '/../src/Services/FloodRiskPredictorInterface.php';
require_once __DIR__ . '/../src/Services/DrrmFloodRiskAiClient.php';
require_once __DIR__ . '/../src/Services/DrrmAiStatusService.php';
require_once __DIR__ . '/../src/Services/FastApiFloodRiskPredictor.php';
require_once __DIR__ . '/../src/Services/DrrmFloodRiskPredictionService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must run from the command line.\n");
    exit(1);
}

if (in_array('--live', $argv, true)) {
    try {
        $liveClient = new DrrmFloodRiskAiClient(AiServiceConfig::fromEnvironment(null));
        $liveHealth = $liveClient->health();
        $liveReady = $liveClient->ready();
        $liveModel = $liveClient->modelStatus();
        $liveCombined = (new DrrmAiStatusService($liveClient))->status();
        $passed = ($liveHealth['code'] ?? null) === 'HEALTHY'
            && ($liveReady['code'] ?? null) === 'MODEL_NOT_AVAILABLE'
            && ($liveModel['model_status'] ?? null) === 'MODEL_NOT_AVAILABLE'
            && ($liveCombined['service_health'] ?? null) === 'HEALTHY'
            && ($liveCombined['prediction_ready'] ?? null) === false;
        echo json_encode([
            'health' => $liveHealth,
            'ready' => $liveReady,
            'model_status' => $liveModel,
            'combined_status' => $liveCombined,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        echo 'DrrmAiLiveIntegration=' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
        exit($passed ? 0 : 1);
    } catch (Throwable) {
        fwrite(STDERR, "DrrmAiLiveIntegration=FAIL\n");
        exit(1);
    }
}

final class TestAiTransport implements DrrmAiHttpTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @param Closure(): DrrmAiHttpResponse $handler */
    public function __construct(private readonly Closure $handler)
    {
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?array $payload,
        int $connectTimeoutMs,
        int $requestTimeoutMs
    ): DrrmAiHttpResponse {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'payload' => $payload,
            'connect_timeout_ms' => $connectTimeoutMs,
            'request_timeout_ms' => $requestTimeoutMs,
        ];
        return ($this->handler)();
    }
}

$failures = [];
$assertions = 0;

/** @param mixed $actual @param mixed $expected */
function assertAiIntegration(string $name, mixed $actual, mixed $expected): void
{
    global $assertions, $failures;
    $assertions++;
    $passed = $actual === $expected;
    echo $name . '=' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$passed) {
        $failures[] = $name;
    }
}

/** @param array<string, mixed> $value */
function hasAiOutput(array $value): bool
{
    foreach (['probability', 'predicted_outcome', 'civentral_risk_level'] as $field) {
        if (array_key_exists($field, $value)) {
            return true;
        }
    }
    return false;
}

/** @return array<string, mixed> Contract-only test fixture; never operational data. */
function aiPredictionContractFixture(): array
{
    return [
        'schema_version' => '1.0',
        'request_id' => 'php-contract-test',
        'prediction_type' => 'FLOOD_WITHIN_24H',
        'valid_from' => '2026-08-24T00:00:00+08:00',
        'valid_until' => '2026-08-25T00:00:00+08:00',
        'location' => ['barangay_id' => '1380100001'],
        'features' => [
            'forecast_rainfall_24h_mm' => 1.0,
            'antecedent_rainfall_24h_mm' => 2.0,
            'antecedent_rainfall_72h_mm' => 3.0,
            'mgb_flood_susceptibility_code' => 'HF',
            'month_sin' => -0.5,
            'month_cos' => -0.8660254037844386,
        ],
        'source_context' => [
            'weather_issued_at' => '2026-08-23T18:00:00+08:00',
            'feature_schema_version' => '1.0.0',
        ],
    ];
}

$environmentNames = [
    'CIVENTRAL_AI_BASE_URL',
    'CIVENTRAL_AI_INTERNAL_KEY',
    'CIVENTRAL_AI_CONNECT_TIMEOUT_MS',
    'CIVENTRAL_AI_REQUEST_TIMEOUT_MS',
];
$originalEnvironment = [];
foreach ($environmentNames as $name) {
    $originalEnvironment[$name] = getenv($name);
}

try {
    putenv('CIVENTRAL_AI_BASE_URL=');
    $missingBaseRejected = false;
    try {
        AiServiceConfig::fromEnvironment(null);
    } catch (RuntimeException) {
        $missingBaseRejected = true;
    }
    assertAiIntegration('MissingBaseUrlRejected', $missingBaseRejected, true);

    $testKey = 'test-only-internal-key-32-characters';
    putenv('CIVENTRAL_AI_BASE_URL=http://127.0.0.1:8098');
    putenv('CIVENTRAL_AI_INTERNAL_KEY=' . $testKey);
    putenv('CIVENTRAL_AI_CONNECT_TIMEOUT_MS=900');
    putenv('CIVENTRAL_AI_REQUEST_TIMEOUT_MS=2500');
    $config = AiServiceConfig::fromEnvironment(null);

    $unreachableTransport = new TestAiTransport(static function (): DrrmAiHttpResponse {
        throw new DrrmAiTransportException(DrrmAiTransportException::UNREACHABLE);
    });
    $unreachable = (new DrrmFloodRiskAiClient($config, $unreachableTransport))->health();
    assertAiIntegration('UnreachableMapped', $unreachable['code'] ?? null, 'AI_SERVICE_UNREACHABLE');

    $timeoutTransport = new TestAiTransport(static function (): DrrmAiHttpResponse {
        throw new DrrmAiTransportException(DrrmAiTransportException::TIMEOUT);
    });
    $timeout = (new DrrmFloodRiskAiClient($config, $timeoutTransport))->health();
    assertAiIntegration('TimeoutFailsClosed', $timeout['code'] ?? null, 'AI_SERVICE_UNREACHABLE');

    $healthPayload = [
        'success' => true,
        'service_status' => 'HEALTHY',
        'service_version' => '1.0.0',
        'python_version' => '3.12.0',
        'tensorflow_installed' => false,
        'model_status' => 'MODEL_NOT_AVAILABLE',
        'risk_policy_status' => 'NOT_CONFIGURED',
        'checked_at' => '2026-08-24T00:00:00Z',
        'unexpected_private_detail' => 'must-not-pass-through',
    ];
    $healthTransport = new TestAiTransport(static fn (): DrrmAiHttpResponse =>
        new DrrmAiHttpResponse(200, json_encode($healthPayload, JSON_THROW_ON_ERROR), 1.25));
    $health = (new DrrmFloodRiskAiClient($config, $healthTransport))->health();
    assertAiIntegration('HealthParsed', $health['code'] ?? null, 'HEALTHY');
    assertAiIntegration('HealthRuntimeReachable', $health['runtime_reachable'] ?? null, true);
    assertAiIntegration('UnexpectedUpstreamFieldRemoved', isset($health['unexpected_private_detail']), false);

    $readyTransport = new TestAiTransport(static fn (): DrrmAiHttpResponse =>
        new DrrmAiHttpResponse(503, json_encode([
            'success' => false,
            'ready' => false,
            'code' => 'MODEL_NOT_AVAILABLE',
            'message' => 'Upstream diagnostic not passed through.',
            'model_status' => 'MODEL_NOT_AVAILABLE',
            'risk_policy_status' => 'NOT_CONFIGURED',
        ], JSON_THROW_ON_ERROR), 1.5));
    $ready = (new DrrmFloodRiskAiClient($config, $readyTransport))->ready();
    assertAiIntegration('Readiness503Parsed', $ready['code'] ?? null, 'MODEL_NOT_AVAILABLE');
    assertAiIntegration('ReadinessNotReady', $ready['ready'] ?? null, false);
    assertAiIntegration('ReadinessNoAiOutput', hasAiOutput($ready), false);

    $modelTransport = new TestAiTransport(static fn (): DrrmAiHttpResponse =>
        new DrrmAiHttpResponse(200, json_encode([
            'success' => true,
            'model_status' => 'MODEL_NOT_AVAILABLE',
            'model_available' => false,
            'approved_for_inference' => false,
            'model_version' => null,
            'feature_schema_version' => '1.0.0',
            'threshold_policy_version' => null,
            'tensorflow_installed' => false,
            'private_artifact_path' => 'must-not-pass-through',
        ], JSON_THROW_ON_ERROR), 1.75));
    $modelClient = new DrrmFloodRiskAiClient($config, $modelTransport);
    $model = $modelClient->modelStatus();
    assertAiIntegration('ModelStatusParsed', $model['model_status'] ?? null, 'MODEL_NOT_AVAILABLE');
    assertAiIntegration('ModelNotApproved', $model['approved_for_inference'] ?? null, false);
    assertAiIntegration('ArtifactPathRemoved', isset($model['private_artifact_path']), false);
    $modelHeaders = $modelTransport->calls[0]['headers'] ?? [];
    $keyHeaderFound = false;
    foreach ($modelHeaders as $header) {
        if (is_string($header) && str_starts_with($header, 'X-CIVENTRAL-AI-Key: ')) {
            $keyHeaderFound = true;
        }
    }
    assertAiIntegration('InternalKeyHeaderAttached', $keyHeaderFound, true);
    assertAiIntegration('InternalKeyNotExposed', str_contains(json_encode($model), $testKey), false);

    $authTransport = new TestAiTransport(static fn (): DrrmAiHttpResponse =>
        new DrrmAiHttpResponse(401, '{"success":false,"code":"UNAUTHORIZED"}', 1.0));
    $authFailure = (new DrrmFloodRiskAiClient($config, $authTransport))->modelStatus();
    assertAiIntegration('AuthenticationFailureMapped', $authFailure['code'] ?? null, 'AI_AUTHENTICATION_FAILED');

    $statusResponses = [
        new DrrmAiHttpResponse(200, json_encode($healthPayload, JSON_THROW_ON_ERROR), 1.0),
        new DrrmAiHttpResponse(503, json_encode([
            'success' => false,
            'ready' => false,
            'code' => 'MODEL_NOT_AVAILABLE',
            'message' => 'No approved model.',
            'model_status' => 'MODEL_NOT_AVAILABLE',
            'risk_policy_status' => 'NOT_CONFIGURED',
        ], JSON_THROW_ON_ERROR), 1.0),
        new DrrmAiHttpResponse(401, '{"success":false,"code":"UNAUTHORIZED"}', 1.0),
    ];
    $statusTransport = new TestAiTransport(
        static function () use (&$statusResponses): DrrmAiHttpResponse {
            $response = array_shift($statusResponses);
            if (!$response instanceof DrrmAiHttpResponse) {
                throw new DrrmAiTransportException(DrrmAiTransportException::INVALID_RESPONSE);
            }
            return $response;
        }
    );
    $authenticationStatus = (new DrrmAiStatusService(
        new DrrmFloodRiskAiClient($config, $statusTransport)
    ))->status();
    assertAiIntegration('StatusPreservesRuntimeHealth', $authenticationStatus['service_health'] ?? null, 'HEALTHY');
    assertAiIntegration('StatusSurfacesAuthenticationFailure', $authenticationStatus['code'] ?? null, 'AI_AUTHENTICATION_FAILED');
    assertAiIntegration('AuthenticationFailureNotReady', $authenticationStatus['prediction_ready'] ?? null, false);

    $invalidJsonTransport = new TestAiTransport(static fn (): DrrmAiHttpResponse =>
        new DrrmAiHttpResponse(200, '{invalid-json', 1.0));
    $invalidJson = (new DrrmFloodRiskAiClient($config, $invalidJsonTransport))->health();
    assertAiIntegration('InvalidJsonMapped', $invalidJson['code'] ?? null, 'AI_SERVICE_INVALID_RESPONSE');

    $predictionTransport = new TestAiTransport(static fn (): DrrmAiHttpResponse =>
        new DrrmAiHttpResponse(503, json_encode([
            'success' => false,
            'code' => 'MODEL_NOT_AVAILABLE',
            'message' => 'No approved model.',
            'model_status' => 'MODEL_NOT_AVAILABLE',
        ], JSON_THROW_ON_ERROR), 2.0));
    $predictionClient = new DrrmFloodRiskAiClient($config, $predictionTransport);
    $prediction = $predictionClient->predictFloodRisk(aiPredictionContractFixture());
    assertAiIntegration('ModelUnavailableMapped', $prediction['code'] ?? null, 'MODEL_NOT_AVAILABLE');
    assertAiIntegration('ModelUnavailableNoProbability', isset($prediction['probability']), false);
    assertAiIntegration('ModelUnavailableNoRiskLevel', isset($prediction['civentral_risk_level']), false);

    $predictor = new FastApiFloodRiskPredictor($predictionClient);
    $missingRainfall = $predictor->predict(
        [
            'weather_issued_at' => '2026-08-23T18:00:00+08:00',
            'valid_from' => '2026-08-24T00:00:00+08:00',
            'valid_until' => '2026-08-25T00:00:00+08:00',
        ],
        [
            'barangay_id' => '1380100001',
            'latitude' => 14.65,
            'longitude' => 120.98,
            'mapped_susceptibility' => 'HF',
        ],
        ['antecedent_rainfall_24h_mm' => 2.0, 'antecedent_rainfall_72h_mm' => 3.0]
    );
    assertAiIntegration('MissingRainfallRejected', $missingRainfall['code'] ?? null, 'AI_REQUEST_INVALID');
    assertAiIntegration('MissingRainfallNoTransport', count($predictionTransport->calls), 1);

    $predictorResult = $predictor->predict(
        [
            'request_id' => 'php-feature-order-test',
            'forecast_rainfall_24h_mm' => 1.0,
            'weather_issued_at' => '2026-08-23T18:00:00+08:00',
            'valid_from' => '2026-08-24T00:00:00+08:00',
            'valid_until' => '2026-08-25T00:00:00+08:00',
        ],
        [
            'barangay_id' => '1380100001',
            'latitude' => 14.65,
            'longitude' => 120.98,
            'mapped_susceptibility' => 'HF',
        ],
        ['antecedent_rainfall_24h_mm' => 2.0, 'antecedent_rainfall_72h_mm' => 3.0]
    );
    $featureKeys = array_keys($predictionTransport->calls[1]['payload']['features'] ?? []);
    assertAiIntegration('CompactFeatureOrder', $featureKeys, [
        'forecast_rainfall_24h_mm',
        'antecedent_rainfall_24h_mm',
        'antecedent_rainfall_72h_mm',
        'mgb_flood_susceptibility_code',
        'month_sin',
        'month_cos',
    ]);
    assertAiIntegration('PredictorFailsClosed', $predictorResult['code'] ?? null, 'MODEL_NOT_AVAILABLE');

    $browserService = new DrrmFloodRiskPredictionService();
    $inputUnavailable = $browserService->requestPrediction([
        'request_id' => 'browser-input-boundary-test',
        'location' => ['barangay_id' => '1380100001'],
    ]);
    assertAiIntegration('ValidatedLocationStopsAtInput', $inputUnavailable['code'] ?? null, 'INPUT_DATA_UNAVAILABLE');
    assertAiIntegration('InputUnavailableNoAiOutput', hasAiOutput($inputUnavailable), false);
    $browserBypass = $browserService->requestPrediction([
        'location' => ['barangay_id' => '1380100001'],
        'forecast_rainfall_24h_mm' => 1.0,
    ]);
    assertAiIntegration('BrowserFeatureBypassRejected', $browserBypass['code'] ?? null, 'AI_REQUEST_INVALID');
    $unknownBarangay = $browserService->requestPrediction([
        'location' => ['barangay_id' => '1380199999'],
    ]);
    assertAiIntegration('UnknownBarangayRejected', $unknownBarangay['code'] ?? null, 'AI_REQUEST_INVALID');

    $integrationSources = implode("\n", [
        file_get_contents(__DIR__ . '/../src/Services/DrrmFloodRiskAiClient.php') ?: '',
        file_get_contents(__DIR__ . '/../src/Services/FastApiFloodRiskPredictor.php') ?: '',
        file_get_contents(__DIR__ . '/../src/Services/DrrmFloodRiskPredictionService.php') ?: '',
    ]);
    assertAiIntegration(
        'NoWarningWriteServiceCoupling',
        str_contains($integrationSources, 'DrrmEarlyWarningWriteService'),
        false
    );
} finally {
    foreach ($originalEnvironment as $name => $value) {
        putenv($value === false ? $name : ($name . '=' . $value));
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'AI integration test failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'AiIntegrationAssertions=' . $assertions . PHP_EOL;
echo "DrrmAiIntegrationFoundation=PASS\n";
