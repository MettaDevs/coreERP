# K-W: identitas Worker di Foundation

Proposal rincian desain untuk keputusan K-W di [master bersama](./README.md#k-w-identitas-worker-pindah-ke-foundation).
Pemilik produk sudah menyetujui arahnya pada 1 Oktober 2026: **identitas pekerja pindah dari modul HR ke
`App\Foundation\Worker`, data kepegawaian tetap di modul HR**. Halaman ini memuat yang perlu disetujui
sebelum dibangun. Belum ada kode, migration, atau manifest yang diubah.

Ringkasnya:

- Worker, Job, Position, dan penugasan posisi pindah ke Foundation sebagai tabel Core `workers`,
  `worker_jobs`, `worker_positions`, dan `worker_position_assignments`. Id barisnya tidak berubah.
- Tautan pengguna ke pekerja menjadi milik Core. Kontrak modul `LinkedWorkerResolver` tidak lagi
  diperlukan, tetapi Platform tetap tidak boleh memakai Foundation, jadi yang tersisa adalah satu
  antarmuka kecil di Platform yang diisi Foundation.
- Modul membaca pekerja lewat kontrak baru `WorkerDirectory` dan memilihnya lewat komponen pemilih milik
  Foundation.
- Kode permission `human-resources.*` yang sudah tercatat di role tenant tidak disapu. Core mendapat kode
  `core.*` sendiri, dan satu migration memberikannya ke role yang hari ini memegang duty HR padanannya.
- Setelah pemindahan, modul HR **tidak memiliki satu tabel pun** sampai fitur kepegawaian pertama
  dibangun. Itu keadaan yang jujur, bukan kekurangan proposal ini.

## 1. Inventaris hari ini

### Tabel dan kolom

Keempat tabel dibuat `2026_07_30_120000_create_human_resources_tables`, lalu ditambah soft delete,
kolom jejak (`created_by_user_id`, `updated_by_user_id`), trigger log perubahan, dan `version` oleh
migration-migration HR berikutnya. Semuanya ber-`tenant_id` dan memakai id ULID.

| Tabel | Kolom bisnis | Indeks unik | Catatan |
| --- | --- | --- | --- |
| `hr_workers` | `personnel_number`, `name`, `email`, `core_membership_id` (boleh kosong) | `(tenant_id, creation_key)`, `(tenant_id, personnel_number)` **penuh**, `hr_workers_core_membership_active_unique` parsial | Nomor dari referensi `human-resources.pekerja` (PEGH) |
| `hr_jobs` | `code`, `name`, `description` | `(tenant_id, creation_key)`, `(tenant_id, code)` **penuh** | Kode dari `human-resources.jabatan` (JABH) |
| `hr_positions` | `code`, `name`, `job_id`, `operating_unit_id`, `valid_from`, `valid_until` | `(tenant_id, creation_key)`, `(tenant_id, code)` **penuh** | Kode dari `human-resources.posisi` (POSH) |
| `hr_worker_position_assignments` | `worker_id`, `position_id`, `valid_from`, `valid_until`, `is_primary` | tidak ada | Satu posisi satu pekerja per periode dijaga pemeriksaan tumpang tindih di controller |

Relasinya: penugasan → pekerja dan posisi; posisi → jabatan dan unit kerja (`operating_unit_id`, id Core).
Tidak ada foreign key di antara keempatnya.

Yang **tidak** ada hari ini, dan perlu dicatat karena arah keputusannya menyebutnya: kolom atasan atau
*reports-to position*. "Atasan" belum pernah disimpan di mana pun.

Dua celah terhadap aturan repo ikut terlihat: indeks unik `personnel_number` dan `code` masih penuh,
padahal tabelnya punya `deleted_at` ([indeks unik pada kode bisnis wajib parsial](../../dev/02-module-standard.md#indeks-unik-pada-kode-bisnis-wajib-parsial)).
Tabel baru menutupnya.

### Yang menulis

Hanya modul HR, lewat `HumanResourcesController`:

| Rute (`/api/modules/human-resources/v1/...`) | Permission |
| --- | --- |
| `POST workers` | `human-resources.workers.create`, ditambah `human-resources.core-account-link.invoke` bila menautkan akun |
| `PATCH workers/{worker}/core-membership` | `human-resources.workers.create` dan `human-resources.core-account-link.invoke`, dengan versi baris |
| `POST jobs` | `human-resources.jobs.create` |
| `POST positions` | `human-resources.positions.create` |
| `POST worker-position-assignments` | `human-resources.assignments.create` |

Tidak ada ubah atau arsipkan untuk keempat resource. Modul HR tidak punya layar: menu Pekerja, Jabatan,
Posisi, dan Penugasan posisi membuka halaman pengganti "Layar ini belum dipindah" (keputusan K-23,
30 September 2026).

### Yang membaca

| Pembaca | Lewat | Untuk |
| --- | --- | --- |
| Modul HR, `GET workers`, `jobs`, `positions`, `worker-position-assignments`, `core-members` | model HR | daftar, disaring kebijakan data `human-resources.workforce-responsibility` (unit kerja posisi yang aktif hari ini) |
| Core, layar Identity & access → Anggota (`Platform\Access\Http\Controllers\AccessController`) | `LinkedWorkerResolvers` → `LinkedWorkers` milik HR | kolom **Pekerja**: nama dan nomor pegawai per keanggotaan |
| Core, lampiran dokumen (gap 7) | `AttachmentRecordTypes` → `WorkerAttachments` milik HR, record type `hr_workers` | hak baca dan ubah lampiran pekerja |
| Core, log perubahan (gap 6) | trigger di `hr_workers`, setelan `ChangeLogDefaults::register('hr_workers', …)`, `WorkerChangeLogValues` | riwayat pekerja dan nama akun yang ditautkan |
| Core, `HrPositionAssignmentController` (`POST internal/v1/human-resources/position-assignments`) | REST dari app `human-resources` | menerapkan `automatic_role_assignment_rules` (`position_id`) ke `role_assignments`. **Tidak ada pemanggilnya sejak F7-01**: klien HTTP HR dibuang, dan role otomatis berbasis posisi berhenti berjalan |
| Modul aset | — | belum memakai pekerja; penanggung jawab memakai pengguna (`penanggung_jawab_user_id`) di work order, penerimaan aset, dan pemantauan aset |

Kontrak yang menyebut HR: `modules/apperp/human-resources/contracts/openapi.yaml` (rute modul),
`contracts/asyncapi.yaml` dengan channel `human-resources.worker-position-assignment.changed` yang **tidak
diterbitkan kode mana pun** dan namanya tidak mengikuti `module.aggregate.action.vN`, serta
`apps/core/contracts/internal/paths/human-resources_position-assignments.yaml` pada pembaca `app`.

Test: `modules/apperp/human-resources/tests/Feature/PenyaringanTenantTest.php` (lingkup posisi, penyaringan
tenant, lampiran, usulan dan penautan akun, kolom Pekerja di layar anggota), ditambah penyebutan HR di
`DataClassificationBoundaryTest`, `DocumentAttachmentTest`, dan `apps/core/loadtest/prepare.sh`.

## 2. Pembagian: identitas dan kepegawaian

### Rujukan

**Business Central.** `Employee` (tabel 5200) hidup di Base App, `src/Layers/W1/BaseApp/HumanResources/Employee/`,
lapis yang dipakai semua app fungsional. Tabel lain menunjuknya lintas area: `Fixed Asset."Responsible Employee"`,
`Phys. Invt. Order Header."Person Responsible"`, `Spend Request."Requested By"`, jurnal umum, dan
`User Setup."Employee No."`. Yang terakhir **ditambahkan app ExpenseAgent lewat tableextension**, bukan
bagian Base App; arahnya dari pengguna ke karyawan. BC tidak punya Position atau Job: jabatan hanya teks
`Job Title`, atasan hanya `Manager No.` yang menunjuk `Employee` lain.

Kartu `Employee Card` (page 5200) dibagi FastTab *General*, *Address & Contact*, *Administration*,
*Personal*, *Payments*, dan *Payroll*.

**Dynamics 365 F&O.** Pekerja (`HcmWorker`) adalah satu orang di buku alamat (`DirPerson`) dan tidak
terikat pada satu entitas legal; keterikatan pada entitas legal adalah *employment* (`HcmEmployment`),
yang memuat tanggal mulai dan akhir. Job (`HcmJob`) menjelaskan pekerjaan sekali; Position (`HcmPosition`)
adalah kursi di satu departemen yang memakai satu job, punya masa berlaku, dan **reports-to position**
yang dipakai sistem untuk menentukan atasan, untuk workflow routing, dan untuk *Manager self service*.
Penugasan pekerja ke posisi (`HcmPositionWorkerAssignment`) bertanggal. Pengguna ditautkan ke *Person*
di halaman Users, terpisah dari pembuatan penggunanya.

- [Positions](https://learn.microsoft.com/en-us/dynamics365/human-resources/hr-personnel-positions)
- [Integrated worker, job, and position](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/dual-write/integrated-hr)
- [HCM employment V2 entity](https://learn.microsoft.com/en-us/dynamics365/human-resources/hr-employ-v2)
- [Create new users](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/create-new-users) — *associate the user with a Person*
- [Restrict access to workers by legal entity](https://learn.microsoft.com/en-us/dynamics365/human-resources/hr-advanced-security)

### Pembagian kolom

| Data | Pemilik | Padanan |
| --- | --- | --- |
| Nomor pegawai, nama, email kantor | **Foundation** | BC `No.`, nama, `Company E-Mail`; F&O personnel number, `DirPerson` |
| Status aktif/nonaktif (kolom baru, lihat keputusan 9) | **Foundation** | BC `Status`; pemilih modul harus bisa melewatkan orang yang sudah keluar tanpa bergantung pada HR |
| Tautan ke akun pengguna (`core_membership_id`) | **Foundation** | BC `User Setup."Employee No."`; F&O *associate user with person* |
| Job: kode, nama, deskripsi | **Foundation** | F&O `HcmJob` |
| Position: kode, nama, job, unit kerja, masa berlaku | **Foundation** | F&O `HcmPosition` |
| Penugasan pekerja ke posisi, bertanggal, `is_primary` | **Foundation** | F&O `HcmPositionWorkerAssignment` |
| Atasan (reports-to position) | **Foundation**, saat dibangun | F&O reports-to position; BC `Manager No.` |
| Employment per entitas legal, tanggal masuk dan keluar, jenis hubungan kerja, alasan keluar | **HR** | F&O `HcmEmployment`; BC FastTab *Administration* |
| Data pribadi: tanggal lahir, nomor identitas, keluarga, alamat pribadi | **HR** | BC FastTab *Personal*, `Employee Relative` |
| Kontrak, kompensasi, payroll, cuti, absensi, kualifikasi | **HR** | BC FastTab *Payroll*, `Employee Absence`, `Employee Qualification` |
| Jadwal kerja pekerja | **HR**, saat absensi dibangun | lihat gap 3: tautan pekerja → template jam kerja milik HR |

Hari ini baris "HR" di tabel itu semuanya **belum dibangun**. Seluruh kolom yang sudah ada jatuh ke
Foundation.

### Position dan Job ikut Foundation

Ya, keduanya ikut. Dasarnya:

1. **Approval menurut posisi dan atasan dipakai lintas modul.** Workflow sudah milik Foundation
   (`Foundation\Workflow`), dan penerima tugasnya hari ini hanya `role` atau `member`. Penerima "posisi X"
   atau "atasan pengaju" yang kelak ditambahkan hanya bisa dijawab bila posisi dan rantai atasan
   tersedia untuk setiap modul, termasuk pada tenant yang tidak membeli HR.
2. **Role otomatis menurut posisi sudah milik Core.** `automatic_role_assignment_rules.position_id` adalah
   tabel Platform yang menunjuk posisi. Selama posisi milik modul, aturan itu bergantung pada modul yang
   mungkin tidak terpasang.
3. **Lingkup unit kerja seorang pekerja datang dari posisinya.** Pekerja tanpa posisi tidak punya unit
   kerja; memindahkan pekerja tanpa posisi berarti Foundation tidak bisa menjawab "pekerja di unit ini"
   untuk pemilih modul aset.
4. Di F&O ketiganya tinggal di *core HR* yang ikut terpasang bersama Finance dan Supply Chain, bukan di
   lisensi Human Resources yang terpisah.

BC tidak punya Position dan Job, jadi untuk keduanya padanannya F&O.

## 3. Nama tabel dan pemindahan data

### Nama

Tabel Core tidak berawalan modul, tetapi domain Foundation memakai awalan domainnya sendiri bila perlu
(`uom_*`, `number_sequence_*`, `working_time_*`, `finance_posting_*`). Usulan:

| Tabel HR | Tabel Core | Alasan nama |
| --- | --- | --- |
| `hr_workers` | `workers` | akar domain, seperti `vendors` |
| `hr_jobs` | `worker_jobs` | `jobs` sudah dipakai antrean Laravel |
| `hr_positions` | `worker_positions` | satu awalan untuk satu domain, sama dengan namespace `Foundation\Worker` |
| `hr_worker_position_assignments` | `worker_position_assignments` | |

Alternatifnya `positions`, `position_assignments`, dan `job_definitions`. Nama itu lebih pendek, tetapi
tiga tabel satu domain tidak lagi terbaca satu kelompok, dan `job_definitions` bukan istilah F&O
(keputusan 2).

### Bentuk tabel baru

- Kolom sama dengan tabel HR, ditambah `status` di `workers` (keputusan 9), dan `party_id` bila keputusan 3
  disetujui.
- `id` dan `creation_key` disalin apa adanya. Id yang sama membuat lampiran, log perubahan, dan
  `automatic_role_assignment_rules.position_id` tetap menunjuk baris yang benar tanpa diterjemahkan.
- Soft delete, kolom jejak, trigger log perubahan, dan `version` dipasang sejak migration pembuatannya,
  lewat `AuditColumns` seperti tabel Core lain.
- Indeks unik kode bisnis **parsial** (`WHERE deleted_at IS NULL`): `workers (tenant_id, personnel_number)`,
  `worker_jobs (tenant_id, code)`, `worker_positions (tenant_id, code)`, dan tautan akun
  `workers (tenant_id, core_membership_id) WHERE deleted_at IS NULL AND core_membership_id IS NOT NULL`.
  `creation_key` tetap unik penuh, karena ia identitas teknis.
- Foreign key gabungan dengan `tenant_id` di antara keempat tabel, seperti `vendors` ke `organizations`:
  semuanya kini milik satu pemilik, jadi database boleh menolak penugasan ke posisi tenant lain.
  `operating_unit_id` ikut menunjuk `operating_units` milik Core.
- Model memakai `BelongsToTenant`, `SoftDeletes`, `HasUlids`, dan `DataClassification` dengan klasifikasi
  kolom yang sama dengan model HR hari ini.

### Pemindahan data

Belum ada pelanggan, jadi data dipindah dengan migration, bukan dibiarkan berdampingan.

1. **Satu migration Core** menyalin keempat tabel bila `hr_workers` ada di database itu. Pada tenant
   berdatabase sendiri yang tidak memasang HR, tabelnya memang tidak ada dan migration tidak melakukan apa
   pun. Penyalinan `INSERT … ON CONFLICT (id) DO NOTHING`, jadi aman dijalankan ulang. Baris terarsip ikut
   disalin dengan `deleted_at`-nya.
2. **Referensi nomor**: Core mendaftarkan `core.worker`, `core.job`, dan `core.position` (pola
   `core.vendor`, app `core`), lalu migration yang sama **memindah `reference_id` baris
   `tenant_number_sequences` milik `human-resources.pekerja`, `.jabatan`, dan `.posisi` ke referensi Core
   padanannya**. Penghitungnya ikut, jadi nomor berikutnya melanjutkan PEGH, JABH, dan POSH tanpa nomor
   ganda. Membuat urutan baru dari 1 dengan prefix yang sama akan bertabrakan dengan indeks unik.
3. **Lampiran**: `document_attachments.record_type` `hr_workers` → `workers`.
4. **Log perubahan**: setelan `hr_workers` didaftarkan ulang untuk `workers`, dan entri lama di
   `change_log_entries` diubah nama tabelnya, supaya riwayat seorang pekerja tetap terbaca dari kartunya.
   Ini menyunting jejak audit; alasannya, seluruh isinya data percobaan, dan tanpa itu riwayat sebelum
   pemindahan hilang dari layar.
5. **Tabel `hr_*` ditinggalkan sebagai tabel yatim**, seperti `app_placements` dan `app_installations`
   saat bentuk app lama dibuang. Modul HR berhenti membaca dan menulisnya pada PR yang sama
   (keputusan 7).

Langkah 1 sampai 4 harus mendarat bersama berhentinya HR menulis ke `hr_*`. Kalau tidak, ada jendela
waktu ketika HR menulis ke tabel lama yang sudah disalin, dan barisnya tidak pernah sampai ke Core.

### Dampak pada `LinkedWorkerResolver`

Kontrak modulnya **tidak diperlukan lagi**: Core sendiri pemilik tautan pengguna ke pekerja, sama seperti
`User Setup` → `Employee No.` di BC berada di lapis aplikasi bersama, bukan di app HR. Registry yang
bertanya kepada modul terpasang (`LinkedWorkerResolverRegistry`) ikut hilang, beserta pemeriksaan
`core_module_installations`-nya: pekerja selalu ada.

Yang **tidak** hilang: layar anggota ada di `App\Platform\Access`, dan Platform tidak boleh memakai
Foundation (`LayerDirectionBoundaryTest`). Jadi Platform tetap mendefinisikan satu antarmuka kecil,
misalnya `Platform\Access\Contracts\LinkedWorkers::forMemberships()`, dan `Foundation\Worker` yang
mengisinya. Ia keluar dari `App\Platform\Modules\Contracts`, karena modul tidak lagi menjadi pengisinya,
dan bentuk jawabannya tetap `{name, personnel_number}` per keanggotaan. Kolom **Pekerja** di layar anggota
tampil untuk setiap tenant, bukan hanya yang memasang HR.

Pola yang sama berlaku untuk role otomatis: penugasan posisi kini ditulis Foundation, yang memanggil
antarmuka Platform Access untuk menerapkan `automatic_role_assignment_rules` di dalam proses, di dalam
transaksi yang sama. Rute `internal/v1/human-resources/position-assignments` tidak punya pemanggil dan
dicabut (keputusan 8).

## 4. Kontrak untuk modul

Satu antarmuka baru di `App\Platform\Modules\Contracts`, mengikuti `VendorDirectory` dan arah
[master bersama](./README.md#sisa-bentuk-microservice-yang-diganti): menerima id, memulangkan baris biasa,
dan membaca banyak id sekaligus.

```php
interface WorkerDirectory
{
    /**
     * Pekerja aktif untuk pemilih, dicocokkan dengan nomor atau nama.
     * $operatingUnitId menyaring pekerja yang posisi aktifnya ada di unit itu (opsional).
     *
     * @return list<WorkerRow>
     */
    public function active(string $tenantId, string $search = '', ?string $operatingUnitId = null, int $limit = 20): array;

    /**
     * Banyak pekerja sekaligus, termasuk yang nonaktif dan diarsipkan, supaya dokumen lama tetap
     * menampilkan namanya. Id yang tidak dikenal tidak ikut dipulangkan.
     *
     * @param  list<string>  $workerIds
     * @return array<string, WorkerRow> berkunci id pekerja
     */
    public function findMany(string $tenantId, array $workerIds): array;

    /** Pekerja yang tertaut ke keanggotaan, untuk mengisi "penanggung jawab = saya". */
    public function forMembership(string $tenantId, string $membershipId): ?array;
}
```

`WorkerRow` adalah array biasa:
`{id, personnel_number, name, status, membership_id, primary_position: {id, code, name, operating_unit_id}|null}`.
Posisi utama yang ikut adalah penugasan aktif hari ini dengan `is_primary`, karena itu yang dibutuhkan
pemilih dan cetakan (nama dan jabatan penanggung jawab). Rantai atasan **tidak** masuk kontrak ini
sekarang; ia ditambahkan bersama penerima workflow menurut atasan (keputusan 10).

Kontrak tidak memeriksa permission. Modul memanggilnya dari rute yang sudah ia jaga sendiri, sama dengan
`VendorDirectory` hari ini.

**Pemilih.** Foundation menyediakan komponen React pemilih pekerja dan endpoint JSON-nya, sesuai butir
"komponen pemilih Vendor dari Foundation" di [daftar pekerjaan](./README.md#pekerjaan). Modul tidak lagi
membuat endpoint perantara. Siapa yang boleh memanggil endpoint pemilih dibahas di bagian keamanan.

## 5. Layar

Satu daftar dan satu kartu **Pekerja** milik Foundation, mengikuti pola halaman Vendor
(`settings/vendors`): `settings/workers`, `settings/positions`, dan `settings/jobs`, ditambah penugasan
posisi sebagai bagian di kartu Pekerja dan di kartu Posisi, bukan halaman tersendiri.

Kartu Pekerja memuat: nomor, nama, email, status, akun pengguna yang tertaut (dengan usulan dari email
yang sudah ada di API HR), posisi utama, riwayat penugasan, lampiran, dan riwayat perubahan. Nama akun dan
unit kerja tampil sebagai nama, bukan id.

Layar ini sekaligus menjawab K-23: tautan pekerja hari ini hanya lewat API karena HR tidak punya layar.

**Bagian HR di kartu yang sama** tampil hanya bila HR terpasang. Mekanisme bagian kartu per modul
(padanan `pageextension`) belum ada. Bentuk sementaranya:

- Selama HR belum punya tabel kepegawaian, **tidak ada bagian HR sama sekali**. Tidak ada tab kosong.
- Saat fitur kepegawaian pertama dibangun, dan mekanisme umumnya belum ada, kartu Pekerja menampilkan
  satu tautan "Data kepegawaian" ke halaman HR untuk pekerja itu, hanya bila HR terpasang untuk tenant
  dan pengguna memegang permission HR. Tautan itu diganti bagian sungguhan begitu mekanisme bagian kartu
  dibangun untuk Vendor dan Worker sekaligus.

**Menu HR.** Entri Pekerja, Jabatan, Posisi, dan Penugasan posisi di manifest HR hari ini membuka halaman
pengganti. Sampai entri menu modul bisa menunjuk halaman Core, rute layar HR untuk keempat entri itu
mengalihkan ke halaman Core-nya (keputusan 11). Menu modul aset dan Procurement mendapat entri yang sama
saat mereka mulai memakai pekerja, bukan di pekerjaan ini.

## 6. Keamanan

### Permission dan duty

Kode yang tercatat hari ini adalah `human-resources.*`, bukan `hr.*`:
`human-resources.workers.read|create`, `.jobs.read|create`, `.positions.read|create`,
`.assignments.read|create`, dan `.core-account-link.invoke`, dengan lima duty `human-resources.*.manage`
dan kebijakan data `human-resources.workforce-responsibility`. Semuanya kode kontrak: role tenant
memegangnya lewat `security_role_duties`, dan `RegisterAppCatalog` menolak manifest yang membuang duty
yang masih dipakai role. Mengganti kodenya juga berarti hibah lingkup yang sudah tercatat berhenti cocok
tanpa 403 dan tanpa log.

Usulan, dengan rantai F&O apa adanya (entry point → permission → privilege → duty) dan pola kode katalog Core (`<grup>.form`, `.read`/`.update`, privilege `.view`/`.maintain`, duty `.inquire`/`.manage`):

| Core (baru, app `core`) | Duty baru | Diberikan ke role yang memegang |
| --- | --- | --- |
| `core.worker.read`, `core.worker.update` | `core.worker.inquire`, `core.worker.manage` | `human-resources.workers.manage` |
| `core.worker-account-link.update` | `core.worker-account-link.manage` | `human-resources.core-account-link.manage` |
| `core.job.read`, `core.job.update` | `core.job.inquire`, `core.job.manage` | `human-resources.jobs.manage` |
| `core.position.read`, `core.position.update` (termasuk penugasan) | `core.position.inquire`, `core.position.manage` | `human-resources.positions.manage` dan `human-resources.assignments.manage` |

`update` di Core mencakup tambah, ubah, dan arsipkan, sama dengan `core.vendor.update`. Hari ini HR hanya
punya `create`; menyamakan dengan pola Core berarti pemegang duty HR mendapat hak ubah dan arsipkan yang
sebelumnya tidak ada (keputusan 5).

Transisinya, dalam satu migration Core pada PR pemindahan:

1. Katalog Core menambah kode di atas, ditulis migration seperti `register_core_security_catalog`.
2. Setiap role yang memegang duty HR di kolom kanan mendapat duty Core padanannya. Role Owner sudah
   mendapat semuanya lewat `OwnerRoleDuties::syncAll`.
3. Manifest HR **tetap mendeklarasikan** seluruh kodenya. `human-resources.workers.*` kelak dipakai lagi
   untuk bagian kepegawaian, dengan nama yang sama. Duty jabatan, posisi, dan penugasan HR tidak lagi
   menjaga apa pun; ia dilepas dari role lewat migration kecil lalu dihapus dari manifest, sesuai aturan
   *menghapus duty yang sudah dipakai role* di `AGENTS.md`, sebagai PR terpisah setelah pemindahan stabil.

### Kebijakan data

Kebijakan baru `core.workforce-responsibility`: menurut unit kerja, dengan turunan, tanpa entitas legal
(sama dengan kebijakan HR), melindungi `core.worker.*` dan `core.position.*`. Jabatan tidak dilindungi,
karena jabatan tidak punya unit kerja. Aturan lingkupnya sama dengan HR hari ini: tanpa akses seluruh
organisasi, pengguna hanya melihat pekerja yang **hari ini** memegang posisi di unit kerjanya, dan pekerja
tanpa posisi hanya dibuat oleh pengguna dengan akses seluruh organisasi.

Migration yang sama menyalin setiap baris `role_assignment_data_policy_scopes` berkode
`human-resources.workforce-responsibility` menjadi baris berkode baru. Kebijakan HR tetap terdaftar,
karena hibahnya masih dipakai dan kelak menjaga data kepegawaian.

Kebijakan itu **tidak** berlaku untuk pemilih. Pemilih hanya memulangkan identitas minimum (nomor, nama,
posisi utama) pekerja aktif seluruh tenant, karena penanggung jawab aset atau teknisi work order sering
berada di unit lain. Endpoint pemilih dijaga permission `core.worker.lookup` (`access: read`), yang
menurut aturan master bersama masuk ke duty bawaan setiap modul yang memakai pekerja (keputusan 6).
Klasifikasi data tidak berubah: nama dan email tetap `EndUserIdentifiableInformation`.

## 7. Dampak dan urutan PR

### Modul HR

- Hilang: keempat model, `HumanResourcesController` (kecuali health), `LinkedWorkers`, `WorkerAttachments`,
  `WorkerChangeLogValues`, `HrNumberSequenceIssuer`, `HrOrganizationDirectory`, dan rute `routes/api.php`
  selain health. `contracts/openapi.yaml` mengecil menjadi health; channel AsyncAPI yang tidak pernah
  diterbitkan dihapus.
- Tetap: manifest dengan seluruh kode keamanannya, `table_prefix: hr_`, rute layar (mengalihkan, keputusan
  11), dan migration lamanya, yang tidak disunting.
- Manifest berhenti mendeklarasikan tiga referensi nomornya setelah urutannya dipindah ke referensi Core.
- `README.md` dan `database/README.md` HR ditulis ulang: modul ini memegang kepegawaian, dan hari ini belum
  ada isinya.

### Core

- Baru: `App\Foundation\Worker` (model, action simpan dan arsipkan, controller layar dan pemilih,
  `WorkerDirectory` pelaksana, pengisi `LinkedWorkers`, pendaftaran lampiran dan log perubahan),
  `App\Platform\Modules\Contracts\WorkerDirectory`, migration tabel, data, katalog keamanan, dan referensi
  nomor.
- Berubah: `AccessController` membaca `LinkedWorkers` milik Platform; `HrPositionAssignmentController`
  diganti antarmuka Access di dalam proses; `CoreServices` kehilangan `LinkedWorkerResolvers`.
- Dicabut: `LinkedWorkerResolver`, `LinkedWorkerResolvers`, `LinkedWorkerResolverRegistry`, rute dan berkas
  kontrak `human-resources/position-assignments`, lalu `python contracts/bundle.py` dan
  `python contracts/check-contract-coverage.py`.

### Test

- `PenyaringanTenantTest` HR pindah ke `apps/core/tests/Feature/Foundation/Worker/`: penyaringan tenant,
  lingkup unit kerja, lampiran, usulan dan penautan akun, satu akun satu pekerja, versi baris, dan kolom
  Pekerja di layar anggota (kini tanpa syarat HR terpasang).
- Baru: penyalinan data (id, `deleted_at`, nomor berikutnya melanjutkan urutan lama), pemberian duty dan
  penyalinan hibah lingkup, arsipkan lalu tidak muncul di daftar maupun detail, kode terarsip boleh dipakai
  ulang, serta role otomatis diterapkan saat penugasan disimpan.
- Disesuaikan: `DataClassificationBoundaryTest`, `DocumentAttachmentTest`, dan modul HR di
  `apps/core/loadtest/prepare.sh`.
- Gate kebenaran load test (0 pelanggaran lintas tenant, 0 nomor ganda, 0 eskalasi hak) dijalankan untuk
  endpoint pekerja dan pemilih sebelum dinyatakan selesai.

### Urutan PR

1. **Tabel dan katalog.** Tabel Core kosong, model, katalog keamanan dan referensi nomor Core,
   `WorkerDirectory`. Belum ada pembaca; aman digabung sendiri.
2. **Pemindahan.** Migration data (tabel, urutan nomor, lampiran, log perubahan, duty, hibah lingkup),
   endpoint JSON Core, `LinkedWorkers` milik Platform, role otomatis di dalam proses, pencabutan rute
   internal dan kontraknya, dan pembersihan modul HR. **Satu PR**, supaya tidak ada jendela HR menulis ke
   tabel lama.
3. **Layar.** Daftar dan kartu Pekerja, Posisi, Jabatan; pengalihan menu HR; komponen pemilih.
   Diverifikasi di container lewat `start.ps1 -Build`.
4. **Pelepasan duty HR yang mati.** Migration pelepas role-duty, lalu manifest HR tanpa duty jabatan,
   posisi, dan penugasan; `app:register-manifest` di container.
5. Di luar K-W, sebagai keputusan sendiri: penanggung jawab aset memakai pekerja (BC `Responsible Employee`),
   dan penerima workflow menurut posisi dan atasan.

## 8. Keputusan yang perlu dijawab pemilik

1. **Position, Job, dan penugasan ikut Foundation.** Rekomendasi: ya, karena approval menurut posisi dan
   atasan, role otomatis menurut posisi, dan lingkup unit kerja semuanya lintas modul.
2. **Nama tabel** `workers`, `worker_jobs`, `worker_positions`, `worker_position_assignments`.
   Rekomendasi: ya; `jobs` sudah dipakai antrean.
3. **Pekerja sebagai party person di buku alamat**, seperti Vendor dan F&O `DirPerson`: nama dan email
   pindah ke party, `workers` memegang `party_id`. Rekomendasi: ya, sekarang, selagi data masih percobaan;
   menundanya berarti migration data kedua setelah ada pelanggan. Kalau ditolak, `name` dan `email` tetap
   kolom `workers`.
4. **Tautan pengguna tetap kolom `workers.core_membership_id`**, bukan tabel setup pengguna terpisah.
   Rekomendasi: ya; satu pemilik kini memegang kedua sisi, dan indeks parsialnya sudah menjaga satu akun
   satu pekerja.
5. **Kode permission Core** `core.worker|job|position.read|update`, `core.worker-account-link.update`,
   diberikan otomatis ke role yang memegang duty HR padanannya, termasuk hak ubah dan arsipkan yang
   sebelumnya tidak ada. Rekomendasi: ya.
6. **Pemilih memulangkan pekerja aktif seluruh tenant** (identitas minimum), dijaga `core.worker.lookup`
   di duty bawaan modul pemakai, tidak disaring unit kerja. Rekomendasi: ya.
7. **Tabel `hr_*` ditinggalkan sebagai tabel yatim** setelah disalin. Rekomendasi: ya, sama dengan
   `app_placements`.
8. **Rute `internal/v1/human-resources/position-assignments` dicabut**, role otomatis diterapkan di dalam
   proses saat penugasan disimpan, dan channel AsyncAPI HR yang tidak pernah terbit dihapus.
   Rekomendasi: ya; rute itu tidak punya pemanggil sejak F7-01.
9. **Kolom `status` (aktif/nonaktif) di `workers`**; tanggal dan alasan keluar tetap HR. Rekomendasi: ya,
   supaya pemilih modul tidak bergantung pada HR untuk melewatkan orang yang sudah keluar.
10. **Reports-to position ditambahkan bersama penerima workflow menurut atasan**, bukan sekarang.
    Rekomendasi: tunda; belum ada pemakainya hari ini.
11. **Menu HR mengalihkan ke halaman Core** sampai entri menu modul bisa menunjuk halaman Core.
    Rekomendasi: ya.
12. **Riwayat log perubahan `hr_workers` diubah nama tabelnya** menjadi `workers`, supaya terbaca dari
    kartu Pekerja. Rekomendasi: ya, karena seluruhnya data percobaan.
