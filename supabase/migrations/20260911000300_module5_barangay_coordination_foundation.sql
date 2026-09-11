-- Additive migration for Module 5 Phase 5A foundation.
-- Safe to inspect and apply later; not run in this workspace session.

create extension if not exists pgcrypto;

create table if not exists drrm_barangay_status_reports (
    id uuid primary key default gen_random_uuid(),
    barangay_id uuid not null references public.barangays(barangay_id) on update cascade on delete restrict,
    situation_level text not null check (situation_level in ('NORMAL','MONITORING','ELEVATED','CRITICAL')),
    affected_households integer not null default 0 check (affected_households >= 0),
    evacuees integer not null default 0 check (evacuees >= 0),
    access_condition text not null check (access_condition in ('ACCESSIBLE','PARTIALLY_BLOCKED','BLOCKED','UNKNOWN')),
    situation_summary text not null check (char_length(trim(situation_summary)) > 0),
    notes text null,
    reported_at timestamptz not null default now(),
    reported_by_reference text null,
    created_at timestamptz not null default now()
);

create table if not exists drrm_barangay_assistance_requests (
    id uuid primary key default gen_random_uuid(),
    barangay_id uuid not null references public.barangays(barangay_id) on update cascade on delete restrict,
    request_category text not null check (request_category in ('RELIEF_GOODS','RESCUE','MEDICAL','EVACUATION','EQUIPMENT','ROAD_ACCESS','INFORMATION','OTHER')),
    priority text not null check (priority in ('NORMAL','HIGH','URGENT')),
    description text not null check (char_length(trim(description)) > 0),
    status text not null default 'PENDING' check (status in ('PENDING','ACKNOWLEDGED','IN_PROGRESS','COMPLETED','CANCELLED')),
    requested_at timestamptz not null default now(),
    requested_by_reference text null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);

create index if not exists idx_drrm_status_reports_barangay_reported_at
    on drrm_barangay_status_reports (barangay_id, reported_at desc, created_at desc, id desc);

create index if not exists idx_drrm_status_reports_status_history
    on drrm_barangay_status_reports (reported_at desc, created_at desc, id desc);

create index if not exists idx_drrm_assistance_requests_barangay_requested_at
    on drrm_barangay_assistance_requests (barangay_id, requested_at desc);

create index if not exists idx_drrm_assistance_requests_status_priority
    on drrm_barangay_assistance_requests (status, priority);
