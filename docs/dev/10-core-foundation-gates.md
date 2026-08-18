# Gate fondasi Core yang belum tersedia

Dokumen ini mencatat kemampuan Core yang memang diperlukan tetapi belum boleh dibuat hanya sebagai tabel atau status kosong. Sebuah kemampuan mulai dikerjakan ketika sumber data, pemilik domain, dan bukti operasional pada kolom **Gate mulai** sudah tersedia.

## Matriks gate

| Fondasi | Keadaan | Gate mulai | Pemilik kebenaran |
| --- | --- | --- | --- |
| Placement dan deployment worker | Fondasi Compose tersedia | Module manifest, entitlement, queue, migration, health check, dan installation registry tersedia. Production baru diaktifkan setelah image digest immutable, target Dokploy, secret reference, dan endpoint health tersedia. | Control Plane untuk desired state; deployment target untuk hasil runtime |
| Upgrade dan uninstall worker | Belum | Compatibility matrix, backup terverifikasi, traffic drain, dependency check, serta prosedur rollback/forward-fix tersedia. | Control Plane mengoordinasi; module dan deployment target mengeksekusi |
| Bootstrap tenant di dalam module | Belum | Module bisnis membutuhkan data awal dan menyediakan API/event provisioning internal yang idempotent; Control Plane tetap dilarang menulis database module. | Module pemilik data |
| Workforce, job, dan position | Belum | HRD/Admin HRD mempunyai model worker, job, position, dan beberapa worker-position assignment aktif berbatas waktu; satu position hanya satu worker aktif; event contract stabil. | Module HRD/Admin HRD |
| Automatic role assignment | Belum | Position atau atribut bisnis sumber rule tersedia dan perubahan datanya menerbitkan event. | Core mengevaluasi rule; module pemilik menerbitkan fakta bisnis |
| Temporary access | Belum | Ada approval owner, alasan, waktu berakhir, audit event, dan proses pencabutan yang dapat dijalankan queue. | Core Identity and Access |
| Segregation of Duties (SoD) | Belum | Duty/privilege proses bisnis sudah stabil dan pemilik approval serta mitigasi konflik telah ditetapkan. | Core Identity and Access |
| Data security policy setara XDS | Belum | Core dapat menghitung beberapa scope `grant`/`revoke` per role assignment dan mengirim scope efektif tepercaya; module pertama mempunyai tabel bisnis, daftar securable resource/field, serta filter record yang dapat diuji. | Policy metadata di Core; filter record dijalankan module pemilik data |
| Field-level permission | Belum | UI/API module mendeklarasikan field securable yang benar-benar memerlukan pembatasan dan server dapat menegakkannya. | Manifest module dan authorization module |
| Access audit trail | Belum | Gateway dan middleware authorization business API tersedia sehingga keputusan allow/deny mempunyai correlation ID dan actor tepercaya. | Core/gateway; event sensitif dapat dimiliki module |
| Hierarchy projection ke module | Belum | Module nyata membutuhkan query descendants dan contract event hierarchy telah stabil. | Organization service menerbitkan; module menyimpan projection lokal |
| Reporting projection lintas module | Belum | Minimal dua module menerbitkan event bisnis stabil dengan kebutuhan report gabungan yang nyata. | Reporting service |
| Satuan dan konversi umum | Tersedia | Kelas satuan, sistem, konversi umum, API internal, dan audit event tersedia. Konversi khusus produk menunggu PIM/Inventory sebagai pemilik produk. | Core untuk katalog/konversi umum; PIM/Inventory untuk konversi per produk |

## Aturan keputusan

1. Gate yang belum terpenuhi tidak menghasilkan compatibility layer, tabel placeholder, atau status UI palsu.
2. Core menyimpan policy dan koordinasi global; data bisnis dan enforcement record tetap berada pada module pemiliknya.
3. Sebuah pekerjaan dianggap selesai hanya ketika mempunyai writer, reader, failure state, dan test yang membuktikan state sebelumnya tidak dapat menyamar sebagai state berikutnya.
4. Untuk deployment, urutannya tetap `catalogued -> entitled -> placed + migrated -> ready`; setiap fakta memiliki sumber kebenaran sendiri.

## Gate deployment saat ini

Fondasi worker dapat dibangun karena dua release unit awal sudah memiliki manifest, API/UI artifact definition, migration, Compose fragment, dan health endpoint. Worker wajib:

1. memvalidasi module, release, placement, dan entitlement aktif;
2. menjalankan pekerjaan melalui queue setelah transaksi onboarding selesai;
3. memasang artifact dan menjalankan migration secara idempotent;
4. menunggu health check nyata;
5. mencatat setiap percobaan serta hanya mengubah placement menjadi `ready` setelah seluruh tahap berhasil;
6. menyimpan kegagalan tanpa mengubah entitlement atau memberikan role.

Deployment SaaS production belum boleh dinyatakan aktif hanya karena worker tersedia. Aktivasi production menunggu image digest hasil CI, target/environment Dokploy yang terdaftar, secret reference dari secret store, domain/routing, serta health probe dari jaringan production. Installer on-prem tetap proses lokal terpisah dan tidak bergantung pada worker Control Plane vendor.

## Lihat juga

- [Standar module](02-module-standard.md) — lifecycle yang gate-nya diatur di sini
- [Release dan on-prem](03-release-and-on-prem.md) — gate deployment dan upgrade
- [Identity dan access](09-identity-and-access.md) — fondasi akses yang masih menunggu gate
- [Load dan concurrency testing](20-load-and-concurrency-testing.md) — gate terakhir sebelum module disebut selesai
- [Empat kebenaran lifecycle](../onboarding/empat-kebenaran.md) — ringkasan aturan keputusan nomor 4
- [Backlog audit fondasi](../todo/README.md) — temuan yang menunggu review, bukan keputusan
