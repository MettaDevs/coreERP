# TODO feed posting finance

Butir kerja untuk [Feed posting finance](/todo/feed-posting-finance/). Baca PRD-nya lebih dulu.
Setiap keputusan yang dirujuk di sini (K-01 dan seterusnya) dijelaskan alasannya di sana.

Status mengikuti [aturan backlog](/todo/): `[ ]` belum, `[~]` sedang dikerjakan, `[x]` selesai
**dan** ada test yang membuktikannya. Setiap area punya **Selesai bila** (kriteria terima area) dan
**Setelah** (area yang harus selesai lebih dulu).

Urutan kerja yang disarankan:

```text
0 ─┬─▶ 1 ─┐
   ├─▶ 2 ─┤
   ├─▶ 3 ─┼─▶ 6 ─▶ 7
   ├─▶ 4 ─┤    │
   └─▶ 5 ─┘    ├─▶ 8 ─▶ 9 ─▶ 10 ─▶ 11 ─▶ 12
               │
               └─▶ 13, 14        15 (tim old-finance, paralel sejak 6 selesai) ─▶ 16
```

---

### 0. [ ] Prasyarat non-kode

**Tempat:** pemilik produk, konsultan akuntansi, tim old-finance · **Setelah:** — ·
**Selesai bila:** semua jawaban tercatat di PRD, dan uji terima punya lingkungan.

- [ ] 0.1 Siapkan templat posting group aset untuk konsultan.
  - [ ] 0.1.1 Baris = group aset yang ada di tenant (ambil dari master group aset).
  - [ ] 0.1.2 Kolom = akun harga perolehan, akumulasi penyusutan, beban penyusutan, lawan hutang, perantara, PPN Masukan, penyeimbang saldo awal.
  - [ ] 0.1.3 Sertakan contoh jurnal per kolom, diambil dari bagian "Jenis posting dan jurnalnya" di PRD.
- [ ] 0.2 Konfirmasi ke konsultan: akun PPN Masukan, akun Hutang Usaha, dan akun penyeimbang saldo awal. Nomor dan `Akun_ID`-nya.
- [ ] 0.3 Konfirmasi ke konsultan: aturan dimensi (neraca → BU, laba rugi → BU + department) dan ringkasan penyusutan per akun + dimensi.
- [ ] 0.4 Tetapkan pemilik dan jadwal pekerjaan di sisi old-finance (area 15).
- [ ] 0.5 Tetapkan tanggal cutover per entitas legal.
- [ ] 0.6 Siapkan instance dev old-finance yang bisa dijangkau server dev CoreERP, untuk area 16.

---

### 1. [ ] Core: nomor operating unit sebagai kode dimensi

**Tempat:** `apps/core` · **Setelah:** — · **Selesai bila:** setiap operating unit bisa diberi
nomor unik, nomor itu terbaca lewat kontrak module dan `/internal/v1/operating-units`, dan BU induk
dari sebuah department bisa ditemukan (K-07).

- [ ] 1.1 Migration: tambah kolom `number` pada `operating_units`.
  - [ ] 1.1.1 `string(30)`, nullable dulu supaya data lama tidak patah.
  - [ ] 1.1.2 Unik per tenant. Tenant dibaca lewat `organizations.tenant_id`, jadi pertimbangkan menyalin `tenant_id` ke `operating_units`, atau indeks unik gabungan lewat kolom baru.
  - [ ] 1.1.3 Pastikan lolos `MigrasiKompatibelMundurTest` (aturan N-1). Kolom nullable baru aman untuk rilis sebelumnya.
- [ ] 1.2 Model dan validasi.
  - [ ] 1.2.1 Tambah `number` ke `$fillable` di `apps/core/app/Models/OperatingUnit.php`.
  - [ ] 1.2.2 Format: huruf besar, angka, `-`. Tanpa spasi.
  - [ ] 1.2.3 Nomor boleh diubah. Riwayat posting menyimpan nomor pada saat terbit (snapshot).
- [ ] 1.3 Layar organisasi (`apps/core/resources/js/pages/settings/organization.tsx`).
  - [ ] 1.3.1 Field "Nomor unit" pada form operating unit.
  - [ ] 1.3.2 Tampilkan nomor di pohon hierarki.
  - [ ] 1.3.3 Tanda peringatan pada BU/department yang belum bernomor, karena posting untuk unit itu akan tertahan.
