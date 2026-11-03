# Receipt write-chain grounding

This reference supports the approved comparison of the actual PHP flow against native Bun+Hono, Bun+Elysia, Go, and Rust implementations. It describes source at `6b3cf5846fd4442c3e895cacf2ba58275f9dd75e`. It does not report a running system, measured query counts, or verified benchmark results. No server or database was accessed.

Throughput checkpoint is n/a for this read-only investigation. Analytics stays outside the task. The only file written by this explorer is this report.

## Endpoint contract

The runtime base is `/api/modules/management-aset/v1`. `routes/api.php:12` applies `konteks-module:management-aset`. Receipt routes are in `routes/api/asset-receipts.php:10-28`. These paths are relative to `modules/apperp/management-aset` unless a path starts with `apps/core` or `docs`.

| Operation | Actual contract |
| --- | --- |
| Create | `POST /penerimaan-aset`. Requires receipt `create` permission and `Idempotency-Key`. The key is 1-143 characters matching `[A-Za-z0-9._:-]+`. New success is 201 with `Location` and `{data: header-with-lookups-and-details}`. It does not call `show`, so it does not add `vendor`, `posting`, `cancellation`, or ETag. |
| Replay creation | Same key returns 200 with `Idempotent-Replayed: true` and the stored raw header. Replay precedes payload validation, includes archived rows, and checks organization scope. It does not compare a payload hash and does not include details. A changed body with an existing key therefore replays the old record. |
| Add or replace lines | There is no add-lines endpoint. `PATCH /penerimaan-aset/{id}` accepts the full required header and full `details` collection. It requires receipt `update`, a draft, and version. It deletes the old detail rows and recreates all lines in one transaction after claiming the header. |
| Complete | `POST /penerimaan-aset/{id}/selesaikan`, body `{version: n}` or valid `If-Match`. Requires `management-aset.aset.create`. Successful response is 200 from `show`, including posting state and ETag. Already completed returns 422. A concurrent caller that read draft but loses the same-version claim gets 409. This action is not a 200 success replay. |
| Read receipt | `GET /penerimaan-aset/{id}` requires receipt `read`. Returns `{data: header, details, live vendor, posting status, cancellation summary}` and `ETag: W/"n"`. |
| Read generated assets | `GET /penerimaan-aset/{id}/aset` requires receipt `read`. Returns `{data: [{id,kode,nama,serial_number,penerimaan_aset_detail_id}],version:n}`, sorted by code. |
| Preview | `GET /penerimaan-aset/{id}/pratinjau-posting` requires receipt `read` and draft. It runs the same posting blockers, amount building, and Core posting preview evaluation as completion. |

Evidence is `src/Http/Controllers/transaksi/PenerimaanAset/PenerimaanAsetController.php:133-245,598-701,1251-1258,1383-1408` and `apps/core/app/Platform/Modules/Contracts/RowVersion.php:47-99,152-155`.

All mutations requiring a version accept the header in preference to the body. Header syntax is a quoted decimal, optionally `W/`; a missing or malformed version returns 428 with `version_required`. A stale claim returns 409 with `stale_version`. The controller reads a fresh version on response. A claim followed by a header update increments the version twice through the database trigger. Consumers must not assume a save means exactly `old+1`.

### Required and optional input

The common minimum body has `legal_entity_id`, `tanggal` as `Y-m-d`, `currency_code` of length 3, and at least one detail. It also needs `responsible_org_unit_id`, unless an explicit location or its nearest ancestor supplies `departemen_bawaan_id`. Each detail requires `nama` up to 150 characters, tenant-owned nonarchived `group_aset_id` and `jenis_aset_id`, integer `jumlah` from 1 to 500, and nonnegative numeric `nilai_per_unit`. `details` is normalized with `array_values`, including JSON objects with text keys. Price and tax become decimal strings. The controller trims trailing fractional zeroes before checking currency unit precision.

Optional header fields are `receiving_org_unit_id`, `tanggal_siap_pakai` on or after receipt date, the two user IDs, `lokasi_aset_id`, `keterangan`, acquisition method, vendor and invoice fields, and manual `purchase_order_reference`. Optional detail fields are condition, manufacturer, model, model number, tax, residual, opening accumulated depreciation and elapsed periods, per-book opening overrides, purchase-request detail link, attributes, and notes. PATCH omitted optional fields clear their stored values. PO reference is trimmed and empty becomes null.

