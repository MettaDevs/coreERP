# Procurement: dari permintaan sampai tagihan vendor

Peta rencana kerja modul procurement, bukan desain kanonik. Ditulis 1 Oktober 2026, saat desain
dimulai lewat sesi tanya-jawab part per part dengan pemilik produk. Halaman ini hanya peta: alur,
batas kepemilikan, dan status setiap part. Keputusan dan butir kerjanya ada di folder part
masing-masing.

Dua sumber, dengan bobot yang berbeda:

- **Acuan perilaku: Business Central dan Dynamics 365 F&O.** Source BC dibaca dari `D:\Kerja\BCApps`
  lewat `bc-tools`; F&O dari Microsoft Learn. Bila keduanya berbeda, keputusan menyebut mana yang
  diikuti dan kenapa.
- **Rancangan QA, `docs/apps/procurement/DOKUMENTASI APLIKASI PROCUREMENT.drawio.xml`, hanya gambaran
  besar.** Ia menunjukkan layar, kolom, dan contoh data yang diharapkan pengguna. Bila rancangan itu
  bertabrakan dengan cara kerja D365, yang diikuti D365, dan perbedaannya ditulis di part yang
  bersangkutan. Rancangan tidak pernah diikuti diam-diam.

Lingkungan rujukan yang dipakai saat membahas setiap part:

| Lingkungan | Alamat | Aturan pakai |
| --- | --- | --- |
| F&O sandbox, perusahaan demo `USMF` | dibuka di browser pane | Milik pihak lain: boleh dibuka dan dicoba isiannya, **tidak boleh disimpan**; tidak ada Post, Confirm, Submit, atau Delete |
| BC sandbox, perusahaan demo CRONUS | dibuka di browser pane | Boleh diubah |
| Source BC | `D:\Kerja\BCApps`, dibaca lewat `bc-tools` | — |

Layar F&O dirujuk dengan nama menu item (`mi=…`), yang berlaku di environment F&O mana pun. Alamat
environment dan tangkapan layar tidak ditulis di repo; temuannya ditulis sebagai teks.

Halaman ini menggantikan [App Procurement terhadap D365](/todo/general/07-app-procurement). Audit itu
ditulis untuk bentuk lama — app dengan repo, database, dan token layanan sendiri — yang sudah
dibuang.

## Cara dokumen ini disusun

- **Satu folder per part**, berisi PRD (`README.md`) dan TODO (`TODO.md`); part yang kecil cukup
  satu berkas. Part dapat diputuskan, dikerjakan, dan dinyatakan selesai sendiri-sendiri, tanpa
  menunggu procurement selesai seluruhnya.
- **Part 0 lebih dulu dari semuanya.** Ia membuktikan modul yang masih kosong melewati seluruh jalur
  hidup modul, sehingga setiap part sesudahnya tinggal menumpang.
