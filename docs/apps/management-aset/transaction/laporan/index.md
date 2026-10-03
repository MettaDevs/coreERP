# Laporan dan ekspor

Halaman ini untuk developer. Aturan platformnya ada di [Dokumen cetak, layout, dan ekspor](/dev/23-document-rendering); halaman ini menjelaskan bagian yang dimiliki Management Aset.

Laporan adalah dokumen yang dibuat dari data app: work order yang dibawa teknisi ke lapangan, atau daftar work order untuk diolah di Excel. Yang dimiliki app ini hanya **dataset** dan **layout bawaan**; layout unggahan tenant, antrean ekspor, render, dialog cetak, dan riwayatnya milik Core dan Shell.

## Konsep yang mudah tertukar

**Laporan bukan layout.** Laporan adalah dataset yang ditulis developer: kolom apa yang tersedia, dari tabel mana, dengan hak apa. Layout adalah berkas Word atau Excel yang menentukan tampilannya, dikelola admin tenant di Core. Kalau customer minta bentuk cetakan berbeda, jawabannya layout baru di halaman Layout laporan Core, bukan kode baru; kode baru hanya dibutuhkan bila ada kolom yang belum ada di dataset.

**Laporan dibaca Core, bukan diminta browser.** Mesin laporan Core memanggil modul ini **di dalam
proses yang sama**, lewat kontrak `ModuleReportProvider`. Tidak ada endpoint HTTP, tidak ada token,
dan tidak ada alamat yang harus benar sebelum sebuah laporan bisa dicetak.

**Konteks dibawa sebagai argumen, bukan dibaca dari permintaan.** Ekspor berjalan di worker antrean,
tempat tidak ada `Request` maupun sesi. Konteks yang dulu ikut sebagai token kini ikut sebagai
parameter — satu-satunya alternatifnya adalah keadaan global yang benar pada permintaan biasa dan
kosong pada worker, persis kegagalan yang paling sulit ditemukan.

## Laporan yang ada

Sumber daftarnya satu: pendaftaran `ReportRegistry` di `src/ModuleServiceProvider.php`. Katalog cetak Core dibaca dari definisi yang sama lewat `PenyediaLaporan::catalog()` saat `app:register-manifest`, jadi tidak ada daftar kedua yang harus disejalankan. Tabel di bawah hanya peta untuk pembaca; bila ia berbeda dengan pendaftaran itu, pendaftaran yang benar.

| Kode manifest | Kelas | Parameter | Layout bawaan | Hak data |
| --- | --- | --- | --- | --- |
| `management-aset.work-order` | `WorkOrderDocument` | `id` work order | Word: header, tabel `baris`, tabel `checklist` | `pemeliharaan-aset.read` |
| `management-aset.daftar-work-order` | `WorkOrderList` | `status`, `dari`, `sampai` | Excel: satu baris per work order | `pemeliharaan-aset.read` |
| `management-aset.laporan-pemeliharaan-aset` | `AssetMaintenanceReport` | `dari`, `sampai`, filter aset bersama tanpa kondisi, lokasi pekerjaan, status, tingkat layanan, teknisi, unit | Excel: satu baris per aset yang dikerjakan pada satu work order | `pemeliharaan-aset.read` |
| `management-aset.berita-acara-serah-terima` | `BeritaAcaraSerahTerima` | `id` mutasi | Word: berita acara satu dokumen mutasi yang selesai | `mutasi-aset.read` |
| `management-aset.daftar-mutasi-aset` | `DaftarMutasiAset` | group, kelompok harta fiskal, jenis, aset, `status`, `dari`, `sampai` | Excel: satu baris per aset yang berpindah | `mutasi-aset.read` |
| `management-aset.laporan-penyusutan-aset` | `AssetDepreciationReport` | `periode`, filter aset bersama, buku | Excel: satu baris per buku aset | `penyusutan.read` |
| `management-aset.laporan-pemusnahan-aset` | `AssetDisposalScrapReport` | `dari`, `sampai`, filter aset bersama, buku | Excel: satu baris per aset yang dimusnahkan | `pemusnahan-aset.read` |
| `management-aset.laporan-penjualan-aset` | `AssetDisposalSaleReport` | `dari`, `sampai`, filter aset bersama, buku | Excel: satu baris per aset yang dijual | `penjualan-aset.read` |
| `management-aset.laporan-monitoring-aset` | `AssetMonitoringReport` | periode, filter aset, kondisi, lokasi, penanggung jawab, unit | Excel: satu baris per aset pada monitoring yang sudah selesai | `monitoring-aset.read` |

