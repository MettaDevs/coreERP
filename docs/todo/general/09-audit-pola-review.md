# Audit pola kesalahan review pada source aktif

Audit ini menelusuri apakah cacat yang ditemukan pada PR Procurement #343–#352 juga ada pada source `main`. Titik awal pemeriksaan adalah commit `025d2a4d4d01b34dcbbed7b489f3b6e163a17a4a`, 27 Oktober 2026. Snapshot PR yang belum masuk `main` tidak dihitung sebagai cacat source aktif.

Pengembangan HR lebih lanjut ditunda karena modulnya belum digunakan. Sesuai arahan terakhir pemilik produk, perbaikan HR dan kontrak direktori Core yang sudah dibuat tetap dipertahankan serta diuji; audit ini tidak menambah halaman atau fitur bisnis HR baru. Kalender kerja milik Foundation tetap termasuk layanan Core bersama.

## Cara membaca hasil

Kecocokan pencarian adalah kandidat, bukan otomatis bug. DataTable rincian yang berisi draft lokal tidak harus meminta batch server. `RecordListScroll` dengan `Inertia::scroll` juga merupakan lazy loading yang sah; keberadaan `paginate` sendiri bukan bukti navigasi nomor halaman. Migration unik lama harus dinilai bersama migration koreksi yang berjalan sesudahnya.

Pemindaian yang dapat diulang ada di `scripts/audit-review-patterns.py`. Jalankan dari akar repo:

```bash
python scripts/audit-review-patterns.py --self-test
python scripts/audit-review-patterns.py --exclude-prefix modules/apperp/human-resources/ --output audit-patterns.json
```

Scanner membaca PHP/TypeScript aktif di Core, Control Plane dan module, dengan pengecualian analytics yang masih ditahan, dependency, fixture, test dan migration. Pemeriksaan schema akhir, permission, ownership dan jumlah query dilakukan terpisah melalui penelusuran pemanggil serta tes. Lulus scanner bukan sertifikasi bebas bug.

## Temuan dan pekerjaan

