<?php

declare(strict_types=1);

use App\Config\AiServiceConfig;
use App\Services\DrrmAiHttpResponse;
use App\Services\DrrmAiHttpTransportInterface;
use App\Services\DrrmAiTransportException;
use App\Services\DrrmNoApprovedLiveRainfallHistoryProvider;
use App\Services\DrrmRainfallHistoryProviderInterface;
use App\Services\DrrmRainfallInferenceClient;
use App\Services\DrrmRainfallPredictionService;

require_once __DIR__ . '/../config/ai.php';
require_once __DIR__ . '/../src/Services/DrrmFloodRiskAiClient.php';
require_once __DIR__ . '/../src/Services/DrrmRainfallInferenceClient.php';
require_once __DIR__ . '/../src/Services/DrrmRainfallHistoryProviderInterface.php';
require_once __DIR__ . '/../src/Services/DrrmNoApprovedLiveRainfallHistoryProvider.php';
require_once __DIR__ . '/../src/Services/DrrmRainfallPredictionService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must run from the command line.\n");
    exit(1);
}

final class RainfallWorkflowTestProvider implements DrrmRainfallHistoryProviderInterface
{
    /** @param array<string, mixed> $result */
    public function __construct(private readonly array $result)
    {
    }

    public function history(): array
    {
        return $this->result;
    }
}

final class RainfallWorkflowTestTransport implements DrrmAiHttpTransportInterface
{
    public int $calls = 0;

    /** @param Closure(): DrrmAiHttpResponse $handler */
    public function __construct(
        private readonly Closure $handler,
        /** @var list<array<string, mixed>> */
        public array $payloads = []
    ) {
    }

    public function request(string $method, string $url, array $headers, ?array $payload, int $connectTimeoutMs, int $requestTimeoutMs): DrrmAiHttpResponse
    {
        $this->calls++;
        $this->payloads[] = $payload ?? [];
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

putenv('CIVENTRAL_AI_BASE_URL=http://127.0.0.1:8098');
putenv('CIVENTRAL_AI_INTERNAL_KEY=test-only-internal-key-32-characters');
putenv('CIVENTRAL_AI_CONNECT_TIMEOUT_MS=900');
putenv('CIVENTRAL_AI_REQUEST_TIMEOUT_MS=2500');
$config = AiServiceConfig::fromEnvironment(null);
$requestId = 'phase-3fd-workflow-test';
$semantics = [
    'measurement' => 'city_mean_precipitation_mm',
    'unit' => 'millimetres',
    'accumulation_interval_minutes' => 30,
    'geographic_scope' => 'Caloocan City mean',
    'timestamp_meaning' => 'observation_interval_start_utc',
];
$history = [];
$start = new DateTimeImmutable('2024-01-01T00:00:00Z');
for ($index = 0; $index < 48; $index++) {
    $history[] = [
        'timestamp_utc' => $start->modify('+' . ($index * 30) . ' minutes')->format('Y-m-d\TH:i:s\Z'),
        'city_mean_precipitation_mm' => 0.25,
    ];
}
$providerResult = fn (?array $observations = null, ?array $sourceSemantics = null): array => [
    'status' => 'AVAILABLE',
    'source_id' => 'TEST_ONLY_VALIDATED_RAINFALL_SOURCE',
    'source_semantics' => $sourceSemantics ?? $semantics,
    'history' => $observations ?? $history,
];
$predictionBody = fn (): string => json_encode([
    'schema_version' => '1.0',
    'request_id' => $requestId,
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
], JSON_THROW_ON_ERROR);
$makeService = static function (array $provider, ?Closure $handler = null) use ($config): array {
    $transport = new RainfallWorkflowTestTransport($handler ?? static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, '{}', 1.0));
    $client = new DrrmRainfallInferenceClient($config, $transport);
    return [new DrrmRainfallPredictionService(new RainfallWorkflowTestProvider($provider), $client), $transport];
};
$validProvider = $providerResult();

[$service, $transport] = $makeService($validProvider, fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, $predictionBody(), 1.0));
$valid = $service->predict($requestId);
$assert('TrustedSourceUsedServerSide', $transport->calls === 1);
$assert('ValidExactHistoryAccepted', ($valid['available'] ?? false) === true);
$assert('HistoryPassedToInferenceClient', ($transport->payloads[0]['history'] ?? null) === $history);
$assert('WorkflowRequestIdPreserved', ($valid['request_id'] ?? null) === $requestId && ($transport->payloads[0]['request_id'] ?? null) === $requestId);
$assert('PredictionAvailable', ($valid['code'] ?? null) === 'RAINFALL_PREDICTION_AVAILABLE');
$assert('NoFloodProbabilityGenerated', !array_key_exists('flood_probability', $valid));
$assert('NoRiskCategoryGenerated', !array_key_exists('risk_level', $valid));
$assert('NoMgbFusionGenerated', !str_contains(json_encode($valid), 'MGB'));

$reorderedSemantics = [
    'timestamp_meaning' => 'observation_interval_start_utc',
    'geographic_scope' => 'Caloocan City mean',
    'accumulation_interval_minutes' => 30,
    'unit' => 'millimetres',
    'measurement' => 'city_mean_precipitation_mm',
];
[$service, $transport] = $makeService(
    $providerResult($history, $reorderedSemantics),
    fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(200, $predictionBody(), 1.0)
);
$reorderedResult = $service->predict($requestId);
$assert('ReorderedSourceSemanticsAccepted', ($reorderedResult['available'] ?? false) === true && $transport->calls === 1);

