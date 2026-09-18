-- CIVENTRAL DRRM Module 4 Phase 4C.1.
-- Read receipts refer to existing ACTIVATED lifecycle history, not copied events.
-- No warning, provider delivery, or recipient contact data is created here.

begin;

create table public.module4_warning_notification_reads (
    id uuid primary key default pg_catalog.gen_random_uuid(),
    activation_history_id uuid not null,
    citizen_reference text not null,
    read_at timestamptz not null default pg_catalog.clock_timestamp(),
    constraint module4_warning_notification_reads_activation_fkey
        foreign key (activation_history_id)
        references public.early_warning_history(id)
        on delete restrict,
    constraint module4_warning_notification_reads_citizen_check check (
        citizen_reference ~ '^CITIZEN:[1-9][0-9]*$'
        and pg_catalog.char_length(citizen_reference) <= 200
    ),
    constraint module4_warning_notification_reads_one_per_citizen_event
        unique (activation_history_id, citizen_reference)
);

comment on table public.module4_warning_notification_reads is
    'Per-citizen in-app read receipts for Module 4 ACTIVATED history events; no delivery or contact data.';

create index idx_module4_warning_notification_reads_citizen_event
    on public.module4_warning_notification_reads (citizen_reference, activation_history_id);

alter table public.module4_warning_notification_reads enable row level security;
revoke all on table public.module4_warning_notification_reads
    from public, anon, authenticated, service_role;
grant select on table public.module4_warning_notification_reads to service_role;

