-- CIVENTRAL DRRM Module 3 Phase 8B: citizen incident submission foundation.
-- This migration creates no incident records and no warning/AI automation.

begin;

alter table public.drrm_incident_severities
    drop constraint if exists drrm_incident_severities_code_check;
alter table public.drrm_incident_severities
    drop constraint if exists drrm_incident_severities_rank_check;

alter table public.drrm_incident_severities
    add constraint drrm_incident_severities_code_check check (
        code in ('UNASSESSED', 'LOW', 'MODERATE', 'HIGH', 'CRITICAL')
    );
alter table public.drrm_incident_severities
    add constraint drrm_incident_severities_rank_check check (
        severity_rank between 0 and 4
    );

insert into public.drrm_incident_severities (code, label, severity_rank, is_active)
values ('UNASSESSED', 'Unassessed', 0, true)
on conflict (code) do update
set label = excluded.label,
    severity_rank = excluded.severity_rank,
    is_active = true;

comment on column public.drrm_incidents.severity_id is
    'Server-owned incident severity. Citizen submissions begin UNASSESSED; citizens cannot declare an official DRRM severity.';

create table if not exists public.drrm_citizen_incident_submission_receipts (
    reporter_reference text not null,
    request_id uuid not null,
    request_fingerprint text not null,
    incident_id uuid not null,
    created_at timestamptz not null default now(),

    constraint drrm_citizen_incident_submission_receipts_pkey
        primary key (reporter_reference, request_id),
    constraint drrm_citizen_incident_submission_receipts_incident_unique
        unique (incident_id),
    constraint drrm_citizen_incident_submission_receipts_incident_fkey
        foreign key (incident_id)
        references public.drrm_incidents (id)
        on delete restrict,
    constraint drrm_citizen_incident_submission_receipts_reporter_check check (
        reporter_reference ~ '^CITIZEN:[0-9]+$'
        and char_length(reporter_reference) <= 200
    ),
    constraint drrm_citizen_incident_submission_receipts_fingerprint_check check (
        request_fingerprint ~ '^[0-9a-f]{64}$'
    )
);

comment on table public.drrm_citizen_incident_submission_receipts is
    'Private server-side ownership, idempotency, duplicate-detection, and rate-limit ledger for authenticated citizen reports.';
comment on column public.drrm_citizen_incident_submission_receipts.request_id is
    'Opaque client-generated UUID scoped to one authenticated citizen; it is never used as a globally enumerable report key.';

create index if not exists drrm_citizen_incident_receipts_rate_idx
    on public.drrm_citizen_incident_submission_receipts (reporter_reference, created_at desc);
create index if not exists drrm_citizen_incident_receipts_duplicate_idx
    on public.drrm_citizen_incident_submission_receipts (
        reporter_reference, request_fingerprint, created_at desc
    );

