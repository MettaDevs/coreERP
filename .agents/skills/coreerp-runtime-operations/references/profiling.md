# Profiling PHP dan PostgreSQL

Gunakan sesuai pertanyaan pengukuran, bukan sebagai daftar setting yang selalu diaktifkan.

## Pisahkan waktu yang berbeda

Catat waktu HTTP klien, waktu sejak masuk worker sampai response siap, occupancy sampai termination selesai, waktu database dari PHP, serta CPU/scheduler bila tersedia. Selisih HTTP dan waktu worker mencakup antrean serta jaringan; ukur biaya jaringan idle sebelum menyebut semuanya antrean.

Pada FrankenPHP ZTS, CPU seluruh proses mencakup worker lain. Jangan memakai `getrusage()` process-wide sebagai CPU satu request. Native profiler yang mendukung thread atau delta `/proc/thread-self/schedstat` dapat memberi ukuran per thread; periksa counter dan satuannya. Waktu database dari PHP mencakup round trip/wait; PHP di luar database bukan otomatis CPU.

Laravel `DB::listen` dapat mengelompokkan bentuk query, jumlah panggilan, dan total waktunya. Jangan memakai `toRawSql()` atau merekam bindings autentikasi. Correlation ID harus menghubungkan request dan trace. Bedakan list/show/write, respons diterima/ditolak, dan volume data per tenant.

Instrumentasi yang di-bootstrap per worker perlu reload saat dipasang/dilepas. Backup berkas yang disentuh, pulihkan byte aslinya, lalu cek health dan bisnis. Simpan log privat dengan batas ukuran; jangan menambah write contention pada disk yang diukur. Ukur overhead lewat kontrol tanpa profiler.

## Query PostgreSQL

Ambil query yang benar-benar dijalankan, jumlahnya per request, wait events/blockers, pertambahan sesi/transaction, buffer/IO dan WAL. Satu sampel backend menunggu belum membuktikan bottleneck dominan.

Untuk SELECT representatif, pakai EXPLAIN ANALYZE dengan BUFFERS dan TIMING OFF dalam transaksi read-only, statement timeout, dan parameter fixture yang benar. Periksa actual rows versus estimasi, loops, scan/join/sort, serta buffer reads/hits. Jangan memakai EXPLAIN ANALYZE pada write karena ia benar-benar menjalankannya.

Jika perlu `auto_explain`, periksa versi dan setting awal. Batasi durasi/sampling, nonaktifkan parameter logging, matikan per-node timing bila tidak dibutuhkan, dan siapkan restore. `log_analyze` dapat menambah overhead walau query tidak dicatat. Jangan memulai dengan logging semua query berparameter untuk seluruh server.

Bedakan query tunggal lambat, banyak query murah, serialization/authorization PHP mahal, koneksi per request, WAL/row locks, CPU steal/scheduler, dan antrean worker. Buktikan lewat trace/plan serta kontrol sebelum mengubah index, query, worker, atau database setting.

## Batas penerapan

Pengukuran dev1 pada 16 Oktober 2026 memperlihatkan health terpisah menghilangkan banjir 503 tanpa penambahan worker. Itu memperbaiki ketersediaan, bukan otomatis biaya transaksi. Jumlah worker dari run tersebut tidak menjadi default server baru; ulangi dengan workload yang dipasang.

Rujukan utama:

- https://frankenphp.dev/docs/performance/
- https://www.postgresql.org/docs/16/auto-explain.html
- https://www.postgresql.org/docs/16/sql-explain.html
- https://docs.kernel.org/scheduler/sched-stats.html
