# Peta kode ke dokumen

Ketemu berkas tapi tidak tahu aturannya, atau baca dokumen tapi tidak tahu kodenya di mana. Tabel ini jembatannya.

Semua path relatif terhadap `apps/core` kecuali disebutkan lain.

## Susunan kode Core

Kode Core tidak dikelompokkan per jenis teknis (`Models/`, `Support/`, `Http/Controllers/`), tetapi per
**lapis**, lalu per **fitur** di dalam lapis itu. Polanya meniru Business Central: System Application,
lalu Business Foundation, lalu Base App. Rencana dan alasannya ada di
[Memecah Core menjadi lapis Platform dan Foundation](/todo/lapis-core/).

| Lapis | Namespace | Isinya |
| --- | --- | --- |
| Platform | `App\Platform\<Fitur>` | Yang dibutuhkan setiap aplikasi bisnis sebelum ada satu pun transaksi: tenant, lingkungan, identitas, akses, organisasi, buku alamat, wilayah, runtime module, laporan, log perubahan, lampiran, retensi, observability, integrasi, lisensi |
| Foundation | `App\Foundation\<Fitur>` | Data acuan bisnis yang dipakai lintas module: satuan ukur, mata uang, nomor urut, kalender fiskal dan kerja, vendor, workflow, posting finance |
| Module | `modules/<vendor>/<module>` (root repo) | Aplikasi bisnis. Hanya boleh menyebut facade `App\Platform\Modules\Contracts` |

Daftar fitur yang berlaku adalah isi folder `app/Platform/` dan `app/Foundation/` itu sendiri.

### Isi satu fitur

```
app/<Lapis>/<Fitur>/
  Models/                       model Eloquent
  Actions/                      satu tindakan bisnis per kelas
  Http/Controllers/             controller layar dan API
  Http/Controllers/Internal/    controller rute internal/v1
  Http/Requests/                form request
  Http/Middleware/              middleware milik fitur
  Console/                      perintah artisan
  Jobs/                         job antrean
  Support/                      semua kelas lain
  ModuleServices/               pelaksana facade module (`…Core`)
  <Fitur>ServiceProvider.php    ikatan milik fitur Foundation, didaftarkan di bootstrap/providers.php
```

Folder yang tidak dibutuhkan sebuah fitur memang tidak ada. Sub-folder yang bermakna dipertahankan,
misalnya `Platform/Geography/Models/AddressHierarchy/`.

Facade module tidak ikut pola per fitur: semuanya tinggal di satu namespace,
`App\Platform\Modules\Contracts`, sedangkan pelaksananya di `ModuleServices` fitur pemiliknya.

Halaman Inertia dan test mengikuti susunan yang sama:

| Jenis | Letak | Contoh |
| --- | --- | --- |
| Halaman Inertia | `resources/js/pages/<lapis>/<fitur>/` dengan huruf kecil dan tanda hubung | `pages/foundation/unit-of-measure/units-of-measure.tsx`, dirender `Inertia::render('foundation/unit-of-measure/units-of-measure')` |
| Test feature | `tests/Feature/<Lapis>/<Fitur>/` | `tests/Feature/Foundation/Vendor/` |
| Test unit | `tests/Unit/<Lapis>/<Fitur>/` | `tests/Unit/Platform/Reporting/` |

### Yang tetap di folder bawaan Laravel

Beberapa kelas adalah perekat framework, bukan milik fitur mana pun, sehingga tetap di tempat
bawaannya:

| Berkas | Alasan |
| --- | --- |
| `app/Providers/*` | Service provider yang didaftarkan `bootstrap/providers.php` |
| `app/Http/Controllers/Controller.php` | Controller dasar yang diturunkan semua controller |
| `app/Http/Middleware/HandleInertiaRequests.php`, `HandleAppearance.php`, `ThrottleRequestsPerRoute.php` | Middleware global dan alias `throttle` yang dipasang `bootstrap/app.php` untuk semua rute |
| `app/Console/Commands/ConfigureLocalCoreCommand.php` | `core:configure-local` hanya menulis `.env` mesin pengembang (kunci aplikasi, sandi database lokal, akun provider); tidak ada fitur yang memilikinya |

