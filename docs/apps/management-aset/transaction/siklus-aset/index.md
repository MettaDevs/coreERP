# Dokumen siklus aset

Halaman ini untuk developer. Isinya cara aset dihentikan pemakaiannya dan dilepas.

Ada empat jenis dokumen, semuanya tersimpan di `aset_tr_dokumen_siklus_aset` dan memakai controller yang sama:

| Dokumen | Gunanya |
| --- | --- |
| `permintaan-pembelian-aset` | Usulan pengadaan |
| `dekomisioning-aset` | Usulan menghentikan pemakaian |
| `penjualan-aset` | Melepas dengan cara dijual, dengan jurnal pelepasan ke aplikasi finance |
| `pemusnahan-aset` | Melepas dengan cara dimusnahkan, dengan jurnal pelepasan ke aplikasi finance |

## Urutannya tidak bisa dilompati

```
in_use ──[dekomisioning disetujui]──> decommissioned ──[penjualan / pemusnahan diposting]──> disposed
```

Aturan yang menjaganya:

- Dokumen dekomisioning **ditolak** kalau asetnya sudah `decommissioned` atau `disposed`.
- Dokumen penjualan dan pemusnahan **ditolak** kalau asetnya belum `decommissioned`.

Jadi tidak ada jalan menjual aset yang persetujuan penghentiannya belum keluar.

## Persetujuan datang dari Core, bukan dari sini

Dokumen dekomisioning tidak menyetujui dirinya sendiri. Saat dibuat, app mengajukannya ke workflow milik Core lewat kontrak `WorkflowEngine`, lalu menunggu.

Core menjalankan alur persetujuan yang dikonfigurasi admin tenant — siapa approver-nya, berapa tahap, dan sebagainya — lalu memancarkan keputusannya sebagai event `WorkflowDecisionTaken`. Amplop `core.workflow.decision.v2` tetap ditulis ke outbox untuk penerima yang berada di luar proses.

Yang perlu dipahami saat menulis kode di sini:

- App **tidak tahu dan tidak boleh tahu** siapa approver-nya. Itu urusan Core.
- Keputusan bisa datang **berhari-hari kemudian**. Karena itu id korelasi disimpan pada dokumen sejak awal — membacanya dari permintaan yang sedang berjalan tidak mungkin, karena permintaan itu sudah lama selesai.
- Boleh atau tidak **pengaju menyetujui dokumennya sendiri** ditentukan parameter workflow milik tenant, bukan aturan mati di dalam mesin. Bawaannya boleh, sama seperti Dynamics 365; admin menyalakan larangannya di `Settings > Workflow`. Ketika menyala, pengaju dikeluarkan dari daftar penerima tugas — jadi dokumen yang penerimanya tinggal dia sendiri ditolak beserta alasannya, bukan menggantung.
- Keputusan yang sama tidak diterapkan dua kali: `aset_processed_core_events` menyimpan id event yang sudah diproses.

Setelah `approved` diterima, aset menjadi `decommissioned`.

## Aturan yang dijaga

**Entitas dan unit kerja dokumen harus sama dengan asetnya.** Dokumen yang mengaku milik badan hukum lain daripada asetnya ditolak. Ini menutup jalan memindahkan aset antar badan hukum lewat pintu belakang.

**Pembuatan dokumen idempoten.** Sama seperti master: `Idempotency-Key` wajib, kunci yang sama mengembalikan dokumen yang sama.

**Dokumen dekomisioning dan pengajuannya lahir bersama-sama.** Keduanya satu transaksi: kalau pengajuannya gagal, dokumennya tidak jadi dibuat dan nomornya tidak jadi terbit. Pengulangan pengiriman tetap ada untuk dokumen lama yang sempat tersimpan tanpa instance, dari masa pengajuan masih berjalan di luar transaksi.

**Aset menjadi `disposed` hanya saat penjualan atau pemusnahan diposting.** Membuat dokumennya hanya menyimpan draf; lihat bagian berikut.

## Penjualan dan pemusnahan: draf lalu posting

Sejak rilis ini penjualan dan pemusnahan mengikuti alur jurnal aset tetap Business Central: baris *Disposal* disusun, diperiksa lewat *Preview Posting*, lalu *Post*.