create function public.mark_module4_warning_notification_read(
    p_activation_history_id uuid,
    p_citizen_reference text
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $function$
declare
    v_history public.early_warning_history%rowtype;
    v_warning public.early_warnings%rowtype;
    v_read_at timestamptz;
    v_inserted boolean := false;
    v_as_of timestamptz;
begin
    p_citizen_reference := pg_catalog.btrim(coalesce(p_citizen_reference, ''));
    if p_citizen_reference !~ '^CITIZEN:[1-9][0-9]*$'
       or pg_catalog.char_length(p_citizen_reference) > 200 then
        raise exception using errcode = '22023', message = 'A trusted citizen reference is required.';
    end if;
    if p_activation_history_id is null then
        raise exception using errcode = '22023', message = 'An activation event identifier is required.';
    end if;

    select history.* into v_history
      from public.early_warning_history as history
     where history.id = p_activation_history_id
       and history.event_type = 'ACTIVATED'
       and history.resulting_status = 'ACTIVE';
    if not found then
        return pg_catalog.jsonb_build_object('outcome', 'NOT_ELIGIBLE');
    end if;

    -- PHP checks the full public projection first. Locking the warning here
    -- closes the cancellation/expiry race before recording a receipt.
    select warning.* into v_warning
      from public.early_warnings as warning
     where warning.id = v_history.warning_id
     for update;
    v_as_of := pg_catalog.clock_timestamp();
    if not found
       or v_warning.status is distinct from 'ACTIVE'
       or v_warning.revision is distinct from v_history.resulting_revision
       or v_warning.issued_at > v_as_of
       or (v_warning.valid_until is not null and v_warning.valid_until <= v_as_of)
       or not exists (
            select 1 from public.early_warning_sources as source
             where source.id = v_warning.source_id
               and source.is_active = true
               and source.source_code in ('PAGASA', 'PHIVOLCS', 'NDRRMC', 'CIVENTRAL')
       )
       or not exists (
            select 1 from public.risk_levels as level
             where level.risk_level_id = v_warning.warning_level_id
               and level.is_active = true
               and level.code in ('LOW', 'MODERATE', 'HIGH', 'CRITICAL')
       ) then
        return pg_catalog.jsonb_build_object('outcome', 'NOT_ELIGIBLE');
    end if;

    insert into public.module4_warning_notification_reads (
        activation_history_id, citizen_reference, read_at
    ) values (
        p_activation_history_id, p_citizen_reference, pg_catalog.clock_timestamp()
    )
    on conflict (activation_history_id, citizen_reference) do nothing
    returning read_at into v_read_at;
    v_inserted := found;

    if not v_inserted then
        select receipt.read_at into v_read_at
          from public.module4_warning_notification_reads as receipt
         where receipt.activation_history_id = p_activation_history_id
           and receipt.citizen_reference = p_citizen_reference;
    end if;
    if v_read_at is null then
        raise exception using errcode = 'P0001', message = 'Warning read receipt could not be resolved.';
    end if;

    return pg_catalog.jsonb_build_object(
        'outcome', case when v_inserted then 'MARKED_READ' else 'ALREADY_READ' end,
        'notification_event_id', p_activation_history_id,
        'read_at', v_read_at
    );
end;
$function$;

revoke all on function public.mark_module4_warning_notification_read(uuid, text)
    from public, anon, authenticated, service_role;
grant execute on function public.mark_module4_warning_notification_read(uuid, text)
    to service_role;

create function public.verify_module4_warning_notification_reads_schema()
returns jsonb
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    with expected_columns(name, type_oid) as (
        values
            ('id', 'pg_catalog.uuid'::pg_catalog.regtype),
            ('activation_history_id', 'pg_catalog.uuid'::pg_catalog.regtype),
            ('citizen_reference', 'pg_catalog.text'::pg_catalog.regtype),
            ('read_at', 'pg_catalog.timestamptz'::pg_catalog.regtype)
    ),
    target as (
        select relation.oid, relation.relrowsecurity, relation.relacl, relation.relowner
          from pg_catalog.pg_class as relation
          join pg_catalog.pg_namespace as namespace on namespace.oid = relation.relnamespace
         where namespace.nspname = 'public'
           and relation.relname = 'module4_warning_notification_reads'
           and relation.relkind = 'r'
    ),
    expected_functions(signature) as (
        values
            ('public.mark_module4_warning_notification_read(uuid,text)'),
            ('public.verify_module4_warning_notification_reads_schema()')
    ),
    actual_functions as (
        select expected.signature, procedure.oid, procedure.prosecdef,
               procedure.proconfig, procedure.proacl, procedure.proowner
          from expected_functions as expected
          left join pg_catalog.pg_proc as procedure
            on procedure.oid = pg_catalog.to_regprocedure(expected.signature)
    )
    select pg_catalog.jsonb_build_object(
        'table_exists', (select pg_catalog.count(*) = 1 from target),
        'columns_valid', not exists (
            select 1 from expected_columns as expected
             where not exists (
                select 1 from target
                join pg_catalog.pg_attribute as attribute on attribute.attrelid = target.oid
                 where attribute.attname = expected.name
                   and attribute.attnum > 0
                   and not attribute.attisdropped
                   and attribute.atttypid = expected.type_oid
                   and attribute.attnotnull
             )
        ),
        'id_default_valid', exists (
            select 1 from target
            join pg_catalog.pg_attribute as attribute on attribute.attrelid = target.oid
            join pg_catalog.pg_attrdef as column_default
              on column_default.adrelid = attribute.attrelid
             and column_default.adnum = attribute.attnum
             where attribute.attname = 'id'
               and pg_catalog.pg_get_expr(column_default.adbin, column_default.adrelid)
                   in ('gen_random_uuid()', 'pg_catalog.gen_random_uuid()')
        ),
        'read_at_default_valid', exists (
            select 1 from target
            join pg_catalog.pg_attribute as attribute on attribute.attrelid = target.oid
            join pg_catalog.pg_attrdef as column_default
              on column_default.adrelid = attribute.attrelid
             and column_default.adnum = attribute.attnum
             where attribute.attname = 'read_at'
               and pg_catalog.pg_get_expr(column_default.adbin, column_default.adrelid)
                   in ('clock_timestamp()', 'pg_catalog.clock_timestamp()')
        ),
        'activation_fk_restrict_valid', exists (
            select 1 from target
            join pg_catalog.pg_constraint as constraint_metadata
              on constraint_metadata.conrelid = target.oid
             where constraint_metadata.conname = 'module4_warning_notification_reads_activation_fkey'
               and constraint_metadata.contype = 'f'
               and constraint_metadata.confrelid = 'public.early_warning_history'::pg_catalog.regclass
               and constraint_metadata.conkey = array[
                   (select attribute.attnum from pg_catalog.pg_attribute as attribute
                     where attribute.attrelid = target.oid
                       and attribute.attname = 'activation_history_id'
                       and not attribute.attisdropped)
               ]::smallint[]
               and constraint_metadata.confkey = array[
                   (select attribute.attnum from pg_catalog.pg_attribute as attribute
                     where attribute.attrelid = constraint_metadata.confrelid
                       and attribute.attname = 'id'
                       and not attribute.attisdropped)
               ]::smallint[]
               and constraint_metadata.confdeltype = 'r'
               and constraint_metadata.convalidated
        ),
        'one_receipt_unique_valid', exists (
            select 1 from target
            join pg_catalog.pg_constraint as constraint_metadata
              on constraint_metadata.conrelid = target.oid
            join pg_catalog.pg_index as index_metadata
              on index_metadata.indexrelid = constraint_metadata.conindid
             where constraint_metadata.conname = 'module4_warning_notification_reads_one_per_citizen_event'
               and constraint_metadata.contype = 'u'
               and constraint_metadata.convalidated
               and index_metadata.indisunique
               and index_metadata.indisvalid
               and index_metadata.indisready
               and index_metadata.indnkeyatts = 2
               and index_metadata.indnatts = 2
               and index_metadata.indpred is null
               and pg_catalog.pg_get_indexdef(index_metadata.indexrelid, 1, true) = 'activation_history_id'
               and pg_catalog.pg_get_indexdef(index_metadata.indexrelid, 2, true) = 'citizen_reference'
        ),
        'citizen_reference_check_valid', exists (
            select 1 from target
            join pg_catalog.pg_constraint as constraint_metadata
              on constraint_metadata.conrelid = target.oid
             where constraint_metadata.conname = 'module4_warning_notification_reads_citizen_check'
               and constraint_metadata.contype = 'c'
               and constraint_metadata.convalidated
               and pg_catalog.replace(pg_catalog.regexp_replace(
                   pg_catalog.regexp_replace(
                       pg_catalog.replace(
                           pg_catalog.pg_get_expr(constraint_metadata.conbin, constraint_metadata.conrelid),
                           'pg_catalog.', ''
                       ),
                       '::text', '', 'g'
                   ),
                   '[[:space:]()]', '', 'g'
               ), 'AND', 'and') = pg_catalog.regexp_replace(
                   'citizen_reference ~ ''^CITIZEN:[1-9][0-9]*$'' and char_length(citizen_reference) <= 200',
                   '[[:space:]()]', '', 'g'
               )
        ),
        'rls_enabled', coalesce((select relrowsecurity from target), false),
        'service_role_select_only',
            pg_catalog.has_table_privilege('service_role', 'public.module4_warning_notification_reads', 'SELECT')
            and not pg_catalog.has_table_privilege('service_role', 'public.module4_warning_notification_reads', 'INSERT')
            and not pg_catalog.has_table_privilege('service_role', 'public.module4_warning_notification_reads', 'UPDATE')
            and not pg_catalog.has_table_privilege('service_role', 'public.module4_warning_notification_reads', 'DELETE'),
        'browser_table_privileges_absent', not (
            pg_catalog.has_table_privilege('anon', 'public.module4_warning_notification_reads', 'SELECT')
            or pg_catalog.has_table_privilege('anon', 'public.module4_warning_notification_reads', 'INSERT')
            or pg_catalog.has_table_privilege('anon', 'public.module4_warning_notification_reads', 'UPDATE')
            or pg_catalog.has_table_privilege('anon', 'public.module4_warning_notification_reads', 'DELETE')
            or pg_catalog.has_table_privilege('authenticated', 'public.module4_warning_notification_reads', 'SELECT')
            or pg_catalog.has_table_privilege('authenticated', 'public.module4_warning_notification_reads', 'INSERT')
            or pg_catalog.has_table_privilege('authenticated', 'public.module4_warning_notification_reads', 'UPDATE')
            or pg_catalog.has_table_privilege('authenticated', 'public.module4_warning_notification_reads', 'DELETE')
        ),
        'public_table_privileges_absent', not exists (
            select 1 from target
            cross join lateral pg_catalog.aclexplode(
                coalesce(target.relacl, pg_catalog.acldefault('r', target.relowner))
            ) as privilege
             where privilege.grantee = 0
               and privilege.privilege_type in ('SELECT', 'INSERT', 'UPDATE', 'DELETE')
        ),
        'functions_hardened', not exists (
            select 1 from actual_functions
             where oid is null or not prosecdef
                or not coalesce(proconfig @> array['search_path=pg_catalog, public'], false)
        ),
        'service_role_execute', not exists (
            select 1 from actual_functions
             where oid is null
                or not coalesce(
                    pg_catalog.has_function_privilege('service_role', oid, 'EXECUTE'), false
                )
        ),
        'browser_execute_absent', not exists (
            select 1 from actual_functions
             where oid is null
                or pg_catalog.has_function_privilege('anon', oid, 'EXECUTE')
                or pg_catalog.has_function_privilege('authenticated', oid, 'EXECUTE')
        ),
        'public_execute_absent', not exists (
            select 1 from actual_functions
            cross join lateral pg_catalog.aclexplode(
                coalesce(proacl, pg_catalog.acldefault('f', proowner))
            ) as privilege
             where privilege.grantee = 0 and privilege.privilege_type = 'EXECUTE'
        ),
        'read_count', (select pg_catalog.count(*) from public.module4_warning_notification_reads)
    );
$function$;

revoke all on function public.verify_module4_warning_notification_reads_schema()
    from public, anon, authenticated, service_role;
grant execute on function public.verify_module4_warning_notification_reads_schema()
    to service_role;

notify pgrst, 'reload schema';

commit;