The controller checks foreign module-master existence with tenant and `deleted_at IS NULL`. It additionally enforces an active eligible condition and active compatible model. It checks a vendor's tenant and legal entity through the Core directory. Despite the nearby comment, `validateVendor` itself does not reject a nonactive vendor. Unknown body fields do not become model fields.

Evidence is controller `:883-982,998-1088,1097-1122,1131-1184,1192-1247`. Exact constants are at `:74-95`.

## Create and line replacement

`store` runs permission, multipart payload merge, key validation, and a tenant-scoped replay lookup. A new request then runs validation, active-company and data-policy authorization, attribute and model validation, vendor lookup, and attachment validation. Uploading files also requires receipt `update`. It calls `AssetNumberSequenceIssuer::issue` before the header transaction with reference `management-aset.penerimaan-aset` and issue key `penerimaan-aset:<creation-key>`. This means header-number issuance is independently committed if a later header transaction fails.

Inside the transaction, `validateRequestLinks` checks linked purchase requests while locking request headers in sorted ID order. It saves the receipt header, one detail per input row, and optional attachments. A `QueryException` is replayed only if a receipt with this key can now be read; otherwise it propagates. POST rereads the decorated header and joined details after commit.

PATCH validates the whole document, checks the current and destination authorization, and opens a transaction. It validates linked requests, claims the receipt version, updates the draft header, deletes detail rows, inserts replacement detail rows, and optionally appends files. The detail model has no `SoftDeletes`; the actual replacement DELETE is physical. That is current implementation behavior even though the repository's general rule says deletion is soft. Do not silently replace baseline behavior in only one candidate.

Evidence is controller `:151-245,795-818,1192-1258` and `src/Models/transaksi/PenerimaanAset/PenerimaanAsetDetail.php:45-77`.

### Create query leaves

The following is a static inventory, not a measured count. Validation rules, middleware, warm caches, and trigger work determine actual SQL execution counts.

| Stage | SQL or storage work |
| --- | --- |
| Replay | `aset_tr_penerimaan_aset` by tenant and creation key, including deleted rows. |
| Existence validation | Individual tenant-scoped checks for group, type, and optional location, condition, manufacturer, model, or request detail. Request detail check includes an organization-filtered request-header subquery. |
| Currency | `currency_precisions` for tenant and currency. Only IDR has an explicit fallback of amount precision 2 and unit precision 3. |
| Default department | If no responsible unit was supplied, `aset_m_lokasi_aset` ancestry lookups for `departemen_bawaan_id`. |
| Condition | If condition supplied, type-condition link existence and eligible active condition existence. |
| Attributes | Type-attribute link IDs, optional sorted `FOR UPDATE` lock of attribute types, joined active definitions, and optional choices. These run when attributes were omitted too. An empty definition set is a valid result. |
| Model | If model supplied, selected model fields and existence of another active model tied to this asset type. |
| Vendor | If vendor supplied, Core `vendors` plus eager-loaded `parties`. |
| Header numbering | Full Core sequence chain below, including issuance and audit. |
| Linked request | If supplied, request details, sorted locked request headers, and reread request details. |
| Persist | Header INSERT, one detail INSERT per input row. Tenant trait fills omitted detail tenant IDs. Triggers stamp audit fields and may write change logs. |
| Attachments | Optional file storage, `document_attachments` metadata, rollback cleanup, and record permission checks. |
| Response | Header plus location join and two detail aggregate subqueries, organization-name lookup, optional member lookup, and details query with request and master joins. |

## Completion transaction

