# App

Halaman di bagian ini bersifat **teknis dan internal** — ditujukan untuk developer yang membangun atau merawat app, bukan panduan pemakaian untuk pengguna bisnis.

Sebuah app bisa berjalan dalam dua bentuk, dan aturannya berbeda:

- **Module** hidup di `modules/<penerbit>/<module>/` pada repo CoreERP, berjalan di runtime Core, dan memakai database tenant yang sama. Ini bentuk yang berlaku untuk pekerjaan baru.
- **App berkontainer** punya repository, database, image, dan token layanan sendiri. Bentuk lama, dipertahankan selama masih ada app yang menjalankannya.

Tabel perbandingan lengkapnya ada di [Grand design](/dev/01-grand-design#dua-bentuk-yang-hidup-berdampingan).

## Katalog

| App | ID manifest | Bentuk | Status |
| --- | --- | --- | --- |
| [Management Aset](/apps/management-aset/) | `management-aset` | Module di `modules/apperp/management-aset/` | Release pengembangan `0.1.0` |
| [Human Resources](/apps/human-resources/) | `human-resources` | Module di `modules/apperp/human-resources/` | Release pengembangan `0.1.0` |
| [Business Partner](/apps/business-partner/) | `business-partner` | Belum dipindah | Fondasi release pengembangan `0.1.0` |
| [Procurement](/apps/procurement/) | `procurement` | App berkontainer, repo `app-erp-procurement` | Fondasi release pengembangan `0.1.0` |

Daftar module yang benar-benar terpasang di sebuah runtime dibaca dari `php artisan module:list`,
bukan dari tabel ini. Tabel ini menunjuk halamannya; runtime yang menyebut isinya.

## Mau membangun modul baru?

Jangan mulai dari menyalin folder modul yang sudah jadi — Anda akan ikut membawa keputusan
domainnya. Mulai dari [Membangun modul baru](/apps/membangun-app-baru): ada gate keputusan yang
harus dilewati sebelum baris kode pertama, dan proposalnya butuh persetujuan.

## Isi halaman app

Tiap halaman app di sini memuat hal yang sama, dengan urutan yang sama:

| Bagian | Isinya |
| --- | --- |
| Identitas | ID manifest, publisher, versi, bentuk penempatan, awalan tabel |
| Domain yang dimiliki | Data apa yang app ini pegang, dan apa yang bukan miliknya |
| Kontrak | Permukaan yang dipanggil dari luar, reference nomor, tipe workflow |
| Struktur kode | Di mana barang disimpan |
| Status terhadap gate | Gate mana yang sudah lewat, mana yang belum, dengan buktinya |
| Menjalankan | Cara menyalakannya di stack lokal |
| Dokumen terkait | Aturan platform yang berlaku |

Cetakannya ada di `docs/apps/_template/index.md`. Salin folder itu menjadi `docs/apps/<id module>/`, isi placeholder-nya, lalu daftarkan pada sidebar dan tabel katalog di atas. Cetakan itu sendiri tidak dirender jadi halaman.

## Di mana dokumen sebuah module hidup

Untuk module, jawabannya jadi sederhana: **di sini**, karena kode dan dokumennya sudah satu repo.
Halaman arsitektur, master, dan transaksi Management Aset adalah contohnya.

Aturan lama masih berlaku untuk app yang belum dipindah: dokumen yang berubah bareng kode hidup di
repo yang sama dengan kodenya, karena kalau tidak, memperbaruinya butuh dua pull request di dua repo
— dan dokumen mati dengan cara persis seperti itu.

## Aturan yang berlaku untuk semua app

Tiga hal yang tidak bisa ditawar, apa pun bentuk penempatannya:

**Satu app tidak menyentuh data app lain.** Pada app berkontainer yang menolak adalah database
terpisah; pada module yang menolak adalah penjaga batas di
`apps/control-plane/tests/Feature/Boundary/` dan analisa statis. Satu database yang sama bukan izin
untuk melakukan `join` ke tabel modul sebelah.

**Data tenant memakai konteks tepercaya dari Core.** Jangan menerima `tenant_id` atau scope organisasi bebas dari browser. Module membacanya lewat kontrak `KonteksTenant` dan `KonteksPermintaan`.

**Nomor dokumen diterbitkan Core.** App mendeklarasikan reference pada manifest; admin tenant yang mengaktifkan dan mengatur formatnya.

## Lihat juga

- [Standar module](/dev/02-module-standard) — kontrak lengkap satu module
- [Gate penemuan dan keputusan](/dev/18-module-discovery-and-decision-gate) — sebelum module dibuat
- [Menerbitkan release app](/dev/13-publishing-an-app-release) — dari manifest ke katalog Core
- [Grand design](/dev/01-grand-design#dua-bentuk-yang-hidup-berdampingan) — dua bentuk penempatan dan aturannya
