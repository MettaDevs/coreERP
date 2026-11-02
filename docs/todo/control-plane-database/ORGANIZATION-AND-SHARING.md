# Fondasi organisasi bersama dan kebijakan berbagi data

Dokumen ini melengkapi [PRD pemisahan database](PRD.md) dengan keputusan 2 November 2026. ERP dan Faskes memakai satu fondasi organisasi. Pilihan siapa berbagi pasien atau stok tidak ditentukan otomatis oleh posisi cabang pada hierarchy.

Statusnya target implementasi. Source Faskes legacy belum membuktikan seluruh mode di bawah aman. Menambahkan konfigurasi tidak membuat capability yang belum ada menjadi tersedia.

Model ini berlaku bagi pengelola pemerintah maupun swasta. [Profil kelompok layanan](SERVICE-GROUP-PROFILES.md) memilih scope pasien, katalog, stok dan pewarisan unit pendukung secara eksplisit. [Pemeriksaan Kuta 1/Mengwi 1](DATABASE-PATTERN-EVIDENCE.md) hanya salah satu studi kasus, bukan syarat bentuk tenant atau pola wajib produk.

## Tetapkan satu master organisasi

Untuk SaaS, **database `core_control_plane` menyimpan master Organization Foundation** bersama metadata control plane. Foundation dan operasi provider merupakan domain berbeda, walaupun database fisiknya sama pada tahap ini. Tidak membuat database Foundation ketiga atau service baru sekarang.

| Data                                                                                                  | Authority dan lokasi target                                                                                                                                        |
| ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Tenant, identity, membership, registry environment                                                    | Domain control plane pada `core_control_plane`.                                                                                                                    |
| Organization ID, nama, status, klasifikasi legal entity/operating unit, identifier umum dan tipe unit | Organization Foundation pada `core_control_plane`, selalu tenant-scoped.                                                                                           |
| Hierarchy purpose, versi, node, effective date, dan hubungan antarorganisasi                          | Organization Foundation pada `core_control_plane`. Closure merupakan projection yang dapat dibangun ulang.                                                         |
| Alamat/kontak organisasi yang merupakan identitas bersama                                             | Foundation sebagai authority. Audit hubungan OrganizationParty dan Address Book sebelum migration; jangan memindahkan seluruh customer/vendor/party transaksi ERP. |
| Metadata kelompok berbagi, policy version, dan deployment acknowledgement                             | Foundation pada `core_control_plane`; tanpa isi data pasien atau saldo stok.                                                                                       |
| Company setup, akun, periode, posting, tax/accounting configuration dan role bisnis ERP               | Ekstensi domain ERP pada database aplikasi. Organization ID mengacu ke Foundation.                                                                                 |
| Tipe fasilitas, provider, konfigurasi layanan, section, local UnitBisnis dan lokasi gudang            | Ekstensi/adapter Faskes pada environment terkait; mengacu ke Organization ID Foundation.                                                                           |
| Master pasien, riwayat klinis, katalog obat, saldo/ledger, reservasi, dan transaksi                   | Database aplikasi pemilik data sesuai policy. Tidak dipindah ke `core_control_plane`.                                                                              |

Source CoreERP saat ini belum menaruh model organisasi pada koneksi pusat. Ini perubahan target yang menggantikan baris PRD lama yang menempatkan seluruh organization/hierarchy sebagai milik ERP. Nama database mengikuti pilihan user, bukan nama produk ERP atau identitas tenant.

Kemampuan directory dan hierarchy tersedia bagi tenant Faskes-only tanpa entitlement atau pemasangan module ERP. Foundation bukan app finansial. User tidak wajib membuat badan hukum atau hierarchy kompleks hanya untuk mencoba satu klinik; buat detail ketika kebutuhan bisnis memang memerlukannya.

Semua create/update/publish organisasi dari admin maupun customer melewati action Foundation yang sama. Provider operator bukan otomatis pengelola semua organisasi customer. Terapkan permission dan tenant context pada API; tabel yang berada di database pusat tidak berarti boleh dibaca lintas tenant.

## Gunakan projection dan binding, bukan master kedua

ERP dan Faskes boleh menyimpan projection readonly untuk FK, UI, laporan, dan kebutuhan lokal. Setiap projection menyimpan authority ID, revision, checkpoint, serta tombstone. Perubahan organisasi dilakukan ke Foundation, bukan mengedit salinan lokal menjadi sumber kebenaran kedua.

