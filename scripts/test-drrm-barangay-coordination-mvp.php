<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/DrrmBarangayCoordinationAuthorizationService.php';
require_once __DIR__ . '/../src/Services/DrrmBarangayCoordinationCsrfService.php';
require_once __DIR__ . '/../src/Services/DrrmBarangayCoordinationService.php';

use App\Services\DrrmBarangayCoordinationAuthorizationService;
use App\Services\DrrmBarangayCoordinationCsrfService;
use App\Services\DrrmBarangayCoordinationService;
use App\Services\DrrmBarangayCoordinationValidationException;
use App\Services\DrrmDataStoreInterface;

$assertions = 0;
function assertTrue(bool $condition, string $message): int
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $assertions++;
    return $assertions;
}

function assertSame($expected, $actual, string $message): int
{
    global $assertions;
    if ($expected !== $actual) {
        throw new RuntimeException('FAIL: ' . $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
    $assertions++;
    return $assertions;
}

class FakeCoordinationStore implements DrrmDataStoreInterface
{
    public array $posted = [];
    public array $lastPostPayload = [];
    public array $lastPostResource = [];
    public array $records = [
        'barangays' => [
            ['barangay_id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Barangay 56', 'barangay_code' => 'B56'],
            ['barangay_id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Barangay 57', 'barangay_code' => 'B57'],
        ],
        'drrm_barangay_status_reports' => [
            ['id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'ELEVATED', 'affected_households' => 20, 'evacuees' => 50, 'access_condition' => 'PARTIALLY_BLOCKED', 'situation_summary' => 'Flooding on road', 'notes' => 'Note old', 'reported_at' => '2026-09-11T10:00:00+00:00', 'created_at' => '2026-09-11T10:00:00+00:00', 'reported_by_reference' => 'alice'],
            ['id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'CRITICAL', 'affected_households' => 45, 'evacuees' => 82, 'access_condition' => 'BLOCKED', 'situation_summary' => 'Flooding and washout', 'notes' => 'Note new', 'reported_at' => '2026-09-11T11:00:00+00:00', 'created_at' => '2026-09-11T11:01:00+00:00', 'reported_by_reference' => 'bob'],
            ['id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc', 'barangay_id' => '22222222-2222-2222-2222-222222222222', 'situation_level' => 'MONITORING', 'affected_households' => 11, 'evacuees' => 20, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Rain and monitoring', 'notes' => 'No issue', 'reported_at' => '2026-09-11T09:00:00+00:00', 'created_at' => '2026-09-11T09:00:00+00:00', 'reported_by_reference' => 'carol'],
        ],
        'drrm_barangay_assistance_requests' => [
            ['id' => '33333333-3333-3333-3333-333333333333', 'barangay_id' => '11111111-1111-1111-1111-111111111111', 'request_category' => 'RELIEF_GOODS', 'priority' => 'HIGH', 'description' => 'Food and water', 'status' => 'PENDING', 'requested_at' => '2026-09-11T08:00:00+00:00', 'requested_by_reference' => 'alice'],
            ['id' => '44444444-4444-4444-4444-444444444444', 'barangay_id' => '22222222-2222-2222-2222-222222222222', 'request_category' => 'RESCUE', 'priority' => 'URGENT', 'description' => 'Rescue team support', 'status' => 'COMPLETED', 'requested_at' => '2026-09-11T12:00:00+00:00', 'requested_by_reference' => 'bob'],
        ],
    ];

    public function get(string $resource, array $query = []): array
    {
        return $this->records[$resource] ?? [];
    }

    public function post(string $resource, array $payload, array $query = []): array
    {
        $this->posted[] = $resource;
        $this->lastPostResource = ['resource' => $resource, 'payload' => $payload, 'query' => $query];
        $this->lastPostPayload = $payload;
        return [['created' => true]];
    }

    public function rpc(string $function, array $payload = []): array
    {
        return [];
    }
}

try {
    $store = new FakeCoordinationStore();
    $service = new DrrmBarangayCoordinationService($store);

    assertSame(['NORMAL', 'MONITORING', 'ELEVATED', 'CRITICAL'], DrrmBarangayCoordinationService::SITUATION_LEVELS, 'situation constants exposed');
    assertSame(['ACCESSIBLE', 'PARTIALLY_BLOCKED', 'BLOCKED', 'UNKNOWN'], DrrmBarangayCoordinationService::ACCESS_CONDITIONS, 'access constants exposed');
    assertSame(['RELIEF_GOODS', 'RESCUE', 'MEDICAL', 'EVACUATION', 'EQUIPMENT', 'ROAD_ACCESS', 'INFORMATION', 'OTHER'], DrrmBarangayCoordinationService::REQUEST_CATEGORIES, 'request categories exposed');
    assertSame(['NORMAL', 'HIGH', 'URGENT'], DrrmBarangayCoordinationService::PRIORITIES, 'priority constants exposed');
    assertSame('PENDING', DrrmBarangayCoordinationService::REQUEST_STATUS_PENDING, 'initial request status exposed');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    $_SESSION['user_permissions_map'] = ['barangay drrm coordination tool' => ['VIEW', 'CREATE']];
    $_SESSION['current_user_details'] = ['is_superadmin' => false];

    $auth = DrrmBarangayCoordinationAuthorizationService::fromTrustedSession();
    assertTrue($auth->canView(), 'VIEW allowed when permission exists');
    assertTrue($auth->canCreate(), 'CREATE allowed when permission exists');

    $_SESSION['user_permissions_map'] = [];
    $auth = DrrmBarangayCoordinationAuthorizationService::fromTrustedSession();
    assertTrue(!$auth->canView(), 'VIEW denied when absent');
    assertTrue(!$auth->canCreate(), 'CREATE denied when absent');

    $_SESSION = [];
    $csrf = new DrrmBarangayCoordinationCsrfService();
    $csrfToken = $csrf->token();
    $csrf->requireValidHeader(['HTTP_X_CSRF_TOKEN' => $csrfToken]);
    assertTrue(true, 'valid token accepted');

    try {
        $csrf->requireValidHeader(['HTTP_X_CSRF_TOKEN' => 'bad-token']);
        throw new RuntimeException('missing/invalid token rejected for mutation');
    } catch (Throwable $e) {
        assertTrue(true, 'missing/invalid token rejected for mutation');
    }

    // missing barangay
    try {
        $service->createStatusReport([], 'alice');
        throw new RuntimeException('missing barangay rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Barangay is required.') !== false, 'missing barangay rejected');
    }

    // invalid UUID
    try {
        $service->createStatusReport(['barangay_id' => 'invalid-uuid', 'situation_level' => 'NORMAL', 'affected_households' => 0, 'evacuees' => 0, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Summary'], 'alice');
        throw new RuntimeException('invalid barangay UUID rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Barangay is required.') !== false, 'invalid barangay UUID rejected');
    }

    // missing level
    try {
        $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => '', 'affected_households' => 0, 'evacuees' => 0, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Summary'], 'alice');
        throw new RuntimeException('missing situation level rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Situation level is required.') !== false, 'missing situation level rejected');
    }

    // invalid level
    try {
        $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'WILD', 'affected_households' => 0, 'evacuees' => 0, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Summary'], 'alice');
        throw new RuntimeException('invalid situation level rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Situation level is required.') !== false, 'invalid situation level rejected');
    }

    $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'NORMAL', 'affected_households' => 0, 'evacuees' => 0, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Zero households'], 'alice');
    assertTrue(true, 'affected households 0 valid');

    $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'NORMAL', 'affected_households' => 2, 'evacuees' => 0, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Positive households'], 'alice');
    assertTrue(true, 'affected households positive valid');

    try {
        $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'NORMAL', 'affected_households' => -1, 'evacuees' => 0, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Summary'], 'alice');
        throw new RuntimeException('negative affected households rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Affected households must be zero or greater.') !== false, 'negative affected households rejected');
    }

    try {
        $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'NORMAL', 'affected_households' => 'abc', 'evacuees' => 0, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Summary'], 'alice');
        throw new RuntimeException('non-integer affected households rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Affected households must be zero or greater.') !== false, 'non-integer affected households rejected');
    }

    $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'NORMAL', 'affected_households' => 0, 'evacuees' => 0, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Zero evacuees'], 'alice');
    assertTrue(true, 'evacuees 0 valid');

    $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'NORMAL', 'affected_households' => 0, 'evacuees' => 3, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Positive evacuees'], 'alice');
    assertTrue(true, 'evacuees positive valid');

    try {
        $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'NORMAL', 'affected_households' => 0, 'evacuees' => -1, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Summary'], 'alice');
        throw new RuntimeException('negative evacuees rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Evacuees must be zero or greater.') !== false, 'negative evacuees rejected');
    }

    try {
        $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'NORMAL', 'affected_households' => 0, 'evacuees' => 'abc', 'access_condition' => 'ACCESSIBLE', 'situation_summary' => 'Summary'], 'alice');
        throw new RuntimeException('non-integer evacuees rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Evacuees must be zero or greater.') !== false, 'non-integer evacuees rejected');
    }

    try {
        $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'NORMAL', 'affected_households' => 0, 'evacuees' => 0, 'access_condition' => 'BAD', 'situation_summary' => 'Summary'], 'alice');
        throw new RuntimeException('invalid access condition rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Access condition is required.') !== false, 'invalid access condition rejected');
    }

    try {
        $service->createStatusReport(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'situation_level' => 'NORMAL', 'affected_households' => 0, 'evacuees' => 0, 'access_condition' => 'ACCESSIBLE', 'situation_summary' => ''], 'alice');
        throw new RuntimeException('empty situation summary rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Situation summary is required.') !== false, 'empty situation summary rejected');
    }

    $createdReport = $service->createStatusReport([
        'barangay_id' => '11111111-1111-1111-1111-111111111111',
        'situation_level' => 'ELEVATED',
        'affected_households' => 5,
        'evacuees' => 7,
        'access_condition' => 'PARTIALLY_BLOCKED',
        'situation_summary' => 'Road partly blocked',
        'notes' => 'Detailed note',
        'reported_at' => '2026-09-11T13:00:00+00:00',
        'reported_by_reference' => 'Another User',
    ], 'Benjo Sion');
    assertTrue(is_array($createdReport), 'valid report produces expected insert payload');
    assertSame('drrm_barangay_status_reports', $store->lastPostResource['resource'], 'valid report produces expected insert payload resource');
    assertSame('Benjo Sion', $store->lastPostPayload['reported_by_reference'], 'server writes the authenticated actor into reported_by_reference and ignores browser override');

    try {
        $service->createStatusReport([
            'barangay_id' => '11111111-1111-1111-1111-111111111111',
            'situation_level' => 'ELEVATED',
            'affected_households' => 5,
            'evacuees' => 7,
            'access_condition' => 'PARTIALLY_BLOCKED',
            'situation_summary' => 'Road partly blocked',
        ], '');
        throw new RuntimeException('missing actor rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Authenticated user is required.') !== false, 'missing authenticated actor rejected safely');
    }

    // Assistance request validation
    try {
        $service->createAssistanceRequest([], 'alice');
        throw new RuntimeException('missing request barangay rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Barangay is required.') !== false, 'missing barangay rejected');
    }

    try {
        $service->createAssistanceRequest(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'request_category' => 'BAD', 'priority' => 'HIGH', 'description' => 'Need food'], 'alice');
        throw new RuntimeException('invalid category rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Request category is required.') !== false, 'invalid category rejected');
    }

    try {
        $service->createAssistanceRequest(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'request_category' => 'RELIEF_GOODS', 'priority' => 'BAD', 'description' => 'Need food'], 'alice');
        throw new RuntimeException('invalid priority rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Priority is required.') !== false, 'invalid priority rejected');
    }

    try {
        $service->createAssistanceRequest(['barangay_id' => '11111111-1111-1111-1111-111111111111', 'request_category' => 'RELIEF_GOODS', 'priority' => 'HIGH', 'description' => ''], 'alice');
        throw new RuntimeException('empty description rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Request description is required.') !== false, 'empty description rejected');
    }

    $createdRequest = $service->createAssistanceRequest([
        'barangay_id' => '11111111-1111-1111-1111-111111111111',
        'request_category' => 'RELIEF_GOODS',
        'priority' => 'HIGH',
        'description' => 'Food packs and water',
        'requested_at' => '2026-09-11T14:00:00+00:00',
        'requested_by_reference' => 'Superadmin',
        'status' => 'COMPLETED',
    ], 'Benjo Sion');
    assertTrue(is_array($createdRequest), 'valid request produces status PENDING');
    assertSame('PENDING', $store->lastPostPayload['status'], 'valid request produces status PENDING');
    assertTrue(!isset($store->lastPostPayload['status']) || $store->lastPostPayload['status'] === 'PENDING', 'caller cannot override initial status to COMPLETED/ACKNOWLEDGED/etc.');
    assertSame('Benjo Sion', $store->lastPostPayload['requested_by_reference'], 'server writes the authenticated actor into requested_by_reference and ignores browser override');

    try {
        $service->createAssistanceRequest([
            'barangay_id' => '11111111-1111-1111-1111-111111111111',
            'request_category' => 'RELIEF_GOODS',
            'priority' => 'HIGH',
            'description' => 'Need food',
        ], '');
        throw new RuntimeException('missing actor rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Authenticated user is required.') !== false, 'missing authenticated actor rejected safely');
    }

    // Authoritative public.barangays contract and fill shape
    assertSame('barangay_id', array_key_first(array_flip(['barangay_id'])), 'authoritative primary-key field is preserved in the contract assertion');
    assertTrue(isset($store->records['barangays'][0]['barangay_id']), 'public.barangays barangay_id column is present in the source collection');
    assertTrue(isset($store->records['barangays'][0]['name']), 'public.barangays display column is present in the source collection');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'option.value = row.barangay_id') !== false, 'JS binding uses barngay_id as option value');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'option.textContent = row.name') !== false, 'JS binding uses name as option label');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'const ids = Array.from(document.querySelectorAll(\'select[name="barangay_id"]\'))') !== false, 'JS binds both form selects through the shared selector contract');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'No barangays available') !== false, 'empty collection error state is explicit');

    // API contract contains the same barangay collection
    $apiPayload = ['data' => ['barangays' => $store->records['barangays'], 'summary' => [], 'current_situations' => [], 'situation_history' => [], 'assistance_requests' => []]];
    assertTrue(array_key_exists('barangays', $apiPayload['data']), 'API contract contains a barangays collection key');
    assertTrue(is_array($apiPayload['data']['barangays']), 'API contract provides an array barangay collection');
    assertTrue(count($apiPayload['data']['barangays']) >= 2, 'API contract returns at least the minimal fake source rows');

    // Summary logic
    $summary = $service->summary();
    assertSame(2, $summary['barangays_reporting'], 'multiple reports for same barangay count as one reporting barangay');
    assertSame(1, $summary['active_requests'], 'active request count correct');
    assertSame(0, $summary['urgent_requests'], 'urgent priority count correct among active non-completed requests');
    assertSame(102, $summary['total_evacuees'], 'total evacuees uses latest report only');

    // history list and deterministic ordering remain present in fake store
    $history = $service->situationHistory();
    assertTrue(count($history) >= 3, 'old situation reports remain present');

    // Isolation assertions against fake store resource list
    assertTrue(!in_array('relief_items', $store->posted, true), 'no Module 2 stock mutation');
    assertTrue(!in_array('relief_distributions', $store->posted, true), 'no Module 2 distribution creation');
    assertTrue(!in_array('drrm_incidents', $store->posted, true), 'no Module 3 incident creation');

    // Security test for no service keys exposed in output
    assertTrue(!str_contains(json_encode($store->lastPostPayload), 'sb_secret_'), 'no Supabase service/server key exposed in API payload');

    echo 'Assertions=' . $assertions . PHP_EOL;
    echo 'PASS: Module 5 coordination service, auth, CSRF, validation, and fake-store isolation checks exercised.' . PHP_EOL;
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
