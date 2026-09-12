<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/DrrmBarangayAssignmentResolver.php';
require_once __DIR__ . '/../src/Services/DrrmBarangayCoordinationAuthorizationService.php';
require_once __DIR__ . '/../src/Services/DrrmBarangayCoordinationCsrfService.php';
require_once __DIR__ . '/../src/Services/DrrmBarangayCoordinationService.php';

use App\Services\AuthService;
use App\Services\DrrmBarangayAssignmentResolver;
use App\Services\DrrmBarangayCoordinationAuthorizationException;
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
    public string $lastRpcFunction = '';
    public array $lastRpcPayload = [];
    public array $lastResolverQuery = [];

    public function get(string $resource, array $query = []): array
    {
        if ($resource === 'drrm_barangay_user_assignments' && $query !== []) {
            $this->lastResolverQuery = $query;
        }

        $rows = $this->records[$resource] ?? [];
        foreach ($query as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (in_array($key, ['select', 'order', 'limit', 'offset'], true)) {
                continue;
            }
            if (!str_starts_with($value, 'eq.')) {
                continue;
            }

            $column = $key;
            $expected = substr($value, 3);
            $rows = array_values(array_filter($rows, static function (array $row) use ($column, $expected): bool {
                $actual = $row[$column] ?? null;
                if (is_bool($actual)) {
                    return ($expected === 'true' && $actual === true)
                        || ($expected === 'false' && $actual === false)
                        || ($expected === '1' && $actual === true)
                        || ($expected === '0' && $actual === false);
                }
                if (is_string($actual)) {
                    return trim($actual) === trim($expected);
                }
                if (is_int($actual)) {
                    return (string) $actual === $expected;
                }
                return (string) ($actual ?? '') === $expected;
            }));
        }
        return $rows;
    }

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
        'drrm_barangay_user_assignments' => [
            ['id' => '55555555-5555-5555-5555-555555555555', 'user_reference' => 'alice', 'barangay_id' => '11111111-1111-1111-1111-111111111111', 'is_active' => true, 'created_at' => '2026-09-11T00:00:00+00:00', 'updated_at' => '2026-09-11T00:00:00+00:00'],
            ['id' => '66666666-6666-6666-6666-666666666666', 'user_reference' => 'alice', 'barangay_id' => '22222222-2222-2222-2222-222222222222', 'is_active' => false, 'created_at' => '2026-09-11T00:00:00+00:00', 'updated_at' => '2026-09-11T00:00:00+00:00'],
            ['id' => '77777777-7777-7777-7777-777777777777', 'user_reference' => 'bob', 'barangay_id' => '22222222-2222-2222-2222-222222222222', 'is_active' => true, 'created_at' => '2026-09-11T00:00:00+00:00', 'updated_at' => '2026-09-11T00:00:00+00:00'],
        ],
    ];

    public function post(string $resource, array $payload, array $query = []): array
    {
        $this->posted[] = $resource;
        $this->lastPostResource = ['resource' => $resource, 'payload' => $payload, 'query' => $query];
        $this->lastPostPayload = $payload;
        return [['created' => true]];
    }

    public function rpc(string $function, array $payload = []): array
    {
        $this->lastRpcFunction = $function;
        $this->lastRpcPayload = $payload;
        return [];
    }
}

