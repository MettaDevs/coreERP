# Handover pembanding stack CoreERP

Dicatat 3 November 2026, sekitar 16:22 WITA. Pengguna meminta berhenti dan menyerahkan pekerjaan kepada agent lain. Semua agent, listener uji, dan build milik eksperimen sudah dihentikan. Belum ada benchmark server, keputusan pemenang, merge, push, atau deploy aplikasi aktif.

## Benchmark dev1, 3-4 November malam

dev2 dilewati atas keputusan pengguna (CPU steal 35-37%). Load runner: `load/user-flow.js` (k6 0.55.0, satu sesi per VU, jeda Uniform(1,9) detik, start siklus Uniform(0,60) sesudah jendela login, warmup 75 detik; sampai r7 start Uniform(0,10) dan warmup 30), `scripts/load.py` (satu run, sampler cgroup/koneksi/checkpoint, oracle rekonsiliasi atas ledger), `scripts/load_report.py`, `scripts/ramp.py`. Fixture 128 tenant × 8 akun: `private/fixture-128.json`, `seed-128.dump` (`23de4f57…`), baseline `seed-128-baseline.json`. Evidence run di `private/load/<run-id>/`.

Budget: setiap SUT 2 CPU, 1 GiB, maksimal 8 koneksi database (PHP 8 worker Octane, Go/Rust pool 8, Bun dua proses reusePort pool 4). Database 4 CPU, 4 GB, shared_buffers 1 GB (nilai di `runtime.env` dev1, bawaan compose tetap ukuran laptop). Generator 2 CPU, 2 GB.

Perbaikan yang terbukti sebelum angka dipakai (rincian dan evidence di `decisions.tsv`):
- Cast `text[]` pada kolom `char(26)` di Go dan Rust membuat query izin seq scan; dihapus.
- Bun `idleTimeout` 255 detik; bawaannya memutus request di atas 10 detik.
- Prepared statement per koneksi di Bun dan Rust; A/B 2×2 menurunkan CPU database per request sekitar separuh.
- Runner: jendela login 0,5 × VU, kegagalan login terpisah dari verdict SLO, fixture lewat SharedArray, ramp berhenti pada run rusak.

Angka sejauh ini, satu run per langkah, belum lima ulangan:
- PHP lulus 200 VU, gagal 400 VU (throttled 50% periode di kuota 2 CPU, baca p95 2,2 detik).
- CPU per request pada 200 VU, sesudah perbaikan: database sekitar 7-8 ms untuk Go/Bun/Rust dan 17 ms untuk PHP; app 2,3 ms Rust, 4,3 ms Go, 6,2 ms Bun, 22 ms PHP.
- Ramp `r7` (Go, Hono, Elysia, Rust yang sudah diperbaiki) berjalan dari 400 VU; log di `~/coreerp-stack-comparison/run-rust-cache.log` dan `private/load/r7-ramp.json`.

Belum: lima ulangan di titik batas tiap stack, PHP diperhalus antara 200 dan 400 VU, `dev-windows1`.

## Hasil r7 dan bottleneck yang terbukti, 4 November pagi

Ramp `r7` selesai, satu run per langkah: Go dan Rust lulus 800 VU dan gagal 1.000 VU (baca p95 139 dan 385 ms, tulis p95 464 dan 601 ms; 444 login Go gagal, Rust 0). Hono dan Elysia lulus 400 dan gagal 800. Oracle bersih di semua run. Rata-rata CPU tidak jenuh (database 123-139% dari 400%, app sekitar 60% dari 200%), jadi rata-rata tidak menjelaskan kegagalan; deret per detik yang menjelaskan.

Diagnosis memakai `scripts/probe.py` (CPU dan socket per proses SUT, state dan wait event setiap backend, per detik) pada `p1-rust-1000` dan `p4-hono-800`:

- **Beban datang bergelombang, bukan rata.** Laju tulis berayun antara 3 dan 100 per detik dengan periode sekitar 65 detik, yaitu satu siklus 13 langkah × jeda rata-rata 5 detik. Semua VU memulai siklus dalam jendela 10 detik yang sama sesudah jendela login, dan 90 detik plateau tidak cukup untuk fasenya menyebar. Stall (detik dengan baca p95 di atas 200 ms) selalu jatuh di puncak gelombang complete. Pada Go/Rust 1.000 VU puncak itu tepat melintasi batas warmup-plateau, sehingga verdict bergantung pada letak jendela plateau terhadap gelombang.
- **Yang ditunggu database adalah flush WAL, bukan kunci baris.** Wait event backend aktif: `LWLock:WALWrite` dan `IO:WalSync` (Rust 199/89 sampel, Hono 151/74), tanpa satu pun kunci yang belum diberikan. Kolom `waiting` di laporan lama menjumlahkan Lock dan LWLock, jadi tidak membedakan keduanya.
- **Disk dev1 lambat untuk fsync.** `pg_test_fsync` di volume data: `fdatasync` 180 operasi per detik, 5,6 ms per operasi. Setiap request melakukan setidaknya satu commit karena sesi disimpan setiap request (sama dengan Laravel), dan setiap commit memegang satu dari 8 koneksi selama flush. Di puncak gelombang kedelapan koneksi habis menunggu disk, lalu request baca ikut antre di pool. Ini batas bersama semua stack di dev1, bukan sifat runtime.
- **Dugaan distribusi `reusePort` Bun tidak merata terbantah.** Koneksi HTTP terbagi 397/403 dan CPU kedua proses naik-turun bersama. Di puncak gelombang tiap proses Bun memakai 77-92% satu core; Bun lebih cepat jenuh karena CPU app per request paling tinggi di antara kandidat native (6,2 ms).

