# TODO pilot engine dan integrasi satu flow aset

Area implementasi belum dikerjakan; keputusan engine sudah dicatat. `[x]` memerlukan bukti yang ditautkan pada area, bukan hanya kode
atau test mock. Istilah **baru (usulan)** pada file berarti file belum ada dan namanya perlu
disepakati saat implementasi. Baca [PRD](./prd), [fondasi Signavio](./fondasi-signavio),
[arsitektur](./arsitektur), dan [verifikasi](./verifikasi).
Runtime ditetapkan: [Flowable](./pemilihan-engine). Gate pilot memeriksa kesiapan integrasi,
bukan memilih ulang engine.

## Area 0 — [ ] Kunci scope dan keputusan produk

**Tempat:** dokumentasi dan review keputusan. **Setelah:** tidak ada.
**Skill:** module-discovery, coreerp-architecture, number-sequence-design bila nomor berubah.

- [x] 0.0 Catat keputusan pemilik 31 Oktober 2026: Flowable, satu source dan konfigurasi proses per tenant. Bukti: WT-16–18 di [README](./).
- [ ] 0.1 Konfirmasi WT-02: flow penerimaan saja; tetapkan respons asynchronous, status UI, dan kapan draft tidak boleh diubah.
- [ ] 0.2 Konfirmasi tidak menambah approval wajib; default/SoD proses existing tidak diubah.
- [ ] 0.3 Tetapkan authorization saat job berjalan, cancellation, serta perubahan konfigurasi/module ketika proses menunggu.
- [ ] 0.4 Audit source [riset](./riset), migrations aktif, route, manifest, tests; tulis gap dengan bukti aktual.
- [ ] 0.5 Tetapkan kepemilikan adapter Foundation/Platform dan kontrak module; jangan bocorkan API engine ke module.
- [ ] 0.6 Catat keputusan di README; tandai usulan ditolak/diganti, jangan menyatakan semuanya disetujui dari satu pesan.
- [ ] 0.7 Tulis happy path, as-is dari controller/test, dan to-be flow aset sesuai PDF SAP hlm. 5;
      bedakan node tindakan bisnis dari detail API engine, HTTP, outbox, dan commit.
- [ ] 0.8 Kunci kontrak domain pilot tanpa bergantung engine: kapan aset tercipta, permission,
      idempotency, cancel dan commit atomik. Tidak menunggu semua module settle untuk mulai pilot.

**Selesai bila:** perilaku satu flow, akses, status, ownership, dan deployment spike tidak ambigu.
Kode produksi dan manifest yang mengubah lifecycle menunggu gate ini.

## Area 0A — [ ] Fondasi model dan update per kemampuan

**Tempat:** model proses, editor, validator, dan kontrak binding. **Setelah:** 0.
**Skill:** module-discovery, coreerp-architecture, frontend-patterns, coreerp-docs.
**Berkas:** `WorkflowGraph.php`, editor existing, migration workflow existing; model/adapter schema
dan fixture **baru (usulan)**. Pemetaan sumber ada di [fondasi Signavio](./fondasi-signavio).

- [ ] 0A.1 Buktikan WT-12: mapping model existing/metadata ke BPMN executable Flowable; target
      interoperability dan dependency editor tambahan perlu bukti. React Flow bukan parser BPMN.
- [ ] 0A.2 Tentukan stable model/element/connection IDs, schema version, kind koneksi, documentation,
      reference dan action binding/version; pisahkan layout dari rencana executable. Simpan hanya yang dipakai.
- [ ] 0A.3 Matriks capability dengan kolom modeled/rendered/validated/executable; serial menjadi target pilot,
      gateway/subprocess/message belum aktif. Unknown element tidak berubah menjadi sukses atau hilang.
- [ ] 0A.4 Rancang binding tindakan module yang minimal untuk satu flow, sehingga menambah tindakan
      tidak memerlukan hardcode nama module/client dalam adapter. Hak tindakan tetap diperiksa module.
- [ ] 0A.5 Konvensi subset publish: start/end, reachable nodes, urutan serial, nama/deskripsi,
      data reference valid, larangan implicit split/duplicate merge. Bedakan error eksekusi dan warning layout.
