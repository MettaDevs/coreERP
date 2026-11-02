# PRD pemisahan database control plane dari CoreERP

Database `core_control_plane` menyimpan pengelolaan layanan serta Organization Foundation bersama untuk ERP dan Faskes. Database ERP menyimpan pekerjaan bisnis dan ekstensi finansial suatu environment. PRD ini menetapkan batas tersebut tanpa sekaligus membangun service baru.

Status adalah target yang disetujui secara arah oleh user pada 2 November 2026. Detail implementasi harus memenuhi inventaris dan gate di bawah. Belum ada migration atau cutover dari pekerjaan dokumentasi ini.

## Masalah dan hasil yang diperlukan

Control plane dan CoreERP saat ini memakai database pusat yang sama. Source menyediakan marker `OwnedByControlPlane`, tetapi sejumlah relasi, transaksi, dan routing masih menganggap seluruh tabel berada pada koneksi bawaan.

Platform akan mengelola Faskes serta produk lain. Penambahan tenant tidak boleh mensyaratkan company atau pemasangan ERP. Kegagalan schema bisnis ERP juga tidak boleh diatasi dengan memasukkan data pasien atau transaksi ke database platform.

Hasil selesai yang dapat diperiksa adalah dua database fisik berbeda, dengan ownership dan role akses jelas, alur admin/Core/Faskes tetap benar, dan tidak ada fallback tersembunyi ke tabel pusat lama. Bukti hanya konfigurasi env tidak cukup.

## Ruang lingkup

| Termasuk                                                                       | Tidak termasuk                                                                |
| ------------------------------------------------------------------------------ | ----------------------------------------------------------------------------- |
| Database control plane tersendiri, koneksi dan role eksplisit.                 | Memecah repository atau membuat microservices untuk module ERP.               |
| Inventaris schema, writer, FK, query, trigger, dan transaksi lintas batas.     | Memindahkan semua tabel pada folder Platform secara membabi buta.             |
| Pemisahan metadata pusat dari proyeksi dan role bisnis lokal.                  | Mengganti SSO menjadi direktori tenant organisasi.                            |
| Migration ownership, copy data, validasi, cutover, rollback, dan rekonsiliasi. | Menulis ke Windows dev1, Bitbucket, atau produksi legacy.                     |
| Menjaga on-prem perpetual serta isolasi aplikasi yang sudah ada.               | Membangun billing atau public trial ERP.                                      |
| ID stabil dan batas kontrak yang mendukung integrasi produk kelak.             | Bridging transaksi, jurnal, barang, stok, pasien, atau pembayaran ERP-Faskes. |

Sesuai AGENTS, CoreERP belum mempunyai customer eksternal sungguhan. Cutover terjadwal dengan maintenance terbatas menjadi pilihan awal. Ini tidak berarti sistem legacy tidak mempunyai customer; sistem tersebut berada di luar target migrasi ini.

## Pemakai dan kepemilikan

- Operator Metta mengelola tenant dan operasi platform melalui admin console.
- Customer mengelola organisasi dan aplikasi yang diizinkan melalui portal yang direncanakan.
- Runtime ERP melayani transaksi lokal dan security bisnis environment.
- Adapter produk menjalankan operasi khusus engine, termasuk SQL Server Faskes.
- SSO membuktikan identitas, sedangkan platform dan aplikasi menegakkan izin pada batas masing-masing.

Konsol tetap tidak mempunyai migration mandiri. Migration pusat dan bisnis dimiliki source Core, dengan target koneksi dan history yang berbeda. Satu tabel hanya mempunyai satu writer otoritatif dan satu jalur migration otoritatif.

## Bukti keadaan awal

Audit source menggunakan HEAD `468efde9fa58ce8f0a4a8008c1fb606e4516ee11`. Pemeriksaan runtime pada percakapan 2 November 2026 terakhir menunjukkan Core dan konsol sama-sama memakai database `core_erp`; CPDB-01 tetap harus merekam ulang koneksi dan schema saat implementasi dimulai.

