# Procurement

Procurement akan memiliki proses pengadaan. Satu record Procurement nantinya mewakili fakta proses—misalnya prakualifikasi pemasok, permintaan, penawaran, atau keputusan—bukan identitas pemasok itu sendiri.

## Identitas

| | |
| --- | --- |
| ID manifest | `procurement` |
| Publisher | `apperp` |
| Versi | `0.1.0` — release pengembangan |
| Kind | `business-app` |
| Butuh Core | `^0.1` |
| Bentuk | Belum dibangun; akan datang sebagai module di runtime Core |
| Repository | `app-erp-procurement` — repo lama, belum dipindah |
| Database | database tenant Core, dengan awalan tabel `procurement_` |
| Dependency app | tidak ada |

::: warning Belum dipindah, dan akan datang sebagai module
Modul bisnis lain sudah pindah ke runtime Core dan memakai database tenant yang sama. Procurement
belum. **Keputusan pemilik produk, 10 September 2026: ia datang sebagai module, bukan sebagai app
berkontainer** — dan dengan keputusan itu jalur hosting container dibuang seluruhnya dari repo ini.
Aturan yang berlaku untuknya karena itu adalah aturan module penuh, di
[Standar module](/dev/02-module-standard).
:::

## Batas domain

Procurement tidak memiliki party atau vendor sebagai data bersama. Ia akan menyimpan hubungan party dengan proses pengadaan dan fakta yang hanya bermakna di Procurement. Contohnya, hasil prakualifikasi adalah keputusan Procurement terhadap calon pemasok pada konteksnya; keputusan itu tidak mengubah identitas party dan tidak menjadi data Accounts Payable.

Pemecahan ini menghindari dua kesalahan yang sering tampak sama pada tahap awal:

- Membuat tabel vendor sendiri di setiap app. Hasilnya, nama atau alamat satu pemasok mudah berbeda antara Procurement dan Accounts Payable.
- Memindahkan seluruh fakta pemasok ke pemilik identitas. Hasil prakualifikasi, penawaran, atau status pengadaan lalu kehilangan pemilik proses yang jelas.

Identitas pihak—nama, alamat, dan kontaknya—dimiliki Core di [buku alamat](/dev/24-global-address-book). Ketika Procurement mulai memakai party, ia memanggil kontrak buku alamat Core dan mencatat peran `vendor` pada registry peran. Tidak ada foreign key, Eloquent relation, atau query langsung ke tabel milik module lain.

## Status fondasi saat ini

Navigasi saat ini hanya memiliki **Master data** dengan sidebar **Data master**. Halaman tersebut berisi satu placeholder dan tidak memuat data contoh, form, nomor, workflow, integrasi, atau proses bisnis.

Tidak ada master Procurement, termasuk prakualifikasi, yang sudah diimplementasikan. Dokumen ini juga tidak menetapkan field, status, kelulusan, atau hak akses prakualifikasi. Semua itu adalah keputusan task berikutnya dan tidak boleh disimpulkan dari wireframe konsultasi.

## Dependency dan deployment

Procurement **tidak menyatakan dependency app**. Ia dulu bergantung pada app `business-partner` untuk identitas pihak; app itu dihapus pada 17 September 2026 karena identitas party memang tinggal di Core, bukan di app bisnis. Yang dibutuhkan Procurement sekarang ada di Core: [buku alamat](/dev/24-global-address-book) untuk identitas, alamat, dan kontak, beserta registry peran untuk mencatat bahwa satu party adalah pemasok pada satu legal entity.

Mesin dependency katalog tetap ada dan tetap berlaku bila suatu saat Procurement bergantung pada module lain: dependency dicatat sebagai fakta katalog, siklus ditolak, dan module prasyarat dipasang lebih dulu. Ia bukan penggabungan database dan bukan pengganti contract lintas module.

Untuk runtime lokal, jalankan:

```powershell
.\start.ps1 -Build
```

## Akses dan kontrak

Manifest saat ini hanya mendeklarasikan rantai baca untuk halaman placeholder:

`procurement.overview.form` → `procurement.overview.read` → `procurement.overview.view` → `procurement.overview.access`.

Hak master atau transaksi belum tersedia. Saat resource pertama disetujui, permission harus dipisah berdasarkan resource dan aksi yang sebenarnya, bukan diberi satu hak umum “kelola Procurement”.

OpenAPI dan AsyncAPI ada sebagai berkas fondasi di repository Procurement. Belum ada endpoint atau event yang dipublikasikan untuk app lain. Untuk memakai party, Procurement memanggil kontrak buku alamat Core dalam satu proses; tidak ada contract lintas repo yang perlu menunggu.

## Yang datang dari Core

Core memberi konteks tenant, otorisasi, katalog, dependency produk, entitlement, deployment, dan runtime status. Procurement tidak menyimpan ulang data tersebut.

Identitas pemasok juga datang dari Core: party beserta alamat dan kontaknya ada di [buku alamat](/dev/24-global-address-book), dan "pihak ini pemasok kami" dicatat di registry peran per legal entity. Procurement tidak menyimpan nama atau alamat pemasok di tabelnya sendiri, dan tetap bertanggung jawab penuh atas validasi serta lifecycle data proses yang dimilikinya.

## Di mana kodenya

| Path di repository Procurement | Isi |
| --- | --- |
| `app.yaml` | Identitas app, dependency, navigasi, dan security |
| `database/migrations/` | Migration yang kelak dimiliki Procurement |
| `contracts/openapi.yaml` | Contract API app |
| `contracts/asyncapi.yaml` | Contract event app |
| `ui/src/main.tsx` | Placeholder Master data saat ini |

## Halaman terkait

- [Buku alamat](/dev/24-global-address-book) — identitas, alamat, dan kontak pihak, milik Core
- [Katalog app](/apps/) — pola satu app satu repository dan batas ownership
- [Membangun modul baru](/apps/membangun-app-baru) — gate sebelum resource atau proses baru dibuat
- [Standar module](/dev/02-module-standard) — contract dan batas lintas app