- [ ] 0A.6 Fixture model v1 untuk load/save, ganti label/layout, unknown capability, template copy,
      dan fingerprint semantik; tests converter eksplisit bila format existing berubah.
- [ ] 0A.7 Tetapkan gate additive update, published immutable, instance pinned, downgrade refusal,
      dan daftar bagian yang berubah per capability. Tidak menjanjikan semua update tanpa migration.
- [ ] 0A.8 Catat dokumen/fixture SAP/OMG yang belum tersedia sebelum mengimplementasikan semantik
      baru. Jangan mengklaim BPMN penuh atau mengisi dictionary/gateway storage dari tebakan.
- [ ] 0A.9 Gunakan [hasil pemeriksaan XML trial](./contoh-xml-signavio) sebagai kasus negatif
      publish executable: non-executable process, task unbound, XOR tanpa expression, timer tanpa durasi,
      message tanpa correlation. Model/deskripsi tidak boleh berubah menjadi transaksi hanya dari import.

**Selesai bila:** format model dan mapping pertama terbukti, boundary editor/executor jelas,
fixture kompatibilitas tersedia, dan capability yang ditunda tidak masuk executable plan.
Smoke engine/API area 1 dapat berlangsung paralel, tetapi persistensi dan binding
produksi area 2–4 menunggu 0A. Model deskriptif/full BPMN bukan syarat flow pertama.

## Area 1 — [ ] Pilot Flowable

**Tempat:** runtime percobaan. **Setelah:** 0. **Skill:** laravel-patterns, coreerp-harbor bila menyentuh image.
**Berkas:** konfigurasi engine/adapter/worker **baru (usulan)**; compose dan image existing hanya bila perlu.
Dependency/image produk menunggu gate 1G. Percobaan terisolasi tidak mengubah default runtime.

- [ ] 1.1 Matriks versi Java/Flowable/PostgreSQL/PHP dan paket REST; audit dependency transitif dan notice.
- [ ] 1.2 Smoke deploy/start BPMN minimal tanpa efek bisnis; buktikan external worker job/API dan history.
- [ ] 1.3 Jalankan persistence engine PostgreSQL; restart engine, job dan instance dapat dilanjutkan.
- [ ] 1.4 Worker PHP mengambil/lock/complete/fail job, memuat layanan module, drain dengan benar tanpa mengganti web runtime.
- [ ] 1.5 Catat resource idle dan aktif; image bersih/on-prem tanpa download saat start.
- [ ] 1.6 Buktikan definisi versi lama dan instance/job yang menunggu tetap diproses setelah adapter/worker diperbarui.
- [ ] 1.7 Audit lisensi engine vs Work/Design, hak bundling/OEM bila relevan dan mapping model existing;
      tidak memasang SDK engine lain atau membangun interpreter BPMN kedua.
- [ ] 1.8 Jalankan flow aset/failure dengan Flowable memakai layanan area 2,
      fixture dan oracle SQL bisnis yang sama dengan baseline; Hello World bukan bukti integrasi.
      Gunakan harness terisolasi, tanpa mengaktifkan endpoint/outbox/UI produk; gap dispatch penuh
      dicatat dan diuji pada area 3–6, tanpa dependency melingkar dengan gate 1G.
- [ ] 1.9 Catat resource, editor/parser, adapter, versioning, isolasi tenant dan on-prem
      dalam [bukti integrasi](./pemilihan-engine); deduplikasi start diuji pada respons hilang/konkurensi.

**Selesai bila:** instalasi bersih dan restart nyata terbukti; biaya awal diketahui. Hentikan perluasan bila
kompatibilitas atau operasional tidak layak; tulis hasil, jangan lanjut hanya karena Hello World lulus.
Binding/domain pilot 1.8 menunggu 0A dan area 2; smoke instalasi tidak perlu menunggunya.

## Area 1G — [ ] Kesiapan Flowable sebelum integrasi penuh

**Tempat:** bukti pilot dan keputusan produk. **Setelah:** 1.8–1.9, 2 dan 0A.

- [ ] 1G.1 Review kebenaran, customization, lisensi, resource, operasi dan kode yang perlu dirawat; trial bukan bukti.
- [ ] 1G.2 Catat versi/distribusi Flowable teruji, API, batas fitur, gap dan keputusan lanjut/tunda.
- [ ] 1G.3 Cocokkan area 3–6 dan pemasangan dengan hasil pilot; keputusan engine sudah WT-16.

