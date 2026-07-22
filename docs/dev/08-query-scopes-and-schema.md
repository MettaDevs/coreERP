# Schema dan query scope: tenant, legal entity, dan organization

Schema ini menerapkan keputusan pada [model tenant dan organisasi](01a-tenant-and-org-hierarchy.md), yang diringkas dari [referensi Microsoft Dynamics 365](../references/dynamics-365-organization-model.md).

## Identitas pada setiap lapisan

```text
Client        = pihak/kontrak komersial di control plane
Tenant        = workspace dan isolation boundary
Organization  = identitas legal entity atau operating unit di dalam tenant
Hierarchy     = susunan organization untuk purpose dan version tertentu
```

`client_id` tidak disalin ke setiap transaksi. Semua transaksi tenant-owned membawa `tenant_id`; transaksi hukum/akuntansi membawa `legal_entity_id`; proses operasional membawa `org_unit_id` bila relevan.

## Schema ownership

```text
control_plane_db
├── clients
├── tenants
├── tenant_module_entitlements
├── tenant_deployments
├── module_placements
└── module_installations

organization_db
├── organizations
├── legal_entities
├── operating_units
├── organization_hierarchies
├── organization_hierarchy_purposes
├── organization_hierarchy_versions
├── organization_hierarchy_nodes
└── organization_hierarchy_closures

identity_access_db
├── users / tenant_memberships
├── workforce / position tables
├── security role/duty/privilege/permission tables
└── assignment, organization scope, temporary access, dan SoD

procurement_db
├── procurement_requisitions
├── procurement_org_hierarchy_projection
├── outbox_events
└── inbox_events

reporting_db
├── tenant_dimension
├── organization_dimension
└── reporting projections
```

Nama database di atas adalah ownership logis. Deployment pooled boleh menempatkannya dalam PostgreSQL cluster yang sama, tetapi role/credential dan migration ownership tetap terpisah. Module tidak membuat foreign key atau query langsung ke database lain.

## Schema minimum organization target

Contoh ini menunjukkan invariant utama, bukan migration yang sudah tersedia di worktree.

```sql
CREATE TABLE organizations (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    classification text NOT NULL
        CHECK (classification IN ('legal_entity', 'operating_unit')),
    status text NOT NULL,
    UNIQUE (tenant_id, id),
    UNIQUE (tenant_id, code)
);

CREATE TABLE legal_entities (
    organization_id uuid PRIMARY KEY REFERENCES organizations(id),
    company_code text NOT NULL,
    country_code text NOT NULL
);

CREATE TABLE operating_units (
    organization_id uuid PRIMARY KEY REFERENCES organizations(id),
    type text NOT NULL
);

CREATE TABLE organization_hierarchies (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL,
    name text NOT NULL,
    status text NOT NULL,
    UNIQUE (tenant_id, id),
    UNIQUE (tenant_id, name)
);

CREATE TABLE hierarchy_purposes (
    id uuid PRIMARY KEY,
    code text NOT NULL UNIQUE,
    name text NOT NULL
);

CREATE TABLE organization_hierarchy_purposes (
    hierarchy_id uuid NOT NULL REFERENCES organization_hierarchies(id),
    purpose_id uuid NOT NULL REFERENCES hierarchy_purposes(id),
    PRIMARY KEY (hierarchy_id, purpose_id)
);

CREATE TABLE organization_hierarchy_versions (
    id uuid PRIMARY KEY,
    hierarchy_id uuid NOT NULL REFERENCES organization_hierarchies(id),
    status text NOT NULL,
    effective_from timestamptz NOT NULL,
    published_at timestamptz NULL,
    UNIQUE (hierarchy_id, id)
);

CREATE TABLE organization_hierarchy_nodes (
    id uuid PRIMARY KEY,
    version_id uuid NOT NULL REFERENCES organization_hierarchy_versions(id),
    organization_id uuid NOT NULL REFERENCES organizations(id),
    parent_node_id uuid NULL,
    UNIQUE (version_id, id),
    UNIQUE (version_id, organization_id),
    FOREIGN KEY (version_id, parent_node_id)
        REFERENCES organization_hierarchy_nodes(version_id, id)
);

CREATE TABLE organization_hierarchy_closures (
    version_id uuid NOT NULL REFERENCES organization_hierarchy_versions(id),
    ancestor_organization_id uuid NOT NULL,
    descendant_organization_id uuid NOT NULL,
    distance integer NOT NULL CHECK (distance >= 0),
    PRIMARY KEY (
        version_id,
        ancestor_organization_id,
        descendant_organization_id
    )
);
```