| Sumber                                                                                          | Temuan yang memengaruhi desain                                                                                                           |
| ----------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `apps/core/app/Platform/ControlPlane/OwnedByControlPlane.php`                                   | Koneksi pusat opsional; kosong mengikuti koneksi bawaan.                                                                                 |
| `apps/core/app/Platform/Environment/Support/EnvironmentConnection.php`                          | `controlPlane()` masih mengembalikan default; database environment kosong mengikuti koneksi itu. `pins()` juga mengatur store framework. |
| `Platform/Tenant/Actions/RegisterBusiness.php` dan `RedeemInvitation.php`                       | Transaksi default menulis calon data pusat dan lokal. Setelah split, transaksi itu tidak otomatis atomic pada dua database.              |
| `Platform/Tenant/Models/TenantMembership.php` dan `Platform/Access/Support/AccessGuards.php`    | Relasi dan `whereHas` menuju role assignment lokal perlu diganti.                                                                        |
| `Platform/Modules/Support/LaunchableAppCatalog.php`                                             | Query permission menggabungkan beberapa tabel. Resolusi koneksi harus diperiksa per query.                                               |
| `Platform/Access/Models/Role.php`, `RoleAssignment.php`, dan `Tenant/Models/InvitationCode.php` | Tidak bertanda pusat. Role ERP tidak boleh dipindah hanya karena melibatkan tenant atau user.                                            |
| `tests/Feature/Boundary/BatasPusatTest.php` dan `FkMenyeberangBatasTest.php`                    | Penanda dan budget FK adalah bahan audit, bukan inventaris schema runtime yang lengkap.                                                  |
| `Platform/ControlPlane/Products/ProductRegistrationSigningKey.php`                              | Key tiket disimpan terenkripsi. Copy ciphertext memerlukan key dekripsi yang tetap sesuai.                                               |

Pemisahan tidak cukup dengan mengubah `COREERP_CONTROL_CONNECTION` atau `DB_DATABASE`. Pernyataan lama yang menyederhanakan hal tersebut digantikan oleh prasyarat PRD ini.

## Pilihan arsitektur

| Kandidat                                                             | Manfaat                                                         | Konsekuensi                                                                                                                 | Keputusan                                            |
| -------------------------------------------------------------------- | --------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| Database terpisah, repository dan deployment sekarang dipertahankan. | Mengunci ownership penyimpanan tanpa migrasi service sekaligus. | Routing, privilege, query, dan commit lintas database tetap harus dibereskan. Process dan host belum sepenuhnya terisolasi. | Dipilih untuk program ini.                           |
| Database dan service platform independen sekaligus.                  | Rilis dan isolasi process dapat berdiri sendiri.                | Menambah autentikasi service, network dependency, dan cutover deployment bersamaan.                                         | Ditunda sampai kebutuhan operasional membuktikannya. |
| Schema berbeda di database yang sama.                                | Pemindahan lebih kecil.                                         | Bukan pemisahan database yang diminta; ordinary join dan privilege dapat tetap mengaburkan ownership.                       | Tidak menjadi target akhir.                          |

Nama database yang dipilih user adalah **`core_control_plane`**, disetel melalui konfigurasi. Database bisnis ERP pooled tetap terpetakan secara eksplisit ke `core_erp` atau nama yang dicatat dalam manifest. Nama database tidak menjadi ID organisasi maupun environment.

Produk Faskes berlaku bagi pengelola pemerintah maupun swasta. Gunakan identifier generic seperti organization, facility, service_group, catalog dan policy. Tidak mensyaratkan instansi pemerintah, parent tertentu, atau ERP untuk memperoleh capability Foundation. Bukti dengan nama database legacy dipertahankan apa adanya, tidak dijadikan template schema baru.

Organization Foundation merupakan domain tenant-scoped tersendiri pada database tersebut. Ia menjadi authority tunggal identity organisasi dan hierarchy lintas produk, tersedia tanpa entitlement ERP. Penempatan ini tidak memasukkan pasien, saldo stok, atau seluruh data bisnis ke control plane. Detail authority dan policy ada pada [fondasi organisasi dan sharing](ORGANIZATION-AND-SHARING.md).

## Kebutuhan fungsional

