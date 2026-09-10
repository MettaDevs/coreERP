# Laporan dan ekspor

Halaman ini untuk developer. Aturan platformnya ada di [Dokumen cetak, layout, dan ekspor](/dev/23-document-rendering); halaman ini menjelaskan bagian yang dimiliki Management Aset.

Laporan adalah dokumen yang dibuat dari data app: work order yang dibawa teknisi ke lapangan, atau daftar work order untuk diolah di Excel. Yang dimiliki app ini hanya **dataset** dan **layout bawaan**; layout unggahan tenant, antrean ekspor, render, dialog cetak, dan riwayatnya milik Core dan Shell.

## Konsep yang mudah tertukar

**Laporan bukan layout.** Laporan adalah dataset yang ditulis developer: kolom apa yang tersedia, dari tabel mana, dengan hak apa. Layout adalah berkas Word atau Excel yang menentukan tampilannya, dikelola admin tenant di Core. Kalau customer minta bentuk cetakan berbeda, jawabannya layout baru di halaman Layout laporan Core, bukan kode baru; kode baru hanya dibutuhkan bila ada kolom yang belum ada di dataset.

**Laporan dibaca Core, bukan diminta browser.** Mesin laporan Core memanggil modul ini **di dalam
proses yang sama**, lewat kontrak `PenyediaLaporanModul`. Tidak ada endpoint HTTP, tidak ada token,
dan tidak ada alamat yang harus benar sebelum sebuah laporan bisa dicetak.

**Konteks dibawa sebagai argumen, bukan dibaca dari permintaan.** Ekspor berjalan di worker antrean,
tempat tidak ada `Request` maupun sesi. Konteks yang dulu ikut sebagai token kini ikut sebagai
parameter — satu-satunya alternatifnya adalah keadaan global yang benar pada permintaan biasa dan
kosong pada worker, persis kegagalan yang paling sulit ditemukan.

## Laporan yang ada

Daftar lengkapnya didaftarkan di `src/ModuleServiceProvider.php` pada `ReportRegistry` dan dideklarasikan di blok `reports` pada `app.yaml`; keduanya harus sejalan.

| Kode manifest | Kelas | Parameter | Layout bawaan | Hak data |
| --- | --- | --- | --- | --- |
| `management-aset.work-order` | `src/Reporting/Definitions/WorkOrderDocument.php` | `id` work order | Word: header, tabel `baris`, tabel `checklist` | `pemeliharaan-aset.read` |
| `management-aset.daftar-work-order` | `src/Reporting/Definitions/WorkOrderList.php` | `status`, `dari`, `sampai` | Excel: satu lembar, satu baris per work order | `pemeliharaan-aset.read` |

Kode di sisi modul adalah kode manifest tanpa awalan ID modul (`work-order`, `daftar-work-order`).

## Yang diminta Core

`PenyediaLaporan` mendaftarkan diri ke `DaftarLaporan` sekali saat boot penyedia layanan modul.
Tanpa pendaftaran itu Core tidak tahu modul punya laporan, dan ia jatuh ke jalur HTTP lama — alamat
yang sudah tidak ada.

| Yang diminta | Guna |
| --- | --- |
| `definisi()` | Placeholder (`fields`), nama parameter, dan layout bawaan |
| `layout()` | Berkas layout bawaan dari `resources/laporan/<kode>/<key>.<format>` |
| `dataset()` | Dataset: `fields` sekali tampil, `tables` diulang per baris, `file_name` |

Tiga rute `internal/v1/laporan` yang dulu melayani ketiganya dihapus bersama controllernya.

## Hak akses

Tidak ada permission baru. Menjalankan laporan menuntut permission data yang disebut manifest (`management-aset.pemeliharaan-aset.read`), diperiksa Core saat tombol ditekan dan diperiksa lagi di `PenyediaLaporan` saat dataset diminta. Mengelola layout adalah hak admin tenant di Core.

Pemeriksaan ganda itu tetap disengaja walau pemanggilnya berpindah dari jaringan ke pemanggilan fungsi. Core memeriksa hak menjalankan laporannya; modul memeriksa hak membaca data yang dilaporkan. Yang kedua tetap perlu ada.

## Aturan yang dijaga, dan alasannya

**Dataset menegakkan scope organisasi yang sama dengan layar detail.** `WorkOrderDocument` memakai `OrganizationScope` pada kueri yang sama seperti `PemeliharaanAsetController`. Tanpa ini, mencetak menjadi jalan pintas membaca work order unit lain.

**Record di luar scope dijawab dengan pesan pengguna, bukan kegagalan server.** `ReportDataException` membawa kalimat yang sama dengan yang dilihat pengguna di layar, dan Core meneruskannya apa adanya ke baris ekspor. Modul tidak menyebut kelas pengecualian Core — ia hanya menyebut kontraknya — jadi penerjemahannya menjadi kegagalan laporan dikerjakan Core di sisi pemanggil.

