# Rancangan A: katalog SQL bersama, domain native

Status: sketsa untuk sintesis, 3 November 2026. Baseline tetap source PHP 6b3cf5846fd4442c3e895cacf2ba58275f9dd75e. Tidak ada aplikasi, database, atau server dijalankan oleh perancang ini.

## Masalah

Bandingkan satu kemampuan bisnis lengkap: login dan workspace, lookup, create receipt, PATCH seluruh detail untuk menambah baris, preview, completion dengan acquisition posting pending, lalu receipt/generated-assets/asset readback. PHP menjalankan handler sungguhan; empat kandidat menjalankan keputusan domain sendiri. Kedua Bun memakai oRPC OpenAPI melalui Hono dan Elysia, dengan HTTP/JSON, path, cookie, status, dan header yang sama dengan PHP, bukan envelope RPC tambahan.
Grounding berasal dari grounding-write.md dan grounding-auth-read.md. Peta source tersebut belum menjadi bukti runtime. VERIFIED pada sketsa ini hanya HEAD worktree dan keberadaan dokumen; jumlah query, respons aktual, correctness, kapasitas, dependency hash, dan konfigurasi efektif masih UNKNOWN. Catatan grounding lama yang menyebut tanpa oRPC digantikan keputusan terbaru di atas.

## Usage: pandangan pemanggil

Contoh berikut pseudocode kontrak, belum API yang tersedia. Adapter mengubah input HTTP menjadi command domain dan hasil domain menjadi respons PHP yang sesuai.
~~~text
created = receipts.create(requestContext, parsedCreate)
saved = receipts.replace(requestContext, created.receiptId, parsedVersion, fullHeaderAndDetails)
completed = receipts.complete(requestContext, saved.receiptId, saved.version)
shown = receipts.show(requestContext, completed.receiptId)
generated = receipts.generatedAssets(requestContext, completed.receiptId)
~~~
Hono dan Elysia memasang handler OpenAPI oRPC yang sama di atas satu pustaka domain TypeScript. Go dan Rust menyediakan facade domain yang setara dan adapter HTTP sendiri. Harness mengirim skenario HTTP yang sama ke kelima alamat, tanpa memanggil facade secara langsung dan tanpa membawa cookie PHP ke kandidat.

## Shape

| Bagian di experiments/stack-comparison | Tanggung jawab |
| --- | --- |
| contract/ | Manifest kasus, request, status/header, normalisasi sempit, serta seed dan konfigurasi fixture yang sama. |
| sql/catalog/ | SQL parameterized per leaf beserta owner, central/business connection, bind/result types, lock, dan keluarga fingerprint PHP. |
| native/ts, native/go, native/rust | Auth/workspace/access, service Core, dan receipt domain native; masing-masing memiliki keputusan dan transaksi sendiri. |
| transports/ | Hono+oRPC OpenAPI, Elysia+oRPC OpenAPI, serta adapter Go/Rust; parsing dan pemetaan respons saja. |
| harness/ | Driver HTTP, SQL observer, differential comparison, oracle read-only, reset snapshot, dan laporan. |

Katalog adalah sumber bersama bagi bentuk query, bukan urutan eksekusi. Setiap entry adalah query yang benar-benar diperlukan saat itu; bind berasal dari request dan state hasil query terbaru. Tidak ada replay daftar SQL hasil tangkapan, batch skrip yang mengandaikan jawaban, atau procedure baru yang memindahkan workflow ke PostgreSQL. Trigger asli tetap dipertahankan.
Manifest katalog menghasilkan accessor typed per owner; receipt tidak menerima executor SQL bebas untuk tabel Core. Core numbering/posting memiliki writer sendiri dan dipanggil lewat fungsi native sebagaimana batas source PHP. Ini menjaga satu pemilik per invariant tanpa membuat jaringan service baru.
Facade receipt menyembunyikan authorization, validasi, currency math, transaction claim, numbering, books, attributes, attachment flow, posting, dan dekorasi readback. Pemanggil hanya memberi command domain dan context tepercaya; tidak menyusun tahap persist sendiri. Per boundary-discipline, input HTTP diperiksa sekali; per model-the-domain, money dan version tidak menjadi string/number bebas di inti.

