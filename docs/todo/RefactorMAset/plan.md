file_path: C:\Users\Msi_\.claude\plans\apakahs-ekarang-kamu-siap-quizzical-rabbit-agent-a42ba864dd8a1623d.md
parameter: x
content: # Refactor klasifikasi aset ke model Dynamics 365 F&O

Repo: `D:\Kerja\app-erp-management-aset`. Laravel 12 API + React 19/Vite UI, PostgreSQL (test: SQLite :memory:).

Ganti rantai 4-level `group → kategori → jenis → entitas` dengan master **flat & independen**. Hierarki hanya hidup di DATA (lokasi, parent-child aset), tidak pernah di schema.

---

## 0. Keputusan kunci (jawaban langsung atas 7 pertanyaan)

### 0.1 Strategi migration: **REWRITE IN PLACE** (bukan corrective)

Alasan yang menentukan, bukan sekadar preferensi:

**`dropForeign` tidak dapat dijalankan di SQLite.** Terverifikasi di
`api/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/SQLiteGrammar.php:604-607`:

```php
public function compileDropForeign(Blueprint $blueprint, Fluent $command)
{
    throw new RuntimeException('This database driver does not support dropping foreign keys by name.');
}
```

`api/phpunit.xml` memakai `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, dan setiap Feature test memakai `RefreshDatabase` → **seluruh 20 migration dijalankan ulang pada SQLite di setiap test run**. Refactor ini wajib membuang FK komposit `(tenant_id, kategori_aset_id) → m_kategori_aset`, `(tenant_id, jenis_aset_id) → m_jenis_aset` pada `m_entitas_aset`, dan FK `m_kategori_aset → m_group_aset`. Corrective migration yang memanggil `dropForeign` akan **melempar RuntimeException dan mematikan seluruh test suite**. Tidak ada jalan keluar selain rewrite.

Alasan pendukung:
- Tidak ada data produksi (dinyatakan constraint). `2026_07_27_040000_reverse_group_entitas_aset_relation.php` sudah membuktikan preseden: ia melempar `RuntimeException` bila ada data, jadi repo ini memang mengasumsikan DB dapat direset.
- Corrective migration akan meninggalkan jejak absurd: `m_kategori_aset` dibuat lalu di-drop, `default_depreciation_profile_id` ditambah lalu dibuang, `m_entitas_aset` mendapat `jenis_aset_id` lalu diganti `pabrikan_aset_id` + `jenis_aset_id`.
- 3 dari 20 migration yang tersentuh sudah no-op atau near-no-op: `2026_07_28_091000` hanya berisi komentar; `2026_07_27_040000` seluruh `up()`-nya bergantung pada `Schema::hasColumn` yang sudah false pada DB baru.

**Migration yang di-rewrite / dihapus:**

| File | Aksi |
| --- | --- |
| `database/migrations/2026_07_23_000000_create_management_aset_tables.php` | Rewrite — `m_entitas_aset` menjadi `m_model_aset` sejak awal, kolom lengkap |
| `2026_07_24_000000_add_lifecycle_to_m_entitas_aset.php` | **Hapus** — kolom `creation_key`/`softDeletes` dipindah ke migration 1 |
| `2026_07_26_000000_create_master_data_aset_tables.php` | Rewrite — `createMaster()` kehilangan parameter `parentTable`/`parentColumn`, `m_kategori_aset` hilang, `m_jenis_aset` flat |
| `2026_07_27_040000_reverse_group_entitas_aset_relation.php` | **Hapus** — seluruhnya no-op pada DB baru |
| `2026_07_28_090000_create_asset_register_and_depreciation_tables.php` | Rewrite — `t_aset` dapat `group_aset_id`, `model_aset_id`, `parent_asset_id`; `m_lokasi_aset` dapat `tipe_lokasi_id`/`org_unit_id`; `t_buku_aset` dapat `buku_id` |
| `2026_07_28_091000_add_composite_keys_for_asset_references.php` | **Hapus** — isinya hanya dua komentar |
| `2026_07_28_094000_add_asset_group_depreciation_defaults.php` | Rewrite — ganti `default_depreciation_profile_id`/`default_book_code` dengan kolom finansial `m_group_aset` |
| `2026_07_28_097000_add_description_to_asset_locations.php` | **Hapus** — `keterangan` masuk ke definisi `m_lokasi_aset` |
| `2026_07_30_121000_create_asset_planning_tables.php` | Sentuh ringan — FK `jenis_aset_id` tetap valid (`m_jenis_aset` masih ada) |
| `2026_08_03_110000_create_asset_acquisition_request_tables.php` | Tidak berubah — FK ke `m_jenis_aset` tetap valid |

**Migration baru** (murni tambahan, tidak menyentuh yang lama):
- `2026_08_06_100000_create_asset_location_type_master.php`
- `2026_08_06_110000_create_depreciation_book_tables.php`
- `2026_08_06_120000_create_asset_attribute_tables.php`

Aturan tambahan: **verifikasi `php artisan migrate:fresh` pada PostgreSQL nyata**, bukan hanya SQLite. SQLite mengabaikan sebagian besar semantik FK komposit; `loadtest/verify.sql` adalah gate sebenarnya.

### 0.2 Kolom setiap tabel → lihat §2 (daftar lengkap per tabel)

### 0.3 `MasterDataController` multi-parent → lihat §3

### 0.4 Cascade `entitas-aset` di `MasterForm.tsx` → lihat §4

### 0.5 Rendering EAV → lihat §5

### 0.6 Test plan → lihat §6

### 0.7 `app.yaml` → lihat §7

### 0.8 Split `contracts/openapi.yaml`: **YA, split sekarang, di fase 0**

1625 baris hari ini, ambang AGENTS.md 1500. Refactor ini menambah 4 master baru + 3 link resource + perubahan pada `aset`/`penyusutan` → estimasi **+1000 baris** kalau ditulis dengan pola boilerplate yang ada sekarang (setiap master ≈ 130 baris, 99% identik).

Split di fase 0 sebelum apa pun ditambahkan, sehingga diff-nya murni "pindah baris" dan reviewable:

```
contracts/
  openapi.yaml            # root: info, servers, tags, security, $ref ke paths/ + components/
  paths/
    master-crud.yaml      # template kolektif: satu blok per master, memakai $ref bersama
    aset.yaml
    penyusutan.yaml
    lifecycle.yaml
  components/
    parameters.yaml
    responses.yaml
    schemas-master.yaml
    schemas-aset.yaml
    schemas-penyusutan.yaml
  openapi.bundle.yaml     # hasil bundle, di-commit (AGENTS.md mewajibkan)
```

`app.yaml` baris 12 (`api.openapi: contracts/openapi.yaml`) diarahkan ke `contracts/openapi.bundle.yaml`, karena Control Plane membaca satu file dan tidak me-resolve `$ref` relatif.

Manfaat konkret di luar sekadar memenuhi ambang: dengan `components/responses.yaml` berisi `MasterListResponse`, `MasterRecordResponse`, `Unauthenticated`, `Forbidden`, `NotFound`, `ValidationError`, dan schema generik `MasterWrite`/`MasterRecord`, setiap master baru turun dari ~130 baris menjadi ~40. Empat master baru = hemat ~360 baris.

Bug kontrak yang ditemukan dan diperbaiki saat split: `components/parameters/IdempotencyKey` mendeklarasikan `maxLength: 140` (`contracts/openapi.yaml:1190`), sedangkan `MasterDataController.php:99` menegakkan `max:133`. Kontrak menjanjikan sesuatu yang API tolak dengan 422.

### 0.9 Keputusan EAV dan horizontal scaling

EAV hanya dipakai untuk atribut aset yang sparse dan dapat dikonfigurasi tenant. Field inti yang sering difilter, dihitung, atau mempunyai konsekuensi finansial tetap berupa kolom biasa. Implementasi memakai **typed EAV**, bukan satu kolom `jsonb`:

- definisi atribut dan nilai atribut dipisah;
- satu baris nilai hanya boleh mengisi satu slot bertipe;
- `data_type` menyatakan tipe penyimpanan (`text`, `integer`, `decimal`, `currency`, `boolean`, `date`, `datetime`, `reference`), sedangkan `validation_mode` menyatakan aturan (`free`, `fixed_list`, `range`);
- `currency` menyimpan amount decimal dengan `currency_code` ISO-4217; `datetime` menyimpan `timestamptz` UTC; `reference` menyimpan ID opaque plus `reference_resource` yang divalidasi melalui contract pemilik resource, bukan FK database lintas module;
- aset dan seluruh nilai atributnya ditulis dalam satu transaksi PostgreSQL;
- unique index, composite FK, dan idempotency menjadi sumber kebenaran saat dua atau lebih instance API menulis bersamaan;
- endpoint membaca definisi/nilai secara batch, bukan satu query per atribut;
- tidak ada definisi atribut, tenant identity, permission, counter, atau idempotency yang hanya hidup di memory satu instance;
- perubahan lintas module memakai outbox/inbox, bukan transaksi database bersama.

Scale-out belum dianggap terverifikasi sampai load test CoreERP berjalan pada PostgreSQL nyata dengan 1000+ VU, 100+ tenant, 2+ instance API, dan 90 detik beban penuh; hasil SQL harus membuktikan nol duplikasi, nol pelanggaran tenant, nol 5xx aplikasi, dan tidak ada dua request idempoten yang membuat dua record.

---

## 1. Sasaran domain (ringkas)

```
SEBELUM                              SESUDAH
group → kategori → jenis → entitas   group_aset   (flat, sumbu FINANSIAL)
                                     jenis_aset   (flat, sumbu TEKNIS/MAINTENANCE)
                                     model_aset   (flat, katalog: pabrikan + jenis)
                                     kategori_aset → DIHAPUS

