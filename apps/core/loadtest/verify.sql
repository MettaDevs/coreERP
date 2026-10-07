-- Oracle kebenaran sisi Core setelah load test.
--
-- Dibaca langsung dari database, bukan lewat API: API adalah yang sedang diuji, jadi ia tidak
-- boleh menjadi hakim atas dirinya sendiri. Setiap baris wajib bernilai 0, dan satu pelanggaran
-- saja membuat skrip berhenti dengan galat sehingga run tidak dapat lolos tanpa dibaca.
--
-- Pasangannya ada di `modules/apperp/management-aset/loadtest/verify.sql`, yang memeriksa isi
-- tabel module. Keduanya dijalankan pada database yang SAMA, karena sejak module masuk ke
-- runtime Core memang hanya ada satu database.
--
-- Lingkupnya seluruh pengguna fixture (`@example.test`). Beri `-v run_id=<RUN_ID>` untuk
-- mempersempit ke satu run yang emailnya berpola `core-load-<run_id>-%`.

\pset pager off
\pset format aligned
\set ON_ERROR_STOP on

\if :{?run_id}
    \set pola 'core-load-' :'run_id' '-%@example.test'
\else
    \set pola '%@example.test'
\endif

create temporary view pengguna_run as
select u.id as user_id, m.id as membership_id, m.tenant_id
from users u
join tenant_memberships m on m.user_id = u.id
where u.email like :'pola';

create temporary table hasil_core (
    pemeriksaan text primary key,
    pelanggaran bigint not null
);

insert into hasil_core
select 'user tanpa tepat satu membership', count(*)
from (select user_id from pengguna_run group by user_id having count(*) <> 1) d;

-- Batas sebuah tenant: klien, entitlement, environment produksi, dan role Owner-nya. Yang hilang
-- salah satunya berarti pendaftaran berhenti di tengah dan meninggalkan tenant setengah jadi.
-- Sejak registry environment, pendaftaran menulis `environments`; `tenant_deployments` tabel yatim.
insert into hasil_core
select 'boundary tenant tidak lengkap', count(*)
from (select distinct tenant_id from pengguna_run) r
join tenants t on t.id = r.tenant_id
where (select count(*) from clients c where c.id = t.client_id) <> 1
   or (select count(*) from tenant_app_entitlements e where e.tenant_id = r.tenant_id and e.status = 'active') < 1
   or (select count(*) from environments en where en.tenant_id = r.tenant_id and en.kind = 'production' and en.deleted_at is null) <> 1
   or (select count(*) from roles ro where ro.tenant_id = r.tenant_id and ro.is_active) < 1;

insert into hasil_core
select 'assignment role menembus tenant', count(*)
from pengguna_run r
join role_assignments a on a.membership_id = r.membership_id
join roles ro on ro.id = a.role_id
where ro.tenant_id <> r.tenant_id;

insert into hasil_core
select 'scope kebijakan data menembus tenant', count(*)
from pengguna_run r
join role_assignments a on a.membership_id = r.membership_id
join role_assignment_data_policy_scopes s on s.role_assignment_id = a.id
where s.tenant_id <> r.tenant_id;

-- Jumlah sequence yang dimaterialisasi harus sama dengan jumlah reference milik app yang
-- benar-benar dibeli tenant itu — tidak kurang, dan tidak satu pun milik app tetangga.
--
-- Reference milik Core sendiri (`app_id = 'core'`, misalnya `core.vendor`) dikecualikan dari
-- keempat pemeriksaan sequence di bawah: ia tidak dibeli, lahir saat record pertamanya dibuat,
-- dan bawaannya ditetapkan Core (`VND-`, 1-999999, scope entitas legal), bukan bawaan app.
-- Skenario yang membuat vendor — penerimaan aset sejak area 9 — memunculkannya.
insert into hasil_core
select 'jumlah sequence tenant tidak sesuai entitlement', count(*)
from (select distinct tenant_id from pengguna_run) r
where (select count(*) from tenant_number_sequences s
       join app_number_sequence_references ref on ref.id = s.reference_id
       where s.tenant_id = r.tenant_id and ref.app_id <> 'core')
   <> (select count(*)
       from app_number_sequence_references ref
       join tenant_app_entitlements e on e.app_id = ref.app_id
       where e.tenant_id = r.tenant_id and e.status = 'active');

insert into hasil_core
select 'sequence milik app yang tidak dibeli', count(*)
from tenant_number_sequences s
join (select distinct tenant_id from pengguna_run) r on r.tenant_id = s.tenant_id
join app_number_sequence_references ref on ref.id = s.reference_id
where ref.app_id <> 'core'
  and not exists (
    select 1 from tenant_app_entitlements e
    where e.tenant_id = s.tenant_id and e.app_id = ref.app_id and e.status = 'active'
);

