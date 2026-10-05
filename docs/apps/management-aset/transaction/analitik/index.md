# Dataset analitik

Management Aset menyatakan **dataset** supaya [engine analitik](/dev/35-analitik) Core dapat menghitung tabel aset menjadi dasbor: berapa aset per group, berapa nilai buku per unit, berapa jam downtime per bulan. Satu dataset adalah pernyataan dari module tentang satu tabel atau gabungan tabel — kolom mana yang boleh dikelompokkan, nilai mana yang sah dijumlah, siapa yang boleh membacanya, dan kolom mana yang menentukan unit kerja pemiliknya. Satu **baris** dalam dataset mewakili satu hal di dunia nyata; apa persisnya berbeda per dataset, dan kolom "Satu baris mewakili" di bawah menyebutnya.

Halaman ini untuk developer yang akan menambah atau mengubah dataset aset. Cara kerja engine, aturan keamanannya, dan cara menyatakan dataset secara umum ada di [engine analitik](/dev/35-analitik); yang ditulis di sini hanya bagian yang dimiliki module aset.

Module aset tidak membuat tabel, migration, atau permission baru untuk analitik. Dataset memakai permission baca yang sama dengan layar daftarnya dan kebijakan data yang sama. Menambah dataset karena itu tidak mengubah susunan role tenant mana pun.

## Konsep yang mudah tertukar

**Dataset dan laporan.** [Laporan dan ekspor](/apps/management-aset/transaction/laporan/) menyerahkan baris untuk dicetak atau diekspor ke Excel: satu work order, atau satu daftar dengan kolom tetap. Dataset menjawab "berapa" atas tabel yang sama, dengan pengelompokan yang dipilih pengguna. Keduanya membaca tabel yang sama dengan aturan yang sama dari layar daftarnya, tetapi tidak saling menggantikan. Laporan penyusutan tidak sama dengan dataset penyusutan; kolomnya, jalur kodenya, dan penjaganya terpisah.

**Nilai buku dan riwayat penyusutan.** `book-values` adalah potret **keadaan sekarang**: satu baris per pasangan aset dan buku. Tabel buku aset sudah menyimpan saldo terakhirnya — finalisasi, pembalikan, dan penyesuaian nilai yang memeliharanya — jadi tidak ada penentuan "baris terakhir" yang dihitung engine. `depreciation-entries` adalah **riwayat**: satu baris per periode, termasuk pembaliknya. Pertanyaan "berapa nilai buku sekarang" dijawab yang pertama; "berapa disusutkan tiap bulan" yang kedua.

