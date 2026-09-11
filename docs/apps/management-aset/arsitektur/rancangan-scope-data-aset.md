# Rancangan scope data Management Aset

Status: diimplementasikan untuk register aset; master referensi tetap tenant-wide.

## Keputusan

Gunakan dua pemeriksaan yang terpisah pada setiap endpoint, termasuk endpoint yang mengisi datatable:

1. **Permission** menentukan tindakan yang boleh dilakukan pengguna pada resource, misalnya `management-aset.aset.read` atau `management-aset.aset.mutate`.
2. **Scope organisasi** menentukan baris data organisasi mana yang boleh dibaca atau diubah oleh pengguna tersebut.

Tenant tetap merupakan batas isolasi paling luar. Scope organisasi tidak boleh pernah memperluas data ke tenant lain.

Ini mengikuti Dynamics 365 Finance: role diberikan berdasarkan tanggung jawab/proses bisnis, sedangkan policy data dapat membatasi role tersebut ke satu organisasi. Role tersusun dari duty, privilege, dan permission; hierarchy organisasi dipakai hanya bila assignment meminta cakupan turunan.

Referensi:

- [Role-based security — Microsoft Learn](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/role-based-security)
- [Manage users and security roles — Microsoft Learn](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/assign-users-security-roles)
- [Create an organization hierarchy — Microsoft Learn](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/tasks/create-organization-hierarchy)

## Cara kerja untuk pengguna dan manager

| Pengguna | Role / tanggung jawab | Scope assignment | Hasil |
| --- | --- | --- | --- |
| Karyawan kantor Negarow | Duty yang memuat privilege baca aset | Organisasi Negarow, tanpa turunan | Hanya aset, rencana, dan dokumen yang berada pada unit Negarow. |
| Manager kantor Negarow | Duty aset yang diperlukan manager, misalnya baca dan mutasi | Organisasi Negarow, dengan `include_descendants=true` hanya bila kantor/unit di bawahnya harus ikut | Data Negarow dan unit turunannya; bukan kantor sejajar atau kantor lain. |
| Manager seluruh bisnis | Role/duty yang sama atau lebih luas sesuai kebutuhan kerja | Organisasi akar pada hierarchy yang dipilih, dengan turunan | Data seluruh subtree yang memang ditugaskan. |

Pengaturan dilakukan oleh administrator tenant pada **assignment role**, bukan dengan membuat role baru untuk setiap kantor:

1. Buat atau pilih security role dari duty aset yang diperlukan.
2. Assign ke user secara manual, atau otomatis dari position bila workflow tenaga kerja sudah tersedia.
3. Pada assignment, pilih organization, hierarchy efektif yang menjadi acuan, dan pilihan sertakan turunan.
4. Simpan masa berlaku dan audit perubahan.

Jika seorang pengguna memiliki lebih dari satu assignment aktif, scope efektifnya adalah gabungan scope tersebut. Role hierarchy dan organization hierarchy tetap dua struktur berbeda: role parent dapat mewarisi duty, tetapi tidak otomatis memberi akses ke organisasi lain.

## Bukan batas organisasi saja, bukan tanggung jawab saja

`legal_entity_id` diperlukan untuk fakta hukum, pembukuan, pajak, dan nomor dokumen. Ia bukan pengganti scope operasional kantor. `org_unit_id` menunjukkan unit yang memakai atau bertanggung jawab atas aset. Karena itu:

- data finansial/registrasi aset selalu dibatasi tenant dan legal entity;
- visibilitas operasional kantor dibatasi tenant, legal entity bila relevan, lalu unit organisasi yang diizinkan;
- hierarchy hanya memperluas arti “turunan”, tidak pernah menjadi permission dengan sendirinya.

## Aturan scope per data

| Data / datatable | Kunci pembatas | Aturan baca dan ubah |
| --- | --- | --- |
| Master klasifikasi (`group`, `jenis`, `model`, `kondisi`, `pabrikan`, profil penyusutan) | `tenant_id` | Ini konfigurasi tenant bersama. Tetap perlu permission resource; tidak disaring per kantor kecuali suatu master kelak benar-benar dimiliki unit tertentu. |
| Lokasi aset | `tenant_id` + `org_unit_id` pada lokasi | Nama lokasi dapat mengungkap kantor lain. `org_unit_id` sudah tersedia sebagai jembatan ke unit organisasi; pakai kolom itu untuk menyaring, dan tetap jangan menyamakan pohon lokasi dengan hierarchy organisasi. |
| Register aset | `tenant_id`, `legal_entity_id`, unit pemakaian aktif | Baris hanya terlihat bila `usage_org_unit_id` penempatan efektif termasuk scope assignment. Aset yang belum ditempatkan memakai unit penerima sebagai fallback; bila keduanya kosong, hanya role yang memang diberi scope legal entity/tenant administratif yang boleh melihatnya. |
| Riwayat penempatan | scope dari aset induknya | Jangan expose sebagai list bebas. Setelah aset lolos scope, riwayatnya boleh dibaca sesuai permission aset. |
| Perencanaan aset | `tenant_id`, `legal_entity_id`, `planning_org_unit_id` | Filter dan validasi tulis terhadap scope unit perencana. Implementasi saat ini hanya memaksa unit aktif saat create/update; list dan show belum membatasi semua assignment yang diizinkan. |
| Permintaan pembelian, pemeliharaan, penjualan, pemusnahan | `tenant_id`, `legal_entity_id`, unit tanggung jawab | Dokumen harus menyimpan unit tanggung jawab, atau mendapatkannya secara konsisten dari aset induk. List dan detail mengikuti scope tersebut. |
| Penyusutan dan monitoring | `tenant_id`, `legal_entity_id`, `usage_org_unit_id` | Filter menurut unit pemakaian yang disalin pada periode/dokumen. Role keuangan lintas unit dapat diberikan scope lebih luas, bukan bypass di aplikasi. |

