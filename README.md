# App ERP Management Aset

## Contoh alur dekomisioning

Pengguna membuat dokumen **Dekomisioning aset** untuk aset yang masih aktif. Aplikasi meminta persetujuan CoreERP. Setelah keputusan `approved` diterima melalui event bertanda tangan `core.workflow.decision.v1`, dokumen disetujui dan aset menjadi tidak aktif (`decommissioned`). Baru setelah itu aset boleh dijual atau dimusnahkan. Nomor dokumen memakai referensi `management-aset.dekomisioning-aset` (prefix `DKMA`).

Management Aset adalah app bisnis mandiri dengan API, UI, database, migration, dan contract sendiri.

## Master data

Delapan master tersedia pada `GET /api/v1/{resource}`. Semuanya memakai bentuk yang sama: `kode` (diterbitkan Number Sequence Core, read-only), `nama`, dan `keterangan`, ditambah penanda `aktif`. Data selalu dibatasi oleh tenant pada token konteks yang ditandatangani Core.

Rancangan pemisahan data per organisasi untuk datatable dan transaksi ada di [docs/rancangan-scope-data-aset.md](docs/rancangan-scope-data-aset.md). Saat ini aplikasi baru menerapkan batas tenant dan permission; scope organisasi masih menunggu contract CoreERP.

| No. | Master | Resource | Tabel | Induk |
| --- | --- | --- | --- | --- |
| 1 | Entitas aset | `entitas-aset` | `m_entitas_aset` | jenis aset |
| 2 | Group aset | `group-aset` | `m_group_aset` | — |
| 3 | Kategori aset | `kategori-aset` | `m_kategori_aset` | group aset |
| 4 | Jenis aset | `jenis-aset` | `m_jenis_aset` | kategori aset |
| 5 | Kondisi aset | `kondisi-aset` | `m_kondisi_aset` | — |
| 6 | Pabrikan aset | `pabrikan-aset` | `m_pabrikan_aset` | — |
| 7 | Item checklist maintenance | `item-checklist-maintenance` | `m_item_checklist_maintenance` | — |
| 8 | Analisa maintenance | `analisa-maintenance` | `m_analisa_maintenance` | — |

### Rantai klasifikasi

Master 1–4 membentuk satu rantai foreign key:

```text
group aset ──< kategori aset ──< jenis aset ──< entitas aset
```

Rantai ini adalah **struktur domain milik Management Aset**, bukan organization hierarchy CoreERP. Karena itu ia memang memakai foreign key permanen pada tabel app sendiri; aturan "jangan menyimpan `parent_id` permanen" pada `docs/dev/01a-tenant-and-org-hierarchy.md` berlaku untuk identitas organization di Core, bukan untuk struktur klasifikasi seperti ini.

Master 5–8 berdiri sendiri dan tidak membawa foreign key.

Aturan yang berlaku pada rantai:

- Anak wajib menyebut induknya saat dibuat; induk dapat diganti lewat `PATCH`.
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

Catatan praktis: memilih induk pada UI memerlukan hak **lihat** pada master induk. Pengguna yang hanya memegang `management-aset.kategori-aset.manage` tidak akan melihat daftar pilihan group aset. API sendiri tidak meminta hak baca induk — keberadaan induk diperiksa sebagai referential integrity, bukan sebagai otorisasi.

Arsip memakai soft delete agar record yang kelak direferensikan data turunan tidak hilang secara fisik.

### Penomoran

Setiap master memiliki reference Number Sequence sendiri dengan scope `tenant`. Aplikasi tidak menyimpan counter dan tidak menerima `kode` dari klien; nilai `kode` yang dikirim klien diabaikan.

| Reference | Contoh format yang disarankan |
| --- | --- |
| `management-aset.entitas-aset` | `EA-` + enam digit |
| `management-aset.group-aset` | `GA-` + enam digit |
| `management-aset.kategori-aset` | `KA-` + enam digit |
| `management-aset.jenis-aset` | `JA-` + enam digit |
| `management-aset.kondisi-aset` | `KD-` + enam digit |
| `management-aset.pabrikan-aset` | `PB-` + enam digit |
| `management-aset.item-checklist-maintenance` | `IC-` + enam digit |
| `management-aset.analisa-maintenance` | `AM-` + enam digit |

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

API health tersedia pada `GET /api/v1/health`. Contract lengkap berada di `contracts/openapi.yaml`.

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
