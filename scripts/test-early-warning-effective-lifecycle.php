<?php

declare(strict_types=1);

use App\Services\DrrmCitizenWarningReadService;
use App\Services\DrrmDataStoreInterface;
use App\Services\DrrmEarlyWarningLifecyclePolicy;
use App\Services\DrrmEarlyWarningReadService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/DrrmEarlyWarningLifecyclePolicy.php';
require_once __DIR__ . '/../src/Services/DrrmEarlyWarningReadService.php';
require_once __DIR__ . '/../src/Services/DrrmCitizenWarningReadService.php';

/**
 * In-memory read store. It deliberately returns every stored-ACTIVE warning
 * even when timestamp filters are supplied, verifying both services enforce
 * the shared PHP lifecycle policy as a defense-in-depth boundary.
 */
final class EffectiveLifecycleTestStore implements DrrmDataStoreInterface
{
    /** @var list<array<string, mixed>> */
    public array $earlyWarningQueries = [];

    /** @param list<array<string, mixed>> $warnings */
    public function __construct(private readonly array $warnings)
    {
    }

    public function get(string $resource, array $query = []): array
    {
        if ($resource === 'early_warnings') {
            $this->earlyWarningQueries[] = $query;
            if (($query['status'] ?? null) === 'eq.ACTIVE') {
                return array_values(array_filter(
                    $this->warnings,
                    static fn (array $warning): bool => ($warning['status'] ?? null) === 'ACTIVE'
                ));
            }
            return $this->warnings;
        }

        if ($resource === 'early_warning_sources') {
            return [[
                'id' => '10000000-0000-4000-8000-000000000001',
                'source_code' => 'PAGASA',
                'source_name' => 'PAGASA',
                'source_type' => 'GOVERNMENT',
                'integration_status' => 'PENDING',
                'is_active' => true,
            ]];
        }

        if ($resource === 'risk_levels') {
            return [
                ['risk_level_id' => 1, 'code' => 'LOW', 'name' => 'Low', 'severity_rank' => 1, 'is_active' => true],
                ['risk_level_id' => 2, 'code' => 'MODERATE', 'name' => 'Moderate', 'severity_rank' => 2, 'is_active' => true],
                ['risk_level_id' => 3, 'code' => 'HIGH', 'name' => 'High', 'severity_rank' => 3, 'is_active' => true],
                ['risk_level_id' => 4, 'code' => 'CRITICAL', 'name' => 'Critical', 'severity_rank' => 4, 'is_active' => true],
            ];
        }

        if ($resource === 'early_warning_areas') {
            $selectedIds = [];
            $warningIdFilter = (string) ($query['warning_id'] ?? '');
            if (preg_match('/^in\.\(([^)]+)\)$/', $warningIdFilter, $matches) === 1) {
                $selectedIds = explode(',', $matches[1]);
            }

            $areas = [];
            foreach ($this->warnings as $warning) {
                if ($selectedIds !== [] && !in_array($warning['id'], $selectedIds, true)) {
                    continue;
                }
                $areas[] = [
                    'warning_id' => $warning['id'],
                    'scope_type' => 'CITY',
                    'barangay_id' => null,
                    'area_name' => DrrmCitizenWarningReadService::CITY_NAME,
                    'created_at' => '2026-09-15T00:00:00+00:00',
                ];
            }
            return $areas;
        }

        throw new RuntimeException('Unexpected in-memory resource: ' . $resource);
    }

    public function post(string $resource, array $payload, array $query = []): array
    {
        throw new RuntimeException('The effective-lifecycle test is read-only.');
    }

    public function rpc(string $function, array $payload = []): array
    {
        throw new RuntimeException('The effective-lifecycle test is read-only.');
    }
}

/** @param mixed $actual @param mixed $expected */
function assertEffectiveLifecycle(string $name, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($name . ' failed. Expected ' . json_encode($expected)
            . ', received ' . json_encode($actual) . '.');
    }
    echo $name . '=PASS' . PHP_EOL;
}

/** @return array<string, mixed> */
function lifecycleWarning(
    string $idSuffix,
    string $status,
    string $issuedAt,
    ?string $validUntil
): array {
    return [
        'id' => '20000000-0000-4000-8000-' . str_pad($idSuffix, 12, '0', STR_PAD_LEFT),
        'source_id' => '10000000-0000-4000-8000-000000000001',
        'title' => 'Lifecycle case ' . $idSuffix,
        'hazard_type' => 'HEAVY_RAINFALL',
        'warning_level_id' => 3,
        'summary' => 'Isolated lifecycle test warning.',
        'status' => $status,
        'issued_at' => $issuedAt,
        'valid_until' => $validUntil,
        'source_reference' => 'https://www.pagasa.dost.gov.ph/',
        'updated_at' => '2026-09-15T00:00:00+00:00',
    ];
}

$asOf = new DateTimeImmutable('2026-09-15T04:00:00+00:00');
$warnings = [
    lifecycleWarning('1', 'ACTIVE', '2026-09-15T03:00:00+00:00', '2026-09-15T05:00:00+00:00'),
    lifecycleWarning('2', 'ACTIVE', '2026-09-15T03:00:00+00:00', null),
    lifecycleWarning('3', 'ACTIVE', '2026-09-15T02:00:00+00:00', '2026-09-15T04:00:00+00:00'),
    lifecycleWarning('4', 'ACTIVE', '2026-09-15T05:00:00+00:00', null),
    lifecycleWarning('5', 'CANCELLED', '2026-09-15T03:00:00+00:00', '2026-09-15T05:00:00+00:00'),
];

