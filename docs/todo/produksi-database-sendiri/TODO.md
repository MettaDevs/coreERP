# TODO production database sendiri

Butir kerja untuk [Production dengan database sendiri](/todo/produksi-database-sendiri/). Baca
halaman itu lebih dulu; keputusan K-01 dan seterusnya dijelaskan alasannya di sana.

Status mengikuti [aturan backlog](/todo/): `[ ]` belum, `[~]` sedang dikerjakan, `[x]` selesai
**dan** ada test yang membuktikannya. Setiap area punya **Selesai bila** (kriteria terima) dan
**Setelah** (area yang harus selesai lebih dulu).

Dokumen ini dua bagian. **Bagian A** berisi pekerjaan pembuatannya. **Bagian B** berisi test yang
membuktikannya, dipisah supaya bisa dikerjakan dan direview sendiri. Setiap area di A menyebut
nomor test di B yang menutupnya; sebuah butir A baru boleh `[x]` bila test B-nya lulus.

Urutan kerja yang disarankan:

```text
0 ─▶ 1 ─▶ 2 ─┬─▶ 3 ─┐
             ├─▶ 4 ─┼─▶ 6 ─▶ 7 ─▶ 8
             └─▶ 5 ─┘
```

Area 4 boleh dikerjakan paralel sejak area 1 selesai, dan sebagian isinya sudah menjadi gap untuk
demo hari ini, jadi hasilnya berguna walau area lain belum jalan.

---

## Bagian A: pembuatan

### 0. [ ] Keputusan yang masih terbuka

**Tempat:** pemilik produk, sesi *Akses akun control-plane* · **Setelah:** — ·
**Selesai bila:** K-07 dan K-08 terjawab dan dicatat di halaman keputusan.

