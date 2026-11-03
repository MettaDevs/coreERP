# Sketsa B: kontrak perilaku, implementasi query milik tiap stack

## Problem
Eksperimen membandingkan PHP aktual `6b3cf5846fd4442c3e895cacf2ba58275f9dd75e` dengan Bun Hono + oRPC OpenAPI, Bun Elysia + oRPC OpenAPI, Go, dan Rust pada penerimaan aset sampai posting acquisition `pending` dan baca kembali. Grounding sumber: `grounding-write.md`, `grounding-auth-read.md`, dan keputusan terbaru `PLAN.md`; kalimat lama yang meniadakan oRPC tidak berlaku.
Ini desain eksperimen, bukan rencana memindah produk atau hasil pengukuran. Tidak ada database/server diakses untuk sketsa ini. Analytics tetap hold.
Bentuk yang dipilih: satu spesifikasi conformance, empat implementasi native yang memiliki SQL dan logikanya sendiri, schema/trigger PostgreSQL asli, serta pemeriksa independen. Tidak ada katalog SQL executable yang dipanggil bersama semua stack.

## Usage dari pemanggil
Contoh berikut adalah API usulan, belum diimplementasikan; jalur `dev1`/`dev2` baru dapat berjalan setelah attestation lokal semua kandidat lulus.
```ts
const fixture = await prepareProfile({ profile: "pending-purchase", seed: 2911 });
const verified = await verifyAll({ fixture, candidates: allFive, site: "local" });
const local = await compare(verified, { site: "local", repeats: 5, load: "until-slo" });
const servers = await compare(verified, { sites: ["dev1", "dev2"], repeats: 5, load: "until-slo" });
```
Pemanggil tidak mengoordinasikan allocation, posting, audit, atau commit. `verifyAll` menghasilkan `VerifiedPlan` hanya bila kontrak HTTP, efek database, auth, dan audit query lengkap untuk seluruh case yang diwajibkan profile.
Contoh dua pemanggil domain di setiap implementasi:
```ts
const draft = await receipts.execute(workspace, { kind: "Create", key, document });
const completed = await receipts.execute(workspace, { kind: "Complete", id: draft.id, version: draft.version });
const page = await receipts.query(workspace, { kind: "ListAssets", criteria });
```
Adapter oRPC memetakan kontrak HTTP yang sama ke domain; Go/Rust memakai adapter HTTP native, sedangkan PHP tetap controller dan middleware aktual. Tidak ada RPC envelope atau jalur cepat internal untuk load generator.

## Shape dan tipe inti
```ts
type ReceiptCommand =
  | { kind: "Create"; key: CreationKey; document: ReceiptDocument }
  | { kind: "ReplaceLines"; id: ReceiptId; version: RowVersion; document: ReceiptDocument }
  | { kind: "Complete"; id: ReceiptId; version: RowVersion };
type ReceiptQuery = ReceiptShow | ReceiptAssets | AssetShow | ReceiptList | AssetList;
interface ReceiptApplication {
  execute(scope: AuthorizedWorkspace, command: ReceiptCommand): Promise<ReceiptOutcome>;
  query(scope: AuthorizedWorkspace, query: ReceiptQuery): Promise<ReceiptView>;
}
// CreationKey, IDs, RowVersion, DecimalAmount, AuthorizedWorkspace, VerifiedPlan: opaque validated types.
// Bodies: not implemented. Complete owns locks, asset creation, books, attributes, posting, and commit.
```
`AuthorizedWorkspace` hanya dapat dihasilkan komponen security dari sesi yang dipulihkan dan izin terbaru, bukan dari tenant/company dalam payload. `ReceiptDocument` memakai decimal string exact; nominal tidak melewati binary float. Discriminated outcome membedakan created/replayed/completed/conflict/rejected, sehingga completed ulang tidak menjadi replay sukses.
Tipe/aturan HTTP berasal dari profile OpenAPI yang dikunci pada hasil PHP; interface domain di atas tidak mengimpor Request, Response, framework context, cookie, atau tipe database. Setiap bahasa mengadaptasi domain type sendiri.
Dua Bun berbagi satu implementasi TypeScript domain/security dan kontrak oRPC yang sama untuk mengisolasi selisih host framework. Go/Rust memiliki implementasi mandiri. PHP tidak diganti port/transpiler.
Interface kecil menyembunyikan transaksi dan otorisasi, sesuai boundary-discipline/minimize-reader-load. Maksimal jalur masuk → pemilik domain → driver DB; hindari facade per tahap load/validate/save yang hanya meneruskan objek.

