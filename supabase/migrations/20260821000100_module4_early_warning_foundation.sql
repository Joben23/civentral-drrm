-- CIVENTRAL DRRM Module 4: Disaster Early Warning System
-- Phase 2 database foundation. This migration creates no warning or area data.

begin;

create table if not exists public.early_warning_sources (
    id uuid primary key default gen_random_uuid(),
    source_code text not null,
    source_name text not null,
    source_type text not null,
    integration_status text not null default 'PENDING',
    is_active boolean not null default true,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),

    constraint early_warning_sources_source_code_unique unique (source_code),
    constraint early_warning_sources_source_code_format check (
        source_code = upper(source_code)
        and source_code ~ '^[A-Z][A-Z0-9_]*$'
    ),
    constraint early_warning_sources_source_name_required check (btrim(source_name) <> ''),
    constraint early_warning_sources_source_type_check check (
        source_type in ('GOVERNMENT_AGENCY', 'INTERNAL_SYSTEM')
    ),
    constraint early_warning_sources_integration_status_check check (
        integration_status in ('PENDING', 'CONNECTED', 'DISABLED')
    )
);

create table if not exists public.early_warnings (
    id uuid primary key default gen_random_uuid(),
    source_id uuid not null,
    external_reference_id text null,
    title text not null,
    hazard_type text not null,
    warning_level_id smallint not null,
    summary text not null,
    status text not null default 'DRAFT',
    issued_at timestamptz not null,
    valid_until timestamptz null,
    source_reference text null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),

    constraint early_warnings_source_id_fkey
        foreign key (source_id)
        references public.early_warning_sources (id)
        on delete restrict,
    constraint early_warnings_warning_level_id_fkey
        foreign key (warning_level_id)
        references public.risk_levels (risk_level_id)
        on delete restrict,
    constraint early_warnings_title_required check (btrim(title) <> ''),
    constraint early_warnings_hazard_type_format check (
        hazard_type = upper(hazard_type)
        and hazard_type ~ '^[A-Z][A-Z0-9_]*$'
    ),
    constraint early_warnings_summary_required check (btrim(summary) <> ''),
    constraint early_warnings_status_check check (
        status in ('DRAFT', 'ACTIVE', 'EXPIRED', 'CANCELLED', 'ARCHIVED')
    ),
    constraint early_warnings_valid_range check (
        valid_until is null or valid_until >= issued_at
    )
);

create table if not exists public.early_warning_areas (
    id uuid primary key default gen_random_uuid(),
    warning_id uuid not null,
    scope_type text not null,
    barangay_id uuid null,
    area_name text not null,
    created_at timestamptz not null default now(),

    constraint early_warning_areas_warning_id_fkey
        foreign key (warning_id)
        references public.early_warnings (id)
        on delete cascade,
    constraint early_warning_areas_barangay_id_fkey
        foreign key (barangay_id)
        references public.barangays (barangay_id)
        on delete restrict,
    constraint early_warning_areas_area_name_required check (btrim(area_name) <> ''),
    constraint early_warning_areas_scope_type_check check (
        scope_type in ('CITY', 'BARANGAY', 'CUSTOM')
    ),
    constraint early_warning_areas_scope_assignment_check check (
        (scope_type = 'CITY' and barangay_id is null)
        or (scope_type = 'BARANGAY' and barangay_id is not null)
        or scope_type = 'CUSTOM'
    )
);

comment on table public.early_warning_sources is
    'Controlled identities for official advisory agencies and CIVENTRAL-generated warnings.';
comment on column public.early_warning_sources.integration_status is
    'Integration readiness only; it does not state that an advisory is active.';
comment on table public.early_warnings is
    'Early-warning records for Caloocan City. Phase 2 intentionally seeds no records.';
comment on column public.early_warnings.hazard_type is
    'Normalized CIVENTRAL hazard code kept as text because Module 1 hazard_types is intentionally map-specific.';
comment on column public.early_warnings.warning_level_id is
    'CIVENTRAL decision-support classification; not an agency-authored PAGASA, PHIVOLCS, or NDRRMC scale.';
comment on table public.early_warning_areas is
    'City, barangay, or custom named areas affected by one early warning; no geometry is stored in Phase 2.';

create unique index if not exists early_warnings_source_external_reference_uidx
    on public.early_warnings (source_id, external_reference_id)
    where external_reference_id is not null;
create index if not exists early_warnings_status_idx
    on public.early_warnings (status);
create index if not exists early_warnings_warning_level_id_idx
    on public.early_warnings (warning_level_id);
create index if not exists early_warnings_source_id_idx
    on public.early_warnings (source_id);
create index if not exists early_warnings_issued_at_idx
    on public.early_warnings (issued_at desc);