try {
    $store = new FakeCoordinationStore();
    $auth = new AuthService();

    $resolver = new DrrmBarangayAssignmentResolver($store);
    assertSame('11111111-1111-1111-1111-111111111111', $resolver->activeBarangayIdForUser('alice'), 'resolver active user_reference returns assigned barangay');
    $lastResolverQuery = $store->lastResolverQuery;
    assertSame(null, $resolver->activeBarangayIdForUser('carol'), 'resolver different user_reference returns null');
    assertSame(null, $resolver->activeBarangayIdForUser('missing@example.com'), 'resolver missing assignment returns null');
    assertSame(null, $resolver->activeBarangayIdForUser(''), 'resolver empty user_reference returns null');
    assertSame(null, $resolver->activeBarangayIdForUser('alice@example.com'), 'resolver email-like user_reference field does not participate');
    assertTrue(isset($lastResolverQuery['user_reference']) && $lastResolverQuery['user_reference'] === 'eq.alice', 'resolver emits canonical user_reference filter');
    assertTrue(isset($lastResolverQuery['is_active']) && $lastResolverQuery['is_active'] === 'eq.true', 'resolver emits canonical is_active filter');

    // API/service method contract: get_barangay_assignment -> readAssignment,
    // set_barangay_assignment -> assignUserBarangay,
    // deactivate_barangay_assignment -> deactivateUserBarangay.
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), 'get_barangay_assignment') !== false, 'API route contains get_barangay_assignment action');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), '->readAssignment') !== false, 'API route maps get_barangay_assignment to readAssignment');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), 'set_barangay_assignment') !== false, 'API route contains set_barangay_assignment action');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), '->assignUserBarangay') !== false, 'API route maps set_barangay_assignment to assignUserBarangay');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), 'deactivate_barangay_assignment') !== false, 'API route contains deactivate_barangay_assignment action');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), '->deactivateUserBarangay') !== false, 'API route maps deactivate_barangay_assignment to deactivateUserBarangay');

    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmBarangayCoordinationService.php'), 'function readAssignment') !== false, 'service contains readAssignment');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmBarangayCoordinationService.php'), 'function assignUserBarangay') !== false, 'service contains assignUserBarangay');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmBarangayCoordinationService.php'), 'function deactivateUserBarangay') !== false, 'service contains deactivateUserBarangay');

    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), '->setAssignment(') === false, 'obsolete setAssignment API call is absent');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), '->deactivateAssignment(') === false, 'obsolete deactivateAssignment API call is absent');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmBarangayCoordinationService.php'), 'setAssignment(') === false, 'obsolete setAssignment service method is absent');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmBarangayCoordinationService.php'), 'deactivateAssignment(') === false, 'obsolete deactivateAssignment service method is absent');

    $service = new DrrmBarangayCoordinationService($store, $auth);

    assertSame(['NORMAL', 'MONITORING', 'ELEVATED', 'CRITICAL'], DrrmBarangayCoordinationService::SITUATION_LEVELS, 'situation constants exposed');
    assertSame(['ACCESSIBLE', 'PARTIALLY_BLOCKED', 'BLOCKED', 'UNKNOWN'], DrrmBarangayCoordinationService::ACCESS_CONDITIONS, 'access constants exposed');
    assertSame(['RELIEF_GOODS', 'RESCUE', 'MEDICAL', 'EVACUATION', 'EQUIPMENT', 'ROAD_ACCESS', 'INFORMATION', 'OTHER'], DrrmBarangayCoordinationService::REQUEST_CATEGORIES, 'request categories exposed');
    assertSame(['NORMAL', 'HIGH', 'URGENT'], DrrmBarangayCoordinationService::PRIORITIES, 'priority constants exposed');
    assertSame('PENDING', DrrmBarangayCoordinationService::REQUEST_STATUS_PENDING, 'initial request status exposed');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    $_SESSION['user_permissions_map'] = ['barangay drrm coordination tool' => ['VIEW', 'CREATE', 'EDIT']];
    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => true];

    $auth = DrrmBarangayCoordinationAuthorizationService::fromTrustedSession();
    assertTrue($auth->canView(), 'VIEW allowed when permission exists');
    assertTrue($auth->canCreate(), 'CREATE allowed when permission exists');
    assertTrue($auth->canEdit(), 'EDIT recognized when capability is present in the trusted map');

    $_SESSION['user_permissions_map'] = ['barangay drrm coordination tool' => ['CREATE']];
    $auth = DrrmBarangayCoordinationAuthorizationService::fromTrustedSession();
    assertTrue(!$auth->canView(), 'VIEW denied when absent');
    assertTrue($auth->canCreate(), 'CREATE allowed when permission exists');
    assertTrue(!$auth->canEdit(), 'EDIT denied when absent from CREATE-only map');

    $_SESSION['user_permissions_map'] = ['barangay drrm coordination tool' => ['VIEW', 'EDIT']];
    $auth = DrrmBarangayCoordinationAuthorizationService::fromTrustedSession();
    assertTrue($auth->canView(), 'VIEW allowed when permission exists');
    assertTrue(!$auth->canCreate(), 'CREATE denied when absent in EDIT-only map');
    assertTrue($auth->canEdit(), 'EDIT allowed when permission exists');

    $_SESSION['user_permissions_map'] = [];
    $auth = DrrmBarangayCoordinationAuthorizationService::fromTrustedSession();
    assertTrue(!$auth->canView(), 'VIEW denied when absent');
    assertTrue(!$auth->canCreate(), 'CREATE denied when absent');
    assertTrue(!$auth->canEdit(), 'EDIT denied when absent');

    assertTrue(DrrmBarangayCoordinationService::transitionIsAllowed('PENDING', 'ACKNOWLEDGED'), 'PENDING → ACKNOWLEDGED true');
    assertTrue(DrrmBarangayCoordinationService::transitionIsAllowed('PENDING', 'CANCELLED'), 'PENDING → CANCELLED true');
    assertTrue(DrrmBarangayCoordinationService::transitionIsAllowed('ACKNOWLEDGED', 'IN_PROGRESS'), 'ACKNOWLEDGED → IN_PROGRESS true');
    assertTrue(DrrmBarangayCoordinationService::transitionIsAllowed('ACKNOWLEDGED', 'CANCELLED'), 'ACKNOWLEDGED → CANCELLED true');
    assertTrue(DrrmBarangayCoordinationService::transitionIsAllowed('IN_PROGRESS', 'COMPLETED'), 'IN_PROGRESS → COMPLETED true');
    assertTrue(DrrmBarangayCoordinationService::transitionIsAllowed('IN_PROGRESS', 'CANCELLED'), 'IN_PROGRESS → CANCELLED true');

    assertTrue(!DrrmBarangayCoordinationService::transitionIsAllowed('PENDING', 'IN_PROGRESS'), 'PENDING → IN_PROGRESS false');
    assertTrue(!DrrmBarangayCoordinationService::transitionIsAllowed('PENDING', 'COMPLETED'), 'PENDING → COMPLETED false');
    assertTrue(!DrrmBarangayCoordinationService::transitionIsAllowed('ACKNOWLEDGED', 'COMPLETED'), 'ACKNOWLEDGED → COMPLETED false');
    assertTrue(!DrrmBarangayCoordinationService::transitionIsAllowed('COMPLETED', 'ACKNOWLEDGED'), 'backwards false');
    assertTrue(!DrrmBarangayCoordinationService::transitionIsAllowed('CANCELLED', 'ACKNOWLEDGED'), 'cancelled terminal false');
    assertTrue(!DrrmBarangayCoordinationService::transitionIsAllowed('COMPLETED', 'CANCELLED'), 'completed terminal false');

    assertTrue(DrrmBarangayCoordinationAuthorizationService::ACTION_CREATE === 'CREATE', 'create action symbol preserved');
    assertTrue(DrrmBarangayCoordinationAuthorizationService::ACTION_EDIT === 'EDIT', 'edit action symbol preserved');

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

    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => true];

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

    // unassigned fail-closed create path for non-global actors
    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => false, 'email' => 'no.assignment@example.com'];
    try {
        $service->createStatusReport([
            'barangay_id' => '11111111-1111-1111-1111-111111111111',
            'situation_level' => 'NORMAL',
            'affected_households' => 0,
            'evacuees' => 0,
            'access_condition' => 'ACCESSIBLE',
            'situation_summary' => 'Summary',
        ], 'alice');
        throw new RuntimeException('unassigned scoped actor rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Barangay assignment is required.') !== false, 'unassigned actor receives fail-closed create denial');
    }

    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => true];

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
    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => true];
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
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'select[name="barangay_id"]:not([data-assignment-barangay])') !== false, 'JS operational loader excludes assignment admin selector by ownership');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'adminSection.querySelector(\'[data-assignment-barangay]\')') !== false, 'assignment admin reads its dropdown through the unique assignment selector');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'state.assignmentBarangays') !== false, 'assignment catalog state remains separate from state.barangays');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'const state = {') !== false, 'assignment and operational state are split into an explicit state object');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'No barangays available') !== false, 'empty collection error state is explicit');

    // API contract contains the same barangay collection
    $apiPayload = ['data' => ['barangays' => $store->records['barangays'], 'summary' => [], 'current_situations' => [], 'situation_history' => [], 'assistance_requests' => []]];
    assertTrue(array_key_exists('barangays', $apiPayload['data']), 'API contract contains a barangays collection key');
    assertTrue(is_array($apiPayload['data']['barangays']), 'API contract provides an array barangay collection');
    assertTrue(count($apiPayload['data']['barangays']) >= 2, 'API contract returns at least the minimal fake source rows');

    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => true];

    // Summary logic
    $summary = $service->summary();
    assertSame(2, $summary['barangays_reporting'], 'multiple reports for same barangay count as one reporting barangay');
    assertSame(1, $summary['active_requests'], 'active request count correct');
    assertSame(0, $summary['urgent_requests'], 'urgent priority count correct among active non-completed requests');
    assertSame(102, $summary['total_evacuees'], 'total evacuees uses latest report only');

    // history list and deterministic ordering remain present in fake store
    $history = $service->situationHistory();
    assertTrue(count($history) >= 3, 'old situation reports remain present');

    $_SESSION['current_user_details'] = [
        'is_superadmin' => false,
        'is_global_access' => false,
        'user_reference' => 'alice',
    ];
    $_SESSION['user_id'] = 'alice';
    $scopedService = new DrrmBarangayCoordinationService($store, new AuthService());
    $scopedReport = $scopedService->createStatusReport([
        'barangay_id' => '22222222-2222-2222-2222-222222222222',
        'situation_level' => 'NORMAL',
        'affected_households' => 0,
        'evacuees' => 0,
        'access_condition' => 'ACCESSIBLE',
        'situation_summary' => 'Scoped report enforced',
    ], 'alice');
    assertTrue(is_array($scopedReport), 'scoped create path accepts the payload format');
    assertSame('11111111-1111-1111-1111-111111111111', $store->lastPostPayload['barangay_id'], 'server replaces browser barangay_id with the assigned barangay for a scoped user');

    $currentScoped = $scopedService->currentSituations();
    assertTrue(count($currentScoped) >= 1, 'scoped current situations are filtered only to the assigned barangay');
    foreach ($currentScoped as $row) {
        assertSame('11111111-1111-1111-1111-111111111111', (string) ($row['barangay_id'] ?? ''), 'current situations stay within the assigned barangay');
    }

    $historyScoped = $scopedService->situationHistory();
    assertTrue(count(array_filter($historyScoped, static fn($row) => (string) ($row['barangay_id'] ?? '') === '22222222-2222-2222-2222-222222222222')) === 0, 'situation history is filtered away from unassigned barangays for a scoped user');

    $requestsScoped = $scopedService->assistanceRequests();
    assertTrue(count(array_filter($requestsScoped, static fn($row) => (string) ($row['barangay_id'] ?? '') === '11111111-1111-1111-1111-111111111111')) === count($requestsScoped), 'assistance requests are filtered to the assigned barangay');

    // Isolation assertions against fake store resource list
    assertTrue(!in_array('relief_items', $store->posted, true), 'no Module 2 stock mutation');
    assertTrue(!in_array('relief_distributions', $store->posted, true), 'no Module 2 distribution creation');
    assertTrue(!in_array('drrm_incidents', $store->posted, true), 'no Module 3 incident creation');

    // Security test for no service keys exposed in output
    // Assignment management authorization and RPC regression tests.
    $_SESSION['user_permissions_map'] = ['barangay drrm coordination tool' => ['VIEW']];
    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => false];

    try {
        $service->readAssignment('alice');
        throw new RuntimeException('readAssignment should require EDIT');
    } catch (DrrmBarangayCoordinationAuthorizationException $e) {
        assertTrue(true, 'readAssignment requires EDIT');
    }

    try {
        $service->assignUserBarangay('alice', '11111111-1111-1111-1111-111111111111');
        throw new RuntimeException('assignUserBarangay should require EDIT');
    } catch (DrrmBarangayCoordinationAuthorizationException $e) {
        assertTrue(true, 'assignUserBarangay requires EDIT');
    }

    try {
        $service->deactivateUserBarangay('alice');
        throw new RuntimeException('deactivateUserBarangay should require EDIT');
    } catch (DrrmBarangayCoordinationAuthorizationException $e) {
        assertTrue(true, 'deactivateUserBarangay requires EDIT');
    }

    $_SESSION['user_permissions_map'] = ['barangay drrm coordination tool' => ['VIEW', 'EDIT']];
    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => false];

    $service->assignUserBarangay('  alice@example.com  ', '11111111-1111-1111-1111-111111111111');
    assertSame('set_drrm_barangay_user_assignment', $store->lastRpcFunction, 'set calls exact RPC');
    assertSame(['p_user_reference' => 'alice@example.com', 'p_barangay_id' => '11111111-1111-1111-1111-111111111111'], $store->lastRpcPayload, 'set RPC receives canonical p_user_reference and p_barangay_id');

    $service->deactivateUserBarangay('  alice@example.com  ');
    assertSame('deactivate_drrm_barangay_user_assignment', $store->lastRpcFunction, 'deactivate calls exact RPC');
    assertSame(['p_user_reference' => 'alice@example.com'], $store->lastRpcPayload, 'deactivate RPC receives p_user_reference');

    try {
        $service->assignUserBarangay('   ', '11111111-1111-1111-1111-111111111111');
        throw new RuntimeException('empty user_reference rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'User reference is required.') !== false, 'empty user_reference rejected');
    }

    try {
        $service->assignUserBarangay('alice@example.com', 'not-a-uuid');
        throw new RuntimeException('invalid barangay UUID rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'Barangay is required.') !== false, 'invalid barangay UUID rejected');
    }

    try {
        $service->deactivateUserBarangay('');
        throw new RuntimeException('empty deactivate user reference rejected');
    } catch (DrrmBarangayCoordinationValidationException $e) {
        assertTrue(strpos($e->getMessage(), 'User reference is required.') !== false, 'empty deactivate user reference rejected');
    }

    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'data-assignment-set') !== false, 'JS contains assignment set handler hook');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'data-assignment-deactivate') !== false, 'JS contains assignment deactivate handler hook');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'data-assignment-current') !== false, 'JS contains assignment current display hook');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/html/drrm/barangay-assignment-admin.html'), 'data-assignment-barangay') !== false, 'admin HTML contains data-assignment-barangay');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'select[name="barangay_id"]:not([data-assignment-barangay])') !== false, 'JS operational selector excludes assignment admin via data-assignment-barangay');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'adminSection.querySelector(\'[data-assignment-barangay]\')') !== false, 'JS admin code explicitly queries the unique assignment admin selector');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'state.barangays') !== false, 'state.barangays exists for operational scoped data');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'state.assignmentBarangays') !== false, 'state.assignmentBarangays exists separately for assignment catalog data');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'get_assignment_barangays') !== false, 'get_assignment_barangays populates the assignment catalog');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'state.assignmentBarangays') !== false, 'current assignment name lookup source is state.assignmentBarangays');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'state.barangays') !== false, 'operational scoped barangay state remains separate');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'get_barangay_assignment') !== false, 'JS contains get_barangay_assignment action');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'set_barangay_assignment') !== false, 'JS contains set_barangay_assignment action');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'deactivate_barangay_assignment') !== false, 'JS contains deactivate_barangay_assignment action');

    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmBarangayCoordinationService.php'), 'deactivate_drrm_barangay_user_assignment') !== false, 'service contract retains exact deactivate RPC function');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmBarangayCoordinationService.php'), 'set_drrm_barangay_user_assignment') !== false, 'service contract retains exact set RPC function');

    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'setAssignment(') === false, 'obsolete setAssignment JS call absent');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'deactivateAssignment(') === false, 'obsolete deactivateAssignment JS call absent');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), 'setAssignment(') === false, 'obsolete setAssignment API call absent');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), 'deactivateAssignment(') === false, 'obsolete deactivateAssignment API call absent');

    // Assignment admin catalog regression checks.
    $_SESSION['user_permissions_map'] = ['barangay drrm coordination tool' => ['VIEW']];
    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => false];

    try {
        $service->assignmentBarangays();
        throw new RuntimeException('assignmentBarangays should require EDIT');
    } catch (DrrmBarangayCoordinationAuthorizationException $e) {
        assertTrue(true, 'assignmentBarangays requires EDIT');
    }

    $_SESSION['user_permissions_map'] = ['barangay drrm coordination tool' => ['VIEW', 'EDIT']];
    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => false];
    $_SESSION['user_id'] = 'bob';

    $assignmentCatalog = $service->assignmentBarangays();
    assertTrue(is_array($assignmentCatalog), 'assignment catalog returns array');
    assertTrue(count($assignmentCatalog) >= 2, 'EDIT actor receives barangay catalog');
    assertTrue(isset($assignmentCatalog[0]['barangay_id']), 'catalog contains barangay_id');
    assertTrue(isset($assignmentCatalog[0]['name']), 'catalog contains name');
    assertTrue(isset($assignmentCatalog[0]['barangay_code']), 'catalog contains barangay_code');

    // Operational availableBarangays() is all-barangay for any module5 EDIT actor. The catalog stays separate and is a reference feed only.
    $_SESSION['user_permissions_map'] = ['barangay drrm coordination tool' => ['VIEW', 'EDIT']];
    $_SESSION['current_user_details'] = ['is_superadmin' => false, 'is_global_access' => false, 'user_reference' => 'bob'];
    $_SESSION['user_id'] = 'bob';
    $editVisibleAvailable = $service->availableBarangays();
    assertSame(2, count($editVisibleAvailable), 'EDIT actor with no global profile sees every barangay in operational Module 5 reads');
    assertTrue(count(array_filter($editVisibleAvailable, static fn(array $row): bool => (string) ($row['barangay_id'] ?? '') === '22222222-2222-2222-2222-222222222222')) >= 1, 'EDIT actor sees the assigned barangay rows in the all-zone feed');

    // Assignment catalog should not be subject to the scoped actor's resolver filter.
    $catalogRows = $service->assignmentBarangays();
    assertTrue(count(array_filter($catalogRows, static fn(array $row): bool => (string) ($row['barangay_id'] ?? '') === '11111111-1111-1111-1111-111111111111')) >= 1, 'assignment catalog is not filtered by target/current scope');

    // API route contract proof.
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), 'get_assignment_barangays') !== false, 'API includes get_assignment_barangays action');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), '->assignmentBarangays()') !== false, 'API maps get_assignment_barangays to assignmentBarangays');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), "['set_barangay_assignment', 'deactivate_barangay_assignment']") !== false, 'CSRF branch remains on set/deactivate mutation actions only');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../api/drrm/barangay-coordination.php'), "'get_assignment_barangays' => DrrmBarangayCoordinationAuthorizationService::ACTION_EDIT") !== false, 'get_assignment_barangays requires EDIT in API route');

    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'get_assignment_barangays') !== false, 'assignment dropdown uses get_assignment_barangays');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'showAssignmentFeedback(\'User reference is required.\')') !== false, 'JS still surfaces explicit empty user reference feedback only on explicit action attempt');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'function loadCatalog()') !== false, 'JS bootstrap has a catalog loader that does not emit an error on page load');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'if (target === \'\') {') !== false, 'empty user reference branch is a silent neutral return for initial load');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../assets/js/drrm/barangay-coordination.js'), 'hideAssignmentFeedback();') !== false, 'empty initial reference branch hides feedback rather than flashing a load-time error');

    assertTrue(!str_contains(json_encode($store->lastPostPayload), 'sb_secret_'), 'no Supabase service/server key exposed in API payload');

    $sidebarSource = file_get_contents(__DIR__ . '/../includes/sidebar.php');
    assertTrue(strpos($sidebarSource, '$sidebarCanViewResource') !== false, 'sidebar relies on a VIEW-resource predicate helper instead of role-name or keyword guessing');
    assertTrue(strpos($sidebarSource, 'user_permissions_map') !== false, 'sidebar reads the trusted server permission map');
    assertTrue(strpos($sidebarSource, 'Barangay Official') === false, 'sidebar does not hardcode Barangay Official role-name visibility');
    assertTrue(strpos($sidebarSource, 'DRRM Administrator') === false, 'sidebar does not hardcode DRRM Administrator role-name visibility');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmMapAuthorizationService.php'), 'fromTrustedSession') !== false, 'map server auth remains from trusted session');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmReliefGoodsAuthorizationService.php'), 'fromTrustedSession') !== false, 'relief auth remains from trusted session');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmIncidentAuthorizationService.php'), 'fromTrustedSession') !== false, 'incident auth remains from trusted session');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmEarlyWarningAuthorizationService.php'), 'fromTrustedSession') !== false, 'early warning auth remains from trusted session');
    assertTrue(strpos(file_get_contents(__DIR__ . '/../src/Services/DrrmBarangayCoordinationAuthorizationService.php'), 'fromTrustedSession') !== false, 'coordination auth remains from trusted session');

    echo 'Assertions=' . $assertions . PHP_EOL;
    echo 'PASS: Module 5 coordination service, auth, CSRF, validation, and fake-store isolation checks exercised.' . PHP_EOL;
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