1. `selesaikan` checks asset-create permission, reads the authorized decorated receipt, checks draft, obtains version, and rechecks scope. It SELECTs all detail rows in line order. An empty set is 422.
2. `assertSisaPermintaan(...,false)` checks linked-request remaining quantities without locks. `AcquisitionPosting::blockers` checks currency register precision, method and cutover or payable vendor, and one eligible posted book for each group.
3. For every line, `lineAmounts` rounds the extended acquisition amount and tax once, resolves the posted book and opening values, then splits each rounded amount across the generated units. It constructs creation keys `penerimaan:<receipt-id>:<line-number>:<unit-number>`.
4. It opens the receipt transaction. The linked-request check repeats while locking request headers in sorted ID order. The receipt version claim is a conditional no-op UPDATE that acquires the row lock. The trigger increments its version. A separate draft-only UPDATE changes status to `selesai`, causing another increment.
5. `issueAssets` runs inside this same transaction. It obtains sequence context fields under a shared lock, resolves contextual codes under shared locks, sorts by the JSON context rank and original position, and issues one Core number for each key `aset:penerimaan:<receipt-id>:<line-number>:<unit-number>`.
6. `PembuatAset::buat` runs once per unit. It validates condition, reads group defaults, resolves location dimensions, inserts the asset, and inserts initial placement. Asset state is `received`, `is_budgeted` is false, serial is null, responsible unit comes from receipt responsible unit, and acquisition value is the exact allocated amount.
7. For each asset it SELECTs receipt code and sets transaction-local `coreerp.fa_ledger_context`. It reads the active group/book matrix, checks profiles only when this asset/book actually depreciates, computes the start date, and inserts each asset book with snapshot configuration and opening amounts. The book INSERT runs the PostgreSQL ledger trigger below.
8. `simpanAtribut` rereads and validates database-defined attributes. It writes one typed attribute row per nonempty definition value and marks referenced attribute types `data_type_locked=true`.
9. `RecordDocuments::flow` always runs. It checks receipt read access, then SELECTs source attachment rows `FOR UPDATE` in ID order. If any exist, it streams generated asset records with original line numbers, reads existing inherited references including archived ones, and inserts only missing references in batches. The file contents are not copied.
10. `AcquisitionPosting::publish` constructs the journal again, calls Core's native `PostingFeed`, and stores it inside this transaction. Core refuses publication without a transaction. Failure of posting shape raises `InvalidPosting`, becomes `posting_failed` 500, and rolls back receipt state, asset codes, assets, placement, books, ledger, attribute writes, attachment references, and finance posting together.
11. The transaction commits. `show` executes full readback including live vendor, posting status, and cancellation summary.

Evidence is controller `:598-679,760-818,832-861`, `src/Services/AssetNumberSequenceIssuer.php:83-132`, and `src/Services/PembuatAset.php:50-115,141-159,200-260,323-389,402-491`.

The completion docblock says asset numbers are issued outside the transaction. Executable lines `:642-657` issue them inside. Use the executable source. A receipt with nonzero value does need a posted book even when its books do not calculate depreciation. `current` wins over another non-`none` posting layer, then book code breaks ties.

## Core services and their query leaves

| Module contract or call | Current owner | Work reached by this flow |
| --- | --- | --- |
| `NumberSequenceIssuer` | `apps/core/app/Foundation/NumberSequence/ModuleServices/NumberSequenceIssuerCore.php:33-50` | Delegates to `Actions/NumberSequenceService`. Context fields SELECT the sequence with reference-exists subquery and shared lock. |
| `CurrencyRounding` | `Foundation/Currency/ModuleServices/CurrencyRoundingCore.php` | `Support/MoneyPrecision.php:51-97,116-122`. Precision SELECT and exact `BigDecimal` HALF_UP math. Cache is request-scoped and cleared at outer transaction boundaries by `CurrencyServiceProvider.php:29-66`. |
| `FinancePostingSettings` | `Foundation/FinancePosting/ModuleServices/FinancePostingSettingsCore.php` | `Support/PostingSettings.php:27-51`. Effective settlement-mode SELECT and finance-setting SELECT. No setting means disabled feed and default `direct_payable`. |
| `PostingFeed` | `Foundation/FinancePosting/ModuleServices/PostingFeedCore.php:19-31` | `PostingPublisher` normalizes, resolves names/accounts/dimensions, evaluates status, and inserts posting/lines/event. |
| `VendorDirectory` | `Foundation/Vendor/ModuleServices/VendorDirectoryCore.php:71-88` | Tenant vendor SELECT plus party SELECT. Used by validation, journal building, Core normalization, and receipt readback. |
| `OrganizationDirectory` | `Platform/Organization/ModuleServices/OrganizationDirectoryCore.php:95-107,151-165` | All tenant operating units with operating-unit join for names; if user IDs supplied, tenant memberships joined to users. `AssetOrganizationDirectory` caches maps per instance. |
| `RecordDocuments` | `Platform/Attachments/ModuleServices/RecordDocumentsCore.php:38-119,134-169` | File validation, append/storage, and reference flow. The module-supplied `AssetAttachments` callback performs scoped receipt existence checks and permission checks, then generated-asset reads. |
| `CompanyContextFilter`, `DataPolicyFilter` | Platform contract classes via `src/Support/OrganizationScope.php:26-83` | Tenant filter alone is insufficient. Receipt reads and writes also enforce active legal entity and the module's `management-aset.asset-responsibility` grants. |
| `BelongsToTenant` | `Platform/Modules/Contracts/BelongsToTenant.php:48-68` | Adds fail-closed tenant query scope, fills missing tenant on save, rejects a supplied tenant different from trusted context. |

