-- CIVENTRAL DRRM Module 4 Phase 4B.1
-- Provider-neutral external advisory staging and fetch-run audit foundation.
-- This migration does not fetch providers, create warnings, or activate alerts.

begin;

alter table public.early_warning_sources
    drop constraint early_warning_sources_integration_status_check;

alter table public.early_warning_sources
    add constraint early_warning_sources_integration_status_check check (
        integration_status in ('CONNECTED', 'PARTIAL', 'PENDING', 'UNAVAILABLE', 'DISABLED')
    );

comment on column public.early_warning_sources.integration_status is
    'Integration readiness: CONNECTED, PARTIAL, PENDING, UNAVAILABLE, or administratively DISABLED. Runtime provider failures belong to fetch-run audit records.';

-- Preserve deliberate operator changes. Promote only rows that still match
-- the exact original seed definitions.
update public.early_warning_sources
   set integration_status = 'CONNECTED'
 where source_code = 'CIVENTRAL'
   and source_name = 'CIVENTRAL DRRM'
   and source_type = 'INTERNAL_SYSTEM'
   and integration_status = 'PENDING'
   and is_active = true;

update public.early_warning_sources
   set integration_status = 'PARTIAL'
 where source_code = 'PAGASA'
   and source_name = 'DOST-PAGASA'
   and source_type = 'GOVERNMENT_AGENCY'
   and integration_status = 'PENDING'
   and is_active = true;

create table public.external_advisory_fetch_runs (
    id uuid primary key default pg_catalog.gen_random_uuid(),
    source_id uuid not null,
    initiated_by_actor_reference text not null,
    started_at timestamptz not null,
    finished_at timestamptz not null default pg_catalog.now(),
    result text not null,
    fetched_item_count integer not null default 0,
    staged_item_count integer not null default 0,
    new_item_count integer not null default 0,
    updated_item_count integer not null default 0,
    unchanged_item_count integer not null default 0,
    error_count integer not null default 0,
    error_summary text null,
    created_at timestamptz not null default pg_catalog.now(),

    constraint external_advisory_fetch_runs_source_id_fkey
        foreign key (source_id)
        references public.early_warning_sources(id)
        on delete restrict,
    constraint external_advisory_fetch_runs_actor_reference_check check (
        initiated_by_actor_reference ~ '^(USER|EMPLOYEE):[A-Za-z0-9][A-Za-z0-9._:@/-]{0,149}$'
    ),
    constraint external_advisory_fetch_runs_result_check check (
        result in ('SUCCESS', 'PARTIAL', 'FAILED', 'SKIPPED')
    ),
    constraint external_advisory_fetch_runs_time_check check (
        finished_at >= started_at
    ),
    constraint external_advisory_fetch_runs_counts_check check (
        fetched_item_count between 0 and 100
        and staged_item_count between 0 and 100
        and new_item_count between 0 and 100
        and updated_item_count between 0 and 100
        and unchanged_item_count between 0 and 100
        and error_count between 0 and 100
        and staged_item_count <= fetched_item_count
        and new_item_count + updated_item_count + unchanged_item_count = staged_item_count
    ),
    constraint external_advisory_fetch_runs_error_summary_check check (
        error_summary is null
        or (length(btrim(error_summary)) between 1 and 1000)
    ),
    constraint external_advisory_fetch_runs_result_pair_check check (
        (result = 'SUCCESS'
            and error_count = 0
            and error_summary is null
            and staged_item_count = fetched_item_count)
        or (result = 'PARTIAL'
            and error_count > 0
            and error_summary is not null
            and staged_item_count > 0)
        or (result = 'FAILED'
            and error_count > 0
            and error_summary is not null
            and staged_item_count = 0)
        or (result = 'SKIPPED'
            and fetched_item_count = 0
            and staged_item_count = 0
            and error_count = 0
            and error_summary is not null)
    )
);

comment on table public.external_advisory_fetch_runs is
    'Append-only application audit of completed external advisory fetch attempts. Safe summaries only; credentials and server traces are prohibited.';
comment on column public.external_advisory_fetch_runs.result is
    'Completed run outcome. SKIPPED records a deliberate no-fetch decision such as a pending verified provider source.';

