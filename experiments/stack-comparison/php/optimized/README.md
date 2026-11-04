# Overlay PHP teroptimasi

Varian teroptimasi PHP untuk profil `pending-purchase-v1`, sesuai [`../../optimized.md`](../../optimized.md). Isinya salinan utuh berkas snapshot `6b3cf5846fd4442c3e895cacf2ba58275f9dd75e` yang diubah, ditambah dua kelas baru, dengan path yang sama dengan repo. Snapshot tidak disunting di tempat. Overlay disalin ke volume source tersendiri. Volume `coreerp-stack-comparison-source` milik pembanding dasar tidak disentuh.

Dengan `CONTEXT_CACHE=off`, kode overlay menjalankan SQL dan perilaku yang sama persis dengan snapshot: setiap method cache langsung memanggil query aslinya, dan `enabled()` hanya membaca config tanpa koneksi atau query. Image yang sama dapat menjalankan pembanding dasar dari volume overlay.

## Berkas

Diganti. Hash adalah sha256 berkas snapshot, dalam format `sha256sum -c` relatif terhadap `/repo`. Hash ini sudah dicocokkan dengan `private/source.tar` lokal:

```text
8795b03ce5c0d28069a570b9abceaee6722fa75149e064c292e02cdda9998ef7  apps/core/config/coreerp.php
6c4ed43fa6b3d93de4f0c3e19bb894e534e9c87f25696f8f314cf257a60e0b89  apps/core/app/Providers/AppServiceProvider.php
b505d045c827096a71de19c638a6bcfb495ef80ea8c6ed7bc691bc7d284b9f7d  apps/core/app/Platform/Access/Support/DataPolicyAccessResolver.php
3205f680c050fa94e4306248355e53710192b49d4afc8325d1633f7ff9090da5  apps/core/app/Platform/Modules/Support/LaunchableAppCatalog.php
1fb6171b3e4d6181244042bdbf008b84f84560385827f475b310b76f74f6322f  apps/core/app/Platform/Environment/Support/CurrentWorkspace.php
```

| Berkas | Perubahan |
| --- | --- |
| `apps/core/config/coreerp.php` | Kunci `context_cache.enabled` (`CONTEXT_CACHE === 'on'`) dan `context_cache.ttl_seconds` (`CONTEXT_CACHE_TTL_SECONDS`, bawaan 300). |
| `apps/core/app/Providers/AppServiceProvider.php` | `scoped(ContextCache::class)`. Driver `people` memakai `ContextCachedUserProvider` sebagai pengganti `EloquentUserProvider`, dengan `withQuery` yang sama. |
| `apps/core/app/Platform/Access/Support/DataPolicyAccessResolver.php` | `resolve()` membungkus `compute()` dengan entri `policies\|membership\|tenant`. Memo per permintaan tetap ada. |
| `apps/core/app/Platform/Modules/Support/LaunchableAppCatalog.php` | `permissionsFor()` membungkus tiga query izin dengan entri `permissions\|membership\|tenant\|app`. |
| `apps/core/app/Platform/Environment/Support/CurrentWorkspace.php` | `memberships()` (entri `memberships\|user\|tenant-alamat`) dan `organizations()` (entri `organizations\|membership\|tenant`) lewat cache. Pemilihan keanggotaan, legal entity, dan unit kerja tidak berubah dan tetap dihitung per permintaan. |

Baru:

| Berkas | Isi |
| --- | --- |
| `apps/core/app/Platform/Access/Support/ContextCache.php` | Cache per worker: query versi, kedaluwarsa, batas validitas, batas 20.000 entri, dan simpan atau bangun ulang model Eloquent dari atribut mentah. |
| `apps/core/app/Platform/Identity/Support/ContextCachedUserProvider.php` | `retrieveById()` lewat cache. Login, remember-me, dan reset password tetap membaca database. |

## Environment

Sesi di Valkey (butir 1) hanya konfigurasi. Snapshot sudah membawa koneksi redis `sessions`, cache store `sessions`, dan phpredis 6.3.0 yang dikompilasi di `apps/core/Dockerfile`.

