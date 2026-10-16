---
name: coreerp-runtime-operations
description: Prepare CoreERP servers or additional nodes, change runtime settings, run business load tests, and profile PHP/PostgreSQL with repeatable evidence and rollback. Use for server setup, setting/tuning, capacity investigations, and load-test operations; not for database sharding design or unrelated application features.
---

# CoreERP runtime operations

Hasil harus dapat diulang: konfigurasi tersimpan sama dengan runtime yang diukur dan kesimpulan menyebut apa yang benar-benar terbukti. Jumlah worker hasil satu server bukan default untuk server lain.

## Temukan keadaan yang aktif

- Baca alias server di konfigurasi SSH pengguna. Gunakan dokumentasi proyek dan runtime untuk peran node; jangan menyalin IP, user, atau key dari catatan lama.
- Catat commit, digest image, topology API/database/proxy, cgroup CPU/RAM, worker/thread, koneksi, serta beban lain pada host dan generator. Simpan baseline dan cara rollback sebelum mengubah setting.
- Cocokkan deployment dengan container aktif. PHP CLI tidak membuktikan OPcache/SAPI web; PID container database bukan jumlah koneksi. `PDO::ATTR_PERSISTENT=false` tidak membuktikan koneksi Octane dibuka per request: ukur pertambahan sesi database.
- Gunakan `coreerp-harbor` untuk jalur rilis/image dan `coreerp-konsol` jika provisioning, SSO, atau sisi pusat disentuh. Pertahankan perubahan lain di checkout pengguna.

## Pemasangan atau penambahan node

Gunakan deployment repo, image rilis terverifikasi, dan lokasi secret operator. Periksa database, sesi/cache bersama, object storage, trusted proxy, domain/TLS, serta scheduler/migration sesuai topology. Jangan menduplikasi penjadwal tanpa mekanisme repo yang menjaganya.

Verifikasi dari runtime UI/API: health, login, operasi bisnis terautentikasi, nomor nyata, tenant scope, dan error. Health perlu menguji PHP/aplikasi tanpa ikut antre dengan seluruh transaksi. Pada SaaS, rujuk `deploy/saas/Caddyfile` dan `deploy/saas/compose.yaml`; jangan menggantinya dengan respons statis yang selalu 200. Health hijau bukan bukti SLO transaksi lulus.

Perubahan harus bertahan setelah recreate. Pertahankan digest image dan setting rilis aktif; nilai env lama tidak boleh menurunkan versi image. Recreate hanya layanan yang berubah, lalu periksa konfigurasi aktif kembali.

## Load test dan tuning

- Ikuti `docs/dev/20-load-and-concurrency-testing.md` dan gate proyek. Nyatakan gap topology, tenant, durasi, dan permukaan; satu instance tidak membuktikan multi-instance.
- Uji business flow lewat sesi nyata. GET login hanya uji halaman login. Provision fixture lewat jalur tepercaya yang berlaku; setup dan smoke harus selesai sebelum pengukuran. Jangan mematikan limiter atau memalsukan konteks tenant agar setup lolos.
- Pisahkan generator bila memungkinkan, tetapi periksa bebannya juga. Pakai fixture sama dan RUN_ID baru: idempotency key lama dapat mengembalikan dokumen selesai. Catat pertumbuhan data, warm-up, beban penuh, think time, dan drain terpisah.
- Catat ukuran/cardinality respons dan header encoding klien. Pastikan parameter seperti `per_page` benar-benar membatasi hasil. Sesuaikan encoding dengan consumer yang diuji; jangan diam-diam mengganti compression antara baseline dan sesudah. Pisahkan waktu waiting/receiving dari proses di worker sebelum menyebut selisihnya antrean.
- Bandingkan kandidat di bawah/atas konfigurasi awal. Ubah satu faktor, ukur pada concurrency tertahan, dan ulangi kontrol bila hasil dekat atau host berubah. Pilih dari latency, pekerjaan selesai, error, CPU/steal, waits dan koneksi; bukan jumlah worker terbanyak.
- Pisahkan correctness, delivery, dan latency. Delivery harus gagal pada proxy rejection/timeout; nol pekerjaan selesai tidak boleh hijau. Hitung probe yang mendapat penolakan sah, bukan hanya percobaan. Status 0 bukan jawaban.
- Cocokkan 5xx dengan upstream log proxy. Jangan menganggap semua 503 dari aplikasi atau semua 5xx dari proxy. Laporkan successful throughput dan completed flows terpisah dari request cepat ditolak; p95 yang didominasi 503 tidak membuktikan SLO.
- Jalankan race yang sengaja berebut dan oracle SQL pada fixture. Buktikan oracle bisa merah bila belum pernah diverifikasi; gunakan rollback untuk suntikan sementara. Jangan menghapus baris tersimpan secara fisik.

## Profiling bila kapasitas tetap rendah

Baca [profiling.md](references/profiling.md) untuk membedakan antrean runtime, eksekusi PHP, query, dan scheduler. Mulai tanpa beban dan pada concurrency tertahan; latensi saat jenuh terutama mengukur antrean.

Pakai profiler/tracing yang tersedia. Instrumentasi tambahan perlu sementara, dibatasi pada run/fixture, diukur overhead-nya, tidak merekam cookie/token/password/bindings sensitif, dan dilepas sesudahnya. Hindari restart database hanya demi profiler bila sampled traces dan EXPLAIN dapat menjawab pertanyaan.

## Hasil dan penyimpanan

Simpan konfigurasi sebelum/sesudah, manifest lingkungan, perintah pengulangan, metrics, trace/SQL tersanitasi, dan verifikasi pemulihan. Pisahkan pengukuran, inferensi, dan hal yang belum diuji. Dashboard/dependency baru tidak wajib.

Bila diminta commit/push, stage hanya pekerjaan ini, pisahkan format dari perilaku, push tanpa force, dan jelaskan bila branch masih perlu merge agar ikut rilis. Skill proyek di `.agents/skills/coreerp-runtime-operations/` adalah salinan yang tersimpan di Git; setelah diubah, sinkronkan instalasi personal dan verifikasi isinya sama.