`CoreServices.php:74-82,104-125` enumerates contracts. Foundation feature providers bind their implementations. `FinancePostingServiceProvider.php:28-38` binds the posting services and singleton account-resolver registry. The module registers `PostingGroupAccountResolver` in `src/ModuleServiceProvider.php:168`, change-log value resolver at `:172`, and attachment record callbacks at `:177`.

There is no generic extension-data facade or configurable custom-field middleware called by receipt store, completion, or `PembuatAset` in this commit. Asset attributes are the active extension mechanism. They read `aset_m_jenis_aset_atribut`, `aset_m_tipe_atribut`, and `aset_m_tipe_atribut_nilai`, then write typed values in `aset_tr_atribut_aset`. Merely copying the input JSON into the asset row skips the implemented validation and locking chain.

### Number issue chain

`NumberSequenceService.php:42-118` wraps each issue in a transaction or savepoint and retries unique-query races up to three attempts. It SELECTs the active tenant sequence with reference, holding `FOR SHARE`, then resolves scope. Legal-entity scope SELECTs the active tenant organization and legal-entity relation. It SELECTs an existing issue by sequence, app, and idempotency key before allocating.

With preallocation on, it locks the counter first, then the oldest live allocation. When no counter exists, it inserts-or-ignores one and rereads it with `FOR UPDATE`. When the block is exhausted, it deletes exhausted allocations, inserts a new block, updates the counter to block-end+1, and inserts a `preallocated` audit event. It increments the allocation's next number, checks formatted-value collision, inserts `number_sequence_issues`, and inserts `number_sequence_audit_events` with event `issued`. These are durable shared database operations. There is no per-process counter. With preallocation off it first checks reusable numbers under lock, otherwise updates the counter directly.

Context values are sorted, validated against `[A-Za-z0-9_-]+` and segment lengths, and hashed into the scope key. Calendar or fiscal reset adds a period key; fiscal choices also read the Core fiscal calendar. Asset contexts are group, fiscal classification, effective initial location, and acquisition year. Missing a required contextual code makes numbering fail with 422 `number_sequence_failed`, not a fallback code.

Evidence is `apps/core/app/Foundation/NumberSequence/Actions/NumberSequenceService.php:410-496,553-631,696-771,915-927` and module issuer `:115-128`.

| Reference | Scope | Current declared initial format | Continuity and manual |
| --- | --- | --- | --- |
| `management-aset.penerimaan-aset` | Legal entity only | Prefix `PNRA`, default five-digit numeric segment and initial range 1-19999. Actual tenant settings own the format. | Normal `issue` requires non-continuous and automatic enabled. |
| `management-aset.asset-code` | Legal entity only, subdivided by configured context hash | `<classification>.<group>.<initial-location>.<acquisition-year>.<four digits>`, initial range 1-9999. `ASTA` is its declared prefix metadata. | Normal `issue` requires non-continuous and automatic enabled. |