aset.jenis_aset_id                   aset.group_aset_id  (wajib)
                                     aset.jenis_aset_id  (wajib)
                                     aset.model_aset_id  (opsional)
                                     aset.parent_asset_id (opsional, hierarki DATA)
```

Depresiasi: `m_group_aset.default_depreciation_profile_id` (satu profil, satu buku) diganti matriks `m_group_buku_penyusutan` (group × buku → profil, masa manfaat, convention).

---

## 2. Daftar kolom lengkap

Semua tabel `m_*` yang merupakan master penuh memakai basis wajib AGENTS.md:
`id` ULID PK, `tenant_id` ULID indexed, `creation_key` varchar(160), `kode` varchar(50), `nama` varchar(150), `keterangan` text nullable, `aktif` boolean default true, `deleted_at`, `created_at`, `updated_at`, `unique(tenant_id, kode)`, `unique(tenant_id, creation_key)`. Di bawah hanya dituliskan **tambahan** di luar basis itu.

### 2.1 `m_group_aset` — sumbu finansial (flat)

| Kolom | Tipe | Ket |
| --- | --- | --- |
| — | — | basis master |
| `number_sequence_ref` | varchar(160) nullable | kode reference nomor Core untuk aset di group ini; **wajib divalidasi terhadap allow-list manifest**, bukan free text (lihat §8 risiko) |
| `capitalization_threshold` | decimal(18,2) nullable | di bawah nilai ini → beban, bukan aset |
| `tipe_harta` | varchar(30) nullable | enum pajak Indonesia: `kelompok_1`, `kelompok_2`, `kelompok_3`, `kelompok_4`, `bangunan_permanen`, `bangunan_non_permanen`, `bukan_objek_penyusutan` |
| `major_type` | varchar(30) nullable | F&O Major type: `tangible`, `intangible`, `right_of_use`, `low_value` |
| `posting_layers` | varchar(120) nullable | daftar layer yang diizinkan, comma-separated (`current,tax`); dipakai sebagai guard saat membuat baris matriks |
| | | **DIBUANG:** `default_depreciation_profile_id`, `default_book_code` |
| index | | `unique(tenant_id, id)` |

### 2.2 `m_jenis_aset` — sumbu teknis (flat)

| Kolom | Ket |
| --- | --- |
| — | basis master |
| | **DIBUANG:** `kategori_aset_id` + index + FK komposit |
| index | `unique(tenant_id, id)` (tetap; sekarang direferensi `m_model_aset`, `m_jenis_aset_atribut`, `tr_penerimaan_aset`, `tr_perencanaan_aset_details`, `tr_permintaan_pengadaan_aset_details`) |

### 2.3 `m_kategori_aset` — **DIHAPUS**

Kontennya bermigrasi ke atribut (§2.11-2.13). Tidak ada tabel pengganti.

### 2.4 `m_model_aset` (eks `m_entitas_aset`) — katalog model

Keputusan: **rename tabel** `m_entitas_aset` → `m_model_aset`, slug `entitas-aset` → `model-aset`. Menyisakan nama lama akan membuat setiap pembaca berikutnya salah paham; "entitas" di CoreERP berarti legal entity, tabrakan istilah yang berbahaya. Rename ini mahal (ripple ke 11 file, lihat §8) tapi dilakukan sekali dan di sinilah momennya.

| Kolom | Tipe | Ket |
| --- | --- | --- |
| — | — | basis master |
| `pabrikan_aset_id` | ULID **NOT NULL** | FK komposit → `m_pabrikan_aset(tenant_id, id)`, restrictOnDelete |
| `jenis_aset_id` | ULID nullable | FK komposit → `m_jenis_aset(tenant_id, id)`, restrictOnDelete |
| `model_number` | varchar(150) nullable | nomor model pabrikan |
| index | | `unique(tenant_id, id)`, `index(tenant_id, pabrikan_aset_id)`, `index(tenant_id, jenis_aset_id)` |

Inilah master pertama dengan **dua induk** → pendorong perubahan §3.

### 2.5 `m_tipe_lokasi_aset` — master baru (flat)

Hanya basis master. `unique(tenant_id, id)`.
Contoh isi tenant: Site, Gedung, Lantai, Ruangan, Area penyimpanan, Kendaraan.

### 2.6 `m_lokasi_aset` — perubahan

| Kolom | Tipe | Ket |
| --- | --- | --- |
| `tipe_lokasi_id` | ULID nullable | FK komposit → `m_tipe_lokasi_aset(tenant_id, id)`, restrictOnDelete |
| `org_unit_id` | ULID nullable, indexed | **milik Core, TIDAK ADA FK** (AGENTS.md: app tidak pernah menyentuh DB Core). Ini jembatan yang men-default financial dimension aset (F&O "Update asset dimension") |
| `keterangan` | text nullable | dipindah dari `2026_07_28_097000` ke definisi utama |
| `parent_id` | ULID nullable | tetap — inilah hierarki DATA yang sah |

### 2.7 `m_profil_penyusutan` — perubahan

| Kolom | Perubahan |
| --- | --- |
| `convention` | tetap varchar(40) tapi jadi **enum tervalidasi**: `full_month`, `half_year`, `pro_rata`, `none`; default `none`; **dibaca engine** |
| `year_basis` | tetap `calendar`\|`fiscal`; **mulai dibaca engine** untuk menentukan batas tahun konvensi |
| `manual_schedule` | kolom json tetap; **tambahkan cast `'manual_schedule' => 'array'` pada model** (bug laten, lihat §8) |
| lainnya | tidak berubah |

### 2.8 `m_buku_penyusutan` — master baru (F&O "Book")

| Kolom | Tipe | Ket |
| --- | --- | --- |
| — | — | basis master |
| `posting_layer` | varchar(20) NOT NULL default `current` | `current`, `operations`, `tax`, `none` |
| `calendar_basis` | varchar(20) NOT NULL default `fiscal` | `fiscal`, `calendar` |
| `post_to_gl` | boolean NOT NULL default false | layer `tax`/`none` biasanya false |
| `calculate_depreciation` | boolean NOT NULL default true | buku memorandum → false |
| `depreciation_profile_id` | ULID nullable | FK komposit → `m_profil_penyusutan`; default level buku |
| `alternative_profile_id` | ULID nullable | FK komposit → `m_profil_penyusutan`; **switch RB → SL-remaining-life** |
| `currency_code` | char(3) nullable | buku mata uang berbeda (opsional, sediakan sekarang agar tidak ada migration korektif nanti) |
| index | | `unique(tenant_id, id)` |

### 2.9 `m_group_buku_penyusutan` — matriks (BUKAN master penuh)

Tidak punya `kode`, tidak minta Number Sequence, tidak punya `creation_key`. Ini link table.

| Kolom | Tipe | Ket |
| --- | --- | --- |
| `id` | ULID PK | |
| `tenant_id` | ULID indexed | |
| `group_aset_id` | ULID NOT NULL | FK komposit → `m_group_aset` |
| `buku_id` | ULID NOT NULL | FK komposit → `m_buku_penyusutan` |
| `depreciation_profile_id` | ULID nullable | FK komposit → `m_profil_penyusutan`; null → pakai default buku |
| `alternative_profile_id` | ULID nullable | FK komposit → `m_profil_penyusutan`; null → pakai buku |
| `useful_life_periods` | unsignedInteger nullable | override profil |
| `convention` | varchar(20) nullable | override profil |
| `residual_percent` | decimal(9,4) nullable | % nilai residu dari nilai perolehan |
| `depreciate` | boolean NOT NULL default true | false → aset di group ini dapat buku tapi tidak disusutkan |
| `deleted_at` | timestamp nullable | **wajib** — `MasterDataController::unarchivedChild()` melakukan `whereNull('deleted_at')` (baris 316); tanpa kolom ini guard arsip `m_group_aset` melempar QueryException di PostgreSQL |
| timestamps | | |
| index | | `unique(tenant_id, id)`, `unique(tenant_id, group_aset_id, buku_id)` |

### 2.10 `tr_penerimaan_aset` — perubahan

| Kolom | Tipe | Ket |
| --- | --- | --- |
| `group_aset_id` | ULID **NOT NULL** | FK komposit → `m_group_aset`, restrictOnDelete. Sumbu finansial |
| `jenis_aset_id` | ULID **NOT NULL** | tetap; FK komposit → `m_jenis_aset`. Sumbu teknis |
| `model_aset_id` | ULID nullable | FK komposit → `m_model_aset` |
| `parent_asset_id` | ULID nullable | FK komposit self → `tr_penerimaan_aset(tenant_id, id)`. Hierarki aset **di data** |
| `financial_dimension_org_unit_id` | ULID nullable, indexed | tanpa FK (milik Core); di-default dari `m_lokasi_aset.org_unit_id` saat penerimaan & mutasi |
| `pabrikan_aset_id` | ULID nullable | tetap (boleh diisi langsung tanpa model) |
| `kondisi_aset_id`, `asset_location_id`, sisanya | | tetap |

### 2.11 `m_tipe_atribut` — master baru (EAV: definisi)

| Kolom | Tipe | Ket |
| --- | --- | --- |
| — | — | basis master |
| `data_type` | varchar(20) NOT NULL | `text`, `integer`, `decimal`, `currency`, `boolean`, `date`, `datetime`, `reference`; tipe penyimpanan |
| `validation_mode` | varchar(20) NOT NULL default `free` | `free`, `fixed_list`, `range`; aturan validasi/presentasi |
| `satuan` | varchar(50) nullable | mm, kg, HP — hanya untuk `integer`/`decimal`, tampilan saja |
| `currency_code` | char(3) nullable | ISO-4217; wajib bila `data_type=currency`, menjadi currency tunggal atribut |
| `reference_resource` | varchar(160) nullable | wajib bila `data_type=reference`; resource namespaced yang menjadi pemilik ID |
| `min_value` | decimal(24,6) nullable | wajib bila `validation_mode=range`; hanya untuk `integer`/`decimal`/`currency` |
| `max_value` | decimal(24,6) nullable | wajib bila `validation_mode=range`; hanya untuk `integer`/`decimal`/`currency` |
| `default_value` | text nullable | dinormalisasi server sesuai `data_type` |
| index | | `unique(tenant_id, id)` |

`fixed_list` dan `range` bukan tipe penyimpanan. Untuk menjaga perilaku yang terbukti di Dynamics, `fixed_list` hanya boleh untuk `text`, sedangkan `range` hanya untuk `integer`, `decimal`, dan `currency`. `reference` wajib memiliki `reference_resource`; ID disimpan opaque, tidak dibuat FK polymorphic, dan resolver/validasinya mengikuti contract resource pemilik. Jika contract target belum ada, tipe reference belum boleh dipakai di UI/API.

Matriks slot nilai:

| `data_type` | Slot `tr_aset_atribut` | Metadata wajib |
| --- | --- | --- |
| `text` | `nilai_text` atau `tipe_atribut_nilai_id` | `fixed_list` memakai slot pilihan |
| `integer` / `decimal` | `nilai_number` | `satuan` opsional |
| `currency` | `nilai_currency_amount` | `currency_code` |
| `boolean` | `nilai_boolean` | — |
| `date` | `nilai_date` | — |
| `datetime` | `nilai_datetime` | disimpan UTC; API RFC 3339 |
| `reference` | `nilai_reference_id` | `reference_resource`; tanpa FK lintas module |

Setelah sebuah tipe atribut dipakai oleh nilai aset, `data_type`, `currency_code`, dan `reference_resource` tidak boleh diubah in-place karena akan mengubah arti data lama. Perubahan dilakukan dengan tipe atribut baru atau migrasi eksplisit yang tervalidasi.

### 2.12 `m_tipe_atribut_nilai` — nilai fixed_list (link table)

`id`, `tenant_id`, `tipe_atribut_id` (FK komposit), `urutan` unsignedSmallInteger, `nilai` varchar(150), `deleted_at`, timestamps.
`unique(tenant_id, id)`, `unique(tenant_id, tipe_atribut_id, nilai)`, `unique(tenant_id, tipe_atribut_id, id)`, `index(tenant_id, tipe_atribut_id, urutan)`.

### 2.13 `m_jenis_aset_atribut` — link jenis ⇄ atribut

`id`, `tenant_id`, `jenis_aset_id` (FK komposit), `tipe_atribut_id` (FK komposit), `wajib` boolean default false, `urutan` unsignedSmallInteger default 0, `deleted_at`, timestamps.
`unique(tenant_id, id)`, `unique(tenant_id, jenis_aset_id, tipe_atribut_id)`.

Tabel kembar `m_tipe_lokasi_aset_atribut` dengan bentuk identik (`tipe_lokasi_id` menggantikan `jenis_aset_id`) untuk "asset attribute requirements" per tipe lokasi. **Ditunda ke akhir fase 6** — bentuknya sama persis, jadi ia gratis begitu yang pertama jalan, tapi tidak memblokir apa pun.

### 2.14 `tr_aset_atribut` — nilai per aset

Keputusan: **kolom bertipe**, bukan satu kolom `text` atau `jsonb`. Alasan: PostgreSQL dapat menegakkan tipe dan meng-index; laporan ERP akan melakukan `where nilai_number between ...` yang mustahil efisien di atas text. Biaya: slot nilai nullable, tepat satu di antaranya terisi.

| Kolom | Tipe |
| --- | --- |
| `id` | ULID PK |
| `tenant_id` | ULID indexed |
| `asset_id` | ULID NOT NULL, FK komposit → `tr_penerimaan_aset` |
| `tipe_atribut_id` | ULID NOT NULL, FK komposit → `m_tipe_atribut` |
| `nilai_text` | text nullable |
| `nilai_number` | decimal(24,6) nullable |
| `nilai_currency_amount` | decimal(24,6) nullable |
| `nilai_boolean` | boolean nullable |
| `nilai_date` | date nullable |
| `nilai_datetime` | timestamptz nullable, selalu UTC |
| `nilai_reference_id` | varchar(160) nullable, opaque; tanpa FK lintas module |
| `tipe_atribut_nilai_id` | ULID nullable, FK komposit → `m_tipe_atribut_nilai` |
| timestamps | |
| index | `unique(tenant_id, id)`, `unique(tenant_id, asset_id, tipe_atribut_id)`, `index(tenant_id, tipe_atribut_id, nilai_number)`, `index(tenant_id, tipe_atribut_id, nilai_currency_amount)`, `index(tenant_id, tipe_atribut_id, nilai_date)`, `index(tenant_id, tipe_atribut_id, nilai_datetime)`, `index(tenant_id, tipe_atribut_id, nilai_reference_id)` |

Constraint database wajib memastikan tepat satu dari `nilai_text`, `nilai_number`, `nilai_currency_amount`, `nilai_boolean`, `nilai_date`, `nilai_datetime`, `nilai_reference_id`, atau `tipe_atribut_nilai_id` terisi. Composite FK fixed-list wajib memakai `(tenant_id, tipe_atribut_id, tipe_atribut_nilai_id)` agar opsi tidak dapat berasal dari atribut lain atau tenant lain; validasi controller saja tidak cukup untuk scale-out. `nilai_reference_id` tidak boleh diberi FK polymorphic; server wajib memeriksa `reference_resource` dan contract pemiliknya.

### 2.15 `tr_buku_aset` — perubahan

| Kolom | Perubahan |
| --- | --- |
| `buku_id` | **BARU** ULID NOT NULL, FK komposit → `m_buku_penyusutan` |
| `book_code` | **DIBUANG**; unique berubah `(tenant_id, asset_id, book_code)` → `(tenant_id, asset_id, buku_id)`. Bentuk API dipertahankan dengan `select buku.kode as book_code` di `DepreciationController::books()`/`index()` |
| `useful_life_periods` | **BARU** unsignedInteger nullable — snapshot dari matriks saat aset dibuat |
| `convention` | **BARU** varchar(20) nullable — snapshot |
| `depreciation_start_on` | **BARU** date nullable — dasar perhitungan convention |
| `alternative_profile_id` | **BARU** ULID nullable, FK komposit → `m_profil_penyusutan` |
| `switched_on` | **BARU** date nullable — kapan switch ke profil alternatif terjadi |
| `depreciate` | **BARU** boolean default true — snapshot dari matriks |

---

## 3. Perubahan `MasterDataController` (multi-parent + link table)

File: `api/app/Http/Controllers/MasterDataController.php` (365 baris), `api/app/Support/MasterParent.php`, `api/app/Support/MasterChild.php`.

### 3.1 Single-parent → multi-parent

`MasterParent` sendiri **tidak berubah** — value object-nya sudah membawa `table`/`column`/`relation`/`label`/`required` dan tidak mengandung asumsi kardinalitas. Yang berasumsi tunggal adalah controller-nya.

Ganti:
```php
protected function parentMaster(): ?MasterParent { return null; }
```
dengan:
```php
/** @return list<MasterParent> */
protected function parentMasters(): array { return []; }
```

Tidak ada shim `parentMaster()` yang dipertahankan — AGENTS.md melarang compatibility layer spekulatif, dan hanya ada 3 subclass yang memakainya.

Delapan lokasi yang menjadi loop:

| Baris sekarang | Perubahan |
| --- | --- |
| 53-59 `index()` validasi | satu aturan `nullable size:26` per parent |
| 75-77 `index()` filter | loop; setiap parent yang terisi menambah `where` (filter dapat digabung — model-aset difilter pabrikan DAN jenis sekaligus) |
| 154-157 `update()` `unsetRelation` | loop |
| 189 `tenantQuery()` `with()` | merge seluruh `eagerLoad()` |
| 211-221 `rejectParentCycle()` | loop, **hanya untuk parent yang `$parent->table === $record->getTable()`** (self-reference, mis. `m_lokasi_aset`). Guard `$parent->table !== $record->getTable()` yang sekarang ada di baris 214 dipindah menjadi kondisi `continue` di dalam loop |
| 254-257 `writeRules()` | satu aturan per parent |
| 276-280 `payload()` | spread setiap kolom parent |
| 351-357 `present()` | setiap parent menyumbang `<col>` + `<payloadKey>` summary |

`present()` untuk `model-aset` menghasilkan:
```json
{ "id": "...", "kode": "...", "nama": "...",
  "pabrikan_aset_id": "...", "pabrikan_aset": { "id","kode","nama" },
  "jenis_aset_id": "...",    "jenis_aset":    { "id","kode","nama" } }
