# Feed posting finance: aset ke aplikasi finance pelanggan

Rencana kerja, bukan desain kanonik. Ditulis 21 September 2026 setelah QA meminta nilai perolehan
dan penyusutan aset bisa **ditarik** oleh aplikasi finance in-house (selanjutnya *old-finance*),
dan tetap bisa dipakai klien lain yang punya aplikasi finance sendiri. Dokumen ini ditulis supaya
tim, konsultan akuntansi, dan tim old-finance bisa bekerja **tanpa ikut percakapan yang
melahirkannya**. Setiap keputusan membawa alasannya. Butir kerjanya ada di
[TODO feed posting finance](/todo/feed-posting-finance/TODO).

Halaman ini **menggantikan** bagian "Kontrak ke backoffice" di
[Penyusutan dan bridge](/todo/managementaset/03-penyusutan-dan-bridge-backoffice). Bagian itu
memutuskan V1 tidak mengirim debit, kredit, maupun akun. Keputusan itu dibalik di sini, dan
alasannya ada di keputusan K-04.

## Pertanyaan yang dijawab halaman ini

- Bagaimana nilai perolehan, saldo awal, dan penyusutan aset sampai ke buku besar aplikasi finance
  milik pelanggan, tanpa dobel dan tanpa hilang?
- Siapa yang menentukan akun, dan bagaimana akun tetap cocok kalau pelanggan mengganti nama atau
  nomor akunnya?
- Bagaimana klinik dan poli sampai ke finance sebagai dimensi keuangan, dan apa bedanya dengan
  lokasi fisik aset?
- Bagaimana satu desain melayani dua kebijakan akuntansi: perolehan langsung ke hutang, atau lewat
  akun perantara?
- Apa saja yang dikerjakan, di bagian repo mana, dengan urutan apa?

## Kenapa ada

1. **QA meminta nilai perolehan dan penyusutan bisa ditarik old-finance.** Sampai sekarang modul
   aset menulis baris `aset_tr_export_penyusutan` setiap kali periode difinalkan, tetapi tidak ada
   satu pun yang membacanya: tidak ada rute, perintah, atau listener. Perolehan bahkan tidak
   menghasilkan apa pun.
2. **Klien lain juga punya finance sendiri**, dan finance mereka belum tentu punya modul aset tetap.
   Yang pasti bisa diterima semua aplikasi finance adalah **jurnal**: tanggal, akun, debit, kredit,
   dimensi. Karena itu feed ini mengirim jurnal siap impor, bukan sekadar data aset.
3. **CoreERP belum punya modul Finance/GL**, dan tidak akan dibangun demi integrasi ini. Yang
   dibutuhkan hanya daftar akun sebagai referensi, bukan buku besar.

## Istilah

Istilah mengikuti Dynamics 365. Nama field di kontrak memakai bahasa Inggris, sama dengan nama
field kontrak lain di repo ini.

| Istilah | Arti | Padanan Dynamics |
| --- | --- | --- |
| **Posting** | Satu kiriman ke finance: satu dokumen sumber dengan jurnal yang seimbang | Subledger journal entry (F&O), G/L entries (BC) |
| `source_document` | Dokumen asal yang melahirkan posting: penerimaan aset, proses penyusutan | Source document (F&O) |
| `journal_lines` | Baris jurnal debit/kredit | Journal lines (BC) |
| `financial_dimensions` | Pasangan kode/nilai yang menempel di setiap baris jurnal | Financial dimensions (F&O), dimension set (BC) |
| **Post** | Posting diterima dan dibukukan oleh finance, status `posted` | Post |
| **Posting group aset** | Pemetaan group aset ke akun-akun yang dipakai | FA posting group (BC), fixed asset posting profile (F&O) |
| `settlement_mode` | Kebijakan jurnal perolehan: langsung ke hutang atau lewat akun perantara | Accrue liability on product receipt (F&O) |
| **Cutover** | Tanggal mulai posting dikirim, per entitas legal | Tanggal go-live migrasi |
| **Pembaca** | Sistem yang menarik feed, misalnya old-finance | Consumer |