Sources are `manifest/fixed-asset/asset-receipts.yaml:40-46`, `manifest/fixed-asset/assets.yaml:59-78`, `docs/dev/14-number-sequences.md:29-52,124-156`, and the number-sequence skill. The receipt and asset business indexes are partial unique indexes on `(tenant_id,legal_entity_id,kode)` in migration `2026_10_06_110000_scope_asset_and_receipt_numbers_to_legal_entity.php:12-22`. Issuer scope and document uniqueness must stay aligned. Existing creation keys remain tenant-wide.

### Journal chain

Module `AcquisitionPosting.php:100-116` rounds extended line amount and tax at currency amount precision. `:205-318` groups totals by group and financial-dimension unit. `:424-446` reads the effective posting-group mapping for the date. It creates acquisition debits, optional tax debits, and matching offset credits. Purchase credits payable in `direct_payable`, clearing account in `clearing`; grant credits grant offset; opening balance credits accumulated depreciation and opening-balance offset. Opening balance uses cutover as posting date and acquisition date as document date. Purchases use invoice date as document date when supplied.

Core `PostingPublisher.php:74-99` SELECTs existing posting by tenant and deterministic posting ID, normalizes even for replay, and compares input hashes on replay. Normalization SELECTs the tenant legal entity, company code, optional vendor+party, and precision. It rejects malformed IDs/type/dates, missing required vendor, fewer than 2 or more than 5000 journal lines, negative values, excessive money precision, both-sided or zero-sided lines, and unequal total debit/credit. Its hash includes journal kind, legal entity, currency, dates, mode, vendor ID, reversal/adjustment links, and ordered account/debit/credit/unit tuples. Description, detail snapshots, and timestamps are outside that hash.

`shape` always evaluates mapping and dimensions before status evaluation, including for disabled feed. It SELECTs finance reference accounts and organizations joined to operating units. `BusinessUnitResolver` SELECTs effective published management-hierarchy versions and then closure ancestors joined to operating units and organizations. It picks each hierarchy's nearest BU and requires every relevant hierarchy to agree on one BU. Balance-sheet accounts get BU; profit/loss accounts also need a numbered department.

Status becomes `manual` for disabled feed or pre-cutover posting, `held` for mapping/dimension problems, otherwise `pending`. Missing account mapping does not roll back completion. A new posting uses a savepoint and inserts one `finance_postings`, its `finance_posting_lines` in batches of 500, and one `finance_posting_events` event `published`. No HTTP delivery runs here. The synchronous finance feed rows are the durable publication record. There is no generic outbox INSERT or workflow-start call in the normal receipt chain.

Evidence is `PostingPublisher.php:373-519,535-700,789-877,953-994`, `FinancePostingEvent.php:47-65`, and `apps/core/app/Platform/Organization/Support/BusinessUnitResolver.php:29-119`.

## Trigger and readback work

Every audited tenant table gets actor stamp, row-version bump, and change-log triggers through `AuditColumns::attach`. `coreerp.user_id` is a connection-session setting. Native connection pools must establish and clear the correct actor per request. Changing a borrowed connection without those settings can preserve SQL success while losing or misattributing the audit trail. `coreerp.change_log` controls logging and must not be left off from fixture migration work.

Change-log SQL checks tenant-specific table settings, then default settings, and optionally field settings. When enabled it inserts one row per changed configured field into `change_log_entries`. Keeping the real PostgreSQL schema preserves this work automatically; reimplementing only the visible tables does not. Evidence is `apps/core/app/Platform/Modules/Contracts/AuditColumns.php:69-100`, `Platform/ChangeLog/Support/AuditActor.php:23-55`, and Core migration `2026_09_28_130000_create_change_log_tables.php:133-220`.

Each book INSERT also runs `aset_capture_book_ledger`. It SELECTs the asset, reads transaction-local `coreerp.fa_ledger_context`, calculates deltas, and inserts `aset_tr_ledger_aset`. A zero-value/zero-balance book creates no ledger row. Ledger rows are immutable by trigger. Current schema also checks `is_budgeted` consistency on asset writes and rejects books/ledger entries for budgeted assets. Evidence is module migrations `2026_10_13_120000_create_fixed_asset_ledger.php:47-91,123-128` and `2026_11_02_140000_add_budgeted_assets.php:27-54`.

