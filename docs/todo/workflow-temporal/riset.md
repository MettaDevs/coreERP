# Riset dan bukti yang harus dibaca

Pemeriksaan source dilakukan 7 Oktober 2026. Ini inventaris repository, bukan konfirmasi schema
runtime. Agen implementasi harus memeriksa ulang file dan runtime; jangan memakai baris chat
sebagai spesifikasi.

Pemilik menetapkan [Flowable](./pemilihan-engine) pada 31 Oktober 2026 setelah kebutuhan
proses visual per tenant dan lintas module diperjelas. Pembandingan engine tidak lagi menjadi
rencana aktif. Bukti trial/customer stories tidak menggantikan pilot integrasi, isolasi dan operasi.

Referensi pemilik: PDF SAP Signavio © 2024 yang diabaikan Git, dibaca seluruhnya dan diperiksa
visual pada hierarchy (hlm. 8) serta checklist (hlm. 21). Identitas, hash, pemetaan halaman,
dan batas yang belum dijelaskan sumber ada di [fondasi Signavio](./fondasi-signavio).
Panduan itu mengarahkan modeling, bukan schema persistence atau implementasi engine CoreERP.

Export trial tambahan diperiksa pada hari yang sama: [contoh XML Signavio](./contoh-xml-signavio).
`isExecutable=false`, task generik, timer tanpa durasi executable, dan kondisi tanpa expression
membuktikan import diagram tidak otomatis memberikan runtime binding. Browser model juga
menampilkan Is executable False; belum ada execution Governance yang diuji pada account ini.

## Source yang sudah ada

Semua path di tabel ini relatif terhadap root repository dan benar-benar ada saat rencana ditulis.

| Path                                                                                                        | Bukti                                                                                          |
| ----------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `apps/core/package.json`                                                                                    | `@xyflow/react` menyediakan renderer existing, bukan runtime BPMN                              |
| `apps/core/composer.json`                                                                                   | Runtime PHP existing; adapter Flowable perlu dibuktikan, bukan memasang SDK engine lain        |
| `apps/core/resources/js/pages/foundation/workflow/workflow-editor.tsx`                                      | Editor node/edge, draft dan publish existing                                                   |
| `apps/core/app/Foundation/Workflow/Support/WorkflowGraph.php`                                               | Jenis node existing: start, end, approval, manual_task, condition, parallel                    |
| `apps/core/app/Foundation/Workflow/Support/WorkflowRuntime.php`                                             | Mesin approval berbasis DB; submit/decide, work items, history, keputusan module               |
| `apps/core/app/Foundation/Workflow/ModuleServices/WorkflowEngineCore.php`                                   | Validasi pengaju, scope, versi berlaku, dan idempotency                                        |
| `apps/core/app/Platform/Modules/Contracts/WorkflowEngine.php`                                               | Kontrak approval in-process, bukan executor proses bisnis umum                                 |
| `apps/core/database/migrations/2026_07_28_090000_create_workflow_tables.php`                                | Tabel graph, versi, instance, task, dan history awal; migration lanjutan tetap harus diperiksa |
| `apps/core/app/Platform/Modules/Support/TenantRunnerCore.php`                                               | Mengikat/pulihkan TenantScope; tidak membuktikan pergantian koneksi database                   |
| `apps/core/app/Platform/Environment/Support/ActiveEnvironment.php`                                          | Konteks environment dan batas akses luar; periksa worker terpisah terhadap aturan ini          |
| `modules/apperp/management-aset/src/Http/Controllers/transaksi/PenerimaanAset/PenerimaanAsetController.php` | `selesaikan()` menjaga versi, scope, nomor, pembuatan aset, dan posting atomik                 |
| `modules/apperp/management-aset/src/Services/AcquisitionPosting.php`                                        | Posting perolehan module, bukan engine workflow                                                |
| `apps/core/app/Foundation/FinancePosting/Support/PostingPublisher.php`                                      | Idempotency posting, status pemetaan, payload snapshot, dan transaksi wajib                    |
| `modules/apperp/management-aset/tests/Feature/PenerimaanAsetTest.php`                                       | Regression penerimaan; tidak menggantikan test Flowable nyata                                  |
| `apps/core/tests/Feature/Foundation/Workflow/WorkflowConfigurationTest.php`                                 | Konfigurasi approval existing yang tidak boleh rusak                                           |

