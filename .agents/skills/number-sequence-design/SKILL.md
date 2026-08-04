---
name: number-sequence-design
description: Design or audit CoreERP number-sequence references, prefixes, scopes, ranges, defaults, manifest declarations, automatic materialization, and UI previews. Use whenever creating or reviewing a CoreERP app/module that owns master data or transaction documents, even when the user has not explicitly mentioned numbering; also use for app manifests, seeds, Control Plane settings, internal number APIs, imports, and number-sequence UI.
---

# Number Sequence Design

## Start from zero context

1. Read `docs/dev/14-number-sequences.md`.
2. Read the owning app manifest. If it does not exist yet, inspect only the relevant domain docs, navigation, UI labels, API contracts, models, and migrations needed to identify business records.
3. Read the approved number-reference decision from `module-discovery`; do not reopen whether a record needs a number unless the design changed.
4. Treat each approved readable business identifier as a **reference**, following Dynamics 365 terminology.
5. Classify every candidate as:
   - **Master**: a reusable business record, such as an asset category.
   - **Transaction**: a document or event with a lifecycle, such as an order, receipt, journal, or invoice.
6. For every candidate, determine the reference code, business title, scope, prefix, continuity, manual-entry policy, reset period, range, numeric width, segments, and preview.
7. Group all unresolved prefixes or document policies into one concise question. Do not edit uncertain declarations and never invent an answer silently.
8. Declare every approved reference in the owning app manifest. Never hardcode app-specific reference names or prefixes in Core.
9. Register the manifest and verify that every declared reference materializes in the shared **Atur nomor** UI when the app is ready for the tenant.

`module-discovery` owns the decision whether a record needs a number. This skill owns the approved reference's technical design and Core configuration.

## Follow the Dynamics 365 model

Use these Microsoft Learn references when current behavior must be checked:

- [Number sequences overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/number-sequence-overview)
- [Set up number sequences using a wizard](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/tasks/set-up-number-sequences-wizard)
- [Set up number sequences individually](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/tasks/set-up-number-sequences-individual-basis)

Apply the same direction:

1. The owning app/module declares the references it requires.
2. Automatic generation gathers all declared references rather than guessing from database tables.
3. An administrator can review the generated code, minimum, maximum, continuity, scope, and format before use.
4. A declared reference can be deliberately excluded; absence must be an explicit decision, not an accidental omission.
5. A number sequence must be associated with its reference before that business record can request a number.
6. Scope controls independent counters even when the scope segment is not printed in the formatted number.
7. Prefer non-continuous numbering unless a regulatory or legal requirement demands gap prevention.
8. Do not convert a used non-continuous sequence into continuous. Create a new policy/sequence when the legal behavior changes.

Map that model to CoreERP:

| Dynamics 365 | CoreERP |
| --- | --- |
| Module number-sequence references | `number_sequences.references` in the owning app manifest |
| Generate wizard | Materialize all references when the registered app is ready for a tenant |
| Number sequences page | Shared Control Plane **Atur nomor** UI |
| Reference association | Manifest reference code used by the internal number API |
| Shared, Company, Legal entity, Operating unit | Tenant, legal entity, operating unit |
| Constant and alphanumeric segments | Constant, number, calendar/scope/fiscal segments supported by CoreERP |

Keep intentional differences visible:

- CoreERP's product default is active `0-19999` with a five-digit numeric segment; do not claim that this exact range is a Dynamics 365 default.
- CoreERP forbids continuous numbering together with manual entry.
- Dynamics 365 supports incrementing alphabetic `&` segments; CoreERP does not currently promise that behavior. Report the gap instead of emulating it with a prefix.
- CoreERP keeps tenant configuration and counters in Control Plane and uses durable database allocation; do not copy Dynamics 365's in-memory non-continuous cache design.

## CoreERP automatic defaults

Use these defaults when the user has not specified another approved policy:

```yaml
status: active
scope_type: tenant
is_continuous: false
allow_manual: false
reset_period: never
preallocation_enabled: true
preallocation_quantity: 20
minimum_number: 0
maximum_number: 19999
segments:
  - type: number
    length: 5
```

If an approved prefix exists, place it before the number segment:

```yaml
segments:
  - type: constant
    value: ENTA
  - type: number
    length: 5
```

Require the UI preview to show both endpoints, for example `ENTA00000 - ENTA19999`, before saving. Ensure the maximum fits the numeric width.

## Scope, reset, and lifecycle

- Choose the narrowest scope that owns uniqueness:
  - `tenant` when one sequence is shared across the business.
  - `legal_entity` when each legal entity owns its counter or legal series.
  - `operating_unit` only when each unit truly owns an independent series.
- Add a scope segment only when users need to recognize the scope from the formatted number. Counter separation does not require printing it.
- Keep `reset_period: never` for ordinary master data.
- Propose calendar or fiscal reset only when the document policy requires it, and include a year/period segment so values cannot collide after reset.
- Use separate references when lifecycle, legal requirement, scope, reset, or continuity differs.
- Do not change structural format, scope, reset, or continuity after numbers have been issued.

## Prefix rules

- Every reference must declare a prefix of exactly four uppercase letters (`A-Z`). A missing, shorter, longer, numeric, or mixed-case prefix fails the manifest audit.
- Build the first three letters from the user-facing business title, then append the one-letter app code. The app code is the same final letter for every reference in an app and is agreed as app vocabulary; never use a table name or internal code.
- For an app/module audit, inventory every business reference first and assign all approved prefixes in one pass. Never seed only the examples already discussed while leaving sibling references blank.
- Reuse these approved Management Aset mappings:
  - `Entitas aset` -> `ENTA`
  - `Grup aset` -> `GRPA`
  - `Kategori aset` -> `KTGA`
  - `Jenis aset` -> `JNSA`
  - `Kondisi aset` -> `KNDA`
  - `Pabrikan aset` -> `PBRA`
  - `Item checklist maintenance` -> `ICMA`
  - `Analisa maintenance` -> `ANMA`
- These mappings are explicit project vocabulary, not an algorithm to reuse blindly in another domain.
- If a new title has no approved first-three-letter abbreviation, the app code is unknown, or the resulting four-letter prefix collides with another reference in the same app, ask once with the complete unresolved list. Do not leave `default_prefix` empty and do not silently invent a collision.

## Transaction rules

For every transaction reference:

1. Identify the actual business document and lifecycle from the owning app.
2. Check the corresponding current Dynamics 365 documentation on Microsoft Learn when an equivalent document exists.
3. Propose the reference code, familiar document abbreviation, scope, reset, continuity, manual-entry policy, range, segments, and first/last preview.
4. Explain briefly when legal requirements justify continuous numbering.
5. Ask the user to approve the proposal before editing the manifest.

Do not create a generic transaction number when distinct documents have different posting, cancellation, fiscal, or legal behavior.

## Manifest and verification

Declare references as app-owned data:

```yaml
number_sequences:
  references:
    - code: owning-app.business-reference
      name: Business title
      default_prefix: ABC
      allowed_scopes:
        - tenant
```

Then verify:

1. Manifest validation and catalog registration succeed.
2. Every declared reference has exactly one four-letter uppercase prefix, all references share the app's final letter, and prefixes do not collide within the app.
3. A ready app materializes every declared reference exactly once.
4. New defaults are active and bounded.
5. All references appear in the shared UI with first/last previews.
6. Save persists after reload and validation errors are visible.
7. Automatic issue is idempotent and unique within scope.
8. Continuous flows, when approved, use reserve/confirm/cancel and recovery.

## Required audit output

Return a compact decision table:

| Reference | Type | Prefix | Scope | Reset | Continuous | Manual | Range | Preview | Decision |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |

Mark unresolved cells as `needs user approval`. Do not mark the audit complete while any required decision is unresolved.
