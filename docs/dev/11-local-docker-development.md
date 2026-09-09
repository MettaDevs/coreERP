# Docker development stack lokal

Stack lokal menyalakan seluruh runtime dengan satu perintah, tanpa memasang PHP, Node, atau
PostgreSQL di host.

> **Yang berubah karena pemindahan ke satu runtime.** Module bisnis tidak lagi punya container,
> database, dan repository sendiri; ia hidup di dalam repo Core pada `modules/<penerbit>/<module>/`
> dan berjalan di proses Core. App yang belum dipindah — hari ini `procurement` — masih berjalan
> sebagai container tersendiri, jadi bagian [App yang masih berupa container](#app-yang-masih-berupa-container)
> di bawah tetap berlaku. Alasannya ada di [keputusan satu runtime](../todo/satu-runtime/00-keputusan.md).

```text
<workspace>\
├─ CoreERP\                   # Core dan seluruh module di dalamnya
└─ erp-docker-start-dev\      # orchestration lokal
    ├─ compose.yaml
    ├─ start.ps1
    └─ .env                   # rahasia lokal, tidak di-commit
```

Dua folder itu saja. Nama folder orchestration harus persis, karena `compose.yaml` membangun dari
relative path ke `../CoreERP`.

## Container yang menyala

`compose.yaml` memakai Compose project `erp`. Daftar layanannya ada di berkas itu; yang perlu
diketahui sebelum membukanya:

| Layanan | Peran |
| --- | --- |
| `core-app` | Web, sekaligus tempat seluruh module berjalan |
| `core-worker` | Queue worker; ekspor laporan dan job onboarding dikerjakan di sini |
| `core-scheduler` | Penjadwal; tanpa ini `number-sequences:recover` tidak pernah jalan |
| `core-db` | PostgreSQL |
| `core-renderer` | Engine PDF (Gotenberg) pada network internal tanpa jalan keluar |
| `docs` | Situs dokumentasi CoreERP |

`core-app` dan `core-worker` berbagi volume `core-storage` untuk layout unggahan dan hasil ekspor;
lihat [dokumen cetak, layout, dan ekspor](23-document-rendering.md). Opsi `-HotReload` menambahkan
satu container Vite selama opsi itu dipakai, dan menghapusnya lagi setelahnya.

Service `docs` menyajikan situs ini pada `http://localhost:18090`. Ia dibangun saat build image,
jadi perubahan dokumen baru tampil setelah `start.ps1 -Build`. Untuk menulis dokumen, jalankan
`npm run docs:dev` dari `CoreERP/docs` di host agar mendapat hot reload; ia tidak bergantung pada
stack ini.

## Menjalankan

```powershell
cd <workspace>\erp-docker-start-dev
.\start.ps1         # pertama kali dan penggunaan harian
.\start.ps1 -Build  # setelah Dockerfile atau source berubah
```

Script menjalankan migration, seed Core, registrasi manifest tiap module terpilih, migration
module, health check, lalu mencatat release lokal sebagai siap.

`-CoreOnly` menyalakan Core tanpa satu pun module — berguna saat onboarding. `-Apps <id module>`
membatasi ke module tertentu beserta dependency transitifnya; ia menerima **id manifest**, bukan
nama folder.

## Module ditemukan dengan memindai repo

Script memindai `CoreERP/modules/<penerbit>/<module>/app.yaml` setiap kali dijalankan. Module baru
karena itu ikut terbaca begitu foldernya ada, dan **stack tidak perlu diubah sama sekali** saat
sebuah module ditambahkan. Tidak ada daftar service, daftar port, atau daftar manifest yang harus
disusul.

Module tidak punya port sendiri untuk dibuka. Halamannya dirender shell Core pada jalur
`/<id module>/<id entri menu>`, dan menunya muncul setelah module itu dipasang untuk tenant yang
sedang dibuka.

Module ber-`kind: internal-fixture` sengaja tidak dapat dipilih; ia bahan uji penjaga batas dan
Core sendiri menolak mendaftarkannya ke katalog. Kodenya tetap dimuat runtime.

## Akses database lokal

Satu database untuk Core dan seluruh module, dapat diakses dari pgAdmin melalui `localhost`:

| Database | Port | Database | User |
| --- | ---: | --- | --- |
| Core | 5543 | `core_erp` | `core_erp` |

Password ada pada `.env` di folder orchestration. Port hanya bind ke `localhost`; PostgreSQL yang
sudah terpasang di host tidak dipakai stack ini.

Batas antar module di dalam database itu dijaga **awalan nama tabel** dan trait `MilikTenant`,
bukan database terpisah. Aturannya di [standar module](02-module-standard.md#nama-tabel).

## Checklist menambah module baru

1. Buat folder `modules/<penerbit>/<module>/` di dalam repo CoreERP. Bentuk folder yang wajib,
   aturan namespace, dan awalan tabelnya ada di `modules/README.md`.
2. Tulis `app.yaml`: identitas, navigasi, empat lapis keamanan, reference nomor bila ada.
3. Jalankan `.\start.ps1 -Build`. Manifest didaftarkan, migration module dijalankan, dan menunya
   muncul di shell setelah module dipasang untuk tenant yang dibuka.

Tidak ada langkah menambah service Compose, mengalokasikan port, atau mendaftarkan alamat UI.
Ketiganya hilang bersama container per app.

Module tidak boleh menyentuh tabel milik module lain. Pada app berkontainer batas itu dijaga
database terpisah; pada module ia dijaga penjaga batas di
`apps/control-plane/tests/Feature/Boundary/` dan analisa statis.

## App yang masih berupa container

App yang belum dipindah ke runtime Core tetap memakai jalur lama: tiga service Compose
(`<app>-db`, `<app>-api`, `<app>-ui`), database sendiri, dan token layanan sendiri. Shell menyusun
path kontennya dari pasangan `(app_id, placement)` lalu container `core-app` mem-proxy path itu ke
container UI app:

```text
http://localhost:8000/apps-content/<placement>/<app-id>/
```

Konfigurasi proxy dirender saat container web naik, oleh `docker/entrypoint.sh` yang memanggil
`app:render-proxy-config`. Karena config itu statis, **placement yang dibuat setelah container
hidup baru dilayani setelah `core-app` di-restart**. Command melaporkan setiap placement yang
dilewati beserta alasannya, jadi periksa lognya bila sebuah app tidak muncul.

Dua akibat yang memudahkan pekerjaan sehari-hari:

- Alamat tidak terikat IP mesin. Ganti jaringan, ganti lease DHCP, atau buka dari laptop lain di
  LAN lewat `http://<ip-mesin>:8000` — semuanya tetap bekerja tanpa menyentuh database.
- UI app harus di-build dengan base relatif (`base: './'` pada Vite). Prefix path memuat nama
  placement, sedangkan satu image UI dipakai semua placement.

Jalur ini tidak dihapus dan tidak boleh dihapus selama masih ada app yang menjalankannya.

## Git

Folder orchestration adalah repository tersendiri dan tidak dipush ke repo Core. `.env` dan berkas
compose hasil generate ada di `.gitignore`; jangan commit `.env`, volume, image hasil build, atau
password. Kalau butuh secret baru untuk Core, tambahkan di `start.ps1` dan `.env.example`, jangan
menyunting `.env` dengan tangan.

## Lihat juga

- [Menyiapkan lingkungan lokal](../onboarding/setup.md) — versi langkah demi langkah untuk anggota tim baru
- [Standar module](02-module-standard.md) — isi wajib satu module
- [Menerbitkan release app](13-publishing-an-app-release.md) — dari stack lokal ke katalog resmi
- [Release dan on-prem](03-release-and-on-prem.md) — mekanisme yang sama di lingkungan nyata
