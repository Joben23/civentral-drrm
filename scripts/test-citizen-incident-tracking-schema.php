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

$migrationPath = __DIR__
    . '/../supabase/migrations/20260825000200_module3_citizen_incident_tracking.sql';
$migration = file_get_contents($migrationPath);
if (!is_string($migration) || $migration === '') {
    fwrite(STDERR, 'CitizenIncidentTrackingMigrationFile=FAIL' . PHP_EOL);
    exit(1);
}

$requiredFragments = [
    'create table if not exists public.drrm_citizen_incident_notification_state',
    'reporter_reference text primary key',
    'last_seen_at timestamptz null',
    'alter table public.drrm_citizen_incident_notification_state enable row level security',
    'from public, anon, authenticated',
    'grant select on table public.drrm_citizen_incident_notification_state',
    'create or replace function public.mark_drrm_citizen_incident_notifications_read',
    'select max(history.changed_at)',
    'incident.reporter_reference = p_reporter_reference',
    'on conflict (reporter_reference) do update',
    'create or replace function public.verify_module3_citizen_incident_tracking_schema',
    'notify pgrst, \'reload schema\';',
];
foreach ($requiredFragments as $fragment) {
    if (!str_contains($migration, $fragment)) {
        fwrite(STDERR, 'MissingTrackingMigrationFragment=' . $fragment . PHP_EOL);
        exit(1);
    }
}

$isolated = !str_contains(
    $migration,
    'create table if not exists public.drrm_incident_status_history'
) && !str_contains($migration, 'insert into public.drrm_incidents')
    && !str_contains($migration, 'insert into public.drrm_incident_status_history')
    && !str_contains($migration, 'insert into public.early_warnings')
    && !str_contains($migration, 'alter table public.early_warnings')
    && stripos($migration, 'tensorflow') === false
    && !str_contains($migration, 'ml/flood-risk/data');
if (!$isolated || !str_starts_with(ltrim($migration), '-- CIVENTRAL DRRM Module 3')
    || !str_contains($migration, 'begin;')
    || !str_ends_with(rtrim($migration), 'commit;')) {
    fwrite(STDERR, 'CitizenIncidentTrackingMigrationIsolation=FAIL' . PHP_EOL);
    exit(1);
}

echo 'CitizenIncidentTrackingMigrationStaticReview=PASS' . PHP_EOL;

try {
    $client = new SupabaseRestClient(
        SupabaseConfig::fromEnvironment(__DIR__ . '/../.env')
    );
    $verification = $client->rpc(
        'verify_module3_citizen_incident_tracking_schema'
    );
} catch (Throwable) {
    echo 'CitizenIncidentTrackingMigrationDeployed=NO' . PHP_EOL;
    echo 'ManualSqlExecutionRequired=YES' . PHP_EOL;
    exit(0);
}

if (array_is_list($verification) && count($verification) === 1
    && is_array($verification[0])) {
    $verification = $verification[0];
}
foreach ([
    'state_table_exists',
    'state_rls_enabled',
    'direct_client_privileges_restricted',
    'service_role_access_minimal',
    'direct_client_rpc_restricted',
    'server_rpc_granted',
    'citizen_owner_index_exists',
] as $key) {
    if (($verification[$key] ?? null) !== true) {
        fwrite(STDERR, 'CitizenTrackingSchemaVerificationFailed=' . $key . PHP_EOL);
        exit(1);
    }
}

echo 'CitizenIncidentTrackingMigrationDeployed=YES' . PHP_EOL;
echo 'CitizenNotificationStateRowCount='
    . (int) ($verification['state_row_count'] ?? -1)
    . PHP_EOL;
echo 'CitizenIncidentTrackingSchemaVerification=PASS' . PHP_EOL;