create or replace function public.submit_drrm_citizen_incident(
    p_reporter_reference text,
    p_request_id uuid,
    p_request_fingerprint text,
    p_incident_type text,
    p_title text,
    p_description text,
    p_location_description text,
    p_barangay_id uuid default null,
    p_latitude numeric default null,
    p_longitude numeric default null
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $function$
declare
    incident_type_record public.drrm_incident_types%rowtype;
    severity_record public.drrm_incident_severities%rowtype;
    existing_incident public.drrm_incidents%rowtype;
    existing_incident_id uuid;
    existing_request_fingerprint text;
    inserted_incident public.drrm_incidents%rowtype;
    recent_count integer;
begin
    p_reporter_reference := btrim(coalesce(p_reporter_reference, ''));
    p_request_fingerprint := lower(btrim(coalesce(p_request_fingerprint, '')));
    p_incident_type := upper(btrim(coalesce(p_incident_type, '')));
    p_title := btrim(coalesce(p_title, ''));
    p_description := btrim(coalesce(p_description, ''));
    p_location_description := btrim(coalesce(p_location_description, ''));

    if p_reporter_reference !~ '^CITIZEN:[0-9]+$'
       or char_length(p_reporter_reference) > 200 then
        raise exception using errcode = '22023', message = 'A verified citizen reporter reference is required.';
    end if;
    if p_request_id is null or p_request_fingerprint !~ '^[0-9a-f]{64}$' then
        raise exception using errcode = '22023', message = 'A valid citizen submission request key is required.';
    end if;
    if char_length(p_title) < 10 or char_length(p_title) > 180
       or p_title ~ '[<>]'
       or regexp_replace(p_title, E'[\t\n\r]', '', 'g') ~ '[[:cntrl:]]' then
        raise exception using errcode = '22023', message = 'The incident title is invalid.';
    end if;
    if char_length(p_description) < 20 or char_length(p_description) > 5000
       or p_description ~ '[<>]'
       or regexp_replace(p_description, E'[\t\n\r]', '', 'g') ~ '[[:cntrl:]]' then
        raise exception using errcode = '22023', message = 'The incident description is invalid.';
    end if;
    if char_length(p_location_description) < 5 or char_length(p_location_description) > 500
       or p_location_description ~ '[<>]'
       or regexp_replace(p_location_description, E'[\t\n\r]', '', 'g') ~ '[[:cntrl:]]' then
        raise exception using errcode = '22023', message = 'The incident location is invalid.';
    end if;
    if (p_latitude is null) <> (p_longitude is null)
       or (p_latitude is not null and (p_latitude < -90 or p_latitude > 90))
       or (p_longitude is not null and (p_longitude < -180 or p_longitude > 180)) then
        raise exception using errcode = '22023', message = 'The incident coordinates are invalid.';
    end if;

    select * into incident_type_record
    from public.drrm_incident_types
    where code = p_incident_type and is_active;
    if not found then
        raise exception using errcode = '22023', message = 'The incident type is invalid.';
    end if;

    select * into severity_record
    from public.drrm_incident_severities
    where code = 'UNASSESSED' and is_active;
    if not found then
        raise exception using errcode = '55000', message = 'The unassessed incident severity is unavailable.';
    end if;

    if p_barangay_id is not null and not exists (
        select 1
        from public.barangays as barangay
        where barangay.barangay_id = p_barangay_id
          and barangay.boundary_dataset_version_id = 'b386cd54-2288-423f-9b92-2092333333c1'::uuid
          and barangay.record_status = 'INACTIVE'
          and barangay.name <> 'Barangay 176'
    ) then
        raise exception using errcode = '22023', message = 'The incident barangay reference is invalid.';
    end if;

    -- Serialize all submissions for one authenticated citizen so limits and
    -- duplicate/idempotency checks remain deterministic under concurrency.
    perform pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(p_reporter_reference, 0));

    select request_fingerprint, incident_id
    into existing_request_fingerprint, existing_incident_id
    from public.drrm_citizen_incident_submission_receipts
    where reporter_reference = p_reporter_reference
      and request_id = p_request_id;

    if found then
        if existing_request_fingerprint <> p_request_fingerprint then
            return jsonb_build_object('success', false, 'error_code', 'DUPLICATE_SUBMISSION');
        end if;

        select * into strict existing_incident
        from public.drrm_incidents
        where id = existing_incident_id;

        return jsonb_build_object(
            'success', true,
            'incident_number', existing_incident.incident_number,
            'status', 'SUBMITTED',
            'submitted_at', existing_incident.reported_at,
            'idempotent_replay', true
        );
    end if;

    if exists (
        select 1
        from public.drrm_citizen_incident_submission_receipts
        where reporter_reference = p_reporter_reference
          and request_fingerprint = p_request_fingerprint
          and created_at >= now() - interval '5 minutes'
    ) then
        return jsonb_build_object('success', false, 'error_code', 'DUPLICATE_SUBMISSION');
    end if;

    select count(*) into recent_count
    from public.drrm_citizen_incident_submission_receipts
    where reporter_reference = p_reporter_reference
      and created_at >= now() - interval '15 minutes';
    if recent_count >= 5 then
        return jsonb_build_object('success', false, 'error_code', 'RATE_LIMITED');
    end if;

    select count(*) into recent_count
    from public.drrm_citizen_incident_submission_receipts
    where reporter_reference = p_reporter_reference
      and created_at >= now() - interval '24 hours';
    if recent_count >= 20 then
        return jsonb_build_object('success', false, 'error_code', 'RATE_LIMITED');
    end if;

    insert into public.drrm_incidents (
        incident_type_id,
        title,
        description,
        severity_id,
        reporter_type,
        reporter_reference,
        barangay_id,
        location_description,
        latitude,
        longitude,
        source,
        created_by_reference
    ) values (
        incident_type_record.incident_type_id,
        p_title,
        p_description,
        severity_record.severity_id,
        'CITIZEN',
        p_reporter_reference,
        p_barangay_id,
        p_location_description,
        p_latitude,
        p_longitude,
        'CITIZEN_MOBILE',
        p_reporter_reference
    ) returning * into inserted_incident;

    insert into public.drrm_citizen_incident_submission_receipts (
        reporter_reference, request_id, request_fingerprint, incident_id
    ) values (
        p_reporter_reference, p_request_id, p_request_fingerprint, inserted_incident.id
    );

    -- Phase 8A's after-insert trigger records the one initial SUBMITTED history
    -- row in this same transaction. No assignment/response/warning row is made.
    return jsonb_build_object(
        'success', true,
        'incident_number', inserted_incident.incident_number,
        'status', inserted_incident.status,
        'submitted_at', inserted_incident.reported_at,
        'idempotent_replay', false
    );
