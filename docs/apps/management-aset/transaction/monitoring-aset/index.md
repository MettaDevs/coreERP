# Monitoring aset

Monitoring aset adalah **pemeriksaan fisik** (stock opname) aset tetap di satu lokasi pada satu tanggal. Satu dokumen memuat daftar aset yang diperiksa; tiap baris mencatat apakah asetnya ada, kondisi fisik yang terlihat, dan keterangan pemeriksa. Sistem membandingkan temuan itu dengan catatan aset dan menandai tiap baris **Sesuai** atau **Tidak sesuai**.

Halaman ini sumber kebenaran untuk keputusan pemilik produk tanggal 30 September 2026. Kalau kode berbeda dari yang tertulis di sini, salah satunya perlu dibetulkan — tanyakan dulu mana yang benar.

## Keputusan yang mengikat

| No | Keputusan | Akibatnya di kode |
| --- | --- | --- |
| 1 | Temuan hanya dicatat dan dilaporkan. Menyelesaikan monitoring **tidak pernah** mengubah register aset. | Tidak ada satu pun tulis ke `aset_tr_aset` atau `aset_tr_penempatan_aset` di controller-nya. Perubahan sungguhan lewat [mutasi](/apps/management-aset/transaction/penempatan/) atau [dekomisioning](/apps/management-aset/transaction/siklus-aset/). |
| 2 | Satu lokasi per dokumen. Unit organisasi dan penanggung jawab opsional di header. **Isi otomatis** memasukkan semua aset yang tercatat di lokasi itu, termasuk yang sudah didekomisioning atau dilepas. Baris juga boleh ditambah dan dikeluarkan dengan tangan. Satu aset paling banyak sekali per dokumen. | `lokasi_aset_id` wajib; indeks unik parsial `(tenant_id, monitoring_aset_id, aset_id)`. |
| 3 | Sesuai/Tidak sesuai dihitung sistem dari status siklus hidup aset dan temuan Ada/Tidak ada; pemeriksa mengisi keterangan. Status draf → selesai. Menyelesaikan membekukan snapshot dan mengunci dokumen; koreksi dengan dokumen baru. | `AssetMonitoringStatus::result()`; kolom `sistem_*`, nilai, dan `hasil` diisi saat selesai. |
| 4 | Hak akses memperluas duty **Pantau aset** dengan buat, ubah, arsipkan, dan selesaikan. Tanpa workflow, tanpa pemisahan tugas. | `manifest/fixed-asset/asset-monitoring.yaml`. |
| 5 | Nomor wajib, reference `management-aset.monitoring-aset`, scope entitas legal, prefix diatur di **Atur nomor**. | Kode tidak pernah menyebut prefix; bawaannya `MONA` dari manifest. |
| 6 | Tindak lanjut: baris Tidak sesuai hanya menautkan layar mutasi atau dekomisioning. | Tautan manual di layar rincian; tidak ada dokumen yang dibuat otomatis. |
| 7 | Kondisi fisik per baris opsional, dipilih dari master kondisi aset, dan hanya temuan. | `kondisi_aset_id` pada baris tidak pernah ditulis ke aset. |

## Tiga hal yang mudah tertukar

**Keberadaan fisik bukan kondisi fisik.** "Ada/Tidak ada" (`ada`) adalah yang menentukan hasil. Kondisi fisik (`kondisi_aset_id`, misalnya Baik atau Rusak) hanya dicatat. Business Central tidak punya field kondisi aset, dan status di Dynamics 365 F&O adalah siklus hidup, bukan kondisi; master kondisi aset milik CoreERP sendiri, jadi ia tidak ikut menilai.

**Status di sistem bukan status monitoring.** Kolom "Kondisi System" pada rancangan QA adalah status siklus hidup aset (`lifecycle_state`: Diterima, Didekomisioning, Dilepas). Status monitoring adalah hasil perbandingannya dengan temuan.

**Tidak sesuai bukan berarti salah.** Aset yang sudah dimusnahkan tetapi ternyata masih ada di tempatnya adalah temuan yang dicari pemeriksaan ini — contoh di rancangan QA persis begitu: sistem "Di Musnahkan", fisik "Ada", hasil Tidak sesuai, keterangan "Belum dimusnahkan". Karena itu isi otomatis sengaja ikut membawa aset yang sudah dilepas.

## Cara hasil dihitung

