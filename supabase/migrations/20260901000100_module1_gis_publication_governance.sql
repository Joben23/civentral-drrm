-- Phase P0 Module 1 publication governance. This migration publishes no data.
-- LIVE CHECK COMPATIBILITY (authoritative 2026-09-01 evidence): this migration
-- preserves dataset DRAFT/UNDER_REVIEW/PUBLISHED/ARCHIVED, center
-- DRAFT/PUBLISHED/ARCHIVED, route DRAFT/UNDER_REVIEW/APPROVED/SUSPENDED/ARCHIVED,
-- and the existing dataset category/hazard scope CHECK. It deliberately does
-- not drop or rewrite any pre-existing status or scope CHECK constraint.

begin;

do $preflight$
declare
    missing_columns text;
begin
    select string_agg(required.table_name || '.' || required.column_name, ', ')
    into missing_columns
    from (
        values
            ('dataset_sources', 'dataset_source_id'),
            ('dataset_sources', 'record_status'),
            ('hazard_types', 'hazard_type_id'),
            ('hazard_types', 'code'),
            ('dataset_versions', 'dataset_version_id'),
            ('dataset_versions', 'dataset_source_id'),
            ('dataset_versions', 'dataset_category'),
            ('dataset_versions', 'hazard_type_id'),
            ('dataset_versions', 'source_reference'),
            ('dataset_versions', 'publication_date'),
            ('dataset_versions', 'effective_from'),
            ('dataset_versions', 'effective_to'),
            ('dataset_versions', 'version_label'),
            ('dataset_versions', 'license'),
            ('dataset_versions', 'review_status'),
            ('dataset_versions', 'reviewed_by_civentral_user_id'),
            ('dataset_versions', 'reviewed_at'),
            ('dataset_versions', 'published_at'),
            ('barangays', 'boundary_dataset_version_id'),
            ('barangays', 'barangay_id'),
            ('barangays', 'barangay_code'),
            ('barangays', 'name'),
            ('barangays', 'district_code'),
            ('barangays', 'boundary_geometry'),
            ('barangays', 'record_status'),
            ('hazard_zones', 'dataset_version_id'),
            ('hazard_zones', 'hazard_zone_id'),
            ('hazard_zones', 'hazard_type_id'),
            ('hazard_zones', 'risk_level_id'),
            ('hazard_zones', 'geometry'),
            ('hazard_zones', 'effective_from'),
            ('hazard_zones', 'effective_to'),
            ('hazard_zones', 'classification_notes'),
            ('hazard_zones', 'record_status'),
            ('fault_features', 'dataset_version_id'),
            ('fault_features', 'fault_feature_id'),
            ('fault_features', 'hazard_type_id'),
            ('fault_features', 'feature_name'),
            ('fault_features', 'feature_class'),
            ('fault_features', 'geometry'),
            ('fault_features', 'effective_from'),
            ('fault_features', 'effective_to'),
            ('fault_features', 'notes'),
            ('fault_features', 'record_status'),
            ('evacuation_centers', 'evacuation_center_id'),
            ('evacuation_centers', 'name'),
            ('evacuation_centers', 'barangay_id'),
            ('evacuation_centers', 'location'),
            ('evacuation_centers', 'address'),
            ('evacuation_centers', 'capacity'),
            ('evacuation_centers', 'publication_status'),
            ('evacuation_centers', 'operational_status'),
            ('evacuation_centers', 'contact_phone'),
            ('evacuation_centers', 'accessibility_notes'),
            ('evacuation_centers', 'managing_office_name'),
            ('evacuation_centers', 'verified_by_civentral_user_id'),
            ('evacuation_centers', 'verified_at'),
            ('evacuation_routes', 'evacuation_route_id'),
            ('evacuation_routes', 'route_name'),
            ('evacuation_routes', 'origin_barangay_id'),
            ('evacuation_routes', 'origin_name'),
            ('evacuation_routes', 'origin_location'),
            ('evacuation_routes', 'destination_center_id'),
            ('evacuation_routes', 'route_geometry'),
            ('evacuation_routes', 'distance_meters'),
            ('evacuation_routes', 'route_status'),
            ('evacuation_routes', 'approved_by_civentral_user_id'),
            ('evacuation_routes', 'approved_at'),
            ('evacuation_routes', 'last_reviewed_at'),
            ('evacuation_routes', 'safety_notes')
    ) as required(table_name, column_name)
    left join information_schema.columns as actual
      on actual.table_schema = 'public'
     and actual.table_name = required.table_name
     and actual.column_name = required.column_name
    where actual.column_name is null;

    if missing_columns is not null then
        raise exception using
            errcode = '55000',
            message = 'Module 1 publication-governance preflight failed; missing columns: ' || missing_columns;
    end if;
end;
$preflight$;

alter table public.dataset_versions
    add column if not exists supersedes_dataset_version_id uuid null;
alter table public.evacuation_routes
    add column if not exists supersedes_route_id uuid null;

do $constraints$
begin
    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.dataset_versions'::regclass
          and conname = 'dataset_versions_review_lifecycle_check'
    ) then
        alter table public.dataset_versions
            add constraint dataset_versions_review_lifecycle_check
            check (
                (
                    review_status = 'DRAFT'
                    and reviewed_by_civentral_user_id is null
                    and reviewed_at is null
                    and published_at is null
                )
                or (
                    review_status = 'UNDER_REVIEW'
                    and published_at is null
                )
                or (
                    review_status in ('PUBLISHED', 'ARCHIVED')
                    and nullif(btrim(reviewed_by_civentral_user_id), '') is not null
                    and reviewed_at is not null
                    and published_at is not null
                    and publication_date is not null
                    and effective_from is not null
                    and nullif(btrim(source_reference), '') is not null
                    and nullif(btrim(license), '') is not null
                )
            )
            not valid;
    end if;

    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.dataset_versions'::regclass
          and conname = 'dataset_versions_review_chronology_check'
    ) then
        alter table public.dataset_versions
            add constraint dataset_versions_review_chronology_check
            check (
                (published_at is null or reviewed_at <= published_at)
                and (effective_to is null or effective_from is null or effective_to >= effective_from)
            )
            not valid;
    end if;

    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.dataset_versions'::regclass
          and conname = 'dataset_versions_supersedes_fkey'
    ) then
        alter table public.dataset_versions
            add constraint dataset_versions_supersedes_fkey
            foreign key (supersedes_dataset_version_id)
            references public.dataset_versions (dataset_version_id)
            on delete restrict
            not valid;
    end if;

    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.dataset_versions'::regclass
          and conname = 'dataset_versions_not_self_superseding_check'
    ) then
        alter table public.dataset_versions
            add constraint dataset_versions_not_self_superseding_check
            check (
                supersedes_dataset_version_id is null
                or supersedes_dataset_version_id <> dataset_version_id
            )
            not valid;
    end if;
end;
$constraints$;

alter table public.dataset_versions
    validate constraint dataset_versions_review_lifecycle_check;
alter table public.dataset_versions
    validate constraint dataset_versions_review_chronology_check;
alter table public.dataset_versions
    validate constraint dataset_versions_supersedes_fkey;
alter table public.dataset_versions
    validate constraint dataset_versions_not_self_superseding_check;

alter table public.barangays alter column record_status set default 'INACTIVE';
alter table public.hazard_zones alter column record_status set default 'INACTIVE';
alter table public.fault_features alter column record_status set default 'INACTIVE';

