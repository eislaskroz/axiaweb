-- Compatibilidad preventiva: si una versión previa alcanzó a crear la tabla con el nombre
-- incorrecto ticket_responsibles, la renombramos sin perder datos.
do $$
begin
  if to_regclass('public.ticket_responsables') is null
     and to_regclass('public.ticket_responsibles') is not null then
    alter table public.ticket_responsibles rename to ticket_responsables;
  end if;
end $$;

-- AXIA Tickets - módulo Responsables
-- Ejecutar DESPUÉS de 01_schema.sql y 02_portal_admin.sql.

create table if not exists public.ticket_responsables (
    profile_id uuid primary key references public.profiles(id) on delete cascade,
    is_active boolean not null default true,
    department text not null default 'Soporte',
    specialties text[] not null default '{}',
    notes text not null default '',
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now()
);

create index if not exists idx_ticket_responsables_active
on public.ticket_responsables(is_active);

alter table public.ticket_responsables enable row level security;

drop policy if exists "ticket_responsables_admin_select" on public.ticket_responsables;
create policy "ticket_responsables_admin_select"
on public.ticket_responsables for select
to authenticated
using (public.is_admin_axia());

drop policy if exists "ticket_responsables_admin_insert" on public.ticket_responsables;
create policy "ticket_responsables_admin_insert"
on public.ticket_responsables for insert
to authenticated
with check (
    public.is_admin_axia()
    and exists (
        select 1 from public.profiles p
        where p.id = profile_id and p.role = 'admin_axia'
    )
);

drop policy if exists "ticket_responsables_admin_update" on public.ticket_responsables;
create policy "ticket_responsables_admin_update"
on public.ticket_responsables for update
to authenticated
using (public.is_admin_axia())
with check (
    public.is_admin_axia()
    and exists (
        select 1 from public.profiles p
        where p.id = profile_id and p.role = 'admin_axia'
    )
);

grant select, insert, update on public.ticket_responsables to authenticated;

-- Crea automáticamente configuración básica para administradores AXIA actuales.
insert into public.ticket_responsables (profile_id, is_active, department)
select id, true, 'Soporte'
from public.profiles
where role = 'admin_axia'
on conflict (profile_id) do nothing;