Kelasnya di `src/Reporting/Definitions/`. Kode di sisi modul adalah kode manifest tanpa awalan ID modul (`work-order`, `daftar-work-order`).

**Kode yang dipakai layar wajib kode yang terdaftar.** Halaman di `ui/laporan/` memanggil pratinjau lewat `useReportData('<kode>')` dan tombol Cetak lewat `reportCode`, dan tombol cetak di layar transaksi lewat `requestPrint({ report })`. Kode yang tidak terdaftar tidak gagal saat build: pratinjaunya menjawab "laporan tidak dikenal" dan dialog cetaknya 404. Menu dan nama halaman boleh berbeda dari kode laporannya — menu **Laporan mutasi aset** membuka halaman yang membaca `daftar-mutasi-aset`, laporan yang sama dengan tombol ekspor di daftar mutasi. `tests/Feature/ReportScreenCodeTest.php` membaca kode-kode itu dari berkas layar dan menolak yang tidak terdaftar.

### Laporan pemeliharaan aset

Satu baris per baris pekerjaan work order, yaitu satu aset yang dirawat atau diperbaiki. Kolomnya mengikuti spesifikasi QA (halaman LAPORAN pada `docs/diagrams/drawio/DOKUMENTASI APLIKASI ASSET MANAGEMENT.drawio`): No. bukti, tanggal work order, kode aset, item aset, spesifikasi, satuan, jumlah, item checklist, analisa perbaikan, jenis pemeliharaan, unit organisasi, dan PIC. Ditambah jenis pekerjaan, lokasi, tingkat layanan, status, dan catatan, karena kolom-kolom itu juga dipakai sebagai filter.

- **Tanggal work order** adalah tanggal dibuat menurut zona pengguna, sama dengan `daftar-work-order`, dan filter periode memakai batas hari yang sama. Jadwal tidak dipakai: tersimpan tanpa zona dan boleh kosong.
- **Jenis pemeliharaan** adalah tipe work order (misalnya Rutin atau Korektif); **jenis pekerjaan** adalah master jenis pekerjaan di baris.
- **Item checklist** menggabungkan butir checklist baris itu menurut nomor urutnya, beserta nilai dan satuannya, atau "tidak berlaku". **Analisa perbaikan** menggabungkan sebab kerusakan dan tindakan perbaikan beserta keterangannya.
- **Lokasi** adalah lokasi yang disalin ke baris saat dibuat, yaitu tempat pekerjaan dikerjakan, bukan lokasi aset hari ini; filter lokasinya membaca kolom yang sama. **PIC** adalah teknisi yang ditugaskan pada baris, dan filter teknisi membaca kolom itu.
- **Satuan dan jumlah** selalu "Unit" dan 1: satu baris pekerjaan menangani satu aset utuh, dan aset tidak punya satuan ukur sendiri.
- Work order yang diarsipkan tidak ikut; semua status lainnya ikut kecuali disaring.
- **Tidak ada kolom biaya.** Padanan Business Central, report 5634 "Maintenance - Details", menampilkan nominal tiap entri pemeliharaan, tetapi work order di modul ini belum mencatat biaya bahan, jasa, maupun tagihan vendor, dan spesifikasi QA juga tidak memintanya. Kolomnya ditambahkan setelah sumber datanya ada.

## Laporan keuangan aset