- [ ] 1.4 Kontrak module `DirektoriOrganisasi` (`apps/core/app/Support/Modules/Contracts/`).
  - [ ] 1.4.1 `unitOperasi()` mengembalikan `number` dan `type` selain `id` dan `name`.
  - [ ] 1.4.2 Metode baru: BU induk dari sebuah org unit, lewat `organization_hierarchy_closures` pada versi hierarki yang berlaku untuk purpose `management`. Pola kuerinya ada di `apps/core/app/Support/DataPolicyAccessResolver.php`.
  - [ ] 1.4.3 Tetapkan perilaku kalau tidak ada BU induk: kembalikan `null`, dan penerbit posting menjadikannya alasan `held`.
- [ ] 1.5 Internal API `/internal/v1/operating-units`.
  - [ ] 1.5.1 Tambah `number`, `type`, dan `updated_since` untuk sinkron.
  - [ ] 1.5.2 Perbarui `apps/core/contracts/openapi-internal.yaml`.
- [ ] 1.6 Test.
  - [ ] 1.6.1 Nomor unik per tenant, dan boleh sama di tenant lain.
  - [ ] 1.6.2 BU induk ditemukan untuk department dua tingkat di bawahnya.
  - [ ] 1.6.3 Department tanpa BU induk menghasilkan `null`.
  - [ ] 1.6.4 Anggaran query `AnggaranQueryPermintaanModuleTest` tetap lolos.

---

### 2. [ ] Core: vendor master gaya Dynamics

**Tempat:** `apps/core` · **Setelah:** — · **Selesai bila:** vendor bisa dibuat per entitas legal
sebagai party dengan peran `vendor`, dipilih modul lewat kontrak, dan ditarik pembaca lewat
`/internal/v1/vendors` (K-06).

- [ ] 2.1 Catatan studi: halaman *Vendors* di F&O dan BC.
  - [ ] 2.1.1 Vendor adalah party di Global Address Book dengan akun vendor per entitas legal.
  - [ ] 2.1.2 Tentukan kolom minimal fase ini: nomor vendor, party (nama), entitas legal, NPWP (opsional), status aktif. Vendor group dan syarat bayar ditunda (`FIN-26`).
  - [ ] 2.1.3 Tulis hasilnya sebagai bagian singkat di PRD.
- [ ] 2.2 Migration dan model.
  - [ ] 2.2.1 Tabel `vendors`: `id`, `tenant_id`, `legal_entity_id`, `party_id`, `number`, `tax_number` (nullable), `status`, timestamps, soft delete.
  - [ ] 2.2.2 Unik (`tenant_id`, `legal_entity_id`, `number`).
  - [ ] 2.2.3 Setiap vendor mendaftarkan baris di `party_role_registrations` dengan `role_code = vendor`.
- [ ] 2.3 Nomor vendor lewat number sequence Core (referensi baru, awalan default `VND`).
- [ ] 2.4 Layar Core.
  - [ ] 2.4.1 Daftar vendor dengan filter entitas legal dan status.
  - [ ] 2.4.2 Form buat/ubah. Party bisa dipilih dari yang ada atau dibuat baru.
  - [ ] 2.4.3 Permission, privilege, dan duty vendor.
- [ ] 2.5 Kontrak module `DaftarVendor`.
  - [ ] 2.5.1 `aktif(legalEntityId, cari)` dan `satu(vendorId)` mengembalikan `{id, number, name, tax_number, status}`.
  - [ ] 2.5.2 Bind di `apps/core/app/Support/Modules/CoreServices.php`.
- [ ] 2.6 Internal API `GET /internal/v1/vendors?updated_since=…` untuk klien integrasi (scope `vendors.read`) + OpenAPI.
- [ ] 2.7 Test.
  - [ ] 2.7.1 Vendor terdaftar sebagai peran `vendor` di party.
  - [ ] 2.7.2 Vendor tenant lain tidak terlihat.
  - [ ] 2.7.3 Sinkron `updated_since` hanya mengembalikan yang berubah.

---

### 3. [ ] Core: daftar akun referensi

**Tempat:** `apps/core` · **Setelah:** — · **Selesai bila:** daftar akun milik finance pelanggan
bisa diimpor, dicari, dan dipilih modul, dan ganti nama, ganti nomor, atau hapus-lalu-buat-ulang
di sisi finance berperilaku seperti di PRD (K-05).

- [ ] 3.1 Migration dan model.
  - [ ] 3.1.1 Tabel `finance_reference_accounts`: `id`, `tenant_id`, `legal_entity_id` (nullable = berlaku untuk semua entitas), `external_id`, `code`, `name`, `type` (`balance_sheet` / `profit_loss`), `active`, `synced_at`, timestamps.
  - [ ] 3.1.2 Unik (`tenant_id`, `legal_entity_id`, `external_id`).
