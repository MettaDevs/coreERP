# Business Partner

Business Partner adalah fondasi untuk identitas pihak bisnis yang dipakai lebih dari satu app. Satu party nantinya dapat mewakili orang atau organisasi. App proses memberi peran atas party itu—misalnya pemasok di Procurement atau vendor pada Accounts Payable—tanpa membuat salinan identitas yang berbeda.

## Identitas

| | |
| --- | --- |
| ID manifest | `business-partner` |
| Publisher | `apperp` |
| Versi | `0.1.0` — release pengembangan |
| Kind | `business-app` |
| Butuh Core | `^0.1` |
| Repository | `app-erp-business-partner` |
| Database | `app_erp_business_partner` |
| Dependency app | Tidak ada |

## Batas domain

**Party, vendor, dan pemasok bukan kata yang sama.** Party adalah identitas pihak bisnis bersama. Vendor atau pemasok adalah peran party pada proses pembelian. Karena satu pihak yang sama bisa muncul di Procurement dan Accounts Payable, menyimpan nama, alamat, atau kontak vendor di kedua app akan membuat pembetulan data harus dilakukan berkali-kali dan dapat menghasilkan versi yang bertentangan.

Business Partner akan memiliki identitas party serta data bersama yang benar-benar disetujui sebagai data party. Procurement akan memiliki fakta proses pengadaan, seperti prakualifikasi pemasok, penawaran, dan keputusan pengadaan, setelah scope-nya disetujui. Accounts Payable akan memiliki utang, invoice, dan pembayaran. Konsekuensi keuangan atau hukum tidak dipindahkan ke Business Partner hanya karena memakai party yang sama.

Setiap app tetap memiliki database sendiri. Tidak ada app yang boleh membaca database Business Partner secara langsung. Saat resource party dan contract-nya sudah diterbitkan, app pemakai mengambil atau mengubah data lewat REST/OpenAPI atau menerima fakta lewat event/AsyncAPI.

## Status fondasi saat ini

Fondasi sudah mempunyai manifest, database mandiri, API health, dan layar **Data pihak bisnis**. Hak yang tersedia hanya untuk membuka layar.

Belum ada resource party, field, migration bisnis, nomor, workflow, event lintas app, maupun hak CRUD. Karena itu dokumen ini tidak menetapkan model orang/organisasi, alamat, kontak, identifier legal, atau lifecycle sebagai fakta yang sudah berjalan. Keputusan tersebut harus dibuat bersama pada task resource pertama, lalu contract dan dokumentasinya diterbitkan dalam pekerjaan yang sama.

## Hubungan dengan Procurement

Procurement mendeklarasikan `dependsOn: business-partner: ^0.1`. Ini menyatakan prerequisite produk, bukan bukti bahwa app sudah dipasang atau siap dipakai.

Core memeriksa Business Partner tersedia di katalog, versinya memenuhi rentang yang diminta, dan graph dependency tidak membentuk siklus. Saat pilihan app dikembangkan untuk deployment, prerequisite disusun lebih dahulu. Placement Procurement tetap baru siap setelah placement Business Partner siap; entitlement, pemasangan, dan runtime readiness adalah keadaan yang berbeda.

Di lingkungan lokal, `erp-dev` membaca dependency yang sama. Menjalankan Procurement akan memasukkan Business Partner lebih dahulu:

```powershell
.\start.ps1 -Build
```

## Akses dan kontrak

Manifest saat ini hanya mendeklarasikan rantai akses baca untuk layar ringkasan:

`business-partner.overview.form` → `business-partner.overview.read` → `business-partner.overview.view` → `business-partner.overview.access`.

Hak untuk membuat, mengubah, atau menonaktifkan party belum boleh ditambahkan sebagai hak umum. Resource, aksi, scope data, dan alasan pemisahan akses harus diputuskan lebih dahulu.

OpenAPI dan AsyncAPI ada di repository app sebagai berkas fondasi. Keduanya belum menjanjikan surface lintas app untuk party.

## Yang datang dari Core

Business Partner menerima konteks tenant dan otorisasi dari Core. Core juga menyimpan katalog app, dependency produk, entitlement tenant, deployment, dan status runtime. Tidak satu pun dari itu menjadi data party di database Business Partner.

## Di mana kodenya

| Path di repository Business Partner | Isi |
| --- | --- |
| `app.yaml` | Identitas app, navigasi, security, dan dependency |
| `database/migrations/` | Migration yang kelak dimiliki app ini |
| `contracts/openapi.yaml` | Contract API app |
| `contracts/asyncapi.yaml` | Contract event app |
| `ui/src/main.tsx` | Placeholder UI saat ini |

## Halaman terkait

- [Procurement](/apps/procurement/) — app proses yang pertama kali menyatakan dependency ini
- [Katalog app](/apps/) — batas antara katalog, entitlement, pemasangan, dan runtime
- [Standar module](/dev/02-module-standard) — aturan app mandiri dan contract
- [Identity dan access](/dev/09-identity-and-access) — rantai role, duty, privilege, permission, dan entry point