Receipt readback is not a single-table fetch. `dokumen` joins location and uses two correlated detail aggregates. It resolves live organization/member names. `baris` joins request line, request header, group, type, condition, manufacturer, and model with tenant-aware joins. It decodes opening-book JSON and attribute JSON. `show` additionally looks up the live vendor, finance posting status when completed/cancelled, and latest cancellation even when none exists. The normal receipt flow starts no approval workflow; `AssetCancellationEngine` injects a workflow engine, but `summary` only reads cancellation and optional posting statuses. Evidence is controller `:133-148,1261-1380`, `AssetOrganizationDirectory.php:71-103`, and `AssetCancellationEngine.php:59-68`.

## Fixture profiles and parity boundary

The smallest valid positive configuration is a standalone receipt in clearing mode, one tenant/legal entity/responsible unit, one asset type, one group, one active non-`none` book and group/book row with depreciation disabled, valid currency precision, and working number sequences. The current asset-code format additionally needs a fiscal-classification code and an initial-location code. Missing account/BU setup makes posting `held`; disabled feed makes it `manual`. These configurations are valid business states, but they omit expensive paths and cannot prove the requested full positive business comparison.

A compact positive profile that exercises actual asset and journal work uses the following configurable fixture data:

- One active tenant, member, role chain, installed module, active selected legal entity, and scoped receipt-read/create/update plus asset-create permissions.
- One numbered BU and one numbered department in one effective published management hierarchy, with real placements and closures.
- One active party/vendor for that legal entity and effective mode `direct_payable`.
- Enabled finance feed with cutover not later than the posting date, explicit amount precision 2 and unit precision 3.
- One fiscal asset classification with valid code, group with that classification and a default or explicit location, and location with a valid initial code and finance dimension department.
- One active asset type. One active current book, one group/book row with `depreciate=true`, and one active straight-line monthly calendar profile with full-month convention and useful life 48.
- One effective posting group with active mapped acquisition, payable, and input-tax accounts. All fixture names, IDs, dates, codes, and account choices come from persisted fixture configuration.
- One required asset-type attribute with a value. This keeps definition reads, attribute-type locking, typed attribute persistence, and locking of data type in the positive workload.
- Three units at string price `333333.333`. The existing test pins acquisition total `1000000.00` and allocated values `333333.34`, `333333.33`, `333333.33`. A nonzero tax exercises the tax branch.

An optional condition link and model/manufacturer pair add their validation paths. Optional linked requests require a valid same-company request/detail and enough remaining quantity. A nonempty attachment exercises storage and inheritance. Neither optional workload is represented by an empty field plus an unconditional success. Separate conformance cases cover absent values too.

One profile cannot execute mutually exclusive purchase, grant, and opening-balance paths, or pending/held/manual states at once. Full parity needs a case matrix. The main run can share one declared profile across all five stacks, while conformance cases cover these choices:

| Choice | Valid cases or expected failure |
| --- | --- |
| Acquisition | Purchase/payable with vendor; purchase/clearing without vendor; grant; opening balance with cutover and per-book balances. |
| Posting state | Pending with all mappings/dimensions; held with missing/inactive/foreign account or unresolved dimensions; manual disabled or before cutover; no posting when every amount is zero. |
| Books | One current and optional nonposted book, no-depreciation, under-threshold, profile inheritance/override, alternative profile, opening values per book. No posted book blocks completion. |
| Numbering | Legal-entity scope, default context segments, optional no-context tenant configuration, preallocation on/off, rollover/refill, exhausted range. Continuous configuration cannot use this receipt's normal issue path. |
| Organization/location | Explicit responsible unit, inherited location department, inherited location dimension, fallback to responsible unit, cross-company denial and organization-grant denial. |
| Attributes | No definitions, required empty refusal, typed values, choices, unknown attribute refusal, one value copied to every generated asset. |
| Requests | Standalone, linked partial receipt, multiple lines sharing one request detail, concurrent remaining-quantity race, wrong company/type or cancelled request refusal. |
| Versions/replay | Creation race and changed-body replay, PATCH race, completion race, completion repeat, absent/invalid/stale version. |
| Attachments | Empty flow still queries source attachments; header attachment flows to all assets; line attachment flows only to corresponding units; no duplicate inherited references; rollback cleanup. |

