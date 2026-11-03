# Sumber stok, titipan DEPO, dan atribusi pembayaran

Spesifikasi ini melengkapi [aturan sharing](ORGANIZATION-AND-SHARING.md) setelah audit source `D:/Kerja/seroja-medika-source-puskesmas` pada 2 November 2026. Sumber stok harus dinamis, tetapi pemakaian di klinik tidak otomatis menjadikan barang atau pendapatan milik klinik.

Ini target desain. Tidak ada source aplikasi atau database diubah. Source routing yang ada digunakan sebagai fondasi; ia belum membuktikan model titipan dan settlement lengkap sudah berjalan.

## Pisahkan peran yang berbeda

| Peran                                       | Pertanyaan                                                              | Contoh profile apotek dengan DEPO klinik                                                                       |
| ------------------------------------------- | ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Requesting/clinical organization            | Siapa memberikan layanan dan mencatat episode/pemakaian?                | Klinik atau poli peminta.                                                                                      |
| Procurement organization                    | Siapa berwenang memesan/membeli dan menjadi pihak pada penerimaan?      | Apotek sesuai profile yang disetujui.                                                                          |
| Stock owner                                 | Siapa pemilik persediaan dan biaya barang?                              | Apotek, tidak berubah hanya karena dititipkan.                                                                 |
| Physical site dan custodian                 | Di mana barang ada dan siapa mengelola penyimpanannya?                  | Lokasi DEPO di Klinik, dengan pengelola yang ditetapkan.                                                       |
| Fulfillment destination dan stock authority | Siapa melaksanakan penyerahan/pemakaian dan ledger mana yang dikurangi? | Petugas/unit yang berwenang; authority stok Apotek dengan lokasi/lot aktual.                                   |
| Seller/billing issuer                       | Siapa pihak penjual/penerbit tagihan barang?                            | Apotek bila kontrak/profile menetapkannya.                                                                     |
| Receivable/revenue beneficiary              | Siapa berhak atas tagihan/hasil penjualan?                              | Apotek atau pihak yang ditentukan kontrak; tidak disimpulkan dari session kasir.                               |
| Collection cashier                          | Siapa menerima uang dari pasien?                                        | Kasir Apotek atau kasir Klinik yang diberi wewenang menagih untuk pihak tersebut.                              |
| Settlement                                  | Bagaimana penerimaan dialokasikan dan diselesaikan antar-pihak?         | Referensi tagihan Apotek, penerimaan, alokasi, dan status penyelesaian yang terpisah dari tagihan jasa Klinik. |

Satu organisasi boleh memegang beberapa peran, tetapi penyimpanannya tidak boleh memakai satu UnitBisnisID yang diasumsikan berarti semuanya. Nama database, nama section DEPO, lokasi fisik, dan user yang menekan Realisasi bukan bukti kepemilikan barang atau pendapatan.

## Modelkan custody tanpa mencampur pemilik

Titipan adalah perjanjian/binding custody yang menghubungkan stock owner, custodian, lokasi, kategori/daftar item yang diizinkan, periode berlaku, kewenangan, dan aturan penerimaan/pemakaian/pengembalian. Pemindahan lokasi tidak otomatis memindahkan title/owner atau mengakui penjualan.

Larangan mencampur stok dua pemilik dalam satu saldo tetap berlaku. Namun, barang Apotek yang berada di DEPO Klinik dapat tetap **satu owner Apotek dengan custodian berbeda**. Ini bukan alasan menolak seluruh model custody hanya karena owner dan lokasi pelayanan berada pada organisasi berbeda.

BMHP milik Klinik, BMHP titipan Apotek, obat resep milik Apotek, dan kategori lain harus dapat mempunyai binding berbeda. Ruangan fisik sama tidak boleh mencampur owner/ledger/lot. Kategori BMHP saja juga tidak menentukan siapa pemilik atau siapa menerima uang.

Item/custody eligibility mengikuti profile fasilitas, perjanjian, kategori barang, lokasi, dan petugas yang berwenang. Konfigurasi sumber stok tidak dapat melewati batas tersebut. Penambahan titipan tidak otomatis memberi Klinik hak membeli atau menjual kategori yang tidak diizinkan oleh profile-nya.

## Atur routing sebagai binding yang berversi

Minimal profile transaksi mengikat tenant, clinical org, procurement org, stock owner, custodian/location, fulfillment unit, authority produk/environment, allowed item category, seller, beneficiary, collector, settlement rule, serta revision/effective date. Nama ini rancangan kontrak, bukan kolom yang sudah tersedia.

