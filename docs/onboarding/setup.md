# Menyiapkan lingkungan lokal

Target: `http://localhost:8000` terbuka dan bisa dipakai, tanpa memasang PHP, Node, atau PostgreSQL di laptop.

## Yang perlu ada dulu

- **Docker Desktop**, jalan
- **Git**
- **Akses ke repo CoreERP dan repo orkestrasi lokal** — minta ke maintainer
- **PowerShell** (skrip stack lokal berbasis PowerShell)
- Koneksi internet untuk build pertama — image Docker dan dependency npm publik masih ditarik dari jaringan

Node dan akun registry npm **tidak** diperlukan. Stack membangun SDK UI di dalam Docker.

## Langkah 1: clone dengan nama folder yang benar

::: danger Baca bagian ini, jangan dilewat
Nama repository **tidak selalu sama** dengan nama folder yang dibutuhkan build. `git clone` biasa dapat menghasilkan folder yang tidak ditemukan Compose. Selalu sebutkan nama foldernya secara eksplisit.
:::

`compose.yaml` melakukan build dari relative path `../CoreERP/apps/core`. Kalau nama foldernya meleset satu huruf, build gagal.

```bash
git clone <url-repo-core> CoreERP
```

```bash
git clone <url-repo-orkestrasi> erp-docker-start-dev
```

Minta kedua URL-nya ke maintainer. Hasil akhirnya:

```text
<workspace>\
├─ CoreERP\
└─ erp-docker-start-dev\
```

Dua folder itu saja. Module bukan lagi repository saudara: ia hidup di dalam repo CoreERP pada
`modules/<penerbit>/<module>/` dan tidak punya container, database, maupun token layanan sendiri.

## Langkah 2: jalankan

```powershell
cd <workspace>\erp-docker-start-dev
.\start.ps1
```

Pada eksekusi pertama, skrip ini:

1. membuat `.env` lokal yang tidak di-commit,
2. membangun `@apperp/ui` di Docker dan menyalinnya ke Core,
3. membangun image,
4. menjalankan migration dan seed Core,
5. mendaftarkan manifest tiap module terpilih lalu menjalankan migration-nya,
6. memverifikasi health check,
7. mencatat release lokal sebagai siap.

Build pertama makan waktu. Berikutnya jauh lebih cepat.

## Penggunaan harian

```powershell
.\start.ps1
```

Setelah mengubah source atau Dockerfile:

```powershell
.\start.ps1 -Build
```

Dua opsi yang berguna sejak hari pertama: `-CoreOnly` menyalakan Core tanpa satu pun module, dan
`-Apps <id module>` membatasi ke module tertentu beserta dependency-nya. Keduanya menerima **id
manifest**, bukan nama folder.

## Yang jalan setelah stack naik

| Layanan | Alamat |
| --- | --- |
| Core app — control plane, shell, dan seluruh module | `http://localhost:8000` |
| **Dokumentasi ini** | **`http://localhost:18090`** |
| Core database `core_erp` | `localhost:5543` |

Docker Desktop mengelompokkan semuanya di bawah project **erp**. Worker, scheduler, dan renderer Core ikut jalan — tanpa worker dan scheduler, job onboarding tertahan dan app tidak akan pernah mencapai status siap.

Module tidak punya alamat sendiri. Halamannya dibuka lewat shell pada `/<id module>/<id entri menu>`
setelah module itu dipasang untuk tenant yang sedang kamu buka.

## Akses database

Sambungkan pgAdmin ke `localhost:5543`, **bukan** ke PostgreSQL yang mungkin sudah ada di laptopmu. Password ada di `.env` folder orkestrasi yang tidak di-commit. Port hanya bind ke `localhost`.

Satu database untuk Core dan seluruh module. Tidak ada database per module untuk disambungkan; yang
memisahkan datanya adalah awalan nama tabel.

## Jebakan yang sering kena

**Migration atau seed dari host memakai database yang salah.** `php artisan migrate`, `db:seed`, dan `tinker` yang dijalankan dari host bisa menunjuk database berbeda dari yang dipakai UI lokal. Untuk data yang harus terlihat di `http://localhost:8000`, jalankan lewat container `core-app`, atau pakai `.\start.ps1 -Build`. Verifikasi hasilnya dengan query dari container, bukan dari host.

**UI tidak berubah walaupun build lulus.** `npm run build` saja tidak cukup. Container runtime harus dibuat ulang lewat `.\start.ps1 -Build`, lalu layarnya dibuka untuk memastikan artifact baru benar-benar tampil. Type-check lulus bukan bukti perubahan UI sudah tayang.

**Menu lama masih muncul setelah mengubah `app.yaml`.** Rebuild UI tidak mendaftarkan ulang manifest. Jalankan `app:register-manifest <id module>` lewat container `core-app`, verifikasi kolom `apps.navigation` di database runtime, lalu reload shell Core.

**Menghapus duty yang sedang dipakai role.** Buat migration kecil untuk melepas relasi role-duty dulu, baru daftarkan ulang manifest.

## Perintah verifikasi

Dijalankan dari `apps/core`:

```bash
composer lint:check
```

```bash
composer types:check
```

```bash
php artisan test
```

Frontend:

```bash
npm run lint:check
```

```bash
npm run format:check
```

Perintah itu **ikut menjangkau `modules/`**, dan itu disengaja: Pint dijalankan pada Core dan pada
`modules/`, `php artisan test` menjalankan suite `Module` bersama suite Core pada PostgreSQL yang
sama, dan Prettier memeriksa berkas `ui/` module. Kalau kamu mengubah module lalu hanya menjalankan
sebagian, CI yang akan menemukannya lebih lambat daripada kamu.

Ini juga yang dijalankan CI. Lihat [Definition of done](/onboarding/definition-of-done) untuk syarat lengkap sebelum pekerjaan disebut selesai.

## Lihat juga

- [Development stack lokal](/dev/11-local-docker-development) — dokumen kanoniknya, termasuk checklist menambah module
- [Hari pertama](/onboarding/hari-pertama)
- [Cara berkontribusi](/onboarding/kontribusi)