The original `loadtest/k6/receipt-posting.js` has `race`, `adjust-race`, and `saturation`, with 2 units per line. Saturation also mixes opening balances, CSV import, and acquisition adjustments. It is not a full positive-posting fixture. `loadtest/README.md:263-268` explicitly says its postings are held because management hierarchy is absent, and it uses one book. Script `:264` disables depreciation. Its setup, profile-dependent requests, and correctness expectations must be treated as bounded evidence rather than complete portability.

### Exact minimal pending-purchase request

This is a JSON template. Every `${fixture...}` value is replaced with persisted fixture configuration before sending. The same HTTP/JSON request contract applies to PHP and all four native stacks. No tRPC or oRPC is part of the comparison.

```json
{
  "legal_entity_id": "${fixture.legal_entity_id}",
  "responsible_org_unit_id": "${fixture.department_id}",
  "tanggal": "${fixture.receipt_date}",
  "currency_code": "${fixture.currency_code}",
  "vendor_id": "${fixture.vendor_id}",
  "lokasi_aset_id": "${fixture.location_id}",
  "details": [
    {
      "nama": "${fixture.asset_name}",
      "group_aset_id": "${fixture.group_id}",
      "jenis_aset_id": "${fixture.asset_type_id}",
      "jumlah": 3,
      "nilai_per_unit": "333333.333"
    }
  ]
}
```

Send it to `POST /api/modules/management-aset/v1/penerimaan-aset` with `Accept: application/json`, `Content-Type: application/json`, authenticated session/CSRF context, and `Idempotency-Key: ${fixture.request_key}`. A 201 response supplies `data.id` and `data.version`. Complete with `POST /api/modules/management-aset/v1/penerimaan-aset/<id>/selesaikan` and body `{"version": <returned-version>}`. Read the same ID and its `/aset` path. For the requested add-lines stage, PATCH the entire returned input header plus the full desired details array and returned `version`, then complete using the PATCH response's new version.

For this minimal body to result in pending, setup supplies enabled current finance feed, direct-payable mode, vendor, valid hierarchy/BU dimension, mapped active acquisition/payable accounts, posted book, valid configured asset numbers, and valid classification/location codes. This body assumes no required attribute definition. The richer main profile above adds its required attribute into each detail and adds tax if its tax path is measured. Both choices execute the normal handlers; only the declared data differs.

### Existing feature-fixture methods

`tests/Concerns/MenerbitkanJurnalPenerimaan.php` provides the most direct positive-posting fixture pattern. These are test helpers, not production endpoints or a benchmark auth mechanism.

| Method and evidence | What it supplies |
| --- | --- |
| `siapkanJurnalPenerimaan`, lines 48-78 | Tenant/member owner, legal entity, BU and department, published management hierarchy, enabled feed/cutover, real Core vendor, and active reference accounts. |
| `organisasi`, lines 81-86 | Posts `/settings/organization/organizations`, then finds the persisted organization ID. |
| `hierarki`, lines 89-109 | Creates management hierarchy, posts placements with version, and publishes its first version. |
| `groupTanpaBuku`, lines 129-135 | Creates group through the module API with an idempotency key. |
| `group`, lines 112-126 | Adds one current book and matrix row. It disables depreciation and is therefore a positive-posting fixture rather than full profile-calculation coverage. |
| `petakan`, lines 149-159 | PUTs effective posting-group accounts with the current row version when present. |
| `kirimDraf`, lines 175-188 | Exact receipt request shape with legal entity, responsible department, date, currency, vendor, and details. |
| `baris`, lines 192-202 | Required detail fields plus optional tax. |
| `selesaikan`, lines 217-221 | Requires receipt-read and asset-create, posts the receipt version. |
| `PenerimaanAsetTest::groupSiapSusut`, lines 629-658 | Contrasting fixture with actual depreciation profile and a matrix row that calculates depreciation. |

Current default asset-code contextual classification and location are additional setup requirements beyond this older helper's minimal fixture. Do not copy a test's alternate number-sequence setup into the experiment without declaring the resulting tenant configuration. `sebagaiPengguna` helpers provide test context and must not replace actual auth/session/middleware execution in the PHP baseline.

