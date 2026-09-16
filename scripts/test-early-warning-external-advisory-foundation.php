<?php

declare(strict_types=1);

use App\Services\DrrmCitizenWarningReadService;
use App\Services\DrrmDataStoreInterface;
use App\Services\DrrmEarlyWarningAuthorizationService;
use App\Services\DrrmExternalAdvisoryIngestionException;
use App\Services\DrrmExternalAdvisoryIngestionService;
use App\Services\DrrmExternalAdvisoryNormalizer;
use App\Services\DrrmExternalAdvisorySyncRequest;
use App\Services\DrrmExternalAdvisoryValidationException;
use App\Services\ExternalAdvisoryFetchResult;
use App\Services\ExternalAdvisoryProviderException;
use App\Services\ExternalAdvisoryProviderInterface;
use App\Services\PagasaExternalAdvisoryProvider;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/ExternalAdvisoryFetchResult.php';
require_once __DIR__ . '/../src/Services/ExternalAdvisoryProviderInterface.php';
require_once __DIR__ . '/../src/Services/DrrmExternalAdvisoryNormalizer.php';
require_once __DIR__ . '/../src/Services/DrrmExternalAdvisoryIngestionService.php';
require_once __DIR__ . '/../src/Services/DrrmExternalAdvisorySyncRequest.php';
require_once __DIR__ . '/../src/Services/PagasaExternalAdvisoryProvider.php';
require_once __DIR__ . '/../src/Services/DrrmEarlyWarningAuthorizationService.php';
require_once __DIR__ . '/../src/Services/DrrmCitizenWarningReadService.php';

/** @param mixed $actual @param mixed $expected */
function assertExternalFoundation(string $name, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            $name . ' failed. Expected ' . json_encode($expected)
            . ', received ' . json_encode($actual) . '.'
        );
    }
    echo $name . '=PASS' . PHP_EOL;
}

/** @param class-string<Throwable> $class @param callable(): mixed $operation */
function assertExternalFoundationThrows(string $name, string $class, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            echo $name . '=PASS' . PHP_EOL;
            return;
        }
        throw $exception;
    }
    throw new RuntimeException($name . ' was not rejected.');
}

/** @return array<string, mixed> */
function validExternalProviderItem(string $summary = 'Official provider summary.'): array
{
    return [
        'external_reference_id' => 'PAGASA-ITEM-001',
        'source_reference' => 'https://example.invalid/official-item-001',
        'title' => 'Official weather advisory sample',
        'advisory_type' => 'WEATHER_ADVISORY',
        'hazard_type' => 'HEAVY_RAINFALL',
        'summary' => $summary,
        'issued_at' => '2026-09-16T08:00:00+08:00',
        'valid_until' => '2026-09-16T18:00:00+08:00',
        'raw_payload' => [
            'provider_id' => 'PAGASA-ITEM-001',
            'revision' => 1,
        ],
    ];
}

final class ExternalFoundationStore implements DrrmDataStoreInterface
{
    /** @var list<array{function: string, payload: array<string, mixed>}> */
    public array $rpcCalls = [];

    /** @var array<string, array{hash: string, version: int}> */
    private array $stagedItems = [];

    public function get(string $resource, array $query = []): array
    {
        if ($resource === 'early_warnings') {
            return [];
        }
        throw new RuntimeException('Unexpected external-foundation read: ' . $resource);
    }

    public function post(string $resource, array $payload, array $query = []): array
    {
        throw new RuntimeException('Direct external-advisory writes are forbidden.');
    }