**Selesai bila:** kesiapan integrasi Flowable dibuktikan. Tidak ada fallback otomatis dua engine.
Approval/template alternatif ditambah setelah default teruji, bukan scope flow pertama.

## Area 2 — [ ] Kontrak operasi dan ekstraksi penyelesaian aset

**Tempat:** module dan boundary Core. **Setelah:** 0; integrasi penuh setelah 1G.
**Skill:** code-formatting, laravel-patterns, security-review, coreerp-architecture.
**Berkas:** controller penerimaan pada [riset](./riset), `AcquisitionPosting.php`, `PenerimaanAsetTest.php`;
layanan `CompleteAssetReceipt` **baru (usulan)**, kontrak operasi internal **baru (usulan)**.

- [ ] 2.1 Ekstrak tindakan domain dari `selesaikan()` tanpa mengubah perilaku terlebih dahulu; endpoint existing memakai layanan yang sama.
- [ ] 2.2 Masukan eksplisit: scope tepercaya, receipt ID/version, actor dan operation ID; bukan Request tiruan atau session worker.
- [ ] 2.3 Pertahankan nomor, pembulatan, saldo awal, kuantitas, vendor, policy, dan posting dalam transaksi existing.
- [ ] 2.4 Simpan hasil idempoten; retry setelah commit mengembalikan asset IDs/posting ID yang sama.
- [ ] 2.5 Jangan mengembalikan sukses untuk dokumen selesai oleh operasi lain; validasi operasi, versi, dan scope.
- [ ] 2.6 Regression jalur sinkron sampai cutover; tidak membuat jurnal manual atau mengubah kontrak finance v1.

**Selesai bila:** test domain existing dan test konkurensi/idempotency terarah lulus di PostgreSQL.
Kontrak operasi final juga menunggu area 0A; ekstraksi tanpa perubahan perilaku dapat berjalan
lebih dahulu setelah area 0.

## Area 3 — [ ] Intent, outbox, dan konteks worker

**Tempat:** Core + module. **Setelah:** 0A, 1G, 2.
**Skill:** coreerp-architecture, laravel-security, error-handling.
**Berkas:** migration/model intent dan outbox **baru (usulan)**; `TenantRunnerCore.php`, `ActiveEnvironment.php` sebagai bukti batas existing.

- [ ] 3.1 Putuskan reuse atau penambahan tabel sesuai [arsitektur](./arsitektur); index unik operasi dan scope dibuktikan SQL.
- [ ] 3.2 Commit intent/outbox atomik; crash sebelum/sesudah dispatch tidak menghilangkan atau menggandakan perintah.
- [ ] 3.3 Operation ID/business key tetap untuk korelasi; buktikan guard deduplikasi start dan rekonsiliasi timeout, bukan mengandalkan key saja.
- [ ] 3.4 Worker menyelesaikan koneksi dari environment tepercaya; request tidak mengirim nama database/kredensial.
- [ ] 3.5 Reset konteks/koneksi/cache setelah setiap job termasuk exception; test A → B → A pada satu worker.
- [ ] 3.6 Rekonsiliasi hasil domain dan proyeksi status; status finance tetap makna existing.

**Selesai bila:** service mati dan dispatch ulang tidak kehilangan intent; pooled/dedicated database diuji nyata.

## Area 4 — [ ] Adapter Flowable untuk satu flow

**Tempat:** adapter eksekusi Core. **Setelah:** 3.
**Skill:** backend-patterns, error-handling, coreerp-architecture.
**Berkas:** BPMN mapping, adapter, registry dan worker entry point **baru (usulan)**;
`WorkflowGraph.php`, `WorkflowRuntime.php`, `WorkflowEngineCore.php` untuk integrasi/reuse terpilih.