Empat laporan padanan laporan aset tetap Business Central, semuanya Excel, satu baris per butir dengan baris total bila totalnya bermakna. Semuanya membaca catatan module ini saja; tidak ada yang membaca buku besar aplikasi finance. Menunya di **Laporan** pada `app.yaml`; test-nya `tests/Feature/AssetFinancialReportsTest.php`, di atas satu riwayat yang memuat perolehan, penyusutan, penurunan nilai, pindah group, pecah aset, dan penjualan.

| Kode | Padanan BC | Parameter | Hak data | Kelas |
| --- | --- | --- | --- | --- |
| `laporan-nilai-buku-aset` | Fixed Asset - Book Value 01/02 | `dari`, `sampai` (bawaan awal tahun sampai hari ini), filter aset, `buku_id` | `penyusutan.read` | `AssetBookValueReport` |
| `laporan-rekonsiliasi-aset-buku-besar` | Fixed Asset - G/L Analysis | `per_tanggal` (bawaan hari ini), filter aset | `penyusutan.read` | `AssetLedgerReconciliationReport` |
| `laporan-proyeksi-penyusutan-aset` | Fixed Asset - Projected Value | `dari`, `sampai` bulan (bawaan 12 bulan mulai bulan ini, paling panjang 60), filter aset, `buku_id` | `penyusutan.read` | `AssetDepreciationProjectionReport` |
| `laporan-perolehan-aset` | Fixed Asset Acquisition List | `dari`, `sampai` (bawaan awal tahun sampai hari ini), filter aset | `aset.read` | `AssetAcquisitionListReport` |

Tidak ada permission baru: ketiga laporan nilai memakai hak yang sama dengan laporan penyusutan, dan daftar perolehan hak yang sama dengan register aset. Tanpa pilihan buku, laporan nilai buku dan proyeksi membaca buku komersial saja (lapisan `current`), seperti laporan penyusutan; menjumlahkan buku fiskal bersama membuat totalnya dobel.

**Mutasi nilai buku.** Register tidak menyimpan buku besar per transaksi seperti FA Ledger Entry BC, jadi mutasinya disusun dari catatan yang membentuk saldo buku aset, masing-masing pada tanggalnya:

| Mutasi | Sumber | Tanggal |
| --- | --- | --- |
| Perolehan | harga perolehan buku − yang masuk lewat reklasifikasi + yang keluar (harga perolehan dasar); akumulasi saldo awal aset lama ikut sebagai penyusutan | tanggal perolehan aset |
| Penyusutan | periode **final** `aset_tr_penyusutan_aset`, termasuk baris pembalik yang negatif | akhir periode |
| Penurunan dan kenaikan nilai | baris dokumen penyesuaian nilai yang sudah diposting | tanggal dokumen |
| Reklasifikasi masuk dan keluar | `aset_tr_reklasifikasi_aset_buku`; pindah group tidak tampil karena saldo buku asetnya tidak berubah | tanggal reklasifikasi |
| Pelepasan | seluruh saldo buku yang ditutup — sesudah ditutup tidak ada yang mengubahnya lagi | `closed_on` |

Nilai buku akhir = nilai buku awal + perolehan − penyusutan − penurunan + kenaikan + reklasifikasi masuk − keluar − pelepasan, dan harga perolehan serta akumulasi punya persamaan yang sama. Pada hari ini saldo akhirnya sama dengan saldo buku aset di register; test-nya memeriksa keduanya. Buku tanpa saldo maupun mutasi dalam rentang, termasuk aset pecahan sebelum ia lahir, tidak tampil.

**Rekonsiliasi ke buku besar.** Satu baris per group aset dan akun neraca posting group: harga perolehan, akumulasi penyusutan, akumulasi penurunan nilai, dan kenaikan nilai, dengan saldo alaminya (debit untuk harga perolehan dan kenaikan nilai, kredit untuk akumulasi). Setiap mutasi di atas — hanya pada buku yang di-post ke finance (K-26) — dibawa satu posting, dan saldonya dipecah menurut keadaan posting itu di feed (`PostingFeed::status`):

