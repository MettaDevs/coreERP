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
| Dependency app | `business-partner: ^0.1` |

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
- Memindahkan seluruh fakta pemasok ke Business Partner. Hasil prakualifikasi, penawaran, atau status pengadaan lalu kehilangan pemilik proses yang jelas.

Ketika Procurement mulai memakai party, ia memakai kontrak Business Partner. Tidak ada foreign key, Eloquent relation, atau query langsung ke tabel milik module lain.

## Status fondasi saat ini

Navigasi saat ini hanya memiliki **Master data** dengan sidebar **Data master**. Halaman tersebut berisi satu placeholder dan tidak memuat data contoh, form, nomor, workflow, integrasi, atau proses bisnis.

Tidak ada master Procurement, termasuk prakualifikasi, yang sudah diimplementasikan. Dokumen ini juga tidak menetapkan field, status, kelulusan, atau hak akses prakualifikasi. Semua itu adalah keputusan task berikutnya dan tidak boleh disimpulkan dari wireframe konsultasi.

## Dependency dan deployment

Manifest Procurement menyatakan `business-partner: ^0.1`. Dependency ini dikelola sebagai fakta katalog di Core:

1. Procurement hanya dapat didaftarkan bila Business Partner tersedia dan versinya cocok.
2. Core menolak dependency yang membentuk siklus.
3. Saat app dipilih untuk deployment, Business Partner ditempatkan lebih dahulu.
4. Procurement tidak boleh dianggap siap hanya karena dependency tercatat; setiap app harus melalui migration dan health check placement-nya sendiri.

Pernyataan dependency adalah cara produk memberi tahu deployment dan pemilihan app bahwa Business Partner adalah prerequisite. Ia bukan penggabungan database dan bukan pengganti contract lintas app.

Untuk runtime lokal, jalankan:

```powershell
.\start.ps1 -Build
```

`erp-dev` akan menemukan Business Partner sebagai dependency transitif dan menjalankannya sebelum Procurement.

## Akses dan kontrak

Manifest saat ini hanya mendeklarasikan rantai baca untuk halaman placeholder:

`procurement.overview.form` → `procurement.overview.read` → `procurement.overview.view` → `procurement.overview.access`.

Hak master atau transaksi belum tersedia. Saat resource pertama disetujui, permission harus dipisah berdasarkan resource dan aksi yang sebenarnya, bukan diberi satu hak umum “kelola Procurement”.

OpenAPI dan AsyncAPI ada sebagai berkas fondasi di repository Procurement. Belum ada endpoint atau event yang dipublikasikan untuk app lain. Contract Business Partner harus sudah ada sebelum Procurement menambahkan integrasi party.

## Yang datang dari Core dan Business Partner

Core memberi konteks tenant, otorisasi, katalog, dependency produk, entitlement, deployment, dan runtime status. Procurement tidak menyimpan ulang data tersebut.

Business Partner akan memberi data party bersama setelah resource dan contract-nya tersedia. Procurement tetap bertanggung jawab penuh atas validasi dan lifecycle data proses yang dimilikinya sendiri.

## Di mana kodenya

| Path di repository Procurement | Isi |
| --- | --- |
| `app.yaml` | Identitas app, dependency, navigasi, dan security |
| `database/migrations/` | Migration yang kelak dimiliki Procurement |
| `contracts/openapi.yaml` | Contract API app |
| `contracts/asyncapi.yaml` | Contract event app |
| `ui/src/main.tsx` | Placeholder Master data saat ini |

## Halaman terkait

- [Business Partner](/apps/business-partner/) — pemilik data pihak bisnis bersama
- [Katalog app](/apps/) — pola satu app satu repository dan batas ownership
- [Membangun modul baru](/apps/membangun-app-baru) — gate sebelum resource atau proses baru dibuat
- [Standar module](/dev/02-module-standard) — contract dan batas lintas app
