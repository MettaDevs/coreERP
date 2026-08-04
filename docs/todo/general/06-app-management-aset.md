# App Management Aset terhadap D365 Asset Management dan Fixed assets

> Dihasilkan dari audit 7 dimensi terhadap Dynamics 365 F&O. Setiap temuan sudah
> melewati satu putaran verifikasi lawan yang membuka file aslinya; temuan yang
> ditolak verifier tidak ikut ditulis di sini.

**Total: 43 temuan** — Blocker: 15 · Tinggi: 18 · Sedang: 9 · Rendah: 1

Legenda status: `[ ]` belum · `[~]` sedang dikerjakan · `[x]` selesai + ada bukti test.

## Blocker

### [ ] `ASSET-01` — There is no asset record at all — the app has classification masters but no asset

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, fixed-assets, procurement, project-accounting

- **Dynamics 365:** D365 Asset Management's root entity is the asset, carrying asset type, asset model, manufacturer, serial number, criticality, lifecycle state, functional location, parent asset, warranty dates, vendor and service-vendor links, and an optional fixed asset link. Everything in the module hangs off it.
- **Keadaan sekarang:** The database has exactly 8 tables, all with the identical shape id/tenant_id/creation_key/kode/nama/keterangan/aktif/deleted_at/timestamps: m_entitas_aset, m_group_aset, m_kategori_aset, m_jenis_aset, m_kondisi_aset, m_pabrikan_aset, m_item_checklist_maintenance, m_analisa_maintenance. None is an asset; they are all lookup lists. All three migrations and every model in api/app/Models were read — no asset instance table exists.
- **Bukti:** D:/Kerja/app-erp-management-aset/database/migrations/2026_07_26_000000_create_master_data_aset_tables.php:18-24 (the complete table list); D:/Kerja/app-erp-management-aset/database/README.md:9-20; D:/Kerja/app-erp-management-aset/README.md:9-18
- **Kenapa penting:** An asset management app with no asset cannot register, locate, maintain, cost, depreciate, or report on anything. Every other capability in D365 Asset Management and Fixed assets is downstream of this one table, which is why the app sits at roughly 2% of the baseline despite the quality of what exists.
- **Perubahan yang diperlukan:** Owner: this app. Add `t_aset` (asset instance) with tenant_id, legal_entity_id, org_unit_id, kode from a new number sequence reference, jenis_aset_id, kondisi_aset_id, pabrikan_aset_id, serial/model, acquisition date, criticality, lifecycle state, parent asset id (self-reference within tenant), functional location id, and opaque references to Core organization and later to a fixed asset. Add CRUD endpoints, permissions, duty, number sequence reference in app.yaml, and OpenAPI paths.

### [ ] `ASSET-02` — `entitas aset` re-implements Core's legal entity as a local master with its own number

**Jenis:** wrong-model · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset, fixed-assets, finance-gl

- **Dynamics 365:** In D365 F&O the asset-owning company is the legal entity, owned by the organization model and never re-created inside Asset Management. Asset Management references it; it does not define it.
- **Keadaan sekarang:** m_entitas_aset is a tenant-scoped master whose own UI describes it as "Kelola perusahaan atau entitas yang menjadi pemilik aset" — the asset-owning company. It has its own number sequence reference `management-aset.entitas-aset`, its own permissions and duty, and is the permanent root of the app's foreign key chain. Meanwhile Core already owns `organizations` with classification legal_entity and operating_unit, and the context token already carries legal_entity_id and org_unit_id.
- **Bukti:** D:/Kerja/app-erp-management-aset/ui/src/masters.ts:53-60; D:/Kerja/app-erp-management-aset/app.yaml:206-209; D:/Kerja/app-erp-management-aset/database/migrations/2026_07_26_000000_create_master_data_aset_tables.php:14-18; apps/control-plane/app/Support/AppContextToken.php:29-30; apps/control-plane/app/Support/CurrentWorkspace.php:76-84
- **Kenapa penting:** docs/dev/02-module-standard.md:90 states that organization IDs are opaque references to the Organization service, not app-owned identities. Two competing registries of "which company owns this" will diverge the moment a tenant adds a legal entity in Core, and asset cost will not aggregate to the same company the ledger uses. The app's README argues correctly that a classification chain may hold a permanent parent_id, but that argument does not extend to the chain's root being a company.
- **Perubahan yang diperlukan:** Owner: this app, with a contract from Core. Either drop m_entitas_aset and root the chain on Core's legal_entity_id carried as an opaque column, or keep it strictly as a non-company asset-portfolio grouping and relabel it so it no longer means "perusahaan". Owner: Core must expose a read endpoint listing the tenant's legal entities and operating units so the app can display names without cross-database queries.
- **Koreksi verifier:** m_entitas_aset does not duplicate legal-entity data (it holds only kode/nama/keterangan/aktif), but its user-facing meaning is declared as 'the company that owns the asset' while its README declares it a pure classification root. That ambiguity sits at the permanent FK root of every future asset record and must be resolved before the asset table ships: either relabel it as a non-company asset-portfolio grouping, or bind it to Core's legal_entity_id as an opaque reference. Core must also expose a read contract for the tenant's legal entities/operating units so names can be shown without cross-database queries.

### [ ] `ASSET-06` — No maintenance job type at all, and therefore no asset-type-to-job-type permitted matrix

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset

- **Dynamics 365:** D365 Asset Management defines maintenance job types, job type categories, job type variants, and trades; each asset type declares which maintenance job types are permitted on it, which is what makes work order job lines validatable.
- **Keadaan sekarang:** The nearest thing is m_jenis_aset (asset type), a leaf of the classification chain with only kode/nama/keterangan/aktif. There is no maintenance job type, job type category, variant, trade, or asset-type-to-job-type relation table.
- **Bukti:** D:/Kerja/app-erp-management-aset/database/migrations/2026_07_26_000000_create_master_data_aset_tables.php:20; D:/Kerja/app-erp-management-aset/app.yaml:29-31
- **Kenapa penting:** Maintenance job type is the second-most-central entity after the asset. Without it there is nothing for a maintenance plan to schedule, nothing for a work order job line to reference, and no way to reject an invalid job on an asset type. It is also the natural attachment point for the checklist the app already has.
- **Perubahan yang diperlukan:** Owner: this app. Add `m_jenis_pekerjaan_maintenance` with category, default trade, default duration, and preventive/corrective/condition-based classification; add the many-to-many permitted-combination table against m_jenis_aset; add variants if tenants need them. Add matching permissions, duties, and number sequence references.

### [ ] `ASSET-08` — No maintenance plan, maintenance round, or schedule line — nothing generates preventive work

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset

- **Dynamics 365:** D365 Asset Management has maintenance plans (time-based with an interval, counter-based with a counter and interval quantity), maintenance rounds covering many assets, maintenance schedule lines produced by the plan, and a scheduling calculation turning lines into maintenance schedule proposals and then work orders.
- **Keadaan sekarang:** No plan, round, schedule line, proposal, or calculation exists. api/routes/console.php contains only Laravel's stock `inspire` command, so there is no scheduled job that could run a calculation even if the tables existed.
- **Bukti:** D:/Kerja/app-erp-management-aset/api/routes/console.php:1-9; D:/Kerja/app-erp-management-aset/database/migrations/2026_07_26_000000_create_master_data_aset_tables.php:18-24
- **Kenapa penting:** Preventive maintenance is the module's commercial reason to exist; corrective-only maintenance can be run on a spreadsheet. Without plans there is no forward workload, no backlog, and no basis for a maintenance budget or forecast.
- **Perubahan yang diperlukan:** Owner: this app. Add maintenance plan (time-based and counter-based), maintenance round with asset lines, maintenance schedule line, and maintenance schedule proposal, plus an idempotent tenant-aware job that calculates proposals. Owner: Core must provide a tenant-scoped recurring batch job framework (ASSET-35) and a working-time calendar (ASSET-34) before a due date can be computed correctly.

