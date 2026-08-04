# Docker development stack lokal

Stack lokal menyatukan repository yang tetap mandiri agar developer cukup menjalankan Docker, tanpa menjalankan `npm run dev` atau `php artisan serve` satu per satu.

```text
D:\Kerja\
├─ CoreERP\
├─ app-erp-management-aset\
└─ erp-dev\                 # orchestration lokal
    ├─ compose.yaml
    ├─ start.ps1
    └─ .env                  # rahasia lokal, tidak di-commit
```

`erp-dev` memakai Compose project `erp`; Docker Desktop mengelompokkan `core-app`, `core-db`, `<app>-api`, `<app>-ui`, `<app>-db`, dan `docs` di bawahnya. Ia bukan repository domain dan tidak mengubah ownership aplikasi.

Service `docs` menyajikan situs dokumentasi CoreERP pada `http://localhost:18090`. Ia dibangun dari `CoreERP/docs` dengan pola yang sama seperti UI app — artifact dibangun saat build image, lalu disajikan nginx — sehingga perubahan dokumen tampil setelah `start.ps1 -Build`. Untuk menulis dokumen, jalankan `npm run docs:dev` dari `CoreERP/docs` di host agar mendapat hot reload.

## Menjalankan

```powershell
cd D:\Kerja\erp-dev
.\start.ps1         # pertama kali dan penggunaan harian
.\start.ps1 -Build  # setelah Dockerfile/source berubah
```

Script menjalankan migration, seed Core, registrasi manifest, health check, lalu mencatat release/placement lokal sebagai siap. Worker dan scheduler Core ikut dijalankan agar job onboarding tidak tertahan.

Sebelum Compose build, script membangun `@apperp/ui` dengan Docker, memverifikasi versinya, lalu menyalin `apperp-ui.tgz` ke cache Core dan `ui/vendor` app. Developer tidak perlu memasang Node, membuka `registry.apperp.local`, atau membuat akun registry. Build pertama tetap dapat memerlukan internet untuk menarik image Docker dan dependency npm publik.

Clone `CoreERP`, app, dan `erp-dev` sebagai folder sejajar, lalu jalankan:

```powershell
D:\Kerja\erp-dev\start.ps1 -Build
```

Tarball hanya adapter development dan diabaikan Git. Release CI wajib menerbitkan versi SDK yang sama ke artifact registry agar build di luar workspace tidak bergantung pada folder lokal.

## Akses database lokal

Database container dapat diakses dari pgAdmin melalui `localhost`:

| Database | Port | Database | User |
| --- | ---: | --- | --- |
| Core | 5543 | `core_erp` | `core_erp` |
| Management Aset | 5544 | `management_aset` | `management_aset` |

Password ada pada `D:\Kerja\erp-dev\.env`. Port hanya bind ke `localhost`; PostgreSQL host/PgEdge yang sudah ada tidak dipakai oleh stack ini.

## Checklist menambah app baru

1. Buat repository app mandiri berisi API, UI, migration database, OpenAPI/AsyncAPI contract, manifest, dan deploy definition.
2. Buat image API dan UI di repository app. API build context harus mencakup `database/migrations` app itu.
3. Tambahkan tiga service ke `erp-dev/compose.yaml`: `<app>-db`, `<app>-api`, dan `<app>-ui`. Database mendapat volume dan port localhost unik berikutnya; API dan UI mendapat port host unik bila perlu dibuka langsung.
4. Tambahkan path manifest, alamat UI lokal, dan nama service ke bootstrap di `start.ps1`.
5. Jalankan `.\start.ps1 -Build`. Script baru mencatat release/placement siap setelah migration dan seluruh health check berhasil.

App tidak boleh membaca database app lain. Integrasi memakai REST/OpenAPI atau event/AsyncAPI.

## Git

Dockerfile, `.dockerignore`, deploy definition, migration, dan contract adalah bagian repository app dan wajib di-commit bersama app. Folder `erp-dev` saat ini adalah orchestration lokal dan tidak dipush ke repository Core maupun repository app. Jika tim membutuhkan stack bersama, buat repository terpisah `apperp-dev-stack` dengan `.env.example` saja; jangan commit `.env`, volume, image hasil build, atau password.

## Lihat juga

- [Menyiapkan lingkungan lokal](../onboarding/setup.md) — versi langkah demi langkah untuk anggota tim baru
- [Standar module](02-module-standard.md) — isi wajib app yang ditambahkan ke stack
- [Menerbitkan release app](13-publishing-an-app-release.md) — dari stack lokal ke katalog resmi
- [Release dan on-prem](03-release-and-on-prem.md) — mekanisme yang sama di lingkungan nyata