- [ ] 3.2 Impor CSV.
  - [ ] 3.2.1 Templat kolom: `external_id,code,name,type,active`.
  - [ ] 3.2.2 Upsert berdasarkan `external_id`. Nama dan nomor boleh berubah.
  - [ ] 3.2.3 Akun yang hilang dari berkas **tidak** otomatis dinonaktifkan. Laporan impor menyebutnya, dan pengguna memutuskan.
  - [ ] 3.2.4 Validasi: `type` wajib salah satu dari dua nilai, dan `external_id` tidak boleh ganda dalam satu berkas.
  - [ ] 3.2.5 Laporan hasil: baru, berubah, tidak ada di berkas, ditolak (beserta baris dan alasannya).
- [ ] 3.3 Layar Core: daftar akun, pencarian, impor, dan riwayat impor.
- [ ] 3.4 Kontrak module `DaftarAkun`.
  - [ ] 3.4.1 `cari(legalEntityId, kata)` untuk dropdown.
  - [ ] 3.4.2 `satu(accountId)` mengembalikan `{id, external_id, code, name, type, active}`.
  - [ ] 3.4.3 Bind di `CoreServices.php`.
- [ ] 3.5 Test.
  - [ ] 3.5.1 Ganti nama: pemetaan tetap, nama baru terbaca.
  - [ ] 3.5.2 Ganti nomor dengan `external_id` sama: pemetaan tetap, nomor baru terbaca.
  - [ ] 3.5.3 Hapus lalu buat ulang dengan `external_id` baru: akun lama tetap ada, pengguna menonaktifkannya, dan posting yang memakainya tertahan.
  - [ ] 3.5.4 Akun tenant lain tidak terlihat.

---

### 4. [ ] Core: klien integrasi dan autentikasinya

**Tempat:** `apps/core` · **Setelah:** — · **Selesai bila:** pembaca eksternal bisa diberi token
bercakupan sempit, hanya dari IP yang diizinkan, dan ditolak di salinan sandbox.

- [ ] 4.1 Migration dan model `integration_clients`: `id`, `tenant_id`, `name`, `token_digest`, `scopes` (json), `allowed_ips` (json), `status`, `last_used_at`, timestamps.
- [ ] 4.2 Middleware.
  - [ ] 4.2.1 Header token berbentuk `<client_id>.<secret>`. Bandingkan digest dengan `hash_equals`, mengikuti pola `apps/core/app/Http/Middleware/AuthenticateAppService.php`.
  - [ ] 4.2.2 Periksa status, allowlist IP, dan scope per rute.
  - [ ] 4.2.3 Isi atribut request `coreerp.tenant_id` dari klien. Jangan pernah dari URL, query, atau body.
  - [ ] 4.2.4 Daftarkan alias di `apps/core/bootstrap/app.php`, beserta rate limiter tersendiri.
- [ ] 4.3 Gerbang environment: kalau `ActiveEnvironment::outboundAllowed()` false, semua rute klien integrasi menjawab 503 dengan alasan dari `refusalReason()`.
- [ ] 4.4 Layar Core: terbitkan token (tampil sekali), cabut, ubah scope dan IP, lihat `last_used_at`.
- [ ] 4.5 Test.
  - [ ] 4.5.1 Token salah, dicabut, atau dari IP asing ditolak.
  - [ ] 4.5.2 Scope kurang menghasilkan 403.
  - [ ] 4.5.3 Environment sandbox menghasilkan 503.
  - [ ] 4.5.4 Tenant tidak bisa ditimpa lewat header.

---

### 5. [ ] Core: setelan posting per entitas legal

**Tempat:** `apps/core` · **Setelah:** — · **Selesai bila:** feed bisa diaktifkan per entitas
legal dengan tanggal cutover, dan mode penyelesaian perolehan terbaca per tanggal (K-10, K-16).

- [ ] 5.1 Migration.
  - [ ] 5.1.1 `finance_posting_settings`: `legal_entity_id` (unik), `tenant_id`, `enabled`, `cutover_date`.
  - [ ] 5.1.2 `finance_settlement_modes`: `legal_entity_id`, `mode` (`direct_payable` / `clearing`), `effective_from`. Unik (`legal_entity_id`, `effective_from`).
- [ ] 5.2 Layar Core di halaman entitas legal: aktif/tidak, tanggal cutover, dan riwayat mode beserta tanggal berlakunya.
- [ ] 5.3 Kontrak module: `modePenyelesaian(legalEntityId, tanggal)` dan `cutover(legalEntityId)`.
- [ ] 5.4 Test.
  - [ ] 5.4.1 Mode terbaca sesuai tanggal berlaku.
  - [ ] 5.4.2 Entitas tanpa baris mode menghasilkan default `direct_payable`.
  - [ ] 5.4.3 Tanggal berlaku tidak boleh ganda.

