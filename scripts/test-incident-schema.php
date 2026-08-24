<?php

declare(strict_types=1);

use App\Config\SupabaseConfig;
use App\Services\SupabaseRestClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';

$migrationPath = __DIR__ . '/../supabase/migrations/20260824000100_module3_incident_reporting_foundation.sql';
$migration = file_get_contents($migrationPath);
if (!is_string($migration) || $migration === '') {
    fwrite(STDERR, "Module3MigrationFile=FAIL\n");
    exit(1);
}

$requiredFragments = [
    'create table if not exists public.drrm_incident_types',
    'create table if not exists public.drrm_incident_severities',
    'create table if not exists public.drrm_incidents',
    'create table if not exists public.drrm_incident_status_history',
    'create table if not exists public.drrm_incident_assignments',
    'create table if not exists public.drrm_incident_response_logs',
    'create or replace function public.transition_drrm_incident',
    'create or replace function public.add_drrm_incident_response',
    'create or replace function public.drrm_incident_summary',
    "nextval('public.drrm_incident_number_seq')",
    'notify pgrst, \'reload schema\';',
];

foreach ($requiredFragments as $fragment) {
    if (!str_contains($migration, $fragment)) {
        fwrite(STDERR, 'MissingMigrationFragment=' . $fragment . PHP_EOL);
        exit(1);
    }
}
if (substr_count($migration, ' enable row level security;') !== 6
    || str_contains($migration, 'insert into public.drrm_incidents (')
    || str_contains($migration, 'insert into public.early_warnings')) {
    fwrite(STDERR, "Module3MigrationSecurityReview=FAIL\n");
    exit(1);
}

echo "Module3MigrationStaticReview=PASS\n";

try {
    $client = new SupabaseRestClient(SupabaseConfig::fromEnvironment(__DIR__ . '/../.env'));
    $verification = $client->rpc('verify_module3_incident_schema');
} catch (Throwable) {
    echo "Module3MigrationDeployed=NO\n";
    echo "ManualSqlExecutionRequired=YES\n";
    exit(0);
}

if (array_is_list($verification) && count($verification) === 1 && is_array($verification[0])) {
    $verification = $verification[0];
}
$requiredTrue = [
    'tables_exist', 'rls_enabled', 'direct_client_privileges_restricted',
    'incident_type_seed_valid', 'severity_seed_valid',
];
foreach ($requiredTrue as $key) {
    if (($verification[$key] ?? null) !== true) {
        fwrite(STDERR, 'Module3SchemaVerificationFailed=' . $key . PHP_EOL);
        exit(1);
    }
}
if (($verification['warning_trigger_count'] ?? null) !== 0) {
    fwrite(STDERR, "Module3WarningIsolation=FAIL\n");
    exit(1);
}

echo "Module3MigrationDeployed=YES\n";
echo 'Module3IncidentCount=' . (int) ($verification['incident_count'] ?? -1) . PHP_EOL;
echo "Module3SchemaVerification=PASS\n";
