# TODO Management Aset - Standar Indonesia, organisasi, dan seed tenant

Dokumen ini adalah backlog keputusan dan implementasi dari pembahasan UI
Management Aset, pembanding Dynamics 365 Finance & Operations (D365 F&O),
lokasi/unit organisasi, buku penyusutan, profil penyusutan, dan default
Indonesia.

Dokumen ini bukan desain kanonik. Aturan lintas modul tetap mengikuti
[`docs/dev/README.md`](../../../dev/README.md) dan skill arsitektur CoreERP.

## Keputusan yang sudah diambil

- Default produk disiapkan untuk Indonesia dan seed dasar diberlakukan ke
  semua tenant. Negara atau rezim pajak tetap harus menjadi konfigurasi agar
  desain tidak terkunci untuk selamanya.
- Lokasi fisik dan departemen/unit organisasi bukan hal yang sama. Lokasi
  menyatakan tempat aset; unit organisasi menyatakan pemilik atau dimensi
  operasional/keuangan yang dirujuk dari Core.
- Pola lokasi, operating unit, dan financial dimension mengikuti perilaku
  standar D365 terlebih dahulu. Kita tidak membuat aturan hubungan custom
  sebelum bridging Finance tersedia.
- Tahap awal hanya menyiapkan cangkang `Dimensi keuangan` pada form Lokasi
  Aset. Cangkang ini belum memiliki pilihan, validasi, endpoint, penyimpanan,
  atau proses posting.
- V1 Management Aset belum membuat jurnal GL. COA, akun utama, posting profile,
  financial dimension, debit/kredit, dan rekonsiliasi jurnal tetap menjadi
  tanggung jawab Finance/backoffice.
- Klasifikasi fiskal Kelompok 1, 2, 3, dan 4 harus menjadi data konfigurasi
  berversi, bukan pilihan yang ditulis di PHP atau React.
- Kelompok aset milik tenant tetap dapat dibuat sesuai bisnis tenant. Seed
  fiskal menyediakan referensi standar, tetapi tidak boleh memaksa nama atau
  struktur aset milik perusahaan tertentu.
- Tidak ada satu umur manfaat komersial yang benar untuk semua aset. Buku
  komersial mengikuti kebijakan akuntansi tenant/legal entity; default pajak
  Indonesia adalah starter configuration, bukan pengganti kebijakan komersial.

## Status dan bukti

Gunakan `[ ]` untuk belum dikerjakan, `[~]` untuk sedang dikerjakan, dan `[x]`
untuk selesai. `[x]` hanya boleh dipakai setelah ada bukti migration/seed,
test, kontrak, dan verifikasi runtime yang relevan.

Prioritas: `P0` menghalangi data atau keamanan, `P1` wajib sebelum pilot,
`P2` dapat menyusul setelah pilot.

## Backlog perubahan

### [x] MAS-IND-01 (P0) - Ikuti pola D365 dan siapkan cangkang bridging Finance

**Masalah saat ini**

- Finance belum tersedia, sehingga belum ada sumber data financial dimension
  yang boleh dipakai oleh Management Aset.
- Form lokasi masih menampilkan `ID unit organisasi` sebagai input teks. User
  tidak seharusnya mengetik ULID atau ID internal, tetapi penggantian kontrol
  belum boleh dilakukan sebelum requirement bridging disetujui.
- Lokasi D365 lebih dekat ke functional location/physical hierarchy; operating
  unit dan financial dimension adalah konsep organisasi/keuangan yang berbeda.
- D365 memisahkan modul Fixed assets Finance dari Asset Management/functional
  location. Relasi dan pewarisan financial dimension menjadi bagian bridging,
  bukan data baru milik lokasi fisik.

**Perubahan yang diperlukan**

- Tambahkan cangkang visual `Dimensi keuangan` pada form tambah dan ubah Lokasi
  Aset, dengan struktur yang mengikuti pola D365.
