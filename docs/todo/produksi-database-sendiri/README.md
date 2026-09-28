# Production dengan database sendiri

Rencana kerja, bukan desain kanonik. Ditulis 24 September 2026 setelah pemilik produk memutuskan
bahwa production di server kami diperlakukan sama dengan demo: punya database sendiri. Butir
kerjanya ada di [TODO production database sendiri](/todo/produksi-database-sendiri/TODO).

## Pertanyaan yang dijawab halaman ini

- Kenapa demo sudah punya database sendiri, sedangkan production semua tenant masih di satu
  database bersama?
- Bagaimana production mendapat database sendiri tanpa membuang pilihan database bersama?
- Siapa yang memilih, dan di mana pilihannya dibuat?

## Keadaan hari ini

Diperiksa di kode dan di server dev 1 (`103.122.2.94`) pada 24 September 2026.

| Environment | Datanya di mana | Yang menentukan |
| --- | --- | --- |
| Production di server kami | Database bersama `core_erp`, dipisah `tenant_id` | `RegisterBusiness` selalu menulis `database_name` kosong dan status `active` |
| Demo dan sandbox | Database sendiri `env_<tenant>_<env>_<hash>` di server yang sama | `environment:provision` mengisi `database_name` |
| Production di server client | Server client sendiri, tidak ada di server kami | `hosting = client_server`, dijaga constraint |

Tidak ada constraint database maupun penolakan di `environment:provision` yang melarang production
di server kami punya database sendiri. Mekanismenya (koneksi per environment, salin, upgrade,
penghapusan) sudah dipakai demo. Satu-satunya yang membuat production selalu di database bersama
adalah alur pembuatan tenant.

## Keputusan

| Kode | Keputusan | Alasan |
| --- | --- | --- |
| K-01 | Production di server kami **bawaannya database sendiri**. | Isolasi yang sama dengan demo. Data satu client tidak berbagi database dengan client lain, dan restore satu client tidak menyentuh client lain. |
| K-02 | **Database bersama tetap ada** sebagai pilihan paket termurah. | Biaya per tenant paling rendah. Pola ini disebut *vertically partitioned* / *hybrid* di panduan Azure. |
| K-03 | **Operator admin.erp yang memilih**, saat membuat client dan saat menambah production dari layar lingkungan. | Client baru hanya lahir dari admin.erp (lihat K-04). |
| K-04 | **Pendaftaran mandiri hilang di v1.** Endpoint `POST api/v1/business-registrations` dan halaman `auth/register` hanya untuk memudahkan pengembangan. `RegisterBusiness` tetap ada karena `POST internal/v1/tenants` memakainya. | Keputusan pemilik produk, sudah tercatat di `AGENTS.md` (PR #177). |
| K-05 | **Tidak ada schema per tenant.** Pemisahnya database. | Kode hanya mengenal dua bentuk: database bersama atau database sendiri. Schema akan jadi bentuk ketiga yang biaya operasinya hampir sama dengan database sendiri tetapi isolasinya lebih lemah. |
| K-06 | Tenant percobaan yang sudah ada di database bersama **dibiarkan**. Alat pindah dari database bersama ke database sendiri baru dibuat ketika ada client sungguhan yang memintanya. | Client masih nol; semua data hari ini data percobaan. |

### Keputusan yang masih terbuka

| Kode | Pertanyaan | Usulan |
| --- | --- | --- |
| K-07 | Bagaimana Core tahu sebuah production memilih database sendiri **sebelum** databasenya dibuat? `database_name` baru terisi saat penyiapan. | Kolom baru di `environments` yang menyimpan pilihannya, dijaga constraint. Menyimpulkannya dari `status = provisioning` tidak aman: production database bersama yang turun ke `degraded` lalu disiapkan ulang akan diberi database baru yang kosong, dan datanya tertinggal di database bersama. |
| K-08 | Setelah operator membuat client dengan production database sendiri, penyiapannya berjalan otomatis atau menunggu tombol **Siapkan**? | Samakan dengan demo hari ini. Tanyakan ke sesi *Akses akun control-plane* pola mana yang dipakai layar sekarang. |

## Sumber

- [Tenancy models for a multitenant solution](https://learn.microsoft.com/en-us/azure/architecture/guide/multitenant/considerations/tenancy-models), Azure Architecture Center. Bagian *Vertically partitioned deployments*: sebagian tenant di infrastruktur bersama, sebagian di infrastruktur sendiri. Syaratnya kode mendukung kedua bentuk, dan ada jalur pindah antar keduanya.
- [Multitenant SaaS patterns](https://learn.microsoft.com/en-us/azure/azure-sql/database/saas-tenancy-app-design-patterns), Azure SQL Database. Bagian *Hybrid sharded multitenant database model*: semua database tetap membawa kolom tenant, sebagian hanya berisi satu tenant.
- [Power Platform environments overview](https://learn.microsoft.com/en-us/power-platform/admin/environments-overview). Setiap environment Dynamics 365, production maupun sandbox, punya database Dataverse sendiri.