~~~text
opaque TenantId, CompanyId, MembershipId, ReceiptId, ReceiptVersion, IssueKey
opaque AuthorizedContext // Dibentuk guard per request; body/header tidak dapat membuatnya.
ExactDecimal = { unscaled: BigInteger, scale: UInt }
ReceiptState = Draft | Completed | Cancelled
CreateOutcome = Created(DecoratedReceipt) | Replayed(RawReceiptHeader)
interface ReceiptWorkflow {
  create(ctx: AuthorizedContext, input: CreateReceipt): CreateOutcome = not implemented
  replace(ctx, id: ReceiptId, version: ReceiptVersion, input: FullReceipt): DecoratedReceipt = not implemented
  complete(ctx, id: ReceiptId, version: ReceiptVersion): ReceiptShown = not implemented
  show(ctx, id: ReceiptId): ReceiptShown = not implemented
  generatedAssets(ctx, id: ReceiptId): GeneratedAssetCollection = not implemented
}
interface NativeCore {
  restoreSession(facts: RequestFacts): AuthorizedContext = not implemented
  issueNumber(ctx, reference, issueKey: IssueKey, contextValues, transaction): IssuedNumber = not implemented
  publishPosting(ctx, journal: ValidJournal, transaction): Posting = not implemented
}
allocateRounded(total: ExactDecimal, units: PositiveInt): ExactDecimal[] = not implemented
buildAcquisitionJournal(facts: ResolvedPostingFacts): ValidJournal = not implemented
~~~
Signatures diterjemahkan idiomatis menjadi Promise di TypeScript, context/error di Go, serta async Result di Rust. Seluruh bodies belum diimplementasikan. Accessor katalog dan resolved facts bersifat internal; objek Hono/Elysia/oRPC, SQL row, dan JSON wire tidak masuk API domain.

## Invariant native yang harus dipertahankan

Auth native menjalankan bcrypt dengan cost fixture yang sama, termasuk dua pemeriksaan yang dicapai Fortify pada fixture tanpa 2FA, limiter, person-provider, rotasi sesi/token, last_login_at, JSON/base64 session store, expiry, cookie encryption/MAC, CSRF, dan logout. Driver sesi/config efektif harus sama; jalur Redis-generation hanya diuji bila driver itu dipilih. Tidak ada sesi jadi, allow-all token, atau bypass middleware.
Setiap request memulihkan membership aktif, tenant alamat, lisensi/module permission, active legal entity, recursive role→duty→privilege→permission, tanggal assignment, dan data policy/hierarchy. Cache hanya selama request sesuai source; pencabutan berlaku pada request berikutnya. Unit aktif bukan tambahan filter seluruh unit yang sudah diizinkan policy. Pada SHA ini policy resolver tidak join active roles: catat dan uji perilaku aktual, jangan membawa perbaikan branch lain.
Create mengeluarkan header number dalam transaksi tersendiri sebelum header save; completion mengeluarkan asset numbers di dalam transaksi completion dengan savepoint/retry yang sama. Counter, preallocation/refill, reusable number, scope/context/fiscal reset, collision, issue dan audit memakai tabel asli; tidak ada counter per proses atau prefix fallback.
Math native memakai decimal tepat, precision dari data, HALF_UP pada extended amount/tax, lalu remainder ke unit pertama. Completion memuat default/book/profile/location, atribut typed/choice dan locks, account mapping, published hierarchy→BU, snapshots, serta publication hash/status. Posting pending benar-benar tersimpan beserta lines/event, bukan status konstan atau HTTP delivery pura-pura.
Transaksi completion mencakup claim/version trigger, status, nomor asset, assets, placements, books/ledger trigger, attributes, inherited attachments, dan finance posting. Actor koneksi dan transaction-local ledger context dipasang pada koneksi yang benar dan dibersihkan saat pool release, termasuk rollback/error; transaction pooling tidak dipakai sebelum kompatibilitasnya terbukti.
Read memakai seluruh lookup/scalar subquery, vendor/party, cancellation summary, posting status, attributes, search RecordSearch, IncrementalList/cursor, tenant/company/policy, soft-delete dan present-field whitelist yang dicapai source. Skenario posted/cancelled dan vendor tidak diganti fixture draft murah. Preview menjalankan blocker dan evaluasi posting sungguhan.

