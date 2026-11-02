# TODO fondasi organisasi dan variasi sharing Faskes

Dokumen ini menjalankan [spesifikasi organisasi dan sharing](../control-plane-database/ORGANIZATION-AND-SHARING.md). Seluruh tugas implementasi belum dimulai. Group sharing adalah batas data bisnis; ia berbeda dari hierarchy, daftar user yang boleh masuk, dan penempatan database.

Master organisasi, hierarchy, dan metadata policy berada pada Organization Foundation di `core_control_plane`. Pasien dan saldo stok tetap pada aplikasi yang menjadi authority. Faskes-only tidak memerlukan entitlement ERP.

## Peta pekerjaan tanpa duplikasi

| Unit                                                   | Owner yang disarankan                     | Dependensi                                                      | Status  | Bukti                                                                                         |
| ------------------------------------------------------ | ----------------------------------------- | --------------------------------------------------------------- | ------- | --------------------------------------------------------------------------------------------- |
| Foundation authority dan kontrak policy                | Owner CPDB-01/02/03                       | Mengikuti TODO CPDB                                             | blocked | Dikerjakan di CPDB, bukan schema baru milik tim Faskes.                                       |
| Binding organisasi dan baseline policy                 | Owner PF-02                               | CPDB-05                                                         | blocked | Mapping tenant/org/environment/local ID dan mode baseline yang diuji.                         |
| SH-01. Registry pasien dan akses klinis                | Healthcare backend + security             | PF-02, PF-04                                                    | blocked | Scope dan matriks OS pasien pada SHA implementasi.                                            |
| SH-02. Katalog dan sumber stok                         | Healthcare inventory + database           | PF-02, PF-04                                                    | blocked | Resolver, ledger authority, concurrency, dan matriks OS stok.                                 |
| SH-03. Perubahan policy yang berisi data               | Platform + owner domain terkait           | SH-01 dan/atau SH-02 sesuai domain yang diubah                  | blocked | Preview, migration, fencing, dan rekonsiliasi sesuai capability yang sudah tersedia.          |
| SH-04. Katalog obat dan jasa organisasi pengelola      | Healthcare reference-data + adapter       | PF-02 dan kontrak CPDB-03 yang diverifikasi CPDB-05             | blocked | Publisher/mapping/release/acknowledgement obat dan jasa lintas kelompok, tanpa pasien/saldo.  |
| SH-05. Preset dan proses kelompok unit utama/pendukung | Healthcare workflow + platform            | PF-02, PF-04, dan domain capability SH-01/02/04 yang dipasarkan | blocked | Assignment, inheritance, profile version dan matriks SG.                                      |
| SH-06. Custody DEPO dan atribusi pembayaran            | Healthcare inventory + billing + security | PF-02, PF-04, SH-02 dan capability billing yang ditawarkan      | blocked | Snapshot role, ledger receipt dan CD-01..12; tidak mengklaim settlement dari unit kasir saja. |

SH-01 dan SH-02 dapat dikerjakan paralel setelah kontrak yang sama dikunci. SH-03 tidak menjadi dependensi awal kedua unit; ia dibutuhkan sebelum user dapat mengubah sharing yang sudah mempunyai data. Jangan membuat siklus dependency.

## Foundation bersama pada CPDB

- [ ] FND-01. Masukkan identity organization, tipe umum, hierarchy purpose/version/node, dan policy metadata ke manifest pusat. Pisahkan atribut finansial ERP dari identitas legal entity generik.
- [ ] FND-02. Audit OrganizationParty/Address Book, company code, hierarchy FK, dan pemakai policy. Catat pemisahan field/projection tanpa memindahkan seluruh master customer/vendor ERP.
- [ ] FND-03. Sediakan create/update/publish Foundation dengan permission tenant-scoped. Bukti OS-01/02/18; tidak ada prasyarat entitlement/module ERP.
- [ ] FND-04. Definisikan domain group, authority, anggota Organization ID, revision/effective date, dan capability. Satu write context mempunyai satu authority yang tidak ambigu.
- [ ] FND-05. Pisahkan production snapshot dari sandbox draft. Publication menunggu acknowledgement compatible adapter; grant restriktif lama tidak hidup kembali karena stale snapshot.
- [ ] FND-06. Sediakan draft profil kelompok layanan dan assignment kelompok unit utama/pendukung dengan versi struktur layanan. Pisahkan drug catalog, service catalog, pricing, clinical scope dan stok; tidak mengunci UnitBisnisID atau jumlah unit.
- [ ] FND-07. Sediakan create/clone/edit draft profile buatan admin sebagai data di core_control_plane, dengan validasi capability, publish immutable revision dan aktivasi ber-acknowledgement. Nama template contoh bukan enum tertutup; profile baru tidak memerlukan class/cabang kode baru jika kemampuan yang dipakai sudah tersedia.