## Keputusan

| ID | Keputusan | Alasan |
| --- | --- | --- |
| K-01 | **Kontrak CoreERP yang jadi acuan.** Old-finance yang menyesuaikan diri. Modul `api_finance` di old-finance belum pernah dipakai dan tidak dijadikan acuan. | Satu kontrak untuk banyak pembaca. Kalau kontrak mengikuti satu pembaca, pembaca berikutnya harus dibuatkan jalur khusus. |
| K-02 | **Engine di Core**, bukan di control-plane. Control-plane hanya menerima angka kesehatan lewat laporan agent. | Data keuangan tenant tidak boleh keluar dari server tempat datanya berada. Postingnya juga harus tabel Core, karena module tidak boleh membuat tabel Core (`ModuleTanpaKerangkaTest`). |
| K-03 | **Pembaca menarik (pull), lalu melapor balik (ack).** Posting yang belum di-ack disajikan ulang sampai di-ack. Tidak memakai kursor "id terakhir". | Old-finance belum punya API dan bisa mati saat update. Pull membuatnya tinggal melanjutkan. Kursor id bisa melewatkan baris yang commit-nya terlambat, sedangkan model ack tidak. |
| K-04 | **Posting membawa `journal_lines` siap impor.** CoreERP menyimpan pemetaan ke **kode akun milik finance pelanggan**, seperti aplikasi payroll yang mengekspor jurnal ke software akuntansi. | Semua aplikasi finance bisa mengimpor jurnal. Tidak semua bisa memetakan data aset sendiri. |
| K-05 | **Daftar akun referensi di Core**, per tenant dan opsional per entitas legal. Setiap akun punya `external_id` yang tidak boleh berubah (di old-finance: `Akun_ID`). Diimpor lewat CSV, dipilih lewat dropdown. | Ganti nama atau nomor akun tetap aman karena pemetaan menunjuk ID, bukan nomor. Tabel ini kelak diambil alih modul Finance sebagai master COA tanpa mengubah pemakainya. |
| K-06 | **Vendor master dibangun di Core, mengikuti Dynamics**: vendor adalah party di Global Address Book dengan peran `vendor`, per entitas legal. Pembaca yang menyinkronkan vendor dari Core ke sistemnya. | Keputusan pemilik produk. Skema perolehan langsung ke hutang wajib membawa vendor. |
| K-07 | **Kode dimensi = nomor operating unit baru di Core.** Dua dimensi: `BUSINESS_UNIT` (klinik) dan `DEPARTMENT` (poli). | Organization di Core tidak punya kode stabil lagi. Padanannya di F&O adalah *operating unit number*. Keputusan pemilik produk. |
| K-08 | **Lokasi fisik terpisah dari dimensi.** Lokasi (gedung › lantai › ruang) memetakan ke department. Kalau lokasi tidak punya pemetaan, pemetaan diambil dari lokasi induk terdekat. | Satu poli bisa tersebar di beberapa lantai dan ruangan. Memindahkan aset antar ruangan dalam poli yang sama tidak boleh mengubah jurnal. |
| K-09 | **Dimensi ditentukan jenis akun.** Akun neraca hanya membawa `BUSINESS_UNIT`. Akun laba rugi membawa `BUSINESS_UNIT` + `DEPARTMENT`. | Rekomendasi Microsoft: dua account structure, neraca dan laba rugi. |
| K-10 | **`settlement_mode` per entitas legal, dengan tanggal berlaku.** Default `direct_payable` (skema konsultan). `clearing` tetap didukung. Mode dicatat di setiap posting, dan koreksi selalu mewarisi mode posting aslinya. | Dynamics mendukung kedua cara. Mengganti mode di tengah jalan tidak boleh membuat koreksi masuk ke akun yang berbeda dari jurnal aslinya. |
| K-11 | **PPN diisi di penerimaan aset** dan dijurnal ke akun PPN Masukan. | Keputusan konsultan. |
| K-12 | **Cara perolehan** (pembelian, hibah, saldo awal) disiapkan sebagai kerangka. Untuk sekarang semuanya memakai akun yang sama. | Kata konsultan, di sektor swasta hibah tetap masuk nilai perolehan. Di pemerintah dibedakan, jadi kerangkanya disiapkan. |
| K-13 | **Saldo awal dikirim ke finance** sebagai `asset.opening_balance`, dengan akun penyeimbang yang sudah ada di finance. | GL old-finance masih nol, jadi tidak ada risiko dobel. |
| K-14 | **Penyusutan di-post lewat proses "Post penyusutan"** per entitas legal × buku × periode. Jurnal diringkas per akun + dimensi, dibulatkan per aset dulu lalu dijumlah. Rincian per aset ikut di `details`. | Konsultan meminta ringkasan per group. Total di GL harus sama persis dengan total register aset. Mirip *depreciation proposal* di F&O. |
| K-15 | **Buku dengan `posting_layer = none` tidak pernah di-post.** Saklar `export_to_backoffice` dilebur ke `posting_layer`. | Di F&O, "Post to general ledger = No" otomatis menjadikan posting layer *None*. Dua saklar yang maknanya tumpang tindih sudah terbukti menghasilkan reversal yang terekspor walaupun aslinya tidak. |
| K-16 | **Cutover per entitas legal diatur di Core.** Posting bertanggal sebelum cutover berstatus `manual` dan tidak disajikan. | Supaya riwayat yang sudah dijurnal manual tidak terkirim ulang. |
| K-17 | **Posting yang ditolak tidak diberi tanggal ulang otomatis.** Pengguna membuat koreksi di periode yang masih terbuka. | Tanggal akuntansi tidak boleh bergeser diam-diam. |
| K-18 | **Tidak ada fallback diam-diam** di kedua sisi. Pemetaan kosong berarti posting ditahan. Kode tak dikenal di sisi pembaca berarti posting ditolak. | Verifikator lama pernah menjurnal ke akun 0 tanpa error karena konfigurasinya kosong. |
| K-19 | **Hanya IDR** di fase ini. | Belum ada kebutuhan multi-currency (lihat `FIN-20`). |

