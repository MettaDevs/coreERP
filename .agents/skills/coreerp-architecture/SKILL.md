---
name: coreerp-architecture
description: Guard CoreERP architecture boundaries, organization model, authorization, contracts, and lifecycle truth. Use when changing tenant or organization scope, legal entities, operating units, hierarchies, workforce/positions, roles/permissions, module catalog, entitlement, onboarding, provisioning, installation, placement, deployment, product launcher, upgrades, SaaS/on-prem packaging, or module availability UI/API. Also use when touching any cross-module surface — OpenAPI/AsyncAPI contract files, `internal/v1` endpoints, published events, webhooks, event envelopes or versions — or when adding a route another app calls.
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

### Contract decision gate

A contract is the promise a module makes to code it does not control. It is not
documentation of the code, and it is not optional once an endpoint or event
crosses a module boundary.

Before adding or changing anything reachable from outside the module — any
`internal/v1` endpoint, any published event, any webhook, any signed payload:

1. **Decide the transport from the semantics, not from convenience.** REST is a
   command or a query: "do this", "give me that". An event is a fact that already
   happened. A synchronous call documented as a channel, or a fact modelled as a
   command, is a wrong contract even when the code works.
2. **The contract is written, not generated, for every cross-module surface.**
   Generated specs are acceptable only for surfaces whose sole consumer is this
   module's own UI. A generated file cannot be the source of truth for a promise,
   because changing the code silently changes the promise.
3. **An undocumented cross-module surface is an incomplete change.** If a route
   exists that another app calls and it is absent from the contract, the change is
   not finished. Absence is the most common failure here and it is invisible in
   tests — nothing fails when a contract omits an endpoint. For Control Plane, the
   app-facing surface lives in `contracts/openapi-internal.yaml` and is enforced by
   `python contracts/check-contract-coverage.py`, which also runs in CI. Run it after
   any change to `routes/api.php`.

   **Every app carries its own coverage check, not only Control Plane**, and it runs in
   CI — a checker no pipeline invokes is a file, not a gate. Three things decide whether
   it is worth having:

   - **Read routes from the framework, not from the route file's text.** Masters are
     commonly registered by looping over an array, so their paths never appear as
     literals; a regular expression reports a clean run while missing most of the
     surface. `php artisan route:list --json` is the only authoritative list.
   - **Expand path templates whose parameter carries an `enum` before comparing.** One
     templated path can legitimately document several concrete resources. Compared
     literally it reports real, documented endpoints as missing, and a check that cries
     wolf is a check people learn to skip — worse than having none.
   - **Name deferred gaps; never allowlist them silently.** A gap that is known and owned
     belongs in an explicit list carrying its reason, printed on every run. An entry that
     no longer matches a live route must fail, or a stale exemption quietly excuses
     whatever route later claims that path.
4. **Code must not accept what the contract forbids.** The aspirational-shape rule has a
   mirror that is easier to miss: a handler validating a field the contract declares
   impossible — `additionalProperties: false` with that key absent — advertises a
   capability nobody can use. It is not merely dead code; the next reader concludes the
   publisher can send it. Where the contract defers a field, the code waits for the
   contract, never the other way round.
5. **Events carry the full envelope and an explicit version.** Channel names are
   `module.aggregate.action.vN` per `docs/dev/04-api-and-integration.md`; the
   envelope carries `id`, `type`, `occurred_at`, `tenant_id`, `correlation_id`,
   and `data`, plus `legal_entity_id` when the fact has legal or accounting
   consequence and `org_unit_id` when an operating unit owns it. A channel without
   `.vN` has no way to change without breaking every consumer.
6. **Document what is true, then name the gap.** When the code does not yet satisfy
   the canonical rule, the contract describes the code and states the gap in
   `info.description`. Never write the aspirational shape — a consumer would build
   against a field that never arrives.
7. **A published version is immutable.** Adding a required field, removing one, or
   narrowing a type is `vN+1`, not an edit to `vN`. Widening an enum a consumer
   switches on is also breaking.
8. **Both sides move together.** Publisher and consumer contracts live in separate
   repos; a change to one is incomplete until the other matches in the same piece of
   work. State explicitly which files in which repos were changed. A published event
   is only real when all three legs exist: the publisher writes it, the transport
   routes it to a URL, and the consumer has a route that accepts it. Two of the three
   is a fact nobody receives.

   Because such a change lands as several branches, start each from the updated default
   branch. `git checkout -b` branches from wherever you are standing, so continuing
   straight from the previous feature branch silently carries its commits along; every
   PR then targets the default branch and shows the same work again, and a reviewer
   reads the same diff several times. When one change genuinely builds on another,
   branch from it deliberately and set the PR's base to that branch, not to the default
   one.
9. **Transport security is part of the contract.** Signature headers, the exact
   string that is signed, and the failure status belong in the spec. A consumer
   cannot verify a signature it has to reverse-engineer from the publisher's source.
