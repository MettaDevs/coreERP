# TODO part 1 — vendor dan akses dari luar

Butir kerja untuk [Part 1](/todo/procurement/01-vendor-dan-akses-eksternal/). Baca PRD-nya lebih
dulu; keputusan `K1-01` dan seterusnya dijelaskan alasannya di sana.

Status mengikuti [aturan backlog](/todo/): `[ ]` belum, `[~]` sedang dikerjakan, `[x]` selesai
**dan** ada test yang membuktikannya. Setiap area punya **Tempat**, **Setelah** (yang harus selesai
lebih dulu), **Keputusan** (yang harus sudah diputuskan), dan **Selesai bila**.

Area Foundation dan Platform menunggu pemindahan lapis Core ([lapis-core](/todo/lapis-core/))
selesai, supaya berkas baru langsung lahir di tempatnya.

```text
part 0 kerangka berjalan ──────────┐
1 kontak vendor ──▶ 2 kartu vendor ┴──▶ 3 prakualifikasi pada vendor ──┐
                                                                     ├──▶ 5 onboarding lewat portal
tambalan SSO ──▶ 4 akses eksternal ──────────────────────────────────┘
```

Area 1–3 adalah fase 1, kecuali dua butir area 3 yang menunggu part 2. Area 4–5 adalah fase 2.

---

### 1. [ ] Kontak vendor

**Tempat:** Foundation, buku alamat · **Setelah:** — · **Keputusan:** K1-05 · **Selesai bila:**
satu vendor dapat punya banyak kontak orang per legal entity, kontak tampil di kartu vendor, dan
test membuktikan kontak tenant lain tidak dapat ditautkan.

- [ ] 1.1 Relasi party orang ↔ vendor per legal entity, padanan *contact person* F&O. Bentuknya
  ditimbang dengan `party_role_registrations` (peran `contact` sudah dikenal di sana).
- [ ] 1.2 Bagian **Kontak** di kartu vendor, dengan pemilih orang dari buku alamat.
- [ ] 1.3 Kontak dapat dinonaktifkan tanpa dihapus; dokumen lama tetap menunjuk kontaknya.

### 2. [ ] Kartu vendor: nomor identitas, duplikat, tahanan, dan perubahan berpersetujuan

**Tempat:** Foundation · **Setelah:** 1 · **Keputusan:** K1-10, K1-11 · **Selesai bila:** syarat
nomor identitas tersimpan per negara dan jenis vendor, peringatan duplikat muncul tanpa menolak,
tahanan vendor dihormati dokumen yang memakainya, dan perubahan field yang dijaga menunggu
persetujuan.

- [ ] 2.1 Setelan syarat nomor identitas per negara dan jenis vendor. Bawaan Indonesia: NPWP
  wajib, NIB opsional; data bawaan untuk tenant lama lewat migration, bukan seeder.
- [ ] 2.2 Peringatan nomor ganda saat menyimpan vendor: NPWP, NIK, atau NIB yang sudah dipakai
  vendor lain di tenant yang sama.
- [ ] 2.3 Kontrak Foundation yang dipakai area 3 untuk memeriksa nomor ganda tanpa menyimpan.
- [ ] 2.4 **Tahanan vendor** mengikuti *vendor hold* F&O: Tidak, Invoice, Pembayaran, Permintaan,
  Semua, Tidak pernah. Kolom `status` hari ini (`active`/`inactive`) tidak cukup. Dokumen yang
  memakai vendor membaca tahanan ini.
- [ ] 2.5 **Perubahan field penting pada vendor yang sudah lolos** — rekening bank, NPWP, nama —
  menjadi usulan perubahan yang disetujui lewat workflow, seperti *vendor workflow* F&O. Field mana
  yang dijaga adalah setelan client.

### 3. [ ] Prakualifikasi dan kualifikasi pada vendor, tanpa portal

**Tempat:** modul, sebagai bagian procurement di kartu vendor · **Setelah:** part 0, 1, 2 ·
**Keputusan:** K1-02, K1-05, K1-10, K1-11; butir 3.3 dan 3.4 menunggu part 2 · **Selesai bila:**
vendor baru tertahan sampai dikualifikasi, staf dapat melengkapi dokumen dan kategorinya, melepas
tahanan hanya lewat hak terpisah, dan semua test di 3.8 lulus.

Area ini paling lengkap digambar di rancangan QA (halaman **Master Data**, bagian Prakualifikasi
dan Kualifikasi). Mengikuti F&O (`K1-11`), kualifikasi adalah **keadaan pada vendor**, bukan dokumen
tersendiri. Persyaratan di bawah **menggantikan** rancangan QA bila keduanya berbeda.

- [ ] 3.1 **Vendor baru yang belum dikualifikasi ditahan.** Staf membuat vendor di kartu vendor
  Foundation (bukan lewat *vendor request*, yang di F&O hanya lahir dari wizard portal) dengan
  tahanan **All** (2.4). Kolom registrasi QA yang dipertahankan — nama, jenis vendor, status badan
  hukum, NPWP/NIK, NIB, telepon, email, media sosial, alamat, jumlah karyawan, waktu kesediaan
  barang, catatan — mengisi kartu vendor dan buku alamat, bukan tabel prakualifikasi. "Nama PIC"
  menjadi kontak orang (K1-05); kolom user dan password **tidak ada** (K1-02).
