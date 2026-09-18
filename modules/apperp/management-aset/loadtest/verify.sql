-- Oracle kebenaran Management Aset setelah load test.
--
-- Dijalankan langsung pada database, bukan lewat API, supaya yang diperiksa adalah keadaan
-- data yang benar-benar tersimpan dan bukan pandangan kode yang sama yang sedang diuji.
-- Setiap baris hasil wajib bernilai 0 pada kolom `pelanggaran`; kalau tidak, skrip berhenti
-- dengan galat sehingga run tidak dapat lolos diam-diam.
--
-- ## Dua hal yang berubah sejak module masuk ke runtime Core
--
-- 1. **Nama tabel berawalan `aset_`.** Module berbagi database dengan Core dan dipisahkan
--    awalan tabel, bukan database sendiri. Query yang masih menyebut `m_group_aset` tidak
--    error — ia hanya tidak menemukan tabel dan gate-nya lolos secara palsu.
--
-- 2. **Oracle nomor pindah ke `number_sequence_issues`.** Dulu jumlah nomor terbit dibaca dari
--    `/__stats` milik tiruan Core. Tiruan itu tidak ada lagi; nomor diterbitkan proses yang
--    sama lewat `PenerbitNomor`, dan tiap penerbitan meninggalkan baris di buku terbitan Core.
--    Itu oracle yang lebih kuat daripada yang digantikannya: ia tidak hanya menghitung, ia
--    juga mengikat tiap kode yang tersimpan module ke satu baris terbitan milik tenant dan
--    reference yang benar. Kode yang tidak punya pasangan di sana berarti nomor yang lahir di
--    luar Number Sequence.

\pset pager off
\pset format aligned
\set ON_ERROR_STOP on

\echo
\echo '=== PEMERIKSAAN KEBENARAN MANAGEMENT ASET (semua pelanggaran harus 0) ==='

create temporary view terbitan as
select s.tenant_id, r.code as referensi, i.formatted_value
from number_sequence_issues i
join tenant_number_sequences s on s.id = i.sequence_id
join app_number_sequence_references r on r.id = s.reference_id;