```
Kontrak UI (`parentSummaryOf`) tidak berubah bentuknya — hanya jumlahnya.

### 3.2 `MasterChild` butuh flag soft delete

`unarchivedChild()` (baris 310-325) melakukan `whereNull('deleted_at')` tanpa syarat. Setelah refactor, anak `m_group_aset` mencakup `tr_penerimaan_aset` (punya `deleted_at`) — aman. Tapi anak `m_tipe_atribut` mencakup `tr_aset_atribut` yang **tidak punya** `deleted_at` → QueryException 500 di PostgreSQL saat mengarsipkan tipe atribut.

Tambahkan ke `MasterChild`:
```php
public function __construct(
    public string $table,
    public string $column,
    public string $label,
    public bool $softDeletes = true,   // BARU
) {}
```
dan di `unarchivedChild()` terapkan `whereNull('deleted_at')` hanya bila `$child->softDeletes`.

### 3.3 Link table: JANGAN dipaksa lewat `MasterDataController`

`m_group_buku_penyusutan`, `m_jenis_aset_atribut`, `m_tipe_atribut_nilai` bukan master: tidak ada `kode`, tidak ada Number Sequence, tidak ada `creation_key`. Memaksanya melewati `MasterDataController` berarti menerbitkan nomor Core untuk baris matriks — pemborosan dan salah secara domain.

Buat base tipis kedua: `api/app/Http/Controllers/MasterLinkController.php`.

- Semantik **replace-whole-set**: `GET /api/v1/<owner>/{id}/<link>` dan `PUT /api/v1/<owner>/{id}/<link>` dengan body array penuh. Ini menghapus seluruh kebutuhan idempotency-key per baris — PUT idempoten secara alami.
- Izin memakai permission **pemilik**, bukan resource sendiri: menulis matriks group/buku butuh `management-aset.group-aset.update`. Konsekuensi manifest: nol entri baru untuk link table (§7).
- Subclass menyatakan: tabel link, kolom owner, daftar kolom yang boleh ditulis + aturan validasinya, dan FK yang harus divalidasi same-tenant.

Kode bersama `tenantId()` dan `requirePermission()` sekarang `private` di `MasterDataController` (baris 223-241). Ekstrak ke trait `api/app/Support/CoreErpRequestContext.php` yang dipakai `MasterDataController` dan `MasterLinkController`. Ini juga membereskan duplikasi `can()`/`tenant()` yang saat ini disalin di `AssetController.php:168-173`, `DepreciationController.php:118-119`, `DepreciationProfileController.php:49-50`.

---

## 4. Menghapus cascade `entitas-aset` di `MasterForm.tsx`

### 4.1 Apa yang dihapus

`ui/src/master/MasterForm.tsx` — hapus seluruhnya:
- baris 38-42: state `groups`, `categories`, `types`, `groupId`, `categoryId`
- baris 45: `isEntitasAset`
- baris 53-54: `categoryItems`, `typeItems` (filter client-side)
- baris 56-77: `useEffect` yang menembak 3 request `?per_page=100` dan mereverse-engineer rantai dari record yang sedang diedit
- baris 121-174: tiga `<Select>` hardcoded Group/Kategori/Jenis

Total ≈ 75 baris hilang.

### 4.2 Apa yang menggantikan: **tidak ada cascade**

Ini intinya. Setelah master flat, `model-aset` punya dua induk yang **saling independen** — pabrikan dan jenis. Tidak ada yang menyaring apa pun. Yang dibutuhkan hanya render *N* dropdown sejajar, bukan *N* dropdown bertingkat.

`ui/src/master/masters.ts`:
```ts
export type MasterConfig = {
    // ...
    parents?: MasterParentConfig[];   // menggantikan parent?: MasterParentConfig
};
```
`MasterResource` union: hapus `'entitas-aset'`, `'kategori-aset'`; tambah `'model-aset'`, `'tipe-lokasi-aset'`, `'buku-penyusutan'`, `'tipe-atribut'`, `'profil-penyusutan'`.
`Permission` union: `profil-penyusutan` naik dari `read|create` menjadi 4 aksi penuh (masuk lewat `MasterResource`), sehingga baris 20 dihapus.

Entry `model-aset`:
```ts
{
    resource: 'model-aset',
    nav: 'Model aset', title: 'Model aset',
    subtitle: 'Katalog model spesifik dari satu pabrikan.',
    kodeLabel: 'Kode model aset', namaLabel: 'Nama model aset',
    singular: 'model aset',
    parents: [
        { resource: 'pabrikan-aset', field: 'pabrikan_aset_id', summaryKey: 'pabrikan_aset', label: 'Pabrikan aset' },
        { resource: 'jenis-aset',    field: 'jenis_aset_id',    summaryKey: 'jenis_aset',    label: 'Jenis aset', required: false },
    ],
}
```

`MasterPage.tsx`:
- state `parentOptions: ParentSummary[]` → `Record<string, ParentSummary[]>` berkunci `parent.field`; `parentFilter: string` → `Record<string, string>`
- `useEffect` baris 64-82 memuat opsi untuk **setiap** parent secara paralel (`Promise.all`), bukan satu
- `query` (baris 42-48) menambah satu param per parent yang terisi → server sudah mendukungnya setelah §3.1
- kolom tabel (baris 141-144): satu kolom per parent
- toolbar filter (baris 168-179): satu `<Select>` per parent

`MasterForm.tsx`: satu `<Select>` di dalam `parents.map()`, memakai blok generik yang **sudah ada** di baris 175-191 — tidak ada komponen baru, hanya blok lama yang di-loop dan blok hardcoded yang dibuang.

`App.tsx` baris 47: `active = visible.find(m => m.resource === hashResource) ?? visible[0]` — pengguna yang bookmark `#/kategori-aset` akan diam-diam mendarat di master pertama. Tidak fatal, tapi catat; opsional: tambahkan halaman "resource tidak dikenal".

