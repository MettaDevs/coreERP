# Handover pembanding stack CoreERP

Dicatat 3 November 2026, sekitar 16:22 WITA. Pengguna meminta berhenti dan menyerahkan pekerjaan kepada agent lain. Semua agent, listener uji, dan build milik eksperimen sudah dihentikan. Belum ada benchmark server, keputusan pemenang, merge, push, atau deploy aplikasi aktif.

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
