<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\DrrmEarlyWarningLifecycleException;
use App\Services\DrrmEarlyWarningReadService;
use App\Services\DrrmEarlyWarningValidationException;
use App\Services\DrrmEarlyWarningWriteService;
use App\Services\SupabaseRestClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!in_array('--execute', $argv, true)) {
    fwrite(STDERR, "Refusing to create controlled TEST records without --execute.\n");
    exit(1);
}

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../src/Services/DrrmEarlyWarningReadService.php';
require_once __DIR__ . '/../src/Services/DrrmEarlyWarningWriteService.php';

/** @param mixed $actual @param mixed $expected */
function assertWorkflowResult(string $name, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($name . ' failed.');
    }
    echo $name . "=PASS\n";
}

/** @param callable(): mixed $operation */
function assertValidationRejected(string $name, callable $operation): void
{
    try {
        $operation();
    } catch (DrrmEarlyWarningValidationException) {
        echo $name . "=PASS\n";
        return;
    }
    throw new RuntimeException($name . ' was not rejected.');
}

/** @param list<array<string, mixed>> $records @return list<string> */
function recordIds(array $records): array
{
    $ids = array_map(static fn (array $record): string => (string) ($record['id'] ?? ''), $records);
    sort($ids);
    return $ids;
}

/**
 * @param array<string, string> $createdWarnings warning ID => exact title
 */
function cleanupTestWarnings(SupabaseRestClient $client, array $createdWarnings): void
{
    foreach (array_reverse($createdWarnings, true) as $warningId => $expectedTitle) {
        $records = $client->get('early_warnings', [
            'select' => 'id,title,status',
            'id' => 'eq.' . $warningId,
            'limit' => 2,
        ]);

        if ($records === []) {
            continue;
        }

        if (count($records) !== 1 || !is_array($records[0])
            || ($records[0]['id'] ?? null) !== $warningId
            || ($records[0]['title'] ?? null) !== $expectedTitle
            || !str_starts_with($expectedTitle, 'TEST -')) {
            throw new RuntimeException('Cleanup refused an unverified warning record.');
        }

        $client->delete('early_warning_areas', [
            'warning_id' => 'eq.' . $warningId,
            'select' => 'id',
        ]);
        $deleted = $client->delete('early_warnings', [
            'id' => 'eq.' . $warningId,
            'title' => 'eq.' . $expectedTitle,
            'select' => 'id',
        ]);

        if (count($deleted) !== 1 || ($deleted[0]['id'] ?? null) !== $warningId) {
            throw new RuntimeException('Cleanup could not verify exact test-warning deletion.');
        }
    }
}

$cleanupId = null;
$cleanupTitle = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--cleanup-id=')) {
        $cleanupId = substr($argument, strlen('--cleanup-id='));
    } elseif (str_starts_with($argument, '--cleanup-title=')) {
        $cleanupTitle = substr($argument, strlen('--cleanup-title='));
    }
}

if ($cleanupId !== null || $cleanupTitle !== null) {
    if ($cleanupId === null || $cleanupTitle === null || !str_starts_with($cleanupTitle, 'TEST -')) {
        fwrite(STDERR, "Cleanup requires an exact UUID and exact TEST - title.\n");
        exit(1);
    }

    $cleanupClient = new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../.env'));
    cleanupTestWarnings($cleanupClient, [$cleanupId => $cleanupTitle]);
    echo "ExactTestWarningCleanup=PASS\n";
    exit(0);
}

$config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
$client = new SupabaseRestClient($config);
$writeService = new DrrmEarlyWarningWriteService($client);
$createdWarnings = [];
$baselineWarnings = $client->get('early_warnings', ['select' => 'id,title,status', 'order' => 'created_at.asc']);
$baselineAreas = $client->get('early_warning_areas', ['select' => 'id,warning_id', 'order' => 'created_at.asc']);
$baselineWarningIds = recordIds($baselineWarnings);
$baselineAreaIds = recordIds($baselineAreas);
$baselineActiveCount = (new DrrmEarlyWarningReadService($client))->activeWarningCount();

