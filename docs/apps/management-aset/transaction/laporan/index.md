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

Daftar lengkapnya didaftarkan di `src/ModuleServiceProvider.php` pada `ReportRegistry`. Katalog cetak Core dibaca dari definisi yang sama lewat `PenyediaLaporan::catalog()` saat `app:register-manifest`, jadi tidak ada daftar kedua yang harus disejalankan.

| Kode manifest | Kelas | Parameter | Layout bawaan | Hak data |
| --- | --- | --- | --- | --- |
| `management-aset.work-order` | `src/Reporting/Definitions/WorkOrderDocument.php` | `id` work order | Word: header, tabel `baris`, tabel `checklist` | `pemeliharaan-aset.read` |
| `management-aset.daftar-work-order` | `src/Reporting/Definitions/WorkOrderList.php` | `status`, `dari`, `sampai` | Excel: satu lembar, satu baris per work order | `pemeliharaan-aset.read` |
| `management-aset.laporan-monitoring-aset` | `src/Reporting/Definitions/AssetMonitoringReport.php` | periode, filter aset, kondisi, lokasi, penanggung jawab, unit | Excel: satu baris per aset pada monitoring yang sudah selesai | `monitoring-aset.read` |

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

**Uang, angka, persen, tanggal, bulan, dan waktu diformat Core, bukan di dataset.** Placeholder seperti itu menyatakan `type` (`money`, `number`, `percent`, `date`, `month`, `datetime`) di `fields()` dan dikirim mentah dari `data()`; Core yang menulisnya sebagai teks di Word dan layar pratinjau, dan sebagai angka atau tanggal asli di Excel. Jangan menulis `number_format` rupiah atau nama bulan di definisi laporan. Waktu cetak (`dicetak_pada`), waktu work order dibuat, dan waktu mulai dan selesai aktualnya dikirim dalam UTC bertipe `datetime`, lalu dicetak menurut zona pengguna beserta nama zonanya, misalnya "01/09/2026 00:30 WIB". "Hari ini" di definisi — periode bawaan, nama berkas, batas hari filter tanggal — memakai `ReportContext::now()`, yaitu jam menurut zona pengguna. Jadwal work order (`diharapkan_*`, `dijadwalkan_*`) diketik pengguna tanpa zona dan dicetak apa adanya. Nilai lain — status berlabel Indonesia dan tanggal pada laporan lama — tetap diformat di dataset, supaya semua layout satu laporan menampilkannya dengan cara yang sama.

**Parameter divalidasi di app.** Manifest hanya menyebut nama parameter; aturannya (`ulid`, `date_format`, `in:`) ada di `parameterRules()` tiap definisi, karena app yang tahu artinya.

**Filter master aset boleh banyak pilihan (K-28).** `AssetReportFilters` menerima daftar id untuk group, kelompok harta fiskal, jenis, lokasi, dan kondisi (`group_aset_id[]=...`). Beberapa pilihan pada satu filter berarti *atau*, filter yang berbeda tetap *dan*, seperti `A|B` pada satu field di BC. Kepala laporan (`filter_group`, `filter_lokasi`, `filter_kondisi`, dan seterusnya) menulis nama setiap pilihan dipisah koma, dan "Tidak ditemukan" untuk id yang tidak ada pada tenant itu. Satu nilai tanpa daftar tetap diterima sebagai daftar berisi satu (`PenyediaLaporan` membungkusnya sebelum validasi), supaya opsi terakhir dan tautan lama tidak rusak. Nama parameter di katalog Core adalah kunci aturannya tanpa aturan per butir (`group_aset_id.*`). Aset dan buku penyusutan tetap satu pilihan: dua buku sekaligus menjumlahkan aset yang sama dua kali.