- Cangkang menampilkan status yang jujur, misalnya `Belum tersedia`, dan
  menjelaskan bahwa pengaturan menunggu Finance. Jangan tampilkan dropdown,
  lookup, nilai Department palsu, tanda required, atau input ULID baru.
- Cangkang tidak boleh mengubah payload simpan lokasi, migration, model, atau
  endpoint. Form lokasi harus tetap dapat disimpan tanpa konfigurasi Finance.
- Pertahankan lokasi sebagai struktur fisik tenant, misalnya site, gedung,
  lantai, ruangan, area, atau rak. Jangan menjadikan setiap lokasi otomatis
  sebagai departemen.
- Simpan snapshot unit organisasi/dimensi pada fakta transaksi aset ketika
  diperlukan untuk histori. Jelaskan fallback ke unit penggunaan bila mapping
  lokasi tidak diisi; fallback ini bukan pengganti otorisasi organisasi.
- Jangan membuat data departemen, legal entity, akun, atau dimensi keuangan
  nyata dari seed Management Aset.

**Acceptance criteria**

- Requirement dan mockup memiliki satu cangkang `Dimensi keuangan` yang
  konsisten pada form tambah dan ubah lokasi.
- Tahap cangkang tidak mengirim data financial dimension dan tidak membuat
  data Department, operating unit, atau akun Finance.
- Tidak ada field required baru dan tidak ada nilai contoh yang terlihat seperti
  data Finance nyata.
- Implementasi lookup/pewarisan baru dikerjakan setelah Finance/Core contract
  tersedia; saat itu route dan payload wajib ditulis di kontrak OpenAPI yang
  tepat, tanpa query database lintas module.
- Pada implementasi final, tidak ada lagi field ULID mentah pada UI end user.

**Bukti implementasi**

- Form generik `D:\Kerja\app-erp-management-aset\ui\src\master\MasterForm.tsx`
  menampilkan satu cangkang `Dimensi keuangan` untuk resource `lokasi-aset`; pola
  yang sama otomatis dipakai saat tambah dan ubah.
- Cangkang hanya menampilkan status `Belum tersedia` dan penjelasan bahwa
  pengaturan menunggu Finance. Ia tidak menambah state form, field required,
  pilihan, atau nilai contoh.
- Payload simpan tetap hanya dibentuk dari field master, parent, dan extra field
  yang sudah ada; tidak ada `financial dimension`, `Department`, atau `org_unit_id`
  baru yang dikirim dari UI. Tidak ada migration, model, endpoint, atau kontrak
  yang diubah untuk cangkang ini.
- `npm.cmd run build` pada UI lulus. Recreate runtime melalui
  `D:\Kerja\erp-dev\start.ps1 -Build -Apps management-aset` lulus dan seluruh
  container terkait berstatus sehat.
- Verifikasi mata melalui Shell belum dapat dibuka pada sesi ini karena akun
  provider lokal tidak memiliki membership tenant; UI artifact langsung merespons
  `Menyiapkan akses aplikasi…`, sehingga ini adalah keterbatasan handshake runtime,
  bukan error build atau payload.

### [X ] MAS-IND-02 (P0) - Hapus hardcode klasifikasi fiskal dari aplikasi

**Bukti hardcode saat ini**

- Model API `GroupAset` mendefinisikan `TIPE_HARTA` berisi Kelompok 1-4,
  bangunan, dan bukan objek penyusutan.
- UI `masters.ts` mengulang daftar yang sama sebagai opsi statis.
- Controller memvalidasi nilai memakai `Rule::in(GroupAset::TIPE_HARTA)`.

Lokasi kode saat ini berada di repo aplikasi Management Aset:

- `D:\Kerja\app-erp-management-aset\api\app\Models\master\GroupAset.php`
- `D:\Kerja\app-erp-management-aset\ui\src\master\masters.ts`
- `D:\Kerja\app-erp-management-aset\api\app\Http\Controllers\master\GroupAsetController.php`

**Keputusan desain**

