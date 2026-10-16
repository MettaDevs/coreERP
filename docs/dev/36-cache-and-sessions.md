# Cache dan sesi bersama dengan Valkey

Core dapat menyimpan session dan cache framework pada Valkey di mesin yang sama dengan API. PostgreSQL tetap
menyimpan data bisnis, nomor, audit, antrean pekerjaan, dan hasil cache analitik yang memang milik database tenant.
Pemindahan ini tidak membuat respons API otomatis di-cache dan tidak menambah CPU yang tersedia untuk transaksi.

## Cache pada jalur transaksi

Posting, stok, status dokumen, hak akses, dan hasil sesudah simpan tetap membaca keadaan terbaru.
TTL pada respons transaksi bukan cara mengurangi biaya query tulis. Cari pengulangan query dan rencana
eksekusinya dahulu; pembacaan referensi hanya boleh dipakai ulang bila batas hidup dan invalidasinya jelas.

`MoneyPrecision` berbagi hasil baca dalam satu scope request/job. Kuncinya membedakan koneksi/database,
tenant, dan mata uang. Penyimpanan atau penghapusan `CurrencyPrecision`, awal/commit transaksi terluar,
serta rollback termasuk savepoint membuang hasil itu. Savepoint yang berhasil tidak mengulang bacaan
referensi yang sama. Penulisan tabel presisi melalui query builder atau SQL Laravel juga membuang cache,
karena jalur itu tidak menerbitkan event model; SELECT biasa tidak membuangnya. Request berikutnya pada worker Octane membaca ulang; tidak ada TTL lintas
API yang membuat perubahan presisi terlambat terlihat. Counter dan nomor tidak ikut cache ini.

Daftar work order memakai `IncrementalList` dengan tipe urut native yang dinyatakan server melalui
`nativeSortColumns`. Pengurutan tanggal asli dapat memakai indeks, tanpa cast teks untuk mengurutkan
seluruh data. Format cursor lama, pemecah seri ID, posisi NULL di belakang, search/filter server, dan
pembacaan langsung dari PostgreSQL dipertahankan. Kolom yang tidak menyatakan tipe native tetap memakai
perilaku urut sebelumnya; jangan mengubah arti urutan kolom hanya untuk mendapatkan indeks.
Cursor lanjutan memakai perbandingan pasangan nilai/ID. Kelompok NULL digabung dengan `UNION ALL` dalam
satu statement, supaya index seek tetap bekerja dan edit bersamaan tidak menggandakan baris dalam satu batch.

## Batas host, tenant, dan lingkungan

`ResolveEnvironment` menentukan lingkungan dari alamat yang terdaftar sebelum Laravel membuka sesi. Alamat asing
di bawah domain SaaS ditolak. `EnvironmentConnection` menggeser database bisnis, tetapi pengguna, membership,
session, rate limit, dan pekerjaan pusat tetap memakai penyimpan yang memang dipilih untuknya. Koneksi session
Valkey bernama `sessions` tidak boleh ditimpa menjadi nama koneksi PostgreSQL ketika database lingkungan berubah.

Cookie tetap host-only, Secure pada SaaS, HttpOnly, dan SameSite=Lax. Cookie tenant tidak sengaja diperluas ke domain
induk. ID session acak boleh berada pada satu penyimpan bersama; hak ke tenant tujuan tetap diperiksa oleh
`RequireTenantMembershipAtAddress` dan workspace tepercaya, bukan dianggap sah karena nama host.

Prefix protokol secara bawaan memuat nama aplikasi dan sidik APP_KEY penempatan. API yang berbagi penempatan
memakai prefix yang sama; penempatan dengan kunci lain tidak berbagi keyspace. Tidak ada pengubahan prefix global
per request pada worker Octane. Kunci cache yang berisi data lingkungan harus membawa identitas sumbernya.
`TimezoneResolverService::cacheKey()` membedakan host/port/database tepercaya agar ID geografi hasil salinan
lingkungan tidak bertabrakan. Cache hasil analitik tetap di PostgreSQL tenant dan mempertahankan tenant, perusahaan,
serta fingerprint hak principal yang sudah diperiksa.

Driver session Valkey berbawaan terenkripsi oleh Laravel dengan APP_KEY. AOF menyimpan payload terenkripsi,
bukan isi session yang dapat dibaca langsung. Perintah transfer mengenkripsi payload database lama yang belum
terenkripsi dengan bentuk yang sama seperti `EncryptedStore`; tidak menafsirkan isi session.

## Penyimpan dan hak

Layanan SaaS `core-valkey` memakai Valkey, bukan server Redis. Nama driver Laravel dan ekstensi client-nya tetap
`redis`/phpredis karena memakai protokol yang kompatibel. Valkey 9.1 menyediakan ACL database yang dipakai oleh
`deploy/saas/valkey/start.sh`:

| Akun       | Database | Isi                                           |
| ---------- | -------- | --------------------------------------------- |
| `cache`    | 1        | Cache framework, rate limit, dan lock bersama |
| `sessions` | 2        | Payload session dan generasi pencabutan login |

