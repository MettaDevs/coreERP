# Pabrikan dan model

Halaman ini untuk developer. Perilaku dasar master ada di [Master data](/apps/management-aset/master/).

Dua master yang menjawab **barang ini buatan siapa, tipe apa**:

| Master | Isi |
| --- | --- |
| `pabrikan-aset` | Pembuat barang |
| `model-aset` | Tipe barang dari pabrikan itu |

Model berinduk pabrikan. Ini satu-satunya rantai induk yang tersisa di master aset setelah rantai klasifikasi lama dibongkar.

## Keduanya opsional pada aset

Aset boleh tidak menyebut pabrikan maupun model. Alasannya praktis: barang rakitan atau barang lama sering tidak ada di katalog mana pun, dan memaksa memilih akan melahirkan record sampah bernama "Lain-lain".

## Model dikaitkan ke jenis aset

Selain berinduk pabrikan, model bisa dikaitkan ke jenis aset. Kaitan itu disunting **dari sisi jenis aset**, lewat `PUT /api/v1/jenis-aset/{id}/models`.

Alasannya: yang punya kepentingan atas daftar itu adalah jenis aset — ia yang membatasi model apa yang masuk akal. Katalog model dan pabrikan tidak dibuat atau diubah dari sana; endpoint itu hanya memindahkan keanggotaan.

## Aturan kombinasi

Diperiksa saat aset dibuat maupun dikoreksi, di `AssetController::assertModelCombination()`:

**Kalau jenis aset sudah punya daftar model**, hanya model dari daftar itu yang boleh dipakai.

**Kalau jenisnya belum punya daftar sama sekali**, model apa pun boleh. Ini disengaja supaya jenis yang belum diatur tidak memblokir pencatatan aset.

**Pabrikan pada model harus sama dengan pabrikan pada aset.** Memilih model Toyota sambil menyebut pabrikan Honda ditolak.

**Satu model hanya boleh dimiliki satu jenis aset.** Mengaitkan model yang sudah dipegang jenis lain ditolak sebagai galat validasi, bukan dipindahkan diam-diam.

## Detail pabrikan

`GET /api/v1/pabrikan-aset/{id}/detail` mengembalikan jumlah data yang menggantung pada pabrikan itu. Sama seperti detail jenis aset, angkanya bernilai `null` — bukan 0 — kalau pemanggil tidak berhak membaca resource yang dihitung. Nol adalah pernyataan bahwa tidak ada; `null` berarti tidak boleh tahu.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Http/Controllers/master/PabrikanAsetController.php` | Master pabrikan |
| `api/app/Http/Controllers/master/ModelAsetController.php` | Master model |
| `api/app/Http/Controllers/master/JenisAsetModelController.php` | Kaitan jenis ke model |
| `api/app/Http/Controllers/master/PabrikanAsetDetailController.php` | Angka pada panel detail |
| `AssetController::assertModelCombination()` | Aturan kombinasi |

## Halaman terkait

- [Jenis aset dan atribut](/apps/management-aset/master/jenisaset/) — tempat kaitan model disunting
- [Register aset](/apps/management-aset/transaction/register-aset/) — pemakai kombinasi ini
