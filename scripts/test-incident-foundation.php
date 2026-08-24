<?php

declare(strict_types=1);

use App\Services\DrrmDataStoreInterface;
use App\Services\DrrmIncidentAuthorizationService;
use App\Services\DrrmIncidentCsrfService;
use App\Services\DrrmIncidentLifecycleException;
use App\Services\DrrmIncidentReadService;
use App\Services\DrrmIncidentValidationException;
use App\Services\DrrmIncidentWriteService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/DrrmIncidentAuthorizationService.php';
require_once __DIR__ . '/../src/Services/DrrmIncidentCsrfService.php';
require_once __DIR__ . '/../src/Services/DrrmIncidentReadService.php';
require_once __DIR__ . '/../src/Services/DrrmIncidentWriteService.php';

final class IncidentTestStore implements DrrmDataStoreInterface
{
    /** @var list<array{function: string, payload: array<string, mixed>}> */
    public array $rpcCalls = [];

    public function __construct(public string $status = 'SUBMITTED')
    {
    }

    public function get(string $resource, array $query = []): array
    {
        if ($resource === 'drrm_incident_types') {
            $codes = DrrmIncidentReadService::INCIDENT_TYPES;
            return array_map(static fn (string $code, int $index): array => [
                'incident_type_id' => $index + 1,
                'code' => $code,
                'label' => ucwords(strtolower(str_replace('_', ' ', $code))),
                'sort_order' => $index + 1,
            ], $codes, array_keys($codes));
        }
        if ($resource === 'drrm_incident_severities') {
            $codes = DrrmIncidentReadService::SEVERITIES;
            return array_map(static fn (string $code, int $index): array => [
                'severity_id' => $index + 1,
                'code' => $code,
                'label' => ucfirst(strtolower($code)),
                'severity_rank' => $index + 1,
            ], $codes, array_keys($codes));
        }
        if ($resource === 'drrm_incidents' && ($query['select'] ?? null) === 'id,status') {
            return [[
                'id' => '11111111-1111-4111-8111-111111111111',
                'status' => $this->status,
            ]];
        }
        if (in_array($resource, [
            'drrm_incidents', 'drrm_incident_status_history',
            'drrm_incident_assignments', 'drrm_incident_response_logs',
        ], true)) {
            return [];
        }
        throw new RuntimeException('Unexpected test resource: ' . $resource);
    }

    public function post(string $resource, array $payload, array $query = []): array
    {
        throw new RuntimeException('No incident foundation test may create an operational record.');
    }

    public function rpc(string $function, array $payload = []): array
    {
        $this->rpcCalls[] = ['function' => $function, 'payload' => $payload];
        if ($function === 'drrm_incident_summary') {
            return [
                'submitted' => 0,
                'under_review' => 0,
                'active_response' => 0,
                'resolved_today' => 0,
                'total' => 0,
            ];
        }
        if ($function === 'transition_drrm_incident') {
            $targets = [
                'REVIEW' => 'UNDER_REVIEW', 'VERIFY' => 'VERIFIED',
                'ASSIGN' => 'ASSIGNED', 'RESOLVE' => 'RESOLVED',
                'CLOSE' => 'CLOSED', 'REJECT' => 'REJECTED',
            ];
            return [
                'id' => $payload['p_incident_id'],
                'incident_number' => 'INC-2026-000001',
                'previous_status' => $this->status,
                'status' => $targets[$payload['p_action']],
            ];
        }
        if ($function === 'add_drrm_incident_response') {
            return [
                'id' => $payload['p_incident_id'],
                'incident_number' => 'INC-2026-000001',
                'previous_status' => $this->status,
                'status' => $this->status === 'ASSIGNED' ? 'RESPONDING' : 'RESPONDING',
                'action_type' => $payload['p_action_type'],
            ];
        }
        throw new RuntimeException('Unexpected test RPC: ' . $function);
    }
}

$failures = [];

function incidentAssert(string $name, bool $condition): void
{
    global $failures;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

/** @param class-string<Throwable> $exceptionClass */
function incidentAssertThrows(string $name, string $exceptionClass, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        incidentAssert($name, $exception instanceof $exceptionClass);
        return;
    }
    incidentAssert($name, false);
}