10. **Split before the file becomes unreviewable.** Past roughly 1500 lines, break the
   spec into `paths/` and `components/` joined by `$ref`, and commit a bundled
   artifact next to the split source for tooling that cannot resolve cross-file
   refs. One 10k-line spec guarantees merge conflicts between unrelated features.

#### Envelope fields cannot be added retroactively

`core.workflow.decision` reached `v2` because `v1` shipped without `correlation_id`.
The lesson is about *when* a field must exist, not about that one field:

- **A field that records what happened at request time must be persisted at request
  time.** A workflow decision is emitted days after the request that started it, so
  reading the correlation from the current request is impossible — it has to live on
  the aggregate (`workflow_instances.correlation_id`) before it can reach the event.
  Any envelope field describing the *origin* of a fact has this shape.
- **History cannot be backfilled.** Events already delivered without a correlation are
  permanently uncorrelated. This is why the cost of omitting such a field grows with
  time while the cost of adding it stays flat — add it when the table is created.
- **Batch envelope changes into one version.** `correlation_id` and `legal_entity_id`
  went out together in `v2` because making every consumer migrate twice for one
  envelope is a cost with no benefit. Before bumping a version, check whether any
  other known envelope gap should ride along.
- **A publisher must refuse to emit a payload that violates its own contract.** When a
  required envelope field is missing, hold the row back and report it. Sending a
  half-formed event moves the failure to the consumer, where it looks like a bug in
  code that did nothing wrong.
- **Deriving a field is part of the design, not an afterthought.** `legal_entity_id`
  is not on the workflow instance; it is reached through the configuration version the
  instance ran against. If a required envelope field has no obvious source, resolve
  where it comes from before promising it in the contract.
- **The consumer's transport client sends what the publisher needs.** A correlation
  only exists if the caller supplies it — `WorkflowClient` sends `X-Correlation-Id`.
  An envelope field nobody populates is a contract that is true and useless.

Security objects follow the same rule and have a specific trap. Entry points,
permissions, privileges, and duties are **declared in the app manifest** and travel
with the release. Tenants compose roles and duties from those declarations; they
never mint new codes. Dynamics 365 F&O allows security objects to be created through
the UI, which stores them only in that environment's database under generated GUID
names — unreviewable, unversioned, and lost on refresh. CoreERP deliberately rejects
that model (`docs/dev/09-identity-and-access.md`). Never add a path that lets a
tenant create a permission code or entry point at runtime.

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

### Replace endpoints must hold the row they replace

An endpoint that swaps a whole set — "these are now the linked records" — deletes and
re-inserts. Inside a transaction that still is not enough: two callers can interleave so
one deletes, the other deletes and inserts, then the first inserts on top. The result is
the **union of both sets**, which neither caller asked for and which no database
constraint rejects, because every surviving row is individually valid. Take
`lockForUpdate()` on the owning row inside the transaction, before the delete.

A join table edited from **both** ends needs more care. Locking each direction's own
owner does not serialise anything — the two directions hold locks on different tables and
still overwrite each other. Both directions must lock the **same** side, over the union of
the old and new sets, so that any two operations touching link `(a, b)` share a lock. Take
those locks in a fixed order (sort by id) or two transactions will grab the same rows in
opposite order and wait on each other forever.

Feature tests cannot show any of this, and neither can a code review that only reads one
request at a time. It belongs in the load test below.

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

**A scenario that never contends proves nothing.** Spreading virtual users evenly across
every tenant and every record is the right shape for saturation, and the wrong shape for
a race: two writers almost never meet, the run comes back green, and the defect ships.
Race scenarios concentrate — many users, few records, writing deliberately conflicting
values — and assert the read-back equals one of the values submitted, never a blend of
them. Keep them as their own profile alongside saturation; each answers a question the
other cannot.

**Every new surface needs its own scenario.** A module whose load test covers the masters
it shipped with, but not the ones added later, is unverified for the part that changed.
Check the scenario's resource list against the routes before claiming a module is
covered — a name that merely sounds related (an old master that happens to contain the
word) is not coverage.

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

### An undocumented module is also incomplete

The rules a module enforces — what may not change after a record exists, which value is copied rather than referenced, why one permission is split from its neighbour — are invisible in the schema and only half-visible in the code. Left unwritten they survive in one person's memory and in comments, and both are lost when that person moves on.

A module is finished when its behaviour is documented under `docs/apps/<app-id>/`, every page is registered in the sidebar, and the documentation build passes. See the `coreerp-docs` skill for the pattern and the coverage sweep that proves nothing was missed.

## Launcher rule

A launcher labelled "produk terpasang" must query installation records for the active tenant and placement, normally filtering `status=ready`. Entitlement may be checked additionally for authorization, never as installation evidence.
