# App ERP Management Aset

## Contoh alur dekomisioning

Pengguna membuat dokumen **Dekomisioning aset** untuk aset yang masih aktif. Aplikasi meminta persetujuan CoreERP. Setelah keputusan `approved` diterima melalui event bertanda tangan `core.workflow.decision.v2`, dokumen disetujui dan aset menjadi tidak aktif (`decommissioned`). Baru setelah itu aset boleh dijual atau dimusnahkan. Nomor dokumen memakai referensi `management-aset.dekomisioning-aset` (prefix `DKMA`).

Management Aset adalah app bisnis mandiri dengan API, UI, database, migration, dan contract sendiri.

## Master data

Delapan master tersedia pada `GET /api/v1/{resource}`. Semuanya memakai bentuk yang sama: `kode` (diterbitkan Number Sequence Core, read-only), `nama`, dan `keterangan`, ditambah penanda `aktif`. Data selalu dibatasi oleh tenant pada token konteks yang ditandatangani Core.

Rancangan pemisahan data per organisasi untuk datatable dan transaksi ada di [docs/rancangan-scope-data-aset.md](docs/rancangan-scope-data-aset.md). Saat ini aplikasi baru menerapkan batas tenant dan permission; scope organisasi masih menunggu contract CoreERP.

| No. | Master | Resource | Tabel | Induk |
| --- | --- | --- | --- | --- |
| 1 | Group aset | `group-aset` | `m_group_aset` | — |
| 2 | Jenis aset | `jenis-aset` | `m_jenis_aset` | — |
| 3 | Model aset | `model-aset` | `m_model_aset` | pabrikan aset (wajib), jenis aset (opsional) |
| 4 | Kondisi aset | `kondisi-aset` | `m_kondisi_aset` | — |
| 5 | Pabrikan aset | `pabrikan-aset` | `m_pabrikan_aset` | — |
| 6 | Tipe lokasi aset | `tipe-lokasi-aset` | `m_tipe_lokasi_aset` | — |
| 7 | Lokasi aset | `lokasi-aset` | `m_lokasi_aset` | lokasi aset (opsional, menunjuk dirinya sendiri), tipe lokasi (opsional) |
| 8 | Item checklist maintenance | `item-checklist-maintenance` | `m_item_checklist_maintenance` | — |
| 9 | Analisa maintenance | `analisa-maintenance` | `m_analisa_maintenance` | — |
| 10 | Profil penyusutan | `profil-penyusutan` | `m_profil_penyusutan` | — |

### Field di luar bentuk dasar

Sebagian master membawa kolom sendiri di luar `kode`/`nama`/`keterangan`/`aktif`. Kolom itu dideklarasikan sekali per master lewat `extraRules()`/`extraPayload()`/`extraPresent()` pada controller, dan dirender di UI lewat `extraFields` pada `ui/src/master/masters.ts`. Tidak ada halaman bespoke per master.

**Group aset** membawa perlakuan finansial, mengikuti "Fixed asset group" F&O:

| Field | Arti |
| --- | --- |
| `tipe_harta` | Kelompok harta berwujud menurut PMK; menentukan masa manfaat dan tarif fiskal |
| `major_type` | Berwujud, tidak berwujud, hak guna, atau bernilai rendah |
| `capitalization_threshold` | Di bawah nilai ini perolehan dibebankan, bukan dikapitalisasi |
| `posting_layers` | Lapisan pembukuan yang boleh dipakai group ini |

**Profil penyusutan** membawa aturan penyusutannya sendiri (`method`, `frequency`, `year_basis`, `convention`, `useful_life_periods`, `rate_percent`, `manual_schedule`). Field mana yang wajib bergantung pada `method`, dan divalidasi sebagai aturan per field sehingga klien menerima pesan yang tepat sasaran.

### Klasifikasi aset