$testSessionPath = sys_get_temp_dir();
if (!is_dir($testSessionPath) || !is_writable($testSessionPath)) {
    throw new RuntimeException('A writable temporary directory is required.');
}
session_save_path($testSessionPath);
session_id('module3-foundation-' . bin2hex(random_bytes(8)));
session_start();

$_SESSION = [
    'user_id' => '42',
    'current_user_details' => ['is_superadmin' => false, 'is_global_access' => true],
    'user_permissions_map' => [DrrmIncidentAuthorizationService::RESOURCE => ['VIEW']],
];
$viewOnly = DrrmIncidentAuthorizationService::fromTrustedSession();
incidentAssert('ViewPermissionAccepted', $viewOnly->canView());
incidentAssert('UnauthorizedTransitionRejected', !$viewOnly->allowsLifecycleAction('VERIFY'));
incidentAssert('GlobalAccessDoesNotBypassModuleRbac', !$viewOnly->isSuperadmin());

$_SESSION['user_permissions_map'] = ['incident reporting' => ['VIEW', 'VERIFY_INCIDENT']];
$alias = DrrmIncidentAuthorizationService::fromTrustedSession();
incidentAssert('ResourceAliasRejected', !$alias->canView());

$_SESSION['current_user_details']['is_superadmin'] = true;
$superadmin = DrrmIncidentAuthorizationService::fromTrustedSession();
incidentAssert('SuperadminCanUseAllIncidentActions',
    $superadmin->canView()
    && $superadmin->allowsLifecycleAction('REVIEW')
    && $superadmin->allowsLifecycleAction('VERIFY')
    && $superadmin->allowsLifecycleAction('ASSIGN')
    && $superadmin->canUpdateResponse()
    && $superadmin->allowsLifecycleAction('RESOLVE')
    && $superadmin->allowsLifecycleAction('CLOSE')
    && $superadmin->allowsLifecycleAction('REJECT')
);

$csrf = new DrrmIncidentCsrfService();
$csrfToken = $csrf->token();
incidentAssert('CsrfTokenValid', strlen($csrfToken) === 43 && $csrf->validate($csrfToken));
incidentAssert('CsrfMissingRejected', !$csrf->validate(null));
incidentAssert('CsrfInvalidRejected', !$csrf->validate('invalid'));
$_SESSION['drrm_incident_csrf']['issued_at'] = time() - DrrmIncidentCsrfService::TOKEN_TTL_SECONDS;
incidentAssert('CsrfExpiredRejected', !$csrf->validate($csrfToken));

$emptyStore = new IncidentTestStore();
$readService = new DrrmIncidentReadService($emptyStore);
$summary = $readService->summary();
incidentAssert('SummaryReturnsRealZeroCounts', $summary === [
    'submitted' => 0,
    'under_review' => 0,
    'active_response' => 0,
    'resolved_today' => 0,
    'total' => 0,
]);
$list = $readService->incidents();
incidentAssert('EmptyIncidentListReturned', $list['incidents'] === [] && $list['count'] === 0);
incidentAssertThrows('MalformedFilterRejected', InvalidArgumentException::class,
    fn () => $readService->incidents(['status' => 'ACTIVE'])
);

$incidentId = '11111111-1111-4111-8111-111111111111';
$submittedStore = new IncidentTestStore('SUBMITTED');
$submittedService = new DrrmIncidentWriteService($submittedStore);
incidentAssertThrows('InvalidBackwardOrSkippedTransitionRejected', DrrmIncidentLifecycleException::class,
    fn () => $submittedService->transition([
        'incident_id' => $incidentId,
        'action' => 'VERIFY',
    ], 'USER:42')
);
incidentAssertThrows('BrowserSuppliedStatusRejected', DrrmIncidentValidationException::class,
    fn () => $submittedService->transition([
        'incident_id' => $incidentId,
        'action' => 'REVIEW',
        'status' => 'CLOSED',
    ], 'USER:42')
);
incidentAssertThrows('HtmlResponseRejected', DrrmIncidentValidationException::class,
    fn () => (new DrrmIncidentWriteService(new IncidentTestStore('RESPONDING')))->addResponse([
        'incident_id' => $incidentId,
        'action_type' => 'RESPONSE_UPDATE',
        'message' => '<b>unsafe</b>',
    ], 'USER:42')
);
incidentAssertThrows('AssignmentTargetRequired', DrrmIncidentValidationException::class,
    fn () => (new DrrmIncidentWriteService(new IncidentTestStore('VERIFIED')))->transition([
        'incident_id' => $incidentId,
        'action' => 'ASSIGN',
    ], 'USER:42')
);
incidentAssertThrows('ResolutionNoteRequired', DrrmIncidentValidationException::class,
    fn () => (new DrrmIncidentWriteService(new IncidentTestStore('RESPONDING')))->transition([
        'incident_id' => $incidentId,
        'action' => 'RESOLVE',
    ], 'USER:42')
);

