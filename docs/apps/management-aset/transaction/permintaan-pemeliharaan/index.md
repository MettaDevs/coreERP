# Permintaan pemeliharaan

Permintaan pemeliharaan adalah **laporan dari sebuah unit** bahwa aset — atau sebuah lokasi, bila yang rusak bukan satu aset tertentu — butuh perbaikan. Satu baris di sini adalah satu laporan: siapa yang melapor, apa yang rusak, seberapa mendesak, dan apa keputusan perencana atasnya. Padanannya *Maintenance requests* di Dynamics 365 F&O Asset Management ([overview](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/manage-maintenance-requests/maintenance-request-overview), [request types](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/setup-for-maintenance-requests/request-types), [create a work order from a request](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/manage-maintenance-requests/create-work-order-from-a-maintenance-request)). Business Central tidak punya padanan di Fixed Assets; *Maintenance Registration* BC hanya mencatat servis yang sudah terjadi.

## Yang mudah tertukar

**Permintaan bukan work order.** Permintaan menyatakan ada masalah; work order menyatakan pekerjaan yang akan dikerjakan, oleh siapa, dengan checklist apa. F&O memisahkannya dengan alasan yang sama: yang melapor (perawat, staf ruangan) bukan orang yang merencanakan pekerjaan. Membuat work order dari permintaan adalah tindakan perencana, bukan efek samping mengajukan.

**Unit pelapor bukan unit penanggung jawab aset.** `responsible_org_unit_id` pada permintaan adalah unit yang melapor, dan itulah pemilik permintaan menurut kebijakan organisasi. Work order yang dibuat darinya memakai unit yang sama sebagai bawaan; perencana dapat mengubahnya di work order draf.

## Status

Setiap perpindahan adalah tombol tersendiri — **Ajukan**, **Terima**, **Tolak**, **Buat work order** — bukan perubahan otomatis saat menyimpan, seperti dokumen BC dan F&O.

| Dari | Ke | Tindakan | Permission |
| --- | --- | --- | --- |
| — | `draft` | Buat permintaan | `create` |
| `draft` | `draft` | Ubah isian | `update` |
| `draft` | `diajukan` | Ajukan | `submit` |
| `diajukan` | `diterima` | Terima | `review` |
| `diajukan` | `ditolak` | Tolak, dengan alasan wajib | `review` |
| `diterima` | `work_order_dibuat` | Buat work order | `review` **dan** `pemeliharaan-aset.create` |
| `draft`, `ditolak` | diarsipkan | Arsipkan | `archive` |

F&O menyusun tahapannya sebagai *lifecycle model* yang diatur tenant. **Keputusan:** alurnya tetap dan pendek di sini, karena satu-satunya keputusan yang dibutuhkan adalah diterima atau ditolak; lifecycle model yang dapat disusun tenant menunggu kebutuhan nyata.

## Data yang disimpan

| Tabel | Isi |
| --- | --- |
| `aset_m_jenis_permintaan_pemeliharaan` | Master jenis permintaan (*Maintenance request type*), dengan `tipe_work_order_id` opsional yang diwarisi work order. |
| `aset_tr_permintaan_pemeliharaan` | Kode (Number Sequence per entitas legal), entitas legal, unit pelapor, jenis, aset dan/atau lokasi (salah satu wajib, constraint `CHECK`), deskripsi, tingkat layanan, sebab kerusakan, status, waktu diajukan dan diputuskan, pemutus (`diputuskan_oleh_user_id`, data pribadi pseudonim), alasan penolakan, dan `pemeliharaan_aset_id`. |

Pelapor diambil dari kolom jejak `created_by_user_id` — padanan *Started by* F&O yang terisi otomatis — dan ditampilkan dengan nama, seperti pemutusnya.

## Aturan yang dijaga, dan alasannya