create index if not exists early_warnings_valid_until_idx
    on public.early_warnings (valid_until)
    where valid_until is not null;
create index if not exists early_warnings_hazard_type_idx
    on public.early_warnings (hazard_type);

create index if not exists early_warning_areas_warning_id_idx
    on public.early_warning_areas (warning_id);
create index if not exists early_warning_areas_barangay_id_idx
    on public.early_warning_areas (barangay_id)
    where barangay_id is not null;
create index if not exists early_warning_areas_scope_type_idx
    on public.early_warning_areas (scope_type);

create or replace function public.set_module4_updated_at()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $function$
begin
    new.updated_at = now();
    return new;
end;
$function$;

drop trigger if exists early_warning_sources_set_updated_at on public.early_warning_sources;
create trigger early_warning_sources_set_updated_at
before update on public.early_warning_sources
for each row execute function public.set_module4_updated_at();

drop trigger if exists early_warnings_set_updated_at on public.early_warnings;
create trigger early_warnings_set_updated_at
before update on public.early_warnings
for each row execute function public.set_module4_updated_at();

alter table public.early_warning_sources enable row level security;
alter table public.early_warnings enable row level security;
alter table public.early_warning_areas enable row level security;

revoke all on table public.early_warning_sources from public, anon, authenticated;
revoke all on table public.early_warnings from public, anon, authenticated;
revoke all on table public.early_warning_areas from public, anon, authenticated;

grant select, insert, update, delete on table public.early_warning_sources to service_role;
grant select, insert, update, delete on table public.early_warnings to service_role;
grant select, insert, update, delete on table public.early_warning_areas to service_role;

revoke all on function public.set_module4_updated_at() from public, anon, authenticated;
grant execute on function public.set_module4_updated_at() to service_role;

insert into public.early_warning_sources (
    source_code,
    source_name,
    source_type,
    integration_status,
    is_active
)
values
    ('PAGASA', 'DOST-PAGASA', 'GOVERNMENT_AGENCY', 'PENDING', true),
    ('PHIVOLCS', 'DOST-PHIVOLCS', 'GOVERNMENT_AGENCY', 'PENDING', true),
    ('NDRRMC', 'National Disaster Risk Reduction and Management Council', 'GOVERNMENT_AGENCY', 'PENDING', true),
    ('CIVENTRAL', 'CIVENTRAL DRRM', 'INTERNAL_SYSTEM', 'PENDING', true)
on conflict (source_code) do nothing;