end;
$function$;

alter table public.drrm_citizen_incident_submission_receipts enable row level security;

revoke all on table public.drrm_citizen_incident_submission_receipts from public, anon, authenticated;
revoke all on function public.submit_drrm_citizen_incident(
    text, uuid, text, text, text, text, text, uuid, numeric, numeric
) from public, anon, authenticated;
grant execute on function public.submit_drrm_citizen_incident(
    text, uuid, text, text, text, text, text, uuid, numeric, numeric
) to service_role;

create or replace function public.verify_module3_citizen_submission_schema()
returns jsonb
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    select jsonb_build_object(
        'receipt_table_exists', to_regclass('public.drrm_citizen_incident_submission_receipts') is not null,
        'receipt_rls_enabled', coalesce((
            select relrowsecurity
            from pg_catalog.pg_class
            where oid = to_regclass('public.drrm_citizen_incident_submission_receipts')
        ), false),
        'direct_client_privileges_restricted', not exists (
            select 1
            from unnest(array['anon', 'authenticated']) as target_role(role_name)
            where has_table_privilege(
                target_role.role_name,
                'public.drrm_citizen_incident_submission_receipts',
                'SELECT,INSERT,UPDATE,DELETE'
            )
        ),
        'direct_client_rpc_restricted', not exists (
            select 1
            from unnest(array['anon', 'authenticated']) as target_role(role_name)
            where has_function_privilege(
                target_role.role_name,
                'public.submit_drrm_citizen_incident(text,uuid,text,text,text,text,text,uuid,numeric,numeric)',
                'EXECUTE'
            )
        ),
        'unassessed_severity_valid', (
            select count(*) = 1
            from public.drrm_incident_severities
            where code = 'UNASSESSED' and label = 'Unassessed'
              and severity_rank = 0 and is_active
        ),
        'incident_count', (select count(*) from public.drrm_incidents),
        'receipt_count', (select count(*) from public.drrm_citizen_incident_submission_receipts),
        'warning_trigger_count', (
            select count(*)
            from pg_catalog.pg_trigger as trigger_record
            join pg_catalog.pg_class as table_record on table_record.oid = trigger_record.tgrelid
            where table_record.relname in ('drrm_incidents', 'drrm_citizen_incident_submission_receipts')
              and pg_catalog.pg_get_triggerdef(trigger_record.oid) ilike '%early_warning%'
              and not trigger_record.tgisinternal
        )
    );
$function$;

revoke all on function public.verify_module3_citizen_submission_schema() from public, anon, authenticated;
grant execute on function public.verify_module3_citizen_submission_schema() to service_role;

notify pgrst, 'reload schema';

commit;