- [ ] 4.1 Deploy snapshot BPMN tervalidasi; ikat process definition ID/versi, tanpa PHP/SQL/URL bebas.
- [ ] 4.2 External worker job memanggil layanan area 2; engine tidak mengakses tabel bisnis dan tidak ada interpreter BPMN kedua.
- [ ] 4.3 Kategorikan gagal validasi permanen vs kegagalan sementara; tetapkan timeout/retry agar validasi tidak berulang tanpa batas.
- [ ] 4.4 Satu pemilik executor; proses approval/dekomisioning existing tidak dialihkan atau digandakan.
- [ ] 4.5 Buktikan cancel sebelum commit, race cancel/commit, dan pemulihan setelah commit tanpa physical delete.
- [ ] 4.6 Definition/rules/action-contract versioning dan drain worker; uji instance serta subprocess lama tidak mengikuti latest diam-diam.
- [ ] 4.7 Adapter memetakan model semantik area 0A ke BPMN/binding, bukan komponen UI atau kode
      engine mentah dari tenant; dukung hanya capability yang terdaftar dan diuji.

**Selesai bila:** matriks failure [verifikasi](./verifikasi) lulus dengan service/worker asli.

## Area 5 — [ ] Status pengguna dan konfigurasi terbatas

**Tempat:** layar Core dan module. **Setelah:** 0, kontrak status area 3; end-to-end setelah 4.
**Skill:** coreerp-page-standard, coreerp-ui, react-patterns, code-formatting.
**Berkas:** editor workflow existing dan UI penerimaan yang ditemukan dari route aktual; komponen status **baru (usulan)**.

- [ ] 5.1 Pilih template satu flow; salinan tenant tidak ditimpa update template. Tidak mengerjakan flow kedua.
- [ ] 5.2 Tampilkan menunggu/diproses/gagal/selesai dan tautan hasil; payload engine teknis tidak tampil ke pengguna bisnis.
- [ ] 5.3 Polling/backoff dan retry UI tidak membuat intent kedua; refresh memulangkan keadaan tersimpan.
- [ ] 5.4 Endpoint menyaring tenant, environment, scope, dan permission; menyembunyikan tombol bukan pengaman.
- [ ] 5.5 Dengan HotReload aktif, biarkan source UI diterapkan ke runtime yang berjalan tanpa rebuild atau reload manual. Jika user/developer melaporkan layar stale, periksa Vite/mount dan lakukan fallback seperlunya; health check dilakukan setelah runtime direstart.
- [ ] 5.6 Jika manifest berubah, register di container dan cek navigation runtime; jika route/contract berubah, bundle dan coverage wajib.
- [ ] 5.7 Layout/editor round-trip mempertahankan IDs/semantik. Validator menunjukkan node/error
      publish; menu capability unsupported tidak menjanjikan tindakan yang belum berjalan.

**Selesai bila:** layar runtime baru terverifikasi dan tidak menyatakan sukses sebelum efek bisnis selesai.

## Area 6 — [ ] Uji penerimaan, beban, dan release

**Tempat:** test, load test, dokumentasi, deployment. **Setelah:** 1–5.
**Skill:** ai-regression-testing, laravel-verification, coreerp-docs, coreerp-harbor; coreerp-konsol hanya bila relevan.

- [ ] 6.1 Tambah test Flowable nyata terpisah dari mock unit; fixture definisi/instance/job tidak menyimpan secret.
- [ ] 6.2 Jalankan [matriks failure dan oracle](./verifikasi), simpan bukti SQL dan log correlation.
- [ ] 6.3 Uji beban serta ukur bottleneck, RSS, disk history, queue lag; sepakati gate latensi berdasarkan baseline.
- [ ] 6.4 Uji rollout/rollback, backup/restore, drain, penghentian start baru, dan operasi on-prem tanpa Cloud.
- [ ] 6.5 Jalankan pemeriksaan repo scope berubah; suite penuh Core mengikuti paralel lalu grup serial.
- [ ] 6.6 Tulis dokumentasi kanonik sesuai kode yang dikirim; rencana ini tidak menjadi klaim fitur selesai.
- [ ] 6.7 Review lanjut/tunda berdasarkan bukti operasional dan produk; tidak otomatis memasang ke semua client atau flow lain.
- [ ] 6.8 Gate pembaruan per bagian: fixture model/template v1 tetap dibaca setelah capability
      baru; perubahan layout tidak mengganti fingerprint executable; instance/job lama tetap diproses.