## Snapshot dan batas setup

Engineer fixture membangun satu lingkungan PHP sintetis melalui jalur tepercaya, commit transaksi terluar, lalu mengekspor schema, functions/triggers, settings, reference data, dan fixture rows. Ini pekerjaan sekali untuk membuat artefak pembanding, bukan dependency kandidat saat hidup. Tidak mengambil dump database aktif.
Setiap lane menerima clone dari artefak yang sama, termasuk central/business separation bila profile dedicated dipakai. Prepare kandidat hanya restore PostgreSQL dan membaca fixture lock; image kandidat tidak berisi PHP atau memanggil PHP untuk bootstrap yang diperlukan oleh auth, numbering, atau operasi. Setiap runtime login sendiri lewat HTTP. Baseline PHP memakai source asli yang dipaku dan dependency yang dicocokkan hash/lock sebelum pengujian.
Fixture utama memiliki 100+ tenant sintetis, izin nyata, vendor, direct_payable, feed enabled, hierarchy/BU/department lengkap, account mapping, contextual asset numbers, posted depreciating book/profile, required typed attribute, nilai pecahan dan tax nonzero. Nama/ID/kode/tanggal berasal dari konfigurasi fixture yang tersimpan; setiap VU mempunyai akun dan key namespace sendiri.
Reset dari snapshot bersih sebelum setiap correctness lane dan setiap pengulangan load; pulihkan counter/allocation, session/cache/limiter, audit/change-log, attachments dan data bisnis. Manifest mencatat checksum schema+fixture, runtime/config, seed, dan row counts. Warmup deterministik dilakukan sesudah restore; ukuran data awal dan langkah warmup sama. Tidak membersihkan data di tengah window ukur.

## Kontrak differential dan oracle independen

Driver menjalankan action manifest yang sama terhadap setiap clone. Kasus inti membuktikan create→PATCH→preview→complete→read, pending posting, exact split, dan read/search/pagination. Matriks conformance terpisah menutup grant/opening/clearing, held/manual/zero, preallocation/refill/exhaustion, required/unknown attributes, linked requests, attachments, rollback, dan fixture cancelled untuk read.
Normalizer mempertahankan tipe JSON, decimal string, null versus absent, field whitelist, status, ETag, Location, replay header, error code dan field errors, serta urutan array. Hanya origin dan nilai acak/clock yang terdaftar boleh dipetakan. ULID dipetakan bijektif melalui creation key/source-line/unit dengan validasi format, uniqueness dan referensi; timestamp diperiksa rentang/relasinya. Jangan membuang semua ID/timestamp atau menyortir respons agar diff hilang.
Cursor diperiksa sebagai opaque round-trip dan decoded contract; fixture read memiliki IDs/timestamps tetap untuk ties/null/numeric sort. Untuk data baru, oracle menghitung urutan dari comparator asli dan tie-break ID pada setiap clone; perbedaan ID acak tidak boleh menutupi baris hilang/ganda atau urutan yang salah. Cookie ciphertext tidak dibandingkan literal: flags, rotasi, validasi crypto dan perilaku sesi diperiksa.
Oracle memakai koneksi read-only dan query assertions sendiri, terpisah dari katalog kandidat dan response builder. Setelah setiap langkah, cocokkan header/details/version; draft nol efek completion; asset count dan receipt/detail linkage; placements/books; ledger immutable dan delta; exact money/tax/journal balance; posting deterministic ID/hash/status/problems/snapshots; typed attributes/locks; attachments; issues/counters/audit actor/change-log; serta tidak ada efek tenant lain.
Create replay memulangkan raw header tanpa details, menerima changed-body, dan mencakup archived record; jangan menambah hash check hanya di kandidat. PATCH benar-benar mengganti details dengan DELETE fisik pada SHA ini. Completion ulang 422; race loser 409 atau post-read 422; header mengalahkan body version; malformed/missing 428; stale 409; trigger dapat menaikkan version dua kali. Semua ini kontrak terukur, bukan rancangan perilaku ideal.
Fault/race lane memaksa kegagalan setelah claim, numbering, book trigger, dan sebelum/di posting, lalu memeriksa rollback menyeluruh dan durable header issue yang memang sudah committed. Crash-after-commit/retry, creation race, same-version completion, linked-request overreceipt, serta pool actor/tenant leakage memakai barrier deterministik; dipisahkan dari traffic manusia.
Negative HTTP mencakup password salah/limiter, cookie MAC rusak, session expired/deleted, application account, CSRF salah/hilang tanpa same-origin bypass, foreign tenant/company/unit/id, membership/assignment/role/permission revoke dengan cookie sama, dan receipt-create tanpa asset-create/read. Status akhirnya dipaku dari PHP runtime pada fixture yang sama; gap PHP dicatat, bukan diterima sebagai correctness tanpa penjelasan.

