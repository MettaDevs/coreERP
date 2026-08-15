# Graph Report - app-erp-management-aset  (2026-08-07)

## Corpus Check
- 152 files · ~69,673 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1000 nodes · 1917 edges · 86 communities (63 shown, 23 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS · INFERRED: 5 edges (avg confidence: 0.74)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `52b724f6`
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
- 2026_07_23_000000_create_management_aset_tables.php
- 2026_07_24_000000_add_lifecycle_to_m_entitas_aset.php
- 2026_07_27_040000_reverse_group_entitas_aset_relation.php
- server.mjs
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
- AssetAttributeValidator

## God Nodes (most connected - your core abstractions)
1. `MasterDataController` - 53 edges
2. `MasterData` - 46 edges
3. `MasterDataAsetTest` - 39 edges
4. `DepreciationEndToEndTest` - 31 edges
5. `TestCase` - 24 edges
6. `MasterLinkController` - 22 edges
7. `Controller` - 20 edges
8. `DepreciationScaleTest` - 20 edges
9. `api()` - 20 edges
10. `AssetAttributeTest` - 19 edges

## Surprising Connections (you probably didn't know these)
- `AnalisaMaintenance` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/AnalisaMaintenance.php → api/app/Models/MasterData.php
- `ItemChecklistMaintenance` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/ItemChecklistMaintenance.php → api/app/Models/MasterData.php
- `JenisAset` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/JenisAset.php → api/app/Models/MasterData.php
- `KondisiAset` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/KondisiAset.php → api/app/Models/MasterData.php
- `PabrikanAset` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/PabrikanAset.php → api/app/Models/MasterData.php

## Import Cycles
- None detected.

## Communities (86 total, 23 thin omitted)

### Community 2 - "composer.json"
Cohesion: 0.05
Nodes (40): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+32 more)

### Community 3 - "MasterDataController"
Cohesion: 0.07
Nodes (18): ContextController, Controller, HealthController, JenisAsetAtributDefinisiController, ReferenceDataController, WorkflowDecisionController, DokumenSiklusAsetController, DepreciationController (+10 more)

### Community 5 - "package.json"
Cohesion: 0.06
Nodes (30): @apperp/ui, react, react-dom, sonner, tailwindcss, @tailwindcss/vite, @types/react, @types/react-dom (+22 more)

### Community 6 - "master-data.js"
Cohesion: 0.11
Nodes (33): auth(), CHAINED, correctnessThresholds, createMaster(), crossTenantProbe(), crossTenantProbes, fixture, idempotencyRace() (+25 more)

### Community 7 - "scripts"
Cohesion: 0.08
Nodes (26): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+18 more)

### Community 8 - "MasterPage.tsx"
Cohesion: 0.14
Nodes (16): setContextToken(), App(), useHashResource(), CONTENT, FixedAssetSetupPlaceholderPage(), SetupKind, config, config (+8 more)

### Community 9 - "Load test Management Aset"
Cohesion: 0.05
Nodes (34): Aturan scope per data, Bukan batas organisasi saja, bukan tanggung jawab saja, Cara kerja untuk pengguna dan manager, Implementasi saat ini, Keputusan, Keputusan yang masih memerlukan persetujuan owner, Kontrak yang diperlukan dari CoreERP, Rancangan scope data Management Aset (+26 more)

### Community 10 - "compilerOptions"
Cohesion: 0.12
Nodes (15): DOM, DOM.Iterable, ES2022, src, compilerOptions, isolatedModules, jsx, lib (+7 more)

### Community 11 - "mint-tenants.mjs"
Cohesion: 0.18
Nodes (12): ACTIONS, allPermissions, b64url(), contextToken(), encodeCrockford(), MASTERS, narrow, narrowId (+4 more)

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
Cohesion: 0.10
Nodes (6): DepreciationCalculator, DepreciationCalculatorTest, ExampleTest, Carbon, Illuminate\Support\Carbon, PHPUnit\Framework\TestCase

### Community 20 - "Database"
Cohesion: 0.25
Nodes (7): Database, Dekomisioning dan event workflow, Dekomisioning dan keputusan workflow, Foreign key lintas tabel app, Klasifikasi aset, Tabel master, Tabel transaksi

### Community 21 - "check-manifest.py"
Cohesion: 0.67
Nodes (3): check_layer(), codes(), Validate app.yaml against the Control Plane catalog contract.  Mirrors apps/cont

