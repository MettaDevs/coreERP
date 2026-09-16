# Identity, responsibility-based security, dan organization scope

Core Platform/Control Plane adalah sumber identity, tenant membership, security metadata, role assignment, dan entitlement. Module bisnis tidak membuat sistem user sendiri dan tidak membaca database Core secara langsung.

Model target mengikuti Microsoft Dynamics 365 untuk workforce dan role-based security. Rujukan resminya dirangkum di [referensi model organisasi Dynamics 365](../references/dynamics-365-organization-model.md).

## Isi halaman ini

Dokumen ini panjang dan memuat empat jenis isi yang berbeda. Kalau kamu mencari sesuatu yang spesifik, masuk lewat sini:

**Model keamanan** — [Role platform](#role-platform) · [Security mengikuti tanggung jawab bisnis](#security-mengikuti-tanggung-jawab-bisnis) · [Hierarchy security role](#hierarchy-security-role) · [Permission dan operasi sensitif](#permission-dan-operasi-sensitif) · [Granularitas permission dan duty](#granularitas-permission-dan-duty) · [Data-policy-scoped role assignment](#data-policy-scoped-role-assignment)

**Orang dan penugasan** — [Workforce dan rangkap position](#workforce-dan-rangkap-position) · [Temporary access dan segregation of duties](#temporary-access-dan-segregation-of-duties) · [Model data target](#model-data-target)

**Alur runtime** — [Onboarding](#onboarding) · [Invitation dan role provisioning](#invitation-dan-role-provisioning) · [Runtime authorization](#runtime-authorization) · [Number sequence administration](#number-sequence-administration) · [Product launcher](#product-launcher) · [Active workspace](#active-workspace) · [Provider access](#provider-access)

**Keadaan sekarang dan operasional** — [Keadaan worktree saat ini](#keadaan-worktree-saat-ini) · [Acceptance target](#acceptance-target) · [Setup lokal](#setup-lokal)

::: tip Baru pertama kali membaca ini?
Mulai dari [Glosarium](../onboarding/glosarium.md) untuk istilah role, duty, privilege, dan permission, lalu [Alur end-to-end](../onboarding/alur-end-to-end.md) yang menunjukkan model ini bekerja pada satu kasus nyata.
:::

## Role platform

Setiap membership tenant mempunyai satu role platform yang dilindungi:

| Role | Hak dasar | Batas |
| --- | --- | --- |
| `owner` | Kendali akhir tenant, admin, entitlement, dan delegasi akses. | Hanya owner dapat memindahkan ownership. |
| `admin` | Mengelola anggota, security role, assignment, dan konfigurasi tenant. | Tidak dapat menghapus atau menurunkan owner. |
| `user` | Anggota tenant. | Tidak memperoleh akses bisnis sampai menerima security role. |

Role platform mengatur administrasi SaaS. Ia terpisah dari security role bisnis dan tidak dipakai sebagai template duty/permission.

## Security mengikuti tanggung jawab bisnis

Rantai authorization kanonik adalah:

```text
User
  -> Role assignment
  -> Security role
  -> Duty
  -> Privilege
  -> Permission
  -> Module entry point
```

| Tingkat | Makna |
| --- | --- |
| Security role | Sekumpulan tanggung jawab atau partisipasi dalam proses bisnis. |
| Duty | Bagian dari proses bisnis, misalnya mengelola requisition. |
| Privilege | Aksi yang diperlukan untuk menyelesaikan suatu tugas. |
| Permission | Hak dan access level pada entry point tertentu. |
| Module entry point | Form, menu item, API/service, report, atau action yang dilindungi. |

Role tidak mempunyai `module_id` dan tidak dimiliki department. App mendaftarkan entry point dan permission kanonik melalui contract/manifest. Administrator tenant menyusun role dari duty yang diperlukan, termasuk duty lintas app.

## Security Configuration

Core menyediakan **Konfigurasi keamanan** untuk admin tenant, mengikuti model Security configuration Dynamics 365. Layar menampilkan pohon yang dapat ditelusuri: `tanggung jawab → tugas akses → izin → layar atau layanan`. Nama bisnis ditampilkan sebagai informasi utama; kode teknis hanya rincian audit.

- **Layar atau layanan** dan **izin** selalu berasal dari manifest app yang telah terdaftar. Tenant tidak dapat menciptakan kode atau titik akses palsu.
- Tenant dapat membuat **tugas akses** khusus dari izin app yang entitlement-nya aktif, lalu membuat **tanggung jawab** khusus dari tugas akses bawaan maupun khusus.
- Konfigurasi khusus dimulai sebagai `draft`. Hanya konfigurasi `active` yang sudah diterbitkan dapat dipilih pada security role. Konfigurasi yang telah diterbitkan tidak diubah di tempat; perubahan dibuat sebagai draf baru agar akses yang sedang berlaku tidak berubah diam-diam.
- Security role dan pemberiannya ke anggota tetap tenant-owned. Data policy scope tetap diatur pada assignment, terpisah dari komposisi role.

Model ini adalah adaptasi aman dari Dynamics: Dynamics memungkinkan konfigurasi security object dan entry point, sedangkan CoreERP menjaga entry point/permission sebagai kontrak app agar pembaruan app dan API tidak dapat dirusak oleh konfigurasi tenant. Lihat [Security configuration Dynamics 365](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/setup-process-role-hierarchy).

Keempat lapis disimpan sebagai empat baris berbeda. Privilege yang memakai kode yang sama dengan permission-nya berarti rantai itu runtuh menjadi satu lapis bersalin tiga, dan tenant kehilangan kemampuan memberi satu tugas tanpa memberi seluruh duty. `access_level` selalu dideklarasikan manifest, tidak pernah ditebak dari potongan kode. Nilainya mengikuti Dynamics 365: `read`, `update`, `create`, `correct`, `delete`, `invoke`.

## Hierarchy security role

Satu security role dapat disusun di atas role lain. Pemegang role parent ikut memperoleh seluruh duty turunannya, sehingga `Manajer Aset` cukup dibangun di atas `Pengelola Group Aset` tanpa menyalin duty-nya.

Satu role boleh mempunyai lebih dari satu parent dan lebih dari satu child, jadi susunannya graph berarah tanpa siklus — bukan tree. Aturannya:

- lingkaran ditolak saat disimpan, bukan disaring saat dibaca;
- role turunan harus milik tenant yang sama, dan database menegakkannya lewat composite foreign key;
- role yang dinonaktifkan tidak memberi hak apa pun dan tidak menjadi jembatan ke turunannya;
- dua jalur menuju satu role yang sama tidak menggandakan hak.

Hierarchy role menyusun **tanggung jawab**. Ia tidak ada hubungannya dengan organization hierarchy yang menyusun **organisasi**; jangan meresolusi yang satu memakai yang lain.

App tidak mengetahui hierarchy role sama sekali. Core sudah memperluas rantainya sebelum menerbitkan permission efektif pada token konteks, sehingga hak warisan tiba di app persis seperti hak langsung.

Contoh: pegawai Finance yang juga mengontrol aset menerima role `Finance Manager` dan `Asset Controller`. Rangkap jabatan tidak memindahkan module Asset ke Finance dan tidak memerlukan identity kedua.

## Permission dan operasi sensitif

Permission menyatakan kemampuan bisnis, bukan sekadar visibilitas menu. UI menyembunyikan action yang tidak dimiliki user, tetapi API tetap memvalidasi authorization.

| Operasi | Pola permission | Catatan |
| --- | --- | --- |
| Read | `module.resource.read` | Membaca daftar dan detail. |
| Create | `module.resource.create` | Membuat record pada konteks yang sah. |
| Update | `module.resource.update` | Tidak otomatis memberi hak approve. |
| Delete lifecycle | `archive`, `void`, atau `retire` | Hindari hard-delete generik untuk transaksi ERP. |
| Sensitive action | `approve`, `post`, `pay`, atau `close` | Duty terpisah dan masuk pemeriksaan SoD. |
| Settings | `module.settings.manage` | Mengubah konfigurasi module. |

Tenant boleh mengubah komposisi role dan membuat duty/privilege khusus melalui Konfigurasi keamanan, tetapi tidak boleh menciptakan permission code atau entry point palsu. Kode tersebut hanya berasal dari contract module yang dikenal katalog.

## Granularitas permission dan duty

Permission dibuat per kemampuan kecil yang benar-benar dilindungi, misalnya `app.entities.manage`, `app.groups.manage`, dan `app.categories.manage`. Satu menu “Master Data” tidak berarti satu permission atau satu duty.

Duty menggabungkan privilege, dan privilege menggabungkan permission; duty bukan jabatan dan tidak boleh otomatis menyatukan seluruh master data. Contoh yang sah untuk satu master:

```text
permission  entitas-aset.read / .create / .update / .archive
privilege   entitas-aset.maintain -> read + create + update
            entitas-aset.retire   -> archive
duty        entitas-aset.manage   -> maintain + retire
role        disusun tenant dari duty yang diperlukan
```

Lapisan privilege bukan formalitas. Ia yang membuat tenant dapat memberi hak memelihara data tanpa memberi hak mengarsipkannya. Kalau privilege dibuat satu-untuk-satu dengan permission, pilihan itu hilang dan duty menjadi satu-satunya ukuran pemberian hak.

Administrator tenant kemudian dapat menyusun role sesuai jabatan setempat. Seseorang dapat hanya mengelola entitas tanpa memperoleh hak mengelola grup, kategori, atau data maintenance.

Sebelum membuat atau mengubah contract permission/duty, pemilik produk dan tenant harus menyepakati: resource yang dikelola, aksi yang diizinkan, apakah read dipisahkan dari manage, cakupan organisasi, serta duty mana yang dibutuhkan. Jika scope ini belum jelas, implementasi berhenti pada rekomendasi opsi; tidak ada permission, duty, UI, atau endpoint yang ditambahkan sampai keputusan dikonfirmasi.

## Data-policy-scoped role assignment

```text
role_assignments
├── membership_id, role_id
├── source: manual | automatic | temporary
└── valid_from, valid_until

app_data_policies
└── code, protected_permissions, required dimensions, descendant rule

role_assignment_data_policy_scopes
├── role_assignment_id, policy_code
├── legal_entity_id, organization_id
├── hierarchy_id, hierarchy_version_id
└── include_descendants, valid_from, valid_until
```

Role memberi kemampuan menjalankan entry point; **data policy** menentukan record mana yang boleh dijangkau. Satu role assignment dapat memiliki beberapa grant pada policy yang dipakainya. Grant menyebut legal entity bila policy memerlukannya, node organisasi, serta hierarchy dan versinya bila descendants disertakan. Assignment pada Business Unit Negarow dengan `include_descendants=true` hanya berlaku menurut hierarchy dan versi yang dicatat; hierarchy lain tidak mengubahnya.

Grant dalam policy yang sama digabungkan agar seorang holding dapat diberi FO Negarow dan Sales Negarow sekaligus. Claim aplikasi membawa **pasangan grant**, bukan dua daftar ID datar: grant `(legal entity A, FO A)` dan `(legal entity B, FO B)` tidak pernah berubah menjadi izin palsu `(legal entity A, FO B)`. Module menerapkan OR antar-pasangan grant dan AND di dalam satu pasangan. Policy yang berbeda tidak pernah saling memperluas akses. Bila satu resource kelak membutuhkan lebih dari satu policy, kontrak resource wajib menyatakan kombinatornya; default bukan union lintas policy. Tidak ada `revoke` generik: pengecualian harus menjadi policy/proses yang jelas dan diuji, bukan daftar deny global yang dapat menabrak policy lain.

Contoh yang wajib dapat dimodelkan tanpa menggandakan identity: role `Petugas Aset` Andi berlaku pada business unit Negarow dan Denpasar, sedangkan role `Manajer Aset` hanya berlaku di Negarow beserta turunannya. Dua scope business unit yang sejajar bukan alasan membuat role baru, membership kedua, atau menaruh dua nilai pada kolom user.

## Workforce dan rangkap position

Department, job, position, dan worker-position assignment adalah fakta berbeda:

- department adalah operating unit;
- job adalah jenis pekerjaan;
- position adalah kursi kerja tertentu;
- assignment menyatakan worker yang menduduki position dan periode berlakunya.

Satu worker dapat mempunyai beberapa position aktif. Satu position hanya mempunyai satu worker aktif pada waktu yang sama. Position relationship dapat membentuk managerial atau matrix reporting hierarchy tanpa mengubah organization hierarchy.

Contoh kerja pada dua business unit adalah satu worker dengan dua position assignment efektif: `Petugas Aset — Negarow` berada pada business unit Negarow dan `Petugas Aset — Denpasar` berada pada business unit Denpasar. Kedua assignment mempunyai tanggal mulai/akhir masing-masing. Posisi adalah fakta HR; scope data dan role tetap dihitung terpisah dari seluruh assignment aktif.

Automatic assignment rule dapat menghubungkan position atau business data dengan security role. Berakhirnya position assignment harus menghitung ulang role otomatis tanpa mencabut assignment manual yang tidak terkait.

## Temporary access dan segregation of duties

Temporary role session memberi akses berbatas waktu dengan:

- role dan organization scope yang eksplisit;
- waktu mulai/akhir;
- mode `merge` atau `replace`;
- approval dan audit trail;
- pencabutan otomatis saat periode selesai.

Segregation of duties (SoD) mendefinisikan pasangan duty yang konflik, severity, risiko, dan mitigasi. Conflict evaluation menggunakan seluruh duty efektif lintas role. Rangkap jabatan tetap boleh selama tidak menghasilkan konflik yang ditolak atau konflik tersebut memperoleh approval serta mitigasi tercatat.

## Model data target

```text
users
tenant_memberships

workers
jobs
positions
worker_position_assignments
position_hierarchy_types
position_relationships

app_entry_points
permissions
security_privileges
security_privilege_permissions
security_duties
security_duty_privileges
security_roles
security_role_duties
security_role_children

role_assignments
app_data_policies
role_assignment_data_policy_scopes
automatic_role_assignment_rules
temporary_role_sessions
sod_rules
sod_conflicts
access_audit_events
```

Semua record tenant-owned membawa `tenant_id`. Join lintas module database tetap dilarang; module menerima identity, permission, dan claim data policy melalui token/API/event contract tepercaya.

## Keadaan worktree saat ini

Control Plane mempunyai UI [Organization](/settings/organization) untuk legal entity, operating unit/business unit, dan hierarchy; [Access](/settings/access) untuk role dan batas data per policy; serta [Konfigurasi keamanan](/settings/security-configuration) untuk menyusun privilege/duty khusus tenant dari izin aplikasi yang telah terdaftar. Assignment otomatis dari HR dipertahankan saat admin mengubah assignment manual. Core menerbitkan claim policy efektif melalui token konteks bertanda tangan; pemilihan workspace hanya menjadi filter awal dan nilai default transaksi.

HR menyediakan workforce dasar dan mengirim perubahan penugasan posisi ke Core. Core menyimpan audit perubahan dan dapat mematerialisasi role otomatis melalui rule berbasis posisi. Konfigurasi rule otomatis, temporary role session, SoD evaluation, payroll, cuti, dan workflow belum tersedia sebagai layar administrasi. Status repo rinci ada di [10-core-foundation-gates.md](10-core-foundation-gates.md).

## Onboarding

`POST /register` dan business-registration API pada target memakai action transaksi yang sama:

```text
identity user
  -> client
  -> tenant
  -> owner membership
  -> entitlement untuk produk yang dipilih
```

Registrasi tidak meminta organization depth dan tidak membuat root organization palsu. Guided setup setelah registrasi membuat minimal satu legal entity sebelum proses finansial atau dokumen resmi digunakan. Operating unit dan purpose-scoped hierarchy ditambahkan hanya sesuai struktur perusahaan.

Pemilihan produk hanya menghasilkan entitlement. Artifact deployment diproses dan dicatat terpisah oleh installation/deployment registry. Role tidak membuktikan entitlement, entitlement tidak membuktikan installation, dan installation tidak membuktikan runtime readiness.

## Invitation dan role provisioning

Owner/Admin membuat kode undangan yang dapat dipakai berulang sampai dicabut atau kedaluwarsa. Core menyimpan hash untuk validasi dan ciphertext agar admin yang berwenang dapat menyalin ulang kode aktif. Invitation dapat membawa role platform selain `owner`, security-role assignment, serta grant data policy. Redemption membuat identity/membership dan assignment dalam satu transaksi; akses anggota yang sudah bergabung tidak berubah jika kode kemudian dicabut.

### Dua jenis undangan

Sejak 16 September 2026 undangan punya dua bentuk, dan yang membedakannya satu kolom: ada atau tidaknya email yang diundang.

| | Kode anonim | Terikat akun SSO |
| --- | --- | --- |
| Siapa yang dapat menukarkan | Siapa pun yang memegang kodenya | Hanya satu akun SSO tertentu |
| Berapa kali | Berulang, sampai dicabut | Sekali |
| Kedaluwarsa | Tidak, kecuali disetel | Ya, bawaan tujuh hari |
| Cara menukarkan | Formulir nama, email, kata sandi di `/join` | Tombol masuk SSO |
| Syarat | Tidak ada | Email wajib sudah terdaftar di penyedia SSO |

Undangan terikat menyimpan `sso_subject` — nilai yang dijawab endpoint pencarian penyedia saat undangan dibuat, dan yang kelak muncul sebagai klaim `sub` di ID token. **Yang dibandingkan saat penukaran adalah subjek itu, tidak pernah emailnya.** Alasannya sama dengan alasan jalur masuk SSO menolak pencocokan lewat email: penyedia menulis `email_verified_at` pada pendaftaran mandiri tanpa benar-benar memverifikasi, sehingga siapa pun yang mendaftar di sana dengan email orang lain akan lolos kalau email dijadikan bukti.

Konsekuensinya, kode undangan terikat **bukan rahasia yang menentukan**. Ia hanya memilih baris mana yang sedang ditukar, dan itu sebabnya ia boleh dikirim lewat tautan di email. `RedeemInvitation::locate()` menolak kode terikat pada jalur kata sandi, jadi tidak ada jalan lain menukarkannya selain upacara SSO.

Penukarannya memakai upacara tiga kaki yang sama dengan masuk lewat SSO — `sso/gabung` di alamat tenant, `sso/callback` di domain dasar, `sso/serah` kembali di alamat tenant — dengan `sso_login_attempts.invitation_id` sebagai penanda jenis upacaranya. Akun, tautan identitas, keanggotaan, peran, dan batas datanya lahir dalam satu transaksi di kaki ketiga, lewat `RedeemInvitation::attachInvitation()` yang dipakai bersama kedua jalur lain.

Emailnya dikirim penyedia, bukan Core: repo ini tidak punya jalur email sama sekali. Tautannya mendarat di `/undangan` pada **domain dasar** lalu dialihkan ke alamat tenant, karena penyedia menolak `accept_url` yang host-nya di luar alamat balik client — dan alamat balik itu satu per penempatan, di domain dasar.

Yang perlu disadari: endpoint pencarian penyedia tidak mengenal scope, sehingga setiap operator ber-`manage-access` dapat memakainya untuk memeriksa apakah sebuah email terdaftar di direktori. Yang menahannya hanya throttle dan jejak audit `access.invitation.sso.ditolak`.

Role dapat diberikan melalui:

1. assignment manual;
2. automatic assignment rule berdasarkan position/business data;
3. temporary role session berbatas waktu.

Semua perubahan assignment menghasilkan audit event dan menjalankan pemeriksaan SoD sebelum efektif.

## Runtime authorization

Untuk setiap entry point module, gateway/service melakukan urutan berikut:

1. validasi identity, session/token, dan `TenantContext`;
2. pastikan produk dikenal katalog;
3. pastikan tenant mempunyai entitlement yang masih berlaku;
4. pastikan installation registry menyatakan release pada placement sudah `ready`;
5. ambil role assignment yang aktif;
6. perluas role → duty → privilege → permission;
7. terapkan claim data policy pada record/proses yang dilindungi;
8. catat operasi sensitif pada audit trail.

Tidak ada satu langkah yang boleh menggantikan langkah lainnya.

### Kontrak signed app context

Core menandatangani JWT maksimal lima menit untuk app yang tepat (`aud`). Selain
`tenant_id`, `sub`, dan `permissions`, claim `data_policies` berbentuk berikut:

```json
{
  "management-aset.asset-responsibility": {
    "all": false,
    "scope_grants": [
      { "legal_entity_id": "LE-A", "operating_unit_ids": ["FO-A"] },
      { "legal_entity_id": "LE-B", "operating_unit_ids": ["FO-B"] }
    ]
  }
}
```

App memverifikasi signature, `iss`, `aud`, masa berlaku, tenant, dan bentuk
claim sebelum menjalankan query. Nilai organisasi dari browser dan pilihan
workspace hanya boleh menjadi nilai awal form/filter; keduanya tidak boleh
mengubah pasangan grant ini.

## Number sequence administration

Owner dan admin tenant dapat mengatur Number Sequence melalui permission platform `manage-number-sequences`. Permission ini memberi akses ke konfigurasi nomor tenant aktif saja; ia tidak memberi provider admin akses ke nomor tenant dan tidak memberi app akses ke layar konfigurasi. App memakai credential service serta context tenant yang Core verifikasi terhadap entitlement dan installation readiness.

## Product launcher

Core selalu tersedia sebagai shell Core. Produk bisnis hanya tampil sebagai siap digunakan bila:

- tenant mempunyai entitlement aktif;
- installation/deployment registry menyatakan placement/release `ready`;
- user memiliki permission pada entry point launcher.

Jika installation registry belum tersedia, UI tidak boleh memakai label “terpasang” atau “siap digunakan”.

## Active workspace

Header workspace memilih tenant, legal entity, dan operating unit aktif dari scope yang sah. Server memvalidasi semua pilihan sebelum menyimpannya pada session. Untuk worker dengan scope Negarow dan Denpasar, pilihan workspace menentukan konteks transaksi baru; ia bukan penyempitan atau perluasan authorization. Datatable boleh memakai workspace sebagai filter awal yang mudah dipahami, tetapi endpoint tetap mengotorisasi terhadap seluruh scope efektif dan tidak boleh menerima unit dari browser sebagai bukti hak.

Module menerima `tenant_id`, `legal_entity_id`, dan `org_unit_id` dari `TenantContext` tepercaya. Nilai tersebut tidak boleh diambil dari body/query parameter bebas.

## Provider access

Provider admin bukan tenant membership. Akses support lintas tenant hanya boleh melihat metadata yang diizinkan dan tidak otomatis dapat membaca data bisnis module. Credential lokal berasal dari environment/secret store, bukan source code.

## Acceptance target

- satu worker dengan dua position pada dua business unit menerima union role dan union scope yang tepat tanpa identity atau membership ganda;
- satu role assignment dapat menyimpan beberapa grant policy yang berbeda tanpa mengubah grant role lain;
- role lintas module bekerja tanpa `roles.module_id`;
- `include_descendants` selalu menyebut hierarchy yang menjadi acuan;
- perubahan hierarchy tidak mengubah sejarah scope version yang sudah efektif;
- temporary role dicabut otomatis saat berakhir;
- konflik SoD ditolak atau mempunyai approval dan mitigasi;
- entitlement tanpa installation record tidak pernah tampil sebagai produk terpasang;
- API tetap menolak akses walaupun menu disembunyikan atau workspace selector dimanipulasi.

## Setup lokal

Setup Laravel saat ini tetap dijalankan dari `apps/core`:

```powershell
php artisan core:configure-local
php artisan migrate --seed --force
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Perintah tersebut hanya diarahkan ke database Core. Foundation policy scope per role assignment, token claim bertanda tangan, dan enforcement awal Asset/HR tersedia. Konfigurasi automatic rule, temporary role session, SoD evaluation, serta load gate lintas aplikasi masih mengikuti backlog fleksibilitas. Database development di-reset saat model security berubah; tidak ada compatibility path scope lama.

## Lihat juga

- [Rantai keamanan modul transaksi](19-transaction-security-chain.md) — ringkasan rantai ini beserta diagram dan checklist per transaksi
- [Tenant dan hierarki organisasi](01a-tenant-and-org-hierarchy.md) — organisasi yang menjadi batas scope
- [Query scope dan schema](08-query-scopes-and-schema.md) — persistensi dan query scope-nya
- [Standar module](02-module-standard.md) — asal duty dan privilege dari manifest app
- [Gate fondasi Core](10-core-foundation-gates.md) — bagian akses mana yang belum tersedia
- [Alur end-to-end](../onboarding/alur-end-to-end.md) — role, duty, dan scope dalam satu alur nyata
- [Glosarium](../onboarding/glosarium.md) — istilah role, duty, privilege, dan permission
