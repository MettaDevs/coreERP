# Laporan dan ekspor

Halaman ini untuk developer. Aturan platformnya ada di [Dokumen cetak, layout, dan ekspor](/dev/23-document-rendering); halaman ini menjelaskan bagian yang dimiliki Management Aset.

Laporan adalah dokumen yang dibuat dari data app: work order yang dibawa teknisi ke lapangan, atau daftar work order untuk diolah di Excel. Yang dimiliki app ini hanya **dataset** dan **layout bawaan**; layout unggahan tenant, antrean ekspor, render, dialog cetak, dan riwayatnya milik Core dan Shell.

## Konsep yang mudah tertukar

**Laporan bukan layout.** Laporan adalah dataset yang ditulis developer: kolom apa yang tersedia, dari tabel mana, dengan hak apa. Layout adalah berkas Word atau Excel yang menentukan tampilannya, dikelola admin tenant di Core. Kalau customer minta bentuk cetakan berbeda, jawabannya layout baru di halaman Layout laporan Core, bukan kode baru; kode baru hanya dibutuhkan bila ada kolom yang belum ada di dataset.

**Endpoint laporan app dipanggil Core, bukan browser.** Rutenya di bawah `internal/v1`, tetapi ia bukan penerima event: ia perintah dan pertanyaan, dikontrak di OpenAPI, dan dipanggil dengan **token konteks pengguna** yang menekan Cetak. Karena itu middleware-nya `coreerp` yang sama dengan `/api/v1`, dan permission serta scope organisasi yang ditegakkan sama persis dengan layar detail.

## Laporan yang ada

Daftar lengkapnya didaftarkan di `api/app/Providers/AppServiceProvider.php` pada `ReportRegistry` dan dideklarasikan di blok `reports` pada `app.yaml`; keduanya harus sejalan.

| Kode manifest | Kelas | Parameter | Layout bawaan | Hak data |
| --- | --- | --- | --- | --- |
| `management-aset.work-order` | `Reporting/Definitions/WorkOrderDocument.php` | `id` work order | Word: header, tabel `baris`, tabel `checklist` | `pemeliharaan-aset.read` |
| `management-aset.daftar-work-order` | `Reporting/Definitions/WorkOrderList.php` | `status`, `dari`, `sampai` | Excel: satu lembar, satu baris per work order | `pemeliharaan-aset.read` |

Kode di sisi app adalah kode manifest tanpa awalan ID app (`work-order`, `daftar-work-order`).

## Endpoint

Semua di bawah `/api/internal/v1/laporan`, middleware `coreerp`. Kontraknya di `contracts/src/paths/laporan.yaml`.

| Endpoint | Guna |
| --- | --- |
| `GET {kode}` | Placeholder (`fields`), nama parameter, dan layout bawaan |
| `GET {kode}/layouts/{key}` | Berkas layout bawaan dari `api/resources/laporan/<kode>/<key>.<format>` |
| `POST {kode}/dataset` | Dataset: `fields` sekali tampil, `tables` diulang per baris, `file_name` |

Pemeriksa cakupan kontrak memperlakukan rute `internal/v1` yang ada di OpenAPI sebagai endpoint REST, dan sisanya sebagai penerima event AsyncAPI.

## Hak akses

Tidak ada permission baru. Menjalankan laporan menuntut permission data yang disebut manifest (`management-aset.pemeliharaan-aset.read`), diperiksa Core saat tombol ditekan dan diperiksa lagi di `LaporanInternalController` saat dataset diminta. Mengelola layout adalah hak admin tenant di Core.

Pemeriksaan ganda itu disengaja: token yang dibawa Core adalah token pengguna, dan app tidak mempercayai pemeriksaan pihak lain atas datanya sendiri.

## Aturan yang dijaga, dan alasannya

**Dataset menegakkan scope organisasi yang sama dengan layar detail.** `WorkOrderDocument` memakai `OrganizationScope` pada kueri yang sama seperti `PemeliharaanAsetController`. Tanpa ini, mencetak menjadi jalan pintas membaca work order unit lain.

**Record di luar scope dijawab 422 dengan pesan pengguna, bukan 404 atau 500.** `ReportDataException` membawa kalimat yang sama dengan yang dilihat pengguna di layar, dan Core meneruskannya apa adanya ke baris ekspor. Kesalahan server hanya untuk hal yang benar-benar rusak.

