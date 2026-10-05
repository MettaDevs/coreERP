---
name: coreerp-analytics
description: Build and change CoreERP's analytics engine — module datasets, the JSON query compiler, dashboards and widgets, publications, the OData feed, and embeds. Use when declaring or changing a module's analytics dataset, touching anything under App\Platform\Analytics or App\Platform\Modules\Contracts\Analytics, building analytics screens, or exposing analytics data outside CoreERP.
---

# CoreERP analytics

CoreERP's own Power BI–style engine: modules declare datasets, Core compiles a JSON query into SQL
against module tables and runs it read-only, users build dashboards without code, and (phase 2) data
leaves CoreERP only through publications (JSON/CSV, OData v4, embeds).

**What exists.** Everything below under "Shipped" is code in the repo and the code is the authority. The
canonical description is `docs/dev/35-analitik.md`; the module-side catalogue is
`docs/apps/management-aset/transaction/analitik/index.md`. If code and this skill disagree, trust the
code and fix the skill in the same pull request.

| | State |
| --- | --- |
| Shipped | Dataset contract, registry, and validator; query model; compiler, read-only executor, and results; read security and the permission chain; dashboard storage and the screen API; dashboard screens; result cache, concurrency limits, and the masked query log; the management-aset datasets; formulas, period comparisons, and their builder controls |
| Open in phase 1 | Widget builder and explorer (the `/analytics/explore` page is still a temporary preview), load test, filtered measures on the asset datasets |
| Plan only (phase 2–3) | Slicers, drill, cross-module drill-across, publications, Query API, OData, embeds, templates, rollups, alerts |

The live status of each area is in the area titles of `docs/todo/analitik/todo-fase-1.md`; decisions
(`KA-xx`), the PRD, and phase 2–3 designs are in `docs/todo/analitik/`. Treat those pages as plans: they
describe what is intended, and a plan sentence is never evidence that code exists.

## Read first, by task

| Task | Read |
| --- | --- |
| Any analytics work | `docs/dev/35-analitik.md`, then `docs/todo/analitik/TODO.md` (rules, shared files) |
| Declaring a dataset in a module | `docs/dev/35-analitik.md` (section *Menyatakan dataset di module*), `docs/apps/management-aset/transaction/analitik/index.md`, and a finished example in `modules/apperp/management-aset/src/Analytics/` |
| Compiler, executor, results | `docs/todo/analitik/mesin-query.md` (query shape and SQL rules), then the code docblocks in `Query/` |
| Permissions, data policy, personal data | `docs/dev/35-analitik.md`, `docs/todo/analitik/keamanan.md`, and the data policy gate in `coreerp-architecture` |
| Dashboards, widgets, screens | `docs/todo/analitik/dasbor-dan-visual.md`, then `coreerp-ui` and `coreerp-page-standard` |
| Formulas, slicers, publications, OData, embeds (phase 2) | `docs/todo/analitik/akses-luar.md` and the phase 2 TODO, then the contract decision gate in `coreerp-architecture` |
| Cache, limits, load test | `docs/todo/analitik/kinerja-dan-uji-beban.md` |

## Boundaries that never bend

