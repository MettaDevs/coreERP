# Laporan Komprehensif: Analisis Progres TODO No. 15 (Feed Posting Finance)
**Penyelarasan Integrasi CoreERP & Back Office (BO Penta)**  
*Dokumen Analisis, Kendala, Solusi, Status Terkini, dan Rekomendasi*

**Snapshot historis 30 September 2026:** Area 15 tetap terbuka sampai seluruh area 16 lulus.
Skenario 16.2–16.6, 16.9, 16.11, dan 16.12 kini tercatat lulus; 16.1, 16.7, 16.8, 16.10,
dan 16.13 masih terbuka. Uji pemulihan 16.9 sudah lulus: PNRA00007 dan PNRA00008 masing-masing
memiliki satu jurnal, faktur, dan kartu hutang yang seimbang serta ack HTTP 200 setelah worker pulih.
Aturan 15.7 kini membedakan ID yang belum ada pada snapshot direktori atau belum memiliki mapping
BO (menunggu di inbox, tanpa ack) dari akun yang tidak dikenal/tidak aktif, vendor/unit yang
dipastikan tidak aktif atau tidak cocok lewat sync setelah posting terbit, dan legal entity yang
tidak cocok (ditolak dengan alasan).
Kolom checkpoint full sync sudah diterapkan pada database dev BO metta-demo-bo-penta. Full sync
vendor dan operating unit untuk PTD1 berhasil pada 30 September 2026 pukul 22.45 WITA, tanpa error.
Uji negatif E2E masih perlu dilakukan, jadi subitem 15.7 belum ditutup. Ringkasan lama di bawah
adalah snapshot historis dan tidak mencerminkan status terbaru tersebut.

**Retest setelah pull CoreERP `main`, 2 Oktober 2026:** TODO 16.2 lulus ulang menggunakan
`ASTA00000/KOMERSIAL` untuk Oktober; jurnal `2026/10/JUM/0004` di BO seimbang Rp208.333,33 dan
tidak menghasilkan faktur hutang. TODO 16.3 juga lulus ulang: pembalikan dengan `AST-DRV-01M3XQZ69ES0Y9ZE66KSNP3CQW` dibukukan sebagai `2026/10/JUM/0005`, tanpa faktur hutang, dan nilai buku kembali ke Rp9.791.666,67. TODO 16.8 sudah terbukti pada sisi CoreERP: koreksi ASTA00022
memakai hutang pemasok sesuai mode saat perolehan, tetapi BO menolak `asset.acquisition_adjustment`
sebagai `INVALID` karena jenis posting belum didukung. TODO 16.8 tetap terbuka; retest 16.3 sudah
selesai memakai posting Oktober ASTA00000 yang sama.

**Pembaruan progres 15.7, 2 Oktober 2026:** N5 unit lulus melalui PNRA00018 dan N6 melalui
PNRA00019. Posting menunggu saat snapshot belum cukup baru; setelah sync membuktikan BU tidak aktif
atau tetap tidak ditemukan setelah full sync, BO mengirim ack `UNKNOWN_DIMENSION` HTTP 200 tanpa
AP/GL. N1, N2 department, N3, N4, N5 vendor, dan N9 juga sudah memiliki bukti. N2 BU masih perlu
bukti khusus; N7 dan N8 belum diuji sehingga TODO 15.7 tetap terbuka.

---

## 1. Ringkasan Eksekutif & Jawaban Utama

### Mengapa TODO No. 15 di `coreERP/.../TODO.md` Masih Berstatus Kosong `[ ]`?
Banyak kebingungan muncul karena di file [TODO.md](TODO.md) item nomor 15 masih berupa kotak kosong `[ ]`:
```markdown
### 15. [ ] Old-finance (dependensi, dikerjakan tim old-finance)
Selesai bila: skenario area 16 lulus. Kontrak CoreERP yang menjadi acuan (K-01).
```
Namun, catatan di repo BO `bo_metta_anggaran_new` menunjukkan modul integrasi sudah dibangun, worker berjalan, dan sejumlah transaksi uji masuk ke GL serta modul Hutang (AP). Arsip dan catatan tersebut berada di `docs/integrasi-bo-coreerp/arsip/COREERP_FINANCE_FEED_IMPLEMENTATION_PLAN.md`, `docs/integrasi-bo-coreerp/E2E_PROGRESS.md`, `docs/integrasi-bo-coreerp/SETUP.md`, dan `docs/integrasi-bo-coreerp/FLOW_UI.md` pada repo BO. Catatan BO dirapikan pada 3 Oktober 2026; kelanjutan status dan batas bukti dibaca dari register E2E tersebut.