Binding dapat berbeda per kategori, tujuan resep, section/poli, serta profile proses. Backend mengambil ulang mapping yang sah; browser tidak menentukan koneksi, owner, rekening, atau Lokasi_ID bebas. Jangan memindahkan konstanta SEC025, SEC090, lokasi 101, atau unit 2/14 menjadi default universal platform.

Flowable dapat memilih atau mengoordinasikan tindakan yang didukung sesuai profile/version. Action Faskes tetap memvalidasi kelayakan, owner, custody, lot, akses, pembayaran, dan atomic effect. Mengubah gambar proses tidak memberikan hak mutasi ledger atau menghapus syarat kewenangan fasilitas.

Jika authority tidak tersedia atau binding ambigu, tolak/pending operasi. Jangan fallback ke database klinik atau Apotek lain sehingga pemilik/source berubah diam-diam.

## Pisahkan tiga keadaan transaksi

| Keadaan                             | Makna                                                                                          |
| ----------------------------------- | ---------------------------------------------------------------------------------------------- |
| Pemakaian/layanan klinis            | Episode dan realisasi di unit Klinik tercatat.                                                 |
| Fulfillment persediaan              | Alokasi/reservasi/issue pada ledger owner berhasil dan receipt tersedia.                       |
| Billing, collection, dan settlement | Tagihan pihak yang benar, penerimaan, alokasi beneficiary, serta status penyelesaian tercatat. |

Jangan menutup seluruh keadaan dengan satu flag realisasi atau ClosePayment. Stock issue, penyerahan, pembayaran, dan penyelesaian antar-pihak dapat mempunyai waktu/status berbeda. UI dan laporan harus menunjukkan keadaan sebenarnya.

Satu penerimaan kas dapat dialokasikan ke jasa Klinik dan tagihan obat Apotek sesuai profile yang sah. Setiap bagian mempunyai dokumen asal dan pihak yang benar; tidak diakui dua kali. BMHP dapat dibebankan sebagai komponen jasa atau baris pemakaian tersendiri sesuai mode yang didukung, tanpa otomatis membuat dua penjualan.

Jangan menyatakan seluruh transaksi lama sudah masuk pendapatan Apotek hanya karena BO target, section, atau label DEPO menunjukkan farmasi. Pemetaan invoice, beneficiary, dan settlement membutuhkan bukti pada jalur transaksi/posting terkait.

## Jaga integritas ketika database berbeda

Klinik menyimpan episode/consumption dan intent operasi. Authority Apotek menyimpan reservasi/issue serta receipt idempotent. Acknowledgement menyelesaikan status sesuai kontrak; clinical finalization atau payment closure tidak boleh mengaku fulfillment sukses sebelum syarat receipt yang dipilih terpenuhi.

Satu transaksi lokal Klinik tidak otomatis membungkus FIFO di koneksi APOTEK. Uji kegagalan sebelum/sesudah issue, commit klinis, dan pencatatan penerimaan. Gunakan durable operation/outbox, receipt, rekonsiliasi, serta state pending/gagal yang dapat diperbaiki. Jangan menambah saldo bayangan Klinik yang dapat dikurangi secara independen dari owner.

Snapshot transaksi mencatat owner, custodian, lokasi, lot/batch/expiry, authority, seller, beneficiary, collector, profile revision, episode/order ID beserta origin environment, dan idempotency ID. NoReg atau local ID saja tidak unik lintas database fasilitas.

Retur, pembatalan, koreksi tagihan, dan refund menunjuk dokumen/owner/lot serta alokasi asal. Jangan memakai routing/profile terbaru untuk mengembalikan obat ke owner lain atau membebankan refund ke kas yang salah. Perpindahan owner adalah transaksi/perjanjian terpisah, bukan konsekuensi perubahan parent organisasi.

## Bukti source Seroja yang diperiksa

Checkout berada pada branch DIVA, HEAD `2bbd9327ae69b3fd844c2420cf9b5c76447d5b9d`. AGENTS/source pada folder ini menjelaskan sistem Penta Medica. Ini source audit, bukan verifikasi runtime atau database Seroja yang sedang dipakai.

