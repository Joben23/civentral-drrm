<?php

declare(strict_types=1);

use App\Services\CitizenIdentity;
use App\Services\DrrmCaloocanBoundaryService;
use App\Services\DrrmCitizenIncidentSubmissionException;
use App\Services\DrrmCitizenIncidentSubmissionService;
use App\Services\DrrmDataStoreInterface;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/CitizenSessionIdentityVerifier.php';
require_once __DIR__ . '/../src/Services/DrrmCaloocanBoundaryService.php';
require_once __DIR__ . '/../src/Services/DrrmCitizenIncidentSubmissionService.php';

final class CitizenIncidentTestStore implements DrrmDataStoreInterface
{
    public array $rpcCalls = [];
    public int $postCalls = 0;
    public function __construct(public string $mode = 'success') {}
    public function get(string $resource, array $query = []): array
    {
        if ($resource !== 'barangays') { throw new RuntimeException('Unexpected test resource.'); }
        return ($query['barangay_id'] ?? null) === 'eq.aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' ? [[
            'barangay_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'name' => 'Barangay 1',
            'boundary_dataset_version_id' => DrrmCitizenIncidentSubmissionService::BARANGAY_DATASET_VERSION_ID,
            'record_status' => 'INACTIVE',
        ]] : [];
    }
    public function post(string $resource, array $payload, array $query = []): array
    {
        $this->postCalls++;
        throw new RuntimeException('Direct table mutation is forbidden.');
    }
    public function rpc(string $function, array $payload = []): array
    {
        $this->rpcCalls[] = ['function' => $function, 'payload' => $payload];
        return match ($this->mode) {
            'rate' => ['success' => false, 'error_code' => 'RATE_LIMITED'],
            'duplicate' => ['success' => false, 'error_code' => 'DUPLICATE_SUBMISSION'],
            'unavailable' => throw new RuntimeException('Simulated outage.'),
            default => [
                'success' => true, 'incident_number' => 'INC-2026-000321',
                'status' => 'SUBMITTED', 'submitted_at' => '2026-08-25T06:30:00+00:00',
                'idempotent_replay' => $this->mode === 'replay',
            ],
        };
    }
}

$failures = [];
function cia(string $name, bool $condition): void
{
    global $failures;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) { $failures[] = $name; }
}
function cie(string $name, DrrmCitizenIncidentSubmissionService $service, CitizenIdentity $identity, array $input, string $code): void
{
    try { $service->submit($input, $identity); }
    catch (DrrmCitizenIncidentSubmissionException $exception) {
        cia($name, $exception->errorCode === $code); return;
    }
    cia($name, false);
}

$boundary = new DrrmCaloocanBoundaryService(__DIR__ . '/../data/import/caloocan-city-boundary.geojson');
$store = new CitizenIncidentTestStore();
$service = new DrrmCitizenIncidentSubmissionService($store, $boundary);
$identity = new CitizenIdentity(42);
$valid = [
    'request_id' => '11111111-1111-4111-8111-111111111111',
    'incident_type' => 'FLOOD',
    'title' => 'Floodwater rising on Samson Road',
    'description' => 'Floodwater is rising near the intersection and is affecting vehicle access.',
    'barangay_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'location_description' => 'Samson Road near the public market',
    'latitude' => 14.746655, 'longitude' => 121.043414,
];

cia('ReviewedBoundaryAcceptsCaloocanCenter', $boundary->contains(14.746655, 121.043414));
cia('ReviewedBoundaryRejectsOutsidePoint', !$boundary->contains(14.5995, 120.9842));
$result = $service->submit($valid, $identity);
cia('AuthenticatedValidSubmissionAccepted', $result['incident_number'] === 'INC-2026-000321' && $result['status'] === 'SUBMITTED');
$call = $store->rpcCalls[0] ?? [];
cia('AtomicCitizenRpcUsed', ($call['function'] ?? null) === 'submit_drrm_citizen_incident');
$rpc = $call['payload'] ?? [];
cia('ReporterReferenceComesFromVerifiedIdentity', ($rpc['p_reporter_reference'] ?? null) === 'CITIZEN:42');
cia('CitizenRpcPayloadHasNoPrivilegedFields', array_intersect(array_keys($rpc), [
    'status', 'source', 'severity', 'verification_status', 'assigned_user_reference',
]) === []);
cia('NoDirectSupabaseTableMutation', $store->postCalls === 0);

