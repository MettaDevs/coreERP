# PRD — engine analitik

Kebutuhan produk untuk [engine analitik](/todo/analitik/). Keputusan bernomor `KA-xx` ada di
halaman peta; halaman ini menjelaskan masalahnya, siapa pemakainya, apa yang harus bisa mereka
lakukan, dan ukuran yang dipakai untuk menyatakan sebuah fase selesai.

## 1. Ringkasan

CoreERP mendapat engine analitik di dalam Core:

1. **Module menyatakan dataset**: tabel mana yang boleh dianalisis, field apa yang ditawarkan,
   measure apa yang sah dijumlah, dan kolom mana yang menegakkan kebijakan data.
2. **Core menjalankan query** yang disusun pengguna tanpa koding — kelompokkan menurut, jumlahkan,
   saring, urutkan — dengan aman: hanya anggota dataset, selalu dalam batas tenant, hak, dan
   kebijakan data pengguna, baca-saja, dan berbatas waktu.
3. **Pengguna menyusun dasbor**: tile angka, grafik, tabel, slicer, dan drill sampai ke dokumen.
4. **Data dibuka ke luar secara terkendali**: publikasi yang dibaca Excel, Power BI, n8n, atau
   dipasang di situs pelanggan sebagai embed.

Engine ini bukan salinan Power BI. Yang ditiru adalah cara kerjanya untuk data produk kita sendiri:
model semantik, query tanpa SQL, visual, berbagi, dan embed. Yang sengaja tidak ditiru ada di
bagian 4.

## 2. Masalah

**Permintaan dasbor datang dari hampir setiap pelanggan dan isinya berbeda-beda.** Di sistem lama,
setiap dasbor ditulis tangan untuk satu pelanggan, di salinan source milik pelanggan itu. Setiap
perubahan menunggu programmer, dan setiap dasbor ikut menua bersama salinannya.

**CoreERP hari ini belum menjawabnya.** Halaman `/dashboard` Core masih kerangka bawaan starter kit.
Dasbor aset yang sedang dibangun sesi lain adalah endpoint module dengan isi tetap: tujuh kelompok
angka yang dipilih pengembang. Satu pelanggan yang meminta angka kedelapan berarti kode baru dan
rilis baru — pola sistem lama yang sedang ditinggalkan.

**Desain kanonik sekarang menaruh dasbor khusus di luar produk.**
[Kebutuhan khusus pelanggan](/dev/05-customization-and-addons) menempatkan "dasbor khusus" di
urutan 3, integrasi yang dibangun pelanggan sendiri di atas API. Untuk pelanggan fasilitas kesehatan
kecil, jawaban itu berarti membayar Power BI dan seseorang yang bisa memakainya. Pemilik produk
memutuskan jawaban itu tidak cukup (KA-01).

