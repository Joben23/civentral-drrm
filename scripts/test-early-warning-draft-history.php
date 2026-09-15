<?php

declare(strict_types=1);

use App\Services\DrrmCitizenWarningReadService;
use App\Services\DrrmDataStoreInterface;
use App\Services\DrrmEarlyWarningConflictException;
use App\Services\DrrmEarlyWarningLifecycleException;
use App\Services\DrrmEarlyWarningReadService;
use App\Services\DrrmEarlyWarningValidationException;
use App\Services\DrrmEarlyWarningWriteException;
use App\Services\DrrmEarlyWarningWriteService;
use App\Services\SupabaseRestException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/DrrmEarlyWarningWriteService.php';
require_once __DIR__ . '/../src/Services/DrrmEarlyWarningReadService.php';
require_once __DIR__ . '/../src/Services/DrrmCitizenWarningReadService.php';

final class DraftHistoryTestStore implements DrrmDataStoreInterface
{
    private const SOURCE_ID = '10000000-0000-4000-8000-000000000004';

    /** @var array<string, array<string, mixed>> */
    public array $warnings = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $areas = [];

    /** @var list<array<string, mixed>> */
    public array $history = [];

    /** @var list<array{function: string, payload: array<string, mixed>}> */
    public array $rpcCalls = [];

    public ?Throwable $nextRpcFailure = null;

    private int $warningSequence = 1;
    private int $historySequence = 1;

