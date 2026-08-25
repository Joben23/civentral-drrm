<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\SupabaseRestClient;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';

$migration = file_get_contents(__DIR__ . '/../supabase/migrations/20260825000100_module3_citizen_submission_foundation.sql');
if (!is_string($migration) || $migration === '') { fwrite(STDERR, "CitizenIncidentMigrationFile=FAIL\n"); exit(1); }
foreach ([
    'create table if not exists public.drrm_citizen_incident_submission_receipts',
    'create or replace function public.submit_drrm_citizen_incident',
    "'CITIZEN_MOBILE'", "where code = 'UNASSESSED' and is_active",
    'alter table public.drrm_citizen_incident_submission_receipts enable row level security',
    'notify pgrst, \'reload schema\';',
] as $fragment) {
    if (!str_contains($migration, $fragment)) { fwrite(STDERR, 'MissingFragment=' . $fragment . PHP_EOL); exit(1); }
}
if (str_contains($migration, 'insert into public.early_warnings')
    || stripos($migration, 'tensorflow') !== false || str_contains($migration, 'ml/flood-risk/data')) {
    fwrite(STDERR, "CitizenIncidentMigrationIsolation=FAIL\n"); exit(1);
}
echo "CitizenIncidentMigrationStaticReview=PASS\n";

try {
    $client = new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../.env'));
    $verification = $client->rpc('verify_module3_citizen_submission_schema');
} catch (Throwable) {
    echo "CitizenIncidentMigrationDeployed=NO\n";
    echo "ManualSqlExecutionRequired=YES\n";
    exit(0);
}
if (array_is_list($verification) && count($verification) === 1 && is_array($verification[0])) { $verification = $verification[0]; }
foreach (['receipt_table_exists', 'receipt_rls_enabled', 'direct_client_privileges_restricted',
    'direct_client_rpc_restricted', 'unassessed_severity_valid'] as $key) {
    if (($verification[$key] ?? null) !== true) { fwrite(STDERR, 'SchemaVerificationFailed=' . $key . PHP_EOL); exit(1); }
}
if (($verification['warning_trigger_count'] ?? null) !== 0) { fwrite(STDERR, "CitizenIncidentWarningIsolation=FAIL\n"); exit(1); }
echo "CitizenIncidentMigrationDeployed=YES\n";
echo 'CitizenIncidentCount=' . (int) ($verification['incident_count'] ?? -1) . PHP_EOL;
echo 'CitizenSubmissionReceiptCount=' . (int) ($verification['receipt_count'] ?? -1) . PHP_EOL;
echo "CitizenIncidentSchemaVerification=PASS\n";