### [ ] `ASSET-11` — No asset spare parts, asset BOM, or link to an item master

**Jenis:** missing-app · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, procurement, inventory

- **Dynamics 365:** D365 Asset Management links assets to spare parts through the product/item master and an asset BOM, so a work order job can reserve and consume inventory and a purchase order can be raised for a missing part.
- **Keadaan sekarang:** No spare part, BOM, or item reference exists in the app. There is no inventory app anywhere in the platform: modules/, addons/, and integrations/ contain only README.md, and the only other app artifact is the procurement hello-world stub.
- **Bukti:** D:/Kerja/app-erp-management-aset/database/migrations/ (no BOM or item table); apps/control-plane/contracts/apps/procurement.yaml:6-25; modules/README.md, addons/README.md, integrations/README.md are the only files in those directories
- **Kenapa penting:** Maintenance cost is dominated by parts. With no item master there is nothing to consume, nothing to cost, and nothing to purchase, which also removes the integration point PROCUREMENT is meant to provide. Item master is not this app's data — inventing a local one would repeat the ASSET-02 mistake.
- **Perubahan yang diperlukan:** Owner: a new inventory app (item master, unit usage, on-hand, reservation, issue). Owner: this app for the asset-BOM/spare-part linkage table holding opaque item IDs, calling inventory's REST contract to reserve and consume. Owner: Core for unit of measure.

### [ ] `ASSET-14` — No maintenance request — the entry point for corrective maintenance is absent

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset

- **Dynamics 365:** D365 Asset Management has a maintenance request with request type, source, asset and functional location, description, service level/priority, downtime start/end, and a state model that can be converted into a work order.
- **Keadaan sekarang:** No maintenance request table, endpoint, permission, or number sequence reference. All 32 permissions and all 8 number sequence references in app.yaml are master-data only.
- **Bukti:** D:/Kerja/app-erp-management-aset/app.yaml:49-146; D:/Kerja/app-erp-management-aset/app.yaml:204-237; D:/Kerja/app-erp-management-aset/api/routes/api.php:16-25
- **Kenapa penting:** The maintenance request is where real users enter the system — a machine broke and someone reported it. Without it there is no intake, no downtime capture, and no funnel into work orders, so the app has no operational end user today. It also needs approval and photos, neither of which Core can yet provide.
- **Perubahan yang diperlukan:** Owner: this app. Add maintenance request with type, source, asset, functional location, priority, downtime start/end, and an explicit server-enforced state model, plus its number sequence reference and permissions. Owner: Core for workflow approval (ASSET-30) and attachments (ASSET-31).

### [ ] `ASSET-15` — No work order — no header, job lines, type, pool, or lifecycle states

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, procurement, project-accounting, fixed-assets

- **Dynamics 365:** D365's work order has a work order type, a work order pool, job lines (each with asset, maintenance job type, variant, trade, expected duration, and its own lifecycle state), a header lifecycle state model with mandatory-transition rules, and a link to a project for cost.
- **Keadaan sekarang:** Nothing. The app exposes health, context, and five CRUD routes for each of eight masters, and no transactional endpoint of any kind.
- **Bukti:** D:/Kerja/app-erp-management-aset/api/routes/api.php:27-41 (the complete route table); D:/Kerja/app-erp-management-aset/contracts/openapi.yaml:21-985 (all documented paths are master CRUD)
- **Kenapa penting:** The work order is the transaction the app exists to produce. Its absence means no maintenance execution, no actual cost, no history, and nothing for procurement, inventory, project accounting, or fixed assets to integrate with. It is the highest-value next increment and simultaneously the one with the deepest Core dependencies.
- **Perubahan yang diperlukan:** Owner: this app. Add work order type, work order pool, work order header (with legal_entity_id and org_unit_id), job lines, and a lifecycle state model with server-enforced transitions and a transition log; add number sequence references including a legal-entity-scoped work order number, plus permissions and duties. Owner: Core for the prerequisites in ASSET-30, ASSET-31, ASSET-32, ASSET-33, ASSET-34, and ASSET-35.

### [ ] `ASSET-17` — No actual posting: no hours, item consumption, or expenses, and no project or cost integration

**Jenis:** missing-core-capability · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset, finance-gl, project-accounting

- **Dynamics 365:** In D365 every work order is backed by a project; hours, item consumption, and expenses post as project journals, producing work order cost control, maintenance budget versus actual, and the GL posting with financial dimensions.
- **Keadaan sekarang:** No hour journal, item journal, expense journal, cost table, budget, forecast, or project reference. There is also no currency and no financial dimension anywhere in the platform to post with.
- **Bukti:** D:/Kerja/app-erp-management-aset/database/migrations/ (no transactional table at all); no currency, exchange rate, or financial dimension table in apps/control-plane/database/migrations/
- **Kenapa penting:** Cost is why finance funds an EAM purchase. Without actual posting there is no cost per asset, no budget variance, and no chargeback, and the app cannot answer the question every plant manager asks: what did this machine cost us this year.
- **Perubahan yang diperlukan:** Owner: a new project-accounting app and a finance/general-ledger app as separate release units. Owner: Core for currency plus exchange rate and for financial dimensions as shared metadata. Owner: this app for the work order journals and for publishing a `management-aset.work-order-completed.v1` event carrying hours, items, and expenses for the finance apps to consume.
- **Koreksi verifier:** Correct as a gap, but the kind is misassigned: this is missing-app, not missing-core-capability. Work-order journals belong here; the project-accounting and general-ledger apps that turn them into cost and postings do not exist as repos, and Core lacks the currency and financial-dimension metadata both would need (ASSET-32). The app's own obligation is limited to the journals plus a work-order-completed event.

### [ ] `ASSET-20` — No fixed asset link, acquisition from receipt, depreciation, transfer, or disposal

**Jenis:** missing-app · **Verifikasi:** CONFIRMED · **Memblokir:** fixed-assets, finance-gl, management-aset

- **Dynamics 365:** D365 F&O Fixed assets (Finance) owns the fixed asset book, acquisition including automatic acquisition from a purchase product receipt, depreciation profiles and runs, revaluation, transfer, disposal by sale or scrap, and write-off. D365 Asset Management links an asset to its fixed asset record so maintenance and accounting agree on the same physical thing.
- **Keadaan sekarang:** None of it exists, in this app or anywhere in the platform: no GL, no depreciation, no acquisition, no disposal, no fixed asset table. Core has fiscal calendars and periods (which depreciation would need) but nothing to post into them.
- **Bukti:** apps/control-plane/database/migrations/2026_07_26_010000_create_fiscal_calendar_tables.php:11-44; no fixed asset, depreciation, or ledger table in apps/control-plane/database/migrations/ or in D:/Kerja/app-erp-management-aset/database/migrations/
- **Kenapa penting:** Half of the audit baseline is entirely absent and, correctly, does not belong in this app. Deciding that now matters: if maintenance ships a depreciation field to satisfy one customer, the platform acquires an accounting sub-ledger inside a maintenance app with no GL behind it.
- **Perubahan yang diperlukan:** Owner: a new fixed-assets app depending on a finance/general-ledger app, owning book, depreciation profile and run, acquisition, transfer, disposal, and write-off, all legal-entity-scoped. Owner: this app for a single opaque fixed_asset_id on its asset table plus asset-acquired and asset-disposed events so the two registers reconcile without cross-database queries. Owner: Core for currency and financial dimensions.

