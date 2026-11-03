# Limiter login PHP yang harus ditiru kandidat native

Sumber: vendor `laravel/framework` 13.35.0 (`Cache/DatabaseStore.php`, `Cache/RateLimiter.php`,
`Routing/Middleware/ThrottleRequests.php`) dan `laravel/fortify` (`LoginRateLimiter.php`) yang dipakai
container PHP uji, serta `apps/core/app/Providers/FortifyServiceProvider.php` pada SHA
`6b3cf5846fd4442c3e895cacf2ba58275f9dd75e`. Urutan statement di bawah sudah dicocokkan dengan log
PostgreSQL PHP (`private/l1-php-login.json`, dihasilkan `scripts/login_sequence.py`).

## Konstanta

- `P` = `CACHE_PREFIX`, bawaan `laravel-cache-`.
- `F` = `illuminate:cache:flexible:created:`.
- Batas 5 percobaan, decay 60 detik. `now` = detik Unix.
- Nilai di-serialize seperti PHP: bilangan bulat `N` menjadi `i:N;`.
- `email` = isi field email, huruf kecil. `ip` = alamat klien yang dilihat server.
- Kunci limiter route: `Kr = md5("login" + email + "|" + ip)` dalam hex huruf kecil.
- Kunci Fortify: `Kf = email + "|" + ip`.

## Primitif cache (`DatabaseStore`)

Setiap primitif adalah statement tersendiri di luar transaksi, kecuali `increment`.

- `get(k)`: `SELECT * FROM cache WHERE key IN ($1)` dengan `P+k`. Bila baris ada dan `expiration <= now`, jalankan `DELETE FROM cache WHERE key IN ($1,$2) AND expiration <= $3` dengan `P+k` dan `P+F+k`, lalu kembalikan kosong.
- `add(k, v)`: panggil `get(k)`; bila ada, selesai tanpa menulis. Bila kosong, `INSERT INTO cache (key,value,expiration) VALUES ($1,$2,$3) ON CONFLICT DO NOTHING` dengan `expiration = now + 60`.
- `increment(k)`: satu transaksi berisi `SELECT * FROM cache WHERE key = $1 LIMIT 1 FOR UPDATE`, lalu bila baris ada `UPDATE cache SET value = $1 WHERE key = $2` dengan nilai lama + 1. Expiration tidak diubah. Bila baris tidak ada, commit tanpa UPDATE dan hasilnya 0.
- `put(k, v)`: `INSERT ... ON CONFLICT (key) DO UPDATE SET value, expiration` dengan `expiration = now + 60`.
- `forget(k)`: `DELETE FROM cache WHERE key IN ($1,$2)` dengan `P+k` dan `P+F+k`.

## Operasi limiter (`RateLimiter`)

- `attempts(k)` = nilai `get(k)`, atau 0.
- `hit(k)`: `add(k+":timer", now+60)`, lalu `added = add(k, 0)`, lalu `hits = increment(k)`. Bila `added` salah dan `hits == 1`, `put(k, 1)`. Hasilnya `hits`.
- `tooManyAttempts(k)`: bila `attempts(k) >= 5`, maka `get(k+":timer")`; ada berarti benar, kosong berarti `forget(k)` lalu salah.
- `availableIn(k)` = `max(0, get(k+":timer") - now)`.

## POST /login

1. Baca sesi seperti sekarang.
2. Bila `tooManyAttempts(Kr)`: `ra = availableIn(Kr)`, jawab 429 `{"message":"Too Many Attempts."}` dengan header `X-RateLimit-Limit: 5`, `X-RateLimit-Remaining: 0`, `Retry-After: ra`, `X-RateLimit-Reset: now + ra`. Sesi tetap disimpan.
3. `hit(Kr)`.
4. Validasi dan pemeriksaan kredensial seperti sekarang (dua pembacaan user dan dua bcrypt pada login sukses).
5. Gagal: `hit(Kf)`, lalu 422 seperti sekarang.
6. Sukses: hapus baris sesi lama satu kali, update `last_login_at`, pasang actor, lalu `forget(Kf)` dan `forget(Kf+":timer")` sebagai dua DELETE terpisah.
7. Pada 200 dan 422 tambahkan `X-RateLimit-Limit: 5` dan `X-RateLimit-Remaining: max(0, 5 - attempts(Kr))`. `attempts` di sini adalah satu SELECT baru sesudah handler.
8. Simpan sesi seperti sekarang.

Urutan PHP untuk login gagal pertama, dari log: baca sesi; `get Kr`; `add Kr:timer` (SELECT, INSERT); `add Kr` (SELECT, INSERT); transaksi increment `Kr`; baca user; `add Kf:timer`; `add Kf`; transaksi increment `Kf`; `get Kr` untuk header; tulis sesi.

## Di luar cakupan

PHP menjalankan limiter sebelum validasi field, jadi email kosong pun dihitung dengan kunci `md5("login|" + ip)`. Kandidat boleh mempertahankan urutan validasinya; kasus itu tidak ada di profil ini.
