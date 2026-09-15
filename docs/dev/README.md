# CoreERP SaaS App Platform

> **Baru bergabung dengan tim?** Mulai dari [panduan onboarding](../onboarding/index.md), bukan dari halaman ini. Dokumen di bawah adalah spesifikasi, bukan orientasi.
>
> **Mau membuat modul baru?** Langkah teknisnya ada di [jalur membangun modul baru](../apps/membangun-app-baru.md) — persiapan, konvensi penamaan, berkas yang wajib ada, lalu sepuluh tahap dengan gate keluar. Dokumen di halaman ini adalah aturannya; halaman itu urutan mengerjakannya.

Dokumen ini adalah **desain kanonik** untuk CoreERP.

> **Dua bentuk hidup berdampingan.** Module bisnis berjalan di dalam runtime Core dan memakai
> database tenant yang sama; app yang belum dipindah masih berjalan sebagai container dengan
> database dan token layanan sendiri. Aturan di bawah berlaku untuk keduanya kecuali disebutkan
> lain, dan tabel perbandingannya ada di
> [Grand design](01-grand-design.md#dua-bentuk-yang-hidup-berdampingan). Menilai yang satu dengan
> aturan yang lain adalah kesalahan yang paling mudah terjadi di repo ini.

Target yang dikunci:

1. Satu app adalah **kemampuan bisnis yang dapat dipasang dan dicabut sendiri**, dengan datanya sendiri, kontraknya sendiri, dan siklus rilisnya sendiri.
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
| [02-module-standard.md](02-module-standard.md) | Standar app: repository, release unit, manifest, database ownership, lifecycle, dan bantuan kontekstual per field |
| [03-release-and-on-prem.md](03-release-and-on-prem.md) | Provisioning, Docker Compose edition, update, dan uninstall |
| [04-api-and-integration.md](04-api-and-integration.md) | REST/OpenAPI, event/AsyncAPI, bridge POS-Booking, dan API governance |
| [05-customization-and-addons.md](05-customization-and-addons.md) | Konfigurasi, addon private, extension customer, dan anti-fork policy |
| [06-worktree-target.md](06-worktree-target.md) | Kondisi repo sekarang dan target pemisahan repository |
| [07-reporting-and-replicas.md](07-reporting-and-replicas.md) | Read replica per app, reporting projection lintas app, dan consistency policy |
| [08-query-scopes-and-schema.md](08-query-scopes-and-schema.md) | Schema organization/hierarchy target dan query tenant/legal-entity/organization scope |
| [09-identity-and-access.md](09-identity-and-access.md) | Role platform, security role/duty/privilege/permission, workforce, SoD, dan organization scope |
| [10-core-foundation-gates.md](10-core-foundation-gates.md) | Fondasi Core yang belum tersedia, pemilik kebenaran, dan kondisi kapan implementasinya boleh dimulai |
| [11-local-docker-development.md](11-local-docker-development.md) | Stack Docker lokal, akses database, dan checklist menambah app |
| [12-external-module-integration.md](12-external-module-integration.md) | Panduan integrasi sistem eksternal ke modul CoreERP |
| [13-publishing-an-app-release.md](13-publishing-an-app-release.md) | Kontrak CI untuk mendaftarkan katalog dan release app dari repository terpisah |
| [14-number-sequences.md](14-number-sequences.md) | Reference nomor aplikasi, konfigurasi tenant, scope, periode reset fiskal, API penerbitan, dan scale-out |
| [15-fiscal-calendars.md](15-fiscal-calendars.md) | Kalender fiskal, tahun dan periode fiskal, serta kepemilikannya oleh entitas legal |
| [16-units-of-measure.md](16-units-of-measure.md) | Cara menyiapkan kelas, sistem, satuan, dan konversi umum |
| [17-healthcare-finance-subledger.md](17-healthcare-finance-subledger.md) | Healthcare sebagai subledger, proses verifikasi, dan posting Finance yang dapat dikonfigurasi per faskes |
| [18-module-discovery-and-decision-gate.md](18-module-discovery-and-decision-gate.md) | Gate keputusan sebelum membuat app, master, transaksi, workflow, nomor, atau integrasi |
| [19-transaction-security-chain.md](19-transaction-security-chain.md) | Rantai keamanan satu modul transaksi: empat lapis manifest, lalu security role sampai user |
| [20-load-and-concurrency-testing.md](20-load-and-concurrency-testing.md) | Gate concurrency wajib sebelum modul dinyatakan selesai: 1000+ VU, 100+ tenant, multi-instance, penguncian endpoint pengganti, dan oracle kebenaran |
| [21-visual-workflow-engine.md](21-visual-workflow-engine.md) | Visual Workflow Engine: tipe workflow manifest, editor visual, resolusi assignee, inbox persetujuan terpusat, dan callback event v2 |
| [22-ci-cd.md](22-ci-cd.md) | CI/CD polyrepo, runner trust zone, immutable image, promotion, signing, dan bundle on-prem |
| [23-document-rendering.md](23-document-rendering.md) | Dokumen cetak gaya Business Central: dataset milik app, layout Word/Excel milik tenant, engine render milik Core, ekspor di latar belakang |
| [24-global-address-book.md](24-global-address-book.md) | Buku alamat gaya Global Address Book: party, alamat pos, kontak elektronik; organisasi tenant adalah party, dan kop dokumen membaca alamatnya dari sini |
| [25-standar-penjaga-dan-pengujian.md](25-standar-penjaga-dan-pengujian.md) | Penjaga batas arsitektur: syarat sebelum sebuah test penjaga boleh dipercaya, termasuk terbukti dapat merah |
| [26-modul-yang-sedang-dipindah.md](26-modul-yang-sedang-dipindah.md) | Cara Core menampung modul yang baru ditarik lewat subtree dan belum lolos penjaga, tanpa membuka lubang permanen |
| [27-ui-modul-dalam-shell.md](27-ui-modul-dalam-shell.md) | Layar modul sebagai halaman Inertia di dalam build shell: aturan yang menjaga perpindahan dari iframe, dan alasannya |
| [28-pelaporan-kesalahan.md](28-pelaporan-kesalahan.md) | Laporan kesalahan ke berkas di mesin dan ke SigNoz: isi, tujuan, dan cara mematikannya |
| [29-alur-rilis-server-klien.md](29-alur-rilis-server-klien.md) | Dari branch sampai server klien: tombol rilis, perakit, Harbor, SaaS dev, agen, dan nomor rilis |
| [30-registry-harbor.md](30-registry-harbor.md) | Registry Harbor: isi, robot, kredensial per operasi, penarikan lewat digest, immutability dan retensi, dan jebakan yang terukur |

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