## Alur

```text
Modul aset                         Core                                   Pembaca
─────────────                      ──────────────────────────             ─────────────────────
penerimaan selesai ──┐
saldo awal ──────────┼─ PenerbitPosting ─▶ finance_postings ──GET──▶ old-finance (sekarang)
koreksi nilai ───────┤   (satu transaksi)   held / pending      ◀─ack── modul Finance (nanti)
post penyusutan ─────┤                      posted / rejected          konektor lain (nanti)
reversal ────────────┘                      manual
                                                 │
                                   agent ──▶ control-plane: jumlah pending/ditolak, tarikan terakhir
```

Posting ditulis **dalam transaksi database yang sama** dengan dokumen sumbernya. Penerimaan yang
gagal disimpan tidak meninggalkan posting yatim, dan penerimaan yang berhasil pasti punya posting.

## Kontrak

### Bentuk posting

```json
{
  "contract_version": 1,
  "posting_id": "AST-ACQ-01J9Z3...",
  "posting_type": "asset.acquisition",
  "settlement_mode": "direct_payable",
  "legal_entity": { "id": "01J...", "code": "PT-METTA" },
  "posting_date": "2026-09-28",
  "source_document": {
    "module": "management-aset",
    "type": "penerimaan-aset",
    "number": "PNA-2026-09-0007",
    "description": "Penerimaan ambulans"
  },
  "vendor": { "id": "01J...", "number": "VND-000123", "name": "PT Karoseri Sehat" },
  "vendor_invoice_reference": null,
  "journal_lines": [
    {
      "line_no": 1,
      "account": { "external_id": "1452", "code": "1-2300", "name": "Aset Tetap - Kendaraan" },
      "debit": "500000000.00",
      "credit": "0.00",
      "description": "KEND-0012 Ambulans",
      "financial_dimensions": [
        { "code": "BUSINESS_UNIT", "value": "KLN-A", "name": "Klinik Metta A" }
      ]
    },
    {
      "line_no": 2,
      "account": { "external_id": "1501", "code": "1-1500", "name": "PPN Masukan" },
      "debit": "55000000.00",
      "credit": "0.00",
      "description": "PPN KEND-0012",
      "financial_dimensions": [
        { "code": "BUSINESS_UNIT", "value": "KLN-A", "name": "Klinik Metta A" }
      ]
    },
    {
      "line_no": 3,
      "account": { "external_id": "2110", "code": "2-1100", "name": "Hutang Usaha" },
      "debit": "0.00",
      "credit": "555000000.00",
      "description": "PT Karoseri Sehat",
      "financial_dimensions": [
        { "code": "BUSINESS_UNIT", "value": "KLN-A", "name": "Klinik Metta A" }
      ]
    }
  ],
  "totals": { "debit": "555000000.00", "credit": "555000000.00", "currency": "IDR" },
  "reverses_posting_id": null,
  "adjusts_posting_id": null,
  "details": {
    "assets": [
      { "asset_code": "KEND-0012", "asset_group": "KENDARAAN", "book": "KOMERSIAL",
        "acquisition_value": "500000000.00", "tax_amount": "55000000.00" }
    ]
  }
}
```