- [ ] 3.2 **Daftar "Prakualifikasi" di QA = daftar vendor yang masih tertahan karena belum
  dikualifikasi**, bukan dokumen bernomor. **Berbeda dari QA:** nomor `Pra/VIII/26/0001` hilang,
  karena F&O tidak punya dokumen prakualifikasi; jejak siapa memeriksa dan kapan tercatat di riwayat
  perubahan vendor.
- [ ] 3.3 **Kelengkapan dokumen** ("Ada/Tidak Ada" di QA) menjadi **sertifikasi vendor** seperti F&O:
  jenis, nomor, masa berlaku, dan lampiran. Daftar jenisnya data yang diatur client. Bentuk dan
  pemiliknya diputuskan di part 2.
- [ ] 3.4 **Barang yang dapat dipasok** menjadi **kategori pengadaan yang disetujui per vendor**
  dan, bila diputuskan di part 2, evaluasi vendor per kriteria seperti F&O.
- [ ] 3.5 **"Lolos" = tahanan dilepas; "Batal" = tahanan dipertahankan dengan alasan wajib.**
  Melepas tahanan memakai permission tersendiri, dan orang yang membuat vendor tidak boleh
  melepasnya sendiri (SoD). Vendor yang batal tidak dihapus; ia tetap tertahan atau diarsipkan.
- [ ] 3.6 **Daftar "Kualifikasi" di QA = vendor yang tahanannya sudah dilepas**, dengan kategori
  yang disetujui.
- [ ] 3.7 **Peringatan duplikat** (K1-10) saat vendor dibuat, lewat 2.2.
- [ ] 3.8 **Test:** penyaringan tenant; vendor tertahan tidak dapat dipakai di dokumen pengadaan;
  pembuat vendor tidak dapat melepas tahanannya; melepas tanpa hak ditolak; batal tanpa alasan
  ditolak.
- [ ] 3.9 **Akses:** rantai entry point → permission → privilege → duty per resource, dideklarasikan
  di `manifest/`; gate data policy dari skill `coreerp-architecture` dijalankan sebelum tabel dibuat.
- [ ] 3.10 Halaman dokumentasi di `docs/apps/procurement/`, mengikuti
  [pola dokumen](/apps/management-aset/pola-dokumen).

### 4. [ ] Akses eksternal vendor

**Tempat:** Platform · **Setelah:** tambalan SSO, 1 · **Keputusan:** K1-01, K1-03, K1-06, K1-08,
K1-09 · **Selesai bila:** user eksternal hanya membaca baris vendor tempat ia menjadi kontak aktif,
dibuktikan test dan gate load test fase 2.

- [ ] 4.1 Jenis keanggotaan eksternal yang tidak pernah melihat menu internal.
- [ ] 4.2 Data policy per vendor: baris disaring lewat kontak aktif user, padanan *extensible data
  security* F&O. Data policy hari ini hanya berbasis organisasi.
- [ ] 4.3 Undangan berbukti kotak masuk (K1-06): tautan ke email, diikat ke akun yang menebusnya;
  tidak memilih akun lewat pencarian email di SSO.
- [ ] 4.4 Login user eksternal selalu lewat SSO kita (K1-08), dan aturan satu penyedia per tenant
  disesuaikan.
- [ ] 4.5 Role eksternal bawaan *Vendor*, *Vendor admin*, *Vendor prospect* (K1-04); client memilih
  yang tersedia.
- [ ] 4.6 Verifikasi dua langkah wajib untuk user eksternal yang login dengan kata sandi (K1-06).
- [ ] 4.7 Pencabutan akses per tenant oleh staf client atau *Vendor admin*.

### 5. [ ] Onboarding calon vendor lewat portal

**Tempat:** modul · **Setelah:** 3, 4 · **Keputusan:** K1-04, K1-07 · **Selesai bila:** permintaan
calon vendor yang diketik staf dapat diundang, calon vendor mengisi wizard, dan *vendor request*
yang disetujui melahirkan tepat satu vendor dengan sertifikasi dan kategori yang ia ajukan.

- [ ] 5.1 **Permintaan calon vendor** mengikuti layar F&O *Prospective vendor registration
  requests*: nama perusahaan, bidang usaha, alasan, nomor organisasi (NIB), jenis organisasi, nama
  dan email kontak, legal entity, bahasa, status proses. Staf dapat membuatnya (K1-07).
- [ ] 5.2 Undangan ke kontaknya dengan role *Vendor prospect*, lewat area 4.
- [ ] 5.3 **Wizard registrasi** melahirkan *vendor request*. Isi wizard diatur per negara (*vendor
  request configuration* F&O, K1-10).
- [ ] 5.4 **Persetujuan *vendor request* lewat workflow Core.** Disetujui → vendor dibuat lewat
  kontrak Foundation dengan kunci idempoten (`creation_key` di `vendors`), sehingga persetujuan
  ganda tetap melahirkan satu vendor; sertifikasi dan kategori yang diajukan ikut ke bagian
  kualifikasi di area 3. Ditolak → tidak ada vendor yang lahir, dan user calon vendor dinonaktifkan.
- [ ] 5.5 Peringatan duplikat (K1-10) saat *vendor request* diperiksa, lewat 2.3.

Balasan RFQ, respons PO, dan invoice oleh vendor bukan bagian part ini; masing-masing ada di part
4, 5, dan 7.
