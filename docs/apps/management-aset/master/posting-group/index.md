# Posting group aset

Halaman ini untuk developer. Perilaku dasar master ada di [Master data](/apps/management-aset/master/), dan feed yang memakai akun-akun ini ada di [Feed posting finance](/dev/34-feed-posting-finance).

Posting group menjawab satu pertanyaan: **jurnal aset dari group ini masuk ke akun mana.** Satu baris adalah delapan akun untuk satu group aset, berlaku sejak satu tanggal. Padanannya *FA Posting Groups* di Business Central dan *fixed asset posting profile* di F&O.

Penerimaan, saldo awal, dan "Post penyusutan" (area 9 sampai 11 feed posting finance) membaca akunnya dari sini. Selama akunnya kosong, posting yang membutuhkannya tertahan di Core.

## Konsep yang mudah tertukar

| | Menjawab | Tempatnya |
| --- | --- | --- |
| **Posting group** | Akun jurnal untuk group ini | `aset_m_posting_group`, halaman ini |
| **Matriks group × buku** | Group ini disusutkan di buku apa, dengan profil apa | `aset_m_group_buku_penyusutan`, [Penyusutan: profil dan buku](/apps/management-aset/master/depresiasi/) |
| **Daftar akun** | Akun apa saja yang ada di aplikasi finance pelanggan | `finance_reference_accounts`, milik Core |

Posting group tidak menyimpan akun, hanya menunjuknya. Nomor dan nama akun tetap milik daftar akun Core, dan boleh berubah lewat impor ulang tanpa memutus pemetaan (K-05).

## Data yang disimpan

`aset_m_posting_group`, satu baris per group dan `effective_from`:

| Kolom | Wajib | Dipakai |
| --- | --- | --- |
| `acquisition_account_id` | ya | Harga perolehan: penerimaan, saldo awal, koreksi nilai |
| `accumulated_depreciation_account_id` | ya | Akumulasi penyusutan: penyusutan, saldo awal |
| `depreciation_expense_account_id` | ya | Beban penyusutan |
| `payable_account_id` | ya | Lawan hutang pada mode `direct_payable`, bawaan setiap entitas legal |
| `clearing_account_id` | bila dipakai | Perantara pada mode `clearing` |
| `input_vat_account_id` | bila dipakai | PPN Masukan, untuk penerimaan yang membawa PPN (K-11) |
| `opening_balance_offset_account_id` | bila dipakai | Penyeimbang saldo awal saat cutover (K-13) |
| `grant_offset_account_id` | bila dipakai | Lawan hibah: kredit penerimaan dengan cara perolehan `hibah` (K-25) |

Di layar, tiap kolom akun membawa ikon bantuan berisi contoh jurnalnya, diambil dari bagian "Jenis posting dan jurnalnya" di PRD feed posting finance (K-39). Akun diisi langsung di layar ini; tidak ada templat berkas terpisah.

Setiap kolom akun menyimpan id `finance_reference_accounts`, tanpa foreign key. Indeks uniknya `(tenant_id, group_aset_id, effective_from) WHERE deleted_at IS NULL`.

## Endpoint

| Endpoint | Gunanya |
| --- | --- |
| `GET /api/v1/posting-group-aset` | Matriks: setiap group dengan baris yang berlaku hari ini, riwayatnya, akun wajib yang kosong, dan akun yang tidak bisa dipakai lagi |
| `GET /api/v1/posting-group-aset/akun?q=` | Pemilih akun: akun aktif yang berlaku untuk semua entitas legal |
| `PUT /api/v1/posting-group-aset/{group}/{tanggal}` | Menyimpan satu baris; tanggal baru menjadi baris baru |
| `DELETE /api/v1/posting-group-aset/{group}/{tanggal}` | Mengarsipkan satu baris |

## Hak akses

| Permission | Untuk |
| --- | --- |
| `management-aset.fixed-asset-posting-profiles.read` | Melihat matriks dan mencari akun |
| `management-aset.fixed-asset-posting-profiles.create` | Membuat baris untuk tanggal berlaku baru |
| `management-aset.fixed-asset-posting-profiles.update` | Mengubah akun pada baris yang sudah ada |
| `management-aset.fixed-asset-posting-profiles.archive` | Mengarsipkan baris |

Kodenya `fixed-asset-posting-profiles` karena itulah kode yang sudah terdaftar di manifest sejak layar ini masih kosong; kode adalah kontrak, labelnya yang berganti menjadi "Posting group aset".

Duty-nya `management-aset.fixed-asset-posting-profiles.manage`, **terpisah** dari duty lain (keputusan pemilik produk, 22 September 2026). Akun jurnal menentukan ke mana uang dicatat; role yang sudah ada tidak boleh diam-diam ikut dapat mengubahnya hanya karena duty lamanya bertambah isi.

Akibatnya pada tenant yang sudah ada sebelum rilis ini: role Owner menyimpan salinan duty saat tenant mendaftar, dan sinkronisasi katalog tidak menambahkan duty baru ke role mana pun. Layar tetap terlihat, karena `.view` masih ikut duty setup aset tetap yang lama, tetapi hanya untuk dilihat (tombolnya **Lihat**, bukan **Atur**) sampai admin tenant menambahkan duty `fixed-asset-posting-profiles.manage` ke sebuah role. Tenant yang mendaftar sesudah rilis mendapatkannya lewat role Owner.

## Aturan yang dijaga, dan alasannya

**Baris dipilih menurut tanggal posting, bukan tanggal hari ini.** Posting memakai baris dengan `effective_from` terbesar yang tidak melewati tanggal postingnya (`AssetPostingAccounts::effective()`). Akun group bisa berganti, misalnya saat konsultan memecah akun kendaraan. Posting tertahan bertanggal sebelum pergantian yang divalidasi ulang sesudahnya tetap harus masuk ke akun lama.

