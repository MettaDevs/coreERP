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
        select tenant_id, kode from m_entitas_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_group_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_kategori_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_jenis_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_kondisi_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_pabrikan_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_item_checklist_maintenance group by 1, 2 having count(*) > 1
        union all select tenant_id, kode from m_analisa_maintenance group by 1, 2 having count(*) > 1
    ) d
),
duplikat_kunci as (
    select count(*) as n from (
        select tenant_id, creation_key from m_entitas_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_group_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_kategori_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_jenis_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_kondisi_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_pabrikan_aset group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_item_checklist_maintenance group by 1, 2 having count(*) > 1
        union all select tenant_id, creation_key from m_analisa_maintenance group by 1, 2 having count(*) > 1
    ) d
),
induk_lintas_tenant as (
    select
        (select count(*) from m_entitas_aset c join m_jenis_aset p on p.id = c.jenis_aset_id where p.tenant_id <> c.tenant_id)
      + (select count(*) from m_kategori_aset c join m_group_aset p on p.id = c.group_aset_id where p.tenant_id <> c.tenant_id)
      + (select count(*) from m_jenis_aset c join m_kategori_aset p on p.id = c.kategori_aset_id where p.tenant_id <> c.tenant_id) as n
),
induk_hilang as (
    select
        (select count(*) from m_entitas_aset c left join m_jenis_aset p on p.id = c.jenis_aset_id where p.id is null)
      + (select count(*) from m_kategori_aset c left join m_group_aset p on p.id = c.group_aset_id where p.id is null)
      + (select count(*) from m_jenis_aset c left join m_kategori_aset p on p.id = c.kategori_aset_id where p.id is null) as n
),
prefix_salah as (
    -- Prefix kode berasal dari reference Number Sequence yang berbeda per master.
    -- Prefix yang tertukar berarti satu master memakai reference milik master lain.
    select
        (select count(*) from m_entitas_aset where kode not like 'EA-%')
      + (select count(*) from m_group_aset where kode not like 'GA-%')
      + (select count(*) from m_kategori_aset where kode not like 'KA-%')
      + (select count(*) from m_jenis_aset where kode not like 'JA-%')
      + (select count(*) from m_kondisi_aset where kode not like 'KD-%')
      + (select count(*) from m_pabrikan_aset where kode not like 'PB-%')
      + (select count(*) from m_item_checklist_maintenance where kode not like 'IC-%')
      + (select count(*) from m_analisa_maintenance where kode not like 'AM-%') as n
),
tenant_kosong as (
    select
        (select count(*) from m_entitas_aset where tenant_id is null or length(tenant_id) <> 26)
      + (select count(*) from m_group_aset where tenant_id is null or length(tenant_id) <> 26)
      + (select count(*) from m_kategori_aset where tenant_id is null or length(tenant_id) <> 26) as n
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
union all select 'posting export penyusutan ganda', n from export_ganda;

\echo
\echo '=== VOLUME DATA ==='
select 'm_entitas_aset' as tabel, count(*) as baris, count(distinct tenant_id) as tenant from m_entitas_aset
union all select 'm_group_aset', count(*), count(distinct tenant_id) from m_group_aset
union all select 'm_kategori_aset', count(*), count(distinct tenant_id) from m_kategori_aset
union all select 'm_jenis_aset', count(*), count(distinct tenant_id) from m_jenis_aset
union all select 'm_kondisi_aset', count(*), count(distinct tenant_id) from m_kondisi_aset
union all select 'm_pabrikan_aset', count(*), count(distinct tenant_id) from m_pabrikan_aset
union all select 'm_item_checklist_maintenance', count(*), count(distinct tenant_id) from m_item_checklist_maintenance
union all select 'm_analisa_maintenance', count(*), count(distinct tenant_id) from m_analisa_maintenance
union all select 'tr_penerimaan_aset', count(*), count(distinct tenant_id) from tr_penerimaan_aset
union all select 'tr_penempatan_aset', count(*), count(distinct tenant_id) from tr_penempatan_aset
union all select 'tr_dokumen_siklus_aset', count(*), count(distinct tenant_id) from tr_dokumen_siklus_aset
union all select 'tr_perencanaan_aset', count(*), count(distinct tenant_id) from tr_perencanaan_aset
union all select 'tr_perencanaan_aset_details', count(*), count(distinct tenant_id) from tr_perencanaan_aset_details
order by 1;
