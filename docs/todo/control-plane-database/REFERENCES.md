# Referensi arsitektur dan bahan belajar

Materi ini diperiksa pada 2 November 2026. Referensi berasal dari dokumentasi resmi Microsoft, AWS, Google, dan PostgreSQL. Penerapan pada Metta adalah keputusan desain tim, bukan klaim bahwa perusahaan tersebut menggunakan schema atau deployment internal yang sama.

Dokumen rekomendasi berbeda dari bukti implementasi produk. Contoh AWS di bawah adalah referensi arsitektur, bukan sensus cara seluruh perusahaan menyimpan database. Tidak ada sertifikasi vendor yang diberikan oleh rencana ini.

## Pelajari batas control plane dan application plane

**Microsoft Azure Architecture Center.** [Considerations for multitenant control planes](https://learn.microsoft.com/en-us/azure/architecture/guide/multitenant/considerations/control-planes#isolate-your-control-plane-from-tenant-workloads).

Microsoft merekomendasikan pemisahan resource pengelolaan dari workload tenant. Tujuannya membatasi pengaruh gangguan dan privilege. Untuk Metta, metadata platform dipisahkan dari transaksi ERP. Database pada cluster yang sama hanya memenuhi batas penyimpanan dan role; isolasi host/process penuh tetap belum tercapai. Pembagian resource lebih jauh harus didukung kebutuhan operasi dan bukti risiko.

**AWS SaaS Architecture Fundamentals.** [Control plane vs. application plane](https://docs.aws.amazon.com/whitepapers/latest/saas-architecture-fundamentals/control-plane-vs.-application-plane.html).

AWS membedakan pengelolaan SaaS global dari fungsi aplikasi yang dipakai tenant. Pada Metta, platform memerintah provisioning dan adapter produk mengerjakan detail engine. Ini tidak mengharuskan satu microservice untuk setiap module atau satu database untuk setiap tabel. SSO tetap komponen autentikasi, bukan database transaksi platform.

**AWS Prescriptive Guidance.** [Manage tenants across multiple SaaS products on a single control plane](https://docs.aws.amazon.com/prescriptive-guidance/latest/patterns/manage-tenants-across-multiple-saas-products-on-a-single-control-plane.html).

Pola ini mendukung katalog dan pengelolaan tenant bersama lintas produk. Metta memakai satu tenant kanonik untuk Faskes dan ERP kelak. Referensi ini tidak menyelesaikan integrasi transaksi antarproduk; AWS juga menyatakan integrasi ke masing-masing produk di luar scope pola tersebut. Kita tidak menganggap diagramnya sebagai bridging yang sudah tersedia.

## Pelajari batas teknis database

**PostgreSQL.** [Schemas](https://www.postgresql.org/docs/current/ddl-schemas.html) dan [Privileges](https://www.postgresql.org/docs/current/ddl-priv.html).

Database dan schema berbeda konsep. Koneksi PostgreSQL biasa berada pada satu database, sedangkan role dibagi pada tingkat cluster. Karena itu periksa CONNECT, schema usage, table/sequence/default privilege, dan inheritance. Ordinary FK lintas database tidak dipertahankan dengan menulis nama database pada query. FDW atau dblink tidak dipakai untuk menyamarkan pemisahan.

Database berbeda pada cluster sama tetap berbagi CPU, disk, administrasi cluster, dan failure domain. PRD membedakan batas ini dari pemisahan server.

## Pelajari proyeksi data lokal

**Microsoft Azure Architecture Center.** [Materialized View pattern](https://learn.microsoft.com/en-us/azure/architecture/patterns/materialized-view).

Proyeksi adalah bentuk turunan untuk kebutuhan baca, bukan sumber data baru yang bisa diedit mandiri. Metta dapat mempertahankan referensi aktor, tenant, dan membership lokal agar audit dan FK bisnis tidak memerlukan query pusat setiap saat. Pola ini tidak memberi jaminan bahwa cache authorization selalu mutakhir. Revision, expiry, revoke, dan rekonsiliasi tetap harus ditentukan pada kontrak aplikasi.

## Pelajari commit, retry, dan kegagalan parsial

**AWS Prescriptive Guidance.** [Transactional outbox pattern](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html).

Outbox menangani kegagalan antara commit data dan pengiriman pekerjaan. Di Metta, perubahan authority pusat dan catatan operation tersimpan lokal bersama. Target menyimpan receipt untuk menolak duplikat. Outbox tidak membuat dua database menjadi satu transaksi ACID dan tidak memberikan exactly-once delivery. Tidak perlu memasang Kafka atau broker baru hanya karena pola ini dipakai; pilih transport yang memenuhi durabilitas kontrak.

**AWS Prescriptive Guidance.** [Saga orchestration pattern](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/saga-orchestration.html).

Gunakan pemikiran operasi bertahap untuk tenant, grant lokal, dan provisioning yang dapat gagal sebagian. Receipt dan rekonsiliasi boleh diwujudkan melalui operation state yang sederhana. Referensi bukan alasan memasang workflow engine generik sekarang. Kompensasi bisnis harus eksplisit; timeout tidak membenarkan penghapusan database fasilitas.

## Pelajari pembatasan dampak gangguan

**Google SRE.** [Addressing Cascading Failures](https://sre.google/sre-book/addressing-cascading-failures/).

Bab ini membahas bagaimana kegagalan dan overload dapat menyebar, termasuk bahaya retry yang menambah beban. Terapkan timeout, retry budget, backoff dengan jitter, dan pemisahan resource klien pada akses pusat. Materi ini bukan bukti topologi database internal Google yang dipakai Metta.

**Microsoft Azure Architecture Center.** [Bulkhead pattern](https://learn.microsoft.com/en-us/azure/architecture/patterns/bulkhead).

Pisahkan pool koneksi dan antrean kerja sesuai batasnya agar masalah pusat tidak menghabiskan seluruh resource transaksi ERP. Pada deployment awal dengan runtime bersama, isolasi tetap terbatas. Rehearsal harus mengukur perilaku tersebut, bukan mengasumsikan database terpisah membuat semua gangguan independen.

## Pelajari perubahan bertahap tanpa dua otoritas

**Microsoft Azure Architecture Center.** [Strangler Fig pattern](https://learn.microsoft.com/en-us/azure/architecture/patterns/strangler-fig).

Ambil gagasan pemindahan bertahap berdasarkan batas domain. Untuk Metta, refactor koneksi dan query sebelum cutover data, lalu pindahkan authority satu kali di bawah maintenance. Jangan menyalin seluruh mekanisme routing service atau mempertahankan dual-write tanpa batas. Setelah cutover, salinan pusat lama tidak boleh tetap menjadi writer kedua.

## Pelajari organisasi dan aturan berbagi yang berbeda

**Microsoft Dynamics 365.** [Organizations and organizational hierarchies](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/organizations-organizational-hierarchies) dan [Cross-company data sharing](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/cross-company-data-sharing).

Gunakan identitas organisasi stabil dan hierarchy berbasis purpose. Kebijakan berbagi reference/master berbeda dari transaksi. Microsoft membatasi cross-company sharing tersebut dan tidak memakainya untuk membagi transaksi. Metta karena itu memisahkan katalog obat dari authority saldo stok, serta tidak mengubah akses hanya karena dua klinik menjadi saudara dalam hierarchy. Lokasi master Foundation pada core_control_plane adalah keputusan Metta, bukan klaim topologi internal Dynamics.

**Microsoft Supply Chain.** [Inventory on-hand list](https://learn.microsoft.com/en-us/dynamics365/supply-chain/inventory/inventory-on-hand-list).

Dimensi lokasi dan reservasi tetap diperlukan saat melihat ketersediaan agregat. Pada Metta, total gabungan tidak berarti satu gudang fisik atau hak issue bersama. Authority ledger tetap tunggal untuk owner/lokasi yang dipilih. Read model ketersediaan bukan jaminan reserve/issue atomik.

**Google Cloud Healthcare.** [FHIR access data model and control system](https://docs.cloud.google.com/healthcare-api/docs/fhir-access-control-technical).

Materi ini menunjukkan kebijakan akses berdasarkan actor, context, resource, dan consent, termasuk perbedaan resource pasien dan resource yang mereferensikannya. Metta mengambil pemisahan akses demografi, episode, dan dokumen. Ini bukan kewajiban memakai FHIR atau Google Cloud, bukan bukti consent/legal eligibility customer, dan bukan klaim bahwa mekanisme baca FHIR tersebut sudah menegakkan seluruh write SQL Server legacy.

## Dasarkan profile fasilitas pada regulasi yang sesuai

[Permenkes 11 Tahun 2025, bagian Klinik Pratama halaman tercetak 82](https://jdih.kemkes.go.id/storage/documents/pdfs/2025permenkes011.pdf#page=82) menjadi referensi pembedaan profile pelayanan farmasi dan penyimpanan khusus. Jangan menjadikan label Klinik sebagai larangan universal atau menganggap nama DEPO membuktikan skema titipan sah. [Spesifikasi custody](SUPPLY-CUSTODY-AND-PAYMENT.md) membedakan desain teknis, bukti source/database, dan kelayakan fasilitas yang belum diverifikasi.

## Adaptasikan pola Business Central yang memang ditemukan

Audit clone BCApps lokal pada commit `777e102e90a078b7256bf94b065ba50089abafa9` menemukan Microsoft Subcontracting dengan `Component Supply Method` dan mode `Consignment at Vendor`. Kode berada pada `src/Apps/W1/Subcontracting/App/src/Manufacturing/ComponentSupplyMethod.Enum.al` serta `General/SubcontractingManagement.Codeunit.al`. Barang milik perusahaan dapat berada di lokasi pihak lain. Ini analog custody/source, bukan fitur healthcare atau bukti settlement Klinik-Apotek lengkap. [Dokumentasi supply method BC](https://learn.microsoft.com/en-us/dynamics365/business-central/subcontract-components).

Location/Stockkeeping Unit dan ledger BC memisahkan item, lokasi, reservation, consumption serta invoice. Master Data Management memiliki source company dan pasangan Integration System ID/Local System ID untuk coupling record. Adaptasikan konsep mapping serta publisher/subscriber tersebut, bukan menganggap ID integer yang sama selalu data yang sama. [Dokumentasi master data BC](https://learn.microsoft.com/en-us/dynamics365/business-central/admin-set-up-data-sync).

`Sell-to Customer` berarti customer yang dilayani, sedangkan `Bill-to Customer` berarti pihak yang ditagih. Keduanya bukan pengganti stock owner atau penerima pendapatan. Company BC juga tidak disamakan dengan tenant platform. App Subcontracting pada snapshot main tersebut berversi `30.0.0.0`; ketersediaannya pada BC trial tidak diuji. Yang diadaptasi adalah model dan batas bisnis, bukan pemasangan module manufacturing untuk pelayanan pasien.

## Hubungkan pelajaran ke gate Metta

| Pelajaran                    | Bagian PRD               | Bukti sebelum rilis                                                                   |
| ---------------------------- | ------------------------ | ------------------------------------------------------------------------------------- |
| Batas platform dan workload  | FR-01, FR-07             | Manifest ownership, database/role berbeda, uji privilege negatif.                     |
| Integritas setelah pemisahan | FR-03, FR-04             | ID tetap, FK lokal, projection revision, orphan check.                                |
| Kegagalan dua commit         | FR-06                    | Crash injection, receipt idempotent, replay/out-of-order aman.                        |
| Revocation dan availability  | FR-05, FR-09             | Sesi aktif dan outage diuji terhadap batas 60 detik.                                  |
| Migrasi terkendali           | FR-08, FR-10             | Rehearsal, checksum, sequence, decrypt/sign, rollback dua kondisi.                    |
| Lintas produk                | Bagian bridging pada PRD | ID tenant/environment stabil; tidak ada shared business table atau synchronizer baru. |

Urutan belajar yang disarankan adalah batas SaaS, dasar PostgreSQL, proyeksi, outbox, lalu kegagalan dan cutover. Setelah membaca tiap pola, buka source yang dipetakan pada PRD dan jelaskan gate yang melindunginya. Memahami diagram belum membuktikan implementasi memenuhi pola.
