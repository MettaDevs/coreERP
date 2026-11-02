# Profil kelompok layanan Faskes

Desain ini berlaku untuk Faskes pemerintah, swasta, yayasan, maupun bentuk pengelola lain. Jenis pengelola tidak menjadi syarat tenant, nama tabel, atau penentu otomatis sharing dan proses bisnis.

Status adalah target PRD, belum implementasi. [Pemeriksaan database legacy](DATABASE-PATTERN-EVIDENCE.md) merupakan salah satu contoh, bukan definisi seluruh produk. Master organisasi tetap pada Organization Foundation di `core_control_plane`; pasien, katalog, dan ledger tetap di aplikasi pemilik data.

## Gunakan konsep dan identifier yang netral

| Konsep                                      | Istilah domain yang dipakai                                                                   |
| ------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Pengguna layanan platform                   | Tenant dan organisasi pengelola.                                                              |
| Unit yang memberikan layanan                | Facility/organization dan ekstensi profile healthcare.                                        |
| Unit yang memakai scope operasional bersama | Service group atau kelompok layanan.                                                          |
| Hubungan unit utama dan pendukung           | Assignment versi struktur layanan yang dipilih, bukan parent permanen baru pada organization. |
| Pengelola master referensi                  | Catalog authority atau pengelola katalog yang ditetapkan.                                     |
| Aturan yang dijalankan                      | Policy, process profile, serta capability adapter yang berversi.                              |

Calon identifier menggunakan `organization_id`, `service_group_id`, `patient_registry_id`, `catalog_group_id`, `stock_owner_org_id`, `custodian_org_id`, dan `process_profile_version`. Nama schema final ditetapkan pada inventory/desain migration; tidak diturunkan dari sektor pemilik atau nama customer.

Metadata pengelola boleh disimpan bila diperlukan untuk pelaporan atau profile regulasi yang relevan. Metadata tersebut tidak memberi permission, tidak menentukan sharing otomatis, dan tidak mewajibkan suatu bentuk badan usaha. Kewenangan tetap mengikuti izin, capability, grant, serta profile layanan yang diverifikasi.

## Pilih profile sesuai proses, bukan sektor

| Profile yang direncanakan        | Perilaku                                                                                                   |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `single_facility`                | Satu fasilitas dapat bekerja tanpa parent, unit pendukung, atau hierarchy kompleks.                        |
| `service_network`                | Beberapa kelompok layanan; tiap kelompok dapat mempunyai unit utama dan pendukung.                         |
| `isolated_groups_shared_catalog` | Registry pasien antar-kelompok terpisah, obat/jasa bersama sesuai anggota katalog, policy lain independen. |
| Profile turunan tervalidasi      | Customer memilih override yang didukung; kode sumber tidak difork per customer.                            |

## Buat profile sebagai data, bukan percabangan kode

Nama profile pada tabel adalah contoh template awal, bukan enum tertutup. Admin yang berwenang dapat membuat profile dengan nama sendiri, dari kosong atau salinan template, lalu menggabungkan mode sharing, binding source stok/custody, grant, dan process definition yang telah didukung.

Definition, konfigurasi dan version profile disimpan sebagai data tenant-scoped pada `core_control_plane`. Record memerlukan ID stabil, nama/slug, tenant atau scope template platform, revision, status, parameter tervalidasi dan referensi capability/process version. Detail tabel dikunci pada desain schema; jangan membuat class atau cabang kode untuk setiap nama profile customer.

Lifecycle berupa draft, validasi, publish, lalu aktivasi setelah acknowledgement target yang kompatibel. Definition yang published tidak diedit in-place. Instance berjalan memakai versi yang dipin; perubahan scope berisi data tetap mengikuti migration gate. Profile tidak boleh memperluas permission atau mengeksekusi kode bebas.

Kemampuan bisnis baru tetap memerlukan pengembangan action/adapter sekali. Setelah capability tersedia dan diuji, admin dapat memakainya dalam profile tanpa fork source per customer. Flowable mengoordinasikan proses yang didukung; ia bukan pengganti enforcement data, stock ledger, atau validasi izin.

Satu kelompok layanan adalah binding operasional pada fondasi organisasi yang sama. Ia bukan master organisasi kedua. Satu unit mempunyai assignment aktif yang tidak ambigu untuk context pasien; authority dan mode domain lain ditetapkan eksplisit.

```text
Tenant organisasi pengelola
├── Pengelola katalog obat bersama jika dipilih
├── Pengelola katalog jasa bersama jika dipilih
├── Kelompok layanan A
│   ├── Unit utama A
│   ├── Unit pendukung A1
│   └── Unit pendukung A2
├── Kelompok layanan B
│   ├── Unit utama B
│   └── Unit pendukung B1
└── Fasilitas mandiri bila diperlukan
```