## Semua query ikut dihitung

Collector request dimulai sebelum environment/session middleware dan berakhir setelah session write/response finalization. Observer PHP dipasang sebagai bootstrap eksperimen terpisah tanpa mengubah business source; listener boot tunggal mengambil collector request aktif, bukan menumpuk listener pada Octane. Tangkap central maupun business, environment/host, session read/write/GC, user/audit set_config, permission/policy, validation, number transactions/savepoints, business dan readback.
Client listener saja tidak melihat SQL dalam trigger atau query gagal. Pada clone correctness yang terisolasi, tambahkan PostgreSQL statement/transaction logs dan pg_stat_statements track=all dengan utility tracking, serta auto_explain nested-statements untuk atribusi. Jalankan satu request/barrier terkontrol per pengambilan dengan backend PID/connection-role; selisih counter nested dan log plan harus cocok sebelum mengklaim lengkap. Deferred work saat COMMIT dan rollback ikut rekonsiliasi. Dukungan extension/logging masih UNKNOWN dan harus dibuktikan lokal.
Laporkan terpisah top-level round trips, SQL statements dispatched, nested trigger/function statements, cache hit/lifetime, DB time, lock wait, dan transaction/connection count. Jangan menjumlah durasi parent+nested menjadi total DB time. Fingerprint memetakan keluarga query dan cabang, bukan memaksa alias SQL identik atau mereplay trace. Seluruh query tambahan/dihilangkan harus dijelaskan; no-query shortcut gagal conformance.
Bindings, cookie/token, password dan body tidak masuk laporan. Trace privat memakai fixture sintetis, SQL parameterized/fingerprint, connection-role dan request label. Observer overhead dikalibrasi; trace correctness terpisah dari capacity run, dengan observability ringan yang setara di lima stack. Jika nested SQL belum terhitung, status query-accounting tetap NOT VERIFIED.

## Load yang adil setelah correctness lokal

