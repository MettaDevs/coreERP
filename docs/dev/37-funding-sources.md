# Sumber dana

Sumber dana adalah definisi asal pembiayaan yang dapat dipilih dokumen lintas modul. Satu baris `funding_sources` menyimpan nama, deskripsi, dan status aktif di dalam satu tenant. Master ini milik Foundation sehingga pengguna Management Aset tidak harus memasang Procurement atau Budgeting untuk memakainya.

Sumber dana tidak menyimpan pagu, saldo, rekening pembayaran, atau persetujuan anggaran. Memilihnya pada dokumen hanya menyatakan asal pembiayaan yang direncanakan. Nilai rencana dan pemeriksaan ketersediaan dana tetap menjadi fakta proses pemilik dokumen.

## Data dan akses

`funding_sources` membawa `tenant_id`, `name`, `description`, `active`, `version`, kolom audit, serta `deleted_at`. Definisi dipakai bersama perusahaan dalam tenant; tidak ada penyaringan perusahaan atau unit kerja pada master ini. Dokumen yang merujuknya tetap menegakkan scope perusahaan dan unitnya sendiri.

Nama wajib diisi dan deskripsi opsional. Identitas referensi memakai ID, bukan nama atau deskripsi. Nama tidak diwajibkan unik; dua sumber bernama sama dapat diberi deskripsi berbeda. Kartu dan lookup menampilkan Nama dan Deskripsi. Tidak ada input kode atau reference Number Sequence baru. Kolom `code` lama dibuat nullable dan tetap disimpan untuk riwayat; kode lama tidak ditampilkan atau ditulis ulang. Delete selalu soft delete sehingga referensi dokumen tetap ada.

Pembacaan halaman dan API memakai `core.reference-data.read`. Penulisan memakai gate `manage-reference-data`, yang dipetakan ke `core.reference-data.update`. Master baru langsung aktif. Update dan Delete membutuhkan `version`; versi basi tidak menimpa perubahan pengguna lain.

## Permukaan

| Permukaan | Fungsi |
| --- | --- |
| `GET /settings/funding-sources` | Halaman master dengan DataTable bertahap dan kartu SDK. |
| `GET /api/v1/funding-sources` | Search, sort, dan cursor daftar milik tenant aktif. |
| `POST /api/v1/funding-sources` | Membuat record aktif dengan `Idempotency-Key`; replay berbeda isian ditolak. |
| `PATCH /api/v1/funding-sources/{id}` | Mengubah nama, deskripsi, dan status. |
| `DELETE /api/v1/funding-sources/{id}` | Mengarsipkan record. |

Module membaca lewat `App\Platform\Modules\Contracts\FundingSourceDirectory`, bukan model atau tabel Foundation. `lookup()` membatasi pilihan baru ke record aktif; `record_ids` dapat memulihkan label referensi tersimpan termasuk arsip. `resolve()` mengembalikan record yang belum diarsipkan, termasuk status aktif agar pemanggil memutuskan kelayakan referensi. Keduanya selalu menyaring tenant.

`importLegacy()` khusus migration data. Ia memindahkan keterangan lama dengan nama yang dipertahankan, memakai kunci deterministik dan lock per tenant/nama untuk mencegah duplikasi saat pengulangan. Record bernama sama dipakai kembali, termasuk nonaktif atau arsip; migrasi tidak mengaktifkan kembali master pengguna. Kode `LEGACY-` yang dihasilkan adalah identitas teknis untuk keterangan lama, bukan nama sumber dana bawaan.

## Kode dan verifikasi

Implementasi berada di `apps/core/app/Foundation/FundingSource/`. Provider fitur mengikat directory dan mendaftarkan rute sesi; tidak ada endpoint `internal/v1` baru. Kartu `FundingSourceCreateCard` pada `resources/js/pages/foundation/funding-source/funding-sources.tsx` dipakai ulang oleh lookup transaksi dan mengembalikan record lengkap setelah simpan.

Test `tests/Feature/Foundation/FundingSource/FundingSourceTest.php` memeriksa pembuatan tanpa kode, replay nama/deskripsi, versi basi, penghapusan lunak, kode warisan yang dipertahankan, pencarian nama/deskripsi, scope tenant, cursor, label arsip, serta impor data lama yang dapat diulang.
