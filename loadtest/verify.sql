-- Oracle kebenaran setelah load test.
--
-- Dijalankan langsung pada database, bukan lewat API, supaya yang diperiksa adalah
-- keadaan data yang benar-benar tersimpan dan bukan pandangan kode yang sama yang
-- sedang diuji. Setiap baris hasil wajib bernilai 0 pada kolom `pelanggaran`.

\pset pager off
\pset format aligned

\echo
\echo '=== PEMERIKSAAN KEBENARAN (semua pelanggaran harus 0) ==='

with duplikat_kode as (
    select count(*) as n from (
        select tenant_id, kode from m_group_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_jenis_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_model_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_tipe_lokasi_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_buku_penyusutan group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_kondisi_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_pabrikan_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_item_checklist_maintenance group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_analisa_maintenance group by 1, 2 having count(*) > 1
    ) d
),
duplikat_kunci as (
    select count(*) as n from (
        select tenant_id, creation_key from m_group_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_jenis_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_model_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_tipe_lokasi_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_buku_penyusutan group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_kondisi_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_pabrikan_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_item_checklist_maintenance group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_analisa_maintenance group by 1, 2 having count(*) > 1
    ) d
),
induk_lintas_tenant as (
    select
        (select count(*) from m_model_aset c join m_pabrikan_aset p on p.id = c.pabrikan_aset_id where p.tenant_id <> c.tenant_id)
      + (select count(*) from m_model_aset c join m_jenis_aset p on p.id = c.jenis_aset_id where p.tenant_id <> c.tenant_id)
      + (select count(*) from m_lokasi_aset c join m_lokasi_aset p on p.id = c.parent_id where p.tenant_id <> c.tenant_id)
      + (select count(*) from m_lokasi_aset c join m_tipe_lokasi_aset p on p.id = c.tipe_lokasi_id where p.tenant_id <> c.tenant_id)
      + (select count(*) from m_group_buku_penyusutan m join m_group_aset g on g.id = m.group_aset_id where g.tenant_id <> m.tenant_id)
      + (select count(*) from m_group_buku_penyusutan m join m_buku_penyusutan b on b.id = m.buku_id where b.tenant_id <> m.tenant_id)
      + (select count(*) from tr_buku_aset k join m_buku_penyusutan b on b.id = k.buku_id where b.tenant_id <> k.tenant_id)
      + (select count(*) from tr_penerimaan_aset a join m_group_aset p on p.id = a.group_aset_id where p.tenant_id <> a.tenant_id)
      + (select count(*) from tr_penerimaan_aset a join m_jenis_aset p on p.id = a.jenis_aset_id where p.tenant_id <> a.tenant_id) as n
),
induk_hilang as (
    select
        (select count(*) from m_model_aset c left join m_pabrikan_aset p on p.id = c.pabrikan_aset_id where p.id is null)
      + (select count(*) from m_model_aset c left join m_jenis_aset p on p.id = c.jenis_aset_id where c.jenis_aset_id is not null and p.id is null)
      + (select count(*) from tr_penerimaan_aset a left join m_group_aset p on p.id = a.group_aset_id where p.id is null) as n
),
prefix_salah as (
    -- Prefix kode berasal dari reference Number Sequence yang berbeda per master.
    -- Prefix yang tertukar berarti satu master memakai reference milik master lain.
    select
        (select count(*) from m_group_aset where kode not like 'GRPA%')
      + (select count(*) from m_jenis_aset where kode not like 'JNSA%')
      + (select count(*) from m_model_aset where kode not like 'MDLA%')
      + (select count(*) from m_tipe_lokasi_aset where kode not like 'TLKA%')
      + (select count(*) from m_buku_penyusutan where kode not like 'BKPY%')
      + (select count(*) from m_kondisi_aset where kode not like 'KNDA%')
      + (select count(*) from m_pabrikan_aset where kode not like 'PBRA%')
      + (select count(*) from m_item_checklist_maintenance where kode not like 'ICMA%')
      + (select count(*) from m_analisa_maintenance where kode not like 'ANMA%') as n
),
tenant_kosong as (
    select
        (select count(*) from m_group_aset where tenant_id is null or length(tenant_id) <> 26)
      + (select count(*) from m_jenis_aset where tenant_id is null or length(tenant_id) <> 26)
      + (select count(*) from m_model_aset where tenant_id is null or length(tenant_id) <> 26) as n
),
aset_duplikat as (
    select count(*) as n from (
        select tenant_id, kode from tr_penerimaan_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from tr_penerimaan_aset group by 1, 2 having count(*) > 1
    ) d
),
penempatan_lintas_tenant as (
    select count(*) as n from tr_penempatan_aset p join tr_penerimaan_aset a on a.id = p.asset_id where p.tenant_id <> a.tenant_id
),
dokumen_lintas_tenant as (
    select count(*) as n from tr_dokumen_siklus_aset d join tr_penerimaan_aset a on a.id = d.asset_id where d.asset_id is not null and d.tenant_id <> a.tenant_id
),
perencanaan_duplikat as (
    select count(*) as n from (
        select tenant_id, kode from tr_perencanaan_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from tr_perencanaan_aset group by 1, 2 having count(*) > 1
    ) d
),
perencanaan_detail_tidak_sah as (
    select
        (select count(*) from tr_perencanaan_aset_details d join tr_perencanaan_aset h on h.id = d.planning_id where d.tenant_id <> h.tenant_id)
      + (select count(*) from tr_perencanaan_aset_details d join m_jenis_aset j on j.id = d.jenis_aset_id where d.tenant_id <> j.tenant_id)
      + (select count(*) from tr_perencanaan_aset_details d left join tr_perencanaan_aset h on h.id = d.planning_id where h.id is null) as n
),
perencanaan_prefix_salah as (
    select count(*) as n from tr_perencanaan_aset where kode not like 'PLNA%'
),
export_ganda as (
    select count(*) as n from (
        select tenant_id, posting_id from tr_export_penyusutan group by 1, 2 having count(*) > 1
        union all select tenant_id, depreciation_period_id from tr_export_penyusutan group by 1, 2 having count(*) > 1
    ) d
),
depreciation_negative as (
    select count(*) as n from tr_penyusutan_aset
    where reverses_period_id is null and amount < 0
),
depreciation_nbv_below_residual as (
    select count(*) as n from tr_buku_aset
    where net_book_value < greatest(coalesce(residual_value, 0), 0)
),
depreciation_final_ganda as (
    select count(*) as n from (
        select tenant_id, asset_book_id, period_ends_on
        from tr_penyusutan_aset
        where reverses_period_id is null and status = 'final'
        group by 1, 2, 3 having count(*) > 1
    ) d
),
depreciation_rounding_not_finished as (
    select count(*) as n from (
        select b.id
        from tr_buku_aset b
        left join tr_penyusutan_aset p
          on p.tenant_id = b.tenant_id
         and p.asset_book_id = b.id
         and p.reverses_period_id is null
         and p.status = 'final'
        where b.round_off_depreciation > 0
          and b.useful_life_periods is not null
        group by b.id, b.residual_value, b.net_book_value, b.useful_life_periods
        having count(p.id) >= b.useful_life_periods
           and b.net_book_value <> coalesce(b.residual_value, 0)
    ) d
)
select 'kode ganda dalam satu tenant' as pemeriksaan, n as pelanggaran from duplikat_kode
union all select 'creation_key ganda dalam satu tenant', n from duplikat_kunci
union all select 'anak menunjuk induk tenant lain', n from induk_lintas_tenant
union all select 'anak dengan induk tidak ada', n from induk_hilang
union all select 'prefix kode tidak sesuai reference master', n from prefix_salah
union all select 'tenant_id kosong atau bukan ULID', n from tenant_kosong
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
union all select 'round-off tidak mendarat di residual atau nol', n from depreciation_rounding_not_finished;