    public function get(string $resource, array $query = []): array
    {
        if ($resource === 'early_warning_sources') {
            return [[
                'id' => self::SOURCE_ID,
                'source_code' => 'CIVENTRAL',
                'source_name' => 'CIVENTRAL DRRM',
                'source_type' => 'INTERNAL_SYSTEM',
                'integration_status' => 'PENDING',
                'is_active' => true,
            ]];
        }
        if ($resource === 'risk_levels') {
            $levels = [
                ['risk_level_id' => 1, 'code' => 'LOW', 'name' => 'Low', 'severity_rank' => 1, 'is_active' => true],
                ['risk_level_id' => 2, 'code' => 'MODERATE', 'name' => 'Moderate', 'severity_rank' => 2, 'is_active' => true],
                ['risk_level_id' => 3, 'code' => 'HIGH', 'name' => 'High', 'severity_rank' => 3, 'is_active' => true],
                ['risk_level_id' => 4, 'code' => 'CRITICAL', 'name' => 'Critical', 'severity_rank' => 4, 'is_active' => true],
            ];
            if (is_string($query['code'] ?? null) && str_starts_with($query['code'], 'eq.')) {
                $code = substr($query['code'], 3);
                return array_values(array_filter($levels, static fn (array $level): bool => $level['code'] === $code));
            }
            if (is_string($query['risk_level_id'] ?? null) && str_starts_with($query['risk_level_id'], 'eq.')) {
                $id = (int) substr($query['risk_level_id'], 3);
                return array_values(array_filter($levels, static fn (array $level): bool => $level['risk_level_id'] === $id));
            }
            return $levels;
        }
        if ($resource === 'dataset_versions') {
            return [];
        }
        if ($resource === 'barangays') {
            return $this->legacyBarangays();
        }
        if ($resource === 'early_warnings') {
            $rows = array_values($this->warnings);
            if (is_string($query['id'] ?? null) && str_starts_with($query['id'], 'eq.')) {
                $id = substr($query['id'], 3);
                return isset($this->warnings[$id]) ? [$this->warnings[$id]] : [];
            }
            if (($query['status'] ?? null) === 'eq.ACTIVE') {
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $warning): bool => $warning['status'] === 'ACTIVE'
                ));
            }
            return $rows;
        }
        if ($resource === 'early_warning_areas') {
            $ids = $this->filterIds((string) ($query['warning_id'] ?? ''));
            $rows = [];
            foreach ($this->areas as $warningId => $areas) {
                if ($ids === [] || in_array($warningId, $ids, true)) {
                    array_push($rows, ...$areas);
                }
            }
            return $rows;
        }
        if ($resource === 'early_warning_history') {
            $ids = $this->filterIds((string) ($query['warning_id'] ?? ''));
            return array_values(array_filter(
                array_reverse($this->history),
                static fn (array $event): bool => $ids === [] || in_array($event['warning_id'], $ids, true)
            ));
        }
        throw new RuntimeException('Unexpected test resource: ' . $resource);
    }

    public function post(string $resource, array $payload, array $query = []): array
    {
        throw new RuntimeException('Direct REST writes are forbidden in this isolated test.');
    }

    public function rpc(string $function, array $payload = []): array
    {
        $this->rpcCalls[] = ['function' => $function, 'payload' => $payload];
        if ($this->nextRpcFailure !== null) {
            $failure = $this->nextRpcFailure;
            $this->nextRpcFailure = null;
            throw $failure;
        }
        return match ($function) {
            'create_module4_warning_draft' => $this->create($payload),
            'update_module4_warning_draft' => $this->update($payload),
            'change_module4_warning_status' => $this->changeStatus($payload),
            default => throw new RuntimeException('Unexpected test RPC: ' . $function),
        };
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function create(array $payload): array
    {
        $id = sprintf('50000000-0000-4000-8000-%012d', $this->warningSequence++);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
        $warning = [
            'id' => $id,
            'source_id' => $payload['p_source_id'],
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
        $this->warnings[$id] = $warning;
        $this->areas[$id] = $this->areasWithWarningId($id, $payload['p_areas']);
        $this->appendHistory($warning, 'CREATED', (string) $payload['p_actor_reference']);
        return [
            'outcome' => 'CREATED', 'id' => $id, 'title' => $warning['title'],
            'status' => 'DRAFT', 'revision' => 1, 'updated_at' => $now,
            'affected_area_count' => count($this->areas[$id]),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function update(array $payload): array
    {
        $id = (string) $payload['p_warning_id'];
        if (!isset($this->warnings[$id])) {
            return ['outcome' => 'NOT_FOUND'];
        }
        $current = $this->warnings[$id];
        if ($current['status'] !== 'DRAFT') {
            return ['outcome' => 'NOT_DRAFT', 'status' => $current['status'], 'revision' => $current['revision']];
        }
        if ($current['revision'] !== $payload['p_expected_revision']) {
            return ['outcome' => 'REVISION_CONFLICT', 'status' => $current['status'], 'revision' => $current['revision']];
        }
        $revision = (int) $current['revision'] + 1;
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
        foreach ([
            'source_id', 'title', 'hazard_type', 'warning_level_id', 'summary',
            'issued_at', 'valid_until', 'source_reference',
        ] as $field) {
            $current[$field] = $payload['p_' . $field];
        }
        $current['revision'] = $revision;
        $current['updated_at'] = $now;
        $this->warnings[$id] = $current;
        $this->areas[$id] = $this->areasWithWarningId($id, $payload['p_areas']);
        $this->appendHistory($current, 'DRAFT_UPDATED', (string) $payload['p_actor_reference']);
        return [
            'outcome' => 'UPDATED', 'id' => $id, 'title' => $current['title'],
            'status' => 'DRAFT', 'revision' => $revision, 'updated_at' => $now,
            'affected_area_count' => count($this->areas[$id]),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function changeStatus(array $payload): array
    {
        $id = (string) $payload['p_warning_id'];
        if (!isset($this->warnings[$id])) {
            return ['outcome' => 'NOT_FOUND'];
        }
        $warning = $this->warnings[$id];
        if ($warning['revision'] !== $payload['p_expected_revision']) {
            return ['outcome' => 'REVISION_CONFLICT', 'status' => $warning['status'], 'revision' => $warning['revision']];
        }
        $action = $payload['p_action'];
        if (($action === 'ACTIVATE' && $warning['status'] !== 'DRAFT')
            || ($action === 'CANCEL' && !in_array($warning['status'], ['DRAFT', 'ACTIVE'], true))) {
            return ['outcome' => 'INVALID_STATUS', 'status' => $warning['status'], 'revision' => $warning['revision']];
        }
        $previousStatus = $warning['status'];
        $warning['status'] = $action === 'ACTIVATE' ? 'ACTIVE' : 'CANCELLED';
        $warning['revision']++;
        $warning['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
        $this->warnings[$id] = $warning;
        $this->appendHistory(
            $warning,
            $action === 'ACTIVATE' ? 'ACTIVATED' : 'CANCELLED',
            (string) $payload['p_actor_reference']
        );
        return [
            'outcome' => 'CHANGED', 'id' => $id, 'title' => $warning['title'],
            'previous_status' => $previousStatus, 'status' => $warning['status'],
            'revision' => $warning['revision'], 'updated_at' => $warning['updated_at'],
        ];
    }

    /** @param array<string, mixed> $warning */
    private function appendHistory(array $warning, string $eventType, string $actor): void
    {
        $this->history[] = [
            'id' => sprintf('60000000-0000-4000-8000-%012d', $this->historySequence++),
            'warning_id' => $warning['id'],
            'event_type' => $eventType,
            'actor_reference' => $actor,
            'occurred_at' => $warning['updated_at'],
            'resulting_status' => $warning['status'],
            'resulting_revision' => $warning['revision'],
            'details' => [],
        ];
    }

    /** @param list<array<string, mixed>> $areas @return list<array<string, mixed>> */
    private function areasWithWarningId(string $warningId, array $areas): array
    {
        return array_map(static fn (array $area): array => [
            'warning_id' => $warningId,
            'scope_type' => $area['scope_type'],
            'barangay_id' => $area['barangay_id'],
            'area_name' => $area['area_name'],
            'created_at' => '2026-09-15T00:00:00+00:00',
            'id' => '70000000-0000-4000-8000-' . substr(md5($warningId . json_encode($area)), 0, 12),
        ], $areas);
    }

    /** @return list<string> */
    private function filterIds(string $filter): array
    {
        if (str_starts_with($filter, 'eq.')) {
            return [substr($filter, 3)];
        }
        if (preg_match('/^in\.\(([^)]+)\)$/', $filter, $matches) === 1) {
            return explode(',', $matches[1]);
        }
        return [];
    }

    /** @return list<array<string, mixed>> */
    private function legacyBarangays(): array
    {
        $rows = [];
        $sequence = 1;
        for ($number = 1; $number <= 188; $number++) {
            if ($number === 176) {
                continue;
            }
            $rows[] = [
                'barangay_id' => sprintf('30000000-0000-4000-8000-%012d', $sequence++),
                'barangay_code' => sprintf('13801%05d', $number),
                'name' => 'Barangay ' . $number,
                'boundary_dataset_version_id' => DrrmEarlyWarningWriteService::BARANGAY_DATASET_VERSION_ID,
                'record_status' => 'INACTIVE',
            ];
        }
        return $rows;
    }
}

/** @param mixed $actual @param mixed $expected */
function assertDraftHistory(string $name, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($name . ' failed. Expected ' . json_encode($expected)
            . ', received ' . json_encode($actual) . '.');
    }
    echo $name . '=PASS' . PHP_EOL;
}

/** @param class-string<Throwable> $class @param callable(): mixed $operation */
function assertDraftHistoryThrows(string $name, string $class, callable $operation): void
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

$store = new DraftHistoryTestStore();
$service = new DrrmEarlyWarningWriteService($store);
$actor = DrrmEarlyWarningWriteService::actorReferenceFromSession([
    'user_id' => 'officer-42',
    'employee_id' => 'ignored-browser-value',
]);
assertDraftHistory('TrustedUserActorResolved', $actor, 'USER:officer-42');
assertDraftHistory(
    'TrustedEmployeeFallbackResolved',
    DrrmEarlyWarningWriteService::actorReferenceFromSession(['employee_id' => 'EMP-9']),
    'EMPLOYEE:EMP-9'
);

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$base = [
    'title' => 'Isolated flood draft',
    'hazard_type' => 'FLOOD',
    'warning_level' => 'LOW',
    'source_code' => 'CIVENTRAL',
    'summary' => 'Database-free Phase 4A.2 warning.',
    'issued_at' => $now->modify('-5 minutes')->format('Y-m-d\TH:i:sP'),
    'valid_until' => $now->modify('+2 hours')->format('Y-m-d\TH:i:sP'),
    'source_reference' => null,
    'scope_type' => 'CITY',
    'barangay_ids' => [],
];

assertDraftHistoryThrows(
    'BrowserActorFieldRejected',
    DrrmEarlyWarningValidationException::class,
    fn () => $service->createDraft($base + ['actor_reference' => 'USER:attacker'], $actor)
);

$created = $service->createDraft($base, $actor);
$warningId = $created['id'];
assertDraftHistory('CreateDraftStatus', $created['status'], 'DRAFT');
assertDraftHistory('CreateInitialRevision', $created['revision'], 1);
assertDraftHistory('CreateHistoryEvent', $store->history[0]['event_type'], 'CREATED');
assertDraftHistory('CreateHistoryTrustedActor', $store->history[0]['actor_reference'], $actor);
assertDraftHistory('CreateUsesTransactionalRpc', $store->rpcCalls[0]['function'], 'create_module4_warning_draft');

$editedInput = array_replace($base, [
    'warning_id' => $warningId,
    'expected_revision' => 1,
    'title' => 'Edited isolated flood draft',
    'warning_level' => 'HIGH',
]);
$edited = $service->updateDraft($editedInput, $actor);
assertDraftHistory('ValidDraftEditStatus', $edited['status'], 'DRAFT');
assertDraftHistory('DraftEditChangedField', $store->warnings[$warningId]['title'], 'Edited isolated flood draft');
assertDraftHistory('DraftEditIncrementsRevision', $edited['revision'], 2);
assertDraftHistory('DraftUpdatedHistoryEvent', $store->history[1]['event_type'], 'DRAFT_UPDATED');

assertDraftHistoryThrows(
    'StaleRevisionRejected',
    DrrmEarlyWarningConflictException::class,
    fn () => $service->updateDraft($editedInput, $actor)
);
assertDraftHistoryThrows(
    'ReversedTimestampsRejected',
    DrrmEarlyWarningValidationException::class,
    fn () => $service->updateDraft(array_replace($editedInput, [
        'expected_revision' => 2,
        'issued_at' => $now->modify('+2 hours')->format('Y-m-d\TH:i:sP'),
        'valid_until' => $now->modify('+1 hour')->format('Y-m-d\TH:i:sP'),
    ]), $actor)
);
assertDraftHistoryThrows(
    'UnknownAuthoritativeBarangayRejected',
    DrrmEarlyWarningValidationException::class,
    fn () => $service->updateDraft(array_replace($editedInput, [
        'expected_revision' => 2,
        'scope_type' => 'BARANGAY',
        'barangay_ids' => ['40000000-0000-4000-8000-000000000001'],
    ]), $actor)
);

$activated = $service->activate($warningId, 2, $actor);
assertDraftHistory('ActivateStatus', $activated['status'], 'ACTIVE');
assertDraftHistory('ActivateIncrementsRevision', $activated['revision'], 3);
assertDraftHistory('ActivatedHistoryEvent', $store->history[2]['event_type'], 'ACTIVATED');
assertDraftHistoryThrows(
    'EditActiveRejected',
    DrrmEarlyWarningLifecycleException::class,
    fn () => $service->updateDraft(array_replace($editedInput, ['expected_revision' => 3]), $actor)
);

$cancelledActive = $service->cancel($warningId, 3, $actor);
assertDraftHistory('CancelActiveStatus', $cancelledActive['status'], 'CANCELLED');
assertDraftHistory('CancelledActiveHistoryEvent', $store->history[3]['event_type'], 'CANCELLED');
assertDraftHistoryThrows(
    'EditCancelledRejected',
    DrrmEarlyWarningLifecycleException::class,
    fn () => $service->updateDraft(array_replace($editedInput, ['expected_revision' => 4]), $actor)
);

$draftToCancel = $service->createDraft(array_replace($base, ['title' => 'Draft cancelled directly']), $actor);
$cancelledDraft = $service->cancel($draftToCancel['id'], 1, $actor);
assertDraftHistory('CancelDraftStatus', $cancelledDraft['status'], 'CANCELLED');
assertDraftHistory('CancelledDraftHistoryEvent', end($store->history)['event_type'], 'CANCELLED');

$history = (new DrrmEarlyWarningReadService($store))->warningHistory($warningId);
assertDraftHistory('AdministrativeHistoryCount', count($history), 4);
assertDraftHistory('AdministrativeHistoryNewestFirst', $history[0]['event_type'], 'CANCELLED');
assertDraftHistory('AdministrativeHistoryResultingRevision', $history[0]['resulting_revision'], 4);

$publicDraft = $service->createDraft(array_replace($base, ['title' => 'Public active warning']), $actor);
$service->activate($publicDraft['id'], 1, $actor);
$publicResult = (new DrrmCitizenWarningReadService($store))->activeWarnings($now);
$publicJson = json_encode($publicResult, JSON_THROW_ON_ERROR);
assertDraftHistory('CitizenProjectionIncludesEligibleActiveWarning', count($publicResult['warnings']), 1);
foreach (['actor_reference', 'event_type', 'history', 'revision', 'resulting_revision'] as $forbidden) {
    assertDraftHistory('CitizenExcludes_' . $forbidden, str_contains($publicJson, '"' . $forbidden . '"'), false);
}

$authoritativeBarangay = $service->availableBarangays()[0];
assertDraftHistoryThrows(
    'DuplicateBarangaySelectionRejected',
    DrrmEarlyWarningValidationException::class,
    fn () => $service->createDraft(array_replace($base, [
        'title' => 'Duplicate barangay validation',
        'scope_type' => 'BARANGAY',
        'barangay_ids' => [$authoritativeBarangay['barangay_id'], $authoritativeBarangay['barangay_id']],
    ]), $actor)
);
$barangayDraft = $service->createDraft(array_replace($base, [
    'title' => 'Authoritative barangay name validation',
    'scope_type' => 'BARANGAY',
    'barangay_ids' => [$authoritativeBarangay['barangay_id']],
]), $actor);
assertDraftHistory(
    'ApplicationUsesAuthoritativeBarangayName',
    $store->areas[$barangayDraft['id']][0]['area_name'],
    $authoritativeBarangay['name']
);

foreach (['22023', '23514'] as $validationSqlState) {
    $store->nextRpcFailure = new SupabaseRestException(
        'SIMULATED RAW DATABASE DETAIL: public.early_warning_history',
        400,
        $validationSqlState
    );
    try {
        $service->createDraft(array_replace($base, ['title' => 'Validation mapping ' . $validationSqlState]), $actor);
        throw new RuntimeException('SqlState_' . $validationSqlState . '_was_not_rejected.');
    } catch (DrrmEarlyWarningValidationException $exception) {
        assertDraftHistory('SqlState_' . $validationSqlState . '_MapsToValidation', $exception->getMessage(), 'The warning failed server-side validation.');
        assertDraftHistory('SqlState_' . $validationSqlState . '_HidesRawDetails', str_contains($exception->getMessage(), 'early_warning_history'), false);
    }
}

$store->nextRpcFailure = new SupabaseRestException(
    'SIMULATED RAW UNIQUE DETAIL: uq_early_warning_areas_warning_barangay',
    409,
    '23505'
);
try {
    $service->createDraft(array_replace($base, ['title' => 'Duplicate mapping']), $actor);
    throw new RuntimeException('DuplicateSqlState_was_not_rejected.');
} catch (DrrmEarlyWarningValidationException $exception) {
    assertDraftHistory(
        'DuplicateSqlStateMapsToSafeValidation',
        $exception->getMessage(),
        'The warning contains duplicate or conflicting constrained values.'
    );
    assertDraftHistory('DuplicateSqlStateHidesConstraintName', str_contains($exception->getMessage(), 'uq_early_warning'), false);
}

$store->nextRpcFailure = new SupabaseRestException('SIMULATED INFRASTRUCTURE FAILURE', 503, '08006');
assertDraftHistoryThrows(
    'UnexpectedInfrastructureFailureRemainsServerError',
    DrrmEarlyWarningWriteException::class,
    fn () => $service->createDraft(array_replace($base, ['title' => 'Infrastructure mapping']), $actor)
);

$updateEndpoint = file_get_contents(__DIR__ . '/../api/drrm/early-warning-update.php');
assertDraftHistory(
    'UpdateEndpointMapsConflictTo409',
    is_string($updateEndpoint)
        && str_contains($updateEndpoint, 'DrrmEarlyWarningConflictException')
        && str_contains($updateEndpoint, '409'),
    true
);
foreach (['early-warning-create.php', 'early-warning-update.php', 'early-warning-status.php'] as $endpointName) {
    $endpointSource = file_get_contents(__DIR__ . '/../api/drrm/' . $endpointName);
    assertDraftHistory(
        'ValidationMapsTo422_' . $endpointName,
        is_string($endpointSource)
            && str_contains($endpointSource, 'DrrmEarlyWarningValidationException')
            && str_contains($endpointSource, '422'),
        true
    );
}

$migration = file_get_contents(__DIR__ . '/../supabase/migrations/20260915000100_module4_phase4a2_draft_history.sql');
foreach ([
    'early_warning_history',
    'revision integer not null default 1',
    'create_module4_warning_draft',
    'update_module4_warning_draft',
    'change_module4_warning_status',
    'for update',
    'to service_role',
    'resulting_revision integer not null',
    'on delete restrict',
    'uq_early_warning_areas_warning_barangay',
    'left join public.barangays as barangay',
    'then barangay.name',
    "count(distinct area->>'barangay_id')",
    'early_warning_history_event_status_pair_check',
    "event_type = 'ACTIVATED' and resulting_status = 'ACTIVE'",
    "event_type = 'CANCELLED' and resulting_status = 'CANCELLED'",
    'on public.early_warning_history (warning_id, resulting_revision)',
    'revoke insert, update, delete on table public.early_warnings from service_role',
    'revoke insert, update, delete on table public.early_warning_areas from service_role',
    'grant select on table public.early_warnings to service_role',
    'grant select on table public.early_warning_areas to service_role',
] as $requiredMigrationFragment) {
    assertDraftHistory(
        'MigrationContains_' . preg_replace('/[^A-Za-z0-9]+/', '_', $requiredMigrationFragment),
        is_string($migration) && str_contains(strtolower($migration), strtolower($requiredMigrationFragment)),
        true
    );
}

assertDraftHistory(
    'AllSecurityDefinerFunctionsUseSafeSearchPath',
    is_string($migration) && substr_count(strtolower($migration), 'set search_path = pg_catalog, public') === 5,
    true
);
assertDraftHistory(
    'NoLegacyUnsafeSearchPath',
    is_string($migration) && str_contains(strtolower($migration), 'set search_path = public, pg_temp'),
    false
);

$applicationFiles = array_merge(
    glob(__DIR__ . '/../api/drrm/*.php') ?: [],
    glob(__DIR__ . '/../src/Services/*.php') ?: [],
    glob(__DIR__ . '/../pages/drrm/*.php') ?: [],
    glob(__DIR__ . '/../assets/js/drrm/*.js') ?: []
);
$directWarningDml = [];
foreach ($applicationFiles as $applicationFile) {
    $source = file_get_contents($applicationFile);
    $compactSource = is_string($source) ? preg_replace('/\s+/', '', $source) : null;
    if (!is_string($compactSource)) {
        continue;
    }
    foreach (['post', 'patch', 'delete'] as $operation) {
        foreach ([chr(39), chr(34)] as $quote) {
            foreach (['early_warnings', 'early_warning_areas'] as $resource) {
                if (str_contains($compactSource, $operation . '(' . $quote . $resource . $quote)) {
                    $directWarningDml[] = $applicationFile;
                    continue 4;
                }
            }
        }
    }
}
assertDraftHistory('NoDirectWarningDmlInApplicationCode', $directWarningDml, []);

echo 'DatabaseAccess=NONE' . PHP_EOL;
echo 'DatabaseWrites=NONE' . PHP_EOL;
echo 'Module 4 Phase 4A.2 draft/history: OK' . PHP_EOL;