### FR-01. Inventaris ownership harus lengkap

Sebelum cutover, semua tabel, sequence, index, FK, trigger, view, fungsi, store framework, dan writer yang ditemukan harus diklasifikasikan. Gunakan model, seluruh migration yang terpasang termasuk module, dan katalog PostgreSQL aktual. Tidak boleh ada objek atau writer belum diklasifikasikan pada manifest cutover.

Kelompok berikut hanya seed inventaris, bukan daftar pemindahan final.

| Target                                          | Kelompok dan aturan                                                                                                                                                                                                                          |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Control plane                                   | Clients, canonical tenants, identity lokal, external identity, tenant membership, provider access, registry environment/operation, sites dan lisensi/operasinya, console settings/audit, serta record product customer dan managed Faskes.   |
| Control plane setelah kontrak dikunci           | Hak komersial produk, environment grant, undangan organisasi dan authority pengelola tenant. Periksa model yang belum memakai marker seperti `TenantAppEntitlement` dan tabel tanpa model seperti `environment_members`.                     |
| Organization Foundation pada core_control_plane | Identity organization, generic legal-entity/operating-unit profile, hierarchy purpose/version/node, metadata sharing policy dan binding authority. Faskes-only tidak memerlukan entitlement ERP.                                             |
| Environment ERP                                 | Ekstensi finansial legal entity/company, transaksi, party bisnis ERP, nomor, pemasangan module, katalog security bisnis, role/duty/assignment, dan data-policy scope bisnis. Master generik organisasi/hierarchy berada di Foundation pusat. |
| Proyeksi lokal                                  | Referensi tenant/principal/membership yang diperlukan FK dan audit lokal; snapshot akses yang terbatas. Ini turunan yang tidak dapat diedit sebagai sumber authority kedua.                                                                  |
| Harus diklasifikasikan eksplisit                | Invitation lama yang membawa role bisnis, session, password reset, cache, queue, locks, scheduler, notification, dan tabel infrastructure lainnya. Tidak menebak dari nama folder.                                                           |

### FR-02. Routing koneksi tidak boleh ambigu

Pisahkan resolusi koneksi control, bisnis environment, dan maintenance/provisioning. Environment dengan `database_name=null` tetap berarti business database pooled yang diketahui, bukan database pusat baru. Koneksi maintenance bukan credential web runtime.

Audit `for()`, `register()`, `pins()`, semua koneksi store framework, CLI, queue worker, scheduler, installer, salinan sandbox, dan module migration. Perpindahan context tidak boleh mengubah koneksi model pusat secara diam-diam.

### FR-03. ID dan histori tetap stabil

Pertahankan tenant ID, environment ID, instance Faskes UUID, subject SSO, local binding, slug/URL, expiry, primary key pelaku audit, revision, dan operation receipt. Pertahankan tipe ID yang sudah ada. Jangan mengubah semua ID menjadi UUID dalam proyek ini atau menghubungkan akun berdasarkan email.

### FR-04. FK dan query lintas batas diganti dengan kontrak eksplisit

Database bisnis tidak melakukan ordinary join/FK ke database pusat. `dblink` atau FDW bukan jalan pintas untuk mempertahankan dependency tersembunyi.

Jika FK lokal dibutuhkan, arahkan ke proyeksi lokal dengan ID otoritatif yang stabil. Manifest menetapkan field, revision, checkpoint, pembaruan, tombstone, rekonsiliasi, dan pemiliknya. Data proyeksi tidak menyimpan password atau secret SSO. Query audit lokal menggunakan referensi aktor lokal, bukan lookup pusat pada setiap baris transaksi.

Proyeksi authorization juga memerlukan validitas dan perilaku revoke, bukan sekadar copy tabel. Tidak ada tombol mengubah canonical tenant atau membership melalui tabel proyeksi ERP.

### FR-05. Kewenangan platform berbeda dari role bisnis

Platform memutuskan siapa anggota organisasi, siapa boleh mengelolanya, dan siapa boleh masuk environment. ERP menentukan izin bisnis setelah akses environment sah. Role Owner ERP dan `OwnerRoleDuties` tetap mengikuti kontrak ERP, bukan dipindahkan seluruhnya menjadi role platform atau klinis Faskes.

