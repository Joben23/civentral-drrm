-- PHASE 5C MIGRATION: NOT APPLIED
-- Server-managed DRRM barangay assignment table for authoritative scoping.
-- This migration file is intentionally created but must not be executed.

create extension if not exists pgcrypto;

create table if not exists public.drrm_barangay_user_assignments (
    id uuid primary key default gen_random_uuid(),
    user_reference text not null check (trim(user_reference) <> '' and user_reference = btrim(user_reference)),
    barangay_id uuid not null,
    is_active boolean not null default true,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    constraint drrm_barangay_user_assignments_barangay_fk
        foreign key (barangay_id)
        references public.barangays(barangay_id)
        on update cascade
        on delete restrict
);

create unique index if not exists drrm_barangay_user_assignments_one_active_assignment_per_user_reference
    on public.drrm_barangay_user_assignments (user_reference)
    where is_active = true;

alter table public.drrm_barangay_user_assignments
    enable row level security;

revoke all on table public.drrm_barangay_user_assignments from public, anon, authenticated;

grant select on table public.drrm_barangay_user_assignments to service_role;

create or replace function public.set_drrm_barangay_user_assignment(
    p_user_reference text,
    p_barangay_id uuid
)
returns public.drrm_barangay_user_assignments
language plpgsql
security definer
set search_path = public
as $$
declare
    v_user_reference text := btrim(p_user_reference);
    v_row public.drrm_barangay_user_assignments;
begin
    if v_user_reference = '' then
        raise exception 'User reference is required.';
    end if;

    if not exists (select 1 from public.barangays where barangay_id = p_barangay_id) then
        raise exception 'Barangay is required.';
    end if;

    update public.drrm_barangay_user_assignments
       set is_active = false,
           updated_at = now()
     where user_reference = v_user_reference
       and is_active = true;

    insert into public.drrm_barangay_user_assignments (
        user_reference,
        barangay_id,
        is_active,
        created_at,
        updated_at
    ) values (
        v_user_reference,
        p_barangay_id,
        true,
        now(),
        now()
    )
    returning * into v_row;

    return v_row;
end;
$$;

create or replace function public.deactivate_drrm_barangay_user_assignment(
    p_user_reference text
)
returns public.drrm_barangay_user_assignments
language plpgsql
security definer
set search_path = public
as $$
declare
    v_user_reference text := btrim(p_user_reference);
    v_row public.drrm_barangay_user_assignments;
begin
    if v_user_reference = '' then
        raise exception 'User reference is required.';
    end if;

    update public.drrm_barangay_user_assignments
       set is_active = false,
           updated_at = now()
     where user_reference = v_user_reference
       and is_active = true
     returning * into v_row;

    return v_row;
end;
$$;

revoke all on function public.set_drrm_barangay_user_assignment(text, uuid) from public, anon, authenticated;
revoke all on function public.deactivate_drrm_barangay_user_assignment(text) from public, anon, authenticated;

grant execute on function public.set_drrm_barangay_user_assignment(text, uuid) to service_role;
grant execute on function public.deactivate_drrm_barangay_user_assignment(text) to service_role;

comment on table public.drrm_barangay_user_assignments is 'Authoritative CIVentral application user_reference to public.barangays scoping rows for DRRM Module 5. External user_reference; no auth.users FK.';
comment on constraint drrm_barangay_user_assignments_barangay_fk on public.drrm_barangay_user_assignments is 'Authoritative relational FK stays barangay_id -> public.barangays(barangay_id).';
