# Menyiapkan lingkungan lokal

Target: `http://localhost:8000` terbuka dan bisa dipakai, tanpa memasang PHP, Node, atau PostgreSQL di laptop.

## Yang perlu ada dulu

- **Docker Desktop**, jalan
- **Git**
- **Akses Bitbucket** ke organisasi `metta-development`
- **PowerShell** (skrip stack lokal berbasis PowerShell)
- Koneksi internet untuk build pertama — image Docker dan dependency npm publik masih ditarik dari jaringan

Node dan akun registry npm **tidak** diperlukan. Stack membangun SDK UI di dalam Docker.

## Langkah 1: clone dengan nama folder yang benar

::: danger Baca bagian ini, jangan dilewat
Nama repository **tidak sama** dengan nama folder yang dibutuhkan build. `git clone` biasa akan menghasilkan folder yang tidak ditemukan Compose. Selalu sebutkan nama foldernya secara eksplisit.
:::

`compose.yaml` melakukan build dari relative path `../CoreERP/apps/control-plane` dan `../app-erp-management-aset`. Kalau nama foldernya meleset satu huruf, build gagal.

```bash
git clone https://bitbucket.org/metta-development/core-erp.git CoreERP
```

```bash
git clone https://bitbucket.org/metta-development/app-erp-management-asset.git app-erp-management-aset
```

Perhatikan: repository-nya `app-erp-management-**asset**`, foldernya `app-erp-management-**aset**`.

Repository `erp-dev`, `app-erp-hr`, dan `app-erp-template` juga di-clone sejajar dengan nama folder persis seperti di bawah. Minta URL-nya ke maintainer kalau belum ada di daftar repo yang kamu lihat.

Hasil akhirnya:

```text
<workspace>\
├─ CoreERP\
├─ app-erp-management-aset\
├─ app-erp-hr\
├─ app-erp-template\
└─ erp-dev\
```

## Langkah 2: jalankan

```powershell
cd <workspace>\erp-dev
.\start.ps1
```

Pada eksekusi pertama, skrip ini:

1. membuat `.env` lokal yang tidak di-commit,
2. membangun `@apperp/ui` di Docker dan menyalinnya ke Core dan app,
3. membangun image,
4. menjalankan migration kedua database,
5. men-seed Core,
6. mendaftarkan manifest app,
7. memverifikasi health check,
8. mencatat release lokal sebagai siap.

Build pertama makan waktu. Berikutnya jauh lebih cepat.

## Penggunaan harian

```powershell
.\start.ps1
```

Setelah mengubah source atau Dockerfile:

```powershell
.\start.ps1 -Build
```

## Yang jalan setelah stack naik

| Layanan | Alamat |
| --- | --- |
| Core app (control plane + shell) | `http://localhost:8000` |
| **Dokumentasi ini** | **`http://localhost:18090`** |
| Core database `core_erp` | `localhost:5543` |
| Management Aset — API | `localhost:18091` |
| Management Aset — UI | `localhost:18092` |
| Management Aset — database `management_aset` | `localhost:5544` |
| HR — UI | `localhost:18093` |

Docker Desktop mengelompokkan semuanya di bawah project **erp**. Worker dan scheduler Core ikut jalan — tanpa keduanya, job onboarding tertahan dan app tidak akan pernah mencapai status siap.

## Akses database

Sambungkan pgAdmin ke `localhost` pada port di tabel atas, **bukan** ke PostgreSQL yang mungkin sudah ada di laptopmu. Password ada di `erp-dev\.env` yang tidak di-commit. Port hanya bind ke `localhost`.

## Jebakan yang sering kena

**Migration atau seed dari host memakai database yang salah.** `php artisan migrate`, `db:seed`, dan `tinker` yang dijalankan dari host bisa menunjuk database berbeda dari yang dipakai UI lokal. Untuk data yang harus terlihat di `http://localhost:8000`, jalankan lewat container `core-app`, atau pakai `.\start.ps1 -Build`. Verifikasi hasilnya dengan query dari container, bukan dari host.

**UI tidak berubah walaupun build lulus.** `npm run build` saja tidak cukup. Container runtime harus dibuat ulang lewat `.\start.ps1 -Build`, lalu layarnya dibuka untuk memastikan artifact baru benar-benar tampil. Type-check lulus bukan bukti perubahan UI sudah tayang.

**Menu lama masih muncul setelah mengubah `app.yaml`.** Rebuild UI tidak mendaftarkan ulang manifest. Jalankan `app:register-manifest <id module>` lewat container `core-app`, verifikasi kolom `apps.navigation` di database runtime, lalu reload shell Core.

**Menghapus duty yang sedang dipakai role.** Buat migration kecil untuk melepas relasi role-duty dulu, baru daftarkan ulang manifest.

## Perintah verifikasi

Dijalankan dari `apps/control-plane`:

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

Ini juga yang dijalankan CI. Lihat [Definition of done](/onboarding/definition-of-done) untuk syarat lengkap sebelum pekerjaan disebut selesai.

## Lihat juga

- [Development stack lokal](/dev/11-local-docker-development) — dokumen kanoniknya, termasuk checklist menambah app
- [Hari pertama](/onboarding/hari-pertama)
- [Cara berkontribusi](/onboarding/kontribusi)