Aturan bentuk:

- Nilai uang selalu **string desimal dua angka**, tidak pernah float dan tidak pernah format
  tampilan lokal.
- `posting_date` adalah **tanggal saja**. Nilainya diambil dari dokumen, bukan dari jam server.
- `journal_lines` wajib seimbang. CoreERP menolak menerbitkan posting yang tidak seimbang.
- Akun dikirim dengan `external_id` dan `code` sekaligus. Pembaca mencocokkan lewat `external_id`.
- `details` hanya informasi untuk pelacakan dan laporan. Pembaca tidak boleh menjurnal dari
  `details`.

### Endpoint

Semua endpoint ada di bawah `/api/internal/v1`, dijaga klien integrasi (lihat Keamanan), dan
dicatat di `apps/core/contracts/openapi-internal.yaml`.

| Endpoint | Guna |
| --- | --- |
| `GET /finance-postings?status=pending&limit=100` | Posting yang belum di-ack, urut `posting_date` lalu waktu terbit. Disajikan ulang sampai di-ack. |
| `POST /finance-postings/{posting_id}/ack` | Hasil dari pembaca: `posted` + `external_reference` (nomor voucher/faktur), atau `rejected` + `reason_code` + `reason`. Idempoten: ack yang sama boleh diulang. |
| `GET /vendors?updated_since=…` | Sinkron vendor untuk pembaca (K-06) |
| `GET /operating-units?updated_since=…` | Sinkron kode dimensi untuk tabel penerjemah pembaca (endpoint yang sudah ada, ditambah `number`) |

Kode alasan penolakan: `PERIOD_CLOSED`, `UNKNOWN_ACCOUNT`, `UNKNOWN_DIMENSION`, `UNKNOWN_VENDOR`,
`UNKNOWN_LEGAL_ENTITY`, `INVALID`.

### Status posting

| Status | Arti | Disajikan ke pembaca? |
| --- | --- | --- |
| `held` | Ditahan di CoreERP: pemetaan akun kosong, akun nonaktif, atau dimensi tidak bisa dibentuk. Alasannya dicatat. | Tidak |
| `pending` | Siap ditarik | Ya, berulang sampai di-ack |
| `posted` | Pembaca sudah membukukan | Tidak |
| `rejected` | Pembaca menolak, dengan kode alasan | Tidak |
| `manual` | Bertanggal sebelum cutover, atau ditandai manual oleh pengguna dengan alasan | Tidak |

