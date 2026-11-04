# Pindah ke Bun + Elysia

Pada 4 November 2026 pemilik produk memutuskan semua repo baru ditulis dengan Bun + Elysia. Repo barunya dua; nama
dan pembagian ERP, Praktik Dokter, dan control plane di antara keduanya belum diputuskan. Selama repo baru belum
menggantikan apa pun, CoreERP di repo ini tetap acuan perilaku: nama tabel dan kolom database tetap sama, dan respons
HTTP repo baru diuji terhadap respons CoreERP.

Halaman ini mencatat apa yang sudah diukur, konfigurasi yang dipakai saat diukur, dan aturan yang wajib dibawa ke repo
baru. Desain repo baru sendiri belum ditulis.

## Dasar keputusan

Keputusan diambil dari eksperimen pembanding stack pada 3–4 November 2026. CoreERP (Laravel di Octane FrankenPHP), Go,
Bun + Hono, Bun + Elysia, dan Rust menjalankan satu alur transaksi yang sama, profil `pending-purchase-v1`, dari
snapshot CoreERP commit `6b3cf584`: daftar penerimaan, buat penerimaan, lihat draf, ganti baris, pratinjau, selesaikan
penerimaan, lihat penerimaan selesai, aset hasil penerimaan, daftar aset, dan detail aset. Sebelum diuji beban, setiap
kandidat wajib menghasilkan respons HTTP, penulisan SQL, dan hasil oracle yang sama dengan CoreERP.

Beban memakai model tertutup. Setiap VU menunggu Uniform(1, 9) detik di antara aksi, jadi jumlah VU bukan jumlah
request serentak. Data uji 128 tenant × 8 akun, dan pengguna sudah login sebelum pengukuran. Batas lulus: baca p95
200 ms dan p99 500 ms, tulis p95 400 ms dan p99 900 ms, nol error 5xx aplikasi, nol pelanggaran tenant, nol nomor
ganda, dan oracle SQL bersih.

| Server dan kuota | Elysia lulus | Elysia gagal | Yang membatasi |
| --- | --- | --- | --- |
| dev1: app 2 CPU, database 4 CPU | 900 VU | 1.000 VU | fsync WAL; semua kandidat non-PHP tertahan ±200 req/dtk |
| Cue: app 2 CPU, database 4 CPU | belum diukur di bawah 1.600 | 1.600 VU, baca p95 411 ms | CPU app, 24% periode ter-throttle |
| Cue: tanpa kuota, 8 core dibagi app, database, dan generator | 2.400 VU, baca p95 109 ms, 476 req/dtk | 2.800 VU, baca p95 944 ms | CPU host; app ±3,5 core, database ±3,8 core |

Angka dev1 untuk kandidat lain, dengan profil yang sama: Rust lulus 1.000 dan gagal 1.100, Go 900 dan 1.000, Hono 800
dan 900, CoreERP 300 dan 400. Waktu CPU aplikasi per request di dev1: Rust 2,3 ms, Go 4,3 ms, Bun 6,2 ms, CoreERP 22 ms.
Pada core yang sama, Elysia butuh lebih banyak CPU daripada Go untuk jumlah VU yang sama.

Batasan angka di atas:

- Satu run per langkah. Lima ulangan belum selesai, jadi variasinya belum diketahui.
- Disk Cue tidak meneruskan flush ke host: `fdatasync` ±65 µs, sedangkan dev1 ±5,6 ms. Angka Cue adalah batas atas
  bila fsync gratis, bukan kapasitas yang durabel.
- Kapasitas produksi wajib diukur ulang di server dengan disk lokal yang flush-nya durabel. Jangan mengutip angka Cue
  sebagai jaminan.

## Konfigurasi yang diukur