## Peta artefak
| Lokasi di `experiments/native-stack/` | Pemilik kebenaran |
| --- | --- |
| `profile/profile.yaml`, `profile/openapi.yaml` | Profil, case wajib, payload/status/header, normalisasi yang diizinkan, SLO, seed dan versi schema. |
| `profile/obligations.yaml` | ID kewajiban semantik/query beserta source anchor, cabang, lock, cardinality, dan assertion; bukan template SQL runtime. |
| `fixture/schema/`, `fixture/data/`, `fixture/manifest.json` | Snapshot schema asli berikut functions/triggers/indexes; fixture persisted, digest, generator dan loader SQL mandiri. |
| `php/` | Launcher PHP aktual, config produksi eksperimen, instrumentation sementara; tidak mengganti algoritma bisnis. |
| `typescript/`, `hono/`, `elysia/` | Satu domain/security native TS dan dua shell yang memasang oRPC OpenAPI handler yang sama. |
| `go/`, `rust/` | Domain/security/SQL masing-masing runtime, dependency lock dan build release. |
| `harness/`, `oracle/` | HTTP driver/cookie jar, seed/action schedule, verifier SQL independen, mutasi pembuktian oracle. |
| `evidence/<run-id>/` | Manifest host/config/build, traces privat, coverage, hasil oracle, metrics mentah dan ringkasan aman. |
Schema diekspor sekali dari migration aktual yang terpasang pada database eksperimen baseline. Loader fixture tidak memerlukan PHP; image kandidat tidak membawa PHP dan dapat bootstrap clone snapshot, login, serta beroperasi ketika PHP dimatikan. Tidak ada stored procedure baru yang memindahkan seluruh receipt workflow ke database.
Setiap kandidat/run mendapat clone database, storage dan namespace session/cache sendiri dari snapshot yang sama; pengujian race sengaja berbagi record hanya di dalam satu run. Pergantian kandidat tidak mewarisi data, counter, block allocation, atau cache kandidat sebelumnya.

## Kontrak profile utama
Satu line final berjumlah tiga unit pada harga string `333333.333`, `ppn_per_unit` nonzero, dan satu atribut wajib bertipe dengan nilai; acquisition total `1000000.00` terbagi `333333.34`, `333333.33`, `333333.33`. Pajak dihitung dan dibagi terpisah menggunakan HALF_UP sesuai PHP.
Fixture memuat vendor/party, direct-payable, feed enabled/cutover valid, amount precision 2/unit precision 3, account acquisition/payable/input-tax aktif, BU+department bernomor dalam management hierarchy published, lokasi/classification/context nomor valid, buku current dan profil depresiasi straight-line monthly aktif dengan useful life 48. Seluruh nama, kode, tanggal dan ID dibaca dari fixture persisted.
Siklus utama: POST draft satu unit → PATCH seluruh header/details menjadi tiga unit → POST selesaikan → GET receipt → GET receipt/aset → GET setiap aset → list receipt dan aset dengan cursor. Setiap aksi memakai versi dan ID jawaban sebelumnya; PATCH bukan endpoint append-lines.
Beban positif harus menghasilkan `pending`, bukan held/manual. Draf tidak menghasilkan aset/posting. Book snapshot, start date, ledger, atribut typed, datatype lock dan audit tetap nyata. Pemilihan condition/model/manufacturer serta attachment/link request adalah case tambahan terdeklarasi, bukan sukses kosong.
Pembuatan draft mempertahankan penerbitan nomor header sebelum transaksi header; completion menerbitkan nomor aset di dalam transaksi. Claim+update dapat menaikkan versi dua kali melalui trigger. Replay create mendahului validasi, termasuk body berubah/arsip; complete ulang 422, kalah claim 409.
Batas cache dan transaksi mengikuti PHP untuk profile yang dibandingkan: request cache precision/directory/policy, reset pada batas transaksi terkait, savepoint/retry numbering, urutan lock request/context/nomor, dan atomicity posting tetap diaudit.
Read mengerjakan tenant/company/policy filter sebelum limit, join lookup berpasangan tenant, live vendor/posting/cancellation, field present explicit, ETag, decimal/date/boolean JSON yang benar, serta RecordSearch dan IncrementalList aktual. Search ILIKE dan list seluruh tabel bukan substitusi.

