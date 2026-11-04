# Versi teroptimasi profil `pending-purchase-v1`

Keputusan pengguna, 4 November 2026: semua cara menaikkan batas VU dari sesi "Perbedaan disk write dev 1 dan dev 2" diterapkan **sekaligus** ke kelima stack, lalu diuji sekali dengan prosedur yang sama dengan pembanding dasar (ramp mode sesi lalu lima ulangan). Cara yang terbukti menaikkan batas dengan gate kebenaran tetap bersih wajib dipakai pada refactor ke stack terpilih.

Bottleneck yang terbukti pada pembanding dasar di dev1 adalah fsync WAL. Setiap request, termasuk baca, menulis baris `sessions` lalu commit. Selain itu ±9 dari ±13 query per request hanya membentuk konteks (sesi, user, keanggotaan, scope kebijakan data, organisasi, izin rekursif).

## Saklar

Setiap stack membaca dua saklar environment. Bawaannya adalah perilaku pembanding dasar, sehingga image yang sama dapat menjalankan keduanya dan hasil dasar tetap dapat diulang.

| Variabel | Nilai | Bawaan |
| --- | --- | --- |
| `SESSION_BACKEND` | `database` atau `valkey` (bukan `SESSION_STORE`, yang di Laravel berarti cache store sesi) | `database` |
| `CONTEXT_CACHE` | `off` atau `on` | `off` |
| `VALKEY_HOST`, `VALKEY_PORT` | host dan port Valkey | `valkey`, `6379` |
| `SESSION_KEY_PREFIX` | awalan kunci sesi di Valkey | `coreerp-session:` |
| `CONTEXT_CACHE_TTL_SECONDS` | umur maksimum entri cache konteks | `300` |

Versi teroptimasi = `SESSION_BACKEND=valkey` dan `CONTEXT_CACHE=on`.

## 1. Sesi di Valkey (butir 3)

- Kunci `SESSION_KEY_PREFIX + session_id`. Nilai: string payload yang sama persis dengan yang hari ini disimpan di kolom `sessions.payload`. TTL 7200 detik (lifetime sesi).
- Awal request: satu `GET`. Kunci tidak ada sama dengan sesi tidak ada atau kedaluwarsa, sama seperti baris yang `last_activity`-nya terlalu tua hari ini. Pemeriksaan `last_activity` diganti TTL Valkey.
- Akhir request, di titik yang sama dengan upsert `sessions` hari ini: satu `SET key value EX 7200`. Tidak ada akses tabel `sessions` sama sekali di mode ini (tidak ada select, upsert, maupun GC `delete ... last_activity`).
- Logout dan regenerasi id saat login: `DEL` kunci lama, `SET` kunci baru di akhir request.
- Cookie, enkripsi cookie, CSRF, dan isi payload tidak berubah.
- Klien: Go `github.com/redis/go-redis/v9`; Bun memakai klien Redis bawaan Bun (`RedisClient` dari `"bun"`), tanpa dependency baru; Rust crate `redis` dengan `tokio-comp` dan `aio::ConnectionManager`. PHP memakai `SESSION_DRIVER=redis` dengan `phpredis` (dikompilasi di `apps/core/Dockerfile` snapshot).
- Kegagalan Valkey menjadi error 503 untuk request itu, bukan jatuh diam-diam ke database.

## 2. Cache konteks per proses (butir 1 dan 2)

Skema tambahan (`infra/optimized-schema.sql`, dipasang harness ke seed versi teroptimasi): tabel `security_context_versions` satu baris, dinaikkan trigger statement-level pada setiap insert, update, delete, atau truncate di tabel yang membentuk konteks: `users`, `tenants`, `tenant_memberships`, `role_assignments`, `role_assignment_data_policy_scopes`, `organization_hierarchy_closures`, `organizations`, `roles`, `security_role_children`, `security_role_duties`, `security_duty_privileges`, `security_privilege_permissions`, `permissions`. Untuk `users`, update hanya menaikkan versi bila menyentuh kolom selain `last_login_at`, `updated_at`, dan `remember_token`, karena setiap login menulis dua kolom pertama dan tidak boleh mengosongkan cache semua user.

Setiap request yang membutuhkan user atau konteks:

1. Tepat **satu** query versi: `select version from security_context_versions where id = 1` (prepared). Bila berbeda dari versi cache proses, seluruh cache proses dikosongkan dan versinya diganti.
2. Bagian konteks diambil dari cache bila ada dan belum kedaluwarsa; bila tidak, dihitung dengan query yang sama persis dengan hari ini, lalu disimpan.