    public function rpc(string $function, array $payload = []): array
    {
        if ($function !== 'stage_module4_external_advisory_fetch') {
            throw new RuntimeException('Unexpected external-foundation RPC: ' . $function);
        }
        $this->rpcCalls[] = ['function' => $function, 'payload' => $payload];

        $new = 0;
        $updated = 0;
        $unchanged = 0;
        foreach ($payload['p_items'] as $item) {
            $key = $payload['p_source_code'] . ':' . $item['deduplication_key'];
            if (!array_key_exists($key, $this->stagedItems)) {
                $new++;
                $this->stagedItems[$key] = [
                    'hash' => $item['payload_hash'],
                    'version' => 1,
                ];
            } elseif ($this->stagedItems[$key]['hash'] === $item['payload_hash']) {
                $unchanged++;
            } else {
                $updated++;
                $this->stagedItems[$key] = [
                    'hash' => $item['payload_hash'],
                    'version' => $this->stagedItems[$key]['version'] + 1,
                ];
            }
        }

        return [[
            'outcome' => 'RECORDED',
            'fetch_run_id' => sprintf(
                '90000000-0000-4000-8000-%012d',
                count($this->rpcCalls)
            ),
            'source_code' => $payload['p_source_code'],
            'result' => $payload['p_result'],
            'fetched_item_count' => $payload['p_fetched_item_count'],
            'staged_item_count' => count($payload['p_items']),
            'new_item_count' => $new,
            'updated_item_count' => $updated,
            'unchanged_item_count' => $unchanged,
            'error_count' => $payload['p_error_count'],
        ]];
    }

    public function payloadVersion(string $sourceCode, string $deduplicationKey): ?int
    {
        $key = $sourceCode . ':' . $deduplicationKey;
        return $this->stagedItems[$key]['version'] ?? null;
    }
}

final class SuccessfulExternalProvider implements ExternalAdvisoryProviderInterface
{
    /** @param array<string, mixed> $item */
    public function __construct(private readonly array $item)
    {
    }

    public function sourceCode(): string
    {
        return 'PAGASA';
    }

    public function classification(): array
    {
        return (new PagasaExternalAdvisoryProvider())->classification();
    }

    public function fetch(): ExternalAdvisoryFetchResult
    {
        return ExternalAdvisoryFetchResult::success([$this->item]);
    }
}

final class TimeoutExternalProvider implements ExternalAdvisoryProviderInterface
{
    public function sourceCode(): string
    {
        return 'PAGASA';
    }

    public function classification(): array
    {
        return (new PagasaExternalAdvisoryProvider())->classification();
    }

    public function fetch(): ExternalAdvisoryFetchResult
    {
        throw new ExternalAdvisoryProviderException(
            'SIMULATED SECRET UPSTREAM TIMEOUT DETAIL'
        );
    }
}

final class BatchExternalProvider implements ExternalAdvisoryProviderInterface
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(private readonly array $items)
    {
    }

    public function sourceCode(): string
    {
        return 'PAGASA';
    }

    public function classification(): array
    {
        return (new PagasaExternalAdvisoryProvider())->classification();
    }

    public function fetch(): ExternalAdvisoryFetchResult
    {
        return ExternalAdvisoryFetchResult::success($this->items);
    }
}

$normalizer = new DrrmExternalAdvisoryNormalizer();
$baseItem = validExternalProviderItem();
$normalized = $normalizer->normalize('PAGASA', $baseItem);
assertExternalFoundation('ProviderIdIdentityMethod', $normalized['identity_method'], 'PROVIDER_ID');
assertExternalFoundation('TimestampNormalizedToUtc', $normalized['issued_at'], '2026-09-16T00:00:00+00:00');
assertExternalFoundation('DeduplicationKeyIsSha256', preg_match('/^[0-9a-f]{64}$/', $normalized['deduplication_key']), 1);
assertExternalFoundation('PayloadHashIsSha256', preg_match('/^[0-9a-f]{64}$/', $normalized['payload_hash']), 1);

$changedProviderItem = array_replace($baseItem, [
    'summary' => 'Revised official provider summary.',
    'raw_payload' => ['revision' => 2, 'provider_id' => 'PAGASA-ITEM-001'],
]);
$changedPayload = $normalizer->normalize('PAGASA', $changedProviderItem);
assertExternalFoundation(
    'StableProviderIdKeepsDuplicateIdentity',
    $changedPayload['deduplication_key'],
    $normalized['deduplication_key']
);
assertExternalFoundation(
    'ChangedProviderPayloadChangesHash',
    $changedPayload['payload_hash'] === $normalized['payload_hash'],
    false
);

