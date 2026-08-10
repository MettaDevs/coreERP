# Database

## Dekomisioning dan keputusan workflow

`tr_dokumen_siklus_aset.workflow_instance_id` mengikat usulan dekomisioning ke workflow CoreERP. `processed_core_events` adalah inbox idempoten: event keputusan yang sama hanya mengubah status sekali. Kedua tabel tetap berada di database Aset; CoreERP tidak mengaksesnya langsung.

Migration app berada di `migrations/` dan dijalankan oleh Laravel API melalui `api/app/Providers/AppServiceProvider.php`.

Database ini hanya dimiliki Management Aset. Referensi tenant dan unit organisasi disimpan sebagai ID opaque; tidak ada foreign key atau query ke database Core.

## Tabel master

| Tabel | Foreign key |
| --- | --- |
| `m_group_aset` | — |
| `m_jenis_aset` | — |
| `m_model_aset` | `(tenant_id, pabrikan_aset_id)` → `m_pabrikan_aset (tenant_id, id)`, `(tenant_id, jenis_aset_id)` → `m_jenis_aset (tenant_id, id)` |
| `m_kondisi_aset` | — |
| `m_pabrikan_aset` | — |
| `m_tipe_lokasi_aset` | — |
| `m_lokasi_aset` | `(tenant_id, parent_id)` → `m_lokasi_aset (tenant_id, id)`, `(tenant_id, tipe_lokasi_id)` → `m_tipe_lokasi_aset (tenant_id, id)` |
| `m_item_checklist_maintenance` | — |
| `m_analisa_maintenance` | — |
| `m_profil_penyusutan` | — |

Sebagian master membawa kolom tambahan di luar bentuk dasar: `m_group_aset` menyimpan perlakuan finansial (`tipe_harta`, `major_type`, `capitalization_threshold`, `posting_layers`), `m_model_aset` menyimpan `model_number`, `m_lokasi_aset` menyimpan `org_unit_id`, dan `m_profil_penyusutan` menyimpan aturan penyusutannya.

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
