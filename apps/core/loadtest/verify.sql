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
-- mempersempit ke RUN_ID skenario Core atau FIXTURE skenario module.

\pset pager off
\pset format aligned
\set ON_ERROR_STOP on

\if :{?run_id}
    \set pola 'core-load-' :'run_id' '-%@example.test'
    \set pola_module 'load-' :'run_id' '%@example.test'
\else
    \set pola '%@example.test'
    \set pola_module '%@example.test'
\endif

create temporary view pengguna_run as
select u.id as user_id, m.id as membership_id, m.tenant_id
from users u
join tenant_memberships m on m.user_id = u.id
where u.email like :'pola' or u.email like :'pola_module';

do $$ begin
    if not exists (select 1 from pengguna_run) then
        raise exception 'Fixture tidak ditemukan; oracle tidak dapat memeriksa run kosong.';
    end if;
end $$;

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

-- Bawaan materialisasi mengikuti profile dan pengaturan reference yang dinyatakan module.
-- Scope tetap diperiksa terhadap pilihan yang diizinkan reference.
insert into hasil_core
select 'default sequence tidak sesuai profile dan reference', count(*)
from tenant_number_sequences s
join (select distinct tenant_id from pengguna_run) r on r.tenant_id = s.tenant_id
join app_number_sequence_references ref on ref.id = s.reference_id
left join number_sequence_profiles p on p.code = s.profile_code
where ref.app_id <> 'core' and (
      s.status <> 'active'
   or s.minimum_number is distinct from coalesce((ref.default_settings->>'minimum_number')::integer, 1)
   or s.maximum_number is distinct from coalesce((ref.default_settings->>'maximum_number')::integer, 19999)
   or not (ref.allowed_scopes::jsonb ? s.scope_type)
   or p.code is null
   or s.is_continuous is distinct from coalesce((ref.default_settings->>'is_continuous')::boolean, p.is_continuous)
   or s.allow_manual is distinct from coalesce((ref.default_settings->>'allow_manual')::boolean, p.allow_manual)
   or s.allow_automatic is distinct from coalesce((ref.default_settings->>'allow_automatic')::boolean, true)
   or s.preallocation_enabled is distinct from coalesce((ref.default_settings->>'preallocation_enabled')::boolean, p.preallocation_enabled)
   or s.preallocation_quantity is distinct from coalesce((ref.default_settings->>'preallocation_quantity')::integer, p.preallocation_quantity));

insert into hasil_core
select 'format sequence tidak sesuai reference', count(*)
from tenant_number_sequences s
join (select distinct tenant_id from pengguna_run) r on r.tenant_id = s.tenant_id
join app_number_sequence_references ref on ref.id = s.reference_id
where ref.app_id <> 'core' and case
    when ref.default_settings->'segments' is not null
        then s.segments::jsonb is distinct from ref.default_settings->'segments'
    else (ref.default_prefix is not null and (
           s.segments->0->>'type' <> 'constant'
        or s.segments->0->>'value' <> ref.default_prefix
      )) or (ref.default_prefix is null and json_array_length(s.segments) <> 1)
end;

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