Binding legacy harus cukup untuk membedakan tenant, organisasi, produk, environment, jenis objek lokal, dan local ID. `UnitBisnisID=2` pada dua database bukan organisasi yang sama secara otomatis. ID lokal tidak diganti secara massal; tambahkan mapping yang diverifikasi dan rekonsiliasi.

ERP-specific `company_code` dan konfigurasi akuntansi tetap konteks ERP, bukan syarat semua operating unit Foundation. Provider BPJS, kredensial integrasi, SectionID, dan Lokasi_ID tidak menjadi identitas organisasi global. Jangan memetakan berdasarkan nama cabang atau prefix nomor.

Foundation yang sama dipakai lintas produk melalui kontrak. Jika kelak storage Foundation dipisah, kontrak dan ID tetap; implementasi tidak boleh mengasumsikan Foundation selalu satu koneksi dengan control plane.

On-prem perpetual menggunakan authority Foundation lokal dan tidak memerlukan vendor. Satu partisi organisasi mempunyai satu penulis otoritatif. Deployment campuran SaaS/on-prem tidak otomatis memperoleh sinkronisasi dua arah; pilih authority dan kontrak eksplisit sebelum mengaktifkannya.

## Pisahkan organisasi, sharing, akses, dan placement

| Keputusan                   | Pertanyaan yang dijawab                                                    |
| --------------------------- | -------------------------------------------------------------------------- |
| Organization directory      | Unit bisnis mana yang dimaksud?                                            |
| Hierarchy purpose/version   | Bagaimana unit berhubungan untuk tujuan tertentu?                          |
| Sharing policy/domain group | Data mana yang benar-benar dipakai bersama, dan siapa authority-nya?       |
| Authorization grant         | Siapa boleh membaca, menulis, mengeluarkan stok, atau melihat rekam medis? |
| Placement                   | Data otoritatif berada di produk, environment, dan database mana?          |

Klinik A dan B boleh satu parent hierarchy tetapi memisahkan pasien. A dan C boleh berbagi registry pasien, sementara A dan B berbagi sumber stok. Grouping per domain dapat berbeda. Memindahkan node hierarchy tidak otomatis mengubah sharing, grant, pemilik stok, atau database.

Jika UI menyediakan pemilih descendants hierarchy, hasilnya dimaterialisasi sebagai anggota policy pada versi yang disetujui. Perubahan hierarchy berikutnya harus menghasilkan preview perubahan policy, bukan memperluas akses diam-diam.

## Definisikan domain sharing secara terpisah

| Domain               | Pilihan yang direncanakan                                                                     | Batas yang tetap ditegakkan                                                                                                 |
| -------------------- | --------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Patient registry     | Registry per klinik atau registry bersama kelompok klinik.                                    | Registry sama tidak otomatis membuka rekam medis, episode, dokumen, tagihan, dan semua field pasien.                        |
| Clinical records     | Fasilitas asal, akses episode tertentu, atau akses lintas fasilitas yang diberi grant.        | Hak per resource/workflow, provenance fasilitas, policy berlaku sekarang, serta persetujuan yang diwajibkan kebijakan.      |
| Item catalog         | Daftar obat/satuan bersama kelompok atau masing-masing.                                       | Katalog sama tidak menyatukan saldo, harga, lot, atau kepemilikan stok.                                                     |
| Service catalog      | Definisi/kode jasa bersama organisasi pengelola atau kelompok yang dipilih.                   | Tarif, komponen biaya, availability section dan transaksi tidak otomatis sama.                                              |
| Pricing/availability | Ikuti induk atau override field yang disetujui pada kelompok/unit.                            | Version/effective date, pemilik field, serta referensi jasa canonical tetap jelas.                                          |
| Inventory source     | Gudang masing-masing, gudang pusat bersama, atau jaringan lokasi yang dialokasikan eksplisit. | Satu authority ledger/reservasi per stock owner dan lokasi; tidak ada penjumlahan salinan saldo yang dianggap stok tunggal. |

Default tenant baru dipilih dari preset berversi. Pada preset Klinik mandiri, registry terisolasi. Pada profil kelompok layanan, scope pasien adalah kelompok unit utama dan pendukungnya, sedangkan katalog obat/jasa bersama sesuai anggota katalog pengelola. Data lama tidak diubah ke default baru secara diam-diam. Inventaris dan tandai scope legacy yang sudah berbagi sebelum partition atau migration.