$rawOrderVariant = $normalizer->normalize(
    'PAGASA',
    array_replace($baseItem, [
        'raw_payload' => ['revision' => 1, 'provider_id' => 'PAGASA-ITEM-001'],
    ])
);
assertExternalFoundation(
    'CanonicalPayloadHashIgnoresObjectKeyOrder',
    $rawOrderVariant['payload_hash'],
    $normalized['payload_hash']
);

$fallbackItem = $baseItem;
$fallbackItem['external_reference_id'] = null;
$fallback = $normalizer->normalize('PAGASA', $fallbackItem);
assertExternalFoundation('FallbackIdentityMethod', $fallback['identity_method'], 'DETERMINISTIC_FALLBACK');
assertExternalFoundation(
    'FallbackIdentityStableAcrossSummaryChange',
    $normalizer->normalize(
        'PAGASA',
        array_replace($fallbackItem, ['summary' => 'Updated text only.'])
    )['deduplication_key'],
    $fallback['deduplication_key']
);

assertExternalFoundationThrows(
    'ReversedProviderTimestampsRejected',
    DrrmExternalAdvisoryValidationException::class,
    fn () => $normalizer->normalize('PAGASA', array_replace($baseItem, [
        'valid_until' => '2026-09-15T18:00:00+08:00',
    ]))
);
assertExternalFoundationThrows(
    'TimezoneLessProviderTimestampRejected',
    DrrmExternalAdvisoryValidationException::class,
    fn () => $normalizer->normalize('PAGASA', array_replace($baseItem, [
        'issued_at' => '2026-09-16T08:00:00',
    ]))
);
assertExternalFoundationThrows(
    'UnknownProviderFieldRejected',
    DrrmExternalAdvisoryValidationException::class,
    fn () => $normalizer->normalize('PAGASA', $baseItem + ['fetch_url' => 'https://attacker.invalid'])
);
assertExternalFoundationThrows(
    'CombinedProviderResultCountIsBounded',
    InvalidArgumentException::class,
    fn () => new ExternalAdvisoryFetchResult(
        ExternalAdvisoryFetchResult::RESULT_PARTIAL,
        array_fill(0, ExternalAdvisoryFetchResult::MAX_ITEMS, $baseItem),
        1,
        'One provider item failed.'
    )
);

$pagasaReadinessProvider = new PagasaExternalAdvisoryProvider();
$classification = $pagasaReadinessProvider->classification();
assertExternalFoundation('PagasaOverallClassification', $classification['integration_status'], 'PARTIAL');
assertExternalFoundation('PagasaSourceKind', $classification['source_kind'], 'FORECAST_METADATA_API');
assertExternalFoundation('PagasaOperationalAdvisoriesPending', $classification['operational_advisory_status'], 'PENDING');
assertExternalFoundation('PagasaOperationalFetchUnsupported', $classification['supports_operational_advisory_fetch'], false);
$pagasaSkippedResult = $pagasaReadinessProvider->fetch();
assertExternalFoundation('PagasaReadinessResultIsSkipped', $pagasaSkippedResult->result(), 'SKIPPED');
assertExternalFoundation('PagasaReadinessFetchedItemCount', $pagasaSkippedResult->fetchedItemCount(), 0);
assertExternalFoundation(
    'PagasaReadinessAdapterStagesNoSyntheticItems',
    $pagasaSkippedResult->items(),
    []
);