Writer organization membuat tepat satu row subtype yang sesuai dengan `organizations.classification`; satu organization tidak boleh sekaligus menjadi legal entity dan operating unit. Application/service validation juga memastikan organization, hierarchy, version, dan node berada dalam tenant yang sama; graph tidak bersiklus; dan satu organization hanya muncul sekali dalam satu version. `distance` hanya mempercepat query relasi, bukan batas depth. Establishment ditentukan oleh placement operating unit dalam effective hierarchy purpose `Enterprise establishment structure`, bukan oleh nilai subtype baru.

## Schema transaksi module

```sql
CREATE TABLE procurement_requisitions (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL,
    legal_entity_id uuid NOT NULL,
    org_unit_id uuid NULL,
    requested_at timestamptz NOT NULL,
    status text NOT NULL,
    total_amount numeric(18,2) NOT NULL
);

CREATE INDEX procurement_requisitions_tenant_requested_idx
    ON procurement_requisitions (tenant_id, requested_at DESC);

CREATE INDEX procurement_requisitions_scope_requested_idx
    ON procurement_requisitions (
        tenant_id,
        legal_entity_id,
        org_unit_id,
        requested_at DESC
    );

CREATE TABLE procurement_org_hierarchy_projection (
    tenant_id uuid NOT NULL,
    hierarchy_id uuid NOT NULL,
    version_id uuid NOT NULL,
    ancestor_organization_id uuid NOT NULL,
    descendant_organization_id uuid NOT NULL,
    distance integer NOT NULL,
    PRIMARY KEY (
        tenant_id,
        version_id,
        ancestor_organization_id,
        descendant_organization_id
    )
);
```

Projection diperbarui dari event organization contract, terutama organization identity changed dan hierarchy version published. Publish version baru tidak menghapus projection version lama yang masih dibutuhkan transaksi, authorization, atau laporan historis.

## Contoh query per scope

Nilai parameter harus berasal dari `TenantContext` dan authorization result tepercaya.

### Satu legal entity tanpa operating unit

```sql
SELECT *
FROM procurement_requisitions
WHERE tenant_id = :tenant_id
  AND legal_entity_id = :legal_entity_id;
```

### Satu operating unit

```sql
SELECT *
FROM procurement_requisitions
WHERE tenant_id = :tenant_id
  AND legal_entity_id = :legal_entity_id
  AND org_unit_id = :org_unit_id;
```

### Organization beserta descendants pada hierarchy tertentu

```sql
SELECT r.*
FROM procurement_requisitions r
JOIN procurement_org_hierarchy_projection h
  ON h.tenant_id = r.tenant_id
 AND h.descendant_organization_id = r.org_unit_id
WHERE r.tenant_id = :tenant_id
  AND h.hierarchy_id = :hierarchy_id
  AND h.version_id = :effective_version_id
  AND h.ancestor_organization_id = :scope_organization_id;
```

Query tidak mengasumsikan branch selalu level dua atau outlet selalu level tiga. Arti descendants datang dari hierarchy/version yang dipilih role assignment.

### Seluruh tenant

```sql
SELECT *
FROM procurement_requisitions
WHERE tenant_id = :tenant_id;
```

Scope tenant hanya sah bila permission dan role assignment memang memberikannya. Predicate `tenant_id` tidak pernah dihapus.

### Reporting lintas tenant milik satu client

Module database tidak menyimpan `client_id`. Gunakan reporting projection:

```sql
SELECT *
FROM reporting_procurement_requisitions
WHERE client_id = :client_id;
```

Ini adalah query reporting eventually consistent, bukan query operasional lintas database.

## Urutan authorization query

1. Bangun `TenantContext` dari token/session tepercaya.
2. Validasi entitlement dan installation readiness secara terpisah.
3. Resolve permission melalui role → duty → privilege → permission.
4. Resolve organization scope assignment dan effective hierarchy version.
5. Terapkan `tenant_id` lalu `legal_entity_id`/`org_unit_id` pada query module.
6. Gunakan local hierarchy projection bila descendants diperlukan.

## Keadaan worktree saat ini

Control Plane sudah memakai `organizations`, subtype legal entity/operating unit, purpose, hierarchy, version, node, dan closure per version. Flow saat ini mendukung membuat directory, menyusun draft, menambah placement, dan publish. Penggandaan draft dari published version, penjadwalan restrukturisasi yang lebih lengkap, dan projection hierarchy ke database module masih belum tersedia.

## Aturan tetap

1. Tidak ada query atau foreign key lintas module database.
2. Organization identity tidak menyimpan parent/depth permanen.
3. Setiap organization mempunyai tepat satu klasifikasi legal entity atau operating unit.
4. Closure selalu terikat ke satu hierarchy version.
5. Historical version tidak ditulis ulang saat restrukturisasi.
6. `org_unit_id` tidak menggantikan `legal_entity_id` untuk kebutuhan hukum/akuntansi.
7. Endpoint production mendefinisikan response fields, pagination, limit, dan ordering dalam OpenAPI; `SELECT *` di atas hanya contoh scope.