create table public.external_advisories (
    id uuid primary key default pg_catalog.gen_random_uuid(),
    source_id uuid not null,
    external_reference_id text null,
    deduplication_key text not null,
    identity_method text not null,
    source_reference text null,
    title text not null,
    advisory_type text null,
    hazard_type text null,
    summary text null,
    issued_at timestamptz null,
    valid_until timestamptz null,
    first_fetched_at timestamptz not null,
    fetched_at timestamptz not null,
    first_fetch_run_id uuid not null,
    last_fetch_run_id uuid not null,
    raw_payload jsonb not null,
    normalized_payload jsonb not null,
    payload_hash text not null,
    payload_version integer not null default 1,
    review_status text not null default 'PENDING_REVIEW',
    linked_warning_id uuid null,
    created_at timestamptz not null default pg_catalog.now(),
    updated_at timestamptz not null default pg_catalog.now(),

    constraint external_advisories_source_id_fkey
        foreign key (source_id)
        references public.early_warning_sources(id)
        on delete restrict,
    constraint external_advisories_first_fetch_run_id_fkey
        foreign key (first_fetch_run_id)
        references public.external_advisory_fetch_runs(id)
        on delete restrict,
    constraint external_advisories_last_fetch_run_id_fkey
        foreign key (last_fetch_run_id)
        references public.external_advisory_fetch_runs(id)
        on delete restrict,
    constraint external_advisories_linked_warning_id_fkey
        foreign key (linked_warning_id)
        references public.early_warnings(id)
        on delete restrict,
    constraint external_advisories_source_deduplication_unique
        unique (source_id, deduplication_key),
    constraint external_advisories_external_reference_id_check check (
        external_reference_id is null
        or length(btrim(external_reference_id)) between 1 and 500
    ),
    constraint external_advisories_deduplication_key_check check (
        deduplication_key ~ '^[0-9a-f]{64}$'
    ),
    constraint external_advisories_identity_method_check check (
        identity_method in ('PROVIDER_ID', 'DETERMINISTIC_FALLBACK')
    ),
    constraint external_advisories_identity_pair_check check (
        (identity_method = 'PROVIDER_ID' and external_reference_id is not null)
        or (identity_method = 'DETERMINISTIC_FALLBACK' and external_reference_id is null)
    ),
    constraint external_advisories_source_reference_check check (
        source_reference is null
        or length(btrim(source_reference)) between 1 and 2000
    ),
    constraint external_advisories_title_check check (
        length(btrim(title)) between 1 and 500
    ),
    constraint external_advisories_advisory_type_check check (
        advisory_type is null
        or (
            advisory_type = upper(advisory_type)
            and advisory_type ~ '^[A-Z][A-Z0-9_]{0,99}$'
        )
    ),
    constraint external_advisories_hazard_type_check check (
        hazard_type is null
        or hazard_type in (
            'FLOOD', 'HEAVY_RAINFALL', 'TROPICAL_CYCLONE', 'LANDSLIDE',
            'EARTHQUAKE', 'VOLCANIC_ACTIVITY', 'OTHER'
        )
    ),
    constraint external_advisories_summary_check check (
        summary is null
        or length(btrim(summary)) between 1 and 10000
    ),
    constraint external_advisories_valid_range_check check (
        valid_until is null
        or (issued_at is not null and valid_until > issued_at)
    ),
    constraint external_advisories_fetch_time_check check (
        fetched_at >= first_fetched_at
    ),
    constraint external_advisories_raw_payload_check check (
        pg_catalog.jsonb_typeof(raw_payload) in ('object', 'array')
        and pg_catalog.octet_length(raw_payload::text) <= 1048576
    ),
    constraint external_advisories_normalized_payload_check check (
        pg_catalog.jsonb_typeof(normalized_payload) = 'object'
        and pg_catalog.octet_length(normalized_payload::text) <= 131072
    ),
    constraint external_advisories_payload_hash_check check (
        payload_hash ~ '^[0-9a-f]{64}$'
    ),
    constraint external_advisories_payload_version_positive check (
        payload_version > 0
    ),
    constraint external_advisories_review_status_check check (
        review_status in ('PENDING_REVIEW', 'DRAFT_CREATED', 'DISMISSED')
    ),
    constraint external_advisories_review_link_pair_check check (
        (review_status = 'DRAFT_CREATED' and linked_warning_id is not null)
        or (review_status in ('PENDING_REVIEW', 'DISMISSED') and linked_warning_id is null)
    )
);

comment on table public.external_advisories is
    'Untrusted external provider items staged for administrative review. Rows never become warnings automatically.';
comment on column public.external_advisories.deduplication_key is
    'SHA-256 provider identity key. Stable provider IDs are preferred; deterministic fallback identity has documented collision/change limitations.';
comment on column public.external_advisories.payload_hash is
    'SHA-256 of canonical normalized fields plus the retained raw provider payload; payload_version increases only when this hash changes.';
comment on column public.external_advisories.review_status is
    'Human review boundary. DRAFT_CREATED linking is reserved for Phase 4B.2.';

create unique index uq_external_advisories_source_external_reference
    on public.external_advisories(source_id, external_reference_id)
    where external_reference_id is not null;
create index idx_external_advisories_source_review_issued
    on public.external_advisories(source_id, review_status, issued_at desc);
create index idx_external_advisories_payload_hash
    on public.external_advisories(payload_hash);
create index idx_external_advisories_linked_warning
    on public.external_advisories(linked_warning_id)
    where linked_warning_id is not null;
create index idx_external_advisory_fetch_runs_source_started
    on public.external_advisory_fetch_runs(source_id, started_at desc);
create index idx_external_advisory_fetch_runs_result_started
    on public.external_advisory_fetch_runs(result, started_at desc);

create trigger external_advisories_set_updated_at
before update on public.external_advisories
for each row execute function public.set_module4_updated_at();

alter table public.external_advisory_fetch_runs enable row level security;
alter table public.external_advisories enable row level security;

