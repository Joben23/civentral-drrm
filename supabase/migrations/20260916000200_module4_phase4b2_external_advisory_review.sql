-- CIVENTRAL DRRM Module 4 Phase 4B.2
-- Human review of staged external advisories and atomic Phase 4A draft conversion.
-- This migration creates no advisory, warning, area, or history data.

begin;

alter table public.external_advisories
    add column reviewed_at timestamptz null,
    add column reviewed_by_actor_reference text null,
    add column reviewed_payload_version integer null,
    add column reviewed_payload_hash text null;

alter table public.external_advisories
    add constraint external_advisories_review_actor_reference_check check (
        reviewed_by_actor_reference is null
        or reviewed_by_actor_reference ~ '^(USER|EMPLOYEE):[A-Za-z0-9][A-Za-z0-9._:@/-]{0,149}$'
    ),
    add constraint external_advisories_reviewed_payload_version_check check (
        reviewed_payload_version is null or reviewed_payload_version > 0
    ),
    add constraint external_advisories_reviewed_payload_hash_check check (
        reviewed_payload_hash is null or reviewed_payload_hash ~ '^[0-9a-f]{64}$'
    ),
    add constraint external_advisories_review_state_check check (
        (
            review_status = 'PENDING_REVIEW'
            and linked_warning_id is null
            and reviewed_at is null
            and reviewed_by_actor_reference is null
            and reviewed_payload_version is null
            and reviewed_payload_hash is null
        )
        or (
            review_status = 'DISMISSED'
            and linked_warning_id is null
            and reviewed_at is not null
            and reviewed_by_actor_reference is not null
            and reviewed_payload_version is not null
            and reviewed_payload_hash is not null
        )
        or (
            review_status = 'DRAFT_CREATED'
            and linked_warning_id is not null
            and reviewed_at is not null
            and reviewed_by_actor_reference is not null
            and reviewed_payload_version is not null
            and reviewed_payload_hash is not null
        )
    );

create unique index uq_external_advisories_linked_warning
    on public.external_advisories(linked_warning_id)
    where linked_warning_id is not null;

create table public.external_advisory_review_history (
    id uuid primary key default pg_catalog.gen_random_uuid(),
    external_advisory_id uuid not null,
    event_type text not null,
    actor_reference text not null,
    occurred_at timestamptz not null default pg_catalog.now(),
    resulting_review_status text not null,
    payload_version integer not null,
    payload_hash text not null,
    linked_warning_id uuid null,
    reviewed_snapshot jsonb not null,
    details jsonb not null default '{}'::jsonb,

    constraint external_advisory_review_history_advisory_id_fkey
        foreign key (external_advisory_id)
        references public.external_advisories(id)
        on delete restrict,
    constraint external_advisory_review_history_linked_warning_id_fkey
        foreign key (linked_warning_id)
        references public.early_warnings(id)
        on delete restrict,
    constraint external_advisory_review_history_event_type_check check (
        event_type in ('DISMISSED', 'DRAFT_CREATED')
    ),
    constraint external_advisory_review_history_actor_reference_check check (
        actor_reference ~ '^(USER|EMPLOYEE):[A-Za-z0-9][A-Za-z0-9._:@/-]{0,149}$'
    ),
    constraint external_advisory_review_history_status_check check (
        resulting_review_status in ('DISMISSED', 'DRAFT_CREATED')
    ),
    constraint external_advisory_review_history_event_status_pair_check check (
        (
            event_type = 'DISMISSED'
            and resulting_review_status = 'DISMISSED'
            and linked_warning_id is null
        )
        or (
            event_type = 'DRAFT_CREATED'
            and resulting_review_status = 'DRAFT_CREATED'
            and linked_warning_id is not null
        )
    ),
    constraint external_advisory_review_history_payload_version_check
        check (payload_version > 0),
    constraint external_advisory_review_history_payload_hash_check
        check (payload_hash ~ '^[0-9a-f]{64}$'),
    -- 96 KiB accommodates the bounded visible advisory fields even with JSON
    -- escaping. An abnormally long source name fails closed; it is not cut.
    constraint external_advisory_review_history_snapshot_check check (
        pg_catalog.jsonb_typeof(reviewed_snapshot) = 'object'
        and pg_catalog.octet_length(reviewed_snapshot::text) <= 98304
        and reviewed_snapshot ?& array[
            'source_code', 'source_name', 'external_reference_id',
            'source_reference', 'title', 'advisory_type', 'hazard_type',
            'summary', 'issued_at', 'valid_until', 'payload_version', 'payload_hash'
        ]
        and reviewed_snapshot - array[
            'source_code', 'source_name', 'external_reference_id',
            'source_reference', 'title', 'advisory_type', 'hazard_type',
            'summary', 'issued_at', 'valid_until', 'payload_version', 'payload_hash'
        ] = '{}'::jsonb
        and reviewed_snapshot->>'payload_version' is not distinct from payload_version::text
        and reviewed_snapshot->>'payload_hash' is not distinct from payload_hash
    ),
    constraint external_advisory_review_history_details_check check (
        pg_catalog.jsonb_typeof(details) = 'object'
        and pg_catalog.octet_length(details::text) <= 8192
    ),
    constraint external_advisory_review_history_one_terminal_event
        unique (external_advisory_id)
);