| ID | Perilaku dan dampak | Perbaikan/verifikasi | Status |
| --- | --- | --- | --- |
| A01 | Serializer jenis pekerjaan, template checklist dan variabel checklist maintenance menanyakan jumlah rincian per baris daftar. | Hitungan digabung dalam query induk. Probe 1/10/25 induk turun dari 2/11/26 menjadi 1/1/1 SELECT, termasuk JSON. | Diperbaiki; tes PostgreSQL terarah lulus |
| A02 | Pengaitan kondisi jenis aset memeriksa kondisi serta relasi satu per ID. | Query himpunan ID menggantikan query per elemen, dengan error indeks tetap sama. Probe turun dari 8/26/56 menjadi 8/8/8 SELECT. | Diperbaiki; tes PostgreSQL terarah lulus |
| A03 | Daftar pelanggan, lingkungan dan situs admin.erp serta picker pelanggan/server mengambil seluruh pilihan. | Batch cursor dan SDK incremental, validasi cursor/search, label pilihan tersimpan, portal overlay serta refresh setelah create. Ringkasan seluruh situs tetap dihitung dalam chunk dengan aturan status yang sama. | Diperbaiki; tes PostgreSQL lulus |
| A04 | Endpoint geografis lama `villages-paginated` tanpa batas/validasi yang memadai masih terdaftar meski UI aktif memakai jalur baru. | Jalur tanpa consumer/contract itu dihapus. Daftar divisions wajib section, permission dan batch tervalidasi. | Diperbaiki; tes PostgreSQL terarah lulus |
| A05 | Riwayat ekspor memilih data awal ketika hasil live kosong; ekspor terakhir yang dihapus dapat muncul lagi. | Snapshot belum dimuat bernilai `null`; hasil kosong tetap `[]`. Tray dan halaman memakai snapshot yang sama. | Diperbaiki; tes kode aktual gagal sebelum perubahan dan lulus sesudahnya |
| A06 | Error filter finance serta gagal menghapus/mengunduh pada riwayat ekspor hanya lewat toast. | Feedback menetap di bawah header pekerjaan aktif memakai adapter Core yang ada. | Diperbaiki; pemeriksaan source dan adapter, belum browser |
| A07 | Counter nonaktif yang sudah terpilih hilang dari GET, lalu berisiko terlepas saat save. | Pertahankan relasi tersimpan, tetapi tolak pilihan nonaktif baru, arsip dan tenant lain. | Diperbaiki; tes PostgreSQL terarah lulus |
| A08 | Mutasi template jam kerja memeriksa tenant saja; fallback memilih perusahaan pertama dan body boleh menunjuk perusahaan lain. | Wajib perusahaan aktif yang diizinkan, tolak body berbeda, guard semua command dan batasi daftar JSON. Fixture tes memilih perusahaan beserta grant yang nyata. | Diperbaiki; tes PostgreSQL terarah lulus |
| A09 | Daftar dan lookup HR membaca seluruh baris sebelum menyaring. | Perbaikan yang sudah dibuat dipertahankan: cursor, batch membership ID yang tidak tertukar dengan user ID, scope tenant dan pembatasan email pada lookup yang memerlukannya. | Dipertahankan; tes PostgreSQL lulus, tanpa fitur HR baru |
| A10 | Workflow inbox membaca semua pending, lalu menanyakan aturan/permission saat memeriksa setiap baris. | Baca kandidat per batch; aturan dan permission dipakai ulang dalam request, tetapi keputusan tenant/perusahaan/unit/pengaju tetap per item. Cursor tidak melewatkan hasil yang boleh dilihat. | Diperbaiki; tes PostgreSQL terarah lulus |
| A11 | Expiry operasi situs menjalankan dua UPDATE per situs dalam daftar. | Batch expiry mempertahankan status penutupan dan pembersihan hash. Ini write amplification, bukan read N+1. | Diperbaiki; tes PostgreSQL lulus |
| A12 | Panel dokumen lampiran mengambil seluruh lampiran record sekaligus. | Cursor dan DataTable incremental; nama pembuat di-resolve satu batch; jumlah total terpisah dari jumlah yang sudah dimuat. Upload/arsip menyelaraskan ulang daftar. | Diperbaiki; tes PostgreSQL lulus |
| A13 | Daftar anggota dan undangan mengambil seluruh tenant sekaligus; pemutakhiran editor undangan mengganti semua draft dari props. | Batch daftar dan referensi; editor menggabungkan baris tersimpan tanpa membuang draft lain, termasuk ketika baris berada di luar batch awal. | Diperbaiki; tes PostgreSQL lulus |
| A14 | Katalog buku alamat dikirim seluruhnya ke manager, filter dan form. | Lookup bertahap dan label relasi yang sudah dipilih; hasil parsial tidak mencabut ID yang belum dimuat. | Diperbaiki; tes PostgreSQL lulus |
| A15 | Search pada monitor identity di-cast sebelum validasi sehingga array mencapai konversi PHP. | String dibatasi dan input tak sah menjadi error validasi, bukan kegagalan konversi. | Diperbaiki; tes PostgreSQL lulus |
| A16 | Copy default pekerjaan dengan key sama dapat menabrak UNIQUE saat retry. | Simpan hash permintaan awal, serialize key dalam transaksi, replay tidak bergantung isi source/copy yang kemudian diedit dan mismatch menjadi konflik. | Diperbaiki; tes PostgreSQL lulus |
| A17 | Replay create master HR belum membandingkan payload awal yang tetap. | Hash permintaan awal pada hasil yang sudah dibuat; source yang sudah ditulis dipertahankan, tanpa pengembangan HR tambahan. | Dipertahankan; tes PostgreSQL lulus |
| A18 | Pemeriksaan overlap assignment posisi dilakukan sebelum write tanpa penguncian posisi. | Perubahan transaksi/penguncian yang sudah ditulis dipertahankan untuk diuji bersama perilaku overlap yang ada. | Dipertahankan; tes PostgreSQL lulus |
| A19 | Rincian nilai variabel dan baris template checklist dihapus fisik saat replace. | Soft delete, unique aktif parsial, ID tersimpan tetap sama, omission diarsipkan dan pembacaan work order lama mempertahankan referensi arsip. | Diperbaiki; tes PostgreSQL lulus |

