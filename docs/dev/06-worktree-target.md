# Worktree sekarang dan target implementasi

## Baseline yang benar-benar ada saat ini

Root repository sekarang sudah berbentuk monorepo. Laravel 13 React starter dipindahkan utuh ke `apps/control-plane`; ia belum menjadi module platform dan belum memiliki domain ERP:

| Lokasi worktree | Keadaan saat ini | Dampak terhadap target |
| --- | --- | --- |
| `apps/control-plane/composer.json` | Laravel 13, Inertia, Fortify; tidak ada package module/contract registry | Basis Laravel untuk Control Plane; service module dibuat di boundary sendiri. |
| `apps/control-plane/routes/web.php` | Signup bisnis tanpa pilihan depth, organization directory, draft/published purpose-scoped hierarchy, join invitation, responsibility-based access, identity monitor, katalog module, dan REST v1 first-party | Fondasi organization, access, tenant deployment binding, dan queued placement worker sudah runnable; workforce, temporary access, dan SoD masih perlu dibangun. |
| `apps/control-plane/resources/js/app.tsx` | `createInertiaApp`, layout resolver berdasar nama halaman | UI customer final berada di `apps/web-shell` dan memuat UI artifact module. |
| `apps/control-plane/bootstrap/app.php` | Routing hanya `web.php`, middleware web Inertia | Target menambah routing API, gateway/auth middleware, dan tenant placement resolver. |
| `apps/control-plane/bootstrap/providers.php` | App dan Fortify provider | Target memisahkan Control Plane provider dan module service provider. |
| `apps/control-plane/.env.example` | PostgreSQL dengan database `core_erp` | Core Platform memiliki database sendiri; module dilarang query database ini secara langsung. |
| `apps/control-plane/config/queue.php` | Driver database default, Redis/SQS tersedia | Target memakai queue/broker untuk outbox/inbox event. |
| `modules/coreerp/procurement` dan `modules/coreerp/management-asset` | Release unit resmi awal: API/UI hello, database, migration, contract, dan Compose fragment | Boundary final sudah ada; isi domain bisnis belum dibuat. |

Control Plane dan dua module resmi awal sudah memiliki fondasi runnable. Core sekarang memiliki organization directory (`organizations`, `legal_entities`, `operating_units`), purpose-scoped versioned hierarchy, membership, entitlement, tenant deployment binding, module placement registry, installation-attempt history, queued Compose placement worker, role → duty → privilege → permission, organization-scoped assignment, invitation, dan provider access. Onboarding tidak lagi memilih depth atau membuat root organization palsu. Launcher memisahkan hak produk dari produk yang benar-benar siap dibuka. `Establishment` sudah diperlakukan sebagai peran operating unit dalam hierarchy purpose `Enterprise establishment structure`, bukan sebagai operating-unit type. Integrasi worker dengan Dokploy production menunggu gate infra pada [dokumen fondasi Core](10-core-foundation-gates.md); workforce/position, automatic dan temporary assignment, SoD, audit access, serta domain bisnis module masih menjadi fase berikutnya.

## Target monorepo

```text
apps/
├── control-plane/               # tenant, identity, entitlement, placement, installer
├── provider-console/            # provider operations, memakai Control Plane API
└── web-shell/                   # tenant UI host yang memuat UI artifact module

modules/
├── coreerp/procurement/
│   └── api/ ui/ database/ contracts/ deploy/ module.yaml
└── coreerp/management-asset/
    └── api/ ui/ database/ contracts/ deploy/ module.yaml

addons/
└── client-a/loyalty-policy/

integrations/
└── coreerp/pos-booking-bridge/

packages/
├── contracts/                   # shared envelope/schema tooling, bukan domain model internal
├── module-sdk/                  # manifest and host SDK
└── ui-sdk/                      # stable UI host interface

deploy/
├── compose/                     # base + generated customer editions
├── kubernetes/                  # nanti, bukan requirement v1
└── scripts/                     # provision, migrate, verify, uninstall
```

## Migration path dari starter saat ini

1. **Control-plane foundation:** pertahankan Laravel/Fortify, tenant identity, entitlement, invitation, dan provider identity monitor. Ganti depth onboarding dengan organization directory serta purpose-scoped versioned hierarchy; ganti role per module dengan role → duty → privilege → permission dan organization-scoped assignment. Implementasikan deployment registry sebagai sumber installation/readiness tanpa memasukkan domain ERP ke Core.
2. **API/UI boundary:** implementasikan Provider Console dan Web Shell sebagai aplikasi terpisah yang memakai API. Jangan membangun fitur POS baru di `apps/control-plane/routes/web.php` Inertia.
3. **First vertical module:** lanjutkan Procurement dari hello-world menjadi satu capability bisnis penuh tanpa mengubah boundary release unitnya.
4. **Second independent module:** lanjutkan Management Asset dengan pola identik, tanpa model atau database access Procurement.
5. **Bridge:** implementasikan integration pertama hanya setelah ada use case lintas module yang nyata; tidak ada bridge spekulatif.
6. **Installer:** control plane menghasilkan pooled atau isolated placement untuk SaaS. Generator/installer lokal menghasilkan Compose edition `onprem-perpetual`, memverifikasi bundle bertanda tangan, menjalankan migration, dan mencatat lifecycle tanpa koneksi vendor.
7. **Customization SDK:** setelah contract Procurement/Management Asset stabil, tambah addon SDK/private registry flow.

## Acceptance evidence untuk fase pertama

- Procurement dapat berjalan sendiri dengan API, UI, dan `core_module_procurement`.
- Management Asset dapat berjalan sendiri dengan API, UI, dan `core_module_management_asset`.
- Edition Procurement-only tidak memuat image, UI artifact, migration, atau database Management Asset.
- Token Tenant A tidak bisa membaca/menulis record Tenant B pada database module pooled.
- Upgrade module menolak manifest yang incompatible sebelum migration berjalan.
- Reporting gabungan dibaca dari `reporting_db` projection, bukan join lintas database/replica module.
- Report operasional replica menampilkan `data_as_of` dan tidak dipakai sebagai source of truth transaksi kritis.
- Tenant dengan satu legal entity dan tanpa operating-unit hierarchy dapat mengambil data entity tersebut dengan `tenant_id + legal_entity_id`; query descendants memakai projection lokal yang menyebut hierarchy dan effective version.
- Registrasi tidak meminta organization depth dan tidak membuat root organization generik.
- Role lintas module tidak mempunyai `module_id`; effective permission mengikuti role → duty → privilege → permission.
- Entitlement tanpa module placement `ready` tidak pernah ditampilkan sebagai module `installed` atau `ready`.