---

### 6. [ ] Core: feed posting

**Tempat:** `apps/core` · **Setelah:** 1, 3, 4, 5 · **Selesai bila:** module bisa menerbitkan
posting dalam transaksinya sendiri, pembaca bisa menarik dan ack sesuai kontrak di PRD, dan
tidak ada posting yang hilang atau dobel.

- [ ] 6.1 Migration `finance_postings`.
  - [ ] 6.1.1 Kolom: `id`, `tenant_id`, `legal_entity_id`, `posting_id` (unik per tenant), `posting_type`, `source_module`, `source_type`, `source_number`, `posting_date`, `settlement_mode` (nullable), `status`, `held_reason`, `payload` (json, bentuk kontrak lengkap), `total_debit`, `total_credit`, `reverses_posting_id`, `adjusts_posting_id`, `external_reference`, `reason_code`, `reason`, `acknowledged_at`, `served_count`, `last_served_at`, timestamps.
  - [ ] 6.1.2 Indeks (`tenant_id`, `status`, `posting_date`) untuk penarikan.
- [ ] 6.2 Kontrak `PenerbitPosting` di `apps/core/app/Support/Modules/Contracts/`.
  - [ ] 6.2.1 `terbitkan(array $posting)` menerima jenis, entitas legal, tanggal, dokumen sumber, vendor, dan baris jurnal dengan `account_id` referensi + `org_unit_id` sumber dimensi.
  - [ ] 6.2.2 Dipanggil **di dalam** transaksi pemanggil. Tidak membuka transaksi sendiri.
  - [ ] 6.2.3 Idempoten: `posting_id` yang sama mengembalikan posting yang sudah ada.
  - [ ] 6.2.4 Bind di `CoreServices.php`, dan buat pembungkus di sisi module mengikuti pola `KalenderFiskalAset`.
- [ ] 6.3 Validasi dan pembentukan payload.
  - [ ] 6.3.1 Seimbang, dan setiap baris hanya debit atau hanya kredit.
  - [ ] 6.3.2 Akun ada dan aktif. Snapshot `external_id`, `code`, `name` ke payload.
  - [ ] 6.3.3 Dimensi: akun neraca → `BUSINESS_UNIT`, akun laba rugi → `BUSINESS_UNIT` + `DEPARTMENT`, dari org unit lewat area 1.4. Snapshot nomor dan nama.
  - [ ] 6.3.4 Vendor wajib untuk `direct_payable` + pembelian.
  - [ ] 6.3.5 Tanggal sebelum cutover → `manual`. Entitas legal dengan feed tidak aktif → `manual`.
  - [ ] 6.3.6 Gagal di 6.3.2 atau 6.3.3 → `held` dengan alasan. Gagal di 6.3.1 → exception, karena itu bug penerbit.
  - [ ] 6.3.7 Nilai uang disimpan dan dikirim sebagai string desimal dua angka.
- [ ] 6.4 `GET /internal/v1/finance-postings`.
  - [ ] 6.4.1 Hanya `pending`, urut `posting_date` lalu waktu terbit, `limit` maksimal 500.
  - [ ] 6.4.2 Naikkan `served_count` dan `last_served_at`, dan catat waktu tarikan terakhir per klien.
  - [ ] 6.4.3 Posting disajikan ulang sampai di-ack.
- [ ] 6.5 `POST /internal/v1/finance-postings/{posting_id}/ack`.
  - [ ] 6.5.1 `posted` wajib `external_reference`. `rejected` wajib `reason_code` dari daftar di PRD, plus `reason`.
  - [ ] 6.5.2 Ack sama yang diulang → 200 tanpa perubahan. Ack yang bertentangan dengan status akhir → 409.
  - [ ] 6.5.3 `posting_id` tidak dikenal atau milik tenant lain → 404.
- [ ] 6.6 Validasi ulang posting `held` setelah pemetaan diperbaiki. Payload dibentuk ulang, dan `posting_id` tetap.
- [ ] 6.7 Kontrak.
  - [ ] 6.7.1 Tulis ketiga rute di `apps/core/contracts/openapi-internal.yaml`, dengan skema `FinancePosting`, `JournalLine`, `FinancialDimension`, dan `Ack`.
  - [ ] 6.7.2 Pastikan `apps/core/contracts/check-contract-coverage.py` lulus. Rute ditulis sebagai path literal berkutip tunggal.
