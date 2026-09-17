-- Forward-only fix for Phase 4B.2 catalog-verifier deparser equivalence.
-- No table, constraint, index, RPC mutation path, or data is changed.

begin;

create or replace function public.verify_module4_external_advisory_review_schema()
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
    -- PostgreSQL may deparse `a IS NOT DISTINCT FROM b` as
    -- `NOT (a) IS DISTINCT FROM b`. Normalize only the two reviewed
    -- payload equalities after harmless formatting/cast normalization;
    -- leave the size, key sets, subtraction, and other operators intact.
    review_definition_checks as (
        select expected.name,
               coalesce(
                   actual.contype = 'c'
                   and actual.convalidated
                   and pg_catalog.replace(
                       pg_catalog.replace(
                           pg_catalog.lower(pg_catalog.regexp_replace(
                               pg_catalog.regexp_replace(
                                   pg_catalog.replace(
                                       pg_catalog.pg_get_expr(actual.conbin, actual.conrelid),
                                       'pg_catalog.', ''
                                   ),
                                   '::(text|jsonb)', '', 'g'
                               ),
                               '[[:space:]()]', '', 'g'
                           )),
                           'notreviewed_snapshot->>''payload_version''isdistinctfrompayload_version',
                           'reviewed_snapshot->>''payload_version''isnotdistinctfrompayload_version'
                       ),
                       'notreviewed_snapshot->>''payload_hash''isdistinctfrompayload_hash',
                       'reviewed_snapshot->>''payload_hash''isnotdistinctfrompayload_hash'
                   ) = pg_catalog.lower(pg_catalog.regexp_replace(
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

commit;