### 4.3 Utang yang tidak diperbaiki di sini

Pemuatan opsi tetap `?per_page=100&aktif=true` tanpa pencarian server-side. Tenant dengan >100 pabrikan akan kehilangan pilihan secara senyap. Ini sudah bug hari ini (`MasterPage.tsx:72`), diperburuk oleh bertambahnya dropdown. **Di luar scope refactor ini** — catat sebagai follow-up terpisah, jangan campur.

---

## 5. Pendekatan rendering EAV

Prinsip: **satu renderer, dua sumber**. Field statis tambahan (mis. `tipe_harta` pada group-aset) dan field EAV dinamis (atribut per jenis aset) dirender oleh fungsi yang sama; yang berbeda hanya dari mana definisinya datang.

### 5.1 Tipe bersama

`ui/src/master/fields.ts` (baru):
```ts
export type FieldType = 'text' | 'textarea' | 'number' | 'currency' | 'boolean' | 'date' | 'datetime' | 'select' | 'reference';

export type FieldConfig = {
    name: string;
    label: string;
    type: FieldType;
    required?: boolean;
    options?: { value: string; label: string }[];   // select
    resource?: MasterResource;                       // reference → dropdown master lain
    currencyCode?: string;                           // currency → ISO-4217
    min?: number; max?: number; step?: number;       // number / validation_mode=range
    suffix?: string;                                 // satuan
    visibleWhen?: (form: Record<string, unknown>) => boolean;
};
```
`ui/src/master/DynamicField.tsx` (baru): satu komponen, `switch (config.type)` → `Input`/`Textarea`/`Switch`/`Input[type=date]`/`Input[type=datetime-local]`/`Select`/currency input. Tidak ada logika domain di dalamnya; datetime dikonversi ke/dari UTC di boundary API.

