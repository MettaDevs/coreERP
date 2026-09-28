# TODO analisa gap CoreERP ke BC, fase 1

Butir kerja untuk [Analisa gap CoreERP ke Business Central, fase 1](/todo/AnalisaGapCoreErpkeBCPhase1/).
Baca halaman itu lebih dulu: setiap butir di sini hanya menyebut apa yang dibuat, sedangkan alasannya,
cara BC melakukannya, dan sumbernya ada di bagian README yang dirujuk.

Status mengikuti [aturan backlog](/todo/): `[ ]` belum, `[~]` sedang dikerjakan, `[x]` selesai
**dan** ada test yang membuktikannya. Setiap area punya **Tempat**, **Setelah** (area yang harus
selesai lebih dulu), dan **Selesai bila** (kriteria terima).

**Bagian A** berisi pekerjaan pembuatannya. **Bagian B** berisi test yang membuktikannya. Sebuah butir
A baru boleh `[x]` bila test B-nya lulus.

Urutan kerja yang disarankan:

```text
0 ─┬─▶ 1 ─▶ 2 ─▶ 4 ─▶ 8
   ├─▶ 3
   ├─▶ 5 ─▶ 6
   └─▶ 7
```

Area 3, 5, dan 7 tidak saling bergantung dan boleh dikerjakan paralel sejak keputusannya diambil.

---

## Bagian A: pembuatan

### 0. [ ] Keputusan yang masih terbuka

**Tempat:** pemilik produk · **Setelah:** — · **Selesai bila:** K-01 sampai K-08 dijawab dan dicatat di
tabel keputusan README.

- [ ] 0.1 K-01 nama kolom jejak dan rujukannya. [README: Gap 1 dan 6](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-1-6)
- [ ] 0.2 K-02 penangkap log perubahan: trigger PostgreSQL atau event Eloquent. [README: Gap 1 dan 6](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-1-6)
- [ ] 0.3 K-03 versi baris: kolom eksplisit atau `xmin`. [README: Gap 2](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-2)
- [ ] 0.4 K-04 tanggal kerja per pengguna masuk fase 1 atau tidak. [README: Gap 3](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-3)
- [ ] 0.5 K-05 daftar awal tabel yang boleh diretensi. [README: Gap 4](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-4)
- [ ] 0.6 K-06 bentuk deklarasi klasifikasi di kode. [README: Gap 5](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-5)
- [ ] 0.7 K-07 lampiran ikut berpindah antar dokumen atau tidak. [README: Gap 7](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-7)
- [ ] 0.8 K-08 job latar per tenant di fase 2 bersama notifikasi. [README: Gap 10](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-10)

### 1. [ ] Kolom jejak pembuat dan pengubah (gap 1)

**Tempat:** Core, lalu setiap module · **Setelah:** 0.1 · **Selesai bila:** setiap tabel tenant punya
kolom pelaku buat dan ubah yang terisi otomatis, dan test B-1 lulus.

Rujukan: [README: Gap 1 dan 6](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-1-6).

- [ ] 1.1 Helper migration untuk kolom jejak, dipakai tabel baru.
- [ ] 1.2 Pengisian otomatis dari pengguna yang sedang login, dan dari pengguna pemicu untuk job latar.
- [ ] 1.3 Migration yang menambahkan kolom jejak ke tabel tenant Core yang sudah ada.
- [ ] 1.4 Migration yang sama untuk tabel module (`management-aset`, `human-resources`).
- [ ] 1.5 Test boundary yang menolak tabel tenant baru tanpa kolom jejak.

### 2. [ ] Log perubahan dan riwayat per record (gap 6)

**Tempat:** Core (layanan dan Shell) · **Setelah:** 1, 0.2 · **Selesai bila:** perubahan pada tabel
dan field yang dipilih tenant tercatat lengkap, riwayat per record tampil di Shell, test B-2 lulus,
dan load test module aset tetap lolos dengan log aktif.

Rujukan: [README: Gap 1 dan 6](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-1-6).

- [ ] 2.1 Tabel setup per tenant: tabel mana, dan untuk penambahan/perubahan/penghapusan dicatat
      tidak sama sekali, sebagian field, atau semua field.