- **Core may read module tables directly, but only through datasets the module registered.** Owner's
  decision, 3 October 2026 (`docs/dev/02-module-standard.md#ownership-dan-data`). No module table or
  column name is ever written in `App\Platform\Analytics`; `AnalyticsBoundaryTest` reads file text,
  comments included, and rejects `Modules\` names, module table prefixes (from every `app.yaml`
  `table_prefix`), and `DB::table(`/`DB::select…(` outside `SQL_COMPOSERS` (compiler, cache, log).
- **A module never reads another module's tables**, and a dataset class references only its own
  models plus `App\Platform\Modules\Contracts`. `DatasetValidator` rejects other modules' models,
  joins, and references. Cross-module analysis goes through shared dimensions (phase 2 drill-across:
  aggregate each dataset by the same shared dimension, merge on its values), never a row join.
- **Every read is tenant-filtered, then policy-filtered, then user-filtered, in that order.** Tenant comes
  from the model's `BelongsToTenant` scope, which fails closed, so queries run inside
  `TenantRunner::runFor()` — Core routes do not pass `ResolveModuleContext`. Data policy goes through
  `DataPolicyFilter::apply()` on the columns the dataset declared; no grant means zero rows. User filters
  only narrow. A locked filter (publication, embed) that is empty or names an unknown field means zero
  rows, because `FieldFilterExpression` reads an empty value as "no filter".
- **Rights are checked before the query is validated against the dataset.** (`QueryParser`, shape only and
  blind to the dataset, runs earlier in the controller.) Order in `RunQuery`: normalize, find the dataset
  (404 `dataset_unknown`), module installed per `core_module_installations` and licensed (404), read
  permission of the dataset's resource (403 `dataset_forbidden`), validate with the personal-data gate,
  then cache, slot, compile, execute. A user without rights must not learn column names from errors, and
  a cache hit must never skip the rights check.
- **Every query runs read-only, time-boxed, and row-capped**: `set transaction read only`,
  `set local statement_timeout`, `LIMIT n + 1`, and `rollBack()` in `finally` — never commit.
  Rollback to a savepoint is what undoes the read-only flag when the engine runs inside another
  transaction (tests, jobs); a commit leaves it on for the rest of the outer transaction.
- **Money is never summed across currencies, quantity never across units.** The compiler adds the
  currency or unit column as an implicit dimension (`c0…`), and the validator rejects a money measure
  without a currency column. A dataset with no currency column has no money measure.
- **Personal data.** `EndUserIdentifiableInformation` fields are invisible without
  `core.analytics.personal-data.read` — as dimension, filter, time-range column, and measure source alike
  (`PersonalDataGate` implements `FieldUseGate`). Sorting is covered because `sort` only accepts chosen
  keys. Pseudonymous ids may be grouped, but the names behind them are hidden by
  `SharedDimensionRegistry::labels()` unless the principal holds the right. `AccountData` never enters a
  dataset. Publications, the OData feed, and embeds never carry personal data (phase 2).
- **Who the query runs as.** Dashboards, shared or not, run as the viewer: `WidgetDataController` builds the
  principal from the requesting membership, never from the dashboard owner. Publications will run as their
  current owner narrowed by locked filters (phase 2).
- **Cache lives in the tenant database** (`analytics_query_cache`), never in Laravel's cache store, which
  `EnvironmentConnection::pins()` points at the central database. The key (`QueryCache::key()`) includes
  tenant, dataset definition hash, normalized query, `ScopeFingerprint`, time zone, today's date, and the
  principal's row limit. Slot and stampede locks may use Laravel's lock store: their names carry only the
  tenant id and a hash. Every entry point calls `RunQuery::handle($principal, $query, cacheTtl:, refresh:,
  source:)`.
- **Permission codes come only from the catalog migration the owner approved** (KA-14, 3 October 2026):
  `2026_10_03_120000_register_analytics_security_catalog`. Codes that reach tenant roles cannot be renamed
  silently. Routes name their gate with `CoreSecurityCatalog::gate(...)` (explore page and `POST query`:
  `explore.invoke`; dashboards, widgets, saved queries, and the dataset catalog: `dashboard.read`, creating
  also `dashboard.create`); add a `CoreSecurityCatalog` constant only when code uses the code. Only the Owner
  role holds the duties automatically (PQ-04). Read access to a dataset is the module's existing read
  permission (KA-15): modules add no permission codes for analytics.
- **Names in code are English; user-facing text is plain Indonesian.** Screens never show `dataset`,
  `measure`, `tenant`, or other architecture words; they say Dasbor, Analisis, Kolom, Nilai, Saring, Bagikan,
  Arsipkan. No row is deleted physically; archiving fills `deleted_at` (the only exception is expired
  `analytics_query_cache` rows, which are computed copies).

## Declaring a dataset

1. Read the module's list controller for the resource. The permission it checks and the way it calls
   `OrganizationScope` (`asetQuery`, `query` with which columns, or `legalEntityQuery`) decide
   `permission()` and `dataPolicy()`. Never infer policy columns from names. Use the module's exact
   policy code (the asset policy is spelled `asset-responsibility` on purpose; renaming it silently drops
   tenants' grants).
2. Write one class in `modules/<publisher>/<module>/src/Analytics/` implementing
   `Contracts\Analytics\Dataset` (`moduleId()`, `definition()`; the definition must not read the database or
   request). Working examples: `AssetRegisterDataset` (model source, `fieldsFromModel()`),
   `DepreciationEntriesDataset` (query source), `DisposalDataset` (shared base class).

   ```php
   return DatasetDefinition::make('management-aset.asset-register', 'Register aset')
       ->model(Aset::class)
       ->permission('management-aset.aset.read')
       ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
       ->fieldsFromModel(except: ['keterangan'])
       ->reference('group_aset_id', GroupAset::class)
       ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
       ->time('acquired_on', default: true)
       ->measure('count', 'Jumlah aset', Aggregate::Count)
       ->measure('acquisition_value', 'Nilai perolehan', Aggregate::Sum,
           field: 'acquisition_value', format: MeasureFormat::Money, currency: 'currency_code')
       ->version(1);
   ```

3. Register it in the module's `ModuleServiceProvider::boot()` next to the report provider:
   `$this->app->make(Datasets::class)->register($this->app->make(AssetRegisterDataset::class));`
   `Datasets` is a singleton in `CoreServices::SINGLETON_BINDINGS`; bound plain, every registration lands in
   a throwaway copy and Core sees an empty list without any error.
4. Add `tests/Feature/Analytics/<Name>DatasetTest.php` with the `ProbesAssetDatasets` and
   `ChecksMoneyPerCurrency` traits (`tests/Concerns/`): tenant isolation, policy parity against the module's
   list endpoint for four users (whole organization, unit A, unit B, no grant), 403 without the read
   permission, and money per currency. Seed so a wrong policy column shows up as wrong rows (the opposite
   unit on the financial-dimension column), then break the policy column once and watch parity go red.
5. Run `php artisan analytics:datasets` (it prints why a dataset is rejected and exits non-zero) and the
   Boundary suite (`AnalyticsDatasetsBoundaryTest`). No manifest, catalog, or migration change is needed;
   the validator reads permissions and data policies from the module's merged manifest, not the database.

Choose `fromQuery()` only when a table plus joins cannot say it (currency or unit in another table, a value
computed from several columns). Build the source with `SourceQuery::from(Model::class)`, not
`Model::query()`: the contract takes `Builder<Model>` and static analysis rejects `Builder<ConcreteModel>`
(the template parameter is not covariant); the result is identical. The source must select `tenant_id`, must
keep the model's tenant scope, uses no `join()`, and every field is declared with `field()` plus its
classification. One dataset has one permission: split resources guarded by different permissions even when
they share a table (`asset-sales` and `asset-scraps`).

Free text, names, and pseudonymous ids are excluded with `except` (high cardinality, often personal data);
a numeric column is a measure only if declared; `Count` counts rows, `CountDistinct` counts things.

Changing a dataset: adding is free; renaming a key needs `version(n+1, renamed: [...])`; removing a
key needs `version(n+1)` and leaves affected widgets reading "column no longer available"
(`field_removed`). Changing a policy column moves the visible rows exactly as it would on the module's list
screen, so ship it with the parity test.

## SQL generation rules

- Never alias the base table: `TenantScope` is injected with the real table name. Query-source datasets
  use the engine's `SOURCE_ALIAS`, and the outer query filters `tenant_id` again.
- Joins always carry `alias.tenant_id = <base>.tenant_id`; data joins add `alias.deleted_at IS NULL`;
  label joins include archived rows so archived masters keep their names. Every join is a `LEFT JOIN`
  with its conditions in `ON`, attached only when the query names one of its columns, so adding a
  grouping never changes the row count.
- Select dimensions as `d0, d1…` (with `d0_label` for module references), implicit currency/unit as `c0…`,
  measures as `m0…`, and group and order **by alias**. A parameterised expression repeated in `GROUP BY`
  becomes a different parameter and PostgreSQL rejects the grouping. Join aliases of the form letter+digits
  are reserved by the engine and rejected in datasets.
- Time buckets: `date` → `date_trunc(g, col)::date`; `timestamp` (UTC, the repo default) →
  `date_trunc(g, (col at time zone 'UTC') at time zone '<tz>')::date`; `timestamptz` →
  `date_trunc(g, col at time zone '<tz>')::date`. The zone is a literal checked against
  `DateTimeZone::listIdentifiers()`. Never `to_char()` a `timestamptz` — it uses the session zone. Weeks start
  on Monday, written explicitly. Relative ranges (`@this_month`…) are computed in the user's zone from
  `principal->now()`.
- Measure filters compile to `FILTER (WHERE …)` with bindings; `sum` is wrapped in `coalesce(…, 0)` —
  around the filter, `coalesce(sum(x) filter (where …), 0)`, since `FILTER` belongs to the aggregate call;
  `avg/min/max` stay null on empty groups.
- `NULLS LAST` cannot go through `orderBy()`: a descending sort on a nullable column (any dimension;
  `avg/min/max` measures) is preceded by an `IsNullExpression` key repeating the column's expression,
  because PostgreSQL does not accept an output alias inside an `ORDER BY` expression.
- Totals reuse the same filtering steps, group only by the measures' currency/unit columns under the
  same aliases as the result, and ignore `limit`.
- Gap filling (`GapFiller`) is PHP after the query: zero for `count`/`sum`, empty for `avg/min/max`, per
  combination of the other dimensions, and skipped when the result is truncated, the first sort is not the
  period, or the fill would exceed the point or row limit.
- User filter values go through `FieldFilterExpression::apply()` (K-30 BC syntax) — bindings only. A filter
  on an unknown column is rejected, never ignored.
- No raw SQL built from identifiers: Laravel's `selectRaw`/`orderByRaw`/`groupByRaw` take
  `literal-string`, and Larastan rejects interpolated column names and aliases. Select columns with
  `addSelect('<table>.<column> as d0')`, group and order with `groupBy()`/`orderBy()`, and build
  aggregates as grammar-aware `Expression` objects passed to `selectExpression()` (`MeasureExpression`,
  `TimeBucketExpression`, `IsNullExpression`).
- Decimals leave the server as strings; `count` as an integer.

## Dashboards and storage

- Tables `analytics_dashboards`, `analytics_widgets`, `analytics_saved_queries` are tenant tables with
  audit columns, `version`, and the three triggers; migrations carry them directly because admin.erp runs
  Core migrations without `App\` classes.
- Sharing rules live in `Dashboards\DashboardAccess` (copied from report presets, K-25): private is owner
  only (others 404), shared is visible to every `dashboard.read` holder and editable with
  `shared-dashboard.update`; visible but not editable answers 403 with the reason.
- Path ids resolve only inside the active tenant through `Models\BindsWithinActiveTenant` (another tenant's
  id is 404 before the controller runs). `DashboardAccess` deliberately does not compare tenants again, so
  that `AnalyticsTenantIsolationTest` can prove the single guard red. Do not "fix" it by adding a second check.
- Every change claims the row version (`RowVersion::claim`: 428 without a version, 409 for a stale one). The
  version rises twice on a write that touches columns; use the `ETag` from the response.
- Widgets are validated on save against the current dataset and the **saver's** rights
  (`StoredQuery::validate()`, `WidgetDefinition`): unknown `visual` parts are rejected, not ignored. The
  saver's rights are not stored. A title or TTL change does not re-validate the query, so a widget whose
  column vanished can still be renamed or archived. Reading maps renamed keys through `CompiledDataset::renamed()`
  and reports `field_removed` or `dataset_unavailable` instead of a 500.
- Widget count per dashboard is checked under the dashboard row lock; layout accepts only that dashboard's
  widgets, widths from `DashboardController::WIDTHS`, 12 columns.
- Archiving a dashboard archives its widgets; no row is deleted physically.

## Screens

- Core pages under `resources/js/pages/platform/analytics/`; no `AppLayout` wrapper, breadcrumbs via
  `Page.layout`, a sidebar entry guarded by the dashboard permission, verified by walking the rail.
  `DashboardApiTest::test_dashboard_pages_render_their_components` goes red when a page component is missing
  from the Vite manifest (a missing component answers 500 as a full page).
- Charts use `@apperp/ui/chart` (Recharts, already installed) with `--chart-1…5` colours, loaded lazily. Every
  chart has a table fallback and an `aria-label` summary; colour is never the only signal.
- Grid widths come from a full class map (`lg:col-span-6`), never `col-span-${w}` — Tailwind never sees
  a composed class.
- All analytics number formatting goes through `resources/js/lib/analytics/format.ts` (`Intl` `id-ID`,
  compact "Rp 1,3 M"). It writes whole values without decimals and fractional values with full decimals
  (`Rp 240.500.000,50`) because Chrome and Node disagree on rupiah fraction digits.
  "Dihitung pukul …" uses `meta.timezone` and prints the zone.
- Widgets load when visible, fail individually with an actionable message, and show when their data
  was computed. Widget menus say Arsipkan, not Hapus.
- Overlays pass `portalContainer` to every `Select`; URL-owned state (explorer query, slicers) is
  derived from the URL each render, never copied into `useState`.
- Widget text is plain text: never render it as HTML or Markdown.
- The repo has no JavaScript unit-test runner. Prove screens with types, lint, build, and the rebuilt
  runtime (`D:\Kerja\erp-dev\start.ps1 -Build`); a preview with a throwaway database is acceptable when the
  shared runtime belongs to another session.

## Outside CoreERP (phase 2, plan only — no code yet)

- Publications reference a saved query or dashboard, list allowed integration clients, carry locked
  filters, and may suppress groups smaller than *k*. `core.analytics.publication.*` exists in the catalog but
  nothing uses it.
- Callers outside read **publications only** (KA-11), via integration clients with scopes
  `analytics.read` / `analytics.embed`. Every outside surface is hand-written in
  `apps/core/contracts/internal/integrasi-analitik.yaml`; after route or contract changes run
  `python contracts/bundle.py` then `python contracts/check-contract-coverage.py` from `apps/core`. The
  screen API under `api/v1/analytics` is Core-UI-only, so it is not an `internal/v1` contract.
- OData accepts Basic (`client_id:secret`) and a Web API key in the query string only on OData routes;
  the key is stripped before logging and scrubbed from proxy and error-report traces.
- Embed tokens: 32 random bytes, stored as sha256, short-lived, bound to one publication, one origin, and
  locked parameters; delivered by URL fragment then `postMessage`; the embed route is outside the `web`
  group (no session, no cookies) and sends `frame-ancestors` for registered origins only.
- Formulas are a small closed language compiled to SQL (KA-19), not DAX. `compare` and `formulas` are
  rejected as "not available yet" until then, never silently ignored.

## Tests that exist

`AnalyticsBoundaryTest`, `AnalyticsDatasetsBoundaryTest`, `DatasetValidatorTest`, `DatasetRegistryTest`,
`QueryShapeSyncTest` (schema, parser, TypeScript, and token lists agree), `WalkingSkeletonTest` (tenant,
permission, policy parity, money per currency, rollback to savepoint, zones), `PersonalDataGateTest`,
`DataPolicyFilterTest` (parity with the module's `OrganizationScope`), `AnalyticsTenantIsolationTest`,
`SharedDashboardRunsAsViewerTest`, `DashboardApiTest`, `AnalyticsCacheIsolationTest`, `QueryCacheTest`,
`QueryLimitsTest`, `QueryLogTest`, `ScopeFingerprintTest`, and per-dataset `<Name>DatasetTest` in the module.
From phase 2 add: publication suspension, empty locked filter at publication level, `$filter` injection, key
scrubbing, and embed token tests. Each one is seen red once before it is trusted
(`docs/dev/25-standar-penjaga-dan-pengujian.md`); record how you broke the guard in the pull request.

## Commands

From `apps/core` in your worktree (see the environment notes in the repo instructions: PHP and npm through
PowerShell, one test run at a time per database):

- One file or folder: `php -d memory_limit=1G vendor/phpunit/phpunit/phpunit tests/Feature/Platform/Analytics`
  (also `tests/Unit/Platform/Analytics`, `tests/Feature/Boundary`, and the module's `tests/Feature/Analytics`)
- Before calling it green: `composer test:fast` (parallel stage, then `--group=serial`), `composer lint:check`,
  `composer types:check`
- Screens: `npm run format:check`, `npm run types:check`, `npm run lint:check`, `npm run build`, `npm run bundle:check`
- Datasets and plans: `php artisan analytics:datasets`;
  `php artisan analytics:explain --query='<json>' --tenant=<id> --user=<email>` prints the compiled SQL and
  `EXPLAIN` (no `ANALYZE`) as that user. It takes no widget argument yet: paste the widget's stored `query`.
- Docs and skills: `npm run docs:build` from `docs/`, `python .github/scripts/check-skill-copies.py` from the
  repo root (both skill copies must match).

## Pitfalls

Each of these cost time while building the engine.

- Treating a numeric column as a measure. Only declared measures exist.
- Guessing the policy column (`financial_dimension_org_unit_id` instead of `responsible_org_unit_id`
  shows other units' assets). The parity test exists to catch exactly this.
- Filtering `tenant_id` by hand on a `BelongsToTenant` model: the query stays right even if the trait
  is removed, so the guard stops being measurable.
- Committing a read query, which leaves `READ ONLY` and `statement_timeout` active for the rest of an
  outer transaction.
- Caching in Laravel's cache store, which copies tenant data into the central database.
- Letting `count` stand for "distinct things": it counts rows; use `CountDistinct`.
- Registering datasets on a plainly bound `Datasets`: registrations vanish without an error.
- Registering routes conditionally behind a switch: Wayfinder and `route:cache` read the route list, so
  environments diverge. Gate with middleware instead.
- Testing the cache with a PHPUnit stub principal: `fingerprint()` returns `''` for every stub, so
  different reaches share entries and isolation tests pass for the wrong reason. Use a real principal
  (`tests/Feature/Platform/Analytics/Support/TestPrincipal.php`).
- Binding a gzip string to a `bytea` column: PostgreSQL rejects it as invalid UTF-8. Bind a stream
  (`PARAM_LOB`), and read it back with `stream_get_contents()`.
- Laravel's `DatabaseLock` fails inside a PostgreSQL transaction when the lock is contested. Slots and
  stampede locks run outside one; remember it if a phase 3 job calls `RunQuery` inside a transaction on the
  same database.
- A slot lease shorter than two statement timeouts plus a few seconds: one query runs two statements (result
  and totals), and a lease that expires mid-query frees the slot exactly when the server is busiest.
- Logging a filter value without masking it: `QueryLog::masked()` hides values on personal, person-id,
  and unknown fields, and the log hash is taken from the masked form.
- Believing a guard that never failed: `catch` blocks returning `null` or `[]` are rejected by
  `CatchTidakMemalsukanHasilTest` (the registry checks for a missing table instead of catching), and
  `AnalyticsBoundaryTest` reads comments, so a module table name in a docblock fails it.
- A new Core migration that uses an `App\…` class: admin.erp runs Core migrations too. After a new
  migration run all of `tests/Feature/Boundary` and the `apps/control-plane` migration test.
- `ConvertEmptyStringsToNull` turns an emptied filter in a JSON body into `null`; the parser reads `null`
  as empty, or the screen gets a 422 for a field the user cleared.
- Filtered measures and personal data: the gate checks a measure's source column only when it is a dataset
  field. A `min`/`max` over a column excluded from the field list has no classification and is not checked
  (no dataset does this today); keep it that way until the validator closes the gap.
- Expecting Intl to format rupiah the same in every runtime. Always use `lib/analytics/format.ts`.
- Running two PHPUnit runs at once on the shared PostgreSQL: `out of shared memory` and missing relations are
  contention, not a code defect. Drop the per-process test databases and rerun one at a time.
