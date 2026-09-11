# Kondisi repository sekarang dan target pemisahan app

::: danger Arah halaman ini sudah dibalik
Halaman ini ditulis ketika target platform adalah **satu repository per app bisnis**. Target itu
sudah dibalik: module bisnis kini hidup di dalam repo CoreERP pada `modules/<penerbit>/<module>/`
dan berjalan di runtime Core. Alasan dan bukti pembalikannya ada di
[grand design](01-grand-design.md).

Isinya tidak dibuang karena app yang belum dipindah masih menjalankan bentuk yang digambarkan di
sini, dan aturan ownership serta batas release di bawah tetap berlaku untuknya. Yang salah adalah
membacanya sebagai arah untuk pekerjaan baru.
:::

## Keadaan sekarang

| Lokasi | Keadaan |
| --- | --- |
| `apps/control-plane/` | Laravel, Inertia, Fortify; organization, access, entitlement, placement, worker, dan **runtime yang menjalankan seluruh module** ada di sini. Ia tidak memiliki domain bisnis sendiri. |
| `apps/provider-console/` | Ada di repository ini; hanya memakai Control Plane API untuk operasi provider. |
| Web Shell | Tidak punya folder sendiri. Launcher dan halaman tuan rumah hidup di UI Control Plane (`resources/js/components/product-launcher.tsx`, `resources/js/lib/halaman-module.tsx`, `resources/js/pages/apps/host.tsx`). |
| `modules/` | Module bisnis, satu folder per module. Berjalan di runtime Core, memakai database tenant yang sama, dipisahkan awalan tabel. |
| App bisnis yang belum dipindah | Masih berjalan dari repository `app-erp-*` sendiri dengan container dan database sendiri. |
| `packages/` | Belum merupakan package registry berversi. Package hanya dipublish bila dipakai minimal dua repository. |

Control Plane sekarang memiliki organization directory (`organizations`, `legal_entities`, `operating_units`), purpose-scoped versioned hierarchy, membership, entitlement, tenant deployment binding, placement registry, installation-attempt history, queued placement worker, role → duty → privilege → permission, organization-scoped assignment, invitation, dan provider access. Launcher memisahkan hak produk dari produk yang benar-benar siap dibuka. Workforce/position, automatic dan temporary assignment, SoD, audit access, dan integrasi deployment production masih menjadi fase berikutnya.

## Target repository

```text
CoreERP/                            # repository platform yang sekarang ini
├── apps/
│   ├── control-plane/              # tenant, identity, entitlement, placement, installer, runtime module
│   ├── provider-console/           # operasi provider, memakai Control Plane API
│   └── web-shell/                  # host UI tenant dan launcher (belum ada, lihat tabel di atas)
├── modules/<penerbit>/<module>/    # module bisnis, berjalan di runtime Core
├── editions/                       # satu berkas per pelanggan
├── deploy/                         # deployment platform
└── README.md

app-erp-<app-key>/                  # app yang belum dipindah; bentuk lama
├── api/ ui/ database/ contracts/ deploy/
├── app.yaml
└── README.md
```

Untuk app yang masih berupa repository sendiri, API dan UI tetap satu repository: pemisahan
dilakukan antar-app, bukan antara frontend dan backend. Tidak ada Git submodule dan tidak ada shared
database.

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

## Lihat juga

- [Standar module](02-module-standard.md) — isi wajib satu module, dan isi wajib satu repository app
- [Release dan on-prem](03-release-and-on-prem.md) — image edisi dan bundle on-prem
- [Development stack lokal](11-local-docker-development.md) — bentuk stack yang benar-benar dijalankan hari ini
- [Mendaftarkan katalog produk](13-publishing-an-app-release.md) — CI per repository app
- [Grand design](01-grand-design.md) — kenapa arah halaman ini dibalik
