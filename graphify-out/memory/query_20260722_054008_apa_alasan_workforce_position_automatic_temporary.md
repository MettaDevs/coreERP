---
type: "query"
date: "2026-07-22T05:40:08.052738+00:00"
question: "apa alasan workforce position automatic temporary assignment SoD deployment worker belum dibangun dan pastikan migrasi ulang tanpa tabel lama"
contributor: "graphify"
outcome: "useful"
source_nodes: ["Workforce dan rangkap position", "Temporary access dan segregation of duties", "Migrations", "Role", "Organization"]
---

# Q: apa alasan workforce position automatic temporary assignment SoD deployment worker belum dibangun dan pastikan migrasi ulang tanpa tabel lama

## Answer

Expanded via graph vocabulary: workforce position HR module automatic role assignment business data temporary access SoD deployment worker installation registry migration old tables. Verified Microsoft sources: jobs and positions are maintained in Human Resources; automatic role assignment is based on business data and can associate HR positions with security roles. Rebuilt PostgreSQL core_erp from target migrations, fixed PostgreSQL self-reference constraint ordering, removed legacy roles.is_template and tenants.deployment_profile, and verified old org_units/org_unit_closures/role_permissions tables are absent.

## Outcome

- Signal: useful

## Source Nodes

- Workforce dan rangkap position
- Temporary access dan segregation of duties
- Migrations
- Role
- Organization