**Tiga unit yang berbeda.** Aset punya unit penanggung jawab (`responsible_org_unit_id`), unit dimensi keuangan (`financial_dimension_org_unit_id`), dan — pada periode penyusutan — unit pengguna (`usage_org_unit_id`). Ketiganya menunjuk unit kerja, dan hanya sebagian yang menjadi kolom kebijakan dataset. Dataset register aset tetap menawarkan unit dimensi keuangan sebagai dimensi untuk dikelompokkan, tetapi **kebijakan datanya tidak pernah memakainya**; lihat [kolom kebijakan](#kolom-kebijakan-dan-kenapa-kolom-itu).

**Satu dataset, satu permission.** Penjualan dan pemusnahan aset tersimpan di satu tabel yang dibedakan `jenis_dokumen`, tetapi layar daftarnya dijaga permission berbeda (`penjualan-aset.read` dan `pemusnahan-aset.read`). Dataset gabungan akan memperlihatkan pemusnahan kepada pengguna yang hanya boleh membaca penjualan, jadi masing-masing menjadi dataset sendiri.

**Jumlah baris dan jumlah dokumen.** Dataset yang berbasis baris dokumen (penerimaan, penyesuaian nilai, reklasifikasi, pemeriksaan fisik) punya measure `count` yang menghitung baris dan measure `CountDistinct` yang menghitung dokumen atau aset. Satu dokumen dengan tiga baris dihitung tiga oleh `count` dan satu oleh `document_count`.

## Dataset yang ada

Sumber daftar yang berlaku ada di `ModuleServiceProvider::boot()` pada blok pendaftaran `Datasets`, dan `php artisan analytics:datasets` menampilkan yang terbaca engine. Tabel di bawah peta untuk pembaca; bila berbeda dengan pendaftaran itu, pendaftarannya yang benar. Semua kode dataset berawalan `management-aset.` dan semua permission berawalan `management-aset.`.

| Kode | Satu baris mewakili | Permission | Measure |
| --- | --- | --- | --- |
| `asset-register` | Satu aset tercatat, termasuk komponen | `aset.read` | `count`, `disposed`, `decommissioned`; `acquisition_value` dan `average_acquisition_value` (uang) |
| `asset-receipts` | Satu **baris** dokumen penerimaan | `penerimaan-aset.read` | `count`, `receipt_count` (dokumen), `quantity`; `receipt_value` dan `completed_value` (uang, belum termasuk PPN) |
| `depreciation-entries` | Satu periode penyusutan sebuah buku aset, termasuk pembaliknya | `penyusutan.read` | `count`, `asset_count`; `amount` dan `final_amount` (uang) |
| `book-values` | Satu pasangan aset dan buku penyusutan | `penyusutan.read` | `count`, `asset_count`; `acquisition_value`, `accumulated_depreciation`, `net_book_value`, `active_net_book_value` (uang) |
| `work-orders` | Satu work order | `pemeliharaan-aset.read` | `count`, `completed`, `cancelled` |
| `maintenance-requests` | Satu permintaan pemeliharaan | `permintaan-pemeliharaan.read` | `count`, `asset_count`, `accepted`, `rejected` |
| `downtime` | Satu periode aset berhenti dipakai | `downtime-aset.read` | `count`, `asset_count`, `open_count`; `duration_hours` dan `kpi_duration_hours` (jam) |
| `asset-sales` | Satu dokumen penjualan aset | `penjualan-aset.read` | `count`, `asset_count`, `posted`; `acquisition_value`, `proceeds`, `posted_proceeds` (uang) |
| `asset-scraps` | Satu dokumen pemusnahan aset | `pemusnahan-aset.read` | `count`, `asset_count`, `posted`; `acquisition_value` (uang) |
| `value-adjustments` | Satu aset pada satu dokumen penyesuaian nilai | `penyesuaian-nilai-aset.read` | `count`, `document_count`; `amount`, `net_effect`, `write_down_amount`, `appreciation_amount`, `posted_net_effect` (uang) |
| `reclassifications` | Satu aset asal pada satu dokumen reklasifikasi | `reklasifikasi-aset.read` | `count`, `document_count`, `split_count`; `moved_value` (uang) |
| `insurance-policies` | Satu polis asuransi | `polis-asuransi.read` | `count`, `blocked` |
| `warranties` | Satu garansi aset | `garansi-aset.read` | `count`, `asset_count`, `full_coverage` |
| `physical-checks` | Satu aset pada satu pemeriksaan fisik (monitoring) | `monitoring-aset.read` | `count`, `document_count`, `asset_count`, `mismatch`, `absent`; `acquisition_value`, `accumulated_depreciation`, `book_value` (uang) |

Kelas dataset ada di `modules/apperp/management-aset/src/Analytics/`, satu berkas per dataset; dataset penjualan dan pemusnahan berbagi kelas dasar `DisposalDataset`. Field datang dari dua jalur: `fieldsFromModel()` mengambil katalog filter tambahan K-30 model (register aset dan work order), dan `field()` menyatakannya satu per satu untuk dataset yang bersumber query.

Beberapa kebiasaan yang berlaku di semua dataset ini:

- **Measure bersaringan menjawab "berapa yang sudah …" tanpa dataset tambahan.** Measure berikut membawa saringan tetap pada satu field (`where` pada `measure()`), jadi hitungan atau jumlahnya hanya memuat baris yang cocok, dengan pengelompokan yang sama dengan measure biasanya: `disposed` dan `decommissioned` (status aset), `completed_value` (penerimaan berstatus selesai), `final_amount` (periode penyusutan final), `active_net_book_value` (buku aktif), `completed` dan `cancelled` (work order), `accepted` dan `rejected` (permintaan pemeliharaan), `open_count` dan `kpi_duration_hours` (downtime yang masih berjalan dan yang masuk KPI), `posted` dan `posted_proceeds` (dokumen pelepasan yang sudah diposting), `write_down_amount`, `appreciation_amount`, dan `posted_net_effect` (penyesuaian nilai), `split_count` (baris pecah aset), `blocked` (polis diblokir), `full_coverage` (garansi penuh), serta `mismatch` dan `absent` (temuan pemeriksaan fisik). Saringannya memakai konstanta status milik module (misalnya `StatusAset::DILEPAS`), bukan teks yang diketik ulang.
- **Uang selalu bersama mata uangnya.** Setiap measure uang menyebut kolom `currency_code`, dan engine tidak pernah menjumlahkan lintas mata uang: aset IDR dan USD tampil sebagai dua baris. Mata uang di tabel yang tidak menyimpannya (periode penyusutan, dokumen pelepasan, baris penyesuaian) diambil dari asetnya, dan itu salah satu alasan dataset-nya bersumber query.
- **Dokumen yang masih draf ikut terhitung** kecuali disaring menurut status, atau memakai measure bersaringan seperti `completed_value`, `final_amount`, dan `posted`. Nilai yang baru dibekukan saat dokumen diselesaikan (nilai buku di pemeriksaan fisik, nilai yang dipindah di reklasifikasi) kosong selama draf, sehingga ukuran uangnya hanya bermakna untuk dokumen yang sudah selesai atau diposting.
- **Aset yang diarsipkan tetap punya riwayat.** Dataset yang bergabung dengan aset menyaring arsip pada tabel dasar dan header dokumennya, tidak pada asetnya: aset yang sudah dilepas berakhir diarsipkan, dan dokumen pelepasannya tidak boleh ikut hilang.

## Kolom kebijakan, dan kenapa kolom itu

Semua dataset memakai kebijakan data `management-aset.asset-responsibility`. **Kode kebijakan itu memang dieja `asset`, dan tidak boleh diganti** — kode itu tercatat pada hibah lingkup milik tenant, jadi mengganti ejaannya membuat pengguna kehilangan akses ke asetnya sendiri tanpa satu galat pun yang terlihat.

Kolom yang menegakkan kebijakan bukan hasil menebak dari nama kolom. Untuk setiap dataset, kolomnya dibaca dari **controller daftar resource-nya**: cara ia memanggil `OrganizationScope` menentukan kolom yang dipakai dataset, sehingga angka di dasbor tidak lebih luas dan tidak lebih sempit daripada daftar yang dapat dibuka pengguna yang sama.

| Dataset | Controller daftar yang dibaca | Pemanggilan `OrganizationScope` | Kolom kebijakan dataset |
| --- | --- | --- | --- |
| `asset-register` | `AsetController::index` | `asetQuery()` | `legal_entity_id` dan `responsible_org_unit_id` aset |
| `asset-receipts` | `PenerimaanAsetController::index` | `query()` pada header | Header: `legal_entity_id`, `responsible_org_unit_id` |
| `depreciation-entries` | `DepreciationController::index` | `query()` pada periode | Periode: `legal_entity_id`, **`usage_org_unit_id`** |
| `book-values` | `DepreciationController::books` | `asetQuery()` | Aset: `legal_entity_id`, `responsible_org_unit_id` |
| `work-orders` | `PemeliharaanAsetController::index` | `query()` pada header | Header work order: `legal_entity_id`, `responsible_org_unit_id` |
| `maintenance-requests` | `MaintenanceRequestController::index` | `query()` | Permintaan itu sendiri: `legal_entity_id`, `responsible_org_unit_id` |
| `downtime` | `AssetDowntimeController::index` | `asetQuery()` | Aset: `legal_entity_id`, `responsible_org_unit_id` |
| `asset-sales`, `asset-scraps` | `DokumenSiklusAsetController::index` | `query()` pada dokumen | Dokumen: `legal_entity_id`, `responsible_org_unit_id` |
| `value-adjustments` | `AssetValueAdjustmentController::index` | `query()` pada header | Header: `legal_entity_id`, `responsible_org_unit_id` |
| `reclassifications` | `AssetReclassificationController::index` | `query()` pada header | Header: `legal_entity_id`, `responsible_org_unit_id` |
| `insurance-policies` | `InsurancePolicyController::index` | `legalEntityQuery()` | **`legal_entity_id` saja** |
| `warranties` | `AssetWarrantyController::index` | `asetQuery()` | Aset: `legal_entity_id`, `responsible_org_unit_id` |
| `physical-checks` | `AssetMonitoringController::index` | `query()` pada header | Header: `legal_entity_id`, `responsible_org_unit_id` (boleh kosong) |

Alasan di balik pilihan yang tidak seragam:

- **Register aset memakai unit penanggung jawab, bukan unit dimensi keuangan.** Keduanya menunjuk unit kerja, tetapi daftar aset menyaring menurut unit penanggung jawab. Salah pilih kolom berarti kepala unit melihat aset unit lain. Test paritas sengaja membuat unit dimensi keuangan berlawanan dengan unit penanggung jawab pada sebagian aset, supaya kolom yang salah terlihat sebagai baris yang salah.
- **Riwayat penyusutan memakai `usage_org_unit_id` milik periode.** Dua kolom ini disalin ke baris periode justru supaya penyaringan tidak menelusuri aset, dan daftarnya mengikutinya. Unit pengguna aset pada periode itu bisa berbeda dari unit penanggung jawab asetnya hari ini.
- **Nilai buku tidak punya kolom unit sendiri.** Tabel buku aset tidak menyimpan unit, jadi dataset bergabung dengan asetnya dan memakai kolom asetnya, persis seperti daftarnya. Hal yang sama berlaku untuk downtime dan garansi, yang menempel pada aset.
- **Dokumen berdetail mengikuti headernya.** Baris penerimaan, penyesuaian nilai, reklasifikasi, dan pemeriksaan fisik tidak punya unit; mereka mengikuti header dokumen, dan header yang diarsipkan tidak ikut, persis seperti daftarnya. Work order dan permintaan pemeliharaan membawa unit sendiri, jadi tidak perlu join. Permintaan atas lokasi bahkan tidak punya aset.
- **Polis asuransi hanya mencocokkan legal entity.** Polis milik entitas legal, bukan satu unit, jadi cukup hibah pada entitas legalnya, unit mana pun. Kebijakan yang mencocokkan unit akan menyembunyikan polis dari pengguna yang layar polisnya menampilkannya.
- **Pemeriksaan fisik tanpa unit hanya terlihat bagi yang menjangkau seluruh organisasi.** Unit di header boleh kosong; tanpa unit tidak ada hibah yang dapat dicocokkan. Layar daftarnya begitu, dan dataset tidak membuat pengecualian.

Paritas ini dijaga test: setiap `<Nama>DatasetTest` memberi himpunan baris dataset untuk empat pengguna (seluruh organisasi, hibah unit A, hibah unit B, dan tanpa hibah) dan menuntutnya **sama** dengan himpunan baris endpoint daftar module. Merusak kolom kebijakan salah satu dataset membuat test itu merah; periksa itu sebelum mempercayainya.

## Field yang sengaja tidak ada

| Yang tidak masuk | Dataset | Kenapa |
| --- | --- | --- |
| `keterangan`, `serial_number`, `model_number` | `asset-register` | Teks bebas berkardinalitas tinggi, tidak berguna sebagai pengelompok, dan sering memuat data pribadi |
| `keterangan`, `penanggung_jawab_user_id` | `work-orders` | Teks bebas, dan penanggung jawab adalah pengenal pengguna |
| Deskripsi, alasan penolakan, pengguna yang memutuskan | `maintenance-requests` | Teks bebas dan data pribadi |
| Nama polis, nomor polis | `insurance-policies` | Pengenal berkardinalitas tinggi, bukan pengelompok |

Field yang memuat data pribadi tidak tersedia bagi pengguna tanpa hak data pribadi di analitik dan tidak pernah ditawarkan di katalog. Memasukkan kolom seperti itu ke dataset adalah keputusan yang ditinjau, bukan kelonggaran.

## Yang belum ada

- **Laba atau rugi pelepasan.** Tidak tersimpan di tabel mana pun: ia dihitung saat pratinjau dan saat posting, lalu hanya ikut ke jurnal. Dataset penjualan hanya punya hasil penjualan dan nilai perolehan aset yang dilepas.
- **Measure uang untuk polis asuransi.** Tabel polis tidak menyimpan mata uang, dan uang tanpa mata uang tidak boleh dijumlah. Premi dan nilai pertanggungan menunggu kolom mata uang pada polis.
- **KPI pemeliharaan** (availability, MTBF, MTTR). Dihitung `MaintenanceKpi` dari beberapa tabel dengan logika jam yang tidak dapat dinyatakan sebagai agregat sederhana; bentuk dataset terhitung untuknya belum diputuskan.
- **Jam kerja work order.** Jam ada di baris pekerjaan, bukan header; dataset baris pekerjaan belum dibuat.
- **Lama downtime catatan yang masih terbuka.** Dataset hanya menghitung lama untuk catatan yang sudah ditutup, karena nilai yang bergantung pada jam pembacaan tidak cocok untuk hasil yang disimpan di cache. Catatan terbuka dapat dihitung terpisah lewat field "Masih berhenti".
- **Kontrak servis dan pertanggungan per aset** belum dijadikan dataset.

## Menambah atau mengubah dataset aset

1. Baca controller daftar resource-nya, lalu tentukan permission dan kolom kebijakannya dari situ — jangan dari nama kolom.
2. Tulis kelasnya di `src/Analytics/`, daftarkan di `ModuleServiceProvider::boot()`, dan tulis `<Nama>DatasetTest` dengan trait `ProbesAssetDatasets` dan `ChecksMoneyPerCurrency`. Trait itu ada di `tests/Concerns/`.
3. Susun data awal test supaya kolom kebijakan yang salah terlihat sebagai baris yang salah, lalu rusak kolom kebijakannya sekali dan pastikan test paritas merah. Untuk dataset yang punya field waktu, pakai `ChecksTimeZoneBuckets` (satu baris di batas bulan harus jatuh di bulan yang benar bagi UTC, WIB, WITA, dan WIT; kolom `date` tidak pernah bergeser, kolom `timestamp` berisi UTC), dan beri setiap measure bersaringan data awal yang beragam supaya saringannya terbukti memilih baris yang benar.
4. Jalankan `php artisan analytics:datasets` dan `tests/Feature/Boundary` dari `apps/core`.
5. Mengganti atau menghapus kunci field atau measure butuh `version(n+1)`; widget tenant menyimpan kuncinya. Aturannya di [engine analitik](/dev/35-analitik#menyatakan-dataset-di-module).

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `modules/apperp/management-aset/src/Analytics/*Dataset.php` | Satu kelas per dataset, dan `SourceQuery` untuk query sumber |
| `modules/apperp/management-aset/src/ModuleServiceProvider.php` | Pendaftaran dataset di `boot()` |
| `modules/apperp/management-aset/tests/Feature/Analytics/` | Satu test per dataset |
| `modules/apperp/management-aset/tests/Concerns/ProbesAssetDatasets.php`, `ChecksMoneyPerCurrency.php`, `ChecksTimeZoneBuckets.php` | Isolasi tenant, paritas kebijakan data, penolakan tanpa permission, uang per mata uang, dan pengelompokan waktu menurut zona |
| `modules/apperp/management-aset/src/Support/OrganizationScope.php` | Aturan lingkup yang ditiru kebijakan data dataset |

## Halaman terkait

- [Engine analitik](/dev/35-analitik) — aturan engine, keamanan baca, dan cara menyatakan dataset
- [Laporan dan ekspor](/apps/management-aset/transaction/laporan/) — dataset laporan untuk cetak dan Excel
- [Batas tenant dan organisasi](/apps/management-aset/arsitektur/batas-tenant-dan-organisasi) — dua lapis penyaringan dan `OrganizationScope`
- [Register aset](/apps/management-aset/transaction/register-aset/) — sumber dataset `asset-register`
- [Proses penyusutan](/apps/management-aset/transaction/penyusutan/) — buku dan periode di balik `book-values` dan `depreciation-entries`
