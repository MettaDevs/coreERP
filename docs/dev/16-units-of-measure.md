# Satuan dan konversi

Halaman **Satuan** dipakai untuk membuat daftar ukuran yang dapat dipakai bersama oleh aplikasi seperti Aset, Procurement, Inventory, dan PIM. Isi hanya ukuran yang benar-benar dipakai bisnis; jangan membuat daftar panjang untuk berjaga-jaga.

## Urutan mengisi

1. Buat **kelas satuan**.
2. Buat **sistem satuan** bila diperlukan.
3. Buat satuan di dalam kelasnya.
4. Tambahkan konversi umum hanya bila dua satuan itu memang perlu saling diubah.

## Data awal saat tenant dibuat

Core otomatis menyalin katalog awal untuk setiap tenant baru dan juga mengisi tenant yang sudah ada saat seeder Core dijalankan. Katalog awal memakai SI/Metrik untuk pengukuran fisik dan menyimpan kode eksternal `UN/ECE-REC20` untuk pertukaran dokumen internasional.

Untuk instalasi on-prem baru, jalankan `php artisan migrate --seed` pada Control Plane. Bila instalasi dan migrasi sudah ada, jalankan `php artisan db:seed` untuk mengisi katalog tenant yang sudah ada. Menjalankannya kembali aman; data standar yang sama tidak dibuat dua kali.

Katalog awal mencakup kelas Jumlah, Massa, Panjang, Luas, Volume, Waktu, dan Energi; sistem Metrik, Imperial, serta Amerika Serikat; serta satuan umum seperti pcs, set, lusin, kg, gram, ton, meter, sentimeter, kilometer, liter, mililiter, meter kubik, jam, menit, hari, dan kWh. Konversi umum seperti lusin ke pcs, kg ke gram, meter ke sentimeter, liter ke mililiter, dan jam ke menit juga tersedia.

Data awal adalah titik mulai, bukan daftar wajib. Tenant dapat menonaktifkan atau menambah satuan sesuai kebutuhan bisnisnya. Jangan mengubah kode atau menghapus satuan yang sudah dipakai transaksi.

Rujukan standar: [SI base units oleh BIPM](https://www.bipm.org/en/measurement-units/si-base-units) dan [UN/CEFACT Recommendation 20](https://unece.org/trade/documents/2021/06/uncefact-rec20-0). Rec 20 menyediakan kode satuan untuk pertukaran informasi perdagangan internasional.

## Kelas satuan

Kelas menjawab pertanyaan: **apa yang diukur?** Satuan hanya boleh dikonversi dengan satuan dalam kelas yang sama.

| Kelas | Untuk mengukur | Contoh satuan |
| --- | --- | --- |
| `QUANTITY` / Jumlah | Banyaknya barang | pcs, unit, set, pasang |
| `MASS` / Massa | Berat | kg, g, ton |
| `LENGTH` / Panjang | Jarak atau ukuran panjang | m, cm, km |
| `VOLUME` / Volume | Isi atau cairan | l, ml, m3 |
| `TIME` / Waktu | Durasi atau jam kerja | jam, menit, hari |

Contoh pengisian pertama:

```text
Kode kelas: QUANTITY
Nama kelas: Jumlah
```

Kode adalah identitas stabil untuk integrasi dan laporan. Gunakan huruf besar, angka, `_`, `-`, atau `.`; jangan mengubah kode setelah dipakai transaksi.

## Sistem satuan

Sistem satuan adalah keluarga standar, bukan jenis pengukuran.

| Sistem | Contoh |
| --- | --- |
| `METRIC` / Metrik | kg, g, m, cm, l |
| `IMPERIAL` / Imperial | lb, ft, gal |
| Tidak ditentukan | pcs, unit, set, pasang |

Untuk penggunaan awal di Indonesia, biasanya cukup membuat `METRIC` / `Metrik`. Satuan jumlah seperti `PCS` tidak perlu sistem satuan.

## Menambah satuan

Setiap satuan membutuhkan:

| Kolom | Arti | Contoh untuk barang per buah |
| --- | --- | --- |
| Kode | Identitas singkat yang stabil | `PCS` |
| Nama | Nama yang dibaca pengguna | `Pieces` atau `Buah` |
| Simbol | Tampilan pendek, bila ada | `pcs` |
| Kelas | Apa yang diukur | `Jumlah` |
| Sistem | Keluarga standar, bila ada | Tidak ditentukan |
| Jumlah angka desimal | Ketelitian kuantitas | `0` untuk pcs; `3` untuk kg/liter bila perlu |

Contoh aman untuk mulai:

```text
Kelas: QUANTITY / Jumlah
Satuan: PCS / Pieces / pcs / desimal 0

Kelas: MASS / Massa
Sistem: METRIC / Metrik
Satuan: KG / Kilogram / kg / desimal 3
Satuan: G / Gram / g / desimal 0
```

## Konversi umum

Konversi umum berlaku untuk semua aplikasi dan semua barang. Isi **dari satuan × pengali + pergeseran = ke satuan**.

Contoh `kg` ke `g`:

```text
Dari satuan: KG
Ke satuan: G
Pengali: 1000
Pergeseran: 0
Pembulatan desimal: 0
```

Artinya `2.5 kg` menjadi `2500 g`. Hanya buat konversi dalam kelas yang sama: kg ke g boleh; kg ke pcs tidak boleh.

`Pergeseran` biasanya `0`. Kolom itu hanya diperlukan untuk satuan yang tidak mulai dari nol yang sama, misalnya suhu. Jangan mengisinya bila tidak memahami kebutuhan konversinya.

## Yang belum diisi di Core

Aturan seperti **1 dus = 12 pcs** bukan konversi umum, karena jumlah isi dapat berbeda per produk. Aturan tersebut akan menjadi data PIM/Inventory saat app pemilik produk tersedia. Jangan membuatnya sebagai konversi umum di halaman ini.

## Lihat juga

- [Number sequence](14-number-sequences.md) — reference data platform lain
- [Kalender fiskal](15-fiscal-calendars.md) — reference data platform lain
- [Gate fondasi Core](10-core-foundation-gates.md) — status kepemilikan konversi per produk