### Community 24 - "2026_07_23_000000_create_management_aset_tables.php"
Cohesion: 0.07
Nodes (4): GroupBukuPenyusutanController, JenisAsetAtributController, TipeAtributNilaiController, MasterLinkController

### Community 25 - "2026_07_24_000000_add_lifecycle_to_m_entitas_aset.php"
Cohesion: 0.14
Nodes (24): emptyValue(), FieldConfig, FieldOption, FieldValue, isVisible(), payloadValue(), valueFrom(), FormValue (+16 more)

### Community 50 - "TestCase"
Cohesion: 0.07
Nodes (12): AssetLocationTest, AssetPlanningTest, AssetRegisterTest, DepreciationTest, ModelAsetTest, ProfilPenyusutanTest, TestCase, Illuminate\Foundation\Testing\RefreshDatabase (+4 more)

### Community 51 - "MasterData"
Cohesion: 0.14
Nodes (5): ProfilPenyusutanController, GroupAset, ProfilPenyusutan, TipeAtribut, MasterData

### Community 52 - "Asset"
Cohesion: 0.21
Nodes (6): AssetController, Asset, AssetBook, Illuminate\Database\Eloquent\Concerns\HasUlids, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\SoftDeletes

### Community 53 - "MasterParent"
Cohesion: 0.20
Nodes (3): MasterChild, MasterParent, Illuminate\Validation\Rules\Exists

### Community 62 - "KategoriAsetController"
Cohesion: 0.16
Nodes (20): auth(), finalize(), finalizeLatency, fixture, ids(), options, periodDates(), proposalLatency (+12 more)

### Community 65 - "Illuminate\Database\Eloquent\Relations\BelongsTo"
Cohesion: 0.13
Nodes (5): ModelAsetController, BukuPenyusutan, LokasiAset, ModelAset, Illuminate\Database\Eloquent\Relations\BelongsTo

### Community 66 - "api"
Cohesion: 0.21
Nodes (14): api(), errorMessage(), Buku, CONVENTIONS, emptyRow(), GroupBookMatrix(), Row, Book (+6 more)

### Community 70 - "AssetPage.tsx"
Cohesion: 0.29
Nodes (8): Asset, AssetPage(), AssetType, Context, Placement, AttributeDefinition, numeric(), toFieldConfig()

### Community 71 - "PlanningPage.tsx"
Cohesion: 0.27
Nodes (8): AssetType, Context, Detail, emptyDetail(), emptyPlan(), Permission, Plan, PlanningPage()

### Community 76 - "bundle.py"
Cohesion: 0.43
Nodes (5): build(), _Dumper, _load(), main(), Bangun contracts/openapi.yaml dari contracts/src/.  AGENTS.md mewajibkan kontrak

## Knowledge Gaps
- **179 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+174 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **23 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `MasterDataController` connect `MasterData` to `Illuminate\Database\Eloquent\Relations\BelongsTo`, `MasterDataController`, `LokasiAsetController`, `BukuPenyusutanController`, `TipeAtributController`, `TipeLokasiAsetController.php`, `MasterData`, `MasterParent`, `MasterChild`, `api.php`, `GroupAsetController.php`, `ItemChecklistMaintenanceController.php`, `KondisiAsetController.php`, `PabrikanAsetController.php`?**
  _High betweenness centrality (0.050) - this node is a cross-community bridge._
- **Why does `Controller` connect `MasterDataController` to `2026_07_23_000000_create_management_aset_tables.php`, `MasterData`, `Asset`?**
  _High betweenness centrality (0.045) - this node is a cross-community bridge._
- **Why does `MasterData` connect `MasterData` to `Illuminate\Database\Eloquent\Relations\BelongsTo`, `MasterData`, `LokasiAsetController`, `BukuPenyusutanController`, `TipeAtributController`, `TipeLokasiAsetController.php`, `Asset`, `MasterParent`, `MasterChild`, `api.php`, `GroupAsetController.php`, `ItemChecklistMaintenanceController.php`, `KondisiAsetController.php`, `PabrikanAsetController.php`?**
  _High betweenness centrality (0.033) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _179 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `MasterDataAsetTest` be split into smaller, more focused modules?**
  _Cohesion score 0.13015873015873017 - nodes in this community are weakly interconnected._
- **Should `composer.json` be split into smaller, more focused modules?**
  _Cohesion score 0.04878048780487805 - nodes in this community are weakly interconnected._
- **Should `MasterDataController` be split into smaller, more focused modules?**
  _Cohesion score 0.06880076445293837 - nodes in this community are weakly interconnected._