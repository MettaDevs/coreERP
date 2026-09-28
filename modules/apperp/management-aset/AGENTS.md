# App ERP Management Aset

App bisnis mandiri di bawah platform CoreERP (`D:\Kerja\CoreERP`). Repo ini memiliki API, UI, database, migration, dan contract sendiri.

- Baca `README.md` untuk daftar master, rantai klasifikasi, hak akses, dan penomoran. Aturan platform ada di `docs/dev/` pada repo CoreERP; buka hanya dokumen yang relevan.
- Jaga perubahan dan dependency tetap minimal. Jangan membuat abstraksi atau compatibility layer spekulatif.
- Jangan pernah hardcode nama perusahaan, orang, atau modul besar. Semua itu konfigurasi/data, bukan konstanta kode.

## Struktur fitur

- Setiap fitur atau halaman baru wajib memiliki folder sendiri pada API dan UI. Jangan menambahkan controller, model, page, atau komponen khusus fitur ke root bersama.
- API menaruh kode khusus fitur di `src/Http/Controllers/transaksi/<Fitur>/` dan `src/Models/transaksi/<Fitur>/` (master di `master/`). UI menaruh page, form, konfigurasi, dan komponen khususnya di `ui/transactions/<fitur>/`.
- Rute fitur ada di `routes/api/<fitur>.php`, satu berkas per fitur, dimuat otomatis oleh `routes/api.php` di bawah prefix dan middleware yang sama. Nama berkasnya bahasa Inggris seperti nama kode lain; alamat URL-nya tetap. Rute spesifik yang harus didahulukan dari `{id}` (misalnya `penerimaan-aset/vendor`) ditaruh di berkas yang sama, sebelum rute `{id}`-nya.
- Kode lintas fitur saja yang boleh tetap di root/shared: controller dan model dasar, middleware, service integrasi, support, shell aplikasi, API client, dan style global.
- Nama folder fitur memakai nama domain yang konsisten pada API dan UI. Jika fitur tumbuh, tambahkan subfolder lokal seperti `Components`, `Requests`, atau `Services` di dalam folder fitur; jangan membuat folder global baru hanya untuk satu fitur.
- Detail pola dan contoh berada di `docs/agent.md` dan `docs/skills/struktur-fitur.md`.
- Untuk dropdown bertingkat, reset semua nilai turunan saat induk berubah dan gunakan nilai kosong yang controlled; lihat `docs/skills/struktur-fitur.md`.
- Untuk dropdown bertingkat, reset semua nilai turunan saat induk berubah dan pastikan UI benar-benar menampilkan pilihan kosong; ikuti aturan lengkap pada `docs/skills/struktur-fitur.md`.

## Batas yang tidak boleh dilanggar

- App ini **tidak pernah** menyentuh database Core, dan Core tidak menyentuh database ini. Pertukaran hanya lewat REST/OpenAPI, event/AsyncAPI, dan token konteks bertanda tangan.
- Setiap endpoint dan event yang menyeberang batas app wajib ada di `contracts/`. Tidak ada test yang gagal karena contract kurang lengkap, jadi periksa manual sebelum menyatakan selesai.
- Contract ditulis tangan dan merupakan sumber kebenaran, bukan hasil generate dari kode. Ambang ~1500 baris sudah terlampaui, jadi sumbernya kini dipecah di `contracts/src/` (`paths/` dan `components/`) dan `contracts/openapi.yaml` adalah **bundle hasil generate** — jangan pernah menyuntingnya langsung, isinya ditimpa tiap build. Sunting `contracts/src/`, lalu jalankan `python contracts/bundle.py`. `python contracts/bundle.py --check` memastikan bundle sinkron dengan sumbernya. Bundle sengaja tetap bernama `contracts/openapi.yaml` supaya `api.openapi` di `app.yaml` dan Control Plane membaca path yang sama seperti sebelum dipecah.
- Mengubah contract event berarti mengubah kedua sisi. Pasangan `contracts/asyncapi.yaml` di sini adalah `CoreERP/apps/core/contracts/asyncapi.yaml`; keduanya berubah dalam pekerjaan yang sama.
- Event yang diterima wajib mengontrakkan header signature dan status kegagalannya, bukan hanya payload. Consumer tidak boleh menebak string yang ditandatangani dari source publisher.
- `tenant_id` hanya berasal dari konteks permintaan yang disusun Core (`ResolveModuleContext`), dan dibaca module lewat kontrak `KonteksPermintaan`. Tidak pernah dari body, query, atau header bebas.
- `kode` selalu diterbitkan Number Sequence Core. App tidak menyimpan counter dan mengabaikan `kode` yang dikirim klien.
- Format, status, dan counter nomor adalah keputusan owner/admin tenant di Control Plane. Manifest hanya mendeklarasikan reference dan allowed scope.
- Arsip adalah soft delete. Jangan mengganti dengan hard delete: record lama masih direferensikan data turunan.
- Master klasifikasi **datar dan saling lepas**, mengikuti model Dynamics 365 F&O: aset menunjuk `group_aset_id` (sumbu finansial) dan `jenis_aset_id` (sumbu teknis) secara langsung dan sejajar. Jangan menambah tingkat klasifikasi baru sebagai tabel; pembedaan yang lebih rinci diselesaikan lewat atribut.
- Yang hierarkis hanya data, bukan skema: `m_lokasi_aset.parent_id` dan `tr_aset.induk_aset_id` menunjuk dirinya sendiri. Keduanya struktur domain app ini, bukan organization hierarchy CoreERP; foreign key permanen di sini sah, di identitas organization Core tidak.