## Auth dan matrix conformance
Setiap VU mendapat principal tersendiri dan login HTTP sendiri: GET login/CSRF → POST login → cookie sesi baru → PUT workspace-context. Akun tanpa 2FA adalah profile utama; cookie PHP tidak disalin untuk kandidat dan tidak ada actingAs/bypass auth.
Native mengerjakan password cost dan pemeriksaan ganda Fortify, limiter, cookie MAC/encryption/prefix, database JSON session, expiry/rotation/save-on-GET/GC lottery, user provider person-only, audit actor, environment routing, membership dan module/license checks yang benar-benar dijalankan baseline.
Setiap request memulihkan authority dari membership/assignment, role hierarchy aktif, duty→privilege→permission, grant bertanggal, company dan policy unit/descendants. Workspace/session menyimpan pilihan saja. Database pusat dan bisnis dipisah bila profile environment terpisah dipakai; shared profile tidak berpura-pura membuktikan dedicated routing.
Matrix wajib mencakup auth hilang/cookie rusak/expired/deleted/logout/password salah/application account/limiter/CSRF 419; probe CSRF menghapus header same-origin yang dapat melewati pemeriksaan. Cabut membership/role/duty/permission di antara dua request dengan cookie sama.
Matrix bisnis mencakup tenant/company/unit asing; all/specific/descendants grants; null/ties/numeric sort/page kedua/frase/punctuation search; creation/PATCH/completion races; invalid/stale/missing version; sequence refill/exhaustion/preallocation off; atribut invalid; rollback posting.
Case tambahan mencakup clearing/grant/opening/per-book balances, held/manual/zero/no-posted-book, inherited location, nondepreciating/threshold/profile override, request remaining-quantity race, dan attachment inheritance/rollback. Coverage report menyebut setiap case yang belum dibuat; belum lengkap berarti bukan full parity.
2FA, SSO, Redis generation revocation, dedicated environment, serta cancellation aktual diberi case tersendiri bila masuk profile; exclusion harus tercatat sebelum benchmark. Tidak mengklaim semua aplikasi PHP sudah direplikasi karena satu flow ini lulus.

## Audit query dan oracle
`obligations.yaml` memetakan semua leaf grounding: environment/session/user/set_config; membership/role/permission/policy; existence/currency/vendor/org/attribute/model; number scope/counter/allocation/issue/audit; receipt/detail/request locks; assets/placements/books/ledger; posting normalization/mapping/BU/lines/events; attachments dan seluruh read decoration.
Setiap implementasi menautkan statement fingerprint ke obligation. Satu query boleh memenuhi beberapa obligation; count/fingerprint/lock-order berbeda harus dijelaskan dan ditinjau. Oracle hijau tanpa kerja semantik yang wajib tetap gagal conformance. Tidak memaksa physical query-count identik atau menambah query pura-pura.
Collector PHP dimulai sebelum ResolveEnvironment/StartSession dan selesai setelah session write/termination terkait; satu DB listener saat boot mengambil collector request aktif. Driver native mencatat boundary yang sama, connection role, transaction/savepoint, query hash, durasi, rows dan error class tanpa bindings, password, cookie/token atau body.
Trace validasi dingin/panas dan refill/cabang negatif disimpan privat. Trigger ledger/audit/change-log ditelusuri lewat schema digest, delta tabel serta nested PostgreSQL statistics/logging bila tersedia; app query count dilaporkan terpisah dari nested trigger work. Fitur instrumentation yang belum tersedia tidak dianggap sudah terbukti.
Oracle memakai koneksi SQL read-only terpisah dan perhitungan expected dari fixture/command, tanpa memanggil helper kandidat. Periksa exact jumlah/link tenant-company, unique kode/key, allocation/counter/issue/audit, placements/books/profile/ledger, exact decimal split+tax, satu posting deterministic+balanced lines+event+snapshot+pending, atribut dan audit actor.
Setelah kegagalan/race, periksa versi/status dan seluruh efek rollback; nomor header yang sudah committed tetap ada. Uji pooled connection dengan aktor bergantian untuk mendeteksi actor/tenant/ledger context bocor. Session-level set_config tidak dianggap aman dengan transaction pooling tanpa pembuktian.
Bandingkan status/header dan nilai JSON dengan PHP aktual, termasuk create/replay yang bentuknya berbeda. Normalisasi hanya ID generated/timestamp nondeterministik yang diizinkan manifest; decimal, date format, field absent/null, sort/cursor, status dan versi tidak disamarkan.
Buktikan verifier merah pada fixture cacat terkontrol sebelum mempercayai nol; jika constraint mencegah injeksi, catat kegagalan constraint sebagai bukti. Korelasi HTTP→SQL memakai run/request ID nonrahasia; hasil privat tidak dipublikasikan sebagai raw dump.

