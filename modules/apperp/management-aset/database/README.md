# Database

## Dekomisioning dan keputusan workflow

`tr_dokumen_siklus_aset.workflow_instance_id` mengikat usulan dekomisioning ke workflow CoreERP. `processed_core_events` adalah inbox idempoten: event keputusan yang sama hanya mengubah status sekali. Kedua tabel tetap berada di database Aset; CoreERP tidak mengaksesnya langsung.

Migration module berada di `migrations/` dan dijalankan `ModuleMigrator` milik Core, bukan didaftarkan penyedia layanan module. Pencatatannya per module supaya pemasangan dan pencabutan per tenant bisa dilacak.

Database ini hanya dimiliki Management Aset. Referensi tenant dan unit organisasi disimpan sebagai ID opaque; tidak ada foreign key atau query ke database Core.

## Tabel master

| Tabel | Foreign key |
| --- | --- |
| `m_group_aset` | — |
| `m_kelompok_harta_fiskal` | Referensi fiskal tenant; tidak memakai Number Sequence |
| `m_jenis_aset` | — |
| `m_model_aset` | `(tenant_id, pabrikan_aset_id)` → `m_pabrikan_aset (tenant_id, id)`, `(tenant_id, jenis_aset_id)` → `m_jenis_aset (tenant_id, id)` |
| `m_kondisi_aset` | — |
| `m_pabrikan_aset` | — |
| `m_tipe_lokasi_aset` | — |
| `m_lokasi_aset` | `(tenant_id, parent_id)` → `m_lokasi_aset (tenant_id, id)`, `(tenant_id, tipe_lokasi_id)` → `m_tipe_lokasi_aset (tenant_id, id)` |
| `m_item_checklist_maintenance` | — |
| `m_analisa_maintenance` | — |
| `m_profil_penyusutan` | — |
| `m_tipe_atribut` | — |
| `m_tipe_atribut_nilai` | `(tenant_id, tipe_atribut_id)` → `m_tipe_atribut (tenant_id, id)` |
| `m_jenis_aset_atribut` | `(tenant_id, jenis_aset_id)` → `m_jenis_aset`, `(tenant_id, tipe_atribut_id)` → `m_tipe_atribut` |

Maintenance setup menambah tabel `m_maintenance_job_type`, `m_maintenance_job_type_variant`,
`m_maintenance_job_type_default`, `m_maintenance_job_type_asset_type`,
`m_maintenance_checklist_variable`, `m_maintenance_checklist_variable_value`,
`m_maintenance_checklist_template`, dan `m_maintenance_checklist_template_line`. Semua tabel
ini tenant-scoped dan memakai foreign key gabungan dengan `tenant_id` untuk mencegah
referensi lintas tenant.

Baris template bertipe `measurement` menunjuk `unit_id` milik CoreERP dan menyimpan kode
satuan sebagai snapshot tampilan. Batas minimum/maksimum opsional harus diisi berpasangan;
saat template disalin, batas tersebut ikut ke checklist work order dan angka di luar rentang
ditandai gagal.

`m_maintenance_job_type_requirement` sudah dihapus. Skill dan sertifikat adalah kompetensi
milik Human Resources yang dipasang pada pekerja; job type hanya boleh menyimpan persyaratan
yang merujuk kompetensi itu, bukan menuliskannya sebagai teks bebas di database aset.
Persyaratan dibangun ulang sebagai referensi ke Workforce Core setelah kontraknya tersedia,
bersama penjadwalan berbasis kompetensi yang menjadi satu-satunya pembacanya.

Work order menambah master `m_tipe_work_order`, `m_tingkat_layanan`, `m_trade`,
`m_sebab_kerusakan`, dan `m_tindakan_perbaikan`, serta transaksi `tr_pemeliharaan_aset`,
`tr_pemeliharaan_aset_details`, `tr_pemeliharaan_aset_checklist`, dan
`tr_pemeliharaan_aset_status_log`.

Master sebab dan tindakan memiliki `minta_keterangan`. Jika aktif, baris pekerjaan wajib
menyimpan teks bebas pada `sebab_kerusakan_keterangan` atau
`tindakan_perbaikan_keterangan`; teks tersebut dikosongkan bila pilihan tidak memintanya.

`m_validasi_status_work_order` menyimpan aturan yang harus dipenuhi sebelum work order boleh
berpindah ke satu status. Ia melekat pada status tujuan, bukan pada tipe work order,
sehingga pemeriksaan yang sama dapat longgar saat pekerjaan dijadwalkan dan ketat saat
dinyatakan selesai. Tiap aturan punya tingkat keparahan: `informasi` hanya dicatat,
`peringatan` membiarkan transisi berjalan tetapi tersimpan di kolom `peringatan` pada jejak
status, dan `error` menolak transisi. Barisnya matriks tetap status x aturan yang disemai
saat provisioning; tenant hanya mengubah keaktifan dan keparahannya.

Master maintenance memakai nomor tenant-scoped `JPMA`, `VJMA`, `DJMA`, `VCMA`, dan `TCMA`.
Seed Indonesia bersifat idempoten melalui `creation_key`; baris yang sudah ada tidak ditimpa
sehingga tenant dapat menyesuaikan kategori, trade, varian, dan checklistnya.

Seed katalog Indonesia–Asia pada `m_pabrikan_aset` dan `m_model_aset` memakai sub-template
`id:manufacturer-models:indonesia-asia:v1`. Isinya 68 pabrikan dan 209 model/seri; setiap
model menunjuk pabrikan yang sama tenant, sementara `jenis_aset_id` dan `model_number`
dibiarkan `NULL` agar tenant dapat mengaitkannya kemudian.