create unique index if not exists dataset_versions_one_successor_uidx
    on public.dataset_versions (supersedes_dataset_version_id)
    where supersedes_dataset_version_id is not null;

create unique index if not exists dataset_versions_one_published_scope_uidx
    on public.dataset_versions (
        dataset_category,
        coalesce(hazard_type_id, 0::smallint)
    )
    where review_status = 'PUBLISHED';

-- Deliberately independent of hazard_type_id so a malformed hazard value can
-- never be used to create a second PUBLISHED barangay catalog.
create unique index if not exists dataset_versions_one_published_barangay_catalog_uidx
    on public.dataset_versions ((1))
    where review_status = 'PUBLISHED'
      and dataset_category = 'BARANGAY_BOUNDARY';

comment on column public.dataset_versions.supersedes_dataset_version_id is
    'Immutable predecessor link for a replacement version. No successor is inferred or fabricated.';
comment on column public.evacuation_routes.supersedes_route_id is
    'Immutable predecessor link for a reviewed replacement route; approved definitions are never edited in place.';
comment on column public.evacuation_centers.capacity is
    'Zero means unknown or not recorded; it is not a verified zero-person capacity.';

create or replace function public.enforce_dataset_version_publication_workflow()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $function$
declare
    predecessor public.dataset_versions%rowtype;
    hazard_code text;
begin
    if new.dataset_category = 'BARANGAY_BOUNDARY' then
        if new.hazard_type_id is not null then
            raise exception using
                errcode = '23514',
                message = 'A BARANGAY_BOUNDARY dataset version must not have a hazard type.';
        end if;
    elsif new.dataset_category in ('HAZARD_ZONE', 'FAULT_FEATURE') then
        if new.hazard_type_id is null then
            raise exception using
                errcode = '23514',
                message = 'A hazard or fault dataset version requires a hazard type.';
        end if;

        select code into hazard_code
        from public.hazard_types
        where hazard_type_id = new.hazard_type_id;
        if not found
           or (new.dataset_category = 'FAULT_FEATURE'
               and hazard_code <> 'EARTHQUAKE_FAULT')
           or (new.dataset_category = 'HAZARD_ZONE'
               and hazard_code = 'EARTHQUAKE_FAULT') then
            raise exception using
                errcode = '23514',
                message = 'The dataset category and hazard type are not semantically compatible.';
        end if;
    else
        raise exception using
            errcode = '23514',
            message = 'The dataset category is not defined by the existing Module 1 model.';
    end if;

    if tg_op = 'INSERT' and new.review_status is distinct from 'DRAFT' then
        raise exception using
            errcode = '23514',
            message = 'A dataset version must enter the workflow as DRAFT.';
    end if;

    if tg_op = 'UPDATE' and new.review_status is distinct from old.review_status then
        if not (
            (old.review_status is not distinct from 'DRAFT'
             and new.review_status is not distinct from 'UNDER_REVIEW')
            or (old.review_status is not distinct from 'UNDER_REVIEW'
                and new.review_status is not distinct from 'PUBLISHED')
            or (old.review_status is not distinct from 'PUBLISHED'
                and new.review_status is not distinct from 'ARCHIVED')
        ) then
            raise exception using
                errcode = '23514',
                message = 'Dataset versions must follow DRAFT -> UNDER_REVIEW -> PUBLISHED -> ARCHIVED.';
        end if;
    end if;

    if tg_op = 'UPDATE' and old.review_status <> 'DRAFT'
       and new.supersedes_dataset_version_id is distinct from old.supersedes_dataset_version_id then
        raise exception using
            errcode = '23514',
            message = 'Dataset predecessor lineage is immutable after draft review begins.';
    end if;

    -- Lineage may be drafted provisionally, but it must be valid before the
    -- DRAFT -> UNDER_REVIEW freeze. The predecessor is locked before its chain
    -- is inspected; reviewed/archived lineage is immutable.
    if new.supersedes_dataset_version_id is not null
       and new.review_status in ('UNDER_REVIEW', 'PUBLISHED', 'ARCHIVED') then
        if new.supersedes_dataset_version_id = new.dataset_version_id then
            raise exception using
                errcode = '23514',
                message = 'A dataset version cannot supersede itself.';
        end if;

        select * into predecessor
        from public.dataset_versions
        where dataset_version_id = new.supersedes_dataset_version_id
        for share;

        if not found
           or predecessor.review_status is distinct from 'ARCHIVED'
           or predecessor.dataset_category is distinct from new.dataset_category
           or predecessor.hazard_type_id is distinct from new.hazard_type_id then
            raise exception using
                errcode = '23514',
                message = 'A reviewed successor requires an ARCHIVED predecessor in the same dataset and hazard scope.';
        end if;

        if exists (
            with recursive lineage(
                dataset_version_id,
                supersedes_dataset_version_id,
                path,
                cycle
            ) as (
                select
                    version.dataset_version_id,
                    version.supersedes_dataset_version_id,
                    array[new.dataset_version_id, version.dataset_version_id],
                    version.dataset_version_id = new.dataset_version_id
                from public.dataset_versions as version
                where version.dataset_version_id = new.supersedes_dataset_version_id

                union all

                select
                    predecessor_version.dataset_version_id,
                    predecessor_version.supersedes_dataset_version_id,
                    lineage.path || predecessor_version.dataset_version_id,
                    predecessor_version.dataset_version_id = any(lineage.path)
                from lineage
                join public.dataset_versions as predecessor_version
                  on predecessor_version.dataset_version_id =
                     lineage.supersedes_dataset_version_id
                where not lineage.cycle
            )
            select 1 from lineage where cycle
        ) then
            raise exception using
                errcode = '23514',
                message = 'Dataset-version predecessor lineage contains a cycle.';
        end if;
    end if;

    if tg_op = 'UPDATE' and old.review_status in ('PUBLISHED', 'ARCHIVED')
       and (
           new.dataset_version_id is distinct from old.dataset_version_id
           or new.dataset_source_id is distinct from old.dataset_source_id
           or new.dataset_category is distinct from old.dataset_category
           or new.hazard_type_id is distinct from old.hazard_type_id
           or new.source_reference is distinct from old.source_reference
           or new.publication_date is distinct from old.publication_date
           or new.effective_from is distinct from old.effective_from
           or new.effective_to is distinct from old.effective_to
           or new.version_label is distinct from old.version_label
           or new.license is distinct from old.license
           or new.reviewed_by_civentral_user_id is distinct from old.reviewed_by_civentral_user_id
           or new.reviewed_at is distinct from old.reviewed_at
           or new.published_at is distinct from old.published_at
           or new.supersedes_dataset_version_id is distinct from old.supersedes_dataset_version_id
       ) then
        raise exception using
            errcode = '23514',
            message = 'Published or archived dataset-version provenance is immutable; create a successor.';
    end if;

    if new.review_status = 'PUBLISHED' then
        perform 1
        from public.dataset_sources as source
        where source.dataset_source_id = new.dataset_source_id
          and source.record_status = 'ACTIVE'
        for share;
        if not found then
            raise exception using
                errcode = '23514',
                message = 'A dataset version cannot be PUBLISHED unless its source is ACTIVE.';
        end if;

    end if;

    if tg_op = 'UPDATE'
       and old.review_status = 'PUBLISHED'
       and new.review_status = 'ARCHIVED'
       and (
           exists (
               select 1 from public.barangays
               where boundary_dataset_version_id = old.dataset_version_id
                 and record_status = 'ACTIVE'
           )
           or exists (
               select 1 from public.hazard_zones
               where dataset_version_id = old.dataset_version_id
                 and record_status = 'ACTIVE'
           )
           or exists (
               select 1 from public.fault_features
               where dataset_version_id = old.dataset_version_id
                 and record_status = 'ACTIVE'
           )
       ) then
        raise exception using
            errcode = '23514',
            message = 'Deactivate published GIS child records before archiving their dataset version.';
    end if;

    return new;
