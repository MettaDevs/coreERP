-- Ekspektasi fixture independen: perusahaan A LTCOMPANY<n>, B CCB<n>, tepat dua dokumen per tenant.
\set ON_ERROR_STOP on
\pset pager off
\if :{?fixture}
\else
    \set fixture 'company-gate'
\endif
\if :{?tenants}
\else
    \set tenants 128
\endif

create temporary table company_expectations as
select m.tenant_id, i as fixture_index, company,
       'cp-' || :'fixture' || '-' || i || '-' || company as creation_key,
       case company when 'A' then 'LTCOMPANY' || i else 'CCB' || i end as company_code
from generate_series(0, :tenants - 1) i
cross join (values ('A'), ('B')) companies(company)
join users u on u.email = 'load-' || :'fixture' || 'company-' || i || '@example.test'
join tenant_memberships m on m.user_id = u.id;

-- Membuktikan oracle dapat merah tanpa mengubah satu pun record bisnis.
\if :{?selftest}
update company_expectations set company_code = company_code || '_invalid'
where fixture_index = 0 and company = 'A';
\endif

create temporary table company_results as
select 'expected fixtures missing' as verification,
       abs(2 * :tenants - (select count(*) from company_expectations))::bigint as violations
union all
select 'company document missing or wrong owner', count(*)
from company_expectations e
left join aset_tr_perencanaan_aset p on p.tenant_id = e.tenant_id and p.creation_key = e.creation_key
left join organizations o on o.id = p.legal_entity_id and o.tenant_id = e.tenant_id
left join legal_entities l on l.organization_id = o.id
where p.id is null or p.deleted_at is not null or l.company_code is distinct from e.company_code
union all
select 'forbidden cross company create persisted', count(*)
from aset_tr_perencanaan_aset
where creation_key like 'cp-' || :'fixture' || '-reject-%';

table company_results;
do $$ begin
    if exists(select 1 from company_results where violations <> 0) then
        raise exception 'company context SQL oracle failed';
    end if;
end $$;
