# Graph Report - ui  (2026-07-30)

## Corpus Check
- 21 files · ~7,472 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 118 nodes · 204 edges · 8 communities
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `7acdab38`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- api
- App.tsx
- package.json
- MasterPage.tsx
- compilerOptions
- devDependencies
- PlanningPage.tsx

## God Nodes (most connected - your core abstractions)
1. `api()` - 21 edges
2. `errorMessage()` - 19 edges
3. `compilerOptions` - 10 edges
4. `App()` - 7 edges
5. `LifecycleConfig` - 6 edges
6. `permission` - 5 edges
7. `parentSummaryOf()` - 5 edges
8. `PlanningPage()` - 5 edges
9. `MasterForm()` - 4 edges
10. `MasterPage()` - 4 edges

## Surprising Connections (you probably didn't know these)
- `App()` --calls--> `api()`  [EXTRACTED]
  src/App.tsx → src/api.ts
- `App()` --calls--> `errorMessage()`  [EXTRACTED]
  src/App.tsx → src/api.ts
- `App()` --calls--> `permission`  [EXTRACTED]
  src/App.tsx → src/master/masters.ts
- `MasterForm()` --calls--> `api()`  [EXTRACTED]
  src/master/MasterForm.tsx → src/api.ts
- `MasterPage()` --calls--> `api()`  [EXTRACTED]
  src/master/MasterPage.tsx → src/api.ts

## Import Cycles
- None detected.

## Communities (8 total, 0 thin omitted)

### Community 0 - "api"
Cohesion: 0.15
Nodes (19): api(), errorMessage(), Asset, AssetPage(), AssetType, Context, Placement, Book (+11 more)

### Community 1 - "App.tsx"
Cohesion: 0.18
Nodes (12): setContextToken(), App(), useHashResource(), config, config, config, config, config (+4 more)

### Community 2 - "package.json"
Cohesion: 0.11
Nodes (17): @apperp/ui, dependencies, @apperp/ui, react, react-dom, vite, @vitejs/plugin-react, name (+9 more)

### Community 3 - "MasterPage.tsx"
Cohesion: 0.22
Nodes (15): FormValue, MasterForm(), emptyMeta, ListMeta, MasterPage(), MasterAction, MasterConfig, MasterParentConfig (+7 more)

### Community 4 - "compilerOptions"
Cohesion: 0.12
Nodes (15): DOM, DOM.Iterable, ES2022, src, compilerOptions, isolatedModules, jsx, lib (+7 more)

### Community 5 - "devDependencies"
Cohesion: 0.18
Nodes (11): devDependencies, tailwindcss, @tailwindcss/vite, @types/react, @types/react-dom, typescript, tailwindcss, @tailwindcss/vite (+3 more)

### Community 6 - "PlanningPage.tsx"
Cohesion: 0.31
Nodes (8): AssetType, Context, Detail, emptyDetail(), emptyPlan(), Permission, Plan, PlanningPage()

## Knowledge Gaps
- **51 isolated node(s):** `name`, `private`, `type`, `dev`, `build` (+46 more)
  These have ≤1 connection - possible missing edges or undocumented components.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `api()` connect `api` to `App.tsx`, `MasterPage.tsx`, `PlanningPage.tsx`?**
  _High betweenness centrality (0.071) - this node is a cross-community bridge._
- **Why does `errorMessage()` connect `api` to `App.tsx`, `MasterPage.tsx`, `PlanningPage.tsx`?**
  _High betweenness centrality (0.054) - this node is a cross-community bridge._
- **What connects `name`, `private`, `type` to the rest of the system?**
  _51 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `api` be split into smaller, more focused modules?**
  _Cohesion score 0.14666666666666667 - nodes in this community are weakly interconnected._
- **Should `package.json` be split into smaller, more focused modules?**
  _Cohesion score 0.1111111111111111 - nodes in this community are weakly interconnected._
- **Should `compilerOptions` be split into smaller, more focused modules?**
  _Cohesion score 0.125 - nodes in this community are weakly interconnected._