ID FND menguraikan pekerjaan CPDB yang sama. Owner mencatat satu PR/SHA sumber, bukan membuka writer atau migration kedua.

## SH-01. Terapkan batas pasien secara menyeluruh

- [ ] SH-01a. Inventaris query mPasien/NRM, shared clinical fields, procedure/view, episode, enrichment list, duplicate lookup, job, print/export, dan attachment. Catat seluruh entry point, bukan hanya form.
- [ ] SH-01b. Tetapkan patient internal ID, namespace registry, nomor NRM dan collision policy. Jangan auto merge berdasarkan NIK/NRM/email. Pertahankan mapping data lama dan referensi episode.
- [ ] SH-01c. Pisahkan field demografi yang dapat dibagi dari riwayat klinis/EMR/dokumen dan tagihan. Terapkan allowlist data yang jelas, bukan satu flag shared_patient yang membuka semua field.
- [ ] SH-01d. Implementasikan resolver scope yang dipakai read/write/search/report/file dan backend procedure terkait. UI filter tidak menjadi authorization.
- [ ] SH-01e. Backfill legacy group secara eksplisit setelah inventaris. Jangan membuat default isolated baru memutus hubungan historis atau default shared membuka pasien klinik lain.
- [ ] SH-01f. Uji OS-03/04/05/08/09/12/13/15/16/18 pada aplikasi nyata dan SQL Server. Catat negative checks, cache/index/key collision, dan akses file langsung.
- [ ] SH-01g. Aktifkan capability mode registry hanya setelah schema dan seluruh jalur lulus. Mode lintas environment tetap tidak tersedia tanpa authority/adapter yang diuji.

Untuk profil kelompok layanan, isolasi fisik per kelompok unit utama/pendukung dapat menjadi boundary registry. Tidak wajib memaksa semua kelompok masuk satu mPasien tenant-wide dahulu. Mapping ID registry/pasien dan otorisasi route environment tetap diuji. Jika beberapa kelompok dikonsolidasikan pada satu DB, seluruh scope query/procedure harus lulus sebelum mode itu aktif.

## SH-02. Terapkan variasi sumber stok tanpa saldo ganda

- [ ] SH-02a. Inventaris mBarang, satuan/konversi, kartu gudang, penerimaan, batch/expiry, reservasi, stock owner, UnitBisnis/Lokasi hardcode, serta procedure FIFO/transfer/retur.
- [ ] SH-02b. Pisahkan group katalog dari warehouse/stock source. Satu katalog bersama tidak memberi saldo atau hak issue bersama.
- [ ] SH-02c. Definisikan mode saldo terpisah, gudang pusat bersama, jaringan beberapa gudang, dan visibility agregat. Kontrak menentukan alokasi lokasi/lot nyata serta batas owner/legal entity.
- [ ] SH-02d. Gunakan resolver section-ke-sumber-stok pada semua jalur, termasuk BHP poli/lab, penjualan obat bebas/resep, laporan, retur, dan transfer. Ganti hardcode yang bertentangan; jangan hanya memperbaiki menu farmasi.
- [ ] SH-02e. Terapkan reserve/issue atomik dengan receipt idempotency pada authority ledger. Simpan unit peminta, owner, lokasi, item, satuan, lot, transaksi asal, dan revision policy.
- [ ] SH-02f. Uji dua klinik mengeluarkan obat terakhir bersamaan, retry setelah timeout, retur setelah routing berubah, dan transfer gagal sebagian. Tidak mengklaim procedure concurrency aman tanpa mengujinya.
- [ ] SH-02g. Uji OS-04/06/07/08/09/10/11/13/14/15/16/17/18. C tidak dapat issue pool A+B; total agregat tidak menjadi izin konsumsi.
- [ ] SH-02h. Rilis hanya mode yang adapter akui. Jangan membuat shared stock lintas database dengan menyalin saldo ke dua writer. Shared ledger lintas environment merupakan capability terpisah yang tetap tertutup sampai diimplementasikan.

Model titipan Apotek pada lokasi Klinik dijabarkan pada SH-06 dan [spesifikasi custody](../control-plane-database/SUPPLY-CUSTODY-AND-PAYMENT.md). Owner tunggal dengan custodian berbeda tidak dianggap pool dua owner. SH-02 saja tidak membuktikan atribusi seller, uang, atau settlement sudah benar.