**Nilai diformat di dataset, bukan di layout.** Tanggal `d/m/Y H:i`, jam dua desimal, status berlabel Indonesia. Semua layout satu laporan menampilkan tanggal dengan cara yang sama tanpa pembuat template perlu tahu format apa pun.

**Parameter divalidasi di app.** Manifest hanya menyebut nama parameter; aturannya (`ulid`, `date_format`, `in:`) ada di `parameterRules()` tiap definisi, karena app yang tahu artinya.

**Kop dan footer datang dari Core, bukan dari dataset.** Kedua layout bawaan memakai blok kop tiga kolom dengan placeholder `${kop.*}` yang diisi Core dari Identitas cetak legal entity (atau operating unit) yang mencetak, dengan alamat dan kontak dari buku alamat organisasi. Dataset app tidak memuat nama perusahaan, alamat, atau logo; menambahkannya akan menggandakan sumber kebenaran.

**Layout bawaan dibangkitkan dari kode.** `php artisan laporan:bangun-layout-bawaan` menulis ulang berkas di `resources/laporan/` dari `src/Console/Commands/BangunLayoutLaporanBawaan.php`. Perintah itu didaftarkan penyedia layanan modul; tanpa pendaftaran itu ia tidak ada sama sekali, karena kerangka lama yang menemukannya dengan memindai foldernya sendiri sudah dibuang. Perubahan template terbaca di review sebagai perubahan kode, dan hasilnya sama di mesin siapa pun; berkas hasilnya tetap di-commit karena runtime membaca berkas.

## Yang datang dari Core dan Shell

- Halaman **Layout laporan** dan **Ekspor laporan** di Control Plane, dialog cetak, tray Ekspor di header, dan lonceng notifikasi.
- Antrean dan worker, engine PDF `core-renderer`, penyimpanan layout dan hasil.
- Konteks pengguna yang diserahkan sebagai argumen pada setiap panggilan di atas.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `src/Reporting/PenyediaLaporan.php` | Pintu yang dipanggil Core; mengimplementasikan `PenyediaLaporanModul` |
| `src/Reporting/ReportRegistry.php` | Daftar laporan modul ini |
| `src/Reporting/ReportDefinition.php` | Kontrak satu laporan: kode, permission, layout bawaan, parameter, placeholder, dataset |
| `src/Reporting/ReportContext.php` | Konteks yang diserahkan Core, dan penyusun scope untuk `OrganizationScope` |
| `src/Reporting/ReportData.php` | Hasil dataset: `fields` sekali tampil, `tables` diulang per baris |
| `src/Reporting/ReportDataException.php` | Pesan untuk pengguna saat dataset tidak dapat disusun |
| `src/Reporting/Definitions/` | Dataset tiap laporan |
| `src/Console/Commands/BangunLayoutLaporanBawaan.php` | Pembangkit layout bawaan |
| `resources/laporan/` | Layout bawaan per kode laporan |
| `tests/Feature/PenyediaLaporanTest.php` | Definisi, layout bawaan, dataset dengan permission dan scope, filter daftar |
| `ui/print.ts` | `requestPrint()` mengirim `CustomEvent('coreerp:print')` ke shell |

Semuanya relatif terhadap `modules/apperp/management-aset/`.

Tombol **Cetak** ada di halaman rincian work order dan **Ekspor daftar** di daftarnya; keduanya hanya mengirim kode laporan dan parameter ke shell, lalu shell membuka dialog cetak milik Core.

## Menambah laporan baru

1. Tulis kelas yang mengimplementasikan `ReportDefinition` di `src/Reporting/Definitions/`. Dataset memakai `OrganizationScope` seperti endpoint detailnya.
2. Tambahkan layout bawaan ke `BangunLayoutLaporanBawaan`, jalankan command-nya, commit berkasnya.
3. Daftarkan di `ModuleServiceProvider` pada `ReportRegistry`, dan di blok `reports` pada `app.yaml`.
4. Tambahkan tombol cetak pada halaman record-nya dengan `requestPrint()`.
5. Tambahkan test di `PenyediaLaporanTest` untuk placeholder utama dan penolakan di luar scope, lalu perbarui halaman ini.

`PenyediaLaporan` tidak berubah: ia generik dan menerima kode baru apa adanya.

## Halaman terkait

- [Dokumen cetak, layout, dan ekspor](/dev/23-document-rendering) — aturan platform dan bagian yang dimiliki Core
- [Pemeliharaan aset](/apps/management-aset/transaction/pemeliharaan-aset/) — dokumen yang dicetak laporan pertama
- [Batas tenant dan organisasi](/apps/management-aset/arsitektur/batas-tenant-dan-organisasi) — scope yang ditegakkan dataset