`load.py` kini mencatat `wal_write` dan `wal_sync` di penghitung run, dan `probe.py` mencatat laju commit serta fsync per detik.

Keputusan pengguna 4 November: awal siklus disebar sepanjang satu siklus. `profile.json` kini Uniform(0,60) (12 aksi × jeda rata-rata 5 detik) dan warmup 75 detik; `load.py` mengambil nilai bawaannya dari `profile.json`. Laporan run memuat `plateau_rates`: laju baca/tulis per 10 detik di plateau dan koefisien variasinya. Pada `r7-rust-1000` CV laju tulis 0,67 (8,8 sampai 84 per detik). Hasil ramp r1 sampai r7 memakai profil lama dan tidak dicampur dengan hasil profil baru.

Keputusan pengguna 4 November, diteruskan lewat sesi "Perbedaan disk write dev 1 dan dev 2" lalu dikonfirmasi langsung: cara menaikkan batas VU berikut diuji A/B **sesudah** ramp r8 dan lima ulangan selesai. Cara yang terbukti menaikkan batas dengan gate kebenaran tetap bersih (0 lintas tenant, 0 nomor ganda, 0 eskalasi hak, 0 error 5xx, oracle bersih) **wajib** dipakai pada refactor ke stack terpilih.

1. Cache konteks permintaan per tenant + user + nomor versi, dibuang saat role, izin, atau keanggotaan berubah; tanpa state per permintaan yang tertinggal di worker yang hidup lama.
2. Role rekursif diratakan menjadi tabel izin jadi yang diperbarui saat role berubah.
3. Sesi di Valkey, bukan Postgres.
4. PgBouncer transaction pooling.
5. FrankenPHP worker untuk varian PHP (runtime PHP uji sudah Octane/FrankenPHP).
6. `synchronous_commit = off` lewat `SET LOCAL` hanya untuk tulisan tidak penting (sesi, log aktivitas); posting, penomoran, dan stok tetap on.
7. Setelan dasar Postgres (sebagian sudah: shared_buffers 1 GB dari 4 GB, max_wal_size 1 GB).

Dilarang: `fsync=off`, `full_page_writes=off`, unlogged table untuk data penting. Butir 1, 2, 3, 4, dan 6 mengubah perilaku yang dijaga gate kesetaraan, jadi diterapkan ke PHP juga agar perbandingan tetap adil. Urutan uji mengikuti bottleneck yang terbukti: 6 dan 3 dulu (menghapus fsync per request), lalu 1 dan 2 bila CPU menjadi limiter berikutnya.

## Gangguan I/O di dev1 dan perubahan di luar folder eksperimen, 4 November

dev1 dipakai bersama. Job backup milik rekan pengguna (root, `/usr/local/libexec/metta-backup/registry-drill.py` dari sesi SSH, plus timer `metta-*`) membaca disk 18-220 MB/s selama `r8n-go-900` dan `r8n-rust-1000`. Database run sendiri hampir tidak membaca disk. Penanganannya:

- `load_report.py`: `host_io` dari vmstat plateau. Gagal latensi saat baca disk host rata-rata di atas 5 MiB/s berarti `INCONCLUSIVE_HOST_IO`; pelanggaran kebenaran tetap FAIL. `r8n-go-900` dan `r8n-rust-1000` kini INCONCLUSIVE_HOST_IO dan diukur ulang di mode sesi.
- `load.py` menunggu baca disk host di bawah 5 MiB/s selama 30 detik sebelum start (paling lama 20 menit). `ramp.py` dan `repeat.py` mengulang run INCONCLUSIVE_HOST_IO sampai dua kali dengan clone baru.

**Perubahan di dev1 di luar folder eksperimen, wajib dibereskan sesudah pengujian:**

