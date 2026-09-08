# Graph Report - loadtest  (2026-07-30)

## Corpus Check
- 7 files · ~6,125 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 66 nodes · 96 edges · 6 communities (5 shown, 1 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `7acdab38`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- master-data.js
- auth
- mint-tenants.mjs
- Load test Management Aset
- check-manifest.py
- server.mjs

## God Nodes (most connected - your core abstractions)
1. `auth()` - 11 edges
2. `violation()` - 9 edges
3. `Load test Management Aset` - 8 edges
4. `record()` - 7 edges
5. `post()` - 6 edges
6. `crossTenantProbe()` - 5 edges
7. `lifecycleTransaction()` - 5 edges
8. `listMaster()` - 4 edges
9. `showMaster()` - 4 edges
10. `createMaster()` - 4 edges

## Surprising Connections (you probably didn't know these)
- `setup()` --references--> `tenants`  [EXTRACTED]
  k6/master-data.js → mint-tenants.mjs

## Import Cycles
- None detected.

## Communities (6 total, 1 thin omitted)

### Community 0 - "master-data.js"
Cohesion: 0.12
Nodes (15): CHAINED, correctnessThresholds, crossTenantProbes, fixture, idempotencyReplays, KODE_PREFIX, LATENCY_SLO, readLatency (+7 more)

### Community 1 - "auth"
Cohesion: 0.29
Nodes (15): auth(), createMaster(), crossTenantProbe(), idempotencyRace(), lifecycleTransaction(), listMaster(), mutateAsset(), permissionScopeProbe() (+7 more)

### Community 2 - "mint-tenants.mjs"
Cohesion: 0.15
Nodes (14): setup(), ACTIONS, allPermissions, b64url(), contextToken(), encodeCrockford(), MASTERS, narrow (+6 more)

### Community 3 - "Load test Management Aset"
Cohesion: 0.17
Nodes (11): Bentuk stack, Catatan jujur soal batas test ini, Dua profil, dua pertanyaan berbeda, Gate kebenaran — LULUS pada 1000 VU, Gate latensi — LULUS pada 16 concurrent, Hasil terukur, Load test Management Aset, Menjalankan (+3 more)

### Community 4 - "check-manifest.py"
Cohesion: 0.67
Nodes (3): check_layer(), codes(), Validate app.yaml against the Control Plane catalog contract.  Mirrors apps/cont

## Knowledge Gaps
- **34 isolated node(s):** `VUS`, `fixture`, `CHAINED`, `STANDALONE`, `KODE_PREFIX` (+29 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **1 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `setup()` connect `mint-tenants.mjs` to `master-data.js`, `auth`?**
  _High betweenness centrality (0.215) - this node is a cross-community bridge._
- **Why does `auth()` connect `auth` to `master-data.js`, `mint-tenants.mjs`?**
  _High betweenness centrality (0.039) - this node is a cross-community bridge._
- **What connects `VUS`, `fixture`, `CHAINED` to the rest of the system?**
  _34 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `master-data.js` be split into smaller, more focused modules?**
  _Cohesion score 0.11764705882352941 - nodes in this community are weakly interconnected._