$store = new ExternalFoundationStore();
$ingestion = new DrrmExternalAdvisoryIngestionService($store);
$firstRun = $ingestion->synchronize(
    new SuccessfulExternalProvider($baseItem),
    'USER:officer-42'
);
assertExternalFoundation('FirstFetchUsesStagingRpc', $store->rpcCalls[0]['function'], 'stage_module4_external_advisory_fetch');
assertExternalFoundation('FirstFetchCreatesStagedIdentity', $firstRun['new_item_count'], 1);
assertExternalFoundation('FirstFetchUpdatesNoIdentity', $firstRun['updated_item_count'], 0);
assertExternalFoundation(
    'FirstFetchInitializesPayloadVersion',
    $store->payloadVersion('PAGASA', $normalized['deduplication_key']),
    1
);
assertExternalFoundation('FetchNeverCreatesWarning', $firstRun['warning_created'], false);
assertExternalFoundation('FetchNeverActivatesWarning', $firstRun['warning_activated'], false);

$changedRun = $ingestion->synchronize(
    new SuccessfulExternalProvider($changedProviderItem),
    'USER:officer-42'
);
assertExternalFoundation('ChangedPayloadCreatesNoDuplicate', $changedRun['new_item_count'], 0);
assertExternalFoundation('ChangedPayloadUpdatesExistingItem', $changedRun['updated_item_count'], 1);
assertExternalFoundation('ChangedPayloadIsNotUnchanged', $changedRun['unchanged_item_count'], 0);
assertExternalFoundation(
    'ChangedPayloadIncrementsVersionExactlyOnce',
    $store->payloadVersion('PAGASA', $normalized['deduplication_key']),
    2
);

$unchangedRun = $ingestion->synchronize(
    new SuccessfulExternalProvider($changedProviderItem),
    'USER:officer-42'
);
assertExternalFoundation('RepeatedChangedPayloadCreatesNoDuplicate', $unchangedRun['new_item_count'], 0);
assertExternalFoundation('RepeatedChangedPayloadDoesNotUpdate', $unchangedRun['updated_item_count'], 0);
assertExternalFoundation('RepeatedChangedPayloadRecognizedUnchanged', $unchangedRun['unchanged_item_count'], 1);
assertExternalFoundation(
    'RepeatedChangedPayloadKeepsVersionTwo',
    $store->payloadVersion('PAGASA', $normalized['deduplication_key']),
    2
);

$skippedStore = new ExternalFoundationStore();
$skippedRun = (new DrrmExternalAdvisoryIngestionService($skippedStore))->synchronize(
    $pagasaReadinessProvider,
    'USER:officer-42'
);
assertExternalFoundation('SkippedProviderStagesNoItems', $skippedRun['staged_item_count'], 0);
assertExternalFoundation('SkippedProviderCreatesNoAdvisory', $skippedRun['new_item_count'], 0);
assertExternalFoundation('SkippedProviderCreatesNoWarning', $skippedRun['warning_created'], false);
assertExternalFoundation('SkippedProviderActivatesNoWarning', $skippedRun['warning_activated'], false);
assertExternalFoundation('SkippedProviderUsesOnlyStagingRpc', $skippedStore->rpcCalls[0]['function'], 'stage_module4_external_advisory_fetch');

$oversizedItems = [];
for ($index = 1; $index <= 5; $index++) {
    $oversizedItems[] = array_replace($baseItem, [
        'external_reference_id' => sprintf('PAGASA-LARGE-%03d', $index),
        'source_reference' => sprintf('https://example.invalid/large-%03d', $index),
        'raw_payload' => [
            'sequence' => $index,
            'secret_marker' => 'DO_NOT_EXPOSE_OVERSIZED_PROVIDER_CONTENT',
            'content' => str_repeat('x', 900000),
        ],
    ]);
}
$oversizedStore = new ExternalFoundationStore();
$oversizedIngestion = new DrrmExternalAdvisoryIngestionService($oversizedStore);
try {
    $oversizedIngestion->synchronize(
        new BatchExternalProvider($oversizedItems),
        'USER:officer-42'
    );
    throw new RuntimeException('OversizedAggregatePayloadRejected was not rejected.');
} catch (DrrmExternalAdvisoryIngestionException $exception) {
    assertExternalFoundation(
        'OversizedAggregatePayloadReturnsSafeError',
        $exception->getMessage(),
        'The external advisory batch exceeds the safe aggregate size limit.'
    );
    assertExternalFoundation(
        'OversizedAggregatePayloadHidesProviderContent',
        str_contains($exception->getMessage(), 'DO_NOT_EXPOSE'),
        false
    );
}
assertExternalFoundation('OversizedAggregatePayloadRejectedBeforeRpc', count($oversizedStore->rpcCalls), 0);

