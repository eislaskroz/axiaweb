-- AXIA Tickets - Notas de servicio y evidencia fotográfica
-- Ejecutar DESPUÉS de 02_portal_admin.sql y 03_responsables.sql.

create table if not exists public.ticket_service_notes (
    id uuid primary key default gen_random_uuid(),
    ticket_id uuid not null references public.tickets(id) on delete cascade,
    author_id uuid null references public.profiles(id) on delete set null,
    notes text not null check (char_length(btrim(notes)) between 1 and 5000),
    created_at timestamptz not null default now()
);
create index if not exists idx_ticket_service_notes_ticket
on public.ticket_service_notes(ticket_id, created_at desc);

create table if not exists public.ticket_evidence (
    id uuid primary key default gen_random_uuid(),
    ticket_id uuid not null references public.tickets(id) on delete cascade,
    note_id uuid not null references public.ticket_service_notes(id) on delete cascade,
    uploaded_by uuid null references public.profiles(id) on delete set null,
    storage_path text not null unique,
    file_name text not null,
    mime_type text not null check (mime_type in ('image/jpeg','image/png','image/webp')),
    file_size bigint not null check (file_size > 0 and file_size <= 8388608),
    created_at timestamptz not null default now()
);
create index if not exists idx_ticket_evidence_ticket
on public.ticket_evidence(ticket_id, created_at desc);
create index if not exists idx_ticket_evidence_note
on public.ticket_evidence(note_id, created_at asc);

alter table public.ticket_service_notes enable row level security;
alter table public.ticket_evidence enable row level security;

drop policy if exists "ticket_service_notes_admin_select" on public.ticket_service_notes;
create policy "ticket_service_notes_admin_select"
on public.ticket_service_notes for select to authenticated
using (public.is_admin_axia());

drop policy if exists "ticket_service_notes_admin_insert" on public.ticket_service_notes;
create policy "ticket_service_notes_admin_insert"
on public.ticket_service_notes for insert to authenticated
with check (public.is_admin_axia() and author_id = auth.uid());

drop policy if exists "ticket_evidence_admin_select" on public.ticket_evidence;
create policy "ticket_evidence_admin_select"
on public.ticket_evidence for select to authenticated
using (public.is_admin_axia());

drop policy if exists "ticket_evidence_admin_insert" on public.ticket_evidence;
create policy "ticket_evidence_admin_insert"
on public.ticket_evidence for insert to authenticated
with check (public.is_admin_axia() and uploaded_by = auth.uid());

grant select, insert on public.ticket_service_notes to authenticated;
grant select, insert on public.ticket_evidence to authenticated;

-- Bucket privado para fotografías de evidencia.
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
    'ticket-evidence',
    'ticket-evidence',
    false,
    8388608,
    array['image/jpeg','image/png','image/webp']
)
on conflict (id) do update set
    public=false,
    file_size_limit=excluded.file_size_limit,
    allowed_mime_types=excluded.allowed_mime_types;

-- Solo administradores AXIA autenticados pueden gestionar objetos de este bucket.
drop policy if exists "ticket_evidence_storage_admin_select" on storage.objects;
create policy "ticket_evidence_storage_admin_select"
on storage.objects for select to authenticated
using (bucket_id='ticket-evidence' and public.is_admin_axia());

drop policy if exists "ticket_evidence_storage_admin_insert" on storage.objects;
create policy "ticket_evidence_storage_admin_insert"
on storage.objects for insert to authenticated
with check (bucket_id='ticket-evidence' and public.is_admin_axia());

drop policy if exists "ticket_evidence_storage_admin_delete" on storage.objects;
create policy "ticket_evidence_storage_admin_delete"
on storage.objects for delete to authenticated
using (bucket_id='ticket-evidence' and public.is_admin_axia());

-- Añade al historial una marca cuando se documenta una atención.
create or replace function public.audit_ticket_service_note()
returns trigger language plpgsql security definer set search_path=public as $$
begin
    insert into public.ticket_history(ticket_id,changed_by,action,old_value,new_value)
    values(new.ticket_id,new.author_id,'Nota de servicio',null,left(new.notes,180));
    return new;
end; $$;

drop trigger if exists trg_audit_ticket_service_note on public.ticket_service_notes;
create trigger trg_audit_ticket_service_note
after insert on public.ticket_service_notes
for each row execute function public.audit_ticket_service_note();
