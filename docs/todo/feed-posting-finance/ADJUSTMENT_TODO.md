# TODO Adjustment CoreERP — tinjauan akun BO dan dokumen penerimaan

## API preview bukti untuk BO — 14 Oktober 2026

USER menyetujui rencana preview di BO melalui API dan meminta pengujian lokal. CoreERP memiliki endpoint read-only daftar/unduh bukti berdasarkan posting_id+receipt; BO memiliki daftar/proxy/preview. Mengadaptasi [document management Dynamics365](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/organization-administration/configure-document-management): berkas tetap melekat record asal. Scope existing finance-postings.read mencakup bukti Penerimaan yang dirujuk posting pending/posted dan boleh dibaca klien tenant/prefix tersebut. Tidak membuka akses bebas ke dokumen; tidak membuat permission/catalog/schema baru. Parent harus aktif dan mempunyai tenant_id/legal_entity_id sama dengan posting, dijawab AssetAttachments melalui kontrak FinanceEvidenceRecordType. Izin unit pengguna BO diperiksa consumer atas semua unit sumber sebelum memanggil API. Reference lama tanpa receipt tidak di-backfill. API daftar tidak mengirim storage_path/hash/token/URL; download memeriksa hash dan ukuran maksimum25MiB.

| Task | Pemilik | Status | Dependensi/batas | Selesai |
| --- | --- | --- | --- | --- |
| CORE-DOC-API | API, kontrak dan pemeriksaan parent module | AGENT | SELESAI SOURCE + RUNTIME LOKAL | Daftar/unduh PNG200, token salah401, ID salah404; bundle/25route/Pint lulus | 2026-10-14 |
| CORE-DOC-API-REGRESSION | Fixture keamanan PHPUnit Core | AGENT | DISIAPKAN, BELUM DIJALANKAN | Persetujuan reset core_erp_test belum diberikan; uji existing tanpa reset diprioritaskan USER | — |
| CORE-DOC-API-DEV | Rilis dan uji runtime dev | AGENT | BELUM SIAP DIUJI | Kedua sisi source perlu dirilis; prasyarat sisi BO PUSAT/PTDI: credential terakhir tercatat HTTP401 (9 Okt). Setelah rilis pasangan, pulihkan/tes credential PTDI lalu lakukan GET bukti pada receipt dev yang memiliki lampiran. Tidak ada migration fitur | — |

CoreERP runtime lokal memakai image turunan PHP/contract dari image existing dan app/worker/scheduler healthy tanpa migrate/seed. BO lokal telah mem-preview image PNG PNRA00028 pada source penyusutan ASTA00034. Token PTD1 kosong diisi terenkripsi dengan credential Windows existing setelah persetujuan eksplisit USER (perubahan hanya setting BO lokal); bukan token dev. PDF/S3/lintas server nyata belum dibuktikan. Bukti dan handoff kanonik: [E2E BO](../../../../bo_metta_anggaran_new/docs/integrasi-bo-coreerp/E2E_PROGRESS.md), [checklist BO](../../../../bo_metta_anggaran_new/docs/integrasi-bo-coreerp/CONFIG_TODO.md). Tidak membuat transaksi/GL/ACK.

