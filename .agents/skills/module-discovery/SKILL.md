---
name: module-discovery
description: Discover and propose a CoreERP app, master, transaction, or business feature before implementation. Use whenever asked to add a module, app, master data, transaction, workflow, approval, integration, or business process; research the relevant official Dynamics 365 documentation first and obtain user approval for material design decisions.
---

# Module Discovery

1. Read `docs/dev/README.md`, the relevant canonical documents, and the owning app manifest when one exists.
2. Search official Microsoft Learn Dynamics 365 documentation for the closest business process. Report the direct links and whether the mapping is direct, adapted, or absent. Do not claim a Dynamics feature when no official reference was found.
3. Classify ownership: existing app feature, new business app, Core policy/coordinator, or a future app whose owner is not available. Never place business facts in Core only for convenience.
4. Produce this proposal before code or manifest edits, then wait for approval if a material choice is unresolved:

| Capability | Decision | Reason / Dynamics reference | User approval needed |
| --- | --- | --- | --- |
| Resource and lifecycle | | | |
| Tenant/legal entity/org-unit scope | | | |
| Security and data policy | | | |
| Number reference | | | |
| Workflow and SoD | | | |
| REST, event, reporting, attachment/audit | | | |

## Handoff after approval

Run these skills in order; do not duplicate their detailed rules here:

1. Always use `coreerp-architecture` for ownership, organization scope, access chain, data-policy gate, manifest, and lifecycle truth.
2. Use `number-sequence-design` only when the approved proposal marks a number reference `required`.
3. Use `coreerp-page-standard` and `coreerp-ui` only when the approved change creates or changes a business screen.
4. Use `api-design`, `security-review`, and the canonical API/event document only when the approved change exposes an API, internal command, webhook, or cross-app event.
5. Use the relevant testing skill and the CoreERP load gate before declaring an implemented app/module complete.

## Number reference decision

Inventory only readable business masters and documents; exclude IDs, joins, logs, and internal rows. For each candidate, explicitly decide `required`, `not required`, or `deferred`.

When required, the owning app declares the reference in `app.yaml`; Core materializes it and the tenant administrator adjusts it in **Atur nomor**. The user can deliberately exclude a declared reference. Do not infer references from tables or hardcode app references in Core. After approval, use `number-sequence-design` for scope, prefix, range, format, and verification.

## Workflow and SoD decision

Propose workflow only for an approval, exception, irreversible/high-risk decision, or controlled handoff. Propose SoD when one person must not both initiate and verify/approve the same process. Otherwise state that neither is needed; do not add approval to ordinary CRUD.

## Completion

Record approved decisions in the owning app's design/README and contracts. Implement only approved decisions. Re-run this gate when a new document lifecycle or cross-app consumer is introduced.