Jenis fasilitas adalah metadata healthcare yang berbeda dari klasifikasi legal entity/operating unit. Tidak disimpulkan dari teks nama atau prefix kode. Assignment ditinjau terhadap versi struktur layanan dan scope, bukan konstanta UnitBisnisID tertentu.

## Konfigurasikan domain secara independen

| Domain                         | Profile kelompok terisolasi dengan katalog bersama                              | Batas validasi                                                                                     |
| ------------------------------ | ------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Master pasien/registry         | Terpisah antar-kelompok; unit pendukung memakai registry kelompok bila dipilih. | Semua query, enrichment, nomor, export dan file mematuhi scope yang sama.                          |
| Pelayanan/rekam medis/episode  | Dataset kelompok dapat digunakan bersama sesuai policy.                         | Grant dan provenance unit asal tetap berlaku; hierarchy bukan izin baca otomatis.                  |
| Master obat/satuan             | Katalog bersama atau terpisah menurut keanggotaan group.                        | Tidak otomatis menyamakan saldo, kepemilikan, harga, atau hak issue.                               |
| Definisi jasa                  | Katalog bersama atau terpisah sesuai profile.                                   | Tarif, biaya, akun dan section availability tidak ikut ditimpa tanpa policy field yang sesuai.     |
| Pricing/formulary/availability | Ikuti profile kelompok atau override yang diizinkan.                            | Versi/effective date dan pemilik field tetap jelas.                                                |
| Stok                           | Sumber kelompok, lokasi sendiri, atau custody sesuai profile terverifikasi.     | Owner, custodian, ledger, lokasi/lot dan unit peminta tidak disamakan.                             |
| Routing layanan                | Registrasi, poli, lab, farmasi dan kasir memakai process profile.               | Hanya action/binding yang adapter telah dukung; tidak menjalankan kode arbitrary dari konfigurasi. |

Pewarisan scope kelompok tidak memberi semua pegawai semua hak dan tidak membuat satu saldo virtual. Nomor dokumen serta identitas unit tetap dipertahankan sesuai konteks domain. Kode provider, credential, dan tujuan integrasi tidak disalin otomatis; pemakaian provider bersama harus melalui binding yang disetujui. Sandbox menonaktifkan outbound production.

Profile dimaterialisasi sebagai policy version yang dapat direview. Perubahan hierarchy dapat menghasilkan draft assignment, tetapi tidak langsung memindahkan pasien, memberi akses, atau mengubah authority stok. Episode berjalan merekam organisasi asal, kelompok, policy/process version, authority, dan correlation/idempotency ID; grant baca tetap yang berlaku sekarang.

## Pilih placement sesuai kemampuan

Profile kelompok terisolasi tahap awal dapat memakai satu environment/database per kelompok layanan dengan seluruh unit pendukungnya. Fasilitas pendukung tidak otomatis menambah database. Ini pilihan deployment yang cocok dengan legacy, bukan kewajiban sektor tertentu atau satu database per unit fisik.

Konsolidasi beberapa registry kelompok pada satu database ditolak sampai seluruh scope query/procedure/report/file lulus. Jika kelompok sengaja berada di beberapa environment, authority dan adapter koneksinya harus benar-benar mendukungnya.

Distribusi katalog referensi lintas environment adalah capability SH-04. Sharing pasien atau ledger lintas environment adalah capability lain dan tidak ikut aktif. Profile jaringan hanya ditawarkan jika seluruh capability yang dijanjikan sudah diuji.

## Gunakan satu authority katalog per key dan field

Foundation menyimpan group, authority reference, revision dan anggota; isi katalog tetap milik aplikasi/catalog store yang terdaftar. Authority obat dan jasa boleh berbeda jika proses memerlukannya. Binding produk/environment atau endpoint harus nyata dan tervalidasi sebelum aktif.

Database unit menyimpan projection/mapping lokal, bukan writer canonical kedua. Tetapkan field canonical dan allowed local overrides. Katalog jasa memisahkan definisi dari pricing/komponen biaya/section/akun lokal. Kesamaan integer ID sumber tidak cukup untuk menggabungkan record.

Release dan dependency obat/satuan atau jasa/komponen harus lengkap sebelum diaktifkan. Gunakan acknowledgement, receipt, retry idempotent dan status pending/failure. Snapshot approved tetap jelas saat authority sementara tidak tersedia. Rekonsiliasi konflik data legacy dilakukan oleh pengelola katalog yang berwenang, bukan blind overwrite.

## Pindahkan anggota dan ubah proses secara terkontrol

Pemindahan unit pendukung antar-kelompok dapat menyentuh registry, episode, grant, section/lokasi, katalog, stock pool dan proses berjalan. Lakukan preview dan migration operation untuk perubahan berisi data; update parent saja tidak cukup.

Histori mempertahankan unit/kelompok asal. Putuskan akses historis dan cakupan pemindahan tanpa auto merge pasien. Episode, resep, billing dan reservasi terbuka diselesaikan menurut authority/revision yang sah. Retur dan refund memakai dokumen/owner/alokasi asal.