end;
$function$;

drop trigger if exists dataset_versions_enforce_publication_workflow
    on public.dataset_versions;
create trigger dataset_versions_enforce_publication_workflow
before insert or update on public.dataset_versions
for each row execute function public.enforce_dataset_version_publication_workflow();

create or replace function public.prevent_reviewed_dataset_version_delete()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $function$
begin
    if old.review_status is distinct from 'DRAFT' then
        raise exception using
            errcode = '23514',
            message = 'Only DRAFT dataset versions may be deleted.';
    end if;
    return old;
end;
$function$;

drop trigger if exists dataset_versions_prevent_reviewed_delete
    on public.dataset_versions;
create trigger dataset_versions_prevent_reviewed_delete
before delete on public.dataset_versions
for each row execute function public.prevent_reviewed_dataset_version_delete();

create or replace function public.prevent_published_dataset_source_deactivation()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $function$
begin
    if old.record_status = 'ACTIVE'
       and new.record_status <> 'ACTIVE'
       and exists (
           select 1
           from public.dataset_versions
           where dataset_source_id = old.dataset_source_id
             and review_status = 'PUBLISHED'
       ) then
        raise exception using
            errcode = '23514',
            message = 'A source with a PUBLISHED dataset version must remain ACTIVE.';
    end if;
    return new;
end;
$function$;

drop trigger if exists dataset_sources_prevent_published_deactivation
    on public.dataset_sources;
create trigger dataset_sources_prevent_published_deactivation
before update of record_status on public.dataset_sources
for each row execute function public.prevent_published_dataset_source_deactivation();

do $existing_dataset_invariants$
begin
    if exists (
        select 1
        from public.dataset_versions as version
        left join public.hazard_types as hazard
          on hazard.hazard_type_id = version.hazard_type_id
        where not (
            (
                version.dataset_category = 'BARANGAY_BOUNDARY'
                and version.hazard_type_id is null
            )
            or (
                version.dataset_category = 'HAZARD_ZONE'
                and version.hazard_type_id is not null
                and hazard.hazard_type_id is not null
                and hazard.code <> 'EARTHQUAKE_FAULT'
            )
            or (
                version.dataset_category = 'FAULT_FEATURE'
                and version.hazard_type_id is not null
                and hazard.code = 'EARTHQUAKE_FAULT'
            )
        )
    ) then
        raise exception using
            errcode = '23514',
            message = 'Existing dataset versions violate category/hazard semantic scope; no rows were changed.';
    end if;

    if exists (
        select 1
        from public.dataset_versions as version
        left join public.dataset_sources as source
          on source.dataset_source_id = version.dataset_source_id
        where version.review_status = 'PUBLISHED'
          and (
              source.dataset_source_id is null
              or source.record_status <> 'ACTIVE'
              or nullif(btrim(version.reviewed_by_civentral_user_id), '') is null
              or version.reviewed_at is null
              or version.published_at is null
              or version.publication_date is null
              or version.effective_from is null
              or nullif(btrim(version.source_reference), '') is null
              or nullif(btrim(version.license), '') is null
          )
    ) then
        raise exception using
            errcode = '23514',
            message = 'Existing PUBLISHED dataset versions violate publication governance; no rows were changed.';
    end if;

    if exists (
        select 1
        from public.barangays as child
        left join public.dataset_versions as version
          on version.dataset_version_id = child.boundary_dataset_version_id
        left join public.dataset_sources as source
          on source.dataset_source_id = version.dataset_source_id
        where child.record_status = 'ACTIVE'
          and (
              version.review_status is distinct from 'PUBLISHED'
              or version.dataset_category is distinct from 'BARANGAY_BOUNDARY'
              or version.hazard_type_id is not null
              or source.record_status is distinct from 'ACTIVE'
          )
    ) or exists (
        select 1
        from public.hazard_zones as child
        left join public.dataset_versions as version
          on version.dataset_version_id = child.dataset_version_id
        left join public.dataset_sources as source
          on source.dataset_source_id = version.dataset_source_id
        left join public.hazard_types as hazard
          on hazard.hazard_type_id = version.hazard_type_id
        where child.record_status = 'ACTIVE'
          and (
              version.review_status is distinct from 'PUBLISHED'
              or version.dataset_category is distinct from 'HAZARD_ZONE'
              or version.hazard_type_id is distinct from child.hazard_type_id
              or hazard.code is not distinct from 'EARTHQUAKE_FAULT'
              or source.record_status is distinct from 'ACTIVE'
          )
    ) or exists (
        select 1
        from public.fault_features as child
        left join public.dataset_versions as version
          on version.dataset_version_id = child.dataset_version_id
        left join public.dataset_sources as source
          on source.dataset_source_id = version.dataset_source_id
        left join public.hazard_types as hazard
          on hazard.hazard_type_id = version.hazard_type_id
        where child.record_status = 'ACTIVE'
          and (
              version.review_status is distinct from 'PUBLISHED'
              or version.dataset_category is distinct from 'FAULT_FEATURE'
              or version.hazard_type_id is distinct from child.hazard_type_id
              or hazard.code is distinct from 'EARTHQUAKE_FAULT'
              or source.record_status is distinct from 'ACTIVE'
          )
    ) then
        raise exception using
            errcode = '23514',
            message = 'Existing ACTIVE GIS children are coupled to ineligible dataset versions; no rows were changed.';
    end if;
end;
$existing_dataset_invariants$;

create or replace function public.enforce_active_gis_dataset_coupling()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $function$
declare
    version_id uuid;
    expected_category text := tg_argv[1];
    version_category text;
    version_hazard_type_id smallint;
    record_hazard_type_id smallint;
begin
    if new.record_status <> 'ACTIVE' then
        return new;
    end if;

    version_id := nullif(to_jsonb(new) ->> tg_argv[0], '')::uuid;
    select version.dataset_category, version.hazard_type_id
    into version_category, version_hazard_type_id
    from public.dataset_versions as version
    join public.dataset_sources as source
      on source.dataset_source_id = version.dataset_source_id
     and source.record_status = 'ACTIVE'
    where version.dataset_version_id = version_id
      and version.review_status = 'PUBLISHED'
    for share of version, source;

    if not found or version_category <> expected_category then
        raise exception using
            errcode = '23514',
            message = 'An ACTIVE GIS record requires an ACTIVE source and a PUBLISHED dataset version in the matching category.';
    end if;

    if expected_category <> 'BARANGAY_BOUNDARY' then
        record_hazard_type_id := nullif(to_jsonb(new) ->> 'hazard_type_id', '')::smallint;
        if version_hazard_type_id is distinct from record_hazard_type_id then
            raise exception using
                errcode = '23514',
                message = 'An ACTIVE GIS record hazard type must match its PUBLISHED dataset version.';
        end if;
    end if;

    return new;
end;
$function$;

