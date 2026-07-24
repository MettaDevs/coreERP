# Standar app dan addon app

Dokumen ini memakai istilah **app**. App adalah produk atau kemampuan bisnis yang dapat dipasang dan dirilis mandiri. Istilah `module` pada nama tabel, endpoint, atau kode yang sudah ada adalah nama teknis lama; jangan memakainya untuk desain baru atau komunikasi produk.

## Repository dan release unit wajib

Satu app bisnis memiliki satu repository. API dan UI bukan repository terpisah karena keduanya perlu diuji, diberi versi, dan dirilis sebagai satu kemampuan bisnis.

```text
apperp-accounting/                  # satu repository app
├── app.yaml
├── api/                            # Laravel service milik Accounting
│   ├── Dockerfile
│   ├── app/
│   ├── routes/api.php
│   └── tests/
├── ui/                             # React/Vite UI milik Accounting
│   ├── package.json
│   └── src/
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

Repository CoreERP ini adalah repository platform. Ia menampung `control-plane`, `provider-console`, dan `web-shell`; ia tidak berisi domain atau database app bisnis.

Setiap app menghasilkan artifact terpisah: image API, artifact/image UI, migration, contract, dan manifest. Cloud dapat menyajikan UI lewat CDN/artifact registry; on-prem perpetual menyajikannya dari image static UI yang hanya ada untuk app berlisensi dan didistribusikan dalam bundle release bertanda tangan.

Jangan memakai Git submodule untuk menghubungkan repository. Contract yang dipakai pihak lain dipublish sebagai artifact berversi; source app tidak diambil langsung oleh app lain.

## Contoh manifest

```yaml
id: accounting
publisher: coreerp
version: 1.0.0
kind: business-app
requires:
  core: ^1.0
dependsOn: []
api:
  image: registry.coreerp.local/apps/accounting-api:1.0.0
  openapi: contracts/openapi.yaml
ui:
  image: registry.coreerp.local/apps/accounting-ui:1.0.0
  entry: /apps/accounting/entry.js
database:
  logicalName: accounting
  migrations: database/migrations
events:
  asyncapi: contracts/asyncapi.yaml
capabilities:
  - accounting.journal.create
  - accounting.journal.read
security:
  entryPoints:
    - code: accounting.journals.form
      type: form
    - code: accounting.journals.api
      type: api
  permissions:
    - code: accounting.journal.read
      entryPoint: accounting.journals.form
      access: read
    - code: accounting.journal.create
      entryPoint: accounting.journals.api
      access: create
  privileges:
    - code: accounting.journals.maintain
      permissions: [accounting.journal.read, accounting.journal.create]
  duties:
    - code: accounting.journals.process
      privileges: [accounting.journals.maintain]