\echo
\echo '=== VOLUME DATA ==='
select 'm_group_aset' as tabel, count(*) as baris, count(distinct tenant_id) as tenant from m_group_aset
union all select 'm_jenis_aset', count(*), count(distinct tenant_id) from m_jenis_aset
union all select 'm_model_aset', count(*), count(distinct tenant_id) from m_model_aset
union all select 'm_tipe_lokasi_aset', count(*), count(distinct tenant_id) from m_tipe_lokasi_aset
union all select 'm_buku_penyusutan', count(*), count(distinct tenant_id) from m_buku_penyusutan
union all select 'm_group_buku_penyusutan', count(*), count(distinct tenant_id) from m_group_buku_penyusutan
union all select 'm_lokasi_aset', count(*), count(distinct tenant_id) from m_lokasi_aset
union all select 'm_kondisi_aset', count(*), count(distinct tenant_id) from m_kondisi_aset
union all select 'm_pabrikan_aset', count(*), count(distinct tenant_id) from m_pabrikan_aset
union all select 'm_item_checklist_maintenance', count(*), count(distinct tenant_id) from m_item_checklist_maintenance
union all select 'm_analisa_maintenance', count(*), count(distinct tenant_id) from m_analisa_maintenance
union all select 'tr_penerimaan_aset', count(*), count(distinct tenant_id) from tr_penerimaan_aset
union all select 'tr_penempatan_aset', count(*), count(distinct tenant_id) from tr_penempatan_aset
union all select 'tr_dokumen_siklus_aset', count(*), count(distinct tenant_id) from tr_dokumen_siklus_aset
union all select 'tr_perencanaan_aset', count(*), count(distinct tenant_id) from tr_perencanaan_aset
union all select 'tr_perencanaan_aset_details', count(*), count(distinct tenant_id) from tr_perencanaan_aset_details
union all select 'tr_penyusutan_aset', count(*), count(distinct tenant_id) from tr_penyusutan_aset
union all select 'tr_export_penyusutan', count(*), count(distinct tenant_id) from tr_export_penyusutan
order by 1;