- Atas keputusan pengguna, batas **baca** runtime 4 MB/s di `/dev/vda` untuk `user-0.slice` dan unit `metta-native-upload`, `metta-native-pg-backup`, `metta-native-config-backup`, `metta-jowhost-backup-metrics`. Pasang/cabut lewat `~/coreerp-stack-comparison/io-limit.sh apply|revert`. Pencabutan otomatis terjadwal pukul 22:00 WIB lewat `coreerp-io-limit-revert.timer` (transient). Cabut lebih awal begitu pengujian selesai.
- Batas **tulis** 4 MB/s sempat dipasang lalu dicabut pukul 09:10 WIB. Halaman kotor dari drill menumpuk (Dirty 103 MB, Writeback 120 MB) karena writeback cgroup ikut tertahan, dan batas dirty global lalu menahan proses lain: `docker rm` tersangkut lebih dari satu menit, iowait 35% pada util disk 8%. Sesudah dicabut, Dirty turun ke 12 MB dan Writeback ke 0 dalam lima detik. Jangan membatasi tulis lewat `io.max` tanpa batas memori cgroup yang sama. Sebagai gantinya, penjaga `host_io` dan penantian sebelum run juga memeriksa tulis host (> 12 MB/s rata-rata; run sendiri 3-7 MB/s).
- `/root/AGENTS.md` dan `/root/CLAUDE.md`: catatan sementara untuk agent rekan pengguna (apa yang berjalan, cara mengecek, batas I/O, cara mencabut). Hapus sesudah pengujian.
- Scheduler disk dev1 `mq-deadline`, jadi `ionice`/`IOWeight` tidak berefek; hanya `io.max` yang membatasi.

## Correctness selesai di dev1, 3 November 20:46 WITA

Cluster uji dev1 berjalan dari compose eksperimen (`coreerp-stack-comparison`, port loopback 18640/18641). `runtime.env` di dev1 sudah LF dan `PHP_IMAGE` menunjuk ID dev1. Evidence dev1 berawalan `d1-` (PHP dan Go/Hono/Elysia) serta `d2-` (Rust sesudah perbaikan reset).

- PHP dev1 identik dengan PHP laptop: baseline 760 hash baris, HTTP golden, kewajiban SQL, conformance, dan urutan login.
- Go, Hono, Elysia, dan Rust VERIFIED pada golden lima gate (HTTP, oracle rekonsiliasi, graph posting, kewajiban SQL termasuk login), conformance 48 probe + 6 throttle, dan urutan login per transaksi.
- Rust kini mereset koneksi seperti `ResetDatabaseConnections` PHP; `ROLLBACK; RESET ALL` hilang. Satu-satunya ROLLBACK tersisa ada di jalur error.
- Skrip run berurutan: `~/coreerp-stack-comparison/run-gates.sh` dan `run-rust.sh`. Jalankan terlepas (`setsid nohup ... &`) karena sesi SSH panjang bisa menggantung.

Sisa sebelum angka kapasitas: load runner pengguna, fixture 128 tenant × 8 akun, budget pool/CPU/RAM yang setara (pool sekarang Go 10, Bun 10, Rust 8, PHP 4 worker Octane), lalu ramp per stack di dev1, dev2, dan `dev-windows1`.

## Pindah ke dev1, 3 November malam

Atas permintaan pengguna (RAM laptop habis), pekerjaan pindah ke dev1. Lokasinya `/home/claude/coreerp-stack-comparison/CoreERP` (folder 0700), branch `codex/native-stack-comparison`. Repo itu snapshot satu commit, karena checkout laptop shallow dan bundle tidak bisa di-clone. Hash tree-nya `754d6c0491e58fbfb63167f118641c0487172e6b`, sama dengan commit laptop `e646a411`. Commit sesudahnya dikerjakan di dev1. Push ke GitHub belum pernah dilakukan.

- `private/` (391 berkas, termasuk `runtime.env`, `seed.dump` `2a8145dc…`, dan semua evidence) tersalin dengan izin 0700/0600 dan sha256 per berkas cocok.
- Image PHP `coreerp-pf01-test:local` dimuat ke dev1. ID-nya `b601344b…` karena Docker 28 memakai digest config, sedangkan Docker Desktop memakai `25260e58…` (digest manifest). Diff-ID ke-30 layer, config, dan env identik. **`PHP_IMAGE` di `runtime.env` masih ID laptop; ganti ke ID dev1 sebelum `lab.py`.**
- Volume `coreerp-pf01-vendor` dibuat di dev1, 17.487 berkas dengan manifest sha256 sama.
- Database belum dibuat di dev1. Buat cluster baru lewat compose dan restore dari `seed.dump`; jangan memakai cluster SaaS dev.
- Di laptop, container, jaringan, dan volume eksperimen (`coreerp-stack-comparison-database`, `-source`, `coreerp-native-rust-build`, `-cargo`) sudah dihapus atas permintaan pengguna. Volume `coreerp-pf01-*` milik pekerjaan lain dibiarkan.
- Di laptop, uji dijalankan satu stack bergantian. Di VPS paralel boleh.

