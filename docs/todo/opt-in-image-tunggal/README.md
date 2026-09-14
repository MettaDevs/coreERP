# Opt-in: satu image untuk semua klien on-prem dikelola

**Status: opt-in, tidak sedang dikerjakan.** Ditulis 14 September 2026 dari percakapan dengan pemilik
produk. Halaman ini baru diambil kalau pemilik produk memintanya. Sampai saat itu, yang berlaku
adalah skema di [On-prem yang dikelola vendor](/todo/on-prem-dikelola/): paket **per kombinasi modul**,
dirakit di server kita sendiri, lalu disajikan admin.erp ke agen.

Isinya lima usulan yang **dapat dipilih satu per satu**. Tidak ada yang saling mewajibkan, kecuali
yang disebut di bagiannya.

| | Usulan | Pengganti untuk |
| --- | --- | --- |
| A | Satu image berisi semua modul untuk semua klien; app yang dapat dibuka diatur dari admin.erp | image per kombinasi modul |
| B | Lisensi **mengunci**, dan isinya dibaca dari admin.erp | lisensi yang hanya memperingatkan |
| C | Image dirampingkan: tanpa compiler dan header C | image berbasis `php:8.4-apache` apa adanya |
| D | Versi hanya lahir dari tag (`v1.2.0`) | versi dari tanggal commit setiap kali paket diminta |
| E | Tempat menyimpan image: GHCR, atau tetap admin.erp | admin.erp |

## Yang sudah diukur

Diukur 14 September 2026 dari image edisi di GHCR, commit `e4d4e6b`.

| Image | Ukuran terkompres |
| --- | --- |
| `edisi-praktek-dr-budi` (Core saja) | 237,5 MB |
| `edisi-apotek-sejahtera` (Core + modul aset) | 238,0 MB |

**Satu modul menambah sekitar setengah megabyte.** Source terkompres modul aset sekitar 0,4 MB. Jumlah
modul hampir tidak memengaruhi ukuran image; yang memengaruhi penyimpanan adalah **jumlah versi** yang
disimpan.

Isi image Core saja, per lapisan:

| Bagian | MB terkompres |
| --- | --- |
| Perkakas kompilasi bawaan image `php` resmi (`gcc`, `g++`, header C) | 120,8 |
| Library dan header `-dev`, ekstensi PHP, klien PostgreSQL — dari `apps/core/Dockerfile` | 42,3 |
| Debian dasar | 30,8 |
| Source PHP dan PHP hasil kompilasi, bawaan image `php` | 27,6 |
| Apache | 4,2 |
| **Kode Core beserta `vendor/`** | **10,6** |
| Aset tampilan hasil build | 1,1 |

Kode kita sendiri kurang dari 12 MB. Sisanya bawaan image dasar yang tidak pernah dipakai di server
klien.

## A. Satu image untuk semua klien

Image dibangun **sekali per versi**, berisi Core dan seluruh modul — sama dengan image SaaS. Tenant di
server klien hanya dapat membuka app yang ada di `tenant_app_entitlements`, dan daftar itu datang dari
admin.erp lewat agen.

**Yang didapat:**
- Perakit tidak lagi punya antrean per kombinasi modul. Satu build per versi.
- Klien yang menambah app cukup diubah di admin.erp. Tidak ada build ulang, tidak ada pemasangan ulang.
- Pemasangan pertama tidak pernah menunggu paket dirakit.

**Yang dibayar:**
- **Kode seluruh modul ada di server klien**, termasuk yang tidak dibeli. Image PHP berisi berkas
  `.php` biasa; siapa pun yang punya akses root atau Docker di server itu dapat menyalinnya keluar
  (`docker cp`, `docker exec`) dan membacanya. Aset tampilan dipadatkan, tetapi tetap dapat dibaca.
- Menyembunyikan kode PHP menuntut encoder berbayar seperti ionCube atau SourceGuardian. Itu keputusan
  tersendiri, dan tidak termasuk usulan ini.
- Siapa pun yang punya akses root dapat mengubah database untuk menyalakan app yang tidak dibayar.
  Penjaganya ada di usulan B. Tanpa B, yang tersisa hanya agen yang menyamakan app aktif dengan
  admin.erp setiap putaran, dan situs yang tampil "Tertinggal" kalau agennya dimatikan.

## B. Lisensi yang mengunci

Menggantikan keputusan "lisensi hanya memperingatkan" di PRD on-prem dikelola.

- **admin.erp sumber kebenarannya.** Lisensi adalah berkas yang ditandatangani admin.erp, berisi
  tenant, daftar app yang dibeli, dan tanggal berlaku.
- **Core di server klien memeriksa tanda tangannya.** App yang tidak ada di lisensi tidak dapat dibuka,
  walaupun database diubah. Mengubah database tidak dapat menghasilkan tanda tangan yang sah.
- **Masa berlakunya pendek** — misalnya 30 hari — dan diperpanjang agen secara otomatis selama sewa
  aktif. Agen yang dimatikan, internet yang diblokir, atau sewa yang berhenti membuat lisensi tidak
  diperpanjang, habis, lalu mengunci.
