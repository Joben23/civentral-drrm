-- CIVENTRAL DRRM Module 2: Relief Goods Distribution Tracker
-- Phase 4A functional MVP foundation.

begin;

create table if not exists public.relief_items (
    id uuid primary key default gen_random_uuid(),
    item_name text not null,
    category text not null,
    unit text not null,
    current_stock numeric(14,3) not null default 0,
    reorder_level numeric(14,3) not null default 0,
    is_active boolean not null default true,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    constraint relief_items_name_required check (btrim(item_name) <> '' and char_length(item_name) <= 180),
    constraint relief_items_category_required check (btrim(category) <> '' and char_length(category) <= 100),
    constraint relief_items_unit_required check (btrim(unit) <> '' and char_length(unit) <= 40),
    constraint relief_items_stock_nonnegative check (current_stock >= 0),
    constraint relief_items_reorder_nonnegative check (reorder_level >= 0),
    constraint relief_items_name_unique unique (item_name)
);

create table if not exists public.relief_distributions (
    id uuid primary key default gen_random_uuid(),
    client_reference text not null unique,
    destination_type text not null,
    barangay_id uuid null references public.barangays (barangay_id) on delete restrict,
    evacuation_center_id uuid null references public.evacuation_centers (evacuation_center_id) on delete restrict,
    status text not null default 'RELEASED',
    notes text null,
    distributed_at timestamptz not null default now(),
    responsible_user_reference text not null,
    created_at timestamptz not null default now(),
    constraint relief_distributions_destination_type_check check (destination_type in ('BARANGAY', 'EVACUATION_CENTER')),
    constraint relief_distributions_destination_check check (
        (destination_type = 'BARANGAY' and barangay_id is not null and evacuation_center_id is null)
        or (destination_type = 'EVACUATION_CENTER' and evacuation_center_id is not null and barangay_id is null)
    ),
    constraint relief_distributions_status_check check (status in ('PREPARED', 'RELEASED', 'RECEIVED', 'CANCELLED')),
    constraint relief_distributions_actor_required check (btrim(responsible_user_reference) <> ''),
    constraint relief_distributions_notes_length check (notes is null or char_length(notes) <= 2000)
);

create table if not exists public.relief_distribution_items (
    id uuid primary key default gen_random_uuid(),
    distribution_id uuid not null references public.relief_distributions (id) on delete restrict,
    relief_item_id uuid not null references public.relief_items (id) on delete restrict,
    quantity numeric(14,3) not null,
    constraint relief_distribution_items_quantity_positive check (quantity > 0),
    constraint relief_distribution_items_unique_item unique (distribution_id, relief_item_id)
);

create table if not exists public.relief_stock_movements (
    id uuid primary key default gen_random_uuid(),
    relief_item_id uuid not null references public.relief_items (id) on delete restrict,
    movement_type text not null,
    quantity numeric(14,3) not null,
    distribution_id uuid null references public.relief_distributions (id) on delete restrict,
    source text null,
    reference_note text null,
    actor_reference text not null,
    created_at timestamptz not null default now(),
    constraint relief_stock_movements_type_check check (movement_type in ('STOCK_IN', 'DISTRIBUTION_RELEASE')),
    constraint relief_stock_movements_quantity_positive check (quantity > 0),
    constraint relief_stock_movements_distribution_check check (
        (movement_type = 'STOCK_IN' and distribution_id is null)
        or (movement_type = 'DISTRIBUTION_RELEASE' and distribution_id is not null)
    ),
    constraint relief_stock_movements_actor_required check (btrim(actor_reference) <> '')
);

create index if not exists relief_items_active_idx on public.relief_items (is_active, item_name);
create index if not exists relief_distributions_date_idx on public.relief_distributions (distributed_at desc);
create index if not exists relief_distribution_items_distribution_idx on public.relief_distribution_items (distribution_id);
create index if not exists relief_stock_movements_item_date_idx on public.relief_stock_movements (relief_item_id, created_at desc);

create or replace function public.set_module2_updated_at()
returns trigger language plpgsql set search_path = pg_catalog, public as $function$
begin new.updated_at = now(); return new; end;
$function$;

drop trigger if exists relief_items_set_updated_at on public.relief_items;
create trigger relief_items_set_updated_at before update on public.relief_items for each row execute function public.set_module2_updated_at();

