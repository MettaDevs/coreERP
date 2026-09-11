# Pengujian

Halaman ini untuk developer. Isinya apa yang bisa dan **tidak bisa** dilihat tiap jenis pengujian di modul ini.

## Feature test

```bash
cd apps/control-plane && php artisan test
```

Satu perintah menjalankan test Core **dan** seluruh module. Suite `Module` pada
`apps/control-plane/phpunit.xml` menyapu `modules/*/*/tests`; tidak ada perintah kedua untuk modul
ini, dan tidak ada `artisan` di dalam folder modul untuk menjalankannya sendiri.

::: tip Yang berubah dan kenapa itu penting
Modul dulu punya suite sendiri di atas SQLite. Suite itu **tidak pernah membuktikan apa pun tentang
penguncian baris**: pada SQLite, `lockForUpdate`, `sharedLock`, dan `FOR UPDATE SKIP LOCKED`
dikompilasi menjadi string kosong. Sekarang test modul berjalan pada koneksi PostgreSQL yang sama
dengan test Core.

Satu hal yang **tidak pernah bisa diuji sebelumnya** kini bisa: bahwa daftar tidak memuat baris
tenant lain. Dulu tiap tenant punya databasenya sendiri, jadi kebocoran semacam itu tidak punya
tempat untuk terjadi di dalam test — dan karena itu tidak punya tempat untuk dibuktikan tidak
terjadi.
:::

### Di mana menambah test

| Berkas | Menjaga |
| --- | --- |
| `AssetRegisterTest` | Pembuatan aset, idempotency, kolom yang disalin dari group |
| `AssetLifecycleTest` | Perpindahan status hidup aset |
| `AssetLocationTest` | Lokasi dan pemetaan dimensi keuangan |
| `AssetAttributeTest` | Atribut per jenis aset dan penguncian tipe data |
| `AssetPlanningTest` | Rencana pengadaan dan penanda versinya |
| `ModelAsetTest` | Aturan kombinasi jenis, pabrikan, dan model |
| `MasterDataAsetTest` | Perilaku bersama semua master |
| `ProfilPenyusutanTest`, `DepreciationBookTest` | Profil dan buku |
| `DepreciationTest`, `DepreciationEndToEndTest` | Proposal, finalisasi, pembalikan |
| `DepreciationCalculatorTest` | Hitungan murni, tanpa HTTP |
| `DepreciationScaleTest` | Perilaku pada jumlah data besar |
| `MaintenanceSetupTest` | Setup maintenance dan penautannya |
| `WorkOrderTest`, `WorkOrderExecutionTest` | Dokumen work order dan pengisian checklist |
| `IndonesiaStarterProvisioningTest` | Penyiapan tenant, termasuk pengulangannya |
| `NumberSequenceFailureTest` | Perilaku saat penerbitan nomor ditolak |
| `PenyediaLaporanTest` | Definisi, layout bawaan, dan dataset laporan lewat kontrak |
| `PerkakasModuleTest` | Perkakas yang dipakai bersama test lain |

`tests/Concerns/BerinteraksiDenganKonteksCore` menyusun konteks modul untuk test. Pakai itu, jangan
menyusun konteks sendiri — kalau bentuknya berubah, satu tempat yang perlu disesuaikan.

Test lama yang mencetak JWT sendiri dan menandatanganinya dengan kunci yang ia pasang sendiri sudah
dibuang. Jalur itu tidak ada lagi, dan test yang menguji jalur yang tidak ada adalah test yang hijau
tanpa membuktikan apa pun.

### Yang dijaganya

- Induk lintas tenant tertolak.
- Daftar tidak pernah memuat baris tenant lain.
- Menyimpan atas nama tenant lain dibatalkan sisi tulis `MilikTenant`.
- Hak satu master tidak merembet ke master lain.
- Induk yang masih beranak tidak bisa diarsipkan.
- `kode` selalu berasal dari Core.
- Transisi status work order mengikuti grafiknya.
- Penyiapan data awal idempoten.

### Penjaga batas berjalan di perintah yang sama

`apps/control-plane/tests/Feature/Boundary/` memindai seluruh isi `modules/` dan menolak: tabel
tanpa awalan modul, tabel milik modul lain yang disentuh, model tenant tanpa `MilikTenant`,
namespace yang menyeberang, kerangka aplikasi Laravel di dalam folder modul, rute modul tanpa
middleware konteks, dan manifest yang susunannya tidak sah.

Ia bukan test milik modul ini, tetapi ia yang menangkap pelanggaran modul ini. Kalau salah satunya
merah setelah perubahan Anda, itu bukan test yang perlu dilonggarkan.

## Yang tidak bisa dilihat feature test

Ini bagian yang penting, dan mudah dilupakan.

Feature test **secara struktural** tidak bisa melihat:

- koneksi database habis,
- nomor ganda,
- batas tenant yang bocor hanya ketika permintaan saling menyela,
- kunci idempotency yang berlomba dengan dirinya sendiri,
- dan penggantian sekumpulan baris yang saling menimpa.

Bukan karena testnya kurang lengkap, melainkan karena ia menjalankan satu permintaan pada satu waktu. Menambah test feature tidak akan pernah menutup celah ini.

Contoh nyata: endpoint penautan maintenance menghapus lalu menyisipkan ulang tanpa mengunci baris pemiliknya. Dua penulis bisa menghasilkan gabungan dua daftar. Seluruh suite tetap hijau.

## Load test

Skenario dan oracle SQL-nya ada di `modules/apperp/management-aset/loadtest/`, karena permukaan
yang diuji milik modul ini. **Stack-nya bukan milik modul**: sejak 10 September 2026 hanya ada satu,
`apps/control-plane/loadtest/`, yang menjalankan runtime Core sungguhan dengan empat instance di
belakang nginx. Cara menjalankannya, hasil terukurnya, dan batas kejujurannya ada di `README.md`
folder itu.

