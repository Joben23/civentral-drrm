<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function notificationContract(string $file): string
{
    $contents = file_get_contents(__DIR__ . '/../' . $file);
    if (!is_string($contents)) throw new RuntimeException('Missing notification contract file.');
    return $contents;
}

function notificationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$sql = notificationContract('supabase/migrations/20260918000100_module4_phase4c1_warning_notification_reads.sql');
foreach (['references public.early_warning_history(id)', 'on delete restrict',
    'unique (activation_history_id, citizen_reference)', 'enable row level security',
    'security definer', 'set search_path = pg_catalog, public',
    'on conflict (activation_history_id, citizen_reference) do nothing',
    'verify_module4_warning_notification_reads_schema'] as $rule) {
    notificationAssert(str_contains($sql, $rule), 'Missing migration rule: ' . $rule);
}
foreach (['constraint_metadata.conkey = array[', 'constraint_metadata.confkey = array[',
    'constraint_metadata.conrelid = target.oid',
    'constraint_metadata.confrelid = ',
    'constraint_metadata.confdeltype = ', 'constraint_metadata.convalidated',
    'index_metadata.indnkeyatts = 2',
    'index_metadata.indnatts = 2', 'index_metadata.indisready',
    'index_metadata.indpred is null',
    'pg_catalog.pg_get_indexdef(index_metadata.indexrelid, 1, true)',
    'pg_catalog.pg_get_indexdef(index_metadata.indexrelid, 2, true)',
    'id_default_valid', 'read_at_default_valid',
    'gen_random_uuid()', 'clock_timestamp()',
    'pg_catalog.pg_get_expr(constraint_metadata.conbin, constraint_metadata.conrelid)',
    'CITIZEN:[1-9][0-9]*$', 'char_length(citizen_reference) <= 200',
    'pg_catalog.regexp_replace('] as $rule) {
    notificationAssert(str_contains($sql, $rule), 'Verifier does not attest: ' . $rule);
}
notificationAssert(!str_contains($sql, 'like ' . chr(39) . '%CITIZEN:%'),
    'A loose citizen-reference substring check remains.');
$service = notificationContract('src/Services/DrrmCitizenWarningNotificationService.php');
notificationAssert(str_contains($service, 'activeWarnings($asOf)'), 'Public eligibility is not reused.');
notificationAssert(!str_contains($service, 'change_module4_warning_status'), 'Notification path changes warning status.');
$readiness = notificationContract('api/drrm/early-warning-notification-readiness.php');
notificationAssert(str_contains($readiness, '->canView()'), 'Admin readiness lacks VIEW authorization.');
foreach (['grant select on table public.module4_warning_notification_reads to service_role',
    'revoke all on table public.module4_warning_notification_reads',
    'grant execute on function public.mark_module4_warning_notification_read(uuid, text)',
    'revoke all on function public.mark_module4_warning_notification_read(uuid, text)'] as $rule) {
    notificationAssert(str_contains($sql, $rule), 'Missing privilege rule: ' . $rule);
}
$feedEndpoint = notificationContract('api/citizen/drrm/warning-notifications.php');
$readEndpoint = notificationContract('api/citizen/drrm/warning-notifications-read.php');
notificationAssert(str_contains($feedEndpoint, 'drrmCitizenTrackingInitialize(' . chr(39) . 'GET' . chr(39) . ')'), 'Feed is not GET-only.');
notificationAssert(str_contains($readEndpoint, 'drrmCitizenTrackingInitialize(' . chr(39) . 'POST' . chr(39) . ')'), 'Read endpoint is not POST-only.');
notificationAssert(str_contains($readEndpoint, 'drrmCitizenTrackingIdentity($config)'), 'Read endpoint lacks trusted citizen identity.');

echo 'Module 4 notification security contract: OK' . PHP_EOL;