- [ ] 2.2 Tabel setup per field untuk mode sebagian field.
- [ ] 2.3 Tabel entri: waktu, pelaku, tabel, field, jenis perubahan, nilai lama, nilai baru, ID record.
      Entri tidak bisa diubah.
- [ ] 2.4 Penangkap sesuai K-02, termasuk pengiriman pelaku per transaksi.
- [ ] 2.5 Log dimatikan selama migration dan upgrade versi.
- [ ] 2.6 Endpoint riwayat per record, dengan nama pelaku, bukan ID.
- [ ] 2.7 Komponen riwayat di Shell, seperti contoh riwayat tugas dari pemilik.
- [ ] 2.8 Setelan awal: tabel aset dan pekerja dengan mode sebagian field, bukan semua field.

### 3. [ ] Versi baris dan pengaman edit bersamaan (gap 2)

**Tempat:** Core, lalu setiap module · **Setelah:** 0.3 · **Selesai bila:** penyimpanan dengan versi
basi ditolak dengan pesan yang jelas, baik dari layar maupun API, dan test B-3 lulus.

Rujukan: [README: Gap 2](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-2).

- [ ] 3.1 Kolom versi baris di tabel tenant, naik setiap kali baris berubah.
- [ ] 3.2 Endpoint baca memulangkan versi; API memulangkannya sebagai ETag.
- [ ] 3.3 Endpoint tulis mewajibkan versi (form) atau `If-Match` (API), lalu update bersyarat.
- [ ] 3.4 Jawaban 409 dengan pesan yang menjelaskan dampaknya bagi pengguna.
- [ ] 3.5 Form di Shell membawa versi dan menampilkan pesan muat ulang saat ditolak.

### 4. [ ] Retensi data log (gap 4)

**Tempat:** Core · **Setelah:** 2, 0.5 · **Selesai bila:** semua penghapusan log berjalan lewat satu
layanan, nilai bawaannya sama dengan config hari ini, dan test B-4 lulus.

Rujukan: [README: Gap 4](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-4).

- [ ] 4.1 Daftar tabel yang boleh diretensi di kode: tabel, kolom tanggal, minimum, bawaan.
- [ ] 4.2 Bawaan diambil dari `coreerp.audit_retention_days`, `coreerp.confirmed_pool_retention_days`,
      dan `reporting.retention_days`.
- [ ] 4.3 Setelan masa simpan per tenant yang tidak boleh di bawah minimum.
- [ ] 4.4 Satu job terjadwal yang menerapkan kebijakan dan mencatat hasilnya.
- [ ] 4.5 `RecoverNumberSequenceReservations` dan `PurgeReportExports` beralih memakai layanan ini.
- [ ] 4.6 Entri log perubahan (area 2) didaftarkan dengan waktu buat sebagai tanggal acuan.

### 5. [ ] Klasifikasi data per kolom (gap 5)

**Tempat:** Core, lalu setiap module · **Setelah:** 0.6 · **Selesai bila:** setiap model tenant punya
klasifikasi, telemetri hanya membawa data `SystemMetadata`, dan test B-5 lulus.

Rujukan: [README: Gap 5](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-5).

- [ ] 5.1 Enum klasifikasi dengan nilai yang sama dengan BC.
- [ ] 5.2 Deklarasi klasifikasi bawaan tabel dan timpaan per kolom, sesuai K-06.
- [ ] 5.3 Klasifikasi semua model tenant Core yang sudah ada.
- [ ] 5.4 Klasifikasi model module aset dan HR, dengan kolom identitas ditandai eksplisit.
- [ ] 5.5 Penyaring telemetri: hanya atribut `SystemMetadata` yang dikirim ke OpenTelemetry/SigNoz.
- [ ] 5.6 Test yang gagal bila ada model tenant tanpa klasifikasi (padanan AS0016).

### 6. [ ] Layanan lampiran dokumen (gap 7)

**Tempat:** Core, dipakai module · **Setelah:** 5, 0.7 · **Selesai bila:** record di daftar fase 1
dapat diberi lampiran lewat layanan Core, haknya mengikuti record induk, dan test B-6 lulus. Tampilan
lampiran dibuat di PRD lain.