Kelas baru tidak masuk ke folder ini. Kalau ragu fitur mana pemiliknya, tanyakan dulu.

### Jebakan `php artisan make:*`

Perintah `make:model`, `make:controller`, `make:request`, `make:command`, dan sejenisnya membuat berkas
di folder bawaan Laravel (`app/Models`, `app/Http/Controllers`, `app/Console/Commands`, …). Folder itu
bukan tempatnya lagi. Sebut namespace lengkap:

```bash
php artisan make:model "App\Foundation\Vendor\Models\Vendor"
php artisan make:controller "App\Foundation\Vendor\Http\Controllers\VendorController"
```

atau pindahkan berkasnya ke fitur pemiliknya sesudah dibuat. Penjaga batas tetap membaca `app/Models`
bila folder itu muncul lagi, jadi model yang salah tempat tidak lolos diam-diam, tetapi ia tetap salah
tempat.

Perintah artisan baru ditemukan otomatis dari `app/<Lapis>/<Fitur>/Console/`: `bootstrap/app.php`
mendaftarkan folder itu lewat `withCommands`. Tidak perlu mendaftarkannya di tempat lain.

### Penjaga arah lapis

Arah ketergantungannya satu: module ke Foundation lewat facade, Foundation ke Platform. Platform tidak
boleh memakai Foundation, dan `Platform\ControlPlane` hanya boleh dipakai dari `Platform\ControlPlane`
(kecuali penanda `OwnedByControlPlane`).

Yang menjaganya `tests/Feature/Boundary/LayerDirectionBoundaryTest.php`. Ia membaca teks berkas, jadi
`use`, pemanggilan statis, dan nama kelas di dalam string sama-sama tertangkap. Pelanggaran yang sudah
ada sebelum pemindahan dicatat di konstanta `ALLOWED`, dan **daftar itu hanya boleh memendek**:

- Pelanggaran baru di luar `ALLOWED` membuat test merah. Perbaiki arahnya, jangan menambah entri.
- Entri `ALLOWED` yang tidak lagi ditemukan di kode juga membuat test merah, supaya pengecualian yang
  sudah diperbaiki ikut dihapus dari daftar.
