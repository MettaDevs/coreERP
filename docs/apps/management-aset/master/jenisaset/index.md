# Jenis aset dan atribut

Halaman ini untuk developer. Perilaku dasar master — kode dari Core, idempotency, arsip lunak — ada di [Master data](/apps/management-aset/master/); di sini hanya yang khusus milik jenis aset.

**Jenis aset menentukan perlakuan teknis sebuah barang**: data tambahan apa yang harus diisi, model apa yang boleh dipilih, dan pekerjaan maintenance apa yang berlaku.

Ia berdiri sejajar dengan group aset, tidak di bawahnya. Group mengurus uang, jenis mengurus teknis. Penjelasan kenapa dua sumbu ini terpisah ada di [Register aset](/apps/management-aset/transaction/register-aset/).

## Atribut: kolom tambahan yang ditentukan tenant

Kolom aset sudah tetap, tetapi tiap perusahaan mencatat hal yang berbeda. Kompresor butuh kapasitas dan tekanan; kendaraan butuh nomor polisi dan kilometer. Menambah kolom untuk tiap kebutuhan itu tidak mungkin, jadi dipakai atribut.

Susunannya empat tabel:

| Tabel | Isi |
| --- | --- |
| `aset_m_tipe_atribut` | Daftar hal yang bisa dicatat. Punya tipe data dan satuan |
| `aset_m_tipe_atribut_nilai` | Pilihan nilai, kalau tipenya berupa daftar pilihan |
| `aset_m_jenis_aset_atribut` | Atribut mana berlaku untuk jenis mana, dan mana yang wajib |
| `aset_tr_aset_atribut` | Nilai sebenarnya, milik satu aset |

### Nilai disimpan menurut tipenya

`aset_tr_aset_atribut` punya kolom terpisah: `nilai_text`, `nilai_number`, `nilai_boolean`, `nilai_date`, dan `tipe_atribut_nilai_id` untuk pilihan.

Bukan satu kolom teks untuk semua. Alasannya sederhana: angka harus bisa dibandingkan sebagai angka dan tanggal harus bisa diurutkan sebagai tanggal. Menyimpan `"12"` dan `"9"` sebagai teks membuat `"12" < "9"` bernilai benar, dan laporan jadi salah tanpa ada yang error.

### Tipe data terkunci setelah dipakai

Kalau sebuah tipe atribut sudah pernah diisi pada aset, `data_type`-nya tidak bisa diganti lagi. Layar menampilkannya sebagai kolom terkunci dengan penjelasan, bukan sekadar dinonaktifkan.

Alasannya: nilai lama sudah tersimpan di kolom sesuai tipe lama. Mengganti tipe membuat nilai itu berada di kolom yang salah, dan tidak ada cara otomatis menebak maksud aslinya. Kalau bentuk datanya memang berbeda, buat tipe atribut baru.

## Aturan yang dijaga

**Mengganti jenis aset pada sebuah aset menghapus semua nilai atributnya.** Atribut milik jenis lama tidak berlaku untuk jenis baru, jadi nilainya wajib dikirim ulang bersama permintaan koreksi. Yang tidak dikirim akan kosong — itu disengaja, bukan kehilangan data.

**Atribut wajib harus terisi.** Ditandai di `m_jenis_aset_atribut.wajib`. Diperiksa `AssetAttributeValidator` saat aset disimpan, bukan hanya di layar.

**Isi nilai divalidasi terhadap definisinya.** Bentuk kiriman (`atribut[].tipe_atribut_id`, `atribut[].nilai`) divalidasi di `AssetController::rules()`, tetapi apakah nilainya sah untuk tipe itu hanya diketahui saat berjalan — karena definisinya data, bukan kode.

**Model harus cocok dengan jenisnya.** Kalau sebuah jenis sudah punya daftar model yang dikaitkan, hanya model dari daftar itu yang boleh dipakai. Kalau jenisnya belum punya daftar sama sekali, model apa pun boleh — supaya jenis yang belum diatur tidak memblokir pencatatan. Pabrikan pada model harus sama dengan pabrikan pada aset.

**Kaitan model disunting dari sisi jenis aset.** `PUT /api/v1/jenis-aset/{id}/models` mengganti seluruh daftar sekaligus; kirim array kosong untuk melepas semua. Katalog model dan pabrikan tidak dibuat atau diubah di sini. Satu model hanya boleh dimiliki satu jenis; mengaitkan model yang sudah dipegang jenis lain ditolak, tidak dipindahkan diam-diam.

Endpoint itu butuh **dua** permission sekaligus: `jenis-aset.update` karena yang berubah jenisnya, dan `model-aset.read` karena isinya membaca resource model.

## Endpoint khusus

| Endpoint | Gunanya |
| --- | --- |
| `GET /api/v1/jenis-aset/{id}/atribut` | Nilai atribut |
| `GET /api/v1/jenis-aset/{id}/atribut-definisi` | Definisi atribut yang berlaku, untuk membangun form |
| `GET /api/v1/jenis-aset/{id}/detail` | Jumlah data yang menggantung pada jenis ini |
| `PUT /api/v1/jenis-aset/{id}/models` | Mengganti daftar model |
| `GET`/`PUT /api/v1/jenis-aset/{id}/maintenance-job-types` | Pekerjaan maintenance yang berlaku |

`detail` berdiri sendiri, bukan bagian dari respons master, supaya daftar tidak menghitung apa pun dan tiap angka bisa menegakkan izinnya sendiri. `model_count` dan `asset_count` bernilai `null` — bukan 0 — kalau pemanggil tidak punya hak membaca resource itu. Nol adalah pernyataan bahwa tidak ada; `null` berarti tidak boleh tahu.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `src/Http/Controllers/master/JenisAsetController.php` | Master jenis aset |
| `src/Http/Controllers/master/JenisAsetModelController.php` | Kaitan jenis ke model |
| `src/Http/Controllers/master/JenisAsetDetailController.php` | Angka pada panel detail |
| `src/Http/Controllers/master/JenisAsetAtributController.php`, `JenisAsetAtributDefinisiController.php` | Nilai dan definisi atribut |
| `src/Http/Controllers/master/TipeAtributController.php`, `TipeAtributNilaiController.php` | Master tipe atribut dan pilihan nilainya |
| `src/Support/AssetAttributeValidator.php` | Validasi nilai atribut |
| `database/migrations/2026_08_07_130000_create_asset_attribute_tables.php` | Empat tabel atribut |
| `ui/master/DynamicField.tsx` | Field yang dibentuk dari definisi atribut |

## Halaman terkait

- [Master data](/apps/management-aset/master/) — perilaku bersama
- [Register aset](/apps/management-aset/transaction/register-aset/) — pemakai atribut
- [Setup maintenance](/apps/management-aset/master/maintenance/) — pekerjaan yang dikaitkan ke jenis