Keadaan verifikasi limiter login saat pindah:
- Limiter Go, Bun, dan Rust sudah disamakan dengan `login-limiter.md` (commit `cf42173d`, `25a2a9d7`, `1df3187a`).
- `login_sequence.py` VERIFIED untuk Go, Hono, dan Elysia terhadap PHP l4 (`private/l4-*-login-compare.json`).
- Conformance lengkap Go (48 probe + 6 throttle) VERIFIED (`private/c3-go-conformance.json`).
- Golden Go g3 gagal karena observer kehilangan fingerprint (`pg_stat_statements.max` 5000 penuh oleh puluhan clone), bukan karena Go. `golden.py` kini me-reset statistik sebelum alur.
- Belum dijalankan dengan kode baru: golden g3 dan conformance c3 Hono/Elysia, image Rust baru, serta seluruh gate Rust.

## Lanjutan 3 November, 16:40 sampai 17:06 WITA

Bagian ini menggantikan status pada bagian di bawahnya bila bertentangan. Bukti per baris ada di `.audit/native-stack/decisions.tsv`.

- **Checkpoint.** Harness, patch Go, Rust `06a711b8` (cherry-pick `d5d7d78b`), dan checker posting sudah ber-commit lokal di `codex/native-stack-comparison`. Tidak ada push. Berkas `private/` tidak pernah di-stage.
- **PostgreSQL uji.** Recovery selesai normal dari WAL. Kedua belas database terbaca penuh lewat `pg_dump`. Index tidak diperiksa dan data checksums mati. Service database kini memakai `stop_grace_period: 120s`.
- **P1 rekonsiliasi ditutup.** `sql_oracle.py check` wajib menerima `--acknowledgements` dan `--baseline private/seed-baseline.json`. Setiap entri ledger diikat ke akun dan `receipt_body` fixture. Baris tahan lama baru tanpa pemilik yang diakui, baris seed yang berubah, atau ledger tanpa completion baru menjadi merah. Sembilan probe kerusakan merah semua pada golden PHP.
- **Golden ulang lulus.** PHP, Go, Hono, Elysia, dan Rust lulus HTTP differential, oracle rekonsiliasi, dan checker posting pada clone `erp_compare_g2_*` / `erp_compare_ts_g2*` dengan run-id `golden-20261103`. Kandidat dijalankan dari image hasil `git archive` commit `936498de` lewat `scripts/golden.py`. Container PHP uji mati sebelum dan sesudah setiap alur native. Image Go dan Bun tidak berisi berkas PHP, image Rust hanya satu binary. Ini juga bukti PHP-off cold start dari snapshot SQL untuk profil utama.
- **Elysia INCONCLUSIVE sekali.** Percobaan pertama: completion ter-commit, driver tidak menerima respons, log hilang. Tiga Elysia dan tiga Hono ulang lulus. `golden.py` kini menyimpan stderr dan log container.
- **Docker Desktop berhenti pukul 17:04:52** saat container Go conformance dibuat. Log backend putus tanpa pesan shutdown dan tidak ada event Windows. Pengguna menyetujui start ulang pukul 17:09. Recovery PostgreSQL uji normal lagi dan 30 database terbaca.
- **Conformance bersama lulus.** `scripts/conformance.py` menjalankan 31 probe HTTP Go, 12 probe pencabutan akses, dan 5 probe rollback (INSERT posting dipaksa gagal lewat constraint sementara). Keempat kandidat 0 selisih terhadap acuan PHP `private/c2-php-conformance.json`. Dua probe lama ternyata cacat dan sudah diperbaiki: sesi -7.201 detik jatuh di batas detik karena `::integer` membulatkan, dan header `Idempotent-Replayed` dicari peka huruf.
- **Kewajiban SQL.** `scripts/compare_queries.py` membandingkan trace golden per aksi: baris tertulis per tabel, kunci baris, tabel dibaca sesi terautentikasi, batas transaksi, dan kerja trigger. Sesi steady (workspace sampai asset-show) VERIFIED untuk keempat kandidat dengan register `query-obligations.json`. Strategi query tetap berbeda, jadi atribusi selisih ke runtime murni INCONCLUSIVE.
- **Login belum setara (NOT VERIFIED).** Status throttle 5×422 lalu 429 sama di semua stack. PHP (Fortify, cache database) menulis tiga baris cache dalam transaksi dengan `FOR UPDATE`. Hono/Elysia menulis satu baris upsert, Rust tanpa transaksi dan kunci, Rust tanpa `set_config` pada GET /login. Header `X-RateLimit-Limit/Remaining` tidak ada di Go dan Rust, `X-RateLimit-Reset` tidak ada di Hono/Elysia. Angka cold-login tidak boleh dibandingkan sampai ini ditutup.
- **Tuning sebelum benchmark.** Rust mengirim `ROLLBACK; RESET ALL` saat koneksi kembali dan diambil dari pool (`native/rust/src/main.rs:42`, `:150`): dua round trip tambahan dan dua WARNING log PostgreSQL per request. Pool belum setara: Go 10, Bun 10, Rust 8, PHP 4 worker Octane.