## Batas pekerjaan

Copy dan retensi hanya mencakup endpoint serta dua tabel yang dicatat di atas. Retensi tidak membuat engine versioning definisi checklist; ia menjaga ID dan referensi yang sudah ada. Pengembangan HR berikutnya beserta rencana pemindahan identitas Worker tidak dikerjakan pada audit ini. Audit tidak menyatakan seluruh codebase sudah memenuhi setiap aturan atau bebas dari cacat lain.

## Bukti query dan batas pengukuran

Authority workflow diuji dengan permission aplikasi serta grant perusahaan/unit yang nyata, termasuk seluruh SELECT dan serialisasi: 1/10/25 item turun dari 6/42/102 menjadi 6/6/6, baik tipe workflow sama maupun berbeda. Itu pengukuran pipeline authority, bukan klaim bahwa seluruh HTTP hanya membutuhkan enam query.

Expiry situs menghasilkan dua pernyataan UPDATE per batch, dibanding dua per situs sebelumnya. Ringkasan seluruh armada masih O(N); pembatasan memori dan respons tidak sama dengan menghilangkan kebutuhan membaca seluruh armada untuk ringkasan.

Probe SQLite tidak memasang trigger PostgreSQL dan bukan benchmark kapasitas. Tes feature PostgreSQL berjalan pada database audit terpisah; Core dijalankan paralel, Control Plane serial. Runtime aplikasi tidak di-rebuild dan data kerja tidak dipakai sebagai fixture.

## Aturan yang dipakai

- [Standar module: ownership, lifecycle, soft delete dan daftar bertahap](../../dev/02-module-standard.md).
- [Hak akses](../../dev/09-identity-and-access.md).
- [Nomor dan idempotency](../../dev/14-number-sequences.md).
- [Feedback error pada pekerjaan aktif](../../dev/28-pelaporan-kesalahan.md).
- [Master bersama](../master-bersama/README.md).

Tes browser dan load test kapasitas belum dilakukan. Tiga migration perbaikan sudah dijalankan lewat lifecycle module pada runtime lokal; kolom hash, soft delete dan kedua unique index aktif diverifikasi langsung dari container. Tidak ada pemasangan module, perubahan entitlement, deployment server atau rebuild container.


## Verifikasi akhir

- PostgreSQL terpisah: putaran regresi terakhir berisi 57 tes lulus (685 assertion), diikuti 59 tes alur maintenance/work order dan akses lulus (523 assertion). Ini bukan seluruh suite Core. Putaran luas juga mencatat empat tes yang dilewati; tidak dianggap lulus.
- Seluruh suite admin.erp selesai tanpa failure (2.913 assertion). Runner mencatat warning pada 453 tes karena berkas .env lokal tidak tersedia; hasil ini tidak disamarkan sebagai tanpa warning.
- PHPStan Core dan admin.erp lulus tanpa error; TypeScript kedua aplikasi lulus. Formatter serta lint source yang berubah diperiksa terarah.
- 	est-export-watch.mjs, 	est-access-invitation-drafts.mjs, 	est-address-book-options.mjs, 	est-inertia-feedback.mjs, MasterChildIdentity.test.mjs dan DraftMasterDetails.test.mjs lulus dengan kode fungsi/callback aktual.
- Bundle dan coverage contract Core lulus untuk 41 route internal. Tidak ada permukaan internal baru pada perubahan daftar UI ini.
- Build VitePress lulus; warning penyorotan bahasa AL dan ukuran chunk tetap berasal dari build dokumentasi.

Permintaan create lama yang tidak menyimpan hash awal ditolak dengan konflik saat diulang, bukan ditebak cocok dari record yang mungkin sudah diedit. Identitas child tersimpan tidak dibangun ulang dari nama atau nomor baris.