### 5.2 Sumber 1 — field statis lewat config

`MasterConfig` menerima `extraFields?: FieldConfig[]`. `MasterForm` merender `[...parents-as-fields, ...extraFields]` lewat `DynamicField`. Hasil langsung:
- `group-aset` mendapat `tipe_harta` (select), `major_type` (select), `capitalization_threshold` (number), `number_sequence_ref` (select dari allow-list)
- `buku-penyusutan` mendapat `posting_layer`, `calendar_basis`, `post_to_gl`, `calculate_depreciation`, `depreciation_profile_id` (reference), `alternative_profile_id` (reference)
- `tipe-atribut` mendapat `data_type` + `validation_mode` + `min_value`/`max_value` dengan `visibleWhen: f => f.validation_mode === 'range'`
- `profil-penyusutan` mendapat `method`, `frequency`, `year_basis`, `convention`, `useful_life_periods`, `rate_percent` dengan `visibleWhen` — **sehingga `ui/src/transactions/inventarisasi-aset/DepreciationProfilePage.tsx` (362 baris) dihapus seluruhnya** dan `profil-penyusutan` masuk ke `MASTERS` seperti master lain. Ini pembayaran nyata untuk framework `extraFields`, bukan abstraksi spekulatif.

`api/app/Http/Controllers/MasterDataController.php` perlu hook sejajar di sisi server: `protected function extraRules(string $tenantId, bool $creating): array` dan `protected function extraPayload(array $data): array`, di-merge di `writeRules()`/`payload()`/`present()`. Tanpa ini setiap master dengan kolom tambahan terpaksa override `writeRules()` penuh.

### 5.3 Sumber 2 — field dinamis EAV

Endpoint baru: `GET /api/v1/jenis-aset/{id}/atribut` mengembalikan definisi yang sudah di-join:
```json
{ "data": [
  { "tipe_atribut_id": "01J...", "kode": "TATR-000004", "nama": "Kapasitas bucket",
    "data_type": "decimal", "validation_mode": "range", "satuan": "m3", "currency_code": null, "reference_resource": null, "min_value": null, "max_value": null,
    "wajib": true, "urutan": 1, "nilai_pilihan": [] }
]}
```
`ui/src/transactions/inventarisasi-aset/attributes.ts` (baru) memetakan definisi → `FieldConfig`:

| `data_type` | `FieldConfig` |
| --- | --- |
| `text` | `{ type: 'text' }` |
| `integer` / `decimal` | `{ type: 'number', suffix: satuan }` |
| `boolean` | `{ type: 'boolean' }` |
| `date` | `{ type: 'date' }` |
| `datetime` | `{ type: 'datetime' }` dengan wire value RFC 3339 |
| `currency` | `{ type: 'currency', currencyCode: currency_code }` |
| `reference` | `{ type: 'reference', resource: reference_resource }` |
| `validation_mode=fixed_list` | `{ type: 'select', options: nilai_pilihan }` |
| `validation_mode=range` | `{ type: 'number', min, max, suffix }` |

`AssetPage.tsx` memanggil endpoint itu setiap `jenis_aset_id` berubah, lalu merender `definitions.map(d => <DynamicField config={toFieldConfig(d)} …/>)`. Sama persis dengan §5.2 — komponen yang sama.

### 5.4 Bentuk wire

Kirim **satu** kunci `nilai` per atribut; server yang memetakan ke kolom bertipe berdasarkan `data_type`:
```json
{ "jenis_aset_id": "...", "group_aset_id": "...",
  "atribut": [ { "tipe_atribut_id": "01J...", "nilai": 1.8 },
               { "tipe_atribut_id": "01K...", "nilai": "Kuning" } ] }
```
UI tetap bodoh (tidak perlu tahu kolom mana), DB tetap bertipe. Validasi server (`api/app/Support/AssetAttributeValidator.php`, baru):
- setiap atribut `wajib` pada jenis tersebut harus ada → 422
- `tipe_atribut_id` harus terdaftar pada jenis aset itu → 422 (tolak atribut liar)
- `validation_mode=fixed_list` → nilai harus ada di `m_tipe_atribut_nilai` milik atribut dan tenant yang sama
- `validation_mode=range` → `min_value <= nilai <= max_value`
- `integer`/`decimal`/`currency`/`date`/`datetime`/`boolean` → cast + tolak tipe salah; currency wajib cocok dengan `currency_code`, datetime wajib RFC 3339 dan dinormalisasi ke UTC
- `reference` → `reference_resource` wajib ada, ID tidak boleh kosong, dan target divalidasi melalui contract pemilik resource; tidak ada query database lintas module

Scale-out rules: `AssetController::store()` menyimpan aset dan atribut dalam satu transaksi; retry memakai idempotency key yang unik per tenant/endpoint; `GET` list/show mengambil definisi dan nilai dengan batch query; cache lokal tidak boleh menjadi sumber kebenaran. Jika dua pengguna mengubah aset yang sama, gunakan optimistic concurrency (`If-Match`/versi baris) atau tolak stale update secara eksplisit.

**MasterForm generik TIDAK diberi EAV.** Master tidak punya atribut dinamis; hanya aset yang punya. Menyuntikkan EAV ke `MasterForm` akan merusak kesederhanaan yang membuatnya berguna. `DynamicField` yang dibagi, bukan `MasterForm`.

---

## 6. Rencana test

### 6.1 `api/tests/Feature/MasterDataAsetTest.php` — 11 dari 19 metode rusak

**Selamat tanpa perubahan (8):**

| Metode | Alasan |
| --- | --- |
| `test_master_mandiri_menjalankan_crud_tanpa_induk` (provider 5 kasus) | 5 master mandiri tetap mandiri; assert baris 73 atas `kategori_aset_id` menjadi hampa tapi tetap hijau |
| `test_kode_dari_klien_diabaikan_dan_selalu_berasal_dari_core` | pakai `kondisi-aset` |
| `test_daftar_master_tidak_pernah_menampilkan_data_tenant_lain` | pakai `kondisi-aset` |
| `test_kunci_pembuatan_milik_record_yang_diarsipkan_tetap_direplay` | pakai `kondisi-aset` |
| `test_retry_tetap_direplay_walau_aktif_dikirim_sebagai_angka` | pakai `kondisi-aset` |
| `test_filter_daftar_menghormati_nilai_tepi_yang_sah` | pakai `kondisi-aset` |
| `test_pencarian_angka_nol_tetap_dipakai_sebagai_kata_kunci` | pakai `pabrikan-aset` |
| `test_kunci_pembuatan_dibatasi_agar_selalu_muat_pada_batas_core` | slug terpanjang tetap `item-checklist-maintenance` (26). Slug baru: `tipe-lokasi-aset` (16), `buku-penyusutan` (15), `tipe-atribut` (12), `model-aset` (10) — semua lebih pendek, batas 133 tetap benar. **Verifikasi eksplisit ini di review** |

**Rusak (11):**