Lanjutkan dari sini: putuskan apakah login ditutup dulu, lakukan tuning yang sepadan dan catat budget-nya, lalu bangun load runner (langkah 7 di bawah).

## Mandat pengguna

Bangun pembanding Bun + Hono + oRPC OpenAPI, Bun + Elysia + oRPC OpenAPI, Go, dan Rust, dengan PHP existing sebagai baseline. Alur bisnis, aturan, query, transaksi, audit, penomoran, serta hasil tersimpan harus setara. Empat kandidat menjalankan auth sampai readback secara native tanpa memanggil PHP. Mulai dengan correctness lokal, baru uji terisolasi di dev1/dev2. Windows ditunda. Analytics tetap hold.

Beban yang diminta menyerupai aktivitas pengguna: klik/request lalu jeda acak dengan mean sekitar lima detik, start tersebar. Cari batas VU yang masih memenuhi SLO. Jangan mengklaim maksimum RPS atau seluruh kapasitas ERP dari satu profil ini.

## Checkout dan commit

Checkout integrasi utama:

`C:/Users/Msi_/.codex/worktrees/native-stack-comparison/CoreERP`

Branch `codex/native-stack-comparison`. HEAD sebelum dokumen ini `6541da1c`. Baseline source dan fixture dipaku pada origin/main `6b3cf5846fd4442c3e895cacf2ba58275f9dd75e`, yaitu main yang di-fetch saat eksperimen dimulai. Jangan mengganti baseline diam-diam jika main sudah bergerak; perubahan baseline memerlukan regenerasi fixture dan pengulangan bukti.

Commit yang sudah terintegrasi:

| Commit integrasi | Isi |
| --- | --- |
| `00bc0e48` | Fixture PHP, dari author commit `2fb85fdd2b62e0aff84e451e2648f95501630c5d`. |
| `d4660178` | Native Go, dari `21462da09a2bf062a45a17bcde3822b5239937ed`. |
| `6541da1c` | Kedua Bun, dari `c39ff54338201e718690212c83ef77ef60e63062`. |

Perubahan parent yang belum di-commit mencakup `.gitignore` eksperimen, `infra/compose.yaml`, `profile.json`, `scripts/`, `tests/test_harness.py`, dan perbaikan `native/go/posting.go`. Jangan membersihkan atau menimpanya. `.audit/native-stack/` juga belum di-commit.

Rust belum di-cherry-pick. Commit siap `06a711b847f3bb22db64b69815a9a776baab80c4`, branch `codex/stack-native-rust`, worktree bersih di `C:/Users/Msi_/.codex/worktrees/stack-native-rust/CoreERP`. Hanya `experiments/stack-comparison/native/rust/**` yang berubah.

Checker posting selesai tetapi BELUM di-commit dan BELUM disalin ke integrasi. Lokasinya di worktree `C:/Users/Msi_/.codex/worktrees/stack-php-baseline/CoreERP`, branch `codex/stack-posting-checker`, berbasis `6541da1c`:

- `experiments/stack-comparison/scripts/compare_posting.py`
- `experiments/stack-comparison/tests/test_posting_compare.py`

Evidence checker berada pada `experiments/stack-comparison/private/` di worktree tersebut. Ambil hanya dua file kode tadi; jangan stage folder private. Jangan menyentuh perubahan sesi lain pada `D:/Kerja/CoreERP`, termasuk branch sandbox-copy.

## Profil bisnis yang sudah dibangun

Profil `pending-purchase-v1` pada `profile.json` menjalankan receipt-list, create, show draft, PATCH penggantian seluruh detail, preview, complete, show completed, generated assets, asset-list, dan show ketiga aset. Totalnya 12 request bisnis, setelah GET login, POST login, dan pemilihan workspace melalui HTTP nyata.

Fixture lokal berisi dua tenant, delapan akun utama, dan enam akun probe. Purchase memakai vendor, hierarchy manajemen terpublikasi dan BU, mapping akun aktif, tiga unit seharga `333333.333`, pajak per unit `33333.333`, buku current dengan penyusutan garis lurus 48 bulan, serta atribut decimal wajib. Posting harus `pending`, bukan fixture murah `held`.

Aturan aktual PHP penting:

- Nomor header commit sebelum transaksi header. Nomor aset berada dalam transaksi completion bersama aset, placement, book, ledger trigger, atribut, dan posting.
- PATCH mengganti detail secara fisik sesuai source aktual. Tidak ada endpoint add-lines.
- Create replay terjadi sebelum validasi dan tidak membandingkan hash payload. Body berubah tetap replay 200 dengan raw header.
- Completion ulang 422. Kalah claim versi dapat 409. Claim dan UPDATE status menyebabkan version naik dua kali. Alur utama menghasilkan versi 1, 3, 5.
- Sesi Laravel source ini JSON/base64, cookie `laravel-session` dan `XSRF-TOKEN`, AES-256-CBC. Login tanpa 2FA tetap memverifikasi bcrypt dua kali. Cost fixture 12 tidak diturunkan.
- Setiap pemulihan user memasang actor audit. INSERT book memicu ledger SQL. Jangan mengabaikan middleware, shared search/cursor, permission chain, org policy, atau trigger.
- Normal receipt tidak memulai approval workflow atau generic outbox. Feed finance diterbitkan sinkron dalam transaksi.

