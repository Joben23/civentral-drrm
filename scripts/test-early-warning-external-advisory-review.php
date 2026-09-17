<?php

declare(strict_types=1);

use App\Services\DrrmCitizenWarningReadService;
use App\Services\DrrmDataStoreInterface;
use App\Services\DrrmEarlyWarningConflictException;
use App\Services\DrrmEarlyWarningValidationException;
use App\Services\DrrmEarlyWarningWriteException;
use App\Services\DrrmExternalAdvisoryReviewService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/DrrmExternalAdvisoryReviewService.php';
require_once __DIR__ . '/../src/Services/DrrmCitizenWarningReadService.php';

/** @param mixed $actual @param mixed $expected */
function assertExternalReview(string $name, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($name . ' failed: ' . json_encode($actual));
    }
    echo $name . '=PASS' . PHP_EOL;
}

/** @param class-string<Throwable> $class @param callable(): mixed $operation */
function assertExternalReviewThrows(string $name, string $class, callable $operation): void
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

function canonicalReviewCheck(string $definition): string
{
    $withoutCasts = preg_replace(
        '/::(?:text|jsonb)/i',
        '',
        str_replace('pg_catalog.', '', $definition)
    );
    $normalized = is_string($withoutCasts)
        ? preg_replace('/[\s()]/', '', $withoutCasts)
        : null;
    if (!is_string($normalized)) {
        throw new RuntimeException('Unable to normalize a review constraint definition.');
    }
    return strtolower($normalized);
}

function verifierExpectedCheck(string $sql, string $tag): string
{
    $marker = '$' . $tag . '$';
    $start = strpos($sql, $marker);
    $end = $start === false ? false : strpos($sql, $marker, $start + strlen($marker));
    if ($start === false || $end === false) {
        throw new RuntimeException('Verifier expected CHECK definition is missing.');
    }
    return substr($sql, $start + strlen($marker), $end - $start - strlen($marker));
}

final class ExternalReviewStore implements DrrmDataStoreInterface
{
    public const ADVISORY_ID = '70000000-0000-4000-8000-000000000001';
    public const SOURCE_ID = '10000000-0000-4000-8000-000000000001';
    public const RUN_ID = '80000000-0000-4000-8000-000000000001';
    public const WARNING_ID = '50000000-0000-4000-8000-000000000001';

    /** @var array<string, mixed> */
    public array $advisory;
    /** @var array<string, array<string, mixed>> */
    public array $warnings = [];
    /** @var list<array<string, mixed>> */
    public array $warningHistory = [];
    /** @var list<array<string, mixed>> */
    public array $reviewHistory = [];
    /** @var list<array{function: string, payload: array<string, mixed>}> */
    public array $rpcCalls = [];
    public bool $failConversion = false;
    /** @var array<string, mixed>|null */
    public ?array $phase4aReturnOverride = null;
    public bool $omitPersistedWarning = false;
    public ?string $persistedWarningStatusOverride = null;
    public ?int $persistedWarningRevisionOverride = null;
    public bool $omitCreatedHistory = false;

    public function __construct()
    {
        $this->advisory = [
            'id' => self::ADVISORY_ID,
            'source_id' => self::SOURCE_ID,
            'external_reference_id' => 'PAGASA-EXT-001',
            'source_reference' => 'https://example.invalid/pagasa/001',
            'title' => 'Official rainfall advisory',
            'advisory_type' => 'WEATHER_ADVISORY',
            'hazard_type' => 'HEAVY_RAINFALL',
            'summary' => 'Official provider summary for human review.',
            'issued_at' => '2026-09-16T00:00:00+00:00',
            'valid_until' => '2026-09-16T12:00:00+00:00',
            'first_fetched_at' => '2026-09-16T00:01:00+00:00',
            'fetched_at' => '2026-09-16T00:02:00+00:00',
            'first_fetch_run_id' => self::RUN_ID,
            'last_fetch_run_id' => self::RUN_ID,
            'payload_hash' => str_repeat('a', 64),
            'payload_version' => 1,
            'review_status' => 'PENDING_REVIEW',
            'linked_warning_id' => null,
            'reviewed_at' => null,
            'reviewed_by_actor_reference' => null,
            'reviewed_payload_version' => null,
            'reviewed_payload_hash' => null,
            'created_at' => '2026-09-16T00:01:00+00:00',
            'updated_at' => '2026-09-16T00:02:00+00:00',
            'raw_payload' => ['must_not' => 'leave the service'],
            'normalized_payload' => ['must_not' => 'leave the service'],
        ];
    }

