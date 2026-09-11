-- Phase 5B additive migration for Module 5 assistance request response workflow.
-- This migration is intentionally not applied in this workspace session.

create extension if not exists pgcrypto;

create table if not exists drrm_barangay_assistance_request_updates (
    id uuid primary key default gen_random_uuid(),
    request_id uuid not null references drrm_barangay_assistance_requests(id) on update cascade on delete restrict,
    from_status text not null check (from_status in ('PENDING','ACKNOWLEDGED','IN_PROGRESS','COMPLETED','CANCELLED')),
    to_status text not null check (to_status in ('PENDING','ACKNOWLEDGED','IN_PROGRESS','COMPLETED','CANCELLED')),
    response_note text null check (char_length(trim(coalesce(response_note, ''))) <= 1000),
    handled_by_reference text not null check (char_length(trim(handled_by_reference)) > 0),
    created_at timestamptz not null default now()
);

revoke all on table public.drrm_barangay_assistance_request_updates from public, anon, authenticated;
grant select on table public.drrm_barangay_assistance_request_updates to service_role;

-- defense in depth: no public policies, RLS enabled server-managed history table
alter table public.drrm_barangay_assistance_request_updates enable row level security;
revoke all on table public.drrm_barangay_assistance_request_updates from public, anon, authenticated;

create index if not exists idx_drrm_assistance_request_updates_request_created_at
    on drrm_barangay_assistance_request_updates (request_id, created_at desc, id desc);

create index if not exists idx_drrm_assistance_request_updates_to_status
    on drrm_barangay_assistance_request_updates (to_status);

create index if not exists idx_drrm_assistance_request_updates_handler
    on drrm_barangay_assistance_request_updates (handled_by_reference);

create or replace function public.transition_assistance_request(
    p_request_id uuid,
    p_expected_status text,
    p_target_status text,
    p_response_note text default null,
    p_handled_by_reference text default null
)
returns table (
    id uuid,
    barangay_id uuid,
    request_category text,
    priority text,
    description text,
    status text,
    requested_at timestamptz,
    requested_by_reference text,
    created_at timestamptz,
    updated_at timestamptz
)
language plpgsql
security definer
set search_path = public
as $$
declare
    v_request record;
    v_note text;
    v_handler text;
begin
    v_note := trim(coalesce(p_response_note, ''));
    v_handler := trim(coalesce(p_handled_by_reference, ''));

    if p_request_id is null then
        raise exception 'Assistance request is required.';
    end if;

    if p_expected_status is null then
        raise exception 'Expected status is required.';
    end if;

    if p_target_status is null then
        raise exception 'Target status is required.';
    end if;

    if v_handler = '' then
        raise exception 'Authenticated user is required.';
    end if;

    if p_expected_status not in ('PENDING','ACKNOWLEDGED','IN_PROGRESS','COMPLETED','CANCELLED')
       or p_target_status not in ('PENDING','ACKNOWLEDGED','IN_PROGRESS','COMPLETED','CANCELLED') then
        raise exception 'Illegal assistance request transition.';
    end if;

    if not (
        (p_expected_status = 'PENDING' and p_target_status = 'ACKNOWLEDGED')
        or (p_expected_status = 'PENDING' and p_target_status = 'CANCELLED')
        or (p_expected_status = 'ACKNOWLEDGED' and p_target_status = 'IN_PROGRESS')
        or (p_expected_status = 'ACKNOWLEDGED' and p_target_status = 'CANCELLED')
        or (p_expected_status = 'IN_PROGRESS' and p_target_status = 'COMPLETED')
        or (p_expected_status = 'IN_PROGRESS' and p_target_status = 'CANCELLED')
    ) then
        raise exception 'Illegal assistance request transition.';
    end if;

    if p_target_status in ('COMPLETED','CANCELLED') and v_note = '' then
        raise exception 'Response note is required for completed or cancelled requests.';
    end if;

    select r.* into v_request
    from public.drrm_barangay_assistance_requests as r
    where r.id = p_request_id
    for update;

    if not found then
        raise exception 'Assistance request not found.';
    end if;

    if v_request.status <> p_expected_status then
        raise exception 'Stale assistance request status.';
    end if;

    update public.drrm_barangay_assistance_requests as r
    set status = p_target_status,
        updated_at = now()
    where r.id = p_request_id;

    insert into public.drrm_barangay_assistance_request_updates(
        request_id,
        from_status,
        to_status,
        response_note,
        handled_by_reference,
        created_at
    ) values (
        p_request_id,
        v_request.status,
        p_target_status,
        v_note,
        v_handler,
        now()
    );

    return query
    select
        r.id,
        r.barangay_id,
        r.request_category,
        r.priority,
        r.description,
        r.status,
        r.requested_at,
        r.requested_by_reference,
        r.created_at,
        r.updated_at
    from public.drrm_barangay_assistance_requests as r
    where r.id = p_request_id;
end;
$$;

revoke all on function public.transition_assistance_request(uuid, text, text, text, text) from public, anon, authenticated;
grant execute on function public.transition_assistance_request(uuid, text, text, text, text) to service_role;
