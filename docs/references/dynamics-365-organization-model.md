# Referensi model organisasi Microsoft Dynamics 365

> **Status:** referensi eksternal, bukan desain kanonik CoreERP. Ditelaah pada 22 Juli 2026 dari dokumentasi resmi Microsoft. Dokumentasi vendor tidak disalin ke repository agar tidak menjadi snapshot yang cepat usang; tautan sumber dan temuan yang relevan dicatat di sini.

## Kesimpulan singkat

Dynamics 365 layak menjadi referensi utama struktur organisasi CoreERP, tetapi modelnya lebih kuat daripada ringkasan awal `legal_entities + org_units.parent_id`.

Enam prinsip utamanya adalah:

1. identitas organisasi dibuat lebih dahulu dan tidak ditentukan oleh posisinya di tree;
2. legal entity dan operating unit mempunyai konsekuensi bisnis yang berbeda;
3. hubungan parent-child berada dalam hierarchy yang mempunyai purpose, draft/published lifecycle, dan effective date;
4. worker, job, position, dan position hierarchy dipisahkan dari organization hierarchy;
5. akses mengikuti rantai **security role → duty → privilege → permission**, bukan kepemilikan modul oleh department;
6. role assignment dapat dibatasi ke organization, berasal dari position/business data, atau berlaku sementara dan diaudit.

Implikasinya bagi CoreERP: pemisahan tenant, legal entity, dan operating unit tetap tepat, tetapi satu `parent_id` permanen pada `org_units` dan role yang dimiliki satu modul tidak cukup jika Dynamics 365 benar-benar menjadi acuan utama.

## Sumber resmi yang ditelaah

