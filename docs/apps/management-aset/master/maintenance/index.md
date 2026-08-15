# Setup maintenance

Halaman ini untuk developer. Pelaksanaannya ada di [Pemeliharaan aset](/apps/management-aset/transaction/pemeliharaan-aset/); di sini hanya penyiapannya.

Setup maintenance menjawab satu pertanyaan: **kalau ada pekerjaan pada aset jenis tertentu, apa saja yang harus dikerjakan dan diperiksa?**

## Susunannya

| Master | Tabel | Isi |
| --- | --- | --- |
| **Tipe pekerjaan** | `m_maintenance_job_type` | Jenis pekerjaan, misalnya "Servis berkala" |
| **Varian** | `m_maintenance_job_type_variant` | Turunan pekerjaan, misalnya "Servis 10.000 km" |
| **Default** | `m_maintenance_job_type_default` | Nilai bawaan saat pekerjaan dibuat |
| **Variabel checklist** | `m_maintenance_checklist_variable`, pilihan nilainya di `m_maintenance_checklist_variable_value` | Hal yang diukur atau dinilai |
| **Template checklist** | `m_maintenance_checklist_template`, barisnya di `m_maintenance_checklist_template_line` | Susunan baris pemeriksaan |
| **Kaitan ke jenis aset** | `m_maintenance_job_type_asset_type` | Pekerjaan mana berlaku untuk jenis aset mana |

::: tip Tabel yang sudah dihapus
`m_maintenance_job_type_requirement` pernah ada untuk menyimpan syarat keahlian dan sertifikat, lalu dihapus migration `2026_08_15_140000`. Alasannya ada di [Master work order](/apps/management-aset/master/work-order/): kompetensi itu milik Human Resources dan dipasang pada pekerja, bukan ditulis sebagai teks bebas di sini.
:::

Ditambah master pendukung yang dipakai saat pekerjaan dikerjakan — tipe work order, tingkat layanan, sebab kerusakan, tindakan perbaikan, dan keahlian — yang dibahas di [Master work order](/apps/management-aset/master/work-order/).

Semuanya mewarisi perilaku di [Master data](/apps/management-aset/master/).

::: warning Dua master lama yang namanya mirip
`item-checklist-maintenance` dan `analisa-maintenance` **bukan** bagian dari susunan di atas. Keduanya master sederhana dari rancangan sebelumnya, tanpa kolom khusus, dan tidak dipakai template checklist maupun tipe pekerjaan.

Ini penting saat memeriksa cakupan: skenario load test yang memuat kedua nama itu **tidak** menguji setup maintenance. Nama yang kebetulan terdengar mirip bukan cakupan.
:::

## Template checklist bisa bersarang

Satu baris template bisa menyisipkan template lain. Ini supaya bagian yang berulang — misalnya "pemeriksaan keselamatan dasar" — ditulis sekali dan dipakai banyak template.

Jenis baris yang tersedia: `header`, `text`, `measurement`, `variable`, dan `template`.

Aturan yang dijaga:

- Baris `measurement` **wajib** punya satuan.
- Baris `variable` **wajib** menunjuk variabel checklist.
- Baris `header` tidak pernah bisa ditandai wajib — ia hanya memberi struktur dan tidak pernah diisi teknisi, jadi kalau bisa wajib ia akan menahan penyelesaian pekerjaan selamanya.

### Sarang yang melingkar dihentikan

Kalau template A menyisipkan B dan B menyisipkan A, pemekaran akan berputar tanpa henti. Kodenya menyimpan daftar template yang sudah dilewati pada cabang yang sedang ditelusuri; kalau bertemu yang sama lagi, cabang itu berhenti dan mengembalikan kosong.

Yang perlu diperhatikan: daftar itu **per cabang**, bukan global. Jadi dua baris berbeda yang sama-sama menyisipkan template B tetap menghasilkan B dua kali — itu memang benar, karena bukan lingkaran, hanya pengulangan yang disengaja.

## Endpoint penautan

Beberapa hubungan disunting sebagai "ganti seluruh daftar", bukan tambah-satu-per-satu:

| Endpoint | Mengganti |
| --- | --- |
| `PUT /api/v1/maintenance-job-types/{id}/asset-types` | Jenis aset yang berlaku bagi pekerjaan ini |
| `PUT /api/v1/jenis-aset/{id}/maintenance-job-types` | Kebalikannya, dari sisi jenis aset |
| `PUT /api/v1/maintenance-checklist-variables/{id}/values` | Pilihan nilai variabel |
| `PUT /api/v1/maintenance-checklist-templates/{id}/lines` | Baris template |
| `POST /api/v1/maintenance-job-type-defaults/{id}/copy` | Menyalin default ke tempat lain |

### Kenapa penautan mengunci baris

Endpoint "ganti seluruh daftar" bekerja dengan menghapus lalu menyisipkan ulang. Dua permintaan yang berjalan bersamaan bisa saling menyela: yang satu menghapus, yang lain menghapus dan menyisipkan, lalu yang pertama menyisipkan di atasnya. Hasilnya gabungan dua daftar — yang tidak diminta siapa pun, dan yang tidak ditolak batasan database mana pun karena tiap barisnya sah.

Karena itu tiap penggantian menahan baris pemiliknya lebih dulu.

Tabel kaitan pekerjaan × jenis aset punya kerumitan tambahan: ia disunting dari **dua arah**. Mengunci pemilik masing-masing arah tidak menolong, karena keduanya memegang kunci pada tabel berbeda. Jadi kedua arah mengunci sisi yang sama — sisi pekerjaan — atas gabungan daftar lama dan baru, dalam urutan id yang tetap supaya tidak saling menunggu.

Kalau Anda menambah endpoint sejenis, ikuti pola itu. Lihat [gate concurrency](/dev/20-load-and-concurrency-testing).

## Data awal

Setup maintenance ikut diisi saat tenant disiapkan, dari template `id:maintenance:starter:v1` di `api/config/management_aset.php`. Bisa juga dijalankan manual untuk tenant yang sudah ada:

```bash
php artisan management-aset:seed-maintenance --tenant=<ULID>
```

Sifatnya idempoten dan tidak menimpa perubahan tenant.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Http/Controllers/master/MaintenanceJobTypeController.php` | Tipe pekerjaan |
| `api/app/Http/Controllers/master/MaintenanceJobTypeVariantController.php` | Varian |
| `api/app/Http/Controllers/master/MaintenanceJobTypeDefaultController.php` | Nilai bawaan |
| `api/app/Http/Controllers/master/MaintenanceChecklistVariableController.php` | Variabel checklist |
| `api/app/Http/Controllers/master/MaintenanceChecklistTemplateController.php` | Template checklist |
| `api/app/Http/Controllers/master/MaintenanceSetupLinkController.php` | Semua endpoint penautan dan penguncian barisnya |
| `api/app/Http/Controllers/master/ItemChecklistMaintenanceController.php`, `AnalisaMaintenanceController.php` | Dua master lama yang disebut di peringatan atas |
| `database/migrations/2026_08_14_110000_create_maintenance_setup_tables.php` | Tabel setup |
| `loadtest/k6/maintenance.js` | Uji beban, termasuk balapan penautan |

## Halaman terkait

- [Pemeliharaan aset](/apps/management-aset/transaction/pemeliharaan-aset/) — pelaksanaan
- [Jenis aset dan atribut](/apps/management-aset/master/jenisaset/) — tempat pekerjaan dikaitkan
- [Master data](/apps/management-aset/master/) — perilaku bersama
