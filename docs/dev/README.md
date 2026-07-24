# CoreERP SaaS App Platform

Dokumen ini adalah **desain kanonik** untuk CoreERP. Ia menggantikan asumsi awal bahwa seluruh modul adalah Composer package dalam satu Laravel runtime dan satu data-plane bersama.

Target yang dikunci:

1. Satu app adalah **release unit mandiri** dan memiliki repository sendiri: API, UI artifact, database, migration, kontrak, dan image Docker.
2. App dapat di-install, di-enable, di-upgrade, di-disable, dan di-uninstall secara aman.
3. Cloud SaaS mendukung profile `pooled` dan `isolated`; on-prem perpetual memakai profile `onprem-perpetual` dan dapat beroperasi tanpa koneksi runtime ke vendor.
4. API sync menggunakan REST/JSON dengan OpenAPI; dampak lintas database menggunakan event contract. gRPC dan GraphQL adalah opsi melalui ADR, bukan standar v1.
5. Customisasi tidak dilakukan dengan fork CoreERP. Gunakan konfigurasi, integration connector, atau addon app.
6. Control plane global mengelola SaaS. On-prem perpetual menyimpan state instalasi dan lisensinya secara lokal; konektor support ke vendor adalah opsi eksplisit, bukan syarat operasi.

## Peta dokumen

| Dokumen | Isi |
| --- | --- |
| [01-grand-design.md](01-grand-design.md) | AWS control plane/application plane, deployment profile, dan keputusan inti |
| [01a-tenant-and-org-hierarchy.md](01a-tenant-and-org-hierarchy.md) | Tenant, organization directory, legal entity, operating unit, dan versioned hierarchy |
| [02-module-standard.md](02-module-standard.md) | Standar app: repository, release unit, manifest, database ownership, dan lifecycle |
| [03-release-and-on-prem.md](03-release-and-on-prem.md) | Provisioning, Docker Compose edition, update, dan uninstall |
| [04-api-and-integration.md](04-api-and-integration.md) | REST/OpenAPI, event/AsyncAPI, bridge POS-Booking, dan API governance |
| [05-customization-and-addons.md](05-customization-and-addons.md) | Konfigurasi, addon private, extension customer, dan anti-fork policy |
| [06-worktree-target.md](06-worktree-target.md) | Kondisi repo sekarang dan target pemisahan repository |
| [07-reporting-and-replicas.md](07-reporting-and-replicas.md) | Read replica per app, reporting projection lintas app, dan consistency policy |
| [08-query-scopes-and-schema.md](08-query-scopes-and-schema.md) | Schema organization/hierarchy target dan query tenant/legal-entity/organization scope |
| [09-identity-and-access.md](09-identity-and-access.md) | Role platform, security role/duty/privilege/permission, workforce, SoD, dan organization scope |
| [10-core-foundation-gates.md](10-core-foundation-gates.md) | Fondasi Core yang belum tersedia, pemilik kebenaran, dan kondisi kapan implementasinya boleh dimulai |

## Referensi utama

- [AWS SaaS Architecture Fundamentals - PDF lokal](../saas-architecture-fundamentals.pdf)
- [AWS SaaS Architecture Fundamentals - online](https://docs.aws.amazon.com/id_id/whitepapers/latest/saas-architecture-fundamentals/saas-architecture-fundamentals.pdf)
- [Citus: Designing SaaS database with PostgreSQL](https://learn.microsoft.com/en-us/postgresql/citus/designing-saas?view=citus-14)
- [Model organisasi Microsoft Dynamics 365](../references/dynamics-365-organization-model.md)

## Glosarium singkat

| Istilah | Arti di CoreERP |
| --- | --- |
| Tenant | Pemegang kontrak dan batas isolasi data tertinggi. |
| Organization | Identitas bisnis stabil di dalam tenant; diklasifikasikan sebagai legal entity atau operating unit. |
| Legal entity | Organization dengan konsekuensi hukum, ledger, pajak, dan statutory reporting sendiri. |
| Operating unit | Organization untuk tanggung jawab operasional seperti business unit, department, cost center, value stream, atau channel. Establishment adalah peran operating unit dalam hierarchy purpose khusus, bukan tipe organization terpisah. |
| Organization hierarchy | Penempatan parent-child organization untuk purpose dan version tertentu; bukan depth permanen. |
| App | Produk atau kemampuan bisnis yang dapat dipasang dan dirilis mandiri, misalnya POS atau Booking. Satu app memiliki satu repository. |
| Addon app | App tambahan, biasanya integration atau kebutuhan khusus customer. |
| Pool | Tenant berbagi deployment dan database modul, dipisahkan oleh `tenant_id`. |
| Silo/isolated | Resource suatu modul ditempatkan khusus untuk satu tenant. |
| On-prem perpetual | Deployment Compose di server customer dengan app yang dibeli saja, state instalasi lokal, lisensi perpetual bertanda tangan, dan update manual. Server tetap dapat online untuk pengguna customer tanpa harus tersambung ke vendor. |
| Managed support connector | Konektor outbound mTLS yang opsional dan disetujui customer untuk mengirim health/version minimum; bukan remote shell dan tidak mengirim data bisnis. |
| Control plane | Layanan global vendor untuk mengelola tenant, lisensi, placement, operasi, dan billing **SaaS**. |
| Application plane | API/UI/database yang menjalankan fungsi ERP untuk tenant. |
