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
    AddressBook/  Geography/   (K-2)
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
    UnitOfMeasure/  Currency/  NumberSequence/
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
| Platform/Geography (K-2; awalnya Foundation) | 24 | `Models/ReferenceData`, `CountryRegion`, `AddressSetupController`, `Services/AddressHierarchy`, perintah impor wilayah dan kode pos |
| Foundation/NumberSequence | 21 | semua `NumberSequence*`, `CoreNumberSequences`, `NumberSequenceIssuerCore` |
| Platform/AddressBook (K-2; awalnya Foundation) | 13 | model `Party*`, `PostalAddress`, `ElectronicAddress`, `Location*`, `Support/AddressBook`, `Http/Controllers/GlobalAddressBook` |
| Foundation/FiscalCalendar, WorkingCalendar, Workflow | 9 + 8 + 9 | nama sama |
| Foundation/Vendor, UnitOfMeasure, Currency | 6 + 5 + 4 | nama sama; `MoneyPrecision` ke Currency |
| Facade (`Platform/Modules/Contracts`) | 38 | lihat [Facade module](#facade-module) |

Sebelum setiap PR, jalankan ulang pemetaan atas `origin/main`. Berkas baru yang lahir sesudah
tanggal di atas harus ikut dipetakan.

## Pelanggaran arah yang sudah ada

Dengan keputusan di atas ada **23 import** yang melanggar arah. Semuanya jatuh ke empat pola:

| Pola | Contoh | Perbaikan |
| --- | --- | --- |
| ~~`CoreServices` (Platform/Modules) menyambungkan semua facade bisnis~~ | ~~`CoreServices` → `NumberSequenceIssuerCore`, `VendorDirectoryCore`, `WorkflowEngineCore`, `PostingFeedCore`, … (10 import)~~ | Selesai di PR 8b: tiap fitur Foundation mengikat pelaksananya sendiri di `<Fitur>ServiceProvider`, `CoreServices` hanya menyebut antarmukanya |
| Integration dan onboarding menyentuh milik fitur lain | ~~`AuthenticateIntegrationClient`, `IntegrationClientController` → `IntegrationClientAccounts` (Finance)~~; `RegisterBusiness` → `Client` (ControlPlane) (1 import tersisa) | `IntegrationClientAccounts` pindah ke `Platform\Integration\Support` di PR 8b: isinya hanya akun aplikasi klien integrasi (`User` dan `IntegrationClient`, keduanya Platform), tanpa satu pun model posting. `RegisterBusiness` → `Client` bisa dibiarkan sebagai pengecualian tercatat karena pintu itu dimatikan di v1 |
| ~~ControlPlane menyebut perintah workflow di docblock (ditemukan saat PR 5)~~ | ~~`ConvertEnvironment`, `CopyEnvironment` → `PublishWorkflowEvents` (Workflow) (2 import)~~ | Selesai di PR 8b: `use` dihapus, docblock menyebut `workflow-events:publish` |
| ~~Pemasangan module dan onboarding tenant langsung menyiapkan data Foundation~~ | ~~`InstallModule`, `RegisterAppCatalog`, `RegisterBusiness` → `EnsureNumberSequenceDrafts`, `ProvisionDefaultUnitsOfMeasure`, `NumberSequenceReference` (6 import)~~ | **Selesai.** Platform mengirim event internal (`Platform\Tenant\Events\TenantCreated`, `TenantModulesInstalled`, `Platform\Modules\Events\ModuleInstallationRecorded`, `AppNumberSequenceReferencesDeclared`, `AppCatalogRegistered`) sinkron di titik pemanggilan lama, dan Foundation mendengarkannya. `TenantProvisioned` tidak dipakai karena ia dikirim per module sesudah commit. Listener didaftarkan di `NumberSequenceServiceProvider` dan `UnitOfMeasureServiceProvider` |
| Reporting memakai presisi uang dan buku alamat | ~~`ValueFormat`, `ValueFormats` → `MoneyPrecision`~~; ~~`PrintIdentityStore` → `OrganizationAddressBook`~~ | `ValueFormats` membaca presisi lewat `CurrencyRounding`; `ValueFormat` membawa pembulatan tampilannya sendiri, dijaga sama persis dengan `MoneyPrecision::round` oleh `ValueFormatRoundingTest`. **Selesai.** `PrintIdentityStore` → `OrganizationAddressBook` hilang karena AddressBook pindah ke Platform (K-2) |
| Model tenant mengenal client milik pusat | `Tenant` → `Client` (ControlPlane) (1 import) | Pengecualian tercatat, keputusan pemilik produk 1 Oktober 2026: isi kelas `Tenant` tidak diubah |
| Model organisasi menunjuk data Foundation | `LegalEntity` → `FiscalCalendar`; ~~`OrganizationParty` → `Party` (AddressBook)~~ (1 import tersisa) | Pengecualian tercatat, keputusan pemilik produk 1 Oktober 2026: PR pemindahan tidak mengubah isi kelas. `OrganizationParty` → `Party` hilang karena AddressBook pindah ke Platform (K-2) |

**K-2: letak AddressBook — diputuskan pemilik produk 1 Oktober 2026: Platform.** Organization,
Vendor, dan Worker semuanya party, dan di F&O global address book menjadi dasar model organisasi.
AddressBook pindah ke `App\Platform\AddressBook`, sehingga pengecualian `OrganizationParty` → `Party`
dan `PrintIdentityStore` → `OrganizationAddressBook` hilang. Vendor (Foundation) memakai AddressBook
(Platform), dan arah itu sah.

Geography ikut pindah ke `App\Platform\Geography` dalam PR yang sama. Alasannya: AddressBook memakai
`CountryRegion` (`PostalAddressFormatter` dan `OrganizationLocationController`), sehingga AddressBook
di Platform dengan Geography di Foundation melahirkan dua pelanggaran arah baru. Di F&O, address setup
(country/region, provinsi, kode pos) memang bagian dari global address book. Geography sendiri hanya
memakai Platform, jadi pemindahannya tidak menambah pengecualian.

Test Boundary lahir dengan **daftar pengecualian** berisi 23 import ini. Setiap PR hanya boleh
memperpendek daftar itu, tidak boleh memperpanjangnya.

## Facade module

Isi `App\Platform\Modules\Contracts` adalah API publik Core bagi module. Sampai PR facade isinya
tinggal di `App\Support\Modules\Contracts` dan sebagian besar bernama Indonesia (`PenerbitNomor`,
`DaftarVendor`, `MesinWorkflow`, `KalenderFiskal`, `PenyediaLaporanModul`, …), begitu pula
pelaksananya (`PenerbitNomorCore`, `DaftarAkunCore`, …). PR facade memindahkannya dan mengganti
semuanya ke nama Inggris (`NumberSequenceIssuer`, `VendorDirectory`, `WorkflowEngine`,
`FiscalCalendarDirectory`, `ModuleReportProvider`, …); tabel pemetaan lengkapnya ada di deskripsi
pull request itu.

**K-1: letak facade — diputuskan pemilik produk: tetap satu namespace,
`App\Platform\Modules\Contracts`, dengan nama Inggris.** Bukan per fitur. Aturan "module hanya boleh
menyebut satu namespace" tetap berlaku; hanya namespacenya yang berganti, dan
`ModuleNamespaceBoundaryTest` menolak namespace lama. Pelaksana tiap kontrak tetap tinggal di
`ModuleServices` fitur pemiliknya. Pilihan yang tidak diambil: facade per fitur
(`App\Foundation\NumberSequence\Contracts\…`, module memakai `App\*\*\Contracts\*`).

## Urutan pekerjaan

Satu domain per PR. Reporting paling akhir karena sesi analisa gap masih mengerjakan K-30
("+ Tambah filter").

- [x] **PR 0 — pagar dulu, tanpa memindah berkas** (#230). Test Boundary untuk arah lapis beserta
      daftar pengecualiannya. ESLint `no-restricted-imports` dan CODEOWNERS belum ikut; lihat sisa di
      bawah
- [x] **Pilot — Foundation/Vendor** (#231)
- [x] **PR 1 — Platform/Environment dan Platform/ControlPlane** (#234)
- [x] **PR 2 — Foundation kecil:** Vendor (#231), UnitOfMeasure, Currency, FiscalCalendar,
      WorkingCalendar (#233)
- [x] **PR 3 — Foundation/NumberSequence** (#233). Pembalikan arah dari `InstallModule`,
      `RegisterAppCatalog`, dan `RegisterBusiness` menyusul lewat event sinkron (PR balik arah)
- [x] **PR 4 — Foundation/Geography dan Foundation/AddressBook** (#232). Pemecahan berkas raksasa
      ditunda; lihat sisa di bawah
- [x] **PR 5 — Foundation/FinancePosting, Foundation/Workflow,** dan Platform/Integration (#237)
- [x] **PR 6 — Platform/Organization dan Platform/Tenant** (#236)
- [x] **PR 7 — Platform/Identity, Platform/Access,** dan fitur Platform kecil (ChangeLog,
      Observability, Integration, License, Docs, Attachments, Retention) (#238)
- [x] **PR 9 — Platform/Reporting** (#240)
- [x] **Platform/Modules** (#241)
- [x] **PR 8 — facade:** ganti nama ke Inggris, pindah sesuai K-1, sesuaikan `modules/` (#242)
- [x] **Penutup:** sisa kelas di folder lama dipindah, path lama di `docs/`, skill, dan kontrak
      diperbarui, peta kode di [Peta kode ke dokumen](/onboarding/peta-kode#susunan-kode-core)

Folder lama di `apps/core/app` sekarang hanya berisi perekat Laravel: `Providers/`,
`Http/Controllers/Controller.php`, `Http/Middleware/{HandleInertiaRequests,HandleAppearance,ThrottleRequestsPerRoute}`,
dan `Console/Commands/ConfigureLocalCoreCommand.php`. Alasannya tercatat di peta kode.

### Yang tersisa

- [x] **PR 8b:** bongkar `CoreServices` menjadi pendaftaran per fitur. Tujuh fitur Foundation
      (Currency, FinancePosting, FiscalCalendar, NumberSequence, UnitOfMeasure, Vendor, Workflow)
      kini punya `<Fitur>ServiceProvider` di `bootstrap/providers.php`, dengan umur ikatan yang sama
      (`bind`, `singleton` untuk `PostingAccountResolvers`, `scoped` untuk `WorkflowParameters`).
      Ikut dibereskan: docblock `ConvertEnvironment`/`CopyEnvironment` dan `IntegrationClientAccounts`.
      `ALLOWED` turun dari 26 menjadi 12 baris
- [ ] **Pembalikan arah pemasangan dan onboarding:** `InstallModule`, `RegisterAppCatalog`, dan
      `RegisterBusiness` berhenti menyiapkan data Foundation secara langsung (pola kedua pada tabel
      pelanggaran)
- [x] **K-2:** AddressBook dan Geography pindah ke Platform. Pengecualian
      `OrganizationParty -> Party` dan `PrintIdentityStore -> OrganizationAddressBook` hilang;
      `ALLOWED` tinggal 3 baris
- [ ] **Daftar `ALLOWED` kosong.** Selama pekerjaan di atas belum selesai, daftar itu belum bisa
      kosong; ia hanya boleh memendek
- [ ] **CODEOWNERS per lapis** dan **ESLint `no-restricted-imports`** untuk `resources/js`, bagian
      PR 0 yang belum dikerjakan
- [ ] **Pecah berkas raksasa:** `pages/platform/geography/address-setup.tsx`,
      `Platform/Geography/Http/Controllers/AddressSetupController.php`,
      `pages/platform/organization/organization.tsx`, `pages/platform/access/access.tsx`, dan
      `pages/platform/access/security-configuration.tsx`. Ini perubahan isi, jadi PR-nya terpisah
      dari pemindahan
- [ ] **Test yang masih di folder lama:** `tests/Feature/Auth`, `tests/Feature/ControlPlane`,
      `tests/Feature/Database`, dan `tests/Feature/Modules` belum mengikuti
      `tests/Feature/<Lapis>/<Fitur>`. `tests/Feature/Http` menguji perekat
      `ThrottleRequestsPerRoute`, jadi tetap di sana
- [ ] **Sisa nama Indonesia di kode.** Pemindahan sengaja tidak mengganti nama kelas selain facade.
      Yang tersisa, sebagai pekerjaan lanjutan:
      - nama parameter method facade di `App\Platform\Modules\Contracts`, yang ikut terlihat oleh
        module lewat argumen bernama;
      - pembungkus facade di module, misalnya `AssetNumberSequenceIssuer`, `AssetUnitOfMeasureDirectory`, dan
        `HrNumberSequenceIssuer`;
      - kelas Platform dan Foundation, misalnya `ErrorReporter`, `ActiveSpan`,
        `AttachTraceContext`, `RequirePasswordChange`, `PostingStatusChanged`, `ModulesBeingMoved`, dan
        `ModulesWithoutTypeAnalysis`.

      Mengganti nama kelas yang disebut di docs, `phpstan-baseline.neon`, atau test mengikuti aturan
      yang sama dengan pemindahan: satu PR per kelompok, isi kelas tidak berubah

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