Akun bawaan dimatikan. Setiap akun mempunyai secret berbeda, tidak boleh memilih database akun lain, dan tidak
memiliki perintah administrasi, MONITOR, FLUSHDB, atau FLUSHALL. Port tidak dipublikasikan oleh Compose. Secret hanya
ada di env privat operator; berkas ACL menyimpan hash dan berizin ketat. Untuk koneksi lintas host, sediakan jaringan
privat dan TLS sebelum membuka koneksi; konfigurasi satu mesin ini bukan izin membuka port ke internet.

`maxmemory-policy=noeviction` dipakai karena lock, rate limit, dan sesi tidak boleh hilang demi memberi tempat bagi
cache lain. Batas memori dapat diatur melalui `VALKEY_MAXMEMORY`; bila penuh, penulisan gagal, bukan menghapus sesi
atau mengizinkan fallback per-instance. Pantau pemakaian memori dan kegagalan tulis. AOF memakai `appendfsync always`
dan volume `core-valkey-data`: pencabutan sesi tidak boleh bergantung pada cache sementara. Biaya fsync-nya tetap
harus diukur dalam load test. Health `/up` memeriksa koneksi Valkey yang aktif tanpa membuka sesi bisnis.

## Logout dan pembersihan

Logout biasa menghapus sesi aktif melalui driver Laravel. Back-channel logout SSO tetap mengakhiri seluruh sesi
pengguna lintas alamat, termasuk dengan banyak API: `SessionRevocation` mengganti generasi pengguna pada database
session, dan middleware menolak generasi lama pada request berikutnya. Penanda tidak kedaluwarsa sendiri; generasi
yang menghilang tidak boleh mengeluarkan sesi baru secara keliru. Login baru merekam generasi terbaru. Remember token
dicabut agar remember-me tidak langsung membuka sesi lagi setelah logout penyedia.

Jangan menjalankan `cache:clear` atau flush massal pada penyimpan ini. Perintah tersebut sengaja ditolak ACL.
Invalidasi kode memakai `forget()` terhadap kunci miliknya; TTL menangani cache biasa. Jangan memakai instance
Valkey/Redis Harbor untuk CoreERP dan jangan menghapus volume saat memperbarui image.

## Aktivasi dan rollback

SaaS tetap berbawaan `SESSION_DRIVER=database` dan `CACHE_STORE=database` sampai operator melakukan cutover. Sebelum
merilis compose baru, buat `VALKEY_CACHE_PASSWORD` dan `VALKEY_SESSION_PASSWORD` acak alfanumerik minimal 32 karakter
di `/etc/coreerp/saas.env`, tanpa mencetaknya. Rilis membawa image Valkey lewat Harbor/digest seperti layanan lain.

Sesudah layanan dan client PHP terverifikasi, hentikan API sebentar dan jalankan
`php artisan core:sessions:transfer-to-valkey` melalui container CLI sekali-jalan dengan konfigurasi driver database
lama. Perintah ini hanya memindahkan session yang belum kedaluwarsa, mempertahankan payload opaque dan waktu habisnya,
kemudian menandai salinan database sebagai kedaluwarsa. Jumlah dipindah dicetak; ID, cookie, dan payload tidak.
Perintah wajib berjalan ketika tidak ada API yang menulis sesi database. Bila gagal, jangan mengganti driver.

Setelah berhasil, atur `SESSION_DRIVER=redis` dan `CACHE_STORE=redis` lalu recreate API, worker, dan scheduler dengan
image yang sama. Koneksi session dan store `sessions` dipilih config; jangan mengisinya dengan koneksi `pgsql`.
Verifikasi cookie lama masih diterima, login/logout/CSRF, pergantian tenant/perusahaan, penolakan tenant lain,
back-channel logout, dan penggunaan sesi melalui lebih dari satu API. Queue tetap database.

Rollback ke driver database membutuhkan login ulang karena salinan database sudah kedaluwarsa. Ini disengaja:
rollback tidak boleh menghidupkan kembali sesi yang sudah dicabut pada Valkey. Jangan mengimpor balik snapshot lama.

## Pemeriksaan

`SessionRevocationTest`, `ValkeyHealthTest`, `EnvironmentAddressTest`, dan `TimezoneCacheScopeTest` menjaga perilaku
aplikasi. `ValkeyStoreIntegrationTest` memakai Valkey sungguhan di CI, termasuk lock dan penolakan flush/akses database
session oleh akun cache. Restart/crash dan ACL diuji pada instance terisolasi sebelum deployment. Load test bisnis
tetap memakai alur yang sama; periksa oracle SQL, bukan hanya kecepatan respons.

Lihat [SSO](32-sso.md), [scope database](08-query-scopes-and-schema.md),
[alur rilis](29-alur-rilis-server-klien.md), [ACL Valkey](https://valkey.io/docs/topics/acl/), dan
[persistence Valkey](https://valkey.io/docs/topics/persistence/).