**Penyebab Perbedaan Status:**
1. **Kriteria Selesai yang Ketat pada `TODO.md`:**  
   Di sisi CoreERP, aturan penutupan item 15 berbunyi: *"Selesai bila: skenario area 16 lulus"*. Area 16 adalah 13 skenario UAT End-to-End (E2E) lintas sistem di lingkungan nyata. Karena seluruh skenario 16 belum ditutup secara formal, checklist nomor 15 di `TODO.md` belum dicentang `[x]`.
2. **Kondisi Pekerjaan di Sisi BO (Old-Finance):**  
   Di sisi BO, **fondasi teknis 15.1 s.d. 15.7 sebenarnya sudah selesai dibangun dan berfungsi**. Tiga skenario transaksi utama bahkan sudah berhasil diuji dan diverifikasi:
   - **Akuisisi Aset + PPN (`direct_payable`)**: Sukses membentuk faktur AP FAP/015 Rp 11.000.000, 1 kartu hutang, dan 1 GL seimbang tanpa duplikasi.
   - **Penyusutan GL-only (16.2)**: Sukses menjurnal beban & akumulasi penyusutan Rp 208.333,33 ke GL `2026/09/JUM/0002` dan mengirim ack HTTP 200.
   - **Reversal Penyusutan (16.3)**: Sukses membukukan jurnal balik `2026/09/JUM/0003` dan memulihkan saldo buku aset di CoreERP.
3. **Mengapa Belum Ditutup Penuh?**  
   Pengujian yang dilakukan baru menggunakan *fixture data pengujian dev*, belum mencakup seluruh skenario ekstrem (seperti periode pembukuan tutup, penolakan fallback akun tak dikenal secara E2E, desimal harga satuan, dll.), serta dependensi CoreERP tertentu (seperti Area 12 untuk koreksi nilai perolehan) masih berjalan.

---

## 2. Apa yang Terjadi (What's Going On)

### 2.1 Arsitektur & Peran Masing-Masing Sistem
* **CoreERP (Laravel + PostgreSQL):**  
  Aplikasi operasional aset. Saat terjadi penerimaan aset, penyusutan bulanan, atau reversal, CoreERP menerbitkan baris-baris jurnal ke feed internal (`GET /api/internal/v1/finance-postings`). Jurnal ini berstatus `pending` sampai aplikasi finance membukukannya dan mengirim tanda terima (`ack`).
* **BO Penta / Old-Finance (CodeIgniter 3 HMVC + SQL Server):**  
  Aplikasi akuntansi & back office utama rumah sakit/klinik. Bertindak sebagai *puller/konsumen*. BO menarik data pending secara berkala, menerjemahkan kode CoreERP ke master BO (Akun, BU, Section, Vendor), membukukan ke Buku Besar (GL) dan Hutang (AP), lalu mengirim `POST .../ack` agar CoreERP tahu posting telah selesai atau ditolak.

### 2.2 Rincian Butir Pekerjaan No. 15
Item 15 diatur dalam 7 sub-bagian utama:
* **15.1 Tabel Penerjemah (Mapping):** Menerjemahkan Entitas Legal (`legal_entity.code`), Business Unit (`BU`), Department (`SectionID`), dan Vendor CoreERP (`Supplier_ID`).
* **15.2 Ekspor Daftar Akun ke CSV:** BO mengekspor COA dalam format `external_id,code,name,type,active` agar CoreERP dapat mengimpor nomor akun BO.
* **15.3 Sinkronisasi Vendor & Operating Unit:** BO menarik master vendor dan unit organisasi CoreERP via API agar data pemetaan selalu terbaru.
* **15.4 Job Terjadwal Pull Posting:** Worker otomatis terjadwal yang membaca feed pending secara aman, idempoten (`UNIQUE(posting_id)`), menjaga presisi angka dan tanggal asli.
* **15.5 Posting `direct_payable`:** Untuk pembelian aset langsung ke hutang vendor, membuat 1 faktur AP dan 1 kartu hutang **tanpa membuat jurnal GL ganda**.
* **15.6 Pengiriman Ack:** Mengirim sinyal `posted` (lengkap dengan nomor bukti BO) atau `rejected` (dengan kode alasan penolakan).
* **15.7 Kebijakan Tanpa Fallback (No Silent Fallback):** Data yang tidak dikenal atau tidak aktif wajib langsung di-`rejected`, dilarang keras menebak akun/unit default secara diam-diam.

---

