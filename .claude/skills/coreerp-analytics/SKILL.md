---
name: coreerp-analytics
description: Build and change CoreERP's analytics engine — module datasets, the JSON query compiler, dashboards and widgets, publications, the OData feed, and embeds. Use when declaring or changing a module's analytics dataset, touching anything under App\Platform\Analytics or App\Platform\Modules\Contracts\Analytics, building analytics screens, or exposing analytics data outside CoreERP.
---

# CoreERP analytics

CoreERP's own Power BI–style engine: modules declare datasets, Core compiles a JSON query into SQL
against module tables, users build dashboards without code, and data leaves CoreERP only through
publications (JSON/CSV, OData v4, embeds).

**Status.** Area 0 (walking skeleton) shipped on 3 October 2026: the dataset contract
(`App\Platform\Modules\Contracts\Analytics`), `DatasetRegistry`, the query parser, validator,
compiler, and read-only executor, `Actions\RunQuery`, `POST /api/v1/analytics/query`, and a temporary
`/analytics/explore` page, all behind `analytics.enabled`. Area 1 (4 October 2026) completed the
dataset contract (`join()`, `reference()`, `shared()`, `fromQuery()`, `field()`, `version()`, …),
`DatasetValidator` (two stages: `declare()` without a database, `compile()` per database),
`SharedDimensions` with label resolvers (Foundation features register vendor and currency), the
`contoh-a` fixture datasets for engine tests, `AnalyticsDatasetsBoundaryTest`, and
`php artisan analytics:datasets`. Everything else is still a plan. The plan,
its decisions (`KA-xx`), and the work areas live in `docs/todo/analitik/`. Once an area ships, its code
and `docs/dev/35-analitik.md` are the authority, and area 11 rewrites this skill to describe what exists.
If code and this skill disagree, trust the code and fix the skill in the same pull request.

## Read first, by task

| Task | Read |
| --- | --- |
| Any analytics work | `docs/todo/analitik/README.md` (decisions), `docs/todo/analitik/TODO.md` (rules, shared files) |
| Declaring a dataset in a module | `docs/todo/analitik/model-semantik.md` |
| Compiler, executor, results, formulas | `docs/todo/analitik/mesin-query.md` |
| Permissions, data policy, personal data | `docs/todo/analitik/keamanan.md`, and the data policy gate in `coreerp-architecture` |
| Dashboards, widgets, screens | `docs/todo/analitik/dasbor-dan-visual.md`, then `coreerp-ui` and `coreerp-page-standard` |
| Publications, OData, embeds | `docs/todo/analitik/akses-luar.md`, then the contract decision gate in `coreerp-architecture` |
| Cache, limits, load test | `docs/todo/analitik/kinerja-dan-uji-beban.md` |

## Boundaries that never bend

