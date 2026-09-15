begin;

alter table public.early_warnings
    add column if not exists revision integer not null default 1;

do $$
begin
    if not exists (
        select 1
        from pg_constraint
        where conname = 'early_warnings_revision_positive'
          and conrelid = 'public.early_warnings'::regclass
    ) then
        alter table public.early_warnings
            add constraint early_warnings_revision_positive check (revision > 0);
    end if;
end;
$$;

create or replace function public.create_module4_warning_draft(
    p_source_id uuid,
    p_title text,
    p_hazard_type text,
    p_warning_level_id smallint,
    p_summary text,
    p_issued_at timestamptz,
    p_valid_until timestamptz,
    p_source_reference text,
    p_areas jsonb,
    p_actor_reference text
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
    v_warning public.early_warnings%rowtype;
    v_area_count integer;
begin
    p_actor_reference := btrim(coalesce(p_actor_reference, ''));
    p_title := btrim(coalesce(p_title, ''));
    p_summary := btrim(coalesce(p_summary, ''));
    p_hazard_type := upper(btrim(coalesce(p_hazard_type, '')));
    p_source_reference := nullif(btrim(coalesce(p_source_reference, '')), '');

    perform public.module4_assert_actor_reference(p_actor_reference);
    perform public.module4_assert_warning_definition(
        p_source_id, p_title, p_hazard_type, p_warning_level_id, p_summary,
        p_issued_at, p_valid_until, p_source_reference, p_areas
    );

    insert into public.early_warnings (
        source_id,
        external_reference_id,
        title,
        hazard_type,
        warning_level_id,
        summary,
        status,
        issued_at,
        valid_until,
        source_reference,
        revision
    ) values (
        p_source_id,
        null,
        p_title,
        p_hazard_type,
        p_warning_level_id,
        p_summary,
        'DRAFT',
        p_issued_at,
        p_valid_until,
        p_source_reference,
        1
    ) returning * into v_warning;

    insert into public.early_warning_areas (warning_id, scope_type, barangay_id, area_name)
    select
        v_warning.id,
        area->>'scope_type',
        case when area->>'scope_type' = 'BARANGAY' then (area->>'barangay_id')::uuid else null end,
        case
            when area->>'scope_type' = 'BARANGAY' then barangay.name
            else btrim(area->>'area_name')
        end
      from pg_catalog.jsonb_array_elements(p_areas) area
      left join public.barangays as barangay
        on area->>'scope_type' = 'BARANGAY'
       and barangay.barangay_id = (area->>'barangay_id')::uuid;
    get diagnostics v_area_count = row_count;

    insert into public.early_warning_history (
        warning_id, event_type, actor_reference, resulting_status, resulting_revision, details
    ) values (
        v_warning.id,
        'CREATED',
        p_actor_reference,
        'DRAFT',
        v_warning.revision,
        jsonb_build_object('affected_area_count', v_area_count)
    );

    return jsonb_build_object(
        'outcome', 'CREATED',
        'id', v_warning.id,
        'title', v_warning.title,
        'status', v_warning.status,
        'revision', v_warning.revision,
        'updated_at', v_warning.updated_at,
        'affected_area_count', v_area_count
    );
end;
$$;

create or replace function public.update_module4_warning_draft(
    p_warning_id uuid,
    p_expected_revision integer,
    p_source_id uuid,
    p_title text,
    p_hazard_type text,
    p_warning_level_id smallint,
    p_summary text,
    p_issued_at timestamptz,
    p_valid_until timestamptz,
    p_source_reference text,
    p_areas jsonb,
    p_actor_reference text
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
    v_current public.early_warnings%rowtype;
    v_updated public.early_warnings%rowtype;
    v_area_count integer;
begin
    p_actor_reference := btrim(coalesce(p_actor_reference, ''));
    perform public.module4_assert_actor_reference(p_actor_reference);

    select *
      into v_current
      from public.early_warnings
     where id = p_warning_id
     for update;

    if not found then
        return jsonb_build_object('outcome', 'NOT_FOUND');
    end if;

    if v_current.status <> 'DRAFT' then
        return jsonb_build_object(
            'outcome', 'NOT_DRAFT',
            'status', v_current.status,
            'revision', v_current.revision
        );
    end if;

    if p_expected_revision is null or p_expected_revision <> v_current.revision then
        return jsonb_build_object(
            'outcome', 'REVISION_CONFLICT',
            'status', v_current.status,
            'revision', v_current.revision
        );
    end if;

    p_title := btrim(coalesce(p_title, ''));
    p_summary := btrim(coalesce(p_summary, ''));
    p_hazard_type := upper(btrim(coalesce(p_hazard_type, '')));
    p_source_reference := nullif(btrim(coalesce(p_source_reference, '')), '');

    perform public.module4_assert_warning_definition(
        p_source_id, p_title, p_hazard_type, p_warning_level_id, p_summary,
        p_issued_at, p_valid_until, p_source_reference, p_areas
    );

    update public.early_warnings
       set source_id = p_source_id,
           title = p_title,
           hazard_type = p_hazard_type,
           warning_level_id = p_warning_level_id,
           summary = p_summary,
           issued_at = p_issued_at,
           valid_until = p_valid_until,
           source_reference = p_source_reference,
           revision = revision + 1
     where id = p_warning_id
     returning * into v_updated;

    delete from public.early_warning_areas where warning_id = p_warning_id;

    insert into public.early_warning_areas (warning_id, scope_type, barangay_id, area_name)
    select
        p_warning_id,
        area->>'scope_type',
        case when area->>'scope_type' = 'BARANGAY' then (area->>'barangay_id')::uuid else null end,
        case
            when area->>'scope_type' = 'BARANGAY' then barangay.name
            else btrim(area->>'area_name')
        end
      from pg_catalog.jsonb_array_elements(p_areas) area
      left join public.barangays as barangay
        on area->>'scope_type' = 'BARANGAY'
       and barangay.barangay_id = (area->>'barangay_id')::uuid;
    get diagnostics v_area_count = row_count;

    insert into public.early_warning_history (
        warning_id, event_type, actor_reference, resulting_status, resulting_revision, details
    ) values (
        p_warning_id,
        'DRAFT_UPDATED',
        p_actor_reference,
        'DRAFT',
        v_updated.revision,
        jsonb_build_object(
            'previous_revision', v_current.revision,
            'affected_area_count', v_area_count
        )
    );

    return jsonb_build_object(
        'outcome', 'UPDATED',
        'id', v_updated.id,
        'title', v_updated.title,
        'status', v_updated.status,
        'revision', v_updated.revision,
        'updated_at', v_updated.updated_at,
        'affected_area_count', v_area_count
    );
end;
$$;

comment on column public.early_warnings.revision is
    'Monotonic optimistic-concurrency token. Incremented by Module 4 mutation RPCs.';

create table if not exists public.early_warning_history (
    id uuid primary key default pg_catalog.gen_random_uuid(),
    warning_id uuid not null,
    event_type text not null,
    actor_reference text not null,
    occurred_at timestamptz not null default pg_catalog.now(),
    resulting_status text not null,
    resulting_revision integer not null,
    details jsonb not null default '{}'::jsonb,
    constraint early_warning_history_warning_id_fkey
        foreign key (warning_id)
        references public.early_warnings(id)
        on delete restrict,
    constraint early_warning_history_event_type_check
        check (event_type in ('CREATED', 'DRAFT_UPDATED', 'ACTIVATED', 'CANCELLED')),
    constraint early_warning_history_actor_reference_check check (
        actor_reference ~ '^(USER|EMPLOYEE):[A-Za-z0-9][A-Za-z0-9._:@/-]{0,149}$'
    ),
    constraint early_warning_history_resulting_status_check check (
        resulting_status in ('DRAFT', 'ACTIVE', 'EXPIRED', 'CANCELLED', 'ARCHIVED')
    ),
    constraint early_warning_history_event_status_pair_check check (
        (event_type in ('CREATED', 'DRAFT_UPDATED') and resulting_status = 'DRAFT')
        or (event_type = 'ACTIVATED' and resulting_status = 'ACTIVE')
        or (event_type = 'CANCELLED' and resulting_status = 'CANCELLED')
    ),
    constraint early_warning_history_resulting_revision_positive
        check (resulting_revision > 0),
    constraint early_warning_history_details_object_check
        check (pg_catalog.jsonb_typeof(details) = 'object')
);

comment on table public.early_warning_history is
    'Append-only application history for persisted Module 4 warning lifecycle mutations.';
comment on column public.early_warning_history.actor_reference is
    'Trusted CIVENTRAL session identity (USER:<id> or EMPLOYEE:<id>); intentionally no cross-database FK.';

create index if not exists idx_early_warning_history_warning_time
    on public.early_warning_history (warning_id, occurred_at desc, resulting_revision desc);
create unique index if not exists uq_early_warning_history_warning_revision
    on public.early_warning_history (warning_id, resulting_revision);

create unique index if not exists uq_early_warning_areas_warning_barangay
    on public.early_warning_areas (warning_id, barangay_id)
    where scope_type = 'BARANGAY' and barangay_id is not null;

alter table public.early_warning_history enable row level security;

revoke all on table public.early_warning_history from public, anon, authenticated;
revoke insert, update, delete on table public.early_warning_history from service_role;
grant select on table public.early_warning_history to service_role;

revoke insert, update, delete on table public.early_warnings from service_role;
revoke insert, update, delete on table public.early_warning_areas from service_role;
grant select on table public.early_warnings to service_role;
grant select on table public.early_warning_areas to service_role;

create or replace function public.module4_assert_actor_reference(p_actor_reference text)
returns void
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
begin
    if p_actor_reference is null
       or p_actor_reference !~ '^(USER|EMPLOYEE):[A-Za-z0-9][A-Za-z0-9._:@/-]{0,149}$' then
        raise exception using
            errcode = '22023',
            message = 'A trusted Module 4 actor reference is required.';
    end if;
end;
$$;

create or replace function public.module4_assert_warning_definition(
    p_source_id uuid,
    p_title text,
    p_hazard_type text,
    p_warning_level_id smallint,
    p_summary text,
    p_issued_at timestamptz,
    p_valid_until timestamptz,
    p_source_reference text,
    p_areas jsonb
)
returns void
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
    v_source_code text;
    v_scope_type text;
    v_area_count integer;
begin
    if p_title is null or length(btrim(p_title)) = 0 or length(btrim(p_title)) > 180 then
        raise exception using errcode = '22023', message = 'Warning title is invalid.';
    end if;

    if p_summary is null or length(btrim(p_summary)) = 0 or length(btrim(p_summary)) > 5000 then
        raise exception using errcode = '22023', message = 'Warning summary is invalid.';
    end if;

    if p_hazard_type not in (
        'FLOOD', 'HEAVY_RAINFALL', 'TROPICAL_CYCLONE', 'LANDSLIDE',
        'EARTHQUAKE', 'VOLCANIC_ACTIVITY', 'OTHER'
    ) then
        raise exception using errcode = '22023', message = 'Hazard type is invalid.';
    end if;

    if p_issued_at is null then
        raise exception using errcode = '22023', message = 'Issued At is required.';
    end if;

    if p_valid_until is not null and p_valid_until <= p_issued_at then
        raise exception using errcode = '22023', message = 'Valid Until must be later than Issued At.';
    end if;

    if p_source_reference is not null and length(p_source_reference) > 1000 then
        raise exception using errcode = '22023', message = 'Source Reference is invalid.';
    end if;

    select source_code
      into v_source_code
      from public.early_warning_sources
     where id = p_source_id
       and is_active = true;

    if v_source_code is null or v_source_code not in ('PAGASA', 'PHIVOLCS', 'NDRRMC', 'CIVENTRAL') then
        raise exception using errcode = '22023', message = 'Warning source is invalid.';
    end if;

    if v_source_code in ('PAGASA', 'PHIVOLCS', 'NDRRMC')
       and nullif(btrim(coalesce(p_source_reference, '')), '') is null then
        raise exception using
            errcode = '22023',
            message = 'A source reference is required for official external advisories.';
    end if;

    if not exists (
        select 1
          from public.risk_levels
         where risk_level_id = p_warning_level_id
           and code in ('LOW', 'MODERATE', 'HIGH', 'CRITICAL')
           and is_active = true
    ) then
        raise exception using errcode = '22023', message = 'Warning level is invalid.';
    end if;

    if p_areas is null
       or pg_catalog.jsonb_typeof(p_areas) <> 'array'
       or pg_catalog.jsonb_array_length(p_areas) = 0 then
        raise exception using errcode = '22023', message = 'At least one affected area is required.';
    end if;

    v_area_count := pg_catalog.jsonb_array_length(p_areas);
    if v_area_count > 193 then
        raise exception using errcode = '22023', message = 'Too many affected areas were supplied.';
    end if;

    if exists (
        select 1
          from pg_catalog.jsonb_array_elements(p_areas) area
         where pg_catalog.jsonb_typeof(area) <> 'object'
            or coalesce(area->>'scope_type', '') not in ('CITY', 'BARANGAY')
    ) then
        raise exception using errcode = '22023', message = 'An affected area is invalid.';
    end if;

    select min(area->>'scope_type')
      into v_scope_type
      from pg_catalog.jsonb_array_elements(p_areas) area;

    if exists (
        select 1
          from pg_catalog.jsonb_array_elements(p_areas) area
         where area->>'scope_type' <> v_scope_type
    ) then
        raise exception using errcode = '22023', message = 'Affected-area scopes cannot be mixed.';
    end if;

    if v_scope_type = 'CITY' then
        if v_area_count <> 1
           or (p_areas->0->>'barangay_id') is not null
           or nullif(btrim(coalesce(p_areas->0->>'area_name', '')), '') is null
           or length(btrim(p_areas->0->>'area_name')) > 180
           or btrim(p_areas->0->>'area_name') <> 'Caloocan City' then
            raise exception using errcode = '22023', message = 'CITY scope is invalid.';
        end if;
    else
        if exists (
            select 1
              from pg_catalog.jsonb_array_elements(p_areas) area
             where coalesce(area->>'barangay_id', '') !~
                '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$'
        ) then
            raise exception using errcode = '22023', message = 'A barangay identifier is invalid.';
        end if;

        if (
            select pg_catalog.count(distinct area->>'barangay_id')
            from pg_catalog.jsonb_array_elements(p_areas) area
        ) <> v_area_count then
            raise exception using errcode = '22023', message = 'Duplicate barangay identifiers are not allowed.';
        end if;

        if exists (
            select 1
              from pg_catalog.jsonb_array_elements(p_areas) area
              left join public.barangays as barangay
                on barangay.barangay_id = (area->>'barangay_id')::uuid
             where barangay.barangay_id is null
                or not public.is_drrm_barangay_write_eligible(barangay.barangay_id)
                or nullif(btrim(barangay.name), '') is null
                or length(btrim(barangay.name)) > 180
        ) then
            raise exception using errcode = '22023', message = 'A barangay is not in the authoritative write catalog.';
        end if;
    end if;
end;
$$;

create or replace function public.change_module4_warning_status(
    p_warning_id uuid,
    p_expected_revision integer,
    p_action text,
    p_actor_reference text
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
    v_current public.early_warnings%rowtype;
    v_updated public.early_warnings%rowtype;
    v_target_status text;
    v_event_type text;
    v_source_code text;
begin
    p_action := upper(btrim(coalesce(p_action, '')));
    p_actor_reference := btrim(coalesce(p_actor_reference, ''));
    perform public.module4_assert_actor_reference(p_actor_reference);

    if p_action not in ('ACTIVATE', 'CANCEL') then
        raise exception using errcode = '22023', message = 'Invalid warning lifecycle action.';
    end if;

    select *
      into v_current
      from public.early_warnings
     where id = p_warning_id
     for update;

    if not found then
        return jsonb_build_object('outcome', 'NOT_FOUND');
    end if;

    if p_expected_revision is null or p_expected_revision <> v_current.revision then
        return jsonb_build_object(
            'outcome', 'REVISION_CONFLICT',
            'status', v_current.status,
            'revision', v_current.revision
        );
    end if;

    if p_action = 'ACTIVATE' then
        if v_current.status <> 'DRAFT' then
            return jsonb_build_object(
                'outcome', 'INVALID_STATUS',
                'status', v_current.status,
                'revision', v_current.revision
            );
        end if;

        if v_current.issued_at > now() then
            return jsonb_build_object('outcome', 'FUTURE_ISSUED_AT');
        end if;
        if v_current.valid_until is not null and v_current.valid_until <= v_current.issued_at then
            return jsonb_build_object('outcome', 'INVALID_VALIDITY_ORDER');
        end if;
        if v_current.valid_until is not null and v_current.valid_until <= now() then
            return jsonb_build_object('outcome', 'ALREADY_EXPIRED');
        end if;
        if nullif(btrim(v_current.title), '') is null
           or nullif(btrim(v_current.summary), '') is null
           or v_current.hazard_type not in (
                'FLOOD', 'HEAVY_RAINFALL', 'TROPICAL_CYCLONE', 'LANDSLIDE',
                'EARTHQUAKE', 'VOLCANIC_ACTIVITY', 'OTHER'
           ) then
            return jsonb_build_object('outcome', 'INCOMPLETE');
        end if;

        select source_code
          into v_source_code
          from public.early_warning_sources
         where id = v_current.source_id
           and is_active = true;

        if v_source_code is null
           or v_source_code not in ('PAGASA', 'PHIVOLCS', 'NDRRMC', 'CIVENTRAL') then
            return jsonb_build_object('outcome', 'INVALID_SOURCE');
        end if;
        if v_source_code in ('PAGASA', 'PHIVOLCS', 'NDRRMC')
           and nullif(btrim(coalesce(v_current.source_reference, '')), '') is null then
            return jsonb_build_object('outcome', 'MISSING_SOURCE_REFERENCE');
        end if;
        if not exists (
            select 1
              from public.risk_levels
             where risk_level_id = v_current.warning_level_id
               and code in ('LOW', 'MODERATE', 'HIGH', 'CRITICAL')
               and is_active = true
        ) then
            return jsonb_build_object('outcome', 'INVALID_WARNING_LEVEL');
        end if;
        if not exists (
            select 1 from public.early_warning_areas where warning_id = p_warning_id
        ) then
            return jsonb_build_object('outcome', 'NO_AFFECTED_AREA');
        end if;

        v_target_status := 'ACTIVE';
        v_event_type := 'ACTIVATED';
    else
        if v_current.status not in ('DRAFT', 'ACTIVE') then
            return jsonb_build_object(
                'outcome', 'INVALID_STATUS',
                'status', v_current.status,
                'revision', v_current.revision
            );
        end if;
        v_target_status := 'CANCELLED';
        v_event_type := 'CANCELLED';
    end if;

    update public.early_warnings
       set status = v_target_status,
           revision = revision + 1
     where id = p_warning_id
     returning * into v_updated;

    insert into public.early_warning_history (
        warning_id, event_type, actor_reference, resulting_status, resulting_revision, details
    ) values (
        p_warning_id,
        v_event_type,
        p_actor_reference,
        v_target_status,
        v_updated.revision,
        jsonb_build_object('previous_status', v_current.status)
    );

    return jsonb_build_object(
        'outcome', 'CHANGED',
        'id', v_updated.id,
        'title', v_updated.title,
        'previous_status', v_current.status,
        'status', v_updated.status,
        'revision', v_updated.revision,
        'updated_at', v_updated.updated_at
    );
end;
$$;

revoke all on function public.module4_assert_actor_reference(text)
    from public, anon, authenticated, service_role;
revoke all on function public.module4_assert_warning_definition(
    uuid, text, text, smallint, text, timestamptz, timestamptz, text, jsonb
) from public, anon, authenticated, service_role;

revoke all on function public.create_module4_warning_draft(
    uuid, text, text, smallint, text, timestamptz, timestamptz, text, jsonb, text
) from public, anon, authenticated, service_role;
grant execute on function public.create_module4_warning_draft(
    uuid, text, text, smallint, text, timestamptz, timestamptz, text, jsonb, text
) to service_role;

revoke all on function public.update_module4_warning_draft(
    uuid, integer, uuid, text, text, smallint, text, timestamptz, timestamptz, text, jsonb, text
) from public, anon, authenticated, service_role;
grant execute on function public.update_module4_warning_draft(
    uuid, integer, uuid, text, text, smallint, text, timestamptz, timestamptz, text, jsonb, text
) to service_role;

revoke all on function public.change_module4_warning_status(uuid, integer, text, text)
    from public, anon, authenticated, service_role;
grant execute on function public.change_module4_warning_status(uuid, integer, text, text)
    to service_role;

notify pgrst, 'reload schema';

commit;