- Buat reference data fiskal berversi, misalnya `m_kelompok_harta_fiskal`,
  dengan minimal `template_key`, yurisdiksi, label, referensi regulasi,
  tanggal berlaku, umur manfaat, tarif garis lurus, tarif saldo menurun,
  boleh saldo menurun, dapat disusutkan, dan status aktif.
- `m_group_aset` merujuk ke klasifikasi fiskal tersebut. `Group Aset` tetap
  menjadi master bisnis tenant, sedangkan `Kelompok Harta Fiskal` menjadi
  referensi regulasi.
- Nilai `kelompok_1` dan sejenisnya tidak lagi menjadi daftar opsi di React
  atau enum business rule di controller. Enum boleh tetap dipakai untuk
  metode teknis yang benar-benar stabil, bukan untuk daftar regulasi.
- `template_key` yang stabil untuk idempotensi seed bukan hardcode business
  logic. Yang harus dihilangkan adalah daftar label/tarif/aturan yang tersebar
  di source code.
- Jika regulasi berubah, buat versi/effective date baru. Jangan mengubah baris
  regulasi yang sudah dipakai aset lama tanpa strategi histori/migrasi.

**Acceptance criteria**

- Semua opsi klasifikasi fiskal dibaca dari API/database.
- Penambahan versi aturan pajak dapat dilakukan lewat seed/migration/config,
  tanpa merilis ulang UI hanya untuk menambah opsi.
- Aset yang sudah aktif tetap dapat ditelusuri ke versi aturan yang dipakai.
- Label Indonesia, tarif, dan masa manfaat tidak muncul sebagai konstanta
  duplikat di PHP dan TypeScript.

### [x] MAS-IND-03 (P1) - Seed starter Indonesia untuk semua tenant

**Cara provisioning**

- Seed harus idempotent dan dijalankan pada lifecycle provisioning tenant baru
  maupun backfill tenant lama.
- Jangan mengandalkan `DatabaseSeeder` aplikasi yang kosong untuk membuat data
  lintas tenant. Provisioning harus mengikuti alur Core/Control Plane yang
  tepercaya, melalui API atau event sesuai kontrak.
- Gunakan `creation_key`/template key yang stabil untuk menemukan data yang
  sudah dibuat. Jangan memalsukan kode bisnis atau menyalin nama perusahaan.
- Kode master yang memang memiliki nomor dibagikan melalui Core Number
  Sequence. Reference regulasi dapat memakai `template_key` stabil dan tidak
  perlu nomor urut baru tanpa keputusan desain.

**Reference fiskal yang disiapkan**

- Kelompok 1.
- Kelompok 2.
- Kelompok 3.
- Kelompok 4.
- Bangunan permanen.
- Bangunan tidak permanen.
- Tanah/bukan objek penyusutan sesuai kebutuhan domain yang disepakati.

Nilai tarif dan masa manfaat harus memiliki sumber regulasi dan tanggal
berlaku. Untuk default Indonesia saat ini, sumber yang menjadi acuan kerja
adalah PMK 72 Tahun 2023/DJP; jangan menyimpan angka tanpa metadata sumber.

**Profil starter**

- Garis lurus untuk Kelompok 1-4.
- Saldo menurun untuk Kelompok 1-4 bila diperlukan oleh buku fiskal.
- Garis lurus untuk bangunan permanen dan tidak permanen.
- Frekuensi bulanan mengikuti kebutuhan aplikasi saat ini.
- Jangan membuat `SL_REMAIN` sebagai aturan pajak default. Pola pergantian
  saldo menurun ke garis lurus di D365 adalah kemampuan konfigurasi, bukan
  otomatis berarti aturan pajak Indonesia.
- Jangan men-seed profil `consumption` atau jadwal manual sebagai default
  Indonesia karena keduanya membutuhkan kebijakan dan data aset spesifik.

**Buku starter**

- Buku komersial.
- Buku fiskal/pajak.
- Mapping group x book harus memilih profil efektif sebelum aset dapat mulai
  dihitung penyusutannya.
- `Ekspor ke Finance/backoffice` tetap nonaktif atau tidak tersedia sampai
  Finance dan posting profile benar-benar siap. Jangan membuat akun GL fiktif.