Rujukan: [README: Gap 7](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-7).

- [ ] 6.1 Tabel lampiran: tenant, jenis record, ID record, baris dokumen (opsional), metadata berkas,
      lokasi di disk, hash isi, pelaku, waktu, `deleted_at`.
- [ ] 6.2 Penyimpanan di disk `s3`.
- [ ] 6.3 Pemeriksaan hak berdasarkan record induk.
- [ ] 6.4 Klasifikasi lampiran mengikuti induknya.
- [ ] 6.5 Endpoint unggah, daftar, unduh, dan arsip.
- [ ] 6.6 Pendaftaran record fase 1 sesuai tabel di README.

### 7. [ ] Tanggal kerja dan tautan pengguna ke pekerja (gap 3)

**Tempat:** Core dan module HR · **Setelah:** 0.4 · **Selesai bila:** butir yang disetujui K-04 selesai
dan test B-7 lulus.

Rujukan: [README: Gap 3](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-3).

- [ ] 7.1 Bila K-04 disetujui: tanggal kerja per pengguna, dipakai sebagai tanggal bawaan di form
      transaksi.
- [ ] 7.2 Usulan tautan pekerja ke keanggotaan berdasarkan kecocokan email di form pekerja HR.
- [ ] 7.3 Pekerja yang tertaut tampil di layar pengguna.
- [ ] 7.4 Catatan di backlog HR: tautan pekerja ke template jam kerja dibuat saat absensi atau
      timesheet dibangun.

### 8. [ ] Penyempurnaan ekspor laporan (gap 10)

**Tempat:** Core (reporting) · **Setelah:** 4, 0.8 · **Selesai bila:** gangguan sesaat diulang terbatas,
kegagalan tetap tidak diulang, masa simpan lewat layanan retensi, dan test B-8 lulus.

Rujukan: [README: Gap 10](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-10).

- [ ] 8.1 Bedakan gangguan sesaat (misalnya renderer terlambat) dari kegagalan tetap; ulangi yang
      pertama dengan batas percobaan.
- [ ] 8.2 Masa simpan hasil ekspor lewat layanan retensi (area 4).
- [ ] 8.3 Opsional: batas baris dapat dinaikkan per laporan di bawah batas maksimum.
- [ ] 8.4 Opsional: ekspor baris yang sedang tampil di tabel dari frontend, dengan batas baris.

---

## Bagian B: test

- [ ] **B-1** (area 1) Tabel tenant tanpa kolom jejak ditolak test boundary. Membuat dan mengubah
      record lewat request mengisi pelaku yang benar; job latar mengisi pengguna pemicunya.
- [ ] **B-2** (area 2) Update lewat query builder tercatat. Field di luar setup tidak tercatat. Entri
      tidak bisa diubah. Riwayat per record urut waktu dan tidak bocor antar tenant.
- [ ] **B-3** (area 3) Dua penyimpanan dengan versi yang sama: yang kedua ditolak 409. `PATCH` tanpa
      `If-Match` atau dengan ETag basi ditolak.
- [ ] **B-4** (area 4) Tanpa setelan tenant, hasilnya sama dengan perilaku hari ini. Masa simpan di
      bawah minimum ditolak. Tabel di luar daftar tidak pernah tersentuh.
- [ ] **B-5** (area 5) Model tanpa klasifikasi membuat test gagal. Atribut selain `SystemMetadata` tidak
      sampai ke exporter telemetri.
- [ ] **B-6** (area 6) Pengguna tanpa hak atas record induk tidak bisa melihat atau mengunduh
      lampirannya. Lampiran tidak bocor antar tenant. Hash yang tidak cocok terdeteksi.
- [ ] **B-7** (area 7) Usulan tautan hanya menawarkan keanggotaan tenant yang sama. Satu keanggotaan
      tidak bisa tertaut ke dua pekerja.
- [ ] **B-8** (area 8) Gangguan sesaat diulang sampai batas percobaan. Kegagalan layout dan data
      terlalu besar tetap tidak diulang.
