# Standar module dan addon

## Release unit wajib

Setiap module harus dapat diproduksi sebagai artifact terpisah. Module bukan hanya backend.

```text
modules/vendor/pos/
├── module.yaml
├── api/
│   ├── Dockerfile
│   ├── app/                    # Laravel service khusus POS
│   ├── routes/api.php
│   └── tests/
├── ui/
│   ├── package.json
│   ├── src/
│   └── dist/                   # generated UI artifact, tidak di-commit
├── database/
│   ├── migrations/
│   └── seeders/
├── contracts/
│   ├── openapi.yaml
│   └── asyncapi.yaml
├── deploy/
│   ├── compose.fragment.yaml
│   └── migrate.sh
└── README.md
```

`api` dan `ui` boleh berada pada monorepo yang sama, tetapi dirilis sebagai artifact berbeda. Cloud dapat menyajikan UI lewat CDN/artifact registry; on-prem perpetual menyajikannya dari image static UI yang hanya ada untuk module berlisensi dan didistribusikan dalam bundle release bertanda tangan.

## Contoh manifest

```yaml
id: pos
publisher: coreerp
version: 1.0.0
kind: business-module
requires:
  core: ^1.0
dependsOn: []
api:
  image: registry.coreerp.local/modules/pos-api:1.0.0
  openapi: contracts/openapi.yaml
ui:
  image: registry.coreerp.local/modules/pos-ui:1.0.0
  entry: /modules/pos/entry.js
database:
  logicalName: pos
  migrations: database/migrations
events:
  asyncapi: contracts/asyncapi.yaml
capabilities:
  - pos.sale.create
  - pos.catalog.read
security:
  entryPoints:
    - code: pos.sales.form
      type: form
    - code: pos.sales.api
      type: api
  permissions:
    - code: pos.sale.read
      entryPoint: pos.sales.form
      access: read
    - code: pos.sale.create
      entryPoint: pos.sales.api
      access: create
    - code: pos.sale.void
      entryPoint: pos.sales.api
      access: execute
  privileges:
    - code: pos.sales.maintain
      permissions: [pos.sale.read, pos.sale.create]
    - code: pos.sales.void
      permissions: [pos.sale.void]
  duties:
    - code: pos.sales.process
      privileges: [pos.sales.maintain]
    - code: pos.sales.supervise
      privileges: [pos.sales.maintain, pos.sales.void]
dataRetention: archive
```

Manifest mendaftarkan metadata keamanan kanonik sampai duty. Security role, user assignment, dan organization scope dibuat pada tenant; ketiganya bukan bagian dari manifest module dan tidak dibatasi ke satu module.

## Ownership database

Core Platform memiliki `core_erp`. Setiap module resmi memiliki database dengan pola `core_module_<module>`, misalnya `core_module_procurement` dan `core_module_management_asset`; addon memakai `addon_<publisher>_<module>`. Setiap database mempunyai database user/secret sendiri. Tidak ada foreign key, Eloquent relation, atau query langsung lintas database.

Di dalam database sendiri, module boleh memakai transaksi, foreign key, dan table desain normal. Semua tabel tenant-scoped membawa `tenant_id`; data dengan konsekuensi hukum/akuntansi membawa `legal_entity_id`; data operational membawa `org_unit_id` bila ownership terjadi pada operating unit. ID organisasi adalah reference opaque ke Organization service, bukan foreign key lintas database. Lihat [model tenant dan organisasi](01a-tenant-and-org-hierarchy.md).

## Kontrak module

| Area | Aturan |
| --- | --- |
| API sync | REST/JSON di bawah `/api/v1`, lengkap dalam OpenAPI. |
| Event | Event dibuat melalui outbox setelah commit; payload dan channel ditulis dalam AsyncAPI. |
| UI | UI entry mendaftarkan route/menu melalui host SDK; host memuat artifact hanya bila entitlement aktif, installation registry `ready`, dan user mempunyai permission entry point. |
| Auth | Semua endpoint memvalidasi token, `TenantContext`, entitlement, installation readiness, permission, dan organization scope. Security metadata mengikuti [identity dan access](09-identity-and-access.md). |
| Data | Tidak ada database access lintas module. ID module lain hanya reference opaque. |
| Jobs | Idempotent, membawa `tenant_id`, memiliki retry/dead-letter policy. |
| Observability | Log, trace, metric, dan event menyertakan tenant/module/correlation ID. |
| Compatibility | Manifest mendeklarasikan rentang versi Core dan dependency; dependency cycle ditolak. |

## Lifecycle module

Lifecycle tidak dimodelkan sebagai satu status linear karena empat fakta mempunyai sumber kebenaran berbeda:

| Fakta | Sumber kebenaran |
| --- | --- |
| Catalogued | Module catalog/manifest mengenal produk dan contract-nya. |
| Entitled | Tenant entitlement menyatakan hak komersial masih berlaku. |
| Installed | Installation registry mencatat artifact dan migration berhasil pada placement/release. |
| Ready | Placement/runtime health menyatakan release dapat diroute. |

Disable mencabut akses dan menghentikan jobs tanpa memalsukan installation state. Uninstall menghapus placement/artifact setelah dependency kosong; data default diarsipkan dan `purge` memerlukan backup serta approval eksplisit.

## Addon type

| Type | Pembuat | Contoh |
| --- | --- | --- |
| Business module | Vendor | POS, Booking, Finance |
| Bridge module | Vendor/partner | POS-Booking Bridge |
| Private addon | Vendor/partner | Loyalty khusus Client A |
| Customer extension | Customer melalui SDK dan approval | Connector mesin produksi |

Customer extension pada managed cloud tidak boleh mengunggah arbitrary container. Ia harus memakai publisher namespace, signed image, manifest tervalidasi, least-privilege permission, dan security review. Pada on-prem perpetual, customer dapat menjalankan sidecar sendiri tetapi hanya melalui API/event contract publik; tidak ada query langsung DB atau perubahan source core. Support vendor berlaku sesuai batas kontrak, bukan melalui enrollment runtime wajib.