    public function get(string $resource, array $query = []): array
    {
        if ($resource === 'external_advisories') {
            if (isset($query['id']) && $query['id'] !== 'eq.' . self::ADVISORY_ID) {
                return [];
            }
            if (isset($query['review_status'])
                && $query['review_status'] !== 'eq.' . $this->advisory['review_status']) {
                return [];
            }
            return [$this->advisory];
        }
        if ($resource === 'early_warning_sources') {
            return [[
                'id' => self::SOURCE_ID, 'source_code' => 'PAGASA',
                'source_name' => 'DOST-PAGASA', 'source_type' => 'GOVERNMENT_AGENCY',
                'is_active' => true,
            ]];
        }
        if ($resource === 'external_advisory_fetch_runs') {
            return [[
                'id' => self::RUN_ID, 'result' => 'SUCCESS',
                'fetched_item_count' => 1, 'staged_item_count' => 1,
                'new_item_count' => 1, 'updated_item_count' => 0,
                'unchanged_item_count' => 0, 'error_count' => 0,
                'started_at' => '2026-09-16T00:00:59+00:00',
                'finished_at' => '2026-09-16T00:01:01+00:00',
            ]];
        }
        if ($resource === 'external_advisory_review_history') {
            return array_reverse($this->reviewHistory);
        }
        if ($resource === 'risk_levels') {
            return [['risk_level_id' => 3, 'code' => 'HIGH', 'name' => 'High', 'is_active' => true]];
        }
        if ($resource === 'early_warnings') {
            $rows = array_values($this->warnings);
            return ($query['status'] ?? null) === 'eq.ACTIVE'
                ? array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === 'ACTIVE'))
                : $rows;
        }
        throw new RuntimeException('Unexpected review test read: ' . $resource);
    }

    public function post(string $resource, array $payload, array $query = []): array
    {
        throw new RuntimeException('Direct review-table writes are forbidden.');
    }

    public function simulateProviderRefresh(): void
    {
        $this->advisory['payload_version'] += 1;
        $this->advisory['payload_hash'] = str_repeat('b', 64);
        $this->advisory['title'] = 'Revised official rainfall advisory';
        $this->advisory['summary'] = 'Revised provider content after the review decision.';
        $this->advisory['raw_payload'] = ['later_provider_content' => true];
        $this->advisory['normalized_payload'] = ['later_provider_content' => true];
    }

    /** @return array<string, mixed> */
    private function reviewedSnapshot(): array
    {
        return [
            'source_code' => 'PAGASA',
            'source_name' => 'DOST-PAGASA',
            'external_reference_id' => $this->advisory['external_reference_id'],
            'source_reference' => $this->advisory['source_reference'],
            'title' => $this->advisory['title'],
            'advisory_type' => $this->advisory['advisory_type'],
            'hazard_type' => $this->advisory['hazard_type'],
            'summary' => $this->advisory['summary'],
            'issued_at' => $this->advisory['issued_at'],
            'valid_until' => $this->advisory['valid_until'],
            'payload_version' => $this->advisory['payload_version'],
            'payload_hash' => $this->advisory['payload_hash'],
        ];
    }

    public function rpc(string $function, array $payload = []): array
    {
        $this->rpcCalls[] = ['function' => $function, 'payload' => $payload];
        if (!in_array($function, [
            'dismiss_module4_external_advisory',
            'convert_module4_external_advisory_to_draft',
        ], true)) {
            throw new RuntimeException('Unexpected review test RPC: ' . $function);
        }
        if ($this->advisory['review_status'] !== 'PENDING_REVIEW') {
            return ['outcome' => 'REVIEW_CONFLICT'];
        }
        if ($payload['p_expected_payload_version'] !== $this->advisory['payload_version']) {
            return ['outcome' => 'PAYLOAD_VERSION_CONFLICT'];
        }
        return $function === 'dismiss_module4_external_advisory'
            ? $this->dismiss($payload)
            : $this->convert($payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function dismiss(array $payload): array
    {
        $now = '2026-09-16T03:00:00+00:00';
        $this->advisory['review_status'] = 'DISMISSED';
        $this->advisory['reviewed_at'] = $now;
        $this->advisory['reviewed_by_actor_reference'] = $payload['p_actor_reference'];
        $this->advisory['reviewed_payload_version'] = $this->advisory['payload_version'];
        $this->advisory['reviewed_payload_hash'] = $this->advisory['payload_hash'];
        $this->reviewHistory[] = [
            'id' => '90000000-0000-4000-8000-000000000001',
            'external_advisory_id' => self::ADVISORY_ID,
            'event_type' => 'DISMISSED',
            'actor_reference' => $payload['p_actor_reference'],
            'occurred_at' => $now,
            'resulting_review_status' => 'DISMISSED',
            'payload_version' => $this->advisory['payload_version'],
            'payload_hash' => $this->advisory['payload_hash'],
            'linked_warning_id' => null,
            'reviewed_snapshot' => $this->reviewedSnapshot(),
        ];
        return [
            'outcome' => 'DISMISSED', 'external_advisory_id' => self::ADVISORY_ID,
            'review_status' => 'DISMISSED', 'payload_version' => $this->advisory['payload_version'],
            'reviewed_at' => $now, 'linked_warning_id' => null,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function convert(array $payload): array
    {
        if ($payload['p_expected_source_id'] !== self::SOURCE_ID) {
            return ['outcome' => 'SOURCE_CONFLICT'];
        }

        $nextAdvisory = $this->advisory;
        $nextWarnings = $this->warnings;
        $nextWarningHistory = $this->warningHistory;
        $nextReviewHistory = $this->reviewHistory;
        $now = '2026-09-16T03:05:00+00:00';
        $warning = [
            'id' => self::WARNING_ID,
            'source_id' => self::SOURCE_ID,
            'title' => $payload['p_title'],
            'hazard_type' => $payload['p_hazard_type'],
            'warning_level_id' => $payload['p_warning_level_id'],
            'summary' => $payload['p_summary'],
            'status' => 'DRAFT',
            'issued_at' => $payload['p_issued_at'],
            'valid_until' => $payload['p_valid_until'],
            'source_reference' => $payload['p_source_reference'],
            'revision' => 1,
            'updated_at' => $now,
        ];
        $nextWarnings[self::WARNING_ID] = $warning;
        $nextWarningHistory[] = [
            'warning_id' => self::WARNING_ID, 'event_type' => 'CREATED',
            'actor_reference' => $payload['p_actor_reference'],
            'resulting_status' => 'DRAFT', 'resulting_revision' => 1,
        ];
        $phase4aResult = $this->phase4aReturnOverride ?? [
            'outcome' => 'CREATED',
            'id' => self::WARNING_ID,
            'status' => 'DRAFT',
            'revision' => 1,
        ];
        if (($phase4aResult['outcome'] ?? null) !== 'CREATED'
            || ($phase4aResult['status'] ?? null) !== 'DRAFT'
            || ($phase4aResult['revision'] ?? null) !== 1
            || !is_string($phase4aResult['id'] ?? null)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $phase4aResult['id']) !== 1) {
            throw new RuntimeException('Simulated Phase 4A return validation failure.');
        }
        if ($this->omitPersistedWarning) {
            unset($nextWarnings[self::WARNING_ID]);
        }
        if ($this->persistedWarningStatusOverride !== null) {
            $nextWarnings[self::WARNING_ID]['status'] = $this->persistedWarningStatusOverride;
        }
        if ($this->persistedWarningRevisionOverride !== null) {
            $nextWarnings[self::WARNING_ID]['revision'] = $this->persistedWarningRevisionOverride;
        }
        $persisted = $nextWarnings[$phase4aResult['id']] ?? null;
        if ($persisted === null
            || $persisted['status'] !== 'DRAFT'
            || $persisted['revision'] !== 1) {
            throw new RuntimeException('Simulated persisted draft validation failure.');
        }
        if ($this->omitCreatedHistory) {
            $nextWarningHistory = [];
        }
        $createdEvents = array_values(array_filter(
            $nextWarningHistory,
            static fn (array $event): bool => $event['warning_id'] === self::WARNING_ID
                && $event['event_type'] === 'CREATED'
        ));
        if (count($createdEvents) !== 1
            || $createdEvents[0]['actor_reference'] !== $payload['p_actor_reference']
            || $createdEvents[0]['resulting_status'] !== 'DRAFT'
            || $createdEvents[0]['resulting_revision'] !== 1) {
            throw new RuntimeException('Simulated CREATED history validation failure.');
        }
        $nextAdvisory['review_status'] = 'DRAFT_CREATED';
        $nextAdvisory['linked_warning_id'] = self::WARNING_ID;
        $nextAdvisory['reviewed_at'] = $now;
        $nextAdvisory['reviewed_by_actor_reference'] = $payload['p_actor_reference'];
        $nextAdvisory['reviewed_payload_version'] = $this->advisory['payload_version'];
        $nextAdvisory['reviewed_payload_hash'] = $this->advisory['payload_hash'];
        $nextReviewHistory[] = [
            'id' => '90000000-0000-4000-8000-000000000002',
            'external_advisory_id' => self::ADVISORY_ID,
            'event_type' => 'DRAFT_CREATED',
            'actor_reference' => $payload['p_actor_reference'],
            'occurred_at' => $now,
            'resulting_review_status' => 'DRAFT_CREATED',
            'payload_version' => $this->advisory['payload_version'],
            'payload_hash' => $this->advisory['payload_hash'],
            'linked_warning_id' => self::WARNING_ID,
            'reviewed_snapshot' => $this->reviewedSnapshot(),
        ];

        if ($this->failConversion) {
            throw new RuntimeException('Simulated transaction failure after provisional work.');
        }

        $this->advisory = $nextAdvisory;
        $this->warnings = $nextWarnings;
        $this->warningHistory = $nextWarningHistory;
        $this->reviewHistory = $nextReviewHistory;
        return [
            'outcome' => 'DRAFT_CREATED', 'external_advisory_id' => self::ADVISORY_ID,
            'review_status' => 'DRAFT_CREATED', 'payload_version' => $this->advisory['payload_version'],
            'reviewed_at' => $now, 'linked_warning_id' => self::WARNING_ID,
            'id' => self::WARNING_ID, 'title' => $warning['title'],
            'status' => 'DRAFT', 'revision' => 1, 'updated_at' => $now,
            'affected_area_count' => count($payload['p_areas']),
        ];
    }
}

/** @return array<string, mixed> */
function validExternalConversionInput(int $version = 1): array
{
    return [
        'external_advisory_id' => ExternalReviewStore::ADVISORY_ID,
        'expected_payload_version' => $version,
        'title' => 'Caloocan heavy rainfall warning draft',
        'hazard_type' => 'HEAVY_RAINFALL',
        'warning_level' => 'HIGH',
        'source_code' => 'PAGASA',
        'summary' => 'Officer-confirmed warning definition based on the staged advisory.',
        'issued_at' => '2026-09-16T00:00:00+00:00',
        'valid_until' => '2026-09-16T12:00:00+00:00',
        'source_reference' => 'https://example.invalid/pagasa/001',
        'scope_type' => 'CITY',
        'barangay_ids' => [],
    ];
}

/** @param callable(ExternalReviewStore): void $configure */
function assertFailedConversionRollsBack(string $name, callable $configure): void
{
    $store = new ExternalReviewStore();
    $configure($store);
    $service = new DrrmExternalAdvisoryReviewService($store);
    assertExternalReviewThrows($name, DrrmEarlyWarningWriteException::class, static function () use ($service): void {
        $service->convertToDraft(validExternalConversionInput(), 'USER:42');
    });
    assertExternalReview($name . 'KeepsPending', $store->advisory['review_status'], 'PENDING_REVIEW');
    assertExternalReview($name . 'LeavesNoWarning', count($store->warnings), 0);
    assertExternalReview($name . 'LeavesNoReviewAudit', count($store->reviewHistory), 0);
}

$readStore = new ExternalReviewStore();
$readService = new DrrmExternalAdvisoryReviewService($readStore);
$list = $readService->listAdvisories();
assertExternalReview('PendingListCount', count($list), 1);
assertExternalReview('ListOmitsRawPayload', array_key_exists('raw_payload', $list[0]), false);
assertExternalReview('ListOmitsNormalizedPayload', array_key_exists('normalized_payload', $list[0]), false);
assertExternalReview('ListOmitsPayloadHash', array_key_exists('payload_hash', $list[0]), false);
$detail = $readService->advisoryDetail(ExternalReviewStore::ADVISORY_ID);
assertExternalReview('DetailHasSafeProvenance', $detail['provenance']['latest_fetch']['result'], 'SUCCESS');
assertExternalReview('DetailReviewHistoryInitiallyEmpty', $detail['review_history'], []);
assertExternalReview('DetailOmitsRawPayload', array_key_exists('raw_payload', $detail), false);

$dismissStore = new ExternalReviewStore();
$dismissService = new DrrmExternalAdvisoryReviewService($dismissStore);
$dismissed = $dismissService->dismiss([
    'external_advisory_id' => ExternalReviewStore::ADVISORY_ID,
    'expected_payload_version' => 1,
], 'USER:42');
assertExternalReview('DismissTransition', $dismissed['review_status'], 'DISMISSED');
assertExternalReview('DismissCreatesNoWarning', count($dismissStore->warnings), 0);
assertExternalReview('DismissAuditAppended', $dismissStore->reviewHistory[0]['event_type'], 'DISMISSED');
assertExternalReview('DismissActorIsTrustedArgument', $dismissStore->reviewHistory[0]['actor_reference'], 'USER:42');
assertExternalReview('DismissReviewedVersionSnapshot', $dismissStore->advisory['reviewed_payload_version'], 1);
assertExternalReview('DismissReviewedHashSnapshot', $dismissStore->advisory['reviewed_payload_hash'], str_repeat('a', 64));
assertExternalReview('DismissAuditHashSnapshot', $dismissStore->reviewHistory[0]['payload_hash'], str_repeat('a', 64));
$dismissSnapshot = $dismissStore->reviewHistory[0]['reviewed_snapshot'];
assertExternalReview('DismissSnapshotFieldSet', array_keys($dismissSnapshot), [
    'source_code', 'source_name', 'external_reference_id', 'source_reference',
    'title', 'advisory_type', 'hazard_type', 'summary', 'issued_at',
    'valid_until', 'payload_version', 'payload_hash',
]);
assertExternalReview('DismissSnapshotTitle', $dismissSnapshot['title'], 'Official rainfall advisory');
assertExternalReview('DismissSnapshotSource', $dismissSnapshot['source_name'], 'DOST-PAGASA');
assertExternalReview('DismissSnapshotVersion', $dismissSnapshot['payload_version'], 1);
assertExternalReview('DismissSnapshotHash', $dismissSnapshot['payload_hash'], str_repeat('a', 64));
assertExternalReview('DismissSnapshotOmitsRaw', array_key_exists('raw_payload', $dismissSnapshot), false);
assertExternalReview('DismissSnapshotOmitsConfiguration', array_key_exists('provider_config', $dismissSnapshot), false);
assertExternalReview('DismissedPendingListIsEmpty', $dismissService->listAdvisories(), []);
assertExternalReviewThrows('DoubleDismissRejected', DrrmEarlyWarningConflictException::class, static function () use ($dismissService): void {
    $dismissService->dismiss([
        'external_advisory_id' => ExternalReviewStore::ADVISORY_ID,
        'expected_payload_version' => 1,
    ], 'USER:42');
});
$dismissStore->simulateProviderRefresh();
assertExternalReview('DismissedCurrentVersionCanRefresh', $dismissStore->advisory['payload_version'], 2);
assertExternalReview('DismissedCurrentTitleCanRefresh', $dismissStore->advisory['title'], 'Revised official rainfall advisory');
assertExternalReview('DismissedStatusRemainsTerminal', $dismissStore->advisory['review_status'], 'DISMISSED');
assertExternalReview('DismissedReviewedAtSurvivesRefresh', $dismissStore->advisory['reviewed_at'], '2026-09-16T03:00:00+00:00');
assertExternalReview('DismissedActorSurvivesRefresh', $dismissStore->advisory['reviewed_by_actor_reference'], 'USER:42');
assertExternalReview('DismissedReviewedVersionRemainsOne', $dismissStore->advisory['reviewed_payload_version'], 1);
assertExternalReview('DismissedReviewedHashRemainsOne', $dismissStore->advisory['reviewed_payload_hash'], str_repeat('a', 64));
assertExternalReview('DismissedSnapshotRemainsOriginal', $dismissStore->reviewHistory[0]['reviewed_snapshot'], $dismissSnapshot);

$staleStore = new ExternalReviewStore();
$staleStore->simulateProviderRefresh();
$staleService = new DrrmExternalAdvisoryReviewService($staleStore);
assertExternalReviewThrows('ProviderRefreshMakesReviewStale', DrrmEarlyWarningConflictException::class, static function () use ($staleService): void {
    $staleService->convertToDraft(validExternalConversionInput(1), 'USER:42');
});
assertExternalReview('StaleReviewDoesNotCreateWarning', count($staleStore->warnings), 0);
assertExternalReview('StaleReviewDoesNotChangeStatus', $staleStore->advisory['review_status'], 'PENDING_REVIEW');
assertExternalReviewThrows('ProviderRefreshMakesDismissalStale', DrrmEarlyWarningConflictException::class, static function () use ($staleService): void {
    $staleService->dismiss([
        'external_advisory_id' => ExternalReviewStore::ADVISORY_ID,
        'expected_payload_version' => 1,
    ], 'USER:42');
});

$conversionStore = new ExternalReviewStore();
$conversionService = new DrrmExternalAdvisoryReviewService($conversionStore);
$converted = $conversionService->convertToDraft(validExternalConversionInput(), 'EMPLOYEE:DRRM-7');
assertExternalReview('DraftConversionStatus', $converted['review_status'], 'DRAFT_CREATED');
assertExternalReview('ConvertedWarningStaysDraft', $converted['warning']['status'], 'DRAFT');
assertExternalReview('ConvertedWarningRevisionOne', $converted['warning']['revision'], 1);
assertExternalReview('NoAutomaticActivation', $converted['warning_activated'], false);
assertExternalReview('ProvenanceWarningLink', $conversionStore->advisory['linked_warning_id'], ExternalReviewStore::WARNING_ID);
assertExternalReview('CreatedLifecycleEvent', $conversionStore->warningHistory[0]['event_type'], 'CREATED');
assertExternalReview('CreatedLifecycleResultDraft', $conversionStore->warningHistory[0]['resulting_status'], 'DRAFT');
assertExternalReview('DraftCreatedReviewAudit', $conversionStore->reviewHistory[0]['event_type'], 'DRAFT_CREATED');
assertExternalReview('ConversionReviewedVersionSnapshot', $conversionStore->advisory['reviewed_payload_version'], 1);
assertExternalReview('ConversionReviewedHashSnapshot', $conversionStore->advisory['reviewed_payload_hash'], str_repeat('a', 64));
assertExternalReview('ConversionAuditHashSnapshot', $conversionStore->reviewHistory[0]['payload_hash'], str_repeat('a', 64));
$conversionSnapshot = $conversionStore->reviewHistory[0]['reviewed_snapshot'];
assertExternalReview('ConversionSnapshotTitle', $conversionSnapshot['title'], 'Official rainfall advisory');
assertExternalReview('ConversionSnapshotVersion', $conversionSnapshot['payload_version'], 1);
assertExternalReview('ConversionSnapshotHash', $conversionSnapshot['payload_hash'], str_repeat('a', 64));
assertExternalReview('ConversionSnapshotOmitsRaw', array_key_exists('raw_payload', $conversionSnapshot), false);
assertExternalReview('ConversionSnapshotOmitsWarningLink', array_key_exists('linked_warning_id', $conversionSnapshot), false);
assertExternalReview('HumanAreaSelectionPassedToPhase4A', $conversionStore->rpcCalls[0]['payload']['p_areas'][0]['scope_type'], 'CITY');
assertExternalReview('TrustedConversionActor', $conversionStore->warningHistory[0]['actor_reference'], 'EMPLOYEE:DRRM-7');
assertExternalReviewThrows('DuplicateConversionRejected', DrrmEarlyWarningConflictException::class, static function () use ($conversionService): void {
    $conversionService->convertToDraft(validExternalConversionInput(), 'EMPLOYEE:DRRM-7');
});
$conversionStore->simulateProviderRefresh();
assertExternalReview('ConvertedCurrentVersionCanRefresh', $conversionStore->advisory['payload_version'], 2);
assertExternalReview('ConvertedStatusRemainsTerminal', $conversionStore->advisory['review_status'], 'DRAFT_CREATED');
assertExternalReview('ConvertedWarningLinkSurvivesRefresh', $conversionStore->advisory['linked_warning_id'], ExternalReviewStore::WARNING_ID);
assertExternalReview('ConvertedReviewedAtSurvivesRefresh', $conversionStore->advisory['reviewed_at'], '2026-09-16T03:05:00+00:00');
assertExternalReview('ConvertedActorSurvivesRefresh', $conversionStore->advisory['reviewed_by_actor_reference'], 'EMPLOYEE:DRRM-7');
assertExternalReview('ConvertedReviewedVersionRemainsOne', $conversionStore->advisory['reviewed_payload_version'], 1);
assertExternalReview('ConvertedReviewedHashRemainsOne', $conversionStore->advisory['reviewed_payload_hash'], str_repeat('a', 64));
assertExternalReview('ConvertedSnapshotRemainsOriginal', $conversionStore->reviewHistory[0]['reviewed_snapshot'], $conversionSnapshot);

$publicProjection = (new DrrmCitizenWarningReadService($conversionStore))->activeWarnings(
    new DateTimeImmutable('2026-09-16T04:00:00+00:00')
);
assertExternalReview('CitizenReceivesNoDraft', $publicProjection['active_warning_count'], 0);

$rollbackStore = new ExternalReviewStore();
$rollbackStore->failConversion = true;
$rollbackService = new DrrmExternalAdvisoryReviewService($rollbackStore);
assertExternalReviewThrows('ConversionFailureSurfaced', DrrmEarlyWarningWriteException::class, static function () use ($rollbackService): void {
    $rollbackService->convertToDraft(validExternalConversionInput(), 'USER:42');
});
assertExternalReview('RollbackLeavesPendingReview', $rollbackStore->advisory['review_status'], 'PENDING_REVIEW');
assertExternalReview('RollbackLeavesNoWarning', count($rollbackStore->warnings), 0);
assertExternalReview('RollbackLeavesNoAudit', count($rollbackStore->reviewHistory), 0);

$validPhase4aResult = [
    'outcome' => 'CREATED',
    'id' => ExternalReviewStore::WARNING_ID,
    'status' => 'DRAFT',
    'revision' => 1,
];
$badPhase4aResults = [
    'MissingOutcome' => ['id' => ExternalReviewStore::WARNING_ID, 'status' => 'DRAFT', 'revision' => 1],
    'MissingId' => ['outcome' => 'CREATED', 'status' => 'DRAFT', 'revision' => 1],
    'MissingStatus' => ['outcome' => 'CREATED', 'id' => ExternalReviewStore::WARNING_ID, 'revision' => 1],
    'MissingRevision' => ['outcome' => 'CREATED', 'id' => ExternalReviewStore::WARNING_ID, 'status' => 'DRAFT'],
    'NullOutcome' => array_replace($validPhase4aResult, ['outcome' => null]),
    'NullId' => array_replace($validPhase4aResult, ['id' => null]),
    'NullStatus' => array_replace($validPhase4aResult, ['status' => null]),
    'NullRevision' => array_replace($validPhase4aResult, ['revision' => null]),
    'WrongStatus' => array_replace($validPhase4aResult, ['status' => 'ACTIVE']),
    'WrongRevision' => array_replace($validPhase4aResult, ['revision' => 2]),
    'InvalidId' => array_replace($validPhase4aResult, ['id' => 'not-a-uuid']),
];
foreach ($badPhase4aResults as $case => $badResult) {
    assertFailedConversionRollsBack('RejectPhase4A' . $case, static function (ExternalReviewStore $store) use ($badResult): void {
        $store->phase4aReturnOverride = $badResult;
    });
}
assertFailedConversionRollsBack('RejectMissingPersistedWarning', static function (ExternalReviewStore $store): void {
    $store->omitPersistedWarning = true;
});
assertFailedConversionRollsBack('RejectPersistedActiveWarning', static function (ExternalReviewStore $store): void {
    $store->persistedWarningStatusOverride = 'ACTIVE';
});
assertFailedConversionRollsBack('RejectPersistedWrongRevision', static function (ExternalReviewStore $store): void {
    $store->persistedWarningRevisionOverride = 2;
});
assertFailedConversionRollsBack('RejectMissingCreatedHistory', static function (ExternalReviewStore $store): void {
    $store->omitCreatedHistory = true;
});

$missingLevel = validExternalConversionInput();
$missingLevel['warning_level'] = '';
assertExternalReviewThrows('NoInferredWarningLevel', DrrmEarlyWarningValidationException::class, static function () use ($readService, $missingLevel): void {
    $readService->convertToDraft($missingLevel, 'USER:42');
});
$missingArea = validExternalConversionInput();
$missingArea['scope_type'] = '';
assertExternalReviewThrows('NoInferredAffectedArea', DrrmEarlyWarningValidationException::class, static function () use ($readService, $missingArea): void {
    $readService->convertToDraft($missingArea, 'USER:42');
});
assertExternalReview('InvalidHumanChoicesNeverInvokeRpc', count($readStore->rpcCalls), 0);

$untrustedActorInput = validExternalConversionInput();
$untrustedActorInput['actor_reference'] = 'USER:browser-controlled';
assertExternalReviewThrows('BrowserActorFieldRejected', DrrmEarlyWarningValidationException::class, static function () use ($readService, $untrustedActorInput): void {
    $readService->convertToDraft($untrustedActorInput, 'USER:42');
});
$untrustedSnapshotInput = validExternalConversionInput();
$untrustedSnapshotInput['reviewed_snapshot'] = ['title' => 'browser replacement'];
assertExternalReviewThrows('BrowserSnapshotFieldRejected', DrrmEarlyWarningValidationException::class, static function () use ($readService, $untrustedSnapshotInput): void {
    $readService->convertToDraft($untrustedSnapshotInput, 'USER:42');
});
$untrustedHashInput = validExternalConversionInput();
$untrustedHashInput['payload_hash'] = str_repeat('f', 64);
assertExternalReviewThrows('BrowserHashFieldRejected', DrrmEarlyWarningValidationException::class, static function () use ($readService, $untrustedHashInput): void {
    $readService->convertToDraft($untrustedHashInput, 'USER:42');
});
$dismissExtraStore = new ExternalReviewStore();
$dismissExtraService = new DrrmExternalAdvisoryReviewService($dismissExtraStore);
assertExternalReviewThrows('DismissBrowserSnapshotRejected', DrrmEarlyWarningValidationException::class, static function () use ($dismissExtraService): void {
    $dismissExtraService->dismiss([
        'external_advisory_id' => ExternalReviewStore::ADVISORY_ID,
        'expected_payload_version' => 1,
        'reviewed_snapshot' => ['title' => 'browser replacement'],
    ], 'USER:42');
});
assertExternalReview('DismissBrowserSnapshotNeverInvokesRpc', count($dismissExtraStore->rpcCalls), 0);

$migration = file_get_contents(
    __DIR__ . '/../supabase/migrations/20260916000200_module4_phase4b2_external_advisory_review.sql'
);
$reviewEndpoint = file_get_contents(__DIR__ . '/../api/drrm/external-advisory-review.php');
$readEndpoint = file_get_contents(__DIR__ . '/../api/drrm/external-advisories.php');
$citizenEndpoint = file_get_contents(__DIR__ . '/../api/citizen/drrm/active-warnings.php');
if (!is_string($migration) || !is_string($reviewEndpoint)
    || !is_string($readEndpoint) || !is_string($citizenEndpoint)) {
    throw new RuntimeException('Unable to inspect the Phase 4B.2 contracts.');
}
$conversionSql = substr(
    $migration,
    strpos($migration, 'create function public.convert_module4_external_advisory_to_draft')
);
assertExternalReview(
    'MigrationLocksSourceBeforeAdvisory',
    strpos($conversionSql, 'from public.early_warning_sources as source')
        < strpos($conversionSql, 'select advisory.*'),
    true
);
assertExternalReview(
    'MigrationReusesPhase4ACreateRpc',
    str_contains($migration, 'public.create_module4_warning_draft('),
    true
);
assertExternalReview(
    'MigrationHasImmutableReviewAudit',
    str_contains($migration, 'external_advisory_review_history_one_terminal_event'),
    true
);
assertExternalReview(
    'MigrationSnapshotObjectAndByteBound',
    str_contains($migration, "pg_catalog.jsonb_typeof(reviewed_snapshot) = 'object'")
        && str_contains($migration, 'pg_catalog.octet_length(reviewed_snapshot::text) <= 98304')
        && str_contains($migration, 'and reviewed_snapshot ?& array[')
        && str_contains($migration, "and reviewed_snapshot - array[")
        && str_contains($migration, "reviewed_snapshot->>'payload_version' is not distinct from payload_version::text")
        && str_contains($migration, "reviewed_snapshot->>'payload_hash' is not distinct from payload_hash"),
    true
);
assertExternalReview(
    'MigrationBuildsBothSnapshotsFromLockedRows',
    substr_count($migration, 'v_reviewed_snapshot := pg_catalog.jsonb_build_object(') === 2
        && substr_count($migration, "'source_name', v_source.source_name") === 2
        && substr_count($migration, "'summary', v_current.summary") === 2
        && substr_count($migration, "'payload_hash', v_current.payload_hash") === 2,
    true
);
assertExternalReview(
    'MigrationValidatesPersistedDraftAndHistory',
    str_contains($conversionSql, "v_created->>'outcome' is distinct from 'CREATED'")
        && str_contains($conversionSql, "v_created->>'status' is distinct from 'DRAFT'")
        && str_contains($conversionSql, "v_created->>'revision' is distinct from '1'")
        && str_contains($conversionSql, "coalesce(v_created->>'id', '') !~")
        && str_contains($conversionSql, 'from public.early_warnings as warning')
        && str_contains($conversionSql, 'from public.early_warning_history as history'),
    true
);
assertExternalReview(
    'MigrationVerifierChecksTypesAndUniqueDefinitions',
    str_contains($migration, 'attribute.atttypid = expected.type_oid')
        && str_contains($migration, 'attribute.attnotnull = expected.is_not_null')
        && str_contains($migration, 'review_linked_warning_unique_index_valid')
        && str_contains($migration, 'review_one_terminal_event_unique_valid'),
    true
);
$verifierSql = substr(
    $migration,
    strpos($migration, 'create function public.verify_module4_external_advisory_review_schema()')
);
assertExternalReview('VerifierReadsActualConstraintExpression',
    str_contains($verifierSql, 'pg_catalog.pg_get_expr(actual.conbin, actual.conrelid)'), true);
assertExternalReview('VerifierUsesFullDefinitionComparison',
    str_contains($verifierSql, 'review_definition_checks as (')
        && str_contains($verifierSql, 'review_state_constraint_valid')
        && str_contains($verifierSql, 'review_snapshot_constraint_valid')
        && !str_contains($verifierSql, 'pg_catalog.pg_get_constraintdef(constraint_metadata.oid) like'),
    true);
assertExternalReview('VerifierOutputsDependOnDefinitionChecks',
    preg_match('/review_state_constraint_valid.{0,200}from review_definition_checks/s', $verifierSql) === 1
        && preg_match('/review_snapshot_constraint_valid.{0,200}from review_definition_checks/s', $verifierSql) === 1,
    true);
$stateDefinition = canonicalReviewCheck(verifierExpectedCheck($verifierSql, 'review_state'));
$stateFields = [
    'linked_warning_id', 'reviewed_at', 'reviewed_by_actor_reference',
    'reviewed_payload_version', 'reviewed_payload_hash',
];
$stateBranches = [];
foreach ([
    'pending_review' => [false, false, false, false, false],
    'dismissed' => [false, true, true, true, true],
    'draft_created' => [true, true, true, true, true],
] as $status => $notNull) {
    $branch = 'review_status=' . chr(39) . $status . chr(39);
    foreach ($stateFields as $index => $field) {
        $branch .= 'and' . $field . ($notNull[$index] ? 'isnotnull' : 'isnull');
    }
    $stateBranches[] = $branch;
}
assertExternalReview('VerifierChecksThreeExactReviewStates',
    $stateDefinition, implode('or', $stateBranches));
$stateStart = 'add constraint external_advisories_review_state_check check (';
$stateOffset = strpos($migration, $stateStart);
$stateEnd = $stateOffset === false ? false
    : strpos($migration, 'create unique index uq_external_advisories_linked_warning', $stateOffset);
if ($stateOffset === false || $stateEnd === false) {
    throw new RuntimeException('Migration review-state CHECK is missing.');
}
$stateSource = substr($migration, $stateOffset + strlen($stateStart),
    $stateEnd - $stateOffset - strlen($stateStart));
assertExternalReview('VerifierStateMatchesCreatedConstraint',
    $stateDefinition, canonicalReviewCheck(substr($stateSource, 0, strrpos($stateSource, ';'))));
$snapshotDefinition = canonicalReviewCheck(verifierExpectedCheck($verifierSql, 'review_snapshot'));
$snapshotKeys = [
    'source_code', 'source_name', 'external_reference_id', 'source_reference',
    'title', 'advisory_type', 'hazard_type', 'summary', 'issued_at',
    'valid_until', 'payload_version', 'payload_hash',
];
$keyList = implode(',', array_map(
    static fn (string $key): string => chr(39) . $key . chr(39), $snapshotKeys
));
$requiredSnapshot = 'jsonb_typeofreviewed_snapshot=' . chr(39) . 'object' . chr(39)
    . 'andoctet_lengthreviewed_snapshot<=98304'
    . 'andreviewed_snapshot?&array[' . $keyList . ']'
    . 'andreviewed_snapshot-array[' . $keyList . ']=' . chr(39) . '{}' . chr(39)
    . 'andreviewed_snapshot->>' . chr(39) . 'payload_version' . chr(39)
    . 'isnotdistinctfrompayload_version'
    . 'andreviewed_snapshot->>' . chr(39) . 'payload_hash' . chr(39)
    . 'isnotdistinctfrompayload_hash';
assertExternalReview('VerifierChecksExactSnapshotRules',
    $snapshotDefinition, $requiredSnapshot);
$snapshotStart = 'constraint external_advisory_review_history_snapshot_check check (';
$snapshotOffset = strpos($migration, $snapshotStart);
$snapshotEnd = $snapshotOffset === false ? false
    : strpos($migration, 'constraint external_advisory_review_history_details_check', $snapshotOffset);
if ($snapshotOffset === false || $snapshotEnd === false) {
    throw new RuntimeException('Migration reviewed-snapshot CHECK is missing.');
}
$snapshotSource = substr($migration, $snapshotOffset + strlen($snapshotStart),
    $snapshotEnd - $snapshotOffset - strlen($snapshotStart));
assertExternalReview('VerifierSnapshotMatchesCreatedConstraint',
    $snapshotDefinition, canonicalReviewCheck(substr($snapshotSource, 0, strrpos($snapshotSource, ','))));
assertExternalReview(
    'MigrationServiceRoleOnlyRpcGrants',
    substr_count($migration, 'from public, anon, authenticated, service_role;') >= 3
        && substr_count($migration, 'to service_role;') >= 4,
    true
);
assertExternalReview(
    'MigrationDoesNotActivateWarning',
    str_contains($migration, 'change_module4_warning_status'),
    false
);
assertExternalReview(
    'MutationEndpointRequiresCsrf',
    str_contains($reviewEndpoint, 'requireValidHeader($_SERVER)'),
    true
);
assertExternalReview(
    'MutationEndpointUsesTrustedSessionActor',
    str_contains($reviewEndpoint, 'actorReferenceFromSession()'),
    true
);
assertExternalReview(
    'ReadEndpointRequiresView',
    str_contains($readEndpoint, '->canView()'),
    true
);
assertExternalReview(
    'CitizenEndpointHasNoStagingProjection',
    str_contains($citizenEndpoint, 'external_advisor'),
    false
);

echo 'Module4ExternalAdvisoryReview=PASS' . PHP_EOL;