Ini profil terpilih, bukan parity seluruh ERP. Lampiran nonempty, linked purchase request, grant, opening balance, cancellation, serta beberapa mode numbering/auth/environment belum dicakup. Scope masing-masing native ada pada README-nya.

## Yang sudah terbukti

- PHP baseline menjalankan source Git aktual. Attestation 1.246 file menghasilkan nol mismatch, nol extra source, dan nol dependency-version mismatch terhadap lock. Evidence `private/source-attestation.json`.
- PHP dan empat kandidat telah menyelesaikan 12 request bisnis native pada fixture masing-masing. Ini smoke/correctness, bukan capacity test.
- Oracle SQL non-PHP `scripts/sql_oracle.py` lulus 24 pemeriksaan pada profil utama. Self-test mengubah satu asset satu sen di dalam transaksi; tiga pemeriksaan merah dan rollback memulihkan kondisi bersih. Evidence `private/oracle-selftest.json`.
- PHP versus Go dan Rust pernah menghasilkan nol perbedaan respons pada clone bersih. Dua Bun sempat berbeda pada 18 field tanggal karena `.000000Z`; source Bun sudah diperbaiki dan re-read 18 field per adapter cocok.
- PHP menjalankan 31 probe HTTP conformance yang sama dengan Go dan lulus. Native Go juga pernah lulus 31 probe; Bun 28 probe; Rust 26 probe ditambah 11 probe auth/revocation. Perhatikan batas versi binary pada bagian berikut.
- Trace PHP completion tercatat 141 top-level dan 196 nested statements; Go 148 dan 196. Ini satu trace lokal, bukan angka performa. Jangan menjumlah durasi parent dan nested atau menyebut semuanya round trip.
- Checker posting baru membaca full stored input/payload/hash/accounts/lines/events. PHP, Bun, dan Rust lama lolos. Ia menemukan enam selisih input Go yang tidak terlihat di HTTP. Tujuh probe korupsi rollback telah dibuktikan merah dan pulih; enam unit test checker lulus sebelum serah terima.

## Bukti yang belum boleh dianggap final

Review independen di `.audit/native-stack/review-native-go-harness.md` menemukan lima gap. Tiga P2 pada comparator/observer sudah diperbaiki parent dan delapan regression test harness lulus: identity source/profile/fixture/run dan auth harus cocok; seluruh urutan 12 aksi wajib ada; timestamp readback harus stabil; fingerprint statistik yang hilang wajib gagal.

ARTIFACT HTTP LAMA BELUM MEMILIKI SEMUA METADATA BARU. Jangan menambahkan metadata secara manual agar artifact lama hijau. Jalankan ulang actual flow memakai driver sekarang dan clone baru. Jangan menyebut verdict lama sebagai kelulusan checker terbaru.

P1 yang masih terbuka adalah rekonsiliasi setiap acknowledgement workload dengan input fixture dan transaksi durable. Oracle 24-check lama menghitung data tersimpan tetapi belum mengikat semua receipt/amount/actor ke setiap request atau setiap VU. Beban yang tidak melakukan pekerjaan baru tidak boleh lolos karena seed sudah memiliki satu completed receipt.

P1 stored posting ditangani checker baru yang masih berada di worktree lain. Input hash harus exact; source hash tidak memasukkan generated receipt/asset IDs atau timestamps. Mapping account yang salah dengan jumlah tetap seimbang juga harus gagal.

Perbaikan Go parent BELUM di-commit pada `native/go/posting.go`: simpan original input tanpa tambahan `line_no`, pertahankan zero input sebagai `"0"`, normalisasi pada salinan, dan ambil published_at dari payload. `go test` dan `go vet` selesai exit 0 setelah patch. Binary HTTP/full graph belum diulang untuk patch ini.

Rust commit terbaru memperbaiki occurred_at/published_at agar SQL mengambil waktu dari input/payload, bukan sampling ulang. Bug lama dan perbaikannya dibuktikan dengan account-table lock pada DB development. Tujuh release unit test lulus. Golden Rust lama dan graph-nya berasal dari SEBELUM fix tersebut. Image corrected `coreerp-native-rust:local` berhasil dibangun dengan ID `sha256:c11295b3e8cdc5bda02780c04b62783344eb9b71199fb24857a65525e40824a1`, tetapi packaged image belum runtime-smoked dan belum mendapat golden flow baru.

