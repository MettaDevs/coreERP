# Keputusan desain pembanding stack

Penilai same-model; 3 November 2026. Penilaian hanya membaca kedua grounding, sketsa A/B, `.audit/native-stack/PLAN.md`, dan `experiments/stack-comparison/profile.json`. Tidak menjalankan aplikasi, mengakses database/server, atau mengukur performa. Seluruh parity dan kapasitas masih NOT VERIFIED.

Pilih **B sebagai dasar**, dengan graft kecil dari A. Keunggulannya tipis: B memberi tiap bahasa kepemilikan domain, transaksi, dan SQL melalui jalur masuk yang pendek. A lebih lengkap pada audit query dan normalisasi, tetapi katalog executable beserta generator accessor menambah proyek infrastruktur sebelum alur pertama terbukti.

Skor 1–5 menilai sketsa saat ini, bukan implementasi yang sudah teruji.

| Kriteria | A | B | Alasan |
| --- | ---: | ---: | --- |
| Perilaku PHP aktual dan operasi native tanpa PHP | 5 | 4 | Keduanya menjaga transaksi, money dan replay. A lebih eksplisit tentang DELETE detail fisik dan policy role pada SHA ini; sketsa API/main B juga belum memasukkan preview lengkap. |
| Semua SQL, middleware, trigger, query gagal, COMMIT | 5 | 4 | A merinci rekonsiliasi log PostgreSQL, nested work dan deferred COMMIT; B sudah memetakan obligation tetapi logging nested masih bersyarat. |
| R&D terbatas dan kepemilikan native | 3 | 4 | A membutuhkan katalog dan generator lintas bahasa; B dapat membuktikan satu irisan vertikal sebelum memperluas implementasi. Keduanya tetap pekerjaan besar. |
| Fairness tanpa kerja SQL buatan | 4 | 4 | A mengontrol bentuk query, B jujur mengukur stack beserta strategi query. Keduanya perlu bukti trace PHP; count yang dipaksa sama bukan bukti fairness. |
| Oracle independen, gagal tertutup, lima repeat | 5 | 5 | Keduanya memakai oracle SQL terpisah, negative self-test, snapshot bersih dan lima putaran; B mengekspresikan gate melalui VerifiedPlan. Ini masih rencana. |
| Ekstensi sederhana dan call chain pendek | 3 | 5 | B cukup adapter → pemilik domain → driver. A menambah katalog/accessor yang harus dipahami serta dijaga bersamaan. |
| Total | 25 | 26 | B menang melalui permukaan internal yang lebih kecil. |

## Graft kecil dan koreksi sebelum implementasi

1. Ambil ketelitian audit A: collector dari sebelum environment/session sampai finalization/termination terkait; tangkap kedua koneksi, transaksi/savepoint, statement gagal, rollback dan COMMIT. Bedakan top-level round trip dari SQL nested trigger/function. Schema digest dan delta tabel membuktikan efek, tetapi tidak sendiri membuktikan semua query sudah dihitung. Jika rekonsiliasi nested/failed/commit belum ada, query-accounting gagal dan gate server tetap tertutup.
2. Ambil normalisasi A: pertahankan tipe, null/absent, urutan, status, ETag, Location, field whitelist dan error; petakan ID secara bijektif melalui identitas bisnis, periksa timestamp/format, dan uji cursor round-trip. Oracle tidak mengimpor SQL executable, helper money, atau response builder kandidat.
3. Katalog penuh A ditolak. Leaf SQL parameterized yang memang identik boleh memakai satu sumber bernama dan diaudit; masing-masing bahasa tetap memiliki binding, driver dan transaksi. Ini reuse teks terbatas, bukan replay trace atau executor workflow bersama. Tidak membuat generator/accessor untuk setiap kombinasi stack/owner sebelum kebutuhannya terbukti.
4. Pakai profil tersimpan sebagai kontrak: tambahkan ke B urutan receipt-list → create → show-draft → replace-lines → preview → complete → show-completed → receipt-assets → asset-list → asset-show. Preview perlu query domain sendiri. Iterate asset-show untuk seluruh aset yang dibuat. Lookup setup dan halaman lanjutan tetap ada pada coverage conformance.
5. B memakai Exponential(mean=5s), sedangkan `profile.json` menetapkan Uniform(1,9), start Uniform(0,10), mean 5. Pertahankan profil tersimpan; jangan mengubah distribusi diam-diam. Gunakan satu lokasi `experiments/stack-comparison/`, bukan pohon kedua `experiments/native-stack/` milik B.
6. Jangan merapikan perilaku baseline hanya di native: replay create sebelum validasi, body berubah tetap replay raw header, archived ikut; PATCH menghapus detail fisik; complete ulang 422, kalah claim 409 atau selesai setelah reread 422; If-Match mengalahkan body, version dapat naik dua kali. Policy pada SHA ini tidak join role aktif, meski permission chain melakukannya. Catat gap dan cocokkan respons PHP aktual.