| Kolom | Keadaan posting |
| --- | --- |
| Sudah dibukukan | `posted` — sudah ada ack dari aplikasi finance |
| Dicatat manual | `manual` — sebelum cutover, feed mati, atau ditandai manual |
| Menunggu | `pending` |
| Tertahan | `held` |
| Ditolak | `rejected` |
| Belum dikirim | belum ada posting: penyusutan final yang belum di-post, aset tanpa penerimaan, atau pecah di dalam satu group yang memang tidak dijurnal |

"Belum ada di buku besar" = saldo register − sudah dibukukan − dicatat manual. Posting tiap mutasi: perolehan dan saldo awalnya ke jurnal penerimaan (`AST-ACQ-`/`AST-OPB-`, koreksi nilai perolehan ikut keadaan jurnal itu), penyusutan ke `posted_posting_id` periodenya, penyesuaian nilai dan reklasifikasi ke `posting_id` dokumennya, pelepasan ke `AST-DSP-<id aset>`. Mutasi masuk ke group aset pada tanggalnya: aset yang pindah group masih di group asal pada tanggal reklasifikasi itu sendiri, karena penyusutan sampai tanggal itu harus final sebelum reklasifikasi diposting. Nomor dan nama akun dibaca dari posting group yang berlaku pada tanggal laporan.

Batasnya sengaja: module tidak membaca buku besar milik aplikasi finance — itu sistem lain. Yang dapat ia pastikan hanya bagian rantainya sendiri, yaitu mutasi register mana yang sudah terkirim dan dalam keadaan apa. Saldo buku besar dicocokkan di aplikasi finance dengan kolom "Sudah dibukukan" dan "Dicatat manual". Group tanpa buku yang di-post ke finance tidak ikut.

**Proyeksi penyusutan.** Memakai `DepreciationCalculator` yang sama dengan proposal penyusutan, termasuk perpindahan ke profil alternatif (dipindah dari controller ke `DepreciationCalculator::applyAlternativeProfile()` supaya tidak ada rumus kedua). Proyeksi berjalan dari keadaan buku sekarang: mulai periode sesudah periode final terakhir — usulan yang belum difinalkan ikut dihitung ulang — dan setiap periode mengurangi nilai buku yang dipakai periode berikutnya. Umur berjalan = `elapsed_periods_offset` + periode asli yang final, sama dengan proposal. Periode berakhir pada akhir bulan, kuartal, semester, atau tahun kalender menurut frekuensi profil. Buku yang tidak disusutkan, sudah ditutup, atau berprofil konsumsi tidak diproyeksikan, dan periode tanpa penyusutan tidak tampil.

**Daftar perolehan.** Aset dengan tanggal perolehan di rentang. Nilai perolehan = harga perolehan register sekarang + bagian yang sudah dipecah ke aset lain, yaitu nilai saat diperoleh. Cara perolehan dan dokumen asal dari penerimaan yang melahirkannya; aset yang dicatat langsung di register tertulis "Dicatat langsung di register". Aset pecahan tidak ikut: ia lahir dari reklasifikasi, bukan diperoleh.

**Laporan penyusutan ikut membaca pecah aset.** Akumulasi `laporan-penyusutan-aset` = akumulasi saldo awal + periode final + akumulasi yang masuk dikurangi yang keluar lewat pecah aset sampai akhir bulan, supaya buku aset pecahan dan aset asalnya tetap sama dengan register.

## Yang diminta Core

`PenyediaLaporan` mendaftarkan diri ke `ModuleReportProviders` sekali saat boot penyedia layanan modul.
Tanpa pendaftaran itu Core tidak tahu modul punya laporan, dan ia jatuh ke jalur HTTP lama — alamat
yang sudah tidak ada.

| Yang diminta | Guna |
| --- | --- |
| `definition()` | Placeholder (`fields`), nama parameter, dan layout bawaan |
| `defaultLayout()` | Berkas layout bawaan dari `resources/laporan/<kode>/<key>.<format>` |
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