Belum dilakukan: PHP-off cold-bootstrap smoke empat kandidat; rekonsiliasi query obligations lengkap termasuk failure/rollback; benchmark lokal; load runner manusia; lima repeat; profiling bottleneck; benchmark dev1/dev2. Tidak ada kesimpulan siapa tercepat atau apakah rebuild sudah layak diadopsi.

## Artifact privat dan runtime yang dihentikan

Folder privat utama `experiments/stack-comparison/private/` pada checkout integrasi. `runtime.env` berisi secret fixture sintetis. Jangan cat/read ke chat, commit, atau log. `fixture.json` memuat ID/payload; `seed.dump` adalah snapshot schema dan data sintetis bersama, mengecualikan sesi/cache.

SHA256 seed: `2a8145dc71dc90722d8d2fa489ebd4710839a87a655c2c46ee4c6f2cf5975cd2`.

Evidence utama adalah `php-final-flow.json`, `go-final-flow.json`, `hono-final-flow.json`, `elysia-final-flow.json`, `rust-final-flow.json`, pasangan `*-oracle.json`/`*-differences.json`, dan folder trace `php-final-traces`/`go-final-traces`. Batas versi di atas tetap berlaku. Evidence Bun tambahan ada di worktree `stack-native-ts/CoreERP/experiments/stack-comparison/native/typescript/private/`. Evidence Rust timestamp ada di worktree Rust `native/rust/private/timestamp-before.json` dan `timestamp-after.json`.

Docker project milik eksperimen `coreerp-stack-comparison` sudah STOP. Named volumes `coreerp-stack-comparison-source` dan `coreerp-stack-comparison-database` dipertahankan. Dependency volume `coreerp-pf01-vendor` external, jangan hapus. Containers PHP/tools/database bukan layanan ERP bersama.

PENTING: stop terakhir PostgreSQL keluar 137, `OOMKilled=false`. Log menunjukkan fast shutdown dan checkpoint shutdown mulai tetapi tidak menunjukkan selesai sebelum container berhenti. Jangan mengklaim clean shutdown atau langsung menganggap live volume konsisten. Tidak direstart setelah pengguna meminta stop. Saat melanjutkan, periksa recovery dan kesehatan cluster; seed dump serta evidence host tetap ada. Perbaiki grace period stop default menjadi cukup panjang, misalnya 120 detik, hanya pada project uji ini sebelum shutdown berikutnya.

Database yang dipertahankan: `erp_compare`, `_ts`, `_go`, `_rust`, `erp_compare_final_php`, `_final_go`, `_final_hono`, `_final_elysia`, `_final_rust`, serta `erp_compare_posting_probe`. DB development berisi smoke/probe dan bukan seed bersih. DB Rust development juga menyimpan reproduksi timestamp lama. Final PHP memiliki receipt conformance tambahan; checker posting memilih creation_key golden yang spesifik.

Listener Go parent PID 50048 sudah dihentikan; session 67170 selesai. Bun ports 18644/18645 sudah dihentikan agent. Semua container Rust owned berhenti, termasuk builder. Image dan volume dipertahankan. Jangan menghentikan stack `erp-*`, CPDB lain, atau service server milik sesi lain.

## Server dan rancangan pengukuran

SSH config otoritatif `C:/Users/Msi_/.ssh/config`. Dev2 dapat diakses dengan `ssh -J dev1 dev2`; koneksi langsung dari laptop timeout saat diperiksa. Dev1 dan dev2 telah diinventarisasi read-only. Tidak ada perubahan layanan atau load test server. Dev1 lebih padat layanan; usulan awal dev2 sebagai API+DB eksperimen dan dev1 generator. Jalur sustained load antarnode belum disiapkan. Dev2 tidak mempunyai interface `wt0` saat diperiksa; jangan mengasumsikan NetBird tersedia atau mengubah jaringan/SSH layanan yang ada.

Semua kandidat harus dibandingkan bergantian pada host/topologi, snapshot awal, total CPU/RAM, pool budget, proxy/TLS/encoding, dan tuning yang sepadan. Dua API replicas dan 100+ tenant adalah target. Semua workload harus memakai sesi nyata; tidak mematikan limiter atau membuat authority palsu.

`profile.json` menetapkan Uniform(1,9) detik antaraksi dan start Uniform(0,10), mean 5 detik; ini simulasi, bukan telemetri manusia. Closed-loop, plateau minimal 90 detik, warmup/drain terpisah, lima repeat dengan urutan Latin-square, refine last-pass/first-fail. Sampel p99 harus cukup; cap generator/resource bukan batas aplikasi.

Target repo dari `docs/dev/20-load-and-concurrency-testing.md`: baca p95 <200 ms/p99 <500 ms; tulis termasuk completion p95 <400 ms/p99 <900 ms. Nol tenant leak, duplikasi nomor/posting, escalation, rollback salah, application 5xx, unexpected delivery failures. Ini target proyek, bukan SLA Microsoft.