### [ ] `ASSET-22` — The API never validates entitlement, installation readiness, or organization scope

**Jenis:** contract-gap · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, procurement

- **Dynamics 365:** Not a D365 concept in this form — this is CoreERP's own stated standard for app APIs.
- **Keadaan sekarang:** RequireCoreErpContext validates the HMAC signature, alg, iss, aud, exp, iat, a ULID tenant_id, and that permissions is a list of strings — then stops. It does not check entitlement, installation readiness, or organization scope, and it cannot: Core's entire internal API is four number-sequence endpoints. The entitlement plus readiness gate lives only in Core's launcher query, which decides whether the iframe renders at all.
- **Bukti:** D:/Kerja/app-erp-management-aset/api/app/Http/Middleware/RequireCoreErpContext.php:44-58; apps/control-plane/routes/api.php:6-11 (the complete internal API surface); apps/control-plane/app/Support/LaunchableAppCatalog.php:30-51; docs/dev/02-module-standard.md:99
- **Kenapa penting:** The app's API is reachable independently of the shell. A token minted while an entitlement was active stays valid for 300 seconds after it lapses, and anyone holding a valid token can call the API for a tenant whose placement has been disabled, because the readiness check is on the page-render path rather than the data path. The stated standard is unmet in the only app that implements it, and app #2 will copy the same middleware.
- **Perubahan yang diperlukan:** Owner: Core. Add internal endpoints (or short-lived signed claims in the token) for entitlement status, installation readiness, and the caller's authorized organization scope set, and publish them as a small versioned SDK package so every app enforces them identically instead of copy-pasting HMAC code. Owner: this app — call them in the middleware and add tests for the lapsed-entitlement and not-ready cases.

### [ ] `ASSET-27` — No CI anywhere in the app repo

**Jenis:** ops-gap · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, procurement

- **Dynamics 365:** Not applicable — CoreERP's own requirement.
- **Keadaan sekarang:** No .github, no .gitlab-ci.yml, and no pipeline file of any kind exists in D:/Kerja/app-erp-management-aset or in D:/Kerja/app-erp-template. Tests exist and are runnable but nothing runs them automatically; there is no lint, no static analysis, no OpenAPI/AsyncAPI validation, no contract compatibility check, and no image build producing a digest.
- **Bukti:** Full file listing of D:/Kerja/app-erp-management-aset and D:/Kerja/app-erp-template shows no CI directory or file; docs/dev/02-module-standard.md:105; docs/dev/10-core-foundation-gates.md:40
- **Kenapa penting:** Core's own gate document makes a CI-produced immutable image digest a precondition for production deployment, so the absence of CI blocks production for this app by Core's own rule. It also means the good tests that exist can silently rot and the OpenAPI file can drift from the routes unnoticed.
- **Perubahan yang diperlukan:** Owner: this app and the template, so app #2 inherits it — add a pipeline running composer install and the PHPUnit suite, tsc --noEmit plus the vite build, OpenAPI and AsyncAPI lint, and a build publishing API and UI images by digest. Owner: Core — define where digests are recorded so app_releases and app_placements can reference them.

### [ ] `ASSET-28` — The deploy fragment has no database and no DB_* environment, so the production API would fall back to SQLite; migrate.sh is missing

**Jenis:** ops-gap · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset

- **Dynamics 365:** Not applicable — CoreERP's own release standard.
- **Keadaan sekarang:** deploy/compose.fragment.yaml defines only management-aset-api and management-aset-ui with COREERP_* environment, no DB service, and no DB_CONNECTION/DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD. api/config/database.php:20 defaults to sqlite and api/.env.example ships DB_CONNECTION=sqlite. The template does provision postgres with DB_* env and does have deploy/migrate.sh; the app repo has neither. app.yaml also declares logicalName app_erp_management_aset while docs/dev/02-module-standard.md:88 specifies the `core_app_<app>` pattern.
- **Bukti:** D:/Kerja/app-erp-management-aset/deploy/compose.fragment.yaml (whole file: two services, no DB, no DB_* env); D:/Kerja/app-erp-management-aset/api/config/database.php:20; D:/Kerja/app-erp-template/deploy/compose.yaml:2-14 and D:/Kerja/app-erp-template/deploy/migrate.sh; D:/Kerja/app-erp-management-aset/app.yaml:44-46; docs/dev/02-module-standard.md:26-28,88
- **Kenapa penting:** Deployed as written, the app runs on a container-local SQLite file — data lost on redeploy, no pooled multi-tenant database, and composite foreign keys behaving differently from Postgres. The Dockerfile installs pdo_pgsql so the intent is clearly Postgres; the fragment never says so. The missing migrate.sh means the placement's migration_status has no script to run, yet LaunchableAppCatalog gates launch on migration_status = succeeded.
- **Perubahan yang diperlukan:** Owner: this app — add the app-owned Postgres service with the correct database name, DB_* environment backed by a secret reference, and deploy/migrate.sh running `php artisan migrate --force`. Owner: Core — validate at catalog registration that a release's deploy fragment declares a database and a migrate entry point before a placement may reach `placed`.
- **Koreksi verifier:** The gap is real and worse than described, but the failure mechanism is mis-stated: the API would not quietly fall back to SQLite in production, because deployment never gets that far. Core's own worker requires a database_service that the fragment does not declare and executes /coreerp/migrate.sh, which this app's Dockerfile never copies into the image — so DeployAppPlacement fails at the migration stage and the placement is marked failed. The correct fix set is: add the Postgres service and DB_* env (secret-referenced) to the fragment, add deploy/migrate.sh, add the COPY line to api/Dockerfile, and rename the database to core_app_management_aset. Core should validate at release registration that the declared database_service actually exists in the referenced compose file.

### [ ] `ASSET-30` — Core has no workflow or approval engine, and no plan for one

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, procurement, fixed-assets

