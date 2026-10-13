# Kelompok harta

Satu baris kelompok harta menyimpan klasifikasi dan nilai acuan penyusutan untuk satu tenant. Halaman `management-aset/kelompok-harta` membaca dan mengubah referensi yang sebelumnya hanya tersedia melalui dropdown Group aset.

## Kelompok harta dan profil penyusutan

Kelompok harta menyimpan nama klasifikasi, masa manfaat dan tarif acuan. Profil penyusutan menyimpan metode dan frekuensi yang dipakai untuk menghitung penyusutan. Data awal membuat profil dari nilai kelompok; setelah dibuat, kedua record berdiri sendiri. Mengubah kelompok tidak memperbarui profil maupun menghitung ulang penyusutan.

Dalam [setup Fixed assets Dynamics 365](https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/set-up-fixed-assets), profil dan buku adalah bagian pengaturan penyusutan. Kelompok harta di sini adalah referensi lokal Indonesia; bukan klaim padanan langsung master Dynamics.

## Data dan endpoint

Tabelnya tetap `aset_m_kelompok_harta_fiskal`. `template_key` mengenali data awal, bukan nomor bisnis; resource ini tidak memerlukan Number Sequence. `tenant_id` berasal dari konteks Core tepercaya. Referensi ini berlaku untuk seluruh tenant dan tidak memerlukan policy organisasi.

| Field | Isi |
| --- | --- |
| `label` | Nama yang tampil di dropdown dan halaman detail |
| `effective_from`, `effective_to` | Rentang tanggal berlaku; tanggal mulai tetap wajib |
| `useful_life_years` | Masa manfaat dalam tahun |
| `straight_line_rate_percent`, `reducing_balance_rate_percent` | Tarif acuan dalam persen |
| `depreciable`, `allow_reducing_balance` | Apakah disusutkan dan apakah saldo menurun diperbolehkan |
| `aktif` | Ketersediaan pada pilihan aktif |
| `version` | Versi baris untuk mencegah penyimpanan data basi |

Dropdown menampilkan nama saja. Tanggalnya tetap dikirim oleh endpoint referensi dan terlihat pada halaman detail. Field referensi aturan tidak ditampilkan pada form; metadata yang sudah ada tetap tersimpan dan tidak dikirim ulang saat menyimpan form.

| Endpoint | Gunanya |
| --- | --- |
| `GET /api/modules/management-aset/v1/kelompok-harta` | Daftar, pencarian nama dan pagination |
| `PATCH /api/modules/management-aset/v1/kelompok-harta/{id}` | Mengubah referensi milik tenant yang sedang aktif |

## Hak akses dan validasi

`management-aset.kelompok-harta.read` dan `.update` terpisah dari izin Group aset. Duty `.view` memberikan baca; `.manage` memberikan baca dan ubah. Tidak ada workflow, aksi tambah atau arsip pada halaman ini.

Penyimpanan memakai `RowVersion` di dalam transaksi. Versi tidak dikirim menjawab 428, versi basi menjawab 409, dan ID tenant lain menjawab 404. Penolakan validasi membatalkan klaim versi. Tanggal akhir tidak boleh mendahului tanggal mulai; kelompok yang disusutkan wajib memiliki masa manfaat dan tarif garis lurus; tarif saldo menurun wajib jika metode itu diperbolehkan. Persentase harus lebih dari nol dan paling tinggi 100.

**Edit memperbarui baris yang sama.** Ini bukan penerbitan versi regulasi baru. Group dan aset yang menyimpan ID itu tetap menunjuk referensi yang sama. Profil yang sudah dibuat tetap memakai nilainya sendiri. `tenant_id`, `template_key` dan yurisdiksi tidak dapat diubah melalui endpoint ini.

## Letak kode

| Berkas di module | Isi |
| --- | --- |
| `src/Http/Controllers/master/AssetClassifications/AssetClassificationController.php` | Hak akses, daftar dan penyimpanan |
| `ui/transactions/asset-classifications/AssetClassificationPage.tsx` | Daftar dan detail pada satu halaman |
| `manifest/setup/asset-classifications.yaml` | Entry point, permission, privilege dan duty |
| `tests/Feature/AssetClassificationTest.php` | Isolasi tenant, izin, versi dan validasi |
| `src/Services/ProvisionIndonesiaStarterData.php` | Membuat referensi dan profil awal dari konfigurasi |

Lihat juga [profil, buku dan matriks penyusutan](/apps/management-aset/master/depresiasi/) dan [Group aset](/apps/management-aset/master/groupaset/).