## Pemeriksaan sistem lama, 31 Oktober 2026

Source lokal ditelusuri tanpa mengubah kode atau data pasien. SELECT terbatas pada setting FO
Puskesmas dan PIP membantu memilih cabang kode; ini bukan bukti deployment atau sesi browser.
Stored procedure yang terpasang dan seluruh efek runtime belum diverifikasi. Tidak membuat
template baru dengan menyalin controller lama yang menggabungkan beberapa efek bisnis.

| Repo/snapshot                                    | Perbedaan yang terbukti dari source                                                                                                                                                            | Implikasi untuk capability dan konfigurasi                                                                                                                         |
| ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `D:/puskesmas/dev-puskesmas`, DIVA1 `d9a26069`   | Kasir registrasi terpisah dari kasir akhir; daftar poli menyaring `SIMtrRegRJ.SudahBayar`. Realisasi farmasi menulis stok sebelum handover; kesiapan obat mengecualikan ID kerja sama tertentu | Pisahkan biaya registrasi, pembayaran akhir, realisasi stok dan penyerahan; filter daftar bukan invariant backend universal                                        |
| `D:/Kerja/klinik-pip`, master `cc43ba9`          | Daftar kasir menyaring pemeriksaan selesai; pembayaran juga menutup status kunjungan. Farmasi membayar obat bebas. Cabang `multi_bo` production/development berbeda                            | Pisahkan penerimaan uang dari penutupan kunjungan; jangan menganggap pembayaran obat bebas melunasi seluruh kunjungan atau menyalin flag lingkungan tanpa validasi |
| `D:/Kerja/ridani-farma`, main `dd9e0ae`          | Penjualan farmasi, pembayaran dan verifikasi terpisah. `UsePurchasing=0` membuat order dari permintaan; posting penerimaan menulis AP dan menandai `Posting_GL`                                | Order/persetujuan bukan tahap wajib universal; nama status tidak membuktikan kas dibayar atau siklus GL selesai                                                    |
| `D:/Kerja/bo_metta_anggaran_new`, main `a3e4dfa` | Alokasi GC mengurangi sisa faktur/invoice/voucher; posting AP/AR/GC dan penutupan periode terpisah. Feed dapat berstatus posted saat jurnal GL `Posted=0`                                      | Modelkan alokasi, pengakuan, jurnal dan tutup buku sebagai fakta berbeda; konfigurasi proses tidak menggantikan posting profile                                    |

Jangkar untuk memeriksa ulang, relatif terhadap masing-masing repo:

- Puskesmas: `production/application/modules/puskesmas/cashier_registration/helpers/general_payment_helper.php:137`,
  `poly/controllers/Outpatient.php:1730` dan `pharmacy/controllers/Pharmacy.php:1758` di bawah root module puskesmas.
- PIP: `production/application/modules/clinic/cashier/controllers/General_payment.php:750`,
  `pharmacy/controllers/Drug_payment.php:55` di bawah root clinic, serta
  `production/application/modules/verification/verification/helpers/verification_helper.php:185`
  dan sibling development `:179`.
- Ridani: `production/application/modules/inventory/inventory/controllers/transactions/Purchase_request.php:120`,
  `transactions/Goods_receipt.php:378` dan `controllers/behaviors/Posting.php:76` pada root inventory.
- BO: `development/application/modules/back_office/general_cashier/models/cash_bank_expense/Voucher_m.php:254`,
  `general_ledger/controllers/Closing.php:67` dan
  `finance_feed/models/Finance_feed_posting_m.php:1102` pada root back_office.

