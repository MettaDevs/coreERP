# App ERP Management Aset

App bisnis mandiri di bawah platform CoreERP (`D:\Kerja\CoreERP`). Repo ini memiliki API, UI, database, migration, dan contract sendiri.

- Baca `README.md` untuk daftar master, rantai klasifikasi, hak akses, dan penomoran. Aturan platform ada di `docs/dev/` pada repo CoreERP; buka hanya dokumen yang relevan.
- Jaga perubahan dan dependency tetap minimal. Jangan membuat abstraksi atau compatibility layer spekulatif.
- Jangan pernah hardcode nama perusahaan, orang, atau modul besar. Semua itu konfigurasi/data, bukan konstanta kode.

## Struktur fitur

- Setiap fitur atau halaman baru wajib memiliki folder sendiri pada API dan UI. Jangan menambahkan controller, model, page, atau komponen khusus fitur ke root bersama.
- API menaruh kode khusus fitur di `api/app/Http/Controllers/<fitur>/` dan `api/app/Models/<fitur>/`. UI menaruh page, form, konfigurasi, dan komponen khususnya di `ui/src/<fitur>/`.
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
- Yang hierarkis hanya data, bukan skema: `m_lokasi_aset.parent_id` dan `tr_penerimaan_aset.parent_asset_id` menunjuk dirinya sendiri. Keduanya struktur domain app ini, bukan organization hierarchy CoreERP; foreign key permanen di sini sah, di identitas organization Core tidak.

## Menambah atau mengubah master

1. Tabel baru mengikuti bentuk yang sama: `id` ULID, `tenant_id`, `creation_key`, `kode`, `nama`, `keterangan`, `aktif`, soft delete, `unique(tenant_id, kode)`, `unique(tenant_id, creation_key)`.
2. Foreign key ke master lain wajib **gabungan dengan `tenant_id`** — `(tenant_id, parent_id)` → `(tenant_id, id)` — sehingga induk lintas tenant ditolak database, bukan hanya validasi aplikasi. Tabel induk perlu `unique(tenant_id, id)`.
3. Controller cukup mewarisi `MasterDataController` dan menyatakan slug resource, model, induk, serta anaknya. Jangan menyalin ulang logika hak akses, idempotency, atau penomoran.
4. `app.yaml` wajib menambah empat lapis Dynamics 365 secara terpisah — entry point, permission, privilege, duty — plus satu reference nomor. Kode privilege tidak boleh sama dengan kode permission.
5. Perbarui `contracts/src/` (lalu `python contracts/bundle.py`), `README.md`, dan `database/README.md` pada perubahan yang sama.

## Verifikasi sebelum menyatakan selesai

Urutannya penting: yang murah lebih dulu, tetapi tidak boleh berhenti sebelum yang terakhir.

```bash
cd api && php artisan test
```

```bash
cd api && vendor/bin/pint --test && vendor/bin/pint --test ../database
```

```bash
python loadtest/check-manifest.py app.yaml
```

```bash
cd ui && npm run build
```

Lalu **load test wajib** — lihat `loadtest/README.md`. Sebuah modul belum selesai hanya karena test feature lulus. Test feature berjalan satu request pada satu proses terhadap SQLite; ia tidak dapat melihat koneksi habis, nomor ganda, batas tenant yang bocor saat request saling menyela, atau idempotency key yang berlomba.

Minimum yang harus dipenuhi: 1000+ VU serentak, 100+ tenant, 2+ instance API di belakang load balancer, PostgreSQL asli, 90 detik pada beban penuh. Gate kebenaran (0 pelanggaran, 0 error aplikasi) berlaku di perangkat keras apa pun. Gate latensi diukur pada concurrency yang masih tertahan, bukan pada titik jenuh.

Registrasi ulang katalog ke Control Plane diperlukan setiap kali entry point, permission, privilege, duty, atau reference nomor bertambah.
