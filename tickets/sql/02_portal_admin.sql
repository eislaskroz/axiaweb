-- AXIA Tickets - ampliación para portal administrativo
-- Ejecutar después de 01_schema.sql de la app Android.

-- 1) Amplía estados sin afectar tickets existentes.
alter table public.tickets drop constraint if exists tickets_status_check;
alter table public.tickets add constraint tickets_status_check
check (status in ('Abierto','En proceso','Cerrado'));

-- 2) Historial administrativo.
create table if not exists public.ticket_history (
    id uuid primary key default gen_random_uuid(),
    ticket_id uuid not null references public.tickets(id) on delete cascade,
    changed_by uuid null references public.profiles(id) on delete set null,
    action text not null,
    old_value text null,
    new_value text null,
    created_at timestamptz not null default now()
);
create index if not exists idx_ticket_history_ticket on public.ticket_history(ticket_id, created_at desc);
alter table public.ticket_history enable row level security;
drop policy if exists "ticket_history_admin_select" on public.ticket_history;
create policy "ticket_history_admin_select" on public.ticket_history for select to authenticated using (public.is_admin_axia());

grant select on public.ticket_history to authenticated;

-- 3) Registra automáticamente cambios de estado y asignación.
create or replace function public.audit_ticket_admin_changes()
returns trigger language plpgsql security definer set search_path=public as $$
begin
  if old.status is distinct from new.status then
    insert into public.ticket_history(ticket_id,changed_by,action,old_value,new_value)
    values(new.id,auth.uid(),'Cambio de estado',old.status,new.status);
  end if;
  if old.assigned_to is distinct from new.assigned_to then
    insert into public.ticket_history(ticket_id,changed_by,action,old_value,new_value)
    values(new.id,auth.uid(),'Cambio de responsable',coalesce(old.assigned_to::text,'Sin asignar'),coalesce(new.assigned_to::text,'Sin asignar'));
  end if;
  return new;
end; $$;

drop trigger if exists trg_audit_ticket_admin_changes on public.tickets;
create trigger trg_audit_ticket_admin_changes after update on public.tickets
for each row execute function public.audit_ticket_admin_changes();