dataRetention: archive
```

Manifest mendaftarkan metadata keamanan kanonik sampai duty. Security role, user assignment, dan organization scope dibuat pada tenant; ketiganya bukan bagian dari manifest app dan tidak dibatasi ke satu app.

## Ownership dan database

Setiap app memiliki owner yang bertanggung jawab atas code review, contract, database, release, rollback, dan incident app tersebut. Core Platform memiliki `core_erp`. Setiap app resmi memiliki database dengan pola `core_app_<app>`, misalnya `core_app_procurement` dan `core_app_management_asset`; addon memakai `addon_<publisher>_<app>`. Setiap database mempunyai database user/secret sendiri. Tidak ada foreign key, Eloquent relation, atau query langsung lintas database.

Di dalam database sendiri, app boleh memakai transaksi, foreign key, dan table desain normal. Semua tabel tenant-scoped membawa `tenant_id`; data dengan konsekuensi hukum/akuntansi membawa `legal_entity_id`; data operasional membawa `org_unit_id` bila ownership terjadi pada operating unit. ID organisasi adalah reference opaque ke Organization service, bukan foreign key lintas database. Lihat [model tenant dan organisasi](01a-tenant-and-org-hierarchy.md).

## Contract dan dependency

| Area | Aturan |
| --- | --- |
| API sync | REST/JSON di bawah `/api/v1`, lengkap dalam OpenAPI. |
| Event | Event dibuat melalui outbox setelah commit; payload dan channel ditulis dalam AsyncAPI. |
| UI | UI entry mendaftarkan route/menu melalui host SDK; host memuat artifact hanya bila entitlement aktif, installation registry `ready`, dan user mempunyai permission entry point. |
| Auth | Semua endpoint memvalidasi token, `TenantContext`, entitlement, installation readiness, permission, dan organization scope. Security metadata mengikuti [identity dan access](09-identity-and-access.md). |
| Data | Tidak ada database access lintas app. ID app lain hanya reference opaque. |
| Jobs | Idempotent, membawa `tenant_id`, memiliki retry/dead-letter policy. |
| Observability | Log, trace, metric, dan event menyertakan tenant/app/correlation ID. |
| Compatibility | Manifest mendeklarasikan rentang versi Core dan dependency; dependency cycle ditolak. |

Contract adalah batas integrasi, bukan shared domain model. Contract tetap dimiliki repository app penerbit. App consumer memakai versi contract yang dipublish dan menjalankan compatibility check di CI.

`packages/` tidak dibuat sebagai tempat menaruh kode bersama tanpa kebutuhan nyata. Package baru hanya dibuat ketika minimal dua repository membutuhkan interface yang sama dan interface tersebut siap diberi versi/publish. Kandidat awal yang wajar hanya SDK kecil untuk autentikasi/konteks tenant atau host UI; bukan model bisnis bersama.

## Navigasi Web Shell

Web Shell memiliki layout bersama agar pengguna tidak berpindah-pindah pola saat membuka app.

| Area layar | Pemilik | Isi |
| --- | --- | --- |
| Pemilih aplikasi di header | Web Shell | Launcher aplikasi: hanya app yang berhak dipakai tenant, tercatat `ready` pada installation registry, dan boleh dibuka user yang aktif. |
| Rail paling kiri | App aktif | Navigasi utama app, misalnya Dashboard, Organisasi, dan Akses. |
| Header | Web Shell | Konteks kerja yang dipakai bersama, pencarian, notifikasi, preferensi tampilan, dan menu akun. |
| Sidebar di kanan rail | App aktif | Navigasi turunan dari pilihan pada rail, yang didaftarkan UI artifact melalui host SDK. |
| Konten utama | App aktif | Halaman dan alur bisnis app aktif. |

Contoh: saat user memilih `Akses` pada rail, sidebar dapat berisi `Anggota`, `Role`, dan `Undangan`. Pada Management Asset, rail dapat memuat `Master`; sidebar kemudian berisi `Asset`, `Kategori`, dan `Grup`. Saat user berpindah aplikasi melalui header, kedua navigasi tersebut diganti seluruhnya oleh navigasi aplikasi aktif.

App tidak membuat ulang header atau kerangka navigasi. App hanya mendaftarkan identitas, route, menu utama pada rail, menu turunan pada sidebar, dan permission entry point-nya. Nama, ikon, urutan, dan label menu berasal dari metadata app/host SDK, bukan daftar app yang di-hardcode di Web Shell.

## Lifecycle app

Lifecycle tidak dimodelkan sebagai satu status linear karena empat fakta mempunyai sumber kebenaran berbeda:

| Fakta | Sumber kebenaran |
| --- | --- |
| Catalogued | App catalog/manifest mengenal produk dan contract-nya. |
| Entitled | Tenant entitlement menyatakan hak komersial masih berlaku. |
| Installed | Installation registry mencatat artifact dan migration berhasil pada placement/release. |
| Ready | Placement/runtime health menyatakan release dapat diroute. |

Disable mencabut akses dan menghentikan jobs tanpa memalsukan installation state. Uninstall menghapus placement/artifact setelah dependency kosong; data default diarsipkan dan `purge` memerlukan backup serta approval eksplisit.

## Jenis app

| Type | Pembuat | Contoh |
| --- | --- | --- |
| Business app | Vendor | POS, Booking, Accounting |
| Bridge app | Vendor/partner | POS-Booking Bridge |
| Private addon app | Vendor/partner | Loyalty khusus customer |
| Customer extension app | Customer melalui SDK dan approval | Connector mesin produksi |

Customer extension pada managed cloud tidak boleh mengunggah arbitrary container. Ia harus memakai publisher namespace, signed image, manifest tervalidasi, least-privilege permission, dan security review. Pada on-prem perpetual, customer dapat menjalankan sidecar sendiri tetapi hanya melalui API/event contract publik; tidak ada query langsung DB atau perubahan source Core. Support vendor berlaku sesuai batas kontrak, bukan melalui enrollment runtime wajib.