**Reference UI opsional**

- Tipe lokasi generik: site, gedung, lantai, ruangan, area, rak.
- Kondisi aset generik: baru, baik, perlu perhatian, rusak, tidak digunakan.

Tipe dan kondisi tersebut boleh menjadi starter reference, tetapi lokasi nyata,
departemen, pabrikan, model, dan kelompok aset bisnis tidak boleh dipaksakan
sebagai data perusahaan tertentu.

**Acceptance criteria**

- Seed aman dijalankan dua kali tanpa duplikasi.
- Tenant baru dan tenant lama menerima reference Indonesia yang sama menurut
  versi template yang dipilih.
- Seed satu tenant tidak dapat membaca atau menulis tenant lain.
- Tidak ada nomor akun, COA, posting profile, legal entity, atau departemen
  nyata yang dibuat oleh seed ini.

**Bukti implementasi**

- Konfigurasi berversi berada di `api/config/management_aset.php`. Event
  `core.tenant.provisioned.v1` dari Core diterima melalui endpoint bertanda
  tangan dan menjalankan seed hanya bila `management-aset` tercantum pada
  entitlement tenant. Ini mencegah seed parsial pada tenant yang belum memakai
  app; saat entitlement baru ditambahkan, backfill membuat event dengan daftar
  app terbaru.
- Reference fiskal, profil, buku, tipe lokasi, dan kondisi memakai key stabil.
  Kode master diterbitkan lewat Core Number Sequence; buku starter tidak
  mengekspor ke Finance/backoffice dan tidak membuat akun GL.
- Backfill tersedia melalui `php artisan tenant-provisioning:backfill` di Core
  dan publisher event memakai outbox + signature yang sudah ada.
- Test app: 113 test lulus; test provisioning khusus mencakup retry tanpa
  duplikasi, signature, dan skip tenant untuk app lain. Test Control Plane
  provisioning/backfill/publisher terkait lulus. Runtime container berhasil
  menjalankan migration, route provisioning, backfill, dan publish; tenant
  yang entitled menerima 7 reference fiskal, 10 profil, 2 buku, 6 tipe lokasi,
  dan 5 kondisi tanpa nomor baru pada retry.

### [x] MAS-IND-04 (P1) - Selaraskan buku, profil, dan matriks group x book

**Perubahan yang diperlukan**

- Jadikan matriks group x book sebagai sumber konfigurasi efektif. Field profil
  pada header buku boleh kosong hanya jika ada profil efektif di matriks.
- Sebelum aset diaktifkan atau mulai disusutkan, sistem harus menemukan buku
  dan profil efektif. Jangan diam-diam membuat aset tanpa depreciation book
  ketika konfigurasi belum lengkap.
- Validasi masa manfaat, metode, frekuensi, konvensi, dan tanggal berlaku pada
  kombinasi group x book.
- Tinjau dan pensiunkan jalur legacy `default_depreciation_profile_id` dan
  `default_book_code` di group aset bila sudah tidak menjadi sumber kanonik.
- Profile/buku yang sudah digunakan aset tidak boleh diubah sembarangan.
  Gunakan versi baru atau effective date baru untuk perubahan regulasi.
- Samakan bahasa UI dengan konsep D365: profil utama, profil pengganti
  (opsional), posting layer, hitung penyusutan, dan ekspor ke Finance.

**Acceptance criteria**

- Kombinasi group/book tanpa profil efektif menghasilkan validasi yang jelas.
- Aset baru tidak masuk status siap tanpa buku/profil yang dapat dihitung.
- Perubahan profil baru tidak mengubah histori perhitungan aset lama.
- Field opsional tidak diberi tanda `*`; field required memiliki validasi merah
  yang konsisten dan hanya satu tanda `*`.

**Bukti implementasi**