## SH-03. Ubah policy tanpa menghapus histori

- [ ] SH-03a. Buat preview perbedaan anggota/scope, data/field yang akan terlihat, konflik ID/NRM, reservasi terbuka, saldo, serta referensi episode dan file.
- [ ] SH-03b. Pisahkan perubahan label/draft dari merge/split registry/pool dan pergantian authority. Perubahan berisi data memakai migration operation, backup, validation, dan approval yang sesuai domain.
- [ ] SH-03c. Terapkan revision/effective date, fencing, acknowledgement, dan rekonsiliasi. Transaksi yang berjalan tidak boleh menulis ke authority berbeda karena konfigurasi baru.
- [ ] SH-03d. Terapkan pencabutan akses dengan policy terkini. Snapshot transaksi lama hanya bukti provenance; bukan grant lama yang dapat dipakai selamanya.
- [ ] SH-03e. Uji OS-11/13/14/15/16/17 dan rollback setelah write baru. Jangan mengklaim stop-sharing menarik kembali data yang sudah diekspor.
- [ ] SH-03f. Catat migration gate untuk authority on-prem atau mixed hosting. Tidak ada dua master writable pada partisi organisasi yang sama.

## SH-04. Distribusikan master obat dan jasa organisasi pengelola

- [ ] SH-04a. Tetapkan satu authority aplikasi untuk setiap domain katalog, field canonical, allowed local overrides, penerima, dan mapping ID lokal. Tidak mengambil authority hanya dari nama IFK atau kesamaan integer ID.
- [ ] SH-04b. Rekonsiliasi katalog sumber legacy, termasuk tambahan barang dan JasaID sama dengan nama berbeda. Preview konflik dan pilihan canonical disetujui pemilik katalog; tidak blind overwrite.
- [ ] SH-04c. Distribusikan release obat/satuan/konversi dan definisi jasa beserta dependency yang diperlukan dengan receipt dan acknowledgement. Jangan menganggap master jasa mencakup seluruh tarif/section/akun lokal.
- [ ] SH-04d. Uji kegagalan penerima, retry, out-of-order revision, rollback katalog, dan authority sementara mati. Snapshot approved tetap jelas; tidak ada release setengah lengkap.
- [ ] SH-04e. Uji SG-03/04/07/08/12 dan pasien antar-kelompok tetap terisolasi. Tidak ikut menyalin mPasien, rekam medis, transaksi, atau saldo stok saat master katalog diperbarui.
- [ ] SH-04f. Publikasi katalog dibatasi pada pengelola yang berwenang. User unit pendukung yang memakai obat/jasa tidak otomatis menjadi pengubah master pengelola katalog.

SH-04 bergantung pada PF-02 dan kontrak Foundation/durable delivery CPDB-03 yang sudah diverifikasi pada CPDB-05. Ia tidak mensyaratkan sharing pasien atau stock ledger lintas environment. Distribusi reference catalog lintas environment adalah capability yang berbeda dan wajib untuk profil kelompok layanan dengan database per kelompok.

## SH-05. Jalankan preset dan proses kelompok layanan

- [ ] SH-05a. Bentuk template jaringan layanan berversi untuk kelompok unit utama/pendukung. Scope pasien antar-kelompok terpisah; unit pendukung mewarisi policy kelompok yang dipilih. Klinik mandiri tidak dihubungkan dari kemiripan nama/alamat.
- [ ] SH-05b. Import kelompok legacy memakai mapping yang direview. Tidak menganggap Pustu=true, UnitBisnisID=2, atau section UnitBisnisID NULL sebagai relasi parent universal.
- [ ] SH-05c. Validasi binding registrasi/poli, routing lab, farmasi/source stok, dan kasir yang adapter dukung. Simpan process profile version serta provenance unit pada episode/operasi.
- [ ] SH-05d. Buat preview untuk perubahan assignment, profile kelompok, atau perpindahan unit. Episode/resep/billing/reservasi berjalan tidak diam-diam berpindah authority; gunakan SH-03 bila data harus dimigrasikan.
- [ ] SH-05e. Uji SG-01/02/05/06/09/10/11/12 serta role/grant. Shared dataset kelompok tidak memberi hak lintas kelompok atau admin otomatis.

SH-05 memakai PF-02, PF-04, dan domain capability yang dipasarkan dari SH-01/02/04. SH-03 hanya diperlukan untuk perubahan berisi data, bukan dependensi pembuatan template kosong. Tidak memasang workflow engine baru; gunakan profile dan action yang mendukung alur nyata serta validasi bisnis aplikasi.

