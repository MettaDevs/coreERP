# Memecah Core menjadi lapis Platform dan Foundation

Rencana kerja memindahkan kode `apps/core/app` dari susunan per jenis teknis (`Models/`, `Support/`,
`Http/Controllers/`, …) menjadi dua lapis, dan di dalam tiap lapis per fitur. Polanya meniru
Business Central (`D:\Kerja\BCApps`): System Application → Business Foundation → Base App. Lapis
ketiga di sini adalah `modules/`, yang sudah terpisah dan sudah dijaga.

Disetujui pemilik produk pada 1 Oktober 2026.

## Kenapa sekarang

Tim akan bertambah menjadi 3–4 engineer ditambah magang, dan kode ini kelak diserahterimakan.
Biaya memindah namespace naik seiring **jumlah kode × jumlah orang × jumlah branch yang terbuka**,
jadi sekarang adalah saat termurahnya. Pemindahan ini murni perubahan kode, tidak menyentuh data:
tidak ada nama kelas PHP yang tersimpan di database (tidak ada morph map, dan `source_document_type`
berupa string bebas).

## Yang diputuskan

| Keputusan | Isi |
| --- | --- |
| Lapis | `App\Platform\<Fitur>` dan `App\Foundation\<Fitur>`, di dalam satu app `apps/core` |
| Lapis aplikasi | `modules/` tetap seperti sekarang |
| Control plane | `apps/control-plane` tetap aplikasi terpisah dan tidak disentuh. Sisi Core-nya dibelah dua: `Platform\Environment` (lingkungan aktif, alamat, koneksi; boleh dipakai siapa pun) dan `Platform\ControlPlane` (site, operasi, audit operator, setelan konsol; tertutup untuk kode lain) |
| Organization | masuk **Platform**, karena cakupan data policy di Access bergantung padanya. Ini sejalan dengan F&O: model organisasi adalah dasar keamanan |
| Vendor | masuk Foundation. Di BC, Vendor ada di Base App; ini pilihan kita karena Vendor dipakai lintas module |
| Workflow | masuk Foundation |
| Reporting | masuk Platform, seperti Report Layouts di System Application |
| Facade module | diganti ke nama Inggris dalam pekerjaan ini, sesuai aturan nama kode di `AGENTS.md` |
| Tidak pernah | nama lapis masuk ke nama tabel, kode permission/duty, nama event, URL, atau kontrak. Lapis hanya urusan susunan kode |
| Ditunda sampai ada alasan | composer package per lapis, path filter CI, CD terpisah |

## Arah ketergantungan

```
modules/  ──hanya lewat facade──►  Foundation  ──►  Platform
                                                      ▲
                     Platform\ControlPlane hanya dipakai dari Platform\ControlPlane
```

- Platform tidak boleh memakai Foundation.
- Module hanya memakai facade (kontrak publik). Aturan ini sudah dijaga
  `ModuleNamespaceBoundaryTest`, dan hanya pola namespace-nya yang berubah.
- Hanya `Platform\ControlPlane` yang boleh memakai `Platform\ControlPlane`. Satu-satunya
  pengecualian adalah penanda `OwnedByControlPlane`, karena model milik pusat perlu memakainya.

Arah ini ditegakkan oleh test Boundary baru, bukan oleh package terpisah. Di PHP satu aplikasi
tetap memuat semua kelas, jadi package tidak menjaga apa pun. Untuk TypeScript, penegaknya
`no-restricted-imports` di ESLint.

## Susunan tujuan

```
apps/core/app/
  Platform/
    Tenant/          Models/ Actions/ Http/ Console/ …
    Environment/
    ControlPlane/
    Identity/        (termasuk SSO, Fortify, passkey, profil)
    Access/
    Organization/
    Modules/         (runtime module, katalog, entitlement, pemasangan)
    Reporting/
    ChangeLog/
    Attachments/
    Retention/
    Observability/
    Integration/
    License/
    Docs/
  Foundation/
    AddressBook/  Geography/  UnitOfMeasure/  Currency/  NumberSequence/
    FiscalCalendar/  WorkingCalendar/  Vendor/  Workflow/  Finance/
  Http/ Providers/   (hanya perekat Laravel: Controller dasar, HandleInertiaRequests, provider)
```