## Jenis posting dan jurnalnya

Contoh memakai COA ini: `1-2300` Aset Tetap – Kendaraan, `1-2390` Akumulasi Penyusutan –
Kendaraan, `6-5100` Beban Penyusutan Kendaraan, `1-1500` PPN Masukan, `2-1100` Hutang Usaha,
`2-1900` Aset Diterima Belum Difakturkan (perantara), `3-9000` Penyeimbang Saldo Awal.

### `asset.acquisition`: penerimaan aset selesai

| Mode | Debit | Kredit |
| --- | --- | --- |
| `direct_payable` | `1-2300` 500 · `1-1500` 55 | `2-1100` 555 |
| `clearing` | `1-2300` 500 · `1-1500` 55 | `2-1900` 555 |

Pada `direct_payable`, **posting inilah hutangnya**. Modul hutang pembaca membuat faktur dari
posting ini dan menempelkan nomor faktur vendor belakangan, tanpa jurnal kedua. Pada `clearing`,
pembaca membuat faktur sendiri dengan jurnal Dr `2-1900` / Cr `2-1100`, dan saldo `2-1900` harus
kembali nol.

### `asset.acquisition_adjustment`: koreksi nilai perolehan

Faktur ternyata 510, padahal tercatat 500. Koreksi **dimulai dari modul aset**, supaya register
aset (dasar penyusutan), GL, dan hutang sama-sama menjadi 510. Mode mengikuti posting asal
(`adjusts_posting_id`):

| Mode asal | Debit | Kredit |
| --- | --- | --- |
| `direct_payable` | `1-2300` 10 | `2-1100` 10 |
| `clearing` | `1-2300` 10 | `2-1900` 10 |

Fase ini hanya mendukung koreksi **sebelum ada periode penyusutan**, sama seperti aturan
`AsetController` sekarang.

### `asset.opening_balance`: saldo awal saat cutover

| Debit | Kredit |
| --- | --- |
| `1-2300` 100 (harga perolehan) | `1-2390` 40 (akumulasi s/d cutover) · `3-9000` 60 (nilai buku) |

### `asset.depreciation`: proses "Post penyusutan"

Satu posting untuk satu entitas legal × satu buku × satu periode. Diringkas per akun + dimensi:

| Debit | Kredit |
| --- | --- |
| `6-5100` per (klinik, poli), misalnya KLN-A/POLI-UMUM 2,5 dan KLN-A/UGD 1,2 | `1-2390` per klinik, misalnya KLN-A 3,7 |

Beban membawa BU + department (akun laba rugi). Akumulasi hanya membawa BU (akun neraca).

### `asset.depreciation_reversal`: pembalikan periode

Kebalikan dari baris posting asal, merujuk `reverses_posting_id`. Kalau periode aslinya belum
pernah di-post, tidak ada posting pembalikan.

## Validasi dan penahanan

`PenerbitPosting` memeriksa semua hal berikut sebelum posting berstatus `pending`:

1. Jurnal seimbang, dan setiap baris hanya berisi debit atau kredit, tidak dua-duanya.
2. Setiap akun ada di daftar akun referensi dan aktif.
3. Setiap baris mendapat dimensi sesuai jenis akunnya, dan setiap operating unit yang dipakai punya
   nomor.
4. Pada `direct_payable` + pembelian, vendor terisi.
5. `posting_id` belum pernah terbit. Menerbitkan ulang dengan `posting_id` yang sama
   mengembalikan posting yang sudah ada.
6. Tanggal sebelum cutover → `manual`.

Kalau poin 2 atau 3 gagal, posting tetap dibuat dengan status `held` dan alasannya. Dokumen sumber
tetap tersimpan, karena kesalahan pemetaan tidak boleh menghalangi pekerjaan operasional. Setelah
pemetaan diperbaiki, tombol "Validasi ulang" memindahkan posting ke `pending`.