$timeoutRun = $ingestion->synchronize(
    new TimeoutExternalProvider(),
    'USER:officer-42'
);
$timeoutPayload = end($store->rpcCalls)['payload'];
assertExternalFoundation('TimeoutRecordedAsFailedRun', $timeoutRun['result'], 'FAILED');
assertExternalFoundation('TimeoutStagesNoItems', $timeoutRun['staged_item_count'], 0);
assertExternalFoundation(
    'TimeoutHidesProviderExceptionDetail',
    str_contains((string) $timeoutPayload['p_error_summary'], 'SECRET'),
    false
);
assertExternalFoundationThrows(
    'UntrustedActorRejected',
    DrrmExternalAdvisoryIngestionException::class,
    fn () => $ingestion->synchronize(
        new SuccessfulExternalProvider($baseItem),
        'BROWSER:attacker'
    )
);

$viewOnly = new DrrmEarlyWarningAuthorizationService(['VIEW'], false);
$creator = new DrrmEarlyWarningAuthorizationService(['CREATE_WARNING'], false);
assertExternalFoundation('ViewDoesNotAuthorizeSynchronization', $viewOnly->canSynchronizeExternalAdvisories(), false);
assertExternalFoundation('CreateWarningOwnsSynchronization', $creator->canSynchronizeExternalAdvisories(), true);

assertExternalFoundation(
    'SyncRequestAcceptsPagasaOnly',
    DrrmExternalAdvisorySyncRequest::sourceCodeFromInput(['source_code' => 'PAGASA']),
    'PAGASA'
);
foreach (['url', 'actor_reference', 'payload', 'items', 'provider_url', 'provider_config'] as $extraKey) {
    assertExternalFoundationThrows(
        'SyncRequestRejects_' . $extraKey,
        InvalidArgumentException::class,
        fn () => DrrmExternalAdvisorySyncRequest::sourceCodeFromInput([
            'source_code' => 'PAGASA',
            $extraKey => 'browser-controlled-value',
        ])
    );
}
assertExternalFoundationThrows(
    'SyncRequestRejectsUnknownSource',
    InvalidArgumentException::class,
    fn () => DrrmExternalAdvisorySyncRequest::sourceCodeFromInput(['source_code' => 'UNVERIFIED'])
);

$publicResult = (new DrrmCitizenWarningReadService($store))->activeWarnings(
    new DateTimeImmutable('2026-09-16T00:00:00+00:00')
);
$publicJson = json_encode($publicResult, JSON_THROW_ON_ERROR);
foreach ([
    'external_advisories',
    'external_advisory_fetch_runs',
    'raw_payload',
    'normalized_payload',
    'payload_hash',
    'initiated_by_actor_reference',
] as $forbiddenField) {
    assertExternalFoundation(
        'CitizenExcludes_' . $forbiddenField,
        str_contains($publicJson, $forbiddenField),
        false
    );
}

