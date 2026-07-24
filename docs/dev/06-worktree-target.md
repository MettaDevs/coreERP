# Kondisi repository sekarang dan target pemisahan app

## Baseline yang benar-benar ada saat ini

Repository saat ini adalah monorepo transisi. Laravel 13 React starter berada di `apps/control-plane`; ia belum menjadi app platform yang terpisah secara fisik dari app bisnis.

| Lokasi sekarang | Keadaan saat ini | Arah target |
| --- | --- | --- |
| `apps/control-plane/` | Laravel 13, Inertia, Fortify; organization, access, entitlement, placement, dan worker sudah ada. | Tetap di repository CoreERP ini sebagai Control Plane. Ia tidak memiliki domain bisnis app. |
| `apps/provider-console/` | Belum dipisahkan sebagai aplikasi mandiri. | Dibuat di repository CoreERP ini; hanya memakai Control Plane API untuk operasi provider. |
| `apps/web-shell/` | Belum dipisahkan sebagai aplikasi mandiri. | Dibuat di repository CoreERP ini; menjadi host UI dan launcher app tenant. |
| App bisnis | Tidak lagi disimpan di repository CoreERP. | Management Asset berjalan dari repository `app-erp-management-asset`; app berikutnya dibuat sebagai repository `apperp-<app-key>` sendiri. |
| `packages/` | Belum merupakan package registry berversi. | Tidak dipisahkan dulu. Package hanya dipublish bila dipakai minimal dua repository. |

Control Plane sekarang memiliki organization directory (`organizations`, `legal_entities`, `operating_units`), purpose-scoped versioned hierarchy, membership, entitlement, tenant deployment binding, placement registry, installation-attempt history, queued placement worker, role → duty → privilege → permission, organization-scoped assignment, invitation, dan provider access. Launcher memisahkan hak produk dari produk yang benar-benar siap dibuka. Workforce/position, automatic dan temporary assignment, SoD, audit access, dan integrasi deployment production masih menjadi fase berikutnya.

## Target repository

```text
CoreERP/                            # repository platform yang sekarang ini
├── apps/
│   ├── control-plane/              # tenant, identity, entitlement, placement, installer
│   ├── provider-console/           # operasi provider, memakai Control Plane API
│   └── web-shell/                  # host UI tenant dan launcher app
├── deploy/                         # deployment platform
└── README.md

apperp-<app-key>/                   # satu repository untuk satu app bisnis
├── api/ ui/ database/ contracts/ deploy/
├── app.yaml
└── README.md

apperp-<bridge-key>/                # repository bridge bila use case nyata muncul
└── api/ database/ contracts/ deploy/ app.yaml
```

API dan UI sebuah app tetap satu repository. Pemisahan repository dilakukan antar-app, bukan antara frontend dan backend. Addon app mengikuti bentuk repository yang sama. Tidak ada Git submodule dan tidak ada shared database.

## Urutan implementasi

1. **Tetapkan contract dan template app:** gunakan struktur pada [standar app](02-module-standard.md), format manifest, aturan versi API/event, serta pipeline CI dasar. Belum perlu membuat package bersama.
2. **Selesaikan platform minimum:** pisahkan Provider Console dan Web Shell dalam repository platform. Web Shell hanya perlu login/konteks kerja, launcher dari installation registry `ready`, dan kemampuan memuat satu UI app. Provider Console hanya perlu katalog, release, placement, dan status operasi yang benar.
3. **Buat satu app pilot langsung pada repository sendiri:** app pilot harus memiliki Laravel API, UI, migration, OpenAPI/AsyncAPI, deploy, serta CI sendiri. Jangan membuat domain baru di `apps/control-plane`.
4. **Buktikan release end-to-end:** CI menerbitkan API/UI/manifest; installer menjalankan migration; registry mencatat `ready`; Web Shell dapat membuka UI app. Hak produk tanpa placement `ready` tidak boleh membuat app tampil sebagai terpasang.
5. **Skalakan ke app kedua:** baru setelah langkah 4 stabil. Jika ada kebutuhan kode lintas repository yang benar-benar sama, publish package kecil yang berversi dan memiliki owner.
6. **Bridge atau reporting:** dibuat hanya saat ada use case lintas app nyata; komunikasi memakai REST/OpenAPI atau event/AsyncAPI, bukan query database.

## Batas release dan ownership

Satu release app mencakup versi API image, UI artifact, migration, manifest, OpenAPI/AsyncAPI, dan informasi kompatibilitas. Rollback atau forward-fix app tidak boleh membangun ulang app lain.

Setiap app memiliki owner untuk:

- review dan kualitas source;
- schema dan data database miliknya;
- contract yang dipublish;
- CI, release, rollback, dan penanganan incident.

Control Plane mengoordinasi katalog, entitlement, placement, dan status runtime. Ia tidak menulis database app. App tidak membaca database Control Plane atau app lain secara langsung.

## Acceptance evidence untuk fase pertama

- Satu app pilot berjalan dari repository sendiri dengan API, UI, dan database miliknya.
- Web Shell hanya membuka app ketika tenant berhak menggunakannya dan placement release tercatat `ready`.
- Token Tenant A tidak bisa membaca/menulis record Tenant B pada database app pooled.
- Upgrade app menolak manifest yang incompatible sebelum migration berjalan.
- Contract change memicu compatibility check pada app consumer yang terdaftar.
- Reporting gabungan dibaca dari `reporting_db` projection, bukan join lintas database/replica app.
- Package bersama tidak dibuat sebelum dipakai minimal dua repository dan memiliki versi serta owner.
