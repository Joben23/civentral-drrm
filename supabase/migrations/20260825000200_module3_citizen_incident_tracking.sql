-- CIVENTRAL DRRM Module 3: secure citizen incident tracking state.
-- This migration creates no incident or status-history records.

begin;

create table if not exists public.drrm_citizen_incident_notification_state (
    reporter_reference text primary key,
    last_seen_at timestamptz null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),

    constraint drrm_citizen_incident_notification_state_reporter_check check (
        reporter_reference ~ '^CITIZEN:[1-9][0-9]*$'
        and char_length(reporter_reference) <= 200
    ),
    constraint drrm_citizen_incident_notification_state_timestamps_check check (
        updated_at >= created_at
    )
);

comment on table public.drrm_citizen_incident_notification_state is
    'Private server-mediated watermark for citizen incident status notifications; contains no profile PII.';
comment on column public.drrm_citizen_incident_notification_state.last_seen_at is
    'Latest eligible append-only incident status-history timestamp seen by this reporter.';

create index if not exists drrm_incidents_citizen_owner_reported_idx
    on public.drrm_incidents (reporter_reference, reported_at desc)
    where reporter_type = 'CITIZEN';

create or replace function public.mark_drrm_citizen_incident_notifications_read(
    p_reporter_reference text
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $function$
declare
    eligible_last_seen_at timestamptz;
    resulting_last_seen_at timestamptz;
begin
    p_reporter_reference := btrim(coalesce(p_reporter_reference, ''));
    if p_reporter_reference !~ '^CITIZEN:[1-9][0-9]*$'
       or char_length(p_reporter_reference) > 200 then
        raise exception using
            errcode = '22023',
            message = 'A controlled citizen reporter reference is required.';
    end if;

    -- Advance only to the latest eligible event visible in this database
    -- snapshot instead of trusting a client timestamp or blindly using now().
    select max(history.changed_at)
    into eligible_last_seen_at
    from public.drrm_incident_status_history as history
    join public.drrm_incidents as incident
      on incident.id = history.incident_id
    where incident.reporter_type = 'CITIZEN'
      and incident.reporter_reference = p_reporter_reference
      and history.to_status in (
          'UNDER_REVIEW', 'VERIFIED', 'ASSIGNED', 'RESPONDING',
          'RESOLVED', 'CLOSED', 'REJECTED'
      );

    insert into public.drrm_citizen_incident_notification_state as state (
        reporter_reference, last_seen_at, created_at, updated_at
    ) values (
        p_reporter_reference, eligible_last_seen_at, now(), now()
    )
    on conflict (reporter_reference) do update
    set last_seen_at = case
            when excluded.last_seen_at is null then state.last_seen_at
            when state.last_seen_at is null then excluded.last_seen_at
            else greatest(state.last_seen_at, excluded.last_seen_at)
        end,
        updated_at = now()
    returning last_seen_at into resulting_last_seen_at;

    return jsonb_build_object('last_seen_at', resulting_last_seen_at);
end;
$function$;

alter table public.drrm_citizen_incident_notification_state enable row level security;

revoke all on table public.drrm_citizen_incident_notification_state
    from public, anon, authenticated, service_role;
grant select on table public.drrm_citizen_incident_notification_state
    to service_role;

revoke all on function public.mark_drrm_citizen_incident_notifications_read(text)
    from public, anon, authenticated;
grant execute on function public.mark_drrm_citizen_incident_notifications_read(text)
    to service_role;

create or replace function public.verify_module3_citizen_incident_tracking_schema()
returns jsonb
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    select jsonb_build_object(
        'state_table_exists',
            to_regclass('public.drrm_citizen_incident_notification_state') is not null,
        'state_rls_enabled', coalesce((
            select relrowsecurity
            from pg_catalog.pg_class
            where oid = to_regclass(
                'public.drrm_citizen_incident_notification_state'
            )
        ), false),
        'direct_client_privileges_restricted', not exists (
            select 1
            from unnest(array['anon', 'authenticated']) as target_role(role_name)
            where has_table_privilege(
                target_role.role_name,
                'public.drrm_citizen_incident_notification_state',
                'SELECT,INSERT,UPDATE,DELETE'
            )
        ),
        'service_role_access_minimal',
            has_table_privilege(
                'service_role',
                'public.drrm_citizen_incident_notification_state',
                'SELECT'
            )
            and not has_table_privilege(
                'service_role',
                'public.drrm_citizen_incident_notification_state',
                'INSERT'
            )
            and not has_table_privilege(
                'service_role',
                'public.drrm_citizen_incident_notification_state',
                'UPDATE'
            )
            and not has_table_privilege(
                'service_role',
                'public.drrm_citizen_incident_notification_state',
                'DELETE'
            ),
        'direct_client_rpc_restricted', not exists (
            select 1
            from unnest(array['anon', 'authenticated']) as target_role(role_name)
            where has_function_privilege(
                target_role.role_name,
                'public.mark_drrm_citizen_incident_notifications_read(text)',
                'EXECUTE'
            )
        ),
        'server_rpc_granted', has_function_privilege(
            'service_role',
            'public.mark_drrm_citizen_incident_notifications_read(text)',
            'EXECUTE'
        ),
        'citizen_owner_index_exists',
            to_regclass('public.drrm_incidents_citizen_owner_reported_idx') is not null,
        'state_row_count', (
            select count(*)
            from public.drrm_citizen_incident_notification_state
        )
    );
$function$;

revoke all on function public.verify_module3_citizen_incident_tracking_schema()
    from public, anon, authenticated;
grant execute on function public.verify_module3_citizen_incident_tracking_schema()
    to service_role;

notify pgrst, 'reload schema';

commit;
