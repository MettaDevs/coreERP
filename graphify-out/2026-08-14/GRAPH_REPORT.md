# Graph Report - app-erp-management-aset  (2026-08-14)

## Corpus Check
- 201 files · ~110,880 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1363 nodes · 2905 edges · 125 communities (84 shown, 41 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS · INFERRED: 10 edges (avg confidence: 0.74)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `3ba7cf62`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- MasterDataAsetTest
- MasterData
- composer.json
- MasterDataController
- package.json
- master-data.js
- scripts
- MasterPage.tsx
- Load test Management Aset
- compilerOptions
- mint-tenants.mjs
- RequireCoreErpContext.php
- api/README.md
- App ERP Management Aset
- AppServiceProvider
- DatabaseSeeder
- InteractsWithCoreErpContext.php
- ExampleTest
- 2026_07_26_000000_create_master_data_aset_tables.php
- Database
- check-manifest.py
- 0001_01_01_000001_create_cache_table.php
- 0001_01_01_000002_create_jobs_table.php
- 2026_07_23_000000_create_management_aset_tables.php
- 2026_07_24_000000_add_lifecycle_to_m_entitas_aset.php
- 2026_07_27_040000_reverse_group_entitas_aset_relation.php
- server.mjs
- artisan
- migrate.sh
- struktur-fitur.md
- TestCase
- MasterData
- Asset
- MasterParent
- MasterChild
- api.php
- GroupAsetController.php
- ItemChecklistMaintenanceController.php
- KondisiAsetController.php
- PabrikanAsetController.php
- KategoriAsetController
- Illuminate\Database\Eloquent\Relations\BelongsTo
- api
- DepreciationScaleTest
- AssetAttributeTest
- DepreciationBookTest
- AssetPage.tsx
- PlanningPage.tsx
- LokasiAsetController
- BukuPenyusutanController
- TipeAtributController
- TipeLokasiAsetController.php
- bundle.py
- PerencanaanAsetController
- DokumenSiklusAsetController
- PermintaanPengadaanAsetController
- ProfilPenyusutanTest
- UnitOfMeasureClient
- ProvisionIndonesiaStarterData
- AssetLocationTest
- ProfilPenyusutanController
- AssetAttributeValidator
- AssetPlanningTest
- AssetRegisterTest
- NumberSequenceFailureTest
- require-dev
- setup
- DepreciationTest
- ModelAsetTest
- config
- IndonesiaStarterProvisioningTest
- psr-4
- require
- post-create-project-cmd
- extra
- keywords
- autoload-dev
- ModelAsetFormSheet.tsx
- LokasiAsetController
- ModelAsetController
- MaintenanceSetupTest
- MaintenanceChecklistTemplateLines.tsx
- 2026_08_14_110000_create_maintenance_setup_tables.php
- TipeLokasiAsetController
- keywords

## God Nodes (most connected - your core abstractions)
1. `MasterData` - 70 edges
2. `MasterDataController` - 66 edges
3. `MasterDataAsetTest` - 43 edges
4. `api()` - 39 edges
5. `errorMessage()` - 38 edges
6. `AssetAttributeTest` - 35 edges
7. `AssetLifecycleTest` - 33 edges
8. `TestCase` - 32 edges
9. `DepreciationEndToEndTest` - 31 edges
10. `Controller` - 28 edges

## Surprising Connections (you probably didn't know these)
- `AnalisaMaintenance` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/AnalisaMaintenance.php → api/app/Models/MasterData.php
- `ItemChecklistMaintenance` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/ItemChecklistMaintenance.php → api/app/Models/MasterData.php
- `KondisiAset` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/KondisiAset.php → api/app/Models/MasterData.php
- `DynamicField()` --indirect_call--> `optionLabel()`  [INFERRED]
  ui/src/master/DynamicField.tsx → ui/src/master/useMasterOptions.ts
- `ContextController` --inherits--> `Controller`  [EXTRACTED]
  api/app/Http/Controllers/ContextController.php → api/app/Http/Controllers/Controller.php

## Import Cycles
- None detected.

## Communities (125 total, 41 thin omitted)

### Community 2 - "composer.json"
Cohesion: 0.18
Nodes (10): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+2 more)

### Community 3 - "MasterDataController"
Cohesion: 0.20
Nodes (5): JenisAsetDetailController, Builder, PabrikanAsetDetailController, OrganizationScope, Illuminate\Database\Query\Builder

### Community 5 - "package.json"
Cohesion: 0.06
Nodes (32): @apperp/ui, lucide-react, react, react-dom, sonner, tailwindcss, @tailwindcss/vite, @types/react (+24 more)

### Community 6 - "master-data.js"
Cohesion: 0.07
Nodes (48): attributeConstraintRace(), auth(), CHAINED, correctnessThresholds, createMaster(), crossTenantProbe(), crossTenantProbes, fixture (+40 more)