Checkout pembanding `D:/Kerja/dev-puskesmas` lebih lama. BO lokal tertinggal dari referensi
`origin/main` yang tersedia saat pemeriksaan; diff relevan dibaca tanpa pull. Definisi koneksi
BO yang diminta beberapa cabang honor/posting belum seluruhnya terbukti tersedia. Temuan ini
menentukan skenario uji dan pemisahan tindakan, bukan klaim semua jalur lama berjalan tanpa error.

## Referensi resmi

| Sumber                                                                                                                                      | Kesimpulan yang didukung                                                                          |
| ------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| [BC workflows](https://learn.microsoft.com/en-us/dynamics365/business-central/across-workflow)                                              | Event/response, template, approval, automated posting; tindakan yang belum ada perlu extension    |
| [F&O workflow elements](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/workflow-elements)   | Tugas dan kondisi proses bisnis; bukan bukti pemakaian engine vendor tertentu                     |
| [Flowable OSS](https://www.flowable.com/open-source/docs/oss-introduction/)                                                                 | Engine BPMN/DMN/CMMN dan lisensi; versi/distribusi runtime tetap diuji                            |
| [Flowable REST](https://www.flowable.com/open-source/docs/bpmn/ch14-REST)                                                                   | API deployment/instance/task/job; periksa versi dan operasi aktual sebelum adapter ditulis        |
| [External worker](https://www.flowable.com/open-source/docs/bpmn/ch07b-BPMN-Constructs#external-worker-task)                                | Worker dapat memakai bahasa lain lewat API; efek bisnis tetap memerlukan idempotency              |
| [Deployment](https://www.flowable.com/open-source/docs/bpmn/ch06-Deployment)                                                                | Definisi/deployment berversi; pin definisi yang dipakai instance                                  |
| [Multitenancy dan async jobs](https://www.flowable.com/open-source/docs/bpmn/ch18-Advanced/)                                                | Tenant engine bukan pemeriksaan akses bisnis; retry/job tidak menghapus kewajiban guard Core      |
| [Batas UI OSS](https://www.flowable.com/blog/releases/flowable-open-source-7-0-0-release)                                                   | UI lama dikeluarkan sejak versi 7; hak bundling editor modern tidak otomatis mengikuti engine OSS |
| [Ledger/subledger](https://learn.microsoft.com/en-us/dynamics365/finance/general-ledger/ledger-subledger)                                   | Posting profile dan AR/AP/GL punya tanggung jawab berbeda dari workflow                           |
| [SAP modeling conventions](https://help.sap.com/docs/signavio-process-manager/workspace-admin-guide/modeling-convention-rules-for-bpmn-2-0) | Validation convention dan severity; aturan organisasi berbeda dari dukungan runtime               |
| [SAP export](https://help.sap.com/docs/signavio-process-manager/user-guide/export-intro)                                                    | BPMN XML dan format pertukaran lain; contoh export nyata masih perlu untuk round-trip             |
| [SAP Governance versions](https://help.sap.com/docs/signavio-process-governance/user-guide/versions)                                        | Published workflow untuk memulai case; bukan bukti Process Manager menjalankan transaksi ERP      |
| [SAP Governance developer guide](https://help.sap.com/docs/signavio-process-governance/developer-guide/intro)                               | Trigger/actions untuk integrasi; bukan embedded engine dengan task/case API untuk CoreERP         |

Engine Flowable berlisensi Apache 2.0; distribusi tetap membawa notice dependency versi yang
dipilih. Work/Design, paket komersial dan hak OEM dievaluasi terpisah. Belum ada audit lengkap
dependency/image atau keputusan paket editor yang boleh didistribusikan bersama CoreERP.

## Klaim yang dilarang sebelum ada bukti

Tidak menjanjikan exactly-once efek database dari retry engine, deduplikasi start dari business key
saja, RAM/throughput tertentu, isolasi dari tenant ID engine saja, rollback tanpa uji instance lama,
atau semua graph React Flow otomatis executable. Jangan menyebut Flowable sebagai pengganti authorization,
outbox, kontrak integrator, maupun perhitungan jurnal.