Isi cache, semuanya di memori proses atau worker (bukan Valkey), dikunci oleh id yang disebut dan berlaku untuk satu versi:

| Entri | Kunci | Isi |
| --- | --- | --- |
| user | `user_id` | baris `users` yang dipakai sesi hari ini |
| keanggotaan | `user_id` | keanggotaan aktif beserta tenant |
| kebijakan | `membership_id`, `tenant_id` | scope kebijakan data beserta `operating_unit_ids` hasil closure |
| organisasi | `tenant_id` | organisasi aktif tenant |
| izin | `membership_id`, `tenant_id` | himpunan kode izin hasil role rekursif (butir 2: izin jadi) |

Kedaluwarsa entri = `min(sekarang + CONTEXT_CACHE_TTL_SECONDS, batas validitas berikutnya)`. Untuk entri kebijakan dan izin, batas validitas berikutnya dihitung sekali saat miss:

```sql
select min(t) from (
  select a.valid_until as t from role_assignments a where a.membership_id = $1 and a.status = 'active' and a.valid_until > now()
  union all select a.valid_from from role_assignments a where a.membership_id = $1 and a.status = 'active' and a.valid_from > now()
  union all select s.valid_until from role_assignment_data_policy_scopes s join role_assignments a on a.id = s.role_assignment_id
            where a.membership_id = $1 and s.tenant_id = $2 and s.valid_until > now()
  union all select s.valid_from from role_assignment_data_policy_scopes s join role_assignments a on a.id = s.role_assignment_id
            where a.membership_id = $1 and s.tenant_id = $2 and s.valid_from > now()
) boundaries
```

Dengan begitu penugasan yang berakhir karena waktu tanpa ada penulisan juga tidak bertahan di cache (0 eskalasi hak), dan penugasan yang baru mulai tidak tertunda.

Aturan yang wajib dijaga:

- Pemilihan tempat kerja (keanggotaan, legal entity, unit kerja dari body atau session data) tetap dihitung per request dari data cache. Hasil pemilihan dan apa pun yang khas request tidak pernah disimpan di cache.
- Cache hanya berisi data per user, keanggotaan, dan tenant yang disebut di kunci; tidak ada objek request, sesi, atau koneksi.
- Ukuran dibatasi: kosongkan cache bila jumlah entri melewati 20.000.
- Data selalu dibaca **sesudah** query versi pada request yang sama, jadi data yang disimpan untuk versi `v` tidak pernah lebih tua dari `v`.
- `set_config('coreerp.user_id', ...)` dan reset di akhir request tidak berubah: trigger audit membutuhkannya.
- Respons HTTP, status, header, dan urutan penulisan bisnis tidak berubah.

## 3. Setelan PostgreSQL (butir 7)

Dipasang harness lewat `ALTER SYSTEM` dan `pg_reload_conf()` sebelum run teroptimasi, lalu dikembalikan: `max_wal_size = 4GB`, `checkpoint_timeout = 15min`, `random_page_cost = 1.1`. `shared_buffers` sudah 1 GB dari 4 GB (25%). Indeks query konteks tidak ditambah: dengan cache, query itu hanya berjalan saat miss.

## Butir yang tidak diterapkan

- **PgBouncer transaction pooling (butir 4).** Actor audit dipasang dengan `set_config(..., false)` pada level sesi koneksi. Di transaction pooling, koneksi server bergantian antar klien, sehingga actor satu user dapat terbawa ke request user lain: pelanggaran kebenaran audit. Prepared statement per koneksi juga harus diatur ulang. Setiap stack sudah dibatasi 8 koneksi database, jadi PgBouncer menambah satu hop tanpa menambah kapasitas.
- **`synchronous_commit = off` untuk tulisan tidak penting (butir 6).** Sesudah butir 3, sesi tidak lagi ditulis ke PostgreSQL. Tulisan lain di profil ini (penerimaan, aset, ledger, posting, nomor, change log) adalah data penting dan tetap durable.
- **FrankenPHP worker (butir 5)** sudah dipakai sejak awal: Octane FrankenPHP, 8 worker.

Larangan tetap berlaku: tidak ada `fsync=off`, `full_page_writes=off`, atau unlogged table.

## Gate

Gate kebenaran sama dengan pembanding dasar, dengan PHP teroptimasi sebagai acuan: golden lima gate, conformance HTTP, pencabutan (12), rollback (5), throttle (6), dan oracle rekonsiliasi pada setiap run beban. Harness menyesuaikan probe sesi (TTL dan `DEL` di Valkey menggantikan `UPDATE`/`DELETE sessions`) dan register kewajiban SQL (tanpa akses `sessions`, ditambah query versi, query konteks hanya saat miss).