Semua lima lane harus lolos kontrak HTTP dan oracle lokal sebelum dev1/dev2. Server hanya mendapat compose project, port, volume, database dan resource namespace eksperimen yang diinventarisasi; tidak deploy/mengubah layanan aktif. Placement mengikuti inventory SSH/runtime yang sudah diverifikasi, tanpa menebak IP atau kapasitas. Analytics dan server Windows tetap di luar scope.
Gunakan dua API replicas di balik LB, PostgreSQL asli dan profile tenant yang sama. Samakan total CPU/RAM API, CPU placement, DB/LB/network/storage, total connection budget, keepalive/persistent connection, TLS dan log level. Pin versi/build flags/config; hangatkan JIT/opcache/cache secara setara. Worker/pool/prepared-statement tuning boleh idiomatis dengan cap dan budget tuning yang sama, tanpa mengubah query/authorization semantics.
Profil manusia memakai start jitter dan jeda independen Uniform(1,9) detik, mean 5, seed tersimpan dan sama antar-stack. Login asli dilakukan sekali per VU dengan start tersebar; biaya/query cold-login dilaporkan, lalu steady-session window dinyatakan terpisah. Aksi bisnis tetap serial per pengguna; latensi respons, waktu server aktif seluruh chain, think time, cycle wall time, offered/achieved rate dan active VU dilaporkan terpisah. Ini simulasi, bukan telemetri perilaku manusia.
Ramp sampai SLO pertama gagal, lalu bracket ulang. Gate per aksi: read p95<200/p99<500 ms dan write termasuk completion p95<400/p99<900 ms; nol tenant leak, nomor/posting ganda, authorization escalation, rollback salah dan application 5xx. Chain dilaporkan terpisah; kapasitas menuntut seluruh aksi lulus, tanpa mengarang angka SLO chain. Tahan setiap titik sekurangnya 90 detik setelah warmup stabil; 1000+ VU diuji bila resource mengizinkan, bukan syarat mengabaikan kegagalan lebih awal.
Lima putaran seimbang memakai urutan Latin-square PHP→Hono→Elysia→Go→Rust yang dirotasi tiap putaran. Tiap stack mendapat lima repeat per titik konfirmasi dengan snapshot/seed/placement yang sama; generator terpisah atau headroom-nya dibuktikan. Laporkan semua repeat, median/rentang, failure bracket, dan titik tertinggi yang lolos kelimanya. Host/DB/generator saturation membatasi kesimpulan; throughput di titik jenuh bukan kapasitas SLO. Reverse placement hanya sebagai eksperimen terpisah jika inventory mendukungnya.

## Tradeoff, alternatif, dan sintesis

Kita menerima tiga implementasi domain untuk mempertahankan native execution di tiga bahasa; sharing TypeScript antara kedua Bun mengisolasi perbedaan framework. Katalog mengurangi drift SQL dan mempermudah audit, dengan biaya kandidat belum menguji keunggulan query planner/ORM idiomatis masing-masing bahasa. Kesimpulan hanya berlaku untuk kemampuan dan tuning yang diuji.
Alternatif ORM independen ditolak untuk kandidat A karena bentuk query/locks mudah bergeser dan beban perbandingan melebar, walaupun lebih idiomatis. Alternatif workflow di stored procedure mempunyai API kecil tetapi menyembunyikan seluruh kerja domain dari runtime, sehingga tidak menjawab port native. Replay SQL mempunyai permukaan sederhana namun tidak memutuskan state/permission dari request baru dan tidak memenuhi tugas.
Synthesis decision: menunggu perbandingan parent dengan kandidat B; A belum dinyatakan pemenang. Red-flag screen: satu facade per kemampuan, satu katalog per leaf, writer menurut owner, internal tak diekspor, tanpa wrapper pass-through dan tanpa dua jalur operasi.
Pertanyaan yang dijawab oleh probe implementasi, tanpa checkpoint manusia baru: apakah hashing dependency/vendor cocok; apakah response/date/error serialization sesuai; apakah session/cookie dan driver efektif sama; apakah nested SQL terekonsiliasi; apakah PostgreSQL extension tersedia; dan apakah SLO dipenuhi sebelum bottleneck generator/host? Sampai terbukti, hasil tetap NOT VERIFIED atau INCONCLUSIVE.
Langkah berikut: bekukan fixture lock dari engineer PHP, jalankan satu cycle PHP melalui HTTP beserta full-query trace dan oracle, lalu isi satu implementasi native mengikuti kontrak itu sebelum menambah paralelisme atau beban server.