## Oracle invariants

The oracle reads SQL independently of each API. The existing `loadtest/verify.sql:271-304,321-356,443-448` supplies useful receipt checks, but the comparison needs these complete assertions for its declared cases:

- A draft produces zero assets, placements, books, ledger entries, and finance postings.
- One successful completion creates exactly `sum(details.jumlah)` assets, each linked to the correct receipt and detail in the same tenant and legal entity. Each asset has exactly one initial placement and all active configured books.
- An unsuccessful completion leaves the receipt draft, the version unchanged, and no completion-side number issue, asset, book, ledger, attribute, inherited attachment, or posting. Header number issued at draft creation remains.
- A completion race has one winner. Losers return 409 or post-read completed 422, with no doubled rows. Linked-request races never over-receive quantity.
- Active receipt and asset codes are unique within tenant/legal entity. Same number in another legal entity is allowed. Creation keys replay per tenant, including cross-company collision behavior.
- Currency arithmetic uses exact decimal strings and HALF_UP once per extended line. Splits distribute smallest-unit remainder to first assets. Asset sums equal rounded line amounts. Receipt stores unit price at six decimals; register and ledger amounts use two decimals.
- A nonzero completed receipt has exactly one deterministic `AST-ACQ-<receipt-id>` or `AST-OPB-<receipt-id>` posting, with correct type, tenant, company, source, currency, mode, and dates. A zero-value receipt has none.
- Every journal line has exactly one nonzero side, sum debit equals sum credit, and amounts match register plus rounded tax. Opening journal accumulated balances match the selected posted book. Snapshot vendor/account/company/dimension names and codes match the fixture at publication.
- Posting status and exact problems match the configured state. Missing mapping permits completion as held. Invalid posting shape rolls back completion. Pending requires valid accounts and derived BU.
- Every nonzero created book has the expected immutable ledger entry, source receipt metadata, acquisition delta, opening accumulated delta, and net amount. Book start date, depreciation flag, profile snapshots, residual, life, and opening offsets match PHP.
- Typed attribute values and the data-type lock match definitions. Neither cross-tenant type IDs nor unknown attributes survive.
- Audit actor, row-version changes, and configured change-log entries belong to the caller. Pooled connections do not retain another actor, tenant, or local ledger context.
- POST, replay, PATCH, completion, receipt GET, and asset GET match their actual response shape, status, and headers. Use returned versions rather than synthetic increments.
- Explicit denial probes cover other tenant, active-company mismatch, out-of-scope unit, receipt-create without asset-create, and asset-create without receipt-read. Completion invokes show and attachment read authorization, so positive fixture needs receipt-read too.

The current tests pin many of these cases in `tests/Feature/PenerimaanAsetTest.php:52-74,158-231,242-301,301-356,409-475,502-569`, `tests/Feature/AcquisitionPostingTest.php:34-137,178-312`, and `tests/Concerns/MenerbitkanJurnalPenerimaan.php:48-124,165-220`. The tests were read, not run in this investigation.

## Blockers to an unqualified full-parity claim

The required native work includes Core sequence allocation, effective organization and account resolution, dynamic attribute validation, attachment callbacks, audit connection state, PostgreSQL triggers, decimal math, and response decoration. A candidate that calls PHP for any of those has not met native operation. A candidate that omits them has a smaller workload than PHP.

Exact query-count parity cannot be proved from this static map. Cache lifetime, transaction resets, fixture cardinality, route middleware, auth/session work, cold number blocks, and trigger configuration alter query execution. Capture a baseline query fingerprint/count per full operation on the exact fixture before benchmarking. Compare candidate traces against that operation contract. Reusing the same full PostgreSQL schema preserves ledger, audit, and relational checks without PHP in candidate operation.

The source contains contract/comment drift. The public YAML still names the former asset-number reference and describes preissued numbers, while executable code uses `management-aset.asset-code` within the completion transaction. Generic receipt conflict documentation also exceeds actual receipt payload-replay checks. Compare to current executable PHP behavior and list any chosen deviation explicitly. Do not quietly make only the candidates stricter.