## 3. Kendala yang Dihadapi (Obstacles) & Cara Penyelesaiannya (How It Resolved)

Pembangunan integrasi ini menghadapi beberapa tantangan teknis kompleks pada arsitektur legacy BO dan database SQL Server. Berikut rangkuman kendala dan cara mengatasinya:

### Kendala 1: Perbedaan Dua Instance Database SQL Server
* **Masalah:** Konfigurasi lama MCP menunjuk ke host `172.13.0.8`, sedangkan runtime CLI lokal menunjuk ke `LAPTOP-PJ6K0I1A\(local):1433`. Nama databasenya identik (`metta-demo-bo-penta`), tetapi datanya berbeda.
* **Solusi:** Seluruh migrasi skema DDL, mapping, dan eksekusi task dijadwalkan dan diverifikasi tegas pada instance lokal yang menjadi target runtime aktif.

### Kendala 2: Model Lama Merujuk Tabel Registry yang Tidak Ada
* **Masalah:** Modul lama `api_finance` merujuk ke tabel `dbo.API_Finance_Document_Map`, namun tabel tersebut tidak pernah ada di database fisik BO.
* **Solusi:** Dibuat 6 tabel baru khusus integrasi CoreERP dengan skema rapi dan indeks unik:
  1. `dbo.API_CoreERP_Finance_Posting` (Inbox deduplikasi berbasis `posting_id`)
  2. `dbo.API_CoreERP_Feed_Setting` (Konfigurasi per legal entity, timeout, AP type, user integrasi)
  3. `dbo.API_CoreERP_OperatingUnit_Map` (Pemetaan BU dan Department ke BO)
  4. `dbo.API_CoreERP_OperatingUnit_Alias` (Penyimpan riwayat nomor unit agar posting lama tidak gagal)
  5. `dbo.API_CoreERP_Vendor_Map` (Pemetaan vendor CoreERP ke Supplier BO)
  6. `dbo.API_CoreERP_Sync_State` (Penyimpan checkpoint timestamp incremental sync)

### Kendala 3: Ancaman Jurnal Ganda pada Modul Hutang (`direct_payable`)
* **Masalah:** Pada alur akuisisi aset, posting feed harus menghasilkan faktur di modul Hutang (AP) dan jurnal GL. Di BO standar, jika faktur AP disimpan lalu diposting manual oleh kasir, BO akan men-generate jurnal GL kedua, sehingga nilai aset dan hutang tercatat dua kali lipat. Selain itu, kartu hutang (`AP_trKartuHutang`) hanya terbentuk bila trigger mendeteksi `Diakui_Hutang = 1`.
* **Solusi:** Importer feed mengunci kondisi ini dalam satu transaksi atomik:
  - Mengisi `Diakui_Hutang = 1` agar database trigger `insAPtrFaktur` otomatis melahirkan kartu hutang.
  - Mengisi `Posted = 1` agar menu posting reguler AP menganggap faktur sudah diposting dan tidak membuat jurnal GL kedua.
  - Menyamakan nomor bukti jurnal dengan nomor faktur (`No_Bukti = No_Faktur`).
  - Memverifikasi keseimbangan debit/kredit sebelum commit.

### Kendala 4: Baris Neraca CoreERP Tidak Memiliki Dimensi Department
* **Masalah:** Akun neraca (seperti Akumulasi Penyusutan dan Nilai Perolehan) dari CoreERP hanya membawa dimensi Business Unit (`BU`), tanpa Department. Di database BO, kolom `TBJ_Transaksi_Detail.SectionID` berstatus `NOT NULL`. Default skema `SEC000` tidak ada di data master.
* **Solusi:**
  1. View `dbo.SIMmSection` diselaraskan agar membaca tabel master FO Penta asli.
  2. Dibuat master section teknis resmi ber-ID `SEC097` dengan nama `"TANPA DEPARTMENT - FINANCE FEED"` di bawah Unit Bisnis 2.
  3. Seluruh baris neraca otomatis dialokasikan ke `SEC097` secara formal dan transparan, tanpa fallback liar.

### Kendala 5: View Supplier Kosong di Lingkungan Dev Lokal
* **Masalah:** View `dbo.mSupplier` membaca database FO yang belum memiliki data vendor di lokal. Akibatnya mapping vendor gagal.
* **Solusi:** Diterapkan Draf SQL 007 yang membuat tabel dukungan minimal dengan vendor fixture `SUPP0001` (`Supplier_ID = 974`, nama `"Test New Vendor"`).