drop trigger if exists barangays_enforce_active_dataset_coupling on public.barangays;
create trigger barangays_enforce_active_dataset_coupling
before insert or update of record_status, boundary_dataset_version_id on public.barangays
for each row execute function public.enforce_active_gis_dataset_coupling(
    'boundary_dataset_version_id',
    'BARANGAY_BOUNDARY'
);

drop trigger if exists hazard_zones_enforce_active_dataset_coupling on public.hazard_zones;
create trigger hazard_zones_enforce_active_dataset_coupling
before insert or update of record_status, dataset_version_id, hazard_type_id on public.hazard_zones
for each row execute function public.enforce_active_gis_dataset_coupling(
    'dataset_version_id',
    'HAZARD_ZONE'
);

drop trigger if exists fault_features_enforce_active_dataset_coupling on public.fault_features;
create trigger fault_features_enforce_active_dataset_coupling
before insert or update of record_status, dataset_version_id, hazard_type_id on public.fault_features
for each row execute function public.enforce_active_gis_dataset_coupling(
    'dataset_version_id',
    'FAULT_FEATURE'
);

create or replace function public.protect_published_gis_child()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $function$
declare
    old_version_id uuid;
    old_version_status text;
begin
    old_version_id := nullif(to_jsonb(old) ->> tg_argv[0], '')::uuid;
    -- Concurrency/lock order: a child write first holds its own row, then takes
    -- SHARE on its parent. Parent workflow updates never lock child rows. Thus
    -- publication either waits for this child write to commit, or this trigger
    -- waits and observes PUBLISHED/ARCHIVED before allowing the child change.
    select review_status into old_version_status
    from public.dataset_versions
    where dataset_version_id = old_version_id
    for share;

    if old_version_status in ('PUBLISHED', 'ARCHIVED') then
        raise exception using
            errcode = '23514',
            message = 'Published or archived authoritative GIS fields are immutable; create a successor dataset version.';
    end if;

    if tg_op = 'DELETE' then
        return old;
    end if;
    return new;
end;
$function$;

drop trigger if exists barangays_protect_published_geometry on public.barangays;
drop trigger if exists barangays_protect_published_authority on public.barangays;
-- record_status is intentionally excluded from immutable authority fields so
-- ACTIVE -> INACTIVE deactivation remains available before controlled archive.
create trigger barangays_protect_published_authority
before update of
    barangay_id,
    barangay_code,
    name,
    district_code,
    boundary_geometry,
    boundary_dataset_version_id
or delete on public.barangays
for each row execute function public.protect_published_gis_child(
    'boundary_dataset_version_id'
);

drop trigger if exists hazard_zones_protect_published_geometry on public.hazard_zones;
drop trigger if exists hazard_zones_protect_published_authority on public.hazard_zones;
create trigger hazard_zones_protect_published_authority
before update of
    hazard_zone_id,
    hazard_type_id,
    risk_level_id,
    dataset_version_id,
    geometry,
    effective_from,
    effective_to,
    classification_notes
or delete on public.hazard_zones
for each row execute function public.protect_published_gis_child('dataset_version_id');

drop trigger if exists fault_features_protect_published_geometry on public.fault_features;
drop trigger if exists fault_features_protect_published_authority on public.fault_features;
create trigger fault_features_protect_published_authority
before update of
    fault_feature_id,
    hazard_type_id,
    dataset_version_id,
    feature_name,
    feature_class,
    geometry,
    effective_from,
    effective_to,
    notes
or delete on public.fault_features
for each row execute function public.protect_published_gis_child('dataset_version_id');

do $center_route_constraints$
begin
    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.evacuation_centers'::regclass
          and conname = 'evacuation_centers_capacity_semantics_check'
    ) then
        alter table public.evacuation_centers
            add constraint evacuation_centers_capacity_semantics_check
            check (capacity >= 0)
            not valid;
    end if;

    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.evacuation_centers'::regclass
          and conname = 'evacuation_centers_verification_evidence_check'
    ) then
        alter table public.evacuation_centers
            add constraint evacuation_centers_verification_evidence_check
            check (
                (
                    verified_by_civentral_user_id is null
                    and verified_at is null
                )
                or (
                    nullif(btrim(verified_by_civentral_user_id), '') is not null
                    and verified_at is not null
                )
            )
            not valid;
    end if;

    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.evacuation_centers'::regclass
          and conname = 'evacuation_centers_publication_prerequisites_check'
    ) then
        alter table public.evacuation_centers
            add constraint evacuation_centers_publication_prerequisites_check
            check (
                publication_status <> 'PUBLISHED'
                or (
                    operational_status is not null
                    and operational_status <> 'INACTIVE'
                    and nullif(btrim(verified_by_civentral_user_id), '') is not null
                    and verified_at is not null
                )
            )
            not valid;
    end if;

    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.evacuation_routes'::regclass
          and conname = 'evacuation_routes_operational_evidence_check'
    ) then
        alter table public.evacuation_routes
            add constraint evacuation_routes_operational_evidence_check
            check (
                route_status not in ('APPROVED', 'SUSPENDED')
                or (
                    nullif(btrim(approved_by_civentral_user_id), '') is not null
                    and approved_at is not null
                    and last_reviewed_at is not null
                    and destination_center_id is not null
                    and route_geometry is not null
                    and distance_meters is not null
                    and distance_meters > 0
                    and nullif(btrim(safety_notes), '') is not null
                )
            )
            not valid;
    end if;

    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.evacuation_routes'::regclass
          and conname = 'evacuation_routes_geometry_governance_check'
    ) then
        alter table public.evacuation_routes
            add constraint evacuation_routes_geometry_governance_check
            check (
                extensions.st_srid(route_geometry) = 4326
                and extensions.geometrytype(route_geometry) = 'LINESTRING'
                and not extensions.st_isempty(route_geometry)
                and extensions.st_isvalid(route_geometry)
            )
            not valid;
    end if;

    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.evacuation_routes'::regclass
          and conname = 'evacuation_routes_supersedes_fkey'
    ) then
        alter table public.evacuation_routes
            add constraint evacuation_routes_supersedes_fkey
            foreign key (supersedes_route_id)
            references public.evacuation_routes (evacuation_route_id)
            on delete restrict
            not valid;
    end if;

    if not exists (
        select 1 from pg_catalog.pg_constraint
        where conrelid = 'public.evacuation_routes'::regclass
          and conname = 'evacuation_routes_not_self_superseding_check'
    ) then
        alter table public.evacuation_routes
            add constraint evacuation_routes_not_self_superseding_check
            check (
                supersedes_route_id is null
                or supersedes_route_id <> evacuation_route_id
            )
            not valid;
    end if;
end;
$center_route_constraints$;

alter table public.evacuation_centers
    validate constraint evacuation_centers_capacity_semantics_check;
alter table public.evacuation_centers
    validate constraint evacuation_centers_verification_evidence_check;
alter table public.evacuation_centers
    validate constraint evacuation_centers_publication_prerequisites_check;
alter table public.evacuation_routes
    validate constraint evacuation_routes_operational_evidence_check;
alter table public.evacuation_routes
    validate constraint evacuation_routes_geometry_governance_check;
alter table public.evacuation_routes
    validate constraint evacuation_routes_supersedes_fkey;
alter table public.evacuation_routes
    validate constraint evacuation_routes_not_self_superseding_check;

create unique index if not exists evacuation_routes_one_successor_uidx
    on public.evacuation_routes (supersedes_route_id)
    where supersedes_route_id is not null;