Halaman Inertia dan test mengikuti susunan yang sama:
`resources/js/pages/<lapis>/<fitur>/…` dan `tests/Feature/<Lapis>/<Fitur>/…`.

## Pemetaan folder lama ke fitur

Hasil pemetaan 426 berkas PHP di `apps/core/app` pada `origin/main` 1 Oktober 2026 (setelah #227):

| Lapis / fitur | Berkas | Asal utama |
| --- | ---: | --- |
| Platform/Modules | 45 | `Support/Modules` (kecuali Contracts), `Actions/Modules`, `Actions/Provider`, `Http/Controllers/Provider`, model `CoreApp`, `AppEntryPoint`, `AppServiceCredential`, `ModuleInstallation`, `TenantAppEntitlement`, `ProviderAccess`, perintah `module:*` |
| Platform/Reporting | 33 | `Support/Reporting`, `Http/Controllers/Reporting`, `RunReportExport`, `ReportFormatterCore`, `ReportPreset`, `ReportLastUsedOption` |
| Platform/Identity | 32 | `Support/Sso`, `Http/Controllers/Auth`, `Actions/Fortify`, `Concerns`, `Http/Controllers/Settings`, model `User`, `Passkey`, `ExternalIdentity`, `SsoLoginAttempt`, `TenantIdentityProvider`, `TemporaryPassword` |
| Platform/Access | 29 | `Support/Access`, `Actions/Access`, `Http/Controllers/Access`, model role/duty/privilege/permission, `DataPolicy*`, `RoleHierarchy`, `SodConflictEvaluator` |
| Platform/Organization | 27 | `Actions/Organization`, `Http/Controllers/Organization`, model organisasi/legal entity/operating unit/hierarchy, `BusinessUnitResolver`, `OrganizationDirectoryCore`, direktori internal organisasi dan posisi HR |
| Platform/ControlPlane | 23 | model `Site*`, `ConsoleSetting`, `OperatorAuditEvent`, `Client`, perintah lingkungan (provision/copy/restore/purge/upgrade), `UpgradeEnvironment`, `FleetController`, `ControlPlaneOnly` |
| Platform/Tenant | 16 | model `Tenant`, `TenantMembership`, `InvitationCode`, `Actions/Onboarding`, `Http/Controllers/Onboarding`, provisioning dan entitlement internal |
| Platform/Environment | 9 | model `Environment`, `EnvironmentOperation`, `ActiveEnvironment`, `EnvironmentAddress`, `EnvironmentConnection`, `OutboundGuard`, `ResolveEnvironment`, `CurrentWorkspace` |
| Platform/ChangeLog, Observability, Integration, License, Docs | 7 + 9 + 6 + 3 + 2 | folder `Support/` bernama sama, beserta middleware dan controllernya |
| Platform/Attachments, Retention | ±10 | `Support/Attachments`, `Support/Retention`, `DocumentAttachment`, `AttachmentController`, `RetentionController`, `ApplyRetention` |
| Foundation/Finance | 27 | `Support/Finance`, `Http/Controllers/Finance` (kecuali `IntegrationClientController`), model `Finance*`, facade akun/posting |
| Foundation/Geography | 24 | `Models/ReferenceData`, `CountryRegion`, `AddressSetupController`, `Services/AddressHierarchy`, perintah impor wilayah dan kode pos |
| Foundation/NumberSequence | 21 | semua `NumberSequence*`, `CoreNumberSequences`, `NumberSequenceIssuerCore` |
| Foundation/AddressBook | 13 | model `Party*`, `PostalAddress`, `ElectronicAddress`, `Location*`, `Support/AddressBook`, `Http/Controllers/GlobalAddressBook` |
| Foundation/FiscalCalendar, WorkingCalendar, Workflow | 9 + 8 + 9 | nama sama |
| Foundation/Vendor, UnitOfMeasure, Currency | 6 + 5 + 4 | nama sama; `MoneyPrecision` ke Currency |
| Facade (`Platform/Modules/Contracts`) | 38 | lihat [Facade module](#facade-module) |

Sebelum setiap PR, jalankan ulang pemetaan atas `origin/main`. Berkas baru yang lahir sesudah
tanggal di atas harus ikut dipetakan.

## Pelanggaran arah yang sudah ada

Dengan keputusan di atas ada **23 import** yang melanggar arah. Semuanya jatuh ke empat pola:

| Pola | Contoh | Perbaikan |
| --- | --- | --- |
| `CoreServices` (Platform/Modules) menyambungkan semua facade bisnis | `CoreServices` → `NumberSequenceIssuerCore`, `VendorDirectoryCore`, `WorkflowEngineCore`, `PostingFeedCore`, … (13 import) | Tiap fitur Foundation mendaftarkan implementasi facade-nya sendiri di service provider fitur itu. Platform hanya menyediakan tempat pendaftarannya. Ini pembalikan arah, seperti di BC |
| Pemasangan module dan onboarding tenant langsung menyiapkan data Foundation | `InstallModule`, `RegisterAppCatalog`, `RegisterBusiness` → `EnsureNumberSequenceDrafts`, `ProvisionDefaultUnitsOfMeasure` (5 import) | Foundation mendengarkan kejadian tenant/module. Kontrak `TenantProvisioned` sudah ada; kejadian "module terpasang" ditambahkan bila belum ada |
| Reporting memakai presisi uang dan buku alamat | `ValueFormat`, `ValueFormats` → `MoneyPrecision`; `PrintIdentityStore` → `OrganizationAddressBook` (3 import) | Lewat facade (`CurrencyRounding` sudah ada) atau penyedia yang didaftarkan Foundation |
| Integration dan onboarding menyentuh milik fitur lain | `AuthenticateIntegrationClient`, `IntegrationClientController` → `IntegrationClientAccounts` (Finance); `RegisterBusiness` → `Client` (ControlPlane) (3 import) | Tentukan pemilik saat PR domain itu. `RegisterBusiness` → `Client` bisa dibiarkan sebagai pengecualian tercatat karena pintu itu dimatikan di v1 |
| ControlPlane menyebut perintah workflow di docblock (ditemukan saat PR 5) | `ConvertEnvironment`, `CopyEnvironment` → `PublishWorkflowEvents` (Workflow) (2 import) | Hanya rujukan docblock; perbaikan: hapus `use`, sebut nama perintah artisan-nya saja |
| Model tenant mengenal client milik pusat | `Tenant` → `Client` (ControlPlane) (1 import) | Pengecualian tercatat, keputusan pemilik produk 1 Oktober 2026: isi kelas `Tenant` tidak diubah |
| Model organisasi menunjuk data Foundation | `LegalEntity` → `FiscalCalendar`; `OrganizationParty` → `Party` (AddressBook) (2 import) | Pengecualian tercatat, keputusan pemilik produk 1 Oktober 2026: PR pemindahan tidak mengubah isi kelas. `OrganizationParty` → `Party` bergantung pada K-2 |

**Keputusan terbuka K-2: letak AddressBook.** Organization, Vendor, dan Worker semuanya party.
Apakah AddressBook (`Party`) seharusnya di Platform, seperti global address book di F&O yang
menjadi dasar model organisasi? Kalau ya, pengecualian `OrganizationParty` → `Party` dan
`PrintIdentityStore` → `OrganizationAddressBook` ikut hilang.

Test Boundary lahir dengan **daftar pengecualian** berisi 23 import ini. Setiap PR hanya boleh
memperpendek daftar itu, tidak boleh memperpanjangnya.

## Facade module

Isi `App\Platform\Modules\Contracts` adalah API publik Core bagi module. Sebagian besar masih
bernama Indonesia (`NumberSequenceIssuer`, `VendorDirectory`, `WorkflowEngine`, `FiscalCalendarDirectory`,
`ModuleReportProvider`, …), begitu pula implementasinya di `Services/Modules` (`NumberSequenceIssuerCore`,
`AccountDirectoryCore`, …). Semuanya diganti ke nama Inggris dalam pekerjaan ini. Biayanya naik dengan
setiap module dan kontrak baru, dan saat ini baru dua module yang memakainya.

**Keputusan terbuka K-1: letak facade.** Pilihannya:

1. Tetap satu namespace publik, misalnya `App\Platform\Modules\Contracts\…`. Test Boundary paling
   sederhana, tapi pemilik kontrak tidak terlihat dari letaknya.
2. Per fitur: `App\Foundation\NumberSequence\Contracts\NumberSequenceIssuer`, dan module hanya
   boleh memakai `App\*\*\Contracts\*`. Ini pola BC: tiap module punya facade sendiri, sisanya
   internal. Pemilik kontrak jelas.

Rekomendasi: **pilihan 2**. Kontrak baru yang lahir sebelum K-1 diputuskan tetap ditaruh di
`App\Platform\Modules\Contracts`, **dengan nama Inggris**.

## Urutan pekerjaan

Satu domain per PR. Reporting paling akhir karena sesi analisa gap masih mengerjakan K-30
("+ Tambah filter").

- [ ] **PR 0 — pagar dulu, tanpa memindah berkas.** Test Boundary untuk arah lapis beserta daftar
      pengecualiannya (dicocokkan lewat pola folder lama *dan* baru, supaya tetap berlaku selama
      pemindahan), ESLint `no-restricted-imports` untuk `resources/js`, dan CODEOWNERS per lapis
- [ ] **PR 1 — Platform/Environment dan Platform/ControlPlane.** Tutup ControlPlane
- [ ] **PR 2 — Foundation kecil:** Vendor, UnitOfMeasure, Currency, FiscalCalendar, WorkingCalendar
- [ ] **PR 3 — Foundation/NumberSequence,** termasuk membalik arah dari `InstallModule`,
      `RegisterAppCatalog`, dan `RegisterBusiness`
- [ ] **PR 4 — Foundation/Geography dan Foundation/AddressBook,** sekaligus memecah
      `address-setup.tsx` (4.429 baris) dan `AddressSetupController.php` (1.554 baris)
- [ ] **PR 5 — Foundation/Finance dan Foundation/Workflow**
- [ ] **PR 6 — Platform/Organization dan Platform/Tenant,** sekaligus memecah `organization.tsx`
- [ ] **PR 7 — Platform/Identity, Platform/Access,** dan fitur Platform kecil (ChangeLog,
      Observability, Integration, License, Docs, Attachments, Retention). Pecah `access.tsx` dan
      `security-configuration.tsx`
- [ ] **PR 8 — facade:** ganti nama ke Inggris, pindah sesuai K-1, sesuaikan `modules/`, dan
      bongkar `CoreServices` menjadi pendaftaran per fitur. **Kabari sesi analisa gap sebelum
      mulai**
- [ ] **PR 9 — Platform/Reporting,** setelah K-30 masuk main. **Kabari sesi analisa gap sebelum
      mulai**
- [ ] **Penutup:** daftar pengecualian Boundary kosong. Perbarui `docs/dev` (peta kode, grand
      design, standar module), skill yang menyebut path lama, dan `docs/onboarding/peta-kode`

## Yang wajib diperiksa di setiap PR pemindahan

- **Isi kelas tidak berubah.** Hanya namespace, `use`, dan path. Perubahan perilaku masuk PR lain,
  sesuai skill `code-formatting`
- `resources/js/actions` dan `routes` (Wayfinder) di-generate ulang dari namespace controller. Semua
  impor `@/actions/App/...` harus mengikuti
- Nama halaman di `Inertia::render(...)` harus mengikuti letak baru halaman TSX
- `phpstan-baseline.neon` menyimpan path berkas. Perbarui path-nya; jangan tambah entri baru
- Rujukan kelas dalam string, seperti `config/*.php`, `routes/*.php`, `bootstrap/app.php`, alias
  middleware, dan `$commands`, tidak tertangkap oleh pencarian `use`
- Kontrak (`contracts/*.yaml`) tidak berubah. Jalankan `python contracts/bundle.py` dan
  `check-contract-coverage.py` untuk membuktikannya
- Suite Core dua tahap, persis seperti CI (lihat `AGENTS.md`), plus `tests/Feature/Boundary`

## Koordinasi

Sesi "Analisa Gap Core ERP ke BC Phase 1" sedang memegang:

- **Reporting**: `Support/Reporting/**`, `Http/Controllers/Reporting/**`, `resources/js/lib/reports.ts`,
  komponen dialog cetak. Perkiraan 1–2 hari untuk K-30
- **Facade**: kontrak baru untuk katalog field tabel dan pengurai ekspresi filter, plus perubahan
  `ModuleReportProvider`
- `modules/apperp/management-aset/src/Reporting/**` dan `ui/laporan/**`

PR 8 dan PR 9 menunggu pekerjaan itu masuk main. Sesi itu juga diminta tidak menambah import dari
Reporting ke model Foundation.

PR magang yang masih terbuka tidak ditunggu. Bila bentrok, PR itu yang menyesuaikan ke namespace
baru.
