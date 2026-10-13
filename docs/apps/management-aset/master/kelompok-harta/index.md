# Kelompok Aset

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
| `depreciable`, `allow_straight_line`, `allow_reducing_balance` | Apakah disusutkan dan apakah garis lurus/saldo menurun diperbolehkan |
| `aktif` | Ketersediaan pada pilihan aktif |
| `version` | Versi baris untuk mencegah penyimpanan data basi |

Dropdown menampilkan nama saja. Tanggal berlaku dan referensi aturan tidak ditampilkan pada form; metadata yang sudah ada tetap tersimpan dan tidak dikirim ulang saat menyimpan form. Endpoint tetap mengembalikan tanggal dan backend tetap memvalidasi rentangnya. Detail aturan penyusutan memakai FastTab `CollapsibleSection` dari SDK, terbuka saat awal dan tetap menampilkan ringkasan status ketika ditutup.

| Endpoint | Gunanya |
| --- | --- |
| `GET /api/modules/management-aset/v1/kelompok-harta` | Daftar, pencarian nama dan pagination |
| `POST /api/modules/management-aset/v1/kelompok-harta` | Menambah kelompok dengan izin create dan Idempotency-Key |
| `PATCH /api/modules/management-aset/v1/kelompok-harta/{id}` | Mengubah referensi milik tenant yang sedang aktif |
| `DELETE /api/modules/management-aset/v1/kelompok-harta/{id}` | Mengarsipkan referensi yang tidak dipakai data yang belum diarsipkan |

## Hak akses dan validasi

`management-aset.kelompok-harta.read`, `.create`, `.update` dan `.archive` terpisah dari izin Group aset. Duty `.view` memberikan baca; `.manage` memberikan baca, tambah, ubah dan arsip lewat privilege yang berbeda. Tidak ada workflow. Tombol Tambah data membuka form baru yang aktif secara bawaan. Hasil simpan langsung masuk daftar dan dipilih, tanpa bergantung pada refresh.

Klik atau fokus pada field membuka mode edit, mengikuti Group aset. Pindah record tanpa perubahan langsung membuka record tujuan dalam mode baca. Jika perubahan belum disimpan, dialog menawarkan tetap di record sekarang atau membuang perubahan sebelum berpindah. Batal membuang perubahan; respons sukses mengembalikan form ke mode baca.

Arsip mengisi `deleted_at` lewat model. Kelompok yang masih dipakai Group aset atau aset yang belum diarsipkan ditolak dengan 409, sehingga referensi yang sedang digunakan tetap tersedia. Arsip selalu meminta konfirmasi dan memakai versi baris, sama seperti edit.

Penyimpanan memakai `RowVersion` di dalam transaksi. Versi tidak dikirim menjawab 428, versi basi menjawab 409, dan ID tenant lain menjawab 404. Penolakan validasi membatalkan klaim versi. Tanggal akhir tidak boleh mendahului tanggal mulai; kelompok yang disusutkan wajib memiliki masa manfaat minimal satu tahun. Tarif wajib bila disusutkan dan metode terkait diperbolehkan. Persentase boleh nol dan paling tinggi 100. Menonaktifkan izin metode tidak menghapus tarif yang sudah tersimpan atau mengubah profil lama.

**Edit memperbarui baris yang sama.** Ini bukan penerbitan versi regulasi baru. Group dan aset yang menyimpan ID itu tetap menunjuk referensi yang sama. Profil yang sudah dibuat tetap memakai nilainya sendiri. `tenant_id`, `template_key` dan yurisdiksi tidak dapat diubah melalui endpoint ini.

## Keputusan penambahan data

| Kemampuan | Keputusan | Alasan |
| --- | --- | --- |
| Resource dan lifecycle | Referensi module yang sama; aktif/nonaktif dan arsip | Bukan transaksi baru |
| Scope dan policy | Seluruh tenant, tanpa filter organisasi | Nilai acuan bersama dalam tenant |
| Security | Permission create masuk privilege maintain | Izin baca/ubah saja tidak boleh menambah |
| Nomor | Tidak diperlukan | Identitas ULID dan template_key bersifat internal; tidak ada kode bisnis |
| Workflow dan SoD | Tidak diperlukan | CRUD referensi biasa |
| API dan audit | POST pada resource yang sama; Idempotency-Key dan versi pada edit | Tidak menambah event atau consumer lintas module |

`template_key` untuk data baru adalah `custom:<Idempotency-Key>`; indeks unik tenant/key yang sudah ada mencegah dua record saat permintaan bersamaan. Key yang sama dengan isi berbeda atau record terarsip ditolak 409. `effective_from` diisi tanggal server, sedangkan yurisdiksi memakai `modules.management-aset.classification_defaults.jurisdiction`. Keduanya tidak diambil dari body create. Kolom `allow_straight_line` ditambahkan lewat migration dengan default true untuk mempertahankan perilaku referensi lama.

Pilihan metode di sini adalah izin dan tarif acuan pada referensi, bukan saklar perhitungan untuk profil yang sudah dibuat. Ini penyesuaian lokal terhadap konsep [profil penyusutan Dynamics 365](https://learn.microsoft.com/en-us/dynamics365/finance/fixed-assets/tasks/set-up-depreciation-profiles), bukan master Dynamics dengan padanan langsung. Tarif nol pada referensi tidak berarti mengubah aturan validasi tarif pada master Metode Penyusutan.

## Letak kode

| Berkas di module | Isi |
| --- | --- |
| `src/Http/Controllers/master/AssetClassifications/AssetClassificationController.php` | Hak akses, daftar dan penyimpanan |
| `ui/transactions/asset-classifications/AssetClassificationPage.tsx` | Daftar dan detail pada satu halaman |
| `manifest/setup/asset-classifications.yaml` | Entry point, permission, privilege dan duty |
| `tests/Feature/AssetClassificationTest.php` | Isolasi tenant, izin, versi dan validasi |
| `src/Services/ProvisionIndonesiaStarterData.php` | Membuat referensi dan profil awal dari konfigurasi |

Lihat juga [profil, buku dan matriks penyusutan](/apps/management-aset/master/depresiasi/) dan [Group aset](/apps/management-aset/master/groupaset/).
