# Pemeliharaan aset

Halaman ini untuk developer. Penyiapannya ada di [Setup maintenance](/apps/management-aset/master/maintenance/).

Pemeliharaan aset — di kode disebut **work order** — adalah dokumen satu pekerjaan pada satu aset: apa yang dikerjakan, siapa yang mengerjakan, apa yang diperiksa, dan hasilnya.

## Status dan siapa yang boleh memindahkannya

Berbeda dari aset, status work order **tidak disebar sebagai pemeriksaan di controller**. Ia dikumpulkan di satu kelas, `WorkOrderStatus`.

Alasannya ada di komentar kelas itu, dan itu alasan yang bagus: work order berpindah tangan. Perencana menjadwalkan, teknisi mengerjakan, penyelia menutup. Kalau daftar transisi dan hak yang menjaganya tersebar di banyak tempat, satu jalur pasti terlewat dan status bisa dilompati.

| Dari | Ke | Dijaga permission |
| --- | --- | --- |
| `draft` | `dijadwalkan` | `schedule` |
| `draft` | `dibatalkan` | `schedule` |
| `dijadwalkan` | `dikerjakan` | `execute` |
| `dijadwalkan` | `dibatalkan` | `schedule` |
| `dikerjakan` | `selesai` | `execute` |
| `dikerjakan` | `dibatalkan` | `schedule` |
| `selesai` | `ditutup` | `close` |
| `selesai` | `dibatalkan` | `schedule` |

`ditutup` dan `dibatalkan` adalah status akhir. Dokumen di sana tidak bisa diubah, tidak bisa berpindah status, dan tidak bisa diarsipkan jadi sesuatu yang lain.

**Perhatikan pembatalan dijaga `schedule`, bukan `update`.** Teknisi yang hanya boleh mengerjakan tidak boleh membatalkan pekerjaannya sendiri — membatalkan pekerjaan yang sudah berjalan adalah keputusan penjadwalan, bukan penyuntingan dokumen.

Kelas itu hanya memegang grafik transisi dan haknya. Syarat isi data — baris pekerjaan harus ada, checklist wajib harus terisi, sebab dan tindakan sesuai tipe — bergantung pada database dan ditegakkan controller.

## Endpoint

| Endpoint | Gunanya |
| --- | --- |
| `GET /api/v1/pemeliharaan-aset` | Daftar |
| `GET /api/v1/pemeliharaan-aset/saya` | Pekerjaan milik pengguna yang sedang masuk |
| `POST /api/v1/pemeliharaan-aset` | Membuat work order |
| `GET /api/v1/pemeliharaan-aset/{id}` | Detail, lengkap dengan riwayat perubahan status |
| `PATCH /api/v1/pemeliharaan-aset/{id}` | Mengubah isi |
| `DELETE /api/v1/pemeliharaan-aset/{id}` | Menghapus, hanya untuk `draft` dan `dibatalkan` |
| `POST /api/v1/pemeliharaan-aset/{id}/status` | Memindahkan status |
| `GET`/`PUT /api/v1/pemeliharaan-aset/{id}/jobs/{jobId}/checklist` | Mengisi checklist |
| `POST /api/v1/pemeliharaan-aset/{id}/jobs/{jobId}/checklist/dari-template` | Mengisi checklist dari template |

## Aturan yang dijaga

**Isi hanya bisa disunting pada status tertentu.** Diperiksa lewat `WorkOrderStatus::dapatDisunting()`, bukan daftar status yang ditulis ulang di tiap endpoint.

**Menghapus hanya boleh untuk `draft` dan `dibatalkan`.** Pekerjaan yang sudah dijadwalkan sudah jadi janji ke orang lain; ia dibatalkan, bukan dihilangkan.

**Riwayat status disimpan.** Tabel `tr_pemeliharaan_aset_status_log` mencatat tiap perpindahan. Detail work order mengembalikannya sebagai `status_log`.

Tabel lain yang terlibat: `tr_pemeliharaan_aset_details` menyimpan baris pekerjaan, dan `tr_pemeliharaan_aset_checklist` menyimpan hasil pemeriksaan yang dimekarkan dari template.

**Perubahan memakai penanda versi.** Kalau dokumen sudah berubah sejak terakhir dibaca, permintaan ditolak sebagai versi basi, bukan ditimpa. Dua orang yang membuka pekerjaan yang sama tidak saling menghapus perubahan tanpa sadar.

**Checklist dari template dimekarkan saat diisi**, bukan disalin saat template dibuat. Jadi memperbaiki template memperbaiki pekerjaan yang belum diisi, dan tidak mengubah yang sudah diisi.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Support/WorkOrderStatus.php` | Grafik transisi dan hak penjaganya |
| `api/app/Http/Controllers/transaksi/PemeliharaanAset/PemeliharaanAsetController.php` | Dokumen work order |
| `api/app/Http/Controllers/transaksi/PemeliharaanAset/PelaksanaanController.php` | Pengisian checklist dan pemekaran template |
| `ui/src/transactions/pemeliharaan-aset/WorkOrderPage.tsx` | Layar work order |
| `ui/src/transactions/pemeliharaan-aset/StatusValidationPage.tsx` | Layar matriks validasi status |
| `database/migrations/2026_08_15_110000_create_work_order_tables.php` | Tabel work order |
| `api/tests/Feature/WorkOrderTest.php`, `WorkOrderExecutionTest.php` | Test |

## Halaman terkait

- [Setup maintenance](/apps/management-aset/master/maintenance/) — tipe pekerjaan, varian, template
- [Register aset](/apps/management-aset/transaction/register-aset/) — aset yang dikerjakan
- [Identity dan access](/dev/09-identity-and-access) — permission dan duty