revoke all on table public.external_advisory_fetch_runs from public, anon, authenticated, service_role;
revoke all on table public.external_advisories from public, anon, authenticated, service_role;
grant select on table public.external_advisory_fetch_runs to service_role;
grant select on table public.external_advisories to service_role;

create function public.stage_module4_external_advisory_fetch(
    p_source_code text,
    p_started_at timestamptz,
    p_result text,
    p_fetched_item_count integer,
    p_items jsonb,
    p_error_count integer,
    p_error_summary text,
    p_actor_reference text
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $function$
declare
    v_source_id uuid;
    v_run_id uuid := pg_catalog.gen_random_uuid();
    v_finished_at timestamptz := pg_catalog.now();
    v_item jsonb;
    v_current public.external_advisories%rowtype;
    v_external_reference_id text;
    v_source_reference text;
    v_title text;
    v_advisory_type text;
    v_hazard_type text;
    v_summary text;
    v_issued_at timestamptz;
    v_valid_until timestamptz;
    v_expected_normalized jsonb;
    v_staged_count integer;
    v_new_count integer := 0;
    v_updated_count integer := 0;
    v_unchanged_count integer := 0;
begin
    p_source_code := upper(btrim(coalesce(p_source_code, '')));
    p_result := upper(btrim(coalesce(p_result, '')));
    p_error_summary := nullif(btrim(coalesce(p_error_summary, '')), '');
    p_actor_reference := btrim(coalesce(p_actor_reference, ''));

    perform public.module4_assert_actor_reference(p_actor_reference);

    if p_source_code !~ '^[A-Z][A-Z0-9_]{0,49}$' then
        raise exception using errcode = '22023', message = 'External advisory source is invalid.';
    end if;
    if p_started_at is null
       or p_started_at < v_finished_at - interval '1 hour'
       or p_started_at > v_finished_at + interval '1 minute' then
        raise exception using errcode = '22023', message = 'External advisory fetch start time is invalid.';
    end if;
    if p_result not in ('SUCCESS', 'PARTIAL', 'FAILED', 'SKIPPED') then
        raise exception using errcode = '22023', message = 'External advisory fetch result is invalid.';
    end if;
    if p_fetched_item_count is null or p_fetched_item_count not between 0 and 100
       or p_error_count is null or p_error_count not between 0 and 100 then
        raise exception using errcode = '22023', message = 'External advisory fetch counts are invalid.';
    end if;
    if p_error_summary is not null and length(p_error_summary) > 1000 then
        raise exception using errcode = '22023', message = 'External advisory error summary is invalid.';
    end if;
    if p_items is null
       or pg_catalog.jsonb_typeof(p_items) <> 'array'
       or pg_catalog.jsonb_array_length(p_items) > 100 then
        raise exception using errcode = '22023', message = 'External advisory item collection is invalid.';
    end if;
    if pg_catalog.octet_length(p_items::text) > 4194304 then
        raise exception using
            errcode = '22023',
            message = 'External advisory item collection exceeds the safe aggregate size limit.';
    end if;

    v_staged_count := pg_catalog.jsonb_array_length(p_items);
    if v_staged_count > p_fetched_item_count then
        raise exception using errcode = '22023', message = 'External advisory fetch counts are inconsistent.';
    end if;
    if not (
        (p_result = 'SUCCESS'
            and p_error_count = 0
            and p_error_summary is null
            and v_staged_count = p_fetched_item_count)
        or (p_result = 'PARTIAL'
            and p_error_count > 0
            and p_error_summary is not null
            and v_staged_count > 0)
        or (p_result = 'FAILED'
            and p_error_count > 0
            and p_error_summary is not null
            and v_staged_count = 0)
        or (p_result = 'SKIPPED'
            and p_fetched_item_count = 0
            and v_staged_count = 0
            and p_error_count = 0
            and p_error_summary is not null)
    ) then
        raise exception using errcode = '22023', message = 'External advisory fetch outcome is inconsistent.';
    end if;

    select source.id
      into v_source_id
      from public.early_warning_sources as source
     where source.source_code = p_source_code
       and source.source_type = 'GOVERNMENT_AGENCY'
       and source.integration_status in ('CONNECTED', 'PARTIAL')
       and source.is_active = true
     for update;

    if v_source_id is null then
        raise exception using errcode = '22023', message = 'External advisory source is not fetch-enabled.';
    end if;

    if (
        select pg_catalog.count(*) <> pg_catalog.count(distinct item->>'deduplication_key')
          from pg_catalog.jsonb_array_elements(p_items) as items(item)
    ) then
        raise exception using errcode = '22023', message = 'Duplicate external advisory identities are not allowed in one fetch.';
    end if;

    for v_item in
        select item from pg_catalog.jsonb_array_elements(p_items) as items(item)
    loop
        if pg_catalog.jsonb_typeof(v_item) <> 'object'
           or not v_item ?& array[
                'external_reference_id', 'source_reference', 'title', 'advisory_type',
                'hazard_type', 'summary', 'issued_at', 'valid_until',
                'deduplication_key', 'identity_method', 'raw_payload',
                'normalized_payload', 'payload_hash'
           ] then
            raise exception using errcode = '22023', message = 'An external advisory item is incomplete.';
        end if;
        if exists (
            select 1
              from pg_catalog.jsonb_object_keys(v_item) as item_key(key)
             where item_key.key not in (
                'external_reference_id', 'source_reference', 'title', 'advisory_type',
                'hazard_type', 'summary', 'issued_at', 'valid_until',
                'deduplication_key', 'identity_method', 'raw_payload',
                'normalized_payload', 'payload_hash'
             )
        ) then
            raise exception using errcode = '22023', message = 'An external advisory item contains unsupported fields.';
        end if;

        if coalesce(pg_catalog.jsonb_typeof(v_item->'external_reference_id'), 'null') not in ('string', 'null')
           or coalesce(pg_catalog.jsonb_typeof(v_item->'source_reference'), 'null') not in ('string', 'null')
           or pg_catalog.jsonb_typeof(v_item->'title') <> 'string'
           or coalesce(pg_catalog.jsonb_typeof(v_item->'advisory_type'), 'null') not in ('string', 'null')
           or coalesce(pg_catalog.jsonb_typeof(v_item->'hazard_type'), 'null') not in ('string', 'null')
           or coalesce(pg_catalog.jsonb_typeof(v_item->'summary'), 'null') not in ('string', 'null')
           or coalesce(pg_catalog.jsonb_typeof(v_item->'issued_at'), 'null') not in ('string', 'null')
           or coalesce(pg_catalog.jsonb_typeof(v_item->'valid_until'), 'null') not in ('string', 'null')
           or pg_catalog.jsonb_typeof(v_item->'deduplication_key') <> 'string'
           or pg_catalog.jsonb_typeof(v_item->'identity_method') <> 'string'
           or pg_catalog.jsonb_typeof(v_item->'raw_payload') not in ('object', 'array')
           or pg_catalog.jsonb_typeof(v_item->'normalized_payload') <> 'object'
           or pg_catalog.jsonb_typeof(v_item->'payload_hash') <> 'string' then
            raise exception using errcode = '22023', message = 'An external advisory item contains invalid field types.';
        end if;

        v_external_reference_id := nullif(btrim(coalesce(v_item->>'external_reference_id', '')), '');
        v_source_reference := nullif(btrim(coalesce(v_item->>'source_reference', '')), '');
        v_title := btrim(coalesce(v_item->>'title', ''));
        v_advisory_type := nullif(btrim(coalesce(v_item->>'advisory_type', '')), '');
        v_hazard_type := nullif(btrim(coalesce(v_item->>'hazard_type', '')), '');
        v_summary := nullif(btrim(coalesce(v_item->>'summary', '')), '');

        if v_external_reference_id is not null and length(v_external_reference_id) > 500
           or v_source_reference is not null and length(v_source_reference) > 2000
           or length(v_title) not between 1 and 500
           or v_advisory_type is not null and (
                length(v_advisory_type) > 100
                or v_advisory_type !~ '^[A-Z][A-Z0-9_]*$'
           )
           or v_hazard_type is not null and v_hazard_type not in (
                'FLOOD', 'HEAVY_RAINFALL', 'TROPICAL_CYCLONE', 'LANDSLIDE',
                'EARTHQUAKE', 'VOLCANIC_ACTIVITY', 'OTHER'
           )
           or v_summary is not null and length(v_summary) > 10000
           or v_item->>'deduplication_key' !~ '^[0-9a-f]{64}$'
           or v_item->>'payload_hash' !~ '^[0-9a-f]{64}$'
           or v_item->>'identity_method' not in ('PROVIDER_ID', 'DETERMINISTIC_FALLBACK')
           or (v_item->>'identity_method' = 'PROVIDER_ID' and v_external_reference_id is null)
           or (v_item->>'identity_method' = 'DETERMINISTIC_FALLBACK' and v_external_reference_id is not null)
           or (v_item->>'identity_method' = 'DETERMINISTIC_FALLBACK'
                and (v_source_reference is null or v_item->>'issued_at' is null))
           or pg_catalog.octet_length((v_item->'raw_payload')::text) > 1048576
           or pg_catalog.octet_length((v_item->'normalized_payload')::text) > 131072 then
            raise exception using errcode = '22023', message = 'An external advisory item failed validation.';
        end if;

        v_issued_at := null;
        if v_item->>'issued_at' is not null then
            if v_item->>'issued_at' !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?(Z|[+-][0-9]{2}:[0-9]{2})$' then
                raise exception using errcode = '22023', message = 'External advisory issue timestamp is invalid.';
            end if;
            v_issued_at := (v_item->>'issued_at')::timestamptz;
        end if;

        v_valid_until := null;
        if v_item->>'valid_until' is not null then
            if v_item->>'valid_until' !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?(Z|[+-][0-9]{2}:[0-9]{2})$' then
                raise exception using errcode = '22023', message = 'External advisory validity timestamp is invalid.';
            end if;
            v_valid_until := (v_item->>'valid_until')::timestamptz;
        end if;
        if v_valid_until is not null and (v_issued_at is null or v_valid_until <= v_issued_at) then
            raise exception using errcode = '22023', message = 'External advisory validity range is invalid.';
        end if;

        v_expected_normalized := pg_catalog.jsonb_build_object(
            'external_reference_id', v_item->'external_reference_id',
            'source_reference', v_item->'source_reference',
            'title', v_item->'title',
            'advisory_type', v_item->'advisory_type',
            'hazard_type', v_item->'hazard_type',
            'summary', v_item->'summary',
            'issued_at', v_item->'issued_at',
            'valid_until', v_item->'valid_until'
        );
        if v_item->'normalized_payload' <> v_expected_normalized then
            raise exception using errcode = '22023', message = 'External advisory normalized payload is inconsistent.';
        end if;
    end loop;

    -- The source-row lock serializes fetches per provider. Count first so the
    -- completed audit row can be inserted once with internally consistent data.
    for v_item in
        select item from pg_catalog.jsonb_array_elements(p_items) as items(item)
    loop
        select advisory.*
          into v_current
          from public.external_advisories as advisory
         where advisory.source_id = v_source_id
           and advisory.deduplication_key = v_item->>'deduplication_key'
         for update;

        if not found then
            v_new_count := v_new_count + 1;
        else
            if v_current.identity_method <> v_item->>'identity_method'
               or v_current.external_reference_id is distinct from nullif(btrim(coalesce(v_item->>'external_reference_id', '')), '') then
                raise exception using errcode = '23505', message = 'External advisory identity collision detected.';
            end if;
            if v_current.payload_hash = v_item->>'payload_hash' then
                v_unchanged_count := v_unchanged_count + 1;
            else
                v_updated_count := v_updated_count + 1;
            end if;
        end if;
    end loop;

    insert into public.external_advisory_fetch_runs (
        id, source_id, initiated_by_actor_reference, started_at, finished_at,
        result, fetched_item_count, staged_item_count, new_item_count,
        updated_item_count, unchanged_item_count, error_count, error_summary
    ) values (
        v_run_id, v_source_id, p_actor_reference, p_started_at, v_finished_at,
        p_result, p_fetched_item_count, v_staged_count, v_new_count,
        v_updated_count, v_unchanged_count, p_error_count, p_error_summary
    );

    for v_item in
        select item from pg_catalog.jsonb_array_elements(p_items) as items(item)
    loop
        select advisory.*
          into v_current
          from public.external_advisories as advisory
         where advisory.source_id = v_source_id
           and advisory.deduplication_key = v_item->>'deduplication_key'
         for update;

        if not found then
            insert into public.external_advisories (
                source_id, external_reference_id, deduplication_key, identity_method,
                source_reference, title, advisory_type, hazard_type, summary,
                issued_at, valid_until, first_fetched_at, fetched_at,
                first_fetch_run_id, last_fetch_run_id, raw_payload,
                normalized_payload, payload_hash, payload_version,
                review_status, linked_warning_id
            ) values (
                v_source_id,
                nullif(btrim(coalesce(v_item->>'external_reference_id', '')), ''),
                v_item->>'deduplication_key',
                v_item->>'identity_method',
                nullif(btrim(coalesce(v_item->>'source_reference', '')), ''),
                btrim(v_item->>'title'),
                nullif(btrim(coalesce(v_item->>'advisory_type', '')), ''),
                nullif(btrim(coalesce(v_item->>'hazard_type', '')), ''),
                nullif(btrim(coalesce(v_item->>'summary', '')), ''),
                (v_item->>'issued_at')::timestamptz,
                (v_item->>'valid_until')::timestamptz,
                v_finished_at,
                v_finished_at,
                v_run_id,
                v_run_id,
                v_item->'raw_payload',
                v_item->'normalized_payload',
                v_item->>'payload_hash',
                1,
                'PENDING_REVIEW',
                null
            );
        elsif v_current.payload_hash = v_item->>'payload_hash' then
            update public.external_advisories
               set fetched_at = v_finished_at,
                   last_fetch_run_id = v_run_id
             where id = v_current.id;
        else
            update public.external_advisories
               set source_reference = nullif(btrim(coalesce(v_item->>'source_reference', '')), ''),
                   title = btrim(v_item->>'title'),
                   advisory_type = nullif(btrim(coalesce(v_item->>'advisory_type', '')), ''),
                   hazard_type = nullif(btrim(coalesce(v_item->>'hazard_type', '')), ''),
                   summary = nullif(btrim(coalesce(v_item->>'summary', '')), ''),
                   issued_at = (v_item->>'issued_at')::timestamptz,
                   valid_until = (v_item->>'valid_until')::timestamptz,
                   fetched_at = v_finished_at,
                   last_fetch_run_id = v_run_id,
                   raw_payload = v_item->'raw_payload',
                   normalized_payload = v_item->'normalized_payload',
                   payload_hash = v_item->>'payload_hash',
                   payload_version = payload_version + 1
             where id = v_current.id;
        end if;
    end loop;

    return pg_catalog.jsonb_build_object(
        'outcome', 'RECORDED',
        'fetch_run_id', v_run_id,
        'source_code', p_source_code,
        'result', p_result,
        'fetched_item_count', p_fetched_item_count,
        'staged_item_count', v_staged_count,
        'new_item_count', v_new_count,
        'updated_item_count', v_updated_count,
        'unchanged_item_count', v_unchanged_count,
        'error_count', p_error_count,
        'finished_at', v_finished_at
    );
end;
$function$;

revoke all on function public.stage_module4_external_advisory_fetch(
    text, timestamptz, text, integer, jsonb, integer, text, text
) from public, anon, authenticated, service_role;
grant execute on function public.stage_module4_external_advisory_fetch(
    text, timestamptz, text, integer, jsonb, integer, text, text
) to service_role;

create function public.verify_module4_external_advisory_schema()
returns jsonb
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    with expected_tables(name) as (
        values
            ('early_warning_sources'),
            ('early_warnings'),
            ('early_warning_areas'),
            ('early_warning_history'),
            ('external_advisory_fetch_runs'),
            ('external_advisories')
    ),
    target_tables as (
        select class.relname, class.relrowsecurity
          from pg_catalog.pg_class as class
          join pg_catalog.pg_namespace as namespace on namespace.oid = class.relnamespace
          join expected_tables as expected on expected.name = class.relname
         where namespace.nspname = 'public'
           and class.relkind = 'r'
    ),
    expected_foreign_keys(table_name, name, delete_action) as (
        values
            ('early_warnings', 'early_warnings_source_id_fkey', 'r'),
            ('early_warnings', 'early_warnings_warning_level_id_fkey', 'r'),
            ('early_warning_areas', 'early_warning_areas_warning_id_fkey', 'c'),
            ('early_warning_areas', 'early_warning_areas_barangay_id_fkey', 'r'),
            ('early_warning_history', 'early_warning_history_warning_id_fkey', 'r'),
            ('external_advisory_fetch_runs', 'external_advisory_fetch_runs_source_id_fkey', 'r'),
            ('external_advisories', 'external_advisories_source_id_fkey', 'r'),
            ('external_advisories', 'external_advisories_first_fetch_run_id_fkey', 'r'),
            ('external_advisories', 'external_advisories_last_fetch_run_id_fkey', 'r'),
            ('external_advisories', 'external_advisories_linked_warning_id_fkey', 'r')
    ),
    expected_checks(table_name, name) as (
        values
            ('early_warning_sources', 'early_warning_sources_integration_status_check'),
            ('early_warnings', 'early_warnings_revision_positive'),
            ('early_warnings', 'early_warnings_status_check'),
            ('early_warning_areas', 'early_warning_areas_scope_type_check'),
            ('early_warning_history', 'early_warning_history_event_status_pair_check'),
            ('early_warning_history', 'early_warning_history_actor_reference_check'),
            ('external_advisory_fetch_runs', 'external_advisory_fetch_runs_actor_reference_check'),
            ('external_advisory_fetch_runs', 'external_advisory_fetch_runs_result_check'),
            ('external_advisory_fetch_runs', 'external_advisory_fetch_runs_time_check'),
            ('external_advisory_fetch_runs', 'external_advisory_fetch_runs_counts_check'),
            ('external_advisory_fetch_runs', 'external_advisory_fetch_runs_error_summary_check'),
            ('external_advisory_fetch_runs', 'external_advisory_fetch_runs_result_pair_check'),
            ('external_advisories', 'external_advisories_external_reference_id_check'),
            ('external_advisories', 'external_advisories_deduplication_key_check'),
            ('external_advisories', 'external_advisories_identity_method_check'),
            ('external_advisories', 'external_advisories_identity_pair_check'),
            ('external_advisories', 'external_advisories_source_reference_check'),
            ('external_advisories', 'external_advisories_title_check'),
            ('external_advisories', 'external_advisories_advisory_type_check'),
            ('external_advisories', 'external_advisories_hazard_type_check'),
            ('external_advisories', 'external_advisories_summary_check'),
            ('external_advisories', 'external_advisories_valid_range_check'),
            ('external_advisories', 'external_advisories_fetch_time_check'),
            ('external_advisories', 'external_advisories_raw_payload_check'),
            ('external_advisories', 'external_advisories_normalized_payload_check'),
            ('external_advisories', 'external_advisories_payload_hash_check'),
            ('external_advisories', 'external_advisories_payload_version_positive'),
            ('external_advisories', 'external_advisories_review_status_check'),
            ('external_advisories', 'external_advisories_review_link_pair_check')
    ),
    expected_indexes(table_name, name) as (
        values
            ('early_warnings', 'early_warnings_source_external_reference_uidx'),
            ('early_warnings', 'early_warnings_status_idx'),
            ('early_warnings', 'early_warnings_warning_level_id_idx'),
            ('early_warnings', 'early_warnings_source_id_idx'),
            ('early_warnings', 'early_warnings_issued_at_idx'),
            ('early_warnings', 'early_warnings_valid_until_idx'),
            ('early_warnings', 'early_warnings_hazard_type_idx'),
            ('early_warning_areas', 'early_warning_areas_warning_id_idx'),
            ('early_warning_areas', 'early_warning_areas_barangay_id_idx'),
            ('early_warning_areas', 'early_warning_areas_scope_type_idx'),
            ('early_warning_history', 'idx_early_warning_history_warning_time'),
            ('early_warning_history', 'uq_early_warning_history_warning_revision'),
            ('early_warning_areas', 'uq_early_warning_areas_warning_barangay'),
            ('external_advisories', 'external_advisories_source_deduplication_unique'),
            ('external_advisories', 'uq_external_advisories_source_external_reference'),
            ('external_advisories', 'idx_external_advisories_source_review_issued'),
            ('external_advisories', 'idx_external_advisories_payload_hash'),
            ('external_advisories', 'idx_external_advisories_linked_warning'),
            ('external_advisory_fetch_runs', 'idx_external_advisory_fetch_runs_source_started'),
            ('external_advisory_fetch_runs', 'idx_external_advisory_fetch_runs_result_started')
    ),
    expected_functions(signature) as (
        values
            ('public.stage_module4_external_advisory_fetch(text,timestamptz,text,integer,jsonb,integer,text,text)'),
            ('public.verify_module4_external_advisory_schema()')
    ),
    actual_functions as (
        select
            expected.signature,
            procedure.oid,
            procedure.prosecdef,
            procedure.proconfig,
            procedure.proacl,
            procedure.proowner
          from expected_functions as expected
          left join pg_catalog.pg_proc as procedure
            on procedure.oid = pg_catalog.to_regprocedure(expected.signature)
    )
    select pg_catalog.jsonb_build_object(
        'tables_exist', (select pg_catalog.count(*) = 6 from target_tables),
        'rls_enabled', (
            select pg_catalog.count(*) = 6 and pg_catalog.bool_and(relrowsecurity)
              from target_tables
        ),
        'foreign_keys_valid', not exists (
            select 1
              from expected_foreign_keys as expected
             where not exists (
                select 1
                  from pg_catalog.pg_constraint as actual
                  join pg_catalog.pg_class as relation on relation.oid = actual.conrelid
                  join pg_catalog.pg_namespace as namespace on namespace.oid = relation.relnamespace
                 where namespace.nspname = 'public'
                   and relation.relname = expected.table_name
                   and actual.conname = expected.name
                   and actual.contype = 'f'
                   and actual.confdeltype::text = expected.delete_action
                   and actual.convalidated
             )
        ),
        'check_constraints_valid', not exists (
            select 1
              from expected_checks as expected
             where not exists (
                select 1
                  from pg_catalog.pg_constraint as actual
                  join pg_catalog.pg_class as relation on relation.oid = actual.conrelid
                  join pg_catalog.pg_namespace as namespace on namespace.oid = relation.relnamespace
                 where namespace.nspname = 'public'
                   and relation.relname = expected.table_name
                   and actual.conname = expected.name
                   and actual.contype = 'c'
                   and actual.convalidated
             )
        ),
        'unique_source_code_valid', exists (
            select 1
              from pg_catalog.pg_constraint as actual
              join pg_catalog.pg_class as relation on relation.oid = actual.conrelid
              join pg_catalog.pg_namespace as namespace on namespace.oid = relation.relnamespace
             where namespace.nspname = 'public'
               and relation.relname = 'early_warning_sources'
               and actual.conname = 'early_warning_sources_source_code_unique'
               and actual.contype = 'u'
               and actual.convalidated
        ),
        'warning_status_check_valid', exists (
            select 1
              from pg_catalog.pg_constraint as actual
              join pg_catalog.pg_class as relation on relation.oid = actual.conrelid
              join pg_catalog.pg_namespace as namespace on namespace.oid = relation.relnamespace
             where namespace.nspname = 'public'
               and relation.relname = 'early_warnings'
               and actual.conname = 'early_warnings_status_check'
               and actual.contype = 'c'
               and actual.convalidated
        ),
        'area_scope_type_check_valid', exists (
            select 1
              from pg_catalog.pg_constraint as actual
              join pg_catalog.pg_class as relation on relation.oid = actual.conrelid
              join pg_catalog.pg_namespace as namespace on namespace.oid = relation.relnamespace
             where namespace.nspname = 'public'
               and relation.relname = 'early_warning_areas'
               and actual.conname = 'early_warning_areas_scope_type_check'
               and actual.contype = 'c'
               and actual.convalidated
        ),
        'indexes_valid', not exists (
            select 1
              from expected_indexes as expected
             where not exists (
                select 1
                  from pg_catalog.pg_indexes as actual
                 where actual.schemaname = 'public'
                   and actual.tablename = expected.table_name
                   and actual.indexname = expected.name
             )
        ),
        'direct_client_privileges_restricted', not exists (
            select 1
              from expected_tables as expected
             where pg_catalog.has_table_privilege('anon', 'public.' || expected.name, 'SELECT')
                or pg_catalog.has_table_privilege('anon', 'public.' || expected.name, 'INSERT')
                or pg_catalog.has_table_privilege('anon', 'public.' || expected.name, 'UPDATE')
                or pg_catalog.has_table_privilege('anon', 'public.' || expected.name, 'DELETE')
                or pg_catalog.has_table_privilege('authenticated', 'public.' || expected.name, 'SELECT')
                or pg_catalog.has_table_privilege('authenticated', 'public.' || expected.name, 'INSERT')
                or pg_catalog.has_table_privilege('authenticated', 'public.' || expected.name, 'UPDATE')
                or pg_catalog.has_table_privilege('authenticated', 'public.' || expected.name, 'DELETE')
        ),
        'external_service_role_privileges_restricted', (
            pg_catalog.has_table_privilege('service_role', 'public.external_advisories', 'SELECT')
            and not pg_catalog.has_table_privilege('service_role', 'public.external_advisories', 'INSERT')
            and not pg_catalog.has_table_privilege('service_role', 'public.external_advisories', 'UPDATE')
            and not pg_catalog.has_table_privilege('service_role', 'public.external_advisories', 'DELETE')
            and pg_catalog.has_table_privilege('service_role', 'public.external_advisory_fetch_runs', 'SELECT')
            and not pg_catalog.has_table_privilege('service_role', 'public.external_advisory_fetch_runs', 'INSERT')
            and not pg_catalog.has_table_privilege('service_role', 'public.external_advisory_fetch_runs', 'UPDATE')
            and not pg_catalog.has_table_privilege('service_role', 'public.external_advisory_fetch_runs', 'DELETE')
        ),
        'staging_rpc_hardened', not exists (
            select 1
              from actual_functions as procedure
             where procedure.oid is null
                or not procedure.prosecdef
                or not coalesce(
                    procedure.proconfig @> array['search_path=pg_catalog, public'],
                    false
                )
        ),
        'phase4b_security_definer_functions_hardened', not exists (
            select 1
              from actual_functions as procedure
             where procedure.oid is null
                or not procedure.prosecdef
                or not coalesce(
                    procedure.proconfig @> array['search_path=pg_catalog, public'],
                    false
                )
        ),
        'staging_rpc_service_role_execute', pg_catalog.has_function_privilege(
            'service_role',
            'public.stage_module4_external_advisory_fetch(text,timestamptz,text,integer,jsonb,integer,text,text)',
            'EXECUTE'
        ),
        'phase4b_rpc_service_role_execute', not exists (
            select 1
              from actual_functions as procedure
             where procedure.oid is null
                or not coalesce(
                    pg_catalog.has_function_privilege('service_role', procedure.oid, 'EXECUTE'),
                    false
                )
        ),
        'phase4b_rpc_public_execute_revoked', not exists (
            select 1
              from actual_functions as procedure
             where procedure.oid is null
                or exists (
                    select 1
                      from pg_catalog.aclexplode(
                        coalesce(
                            procedure.proacl,
                            pg_catalog.acldefault('f', procedure.proowner)
                        )
                      ) as privilege
                     where privilege.grantee = 0
                       and privilege.privilege_type = 'EXECUTE'
                )
        ),
        'phase4b_rpc_anon_execute_revoked', not exists (
            select 1
              from actual_functions as procedure
             where procedure.oid is null
                or coalesce(
                    pg_catalog.has_function_privilege('anon', procedure.oid, 'EXECUTE'),
                    false
                )
        ),
        'phase4b_rpc_authenticated_execute_revoked', not exists (
            select 1
              from actual_functions as procedure
             where procedure.oid is null
                or coalesce(
                    pg_catalog.has_function_privilege('authenticated', procedure.oid, 'EXECUTE'),
                    false
                )
        ),
        'policy_count', (
            select pg_catalog.count(*)
              from pg_catalog.pg_policies
             where schemaname = 'public'
               and tablename in (select name from expected_tables)
        ),
        'source_count', (select pg_catalog.count(*) from public.early_warning_sources),
        'source_seed_valid', (
            select pg_catalog.count(*) = 4
              from public.early_warning_sources
             where (source_code, source_name, source_type, integration_status, is_active) in (
                ('PAGASA', 'DOST-PAGASA', 'GOVERNMENT_AGENCY', 'PARTIAL', true),
                ('PHIVOLCS', 'DOST-PHIVOLCS', 'GOVERNMENT_AGENCY', 'PENDING', true),
                ('NDRRMC', 'National Disaster Risk Reduction and Management Council', 'GOVERNMENT_AGENCY', 'PENDING', true),
                ('CIVENTRAL', 'CIVENTRAL DRRM', 'INTERNAL_SYSTEM', 'CONNECTED', true)
             )
        ),
        'warning_count', (select pg_catalog.count(*) from public.early_warnings),
        'area_count', (select pg_catalog.count(*) from public.early_warning_areas),
        'history_count', (select pg_catalog.count(*) from public.early_warning_history),
        'external_advisory_count', (select pg_catalog.count(*) from public.external_advisories),
        'external_fetch_run_count', (select pg_catalog.count(*) from public.external_advisory_fetch_runs),
        'risk_levels_reused', (
            select pg_catalog.count(*) = 4
              from public.risk_levels
             where code in ('LOW', 'MODERATE', 'HIGH', 'CRITICAL')
               and is_active
        )
    );
$function$;

revoke all on function public.verify_module4_external_advisory_schema()
    from public, anon, authenticated, service_role;
grant execute on function public.verify_module4_external_advisory_schema()
    to service_role;

notify pgrst, 'reload schema';

commit;
