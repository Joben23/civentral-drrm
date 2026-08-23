<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\DrrmCitizenWarningReadService;
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
require_once __DIR__ . '/../src/Services/DrrmEarlyWarningWriteService.php';
require_once __DIR__ . '/../src/Services/DrrmCitizenWarningReadService.php';

/** @param mixed $actual @param mixed $expected */
function assertCitizenResult(string $name, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($name . ' failed.');
    }
    echo $name . "=PASS\n";
}

/** @param list<array<string, mixed>> $records @return list<string> */
function citizenRecordIds(array $records): array
{
    $ids = array_map(static fn (array $record): string => (string) ($record['id'] ?? ''), $records);
    sort($ids);
    return $ids;
}

/** @param array<string, mixed> $response @return array<string, array<string, mixed>> */
function publicWarningsById(array $response): array
{
    $warnings = [];
    foreach ($response['warnings'] ?? [] as $warning) {
        if (is_array($warning) && is_string($warning['id'] ?? null)) {
            $warnings[$warning['id']] = $warning;
        }
    }
    return $warnings;
}

/** @param array<string, string> $createdWarnings warning ID => exact title */
function cleanupCitizenTestWarnings(SupabaseRestClient $client, array $createdWarnings): void
{
    foreach (array_reverse($createdWarnings, true) as $warningId => $title) {
        if (!str_starts_with($title, 'TEST - Citizen API ')) {
            throw new RuntimeException('Cleanup refused a title outside the controlled test prefix.');
        }

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
            || ($records[0]['title'] ?? null) !== $title) {
            throw new RuntimeException('Cleanup refused an unverified warning record.');
        }

        $client->delete('early_warning_areas', [
            'warning_id' => 'eq.' . $warningId,
            'select' => 'id',
        ]);
        $deleted = $client->delete('early_warnings', [
            'id' => 'eq.' . $warningId,
            'title' => 'eq.' . $title,
            'select' => 'id',
        ]);

        if (count($deleted) !== 1 || ($deleted[0]['id'] ?? null) !== $warningId) {
            throw new RuntimeException('Cleanup could not verify exact test-warning deletion.');
        }
    }
}

$config = SupabaseConfig::fromEnvironment(__DIR__ . '/../.env');
$client = new SupabaseRestClient($config);
$writeService = new DrrmEarlyWarningWriteService($client);
$publicService = new DrrmCitizenWarningReadService($client);
$createdWarnings = [];

$baselineWarnings = $client->get('early_warnings', [
    'select' => 'id,title,status',
    'order' => 'created_at.asc',
]);
$baselineAreas = $client->get('early_warning_areas', [
    'select' => 'id,warning_id',
    'order' => 'created_at.asc',
]);
$baselineWarningIds = citizenRecordIds($baselineWarnings);
$baselineAreaIds = citizenRecordIds($baselineAreas);
$baselinePublic = $publicService->activeWarnings();
$baselinePublicIds = array_keys(publicWarningsById($baselinePublic));
sort($baselinePublicIds);