Klasifikasi mengikuti model Dynamics 365 F&O: **master klasifikasi datar dan saling lepas**, dan aset menunjuk masing-masing secara langsung.

```text
                ┌─ group aset   (sumbu FINANSIAL: penyusutan, GL, penomoran)
                │
        ASET ───┼─ jenis aset   (sumbu TEKNIS: maintenance, atribut)
                │
                └─ model aset   (katalog per pabrikan, opsional)
```

Kedua sumbu wajib dan sejajar; tidak ada yang menyaring pilihan yang lain, dan tidak ada tingkat perantara yang wajib diisi. Karena itu tenant yang hanya mengenal satu tingkat klasifikasi tetap terlayani, sementara tenant yang butuh pembedaan lebih rinci menambahkannya sebagai atribut, bukan sebagai sub-kategori baru.

Yang hierarkis hanyalah **data**, bukan skema: `m_lokasi_aset.parent_id` dan `tr_penerimaan_aset.parent_asset_id` menunjuk dirinya sendiri sedalam yang dibutuhkan tenant. Keduanya **struktur domain milik Management Aset**, bukan organization hierarchy CoreERP; aturan "jangan menyimpan `parent_id` permanen" pada `docs/dev/01a-tenant-and-org-hierarchy.md` berlaku untuk identitas organization di Core, bukan untuk struktur seperti ini.

### Lokasi dan dimensi keuangan

Pohon lokasi sengaja **terpisah** dari struktur organisasi. "Di mana benda ini berada" dan "siapa yang bertanggung jawab" adalah dua pertanyaan berbeda yang berubah karena sebab berbeda: reorganisasi tidak memindahkan barang, dan memindahkan barang tidak mengubah struktur organisasi. Menyatukan keduanya membuat riwayat lokasi rusak setiap kali unit kerja digabung, dan membatasi kedalaman lokasi pada unit organisasi terkecil — padahal stock opname butuh sampai tingkat ruangan atau rak.

Keduanya dihubungkan lewat satu field opsional, `m_lokasi_aset.org_unit_id`; padanan toggle **Update asset dimension** pada Functional location type di F&O. Saat aset diterima atau dimutasi, `financial_dimension_org_unit_id` pada aset diisi dari unit milik lokasinya, dan jatuh kembali ke unit pengguna bila lokasi tidak dipetakan. Nilainya **disalin, bukan dilihat saat dibaca**: mengubah pemetaan lokasi kelak tidak menulis ulang pembebanan aset yang sudah berjalan.

Aturan yang berlaku pada master berinduk:

- Anak wajib menyebut induk wajibnya saat dibuat; induk dapat diganti lewat `PATCH`. Master dengan lebih dari satu induk memperlakukan tiap induk secara terpisah — memindahkan satu tidak menggeser yang lain.
- Induk wajib berada pada tenant yang sama dan belum diarsipkan. Selain divalidasi aplikasi, database menegakkannya lewat foreign key gabungan `(tenant_id, <induk>_id)` → `(tenant_id, id)`, sehingga induk lintas tenant tidak mungkin tersimpan.
- Induk tidak dapat diarsipkan selama masih dipakai anak yang belum diarsipkan; API menjawab `409` dengan kode `referenced_by_children`. Penanda `aktif` tidak memengaruhi aturan ini — yang dijaga adalah referensi yang masih hidup, bukan status pakainya.
- Daftar anak dapat disaring dengan `?<induk>_id=<ULID>`, dan setiap record anak menyertakan ringkasan induk (`id`, `kode`, `nama`) agar UI tidak perlu permintaan tambahan. Induk yang sudah diarsipkan tetap disertakan supaya asal data tidak hilang.

### Hak akses

Setiap master memiliki empat permission dan satu duty tersendiri, sehingga satu master dapat dikelola tanpa ikut memberi hak pada master sebelahnya:

- `management-aset.<resource>.read`
- `management-aset.<resource>.create`
- `management-aset.<resource>.update`
- `management-aset.<resource>.archive`