**Power BI mahal untuk bentuk pelanggan ini.** Embed untuk pelanggan butuh kapasitas berbayar per
bulan, dan data harus keluar dari CoreERP ke layanan Microsoft. Rinciannya di
[riset](/todo/analitik/riset#power-bi).

## 3. Tujuan dan ukuran keberhasilan

| Tujuan | Ukuran | Target |
| --- | --- | --- |
| Permintaan dasbor dijawab tanpa koding | Bagian permintaan dasbor pelanggan yang dapat disusun konsultan dari dataset yang ada | ≥ 80% setelah fase 2 |
| Konsultan cepat | Waktu menyusun satu widget dari permintaan tertulis | < 5 menit untuk widget satu dataset |
| Data tetap aman | Kebocoran lintas tenant, lintas kebijakan data, atau data pribadi | Nol, dibuktikan test dan oracle SQL uji beban |
| Database transaksi tidak terganggu | Latensi layar transaksi saat dasbor dipakai bersamaan | Tetap dalam gate baca/tulis skill arsitektur |
| Module mudah ikut | Usaha menambah dataset ke module | Satu kelas, satu baris pendaftaran, satu test; Core tidak diubah |
| Data dapat dipakai di luar | Excel/Power BI membaca feed dan me-refresh; situs pelanggan memasang dasbor | Terbukti di SaaS dev pada fase 2 |

Ukuran pemakaian diambil hanya dari SaaS. Server on-prem tidak mengirim telemetri wajib, jadi tidak
ada angka pemakaian yang ditarik dari sana.

## 4. Bukan tujuan

| Tidak dibangun | Alasan |
| --- | --- |
| Impor berkas bebas, ETL, Power Query | Sumber data hanya tabel CoreERP (KA-04). Data dari luar masuk lewat module atau kontrak masuk, bukan lewat engine |
| SQL bebas dari pengguna | Tidak ada cara aman memberi SQL pada database bersama banyak tenant |
| Bahasa rumus selengkap DAX | Bahasa kecil yang aman cukup untuk rasio, selisih, persen, dan kondisi sederhana (KA-19) |
| Analitik lintas tenant | Konsolidasi terjadi di dalam tenant lewat legal entity (KA-23) |
| Publish to web tanpa autentikasi | Data fasilitas kesehatan; lihat [riset](/todo/analitik/riset#power-bi) |
| Aplikasi mobile tersendiri | Layar web responsif |
| Tanya-jawab AI di atas data | Menyusul lewat rencana asisten AI, di atas query engine ini |
| GraphQL | Lihat KA-09 dan [riset](/todo/analitik/riset#_3-membuka-data-ke-luar-rest-odata-atau-graphql) |
| Visual buatan pelanggan (kode di dalam runtime) | Kode pelanggan tidak pernah berjalan di runtime ([standar](/dev/05-customization-and-addons)) |
| Streaming waktu-nyata | Dasbor membaca saat dibuka atau di-refresh; cache paling lama beberapa menit |

## 5. Pengguna

| Peran | Siapa | Yang ia butuhkan |
| --- | --- | --- |
| Konsultan implementasi | Tim kita saat serah terima ke pelanggan | Menyusun dasbor sesuai permintaan pelanggan di tempat, tanpa programmer |
| Admin tenant | Staf TI atau keuangan pelanggan | Menyusun, membagikan, dan menetapkan dasbor beranda per peran; mengatur embed dan feed |
| Pimpinan dan kepala unit | Direktur, kepala instalasi, kepala gudang | Melihat angka unitnya, membandingkan periode, membuka daftar di balik angka |
| Staf operasional | Kasir, petugas gudang, petugas aset | Tile angka harian di beranda; tidak menyusun apa pun |
| Pengembang module | Tim kita | Menyatakan dataset dengan cepat dan aman, tanpa menyentuh Core |
| Developer pelanggan atau partner | Di luar CoreERP | Menarik angka ke sistem lain, memasang dasbor di portal |
| Operator vendor | Tim kita di admin.erp | Melihat query yang berat, menahan beban berlebihan |

## 6. Cerita pengguna

Setiap cerita punya kriteria terima. Fase menunjukkan kapan cerita itu harus lulus.

| Kode | Cerita | Kriteria terima | Fase |
| --- | --- | --- | --- |
| US-01 | Sebagai admin, saya melihat dasbor aset bawaan begitu module aset terpasang | Template dasbor aset tersedia di galeri; "Pakai" membuat salinan milik tenant; angkanya sama dengan layar daftar aset untuk filter yang sama | 2 |
| US-02 | Sebagai konsultan, saya menyusun widget "nilai perolehan per group aset tahun ini" tanpa koding | Pilih dataset Aset → nilai Nilai perolehan → kelompok Group aset → saring Tanggal perolehan "tahun ini" → grafik batang; hasil tampil < 5 menit sejak mulai | 1 |
| US-03 | Sebagai kepala unit, saya hanya melihat angka unit yang menjadi tanggung jawab saya | Widget yang sama menunjukkan angka berbeda untuk dua pengguna dengan hibah unit berbeda; pengguna tanpa hibah melihat nol baris, bukan semua | 1 |
| US-04 | Sebagai admin, saya membagikan dasbor ke semua pengguna tenant | Dasbor bersama terlihat setiap pengguna yang berhak melihat dasbor; widget yang datasetnya tidak boleh ia baca tampil "Anda tidak punya akses ke data ini", bukan angka | 1 |
| US-05 | Sebagai pengguna, saya mengklik batang grafik untuk melihat daftar di baliknya lalu membuka kartu asetnya | Drill membuka daftar baris dengan saringan dari batang yang diklik; baris membuka layar record module | 2 |
| US-06 | Sebagai pengguna, saya menyaring seluruh dasbor dengan unit kerja dan periode | Slicer berlaku ke semua widget yang datasetnya punya field itu atau dimensi bersamanya; widget lain menandai bahwa slicer tidak berlaku untuknya | 2 |
| US-07 | Sebagai admin, saya memasang dasbor di portal fasilitas dengan saringan terkunci per unit | Embed hanya tampil di asal situs yang didaftarkan; token kedaluwarsa dalam menit; saringan terkunci tidak dapat diubah dari peramban | 2 |
| US-08 | Sebagai analis pelanggan, saya membaca publikasi dari Excel dan Power BI | `OData.Feed` membaca `$metadata` dan baris; refresh terjadwal berhasil (dibuktikan spike) | 2 |
| US-09 | Sebagai developer pelanggan, saya menarik angka harian dari n8n | Endpoint publikasi JSON/CSV dengan token klien integrasi; kursor halaman; rate limit per klien | 2 |
| US-10 | Sebagai pengembang module, saya menambah dataset baru | Satu kelas dataset, satu baris pendaftaran, satu test katalog; Core tidak diubah; penjaga gagal bila kolom tidak bernama atau kolom kebijakan tidak ada | 1 |
| US-11 | Sebagai pimpinan, saya membandingkan bulan ini dengan bulan lalu dan tahun lalu | Widget menampilkan nilai, selisih, dan persen perubahan; periode dihitung menurut zona waktu pengguna | 2 |
| US-12 | Sebagai admin, saya mendapat peringatan saat jumlah aset rusak melewati ambang | Peringatan dievaluasi terjadwal; pemberitahuan sampai ke penerima; hak penerima diperiksa ulang saat dikirim | 3 |
| US-13 | Sebagai pengguna, saya mengekspor isi widget ke Excel | Lewat antrean ekspor yang sudah ada; isi sama dengan yang tampil | 2 |
| US-14 | Sebagai operator vendor, saya tahu query mana yang berat | Log query mencatat dataset, durasi, baris, dan sumber; query yang melewati batas waktu dihentikan dengan pesan yang menyarankan mempersempit saringan | 1 |
| US-15 | Sebagai pengguna tanpa hak data pribadi, saya tidak melihat nama pasien di analitik, walau dasbornya dibuat orang yang berhak | Field data pribadi tidak muncul di katalog; widget yang memakainya tampil "Kolom ini memuat data pribadi"; query langsung ditolak | 1 |
| US-16 | Sebagai pengguna, saya menetapkan dasbor favorit sebagai beranda | Beranda membuka dasbor pilihan pengguna, atau dasbor peran bila ia belum memilih | 2 |

## 7. Cakupan per fase

Rinciannya per area kerja ada di [TODO](/todo/analitik/TODO). Ringkasnya:

**Fase 0 — kerangka berjalan.** Satu dataset aset, satu endpoint query, satu halaman dengan satu tile
dan satu grafik batang, lengkap dengan izin, tenant, kebijakan data, dan transaksi baca-saja.
Gunanya membuktikan semua lapis tersambung sebelum enam agen bekerja bersamaan di atasnya.

**Fase 1 — engine untuk satu module.**

- Kontrak dataset lengkap; dataset pilot module aset.
- Query: dimensi, measure (jumlah baris, jumlah unik, total, rata-rata, minimum, maksimum, measure
  bersaringan), pengelompokan waktu per hari/minggu/bulan/kuartal/tahun, saringan sintaks BC,
  rentang tanggal relatif, top-N, total.
- Keamanan: izin dataset, kebijakan data, gerbang data pribadi, log query.
- Dasbor pribadi dan bersama; widget tile angka (dengan ambang ala Cue), batang, kolom, garis, area,
  donat, tabel, teks.
- Pembangun widget dan penjelajah data.
- Cache di database tenant, batas beban, uji beban, dokumentasi.

**Fase 2 — interaksi dan dunia luar.** Slicer, cross-filter, drill-down dan drill-through,
ekspor widget, rumus, perbandingan periode, dimensi bersama lintas module, publikasi, Query API,
feed OData, embed, template dasbor, dasbor per peran.

**Fase 3 — lanjutan.** Pivot dan subtotal, peringatan ambang, kirim terjadwal, ringkasan
pra-agregasi, hak add-on, dataset module lain, tombol Analisis di layar daftar module.

## 8. Kebutuhan fungsional

### Dataset dan model semantik

- **FR-01** Module menyatakan dataset dengan kelas PHP yang memenuhi kontrak di
  `App\Platform\Modules\Contracts\Analytics`, didaftarkan dari penyedia layanannya. Bentuknya di
  [model semantik](/todo/analitik/model-semantik).
- **FR-02** Field diambil dari katalog kolom yang sudah ada (`FIELD_CAPTIONS`, `FIELD_OPTIONS`,
  `FIELD_LOOKUPS`, `FIELD_HIDDEN`, `TableFields`) bila dataset memintanya, sehingga nama tampilan
  tidak ditulis dua kali.
- **FR-03** Measure dinyatakan pengembang module, tidak ditebak dari tipe kolom. Kolom angka tidak
  otomatis menjadi measure, karena menjumlah tahun perolehan atau nomor urut tidak bermakna.
- **FR-04** Measure uang membawa kolom mata uang; measure kuantitas membawa kolom satuan (KA-22).
- **FR-05** Field rujukan (ULID) membawa sumber labelnya: tabel module yang sama lewat join yang
  dinyatakan, atau dimensi bersama milik Core (unit kerja, legal entity, pengguna, vendor).
- **FR-06** Dataset menyatakan kolom kebijakan data (legal entity, unit kerja) untuk kode kebijakan
  module; tanpa itu dataset yang resource-nya dilindungi kebijakan ditolak saat didaftarkan.
- **FR-07** Dataset berversi. Field yang diganti namanya dicatat sebagai peta nama lama ke baru,
  sehingga widget tenant tidak rusak diam-diam.
- **FR-08** Dataset hanya ditawarkan kepada tenant yang memasang module-nya, dan kepada pengguna yang
  memegang permission baca resource-nya (KA-15).
- **FR-09** Dataset boleh bersumber dari query module (subquery) untuk bentuk yang tidak dapat
  dinyatakan sebagai tabel plus join, misalnya nilai buku terakhir per aset. Aturannya di
  [model semantik](/todo/analitik/model-semantik#dataset-bersumber-query).

### Query

- **FR-10** Query berbentuk JSON (KA-07) dengan dataset, dimensi, measure, saringan, rentang waktu,
  urutan, batas, dan permintaan total. Skemanya di [mesin query](/todo/analitik/mesin-query).
- **FR-11** Saringan memakai sintaks BC dari K-30 (`..`, `|`, `&`, `<>`, `*`, `?`, `@`, `''`, `t`) untuk
  teks, angka, dan tanggal; daftar nilai untuk pilihan, ya/tidak, dan rujukan.
- **FR-12** Rentang tanggal relatif: hari ini, kemarin, minggu ini/lalu, bulan ini/lalu, kuartal
  ini/lalu, tahun ini/lalu, 7/30/90 hari terakhir, 12 bulan terakhir, awal tahun sampai hari ini.
  Semuanya menurut zona waktu pengguna.
- **FR-13** Pengelompokan waktu per hari, minggu (mulai Senin), bulan, kuartal, dan tahun, menurut
  zona waktu pengguna; deret waktu diisi celahnya.
- **FR-14** Top-N dengan urutan pada measure atau dimensi; fase 2 menambah "Lainnya" untuk sisa.
- **FR-15** Total keseluruhan, dihitung terpisah dari baris supaya tidak terpengaruh batas baris.
- **FR-16** Hasil bertipe: kolom membawa jenis (dimensi/measure), tipe, format, dan label; nilai uang
  dikirim sebagai string desimal, bukan float.
- **FR-17** Rumus (fase 2): rasio, selisih, persen, kondisi sederhana, dikompilasi ke SQL (KA-19).
- **FR-18** Perbandingan periode (fase 2): periode sebelumnya, periode sama tahun lalu, beserta selisih
  dan persen perubahan.

### Dasbor dan widget

- **FR-20** Dasbor pribadi (milik satu pengguna) dan bersama (seluruh tenant), meniru aturan preset
  laporan K-25.
- **FR-21** Jenis widget fase 1: tile angka, batang, kolom, garis, area, donat, tabel, teks. Fase 2
  menambah tumpuk dan tumpuk 100%; fase 3 pivot.
- **FR-22** Tile angka punya dua ambang dan gaya bermakna ala Cue Setup BC (`Favorable`,
  `Unfavorable`, `Ambiguous`, `Subordinate`), dipetakan ke token warna tema dan ikon.
- **FR-23** Tata letak grid 12 kolom; fase 1 memindah dan mengubah lebar widget dengan tombol, tanpa
  seret-lepas (KA-12).
- **FR-24** Setiap widget menunjukkan kapan datanya dihitung dan dapat di-refresh.
- **FR-25** Widget yang datasetnya tidak lagi tersedia (module dicabut, field dihapus, hak hilang)
  tampil dengan alasan yang dapat ditindaklanjuti, tidak pernah menjatuhkan seluruh dasbor.
- **FR-26** Batas jumlah widget per dasbor (bawaan 24) dan widget di luar layar dimuat saat
  digulir, supaya satu dasbor tidak menembakkan puluhan query sekaligus.

### Pembangun dan penjelajah

- **FR-30** Pembangun widget: pilih dataset → nilai (measure) → kelompokkan menurut → saring → urutkan
  dan batasi → pilih tampilan → pratinjau langsung → simpan.
- **FR-31** Penjelajah data: tabel hasil dengan pengelompokan dan total, dapat berpindah ke grafik,
  dapat disimpan sebagai widget atau query tersimpan. Padanan *Data analysis mode* BC (KA-21).
- **FR-32** Pemilih nilai rujukan memakai pemilih yang sama dengan filter tambahan laporan
  (`MasterFilter`), jadi daftar pilihannya mengikuti hak pengguna.

### Interaksi (fase 2)

- **FR-40** Slicer dasbor: unit kerja, legal entity, periode, dan field lain yang dipilih penyusun.
- **FR-41** Cross-filter: klik nilai di satu widget menyaring widget lain yang punya field atau dimensi
  bersama yang sama.
- **FR-42** Drill-down hierarki waktu (tahun → kuartal → bulan → hari) dan hierarki yang dinyatakan
  dataset.
- **FR-43** Drill-through ke daftar baris (berhalaman, maksimal 1.000 baris tampil, sisanya lewat
  ekspor) lalu ke layar record module bila dataset menyatakan rute record-nya.
- **FR-44** Ekspor isi widget dan daftar drill ke Excel lewat antrean ekspor yang sudah ada.

### Berbagi, hak, dan publikasi

- **FR-50** Hak mengikuti rantai role → duty → privilege → permission (KA-14); hak dataset mengikuti
  permission baca resource module (KA-15).
- **FR-51** Publikasi (fase 2): pengguna berhak membuat publikasi dari query tersimpan atau dasbor,
  dengan saringan terkunci yang tidak boleh melebihi jangkauan datanya sendiri, daftar klien
  integrasi yang boleh membacanya, dan untuk embed, daftar asal situs.
- **FR-52** Klien integrasi dengan scope `analytics.read` membaca publikasi yang diizinkan baginya
  lewat REST JSON/CSV dan OData v4; scope `analytics.embed` mencetak token embed.
- **FR-53** Publikasi dapat dihentikan sementara dan dicabut; pencabutan berlaku pada permintaan
  berikutnya.
- **FR-54** Publikasi tidak pernah membawa field data pribadi (KA-05) dan dapat menyembunyikan
  kelompok berisi kurang dari *k* baris.

### Template, beranda, peringatan

- **FR-60** Module mendaftarkan template dasbor bawaan sebagai kode; admin memasangnya menjadi dasbor
  tenant (KA-17).
- **FR-61** Admin menetapkan dasbor beranda per security role; pengguna boleh memilih berandanya
  sendiri (fase 2). Halaman `/dashboard` kerangka diganti.
- **FR-62** Peringatan ambang terjadwal dan kirim dasbor terjadwal (fase 3), menunggu infrastruktur
  email dan pemberitahuan sisi server.

### Administrasi dan operasi

- **FR-70** Log query per tenant dengan retensi lewat `RetentionService`.
- **FR-71** Perintah `analytics:datasets` (daftar dan periksa dataset) dan `analytics:explain` (rencana
  query sebuah widget) untuk operator.
- **FR-72** Batas waktu, batas baris, batas laju, dan batas query bersamaan dapat disetel lewat config,
  dengan bawaan yang aman untuk server on-prem satu container.

## 9. Kebutuhan nonfungsional

| Kode | Kebutuhan | Ukuran |
| --- | --- | --- |
| NFR-01 | Isolasi tenant | Nol baris tenant lain di hasil mana pun; diverifikasi oracle SQL saat uji beban |
| NFR-02 | Kebijakan data | Untuk setiap dataset berkebijakan, himpunan baris yang terlihat lewat analitik sama dengan lewat layar daftar module (test paritas) |
| NFR-03 | Gagal tertutup | Konteks tenant, hibah, atau saringan terkunci yang hilang menghasilkan nol baris atau penolakan, tidak pernah "semua" |
| NFR-04 | Baca-saja | Setiap query di transaksi `READ ONLY`; percobaan menulis gagal di database, bukan hanya di kode |
| NFR-05 | Batas waktu | Bawaan 8 detik dari layar, 20 detik dari API dan feed, 60 detik untuk job; dapat disetel |
| NFR-06 | Latensi | Widget dari cache p95 < 200 ms; widget tanpa cache pada dataset rujukan p95 < 1 s pada concurrency yang masih tertahan; dasbor 6 widget terbuka penuh p95 < 2,5 s. Angka dikunci di area 10 |
| NFR-07 | Uji beban | Gate modul baru berlaku: 1000+ VU, 100+ tenant, 2+ instance, PostgreSQL, 90 detik; gate kebenaran nol pelanggaran |
| NFR-08 | On-prem | Tanpa runtime tambahan, tanpa Redis wajib, tanpa telemetri wajib; berjalan di container `core-app` yang sama |
| NFR-09 | Database sendiri | Query dan cache berjalan di koneksi environment tenant; data tenant tidak disalin ke database pusat (KA-18) |
| NFR-10 | Kompatibilitas mundur | Migration memenuhi aturan N-1; dataset versi baru tidak merusak widget lama |
| NFR-11 | Aksesibilitas | Grafik punya padanan tabel; warna bukan satu-satunya sinyal; dapat dipakai dengan keyboard |
| NFR-12 | Bahasa dan format | Bahasa Indonesia sehari-hari di layar; angka `1.234.567,89`; uang `Rp`; tanggal `dd/mm/yyyy`; zona waktu pengguna |
| NFR-13 | Keteramatan | Durasi, baris, cache, dan status setiap query tercatat; galat dilaporkan ke SigNoz tanpa data pribadi |
| NFR-14 | Kontrak | Setiap permukaan untuk luar ada di `apps/core/contracts/internal/` dan lolos pemeriksa cakupan |

## 10. Prinsip pengalaman pengguna

- **Mulai dari pertanyaan, bukan dari tabel.** Pembangun bertanya "nilai apa" lalu "dikelompokkan
  menurut apa", seperti pengguna memikirkannya, dan menyembunyikan join sepenuhnya.
- **Angka selalu dapat ditelusuri.** Setiap angka dapat dibuka menjadi daftar baris di baliknya
  (fase 2), dan daftar itu sama dengan yang dilihat di layar module.
- **Penolakan menjelaskan dirinya.** "Anda tidak punya akses ke data aset unit ini" lebih berguna
  daripada widget kosong yang tampak seperti nol.
- **Tidak ada istilah arsitektur di layar.** Bukan "dataset", "measure", atau "tenant": layar memakai
  "Data", "Nilai", "Kelompokkan menurut", dan "Saring".
- **Ikuti pola Shell yang ada.** Halaman Core tanpa `AppLayout` ganda, kartu SDK, `DataTable`,
  `Sheet` kanan untuk pembangun, bantuan kontekstual hanya pada field yang rumit
  ([standar halaman](/dev/02-module-standard#bantuan-kontekstual-pada-halaman-dan-field)).

## 11. Model komersial (KA-06)

| Kemampuan | Paket |
| --- | --- |
| Melihat dasbor yang dibagikan, dasbor beranda per peran, template bawaan module | Semua paket |
| Pembangun widget, penjelajah, dasbor bersama buatan sendiri | Add-on Analitik |
| Publikasi, Query API, feed OData, embed | Add-on Analitik |
| Peringatan dan kirim terjadwal | Add-on Analitik |

Core belum punya hak per fitur: hak saat ini per app id, dan Core sendiri tidak pernah memerlukan
hak. Pilihan mekanismenya ada di [pertanyaan terbuka](#_14-pertanyaan-terbuka). Selama belum
diputuskan, semua kemampuan menyala untuk semua tenant — aman karena belum ada pelanggan di CoreERP —
dan area 22 menutupnya sebelum rilis yang dijual. Nama add-on yang tampil di layar dibaca dari
katalog, tidak ditulis mati di kode.

## 12. Ketergantungan

| Bergantung pada | Untuk | Keadaan |
| --- | --- | --- |
| Filter tambahan K-30 (`TableFields`, `FieldFilterExpression`) | Katalog field dan sintaks saringan | Ada |
| Klasifikasi data per kolom (#217) | Gerbang data pribadi | Ada |
| `DataPolicyAccessResolver` | Hibah kebijakan data | Ada |
| `LaunchableAppCatalog` | Module terpasang dan permission pengguna | Ada |
| Klien integrasi (`integration_clients`) | Akses luar | Ada; scope baru ditambah |
| Antrean ekspor (`report_exports`) | Ekspor widget | Ada |
| `RetentionService` | Retensi log query, token embed, cache | Ada |
| `@apperp/ui/chart` (Recharts 3.8) | Grafik | Ada, belum dipakai siapa pun |
| Keputusan pemilik KA-11, KA-14 | Area 6, 15–17 | Disetujui 3 Okt 2026 |
| Spike KA-13 | Area 16 | Menunggu |
| Email dan pemberitahuan sisi server | Area 20 | Belum ada |
| Job dan jadwal per environment (TODO database sendiri 4.1, 4.2) | Area 20, 21 | Belum ada |

## 13. Risiko

| Kode | Risiko | Dampak | Penangkal | Area |
| --- | --- | --- | --- | --- |
| R-01 | Compiler lupa menyaring tenant atau kebijakan pada join | Kebocoran data antar tenant atau antar unit | Saringan disisipkan di satu tempat; test lintas tenant per dataset; test paritas kebijakan; oracle SQL uji beban | 3, 4, 10 |
| R-02 | Query berat menekan database transaksi | Layar transaksi melambat untuk semua tenant di server yang sama | Batas waktu, batas baris, batas laju, batas bersamaan per tenant, cache, ringkasan di fase 3 | 3, 9, 21 |
| R-03 | Module mengganti kolom; widget tenant rusak diam-diam | Angka salah atau widget kosong tanpa sebab | Versi dataset, peta nama lama, penjaga katalog, pesan di widget | 1, 7 |
| R-04 | Data pribadi pasien terbuka lewat analitik atau embed | Pelanggaran privasi | KA-05; publikasi tanpa data pribadi; kelompok kecil disembunyikan | 4, 15 |
| R-05 | Token embed bocor | Dasbor terbaca pihak lain | TTL menit, terikat asal dan dasbor, dapat dicabut, rate limit, log | 17 |
| R-06 | Uang dijumlah lintas mata uang | Angka keuangan salah | KA-22 dipaksa compiler; test | 3 |
| R-07 | Zona waktu salah di batas hari | Transaksi pukul 00.30 WIB masuk hari kemarin | `date_trunc` berzona, rentang relatif berzona; test di batas hari | 3 |
| R-08 | Cache menyimpan data tenant di database pusat | Data tenant ber-database sendiri keluar dari databasenya | KA-18 | 9 |
| R-09 | Cakupan melebar menjadi "Power BI lengkap" | Proyek tidak pernah selesai | Bukan-tujuan di bagian 4; gate per fase | semua |
| R-10 | Server on-prem satu container kehabisan proses karena analitik | Layar kasir lambat | Batas bersamaan per tenant, batas waktu rendah, fitur berat dapat dimatikan lewat config | 9 |
| R-11 | Dua implementasi aturan kebijakan data menyimpang | Analitik dan layar module menunjukkan baris berbeda | Satu pembantu `DataPolicyFilter` di kontrak; test paritas | 4 |
| R-12 | Hak add-on belum punya mekanisme | Fitur berbayar terbuka gratis | KA-06; area 22 sebelum rilis berbayar | 22 |
| R-13 | Recharts lambat untuk deret panjang | Grafik tersendat | Batas titik per seri; agregasi waktu lebih kasar; ECharts hanya bila perlu | 7 |
| R-14 | Refresh Power BI service tidak bekerja dengan autentikasi kita | Janji ke pelanggan tidak terpenuhi | Spike sebelum dijanjikan | 16 |
| R-15 | Dasbor sesi lain dan engine berjalan sendiri-sendiri | Dua jalur untuk hal yang sama | Koordinasi; template di area 18 | 18 |

## 14. Pertanyaan terbuka

| Kode | Pertanyaan | Usulan | Yang memutuskan |
| --- | --- | --- | --- |
| PQ-01 | Susunan permission, privilege, dan duty analitik (KA-14) | Lihat [rantai izin yang diusulkan](/todo/analitik/keamanan#rantai-izin-yang-diusulkan) | Pemilik — disetujui apa adanya, 3 Okt 2026 |
| PQ-02 | Pemanggil luar hanya membaca publikasi di v1 (KA-11)? | Ya. Query bebas dari luar menunggu akun aplikasi punya peran | Pemilik — disetujui, 3 Okt 2026 |
| PQ-03 | Mekanisme hak add-on (KA-06) | (a) Produk katalog `analytics` milik Core yang hanya butuh hak, tanpa catatan pemasangan; atau (b) penanda di lisensi dan paket admin.erp. Usulan (a), karena `TenantProducts` dan `SiteLicense::allowsApp` sudah bekerja per app id | Pemilik |
| PQ-04 | Siapa yang otomatis memegang hak data pribadi analitik? | Hanya role Owner, mengikuti pola migration katalog Core; role lain diberi sengaja | Pemilik — disetujui, 3 Okt 2026 |
| PQ-05 | Masa simpan log query bawaan | 90 hari, dapat diubah admin tenant, minimum 7 | Pemilik — disetujui, 3 Okt 2026 |
| PQ-06 | Embed tanpa token (publik) untuk angka agregat yang tidak sensitif? | Tidak di v1 | Pemilik |
| PQ-07 | Ambang kelompok kecil bawaan untuk publikasi (*k*) | 5 untuk dataset yang menyentuh pasien, mati untuk yang lain; penyusun publikasi boleh menaikkan | Pemilik bersama konsultan klinis |
| PQ-08 | Tombol Analisis di layar daftar module (menyentuh UI module) | Opsional per module, mulai dari aset di fase 3 | Pemilik |
| PQ-09 | Module yang memang harus ter-link (`dependsOn`): boleh membaca tabel module induknya? | Tetap tidak; kerja sama lewat kontrak atau event seperti aturan sekarang. Ditanyakan karena pemilik menyebut "kecuali memang benar-benar harus linked" | Pemilik |

## 15. Gate rilis per fase

Sebuah fase selesai bila **semua** ini benar:

1. Setiap area fase itu bertanda `[x]` dengan test yang membuktikannya, menurut
   [aturan backlog](/todo/).
2. Suite Core paralel dua tahap hijau, termasuk grup `lambat`, dan PHPStan tidak menambah baseline.
3. Uji beban fase itu lulus gate kebenaran dan gate latensi (area 10), dengan sumber daya yang jenuh
   pertama disebut.
4. Tinjauan keamanan (skill `security-review`) atas permukaan baru, termasuk percobaan menembus
   tenant, kebijakan, data pribadi, dan token.
5. Dokumentasi kanonik ditulis atau diperbarui, terdaftar di sidebar, dan build docs bersih.
6. Layar yang berubah dibuka di runtime lokal setelah container dibangun ulang, terang dan gelap,
   dengan keyboard.
7. Demo ke pemilik produk dengan data uji, termasuk satu skenario yang **ditolak** dengan benar.

## 16. Rujukan

- [Riset](/todo/analitik/riset) — sumber BC, F&O, Power BI, dan produk lain.
- [Ownership dan data](/dev/02-module-standard#ownership-dan-data) — syarat Core membaca tabel module.
- [Dokumen cetak](/dev/23-document-rendering) — mesin laporan yang paling dekat bentuknya.
- [Query scope dan schema](/dev/08-query-scopes-and-schema) — urutan otorisasi query.
- [Load dan concurrency testing](/dev/20-load-and-concurrency-testing) — gate uji beban.
- [Analisa gap BC fase 1](/todo/AnalisaGapCoreErpkeBCPhase1/) — K-24 sampai K-30 yang dipakai ulang.