- Matriks group x book sekarang divalidasi sebagai sumber konfigurasi efektif: buku
  dan profil harus aktif, profil utama boleh berasal dari baris matriks atau header
  buku, dan masa manfaat, metode, frekuensi, konvensi, serta rentang tanggal profil
  diperiksa sebelum matriks disimpan.
- Profil penyusutan memiliki `effective_from`/`effective_to`. Profil yang sudah
  tersalin ke buku aset tidak dapat mengubah aturan perhitungannya; perubahan harus
  memakai profil baru. Buku yang sudah dipakai aset juga tidak dapat mengubah aturan
  perhitungannya.
- Penerimaan aset tidak lagi memakai `default_depreciation_profile_id`,
  `default_book_code`, profil cadangan dari form, atau kode `PRIMARY`. Aset tanpa
  matriks masih dapat tercatat sebagai `received`, tetapi penempatan ditolak sampai
  buku dan profil efektif tersedia. Aturan matriks tetap disalin ke `tr_buku_aset`
  sebagai snapshot.
- UI memakai istilah `Profil utama`, `Profil pengganti (opsional)`, `Lapisan posting`,
  `Hitung penyusutan`, dan `Ekspor ke Finance`; field tanggal berlaku tersedia pada
  form profil. Tidak ada lagi input profil/kode buku cadangan pada penerimaan aset.
- Kontrak OpenAPI sumber dan bundle memuat tanggal berlaku, metode garis lurus sisa
  umur, serta arti baru matriks/header buku. Tidak ada route baru pada task ini.
- Test aplikasi lulus: **120 test, 6.970 assertion**. TypeScript dan production
  build UI lulus. Runtime container sehat; migration
  `2026_08_12_110000_add_effective_dates_to_depreciation_profiles` berstatus `Ran`
  dan kedua kolom tanggal berlaku terdeteksi di database runtime.
- Verifikasi visual melalui sesi browser lokal berhenti pada layar login/akses aplikasi
  karena tidak ada kredensial tenant yang valid pada sesi uji; tidak dibuat tenant atau
  user baru hanya untuk mengatasi blocker tersebut. Build UI dan health check container
  tetap sudah diverifikasi.

### [x] MAS-IND-05 (P1) - Tegaskan batas buku komersial dan fiskal

- Buku fiskal memakai default regulasi Indonesia yang berversi.
- Buku komersial tidak diberi umur manfaat universal. Tenant/legal entity
  menentukan umur manfaat, metode, nilai residu, dan review sesuai kebijakan
  akuntansi dan pola konsumsi manfaat aset.
- Sediakan konfigurasi khusus bila sektor/usaha membutuhkan pengecualian;
  pengecualian tidak boleh ditanam sebagai default global.
- Tampilkan bantuan singkat bahwa buku fiskal dan buku komersial dapat memiliki
  metode/umur manfaat berbeda.
- Hubungkan hasil perhitungan ke Finance hanya setelah kontrak posting dan
  ownership COA tersedia.

**Bukti implementasi**

- Template starter Indonesia memakai key versi `id:pmk72-2023:starter:v1`.
  Buku fiskal memakai lapisan `tax` dan mapping group x book memilih profil
  fiskal yang memiliki tanggal berlaku; buku komersial memakai lapisan `current`
  tanpa profil atau umur manfaat global.
- Profil komersial, umur manfaat, metode, nilai residu, dan pengecualian sektor
  tetap dikonfigurasi per tenant/legal entity melalui profil dan matriks group x
  book. Seeder global tidak mengisi aturan komersial.
- UI Buku penyusutan dan matriks menampilkan bantuan bahwa buku komersial dan
  fiskal dapat memakai metode, masa manfaat, dan tanggal berlaku berbeda.
- `export_to_backoffice` sekarang opt-in dan default database/API-nya `false`.
  `tr_export_penyusutan` tetap hanya bridge internal tanpa debit, kredit, akun,
  COA, atau posting ke Finance; koneksi lintas app menunggu kontrak posting dan
  ownership Finance.
- Kontrak OpenAPI sumber dan bundle menjelaskan bridge internal tanpa posting GL
  serta default ekspor yang mati. Migration hanya mengubah default untuk data
  baru; nilai eksplisit pada buku aktif tidak ditimpa.