## Keamanan

- **Klien integrasi per tenant.** Guard `internal-app` yang ada mensyaratkan entitlement dan
  instalasi module, jadi tidak cocok untuk sistem eksternal. Klien integrasi punya token (hanya
  digest yang disimpan, ditampilkan sekali saat terbit), scope (`finance-postings.read`,
  `finance-postings.ack`, `vendors.read`, `operating-units.read`), allowlist IP, dan status.
- `tenant_id` diambil dari klien integrasi, tidak pernah dari URL, query, atau body.
- **Salinan sandbox tidak menyajikan posting.** Kalau `ActiveEnvironment::outboundAllowed()`
  bernilai false, endpoint feed menolak melayani. Tanpa ini, server uji bisa memposting ke finance
  produksi.
- Pembaca di jaringan klien memanggil ke dalam lewat LAN. Server klien tidak membuka port baru ke
  internet.

## Operasional

- **Layar pantau di Core**: daftar posting per status, detail jurnal, alasan tahan/tolak, aksi
  "tandai manual" dengan alasan, dan "validasi ulang".
- **Laporan agent** membawa `finance_feed`: jumlah `pending`, `held`, `rejected`, umur posting
  `pending` tertua, dan waktu tarikan terakhir. Control-plane menampilkannya di halaman site,
  supaya masalah terlihat sebelum klien menelepon.

## Di luar cakupan

- Pelepasan aset (jual/musnah) dan laba/rugi pelepasan. Jenis posting-nya dicadangkan di kontrak.
- Reklasifikasi akumulasi saat aset pindah antar klinik (BU). Pindah antar poli dalam satu klinik
  sudah aman, karena hanya penyusutan berikutnya yang pindah dimensi.
- Koreksi nilai perolehan setelah ada periode penyusutan.
- Laporan rekonsiliasi saldo perantara untuk mode `clearing`.
- Modul Finance/GL, COA penuh, pajak (`FIN-23`, `FIN-24`), dan multi-currency (`FIN-20`).
- Posting dari modul lain (HR, procurement). Feed-nya dibangun umum, tapi penerbit pertamanya
  hanya modul aset.

## Dependensi

| Pihak | Yang dibutuhkan | Menghalangi |
| --- | --- | --- |
| Konsultan akuntansi | Isi posting group per group aset | Posting tertahan (`held`) sampai terisi, tapi pengembangan bisa jalan |
| Konsultan akuntansi | Konfirmasi akun PPN Masukan, Hutang, dan penyeimbang saldo awal | Uji terima |
| Tim old-finance | Job tarik, tabel penerjemah (akun, vendor, dimensi, entitas legal), faktur dari posting `direct_payable` tanpa jurnal kedua, ack, tanpa fallback | Uji terima E2E |
| Tim old-finance | Instance dev untuk uji E2E | Uji terima E2E |

## Kriteria terima

Fase ini selesai kalau semua skenario berikut lulus di instance dev old-finance, bukan hanya lewat
tiruan HTTP:

1. Penerimaan satu ambulans + PPN muncul sebagai satu faktur di modul hutang old-finance, dengan
   hutang 555 dan **tanpa jurnal kedua**.
2. Satu proses "Post penyusutan" menghasilkan jurnal ringkas per klinik/poli, dan totalnya sama
   persis dengan total register aset.
3. Reversal satu periode menghasilkan jurnal balik yang merujuk posting asal.
4. Posting ke periode yang sudah ditutup old-finance ditolak dengan `PERIOD_CLOSED` dan tampil di
   layar pantau.
5. Saldo awal satu aset lama terbukukan dengan nilai buku di akun penyeimbang.
6. Old-finance mengganti nama dan nomor satu akun, lalu setelah impor ulang, posting berikutnya
   memakai nomor baru tanpa mengubah pemetaan.
