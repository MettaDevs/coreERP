# Mendaftarkan app dari repository terpisah

Dokumen ini adalah kontrak kerja antara tim app dan CoreERP. Tujuannya agar satu tim dapat membuat app bisnis pada repository sendiri tanpa menaruh source domain, database, atau UI app di repository CoreERP.

## Batas ownership

| Pemilik | Tanggung jawab |
| --- | --- |
| Tim app | `app.yaml`, API, UI, database, contract, test, image, dan bundle deployment app. |
| CI app | Memvalidasi manifest, membangun image digest immutable, lalu mengirim metadata katalog/release ke Control Plane. |
| CoreERP Control Plane | Katalog, entitlement tenant, role metadata, placement, installation registry, dan launcher. |

App tidak mengakses database CoreERP. CoreERP tidak mengakses database app. Keduanya bertukar konteks dan kontrak yang dipublikasikan.

## Yang ada di repository app

Setiap app memakai struktur minimum berikut.

```text
app-erp-<app-key>/
├─ app.yaml
├─ api/
├─ ui/
├─ database/
├─ contracts/
│  ├─ openapi.yaml
│  └─ asyncapi.yaml
└─ deploy/
```

`app.yaml` menyatakan ID app, versi, dependency, UI entry, logical database, serta permission/entry point. CI menghitung SHA-256 file manifest yang diloloskan dan membangun image API/UI dengan digest immutable. Tag mutable tidak boleh didaftarkan sebagai release.

## Registrasi katalog dan release

Registrasi dilakukan oleh service account CI/provider, bukan developer yang mengubah repository CoreERP.

1. **Katalog sekali per app.** CI atau operator provider memanggil `POST /api/v1/provider/apps` dengan metadata dari manifest: ID, nama produk, versi awal, database logis, UI entry, repository URL, URL contract, serta keempat lapis keamanan `security.entry_points`, `security.permissions`, `security.privileges`, dan `security.duties`. Nama key payload sama persis dengan `app.yaml`, jadi CI mengirim isinya apa adanya.
2. **Release sekali per versi.** Setelah artifact siap, CI memanggil `POST /api/v1/provider/apps/{app}/releases`.
3. **Placement.** Hanya release yang tercatat dapat dipakai worker placement. Entitlement tetap tidak berarti app sudah terpasang atau siap.

Payload release minimum:

```json
{
  "version": "1.0.0",
  "manifest_sha256": "<64-character-sha256>",
  "api_image": "registry.example/app-api@sha256:<64-character-sha256>",
  "ui_image": "registry.example/app-ui@sha256:<64-character-sha256>",
  "bundle_path": "app-key/1.0.0",
  "compose_file": "compose.yaml",
  "compose_project": "app-key",
  "api_service": "app-api",
  "ui_service": "app-ui",
  "database_service": "app-db"
}
```

`bundle_path` selalu relatif terhadap `COREERP_RELEASE_ROOT`, yaitu lokasi artifact yang sudah dibuat CI dan tersedia untuk worker deployment. Bundle berisi Compose lengkap, termasuk service database. Image API wajib membawa skrip `deploy/migrate.sh` pada `/coreerp/migrate.sh`; worker menjalankannya setelah database sehat dan sebelum API/UI dinyalakan. Bundle bukan path source repository developer.

## Batas lifecycle

Control Plane menyimpan fakta berbeda berikut:

```text
catalogued  = app dan kontraknya tercatat pada katalog
release known = artifact deployable untuk versi tersebut tercatat
entitled   = tenant berhak memakai app
installed  = artifact + migration berhasil pada placement
ready      = runtime health berhasil dan app dapat diroute
```

Launcher hanya menampilkan app ketika entitlement aktif, release yang terdaftar cocok dengan placement, dan placement telah `ready`. Katalog atau entitlement saja tidak membuat app terlihat sebagai terpasang.

## Pekerjaan yang masih bukan otomatis

Upgrade belum dapat didaftarkan melalui endpoint ini: versi release harus sama dengan versi katalog. Upgrade memerlukan compatibility matrix, backup, dan workflow rollback terverifikasi; jangan menyamarkan perubahan versi sebagai instalasi biasa.

App juga wajib menyediakan middleware/gateway yang menerima `TenantContext` tepercaya dari CoreERP dan menegakkan tenant serta organization scope pada API-nya. Jangan menerima `tenant_id` bebas dari request browser.

Untuk UI app yang dimuat Web Shell, Core menerbitkan token konteks HS256 berumur lima menit dan mengirimkannya ke iframe dengan `postMessage` yang dibatasi ke origin UI app. Token memuat app audience, tenant aktif, organization aktif, serta permission efektif. UI meneruskan token sebagai bearer token ke API app; API memverifikasi signature, expiry, audience, dan tenant sebelum menjalankan query. Core dan API app menerima `COREERP_APP_CONTEXT_SIGNING_KEY` yang sama melalui secret store deployment, bukan source code. Web Shell memperbarui token setiap empat menit selama app masih terbuka.

Metadata keamanan adalah milik repository app, bukan daftar yang ditulis di CoreERP. CI mengubah `app.yaml` menjadi payload registrasi; Control Plane memvalidasi bahwa setiap kode memakai awalan ID app, bahwa permission menunjuk entry point yang dideklarasikan, privilege hanya memakai permission app tersebut, dan duty hanya memakai privilege app tersebut. Menambah rule bisnis atau permission pada Procurement, Finance, POS, maupun app lain tidak memerlukan perubahan source CoreERP.

CI boleh mengirim ulang registrasi katalog untuk app yang sama saat metadata keamanan berubah. Manifest adalah sumber kebenaran: metadata yang tidak lagi dideklarasikan akan dihapus. Pengecualiannya adalah duty yang masih dipakai security role tenant — registrasi ditolak dengan menyebut duty tersebut, supaya hak yang sedang berjalan tidak hilang diam-diam. Registrasi katalog bukan upgrade release aplikasi.

## Lihat juga

- [Standar module](02-module-standard.md) — isi manifest yang didaftarkan
- [Release dan on-prem](03-release-and-on-prem.md) — apa yang terjadi setelah release terdaftar
- [Gate penemuan dan keputusan](18-module-discovery-and-decision-gate.md) — syarat sebelum app layak dirilis
- [Gate fondasi Core](10-core-foundation-gates.md) — batas antara katalog dan deployment nyata