| Status aset di register | Diharapkan | Temuan Ada | Temuan Tidak ada |
| --- | --- | --- | --- |
| Diterima (aktif) | ada | Sesuai | Tidak sesuai |
| Didekomisioning | tidak ada | Tidak sesuai | Sesuai |
| Dilepas (dijual/dimusnahkan) | tidak ada | Tidak sesuai | Sesuai |

Aturannya satu, `StatusAset::expectedOnSite()`, dipakai `AssetMonitoringStatus::result()`. Baris yang belum diperiksa (`ada` kosong) belum punya hasil, dan monitoring tidak dapat diselesaikan selama masih ada baris seperti itu.

Lokasi yang tercatat berbeda dari lokasi dokumen **tidak** membuat baris Tidak sesuai — keputusan 3 hanya membandingkan siklus hidup dengan keberadaan. Layar menampilkan "Tercatat di …" supaya pemeriksa melihatnya dan dapat mencatat mutasi.

## Status dokumen

| Status | Arti | Yang boleh |
| --- | --- | --- |
| `draft` | Sedang disusun dan diperiksa. Hasil dihitung langsung dari catatan aset sekarang. | ubah, isi otomatis, arsipkan, selesaikan |
| `selesai` | Temuan dan keadaan register dibekukan. | hanya dibaca dan dilampiri foto |

Koreksi atas monitoring yang sudah selesai adalah monitoring baru. Temuan yang sudah menjadi dasar laporan tidak disunting.

## Data yang disimpan

| Tabel | Isi |
| --- | --- |
| `aset_tr_monitoring_aset` | Header: nomor, entitas legal, lokasi, unit organisasi (opsional), penanggung jawab (opsional), tanggal, keterangan, status, `diselesaikan_pada`. |
| `aset_tr_monitoring_aset_details` | Satu aset per baris: `ada`, `kondisi_aset_id`, `keterangan`, lalu yang dibekukan saat selesai — `sistem_lifecycle_state`, `sistem_lokasi_id`, `sistem_org_unit_id`, `sistem_custodian_user_id`, `nilai_perolehan`, `akumulasi_penyusutan`, `nilai_buku`, dan `hasil`. |

Yang perlu dikenali:

- **Nilai dibaca dari buku komersial** — buku aset tanpa master atau yang master bukunya berlapisan `current` — dan bila ada lebih dari satu, yang kodenya paling awal. Aset tanpa buku seperti itu memakai nilai perolehan register, dengan akumulasi dan nilai buku kosong, bukan nol.
- **Penanggung jawab dibaca dari penempatan terakhir**, karena aset tidak menyimpannya.
- **Nama unit dan orang tidak dibekukan.** Idnya yang dibekukan; namanya diterjemahkan saat dibaca, sama seperti mutasi.
- **Baris yang dikeluarkan diarsipkan**, tidak dihapus, dan nomor barisnya tidak dipakai ulang: foto bukti menempel ke nomor baris.
- **Nomor unik per entitas legal**, `(tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL`, karena counternya per entitas legal.
- Kolom jejak, versi baris, dan klasifikasi data mengikuti [standar module](/dev/02-module-standard#kolom-jejak-pembuat-dan-pengubah). Log perubahan bawaan mencatat `tanggal`, `status`, dan `deleted_at` header.

## Endpoint

Semua di bawah `/api/modules/management-aset/v1/`. Kontraknya `contracts/src/paths/monitoring-aset.yaml`.

| Endpoint | Guna |
| --- | --- |
| `GET monitoring-aset` | Daftar, dengan saringan `status`, `dari`, `sampai`, `lokasi_aset_id`, beserta jumlah baris, belum diperiksa, dan tidak sesuai. |
| `POST monitoring-aset` | Draf baru; wajib `Idempotency-Key`. Baris boleh dikirim sekaligus atau kosong. |
| `GET monitoring-aset/{id}` | Rincian beserta baris dan `ETag`. |
| `PATCH monitoring-aset/{id}` | Ubah draf; `details` wajib dikirim dan dicocokkan menurut aset. |
| `DELETE monitoring-aset/{id}` | Arsipkan draf. |
| `POST monitoring-aset/{id}/isi-otomatis` | Tambahkan semua aset di lokasi yang belum ada di dokumen. |
| `POST monitoring-aset/{id}/selesaikan` | Bekukan temuan dan kunci dokumen. |

Semua perubahan atas dokumen yang sudah ada membawa versi baris; versi basi dijawab 409.

## Hak akses

Satu duty yang sudah ada, `management-aset.monitoring-aset.manage` ("Pantau aset"), kini berisi empat privilege:

| Privilege | Permission | Access level |
| --- | --- | --- |
| `monitoring-aset.view` | `monitoring-aset.read` | read |
| `monitoring-aset.maintain` | `read`, `create`, `update` | create, update |
| `monitoring-aset.retire` | `archive` | delete |
| `monitoring-aset.complete-check` | `complete` | invoke |

Permission ubah juga menjaga isi otomatis dan melampirkan foto. Kelimanya masuk `protected_permissions` kebijakan `management-aset.asset-responsibility`.

**Dokumen tanpa unit organisasi.** Kebijakan organisasi mencocokkan pasangan entitas legal dan unit; dokumen tanpa unit tidak punya unit untuk dicocokkan. Karena itu hanya pengguna yang menjangkau seluruh organisasi yang boleh membuat dan melihatnya; pengguna lain diminta memilih unit (422 pada `responsible_org_unit_id`). Aset yang ditambahkan, dengan tangan maupun lewat isi otomatis, selalu dibatasi jangkauan pengguna.

Layar rincian memakai daftar lokasi, kondisi, dan aset dari master masing-masing. Pengguna yang hanya memegang duty Pantau aset tanpa hak lihat master itu tetap bisa bekerja lewat isi otomatis; pilihan yang tidak dapat dimuat ditandai di layar.

## Nomor dokumen

Reference `management-aset.monitoring-aset`, scope entitas legal, prefix bawaan `MONA` dari manifest. Formatnya diatur admin tenant di **Atur nomor**; kode tidak menyebut prefix apa pun. Contoh "Monitoring. 001/Unik" di rancangan QA dapat disusun di sana. Lihat [number sequence](/dev/14-number-sequences).

## Laporan

`laporan-monitoring-aset` (izin `monitoring-aset.read`) dibaca mesin laporan Core dan layar Laporan monitoring aset. Isinya hanya monitoring yang sudah selesai, satu baris per aset, dari nilai yang dibekukan — laporan Agustus yang dicetak Desember tetap menyebut keadaan Agustus. Saringannya: periode, group, kelompok harta fiskal, jenis, aset, kondisi fisik, lokasi, penanggung jawab, dan unit organisasi. Penanggung jawab dan unit disaring pada nilai beku baris, bukan header. Layout bawaan Excel dibangun `AssetMonitoringReportLayout`.

## Lampiran

Header terdaftar di `AssetAttachments`, jadi foto bukti bisa dilampirkan ke dokumen atau ke nomor baris. Lampiran tetap boleh ditambah setelah selesai, seperti lampiran dokumen terposting di BC.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `database/migrations/2026_09_30_100000_create_asset_monitoring_tables.php` | Dua tabel dan indeksnya |
| `database/migrations/2026_09_30_100100_register_asset_monitoring_change_log_defaults.php` | Bawaan log perubahan |
| `src/Http/Controllers/transaksi/MonitoringAset/AssetMonitoringController.php` | Endpoint dan aturannya |
| `src/Models/transaksi/MonitoringAset/` | `AssetMonitoring`, `AssetMonitoringLine` |
| `src/Support/AssetMonitoringStatus.php` | Status dan hitungan hasil |
| `src/Reporting/Definitions/AssetMonitoringReport.php` | Dataset laporan |
| `manifest/fixed-asset/asset-monitoring.yaml` | Empat lapis keamanan dan reference nomor |
| `ui/transactions/monitoring-aset/` | Daftar dan rincian |
| `tests/Feature/AssetMonitoringTest.php` | Test feature |
| `loadtest/k6/monitoring.js` | Skenario beban dan balapan |

## Halaman terkait

- [Penempatan dan mutasi](/apps/management-aset/transaction/penempatan/) — tindak lanjut aset yang berada di tempat lain
- [Dokumen siklus aset](/apps/management-aset/transaction/siklus-aset/) — tindak lanjut aset yang hilang
- [Laporan dan ekspor](/apps/management-aset/transaction/laporan/)
- [Layar setup yang belum berisi](/apps/management-aset/transaction/monitoring/)