| Source relatif terhadap repository tersebut                                                            | Temuan dan batas kesimpulan                                                                                                                                                                                                         |
| ------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `docs/architecture/business-units-phase1.md` dan `libraries/Business_units.php`                        | AUTH dan operasional FO/APOTEK mempunyai routing terpisah. Pemilihan database tidak membuktikan economic ownership.                                                                                                                 |
| `helpers/pharmacy_routing_helper.php`                                                                  | Memisahkan destination, FarmasiStokSectionID, dan Lokasi_ID. Tujuan/unit masih khusus SEC025/SEC090 dan 2/14. Object DB yang diberikan diutamakan sebelum mencoba APOTEK; tidak semua pemanggil otomatis berada pada database sama. |
| `modules/puskesmas/poly/helpers/eresep_helper.php`                                                     | Resep menyimpan destination/source routing. Snapshot ini titik awal untuk binding transaksi yang lebih lengkap.                                                                                                                     |
| `modules/puskesmas/poly/models/Bmhp_model.php`                                                         | Standalone BMHP memakai SIMtrPOP/SIMtrDetailPOP, DEPO SEC090/lokasi101, dan filter BMHP. Header klinis memakai koneksi aktif, sedangkan lookup stok/FIFO dapat memakai APOTEK. Belum bukti atomicity lintas koneksi.                |
| `modules/puskesmas/poly/controllers/Products.php`                                                      | Lookup E-Resep menggunakan lokasi dari routing. Jalur yang diperiksa tidak membatasi semua pilihan menuju DEPO sebagai BMHP-only. Jangan menyamakan jalur standalone BMHP dengan seluruh E-Resep.                                   |
| `modules/puskesmas/pharmacy/controllers/Pharmacy.php` dan `cashier/helpers/general_payment_helper.php` | Unit aktif, unit detail stok, dan penutupan pembayaran mempunyai konteks berbeda. Audit ini belum membuktikan semua penerimaan obat Klinik dialokasikan ke Apotek.                                                                  |
| `modules/puskesmas/cashier/helpers/posting_helper.php` dan inventory `Posting_model.php`               | Ada pemilihan tujuan BO/pembelian berdasarkan audit atau mapping fasilitas. Routing tersebut tidak menggantikan atribusi seller/beneficiary/collector secara eksplisit.                                                             |

## Bukti database Penta yang diperiksa

Setelah user menyebut penta-demo, MCP pada server dev yang terdaftar menunjukkan nama aktual `metta-demo-penta` dan pasangan `metta-demo-apotek-penta` pada instance WIN-LOBQ0DEAO40. Database BO terpisah juga terdaftar. Nama penta-demo tidak diasumsikan sebagai target lain tanpa verifikasi.

Pemeriksaan pada 2 November 2026 hanya SELECT metadata, konfigurasi unit yang aman, dan agregat. Tidak mengambil identitas pasien, nilai credential, atau nominal uang.

| Temuan SQL                                                                                                              | Batas kesimpulan                                                                                                                                                                     |
| ----------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| mUnitBisnis pusat memetakan unit Klinik 2 ke FO dan unit Apotek 14 ke APOTEK.                                           | Ini mapping yang tersimpan di database; bukan verifikasi seluruh setting IIS/deployment aktif.                                                                                       |
| SEC025/APOTEK adalah unit14/lokasi30; SEC090/DEPO KLINIK adalah unit14/lokasi101 pada kedua database yang dibandingkan. | Konfigurasi DEPO dibukukan pada konteks teknis Apotek walaupun dipakai layanan Klinik. Ini bukan bukti title legal dari nama/ID saja.                                                |
| mBarangLokasiNew Apotek pada DEPO101 mempunyai 4 baris lot, semuanya kategori BMHP dan UnitBisnisID14.                  | Snapshot kategori stok saat dicek, bukan jaminan seluruh future E-Resep menuju DEPO hanya BMHP.                                                                                      |
| SIMtrPOP Klinik mempunyai 14 transaksi aktif yang sudah Ditagihkan, unit2/sectionSEC001.                                | Bukti pencatatan pemakaian klinis pada Klinik. Tidak menghubungkan setiap baris ke kartu gudang tanpa reference trace tambahan.                                                      |
| Kartu gudang Apotek pada lokasi101 mempunyai 16 movement tipe564 dan 6 movement tipe520, seluruhnya unit14.             | Bukti mutasi stok pada konteks Apotek. Perbedaan jumlah dengan SIMtrPOP bukan bukti mismatch karena scope/history dan pemetaan dokumen belum dibandingkan satu per satu.             |
| Agregat BILLFarmasi yang diperiksa pada kedua database memakai unit14/sectionSEC025; ada bill open dan closed.          | Mendukung atribusi billing farmasi ke unit Apotek pada snapshot ini. ClosePayment dan UnitBisnisID tidak membuktikan seluruh penerimaan sudah settled ke rekening/pendapatan Apotek. |