| Sumber | Terakhir diperbarui | Fokus |
| --- | --- | --- |
| [Organizations and organizational hierarchies overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/organizations-organizational-hierarchies) | 25 April 2026 | Legal entity, operating unit, establishment, hierarchy purpose, dan financial reporting |
| [Plan your organizational hierarchy](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/plan-organizational-hierarchy) | 6 Juni 2026 | Perbedaan konsekuensi legal entity dan operating unit serta praktik pemodelan hierarchy |
| [Create an organization hierarchy](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/tasks/create-organization-hierarchy) | 25 April 2026 | Purpose, draft, publish, dan penempatan organization ke hierarchy |
| [Create a legal entity](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/tasks/create-legal-entity) | 12 Maret 2026 | Data hukum, pajak, rekening bank, nomor registrasi, dan number sequence |
| [Create an operating unit](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/tasks/create-operating-unit) | 25 April 2026 | Cost center, business unit, department, value stream, dan establishment |
| [Financial dimensions and tags](https://learn.microsoft.com/en-us/dynamics365/finance/general-ledger/financial-dimensions) | 28 Mei 2026 | Dimensi finansial, entity-backed dimension, dan legal-entity override |
| [Purchasing policies overview](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchase-policies) | 1 Juli 2026 | Pemakaian hierarchy purpose untuk policy dan precedence antar-hierarchy |
| [Departments, jobs, and positions](https://learn.microsoft.com/en-us/dynamics365/human-resources/hr-personnel-departments-jobs-positions) | Ditelaah 22 Juli 2026 | Perbedaan department, job, position, rangkap position, dan reporting hierarchy |
| [Role-based security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/role-based-security) | 22 Januari 2026 | Rantai role, duty, privilege, permission, automatic assignment, dan organization scope |
| [Assign users to security roles](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/assign-users-security-roles) | 21 November 2025 | Assignment manual dan automatic berdasarkan business data |
| [Set up process roles and a process hierarchy](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/setup-process-role-hierarchy) | 5 Maret 2026 | Role berbasis position/proses serta pemetaan task, duty, dan privilege |
| [User security governance overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/security-gov-overview) | 5 Maret 2026 | Governance role, privileged access, temporary assignment, dan segregation of duties |
| [Temporary role management](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/temp-role-mgmt) | 5 Maret 2026 | Akses sementara mode merge/replace, organization scope, periode berlaku, dan audit |
| [Set up segregation of duties](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/set-up-segregation-duties) | 26 November 2025 | Pasangan duty yang konflik, severity, risk, mitigation, dan validasi konflik |

## Model organisasi Dynamics 365

### Internal organization

Dynamics 365 mengenal legal entities, operating units, dan teams sebagai internal organizations. Organization dibuat sebelum dimasukkan ke hierarchy. Karena itu, identitas organization tidak berubah hanya karena organization dipindahkan atau ditampilkan dari sudut pandang hierarchy lain.

Teams tidak dapat dimasukkan ke organizational hierarchy. Ini menunjukkan bahwa tidak semua kelompok pengguna harus diperlakukan sebagai unit organisasi formal.

### Legal entity

Legal entity adalah organisasi yang dikenali melalui registrasi hukum, dapat membuat kontrak, dan harus membuat laporan atas kinerjanya. Company ID menjadi batas keamanan data pada banyak area Dynamics 365.

Konsekuensi yang melekat pada legal entity meliputi:

- ledger, chart of accounts, mata uang akuntansi, dan kalender fiskal;
- parameter Accounts Receivable, Accounts Payable, cash/bank, dan modul lain;
- pajak dan statutory reporting sesuai negara registrasi;
- year-end closing dan kebutuhan konsolidasi;
- transaksi intercompany pada subledger;
- nomor registrasi, rekening bank, tax registration, dan number sequence.

Karena itu, financial dimension atau operating unit tidak boleh digunakan sebagai pengganti legal entity bila unit tersebut benar-benar mempunyai kewajiban hukum, pajak, kontrak, atau laporan keuangan sendiri.

### Operating unit

Operating unit membagi tanggung jawab atas sumber daya dan proses operasional. Jenis yang disebutkan Microsoft antara lain:

- cost center;
- business unit;
- department;
- value stream;
- commerce/retail channel.

Operating unit berbagi master data dan parameter modul dengan legal entity induknya. Operating unit tidak mempunyai ledger, mata uang fungsional, kalender fiskal, atau prosedur closing sendiri. Keuntungannya adalah transaksi dan laporan lintas operating unit lebih ringan daripada lintas legal entity.

Microsoft juga menjelaskan bahwa operating unit dapat dipakai untuk proses yang melintasi legal entity. Cost center dapat mengendalikan proses lintas legal entity, business unit dapat dipakai untuk laporan berdasarkan lini bisnis, dan retail channel dapat mengelola toko dalam atau lintas legal entity.

### Establishment

Sejak Dynamics 365 Finance 10.0.48, operating unit dapat dikenali sebagai establishment melalui hierarchy dengan purpose `Enterprise establishment structure`.

Establishment adalah unit operasional stabil dari suatu legal entity yang mungkin memiliki nomor registrasi sendiri pada invoice atau laporan regulator. Legal entity tetap menjadi satu-satunya badan hukum dan accounting entity. Site atau financial dimension dapat dipakai untuk menentukan establishment pada dokumen transaksi.

Pelajaran bagi CoreERP: cabang atau outlet yang menerbitkan dokumen resmi perlu terhubung jelas ke satu legal entity, tetapi department, cost center, atau business unit tidak selalu harus dimiliki hanya oleh satu legal entity.

## Hierarchy bukan atribut tunggal pada organization

Dynamics 365 membuat organization lebih dahulu, kemudian memasukkannya ke satu atau beberapa hierarchy. Satu hierarchy dapat ditetapkan untuk satu atau beberapa purpose yang menentukan:

- jenis organization yang boleh digunakan;
- skenario aplikasi yang mengonsumsi hierarchy;
- parameter atau policy yang dapat diwariskan dari parent;
- cara hierarchy dipakai untuk reporting atau proses bisnis.

Contoh purpose atau sudut pandang yang ditemukan:

- legal, tax, dan statutory reporting;
- internal management reporting;
- procurement internal control;
- multi-company processing;
- enterprise establishment structure.

Hierarchy dapat disimpan sebagai draft lalu dipublikasikan. Microsoft juga menganjurkan effective date untuk analisis dampak restrukturisasi sebelum versi baru berlaku.

Microsoft menyarankan memakai satu hierarchy untuk beberapa purpose bila susunannya memang sama, dan tidak membuat beberapa hierarchy untuk purpose yang sama dalam satu legal entity. Jadi relasi target CoreERP adalah many-to-many melalui `organization_hierarchy_purposes`, bukan satu kolom `purpose_id` permanen pada hierarchy.

Konsekuensi desainnya adalah hubungan parent-child semestinya milik hierarchy/version, bukan menjadi identitas permanen operating unit. Satu department dapat tetap menjadi organization yang sama ketika susunan reporting atau procurement berubah.

## Hierarchy purpose mempunyai dampak nyata

Purchasing policy Dynamics 365 hanya berlaku terhadap organization dalam hierarchy dengan purpose yang sesuai. Organisasi kompleks dapat mempunyai lebih dari satu hierarchy policy, kemudian memakai precedence untuk menentukan aturan yang menang.

Artinya, CoreERP tidak boleh menganggap semua hierarchy otomatis menjadi sumber permission. Hierarchy reporting, procurement, dan legal dapat mempunyai susunan berbeda tanpa mengubah akses pengguna.

`operational_access` bukan nama purpose resmi Microsoft. Istilah tersebut sebelumnya merupakan usulan CoreERP untuk menamai tree yang dikonsumsi scope `self/subtree`. Rekomendasi yang lebih tepat adalah memisahkan keputusan akses dari purpose bisnis: role assignment memilih organization dan, bila memakai `subtree`, hierarchy yang menjadi acuan. Hierarchy lain tidak otomatis memberikan atau mencabut akses.

## Workforce, job, position, dan rangkap jabatan

Microsoft memisahkan empat fakta yang sering tercampur dalam desain ERP:

- **department** adalah operating unit di organization directory;
- **job** menjelaskan jenis pekerjaan, tanggung jawab umum, dan klasifikasinya;
- **position** adalah kursi kerja tertentu di dalam organization, misalnya `Finance Manager Makassar`;
- **worker-position assignment** menyatakan siapa yang menduduki kursi itu dan selama periode apa.

Satu worker dapat mempunyai beberapa position. Pada waktu yang sama, satu position hanya diduduki satu worker. Hubungan `reports to` berada pada position hierarchy; Microsoft juga mengizinkan beberapa hierarchy type sehingga reporting line manajerial dan matrix dapat berbeda tanpa menggandakan worker atau department.

Karena itu, rangkap jabatan tidak dimodelkan dengan memindahkan user ke department lain atau menamai ulang department. Contoh perusahaan Z:

- Dewi mempunyai assignment aktif sebagai `Finance Manager`;
- Dewi juga mempunyai assignment aktif sebagai `Asset Controller`;
- kedua position dapat berada di department atau reporting hierarchy yang berbeda;
- setiap position dapat memicu security-role assignment yang berbeda.

Position adalah fakta organisasi/HR, bukan permission. Hak Dewi tetap berasal dari security role yang ditugaskan kepadanya, baik melalui rule berdasarkan position maupun assignment manual.

## Security berbasis tanggung jawab, bukan kepemilikan modul

Microsoft menyusun akses sebagai rantai berikut:

`User → Role Assignment → Security Role → Duty → Privilege → Permission → Entry Point`

Makna setiap tingkat:

| Tingkat | Makna |
| --- | --- |
| Security role | Sekumpulan tanggung jawab dalam pekerjaan atau proses bisnis. |
| Duty | Bagian dari suatu proses bisnis, misalnya memelihara aset tetap. |
| Privilege | Aksi yang diperlukan untuk menyelesaikan tugas. |
| Permission | Hak pada entry point tertentu beserta tingkat aksesnya. |
| Entry point | Form, menu item, service/API, report, atau aksi yang dilindungi. |

Role tidak dimiliki oleh satu department dan tidak harus berhenti pada batas modul. Module menyediakan entry point dan permission; administrator tenant menyusun role dari duty/privilege yang diperlukan oleh tanggung jawab bisnis. Seorang user dapat menerima beberapa role, dan effective access adalah gabungan assignment aktifnya setelah organization scope diterapkan.

Dengan demikian, label `FINANCE` pada diagram hanyalah kelompok pembaca, bukan container yang memiliki tiga modul. Jika Finance perusahaan Z juga mengontrol aset, tenant dapat memberikan role `Asset Controller` kepada position atau user Finance tanpa memindahkan modul Asset, menggandakan user, atau mengubah organization hierarchy.

### Assignment otomatis, manual, dan organization scope

Microsoft mendukung assignment manual serta automatic role assignment berdasarkan business data, termasuk position. Role assignment juga dapat diberi organization scope: akses hanya untuk organization tertentu atau node beserta turunannya pada hierarchy yang dipilih.

Konsekuensinya:

- rule position menentukan **tanggung jawab apa** yang otomatis diterima;
- organization scope menentukan **data organisasi mana** yang dapat dijangkau;
- hierarchy menentukan arti `with children`, bukan menentukan permission;
- perubahan position atau berakhirnya assignment harus menghitung ulang akses otomatis;
- assignment manual tetap tersedia untuk pengecualian yang sah dan harus diaudit.

### Akses sementara dan segregation of duties

Untuk pegawai pengganti, audit, atau keadaan darurat, Microsoft mempunyai temporary role management. Assignment mempunyai waktu mulai/akhir, organization scope, mode `merge` atau `replace`, dan audit trail; setelah periode berakhir, akses kembali otomatis.

Segregation of duties (SoD) mendefinisikan pasangan duty yang tidak seharusnya dimiliki orang yang sama, lengkap dengan severity, risiko, dan mitigasi. Validasi dilakukan terhadap duty efektif lintas semua role. Jadi rangkap jabatan boleh, tetapi tidak otomatis bebas konflik: kombinasi seperti membuat vendor sekaligus membayar vendor harus ditolak atau memerlukan mitigasi dan approval yang tercatat.

## Financial dimensions bukan organization tree kedua yang disamarkan

Financial dimensions mengategorikan transaksi untuk analisis, misalnya department, project, cost center, atau lini bisnis. Sebagian dimension dapat mengambil nilai dari operating unit dan dapat dibatasi per legal entity melalui override.

Microsoft memperingatkan bahwa financial dimension tidak menggantikan legal entity untuk kebutuhan operasional, pajak, inventory, sales/purchase, atau statutory reporting.

Untuk CoreERP, cost center dan profit center sebaiknya menjadi konsep milik modul Finance. Organization directory dapat menjadi sumber nilai bila diperlukan, tetapi hierarchy Finance tidak boleh dipaksa mengikuti operational-access hierarchy.

## Balanced hierarchy dan placeholder

Microsoft menyarankan balanced hierarchy untuk beberapa kebutuhan reporting: tipe unit pada level yang sama konsisten dan jarak dari root ke level tersebut sama. Placeholder organization mungkin diperlukan bila terdapat level antara legal entity, department, atau business unit.

Temuan ini mengoreksi pernyataan awal bahwa placeholder selalu salah. Yang harus dihindari adalah membuat cabang atau outlet palsu sebagai data operasional hanya agar registrasi lolos.

Jika kelak reporting membutuhkan tree yang seimbang, grouping/placeholder dapat hidup pada hierarchy khusus reporting dan tidak perlu berpura-pura sebagai lokasi operasional, badan hukum, atau unit yang menjalankan transaksi.

## Pemetaan ke CoreERP

| Dynamics 365 | CoreERP | Catatan |
| --- | --- | --- |
| Environment/outer application boundary | `tenant` | Tidak identik. Tenant CoreERP tetap menjadi batas kontrak, isolasi, placement, dan metering di luar organization model. |
| Internal organization identity | Organization directory | Identitas stabil yang tidak ditentukan oleh depth atau parent tunggal. |
| Legal entity/company | Legal entity CoreERP | Batas kontrak hukum, ledger, pajak, mata uang, dan statutory reporting. |
| Operating unit | Org/operating unit CoreERP | Department, branch, outlet, business unit, atau unit operasional lain. |
| Organization hierarchy | Purpose-scoped hierarchy | Menyimpan hubungan organization menurut satu sudut pandang. |
| Hierarchy version/effective date | Published hierarchy version | Diperlukan agar restrukturisasi tidak menulis ulang sejarah secara diam-diam. |
| Department | Operating unit bertipe department | Organization formal; bukan role atau kelompok modul. |
| Job | Job definition | Jenis pekerjaan yang dapat dipakai banyak position. |
| Position | Position | Kursi kerja tertentu, mempunyai department dan dapat memiliki reporting relationship. |
| Worker-position assignment | Position assignment berbatas waktu | Memungkinkan satu worker merangkap beberapa position. |
| Security role | Security role tenant | Mewakili tanggung jawab/proses; tidak mempunyai `module_id`. |
| Duty → privilege → permission | Lapisan security metadata | Menghubungkan tanggung jawab sampai entry point modul. |
| User-role assignment | Role assignment | Manual, otomatis berdasarkan rule, atau sementara. |
| Assign organizations | Organization scope pada role assignment | Memilih organization/hierarchy dan apakah turunannya ikut tercakup. |
| Temporary role management | Temporary role session | Periode, merge/replace, organization scope, approval, dan audit. |
| Segregation of duties | SoD rule dan conflict review | Konflik dihitung dari duty efektif lintas role. |
| Financial dimension | Konsep/projection modul Finance | Bukan pengganti legal entity atau operational hierarchy. |
| Establishment | Unit operasional dengan identitas regulator | Relevan untuk cabang/outlet yang muncul pada invoice atau laporan regulator. |

## Keputusan yang diperbarui

### Tetap diyakini

1. Dynamics 365 adalah referensi utama yang lebih cocok daripada menyalin enterprise structure SAP secara penuh.
2. `tenant`, legal entity, dan operating unit harus dibedakan.
3. Pilihan `organization_depth` saat registrasi tidak cocok sebagai domain invariant.
4. Legal entity harus menjadi scope eksplisit untuk transaksi finansial dan dokumen resmi.
5. Financial dimension dan cost center tidak boleh dipaksa menjadi bagian dari satu operational tree.

### Rekomendasi yang berubah

Rekomendasi awal mempertahankan satu flexible tree melalui `org_units.parent_id` dan role per modul. Setelah membaca dokumentasi lebih dalam, kedua batas tersebut terlalu sempit.

Jika Dynamics 365 dipakai sebagai acuan utama, CoreERP sebaiknya memisahkan:

- organization identity;
- legal-entity atau operating-unit classification;
- hierarchy definition dan purpose;
- hierarchy version dan placement;
- worker, job, position, dan position assignment;
- security role, duty, privilege, permission, dan entry point;
- user-role assignment, organization scope, automatic assignment rule, serta temporary assignment;
- SoD rule dan conflict resolution.

Ini adalah target domain model, bukan menu opsional. Pengiriman fitur boleh bertahap, tetapi tahapan awal tidak boleh mengubah makna data menjadi model lain. Baseline lama `org_units.parent_id` dan `roles.module_id` telah dihapus dari Control Plane; fitur workforce, temporary access, dan SoD yang belum tersedia harus ditambahkan di atas model target, bukan melalui compatibility layer lama.

### Implikasi untuk banyak modul

Jumlah modul tidak berarti CoreERP harus membuat satu organization hierarchy untuk setiap modul. Hierarchy baru hanya diperlukan bila suatu proses membutuhkan susunan parent-child yang benar-benar berbeda.

| Kelompok modul | Memakai organization directory/hierarchy untuk | Struktur yang tetap dimiliki modul |
| --- | --- | --- |
| Asset | Legal entity pemilik dan unit penanggung jawab | Kategori aset, lokasi aset, dan lifecycle aset |
| HRD dan Admin HRD | Department atau business unit | Position, supervisor, job, dan reporting line pegawai |
| Inventory dan Produksi | Legal entity, establishment, atau unit penanggung jawab | Site, warehouse, bin/location, work center, BOM, dan production flow |
| CRM, Marketing, dan Penjualan | Unit pemilik customer atau channel | Sales team, territory, campaign, dan pipeline |
| Procurement | Unit pemohon dan hierarchy kebijakan bila berbeda | Kategori procurement, vendor, requisition, dan workflow approval |
| Cost Control, Anggaran, dan SIA | Legal entity dan management responsibility | Ledger, account, cost center, financial dimension, dan budget structure |
| Proyek | Legal entity dan unit pemilik proyek | WBS, project team, milestone, dan task hierarchy |

Satu management hierarchy dapat dipakai banyak modul untuk konteks organisasi umum. Procurement hierarchy, establishment hierarchy, atau legal-reporting hierarchy ditambahkan hanya ketika susunannya berbeda dan ada proses yang mengonsumsinya. Tree domain seperti WBS proyek, warehouse/bin, position reporting, chart of accounts, dan sales territory bukan organization hierarchy.

### Model relasional minimum target

Nama tabel berikut bersifat rancangan CoreERP, tetapi relasi dan pemisahan tanggung jawabnya mengikuti konsep Microsoft.

| Area | Tabel minimum | Relasi penting |
| --- | --- | --- |
| Organization | `organizations`, `legal_entities`, `operating_units` | Subtype menunjuk satu identity organisasi yang stabil. |
| Hierarchy | `organization_hierarchies`, `hierarchy_purposes`, `organization_hierarchy_purposes`, `organization_hierarchy_versions`, `organization_hierarchy_nodes` | Parent-child berada pada node versi, bukan pada organization identity. |
| Workforce | `workers`, `jobs`, `positions`, `worker_position_assignments`, `position_hierarchy_types`, `position_relationships` | Satu worker dapat mempunyai banyak assignment; satu position maksimal satu worker aktif pada waktu yang sama. |
| Security metadata | `security_roles`, `security_duties`, `security_privileges`, `security_permissions`, `module_entry_points` | Join tables menghubungkan role→duty→privilege→permission; permission menunjuk entry point modul. |
| Assignment | `user_role_assignments`, `role_assignment_org_scopes`, `automatic_role_assignment_rules` | Assignment menyimpan sumber, periode berlaku, dan organization scope. |
| Governance | `temporary_role_sessions`, `sod_rules`, `sod_conflicts`, `access_audit_events` | Akses sementara dan konflik SoD selalu dapat ditelusuri. |

Semua tabel tenant-owned membawa `tenant_id`. Transaction table tetap membawa `legal_entity_id` dan `org_unit_id` saat relevan. Module entry point berasal dari contract/manifest modul, sedangkan role composition adalah konfigurasi tenant.

### Operasi yang harus tersedia

1. **Organization lifecycle:** buat identity, klasifikasikan sebagai legal entity/operating unit, susun draft hierarchy, lakukan validasi, publish dengan effective date, dan simpan versi lama.
2. **Workforce lifecycle:** buat job dan position, hubungkan position ke department, tetapkan reporting relationship, lalu buat assignment worker dengan tanggal mulai/akhir. Rangkap jabatan berarti menambah position assignment kedua.
3. **Security design:** modul mendaftarkan entry point dan permission; duty dan privilege membentuk proses; administrator menyusun security role lintas modul sesuai tanggung jawab.
4. **Role provisioning:** berikan role secara manual atau melalui automatic rule berdasarkan position/business data, lalu tentukan organization scope dan apakah children ikut tercakup.
5. **Runtime authorization:** identifikasi user dan tenant, ambil assignment aktif, perluas role→duty→privilege→permission, terapkan organization scope, periksa entitlement tenant dan installation registry, lalu izinkan entry point.
6. **Temporary cover:** buat permintaan berbatas waktu, pilih mode merge/replace dan organization scope, approve, aktifkan otomatis, audit pemakaian, lalu cabut otomatis saat berakhir.
7. **SoD review:** hitung duty efektif lintas role, temukan pasangan konflik, lalu reject atau rekam approval dan mitigasi sebelum assignment berlaku.

### Batas SaaS CoreERP yang tetap terpisah

Microsoft menjadi acuan model organisasi, workforce, dan authorization di dalam tenant. CoreERP tetap mempunyai outer boundary SaaS yang tidak identik dengan organization model Dynamics 365:

- `tenant` adalah batas kontrak dan isolasi data;
- entitlement menyatakan tenant berhak memakai produk;
- installation/deployment registry menyatakan artifact sudah ditempatkan dan siap;
- RBAC menyatakan user boleh melakukan aksi tertentu pada organization tertentu.

Keempatnya diperiksa berurutan dan tidak boleh disimpulkan satu dari yang lain. Role tidak membuat tenant memperoleh modul, entitlement tidak membuat user mempunyai permission, dan status `installed/ready` tidak boleh diturunkan dari keduanya.

### Urutan build tanpa mengubah target model

- registrasi membuat tenant tanpa organization depth; guided setup sesudahnya meminta minimal satu legal entity sebelum transaksi resmi digunakan;
- organization directory dan versioned hierarchy dibangun sebelum UI reparenting;
- job, position, multi-position assignment, dan position relationship menjadi model workforce kanonik;
- security metadata menggunakan rantai role/duty/privilege/permission sejak awal; tidak membuat role milik satu modul;
- organization-scoped assignment, automatic rules, temporary role, dan SoD ditambahkan sebagai operasi di atas model yang sama, bukan sebagai compatibility layer;
- purpose hierarchy ditambahkan saat konsumen bisnisnya tersedia, tetapi schema version/placement sudah benar sejak fondasi.

Blueprint visual relasi, database minimum, dan operasi tersedia pada page **6 - Build Blueprint Microsoft Model** di [`coreerp-saas-organization-hierarchy.drawio`](./coreerp-saas-organization-hierarchy.drawio).

## Tingkat keyakinan setelah riset

Riset ini meningkatkan keyakinan bahwa Dynamics 365 adalah acuan utama yang tepat. Modelnya konsisten dari organization directory sampai workforce dan authorization: legal entity dibedakan dari operating unit, hierarchy mempunyai purpose/version, worker dapat merangkap position, dan akses diturunkan dari tanggung jawab melalui role/duty/privilege/permission serta organization scope.

Riset ini sekaligus menolak versi implementasi yang terlalu sederhana. Model `legal_entities + satu org_units tree + role per module` mencampurkan identitas organization dengan satu sudut pandang hierarchy dan mengikat tanggung jawab ke batas teknis modul. Target yang dipilih adalah **organization directory dengan purpose-scoped, versioned hierarchies; position-based workforce; dan responsibility-based security**.