**Nilai diformat di dataset, bukan di layout.** Tanggal `d/m/Y H:i`, jam dua desimal, status berlabel Indonesia. Semua layout satu laporan menampilkan tanggal dengan cara yang sama tanpa pembuat template perlu tahu format apa pun.

**Parameter divalidasi di app.** Manifest hanya menyebut nama parameter; aturannya (`ulid`, `date_format`, `in:`) ada di `parameterRules()` tiap definisi, karena app yang tahu artinya.

**Kop dan footer datang dari Core, bukan dari dataset.** Kedua layout bawaan memakai blok kop tiga kolom dengan placeholder `${kop.*}` yang diisi Core dari Identitas cetak legal entity (atau operating unit) yang mencetak, dengan alamat dan kontak dari buku alamat organisasi. Dataset app tidak memuat nama perusahaan, alamat, atau logo; menambahkannya akan menggandakan sumber kebenaran.

**Layout bawaan dibangkitkan dari kode.** `php artisan laporan:bangun-layout-bawaan` menulis ulang berkas di `api/resources/laporan/` dari `Console/Commands/BangunLayoutLaporanBawaan.php`. Perubahan template terbaca di review sebagai perubahan kode, dan hasilnya sama di mesin siapa pun; berkas hasilnya tetap di-commit karena runtime membaca berkas. Paket PhpWord dan PhpSpreadsheet hanya ada di `require-dev` untuk command ini; render dilakukan Core.

## Yang datang dari Core dan Shell

- Halaman **Layout laporan** dan **Ekspor laporan** di Control Plane, dialog cetak, tray Ekspor di header, dan lonceng notifikasi.
- Antrean dan worker, engine PDF `core-renderer`, penyimpanan layout dan hasil.
- Token konteks pengguna pada setiap panggilan ke endpoint di atas.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `api/app/Reporting/ReportDefinition.php` | Kontrak satu laporan: kode, permission, layout bawaan, parameter, placeholder, dataset |
| `api/app/Reporting/ReportContext.php` | Konteks tepercaya dari token, dan request tiruan untuk `OrganizationScope` |
| `api/app/Reporting/ReportData.php` | Hasil dataset: `fields` sekali tampil, `tables` diulang per baris |
| `api/app/Reporting/ReportDataException.php` | Pesan untuk pengguna saat dataset tidak dapat disusun |
| `api/app/Reporting/Definitions/` | Dataset tiap laporan |
| `api/app/Http/Controllers/laporan/LaporanInternalController.php` | Tiga endpoint yang dipanggil Core |
| `api/resources/laporan/` | Layout bawaan per kode laporan |
| `api/tests/Feature/LaporanInternalTest.php` | Definisi, layout bawaan, dataset dengan permission dan scope, filter daftar |
| `ui/src/shell.ts` | `requestPrint()` mengirim `coreerp.print` ke Shell |

Tombol **Cetak** ada di halaman rincian work order dan **Ekspor daftar** di daftarnya; keduanya hanya mengirim kode laporan dan parameter ke Shell, lalu Shell membuka dialog cetak milik Core. Tombol tampil hanya bila app dibuka di dalam Shell.

## Menambah laporan baru

1. Tulis kelas yang mengimplementasikan `ReportDefinition` di `Reporting/Definitions/`. Dataset memakai `OrganizationScope` seperti endpoint detailnya.
2. Tambahkan layout bawaan ke `BangunLayoutLaporanBawaan`, jalankan command-nya, commit berkasnya.
3. Daftarkan di `AppServiceProvider` dan di blok `reports` pada `app.yaml`.
4. Tambahkan tombol cetak pada halaman record-nya dengan `requestPrint()`.
5. Tambahkan test di `LaporanInternalTest` untuk placeholder utama dan penolakan di luar scope, lalu perbarui halaman ini.

Kontrak tidak berubah: tiga endpoint laporan generik dan menerima kode baru apa adanya.

## Halaman terkait

- [Dokumen cetak, layout, dan ekspor](/dev/23-document-rendering) — aturan platform dan bagian yang dimiliki Core
- [Pemeliharaan aset](/apps/management-aset/transaction/pemeliharaan-aset/) — dokumen yang dicetak laporan pertama
- [Batas tenant dan organisasi](/apps/management-aset/arsitektur/batas-tenant-dan-organisasi) — scope yang ditegakkan dataset