Rencana terbaru bukti PO/Penerimaan untuk Penerimaan dan Penyusutan BO: [catatan 14 Oktober 2026](#bukti-dokumen-perolehan-untuk-bo--14-oktober-2026). Bagian ini memperinci CORE-05/06/07/10/11; status dan keputusan lama di bawah adalah konteks historis, bukan pekerjaan yang sudah selesai atau otorisasi database.

Dibuat 9 Oktober 2026 (WITA). Status mengikuti bagian masing-masing; catatan awal adalah rencana historis. Implementasi lokal bukti dokumen tahap awal dan batas pengujiannya dicatat pada pembaruan 14 Oktober di bawah.

Pasangan checklist BO ada di repo BO pada `docs/integrasi-bo-coreerp/ADJUSTMENT_TODO.md`; register bukti E2E ada di `docs/integrasi-bo-coreerp/E2E_PROGRESS.md`. Referensi CoreERP saat ini: [PRD](README.md) dan [TODO existing](TODO.md). Checklist ini mengatur pekerjaan baru; tidak menggantikan bukti pengujian atau menandai implementasi existing belum selesai.

## Masalah dan tujuan

Feed saat ini mengirim usulan akun dan BO langsung membukukan transaksi yang valid. Pengguna meminta pola seperti faktur Farmasi: transaksi masuk sebagai draft, Akunting dapat memilih akun, lalu melakukan posting manual. Dokumen di BO juga harus menampilkan **Nomor Penerimaan, Nomor PO, dan Image Dokumen** yang berasal dari CoreERP.

CoreERP memiliki perubahan sendiri pada konfigurasi, upload gambar, referensi dokumen, kontrak feed, dan status pengguna. BO memiliki perubahan pada penyimpanan draft, edit akun, pembukuan, tampilan gambar, dan pengiriman hasil. Mode pembukuan otomatis/manual merupakan pengaturan terpisah dari `settlement_mode` (direct_payable/clearing). Memilih clearing tidak berarti menahan posting di BO.

## Implementation Plan

1. Audit pilihan dan fasilitas dokumen yang sudah ada; tetapkan kontrak bersama sebelum perubahan source.
2. Tambahkan pilihan tinjauan BO dan upload gambar memakai fasilitas CoreERP yang sesuai.
3. Kirim snapshot referensi penerimaan/PO, usulan akun, serta identitas lampiran melalui feed.
4. Sesuaikan status dan konfirmasi dengan tahap draft BO; pembukuan akhir menunggu tindakan Akunting.
5. Implementasikan penerima BO, lalu verifikasi satu alur lengkap tanpa transaksi ganda.

## Checklist CoreERP

Semua task implementasi milik AGENT. Tanggal selesai diisi hanya setelah kriteria task terpenuhi. Pilihan yang belum diputuskan adalah dependensi perencanaan, bukan hasil implementasi.

| ID | Task / kriteria selesai | Penanggung jawab | Status | Dependensi / penghalang | Tanggal selesai |
|---|---|---|---|---|---|
| CORE-01 | Audit konfigurasi finance, publikasi penerimaan, fasilitas upload/lampiran, dan sumber nomor PO. Catat kemampuan existing serta gap; jangan menganggap opsi mode manual sudah ada. | AGENT | BELUM DIMULAI | — | — |
| CORE-02 | Tetapkan kontrak bersama BO: mode pembukuan, lifecycle draft/final, nomor penerimaan/PO, metadata gambar, dan hasil pembukuan. Nama field/versi final mengikuti audit contract existing. | AGENT | BELUM DIMULAI | CORE-01; BO-01 | — |
| CORE-03 | Tambahkan pilihan otomatis atau ditinjau Akunting di BO pada konfigurasi integrasi yang tepat. Simpan mode pada snapshot transaksi; pergantian konfigurasi tidak mengubah transaksi yang telah diterbitkan. | AGENT | BELUM DIMULAI | CORE-02; DEC-01 | — |
| CORE-04 | Tetap terbitkan feed ketika penerimaan diselesaikan, termasuk mode draft BO. Akun dari posting group menjadi usulan awal yang dapat disesuaikan oleh Akunting di BO. | AGENT | BELUM DIMULAI | CORE-03 | — |
| CORE-05 | Tambahkan upload Image Dokumen pada penerimaan aset, preview, dan akses gambar yang telah diunggah. Audit/reuse fasilitas berkas Core; validasi jenis/ukuran, izin tenant/entity, serta arsip/retensi. Batas ukuran dan jumlah gambar diputuskan pada DEC-02. | AGENT | BELUM DIMULAI | CORE-01; DEC-02 | — |
| CORE-06 | Sertakan Nomor Penerimaan dan Nomor PO aktual pada feed. Hubungkan PO ke penerimaan yang benar; jangan mengisi nomor permintaan pembelian sebagai nomor PO. Untuk penerimaan tanpa PO, tampilkan kosong/belum ada dengan jujur. | AGENT | BELUM DIMULAI | CORE-01; CORE-02 | — |
| CORE-07 | Sertakan identitas/metadata lampiran dan jalur akses yang dapat dibaca BO secara terotorisasi. Tentukan download terautentikasi atau salinan BO; URL sementara tidak boleh menjadi satu-satunya referensi permanen. Jangan mempublikasikan gambar privat atau menaruh token di URL. | AGENT | BELUM DIMULAI | CORE-02; CORE-05; BO-05 | — |
| CORE-08 | Tampilkan status diterima/menunggu tinjauan BO sesuai kontrak. Konfirmasi draft belum berarti Sudah dibukukan; status akhir dan nomor jurnal/faktur BO muncul setelah pembukuan manual berhasil. | AGENT | BELUM DIMULAI | CORE-02; BO-02 | — |
| CORE-09 | Tetapkan koreksi/pembatalan/reversal setelah akun BO berubah. Identitas posting dan snapshot akun usulan tetap; reversal mengacu pembukuan aktual BO, bukan hanya akun usulan CoreERP. | AGENT | BELUM DIMULAI | CORE-02; BO-02 | — |
| CORE-10 | Perbarui contract tertulis, versi/capability bila diperlukan, serta dokumentasi pengguna. Payload lama tanpa PO/gambar tetap dapat dibaca sesuai strategi kompatibilitas yang ditetapkan. Jalankan bundling dan pemeriksaan coverage saat contract/source diimplementasikan. | AGENT | BELUM DIMULAI | CORE-02 sampai CORE-09 | — |
| CORE-11 | Verifikasi bersama BO: draft tidak menghasilkan pembukuan akhir; perubahan akun + posting manual berhasil; receipt/PO/image cocok; retry tidak menggandakan dokumen; isolasi akses gambar; automatic tetap bekerja bila dipilih. Catat bukti di register E2E existing. | AGENT | BELUM DIMULAI | CORE-10; BO-08 | — |

## Keputusan produk yang perlu dicatat

| ID | Keputusan | Penanggung jawab | Status | Dependensi | Tanggal selesai |
|---|---|---|---|---|---|
| DEC-01 | Pilih default mode dan cakupannya: per entitas/integrasi, jenis transaksi, atau pilihan per transaksi. Tentukan apakah hanya pembelian direct_payable atau juga GL-only memakai tinjauan manual. Rencana awal mencakup draft AP untuk pembelian hutang dan draft GL untuk penyusutan. | USER | BELUM DIPUTUSKAN | Rekomendasi CORE-01/BO-01 disiapkan AGENT | — |
| DEC-02 | Pilih jenis Image Dokumen yang dilampirkan pada penerimaan, jumlah/ukuran yang diizinkan, kewajiban upload, dan apakah gambar PO ikut ditampilkan. Kebutuhan awal: upload gambar di penerimaan CoreERP dan view di BO. | USER | BELUM DIPUTUSKAN | Audit fasilitas berkas CORE-01 | — |

## Kriteria terima data dokumen

- BO menampilkan nomor penerimaan persis dari CoreERP dan nomor PO aktual jika ada.
- Gambar yang tampil milik penerimaan yang sama; akun tanpa hak tidak dapat mengaksesnya.
- Dokumen tanpa gambar/PO tetap memiliki tampilan kosong yang jelas sesuai kebijakan wajib/opsional.
- Gambar tetap dapat dilihat setelah retry, sesi login baru, dan berakhirnya URL sementara.
- Penyusutan dapat berisi beberapa aset/penerimaan/PO; tampilkan referensi per sumber bila ditambahkan, jangan menyajikan satu nomor penerimaan seolah mencakup seluruh batch.

Dokumen ini hanya TODO. Tidak menjalankan perubahan server/database, transaksi, atau deployment.

## Addendum: peta ayat jurnal penyusutan per grup — 12 Oktober 2026

**Masalah:** source lokal PTD1 `AST-DEP-01m3bxqhezkx2xv31c1q76fy8f-01M3BXCYBNE2QEXR7SJG2D3RJT-20261031-5` sudah mengandung tiga aset pada UJI-GRUP-A/B dan jurnal seimbang IDR350.000, tetapi payload yang disimpan BO tidak membawa `details.journal_groups`. BO memerlukan nomor baris debit/kredit per grup untuk membuat dua voucher yang benar. Source lama immutable dan tidak dapat diperkaya sesudah publish.

**Pemilik:** CoreERP membentuk dan mengontrakkan `journal_groups`; BO adalah konsumen yang sudah memiliki mapper untuk membaca field tersebut. HEAD `main` belum mengirim metadata itu. Working tree sekarang membentuk pemetaannya dan kontraknya, tetapi source PTDI lama serta runtime dev belum memuat perubahan ini. Source lama immutable sehingga pembuktian berikutnya memakai posting ID baru.

**Implementation Plan**

1. Nyatakan `details.journal_groups` di contract finance-posting v1 sebagai metadata opsional untuk `asset.depreciation`: `asset_group`, nama tampilan, `depreciation_expense_line_nos`, dan `accumulated_depreciation_line_nos`. Metadata ini hanya mengaitkan grup ke `journal_lines`; angka/akun jurnal tetap berasal dari `journal_lines`.
2. Tambahkan regression test `DepreciationPostingTest` dengan dua grup dan satu buku bersama. Pastikan dua peta grup memuat seluruh nomor baris tepat sekali dan total asset/detail cocok dengan debit/kredit.
3. Bundel ulang contract dan jalankan pemeriksaan coverage serta tes relevan.
4. Bangun ulang runtime CoreERP lokal dari checkout ini. Source #5 yang sudah terbit tidak berubah; verifikasi mapping melalui source baru yang memakai posting ID baru.
5. Setelah source baru diterima BO dan kedua grup bisa diedit, USER menjalankan tindakan posting final; AGENT memeriksa ack dan voucher secara baca-saja.

| Task | Penanggung jawab | Status | Penghalang / dependensi | Tanggal selesai |
|---|---|---|---|---|
| CORE-GRP-01: contract dan test peta ayat jurnal per grup | AGENT / CoreERP | SELESAI | CORE-GRP-01A dan CORE-GRP-01B selesai; runtime dev serta source PTDI baru tetap task lanjutan | 2026-10-12 |
| CORE-GRP-01A: nyatakan metadata opsional di contract, bundle, cek coverage, dan format test | AGENT / CoreERP | SELESAI | Metadata opsional di `details` terbuka; bundle check, 23 route internal, dan Pint lulus | 2026-10-12 |
| CORE-GRP-01B: buktikan mapping dua grup di satu buku dan kecocokan total | AGENT | SELESAI | `DepreciationPostingTest`: 14 test/912 assertion; memeriksa 2 grup, 2 department, semua nomor baris dan subtotal, serta periode batal lalu diusulkan ulang | 2026-10-12 |
| CORE-GRP-02: rebuild runtime CoreERP lokal dan verifikasi payload baru | AGENT | TODO | Image test sementara bukan runtime dev; tidak mengubah runtime biasa. Source #5 tidak direplay | — |

## Analisis dan Implementation Plan setelah pull main — 12 Oktober 2026

Bagian ini mencatat implementasi setelah USER meminta melanjutkan pekerjaan. Producer, contract, regression test, dan dokumentasi telah diperbarui di working tree CoreERP; belum dirilis ke runtime dev. Test memakai PostgreSQL sementara tanpa volume, terpisah dari database dev.

### Kondisi source dan bukti

- Pull `origin/main` berhasil secara fast-forward dari `666b3055` ke `f91bfceb`, tanpa konflik. Checkout sama dengan `origin/main`; perubahan lokal sebelumnya tetap ada.
- Dua commit baru adalah `3d786f86` (menu Penyusutan aset kembali tersedia) dan `f91bfceb` (Shell, FactBox, lampiran/catatan aset). Keduanya tidak menambahkan peta grup ke producer finance. Pull juga membawa migration catatan dokumen; keberadaan berkas itu bukan bukti migration telah dijalankan.
- Pada HEAD main, producer belum menyertakan `details.journal_groups`. Working tree sekarang mengirim kode/nama group dan nomor baris beban/akumulasi; perubahan belum tersedia di runtime dev.
- Bukti PTDI terbaru di register E2E BO: ASTA00036 masuk otomatis ke draft rekap, satu grup/satu aset/Rp833,33. Payload tidak membawa `journal_groups`; fallback satu grup bekerja. Tujuan laporan/rekap satu grup telah tercapai, sedangkan pemetaan multi-grup masih membutuhkan source baru.
- Nomor baris kandidat dibentuk mengikuti urutan debit kemudian kredit. `PostingPublisher::journalLines()` menetapkan nomor dari indeks masukan tanpa mengubah urutannya, dan menyalin `details` ke payload. Pola ini sesuai untuk mengaitkan grup ke baris jurnal; akun, nominal, dan dimensi tetap harus diambil BO dari `journal_lines`.
- Hitungan masa manfaat kini mengecualikan `cancelled_at`, mengikuti `DepreciationController` dan `BookPeriods`. Regression membuktikan sisa umur tetap 47 dari 48 bulan setelah periode lama dibatalkan dan diusulkan ulang.
- Penyebab fixture hierarchy ditemukan: runner test memakai `APP_ENV=local`, sehingga request web mendapat CSRF 419. Helper hanya memeriksa error validasi dan kemudian gagal mencari hierarchy. Runner kini memakai `APP_ENV=testing`; helper juga menegaskan redirect sukses dan mencari hierarchy pada tenant yang benar. Tidak ada model atau migration baru.

### Dokumentasi dan pola yang menjadi acuan

Indeks `docs/dev/README.md` dan struktur dokumentasi ditinjau untuk menentukan aturan aktif. Pembacaan terperinci difokuskan pada standar module, API/integrasi, scope tenant/organisasi, pengujian, finance feed, rilis, serta dokumentasi aset mengenai kontrak, integrasi Core, penyusutan dan pola penulisan fitur.

| Area | Acuan | Dampak pada implementasi |
| --- | --- | --- |
| Pemilik kode/data | `docs/dev/02-module-standard.md`, `docs/dev/04-api-and-integration.md` | Metadata grup dibentuk module aset; penerbit Core tetap dipanggil melalui `App\Platform\Modules\Contracts\PostingFeed`. Tidak membuat akses langsung module ke tabel finance Core |
| Tenant dan dimensi | `docs/dev/08-query-scopes-and-schema.md`, dokumentasi proses penyusutan | Scope existing dan snapshot unit pada periode dipertahankan. Beban per grup/department; akumulasi per grup/business unit |
| Snapshot dan pengulangan | `docs/dev/34-feed-posting-finance.md` | Posting lama tidak diperkaya ulang. Memanggil publish dengan ID sama mengembalikan posting lama; verifikasi membutuhkan posting ID baru |
| Kontrak | Skill `coreerp-architecture`, schema FinancePosting, `docs/dev/04-api-and-integration.md` | `details` pada v1 existing adalah objek terbuka. Kandidat metadata harus dinilai sebagai penggunaan ruang ekstensi yang sudah dijanjikan; jangan menambah field wajib envelope, mengganti semantik field existing, atau mempersempit bentuk yang dahulu valid. Jika membutuhkan perubahan janji v1, gunakan versi baru berdampingan |
| Pengujian | `docs/dev/25-standar-penjaga-dan-pengujian.md`, AGENTS.md, workflow CI | Regression memakai publisher asli, mengecek setiap nomor baris dan subtotal group, serta periode batal. Suite penuh mengikuti tahap paralel lalu serial |
| Dokumentasi | Skill `coreerp-docs`, `docs/apps/management-aset/pola-dokumen.md` | Aturan dan alasan masuk halaman fitur existing; bundle disusun dari sumber, bukan diedit tangan. Bedakan bukti test dan rencana |
| Rilis | `docs/dev/29-alur-rilis-server-klien.md` | Merge/pull main tidak men-deploy. Dev menggunakan rilis/image yang dihasilkan alur resmi; audit SHA/digest runtime sebelum E2E |

Ada catatan lama yang tidak boleh dijadikan instruksi implementasi: K-24 pada README backlog masih menyebut kode grup/buku diketik manual, sedangkan main sudah memuat perubahan nomor otomatis (`ef1271db`). Petunjuk test pada halaman pengujian module juga perlu diselaraskan dengan perintah paralel/serial terbaru di AGENTS.md. Koreksi dokumen itu mengikuti perilaku dan keputusan terbaru, bukan mengubah perilaku aplikasi agar cocok dengan teks lama.

Lampiran penerimaan/PO adalah task terpisah dari peta penyusutan. Main terbaru sudah mempunyai fasilitas lampiran/catatan Core. Bila task gambar dilanjutkan, gunakan `AttachmentRecordTypes` dan hak record induk; akses eksternal BO tetap perlu contract terotorisasi tersendiri. Ketersediaan lampiran dengan sesi UI belum membuktikan BO dapat mengunduhnya memakai klien integrasi.

### Hasil implementasi dan validasi — 12 Oktober 2026

- Producer membentuk `details.journal_groups` dari agregasi jurnal yang sama, mengaitkan kode/nama group dengan nomor baris beban dan akumulasi. Nomor akun, nilai, dan dimensi tetap dibaca dari `journal_lines`.
- Metadata ditambahkan secara opsional pada objek `details` terbuka di contract FinancePosting v1. Bundle check dan coverage 23 route lulus.
- `DepreciationPostingTest` lulus seluruhnya: 14 test/912 assertion. Regression baru mencakup dua group di satu buku, dua department, cakupan nomor baris tepat satu kali, subtotal per group, dan proposal ulang setelah pembatalan periode.
- Suite paralel Core lulus: 2.386 test, 23.622 assertion, 5 skip. Pint check tiga file PHP dan PHPStan producer lulus.
- VitePress build lulus tanpa dead link. Ada peringatan lama syntax highlight untuk `al` dan ukuran chunk; build tetap selesai.
- Suite serial lulus: 52 test pass, 5 skip karena klien `pg_dump`/`pg_restore` tidak berada di PATH test. Diagnostik terpisah saat kedua klien tersedia menunjukkan `pg_restore` 17 ke server PostgreSQL 16 menolak `SET transaction_timeout`; ini persoalan kesesuaian versi pada test lokal, bukan producer penyusutan.
- Perubahan belum dirilis ke runtime dev; source PTDI baru dan rekap BO multi-group masih menunggu CORE-GRP-DEV serta E2E.

### Implementation Plan

1. Siapkan branch dari main terbaru dengan mempertahankan perubahan lokal. Inventarisasi diff yang sudah ada agar pemetaan grup dan rincian laporan memiliki kriteria terima masing-masing; jangan menghapus perubahan sebelumnya hanya untuk memperkecil diff.
2. Periksa lingkungan pengujian: source yang dimuat, autoload, konfigurasi test dan database test terpisah. Jalankan regression terarah dan tangkap exception serta respons pembuatan hierarchy. Perbaiki hanya penyebab yang terbukti; jangan menambahkan model atau migration berdasarkan pesan lama.
3. Selesaikan producer peta grup mengikuti agregasi existing. Setiap nomor baris harus masuk tepat satu grup dan sisi yang benar. Satu grup dapat mempunyai beberapa baris beban/akumulasi karena dimensi berbeda. Gunakan kode/nama grup dari data tenant; hitungan uang tetap BigDecimal dan aturan presisi existing.
4. Tinjau pengayaan rincian aset yang sudah ada. Selaraskan hitungan periode dengan aturan pembatalan/proposal ulang existing, lalu buktikan sisa umur pada skenario batal dan hitung ulang. Jangan mengubah saldo buku atau rumus penyusutan untuk sekadar menambahkan metadata.
5. Selesaikan keputusan kompatibilitas contract secara tertulis: metadata opsional di ruang `details` yang sudah terbuka, semua nilai jurnal tetap dari `journal_lines`, dan konsumen lama boleh mengabaikannya. Review pembatasan schema kandidat agar tidak mempersempit payload valid existing. Bila hasil review menunjukkan perubahan kontrak v1, rencanakan v2 berdampingan sebelum implementasi wire change.
6. Perluas regression yang bermakna: dua grup memakai satu buku dan akun sama; satu grup memakai beberapa department; seluruh nomor baris tercakup tepat sekali; subtotal per grup sama dengan aset dan debit/kredit grup; source lama tetap immutable; retry tidak menambah posting; scope unit, reversal dan pembatalan tetap benar. Test harus mencapai assertion melalui publisher Core asli.
7. Jalankan Pint check pada file terkait, test DepreciationPosting dan test finance/DocsPortal yang terdampak, serta penjaga batas module. Bundel dari `apps/core/contracts/internal/` dengan `contracts/bundle.py`, lalu `--check` dan `check-contract-coverage.py`. Sebelum menyatakan siap merge, jalankan gate CI yang diwajibkan repo dengan suite paralel dan serial.
8. Perbarui halaman proses penyusutan, panduan finance feed dan checklist existing sesuai perilaku final. Catat batas bukti lokal/remote. Jalankan build dokumentasi dan periksa tautan/encoding.
9. Setelah kode tervalidasi, verifikasi runtime lokal dari stack erp-dev aktual. Untuk remote PTDI, gunakan alur rilis/dev resmi dan cocokkan identitas source/image yang berjalan. Task ini belum melakukan deployment.
10. Buat skenario PTDI baru dua grup setelah runtime benar. USER melakukan finalisasi dan Post transaksi sesuai batas tindakan finansial yang telah berlaku; AGENT memeriksa payload baru dan draft rekap BO. Tahap ini berhenti di laporan/rekap; posting voucher grup merupakan task lanjutan tersendiri.

### Checklist kelanjutan

| ID | Task | Penanggung jawab | Status | Penghalang/dependensi | Tanggal selesai |
| --- | --- | --- | --- | --- | --- |
| CORE-GRP-AUDIT | Pull main, audit aturan dan kandidat perubahan, susun analisis/plan | AGENT | SELESAI | Pemeriksaan source/dokumentasi; bukan hasil test runtime | 2026-10-12 |
| CORE-GRP-FIXTURE | Diagnosis kegagalan setup hierarchy pada lingkungan test | AGENT | SELESAI | `APP_ENV=local` memicu CSRF 419; runner testing dan assert hierarchy tenant memperjelas fixture | 2026-10-12 |
| CORE-GRP-PRODUCER | Selesaikan mapping grup dan hitungan masa manfaat | AGENT | SELESAI | Metadata per group; periode `cancelled_at` tidak ikut dihitung; regression lulus | 2026-10-12 |
| CORE-GRP-CONTRACT | Tetapkan kompatibilitas metadata dan selaraskan contract tertulis/bundle | AGENT | SELESAI | Metadata opsional di `details` v1 terbuka; bundle check dan coverage lulus | 2026-10-12 |
| CORE-GRP-VERIFY | Buktikan regression, batas module, contract dan gate CI | AGENT | SELESAI | DepreciationPostingTest 14/14; suite paralel 2.391 pass/5 skip; serial 52 pass/5 skip; Pint, PHPStan, bundle, coverage, dan build docs lulus | 2026-10-12 |
| CORE-GRP-DOCS | Selaraskan dokumentasi fitur/checklist dan periksa build docs | AGENT | SELESAI | Halaman penyusutan dan finance feed diselaraskan; VitePress build tanpa dead link | 2026-10-12 |
| CORE-GRP-DEV | Verifikasi source/image lokal dan siapkan rilis dev yang dapat ditinjau | AGENT | TODO | CORE-GRP-VERIFY dan CORE-GRP-DOCS; akses/alur rilis diperiksa saat tahap ini dimulai | — |
| CORE-GRP-E2E-PREP | Siapkan source uji PTDI baru dua grup dan periksa pratinjau | AGENT | TODO | Runtime dev sudah memuat implementasi yang tervalidasi | — |
| CORE-GRP-E2E-POST | Finalisasi dan Post source uji baru di CoreERP dev | USER | MENUNGGU CORE-GRP-E2E-PREP | Task finansial final setelah pratinjau bersih | — |
| CORE-GRP-E2E-AUDIT | Audit payload baru, pemetaan dua grup dan rekap draft BO | AGENT | MENUNGGU CORE-GRP-E2E-POST | Bukti masuk register E2E BO existing | — |

Pemilik perubahan source adalah CoreERP: producer module aset, contract dan dokumentasinya. BO sudah mempunyai mapper; tahap berikutnya memverifikasi konsumsi payload dan rekap. Jika verifikasi menemukan defect BO, catat perubahan BO sebagai task tersendiri berdasarkan bukti.


## Keputusan alur draft jurnal BO — 13 Oktober 2026

Permintaan USER: Rekapitulasi memilih akun dan mengirim satu voucher per grup ke draft Jurnal Transaksi. Edit akun dan batal draft dilakukan di Jurnal Transaksi; posting final baru memasukkan jurnal ke Jurnal Umum dan Buku Besar.

Audit CoreERP: kontrak ack hanya menerima posted/rejected. Pull tidak membukukan jurnal dan tidak mengakhiri source. Selama draft, edit akun, atau batal draft yang masih dapat dikerjakan ulang, BO tidak mengirim ack final; source CoreERP tetap pending. Batal draft bukan rejected. BO harus mempertahankan identitas sumber/grup dan mencegah polling berulang membuat draft ganda. Ack posted beserta referensi voucher baru dikirim setelah seluruh grup pada source dibukukan. Payload sumber CoreERP tetap immutable; akun yang dipilih BO disimpan di BO. Koreksi nominal sumber dan pembalikan setelah pembukuan merupakan alur berbeda.

Implementation Plan kelanjutan: (1) pertahankan producer/kontrak grup yang sudah selesai lokal; (2) rilis dan audit payload source baru menurut CORE-GRP-DEV/E2E; (3) audit mekanisme draft/posted pada BO sebelum merancang perubahan Jurnal Transaksi, edit/batal dan ack final. Tidak diperlukan status draft/cancelled baru pada API CoreERP untuk cakupan ini.

| ID | Task | Penanggung jawab | Status | Penghalang/dependensi | Tanggal selesai |
| --- | --- | --- | --- | --- | --- |
| CORE-GRP-DRAFT-AUDIT | Periksa dukungan payload dan ack untuk draft/edit/batal sebelum pembukuan BO | AGENT | SELESAI | Audit source dan kontrak; bukan bukti runtime dev | 2026-10-13 |
| BO-GRP-DRAFT-PLAN | Audit draft jurnal existing dan susun plan perubahan BO | AGENT | TODO | Mengikuti keputusan USER; source BO perlu diperiksa sebelum perubahan kode | — |

Pemilik tahap draft/edit/batal/posting GL adalah BO. CoreERP menyediakan sumber grup dan menerima konfirmasi pembukuan final. Implementasi producer CoreERP tetap belum dirilis ke dev.

## Bukti dokumen perolehan untuk BO — 14 Oktober 2026

Status terkini: **TAHAP AWAL AKTIF DI LOKAL; REGRESSION BACKEND LULUS; VERIFIKASI VISUAL MENUNGGU**. USER meminta daftar penyesuaian, lalu menyetujui nomor PO manual dan empat migration/pengujian lokal. Cakupan yang sudah diimplementasikan dan pekerjaan lanjut dijelaskan pada bagian implementasi lokal di bawah. Persetujuan lokal tidak menjadi otorisasi penerapan server dev remote.

### Masalah dan dasar existing

BO membutuhkan scan/foto PO, invoice, atau bukti penerimaan untuk meninjau aset pada Rekapitulasi Penyusutan dan Jurnal Transaksi. Foto fisik aset merupakan fungsi berbeda dari dokumen perolehan.

Audit source: `modules/apperp/management-aset/src/Services/AssetAttachments.php` sudah mendaftarkan `aset_tr_penerimaan_aset` dan model baris penerimaan pada layanan lampiran Core. Lampiran dapat dikaitkan ke dokumen atau barisnya. `PenerimaanDetailPage.tsx` belum menampilkan komponen unggah lampiran dalam audit ini. `PembuatAset.php` menyimpan `penerimaan_aset_id` dan `penerimaan_aset_detail_id` pada aset. Data Penerimaan mempunyai referensi invoice vendor dan hubungan baris ke permintaan pembelian; nomor permintaan pembelian tidak boleh dianggap sebagai nomor PO aktual. Sumber nomor PO aktual masih perlu ditetapkan. Temuan ini berasal dari source lokal, bukan verifikasi UI/runtime dev atau akses lampiran melalui klien BO.

### Rekomendasi hubungan dokumen dan tahap proses

- Masukkan referensi PO dan unggah bukti ketika Penerimaan sudah tersimpan sebagai draf, sebelum Selesaikan penerimaan. Gunakan ID dokumen yang sudah tersedia untuk lampiran.
- Lampiran pada header Penerimaan berlaku bagi seluruh aset hasil dokumen itu; lampiran pada baris berlaku bagi aset yang lahir dari baris tersebut. Satu baris dapat melahirkan beberapa aset. Simpan satu berkas dan relasinya; jangan menyalin berkas untuk setiap aset/grup/bulan.
- Penyusutan menelusuri bukti melalui aset ke Penerimaan/baris asal. Bukti tidak diunggah ulang pada proposal, finalisasi, atau Post penyusutan.
- Satu voucher BO tetap per entitas legal + bulan + buku + mata uang + grup. Daftar bukti grup adalah gabungan bukti aset penyusutan di grup tersebut, dengan dokumen yang sama ditampilkan sekali. Satu PO bisa terkait beberapa grup dan satu grup bisa memuat beberapa PO.
- BO menampilkan referensi dan akses baca-saja pada Detail Grup Rekapitulasi serta Detail/Edit Jurnal Transaksi. Bukti tidak mengubah nominal, akun, dimensi, jumlah voucher, atau aturan ack.
- Rekomendasi awal: dokumen opsional; kosong ditampilkan dengan jelas. Kewajiban upload, jenis/ukuran/jumlah berkas mengikuti keputusan produk dan fasilitas Core existing, bukan nilai baru yang ditetapkan diam-diam.

### Implementation Plan

1. Audit fasilitas lampiran, hak akses, referensi PO, dan relasi Penerimaan/aset; tetapkan pemilik data dan contract sebelum perubahan kode. Ikuti discovery/standar module yang berlaku saat implementasi.
2. Tetapkan referensi PO aktual. Jika belum ada objek PO yang sesuai, putuskan referensi manual atau relasi ke sumber PO; jangan membuat modul PO baru sebagai efek samping fitur lampiran.
3. Pasang unggah/preview/download dokumen pada Penerimaan tersimpan memakai fasilitas Core; dukung lampiran header dan baris. Tentukan kebijakan menambah/mengarsipkan lampiran sesudah penerimaan selesai dan setelah bukti dirujuk posting.
4. Sediakan penelusuran bukti dari aset, termasuk beberapa aset dari satu baris, beberapa dokumen per aset, dan aset saldo awal/tanpa Penerimaan asal yang lengkap. Tidak adanya bukti tidak boleh diganti dengan bukti aset lain.
5. Lengkapi contract Finance Feed Penerimaan dan Penyusutan dengan identitas Penerimaan/baris/PO dan identitas metadata dokumen yang diperlukan BO. Nama field final mengikuti audit contract. Snapshot sumber yang sudah terbit tetap immutable; putuskan bagaimana lampiran yang ditambahkan kemudian dapat ditemukan tanpa mengubah posting lama.
6. Sediakan akses list/preview/download yang terautentikasi untuk klien integrasi BO, mengikuti scope tenant/entity dan izin record. Akses UI CoreERP tidak dianggap cukup. Jangan menyimpan token atau URL sementara sebagai referensi permanen, dan jangan mengirim binary/base64 dalam setiap posting.
7. Implementasikan tampilan BO sebagai pekerjaan terpisah setelah contract disepakati. Gunakan satu sumber data dokumen untuk kedua halaman, pertahankan hubungan aset/sumber, dan cegah duplikasi tampilan berkas.
8. Verifikasi Penerimaan → aset → Penyusutan → rekap → draft jurnal BO, termasuk banyak aset/grup/PO, dokumen kosong, berkas diarsipkan, retry, serta penolakan akses lintas tenant/entity. Bukti E2E masuk register BO existing. Jika perlu migration, siapkan SQL/perubahan tepat dan minta persetujuan sebelum penerapan database.

### Checklist kelanjutan

| ID | Task / kriteria selesai | Penanggung jawab | Status | Dependensi / penghalang | Tanggal selesai |
| --- | --- | --- | --- | --- | --- |
| CORE-DOC-NOTE | Catat hasil audit source awal, lokasi proses, scope CoreERP/BO dan Implementation Plan | AGENT | SELESAI | Dokumen perencanaan; bukan implementasi/runtime | 2026-10-14 |
| CORE-DOC-AUDIT | Audit rinci sumber PO, fasilitas lampiran dan hak record; selaraskan CORE-01/05/06/07 lama dengan bukti terkini | AGENT | BELUM DIMULAI | Mengikuti rencana ini | — |
| CORE-DOC-DESIGN | Siapkan keputusan sumber PO, kewajiban upload, lifecycle lampiran setelah selesai, dan penemuan lampiran tambahan setelah posting | AGENT | BELUM DIMULAI | CORE-DOC-AUDIT | — |
| CORE-DOC-DECISION | Putuskan pilihan material yang diajukan CORE-DOC-DESIGN | USER | MENUNGGU RANCANGAN | AGENT menyediakan pilihan konkret; bukan permintaan parameter ulang saat ini | — |
| CORE-DOC-UI | Unggah/preview dokumen header dan baris pada Penerimaan tersimpan | AGENT | DIRENCANAKAN | CORE-DOC-DESIGN/DECISION; reuse layanan Core | — |
| CORE-DOC-TRACE | Hubungkan penelusuran bukti dari aset ke Penerimaan/baris/PO | AGENT | DIRENCANAKAN | CORE-DOC-DESIGN/DECISION; relasi asal aset existing | — |
| CORE-DOC-CONTRACT | Tetapkan metadata referensi Penerimaan/PO/lampiran untuk acquisition dan depreciation; pertahankan snapshot lama | AGENT | DIRENCANAKAN | CORE-DOC-TRACE; kesepakatan consumer BO | — |
| CORE-DOC-ACCESS | Sediakan akses dokumen terautentikasi untuk klien BO beserta penjagaan akses | AGENT | DIRENCANAKAN | CORE-DOC-CONTRACT; kebijakan scope/arsip | — |
| BO-DOC-VIEW | Tampilkan referensi dan dokumen pendukung pada rincian aset/grup di Rekapitulasi dan Jurnal Transaksi BO | AGENT | DIRENCANAKAN · PEMILIK BO | CORE-DOC-CONTRACT/ACCESS; bukan perubahan kode CoreERP | — |
| CORE-DOC-VERIFY | Verifikasi contract/source dan integrasi dokumen bersama BO; catat hasil dan batas di register E2E BO | AGENT | DIRENCANAKAN | CORE-DOC-UI/TRACE/CONTRACT/ACCESS dan BO-DOC-VIEW | — |

Pemilik perubahan CoreERP: UI Penerimaan, referensi PO, penelusuran bukti, producer/contract dan akses lampiran. Pemilik perubahan BO: tampilan referensi/preview pada kedua halaman. Rencana ini tidak mengharuskan perubahan status ack atau mengulang implementasi voucher grup yang sudah ada. Belum ada perubahan aplikasi, database, deployment, atau transaksi dari pencatatan ini.

### Implementasi lokal tahap awal — 14 Oktober 2026

USER memilih nomor PO manual pada Penerimaan dan meminta implementasi/uji di CoreERP lokal
sebelum BO. Source tahap awal disiapkan pada working tree: PO opsional 80 karakter, panel
lampiran header pada Penerimaan tersimpan, tautan Penerimaan asal dari detail aset, serta
referensi receipt/PO pada metadata acquisition dan depreciation. Snapshot posting lama
tidak diperbarui. Upload khusus baris belum diimplementasikan: controller draf saat ini
menghapus dan membuat ulang baris, sehingga lampiran baris memerlukan hubungan stabil
sebelum UI upload ditawarkan. API unduh klien BO juga belum dibuat; metadata bukan izin akses.

Dasar keputusan: [Document management Dynamics 365](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/organization-administration/configure-document-management)
menempatkan dokumen pada record, dan [Acquire assets through procurement](https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/acquire-assets-procurement)
menghubungkan perolehan aset dengan dokumen pembelian. Penerapan CoreERP diadaptasi melalui
Penerimaan yang sudah ada; referensi manual tidak membangun transaksi PO baru.

| Capability | Keputusan tahap awal | Dasar | Persetujuan |
| --- | --- | --- | --- |
| Resource/lifecycle | Penerimaan existing, PO diubah hanya saat draf; lampiran mengikuti izin record existing | Tidak menambah lifecycle finansial | Permintaan implementasi lokal dan pilihan PO manual USER |
| Scope | Tenant, legal entity dan unit penanggung jawab Penerimaan existing; join asal penyusutan menyaring tenant/entity | Standar module dan policy existing | Tidak memperluas scope |
| Security | Layanan lampiran sesi Core existing; klien integrasi BO belum mendapat akses dokumen | Contract/izin eksternal belum disepakati | Endpoint/scope baru masih perlu rancangan |
| Number reference | Tidak diperlukan untuk nomor PO manual | Nomor berasal dari dokumen eksternal | USER memilih manual |
| Workflow/SoD | Tidak menambah workflow; finalisasi/Posting unchanged | Bukti pendukung tidak membukukan transaksi | Mengikuti alur existing |
| API/attachment/audit | Metadata opsional di details terbuka, layanan lampiran existing, migration satu kolom nullable | Tidak mengganti payload/jurnal sumber | Penerapan database menunggu persetujuan tepat |

Preflight baca-saja pada `erp-core-db-1 / core_erp / public` menemukan tiga migration main
existing belum diterapkan (allow_straight_line, jenis/kondisi, indeks lokasi aktif), selain
migration PO baru. [Preview SQL](LOCAL_DOCUMENTS_SCHEMA_REVIEW.sql) dihasilkan dengan
Laravel connection pretend, tanpa DDL/DML. Penerapan empat migration dan penggunaan schema
`core_erp_test` untuk regression telah diajukan ke USER; tidak menjalankan start.ps1 yang
akan melakukan migration/seed lain otomatis.

| ID | Task | Penanggung jawab | Status | Dependensi/penghalang | Tanggal selesai |
| --- | --- | --- | --- | --- | --- |
| CORE-DOC-LOCAL-PO-CHOICE | Pilih nomor PO manual pada Penerimaan | USER | SELESAI | Jawaban langsung pada chat | 2026-10-14 |
| CORE-DOC-LOCAL-SOURCE | Siapkan UI PO/lampiran header, tautan asal aset, metadata receipt dan contract | AGENT | SELESAI TAHAP AWAL | Runtime lokal aktif; belum mencakup upload khusus baris atau API unduh klien BO | 2026-10-14 |
| CORE-DOC-LOCAL-STATIC | Periksa tipe UI, format PHP, contract bundle dan coverage route | AGENT | SELESAI | TypeScript, ESLint, Prettier lulus; Pint 8 file; bundle Core/module sinkron; 23 route image tercakup; build image aplikasi lulus | 2026-10-14 |
| CORE-DOC-LOCAL-SQL-APPROVAL | Persetujuan empat migration lokal dan regression pada database test terpisah | USER | SELESAI | Jawaban Setuju, terapkan lokal dan uji pada chat | 2026-10-14 |
| CORE-DOC-LOCAL-MIGRATE | Terapkan empat migration yang disetujui dan verifikasi metadata lokal | AGENT | SELESAI | erp-core-db-1/core_erp/public; empat nama tercatat dan kolom PO ada | 2026-10-14 |
| CORE-DOC-LOCAL-RUNTIME | Recreate aplikasi, worker dan scheduler dari image perubahan lokal | AGENT | SELESAI | Health HTTP200; DB runtime core_erp/kolom PO ada; bundle AsetPage-CYdj-T1B.js HTTP200 memuat UI PO/lampiran | 2026-10-14 |
| CORE-DOC-LOCAL-TEST | Jalankan regression Penerimaan, acquisition, depreciation serta penjagaan akses lampiran | AGENT | SELESAI | 53 skenario tercakup: 50 pass run awal, dua test baru diperbaiki lalu pass 46 assertion, satu test tambahan bukti Penerimaan pass 11 assertion. core_erp_test terpisah | 2026-10-14 |
| CORE-DOC-LOCAL-UI | Verifikasi interaksi simpan PO, upload/preview pada UI lokal | AGENT | TERHALANG ALAT UI | cua_repl dan node_repl gagal memulai kernel; backend tests bukan bukti interaksi visual | — |
| CORE-DOC-LOCAL-UI-USER | Buka draf Penerimaan lokal, isi PO/Simpan, unggah dan buka dokumen pendukung; kirim nomor Penerimaan serta hasil non-rahasia | USER | MENUNGGU TINDAKAN | Handoff karena alat UI AGENT tidak tersedia; tidak perlu Selesaikan penerimaan untuk cek draf | — |
| CORE-DOC-LINE-LIFECYCLE | Rancang hubungan baris stabil saat edit/arsip sebelum upload khusus baris | AGENT | BELUM DIMULAI | Temuan penggantian seluruh baris pada controller Penerimaan | — |
| CORE-DOC-ACCESS | Rancang akses dokumen untuk klien BO dan policy lampiran setelah posting | AGENT | BELUM DIIMPLEMENTASIKAN | Tahap berikutnya setelah CoreERP lokal siap; bukan sekadar menaruh URL di feed | — |

Tidak ada klaim selesai untuk download BO, tampilan dokumen BO, atau bukti E2E penuh dari tahap source ini.

Batas lingkungan: build dokumentasi belum selesai; pemasangan dependency docs berhenti ENOSPC.
Folder node_modules root dan docs yang dibuat AGENT pada turn ini dibersihkan setelah checks
source selesai, menyisakan sekitar 0,43 GB pada C:. Tidak menghapus source, volume database,
container aktif, atau cache/data pengguna untuk mengatasi keterbatasan ini. Image aplikasi
hasil build tetap tersedia; runtime aktif belum direcreate dan migration belum dijalankan.

Pembaruan setelah persetujuan USER: empat migration diterapkan melalui module:migrate dan
runtime aplikasi/worker/scheduler direcreate dari image perubahan. Bundle contract terakhir
diselaraskan sebagai layer tambahan pada image aplikasi yang sudah dibangun, tanpa perubahan
PHP/UI baru. Regression memakai PHPUnit terarah pada image test existing dengan source
current yang di-mount, APP_ENV=testing dan DB_TEST_DATABASE=core_erp_test eksplisit. Dua masalah
test awal adalah argumen fixture jumlah yang kosong dan assertSame atas urutan key JSONB;
perbaikannya hanya pada test, kedua skenario kemudian lulus. Test tambahan membuktikan PDF
header Penerimaan bertahan setelah edit draf, download untuk pembaca berhasil dan upload
tanpa izin ditolak. Tidak membuat transaksi aplikasi lokal/BO atau melakukan Post final.
Verifikasi UI gagal karena kedua alat browser/node mengembalikan failed to write kernel
assets: The system cannot find the path specified, termasuk setelah reset. Handoff di atas
memerlukan operator UI; bukan kekurangan akses database atau izin implementasi.

### Upload lokal PNRA00028 — 14 Oktober 2026

Screenshot USER menunjukkan modal upload gagal dan 0 berkas tersimpan. Log CoreERP menunjukkan
error saat membentuk S3Client karena konfigurasi wajib belum ada. `coreerp.attachments.disk`
memilih `s3` secara bawaan, sementara Compose belum meneruskan pilihan disk ke container.
AGENT menambahkan `COREERP_ATTACHMENT_DISK=local` pada `.env` development dan memetakan variabel
itu pada `erp-dev/compose.yaml`; default s3 untuk environment lain tetap sama. App, worker, dan
scheduler lokal direcreate. Runtime memastikan disk `local`, root writable, dan health200.
Storage Core berhasil put/read/delete berkas sementara. Regression lampiran lulus 1 test/11
assertion: PDF header bertahan setelah edit draf, unduh oleh pembaca berhasil, upload tanpa
izin ditolak. SELECT memastikan PNRA00028 masih draft dan attachment count0. Otomasi browser
masih gagal memulai kernel; jadi upload aktual dari modal belum dicoba lagi. CORE-DOC-LOCAL-UI-USER
sekarang menunjuk reload receipt yang sama lalu unggah ulang, tanpa Selesaikan penerimaan.

Pembaruan setelah screenshot: regression tambahan `test_penerimaan_dapat_menyimpan_lampiran_ke_disk_lokal_yang_dikonfigurasi`
menjalankan POST upload, list, dan GET download stream pada disk `local` konfigurasi nyata
(bukan fake). Berkas berisi sama dengan fixture PDF dan dihapus sesudah test. Hasil lulus
1 test/10 assertion di core_erp_test. Gabungan 54 kasus regression punya hasil akhir lulus:
50 pada run pertama, dua yang gagal test diperbaiki lalu rerun lulus46 assertion, dan dua
test lampiran tambahan lulus1/11 serta1/10 assertion. Berkas receipt pengguna belum terunggah;
otomasi browser masih terhalang kernel.
