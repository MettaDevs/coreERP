# Identity, responsibility-based security, dan organization scope

Core Platform/Control Plane adalah sumber identity, tenant membership, security metadata, role assignment, dan entitlement. Module bisnis tidak membuat sistem user sendiri dan tidak membaca database Core secara langsung.

Model target mengikuti Microsoft Dynamics 365 untuk workforce dan role-based security. Rujukan resminya dirangkum di [referensi model organisasi Dynamics 365](../references/dynamics-365-organization-model.md).

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

Role tidak mempunyai `module_id` dan tidak dimiliki department. Module mendaftarkan entry point, permission, privilege, dan duty kanonik melalui contract/manifest. Administrator tenant menyusun role dari duty yang diperlukan, termasuk duty lintas module.

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

Tenant boleh mengubah komposisi role, tetapi tidak boleh menciptakan permission code atau entry point palsu. Kode tersebut hanya berasal dari contract module yang dikenal katalog.

## Organization-scoped role assignment

```text
user_role_assignments
├── membership_id, security_role_id
├── source: manual | automatic | temporary
└── valid_from, valid_until

role_assignment_org_scopes
├── assignment_id, organization_id
├── hierarchy_id                 # wajib bila descendants disertakan
└── include_descendants
```

Assignment pada Branch Makassar dengan `include_descendants=true` berlaku pada turunannya menurut hierarchy yang disebutkan. Hierarchy lain tidak otomatis memberi atau mencabut akses. Effective access adalah gabungan seluruh assignment aktif setelah organization scope diterapkan.

## Workforce dan rangkap position

Department, job, position, dan worker-position assignment adalah fakta berbeda:

- department adalah operating unit;
- job adalah jenis pekerjaan;
- position adalah kursi kerja tertentu;
- assignment menyatakan worker yang menduduki position dan periode berlakunya.

Satu worker dapat mempunyai beberapa position aktif. Satu position hanya mempunyai satu worker aktif pada waktu yang sama. Position relationship dapat membentuk managerial atau matrix reporting hierarchy tanpa mengubah organization hierarchy.

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

module_entry_points
security_permissions
security_privileges
security_privilege_permissions
security_duties
security_duty_privileges
security_roles
security_role_duties

user_role_assignments
role_assignment_org_scopes
automatic_role_assignment_rules
temporary_role_sessions
sod_rules
sod_conflicts
access_audit_events
```

Semua record tenant-owned membawa `tenant_id`. Join lintas module database tetap dilarang; module menerima identity, permission, dan organization scope melalui token/API/event contract tepercaya.

## Keadaan worktree saat ini

Source code Control Plane sudah memakai role → duty → privilege → permission, role lintas module, assignment manual dengan organization/hierarchy scope, onboarding tanpa depth, serta launcher yang memeriksa entitlement, installation readiness, dan business role secara terpisah.

Placement worker sekarang menulis registry hanya setelah Compose pull, migration, dan health check berhasil. Bagian target yang belum tersedia adalah workforce/position, automatic assignment rule, temporary role session, SoD evaluation, access audit event, serta integrasi deployment production dengan Dokploy. Status repo rinci ada di [06-worktree-target.md](06-worktree-target.md).

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

Owner/Admin membuat invitation yang single-use, berbatas waktu, dan hanya menyimpan hash. Invitation dapat membawa role platform selain `owner`, security-role assignment, serta organization scope. Redemption membuat identity/membership dan assignment dalam satu transaksi lalu menandai invitation telah digunakan.

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
7. terapkan legal-entity dan organization scope;
8. catat operasi sensitif pada audit trail.

Tidak ada satu langkah yang boleh menggantikan langkah lainnya.

## Product launcher

Core selalu tersedia sebagai control-plane shell. Produk bisnis hanya tampil sebagai siap digunakan bila:

- tenant mempunyai entitlement aktif;
- installation/deployment registry menyatakan placement/release `ready`;
- user memiliki permission pada entry point launcher.

Jika installation registry belum tersedia, UI tidak boleh memakai label “terpasang” atau “siap digunakan”.

## Active workspace

Header workspace memilih tenant, legal entity, dan operating unit aktif dari assignment yang sah. Server memvalidasi semua pilihan sebelum menyimpannya pada session. Selector tidak menggantikan authorization endpoint dan tidak menjadi tempat submenu module.

Module menerima `tenant_id`, `legal_entity_id`, dan `org_unit_id` dari `TenantContext` tepercaya. Nilai tersebut tidak boleh diambil dari body/query parameter bebas.

## Provider access

Provider admin bukan tenant membership. Akses support lintas tenant hanya boleh melihat metadata yang diizinkan dan tidak otomatis dapat membaca data bisnis module. Credential lokal berasal dari environment/secret store, bukan source code.

## Acceptance target

- user dengan dua position menerima union role yang tepat tanpa identity ganda;
- role lintas module bekerja tanpa `roles.module_id`;
- `include_descendants` selalu menyebut hierarchy yang menjadi acuan;
- perubahan hierarchy tidak mengubah sejarah scope version yang sudah efektif;
- temporary role dicabut otomatis saat berakhir;
- konflik SoD ditolak atau mempunyai approval dan mitigasi;
- entitlement tanpa installation record tidak pernah tampil sebagai produk terpasang;
- API tetap menolak akses walaupun menu disembunyikan atau workspace selector dimanipulasi.

## Setup lokal

Setup Laravel saat ini tetap dijalankan dari `apps/control-plane`:

```powershell
php artisan core:configure-local
php artisan migrate --seed --force
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Perintah tersebut hanya diarahkan ke database Core. Migration organization/hierarchy dan fondasi responsibility-based security sudah tersedia; workforce/position, automatic/temporary access, SoD, dan audit trail belum diimplementasikan.