with duplikat_kode as (
    select count(*) as n from (
        select tenant_id, kode from aset_m_group_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_jenis_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_model_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_tipe_lokasi_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_buku_penyusutan group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_kondisi_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_pabrikan_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_item_checklist_maintenance group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_analisa_maintenance group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_maintenance_job_type group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_maintenance_checklist_variable group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_tipe_atribut group by 1, 2 having count(*) > 1
    ) d
),
duplikat_kunci as (
    select count(*) as n from (
        select tenant_id, creation_key from aset_m_group_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_m_jenis_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_m_model_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_m_tipe_lokasi_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_m_buku_penyusutan group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_m_kondisi_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_m_pabrikan_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_m_item_checklist_maintenance group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_m_analisa_maintenance group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_m_maintenance_job_type group by 1, 2 having count(*) > 1
    ) d
),
induk_lintas_tenant as (
    select
        (select count(*) from aset_m_model_aset c join aset_m_pabrikan_aset p on p.id = c.pabrikan_aset_id where p.tenant_id <> c.tenant_id)
      + (select count(*) from aset_m_model_aset c join aset_m_jenis_aset p on p.id = c.jenis_aset_id where p.tenant_id <> c.tenant_id)
      + (select count(*) from aset_m_lokasi_aset c join aset_m_lokasi_aset p on p.id = c.parent_id where p.tenant_id <> c.tenant_id)
      + (select count(*) from aset_m_lokasi_aset c join aset_m_tipe_lokasi_aset p on p.id = c.tipe_lokasi_id where p.tenant_id <> c.tenant_id)
      + (select count(*) from aset_m_group_buku_penyusutan m join aset_m_group_aset g on g.id = m.group_aset_id where g.tenant_id <> m.tenant_id)
      + (select count(*) from aset_m_group_buku_penyusutan m join aset_m_buku_penyusutan b on b.id = m.buku_id where b.tenant_id <> m.tenant_id)
      + (select count(*) from aset_tr_buku_aset k join aset_m_buku_penyusutan b on b.id = k.buku_id where b.tenant_id <> k.tenant_id)
      + (select count(*) from aset_tr_aset a join aset_m_group_aset p on p.id = a.group_aset_id where p.tenant_id <> a.tenant_id)
      + (select count(*) from aset_tr_aset a join aset_m_jenis_aset p on p.id = a.jenis_aset_id where p.tenant_id <> a.tenant_id)
      -- Tabel penghubung yang disunting dari dua arah: inilah yang dijaga skenario link-race.
      + (select count(*) from aset_m_maintenance_job_type_jenis_aset l join aset_m_maintenance_job_type j on j.id = l.job_type_id where j.tenant_id <> l.tenant_id)
      + (select count(*) from aset_m_maintenance_job_type_jenis_aset l join aset_m_jenis_aset t on t.id = l.jenis_aset_id where t.tenant_id <> l.tenant_id) as n
),
induk_hilang as (
    select
        (select count(*) from aset_m_model_aset c left join aset_m_pabrikan_aset p on p.id = c.pabrikan_aset_id where p.id is null)
      + (select count(*) from aset_m_model_aset c left join aset_m_jenis_aset p on p.id = c.jenis_aset_id where c.jenis_aset_id is not null and p.id is null)
      + (select count(*) from aset_tr_aset a left join aset_m_group_aset p on p.id = a.group_aset_id where p.id is null)
      + (select count(*) from aset_m_maintenance_job_type_jenis_aset l left join aset_m_maintenance_job_type j on j.id = l.job_type_id where j.id is null)
      + (select count(*) from aset_m_maintenance_job_type_jenis_aset l left join aset_m_jenis_aset t on t.id = l.jenis_aset_id where t.id is null) as n
),
prefix_salah as (
    -- Prefix kode berasal dari reference Number Sequence yang berbeda per master. Prefix yang
    -- tertukar berarti satu master memakai reference milik master lain.
    select
        (select count(*) from aset_m_group_aset where kode not like 'GRPA%')
      + (select count(*) from aset_m_jenis_aset where kode not like 'JNSA%')
      + (select count(*) from aset_m_model_aset where kode not like 'MDLA%')
      + (select count(*) from aset_m_tipe_lokasi_aset where kode not like 'TLKA%')
      + (select count(*) from aset_m_buku_penyusutan where kode not like 'BKPY%')
      + (select count(*) from aset_m_kondisi_aset where kode not like 'KNDA%')
      + (select count(*) from aset_m_pabrikan_aset where kode not like 'PBRA%')
      + (select count(*) from aset_m_item_checklist_maintenance where kode not like 'ICMA%')
      + (select count(*) from aset_m_analisa_maintenance where kode not like 'ANMA%')
      + (select count(*) from aset_m_maintenance_job_type where kode not like 'JPMA%')
      + (select count(*) from aset_m_maintenance_checklist_variable where kode not like 'VCMA%')
      + (select count(*) from aset_m_tipe_atribut where kode not like 'TATR%') as n
),
-- Oracle nomor, pengganti `/__stats` milik tiruan Core: tiap kode yang tersimpan module wajib
-- punya satu baris terbitan pada tenant DAN reference yang benar.
nomor_tanpa_terbitan as (
    select
        (select count(*) from aset_m_group_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.group-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_jenis_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.jenis-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_model_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.model-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_pabrikan_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.pabrikan-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_kondisi_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.kondisi-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_tipe_lokasi_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.tipe-lokasi-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_item_checklist_maintenance t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.item-checklist-maintenance' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_analisa_maintenance t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.analisa-maintenance' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_maintenance_job_type t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.maintenance-job-types' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_maintenance_checklist_variable t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.maintenance-checklist-variables' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_buku_penyusutan t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.buku-penyusutan' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_profil_penyusutan t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.profil-penyusutan' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_tipe_atribut t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.tipe-atribut' and i.formatted_value = t.kode))
      + (select count(*) from aset_tr_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_tr_perencanaan_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.perencanaan-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_tr_pemeliharaan_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.pemeliharaan-aset' and i.formatted_value = t.kode)) as n
),
nomor_terbit_ganda as (
    -- Satu nomor tidak boleh diterbitkan dua kali pada tenant dan reference yang sama. Ini
    -- yang dulu dibuktikan dengan membandingkan jumlah terbit dan jumlah nomor unik di stub.
    select count(*) as n from (
        select tenant_id, referensi, formatted_value from terbitan group by 1, 2, 3 having count(*) > 1
    ) d
),
nomor_dipakai_dua_record as (
    -- Satu nomor terbit dipakai dua baris berbeda dalam satu tenant.
    select count(*) as n from (
        select tenant_id, kode from aset_m_group_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_m_maintenance_job_type group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_tr_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from aset_tr_perencanaan_aset group by 1, 2 having count(*) > 1
    ) d
),
tenant_kosong as (
    select
        (select count(*) from aset_m_group_aset where tenant_id is null or length(trim(tenant_id)) <> 26)
      + (select count(*) from aset_m_jenis_aset where tenant_id is null or length(trim(tenant_id)) <> 26)
      + (select count(*) from aset_m_model_aset where tenant_id is null or length(trim(tenant_id)) <> 26)
      + (select count(*) from aset_m_maintenance_job_type where tenant_id is null or length(trim(tenant_id)) <> 26) as n
),
tenant_tak_dikenal as (
    -- Baris module yang menunjuk tenant yang tidak ada di Core. Tidak mungkin sebelum
    -- pemindahan karena tiap tenant punya databasenya sendiri; mungkin sekarang.
    select
        (select count(*) from aset_m_group_aset t left join tenants c on c.id = t.tenant_id where c.id is null)
      + (select count(*) from aset_tr_aset t left join tenants c on c.id = t.tenant_id where c.id is null)
      + (select count(*) from aset_m_maintenance_job_type t left join tenants c on c.id = t.tenant_id where c.id is null) as n
),
aset_duplikat as (
    select count(*) as n from (
        select tenant_id, kode from aset_tr_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_tr_aset group by 1, 2 having count(*) > 1
    ) d
),
penempatan_lintas_tenant as (
    select count(*) as n from aset_tr_penempatan_aset p join aset_tr_aset a on a.id = p.aset_id where p.tenant_id <> a.tenant_id
),
dokumen_lintas_tenant as (
    select count(*) as n from aset_tr_dokumen_siklus_aset d join aset_tr_aset a on a.id = d.aset_id where d.aset_id is not null and d.tenant_id <> a.tenant_id
),
perencanaan_duplikat as (
    select count(*) as n from (
        select tenant_id, kode from aset_tr_perencanaan_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_tr_perencanaan_aset group by 1, 2 having count(*) > 1
    ) d
),
perencanaan_detail_tidak_sah as (
    select
        (select count(*) from aset_tr_perencanaan_aset_details d join aset_tr_perencanaan_aset h on h.id = d.planning_id where d.tenant_id <> h.tenant_id)
      + (select count(*) from aset_tr_perencanaan_aset_details d join aset_m_jenis_aset j on j.id = d.jenis_aset_id where d.tenant_id <> j.tenant_id)
      + (select count(*) from aset_tr_perencanaan_aset_details d left join aset_tr_perencanaan_aset h on h.id = d.planning_id where h.id is null) as n
),
perencanaan_prefix_salah as (
    select count(*) as n from aset_tr_perencanaan_aset where kode not like 'PLNA%'
),
export_ganda as (
    select count(*) as n from (
        select tenant_id, posting_id from aset_tr_export_penyusutan group by 1, 2 having count(*) > 1
        union all select tenant_id, depreciation_period_id from aset_tr_export_penyusutan group by 1, 2 having count(*) > 1
    ) d
),
depreciation_negative as (
    select count(*) as n from aset_tr_penyusutan_aset where reverses_period_id is null and amount < 0
),
depreciation_nbv_below_residual as (
    select count(*) as n from aset_tr_buku_aset where net_book_value < greatest(coalesce(residual_value, 0), 0)
),
depreciation_final_ganda as (
    select count(*) as n from (
        select tenant_id, buku_aset_id, period_ends_on
        from aset_tr_penyusutan_aset
        where reverses_period_id is null and status = 'final'
        group by 1, 2, 3 having count(*) > 1
    ) d
),
attribute_value_outside_active_values as (
    select count(*) as n
    from aset_tr_aset_atribut a
    join aset_m_tipe_atribut t on t.tenant_id = a.tenant_id and t.id = a.tipe_atribut_id
    where t.data_type = 'string'
      and exists (select 1 from aset_m_tipe_atribut_nilai v where v.tenant_id = t.tenant_id and v.tipe_atribut_id = t.id and v.deleted_at is null)
      and not exists (
          select 1 from aset_m_tipe_atribut_nilai v
          where v.tenant_id = a.tenant_id and v.tipe_atribut_id = a.tipe_atribut_id and v.deleted_at is null and v.nilai = a.nilai_text
      )
),
integer_fraction as (
    select count(*) as n
    from aset_tr_aset_atribut a
    join aset_m_tipe_atribut t on t.tenant_id = a.tenant_id and t.id = a.tipe_atribut_id
    where t.data_type = 'integer' and a.nilai_number <> trunc(a.nilai_number)
),
attribute_cross_tenant as (
    select
        (select count(*) from aset_tr_aset_atribut a join aset_m_tipe_atribut t on t.id = a.tipe_atribut_id where t.tenant_id <> a.tenant_id)
      + (select count(*) from aset_m_tipe_atribut_nilai v join aset_m_tipe_atribut t on t.id = v.tipe_atribut_id where t.tenant_id <> v.tenant_id)
      + (select count(*) from aset_m_jenis_aset_atribut l join aset_m_tipe_atribut t on t.id = l.tipe_atribut_id where t.tenant_id <> l.tenant_id) as n
),
work_order_duplikat as (
    select count(*) as n from (
        select tenant_id, kode from aset_tr_pemeliharaan_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from aset_tr_pemeliharaan_aset group by 1, 2 having count(*) > 1
    ) d
),
work_order_prefix_salah as (
    select count(*) as n from aset_tr_pemeliharaan_aset where kode not like 'PMHA%'
),
work_order_transisi_tidak_sah as (
    -- Grafik transisi `WorkOrderStatus` ditulis ulang di sini sebagai data, bukan dibaca dari
    -- kodenya: oracle yang memanggil kode yang sedang diuji hanya membuktikan kode itu
    -- konsisten dengan dirinya sendiri. Satu baris di luar daftar ini berarti sebuah dokumen
    -- melompati status, dan tidak ada batasan basis data yang menolaknya — baris status log-nya
    -- tetap satu baris yang sah.
    select count(*) as n
    from aset_tr_pemeliharaan_aset_status_log l
    where (l.dari_status, l.ke_status) not in (
        ('draft', 'dijadwalkan'), ('draft', 'dibatalkan'),
        ('dijadwalkan', 'dikerjakan'), ('dijadwalkan', 'dibatalkan'),
        ('dikerjakan', 'selesai'), ('dikerjakan', 'dibatalkan'),
        ('selesai', 'ditutup'), ('selesai', 'dibatalkan')
    )
),
saldo_buku_tidak_cocok_periode as (
    -- Akumulasi penyusutan pada buku aset wajib sama dengan jumlah nilai seluruh periode final
    -- miliknya, termasuk periode pembalik yang nilainya negatif. Penambahannya dikerjakan
    -- `incrementEach` di dalam `finalize()`, di luar kunci buku; kalau satu periode pernah
    -- ditambahkan dua kali — misalnya karena kunci barisnya tidak menahan dua finalisasi
    -- serentak — baris periodenya tetap satu dan tetap sah, dan hanya perbandingan ini yang
    -- memperlihatkannya.
    select count(*) as n
    from aset_tr_buku_aset b
    where b.accumulated_depreciation <> coalesce((
        select sum(p.amount) from aset_tr_penyusutan_aset p
        where p.buku_aset_id = b.id and p.status = 'final'
    ), 0)
),
work_order_child_tidak_sah as (
    select
        (select count(*) from aset_tr_pemeliharaan_aset_details d join aset_tr_pemeliharaan_aset h on h.id = d.pemeliharaan_aset_id where d.tenant_id <> h.tenant_id)
      + (select count(*) from aset_tr_pemeliharaan_aset_details d left join aset_tr_pemeliharaan_aset h on h.id = d.pemeliharaan_aset_id where h.id is null)
      + (select count(*) from aset_tr_pemeliharaan_aset_checklist c join aset_tr_pemeliharaan_aset_details d on d.id = c.pemeliharaan_aset_detail_id where c.tenant_id <> d.tenant_id)
      + (select count(*) from aset_tr_pemeliharaan_aset_status_log l join aset_tr_pemeliharaan_aset h on h.id = l.pemeliharaan_aset_id where l.tenant_id <> h.tenant_id) as n
)
select 'kode ganda dalam satu tenant' as pemeriksaan, n as pelanggaran into temporary table hasil_aset from duplikat_kode
union all select 'creation_key ganda dalam satu tenant', n from duplikat_kunci
union all select 'anak menunjuk induk tenant lain', n from induk_lintas_tenant
union all select 'anak dengan induk tidak ada', n from induk_hilang
union all select 'prefix kode tidak sesuai reference master', n from prefix_salah
union all select 'kode tanpa baris terbitan Number Sequence', n from nomor_tanpa_terbitan
union all select 'nomor terbit dua kali pada reference yang sama', n from nomor_terbit_ganda
union all select 'satu nomor dipakai dua record', n from nomor_dipakai_dua_record
union all select 'tenant_id kosong atau bukan ULID', n from tenant_kosong
union all select 'baris module menunjuk tenant yang tidak ada', n from tenant_tak_dikenal
union all select 'kode atau kunci aset ganda', n from aset_duplikat
union all select 'penempatan menunjuk aset tenant lain', n from penempatan_lintas_tenant
union all select 'dokumen lifecycle menunjuk aset tenant lain', n from dokumen_lintas_tenant
union all select 'kode atau kunci perencanaan ganda', n from perencanaan_duplikat
union all select 'detail perencanaan lintas tenant atau yatim', n from perencanaan_detail_tidak_sah
union all select 'prefix nomor perencanaan salah', n from perencanaan_prefix_salah
union all select 'posting export penyusutan ganda', n from export_ganda
union all select 'nilai penyusutan negatif', n from depreciation_negative
union all select 'NBV melewati nilai residual atau nol', n from depreciation_nbv_below_residual
union all select 'finalisasi periode ganda', n from depreciation_final_ganda
union all select 'nilai teks di luar Values aktif', n from attribute_value_outside_active_values
union all select 'nilai pecahan tersimpan pada integer', n from integer_fraction
union all select 'atribut menunjuk data tenant lain', n from attribute_cross_tenant
union all select 'kode atau kunci work order ganda', n from work_order_duplikat
union all select 'prefix nomor work order salah', n from work_order_prefix_salah
union all select 'detail/checklist/status log work order lintas tenant atau yatim', n from work_order_child_tidak_sah
union all select 'transisi status work order di luar grafik', n from work_order_transisi_tidak_sah
union all select 'akumulasi buku aset tidak sama dengan jumlah periode final', n from saldo_buku_tidak_cocok_periode;

select pemeriksaan, pelanggaran from hasil_aset order by pemeriksaan;

\echo
\echo '=== VOLUME DATA ==='
select 'aset_m_group_aset' as tabel, count(*) as baris, count(distinct tenant_id) as tenant from aset_m_group_aset
union all select 'aset_m_jenis_aset', count(*), count(distinct tenant_id) from aset_m_jenis_aset
union all select 'aset_m_model_aset', count(*), count(distinct tenant_id) from aset_m_model_aset
union all select 'aset_m_tipe_lokasi_aset', count(*), count(distinct tenant_id) from aset_m_tipe_lokasi_aset
union all select 'aset_m_buku_penyusutan', count(*), count(distinct tenant_id) from aset_m_buku_penyusutan
union all select 'aset_m_group_buku_penyusutan', count(*), count(distinct tenant_id) from aset_m_group_buku_penyusutan
union all select 'aset_m_kondisi_aset', count(*), count(distinct tenant_id) from aset_m_kondisi_aset
union all select 'aset_m_pabrikan_aset', count(*), count(distinct tenant_id) from aset_m_pabrikan_aset
union all select 'aset_m_item_checklist_maintenance', count(*), count(distinct tenant_id) from aset_m_item_checklist_maintenance
union all select 'aset_m_analisa_maintenance', count(*), count(distinct tenant_id) from aset_m_analisa_maintenance
union all select 'aset_m_maintenance_job_type', count(*), count(distinct tenant_id) from aset_m_maintenance_job_type
union all select 'aset_m_maintenance_job_type_jenis_aset', count(*), count(distinct tenant_id) from aset_m_maintenance_job_type_jenis_aset
union all select 'aset_m_maintenance_checklist_variable', count(*), count(distinct tenant_id) from aset_m_maintenance_checklist_variable
union all select 'aset_tr_aset', count(*), count(distinct tenant_id) from aset_tr_aset
union all select 'aset_tr_penempatan_aset', count(*), count(distinct tenant_id) from aset_tr_penempatan_aset
union all select 'aset_tr_perencanaan_aset', count(*), count(distinct tenant_id) from aset_tr_perencanaan_aset
union all select 'aset_tr_perencanaan_aset_details', count(*), count(distinct tenant_id) from aset_tr_perencanaan_aset_details
union all select 'aset_tr_pemeliharaan_aset', count(*), count(distinct tenant_id) from aset_tr_pemeliharaan_aset
union all select 'aset_tr_pemeliharaan_aset_details', count(*), count(distinct tenant_id) from aset_tr_pemeliharaan_aset_details
union all select 'aset_tr_pemeliharaan_aset_status_log', count(*), count(distinct tenant_id) from aset_tr_pemeliharaan_aset_status_log
union all select 'aset_tr_buku_aset', count(*), count(distinct tenant_id) from aset_tr_buku_aset
union all select 'aset_tr_penyusutan_aset', count(*), count(distinct tenant_id) from aset_tr_penyusutan_aset
union all select 'aset_tr_export_penyusutan', count(*), count(distinct tenant_id) from aset_tr_export_penyusutan
order by 1;

\echo
\echo '=== TERBITAN NOMOR (Core, bukan tiruan) ==='
select referensi, count(*) as terbit, count(distinct formatted_value || ' ' || tenant_id) as unik
from terbitan
where referensi like 'management-aset.%'
group by referensi
order by referensi;

-- Gate: satu pelanggaran saja membuat perintah ini berhenti dengan status bukan nol, sehingga
-- run tidak dapat dinyatakan lulus hanya karena tidak ada yang membaca keluarannya.
do $$
begin
    if exists (select 1 from hasil_aset where pelanggaran <> 0) then
        raise exception 'Gate kebenaran Management Aset GAGAL; lihat daftar pemeriksaan di atas.';
    end if;
end
$$;

\echo 'Gate kebenaran Management Aset: LULUS (semua pemeriksaan 0).'
