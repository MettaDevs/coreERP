---
name: coreerp-architecture
description: Guard CoreERP architecture boundaries, organization model, authorization, and lifecycle truth. Use when changing tenant or organization scope, legal entities, operating units, hierarchies, workforce/positions, roles/permissions, module catalog, entitlement, onboarding, provisioning, installation, placement, deployment, product launcher, upgrades, SaaS/on-prem packaging, or module availability UI/API.
---

# CoreERP Architecture

Preserve the distinctions between the SaaS tenant boundary, business organization model, authorization, commercial rights, deployed artifacts, and live runtime state.

## Canonical sources

Read only the sections relevant to the task:

- `docs/dev/README.md` is the canonical design index.
- `docs/dev/01a-tenant-and-org-hierarchy.md` defines the CoreERP organization model.
- `docs/dev/08-query-scopes-and-schema.md` defines organization persistence and query scope.
- `docs/dev/09-identity-and-access.md` defines workforce and responsibility-based access.
- `docs/references/dynamics-365-organization-model.md` records the Microsoft Dynamics 365 sources and the mapping decisions used by CoreERP.

The Dynamics reference governs organization, workforce, and responsibility-based security inside a tenant. CoreERP's tenant, entitlement, deployment, and on-prem boundaries remain separate decisions.

## Organization model guard

- Tenant is the contract and isolation boundary, not the root organization.
- Organization identity is stable and has exactly one classification: legal entity or operating unit.
- Legal entity owns legal, ledger, tax, currency, fiscal-calendar, and statutory consequences. Operating unit divides operational resources and processes; it cannot replace a legal entity when those consequences exist.
- Establishment is an operating unit placed in an effective hierarchy with purpose `Enterprise establishment structure`; it is not an `operating_units.type` value or a second legal entity.
- Parent-child belongs to a purpose-scoped, versioned hierarchy. Never store permanent `parent_id`, depth, or a single universal tree on organization identity.
- Preserve draft, publish, effective-date, and historical versions. Reparenting creates or changes a draft; it does not rewrite published history.
- Department is an operating unit. Job, position, worker assignment, position reporting, project WBS, warehouse/bin, chart of accounts, financial dimensions, and sales territory are separate domain structures.
- Every tenant-owned transaction/event carries `tenant_id`; legal/accounting facts also carry `legal_entity_id`; operational facts carry `org_unit_id` when relevant. These IDs are trusted context or opaque contract references, never cross-module foreign keys.
- Access follows role assignment → security role → duty → privilege → permission. Organization scope names the organization and the hierarchy used for descendants; hierarchy placement does not grant permission by itself.

If a requested model conflicts with these rules, stop and report the conflict against the canonical source instead of adding a compatibility layer.

## Module lifecycle states

| State | Meaning | Valid source of truth |
| --- | --- | --- |
| Catalogued | CoreERP knows the product and its contracts. | Canonical module catalog/manifest |
| Entitled | A tenant is allowed to use the module. | Tenant entitlement |
| Installed | Release artifacts and database migrations were successfully applied to a placement. | Installation/deployment registry |
| Ready | The installed module passed readiness checks and is routable. | Runtime/placement status |

Never derive a later state from an earlier state:

- Entitlement is not installation.
- Installation is not runtime readiness.
- A manifest entry is not proof that its container or database exists.
- `active` or `trial` entitlement cannot drive UI labelled "installed".

## Required workflow

### Permission and duty decision gate

Before changing an app manifest, catalog registration, role, duty, privilege, permission, entry point, protected menu, or protected endpoint:

1. Classify the requested access as a resource and action, never as a job title or a menu alone.
2. Keep permissions granular enough that one resource can be managed without granting sibling resources.
3. Model each layer as itself. An **entry point** is the protected thing (`form`, `menu_item`, `api`, `report`, `action`). A **permission** pairs one entry point with one access level. A **privilege** bundles permissions into one task. A **duty** bundles privileges into part of a business process. Tenant roles then compose duties.
4. If the user has not explicitly stated which resources/actions belong together, do **not** edit code, manifest, or documentation as though the choice were settled. Recommend the smallest sensible permission/duty split, ask for confirmation, and wait.

Example: “master data” is not a sufficient scope. Recommend separate `entities.manage`, `groups.manage`, and `categories.manage`, then ask whether a combined “manage master” duty is wanted.

#### The four layers must never be collapsed

The chain `role → duty → privilege → permission → entry point` has to survive into storage, not just into prose:

- Persist four distinct rows. A privilege that carries the same code as its only permission is the chain collapsed into one layer copied three times, and it silently removes the level tenants need in order to grant a task without granting the whole duty.
- Privilege codes must differ from permission codes. Reject a manifest that reuses one.
- `access_level` is declared, never derived. Splitting it out of a permission code string (`…archive` → access level `archive`) invents levels that do not exist.
- Valid access levels are exactly `read`, `update`, `create`, `correct`, `delete`, and `invoke`. CoreERP delete-lifecycle actions (`archive`, `void`, `retire`) use `delete`; a service operation with no CRUD meaning uses `invoke`.
- Manifest keys mirror the Control Plane catalog payload exactly, so `app.yaml` posts verbatim with no transformation layer to drift.

#### Security role hierarchy

A security role may be built on other roles: a parent inherits the duties of every descendant. A role can have several parents and several children, so this is an acyclic directed graph, not a tree. Reject cycles at write time, and treat an inactive role as granting nothing and bridging nothing to its own children. This is a hierarchy of responsibility and is unrelated to organization hierarchy; never resolve one against the other.

### Number sequence decision gate

Before adding or changing a Number Sequence reference, manifest, setting, or API contract:

1. Confirm the owning app, reference code, and allowed tenant/legal-entity/operating-unit scopes.
2. Confirm whether the reference is non-continuous, continuous, or allows manual values; reject continuous plus manual.
3. Confirm reset-period, format segments, counter limit, and whether preallocation is enabled. Preallocation must always be durable and owned by Control Plane; never cache a counter or a number range in app or API-instance memory. Non-continuous preallocates a block per scope. Continuous may also preallocate, but only as durable rows in the Core pool that stay individually accounted for, so a gap is impossible.
4. Confirm the reset period explicitly: `never`, `calendar_year`, `fiscal_year`, or `fiscal_period`. A reset period other than `never` requires a format segment that varies with that period.
5. Fiscal reset requires a legal entity in context, because the fiscal calendar belongs to the legal entity and never to an operating unit. Legal-entity scope resolves it from the scope itself. Operating-unit scope is allowed, but the caller must supply the legal entity per request — an operating unit is deliberately shared across legal entities, so it cannot imply one — and that legal entity becomes part of the counter's scope key. Tenant scope cannot use fiscal reset: it names no organization, so the caller's argument would be the only thing deciding the counter's identity.
6. Anything that splits a counter belongs in the scope key, never only in the period key. The uniqueness index is bounded by period, so a fact that varies the period while the scope key stays constant lets one declared scope hold two counters and issue the same document number twice without the database noticing.
7. Keep business references in the app manifest. Keep tenant configuration, counter, audit, and issuance in Control Plane. Never grant an app direct database access.
8. If any of those choices are absent from the request, recommend the smallest safe setting and ask before changing code or documentation as if it were settled.

### Data policy decision gate

Before creating or changing an app resource that stores or exposes operational
records:

1. Classify the resource as tenant-wide reference data, legal-entity-scoped
   data, or data that needs an organization security policy. Do not make an
   operating unit a universal filter merely because it exists in the workspace.
2. For a policy-scoped resource, the app manifest/contract must declare one
   stable, namespaced policy code (for example
   `management-aset.asset-responsibility`), the protected entry points/actions,
   the required legal-entity and operating-unit dimensions, and whether an
   organization grant may include descendants.
3. The app contract must state which business record fields/relations it uses
   to enforce the policy. Those implementation details remain inside the app;
   Core must not receive table names, query its database, or own its query.
4. Core owns the policy catalog, role-assignment grants, hierarchy/version
   resolution, effective dates, provenance, audit, and signed policy-specific
   context claims. The app owns enforcement on every list, search, detail,
   create, update, delete, and sensitive action endpoint.
5. A workspace selection may default a filter or new-record value only. It is
   never authorization, and browser-supplied organization IDs are never proof
   of access.
6. Define and test the combination rule before implementation when more than
   one role, policy, grant, automatic source, or temporary source can apply.
   Do not silently assume union; XDS policies may intersect, while a process
   can have its own documented organization-grant rule.

Use the implementation backlog in
`docs/todo/addNewRulestoCoreforFleksibilitas/README.md` until its target model
is promoted into the canonical design. The Dynamics references behind this gate
are the organization, Budget planning security, and XDS documentation linked
there.

Before implementing module-availability UI or API:

1. Translate the user's exact noun into a lifecycle state.
2. Locate that state's persisted source of truth.
3. Check tenant, placement, release, and expiry scope where applicable.
4. Name props, methods, endpoints, and labels after the actual state.
5. Add a test proving one lifecycle state cannot impersonate another.

Before implementing organization, workforce, or access changes:

1. Classify each noun as tenant, legal entity, operating unit, hierarchy placement, workforce fact, financial dimension, or authorization fact.
2. Identify the owning module/service and persisted source of truth.
3. Determine whether records/events need `tenant_id`, `legal_entity_id`, and/or `org_unit_id`.
4. For descendants, name the hierarchy, purpose, and effective version explicitly.
5. Add the smallest test that rejects cross-tenant references, invalid classification, hierarchy cycles/history rewrites, or scope escalation relevant to the change.

If the required source of truth does not exist, stop and report the missing registry or schema. Do not substitute entitlement, hard-coded catalog data, or optimistic UI and call it installed.

## Module completion gate: concurrency and load

A module is **not finished when its feature tests pass**. Feature tests run one request at a time, in one process, against SQLite. They structurally cannot observe connection exhaustion, lost updates, duplicate issued numbers, a tenant boundary that only leaks under interleaving, or an idempotency key that races itself.

Before reporting any new module complete, run a load test that satisfies **all** of:

| Dimension | Minimum |
| --- | --- |
| Concurrent virtual users | 1000+, sustained — not a burst |
| Distinct tenants driven simultaneously | 100+ |
| API server instances behind a load balancer | 2+, 4 recommended |
| Database | the real engine the module ships on (PostgreSQL). Never SQLite |
| Duration at full load | 90s+ after warm-up |
| Number Sequence | a stub standing in for Control Plane that records every number it issues |

Multiple instances are not decoration. They are the only way to prove the module keeps no counter, no tenant identity, and no permission cache in the memory of one API process.

### Correctness gate — hard, applies on any hardware

These must be **exactly zero**. They do not scale with CPU, so a slow laptop is never an excuse:

- application 5xx (a 502/504 from the load balancer is saturation, not a defect — attribute it explicitly)
- duplicate `kode` or duplicate `creation_key` within one tenant
- any child row whose parent belongs to a different tenant, or whose parent does not exist
- any read, list, or write that crosses a tenant boundary
- any permission on one resource granting access to a sibling resource
- two concurrent requests with the same `Idempotency-Key` producing two records
- any number-sequence prefix belonging to a different reference than the master that stored it

Verify these with SQL against the database after the run, not through the API. The API is the thing under test; it cannot be its own oracle.

### Latency gate — measured at sustainable concurrency

Report percentiles at the highest concurrency that still meets the SLO, not at the saturation point. Latency at saturation measures queue depth, not code.

| Operation | p95 | p99 |
| --- | --- | --- |
| Read (show, list) | < 200 ms | < 500 ms |
| Write (create, update, archive), incl. one Core round trip | < 400 ms | < 900 ms |

Also record the **uncontended single-request budget**, which is portable across hardware in a way that percentiles under load are not:

| Path | Budget |
| --- | --- |
| No auth, no database | < 25 ms |
| Authenticated, single-table read | < 50 ms |
| Authenticated write incl. Number Sequence call | < 120 ms |

### Naming the bottleneck is part of the gate

An absolute requests-per-second number means nothing without the hardware it was measured on. What is always meaningful: **which resource saturated first**. Report it with evidence (CPU per container, connection counts, worker counts). "It was slow" is not a result; "PostgreSQL burned 5.5 cores because every request opened a new connection, 36,809 sessions for 282,000 transactions" is.

Connection handling dominates before module code does. Check this first, every time:

- Laravel opens and closes a database connection per request unless told otherwise. PostgreSQL forks a backend process per connection, so per-request connect is a fork storm long before any query is slow.
- Fix it at the deployment layer with persistent connections (`DB_PERSISTENT`, worker count × instances must stay under `max_connections`) or a pooler. PgBouncer is single-threaded and authenticates every client connection; it becomes the next bottleneck if the app still connects per request.
- Cache config and routes as production does. A closure in a route file silently disables `route:cache`; use a controller.

If a load test cannot be run because the environment is missing (no Docker, no real database), say so plainly and report the module as unverified under concurrency. Do not report it complete.

## Launcher rule

A launcher labelled "produk terpasang" must query installation records for the active tenant and placement, normally filtering `status=ready`. Entitlement may be checked additionally for authorization, never as installation evidence.