do $existing_center_route_invariants$
begin
    if exists (
        select 1
        from public.evacuation_centers
        where publication_status = 'PUBLISHED'
          and (
              operational_status is null
              or operational_status = 'INACTIVE'
              or nullif(btrim(verified_by_civentral_user_id), '') is null
              or verified_at is null
          )
    ) then
        raise exception using
            errcode = '23514',
            message = 'Existing PUBLISHED evacuation centers lack verification evidence; no rows were changed.';
    end if;

    if exists (
        select 1
        from public.evacuation_routes as route
        left join public.evacuation_centers as center
          on center.evacuation_center_id = route.destination_center_id
        where route.route_status in ('APPROVED', 'SUSPENDED')
          and (
              route.distance_meters is null
              or route.distance_meters <= 0
              or route.route_geometry is null
              or route.destination_center_id is null
              or route.approved_at is null
              or route.last_reviewed_at is null
              or nullif(btrim(route.approved_by_civentral_user_id), '') is null
              or nullif(btrim(route.safety_notes), '') is null
              or center.publication_status is distinct from 'PUBLISHED'
              or center.operational_status is null
              or center.operational_status = 'INACTIVE'
              or center.verified_at is null
              or nullif(btrim(center.verified_by_civentral_user_id), '') is null
          )
    ) then
        raise exception using
            errcode = '23514',
            message = 'Existing APPROVED/SUSPENDED routes violate destination, distance, safety, or review governance; no rows were changed.';
    end if;
end;
$existing_center_route_invariants$;

create or replace function public.enforce_evacuation_center_publication_workflow()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $function$
begin
    if tg_op = 'INSERT' and new.publication_status is distinct from 'DRAFT' then
        raise exception using
            errcode = '23514',
            message = 'An evacuation center must enter publication workflow as DRAFT.';
    end if;

    if tg_op = 'DELETE' then
        if old.publication_status in ('PUBLISHED', 'ARCHIVED') then
            raise exception using
                errcode = '23514',
                message = 'A PUBLISHED or ARCHIVED evacuation center cannot be deleted.';
        end if;
        return old;
    end if;

    if tg_op = 'UPDATE'
       and new.publication_status is distinct from old.publication_status
       and not (
           (old.publication_status is not distinct from 'DRAFT'
            and new.publication_status is not distinct from 'PUBLISHED')
           or (old.publication_status is not distinct from 'PUBLISHED'
               and new.publication_status is not distinct from 'ARCHIVED')
       ) then
        raise exception using
            errcode = '23514',
            message = 'Evacuation centers must follow DRAFT -> PUBLISHED -> ARCHIVED.';
    end if;

    if tg_op = 'UPDATE'
       and old.publication_status in ('PUBLISHED', 'ARCHIVED')
       and (
            new.evacuation_center_id is distinct from old.evacuation_center_id
            or new.name is distinct from old.name
            or new.barangay_id is distinct from old.barangay_id
            or new.location is distinct from old.location
            or new.address is distinct from old.address
            or new.capacity is distinct from old.capacity
            or new.contact_phone is distinct from old.contact_phone
            or new.accessibility_notes is distinct from old.accessibility_notes
            or new.managing_office_name is distinct from old.managing_office_name
            or new.verified_by_civentral_user_id is distinct from old.verified_by_civentral_user_id
            or new.verified_at is distinct from old.verified_at
       ) then
        raise exception using
            errcode = '23514',
            message = 'Published or archived center identity, location, and verification evidence are immutable; create and verify a replacement center.';
    end if;

    if tg_op = 'UPDATE'
       and old.publication_status = 'PUBLISHED'
       and new.publication_status = 'ARCHIVED'
       and new.operational_status is distinct from 'INACTIVE' then
        raise exception using
            errcode = '23514',
            message = 'A center must be INACTIVE before it is ARCHIVED.';
    end if;

    if tg_op = 'UPDATE'
       and old.publication_status = 'ARCHIVED'
       and new.operational_status is distinct from old.operational_status then
        raise exception using
            errcode = '23514',
            message = 'An ARCHIVED center operational status is immutable.';
    end if;

    return new;
end;
$function$;

drop trigger if exists evacuation_centers_enforce_publication_workflow
    on public.evacuation_centers;
create trigger evacuation_centers_enforce_publication_workflow
before insert or update or delete on public.evacuation_centers
for each row execute function public.enforce_evacuation_center_publication_workflow();

create or replace function public.enforce_approved_route_governance()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $function$
declare
    predecessor public.evacuation_routes%rowtype;
begin
    if tg_op = 'INSERT' and new.route_status is distinct from 'DRAFT' then
        raise exception using
            errcode = '23514',
            message = 'An evacuation route must enter approval workflow as DRAFT.';
    end if;

    if tg_op = 'UPDATE'
       and new.route_status is distinct from old.route_status
       and not (
           (old.route_status is not distinct from 'DRAFT'
            and new.route_status is not distinct from 'UNDER_REVIEW')
           or (old.route_status is not distinct from 'UNDER_REVIEW'
               and new.route_status is not distinct from 'APPROVED')
           or (old.route_status is not distinct from 'APPROVED'
               and new.route_status is not distinct from 'SUSPENDED')
           or (old.route_status in ('APPROVED', 'SUSPENDED')
               and new.route_status is not distinct from 'ARCHIVED')
       ) then
        raise exception using
            errcode = '23514',
            message = 'Routes must follow DRAFT -> UNDER_REVIEW -> APPROVED -> SUSPENDED/ARCHIVED; replacement definitions require successors.';
    end if;

    if tg_op = 'UPDATE'
       and old.route_status is distinct from 'DRAFT'
       and new.supersedes_route_id is distinct from old.supersedes_route_id then
        raise exception using
            errcode = '23514',
            message = 'Route predecessor lineage is immutable after review begins.';
    end if;

    if new.supersedes_route_id is not null then
        select * into predecessor
        from public.evacuation_routes
        where evacuation_route_id = new.supersedes_route_id
        for share;
        if not found
           or predecessor.evacuation_route_id = new.evacuation_route_id
           or (
               new.route_status = 'DRAFT'
               and predecessor.route_status not in ('APPROVED', 'SUSPENDED', 'ARCHIVED')
           )
           or (
               new.route_status <> 'DRAFT'
               and predecessor.route_status is distinct from 'ARCHIVED'
           ) then
            raise exception using
                errcode = '23514',
                message = 'A reviewed route successor requires a different ARCHIVED predecessor.';
        end if;
    end if;

    if new.route_status not in ('APPROVED', 'SUSPENDED') then
        return new;
    end if;

    if new.destination_center_id is null
       or new.route_geometry is null
       or new.distance_meters is null
       or new.distance_meters <= 0
       or nullif(btrim(new.approved_by_civentral_user_id), '') is null
       or new.approved_at is null
       or new.last_reviewed_at is null
       or nullif(btrim(new.safety_notes), '') is null then
        raise exception using
            errcode = '23514',
            message = 'An APPROVED/SUSPENDED route requires approval and safety evidence, geometry, destination, and positive distance.';
    end if;

    perform 1
    from public.evacuation_centers
    where evacuation_center_id = new.destination_center_id
      and publication_status = 'PUBLISHED'
      and operational_status <> 'INACTIVE'
      and nullif(btrim(verified_by_civentral_user_id), '') is not null
      and verified_at is not null
    for share;

    if not found then
        raise exception using
            errcode = '23514',
            message = 'An APPROVED/SUSPENDED route requires a verified, operationally published destination center.';
    end if;

    return new;
