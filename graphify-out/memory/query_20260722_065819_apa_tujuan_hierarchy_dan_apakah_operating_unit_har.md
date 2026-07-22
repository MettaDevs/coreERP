---
type: "query"
date: "2026-07-22T06:58:19.876883+00:00"
question: "Apa tujuan hierarchy dan apakah operating unit harus menjadi organisasi paling atas?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Organization", "OrganizationHierarchy", "LegalEntity", "OperatingUnit"]
---

# Q: Apa tujuan hierarchy dan apakah operating unit harus menjadi organisasi paling atas?

## Answer

Expanded from original query via vocab: [organization, hierarchy, operating, purpose, legal]. Tenant is not an organization root. Organizations are stable legal-entity or operating-unit identities; parent-child placement belongs to a purpose-scoped versioned hierarchy. For legal/accounting or establishment views, legal entity is the root and operating units sit below it. The current tenant already has one published Business policy hierarchy with its legal entity as root and operating unit as child. Duplicate hierarchy name input should be returned as field validation, not a raw database exception.

## Outcome

- Signal: useful

## Source Nodes

- Organization
- OrganizationHierarchy
- LegalEntity
- OperatingUnit