### Community 7 - "scripts"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 8 - "MasterPage.tsx"
Cohesion: 0.13
Nodes (18): setContextToken(), App(), useHashResource(), CONTENT, FixedAssetSetupPlaceholderPage(), SetupKind, DETAIL_LAYOUT_RESOURCES, MASTERS (+10 more)

### Community 9 - "Load test Management Aset"
Cohesion: 0.05
Nodes (37): Aturan scope per data, Bukan batas organisasi saja, bukan tanggung jawab saja, Cara kerja untuk pengguna dan manager, Implementasi saat ini, Keputusan, Keputusan yang masih memerlukan persetujuan owner, Kontrak yang diperlukan dari CoreERP, Rancangan scope data Management Aset (+29 more)

### Community 10 - "compilerOptions"
Cohesion: 0.12
Nodes (15): DOM, DOM.Iterable, ES2022, src, compilerOptions, isolatedModules, jsx, lib (+7 more)

### Community 12 - "RequireCoreErpContext.php"
Cohesion: 0.30
Nodes (4): RequireCoreErpContext, VerifyCoreErpEvent, Closure, Symfony\Component\HttpFoundation\Response

### Community 13 - "api/README.md"
Cohesion: 0.25
Nodes (7): About Laravel, Agentic Development, Code of Conduct, Contributing, Learning Laravel, License, Security Vulnerabilities

### Community 14 - "App ERP Management Aset"
Cohesion: 0.33
Nodes (5): App ERP Management Aset, Batas yang tidak boleh dilanggar, Menambah atau mengubah master, Struktur fitur, Verifikasi sebelum menyatakan selesai

### Community 16 - "DatabaseSeeder"
Cohesion: 0.60
Nodes (3): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder

### Community 18 - "ExampleTest"
Cohesion: 0.15
Nodes (3): DepreciationCalculatorTest, ExampleTest, PHPUnit\Framework\TestCase

### Community 20 - "Database"
Cohesion: 0.25
Nodes (7): Database, Dekomisioning dan event workflow, Dekomisioning dan keputusan workflow, Foreign key lintas tabel app, Klasifikasi aset, Tabel master, Tabel transaksi

### Community 21 - "check-manifest.py"
Cohesion: 0.67
Nodes (3): check_layer(), codes(), Validate app.yaml against the Control Plane catalog contract.  Mirrors apps/cont

### Community 24 - "2026_07_23_000000_create_management_aset_tables.php"
Cohesion: 0.06
Nodes (4): GroupBukuPenyusutanController, JenisAsetAtributController, TipeAtributNilaiController, MasterLinkController

### Community 25 - "2026_07_24_000000_add_lifecycle_to_m_entitas_aset.php"
Cohesion: 0.16
Nodes (19): newIdempotencyKey(), DetailMode, STATUS_ITEMS, FormValue, MasterForm(), allLabel(), emptyMeta, extraSectionFor() (+11 more)

### Community 26 - "2026_07_27_040000_reverse_group_entitas_aset_relation.php"
Cohesion: 0.13
Nodes (4): DepreciationCalculator, DepreciationEndToEndTest, Carbon, Illuminate\Support\Carbon

### Community 50 - "TestCase"
Cohesion: 0.26
Nodes (5): TestCase, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, Illuminate\Testing\TestResponse, Tests\Concerns\InteractsWithCoreErpContext

### Community 51 - "MasterData"
Cohesion: 0.08
Nodes (11): MaintenanceChecklistTemplateController, MaintenanceChecklistVariableController, GroupAset, JenisAset, MaintenanceChecklistTemplate, MaintenanceChecklistVariable, PabrikanAset, ProfilPenyusutan (+3 more)

### Community 52 - "Asset"
Cohesion: 0.16
Nodes (7): AssetController, KelompokHartaFiskal, Asset, AssetBook, Illuminate\Database\Eloquent\Concerns\HasUlids, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\SoftDeletes

### Community 53 - "MasterParent"
Cohesion: 0.12
Nodes (3): MaintenanceJobTypeDefaultController, MasterParent, Illuminate\Validation\Rules\Exists

### Community 59 - "ItemChecklistMaintenanceController.php"
Cohesion: 0.12
Nodes (17): BELUM_TERSEDIA, JenisAsetCounters(), JenisAsetDetail, JenisAsetModelSummary, Choice, JenisAsetMaintenanceJobTypes(), itemOf(), JenisAsetModels() (+9 more)

### Community 62 - "KategoriAsetController"
Cohesion: 0.16
Nodes (20): auth(), finalize(), finalizeLatency, fixture, ids(), options, periodDates(), proposalLatency (+12 more)

### Community 65 - "Illuminate\Database\Eloquent\Relations\BelongsTo"
Cohesion: 0.12
Nodes (5): BukuPenyusutan, LokasiAset, MaintenanceJobTypeDefault, ModelAset, Illuminate\Database\Eloquent\Relations\BelongsTo