end;
$function$;

drop trigger if exists evacuation_routes_enforce_approval_governance
    on public.evacuation_routes;
create trigger evacuation_routes_enforce_approval_governance
before insert or update of
    route_status,
    destination_center_id,
    route_geometry,
    distance_meters,
    approved_by_civentral_user_id,
    approved_at,
    last_reviewed_at,
    safety_notes,
    supersedes_route_id
on public.evacuation_routes
for each row execute function public.enforce_approved_route_governance();

create or replace function public.protect_approved_route_destination()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $function$
declare
    remains_eligible boolean;
begin
    if not exists (
        select 1
        from public.evacuation_routes
        where destination_center_id = old.evacuation_center_id
          and route_status in ('APPROVED', 'SUSPENDED')
    ) then
        if tg_op = 'DELETE' then return old; end if;
        return new;
    end if;

    if tg_op = 'DELETE' then
        raise exception using
            errcode = '23514',
            message = 'A destination center used by an APPROVED/SUSPENDED route cannot be deleted.';
    end if;

    remains_eligible := new.publication_status is not distinct from 'PUBLISHED'
        and new.operational_status is not null
        and new.operational_status <> 'INACTIVE'
        and nullif(btrim(new.verified_by_civentral_user_id), '') is not null
        and new.verified_at is not null;

    if remains_eligible is not true then
        raise exception using
            errcode = '23514',
            message = 'A destination center used by an APPROVED/SUSPENDED route must remain verified and operationally published.';
    end if;

    return new;
end;
$function$;

drop trigger if exists evacuation_centers_protect_approved_routes
    on public.evacuation_centers;
create trigger evacuation_centers_protect_approved_routes
before update of
    publication_status,
    operational_status,
    verified_by_civentral_user_id,
    verified_at
or delete on public.evacuation_centers
for each row execute function public.protect_approved_route_destination();

create or replace function public.protect_approved_route_definition()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $function$
begin
    if tg_op = 'DELETE'
       and old.route_status in ('APPROVED', 'SUSPENDED', 'ARCHIVED') then
        raise exception using
            errcode = '23514',
            message = 'An approved, suspended, or archived route cannot be deleted.';
    end if;

    if tg_op = 'UPDATE'
       and old.route_status in ('APPROVED', 'SUSPENDED', 'ARCHIVED')
       and (
           new.evacuation_route_id is distinct from old.evacuation_route_id
           or new.route_name is distinct from old.route_name
           or new.origin_barangay_id is distinct from old.origin_barangay_id
           or new.origin_name is distinct from old.origin_name
           or new.origin_location is distinct from old.origin_location
           or new.destination_center_id is distinct from old.destination_center_id
           or new.route_geometry is distinct from old.route_geometry
           or new.distance_meters is distinct from old.distance_meters
           or new.safety_notes is distinct from old.safety_notes
           or new.approved_by_civentral_user_id is distinct from old.approved_by_civentral_user_id
           or new.approved_at is distinct from old.approved_at
           or new.last_reviewed_at is distinct from old.last_reviewed_at
           or new.supersedes_route_id is distinct from old.supersedes_route_id
       ) then
        raise exception using
            errcode = '23514',
            message = 'Approved route evidence and safety definition are immutable through suspension/archive; create a reviewed successor route.';
    end if;
    if tg_op = 'DELETE' then return old; end if;
    return new;
end;
$function$;

drop trigger if exists evacuation_routes_protect_approved_definition
    on public.evacuation_routes;
create trigger evacuation_routes_protect_approved_definition
before update or delete on public.evacuation_routes
for each row execute function public.protect_approved_route_definition();

create or replace function public.is_complete_drrm_legacy_barangay_catalog()
returns boolean
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    with expected(barangay_code, name) as (
        select
            '13801' || lpad(number::text, 5, '0'),
            'Barangay ' || number::text
        from generate_series(1, 188) as series(number)
        where number <> 176
    ),
    actual as (
        select barangay_code, name
        from public.barangays
        where boundary_dataset_version_id =
                  'b386cd54-2288-423f-9b92-2092333333c1'::uuid
          and record_status = 'INACTIVE'
          and boundary_geometry is not null
    )
    select
        (select count(*) from actual) = 187
        and not exists (
            select 1
            from expected
            where not exists (
                select 1 from actual
                where actual.barangay_code = expected.barangay_code
                  and actual.name = expected.name
            )
        )
        and not exists (
            select 1
            from actual
            where not exists (
                select 1 from expected
                where expected.barangay_code = actual.barangay_code
                  and expected.name = actual.name
            )
        );
$function$;

create or replace function public.is_complete_drrm_barangay_catalog(
    p_dataset_version_id uuid
)
returns boolean
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    with expected(barangay_code, name) as (
        select
            '13801' || lpad(number::text, 5, '0'),
            'Barangay ' || number::text
        from generate_series(1, 188) as series(number)
        where number <> 176

        union all

        select
            '13801' || lpad((188 + replacement_number)::text, 5, '0'),
            'Barangay 176-' || chr(64 + replacement_number)
        from generate_series(1, 6) as series(replacement_number)
    ),
    governed_version as (
        select version.dataset_version_id
        from public.dataset_versions as version
        join public.dataset_sources as source
          on source.dataset_source_id = version.dataset_source_id
         and source.record_status = 'ACTIVE'
        where version.dataset_version_id = p_dataset_version_id
          and version.dataset_category = 'BARANGAY_BOUNDARY'
          and version.hazard_type_id is null
          and version.review_status = 'PUBLISHED'
          and nullif(btrim(version.reviewed_by_civentral_user_id), '') is not null
          and version.reviewed_at is not null
          and version.published_at is not null
          and version.publication_date is not null
          and version.effective_from is not null
          and nullif(btrim(version.source_reference), '') is not null
          and nullif(btrim(version.license), '') is not null
    ),
    actual as (
        select barangay_code, name
        from public.barangays
        where boundary_dataset_version_id = p_dataset_version_id
          and record_status = 'ACTIVE'
          and boundary_geometry is not null
    )
    select
        exists (select 1 from governed_version)
        and (select count(*) from actual) = 193
        and not exists (
            select 1
            from expected
            where not exists (
                select 1 from actual
                where actual.barangay_code = expected.barangay_code
                  and actual.name = expected.name
            )
        )
        and not exists (
            select 1
            from actual
            where not exists (
                select 1 from expected
                where expected.barangay_code = actual.barangay_code
                  and expected.name = actual.name
            )
        );
$function$;

create or replace function public.current_drrm_barangay_catalog_version_id()
returns uuid
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    select case
        when count(*) = 1 then (array_agg(version.dataset_version_id))[1]
        else null
    end
    from public.dataset_versions as version
    where version.dataset_category = 'BARANGAY_BOUNDARY'
      and version.hazard_type_id is null
      and version.review_status = 'PUBLISHED'
      and public.is_complete_drrm_barangay_catalog(version.dataset_version_id);
$function$;

create or replace function public.current_drrm_barangay_write_catalog_version_id()
returns uuid
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    select coalesce(
        public.current_drrm_barangay_catalog_version_id(),
        case
            when public.is_complete_drrm_legacy_barangay_catalog()
            then 'b386cd54-2288-423f-9b92-2092333333c1'::uuid
            else null
        end
    );
$function$;