assertEffectiveLifecycle(
    'ActiveWithFutureValidity',
    DrrmEarlyWarningLifecyclePolicy::isEffectivelyActive($warnings[0], $asOf),
    true
);
assertEffectiveLifecycle(
    'ActiveWithoutExpiryAfterIssue',
    DrrmEarlyWarningLifecyclePolicy::isEffectivelyActive($warnings[1], $asOf),
    true
);
assertEffectiveLifecycle(
    'ExpiredActiveExcluded',
    DrrmEarlyWarningLifecyclePolicy::isEffectivelyActive($warnings[2], $asOf),
    false
);
assertEffectiveLifecycle(
    'FutureIssuedActiveExcluded',
    DrrmEarlyWarningLifecyclePolicy::isEffectivelyActive($warnings[3], $asOf),
    false
);
assertEffectiveLifecycle(
    'CancelledNeverActive',
    DrrmEarlyWarningLifecyclePolicy::isEffectivelyActive($warnings[4], $asOf),
    false
);
assertEffectiveLifecycle(
    'ExpiredEffectiveStatus',
    DrrmEarlyWarningLifecyclePolicy::effectiveStatus($warnings[2], $asOf),
    'EXPIRED'
);
assertEffectiveLifecycle('ExpiredStoredStatusPreserved', $warnings[2]['status'], 'ACTIVE');

$expiredViolation = DrrmEarlyWarningLifecyclePolicy::activationTimestampViolation(
    new DateTimeImmutable('2026-09-15T02:00:00+00:00'),
    new DateTimeImmutable('2026-09-15T03:00:00+00:00'),
    $asOf
);
assertEffectiveLifecycle(
    'ExpiredDraftActivationRejected',
    $expiredViolation,
    DrrmEarlyWarningLifecyclePolicy::ACTIVATION_ALREADY_EXPIRED
);

$futureViolation = DrrmEarlyWarningLifecyclePolicy::activationTimestampViolation(
    new DateTimeImmutable('2026-09-15T05:00:00+00:00'),
    new DateTimeImmutable('2026-09-15T06:00:00+00:00'),
    $asOf
);
assertEffectiveLifecycle(
    'FutureIssuedDraftActivationRejected',
    $futureViolation,
    DrrmEarlyWarningLifecyclePolicy::ACTIVATION_FUTURE_ISSUED_AT
);

assertEffectiveLifecycle(
    'InvalidCalendarTimestampRejected',
    DrrmEarlyWarningLifecyclePolicy::parseTimestamp('2026-02-31T12:00:00+00:00'),
    null
);
assertEffectiveLifecycle(
    'TimestampWithoutOffsetRejected',
    DrrmEarlyWarningLifecyclePolicy::parseTimestamp('2026-09-15T04:00:00'),
    null
);
assertEffectiveLifecycle(
    'EqualValidityRejected',
    DrrmEarlyWarningLifecyclePolicy::activationTimestampViolation($asOf, $asOf, $asOf),
    DrrmEarlyWarningLifecyclePolicy::ACTIVATION_VALIDITY_ORDER_INVALID
);

$store = new EffectiveLifecycleTestStore($warnings);
$admin = new DrrmEarlyWarningReadService($store, $asOf);
$citizen = new DrrmCitizenWarningReadService($store);
$adminCount = $admin->activeWarningCount();
$citizenResult = $citizen->activeWarnings($asOf);

assertEffectiveLifecycle('AdminEffectiveActiveCount', $adminCount, 2);
assertEffectiveLifecycle('CitizenEffectiveActiveCount', $citizenResult['active_warning_count'], 2);
assertEffectiveLifecycle('AdminCitizenEligibilityAgreement', $adminCount, $citizenResult['active_warning_count']);
assertEffectiveLifecycle(
    'CurrentWarningIsEffectivelyActive',
    $admin->currentActiveWarning()['is_effectively_active'] ?? null,
    true
);
assertEffectiveLifecycle('HighRiskAreaCountUsesEffectiveActive', $admin->highRiskActiveAreaCount(), 2);
assertEffectiveLifecycle('PagasaMetricUsesEffectiveActive', $admin->weatherAdvisoryCount(), 2);

$recentById = [];
foreach ($admin->recentWarnings() as $warning) {
    $recentById[$warning['id']] = $warning;
}
$expiredId = $warnings[2]['id'];
assertEffectiveLifecycle('RecentStoredStatus', $recentById[$expiredId]['stored_status'], 'ACTIVE');
assertEffectiveLifecycle('RecentEffectiveStatus', $recentById[$expiredId]['effective_status'], 'EXPIRED');

$effectiveQueries = array_values(array_filter(
    $store->earlyWarningQueries,
    static fn (array $query): bool => ($query['status'] ?? null) === 'eq.ACTIVE'
));
assertEffectiveLifecycle('BothServicesUseIssuedAtFilter', count(array_filter(
    $effectiveQueries,
    static fn (array $query): bool => str_starts_with((string) ($query['issued_at'] ?? ''), 'lte.')
)), 2);
assertEffectiveLifecycle('BothServicesUseValidityFilter', count(array_filter(
    $effectiveQueries,
    static fn (array $query): bool => str_contains((string) ($query['or'] ?? ''), 'valid_until.gt.')
)), 2);

echo 'DatabaseAccess=NONE' . PHP_EOL;
echo 'DatabaseWrites=NONE' . PHP_EOL;
echo 'DRRM early-warning effective lifecycle: OK' . PHP_EOL;
