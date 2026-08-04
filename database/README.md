# Database

## Dekomisioning dan keputusan workflow

`tr_dokumen_siklus_aset.workflow_instance_id` mengikat usulan dekomisioning ke workflow CoreERP. `processed_core_events` adalah inbox idempoten: event keputusan yang sama hanya mengubah status sekali. Kedua tabel tetap berada di database Aset; CoreERP tidak mengaksesnya langsung.

Migration app berada di `migrations/` dan dijalankan oleh Laravel API melalui `api/app/Providers/AppServiceProvider.php`.

Database ini hanya dimiliki Management Aset. Referensi tenant dan unit organisasi disimpan sebagai ID opaque; tidak ada foreign key atau query ke database Core.

## Tabel master

| Tabel | Foreign key |
| --- | --- |
| `m_entitas_aset` | `(tenant_id, jenis_aset_id)` → `m_jenis_aset (tenant_id, id)` |
| `m_group_aset` | — |
| `m_kategori_aset` | `(tenant_id, group_aset_id)` → `m_group_aset (tenant_id, id)` |
| `m_jenis_aset` | `(tenant_id, kategori_aset_id)` → `m_kategori_aset (tenant_id, id)` |
| `m_kondisi_aset` | — |
| `m_pabrikan_aset` | — |
| `m_item_checklist_maintenance` | — |
| `m_analisa_maintenance` | — |

Setiap tabel master memakai kolom yang sama: `id` (ULID), `tenant_id`, `creation_key`, `kode`, `nama`, `keterangan`, `aktif`, `deleted_at`, dan timestamps. Constraint yang berlaku pada semuanya:

- `unique (tenant_id, kode)` — kode unik per tenant.
- `unique (tenant_id, creation_key)` — satu `Idempotency-Key` hanya menghasilkan satu record per tenant.

## Tabel transaksi

| Tabel | Fungsi |
| --- | --- |
| `tr_penerimaan_aset` | Register yang terbentuk saat aset diterima. |
| `tr_penempatan_aset` | Riwayat unit pengguna, PIC, dan lokasi aset. |
| `tr_buku_aset` | Nilai buku aset untuk penyusutan. |
| `tr_penyusutan_aset` | Proposal, finalisasi, dan reversal penyusutan per periode. |
| `tr_export_penyusutan` | Bukti export penyusutan ke backoffice. |
| `tr_dokumen_siklus_aset` | Dokumen lifecycle yang sudah tersedia. |
| `tr_perencanaan_aset` | Header perencanaan aset per entitas legal dan unit kerja. |
| `tr_perencanaan_aset_details` | Rincian jenis aset, jumlah, harga perkiraan, dan spesifikasi yang diminta. |

Konvensi tabel: master memakai `m_`; transaksi memakai `tr_`; dan detail
transaksi yang memiliki header sendiri memakai akhiran `_details`.

## Foreign key lintas tabel app

Foreign key rantai klasifikasi sengaja **gabungan** dengan `tenant_id`, bukan hanya kolom induk. Karena itu tabel induk juga memiliki `unique (tenant_id, id)` sebagai target referensi. Efeknya: menyimpan anak yang menunjuk induk milik tenant lain gagal di level database, bukan hanya di validasi aplikasi.

Foreign key memakai `on delete restrict`. Arsip adalah soft delete (`UPDATE deleted_at`), sehingga arsip tidak pernah memutus referensi; API menolak arsip induk yang masih punya anak aktif dengan `409 referenced_by_children`.

Rantai `group aset → kategori aset → jenis aset → entitas aset` adalah struktur domain milik app ini. Ia bukan organization hierarchy CoreERP, jadi `parent_id` permanen di sini tidak melanggar aturan hierarchy pada `docs/dev/01a-tenant-and-org-hierarchy.md`.
# Dekomisioning dan event workflow

`tr_dokumen_siklus_aset` menyimpan `workflow_instance_id` untuk usulan dekomisioning. `processed_core_events` adalah inbox idempoten: satu event keputusan Core hanya dapat mengubah status aset satu kali.