Sebagian master membawa kolom tambahan di luar bentuk dasar: `m_group_aset` menyimpan perlakuan finansial (`kelompok_harta_fiskal_id`, `property_type`, `asset_location_id`, `capitalization_threshold`), `m_kelompok_harta_fiskal` menyimpan referensi regulasi berversi, `m_model_aset` menyimpan `model_number`, `m_lokasi_aset` menyimpan `org_unit_id`, dan `m_profil_penyusutan` menyimpan aturan penyusutannya.

`m_tipe_atribut.data_type` menyimpan tipe dasar `string`, `decimal`, `integer`, `date`, atau `boolean`. Values aktif berada terpisah di `m_tipe_atribut_nilai`; min/max opsional berada pada tipe atribut dan wajib berpasangan untuk angka. `data_type_locked` menjadi benar saat nilai pertama berhasil ditulis ke `tr_aset_atribut` dan tidak dibuka kembali saat nilai aset dikoreksi atau dihapus.

`m_kelompok_harta_fiskal` memiliki `template_key`, yurisdiksi, label, metadata regulasi,
tanggal berlaku, umur manfaat, tarif penyusutan, dan penanda aktif. `template_key` hanya
untuk seed idempoten; ia bukan nomor bisnis. `tr_penerimaan_aset.kelompok_harta_fiskal_id`
adalah snapshot versi yang dipakai ketika aset diterima, sehingga perubahan referensi group
tidak menulis ulang histori aset.

`m_lokasi_aset.org_unit_id` dan `tr_penerimaan_aset.financial_dimension_org_unit_id` adalah ID opaque milik Core, jadi keduanya sengaja **tanpa foreign key**. Nilai pada aset disalin dari lokasinya saat penerimaan dan mutasi; ia snapshot keputusan saat itu, bukan lookup yang ikut berubah bila pemetaan lokasi diubah kemudian.

Setiap tabel master memakai kolom yang sama: `id` (ULID), `tenant_id`, `creation_key`, `kode`, `nama`, `keterangan`, `aktif`, `deleted_at`, dan timestamps. Constraint yang berlaku pada semuanya:

- `unique (tenant_id, kode)` — kode unik per tenant.
- `unique (tenant_id, creation_key)` — satu `Idempotency-Key` hanya menghasilkan satu record per tenant.

## Tabel transaksi

| Tabel | Fungsi |
| --- | --- |
| `tr_penerimaan_aset` | Register yang terbentuk saat aset diterima. |
| `tr_penempatan_aset` | Riwayat unit pengguna, PIC, dan lokasi aset. |
| `tr_buku_aset` | Nilai buku aset untuk penyusutan, termasuk snapshot kelipatan pembulatan dari Book/matriks. |
| `tr_penyusutan_aset` | Proposal, finalisasi, dan reversal penyusutan per periode. |
| `tr_export_penyusutan` | Bukti export penyusutan ke backoffice. |
| `tr_dokumen_siklus_aset` | Dokumen lifecycle yang sudah tersedia. |
| `tr_perencanaan_aset` | Header perencanaan aset per entitas legal dan unit kerja. |
| `tr_perencanaan_aset_details` | Rincian jenis aset, jumlah, harga perkiraan, dan spesifikasi yang diminta. |
| `tr_aset_atribut` | Nilai atribut bertipe per aset; tipe dasar pemiliknya dikunci saat baris pertama tersimpan. |

Konvensi tabel: master memakai `m_`; transaksi memakai `tr_`; dan detail
transaksi yang memiliki header sendiri memakai akhiran `_details`.

## Foreign key lintas tabel app

Foreign key antar master sengaja **gabungan** dengan `tenant_id`, bukan hanya kolom induk. Karena itu tabel induk juga memiliki `unique (tenant_id, id)` sebagai target referensi. Efeknya: menyimpan anak yang menunjuk induk milik tenant lain gagal di level database, bukan hanya di validasi aplikasi.

Foreign key memakai `on delete restrict`. Arsip adalah soft delete (`UPDATE deleted_at`), sehingga arsip tidak pernah memutus referensi; API menolak arsip induk yang masih punya anak aktif dengan `409 referenced_by_children`.

## Klasifikasi aset

Master klasifikasi mengikuti model Dynamics 365 F&O: **datar dan saling lepas**. Aset menunjuk dua sumbu wajib secara langsung dan sejajar — `group_aset_id` untuk perlakuan finansial (penyusutan, GL, penomoran) dan `jenis_aset_id` untuk perlakuan teknis (maintenance, atribut). Tidak ada rantai berjenjang di antara keduanya, sehingga tenant yang hanya mengenal satu tingkat klasifikasi tidak perlu mengisi tingkat yang tidak mereka punya.

`m_model_aset` adalah katalog barang per pabrikan (padanan "Manufacturers and models"), dengan dua induk yang saling lepas: pabrikan wajib, jenis opsional.

Yang hierarkis hanyalah **data**, bukan skema: `m_lokasi_aset.parent_id` dan `tr_penerimaan_aset.parent_asset_id` menunjuk dirinya sendiri sedalam yang dibutuhkan tenant. Keduanya struktur domain milik app ini, bukan organization hierarchy CoreERP, jadi `parent_id` permanen di sini tidak melanggar aturan hierarchy pada `docs/dev/01a-tenant-and-org-hierarchy.md`.
# Dekomisioning dan event workflow

`tr_dokumen_siklus_aset` menyimpan `workflow_instance_id` untuk usulan dekomisioning. `processed_core_events` adalah inbox idempoten: satu event keputusan Core hanya dapat mengubah status aset satu kali.
