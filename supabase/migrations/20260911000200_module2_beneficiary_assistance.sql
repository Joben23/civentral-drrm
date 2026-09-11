-- CIVENTRAL DRRM Module 2: Phase 4B beneficiary assistance monitoring.
-- This migration records assistance evidence without changing central stock.

begin;

create table if not exists public.relief_beneficiaries (
    id uuid primary key default gen_random_uuid(),
    household_head_name text not null,
    barangay_id uuid not null references public.barangays (barangay_id) on delete restrict,
    household_size integer not null,
    contact_number text null,
    location_notes text null,
    registration_notes text null,
    created_at timestamptz not null default now(),
    created_by_reference text not null,
    constraint relief_beneficiaries_head_required check (btrim(household_head_name) <> '' and char_length(household_head_name) <= 180),
    constraint relief_beneficiaries_household_size_positive check (household_size > 0 and household_size <= 1000),
    constraint relief_beneficiaries_contact_length check (contact_number is null or char_length(contact_number) <= 40),
    constraint relief_beneficiaries_location_notes_length check (location_notes is null or char_length(location_notes) <= 1000),
    constraint relief_beneficiaries_registration_notes_length check (registration_notes is null or char_length(registration_notes) <= 2000),
    constraint relief_beneficiaries_creator_required check (btrim(created_by_reference) <> '')
);

create table if not exists public.relief_distribution_beneficiaries (
    id uuid primary key default gen_random_uuid(),
    beneficiary_id uuid not null references public.relief_beneficiaries (id) on delete restrict,
    distribution_id uuid not null references public.relief_distributions (id) on delete restrict,
    served_at timestamptz not null default now(),
    served_by_reference text not null,
    notes text null,
    constraint relief_distribution_beneficiaries_unique_link unique (beneficiary_id, distribution_id),
    constraint relief_distribution_beneficiaries_served_by_required check (btrim(served_by_reference) <> ''),
    constraint relief_distribution_beneficiaries_notes_length check (notes is null or char_length(notes) <= 2000)
);

create table if not exists public.relief_distribution_beneficiary_items (
    id uuid primary key default gen_random_uuid(),
    assistance_id uuid not null references public.relief_distribution_beneficiaries (id) on delete restrict,
    distribution_item_id uuid not null references public.relief_distribution_items (id) on delete restrict,
    quantity_received numeric(14,3) not null,
    constraint relief_distribution_beneficiary_items_quantity_positive check (quantity_received > 0),
    constraint relief_distribution_beneficiary_items_unique_item unique (assistance_id, distribution_item_id)
);

create index if not exists relief_beneficiaries_barangay_idx on public.relief_beneficiaries (barangay_id, created_at desc);
create index if not exists relief_distribution_beneficiaries_beneficiary_idx on public.relief_distribution_beneficiaries (beneficiary_id, served_at desc);
create index if not exists relief_distribution_beneficiaries_distribution_idx on public.relief_distribution_beneficiaries (distribution_id);
create index if not exists relief_distribution_beneficiary_items_assistance_idx on public.relief_distribution_beneficiary_items (assistance_id);
create index if not exists relief_distribution_beneficiary_items_distribution_item_idx on public.relief_distribution_beneficiary_items (distribution_item_id);

alter table public.relief_beneficiaries enable row level security;
alter table public.relief_distribution_beneficiaries enable row level security;
alter table public.relief_distribution_beneficiary_items enable row level security;
revoke all on table public.relief_beneficiaries, public.relief_distribution_beneficiaries from public, anon, authenticated;
revoke all on table public.relief_distribution_beneficiary_items from public, anon, authenticated;
grant select, insert on table public.relief_beneficiaries to service_role;
grant select, insert on table public.relief_distribution_beneficiaries to service_role;
grant select, insert on table public.relief_distribution_beneficiary_items to service_role;

create or replace function public.record_relief_beneficiary_assistance(
    p_beneficiary_id uuid,
    p_distribution_id uuid,
    p_served_by_reference text,
    p_notes text,
    p_items jsonb
)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public as $function$
declare
    assistance_id uuid;
    item_entry jsonb;
    v_distribution_item_id uuid;
    requested_quantity numeric;
    released_quantity numeric;
    allocated_quantity numeric;
begin
    if p_items is null or jsonb_typeof(p_items) <> 'array' or jsonb_array_length(p_items) = 0 then
        raise exception 'ITEMS_REQUIRED';
    end if;
    if not exists (select 1 from public.relief_beneficiaries where id = p_beneficiary_id) then
        raise exception 'BENEFICIARY_NOT_FOUND';
    end if;
    perform 1 from public.relief_distributions where id = p_distribution_id and status = 'RELEASED' for update;
    if not found then raise exception 'RELEASED_DISTRIBUTION_REQUIRED'; end if;
    if exists (select 1 from public.relief_distribution_beneficiaries where beneficiary_id = p_beneficiary_id and distribution_id = p_distribution_id) then
        raise exception 'DUPLICATE_ASSISTANCE';
    end if;
    insert into public.relief_distribution_beneficiaries (beneficiary_id, distribution_id, served_by_reference, notes)
    values (p_beneficiary_id, p_distribution_id, p_served_by_reference, nullif(btrim(p_notes), ''))
    returning id into assistance_id;
    for item_entry in select value from jsonb_array_elements(p_items) loop
        v_distribution_item_id := (item_entry->>'distribution_item_id')::uuid;
        requested_quantity := (item_entry->>'quantity_received')::numeric;
        if requested_quantity is null or requested_quantity <= 0 then raise exception 'INVALID_QUANTITY'; end if;
        select rdi.quantity into released_quantity from public.relief_distribution_items as rdi where rdi.id = v_distribution_item_id and rdi.distribution_id = p_distribution_id for update;
        if not found then raise exception 'ITEM_NOT_IN_DISTRIBUTION'; end if;
        select coalesce(sum(rdbi.quantity_received), 0) into allocated_quantity from public.relief_distribution_beneficiary_items as rdbi where rdbi.distribution_item_id = v_distribution_item_id;
        if allocated_quantity + requested_quantity > released_quantity then raise exception 'ALLOCATION_EXCEEDS_RELEASED'; end if;
        insert into public.relief_distribution_beneficiary_items (assistance_id, distribution_item_id, quantity_received)
        values (assistance_id, v_distribution_item_id, requested_quantity);
    end loop;
    return jsonb_build_object('id', assistance_id, 'beneficiary_id', p_beneficiary_id, 'distribution_id', p_distribution_id);
exception when invalid_text_representation or numeric_value_out_of_range then
    raise exception 'INVALID_QUANTITY';
end;
$function$;
grant execute on function public.record_relief_beneficiary_assistance(uuid, uuid, text, text, jsonb) to service_role;

commit;