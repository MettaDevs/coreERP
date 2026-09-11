# Mendaftarkan katalog sebuah produk

::: warning Bentuk repository terpisah sudah tidak ada
Module bisnis tidak berasal dari repository terpisah. Manifestnya didaftarkan dari dalam repo Core
dengan `app:register-manifest <id module>` — bentuk berjalur berkas sudah ditolak — dan kodenya ikut
image edisi Core, bukan image sendiri. Alurnya ada di
[jalur membangun modul baru](../apps/membangun-app-baru.md) dan
[Release dan on-prem](03-release-and-on-prem.md#dua-bentuk-rilis).

**Pendaftaran rilis oleh penyedia dibuang pada 10 September 2026** bersama seluruh jalur hosting
container: endpoint `POST /api/v1/provider/apps/{app}/releases`, model `AppRelease`, dan job
penempatan app tidak ada lagi. Yang tersisa dari halaman ini adalah pendaftaran **katalog**, yang
masih berjalan dan masih punya endpoint penyedianya.
:::

Halaman ini menjelaskan apa yang dicatat platform tentang sebuah produk, siapa yang mencatatnya, dan
batas mana yang tidak boleh dilewati saat mencatatnya.

## Batas ownership

| Pemilik | Tanggung jawab |
| --- | --- |
| Tim module | `app.yaml`, rute, halaman UI, migration, contract, dan test module. |
| CI | Memvalidasi manifest lalu mengirim metadata katalog ke Control Plane. |
| CoreERP Control Plane | Katalog, entitlement tenant, role metadata, catatan pemasangan module, dan launcher. |

Sebuah module tidak menyentuh tabel module lain. Keduanya bertukar kontrak PHP dan event in-process;
permukaan yang dipanggil dari luar runtime tetap dikontrakkan.

## Yang ada di folder module

`app.yaml` menyatakan ID module, versi, dependency, entri menu UI, serta permission dan entry point.
Strukturnya lengkap ada di [Standar module](02-module-standard.md).

## Registrasi katalog

Registrasi dilakukan oleh service account CI atau provider, bukan developer yang mengubah repository
CoreERP.

**Katalog sekali per module.** CI atau operator provider memanggil `POST /api/v1/provider/apps` dengan
metadata dari manifest: ID, nama produk, versi, URL repository dan contract, `dependsOn`, serta keempat
lapis keamanan `security.entry_points`, `security.permissions`, `security.privileges`, dan
`security.duties`. Untuk dependency, key payload sama persis dengan `app.yaml`: map ID module ke
rentang versi. Control Plane menyimpan relasinya, memeriksa target tersedia dan cocok versinya, lalu
menolak cycle.

Di dalam repo ini jalur yang sama dipanggil `app:register-manifest <id module>`, dan itu perintah yang
sama dengan yang dijalankan admin on-prem.

**Tidak ada pendaftaran rilis.** Sampai 10 September 2026 ada langkah kedua —
`POST /api/v1/provider/apps/{app}/releases` — yang mencatat digest image, berkas Compose, dan nama
service per app supaya worker penempatan bisa menariknya. Langkah itu dibuang bersama jalur hosting
container: kode module ikut image edisi Core, jadi sidik jari yang berarti adalah sidik jari image
edisi itu, bukan sidik jari per app.

## Batas lifecycle

Control Plane menyimpan fakta berbeda berikut:

```text
catalogued = module dan kontraknya tercatat pada katalog
entitled   = tenant berhak memakai module
installed  = migration module berhasil dijalankan untuk tenant itu
```

Launcher hanya menampilkan sebuah produk ketika entitlement aktif **dan** catatan pemasangan module
untuk tenant itu berstatus `installed`. Katalog atau entitlement saja tidak membuat produk terlihat
sebagai terpasang. Rinciannya di [Tiga kebenaran lifecycle](../onboarding/tiga-kebenaran.md).

Jika pembeli memilih produk yang memiliki `dependsOn`, onboarding menambahkan semua prerequisite
transitif sebagai entitlement teknis. Katalog dan marketing tetap harus menyajikannya sebagai produk
utama beserta bagian yang sudah termasuk, bukan sebagai daftar module teknis yang harus dipilih
pelanggan.

## Pekerjaan yang masih bukan otomatis

Upgrade memerlukan compatibility matrix, backup, dan workflow rollback terverifikasi; jangan
menyamarkan perubahan versi sebagai pemasangan biasa.

Disable dan uninstall juga belum tersedia. `dependsOn` sudah melindungi registrasi dan onboarding,
tetapi belum dapat menolak pelepasan sebuah module karena belum ada operasi pelepasan yang bisa
dijalankan.

Halaman module menerima konteks tenant dari request Core yang sama, dan setiap query module menyaring
`tenant_id` lewat `MilikTenant`. Tidak ada `tenant_id` bebas yang boleh datang dari request browser.

Metadata keamanan adalah milik manifest module, bukan daftar yang ditulis di kode Core. CI mengubah `app.yaml` menjadi payload registrasi; Control Plane memvalidasi bahwa setiap kode memakai awalan ID app, bahwa permission menunjuk entry point yang dideklarasikan, privilege hanya memakai permission app tersebut, dan duty hanya memakai privilege app tersebut. Menambah rule bisnis atau permission pada sebuah module tidak memerlukan perubahan source Core.

CI boleh mengirim ulang registrasi katalog untuk module yang sama saat metadata keamanan berubah. Manifest adalah sumber kebenaran: metadata yang tidak lagi dideklarasikan akan dihapus. Pengecualiannya adalah duty yang masih dipakai security role tenant — registrasi ditolak dengan menyebut duty tersebut, supaya hak yang sedang berjalan tidak hilang diam-diam. Registrasi katalog bukan upgrade versi module.

## Lihat juga

- [Standar module](02-module-standard.md) — isi manifest yang didaftarkan
- [Release dan on-prem](03-release-and-on-prem.md) — apa yang terjadi setelah release terdaftar
- [Gate penemuan dan keputusan](18-module-discovery-and-decision-gate.md) — syarat sebelum app layak dirilis
- [Gate fondasi Core](10-core-foundation-gates.md) — batas antara katalog dan deployment nyata
