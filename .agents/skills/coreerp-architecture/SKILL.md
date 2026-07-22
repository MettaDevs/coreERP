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

## Launcher rule

A launcher labelled "produk terpasang" must query installation records for the active tenant and placement, normally filtering `status=ready`. Entitlement may be checked additionally for authorization, never as installation evidence.