### [~] MAS-IND-06 (P1) - Audit number sequence dan materialisasi seed

Reference nomor yang sudah tercatat di manifest aplikasi harus tetap menjadi
sumber kebenaran, termasuk yang relevan untuk group aset, buku, profil, dan
lokasi. Periksa minimal:

- Tidak ada kode `GRPA`, `BKPY`, `DPRE`, `LOCA`, atau kode master lain yang
  ditulis langsung sebagai hasil seed.
- `creation_key` dipakai untuk idempotensi; kode yang tampil ke user diterbitkan
  melalui Control Plane Number Sequence.
- Scope tenant/legal entity mengikuti jenis resource. Reference regulasi tidak
  otomatis mendapat nomor transaksi.
- Tenant baru memperoleh konfigurasi nomor sebelum seed master berjalan.
- UI preview nomor dan konfigurasi sequence menunjukkan status materialisasi
  yang nyata, bukan sekadar entitlement aplikasi.

**Bukti audit dan perbaikan**

- `app.yaml` Management Aset mendeklarasikan 19 reference: master tenant
  memakai scope `tenant`, sedangkan aset dan dokumen siklus memakai scope
  `legal_entity`. Profil, buku, lokasi, checklist, dan master lain tidak lagi
  memakai kode hasil seed; `ProvisionIndonesiaStarterData` menerbitkan kode
  lewat `NumberSequenceClient` dengan `creation_key` yang stabil.
- `EnsureNumberSequenceDrafts` sekarang memilih scope default dari
  `allowed_scopes`: `tenant` bila tersedia, lalu `legal_entity` atau
  `operating_unit` bila itu satu-satunya scope. Materialisasi tetap memakai
  profile non-continuous, rentang `0`–`19999`, preallocation 20, dan prefix
  manifest.
- Migration repair hanya memperbaiki row lama yang scope-nya bertentangan
  dengan manifest dan belum memiliki state penomoran apa pun. Row yang sudah
  memiliki counter, allocation, reservation, nomor, atau audit event dilewati;
  migration tidak menghapus sequence dan `down` sengaja tidak membalikkan
  scope yang sudah diperbaiki.
- Endpoint internal `issue` dan `reserve` memastikan materialisasi untuk
  tenant/app yang sudah `ready` sebelum menerbitkan nomor. Ini menutup race
  ketika event provisioning diterima sebelum halaman pengaturan sequence
  dibuka. Materialisasi tetap dimiliki Control Plane; app tidak membuat
  counter sendiri.
- Test Control Plane `NumberSequenceTest` membuktikan scope tenant,
  fallback `legal_entity`, idempotensi materialisasi, dan issue pertama dari
  jalur internal ketika row sequence belum ada, serta repair aman untuk row
  belum terpakai. Hasil terakhir: 33 test, 86 assertion, lulus.
- Audit runtime dev menemukan 21 reference katalog: 19 masih ada di manifest
  saat ini dan 2 (`entitas-aset`, `kategori-aset`) adalah reference historis.
  `kategori-aset` sudah pernah menerbitkan nomor, jadi tidak dihapus atau
  diubah otomatis. Row sequence legal entity yang sudah terlanjur dibuat oleh
  logika lama juga tidak ditulis ulang karena perubahan scope setelah nomor
  digunakan tidak aman. Khusus `perencanaan-aset` sudah memiliki 5 nomor,
  1 counter, 1 allocation, dan 6 audit event; sequence itu perlu keputusan
  repair/deprecation tersendiri sebelum data dev dijadikan basis pilot/produksi.
  Migration repair sudah memperbaiki enam reference legal entity yang belum
  punya state penomoran tanpa menyentuh nomor aktif. Catalog runtime masih
  menyimpan dua reference historis sehingga berisi 21 row, sedangkan manifest
  Management Aset saat ini berisi 19 reference; keduanya dipertahankan karena
  salah satunya sudah terpakai.