[$service, $transport] = $makeService((new DrrmNoApprovedLiveRainfallHistoryProvider())->history());
$unavailable = $service->predict($requestId);
$assert('NoApprovedLiveSourceFailsClosed', ($unavailable['code'] ?? null) === 'RAINFALL_HISTORY_UNAVAILABLE' && $transport->calls === 0);

$malformedProvider = ['status' => 'AVAILABLE', 'source_id' => 'TEST_ONLY_MALFORMED_SOURCE'];
[$service, $transport] = $makeService($malformedProvider);
$assert('MalformedSourceFailsClosed', ($service->predict($requestId)['code'] ?? null) === 'RAINFALL_HISTORY_INVALID' && $transport->calls === 0);

$malformed = $validProvider;
$malformed['history'] = array_slice($history, 0, 47);
[$service, $transport] = $makeService($malformed);
$assert('FewerThan48Rejected', ($service->predict($requestId)['code'] ?? null) === 'RAINFALL_HISTORY_INVALID' && $transport->calls === 0);

$gap = $history;
$gap[10]['timestamp_utc'] = '2024-01-01T06:15:00Z';
$invalidCases = [
    'TimestampGapRejected' => $gap,
    'DuplicateTimestampRejected' => array_replace($history, [11 => $history[10]]),
];
foreach ($invalidCases as $name => $observations) {
    [$service, $transport] = $makeService($providerResult($observations));
    $assert($name, ($service->predict($requestId)['code'] ?? null) === 'RAINFALL_HISTORY_INVALID' && $transport->calls === 0);
}

$nonUtc = $history;
$nonUtc[0]['timestamp_utc'] = '2024-01-01T08:00:00+08:00';
$negative = $history;
$negative[0]['city_mean_precipitation_mm'] = -0.1;
$nan = $history;
$nan[0]['city_mean_precipitation_mm'] = NAN;
$infinity = $history;
$infinity[0]['city_mean_precipitation_mm'] = INF;
foreach ([
    'NonUtcTimestampRejected' => $nonUtc,
    'NegativeRainfallRejected' => $negative,
    'NanRainfallRejected' => $nan,
    'InfinityRainfallRejected' => $infinity,
] as $name => $observations) {
    [$service, $transport] = $makeService($providerResult($observations));
    $assert($name, ($service->predict($requestId)['code'] ?? null) === 'RAINFALL_HISTORY_INVALID' && $transport->calls === 0);
}

$missing = $history;
unset($missing[0]['city_mean_precipitation_mm']);
[$service, $transport] = $makeService($providerResult($missing));
$missingResult = $service->predict($requestId);
$assert('MissingValuesNotZeroFilled', ($missingResult['code'] ?? null) === 'RAINFALL_HISTORY_INVALID' && $transport->calls === 0);

$incompatibleSemantics = $semantics;
$incompatibleSemantics['unit'] = 'inches';
[$service, $transport] = $makeService($providerResult($history, $incompatibleSemantics));
$assert('IncompatibleSemanticsFailClosed', ($service->predict($requestId)['code'] ?? null) === 'RAINFALL_HISTORY_INCOMPATIBLE' && $transport->calls === 0);

$incompatible = $validProvider;
$incompatible['status'] = 'INCOMPATIBLE';
[$service, $transport] = $makeService($incompatible);
$assert('ProviderIncompatibleStatePreserved', ($service->predict($requestId)['code'] ?? null) === 'RAINFALL_HISTORY_INCOMPATIBLE' && $transport->calls === 0);

[$service, $transport] = $makeService($validProvider, static fn (): DrrmAiHttpResponse => new DrrmAiHttpResponse(500, '{"code":"AI_SERVICE_ERROR"}', 1.0));
$assert('PredictionFailurePreserved', ($service->predict($requestId)['code'] ?? null) === 'AI_SERVICE_ERROR');

[$service, $transport] = $makeService($validProvider, static function (): DrrmAiHttpResponse {
    throw new DrrmAiTransportException(DrrmAiTransportException::TIMEOUT);
});
$assert('ServiceTimeoutPreserved', ($service->predict($requestId)['code'] ?? null) === 'AI_SERVICE_TIMEOUT');

$workflowSource = file_get_contents(__DIR__ . '/../src/Services/DrrmRainfallPredictionService.php');
$assert('NoModule4WarningCreation', is_string($workflowSource) && !str_contains($workflowSource, 'DrrmEarlyWarningWriteService'));
$assert('NoCitizenDirectAiPath', is_string($workflowSource) && !str_contains($workflowSource, '$_POST') && !str_contains($workflowSource, 'HTTP_'));
$assert('NoFloodClassificationOrMgbFusion', is_string($workflowSource) && !str_contains($workflowSource, 'flood_probability') && !str_contains($workflowSource, 'risk_level') && !str_contains($workflowSource, 'MGB'));

if ($failures !== []) {
    fwrite(STDERR, 'Rainfall workflow failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'RainfallWorkflowAssertions=' . $assertions . PHP_EOL;
echo "DrrmRainfallWorkflow=PASS\n";
