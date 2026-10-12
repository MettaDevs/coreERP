# Riset dan bukti yang harus dibaca

Pemeriksaan source dilakukan 7 Oktober 2026. Ini inventaris repository, bukan konfirmasi schema
runtime. Agen implementasi harus memeriksa ulang file dan runtime; jangan memakai baris chat
sebagai spesifikasi.

Pemilihan runtime dibuka sesudah pembahasan alur visual executable: lihat
[Flowable vs Temporal](./pemilihan-engine). Bukti trial/customer stories tidak menggantikan
pilot integrasi/isolasi/operasional CoreERP.

Referensi pemilik: PDF SAP Signavio © 2024 yang diabaikan Git, dibaca seluruhnya dan diperiksa
visual pada hierarchy (hlm. 8) serta checklist (hlm. 21). Identitas, hash, pemetaan halaman,
dan batas yang belum dijelaskan sumber ada di [fondasi Signavio](./fondasi-signavio).
Panduan itu mengarahkan modeling, bukan schema persistence atau executor Temporal.

Export trial tambahan diperiksa pada hari yang sama: [contoh XML Signavio](./contoh-xml-signavio).
`isExecutable=false`, task generik, timer tanpa durasi executable, dan kondisi tanpa expression
membuktikan import diagram tidak otomatis memberikan runtime binding. Browser model juga
menampilkan Is executable False; belum ada execution Governance yang diuji pada account ini.

## Source yang sudah ada

Semua path di tabel ini relatif terhadap root repository dan benar-benar ada saat rencana ditulis.

| Path | Bukti |
| --- | --- |
| `apps/core/package.json` | `@xyflow/react` sudah terpasang; tidak ditemukan SDK Temporal |
| `apps/core/composer.json` | Laravel/Octane existing, belum ada `temporal/sdk` |
| `apps/core/resources/js/pages/foundation/workflow/workflow-editor.tsx` | Editor node/edge, draft dan publish existing |
| `apps/core/app/Foundation/Workflow/Support/WorkflowGraph.php` | Jenis node existing: start, end, approval, manual_task, condition, parallel |
| `apps/core/app/Foundation/Workflow/Support/WorkflowRuntime.php` | Mesin approval berbasis DB; submit/decide, work items, history, keputusan module |
| `apps/core/app/Foundation/Workflow/ModuleServices/WorkflowEngineCore.php` | Validasi pengaju, scope, versi berlaku, dan idempotency |
| `apps/core/app/Platform/Modules/Contracts/WorkflowEngine.php` | Kontrak approval in-process, bukan executor proses bisnis umum |
| `apps/core/database/migrations/2026_07_28_090000_create_workflow_tables.php` | Tabel graph, versi, instance, task, dan history awal; migration lanjutan tetap harus diperiksa |
| `apps/core/app/Platform/Modules/Support/TenantRunnerCore.php` | Mengikat/pulihkan TenantScope; tidak membuktikan pergantian koneksi database |
| `apps/core/app/Platform/Environment/Support/ActiveEnvironment.php` | Konteks environment dan batas akses luar; periksa worker terpisah terhadap aturan ini |
| `modules/apperp/management-aset/src/Http/Controllers/transaksi/PenerimaanAset/PenerimaanAsetController.php` | `selesaikan()` menjaga versi, scope, nomor, pembuatan aset, dan posting atomik |
| `modules/apperp/management-aset/src/Services/AcquisitionPosting.php` | Posting perolehan module, bukan engine workflow |
| `apps/core/app/Foundation/FinancePosting/Support/PostingPublisher.php` | Idempotency posting, status pemetaan, payload snapshot, dan transaksi wajib |
| `modules/apperp/management-aset/tests/Feature/PenerimaanAsetTest.php` | Regression penerimaan; tidak menggantikan test Temporal nyata |
| `apps/core/tests/Feature/Foundation/Workflow/WorkflowConfigurationTest.php` | Konfigurasi approval existing yang tidak boleh rusak |

## Referensi resmi

| Sumber | Kesimpulan yang didukung |
| --- | --- |
| [BC workflows](https://learn.microsoft.com/en-us/dynamics365/business-central/across-workflow) | Event/response, template, approval, automated posting; tindakan yang belum ada perlu extension |
| [F&O workflow elements](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/workflow-elements) | Tugas dan kondisi proses bisnis; bukan bukti bahwa F&O memakai Temporal |
| [SDK PHP](https://github.com/temporalio/sdk-php) | SDK client memerlukan gRPC; worker memerlukan RoadRunner; protobuf direkomendasikan |
| [Quickstart PHP](https://docs.temporal.io/develop/php/set-up-your-local-php) | Cara membuktikan koneksi client–service–worker; development server bukan deployment production |
| [Activity](https://docs.temporal.io/activity-definition) | I/O dan perubahan DB di activity; retry membutuhkan idempotency bisnis |
| [Workflow definition](https://docs.temporal.io/workflow-definition) | Kode workflow deterministik; jangan query DB/hierarchy/config terbaru saat replay |
| [Versioning PHP](https://docs.temporal.io/develop/php/workflows/versioning) | Perubahan worker harus kompatibel dengan history proses lama |
| [Deployment](https://docs.temporal.io/self-hosted-guide/deployment) | Service dan penyimpanan terpisah, Docker/manual/Kubernetes; Kubernetes bukan syarat awal |
| [Visibility](https://docs.temporal.io/self-hosted-guide/visibility) | PostgreSQL mendukung visibility; Elasticsearch bukan kewajiban universal |
| [Upgrade server](https://docs.temporal.io/self-hosted-guide/upgrade-server) | Upgrade schema/server perlu mengikuti jalur versi yang didukung |
| [Production checklist](https://docs.temporal.io/self-hosted-guide/production-checklist) | Kapasitas bergantung workload; resource harus diukur |
| [SAP modeling conventions](https://help.sap.com/docs/signavio-process-manager/workspace-admin-guide/modeling-convention-rules-for-bpmn-2-0) | Validation convention dan severity; aturan organisasi berbeda dari dukungan runtime |
| [SAP export](https://help.sap.com/docs/signavio-process-manager/user-guide/export-intro) | BPMN XML dan format pertukaran lain; contoh export nyata masih perlu untuk round-trip |
| [SAP Governance versions](https://help.sap.com/docs/signavio-process-governance/user-guide/versions) | Published workflow untuk memulai case; bukan bukti mesin eksekusi Process Manager sama dengan Temporal |
| [SAP Governance developer guide](https://help.sap.com/docs/signavio-process-governance/developer-guide/intro) | Trigger/actions untuk integrasi; bukan embedded engine dengan task/case API untuk CoreERP |

Lisensi utama: [server MIT](https://github.com/temporalio/temporal/blob/main/LICENSE),
[SDK PHP MIT](https://github.com/temporalio/sdk-php/blob/master/LICENSE.md),
[RoadRunner MIT](https://github.com/roadrunner-server/roadrunner/blob/master/LICENSE).
Distribusi wajib membawa notice. Belum ada audit dependency transitif/image untuk versi yang akan
dipilih. Temporal Cloud memiliki ketentuan tersendiri dan tidak dipilih pada pembuktian self-host.

## Klaim yang dilarang sebelum ada bukti

Tidak menjanjikan exactly-once efek database dari retry Temporal, RAM tertentu, throughput tertentu,
isolasi tenant dari namespace saja, rollback deployment tanpa replay test, atau bahwa semua graph
React Flow otomatis executable. Jangan menyebut engine Temporal sebagai pengganti authorization,
outbox, kontrak integrator, maupun perhitungan jurnal.