Pemeriksaan metadata kolom yang ditargetkan pada BILLFarmasi/SIMtrPOP/SIMtrKasir belum menunjukkan pemisahan eksplisit semua role owner/custodian/seller/beneficiary/collector. Sebagian atribusi mungkin melalui mapping atau procedure; hal itu tetap perlu ditelusuri, bukan disimpulkan tidak ada dari satu pencarian.

Definisi procedure/trigger, settlement/posting end-to-end, dan izin fasilitas belum diverifikasi. Tidak menjalankan transaksi pasien, perubahan source, atau write SQL.

## Hindari larangan hukum yang terlalu umum

[Permenkes 11 Tahun 2025, bagian Klinik Pratama, halaman tercetak 82](https://jdih.kemkes.go.id/storage/documents/pdfs/2025permenkes011.pdf#page=82) membedakan klinik yang menyelenggarakan pelayanan kefarmasian melalui instalasi farmasi dan yang tidak. Ketentuan penyimpanan obat kegawatdaruratan/BMHP juga disebutkan.

Karena itu jangan menanam larangan universal semua Klinik tidak boleh mempunyai obat. Profile mengikuti izin, jenis layanan, kategori barang dan kewenangan yang diverifikasi. Model titipan teknis tidak membuktikan suatu susunan usaha/layanan sah; nama DEPO dan konfigurasi software bukan pengganti verifikasi tersebut.

## Acceptance custody dan pembayaran

| ID    | Skenario                                                                         | Hasil wajib                                                                                                                   |
| ----- | -------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| CD-01 | Obat owner Apotek disimpan pada DEPO Klinik.                                     | Lokasi/custodian tercatat, owner/ledger tetap Apotek, tidak otomatis membuat pembelian atau aset milik Klinik.                |
| CD-02 | BMHP Klinik dan obat/BMHP titipan berada pada lokasi fisik sama.                 | Lot, owner, kategori, aturan penggunaan dan provenance tidak tercampur.                                                       |
| CD-03 | Klinik merealisasikan pemakaian dari stok Apotek.                                | Episode klinis dan receipt issue terhubung; satu pengurangan, bukan saldo bayangan atau owner baru.                           |
| CD-04 | Kasir Klinik menerima pembayaran jasa dan obat Apotek.                           | Alokasi ke seller/beneficiary masing-masing jelas, settlement tertunda terlihat, tidak otomatis seluruhnya pendapatan Klinik. |
| CD-05 | Retur/refund setelah source/profile diubah.                                      | Dokumen, owner, lot, dan alokasi kas asal dipakai; tidak memotong atau mengembalikan ke pihak baru.                           |
| CD-06 | Dua unit memakai lot terakhir atau mengulang request.                            | Reserve/issue atomik dan idempotent; tidak double decrement atau fulfillment semu.                                            |
| CD-07 | Klinik tanpa capability/perizinan yang disyaratkan meminta obat di luar profile. | Backend menolak meskipun stok tersedia atau process diagram mengizinkan urutannya.                                            |
| CD-08 | Fasilitas berizin dengan profile pelayanan farmasi berbeda.                      | Tidak ditolak oleh hardcode semua Klinik terlarang; eligibility sesuai profile terverifikasi.                                 |
| CD-09 | Klinik committed, Apotek gagal, atau ack issue hilang.                           | Status pending/gagal dapat direkonsiliasi tanpa duplikasi klinis, stock issue, tagihan, atau penerimaan.                      |
| CD-10 | Tujuan DEPO dipilih pada E-Resep non-BMHP.                                       | Aturan kategori dan kewenangan diperiksa eksplisit; tidak dianggap aman dari nama section.                                    |
| CD-11 | Missing mapping atau authority tidak tersedia.                                   | Tidak fallback ke database/owner lain; data transaksi tetap dapat ditelusuri.                                                 |
| CD-12 | Laporan, print, audit, dan pembukuan.                                            | Unit pelayanan, lokasi, owner, seller, collector, beneficiary dan status settlement tidak disamakan dari satu UnitBisnisID.   |

Persyaratan ini memperluas sharing stok healthcare; ia tidak mengaktifkan bridging bisnis ERP-Faskes atau posting akuntansi baru secara otomatis. Foundation menyimpan policy/reference antarorganisasi. Ledger, uang, perjanjian operasional, dan transaksi tetap dimiliki domain aplikasi yang sesuai.