- [ ] 6.8 Test.
  - [ ] 6.8.1 Penerbitan di dalam transaksi yang di-rollback tidak meninggalkan posting.
  - [ ] 6.8.2 Posting yang terbit selama penarikan berlangsung tetap tersaji di tarikan berikutnya.
  - [ ] 6.8.3 Ack ganda aman. Ack bertentangan menghasilkan 409.
  - [ ] 6.8.4 `held` → perbaiki pemetaan → validasi ulang → `pending`.
  - [ ] 6.8.5 Dimensi sesuai jenis akun.
  - [ ] 6.8.6 Tenant terisolasi.

---

### 7. [ ] Core: layar pantau posting

**Tempat:** `apps/core` · **Setelah:** 6 · **Selesai bila:** pengguna finance di sisi CoreERP bisa
melihat, menelusuri, dan menindaklanjuti setiap posting tanpa membuka database.

- [ ] 7.1 Daftar posting: filter status, jenis, entitas legal, dan rentang tanggal. Kolom umur `pending`.
- [ ] 7.2 Detail: dokumen sumber (tautan ke modul), baris jurnal beserta dimensi, alasan tahan/tolak, dan riwayat tarik/ack.
- [ ] 7.3 Aksi.
  - [ ] 7.3.1 "Validasi ulang" untuk `held`.
  - [ ] 7.3.2 "Tandai manual" dengan alasan wajib, untuk `held`, `pending`, dan `rejected`.
  - [ ] 7.3.3 Tidak ada aksi ubah tanggal atau ubah nilai (K-17).
- [ ] 7.4 Permission, privilege, dan duty: lihat, dan tindak lanjut.
- [ ] 7.5 Test: aksi tercatat dengan pelaku dan alasan, dan posting `posted` tidak bisa ditandai manual.

---

### 8. [ ] Modul aset: setup posting

**Tempat:** `modules/apperp/management-aset` · **Setelah:** 1, 3 · **Selesai bila:** setiap group
aset bisa dipetakan ke akun referensi, buku menentukan boleh di-post atau tidak lewat satu saklar,
dan dimensi lokasi mewarisi dari induk.

- [ ] 8.1 Posting group aset, menggantikan placeholder `ui/pengaturan-aset-tetap/PengaturanAsetTetapPlaceholderPage.tsx`.
  - [ ] 8.1.1 Tabel `aset_m_posting_group`: `group_aset_id`, `effective_from`, dan tujuh kolom akun (ID daftar akun referensi): harga perolehan, akumulasi, beban penyusutan, lawan hutang, perantara, PPN Masukan, penyeimbang saldo awal.
  - [ ] 8.1.2 Unik (`tenant_id`, `group_aset_id`, `effective_from`).
  - [ ] 8.1.3 Controller + rute, dengan akun dipilih lewat kontrak `DaftarAkun`.
  - [ ] 8.1.4 UI tabel matriks gaya FA Posting Groups BC: baris group, kolom akun, dan riwayat tanggal berlaku.
  - [ ] 8.1.5 Tanda di group yang belum dipetakan.
- [ ] 8.2 Cara perolehan (K-12).
  - [ ] 8.2.1 Konstanta `pembelian`, `hibah`, `saldo_awal`.
  - [ ] 8.2.2 Untuk sekarang, semua cara memakai kolom akun yang sama. Tulis titik perluasannya di kode supaya akun per cara bisa ditambah nanti.
- [ ] 8.3 Pewarisan dimensi lokasi (K-08).
  - [ ] 8.3.1 Satu fungsi bersama: naik lewat `parent_id` sampai menemukan `org_unit_id`.
  - [ ] 8.3.2 Pakai di `PembuatAset::dimensiLokasi` dan `MutasiAsetController::dimensiLokasi`.
  - [ ] 8.3.3 Jaga dari siklus parent (batas kedalaman).
- [ ] 8.4 Lebur `export_to_backoffice` ke `posting_layer` (K-15).
  - [ ] 8.4.1 Migration data: buku dengan `export_to_backoffice = false` dan layer bukan `none` → tinjau satu per satu. Buku pajak → `none`.
  - [ ] 8.4.2 Hapus saklar dari UI (`ui/master/masters.ts`) dan dari `BukuPenyusutanController`.
  - [ ] 8.4.3 Kolom lama dibiarkan sampai rilis berikutnya (aturan N-1), lalu dihapus.
- [ ] 8.5 `app.yaml`: entry point, permission, privilege, dan duty posting group. Hapus entry point placeholder lama atau alihkan ke halaman baru. Kode kontrak lama jangan diganti nama.
- [ ] 8.6 Test.
  - [ ] 8.6.1 Pemetaan bertanggal berlaku terbaca sesuai tanggal posting.
  - [ ] 8.6.2 Lokasi ruang tanpa pemetaan mewarisi dari lantai.
  - [ ] 8.6.3 Buku `none` tidak pernah menghasilkan posting.

