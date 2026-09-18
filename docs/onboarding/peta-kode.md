# Peta kode ke dokumen

Ketemu berkas tapi tidak tahu aturannya, atau baca dokumen tapi tidak tahu kodenya di mana. Tabel ini jembatannya.

Semua path relatif terhadap `apps/core` kecuali disebutkan lain.

## Berdasarkan area

### Module: runtime, kontrak, dan penjaga batas

| Kode | Dokumen |
| --- | --- |
| `modules/` (root repo) — satu folder per module | [Standar module](/dev/02-module-standard), dan `modules/README.md` untuk bentuk foldernya |
| `app/Support/Modules/Contracts/` | [API dan integrasi](/dev/04-api-and-integration) — satu-satunya namespace Core yang boleh disebut module |
| `app/Support/Modules/ModuleRegistry.php`, `ModuleManifest.php` | [Standar module](/dev/02-module-standard) — pembacaan `app.yaml` |
| `app/Support/Modules/ModuleMigrator.php`, `ModuleMigrationRepository.php` | [Development stack lokal](/dev/11-local-docker-development) |
| `app/Support/Modules/TenantScope.php` dan trait `MilikTenant` | [Standar module](/dev/02-module-standard#penyaringan-tenant) |
| `app/Support/Modules/EditionModules.php`, `config/modules.php` | [Release dan on-prem](/dev/03-release-and-on-prem#dua-bentuk-rilis) |
| `tests/Feature/Boundary/` | [Definition of done](/onboarding/definition-of-done) — penjaga batas yang memindai `modules/` |
| `app/Console/Commands/Module*.php`, `RegisterAppManifestCommand.php` | [Mendaftarkan katalog produk](/dev/13-publishing-an-app-release) |
| `resources/js/lib/halaman-module.tsx` | Tuan rumah halaman module di dalam shell |

### Tenant, organisasi, hierarki

| Kode | Dokumen |
| --- | --- |
| `app/Models/Organization.php`, `OrganizationHierarchy.php`, `OrganizationHierarchyNode.php`, `OrganizationHierarchyVersion.php` | [Tenant dan hierarki organisasi](/dev/01a-tenant-and-org-hierarchy) |
| `app/Models/LegalEntity.php`, `OperatingUnit.php`, `HierarchyPurpose.php` | [Tenant dan hierarki organisasi](/dev/01a-tenant-and-org-hierarchy) |
| `app/Actions/Organization/` | [Query scope dan schema](/dev/08-query-scopes-and-schema) |
| `app/Http/Controllers/Organization/` | idem |
| `app/Support/CurrentWorkspace.php` | [Query scope dan schema](/dev/08-query-scopes-and-schema) |

### Identity, akses, security

| Kode | Dokumen |
| --- | --- |
| `app/Models/Role.php`, `RoleAssignment.php`, `Permission.php` | [Identity dan access](/dev/09-identity-and-access) |
| `app/Actions/Access/` — `CreateInvitation`, `UpdateMembership`, `UpsertRole` | idem |
| `app/Http/Controllers/Access/` | idem |
| `app/Support/RoleHierarchy.php` | [Identity dan access](/dev/09-identity-and-access) |
| `app/Support/SodConflictEvaluator.php` | [Identity dan access](/dev/09-identity-and-access) — bagian SoD |
| `app/Support/DataPolicyAccessResolver.php`, `DataPolicyScopeResolver.php` | [Query scope dan schema](/dev/08-query-scopes-and-schema) |
| `app/Models/AppDataPolicy.php` | [Standar module](/dev/02-module-standard) — deklarasi manifest |

### Onboarding dan tenant baru

| Kode | Dokumen |
| --- | --- |
| `app/Actions/Onboarding/RegisterBusiness.php` | [Alur end-to-end](/onboarding/alur-end-to-end) |
| `app/Actions/Onboarding/RedeemInvitation.php` | [Identity dan access](/dev/09-identity-and-access) |
| `app/Http/Controllers/Onboarding/` | idem |

### Katalog app, pemasangan module, deployment

| Kode | Dokumen |
| --- | --- |
| `app/Models/CoreApp.php`, `ModuleInstallation.php` | [Standar module](/dev/02-module-standard) |
| `app/Actions/Modules/InstallModule.php` | [Tiga kebenaran lifecycle](/onboarding/tiga-kebenaran), [Release dan on-prem](/dev/03-release-and-on-prem) |
| `app/Http/Controllers/Provider/AppCatalogController.php` | [Mendaftarkan katalog produk](/dev/13-publishing-an-app-release) |
| `app/Support/LaunchableAppCatalog.php` | [Standar module](/dev/02-module-standard) |
| `app/Http/Controllers/AppLaunchManifestController.php` | idem |
| tabel `core_module_installations`, `tenant_deployments` | [Gate fondasi Core](/dev/10-core-foundation-gates) |

### Reference data platform

| Kode | Dokumen |
| --- | --- |
| `app/Models/NumberSequence*.php`, `app/Actions/NumberSequence/`, `app/Support/NumberSequenceMatrix.php` | [Number sequence](/dev/14-number-sequences) |
| `app/Models/FiscalCalendar.php`, `FiscalYear.php`, `FiscalPeriod.php`, `app/Actions/FiscalCalendar/` | [Kalender fiskal](/dev/15-fiscal-calendars) |
| `app/Services/UnitOfMeasureService.php`, `app/Actions/ReferenceData/` | [Satuan ukur](/dev/16-units-of-measure) |
| `app/Models/CountryRegion.php`, `Party.php`, `PartyLocation.php`, `ElectronicAddress.php` | [Query scope dan schema](/dev/08-query-scopes-and-schema) |

### Workflow

| Kode | Dokumen |
| --- | --- |
| `app/Support/WorkflowRuntime.php`, `app/Http/Controllers/Workflow/` | [Gate penemuan dan keputusan](/dev/18-module-discovery-and-decision-gate) |

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
| "Boleh tidak saya fork Core untuk kebutuhan customer?" | [Kustomisasi dan addon](/dev/05-customization-and-addons) — jawabannya tidak |
| "Bagaimana app saya masuk katalog?" | [Mendaftarkan katalog produk](/dev/13-publishing-an-app-release) |
| "Saya harus bikin app baru atau menambah ke app yang ada?" | [Gate penemuan dan keputusan](/dev/18-module-discovery-and-decision-gate) |
| "Saya ditugaskan ke app X, mulai dari mana?" | [Katalog app](/apps/) lalu hub app-nya |
| "Langkah membangun modul dari nol apa saja?" | [Membangun modul baru](/apps/membangun-app-baru) |
| "Kenapa module saya tidak boleh menyebut kelas Core ini?" | [Standar module](/dev/02-module-standard) — hanya `App\Support\Modules\Contracts` yang boleh disebut |
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
