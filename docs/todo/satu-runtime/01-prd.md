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

### F2-03 — Migrator per modul

**Kenapa.** Migration modul harus bisa dijalankan sendiri, per tenant, dan riwayatnya dicatat terpisah
supaya menjalankan ulang tidak mengulang yang sudah jalan.

**Berkas.**
- `apps/control-plane/app/Support/Modules/ModuleMigrator.php`
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

**Selesai bila.** Halaman modul contoh terbuka untuk pengguna yang berhak, tanpa satu pun baris
penempatan container.

**Rujukan.** [empat kebenaran lifecycle](../../onboarding/empat-kebenaran.md).

**Bergantung pada.** F2-05.

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

**Selesai bila.** Modul contoh menerbitkan satu nomor lewat antarmuka ini, dan analisa statis menolak
modul yang memanggil model Core langsung.

**Rujukan.** [number sequence](../../dev/14-number-sequences.md), bagian 5.3 dokumen ini.

**Bergantung pada.** F2-02.

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
3. Test memastikan pengguna tanpa izin mendapat 403 pada rute modul contoh.

**Selesai bila.** Rute modul contoh terlindungi tanpa token, dan atribut permintaannya sama dengan yang
dibaca app lama.

**Rujukan.** [identity dan access](../../dev/09-identity-and-access.md).

**Bergantung pada.** F2-01.

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

## 10. Fase 3: Management Aset pindah ke dalam Core

Ini fase terbesar. Yang dipindah: 99 berkas PHP, 42 migration yang menghasilkan 45 tabel, 22 berkas test,
dan manifest berisi 65 entry point, 122 permission, 64 privilege, 36 duty, 29 referensi nomor, 2 tipe
workflow, dan 2 laporan.

**Kriteria keluar.** Seluruh test Management Aset lulus di dalam Core pada PostgreSQL, dan pencarian
`Http::` di folder modul tidak menemukan satu pun panggilan ke Core.

**Urutan yang dipakai.** Pindahkan berkasnya dulu tanpa mengubah perilaku, baru ganti jalur
pemanggilannya satu per satu. Membalik urutan ini membuat setiap PR menyentuh dua hal sekaligus dan
sulit ditinjau.

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

**Selesai bila.** `git log -- modules/apperp/management-aset` menampilkan 35 commit asli, dan
`git blame` pada sebuah controller menunjukkan penulis aslinya.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F2-05.

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

**Selesai bila.** Folder modul tidak lagi berisi kerangka Laravel, dan Core tetap menyala.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F3-01.

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

**Selesai bila.** `composer types:check` lulus, dan tidak ada lagi rujukan ke `../database/migrations` di
seluruh repo.

**Rujukan.** Bagian 5.1 dokumen ini.

**Bergantung pada.** F3-02.

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

**Selesai bila.** Seluruh tabel modul berawalan `aset_`, tidak ada tabel modul tanpa awalan, dan penjaga
F1-04 lulus untuk modul ini. Jumlah tabelnya dicatat oleh task ini, bukan diasumsikan dari dokumen.

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

**Rujukan.** Bagian 5.2 dokumen ini, [query scope](../../dev/08-query-scopes-and-schema.md).

**Bergantung pada.** F1-06, F3-04.

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

**Selesai bila.** Seluruh rute modul terlindungi, dan test batas data policy lulus tanpa token.

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
