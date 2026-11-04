# Kandidat native TypeScript

Hono dan Elysia memasang `OpenAPIHandler` oRPC yang sama. `src/receipts.ts`, `src/inventory.ts`, `src/posting.ts`, `src/numbering.ts`, dan `src/security.ts` menjalankan alur bisnis serta keamanan di Bun. Proses kandidat dan image kandidat tidak menjalankan PHP.

Implementasi mengikuti source CoreERP `6b3cf5846fd4442c3e895cacf2ba58275f9dd75e`. Database memakai schema dan trigger PostgreSQL asli yang disiapkan eksperimen induk. Kandidat tidak membaca fixture untuk membentuk jawaban atau menyusun jurnal. Nama, akun, scope nomor, matriks buku, profil, definisi atribut, dan izin dibaca dari database.

## Jalankan kandidat

Jalankan dari direktori ini dengan Bun 1.4.4.

```powershell
bun install --frozen-lockfile
bun run typecheck
bun test
bun run build
./run.ps1 -EnvPath <private-runtime.env> -Adapter hono
./run.ps1 -EnvPath <private-runtime.env> -Adapter elysia
```

Hono memakai port 18644. Elysia memakai port 18645. `PORT` dan `HOST` mengganti alamat listener. `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, dan `DB_POOL_SIZE` mengatur pool. Default lokal hanya menunjuk database eksperimen `erp_compare_ts` pada `127.0.0.1:18640`. Clone verifikasi akhir bernama `erp_compare_final_hono` dan `erp_compare_final_elysia`. Database pengembangan tambahan harus berawalan `erp_compare_ts_`.

Versi teroptimasi ([`../../optimized.md`](../../optimized.md)) dinyalakan dengan `SESSION_BACKEND=valkey` dan `CONTEXT_CACHE=on`; bawaannya `database` dan `off`, yaitu perilaku pembanding dasar dengan SQL dan urutan yang sama. `VALKEY_HOST`, `VALKEY_PORT`, dan `SESSION_KEY_PREFIX` menunjuk Valkey sesi lewat klien Redis bawaan Bun. `CONTEXT_CACHE_TTL_SECONDS` membatasi umur entri cache konteks. Setiap proses Bun memegang klien Valkey dan cache konteksnya sendiri.

`COMPARE_ENV` menunjuk berkas privat berisi `APP_KEY` dan `DB_PASSWORD`. Variabel proses mengalahkan nilai berkas. Cookie menggunakan AES-256-CBC Laravel, MAC envelope, prefix nama cookie, dan sesi database JSON yang dibungkus base64. Setiap login memeriksa password dua kali dengan biaya hash yang tersimpan. Pilihan workspace tidak memberi izin baru. Setiap request memulihkan membership, role aktif dan hierarkinya, duty, privilege, permission, serta policy organisasi dari database.

Build Linux menggunakan konteks root repository.

```sh
docker build -f experiments/stack-comparison/native/typescript/Dockerfile -t coreerp-compare-ts .
docker run --rm --env-file private-runtime.env -e DB_DATABASE=erp_compare_ts -e DB_HOST=database coreerp-compare-ts
docker run --rm --env-file private-runtime.env -e DB_DATABASE=erp_compare_ts -e DB_HOST=database coreerp-compare-ts bun dist/elysia.js
```

Network dan anggaran resource container disiapkan pengendali eksperimen. Contoh perintah tidak menerapkan anggaran performa. `bun run format` dan `bun run format:check` memakai `.prettierrc` Core yang sudah ada, dengan versi Prettier dan plugin yang sama dengan lock repository.

## Batas profil

Alur yang diimplementasikan adalah login, workspace, daftar penerimaan, create, GET draft, PATCH penuh, pratinjau, completion, GET selesai, daftar aset penerimaan, daftar aset, dan GET setiap aset. Nominal memakai aritmetika desimal berbasis `BigInt`, pembulatan HALF_UP, dan pembagian remainder ke unit awal. Completion menulis aset, penempatan, snapshot buku, atribut bertipe, dan posting finance dalam transaksi yang sama. Trigger PostgreSQL menulis ledger dan audit. Nomor header diterbitkan sebelum transaksi dokumen. Nomor aset tetap di dalam transaksi completion.

Profil utama adalah pembelian dengan tiga unit, vendor, pajak, buku yang di-post, profil penyusutan, satu atribut wajib, dan jurnal `pending`. Upload lampiran, saldo awal, pembatalan dokumen, planning lookup, SSO, Redis session revocation, routing database per environment, lisensi wajib, serta rotasi previous APP_KEY belum memiliki conformance. Konfigurasi driver sesi, enkripsi payload sesi, cache, lisensi wajib, domain environment, dan previous key di luar profil ditolak saat startup. Saldo awal dan planning lookup ditolak eksplisit oleh endpoint. Penerusan referensi lampiran yang sudah ada dan purchase request links memiliki implementasi, tetapi belum dinyatakan terverifikasi oleh profil ini.

HTML bootstrap `/login` hanya membuka sesi dan token. UI login, Inertia props, 2FA challenge completion, remember-me, dan halaman aplikasi tidak direplikasi. Request JSON bisnis memakai rantai auth serta domain native.

`TRACE_FILE` mengaktifkan JSONL privat berisi template SQL tanpa binding, fingerprint, urutan, durasi, dan penanda kegagalan. Trace mencakup sesi, authority, transaksi dan savepoint, serta query domain. Trace driver tidak menghitung statement di dalam trigger sebagai round trip aplikasi. Rekonsiliasi kerja trigger dan coverage query dilakukan pengendali eksperimen.

Pemeriksaan lokal awal menjalankan alur HTTP nyata melalui kedua adapter dan oracle SQL independen milik eksperimen induk. Hasil itu belum membuktikan seluruh matrix negatif, query accounting, build container Linux, atau kapasitas. Bukti rinci tetap di direktori privat. Tidak ada hasil benchmark atau klaim kecepatan dalam kandidat ini.

## Referensi adapter

API paket dipaku pada oRPC 1.16.1. Handler memakai [Fetch OpenAPI handler](https://v1.orpc.dev/docs/openapi/openapi-handler), output detailed, dan `customErrorResponseBodyEncoder` agar status, body, ETag, Location, serta error mengikuti PHP. Elysia memakai `parse: 'none'` sesuai [adapter resmi Elysia](https://orpc.dev/docs/adapters/elysia). Hono mengikuti [adapter resmi Hono](https://orpc.dev/docs/adapters/hono).
