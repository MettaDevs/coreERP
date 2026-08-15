# Penyusutan: profil, buku, dan matriks

Halaman ini untuk developer. Isinya cara penyusutan **disiapkan**; cara menjalankannya ada di [Proses penyusutan](/apps/management-aset/transaction/penyusutan/).

Penyusutan di modul ini punya tiga lapis yang sering tertukar:

| Lapis | Apa itu | Contoh |
| --- | --- | --- |
| **Profil penyusutan** | Cara menghitung | Garis lurus 8 tahun, saldo menurun 25% |
| **Buku penyusutan** | Untuk keperluan apa dihitung | Buku komersial, buku fiskal |
| **Matriks group × buku** | Group mana pakai profil apa di buku mana | Group "Kendaraan" di buku fiskal pakai profil "Kelompok II" |

Satu aset bisa disusutkan beberapa kali dengan cara berbeda — sekali untuk laporan keuangan, sekali untuk pajak. Itu sebabnya buku dan profil terpisah.

## Profil penyusutan

Master biasa dengan kolom tambahan yang menentukan hitungan:

| Kolom | Isi |
| --- | --- |
| `method` | Cara hitung, misalnya garis lurus |
| `frequency` | Seberapa sering, misalnya bulanan |
| `year_basis` | Dasar tahun yang dipakai |
| `convention` | Aturan pembulatan awal periode |
| `useful_life_periods` | Masa manfaat dalam jumlah periode |
| `rate_percent` | Persentase, untuk metode yang memakainya |

## Matriks group × buku

Inilah tempat keputusan sebenarnya dibuat. Untuk tiap kombinasi group aset dan buku, ditentukan:

- profil utama yang dipakai,
- profil alternatif kalau ada,
- masa manfaat khusus yang menimpa nilai di profil,
- dan apakah kombinasi itu memang disusutkan (`depreciate`).

Endpointnya `GET`/`PUT /api/v1/group-aset/{id}/buku-penyusutan`.

**Kenapa masa manfaat bisa ditimpa di sini:** profil dipakai banyak group. "Garis lurus" sama caranya untuk kendaraan dan bangunan, yang berbeda hanya masa manfaatnya. Menyalin profil hanya untuk mengubah angka itu akan melahirkan puluhan profil yang nyaris sama.

**Validasi yang penting:** kalau masa manfaat tidak diisi di baris matriks **dan** tidak ada di profilnya, penyimpanan ditolak dengan pesan yang menyebut baris mana. Kalau dibiarkan, kesalahannya baru muncul berbulan-bulan kemudian saat penyusutan gagal dihitung.

## Buku aset

Saat sebuah aset diterima, app membentuk baris `tr_buku_aset` untuk tiap buku yang berlaku bagi group-nya. Isinya:

| Kolom | Isi |
| --- | --- |
| `book_code` | Buku yang mana |
| `acquisition_value` | Nilai perolehan saat itu |
| `residual_value` | Nilai sisa |
| `accumulated_depreciation` | Akumulasi penyusutan sampai sekarang |
| `net_book_value` | Nilai buku bersih |
| `status` | `active` selama aset masih dipakai |

Kunci uniknya `(tenant_id, asset_id, book_code)` — satu aset tidak bisa punya dua buku dengan kode yang sama.

**Buku dibentuk dari matriks, bukan dari kiriman klien.** Field `depreciation_profile_id` dan `book_code` pada permintaan pembuatan aset sengaja **ditolak** (`prohibited`). Kalau klien boleh menentukannya sendiri, akan muncul buku bayangan yang tidak sesuai matriks dan tidak ada yang menyadarinya.

## Aturan yang dijaga

**Aset tidak bisa ditempatkan kalau bukunya belum lengkap.** Saat mutasi pertama, kode memeriksa: apakah ada buku aktif, apakah tiap buku terhubung ke buku penyusutan, dan apakah profilnya benar-benar bisa dihitung. Kalau tidak, mutasi ditolak dengan pesan yang menunjuk matriksnya.

Ini disengaja. Aset yang sudah berstatus dipakai tetapi bukunya salah akan menghasilkan angka penyusutan yang salah, diam-diam, sampai ada yang memeriksa laporan.

**Group aset tidak bisa diganti setelah aset dibuat.** Bukunya sudah terbentuk dari matriks group lama. Ini alasan utama larangan itu.

**Nilai perolehan tidak bisa diubah setelah ada periode penyusutan.** Periode yang sudah berjalan dihitung dari nilai itu.

**Kelompok harta fiskal disalin ke aset, bukan ditunjuk.** Mengubah pilihan fiskal pada group tidak boleh mengubah aset yang sudah aktif — aturan pajaknya sudah berjalan dengan pilihan lama.

## Kelompok harta fiskal

Referensi kelompok pajak menurut PMK 72/2023, diisi otomatis saat tenant disiapkan. Endpointnya `GET /api/v1/reference-data/kelompok-harta-fiskal`.

Ia hanya referensi: menentukan kelompok mana yang berlaku, bukan menghitung apa pun. Hitungannya tetap dari profil.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Http/Controllers/master/ProfilPenyusutanController.php` | Master profil |
| `api/app/Http/Controllers/master/BukuPenyusutanController.php` | Master buku |
| `api/app/Http/Controllers/master/GroupBukuPenyusutanController.php` | Matriks group × buku |
| `api/app/Services/DepreciationCalculator.php` | Hitungan |
| `api/app/Services/FiscalCalendarClient.php` | Tahun buku dari Core |
| `database/migrations/2026_07_28_090000_create_asset_register_and_depreciation_tables.php` | Tabel profil, buku, dan periode |
| `ui/src/master/GroupBookMatrix.tsx` | Layar matriks |

## Halaman terkait

- [Proses penyusutan](/apps/management-aset/transaction/penyusutan/) — proposal, finalisasi, pembalikan
- [Register aset](/apps/management-aset/transaction/register-aset/) — tempat buku dibentuk
- [Master data](/apps/management-aset/master/) — perilaku bersama
