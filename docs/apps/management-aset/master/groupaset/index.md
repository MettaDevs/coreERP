# Group aset

Halaman ini untuk developer. Perilaku dasar master ada di [Master data](/apps/management-aset/master/).

**Group aset menentukan perlakuan uang sebuah barang**: bagaimana ia disusutkan, masuk kelompok pajak apa, dan ke mana biayanya dibebankan.

Ia berdiri sejajar dengan jenis aset, bukan di atasnya. Group mengurus uang, jenis mengurus teknis. Tidak ada yang menyaring yang lain.

## Kolom khusus

Selain bentuk dasar master, group punya:

| Kolom | Isi |
| --- | --- |
| `kelompok_harta_fiskal_id` | Kelompok pajak menurut PMK 72/2023 |
| `property_type` | Jenis harta — menentukan perlakuan yang tidak bisa disimpulkan dari kelompok fiskal saja |
| `asset_location_id` | Lokasi bawaan untuk aset baru di group ini |
| `capitalization_threshold` | Batas nilai untuk dianggap aset, bukan biaya |

## Yang disalin, bukan ditunjuk

Saat aset diterima, `kelompok_harta_fiskal_id` dan lokasi bawaan **disalin** ke asetnya.

Alasannya: kalau nanti admin mengubah pilihan fiskal pada group, aset yang sudah aktif tidak boleh ikut berubah. Penyusutan dan pelaporan pajaknya sudah berjalan dengan aturan lama, dan mengubahnya diam-diam membuat riwayatnya tidak cocok.

Jadi group hanya **mengisi kekosongan di awal**. Setelah aset terbentuk, nilainya milik aset itu sendiri, dan mengubah group tidak memindahkan aset mana pun.

## Matriks group × buku

Keputusan penyusutan sebenarnya tidak ada di group, melainkan di matriks kombinasi group dan buku penyusutan. Endpointnya `GET`/`PUT /api/v1/group-aset/{id}/buku-penyusutan`.

Penjelasan lengkapnya di [Penyusutan: profil, buku, dan matriks](/apps/management-aset/master/depresiasi/).

## Aturan yang dijaga

**Group tidak bisa diganti setelah aset dibuat.** Buku penyusutan aset sudah terbentuk dari matriks group lama. Permintaan yang mencobanya ditolak dengan pesan yang menyebut alasannya — bukan diabaikan diam-diam, supaya pengguna tidak mengira group sudah berganti.

**Kelompok harta fiskal harus ada di referensi tenant.** Divalidasi terhadap `m_kelompok_harta_fiskal`, yang diisi otomatis saat tenant disiapkan.

**Group yang masih dipakai aset tidak bisa diarsipkan.** Berlaku sebagai aturan induk-beranak biasa.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Http/Controllers/master/GroupAsetController.php` | Master group beserta kolom khususnya |
| `api/app/Http/Controllers/master/GroupBukuPenyusutanController.php` | Matriks group × buku |
| `api/app/Models/master/GroupAset.php` | Model, termasuk daftar `PROPERTY_TYPE` |
| `ui/src/master/GroupBookMatrix.tsx` | Layar matriks |

## Halaman terkait

- [Penyusutan: profil, buku, dan matriks](/apps/management-aset/master/depresiasi/)
- [Jenis aset dan atribut](/apps/management-aset/master/jenisaset/) — sumbu yang satunya
- [Register aset](/apps/management-aset/transaction/register-aset/) — tempat penyalinan terjadi