Aturan per domain berbentuk konfigurasi bertipe dari mode/capability yang dikenal. Jangan menjalankan SQL/PHP/shell atau menerima nama tabel bebas dari customer. Backend tetap memeriksa scope dan authority meskipun UI menyembunyikan opsi.

## Bedakan mode stok yang sering disebut satu stok

| Mode                      | Contoh                                           | Cara menjaga kebenaran                                                                                                          |
| ------------------------- | ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| Saldo terpisah            | A memakai gudang A, B memakai gudang B.          | Issue memotong ledger lokasi asal masing-masing.                                                                                |
| Satu gudang fisik bersama | A dan B mengonsumsi gudang pusat G1; C tetap G2. | Kedua klinik menunjuk authority saldo G1 yang sama. Konsumsi tetap mencatat organisasi peminta dan pemilik stok.                |
| Jaringan beberapa gudang  | A/B dapat dipenuhi dari G1 atau G2.              | Availability agregat dipisahkan dari alokasi. Reservasi/issue memilih lokasi dan lot nyata, bukan memotong angka total virtual. |
| Melihat total gabungan    | Manajemen melihat total A+B+C.                   | Hak melihat total tidak memberikan hak issue atau transfer pada semua gudang.                                                   |

Semua klinik dapat memakai satu sumber stok hanya bila konfigurasi source, pemilik, dan capability mendukungnya. Pertahankan dimensi item, satuan dasar/konversi, gudang/lokasi, penerimaan, batch, expiry, dan atribut ownership/funding yang berlaku. Hierarchy organisasi bukan pengganti struktur gudang.

Pool lintas legal entity atau stock owner ditolak pada tahap awal. Dukungan berikutnya memerlukan aturan transfer/ownership/valuasi yang eksplisit, bukan checkbox sharing. Pemindahan barang secara fisik tetap transaksi transfer dengan lineage.

Larangan mencampur dua owner tersebut tidak menolak model satu owner Apotek yang menitipkan barang pada custodian/lokasi DEPO Klinik. Pisahkan stock owner, procurement party, lokasi/custodian, unit pelayanan/realisasi, seller, beneficiary, collection cashier dan settlement. Pemakaian klinis atau penerimaan kas di Klinik tidak otomatis mengubah pemilik barang/pendapatan. [Spesifikasi sumber stok dan titipan](SUPPLY-CUSTODY-AND-PAYMENT.md) merinci profile, evidence source Seroja, dan CD-01 sampai CD-12.

Dua klinik yang berebut unit terakhir harus melalui operasi reserve/issue atomik pada authority yang sama. Retry harus idempotent. Pembatalan/retur mengacu pada transaksi, owner, lokasi, dan revision asal; policy baru tidak mengarahkan pengembalian ke pool lain.

## Tetapkan identitas dan akses pasien

Pasien menggunakan ID internal dan namespace registry yang stabil. NRM adalah nomor domain dengan aturan scope yang dipilih; NRM sama pada dua registry tidak membuktikan orang yang sama. NIK/email/nama juga bukan perintah merge otomatis.

Mode berbagi master pasien menetapkan field yang diizinkan. Pisahkan demografi minimum dari riwayat alergi/penyakit, EMR, hasil lab, dokumen, dan episode. Source legacy membaca beberapa atribut tersebut bersama; adapter harus memisahkannya sebelum menawarkan mode berbagi identitas saja.

Registry terpisah berarti pencarian, autocomplete, create/update, pemeriksaan duplicate, list, enrichment, export, print, report, job, upload, dan akses file mematuhi scope yang sama. Jangan membocorkan bahwa pasien pada registry lain ada melalui pesan duplicate atau jumlah hasil.

Authorization baca memakai policy/grant yang berlaku sekarang. Revision yang disimpan pada transaksi adalah provenance, bukan izin permanen untuk memakai akses lama yang sudah dicabut.

## Bentuk metadata policy yang harus dikunci sebelum kode

Nama di bawah adalah rancangan kontrak, bukan tabel/kolom yang sudah tersedia.