**Filter terakhir dan preset milik Core.** Halaman laporan membuka filternya dengan pilihan terakhir pengguna dan mencatat pilihan setiap kali pratinjau berhasil; preset bernama dipilih dan disimpan di baris filter (`ui/laporan/_shared/ReportPresets.tsx`), dengan pilihan tanggal relatif seperti "Bulan ini". Pemegang izin preset bersama melihat pilihan **Bagikan filter ini ke semua pengguna di perusahaan ini** saat menyimpan, dan dapat mengarsipkan preset bersama. Keduanya disimpan Core lewat `coreApi()`; module tidak punya tabelnya. Aturannya di [Dokumen cetak, layout, dan ekspor](/dev/23-document-rendering#opsi-terakhir-dan-preset-laporan).

**Register aset dapat diekspor utuh dari layarnya (K-27).** Tombol **Ekspor ke Excel** di Inventarisasi aset mengirim kolom yang tampil, urutan tabel, dan pencarian ke Core; Core meminta barisnya ke `src/Reporting/Lists/AssetRegisterList.php`, yang memakai permission `management-aset.aset.read` dan `OrganizationScope` sama seperti `GET /aset`, dan membaca per seribu baris. Kolomnya menulis nama group, jenis, dan lokasi serta label status, dan nilai perolehan sebagai angka uang.

**Kop dan footer datang dari Core, bukan dari dataset.** Kedua layout bawaan memakai blok kop tiga kolom dengan placeholder `${kop.*}` yang diisi Core dari Identitas cetak legal entity (atau operating unit) yang mencetak, dengan alamat dan kontak dari buku alamat organisasi. Dataset app tidak memuat nama perusahaan, alamat, atau logo; menambahkannya akan menggandakan sumber kebenaran.

**Layout bawaan dibangkitkan dari kode.** `php artisan management-aset:build-builtin-layouts` menulis ulang berkas di `resources/laporan/` dari pembangunnya di `src/Reporting/Layouts/Builtin/`, satu berkas per laporan. Perintah itu didaftarkan penyedia layanan modul; tanpa pendaftaran itu ia tidak ada sama sekali, karena kerangka lama yang menemukannya dengan memindai foldernya sendiri sudah dibuang. Perubahan template terbaca di review sebagai perubahan kode, dan hasilnya sama di mesin siapa pun; berkas hasilnya tetap di-commit karena runtime membaca berkas.

**Satu laporan, satu berkas pembangun.** Pembangun ditemukan perintah dari foldernya, seperti rute di `routes/api/`, jadi dua orang yang mengerjakan dua laporan berbeda tidak menyunting berkas yang sama. Sebelumnya semua layout ditulis di satu kelas perintah, dan enam PR laporan aset pertama saling konflik di sana. Kop, kepala tabel, dan cara menyimpan dipakai bersama lewat `BuiltinLayoutBuilder`. Sebelum menulis apa pun, perintah mencocokkan pembangun dengan definisi laporan dan menolak keduanya bila tidak berpasangan: layout yang dinyatakan definisi tanpa pembangun tidak pernah dibangun, dan pembangun tanpa definisi menulis berkas yang tidak pernah dibaca.

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
| `src/Reporting/Layouts/Builtin/` | Pembangun layout bawaan, satu berkas per laporan |
| `src/Reporting/Layouts/BuiltinLayoutBuilder.php` | Induk pembangun: kop, kepala tabel, penyimpanan |
| `src/Console/Commands/BuildBuiltinLayouts.php` | Perintah yang menjalankan semua pembangun |
| `resources/laporan/` | Layout bawaan per kode laporan |
| `tests/Feature/PenyediaLaporanTest.php` | Definisi, layout bawaan, dataset dengan permission dan scope, filter daftar |
| `src/Reporting/AssetReportFilters.php` | Filter aset bersama: aturan, penerapan pada query, dan nama di kepala laporan |
| `src/Reporting/Lists/AssetRegisterList.php` | Register aset sebagai daftar yang dapat diekspor Core |
| `tests/Feature/AssetRegisterListTest.php` | Baris ekspor register aset mengikuti hak, cakupan unit kerja, dan pencarian |
| `ui/print.ts` | `requestPrint()` mengirim `CustomEvent('coreerp:print')` ke shell |
| `ui/listExport.ts` | `requestListExport()` mengirim `CustomEvent('coreerp:list-export')` ke shell |
| `ui/laporan/_shared/` | Baris filter, filter bersama, preset, dan data pratinjau halaman laporan |

Semuanya relatif terhadap `modules/apperp/management-aset/`.

Tombol **Cetak** ada di halaman rincian work order dan **Ekspor daftar** di daftarnya; keduanya hanya mengirim kode laporan dan parameter ke shell, lalu shell membuka dialog cetak milik Core.

## Menambah laporan baru

1. Tulis kelas yang mengimplementasikan `ReportDefinition` di `src/Reporting/Definitions/`. Dataset memakai `OrganizationScope` seperti endpoint detailnya.
2. Tulis pembangun layout bawaannya sebagai satu kelas baru di `src/Reporting/Layouts/Builtin/` (turunan `BuiltinLayoutBuilder`), jalankan `php artisan management-aset:build-builtin-layouts`, commit berkasnya. Tidak ada daftar yang perlu disunting.
3. Daftarkan di `ModuleServiceProvider` pada `ReportRegistry`. Tidak ada blok manifest; jalankan ulang `app:register-manifest management-aset` supaya laporannya masuk katalog cetak.
4. Tambahkan tombol cetak pada halaman record-nya dengan `requestPrint()`.
5. Tambahkan test di `PenyediaLaporanTest` untuk placeholder utama dan penolakan di luar scope, lalu perbarui halaman ini.

`PenyediaLaporan` tidak berubah: ia generik dan menerima kode baru apa adanya.

## Halaman terkait

- [Dokumen cetak, layout, dan ekspor](/dev/23-document-rendering) — aturan platform dan bagian yang dimiliki Core
- [Pemeliharaan aset](/apps/management-aset/transaction/pemeliharaan-aset/) — dokumen yang dicetak laporan pertama
- [Batas tenant dan organisasi](/apps/management-aset/arsitektur/batas-tenant-dan-organisasi) — scope yang ditegakkan dataset