Authority pengelola tenant tetap melalui mekanisme security role yang dibatasi domain pengelolaan, tanpa mengembalikan `system_role` pada membership. CPDB-01 menetapkan catalog/assignment pengelolaan dan pemetaan identity yang terpisah dari assignment ERP. Undangan organisasi dipisahkan dari grant role bisnis lama; jangan copy pivot role lokal ke undangan pusat tanpa validasi.

### FR-06. Operasi lintas database tahan gagal

Tenant/membership pusat dan operasi yang akan dikirim disimpan dalam transaksi control yang sama. Adapter menulis perubahan lokal beserta receipt idempotency dalam transaksi lokal. Acknowledgement dan rekonsiliasi menyelesaikan status pusat kemudian.

Outbox atau jaminan durable yang setara diperlukan pada batas dua database, meskipun masih satu process. `afterCommit` tanpa catatan pekerjaan tahan gagal bukan pengganti. Konsumen harus menangani duplikat, urutan revision, retry, dan hasil worker lama.

Penerimaan undangan boleh sukses di pusat sementara pembuatan akun lokal gagal. Akses environment tetap pending/blocked sampai receipt lokal sah. Retry melanjutkan operation yang sama, tidak membuat shared owner atau membership ganda.

Tidak menjanjikan transaksi PDO tunggal, exactly-once delivery, atau rollback global otomatis. Kompensasi didefinisikan per operasi; tidak menghapus database berisi data bisnis sebagai kompensasi timeout.

### FR-07. Role database dan privilege mengikuti tujuan

Pisahkan pemilik schema/migration, role aplikasi control, role bisnis ERP, serta credential provisioning. Role control tidak membaca transaksi ERP; role ERP tidak menulis tabel canonical control. Credential maintenance tidak berada pada frontend atau session customer.

Jika satu runtime masih memegang dua credential untuk melayani dua domain, nyatakan pengecualian process tersebut pada threat model dan batasi routing penulisnya. Proyek ini tidak boleh mengklaim bahwa kompromi process itu sudah terisolasi sepenuhnya.

Uji CONNECT, schema usage, table privilege, sequence, default privilege, role inheritance, dan kemungkinan akses melalui PUBLIC. Pisahkan nama database saja tidak cukup.

### FR-08. Secret dan key dipindahkan tanpa diganti sembarangan

Pertahankan ciphertext bersama kemampuan dekripsi yang sah, key ring, signing `kid`, dan korespondensi public/private key. Verifikasi dekripsi tanpa mencatat nilainya. Jangan merotasi APP_KEY, secret SSO, credential database fasilitas, atau signing key hanya untuk mempermudah copy.

Restore environment tidak menyalin credential platform. Backup pusat dan backup bisnis mempunyai scope serta prosedur pemulihan berbeda.

### FR-09. Kegagalan pusat mempunyai batas perilaku

Pembuatan tenant, perubahan grant, privileged administration, dan launch baru ditolak bila authority tidak dapat diverifikasi. Jangan fallback ke salinan canonical lama.

Target awal mempertahankan batas revoke portal maksimal 60 detik. Session bisnis hanya boleh menggunakan snapshot/grant sah sampai expiry dengan maksimum staleness 60 detik; setelah itu akses terlindungi ditolak jika refresh tidak tersedia. Perubahan angka ini memerlukan keputusan keamanan dan perubahan acceptance test.

Artinya kegagalan pusat yang lama dapat membatasi session bisnis. Jangan menjanjikan aplikasi hidup tanpa batas saat pusat mati sekaligus global revoke langsung. Hindari query pusat pada setiap transaksi dengan refresh terbatas, connection pool terpisah, timeout, backoff, dan retry budget. Buktikan tradeoff melalui fault injection.

On-prem perpetual adalah mode berbeda, dengan authority lokal dan tanpa TTL yang mengharuskan koneksi vendor.

### FR-10. Migration mempunyai satu pemilik per schema

