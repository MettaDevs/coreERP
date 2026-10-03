# TODO fase 3 — lanjutan dan paket jual

Butir kerja area 19–23 [engine analitik](/todo/analitik/). Area di fase ini lebih kasar karena
sebagian keputusannya menunggu pengalaman fase 1–2; setiap area memuat keputusan yang harus diambil
sebelum ia dimulai. Aturan pengerjaan di [indeks TODO](/todo/analitik/TODO).

---

### 19. [ ] Pivot, subtotal, dan ekspor lanjutan

**Tempat:** jenis widget `pivot`, `Query/PivotQuery.php`, komponen `pivot-widget.tsx` · **Setelah:** 3,
7 · **Skill:** `coreerp-analytics`, `coreerp-ui` · **Selesai bila:** pivot dengan dimensi baris, satu
dimensi kolom (paling banyak 50 nilai), dan subtotal tergambar dan terekspor ke Excel dengan susunan
yang sama.

- [ ] 19.1 Query pivot dengan `GROUPING SETS`/`ROLLUP`; penanda baris subtotal dari `GROUPING()`.
- [ ] 19.2 Batas nilai kolom dan pesan saat terlampaui ("Terlalu banyak kolom; saring dulu").
- [ ] 19.3 Tampilan dengan kepala tetap saat digulir, total baris dan kolom.
- [ ] 19.4 Ekspor pivot ber-susunan ke Excel lewat antrean ekspor.

---

### 20. [ ] Peringatan ambang dan kirim terjadwal

**Tempat:** tabel peringatan dan langganan, job evaluasi dan pengiriman · **Setelah:** 15; dan
infrastruktur di luar engine: email (hari ini `MAIL_MAILER=log`, nol Mailable), pemberitahuan sisi
server (lonceng Shell hari ini localStorage), serta job dan jadwal per environment
([TODO database sendiri](/todo/produksi-database-sendiri/TODO) 4.1 dan 4.2) · **Skill:**
`coreerp-architecture` · **Selesai bila:** US-12 lulus, hak penerima diperiksa ulang saat dikirim, dan
satu tenant ber-database sendiri menerima peringatannya dari database-nya sendiri.

Keputusan sebelum mulai:

- Saluran: email, pemberitahuan di aplikasi, atau keduanya; dan apakah WhatsApp (permintaan umum di
  pelanggan lama) masuk lewat integrasi luar.
- Isi kiriman terjadwal: tautan ke dasbor (data tetap di CoreERP) atau lampiran Excel/PDF (data keluar).

Butir arah:

- [ ] 20.1 Peringatan: query tersimpan + measure + kondisi (melewati ambang, berubah lebih dari x%),
  jadwal, penerima (pengguna atau role), jeda antarperingatan.
- [ ] 20.2 Evaluasi terjadwal per environment dan tenant, sebagai pemilik peringatan; hak diperiksa
  ulang; galat tidak menghentikan peringatan lain.
- [ ] 20.3 Kirim dasbor terjadwal lewat antrean ekspor dan renderer yang ada.
- [ ] 20.4 Test: penerima yang kehilangan hak tidak menerima; peringatan tidak berulang dalam jeda.

---

### 21. [ ] Ringkasan pra-agregasi dan replika baca

**Tempat:** `app/Platform/Analytics/Rollups/*`, tabel ringkasan, job pembaruan · **Setelah:** 9, 10 ·
**Keputusan:** **KA-16** · **Skill:** `coreerp-analytics`, `coreerp-architecture` · **Selesai bila:**
query yang cocok dengan ringkasan dibaca dari ringkasan dengan angka yang sama persis dengan query
mentah, `data_as_of` tampil, dan uji beban menunjukkan penurunan beban database untuk skenario yang
ditargetkan.

Keputusan sebelum mulai: ringkasan dipicu posting (event module) atau berkala dari `updated_at`; satu
tabel ringkasan generik atau tabel per ringkasan; siapa yang menyatakan ringkasan (module atau admin).
Arah teknisnya di [kinerja](/todo/analitik/kinerja-dan-uji-beban#ringkasan-pra-agregasi-fase-3).

- [ ] 21.1 Definisi ringkasan, penyimpanan, pembaruan bertahap per bucket.
- [ ] 21.2 Penulisan ulang query otomatis dengan aturan kecocokan; kebijakan data tetap berlaku.
- [ ] 21.3 `analytics.read_connection` untuk replika baca opsional, dengan `data_as_of`.
- [ ] 21.4 Test kesamaan angka ringkasan dan mentah, termasuk setelah baris diarsipkan.

---

### 22. [ ] Paket jual dan hak add-on

**Tempat:** mekanisme hak sesuai keputusan, gate privilege analitik, layar keadaan terkunci, katalog
admin.erp, lisensi on-prem · **Setelah:** 15 · **Keputusan:** **KA-06 / PQ-03** · **Skill:**
`coreerp-architecture` (katalog, hak, pemasangan), `coreerp-konsol` (bila menyentuh admin.erp) ·
**Selesai bila:** tenant tanpa add-on tetap melihat dasbor bersama dan template, tidak dapat membuka
pembangun, penjelajah, maupun publikasi, dan pesan di layar menjelaskannya dengan nama paket yang
dibaca dari katalog.

- [ ] 22.1 Mekanisme hak yang diputuskan pemilik; test bahwa hak tidak menyamar sebagai pemasangan.
- [ ] 22.2 Duty `analyze`, `manage`, `publish` hanya dapat disusun ke role bila tenant berhak
  (`TenantProducts`), sama seperti duty module.
- [ ] 22.3 Layar terkunci dengan penjelasan, tanpa nama paket yang ditulis mati.
- [ ] 22.4 Daftar lisensi on-prem dan katalog admin.erp.

---

### 23. [ ] Module lain, tombol Analisis, dan dataset terhitung

**Tempat:** folder `src/Analytics` module lain; layar daftar module (opsional); kontrak dataset
terhitung · **Setelah:** 5 · **Keputusan:** PQ-08 untuk tombol Analisis; bentuk dataset terhitung ·
**Skill:** `module-discovery`, `coreerp-analytics` · **Selesai bila:** setiap module yang ada punya
dataset untuk resource utamanya dengan test paritas, dan keputusan tentang tombol Analisis tercatat.

- [ ] 23.1 Dataset module HR dan module berikutnya saat tersedia, dengan pola area 5.
- [ ] 23.2 Tombol Analisis di layar daftar module (bila PQ-08 disetujui): membuka penjelajah dengan
  dataset dan saringan layar saat itu.
- [ ] 23.3 Dataset terhitung: kontrak untuk angka yang dihitung module (KPI pemeliharaan) dengan
  dimensi dan periode terbatas, dijalankan module, dikemas engine menjadi hasil biasa.
- [ ] 23.4 Pilihan: `OrganizationScope` module aset memakai `DataPolicyFilter`, sehingga aturan
  kebijakan data tinggal satu.