---

### 9. [ ] Modul aset: penerimaan dan posting perolehan

**Tempat:** `modules/apperp/management-aset` · **Setelah:** 2, 6, 8 · **Selesai bila:**
menyelesaikan penerimaan menerbitkan `asset.acquisition` dalam transaksi yang sama, dengan vendor
dan PPN, untuk kedua mode.

- [ ] 9.1 Migration penerimaan.
  - [ ] 9.1.1 Header: `vendor_id` (nullable), `cara_perolehan` (default `pembelian`), `vendor_invoice_reference` (nullable).
  - [ ] 9.1.2 Baris: `ppn_per_unit` decimal(18,2) default 0.
- [ ] 9.2 Validasi saat selesai.
  - [ ] 9.2.1 Vendor wajib kalau mode pada tanggal penerimaan adalah `direct_payable` dan caranya `pembelian`.
  - [ ] 9.2.2 PPN tidak boleh negatif.
- [ ] 9.3 UI `ui/transactions/inventarisasi-aset/PenerimaanDetailPage.tsx`: pilih vendor (dari `DaftarVendor`), cara perolehan, referensi faktur, dan PPN per baris.
- [ ] 9.4 Penerbitan di `PenerimaanAsetController::selesaikan`.
  - [ ] 9.4.1 Satu posting per penerimaan, dengan `posting_id` deterministik dari ID penerimaan.
  - [ ] 9.4.2 Baris: Dr harga perolehan per group + BU, Dr PPN Masukan per BU, Cr hutang atau perantara sesuai mode.
  - [ ] 9.4.3 `posting_date` = `tanggal` penerimaan, **bukan** tanggal siap pakai.
  - [ ] 9.4.4 `details.assets` berisi kode aset, group, buku, nilai, dan PPN.
  - [ ] 9.4.5 Hanya buku yang boleh di-post (bukan `none`) yang menghasilkan baris.
- [ ] 9.5 Test.
  - [ ] 9.5.1 Mode `direct_payable`: jurnal seperti di PRD, seimbang, dengan vendor.
  - [ ] 9.5.2 Mode `clearing`: kredit ke perantara, vendor boleh kosong.
  - [ ] 9.5.3 Group belum dipetakan → penerimaan tetap selesai, posting `held`.
  - [ ] 9.5.4 Gagal di tengah transaksi → tidak ada aset dan tidak ada posting.
  - [ ] 9.5.5 Selesai diulang → tetap satu posting.

---

### 10. [ ] Modul aset: saldo awal

**Tempat:** `modules/apperp/management-aset` · **Setelah:** 8, 9 · **Selesai bila:** aset lama
bisa dimasukkan dengan akumulasi penyusutan sampai cutover, penyusutan berikutnya melanjutkan dari
situ, dan `asset.opening_balance` terbit.

- [ ] 10.1 Desain: penerimaan dengan `cara_perolehan = saldo_awal`.
  - [ ] 10.1.1 Baris membawa `akumulasi_per_unit` dan `periode_berjalan` (jumlah periode yang sudah disusutkan).
  - [ ] 10.1.2 Tanggal penerimaan harus sama dengan, atau sebelum, cutover entitas legalnya.
  - [ ] 10.1.3 Vendor dan PPN tidak berlaku.
- [ ] 10.2 Migration kolom baris dan kolom `elapsed_periods_offset` pada `aset_tr_buku_aset`.
- [ ] 10.3 `PembuatAset::buatBuku` menerima akumulasi dan offset. Nilai buku = perolehan − akumulasi.
- [ ] 10.4 `DepreciationCalculator` menambahkan offset ke hitungan periode berjalan.
- [ ] 10.5 Posting `asset.opening_balance`: Dr harga perolehan, Cr akumulasi, Cr penyeimbang, per group + BU.
- [ ] 10.6 (Opsional) impor CSV untuk saldo awal massal, yang membuat penerimaan jenis saldo awal.
- [ ] 10.7 Test.
  - [ ] 10.7.1 Penyusutan periode pertama setelah cutover sama dengan penyusutan periode ke-(offset+1).
  - [ ] 10.7.2 Jurnal saldo awal seimbang, dengan nilai buku di akun penyeimbang.
  - [ ] 10.7.3 Tanggal setelah cutover ditolak.

---

### 11. [ ] Modul aset: posting penyusutan

**Tempat:** `modules/apperp/management-aset` · **Setelah:** 6, 8 · **Selesai bila:** satu proses
"Post penyusutan" menghasilkan satu posting ringkas yang totalnya sama persis dengan register,
reversal menghasilkan jurnal balik, dan ekspor lama berhenti ditulis.