### Community 66 - "api"
Cohesion: 0.13
Nodes (26): api(), errorMessage(), MaintenanceChecklistVariableValues(), Value, MasterDetailPage(), Buku, CONVENTIONS, emptyRow() (+18 more)

### Community 67 - "DepreciationScaleTest"
Cohesion: 0.12
Nodes (9): Controller, JenisAsetModelController, WorkflowDecisionController, FiscalCalendarClient, NumberSequenceClient, Throwable, NumberSequenceException, RuntimeException (+1 more)

### Community 70 - "AssetPage.tsx"
Cohesion: 0.12
Nodes (23): AKTIF, DetailSection, KETERANGAN, DynamicField(), wrapHint(), emptyValue(), FieldConfig, FieldOption (+15 more)

### Community 71 - "PlanningPage.tsx"
Cohesion: 0.27
Nodes (8): AssetType, Context, Detail, emptyDetail(), emptyPlan(), Permission, Plan, PlanningPage()

### Community 72 - "LokasiAsetController"
Cohesion: 0.19
Nodes (5): ContextController, HealthController, TenantProvisioningController, DepreciationController, Illuminate\Http\JsonResponse

### Community 76 - "bundle.py"
Cohesion: 0.43
Nodes (5): build(), _Dumper, _load(), main(), Bangun contracts/openapi.yaml dari contracts/src/.  AGENTS.md mewajibkan kontrak

### Community 90 - "UnitOfMeasureClient"
Cohesion: 0.29
Nodes (3): ReferenceDataController, UnitOfMeasureClient, Illuminate\Http\Client\Response

### Community 98 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 99 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install --ignore-scripts, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 102 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 104 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 105 - "require"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 106 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 107 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 108 - "keywords"
Cohesion: 0.17
Nodes (3): MaintenanceJobTypeController, MaintenanceJobType, Illuminate\Database\Eloquent\Relations\HasMany

### Community 117 - "ModelAsetFormSheet.tsx"
Cohesion: 0.39
Nodes (6): ModelAsetFormSheet(), optionLabel(), PabrikanModelRecord, ListMeta, PabrikanModels(), ParentSummary

### Community 121 - "MaintenanceChecklistTemplateLines.tsx"
Cohesion: 0.47
Nodes (5): Line, MaintenanceChecklistTemplateLines(), typeCode(), typeLabel(), types

### Community 122 - "2026_08_14_110000_create_maintenance_setup_tables.php"
Cohesion: 0.70
Nodes (3): addMasterColumns(), createMaster(), up()

### Community 124 - "keywords"
Cohesion: 0.67
Nodes (3): keywords, framework, laravel

## Knowledge Gaps
- **201 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+196 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **41 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `MasterDataController` connect `MasterData` to `Illuminate\Database\Eloquent\Relations\BelongsTo`, `DepreciationScaleTest`, `BukuPenyusutanController`, `TipeAtributController`, `TipeLokasiAsetController.php`, `keywords`, `TipeLokasiAsetController`, `MasterData`, `autoload-dev`, `MasterParent`, `MasterChild`, `LokasiAsetController`, `api.php`, `GroupAsetController.php`, `ModelAsetController`, `ProfilPenyusutanController`, `KondisiAsetController.php`, `PabrikanAsetController.php`?**
  _High betweenness centrality (0.059) - this node is a cross-community bridge._
- **Why does `Controller` connect `DepreciationScaleTest` to `MasterData`, `MasterDataController`, `LokasiAsetController`, `Asset`, `DokumenSiklusAsetController`, `0001_01_01_000002_create_jobs_table.php`, `2026_07_23_000000_create_management_aset_tables.php`, `PerencanaanAsetController`, `UnitOfMeasureClient`, `PermintaanPengadaanAsetController`, `AssetAttributeValidator`?**
  _High betweenness centrality (0.049) - this node is a cross-community bridge._
- **Why does `TestCase` connect `TestCase` to `AssetRegisterTest`, `MasterDataAsetTest`, `NumberSequenceFailureTest`, `AssetAttributeTest`, `DepreciationBookTest`, `DepreciationTest`, `IndonesiaStarterProvisioningTest`, `ModelAsetTest`, `mint-tenants.mjs`, `artisan`, `MaintenanceSetupTest`, `ProfilPenyusutanTest`, `2026_07_27_040000_reverse_group_entitas_aset_relation.php`, `AssetLocationTest`, `AssetPlanningTest`?**
  _High betweenness centrality (0.044) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _201 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `MasterDataAsetTest` be split into smaller, more focused modules?**
  _Cohesion score 0.11666666666666667 - nodes in this community are weakly interconnected._
- **Should `package.json` be split into smaller, more focused modules?**
  _Cohesion score 0.06060606060606061 - nodes in this community are weakly interconnected._
- **Should `master-data.js` be split into smaller, more focused modules?**
  _Cohesion score 0.07215686274509804 - nodes in this community are weakly interconnected._