Source migration tetap di Core, tetapi migration pusat, baseline bisnis, dan migration module diarahkan ke target dan history masing-masing. Command baru diberi nama saat desain detail; jangan menulis command yang belum ada sebagai prosedur operasional yang bisa dipaste.

Bootstrap database baru dan upgrade database lama harus sama-sama diuji. Jangan menjalankan ulang seluruh migration historis ke control DB baru jika itu ikut menciptakan tabel bisnis. CI harus memakai database berbeda secara nyata, termasuk guard teardown agar `migrate:fresh` tidak menyentuh database kerja.

### FR-11. Takedown dan desired state tetap berlaku

Enrollment OFF, suspend, expiry, revoked membership, dan lease/revision tidak boleh kembali aktif akibat copy atau restart. Pertahankan keputusan terakhir untuk DivaPraktik dan jangan menghidupkannya sebagai smoke fixture. Gunakan fixture terpisah.

### FR-12. On-prem perpetual tetap mandiri

Deployment on-prem tetap dapat menyimpan metadata lokal yang diperlukan dan bekerja tanpa control DB vendor. Ia tidak menerima direktori operator SaaS atau secret seluruh tenant. Uji installer, login, module migration, entitlement/lisensi, backup, dan update tanpa koneksi vendor.

### FR-13. Organisasi dan hierarchy menjadi fondasi bersama

Pindahkan authority identity organisasi generik, operating-unit profile, hierarchy purpose/version/node, dan metadata policy ke Organization Foundation pada `core_control_plane`. Pertahankan ID serta versi historis. ERP dan Faskes memakai kontrak serta projection lokal yang sama; tidak membuat master organisasi kedua.

Pisahkan generic legal-entity identity dari company setup/accounting ERP. Pisahkan metadata provider/layanan Faskes dari directory bersama. Audit OrganizationParty dan Address Book saat memisahkan field/relasi; jangan memindahkan seluruh party pelanggan/vendor ERP hanya karena organisasi memakai alamat.

Faskes-only dapat membuat unit/hierarchy tanpa membeli atau memasang ERP. Tenant, directory organisasi, hierarchy, sharing policy, grant, dan placement tetap konsep berbeda. Mengubah parent hierarchy tidak otomatis mengubah akses pasien, stok, atau database.

Ini perubahan target dari keputusan PRD sebelumnya yang mempertahankan seluruh organizations/hierarchy sebagai data ERP. Source saat ini belum mengimplementasikan authority bersama tersebut. On-prem mempunyai authority Foundation lokal untuk partisinya, tanpa dual-write vendor.

### FR-14. Aturan sharing harus independen per domain

Modelkan patient registry, akses clinical records, item catalog, service catalog, pricing/availability layanan, dan inventory source sebagai kebijakan terpisah yang berversi. Group memakai Organization ID eksplisit, tenant/environment boundary, authority, capability, effective date, dan acknowledgement. Satu context write tidak boleh mempunyai dua authority yang ambigu.

Dukung secara desain A/B pasien terpisah tetapi stok bersama; registry pasien bersama dengan EMR terpisah; katalog obat bersama dengan saldo berbeda; A+C berbagi pasien sementara A+B berbagi stok; serta gudang pusat untuk semua klinik yang diizinkan. Visibility total stok, hak issue, dan perpindahan barang tetap berbeda.

Metadata dapat dikonfigurasi hanya untuk mode yang adapter telah dukung. Enforcement pasien terpisah pada query legacy dan transaksi stock pool merupakan pekerjaan healthcare tersendiri pada [TODO sharing](../metta-platform-portal/SHARING-TODO.md), bukan dianggap selesai karena migration database platform lulus.

Merge/split registry atau pool yang berisi data adalah migration operation. Wajib preview konflik, history, outstanding reservation, backup, fencing, dan rekonsiliasi. Perubahan policy tidak otomatis menggabungkan pasien, mengalihkan retur ke stok baru, atau menghidupkan grant lama setelah restore. Production dan sandbox tidak berbagi data operasional secara default.

### FR-15. Profil jaringan layanan netral terhadap pengelola