- [ ] 11.1 Penanda di periode.
  - [ ] 11.1.1 Migration: `posted_posting_id` (nullable) pada `aset_tr_penyusutan_aset`.
  - [ ] 11.1.2 Periode final tanpa `posted_posting_id` = belum di-post.
- [ ] 11.2 Proses "Post penyusutan".
  - [ ] 11.2.1 Input: entitas legal, buku, `period_ends_on`.
  - [ ] 11.2.2 Kumpulkan periode original `final` yang belum di-post. Kunci baris dengan `lockForUpdate`.
  - [ ] 11.2.3 Bulatkan per aset, lalu ringkas per (akun beban, BU, department) dan (akun akumulasi, BU).
  - [ ] 11.2.4 Satu posting. `posting_id` deterministik dari entitas + buku + tanggal akhir + nomor urut proses.
  - [ ] 11.2.5 Isi `posted_posting_id` pada semua periode yang ikut.
  - [ ] 11.2.6 Buku `none` ditolak dengan pesan jelas.
  - [ ] 11.2.7 Rute, permission, dan UI tombol di layar penyusutan, dengan ringkasan sebelum konfirmasi.
- [ ] 11.3 Reversal (`DepreciationController::reverse`).
  - [ ] 11.3.1 Kalau periode asal sudah di-post → terbitkan `asset.depreciation_reversal` yang merujuk posting asal, dengan baris balik untuk porsi aset itu saja.
  - [ ] 11.3.2 Kalau periode asal belum di-post → tidak ada posting.
  - [ ] 11.3.3 Hormati `posting_layer`, sehingga asimetri yang ada sekarang hilang.
- [ ] 11.4 Hentikan penulisan `aset_tr_export_penyusutan` di `finalize` dan `reverse`. Tabel dan riwayatnya dibiarkan dan tetap bisa dibaca. Penghapusannya diputuskan terpisah.
- [ ] 11.5 Test.
  - [ ] 11.5.1 Total posting sama dengan jumlah periode di register, sampai ke sen.
  - [ ] 11.5.2 Dua proses paralel untuk buku dan periode yang sama → satu berhasil, satu kosong.
  - [ ] 11.5.3 Reversal setelah post menghasilkan jurnal balik yang merujuk posting asal.
  - [ ] 11.5.4 Reversal sebelum post tidak menghasilkan posting.
  - [ ] 11.5.5 `DepreciationScaleTest` tetap dalam anggarannya.

---

### 12. [ ] Modul aset: koreksi nilai perolehan

**Tempat:** `modules/apperp/management-aset` · **Setelah:** 9 · **Selesai bila:** koreksi nilai
sebelum ada penyusutan menerbitkan `asset.acquisition_adjustment` dengan mode yang diwarisi dari
posting perolehan.

- [ ] 12.1 `AsetController::applyValueChange` menerbitkan posting selisih, dengan `adjusts_posting_id` = posting perolehan aset itu.
- [ ] 12.2 Mode diambil dari posting asal, bukan dari setelan hari ini.
- [ ] 12.3 Selisih negatif menghasilkan jurnal arah sebaliknya.
- [ ] 12.4 Test.
  - [ ] 12.4.1 500 → 510 menghasilkan Dr aset 10 / Cr hutang 10.
  - [ ] 12.4.2 Mode diganti setelah perolehan → koreksi tetap mengikuti mode asal.
  - [ ] 12.4.3 Aset yang sudah disusutkan tetap ditolak (409), seperti sekarang.

---

### 13. [ ] Kontrak dan dokumentasi

**Tempat:** `apps/core/contracts`, `docs/` · **Setelah:** 6, dan diperbarui di setiap area modul ·
**Selesai bila:** pembaca baru bisa membangun konsumen hanya dari dokumen, dan `npx vitepress build
docs` bersih.

- [ ] 13.1 Contoh payload lengkap untuk kelima jenis posting, untuk kedua mode bila berbeda.
- [ ] 13.2 Halaman `docs/dev` untuk feed posting: penerbit, status, validasi, penahanan, dan panduan menambah jenis posting dari modul lain.
- [ ] 13.3 Halaman `docs/apps/management-aset`: posting group, cara perolehan, saldo awal, dan "Post penyusutan".
- [ ] 13.4 Panduan untuk pembaca: urutan sinkron (akun → vendor → unit → tarik posting → ack), tabel penerjemah, dan larangan fallback.
- [ ] 13.5 Daftarkan halaman di `docs/.vitepress/config.ts`, lalu build bersih.
- [ ] 13.6 Tandai bagian "Kontrak ke backoffice" di `docs/todo/managementaset/03-penyusutan-dan-bridge-backoffice.md` sebagai digantikan.

