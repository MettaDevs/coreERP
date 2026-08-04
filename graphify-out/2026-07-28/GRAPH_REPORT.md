# Graph Report - app-erp-management-aset  (2026-07-28)

## Corpus Check
- 102 files · ~32,058 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 576 nodes · 964 edges · 59 communities (46 shown, 13 thin omitted)
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
- MasterParent
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
- agent.md
- struktur-fitur.md
- TestCase
- MasterData
- MasterChild
- KategoriAsetController.php
- ItemChecklistMaintenanceController.php
- AssetLocationController
- GroupAsetController.php
- JenisAsetController

## God Nodes (most connected - your core abstractions)
1. `MasterDataController` - 44 edges
2. `MasterDataAsetTest` - 34 edges
3. `MasterData` - 32 edges
4. `api()` - 17 edges
5. `MasterParent` - 15 edges
6. `errorMessage()` - 15 edges
7. `Controller` - 12 edges
8. `TestCase` - 12 edges
9. `AssetController` - 11 edges
10. `Asset` - 11 edges

## Surprising Connections (you probably didn't know these)
- `ItemChecklistMaintenance` --inherits--> `MasterData`  [EXTRACTED]
  api/app/Models/master/ItemChecklistMaintenance.php → api/app/Models/MasterData.php
- `MasterDataController` --inherits--> `Controller`  [EXTRACTED]
  api/app/Http/Controllers/MasterDataController.php → api/app/Http/Controllers/Controller.php
- `AssetLocationController` --inherits--> `MasterDataController`  [EXTRACTED]
  api/app/Http/Controllers/aset/AssetLocationController.php → api/app/Http/Controllers/MasterDataController.php
- `EntitasAsetController` --inherits--> `MasterDataController`  [EXTRACTED]
  api/app/Http/Controllers/master/EntitasAsetController.php → api/app/Http/Controllers/MasterDataController.php
- `GroupAsetController` --inherits--> `MasterDataController`  [EXTRACTED]
  api/app/Http/Controllers/master/GroupAsetController.php → api/app/Http/Controllers/MasterDataController.php

## Import Cycles
- None detected.

## Communities (59 total, 13 thin omitted)

### Community 1 - "MasterData"
Cohesion: 0.13
Nodes (6): AnalisaMaintenanceController, KondisiAsetController, PabrikanAsetController, MasterDataController, Illuminate\Database\Eloquent\Builder, Illuminate\Validation\Rules\Exists

### Community 2 - "composer.json"
Cohesion: 0.05
Nodes (40): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+32 more)

### Community 3 - "MasterDataController"
Cohesion: 0.10
Nodes (12): AssetController, AssetLifecycleDocumentController, DepreciationController, DepreciationProfileController, ContextController, Controller, HealthController, Asset (+4 more)

### Community 4 - "MasterParent"
Cohesion: 0.17
Nodes (3): EntitasAsetController, EntitasAset, MasterParent

### Community 5 - "package.json"
Cohesion: 0.07
Nodes (28): @apperp/ui, react, react-dom, tailwindcss, @tailwindcss/vite, @types/react, @types/react-dom, typescript (+20 more)

### Community 6 - "master-data.js"
Cohesion: 0.11
Nodes (29): auth(), CHAINED, correctnessThresholds, createMaster(), crossTenantProbe(), crossTenantProbes, fixture, idempotencyRace() (+21 more)

### Community 7 - "scripts"
Cohesion: 0.08
Nodes (26): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+18 more)

### Community 8 - "MasterPage.tsx"
Cohesion: 0.10
Nodes (37): api(), errorMessage(), setContextToken(), App(), useHashResource(), Asset, AssetPage(), AssetType (+29 more)

### Community 9 - "Load test Management Aset"
Cohesion: 0.10
Nodes (19): Bentuk stack, Catatan jujur soal batas test ini, Dua profil, dua pertanyaan berbeda, Gate kebenaran — LULUS pada 1000 VU, Gate latensi — LULUS pada 16 concurrent, Hasil terukur, Load test Management Aset, Menjalankan (+11 more)

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
Cohesion: 0.50
Nodes (3): Database, Foreign key lintas tabel app, Tabel master

### Community 21 - "check-manifest.py"
Cohesion: 0.67
Nodes (3): check_layer(), codes(), Validate app.yaml against the Control Plane catalog contract.  Mirrors apps/cont

### Community 31 - "struktur-fitur.md"
Cohesion: 0.50
Nodes (3): Dropdown bertingkat, Dropdown bertingkat, Skill: struktur fitur

### Community 50 - "TestCase"
Cohesion: 0.12
Nodes (9): AssetLocationTest, AssetRegisterTest, DepreciationTest, EntitasAsetTest, TestCase, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, static (+1 more)

### Community 51 - "MasterData"
Cohesion: 0.15
Nodes (9): AssetBook, AnalisaMaintenance, GroupAset, KondisiAset, PabrikanAset, MasterData, Illuminate\Database\Eloquent\Concerns\HasUlids, Illuminate\Database\Eloquent\Model (+1 more)

### Community 52 - "MasterChild"
Cohesion: 0.21
Nodes (4): AssetLocation, JenisAset, MasterChild, Illuminate\Database\Eloquent\Relations\BelongsTo

## Knowledge Gaps
- **144 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+139 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **13 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `MasterDataController` connect `MasterData` to `MasterDataController`, `MasterParent`, `MasterData`, `MasterChild`, `KategoriAsetController.php`, `ItemChecklistMaintenanceController.php`, `AssetLocationController`, `GroupAsetController.php`, `JenisAsetController`?**
  _High betweenness centrality (0.037) - this node is a cross-community bridge._
- **Why does `MasterData` connect `MasterData` to `MasterData`, `MasterDataController`, `MasterParent`, `MasterChild`, `KategoriAsetController.php`, `ItemChecklistMaintenanceController.php`?**
  _High betweenness centrality (0.023) - this node is a cross-community bridge._
- **Why does `Controller` connect `MasterDataController` to `MasterData`?**
  _High betweenness centrality (0.008) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _144 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `MasterDataAsetTest` be split into smaller, more focused modules?**
  _Cohesion score 0.14393939393939395 - nodes in this community are weakly interconnected._
- **Should `MasterData` be split into smaller, more focused modules?**
  _Cohesion score 0.12698412698412698 - nodes in this community are weakly interconnected._
- **Should `composer.json` be split into smaller, more focused modules?**
  _Cohesion score 0.04878048780487805 - nodes in this community are weakly interconnected._