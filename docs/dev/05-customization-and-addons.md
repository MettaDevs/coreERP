# Kebutuhan khusus pelanggan tanpa fork

Halaman ini menjawab satu pertanyaan: **kalau seorang pelanggan meminta sesuatu yang belum ada di
CoreERP, jawabannya ditaruh di mana.** Pilihannya berurutan, dari yang paling murah dirawat sampai
yang paling khusus. Satu pilihan tidak pernah tersedia: salinan source untuk satu pelanggan.

Larangan itu bukan soal selera. Sistem lama menempuh jalan itu: setiap pelanggan memegang salinan
source dan database-nya sendiri, sehingga setiap update menjadi proyek pindahan tersendiri untuk
setiap pelanggan. CoreERP dibangun justru untuk keluar dari keadaan itu.

## Yang mudah tertukar

**Module khusus dan fitur produk.** Keduanya kode di repo ini, dan keduanya ikut ke image yang sama
untuk semua klien. Bedanya hanya siapa yang boleh membukanya: fitur produk terbuka untuk setiap tenant
yang membeli module-nya, sedangkan module khusus hanya untuk tenant yang lisensinya menyebut module itu.

**Integrasi dan module.** Integrasi berjalan di luar CoreERP dan hanya menyentuh API serta event.
Module berjalan di dalam runtime Core dan tunduk pada seluruh [standar module](02-module-standard.md).
Pelanggan boleh membangun integrasinya sendiri, tetapi tidak pernah menaruh kode di dalam runtime.

**Setelan dan kode.** Perbedaan yang dapat ditulis sebagai data (format nomor, akun posting, alur
persetujuan) adalah setelan tenant. Menjawabnya dengan kode membuat setiap perubahan kecil harus
menunggu rilis.

## Urutan jawaban

| Urutan | Dipakai bila | Bentuknya |
| --- | --- | --- |
| 1. Setelan | Perbedaannya dapat ditulis sebagai data | Setelan tenant oleh owner/admin, termasuk [workflow persetujuan](21-visual-workflow-engine.md) dan [dasbor yang disusun dari dataset module](35-analitik.md) |
| 2. Fitur produk | Dibutuhkan lebih dari satu pelanggan, atau domainnya umum | Fitur module biasa, boleh dinyalakan per tenant |
| 3. Integrasi di luar CoreERP | Kebutuhannya di luar inti ERP: dasbor yang butuh data dari luar CoreERP, form lapangan, notifikasi | Pelanggan atau partner membangunnya sendiri di atas API dan event, dengan alat apa pun |
| 4. Module khusus | Hanya satu pelanggan, dan harus berjalan di dalam ERP | Module di `modules/` yang dilisensikan hanya ke tenant tertentu |
| Tidak pernah | – | Salinan source atau branch per pelanggan |

Mulai dari urutan teratas, dan turun hanya bila urutan di atasnya tidak cukup. Semakin ke bawah,
semakin banyak yang harus ikut dirawat setiap kali Core berubah.

### 1. Setelan

Yang sudah menjadi setelan tenant antara lain format nomor dokumen (lihat
[number sequence](14-number-sequences.md)), akun posting, workflow persetujuan, dan dasbor.
Setelan bertahan melewati update tanpa pekerjaan tambahan, karena yang berubah hanya data.

**Dasbor adalah setelan selama datanya sudah dinyatakan sebagai dataset.** Module menyatakan dataset
(tabel mana yang boleh dianalisis, kolom mana yang boleh dikelompokkan, nilai mana yang sah dijumlah),
dan admin atau konsultan pelanggan menyusun dasbornya dari dataset itu: dasbor, widget, dan query
tersimpan adalah baris di tabel tenant, bukan kode, jadi menyusunnya tidak menunggu rilis dan dasbornya
bertahan melewati update; kunci dataset yang diganti nama dipetakan lewat versi dataset. Hak melihat
angkanya tetap hak baca resource module dan kebijakan datanya, sama dengan layar daftarnya; dasbor tidak
membuka data yang tidak boleh dibuka layar module. Rinciannya di [engine analitik](35-analitik.md).
Penyusunnya memakai pembangun bagian dan penjelajah data di layar Dasbor dan Analisis data.