---

### 14. [ ] Control-plane: pemantauan feed

**Tempat:** `apps/control-plane`, `deploy/agent` · **Setelah:** 6 · **Selesai bila:** halaman
site di admin.erp menampilkan kesehatan feed tiap server klien.

- [ ] 14.1 Endpoint ringkasan di Core: jumlah per status, umur `pending` tertua, dan tarikan terakhir. Yang dibaca agent hanya angka ini, tanpa isi jurnal.
- [ ] 14.2 `deploy/agent/coreerp-agent` membaca ringkasan dan menambahkan `finance_feed` ke laporan, beserta test agent.
- [ ] 14.3 `apps/control-plane/contracts/openapi-agent.yaml` (skema `Report`) dan `SiteReports::TOP_LEVEL`.
- [ ] 14.4 Tampilan di `apps/control-plane/resources/js/pages/sites/show.tsx`, dengan tanda merah kalau ada `rejected`/`held`, atau `pending` lebih tua dari ambang.
- [ ] 14.5 Test di kedua sisi.

---

### 15. [ ] Old-finance (dependensi, dikerjakan tim old-finance)

**Tempat:** repo old-finance · **Setelah:** 6 (kontrak stabil) · **Selesai bila:** skenario area
16 lulus. Kontrak CoreERP yang menjadi acuan (K-01).

- [ ] 15.1 Tabel penerjemah.
  - [ ] 15.1.1 Entitas legal (`legal_entity.code`) → buku/database Back Office.
  - [ ] 15.1.2 Nomor BU → `UnitBisnisID`.
  - [ ] 15.1.3 Nomor department → `SectionID`.
  - [ ] 15.1.4 Vendor Core → `Supplier_ID`.
- [ ] 15.2 Ekspor daftar akun ke CSV sesuai templat area 3.2.1, dengan `external_id` = `Akun_ID`.
- [ ] 15.3 Sinkron vendor dan unit dari Core (`GET /vendors`, `GET /operating-units`).
- [ ] 15.4 Job terjadwal penarik posting.
  - [ ] 15.4.1 Tarik `pending`, dan simpan dengan `UNIQUE(posting_id)`.
  - [ ] 15.4.2 Jurnal diambil dari `journal_lines`. Jangan menjurnal dari `details`.
  - [ ] 15.4.3 Tanggal diambil dari `posting_date`, bukan jam server Windows.
- [ ] 15.5 Posting `direct_payable` jenis perolehan → faktur di modul hutang **tanpa jurnal kedua**. Nomor faktur vendor ditempel belakangan.
- [ ] 15.6 Ack: `posted` + nomor voucher/faktur, atau `rejected` + kode alasan.
- [ ] 15.7 Tanpa fallback: kode akun, vendor, unit, atau entitas yang tidak dikenal → `rejected`, bukan default.

---

### 16. [ ] Uji terima end-to-end

**Tempat:** server dev CoreERP + instance dev old-finance · **Setelah:** 7, 9, 10, 11, 12, 15 ·
**Selesai bila:** semua skenario lulus di sistem sungguhan, bukan lewat tiruan HTTP, dan hasilnya
dicatat di PRD.

- [ ] 16.1 Perolehan satu ambulans + PPN → satu faktur di hutang old-finance, hutang 555, tanpa jurnal kedua.
- [ ] 16.2 Post penyusutan satu bulan → jurnal ringkas per klinik/poli, dan total sama dengan register.
- [ ] 16.3 Reversal satu periode → jurnal balik yang merujuk posting asal.
- [ ] 16.4 Posting ke periode tertutup → `rejected` dengan `PERIOD_CLOSED`, dan tampil di layar pantau.
- [ ] 16.5 Saldo awal satu aset lama → terbukukan, dan penyusutan berikutnya melanjutkan dari offset.
- [ ] 16.6 Ganti nama dan nomor satu akun di old-finance → impor ulang → posting berikutnya memakai nomor baru.
- [ ] 16.7 Salinan sandbox environment → endpoint menjawab 503, dan tidak ada posting tersaji.
- [ ] 16.8 Mode diganti `direct_payable` → `clearing` → koreksi atas penerimaan lama tetap ke Hutang.
- [ ] 16.9 Old-finance mati satu jam → setelah hidup lagi, semua posting tersaji tanpa ada yang hilang.
- [ ] 16.10 Halaman site di admin.erp menampilkan angka feed yang sama dengan layar pantau Core.