| Metode | Kerusakan | Penggantinya |
| --- | --- | --- |
| `test_master_berantai_wajib_membawa_induk` (provider, 3 kasus baris 55-59) | ketiga baris provider mati: `entitas-aset`/`jenis_aset_id`, `kategori-aset`/`group_aset_id`, `jenis-aset`/`kategori_aset_id` | provider tinggal 1 baris: `model-aset` / `pabrikan_aset_id`. `jenis-aset` **pindah ke provider `standaloneMasters`** |
| `test_rantai_klasifikasi_menyimpan_dan_menyajikan_induknya` | `buildChain()` mati | `test_model_aset_menyajikan_kedua_induknya` — assert `pabrikan_aset.id` DAN `jenis_aset.id` pada satu record |
| `test_daftar_anak_dapat_disaring_menurut_induk` | idem | filter `?pabrikan_aset_id=`, `?jenis_aset_id=`, dan **kombinasi keduanya** (kemampuan baru dari §3.1) |
| `test_induk_dari_tenant_lain_ditolak_dan_tidak_menerbitkan_nomor` | bangun 3 level asing | pabrikan asing tunggal; `Http::assertSentCount` turun 3 → 1 |
| `test_induk_dari_tenant_lain_juga_ditolak_saat_mengubah_anak` | idem | idem |
| `test_induk_yang_sudah_diarsipkan_tidak_dapat_dipilih` | idem | pabrikan diarsipkan → `model-aset` tolak 422 |
| `test_anak_dapat_dipindahkan_ke_induk_lain_pada_tenant_yang_sama` | idem | pindah `model-aset` ke pabrikan lain; **tambah kasus: pindah `jenis_aset_id` saja, pabrikan tetap** (tidak mungkin diuji sebelum multi-parent) |
| `test_induk_tidak_dapat_diarsipkan_selama_anaknya_masih_aktif` | assert 409 lewat kategori | `pabrikan-aset` 409 selama ada `model-aset`; `jenis-aset` 409 selama ada `model-aset`; `group-aset` 409 selama ada `tr_penerimaan_aset` **atau** baris `m_group_buku_penyusutan` |
| `test_hak_pada_satu_master_tidak_memberi_hak_pada_master_lain` | assert `kategori-aset` (baris 218-220) + `buildChain()` | ganti `kategori-aset` → `model-aset` |
| `test_setiap_master_memakai_reference_nomornya_sendiri` | loop 4 slug rantai, `assertSentCount(4)` (baris 234-239) | loop `['model-aset','group-aset','jenis-aset','pabrikan-aset']`, count 4 |
| `test_database_menegakkan_batas_tenant_pada_foreign_key_rantai` + helper `insertEntitasDirectly()` (baris 348-367) | insert langsung ke `m_entitas_aset.jenis_aset_id` | `insertModelDirectly()` ke `m_model_aset.pabrikan_aset_id`. **Ini test paling berharga di file** — ia satu-satunya yang membuktikan FK komposit hidup; jangan sampai hilang saat rewrite |

Helper `buildChain()` (baris 385-402) diganti `buildClassification()` yang membuat group + jenis + pabrikan + model **tanpa nesting**.

### 6.2 File test lain

| File | Perubahan |
| --- | --- |
| `api/tests/Feature/EntitasAsetTest.php` (108 baris) | **rename → `ModelAsetTest.php`**; helper `jenis()` (baris 100-107, insert 3 level) → `pabrikan()` 1 insert |
| `api/tests/Feature/AssetRegisterTest.php` | helper `jenis()` (baris 131-140) → insert group + jenis flat; **setiap** payload `POST /api/v1/aset` menambah `group_aset_id`; insert langsung `tr_penerimaan_aset` di `test_register_hides_assets_outside...` (4 baris) menambah `group_aset_id` |
| `api/tests/Feature/DepreciationTest.php` | helper `jenis()` idem; helper `book()` — `tr_buku_aset` insert `book_code` → `buku_id` + insert `m_buku_penyusutan`; assert `-100.0` di `test_reversal...` tetap valid **asal** convention default `none` |
| `api/tests/Feature/AssetPlanningTest.php` | helper jenis-chain yang sama; FK `tr_perencanaan_aset_details.jenis_aset_id` tidak berubah |
| `api/tests/Feature/AssetLocationTest.php` (1 test) | selamat; **tambah** test tipe lokasi + `org_unit_id` |

### 6.3 Test baru

| File | Isi |
| --- | --- |
| `tests/Feature/MasterDataAsetTest.php` (tambahan) | master dua-induk: satu induk salah tenant → 422 hanya pada kolom itu; kedua filter list digabung; `present()` memuat dua summary |
| `tests/Feature/AssetClassificationTest.php` | aset wajib `group_aset_id` DAN `jenis_aset_id`; `model_aset_id` opsional & same-tenant; `parent_asset_id` menunjuk aset tenant sama; **siklus parent-child aset ditolak** |
| `tests/Feature/DepreciationBookTest.php` | matriks group×buku menghasilkan profil + masa manfaat; satu `tr_buku_aset` per buku aktif dengan `depreciate=true`; **nol baris matriks → nol buku, dan itu sah** (menggantikan perilaku senyap `AssetController.php:87`); `calculate_depreciation=false` → buku dibuat tapi proposal ditolak |
| `tests/Unit/DepreciationCalculatorTest.php` | straight_line berhenti di akhir masa manfaat; floor di `residual_value`; `full_month`/`half_year`/`pro_rata`/`none`; `year_basis` calendar vs fiscal; switch RB → profil alternatif saat SL-sisa-umur > RB |
| `tests/Feature/AssetAttributeTest.php` | atribut `wajib` hilang → 422; `validation_mode=fixed_list` di luar daftar → 422; `validation_mode=range` di luar min/max → 422; `currency` menyimpan amount + ISO-4217; `datetime` round-trip UTC/RFC 3339; `reference` tanpa resource/ID atau target contract → 422; `tipe_atribut_id` tidak terdaftar pada jenis itu → 422; tipe/currency/resource tidak dapat diubah setelah dipakai; nilai tersaji saat show aset; `m_tipe_atribut` tidak dapat diarsip selama dipakai (uji `MasterChild::$softDeletes=false`) |
| `tests/Feature/AssetLocationTypeTest.php` | CRUD `tipe-lokasi-aset`; aset mewarisi `financial_dimension_org_unit_id` dari `m_lokasi_aset.org_unit_id` saat terima **dan** saat mutasi |
| `tests/Feature/MasterLinkTest.php` | PUT replace-whole-set idempoten; izin memakai permission pemilik; FK lintas tenant ditolak |
| `tests/Feature/DepreciationProfileTest.php` | baru — `profil-penyusutan` kini punya show/update/archive; **`manual_schedule` bulat-balik lewat API** (bug §8.3) |

### 6.4 Gate non-PHPUnit

`vendor/bin/pint --test` (api + ../database), `python loadtest/check-manifest.py app.yaml`, `cd ui && npm run build`, lalu **load test wajib** (AGENTS.md): `loadtest/k6/master-data.js` baris 35-36, 174, 184, 194, 355, 398 dan `loadtest/verify.sql` baris 15-17, 27-29, 39-47, 53-55, 64-66, 117-119 semuanya menyebut rantai lama dan **akan gagal** sampai diperbarui. Selain itu, load test harus menambah jalur create/read aset dengan atribut dinamis, retry idempoten, dua instance API, serta query verifikasi satu-slot-nilai untuk seluruh tipe termasuk currency/datetime/reference, FK fixed-list, nol N+1 yang material, nol duplikasi, dan nol pelanggaran tenant.

---

## 7. Entri `app.yaml`

Per master baru dengan slug `S` dan label `L`, **enam** lokasi (empat lapis D365 + nav + reference):

1. `ui.navigation.sidebar.master` → `- id: S / label: L / permission: management-aset.S.read`
2. `security.entry_points` → `management-aset.S.form` (type `form`) **dan** `management-aset.S.api` (type `api`)
3. `security.permissions` → `.read` (access `read`, entry_point `.form`), `.create` (`create`, `.api`), `.update` (`update`, `.api`), `.archive` (`delete`, `.api`)
4. `security.privileges` → `management-aset.S.maintain` (read+create+update), `management-aset.S.retire` (archive)
5. `security.duties` → `management-aset.S.manage` → maintain + retire
6. `number_sequences.references` → `management-aset.S`, `default_prefix`, `allowed_scopes: [tenant]`

`loadtest/check-manifest.py` baris 65-67 menegakkan: **kode privilege tidak boleh sama dengan kode permission**. Karena itu jangan pernah menamai privilege `management-aset.S.read`.

### 7.1 Master baru

