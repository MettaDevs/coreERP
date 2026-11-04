# Handoff implementasi platform dan Faskes

Dokumen paket ini adalah spesifikasi dan backlog, bukan fitur yang sudah berjalan. PR dokumentasinya tidak menjalankan migration, membuat database, mengaktifkan trial, atau mengubah source Faskes.

Hasil inventaris dan kontrak awal tersedia pada [CPDB-01](CPDB-01.md). PF-01 telah merge melalui [PR #376](https://github.com/MettaDevs/coreERP/pull/376), main `6b3cf584`. [CPDB-02](CPDB-02.md) menyiapkan routing, schema target dan role; unit tersebut belum melakukan cutover atau mengganti coupling CPDB-03. PF-02 tetap menunggu CPDB-05.

## Mulai dari urutan yang sudah disepakati

1. Baca [PRD](PRD.md), [organisasi/sharing](ORGANIZATION-AND-SHARING.md), dan [TODO database](TODO.md).
2. Jalankan CPDB-01 untuk inventaris schema, writer, routing, serta integritas yang benar-benar ada pada runtime saat implementasi dimulai.
3. Gunakan PF-01 pada [TODO portal](../metta-platform-portal/TODO.md) sekali untuk memisahkan penciptaan tenant dari instalasi ERP.
4. Lanjutkan CPDB-02 sampai CPDB-05 sebelum PF-02 dan unit portal berikutnya. Jangan menyalin creator atau ownership registry baru.
5. Rilis hanya mode yang lulus capability gate. [TODO sharing](../metta-platform-portal/SHARING-TODO.md) memisahkan SH-01 sampai SH-06 dan acceptance OS/SG/CD.

## Pertahankan keputusan inti

- Database target bernama `core_control_plane`. Control metadata dan Organization Foundation mempunyai domain ownership berbeda tetapi dapat memakai database fisik tersebut pada tahap awal.
- Satu master organisasi/hierarchy, tanpa ERP purchase. ERP/Faskes memakai kontrak dan projection lokal, bukan master writable kedua.
- Produk netral untuk pemerintah, swasta, yayasan dan pengelola lain. Bentuk pengelola dan nama customer bukan syarat tenant atau prefix schema.
- Profile buatan admin adalah data berversi. Template contoh bukan enum tertutup. Developer menambahkan capability bisnis bersama, bukan source fork per profile/customer.
- Sharing pasien, clinical records, katalog obat/jasa, pricing, stok/custody dan akses adalah keputusan berbeda. Hierarchy tidak otomatis menggabungkan semuanya.
- Stock owner, physical custodian, clinical unit, seller, collector dan beneficiary harus dibedakan. Snapshot SQL bukan bukti seluruh settlement atau legal eligibility.
- Database, sumber code, dan data Windows/Bitbucket/produksi legacy tidak ditulis sebagai efek samping program. Trial dan tenant yang ditakedown tidak diaktifkan tanpa instruksi baru.

## Gunakan keputusan Flowable yang terbaru

Flowable dipilih untuk workflow proses bisnis. Keputusan dokumentasi yang terpisah tercatat pada [PR workflow #370](https://github.com/MettaDevs/coreERP/pull/370), commit `7a218677`. Saat handoff disusun PR tersebut masih terbuka, sementara checkout main masih berisi pembandingan engine lama. Periksa keadaan terbaru sebelum mengeksekusi backlog workflow; jangan memilih Temporal kembali hanya karena membaca dokumen main yang tertinggal.

Paket ini tidak ikut mengambil atau me-merge PR workflow tersebut. Pemilihan Flowable tidak membuktikan service, editor visual, monitor instance, worker, atau adapter Faskes sudah terpasang. Domain action tetap menjaga validation, authorization, atomic effect, idempotency, dan recovery.

## Bedakan bukti dan pekerjaan yang belum selesai

Source PHP/AL serta SELECT SQL yang dirangkum pada [evidence](DATABASE-PATTERN-EVIDENCE.md) dan [custody](SUPPLY-CUSTODY-AND-PAYMENT.md) mempunyai scope snapshot yang ditulis di sana. Jangan memperluasnya menjadi klaim semua procedure, workflow, posting, dan deployment sudah lolos.

Audit privat, query mentah, credential, dan file sesi lain tidak menjadi bagian paket publik. Referensi dan bukti yang aman ada pada halaman paket ini. Detail konfigurasi runtime tetap diperiksa melalui sumber koneksi yang sah.

Pemeriksaan dokumentasi dapat dijalankan dengan `node scripts/check-healthcare-domain-neutrality.mjs` dan build VitePress. Pengecualian menunggu CI untuk PR dokumentasi ini tidak berlaku otomatis bagi PR implementasi. Ikuti gate test, kontrak, runtime dan keamanan yang sesuai perubahan nyata.

Hasil implementasi [PF-01](../metta-platform-portal/PF-01.md) memisahkan writer tenant kanonik dari bootstrap ERP. Status review, command dan bukti fixture ada pada TODO portal dan `evidence/pf-01/`. Ini tidak memindahkan database atau mengaktifkan portal/enrollment; langkah berikutnya tetap CPDB-02 setelah review unit tersebut.