create or replace function public.is_drrm_barangay_write_eligible(
    p_barangay_id uuid
)
returns boolean
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    with write_catalog as (
        select public.current_drrm_barangay_write_catalog_version_id() as dataset_version_id
    )
    select exists (
        select 1
        from public.barangays as barangay
        join write_catalog
          on write_catalog.dataset_version_id =
             barangay.boundary_dataset_version_id
        where barangay.barangay_id = p_barangay_id
          and barangay.name <> 'Barangay 176'
          and (
              (
                  write_catalog.dataset_version_id =
                      'b386cd54-2288-423f-9b92-2092333333c1'::uuid
                  and barangay.record_status = 'INACTIVE'
              )
              or (
                  write_catalog.dataset_version_id <>
                      'b386cd54-2288-423f-9b92-2092333333c1'::uuid
                  and barangay.record_status = 'ACTIVE'
              )
          )
    );
$function$;

create or replace function public.is_drrm_barangay_historical_reference_eligible(
    p_barangay_id uuid
)
returns boolean
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    select exists (
        select 1
        from public.barangays as barangay
        left join public.dataset_versions as version
          on version.dataset_version_id = barangay.boundary_dataset_version_id
        left join public.dataset_sources as source
          on source.dataset_source_id = version.dataset_source_id
        where barangay.barangay_id = p_barangay_id
          and barangay.name <> 'Barangay 176'
          and barangay.record_status in ('ACTIVE', 'INACTIVE')
          and (
              barangay.boundary_dataset_version_id =
                  'b386cd54-2288-423f-9b92-2092333333c1'::uuid
              or (
                  version.dataset_category = 'BARANGAY_BOUNDARY'
                  and version.hazard_type_id is null
                  and version.review_status in ('PUBLISHED', 'ARCHIVED')
                  and nullif(btrim(version.reviewed_by_civentral_user_id), '') is not null
                  and version.reviewed_at is not null
                  and version.published_at is not null
                  and version.publication_date is not null
                  and version.effective_from is not null
                  and nullif(btrim(version.source_reference), '') is not null
                  and nullif(btrim(version.license), '') is not null
                  and (
                      version.review_status = 'ARCHIVED'
                      or source.record_status = 'ACTIVE'
                  )
              )
          )
    );
$function$;

-- Backward-compatible name is historical-only. New-write paths below call the
-- explicitly stricter write predicate.
create or replace function public.is_drrm_barangay_reference_eligible(
    p_barangay_id uuid
)
returns boolean
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    select public.is_drrm_barangay_historical_reference_eligible(p_barangay_id);
$function$;

revoke all on function public.is_complete_drrm_legacy_barangay_catalog()
    from public, anon, authenticated;
revoke all on function public.is_complete_drrm_barangay_catalog(uuid)
    from public, anon, authenticated;
revoke all on function public.current_drrm_barangay_catalog_version_id()
    from public, anon, authenticated;
revoke all on function public.current_drrm_barangay_write_catalog_version_id()
    from public, anon, authenticated;
revoke all on function public.is_drrm_barangay_write_eligible(uuid)
    from public, anon, authenticated;
revoke all on function public.is_drrm_barangay_historical_reference_eligible(uuid)
    from public, anon, authenticated;
revoke all on function public.is_drrm_barangay_reference_eligible(uuid)
    from public, anon, authenticated;
grant execute on function public.is_drrm_barangay_write_eligible(uuid)
    to service_role;
grant execute on function public.is_drrm_barangay_historical_reference_eligible(uuid)
    to service_role;
grant execute on function public.is_drrm_barangay_reference_eligible(uuid)
    to service_role;

create or replace function public.validate_drrm_incident_barangay()
returns trigger
language plpgsql
security definer
set search_path = pg_catalog, public
as $function$
begin
    if new.barangay_id is null then
        return new;
    end if;

    if tg_op = 'UPDATE'
       and new.barangay_id is not distinct from old.barangay_id then
        return new;
    end if;

    if not public.is_drrm_barangay_write_eligible(new.barangay_id) then
        raise exception using
            errcode = '23514',
            message = 'The incident barangay reference is not in an eligible Caloocan catalog.';
    end if;

    return new;
end;
$function$;

drop trigger if exists drrm_incidents_validate_barangay on public.drrm_incidents;
create trigger drrm_incidents_validate_barangay
before insert or update of barangay_id on public.drrm_incidents
for each row execute function public.validate_drrm_incident_barangay();

do $module3_barangay_preflight$
begin
    if exists (
        select 1
        from public.drrm_incidents
        where barangay_id is not null
          and not public.is_drrm_barangay_historical_reference_eligible(barangay_id)
    ) then
        raise exception using
            errcode = '23514',
            message = 'Existing Module 3 incident barangay references are ineligible; no rows were changed.';
    end if;
end;
$module3_barangay_preflight$;

-- Preserve every citizen-submission control while replacing its legacy-only
-- barangay predicate with the shared publication-aware eligibility function.
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

    if p_barangay_id is not null
       and not public.is_drrm_barangay_write_eligible(p_barangay_id) then
        raise exception using errcode = '22023', message = 'The incident barangay reference is invalid.';
    end if;

    perform pg_catalog.pg_advisory_xact_lock(
        pg_catalog.hashtextextended(p_reporter_reference, 0)
    );

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
        reporter_reference,
        request_id,
        request_fingerprint,
        incident_id
    ) values (
        p_reporter_reference,
        p_request_id,
        p_request_fingerprint,
        inserted_incident.id
    );

    return jsonb_build_object(
        'success', true,
        'incident_number', inserted_incident.incident_number,
        'status', inserted_incident.status,
        'submitted_at', inserted_incident.reported_at,
        'idempotent_replay', false
    );
end;
$function$;

revoke all on function public.submit_drrm_citizen_incident(
    text, uuid, text, text, text, text, text, uuid, numeric, numeric
) from public, anon, authenticated;
grant execute on function public.submit_drrm_citizen_incident(
    text, uuid, text, text, text, text, text, uuid, numeric, numeric
) to service_role;

create or replace function public.validate_early_warning_area_barangay()
returns trigger
language plpgsql
security definer
set search_path = pg_catalog, public
as $function$
begin
    if tg_op = 'UPDATE'
       and new.scope_type is not distinct from old.scope_type
       and new.barangay_id is not distinct from old.barangay_id then
        return new;
    end if;

    if new.scope_type = 'BARANGAY'
       and not public.is_drrm_barangay_write_eligible(new.barangay_id) then
        raise exception using
            errcode = '23514',
            message = 'The warning barangay reference is not in an eligible Caloocan catalog.';
    end if;
    return new;
end;
$function$;

drop trigger if exists early_warning_areas_validate_barangay
    on public.early_warning_areas;
create trigger early_warning_areas_validate_barangay
before insert or update of scope_type, barangay_id on public.early_warning_areas
for each row execute function public.validate_early_warning_area_barangay();

do $module4_barangay_preflight$
begin
    if exists (
        select 1
        from public.early_warning_areas
        where scope_type = 'BARANGAY'
          and not public.is_drrm_barangay_historical_reference_eligible(barangay_id)
    ) then
        raise exception using
            errcode = '23514',
            message = 'Existing Module 4 warning barangay references are ineligible; no rows were changed.';
    end if;
end;
$module4_barangay_preflight$;

revoke all on function public.enforce_dataset_version_publication_workflow()
    from public, anon, authenticated;
revoke all on function public.prevent_reviewed_dataset_version_delete()
    from public, anon, authenticated;
revoke all on function public.prevent_published_dataset_source_deactivation()
    from public, anon, authenticated;
