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

9 (tanpa prasyarat)
```

Area 3, 5, dan 7 tidak saling bergantung dan boleh dikerjakan paralel sejak keputusannya diambil. Area 9
tidak menunggu keputusan apa pun dan boleh dikerjakan kapan saja.

---

## Bagian A: pembuatan

### 0. [x] Keputusan

**Tempat:** pemilik produk · **Setelah:** — · **Selesai bila:** K-01 sampai K-10 dijawab dan dicatat di
tabel keputusan README.

Butir area ini keputusan, bukan kode. `[x]` di sini berarti sudah diputuskan pemilik dan dicatat di
README; syarat test pada legenda status berlaku mulai area 1.

Semua keputusan diambil pemilik pada 28 September 2026; isinya di tabel Keputusan README.

- [x] 0.1 K-01 nama kolom jejak: `created_by_user_id`/`updated_by_user_id` ke `users.id`.
      [README: Gap 1 dan 6](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-1-6)
- [x] 0.2 K-02 penangkap log perubahan: trigger PostgreSQL. [README: Gap 1 dan 6](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-1-6)
- [x] 0.3 K-03 versi baris: kolom eksplisit. [README: Gap 2](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-2)
- [x] 0.4 K-04 tanggal kerja per pengguna: masuk fase 1, diisi di My Profile, perilaku seperti BC
      (diputuskan pemilik 28 September 2026). [README: Gap 3](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-3)
- [x] 0.5 K-05 daftar awal tabel yang boleh diretensi. [README: Gap 4](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-4)
- [x] 0.6 K-06 bentuk deklarasi klasifikasi di kode. [README: Gap 5](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-5)
- [x] 0.7 K-07 lampiran belum ikut berpindah antar dokumen di fase 1. [README: Gap 7](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-7)
- [x] 0.8 K-08 job latar per tenant di fase 2 bersama notifikasi. [README: Gap 10](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-10)
- [x] 0.9 K-09 lampiran memakai satu tabel untuk semua record, seperti `Document Attachment` BC
      (diputuskan pemilik 28 September 2026). [README: Gap 7](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-7)
- [x] 0.10 K-10 nilai bawaan zona waktu pengguna: setelan zona waktu entitas legal.
      [README: Gap 3](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-3)

### 1. [x] Kolom jejak pembuat dan pengubah (gap 1)

**Tempat:** Core, lalu setiap module · **Setelah:** 0.1 · **Selesai bila:** setiap tabel tenant punya
kolom pelaku buat dan ubah yang terisi otomatis, dan test B-1 lulus.

Rujukan: [README: Gap 1 dan 6](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-1-6).

- [x] 1.1 Helper migration untuk kolom jejak, dipakai tabel baru. `AuditColumns` di `App\Support\Modules\Contracts`.
- [x] 1.2 Pengisian otomatis dari pengguna yang sedang login, dan dari pengguna pemicu untuk job latar.
- [x] 1.3 Migration yang menambahkan kolom jejak ke tabel tenant Core yang sudah ada.
- [x] 1.4 Migration yang sama untuk tabel module (`management-aset`, `human-resources`).
- [x] 1.5 Test boundary yang menolak tabel tenant baru tanpa kolom jejak.

### 2. [x] Log perubahan dan riwayat per record (gap 6)

**Tempat:** Core (layanan dan Shell) · **Setelah:** 1, 0.2 · **Selesai bila:** perubahan pada tabel
dan field yang dipilih tenant tercatat lengkap, riwayat per record tampil di Shell, test B-2 lulus,
dan load test module aset tetap lolos dengan log aktif.

Rujukan: [README: Gap 1 dan 6](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-1-6).

- [x] 2.1 Tabel setup per tenant: tabel mana, dan untuk penambahan/perubahan/penghapusan dicatat
      tidak sama sekali, sebagian field, atau semua field.
- [x] 2.2 Tabel setup per field untuk mode sebagian field.
- [x] 2.3 Tabel entri: waktu, pelaku, tabel, field, jenis perubahan, nilai lama, nilai baru, ID record.
      Entri tidak bisa diubah.
- [x] 2.4 Penangkap sesuai K-02, termasuk pengiriman pelaku lewat variabel sesi.
- [x] 2.5 Log dimatikan selama migration dan upgrade versi.
- [x] 2.6 Endpoint riwayat per record, dengan nama pelaku, bukan ID.
- [x] 2.7 Komponen riwayat di Shell, seperti contoh riwayat tugas dari pemilik. Dipakai halaman aset;
      layar pekerja HR belum ada.
- [x] 2.8 Setelan awal: tabel aset dan pekerja dengan mode sebagian field, bukan semua field. Dikirim
      module lewat `ChangeLogDefaults`; tenant menggantinya di Pengaturan → Riwayat perubahan.
- [x] 2.9 Tabel akses tanpa `tenant_id` ikut selalu dicatat: penugasan peran, duty per peran,
      privilege per duty, dan permission per privilege. Tenantnya dibaca trigger dari induk yang dirujuk.
- [x] 2.10 Load test aset dengan log aktif: penjenuhan `receipt-posting.js` lulus, kedua `verify.sql`
      bernilai 0 termasuk pemeriksaan log yang baru. Hasilnya di `apps/core/loadtest/README.md`.
      `master-data.js` dan `work-order.js` belum cocok dengan API aset sejak 18 September; perbaikannya
      tugas terpisah.
- [x] 2.11 Penulisan klien integrasi tercatat atas nama akun aplikasinya, bukan sistem (keputusan
      pemilik 29 September 2026, opsi A). Akun itu tidak pernah dapat masuk.

### 3. [x] Versi baris dan pengaman edit bersamaan (gap 2)

**Tempat:** Core, lalu setiap module · **Setelah:** 0.3 · **Selesai bila:** penyimpanan dengan versi
basi ditolak dengan pesan yang jelas, baik dari layar maupun API, dan test B-3 lulus.

Rujukan: [README: Gap 2](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-2).

- [x] 3.1 Kolom versi baris di tabel tenant yang belum punya, naik setiap kali baris berubah. Kolom
      `version` di dokumen aset menjadi acuannya, bukan diganti. Dinaikkan trigger
      `coreerp_bump_row_version` (K-11) di semua tabel tenant Core dan module (K-12), dijaga test boundary
      kolom jejak. Kolom aktivitas mesin klien integrasi tidak menaikkan versi (keputusan 29 September 2026).
- [x] 3.2 Endpoint baca memulangkan versi; API memulangkannya sebagai ETag.
- [x] 3.3 Endpoint tulis mewajibkan versi (form) atau `If-Match` (API), lalu update bersyarat lewat satu
      helper Core. Controller aset yang sekarang menulis penolakannya sendiri beralih ke helper itu.
      Helpernya `RowVersion` (`claim`, dan `claimIfExists` untuk setelan yang lahir saat pertama disimpan).
      Semua endpoint ubah dan arsip Core dan aset (K-13); yang sengaja tidak ikut: baris milik pengguna
      sendiri, rute sesi, pembuatan, impor kumpulan, transisi status yang sudah dijaga status, dan
      `internal/v1` mesin.
- [x] 3.4 Jawaban 409 dengan pesan yang menjelaskan dampaknya bagi pengguna. Tanpa versi dijawab 428.
- [x] 3.5 Form di Shell membawa versi dan menampilkan pesan muat ulang saat ditolak.
- [x] 3.6 Suite Core dan Module penuh hijau, dan load test aset (skenario sudah mengirim versi) lulus
      dengan `verify.sql` bernilai 0. Hasilnya di `apps/core/loadtest/README.md`.

### 4. [x] Retensi data log (gap 4)

**Tempat:** Core · **Setelah:** 2, 0.5 · **Selesai bila:** semua penghapusan log berjalan lewat satu
layanan, nilai bawaannya sama dengan config hari ini, dan test B-4 lulus.

Rujukan: [README: Gap 4](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-4).

- [x] 4.1 Daftar tabel yang boleh diretensi di kode: tabel, kolom tanggal, minimum, bawaan.
- [x] 4.2 Bawaan diambil dari `coreerp.audit_retention_days`, `coreerp.confirmed_pool_retention_days`,
      dan `reporting.retention_days`.
- [x] 4.3 Setelan masa simpan per tenant yang tidak boleh di bawah minimum.
- [x] 4.4 Satu job terjadwal yang menerapkan kebijakan dan mencatat hasilnya.
- [x] 4.5 `RecoverNumberSequenceReservations` dan `PurgeReportExports` beralih memakai layanan ini.
- [x] 4.6 Entri log perubahan (area 2) didaftarkan dengan `changed_at` (waktu perubahan) sebagai tanggal
      acuan, dua kebijakan: tabel yang selalu dicatat min. 365 hari, lainnya min. 28 hari, mati bawaannya.
- [x] 4.7 Layar Pengaturan → Retensi data dengan `core.retention.read`/`core.retention.update` (K-15).
- [x] 4.8 Hasil tiap penerapan per tenant di `retention_policy_log_entries`, tampil di layar dan ikut
      diretensi (K-16).

### 5. [x] Klasifikasi data per kolom (gap 5)

**Tempat:** Core, lalu setiap module · **Setelah:** 0.6 · **Selesai bila:** setiap tabel tenant punya
klasifikasi, notifikasi Discord hanya membawa data teknis (K-18), dan test B-5 lulus.

Rujukan: [README: Gap 5](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-5).

- [x] 5.1 Enum klasifikasi dengan nilai yang sama dengan BC (`DataClass`).
- [x] 5.2 Deklarasi klasifikasi bawaan tabel dan timpaan per kolom, sesuai K-06; tabel tanpa model di
      registry per pemilik (K-19).
- [x] 5.3 Klasifikasi semua tabel tenant Core yang sudah ada: 48 lewat model, 26 lewat registry.
- [x] 5.4 Klasifikasi model module aset (51 tabel) dan HR (4 tabel), dengan kolom identitas ditandai
      eksplisit.
- [x] 5.5 Laporan kesalahan (K-18): SigNoz, di server sendiri, tetap menerima laporan utuh. Notifikasi
      Discord, pihak ketiga, hanya membawa kelas exception, method dan rute, status, `tenant_id`, jejak,
      dan tautan ke SigNoz; tanpa nama orang, email, nama tenant, user agent, atau pesan exception dan SQL
      bernilai. Menyimpang dari BC, yang hanya mengirim `SystemMetadata` ke telemetri.
- [x] 5.6 Test yang gagal bila ada tabel tenant tanpa klasifikasi (padanan AS0016).

### 6. [ ] Layanan lampiran dokumen (gap 7)

**Tempat:** Core, dipakai module · **Setelah:** 5, 0.1, 0.7 · **Selesai bila:** record di daftar fase 1
dapat diberi lampiran lewat layanan Core, haknya mengikuti record induk, dan test B-6 lulus. Tampilan
lampiran dibuat di PRD lain.

Rujukan: [README: Gap 7](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-7).

- [ ] 6.1 Satu tabel lampiran untuk semua record (K-09): tenant, jenis record, ID record, baris dokumen
      (opsional), metadata berkas, lokasi di disk, hash isi, pelaku (nama kolom sesuai K-01), waktu,
      `deleted_at`.
- [ ] 6.2 Penyimpanan di disk `s3`.
- [ ] 6.3 Pemeriksaan hak berdasarkan record induk.
- [ ] 6.4 Klasifikasi lampiran mengikuti induknya.
- [ ] 6.5 Endpoint unggah, daftar, unduh, dan arsip.
- [ ] 6.6 Pendaftaran record fase 1 sesuai tabel di README.

### 7. [ ] Zona waktu dan tanggal kerja pengguna (gap 3)

**Tempat:** Core (My Profile, Shell, reporting) dan module yang mencetak atau mengisi tanggal ·
**Setelah:** 0.4, 0.10 · **Selesai bila:** zona waktu dan tanggal kerja bisa diisi di My Profile, layar
dan cetakan memakai zona pengguna, form transaksi memakai tanggal kerja, dan test B-7 lulus.

Rujukan: [README: Gap 3](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-3).

Zona waktu dikerjakan lebih dulu: "hari ini" pada tanggal kerja baru benar bila dihitung menurut zona
pengguna.

- [x] 7.1 Setelan zona waktu per pengguna di My Profile, dengan nilai bawaan sesuai K-10.
- [x] 7.2 "Hari ini" dihitung dari jam server menurut zona pengguna, tidak pernah dari jam perangkat.
      Form yang sekarang mengisi tanggal dengan `toISOString()` beralih ke nilai ini.
- [ ] 7.3 Layar dan cetakan memformat waktu dengan zona pengguna; cetakan menuliskan zonanya, misalnya
      "28/09/2026 14:05 WITA".
- [ ] 7.4 Module mengirim waktu UTC bertipe `datetime`, dan Core yang memformatnya lewat `ValueFormat`.
      Konteks laporan dan permintaan membawa zona pengguna untuk "hari ini" di module, misalnya periode
      bawaan dan nama berkas.
- [x] 7.5 Tanggal kerja per pengguna per sesi, bawaannya hari ini, diisi di My Profile.
- [x] 7.6 Tanggal kerja kembali ke hari ini saat login ulang atau pindah tenant/legal entity.
- [x] 7.7 Form transaksi memakai tanggal kerja sebagai tanggal bawaan. Sudah: penerimaan, mutasi, dan
      perencanaan aset.
- [x] 7.8 Pengingat di Shell selama tanggal kerja bukan hari ini, mengarah ke My Profile, bisa ditutup
      untuk sisa sesi. Setelah ditutup, tanggal kerja tetap terlihat.

### 8. [ ] Penyempurnaan ekspor laporan (gap 10)

**Tempat:** Core (reporting) · **Setelah:** 4, 0.8 · **Selesai bila:** gangguan sesaat diulang terbatas,
kegagalan tetap tidak diulang, masa simpan lewat layanan retensi, dan test B-8 lulus.

Rujukan: [README: Gap 10](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-10).

- [ ] 8.1 Bedakan gangguan sesaat (misalnya renderer terlambat) dari kegagalan tetap; ulangi yang
      pertama dengan batas percobaan.
- [ ] 8.2 Masa simpan hasil ekspor lewat layanan retensi (area 4). Layanan dan pemakaiannya di
      `RunReportExport` sudah ada; menunggu test yang menjalankan job-nya.
- [ ] 8.3 Opsional: batas baris dapat dinaikkan per laporan di bawah batas maksimum.
- [ ] 8.4 Opsional: ekspor baris yang sedang tampil di tabel dari frontend, dengan batas baris.

### 9. [ ] Tautan pengguna ke pekerja HR (gap 3)

**Tempat:** module HR dan layar pengguna di Core · **Setelah:** — · **Selesai bila:** form pekerja HR
mengusulkan keanggotaan yang cocok, pekerja yang tertaut tampil di layar pengguna, dan test B-9 lulus.

Rujukan: [README: Gap 3](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-3).

- [ ] 9.1 Usulan tautan pekerja ke keanggotaan berdasarkan kecocokan email di form pekerja HR.
- [ ] 9.2 Pekerja yang tertaut tampil di layar pengguna.
- [ ] 9.3 Catatan di backlog HR: tautan pekerja ke template jam kerja dibuat saat absensi atau
      timesheet dibangun.

---

## Bagian B: test

- [x] **B-1** (area 1) Tabel tenant tanpa kolom jejak ditolak test boundary. Membuat dan mengubah
      record lewat request mengisi pelaku yang benar; job latar mengisi pengguna pemicunya.
- [x] **B-2** (area 2) Update lewat query builder tercatat. Field di luar setup tidak tercatat. Entri
      tidak bisa diubah. Riwayat per record urut waktu dan tidak bocor antar tenant.
- [x] **B-3** (area 3) Dua penyimpanan dengan versi yang sama: yang kedua ditolak 409. `PATCH` tanpa
      `If-Match` atau dengan ETag basi ditolak.
- [x] **B-4** (area 4) Tanpa setelan tenant, hasilnya sama dengan perilaku hari ini. Masa simpan di
      bawah minimum ditolak. Tabel di luar daftar tidak pernah tersentuh.
- [x] **B-5** (area 5) Tabel tenant Core atau module tanpa klasifikasi, berbawaan `ToBeClassified`,
      bertimpaan kolom yang tidak ada, atau berkolom nama/email/alamat yang ikut bawaan tanpa ditulis
      eksplisit membuat test gagal. Nama orang, email, nama tenant, dan SQL bernilai di laporan kesalahan
      tidak sampai ke Discord, sementara atribut untuk SigNoz tetap utuh.
- [ ] **B-6** (area 6) Pengguna tanpa hak atas record induk tidak bisa melihat atau mengunduh
      lampirannya. Lampiran tidak bocor antar tenant. Hash yang tidak cocok terdeteksi.
- [ ] **B-7** (area 7) Pukul 00.30 WIB (17.30 UTC hari sebelumnya), "hari ini" bagi pengguna berzona
      `Asia/Jakarta` adalah tanggal WIB, bukan tanggal UTC. Waktu di layar dan cetakan mengikuti zona
      pengguna, dan cetakan menuliskan zonanya. Tanggal kerja yang diisi dipakai form, dan kembali ke hari
      ini setelah login ulang atau pindah tenant/legal entity. Pengingat muncul selama tanggal kerja bukan
      hari ini; setelah ditutup, ia tidak muncul lagi di sesi itu dan tanggal kerja tetap terlihat.
- [ ] **B-8** (area 8) Gangguan sesaat diulang sampai batas percobaan. Kegagalan layout dan data
      terlalu besar tetap tidak diulang.
- [ ] **B-9** (area 9) Usulan tautan hanya menawarkan keanggotaan tenant yang sama. Satu keanggotaan
      tidak bisa tertaut ke dua pekerja.