| Slug | Label | Prefix |
| --- | --- | --- |
| `model-aset` | Model aset | `MDLA` |
| `tipe-lokasi-aset` | Tipe lokasi aset | `TLKA` |
| `buku-penyusutan` | Buku penyusutan | `BKPY` |
| `tipe-atribut` | Tipe atribut | `TATR` |

### 7.2 Yang dihapus

- `entitas-aset` — seluruh 6 lokasi (nav baris 23-25, entry point, 4 permission, 2 privilege, 1 duty, reference `ENTA`). Digantikan `model-aset`.
- `kategori-aset` — seluruh 6 lokasi (nav baris 30-32, entry point baris 145-149, permission baris 274-289, privilege baris 511-520, duty baris 637-641, reference baris 719 `KTGA`). Tanpa pengganti.

### 7.3 Yang dilengkapi (inkonsistensi yang sudah ada)

- **`profil-penyusutan`** hanya punya permission `.read` + `.create` (baris 482-488), privilege `maintain` saja (baris 621-625). Setelah mewarisi `MasterDataController` ia mendapat update + archive → tambah 2 permission, tambah privilege `retire`, tambah `.update` ke `maintain`, tambah `retire` ke duty `manage`.
- **`profil-penyusutan` dan `lokasi-aset`** hanya punya entry point `.api`, tanpa `.form` (baris 235, 238) — menyimpang dari pola master lain, dan permission `.read`-nya menunjuk `.api`. Samakan: tambah `.form`, arahkan `.read` ke sana.
- **`lokasi-aset`** membundel `archive` ke dalam privilege `maintain` (baris 620) alih-alih `retire` terpisah. Samakan.

### 7.4 Link table: nol entri manifest

`m_group_buku_penyusutan`, `m_jenis_aset_atribut`, `m_tipe_atribut_nilai` memakai permission pemilik (§3.3) dan tidak punya `kode`. Nol entry point, nol permission, nol reference nomor. Ini keputusan sadar: mereka diedit **di dalam** form pemiliknya, jadi hak akses yang benar memang hak akses pemiliknya.

**Registrasi ulang katalog ke Control Plane wajib** setiap kali entry point/permission/privilege/duty/reference bertambah (AGENTS.md, kalimat terakhir).

---

## 8. Fase implementasi

Setiap fase berakhir pada keadaan yang dapat dites.

### Fase 0 — Split kontrak (tanpa perubahan perilaku)

**Ubah:** `contracts/openapi.yaml` → pecah ke `contracts/paths/*.yaml` + `contracts/components/*.yaml`; **buat** `contracts/openapi.bundle.yaml`; `app.yaml:12` menunjuk bundle.
**Perbaiki saat itu juga:** `IdempotencyKey.maxLength` 140 → 133.
**Test:** `python loadtest/check-manifest.py app.yaml` hijau; bundle tervalidasi; nol perubahan kode PHP/TS.
**Risiko:** Control Plane mungkin tetap mengharapkan nama file `openapi.yaml` — konfirmasi sebelum mengganti path di `app.yaml`; alternatifnya bundle **adalah** `openapi.yaml` dan sumbernya di `contracts/src/`.

### Fase 1 — `MasterDataController` multi-parent (tanpa perubahan schema)

**Ubah:** `MasterDataController.php` (8 titik §3.1), `MasterChild.php` (+`$softDeletes`), **buat** `api/app/Support/CoreErpRequestContext.php`, **buat** `api/app/Http/Controllers/MasterLinkController.php`.
**Ubah:** `EntitasAsetController`, `KategoriAsetController`, `JenisAsetController`, `AssetLocationController` — `parentMaster()` → `parentMasters()` mengembalikan array satu elemen.
**Test:** `MasterDataAsetTest` **lulus tanpa satu baris pun diubah** — inilah bukti refactor ini behaviour-preserving. Tambah satu test master dua-induk (pakai fixture sementara).
**Yang bisa rusak:** `rejectParentCycle()` — guard `$parent->table !== $record->getTable()` di baris 214 harus menjadi `continue` per-parent, bukan `return` global; salah tempat → siklus lokasi lolos, `AssetLocationTest` merah.

### Fase 2 — Flatten klasifikasi (fase destruktif)

**Migration:** rewrite/hapus 8 file per §0.1.
**API:** hapus `KategoriAsetController.php` + `KategoriAset.php`; rename `EntitasAset*` → `ModelAset*` dengan dua parent; `JenisAsetController` kehilangan parent, `childMasters()` → `m_model_aset` + `tr_penerimaan_aset`; `GroupAsetController.childMasters()` → `tr_penerimaan_aset`; `routes/api.php` array `$masters` (baris 24-33).
**`AssetController.php`:** `rules()` (baris 150-165) tambah `group_aset_id` required, `model_aset_id` nullable, `parent_asset_id` nullable; `store()` simpan ketiganya; `groupDefaults()` (baris 175-182) — **join 3-tabel dihapus**, diganti lookup langsung `m_group_aset` by `group_aset_id`; `present()` (baris 187) tambah kolom.
**UI:** `masters.ts` (`MasterResource`, `Permission`, `MASTERS`, `parent`→`parents`), `MasterPage.tsx`, `MasterForm.tsx` (buang 75 baris §4.1), `AssetPage.tsx` (+ dropdown group).
**Docs/kontrak:** `contracts/paths/`, `README.md`, `database/README.md` (tabel master + kalimat rantai), `app.yaml` (§7.1-7.2), `loadtest/k6/master-data.js`, `loadtest/verify.sql`.
**Test:** 11 metode `MasterDataAsetTest` ditulis ulang (§6.1); `EntitasAsetTest`→`ModelAsetTest`; helper `jenis()` di `AssetRegisterTest`/`DepreciationTest`/`AssetPlanningTest`.
**Yang bisa rusak:** paling banyak. Verifikasi `migrate:fresh` di PostgreSQL **dan** SQLite.

### Fase 3 — Field statis lewat config + `profil-penyusutan` naik kelas

**API:** hook `extraRules()`/`extraPayload()`/`extraPresent()` di `MasterDataController`; kolom finansial `m_group_aset`; **pindahkan** `DepreciationProfileController.php` `transaksi/InventarisasiAset/` → `master/`, tulis ulang extends `MasterDataController` (53 baris → ~35, dan mendapat show/update/archive gratis); pindahkan `DepreciationProfile.php` → `Models/master/`; **tambah cast `manual_schedule => 'array'`**.
**UI:** `fields.ts`, `DynamicField.tsx`, `extraFields` pada `MasterConfig`; **hapus** `DepreciationProfilePage.tsx` (362 baris) dan cabang route `App.tsx:95`.
**`app.yaml`:** §7.3.
**Test:** `DepreciationProfileTest` baru; assert `manual_schedule` bulat-balik lewat API.
**Yang bisa rusak:** `replay()` — lihat §9.2.

### Fase 4 — Tipe lokasi + jembatan org unit

**Migration baru:** `2026_08_06_100000_create_asset_location_type_master.php` (+ `Schema::table('m_lokasi_aset')` menambah `tipe_lokasi_id`/`org_unit_id` — aman, hanya ADD COLUMN, tidak ada `dropForeign`).
**API:** `master/TipeLokasiAsetController.php` + model; **pindahkan** `AssetLocationController.php` → `master/` (AGENTS.md); `AssetController::store()`/`place()` men-default `financial_dimension_org_unit_id` dari lokasi.
**Test:** `AssetLocationTypeTest`; perluas `AssetLocationTest`.

### Fase 5 — Buku penyusutan + matriks + perbaikan engine

**Migration baru:** `2026_08_06_110000_create_depreciation_book_tables.php` — `m_buku_penyusutan`, `m_group_buku_penyusutan`, dan `Schema::table('tr_buku_aset')` untuk kolom baru. `book_code` **dibuang di rewrite fase 2**, bukan di sini, agar tidak ada `dropColumn` pada tabel ber-FK.
**API:** `master/BukuPenyusutanController.php`; `master/GroupBukuPenyusutanController.php` extends `MasterLinkController`; **ekstrak `api/app/Services/DepreciationCalculator.php`** dari `DepreciationController.php:100-117`.
**Perbaikan engine:**
- `straight_line` berhenti di akhir masa manfaat (kini tidak pernah berhenti, baris 104)
- floor di `residual_value` (kini `net` dihitung baris 102 tapi tidak dipakai oleh `straight_line`)
- `convention` sebagai enum yang dihormati, memakai `depreciation_start_on`
- `year_basis` menentukan batas tahun bagi `half_year`/`pro_rata`
- switch profil alternatif saat SL-sisa-umur menghasilkan angka lebih besar dari RB
- `AssetController::store()` baris 87 (`if (!empty($profileId))`) → membuat satu `tr_buku_aset` per baris matriks group tersebut
**Test:** `DepreciationCalculatorTest` (unit), `DepreciationBookTest`; `DepreciationTest::book()` diperbarui.
**Yang bisa rusak:** `DepreciationController::propose()` baris 36-38 men-select kolom profil lewat JOIN — harus mengambil **dua** profil (utama + alternatif).

