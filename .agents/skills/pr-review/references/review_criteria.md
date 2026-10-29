# Kriteria review CoreERP

Baca aturan pada branch/revisi yang sedang dinilai dan aturan kanonik terbaru. Jelaskan
perbedaannya jika contoh Aset lama belum mengikuti aturan terbaru. Jangan menjadikan
legacy sebagai pengecualian untuk source baru.

## Cakupan yang dicatat sebelum review selesai

Catat hasil tiap pemeriksaan yang relevan sebagai `verified`, `finding`, `source_only`,
atau `not_applicable`. `verified` membutuhkan bukti yang sudah diperiksa. `source_only`
menyebut batas pemeriksaan secara terbuka. `not_applicable` membutuhkan alasan berdasarkan
scope diff, bukan karena reviewer belum sempat mengecek.

| ID pemeriksaan | Berlaku ketika | Yang perlu diperiksa |
| --- | --- | --- |
| `scope` | Semua PR | Head/base tepat, perubahan langsung dibanding inherited, tujuan dan batas tugas |
| `behavior` | Semua PR | Perilaku pengguna, kasus gagal/kosong/batas, data contoh yang masuk jalur nyata |
| `code_quality` | Semua PR | Kepemilikan tanggung jawab, duplikasi aturan/state, identitas data, kode mati, penggunaan API SDK yang sebenarnya |
| `verification` | Semua PR | Bukti yang dijalankan, hasil dan keterbatasan. CI hijau bukan bukti alur bisnis benar |
| `shared_capability_ownership` | Resource/fitur bisnis melalui API, form atau lifecycle | Jika modul pertama tidak dibeli, master/kemampuan bersama yang diperlukan modul lain tetap tersedia; keputusan master ke Foundation, engine netral ke Platform |
| `authorization_and_input` | Endpoint/API atau perubahan security/manifest/grant | Permission resource/aksi, tenant/company/policy, input query/write tervalidasi, ID dari sumber tepercaya |
| `n_plus_one` | Pembacaan database | Query pada ukuran batch berbeda, termasuk serializer/callback; lazy-load kontrol bila membuat penghitung sendiri |
| `payload_and_batch_bounds` | Pembacaan database | Batas server, jumlah child, field/proyeksi, alias JSON ganda, query per lookup/baris, respons saat input batch ekstrem |
| `datatable_incremental` | Daftar UI | Lazy loading/incremental saat scroll, cursor/batch terbatas, search/filter/sort server, pembatalan hasil request basi |
| `load_error_states` | Daftar UI | Loading, hasil kosong, gagal dan tanpa izin berbeda; error menetap pada pekerjaan aktif dan tidak disamarkan sebagai empty state |
| `error_header_and_fields` | Form UI | Ringkasan di bawah header/toolbar, state field sama, `noValidate` dan validasi aplikasi, draft tetap ada, busy/dirty guard |
| `numbering_concurrency_soft_delete` | Lifecycle/persistence berubah | Issuer nomor, scope indeks, retry/key/payload, RowVersion, transaksi, identitas child, soft delete |
| `contracts_and_docs` | Contract/integrasi berubah | Permukaan nyata tercatat, versi/transport benar, consumer dan publisher selaras, docs tidak menjanjikan perilaku yang belum ada |

Daftar DataTable **tidak memakai navigasi nomor halaman**. Lazy loading UI berbeda dari
lazy loading relasi Eloquent penyebab N+1. `renderRows` adalah API SDK yang sah; periksa
perilaku sebenarnya sebelum menuduh renderer manual rusak.

Untuk gate pemilik, bedakan fitur bersama yang benar-benar diperlukan modul lain dari
integrasi opsional atas fakta proses modul asal. Bila aturan dan makna sudah jelas,
langsung minta pemindahan ke Core; jangan menawarkan kepemilikan modul sebagai pilihan
setara. Pengujian harus menunjukkan master tetap tersedia ketika modul asal tidak
dipasang atau dinonaktifkan, dengan permission dan scope data tetap dijaga.

## Bukti performa yang tidak tertukar

- Pisahkan jumlah query, jumlah row/payload, dan write amplification. INSERT per rincian
  bukan otomatis read N+1. Query tetap bisa membawa payload yang membesar tanpa batas.
- Untuk klaim bebas read N+1 yang diuji, gunakan ukuran induk berbeda dan child terisi.
  Serialisasi respons ikut dihitung. Catat jumlah record yang benar-benar dimuat, bukan
  fixture yang ternyata terpotong default batch pertama.
- Gunakan `preventLazyLoading` bila cocok. Jika membuat penghitung query, tunjukkan kontrol
  yang sengaja lazy-load memang gagal/menghasilkan pertumbuhan query.
- Catat environment dan bagian yang dilewati. SQLite tanpa trigger PostgreSQL bukan
  benchmark PostgreSQL, uji kapasitas, atau bukti efek trigger.
- Untuk hubungan master pada banyak row, cari pola batch seperti
  `VendorDirectory::findMany`. Jangan memperkenalkan `find()` per sel matriks/list.
- Jangan menyarankan bulk insert yang melewati tenant guard, ULID, audit/event, versi atau
  identitas child tanpa memeriksa kebutuhan tersebut.

## Kualitas dan kehilangan data

Jalankan kasus ubah Vendor, buka ulang edit, rincian bernama sama, baris yang baru terisi
sebagian, retry, serta tutup ketika dirty/busy bila diff menyentuhnya. Label/nama yang bisa
disunting bukan identitas record. Filter nama kosong bukan pengganti validasi baris parsial.

Panjang file, jumlah hook, cast, wrapper atau komentar adalah petunjuk untuk ditelusuri,
bukan temuan otomatis. Pilih perbaikan terkecil yang menghapus penyebab tanpa framework
generik atau compatibility layer spekulatif. Tandai cleanup opsional sebagai P3.

## Sumber kanonik dan contoh

- UI dan lazy loading: `../coreerp-ui/SKILL.md`, `../coreerp-page-standard/SKILL.md`,
  `../../../docs/dev/02-module-standard.md` bagian daftar dimuat bertahap.
- Error terbaru: `../coreerp-ui/references/error-feedback.md` dan
  `../../../docs/dev/28-pelaporan-kesalahan.md`. Contoh toast lama tidak mengalahkan pola header.
- Scope/security/lifecycle: `../coreerp-architecture/SKILL.md`,
  `../../../docs/dev/09-identity-and-access.md`, `../../../docs/dev/14-number-sequences.md`.
- Pola Aset yang relevan: `modules/apperp/management-aset/ui/use-record-list.ts`,
  `ui/master/MasterPage.tsx`, `ui/master/MasterForm.tsx`,
  `src/Http/Controllers/MasterDataController.php`, dan `MasterLinkController.php`.

Path skill di atas relatif terhadap folder `pr-review`; path docs/code relatif terhadap
root repository. Pilih contoh yang perilakunya benar-benar serupa, baca source-nya, lalu
berikan permalink revisi/baris yang sudah diverifikasi pada komentar GitHub.