Duty `management-aset.<resource>.manage` menggabungkan keempatnya. Role tenant menyusun duty tersebut sesuai jabatan lokal; tidak ada duty gabungan lintas master.

Catatan praktis: memilih induk pada UI memerlukan hak **lihat** pada master induk. Pengguna yang hanya memegang `management-aset.model-aset.manage` tidak akan melihat daftar pilihan pabrikan aset. API sendiri tidak meminta hak baca induk — keberadaan induk diperiksa sebagai referential integrity, bukan sebagai otorisasi.

Arsip memakai soft delete agar record yang kelak direferensikan data turunan tidak hilang secara fisik.

### Penomoran

Setiap master memiliki reference Number Sequence sendiri dengan scope `tenant`. Aplikasi tidak menyimpan counter dan tidak menerima `kode` dari klien; nilai `kode` yang dikirim klien diabaikan.

Prefix di bawah adalah `default_prefix` pada `app.yaml`; `loadtest/verify.sql` memeriksa prefix yang sama supaya nomor yang tertukar antar reference langsung ketahuan.

| Reference | Prefix |
| --- | --- |
| `management-aset.group-aset` | `GRPA` |
| `management-aset.jenis-aset` | `JNSA` |
| `management-aset.model-aset` | `MDLA` |
| `management-aset.kondisi-aset` | `KNDA` |
| `management-aset.pabrikan-aset` | `PBRA` |
| `management-aset.tipe-lokasi-aset` | `TLKA` |
| `management-aset.lokasi-aset` | `LOCA` |
| `management-aset.item-checklist-maintenance` | `ICMA` |
| `management-aset.analisa-maintenance` | `ANMA` |
| `management-aset.profil-penyusutan` | `DPRE` |

Format, status, dan counter adalah keputusan owner/admin tenant di Control Plane, bukan milik kode app. Profile yang dipakai seluruh reference di atas: non-continuous, tanpa mode manual, tanpa reset periode, preallocation 20, minimum 1, maksimum 999999.

`POST` wajib membawa header `Idempotency-Key`. Retry dengan kunci yang sama mengembalikan record yang sama beserta header `Idempotent-Replayed: true` dan tidak menerbitkan nomor kedua; kunci yang sama dengan isi berbeda dijawab `409 idempotency_conflict`.

## Perencanaan aset

Perencanaan memakai `tr_perencanaan_aset` sebagai header dan `tr_perencanaan_aset_details` sebagai rincian. Lookup memilih **jenis aset** dari `m_jenis_aset`; aset fisik belum ada pada tahap ini. Spesifikasi yang diperlukan disimpan pada `requested_specification` di setiap rincian sebagai snapshot transaksi, sehingga tidak ada master spesifikasi generik.

Rencana membawa entitas legal dan unit kerja dari konteks CoreERP yang aktif, nomor `PLNA` diterbitkan Core dengan scope entitas legal, dan hanya rencana berstatus draf yang dapat diubah atau diarsipkan. Hak aksesnya dipisah menjadi `read`, `create`, `update`, dan `archive` melalui entry point → permission → privilege → duty `management-aset.perencanaan-aset.manage`.

## Setup

1. Daftarkan `app.yaml` melalui alur publish app CoreERP sampai installation registry menyatakan release `ready`. Registrasi katalog perlu dikirim ulang setiap kali daftar permission, duty, atau reference nomor bertambah.
2. Buat service credential untuk `management-aset`, lalu isi `COREERP_SERVICE_TOKEN` pada API.
3. Pakai nilai `COREERP_APP_CONTEXT_SIGNING_KEY` yang sama pada Core dan API Aset.
4. Aktifkan kedelapan reference pada **Nomor dokumen** di Control Plane dengan scope tenant dan profile pada tabel di atas.
5. Jalankan migration API dan build UI. UI menerima token konteks dari Web Shell melalui `postMessage` dan tidak menerima `tenant_id` dari browser.