foreach ([
    'CitizenSuppliedStatusRejected' => ['status', 'VERIFIED'],
    'CitizenSuppliedAssignmentRejected' => ['assigned_user_reference', 'USER:1'],
    'CitizenSuppliedSeverityRejected' => ['severity', 'CRITICAL'],
    'CitizenSuppliedProbabilityRejected' => ['model_probability', 0.99],
    'CitizenSuppliedRiskRejected' => ['risk_level', 'CRITICAL'],
    'CitizenSuppliedWarningRejected' => ['warning_id', '11111111-1111-4111-8111-111111111111'],
    'CitizenSuppliedIdentityRejected' => ['citizen_user_id', 999],
] as $name => [$field, $value]) { cie($name, $service, $identity, $valid + [$field => $value], 'INVALID_REQUEST'); }

cie('InvalidIncidentTypeRejected', $service, $identity, array_replace($valid, ['incident_type' => 'TSUNAMI']), 'INVALID_INCIDENT_TYPE');
cie('InvalidBarangayRejected', $service, $identity, array_replace($valid, ['barangay_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']), 'INVALID_BARANGAY');
cie('CoordinatePairRequired', $service, $identity, array_diff_key($valid, ['longitude' => true]), 'INVALID_COORDINATES');
cie('CoordinateStringsRejected', $service, $identity, array_replace($valid, ['latitude' => '14.746655']), 'INVALID_COORDINATES');
cie('OutOfRangeCoordinatesRejected', $service, $identity, array_replace($valid, ['latitude' => 91]), 'INVALID_COORDINATES');
cie('OutsideCaloocanCoordinatesRejected', $service, $identity, array_replace($valid, ['latitude' => 14.5995, 'longitude' => 120.9842]), 'INVALID_LOCATION');
cie('HtmlInputRejected', $service, $identity, array_replace($valid, ['description' => '<b>Untrusted incident description text</b>']), 'INVALID_REQUEST');
cie('RateLimitResultNormalized', new DrrmCitizenIncidentSubmissionService(new CitizenIncidentTestStore('rate'), $boundary), $identity, $valid, 'RATE_LIMITED');
cie('DuplicateResultNormalized', new DrrmCitizenIncidentSubmissionService(new CitizenIncidentTestStore('duplicate'), $boundary), $identity, $valid, 'DUPLICATE_SUBMISSION');
cie('DataFailureFailsClosed', new DrrmCitizenIncidentSubmissionService(new CitizenIncidentTestStore('unavailable'), $boundary), $identity, $valid, 'INCIDENT_SERVICE_UNAVAILABLE');

$migration = file_get_contents(__DIR__ . '/../supabase/migrations/20260825000100_module3_citizen_submission_foundation.sql');
$phase8a = file_get_contents(__DIR__ . '/../supabase/migrations/20260824000100_module3_incident_reporting_foundation.sql');
cia('CitizenMigrationTransactionBounded', is_string($migration) && str_contains($migration, 'begin;') && str_ends_with(rtrim($migration), 'commit;'));
cia('SourceForcedByDatabase', is_string($migration) && str_contains($migration, "'CITIZEN_MOBILE'"));
cia('UnassessedSeverityForcedByDatabase', is_string($migration) && str_contains($migration, "code = 'UNASSESSED'"));
cia('RateAndDuplicateControlsPresent', is_string($migration) && str_contains($migration, "interval '15 minutes'") && str_contains($migration, 'DUPLICATE_SUBMISSION'));
cia('ReceiptRlsAndBrowserRestrictionPresent', is_string($migration) && str_contains($migration, 'enable row level security') && str_contains($migration, 'from public, anon, authenticated'));
cia('InitialHistoryProvidedByPhase8ATrigger', is_string($phase8a) && str_contains($phase8a, 'drrm_incidents_record_submission'));
cia('NoAutomaticAssignmentOrResponseInsert', is_string($migration) && !str_contains($migration, 'insert into public.drrm_incident_assignments') && !str_contains($migration, 'insert into public.drrm_incident_response_logs'));
cia('NoWarningAutomation', is_string($migration) && !str_contains($migration, 'insert into public.early_warnings'));
cia('NoTensorFlowCoupling', is_string($migration) && stripos($migration, 'tensorflow') === false && !str_contains($migration, 'ml/flood-risk/data'));

if ($failures !== []) { fwrite(STDERR, 'Failures: ' . implode(', ', $failures) . PHP_EOL); exit(1); }
echo "CitizenIncidentSubmissionFoundation=PASS\n";