- **Pelapor tidak dapat membuat work order.** Duty `permintaan-pemeliharaan.request` tidak memegang `pemeliharaan-aset.create`, dan membuat work order dari permintaan menuntut keduanya: `review` dan hak membuat work order. Tanpa pemisahan ini setiap orang yang boleh melapor dapat melompati perencana.
- **Isian hanya dapat diubah selama draf.** Sesudah diajukan, yang ditinjau perencana harus sama dengan yang dilaporkan.
- **Satu permintaan, paling banyak satu work order.** Kunci idempotensi work order adalah `permintaan:<id>`, dan status `work_order_dibuat` menutup jalan membuat yang kedua. F&O membolehkan satu work order menampung banyak permintaan; menggabungkan beberapa permintaan ke satu work order **belum ada**.
- **Work order lewat jalur work order biasa.** `WorkOrderCreator` yang sama dengan layar work order dan jadwal: nomor, checklist bawaan, pemeriksaan jenis pekerjaan × jenis aset, dan aset yang masih beredar.
- **Aset boleh menyusul.** Pelapor boleh hanya menyebut lokasi; perencana memilih asetnya saat membuat work order, dan aset itu disimpan kembali ke permintaan (F&O: *Asset verified*). Lokasi yang dikosongkan pelapor diisi lokasi aset saat ini, supaya perencana langsung tahu tempatnya.
- **Aset dalam jangkauan dan masih beredar.** Aset yang dilaporkan harus dapat dilihat pelapor (`OrganizationScope::asetQuery`) dan belum dihentikan atau dilepas.
- **Versi baris di setiap langkah.** Ubah, arsipkan, ajukan, terima, tolak, dan buat work order semuanya mengklaim versi permintaan; dua perencana yang memutuskan bersamaan tidak saling menimpa.

## Lampiran

Tabel ini terdaftar sebagai jenis record lampiran Core (`AssetAttachments::types()`), untuk foto kerusakan — padanan *Add photos* di aplikasi seluler F&O. Hak melampiri mengikuti permission `update` dan jangkauan organisasi permintaan. Foto **tidak** disalin ke work order; work order menautkan permintaannya.

## Endpoint

| Endpoint | Guna |
| --- | --- |
| `GET/POST jenis-permintaan-pemeliharaan`, `GET/PATCH/DELETE .../{id}` | Master jenis permintaan. |
| `GET/POST permintaan-pemeliharaan` | Daftar dan buat draf. |
| `GET/PATCH/DELETE permintaan-pemeliharaan/{id}` | Rincian, ubah draf, arsipkan. |
| `POST permintaan-pemeliharaan/{id}/ajukan`, `/terima`, `/tolak`, `/work-order` | Langkah alur status. |

Kontraknya di `contracts/src/paths/permintaan-pemeliharaan.yaml`.

## Hak akses

`manifest/maintenance/maintenance-requests.yaml` memuat dua duty, padanan peran *Maintenance requester* dan perencana di F&O:

| Duty | Privilege | Permission |
| --- | --- | --- |
| `permintaan-pemeliharaan.request` (Ajukan permintaan pemeliharaan) | `compose` | read, create, update, archive, submit |
| `permintaan-pemeliharaan.review-requests` (Tinjau permintaan pemeliharaan) | `decide` | read, review |

Seluruh permission permintaan masuk kebijakan `management-aset.asset-responsibility`. Master jenis permintaan ada di `maintenance/maintenance-request-types.yaml`. Riwayat perubahan deskripsi, status, dan alasan penolakan menyala bawaan lewat migration `register_maintenance_request_change_log_defaults`.

## Belum ada

- Lifecycle model yang dapat disusun tenant, dan status *Depot repair*.
- Beberapa permintaan dalam satu work order; permintaan sebagai baris jadwal (*reference type Maintenance request*).
- Gejala/area/tipe kerusakan (*Fault symptom/area/type*) F&O; yang ada hanya sebab kerusakan.
- Peringatan saat aset yang sama sudah punya permintaan terbuka, atau masih bergaransi.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `database/migrations/2026_10_01_120000_create_maintenance_request_tables.php` | Tabel jenis dan permintaan. |
| `src/Http/Controllers/transaksi/MaintenanceRequest/MaintenanceRequestController.php` | Seluruh endpoint permintaan. |
| `src/Http/Controllers/master/MaintenanceRequestTypeController.php` | Master jenis permintaan. |
| `src/Support/MaintenanceRequestStatus.php` | Status dan yang boleh diarsipkan. |
| `ui/transactions/maintenance-requests/` | Daftar dan rincian. |
| `tests/Feature/MaintenanceRequestTest.php` | Test: alur lengkap sampai work order, lokasi tanpa aset, penolakan dan arsip, pemisahan permission, duty manifest sungguhan, jangkauan organisasi dan idempotensi, pendaftaran lampiran. |

## Halaman terkait

- [Pemeliharaan aset (work order)](/apps/management-aset/transaction/pemeliharaan-aset/)
- [Pemeliharaan preventif](/apps/management-aset/transaction/pemeliharaan-preventif/)
- [Master work order](/apps/management-aset/master/work-order/)