**Menyimpan adalah `PUT` ke alamat pasangan group dan tanggal.** Baris tidak punya kode, identitasnya pasangan itu. Mengulang permintaan yang sama karena jaringan putus memperbarui baris yang sama, bukan membuat baris kedua, jadi tidak perlu `Idempotency-Key`. Dua penyimpanan pertama yang bersamaan untuk tanggal yang sama berjalan berurutan lewat kunci pada baris group aset; tanpa kunci itu yang kedua menabrak indeks unik sebagai 500. Uji beban membuktikannya: tanpa kunci, 66 error 500 dalam 30 detik; dengan kunci, 0 dalam 90 detik (`apps/core/loadtest/README.md`).

**Hanya akun yang berlaku untuk semua entitas legal.** Posting group berlaku untuk seluruh tenant. Akun khusus satu entitas akan tertahan di Core dengan `ACCOUNT_OTHER_LEGAL_ENTITY` begitu dipakai posting entitas lain, jadi ia ditolak sejak disimpan.

**Akun nonaktif ditolak, tetapi kolom yang tidak diubah tidak diperiksa ulang.** Daftar akun bisa berubah sesudah dipetakan: akun dinonaktifkan, hilang, atau dijadikan khusus satu entitas oleh impor ulang. Perubahan itu tidak boleh menghalangi perbaikan kolom lain di baris yang sama, jadi hanya kolom yang nilainya berubah yang diperiksa.

**Ringkasan menghitung setiap group yang posting-nya akan tertahan, bukan hanya yang kolomnya kosong.** Sel yang menunjuk akun yang tidak bisa dipakai lagi ditandai merah seperti sel kosong, dan group-nya ikut dihitung (`unusable_accounts`, `groups_needing_attention`). Tanpa itu, ringkasan bisa hilang padahal posting group itu tetap tertahan, dan orang berhenti melihat sel merahnya.

**Kolom kosong tidak menghalangi transaksi aset.** Penerimaan dan penyusutan tetap berjalan; posting yang membutuhkan akun kosong tertahan di Core dengan jalan pintas ke layar ini (K-18, K-22). Kesalahan pemetaan tidak boleh menghentikan pekerjaan operasional.

**Hanya empat akun yang ditandai wajib.** Perantara, PPN Masukan, dan penyeimbang saldo awal hanya dipakai keadaan tertentu. Tanda merah untuk keadaan yang tidak pernah terjadi di sebuah tenant hanya melatih orang mengabaikan tanda itu. Kekurangannya tetap tertangkap saat posting yang membutuhkannya terbit.

**Cara perolehan belum membedakan akun debit, tetapi membedakan lawannya.** Pembelian, hibah, dan saldo awal (`Support/AcquisitionMethod`) hari ini memakai akun harga perolehan yang sama (K-12). Lawannya berbeda: pembelian ke lawan hutang (`direct_payable`) atau perantara (`clearing`), hibah ke lawan hibah, saldo awal ke penyeimbang saldo awal (`AssetPostingAccounts::offsetColumn()`). Hibah punya kolom sendiri karena tidak ada pemasok yang ditagih; mencatatnya sebagai hutang membuat aplikasi finance membuat faktur yang tidak pernah akan dibayar (keputusan pemilik produk, 24 September 2026, K-25).

**Akun dibaca ulang saat posting tertahan divalidasi ulang.** Setiap baris jurnal aset membawa kunci `posting-group:<group>:<kolom>` (`mapping.reference`). Core menanyakan kunci itu ke `Services/PostingGroupAccountResolver` saat posting dibentuk ulang, jadi mengisi kolom yang dulu kosong benar-benar melepas postingnya lewat Validasi ulang.

## Yang datang dari Core

- Daftar akun lewat kontrak `DaftarAkun`: `cari()` untuk pemilih akun, `banyak()` untuk menerjemahkan id menjadi nomor dan nama.
- Penahanan posting yang akunnya kosong atau nonaktif, beserta layar pantaunya.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `database/migrations/2026_09_23_100000_create_aset_posting_group_table.php` | Tabel dan indeks unik parsial |
| `src/Models/master/AssetPostingGroup.php` | Tujuh kolom akun, label, dan akun wajib |
| `src/Http/Controllers/master/AssetPostingGroupController.php` | Matriks, pemilih akun, simpan, dan arsip |
| `src/Services/AssetPostingAccounts.php` | Baris yang berlaku menurut tanggal posting, akun debit dan kolom lawan per cara perolehan |
| `src/Services/PostingGroupAccountResolver.php` | Akun posting group yang berlaku, untuk posting yang dibentuk ulang Core |
| `src/Support/AcquisitionMethod.php` | Cara perolehan |
| `ui/asset-posting-group/AssetPostingGroupPage.tsx` | Layar matriks dan form per tanggal berlaku |
| `tests/Feature/AssetPostingGroupTest.php` | Test aturan di atas |
| `loadtest/k6/posting-group.js`, `loadtest/verify.sql` | Uji beban: balapan pembuatan dan arsip tanggal yang sama, akun dan group tenant lain |

## Halaman terkait

- [Feed posting finance](/dev/34-feed-posting-finance) — penerbit, penahanan, dan layar pantau di Core
- [Penyusutan: profil dan buku](/apps/management-aset/master/depresiasi/) — lapisan posting buku
- [Lokasi dan dimensi keuangan](/apps/management-aset/master/lokasi/) — dimensi yang menyertai akun di setiap baris jurnal
- [PRD feed posting finance](/todo/feed-posting-finance/) — keputusan K-05, K-11 sampai K-13, K-18, K-22
