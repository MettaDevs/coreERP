# PRD: pemindahan CoreERP ke satu runtime

> **Ini pekerjaan sekali jalan, bukan desain kanonik.** Alasan dan angka pembandingnya ada di
> [dokumen keputusan](00-keputusan.md); cara memakai daftar task ini ada di [ikhtisar](index.md).
> Aturan yang lahir dari pekerjaan ini dipindahkan ke `docs/dev` setelah selesai, lalu folder ini
> boleh dihapus.

## 1. Ringkasan

### 1.1 Apa yang dibangun

CoreERP berpindah dari **satu proses dan satu database per app bisnis** menjadi **satu runtime Laravel
dengan modul yang dipasang dan dicabut per tenant**. Repo app bisnis yang terpisah digabung menjadi
folder modul di dalam repo CoreERP. UI yang sekarang dimuat lewat iframe menjadi satu build Vite.

### 1.2 Apa yang tidak berubah

Manifest `app.yaml`, dependency antar modul, entitlement, rantai keamanan sampai duty, number sequence,
workflow, reporting, dan Web Shell tetap seperti sekarang secara konsep. Yang berubah adalah cara
memanggilnya: dari HTTP antar container menjadi pemanggilan fungsi di proses yang sama.

### 1.3 Kenapa sekarang

Yang benar-benar berisi baru Core dan Management Aset. Human Resources baru puluhan berkas; Procurement,
Business Partner, dan Finance masih kerangka dari template. Memindahkan satu modul yang berisi adalah
pekerjaan beberapa minggu. Menunggu sampai lima modul saling berkirim event membuatnya menjadi penulisan
ulang.

## 2. Yang dianggap selesai

### 2.1 Selesai untuk satu task

Sebuah task selesai bila **semua** berikut benar. Tidak ada yang boleh ditunda ke task berikutnya.

| Syarat | Cara memeriksa |
| --- | --- |
| Perubahan ada dalam satu pull request | Satu PR, satu tujuan, bisa ditinjau dalam 30 menit |
| Test yang relevan lulus | `composer test` di direktori yang tersentuh |
| Analisa statis lulus | `composer types:check` (PHPStan) tanpa error baru |
| Format kode lulus | `composer lint:check` (Pint) |
| Stack lokal masih menyala | `./start.ps1` sampai halaman masuk terbuka |
| Dokumen yang terpengaruh diperbarui | Halaman di `docs/` yang menyebut hal yang diubah |
| Task menyebut dokumen rujukannya | Baris "Rujukan" pada task ini |

### 2.2 Selesai untuk satu fase

Sebuah fase selesai bila seluruh task di dalamnya selesai, **dan** kriteria keluar fase itu terpenuhi.
Kriteria keluar ditulis di awal tiap fase dan berupa hal yang bisa dijalankan, bukan penilaian.

### 2.3 Selesai untuk proyek

1. Satu image berisi Core dan modul Management Aset berjalan dengan satu database, dan modul itu dapat
   dipasang, dinonaktifkan, diaktifkan lagi, serta dicabut per tenant.
2. Modul Human Resources ikut di image yang sama, dan bisa dicabut tanpa menyentuh data Management Aset.
3. Dua bundle edisi berbeda dibangun dari repo yang sama, dan CI membuktikan modul yang tidak dibeli
   tidak ada di dalamnya.
4. Angka proyeksi pada dokumen keputusan diganti angka terukur.
5. Repo app bisnis lama diarsipkan dengan penunjuk ke lokasi barunya.

## 3. Prinsip yang tidak boleh dilanggar

Lima aturan berikut adalah alasan proyek ini bisa gagal atau berhasil. Setiap task yang melanggarnya
ditolak, walau hasilnya jalan.

**P1. Modul hanya menyentuh tabelnya sendiri.** Migration modul hanya membuat tabel dengan prefix
miliknya. Model modul A tidak boleh mengimpor model modul B. Foreign key hanya boleh menunjuk tabel
milik Core. Alasannya: sistem lama gagal justru di sini, dengan 445 tabel tanpa pemilik yang jelas,
sehingga mencabut satu modul berarti menebak-nebak.

**P2. Penjaga dulu, pemindahan kemudian.** Test yang menegakkan P1 harus ada dan terbukti bisa gagal
sebelum modul pertama dipindahkan. Penjaga yang ditambahkan belakangan tidak pernah menangkap pelanggaran
yang sudah terjadi.

**P3. Stack lokal tidak boleh mati lebih dari satu task.** Setiap PR meninggalkan `./start.ps1` dalam
keadaan menyala. Kalau sebuah perubahan terlalu besar untuk itu, ia dipecah, bukan digabung.

**P4. Tidak ada modul baru sampai fase 4 selesai.** Procurement, Business Partner, dan Finance tetap
kerangka kosong. Menambah modul di tengah pemindahan menggandakan pekerjaan.

**P5. Angka menggantikan pendapat.** Setiap klaim penghematan pada dokumen keputusan diganti hasil
pengukuran setelah fase yang bersangkutan selesai.

## 4. Konvensi

### 4.1 Penomoran task

Format `F<fase>-<nomor>`, misalnya `F1-03`. Nomor tidak dipakai ulang walau task dibatalkan, supaya
rujukan di PR lama tetap sah.

### 4.2 Branch dan commit

Mengikuti kebiasaan yang sudah dipakai repo ini:

```text
branch : feat/f1-03-module-registry-table
commit : feat(core): add module registry table
PR     : judul sama dengan commit, badan PR menyebut "Task F1-03" dan tautan ke PRD
```