## SH-06. Pisahkan custody, pemakaian klinis, dan penerimaan uang

- [ ] SH-06a. Inventaris peran klinik/poli, procurement party, stock owner, custodian/lokasi, fulfillment unit, seller, beneficiary, collecting cashier dan target BO. Jangan menyimpulkannya dari satu UnitBisnisID, nama DEPO, atau koneksi APOTEK.
- [ ] SH-06b. Definisikan profile yang membedakan BMHP Klinik, BMHP titipan, dan obat resep dengan kategori, perjanjian custody serta kewenangan fasilitas yang diverifikasi. Ganti binding deployment khusus menjadi konfigurasi tervalidasi; jangan mengganti SEC090/lokasi101/unit2/14 menjadi konstanta universal baru.
- [ ] SH-06c. Hubungkan clinical intent, issue/reservation receipt, dan fulfillment melalui operasi idempotent lintas DB bila diperlukan. Uji gagal sebelum/sesudah FIFO, commit klinis, ack dan pembayaran; satu transaction lokal tidak dianggap atomic lintas koneksi.
- [ ] SH-06d. Pisahkan invoice/seller, receivable/revenue beneficiary, cash collector dan settlement allocation. Uji penerimaan gabungan jasa Klinik dan obat Apotek tanpa double revenue atau autoatribusi seluruh uang ke unit kasir.
- [ ] SH-06e. Simpan snapshot owner/custodian/source/lot/pihak finansial/profile dan origin environment. Retur, batal dan refund merujuk dokumen asal meskipun routing sudah berubah.
- [ ] SH-06f. Terapkan kategori dan eligibility pada API/backend, bukan hanya lookup. Missing authority/binding harus pending/ditolak tanpa fallback ke owner atau database lain.
- [ ] SH-06g. Jalankan CD-01 sampai CD-12 dengan laporan, print, audit, concurrency dan recovery. Source Seroja menjadi evidence reuse, bukan bukti settlement live atau legal compliance.

SH-06 memakai PF-02, PF-04, SH-02, serta capability billing/collection yang benar-benar dipasarkan. Metadata profile dapat dirancang paralel, tetapi mode custody tidak dirilis sampai ledger/financial routing yang dibutuhkan lulus. Ini integrasi internal healthcare, bukan pengaktifan bridging ERP-Faskes.

## Gate rilis portal dan konfigurasi

- [ ] PF-03 membaca capability serta effective policy revision untuk menampilkan pilihan yang benar-benar tersedia. API menolak mode yang tidak didukung walaupun request dibuat manual.
- [ ] PF-06 menguji matriks konfigurasi yang dipasarkan. Rilis satu klinik dengan mode baseline tidak boleh mengklaim seluruh variasi multi-klinik sudah tersedia.
- [ ] Jika rilis menawarkan pasien terpisah dan stok bersama, SH-01 dan SH-02 beserta OS-04 wajib lulus lebih dahulu. Tidak ada fallback shared global untuk melewati gate.
- [ ] Form admin/customer menjelaskan perbedaan berbagi identitas pasien, rekam medis, katalog obat, sumber stok, dan melihat total. Jangan menyatukannya menjadi satu checkbox berbagi semua.
- [ ] Pengaturan hierarchy dan policy tidak mengubah database secara otomatis. Perpindahan placement mempunyai operasi dan gate sendiri.
- [ ] Profil kelompok layanan menjalankan SG-01 sampai SG-14 dan SH-04/05 beserta capability pasien/stok yang ditawarkan. Metadata default tersimpan tidak dianggap implementasi proses kelompok dan distribusi master.
- [ ] Mode titipan DEPO Klinik dengan owner/penerima hasil Apotek memerlukan SH-06 dan CD yang relevan. Jangan menyebut seluruh realisasi Klinik otomatis pendapatan Apotek tanpa bukti transaksi/settlement.

## Catat keputusan dan bukti

| Tanggal    | Keputusan                                                                                                                     | Status implementasi                  |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ |
| 2026-11-02 | Database bernama core_control_plane; Organization Foundation bersama tanpa pembelian ERP; sharing per domain yang independen. | Target PRD, belum diimplementasikan. |

Isi owner aktual, PR/SHA, policy/schema version, hasil OS yang diuji, known gaps, dan evidence path saat pekerjaan diambil. Jangan mencentang tugas dari kelulusan formatter atau build dokumentasi saja.
