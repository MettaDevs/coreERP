# Sudah dikerjakan dan diverifikasi

Dicatat supaya tidak dikerjakan dua kali, dan supaya temuan di dokumen lain yang
sudah tidak berlaku bisa dicoret saat review.

Verifikasi terakhir: control-plane **125/125 lulus**, Management Aset **28/28 lulus**.

## Model keamanan empat lapis

Akar masalahnya ada di Core, bukan di app. `RegisterAppCatalog` lama membuat entry
point, permission, dan privilege dengan **kode yang sama persis**, lalu menebak
access level dengan memotong string kode (`...archive` menjadi access level
`archive`). Duty menyimpan kode permission ke tabel privilege. Rantai empat lapis
itu sebenarnya satu lapis bersalin tiga — dan `app.yaml` hanya mengikuti bentuk
API Core yang memang begitu.

Diverifikasi lebih dulu ke [role-based security D365](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/role-based-security)
dan [security architecture](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/security-architecture):
rantainya memang kanonik, entry point adalah menu item, web content, dan service
operation, dan access level-nya `read`, `update`, `create`, `correct`, `delete`,
`invoke`.

- `AppCatalogRequest` — validasi empat lapis terpisah, access level dan tipe entry
  point D365, cek referensi antar-lapis, dan guard bahwa kode privilege tidak boleh
  sama dengan kode permission.
- `RegisterAppCatalog` — menulis empat baris berbeda; manifest jadi sumber
  kebenaran sehingga metadata yang hilang dipangkas; menolak menghapus duty yang
  masih dipakai security role tenant.
- `AppCatalogSeeder` — didelegasikan ke `RegisterAppCatalog`, jadi hanya ada satu
  jalur ingestion yang bisa menyimpang.
- `app.yaml` Management Aset — 16 entry point, 32 permission, 16 privilege, 8 duty.
  Hak efektif tiap duty **tidak berubah**; yang bertambah adalah kemampuan tenant
  memberi `maintain` tanpa memberi hak arsip.
- Test regresi yang membuktikan keempat lapis tidak dapat saling menyamar.

**Sisa yang belum:** Core masih belum benar-benar membaca `app.yaml`. Validasi
sekarang ada di dua tempat yang ditulis terpisah — `AppCatalogRequest` di Core dan
`loadtest/check-manifest.py` di repo app. Lihat `LIFE-08`.

## Skill dan dokumen yang memuat bug-nya

Skill `coreerp-architecture` sendiri berisi instruksi yang salah: *"Model a duty as
a named bundle of those permissions"* — melompati layer privilege, dan bertentangan
dengan aturan rantai di bagian atas skill yang sama. Kalimat serupa juga ada di
`docs/dev/09`. Jadi drift ini bukan kelalaian sesaat; ia mengikuti instruksi.

- Kedua salinan skill (`.claude/` dan `.agents/`) diperbaiki dan disinkronkan.
- Ditambahkan: empat lapis wajib jadi empat baris berbeda, kode privilege tidak
  boleh sama dengan permission, access level dideklarasikan bukan diturunkan dari
  string, daftar access level D365, dan aturan DAG untuk hierarchy role.

## Hierarchy security role

Mengikuti D365: parent mewarisi duty seluruh turunannya, dan satu role boleh punya
banyak parent maupun banyak child — jadi graph berarah tanpa siklus, bukan tree.

- `security_role_children` dengan composite foreign key `(tenant_id, role_id)`,
  sehingga database yang menolak menautkan role tenant lain.
- `RoleHierarchy` — ekspansi lewat recursive CTE; role non-aktif tidak memberi hak
  **dan tidak menjadi jembatan** ke turunannya.
- `LaunchableAppCatalog` memperluas role assignment lewat hierarchy sebelum
  menurunkan duty, privilege, dan permission.
- `UpsertRole` menolak lingkaran saat menyimpan, di dalam transaksi.
- **9 test**, termasuk: warisan tidak naik ke atas, cucu ikut terwarisi, diamond
  tidak menggandakan hak, role dinonaktifkan memutus jalur, dan token konteks yang
  diterima app memang memuat hak warisan.

App tidak perlu diubah sama sekali — ia hanya membaca daftar permission dari token
dan tidak tahu hierarchy role itu ada. Itu hasil yang benar.

## Pembersihan repo app dan template

- Tabel `users`, `password_reset_tokens`, `sessions`, model `User`, `UserFactory`,
  dan `config/auth.php` dihapus dari **kedua** repo. Sumbernya adalah
  `app-erp-template` yang membawa scaffolding bawaan Laravel, jadi setiap app baru
  akan mewarisinya lagi kalau tidak dicabut dari sana.
- `SESSION_DRIVER` menjadi `array` — service API stateless, tidak ada tabel session.
- Scaffolding Vite, Tailwind, `resources/`, dan `routes/web.php` dibuang dari
  `api/`; Dockerfile memang tidak pernah membangun asset, jadi itu beban mati.

## Docs

- `02` — contoh manifest empat lapis, bagian baru tentang tipe entry point, access
  level, dan larangan meringkas lapis; pola database `app_erp_<app>`.
- `06`, `13` — penamaan repository disamakan ke `app-erp-<app-key>`.
- `08` — `tenant_module_entitlements`, `module_placements`, `module_installations`
  menjadi nama tabel yang sebenarnya.
- `09` — nama tabel, koreksi "duty menggabungkan privilege", dan bagian hierarchy
  security role.
- `13` — payload registrasi empat lapis, aturan prune, dan penolakan menghapus duty
  yang masih dipakai.
- Portal docs mengarah ke `contract_url` yang didaftarkan app, bukan menyajikan
  file dari repository platform; `contracts/apps/procurement.yaml` dihapus.

## Test yang tadinya merah

Empat test gagal sebelum sesi ini dimulai, karena fixture katalog hanya memuat
`management-aset` sementara beberapa test masih memilih `procurement`, dan karena
file contract yang dilayani portal sudah dihapus. Migration
`remove_procurement_development_app` yang menentukan arahnya. Semuanya sekarang hijau.