-- Bawaan sebuah sequence yang baru dimaterialisasi: aktif, rentang 1-19999, tidak kontinu,
-- tidak boleh diisi manual. Scope-nya TIDAK dipatok 'tenant': reference transaksi memang
-- ber-scope legal entity, dan yang benar adalah scope yang diizinkan reference itu sendiri.
insert into hasil_core
select 'default sequence bukan aktif 0-19999', count(*)
from tenant_number_sequences s
join (select distinct tenant_id from pengguna_run) r on r.tenant_id = s.tenant_id
join app_number_sequence_references ref on ref.id = s.reference_id
where ref.app_id <> 'core' and (
      s.status <> 'active'
   or s.minimum_number <> 1
   or s.maximum_number <> 19999
   or not (ref.allowed_scopes::jsonb ? s.scope_type)
   or s.is_continuous
   or s.allow_manual);

insert into hasil_core
select 'prefix sequence tidak sesuai reference', count(*)
from tenant_number_sequences s
join (select distinct tenant_id from pengguna_run) r on r.tenant_id = s.tenant_id
join app_number_sequence_references ref on ref.id = s.reference_id
where ref.app_id <> 'core' and (
      (ref.default_prefix is not null and (
           s.segments->0->>'type' <> 'constant'
        or s.segments->0->>'value' <> ref.default_prefix
      ))
   or (ref.default_prefix is null and json_array_length(s.segments) <> 1));

insert into hasil_core
select 'tenant-reference ganda', count(*)
from (
    select s.tenant_id, s.reference_id
    from tenant_number_sequences s
    join (select distinct tenant_id from pengguna_run) r on r.tenant_id = s.tenant_id
    group by s.tenant_id, s.reference_id
    having count(*) > 1
) d;

-- Nomor yang terbit atas nama tenant lain. Sebelum pemindahan ini mustahil: tiap tenant punya
-- databasenya sendiri. Sekarang satu tabel melayani semuanya, jadi ia harus diperiksa.
insert into hasil_core
select 'terbitan nomor menembus batas tenant', count(*)
from number_sequence_issues i
join tenant_number_sequences s on s.id = i.sequence_id
where s.scope_type = 'tenant' and i.scope_key <> 'tenant:' || rtrim(s.tenant_id);

insert into hasil_core
select 'terbitan nomor atas nama app yang bukan pemilik reference', count(*)
from number_sequence_issues i
join tenant_number_sequences s on s.id = i.sequence_id
join app_number_sequence_references ref on ref.id = s.reference_id
where i.app_id <> ref.app_id;

insert into hasil_core
select 'nomor sama terbit dua kali', count(*)
from (
    select sequence_id, scope_key, period_key, formatted_value
    from number_sequence_issues
    group by 1, 2, 3, 4
    having count(*) > 1
) d;

-- Log perubahan (area 2 analisa gap BC). Pelakunya variabel sesi `coreerp.user_id`: anggota tenant entrinya,
-- atau akun aplikasi klien integrasi milik tenant itu. Pelaku lain berarti nilai itu terbawa dari permintaan
-- lain lewat koneksi yang dipakai ulang.
insert into hasil_core
select 'entri log berpelaku bukan anggota tenantnya', count(*)
from change_log_entries e
where e.created_by_user_id is not null
  and not exists (
      select 1 from tenant_memberships m where m.user_id = e.created_by_user_id and m.tenant_id::text = e.tenant_id::text
  )
  and not exists (
      select 1 from integration_clients c where c.user_id = e.created_by_user_id and c.tenant_id::text = e.tenant_id::text
  );

-- Tabel akses tanpa `tenant_id`: tenant entrinya dibaca trigger dari peran yang dirujuk.
insert into hasil_core
select 'entri log peran atau penugasan peran di tenant lain', count(*)
from change_log_entries e
left join roles r on e.table_name = 'roles' and r.id::text = e.record_id
left join role_assignments ra on e.table_name = 'role_assignments' and ra.id::text = e.record_id
left join roles rr on rr.id = ra.role_id
where e.table_name in ('roles', 'role_assignments')
  and coalesce(r.tenant_id, rr.tenant_id)::text <> e.tenant_id::text;

\if :{?analytics}
create temporary table lt_analytics_observed (
    fixture_id text not null,
    run_id text not null,
    scenario text not null,
    query_code text not null,
    metric text not null,
    tenant_id char(26) not null,
    user_email text not null,
    access_kind text not null,
    group_key char(26),
    currency_code char(3),
    observed_value numeric not null
);

\copy lt_analytics_observed from '/results/analytics-observed.csv' with (format csv, header true, null '')