Awalan commit yang dipakai: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`. Scope diisi bagian yang
disentuh, misalnya `core`, `aset`, `ui`, `ci`.

### 4.3 Bentuk tiap task pada dokumen ini

| Bagian | Isi |
| --- | --- |
| Judul | Kalimat kerja, satu tujuan |
| Kenapa | Satu sampai dua kalimat; apa yang rusak kalau task ini tidak ada |
| Berkas | Daftar berkas yang dibuat atau diubah |
| Langkah | Urutan yang bisa diikuti tanpa menebak |
| Selesai bila | Perintah yang dijalankan dan hasil yang diharapkan |
| Rujukan | Halaman `docs/` yang memberi konteks |
| Bergantung pada | Task lain yang harus selesai lebih dulu |

### 4.4 Ukuran task

Satu task adalah satu pull request yang bisa ditinjau dalam setengah jam. Kalau sebuah task menyentuh
lebih dari sekitar 15 berkas atau menggabungkan dua tujuan berbeda, ia dipecah. Task yang berbunyi
"pindahkan modul" tanpa rincian dianggap belum ditulis.

### 4.5 Kriteria selesai harus bisa gagal

Kalau tidak ada keadaan yang membuat sebuah kriteria berwarna merah, ia bukan kriteria. Tiga bentuk yang
tampak seperti pemeriksaan padahal bukan:

| Terlihat seperti pemeriksaan | Kenapa tidak menguji apa pun |
| --- | --- |
| Mencari jalur lama di repo utama | Rujukan ketiganya ada di repo lain, jadi pencarian selalu hijau sementara stack pengembangan rusak |
| Membangun situs dokumentasi untuk membuktikan folder mati sudah hilang | Rujukannya bukan tautan, jadi pembangunan tidak pernah gagal karenanya |
| Menghitung tabel pada database yang baru dibuat | Nol memang jawaban yang selalu benar di sana |

Sebelum menulis kriteria, sebutkan satu keadaan yang membuatnya gagal. Kalau tidak ada, ganti
kriterianya.
## 5. Arsitektur tujuan

Bagian ini adalah gambar akhir yang dituju semua task. Kalau sebuah task tidak mendekatkan kode ke
bentuk ini, task itu salah tempat.

### 5.1 Susunan folder

```text
CoreERP/
├─ apps/
│  └─ control-plane/          # runtime satu-satunya; Core sekaligus tuan rumah modul
│     ├─ app/                 # kode Core: identity, katalog, number sequence, workflow, reporting
│     ├─ resources/js/        # Web Shell; halaman modul masuk ke sini lewat modules/*/ui
│     └─ database/migrations/ # migration Core saja
├─ modules/
│  └─ apperp/
│     ├─ management-aset/
│     │  ├─ app.yaml          # manifest, sama seperti sekarang
│     │  ├─ src/              # PHP: Http, Models, Services, Actions
│     │  ├─ database/migrations/
│     │  ├─ ui/               # halaman React; di-import build shell
│     │  ├─ tests/
│     │  ├─ contracts/
│     │  └─ composer.json     # package lokal, autoload PSR-4 Modules\Apperp\ManagementAset\
│     └─ human-resources/     # bentuk yang sama
├─ packages/ui/               # @apperp/ui, di-import langsung, bukan tgz
└─ editions/                  # daftar modul per edisi customer
```

Repo `app-erp-*` yang sekarang terpisah menjadi folder di bawah `modules/`. Riwayat git-nya dibawa
serta dengan `git subtree add`, bukan disalin, supaya `git log` dan `git blame` tetap bisa dipakai.

### 5.2 Batas modul: awalan tabel, satu koneksi

Semua tabel berada di satu database. Yang memisahkan modul adalah **awalan nama tabel**, bukan schema
dan bukan koneksi:

| Milik | Awalan | Contoh |
| --- | --- | --- |
| Core | `core_` | `core_tenants`, `core_module_installations` |
| Management Aset | `aset_` | `aset_m_group`, `aset_tr_penerimaan` |
| Human Resources | `hr_` | `hr_m_pekerja` |

Konvensi `m_`, `tr_`, dan `tr_*_details` pada [standar app](../../dev/02-module-standard.md) tetap
berlaku; awalan modul ditambahkan di depannya. Tabel yang ada hari ini tanpa awalan modul diganti
namanya satu kali saat modulnya dipindahkan.

**Setiap tabel modul membawa `tenant_id`.** Ini bukan pilihan. Kolom itu yang membuat penempatan
gabungan dan penempatan terpisah memakai skema yang persis sama, sehingga satu tenant bisa dipindahkan
ke database sendiri tanpa mengubah skema maupun kode. Sudah diuji: baris satu tenant disalin ke database
baru dengan skema identik, dan query aplikasi yang sama persis memberi hasil yang sama.

#### Kenapa bukan schema per modul

Rancangan sebelumnya memakai schema PostgreSQL per modul beserta peran database sendiri. Itu dibatalkan
karena bertabrakan dengan janji yang lebih penting.

Peran modul yang hanya boleh membaca schema Core tidak bisa menulis tabel penerbitan nomor, padahal
`NumberSequenceService` menyentuh `number_sequence_continuous_pool` dan `number_sequence_reservations`.
Seandainya diberi hak tulis pun, koneksi modul tetap koneksi yang berbeda, jadi transaksinya tetap dua
transaksi terpisah. Itu persis masalah yang hari ini terjadi lewat jaringan, hanya tanpa jaringannya,
dan penerbitan nomor di dalam transaksi dokumen adalah keuntungan utama yang membenarkan seluruh
pemindahan ini.

Karena itu **runtime memakai satu koneksi database**, dan modul menulis tabelnya sendiri lewat koneksi
yang sama dengan Core.

#### Siapa yang menjaga batasnya

| Lapisan | Yang dijaga | Kapan |
| --- | --- | --- |
| Test di CI | migration modul hanya membuat tabel berawalan miliknya | tiap pull request |
| Analisa statis | kelas modul tidak mengimpor kelas modul lain | tiap pull request |
| Test tenant | tidak ada query modul tanpa penyaringan tenant | tiap pull request |

Jangan menuliskan bahwa batas ini ditegakkan mesin database. Ia ditegakkan pemeriksaan otomatis, dan itu
harus disebut apa adanya supaya tidak ada yang merasa aman tanpa alasan. Konsekuensinya, pemeriksaan itu
wajib hijau sebelum modul pertama masuk, dan itulah isi task paling awal.

#### Penempatan: gabungan atau terpisah

| | Gabungan, bawaan | Terpisah per tenant |
| --- | --- | --- |
| Database | satu untuk semua tenant | satu per tenant |
| Pasang modul | catat satu baris pada `core_module_installations` | jalankan migration modul di database itu, lalu catat baris |
| Cabut modul | ubah status; hapus data hanya baris milik tenant itu | ubah status; hapus data berarti membuang tabel modul |
| Risiko | satu query lupa menyaring tenant membocorkan semua | tidak ada |
| Dipakai untuk | tenant umum | fasilitas kesehatan dan tenant yang menuntutnya |

Skemanya sama, jadi perpindahan antar keduanya adalah memindahkan baris. Keputusannya diambil per
pelanggan dan bukan keputusan arsitektur.

#### Laporan lintas tenant tidak ada

Konsolidasi terjadi **di dalam** tenant, lewat legal entity dan operating unit, sama seperti Dynamics.
Satu grup dengan tiga klinik adalah satu tenant dengan tiga legal entity, dan laporan gabungannya satu
query biasa. Angka yang benar-benar melintasi tenant hanyalah metering milik vendor, dan itu dihitung
di control plane tanpa menyentuh data bisnis. Jangan merancang federasi database untuk kebutuhan yang
tidak ada.

#### Data tidak pernah dihapus fisik

Semua penghapusan adalah penghapusan lunak, termasuk saat modul dicabut. Diputuskan 7 September 2026;
lihat bagian 5.7. Ini bukan hanya penyederhanaan: rekam medis elektronik wajib disimpan paling singkat
25 tahun sejak kunjungan terakhir menurut Permenkes 24/2022, dan banyak fasilitas memilih tidak
memusnahkannya sama sekali.

Karena itu **perintah pencabutan tidak punya opsi penghapusan data sama sekali.** Ini lebih aman daripada
opsi yang ditolak bersyarat: sebuah opsi yang ada tetapi kadang ditolak akan dicoba, dan cepat atau
lambat ada modul yang lupa menyatakan penguncinya. Opsi yang tidak ada tidak bisa salah dipakai. Bentuk
ini sama dengan Business Central, yang mencabut ekstensi tanpa menyentuh datanya.

Tiga akibat yang harus ditangani, bukan diabaikan. Rinciannya beserta tugasnya ada di bagian 5.7 dan
pada F0-03.

### 5.3 Kode Core yang dipanggil modul

Tiga belas endpoint di bawah `/api/internal/v1/` berhenti menjadi jalur utama dan berubah menjadi
pemanggilan fungsi. Pemetaannya:

| Yang dipanggil modul hari ini lewat HTTP | Menjadi pemanggilan pada |
| --- | --- |
| `POST number-sequences/{reference}/issue` dan `/reserve` | `App\Actions\NumberSequence\NumberSequenceService` |
| `GET fiscal-periods` | `App\Actions\FiscalCalendar\FiscalCalendarService::resolve()` |
| `GET units-of-measure`, `POST units-of-measure/resolve`, `/convert` | `App\Services\UnitOfMeasureService` |
| `POST workflow-instances` | `App\Support\WorkflowRuntime::submit()` |
| `GET members`, `GET members/{id}`, `GET operating-units` | model Core langsung, dibungkus service baru |
| *(tidak pernah lewat HTTP; app lama membacanya dari token)* | `App\Support\CurrentWorkspace::membership()` untuk mendapatkan tenant dan organisasi aktif |

Baris terakhir ditambahkan 8 September 2026 saat mengerjakan F1-02. Ia sebelumnya tidak ada, padahal
**setiap** modul membutuhkannya sebelum bisa melakukan apa pun: tanpa tenant aktif, tidak ada query yang
boleh dijalankan. Yang tidak lewat HTTP mudah terlewat saat memetakan panggilan HTTP.

Arah sebaliknya juga hilang. Hari ini Core memanggil app untuk mengambil data laporan lewat
`App\Support\Reporting\AppReportClient`, dan mengirim keputusan workflow lewat perintah terjadwal
`workflow-events:publish`. Keduanya menjadi pemanggilan langsung di dalam proses.

Endpoint HTTP-nya tidak dihapus. Ia tetap ada untuk integrasi luar dan untuk addon yang ditulis pihak
ketiga, dan kontraknya tetap dijaga `contracts/openapi-internal.yaml`. Yang berubah hanya siapa yang
memakainya.

### 5.4 Kunci penandatangan

`COREERP_APP_CONTEXT_SIGNING_KEY` sekarang dipakai dua hal sekaligus: menandatangani token konteks untuk
iframe, dan menandatangani HMAC event keluar. Setelah UI menyatu dan modul memanggil Core langsung,
pemakaian pertama hilang. Kunci itu tetap ada untuk event ke sistem luar, dan itu harus disebut jelas
saat pemakaiannya dikurangi supaya tidak ada yang mengira kuncinya sudah tidak dipakai.

### 5.5 UI

Halaman modul menjadi folder `modules/<publisher>/<modul>/ui` yang diimpor build Vite milik shell.
Iframe pada `resources/js/pages/apps/host.tsx` diganti pemuatan komponen dengan `React.lazy`, sehingga
React dan `@apperp/ui` dimuat sekali. Menu tetap datang dari `ui.navigation` di manifest lewat
`App\Support\LaunchableAppCatalog`, dan tetap disaring permission seperti sekarang; yang berubah hanya
tujuan tautannya, dari sumber iframe menjadi rute di shell.

`@apperp/ui` berhenti didistribusikan sebagai berkas `.tgz` yang disalin ke tiap repo. Ia menjadi paket
di dalam repo yang diimpor langsung. Ketidakcocokan versi antara paket yang dibangun dan yang disalin,
yang hari ini terlihat dari berkas `apperp-ui-0.4.2.tgz` di samping paket versi `0.6.2`, tidak bisa
terjadi lagi.

### 5.6 Edisi

Sebuah edisi adalah daftar modul untuk satu pelanggan, disimpan sebagai berkas di `editions/`:

```yaml
# editions/apotek-sejahtera.yaml
customer: apotek-sejahtera
profile: onprem-perpetual
release: 2026.10.0
modules: [inventory, farmasi, kasir]
```

CI menghitung dependency dan link module dari `app.yaml`, membangun satu image yang hanya menyalin folder
modul hasil hitungan itu, lalu membuktikan modul lain tidak ikut sebelum bundle ditandatangani.
Bentuk manifest ini adalah Customer Edition Manifest pada
[release dan on-prem](../../dev/03-release-and-on-prem.md), yang sampai sekarang belum pernah dibangun.

### 5.7 Empat keputusan yang dijawab pemilik produk

Dijawab 7 September 2026, setelah F0-01 selesai. Ditulis di sini supaya tidak ada task yang menebaknya.

#### Modul dibeli per tenant

Satu baris pemasangan dimiliki satu tenant, bukan satu legal entity. Konsekuensinya perlu disebut karena
contoh kesehatan mudah salah baca: sebuah grup yang punya apotek **dan** klinik sebagai dua legal entity
di bawah satu tenant membeli kedua modul sekali, lalu memakai legal entity untuk memisahkan datanya.
Yang membeli farmasi saja adalah apotek yang berdiri sebagai tenant tersendiri.

Ini menutup pertanyaan tentang kunci pemasangan: `core_module_installations` berkunci `tenant_id` dan
kode modul, dan tidak perlu dimensi ketiga.

#### Pembaruan on-prem dijalankan admin pelanggan

Tidak ada saluran pembaruan otomatis, dan tidak direncanakan ada. Admin di tempat pelanggan yang
menjalankannya.

Itu menentukan bentuk yang harus dihasilkan fase 5: **satu perintah**, bukan urutan langkah. Seorang
admin yang menjalankan tujuh langkah tangan akan melewatkan langkah keempat pada pembaruan kesepuluh, dan
yang menemukan akibatnya adalah kita, berbulan-bulan kemudian, lewat laporan yang tidak masuk akal.
Perintah itu juga harus aman diulang: dijalankan dua kali harus memberi hasil yang sama dengan sekali.

#### Data tidak pernah dihapus fisik

Semua penghapusan adalah penghapusan lunak. Ini menyederhanakan pencabutan modul: **perintah pencabutan
tidak punya opsi hapus data sama sekali**, jadi tidak ada keputusan yang bisa salah diambil operator.
Ini juga bentuk yang dipakai Business Central, yang mencabut ekstensi tanpa menyentuh datanya.

Tiga akibat yang harus ditangani, bukan diabaikan:

| Akibat | Yang harus dilakukan |
| --- | --- |
| Indeks unik ikut menghitung baris terhapus | pakai indeks unik parsial yang hanya mencakup baris hidup, kalau tidak kode yang sama tidak bisa dipakai ulang setelah dihapus |
| Setiap query harus menyaring baris terhapus | disaring di lapisan model, dan diuji; lupa satu berarti data terhapus muncul lagi di layar |
| Data tumbuh selamanya | ukuran tabel tenant harus dipantau sejak awal, bukan setelah ada yang lambat |

Satu hal yang tidak selesai dengan penghapusan lunak: **tenant yang berhenti berlangganan**. Datanya
tetap ada di database kita tanpa batas waktu, dan itu keputusan hukum, bukan keputusan teknis. Yang
dibutuhkan adalah jalan keluar berupa ekspor lengkap yang bisa dibaca sistem lain atau serah terima
database, ditulis di kontrak sebelum pelanggan pergi, bukan sesudah.

#### Peninjauan pull request ditegakkan mulai sekarang

Sampai hari ini tidak ada yang meninjau, dan alur yang merah diabaikan berbulan-bulan. Mulai sekarang
cabang utama dikunci: perubahan masuk lewat pull request, dan pull request tidak bisa digabungkan
sebelum kedua alur hijau. Ini yang membuat seluruh dokumen ini punya arti — tanpanya, setiap kalimat
"selesai bila test lulus" hanyalah harapan.

Jumlah persetujuan yang diwajibkan disetel nol, bukan satu. Dengan satu engineer dan tiga magang, mewajibkan
satu persetujuan berarti pull request pemimpin tim tidak pernah bisa digabungkan, karena penulis tidak
boleh menyetujui pull request-nya sendiri. Yang ditegakkan mesin adalah pemeriksaan otomatis; peninjauan
manusia untuk pull request magang tetap dilakukan, tapi ditegakkan orang.

## 6. Peta fase

| Fase | Isi | Kriteria keluar |
| --- | --- | --- |
| 0 | Prasyarat: pemeriksaan otomatis hijau dan aturan kerja diselaraskan | Sebuah pull request kosong lulus CI, dan aturan repo tidak lagi melarang pekerjaan ini |
| 1 | Penjaga batas dan kerangka modul | Penjaga terbukti bisa gagal, dan gagal karena alasan yang benar |
| 2 | Core menjadi tuan rumah modul | Modul contoh berisi satu tabel bisa dipasang, dipakai, dinonaktifkan, lalu dicabut |
| 3 | Management Aset pindah ke dalam Core | Semua test Management Aset lulus di dalam Core, tanpa satu pun panggilan HTTP ke Core |
| 4 | UI menjadi satu build | Iframe hilang, React dimuat sekali, halaman modul dipanggil router shell |
| 5 | Edisi dan bundle on-prem | Dua bundle edisi berbeda terbukti tidak memuat modul yang tidak dibeli |
| 6 | Dev stack dan CI | Skrip pengembangan menyalakan satu runtime; satu alur CI |
| 7 | Modul kedua, pengukuran, dan pembersihan | Human Resources ikut; angka proyeksi diganti angka terukur |

Fase dikerjakan berurutan. Fase 4 boleh dimulai setelah task pemindahan test pada fase 3 selesai, karena
UI tidak bergantung pada sisa pemindahan API.

Penomoran task memakai nomor fase, jadi task fase 0 bernomor `F0-xx` dan seterusnya. Nomor tidak dipakai
ulang walau task dibatalkan, supaya rujukan pada pull request lama tetap sah.

**Sebelum mengambil task, lihat papan "sedang dikerjakan" pada [ikhtisar](index.md).** Papan itu hanya ada
di sana; menyalinnya ke sini akan membuat dua daftar yang menyimpang.

## 7. Fase 0: prasyarat

**Kenapa ada fase sebelum penjaga.** Dua hal di luar kode menghalangi seluruh rencana ini, dan keduanya
sudah ada sebelum proyek dimulai. Selama keduanya belum beres, tidak ada task berikutnya yang bisa
dibuktikan selesai.

F0-04, F0-05, dan F0-06 lahir dari pengerjaan task sebelumnya, bukan dari perencanaan. Nomornya mengikuti
urutan lahir, bukan urutan kerja, sesuai aturan bahwa nomor task tidak dipakai ulang. Urutan bacanya
sengaja menaruh F0-06 tepat setelah F0-03, karena ia kelanjutan langsung dari temuannya.

**Kriteria keluar.** Sebuah pull request yang tidak mengubah apa pun lulus seluruh pemeriksaan otomatis,
dan tidak ada berkas aturan yang melarang modul berjalan di runtime Core.

### F0-01 — Pemeriksaan otomatis punya PostgreSQL

**Kenapa.** Tiga jalan terakhir alur test gagal, termasuk di cabang utama. Penyebabnya alur menjalankan
`php artisan test` sementara `phpunit.xml` memaksa koneksi `pgsql_test` dan 38 berkas test memakai
`RefreshDatabase`, padahal alur itu tidak menyediakan PostgreSQL sama sekali. Setiap kalimat "selesai
bila test lulus" pada dokumen ini bersandar pada alur yang tidak pernah hijau.

**Berkas.**
- `.github/workflows/tests.yml`
- `apps/control-plane/.env.example` (catatan cara membuat schema test)
- `docs/dev/11-local-docker-development.md` (bila langkahnya berubah)

**Langkah.**
1. Tambahkan layanan PostgreSQL pada alur test, versi yang sama dengan yang dipakai stack lokal.
2. Tambahkan langkah yang membuat schema test sebelum test dijalankan. Hari ini schema itu dibuat tangan
   menurut catatan pada berkas contoh lingkungan.
3. Isi variabel lingkungan yang dibaca koneksi test, termasuk host, port, pengguna, dan kata sandi.
4. Jalankan seluruh suite dan perbaiki test yang selama ini tidak pernah benar-benar berjalan.

**Selesai bila.** Alur test hijau pada cabang utama, dan jumlah test yang berjalan sama dengan yang
berjalan di mesin pengembang.

**Rujukan.** [CI/CD](../../dev/22-ci-cd.md).

**Bergantung pada.** Tidak ada. Ini task pertama proyek.

**Selesai.** Pull request [#27](https://github.com/MettaDevs/coreERP/pull/27), 7 September 2026. Alur
test hijau di PHP 8.4 dan 8.5 dengan 198 test dan 942 asersi, jumlah yang sama dengan mesin pengembang.

#### Yang ternyata berbeda dari dugaan task ini

Task ini menyebut satu penyebab. Ada empat, dan tiga sisanya tersembunyi karena langkah pertama alur
selalu mati lebih dulu:

| Penghalang | Disebut task ini |
| --- | --- |
| `composer install` gagal di PHP 8.3: lock menuntut `>= 8.4.1`, `composer.json` menulis `^8.3` | tidak |
| Pint 7 berkas, Prettier 10 berkas, ESLint 126 kesalahan | tidak |
| PHPStan level 7 melaporkan 348 temuan sejak commit pertama | tidak |
| Alur test tidak menyediakan PostgreSQL | ya |

Karena Pint berada di langkah paling awal alur linter, tiga pemeriksaan sesudahnya — Prettier, ESLint,
dan cakupan kontrak internal — tidak pernah dijalankan satu kali pun sejak repo dibuat. Pelajarannya
berlaku untuk seluruh dokumen ini: **alur yang gagal tidak melaporkan apa lagi yang akan gagal.** Angka
kegagalan yang terlihat selalu batas bawah.

#### Bug yang hanya bisa ketahuan setelah suitenya berjalan

`NumberSequenceConcurrencyTest` memakai `DatabaseTruncation`, dan daftar pengecualiannya ketinggalan dua
tabel referensi yang diisi migrasi, yaitu `party_types` dan `country_regions`. Ia mengosongkan keduanya,
lalu tiga test pada berkas lain gagal dengan pesan yang terbaca seperti bug berkas itu sendiri. Kedua
tabel ditambahkan 24 Agustus dan 5 September 2026, jadi jebakan ini menunggu dua minggu tanpa terlihat.

Daftar itu kini lengkap dan membawa perintah untuk memeriksanya. Aturan yang lahir dari sini: **setiap
tabel yang diisi migrasi harus terdaftar sebagai pengecualian pemangkasan.** Modul yang masuk pada fase
berikutnya akan membawa tabel referensinya sendiri, jadi aturan ini akan diuji lagi.

#### Dua keputusan yang perlu diketahui pembaca berikutnya

**Baseline PHPStan.** 348 temuan tidak bisa diperbaiki dalam satu pull request, dan menurunkan level
berarti kode modul yang masuk nanti diperiksa selemah kode lama. Yang lama dibekukan di
`apps/control-plane/phpstan-baseline.neon`; kode baru diperiksa penuh di level 7. Berkas itu hanya boleh
menyusut, dan menambah baris ke dalamnya ditolak saat peninjauan. Sudah dibuktikan masih bisa gagal
lewat kelas bercacat sengaja, sesuai bagian 4.5.

**`import/order` dimatikan pada enam berkas antarmuka.** Berkas itu membawa penanda `@chisel-*`, dan
`laravel/chisel` menghapus kode di antara sepasang penanda saat sebuah fitur dimatikan. Menata ulang
impor memindahkan penandanya, sehingga penghapusan fitur akan membuang baris yang salah. Ini ketahuan
karena `composer update --lock` memicu `install:features` dan mengubah 13 berkas di luar task ini;
semuanya dikembalikan. **Jangan menjalankan `composer update` di repo ini tanpa memeriksa berkas yang
ikut berubah.**

#### Alur linter juga disentuh, dan itu memang perlu

Alur linter melaporkan tujuh pelanggaran urutan impor yang tidak muncul di mesin pengembang, karena
`resources/js/actions`, `resources/js/routes`, dan `resources/js/wayfinder` tidak ikut di-commit dan
hanya lahir dari `wayfinder:generate`. Tanpa berkasnya, resolver ESLint gagal menemukan modulnya dan
menggolongkannya sebagai paket luar. Alur test tidak terkena karena `npm run build` membuatnya sebagai
efek samping. Langkah pembuatan berkas itu kini ada pada alur linter.

Ini contoh lain dari pola yang sama: pemeriksaan yang melihat pohon berkas berbeda dari yang dilihat
pengembang akan menuntut hal yang justru ditolak di mesin pengembang.

#### Yang sengaja ditinggalkan

`npm run types:check` melaporkan empat kesalahan `TS2322` yang identik: komponen `Heading` menerima
prop `icon` yang tidak ada pada tipenya. Keempatnya sudah ada di cabang utama sebelum task ini, dan
`tsc` tidak dijalankan alur mana pun. Memasukkan `tsc` ke alur adalah task tersendiri; lihat F0-04.

### F0-02 — Aturan kerja repo diselaraskan

**Kenapa.** Berkas panduan kerja menyatakan setiap modul wajib memiliki API, UI, database, migration,
kontrak, dan container sendiri, serta melarang query lintas modul selain lewat REST atau event. Berkas
itu dibaca setiap sesi kerja. Selama kalimatnya berdiri tanpa pengecualian, setiap pull request pada
fase 2 dan 3 melanggar aturan tertulis, dan peninjau berhak menolaknya.

**Berkas.**
- `AGENTS.md`
- `modules/README.md`
- `.claude/skills/coreerp-architecture/SKILL.md` dan salinannya di `.agents/`
- `.github/scripts/check-skill-copies.py` dan `.github/workflows/lint.yml`

**Langkah.**
1. Jangan menghapus aturan lama. App di repo `app-erp-*` yang belum dipindah masih menjalankannya, dan
   menghapusnya membuat aturan salah untuk kode yang sedang berjalan.
2. Tambahkan pembeda yang jelas antara dua keadaan yang hidup berdampingan selama transisi:
   app lama tetap memiliki container dan database sendiri; modul di bawah `modules/` berjalan di runtime
   Core, memakai database tenant yang sama, tabelnya berawalan nama modul, dan tidak memiliki container,
   database, maupun token layanan sendiri.
3. Nyatakan bahwa pull request yang memindahkan app menjadi modul adalah pengecualian sah terhadap aturan
   lama, dan wajib menyebut dokumen keputusan pada badannya.
4. Dua folder skill berisi salinan yang identik. Samakan keduanya, lalu pasang pemeriksaan yang gagal
   bila keduanya menyimpang lagi. Menyamakannya sekali tanpa pemeriksaan hanya menunda masalahnya.

**Selesai bila.** Membaca berkas panduan kerja dari awal, seorang peninjau dapat menjawab dengan pasti
apakah sebuah pull request yang menaruh modul di runtime Core melanggar aturan atau tidak; dan sebuah
penyimpangan yang sengaja dibuat antara dua folder skill membuat alur merah.

**Rujukan.** [keputusan satu runtime](00-keputusan.md).

**Bergantung pada.** Tidak ada.

#### Yang ternyata berbeda dari dugaan task ini

**Dua folder skill sudah menyimpang, bukan identik.** Tiga skill berbeda isinya, dan penyimpangannya
dua arah: `coreerp-page-standard` dan `coreerp-ui` membawa aturan yang hanya ada di `.agents`,
sementara `frontend-patterns` membawa aturan yang hanya ada di `.claude`. Pada `coreerp-ui` bahkan ada
dua versi berbeda dari aturan yang sama tentang penanda field wajib. Menimpa satu folder dengan yang
lain akan membuang aturan yang sah; ketiganya digabungkan, bukan disalin satu arah.

Karena itu langkah 4 tidak cukup dikerjakan sekali. `.github/scripts/check-skill-copies.py` kini
membandingkan setiap skill yang ada di kedua folder dan gagal bila isinya berbeda, dan ia berjalan di
alur linter. Sudah dibuktikan bisa gagal.

**`modules/README.md` juga membawa aturan lama** dan tidak disebut task ini. Isinya menyatakan setiap
modul wajib punya container sendiri — berkas yang justru dibaca pertama kali oleh orang yang akan
membuat modul pertama.

**`module-discovery` hanya ada di `.agents/`,** dan tidak menyatakan aturan batas modul sama sekali,
jadi ia tidak perlu diubah.

### F0-03 — Aturan penghapusan lunak ditulis sebelum ada tabel modul

**Kenapa.** Semua penghapusan adalah penghapusan lunak (bagian 5.7). Aturan itu harus berdiri sebelum
tabel modul pertama dibuat, karena dua akibatnya mengubah bentuk skema dan tidak murah diperbaiki
belakangan: indeks unik harus parsial, dan setiap query harus menyaring baris terhapus. Modul yang sudah
terlanjur dibuat dengan indeks unik biasa akan menolak kode yang dipakai ulang setelah dihapus, dan
gejalanya muncul sebagai keluhan pengguna, bukan sebagai test merah.

**Berkas.**
- `docs/dev/02-module-standard.md` (bagian penghapusan lunak dan indeks unik)
- `AGENTS.md` (satu kalimat aturan, merujuk ke standar)

**Langkah.**
1. Tulis aturannya di standar modul: setiap tabel modul memiliki penanda terhapus, tidak ada perintah
   yang menghapus baris secara fisik, dan pencabutan modul tidak menyentuh data.
2. Nyatakan bentuk indeks uniknya. Indeks unik pada kode bisnis wajib parsial, hanya mencakup baris
   hidup, supaya kode yang sudah dihapus bisa dipakai ulang. Sertakan satu contoh yang bisa disalin.
3. Nyatakan bahwa penyaringan baris terhapus terjadi di lapisan model, bukan diulang di tiap query, dan
   bahwa modul wajib punya satu test yang membuktikan baris terhapus tidak muncul di daftar.
4. Nyatakan siapa yang memantau pertumbuhan tabel dan sejak kapan. Data yang tidak pernah dihapus tumbuh
   selamanya; itu diterima dengan sadar, bukan dilupakan.

**Selesai bila.** Standar modul menjawab tiga pertanyaan tanpa perlu bertanya orang: bagaimana bentuk
indeks unik pada kolom kode, di mana baris terhapus disaring, dan apa yang terjadi pada data saat modul
dicabut.

**Rujukan.** Bagian 5.2 dan 5.7 dokumen ini, [standar app](../../dev/02-module-standard.md).

**Bergantung pada.** Tidak ada.

#### Yang ditemukan saat mengerjakannya

Aturan indeks unik parsial ternyata bukan pencegahan, melainkan perbaikan: **pasangan yang salah sudah
ada di kode hari ini.**

| Repo | Tabel dengan `deleted_at` dan indeks unik penuh pada kode |
| --- | --- |
| CoreERP | 1 (`units_of_measure`) |
| Management Aset | 17 |

Diukur 8 September 2026. Cara mengukurnya ada pada bagian penghapusan lunak di standar app; angkanya akan
berubah dan harus diukur ulang, bukan dikutip.

Sudah dibuktikan pada database sungguhan, bukan disimpulkan dari membaca migrasi: satuan diarsipkan,
hilang dari daftar, lalu kodenya ditolak dengan `duplicate key value violates unique constraint`.
Pengguna melihat "kode sudah dipakai" untuk kode yang tidak muncul di daftar mana pun. Indeks parsial
memperbaikinya tanpa melonggarkan apa pun — dua baris hidup dengan kode sama tetap ditolak.

Memperbaiki 18 tabel itu bukan bagian task ini, karena 17 di antaranya ada di repo yang akan ditarik
masuk pada F3-01 dan migrasinya akan disentuh lagi di sana. Lihat F0-06.

### F0-07 — Alur otomatis dihemat supaya kuota tidak habis

**Kenapa.** Repo ini privat pada paket gratis, jadi menit alur otomatis terbatas. Kuota yang habis
berarti **tidak ada pemeriksaan sama sekali**, dan itu mengembalikan keadaan sebelum F0-01. Ini bukan
penghematan demi kerapian.

**Berkas.**
- `.github/workflows/tests.yml`
- `.github/workflows/lint.yml`

**Yang diukur lebih dulu.** Ketiga pemeriksaan berjalan **bersamaan**, jadi memangkas satu tidak
memperpendek waktu tunggu sama sekali; yang berkurang adalah menit terpakai.

| Pemeriksaan | Durasi |
| --- | --- |
| `quality` | 1 menit 26 detik |
| `ci (8.4)` | 2 menit 16 detik |
| `ci (8.5)` | 2 menit 41 detik |

Rincian di dalam satu job test: menyiapkan PHP 14 detik, `npm ci` 9 detik, `composer install` 11 detik,
membangun aset 10 detik, analisa tipe 23 detik, test 43 detik. Pemasangan dependensi ternyata murah,
jadi menambah cache tidak menolong banyak.

**Langkah.**
1. PHP 8.5 hanya dijalankan pada cabang utama dan sekali seminggu, bukan pada tiap pull request.
   Produksi berjalan di 8.4; peringatan dini tentang versi berikutnya tetap didapat.
2. Analisa tipe PHP dipindah dari alur test ke alur linter. Ia tidak bergantung versi PHP, jadi
   menjalankannya di dalam matriks berarti mengerjakan hal yang sama dua kali dengan hasil yang pasti
   sama.
3. Kedua alur mendapat `concurrency` dengan pembatalan: mendorong dua kali beruntun tidak lagi
   menghabiskan kuota untuk jalan yang sudah usang.

**Selesai bila.** Sebuah pull request hanya memunculkan dua pemeriksaan, `quality` dan `ci (8.4)`, dan
keduanya hijau.

**Rujukan.** [CI/CD](../../dev/22-ci-cd.md).

**Bergantung pada.** F0-01.

#### Yang dicoba dan dibatalkan

Pembangunan aset sempat ikut dipindah ke alur linter dengan alasan yang sama. Itu **salah**, dan cepat
ketahuan karena dicoba: tanpa manifest Vite, tiga test gagal dengan pesan `Not a valid Inertia response`
— pesan yang sama sekali tidak menyebut aset. Seseorang yang memindahkannya tanpa mencoba akan
menghabiskan waktu lama mencari penyebab di tempat yang salah.

#### Yang sengaja tidak dilakukan

Menggabungkan ketiga pemeriksaan menjadi satu job. Sekarang bila Pint merah, itu terlihat tanpa menunggu
test selesai, dan sebaliknya. Menggabungkannya membuat satu kegagalan menyembunyikan yang lain — persis
penyakit yang membuat alur repo ini merah berbulan-bulan tanpa ada yang tahu bahwa Prettier dan ESLint
belum pernah dijalankan sekali pun.

### F0-06 — Indeks unik penuh pada tabel yang mengarsipkan diperbaiki

**Kenapa.** Delapan belas tabel memiliki `deleted_at` beserta indeks unik penuh pada kode bisnis, jadi
kode yang sudah diarsipkan tidak pernah bisa dipakai ulang. Gejalanya adalah pesan "kode sudah dipakai"
untuk kode yang tidak terlihat di daftar mana pun, dan penyebabnya tidak bisa ditemukan dari layar.

**Berkas.**
- `apps/control-plane/database/migrations/` (satu migrasi baru, `units_of_measure`)
- migrasi Management Aset, dikerjakan setelah reponya ditarik masuk pada F3-01

**Langkah.**
1. Untuk Core, tulis satu migrasi yang membuang indeks unik penuh dan menggantinya dengan indeks parsial.
   Migrasi ini memakai SQL langsung; hitungannya masuk ke daftar migrasi ber-SQL mentah pada bagian 5.
2. Sebelum mengganti, periksa apakah sudah ada baris terarsip yang kodenya bentrok dengan baris hidup.
   Bila ada, indeks parsial tetap bisa dibuat; yang tidak boleh adalah dua baris **hidup** dengan kode
   sama. Buktikan dengan query, jangan berasumsi.
3. Tambahkan test yang mengarsipkan satu baris lalu membuat baris baru dengan kode yang sama, dan
   membuktikan dua baris hidup dengan kode sama tetap ditolak.
4. Untuk Management Aset, kerjakan setelah F3-01 supaya migrasinya hanya disentuh sekali.

**Selesai bila.** Test pada langkah 3 lulus, dan pemeriksaan pada standar app tidak lagi menemukan
pasangan yang salah di repo Core.

**Rujukan.** [penghapusan lunak](../../dev/02-module-standard.md#penghapusan-lunak).

**Bergantung pada.** F0-03. Bagian Management Aset bergantung pada F3-01.

#### Catatan pelaksanaan

Bagian Core selesai. Bagian Management Aset (langkah 4) tetap menunggu F3-01 dan tidak disentuh.

**Jumlah tabelnya benar, bentuk keunikannya yang tidak.** Pemeriksaan ulang seluruh migrasi Core —
`apps/control-plane` beserta `modules/apperp/contoh-a` dan `contoh-b` — menemukan tepat satu tabel yang
mengarsipkan sekaligus memiliki keunikan penuh pada kode bisnis, yaitu `units_of_measure`. Dugaan PRD
tepat. Yang berbeda adalah bentuknya: keunikan itu sebuah **UNIQUE constraint** (`pg_constraint.contype
= 'u'`), bukan indeks unik lepas, karena ia lahir dari `$table->unique([...])`. Resep pada standar app,
`DROP INDEX`, tidak bisa dipakai di sini — constraint hanya bisa dibuang lewat `ALTER TABLE ... DROP
CONSTRAINT`. Sebaliknya penggantinya wajib berupa indeks, bukan constraint, karena PostgreSQL tidak
mengizinkan UNIQUE constraint memiliki klausa `WHERE`. Contoh pada standar app hanya benar untuk tabel
yang keunikannya memang dibuat dengan `CREATE UNIQUE INDEX` sejak awal, seperti kedua modul contoh.

**Nama indeksnya dipakai ulang.** Ini keputusan sendiri, bukan dari PRD. PostgreSQL menyebut pelanggaran
indeks unik biasa maupun parsial dengan kalimat yang sama, `duplicate key value violates unique
constraint "<nama>"`, jadi mempertahankan nama lama membuat pesan yang dilihat pemanggil untuk kasus yang
memang masih harus ditolak — dua baris hidup dengan kode sama — tidak berubah sama sekali.

**Hasil query langkah 2.** Dijalankan pada `erp-core-db-1` (PostgreSQL 16) terhadap kelima schema yang
memiliki tabel `units_of_measure`, sebelum migrasi dipasang:

| Schema | Baris | Terarsip | Bentrok arsip vs hidup | Duplikat hidup |
| --- | --- | --- | --- | --- |
| `public` | 483 | 0 | 0 | 0 |
| `coreerp_test` | 0 | 0 | 0 | 0 |
| `coreerp_t210` | 0 | 0 | 0 | 0 |
| `coreerp_t212` | 0 | 0 | 0 | 0 |
| `coreerp_t300` | 0 | 0 | 0 | 0 |

Tidak ada satu pun baris terarsip, jadi tidak ada bentrok yang perlu diputuskan lebih dulu. Nol itu
memang yang diharapkan, dan alasannya penting: selama keunikan penuh masih berlaku, bentrok semacam itu
mustahil ada karena keunikan itu sendiri yang melarangnya. Angkanya tetap dicatat sebagai hasil
pengukuran, bukan sebagai kesimpulan dari membaca migrasi.

**Kriteria selesainya sudah dibuktikan bisa gagal.** Setelah ketiga test hijau, klausa `WHERE deleted_at
IS NULL` dibuang sehingga indeksnya kembali penuh. Tepat satu test berubah merah — yang mengarsipkan lalu
memakai ulang kodenya — sementara dua test lain tetap hijau. Itu justru yang diinginkan: kedua test lain
memang tidak mengukur keparsialan, jadi test yang mengukurnya tidak sedang dijaga lapisan kedua.
Test-nya juga sengaja membuat baris lewat model langsung, bukan lewat `UnitOfMeasureService` atau request
HTTP, supaya aturan validasi "kode harus unik" tidak menjawab lebih dulu dan menutupi indeksnya.

**`down()` sengaja bisa gagal, dan kegagalannya sudah dijalankan.** Setelah indeks parsial berlaku,
sepasang `(tenant_id, code)` boleh dimiliki satu baris hidup dan satu baris terarsip sekaligus.
Mengembalikan keunikan penuh pada keadaan itu mustahil tanpa memutuskan baris mana yang dibuang, dan itu
bukan keputusan migrasi. Diuji pada schema terpisah dengan sepasang baris seperti itu, `migrate:rollback`
gagal dengan `could not create unique index ... Key (tenant_id, code)=(T1, KG) is duplicated`, dan
gagalnya utuh — indeks parsial tetap terpasang dan baris migrasinya tidak terhapus. Setelah baris
terarsipnya dibuang, rollback yang sama berjalan dan constraint penuh kembali persis seperti semula.

**Pemeriksaan standar app perlu dibaca per tabel, bukan per berkas.** Kedua `grep` pada standar app
mencocokkan berkas, bukan tabel, sehingga `2026_07_30_160000_create_unit_of_measure_tables.php` tetap
muncul di kedua daftar meski pasangan yang salah sudah tidak ada: berkas itu membuat tujuh tabel, dan
`unique(['tenant_id', 'code'])` yang tersisa milik `uom_classes` dan `uom_systems`, yang tidak punya
`deleted_at`. Pemeriksaan yang menentukan dijalankan pada database, lewat `pg_index` dan `pg_constraint`,
bukan lewat `grep`.

**Daftar yang dirujuk langkah 1 tidak ada.** Langkah 1 menyebut "daftar migrasi ber-SQL mentah pada
bagian 5"; daftar seperti itu tidak ada untuk Core. Yang ada di bagian 5 adalah aturan bahwa migration
dikecualikan dari penjaga query mentah, karena ia memang menulis SQL langsung dan berjalan sebelum ada
tenant mana pun. Jadi tidak ada hitungan yang perlu diperbarui.

### F0-04 — `tsc` masuk ke alur linter

**Kenapa.** Berkas TypeScript diperiksa ESLint dan Prettier, tapi tipenya tidak pernah diperiksa mesin
mana pun. Empat kesalahan `TS2322` sudah berdiri di cabang utama dan tidak ada yang menahannya. Fase 4
menyatukan antarmuka modul ke dalam build Core, dan antarmuka modul akan mengimpor tipe dari Core; tanpa
pemeriksaan tipe, ketidakcocokan itu baru terlihat saat halaman dibuka.

**Berkas.**
- `.github/workflows/lint.yml`
- `apps/control-plane/resources/js/components/heading.tsx` atau pemanggilnya

**Langkah.**
1. Perbaiki empat kesalahan yang ada. Putuskan apakah `Heading` memang harus menerima `icon`, lalu
   perbaiki tipenya atau buang prop itu dari keempat pemanggil.
2. Tambahkan langkah `npm run types:check` pada alur linter, setelah berkas Wayfinder dibuat.
3. Buktikan langkah itu bisa gagal dengan satu kesalahan tipe yang sengaja dibuat, lalu kembalikan.

**Selesai bila.** `npm run types:check` hijau di alur, dan sebuah kesalahan tipe yang sengaja
dimasukkan membuat alur merah.

**Rujukan.** F0-01 pada dokumen ini.

**Bergantung pada.** F0-01.

#### Yang ditemukan saat mengerjakannya

Keempat kesalahan itu satu sebab: empat halaman mengoper prop `icon` ke komponen `Heading`, dan `Heading`
tidak menerimanya. Prop itu **tidak pernah dirender**, jadi ikonnya tidak pernah muncul dan tidak ada
yang menyadarinya.

Perbaikannya membuang prop tersebut, bukan menambah dukungan `icon` pada `Heading`. Alasannya bukan
karena lebih sedikit baris: aturan UI pada `AGENTS.md` menyatakan jangan menambahkan ikon pada judul
page atau card. Dari 17 halaman yang memakai `Heading`, hanya 4 yang mengoper `icon`. Menambah dukungan
berarti membuat empat halaman itu berbeda dari tiga belas lainnya, melanggar aturan tertulis, dan
mengubah tampilan yang selama ini tidak pernah berubah.

Ini pola yang layak diingat: **kesalahan tipe yang tidak diperiksa mesin menyembunyikan fitur yang tidak
pernah jalan.** Orang yang menulis keempat halaman itu mengira ikonnya tampil.

### F0-05 — Cabang utama dikunci

**Kenapa.** Alur merah diabaikan berbulan-bulan dan tidak ada yang meninjau. Selama penggabungan tidak
menuntut apa pun, setiap kalimat "selesai bila test lulus" pada dokumen ini hanya harapan. Ini juga yang
membuat F0-01 mungkin terulang: alur bisa merah lagi tanpa ada yang menahan.

**Berkas.** Tidak ada. Ini setelan repo, bukan kode.

**Langkah.**
1. Wajibkan perubahan masuk lewat pull request pada cabang utama.
2. Wajibkan kedua alur hijau sebelum penggabungan: `quality` dari alur linter, serta `ci (8.4)` dan
   `ci (8.5)` dari alur test.
3. Setel jumlah persetujuan yang diwajibkan ke nol, dengan alasan yang ditulis pada bagian 5.7.
4. Jangan mewajibkan setelan ini pada administrator. Pemimpin tim harus tetap bisa keluar sendiri kalau
   ada alur yang rusak karena hal di luar kodenya.

**Selesai bila.** Sebuah pull request dengan alur merah tidak bisa digabungkan lewat antarmuka GitHub,
dan mencoba mendorong langsung ke cabang utama ditolak.

**Rujukan.** Bagian 5.7 dokumen ini.

**Bergantung pada.** F0-01. Mengunci cabang sebelum alurnya hijau berarti mengunci semua orang di luar.

#### Terhalang: fitur ini tidak tersedia pada paket yang dipakai sekarang

Dicoba 7 September 2026. Dua jalan yang disediakan GitHub keduanya ditolak dengan pesan yang sama:

| Yang dicoba | Hasil |
| --- | --- |
| `PUT /repos/:owner/:repo/branches/main/protection` | 403, "Upgrade to GitHub Pro or make this repository public" |
| `POST /repos/:owner/:repo/rulesets` | 403, pesan yang sama |

Sebabnya organisasi `MettaDevs` memakai paket gratis dan repo ini privat. Penguncian cabang pada repo
privat menuntut paket berbayar. Membuat repo ini publik bukan pilihan.

Tiga jalan yang tersisa, dan keputusannya bukan keputusan teknis:

| Jalan | Yang didapat | Yang tidak didapat |
| --- | --- | --- |
| Naik ke GitHub Team | penguncian penuh, persis seperti langkah task ini | biaya bulanan per anggota |
| Hook `pre-push` yang dibagikan di repo | menolak dorongan langsung ke cabang utama dari mesin yang sudah menyetelnya | tidak menghalangi penggabungan pull request merah lewat antarmuka GitHub, dan bisa dilewati siapa pun yang belum menyetel `core.hooksPath` |
| Alur yang gagal keras saat cabang utama merah | pemberitahuan cepat | tidak mencegah apa pun, hanya memberi tahu setelah terjadi |

#### Diputuskan: tidak dikerjakan

Pemilik produk memutuskan 8 September 2026 untuk tidak menaikkan paket dan tidak memasang penambal.
Task ini **ditutup tanpa dikerjakan**, dan nomornya tidak dipakai ulang.

Akibatnya harus dinyatakan terang-terangan supaya tidak ada yang salah mengira: **setiap pemeriksaan
pada dokumen ini bisa dilewati dengan satu klik gabungkan.** Kalimat "selesai bila test lulus" bersandar
pada disiplin orang, bukan pada mesin. Ini keadaan yang sama dengan sebelum proyek dimulai, dan itulah
keadaan yang membuat alur merah bertahan berbulan-bulan.

Yang berubah dibanding sebelumnya cuma satu, tapi bukan hal kecil: alurnya sekarang **benar-benar
hijau**, jadi merahnya berarti sesuatu. Sebelum F0-01 setiap pull request merah, sehingga warna merah
tidak membedakan apa pun dan wajar diabaikan.

## 8. Fase 1: penjaga batas dan kerangka modul

**Kenapa penjaga sebelum kode.** Prinsip P2. Sistem lama gagal karena tidak ada yang menghentikan modul
menyentuh tabel modul lain: 445 tabel disentuh tujuh modul inti, dan sekitar 30 tabel dipakai lima sampai
enam modul sekaligus. Penjaga yang dipasang setelah kode dipindah hanya mengesahkan pelanggaran yang
sudah terjadi.

**Kriteria keluar.** Sebuah modul contoh yang sengaja melanggar aturan membuat CI gagal, dan pesan
gagalnya menyebut aturan mana yang dilanggar.

### F1-01 — Kerangka folder `modules/` dan konvensi

**Kenapa.** Semua task berikutnya menaruh berkas di sini. Tanpa bentuk yang disepakati, tiap modul akan
tumbuh berbeda.

**Berkas.**
- `modules/README.md` (ubah; sekarang masih mewajibkan container per modul)
- `modules/apperp/.gitkeep`

**Langkah.**
1. Tulis ulang isinya: satu modul adalah folder berisi `app.yaml`, `src/`, `database/migrations/`, `ui/`,
   `tests/`, dan `composer.json`.
2. Tetapkan namespace `Modules\<Publisher>\<Modul>\` dan awalan tabel `<modul>_`.
3. Sebutkan yang dilarang ada di dalam modul: `bootstrap/`, `public/`, `config/app.php`, Dockerfile, dan
   berkas compose.

**Selesai bila.** Berkas itu memuat pohon folder lengkap, tabel pemetaan namespace ke awalan tabel, dan
daftar berkas terlarang; dan kalimat tentang container per modul sudah hilang.

**Rujukan.** Bagian 5.1 dokumen ini, [standar app](../../dev/02-module-standard.md).

**Bergantung pada.** F0-02.

#### Yang ditambahkan di luar langkah task ini

**Awalan tabel tidak selalu sama dengan nama folder,** dan itu perlu dinyatakan. `management-aset`
berawalan `aset_`, bukan `management_aset_`. Awalan dipilih pendek dan **tidak boleh berubah** setelah
modul pertama kali dipasang, karena mengubahnya berarti mengganti nama tabel di setiap instalasi
pelanggan. Karena itu tabel pemetaannya berada di `modules/README.md` dan diisi pada pull request yang
membuat modulnya, supaya tabrakan awalan ketahuan saat peninjauan, bukan saat migrasi berjalan.

**Modul contoh tidak boleh sampai ke pelanggan.** Pemilik produk menegaskan ini 8 September 2026.
`contoh-a` dan `contoh-b` hidup di repo sampai fase 7, jadi mereka ada saat bundle edisi dibangun.
Keduanya menandai diri `kind: internal-fixture`, dan F5-04 menolak modul bertanda itu pada edisi mana
pun beserta test yang membuktikannya. Sebuah menu bernama "Contoh A" di layar pelanggan adalah kegagalan
yang tidak boleh mungkin terjadi.

### F1-02 — Dua modul contoh

**Kenapa.** Ketiga penjaga berikutnya menguji sesuatu, dan sesuatu itu harus ada lebih dulu. Pada
rancangan sebelumnya modul contoh dibuat oleh task yang justru bergantung pada penjaga, sehingga tidak
ada yang bisa dikerjakan lebih dulu.

**Berkas.**
- `modules/apperp/contoh-a/` beserta `app.yaml`, satu migration, satu model, `composer.json`
- `modules/apperp/contoh-b/` dengan bentuk yang sama

**Langkah.**
1. Modul A membuat tabel `contoh_a_m_barang`, modul B membuat `contoh_b_m_rak`. Keduanya membawa
   `tenant_id`.
2. Masing-masing punya satu model dan satu rute sederhana.
3. Modul contoh ini hidup sepanjang proyek dan menjadi bahan uji penjaga; jangan dihapus sampai fase 7.
4. Keduanya menandai diri `kind: internal-fixture` pada `app.yaml`. Karena mereka masih ada saat bundle
   edisi dibangun, tanda inilah yang dipakai F5-04 untuk menolaknya.

**Selesai bila.** Kedua folder ada dan migration-nya bisa dijalankan tangan.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F1-01.

#### Kriteria selesai dinaikkan

"Kedua folder ada dan migration-nya bisa dijalankan tangan" tidak bisa gagal karena alasan yang menarik:
folder yang ada tetap ada walau isinya salah. Yang dibuktikan sekarang lebih keras, dan semuanya
dijalankan pada PostgreSQL 16, bukan disimpulkan:

| Yang dibuktikan | Hasil |
| --- | --- |
| Kedua migration jalan lewat `migrate --path` | dua tabel dan dua indeks parsial terbentuk |
| Indeks parsial mengizinkan kode dipakai ulang setelah diarsipkan | baris baru masuk |
| Indeks parsial tetap menolak dua baris hidup berkode sama | `duplicate key value violates unique constraint` |
| `migrate:rollback` mengembalikan keadaan | schema kosong kembali, tanpa indeks tertinggal |

#### Yang ditemukan saat mengerjakannya

**Bagian 5.3 tidak memuat cara modul mengetahui tenant-nya.** Pemetaan di sana disusun dari tiga belas
endpoint HTTP, dan penyelesaian tenant tidak pernah lewat HTTP — app lama membacanya dari token. Padahal
itu hal pertama yang dibutuhkan setiap modul. Barisnya sudah ditambahkan.

**Pint tidak pernah memeriksa `modules/`.** Perintah lint berjalan dari `apps/control-plane`, jadi kode
modul pertama akan masuk tanpa diperiksa siapa pun. `lint:check` kini mencakup `../../modules`, dan sudah
dibuktikan bisa gagal dengan satu berkas berformat kacau.

**PHPStan belum bisa mencakup `modules/`, dan itu bukan kelalaian.** Ia butuh kelas modul dapat dimuat,
dan pemuatan itu baru ada setelah task autoload Composer pada fase 2. Sampai saat itu kode modul
diperiksa Pint saja. Ini harus dibereskan pada F1-08, bukan dibiarkan sampai fase 3.

### F1-03 — Tabel catatan pemasangan modul

**Kenapa.** Core harus tahu modul apa terpasang untuk tenant mana, versinya berapa, apakah sedang aktif,
dan apakah data awalnya sudah pernah diisi. Tabel `apps` yang ada menyimpan katalog, bukan pemasangan.

**Berkas.**
- `apps/control-plane/database/migrations/<baru>_create_core_module_installations_table.php`
- `apps/control-plane/app/Models/ModuleInstallation.php`
- `apps/control-plane/tests/Feature/ControlPlane/ModuleInstallationTest.php`

**Langkah.**
1. Tabel `core_module_installations` berisi `tenant_id`, `module_id`, `version`, `status` bernilai
   `installed`, `disabled`, atau `uninstalled`, ditambah `seeded_at`, `installed_at`, `disabled_at`, dan
   `uninstalled_at`, dengan kunci utama gabungan `tenant_id` dan `module_id`.
2. Kolom `seeded_at` adalah yang mencegah data awal terisi dua kali saat tenant berlangganan ulang. Ini
   sudah diuji: tanpa kolom itu, master bawaan menjadi dobel setiap kali modul diaktifkan kembali.
3. Test memastikan memasang dua kali tidak membuat baris kedua, dan mengaktifkan kembali tidak mengisi
   ulang data awal.

**Selesai bila.** Kedua test lulus.

**Rujukan.** [empat kebenaran lifecycle](../../onboarding/empat-kebenaran.md), bagian 5.2 dokumen ini.

**Bergantung pada.** F0-01.

#### Yang ditemukan saat mengerjakannya

**Statusnya harus tiga, bukan dua.** Task ini menulis `installed` atau `disabled`, sementara task
pencabutan pada fase 2 menyatakan pencabutan "mengubah status pemasangan dan berhenti di situ". Diubah
menjadi apa? Dua kemungkinannya sama-sama salah:

| Kalau pencabutan… | Akibatnya |
| --- | --- |
| menghapus barisnya | `seeded_at` ikut hilang, jadi berlangganan ulang mengisi data awal **di atas data lama yang tidak pernah dihapus** |
| menulis `disabled` | tidak ada bedanya antara dimatikan sementara dan berhenti berlangganan |

Karena itu ada status ketiga, `uninstalled`, dengan `uninstalled_at`, dan barisnya tidak pernah dihapus.
Ini konsekuensi langsung dari keputusan penghapusan lunak pada bagian 5.7 yang belum ada saat task ini
ditulis.

**Status dijaga database, bukan hanya model.** Baris ini ditulis perintah pemasangan, perintah
pencabutan, dan nanti alur pendaftaran tenant. Satu di antaranya menulis status yang salah eja sudah
cukup membuat menu modul hilang tanpa jejak, karena tidak ada yang menolaknya. Sebuah `CHECK` menolaknya
sejak awal.

**Lima test, bukan dua.** Selain dua yang diminta, ditambahkan: pencabutan menyimpan barisnya beserta
jejak data awal, dua tenant yang memasang modul yang sama berdiri sendiri, dan status di luar tiga yang
sah ditolak database. Ketiganya menjaga janji yang baru saja diputuskan dan belum punya penjaga.

Sudah dibuktikan bisa gagal: perintah pemasangan dibuat cacat sengaja supaya menulis ulang `seeded_at`,
dan test yang benar merah dengan pesan yang benar.

### F1-04 — Penjaga pertama: migration modul hanya membuat tabel berawalan miliknya

**Kenapa.** Prinsip P1. Pelanggaran di lapisan migration tidak terlihat sampai datanya sudah telanjur
bercampur, dan sesudah itu memisahkannya berarti menebak-nebak. Itu yang terjadi pada sistem lama.

**Berkas.**
- `apps/control-plane/app/Support/Modules/TableOwnershipInspector.php`
- `apps/control-plane/tests/Feature/Boundary/ModuleTableBoundaryTest.php`

**Langkah.**
1. Pemeriksa membandingkan daftar tabel sebelum dan sesudah migration satu modul dijalankan, lalu
   melaporkan tabel yang lahir tanpa awalan modul itu.
2. Test menjalankan migration tiap modul terdaftar pada database bersih, lalu memastikan tidak ada tabel
   baru yang berawalan `core_` atau awalan modul lain.
3. Pengecualian yang diizinkan ditulis langsung di berkas test, bukan di konfigurasi, supaya pengecualian
   baru terlihat pada diff.

**Selesai bila.** Test lulus untuk kedua modul contoh, dan gagal menyebut nama tabel bila sebuah migration
diubah untuk membuat tabel berawalan modul lain.

**Rujukan.** Prinsip P1 dan bagian 5.2 dokumen ini.

**Bergantung pada.** F1-02, F1-03.

#### Catatan pelaksanaan

Penjaganya dua test, bukan satu. Yang pertama menjalankan migration tiap modul yang ditemukan lalu
memeriksa tabel yang lahir. Yang kedua menguji **pemeriksanya sendiri** dengan daftar tabel buatan, tanpa
menyentuh database, supaya pesan kesalahannya diketahui benar sebelum ia dipakai.

Satu asersi tambahan yang mudah terlewat: test menolak modul yang migration-nya tidak membuat tabel apa
pun. Tanpa itu, sebuah modul dengan folder migration kosong akan membuat penjaga ini hijau tanpa menguji
apa pun — persis pola kriteria selesai yang tidak bisa gagal pada bagian 4.5.

Sudah dibuktikan bisa gagal: migration modul contoh A diubah membuat tabel `contoh_b_m_curian`, dan
penjaganya merah sambil menyebut nama tabelnya beserta awalan yang sah.

Pemindaian modul untuk sementara hidup di dalam berkas test. Registry modul yang sebenarnya dibuat pada
F2-01; saat itu pemindaian di sini diganti dengannya, dan jangan dibiarkan menjadi salinan kedua.

### F1-05 — Penjaga kedua: namespace modul tidak boleh saling impor

**Kenapa.** Batas tabel saja tidak cukup. Modul bisa memanggil kelas modul lain lewat PHP walau tabelnya
terpisah, dan itu membuat modul tidak bisa dicabut sendirian.

**Berkas.**
- `apps/control-plane/tests/Feature/Boundary/ModuleNamespaceBoundaryTest.php`
- `apps/control-plane/phpstan.neon` (folder `modules/` masuk ke `paths` dan `scanDirectories`)

**Langkah.**
1. Aturan memeriksa setiap nama kelas yang dirujuk dari dalam `Modules\<A>\` dan menolak yang berawalan
   `Modules\<B>\`.
2. Taruh berkasnya di bawah `tests/`, bukan `app/`. Analisa statis adalah dependensi pengembangan
   sementara image production dibangun tanpa dependensi itu, jadi kelas yang mewarisi antarmukanya akan
   merujuk kelas yang tidak ada di image.
3. Tambahkan folder `modules/` ke daftar `paths` pada berkas konfigurasi analisa statis, dan pastikan
   kelas modul dapat dimuat lewat pemindaian direktori.

**Selesai bila.** Penjaga lulus pada kode yang ada, dan gagal bila modul contoh A sengaja mengimpor kelas
modul contoh B.

**Rujukan.** Prinsip P1 dokumen ini.

**Bergantung pada.** F1-02.

#### Aturan PHPStan ditulis lebih dulu, lalu dibuang

Task ini menyuruh menulis aturan PHPStan. Aturannya ditulis, dijalankan, dan **berlubang**. PHPStan hanya
mengunjungi nama kelas pada posisi tertentu: pada berkas contoh hanya tiga nama yang sampai ke aturan,
ketiganya tipe argumen. Baris `use`, pemanggilan statis, dan nama kelas di dalam string tidak pernah
sampai — padahal ketiganya justru jalur yang paling mudah dipakai menembus batas.

Penggantinya membaca berkas dengan pencocokan pola. Terdengar lebih kasar, tapi **menangkap lebih
banyak**: impor, pemanggilan statis, nama di dalam string, dan bahkan di dalam komentar. Yang terakhir
bukan berlebihan — sebuah `@return \Modules\Apperp\Lain\Kelas` pada docblock adalah rujukan tipe yang
dibaca alat, bukan sekadar tulisan.

Folder `modules/` tetap dimasukkan ke daftar `paths` PHPStan, karena kode modul memang perlu diperiksa
tipenya. Yang dibuang hanya aturan buatan sendiri itu.

#### Jebakan yang memakan waktu paling lama, dan harus diketahui semua orang

**PHPStan menyimpan hasil analisa, dan mengubah berkas aturan buatan sendiri tidak membatalkan
simpanan itu.** Aturannya sudah terpasang dan sudah berjalan sejak awal, tetapi setiap kali kodenya
diubah, PHPStan menyajikan hasil lama dan melaporkan nol temuan. Itu terbaca persis seperti "aturannya
tidak jalan", dan waktu habis mencari kesalahan yang tidak ada.

Cara memastikannya: hapus foldernya secara paksa, jangan hanya memanggil perintah pembersihnya.

```bash
rm -rf "$TEMP/phpstan"
```

Ini masuk ke keluarga yang sama dengan bagian 4.5. Sebuah penjaga yang melaporkan hijau karena
simpanan lama sama tidak bergunanya dengan penjaga yang tidak pernah dipasang.

#### Penjaganya menangkap pelanggaran yang tidak disengaja

Selain pelanggaran yang sengaja dibuat untuk mengujinya, penjaga ini langsung menemukan satu yang nyata:
docblock pada modul contoh B menyebut namespace modul contoh A secara harfiah, sebagai contoh hal yang
dilarang. Kalimatnya diubah. Sebuah penjaga yang menemukan sesuatu pada hari pertama adalah penjaga
yang menguji sesuatu.

### F1-06 — Penjaga ketiga: tidak ada query modul tanpa penyaringan tenant

**Kenapa.** Pada penempatan gabungan, satu query yang lupa menyaring tenant membocorkan data seluruh
pelanggan. Ini sudah dibuktikan pada simulasi: satu perintah tanpa penyaringan mengembalikan baris milik
semua tenant. Database tidak bisa mencegahnya, jadi pemeriksaan yang harus.

**Berkas.**
- `apps/control-plane/tests/Feature/Boundary/TenantScopeBoundaryTest.php`
- `apps/control-plane/app/Support/Modules/TenantScope.php`

**Langkah.**
1. Model modul memakai global scope yang menyisipkan penyaringan tenant dari konteks permintaan.
2. Test membuat dua tenant berisi data, lalu memastikan permintaan atas nama satu tenant tidak pernah
   mengembalikan baris tenant lain, termasuk pada rute daftar, detail, dan laporan.
3. Test kedua memastikan query builder mentah tanpa penyaringan tenant tertangkap, dengan memeriksa
   berkas modul untuk pemanggilan tabel modul yang tidak melewati scope.

**Selesai bila.** Kedua test lulus, dan test kedua gagal bila sebuah query sengaja dibuat tanpa
penyaringan.

**Rujukan.** [query scope dan schema](../../dev/08-query-scopes-and-schema.md), bagian 5.2 dokumen ini.

**Bergantung pada.** F1-02, dan langkah 1–2 F2-02 yang ditarik ke sini.

#### Scope gagal menutup, bukan gagal membuka

Keputusan yang tidak disebut task ini tapi menentukan segalanya: bila tenant aktif tidak diketahui,
query **dibatalkan dengan pengecualian**, bukan dijalankan tanpa penyaringan.

Pilihan sebaliknya terlihat lebih ramah dan justru paling berbahaya. Sebuah pekerjaan latar yang lupa
menyetel konteks akan membaca data semua orang tanpa satu pun tanda bahaya, dan hasilnya terlihat wajar
sampai ada yang menyadarinya berbulan-bulan kemudian.

#### Langkah 1 dan 2 F2-02 ditarik ke sini

Test model tidak bisa berjalan tanpa kelas modul dapat dimuat, dan itu tugas F2-02. Yang ditarik hanya
bagian autoload-nya: repositori bertipe `path` menunjuk `../../modules/*/*`, dan tiap modul
mendeklarasikan `autoload.psr-4` sendiri. Bagian pemindahan konteks pembangunan image **tetap di F2-02**,
karena ia menyentuh Dockerfile dan compose yang tidak ada hubungannya dengan penjaga ini.

Dua catatan untuk yang mengerjakannya nanti:

- Paket lokal harus diminta dengan `@dev`, bukan `*`. Dengan `*` Composer menolaknya karena tidak memenuhi
  `minimum-stability`, dan pesannya tidak menyebutkan itu dengan jelas.
- Jalankan `composer update` dengan `--no-scripts`. Tanpa itu, `post-update-cmd` memanggil
  `install:features` dan mengubah tiga belas berkas yang tidak ada hubungannya dengan pekerjaan ini.
  Ini sudah pernah terjadi pada F0-01.

#### Penjaganya dua lapis, karena satu lapis bisa dilewati

| Lapis | Menangkap |
| --- | --- |
| Global scope pada model | query lewat model, termasuk `find()` dengan id milik tenant lain |
| Pembacaan berkas modul | `DB::table(`, `DB::select(`, dan `DB::statement(` yang melewati model |

Lapis kedua ada karena lapis pertama hanya berlaku bila query memang lewat model. Query mentah melewati
global scope tanpa memberi tanda apa pun. Migration dikecualikan: ia memang menulis SQL langsung, dan ia
berjalan sebelum ada tenant mana pun.

Keduanya sudah dibuktikan bisa gagal.

### F1-07 — Buktikan ketiga penjaga bisa gagal

**Kenapa.** Pemeriksa yang belum pernah terlihat gagal tidak bisa dipercaya. Repo ini sudah punya
catatannya sendiri: sebuah pemeriksa cakupan tabel pernah melaporkan sukses justru karena rusak, dan
laporan sukses palsu menghentikan pencarian.

**Berkas.**
- `docs/todo/satu-runtime/02-bukti-penjaga.md` (baru)
- modul contoh, diubah sementara lalu dikembalikan

**Langkah.**
1. Ubah modul A supaya migration-nya membuat tabel berawalan modul B. Jalankan penjaga pertama, catat
   pesan gagalnya, lalu kembalikan.
2. Ubah modul A supaya mengimpor model modul B. Jalankan analisa statis, catat pesan gagalnya, lalu
   kembalikan.
3. Buat satu query modul tanpa penyaringan tenant. Jalankan penjaga ketiga, catat pesan gagalnya, lalu
   kembalikan.
4. Tempelkan ketiga pesan gagal itu ke berkas bukti. Jangan menempelkannya ke dokumen ini, supaya
   dokumen rencana tidak berubah setiap kali penjaga disentuh.

**Selesai bila.** Tiga pesan gagal tercatat pada berkas bukti, dan ketiga pemeriksa hijau kembali.

**Rujukan.** Prinsip P2 dokumen ini.

**Bergantung pada.** F1-04, F1-05, F1-06.

### F1-08 — Penjaga berjalan di CI

**Kenapa.** Penjaga yang hanya jalan di laptop akan terlewat pada pull request pertama yang terburu-buru.

**Berkas.**
- `.github/workflows/tests.yml`
- `.github/workflows/lint.yml`

**Langkah.**
1. Suite `Boundary` masuk ke alur test.
2. Analisa statis sudah mencakup folder `modules/` setelah F1-05; pastikan demikian. Perhatikan bahwa
   analisa statis berjalan pada alur **test**, bukan alur linter, meski namanya "Run Type Analysis".
3. Pint juga wajib mencakup `modules/`, ditambahkan pada F1-02.
4. Semua langkah wajib, bukan `continue-on-error`.

**Selesai bila.** Sebuah pull request percobaan yang melanggar salah satu batas ditolak CI.

**Rujukan.** [CI/CD](../../dev/22-ci-cd.md), [bukti penjaga](02-bukti-penjaga.md).

**Bergantung pada.** F0-01, F1-07.

#### Tidak ada berkas alur yang perlu diubah

Ketiga penjaga adalah test PHPUnit biasa di bawah `tests/Feature/Boundary/`, jadi `php artisan test`
sudah menjalankannya. Folder `modules/` sudah masuk ke Pint pada F1-02 dan ke PHPStan pada F1-05.
Task ini karena itu tidak mengubah alur sama sekali; ia **membuktikan** yang sudah ada bekerja.

Itu justru menjadikannya task yang paling mudah dianggap selesai tanpa bukti. Karena itu buktinya
dijalankan sungguhan, bukan disimpulkan dari membaca berkas alur.

#### Buktinya: sebuah pull request yang melanggar, ditolak CI

Cabang percobaan dibuat dengan satu pelanggaran — modul contoh A mengimpor model modul contoh B — lalu
didorong sebagai pull request. Keduanya merah:

| Alur | Yang menolak |
| --- | --- |
| tests | `ModuleNamespaceBoundaryTest` gagal pada kedua versi PHP |
| linter | Pint menolak impor yang tidak dipakai |

Pull request itu ditutup dan cabangnya dihapus. Yang perlu dicatat: **penjaga batas dan pemeriksa gaya
menangkap pelanggaran yang sama dari dua arah berbeda**, dan itu bukan pemborosan — Pint hanya
menangkapnya karena impornya kebetulan tidak dipakai. Impor yang dipakai lolos dari Pint dan hanya
tertahan penjaga batas.

Jumlah test di CI sama dengan di mesin pengembang, 211 test dan 972 asersi pada PHP 8.4 maupun 8.5,
sesuai syarat yang ditetapkan F0-01.

#### Satu hal yang ditemukan dan bukan tentang penjaga

Pull request percobaan itu awalnya **tidak memicu alur sama sekali**, dan halamannya hanya kosong. Sebuah
commit kosong menyusul membuatnya berjalan. Penyebabnya tidak dapat dipastikan dari luar; yang bisa
dipastikan adalah gejalanya, dan gejalanya berbahaya: pull request tanpa pemeriksaan **terlihat sama**
dengan pull request yang pemeriksaannya belum selesai. Selama cabang utama tidak dikunci (F0-05 ditutup
tanpa dikerjakan), tidak ada yang menahan pull request seperti itu digabungkan.

Kalau ini terulang, dorong satu commit kosong dan periksa lagi sebelum menggabungkan.

## 9. Fase 2: Core menjadi tuan rumah modul

**Kriteria keluar.** Modul contoh berisi satu tabel dapat dipasang untuk satu tenant, muncul di menu,
diisi data, dinonaktifkan, diaktifkan lagi tanpa data awalnya terisi dua kali, lalu dicabut tanpa
menyentuh modul lain maupun tenant lain.

### F2-01 — Pemuat modul

**Kenapa.** Core harus menemukan modul dari folder, bukan dari daftar yang ditulis tangan. Daftar yang
ditulis tangan adalah berkas pusat yang diperebutkan banyak orang, dan itu salah satu penyakit sistem
lama.

**Berkas.**
- `apps/control-plane/app/Support/Modules/ModuleRegistry.php`
- `apps/control-plane/app/Support/Modules/ModuleManifest.php`
- `apps/control-plane/app/Providers/ModuleServiceProvider.php`
- `apps/control-plane/app/Console/Commands/ModuleListCommand.php`
- `apps/control-plane/bootstrap/providers.php`
- `apps/control-plane/tests/Feature/ControlPlane/ModuleRegistryTest.php`

**Langkah.**
1. Registry memindai `modules/*/*/app.yaml` dan memuat manifest dengan pustaka YAML yang sudah dipakai
   perintah pendaftaran manifest hari ini.
2. Penyedia layanan mendaftarkan penyedia layanan milik tiap modul, bukan langsung route dan view-nya.
   Modul yang mendaftarkan penyedia rute sendiri diketahui menjadi penyebab masalah kecepatan pada paket
   modul Laravel yang beredar, jadi rute dimuat dari satu penyedia per modul.
3. Modul dengan `id: change-me` dilewati, sama seperti yang dilakukan skrip pengembangan hari ini. Skrip
   itu berada di repo lain, yaitu `erp-dev`, dan memindai folder saudara, bukan `modules/`.
4. Test memastikan registry menemukan dua modul contoh dan mengabaikan yang `change-me`.

**Selesai bila.** `php artisan module:list` menampilkan kedua modul contoh beserta versinya.

**Rujukan.** [standar app, manifest](../../dev/02-module-standard.md).

**Bergantung pada.** F1-02.

#### Manifest menjadi objek, bukan array

Registry mengembalikan `ModuleManifest`, bukan array asosiatif. Bedanya terasa di tempat yang tidak
terlihat sekarang: setiap pemakai manifest — perintah pasang, penentu kesiapan, pembangun edisi —
akan menanyakan hal yang sama, dan array asosiatif membuat setiap pemakai menebak nama kuncinya sendiri
lalu gagal diam-diam saat kuncinya salah eja.

Objek itu **tidak** menyalin seluruh manifest. Entry point, permission, privilege, duty, referensi
nomor, dan tipe workflow tetap dibaca aksi pendaftaran katalog yang sudah ada. Yang disalin hanya yang
dibutuhkan untuk menemukan, menamai, dan memuat modulnya.

#### Manifest rusak dilewati, bukan menjatuhkan runtime

Tidak diminta task ini, tapi diputuskan saat menulisnya: sebuah `app.yaml` yang tidak bisa dibaca
membuat modulnya diabaikan, bukan membuat seluruh aplikasi gagal menyala. Satu modul yang salah ketik
tidak boleh mematikan modul lain milik pelanggan yang sama.

Konsekuensinya harus disebut supaya tidak menjadi jebakan: modul yang manifestnya rusak **hilang tanpa
pesan**. Perintah `module:list` ada justru untuk itu — ia menjawab "apakah runtime melihat modul saya"
tanpa perlu membuka halaman dan menunggu 404.

#### Penyedia layanan per modul, bukan rute langsung

Penyedia layanan pusat mendaftarkan penyedia milik tiap modul, dan berhenti di situ. Modul yang belum
punya penyedia dilewati tanpa suara; modul contoh memang belum punya pada fase ini.

Alasannya bukan selera. Bila penyedia pusat memuat rute setiap modul, jumlah pekerjaan saat menyalakan
aplikasi tumbuh seiring jumlah modul, dan itu keluhan yang berulang pada paket modul Laravel yang
beredar. Satu penyedia per modul membuat modul memutuskan sendiri apa yang perlu dimuat.

### F2-02 — Autoload modul lewat Composer

**Kenapa.** Kelas modul harus bisa dimuat tanpa menambahkan setiap namespace ke berkas Composer Core
secara manual.

**Berkas.**
- `apps/control-plane/composer.json`
- `modules/apperp/contoh-a/composer.json`
- `apps/control-plane/Dockerfile`
- `erp-dev/compose.yaml`

**Langkah.**
1. Tambahkan repositori bertipe `path` yang menunjuk `../../modules/*/*`.
2. Tiap modul mendeklarasikan `autoload.psr-4` untuk namespace-nya sendiri.
3. Konteks pembangunan image hari ini adalah folder `apps/control-plane`, sehingga folder `modules/`
   berada di luar jangkauannya dan pemasangan dependensi berjalan sebelum modul disalin. Pindahkan
   konteks pembangunan ke akar repo dan sesuaikan seluruh jalur relatif pada Dockerfile serta berkas
   compose yang menunjuknya.
4. Jalankan pembangunan image sampai selesai, bukan hanya `composer update` di mesin pengembang.

**Selesai bila.** Kelas `Modules\Apperp\ContohA\...` dapat dipanggil dari tinker, **dan** image berhasil
dibangun.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F2-01.

#### Langkah 1 dan 2 sudah dikerjakan pada F1-06

Autoload lewat repositori path ditarik ke fase 1 karena test penjaga tenant tidak bisa berjalan tanpa
kelas modul dapat dimuat. Yang dikerjakan di sini adalah langkah 3 dan 4, yaitu image-nya.

#### Image menirukan susunan repo, bukan menyalin modul dua kali

Ini keputusan yang menentukan dan tidak disebut task ini. Composer memasang modul lewat tautan simbolik
`vendor/apperp/contoh-a -> ../../../../modules/apperp/contoh-a`, dan tautan itu hanya sah bila jarak
antara `vendor` dan `modules` di dalam image sama dengan jarak keduanya di repo.

Dua jalan lain sempat dipertimbangkan dan ditolak:

| Jalan | Kenapa ditolak |
| --- | --- |
| Composer menyalin modul ke `vendor` alih-alih menaut | kode modul ada dua salinan di dalam image, dan menyunting salah satunya tidak mengubah yang lain |
| Menaruh `modules/` di tempat yang kebetulan cocok dengan hitungan tautan | bekerja karena kebetulan, dan berhenti bekerja begitu ada yang memindahkan folder |

Karena itu image memakai `/repo/apps/control-plane` dan `/repo/modules`, persis seperti repo. Akar
dokumen Apache dan tiga jalur pada skrip masuk container ikut disesuaikan.

#### Dibuktikan di dalam image, bukan di mesin pengembang

```
lrwxrwxrwx contoh-a -> ../../../../modules/apperp/contoh-a/
lrwxrwxrwx contoh-b -> ../../../../modules/apperp/contoh-b/

php artisan module:list      -> kedua modul tampil
class_exists(Modules\Apperp\ContohA\Models\Barang) -> true
```

#### Yang belum bisa dipastikan

Mode muat-ulang-panas pada skrip pengembangan memasang folder app dari mesin pengembang ke dalam
container, termasuk `vendor`. Di Windows, tautan modul di dalam `vendor` adalah reparse point, dan
apakah Docker menerjemahkannya dengan benar **belum diuji**. Folder `modules` kini ikut dipasang supaya
perubahannya langsung terlihat, tapi bila mode itu bermasalah, jalankan stack tanpa muat-ulang-panas
sampai ada yang memeriksanya.

#### Berkas di repo `erp-dev` tidak ikut di-commit

Repo itu punya perubahan yang belum di-commit milik pemiliknya, dan mencampurnya dengan pekerjaan ini
akan menyulitkan keduanya. Yang diubah di sana: konteks pembangunan pada `compose.yaml`, dua pemasangan
volume penyimpanan, dan tiga pemasangan volume pada mode muat-ulang-panas di `start.ps1`.

### F2-03 — Migrator per modul

**Kenapa.** Migration modul harus bisa dijalankan sendiri, per tenant, dan riwayatnya dicatat terpisah
supaya menjalankan ulang tidak mengulang yang sudah jalan.

**Berkas.**
- `apps/control-plane/app/Support/Modules/ModuleMigrator.php`
- `apps/control-plane/app/Support/Modules/ModuleMigrationRepository.php`
- `apps/control-plane/app/Console/Commands/ModuleMigrateCommand.php`
- `apps/control-plane/tests/Feature/ControlPlane/ModuleMigratorTest.php`

**Langkah.**
1. Migrator menjalankan migration dari folder modul dengan tabel riwayat `core_module_migrations` yang
   membawa kolom `module_id`, bukan tabel `migrations` bawaan. Kelas repositori migration Laravel
   menerima nama tabel pada konstruktornya, jadi ini tidak butuh penambalan.
2. Perintah `module:migrate {modul} {--tenant=}`. Pada penempatan gabungan, tabel dibuat sekali dan
   opsi tenant hanya menentukan pencatatan; pada penempatan terpisah, migrator menjalankannya di
   database tenant itu.
3. Test memastikan menjalankan dua kali tidak mengulang migration yang sama, dan tabel `migrations`
   milik Core tidak bertambah.

**Selesai bila.** Migration modul contoh membuat tabel berawalan modul, terbukti lewat query ke
`information_schema`, dan penjaga F1-04 tetap hijau.

**Rujukan.** Bagian 5.2 dokumen ini.

**Bergantung pada.** F1-04, F2-02.

#### Nama tabel saja tidak cukup; riwayatnya harus disaring per modul

Task ini menyebut tabel riwayat terpisah dengan kolom `module_id`. Kolomnya ada, tapi kolom saja tidak
menyelesaikan apa pun bila pembacanya tidak menyaringnya.

Nama berkas migration mengikuti pola waktu dan maksud, misalnya
`2026_09_08_000100_create_m_barang_table`. Dua modul yang ditulis orang berbeda **mudah** menghasilkan
nama yang sama persis. Tanpa penyaringan `module_id` saat membaca riwayat, migration modul B akan
terlihat sudah pernah jalan hanya karena modul A punya berkas bernama sama, dan tabelnya tidak pernah
dibuat. Gejalanya adalah tabel yang hilang tanpa pesan kesalahan apa pun.

Karena itu repositori riwayatnya adalah kelas tersendiri yang menyaring `module_id` pada setiap
pembacaan, bukan sekadar tabel dengan kolom tambahan.

#### Kenapa riwayat modul tidak boleh menumpang tabel `migrations`

Bila menumpang, mencabut sebuah modul lalu memasangnya lagi akan **melewati seluruh migration-nya**,
karena riwayatnya masih tercatat di sana, dan tabelnya tidak pernah dibuat ulang. Ini akan muncul
persis saat pelanggan berlangganan kembali — waktu terburuk untuk menemukannya.

#### Arti opsi tenant berbeda pada dua bentuk penempatan

Perlu ditulis karena mudah disalahpahami sebagai "membuat tabel per tenant":

| Penempatan | Yang dilakukan opsi tenant |
| --- | --- |
| Gabungan, bawaan | tabelnya sudah ada untuk semua tenant; opsi ini hanya menandai untuk siapa pemasangan dicatat |
| Terpisah | migration benar-benar dijalankan di database tenant itu |

#### Enam test

Empat di luar yang diminta, termasuk yang menjaga hal yang baru saja diputuskan: riwayat dua modul tidak
saling menutupi, perintah menolak modul yang tidak dikenal, dan tabel `migrations` milik Core tidak
bertambah satu baris pun.

### F2-04 — Data awal modul, sekali saja

**Kenapa.** Modul membawa master bawaan, misalnya kelompok aset atau satuan. Data itu harus terisi saat
modul dipasang untuk sebuah tenant, dan **tidak boleh terisi lagi** saat modul diaktifkan kembali setelah
sempat dinonaktifkan. Tanpa penjaga, master bawaan menjadi dobel setiap kali pelanggan berlangganan
ulang; ini sudah dibuktikan pada simulasi.

Task ini menggantikan rencana peran database per modul, yang dibatalkan karena alasan pada bagian 5.2.

**Berkas.**
- `apps/control-plane/app/Support/Modules/ModuleSeeder.php`
- `apps/control-plane/tests/Feature/ControlPlane/ModuleSeedTest.php`
- `modules/apperp/contoh-a/database/seeders/`

**Langkah.**
1. Seed milik modul hanya dipanggil oleh pemasangan modul, tidak pernah oleh `db:seed` global. Seed
   modul lain tidak boleh ikut terpanggil.
2. Sebelum menjalankan seed, periksa `seeded_at` pada catatan pemasangan. Bila sudah terisi, lewati.
   Setelah berhasil, isi kolom itu.
3. Baris yang dihasilkan seed diberi penanda bawaan, supaya dapat dibedakan dari data buatan pengguna
   saat modul dicabut atau saat dilakukan pemulihan.
4. Test membuktikan tiga hal: memasang modul A tidak mengisi data modul B; menonaktifkan lalu
   mengaktifkan lagi tidak menambah baris bawaan; data buatan pengguna selamat sepanjang rangkaian itu.

**Selesai bila.** Ketiga test lulus.

**Rujukan.** Bagian 5.2 dokumen ini.

**Bergantung pada.** F1-03, F2-03.

#### Penanda bawaan menuntut satu kolom, dan itu bagian dari standar

Langkah 3 meminta baris hasil seed diberi penanda. Penandanya adalah kolom `bawaan` pada tabel master
modul, ditambahkan lewat migration tersendiri pada kedua modul contoh.

Alasan kolom, bukan tebakan dari tanggal: tanpa penanda, sebuah pemulihan atau pembersihan tidak punya
cara memisahkan baris bawaan dari baris yang diketik pengguna selain menebak, dan menebak berarti suatu
saat membuang data pelanggan. Kolom ini seharusnya menjadi bagian standar tabel master modul, bukan
milik modul contoh saja.

#### Seed dijalankan dengan tenant aktif dipasang sementara

Model modul disaring `TenantScope`, dan scope itu **membatalkan** query yang berjalan tanpa tenant aktif.
Itu memang perilaku yang diinginkan, tapi berarti seeder tidak bisa berjalan begitu saja: pemasang
menaruh tenant aktif ke wadah selama seed berlangsung, lalu mengembalikannya. Seeder dengan demikian
memakai model biasa dan ikut tersaring, bukan menulis lewat query mentah yang melewati penjaga.

#### Lima test, dua di luar yang diminta

Selain tiga yang diminta: baris bawaan terbukti bisa dibedakan dari baris pengguna, dan seed **tidak
dijalankan sama sekali** bila belum ada catatan pemasangan. Yang kedua menutup lubang yang halus — tanpa
catatan pemasangan tidak ada tempat menandai bahwa data awal sudah diisi, jadi menjalankannya berarti
mengisi ulang setiap kali dipanggil.

Rangkaian terpanjang menguji urutan yang paling mungkin terjadi pada pelanggan sungguhan: pasang, isi
data sendiri, nonaktifkan, cabut, pasang lagi. Data pengguna tetap satu baris, baris bawaan tetap dua.

#### Satu jebakan penamaan

`Illuminate\Foundation\Testing\TestCase` sudah memiliki metode `seed()`. Sebuah metode pembantu
bernama sama pada kelas test gagal fatal, bukan sekadar membingungkan.

### F2-05 — Perintah pasang, nonaktifkan, dan cabut

**Kenapa.** Ini fitur produk yang menjadi alasan seluruh proyek: modul dapat dipasang dan dicabut per
tenant.

**Berkas.**
- `apps/control-plane/app/Actions/Modules/InstallModule.php`
- `apps/control-plane/app/Actions/Modules/DisableModule.php`
- `apps/control-plane/app/Actions/Modules/UninstallModule.php`
- `apps/control-plane/app/Console/Commands/ModuleInstallCommand.php`
- `apps/control-plane/app/Console/Commands/ModuleDisableCommand.php`
- `apps/control-plane/app/Console/Commands/ModuleUninstallCommand.php`
- `apps/control-plane/app/Support/AppDependencyGraph.php`
- `apps/control-plane/tests/Feature/ControlPlane/ModuleLifecycleTest.php`

**Langkah.**
1. Pemasangan memeriksa dependency dari manifest, menjalankan migration bila perlu, memanggil seed sesuai
   F2-04, mencatat pada `core_module_installations`, lalu mendaftarkan manifest lewat aksi katalog yang
   sudah ada.
2. Penonaktifan hanya mengubah status dan mengisi `disabled_at`. Data tidak disentuh sama sekali. Menu
   hilang karena shell hanya membaca modul berstatus terpasang.
3. Pencabutan **tidak punya opsi penghapusan data**, sesuai bagian 5.7. Ia mengubah status pemasangan
   dan berhenti di situ. Kalau nanti ada yang menambahkan opsi itu, ia sedang melanggar keputusan
   tertulis, bukan menambah fitur.
4. Menolak mencabut modul yang masih menjadi dependency modul lain yang terpasang pada tenant yang sama.
   Kelas graf dependency yang ada hanya punya penelusuran maju dan berskala katalog; tambahkan
   penelusuran balik yang dipotong dengan catatan pemasangan tenant itu.
5. Test menjalankan seluruh rangkaian: pasang dua modul, isi data, nonaktifkan satu, buktikan datanya
   utuh dan modul lain tidak terpengaruh, aktifkan lagi, buktikan data awal tidak dobel, lalu cabut dan
   buktikan datanya **masih ada** serta modul lain dan tenant lain tetap utuh.

**Selesai bila.** Test rangkaian itu lulus, dan perintah pencabutan tidak memiliki opsi penghapusan data
dalam bentuk apa pun.

**Rujukan.** [release dan on-prem](../../dev/03-release-and-on-prem.md), bagian 5.2 dokumen ini.

**Bergantung pada.** F2-04.

#### Bug yang ditemukan test, bukan diperkirakan sebelumnya

`ModuleInstallation` berkunci gabungan, jadi ia tidak punya primary key tunggal. Akibatnya
`$model->fresh()` **mengembalikan baris yang salah tanpa satu pun peringatan**: ia membangun query dari
primary key yang tidak ada. Aksi penonaktifan dan pencabutan sempat memakainya, dan hanya satu dari
tujuh test yang menangkapnya — yang lain memeriksa status lewat query langsung dan lolos.

Pelajarannya untuk seluruh proyek: **model berkunci gabungan tidak boleh memakai pembantu Eloquent yang
bersandar pada primary key.** Selain `fresh()`, itu termasuk `refresh()`, `find()`, dan `save()` pada
model yang sudah ada.

#### Pemasangan aman dijalankan dua kali

Memasang modul yang sudah terpasang bukan kesalahan. Ia mengembalikan status ke terpasang tanpa
menyentuh data dan tanpa mengisi ulang data awal. Ini bukan kenyamanan: pembaruan on-prem dijalankan
admin pelanggan dengan tangan (bagian 5.7), dan perintah yang meledak bila diulang akan diulang juga,
lalu ditinggal setengah jalan.

#### Dependency diperiksa terhadap tenant, bukan terhadap katalog

Katalog tahu modul mana bergantung pada modul mana, tapi itu bukan pertanyaannya. Pertanyaannya: adakah
modul yang **tenant ini** pakai dan akan rusak bila modul ini dicabut. Modul yang bergantung tapi tidak
dimiliki tenant ini tidak menghalangi apa pun, dan ada test tersendiri untuk itu.

#### Ketiadaan opsi hapus data diuji, bukan hanya dijanjikan

Satu test membaca definisi perintah pencabutan dan menolak setiap opsi yang namanya mengandung `purge`,
`delete`, `drop`, atau `hapus`. Sebuah janji di dalam komentar tidak menahan siapa pun; test ini menahan.

#### Tujuh test

Rangkaian penuh dijalankan sebagai satu test, bukan dipecah per aksi, karena kesalahannya justru muncul
di sambungan antar aksi: pasang dua modul untuk dua tenant, ketik data sendiri, nonaktifkan satu,
aktifkan lagi, cabut. Setiap langkah memeriksa modul lain dan tenant lain ikut tidak terpengaruh.

### F2-06 — Modul terpasang menggantikan kesiapan penempatan

**Kenapa.** Rute halaman app memanggil pemeriksaan yang menuntut baris penempatan container dengan
status siap, dan menu juga disaring lewat jalur yang sama. Setelah tidak ada container per app, penentu
itu selalu gagal, sehingga **setiap halaman modul akan 404 untuk semua orang**. Tanpa task ini, dua fase
berikutnya tidak bisa dibuktikan.

**Berkas.**
- `apps/control-plane/app/Support/LaunchableAppCatalog.php`
- `apps/control-plane/routes/web.php`
- `apps/control-plane/app/Http/Middleware/HandleInertiaRequests.php`
- `apps/control-plane/resources/js/components/product-launcher.tsx`
- `apps/control-plane/tests/Feature/ControlPlane/BusinessOnboardingTest.php`

**Langkah.**
1. Penentu kesiapan membaca `core_module_installations` berstatus terpasang, bukan penempatan container.
2. Untuk app yang belum dipindah dan masih berjalan sebagai container, jalur lama dipertahankan. Kedua
   jalur hidup berdampingan sampai app terakhir dipindah.
3. Peluncur produk dan properti bersama yang menyusun tautan app ikut menyesuaikan.
4. Test onboarding yang memeriksa jumlah produk dan nama komponen halaman ikut diperbarui; ia akan gagal
   karena alasan yang tidak tampak berhubungan bila dilewatkan.

**Selesai bila.** Sebuah produk yang terpasang sebagai modul dapat diluncurkan tanpa satu pun baris
penempatan container, dan app yang masih memakai container tetap bisa diluncurkan seperti sebelumnya.

**Rujukan.** [empat kebenaran lifecycle](../../onboarding/empat-kebenaran.md).

**Bergantung pada.** F2-05.

#### Kriteria selesainya diperbaiki, karena yang lama menuntut sesuatu yang belum ada

Kriteria lama berbunyi "halaman modul contoh terbuka". Halaman modul belum ada — ia dibuat F2-11 — jadi
kriteria itu tidak bisa dipenuhi task ini tanpa mengerjakan task lain sekaligus. Yang bisa dan harus
dibuktikan di sini adalah **penentu kesiapannya**, dan itulah yang diuji.

#### Penentu kesiapan modul jauh lebih sederhana, dan itu memang benar

Kesiapan container menuntut lima hal sekaligus: entitlement aktif, deployment aktif, artifact
ditempatkan, migration berhasil, runtime dinyatakan siap. Kesiapan modul menuntut satu: catatan
pemasangan berstatus terpasang.

Perbedaannya bukan kelalaian. Tidak ada artifact yang ditempatkan, tidak ada runtime terpisah yang perlu
dinyatakan siap, dan tidak ada rilis yang dicocokkan versinya. Modul berjalan di proses yang sama dengan
Core: **kalau Core hidup, modulnya hidup.**

#### Dua jalur hidup berdampingan, dan itu wajib

Menghapus jalur container sekarang akan mematikan app yang belum dipindah. Keduanya digabung sebagai
himpunan, lalu dipotong dengan hak akses. Ada test untuk masing-masing.

#### Pemasangan bukan izin

Satu test mencabut penugasan peran sambil membiarkan pemasangannya, lalu membuktikan produknya tetap
tidak muncul. Ini menjaga larangan pada empat kebenaran lifecycle: menyimpulkan izin dari pemasangan
adalah kesalahan yang sama dengan menyimpulkan pemasangan dari entitlement.

### F2-07 — Pendaftaran tenant memasang modul, bukan menempatkan container

**Kenapa.** Aksi pendaftaran usaha memicu antrian penempatan container setelah transaksinya selesai. Job
itu menjalankan perintah compose, menulis penempatan dan pemasangan, lalu menunggu dependency siap.
Setelah satu runtime, tidak ada container yang perlu ditempatkan; yang harus terjadi adalah memasang
modul untuk tenant baru itu.

**Berkas.**
- `apps/control-plane/app/Actions/Onboarding/RegisterBusiness.php`
- `apps/control-plane/app/Jobs/DeployAppPlacement.php`
- `apps/control-plane/tests/Feature/ControlPlane/BusinessOnboardingTest.php`

**Langkah.**
1. Untuk modul di dalam runtime, ganti pengiriman job penempatan dengan pemanggilan aksi pemasangan
   modul dari F2-05.
2. Job penempatan dipertahankan selama masih ada app yang berjalan sebagai container, dan dipilih
   berdasarkan apakah id itu terdaftar sebagai modul atau sebagai app lama.
3. Test onboarding membuktikan tenant baru langsung memiliki modul terpasang beserta data awalnya.

**Selesai bila.** Mendaftarkan tenant baru menghasilkan baris pemasangan modul dan data awal, tanpa
menjalankan perintah compose apa pun.

**Rujukan.** [empat kebenaran lifecycle](../../onboarding/empat-kebenaran.md).

**Bergantung pada.** F2-05.

#### Jalurnya dipilih dari satu pertanyaan

Apakah id itu ada sebagai folder di `modules/`. Bila ya, tidak ada container yang perlu ditempatkan;
memasangnya berarti menjalankan migration, mencatat pemasangan, dan mengisi data awal, semuanya di
proses yang sama.

Jalur container **tidak dibuang**. Ada empat test, dan salah satunya sengaja mendaftarkan modul dan app
lama sekaligus untuk membuktikan keduanya berjalan berdampingan pada satu pendaftaran. Membuang jalur
lama sekarang akan mematikan produk yang sedang dipakai.

#### Temuan: katalog masih menuntut nama database per app

Kolom `apps.database_name` masih wajib diisi. Ia sisa rancangan database per app, dan modul tidak punya
database sendiri. Untuk sekarang test mengisinya dengan nama database Core supaya pendaftaran bisa
berjalan, tetapi itu menuliskan sesuatu yang tidak benar ke dalam katalog.

### F2-12 — Katalog berhenti menuntut nama database per app

**Kenapa.** `apps.database_name` wajib diisi, padahal modul memakai database yang sama dengan Core.
Selama kolom itu wajib, setiap pendaftaran modul menuliskan nilai yang tidak berarti, dan nilai yang
tidak berarti di kolom wajib adalah cara paling cepat membuat orang berikutnya percaya modul punya
database sendiri.

**Berkas.**
- `apps/control-plane/database/migrations/<baru>` (kolom menjadi opsional)
- `apps/control-plane/app/Http/Requests/Provider/AppCatalogRequest.php`
- `apps/control-plane/app/Actions/Provider/RegisterAppCatalog.php`

**Langkah.**
1. Kolom dibuat opsional lewat migration, bukan diisi nilai pura-pura.
2. Validasi manifest mewajibkannya hanya untuk app yang berjalan sebagai container.
3. Test membuktikan modul dapat didaftarkan ke katalog tanpa nama database, dan app container tetap
   ditolak bila tidak menyebutkannya.

**Selesai bila.** Test pendaftaran tenant tidak lagi perlu mengisi kolom itu, **dan** pendaftaran lewat
jalur resmi berhasil untuk modul tanpa nama database sementara app container tanpa nama database tetap
ditolak.

**Rujukan.** [standar app](../../dev/02-module-standard.md).

**Bergantung pada.** F2-07.

#### Catatan pelaksanaan

Selesai pada 8 September 2026 lewat pull request #52.

**Kriteria selesainya semula terlalu longgar, dan kalimat kedua di atas adalah perbaikannya.** Test
pendaftaran tenant menulis ke tabel `apps` dengan `DB::table()`, jadi yang menahannya selama ini adalah
`NOT NULL` di skema, bukan `AppCatalogRequest`. Migration saja sudah cukup membuat kriteria lama hijau —
tanpa satu baris pun validasi tersentuh, dan jalur resmi tetap menolak modul. Kriteria yang bisa dipenuhi
setengah pekerjaan bukan kriteria; ini contoh bagian 4.5 yang muncul pada rencana yang saya tulis sendiri.

**Yang membedakan modul dari app container adalah keberadaan foldernya di `modules/`, bukan bendera pada
manifest.** Bendera adalah klaim yang bisa berbohong; keberadaan folder adalah kenyataan yang sama dengan
yang dipakai `ModuleServiceProvider` untuk memuat modul. Dengan begitu validasi dan runtime memakai satu
sumber kebenaran, bukan dua yang bisa berselisih. `ModuleRegistry::cari()` yang menjawabnya.

**Daftar berkas di atas kurang satu, dan yang kurang itu jalur pendaftaran kedua.**
`RegisterAppManifestCommand.php` memetakan manifest YAML ke payload lewat `asString()`, yang memulangkan
string kosong bila blok `database` tidak ada. Tanpa ikut diubah, modul yang didaftarkan lewat CLI gagal
pada pola nama database dengan pesan menyesatkan — "format tidak sah", padahal kolomnya memang tidak ada.
Pelajarannya untuk task berikutnya: cari **semua** pintu masuk sebuah data sebelum menulis daftar
berkasnya, karena pintu yang terlewat tidak gagal dengan diam melainkan gagal dengan pesan yang salah.

**`apps.database_name` ternyata metadata mati.** Ia divalidasi dan disimpan, tetapi tidak ada pembaca di
luar presentasi; pembuatan database app didelegasikan sepenuhnya ke compose bundle milik app. Sudah
tercatat sebagai `LIFE-17` di [lifecycle dan deployment](../general/03-lifecycle-dan-deployment.md).
Bahkan untuk app container kolom ini hanya keterangan, bukan penggerak. Task ini tidak mengubah keadaan
itu, hanya berhenti menuntutnya dari pihak yang tidak punya jawabannya.

**`down()` migration sengaja dibiarkan bisa gagal.** Mengembalikan kolom menjadi wajib mustahil dilakukan
dengan jujur bila katalog sudah memuat baris modul, dan mengisi nilai karangan diam-diam saat rollback
justru mengulang persis masalah yang task ini perbaiki.

**Kontrak OpenAPI disunting seperlunya, bukan diregenerasi.** `scramble:export` menghasilkan selisih 4.387
baris terhadap berkas yang di-commit — berkas itu sudah lama tertinggal dari kode dan tidak ada langkah CI
yang menahannya. Membawa regenerasi penuh ke sini akan menenggelamkan perubahan yang sebenarnya. Selisih
sebesar itu adalah temuan tersendiri yang belum punya task.

**Satu baris dokumen ikut jadi salah karenanya.** `docs/dev/02-module-standard.md` menyatakan blok
`database` wajib tanpa syarat; barisnya diperjelas menjadi wajib hanya untuk app container.

### F2-13 — Penjaga tenant ikut menjaga penulisan

**Kenapa.** `TenantScope` menyaring pembacaan, dan itu memang yang dirancang. Tetapi sebuah scope baca
tidak pernah melihat baris yang sedang **ditulis**. Sebelum task ini, sebuah modul dapat menyimpan baris
dengan `tenant_id` milik tenant lain sementara tenant aktif berbeda, dan tidak ada satu pun yang
menahannya: bukan `NOT NULL`, karena kolomnya terisi; bukan scope, karena scope hanya menyentuh `select`.
Nilai itu biasanya datang dari permintaan, dan nilai dari permintaan adalah cara paling wajar sebuah
tenant menulis ke tenant lain. Kebocoran ini bahkan tidak terlihat oleh tenant yang menulis.

**Berkas.**
- `apps/control-plane/app/Support/Modules/Contracts/MilikTenant.php`
- `apps/control-plane/tests/Feature/Boundary/TenantScopeBoundaryTest.php`
- kedua controller modul contoh

**Langkah.**
1. `MilikTenant` mengisi `tenant_id` sendiri dari tenant aktif bila modul tidak menuliskannya.
2. `MilikTenant` membatalkan penyimpanan bila `tenant_id` yang tertulis berbeda dari tenant aktif — pada
   pembuatan maupun pembaruan.
3. Penyaringan tenant dengan tangan dibuang dari kode modul contoh.

**Selesai bila.** Baris baru mewarisi tenant aktif tanpa modul menuliskannya, penyimpanan ke tenant lain
dibatalkan, dan **kode modul menjadi lebih pendek, bukan lebih panjang**.

**Rujukan.** Bagian 5.2 dokumen ini.

**Bergantung pada.** F1-04.

#### Catatan pelaksanaan

Selesai pada 8 September 2026. Task ini tidak ada dalam rencana; ia lahir dari pertanyaan "bagaimana
tenant scope tetap sederhana tanpa penjaganya melonggar", dan jawabannya ternyata bukan menyederhanakan
apa pun melainkan menutup separuh penjaga yang belum ada.

**Lubangnya dibuktikan lebih dulu, sebelum ditutup.** Satu test menyimpan baris ber-`tenant_id` tenant
lain sementara tenant aktif berbeda; ia berhasil tersimpan. Baru setelah itu penjaganya ditulis.

**Penyaringan tangan di modul contoh bukan sekadar berlebihan, ia merusak pengukuran.** Kedua controller
menulis `->where('tenant_id', $konteks->tenantId())` pada model yang sudah memakai `MilikTenant`. Query
itu tetap benar walau `MilikTenant` dicabut — jadi penjaganya tidak terukur oleh kode yang justru
dimaksudkan mencontohkannya. **Ini pola yang sama dengan dua kriteria longgar pada F2-10 dan F2-12: dua
lapisan yang menjawab pertanyaan sama membuat lapisan pertama tak terukur.** Muncul tiga kali dalam satu
hari, jadi ia bukan kebetulan.

**Aturan yang menghapus pekerjaan tidak punya alasan untuk dilanggar.** Modul kini tidak menulis
`tenant_id` sama sekali — tidak pada query, tidak pada penyimpanan. `KonteksTenant` bahkan tidak lagi
dibutuhkan `RakController`. Yang tidak ditulis tidak bisa salah ditulis.

**Bukti bisa gagal.** Dengan penjaganya dilumpuhkan, ketiga test merah:

```
test_baris_baru_mewarisi_tenant_aktif_tanpa_module_menuliskannya
SQLSTATE[23502]: Not null violation: null value in column "tenant_id" of relation
"contoh_a_m_barang" violates not-null constraint

test_menulis_baris_ke_tenant_lain_dibatalkan
Penyimpanan ke tenant lain berhasil. Scope hanya menyaring baca, jadi tanpa penjagaan
tulis sebuah module bisa menanam baris di data tenant lain.

test_memindahkan_baris_ke_tenant_lain_lewat_pembaruan_dibatalkan
Failed asserting that exception of type "RuntimeException" is thrown.
```

**Yang masih terbuka.** Penjagaan ini hidup di lapisan model. Modul yang memakai `DB::table(` melewatinya
sepenuhnya — itu sebabnya penjaga ketiga melarang query mentah pada tabel modul, dan kenapa larangan itu
tidak boleh dilonggarkan diam-diam saat Management Aset masuk dengan 200 pemanggilannya.

### F2-08 — Kontrak layanan Core untuk modul

**Kenapa.** Modul butuh satu pintu resmi ke Core. Tanpa itu, tiap modul akan memanggil model Core
langsung dan batasnya kembali kabur.

**Berkas.**
- `apps/control-plane/app/Support/Modules/Contracts/PenerbitNomor.php`
- `apps/control-plane/app/Support/Modules/Contracts/KalenderFiskal.php`
- `apps/control-plane/app/Support/Modules/Contracts/DaftarSatuan.php`
- `apps/control-plane/app/Support/Modules/Contracts/MesinWorkflow.php`
- `apps/control-plane/app/Support/Modules/Contracts/DirektoriOrganisasi.php`
- `apps/control-plane/app/Support/Modules/CoreServices.php`
- `apps/control-plane/app/Services/UnitOfMeasureService.php`
- `apps/control-plane/app/Services/OrganizationDirectoryService.php`
- `apps/control-plane/tests/PHPStan/ModuleIsolationRule.php`

**Langkah.**
1. **Antarmuka menerima id, bukan model Core.** Ini penting: layanan kalender fiskal hari ini menerima
   objek entitas legal, mesin workflow menerima objek tipe dan versi, dan layanan nomor menerima objek
   sequence. Modul yang harus mengambil objek itu lebih dulu justru melanggar batas yang task ini buat.
   Lapisan pembungkus yang menerjemahkan id menjadi objek.
2. Dua layanan belum ada dan harus dibuat: pendaftaran satuan hari ini berada langsung di controller
   internal, bukan di layanan; dan direktori organisasi belum punya layanan sama sekali.
3. Perluas aturan analisa statis F1-05: modul hanya boleh menyentuh namespace kontrak, bukan sembarang
   kelas Core.

**Selesai bila.** Modul contoh menerbitkan satu nomor lewat antarmuka ini, dan penjaga batas menolak
modul yang memanggil kelas Core di luar kontrak.

**Rujukan.** [number sequence](../../dev/14-number-sequences.md), bagian 5.3 dokumen ini.

**Bergantung pada.** F2-02.

#### Penjaganya test pembaca berkas, bukan aturan analisa statis

Langkah 3 menyebut aturan analisa statis. Aturan itu sudah dibuang pada F1-05 karena berlubang; yang
menggantikannya adalah penjaga yang membaca berkas. Penjaga itulah yang diperluas di sini, dan
perluasannya menangkap lebih banyak jalur daripada yang bisa dilihat analisa statis.

Aturannya satu kalimat: **modul hanya boleh menyebut `App\Support\Modules\Contracts`.**

Aturan itu sempat lebih longgar, mencakup seluruh `App\Support\Modules`, supaya model modul bisa
menyebut `TenantScope` langsung. Kelonggaran itu dibuang: ia berarti kelas apa pun yang kelak ditaruh di
folder itu ikut boleh disentuh modul, tanpa ada yang menahan dan tanpa ada yang memutuskan.

Supaya kalimatnya bisa tetap sempit, dua hal ikut tinggal di dalam `Contracts`:

| Yang dipakai modul | Menggantikan |
| --- | --- |
| trait `MilikTenant` | model menyebut `TenantScope` dan memanggil `addGlobalScope` sendiri |
| kelas induk `SeederModule` | seeder memanggil `TenantScope::tenantAktif()` |

Keduanya menyembunyikan `TenantScope` tanpa melemahkannya: penyaringannya tetap di sana dan tetap gagal
menutup. Yang berubah hanya siapa yang menyebut namanya.

#### Enam antarmuka, bukan lima

Yang keenam, `KonteksTenant`, tidak ada pada rencana. Ia ditambahkan karena pemetaan layanan Core
disusun dari tiga belas endpoint HTTP, dan penyelesaian tenant tidak pernah lewat HTTP — app lama
membacanya dari token. Padahal itu hal **pertama** yang dibutuhkan setiap modul.

Seperti `TenantScope`, ia **gagal menutup**: tidak ada tenant aktif berarti pengecualian, bukan null.
Modul yang menerima null akan meneruskannya ke query, dan query tanpa penyaringan tenant membaca data
seluruh pelanggan.

#### Dua layanan memang belum ada, dan alasannya berbeda

Direktori organisasi belum pernah punya layanan: ketiga pertanyaannya dijawab langsung di controller
internal, jadi modul hanya bisa menanyakannya lewat HTTP. Setelah modul berada di proses yang sama,
pertanyaannya perlu rumah yang bukan controller.

Pencarian tipe dan versi workflow juga hidup di controller. Modul yang memanggilnya lewat HTTP tidak
pernah perlu tahu caranya; setelah menjadi pemanggilan fungsi, pencariannya harus punya satu rumah —
bukan disalin ke setiap modul.

#### Yang dikembalikan baris biasa, bukan model Core

Mengembalikan model berarti modul memegang objek Core dan bisa memanggil apa pun padanya, dan batas yang
dibuat kontrak ini kembali kabur pada baris berikutnya.

#### Dua temuan kecil dari kode yang ada

Tabel `organizations` tidak punya kolom kode, jadi bentuk kembalian direktori disesuaikan dengan
kenyataan, bukan dengan dugaan. Dan profil nomor yang berurutan bernama `continuous-strict`, bukan
`continuous-default`; nama yang ditebak akan lolos analisa statis dan gagal hanya saat dijalankan.

#### Jebakan pola yang layak diingat

Pola pencari namespace modul pada penjaga F1-05 juga cocok di tengah `App\Support\Modules\Contracts\`,
lalu membaca `Contracts` sebagai nama publisher — sebuah modul yang tidak pernah ada. Polanya kini
menuntut `Modules` berada di awal sebuah nama. Bentuk lengkap berawalan garis miring tetap ditangkap,
karena justru itu bentuk yang paling mungkin dipakai untuk menembus batas.

### F2-09 — Penerbitan nomor di dalam transaksi dokumen

**Kenapa.** Ini keuntungan nyata pertama yang bisa ditunjukkan. Hari ini nomor sudah tersimpan di
database Core walau dokumennya gagal disimpan di database modul, dan itu menghasilkan lubang pada urutan
yang seharusnya tidak berlubang. Keuntungan ini hanya mungkin karena modul dan Core memakai satu koneksi,
sesuai keputusan pada bagian 5.2.

**Berkas.**
- `apps/control-plane/app/Actions/NumberSequence/NumberSequenceService.php`
- `apps/control-plane/tests/Feature/ControlPlane/NumberSequenceInTransactionTest.php`

**Langkah.**
1. Penerbitan nomor sudah membungkus transaksinya sendiri di dalam perulangan percobaan ulang; saat
   dipanggil dari dalam transaksi pemanggil, itu menjadi savepoint. Tetapkan dan tulis apakah bentuk
   bersarang itu diterima, atau pemanggilan wajib berada dalam transaksi dan dijaga penegasan.
2. Test pertama: mulai transaksi, terbitkan nomor, lempar kesalahan, lalu pastikan nomor berikutnya tidak
   melompat. Invariannya: nilai berikutnya pada tabel alokasi kembali seperti semula.
3. Test kedua: dua koneksi bersamaan menerbitkan nomor berurutan tanpa duplikat, memakai koneksi kedua
   yang memang disediakan untuk pengujian serentak.

**Selesai bila.** Kedua test lulus pada PostgreSQL sungguhan.

**Rujukan.** [number sequence](../../dev/14-number-sequences.md).

**Bergantung pada.** F2-08.

#### Diputuskan: bentuk bersarang diterima

Langkah 1 menuntut keputusan tertulis. Keputusannya: **penerbitan boleh dipanggil dari dalam transaksi
pemanggil, dan tidak wajib berada di dalamnya.**

Alasannya bukan kompromi. Transaksi milik layanan nomor menjadi savepoint saat pemanggilnya sudah punya
transaksi, jadi percobaan ulang di dalamnya hanya mundur sampai savepoint — bukan membatalkan dokumen
yang sedang disusun pemanggil. Sementara mewajibkan pemanggil membuka transaksi lebih dulu akan memaksa
setiap layar yang sekadar menampilkan nomor berikutnya membuka transaksi tanpa alasan.

#### Testnya menguji invarian, bukan langkah

Yang diperiksa dua hal yang bisa diamati dari luar: nilai berikutnya pada tabel alokasi kembali seperti
semula, dan **tidak ada baris penerbitan yang selamat** dari transaksi yang gagal. Yang kedua ditambahkan
karena yang pertama saja bisa hijau pada implementasi yang menyimpan barisnya tapi lupa memajukan
penghitung.

Sudah dibuktikan bisa gagal: satu `commit` disisipkan di tengah transaksi dokumen untuk menirukan
penerbitan yang berada di luarnya, dan testnya merah dengan pesan yang benar.

#### Satu test dibuang karena tidak membuktikan yang dijanjikan namanya

Sempat ditulis test bernama "dunia dua database meninggalkan lubang". Ia memakai koneksi kedua, tetapi
layanan nomornya tetap berjalan di koneksi bawaan, jadi ia mengukur hal yang sama dengan test
sebelumnya sambil terbaca seolah membandingkan dua dunia. Test yang menjanjikan lebih dari yang
diukurnya lebih buruk daripada tidak ada test: ia membuat orang berhenti mencari.

### F2-10 — Konteks permintaan untuk modul

**Kenapa.** Middleware pada app lama memverifikasi token yang diterbitkan Core. Di dalam proses yang sama,
verifikasi itu tidak ada gunanya; yang dibutuhkan modul adalah pengguna, tenant, izin, dan batas
organisasi yang sudah dipegang Core.

**Berkas.**
- `apps/control-plane/app/Http/Middleware/ResolveModuleContext.php`
- `apps/control-plane/app/Support/Modules/ModuleRequestContext.php`
- `apps/control-plane/app/Providers/ModuleServiceProvider.php`
- `apps/control-plane/tests/Feature/Boundary/ModuleRequestContextTest.php`

**Langkah.**
1. **Middleware menulis atribut permintaan dengan kunci yang persis sama dengan yang dipakai app hari
   ini**, yaitu tenant, entitas legal, unit organisasi, pengguna, izin, dan kebijakan data. Ini bukan
   soal selera: 22 berkas pada modul aset membaca atribut itu langsung, sehingga mengganti bentuknya
   mengubah 22 berkas tanpa alasan. Kelas konteks hanya pembungkus baca di atasnya.
2. Izin bersifat per app, sedangkan middleware yang dipasang global tidak tahu ia sedang melayani modul
   yang mana. Id modul diambil dari grup rute modul, dan middleware didaftarkan per grup oleh penyedia
   layanan modul, bukan global.
3. Test memastikan pengguna tanpa izin mendapat 403 pada rute modul contoh **sebelum controller modul
   sempat berjalan**. Anak kalimat terakhir bukan hiasan; alasannya di catatan pelaksanaan.

**Selesai bila.** Rute modul contoh terlindungi tanpa token, dan atribut permintaannya sama dengan yang
dibaca app lama.

**Rujukan.** [identity dan access](../../dev/09-identity-and-access.md).

**Bergantung pada.** F2-01.

#### Catatan pelaksanaan

Selesai pada 8 September 2026 lewat pull request #53.

**Kriteria langkah 3 semula bisa lulus palsu, dan itu sudah dibuktikan bukan diduga.** Test "pengguna
tanpa izin mendapat 403" ditulis, lalu penolakan di middleware dihapus untuk melihat ia merah — **test itu
tetap hijau**, karena controller modul ikut menjawab 403. Artinya kriteria semula akan menerima middleware
yang perlindungannya sudah lenyap seluruhnya. Yang benar-benar bisa gagal adalah test yang memasang
closure selalu-200 di belakang middleware; itu merah dengan `Expected response status code [403] but
received 200`. Dua penjaga yang menjawab hal sama membuat penjaga pertama tidak terukur — pola ini akan
terulang di setiap lapisan berlapis, jadi test middleware selalu memakai penutup yang tidak ikut menjaga.

**Kontrak baru `KonteksPermintaan`, bukan `KonteksTenant` yang diperbesar.** Modul hanya boleh menyebut
namespace `Contracts`, jadi `ModuleRequestContext` tidak boleh disentuhnya langsung. Menggabungkannya ke
`KonteksTenant` juga salah: yang satu menjawab dari sesi, yang lain dari atribut permintaan, dan satu
antarmuka dengan dua sumber data pasti menyimpang.

**Middleware ikut menolak, tidak sekadar mengisi atribut.** Kalau ia hanya mengisi, perlindungan bergantung
pada setiap controller modul ingat memeriksa — dan modul ditulis pihak lain.

**Dua dari enam kunci lulus dalam keadaan kosong, dan PRD tidak menyebutnya.**
`coreerp.legal_entity_id` dan `coreerp.org_unit_id` bergantung pada kebijakan data:
`CurrentWorkspace::organizations()` menyaring lewat `DataPolicyAccessResolver`, jadi tanpa lingkup
kebijakan keduanya selalu `null` meski organisasinya ada. Test karena itu memasang satu kebijakan data
berlingkup terbatas, supaya isinya bisa dibedakan dari kosong. Test yang membandingkan `null` dengan
`null` akan hijau pada middleware yang tidak menulis apa pun.

**Berkas rute modul contoh sebelumnya tidak dimuat siapa pun.** `routes/web.php` kedua modul ada tetapi
tidak pernah dibaca; penyedia layanan modul kini memuatnya. Ini menjelaskan kenapa tidak ada test yang
gagal sebelumnya: tidak ada rute modul yang benar-benar hidup untuk diuji.

**Satu hal sengaja tidak dikerjakan karena di luar lingkup.** `KonteksTenantPermintaan` mengulang query
sesi pada tiap panggilan padahal middleware sudah menyelesaikan nilai yang sama di depan. Dicatat di sini
supaya tidak hilang.

### F2-11 — Halaman modul contoh di shell

**Kenapa.** Membuktikan jalur menu dari manifest sampai layar bekerja sebelum modul sungguhan dipindah.

**Berkas.**
- `modules/apperp/contoh-a/ui/Pages/Daftar.tsx`
- `apps/control-plane/resources/js/pages/modules/host.tsx` (baru)
- `apps/control-plane/resources/js/app.tsx`
- `apps/control-plane/vite.config.ts`
- `apps/control-plane/tsconfig.json`
- `apps/control-plane/app/Support/LaunchableAppCatalog.php`

**Langkah.**
1. Halaman modul bukan halaman Inertia biasa. Pemilih halaman pada berkas masuk React diperluas supaya
   mengenali nama berformat `Modul::Halaman` dan memuatnya dari folder modul; pola ini sudah dipakai
   orang lain pada Laravel dengan Inertia dan React.
2. Satu halaman tuan rumah menjadi satu-satunya halaman Inertia untuk semua modul. Karena komponen modul
   dimuat malas, halaman itu wajib memiliki pembatas penangguhan dan pembatas kesalahan; tanpa keduanya,
   pemuatan malas melempar.
3. Alias dan izin akses berkas pada konfigurasi Vite ditambahkan supaya folder di luar akar proyek dapat
   dibaca, dan folder modul disertakan pada konfigurasi TypeScript supaya pemeriksaan tipe mencakupnya.
4. Penyaringan menu berdasarkan izin tetap memakai katalog yang ada; hanya tujuan tautannya yang berubah.

**Selesai bila.** Menu modul contoh muncul di sidebar, halamannya terbuka dan menampilkan data dari tabel
modul, dan tidak ada elemen `iframe` pada pohon dokumen halaman itu.

**Rujukan.** Bagian 5.5 dokumen ini.

**Bergantung pada.** F2-06, F2-10.

#### Tiga berkas yang tidak disebut daftar di atas, dan salah satunya menghentikan halaman

Ditulis 8 September 2026, setelah task ini selesai.

`resources/views/app.blade.php` meminta berkas halaman ke manifest Vite dengan menyusun jalur
`resources/js/pages/{komponen}.tsx`. Untuk halaman modul, komponennya bernama `contoh-a::Daftar`, dan
manifest tidak punya kunci itu — halaman modul pertama membalas **500**, bukan layar kosong, dengan pesan
`Unable to locate file in Vite manifest: resources/js/pages/contoh-a::Daftar.tsx`. Baris permintaan itu
sekarang dilewati untuk nama berformat `Modul::Halaman`.

Menebak jalurnya dari nama halaman bukan pilihan yang lebih baik. Kunci manifest untuk halaman modul
berbunyi `../../modules/<penerbit>/<modul>/ui/Pages/<berkas>.tsx`, sedangkan nama halaman sengaja tidak
menyebut penerbit; lebih dari itu, jalur berawalan `../..` tidak bisa dilayani server pengembangan Vite
tanpa awalan `/@fs/`, sehingga baris yang bekerja pada `npm run build` justru gagal saat dikembangkan.
Halaman modul memang dimuat malas, jadi ia diambil pemilih halaman sesudah berkas masuk berjalan.

`app/Http/Middleware/ResolveModuleContext.php` yang membagikan kerangka layar, bukan
`HandleInertiaRequests`. `Inertia\Middleware` memanggil `share()` **sebelum** meneruskan permintaan,
sehingga middleware rute belum berjalan saat prop bersama disusun; prop yang dibaca di sana selalu
kosong, dan halamannya tampil tanpa sidebar tanpa satu pun error.

`resources/views/…` dan `package.json` juga ikut: berkas `ui/` modul berada di luar folder yang diperiksa
`npm run format:check`, jadi ia tidak pernah diformat siapa pun.

#### Alias Vite ada, tetapi bukan alias yang menyelesaikan masalahnya

Langkah 3 menyebut alias dan izin akses berkas. Izin akses (`server.fs.allow`) memang dibutuhkan. Alias
`@modules` ditambahkan untuk kode yang menyebut satu berkas modul secara langsung, tetapi pemindaian
folder pada berkas masuk tetap memakai pola relatif, karena pola `import.meta.glob` diselesaikan saat
membangun dan bentuk relatif yang pasti dikenali.

Yang justru menghentikan `npm run build` adalah hal yang tidak disebut sama sekali: impor `@apperp/ui`
**dari dalam berkas modul**. Pencarian `node_modules` menaiki folder dari berkas yang mengimpor, dan dari
`modules/<penerbit>/<modul>/ui` pendakian itu berakhir di akar repo, yang tidak punya `node_modules`.
Perbaikannya `resolve.dedupe`, bukan alias — alias akan melewati peta `exports` paket dan menuntut jalur
`dist/` ditulis tangan. Hal yang sama muncul pada `tsc`, dan di sana perbaikannya pemetaan `paths`.

#### Halaman modul tetap halaman Inertia, dan tuan rumahnya dipasang pemilih halaman

Langkah 1 dan langkah 2 terbaca bertentangan: kalau pemilih halaman memuat berkas modul langsung, tuan
rumahnya tidak dipakai. Yang dikerjakan: pemilih halaman mengenali nama `Modul::Halaman`, lalu
membungkus komponen modulnya dengan tuan rumah itu. Jadi controller modul menulis
`Inertia::render('contoh-a::Daftar', …)` seperti halaman Laravel biasa, sementara tuan rumah tetap
satu-satunya komponen halaman Inertia yang benar-benar dirender untuk semua modul.

#### Tautan menu modul memakai aturan tetap, bukan kolom manifest baru

Tautan entri menu modul adalah `/<id modul>/<id entri menu>`. Aturan ini mengikat manifest dan berkas rute
modul tanpa kolom tambahan; sebuah kolom kedua berisi jalur akan menyimpang dari berkas rutenya cepat
atau lambat, dan penyimpangannya tidak terlihat sampai ada yang mengklik menunya. Test
`HalamanModuleShellTest` membuktikan setiap tautan menu mendarat pada rute yang terdaftar.

`/apps/<id>` tetap menjadi tautan peluncur produk untuk kedua bentuk. Untuk modul ia meneruskan ke entri
menu pertama yang boleh dilihat pengguna, karena modul tidak punya penempatan container dan
`runtimeFor` akan membalas 404.

#### ESLint belum mencakup halaman modul

`eslint .` menolak berkas di luar folder konfigurasinya. Prettier dan `tsc` sudah mencakupnya; ESLint
belum, dan itu lubang yang perlu ditutup saat modul sungguhan dipindah — bukan sekarang, karena
memindahkan konfigurasi ESLint ke akar repo menyentuh seluruh berkas frontend Core sekaligus.

## 10. Fase 3: Management Aset pindah ke dalam Core

Ini fase terbesar. Yang dipindah: 99 berkas PHP, 42 migration yang menghasilkan 45 tabel, 22 berkas test,
dan manifest berisi 65 entry point, 122 permission, 64 privilege, 36 duty, 29 referensi nomor, 2 tipe
workflow, dan 2 laporan.

**Kriteria keluar.** Seluruh test Management Aset lulus di dalam Core pada PostgreSQL, dan pencarian
`Http::` di folder modul tidak menemukan satu pun panggilan ke Core.

**Urutan yang dipakai.** Pindahkan berkasnya dulu tanpa mengubah perilaku, baru ganti jalur
pemanggilannya satu per satu. Membalik urutan ini membuat setiap PR menyentuh dua hal sekaligus dan
sulit ditinjau.

### F3-00 — Penjaga batas mengenal modul yang sedang dipindah

**Kenapa.** F3-01 menyatakan tidak mengubah apa pun di dalam subtree yang ditariknya. Pull request itu
tidak akan bisa hijau. Sudah diukur pada repo aset apa adanya:

| Penjaga | Yang ditemukannya begitu subtree mendarat |
| --- | --- |
| namespace modul | 131 berkas PHP ber-namespace `App\`, bukan `Modules\Apperp\ManagementAset\` |
| penyaringan tenant | 200 pemanggilan `DB::table(` |
| awalan tabel | `app.yaml` repo itu tidak menyatakan `table_prefix` sama sekali |

Penjaganya benar; rencananya yang belum lengkap. Task ini membuat penjaga mengenal satu keadaan
tambahan — modul sedang dipindah dan belum dibentuk ulang — tanpa melemahkan penjaga untuk modul yang
sudah jadi.

**Berkas.**
- ketiga penjaga di `apps/control-plane/tests/Feature/Boundary/`
- satu tempat bersama yang menyimpan daftar modul yang sedang dipindah

**Langkah.**
1. Penandanya hidup di sisi CoreERP, **bukan di dalam folder modul**. Alasannya menentukan: `app.yaml`
   berada di dalam subtree, dan penanda di sana akan terhapus setiap kali subtree ditarik ulang dari repo
   asalnya — repo yang tidak tahu apa-apa tentang CoreERP.
2. Pengecualian wajib terlihat pada diff pull request, mengikuti pola `PENGECUALIAN` yang sudah dipakai
   penjaga tabel.
3. Pengecualian wajib punya cara berakhir. Dua yang saling melengkapi: sebuah tenggat yang membuat alur
   merah setelah lewat, dan pemeriksaan basi — modul yang dikecualikan tetap dipindai penuh, dan bila
   ternyata **tidak** melanggar apa pun, pengecualiannya sendiri yang gagal. Yang kedua menjawab
   pertanyaan "bagaimana orang tahu ia sudah boleh dibuang" tanpa mengandalkan ingatan siapa pun.
4. Buktikan melonggarkan untuk satu modul tidak melonggarkan untuk modul lain.

**Selesai bila.** Modul yang ditandai lolos ketiga penjaga meski melanggar semuanya, dan modul yang tidak
ditandai tetap merah pada pelanggaran yang sama persis.

**Rujukan.** [bukti penjaga](02-bukti-penjaga.md).

**Bergantung pada.** F1-07.

#### Catatan pelaksanaan

Selesai pada 8 September 2026 lewat pull request #55.

**Pengecualiannya pembalik, bukan pelewat.** Ini bentuk yang tidak saya bayangkan saat menulis task dan
ternyata lebih baik: modul yang ditandai **tetap dipindai penuh**, hanya arti hasilnya yang dibalik.
Dengan begitu pemeriksaan basi jatuh gratis — begitu modul yang dikecualikan ternyata tidak melanggar apa
pun lagi, entrinya sendiri yang gagal. Tidak ada yang perlu mengingat kapan pengecualian boleh dibuang.

**Pemeriksaan basi dinilai utuh per modul, bukan per dimensi.** Kalau per dimensi, modul yang
namespace-nya sudah dibereskan pada F3-03 tetapi tenant-nya belum akan dituntut membuang entrinya, dan
penjaga berikutnya langsung merah. Perincian yang terlalu halus di sini berubah menjadi jebakan.

**Daftarnya dikunci pada nama folder, bukan `id` manifest.** Penjaga namespace tidak pernah membaca
`app.yaml`, dan modul yang belum dibentuk ulang manifestnya mungkin belum terbaca. Nama folder satu-satunya
penanda yang dipegang ketiga penjaga tanpa syarat. Konsekuensinya jujur: **mengganti nama folder membuat
pengecualiannya diam-diam tidak berlaku.**

**Asimetri penjaga tabel dibuktikan, bukan diasumsikan.** Dugaan di bagian atas benar, dan bentuk
kegagalannya sekarang diketahui persis: dengan `table_prefix` diisi, migration kerangka Laravel gagal
dengan `SQLSTATE[42P07] Duplicate table: relation "users" already exists` — testnya mati sebelum sempat
melapor. Alasannya ditulis di docblock `ModuleTableBoundaryTest`, bukan hanya di sini.

**Pemindaian dipindahkan ke satu kelas bersama, `PemindaiModul`.** Kalau penjaga dan pemeriksaan basi
memakai aturan pemindaian yang berbeda, pemeriksaan basi akan mengumumkan "sudah bersih" untuk pelanggaran
yang masih dilihat penjaganya. Satu sumber, dua pembaca.

**Modul palsu untuk pengujian dibuat di folder sementara, bukan di `modules/`.** Nama acak per jalan tidak
cukup: satu jalan yang mati di tengah meninggalkan sisa yang lalu terbaca `module:list`, penjaga lain,
`pint ../../modules`, dan PHPStan yang memang memindai folder itu. Bahan uji yang bocor ke tempat produksi
merusak alat lain, bukan hanya dirinya sendiri.

**Bukti yang dijalankan.** Satu modul uji yang melanggar keempat dimensi sekaligus: tanpa ditandai
`tests 17, passed 13, failed 4`; ditandai `tests 17, passed 17`. Bedanya satu entri pada `DAFTAR`.

**Yang masih rapuh, dan disebut apa adanya.** Tenggat `2026-12-31` adalah tebakan — ia hanya berguna kalau
perpanjangannya ditinjau, bukan distempel. Dimensi awalan tabel tidak punya pemeriksaan basi, konsekuensi
asimetri di atas. Dan entri boleh ada sebelum modulnya mendarat — memang diperlukan F3-01 — sehingga entri
yang salah tulis baru ketahuan saat subtree-nya mendarat.

#### Satu asimetri yang harus diterima, bukan disamarkan

Penjaga namespace dan penjaga tenant hanya membaca berkas, jadi modul yang dikecualikan tetap bisa
dipindai dan pemeriksaan basi bisa dihitung. Penjaga tabel **menjalankan** migration modul, dan modul
yang belum dibentuk ulang membawa migration kerangka Laravel yang akan membuat `users`, `jobs`, dan
`cache` di schema test lalu bertabrakan dengan milik Core. Untuk penjaga itu, pengecualian harus
melewatkan penjalanannya sama sekali, sehingga pemeriksaan basi tidak bisa dihitung dan tenggat menjadi
satu-satunya yang mengakhirinya. Tulis alasannya di tempat pengecualian itu berada.

### F3-25 — Runtime melewatkan modul yang belum menyatakan awalan tabel

Nomornya F3-25 karena nomor tidak dipakai ulang, tetapi tempatnya di sini: **ia dikerjakan sebelum
F3-01.**

**Kenapa.** F3-00 membuat ketiga penjaga batas mengenal modul yang sedang dipindah, dan itu memang
diperlukan — tetapi ternyata belum cukup. Diukur dengan menjalankan F3-01 sungguhan: ketiga penjaga
batas **lulus**, dan **tiga belas test lain justru merah**. Sebabnya bukan penjaga batas sama sekali.

Kehadiran `app.yaml` di dalam subtree saja sudah membuat `ModuleRegistry` menemukan `management-aset`
sebagai modul, dan sejak detik itu Core memperlakukan modul setengah jadi sebagai modul siap pakai:
katalog menampilkannya sebagai dapat diluncurkan, dan pendaftaran tenant memasangnya sebagai modul —
bukan lagi lewat jalur penempatan container — lalu menjalankan seluruh migrationnya, termasuk migration
kerangka Laravel yang membuat `users`, `jobs`, dan `cache`.

**Berkas.**
- `apps/control-plane/app/Support/Modules/ModuleRegistry.php`
- `apps/control-plane/tests/Feature/ControlPlane/ModuleRegistryTest.php`
- `apps/control-plane/tests/Feature/Boundary/ModulSedangDipindahTest.php`

**Langkah.**
1. Registry melewatkan manifest yang tidak menyatakan `table_prefix`. Ini bukan aturan yang dikarang
   untuk keperluan ini: modul tanpa awalan tabel memang belum bisa dilayani, karena tabelnya akan
   memakai nama apa adanya dan bertabrakan dengan milik Core.
2. Melewatkan tidak boleh berarti menghilang tanpa suara. Setiap folder yang manifestnya tanpa awalan
   tabel wajib terdaftar sebagai modul yang sedang dipindah; yang tidak terdaftar membuat alur merah.
3. Buktikan keduanya bisa gagal.

**Selesai bila.** Modul tanpa `table_prefix` tidak ditemukan registry; modul semacam itu yang tidak
terdaftar sedang dipindah membuat alur merah dengan pesan yang menyebut namanya; dan pemeriksaan gaya
frontend tidak lagi merah karena berkas modul yang belum dibentuk ulang.

**Rujukan.** F3-00 pada dokumen ini.

**Bergantung pada.** F3-00.

#### Catatan pelaksanaan

Selesai pada 8 September 2026.

**Task ini tidak ada dalam rencana, dan cara ia ditemukan yang layak dicatat.** Ia muncul bukan dari
membaca kode melainkan dari menjalankan F3-01 apa adanya lalu melihat apa yang merah. Yang merah bukan
yang diperkirakan: penjaga batas — satu-satunya hal yang F3-00 siapkan — justru hijau semua.

**Pelajarannya bukan "F3-00 kurang".** F3-00 mengerjakan persis yang diukurnya dan mengerjakannya dengan
benar. Yang kurang adalah pengukurannya: saya mengukur pelanggaran **batas** yang dibawa modul itu, dan
tidak mengukur apa yang berubah pada Core hanya karena ada folder baru yang punya `app.yaml`. Pertanyaan
"apa yang rusak" dan "apa yang mulai berperilaku lain" adalah dua pertanyaan berbeda, dan yang kedua
tidak pernah saya ajukan.

**Aturannya memakai fakta yang sudah ada, bukan daftar baru.** `table_prefix` yang belum dinyatakan
adalah tanda yang sama yang sudah diukur F3-00. Karena itu pengecualiannya berakhir sendiri: begitu F3-04
memberi modul aset awalan tabelnya, registry menemukannya tanpa ada yang perlu mengingat untuk mencabut
apa pun.

**Bukti bisa gagal.** Entri `management-aset` dibuang sementara dari daftar modul yang sedang dipindah,
dengan subtree-nya sudah mendarat:

```
Module management-aset tidak menyatakan table_prefix dan tidak terdaftar sedang dipindah.
ModuleRegistry melewatkan module tanpa awalan tabel, jadi module ini tidak akan ditemukan
siapa pun dan tidak ada yang gagal karenanya — persis kegagalan diam yang paling mahal
ditemukan belakangan.
```

Dengan perbaikan ini dan subtree sudah mendarat: **287 test lulus**, dari sebelumnya 13 merah.

**Ada satu lagi yang ikut ketahuan, dan sebabnya sama.** Setelah test hijau, alur `quality` tetap merah:
Prettier kini memindai `modules/*/*/ui` sejak F2-11, dan 38 berkas modul aset memakai gaya repo asalnya.
Memformatnya di sini melanggar "jangan ubah apa pun di dalam subtree" dan akan menenggelamkan riwayat
`blame` 38 berkas tanpa memperbaiki apa pun. Jadi modul yang sedang dipindah dikecualikan lewat
`.prettierignore`.

Pengecualian itu punya cara berakhir yang sama seperti yang lain: sebuah test menuntut daftar di
`.prettierignore` **sama persis** dengan daftar modul yang sedang dipindah. Entri yang kurang membuat gaya
merah; entri yang tertinggal setelah modulnya selesai dipindah juga merah — karena pengecualian yang
tertinggal membiarkan modul jadi lolos pemeriksaan gaya selamanya, dan tidak ada yang akan menyadarinya.

**Dan setelah gaya hijau, giliran pemeriksaan tipe — lalu analisa statis.** `tsconfig.json` menyertakan `modules` sejak F2-11
juga, dan UI modul aset masih aplikasi React tersendiri dengan `package.json` serta `node_modules` miliknya
sendiri — memeriksanya dengan dependensi Core menghasilkan ratusan `TS2307 Cannot find module` yang tidak
satu pun menunjuk kesalahan sungguhan. Pengecualiannya dipasang di `exclude`. Sesudahnya PHPStan
memulangkan **560 temuan** dari modul yang sama, semuanya menunjuk keadaan yang memang sedang diperbaiki
bertahap pada F3-02 sampai F3-05 — dan menenggelamkan temuan sungguhan pada kode Core. Modulnya tetap ada
di `scanDirectories`, jadi kelasnya tetap dikenali bila kode Core menyebutnya; hanya analisanya yang
dikecualikan.

Penjaganya digabungkan menjadi satu test bertabel: setiap berkas pengecualian Core wajib mendaftar modul
yang sama persis. Menambahkan pemeriksaan berikutnya ke tabel itu satu baris.

#### Berhenti menebak: seluruh pemeriksaan yang menjangkau `modules/` didaftar sekali

Tiga putaran pertama dihabiskan dengan menunggu CI merah, memperbaiki satu pemeriksaan, lalu menunggu CI
merah lagi. Itu boros dan tidak perlu — daftarnya bisa dibaca, bukan ditunggu. Seluruh alur `quality`
dijalankan di mesin sendiri pada pohon hasil penggabungan, berurutan seperti di CI:

| # | Pemeriksaan | Menjangkau `modules/` | Hasil |
| --- | --- | --- | --- |
| 1 | Pint | ya, `pint ../../modules` | lulus apa adanya — gayanya kebetulan sudah cocok |
| 2 | Prettier | ya, sejak F2-11 | perlu pengecualian |
| 3 | ESLint | **tidak** | lulus; akan menjadi masalah pada hari ia mencakupnya |
| 4 | `tsc` | ya, sejak F2-11 | perlu pengecualian |
| 5 | PHPStan | ya, `paths` memuat `../../modules/` | perlu pengecualian |
| 6 | salinan skill | tidak | lulus |
| 7 | cakupan kontrak internal | tidak | lulus |
| 8 | `npm run build` | lewat pemilih halaman | lulus |

Pelajarannya bukan tentang daftar ini melainkan tentang urutan kerja: **satu pemeriksaan lokal atas
seluruh alur lebih murah daripada tiga putaran CI**, dan ia menemukan hal yang sama.

**Ini kejadian kelima dari pola yang sama dalam satu task**: sebuah pemeriksaan milik Core yang sudah
benar mulai menjangkau modul yang belum siap dijangkau. Penjaga batas (F3-00), registry dan katalog
(task ini), pemeriksaan gaya, pemeriksaan tipe, lalu analisa statis. Yang membedakan kelimanya hanya
siapa yang memindai;
polanya sama, dan pertanyaan yang seharusnya saya ajukan sejak awal adalah **"apa saja di Core yang
memindai `modules/`"** — bukan "apa yang rusak".

Satu yang belum menjangkau dan karenanya belum terlihat: **ESLint**. Ia belum mencakup `modules/` sama
sekali (dicatat pada F2-11), jadi ia akan menjadi kejadian berikutnya pada hari ia mencakupnya. Daftar
pengecualiannya sudah bertabel, jadi menambahkannya nanti satu baris.

Satu koreksi atas catatan ini sendiri: kalimat "kejadian ketiga" dan "keempat" di atas ditulis sambil
menebak siapa yang menyusul, dan tebakannya salah — bukan ESLint melainkan PHPStan. Kalimat yang menebak
urutan berikutnya memang tidak layak ditulis; yang layak adalah daftarnya.

### F3-01 — Bawa repo masuk beserta riwayatnya

**Kenapa.** Menyalin folder membuang `git log` dan `git blame` untuk 9.559 baris kode. Riwayat itu satu-
satunya penjelasan kenapa banyak aturan bisnis ditulis seperti sekarang.

**Berkas.**
- `modules/apperp/management-aset/` (baru, hasil subtree)

**Langkah.**
1. **Periksa repo modul bersih lebih dulu.** Saat rencana ini ditulis, repo itu tertinggal 31 commit yang
   belum terdorong dan 20 berkas yang belum di-commit, termasuk seluruh subsistem laporan. Menarik dari
   remote dalam keadaan itu akan memindahkan modul versi lama tanpa laporannya, dan sepuluh task
   berikutnya akan menyebut berkas yang tidak ada. Pastikan `git status` bersih dan cabang utamanya sama
   dengan remote.
2. Periksa juga cabang lain yang belum digabung, dan putuskan digabung atau ditinggalkan **sebelum**
   pemindahan, bukan sesudah.
3. Tambahkan remote repo modul, lalu tarik masuk dengan `git subtree add` ke `modules/apperp/management-aset`.
4. Jangan ubah apa pun pada pull request ini. Isinya persis repo lama, hanya berpindah tempat.
5. Setelah tergabung, tandai repo lama sebagai hanya baca. Repo yang masih bisa ditulis akan menerima
   commit yang kemudian hilang, dan itu bukan kekhawatiran hipotetis.

**Selesai bila.** `git blame` pada sebuah berkas modul menunjukkan commit, penulis, tanggal, dan jalur
asalnya; dan seluruh commit repo lama ada di dalam graf, terjangkau lewat sisi kedua commit
penggabungannya.

**Kriteria ini sudah diperbaiki sekali.** Semula ia berbunyi "`git log -- modules/apperp/management-aset`
menampilkan 35 commit asli". Itu **tidak mungkin** dengan `git subtree add`, dan bukan karena riwayatnya
hilang: commit lama menyentuh jalur `api/...`, bukan `modules/apperp/management-aset/api/...`, sehingga
`git log` yang dibatasi jalur tidak bisa mencocokkannya. `--follow` bahkan memulangkan nol. Yang bekerja
penuh adalah `git blame` — ia memulangkan SHA asli, penulis asli, tanggal asli, dan jalur lama — serta
`git log <commit penggabungan>^2`. Karena alasan task ini adalah "menjawab kenapa sebuah aturan bisnis
ditulis begitu", dan `blame` menjawab persis pertanyaan itu, `subtree add` dipertahankan dan kriterianya
yang dibetulkan.

Catatan angka: repo itu berisi **37** commit saat ditarik, bukan 35. Angka dalam prosa memang menua.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F2-05, F3-00, dan F3-25. Tanpa F3-00 pull request ini merah karena tiga penjaga
batas; tanpa F3-25 ia merah karena tiga belas test lain yang tidak ada hubungannya dengan penjaga batas.

### F3-02 — Buang berkas yang menjadi milik Core

**Kenapa.** Modul tidak boleh punya kerangka aplikasi sendiri. Dua migration framework di
`api/database/migrations` membuat tabel `cache`, `cache_locks`, `jobs`, `job_batches`, dan `failed_jobs`
yang sudah dimiliki Core, dan itu bentrok pasti.

**Berkas dihapus.**
- `modules/apperp/management-aset/api/bootstrap/`
- `modules/apperp/management-aset/api/public/`
- `modules/apperp/management-aset/api/database/migrations/0001_01_01_000001_create_cache_table.php`
- `modules/apperp/management-aset/api/database/migrations/0001_01_01_000002_create_jobs_table.php`
- `modules/apperp/management-aset/api/config/{app,cache,database,filesystems,logging,mail,queue,session}.php`
- `modules/apperp/management-aset/api/artisan`
- `modules/apperp/management-aset/api/phpunit.xml` (diganti di F3-16)

**Berkas dipertahankan.**
- `api/config/management_aset.php` dan `api/config/services.php` (diurus F3-17 dan F3-19)

**Langkah.**
1. Hapus berkas di daftar atas.
2. Jalankan `composer install` pada Core dan pastikan tidak ada yang mencari berkas yang hilang.

**Selesai bila.** Folder modul tidak lagi berisi kerangka Laravel, dan Core tetap menyala — **dan
sebuah test yang gagal bila kerangka itu kembali.**

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F3-01.

#### Catatan pelaksanaan

Selesai pada 8 September 2026.

**Kriteria selesainya semula tidak punya keadaan gagal.** "Folder modul tidak lagi berisi kerangka
Laravel" benar pada hari berkasnya dihapus dan tidak dijaga apa pun sesudahnya: siapa pun yang menarik
ulang subtree, atau menyalin berkas dari repo lain, mengembalikannya tanpa satu pun peringatan. Karena itu
`ModuleTanpaKerangkaTest` ditambahkan, dan ia berlaku untuk **semua** modul tanpa kecuali — termasuk yang
sedang dipindah. Membuang kerangka adalah langkah pertama pemindahan, jadi tidak ada keadaan sah di mana
sebuah folder modul boleh membawanya.

Dua hal yang dijaganya, dan yang kedua jauh lebih berbahaya:

1. `artisan`, `bootstrap/app.php`, `public/index.php` — selama ada, modul masih bisa dijalankan sebagai
   aplikasi terpisah, dan perubahan yang dibuat di sana tidak akan terlihat di Core.
2. **Migration yang membuat tabel milik Core** (`users`, `jobs`, `job_batches`, `failed_jobs`, `cache`,
   `cache_locks`). Tabrakannya pasti, dan ia muncul saat pemasangan modul di tenant sungguhan — bukan saat
   ada yang memperhatikan.

Keduanya dibuktikan bisa gagal dengan mengembalikan `api/bootstrap/app.php` dan menambahkan satu migration
yang membuat `users`; keduanya merah dengan pesan yang menyebut modul dan berkasnya.

**Yang tidak dihapus dan kenapa.** `Dockerfile`, `Dockerfile.test`, `deploy/`, dan `loadtest/` masih ada.
Ketiganya milik cara penyebaran lama dan nasibnya diputuskan F3-23. Satu akibat yang perlu diketahui
sekarang: `Dockerfile` menyebut `bootstrap/cache` yang sudah tidak ada, jadi ia **tidak akan bisa dibangun
lagi**. Itu memang konsekuensi yang dikehendaki — kontainer modul tidak lagi punya alasan untuk ada — tapi
ia rusak diam-diam sampai F3-23 membuangnya, dan lebih baik dicatat daripada ditemukan orang lain sebagai
kejutan.

`api/composer.json` juga masih menyebut belasan perintah `artisan`. Ia belum disentuh karena F3-03 yang
menggantinya dengan `composer.json` modul yang sesungguhnya.

### F3-03 — Bentuk ulang menjadi susunan modul

**Kenapa.** Susunan `api/app`, `database/migrations` di akar repo, dan `ui/` adalah bentuk repo terpisah.
Susunan modul memakai `src/`, `database/migrations/`, `ui/`, dan `tests/` sejajar.

**Berkas.**
- `modules/apperp/management-aset/src/` (dari `api/app/`)
- `modules/apperp/management-aset/database/migrations/` (tetap; sekarang di dalam modul)
- `modules/apperp/management-aset/tests/` (dari `api/tests/`)
- `modules/apperp/management-aset/routes/` (dari `api/routes/`)
- `modules/apperp/management-aset/resources/laporan/` (dari `api/resources/laporan/`)
- `modules/apperp/management-aset/composer.json` (baru)

**Langkah.**
1. `git mv` seluruh folder ke tempat barunya. Pakai `git mv` supaya riwayat berkas terbawa.
2. Ganti namespace `App\` menjadi `Modules\Apperp\ManagementAset\` di seluruh berkas PHP.
   Lakukan dengan satu perintah ganti massal, lalu periksa hasilnya dengan `composer types:check`.
3. `composer.json` modul mendeklarasikan PSR-4 untuk namespace itu.
4. Hapus `AppServiceProvider::boot()` yang memanggil `loadMigrationsFrom(base_path('../database/migrations'))`.
   Migrator modul dari F2-03 yang mengurus ini sekarang.
5. Hapus `deploy/migrate.sh` dan rujukan `--path=../database/migrations`.

**Selesai bila.** Tiap kelas modul dapat dimuat dengan nama yang dijanjikan `composer.json`-nya, dibuktikan
sebuah test; dan tidak ada lagi rujukan ke jalur migration relatif yang lama di seluruh repo.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F3-02.

#### Catatan pelaksanaan

Selesai pada 8 September 2026.

**Kriteria selesainya semula kosong, dan penyebabnya keputusan kita sendiri.** Ia berbunyi
"`composer types:check` lulus" — padahal F3-25 mengecualikan modul yang sedang dipindah dari PHPStan.
Analisa statis karena itu hijau **tanpa memeriksa satu berkas pun milik modul ini**. Kriteria yang
mengandalkan alat yang sudah kita matikan untuk sasarannya sendiri adalah kriteria yang tidak mengukur
apa pun. Penggantinya `ModuleAutoloadTest`: untuk tiap pemetaan PSR-4 pada `composer.json` modul, tiap
berkas PHP wajib mendeklarasikan namespace yang sesuai jalurnya dan wajib bisa dimuat autoloader.

**Penjaganya sempat gagal dengan cara yang salah, dan itu memperbaiki bentuknya.** Percobaan pertama
langsung memanggil `class_exists`. Saat satu berkas dikembalikan ke `App\Http\Controllers`, PHP fatal —
nama itu **sudah dipakai Core** — dan testnya mati dengan `Premature end of PHP process`, tanpa menyebut
berkas mana yang salah. Penjaganya sekarang membaca namespace dari berkasnya lebih dulu dan hanya mencoba
memuat yang namanya sudah benar. Pesan gagalnya kini menyebut modul, jalur, namespace tertulis, dan
namespace seharusnya.

**Angka sebenarnya.** 99 deklarasi `namespace` dan 233 pernyataan `use` pada 103 berkas. Tidak ada satu
pun rujukan berbentuk string atau nama berkualifikasi penuh — hanya dua bentuk itu, jadi penggantiannya
bisa harfiah dan tidak perlu regex yang bisa salah tangkap.

**Seluruh dependensi modul ternyata sudah dimiliki Core.** `api/composer.json` lama meminta
`laravel/framework`, `laravel/tinker`, `phpoffice/phpspreadsheet`, `phpoffice/phpword`, dan enam paket dev;
tidak satu pun yang tidak ada di Core. Itu sebabnya `composer.json` modul yang baru tidak perlu meminta apa
pun selain versi PHP. Diukur dengan membandingkan kedua berkas, bukan diduga.

**`api/database/seeders/DatabaseSeeder.php` ikut dibuang.** Ia kelas kosong ber-namespace
`Database\Seeders` — sisa kerangka, bukan milik modul. Membiarkannya berarti modul menyumbang kelas ke
namespace yang dimiliki Core.

**Pemformatan ikut berubah, dan hanya urutan impor.** Mengganti awalan namespace mengubah urutan abjad
pernyataan `use`, jadi Pint menuntut 42 berkas dirapikan ulang. Diperiksa bahwa selisihnya benar-benar
hanya itu: 343 baris ditambah, 340 dihapus, seluruhnya baris `namespace` dan `use`. Tidak ada perubahan
gaya lain yang menyelinap masuk dan mengaburkan `git blame`.

**Modul kini didaftarkan Core lewat `composer.json`-nya** (`apperp/management-aset: @dev`), sama seperti
kedua modul contoh. Tanpa itu pemetaan PSR-4 modul tidak dipakai siapa pun dan penjaga di atas tidak bisa
membuktikan apa-apa.

**Yang masih tertinggal di `api/` dan kenapa.** Tinggal `Dockerfile`, `Dockerfile.test`, `README.md`, dan
`config/` berisi dua berkas yang memang ditahan untuk F3-17 dan F3-19. Keempatnya milik cara penyebaran
lama; nasibnya diputuskan F3-23.

### F3-04 — Tabel modul diberi awalan `aset_`

**Kenapa.** Inilah yang memisahkan data modul dari data Core dan modul lain. Tanpa awalan,
`m_lokasi_aset`, `m_trade`, `m_tingkat_layanan`, dan sembilan tabel pemeliharaan akan bertabrakan dengan
modul lain yang wajar memakai nama sama. Satu tabel bahkan tidak punya penanda kepemilikan sama sekali,
yaitu `processed_core_events`, dan itu hampir pasti bentrok dengan modul lain yang melakukan penyaringan
kejadian ganda.

**Berkas.**
- `modules/apperp/management-aset/database/migrations/<baru>_prefix_tabel_modul.php`
- `modules/apperp/management-aset/src/Models/**` (properti nama tabel)
- Seluruh pemanggilan query builder mentah yang menyebut nama tabel

**Langkah.**
1. Satu migration mengganti nama seluruh tabel modul dengan awalan `aset_`. Daftar tabelnya dibaca dari
   `information_schema` dengan pola nama modul, jangan ditulis tangan, supaya tidak ada yang tertinggal.
2. Perhatikan lima migration yang memakai SQL mentah, bukan tiga seperti dugaan awal. Selain tiga yang
   mengubah tipe kolom dan membuat indeks unik parsial, ada satu yang mengganti nama enam tabel sekaligus
   dan satu lagi yang menjalankan pembaruan dengan subquery berkorelasi. Semuanya menyebut nama tabel,
   jadi semuanya ikut berubah.
3. Dua migration memiliki cap waktu yang sama persis, sehingga urutannya sekarang ditentukan urutan
   abjad nama berkas. Pastikan urutannya sebelum pemindahan, jangan setelah.
4. Migration yang isinya kosong dibiarkan apa adanya supaya riwayat migration tenant lama tidak berubah.

**Selesai bila.** Seluruh tabel modul berawalan `aset_` dan tidak ada tabel modul tanpa awalan, dibuktikan
dengan menjalankan migrationnya pada PostgreSQL lalu membandingkan katalognya dengan schema berisi Core
saja. Jumlah tabelnya dicatat oleh task ini, bukan diasumsikan dari dokumen.

**Kriteria "penjaga F1-04 lulus untuk modul ini" dibuang, karena mustahil dipenuhi di sini.** Penjaga tabel
melewatkan modul yang sedang dipindah sama sekali — itu asimetri yang sudah diterima sadar pada F3-00,
sebabnya migration kerangka yang dibawanya. Menuntutnya lulus di sini berarti menuntut modul ini keluar
dari daftar sebelum `tenant_id`-nya ada. Ia lulus pada F3-05, bukan sekarang.

#### Catatan pelaksanaan

Selesai pada 8 September 2026.

**Jumlahnya 46 tabel**, dibaca dari `pg_tables` setelah migration dijalankan sungguhan, dibandingkan dengan
schema berisi 93 tabel Core saja. Bukan dihitung dari dokumen. Satu-satunya yang tidak berawalan `m_`,
`tr_`, atau `t_` memang `processed_core_events`, persis seperti dugaan bagian atas.

**Migration lama sengaja tidak disunting.** Semuanya tetap membuat tabel bernama lama, lalu satu migration
baru mengganti nama seluruhnya di akhir. Menyunting migration lama akan mengubah riwayat yang sudah
dijalankan, dan itu melanggar keputusan pemilik produk bahwa setiap perintah pemasangan harus aman
diulang. Akibatnya langkah 2 pada rencana ini — "lima migration ber-SQL mentah ikut berubah" — tidak
berlaku: tidak satu pun disentuh.

Angka lima itu sendiri meleset: yang memakai `DB::statement` ada **empat**, ditambah satu yang memakai
`Schema::rename` untuk enam tabel sekaligus. Semuanya berjalan sebelum penggantian nama, jadi semuanya
tetap benar apa adanya.

**Daftar tabel dibaca dari katalog, bukan ditulis di dalam migration.** Daftar yang ditulis tangan akan
tertinggal satu tabel pada hari seseorang menambah migration baru, dan tabel yang tertinggal tidak gagal
dengan sendirinya — ia diam sampai bertabrakan dengan modul lain. Sebelum memakai pola nama, diperiksa
bahwa **tidak satu pun dari 93 tabel Core** cocok dengan pola itu.

**656 penyebutan nama tabel di 78 berkas** ikut diganti, dan seluruh 46 nama memang disebut kode — tidak
ada yang hanya hidup di migration.

**Bukti migration bisa gagal.** Sebuah tabel `aset_m_trade` dibuat lebih dulu, lalu migrationnya dijalankan
ulang:

```
2026_09_08_130000_prefix_tabel_modul .. FAIL
RuntimeException
Tabel aset_m_trade sudah ada, jadi m_trade tidak bisa diganti namanya. Jalankan migration ini
pada schema yang belum pernah menerimanya, atau selesaikan penggantian yang setengah jalan.
```

#### F3-25 harus dibetulkan di sini, dan itu koreksi atas keputusan saya sendiri

F3-25 memakai **`table_prefix` yang belum dinyatakan** sebagai tanda "modul ini belum siap dilayani".
Tanda itu bekerja tepat sampai task ini — task yang justru memberi awalan tabel. Begitu awalannya
dinyatakan, registry menyalakan modul yang `tenant_id`-nya belum ada, query mentahnya belum diganti, dan
panggilan HTTP-nya belum dibuang. Tiga belas test yang sama seperti pada F3-25 merah lagi, dengan sebab
yang sama persis.

**Tanda kesiapan yang ikut berubah karena pekerjaan setengah jalan bukan tanda kesiapan.** Sekarang yang
menjadi tanda adalah daftar `ModulSedangDipindah` — ia hanya berubah kalau ada yang sengaja mengubahnya,
punya tenggat, dan punya pemeriksaan basi. Karena registry membacanya, kelas itu pindah dari `tests/` ke
`app/Support/Modules/`; penjaga batas membaca daftar yang sama, jadi tetap satu daftar.

`table_prefix` tetap wajib, tapi kembali menjadi apa adanya: modul yang **tidak** sedang dipindah dan tidak
menyatakan awalan tabel membuat alur merah, bukan menghilang tanpa suara.

**Rujukan.** Bagian 5.2 dokumen ini, [standar app, nama tabel](../../dev/02-module-standard.md).

**Bergantung pada.** F2-03, F3-03.

### F3-05 — Semua tabel modul membawa `tenant_id` dan tersaring otomatis

**Kenapa.** Rencana sebelumnya menyematkan koneksi tersendiri pada model modul. Itu dibatalkan karena
koneksi terpisah membuat penerbitan nomor tidak bisa satu transaksi dengan dokumennya, dan karena 24
tempat pada modul ini membuka transaksi yang setelahnya akan membungkus koneksi yang salah tanpa gagal
dengan berisik.

Yang menggantikannya lebih sederhana dan lebih penting: memastikan setiap tabel modul membawa `tenant_id`
dan setiap query modul tersaring olehnya. Pada penempatan gabungan, satu query yang lupa menyaring
membocorkan data seluruh pelanggan.

**Berkas.**
- `modules/apperp/management-aset/database/migrations/` (tabel yang belum membawa `tenant_id`)
- `modules/apperp/management-aset/src/Models/MasterData.php`
- `modules/apperp/management-aset/src/Models/master/KelompokHartaFiskal.php`
- Seluruh pemanggilan query builder mentah pada `src/`

**Langkah.**
1. Periksa 45 tabel modul; tandai yang belum membawa `tenant_id` dan tambahkan lewat migration baru.
2. Model dasar memakai scope tenant dari F1-06. Perhatikan satu model tidak mewarisi model dasar itu,
   yaitu kelompok harta fiskal, sehingga ia tidak ikut tersaring bila hanya model dasar yang diubah.
3. Pemanggilan query builder mentah tersebar di 29 berkas dengan sekitar 207 kemunculan. Karena
   jumlahnya, sweep ini dipecah menjadi beberapa pull request per area: master, transaksi aset,
   penyusutan, pemeliharaan, dan penyediaan data awal.
4. Penjaga F1-06 dijalankan pada modul ini dan harus hijau.

**Selesai bila.** Penjaga penyaringan tenant lulus untuk seluruh berkas modul, dan sebuah test dua tenant
membuktikan tidak ada kebocoran pada rute daftar, detail, maupun laporan.

#### Catatan pelaksanaan — bagian pertama (kolom dan model)

Langkah 1 dan 2 selesai pada 8 September 2026. Langkah 3, sapuan query mentah, dipecah menurut area
seperti disebut rencana.

**Langkah 1 ternyata tidak perlu dikerjakan sama sekali.** Rencana menyuruh memeriksa tabel yang belum
membawa `tenant_id` lalu menambahkannya lewat migration baru. Diperiksa pada PostgreSQL sungguhan:
**keempat puluh enam tabel modul sudah membawa `tenant_id`**, termasuk `processed_core_events` yang paling
dicurigai. Tidak ada migration yang perlu ditulis. Angka 45 pada rencana juga meleset satu.

**Langkah 2 menyebut satu model yang tidak mewarisi model dasar; sebenarnya tiga.** Selain
`KelompokHartaFiskal` yang memang disebut, ada `Asset` dan `AssetBook` — dan justru dua itu yang memegang
data aset beserta buku penyusutannya. Kalau hanya model dasar yang diubah, dua model paling berisi di
modul ini tidak ikut tersaring, dan tidak ada satu pun yang gagal karenanya.

**Karena itu penjaganya memindai berkas, bukan mengandalkan pewarisan.** `ModelModuleMilikTenantTest`
menuntut setiap kelas modul yang `extends Model` memakai `MilikTenant`. Penjaga yang mengandalkan model
dasar hanya menjaga yang mewarisinya — dan yang tidak mewarisi persis kasus yang paling mudah terlewat.
Ia berlaku untuk semua modul tanpa kecuali, termasuk yang sedang dipindah: modul yang sudah dipasang di
tenant sungguhan sambil menunggu dibereskan adalah modul yang sudah membocorkan data.

Dibuktikan bisa gagal dengan mencabut `MilikTenant` dari satu model:

```
Model module tidak memakai MilikTenant: management-aset/AssetBook.php
Model yang tidak tersaring membocorkan baris milik tenant lain pada penempatan gabungan, dan
kebocoran itu tidak gagal dengan sendirinya — ia tampak seperti daftar yang isinya kebetulan
banyak. Model dasar tidak cukup: yang tidak mewarisinya tidak ikut terjaga.
```

**Kode modul justru menjadi lebih pendek.** Yang ditambahkan hanya satu baris `use MilikTenant;` pada
empat berkas; tidak ada satu pun query yang perlu menyebut `tenant_id` lagi, dan penulisan yang
mencantumkan tenant lain dibatalkan trait itu sendiri. Ini yang membuat aturannya tetap satu kalimat
sekaligus terjaga.

**Rujukan.** Bagian 5.2 dokumen ini, [query scope](../../dev/08-query-scopes-and-schema.md).

**Bergantung pada.** F1-06, F3-04.

### F3-26 — Konteks permintaan dihitung sekali, bukan sekali per penanya

**Kenapa.** Diukur pada permintaan daftar module yang paling sederhana — satu tabel, satu tenant, tanpa
relasi: **22 query, dan hanya satu di antaranya mengambil data yang diminta.** Sisanya konteks dan izin
yang ditanyakan berulang oleh pemanggil yang berbeda, dengan parameter yang sama persis:
`tenant_memberships` empat kali, lingkup kebijakan data enam kali, `organizations` lima kali.

Penyebabnya bukan pemindahan ke satu runtime. `CurrentWorkspace` dan `DataPolicyAccessResolver` memang
tidak pernah mengingat jawabannya, dan Core sudah begitu jauh sebelum module masuk; rute module hanya
melewati seluruh rantai itu sekaligus sehingga akibatnya terlihat.

**Berkas.**
- `apps/control-plane/app/Support/CurrentWorkspace.php`
- `apps/control-plane/app/Support/DataPolicyAccessResolver.php`
- `apps/control-plane/app/Providers/AppServiceProvider.php`
- `apps/control-plane/tests/Feature/Boundary/AnggaranQueryPermintaanModuleTest.php` (baru)

**Langkah.**
1. Kedua kelas mengingat jawabannya selama satu permintaan, berkunci id pengguna dan id keanggotaan.
2. Keduanya diikat `scoped()`, **bukan** `singleton()`. Bedanya menentukan pada pekerja yang hidup lama:
   singleton akan membawa keanggotaan pengguna sebelumnya ke permintaan berikutnya.
3. Berpindah tenant membuang ingatannya.
4. Tambahkan anggaran query per permintaan sebagai test, dan buktikan ia bisa merah.

**Selesai bila.** Satu permintaan daftar module tidak melebihi anggaran query yang ditetapkan, dan
anggaran itu terbukti bisa gagal.

**Bergantung pada.** F3-22.

#### Catatan pelaksanaan

Selesai pada 9 September 2026.

**Hasil: 22 query menjadi 9, 45,2 ms menjadi 19,4 ms.** Sembilan yang tersisa semuanya berbeda dan
masing-masing punya alasan; tidak ada yang mengulang.

**Pengukuran pertama saya salah, dan koreksinya patut dicatat.** Ia menghasilkan 5 query — karena
ingatan dari permintaan pemanasan ikut terpakai, padahal di produksi tiap permintaan mulai dari nol.
Setelah batas permintaan ditiru dengan `forgetScopedInstances()`, angkanya 9. Pengukuran yang tidak
meniru batas permintaan akan selalu memuji dirinya sendiri.

**Penjaganya mengukur jumlah query, bukan milidetik.** Jumlah query stabil antar mesin; milidetik tidak,
dan test kecepatan yang bergantung mesin akan dimatikan orang pada hari pertama ia berkedip. Dibuktikan
bisa merah dengan melumpuhkan ikatan `scoped`: **14 query, batasnya 10.**

### F3-06 — Penerbitan nomor lewat kontrak Core

**Kenapa.** Ini pemanggilan HTTP yang paling sering: setiap dokumen baru dan setiap master baru
memanggilnya. Delapan berkas memakainya, dan semuanya mengembalikan 503 ketika Core tidak terjangkau.

**Berkas.**
- `modules/apperp/management-aset/src/Services/NumberSequenceClient.php` (dihapus)
- `modules/apperp/management-aset/src/Services/NumberSequenceException.php` (dipertahankan)
- `src/Http/Controllers/MasterDataController.php`
- `src/Http/Controllers/master/MaintenanceJobTypeDefaultController.php`
- `src/Http/Controllers/transaksi/InventarisasiAset/AssetController.php`
- `src/Http/Controllers/transaksi/PemeliharaanAset/PemeliharaanAsetController.php`
- `src/Http/Controllers/transaksi/PerencanaanAset/PerencanaanAsetController.php`
- `src/Http/Controllers/transaksi/PermintaanPengadaanAset/PermintaanPengadaanAsetController.php`
- `src/Http/Controllers/transaksi/DokumenSiklusAset/DokumenSiklusAsetController.php`
- `src/Services/ProvisionIndonesiaStarterData.php`

**Langkah.**
1. Ganti ketergantungan pada `NumberSequenceClient` dengan antarmuka `PenerbitNomor` dari F2-06.
2. Pertahankan `NumberSequenceException` beserta kode kesalahannya. Kode itu diuji
   `NumberSequenceFailureTest` dan ditampilkan ke pengguna oleh UI; menghapusnya mengubah perilaku yang
   terlihat.
3. Kesalahan yang dulu berasal dari jaringan (`_unreachable`, `_throttled`, `_unavailable`) sekarang tidak
   mungkin terjadi. Jangan hapus penanganannya pada PR ini; tandai dengan komentar dan bereskan di F3-20
   setelah semuanya terbukti.
4. Bungkus penerbitan nomor dan penyimpanan dokumen dalam satu `DB::transaction`.

**Selesai bila.** `NumberSequenceFailureTest` lulus dengan kode kesalahan yang masih relevan, dan sebuah
test baru membuktikan nomor ikut batal ketika penyimpanan dokumen gagal.

#### Diukur sebelum dikerjakan: task ini jauh lebih besar daripada bentuknya

Percobaan pertama dibatalkan dengan sengaja, dan alasannya perlu diketahui sebelum ada yang mengambilnya
lagi. Menukar klien HTTP dengan kontrak Core hanya menyentuh sepuluh berkas dan berjalan mulus. Yang
runtuh adalah **testnya**, dan runtuhnya menunjukkan dua pekerjaan yang tidak disebut rencana ini:

**1. `NumberSequenceFailureTest` menguji mekanisme yang akan lenyap seluruhnya.** Kesepuluh testnya tentang
kegagalan jaringan: Core tidak terjangkau, token service belum diisi, klasifikasi 4xx dan 5xx, dan status
503 yang dikembalikan ke layar. Tidak satu pun dari itu bisa terjadi lagi di satu proses. Ini bukan test
yang perlu disesuaikan melainkan test yang perlu **diganti** dengan kegagalan yang masih mungkin — dan
memutuskan kegagalan mana yang masih mungkin adalah pekerjaan tersendiri, bukan akibat sampingan.

**2. Seluruh test yang membuat master atau dokumen ikut merah**, 43 dari 48 pada satu berkas saja. Selama
penerbitan lewat HTTP, test cukup memalsukan jawabannya dengan `Http::fake`. Lewat kontrak Core, nomornya
diterbitkan sungguhan — dan itu menuntut profil, referensi, serta penghitung nomor benar-benar ada untuk
tenant uji. Menyiapkannya adalah pekerjaan yang setara dengan trait konteks pada F3-15.

**Yang harus dikerjakan lebih dulu, dan sebaiknya sebagai task tersendiri:** bahan uji nomor urut untuk
tenant uji, dipasang dari trait yang sama dengan konteksnya. Sesudah itu F3-06 kembali menjadi sekecil
bentuknya.

**Status 503 juga tidak lagi jujur** begitu Core sekamar: kegagalan penerbitan berarti permintaannya
sendiri tidak bisa dipenuhi, bukan layanan yang tidak terjangkau. Penggantinya 422. Itu perubahan yang
terlihat pengguna, jadi ia keputusan produk — bukan detail yang boleh ikut menyelinap pada pull request
penggantian jalur.

**Rujukan.** [number sequence](../../dev/14-number-sequences.md), bagian 5.3 dokumen ini.

**Bergantung pada.** F2-07, F3-05.

### F3-07 — Kalender fiskal lewat kontrak Core

**Kenapa.** Satu pemanggil saja, jadi ini task kecil dan bagus untuk membuktikan pola penggantian
sebelum yang lebih besar.

**Berkas.**
- `modules/apperp/management-aset/src/Services/FiscalCalendarClient.php` (dihapus)
- `src/Http/Controllers/transaksi/InventarisasiAset/AssetController.php`

**Langkah.**
1. Ganti dengan antarmuka `KalenderFiskal` dari F2-06.
2. Perilaku ketika periode tidak ditemukan tetap sama: kembalikan `null` dan biarkan pemanggil yang
   memutuskan.
3. Catat di PR bahwa `FiscalCalendarDirectoryController` di Core menyalin ulang logika
   `FiscalCalendarService::resolve()`; keduanya sekarang harus memberi jawaban yang sama. Bereskan di
   F3-20.

**Selesai bila.** Test pendaftaran aset yang menyentuh periode fiskal lulus tanpa HTTP.

**Rujukan.** [fiscal calendar](../../dev/15-fiscal-calendars.md).

**Bergantung pada.** F2-06, F3-05.

### F3-08 — Satuan lewat kontrak Core

**Kenapa.** Empat pemanggil, dan kegagalannya hari ini melempar `RuntimeException` yang berakhir 500 di
layar pengguna.

**Berkas.**
- `modules/apperp/management-aset/src/Services/UnitOfMeasureClient.php` (dihapus)
- `src/Http/Controllers/ReferenceDataController.php`
- `src/Http/Controllers/master/TipeAtributController.php`
- `src/Http/Controllers/master/MaintenanceSetupLinkController.php`
- `src/Http/Controllers/transaksi/PerencanaanAset/PerencanaanAsetController.php`

**Langkah.**
1. Ganti dengan antarmuka `DaftarSatuan` dari F2-06.
2. Pemeriksaan jumlah hasil yang dilakukan `resolve()` tetap dipertahankan; itu menangkap id satuan yang
   dihapus di Core.

**Selesai bila.** Test yang menyentuh satuan lulus, dan tidak ada lagi `RuntimeException` bertuliskan
"Satuan belum dapat dihubungi".

**Rujukan.** [satuan](../../dev/16-units-of-measure.md).

**Bergantung pada.** F2-06, F3-05.

### F3-09 — Workflow lewat kontrak Core

**Kenapa.** Ini yang paling berbelit hari ini: modul mengirim HTTP ke Core untuk memulai workflow, lalu
Core mengirim HTTP balik ke modul lewat perintah terjadwal untuk menyampaikan keputusannya. Dua arah,
dua tanda tangan HMAC, satu tabel dedup.

**Berkas.**
- `modules/apperp/management-aset/src/Services/WorkflowClient.php` (dihapus)
- `src/Http/Controllers/transaksi/DokumenSiklusAset/DokumenSiklusAsetController.php`
- `src/Http/Controllers/transaksi/DekomisioningAset/WorkflowDecisionController.php`
- `apps/control-plane/app/Console/Commands/PublishWorkflowEvents.php`

**Langkah.**
1. Arah keluar: ganti `WorkflowClient::submit()` dengan antarmuka `MesinWorkflow` dari F2-06.
2. Arah masuk: keputusan workflow menjadi event Laravel biasa. Modul mendaftarkan listener; Core
   memancarkan event setelah keputusan disimpan.
3. `PublishWorkflowEvents` tetap ada untuk penerima di luar proses, tapi berhenti mengirim ke modul yang
   berada di dalam proses. Jangan hapus perintahnya.
4. Tabel `processed_core_events` dipakai untuk dedup listener. Karena semua tabel kini satu database,
   namanya **wajib** diberi awalan modul menjadi `aset_processed_core_events`; tanpa itu ia bertabrakan
   dengan tabel bernama sama milik Core atau modul lain.

**Selesai bila.** `AssetLifecycleTest` lulus, dan satu dokumen dekomisioning bisa diajukan lalu disetujui
tanpa satu pun permintaan HTTP.

**Rujukan.** [visual workflow engine](../../dev/21-visual-workflow-engine.md).

**Bergantung pada.** F2-06, F3-05.

### F3-10 — Konteks dan izin dari Core, bukan dari token

**Kenapa.** Ini perubahan perilaku terbesar di seluruh proyek. Middleware `RequireCoreErpContext`
memverifikasi JWT terbitan Core; di dalam proses yang sama, yang dibutuhkan adalah membership yang sudah
dipegang Core.

**Berkas.**
- `modules/apperp/management-aset/src/Http/Middleware/RequireCoreErpContext.php` (dihapus)
- `modules/apperp/management-aset/src/Support/OrganizationScope.php`
- `modules/apperp/management-aset/src/Http/Controllers/ContextController.php`
- `modules/apperp/management-aset/routes/api.php`

**Langkah.**
1. Rute modul memakai middleware `ResolveModuleContext` dari F2-08.
2. `OrganizationScope` membaca `data_policies` dari `ModuleRequestContext`, bukan dari atribut request
   hasil token. Bentuk datanya sengaja dibuat sama di F2-08, jadi isi kelasnya hampir tidak berubah.
3. `ContextController` tetap ada untuk sementara karena UI masih memanggilnya; ia sekarang membaca dari
   `ModuleRequestContext`. Dihapus di F4-07.

**Selesai bila.** Seluruh rute modul terlindungi — dibuktikan sebuah penjaga yang menolak alias middleware
yang tidak terdaftar — dan test batas data policy lulus tanpa token. Bagian kedua baru bisa dijalankan
setelah F3-16; sampai saat itu ia tercatat sebagai belum terbukti, bukan dianggap lulus.

#### Catatan pelaksanaan

Selesai pada 8 September 2026.

**Yang membuat task ini kecil adalah keputusan yang diambil dua task sebelumnya.** F2-10 menyalin kunci
atribut permintaan **harfiah** dari middleware lama modul (`coreerp.tenant_id`, `coreerp.permissions`,
`coreerp.data_policies`, dan seterusnya). Akibatnya `OrganizationScope` — kelas yang menegakkan batas
kebijakan data — **tidak berubah satu baris pun**. Yang berubah hanya siapa yang mengisi atributnya.

Ini contoh keputusan yang tampak sepele saat diambil ("pakai nama kunci yang sama") dan menghemat
perubahan pada 22 berkas saat ditagih.

**Satu bahaya ditemukan yang tidak ada di rencana mana pun.** Alias `coreerp` dan `coreerp-event`
didaftarkan `bootstrap/app.php` milik modul — berkas yang **dihapus F3-02**. Sejak saat itu rute modul
menunjuk alias yang tidak ada di mana pun, dan tidak ada satu pun yang memberi tahu: berkas rutenya belum
dimuat siapa pun (F3-13 yang memuatnya), jadi kesalahannya menunggu diam sampai rutenya dipanggil.

`RuteModuleTerlindungiTest` menutup itu: setiap alias pada berkas rute modul wajib terdaftar di Core, atau
tercatat sebagai sengaja-belum beserta task yang membereskannya. Dua rute panggilan balik Core sengaja
dibiarkan menunjuk alias yang tidak terdaftar — supaya gagal berisik kalau ada yang memuatnya sebelum
F3-09 dan F3-11 mengubahnya menjadi event in-process.

Dibuktikan bisa gagal dengan mengembalikan satu grup ke alias lama:

```
Rute module memakai middleware yang tidak terdaftar di Core:
- management-aset/api.php (api.php) memakai middleware "coreerp"
```

#### Urutan fase 3 perlu dibaca ulang: sebagian besar kriterianya belum bisa dijalankan

Ini bukan temuan tentang task ini melainkan tentang rencananya, dan lebih baik ditulis sekarang daripada
ditemukan lima task lagi.

Kriteria selesai F3-06 sampai F3-10 semuanya menyebut test milik modul — `NumberSequenceFailureTest`,
`AssetLifecycleTest`, "test pendaftaran aset", "test batas data policy". **Kedua puluh berkas test itu
belum berjalan di dalam Core**, dan baru berjalan setelah F3-16, yang bergantung pada F3-15, yang
bergantung pada F3-10. Artinya lima task berturut-turut dikerjakan tanpa jaring pengaman, dan yang paling
besar di antaranya — sapuan 216 query mentah pada langkah 3 F3-05 — adalah yang paling butuh jaring itu.

Yang saya lakukan: F3-10 dikerjakan lebih dulu justru karena ia penghalang F3-15. Sesudah ini **F3-15 dan
F3-16 dikerjakan sebelum sisa F3-05 dan sebelum F3-06 sampai F3-09**, supaya kedua puluh berkas test itu
menjadi jaring bagi semuanya. Urutan aslinya tidak salah secara ketergantungan; ia hanya menunda
satu-satunya alat yang bisa membuktikan pekerjaan berikutnya benar.

**Rujukan.** [identity dan access](../../dev/09-identity-and-access.md).

**Bergantung pada.** F2-08, F3-05.

### F3-11 — Provisioning tenant lewat event in-process

**Kenapa.** Sama seperti F3-09 arah masuk: Core mengirim HTTP ke modul untuk memberi tahu ada tenant
baru. Di dalam proses, itu event biasa.

**Berkas.**
- `modules/apperp/management-aset/src/Http/Controllers/TenantProvisioningController.php`
- `modules/apperp/management-aset/src/Http/Middleware/VerifyCoreErpEvent.php` (dihapus)
- `modules/apperp/management-aset/src/Listeners/SiapkanDataAwalTenant.php` (baru)

**Langkah.**
1. Modul mendaftarkan listener untuk event tenant baru.
2. Isi `ProvisionIndonesiaStarterData` tidak berubah; hanya pemicunya.
3. Endpoint HTTP-nya dipertahankan hanya bila ada penerima luar; kalau tidak ada, hapus beserta
   middleware-nya.

**Selesai bila.** `IndonesiaStarterProvisioningTest` lulus lewat event, bukan lewat permintaan HTTP.

**Rujukan.** [API dan integration bridge](../../dev/04-api-and-integration.md).

**Bergantung pada.** F3-10.

### F3-12 — Laporan dibaca langsung, bukan lewat HTTP

**Kenapa.** Hari ini mesin laporan Core memanggil endpoint modul dengan token pengguna, lalu modul
memeriksa ulang izin yang sudah diperiksa Core. Di dalam proses, registry laporan modul bisa dibaca
langsung.

**Berkas.**
- `apps/control-plane/app/Support/Reporting/AppReportClient.php`
- `apps/control-plane/app/Support/Reporting/ReportCatalog.php`
- `modules/apperp/management-aset/src/Http/Controllers/laporan/LaporanInternalController.php`
- `modules/apperp/management-aset/src/Reporting/ReportRegistry.php`

**Langkah.**
1. `ReportCatalog` mencari definisi laporan pada registry modul yang terdaftar, bukan lewat HTTP.
2. `AppReportClient` tetap ada untuk app di luar proses, tapi tidak lagi dipakai modul internal.
3. `LaporanInternalController` dan tiga rutenya dihapus setelah tidak ada pemanggil.

**Selesai bila.** `ReportingTest` di Core dan `LaporanInternalTest` di modul lulus, dan mencetak satu
dokumen work order menghasilkan PDF tanpa permintaan HTTP antar bagian.

**Rujukan.** [reporting dan replika](../../dev/07-reporting-and-replicas.md), [dokumen cetak](../../dev/23-document-rendering.md).

**Bergantung pada.** F3-10.

### F3-13 — Rute modul didaftarkan lewat penyedia layanan

**Kenapa.** Rute modul harus masuk ke daftar rute Core dengan awalan yang jelas, tanpa menabrak rute Core.

**Berkas.**
- `modules/apperp/management-aset/routes/api.php`
- `apps/control-plane/app/Providers/ModuleServiceProvider.php`

**Langkah.**
1. Rute modul didaftarkan dengan awalan `/api/modules/management-aset/v1`.
2. Rute lama `/api/v1/...` milik modul dihentikan; UI diperbaiki di fase 3.
3. Rute internal yang tersisa setelah F3-11 dan F3-12 dihapus dari berkas ini.

**Selesai bila.** `php artisan route:list` menampilkan rute modul di bawah awalan barunya, dan tidak ada
rute yang bertabrakan.

**Rujukan.** [API dan integration bridge](../../dev/04-api-and-integration.md).

**Bergantung pada.** F3-10.

#### Catatan pelaksanaan

Selesai pada 8 September 2026, bersama F3-22 dan F3-15.

**181 rute modul terdaftar di bawah `api/modules/management-aset`.** Awalan itu bukan kerapian: Core
sudah memakai `api/v1` untuk **tujuh belas** kelompok rutenya sendiri, dan dua pemilik pada satu ruang
nama rute adalah tabrakan yang menunggu tanggal — tabrakan yang muncul sebagai rute yang diam-diam
menang, bukan sebagai kesalahan.

**Penyedia layanan modul didaftarkan untuk modul yang sedang dipindah juga.** Ini membutuhkan pembedaan
baru pada registry: `semua()` untuk yang **dilayani** (katalog, pemasangan, data tenant) dan
`semuaTermasukYangSedangDipindah()` untuk yang **dimuat**. "Belum boleh dipasang untuk tenant" tidak sama
dengan "kodenya tidak boleh dimuat" — modul yang kodenya tidak dimuat tidak punya satu pun test yang bisa
berjalan, dan pemindahannya jadi dikerjakan tanpa jaring pengaman sampai hari terakhir.

Untuk alasan yang sama, migration modul yang sedang dipindah ikut dijalankan bersama migration Core.
Modul yang sudah jadi tidak begitu — migrationnya dijalankan `ModuleMigrator` saat dipasang per tenant.
Keduanya berakhir sendiri begitu modul keluar dari daftar.

**Langkah 2 rencana ditunda dengan sengaja.** Rute `/api/v1/...` lama tidak "dihentikan" melainkan
memang tidak pernah didaftarkan di runtime ini; UI modul masih menunjuk ke sana dan diperbaiki di fase 4.

### F3-14 — Manifest modul terdaftar dari folder

**Kenapa.** Setelah modul ada di dalam repo, manifest tidak perlu didaftarkan lewat perintah yang
menunjuk berkas di repo lain.

**Berkas.**
- `apps/control-plane/app/Console/Commands/RegisterAppManifestCommand.php`
- `modules/apperp/management-aset/app.yaml`

**Langkah.**
1. Daftarkan manifest modul lewat `ModuleRegistry` dan `RegisterAppCatalog` yang sudah ada.
2. Pastikan 65 entry point, 122 permission, 64 privilege, 36 duty, 29 referensi nomor, 2 tipe workflow,
   dan 2 laporan terdaftar seperti sebelumnya. Bandingkan jumlah baris di tabel sebelum dan sesudah.
3. Manifest masih menyebut `api.image` dan `ui.image`; biarkan sampai F5-05 mengganti bentuk rilis.

**Selesai bila.** Jumlah baris pada `permissions`, `security_duties`, dan `app_number_sequence_references`
untuk modul ini sama persis dengan sebelum pemindahan.

**Rujukan.** [standar app](../../dev/02-module-standard.md), [rantai keamanan](../../dev/19-transaction-security-chain.md).

**Bergantung pada.** F2-05, F3-13.

### F3-15 — Test modul pindah dan memakai autentikasi Core

**Kenapa.** Ke-18 berkas test fitur memakai `InteractsWithCoreErpContext` untuk mencetak token JWT.
Semuanya harus berganti cara masuk.

**Berkas.**
- `modules/apperp/management-aset/tests/Concerns/InteractsWithCoreErpContext.php` (diganti)
- `modules/apperp/management-aset/tests/Feature/*.php` (18 berkas)
- `modules/apperp/management-aset/tests/Unit/*.php` (2 berkas)

**Langkah.**
1. Ganti trait dengan yang membuat pengguna, membership, peran, dan duty di Core, lalu memakai
   `actingAs()`.
2. Ikuti kebiasaan test Core: nama method dalam bahasa Indonesia dengan garis bawah, fixture dibuat dengan
   `DB::table(...)->insert(...)` dan `Str::ulid()`.
3. Test yang memeriksa penolakan izin harus tetap memeriksa hal yang sama, hanya lewat peran, bukan lewat
   klaim token.

**Selesai bila.** Ke-20 berkas test lulus di dalam Core.

**Rujukan.** [pengujian](../../apps/management-aset/arsitektur/pengujian.md).

**Bergantung pada.** F3-10.

#### Catatan pelaksanaan

Selesai pada 8 September 2026, bersama F3-22 dan F3-13.

**Trait penggantinya membangun rantai izin sungguhan.** Dulu test mencetak JWT sendiri dengan daftar izin
apa pun yang disebutkannya; sekarang ia membuat `permissions`, `security_privileges`, `security_duties`,
`roles`, dan `role_assignments` di Core lalu `actingAs()`. Konsekuensinya disengaja: **test yang meminta
izin yang tidak ada akan gagal**, bukan lolos dengan klaim yang dikarang sendiri.

Satu rantai baru dibuat per pemanggilan, bukan satu rantai bersama. Kalau dua test berbagi role, keduanya
saling memberi izin tanpa ada yang menyadarinya — dan test yang membuktikan penolakan izin justru yang
paling mudah lolos palsu.

**Yang ditemukan karena test itu akhirnya berjalan, dan tidak akan ditemukan dengan cara lain:**

1. **Tidak ada satu pun yang mengikat tenant aktif selama permintaan.** `TenantScope` membacanya dari
   container dan gagal-menutup, jadi setiap query model modul berakhir 500. Lubang ini tidak terlihat
   sampai ada modul yang punya model **dan** rute sekaligus; kedua modul contoh hanya menyentuh modelnya
   dari test yang mengikat tenantnya sendiri. `ResolveModuleContext` sekarang yang mengikatnya.
2. **Rute modul perlu grup `web`**, karena konteks dibaca dari sesi Core. Tanpa itu `Request::session()`
   melempar "Session store not set on request" — muncul sebagai 500, bukan 401.
3. **Rute modul perlu `auth` di depan `konteks-module`.** Tanpa `auth`, permintaan tanpa pengguna jatuh ke
   middleware konteks dan dijawab 403. Yang benar 401: soalnya identitas, bukan wewenang. Ditemukan oleh
   satu test lama yang memang menuntut 401.
4. **`OrganizationScope` melempar bila kebijakan datanya tidak ada sama sekali** (`$scope['all']` tanpa
   `??`). Dulu tidak pernah terjadi karena token selalu memuat kunci kebijakannya walau kosong; Core
   menyusunnya hanya bila ada.

**Satu test dibuang, bukan diterjemahkan.** `test_gateway_context_and_permission_are_required` memeriksa
token yang dirusak satu huruf. Tidak ada lagi token untuk dirusak. Test yang menguji mekanisme yang sudah
tidak ada akan tetap hijau selamanya tanpa menjaga apa pun; yang tersisa dari test itu — 401 tanpa
pengguna, 403 tanpa izin — dipertahankan dengan nama yang menyebut keduanya.

### F3-16 — Test modul berjalan di PostgreSQL

**Kenapa.** Test modul hari ini memakai SQLite di memori sementara production memakai PostgreSQL. Core
sudah memutuskan sebaliknya, dengan alasan yang ditulis di `config/database.php`: klausa penguncian baris
menjadi string kosong di SQLite, sehingga test penguncian tidak membuktikan apa pun. Dua migration modul
bahkan punya cabang khusus supaya bisa jalan di SQLite.

**Berkas.**
- `modules/apperp/management-aset/tests/` (seluruhnya)
- `apps/control-plane/phpunit.xml`
- `modules/apperp/management-aset/database/migrations/2026_07_28_092000_widen_asset_user_reference_columns.php`
- `modules/apperp/management-aset/database/migrations/2026_08_19_090000_add_nama_to_asset_register.php`

**Langkah.**
1. Test modul masuk ke suite Core dan memakai koneksi `pgsql_test`.
2. Hapus cabang `DB::getDriverName()` pada dua migration itu; sekarang hanya ada satu mesin database.
3. Jalankan seluruh suite dan perbaiki test yang selama ini lulus hanya karena SQLite lebih longgar.

**Selesai bila.** `composer test` menjalankan test Core dan test modul dalam satu perintah, seluruhnya di
PostgreSQL.

#### Catatan pelaksanaan

Langkah 1 selesai bersama F3-22 pada 8 September 2026; langkah 2 dan 3 pada 9 September.

**Dua cabang mesin database dibuang, bukan disimpan "untuk jaga-jaga".** Satu migration memulangkan diri
lebih awal pada SQLite; satu lagi melewati `SET NOT NULL`. Keduanya lahir karena test modul dulu berjalan
di SQLite sementara produksi memakai PostgreSQL — dan akibatnya suite membuktikan perilaku pada mesin yang
tidak pernah dipakai siapa pun, sementara pada mesin yang benar migration itu tidak pernah diuji sama
sekali.

Cabang yang tidak pernah dijalankan adalah kode yang tidak pernah dibuktikan. Sekarang hanya ada satu
mesin, jadi tidak ada yang perlu dijaga-jaga.

**Langkah 3 — "perbaiki test yang selama ini lulus hanya karena SQLite lebih longgar" — ternyata sudah
terjadi seluruhnya pada F3-15.** Kedua puluh berkas test dijalankan di PostgreSQL sejak hari pertama
mereka masuk suite Core, dan yang gagal saat itu diperbaiki satu per satu di sana. Tidak ada sisa untuk
task ini.

**Rujukan.** [pengujian](../../apps/management-aset/arsitektur/pengujian.md).

**Bergantung pada.** F3-15.

### F3-17 — Konfigurasi modul masuk ke Core

**Kenapa.** Berkas `api/config/management_aset.php` berisi data template awal Indonesia yang dipakai
`ProvisionIndonesiaStarterData`, termasuk klasifikasi fiskal menurut PMK 72/2023. Itu milik modul dan
harus ikut pindah tanpa menabrak nama konfigurasi Core.

**Berkas.**
- `modules/apperp/management-aset/config/management-aset.php`
- `apps/control-plane/app/Providers/ModuleServiceProvider.php`

**Langkah.**
1. Penyedia layanan modul menggabungkan konfigurasinya dengan awalan `modules.management-aset`.
2. Ganti seluruh pemanggilan `config('management_aset.…')` menjadi `config('modules.management-aset.…')`.

**Selesai bila.** `IndonesiaStarterProvisioningTest` lulus, dan tidak ada kunci konfigurasi modul yang
berada di akar.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F3-03.

### F3-18 — Perkakas laporan bawaan ikut pindah

**Kenapa.** Perintah pembuat layout bawaan memakai dua pustaka Office yang hari ini hanya terpasang
sebagai dependensi pengembangan di modul. Kalau tidak diurus, perintah itu gagal di image production.

**Berkas.**
- `modules/apperp/management-aset/src/Console/Commands/BangunLayoutLaporanBawaan.php`
- `apps/control-plane/composer.json`
- `modules/apperp/management-aset/resources/laporan/work-order/standar.docx`
- `modules/apperp/management-aset/resources/laporan/daftar-work-order/standar.xlsx`

**Langkah.**
1. Periksa apakah Core sudah memakai `phpoffice/phpword` dan `phpoffice/phpspreadsheet` untuk
   `RenderPipeline`. Kalau sudah, cukup pastikan keduanya ada di `require`, bukan `require-dev`.
2. Daftarkan perintah modul lewat penyedia layanan modul.
3. Pastikan berkas layout ikut terbaca dari folder modul.

**Selesai bila.** Perintah pembuat layout berjalan di image Core, dan mencetak work order menghasilkan PDF
yang sama seperti sebelumnya.

**Rujukan.** [dokumen cetak](../../dev/23-document-rendering.md).

**Bergantung pada.** F3-12.

### F3-19 — Buang sisa konfigurasi klien HTTP

**Kenapa.** Setelah empat klien hilang, empat variabel lingkungan dan satu blok konfigurasi menjadi mati.
Meninggalkannya membuat orang berikutnya mengira modul masih bicara ke Core lewat jaringan.

**Berkas.**
- `modules/apperp/management-aset/config/services.php` (dihapus)
- `modules/apperp/management-aset/.env.example` (dihapus)
- `erp-dev/start.ps1` (bagian yang menulis `APP_<SLUG>_SERVICE_TOKEN`)
- `apps/control-plane/.env.example`

**Langkah.**
1. Hapus blok `services.coreerp` beserta `COREERP_URL`, `COREERP_APP_ID`, dan `COREERP_SERVICE_TOKEN`.
2. `COREERP_APP_CONTEXT_SIGNING_KEY` tetap ada. Ia masih menandatangani HMAC event keluar; tulis itu
   sebagai komentar di `.env.example` supaya tidak ada yang menghapusnya karena mengira sudah tak dipakai.

**Selesai bila.** Pencarian `COREERP_SERVICE_TOKEN` di seluruh repo hanya menemukan riwayat, bukan kode
aktif.

**Rujukan.** Bagian 5.4 dokumen ini.

**Bergantung pada.** F3-06, F3-07, F3-08, F3-09.

### F3-20 — Buktikan tidak ada lagi lompatan HTTP

**Kenapa.** Kriteria keluar fase ini harus diperiksa mesin, bukan dengan perasaan sudah selesai.

**Berkas.**
- `apps/control-plane/tests/Feature/Boundary/NoInternalHttpTest.php`
- `apps/control-plane/app/Http/Controllers/Internal/FiscalCalendarDirectoryController.php`

**Langkah.**
1. Test memindai berkas PHP di `modules/` dan gagal bila menemukan `Http::` yang menunjuk konfigurasi
   Core.
2. Test kedua menjalankan satu alur lengkap, yaitu membuat aset sampai mencetak laporannya, dengan
   `Http::fake()` yang gagal pada permintaan apa pun. Alur harus tetap lulus.
3. Rapikan dua hal yang ditemukan sepanjang fase: `FiscalCalendarDirectoryController` yang menyalin ulang
   logika `FiscalCalendarService::resolve()`, dan kode kesalahan jaringan pada `NumberSequenceException`
   yang sudah tidak mungkin terjadi.

**Selesai bila.** Kedua test lulus, dan `Http::fake()` yang menolak semua permintaan tidak membuat satu
pun test modul gagal.

**Rujukan.** Prinsip P5 dokumen ini.

**Bergantung pada.** F3-19.

### F3-21 — Penyedia layanan modul

**Kenapa.** Empat task pada fase ini menyerahkan pekerjaan kepada "penyedia layanan modul", tapi tidak
ada satu pun yang membuatnya. Berkas penyedia layanan app lama juga memegang pendaftaran registry laporan
dan alias middleware yang dipakai setiap rutenya, sehingga menghapusnya tanpa pengganti membuat modul
tidak bisa menyala.

**Berkas.**
- `modules/apperp/management-aset/src/Providers/ManagementAsetServiceProvider.php`
- `apps/control-plane/app/Support/Modules/ModuleRegistry.php`

**Langkah.**
1. Penyedia layanan modul memuat konfigurasi, rute, perintah artisan, pendaftaran registry laporan, dan
   listener event modul.
2. Registry menemukannya lewat konvensi nama, bukan daftar yang ditulis tangan.
3. Berkas kerangka aplikasi app lama baru boleh dihapus setelah penyedia ini memikul isinya.

**Selesai bila.** Rute, perintah, dan laporan modul tersedia tanpa satu pun berkas kerangka aplikasi di
dalam folder modul.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F3-03.

### F3-22 — Namespace test modul dan pendaftaran suite

**Kenapa.** Test modul memakai namespace `Tests\` dengan kelas dasar bernama sama seperti milik Core,
dan keduanya mendaftarkan akar PSR-4 yang sama. Dua akar untuk satu awalan dengan kelas bernama sama
membuat suite tidak bisa dimuat sama sekali.

**Berkas.**
- `modules/apperp/management-aset/tests/` (seluruhnya)
- `modules/apperp/management-aset/composer.json`
- `apps/control-plane/phpunit.xml`

**Langkah.**
1. Ganti namespace test modul menjadi `Modules\Apperp\ManagementAset\Tests\` dan daftarkan pada
   `autoload-dev` modul.
2. Hapus kelas dasar dan test contoh milik modul; pakai milik Core.
3. Tambahkan suite dan direktori sumber modul pada konfigurasi PHPUnit Core, karena konfigurasinya hari
   ini hanya menyertakan folder aplikasi.

**Selesai bila.** `composer test` menjalankan test Core dan test modul dalam satu perintah.

**Rujukan.** [pengujian](../../apps/management-aset/arsitektur/pengujian.md).

**Bergantung pada.** F3-03.

#### Catatan pelaksanaan

Selesai pada 8 September 2026, digabung dengan F3-13 dan F3-15 karena tidak satu pun dari ketiganya bisa
dibuktikan sendirian.

**Langkah 1 rencana tidak bekerja, dan sebabnya patut diingat.** Ia menyuruh mendaftarkan namespace test
modul pada `autoload-dev` modul. **Composer tidak memuat `autoload-dev` milik dependensi** — hanya milik
paket akar. Akibatnya seluruh test modul gagal dengan "Trait ... not found" tanpa satu pun petunjuk bahwa
sebabnya berada di berkas `composer.json` yang lain. Pemetaannya karena itu didaftarkan di `autoload-dev`
Core, sebaris per modul — konsisten dengan `require` yang memang sudah menyebut tiap modul satu per satu.

**Kelas dasar test modul dibuang, memakai milik Core.** Dua akar PSR-4 untuk satu awalan `Tests\` dengan
kelas bernama sama membuat suite tidak bisa dimuat sama sekali; itu memang yang diperingatkan rencana.

### F3-23 — Nasib berkas kontrak, uji beban, dan penyebaran milik modul

**Kenapa.** Pemindahan menarik masuk folder kontrak, uji beban, dokumentasi, dan penyebaran milik repo
lama. Hanya folder antarmuka yang punya masa depan tertulis; sisanya menggantung, dan pemeriksa cakupan
kontrak pada alur lint akan menunjuk berkas yang tidak jelas statusnya.

**Berkas.**
- `modules/apperp/management-aset/contracts/`
- `modules/apperp/management-aset/loadtest/`
- `modules/apperp/management-aset/deploy/`
- `modules/apperp/management-aset/docs/`
- `.github/workflows/lint.yml`

**Langkah.**
1. Putuskan dan tulis: setelah rute modul hanya dipanggil antarmukanya sendiri di proses yang sama,
   apakah spesifikasi OpenAPI-nya masih kontrak yang dijaga, atau menjadi dokumentasi biasa. Jawabannya
   menentukan apakah pemeriksa cakupan tetap berjalan di CI.
2. Folder penyebaran milik modul dihapus; runtime tidak lagi punya container sendiri.
3. Folder uji beban digabungkan ke milik Core pada fase 7; sampai itu, biarkan dan tandai.
4. Dokumentasi modul dipindahkan ke `docs/apps/` bila belum di sana.

**Selesai bila.** Tidak ada folder menggantung tanpa keputusan tertulis, dan alur lint tetap hijau.

**Rujukan.** [API dan integration bridge](../../dev/04-api-and-integration.md).

**Bergantung pada.** F3-13.

### F3-24 — Stack pengembangan tetap menyala selama pemindahan

**Kenapa.** Prinsip P3. Skrip pengembangan menyentuh modul lewat empat jalur: pemasangan volume
migration, alamat dan token layanan Core, daftar alamat event, dan penerbitan token per app. Semuanya
rusak sejak task pembuangan berkas kerangka, sedangkan perbaikannya baru dijadwalkan pada fase 6. Di
antara keduanya, stack lokal tidak bisa menyala, dan itu melanggar prinsip yang dokumen ini tetapkan
sendiri.

**Berkas.**
- `erp-dev/start.ps1`
- `erp-dev/compose.yaml`

**Langkah.**
1. Sesuaikan skrip supaya modul yang sudah berada di dalam runtime tidak lagi diperlakukan sebagai app
   dengan container sendiri.
2. App yang belum dipindah tetap diperlakukan seperti sekarang. Kedua jalur hidup berdampingan.
3. Hapus pemasangan volume migration yang menunjuk jalur lama, karena jalur itu sudah tidak ada.

**Selesai bila.** Menjalankan skrip pengembangan menyalakan Core beserta modul aset di dalamnya, dan
halaman modul terbuka.

**Rujukan.** [pengembangan lokal](../../dev/11-local-docker-development.md).

**Bergantung pada.** F3-13.

## 11. Fase 4: UI menjadi satu build

**Kriteria keluar.** Tidak ada elemen `iframe` pada halaman modul, React hanya termuat sekali, dan
berpindah antar menu modul tidak memuat ulang halaman.

**Catatan yang memudahkan.** Id item menu di `app.yaml` sama persis dengan nama sumber daya pada
perutean hash di UI modul. Tabel rute per modul bisa memakai id itu langsung, jadi manifest tidak perlu
diubah.

### F4-01 — Hapus `apps/web-shell`

**Kenapa.** Folder itu berisi peluncur lama yang sudah digantikan `product-launcher.tsx` dan halaman
halaman tuan rumah app di shell. Tidak ada compose, skrip, atau CI yang menyebutnya, tapi dua halaman dokumen masih
menyuruh orang membacanya.

**Berkas.**
- `apps/web-shell/` (dihapus)
- `docs/onboarding/peta-kode.md`
- `docs/dev/02-module-standard.md`

**Langkah.**
1. Hapus folder beserta `dist/` yang ikut ter-commit.
2. Perbaiki dua baris dokumen yang menyebutnya.

**Selesai bila.** `npx vitepress build docs` lulus tanpa tautan mati, dan stack lokal tetap menyala.

**Rujukan.** [peta kode](../../onboarding/peta-kode.md).

**Bergantung pada.** Tidak ada.

#### Catatan pelaksanaan

Selesai pada 8 September 2026 lewat pull request #65.

**Tidak ada `dist/` yang ikut ter-commit.** Langkah 1 menyuruh menghapusnya.
`git ls-files apps/web-shell` memulangkan tepat sembilan berkas dan tidak satu pun di bawah `dist/`;
`git log --all -- apps/web-shell/dist` kosong. Folder itu tidak pernah punya build ter-commit.

**Dugaan "tidak ada compose, skrip, atau CI yang menyebutnya" benar, dan sekarang terukur.**
`grep -ril "web-shell"` di seluruh worktree menemukan sepuluh berkas: dua di dalam folder itu sendiri dan
delapan halaman dokumen. Awalan env `WEB_SHELL_` hanya muncul di dua berkas milik folder itu. Nama paket
`@coreerp/web-shell` tidak dirujuk dari mana pun, dan tidak ada `package.json` di akar repo yang membuatnya
ikut `npm install`. `.github/workflows/` hanya menyebut `apps/control-plane`. `D:\Kerja\erp-dev` dan
`app-erp-ci-workflows` nol rujukan.

**Angka "dua baris dokumen" meleset: yang sebenarnya lima baris pada empat halaman.** Selain dua halaman
yang disebut daftar berkas, `docs/onboarding/hari-pertama.md` memuat path itu pada peta repo dan
`docs/dev/06-worktree-target.md` memuatnya dua kali — sekali sebagai keadaan sekarang, sekali pada pohon
target. Baris keadaan sekarang diperbaiki; baris pada pohon target ditahan dengan keterangan "belum ada",
karena memisahkan Web Shell sebagai app tersendiri masih tercatat terbuka sebagai `PLAT-20` di
[layanan platform](../general/04-layanan-platform.md). Yang salah bukan cita-citanya, melainkan pengakuan
bahwa foldernya sudah ada.

**Berkas audit di `docs/todo/general/` sengaja dibiarkan.** `LIFE-26` dan `PLAT-20` menyebut
`apps/web-shell` sebagai bukti temuan pada satu titik waktu, dan `LIFE-26` justru menyarankan penghapusan
ini sebagai salah satu dari dua pilihannya. Menyunting catatan bukti setelah kejadiannya membuat catatan
itu berhenti berguna sebagai bukti.

**Kriteria "tanpa tautan mati" bisa gagal, tapi ia tidak menjaga pekerjaan ini.** Menunjuk
`apps/web-shell/README.md` sebagai tautan Markdown dari `peta-kode.md` membuat build merah dengan
`Found dead link ./../../apps/web-shell/README in file onboarding\peta-kode.md` lalu
`[vitepress] 1 dead link(s) found.` — jadi kriterianya nyata. Tetapi kelima rujukan yang diperbaiki di
sini adalah *code span*, bukan tautan, dan VitePress tidak memeriksa isinya. Build tetap hijau seandainya
kelimanya dibiarkan menggantung. Yang menemukannya `grep`. Untuk task dokumen berikutnya: kriteria
`docs:build` menjaga tautan, bukan path yang dikutip sebagai kode.

**Stack lokal dibuktikan dari jalur pembangunannya, bukan dari menyalakan Docker.** `compose.yaml` erp-dev
membangun `apps/control-plane/Dockerfile` dengan konteks akar repo, dan Dockerfile itu hanya
`COPY apps/control-plane …` — sibling lain di `apps/` tidak pernah masuk image. Worktree ini juga bukan
checkout yang dipakai stack.

**Temuan sampingan untuk F7-06.** `package-lock.json` di akar repo dan `apps/package-lock.json` keduanya
ter-commit, keduanya berisi `"packages": {}`, dan tidak ada `package.json` yang menemani.

### F4-02 — `@apperp/ui` menjadi paket dalam repo

**Kenapa.** Hari ini paket dibangun menjadi berkas `.tgz` lalu disalin ke Core, ke setiap repo app, dan ke
template. Mekanisme itu butuh penyelarasan berkas kunci npm setiap kali dibangun ulang, dan sisa versi
lama masih tertinggal di repo sebagai bukti bahwa penyalinan bisa meleset.

**Berkas.**
- `apps/control-plane/package.json`
- `packages/ui/package.json`
- `apps/control-plane/.packages/apperp-ui.tgz` (dihapus)
- `packages/ui/apperp-ui-0.4.2.tgz` dan `packages/ui/.tmp-pack/` (dihapus)
- `erp-dev/start.ps1` (fungsi penerbit dan penyelaras berkas kunci, dihapus di F6-03)

**Langkah.**
1. Pakai npm workspaces: `packages/ui` menjadi workspace, Core memakainya sebagai `*`.
2. Hapus berkas `.tgz` yang ter-commit.
3. Pastikan pemindaian Tailwind tetap menemukan `packages/ui/dist` lewat satu deklarasi sumber.

**Selesai bila.** `npm run build` di Core menghasilkan bundel yang sama, dan tidak ada berkas `.tgz` di
repo.

**Rujukan.** Bagian 5.5 dokumen ini.

**Bergantung pada.** Tidak ada.

#### Catatan pelaksanaan

Selesai pada 8 September 2026 lewat pull request #67.

**Akar workspace-nya akar repo, dan rencana tidak menyebut itu.** Percobaan pertama menaruh akarnya di
`apps/control-plane` dengan `"workspaces": ["../../packages/ui"]`. npm menerimanya dan tautan
simboliknya terbentuk; yang tidak jalan adalah membangun paketnya. npm mengangkat dependensi workspace ke
`node_modules` milik akar, dan akar itu bukan leluhur `packages/ui`, jadi `tsc` di dalam paket berhenti
pada `TS2307: Cannot find module 'react'`. Akar karena itu pindah ke akar repo — yang sekaligus
menjelaskan kenapa `package-lock.json` akar sudah ada di repo sejak commit pertama tanpa `package.json`
yang menemaninya. Ekornya tiga: `npm ci` berpindah ke akar (dua alur CI dan `Dockerfile`), lockfile app
dihapus, dan `.npmrc` yang memuat `ignore-scripts=true` ikut pindah karena npm mengabaikan `.npmrc` milik
workspace — berkas penjaga yang tidak lagi menjaga apa pun adalah yang paling berbahaya dibiarkan.

**"Bundel yang sama" hampir gagal karena dua hal yang tidak ada hubungannya dengan pengemasan.** Pertama,
menyerahkan lockfile akar ke `npm install` menaikkan 209 paket sekaligus dan mengubah potongan bundel
besar-besaran: 203 berkas menjadi 163, total turun 337.194 bytes. Lockfile akar karena itu disusun dari
lockfile lama app entri per entri, sehingga satu-satunya selisih versi adalah `@apperp/ui` sendiri. Kedua,
`@vitejs/plugin-react` melewatkan `node_modules` secara bawaan; sebagai workspace, `packages/ui/dist`
keluar dari sana dan React Compiler mulai menggarap keluaran `tsc` milik paket itu — bundel bertambah
3.067 bytes di 14 potongan. Ditahan dengan `exclude` pada plugin. Hasil akhirnya 203 berkas di kedua sisi
dengan daftar nama yang sama persis, selisih 346 bytes: `manifest.json` +348 karena 29 kunci font
mendapat awalan `../../`, dan satu potongan `@inertiajs/core` −2 karena beda tanda kurung minifier.

**Pemindaian Tailwind dibuktikan dengan merusaknya.** Baris `@source` dihapus, build diulang:
`npm run build` tetap hijau tanpa satu peringatan pun, dan CSS keluarannya turun dari 202.891 menjadi
81.275 bytes — 1.053 selektor kelas hilang, termasuk `.max-h-\[300px\]{max-height:300px}` milik
`@apperp/ui/command`. Kegagalan yang tidak berbunyi seperti ini yang membuat kriteria "pastikan
pemindaian tetap menemukan" tidak bisa dipercaya tanpa dirusak lebih dulu.

**Dua penanganan F2-11 menjadi tidak perlu, keduanya karena sebab yang sama.** `resolve.dedupe` untuk
`@apperp/ui` dan enam pemetaan `paths` di `tsconfig.json` ada karena pendakian `node_modules` dari
`modules/*/*/ui` berakhir di akar repo yang kosong. Akar repo sekarang akar workspace, jadi pendakian itu
berhenti di tempat yang benar; keduanya dibuang setelah dibuktikan build dan `tsc --noEmit` tetap hijau
tanpanya. `react` dan `react-dom` tetap di `dedupe`: keduanya soal satu salinan React, bukan soal
penemuan berkas.

**`dist/` yang dulu selalu ada kini harus dibangun, dan yang menagihnya bukan cuma `build`.** Sebagai
berkas `.tgz` paket ini datang sudah terbangun; sebagai workspace ia baru lahir saat dibangun, dan
`ignore-scripts=true` menutup jalan `prepare`. Yang menemukan sisanya adalah CI merah: `format:check`
melaporkan 52 berkas tidak terformat sementara mesin pengembang hijau, karena `.prettierrc` menunjuk
`resources/css/app.css` yang mengimpor `@apperp/ui/styles.css` — tanpa `dist/`,
`prettier-plugin-tailwindcss` mengurutkan kelas dengan urutan lain. Delapan skrip akhirnya diawali
`ui:build`. Pelajarannya: pemeriksaan yang selama ini menumpang pada efek samping `npm ci` tidak
mengumumkan ketergantungannya sampai efek samping itu hilang.

**Kriteria "tidak ada berkas `.tgz` di repo" belum terpenuhi seluruhnya, dan itu keputusan.**
`modules/apperp/management-aset/ui/vendor/apperp-ui.tgz` masuk lewat subtree F3-01 setelah rencana ini
ditulis. Folder itu masih proyek Vite tersendiri dengan `Dockerfile` berkonteks foldernya sendiri;
menunjuknya ke `packages/ui` menaruh dependensinya di luar konteks build itu, jadi `Dockerfile`-nya harus
ditulis ulang — untuk build yang di repo ini tidak dijalankan siapa pun, dan yang berkasnya dihapus
seluruhnya oleh F4-03.

**Tahap aset `Dockerfile` ternyata tidak pernah menyalin `modules/` maupun `vendor/`.** Dua dari empat
deklarasi `@source` karena itu menunjuk folder yang tidak ada di dalam image, dan glob halaman modul di
`resources/js/app.tsx` tidak menemukan apa pun — image release dibangun tanpa halaman modul. Keadaannya
sudah begitu sebelum task ini dan tidak diubah di sini; yang pertama wilayah F4-03.

### F4-03 — Halaman modul pindah ke dalam repo shell

**Kenapa.** Selama UI modul berada di proyek Vite sendiri, React akan selalu terbundel dua kali.

**Berkas.**
- `modules/apperp/management-aset/ui/` (dari `ui/src/`)
- `modules/apperp/management-aset/ui/package.json` (dihapus)
- `modules/apperp/management-aset/ui/vite.config.ts` (dihapus)
- `modules/apperp/management-aset/ui/Dockerfile` dan `nginx.conf` (dihapus)
- `apps/control-plane/vite.config.ts` (tambahkan alias)
- `apps/control-plane/resources/css/app.css` (tambahkan sumber pemindaian Tailwind)

**Langkah.**
1. `git mv` isi `ui/src` ke `modules/apperp/management-aset/ui`.
2. Rantai impor relatif antar berkas modul tetap utuh setelah dipindah, jadi tidak ada penulisan ulang
   impor kecuali untuk `@apperp/ui`.
3. Tambahkan alias di `vite.config.ts` Core supaya folder modul bisa diimpor. Core hari ini belum punya
   `resolve.alias` sama sekali, jadi ini penambahan pertama.
4. Tambahkan folder UI modul ke pemindaian Tailwind Core.

**Selesai bila.** `npm run build` di Core berhasil dan menyertakan halaman modul.

**Rujukan.** Bagian 5.5 dokumen ini.

**Bergantung pada.** F4-02.

### F4-04 — Perutean modul memakai rute shell

**Kenapa.** Perutean hash di UI modul ada karena satu image harus bisa disajikan di bawah awalan mana pun.
Setelah UI menyatu, awalan itu tidak ada lagi.

**Berkas.**
- `modules/apperp/management-aset/ui/App.tsx`
- `apps/control-plane/routes/web.php`
- `apps/control-plane/resources/js/pages/modules/host.tsx`

**Langkah.**
1. Ganti `useHashRoute` dengan pembacaan parameter `view` dari properti halaman Inertia.
2. Rantai percabangan yang memilih halaman berdasarkan sumber daya dan izin dipertahankan; hanya sumber
   nilainya yang berubah.
3. Halaman modul dimuat dengan `React.lazy` per item menu, sehingga kode modul terpecah seperti dulu
   terpecah oleh iframe.

**Selesai bila.** Berpindah antar menu modul mengganti isi halaman tanpa memuat ulang seluruh dokumen.

**Rujukan.** Bagian 5.5 dokumen ini.

**Bergantung pada.** F4-03.

### F4-05 — Buang jabat tangan antar bingkai

**Kenapa.** Tiga jenis pesan antar bingkai ada hanya karena UI berada di dalam iframe: pemberitahuan
siap, pengiriman konteks dan tema, serta permintaan cetak.

**Berkas.**
- `modules/apperp/management-aset/ui/shell.ts` (dihapus)
- `modules/apperp/management-aset/ui/App.tsx`
- `apps/control-plane/resources/js/lib/print-requests.ts`
- `apps/control-plane/resources/js/lib/notifications.ts`
- `apps/control-plane/resources/js/components/print-dialog.tsx`

**Langkah.**
1. Tema tidak perlu dikirim; modul memakai kait tema Core yang sudah ada.
2. Permintaan cetak menjadi pemanggilan fungsi biasa ke dialog cetak Core.
3. Pemberitahuan dari modul menjadi pemanggilan fungsi ke lonceng pemberitahuan Core. Penyimpanan di
   peramban tetap seperti sekarang.
4. Pemeriksa bentuk pesan pada dua berkas pustaka Core dipertahankan bila masih ada pengirim luar;
   kalau tidak ada, dihapus.

**Selesai bila.** Pencarian `postMessage` di folder modul tidak menemukan apa pun, dan tombol cetak masih
bekerja.

**Rujukan.** Bagian 5.5 dokumen ini.

**Bergantung pada.** F4-04.

### F4-06 — Panggilan API modul memakai sesi

**Kenapa.** Pembungkus permintaan di UI modul menyusun alamat relatif terhadap dokumen dan menyertakan
token pembawa. Keduanya hanya masuk akal ketika UI disajikan di bawah awalan penempatan.

**Berkas.**
- `modules/apperp/management-aset/ui/api.ts`
- `modules/apperp/management-aset/src/Http/Controllers/ContextController.php` (dihapus)

**Langkah.**
1. Alamat menjadi `/api/modules/management-aset/v1/...` sesuai F3-13.
2. Header `Authorization` dihapus; permintaan memakai sesi dan token CSRF Core.
3. Kunci idempoten dan penormalan pesan kesalahan validasi dipertahankan apa adanya.
4. Izin dibaca dari properti halaman Inertia, bukan dari endpoint konteks.

**Selesai bila.** Seluruh layar modul memuat datanya, dan tidak ada permintaan yang membawa header
`Authorization`.

**Rujukan.** Bagian 5.5 dokumen ini.

**Bergantung pada.** F4-05.

### F4-07 — Ganti bingkai dengan komponen

**Kenapa.** Ini langkah yang menghapus iframe.

**Berkas.**
- `apps/control-plane/resources/js/pages/apps/host.tsx` (dihapus)
- `apps/control-plane/resources/js/pages/modules/host.tsx`
- `apps/control-plane/routes/web.php`
- `apps/control-plane/app/Support/LaunchableAppCatalog.php`

**Langkah.**
1. Rute `apps/{app}` berhenti menerbitkan token konteks dan alamat konten.
2. Tautan menu menunjuk rute shell, bukan sumber bingkai.
3. Penyaringan menu berdasarkan izin tetap memakai `LaunchableAppCatalog::navigationFor()`.
4. Muat ulang berkala setiap empat menit dihapus; ia ada hanya untuk menyegarkan token lima menit.

**Selesai bila.** Halaman modul tidak memiliki elemen `iframe`, dan menu tetap tersaring sesuai izin.

**Rujukan.** Bagian 5.5 dokumen ini.

**Bergantung pada.** F4-06.

### F4-08 — Buang jalur konten dan proxy

**Kenapa.** Perintah pembuat konfigurasi proxy, kelas penyusun jalur konten, dan dua modul Apache ada
hanya untuk mengarahkan permintaan ke container UI per app.

**Berkas.**
- `apps/control-plane/app/Console/Commands/RenderAppProxyConfigCommand.php` (dihapus)
- `apps/control-plane/app/Support/AppContentPath.php` (dihapus)
- `apps/control-plane/tests/Unit/AppContentPathTest.php` (dihapus)
- `apps/control-plane/docker/entrypoint.sh`
- `apps/control-plane/Dockerfile`
- `deploy/apps-content-proxy.md` (dihapus)

**Langkah.**
1. Hapus pemanggilan pembuat konfigurasi proxy dari titik masuk container.
2. Hapus pengaktifan modul proxy Apache dari Dockerfile.
3. Hapus dokumen jalur konten dan perbaiki tautan yang menunjuk ke sana.

**Selesai bila.** Container menyala tanpa konfigurasi proxy, dan `npx vitepress build docs` lulus.

**Rujukan.** [release dan on-prem](../../dev/03-release-and-on-prem.md).

**Bergantung pada.** F4-07.

### F4-09 — Token konteks tinggal untuk event

**Kenapa.** Kunci penandatangan dipakai dua hal: token konteks untuk bingkai dan HMAC event keluar.
Pemakaian pertama hilang di F4-07.

**Berkas.**
- `apps/control-plane/app/Support/AppContextToken.php`
- `apps/control-plane/tests/Unit/AppContextTokenTest.php`

**Langkah.**
1. Pertahankan kelasnya bila masih ada app di luar proses; beri komentar bahwa ia sekarang hanya melayani
   itu.
2. Kalau tidak ada satu pun app luar, hapus kelas dan test-nya, dan pastikan HMAC event memakai kunci yang
   sama lewat jalurnya sendiri.

**Selesai bila.** Keputusannya tertulis di PR, dan kunci penandatangan hanya punya satu pemakai yang
tersisa.

**Rujukan.** Bagian 5.4 dokumen ini.

**Bergantung pada.** F4-07.

### F4-10 — Ukur bundel dan buktikan tidak ada penggandaan

**Kenapa.** Prinsip P5. Ini angka yang menggantikan klaim penghematan pada dokumen keputusan.

**Berkas.**
- `docs/todo/satu-runtime/00-keputusan.md`
- `apps/control-plane/vite.config.ts`

**Langkah.**
1. Bangun bundel, catat ukuran total dan ukuran potongan bersama.
2. Pastikan React hanya muncul di satu potongan.
3. Bandingkan dengan angka sebelum pemindahan, yaitu bundel shell ditambah 702 KB milik modul aset.
4. Ganti baris proyeksi pada dokumen keputusan dengan angka terukur.

**Selesai bila.** Dokumen keputusan memuat angka nyata, dan pemeriksaan otomatis gagal bila React muncul
di lebih dari satu potongan.

**Rujukan.** [keputusan satu runtime](00-keputusan.md).

**Bergantung pada.** F4-07.

## 12. Fase 5: edisi dan bundle on-prem

**Kriteria keluar.** Dua bundle edisi berbeda dibangun dari repo yang sama, dan CI membuktikan modul yang
tidak dibeli tidak ada di dalamnya.

### F5-01 — Berkas manifest edisi

**Kenapa.** Ini Customer Edition Manifest yang sudah dijelaskan pada dokumen rilis tapi belum pernah
dibangun.

**Berkas.**
- `editions/apotek-sejahtera.yaml`
- `editions/praktek-dr-budi.yaml`
- `editions/README.md`

**Langkah.**
1. Bentuknya: pelanggan, profil penempatan, nomor rilis, dan daftar modul.
2. Dua contoh dibuat sekaligus supaya perbedaannya bisa diuji.

**Selesai bila.** Kedua berkas ada dan bentuknya dijelaskan pada `README.md` di folder yang sama.

**Rujukan.** [release dan on-prem](../../dev/03-release-and-on-prem.md).

**Bergantung pada.** Tidak ada.

### F5-02 — Penghitung dependency edisi

**Kenapa.** Daftar modul yang dibeli belum lengkap. Dependency dan modul penghubung harus ikut, dan modul
penghubung hanya ikut bila kedua modul yang dihubungkannya ada.

**Berkas.**
- `apps/control-plane/app/Support/Modules/EditionResolver.php`
- `apps/control-plane/app/Console/Commands/EditionResolveCommand.php`
- `apps/control-plane/tests/Feature/ControlPlane/EditionResolverTest.php`

**Langkah.**
1. Baca manifest edisi, tutup seluruh dependency secara transitif memakai `AppDependencyGraph` yang sudah
   ada.
2. Tambahkan modul penghubung yang seluruh dependency-nya berada di dalam hasil hitungan.
3. Perintah `edition:resolve {edisi}` mencetak daftar akhirnya.

**Selesai bila.** Edisi apotek menghasilkan daftar tanpa modul rawat jalan, dan test membuktikan modul
penghubung ikut hanya ketika kedua sisinya ada.

**Rujukan.** [standar app, dependency](../../dev/02-module-standard.md).

**Bergantung pada.** F5-01.

### F5-03 — Image per edisi

**Kenapa.** Tidak ada satu pun Dockerfile hari ini yang sadar modul; semuanya menyalin seluruh repo.

**Berkas.**
- `apps/control-plane/Dockerfile`
- `scripts/build-edition.sh`

**Langkah.**
1. Dockerfile menerima daftar modul sebagai argumen bangun.
2. Tahap penyalinan hanya mengambil folder modul pada daftar itu.
3. Bangunan aset juga hanya menyertakan UI modul pada daftar itu.

**Selesai bila.** Image edisi apotek berhasil dibangun dan menyala.

**Rujukan.** Bagian 5.6 dokumen ini.

**Bergantung pada.** F5-02.

### F5-04 — Pemeriksaan kebocoran modul

**Kenapa.** Klaim bahwa modul yang tidak dibeli tidak ada di server pelanggan harus dibuktikan mesin,
bukan dijanjikan. Ini pemeriksaan yang membuat seluruh model lisensi berdiri.

**Berkas.**
- `scripts/verify-edition.sh`
- `.github/workflows/edition.yml`

**Langkah.**
1. Bangun image edisi, lalu cari nama namespace modul yang tidak dibeli di dalam berkas image.
2. Jalankan migrasi ke database kosong, lalu daftar nama tabel yang terbentuk. Tidak boleh ada tabel
   berawalan milik modul yang tidak dibeli.
3. Periksa bundel JavaScript untuk rute modul yang tidak dibeli.
4. Buktikan pemeriksa bisa gagal: tambahkan satu modul ke daftar, jalankan, catat pesannya, lalu
   kembalikan. Ini mengikuti pelajaran yang sama dengan F1-07.
5. Tolak modul ber-`kind: internal-fixture` pada edisi mana pun, dan buktikan penolakannya dengan test.
   `contoh-a` dan `contoh-b` hidup di repo sampai fase 7; sebuah menu bernama "Contoh A" di layar
   pelanggan adalah kegagalan yang tidak boleh mungkin terjadi.

**Selesai bila.** Pemeriksaan hijau untuk dua edisi contoh, dan pesan gagalnya tercatat di PR.

**Rujukan.** Bagian 5.6 dokumen ini, prinsip P2.

**Bergantung pada.** F5-03.

### F5-05 — Catatan rilis berbentuk satu image

**Kenapa.** Catatan rilis hari ini mewajibkan dua sidik jari image, yaitu API dan UI, ditambah tiga nama
layanan. Bentuk itu tidak ada lagi. Tiga berkas test mengunci bentuk lama, dan salah satunya adalah test
laporan yang akan gagal karena alasan yang tidak terlihat berhubungan.

**Berkas.**
- `apps/control-plane/app/Models/AppRelease.php`
- `apps/control-plane/app/Console/Commands/BootstrapLocalAppRuntimeCommand.php`
- `apps/control-plane/app/Http/Requests/Provider/AppReleaseRequest.php`
- `apps/control-plane/tests/Feature/ControlPlane/BootstrapLocalAppRuntimeTest.php`
- `apps/control-plane/tests/Feature/ControlPlane/AppCatalogManagementTest.php`
- `apps/control-plane/tests/Feature/ControlPlane/ReportingTest.php`

**Langkah.**
1. Ganti dua kolom image dengan satu kolom image edisi.
2. Nama layanan API, UI, dan database tidak lagi wajib.
3. Perbarui ketiga berkas test. Test laporan memakai perintah ini hanya sebagai persiapan; sebutkan itu di
   PR supaya peninjau tidak bingung.

**Selesai bila.** Ketiga test lulus dengan bentuk rilis yang baru.

**Rujukan.** [menerbitkan release app](../../dev/13-publishing-an-app-release.md).

**Bergantung pada.** F5-03.

### F5-06 — Bundle dan pemasangan di server pelanggan

**Kenapa.** Ini bagian yang belum pernah dibangun sama sekali, dan satu-satunya alasan seluruh arsitektur
ini ada.

**Berkas.**
- `scripts/build-bundle.sh`
- `scripts/update.sh`
- `deploy/compose.edition.yaml`

**Langkah.**
1. Bundle berisi image edisi hasil simpan, berkas compose, manifest rilis, checksum, dan tanda tangan.
2. Skrip pemasangan menjalankan urutan tetap: periksa tanda tangan, cadangkan database, muat image,
   ganti container, jalankan migrasi modul terpasang, periksa kesehatan, dan kembalikan ke image lama bila
   gagal.
3. Image lama tidak dihapus supaya pengembalian cukup mengganti satu nomor versi.

**Selesai bila.** Bundle edisi apotek dipasang di mesin virtual bersih tanpa git, tanpa composer, dan
tanpa npm, lalu berhasil dimutakhirkan ke versi berikutnya dan dikembalikan lagi.

**Rujukan.** [release dan on-prem](../../dev/03-release-and-on-prem.md).

**Bergantung pada.** F5-04, F5-05.

## 13. Fase 6: dev stack dan CI

**Kriteria keluar.** Menjalankan skrip pengembangan menyalakan satu runtime dengan modul terpilih, dan
satu alur CI menjaga seluruh repo.

### F6-01 — Penemuan modul pada skrip pengembangan

**Kenapa.** Skrip hari ini mencari folder saudara berpola tertentu dan mewajibkan tiap app punya dua
Dockerfile. Modul di dalam repo tidak punya keduanya, jadi tidak akan pernah ditemukan.

**Berkas.**
- `erp-dev/start.ps1`

**Langkah.**
1. Ganti pemindaian folder saudara dengan pemindaian folder modul di dalam repo.
2. Hapus syarat keberadaan Dockerfile per app.
3. Pilihan modul memakai id manifest, dan dependency tetap diselesaikan dengan pengurutan yang sudah ada.

**Selesai bila.** Menjalankan skrip dengan satu modul terpilih menyalakan Core beserta modul itu saja.

**Rujukan.** [pengembangan lokal](../../dev/11-local-docker-development.md).

**Bergantung pada.** F3-20.

### F6-02 — Satu compose

**Kenapa.** Berkas compose yang dihasilkan berisi tiga layanan dan satu volume per app. Semuanya hilang.

**Berkas.**
- `erp-dev/compose.yaml`
- `erp-dev/compose.apps.yaml` (dihapus)
- `erp-dev/start.ps1`

**Langkah.**
1. Hapus pembuat berkas compose per app beserta templat layanan database, API, dan UI.
2. Hapus pengalokasian tiga porta per app dan pengurusan rahasia per app.
3. Layanan Core tetap: aplikasi, pekerja, penjadwal, database, perender, dan dokumentasi.

**Selesai bila.** Stack lokal menyala dengan enam container, dan halaman modul terbuka.

**Rujukan.** [pengembangan lokal](../../dev/11-local-docker-development.md).

**Bergantung pada.** F6-01.

### F6-03 — Buang penyebaran paket antarmuka

**Kenapa.** Fungsi penerbit paket dan penyelaras berkas kunci ada hanya untuk menyalin berkas `.tgz` ke
banyak repo.

**Berkas.**
- `erp-dev/start.ps1`
- `app-erp-template/ui/vendor/` (di repo template)

**Langkah.**
1. Hapus kedua fungsi itu beserta pemanggilannya.
2. Hapus salinan paket di repo template.

**Selesai bila.** Menjalankan skrip pengembangan tidak lagi menyentuh berkas paket antarmuka mana pun.

**Rujukan.** Bagian 5.5 dokumen ini.

**Bergantung pada.** F4-02, F6-02.

### F6-04 — Satu alur CI

**Kenapa.** Hari ini hanya satu repo app yang punya pemeriksaan CI, dan alur bersamanya mewajibkan susunan
repo terpisah. Tidak ada satu pun alur yang membangun atau menerbitkan image.

**Berkas.**
- `.github/workflows/tests.yml`
- `.github/workflows/lint.yml`
- `.github/workflows/edition.yml`
- `app-erp-ci-workflows/actions/validate-app-repository/validate_app_repository.py`

**Langkah.**
1. Alur test menjalankan test Core dan seluruh modul dalam satu perintah.
2. Pemeriksa susunan repo diubah menjadi pemeriksa susunan modul: manifest sah, rantai keamanan lengkap,
   kontrak ada bila memang ada permukaan yang dipanggil dari luar runtime, dan awalan tabel modul
   terdaftar pada tabel pemetaan di `modules/README.md`.
3. Syarat lama tentang Dockerfile per app, potongan compose, dan skrip migrasi per app dihapus. Perhatikan
   bahwa pemeriksa itu juga menolak ejaan kunci dependency yang masih dipakai skrip pengembangan; samakan
   keduanya pada PR ini.

**Selesai bila.** Satu PR yang menyentuh Core dan modul dijaga satu alur, dan alur edisi dari F5-04 ikut
berjalan.

**Rujukan.** [CI/CD](../../dev/22-ci-cd.md).

**Bergantung pada.** F5-04.

### F6-05 — Alur bangun dan terbit image

**Kenapa.** Tidak ada satu pun repo yang membangun dan mendorong image hari ini. Tanpa ini, bundle edisi
harus dibangun di laptop.

**Berkas.**
- `.github/workflows/release.yml`
- `docs/dev/22-ci-cd.md`

**Langkah.**
1. Bangun image sekali dari cabang utama, beri sidik jari, dan dorong ke registry.
2. Bundle edisi dibuat dari image yang sudah lulus, bukan dibangun ulang.
3. Awalan versi bergerak dilarang untuk penempatan.
4. Perbarui dokumen CI supaya menggambarkan yang benar-benar ada, dan tandai bagian yang masih rencana.

**Selesai bila.** Satu penggabungan ke cabang utama menghasilkan image bersidik jari di registry.

**Rujukan.** [CI/CD](../../dev/22-ci-cd.md).

**Bergantung pada.** F6-04.

## 14. Fase 7: modul kedua, pengukuran, dan pembersihan

**Kriteria keluar.** Human Resources berjalan sebagai modul kedua, angka proyeksi pada dokumen keputusan
diganti angka terukur, dan repo lama diarsipkan dengan penunjuk ke lokasi barunya.

### F7-01 — Human Resources menjadi modul kedua

**Kenapa.** Satu modul tidak membuktikan batas antar modul. Modul kedua yang punya kebutuhan berbeda,
misalnya direktori anggota dan unit organisasi, membuktikannya.

**Berkas.**
- `modules/apperp/human-resources/`

**Langkah.**
1. Ikuti urutan fase 3 dalam bentuk ringkas: bawa masuk beserta riwayat, bentuk ulang, ganti nama tabel
   ke awalan `hr_`, ganti pemanggilan HTTP, pindahkan test.
2. Dua endpoint direktori Core yang hari ini hanya boleh dipanggil modul ini menjadi pemanggilan fungsi
   lewat antarmuka F2-06.
3. Pastikan ketiga penjaga F1-04, F1-05, dan F1-06 tetap hijau dengan dua modul terpasang.

**Selesai bila.** Kedua modul berjalan bersamaan, dan mencabut satu tidak menyentuh data yang lain.

**Rujukan.** Fase 3 dokumen ini.

**Bergantung pada.** F4-10.

### F7-02 — Ukur ulang dan ganti proyeksi

**Kenapa.** Prinsip P5. Dokumen keputusan memuat baris proyeksi yang ditandai jelas; sekarang ada angka
nyata untuk menggantikannya.

**Berkas.**
- `docs/todo/satu-runtime/00-keputusan.md`
- `docs/todo/satu-runtime/01-prd.md`

**Langkah.**
1. Ukur pemakaian memori idle dan saat beban dengan dua modul terpasang.
2. Ukur ukuran bundel dan jumlah container.
3. Ukur waktu pasang dari mesin virtual bersih sampai halaman masuk terbuka.
4. Ganti setiap baris proyeksi dengan angka terukur, dan sebutkan tanggal pengukurannya.

**Selesai bila.** Tidak ada lagi kata proyeksi pada tabel perbandingan.

**Rujukan.** [keputusan satu runtime](00-keputusan.md).

**Bergantung pada.** F7-01.

### F7-03 — Uji beban di runtime baru

**Kenapa.** Skenario beban yang ada menguji hal yang benar: kebocoran antar tenant, idempotensi, dan
perlombaan penguncian. Semuanya harus tetap lulus setelah pemindahan, dan sekarang tanpa tiruan Core.

**Berkas.**
- `apps/control-plane/loadtest/`
- `modules/apperp/management-aset/loadtest/`

**Langkah.**
1. Gabungkan dua stack uji beban menjadi satu yang menjalankan runtime nyata.
2. Hapus layanan tiruan Core; nomor sekarang diterbitkan proses yang sama.
3. Jalankan skenario penjenuhan dan skenario perlombaan tautan; keduanya harus tetap nol pelanggaran.

**Selesai bila.** Seluruh oracle kebenaran mengembalikan nol, dan hasilnya tersimpan.

**Rujukan.** [load dan concurrency testing](../../dev/20-load-and-concurrency-testing.md).

**Bergantung pada.** F7-01.

### F7-04 — Arsipkan repo lama

**Kenapa.** Repo yang masih bisa ditulis akan menerima perubahan yang hilang, dan itu terjadi diam-diam.

**Berkas.**
- `README.md` pada tiap repo app lama

**Langkah.**
1. Ganti isi berkas pengantar dengan penunjuk ke lokasi baru di dalam repo utama.
2. Setel repo menjadi arsip di penyedia hosting.
3. Repo yang masih kerangka kosong cukup dihapus; tidak ada yang perlu diselamatkan.

**Selesai bila.** Repo lama berstatus arsip dan berkas pengantarnya menunjuk lokasi baru.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F7-01.

### F7-05 — Perbarui dokumen yang terpengaruh

**Kenapa.** Dokumen yang menggambarkan susunan lama akan menyesatkan orang berikutnya, dan repo ini sudah
punya aturan bahwa fitur belum selesai sampai halamannya ada.

**Berkas.**
- `docs/dev/01-grand-design.md`
- `docs/dev/02-module-standard.md`
- `docs/dev/03-release-and-on-prem.md`
- `docs/dev/04-api-and-integration.md`
- `docs/dev/07-reporting-and-replicas.md`
- `docs/dev/11-local-docker-development.md`
- `docs/dev/12-external-module-integration.md`
- `docs/dev/14-number-sequences.md`
- `docs/dev/22-ci-cd.md`
- `docs/dev/23-document-rendering.md`
- `docs/onboarding/peta-kode.md`

**Langkah.**
1. Kerjakan satu halaman per PR, jangan sebelas sekaligus.
2. Tandai bagian yang berubah karena keputusan ini, dan sebutkan halaman keputusannya.
3. Bagian yang tetap benar jangan disentuh.

**Selesai bila.** `npx vitepress build docs` lulus, dan tidak ada halaman yang masih menyebut satu
database per app sebagai keadaan sekarang.

**Rujukan.** [keputusan satu runtime](00-keputusan.md).

**Bergantung pada.** F7-02.

### F7-06 — Bersihkan sisa

**Kenapa.** Beberapa berkas sudah tidak dipakai sebelum proyek ini dimulai, dan sekarang waktunya
sekalian.

**Berkas.**
- `apps/control-plane/resources/js/hooks/use-mobile.ts` atau `use-mobile.tsx` (salah satu, duplikat)
- `apps/control-plane/resources/js/assets/lottie/` (sisa templat lama)
- `deploy/ci/workflows/` (folder kosong)
- `app-erp-deployment` (berkas compose dan skrip yang menyebut enam image)

**Langkah.**
1. Hapus satu per satu, satu PR untuk semuanya karena tidak ada yang berisiko.
2. Untuk repo penyebaran, ganti dua berkas compose dengan satu yang memakai image edisi.

**Selesai bila.** Tidak ada berkas mati yang tersisa, dan repo penyebaran menggambarkan cara pasang yang
sebenarnya dipakai.

**Rujukan.** Bagian 5.6 dokumen ini.

**Bergantung pada.** F7-05.

### F7-07 — Dokumentasi di luar folder desain ikut diperbarui

**Kenapa.** Task pembaruan dokumen hanya mendaftar halaman di folder desain dan satu halaman orientasi.
Yang terlewat justru yang paling spesifik menjelaskan susunan lama: panduan membangun app baru dengan
sepuluh tahapnya, empat halaman orientasi, dan enam halaman arsitektur app. Totalnya sekitar 1.200 baris.

**Berkas.**
- `docs/apps/membangun-app-baru.md`
- `docs/apps/management-aset/arsitektur/integrasi-core.md`
- `docs/apps/management-aset/arsitektur/database.md`
- `docs/apps/management-aset/arsitektur/kontrak.md`
- `docs/apps/management-aset/arsitektur/pengujian.md`
- `docs/apps/management-aset/arsitektur/batas-tenant-dan-organisasi.md`
- `docs/apps/management-aset/arsitektur/index.md`
- `docs/onboarding/hari-pertama.md`
- `docs/onboarding/setup.md`
- `docs/onboarding/index.md`
- `docs/onboarding/definition-of-done.md`

**Langkah.**
1. Satu halaman per pull request, jangan sebelas sekaligus.
2. Halaman integrasi Core menjelaskan empat klien HTTP yang sudah dihapus; ia ditulis ulang, bukan
   ditambal.
3. Panduan membangun app baru berubah menjadi panduan membangun modul baru, dan tahap yang menyebut
   repositori dari template, masuk stack lokal, serta katalog dan release ikut berubah isinya.

**Selesai bila.** Situs dokumentasi terbangun tanpa tautan mati, dan tidak ada halaman yang masih
mengajarkan satu container per app sebagai keadaan sekarang.

**Rujukan.** [keputusan satu runtime](00-keputusan.md).

**Bergantung pada.** F7-02.

### F7-08 — Cetakan modul baru menggantikan repo template

**Kenapa.** Repo template dipakai untuk membuat app baru, dan disebut pada panduan membangun app serta
tiga halaman orientasi. Setelah repo itu dipensiunkan, tidak ada satu pun task yang menyediakan
penggantinya, sehingga membuat modul baru menjadi menyalin folder modul lama dan menebak apa yang harus
diubah.

**Berkas.**
- `modules/_template/`
- `apps/control-plane/app/Console/Commands/ModuleMakeCommand.php`
- `docs/apps/membangun-app-baru.md`

**Langkah.**
1. Cetakan berisi bentuk minimal satu modul: manifest, satu migration bertabel berawalan, satu model
   bertenant, satu rute, satu halaman, dan satu test.
2. Perintah pembuat modul menyalin cetakan itu dan mengganti nama serta awalan tabelnya.
3. Manifest cetakan memakai id `change-me` supaya dilewati registry, seperti perilaku yang sudah ada.

**Selesai bila.** Perintah pembuat modul menghasilkan modul yang langsung lulus ketiga penjaga tanpa
diubah.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F7-04.

### F7-09 — Keputusan yang mengeras dipindahkan ke desain kanonik

**Kenapa.** Folder tempat dokumen ini berada adalah pekerjaan sekali jalan yang boleh dihapus setelah
selesai. Aturan yang lahir dari pekerjaan ini harus berpindah menjadi desain kanonik, kalau tidak ia ikut
terhapus bersama rencana kerjanya.

**Berkas.**
- `docs/dev/02-module-standard.md` (awalan tabel, `tenant_id` wajib, retensi)
- `docs/dev/04-api-and-integration.md` (kontrak layanan Core yang boleh dipanggil modul)
- `docs/dev/03-release-and-on-prem.md` (bentuk edisi dan bundle)
- `docs/dev/11-local-docker-development.md` (susunan runtime)
- `docs/todo/satu-runtime/` (dihapus setelah isinya pindah)

**Langkah.**
1. Pindahkan aturan, bukan menyalin. Setelah pindah, hapus dari dokumen rencana supaya tidak ada dua
   sumber yang bisa menyimpang.
2. Berkas bukti penjaga disimpan sebagai lampiran pada halaman standar app, karena ia menjelaskan kenapa
   penjaganya berbentuk begitu.
3. Folder rencana kerja dihapus terakhir, setelah seluruh isinya punya rumah baru.

**Selesai bila.** Folder rencana kerja kosong dan dihapus, dan seluruh aturannya dapat ditemukan dari
indeks desain kanonik.

**Rujukan.** [ikhtisar](index.md).

**Bergantung pada.** F7-07.

## 15. Rencana mundur

Setiap fase punya jalan mundur yang berbeda, dan jalannya harus diketahui sebelum fase dimulai.

| Fase | Cara mundur | Yang hilang bila mundur |
| --- | --- | --- |
| 0 | Kembalikan pull request-nya | Tidak ada. Belum ada kode yang dipindah |
| 1 | Kembalikan pull request penjaga dan modul contoh | Tidak ada. Core belum bergantung padanya |
| 2 | Kembalikan pull request-nya; app lama masih berjalan sebagai container | Kerangka modul, bukan data |
| 3 | Repo modul lama masih ada dan belum diarsipkan | Pekerjaan pemindahan, bukan data |
| 4 | Balikkan seluruh task fase ini, lalu bangun ulang image UI modul | Bukan sekadar mengembalikan bingkai. Berkas pembangunan UI modul sudah dihapus, jadi tidak ada image yang bisa ditunjuk bingkai |
| 5 | Tidak ada jalan mundur, karena bundle edisi belum pernah ada sebelumnya | Kemampuan yang memang belum pernah dimiliki |
| 6 | Skrip lama ada di riwayat, tapi ia menuntut Dockerfile per app yang sudah dihapus pada fase 4 | Stack pengembangan yang berjalan, bukan sekadar kenyamanan |
| 7 | Tidak ada. Ini fase penutup | Tidak ada |

Titik yang tidak bisa dibalik dengan mudah adalah **F3-01**, yaitu saat repo modul ditarik masuk. Sejak
itu pekerjaan bercabang, dan repo lama menjadi basi walau masih bisa ditulis. Pengarsipan repo pada fase
7 hanya membuat keadaan itu terlihat, bukan menciptakannya.

Karena itu, sebelum F3-01 dijalankan, repo modul wajib dalam keadaan bersih dan seluruh commit-nya sudah
terdorong ke remote. Pemeriksaan itu adalah langkah pertama task tersebut, bukan anggapan.

## 16. Ukuran keberhasilan

Diperiksa saat fase 7 selesai, dan dibandingkan dengan angka pada
[dokumen keputusan](00-keputusan.md).

| Yang diukur | Sebelum | Sasaran |
| --- | --- | --- |
| Container di server pelanggan dengan dua modul | 11 | 5 |
| Database di server pelanggan | 3 | 1 |
| Pemakaian memori idle | 275 MiB dengan satu modul | di bawah 250 MiB dengan dua modul |
| Lompatan jaringan saat membuat satu dokumen | 3 | 0 |
| Berkas React di bundel | satu per app ditambah shell | satu |
| Image yang dibangun per rilis | 6 | 1 |
| Langkah memasang di server pelanggan | belum pernah dibuktikan | satu skrip, terbukti di mesin virtual bersih |
| Waktu satu fitur lintas modul | dua repo, dua PR | satu PR |