Manifest masih berversi `0.1.0`. Menaikkan versi release belum dapat dilakukan lewat endpoint registrasi (lihat `docs/dev/13-publishing-an-app-release.md`): versi release harus sama dengan versi katalog dan upgrade memerlukan compatibility matrix, backup, serta rollback terverifikasi. Selama app masih berada pada release pengembangan, jalankan migration baru pada placement pengembangan dan jangan memperlakukannya sebagai upgrade produksi.

API health tersedia pada `GET /api/v1/health`. Contract lengkap berada di `contracts/openapi.yaml`, yang merupakan bundle hasil generate dari `contracts/src/`. Sunting sumbernya di `contracts/src/`, lalu jalankan `python contracts/bundle.py`; `--check` memverifikasi bundle masih sinkron.

## Workflow

Manifest mendaftarkan tipe workflow **Verifikasi usulan pemusnahan aset**.
Admin tenant memilih approver dan mengaktifkan versinya melalui pengaturan
Workflow di CoreERP. Tipe ini menandai proses yang dapat dikonfigurasi; pengajuan
dan keputusan verifikasi di aplikasi Aset akan ditambahkan bersama endpoint
approval, bukan disimpulkan hanya dari konfigurasi.

## Struktur kode

API memakai satu base controller `App\Http\Controllers\MasterDataController` yang memegang seluruh perilaku bersama: hak akses per resource, batas tenant, idempotency, penerbitan nomor, validasi induk, dan penjagaan arsip. Kode khusus master berada di `api/app/Http/Controllers/master/` dan `api/app/Models/master/`. UI master berada di `ui/src/master/`. Pola folder untuk fitur berikutnya tercatat pada `docs/agent.md`.

Test berada di `api/tests/Feature`. Selain CRUD, test menjaga hal yang tidak boleh regresi: induk lintas tenant tertolak, hak satu master tidak merembet ke master lain, induk beranak yang belum diarsipkan tidak dapat diarsipkan, dan `kode` selalu berasal dari Core.

## Load test

Test feature tidak cukup untuk menyatakan modul selesai. Ia menjalankan satu request pada satu proses terhadap SQLite, sehingga tidak dapat melihat koneksi database habis, nomor terbit dua kali, batas tenant yang bocor saat request saling menyela, atau idempotency key yang berlomba.

`loadtest/` berisi stack lengkap: empat instance API di belakang nginx, PostgreSQL asli, stub Number Sequence yang sekaligus mencatat setiap nomor, dan skenario k6 dengan 1000 virtual user pada 128 tenant. Cara menjalankan, hasil terukur, dan batas kejujurannya ada di [loadtest/README.md](loadtest/README.md).

Hasil pada 1000 VU: nol pelanggaran lintas tenant, nol nomor ganda dari 4.342 nomor terbit, nol eskalasi hak, dan nol error 5xx dari aplikasi. SLO latensi terpenuhi sampai 16 request serentak pada laptop 12 core; di atas itu yang bertambah adalah antrean, bukan hasil.

Load test ini juga yang menemukan bahwa penanganan koneksi database menjadi bottleneck jauh sebelum kode modul: tanpa koneksi persisten, PostgreSQL membakar 5,5 core hanya untuk fork proses baru setiap request. Karena itu tersedia `DB_PERSISTENT` pada `api/config/database.php`, default mati, dinyalakan pada deployment dengan worker proses tetap.
# Dekomisioning aset

Aset yang akan dijual atau dimusnahkan terlebih dahulu diajukan untuk dekomisioning. Setelah approver menyetujui di CoreERP, event keputusan mengubah aset menjadi `decommissioned`; baru setelah itu aplikasi menerima usulan penjualan atau pemusnahan. Nomor dokumen memakai reference `management-aset.dekomisioning-aset` dengan prefix `DKMA` per badan hukum.