try {
    $barangays = $writeService->availableBarangays();
    assertWorkflowResult('ValidatedBarangayCount', count($barangays), 187);

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $testSuffix = $now->format('YmdHis') . '-' . bin2hex(random_bytes(3));
    $basePayload = [
        'hazard_type' => 'FLOOD',
        'warning_level' => 'LOW',
        'source_code' => 'CIVENTRAL',
        'summary' => 'Controlled Module 4 workflow verification record. It must be removed after testing.',
        'issued_at' => $now->modify('-5 minutes')->format('Y-m-d\TH:i:sP'),
        'valid_until' => $now->modify('+2 hours')->format('Y-m-d\TH:i:sP'),
        'source_reference' => null,
        'scope_type' => 'CITY',
        'barangay_ids' => [],
    ];

    assertValidationRejected('InvalidHazardRejected', fn () => $writeService->createDraft(
        array_replace($basePayload, ['title' => 'TEST - invalid hazard', 'hazard_type' => 'TORNADO'])
    ));
    assertValidationRejected('InvalidLevelRejected', fn () => $writeService->createDraft(
        array_replace($basePayload, ['title' => 'TEST - invalid level', 'warning_level' => 'SEVERE'])
    ));
    assertValidationRejected('InvalidSourceRejected', fn () => $writeService->createDraft(
        array_replace($basePayload, ['title' => 'TEST - invalid source', 'source_code' => 'UNKNOWN'])
    ));
    assertValidationRejected('InvalidTimestampRejected', fn () => $writeService->createDraft(
        array_replace($basePayload, ['title' => 'TEST - invalid time', 'issued_at' => 'not-a-time'])
    ));
    assertValidationRejected('InvalidDateRejected', fn () => $writeService->createDraft(
        array_replace($basePayload, ['title' => 'TEST - invalid date', 'issued_at' => '2026-02-31T12:00:00+00:00'])
    ));
    assertValidationRejected('ReversedValidityRejected', fn () => $writeService->createDraft(
        array_replace($basePayload, [
            'title' => 'TEST - reversed validity',
            'issued_at' => $now->format('Y-m-d\TH:i:sP'),
            'valid_until' => $now->modify('-1 hour')->format('Y-m-d\TH:i:sP'),
        ])
    ));
    assertValidationRejected('ExternalReferenceRequired', fn () => $writeService->createDraft(
        array_replace($basePayload, ['title' => 'TEST - missing reference', 'source_code' => 'PAGASA'])
    ));
    assertValidationRejected('EmptyBarangayRejected', fn () => $writeService->createDraft(
        array_replace($basePayload, ['title' => 'TEST - empty barangay', 'scope_type' => 'BARANGAY'])
    ));
    assertValidationRejected('NonexistentBarangayRejected', fn () => $writeService->createDraft(
        array_replace($basePayload, [
            'title' => 'TEST - missing barangay',
            'scope_type' => 'BARANGAY',
            'barangay_ids' => ['00000000-0000-4000-8000-000000000000'],
        ])
    ));
    assertValidationRejected('ArbitraryStatusRejected', fn () => $writeService->createDraft(
        array_replace($basePayload, ['title' => 'TEST - arbitrary status', 'status' => 'ACTIVE'])
    ));

    $cityTitle = 'TEST - Module 4 CITY ' . $testSuffix;
    $cityDraft = $writeService->createDraft(array_replace($basePayload, ['title' => $cityTitle]));
    $createdWarnings[$cityDraft['id']] = $cityTitle;
    assertWorkflowResult('CityCreatedAsDraft', $cityDraft['status'], 'DRAFT');
    assertWorkflowResult('CityAreaCount', $cityDraft['affected_area_count'], 1);
    assertWorkflowResult(
        'DraftDoesNotIncreaseActiveCount',
        (new DrrmEarlyWarningReadService($client))->activeWarningCount(),
        $baselineActiveCount
    );

    $cityActivated = $writeService->activate($cityDraft['id']);
    assertWorkflowResult('CityActivated', $cityActivated['status'], 'ACTIVE');
    assertWorkflowResult(
        'ActivationIncreasesActiveCount',
        (new DrrmEarlyWarningReadService($client))->activeWarningCount(),
        $baselineActiveCount + 1
    );

    $cityCancelled = $writeService->cancel($cityDraft['id']);
    assertWorkflowResult('CityCancelled', $cityCancelled['status'], 'CANCELLED');
    assertWorkflowResult(
        'CancellationRestoresActiveCount',
        (new DrrmEarlyWarningReadService($client))->activeWarningCount(),
        $baselineActiveCount
    );

    try {
        $writeService->activate($cityDraft['id']);
        throw new RuntimeException('Cancelled warning was reactivated.');
    } catch (DrrmEarlyWarningLifecycleException) {
        echo "CancelledReactivationRejected=PASS\n";
    }

    $barangayTitle = 'TEST - Module 4 BARANGAY ' . $testSuffix;
    $barangayDraft = $writeService->createDraft(array_replace($basePayload, [
        'title' => $barangayTitle,
        'hazard_type' => 'HEAVY_RAINFALL',
        'warning_level' => 'MODERATE',
        'scope_type' => 'BARANGAY',
        'barangay_ids' => [$barangays[0]['barangay_id'], $barangays[1]['barangay_id']],
    ]));
    $createdWarnings[$barangayDraft['id']] = $barangayTitle;
    assertWorkflowResult('BarangayCreatedAsDraft', $barangayDraft['status'], 'DRAFT');
    assertWorkflowResult('BarangayAreaCount', $barangayDraft['affected_area_count'], 2);

    $expiredTitle = 'TEST - Module 4 EXPIRED ' . $testSuffix;
    $expiredDraft = $writeService->createDraft(array_replace($basePayload, [
        'title' => $expiredTitle,
        'issued_at' => $now->modify('-2 hours')->format('Y-m-d\TH:i:sP'),
        'valid_until' => $now->modify('-1 hour')->format('Y-m-d\TH:i:sP'),
    ]));
    $createdWarnings[$expiredDraft['id']] = $expiredTitle;
    try {
        $writeService->activate($expiredDraft['id']);
        throw new RuntimeException('Expired draft was activated.');
    } catch (DrrmEarlyWarningLifecycleException) {
        echo "ExpiredActivationRejected=PASS\n";
    }
} finally {
    cleanupTestWarnings($client, $createdWarnings);

    $finalWarnings = $client->get('early_warnings', ['select' => 'id,title,status', 'order' => 'created_at.asc']);
    $finalAreas = $client->get('early_warning_areas', ['select' => 'id,warning_id', 'order' => 'created_at.asc']);
    assertWorkflowResult('BaselineWarningsPreserved', recordIds($finalWarnings), $baselineWarningIds);
    assertWorkflowResult('BaselineAreasPreserved', recordIds($finalAreas), $baselineAreaIds);
    echo 'FinalWarningCount=' . count($finalWarnings) . PHP_EOL;
    echo 'FinalAreaCount=' . count($finalAreas) . PHP_EOL;
    echo "ControlledWorkflowCleanup=PASS\n";
}