**Filter tambahan pada kolom data item (K-30).** Padanan "+ Filter" di request page BC; aturannya di [Dokumen cetak, layout, dan ekspor](/dev/23-document-rendering#filter-tambahan-pada-kolom-data-item). Laporan berikut menyatakan data item lewat `dataItems()`, dan setiap layout bawaannya punya baris "Filter tambahan" (`${filter_tambahan}`) di kepala laporan:

| Laporan | Data item (tabel, alias di query, kolom bawaan) | Filter baris berarti |
| --- | --- | --- |
| `laporan-penyusutan-aset` | Aset (`aset_tr_aset`, `aset_tr_aset`, `kode`); Buku aset (`aset_tr_buku_aset`, `buku`) | buku aset yang dibaca |
| `laporan-pemusnahan-aset`, `laporan-penjualan-aset` | Dokumen pemusnahan/penjualan (`aset_tr_dokumen_siklus_aset`, tanpa alias, `kode`); Aset (`aset_tr_aset`, `aset`, `kode`) | satu dokumen satu aset, keduanya menyaring baris |
| `laporan-monitoring-aset` | Monitoring (`aset_tr_monitoring_aset`, `monitoring`, `kode`); Baris monitoring (`aset_tr_monitoring_aset_details`, tanpa alias) | hanya baris yang cocok tercetak |
| `daftar-mutasi-aset` | Mutasi (`aset_tr_mutasi_aset`, `mutasi`, `kode`); Baris mutasi (`aset_tr_mutasi_aset_details`, tanpa alias) | hanya baris yang cocok tercetak |
| `daftar-work-order` | Work order (`aset_tr_pemeliharaan_aset`, tanpa alias, `kode`); Baris pekerjaan (`aset_tr_pemeliharaan_aset_details`, tanpa alias) | work order tanpa baris yang cocok tidak ikut, jumlah baris dan jam dihitung dari baris yang cocok; tanpa filter baris, work order tanpa baris tetap tampil |
| `laporan-nilai-buku-aset`, `laporan-proyeksi-penyusutan-aset` | Aset (`aset_tr_aset`, `aset_tr_aset`, `kode`); Buku aset (`aset_tr_buku_aset`, `buku`) | buku aset yang dibaca |
| `laporan-perolehan-aset` | Aset (`aset_tr_aset`, `aset_tr_aset`, `kode`) | aset yang dibaca |
| `laporan-rekonsiliasi-aset-buku-besar` | Aset (`aset_tr_aset`, `aset_tr_aset`, `kode`) | aset yang mutasinya dihitung; menurut keadaan aset sekarang |
| `laporan-pemeliharaan-aset` | Work order (`aset_tr_pemeliharaan_aset`, `wo`, `kode`); Baris pekerjaan (`aset_tr_pemeliharaan_aset_details`, tanpa alias) | hanya baris yang cocok tercetak |

Nama tampilan kolom ditulis di model tabelnya (`FIELD_CAPTIONS`, `FIELD_OPTIONS`, `FIELD_LOOKUPS`, `FIELD_HIDDEN`), dan `tests/Feature/ReportFieldCatalogTest.php` menolak kolom yang belum diberi nama atau alasan disembunyikan. Jadwal work order (`diharapkan_*`, `dijadwalkan_*`) sengaja tidak ditawarkan: tersimpan tanpa zona, sedangkan filter tanggal-jam membacanya sebagai UTC. `berita-acara-serah-terima` dan `work-order` dicetak per satu dokumen yang dipilih, jadi tidak punya data item.

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
| `src/Reporting/PenyediaLaporan.php` | Pintu yang dipanggil Core; mengimplementasikan `ModuleReportProvider` |
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
| `tests/Feature/AssetFinancialReportsTest.php` | Laporan keuangan aset: mutasi nilai buku, rekonsiliasi, proyeksi, daftar perolehan |
| `tests/Feature/ReportScreenCodeTest.php` | Kode laporan yang dipakai layar terdaftar di `ReportRegistry` |
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