Batasnya: dasbor hanya dapat menampilkan **data CoreERP yang sudah dinyatakan sebagai dataset**. Bila
isinya butuh dataset yang belum ada, jawabannya menambah dataset di module pemiliknya (urutan 2, fitur
produk), bukan kode khusus pelanggan. Bila isinya butuh data dari luar CoreERP — sistem lain, spreadsheet,
mesin di lapangan — ia tetap [integrasi di luar CoreERP](#3-integrasi-di-luar-coreerp): engine ini hanya
membaca tabel CoreERP.

Custom field (kolom tambahan yang dibuat pelanggan sendiri) **belum ada**. Permintaan yang
membutuhkannya turun ke urutan berikutnya sampai mekanisme itu benar-benar dibangun.

### 2. Fitur produk

**Begitu pelanggan kedua meminta hal yang sama, atau domainnya jelas umum, ia menjadi fitur produk,
bukan module khusus kedua.** Satu jalur kode diuji dan dipakai semua pelanggan. Dua module khusus
untuk hal yang sama berarti dua jalur yang menua sendiri-sendiri dan sama-sama harus diperbaiki
setiap kali Core berubah.

Kalau fiturnya tidak cocok untuk semua pelanggan, ia dinyalakan lewat setelan tenant milik module itu,
bukan dipisah menjadi module khusus.

### 3. Integrasi di luar CoreERP

**CoreERP tidak membangun platform low-code.** Pelanggan boleh memakai Power Apps, n8n, spreadsheet,
atau backoffice-nya sendiri. Yang disediakan CoreERP adalah API dan event yang layak dipakai alat apa
pun. Membangun platform low-code sendiri berarti merawat produk kedua yang sama besarnya dengan ERP-nya,
dan Microsoft sendiri pun menaruh Power Apps sebagai produk terpisah dari Business Central.

Keadaan sekarang, supaya tidak dijanjikan lebih dari yang ada:

- API module berversi sudah ada di `/api/modules/<id module>/v1/...`, dengan kontrak OpenAPI per
  module. Jalurnya masih memakai sesi peramban, jadi sistem pelanggan belum dapat memanggilnya dari
  server ke server. Kredensial token untuk itu direncanakan di
  [API untuk integrator](/todo/api-untuk-integrator/).
- Integrasi domain yang sudah berjalan memakai push/pull dengan signature, misalnya integrasi finance
  yang spesifikasinya di `apps/core/contracts/terbit/integrasi-finance.yaml`.

Dua aturan berlaku untuk setiap integrasi:

- **Sistem luar menyimpan ID (ULID), bukan kode bisnis.** Kode boleh dipakai ulang setelah barisnya
  diarsipkan, karena indeks unik kode bersifat parsial (`WHERE deleted_at IS NULL`, lihat
  [penghapusan lunak](02-module-standard.md#penghapusan-lunak)). Integrasi yang menyimpan kode dapat
  diam-diam menunjuk baris lain; ID tidak pernah dipakai ulang.
- **Event untuk pihak luar sedikit dan stabil.** Event yang diterbitkan adalah janji jangka panjang;
  nama dan aturan versinya ada di [API dan integrasi](04-api-and-integration.md). Kebutuhan internal
  antar-module tidak perlu menjadi event yang diterbitkan.

### 4. Module khusus

Module khusus adalah module biasa. Ia tinggal di `modules/`, memenuhi seluruh
[standar module](02-module-standard.md), dan dipasang per tenant dengan `module:install` seperti module
lain ([lifecycle app](02-module-standard.md#lifecycle-app)). Tiga hal membuatnya berbeda.

**Ia ikut ke image yang sama dengan semua klien; yang menguncinya lisensi dari admin.erp.** Sejak 18
September 2026 tidak ada lagi image per pelanggan (lihat `scripts/verify-edition.sh`). Akibatnya kode
module khusus sampai ke server setiap klien on-prem, walaupun tidak terbuka di sana. Karena itu module
khusus dinamai menurut kemampuannya, bukan menurut pelanggannya, dan tidak memuat nama, rahasia, atau
data pelanggan mana pun. Aturan repo memang melarang nama perusahaan ditulis mati di kode.

**Data tambahannya disimpan di tabelnya sendiri**, dengan kolom yang menunjuk ID milik module lain.
Module tidak boleh menyentuh tabel module lain, termasuk menambah kolom ke sana. Business Central
mengizinkan hal itu lewat `tableextension`; CoreERP sengaja tidak, karena batas tabel antar-module
adalah satu-satunya yang membuat module dapat dicabut tanpa merusak module lain.

**Titik sambung dibuat saat permintaan nyata pertama datang.** Kalau module khusus perlu ikut bereaksi
saat module lain memposting sesuatu, atau perlu menambah field di layar module lain, titik sambungnya
(event internal atau slot layar) ditambahkan ke module pemiliknya ketika kebutuhan itu benar-benar
ada, bersama test-nya. Membuatnya lebih dulu berarti menebak bentuk yang belum pernah diminta siapa
pun. Slot untuk menambah field di layar module lain saat ini belum ada.

## Keputusan dicatat

Setiap permintaan yang berakhir di urutan 2 atau 4 melewati
[gate penemuan dan keputusan](18-module-discovery-and-decision-gate.md). Proposalnya menyebut padanan
Dynamics 365 bila ada, urutan yang dipilih, dan alasan urutan di atasnya tidak cukup. Module khusus
yang kemudian diminta pelanggan kedua dipindahkan menjadi fitur produk, dan keputusan itu dicatat di
proposal yang sama.

## Pembanding: Business Central

Business Central memecahkan masalah yang sama dengan bentuk yang mirip:

- Kebutuhan satu pelanggan ditulis sebagai
  [extension per tenant](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/devenv-extension-types-and-scope)
  yang mengait event, sehingga kode dasarnya tidak pernah disunting dan update tetap berjalan untuk
  semua pelanggan.
- API untuk sistem luar adalah page tersendiri yang dikunci `SystemId`, bukan nomor bisnis, dan
  berversi. Contohnya
  [`APIV2Customers.Page.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Apps/W1/APIV2/app/src/pages/APIV2Customers.Page.al).
- Event di dalam prosesnya sangat banyak, sedangkan
  [event bisnis](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/business-events-overview)
  yang diterbitkan ke sistem luar hanya segelintir, misalnya pesanan penjualan dirilis atau faktur
  pembelian diposting. Daftarnya ada di app
  [`ExternalEvents`](https://github.com/microsoft/BCApps/tree/777e102e90a078b7256bf94b065ba50089abafa9/src/Apps/W1/ExternalEvents).
- Power Apps dan Power Automate tersambung lewat API dan event bisnis tadi, sebagai produk terpisah.

Yang tidak ditiru: menambah kolom atau field ke tabel dan layar milik app lain, dan menjalankan kode
buatan pelanggan di dalam runtime.

## Halaman terkait

- [Standar module](02-module-standard.md) — batas tabel, lifecycle, dan jenis module
- [API dan integrasi](04-api-and-integration.md) — kontrak dan event yang boleh dipakai pihak luar
- [Visual workflow engine](21-visual-workflow-engine.md) — alur persetujuan sebagai setelan
- [Engine analitik](35-analitik.md) — dasbor sebagai setelan, dan cara module menyatakan dataset
- [Gate penemuan dan keputusan](18-module-discovery-and-decision-gate.md) — tempat keputusan dicatat
- [API untuk integrator](/todo/api-untuk-integrator/) — rencana kredensial token untuk sistem pelanggan