| Skrip | Yang diuji | Status |
| --- | --- | --- |
| `master-data.js` | CRUD master, idempotency, batas tenant, eskalasi hak | diukur ulang pada runtime baru |
| `maintenance.js` | Setup maintenance, dan balapan penggantian kaitan | diukur ulang pada runtime baru |
| `depreciation.js` | Proposal dan finalisasi penyusutan beserta pengulangannya | **belum dipindah**; berhenti dengan galat bila dijalankan |
| `work-order.js` | Dokumen work order di bawah beban | **belum dipindah**; berhenti dengan galat bila dijalankan |

Tidak ada lagi tiruan Core. Nomor diterbitkan proses yang sama lewat `PenerbitNomor`, jadi oracle
nomor dibaca dari tabel terbitan Core: tiap `kode` yang tersimpan modul harus punya satu baris di
`number_sequence_issues` pada tenant dan reference yang benar.

::: warning Dua skenario belum diukur ulang
`depreciation.js` dan `work-order.js` masih memakai harness lama — berkas fixture berisi token
konteks dan header `Authorization: Bearer`, dua hal yang tidak ada lagi. Keduanya sengaja dibiarkan
gagal keras, bukan diperbaiki setengah jalan, supaya tidak ada skenario yang hijau tanpa menjalankan
apa pun. Permukaan penyusutan dan work order karena itu **belum terverifikasi di bawah beban** pada
runtime satu proses.
:::

### Profil

| Profil | Menjawab |
| --- | --- |
| `saturation` | Apakah tetap benar di bawah beban penuh |
| `latency` | Berapa lama pada tingkat yang masih memenuhi SLO |
| `link-race`, `attribute-race` | Apakah dua penulis yang berebut baris yang sama saling merusak |

### Skenario balapan harus benar-benar berebut

Menyebar pengguna merata ke seluruh tenant adalah bentuk yang benar untuk `saturation`, dan **salah** untuk balapan: dua penulis nyaris tidak pernah bertemu, hasilnya hijau, dan cacatnya lolos.

Karena itu profil balapan memusatkan: banyak pengguna, sedikit record, menulis nilai yang sengaja bertabrakan, lalu membaca balik dan memastikan hasilnya sama dengan salah satu nilai yang dikirim — bukan campuran keduanya.

### Permukaan baru butuh skenarionya sendiri

Modul yang skenarionya hanya mencakup master lama tetapi tidak yang baru berstatus **belum terverifikasi** untuk bagian yang berubah.

Periksa daftar resource di skrip terhadap rute yang ada. Nama yang kebetulan terdengar mirip bukan cakupan — `item-checklist-maintenance` adalah master lama, bukan setup maintenance yang baru.

## Gate sebelum menyebut modul selesai

Ini aturan platform, bukan pilihan modul. Rinciannya di [Load dan concurrency testing](/dev/20-load-and-concurrency-testing).

Yang harus nol, dan tidak bergantung pada kecepatan mesin:

- error 5xx aplikasi,
- `kode` atau `creation_key` ganda dalam satu tenant,
- baris anak yang induknya milik tenant lain,
- pembacaan atau penulisan yang melewati batas tenant,
- permission satu resource yang memberi akses ke resource sebelah,
- dua permintaan dengan `Idempotency-Key` sama menghasilkan dua record.

Diperiksa dengan SQL langsung ke database setelah run, **bukan** lewat API. API adalah yang sedang diuji; jawabannya tidak bisa dipakai untuk menilai dirinya sendiri.

## Hasil yang sudah tercatat

Diukur ulang **10 September 2026** pada runtime satu proses, empat instance Core di belakang nginx,
PostgreSQL asli, tanpa tiruan Core.

- Penjenuhan: 1000 pengguna serentak pada 128 tenant, 90 detik. Nol pelanggaran kebenaran, nol 5xx
  aplikasi, 225 probe lintas tenant semua ditolak, 73 probe eskalasi hak semua 403.
- Perlombaan tautan: 32 pengguna dipusatkan pada 4 tenant, 90 detik. Nol himpunan gabungan dari
  3.920 pembacaan balik.
- Oracle SQL: 12 pemeriksaan sisi Core dan 26 pemeriksaan sisi modul, seluruhnya nol. 63.861 nomor
  terbit, 63.861 unik.

Oracle-nya dibuktikan bisa merah lebih dulu — probe diarahkan ke record milik sendiri, penulis
balapan mengirim gabungan kedua himpunan, satu baris cacat disuntik ke database lalu dihapus — dan
angka merahnya ada di README stack.

Temuan pentingnya bukan angka throughput, melainkan **apa yang jenuh lebih dulu**, dan jawabannya
berubah: sekarang CPU PHP, bukan PostgreSQL. Dengan PgBouncer session pooling, 1000 pengguna
serentak hanya memakai 39 koneksi database dan PostgreSQL tinggal 11-65% satu core, sementara
keempat instance API menghabiskan hampir seluruh 12 vCPU mesin. Salah satu sebabnya dapat
diperbaiki di lapis deployment: `php artisan config:cache` gagal pada Core, sehingga setiap
permintaan membayar bootstrap Laravel penuh.

## Halaman terkait

- [Load dan concurrency testing](/dev/20-load-and-concurrency-testing) — gate platform
- [Database dan migration](/apps/management-aset/arsitektur/database) — batasan yang diandalkan pengujian
- [Definition of done](/onboarding/definition-of-done) — penjaga batas dan gate load test sebagai syarat selesai
- `apps/control-plane/loadtest/README.md` — stack gabungan: cara menjalankan, hasil terukur, dan batas kejujurannya