Faskes melayani pemerintah, swasta, yayasan, dan bentuk pengelola lain. Tidak ada syarat tenant harus berbentuk instansi pemerintah, memiliki unit utama/pendukung, atau membeli ERP. Ownership sector bila dibutuhkan hanya metadata, bukan sumber permission atau penentu proses/sharing otomatis.

Gunakan konsep generik organization, facility, service group, patient registry, catalog authority, stock owner/custodian, serta process profile. Profile single_facility dan service_network memakai model yang sama. Bentuk pengelola tidak menjadi prefix nama tabel, field, event, route, atau identifier template inti.

Profile kelompok terisolasi dapat memisahkan pasien antar-kelompok, berbagi katalog obat/jasa, dan memberi unit pendukung policy kelompok yang dipilih. Ini salah satu konfigurasi, bukan default wajib semua customer. Master jasa tidak otomatis menyamakan tarif, section availability, komponen biaya, atau transaksi. Katalog obat sama tidak berarti saldo/owner bersama.

Assignment dan policy berasal dari profile yang dipilih serta versi struktur layanan. Perubahan hubungan unit atau proses berjalan dievaluasi sebagai operasi berversi, bukan update parent tanpa dampak data. Tidak mengunci jumlah fasilitas, UnitBisnisID, jenis badan usaha, atau folder source per customer.

Placement terisolasi per service group merupakan pilihan yang cocok dengan legacy, bukan kewajiban sektor tertentu atau database baru per unit fisik. Katalog didistribusikan dari authority aplikasi yang eksplisit ke projection lokal; capability lintas environment tidak otomatis membuka pasien/ledger lintas environment.

Lihat [profil kelompok layanan](SERVICE-GROUP-PROFILES.md) untuk SG-01 sampai SG-14 dan [bukti SQL](DATABASE-PATTERN-EVIDENCE.md) sebagai salah satu studi kasus pemerintah. Matriks pemerintah dan swasta harus sama-sama diuji. Data, ID database, dan nama fasilitas sumber tidak diganti untuk menyamarkan bukti; yang dinetralkan adalah model target.

### FR-16. Sumber stok dan atribusi finansial tidak disamakan dengan unit pelayanan

Dynamic stock source harus mendukung binding terpisah untuk requesting/clinical org, procurement party, stock owner, custodian/lokasi, fulfillment, seller/billing issuer, beneficiary, collection cashier, dan settlement. Contoh target adalah stok milik Apotek di DEPO Klinik, dengan pemakaian klinis tercatat di Klinik dan atribusi tagihan yang tetap sesuai profile. Ini custody satu owner, bukan penggabungan stok dua pemilik.

Pisahkan BMHP Klinik, BMHP titipan, dan obat resep melalui kategori serta profile fasilitas/layanan yang terverifikasi. Jangan menanam larangan universal semua Klinik tidak boleh mempunyai obat, atau menganggap nama DEPO berarti semua jalur hanya BMHP. Audit source menunjukkan kategori khusus pada jalur standalone BMHP, tetapi tidak membuktikan pembatasan tersebut pada seluruh E-Resep.

Snapshot routing, ownership, lot, legal/profile reference dan atribusi finansial dipertahankan pada transaksi. Penerimaan uang, issue stok dan clinical realization mempunyai state/receipt berbeda; satu database transaction lokal tidak menjamin commit koneksi lain. Retur/refund memakai pihak serta dokumen asal.

Lihat [spesifikasi custody dan pembayaran](SUPPLY-CUSTODY-AND-PAYMENT.md). Capability mode ini diuji pada CD-01 sampai CD-12 sebelum ditawarkan. Penambahan metadata platform atau pemilihan Flowable tidak membuktikan ledger, settlement, atau kelayakan fasilitas sudah ditegakkan.

## Siap untuk bridging kemudian

Control plane mempertahankan hubungan `tenant -> fasilitas/instance -> environment -> product` dengan ID stabil. Nama server, nama database, domain, dan email bukan foreign key integrasi.

Ketika bridging benar-benar dibangun, koneksi antarproduk harus menyebut source/target environment, izin tenant, dan mapping company/legal entity ERP yang eksplisit. Tenant sama tidak berarti semua data klinis boleh dikirim ke ERP.

