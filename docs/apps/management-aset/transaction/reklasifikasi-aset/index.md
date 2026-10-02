# Reklasifikasi aset

Reklasifikasi aset memindah aset ke **group aset lain** (pindah group), atau memecah **sebagian nilainya ke aset baru** (pecah aset), beserta harga perolehan, akumulasi penyusutan, penurunan nilai, dan kenaikan nilai setiap bukunya. Bila group-nya berubah, memposting dokumennya mengirim jurnal pemindahan saldo antar akun posting group ke aplikasi finance lewat [feed posting finance](/dev/34-feed-posting-finance) (`asset.reclassification`).

Halaman ini untuk developer. Ia mencatat keputusan desain yang diambil saat fitur ini dibangun (2 Oktober 2026) beserta rujukan Business Central dan Dynamics 365 F&O-nya. Laporan yang membaca hasilnya ada di [Laporan dan ekspor](/apps/management-aset/transaction/laporan/#laporan-keuangan-aset).

## Rujukan

| | Padanan |
| --- | --- |
| Business Central | *FA Reclass. Journal* (tabel 5624 `FA Reclass. Journal Line`): `Reclassify Acq. Cost %` atau `Reclassify Acq. Cost Amount`, `Reclassify Acquisition Cost`, `Reclassify Depreciation`, `Reclassify Write-Down`, `Reclassify Appreciation`, `Reclassify Salvage Value`, `Insert Bal. Account`. `FA Reclass. Transfer Line` (`CalcAmounts`) mengubah nilai menjadi persen, mengalikan setiap saldo buku dengan persen itu, menolak harga perolehan nol dan nilai yang melebihi harga perolehan, dan menolak aset yang sudah dilepas. [Reclassify fixed assets](https://learn.microsoft.com/en-us/dynamics365/business-central/fa-how-trans-split-combine) memecah satu aset ke beberapa aset dengan persen. |
| Dynamics 365 F&O | Pemecahan dan pemindahan aset ([fixed asset split and transfer](https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/split-transfer-fa)): persen dan nilai saling menghitung, aset tujuan dibuat otomatis bila nomornya kosong, nilai dipindah sebagai harga perolehan dan akumulasi penyusutan terpisah, dan pilihan "Use source assets remaining periods" melanjutkan sisa periode penyusutan aset asal. |

## Keputusan desain

| No | Keputusan | Alasannya |
| --- | --- | --- |
| 1 | Satu dokumen = satu **jenis** (`pindah_group` atau `pecah`), header + baris aset. Jenis tidak dapat diganti sesudah draf disimpan. | Isian barisnya berbeda per jenis. Bentuk header + baris mengikuti penyesuaian nilai dan mutasi aset. |
| 2 | **Pindah group** mengganti `group_aset_id` aset yang sama dan memindah seluruh saldonya. Kelompok harta fiskal aset tidak ikut diganti. | BC memindah ke nomor aset lain karena posting group melekat pada FA Depreciation Book yang sudah punya entri; di register ini group ditunjuk langsung oleh aset, jadi cukup diganti dan saldonya dijurnal ulang ke akun group baru. Kelompok harta fiskal adalah salinan saat aset diterima dan diubah di register bila perlu. |
| 3 | **Pecah** memindah persen atau nilai perolehan, salah satu, ke aset baru yang lahir saat diposting. Nilai diubah menjadi perbandingan terhadap harga perolehan register aset; setiap saldo setiap buku (harga perolehan, akumulasi, penurunan nilai, kenaikan nilai, nilai sisa) dikali perbandingan itu lalu dibulatkan sekali ke presisi mata uang. | BC `CalcAmounts`. Satu perbandingan untuk semua buku membuat buku komersial dan fiskal tetap sebanding. Buku yang harga perolehannya sama dengan register memindah persis nilai yang diketik. |
| 4 | Beberapa baris pecah atas aset yang sama dihitung dari saldo sebelum diposting; jumlahnya harus di bawah 100%. Untuk memindah seluruhnya, pakai pindah group. | Contoh BC: 25% dan 45% dari aset yang sama, 30% tinggal di aset asal. 100% berarti aset asal kosong, yang bukan pemecahan. |
| 5 | Aset baru menyalin identitas dan klasifikasi aset asal — jenis, kondisi, pabrikan, model, lokasi, unit, dimensi keuangan, tanggal perolehan dan mulai dipakai, atribut — dengan nomor baru dari reference `management-aset.aset`, nama baru bila diisi, dan penempatan pertama bertanggal reklasifikasi. Nomor seri tidak disalin. | Pecahan bukan perolehan baru, jadi tanggal perolehannya tetap. Nomor seri milik barang fisik aset asal. Nomor terbit sebelum transaksi dengan kunci per baris, sehingga percobaan ulang memakai nomor yang sama. |
| 6 | **Umur yang sudah berjalan ikut**: buku aset baru menyalin aturan buku asal (profil, masa manfaat, konvensi, tanggal mulai menyusut) dan jumlah periode yang sudah disusutkan sampai tanggal reklasifikasi menjadi `elapsed_periods_offset`. Akumulasinya datang dari reklasifikasi, bukan saldo awal, jadi `opening_accumulated_depreciation` nol. | F&O "Use source assets remaining periods". Penyusutan berikutnya kedua aset berjumlah sama dengan penyusutan aset sebelum dipecah. |
| 7 | **Penyusutan sampai tanggal reklasifikasi harus beres** — aturan yang sama dengan pelepasan dan penyesuaian nilai (`Services/BookPeriods`). Aset yang dilepas atau sudah disetujui berhenti dipakai tidak dapat direklasifikasi. | Usulan dihitung dari saldo lama. BC menolak aset yang sudah dilepas atau tidak aktif. |
| 8 | **Jurnal hanya bila group berubah**, dan hanya untuk buku yang di-post ke finance (K-26). Pecah di dalam satu group hanya mengubah register. Buku lain, misalnya fiskal, selalu hanya register. | BC *Insert Bal. Account* menyeimbangkan setiap aset dengan akun posting group-nya sendiri: dalam satu posting group pasangan barisnya saling meniadakan. |
| 9 | Reklasifikasi antar group hanya untuk group yang membawa aset ke finance **lewat buku yang sama**. | Buku yang di-post dipilih dari group (`PembuatAset::bukuDiPostId`). Aset yang pindah ke group dengan buku lain kelak dilepas dari buku yang perolehannya tidak pernah dijurnal. |
| 10 | Pemindahan per buku dicatat sekali saat posting di `aset_tr_reklasifikasi_aset_buku`. | Register tidak menyimpan FA Ledger Entry. Laporan mutasi nilai buku, rekonsiliasi, penyusutan, dan daftar perolehan membaca mutasi reklasifikasi dari sini. |
| 11 | Posting punya duty sendiri, terpisah dari menyusun draf. | Sama dengan penyesuaian nilai dan pelepasan. |

## Jurnalnya

Satu posting per dokumen, bertanggal dokumen, diringkas per group, akun, dan unit (`Services/ReclassificationPosting`). Untuk setiap baris yang berpindah group, dari bagian yang dipindah pada buku yang di-post ke finance:

| Akun posting group | Debit | Kredit | Dimensi |
| --- | --- | --- | --- |
| `acquisition_account_id` — harga perolehan | group tujuan | group asal | dimensi keuangan aset |
| `accumulated_depreciation_account_id` — akumulasi penyusutan | group asal | group tujuan | unit pengguna |
| `write_down_account_id` — akumulasi penurunan nilai | group asal | group tujuan | dimensi keuangan aset |
| `appreciation_account_id` — kenaikan nilai aset | group tujuan | group asal | dimensi keuangan aset |

Dimensinya sama dengan tempat saldo itu dulu dicatat, seperti jurnal pelepasan: akumulasi penyusutan di unit pengguna seperti "Post penyusutan", sisanya di dimensi keuangan aset seperti jurnal perolehan. Tidak ada akun laba rugi, dan nilai buku asetnya tidak berubah.

- `posting_id`: `AST-RCL-<id dokumen>`.
- `details.assets` per baris: `asset_code`, `new_asset_code` (pecah; `null` untuk pindah group), `from_group`, `to_group`, `book`, dan yang dipindah: `acquisition_value`, `accumulated_depreciation`, `write_down_amount`, `appreciation_amount`.
- Pemetaan akun yang kosong tidak menahan posting: posting terbit `held`, nilainya tetap berpindah (K-18).

Contoh pindah group: ventilator 48.000.000 di group Kendaraan, akumulasi 1.000.000, penurunan nilai 2.000.000, dipindah ke group Alat kesehatan.

| Akun | Debit | Kredit |
| --- | --- | --- |
| 1-2400 Aset Tetap - Alat Kesehatan | 48.000.000 | |
| 1-2390 Akumulasi Penyusutan - Kendaraan | 1.000.000 | |
| 1-2395 Akumulasi Penurunan Nilai - Kendaraan | 2.000.000 | |
| 1-2490 Akumulasi Penyusutan - Alat Kesehatan | | 1.000.000 |
| 1-2495 Akumulasi Penurunan Nilai - Alat Kesehatan | | 2.000.000 |
| 1-2300 Aset Tetap - Kendaraan | | 48.000.000 |

Contoh pecah: 12.000.000 dari ambulans 48.000.000 (akumulasi 1.000.000) dipecah ke aset baru di group Alat kesehatan. Perbandingannya 25%, jadi akumulasi yang ikut 250.000: Dr 1-2400 12.000.000, Dr 1-2390 250.000, Cr 1-2490 250.000, Cr 1-2300 12.000.000. Pecahan yang tetap di group Kendaraan tidak dijurnal.

## Status dokumen

| Status | Arti | Yang boleh |
| --- | --- | --- |
| `draft` | Disusun. Group asal di layar adalah group aset sekarang. | ubah, arsipkan, pratinjau posting, posting |
| `posted` | Nilai sudah berpindah; group asal, aset baru, nilai perolehan yang dipindah, dan pemindahan per buku dibekukan; `posting_id` tercatat. | hanya dibaca dan dilampiri |

## Data yang disimpan

| Tabel | Isi |
| --- | --- |
| `aset_tr_reklasifikasi_aset` | Header: nomor, entitas legal, unit penanggung jawab, `jenis`, tanggal, alasan (`keterangan`, maks. 250 karakter karena ikut ke jurnal), status, `diposting_pada`, `posting_id`. |
| `aset_tr_reklasifikasi_aset_details` | Satu aset asal per baris: `group_aset_tujuan_id`, untuk pecah `persen` atau `nilai_perolehan` dan `nama_aset_baru`; lalu `group_aset_asal_id`, `aset_baru_id`, dan `nilai_perolehan_dipindah` yang dibekukan saat posting. |
| `aset_tr_reklasifikasi_aset_buku` | Yang dipindah per baris per buku: buku aset asal dan tujuan, group asal dan tujuan, tanggal, harga perolehan, akumulasi, penurunan nilai, kenaikan nilai, nilai sisa, dan `dijurnal`. Pada pindah group buku aset asal dan tujuannya sama. Ditulis sekali dan tidak pernah diubah, jadi tanpa `deleted_at`, seperti periode penyusutan. |

- Nomor unik per entitas legal, `(tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL`.
- Baris tidak dibatasi indeks unik per aset karena pecah boleh memuat aset yang sama beberapa kali; pindah group menolak aset ganda saat disimpan. Baris dicocokkan menurut `id` saat draf diubah, dan nomor baris tidak dipakai ulang karena lampiran menempel ke nomor baris.
- Foreign key gabungan dengan `tenant_id` ke header, aset asal, aset baru, group tujuan, dan buku aset.
- Kolom jejak, versi baris, dan klasifikasi data (`CustomerContent`) mengikuti [standar module](/dev/02-module-standard#kolom-jejak-pembuat-dan-pengubah). Log perubahan bawaan mencatat `tanggal`, `keterangan`, `status`, dan `deleted_at` header.
- Sesudah pecah, `aset_tr_aset.acquisition_value` aset asal berkurang sebesar `nilai_perolehan_dipindah`, dan aset baru membawa nilai itu.

## Endpoint

Semua di bawah `/api/modules/management-aset/v1/`. Kontraknya `contracts/src/paths/reklasifikasi-aset.yaml`.

| Endpoint | Guna | Permission |
| --- | --- | --- |
| `GET reklasifikasi-aset` | Daftar, saringan `status`, `jenis`, `dari`, `sampai` | `.read` |
| `POST reklasifikasi-aset` | Draf baru; wajib `Idempotency-Key` | `.create` |
| `GET reklasifikasi-aset/{id}` | Rincian, baris, pemindahan per buku sesudah diposting, keadaan jurnal, `ETag` | `.read` |
| `PATCH reklasifikasi-aset/{id}` | Ubah draf; `details` wajib dikirim dan dicocokkan menurut `id` baris | `.update` |
| `DELETE reklasifikasi-aset/{id}` | Arsipkan draf | `.archive` |
| `GET reklasifikasi-aset/{id}/pratinjau-posting` | Penghalang, catatan, pemindahan per buku, dan jurnal yang akan terbit; tidak menyimpan apa pun | `.post` atau `.update` |
| `POST reklasifikasi-aset/{id}/posting` | Pindahkan nilai, lahirkan aset baru atau ganti group, terbitkan jurnal | `.post` |

## Hak akses

`manifest/fixed-asset/asset-reclassifications.yaml`, rantai F&O entry point → permission → privilege → duty:

| Duty | Privilege | Permission |
| --- | --- | --- |
| `management-aset.reklasifikasi-aset.manage` — Kelola reklasifikasi aset | `.maintain` (susun), `.retire` (arsipkan draf) | `.read`, `.create`, `.update`, `.archive`, ditambah `aset.read` dan `group-aset.read` untuk memilih aset dan group tujuan |
| `management-aset.reklasifikasi-aset.posting` — Posting reklasifikasi aset | `.post-to-finance` | `.read`, `.post` |

Seluruh permission-nya masuk kebijakan `management-aset.asset-responsibility`. Menu **Transaksi › Reklasifikasi aset** di `app.yaml`. Nomor dari reference `management-aset.reklasifikasi-aset`, scope entitas legal, prefix bawaan `RKLA`. Lampiran terdaftar di `AssetAttachments` pada dokumen dan barisnya.

## Yang belum ada

- **Menggabungkan aset** (*combine* di BC: memindah seluruh nilai ke aset lain yang sudah ada). Pecah selalu melahirkan aset baru, dan pindah group memindah aset yang sama.
- **Pindah antar entitas legal** (F&O intercompany split and transfer). Aset dan aset barunya selalu di entitas legal dokumen.
- **Reklasifikasi akumulasi saat aset pindah business unit** tanpa berganti group (dicatat di `docs/todo/feed-posting-finance/README.md`). Mutasi aset memindah dimensinya untuk penyusutan berikutnya saja.
- **Kelompok harta fiskal ikut group tujuan.** Sengaja tidak otomatis; ubah di register bila aturan fiskalnya ikut berubah.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `src/Http/Controllers/transaksi/Reclassification/AssetReclassificationController.php` | Daftar, draf, pratinjau, posting |
| `src/Models/transaksi/Reclassification/` | Header, baris, dan pemindahan per buku |
| `src/Services/ReclassificationPosting.php` | Penghalang, rencana pemindahan, aset dan buku baru, jurnal |
| `src/Services/BookPeriods.php` | Aturan penyusutan sampai tanggal reklasifikasi, bersama pelepasan dan penyesuaian nilai |
| `database/migrations/2026_10_02_100000_create_asset_reclassification_tables.php` | Ketiga tabel |
| `ui/transactions/reclassification/` | Daftar dan rincian dengan pratinjau dan posting |
| `tests/Feature/AssetReclassificationTest.php` | Jurnal pindah group dan pecah, saldo per buku, umur berjalan, penghalang, hak akses |

## Halaman terkait

- [Penyesuaian nilai aset](/apps/management-aset/transaction/penyesuaian-nilai-aset/) — penurunan dan kenaikan nilai yang ikut dipindah
- [Dokumen siklus aset](/apps/management-aset/transaction/siklus-aset/) — pelepasan aset pecahan
- [Posting group aset](/apps/management-aset/master/posting-group/) — akunnya
- [Laporan dan ekspor](/apps/management-aset/transaction/laporan/#laporan-keuangan-aset) — mutasi nilai buku dan rekonsiliasi
- [Feed posting finance](/dev/34-feed-posting-finance) — jalur jurnalnya