| Komponen            | Isi minimum                                                                                                                                                                          |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Policy/group        | ID stabil, tenant, domain, mode yang didukung, member Organization ID, authority produk/environment, owner yang relevan, revision, status, dan effective date.                       |
| Write binding       | Satu authority/group aktif yang tidak ambigu untuk domain dan konteks operasi. Anggota satu unit tidak boleh membuat satu write masuk ke dua registry/ledger sekaligus.              |
| Grant               | Subject/role, organisasi atau resource scope, operasi yang diizinkan, expiry, revision, dan batas profil aplikasi.                                                                   |
| Deployment snapshot | Environment ID, revision/hash policy, hierarchy snapshot bila dipakai, adapter/schema capability, checkpoint, dan acknowledgement.                                                   |
| Operation context   | Tenant, organisasi peminta, environment, domain, authority/group, policy revision, actor, dan idempotency/correlation ID. Nilai berasal dari context tepercaya, bukan request bebas. |
| Migration request   | Asal/tujuan, preview konflik, data/history yang terkena, approval, fencing, backup, validation, dan hasil rekonsiliasi.                                                              |

Active/published policy hanya berlaku setelah adapter target mengakui revision yang kompatibel. Publication yang ditolak atau belum diterapkan tidak boleh dilaporkan sebagai effective. Perubahan pembatasan akses harus menolak akses lama sesuai batas revoke, termasuk ketika acknowledgement belum datang.

Snapshot produksi dan sandbox dipisah. Sandbox memakai snapshot Foundation yang dipin atau draft test namespace terisolasi; perubahan uji tidak memublikasikan struktur atau data sharing ke production. Tidak berbagi patient registry atau stock ledger operasional production secara default.

## Jangan samakan konfigurasi dengan migration data

| Perubahan                                     | Perlakuan                                                                                                                                          |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| Label tampilan atau draft yang belum dipakai  | Perubahan metadata tervalidasi dengan audit.                                                                                                       |
| Memperluas anggota sharing/access             | Preview dampak, otorisasi sesuai domain, revision baru, dan acknowledgement adapter sebelum efektif.                                               |
| Menggabungkan atau memisahkan registry pasien | Operasi migration dengan konflik ID/NRM, referensi episode, file, provenance, dan rekonsiliasi. Tidak auto merge.                                  |
| Memindahkan klinik ke stock pool lain         | Periksa transaksi/reservasi terbuka, saldo, lot, owner, dan alokasi. Gunakan cutover/fencing; tidak sekadar mengubah field.                        |
| Mengganti authority atau database             | Operasi migrasi tersendiri dengan satu writer aktif dan replay receipt.                                                                            |
| Menghentikan sharing                          | Cabut akses masa depan; tidak mengklaim dapat menarik kembali data yang telah dilihat atau diekspor. Histori tetap dipertahankan sesuai kebijakan. |

Transaksi berjalan mengikat revision dan authority yang tervalidasi. Gunakan revision/fencing saat commit agar perubahan policy di tengah proses tidak mengalihkan stok atau melewati revoke. Aturan retry, cancellation, dan rejection stale context harus eksplisit pada setiap operasi.

## Batas legacy dan lintas environment

Source yang diperiksa berada pada checkout DIVA1, HEAD `d9a2606921f2777c54aecf3eac8b051fa31e4232`, bukan bukti runtime Linux identik.

- `Patient_model` memakai mPasien/NRM tanpa registry group pada get/update. `Registration_model::get_patient` juga mengambil riwayat klinis pada lookup master. Mode patient registry terpisah dalam satu DB memerlukan perubahan schema/scope dan audit semua query.
- `section_pharmacy_helper` sudah membedakan section pelayanan dan sumber stok. Itu titik awal resolver, bukan bukti seluruh workflow sharing dan concurrency aman.
- Inventory dan pharmacy masih mempunyai asumsi UnitBisnis/lokasi tertentu. Procedure/trigger SQL Server juga harus diperiksa sebelum capability dinyatakan siap.
- Filesystem, cache, session, pencarian, laporan, dan integrasi eksternal termasuk boundary. Menambahkan group ID pada form tidak cukup.

Target awal sharing pasien dan stock ledger yang benar-benar diterapkan dibatasi pada satu environment Faskes yang capability-nya telah diuji. Profil kelompok layanan dapat memakai satu environment per kelompok layanan beserta unit pendukungnya. Foundation tidak mensyaratkan satu database per unit fisik.

Distribusi reference catalog obat dan jasa lintas environment kelompok adalah capability tersendiri yang wajib tersedia sebelum profil kelompok layanan dipasarkan. Ini berbeda dari sharing pasien atau saldo lintas database. SH-04 menangani authority katalog, mapping/release, field lokal dan rekonsiliasi. Dokumen profil kelompok layanan menentukan batas harga/tarif dan proses layanan yang tidak boleh disamakan dengan nama master.