try {
    $cancelledRecord = array_values(array_filter(
        $baselineWarnings,
        static fn (array $warning): bool => ($warning['title'] ?? null) === 'TEST - Caloocan Heavy Rainfall Warning'
            && ($warning['status'] ?? null) === 'CANCELLED'
    ));
    assertCitizenResult('KnownCancelledRecordPresent', count($cancelledRecord), 1);
    assertCitizenResult(
        'KnownCancelledRecordExcluded',
        isset(publicWarningsById($baselinePublic)[(string) $cancelledRecord[0]['id']]),
        false
    );

    $barangays = $writeService->availableBarangays();
    assertCitizenResult('ValidatedBarangayCatalogCount', count($barangays), 187);

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $suffix = $now->format('YmdHis') . '-' . bin2hex(random_bytes(3));
    $basePayload = [
        'hazard_type' => 'FLOOD',
        'warning_level' => 'LOW',
        'source_code' => 'CIVENTRAL',
        'summary' => 'Controlled citizen active-warning API verification. This record must be removed after testing.',
        'issued_at' => $now->modify('-5 minutes')->format('Y-m-d\TH:i:sP'),
        'valid_until' => $now->modify('+2 hours')->format('Y-m-d\TH:i:sP'),
        'source_reference' => null,
        'scope_type' => 'CITY',
        'barangay_ids' => [],
    ];

    $draftTitle = 'TEST - Citizen API DRAFT ' . $suffix;
    $draft = $writeService->createDraft(array_replace($basePayload, ['title' => $draftTitle]));
    $createdWarnings[$draft['id']] = $draftTitle;

    $cancelledTitle = 'TEST - Citizen API CANCELLED ' . $suffix;
    $cancelled = $writeService->createDraft(array_replace($basePayload, ['title' => $cancelledTitle]));
    $createdWarnings[$cancelled['id']] = $cancelledTitle;
    $writeService->cancel($cancelled['id']);

    $expiredTitle = 'TEST - Citizen API EXPIRED ACTIVE ' . $suffix;
    $expired = $writeService->createDraft(array_replace($basePayload, [
        'title' => $expiredTitle,
        'issued_at' => $now->modify('-2 hours')->format('Y-m-d\TH:i:sP'),
    ]));
    $createdWarnings[$expired['id']] = $expiredTitle;
    $writeService->activate($expired['id']);
    $expiredUpdate = $client->patch('early_warnings', [
        'valid_until' => $now->modify('-1 hour')->format('Y-m-d\TH:i:sP'),
    ], [
        'id' => 'eq.' . $expired['id'],
        'status' => 'eq.ACTIVE',
        'title' => 'eq.' . $expiredTitle,
        'select' => 'id,status,valid_until',
    ]);
    assertCitizenResult('ControlledExpiredActivePrepared', count($expiredUpdate), 1);

    $cityTitle = 'TEST - Citizen API CITY ACTIVE ' . $suffix;
    $city = $writeService->createDraft(array_replace($basePayload, [
        'title' => $cityTitle,
        'hazard_type' => 'HEAVY_RAINFALL',
        'warning_level' => 'HIGH',
    ]));
    $createdWarnings[$city['id']] = $cityTitle;
    $writeService->activate($city['id']);

    $barangayTitle = 'TEST - Citizen API BARANGAY ACTIVE ' . $suffix;
    $barangay = $writeService->createDraft(array_replace($basePayload, [
        'title' => $barangayTitle,
        'hazard_type' => 'LANDSLIDE',
        'warning_level' => 'MODERATE',
        'scope_type' => 'BARANGAY',
        'barangay_ids' => [$barangays[0]['barangay_id'], $barangays[1]['barangay_id']],
    ]));
    $createdWarnings[$barangay['id']] = $barangayTitle;
    $writeService->activate($barangay['id']);

    $result = $publicService->activeWarnings();
    $publicWarnings = publicWarningsById($result);

    assertCitizenResult('DraftExcluded', isset($publicWarnings[$draft['id']]), false);
    assertCitizenResult('CancelledExcluded', isset($publicWarnings[$cancelled['id']]), false);
    assertCitizenResult('ExpiredActiveExcluded', isset($publicWarnings[$expired['id']]), false);
    assertCitizenResult('ValidActiveCityReturned', isset($publicWarnings[$city['id']]), true);
    assertCitizenResult('ValidActiveBarangayReturned', isset($publicWarnings[$barangay['id']]), true);
    assertCitizenResult('CityScope', $publicWarnings[$city['id']]['scope'], 'CITY');
    assertCitizenResult(
        'CityAreaProjection',
        $publicWarnings[$city['id']]['affected_areas'],
        [['scope' => 'CITY', 'name' => 'Caloocan City']]
    );
    assertCitizenResult('BarangayScope', $publicWarnings[$barangay['id']]['scope'], 'BARANGAY');

    $expectedBarangayNames = [$barangays[0]['name'], $barangays[1]['name']];
    sort($expectedBarangayNames, SORT_NATURAL | SORT_FLAG_CASE);
    $actualBarangayNames = array_map(
        static fn (array $area): string => (string) ($area['name'] ?? ''),
        $publicWarnings[$barangay['id']]['affected_areas']
    );
    sort($actualBarangayNames, SORT_NATURAL | SORT_FLAG_CASE);
    assertCitizenResult('ValidatedBarangayNamesOnly', $actualBarangayNames, $expectedBarangayNames);
    assertCitizenResult(
        'BarangayAreaIdsNotExposed',
        array_key_exists('barangay_id', $publicWarnings[$barangay['id']]['affected_areas'][0]),
        false
    );
    assertCitizenResult(
        'WarningLevelScale',
        $publicWarnings[$city['id']]['warning_level']['scale'],
        'CIVENTRAL Warning Level'
    );

    $expectedPublicIds = array_merge($baselinePublicIds, [$city['id'], $barangay['id']]);
    sort($expectedPublicIds);
    $actualPublicIds = array_keys($publicWarnings);
    sort($actualPublicIds);
    assertCitizenResult('OnlyEligibleActiveWarningsReturned', $actualPublicIds, $expectedPublicIds);

    $forbiddenKeys = [
        'source_id', 'warning_level_id', 'barangay_id', 'external_reference_id',
        'created_at', 'status', 'csrf_token', 'user_id', 'role_id',
    ];
    foreach ($forbiddenKeys as $forbiddenKey) {
        assertCitizenResult(
            'ForbiddenKeyAbsent_' . $forbiddenKey,
            str_contains(json_encode($result, JSON_THROW_ON_ERROR), '"' . $forbiddenKey . '"'),
            false
        );
    }
} finally {
    cleanupCitizenTestWarnings($client, $createdWarnings);

    $finalWarnings = $client->get('early_warnings', [
        'select' => 'id,title,status',
        'order' => 'created_at.asc',
    ]);
    $finalAreas = $client->get('early_warning_areas', [
        'select' => 'id,warning_id',
        'order' => 'created_at.asc',
    ]);

    assertCitizenResult('BaselineWarningsPreserved', citizenRecordIds($finalWarnings), $baselineWarningIds);
    assertCitizenResult('BaselineAreasPreserved', citizenRecordIds($finalAreas), $baselineAreaIds);
    echo 'FinalWarningCount=' . count($finalWarnings) . PHP_EOL;
    echo 'FinalAreaCount=' . count($finalAreas) . PHP_EOL;
    echo "ControlledCitizenApiCleanup=PASS\n";
}