$reviewResult = $submittedService->transition([
    'incident_id' => $incidentId,
    'action' => 'REVIEW',
    'notes' => 'Initial verification review started.',
], 'USER:42');
incidentAssert('ReviewTransitionAccepted', $reviewResult['status'] === 'UNDER_REVIEW');

$assignmentService = new DrrmIncidentWriteService(new IncidentTestStore('VERIFIED'));
$assignmentResult = $assignmentService->transition([
    'incident_id' => $incidentId,
    'action' => 'ASSIGN',
    'assigned_department_reference' => 'DEPARTMENT:12',
], 'USER:42');
incidentAssert('AssignmentTransitionAccepted', $assignmentResult['status'] === 'ASSIGNED');

$responseService = new DrrmIncidentWriteService(new IncidentTestStore('ASSIGNED'));
$responseResult = $responseService->addResponse([
    'incident_id' => $incidentId,
    'action_type' => 'DISPATCH_NOTE',
    'message' => 'Response team dispatched.',
], 'USER:42');
incidentAssert('DispatchStartsResponse', $responseResult['status'] === 'RESPONDING');
incidentAssert('SessionActorUsesStableReference', DrrmIncidentWriteService::actorReferenceFromSession() === 'USER:42');

$migration = file_get_contents(__DIR__ . '/../supabase/migrations/20260824000100_module3_incident_reporting_foundation.sql');
if (!is_string($migration)) {
    throw new RuntimeException('The Module 3 migration could not be read.');
}
incidentAssert('MigrationTransactionBounded', str_starts_with(ltrim($migration), '-- CIVENTRAL')
    && str_contains($migration, "begin;") && str_ends_with(rtrim($migration), 'commit;'));
incidentAssert('MigrationEnablesAllRls', substr_count($migration, ' enable row level security;') === 6);
incidentAssert('BrowserRolesCannotMutate', substr_count($migration, 'from public, anon, authenticated;') >= 12);
incidentAssert('NoFakeIncidentSeed', !str_contains($migration, 'insert into public.drrm_incidents ('));
incidentAssert('NoWarningAutomation', !str_contains($migration, 'insert into public.early_warnings'));
incidentAssert('NoTensorFlowCoupling', stripos($migration, 'tensorflow') === false);
incidentAssert('IncidentNumberUsesSequence', str_contains($migration, "nextval('public.drrm_incident_number_seq')"));

$sidebar = file_get_contents(__DIR__ . '/../includes/sidebar.php');
incidentAssert('SidebarModuleOrderPreserved', is_string($sidebar)
    && strpos($sidebar, 'Hazard & Evacuation Map System') < strpos($sidebar, 'Relief Goods Distribution Tracker')
    && strpos($sidebar, 'Relief Goods Distribution Tracker') < strpos($sidebar, 'incident-reporting-response.php')
    && strpos($sidebar, 'incident-reporting-response.php') < strpos($sidebar, 'Disaster Early Warning System')
    && strpos($sidebar, 'Disaster Early Warning System') < strpos($sidebar, 'Barangay DRRM Coordination Tool'));
$dashboardScript = file_get_contents(__DIR__ . '/../assets/js/dashboard.js');
incidentAssert('DashboardModule3ReadinessEnabled', is_string($dashboardScript)
    && str_contains($dashboardScript, 'enableIncidentModuleOnDrrmOverview')
    && str_contains($dashboardScript, 'incident-reporting-response.php')
    && str_contains($dashboardScript, "readinessStatus.textContent = 'Available'"));
incidentAssert('DashboardDoesNotEnableModules2Or5', is_string($dashboardScript)
    && !str_contains($dashboardScript, 'Relief Goods Distribution Tracker')
    && !str_contains($dashboardScript, 'Barangay DRRM Coordination Tool'));

$_SESSION = [];
session_destroy();

if ($failures !== []) {
    fwrite(STDERR, 'Module 3 foundation failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Module3Foundation=PASS\n";