- **Bentuk kuncinya**, usulan awal:
  - peringatan tampil 7 hari sebelum habis;
  - setelah habis, pengguna melihat layar "Lisensi habis, hubungi vendor";
  - akun provider tetap dapat masuk untuk perbaikan;
  - data tidak disentuh.
- **Batasnya:** orang yang sanggup mengubah kode PHP di server klien dapat membongkar kunci ini.
  Suntingannya tertimpa setiap pembaruan, dan agen dapat memeriksa bahwa image yang berjalan sama
  dengan digest rilisnya.

B berguna juga tanpa A: dengan image per kombinasi modul, B menjaga masa sewa, bukan daftar modul.

## C. Image ramping

Penyebab ukurannya ada di tabel di atas. Image `php:8.4-apache` resmi membawa perkakas kompilasi
secara permanen supaya `docker-php-ext-install` dapat dipakai di atasnya, dan `apps/core/Dockerfile`
sengaja membiarkannya.

Cara merampingkannya:
1. Ekstensi PHP, termasuk `opentelemetry` dari `pecl`, dikompilasi di tahap build tersendiri.
2. Tahap akhir memakai Debian slim dengan PHP 8.4 dan Apache tanpa compiler dan tanpa paket `-dev`. Yang
   disalin hanya hasil kompilasi ekstensinya dan library runtime yang dibutuhkannya.

Perkiraan ukurannya 70–90 MB terkompres. **Angka itu belum diuji.**

`apps/core/Dockerfile` dipakai SaaS juga, jadi perubahannya diuji di kedua jalur: suite Core,
`scripts/verify-edition.sh`, dan penyebaran dev.

## D. Versi dari tag

Satu versi berarti satu image yang dibangun dari satu titik kode.

- Versi hanya lahir ketika seseorang menerbitkan tag bernomor versi, misalnya `v1.2.0`. Tag dapat
  dibuat dari halaman **Releases** GitHub tanpa terminal.
- Perakit mendeteksi tag baru lalu membangun image bernama versi itu. Merge biasa ke `main` tidak
  menghasilkan versi.
- admin.erp menampilkan "Versi v1.2.0 tersedia". Operator memilih kapan setiap klien diperbarui.
- Versi lama dibuang otomatis; dua sampai tiga versi terakhir disimpan untuk kembali mundur.

Yang digantikannya: nomor rilis `YYYY.MMDD.HHMMSS` dari tanggal commit, yang lahir setiap kali
operator meminta paket terbaru.

## E. Tempat menyimpan image

| | GHCR | admin.erp (yang berjalan) |
| --- | --- | --- |
| Biaya | Penyimpanan dan bandwidth image di Container registry **saat ini gratis**, termasuk paket privat. GitHub berjanji memberi tahu paling lambat sebulan sebelum kebijakan itu berubah. Kuota paket privat untuk organisasi gratis — 500 MB penyimpanan dan 1 GB transfer per bulan — belum diberlakukan untuk image | Disk dan bandwidth server sendiri |
| Unduhan saat pembaruan | Hanya lapisan yang berubah | Seluruh `images.tar.gz`, kecuali admin.erp menjalankan registry sendiri |
| Akses dari server klien | Repo akan privat lagi, jadi server klien butuh token baca. Tokennya milik akun GitHub khusus yang hanya boleh membaca paket, bukan akun pribadi. Token itu terbaca root klien | Permintaan bertanda tangan kunci situs; setiap situs hanya mendapat paket miliknya |
| Menit GitHub Actions | Tidak terpakai bila image dibangun perakit di server kita lalu didorong ke GHCR | Tidak terpakai |

Dengan A, token baca GHCR hanya membuka image yang memang dikirim ke klien itu. Tanpa A, token yang
sama membuka paket kombinasi modul klien lain.

## Kalau diambil, yang berubah dari skema yang berjalan

- **A:**
  - `sites.edition` berhenti diturunkan dari modul yang dibeli, dan antrean `site_package_builds` per
    edisi menjadi satu build per versi;
  - daftar app yang dibeli ikut dikirim agen ke Core di setiap putaran, bukan hanya saat
    `tenant:bootstrap-site`.
- **B:**
  - lisensi berisi daftar app;
  - Core menolak app di luar lisensi;
  - tampilan lisensi habis menjadi kunci, bukan banner.
- **C:** `apps/core/Dockerfile` bertahap. Tidak mengubah skema database.
- **D:** perakit memicu build dari tag, bukan dari permintaan operator; penomoran rilis di
  `site_releases` mengikuti tag.
- **E:** agen dan `update.sh` menarik image lewat digest dari registry, dan server klien menyimpan token
  baca. Jalur itu sudah pernah dibangun di PR #118.

## Sumber

- [About billing for GitHub Packages](https://docs.github.com/en/billing/concepts/product-billing/github-packages)
  — kuota paket privat, dan status Container registry yang saat ini gratis.
- [About billing for GitHub Actions](https://docs.github.com/en/billing/concepts/product-billing/github-actions)
  — pemakaian gratis untuk runner sendiri dan untuk repo publik.
- [getsentry/self-hosted](https://github.com/getsentry/self-hosted): `docker-compose.yml`, `.env`, dan
  `sentry/Dockerfile`. Pola rujukannya: image jadi dari registry, lapisan tipis dibangun di server
  pengguna.
