\pset pager off
\pset format aligned

\if :{?run_id}
\else
    \echo 'run_id wajib diberikan: psql -v run_id=<RUN_ID> ...'
    \quit
\endif

create temporary table loadtest_checks (
    pemeriksaan text primary key,
    pelanggaran bigint not null
);

with run_users as (
    select id
    from users
    where email like 'core-load-' || :'run_id' || '-%@example.test'
)
insert into loadtest_checks
select 'user tanpa tepat satu membership', count(*)
from run_users u
where (select count(*) from tenant_memberships m where m.user_id = u.id) <> 1;

with run_tenants as (
    select m.tenant_id
    from users u
    join tenant_memberships m on m.user_id = u.id
    where u.email like 'core-load-' || :'run_id' || '-%@example.test'
)
insert into loadtest_checks
select 'boundary tenant tidak lengkap', count(*)
from run_tenants r
join tenants t on t.id = r.tenant_id
where (select count(*) from clients c where c.id = t.client_id) <> 1
   or (select count(*) from tenant_app_entitlements e where e.tenant_id = r.tenant_id and e.app_id = 'management-aset' and e.status = 'active') <> 1
   or (select count(*) from tenant_deployments d where d.tenant_id = r.tenant_id and d.status = 'active') <> 1
   or (select count(*) from roles ro where ro.tenant_id = r.tenant_id and ro.name = 'Owner' and ro.is_active) <> 1;

with run_tenants as (
    select m.tenant_id
    from users u
    join tenant_memberships m on m.user_id = u.id
    where u.email like 'core-load-' || :'run_id' || '-%@example.test'
)
insert into loadtest_checks
select 'assignment role menembus tenant', count(*)
from run_tenants r
join tenant_memberships m on m.tenant_id = r.tenant_id
join role_assignments a on a.membership_id = m.id
join roles ro on ro.id = a.role_id
where ro.tenant_id <> m.tenant_id;

with run_tenants as (
    select m.tenant_id
    from users u
    join tenant_memberships m on m.user_id = u.id
    where u.email like 'core-load-' || :'run_id' || '-%@example.test'
)
insert into loadtest_checks
select 'assignment owner atau scope tidak lengkap', count(*)
from run_tenants r
where (select count(*)
       from tenant_memberships m
       join role_assignments a on a.membership_id = m.id
       join roles ro on ro.id = a.role_id
       join role_assignment_org_scopes s on s.assignment_id = a.id
       where m.tenant_id = r.tenant_id
         and ro.tenant_id = r.tenant_id
         and a.status = 'active') <> 1;

with run_tenants as (
    select m.tenant_id
    from users u
    join tenant_memberships m on m.user_id = u.id
    where u.email like 'core-load-' || :'run_id' || '-%@example.test'
),
expected as (
    select count(*) as n
    from app_number_sequence_references
    where app_id = 'management-aset'
)
insert into loadtest_checks
select 'jumlah sequence tenant tidak lengkap', count(*)
from run_tenants r
cross join expected e
where (select count(*) from tenant_number_sequences s where s.tenant_id = r.tenant_id) <> e.n;

with run_tenants as (
    select m.tenant_id
    from users u
    join tenant_memberships m on m.user_id = u.id
    where u.email like 'core-load-' || :'run_id' || '-%@example.test'
)
insert into loadtest_checks
select 'default sequence bukan aktif 0-19999', count(*)
from tenant_number_sequences s
join run_tenants r on r.tenant_id = s.tenant_id
where s.status <> 'active'
   or s.minimum_number <> 0
   or s.maximum_number <> 19999
   or s.scope_type <> 'tenant'
   or s.is_continuous
   or s.allow_manual;

with run_tenants as (
    select m.tenant_id
    from users u
    join tenant_memberships m on m.user_id = u.id
    where u.email like 'core-load-' || :'run_id' || '-%@example.test'
)
insert into loadtest_checks
select 'prefix sequence tidak sesuai reference', count(*)
from tenant_number_sequences s
join run_tenants r on r.tenant_id = s.tenant_id
join app_number_sequence_references ref on ref.id = s.reference_id
where (ref.default_prefix is not null and (
           s.segments->0->>'type' <> 'constant'
        or s.segments->0->>'value' <> ref.default_prefix
      ))
   or (ref.default_prefix is null and json_array_length(s.segments) <> 1);

with run_tenants as (
    select m.tenant_id
    from users u
    join tenant_memberships m on m.user_id = u.id
    where u.email like 'core-load-' || :'run_id' || '-%@example.test'
)
insert into loadtest_checks
select 'tenant-reference ganda', count(*)
from (
    select s.tenant_id, s.reference_id
    from tenant_number_sequences s
    join run_tenants r on r.tenant_id = s.tenant_id
    group by s.tenant_id, s.reference_id
    having count(*) > 1
) duplicates;

select pemeriksaan, pelanggaran
from loadtest_checks
order by pemeriksaan;

\echo
\echo '=== VOLUME RUN ==='
select
    count(distinct u.id) as users,
    count(distinct m.tenant_id) as tenants,
    count(distinct s.id) as sequences
from users u
join tenant_memberships m on m.user_id = u.id
left join tenant_number_sequences s on s.tenant_id = m.tenant_id
where u.email like 'core-load-' || :'run_id' || '-%@example.test';

do $$
begin
    if exists (select 1 from loadtest_checks where pelanggaran <> 0) then
        raise exception 'Gate correctness gagal; lihat pemeriksaan di atas.';
    end if;
end
$$;