### Kendala 6: Kolom Staging Vendor Awalnya `NOT NULL`
* **Masalah:** Pada tabel `API_CoreERP_Vendor_Map`, kolom `Supplier_ID` awalnya `NOT NULL`. Ketika worker melakukan sinkronisasi vendor baru dari CoreERP yang belum dipetakan ke BO, query insert gagal karena belum ada `Supplier_ID`.
* **Solusi:** Diterapkan DDL Draf 012 (`ALTER TABLE dbo.API_CoreERP_Vendor_Map ALTER COLUMN Supplier_ID SMALLINT NULL`) sehingga vendor CoreERP baru dapat distage terlebih dahulu dalam status unmapped, lalu dipetakan via Web Setup BO.

### Kendala 7: Manajemen Kredensial Token & Penanganan Error 401
* **Masalah:** Token API CoreERP tidak boleh ditulis langsung di source code atau file konfigurasi repo (risiko kebocoran rahasia). Selain itu, jika token kedaluwarsa (HTTP 401), worker otomatis tidak boleh terus menerus melakukan retry yang membebani server (infinite retry loop).
* **Solusi:**
  1. Token disimpan terenkripsi di **Windows Credential Manager** menggunakan skrip PowerShell (`bin/set-finance-feed-secret.ps1`).
  2. Skrip wrapper `bin/run-finance-feed.ps1` menyuntikkan token ke environment runtime PHP hanya saat eksekusi berlangsung, lalu membersihkannya kembali.
  3. Dibuat sistem filter ack: jika terjadi error HTTP 401, worker menghentikan pengulangan otomatis dan menandai ack sebagai deferred. Operator dapat memicu pengiriman ulang secara sadar via CLI:
     ```powershell
     php index.php back_office/finance_feed/feed_job retry_401_ack <posting_id> token-rotated
     ```

### Kendala 8: Feed CoreERP Tanpa Kursor Paging (Ack-Driven)
* **Masalah:** Endpoint `GET /finance-postings` tidak menggunakan nomor halaman (`page=2`) atau kursor, melainkan selalu menyajikan 100 data `pending` terlama. Jika data tidak di-ack, pemanggilan ulang akan mengembalikan data yang sama persis.
* **Solusi:** Worker BO memproses posting dan mengirim ack seketika. CoreERP akan menghapus posting tersebut dari antrean pending setelah menerima ack sukses. Worker BO melanjutkan perulangan (`do ... while has_more`) hanya jika ada setidaknya satu ack yang berhasil terkirim. Jika terjadi error atau antrean macet, worker berhenti secara tertib agar tidak melakukan loop tanpa ujung.

---

## 4. Status Perkembangan Terkini (Current Progress)

### 4.1 Tabel Status Detail Area 15

| Sub-Item | Deskripsi Kebutuhan | Status di BO | Keterangan & Bukti Lapangan |
|---|---|---|---|
| **15.1** | **Tabel Penerjemah** | **Selesai (Siap Pakai)** | Legal entity `PTD1`, mata uang `IDR` → `RP`, BU `PENTA MEDICA/2` → `UnitBisnisID=2`, Department `PUMU` → `SectionID=SEC001`, dan Section teknis `SEC097` aktif dan teruji pada transaksi. Layar pemetaan web tersedia di `/finance_feed/setup`. |
| **15.2** | **Ekspor COA ke CSV** | **Kode Selesai** | Controller `finance_feed/setup/export_coa` telah selesai dibuat dengan format standar `external_id,code,name,type,active`. Kueri telah diverifikasi membaca 270 akun leaf BO aktif. Tinggal diunduh dan diimpor ke CoreERP. |
| **15.3** | **Sinkron Vendor & Unit** | **Selesai & Berjalan** | CLI `feed_job sync` berjalan sukses. Sinkronisasi vendor memakai cursor pagination dan checkpoint timestamp. Operating unit dan department ditarik dan dapat dipetakan langsung via antarmuka web. |
| **15.4** | **Job Terjadwal Pull** | **Selesai & Terdaftar** | Windows Task Scheduler `BO Penta CoreERP Finance Feed Run` berjalan tiap 5 menit, dan `Feed Sync` berjalan harian pukul 03.00 WIB. Idempotensi dijamin oleh constraint `UNIQUE(posting_id)` di tabel inbox. |
| **15.5** | **`direct_payable` (AP)** | **Lulus Fixture** | Transaksi akuisisi kendaraan berhasil diposting menghasilkan faktur AP `2026/09/FAP/015` Rp 11.000.000, 1 kartu hutang, dan 1 GL seimbang tanpa duplikasi GL. |
| **15.6** | **Pengiriman Ack** | **Lulus untuk `posted`** | Ack sukses terkirim dan CoreERP merespons HTTP 200. Logika ack `rejected` sudah dibuat di kode, tinggal diuji skenario penolakan E2E. |
| **15.7** | **Tanpa Fallback** | **Kode Siap** | Validasi kode ketat: akun/vendor/unit tidak dikenal atau tidak aktif langsung memicu penolakan (`rejected`), sistem dilarang menggunakan akun default. |