## Alur nyata yang sedang dibandingkan

HTTP login/CSRF → cookie/session sendiri → workspace choice → setiap request memulihkan user, membership, permission dan company/data policy → receipt domain → PostgreSQL asli → decorated HTTP readback. JSON biasa melewati Inertia props, tetapi tetap membayar middleware web/session.

Create memeriksa replay dahulu, kemudian validasi/master/policy/atribut/vendor dan menerbitkan nomor header pada transaksi terpisah. Baru header/details tersimpan. PATCH adalah penggantian dokumen penuh dalam transaksi setelah claim version; caller memakai version dari respons.

Preview menjalankan blocker, money dan evaluasi posting. Completion menghitung extended amount/tax dengan HALF_UP, membagi remainder ke unit awal, mengunci kebutuhan request, claim+ubah receipt, menerbitkan nomor aset, membuat aset/placement/book/ledger/atribut, menjalankan attachment flow, lalu menerbitkan finance posting. Semuanya sesudah claim berada dalam satu transaksi completion; kegagalan posting membatalkannya. Nomor header dari create tetap durable.

Posting pending berarti normalisasi, account mapping, hierarchy→BU/dimension, balanced journal, snapshot, satu finance_postings beserta lines dan event benar-benar tersimpan. Tidak ada HTTP finance delivery atau start approval workflow pada alur normal ini. Readback menambah lookup, live vendor/party, posting status dan cancellation summary; list memakai RecordSearch dan IncrementalList aktual.

## Kerja tersembunyi dengan dampak terbesar

- Fortify fixture tanpa 2FA memeriksa password dua kali; sesi database memakai JSON/base64, ditulis juga pada GET, dapat menjalankan GC. User restoration memasang audit actor melalui SQL set_config. Login dan steady-session wajib dilaporkan terpisah.
- Authority dibaca ulang per request; cookie menyimpan pilihan, bukan izin. Cache precision/policy/directory mengikuti masa hidup PHP dan reset transaksi terkait. Jangan menambahkan query launcher installed/entitled yang tidak dijalankan request JSON baseline.
- Numbering bukan counter lokal: scope/context, lock, allocation refill/reuse, collision, issue/audit, savepoint dan retry merupakan kerja database nyata. Nomor header dan aset memiliki batas commit berbeda.
- Book INSERT menjalankan ledger trigger; audit/version/change-log trigger membaca setting dan dapat menambah banyak SQL. Actor tingkat sesi dan ledger context tingkat transaksi harus benar pada koneksi pool, termasuk error/rollback.
- Attribute definitions dibaca sekalipun input kosong; attachment flow membaca sumber sekalipun tanpa file. Posting tetap mengevaluasi mapping/dimension saat feed disabled. Fixture murah held/manual/nondepreciating tidak mewakili profil pending yang dipilih.

Sumber rincian: bagian Create/Completion/Core services/Trigger pada `grounding-write.md`, serta Autentikasi/Query bisnis/IncrementalList pada `grounding-auth-read.md`. Itu bukti source, belum trace runtime; hash dependency, konfigurasi efektif dan serialization masih harus dibuktikan lokal.

## Batas kesimpulan dan unit pertama

Angka utama B mengukur **stack lengkap**: runtime, framework, oRPC pada Bun, driver, jumlah/bentuk SQL dan cache. Sharing domain TS pada Hono/Elysia mengontrol sebagian perbedaan, bukan mengubahnya menjadi hasil runtime murni.

Untuk mengatribusikan selisih ke runtime, perlu eksperimen terpisah dengan strategi query/rows/locks/batas transaksi dan cache sepadan, disertai CPU/DB/pool/queue evidence. Pengurangan SQL yang sah boleh menjadi keunggulan stack, tetapi tidak boleh diklaim sebagai speedup bahasa. Jangan menambah query palsu untuk menyamakan count; bila strategi dominan berbeda, atribusi runtime INCONCLUSIVE.

Unit berikutnya tetap kecil: clone fixture terisolasi → login dan seluruh main flow PHP aktual → oracle independen dibuktikan merah/hijau → rekonsiliasi query → bekukan kontrak → satu native lengkap → kandidat lain. Native wajib bootstrap/login/beroperasi dengan PHP dimatikan. Tidak ada benchmark server sebelum semua gate lokal profile lulus.

Sesudahnya baru lima repeat per kandidat/host, Latin-square, snapshot/seed berpasangan, dua API replicas dan resource/pool budget sepadan. Laporkan setiap repeat, last-pass/first-fail, sampel per aksi, limiter dan highest point yang lulus kelimanya. dev1 dan dev2 adalah lingkungan hasil terpisah. Closed-loop dengan think time membuktikan kapasitas profil itu, bukan maximum open-loop RPS; mencapai cap tanpa SLO gagal hanya lower bound. Tidak ada klaim adoption atau full parity di luar case matrix yang benar-benar diuji.