comment on table public.external_advisory_review_history is
    'Append-only audit of terminal human review decisions for staged external advisories.';
comment on column public.external_advisory_review_history.payload_version is
    'The exact staged provider payload version reviewed by the officer.';
comment on column public.external_advisory_review_history.reviewed_snapshot is
    'Immutable, bounded human-visible source and advisory fields captured under source/advisory locks; never raw provider payload. Phase 4B.1 may later refresh the current advisory without changing this terminal review evidence.';
comment on column public.external_advisories.reviewed_payload_version is
    'Payload version used for the terminal review decision; later provider refreshes do not change it.';

create index idx_external_advisory_review_history_time
    on public.external_advisory_review_history(occurred_at desc);
create index idx_external_advisory_review_history_warning
    on public.external_advisory_review_history(linked_warning_id)
    where linked_warning_id is not null;

alter table public.external_advisory_review_history enable row level security;

revoke all on table public.external_advisory_review_history
    from public, anon, authenticated, service_role;
grant select on table public.external_advisory_review_history to service_role;

revoke insert, update, delete on table public.external_advisories from service_role;
revoke all on table public.external_advisory_review_history
    from public, anon, authenticated;

create function public.dismiss_module4_external_advisory(
    p_external_advisory_id uuid,
    p_expected_payload_version integer,
    p_actor_reference text
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $function$
declare
    v_source_id uuid;
    v_source public.early_warning_sources%rowtype;
    v_current public.external_advisories%rowtype;
    v_reviewed_at timestamptz;
    v_reviewed_snapshot jsonb;
begin
    p_actor_reference := btrim(coalesce(p_actor_reference, ''));
    perform public.module4_assert_actor_reference(p_actor_reference);

    if p_expected_payload_version is null or p_expected_payload_version < 1 then
        raise exception using
            errcode = '22023',
            message = 'Expected external advisory payload version is invalid.';
    end if;

    select advisory.source_id
      into v_source_id
      from public.external_advisories as advisory
     where advisory.id = p_external_advisory_id;

    if not found then
        return pg_catalog.jsonb_build_object('outcome', 'NOT_FOUND');
    end if;

    -- Use the same provider lock ordering as Phase 4B.1 ingestion. An
    -- in-flight provider refresh must commit before the payload version check.
    select source.*
      into v_source
      from public.early_warning_sources as source
     where source.id = v_source_id
     for update;
    if not found then
        raise exception using errcode = 'P0001', message = 'External advisory source is missing.';
    end if;

    select advisory.*
      into v_current
      from public.external_advisories as advisory
     where advisory.id = p_external_advisory_id
     for update;

    if not found then
        return pg_catalog.jsonb_build_object('outcome', 'NOT_FOUND');
    end if;
    if v_current.review_status <> 'PENDING_REVIEW'
       or v_current.linked_warning_id is not null then
        return pg_catalog.jsonb_build_object(
            'outcome', 'REVIEW_CONFLICT',
            'review_status', v_current.review_status,
            'payload_version', v_current.payload_version
        );
    end if;
    if v_current.payload_version <> p_expected_payload_version then
        return pg_catalog.jsonb_build_object(
            'outcome', 'PAYLOAD_VERSION_CONFLICT',
            'review_status', v_current.review_status,
            'payload_version', v_current.payload_version
        );
    end if;

    -- Preserve the human-visible source/advisory state under the source and
    -- advisory locks. Later provider refreshes may change the current row.
    v_reviewed_snapshot := pg_catalog.jsonb_build_object(
        'source_code', v_source.source_code,
        'source_name', v_source.source_name,
        'external_reference_id', v_current.external_reference_id,
        'source_reference', v_current.source_reference,
        'title', v_current.title,
        'advisory_type', v_current.advisory_type,
        'hazard_type', v_current.hazard_type,
        'summary', v_current.summary,
        'issued_at', v_current.issued_at,
        'valid_until', v_current.valid_until,
        'payload_version', v_current.payload_version,
        'payload_hash', v_current.payload_hash
    );
    if pg_catalog.octet_length(v_reviewed_snapshot::text) > 98304 then
        raise exception using
            errcode = '22023',
            message = 'The reviewed advisory content exceeds the safe audit snapshot limit.';
    end if;

    v_reviewed_at := pg_catalog.clock_timestamp();

    update public.external_advisories
       set review_status = 'DISMISSED',
           reviewed_at = v_reviewed_at,
           reviewed_by_actor_reference = p_actor_reference,
           reviewed_payload_version = v_current.payload_version,
           reviewed_payload_hash = v_current.payload_hash
     where id = v_current.id;

    insert into public.external_advisory_review_history (
        external_advisory_id,
        event_type,
        actor_reference,
        occurred_at,
        resulting_review_status,
        payload_version,
        payload_hash,
        linked_warning_id,
        reviewed_snapshot,
        details
    ) values (
        v_current.id,
        'DISMISSED',
        p_actor_reference,
        v_reviewed_at,
        'DISMISSED',
        v_current.payload_version,
        v_current.payload_hash,
        null,
        v_reviewed_snapshot,
        pg_catalog.jsonb_build_object(
            'source_id', v_current.source_id,
            'external_reference_id', v_current.external_reference_id
        )
    );

    return pg_catalog.jsonb_build_object(
        'outcome', 'DISMISSED',
        'external_advisory_id', v_current.id,
        'review_status', 'DISMISSED',
        'payload_version', v_current.payload_version,
        'reviewed_at', v_reviewed_at,
        'linked_warning_id', null
    );
end;
$function$;

create function public.convert_module4_external_advisory_to_draft(
    p_external_advisory_id uuid,
    p_expected_payload_version integer,
    p_expected_source_id uuid,
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
as $function$
declare
    v_source_id uuid;
    v_source public.early_warning_sources%rowtype;
    v_current public.external_advisories%rowtype;
    v_created jsonb;
    v_warning_id uuid;
    v_reviewed_at timestamptz;
    v_reviewed_snapshot jsonb;
    v_persisted_status text;
    v_persisted_revision integer;
    v_created_event_count integer;
    v_matching_created_event_count integer;
begin
    p_actor_reference := btrim(coalesce(p_actor_reference, ''));
    perform public.module4_assert_actor_reference(p_actor_reference);

    if p_expected_payload_version is null or p_expected_payload_version < 1 then
        raise exception using
            errcode = '22023',
            message = 'Expected external advisory payload version is invalid.';
    end if;
    if p_expected_source_id is null then
        raise exception using
            errcode = '22023',
            message = 'Expected external advisory source is required.';
    end if;

    select advisory.source_id
      into v_source_id
      from public.external_advisories as advisory
     where advisory.id = p_external_advisory_id;

    if not found then
        return pg_catalog.jsonb_build_object('outcome', 'NOT_FOUND');
    end if;

    -- Match the Phase 4B.1 provider-lock order. This serializes review with a
    -- provider refresh without blocking synchronizations for other sources.
    select source.*
      into v_source
      from public.early_warning_sources as source
     where source.id = v_source_id
     for update;
    if not found then
        raise exception using errcode = 'P0001', message = 'External advisory source is missing.';
    end if;

    select advisory.*
      into v_current
      from public.external_advisories as advisory
     where advisory.id = p_external_advisory_id
     for update;

    if not found then
        return pg_catalog.jsonb_build_object('outcome', 'NOT_FOUND');
    end if;
    if v_current.review_status <> 'PENDING_REVIEW'
       or v_current.linked_warning_id is not null then
        return pg_catalog.jsonb_build_object(
            'outcome', 'REVIEW_CONFLICT',
            'review_status', v_current.review_status,
            'payload_version', v_current.payload_version
        );
    end if;
    if v_current.payload_version <> p_expected_payload_version then
        return pg_catalog.jsonb_build_object(
            'outcome', 'PAYLOAD_VERSION_CONFLICT',
            'review_status', v_current.review_status,
            'payload_version', v_current.payload_version
        );
    end if;
    if v_current.source_id <> p_expected_source_id then
        return pg_catalog.jsonb_build_object(
            'outcome', 'SOURCE_CONFLICT',
            'review_status', v_current.review_status,
            'payload_version', v_current.payload_version
        );
    end if;

    -- The snapshot comes only from the locked database rows, never caller data.
    v_reviewed_snapshot := pg_catalog.jsonb_build_object(
        'source_code', v_source.source_code,
        'source_name', v_source.source_name,
        'external_reference_id', v_current.external_reference_id,
        'source_reference', v_current.source_reference,
        'title', v_current.title,
        'advisory_type', v_current.advisory_type,
        'hazard_type', v_current.hazard_type,
        'summary', v_current.summary,
        'issued_at', v_current.issued_at,
        'valid_until', v_current.valid_until,
        'payload_version', v_current.payload_version,
        'payload_hash', v_current.payload_hash
    );
    if pg_catalog.octet_length(v_reviewed_snapshot::text) > 98304 then
        raise exception using
            errcode = '22023',
            message = 'The reviewed advisory content exceeds the safe audit snapshot limit.';
    end if;

    -- Reuse the authoritative Phase 4A transaction logic for validation,
    -- warning revision 1, affected areas, and the CREATED lifecycle event.
    v_created := public.create_module4_warning_draft(
        v_current.source_id,
        p_title,
        p_hazard_type,
        p_warning_level_id,
        p_summary,
        p_issued_at,
        p_valid_until,
        p_source_reference,
        p_areas,
        p_actor_reference
    );

    if v_created->>'outcome' is distinct from 'CREATED'
       or v_created->>'status' is distinct from 'DRAFT'
       or v_created->>'revision' is distinct from '1'
       or coalesce(v_created->>'id', '') !~
            '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$' then
        raise exception using
            errcode = 'P0001',
            message = 'Phase 4A draft creation returned an invalid result.';
    end if;
    v_warning_id := (v_created->>'id')::uuid;

    select warning.status, warning.revision
      into v_persisted_status, v_persisted_revision
      from public.early_warnings as warning
     where warning.id = v_warning_id
     for update;
    if not found
       or v_persisted_status is distinct from 'DRAFT'
       or v_persisted_revision is distinct from 1 then
        raise exception using
            errcode = 'P0001',
            message = 'Phase 4A draft was not persisted as DRAFT revision 1.';
    end if;

    select pg_catalog.count(*),
           pg_catalog.count(*) filter (
               where history.actor_reference = p_actor_reference
                 and history.resulting_status = 'DRAFT'
                 and history.resulting_revision = 1
           )
      into v_created_event_count, v_matching_created_event_count
      from public.early_warning_history as history
     where history.warning_id = v_warning_id
       and history.event_type = 'CREATED';
    if v_created_event_count <> 1 or v_matching_created_event_count <> 1 then
        raise exception using
            errcode = 'P0001',
            message = 'Phase 4A CREATED history was not persisted consistently.';
    end if;

    v_reviewed_at := pg_catalog.clock_timestamp();

    update public.external_advisories
       set review_status = 'DRAFT_CREATED',
           linked_warning_id = v_warning_id,
           reviewed_at = v_reviewed_at,
           reviewed_by_actor_reference = p_actor_reference,
           reviewed_payload_version = v_current.payload_version,
           reviewed_payload_hash = v_current.payload_hash
     where id = v_current.id;

    insert into public.external_advisory_review_history (
        external_advisory_id,
        event_type,
        actor_reference,
        occurred_at,
        resulting_review_status,
        payload_version,
        payload_hash,
        linked_warning_id,
        reviewed_snapshot,
        details
    ) values (
        v_current.id,
        'DRAFT_CREATED',
        p_actor_reference,
        v_reviewed_at,
        'DRAFT_CREATED',
        v_current.payload_version,
        v_current.payload_hash,
        v_warning_id,
        v_reviewed_snapshot,
        pg_catalog.jsonb_build_object(
            'source_id', v_current.source_id,
            'external_reference_id', v_current.external_reference_id
        )
    );

    return v_created || pg_catalog.jsonb_build_object(
        'outcome', 'DRAFT_CREATED',
        'external_advisory_id', v_current.id,
        'review_status', 'DRAFT_CREATED',
        'payload_version', v_current.payload_version,
        'reviewed_at', v_reviewed_at,
        'linked_warning_id', v_warning_id
    );
end;
$function$;

revoke all on function public.dismiss_module4_external_advisory(uuid, integer, text)
    from public, anon, authenticated, service_role;
grant execute on function public.dismiss_module4_external_advisory(uuid, integer, text)
    to service_role;

revoke all on function public.convert_module4_external_advisory_to_draft(
    uuid, integer, uuid, text, text, smallint, text, timestamptz,
    timestamptz, text, jsonb, text
) from public, anon, authenticated, service_role;
grant execute on function public.convert_module4_external_advisory_to_draft(
    uuid, integer, uuid, text, text, smallint, text, timestamptz,
    timestamptz, text, jsonb, text
) to service_role;

create function public.verify_module4_external_advisory_review_schema()
returns jsonb
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    with expected_columns(table_name, name, type_oid, is_not_null) as (
        values
            ('external_advisories', 'reviewed_at', pg_catalog.to_regtype('pg_catalog.timestamptz'), false),
            ('external_advisories', 'reviewed_by_actor_reference', pg_catalog.to_regtype('pg_catalog.text'), false),
            ('external_advisories', 'reviewed_payload_version', pg_catalog.to_regtype('pg_catalog.int4'), false),
            ('external_advisories', 'reviewed_payload_hash', pg_catalog.to_regtype('pg_catalog.text'), false),
            ('external_advisory_review_history', 'reviewed_snapshot', pg_catalog.to_regtype('pg_catalog.jsonb'), true)
    ),
    expected_checks(table_name, name) as (
        values
            ('external_advisories', 'external_advisories_review_actor_reference_check'),
            ('external_advisories', 'external_advisories_reviewed_payload_version_check'),
            ('external_advisories', 'external_advisories_reviewed_payload_hash_check'),
            ('external_advisories', 'external_advisories_review_state_check'),
            ('external_advisory_review_history', 'external_advisory_review_history_event_type_check'),
            ('external_advisory_review_history', 'external_advisory_review_history_actor_reference_check'),
            ('external_advisory_review_history', 'external_advisory_review_history_status_check'),
            ('external_advisory_review_history', 'external_advisory_review_history_event_status_pair_check'),
            ('external_advisory_review_history', 'external_advisory_review_history_payload_version_check'),
            ('external_advisory_review_history', 'external_advisory_review_history_payload_hash_check'),
            ('external_advisory_review_history', 'external_advisory_review_history_snapshot_check'),
            ('external_advisory_review_history', 'external_advisory_review_history_details_check')
    ),
    -- Compare complete deparsed CHECK expressions rather than names/keywords.
    -- Ignore formatting parentheses, whitespace, and built-in text/jsonb
    -- casts; keep every operator, literal, key, and clause in order.
    expected_review_definitions(table_name, name, definition) as (
        values
            ('external_advisories', 'external_advisories_review_state_check', $review_state$
                (
                    review_status = 'PENDING_REVIEW'
                    and linked_warning_id is null
                    and reviewed_at is null
                    and reviewed_by_actor_reference is null
                    and reviewed_payload_version is null
                    and reviewed_payload_hash is null
                )
                or (
                    review_status = 'DISMISSED'
                    and linked_warning_id is null
                    and reviewed_at is not null
                    and reviewed_by_actor_reference is not null
                    and reviewed_payload_version is not null
                    and reviewed_payload_hash is not null
                )
                or (
                    review_status = 'DRAFT_CREATED'
                    and linked_warning_id is not null
                    and reviewed_at is not null
                    and reviewed_by_actor_reference is not null
                    and reviewed_payload_version is not null
                    and reviewed_payload_hash is not null
                )
            $review_state$),
            ('external_advisory_review_history', 'external_advisory_review_history_snapshot_check', $review_snapshot$
                pg_catalog.jsonb_typeof(reviewed_snapshot) = 'object'
                and pg_catalog.octet_length(reviewed_snapshot::text) <= 98304
                and reviewed_snapshot ?& array[
                    'source_code', 'source_name', 'external_reference_id',
                    'source_reference', 'title', 'advisory_type', 'hazard_type',
                    'summary', 'issued_at', 'valid_until', 'payload_version', 'payload_hash'
                ]
                and reviewed_snapshot - array[
                    'source_code', 'source_name', 'external_reference_id',
                    'source_reference', 'title', 'advisory_type', 'hazard_type',
                    'summary', 'issued_at', 'valid_until', 'payload_version', 'payload_hash'
                ] = '{}'::jsonb
                and reviewed_snapshot->>'payload_version' is not distinct from payload_version::text
                and reviewed_snapshot->>'payload_hash' is not distinct from payload_hash
            $review_snapshot$)
    ),
    review_definition_checks as (
        select expected.name,
               coalesce(
                   actual.contype = 'c'
                   and actual.convalidated
                   and pg_catalog.lower(pg_catalog.regexp_replace(
                       pg_catalog.regexp_replace(
                           pg_catalog.replace(
                               pg_catalog.pg_get_expr(actual.conbin, actual.conrelid),
                               'pg_catalog.', ''
                           ),
                           '::(text|jsonb)', '', 'g'
                       ),
                       '[[:space:]()]', '', 'g'
                   )) = pg_catalog.lower(pg_catalog.regexp_replace(
                       pg_catalog.regexp_replace(
                           pg_catalog.replace(expected.definition, 'pg_catalog.', ''),
                           '::(text|jsonb)', '', 'g'
                       ),
                       '[[:space:]()]', '', 'g'
                   )),
                   false
               ) as is_valid
          from expected_review_definitions as expected
          left join pg_catalog.pg_class as relation
            on relation.oid = pg_catalog.to_regclass('public.' || expected.table_name)
          left join pg_catalog.pg_constraint as actual
            on actual.conrelid = relation.oid
           and actual.conname = expected.name
    ),
    expected_foreign_keys(table_name, name, delete_action) as (
        values
            ('external_advisory_review_history', 'external_advisory_review_history_advisory_id_fkey', 'r'),
            ('external_advisory_review_history', 'external_advisory_review_history_linked_warning_id_fkey', 'r')
    ),
    expected_indexes(table_name, name) as (
        values
            ('external_advisories', 'uq_external_advisories_linked_warning'),
            ('external_advisory_review_history', 'external_advisory_review_history_one_terminal_event'),
            ('external_advisory_review_history', 'idx_external_advisory_review_history_time'),
            ('external_advisory_review_history', 'idx_external_advisory_review_history_warning')
    ),
    expected_functions(signature) as (
        values
            ('public.dismiss_module4_external_advisory(uuid,integer,text)'),
            ('public.convert_module4_external_advisory_to_draft(uuid,integer,uuid,text,text,smallint,text,timestamptz,timestamptz,text,jsonb,text)'),
            ('public.verify_module4_external_advisory_review_schema()')
    ),
    actual_functions as (
        select expected.signature,
               procedure.oid,
               procedure.prosecdef,
               procedure.proconfig,
               procedure.proacl,
               procedure.proowner
          from expected_functions as expected
          left join pg_catalog.pg_proc as procedure
            on procedure.oid = pg_catalog.to_regprocedure(expected.signature)
    )
    select public.verify_module4_external_advisory_schema()
        || pg_catalog.jsonb_build_object(
            'review_columns_valid', not exists (
                select 1
                  from expected_columns as expected
                 where not exists (
                    select 1
                      from pg_catalog.pg_attribute as attribute
                      join pg_catalog.pg_class as relation on relation.oid = attribute.attrelid
                      join pg_catalog.pg_namespace as namespace on namespace.oid = relation.relnamespace
                     where namespace.nspname = 'public'
                       and relation.relname = expected.table_name
                       and attribute.attname = expected.name
                       and attribute.attnum > 0
                       and not attribute.attisdropped
                       and attribute.atttypid = expected.type_oid
                       and attribute.attnotnull = expected.is_not_null
                 )
            ),
            'review_history_table_exists', pg_catalog.to_regclass(
                'public.external_advisory_review_history'
            ) is not null,
            'review_history_rls_enabled', coalesce((
                select relation.relrowsecurity
                  from pg_catalog.pg_class as relation
                  join pg_catalog.pg_namespace as namespace on namespace.oid = relation.relnamespace
                 where namespace.nspname = 'public'
                   and relation.relname = 'external_advisory_review_history'
                   and relation.relkind = 'r'
            ), false),
            'review_constraints_valid', not exists (
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
            'review_state_constraint_valid', coalesce((
                select is_valid
                  from review_definition_checks
                 where name = 'external_advisories_review_state_check'
            ), false),
            'review_foreign_keys_valid', not exists (
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
            'review_indexes_valid', not exists (
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
            'review_linked_warning_unique_index_valid', exists (
                select 1
                  from pg_catalog.pg_index as index_metadata
                  join pg_catalog.pg_class as index_relation
                    on index_relation.oid = index_metadata.indexrelid
                  join pg_catalog.pg_class as table_relation
                    on table_relation.oid = index_metadata.indrelid
                  join pg_catalog.pg_namespace as namespace
                    on namespace.oid = table_relation.relnamespace
                 where namespace.nspname = 'public'
                   and table_relation.relname = 'external_advisories'
                   and index_relation.relname = 'uq_external_advisories_linked_warning'
                   and index_metadata.indisunique
                   and index_metadata.indisvalid
                   and index_metadata.indnkeyatts = 1
                   and pg_catalog.pg_get_indexdef(index_relation.oid, 1, true) = 'linked_warning_id'
                   and pg_catalog.pg_get_expr(index_metadata.indpred, index_metadata.indrelid)
                       in ('(linked_warning_id IS NOT NULL)', 'linked_warning_id IS NOT NULL')
            ),
            'review_one_terminal_event_unique_valid', exists (
                select 1
                  from pg_catalog.pg_constraint as constraint_metadata
                  join pg_catalog.pg_class as relation
                    on relation.oid = constraint_metadata.conrelid
                  join pg_catalog.pg_namespace as namespace
                    on namespace.oid = relation.relnamespace
                  join pg_catalog.pg_attribute as attribute
                    on attribute.attrelid = relation.oid
                   and attribute.attname = 'external_advisory_id'
                  join pg_catalog.pg_index as index_metadata
                    on index_metadata.indexrelid = constraint_metadata.conindid
                 where namespace.nspname = 'public'
                   and relation.relname = 'external_advisory_review_history'
                   and constraint_metadata.conname = 'external_advisory_review_history_one_terminal_event'
                   and constraint_metadata.contype = 'u'
                   and constraint_metadata.conkey = array[attribute.attnum]::smallint[]
                   and index_metadata.indisunique
                   and index_metadata.indisvalid
            ),
            'review_snapshot_constraint_valid', coalesce((
                select is_valid
                  from review_definition_checks
                 where name = 'external_advisory_review_history_snapshot_check'
            ), false),
            'review_service_role_privileges_restricted', (
                pg_catalog.has_table_privilege(
                    'service_role', 'public.external_advisory_review_history', 'SELECT'
                )
                and not pg_catalog.has_table_privilege(
                    'service_role', 'public.external_advisory_review_history', 'INSERT'
                )
                and not pg_catalog.has_table_privilege(
                    'service_role', 'public.external_advisory_review_history', 'UPDATE'
                )
                and not pg_catalog.has_table_privilege(
                    'service_role', 'public.external_advisory_review_history', 'DELETE'
                )
                and pg_catalog.has_table_privilege(
                    'service_role', 'public.external_advisories', 'SELECT'
                )
                and not pg_catalog.has_table_privilege(
                    'service_role', 'public.external_advisories', 'INSERT'
                )
                and not pg_catalog.has_table_privilege(
                    'service_role', 'public.external_advisories', 'UPDATE'
                )
                and not pg_catalog.has_table_privilege(
                    'service_role', 'public.external_advisories', 'DELETE'
                )
            ),
            'review_browser_table_privileges_revoked', not (
                pg_catalog.has_table_privilege('anon', 'public.external_advisory_review_history', 'SELECT')
                or pg_catalog.has_table_privilege('anon', 'public.external_advisory_review_history', 'INSERT')
                or pg_catalog.has_table_privilege('anon', 'public.external_advisory_review_history', 'UPDATE')
                or pg_catalog.has_table_privilege('anon', 'public.external_advisory_review_history', 'DELETE')
                or pg_catalog.has_table_privilege('authenticated', 'public.external_advisory_review_history', 'SELECT')
                or pg_catalog.has_table_privilege('authenticated', 'public.external_advisory_review_history', 'INSERT')
                or pg_catalog.has_table_privilege('authenticated', 'public.external_advisory_review_history', 'UPDATE')
                or pg_catalog.has_table_privilege('authenticated', 'public.external_advisory_review_history', 'DELETE')
            ),
            'review_public_table_privileges_revoked', not exists (
                select 1
                  from pg_catalog.pg_class as relation
                  join pg_catalog.pg_namespace as namespace on namespace.oid = relation.relnamespace
                  cross join lateral pg_catalog.aclexplode(
                    coalesce(relation.relacl, pg_catalog.acldefault('r', relation.relowner))
                  ) as privilege
                 where namespace.nspname = 'public'
                   and relation.relname = 'external_advisory_review_history'
                   and privilege.grantee = 0
                   and privilege.privilege_type in ('SELECT', 'INSERT', 'UPDATE', 'DELETE')
            ),
            'review_functions_hardened', not exists (
                select 1
                  from actual_functions as procedure
                 where procedure.oid is null
                    or not procedure.prosecdef
                    or not coalesce(
                        procedure.proconfig @> array['search_path=pg_catalog, public'],
                        false
                    )
            ),
            'review_functions_service_role_execute', not exists (
                select 1
                  from actual_functions as procedure
                 where procedure.oid is null
                    or not coalesce(
                        pg_catalog.has_function_privilege(
                            'service_role', procedure.oid, 'EXECUTE'
                        ),
                        false
                    )
            ),
            'review_functions_public_execute_revoked', not exists (
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
            'review_functions_anon_execute_revoked', not exists (
                select 1
                  from actual_functions as procedure
                 where procedure.oid is null
                    or coalesce(
                        pg_catalog.has_function_privilege('anon', procedure.oid, 'EXECUTE'),
                        false
                    )
            ),
            'review_functions_authenticated_execute_revoked', not exists (
                select 1
                  from actual_functions as procedure
                 where procedure.oid is null
                    or coalesce(
                        pg_catalog.has_function_privilege('authenticated', procedure.oid, 'EXECUTE'),
                        false
                    )
            ),
            'review_history_count', (
                select pg_catalog.count(*) from public.external_advisory_review_history
            )
        );
$function$;

revoke all on function public.verify_module4_external_advisory_review_schema()
    from public, anon, authenticated, service_role;
grant execute on function public.verify_module4_external_advisory_review_schema()
    to service_role;

notify pgrst, 'reload schema';

commit;