- **Core may read module tables directly, but only through datasets the module registered.** Owner's
  decision, 3 October 2026 (`docs/dev/02-module-standard.md#ownership-dan-data`). No module table or
  column name is ever written in `App\Platform\Analytics`; `AnalyticsBoundaryTest` rejects `Modules\`
  names and module table prefixes there.
- **A module never reads another module's tables**, and a dataset class references only its own
  models plus `App\Platform\Modules\Contracts`. Cross-module analysis goes through shared dimensions
  (drill-across: aggregate each dataset by the same shared dimension, merge on its values), never a
  row join across modules.
- **Every read is tenant-filtered, policy-filtered, then user-filtered, in that order.** Tenant comes
  from the model's `BelongsToTenant` scope, which fails closed, so run queries inside
  `TenantRunner::runFor()` — Core routes do not pass `ResolveModuleContext`. Data policy goes through
  `DataPolicyFilter::apply()` on the columns the dataset declared. User filters only narrow.
- **Every query runs read-only, time-boxed, and row-capped**: `set transaction read only`,
  `set local statement_timeout`, `LIMIT n + 1`, and `rollBack()` in `finally` — never commit.
  Rollback to a savepoint is what undoes the read-only flag when the engine runs inside another
  transaction (tests, jobs).
- **Money is never summed across currencies, quantity never across units.** The compiler adds the
  currency or unit column as an implicit dimension.
- **Personal data.** `EndUserIdentifiableInformation` fields are invisible without
  `core.analytics.personal-data.read` — as dimension, filter, sort, and drill column alike. They never
  appear in publications, the OData feed, or embeds. `AccountData` never enters a dataset.
- **Who the query runs as.** Shared dashboards run as the viewer. Publications run as their current
  owner narrowed by locked filters, re-checked on every request. Embeds additionally narrow by the
  parameters locked into the token. An empty locked filter means zero rows, never "all".
- **Cache lives in the tenant database** (`analytics_query_cache`), never in Laravel's cache store,
  which `EnvironmentConnection::pins()` points at the central database. The key includes tenant,
  dataset definition hash, normalized query, scope fingerprint, time zone, and today's date.
- **Outside callers read publications only** (KA-11), via integration clients with scopes
  `analytics.read` / `analytics.embed`. Every outside surface is hand-written in
  `apps/core/contracts/internal/integrasi-analitik.yaml`.
- **Permission codes come only from the catalog migration the owner approved** (KA-14, 3 October
  2026), written in area 4.6 with exactly the codes in `keamanan.md`. Codes that reach tenant roles
  cannot be renamed silently. Until area 4.6 lands, the engine sits behind `analytics.enabled`.

## Declaring a dataset

1. Read the module's list controller for the resource. The permission it checks and the way it calls
   `OrganizationScope` (`asetQuery`, `query` with which columns, or `legalEntityQuery`) decide
   `permission()` and `dataPolicy()`. Never infer policy columns from names.
2. Write one class in `modules/<publisher>/<module>/src/Analytics/` implementing
   `Contracts\Analytics\Dataset`:

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
4. Add `tests/Feature/Analytics/<Name>DatasetTest.php`: tenant isolation, policy parity against the
   module's list endpoint, money per currency, filtered measures, time-zone bucket boundaries. See each
   test fail once by breaking what it guards.
5. Run `php artisan analytics:datasets` (it prints why a dataset is rejected and exits non-zero) and
   the Boundary suite (`AnalyticsDatasetsBoundaryTest`). No manifest, catalog, or migration change is
   needed; read access reuses the module's existing read permission (KA-15), and the validator reads
   permissions and data policies from the module's merged manifest, not the database.

Changing a dataset: adding is free; renaming a key needs `version(n+1, renamed: [...])`; removing a
key needs `version(n+1)` and leaves affected widgets showing "Kolom … sudah tidak tersedia".

## SQL generation rules

- Never alias the base table: `TenantScope` is injected with the real table name.
- Joins always carry `alias.tenant_id = <base>.tenant_id`; data joins add `alias.deleted_at IS NULL`;
  label joins include archived rows so archived masters keep their names.
- Select dimensions as `d0, d1…`, implicit currency/unit as `c0…`, measures as `m0…`, and group and
  order **by alias**. A parameterised expression repeated in `GROUP BY` becomes a different parameter
  and PostgreSQL rejects the grouping.
- Time buckets: `date` → `date_trunc(g, col)::date`; `timestamp` (UTC, the repo default) →
  `date_trunc(g, (col at time zone 'UTC') at time zone '<tz>')::date`; `timestamptz` →
  `date_trunc(g, col at time zone '<tz>')::date`. The zone is a literal checked against
  `DateTimeZone::listIdentifiers()`. Never `to_char()` a `timestamptz` — it uses the session zone.
- Measure filters compile to `FILTER (WHERE …)` with bindings; `sum` is wrapped in `coalesce(…, 0)`;
  `avg/min/max` stay null on empty groups.
- User filter values go through `FieldFilterExpression::apply()` (K-30 BC syntax) — bindings only.
- No raw SQL built from identifiers: Laravel's `selectRaw`/`orderByRaw`/`groupByRaw` take
  `literal-string`, and Larastan rejects interpolated column names and aliases. Select columns with
  `addSelect('<table>.<column> as d0')`, group and order with `groupBy()`/`orderBy()`, and build
  aggregates as grammar-aware `Expression` objects passed to `selectExpression()` (`MeasureExpression`).
- Decimals leave the server as strings.

## Screens

- Core pages under `resources/js/pages/platform/analytics/`; no `AppLayout` wrapper, breadcrumbs via
  `Page.layout`, a sidebar entry guarded by the dashboard permission, verified by walking the rail.
- Charts use `@apperp/ui/chart` (Recharts 3.8, already installed) with `--chart-1…5` colours. Every
  chart has a table fallback and an `aria-label` summary; colour is never the only signal.
- Grid widths come from a full class map (`lg:col-span-6`), never `col-span-${w}` — Tailwind never sees
  a composed class.
- All analytics number formatting goes through `resources/js/lib/analytics/format.ts`
  (`Intl` `id-ID`, compact "Rp 1,3 M").
- Widgets load when visible, fail individually with an actionable message, and show when their data
  was computed.
- Overlays pass `portalContainer` to every `Select`; URL-owned state (explorer query, slicers) is
  derived from the URL each render, never copied into `useState`.
- The repo has no JavaScript unit-test runner. Prove screens with types, lint, build, and the rebuilt
  runtime (`D:\Kerja\erp-dev\start.ps1 -Build`).

## Outside CoreERP

- Publications reference a saved query or dashboard, list allowed integration clients, carry locked
  filters, and may suppress groups smaller than *k*.
- OData accepts Basic (`client_id:secret`) and a Web API key in the query string only on OData routes;
  the key is stripped before logging and scrubbed from proxy logs and SigNoz traces.
- Embed tokens: 32 random bytes, stored as sha256, 600 s, bound to one publication, one origin, and
  locked parameters; delivered by URL fragment then `postMessage`; the embed route is outside the `web`
  group (no session, no cookies) and sends `frame-ancestors` for registered origins only.
- After route or contract changes: `python contracts/bundle.py` then
  `python contracts/check-contract-coverage.py` from `apps/core`.

## Tests that must exist

`AnalyticsBoundaryTest`, `AnalyticsDatasetsBoundaryTest`, `AnalyticsTenantIsolationTest`,
`<Dataset>PolicyParityTest` per policy-bound dataset, `PersonalDataGateTest`,
`SharedDashboardRunsAsViewerTest`, `AnalyticsCacheIsolationTest`, executor rollback/timeout/read-only
tests, time-zone boundary tests, and — from phase 2 — publication suspension, empty locked filter,
`$filter` injection, key scrubbing, and embed token tests. Each one is seen red once before it is
trusted (`docs/dev/25-standar-penjaga-dan-pengujian.md`).

## Commands

From `apps/core` in your worktree:

- One file or folder: `php -d memory_limit=1G vendor/phpunit/phpunit/phpunit tests/Feature/Platform/Analytics`
- Before calling it green: `composer test:fast`, `composer lint:check`, `composer types:check`
- Screens: `npm run format:check`, `npm run types:check`, `npm run lint:check`, `npm run build`, `npm run bundle:check`
- Datasets and plans: `php artisan analytics:datasets`, `php artisan analytics:explain`

## Pitfalls

- Treating a numeric column as a measure. Only declared measures exist.
- Guessing the policy column (`financial_dimension_org_unit_id` instead of `responsible_org_unit_id`
  shows other units' assets). The parity test exists to catch exactly this.
- Filtering `tenant_id` by hand on a `BelongsToTenant` model: the query stays right even if the trait
  is removed, so the guard stops being measurable.
- Committing a read query, which leaves `READ ONLY` and `statement_timeout` active for the rest of an
  outer transaction.
- Caching in Laravel's cache store, which copies tenant data into the central database.
- Letting `count` stand for "distinct things": it counts rows; use `CountDistinct`.
- Rendering widget text as HTML or Markdown.
