# Penyusutan: profil, buku, dan matriks

Halaman ini untuk developer. Isinya cara penyusutan **disiapkan**; cara menjalankannya ada di [Proses penyusutan](/apps/management-aset/transaction/penyusutan/).

Penyusutan di modul ini punya tiga lapis yang sering tertukar:

| Lapis | Apa itu | Contoh |
| --- | --- | --- |
| **Profil penyusutan** | Cara menghitung | Garis lurus 8 tahun, saldo menurun 25% |
| **Buku penyusutan** | Untuk keperluan apa dihitung | Buku komersial, buku fiskal |
| **Matriks group × buku** | Group mana pakai profil apa di buku mana | Group "Kendaraan" di buku fiskal pakai profil "Kelompok II" |

Satu aset bisa disusutkan beberapa kali dengan cara berbeda — sekali untuk laporan keuangan, sekali untuk pajak. Itu sebabnya buku dan profil terpisah.

Tabelnya: `aset_m_profil_penyusutan`, `aset_m_buku_penyusutan`, `aset_m_group_buku_penyusutan` untuk matriksnya, dan `aset_tr_buku_aset` untuk buku milik tiap aset.

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

## Buku penyusutan dan lapisan posting

`posting_layer` pada buku adalah **satu-satunya saklar posting** (K-15 feed posting finance): buku `none` (memorandum) dihitung dan dilaporkan, tetapi tidak pernah di-post ke aplikasi finance. Dari buku lainnya, hanya buku yang di-post group-nya — `current` lebih dulu — yang mengirim jurnal perolehan dan penyusutan aset itu (K-26, K-31); buku lain yang bukan `none` tetap menyusut di register saja.

**Kenapa satu saklar:** dulu ada `export_to_backoffice` di samping lapisan posting. Dua saklar yang maknanya tumpang tindih pernah menghasilkan pembalikan yang terekspor padahal aslinya tidak. Saklar lama dilebur ke lapisan posting; API menolak field itu dengan 422, dan kolomnya dibiarkan satu rilis sebelum dibuang (aturan N-1).

**Kenapa buku fiskal memorandum:** aplikasi finance pelanggan hanya punya satu lapisan GL. Buku fiskal yang ikut di-post akan menjurnal penyusutan aset yang sama untuk kedua kalinya. Migration peleburan menjadikan setiap buku `tax` sebagai `none`, dan buku `FISKAL` bawaan tenant baru lahir sebagai `none`.

## Matriks group × buku

Inilah tempat keputusan sebenarnya dibuat. Untuk tiap kombinasi group aset dan buku, ditentukan:

- profil utama yang dipakai,
- profil alternatif kalau ada,
- masa manfaat khusus yang menimpa nilai di profil,
- dan apakah kombinasi itu memang disusutkan (`depreciate`).

Endpointnya `GET`/`PUT /api/v1/group-aset/{id}/buku-penyusutan`.

**Kenapa masa manfaat bisa ditimpa di sini:** profil dipakai banyak group. "Garis lurus" sama caranya untuk kendaraan dan bangunan, yang berbeda hanya masa manfaatnya. Menyalin profil hanya untuk mengubah angka itu akan melahirkan puluhan profil yang nyaris sama.

**Validasi yang penting:** kalau masa manfaat tidak diisi di baris matriks **dan** tidak ada di profilnya, penyimpanan ditolak dengan pesan yang menyebut baris mana. Kalau dibiarkan, kesalahannya baru muncul berbulan-bulan kemudian saat penyusutan gagal dihitung.

**Berapa baris yang diisi menentukan perlakuan asetnya**, dan itu keputusan per group, bukan per aset:

| Isi matriks | Hasil pada aset group itu |
| --- | --- |
| Satu baris, `depreciate = false` | Tercatat dengan nilai perolehannya, tidak pernah menyusut. Baris ini tidak perlu profil. |
| Satu baris ke buku komersial | Satu buku yang menyusut. Ini yang dipasang template starter Indonesia. |
| Satu baris ke buku fiskal | Satu buku yang menyusut dengan masa manfaat pajak. |
| Dua baris | Komersial dan fiskal berjalan sendiri-sendiri, masing-masing dengan angkanya. |
| Kosong | Aset tetap bisa dicatat, tetapi tidak bisa ditempatkan. Ini jaring pengaman, bukan mode. |

Buku hanya dibentuk saat aset diterima. Menambah baris matriks kemudian **tidak berlaku surut** — aset yang sudah ada tetap memakai buku yang dibentuk untuknya dulu.

## Buku aset

Saat sebuah aset diterima, app membentuk baris `aset_tr_buku_aset` untuk tiap buku yang berlaku bagi group-nya. Isinya:

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
| `src/Http/Controllers/master/ProfilPenyusutanController.php` | Master profil |
| `src/Http/Controllers/master/BukuPenyusutanController.php` | Master buku |
| `src/Http/Controllers/master/GroupBukuPenyusutanController.php` | Matriks group × buku |
| `src/Services/DepreciationCalculator.php` | Hitungan |
| `src/Services/KalenderFiskalAset.php` | Tahun buku dari Core |
| `database/migrations/2026_07_28_090000_create_asset_register_and_depreciation_tables.php` | Tabel profil, buku, dan periode |
| `ui/master/GroupBookMatrix.tsx` | Layar matriks |

## Halaman terkait

- [Proses penyusutan](/apps/management-aset/transaction/penyusutan/) — proposal, finalisasi, pembalikan
- [Register aset](/apps/management-aset/transaction/register-aset/) — tempat buku dibentuk
- [Master data](/apps/management-aset/master/) — perilaku bersama