| Variabel | Nilai | Alasan |
| --- | --- | --- |
| `SESSION_DRIVER` | `redis` | `CacheBasedSessionHandler`: `GET` saat mulai, `SETEX` 7200 saat selesai, `DEL` saat logout atau regenerasi. `gc()` tidak melakukan apa pun. Tabel `sessions` tidak disentuh. |
| `REDIS_CLIENT` | `phpredis` | Sudah bawaan. Ditulis eksplisit. |
| `REDIS_HOST` | `valkey` | Dibaca koneksi `database.redis.sessions`. |
| `REDIS_PORT` | `6379` | Sama. |
| `SESSION_ENCRYPT` | `false` | **Wajib.** Bawaan snapshot untuk `redis` adalah `true`, sehingga payload terenkripsi. Spesifikasi menuntut isi payload tidak berubah, dan sisi native tidak mengenkripsi. |
| `REDIS_PREFIX` | kosong (`""`) | Tanpa ini setiap kunci diawali `<slug APP_NAME>:<16 hex sha256 APP_KEY>:`. |
| `SESSION_CACHE_PREFIX` | `coreerp-session:` | Prefix cache store `sessions`. Bersama `REDIS_PREFIX` kosong, kuncinya `coreerp-session:<id sesi>`, sama dengan `SESSION_KEY_PREFIX` native. |
| `VALKEY_SESSION_DB` | `0` | Bawaannya `2`. Dengan `0`, probe harness tidak perlu `SELECT 2`. |
| `CACHE_STORE` | `database` (tetap) | Limiter login tetap di tabel `cache`. |
| `CONTEXT_CACHE` | `on` | Saklar cache konteks. Selain `on` berarti mati. |
| `CONTEXT_CACHE_TTL_SECONDS` | `300` | Umur maksimum entri. |

Jangan setel `SESSION_STORE` atau `SESSION_CONNECTION`: bawaannya sudah `sessions` saat driver `redis`. Jangan setel `COREERP_CONTROL_CONNECTION` atau `COREERP_BASE_DOMAIN`, karena keduanya mematikan cache konteks (lihat batasan). `VALKEY_SESSION_PASSWORD` dibiarkan kosong. Bila kosong, phpredis tidak mengirim `AUTH`, sehingga `VALKEY_SESSION_USERNAME` (bawaan `sessions`) tidak dipakai. Isi keduanya hanya bila Valkey memakai ACL. `SESSION_LIFETIME` tetap bawaan 120 menit, yaitu TTL 7200 detik.

Saklar `SESSION_BACKEND` di spesifikasi tidak dibaca PHP. Harness menerjemahkan `SESSION_BACKEND=valkey` menjadi variabel di atas, dan `database` berarti environment pembanding dasar tanpa perubahan.

## Memasang overlay ke volume tersendiri

1. Pasang `infra/optimized-schema.sql` ke database run teroptimasi. Dengan `CONTEXT_CACHE=on` tanpa tabel `security_context_versions`, setiap permintaan yang membutuhkan konteks gagal 500. Kegagalan ini disengaja: cache tidak pernah berjalan tanpa penjaga versi.
2. Buat volume baru, misalnya `coreerp-stack-comparison-source-optimized`. Isi volume dengan archive snapshot yang sama seperti `lab.py source`, cocokkan hash, lalu salin overlay. Satu cara tanpa container tools:

   ```sh
   docker volume create coreerp-stack-comparison-source-optimized
   docker run --rm --entrypoint sh \
     -v coreerp-stack-comparison-source-optimized:/repo \
     -v "$PWD/experiments/stack-comparison/private/source.tar:/tmp/source.tar:ro" \
     -v "$PWD/experiments/stack-comparison/php/optimized:/overlay:ro" \
     "$PHP_IMAGE" -c '
       set -e
       tar -xf /tmp/source.tar -C /repo
       cd /repo
       sha256sum -c <<EOF
   8795b03ce5c0d28069a570b9abceaee6722fa75149e064c292e02cdda9998ef7  apps/core/config/coreerp.php
   6c4ed43fa6b3d93de4f0c3e19bb894e534e9c87f25696f8f314cf257a60e0b89  apps/core/app/Providers/AppServiceProvider.php
   b505d045c827096a71de19c638a6bcfb495ef80ea8c6ed7bc691bc7d284b9f7d  apps/core/app/Platform/Access/Support/DataPolicyAccessResolver.php
   3205f680c050fa94e4306248355e53710192b49d4afc8325d1633f7ff9090da5  apps/core/app/Platform/Modules/Support/LaunchableAppCatalog.php
   1fb6171b3e4d6181244042bdbf008b84f84560385827f475b310b76f74f6322f  apps/core/app/Platform/Environment/Support/CurrentWorkspace.php
   EOF
       cp -R /overlay/apps/. /repo/apps/
       mkdir -p apps/core/storage/framework/cache/data apps/core/storage/framework/sessions apps/core/storage/framework/views apps/core/storage/logs apps/core/bootstrap/cache
     '
   ```

   Hanya `apps/` yang disalin, jadi README ini tidak ikut masuk. Bila hash tidak cocok, archive bukan snapshot. Jangan menimpanya dengan overlay, karena salinan utuh akan membatalkan perubahan lain di berkas itu tanpa terlihat.