Task belum ditandai selesai karena data runtime lama tersebut masih perlu
keputusan repair/deprecation yang dapat diaudit.

### [ ] MAS-IND-07 (P1) - Rapikan UI, bantuan, dan validasi

- Ubah `ID unit organisasi` menjadi lookup `Departemen / Unit organisasi
  (opsional)` dan jelaskan dampaknya dalam help text yang ringkas.
- Gunakan label end user yang menjelaskan tindakan, bukan nama tabel atau ID
  internal.
- Bedakan empty state “belum ada data” dari error saat lookup gagal.
- Pastikan semua dropdown/combobox di dalam Sheet atau dialog memakai
  `portalContainer` overlay agar menu dapat dipilih.
- Hilangkan opsi hardcode di UI dan muat dari reference API.
- Audit seluruh label required: satu asterisk saja; optional tidak memakai
  asterisk; validasi server tetap menjadi sumber kebenaran.
- Untuk field profil pengganti, jelaskan bahwa field hanya dipakai bila aturan
  bisnis membutuhkan pergantian metode/hasil saldo menurun.

### [ ] MAS-IND-08 (P1) - Kontrak, test, dan verifikasi runtime

**Kontrak dan batas modul**

- Jika Management Aset mengambil unit organisasi dari Core, tambahkan route dan
  schema ke kontrak OpenAPI lintas app yang ditulis tangan.
- Jangan membuat query database lintas module.
- Tetapkan ownership: Core untuk organisasi/tenant/number sequence; Management
  Aset untuk lokasi fisik, group aset, buku/profil, dan histori penyusutan;
  Finance untuk COA, posting profile, dan jurnal.

**Test minimum**

- Seed tenant idempotent dan backfill tenant lama.
- Penolakan reference fiskal atau unit organisasi lintas tenant.
- Lookup unit organisasi hanya menampilkan data yang boleh dilihat user.
- Validasi effective profile pada group x book.
- Aset tidak dapat mulai disusutkan tanpa buku/profil efektif.
- Versi aturan yang sudah dipakai tidak dapat diubah merusak histori.
- UI required/optional asterisk dan dropdown di overlay.

**Verifikasi**

- Jalankan migration/seed dari container runtime `core-app`, lalu cek data dari
  database runtime dan reload layar terkait. Hasil command host saja tidak cukup.
- Setelah perubahan API/UI, jalankan contract coverage, test scope terkait,
  rebuild/recreate container sesuai aturan workspace, dan verifikasi layar
  melalui `http://localhost:8000`.
- Setelah perubahan kode, perbarui graph knowledge sesuai aturan repo.
- Sebelum modul dinyatakan production-ready, ikuti load gate CoreERP: 1000+
  VU, 100+ tenant, 2+ instance API, database asli, dan pemeriksaan langsung
  terhadap database untuk tenant isolation, nomor ganda, privilege escalation,
  serta error 5xx.

### [ ] MAS-IND-09 (P1) - Catat dependency Finance/backoffice

- Tautkan implementasi dengan temuan `FIN-23`, `FIN-24`, dan `FIN-25` pada
  `docs/todo/general/05-fondasi-finansial.md`.
- Jangan menambahkan fixed asset posting profile, akun utama, offset account,
  atau kombinasi financial dimension sebelum app Finance/GL dan kontraknya siap.
- Placeholder `Parameter Aset Tetap` dan `Profil Posting Aset` harus diberi
  status yang jujur, misalnya “Belum tersedia”, bukan seolah-olah konfigurasi
  D365 sudah lengkap.
- Rancang export penyusutan sebagai payload ke Finance/backoffice. Ownership
  jurnal dan posting tetap berada di Finance.

## Seed yang tidak boleh dibuat sekarang

- Nama perusahaan, nama departemen, lokasi kantor, pabrikan, model, PIC, atau
  akun yang spesifik client.
- COA, main account, offset account, posting profile, journal, atau financial
  dimension value nyata.