| Langkah | Endpoint | Permission | Hasil |
| --- | --- | --- | --- |
| Buat draf | `POST /api/v1/{penjualan-aset\|pemusnahan-aset}` | `.create` | Dokumen `draft`, nomor terbit. Aset dan bukunya tidak berubah. |
| Ubah draf | `PATCH …/{id}` | `.create` | Tanggal, nilai penjualan, keterangan. Asetnya tetap. |
| Pratinjau posting | `GET …/{id}/pratinjau-posting` | `.post` atau `.create` | Saldo buku yang dikeluarkan, laba/rugi, jurnalnya, dan yang menahan posting. Tidak menyimpan apa pun. |
| Posting | `POST …/{id}/posting` | `.post` | Jurnal pelepasan terbit, aset `disposed`, seluruh bukunya ditutup per tanggal dokumen, dokumen `posted`. Satu transaksi. |
| Batalkan draf | `POST …/{id}/batal` | `.create` | Dokumen `cancelled`. Dokumen siklus tidak pernah dihapus. |

**Perubahan alur dari rilis sebelumnya.** Dulu dokumen pelepasan langsung melepas aset dan menutup bukunya saat disimpan, padahal statusnya tetap `draft`, dan tidak ada jurnal. Sekarang menyimpan hanya membuat draf, dan pelepasan terjadi saat diposting. Dokumen lama ditandai `posted` oleh migration `2026_10_01_130000_mark_existing_disposals_as_posted`, karena asetnya memang sudah dilepas; jurnalnya tidak diterbitkan mundur. Laporan penjualan dan pemusnahan hanya membaca dokumen `posted`.

**Posting punya duty sendiri**: `management-aset.penjualan-aset.posting` dan `management-aset.pemusnahan-aset.posting`, dengan permission `.post`. Alasannya sama dengan duty "Post penyusutan ke aplikasi finance": role yang menyusun draf tidak diam-diam dapat melepas aset dan mengirim jurnalnya. Role yang dulu bisa melepas aset lewat `.create` kini perlu duty posting itu ditambahkan oleh admin tenant.

**Yang menahan posting** (`DisposalPosting::blockers()`, sama untuk pratinjau dan posting):

- Aset belum disetujui untuk dekomisioning, atau sudah dilepas lewat dokumen lain. Dua draf untuk aset yang sama boleh ada; hanya yang pertama diposting yang berhasil, karena asetnya dikunci dan diperiksa ulang di dalam transaksi.
- **Penyusutan sampai tanggal pelepasan belum beres** (`Services/BookPeriods`): buku aset masih punya usulan yang belum difinalkan, atau sudah disusutkan sampai sesudah tanggal pelepasan dan periode itu belum dibalik. Padanannya urutan BC: penyusutan dihitung dulu sampai tanggal pelepasan, lalu nilai buku pada tanggal itu yang dikeluarkan. Modul ini tidak menghitung penyusutan sebagian periode otomatis seperti *Depr. until FA Posting Date* BC; pengguna mengusulkan dan memfinalkan periode sampai tanggal pelepasan lewat layar Penyusutan aset, atau memilih tanggal pelepasan di akhir periode terakhir yang sudah final. Periode final yang belum di-post ke finance tidak menahan: "Post penyusutan" tetap mengirimnya kemudian, bertanggal akhir periodenya.
- Pemusnahan membawa nilai penjualan. Pemusnahan tidak punya hasil; aset yang dijual sebagai rongsokan dicatat sebagai penjualan.
- Nilai yang lebih halus dari presisi mata uang.

## Jurnal pelepasan

`Services/DisposalPosting` menerbitkan `asset.disposal_sale` untuk penjualan dan `asset.disposal_scrap` untuk pemusnahan ke [feed posting finance](/dev/34-feed-posting-finance), di dalam transaksi posting, sebelum buku ditutup. Padanannya posting type *Disposal* BC dengan metode *Net* (`Calculate Disposal`, `FA Get G/L Account No.`) dan transaksi *Disposal - sale* / *Disposal - scrap* F&O ([posting profile aset tetap](https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/tasks/set-up-fixed-asset-posting-profiles)). Dua jenis posting seperti F&O, supaya aplikasi finance dapat memperlakukan penjualan dan pemusnahan berbeda.

Nilainya dari **buku yang di-post ke finance** untuk group aset itu (buku yang dulu mem-post perolehannya, K-26). Buku lain, misalnya fiskal, ikut ditutup tanpa jurnal. Group tanpa buku yang di-post melepas aset tanpa jurnal.

| Baris | Akun posting group | Nilai | Unit |
| --- | --- | --- | --- |
| Dr | `accumulated_depreciation_account_id` | Akumulasi penyusutan buku itu | Unit pengguna, seperti "Post penyusutan" |
| Dr | `write_down_account_id` | Penurunan nilai yang tercatat | Dimensi keuangan aset |
| Cr | `acquisition_account_id` | Harga perolehan buku itu | Dimensi keuangan aset, seperti jurnal perolehan |
| Cr | `appreciation_account_id` | Kenaikan nilai yang tercatat | Dimensi keuangan aset |
| Dr | `disposal_proceeds_account_id` | Nilai penjualan (penjualan saja) | Unit pengguna |
| Cr | `disposal_gain_account_id` | Hasil − nilai buku, bila positif | Unit pengguna (department) |
| Dr | `disposal_loss_account_id` | Nilai buku − hasil, bila positif | Unit pengguna (department) |