insert into hasil_core
select 'observasi analitik tidak cocok dengan fixture pengguna', count(*)
from lt_analytics_observed o
left join lt_analytics_user_scope s
  on s.fixture_id = o.fixture_id and s.tenant_id = o.tenant_id and s.user_email = o.user_email
where o.fixture_id = :'run_id'
  and (s.id is null or s.access_kind <> o.access_kind);

insert into hasil_core
select 'pengguna fixture tidak memiliki observasi analitik', count(*)
from lt_analytics_user_scope s
where s.fixture_id = :'run_id'
  and not exists (
      select 1 from lt_analytics_observed o
      where o.fixture_id = s.fixture_id and o.tenant_id = s.tenant_id and o.user_email = s.user_email
        and o.query_code = 'asset-register-by-group'
  );

insert into hasil_core
select 'observasi aset berasal dari tenant lain', count(*)
from lt_analytics_observed o
where o.fixture_id = :'run_id'
  and o.metric in ('count', 'acquisition_value')
  and o.group_key is not null
  and not exists (
      select 1 from aset_tr_aset a
      where a.tenant_id = o.tenant_id and a.group_aset_id = o.group_key and a.deleted_at is null
  );

insert into hasil_core
select 'nilai uang analitik tidak membawa mata uang', count(*)
from lt_analytics_observed o
where o.fixture_id = :'run_id' and o.metric = 'acquisition_value'
  and nullif(trim(o.currency_code), '') is null;

with scopes as (
    select * from lt_analytics_user_scope where fixture_id = :'run_id'
), expected_values as (
    select s.tenant_id, s.user_email, 'count'::text as metric,
           a.group_aset_id::char(26) as group_key, a.currency_code::char(3) as currency_code,
           count(*)::numeric as expected_value
    from scopes s
    join aset_tr_aset a on a.tenant_id = s.tenant_id and a.deleted_at is null
    where s.access_kind = 'all'
       or (s.access_kind = 'two_units'
           and a.legal_entity_id = s.legal_entity_id
           and a.responsible_org_unit_id::text in (select jsonb_array_elements_text(s.unit_ids)))
    group by s.tenant_id, s.user_email, a.group_aset_id, a.currency_code

    union all

    select s.tenant_id, s.user_email, 'acquisition_value'::text,
           a.group_aset_id::char(26), a.currency_code::char(3), sum(a.acquisition_value)::numeric
    from scopes s
    join aset_tr_aset a on a.tenant_id = s.tenant_id and a.deleted_at is null
    where s.access_kind = 'all'
       or (s.access_kind = 'two_units'
           and a.legal_entity_id = s.legal_entity_id
           and a.responsible_org_unit_id::text in (select jsonb_array_elements_text(s.unit_ids)))
    group by s.tenant_id, s.user_email, a.group_aset_id, a.currency_code
), expected as (
    select * from expected_values
    union all
    select s.tenant_id, s.user_email, 'empty'::text, null::char(26), null::char(3), 0::numeric
    from scopes s where s.access_kind = 'none'
), observed as (
    select o.tenant_id, o.user_email, o.metric, o.group_key, o.currency_code,
           min(o.observed_value) as minimum_value, max(o.observed_value) as maximum_value
    from lt_analytics_observed o
    where o.fixture_id = :'run_id' and o.query_code = 'asset-register-by-group'
    group by o.tenant_id, o.user_email, o.metric, o.group_key, o.currency_code
), mismatches as (
    select 1
    from expected e
    full join observed o
      on o.tenant_id = e.tenant_id
     and o.user_email = e.user_email
     and o.metric = e.metric
     and o.group_key is not distinct from e.group_key
     and o.currency_code is not distinct from e.currency_code
    where e.expected_value is null
       or o.minimum_value is null
       or e.expected_value <> o.minimum_value
       or e.expected_value <> o.maximum_value
)
insert into hasil_core
select 'tenant, policy data, atau jumlah per mata uang analitik tidak cocok', count(*)
from mismatches;
\endif

select pemeriksaan, pelanggaran from hasil_core order by pemeriksaan;

\echo
\echo '=== VOLUME RUN ==='
select
    count(distinct user_id) as users,
    count(distinct tenant_id) as tenants,
    (select count(*) from tenant_number_sequences s where s.tenant_id in (select tenant_id from pengguna_run)) as sequences,
    (select count(*) from number_sequence_issues) as nomor_terbit,
    (select count(*) from change_log_entries) as entri_log
from pengguna_run;

do $$
begin
    if exists (select 1 from hasil_core where pelanggaran <> 0) then
        raise exception 'Gate kebenaran Core GAGAL; lihat daftar pemeriksaan di atas.';
    end if;
end
$$;

\echo 'Gate kebenaran Core: LULUS (semua pemeriksaan 0).'