Filter selalu diterapkan pada query server sebelum pagination, total, pengurutan, detail, ekspor, dan mutasi. Filter UI hanya membantu kenyamanan; bukan kontrol keamanan.

## Kontrak yang diperlukan dari CoreERP

Aplikasi aset tidak boleh membaca database CoreERP. Sebelum filter organisasi diimplementasikan, CoreERP harus menjadi sumber kebenaran untuk assignment aktif dan mengirim konteks tepercaya berikut:

```text
tenant_id
user_id
permissions
organization scopes: organization_id, hierarchy_version_id,
                     include_descendants, effective period
legal-entity scope bila assignment membutuhkannya
```

Acuan CoreERP adalah [Identity, responsibility-based security, dan organization scope](/dev/09-identity-and-access): satu worker dapat memiliki dua position assignment aktif pada business unit berbeda, dan satu role assignment dapat memiliki beberapa scope `grant`/`revoke`. Contoh: satu petugas aset bekerja pada Negarow dan Denpasar tanpa identity, membership, atau role duplikat.

Aplikasi harus menerima hasil scope yang sudah diselesaikan melalui token konteks bertanda tangan atau projection/event lokal dari CoreERP. Jangan menerima daftar organization dari body, query parameter, atau header bebas; jangan query database CoreERP secara langsung. Untuk subtree besar, gunakan projection lokal dari scope efektif agar daftar tidak membuat token besar atau menambah panggilan Core pada setiap baris datatable.

Workspace aktif (`legal_entity_id` dan `org_unit_id`) hanya memilih konteks kerja yang sudah diizinkan. Ia **bukan** bukti bahwa pengguna hanya boleh melihat unit itu; manager dapat memiliki beberapa unit yang sah. Otorisasi memakai seluruh scope assignment aktif, bukan hanya unit yang dipilih di workspace.

## Implementasi saat ini

Core menerbitkan token lima menit dengan `organization_scope` yang sudah diselesaikan. Register aset menyimpan `responsible_org_unit_id`, menolak create/mutasi di luar scope token, dan membatasi daftar/detail/riwayat aset pada scope tersebut. Aset lama tanpa unit penanggung jawab tidak muncul untuk scope unit terbatas.

Dokumen siklus, perencanaan, lokasi, monitoring, dan penyusutan masih harus dipindahkan ke guard yang sama sebelum dinyatakan memiliki coverage scope penuh.

## Urutan implementasi setelah disetujui

1. CoreERP menyediakan assignment scope aktif dan contract/projection tepercaya untuk aplikasi.
2. Tetapkan pemilik scope untuk setiap tabel pada tabel di atas; tambahkan unit tanggung jawab pada dokumen/lokasi yang belum memilikinya.
3. Buat satu query scope bersama di aplikasi untuk seluruh endpoint tiap resource, lalu pakai pada list, detail, riwayat, ekspor, dan mutation target.
4. Tambahkan test minimal: karyawan Negarow tidak dapat membaca, membuka detail, atau memutasi aset kantor lain; manager Negarow dapat membaca subtree yang ditugaskan; user dengan permission tanpa scope tidak memperoleh baris.
5. Jalankan load test multi-tenant/multi-instance sesuai `modules/apperp/management-aset/loadtest/README.md`, termasuk pemeriksaan SQL bahwa tidak ada baris lintas scope.

## Keputusan yang masih memerlukan persetujuan owner

- Apakah aset yang belum memiliki unit penerima/pemakaian hanya boleh dilihat oleh role administrasi legal entity, atau harus selalu diwajibkan memiliki unit sejak penerimaan?
- Apakah lokasi aset dianggap data operasional yang harus discope per unit, atau master tenant yang boleh terlihat semua pemegang permission lokasi?
- Duty mana yang boleh dipakai manager: hanya monitoring, atau juga mutasi dan pengelolaan register? Hak ini sengaja tidak disimpulkan dari jabatan “manager”.
