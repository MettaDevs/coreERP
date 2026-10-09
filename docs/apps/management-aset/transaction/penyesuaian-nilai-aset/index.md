# Penyesuaian nilai aset

Penyesuaian nilai aset mencatat **penurunan nilai** (write-down, impairment) atau **kenaikan nilai** (appreciation, revaluasi) atas satu buku penyusutan, untuk satu atau banyak aset sekaligus. Memposting dokumennya mengubah nilai buku aset dan mengirim jurnalnya ke aplikasi finance lewat [feed posting finance](/dev/34-feed-posting-finance) (`asset.write_down`, `asset.appreciation`).

Halaman ini untuk developer. Ia mencatat keputusan desain yang diambil saat fitur ini dibangun (1 Oktober 2026) beserta rujukan Business Central dan Dynamics 365 F&O-nya. Pelepasan yang membalik penyesuaian ini ada di [Dokumen siklus aset](/apps/management-aset/transaction/siklus-aset/#jurnal-pelepasan).

## Rujukan

| | Padanan |
| --- | --- |
| Business Central | Baris jurnal aset tetap ber-*FA Posting Type* `Write-Down` dan `Appreciation`; akun `Write-Down Account` / `Write-Down Expense Acc.` dan `Appreciation Account` / `Appreciation Bal. Account` pada *FA Posting Group* (field 4, 26, 5, 27). Setelan bawaan *FA Posting Type Setup* saat buku dibuat (`DepreciationBook.Table.al`, `OnInsert`): keduanya *Part of Book Value* dan *Include in Depr. Calculation*; write-down juga *Include in Gain/Loss Calc.* |
| Dynamics 365 F&O | Transaksi *Write-down adjustment* dan *Revaluation* di jurnal aset tetap, dengan akun per jenis transaksi di [fixed asset posting profile](https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/tasks/set-up-fixed-asset-posting-profiles). |
| Standar akuntansi | PSAK 48 / IAS 36 (penurunan nilai), PSAK 16 / IAS 16 (model revaluasi). |

## Keputusan desain

| No | Keputusan | Alasannya |
| --- | --- | --- |
| 1 | Satu dokumen = satu **jenis** (penurunan atau kenaikan) dan satu **buku penyusutan**, header + baris aset. | Jenis dan buku menentukan akun dan apakah jurnalnya dikirim; baris cukup membawa aset dan nilainya. Bentuk header + baris mengikuti monitoring dan mutasi aset. |
| 2 | Nilai baris selalu positif, paling banyak dua desimal; arahnya dari jenis. | Tidak ada nilai negatif yang membingungkan; jurnal feed juga tidak menerima nilai negatif. |
| 3 | Alur draf → **Pratinjau posting** → **Posting**, terkunci sesudahnya. Koreksi dengan dokumen baru. | Alur jurnal aset tetap BC (*Preview Posting*, *Post*); langkah 4 feed posting finance. |
| 4 | Penurunan dan kenaikan nilai adalah **bagian nilai buku**: `aset_tr_buku_aset.write_down_amount` dan `appreciation_amount`, dan `net_book_value` = harga perolehan − akumulasi − penurunan + kenaikan. | Bawaan BC *Part of Book Value*. Disimpan sebagai saldo per buku seperti FlowField `Write-Down`/`Appreciation` BC, supaya pelepasan dan penghitung penyusutan membacanya tanpa menjumlah riwayat. |
| 5 | **Penyusutan berikutnya dihitung dari nilai buku baru dibagi sisa masa manfaat.** Garis lurus pada buku yang pernah disesuaikan memakai nilai buku − nilai sisa dibagi sisa periode; garis lurus sisa umur dan saldo menurun sudah begitu. | IAS 36 ¶63 dan IAS 16: beban penyusutan sesudah penurunan nilai atau revaluasi dialokasikan atas nilai tercatat baru selama sisa umur. Garis lurus BC (`CalculateNormalDepreciation`, `CalcSLAmount`) memang selalu membagi nilai buku ke sisa umur. Buku yang tidak pernah disesuaikan tetap memakai rumus lama, supaya angka penyusutan yang berjalan tidak bergeser. |
| 6 | Penurunan nilai tidak boleh melebihi nilai buku. Kenaikan tidak dibatasi. | Nilai buku negatif tidak bermakna; BC menolaknya kecuali buku diizinkan. |
| 7 | **Penyusutan sampai tanggal penyesuaian harus beres**: buku tidak boleh punya usulan yang belum difinalkan, atau periode final yang berakhir sesudah tanggal penyesuaian dan belum dibalik. | Usulan dihitung dari nilai buku lama dan tidak dapat dibuang, hanya difinalkan. Penyusutan sesudah tanggal penyesuaian dihitung dari nilai buku yang salah. Aturan yang sama dengan pelepasan (`Services/BookPeriods`). |
| 8 | Jurnal hanya terbit untuk aset yang **buku dokumen adalah buku yang di-post ke finance** untuk group-nya (K-26). Penyesuaian di buku lain, misalnya fiskal, hanya mengubah nilai buku di register. | Sama dengan "Post penyusutan": feed belum membawa lapisan posting, jadi satu aset hanya dikirim lewat satu buku. |
| 9 | Pemulihan penurunan nilai (*reversal of impairment*, IAS 36 ¶114–117) belum punya jenis sendiri. | Tidak diminta dan tidak ada padanan langsungnya di BC selain write-down bernilai positif. Lihat [Yang belum ada](#yang-belum-ada). |
| 10 | Posting punya duty sendiri, terpisah dari menyusun draf. | Seperti "Post penyusutan ke aplikasi finance" (keputusan pemilik produk, 22 September 2026): role yang menyusun draf tidak diam-diam dapat mengubah nilai buku dan mengirim jurnalnya. |

## Jurnalnya

Satu posting per dokumen, bertanggal dokumen, diringkas per group aset dan unit seperti "Post penyusutan" (`Services/ValueAdjustmentPosting`).

| Jenis | Debit | Kredit |
| --- | --- | --- |
| `asset.write_down` | `write_down_expense_account_id` — beban penurunan nilai, unit pengguna (department) | `write_down_account_id` — akumulasi penurunan nilai, dimensi keuangan aset |
| `asset.appreciation` | `appreciation_account_id` — kenaikan nilai aset, dimensi keuangan aset | `appreciation_offset_account_id` — lawan kenaikan nilai (lazimnya surplus revaluasi), unit pengguna |

Akun neraca yang membawa nilai aset memakai dimensi keuangan aset, sama dengan jurnal perolehan dan pelepasan yang kelak membaliknya; beban dan lawannya memakai unit pengguna dari penempatan terakhir sampai tanggal dokumen, department yang juga menanggung penyusutannya.

- `posting_id`: `AST-WDN-<id dokumen>` atau `AST-APR-<id dokumen>`. Posting ulang dokumen yang sama tidak menerbitkan posting kedua.
- `details.assets` per aset: `adjustment_amount`, `net_book_value_before`, `net_book_value_after`; alasannya di `details.reason` dan `source_document.description`.
- Pemetaan akun yang kosong tidak menahan posting: posting terbit `held`, nilai bukunya tetap berubah (K-18).
- Contoh: ambulans bernilai buku 47.000.000 diturunkan 11.000.000 — Dr Rugi Penurunan Nilai 11.000.000, Cr Akumulasi Penurunan Nilai 11.000.000. Penyusutan bulan berikutnya: 36.000.000 / 47 sisa bulan = 765.957,45, bukan 1.000.000.

Saat aset itu dilepas, jurnal pelepasan mendebit akumulasi penurunan nilai dan mengkredit kenaikan nilai sebesar saldonya, lalu laba/rugi dihitung dari nilai buku yang sudah memuat keduanya.

## Status dokumen

| Status | Arti | Yang boleh |
| --- | --- | --- |
| `draft` | Disusun. Nilai buku sebelum dan sesudah di layar dibaca dari buku aset sekarang. | ubah, arsipkan, pratinjau posting, posting |
| `posted` | Nilai buku sudah berubah; nilai sebelum dan sesudah dibekukan di baris, `posting_id` tercatat. | hanya dibaca dan dilampiri |
| `cancelled` | Dampak penyesuaian dibalik; nilai sebelum/sesudah dokumen asal tetap beku. | dibaca, dengan status jurnal pembatalannya |

Penyesuaian `posted` dapat [dibatalkan](/apps/management-aset/transaction/pembatalan/) dengan
hak tersendiri, atau diajukan melalui workflow. Pembatalan write-down mengurangi saldo write-down
asal; ia bukan appreciation. Penyusutan atau reklasifikasi lanjutan dan saldo yang tidak cukup
menahan pembatalan.

## Data yang disimpan

| Tabel | Isi |
| --- | --- |
| `aset_tr_penyesuaian_nilai_aset` | Header: nomor, entitas legal, unit penanggung jawab, `jenis`, `buku_id`, tanggal, alasan (`keterangan`, maks. 250 karakter karena ikut ke jurnal), status, `diposting_pada`, `posting_id`. |
| `aset_tr_penyesuaian_nilai_aset_details` | Satu aset per baris: `nilai`, `keterangan`, lalu `nilai_buku_sebelum` dan `nilai_buku_sesudah` yang dibekukan saat posting. |
| `aset_tr_buku_aset.write_down_amount`, `appreciation_amount` | Saldo penurunan dan kenaikan nilai per buku aset, nol untuk buku yang sudah ada. |

- Nomor unik per entitas legal, `(tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL`.
- Satu aset paling banyak sekali per dokumen, indeks unik parsial `(tenant_id, penyesuaian_nilai_aset_id, aset_id) WHERE deleted_at IS NULL`. Baris yang dikeluarkan diarsipkan dan nomornya tidak dipakai ulang, karena lampiran menempel ke nomor baris.
- Foreign key gabungan dengan `tenant_id` ke buku penyusutan, aset, dan header.
- Kolom jejak, versi baris, dan klasifikasi data (`CustomerContent`) mengikuti [standar module](/dev/02-module-standard#kolom-jejak-pembuat-dan-pengubah). Log perubahan bawaan mencatat `tanggal`, `keterangan`, `status`, dan `deleted_at` header.
- Koreksi nilai perolehan sesudah penurunan nilai (masih tanpa periode penyusutan) menyamakan nilai buku dengan nilai perolehan baru − penurunan + kenaikan, bukan dengan nilai perolehan saja.

## Endpoint

Semua di bawah `/api/modules/management-aset/v1/`. Kontraknya `contracts/src/paths/penyesuaian-nilai-aset.yaml`.

| Endpoint | Guna | Permission |
| --- | --- | --- |
| `GET penyesuaian-nilai-aset` | Daftar, saringan `status`, `jenis`, `dari`, `sampai`, dengan jumlah baris dan total nilai | `.read` |
| `POST penyesuaian-nilai-aset` | Draf baru; wajib `Idempotency-Key` | `.create` |
| `GET penyesuaian-nilai-aset/{id}` | Rincian, baris, nilai buku, keadaan jurnal, `ETag` | `.read` |
| `PATCH penyesuaian-nilai-aset/{id}` | Ubah draf; `details` wajib dikirim dan dicocokkan menurut aset | `.update` |
| `DELETE penyesuaian-nilai-aset/{id}` | Arsipkan draf | `.archive` |
| `GET penyesuaian-nilai-aset/{id}/pratinjau-posting` | Penghalang, catatan, dan jurnal yang akan terbit; tidak menyimpan apa pun | `.post` atau `.update` |
| `POST penyesuaian-nilai-aset/{id}/posting` | Ubah nilai buku, bekukan baris, terbitkan jurnal | `.post` |

Perubahan atas dokumen yang sudah ada membawa versi baris; versi basi dijawab 409.

## Hak akses

`manifest/fixed-asset/asset-value-adjustments.yaml`, rantai F&O entry point → permission → privilege → duty:

| Duty | Privilege | Permission |
| --- | --- | --- |
| `management-aset.penyesuaian-nilai-aset.manage` — Kelola penyesuaian nilai aset | `.maintain` (susun), `.retire` (arsipkan draf) | `.read`, `.create`, `.update`, `.archive`, ditambah `aset.read` dan `buku-penyusutan.read` untuk memilih aset dan buku |
| `management-aset.penyesuaian-nilai-aset.posting` — Posting penyesuaian nilai aset | `.post-to-finance` | `.read`, `.post` |

Seluruh permission-nya masuk kebijakan `management-aset.asset-responsibility` (unit penanggung jawab). Menu **Transaksi › Penyesuaian nilai aset** di `app.yaml`. Nomor dari reference `management-aset.penyesuaian-nilai-aset`, scope entitas legal, prefix bawaan `PNLA`. Lampiran (misalnya laporan penilai) terdaftar di `AssetAttachments` pada dokumen dan barisnya.

## Yang belum ada

- **Pemulihan penurunan nilai.** Penurunan nilai yang kemudian pulih belum punya jenis sendiri. Kenaikan nilai mendebit akun kenaikan nilai, bukan mengurangi akumulasi penurunan nilai, jadi belum tepat untuk pemulihan menurut IAS 36. Bila dibutuhkan, tambahkan jenis ketiga yang mendebit `write_down_account_id` dan mengkredit akun pendapatan pemulihan, dengan batas saldo penurunan nilai.
- **Surplus revaluasi ke saldo laba** saat aset dilepas atau selama dipakai (IAS 16 ¶41) dicatat manual di aplikasi finance.
- **Penghitungan otomatis nilai yang dapat dipulihkan.** Nilainya diketik pengguna dari hasil penilaian; modul ini tidak menghitung nilai pakai.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `src/Http/Controllers/transaksi/ValueAdjustment/AssetValueAdjustmentController.php` | Daftar, draf, pratinjau, posting |
| `src/Models/transaksi/ValueAdjustment/` | Header dan baris |
| `src/Services/ValueAdjustmentPosting.php` | Penghalang dan jurnal |
| `src/Services/BookPeriods.php` | Aturan penyusutan sampai tanggal penyesuaian, bersama pelepasan |
| `src/Services/DepreciationCalculator.php` | Garis lurus dari nilai buku baru sesudah penyesuaian |
| `database/migrations/2026_10_01_110000_add_value_adjustments_to_asset_books.php`, `2026_10_01_120000_create_asset_value_adjustment_tables.php` | Saldo per buku dan tabel dokumen |
| `ui/transactions/value-adjustment/` | Daftar dan rincian dengan pratinjau dan posting |
| `tests/Feature/AssetValueAdjustmentTest.php` | Jurnal, nilai buku, penyusutan berikutnya, penghalang, pelepasan yang membalik, hak akses |

## Halaman terkait

- [Dokumen siklus aset](/apps/management-aset/transaction/siklus-aset/) — penjualan dan pemusnahan yang membalik penyesuaian ini
- [Proses penyusutan](/apps/management-aset/transaction/penyusutan/) — penyusutan sesudah penyesuaian
- [Posting group aset](/apps/management-aset/master/posting-group/) — akunnya
- [Feed posting finance](/dev/34-feed-posting-finance) — jalur jurnalnya