$migration = file_get_contents(
    __DIR__ . '/../supabase/migrations/20260916000100_module4_phase4b1_external_advisory_foundation.sql'
);
foreach ([
    'create table public.external_advisories',
    'create table public.external_advisory_fetch_runs',
    'unique (source_id, deduplication_key)',
    'stage_module4_external_advisory_fetch',
    'security definer',
    'set search_path = pg_catalog, public',
    'revoke all on table public.external_advisories from public, anon, authenticated, service_role',
    'grant select on table public.external_advisories to service_role',
    'to service_role',
    "review_status = 'DRAFT_CREATED' and linked_warning_id is not null",
    'pg_catalog.octet_length(p_items::text) > 4194304',
    "'phase4b_rpc_public_execute_revoked'",
    "'phase4b_rpc_anon_execute_revoked'",
    "'phase4b_rpc_authenticated_execute_revoked'",
    'actual.confdeltype::text = expected.delete_action',
    'relation.relname = expected.table_name',
] as $requiredSql) {
    assertExternalFoundation(
        'MigrationContains_' . preg_replace('/[^A-Za-z0-9]+/', '_', $requiredSql),
        is_string($migration) && str_contains(strtolower($migration), strtolower($requiredSql)),
        true
    );
}
assertExternalFoundation(
    'MigrationUsesExactCiventralSeedPromotion',
    is_string($migration) && preg_match(
        "/update public\\.early_warning_sources\s+set integration_status = 'CONNECTED'\s+where source_code = 'CIVENTRAL'\s+and source_name = 'CIVENTRAL DRRM'\s+and source_type = 'INTERNAL_SYSTEM'\s+and integration_status = 'PENDING'\s+and is_active = true;/s",
        $migration
    ),
    true
);
assertExternalFoundation(
    'MigrationUsesExactPagasaSeedPromotion',
    is_string($migration) && preg_match(
        "/update public\\.early_warning_sources\s+set integration_status = 'PARTIAL'\s+where source_code = 'PAGASA'\s+and source_name = 'DOST-PAGASA'\s+and source_type = 'GOVERNMENT_AGENCY'\s+and integration_status = 'PENDING'\s+and is_active = true;/s",
        $migration
    ),
    true
);
assertExternalFoundation(
    'MigrationExcludesBroadSourcePromotion',
    is_string($migration) && str_contains($migration, "source_code in ('CIVENTRAL', 'PAGASA')"),
    false
);
assertExternalFoundation(
    'MigrationDoesNotInsertWarnings',
    is_string($migration)
        && preg_match('/insert\s+into\s+public\.early_warnings/i', $migration) === 0,
    true
);

$phase4bSource = implode(PHP_EOL, array_map(
    static fn (string $path): string => (string) file_get_contents($path),
    [
        __DIR__ . '/../api/drrm/external-advisory-sync.php',
        __DIR__ . '/../src/Services/DrrmExternalAdvisoryIngestionService.php',
        __DIR__ . '/../src/Services/PagasaExternalAdvisoryProvider.php',
        __DIR__ . '/../supabase/migrations/20260916000100_module4_phase4b1_external_advisory_foundation.sql',
    ]
));
foreach ([
    'create_module4_warning_draft',
    'update_module4_warning_draft',
    'change_module4_warning_status',
] as $forbiddenLifecycleCall) {
    assertExternalFoundation(
        'Phase4BExcludes_' . $forbiddenLifecycleCall,
        str_contains($phase4bSource, $forbiddenLifecycleCall),
        false
    );
}
foreach ([
    '/insert\s+into\s+public\.early_warning(?:s|_areas)/i',
    '/update\s+public\.early_warning(?:s|_areas)/i',
    '/delete\s+from\s+public\.early_warning(?:s|_areas)/i',
] as $index => $forbiddenDml) {
    assertExternalFoundation(
        'Phase4BExcludesWarningDml_' . $index,
        preg_match($forbiddenDml, $phase4bSource),
        0
    );
}

$pagasaClient = file_get_contents(__DIR__ . '/../src/Services/PagasaTenDayClient.php');
assertExternalFoundation(
    'PagasaResponseSizeIsBounded',
    is_string($pagasaClient)
        && str_contains($pagasaClient, 'MAX_RESPONSE_BYTES')
        && str_contains($pagasaClient, 'CURLOPT_WRITEFUNCTION'),
    true
);

echo 'DatabaseAccess=NONE' . PHP_EOL;
echo 'DatabaseWrites=NONE' . PHP_EOL;
echo 'ExternalAdvisoryAutoPublication=NONE' . PHP_EOL;
echo 'Module 4 Phase 4B.1 external advisory foundation: OK' . PHP_EOL;