---

### 4.2 Hubungan dengan Uji Terima End-to-End Area 16

Meskipun fondasi No. 15 sudah rampung, penutupan resmi checklist bergantung pada 13 skenario di Area 16:
* **16.1 (Perolehan Aset + PPN):** **Lulus sebagai adaptasi**. Berhasil diuji menggunakan kendaraan uji Rp 11.000.000. Tinggal menunggu konfirmasi pemilik produk apakah perlu ambulans secara persis atau pengujian kendaraan ini sah sebagai substitusi.
* **16.2 (Penyusutan Bulanan GL-only):** **Lulus**. Aset `ASTA00001` disusutkan untuk periode September 2026 sebesar Rp 208.333,33. Jurnal GL `2026/09/JUM/0002` terbentuk seimbang di BO dan ack berhasil direspons HTTP 200.
* **16.3 (Reversal Penyusutan):** **Lulus**. Pembalikan periode penyusutan berhasil membuat jurnal balik `2026/09/JUM/0003` di BO, nilai buku aset kembali normal, dan ack sukses terkirim.
* **16.4 s.d. 16.13:** **Belum Diuji**. Menunggu kondisi lingkungan tertentu (misalnya 16.4 menunggu penutupan periode resmi di BO, 16.6 pengujian ubah nomor akun, 16.7 uji sandbox 503, dll.).

---

## 5. Saran & Rekomendasi Langkah Selanjutnya (Suggestions)

Berdasarkan analisa komprehensif di atas, berikut langkah-langkah terarah yang disarankan:

### 1. Update Catatan Status di `TODO.md` (CoreERP)
Jangan biarkan Area 15 terlihat seperti belum tersentuh sama sekali. Perbarui catatan di `TODO.md` bahwa:
> *"Pekerjaan kode sisi Back Office (15.1 s.d. 15.7) telah rampung di repository BO. Tiga pengujian fixture utama (Akuisisi direct_payable, Penyusutan GL-only, dan Reversal) telah lulus dengan status Ack HTTP 200. Status 15 tetap terbuka menunggu penyelesaian sisa skenario Area 16."*

### 2. Jalankan Ekspor dan Impor COA Aktual (Poin 15.2)
* Buka browser dan akses menu setup BO: `http://localhost:8001/development/finance_feed/setup`.
* Klik tombol **Ekspor COA** untuk mendapatkan file CSV akun BO terbaru.
* Impor file CSV tersebut ke CoreERP (sesuai panduan modul aset CoreERP) agar seluruh mapping akun aset di CoreERP merujuk ke ID akun riil BO.

### 3. Lakukan Uji Kasus Negatif (Negative Testing untuk 15.6 & 15.7)
* Simulasikan pengiriman transaksi dari CoreERP dengan vendor yang sengaja belum dipetakan atau akun yang dinonaktifkan di BO.
* Pastikan worker BO menangkap kondisi ini, menandai inbox sebagai `rejected`, dan mengirim ack penolakan ke CoreERP dengan `reason_code` yang sesuai tanpa menyebabkan worker crash.

### 4. Strategi Pengujian Periode Tertutup (Poin 16.4)
* Jangan menutup bulan berjalan secara sembarangan hanya untuk tes.
* Manfaatkan data pengujian masa lalu (misal bulan Juli atau Agustus 2026 yang di database dev sudah berstatus tutup buku), lalu terbitkan posting penyusutan bertanggal bulan tersebut dari CoreERP untuk memvalidasi bahwa BO merespons dengan kode penolakan `PERIOD_CLOSED`.

### 5. Tangani Kinerja Health Check Docker `core-app`
* Saat restart container, health check `core-app` membutuhkan waktu hingga 34 detik sehingga berstatus *unhealthy* oleh Docker daemon (batas timeout default 10 detik).
* Periksa query atau dependensi saat bootstrap container agar respons health check kembali instan dan kontainer berstatus *healthy*.

---

*Laporan ini disusun sebagai referensi resmi pemahaman progres teknis integrasi CoreERP dan Back Office.*