- [ ] 0.1 K-07: bentuk penanda pilihan database (usulan: kolom baru di `environments` dengan dua nilai, dijaga constraint). Tentukan nama kolom dan nilainya.
- [ ] 0.2 K-08: penyiapan production database sendiri berjalan otomatis setelah client dibuat, atau menunggu tombol **Siapkan** seperti demo. Yang perlu diketahui: penyiapan hari ini **sinkron** (`Artisan::call('environment:provision')` di dalam request, batas waktu `core.provision_timeout` bawaan 300 detik), tanpa antrean. Kalau dipicu otomatis di dalam request buat client, request itu bisa berjalan sampai 300 detik.
- [x] 0.3 Tanya sesi *Akses akun control-plane* soal bentrokan (24 September 2026). Tidak ada worktree aktif yang menyentuh `Customers/Store`, `CreateCustomer`, atau layar lingkungan.
- [ ] 0.4 Tanya pemilik produk: PR lama soal pendaftaran mandiri (#18, #16, #13, #3) jauh tertinggal dari `main` dan tidak relevan lagi sejak K-04. Ditutup?

### 1. [ ] Core: penanda pilihan database

**Tempat:** `apps/core` · **Setelah:** 0 · **Selesai bila:** setiap environment tercatat memilih
database sendiri atau database bersama, dan database menolak keadaan yang saling bertentangan.
**Ditutup test:** B-1.

- [ ] 1.1 Migration: tambah kolom penanda (hasil 0.1) pada `environments`.
  - [ ] 1.1.1 Bawaan untuk baris lama = database bersama bila `database_name` kosong, database sendiri bila terisi. Diisi di migration yang sama, bukan seeder.
  - [ ] 1.1.2 Constraint: database bersama wajib `database_name` kosong; `hosting = client_server` tidak boleh memilih database sendiri di server kami.
  - [ ] 1.1.3 Demo dan sandbox selalu database sendiri. Tulis sebagai constraint, bukan hanya di kode.
  - [ ] 1.1.4 Lolos aturan N-1 (`MigrasiKompatibelMundurTest`) dan `tests/Feature/Boundary`.
- [ ] 1.2 Model `Environment` di Core: properti, cast, dan satu method penanya (misalnya "memakai database sendiri?") supaya pembaca tidak membandingkan string sendiri-sendiri.
- [ ] 1.3 Model `Environment` di control-plane ikut membaca kolom baru (tabel milik Core, model penanda `OwnedByControlPlane` sudah ada).

### 2. [ ] Core: pembuatan tenant menerima pilihan database

**Tempat:** `apps/core` · **Setelah:** 1 · **Selesai bila:** `POST internal/v1/tenants` dengan
production database sendiri melahirkan production berstatus `provisioning` tanpa memasang apa pun di
database bersama, dan pilihan database bersama tetap berperilaku persis seperti hari ini.
**Ditutup test:** B-2.

- [ ] 2.1 `TenantProvisioningRequest`: field baru untuk pilihan database production, opsional dengan bawaan **database sendiri** (K-01).
  - [ ] 2.1.1 Ditolak 422 bila dikirim bersama `first_environment_hosting = client_server`.
  - [ ] 2.1.2 Diabaikan (atau ditolak, putuskan) untuk demo dan `none`.
- [ ] 2.2 `RegisterBusiness`: production database sendiri lahir `provisioning` dengan `database_name` kosong dan penanda terisi.
- [ ] 2.3 `RegisterBusiness` bagian `afterCommit`: lewati `InstallModule`, event `TenantDisiapkan`, dan `EnsureNumberSequenceDrafts` untuk environment yang akan mendapat database sendiri. Pemasangannya milik penyiapan (area 3).
  - [ ] 2.3.1 Periksa demo sebagai environment pertama. Dari pembacaan kode, `afterCommit` hari ini tidak melewatkan demo, sehingga module demo mungkin terpasang di database bersama sebelum demonya disiapkan. Buktikan dengan test dulu; bila benar, perbaiki di butir yang sama.
- [ ] 2.4 Controller menjawab `environment_id` dan status environment pertama, supaya admin.erp tahu production itu masih perlu disiapkan.
- [ ] 2.5 Jangan sentuh endpoint pendaftaran mandiri (`api/v1/business-registrations`) selain yang perlu agar ia tetap jalan. Ia hilang di v1 (K-04).

### 3. [ ] Core: penyiapan production database sendiri

**Tempat:** `apps/core` · **Setelah:** 2 · **Selesai bila:** production database sendiri dapat
disiapkan lewat `environment:provision` dan `POST internal/v1/environments/{id}/provision`, dan
production database bersama tidak pernah bisa disiapkan ulang menjadi database baru yang kosong.
**Ditutup test:** B-3.

- [ ] 3.1 `ProvisionEnvironment` dan `EnvironmentProvisioningController` menolak environment yang memilih database bersama, termasuk yang berstatus `degraded`. Pesannya menyebut sebabnya.
- [ ] 3.2 Pastikan penyiapan production memasang module sesuai entitlement (`InstallEntitledModules`), membuat urutan nomor draf, dan memancarkan `TenantDisiapkan` di database environment, sama seperti demo.
- [ ] 3.3 `outbound_allowed` production tetap `true` setelah disiapkan (constraint `environments_keluar_ikut_jenis`).
- [ ] 3.4 Owner dan keanggotaan tetap di database pusat; login di alamat production database sendiri berhasil dan sesinya tersimpan di pusat.

### 4. [ ] Core: jalur yang menganggap semua data di database bersama

**Tempat:** `apps/core`, `modules/` · **Setelah:** 1 · **Selesai bila:** setiap proses latar dan
perintah terjadwal yang menyentuh data tenant bekerja di database environment yang benar, dan
buktinya diperiksa lewat SQL langsung ke kedua database. **Ditutup test:** B-4.

Dari pembacaan kode 24 September 2026, hanya enam tempat yang memakai `EnvironmentConnection`:
middleware `ResolveEnvironment` dan `ResolvePasskeyOrigin`, `ProvisionEnvironment`,
`UpgradeEnvironments`, penyiapan lewat API, serta aksi install/disable/uninstall module. Semua yang
di bawah ini **belum** menyentuhnya, jadi untuk environment berdatabase sendiri mereka bekerja di
database bersama atau tidak bekerja sama sekali. Gap ini sudah berlaku untuk demo hari ini.

- [ ] 4.1 Job antrean membawa environment asalnya dan menjalankan isinya di dalam `runWithin`.
  - [ ] 4.1.1 `RunReportExport`: tidak membawa environment sama sekali. Ekspor dari production database sendiri akan membaca database bersama.
  - [ ] 4.1.2 Cari job lain di `modules/` (hari ini hanya dua kelas `ShouldQueue` di `apps/core`, tetapi periksa ulang saat mengerjakan).
- [ ] 4.2 Perintah terjadwal di `routes/console.php` memutari environment yang berjalan di server kami, masing-masing lewat `runWithin`:
  - [ ] 4.2.1 `number-sequences:recover`
  - [ ] 4.2.2 `workflow-events:publish`
  - [ ] 4.2.3 `finance-postings:push`
  - [ ] 4.2.4 `reporting:purge-exports`
- [ ] 4.3 API integrasi sistem luar (`internal/v1`, feed posting finance, vendor): pastikan permintaan dari klien integrasi dilayani database environment-nya, bukan database bersama.
- [ ] 4.4 `CopyEnvironment`: sandbox dari production database sendiri memakai jalur `pg_dump`, bukan jalur salin per `tenant_id`.
- [ ] 4.5 `ConvertEnvironment`, `FleetController`, `UpgradeEnvironments`, `PurgeEnvironment`: baca ulang cabang `database_name` kosong dan pastikan production database sendiri jatuh ke cabang yang benar.
- [ ] 4.6 Penjaga gagal-tertutup: kode yang lupa pindah koneksi saat melayani environment berdatabase sendiri harus gagal, bukan diam-diam menulis ke database bersama. Docblock `ResolveEnvironment.php` (baris 58-68) sudah mengakui penjaga ini belum ada.
- [ ] 4.7 Reset koneksi per request bila Octane dipakai. Hari ini hanya ada peringatan di `ResolveModuleContext.php` (baris 93-98).
- [ ] 4.8 Foreign key yang menyeberang dari tabel environment ke tabel pusat (misalnya `role_assignments` dan `sod_conflicts` ke `tenant_memberships`) masih diizinkan `FkMenyeberangBatasTest`. Di database terpisah FK itu tidak mungkin berlaku; targetnya nol.
- [ ] 4.9 `verify.sql` load test Core (baris 44-45, 151) dan module aset (baris 153-155) melakukan JOIN ke tabel `tenants`. Tidak jalan di database environment; tulis ulang supaya pemeriksaan berjalan per database.
- [ ] 4.10 Catat setiap tempat lain yang ditemukan saat mengerjakan sebagai butir baru di area ini.

### 5. [ ] Kontrak internal

**Tempat:** `apps/core/contracts/internal/` · **Setelah:** 2 · **Selesai bila:** kontrak
`internal/v1/tenants` dan jawaban penyiapan menggambarkan field baru, dan pemeriksaan kontrak lulus.
**Ditutup test:** B-5.

- [ ] 5.1 Tulis field baru di berkas path dan komponen `internal/v1/tenants`, termasuk bawaannya dan penolakan bersama `client_server`.
- [ ] 5.2 Jalankan `python contracts/bundle.py` lalu `python contracts/check-contract-coverage.py` dari `apps/core`.
- [ ] 5.3 Selama area 4 belum selesai, tulis gap-nya di `info.description` (kontrak menggambarkan keadaan sebenarnya).

### 6. [ ] admin.erp: pilihan database di layar operator

**Tempat:** `apps/control-plane` · **Setelah:** 2, 3, 5 · **Selesai bila:** operator memilih
database sendiri atau database bersama saat membuat client dan saat menambah production, bawaannya
database sendiri, dan production database sendiri dapat disiapkan dari layar. **Ditutup test:** B-6.

Buka skill `coreerp-konsol` sebelum mulai.

- [ ] 6.1 Form buat client (`Customers/Store`, halaman React-nya): pilihan database untuk production, bawaan database sendiri. Disembunyikan untuk demo, `none`, dan production di server client.
  - [ ] 6.1.1 Teks di layar memakai bahasa sehari-hari: sebut dampaknya bagi client (data terpisah dari client lain / paling murah, berbagi database), bukan istilah pooled atau `database_name`.
  - [ ] 6.1.2 Bantuan kontekstual hanya pada field ini, mengikuti standar bantuan kontekstual.
- [ ] 6.2 `CreateCustomer` mengirim field baru ke Core. Jangan kirim apa pun untuk jalur yang tidak memakainya, sama seperti field `first_environment_hosting` hari ini.
- [ ] 6.3 `CreateEnvironment` (tambah production dari layar lingkungan) menerima pilihan yang sama.
  - [ ] 6.3.1 `CreateEnvironment` hari ini menulis tabel `environments` langsung (baris 49 dan 107), bukan lewat API Core, dan itu melanggar invariant 2 skill `coreerp-konsol`. Jangan ditiru untuk kolom baru; putuskan apakah butir ini sekaligus memindahkannya ke API Core.
- [ ] 6.4 Setelah client dibuat dengan production database sendiri, arahkan operator ke langkah penyiapan sesuai K-08. Tombol **Siapkan** yang sudah ada ada di `resources/js/pages/environments/show.tsx`.
- [ ] 6.5 Layar tenant dan lingkungan menampilkan pilihan databasenya.
- [ ] 6.6 Catat pilihan database di audit operator (`OperatorAudit::record`). `CreateCustomer` hari ini belum mencatat audit sama sekali, padahal invariant 4 skill `coreerp-konsol` mewajibkannya. Detail audit tidak memuat rahasia (kata sandi sementara tidak ikut).

### 7. [ ] Dokumentasi

**Tempat:** `docs/` · **Setelah:** 6 · **Selesai bila:** desain kanonik menyebut kedua pilihan
database, dan baris gap di `AGENTS.md` dihapus.

- [ ] 7.1 Perbarui halaman desain di `docs/dev` yang membahas environment dan admin.erp (`31-admin-erp-control-plane.md` dan halaman environment yang relevan). Pakai skill `coreerp-docs`.
- [ ] 7.2 Hapus kalimat "selama implementasinya belum selesai, production masih lahir di database bersama" dari `AGENTS.md`.
- [ ] 7.3 Naikkan keputusan dari halaman ini ke `docs/dev`, lalu tandai halaman ini selesai.

### 8. [ ] Verifikasi runtime

**Tempat:** stack `erp-dev`, SaaS dev server 1 · **Setelah:** 7 · **Selesai bila:** satu client
dengan production database sendiri dan satu client database bersama dibuat dari admin.erp, bisa
login dan dipakai, dan pemeriksaan SQL langsung membuktikan datanya di database yang benar.

- [ ] 8.1 Di `erp-dev`: rebuild lewat `start.ps1 -Build`, buat client dari admin.erp, siapkan, login di alamat tenant.
- [ ] 8.2 Periksa lewat SQL dari container runtime: data production database sendiri tidak ada di `core_erp`, data client database bersama tidak ada di database environment mana pun.
- [ ] 8.3 Di SaaS dev server 1: lewat rilis (`rilis.yml`), bukan deploy tangan. Ulangi 8.1 dan 8.2.

---

## Bagian B: test

Aturan menjalankan test mengikuti `AGENTS.md`: suite Core paralel dua tahap
(`composer test:fast`, lalu tanpa `--exclude-group=lambat` sebelum menyatakan hijau); suite
control-plane serial (`php artisan test`). Test yang membuktikan letak data memeriksa lewat SQL
langsung ke database yang dituju, bukan lewat API yang sedang diuji.

Yang sudah ada dan dipakai ulang:

- Test Core yang membuat database environment sungguhan memakai grup `serial` dan
  `tests/Concerns/DropsTestDatabases.php` (`ProvisionEnvironmentTest`, `CopyEnvironmentTest`,
  `ConvertEnvironmentTest`, `EnvironmentLifecycleTest`). Test baru yang membuat database ikut pola itu.
- `apps/control-plane/tests/TestCase.php` hanya menerima koneksi `pgsql_test` dengan schema selain
  `public`, dibangun `tests/CoreSchema.php` dari migration Core. Panggilan ke Core dipalsukan dengan
  `Http::fake()` plus `Http::preventStrayRequests()` (contoh di `CreateCustomerTest.php`).
- Tidak ada test lintas dua app yang menjalankan Core sungguhan. Penjaga lintas app satu-satunya
  adalah `CoreCommandContractTest`, yang mencocokkan kode konsol dengan `openapi-internal.yaml`.

### B-1. [ ] Penanda pilihan database (menutup area 1)

- [ ] B-1.1 Migration mengisi baris lama dengan benar: `database_name` kosong → database bersama, terisi → database sendiri.
- [ ] B-1.2 Constraint menolak: database bersama dengan `database_name` terisi; `client_server` dengan database sendiri; demo atau sandbox dengan database bersama. Setiap penolakan diuji dengan menulis langsung ke tabel.
- [ ] B-1.3 `tests/Feature/Boundary` dan `MigrasiKompatibelMundurTest` lulus dengan migration baru.

### B-2. [ ] Pembuatan tenant (menutup area 2)

Perluas `BusinessOnboardingTest` dan test API `internal/v1/tenants` yang sudah ada.

- [ ] B-2.1 Tanpa field baru: production lahir memilih database sendiri, status `provisioning`, `database_name` kosong.
- [ ] B-2.2 Dengan pilihan database bersama: perilaku persis hari ini (status `active`, module terpasang di database bersama, urutan nomor draf dibuat).
- [ ] B-2.3 Production database sendiri: **nol** baris `core_module_installations` dan nol urutan nomor untuk tenant itu di database bersama.
- [ ] B-2.4 Entitlement, owner, role Owner, dan `environment_members` tetap tercatat di database pusat untuk kedua pilihan.
- [ ] B-2.5 Kombinasi dengan `client_server` ditolak 422 dengan pesan pada field-nya.
- [ ] B-2.6 Demo sebagai environment pertama tidak memasang module di database bersama (membuktikan atau membantah 2.3.1).
- [ ] B-2.7 Pendaftaran mandiri tetap berjalan seperti hari ini (regresi, sampai v1).

### B-3. [ ] Penyiapan (menutup area 3)

Perluas `ProvisionEnvironmentTest` dan `EnvironmentProvisioningApiTest` di Core.

- [ ] B-3.1 Production database sendiri disiapkan: database dibuat, `database_name` terisi, status `active`, module terpasang di database environment dan **tidak** di database bersama.
- [ ] B-3.2 Production database bersama ditolak disiapkan, baik status `active` maupun `degraded`, lewat perintah maupun API (409).
- [ ] B-3.3 Penyiapan yang gagal di tengah menurunkan status ke `degraded` dan tidak meninggalkan catatan pemasangan palsu.
- [ ] B-3.4 Permintaan ke alamat production database sendiri dirutekan ke databasenya; sesi, cache pembatas login, dan antrean tetap di pusat (perluas `EnvironmentAddressTest` / test `ResolveEnvironment`).
- [ ] B-3.5 Isolasi: data yang ditulis lewat alamat production database sendiri tidak muncul di database bersama, dan sebaliknya. Diperiksa dengan SQL langsung.

### B-4. [ ] Proses latar dan perintah terjadwal (menutup area 4)

- [ ] B-4.1 `RunReportExport` dari production database sendiri membaca database environment dan hasilnya dapat diunduh dari alamat environment itu.
- [ ] B-4.2 Setiap perintah di 4.2 memproses baris di database environment. Satu test per perintah: siapkan baris di database environment, jalankan perintahnya, periksa hasilnya dengan SQL langsung.
- [ ] B-4.3 Proses yang memutari banyak environment memulihkan koneksi bawaan setelah tiap environment, termasuk saat satu environment gagal di tengah (tidak ada tulisan ke database yang salah setelah kegagalan).
- [ ] B-4.4 Sandbox disalin dari production database sendiri lewat jalur `pg_dump` dan isinya sama.
- [ ] B-4.5 Upgrade armada menjalankan migration ke database production database sendiri.
- [ ] B-4.6 Penjaga gagal-tertutup (4.6): kode yang menyentuh tabel environment tanpa pindah koneksi saat melayani environment berdatabase sendiri gagal dengan pesan yang menyebut sebabnya.
- [ ] B-4.7 `FkMenyeberangBatasTest` diperketat ke nol FK yang menyeberang pusat dan environment (4.8).
- [ ] B-4.8 Satu alur ujung-ke-ujung (buat client, siapkan, login, transaksi, ekspor laporan, posting finance) diuji di **kedua** pilihan: database bersama dan database sendiri.
- [ ] B-4.9 Profil load test tersendiri untuk banyak database: 100+ tenant yang masing-masing punya database berarti jumlah koneksi PostgreSQL dan pooler menjadi batas baru. Gate kebenaran tetap sama (0 pelanggaran lintas tenant, 0 nomor ganda, 0 eskalasi hak, 0 error 5xx), diperiksa per database lewat `verify.sql` yang sudah diperbaiki di 4.9.

### B-5. [ ] Kontrak (menutup area 5)

- [ ] B-5.1 `python contracts/bundle.py` dan `python contracts/check-contract-coverage.py` lulus.
- [ ] B-5.2 `CoreCommandContractTest` di control-plane mencocokkan field yang dikirim `CreateCustomer` dengan kontrak.

### B-6. [ ] admin.erp (menutup area 6)

Perluas `CreateCustomerTest`, `CreateEnvironmentTest`, dan `ProvisionEnvironmentTest` di
`apps/control-plane`.

- [ ] B-6.1 Validasi `Customers/Store`: bawaan database sendiri; pilihan database diabaikan untuk demo, `none`, dan `client_server`.
- [ ] B-6.2 `CreateCustomer` mengirim field yang benar untuk setiap kombinasi, diperiksa lewat isi permintaan `Http::fake`.
- [ ] B-6.3 `CreateEnvironment` menyimpan pilihan database untuk production.
- [ ] B-6.4 Layar menampilkan pilihan database pada tenant dan lingkungan.
- [ ] B-6.5 Membuat client mencatat satu baris audit operator yang memuat pilihan database dan tidak memuat kata sandi sementara.
- [ ] B-6.6 **Satu uji lintas dua app tanpa `Http::fake`**: admin.erp memanggil Core sungguhan di stack `erp-dev` untuk membuat client dengan production database sendiri lalu menyiapkannya. Jalur yang hanya diuji lewat pemalsuan bisa lulus tanpa pernah berhasil sekali pun.