- **Folder part baru dibuat saat part itu dibahas.** Sebelum itu, pertanyaannya menunggu di
  [Pertanyaan yang menunggu](#pertanyaan-yang-menunggu) di halaman ini, supaya tidak ada folder
  kosong yang tampak seperti rencana.
- **Keputusan bernomor per part**: `K1-03` adalah keputusan ketiga part 1. Nomor tidak pernah
  dipakai ulang; keputusan yang dibalik ditandai dan diganti nomor baru.
- **Master milik Foundation tidak didokumentasikan di sini.** Item, syarat bayar, pajak, dan
  rekening bank kelak dipakai modul lain juga, jadi rencananya tinggal di
  [master bersama](/todo/master-bersama/). Part procurement hanya menautkannya sebagai prasyarat.
- **Kerja paralel terjadi per area.** Setiap area di TODO menyebut tempatnya, yang harus selesai
  lebih dulu, keputusan yang dibutuhkan, dan kriteria selesainya, serta menyentuh berkasnya sendiri
  — migration, `manifest/<area>/<fitur>.yaml`, rute, dan halaman per fitur. Area yang keputusannya
  masih terbuka belum boleh dimulai.

Alasan tidak memakai satu dokumen untuk semuanya: procurement mencakup proses dari permintaan
sampai pembayaran, ditambah master Foundation dan akses dari luar. Satu dokumen untuk semua itu
tumbuh terlalu besar untuk ditinjau, dan satu `TODO.md` yang diubah beberapa orang sekaligus akan
terus bentrok saat digabung.

## Peta part

| Part | Isi | Folder | Status |
| --- | --- | --- | --- |
| 0 | Kerangka berjalan: modul dikenal katalog, dipilih di admin.erp, terpasang dan tampil di tenant | [00-kerangka-berjalan](/todo/procurement/00-kerangka-berjalan/) | **siap dikerjakan pertama** |
| 1 | Vendor dan akses dari luar: login vendor, identitas, onboarding, kontak | [01-vendor-dan-akses-eksternal](/todo/procurement/01-vendor-dan-akses-eksternal/) | **diputuskan**, siap dikerjakan sebagian |
| 2 | Master pengadaan: item dan kategori, syarat dan cara bayar, pajak, dokumen legalitas, harga perkiraan | — | menunggu dibahas |
| 3 | Purchase requisition (PR) | — | menunggu dibahas |
| 4 | Request for quotation (RFQ) dan seleksi vendor | — | menunggu dibahas |
| 5 | Purchase order (PO) dan SPK | — | menunggu dibahas |
| 6 | Penerimaan barang dan jasa | — | menunggu dibahas |
| 7 | Invoice vendor dan pembayaran | — | menunggu dibahas |
| 8 | Laporan dan monitoring | — | menunggu dibahas |

## Alur yang dituju

Rancangan QA menggambar alur dalam empat lajur — peminta, procurement, vendor, penerimaan.
Petanya ke D365:

| Langkah di QA | F&O | BC | Part |
| --- | --- | --- | --- |
| Purchase Request | Purchase requisition, dengan workflow persetujuan | Tidak ada dokumen permintaan; terdekat Requisition Worksheet, alat perencanaan | 3 |
| Request for Quotation | RFQ case, satu RFQ per vendor, balasan (*bid*) | Purchase Quote, satu dokumen per vendor | 4 |
| Seleksi vendor | *Compare replies*, terima per baris, sisanya ditolak | Tidak ada; staf memilih quote lalu *Make Order* | 4 |
| Purchase Order / SPK | Purchase order dengan versi, persetujuan, dan konfirmasi vendor | Purchase Order | 5 |
| Goods Receipt | Product receipt | Purchase receipt | 6 |
| Invoice Received, Input BO (AP) | Vendor invoice dan pencocokan dengan PO serta penerimaan | Purchase invoice, E-Documents | 7 |
| Monitoring Payment, Monitoring Pengadaan | Status bayar invoice; workspace | Laporan dan cue | 8 |
| Prakualifikasi, Kualifikasi | Tahanan vendor, sertifikasi, dan kategori yang disetujui; onboarding dan *vendor request* lewat portal | Tidak ada | 1 |

Lajur **vendor** di QA — menerima RFQ, membuat penawaran, menerima PO, mengirim barang, menagih —
mengikuti pola F&O di keputusan `K1-01`: vendor boleh mengerjakannya sendiri lewat login, dan setiap
langkahnya juga dapat dicatat staf.

## Batas kepemilikan

Aturannya mengikuti [master bersama](/todo/master-bersama/): master yang dipakai dua modul atau
lebih hidup di Foundation, modul hanya bergantung ke bawah, dan kerja sama dua modul dibangun
sebagai tambahan.

| Bagian | Pemilik | Catatan |
| --- | --- | --- |
| Proses pengadaan: *vendor request*, permintaan, RFQ, balasan, PO, penerimaan, invoice vendor | modul `procurement` di `modules/apperp/procurement` | Fakta proses milik modul |
| Vendor (nomor, NPWP, status) | Foundation, sudah ada (`vendors`) | Procurement pemakai, bukan pemilik |
| Kontak vendor, rekening bank vendor | Foundation, belum ada | Kontak di part 1 |
| Item beserta kategori, syarat bayar, cara bayar, kode dan grup pajak | Foundation, belum ada | Procurement pemakai pertamanya; rencananya di master bersama |
| Kolom vendor khusus procurement | tabel modul, tampil sebagai bagian di kartu vendor yang sama | Pola "satu kartu, bagian per modul" |
| Akses dari luar: user eksternal, data policy per vendor, undangan | Platform | Part 1 |
| Jurnal ke aplikasi finance | Foundation (`FinancePosting`) | Part 7 |
| Penerimaan aset dari PO | modul aset mendengarkan event procurement | Tanpa modul aset, procurement tetap utuh |

Modul aset sudah punya permintaan pembelian aset sendiri
(`modules/apperp/management-aset/manifest/fixed-asset/purchase-requisitions.yaml`, nomor `RPPA`).
Hubungannya dengan purchase requisition procurement diputuskan di part 3.

## Pertanyaan yang menunggu

Saat sebuah part dibahas, pertanyaannya pindah ke folder part itu dan dijawab di sana.

### Part 2 — master pengadaan

- **Item.** Bentuk master item di Foundation: `Item` BC (jenis *Inventory*, *Service*,
  *Non-Inventory*) atau *released product* F&O (jenis item atau jasa). Apakah "Item Upah" dan
  "Item Subkont" di QA cukup menjadi item berjenis jasa?
- **Kategori.** "Kategori Pengadaan" di QA (Pengadaan Barang, Aset/Modal, Jasa) itu kategori atau
  jenis pembelian? F&O memakai *procurement category hierarchy* yang juga menentukan vendor mana
  yang disetujui untuk kategori apa; BC hanya punya `Item Category`.
- **Syarat bayar dan uang muka.** QA memakai DP 50%. F&O memakai *payment schedule* dan
  *prepayment*; BC memakai `Prepayment %`.
- **Cara bayar.**
- **Pajak.** Kode dan grup PPN; PPh yang dipotong saat membayar jasa.
- **Jenis vendor** sebagai *vendor group* F&O.
- **Dokumen legalitas.** Checklist QA (NIB, SIUP, SITU, TDP, akta, surat keagenan, sertifikat)
  sebagai *vendor certifications* F&O, yang punya jenis dan tanggal kedaluwarsa. Sebagian dokumen
  sudah digantikan NIB sejak OSS (TDP, dan menurut beberapa sumber juga SIUP).
- **Harga Perkiraan.** Harga acuan per item. F&O dapat menyembunyikan nilai perkiraan internal dari
  vendor di form RFQ.
- **Daftar item vendor dan kesediaannya** di QA: *approved vendor list* F&O, *trade agreement*,
  atau persetujuan per kategori.
- Awalan nama tabel modul.

### Part 3 — purchase requisition

- Peminta dan penanggung jawab: user atau Worker. Worker masih keputusan terbuka K-W di master
  bersama.
- Persetujuan lewat workflow Core, dan siapa penyetujunya.
- "Sumber Dana" di QA: pengecekan anggaran seperti *budget control* F&O, atau sekadar keterangan.
- "Qty Stock" di QA, padahal belum ada modul persediaan.
- Hubungan dengan permintaan pembelian aset (`RPPA`) di modul aset.
- Status QA (Pending, On Process, Approval, Done) dipetakan ke status F&O (*Draft*, *In review*,
  *Approved*, *Rejected*, *Cancelled*, *Closed*).

### Part 4 — RFQ dan seleksi

- RFQ dibuat dari PR, manual, atau keduanya.
- Penawaran tertutup sampai batas waktu, dan kriteria penilaian.
- Menerima per baris dari vendor berbeda.
- Hasil yang diterima menjadi PO atau perjanjian pembelian.
- Balasan vendor sudah pasti satu record dengan pengisinya, vendor atau staf (`K1-01`).

### Part 5 — purchase order dan SPK

- Persetujuan dan versi PO (*change management* F&O).
- Status konfirmasi vendor; staf boleh mengonfirmasi tanpa respons vendor, dan hal itu tercatat
  (`K1-01`).
- Cetak: kop dan penandatangan dibaca dari data, bukan teks tetap — contoh cetak di QA memuat nama
  perusahaan dan nama orang sungguhan.
- Uang muka.

### Part 6 — penerimaan

- Penerimaan sebagian, penolakan, dan menutup sisa PO ("Close PO" di QA).
- Tanpa modul persediaan: penerimaan dicatat di mana.
- Penerimaan aset lewat event ke modul aset.

### Part 7 — invoice dan pembayaran

- Pencocokan invoice dengan PO dan penerimaan.
- "Input BO (AP)" menjadi jurnal lewat `FinancePosting`.
- Status bayar dibaca dari aplikasi finance.
- Faktur pajak masukan.

### Part 8 — laporan dan monitoring

- Laporan yang dibutuhkan (menu "Laporan" di QA belum berisi spesifikasi). Mesin laporan ada di
  Core; modul hanya menyediakan dataset.
- Isi dashboard dan monitoring.

## Rencana fase

Usulan, ditetapkan ulang setelah part 8 selesai dibahas:

1. **Proses internal.** Master Foundation yang dibutuhkan, lalu prakualifikasi pada vendor
   (`K1-11`), PR, RFQ, seleksi, PO, penerimaan, dan invoice, dengan staf mewakili vendor (`K1-01`).
2. **Akses vendor.** User eksternal, data policy per vendor, undangan berbukti kotak masuk, lalu
   onboarding calon vendor lewat wizard, balasan RFQ, konfirmasi PO, dan invoice oleh vendor.

Modul baru belum selesai hanya karena test feature lulus: gate load test standar module berlaku,
ditambah satu gate untuk fase 2 — **nol baris milik vendor lain yang terbaca user eksternal**,
diverifikasi lewat SQL langsung.