### Fase 6 — Kerangka atribut (EAV)

**Migration baru:** `2026_08_06_120000_create_asset_attribute_tables.php` — `m_tipe_atribut`, `m_tipe_atribut_nilai`, `m_jenis_aset_atribut`, `tr_aset_atribut` (+ `m_tipe_lokasi_aset_atribut` di akhir).
**API:** `master/TipeAtributController.php`; `master/TipeAtributNilaiController.php` + `master/JenisAsetAtributController.php` extends `MasterLinkController`; `GET /api/v1/jenis-aset/{id}/atribut`; `api/app/Support/AssetAttributeValidator.php`; `AssetController` menulis/menyajikan `atribut`.
**UI:** `attributes.ts`, `AssetPage.tsx` merender definisi dinamis lewat `DynamicField` yang sama.
**Test:** `AssetAttributeTest`, `MasterLinkTest`, serta kasus: satu-slot-nilai untuk semua tipe termasuk currency/datetime/reference, fixed-list lintas atribut ditolak oleh FK, reference resource mengikuti contract pemilik, write aset+atribut atomik, retry idempoten lintas instance, stale update ditolak, dan pembacaan list tidak N+1. Kasus concurrency penuh diverifikasi oleh load test PostgreSQL, bukan SQLite.

---

## 9. Apa yang bisa rusak (temuan konkret)

### 9.1 `unarchivedChild()` meledak pada tabel tanpa `deleted_at`
`MasterDataController.php:313-317` melakukan `whereNull('deleted_at')` tanpa syarat. `tr_aset_atribut` tidak punya kolom itu → mengarsipkan `m_tipe_atribut` menghasilkan QueryException 500 di PostgreSQL (SQLite lebih permisif, jadi **test bisa hijau sementara produksi merah**). Diperbaiki oleh `MasterChild::$softDeletes` (§3.2).

### 9.2 `replay()` menghasilkan 409 palsu untuk kolom bertipe
`MasterDataController.php:330`:
```php
if ($record->only(array_keys($payload)) !== $payload) { /* 409 idempotency_conflict */ }
```
Perbandingan `!==` **strict**. Begitu `extraPayload()` menyertakan `capitalization_threshold` dengan cast `decimal:2`, Eloquent mengembalikan string `"1000.00"` sementara payload berisi float `1000.0` → strict tidak sama → retry yang sah dibalas **409**. Komentar di baris 282-283 membuktikan penulis aslinya sudah menemui ini untuk `aktif` dan menormalkannya dengan `filter_var`. Setiap kolom baru wajib dinormalkan di `payload()` dengan tipe yang persis sama seperti hasil cast model, atau `replay()` diubah menjadi perbandingan per-field yang loose. **Ini akan menggigit di fase 3.**

### 9.3 `manual_schedule` tanpa cast — bug laten hari ini
`Models/transaksi/InventarisasiAset/DepreciationProfile.php` mendeklarasikan `manual_schedule` di `$fillable` tapi **tidak punya `casts()`**, sedangkan kolomnya `json` (`2026_07_28_090000:45`) dan `DepreciationProfileController::rules()` memvalidasinya sebagai `array` (baris 40) lalu meneruskannya apa adanya ke `create()` (baris 31). Menyimpan array PHP ke kolom json tanpa cast melempar di PDO PostgreSQL. Tidak ada test yang menangkapnya: `DepreciationTest` menulis `manual_schedule` lewat `DB::table()->update()` dengan `json_encode` manual (baris 71), tidak pernah lewat API. Perbaiki dengan cast di fase 3 dan test bulat-balik.

### 9.4 `number_sequence_ref` tidak boleh free text
`m_group_aset.number_sequence_ref` menunjuk reference Number Sequence Core. AGENTS.md: "Manifest hanya mendeklarasikan reference dan allowed scope" — tenant **tidak dapat** mengarang kode reference. Kalau kolom ini divalidasi sebagai string bebas, `NumberSequenceClient::issue()` akan memanggil Core dengan kode tak dikenal dan `AssetController::store()` mengembalikan **503 `number_sequence_unavailable`** (baris 49-51) — pesan yang menyesatkan untuk kesalahan konfigurasi. Validasi terhadap allow-list tetap yang berasal dari manifest, dan sediakan fallback ke `management-aset.aset` bila kosong. Catatan lanjutan: reference `management-aset.aset` ber-scope `legal_entity` (app.yaml baris ~745); reference per-group harus punya scope yang sama, atau `issue()` dipanggil dengan argumen legal entity yang tidak diterima.

### 9.5 Penciptaan buku yang senyap
`AssetController.php:87` — `if (!empty($profileId))` melewati pembuatan `tr_buku_aset` tanpa jejak apa pun. Aset lolos dibuat, penyusutan diam-diam tidak pernah ada. Setelah matriks, "nol baris matriks → nol buku" harus menjadi keputusan yang diuji, bukan efek samping. Membuatnya hard-fail akan merusak `AssetRegisterTest::test_direct_receipt_...` yang membuat aset tanpa profil sama sekali → pilih: tetap legal, tapi ada test yang menyatakannya.

### 9.6 SQLite menyembunyikan pelanggaran FK komposit
Test suite jalan di SQLite. `loadtest/verify.sql` baris 39-47 adalah satu-satunya gate yang benar-benar membuktikan tidak ada induk lintas tenant. Query itu menyebut `m_entitas_aset`/`m_kategori_aset` dan harus ditulis ulang, jika tidak gate keamanan tenant **lolos secara palsu** setelah refactor.

### 9.7 Ripple rename `entitas-aset` → `model-aset`
11 lokasi: `api/app/Models/master/EntitasAset.php`, `api/app/Http/Controllers/master/EntitasAsetController.php`, `api/routes/api.php:5,26`, `api/tests/Feature/EntitasAsetTest.php`, `api/tests/Feature/MasterDataAsetTest.php`, `ui/src/master/masters.ts:2,61-70`, `contracts/openapi.yaml:180-313` + schema, `app.yaml` (6 lokasi), `README.md`, `database/README.md`, `loadtest/k6/master-data.js:36,184,355`, `loadtest/verify.sql:15,27,39,45,53,64,117`.

### 9.8 Bookmark hash yang mati
`App.tsx:47` jatuh ke `visible[0]` untuk resource tak dikenal. `#/kategori-aset` dan `#/entitas-aset` akan mendarat diam-diam di master lain, bukan pesan error.

### 9.9 EAV tidak boleh hanya aman di level controller
Validasi `data_type`, `validation_mode`, tenant, currency metadata, reference resource, dan fixed-list yang hanya hidup di `AssetAttributeValidator` dapat dilewati oleh retry, worker, import, atau dua instance API yang berlomba. Migration fase 6 wajib menambahkan composite FK fixed-list dan check tepat-satu-slot. SQLite tidak cukup untuk membuktikan perilaku FK tersebut; verifikasi wajib di PostgreSQL.

### 9.10 Scale-out dapat menggandakan atau menghilangkan nilai atribut
Tanpa transaksi atomik, request pembuatan aset dapat berhasil sementara nilai atribut gagal. Tanpa idempotency yang disimpan durable, retry pada instance lain dapat membuat dua aset. Tanpa batch read, satu halaman aset dengan banyak atribut menghasilkan N+1 query. Ketiga hal ini harus diuji di load test dan tidak boleh diselesaikan dengan cache memory per instance.

---

## 10. Berkas paling kritis

- `D:\Kerja\app-erp-management-aset\api\app\Http\Controllers\MasterDataController.php`
- `D:\Kerja\app-erp-management-aset\database\migrations\2026_07_26_000000_create_master_data_aset_tables.php`
- `D:\Kerja\app-erp-management-aset\database\migrations\2026_07_28_090000_create_asset_register_and_depreciation_tables.php`
- `D:\Kerja\app-erp-management-aset\ui\src\master\MasterForm.tsx`
- `D:\Kerja\app-erp-management-aset\api\tests\Feature\MasterDataAsetTest.php`


Error: No such tool available: Write. Write exists but is not enabled in this context. Use one of the available tools instead.