## Fairness dan lima putaran
PHP sungguhan harus bootstrap, login, menjalankan main/matrix, menghasilkan trace dan lolos oracle lokal lebih dahulu. Empat native melewati gate yang sama; tidak ada replay SQL, mock PHP, forwarding ke PHP, shortcut prepared success, atau precomputed journal/permission.
Profil menerapkan resource CPU/RAM, dua+ instance API, DB/schema/index/trigger, total budget pool/koneksi, cache/session store, proxy/TLS/compression, worker/config dan toolchain yang dicatat. Samakan konfigurasi yang sepadan; konfigurasi efektif berbeda diterangkan, bukan disebut identik.
Bandingkan dalam host/topologi yang sama; dev1/dev2 adalah hasil lingkungan terpisah. Generator ditempatkan terpisah dari bottleneck bila mungkin; ukur CPU/steal/RAM/GC, queue, app threads/workers, koneksi/pool wait, DB CPU/I/O/locks/WAL, network dan response bytes.
Jeda sebelum tiap aksi diambil independen `Exponential(mean=5s)` dengan start tersebar dan seed berpasangan antarkandidat. Ini simulasi pengguna, bukan telemetri manusia. Laporkan VU, request in-flight, offered/completed rate, latency request tanpa think time dan durasi siklus termasuk/tanpa think time; closed-loop tidak membuktikan maximum open-loop RPS.
Lakukan smoke/1 VU, pemanasan, lalu staircase sampai first SLO breach; tahan tiap plateau minimal 90 detik, tambah durasi bila sampel persentil tipis, lalu refine interval last-pass/first-fail. Race/retry dan login storm terpisah dari plateau pengguna; login nyata tetap diukur dan tidak memakai limiter yang dilonggarkan.
Lima putaran independen per kandidat/host pada beban pembanding dan batas yang ditemukan, dengan snapshot bersih, seed berpasangan dan urutan Latin-square lima kandidat. Laporan menunjukkan seluruh putaran, median/rentang/interval serta last-pass/first-fail; jangan memilih putaran terbaik atau mencampur persentil lintas run sebagai satu run.
Gate proyek: read p95<200/p99<500 ms, write p95<400/p99<900 ms. Proposal completion memakai gate write yang dikunci di profile sebelum run; cycle dilaporkan tersendiri tanpa mengarang SLO cycle. Nol tenant leak/duplicate/escalation/rollback error/application 5xx; timeout/LB502/504 merupakan kegagalan kapasitas, bukan sukses atau bukti salah data.
Gate modul 1000+ VU, 100+ tenant, dua+ instance dan 90 detik tidak otomatis tercapai; jika batas SLO lebih rendah, laporkan batas terbukti dan gate belum terpenuhi. Pisahkan run trace berat dari run timing dengan konfigurasi ringan identik, serta ukur overhead instrumentation sebelum mempercayai selisih kecil.

## Tradeoff dan alternatif
Kita menerima duplikasi empat implementasi dalam pertukaran untuk SQL/domain native yang tidak dipaksa melalui mesin bersama. Dua Bun sengaja berbagi domain agar perbandingan Hono/Elysia lebih terkendali.
Angka utama mengukur stack+framework+driver+algoritma query, bukan runtime murni. Laporkan total/query count/rows/DB time/lock work per aksi dan delta strategi; penurunan query yang sah tidak dikreditkan hanya ke bahasa. Jika strategi dominan berbeda, atribusi runtime berstatus INCONCLUSIVE sampai eksperimen matched-strategy terpisah.
Alternatif shared executable SQL catalog menyembunyikan banyak detail tetapi menjadikan catalog pemilik workflow dan membatasi pilihan driver/idiom; caller mudah mendapat kesetaraan semu sementara biaya aplikasi bergeser ke SQL. Stored procedure untuk seluruh workflow lebih jauh mengubah pertanyaan menjadi biaya adapter.
Alternatif salinan handler ringan dengan auth atau posting stub lebih pendek tetapi memindahkan beban yang ditanyakan pengguna keluar pengukuran; ditolak. Schema/trigger asli tetap bersama karena memang bagian sistem aktual, bukan pengganti domain di salah satu kandidat.
Synthesis decision belum diisi: ini kandidat independen B; pemilihan base dan graft milik orchestrator setelah membandingkan sketsa lain.

## Batas, risiko dan langkah berikutnya
Apakah byte response/error framework, config session/env PHP efektif, hash vendor, hook termination dan footprint nested trigger dapat dibuktikan lokal? Sampai itu tercatat, kesetaraan runtime dan angka query tetap NOT VERIFIED.
Apakah oRPC OpenAPI dapat mempertahankan semua status/header/error PHP tanpa validasi ganda yang berbeda pada dua Bun? Buktikan adapter paling kecil sebelum membangun domain; overhead oRPC tetap termasuk hasil.
Apakah sumber daya generator/host/DB cukup untuk menyatakan batas aplikasi, dan apakah perubahan query dapat dipisahkan dari runtime? Jika tidak, laporan menyebut limiter terukur dan klaim terkait INCONCLUSIVE.
Langkah implementasi pertama: bangun loader snapshot+profile main, jalankan PHP aktual melalui HTTP dan verifier SQL merah/hijau, lalu bekukan obligation/contract baseline sebelum mengisi implementasi native pertama.