**Selesai bila:** invariant tidak dilanggar, resource diketahui, prosedur recovery dapat diulang, dan
hasil ditinjau. Instalasi service saja tidak menyelesaikan fitur.

## Area lanjut — [ ] Katalog kemampuan, bukan scope fase pertama

Rincian arah dan syarat ada di [roadmap Signavio](./fondasi-signavio#roadmap-per-kemampuan).
Setiap kemampuan berikut membutuhkan kebutuhan nyata, gate penemuan, dan TODO sendiri sebelum kode:

- [ ] Katalog proses/hierarchy, dictionary peran/dokumen/system, documentation dan review;
      reuse data yang terdaftar, lane/reference tidak menciptakan grant.
- [ ] XOR/AND dan subprocess bertahap: token/join semantics, anti-multimerge/deadlock,
      active branch tracking, input/output dan versi subprocess; OR/event-based/complex bukan otomatis ikut.
- [ ] Pools/lanes serta message/timer/connector: publish contract, callback authentication,
      correlation dan rekonsiliasi; tidak memecah DB dari gambar lane.
- [ ] BPMN XML interchange: fixture export SAP, validation schema dan loss report; tidak
      menganggap Signavio XML proprietary sama dengan export BPMN 2.0 XML.
      Satu contoh deskriptif lokal sudah diperiksa; CI memakai fixture sintetis terisolasi, tidak
      menunggu file ignored tersedia dan tidak mendistribusikan template/dictionary trial tanpa izin.
- [ ] Metrik proses, simulation/DMN, serta kolaborasi: kebutuhan dan data nyata dahulu;
      history durable bukan seluruh dashboard atau simulator.

Tidak membuat semua handler/tabel kosong sekarang. Struktur inti, identifiers, versioning,
binding dan fixture area 0A disiapkan supaya bagian lanjut tidak meminta rewrite yang bisa dihindari.

## Arah lintas module dan provisioning tenant — [ ] Setelah pilot aset

WT-17/18 menetapkan arah produk, bukan izin membangun semua module dalam pilot:

- [ ] Katalog capability module menyatakan skema input/output, permission, versi dan efek bisnis.
- [ ] Pembuatan tenant dapat memilih paket default melalui admin.erp → Core setelah module terpasang.
- [ ] Template proses, aturan penjamin/pembayaran, loket, posting profile dan tujuan integrasi berversi;
      salinan tenant tidak ditimpa pembaruan default.
- [ ] Pemilih proses menurut praktik/unit, jenis kunjungan, penjamin dan tanggal berlaku deterministik;
      konflik aturan dan konfigurasi belum lengkap ditolak sebelum aktivasi.
- [ ] Siapkan skenario dari [source sistem lama](./riset#pemeriksaan-sistem-lama-31-oktober-2026):
      kasir registrasi terpisah, kasir akhir, pembayaran obat bebas, pengakuan/posting BO,
      permintaan/order/penerimaan dan alokasi AP/AR sampai jurnal/penutupan.
- [ ] Pisahkan tindakan yang sekarang sekaligus menerima pembayaran/menutup kunjungan atau
      merealisasikan stok/menyerahkan obat; invariant tetap diperiksa layanan pemilik.
- [ ] Uji perubahan template saat proses menunggu, retry lintas module dan status finansial
      terpisah; kegagalan GL tidak menghapus fakta pembayaran yang sudah sah.

## Lajur dan catatan bukti

Setelah area 0, spike instalasi berjalan bersama ekstraksi domain. Binding pilot 1.8 menunggu
0A/2; integrasi penuh menunggu gate 1G. Setelah area 2–3,
adapter dan UI dapat dikerjakan pada file berbeda setelah kontrak dibekukan. Uji crash/load
memerlukan runtime bersama yang disepakati; jangan menjalankan chaos pada stack sesi lain.

Setiap area mengisi: tanggal, keputusan WT yang berlaku, commit/diff, versi runtime, perintah,
hasil, lokasi laporan, dan gap tersisa. Tidak ada status selesai hanya berdasarkan jawaban agen.
Pada setiap kemampuan baru tambahkan capability/rule ID, halaman sumber, perubahan format,
converter bila ada, serta hasil fixture model/instance lama. PDF lokal diabaikan Git dan tidak wajib di CI.
