---
type: "query"
date: "2026-07-22T06:58:19.106478+00:00"
question: "Mengapa dua modul yang dipilih saat registrasi belum muncul dan belum bisa diakses?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["TenantModuleEntitlement", "Launcher rule", "RegisterBusiness", "Module"]
---

# Q: Mengapa dua modul yang dipilih saat registrasi belum muncul dan belum bisa diakses?

## Answer

Expanded from original query via vocab: [module, entitlement, installation, installed, launcher, registry, tenant]. Tenant has 2 active entitlements, but 2 deployment jobs remain queued, there are no module placements/installations, and there are no tenant business roles. Launcher correctly requires all three: entitlement, ready placement, and active business permission. The operational blockers are the stopped queue worker and missing initial role provisioning.

## Outcome

- Signal: useful

## Source Nodes

- TenantModuleEntitlement
- Launcher rule
- RegisterBusiness
- Module