7. Salinan sandbox environment tidak menyajikan satu pun posting.
8. Mode diganti dari `direct_payable` ke `clearing`. Koreksi atas penerimaan sebelum pergantian
   tetap masuk ke Hutang.

## Keadaan kode per 21 September 2026

| Temuan | Bukti |
| --- | --- |
| Aset hanya lahir lewat penerimaan, dengan akumulasi 0. Belum ada jalur saldo awal. | `PembuatAset::buatBuku` di `modules/apperp/management-aset/src/Services/PembuatAset.php` |
| Penerimaan aset tidak punya vendor maupun PPN | `2026_09_18_110000_create_penerimaan_aset_tables.php` |
| Ekspor penyusutan ditulis tetapi tidak pernah dibaca. `acknowledged_at` tidak pernah diisi. | `DepreciationController::finalize` dan `DepreciationExport` |
| Reversal mengekspor tanpa memeriksa `export_to_backoffice` | `DepreciationController::reverse` |
| `posting_layer` disimpan tetapi tidak dipakai | `modules/apperp/management-aset/src/Models/master/BukuPenyusutan.php` |
| Dimensi lokasi tidak mewarisi dari induk | `PembuatAset::dimensiLokasi`, `MutasiAsetController::dimensiLokasi` |
| Halaman profil posting masih placeholder | `modules/apperp/management-aset/ui/pengaturan-aset-tetap/PengaturanAsetTetapPlaceholderPage.tsx` |
| Organization tidak punya kode stabil | `2026_07_24_010000_standardize_legal_entity_company_code.php` menghapus `organizations.code` |
| Peran `vendor` ada, tetapi belum ada yang mendaftarkannya | `apps/core/app/Models/PartyRoleRegistration.php` |
| Guard mesin mensyaratkan instalasi module | `apps/core/app/Http/Middleware/AuthenticateAppService.php` |
| Outbox yang ada hanya push HTTP, belum ada pola pull + ack | `apps/core/app/Console/Commands/PublishWorkflowEvents.php` |

## Sumber

- [Ledger, subledger, and subledger journal entries](https://learn.microsoft.com/en-us/dynamics365/finance/general-ledger/ledger-subledger)
- [Accounting distributions and journal entries for vendor invoices](https://learn.microsoft.com/en-us/dynamics365/finance/accounts-payable/accounting-distributions-subledger-journal-entries-vendor-invoices)
- [General journal entity](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/entity-general-journal)
- [Business Central API v2.0: dimensionSetLines](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/api-reference/v2.0/api/dynamics_dimensionsetline_create)
- [Set up general fixed assets information (FA posting groups)](https://learn.microsoft.com/en-us/dynamics365/business-central/fa-how-setup-general)
- [Set up FA depreciation (G/L integration per depreciation book)](https://learn.microsoft.com/en-us/dynamics365/business-central/fa-how-setup-depreciation)
- [Acquire fixed assets (Business Central)](https://learn.microsoft.com/en-us/dynamics365/business-central/fa-how-acquire)
- [Fixed assets integration (F&O)](https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/fixed-asset-integration)
- [Purchase order posting (accrue liability on product receipt)](https://github.com/MicrosoftDocs/Dynamics-365-Unified-Operations-Public/blob/main/articles/finance/general-ledger/purchase-order-posting.md)
- [Post fixed asset transactions to posting layers](https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/post-fixed-asset-transactions-posting-layers)
- [Create a depreciation proposal (summarize depreciation)](https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/tasks/create-depreciation-proposal)
- [Account structures overview](https://learn.microsoft.com/en-us/dynamics365/finance/general-ledger/configure-account-structures)
- [Financial dimensions](https://learn.microsoft.com/en-us/dynamics365/finance/general-ledger/financial-dimensions)
- [How I post opening balances for fixed assets (Business Central)](https://thedynamicsexplorer.com/2023/09/19/dynamics-365-business-central-how-i-post-opening-balances-for-reducing-balance-fixed-assets/)
