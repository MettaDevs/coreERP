# Docker development stack lokal

Stack lokal menyalakan seluruh runtime dengan satu perintah, tanpa memasang PHP, Node, atau
PostgreSQL di host.

> **Yang berubah karena pemindahan ke satu runtime.** Module bisnis tidak lagi punya container,
> database, dan repository sendiri; ia hidup di dalam repo Core pada `modules/<penerbit>/<module>/`
> dan berjalan di proses Core. App yang belum dipindah — hari ini `procurement` — masih berjalan
> sebagai container tersendiri, jadi bagian [App yang masih berupa container](#app-yang-masih-berupa-container)
> di bawah tetap berlaku. Alasannya ada di [grand design](01-grand-design.md).

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

Registrasi dan migration module dipanggil per id — `app:register-manifest <id>` lalu
`module:migrate <id>` — bukan dengan jalur berkas: Core menemukan sendiri `app.yaml` module lewat
registry-nya, dan jalur yang diketik dari luar berarti dua sumber kebenaran yang bisa berselisih.
Keduanya **aman dijalankan berkali-kali**. Pendaftaran katalog menulis keadaan yang sama pada jalan
kedua, dan `module:migrate` menyimpan riwayatnya sendiri lalu melewati migration yang sudah pernah
jalan. Menjalankan ulang script karena itu bukan tindakan yang perlu dipikir dua kali.

`-CoreOnly` menyalakan Core tanpa satu pun module — berguna saat onboarding. `-Apps <id module>`
membatasi ke module tertentu beserta dependency transitifnya; ia menerima **id manifest**, bukan
nama folder.

## Stack lokal tidak boleh mati lebih dari satu task

Script pengembangan adalah satu-satunya cara seluruh tim menjalankan seluruh runtime, jadi ia
diperlakukan sebagai jalur yang tidak boleh putus: **setiap pull request meninggalkan `start.ps1`
dalam keadaan menyala**. Perubahan yang terlalu besar untuk memenuhi syarat itu sekali jalan
dipecah menjadi beberapa PR yang masing-masing tetap menyala — bukan digabung menjadi satu PR
besar yang mematikan stack sementara.

Alasannya bukan kerapian. Begitu stack mati, tidak ada lagi yang bisa membuktikan perubahan
berikutnya benar-benar berjalan, dan kegagalan menumpuk sampai tidak ketahuan mana yang menyebabkan
mana.

## Muat-ulang-panas: bekerja, dan empat hal yang harus benar sekaligus

Diuji pada 10 September 2026 sampai perubahan benar-benar tampil di layar tanpa muat ulang. Angka
di bawah diukur, bukan diperkirakan.

| Yang diukur | Hasil |
| --- | --- |
| Jeda dari berkas disimpan sampai peramban meminta modul barunya | 0,01 detik |
| Mengambil modul JS yang berubah | 0,15 detik |
| Membangun ulang `app.css` | 0,54 detik |
| Halaman dimuat ulang | tidak |

Sebelum keempat perbaikan di bawah, angka yang sama adalah **99–102 detik** untuk `app.css` — dan
karena Vite menahan seluruh isi satu paket pembaruan sampai bagian terlambatnya selesai, perubahan
JS yang sudah siap dalam 0,5 detik ikut tertahan selama itu.

Itu bentuk kegagalan yang paling menyesatkan yang ditemui sepanjang pengerjaan ini: **muat-ulang-panas
tidak pernah rusak, ia kelaparan.** Pembaruan tetap terkirim dan tetap diterapkan, hanya jauh setelah
siapa pun berhenti menunggu. Yang terlihat pengembang: menyimpan berkas, layar diam, lalu ia menekan
muat ulang — dan muat ulang terasa cepat justru karena antrean tadi sudah menghangatkan cache-nya.

### 1. Server pengembangan dijalankan di host, bukan di dalam container

Ini yang paling berpengaruh, dan satu-satunya yang tidak dapat diperbaiki lewat setelan.

Tailwind membaca ulang seluruh berkas sumbernya setiap kali satu baris berubah. Menembus bind mount
dari drive Windows ke container, satu berkas berharga puluhan milidetik; dibaca langsung oleh
Windows, ia kembali ke ukuran normal. Selisihnya terukur **99 detik lawan 5,5 detik** pada
konfigurasi yang persis sama.

Jalankan `npx vite --host 127.0.0.1 --port 5173` dari `apps/control-plane` di Windows, dan biarkan
sisa stack tetap di container. PHP, database, worker, penjadwal, dan perender tidak membaca ratusan
berkas per perubahan, jadi keduanya tidak terganggu bind mount.

### 2. Yang dipantau pengawas berkas dibatasi

`server.watch.ignored` pada `vite.config.ts` membuang `vendor/`, `storage/`, `bootstrap/cache/`, dan
`public/build/`. Daftar abaian bawaan Vite tidak memuat `vendor/`, padahal di bawah folder app ada
belasan ribu berkas dan sebagian besar miliknya.

Ketika pengawas terpaksa memoll — dan di dalam container ia terpaksa, karena inotify tidak merambat
lewat bind mount — memoll belasan ribu berkas tiap seratus milidetik membanjiri threadpool libuv yang
hanya berisi empat utas. Terukur: proses Vite yang benar-benar menganggur membakar **252% CPU**;
sesudah dibatasi, **6,8%**.

### 3. Yang dipindai Tailwind dipersempit

`app.css` memakai `source(none)` dan menyebut sumbernya satu per satu, alih-alih membiarkan Tailwind
memindai otomatis dari akar repositori. Sumber paket antarmuka juga ditunjuk ke `src`, bukan ke
`dist` — kelasnya sama, berkasnya seperempatnya.

Pembangunan `app.css` turun dari 5,5 detik menjadi **0,365 detik**, dan keluarannya justru lebih
tepat: satu kelas yang hilang, `lowercase`, ternyata tidak pernah dipakai satu komponen pun. Ia lahir
karena pemindaian otomatis membaca kata "lowercase" di dalam berkas PHP aturan validasi.

Kalau kelak sebuah kelas hilang dari tampilan, itu tandanya ada sumber baru yang belum disebut di
`app.css` — bukan tanda bahwa `source(none)` keliru.

### 4. Mesin SSR plugin Inertia dimatikan

Repo ini tidak punya berkas entri SSR. Ketika tidak ada, plugin menuruni daftar kandidatnya dan
berhenti di `resources/js/app.tsx` — entri **klien** — lalu memuatnya di lingkungan SSR saat server
menyala. Ia tersangkut, dan gagal setelah menunggu satu menit penuh:

```
Failed to warm up Inertia SSR module graph: transport invoke timed out after 60000ms
```

Selama menit itu server pengembangan berhenti menjawab, dan gejalanya menyamar sebagai hal lain
sama sekali: aset gagal dimuat, halaman kosong, pembaruan tidak sampai — berubah-ubah tergantung
kapan sebuah permintaan kebetulan jatuh. `inertia({ ssr: false })` mematikannya; waktu nyala Vite
turun dari puluhan detik menjadi 1,4 detik.

### Kalau mengukurnya sendiri, jangan memakai `?t=` buatan

Setiap permintaan `app.css?t=<angka>` yang dibuat tangan **menambah satu modul permanen** ke graf
Vite, dan sejak itu setiap paket pembaruan ikut menyeret semuanya. Pengukuran yang dilakukan begitu
akan melaporkan angka yang memburuk pada tiap percobaan berikutnya — bukan karena sistemnya
melambat, melainkan karena alat ukurnya menumpuk beban.

Cara yang tidak mencemari: baca `?t=` yang **dipasang Vite sendiri** pada modul yang diperbarui — ia
sama dengan waktu ubah berkasnya — lalu bandingkan dengan `performance.getEntriesByType('resource')`
di peramban.

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

Module tidak boleh menyentuh tabel milik module lain. Yang menjaganya adalah penjaga batas di
`apps/control-plane/tests/Feature/Boundary/` dan analisa statis.

## Image dibangun dari akar repo, dan menirukan susunannya

Konteks pembangunan `apps/control-plane/Dockerfile` adalah **akar repo**, bukan folder app:

```powershell
docker build -f apps/control-plane/Dockerfile .
```

`compose.yaml` di folder orchestration memakai bentuk yang sama: `context` menunjuk
`COREERP_SOURCE_PATH` dan `dockerfile` menunjuk `apps/control-plane/Dockerfile`.

Itu bukan pilihan gaya. Module diautoload lewat repositori Composer bertipe `path` yang menunjuk
`../../modules/*/*` dengan `"symlink": true`, jadi Composer memasang tiap module sebagai tautan
simbolik dari `vendor/<penerbit>/<module>` ke `modules/<penerbit>/<module>`. Tautan itu hanya sah
bila jarak `vendor` ke `modules` di dalam image sama persis dengan jaraknya di repo. Selama konteks
pembangunan masih folder app, `modules/` berada di luar jangkauannya dan pemasangan dependensi gagal
sebelum sempat menyalin apa pun. Karena itu image memakai `/repo/apps/control-plane` dan
`/repo/modules`, persis seperti repo.

Dua jalan lain pernah dipertimbangkan dan keduanya ditolak:

| Jalan | Kenapa ditolak |
| --- | --- |
| Composer menyalin module ke `vendor` alih-alih menaut | kode module jadi punya dua salinan di dalam image, dan menyunting salah satunya tidak mengubah yang lain |
| Menaruh `modules/` di tempat yang kebetulan cocok dengan hitungan tautan | ia bekerja karena kebetulan, dan berhenti bekerja begitu ada yang memindahkan folder |

Bagian autoload-nya ditulis di dua tempat dan keduanya wajib:

- `apps/control-plane/composer.json` menyebut repositori `path` itu dan me-require tiap module
  dengan **`@dev`**. Batasan versi `*` tidak bisa dipakai — Composer menolaknya karena tidak
  memenuhi `minimum-stability`, dan pesan kesalahannya tidak menyebut sebab itu sama sekali.
- `modules/<penerbit>/<module>/composer.json` mendeklarasikan `autoload.psr-4` untuk namespace
  module itu sendiri. Tanpa itu pemetaan PSR-4-nya tidak dipakai siapa pun.

## `composer update` di repo ini dijalankan dengan `--no-scripts`

`apps/control-plane/composer.json` memasang `post-update-cmd` yang memanggil `install:features` dan
`vendor:publish --tag=laravel-assets --force`. Keduanya menulis banyak berkas yang tidak ada
hubungannya dengan pekerjaan yang sedang dikerjakan, dan hasilnya masuk ke dalam diff tanpa ada yang
memintanya — ini sudah terjadi lebih dari sekali di repo ini.

```powershell
composer update --no-scripts
```

Apa pun bentuk perintahnya, periksa berkas apa saja yang ikut berubah sesudahnya. Yang berubah di
luar dugaan dikembalikan, bukan ikut di-commit karena sudah terlanjur ada di sana.

## Akar npm adalah akar repo

`package.json` di akar repo mendeklarasikan workspace `apps/control-plane` dan `packages/ui`; Core
meminta paket antarmuka sebagai `"@apperp/ui": "*"`, dan npm memasangnya sebagai tautan simbolik ke
`packages/ui`. Akarnya harus akar repo, bukan `apps/control-plane`: npm mengangkat dependensi
workspace ke `node_modules` milik akar, dan bila akar itu bukan leluhur `packages/ui`, `tsc` di
dalam paket itu berhenti pada `react` yang tidak ditemukan.

Yang mengikuti dari keputusan itu, dan semuanya wajib:

- `npm ci` dijalankan dari akar repo. `apps/control-plane` tidak punya lockfile sendiri; yang ada
  `package-lock.json` di akar.
- `.npmrc` yang memuat `ignore-scripts=true` juga di akar, karena npm mengabaikan `.npmrc` milik
  workspace. Berkas penjaga yang tidak lagi menjaga apa pun adalah yang paling berbahaya dibiarkan.
- Tidak ada berkas `.tgz` di repo. Paket antarmuka tidak lagi disebarkan sebagai berkas paket.
- **Script pengembangan tidak boleh menyentuh berkas paket antarmuka atau menyelaraskan
  lockfile-nya.** Penyelaras lama yang dijalankan atas `apps/control-plane` justru menghapus tautan
  simbolik `node_modules/@apperp/ui`; fungsi yang dulu menyelaraskan pemasangan kini merusaknya.

`apps/control-plane/Dockerfile` menirukan susunan yang sama pada tahap aset: pemasangan npm berjalan
dari `/repo`, dengan `packages/ui` di bawahnya.

## Vite dan tsc harus diberi tahu soal folder di luar akar proyek

UI module berada di `modules/<penerbit>/<module>/ui/`, di luar `apps/control-plane`. Dua setelan
karena itu wajib ada, dan keduanya sudah terpasang:

- `apps/control-plane/vite.config.ts` mengizinkan akar repo lewat `server.fs.allow`. Tanpa itu
  server pengembangan membalas 403 untuk berkas module sementara `npm run build` tetap berhasil, dan
  kegagalan yang hanya muncul di satu dari dua jalur adalah yang paling lama dicari.
- `apps/control-plane/tsconfig.json` menyertakan `../../modules/*/*/ui/**` pada `include`. Tanpa itu
  `npm run types:check` melewatkan halaman module diam-diam, dan halaman yang tidak bisa dikompilasi
  baru ketahuan saat build, di alur yang berbeda dengan pesan yang berbeda.

Yang perlu dijaga terpisah adalah penyelesaian **nama paket**, bukan jalur berkas. Impor
`@apperp/ui` dari dalam berkas module diselesaikan dengan pendakian `node_modules` biasa, dan itu
bekerja justru karena akar repo adalah akar workspace npm — dari `modules/<penerbit>/<module>/ui`
pendakian itu berakhir di akar repo, yang sekarang punya `node_modules`. Bila suatu saat penyelesaian
itu perlu ditolong lagi, alatnya `resolve.dedupe` pada Vite dan pemetaan `paths` pada tsc, **bukan
alias**: alias melewati peta `exports` paket dan menuntut jalur `dist/` ditulis dengan tangan.

Alias `@modules` yang ada pada kedua berkas itu tujuannya lain — ia untuk kode yang menyebut satu
berkas module secara langsung, bukan untuk menyelesaikan nama paket.

## Tidak ada lagi app berkontainer

Sampai 10 September 2026 halaman ini menjelaskan jalur kedua: tiga service Compose per app
(`<app>-db`, `<app>-api`, `<app>-ui`), database sendiri, token layanan sendiri, dan container
`core-app` yang mem-proxy `/apps-content/<placement>/<app-id>/` ke container UI app.

Jalur itu **dibuang seluruhnya**. Pemilik produk memutuskan pada hari yang sama bahwa app terakhir
yang belum dipindah datang sebagai module, jadi jalur itu tidak punya subjek di masa depan — dan
`compose.yaml` pada repo `erp-dev` memang sudah lama hanya menyusun layanan `core-*` beserta `docs`.
Perintah proxy-nya sudah tidak dipanggil `docker/entrypoint.sh`, dan dua modul Apache yang hanya ada
untuk melayaninya sudah tidak dinyalakan `Dockerfile`.

Yang tersisa untuk membuka sebuah produk di stack lokal hanyalah satu tautan:

```text
http://localhost:8000/apps/<app-id>
```

Ia mengalihkan ke entri menu pertama yang boleh dilihat pengguna yang sedang masuk, dan halaman itu
dirender module sebagai halaman Inertia biasa pada `/<id module>/<id entri menu>`.

## Git

Folder orchestration adalah repository tersendiri dan tidak dipush ke repo Core. `.env` dan berkas
compose hasil generate ada di `.gitignore`; jangan commit `.env`, volume, image hasil build, atau
password. Kalau butuh secret baru untuk Core, tambahkan di `start.ps1` dan `.env.example`, jangan
menyunting `.env` dengan tangan.

## Lihat juga

- [Menyiapkan lingkungan lokal](../onboarding/setup.md) — versi langkah demi langkah untuk anggota tim baru
- [Standar module](02-module-standard.md) — isi wajib satu module
- [Mendaftarkan katalog produk](13-publishing-an-app-release.md) — dari stack lokal ke katalog resmi
- [Release dan on-prem](03-release-and-on-prem.md) — mekanisme yang sama di lingkungan nyata
