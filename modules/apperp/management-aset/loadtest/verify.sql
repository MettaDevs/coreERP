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
    --
    -- Group aset dan buku penyusutan tidak ada di sini: kodenya diketik pengguna (cbb0816), dan
    -- buku `FISKAL`/`KOMERSIAL` lahir dari data awal standar Indonesia. Keduanya dijaga
    -- `kode_diketik_tidak_sah` dan `duplikat_kode`, bukan oracle nomor.
    select
        (select count(*) from aset_m_jenis_aset where kode not like 'JNSA%')
      + (select count(*) from aset_m_model_aset where kode not like 'MDLA%')
      + (select count(*) from aset_m_tipe_lokasi_aset where kode not like 'TLKA%')
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
        (select count(*) from aset_m_jenis_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.jenis-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_model_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.model-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_pabrikan_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.pabrikan-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_kondisi_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.kondisi-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_tipe_lokasi_aset t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.tipe-lokasi-aset' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_item_checklist_maintenance t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.item-checklist-maintenance' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_analisa_maintenance t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.analisa-maintenance' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_maintenance_job_type t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.maintenance-job-types' and i.formatted_value = t.kode))
      + (select count(*) from aset_m_maintenance_checklist_variable t where not exists (select 1 from terbitan i where i.tenant_id = t.tenant_id and i.referensi = 'management-aset.maintenance-checklist-variables' and i.formatted_value = t.kode))
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
    -- Akumulasi penyusutan pada buku aset wajib sama dengan akumulasi saldo awalnya (area 10; nol
    -- untuk aset yang lahir di sini) ditambah jumlah nilai seluruh periode final miliknya, termasuk
    -- periode pembalik yang nilainya negatif. Penambahannya dikerjakan `incrementEach` di dalam
    -- `finalize()`, di luar kunci buku; kalau satu periode pernah ditambahkan dua kali — misalnya
    -- karena kunci barisnya tidak menahan dua finalisasi serentak — baris periodenya tetap satu
    -- dan tetap sah, dan hanya perbandingan ini yang memperlihatkannya.
    select count(*) as n
    from aset_tr_buku_aset b
    where b.accumulated_depreciation <> b.opening_accumulated_depreciation + coalesce((
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
),
kode_diketik_tidak_sah as (
    -- Bentuk yang ditegakkan `MasterDataController::kodeManual()`, ditulis ulang di sini sebagai
    -- data: huruf besar, angka, dan tanda hubung di antaranya, paling panjang 30.
    select
        (select count(*) from aset_m_group_aset where kode !~ '^[A-Z0-9]+(-[A-Z0-9]+)*$' or length(kode) > 30)
      + (select count(*) from aset_m_buku_penyusutan where kode !~ '^[A-Z0-9]+(-[A-Z0-9]+)*$' or length(kode) > 30) as n
),
penerimaan_tanpa_posting as (
    -- Jurnal penerimaan terbit di transaksi yang sama dengan penyelesaiannya: jurnal perolehan
    -- (area 9), atau jurnal saldo awal untuk penerimaan saldo awal (area 10). Setiap penerimaan
    -- selesai yang bernilai punya tepat satu posting, di tenant yang sama.
    -- Nol berarti tidak ada penerimaan yang selesai tanpa jurnal, dan tidak ada yang dijurnal dua
    -- kali karena dua penyelesaian yang berlomba sama-sama menang.
    --
    -- Penerimaan yang diselesaikan sebelum migration area 9 memang tidak punya posting, dan
    -- dokumennya tidak menyimpan kapan ia selesai. Volume yang masih berisi penerimaan selesai
    -- dari masa itu karenanya memerah di sini; jalankan gate ini pada volume yang lahir sesudahnya.
    select count(*) as n
    from aset_tr_penerimaan_aset p
    where p.status = 'selesai'
      and p.deleted_at is null
      and exists (
          select 1 from aset_tr_penerimaan_aset_details d
          where d.penerimaan_aset_id = p.id and (d.nilai_per_unit > 0 or d.ppn_per_unit > 0)
      )
      and (
          select count(*) from finance_postings f
          where f.tenant_id = p.tenant_id and f.posting_id = case p.cara_perolehan when 'saldo_awal' then 'AST-OPB-' else 'AST-ACQ-' end || p.id
      ) <> 1
),
posting_perolehan_tanpa_penerimaan_selesai as (
    -- Posting perolehan tanpa penerimaan selesai di tenant yang sama berarti transaksi yang batal
    -- meninggalkan posting yatim, atau posting yang tercatat di tenant lain.
    select count(*) as n
    from finance_postings f
    where f.posting_type in ('asset.acquisition', 'asset.opening_balance')
      and f.source_module = 'management-aset'
      and not exists (
          select 1 from aset_tr_penerimaan_aset p
          where p.tenant_id = f.tenant_id and case p.cara_perolehan when 'saldo_awal' then 'AST-OPB-' else 'AST-ACQ-' end || p.id = f.posting_id and p.status = 'selesai'
      )
),
koreksi_nilai as (
    -- Jurnal koreksi nilai perolehan (area 12), satu baris per posting: `AST-ADJ-<id aset>-<urut>`.
    -- ULID tidak memuat tanda hubung, jadi bagian ketiga dan keempat `posting_id` adalah id aset dan
    -- nomor urutnya.
    select k.tenant_id,
           split_part(k.posting_id, '-', 3) as aset_id,
           split_part(k.posting_id, '-', 4)::int as urut,
           (k.input->'details'->'assets'->0->>'acquisition_value_before')::numeric as sebelum,
           (k.input->'details'->'assets'->0->>'acquisition_value_after')::numeric as sesudah,
           (k.input->'details'->'assets'->0->>'adjustment_amount')::numeric as selisih,
           k.total_debit, k.adjusts_posting_id, k.settlement_mode
    from finance_postings k
    where k.posting_type = 'asset.acquisition_adjustment'
      and k.source_module = 'management-aset'
),
posting_perolehan_tidak_sama_dengan_register as (
    -- Debit posting = nilai perolehan asal aset di register + PPN baris yang dibulatkan ke presisi
    -- posting itu (K-20). Register adalah pembagian nilai baris yang sudah bulat, jadi keduanya harus
    -- sama persis; selisih satu sen pun berarti register dan buku besar berpisah jalan. Saldo awal
    -- tidak ber-PPN, jadi debitnya tepat nilai register. Nilai asal = nilai register hari ini dikurangi
    -- selisih setiap jurnal koreksinya (area 12); koreksi atas jurnal asal yang dicatat manual tidak
    -- berjurnal (K-35), dan run uji beban tidak pernah membuatnya.
    select count(*) as n
    from finance_postings f
    join aset_tr_penerimaan_aset p on p.tenant_id = f.tenant_id and case p.cara_perolehan when 'saldo_awal' then 'AST-OPB-' else 'AST-ACQ-' end || p.id = f.posting_id
    where f.posting_type in ('asset.acquisition', 'asset.opening_balance')
      and f.total_debit <> (
          (select coalesce(sum(a.acquisition_value - coalesce((
               select sum(k.selisih) from koreksi_nilai k where k.tenant_id = a.tenant_id and k.aset_id = a.id
           ), 0)), 0) from aset_tr_aset a where a.penerimaan_aset_id = p.id)
        + (select coalesce(sum(round(d.ppn_per_unit * d.jumlah, f.currency_decimals)), 0)
           from aset_tr_penerimaan_aset_details d where d.penerimaan_aset_id = p.id)
      )
),
posting_saldo_awal_tidak_sama_dengan_register as (
    -- Akumulasi yang dikreditkan jurnal saldo awal — baris ber-`mapping.reference` kolom akumulasi
    -- penyusutan — wajib sama dengan akumulasi awal buku yang di-post di register, untuk setiap
    -- aset yang disebut rincian posting itu (K-13, K-28). Selisih berarti buku besar membuka
    -- saldo yang berbeda dari register, atau buku fiskal terbaca sebagai buku yang di-post.
    select count(*) as n
    from finance_postings f
    where f.posting_type = 'asset.opening_balance'
      and coalesce((
          select sum((l->>'credit')::numeric) from jsonb_array_elements(f.input->'lines') l
          where l->'mapping'->>'reference' like '%:accumulated_depreciation_account_id'
      ), 0) <> coalesce((
          select sum(b.opening_accumulated_depreciation)
          from jsonb_array_elements(f.input->'details'->'assets') a
          join aset_tr_aset x on x.tenant_id = f.tenant_id and x.kode = a->>'asset_code'
          join aset_tr_buku_aset b on b.aset_id = x.id and b.book_code = a->>'book'
      ), 0)
),
penyusutan_di_post_tanpa_posting as (
    -- Periode yang ditandai sudah di-post (area 11) wajib menunjuk posting finance yang benar-benar
    -- ada di tenant yang sama, berjenis sesuai barisnya: `asset.depreciation` untuk periode asli,
    -- `asset.depreciation_reversal` untuk baris pembalik. Penanda tanpa posting berarti register
    -- mengira beban sudah sampai ke buku besar padahal tidak.
    select count(*) as n
    from aset_tr_penyusutan_aset p
    where p.posted_posting_id is not null
      and not exists (
          select 1 from finance_postings f
          where f.tenant_id = p.tenant_id
            and f.posting_id = p.posted_posting_id
            and f.posting_type = case when p.reverses_period_id is null then 'asset.depreciation' else 'asset.depreciation_reversal' end
      )
),
posting_penyusutan_tidak_sama_dengan_register as (
    -- Total satu posting `asset.depreciation` wajib sama persis dengan jumlah periode register yang
    -- ditandainya (K-14, TODO 11.5.1). Periode yang ikut dua proses post, atau posting yang terbit
    -- tanpa menandai periodenya, muncul di sini sebagai selisih.
    select count(*) as n
    from finance_postings f
    where f.posting_type = 'asset.depreciation'
      and f.source_module = 'management-aset'
      and f.total_debit <> coalesce((
          select sum(p.amount) from aset_tr_penyusutan_aset p
          where p.tenant_id = f.tenant_id and p.posted_posting_id = f.posting_id and p.reverses_period_id is null
      ), 0)
),
pembalikan_penyusutan_tidak_mengikuti_asal as (
    -- Pembalikan mengikuti periode aslinya (TODO 11.3): jurnal balik terbit tepat bila periode
    -- aslinya sudah di-post, merujuk posting asal itu, dan bernilai sama dengan periode aslinya.
    -- Periode yang dibalik sebelum di-post lalu ikut proses post juga muncul di sini.
    select count(*) as n
    from aset_tr_penyusutan_aset r
    join aset_tr_penyusutan_aset o on o.tenant_id = r.tenant_id and o.id = r.reverses_period_id
    left join finance_postings f on f.tenant_id = r.tenant_id and f.posting_id = r.posted_posting_id
    where (o.posted_posting_id is null) <> (r.posted_posting_id is null)
       or (r.posted_posting_id is not null and (
              f.id is null
              or f.reverses_posting_id is distinct from o.posted_posting_id
              or f.total_debit <> o.amount
          ))
),
nilai_asal_terkoreksi as (
    -- Aset yang punya jurnal koreksi, dengan jurnal perolehan asalnya: nilai aset itu di rincian jurnal
    -- asal, dan mode yang tercatat di sana.
    select a.tenant_id, a.id as aset_id, a.acquisition_value as register, f.posting_id as asal, f.settlement_mode,
           (select (x->>'acquisition_value')::numeric from jsonb_array_elements(f.input->'details'->'assets') x
            where x->>'asset_code' = a.kode limit 1) as awal
    from aset_tr_aset a
    join aset_tr_penerimaan_aset p on p.tenant_id = a.tenant_id and p.id = a.penerimaan_aset_id
    join finance_postings f on f.tenant_id = a.tenant_id and f.posting_id = case p.cara_perolehan when 'saldo_awal' then 'AST-OPB-' else 'AST-ACQ-' end || p.id
    where exists (select 1 from koreksi_nilai k where k.tenant_id = a.tenant_id and k.aset_id = a.id)
),
koreksi_nilai_rantai_putus as (
    -- Tiap koreksi berangkat dari nilai sesudah koreksi sebelumnya — koreksi pertama dari nilai di
    -- jurnal perolehan asalnya — dan jurnalnya sebesar selisih itu, merujuk jurnal asal dengan mode
    -- yang sama (K-10, K-33). Dua koreksi serentak yang membaca nilai lama muncul di sini sebagai
    -- "sebelum" yang tidak sama dengan "sesudah" pendahulunya.
    select count(*) as n
    from koreksi_nilai k
    left join koreksi_nilai s on s.tenant_id = k.tenant_id and s.aset_id = k.aset_id and s.urut = k.urut - 1
    left join nilai_asal_terkoreksi o on o.tenant_id = k.tenant_id and o.aset_id = k.aset_id
    where k.sebelum is distinct from case when k.urut = 1 then o.awal else s.sesudah end
       or k.selisih <> k.sesudah - k.sebelum
       or k.total_debit <> abs(k.selisih)
       or k.adjusts_posting_id is distinct from o.asal
       or k.settlement_mode is distinct from o.settlement_mode
),
koreksi_nilai_tidak_sampai_register as (
    -- Nilai sesudah koreksi terakhir, dan nilai asal ditambah seluruh selisihnya, sama dengan nilai
    -- register hari ini. Koreksi yang mengubah register tanpa jurnal, atau jurnal tanpa perubahan
    -- register, membuat buku besar dan register berpisah.
    select count(*) as n
    from nilai_asal_terkoreksi o
    where o.register is distinct from (select k.sesudah from koreksi_nilai k where k.tenant_id = o.tenant_id and k.aset_id = o.aset_id order by k.urut desc limit 1)
       or o.register is distinct from o.awal + (select sum(k.selisih) from koreksi_nilai k where k.tenant_id = o.tenant_id and k.aset_id = o.aset_id)
),
koreksi_nilai_nomor_bolong as (
    -- Nomor urut koreksi satu aset 1, 2, 3, … tanpa lubang. Nomor ganda sudah ditolak indeks unik
    -- `(tenant_id, posting_id)`; yang tersisa dijaga kunci baris aset.
    select count(*) as n from (
        select tenant_id, aset_id from koreksi_nilai group by 1, 2 having count(*) <> max(urut)
    ) d
),
aset_penerimaan_tidak_sesuai_jumlah as (
    select count(*) as n
    from aset_tr_penerimaan_aset p
    where p.status = 'selesai'
      and (select count(*) from aset_tr_aset a where a.penerimaan_aset_id = p.id)
          <> (select coalesce(sum(d.jumlah), 0) from aset_tr_penerimaan_aset_details d where d.penerimaan_aset_id = p.id)
),
posting_group_ganda as (
    -- Ditahan skema, bukan kode: indeks unik parsial `(tenant_id, group_aset_id, effective_from)
    -- WHERE deleted_at IS NULL`. Yang dijaga kode adalah jawabannya — tanpa kunci pada baris
    -- group, pembuatan kedua menabrak indeks ini dan dijawab 500 (`server_errors` k6).
    select count(*) as n from (
        select tenant_id, group_aset_id, effective_from from aset_m_posting_group
        where deleted_at is null group by 1, 2, 3 having count(*) > 1
    ) d
),
posting_group_group_lintas_tenant as (
    -- Ditahan skema: kunci asing komposit `(tenant_id, group_aset_id)`.
    select count(*) as n
    from aset_m_posting_group p
    join aset_m_group_aset g on g.id = p.group_aset_id
    where g.tenant_id <> p.tenant_id
),
posting_group_akun_asing as (
    -- Satu-satunya yang ditahan kode: kolom akun menyimpan id daftar akun milik Core TANPA kunci
    -- asing, dan hanya pemeriksaan `DaftarAkun` di controller yang menolak akun tenant lain atau
    -- akun yang tidak ada.
    select count(*) as n
    from aset_m_posting_group p
    cross join lateral (values
        (p.acquisition_account_id), (p.accumulated_depreciation_account_id),
        (p.depreciation_expense_account_id), (p.payable_account_id), (p.clearing_account_id),
        (p.input_vat_account_id), (p.opening_balance_offset_account_id)
    ) as k(account_id)
    where k.account_id is not null
      and not exists (
          select 1 from finance_reference_accounts a
          where a.id = k.account_id and a.tenant_id = p.tenant_id
      )
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
union all select 'akumulasi buku aset tidak sama dengan jumlah periode final', n from saldo_buku_tidak_cocok_periode
union all select 'kode diketik group aset atau buku penyusutan tidak sah', n from kode_diketik_tidak_sah
union all select 'penerimaan selesai tanpa tepat satu posting perolehan', n from penerimaan_tanpa_posting
union all select 'posting perolehan tanpa penerimaan selesai di tenant yang sama', n from posting_perolehan_tanpa_penerimaan_selesai
union all select 'debit posting perolehan tidak sama dengan register ditambah PPN', n from posting_perolehan_tidak_sama_dengan_register
union all select 'akumulasi jurnal saldo awal tidak sama dengan register', n from posting_saldo_awal_tidak_sama_dengan_register
union all select 'periode ditandai di-post tanpa posting berjenis benar', n from penyusutan_di_post_tanpa_posting
union all select 'total posting penyusutan tidak sama dengan register', n from posting_penyusutan_tidak_sama_dengan_register
union all select 'pembalikan penyusutan tidak mengikuti periode aslinya', n from pembalikan_penyusutan_tidak_mengikuti_asal
union all select 'rantai koreksi nilai perolehan terputus atau tidak merujuk jurnal asalnya', n from koreksi_nilai_rantai_putus
union all select 'koreksi nilai perolehan tidak sampai ke register', n from koreksi_nilai_tidak_sampai_register
union all select 'nomor urut koreksi nilai perolehan bolong', n from koreksi_nilai_nomor_bolong
union all select 'jumlah aset penerimaan tidak sama dengan jumlah unit barisnya', n from aset_penerimaan_tidak_sesuai_jumlah
union all select 'posting group ganda untuk group dan tanggal yang sama', n from posting_group_ganda
union all select 'posting group menunjuk group tenant lain', n from posting_group_group_lintas_tenant
union all select 'posting group menunjuk akun tenant lain atau yang tidak ada', n from posting_group_akun_asing;

select pemeriksaan, pelanggaran from hasil_aset order by pemeriksaan;

\echo
\echo '=== VOLUME DATA ==='
select 'aset_m_group_aset' as tabel, count(*) as baris, count(distinct tenant_id) as tenant from aset_m_group_aset
union all select 'aset_m_jenis_aset', count(*), count(distinct tenant_id) from aset_m_jenis_aset
union all select 'aset_m_model_aset', count(*), count(distinct tenant_id) from aset_m_model_aset
union all select 'aset_m_tipe_lokasi_aset', count(*), count(distinct tenant_id) from aset_m_tipe_lokasi_aset
union all select 'aset_m_buku_penyusutan', count(*), count(distinct tenant_id) from aset_m_buku_penyusutan
union all select 'aset_m_group_buku_penyusutan', count(*), count(distinct tenant_id) from aset_m_group_buku_penyusutan
union all select 'aset_m_posting_group', count(*), count(distinct tenant_id) from aset_m_posting_group
union all select 'aset_tr_penerimaan_aset', count(*), count(distinct tenant_id) from aset_tr_penerimaan_aset
union all select 'finance_postings asset.acquisition', count(*), count(distinct tenant_id) from finance_postings where posting_type = 'asset.acquisition'
union all select 'finance_postings asset.opening_balance', count(*), count(distinct tenant_id) from finance_postings where posting_type = 'asset.opening_balance'
union all select 'finance_postings asset.depreciation', count(*), count(distinct tenant_id) from finance_postings where posting_type = 'asset.depreciation'
union all select 'finance_postings asset.depreciation_reversal', count(*), count(distinct tenant_id) from finance_postings where posting_type = 'asset.depreciation_reversal'
union all select 'finance_postings asset.acquisition_adjustment', count(*), count(distinct tenant_id) from finance_postings where posting_type = 'asset.acquisition_adjustment'
union all select 'aset_tr_penyusutan_aset sudah di-post', count(*), count(distinct tenant_id) from aset_tr_penyusutan_aset where posted_posting_id is not null
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