3. Jalankan service `php` dengan volume itu di `/repo` dan environment di atas, satu jaringan dengan service `valkey`. Compose sekarang memaku nama volume `source`, jadi harness perlu memparameterkan nama volume itu atau memakai berkas override. Mount vendor tetap `coreerp-pf01-vendor` read-only.
4. Periksa sebelum run:
   - `php -m | grep -x redis` di container `php`.
   - `grep -c setClassMapAuthoritative vendor/composer/autoload_real.php` harus `0`. Dua kelas baru dimuat lewat fallback PSR-4 `App\`. Vendor dari `--optimize-autoloader` biasa mengizinkannya, sedangkan classmap authoritative tidak.
   - `GET /up` 200. Event `DiagnosingHealth` snapshot melakukan ping ke koneksi `sessions` bila driver `redis`, jadi `/up` juga membuktikan Valkey terjangkau.
   - Jangan menjalankan `config:cache` di volume ini sebelum environment final terpasang.
   - Attestation source untuk volume ini adalah snapshot ditambah overlay. `source_attestation.py` milik pembanding dasar akan melaporkan lima berkas berbeda dan dua berkas tambahan.

## Cara kerja cache konteks

- **Umur.** Isi cache disimpan di properti statis `ContextCache`, satu salinan per thread worker FrankenPHP. Penanda "versi sudah dicocokkan" disimpan di instans yang diikat `scoped()`, sehingga dibuang Octane (`FlushTemporaryContainerInstances`) sesudah setiap permintaan, job antrean, dan tick. Isi tidak memakai `singleton()`, karena singleton yang pertama dibuat di dalam permintaan tersimpan di salinan container permintaan dan ikut dibuang. Penanda tidak memakai `singleton()`, karena singleton yang dibuat di container worker akan menghentikan query versi.
- **Versi.** Panggilan cache pertama pada sebuah permintaan menjalankan `select version from security_context_versions where id = 1`. Biasanya panggilan itu `retrieveById` dari guard sesi. Bila versinya berbeda, seluruh isi dikosongkan. Data hanya dibaca dan disimpan sesudah query versi itu.
- **Entri.** `user|<model>|<id>`, `memberships|<user>|<tenant alamat atau *>`, `policies|<membership>|<tenant>`, `permissions|<membership>|<tenant>|<app>`, `organizations|<membership>|<tenant>`, dan `boundary|<membership>|<tenant>`. Nilainya hanya larik dan skalar. Model Eloquent disimpan sebagai atribut mentah, koneksi, dan relasi tunggal yang sudah dimuat, lalu dibangun ulang dengan `newFromBuilder` pada setiap hit. Perubahan model di satu permintaan tidak terbawa ke permintaan lain.
- **Kedaluwarsa.** `min(sekarang + TTL, batas validitas)`. Batas validitas memakai query batas di spesifikasi tanpa perubahan. PDO tidak mengizinkan placeholder bernama dipakai ulang tanpa emulasi, sehingga `$1` dan `$2` menjadi enam placeholder posisi. Hasilnya disimpan sebagai entri `boundary` dan dipakai bersama entri kebijakan, izin, dan organisasi keanggotaan itu. Query batas dijalankan **sebelum** query data. Bila urutannya terbalik, penugasan yang berakhir di antara kedua query terbaca masih berlaku, tetapi batas akhirnya tidak tercatat.
- **Batas ukuran.** Entri baru yang akan membuat jumlahnya melewati 20.000 lebih dulu mengosongkan seluruh cache.
- **Mati otomatis.** Cache tidak dipakai saat `coreerp.control_connection` terisi, yaitu alamat lingkungan. Dalam keadaan itu `users`, `tenants`, dan `tenant_memberships` dibaca dari database pusat, sedangkan versi dibaca dari database lingkungan. Cache juga tidak dipakai di dalam transaksi database, karena koneksi dapat melihat tulisannya sendiri yang belum di-commit. Menyimpan bacaan itu dapat menyimpan hak yang kemudian di-rollback, padahal versi tidak pernah naik. Pada kedua keadaan itu bacaan langsung ke database seperti snapshot.
- **Tidak berubah.** `set_config('coreerp.user_id', ...)` dari event `Authenticated` tetap berjalan setiap permintaan. Reset `ResetDatabaseConnections` juga tetap berjalan di akhir permintaan. Respons, status, header, dan urutan tulisan bisnis tidak berubah.

SQL permintaan API module saat hit, untuk register kewajiban: query versi, `set_config` pelaku, query bisnis, lalu reset `set_config` ganda. Tidak ada akses tabel `sessions`. Saat miss, query konteks snapshot muncul lagi tanpa perubahan untuk entri yang miss: `users`, `tenant_memberships` dan `tenants`, tiga query izin, lingkup kebijakan (beserta closure dan organisasi per scope bila ada), dan `organizations`. Query batas ditambahkan sekali per keanggotaan.

## Selisih terhadap spesifikasi dan risiko

- **Format nilai sesi berbeda dengan native.** Kolom `sessions.payload` menyimpan base64 dari JSON. `RedisStore` Laravel menyimpan `serialize(<json>)`, yaitu string PHP `s:N:"…";`, tanpa base64. Kunci dan TTL sama. Isi sesi juga sama, kecuali butir berikut. Probe harness sebaiknya hanya memakai `TTL`, `EXPIRE`, dan `DEL`, dan tidak membandingkan byte nilai.
- **Pencabutan sesi snapshot menyala dengan driver `redis`.** Saat driver `redis`, `SessionRevocation` menulis `auth.session_generation` ke payload saat login. `EndSessionAfterProviderLogout` juga melakukan satu `GET coreerp-session:auth:session-generation:<user>` pada setiap permintaan grup web yang sudah login. Akibatnya PHP melakukan dua `GET` per permintaan, bukan satu. Fitur ini milik snapshot dan tidak dimatikan overlay.
- **Valkey mati menghasilkan 500, bukan 503.** Tidak ada fallback diam-diam ke database, tetapi Laravel merender `RedisException` sebagai 500. Kode 503 membutuhkan render exception di `bootstrap/app.php`, jadi butir ini tidak lagi sekadar konfigurasi.
- **`--max-requests=500` mengikis cache.** Worker Octane di-restart setiap 500 permintaan, dan isi cache-nya ikut hilang. Dengan 1.024 akun dan 8 worker, satu worker hanya melihat sebagian kecil akun sebelum restart. Contohnya, pada 200 VU sekitar 5 permintaan per detik per worker, sehingga worker di-restart setiap kira-kira 100 detik dan hit rate kasarnya sekitar 60%. Pada 400 VU angkanya turun ke sekitar 40%. Kandidat native tidak di-restart di tengah run. Menaikkan `--max-requests` pada run teroptimasi adalah keputusan pembanding, jadi tidak diubah di sini.
- **Cache per worker, bukan per proses.** Setiap akun miss sekali di setiap worker yang melayaninya, jadi miss bisa delapan kali lipat dari kandidat satu proses. Ini sesuai kalimat "per proses atau worker" di spesifikasi.
- **Organisasi dikunci keanggotaan, bukan tenant.** Query snapshot menyaring `organizations` dengan id dari kebijakan data keanggotaan (`whereIn`) bila keanggotaan tidak berlingkup penuh. Mengunci per tenant akan mengubah SQL saat miss, jadi kuncinya diganti dan umurnya mengikuti batas validitas keanggotaan.
- **`PUT /api/v1/workspace-context` tidak lewat cache untuk keanggotaan.** Controller snapshot menjalankan query `find` miliknya sendiri, bukan `CurrentWorkspace::memberships()`. Query itu tetap berjalan. Organisasinya tetap lewat cache. Operasi ini hanya terjadi sekali per sesi.
- **Basi di dalam satu permintaan.** Entri yang sudah dibaca sebelum permintaan itu sendiri menulis tabel bertrigger tetap dipakai sampai permintaan selesai. Permintaan berikutnya melihat versi baru. Snapshot sudah begitu untuk keanggotaan, organisasi, dan kebijakan (memo scoped), tetapi tidak untuk `permissionsFor`. Alur profil ini tidak menulis tabel bertrigger. Di trace golden PHP `private/g2-php-traces`, satu-satunya tulisan ke tabel itu adalah `update users set last_login_at, updated_at` saat login, dan kolom itu dikecualikan trigger.
- **Kolom pengguna yang dikecualikan trigger dapat basi.** `last_login_at`, `updated_at`, dan `remember_token` pada pengguna di cache dapat tertinggal sampai TTL habis. Respons API profil ini tidak memuatnya.
- **Zona waktu database.** Kolom validitas bertipe `timestamp` tanpa zona dan berisi UTC. Query batas membandingkannya dengan `now()`, dan hasilnya dibaca sebagai UTC. Ini benar bila `TimeZone` PostgreSQL adalah UTC, yaitu bawaan image `postgres:17`, sama dengan asumsi kandidat native.