revoke all on function public.enforce_active_gis_dataset_coupling()
    from public, anon, authenticated;
revoke all on function public.protect_published_gis_child()
    from public, anon, authenticated;
revoke all on function public.enforce_evacuation_center_publication_workflow()
    from public, anon, authenticated;
revoke all on function public.enforce_approved_route_governance()
    from public, anon, authenticated;
revoke all on function public.protect_approved_route_destination()
    from public, anon, authenticated;
revoke all on function public.protect_approved_route_definition()
    from public, anon, authenticated;
revoke all on function public.validate_drrm_incident_barangay()
    from public, anon, authenticated;
revoke all on function public.validate_early_warning_area_barangay()
    from public, anon, authenticated;

create or replace function public.verify_module1_publication_governance()
returns jsonb
language sql
stable
security definer
set search_path = pg_catalog, public
as $function$
    select jsonb_build_object(
        'dataset_version_count', (select count(*) from public.dataset_versions),
        'draft_dataset_version_count', (
            select count(*) from public.dataset_versions where review_status = 'DRAFT'
        ),
        'non_draft_dataset_version_count', (
            select count(*) from public.dataset_versions where review_status <> 'DRAFT'
        ),
        'barangay_count', (select count(*) from public.barangays),
        'inactive_barangay_count', (
            select count(*) from public.barangays where record_status = 'INACTIVE'
        ),
        'hazard_zone_count', (select count(*) from public.hazard_zones),
        'inactive_hazard_zone_count', (
            select count(*) from public.hazard_zones where record_status = 'INACTIVE'
        ),
        'flood_hazard_zone_count', (
            select count(*) from public.hazard_zones where hazard_type_id = 1
        ),
        'landslide_hazard_zone_count', (
            select count(*) from public.hazard_zones where hazard_type_id = 2
        ),
        'fault_feature_count', (select count(*) from public.fault_features),
        'inactive_fault_feature_count', (
            select count(*) from public.fault_features where record_status = 'INACTIVE'
        ),
        'evacuation_center_count', (select count(*) from public.evacuation_centers),
        'draft_inactive_center_count', (
            select count(*)
            from public.evacuation_centers
            where publication_status = 'DRAFT'
              and operational_status = 'INACTIVE'
        ),
        'unverified_center_count', (
            select count(*)
            from public.evacuation_centers
            where verified_by_civentral_user_id is null
              and verified_at is null
        ),
        'evacuation_route_count', (select count(*) from public.evacuation_routes),
        'active_defaults_are_safe', (
            select count(*) = 3
            from pg_catalog.pg_attribute as attribute
            join pg_catalog.pg_attrdef as default_value
              on default_value.adrelid = attribute.attrelid
             and default_value.adnum = attribute.attnum
            where (
                    (attribute.attrelid = 'public.barangays'::regclass
                     and attribute.attname = 'record_status')
                 or (attribute.attrelid = 'public.hazard_zones'::regclass
                     and attribute.attname = 'record_status')
                 or (attribute.attrelid = 'public.fault_features'::regclass
                     and attribute.attname = 'record_status')
            )
              and pg_catalog.pg_get_expr(default_value.adbin, default_value.adrelid)
                  ilike '%INACTIVE%'
        ),
        'lineage_column_exists', exists (
            select 1
            from pg_catalog.pg_attribute
            where attrelid = 'public.dataset_versions'::regclass
              and attname = 'supersedes_dataset_version_id'
              and not attisdropped
        ),
        'route_lineage_column_exists', exists (
            select 1
            from pg_catalog.pg_attribute
            where attrelid = 'public.evacuation_routes'::regclass
              and attname = 'supersedes_route_id'
              and not attisdropped
        ),
        'governance_constraint_count', (
            select count(*)
            from pg_catalog.pg_constraint as constraint_record
            join (
                values
                    ('public.dataset_versions', 'dataset_versions_review_lifecycle_check'),
                    ('public.dataset_versions', 'dataset_versions_review_chronology_check'),
                    ('public.dataset_versions', 'dataset_versions_supersedes_fkey'),
                    ('public.dataset_versions', 'dataset_versions_not_self_superseding_check'),
                    ('public.evacuation_centers', 'evacuation_centers_capacity_semantics_check'),
                    ('public.evacuation_centers', 'evacuation_centers_verification_evidence_check'),
                    ('public.evacuation_centers', 'evacuation_centers_publication_prerequisites_check'),
                    ('public.evacuation_routes', 'evacuation_routes_operational_evidence_check'),
                    ('public.evacuation_routes', 'evacuation_routes_geometry_governance_check'),
                    ('public.evacuation_routes', 'evacuation_routes_supersedes_fkey'),
                    ('public.evacuation_routes', 'evacuation_routes_not_self_superseding_check')
            ) as expected(table_name, object_name)
              on constraint_record.conrelid = pg_catalog.to_regclass(expected.table_name)
             and constraint_record.conname = expected.object_name
        ),
        'governance_trigger_count', (
            select count(*)
            from pg_catalog.pg_trigger as trigger_record
            join (
                values
                    ('public.dataset_versions', 'dataset_versions_enforce_publication_workflow'),
                    ('public.dataset_versions', 'dataset_versions_prevent_reviewed_delete'),
                    ('public.dataset_sources', 'dataset_sources_prevent_published_deactivation'),
                    ('public.barangays', 'barangays_enforce_active_dataset_coupling'),
                    ('public.hazard_zones', 'hazard_zones_enforce_active_dataset_coupling'),
                    ('public.fault_features', 'fault_features_enforce_active_dataset_coupling'),
                    ('public.barangays', 'barangays_protect_published_authority'),
                    ('public.hazard_zones', 'hazard_zones_protect_published_authority'),
                    ('public.fault_features', 'fault_features_protect_published_authority'),
                    ('public.evacuation_centers', 'evacuation_centers_enforce_publication_workflow'),
                    ('public.evacuation_routes', 'evacuation_routes_enforce_approval_governance'),
                    ('public.evacuation_centers', 'evacuation_centers_protect_approved_routes'),
                    ('public.evacuation_routes', 'evacuation_routes_protect_approved_definition'),
                    ('public.drrm_incidents', 'drrm_incidents_validate_barangay'),
                    ('public.early_warning_areas', 'early_warning_areas_validate_barangay')
            ) as expected(table_name, object_name)
              on trigger_record.tgrelid = pg_catalog.to_regclass(expected.table_name)
             and trigger_record.tgname = expected.object_name
            where not trigger_record.tgisinternal
        )
    );
$function$;

revoke all on function public.verify_module1_publication_governance()
    from public, anon, authenticated;
grant execute on function public.verify_module1_publication_governance()
    to service_role;

comment on function public.is_drrm_barangay_write_eligible(uuid) is
    'Allows exactly one complete current write catalog: governed 193-row successor when ready, otherwise the exact controlled 187-row compatibility catalog.';
comment on function public.is_drrm_barangay_historical_reference_eligible(uuid) is
    'Resolves existing legacy and governed PUBLISHED/ARCHIVED barangay references without making them eligible for new writes.';
comment on function public.is_drrm_barangay_reference_eligible(uuid) is
    'Backward-compatible historical-reference predicate; new writes must use is_drrm_barangay_write_eligible.';
comment on function public.verify_module1_publication_governance() is
    'Restricted, read-only verification for Module 1 GIS publication governance and preserved inventory state.';

notify pgrst, 'reload schema';

commit;