| Bagian | Nilai | Alasan |
| --- | --- | --- |
| Versi | Bun 1.4.4, Elysia 1.4.30, oRPC 1.16.1, postgres.js 3.4.7, zod 4.6.7, PostgreSQL 17 | Versi yang menghasilkan angka di atas |
| Pintu HTTP | Satu route Elysia `app.all('*')` dengan `parse: 'none'` meneruskan request ke handler OpenAPI oRPC; routing dan validasi zod ada di oRPC | Routing dan validasi bawaan Elysia tidak ikut diukur |
| Proses | Satu proses Bun per core dengan `reusePort: true`; Cue tanpa kuota memakai 8 proses dalam satu container | Satu proses Bun menjalankan JavaScript di satu thread, jadi core tambahan tanpa proses tambahan tidak menambah kapasitas |
| Pool database | 4 koneksi per proses; satu request memegang satu koneksi dari awal sampai selesai | Total koneksi = instance × proses × 4, dan harus muat di `max_connections` (bawaan 100) |
| `idleTimeout` | 255 detik | Bawaan Bun 10 detik memutus request yang lebih lama |
| Prepared statement | `sql.unsafe(query, params, { prepare: true })` | Tanpa `prepare`, `unsafe()` mem-parse dan menyusun rencana setiap query; dengan prepared statement CPU database per request turun kira-kira separuh |
| Batas berkas terbuka | `--ulimit nofile=65536:65536` | Soft limit bawaan container 1024 menolak koneksi di atas ±1.000 socket. Bun menaikkannya sendiri, tetapi jangan bergantung pada itu |
| Tanggal | Dibaca sebagai teks (`types.rawDate` postgres.js) | Keluarannya sama persis dengan CoreERP |
| Uang | Bilangan bulat `bigint` dengan skala tetap | Tidak ada float pada nilai uang |
| PostgreSQL | `shared_buffers` 1 GB, `effective_cache_size` 3 GB dari memori 4 GB; selebihnya bawaan (`fsync`, `full_page_writes`, dan `synchronous_commit` on) | Belum ada setelan lain yang terbukti menaikkan batas |

Pada 2.400 VU, delapan proses Bun memakai ±610 MB memori.

## Aturan database yang dibawa

### Parameter mengikuti tipe kolom

Kolom id `char(26)` yang dibandingkan dengan `= any($1::text[])` membuat PostgreSQL tidak memakai indeks dan membaca
seluruh tabel. Cast parameter ke tipe kolomnya sendiri, atau jangan cast.

### Setiap commit membayar satu fsync

Di dev1, `fdatasync` hanya ±180 per detik. Backend menunggu `LWLock:WALWrite` dan `IO:WalSync`, bukan kunci baris.
Pada alur ini, 12 dari 16 commit per siklus adalah penulisan baris `sessions` di PostgreSQL, termasuk pada request
baca. Konsekuensinya:

- Sesi tidak disimpan di PostgreSQL. CoreERP sudah merancang sesi di Valkey ([cache dan sesi](36-cache-and-sessions.md)),
  dan repo baru mengikutinya.
- Rancangan itu memakai AOF `appendfsync always`, karena pencabutan sesi tidak boleh hilang. Di disk lambat, fsync per
  request hanya berpindah dari PostgreSQL ke Valkey. Varian eksperimen memakai `everysec`, dan performanya belum
  diukur. Pilihan ini wajib diputuskan dan diukur sebelum repo baru dinyatakan lebih cepat di disk lambat.
- Request baca tidak menulis apa pun kecuali memang perlu, termasuk pembaruan waktu aktivitas.

### Konteks permintaan

Di CoreERP, sekitar 9 dari 13 query per request hanya membentuk konteks: sesi, user, keanggotaan, scope kebijakan data,
organisasi, dan izin dari role bertingkat. Rancangan cache berikut sudah lolos uji kesetaraan dengan CoreERP (respons
HTTP, pencabutan hak, rollback, dan throttle tanpa perbedaan), tetapi performanya belum diukur:

- Tabel `security_context_versions` berisi satu baris. Nilainya dinaikkan trigger statement-level pada setiap
  perubahan tabel pembentuk konteks. Untuk `users`, perubahan yang hanya menyentuh `last_login_at`, `updated_at`, atau
  `remember_token` tidak menaikkan versi, karena setiap login menulis kolom itu.
- Setiap request menjalankan satu query versi. Bila versinya berubah, seluruh cache proses dikosongkan.
- Cache disimpan di memori proses, dengan kunci user, keanggotaan, dan tenant. Umurnya yang lebih pendek antara TTL
  300 detik dan batas waktu berlaku penugasan berikutnya, sehingga penugasan yang berakhir karena waktu tidak tertinggal
  di cache.
- Pemilihan tempat kerja (keanggotaan, perusahaan, unit kerja) tetap dihitung per request dan tidak disimpan.

Versi dibaca dari database pada setiap request, jadi rancangan ini tetap benar dengan banyak instance API.

### Actor audit

Trigger audit membaca actor dari `set_config('coreerp.user_id', $1, false)`. Pengaturan itu berlaku untuk seluruh sesi
koneksi dan direset di akhir request. Ini aman selama satu request memegang satu koneksi sampai selesai.

PgBouncer dengan transaction pooling memutar koneksi server antar klien pada setiap transaksi, sehingga actor satu
user dapat terbawa ke request user lain. Bila pooler seperti itu dipakai, actor dipasang dengan
`set_config(..., true)` (setara `SET LOCAL`) di dalam setiap transaksi, dan prepared statement diatur ulang.

### Yang dilarang

`fsync=off`, `full_page_writes=off`, dan unlogged table untuk data penting. `synchronous_commit = off` hanya boleh
lewat `SET LOCAL` untuk tulisan yang boleh hilang bila server mati mendadak, bukan untuk posting, nomor, stok, atau
ledger.

## Cara mengukur ulang

- Awal siklus tiap VU disebar Uniform(0, panjang satu siklus), dan pemanasan minimal satu siklus. Bila semua VU mulai
  bersamaan, laju tulis naik turun bergelombang (pernah 3–100 per detik), dan hasil lulus atau gagal bergantung pada
  letak jendela ukur terhadap gelombang itu.
- Login diukur terpisah dari pemakaian.
- Catat I/O disk host selama run. Hasil latensi dari host yang sedang dibebani proses lain tidak sah dan harus diulang.
- Label metrik k6 tidak boleh berisi ID. Label per request membuat k6 kehabisan memori pada 2.000 VU.
- Ulangi lima kali pada titik lulus terakhir dan titik gagal pertama sebelum angkanya dikutip sebagai kapasitas.
- Gate kebenaran dan syarat banyak instance tetap mengikuti [load dan concurrency testing](20-load-and-concurrency-testing.md).
  Eksperimen ini hanya memakai satu instance.

## Yang belum diukur atau diputuskan

- A/B sesi Valkey, cache konteks, dan setelan PostgreSQL dibatalkan pada 4 November. Belum ada satu pun yang terbukti
  menaikkan batas.
- Lima ulangan pada titik batas.
- Kapasitas di server dengan disk yang flush-nya durabel.
- Nama dan pembagian dua repo baru.

## Sumber

- Kode dan harness eksperimen ada di `experiments/stack-comparison/` pada branch `codex/native-stack-comparison`:
  implementasi TypeScript di `native/typescript/`, rancangan varian teroptimasi di `optimized.md` dan
  `infra/optimized-schema.sql`, serta jejak keputusan di `HANDOFF.md` dan `.audit/native-stack/decisions.tsv`.
- Hasil setiap run ada di folder `private/load/` eksperimen itu, di dev1 dan di Cue. Folder itu tidak masuk repo.
- Alur layar, permintaan, dan data CoreERP dalam bentuk yang tidak terikat bahasa pemrograman ada di
  [artifact design system](https://claude.ai/artifact/67fEJNECSNBaNo2G4SJse2).