-- This restricted RPC lets the CLI verification script inspect catalog state
-- without exposing arbitrary SQL or database internals to browser roles.
create or replace function public.verify_module4_early_warning_schema()
returns jsonb
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    with target_tables as (
        select c.relname, c.relrowsecurity
        from pg_catalog.pg_class as c
        join pg_catalog.pg_namespace as n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relkind = 'r'
          and c.relname in (
              'early_warning_sources',
              'early_warnings',
              'early_warning_areas'
          )
    ),
    expected_foreign_keys as (
        select unnest(array[
            'early_warnings_source_id_fkey',
            'early_warnings_warning_level_id_fkey',
            'early_warning_areas_warning_id_fkey',
            'early_warning_areas_barangay_id_fkey'
        ]) as constraint_name
    ),
    expected_checks as (
        select unnest(array[
            'early_warning_sources_source_code_format',
            'early_warning_sources_source_name_required',
            'early_warning_sources_source_type_check',
            'early_warning_sources_integration_status_check',
            'early_warnings_title_required',
            'early_warnings_hazard_type_format',
            'early_warnings_summary_required',
            'early_warnings_status_check',
            'early_warnings_valid_range',
            'early_warning_areas_area_name_required',
            'early_warning_areas_scope_type_check',
            'early_warning_areas_scope_assignment_check'
        ]) as constraint_name
    ),
    expected_indexes as (
        select unnest(array[
            'early_warnings_source_external_reference_uidx',
            'early_warnings_status_idx',
            'early_warnings_warning_level_id_idx',
            'early_warnings_source_id_idx',
            'early_warnings_issued_at_idx',
            'early_warnings_valid_until_idx',
            'early_warnings_hazard_type_idx',
            'early_warning_areas_warning_id_idx',
            'early_warning_areas_barangay_id_idx',
            'early_warning_areas_scope_type_idx'
        ]) as index_name
    )
    select jsonb_build_object(
        'tables_exist', (select count(*) = 3 from target_tables),
        'rls_enabled', (
            select count(*) = 3 and bool_and(relrowsecurity)
            from target_tables
        ),
        'foreign_keys_valid', (
            select count(*) = 4
            from expected_foreign_keys as expected
            join pg_catalog.pg_constraint as actual
              on actual.conname = expected.constraint_name
             and actual.contype = 'f'
             and actual.convalidated
        ),
        'check_constraints_valid', (
            select count(*) = 12
            from expected_checks as expected
            join pg_catalog.pg_constraint as actual
              on actual.conname = expected.constraint_name
             and actual.contype = 'c'
             and actual.convalidated
        ),
        'unique_source_code_valid', exists (
            select 1
            from pg_catalog.pg_constraint
            where conname = 'early_warning_sources_source_code_unique'
              and contype = 'u'
              and convalidated
        ),
        'warning_status_check_valid', exists (
            select 1
            from pg_catalog.pg_constraint
            where conname = 'early_warnings_status_check'
              and contype = 'c'
              and convalidated
        ),
        'area_scope_type_check_valid', exists (
            select 1
            from pg_catalog.pg_constraint
            where conname = 'early_warning_areas_scope_type_check'
              and contype = 'c'
              and convalidated
        ),
        'indexes_valid', (
            select count(*) = 10
            from expected_indexes as expected
            join pg_catalog.pg_indexes as actual
              on actual.schemaname = 'public'
             and actual.indexname = expected.index_name
        ),
        'direct_client_privileges_restricted', not (
            has_table_privilege('anon', 'public.early_warning_sources', 'SELECT')
            or has_table_privilege('anon', 'public.early_warning_sources', 'INSERT')
            or has_table_privilege('anon', 'public.early_warning_sources', 'UPDATE')
            or has_table_privilege('anon', 'public.early_warning_sources', 'DELETE')
            or has_table_privilege('authenticated', 'public.early_warning_sources', 'SELECT')
            or has_table_privilege('authenticated', 'public.early_warning_sources', 'INSERT')
            or has_table_privilege('authenticated', 'public.early_warning_sources', 'UPDATE')
            or has_table_privilege('authenticated', 'public.early_warning_sources', 'DELETE')
            or has_table_privilege('anon', 'public.early_warnings', 'SELECT')
            or has_table_privilege('anon', 'public.early_warnings', 'INSERT')
            or has_table_privilege('anon', 'public.early_warnings', 'UPDATE')
            or has_table_privilege('anon', 'public.early_warnings', 'DELETE')
            or has_table_privilege('authenticated', 'public.early_warnings', 'SELECT')
            or has_table_privilege('authenticated', 'public.early_warnings', 'INSERT')
            or has_table_privilege('authenticated', 'public.early_warnings', 'UPDATE')
            or has_table_privilege('authenticated', 'public.early_warnings', 'DELETE')
            or has_table_privilege('anon', 'public.early_warning_areas', 'SELECT')
            or has_table_privilege('anon', 'public.early_warning_areas', 'INSERT')
            or has_table_privilege('anon', 'public.early_warning_areas', 'UPDATE')
            or has_table_privilege('anon', 'public.early_warning_areas', 'DELETE')
            or has_table_privilege('authenticated', 'public.early_warning_areas', 'SELECT')
            or has_table_privilege('authenticated', 'public.early_warning_areas', 'INSERT')
            or has_table_privilege('authenticated', 'public.early_warning_areas', 'UPDATE')
            or has_table_privilege('authenticated', 'public.early_warning_areas', 'DELETE')
        ),
        'policy_count', (
            select count(*)
            from pg_catalog.pg_policies
            where schemaname = 'public'
              and tablename in (
                  'early_warning_sources',
                  'early_warnings',
                  'early_warning_areas'
              )
        ),
        'source_count', (select count(*) from public.early_warning_sources),
        'source_seed_valid', (
            select count(*) = 4
            from public.early_warning_sources
            where (source_code, source_name, source_type, integration_status, is_active) in (
                ('PAGASA', 'DOST-PAGASA', 'GOVERNMENT_AGENCY', 'PENDING', true),
                ('PHIVOLCS', 'DOST-PHIVOLCS', 'GOVERNMENT_AGENCY', 'PENDING', true),
                ('NDRRMC', 'National Disaster Risk Reduction and Management Council', 'GOVERNMENT_AGENCY', 'PENDING', true),
                ('CIVENTRAL', 'CIVENTRAL DRRM', 'INTERNAL_SYSTEM', 'PENDING', true)
            )
        ),
        'warning_count', (select count(*) from public.early_warnings),
        'area_count', (select count(*) from public.early_warning_areas),
        'risk_levels_reused', (
            select count(*) = 4
            from public.risk_levels
            where code in ('LOW', 'MODERATE', 'HIGH', 'CRITICAL')
              and is_active
        )
    );
$function$;

revoke all on function public.verify_module4_early_warning_schema() from public, anon, authenticated;
grant execute on function public.verify_module4_early_warning_schema() to service_role;

notify pgrst, 'reload schema';

commit;