- **Dynamics 365:** D365 F&O has a platform-wide workflow engine: configurable approval hierarchies, task assignment, delegation, escalation, work item queues, and a per-document workflow state, used by maintenance requests, work orders, purchase requisitions, and fixed asset disposals alike.
- **Keadaan sekarang:** No workflow, approval, or work-item table exists in Core. docs/dev/10-core-foundation-gates.md mentions approval only as a precondition of two identity features (temporary access, SoD) at lines 14-15; the gate matrix contains no workflow row, so this is not merely unstarted but unplanned.
- **Bukti:** No workflow/approval/work-item table in the 24 files under apps/control-plane/database/migrations/; docs/dev/10-core-foundation-gates.md:7-20
- **Kenapa penting:** Maintenance requests, work order release, cost overruns, and asset disposal all require approval in every real deployment, and Procurement (app #2) cannot exist at all without requisition approval. If each app builds its own approval, the platform ends up with N incompatible engines and no single work-item inbox for a manager — the most expensive kind of duplication to unwind later.
- **Perubahan yang diperlukan:** Owner: Core. Add a workflow foundation: versioned workflow definition, approval step with an assignment rule that can target a position once workforce exists, work item with state and audit, delegation, escalation, an internal API for apps to submit a document and subscribe to decisions, and a shared work-item inbox in the shell. Add it to the gate matrix in docs/dev/10 with its own gate condition.

### [ ] `ASSET-32` — Core has no unit of measure, currency, or financial dimensions

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, inventory, procurement, finance-gl, fixed-assets, project-accounting

- **Dynamics 365:** D365 F&O owns unit of measure with conversion classes, currency with exchange rate types and rate tables, and financial dimensions with dimension sets — all shared foundation data consumed by every module including Asset Management.
- **Keadaan sekarang:** None of the three exists in Core. All 24 Core migrations were searched for unit_of_measure, currency, and financial_dimension: no hits. Nothing in the app either.
- **Bukti:** apps/control-plane/database/migrations/ — 24 files, none defining a unit, currency, or dimension table; apps/control-plane/config/coreerp.php contains no currency or unit configuration
- **Kenapa penting:** Counter readings need a unit (hours, km, cycles). Spare part consumption needs a unit and a conversion. Every cost figure needs a currency, and every posting needs financial dimensions to be reportable by cost centre or site. All three are unambiguously shared: if Asset Management invents units and Inventory invents units, a spare part cannot be consumed on a work order without a translation layer — exactly the shared-domain-model coupling docs/dev/02-module-standard.md:105 forbids.
- **Perubahan yang diperlukan:** Owner: Core. Add unit of measure plus conversion, currency plus exchange rate with rate type and effective dating, and financial dimensions plus dimension sets, each with an internal read API and change events. These are Core master data, not an app's. Add all three to the gate matrix in docs/dev/10.

### [ ] `ASSET-33` — Core has no workforce (worker, position, trade) — confirmed still unstarted, and it now blocks app #1

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, workforce, project-accounting

- **Dynamics 365:** D365 F&O owns worker, job, and position in HR; Asset Management assigns work order jobs to workers, groups them by trade and competency, uses them for capacity scheduling, and names a person responsible for an asset.
- **Keadaan sekarang:** docs/dev/10-core-foundation-gates.md:12 lists workforce/job/position as "Belum", gated on an HRD module existing with a stable event contract. Verified: no worker, position, or job table in Core, and no worker or assignment table in the app. The app therefore has no way to record who is responsible for an asset or who performs a work order job.
- **Bukti:** docs/dev/10-core-foundation-gates.md:12; no worker/position/job table in apps/control-plane/database/migrations/; D:/Kerja/app-erp-management-aset/database/migrations/ (no worker or assignment table)
- **Kenapa penting:** The gate's own condition makes workforce wait for an app that does not exist, but app #1's next increment needs it now for custodian, technician assignment, and capacity. docs/dev/10-core-foundation-gates.md:13 also makes automatic role assignment depend on position, so this one gap holds back both maintenance execution and identity automation. The concrete risk is that Asset Management ships a free-text technician name and that becomes the de facto worker master.
- **Perubahan yang diperlukan:** Owner: Core, to decide the seam explicitly rather than defer again: either ship a minimal Core workforce identity (worker linked to a user, position, trade/competency, validity dates) that a future HR app extends, or state in writing that Asset Management may hold only an opaque worker_id plus a display name cached from an event, and name who emits that event. Update the gate row either way.

## Tinggi

### [ ] `ASSET-03` — No table carries legal_entity_id or org_unit_id even though the token supplies both

**Jenis:** wrong-model · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset, fixed-assets, finance-gl, project-accounting

- **Dynamics 365:** In D365 F&O every asset, work order, maintenance request, and fixed asset transaction is company-scoped; operational ownership additionally resolves to an operating unit, site, or functional location.
- **Keadaan sekarang:** RequireCoreErpContext extracts `coreerp.legal_entity_id` and `coreerp.org_unit_id` into request attributes, and nothing in the codebase ever reads them again — those two lines are the only occurrences in the repo. No migration defines either column. MasterDataController::tenantQuery filters on tenant_id alone.
- **Bukti:** D:/Kerja/app-erp-management-aset/api/app/Http/Middleware/RequireCoreErpContext.php:20-21; D:/Kerja/app-erp-management-aset/api/app/Http/Controllers/MasterDataController.php:176-181; all three files in D:/Kerja/app-erp-management-aset/database/migrations/ contain no legal_entity_id or org_unit_id column
- **Kenapa penting:** docs/dev/02-module-standard.md:90 requires legal_entity_id on data with legal or accounting consequence and org_unit_id on operational data owned by an operating unit. Master lookups arguably need neither, but assets, work orders, cost postings, and disposals all do. Retrofitting these columns onto a populated asset register and work order history later has no correct backfill, so they must exist before the first transactional table ships.
- **Perubahan yang diperlukan:** Owner: this app. Put legal_entity_id on asset, work order, maintenance request, cost, and disposal tables and org_unit_id on functional location, work order, and counter reading tables, all as indexed opaque ULID references populated from the context token and never from the request body. Owner: Core must guarantee the token always carries a legal entity when the workspace has one, and must define app behaviour when it is null.
- **Koreksi verifier:** This is not a defect in the eight existing master tables; it is a prerequisite for the first transactional tables (asset, work order, maintenance request, cost, disposal). The real blocking item is on Core: AppContextToken.php:29-30 emits only the single selected legal entity and operating unit, never the authorized set, and no contract defines app behaviour when legal_entity_id is null. Core must specify that before the app can populate the columns correctly.

### [ ] `ASSET-04` — No functional location, and no home for one anywhere in the platform

**Jenis:** missing-core-capability · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset

- **Dynamics 365:** D365 Asset Management functional locations are a hierarchical location structure with their own type, lifecycle model and lifecycle states, attributes, and address; assets are installed into and relocated between them with full history, and cost can be tracked on the location rather than the asset.
- **Keadaan sekarang:** No functional location table, no install/relocate history, no location hierarchy — the app has no location concept at all. Core has `organizations` with classification operating_unit and versioned purpose-scoped hierarchy nodes (purpose `establishment` is seeded) but no postal address table and no physical-location concept.
- **Bukti:** D:/Kerja/app-erp-management-aset/database/migrations/2026_07_26_000000_create_master_data_aset_tables.php:18-24; apps/control-plane/database/migrations/2026_07_20_010000_add_organization_structure_and_closure.php:82-90; no address or party table exists in apps/control-plane/database/migrations/
- **Kenapa penting:** Functional location is how maintenance work is actually organised in the field — 'the pump at line 3', not 'pump serial 44821'. Without it there is no relocation history, no location-level cost roll-up, and no way to keep maintenance history when an asset is swapped out. It also has no obvious owner today: too physical for Core's organization model, too shared to invent twice.
- **Perubahan yang diperlukan:** Owner: this app for the functional location table, its type, its lifecycle states, and an install/relocate history (asset_installations with valid_from/valid_to). Owner: Core for a party/postal-address service the location can reference, and for a read contract exposing operating units so a location can be tied to an org unit without cross-database queries.
- **Koreksi verifier:** Functional location and install/relocate history are entirely absent, and Core has no postal-address or party service to anchor them. This is real but not build-blocking: an asset register and work orders can ship first, and functional location plus asset_installations is an additive increment. It becomes blocking for the field-maintenance workflow and for location-level cost roll-up.

### [ ] `ASSET-05` — No asset lifecycle model or lifecycle state — and the migration named for it adds neither

**Jenis:** doc-code-drift · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset

- **Dynamics 365:** D365 Asset Management requires every asset type to have an asset lifecycle model made of ordered lifecycle states, each controlling whether the asset may appear on a work order, be scheduled, or be reported on. Functional locations have their own separate lifecycle models.
- **Keadaan sekarang:** The migration file is named `2026_07_24_000000_add_lifecycle_to_m_entitas_aset.php` but its up() adds only `creation_key`, `softDeletes()`, and a unique(tenant_id, creation_key). There is no state column, no lifecycle model table, no transition table, and no state machine anywhere in api/app/. The only status-like column in the app is the `aktif` boolean.
- **Bukti:** D:/Kerja/app-erp-management-aset/database/migrations/2026_07_24_000000_add_lifecycle_to_m_entitas_aset.php:11-15 (the entire up() body); D:/Kerja/app-erp-management-aset/database/migrations/2026_07_23_000000_create_management_aset_tables.php:17
- **Kenapa penting:** A misnamed migration is a false claim of capability that anyone reading the file list will believe. Substantively, an `aktif` boolean cannot express the D365 model: commissioned-but-not-handed-over and decommissioned-but-not-disposed are different states permitting different operations. D365 drives work order eligibility entirely from lifecycle state, so no work order design is possible until states exist.
- **Perubahan yang diperlukan:** Owner: this app. Rename or supersede the migration so the filename tells the truth, then add `m_model_lifecycle_aset` and `m_state_lifecycle_aset` (ordered, with maintainable/schedulable/reportable flags), a state column and transition log on the asset table, and a separate lifecycle model set for functional locations. Enforce transitions server-side, not in the UI.
- **Koreksi verifier:** Both halves are real: the migration filename is a false claim (it adds idempotency and soft delete, not lifecycle), and no asset lifecycle model or state machine exists. Severity is high rather than blocker because lifecycle states are designed together with the asset table (ASSET-01) rather than being independently blocked; the misnamed migration itself is a low-severity truthfulness defect that should be corrected in the same pass.

### [ ] `ASSET-07` — "Item checklist maintenance" is a flat name list, not a checklist

**Jenis:** wrong-model · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset

- **Dynamics 365:** A D365 maintenance checklist is a header attached to a maintenance job type, containing ordered checklist lines with a line type (text, measurement, yes/no), an expected value or tolerance, and a required flag; execution results are recorded on the work order job with technician and timestamp.
- **Keadaan sekarang:** m_item_checklist_maintenance is created by the same generic createMaster() helper as every other master: kode, nama, keterangan, aktif. No parent, no ordering, no line type, no expected value, no link to a job type, and no result-recording table.
- **Bukti:** D:/Kerja/app-erp-management-aset/database/migrations/2026_07_26_000000_create_master_data_aset_tables.php:23,52-78; D:/Kerja/app-erp-management-aset/ui/src/masters.ts:110-118
- **Kenapa penting:** A list of inspection item names is a vocabulary, not a checklist. There is no way to say which items apply to which job, in what order, or what a passing reading is, and no result is ever stored. Recorded results are the evidence base for MTBF, condition assessment, and warranty claims, so this quietly removes the app's entire measurement layer.
- **Perubahan yang diperlukan:** Owner: this app. Keep m_item_checklist_maintenance as the item vocabulary, then add `m_checklist_maintenance` (header attached to a maintenance job type) with ordered lines referencing the item, plus a transactional result table recording value, unit of measure, worker, and timestamp against a work order job.

### [ ] `ASSET-09` — No asset counters or counter readings

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset

- **Dynamics 365:** D365 Asset Management has counter types, counters registered per asset (absolute or incremental, with a unit), counter value registrations recorded manually or from IoT, and counter-based maintenance plans that trigger on interval quantity.
- **Keadaan sekarang:** No counter table, counter type, or reading table. There is also no unit of measure anywhere in the platform to express a counter unit such as hours, km, or cycles.
- **Bukti:** D:/Kerja/app-erp-management-aset/database/migrations/ (all three files, no counter table); no unit_of_measure table in apps/control-plane/database/migrations/
- **Kenapa penting:** Counter-based maintenance is how mobile plant, generators, and vehicles are actually maintained, and it is the input to MTBF and availability. It cannot be built correctly without a unit of measure, so this gap is partly blocked on Core.
- **Perubahan yang diperlukan:** Owner: this app for counter type, asset counter, and counter reading (with a unique key per asset+counter+timestamp so retries cannot double-count). Owner: Core for unit of measure and conversion (ASSET-32).

### [ ] `ASSET-12` — No asset warranty, vendor or service-vendor links, and no ownership/custodian model

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, procurement

- **Dynamics 365:** D365 Asset Management records warranty start/end or warranty terms per asset, links the asset to a vendor, manufacturer, and service vendor, and separates who owns the asset from who is responsible for it.
- **Keadaan sekarang:** m_pabrikan_aset (manufacturer) exists as a standalone lookup that nothing references — its controller declares no parent and no child masters, and no asset table exists to point at it. There is no vendor reference, no warranty column or table, no owner, and no custodian.
- **Bukti:** D:/Kerja/app-erp-management-aset/database/migrations/2026_07_26_000000_create_master_data_aset_tables.php:22; D:/Kerja/app-erp-management-aset/api/app/Http/Controllers/PabrikanAsetController.php; D:/Kerja/app-erp-management-aset/ui/src/masters.ts:92-100
- **Kenapa penting:** Warranty determines who pays for a repair; custodian determines who is accountable day to day and who signs a handover — both are routinely audited. A manufacturer lookup with nothing referencing it is a concrete symptom of ASSET-01: the masters were built before the entity they describe.
- **Perubahan yang diperlukan:** Owner: this app for warranty (start, end, terms, claim log) and for manufacturer/vendor/service-vendor reference columns on the asset. Owner: Procurement or a vendor master app for the vendor identity referenced opaquely. Owner: Core for workforce, so custodian points at a real worker rather than free text (ASSET-33).

### [ ] `ASSET-13` — No asset criticality, and no asset hierarchy (parent/child, sub-assets)

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset

- **Dynamics 365:** D365 Asset Management supports parent/child asset structures (an asset installed as a sub-asset of another) and asset criticality on the asset or via functional location, used to prioritise work orders and weight scheduling.
- **Keadaan sekarang:** Neither exists. The only hierarchy in the app is the classification chain entitas -> group -> kategori -> jenis, which classifies asset kinds rather than asset instances. m_kondisi_aset (condition) is a separate flat lookup and is not criticality.
- **Bukti:** D:/Kerja/app-erp-management-aset/database/README.md:9-20 (the complete FK map — every FK is inside the classification chain); D:/Kerja/app-erp-management-aset/README.md:20-30
- **Kenapa penting:** Without parent/child assets there is no way to model a machine and its components, so a repair on a sub-assembly cannot roll cost or failure history up to the machine. Without criticality, work order prioritisation stays manual — exactly what an EAM is bought to avoid.
- **Perubahan yang diperlukan:** Owner: this app. Add a self-referencing parent asset column with a composite (tenant_id, parent_asset_id) foreign key following the pattern already proven at 2026_07_26_000000_create_master_data_aset_tables.php:71-77, plus a criticality master and column, with server-side cycle prevention.

### [ ] `ASSET-16` — No work order scheduling — no resource, tool, or worker capacity and no calendar

**Jenis:** missing-core-capability · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset, workforce, project-accounting

- **Dynamics 365:** D365 Asset Management schedules work order jobs against worker capacity, tool capacity, and calendars (working times, shifts, holidays), honouring trade and competency requirements, producing a scheduled start and end per job.
- **Keadaan sekarang:** No scheduling engine, worker, tool, trade, capacity, or calendar. Core has fiscal_calendars/fiscal_years/fiscal_periods — accounting periods, not working time — and there is no shift, working-time, or holiday table anywhere.
- **Bukti:** apps/control-plane/database/migrations/2026_07_26_010000_create_fiscal_calendar_tables.php:11-44; D:/Kerja/app-erp-management-aset/database/migrations/ (no calendar or capacity table)
- **Kenapa penting:** Scheduling is the difference between a work order log and a maintenance system. It is also the clearest capability that cannot be built app-side: working-time calendars and workforce are shared facts needed by Asset Management, Project Accounting, and HR, so if this app invents them the platform gets three incompatible copies.
- **Perubahan yang diperlukan:** Owner: Core for working-time calendars and the workforce contract (worker, position, trade/competency, availability). Owner: this app for the scheduling assignment tables and the scheduling calculation, consuming Core's read contracts.
- **Koreksi verifier:** Scheduling is absent and its Core prerequisites (working-time calendars, workforce) are absent too. Severity is high rather than blocker: work orders can be created, executed, and costed without a scheduling engine, and the scheduling assignment tables are additive. The Core-owned prerequisites (ASSET-33, ASSET-34) carry the blocking weight, because if this app invents workers or calendars the platform gets incompatible copies.

### [ ] `ASSET-18` — No fault registration, downtime registration, or condition assessment

**Jenis:** missing-core-capability · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset

- **Dynamics 365:** D365 Asset Management registers faults on a work order job using a fault-area / symptom / fault / remedy taxonomy with a fault design per asset type, records downtime periods against the asset, and captures asset condition assessments.
- **Keadaan sekarang:** m_kondisi_aset ("Daftar kondisi yang dapat dipilih saat menilai aset") is a flat lookup that nothing references — there is no assessment record to use it. No fault taxonomy, no fault registration, and no downtime table.
- **Bukti:** D:/Kerja/app-erp-management-aset/ui/src/masters.ts:101-109; D:/Kerja/app-erp-management-aset/api/app/Http/Controllers/KondisiAsetController.php; D:/Kerja/app-erp-management-aset/database/migrations/2026_07_26_000000_create_master_data_aset_tables.php:21
- **Kenapa penting:** Fault and downtime registration are the only source of MTBF, MTTR, and availability. Without them the app can never produce the KPI set the module is judged on, and "analisa maintenance" will stay a dropdown rather than an analysis.
- **Perubahan yang diperlukan:** Owner: this app. Add fault area, symptom, fault, and remedy masters plus a per-asset-type fault design; add fault registration on the work order job; add a downtime registration table keyed to the asset with start, end, and reason; add condition assessment referencing m_kondisi_aset so that master finally has a consumer.

### [ ] `ASSET-23` — Permissions are a 300-second snapshot inside the token; revocation is not effective until refresh

**Jenis:** contract-gap · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, procurement

- **Dynamics 365:** D365 evaluates role, duty, and privilege membership server-side per request, so revoking a role takes effect on the next action.
- **Keadaan sekarang:** Core computes the permission list once when the host page renders and embeds it in a token expiring in 300 seconds; the app authorizes purely from that list with in_array. The host reloads the page every 240 seconds to refresh it. There is no revocation signal and no per-request check.
- **Bukti:** apps/control-plane/app/Support/AppContextToken.php:24-34; apps/control-plane/resources/js/pages/apps/host.tsx:18-25; D:/Kerja/app-erp-management-aset/api/app/Http/Controllers/MasterDataController.php:199-204
- **Kenapa penting:** Removing a user's duty does not stop them for up to five minutes and there is no way to force it. Tolerable for master data; not tolerable for approving a work order, posting cost, or disposing an asset — which are the next features. This is also the shape a future Segregation-of-Duties and access-audit capability must fit into, both listed as not started at docs/dev/10-core-foundation-gates.md:15,18.
- **Perubahan yang diperlukan:** Owner: Core. Either shorten token life sharply and add a revocation/introspection endpoint the app can call for consequential actions, or move to per-request permission checks against Core cached on a role-assignment version. Owner: this app — require the live check on any state-changing action once Core exposes it, and test that a revoked duty is refused.

### [ ] `ASSET-24` — No organization-scope filtering — any user with read permission sees every record in the tenant

**Jenis:** contract-gap · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, procurement

- **Dynamics 365:** D365 restricts records by organization through the security role's organization scope and by extensible data security policies; a user assigned to one operating unit does not see another's assets or work orders.
- **Keadaan sekarang:** Core stores role_assignment_org_scopes (organization_id, hierarchy_version_id, include_descendants) and resolves it in CurrentWorkspace::organizations for its own UI, but the token carries only the single selected legal entity and operating unit — not the authorized set. The app ignores even those two and filters on tenant_id alone.
- **Bukti:** apps/control-plane/database/migrations/2026_07_20_010000_add_organization_structure_and_closure.php:67-79; apps/control-plane/app/Support/CurrentWorkspace.php:44-74; apps/control-plane/app/Support/AppContextToken.php:29-30; D:/Kerja/app-erp-management-aset/api/app/Http/Controllers/MasterDataController.php:176-181
- **Kenapa penting:** docs/dev/02-module-standard.md:99 requires organization scope on every endpoint, and docs/dev/10-core-foundation-gates.md:16 gates the XDS-equivalent policy on the first module having business tables and a testable TenantContext plus organization scope contract. App #1 now has business tables, so that gate's precondition is met and the contract is the blocking item. Without it a multi-site tenant cannot use the app at all.
- **Perubahan yang diperlukan:** Owner: Core. Put the resolved authorized organization set (ids plus descendant expansion) in the context token or behind an internal endpoint, and specify what a null legal entity means. Owner: this app — filter every query by that set once assets and work orders exist, and add a test proving a user scoped to one operating unit cannot read another's records in the same tenant.

### [ ] `ASSET-25` — asyncapi.yaml is the untouched template with zero channels; no event is ever published and there is no outbox

**Jenis:** contract-gap · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset, procurement, fixed-assets, finance-gl

- **Dynamics 365:** D365 F&O publishes business events (asset created, work order state changed, work order completed) that downstream modules and integrations subscribe to.
- **Keadaan sekarang:** contracts/asyncapi.yaml is 84 bytes: asyncapi 3.0.0, an info block, and `channels: {}` — structurally identical to the template's file with only the title changed. No outbox table exists in the app database, no event class or dispatch exists in api/app/, and Core has no broker, no publish endpoint, and no outbox table either.
- **Bukti:** D:/Kerja/app-erp-management-aset/contracts/asyncapi.yaml:1-5 (whole file) versus D:/Kerja/app-erp-template/contracts/asyncapi.yaml (same file, title "Change me events"); no event, outbox, or publish reference anywhere in D:/Kerja/app-erp-management-aset/api/app/; docs/dev/04-api-and-integration.md:51
- **Kenapa penting:** docs/dev/02-module-standard.md:100 requires events created through an outbox after commit and declared in AsyncAPI. With no event, no other app can react to an asset being created or a work order completing, so every future integration has only synchronous REST — the exact one-to-one coupling docs/dev/04-api-and-integration.md:16 says the broker exists to prevent.
- **Perubahan yang diperlukan:** Owner: this app — add an outbox_events table in its own database written inside the business transaction, a publisher draining it after commit, and real channels in asyncapi.yaml. Owner: Core — choose and stand up the broker, define the envelope (tenant_id, app, correlation id, message id) and the inbox/processed-events convention so consumers are idempotent.
- **Koreksi verifier:** Real, but severity is high rather than blocker: the app currently holds only tenant-wide classification lookups, and there is no business fact worth publishing yet. The blocking form arrives with the asset register and work orders. The Core half — choosing a broker, defining the envelope (tenant_id, app, correlation id, message id) and the inbox/processed-events convention — is the item that needs to start now, since docs/dev/02-module-standard.md requires events through an outbox after commit and declared in AsyncAPI.

### [x] `ASSET-26` — Host memakai navigasi app dan merendernya dengan komponen Web Shell

**Jenis:** doc-code-drift · **Verifikasi:** CONFIRMED · **Memblokir:** management-aset, procurement

- **Dynamics 365:** Not a D365 concept — this is CoreERP's own stated Web Shell contract.
- **Keadaan sekarang:** `ui.navigation` menjadi kontrak host deklaratif. Core memvalidasi dan menyimpan rail/sidebar, hanya menampilkan item dengan permission `read` efektif pengguna, serta mengarahkan hash route artifact dari query `view`. Management Aset tidak lagi menggambar navbar kedua di dalam iframe.
- **Bukti:** `AppCatalogRequest` memvalidasi navigation dan referensi permission; `LaunchableAppCatalog::navigationFor()` memfilter menu; `app-sidebar.tsx` merender menu app aktif dengan komponen Web Shell; `BusinessOnboardingTest::test_owner_can_launch_an_entitled_product_after_the_placement_is_ready` membuktikan menu dan route aktif; `ui/src/App.tsx` hanya merender konten bisnis.
- **Batas SDK:** header, rail, dan sidebar tetap milik Web Shell. App tidak mengimpor source komponen internal Core. Komponen konten umum tetap harus datang dari paket `@apperp/ui` yang dipublish dan berversi, bukan dependency path lintas repository.

### [ ] `ASSET-31` — Core has no document attachment service, so photos, certificates, and warranty documents have nowhere to go

**Jenis:** missing-core-capability · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset, procurement

- **Dynamics 365:** D365 F&O has document management (DocuRef): typed attachments — file, note, URL — on any record, with storage, permissions, and retention.
- **Keadaan sekarang:** No attachment, document, or file table in Core; no storage contract; nothing in the app either. The app's only free-text field is `keterangan` (text, max 2000).
- **Bukti:** No attachment or document table in apps/control-plane/database/migrations/; D:/Kerja/app-erp-management-aset/database/migrations/2026_07_26_000000_create_master_data_aset_tables.php:59-61
- **Kenapa penting:** Maintenance is a photo-driven process: the reporter photographs the fault, the technician photographs the repair, the asset carries a calibration certificate and a warranty PDF. A maintenance request without attachments is barely usable, so this blocks ASSET-14 in practice even once the table exists.
- **Perubahan yang diperlukan:** Owner: Core. Add an attachment service: object storage abstraction, attachment record keyed by (tenant_id, app, owner type, owner id) with content type, size, scan state, uploader, and retention class, plus an internal API and signed-URL upload/download so apps never proxy bytes. Owner: this app — attach to maintenance request, work order, checklist result, and asset once the contract exists.
- **Koreksi verifier:** Real and entirely absent, but severity is high rather than blocker: a polymorphic attachment service keyed by (tenant_id, app, owner type, owner id) is additive — adding it later forces no rework of the maintenance-request or work-order tables. It does make the maintenance-request feature poor in practice, since maintenance is a photo-driven process, so it should land alongside ASSET-14 rather than before it.

### [ ] `ASSET-34` — Core has no working-time calendar; fiscal_calendars is period accounting and cannot schedule work

**Jenis:** missing-core-capability · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset, workforce, project-accounting

- **Dynamics 365:** D365 F&O calendars define working times, shifts, capacity per day, and holiday/exception days; Asset Management's scheduling engine consumes them to place work order jobs.
- **Keadaan sekarang:** Core has fiscal_calendars, fiscal_years, and fiscal_periods — name, start, end, and a status per accounting period, plus a nullable fiscal_calendar_id on legal entities. There is no working day, shift, working time, or holiday table anywhere, and docs/dev/15-fiscal-calendars.md scopes these to accounting.
- **Bukti:** apps/control-plane/database/migrations/2026_07_26_010000_create_fiscal_calendar_tables.php:11-44; no working-time or holiday table in apps/control-plane/database/migrations/
- **Kenapa penting:** The name similarity is a trap: a reader will reasonably conclude "Core has calendars" and discover mid-build that fiscal periods cannot answer whether Saturday is a working day at this site. Without working-time calendars every scheduled date the app computes is wrong, and preventive maintenance due dates land on shutdown days.
- **Perubahan yang diperlukan:** Owner: Core. Add working-time calendars as a distinct capability — calendar, working-day pattern, shift with capacity, and dated exceptions/holidays — scoped per tenant and assignable per legal entity or operating unit, with an internal read API, and named clearly apart from fiscal calendars in both code and UI.
- **Koreksi verifier:** Confirmed as an absence and the naming trap is real — a reader will conclude 'Core has calendars' and discover mid-build that fiscal periods cannot answer whether Saturday is a working day at this site. Severity is high rather than blocker, matching ASSET-16: it blocks the scheduling increment, not the work order itself. It must be named distinctly from fiscal calendars in code and UI when it is built.

### [ ] `ASSET-35` — Core has no party/address model, no batch-job framework, no alerts, no data import, and no print/report service

**Jenis:** missing-core-capability · **Verifikasi:** ADJUSTED · **Memblokir:** management-aset, procurement, inventory, fixed-assets

- **Dynamics 365:** D365 F&O provides the Global Address Book (party, postal address, contact), the batch framework (recurring batch jobs with tenant/company context, retries, batch groups), Alerts (rule-based due-date and change notifications), the Data Management Framework (entity-based import/export for mass onboarding), and SSRS print management for document output.
- **Keadaan sekarang:** None of the five exists in Core. Core has Laravel's stock `jobs` table — a queue, not a tenant-aware recurring batch framework with a visible run history. There is no party or postal address table, no notification/alert table, no import/export framework, and no report/print service. The app's routes/console.php has only the stock inspire command.
- **Bukti:** apps/control-plane/database/migrations/0001_01_01_000002_create_jobs_table.php; no party, address, notification, import, or report table in apps/control-plane/database/migrations/; D:/Kerja/app-erp-management-aset/api/routes/console.php:1-9
- **Kenapa penting:** Each of the five directly blocks a named Asset Management capability: addresses block functional locations and vendors (ASSET-04); batch jobs block maintenance schedule generation (ASSET-08); alerts block due-maintenance notification, the module's main proactive value; data import blocks asset onboarding, since no customer types 5,000 assets by hand, which makes the app unsellable without it; print blocks the work order sheet a technician carries into the field. None belongs in a business app — every app needs all five.
- **Perubahan yang diperlukan:** Owner: Core, as five capabilities with their own gate rows in docs/dev/10: (1) party plus postal address service; (2) tenant-aware recurring batch job framework with schedule definition, run history, failure state, and idempotency, exposed to apps; (3) alert rule plus notification delivery with an in-shell inbox; (4) data import/export framework driven by app-declared entity contracts with staging, validation, and error reporting; (5) a print/report service producing documents from app data via a declared template contract.
- **Koreksi verifier:** Every one of the five is real, but bundling them into a single finding is mis-scoped — they have different owners, different severities, and different sequencing, and a single high-severity row will be under-planned. Split them: data import/export is high and the most commercially urgent (no customer types 5 000 assets by hand, which makes the app unsellable without it); tenant-aware recurring batch is high because it blocks maintenance schedule generation (ASSET-08); party/postal address is medium and blocks functional location and vendor; alerts are medium and are the module's main proactive value; print/report is medium and blocks the work order sheet a technician carries. All five belong to Core and all five need their own row in docs/dev/10-core-foundation-gates.md.

### [ ] `ASSET-V01` — No actor is ever recorded: the token carries the user id and the app throws it away, so no master record has an author

**Jenis:** missing-core-capability · **Verifikasi:** VERIFIER-FOUND · **Memblokir:** management-aset, procurement, fixed-assets

- **Dynamics 365:** D365 F&O stamps CreatedBy/ModifiedBy/CreatedDateTime/ModifiedDateTime on every table and adds the database log for field-level change history; asset registers and fixed asset transactions are routinely audited on who changed what.
- **Keadaan sekarang:** apps/control-plane/app/Support/AppContextToken.php:27 puts 'sub' => membership->user_id into the context token. D:/Kerja/app-erp-management-aset/api/app/Http/Middleware/RequireCoreErpContext.php:19-22 extracts only tenant_id, legal_entity_id, org_unit_id, and permissions — 'sub' is never read anywhere in the app. No migration defines created_by, updated_by, deleted_by, or any change-log table, and MasterData.php:18 does not list one in $fillable.
- **Bukti:** apps/control-plane/app/Support/AppContextToken.php:27; D:/Kerja/app-erp-management-aset/api/app/Http/Middleware/RequireCoreErpContext.php:19-22; D:/Kerja/app-erp-management-aset/database/migrations/2026_07_26_000000_create_master_data_aset_tables.php:52-64 (complete column list of every master); D:/Kerja/app-erp-management-aset/api/app/Models/MasterData.php:18
- **Kenapa penting:** The identity is already in the token and is being discarded, so the fix costs nothing today and is unbackfillable later. Once an asset register, a work order approval, a cost posting, or a disposal exists there is no answer to 'who did this', which makes the module unauditable and directly undermines the access-audit-trail gate at docs/dev/10-core-foundation-gates.md:18, whose own condition is that authorization decisions have a correlation ID and a trusted actor.
- **Perubahan yang diperlukan:** Owner: this app — read 'sub' in the middleware, add created_by/updated_by/deleted_by (opaque user ULID) to every table now while they are empty, and add a change-log table for transactional records. Owner: Core — state in the context-token contract that 'sub' is a stable user identifier apps may persist, and define what apps should record for system/batch actors once a batch framework exists.

### [ ] `ASSET-V02` — Core's own migration hand-writes a 'ready' placement for management-aset while no app_releases row exists, so the four-truths lifecycle is faked and the app cannot actually launch

**Jenis:** doc-code-drift · **Verifikasi:** VERIFIER-FOUND · **Memblokir:** management-aset, procurement

- **Dynamics 365:** Not a D365 concept — this is CoreERP's own stated lifecycle rule.
- **Keadaan sekarang:** apps/control-plane/database/migrations/2026_07_23_022000_register_management_asset_development_release.php:22-37 inserts app_placements rows for management-aset with artifact_status 'placed', migration_status 'succeeded', runtime_status 'ready', and ready_at now() — without any artifact ever being placed or any migration ever run. Meanwhile no app_releases row for management-aset 0.1.0 is created by any migration or seeder (searched apps/control-plane/database/migrations/ and apps/control-plane/database/seeders/; app_releases is written only by AppReleaseController). LaunchableAppCatalog.php:38-42 joins app_releases on app_id + version with status 'available', so the launcher finds nothing and the app never appears — while the placement table asserts it is ready.
- **Bukti:** apps/control-plane/database/migrations/2026_07_23_022000_register_management_asset_development_release.php:22-37; apps/control-plane/app/Support/LaunchableAppCatalog.php:38-50; apps/control-plane/app/Http/Requests/Provider/AppReleaseRequest.php:19-30 (the only writer path, requiring digest-pinned images the app does not have); docs/dev/10-core-foundation-gates.md:24,27
- **Kenapa penting:** docs/dev/10-core-foundation-gates.md:24 forbids placeholder tables and fake status, and :27 fixes the order catalogued -> entitled -> placed + migrated -> ready with each fact owning its own truth. This migration writes three of those facts by hand for the flagship app, so the installation registry lies about app #1 and no failure state was ever exercised. The two states are also mutually inconsistent — placement says ready, the launcher says the app does not exist — which will be debugged as a launcher bug rather than as fabricated state.
- **Perubahan yang diperlukan:** Owner: Core — delete or gate the hand-written placement, register a real app_releases row through the normal path once CI produces digests (ASSET-27), and let DeployAppPlacement set the placement statuses. Add a test that a placement cannot reach runtime_status 'ready' without a matching available app_releases row, per gate rule 3.

## Sedang

| ID | Temuan | Perubahan yang diperlukan |
| --- | --- | --- |
| `ASSET-10` | No asset attributes or attribute types | Owner: this app. Add attribute type with a value domain, attribute group, an assignment table binding groups to asset type / model / functional location type / job type, and an attribute value table keyed by (tenant_id,  |
| `ASSET-19` | "Analisa maintenance" is a lookup of conclusions, not analytics — no MTBF, MTTR, availability, downtime, cost, or backlog | Owner: this app. Relabel the master so it reads as a conclusion vocabulary, and separately plan a KPI surface computed from work order, fault, and downtime data with an explicit aggregation strategy (on-read first, a pro |
| `ASSET-21` | No mobile or field surface, no barcode/QR identification, and no IoT or condition monitoring path | Owner: Core — define a device/service identity flow for non-interactive callers and a scan/QR identity convention. Owner: this app — a field-mode UI and an idempotent counter/condition ingestion endpoint keyed per readin |
| `ASSET-29` | Eight tenant-scoped number sequence references is not enough, and none allows legal-entity scope | Owner: this app — declare references for asset, functional location, maintenance request, work order, work order job, maintenance plan, maintenance round, schedule proposal, fault registration, and checklist, with allowe |
| `ASSET-36` | No UI tests at all, and no test covers org scope, token expiry, wrong audience, or contract conformance | Owner: this app and the template — add Vitest plus React Testing Library covering permission-filtered navigation, the postMessage origin rejection path, and form validation; add API tests for expired token, wrong-aud tok |
| `ASSET-V03` | The health endpoint is a hardcoded literal that never touches the database or Core, so 'ready' is not evidence of readiness | Owner: this app and the template — make /api/v1/health verify a database round trip and report the migration state, and add a separate readiness probe that also checks the Core number-sequence dependency, returning 503 w |
| `ASSET-V04` | Archiving is a one-way door: no restore endpoint exists and the consumed number is never returned to Core | Owner: this app — add a restore endpoint with its own permission, filtering restore to records whose parent is still active. Owner: Core — add an internal release/reuse endpoint to the number-sequence API and specify whe |
| `ASSET-V05` | No optimistic concurrency on update: two editors silently overwrite each other and PATCH/DELETE carry no idempotency guard | Owner: this app — add a version column (or a strong ETag derived from updated_at plus row hash), return it in every representation, require If-Match on PATCH and DELETE for transactional resources, and answer 412 on mism |
| `ASSET-V07` | The creation_key migration adds a NOT NULL column plus a unique index to a table that Core already believes is deployed and migrated | Owner: this app — make added columns nullable with a backfill step before any unique index, and squash these three migrations into a single create while the app is still pre-release. Owner: this app and the template — ru |

## Rendah

| ID | Temuan | Perubahan yang diperlukan |
| --- | --- | --- |
| `ASSET-V06` | The app's own API has no rate limiting, while Core's internal API does | Owner: this app and the template — add a per-tenant rate limiter keyed on the context token's tenant_id, with a tighter bucket on POST because it consumes a number. Owner: Core — state the expected limiter shape in docs/ |
