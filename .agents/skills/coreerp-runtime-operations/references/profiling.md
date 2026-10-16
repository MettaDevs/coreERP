# Profiling PHP dan PostgreSQL

Gunakan sesuai pertanyaan pengukuran, bukan sebagai daftar setting yang selalu diaktifkan.

## Pisahkan waktu yang berbeda

Catat waktu HTTP klien, waktu sejak masuk worker sampai response siap, occupancy sampai termination selesai, waktu database dari PHP, serta CPU/scheduler bila tersedia. Selisih HTTP dan waktu worker mencakup antrean serta jaringan; ukur biaya jaringan idle sebelum menyebut semuanya antrean.

Periksa waiting, receiving, jumlah baris, ukuran respons, dan encoding. Endpoint yang mengabaikan limit dapat mengirim seluruh dataset walaupun query-nya cepat. Bandingkan encoding secara eksplisit sebagai faktor terpisah; jangan menganggap request k6 otomatis memakai header browser.

Pada FrankenPHP ZTS, CPU seluruh proses mencakup worker lain. Jangan memakai `getrusage()` process-wide sebagai CPU satu request. Native profiler yang mendukung thread atau delta `/proc/thread-self/schedstat` dapat memberi ukuran per thread; periksa counter dan satuannya. Waktu database dari PHP mencakup round trip/wait; PHP di luar database bukan otomatis CPU.

Laravel `DB::listen` dapat mengelompokkan bentuk query, jumlah panggilan, dan total waktunya. Jangan memakai `toRawSql()` atau merekam bindings autentikasi. Correlation ID harus menghubungkan request dan trace. Bedakan list/show/write, respons diterima/ditolak, dan volume data per tenant.

Berkas kunci instrumen yang private harus dimiliki UID proses web. `chmod 0600` pada berkas milik root tidak membuatnya terbaca worker `www-data`; periksa owner dan izin sebelum menyimpulkan reload gagal.

Instrumentasi yang di-bootstrap per worker perlu reload saat dipasang/dilepas. Backup berkas yang disentuh, pulihkan byte aslinya, lalu cek health dan bisnis. Simpan log privat dengan batas ukuran; jangan menambah write contention pada disk yang diukur. Ukur overhead lewat kontrol tanpa profiler.

Bila log berada pada tmpfs container, ekspor lewat `docker exec` dari namespace runtime ke berkas privat host. Verifikasi jumlah record; pembacaan archive container dapat tidak melihat isi mount seperti `/dev/shm`.

## Query PostgreSQL

Ambil query yang benar-benar dijalankan, jumlahnya per request, wait events/blockers, pertambahan sesi/transaction, buffer/IO dan WAL. Satu sampel backend menunggu belum membuktikan bottleneck dominan.

Untuk SELECT representatif, pakai EXPLAIN ANALYZE dengan BUFFERS dan TIMING OFF dalam transaksi read-only, statement timeout, dan parameter fixture yang benar. Periksa actual rows versus estimasi, loops, scan/join/sort, serta buffer reads/hits. Jangan memakai EXPLAIN ANALYZE pada write karena ia benar-benar menjalankannya.

Jika perlu `auto_explain`, periksa versi dan setting awal. Batasi durasi/sampling, nonaktifkan parameter logging, matikan per-node timing bila tidak dibutuhkan, dan siapkan restore. `log_analyze` dapat menambah overhead walau query tidak dicatat. Jangan memulai dengan logging semua query berparameter untuk seluruh server.

Bedakan query tunggal lambat, banyak query murah, serialization/authorization PHP mahal, koneksi per request, WAL/row locks, CPU steal/scheduler, dan antrean worker. Buktikan lewat trace/plan serta kontrol sebelum mengubah index, query, worker, atau database setting.

Untuk biaya trigger PL/pgSQL, statistik `pg_stat_user_functions` dapat membantu. `track_functions = 'pl'` dapat dipasang sementara pada koneksi aplikasi tanpa restart database; ambil delta, jangan reset statistik cluster bersama. Total waktu fungsi adalah elapsed time, bukan CPU. Kembalikan koneksi/setting sesudah profil dan jangan menganggap berkurangnya jumlah write otomatis menaikkan throughput.

## Biaya observability pada host bersama

Bila aplikasi dan Sentry berada di host yang sama, ukur CPU ingestion/worker Sentry bersama CPU API/database. Sampling tracing dan sampling error adalah setting berbeda. Uji sampling tracing sebagai faktor terpisah sambil mempertahankan pencatatan error, verifikasi opsi SDK pada worker web, lalu ulangi kontrol. Jelaskan berkurangnya detail tracing sebagai tradeoff; angka sampling dari satu load test tidak menjadi default semua server.

## Protokol PDO PostgreSQL

Untuk query Laravel yang menyiapkan lalu mengeksekusi statement sekali saja, ukur biaya round trip driver selain waktu eksekusi SQL. `PDO::PGSQL_ATTR_DISABLE_PREPARES` memakai query berparameter tanpa membuat named prepared statement; ini berbeda dari `PDO::ATTR_EMULATE_PREPARES`. Jangan mengganti binding dengan interpolasi SQL atau melemahkan durability demi angka benchmark.

Uji perubahan ini sebagai faktor tersendiri: periksa binding string dengan kutip, integer, tipe hasil, transaksi, serta alur bisnis dan race yang sama. Statement yang dieksekusi berulang dapat memiliki tradeoff berbeda. Periksa koneksi web yang aktif, koneksi tenant yang menyalin konfigurasi dasar, dan koneksi tes; PHP CLI sendiri tidak membuktikan setting worker web sudah berubah. Pada pengujian dev1 16 Oktober 2026, flag ini memperbaiki throughput secara terbatas; ia tidak menghilangkan antrean pada 1.000 VU.

## Batas penerapan

Pengukuran dev1 pada 16 Oktober 2026 memperlihatkan health terpisah menghilangkan banjir 503 tanpa penambahan worker. Itu memperbaiki ketersediaan, bukan otomatis biaya transaksi. Jumlah worker dari run tersebut tidak menjadi default server baru; ulangi dengan workload yang dipasang.

Rujukan utama:

- https://frankenphp.dev/docs/performance/
- https://www.postgresql.org/docs/16/auto-explain.html
- https://www.postgresql.org/docs/16/sql-explain.html
- https://www.php.net/manual/en/class.pdo-pgsql.php
- https://docs.kernel.org/scheduler/sched-stats.html