Baris bernilai nol tidak dikirim. Nilai buku dihitung dari keempat saldo (harga perolehan − akumulasi − penurunan nilai + kenaikan nilai), jadi jurnalnya selalu seimbang. Penurunan dan kenaikan nilai dibalik bersama harga perolehan, seperti *Write-Down Acc. on Disposal* dan *Appreciation Acc. on Disposal* BC, karena keduanya bagian nilai buku.

Contoh: ambulans 48.000.000, akumulasi 1.000.000, dijual 50.000.000 — Dr Akumulasi 1.000.000, Cr Aset Tetap 48.000.000, Dr Piutang Penjualan Aset 50.000.000, Cr Laba Pelepasan 3.000.000.

- `posting_id` `AST-DSP-<id aset>`: satu aset hanya dilepas sekali, jadi pratinjau dan posting menyebut posting yang sama, dan percobaan ulang tidak menerbitkan yang kedua.
- Tanggal jurnal dan tanggal dokumen = tanggal pelepasan. Bila sebelum cutover atau feed entitas legal mati, posting terbit `manual`.
- Tidak ada `vendor`, pelanggan, atau PPN Keluaran. Tagihan ke pembeli dan pajaknya dibuat di aplikasi finance; akun hasil penjualan berperan sebagai *Sales Bal. Acc.* BC.
- Pemetaan akun yang kosong tidak menahan posting: posting terbit `held`, aset tetap dilepas (K-18).
- Surplus revaluasi yang masih ada di ekuitas tidak dipindahkan ke saldo laba oleh jurnal ini (PSAK 16 / IAS 16 ¶41 memindahkannya langsung di ekuitas); itu jurnal penutup di aplikasi finance.

## Yang belum dirancang

`permintaan-pembelian-aset` rutenya sudah ada tetapi isinya belum dikerjakan. Perilaku yang akan dijanjikannya belum diputuskan, jadi tiga endpoint-nya sengaja **belum masuk kontrak**. Celah itu tercatat di daftar `DEFERRED` pada `contracts/check-contract-coverage.py` dan dicetak tiap kali pemeriksa jalan — supaya ia tidak terlupa, bukan supaya ia dimaafkan.

Jangan menulis kontraknya sebelum perilakunya diputuskan; kontrak yang mendahului keputusan menggambarkan bentuk yang tidak bisa diandalkan pemanggil.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `src/Http/Controllers/transaksi/DokumenSiklusAset/DokumenSiklusAsetController.php` | Keempat jenis dokumen: daftar dan pembuatan |
| `src/Http/Controllers/transaksi/Disposal/AssetDisposalController.php` | Draf penjualan dan pemusnahan: baca, ubah, batal, pratinjau, posting |
| `src/Services/DisposalPosting.php` | Jurnal pelepasan dan penghalangnya |
| `src/Services/BookPeriods.php` | Aturan penyusutan sampai tanggal transaksi, bersama penyesuaian nilai |
| `tests/Feature/DisposalPostingTest.php` | Draf, pratinjau, posting, laba/rugi, penghalang, hak akses |
| `src/Listeners/TerapkanKeputusanDekomisioning.php` | Penerapan keputusan workflow |
| `src/Services/AssetApprovalWorkflow.php` | Pengajuan ke Core lewat kontrak |
| `src/Http/Controllers/transaksi/PermintaanPengadaanAset/PermintaanPengadaanAsetController.php` | Permintaan pembelian — rutenya ada, isinya belum dikerjakan |
| `contracts/asyncapi.yaml` | Kontrak event yang diterima |
| `ui/transactions/_shared/LifecycleDocumentPage.tsx` | Layar permintaan pembelian dan dekomisioning |
| `ui/transactions/disposal/` | Layar penjualan dan pemusnahan: daftar, draf, pratinjau, posting |

## Halaman terkait

- [Register aset](/apps/management-aset/transaction/register-aset/) — status hidup aset
- [Penyesuaian nilai aset](/apps/management-aset/transaction/penyesuaian-nilai-aset/) — penurunan dan kenaikan nilai yang dibalik saat pelepasan
- [Posting group aset](/apps/management-aset/master/posting-group/) — akun jurnal pelepasan
- [Feed posting finance](/dev/34-feed-posting-finance) — jalur jurnalnya
- [API dan integrasi](/dev/04-api-and-integration) — bentuk event dan tanda tangannya
- [Identity dan access](/dev/09-identity-and-access) — permission