- Setiap entri harus tercatat di tabel pelanggaran pada [rencana lapis Core](/todo/lapis-core/#pelanggaran-arah-yang-sudah-ada).

Batas module ke Core dijaga terpisah oleh `ModuleNamespaceBoundaryTest`.

## Berdasarkan area

### Module: runtime, kontrak, dan penjaga batas

| Kode | Dokumen |
| --- | --- |
| `modules/` (root repo) — satu folder per module | [Standar module](/dev/02-module-standard), dan `modules/README.md` untuk bentuk foldernya |
| `app/Platform/Modules/Contracts/` | [API dan integrasi](/dev/04-api-and-integration) — satu-satunya namespace Core yang boleh disebut module |
| `app/Platform/Modules/Support/ModuleRegistry.php`, `ModuleManifest.php`, `ModuleManifestFiles.php` | [Standar module](/dev/02-module-standard) — pembacaan `app.yaml` dan penggabungannya dengan folder `manifest/` |
| `app/Platform/Modules/Support/ModuleMigrator.php`, `ModuleMigrationRepository.php` | [Development stack lokal](/dev/11-local-docker-development) |
| `app/Platform/Modules/Support/TenantScope.php` dan trait `BelongsToTenant` | [Standar module](/dev/02-module-standard#penyaringan-tenant) |
| `app/Platform/Modules/Support/EditionModules.php`, `config/modules.php` | [Release dan on-prem](/dev/03-release-and-on-prem#dua-bentuk-rilis) |
| `tests/Feature/Boundary/` | [Definition of done](/onboarding/definition-of-done) — penjaga batas yang memindai `modules/` dan arah lapis Core |
| `app/Platform/Modules/Console/Module*.php`, `RegisterAppManifestCommand.php` | [Mendaftarkan katalog produk](/dev/13-publishing-an-app-release) |
| `resources/js/lib/halaman-module.tsx` | Tuan rumah halaman module di dalam shell |

### Tenant, organisasi, hierarki

| Kode | Dokumen |
| --- | --- |
| `app/Platform/Organization/Models/Organization.php`, `OrganizationHierarchy.php`, `OrganizationHierarchyNode.php`, `OrganizationHierarchyVersion.php` | [Tenant dan hierarki organisasi](/dev/01a-tenant-and-org-hierarchy) |
| `app/Platform/Organization/Models/LegalEntity.php`, `OperatingUnit.php`, `HierarchyPurpose.php` | [Tenant dan hierarki organisasi](/dev/01a-tenant-and-org-hierarchy) |
| `app/Platform/Organization/Actions/` | [Query scope dan schema](/dev/08-query-scopes-and-schema) |
| `app/Platform/Organization/Http/Controllers/` | idem |
| `app/Platform/Environment/Support/CurrentWorkspace.php` | [Query scope dan schema](/dev/08-query-scopes-and-schema) |

### Identity, akses, security

| Kode | Dokumen |
| --- | --- |
| `app/Platform/Access/Models/Role.php`, `RoleAssignment.php`, `Permission.php` | [Identity dan access](/dev/09-identity-and-access) |
| `app/Platform/Access/Actions/` — `CreateInvitation`, `UpdateMembership`, `UpsertRole` | idem |
| `app/Platform/Access/Http/Controllers/` | idem |
| `app/Platform/Access/Support/RoleHierarchy.php` | [Identity dan access](/dev/09-identity-and-access) |
| `app/Platform/Access/Support/SodConflictEvaluator.php` | [Identity dan access](/dev/09-identity-and-access) — bagian SoD |
| `app/Platform/Access/Support/DataPolicyAccessResolver.php`, `DataPolicyScopeResolver.php` | [Query scope dan schema](/dev/08-query-scopes-and-schema) |
| `app/Platform/Access/Models/AppDataPolicy.php` | [Standar module](/dev/02-module-standard) — deklarasi manifest |
| `app/Platform/Identity/` | Login, SSO, passkey, profil. SSO dijelaskan di [SSO](/dev/32-sso) |

### Onboarding dan tenant baru

| Kode | Dokumen |
| --- | --- |
| `app/Platform/Tenant/Actions/RegisterBusiness.php` | [Alur end-to-end](/onboarding/alur-end-to-end) |
| `app/Platform/Tenant/Actions/RedeemInvitation.php` | [Identity dan access](/dev/09-identity-and-access) |
| `app/Platform/Tenant/Http/Controllers/` | idem |

### Katalog app, pemasangan module, deployment

| Kode | Dokumen |
| --- | --- |
| `app/Platform/Modules/Models/CoreApp.php`, `ModuleInstallation.php` | [Standar module](/dev/02-module-standard) |
| `app/Platform/Modules/Actions/InstallModule.php` | [Tiga kebenaran lifecycle](/onboarding/tiga-kebenaran), [Release dan on-prem](/dev/03-release-and-on-prem) |
| `app/Platform/Modules/Http/Controllers/AppCatalogController.php` | [Mendaftarkan katalog produk](/dev/13-publishing-an-app-release) |
| `app/Platform/Modules/Support/LaunchableAppCatalog.php` | [Standar module](/dev/02-module-standard) |
| `app/Platform/Modules/Http/Controllers/AppLaunchManifestController.php` | idem |
| tabel `core_module_installations`, `tenant_deployments` | [Gate fondasi Core](/dev/10-core-foundation-gates) |

### Reference data platform

| Kode | Dokumen |
| --- | --- |
| `app/Foundation/NumberSequence/` | [Number sequence](/dev/14-number-sequences) |
| `app/Foundation/FiscalCalendar/` | [Kalender fiskal](/dev/15-fiscal-calendars) |
| `app/Foundation/UnitOfMeasure/` | [Satuan ukur](/dev/16-units-of-measure) |
| `app/Platform/Geography/Models/CountryRegion.php`, `app/Platform/AddressBook/Models/Party.php`, `PartyLocation.php`, `ElectronicAddress.php` | [Query scope dan schema](/dev/08-query-scopes-and-schema), [Buku alamat](/dev/24-global-address-book) |

### Workflow

| Kode | Dokumen |
| --- | --- |
| `app/Foundation/Workflow/Support/WorkflowRuntime.php`, `app/Foundation/Workflow/Http/Controllers/` | [Visual workflow engine](/dev/21-visual-workflow-engine), [Gate penemuan dan keputusan](/dev/18-module-discovery-and-decision-gate) |

### Frontend

| Kode | Dokumen |
| --- | --- |
| `resources/js/pages/`, `components/`, `layouts/` | `.agents/skills/coreerp-ui/SKILL.md`, `.agents/skills/coreerp-page-standard/SKILL.md` |
| `packages/ui/` (root repo) | SDK UI bersama `@apperp/ui` |
| `apps/core/resources/js/components/product-launcher.tsx` | Launcher aplikasi di header shell |
| `apps/core/resources/js/lib/halaman-module.tsx` | Tuan rumah halaman module; halaman module ikut build shell |
| `apps/provider-console/` (root repo) | Konsol vendor |

## Berdasarkan pertanyaan

| Kalau kamu bertanya… | Buka |
| --- | --- |
| "Boleh tidak saya join tabel module lain, kan satu database?" | [API dan integration bridge](/dev/04-api-and-integration) — jawabannya tetap tidak |
| "Kolom scope apa yang harus saya pakai?" | [Query scope dan schema](/dev/08-query-scopes-and-schema) |
| "Perlu tidak fitur ini punya nomor?" | [Number sequence](/dev/14-number-sequences), [Gate penemuan](/dev/18-module-discovery-and-decision-gate) |
| "Kenapa app saya tidak muncul sebagai terpasang?" | [Tiga kebenaran lifecycle](/onboarding/tiga-kebenaran) |
| "Boleh tidak saya fork Core untuk kebutuhan customer?" | [Kebutuhan khusus pelanggan](/dev/05-customization-and-addons) — jawabannya tidak |
| "Bagaimana app saya masuk katalog?" | [Mendaftarkan katalog produk](/dev/13-publishing-an-app-release) |
| "Saya harus bikin app baru atau menambah ke app yang ada?" | [Gate penemuan dan keputusan](/dev/18-module-discovery-and-decision-gate) |
| "Saya ditugaskan ke app X, mulai dari mana?" | [Katalog app](/apps/) lalu hub app-nya |
| "Langkah membangun modul dari nol apa saja?" | [Membangun modul baru](/apps/membangun-app-baru) |
| "Kenapa module saya tidak boleh menyebut kelas Core ini?" | [Standar module](/dev/02-module-standard) — hanya `App\Platform\Modules\Contracts` yang boleh disebut |
| "Kelas baru Core ini saya taruh di mana?" | [Susunan kode Core](#susunan-kode-core) di halaman ini |
| "Hak akses apa saja yang harus saya rancang untuk satu transaksi?" | [Rantai keamanan modul transaksi](/dev/19-transaction-security-chain) |
| "Fondasi ini belum ada, boleh saya bikin?" | [Gate fondasi Core](/dev/10-core-foundation-gates) |
| "Kapan module saya boleh disebut selesai?" | [Definition of done](/onboarding/definition-of-done) |

## Aturan yang tidak ada di `docs/`

Sebagian aturan hidup di luar folder ini dan tetap mengikat:

| Berkas | Isinya |
| --- | --- |
| `AGENTS.md` (root) | Aturan main harian: boundary module, bahasa UI, verifikasi runtime, larangan hardcode nama |
| `.agents/skills/coreerp-architecture/SKILL.md` | Penjaga boundary arsitektur, termasuk data policy decision gate |
| `.agents/skills/coreerp-ui/SKILL.md` | Aturan UI, termasuk `portalContainer` untuk dropdown di dalam overlay |
| `.agents/skills/module-discovery/SKILL.md` | Prosedur dan template proposal gate penemuan |
| `.agents/skills/number-sequence-design/SKILL.md` | Desain reference nomor |

## Lihat juga

- [Alur end-to-end](/onboarding/alur-end-to-end) — cara membaca kode ini dalam satu alur utuh
- [Indeks desain kanonik](/dev/)