Business Central Performance Toolkit mendukung concurrent sessions, random min/max user delay, perbandingan baseline dan jumlah SQL. Pada halaman yang diperiksa tidak ditemukan satu SLO p95/p99 universal. Referensi:

- https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/devenv-performance-toolkit
- https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/performance/performance-developer

## Urutan melanjutkan

1. Baca AGENTS dan skills poteto-mode, architecture, runtime-operations, code-formatting, benchmark-checklist. Periksa git status seluruh worktree terkait sebelum menyimpan pekerjaan. Jangan buang untracked files.
2. Amankan checkpoint lokal dengan staging allowlist tanpa private/.audit secrets. Integrasikan Rust commit dan dua checker file yang disebutkan, tanpa memasukkan perubahan sesi lain.
3. Periksa recovery cluster uji yang terakhir exit 137. Jangan restart DB bersama atau menghapus volume. Restore seed ke DB baru jika diperlukan; `lab.py clone` membuat DB baru dan menolak menimpa yang sudah ada.
4. Selesaikan P1 acknowledgement/input/actor reconciliation dan review checker posting. Jangan menjalankan benchmark ketika checker masih dapat meloloskan pekerjaan kosong atau input salah.
5. Buat clone baru untuk kelima kandidat. Gunakan driver terbaru, run-id sama `golden-20261103`, auth nyata, kedua oracle dan full stored posting graph. Ulangi pada binary Go patched, Bun date-fixed, dan Rust corrected image. Jangan menimpa artifact lama.
6. Jalankan conformance negatif/race yang sama pada PHP dan kandidat; account/role/scope revocation, session/cookie/CSRF, versi, money, search/cursor, actor pool serta rollback. Matikan runtime PHP uji, cold-start keempat kandidat dari snapshot SQL, buktikan login sampai complete tanpa PHP.
7. Baru bangun load runner dan topology eksperimen dev1/dev2. Buat fixture baru 128 tenant x 8 pengguna agar 1.024 akun nyata tersedia; existing local fixture users_per_tenant=4 tidak boleh diubah menjadi 8 dengan menyunting JSON. Pisahkan cold login dan steady-session metrics.
8. Ramp hingga SLO gagal, cek generator dan bottleneck, ulangi lima putaran pada titik konfirmasi dengan snapshot baru dan seed jadwal berpasangan. Publikasikan successful throughput, p50/p95/p99 per aksi, cycle time dengan/tanpa think time, resource, SQL/locks/pool, oracle dan variasi. Kesimpulan runtime murni tetap INCONCLUSIVE bila strategi query berbeda.
9. Review source, hasil dan decision trail. Tidak merge/deploy atau memilih stack final tanpa bukti dan instruksi pengguna.

Perintah lokal yang tersedia setelah memastikan database/runtime siap:

```powershell
python experiments/stack-comparison/scripts/lab.py clone --database erp_compare_fresh_case
python experiments/stack-comparison/scripts/http_baseline.py --base <URL> --fixture experiments/stack-comparison/private/fixture.json --env experiments/stack-comparison/private/runtime.env --output <private-new-flow.json> --run-id golden-20261103 --trace-database <owned-db> --trace-directory <private-new-traces>
python experiments/stack-comparison/scripts/compare_http.py --expected <new-php-flow.json> --actual <new-native-flow.json> --output <private-differences.json>
python experiments/stack-comparison/scripts/sql_oracle.py check --fixture experiments/stack-comparison/private/fixture.json --database <owned-db> --baseline experiments/stack-comparison/private/seed-baseline.json --acknowledgements <private-new-flow.json> --output <private-oracle.json>
python experiments/stack-comparison/scripts/golden.py --candidate <go|hono|elysia|rust> --database <fresh-clone> --expected <php-flow.json> --expected-database <php-db> --prefix <private-prefix> --run-id golden-20261103
python experiments/stack-comparison/scripts/conformance.py --base <URL> --database <fresh-clone> --expected experiments/stack-comparison/private/c1-php-conformance.json --output <private-conformance.json>
```

`seed-baseline.json` direkam sekali dari clone seed yang belum disentuh (`sql_oracle.py baseline --database erp_compare_seed_reference --seed private/seed.dump`). Rekam ulang bila seed berubah.

Parent driver/harness tests terakhir delapan test lulus. Checker posting memakai CLI `--expected-database`, `--actual-database`, `--expected-http`, `--actual-http`, `--output`. Jalankan dari lingkungan yang dapat mengimpor script observer, atau setelah dua file checker diintegrasikan.

Semua dokumen grounding, dua desain, verdict same-model, review dan keputusan tersedia di `.audit/native-stack/`. Beberapa row awal timestamp decision trail diakui sebagai perkiraan; koreksi append-only sudah dicatat. Jangan mengubahnya menjadi kronologi presisi yang tidak dibuktikan.