## Menambah atau mengubah master

1. Tabel baru mengikuti bentuk yang sama: `id` ULID, `tenant_id`, `creation_key`, `kode`, `nama`, `keterangan`, `aktif`, soft delete, `unique(tenant_id, kode)`, `unique(tenant_id, creation_key)`.
2. Foreign key ke master lain wajib **gabungan dengan `tenant_id`** — `(tenant_id, parent_id)` → `(tenant_id, id)` — sehingga induk lintas tenant ditolak database, bukan hanya validasi aplikasi. Tabel induk perlu `unique(tenant_id, id)`.
3. Controller cukup mewarisi `MasterDataController` dan menyatakan slug resource, model, induk, serta anaknya, lalu slug-nya didaftarkan di `$masters` pada `routes/api/master-data.php`. Jangan menyalin ulang logika hak akses, idempotency, atau penomoran.
4. `app.yaml` wajib menambah empat lapis Dynamics 365 secara terpisah — entry point, permission, privilege, duty — plus satu reference nomor. Kode privilege tidak boleh sama dengan kode permission.
5. Perbarui `contracts/src/` (lalu `python contracts/bundle.py`), `README.md`, dan `database/README.md` pada perubahan yang sama.

## Verifikasi sebelum menyatakan selesai

Urutannya penting: yang murah lebih dulu, tetapi tidak boleh berhenti sebelum yang terakhir.

Perintahnya ditulis dari akar repo. Sebelumnya blok ini menyuruh `cd api` dan `cd ui`,
peninggalan masa modul ini dua aplikasi tersendiri. `ui/` memang masih ada — itu sumber
layarnya — dan `api/` menyisakan satu `Dockerfile`, tetapi tidak satu pun dari keduanya
punya `artisan` atau `package.json` lagi. Keduanya dijalankan dari `apps/core`.

Satu run test pada satu waktu: `core_erp_test` dipakai bersama, dan dua run serentak saling
menjatuhkan tabel sehingga gagalnya menyamar jadi regresi kode.

```bash
cd apps/core && php artisan test --testsuite=Module
```

```bash
cd apps/core && php vendor/laravel/pint/builds/pint --test ../../modules/apperp/management-aset
```

```bash
cd modules/apperp/management-aset/loadtest && python check-manifest.py
```

```bash
cd apps/core && npm run types:check && npm run lint:check && npm run build
```

Lalu **load test wajib** — lihat `loadtest/README.md`. Sebuah modul belum selesai hanya karena test feature lulus. Test feature berjalan satu request pada satu proses; ia tidak dapat melihat koneksi habis, nomor ganda, batas tenant yang bocor saat request saling menyela, atau idempotency key yang berlomba.

Minimum yang harus dipenuhi: 1000+ VU serentak, 100+ tenant, 2+ instance API di belakang load balancer, PostgreSQL asli, 90 detik pada beban penuh. Gate kebenaran (0 pelanggaran, 0 error aplikasi) berlaku di perangkat keras apa pun. Gate latensi diukur pada concurrency yang masih tertahan, bukan pada titik jenuh.

Registrasi ulang katalog ke Control Plane diperlukan setiap kali entry point, permission, privilege, duty, atau reference nomor bertambah.