PRD ini hanya mengunci batas tersebut. Tidak menambah synchronizer, event penjualan, mapping akun, broker, shared transaction table, atau kredensial bridging yang belum dipakai. Transaksi bisnis kelak berjalan lewat kontrak produk, bukan melalui database control plane sebagai tempat transit data pasien atau jurnal.

## Kriteria penerimaan

| ID    | Bukti wajib                                                                                                                                                                                                                    |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| AC-01 | Core/konsol dan runtime ERP menunjukkan database nyata yang berbeda melalui `current_database()` dan role yang sesuai.                                                                                                         |
| AC-02 | Manifest tidak mempunyai objek/writer/FK/query lintas batas yang belum diklasifikasikan. Role salah gagal melakukan operasi negatif yang diuji.                                                                                |
| AC-03 | Tenant, identity, daftar environment, grant, audit, dan signing/dekripsi tetap benar; tidak ada ID atau masa trial berubah.                                                                                                    |
| AC-04 | Transaksi ERP, module migration, pooled ERP, demo/sandbox, worker, cache/session/queue memakai database yang tepat. Tidak ada data bisnis baru masuk control DB.                                                               |
| AC-05 | Crash sebelum/sesudah commit pusat dan lokal dapat dipulihkan tanpa duplikasi atau akses pending terbuka.                                                                                                                      |
| AC-06 | Revoke, suspend, expiry, restore, serta batas staleness lulus pada dua database dan credential berbeda.                                                                                                                        |
| AC-07 | Backup/copy/sequence/checksum, key dekripsi, dan rollback sebelum/selepas write baru dibuktikan melalui rehearsal.                                                                                                             |
| AC-08 | On-prem tetap mandiri tanpa koneksi vendor. Windows/Bitbucket dan sistem produksi legacy tidak ditulis.                                                                                                                        |
| AC-09 | Readiness launch berasal dari hasil operasi, entitlement aktif, membership, dan grant yang sah. Katalog produk tidak dianggap pemasangan.                                                                                      |
| AC-10 | Plan portal dan dokumen historis menunjuk urutan baru tanpa mengklaim pemisahan sudah terjadi.                                                                                                                                 |
| AC-11 | Faskes-only dapat mengelola directory/hierarchy Foundation tanpa entitlement atau pemasangan ERP. Nama database aktual target adalah core_control_plane.                                                                       |
| AC-12 | Admin, ERP, dan Faskes membaca Organization ID/revision yang sama. Projection tidak menjadi master writable kedua; atribut finansial/provider tetap pada domain produk.                                                        |
| AC-13 | Metadata domain group, authority, revision, acknowledgement dan capability diuji. Policy yang belum didukung ditolak oleh UI dan API.                                                                                          |
| AC-14 | Matriks OS-01 sampai OS-18 dipetakan pada release capability. Case pasien/stock pool hanya dinyatakan siap setelah enforcement SH terkait lulus, bukan dari metadata saja.                                                     |
| AC-15 | Profil kelompok layanan dan peta capability SG-01 sampai SG-14 diuji; mode jaringan layanan ditawarkan hanya setelah enforcement kelompok, reference catalog obat/jasa, dan process profile yang dipasarkan benar-benar lulus. |
| AC-16 | Policy metadata custody/financial attribution divalidasi; mode Apotek-DEPO hanya ditawarkan setelah SH-06 serta CD yang relevan membuktikan clinical, stock, billing/collection dan settlement sesuai kontrak.                 |

Angka performa, durasi maintenance, RPO, dan RTO ditetapkan setelah baseline/rehearsal CPDB-01 dan CPDB-04. Tidak ada kapasitas atau zero-downtime yang diklaim dari dokumen ini. Revoke 60 detik adalah target kontrak, bukan hasil pengukuran.

## Referensi keputusan

[REFERENCES](REFERENCES.md) memetakan setiap pola ke alasan, penggunaan, dan keterbatasan di Metta. Mengikuti pola terdokumentasi bukan sertifikasi vendor atau bukti bahwa Metta memakai implementasi internal yang sama dengan perusahaan tersebut.
