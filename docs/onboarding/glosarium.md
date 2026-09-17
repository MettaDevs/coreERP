# Glosarium

Istilah yang dipakai tim ini punya arti spesifik. Beberapa di antaranya mirip tapi tidak sama, dan tertukar sedikit saja bisa menghasilkan schema yang salah.

## Tenant dan organisasi

| Istilah | Arti di CoreERP |
| --- | --- |
| **Tenant** | Pemegang kontrak dan batas isolasi data tertinggi. |
| **Organization** | Identitas bisnis stabil **di dalam** tenant; diklasifikasikan sebagai legal entity atau operating unit. |
| **Legal entity** | Organization dengan konsekuensi hukum, ledger, pajak, dan statutory reporting sendiri. |
| **Operating unit** | Organization untuk tanggung jawab operasional: business unit, department, cost center, value stream, atau channel. |
| **Establishment** | Peran operating unit dalam hierarchy purpose khusus — **bukan** tipe organization terpisah. |
| **Organization hierarchy** | Penempatan parent-child organization untuk purpose dan version tertentu; bukan kedalaman permanen. |

::: warning Tiga jebakan
**Tenant ≠ organization.** Tenant adalah batas kontrak dan isolasi data. Organization adalah identitas bisnis di dalamnya. Tenant bukan root dari pohon organisasi.

**Parent-child tidak melekat pada organization.** Ia hidup pada node hierarchy berversi. Jangan menyimpan `parent_id` permanen untuk identitas organization Core.

**Legal entity bukan sekadar label.** Ia menentukan ledger, pajak, dan statutory reporting. Jangan dipakai untuk sekadar mengelompokkan.
:::

Aturan pengecualiannya: struktur klasifikasi **milik domain app** boleh memakai foreign key permanen. Contohnya rantai `group aset → kategori aset → jenis aset` di Management Aset — itu bukan organization hierarchy Core, jadi larangan `parent_id` tidak berlaku di sana.

Detail: [Tenant dan hierarki organisasi](/dev/01a-tenant-and-org-hierarchy), [Query scope dan schema](/dev/08-query-scopes-and-schema).

## App dan module

| Istilah | Arti di CoreERP |
| --- | --- |
| **App** | Produk atau kemampuan bisnis yang dapat dipasang dan dirilis mandiri, misalnya POS atau Booking. Satu app punya satu repository. |
| **Addon app** | App tambahan, biasanya integration atau kebutuhan khusus customer. |
| **Release unit** | Satu app sebagai satuan rilis: API, UI artifact, database, migration, kontrak, dan image Docker. |
| **Manifest** | Deklarasi app: navigasi, security, duty, data policy, reference nomor. Sumber kebenaran untuk Core. |
| **Module / modul** | Nama teknis **lama** untuk hal yang sekarang disebut app. Masih tersisa pada nama tabel, endpoint, dan kode yang sudah ada, jadi jangan dihapus dari sana. Tetapi untuk desain baru, dokumen baru, dan komunikasi produk, pakai **app**. |

::: tip Kenapa dua kata itu masih bercampur
Sebagian dokumen ditulis sebelum istilahnya diseragamkan, dan mengganti nama tabel atau endpoint hanya demi keseragaman kata bukan perubahan yang sepadan risikonya. Yang berlaku: tulisan baru memakai `app`, tulisan lama dibiarkan sampai berkasnya memang perlu disunting karena alasan lain.
:::

## Lifecycle

| Istilah | Arti di CoreERP |
| --- | --- |
| **Catalogued** | Produknya dikenal platform. |
| **Entitled** | Tenant berhak memakainya. Belum berarti terpasang. |
| **Installed** | Migration module berhasil dijalankan untuk tenant itu, dan barisnya tercatat di `core_module_installations`. |

Ketiganya adalah fakta terpisah. Lihat [Tiga kebenaran lifecycle](/onboarding/tiga-kebenaran).

**Placed**, **migrated**, dan **ready** adalah istilah jalur hosting container, yang dibuang pada
10 September 2026. Ketiganya tidak dipakai lagi.

## Deployment

| Istilah | Arti di CoreERP |
| --- | --- |
| **Control plane** | Layanan global vendor untuk mengelola tenant, lisensi, deployment, operasi, dan billing **SaaS**. Itu perannya, bukan nama satu aplikasi. |
| **admin.erp** | Aplikasi yang menjalankan peran itu hari ini: kode di `apps/control-plane`, namespace `ControlPlane`, alamatnya `admin.erp.<domain>`. Dipakai operator kita, bukan pengguna klinik. Halamannya [admin.erp: konsol operator](/dev/31-admin-erp-control-plane). |
| **Application plane** | API/UI/database yang menjalankan fungsi ERP untuk tenant. |
| **Pool / pooled** | Tenant berbagi deployment dan database module, dipisahkan oleh `tenant_id`. |
| **Silo / isolated** | Resource suatu module ditempatkan khusus untuk satu tenant. |
| **Placement** | Nama lokasi penempatan artifact app. Pada `isolated`, namanya diberi akhiran id tenant. |
| **On-prem perpetual** | Deployment Compose di server customer dengan app yang dibeli saja, state instalasi lokal, lisensi perpetual bertanda tangan, dan update manual. |
| **Managed support connector** | Konektor outbound mTLS opsional yang disetujui customer untuk mengirim health/version minimum. Bukan remote shell, tidak mengirim data bisnis. |

## Identity dan akses

| Istilah | Arti di CoreERP |
| --- | --- |
| **Membership** | Kaitan user dengan tenant, beserta system role-nya. |
| **Security role** | Kumpulan duty yang diberikan ke membership lewat role assignment. |
| **Duty** | Tanggung jawab bisnis; berisi privilege. Datang dari manifest app. |
| **Privilege / permission** | Izin operasi konkret. |
| **Data policy scope** | Pembatas organisasi pada satu role assignment. Kolom `null` berarti tidak dibatasi. |
| **Workforce / position** | Model orang dan jabatan. Satu position hanya boleh punya satu worker aktif. |
| **SoD** | Segregation of Duties — pengaju tidak boleh sekaligus memverifikasi atau menyetujui. |

Detail: [Identity dan access](/dev/09-identity-and-access).

## Istilah yang tidak boleh muncul di UI end user

`entitlement`, `artifact`, `deployment registry`, `installation registry`, `TenantContext`, `tenant_id`, `placement`, dan istilah arsitektur lain **dilarang** tampil di layar pengguna bisnis — termasuk di hint, label, dialog, empty state, error, dan status.

Pakai bahasa sehari-hari yang menjelaskan tindakan atau dampaknya. Pengecualiannya hanya layar yang memang ditujukan untuk developer atau operator teknis.

## Lihat juga

- [Indeks desain kanonik](/dev/) — glosarium singkat versi asli ada di sini
- [Tenant dan hierarki organisasi](/dev/01a-tenant-and-org-hierarchy)
- [Identity dan access](/dev/09-identity-and-access)
- [Model organisasi Dynamics 365](/references/dynamics-365-organization-model) — asal banyak istilah di atas