- Nilai komersial universal yang menganggap semua aset Indonesia memiliki umur
  manfaat sama.
- Kode bisnis yang kebetulan terlihat seperti nomor sequence tetapi tidak
  diterbitkan Control Plane.

## Urutan pengerjaan yang disarankan

1. Putuskan kontrak lookup Core Organization dan ownership data.
2. Buat schema/reference fiskal berversi dan migration tanpa merusak data lama.
3. Implementasikan provisioning seed Indonesia yang idempotent untuk semua
   tenant.
4. Migrasikan Group Aset dari string hardcode ke reference fiskal.
5. Rapikan buku/profil dan jadikan matriks group x book sebagai sumber efektif.
6. Perbaiki UI lokasi, dropdown, help text, dan tanda required.
7. Tambahkan contract test, tenant-isolation test, runtime verification, dan
   dokumentasikan gap Finance.

### [ ] MAS-IND-10 (P1) - Catat dependensi Workforce/HR untuk kompetensi maintenance

Sejajar dengan MAS-IND-09 yang mencatat dependensi Finance. Yang menunggu di sini adalah
kompetensi pekerja, bukan akun.

**Batas yang berlaku**

- Skill dan sertifikat adalah kompetensi milik Human Resources yang dipasang pada pekerja.
  Di Dynamics 365 keduanya diset pada `Human resources > Workers > Workers > Competencies`;
  maintenance job trade dan job type hanya menyimpan persyaratan yang **merujuk** kompetensi
  itu.
- Gunanya satu: penjadwalan hanya boleh menugaskan pekerja yang skill dan sertifikatnya
  cocok. Tanpa penjadwalan berbasis kompetensi, persyaratan tidak punya pembaca.
- Menyimpan skill sebagai teks bebas di database aset melanggar batas modul dan tidak akan
  pernah cocok dengan kompetensi pekerja, karena keduanya string yang tidak berhubungan.

**Yang sudah dilakukan**

- `m_maintenance_job_type_requirement` dihapus selagi masih kosong; lihat migration
  `2026_08_15_140000_drop_maintenance_job_type_requirement` beserta alasannya.
- `m_trade` sengaja dibiarkan datar tanpa sub-tabel skill dan sertifikat. Ini bukan
  kekurangan yang belum dikerjakan, melainkan bentuk yang benar selama HR belum ada.

**Yang menunggu kontrak Workforce Core**

- Persyaratan kompetensi pada trade dan job type, sebagai referensi opaque ke kompetensi
  Core.
- Penjadwalan berbasis kompetensi pada baris pekerjaan work order.
- Lookup pelaksana: `ditugaskan_ke_user_id` pada `tr_pemeliharaan_aset_details` masih string
  opaque yang diketik, bukan dipilih dari daftar pekerja.

**Acceptance criteria**

- Tidak ada tabel di modul aset yang menyimpan nama skill atau sertifikat sebagai teks.
- Ketika kontrak tersedia, persyaratan dibangun sebagai referensi dan diuji menolak
  kompetensi lintas tenant.

## Referensi

- CoreERP: [`docs/dev/README.md`](../../../dev/README.md)
- D365 F&O - operating units:
  <https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/tasks/create-operating-unit>
- D365 F&O - fixed asset setup:
  <https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/set-up-fixed-assets>
- D365 F&O - depreciation profiles:
  <https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/tasks/set-up-depreciation-profiles>
- D365 F&O - books/value models:
  <https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/tasks/set-up-value-models>
- D365 F&O - fixed asset posting profiles:
  <https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/tasks/set-up-fixed-asset-posting-profiles>
- D365 F&O - functional locations:
  <https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/overview/functional-locations-and-objects>
- DJP - penyusutan dan amortisasi:
  <https://pajak.go.id/penyusutan-dan-amortisasi>
- JDIH Kementerian Keuangan - PMK 72 Tahun 2023:
  <https://www.jdih.kemenkeu.go.id/dok/pmk-72-tahun-2023/summary>
- Repo Management Aset: `D:\Kerja\app-erp-management-aset`
