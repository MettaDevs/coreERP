# Graph Report - app-erp-management-aset  (2026-07-30)

## Corpus Check
- 119 files · ~39,462 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 676 nodes · 1195 edges · 65 communities (51 shown, 14 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS · INFERRED: 1 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `7acdab38`
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

## God Nodes (most connected - your core abstractions)
1. `MasterDataController` - 44 edges
2. `MasterDataAsetTest` - 34 edges
3. `MasterData` - 32 edges
4. `api()` - 21 edges
5. `errorMessage()` - 19 edges
6. `PerencanaanAsetController` - 16 edges
7. `Controller` - 15 edges
8. `MasterParent` - 15 edges
9. `TestCase` - 14 edges
10. `NumberSequenceClient` - 13 edges

## Surprising Connections (you probably didn't know these)
- `AnalisaMaintenance` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/AnalisaMaintenance.php → api/app/Models/MasterData.php
- `GroupAset` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/GroupAset.php → api/app/Models/MasterData.php
- `ItemChecklistMaintenance` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/ItemChecklistMaintenance.php → api/app/Models/MasterData.php
- `KondisiAset` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/KondisiAset.php → api/app/Models/MasterData.php
- `PabrikanAset` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/PabrikanAset.php → api/app/Models/MasterData.php

## Import Cycles
- None detected.

## Communities (65 total, 14 thin omitted)

### Community 2 - "composer.json"
Cohesion: 0.05
Nodes (40): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+32 more)

### Community 3 - "MasterDataController"
Cohesion: 0.08
Nodes (14): ContextController, Controller, ReferenceDataController, DokumenSiklusAsetController, DepreciationController, DepreciationProfileController, PerencanaanAsetController, DepreciationProfile (+6 more)

### Community 5 - "package.json"
Cohesion: 0.07
Nodes (28): @apperp/ui, react, react-dom, tailwindcss, @tailwindcss/vite, @types/react, @types/react-dom, typescript (+20 more)

### Community 6 - "master-data.js"
Cohesion: 0.11
Nodes (32): auth(), CHAINED, correctnessThresholds, createMaster(), crossTenantProbe(), crossTenantProbes, fixture, idempotencyRace() (+24 more)

### Community 7 - "scripts"
Cohesion: 0.08
Nodes (26): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+18 more)

### Community 8 - "MasterPage.tsx"
Cohesion: 0.06
Nodes (54): api(), errorMessage(), setContextToken(), App(), useHashResource(), FormValue, MasterForm(), emptyMeta (+46 more)

### Community 9 - "Load test Management Aset"
Cohesion: 0.06
Nodes (29): Aturan scope per data, Bukan batas organisasi saja, bukan tanggung jawab saja, Cara kerja untuk pengguna dan manager, Implementasi saat ini, Keputusan, Keputusan yang masih memerlukan persetujuan owner, Kontrak yang diperlukan dari CoreERP, Rancangan scope data Management Aset (+21 more)

### Community 10 - "compilerOptions"
Cohesion: 0.12
Nodes (15): DOM, DOM.Iterable, ES2022, src, compilerOptions, isolatedModules, jsx, lib (+7 more)

### Community 11 - "mint-tenants.mjs"
Cohesion: 0.18
Nodes (12): ACTIONS, allPermissions, b64url(), contextToken(), encodeCrockford(), MASTERS, narrow, narrowId (+4 more)

### Community 12 - "RequireCoreErpContext.php"
Cohesion: 0.36
Nodes (3): RequireCoreErpContext, Closure, Symfony\Component\HttpFoundation\Response

### Community 13 - "api/README.md"
Cohesion: 0.25
Nodes (7): About Laravel, Agentic Development, Code of Conduct, Contributing, Learning Laravel, License, Security Vulnerabilities

### Community 14 - "App ERP Management Aset"
Cohesion: 0.33
Nodes (5): App ERP Management Aset, Batas yang tidak boleh dilanggar, Menambah atau mengubah master, Struktur fitur, Verifikasi sebelum menyatakan selesai

### Community 16 - "DatabaseSeeder"
Cohesion: 0.60
Nodes (3): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder

### Community 20 - "Database"
Cohesion: 0.40
Nodes (4): Database, Foreign key lintas tabel app, Tabel master, Tabel transaksi

### Community 21 - "check-manifest.py"
Cohesion: 0.67
Nodes (3): check_layer(), codes(), Validate app.yaml against the Control Plane catalog contract.  Mirrors apps/cont

### Community 31 - "struktur-fitur.md"
Cohesion: 0.50
Nodes (3): Dropdown bertingkat, Dropdown bertingkat, Skill: struktur fitur

### Community 50 - "TestCase"
Cohesion: 0.09
Nodes (10): AssetLocationTest, AssetPlanningTest, AssetRegisterTest, DepreciationTest, EntitasAsetTest, TestCase, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase (+2 more)

### Community 51 - "MasterData"
Cohesion: 0.18
Nodes (6): EntitasAset, JenisAset, KategoriAset, MasterData, AssetLocation, Illuminate\Database\Eloquent\Relations\BelongsTo

### Community 52 - "Asset"
Cohesion: 0.23
Nodes (6): AssetController, Asset, AssetBook, Illuminate\Database\Eloquent\Concerns\HasUlids, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\SoftDeletes

### Community 53 - "MasterParent"
Cohesion: 0.12
Nodes (4): EntitasAsetController, AssetLocationController, MasterParent, Illuminate\Validation\Rules\Exists

### Community 56 - "api.php"
Cohesion: 0.20
Nodes (3): HealthController, AnalisaMaintenanceController, AnalisaMaintenance

## Knowledge Gaps
- **162 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+157 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **14 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `MasterDataController` connect `MasterData` to `MasterDataController`, `MasterData`, `MasterParent`, `MasterChild`, `api.php`, `GroupAsetController.php`, `ItemChecklistMaintenanceController.php`, `KondisiAsetController.php`, `PabrikanAsetController.php`, `KategoriAsetController`?**
  _High betweenness centrality (0.032) - this node is a cross-community bridge._
- **Why does `MasterData` connect `MasterData` to `MasterData`, `MasterDataController`, `Asset`, `MasterParent`, `MasterChild`, `api.php`, `GroupAsetController.php`, `ItemChecklistMaintenanceController.php`, `KondisiAsetController.php`, `PabrikanAsetController.php`?**
  _High betweenness centrality (0.020) - this node is a cross-community bridge._
- **Why does `Controller` connect `MasterDataController` to `api.php`, `MasterData`, `Asset`?**
  _High betweenness centrality (0.009) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _162 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `MasterDataAsetTest` be split into smaller, more focused modules?**
  _Cohesion score 0.14393939393939395 - nodes in this community are weakly interconnected._
- **Should `composer.json` be split into smaller, more focused modules?**
  _Cohesion score 0.04878048780487805 - nodes in this community are weakly interconnected._
- **Should `MasterDataController` be split into smaller, more focused modules?**
  _Cohesion score 0.08450704225352113 - nodes in this community are weakly interconnected._