create or replace function public.receive_relief_stock(
    p_item_id uuid, p_quantity numeric, p_actor_reference text,
    p_source text default null, p_reference_note text default null
)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public as $function$
declare item_row public.relief_items%rowtype;
begin
    if p_quantity is null or p_quantity <= 0 then raise exception 'INVALID_QUANTITY'; end if;
    select * into item_row from public.relief_items where id = p_item_id and is_active for update;
    if not found then raise exception 'ITEM_NOT_FOUND'; end if;
    update public.relief_items set current_stock = current_stock + p_quantity where id = p_item_id;
    insert into public.relief_stock_movements (relief_item_id, movement_type, quantity, source, reference_note, actor_reference)
    values (p_item_id, 'STOCK_IN', p_quantity, nullif(btrim(p_source), ''), nullif(btrim(p_reference_note), ''), p_actor_reference);
    return jsonb_build_object('item_id', p_item_id, 'current_stock', item_row.current_stock + p_quantity);
end;
$function$;

create or replace function public.release_relief_distribution(
    p_client_reference text, p_destination_type text, p_destination_id uuid,
    p_notes text, p_actor_reference text, p_items jsonb
)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public as $function$
declare distribution_id uuid; item_entry jsonb; item_id uuid; item_quantity numeric; item_row public.relief_items%rowtype;
begin
    if exists (select 1 from public.relief_distributions where client_reference = p_client_reference) then
        select id into distribution_id from public.relief_distributions where client_reference = p_client_reference;
        return jsonb_build_object('distribution_id', distribution_id, 'status', 'RELEASED', 'idempotent', true);
    end if;
    if p_client_reference is null or btrim(p_client_reference) = '' then raise exception 'REFERENCE_REQUIRED'; end if;
    if p_destination_type not in ('BARANGAY', 'EVACUATION_CENTER') then raise exception 'INVALID_DESTINATION'; end if;
    if p_destination_type = 'BARANGAY' and not exists (select 1 from public.barangays where barangay_id = p_destination_id) then raise exception 'DESTINATION_NOT_FOUND'; end if;
    if p_destination_type = 'EVACUATION_CENTER' and not exists (select 1 from public.evacuation_centers where evacuation_center_id = p_destination_id) then raise exception 'DESTINATION_NOT_FOUND'; end if;
    if jsonb_typeof(p_items) <> 'array' or jsonb_array_length(p_items) = 0 then raise exception 'ITEMS_REQUIRED'; end if;
    insert into public.relief_distributions (client_reference, destination_type, barangay_id, evacuation_center_id, status, notes, responsible_user_reference)
    values (p_client_reference, p_destination_type, case when p_destination_type = 'BARANGAY' then p_destination_id end, case when p_destination_type = 'EVACUATION_CENTER' then p_destination_id end, 'RELEASED', nullif(btrim(p_notes), ''), p_actor_reference)
    returning id into distribution_id;
    for item_entry in select value from jsonb_array_elements(p_items) loop
        item_id := (item_entry->>'item_id')::uuid;
        item_quantity := (item_entry->>'quantity')::numeric;
        if item_quantity is null or item_quantity <= 0 then raise exception 'INVALID_QUANTITY'; end if;
        select * into item_row from public.relief_items where id = item_id and is_active for update;
        if not found then raise exception 'ITEM_NOT_FOUND'; end if;
        if item_row.current_stock < item_quantity then raise exception 'INSUFFICIENT_STOCK'; end if;
        insert into public.relief_distribution_items (distribution_id, relief_item_id, quantity) values (distribution_id, item_id, item_quantity);
        update public.relief_items set current_stock = current_stock - item_quantity where id = item_id;
        insert into public.relief_stock_movements (relief_item_id, movement_type, quantity, distribution_id, actor_reference) values (item_id, 'DISTRIBUTION_RELEASE', item_quantity, distribution_id, p_actor_reference);
    end loop;
    return jsonb_build_object('distribution_id', distribution_id, 'status', 'RELEASED', 'idempotent', false);
exception when invalid_text_representation or numeric_value_out_of_range then
    raise exception 'INVALID_QUANTITY';
end;
$function$;

alter table public.relief_items enable row level security;
alter table public.relief_distributions enable row level security;
alter table public.relief_distribution_items enable row level security;
alter table public.relief_stock_movements enable row level security;
revoke all on table public.relief_items, public.relief_distributions, public.relief_distribution_items, public.relief_stock_movements from public, anon, authenticated;
grant select, insert, update on table public.relief_items to service_role;
grant select, insert on table public.relief_distributions, public.relief_distribution_items, public.relief_stock_movements to service_role;
grant execute on function public.receive_relief_stock(uuid, numeric, text, text, text) to service_role;
grant execute on function public.release_relief_distribution(text, text, uuid, text, text, jsonb) to service_role;

commit;