Effective date, fencing, backup dan rekonsiliasi dipakai sesuai dampak. Template baru berlaku bagi instance baru atau migration instance yang disetujui. Tidak menjanjikan semua kebutuhan baru tanpa kode; kemampuan baru ditambahkan sekali sebagai action/capability bersama.

## Contoh pemerintah dan swasta

Contoh pemerintah adalah tenant Dinkes dengan beberapa Puskesmas dan Pustu. Pasien dipisah antar-kelompok Puskesmas; Pustu mengikuti scope induknya; master obat/jasa bersama bila profile dipilih. Pola Kuta/Mengwi merupakan bukti salah satu deployment tersebut. Nama database yang memuat istilah itu tetap dipertahankan sebagai fakta sumber, bukan nama tabel/konsep produk baru.

Contoh swasta adalah jaringan Klinik dengan Klinik pusat, cabang/unit pendukung, serta Apotek yang terpisah. Jaringan tersebut dapat memilih pemisahan registry per kelompok, katalog bersama, dan supply/custody sesuai profile yang sama. Tidak perlu membuat instansi pemerintah atau membeli ERP terlebih dahulu.

Fasilitas mandiri juga dapat memakai profile single_facility tanpa membangun jaringan buatan. Ketiga contoh memakai konsep tenant, organisasi, group, policy dan authority yang sama. Perbedaan izin/kategori layanan tetap divalidasi menurut keadaan fasilitas masing-masing.

## Acceptance profil kelompok layanan

| ID    | Skenario                                                           | Hasil wajib                                                                                                               |
| ----- | ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------- |
| SG-01 | Tenant mempunyai banyak kelompok dan fasilitas mandiri.            | Jumlah/kedalaman tidak dipaku dan assignment aktif tidak ambigu.                                                          |
| SG-02 | Kelompok A dan B masing-masing mempunyai unit pendukung.           | Registry A dipakai anggota A sesuai grant; B dan unit pendukung B tidak otomatis mempunyai akses.                         |
| SG-03 | Referensi obat/satuan dari katalog bersama diperbarui.             | Release konsisten tanpa menyalin pasien atau menyamakan saldo.                                                            |
| SG-04 | Definisi jasa bersama, tarif/section berbeda.                      | ID canonical konsisten; atribut lokal yang tidak dibagi tidak ditimpa.                                                    |
| SG-05 | Unit pendukung baru ditempatkan pada kelompok A.                   | Draft mewarisi policy yang dipilih, bukan semua data tenant; aktivasi menunggu binding/grant/capability.                  |
| SG-06 | Unit berpindah kelompok setelah mempunyai data dan stok.           | Preview/migration wajib; tidak auto merge pasien atau mengalihkan reversal ke ledger baru.                                |
| SG-07 | ID lokal sama tetapi definisi master sumber berbeda.               | Rekonsiliasi wajib; tidak dinyatakan sinkron hanya dari ID.                                                               |
| SG-08 | Authority katalog mati atau penerima gagal di tengah release.      | Snapshot approved tetap jelas; retry tidak ganda dan release parsial tidak aktif.                                         |
| SG-09 | User unit pendukung mempunyai hak terbatas.                        | Shared dataset tidak memberinya hak admin atau hak semua anggota.                                                         |
| SG-10 | Beberapa registry diminta berada pada satu database.               | Capability scope diuji dahulu; deployment terisolasi tetap pilihan.                                                       |
| SG-11 | Process profile berubah ketika episode berjalan.                   | Versi/authority instance lama tetap sah atau dimigrasikan eksplisit.                                                      |
| SG-12 | Sandbox atau restore memuat profile jaringan.                      | Tidak mengubah assignment produksi, mengirim data ke produksi, atau menghidupkan grant lama.                              |
| SG-13 | Pengelola pemerintah dan swasta memilih struktur/policy ekuivalen. | Konsep/fitur sama; sektor tidak menjadi syarat akses atau sharing. Profile izin layanan tetap diperiksa.                  |
| SG-14 | Customer mandiri atau swasta tanpa struktur pemerintah.            | Dapat membuat organisasi/fasilitas dan memakai capability tanpa instansi pemerintah, parent fiktif, atau entitlement ERP. |

ID SG-01 sampai SG-12 menggantikan label skenario sektoral terdahulu dengan nomor yang bersesuaian. Ini perubahan istilah, bukan penghapusan bukti atau acceptance yang belum selesai. SG-13/14 mengunci netralitas produk. Rilis profile memerlukan capability serta matriks yang relevan lulus, bukan hanya template tersimpan.

Jalankan `node scripts/check-healthcare-domain-neutrality.mjs` dari root repository untuk memeriksa istilah normatif, nama dokumen, label skenario, dan navigasi. Bukti database sumber dikecualikan secara eksplisit; contoh sektor berada pada bagian Contoh di atas. Review nama schema dan behavior pemerintah/swasta tetap wajib ketika kode fitur dibuat.