Jika A dan B berada di database/environment berbeda, policy harus menunjuk authority nyata dan adapter koneksi yang mendukungnya. Shared stock memerlukan reserve/issue pada authority tunggal; jangan mereplikasi saldo bebas ke dua writer. Capability lintas environment tetap nonaktif sebelum tersedia dan teruji. Ini bukan bridging ERP-Faskes yang otomatis ikut dibangun.

Jika kemampuan belum ada, UI/API menolak mode tersebut dan tetap memakai deployment isolasi yang terverifikasi. Jangan menawarkan gabungan sharing yang implementasinya akan diganti fallback global.

## Skenario penerimaan

| ID    | Konfigurasi atau perubahan                                     | Hasil wajib                                                                                                          |
| ----- | -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| OS-01 | Tenant hanya membeli Faskes.                                   | Dapat mengelola organisasi/hierarchy Foundation tanpa entitlement atau instalasi ERP.                                |
| OS-02 | Organisasi diubah dari admin lalu dibaca ERP/Faskes.           | ID/revision sama; projection tidak dapat diedit sebagai master kedua.                                                |
| OS-03 | A/B pasien terpisah, stok masing-masing.                       | Lookup/save/export/file tidak menembus registry; issue memakai ledger asal.                                          |
| OS-04 | A/B pasien terpisah, stok bersama G1; C terpisah.              | A/B tidak berbagi pasien, hanya memotong saldo G1; C tidak dapat issue G1.                                           |
| OS-05 | Registry pasien A+B bersama, clinical records terpisah.        | Demografi yang diizinkan terlihat; riwayat, EMR, episode, dan dokumen klinik lain tetap ditolak tanpa grant.         |
| OS-06 | Katalog obat seluruh klinik sama; saldo masing-masing.         | Perubahan katalog konsisten tanpa menggabungkan saldo atau hak issue.                                                |
| OS-07 | Semua klinik memakai gudang pusat yang diizinkan.              | Satu ledger otoritatif dengan atribusi konsumsi per unit, batch/expiry dan owner benar.                              |
| OS-08 | Pasien A+C berbagi; stok A+B berbagi.                          | Grouping berbeda berlaku tanpa ketergantungan bentuk hierarchy.                                                      |
| OS-09 | Pindah parent hierarchy.                                       | Tidak otomatis mengubah anggota sharing, akses pasien, lokasi stok, atau database.                                   |
| OS-10 | Dua klinik mengeluarkan unit obat terakhir bersamaan.          | Hanya alokasi/issue sah yang berhasil; tidak oversell atau double decrement.                                         |
| OS-11 | Retry, retur, atau pembatalan setelah policy berganti.         | Tidak double decrement; reversal mengacu transaksi dan owner/source asal.                                            |
| OS-12 | NRM sama pada dua registry, atau matching identitas.           | Tidak menautkan pasien atau membocorkan registry lain tanpa operasi yang sah.                                        |
| OS-13 | Policy berubah saat transaksi berjalan.                        | Tidak ada mixed revision; commit stale ditolak atau ditangani sesuai kontrak, tanpa perpindahan authority diam-diam. |
| OS-14 | Merge/split registry atau pool berisi data/reservasi.          | Perubahan diblokir sampai migration plan, preview konflik, dan rekonsiliasi lulus.                                   |
| OS-15 | Restore atau adapter menerima event policy lama.               | Tidak mengaktifkan grant/revision lama; pemulihan memakai authority terbaru.                                         |
| OS-16 | Sandbox mengubah struktur/policy atau melakukan transaksi.     | Production tidak berubah dan tidak ada issue ke stok atau kirim ke integrasi production.                             |
| OS-17 | Pool beda legal entity atau lintas environment belum didukung. | UI dan API menolak, tanpa fallback shared global.                                                                    |
| OS-18 | Salah tenant mengirim organization/group/authority ID.         | Backend menolak tanpa membaca atau memodifikasi data tenant lain.                                                    |

Implementasi variasi berbagi memiliki pekerjaan tersendiri pada [TODO sharing](../metta-platform-portal/SHARING-TODO.md). Split database dan Foundation tidak dianggap menyelesaikan refactor query klinis atau ledger tersebut.
