# Tahun rencana

Tahun rencana adalah master tahun kalender untuk mengelompokkan kebutuhan suatu perusahaan. Satu record menunjuk satu tahun pada satu perusahaan. Dokumen menyimpan ID master agar pilihan yang dinonaktifkan tetap dapat dikenali pada dokumen lama.

## Batas konsep

Tahun rencana tidak menggantikan kalender fiskal dan tidak mengesahkan anggaran. Tahun 2027 berarti kebutuhan pada 1 Januari sampai 31 Desember 2027. Tanggal dokumen boleh berada pada tahun sebelumnya. Pemeriksaan tanggal kebutuhan terhadap tahun tersebut menjadi tanggung jawab fitur pemilik dokumen.

Definisi ini berada di Foundation agar module yang memerlukannya tidak harus memasang Management Aset. Tidak ada reference Number Sequence baru: angka tahun adalah pengenal bisnisnya, sedangkan ID adalah rujukan antar-record.

## Data dan akses

`planning_years` menyimpan `tenant_id`, `legal_entity_id`, `year`, `active`, `version`, jejak audit, dan `deleted_at`. Tahun aktif unik per tenant dan perusahaan pada record yang belum diarsipkan. Tenant dan perusahaan sama-sama diperiksa; beberapa perusahaan boleh memiliki tahun yang sama dengan ID berbeda.

Tahun dan perusahaan tidak dapat diubah setelah record dibuat. Mengubah keduanya akan mengganti makna tanggal pada semua dokumen yang sudah merujuknya. Update hanya mengubah status aktif, dengan pemeriksaan versi. Delete mengarsipkan record sehingga referensi lama tetap utuh.

Pengelolaan memerlukan `core.reference-data.read` untuk membaca dan `core.reference-data.update` untuk menulis. Perusahaan yang dipilih harus termasuk organisasi yang diizinkan oleh tempat kerja pengguna. Pilihan perusahaan pada form bukan pemberian akses baru. Module pemakai directory tetap menerapkan kebijakan data miliknya sendiri.

## Permukaan

| Endpoint | Fungsi |
| --- | --- |
| `GET /settings/planning-years?legal_entity_id=...` | Halaman master perusahaan yang dipilih. |
| `GET /api/v1/planning-years?legal_entity_id=...` | Daftar bertahap, pencarian, dan sorting tahun. |
| `POST /api/v1/planning-years` | Membuat tahun aktif dengan `Idempotency-Key`. |
| `PATCH /api/v1/planning-years/{id}` | Mengubah status dengan versi yang masih berlaku. |
| `DELETE /api/v1/planning-years/{id}` | Mengarsipkan tahun dengan pemeriksaan versi. |

Replay dengan kunci sama dan isian berbeda ditolak. Record baru langsung aktif. Tidak ada daftar tahun panjang yang dibuat ketika dropdown dibuka.

## Integrasi dan pemindahan data

Module memakai `App\Platform\Modules\Contracts\PlanningYearDirectory`. `lookup()` membatasi tenant dan perusahaan serta hanya menawarkan tahun aktif untuk pilihan baru. `record_ids` memulihkan label tahun tersimpan termasuk arsip. `resolve()` mengembalikan tahun yang belum diarsipkan beserta statusnya agar pemilik dokumen dapat memeriksa pilihan baru dan referensi yang dipertahankan.

`importLegacy()` khusus migration data. Kombinasi tenant, perusahaan, dan tahun dipindahkan secara idempotent dengan lock database; master lama yang nonaktif atau diarsipkan tidak diaktifkan kembali. Migration dokumen pemilik tetap bertanggung jawab memasang ID tersebut tanpa mengganti identitas dokumen atau baris rincian.

## Kode dan verifikasi

Implementasi ada di `apps/core/app/Foundation/PlanningYear/`. Kontrak directory berada di `apps/core/app/Platform/Modules/Contracts/PlanningYearDirectory.php`. Kartu `PlanningYearCreateCard` pada `resources/js/pages/foundation/planning-year/planning-years.tsx` dipakai oleh lookup; perusahaan dibawa dari konteks pemanggil.

Test `tests/Feature/Foundation/PlanningYear/PlanningYearTest.php` memeriksa replay, keunikan per perusahaan, tahun yang tidak dapat diganti maknanya, versi basi, pengarsipan, pemisahan tenant/perusahaan, serta pemindahan tahun lama yang dapat diulang.

Lihat [kalender fiskal](15-fiscal-calendars.md) dan [perencanaan aset](../apps/management-aset/transaction/perencanaan-aset/index.md).
