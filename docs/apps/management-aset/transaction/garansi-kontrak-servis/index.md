# Garansi dan kontrak servis

Garansi dan kontrak servis mencatat **siapa yang menanggung perbaikan sebuah aset, sampai kapan, dan sebatas apa**. Gunanya satu: sebelum teknisi sendiri membongkar aset, perencana tahu bahwa perbaikannya seharusnya diklaim ke vendor.

Padanannya *Vendor warranty* pada aset dan *Warranty agreements* di Dynamics 365 F&O Asset Management ([Warranties on assets and asset types](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/warranty/warranty-on-assets-and-asset-types), [Warranty agreements](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/warranty/warranty-agreement)). Business Central hanya punya dua field pada kartu aset: *Warranty Date* dan *Maintenance Vendor No.* (tabel 5600 `Fixed Asset`).

## Dua bentuk, dan kenapa dipisah

| | Garansi | Kontrak servis |
| --- | --- | --- |
| Menempel pada | Satu aset | Banyak aset |
| Asal | Pemasok atau pabrikan, ikut pembelian | Perjanjian berbayar dengan vendor pemeliharaan |
| Isi | Penjamin, cakupan penuh/sebagian, nomor kartu garansi, periode, catatan | Nomor kontrak, vendor, periode, cakupan, nilai kontrak, daftar aset |
| Nomor sistem | Tidak ada | Number Sequence `KSVA`, per entitas legal |
| Lampiran | Lewat lampiran aset | Berkas kontrak pada dokumennya |

F&O memodelkan keduanya sebagai *warranty agreement* yang dipasang pada aset. **Keputusan:** kontrak servis dibuat dokumen sendiri dengan baris aset, karena satu kontrak pemeliharaan nyata biasanya menanggung puluhan aset dengan satu nomor dan satu berkas; mencatatnya per aset berarti menyalin nomor dan berkas yang sama puluhan kali. Garansi tetap per aset, karena kartu garansi memang per barang.

Cakupan garansi **penuh** atau **sebagian** mengikuti *full* dan *partial coverage* pada warranty agreement F&O. Rincian sebagian (misalnya suku cadang tanpa jasa, atau batas jam) ditulis di catatan; F&O menyimpannya sebagai baris syarat jam, biaya, dan barang, dan itu **belum ada** di sini.

## Data yang disimpan

| Tabel | Isi |
| --- | --- |
| `aset_tr_garansi_aset` | Aset, penjamin (`vendor_id`, opsional), `jenis_garansi` (`penuh`/`sebagian`), nomor kartu garansi, berlaku mulai dan sampai, catatan. |
| `aset_tr_kontrak_servis` | Kode, entitas legal, nomor kontrak dari vendor, vendor servis (wajib), berlaku mulai dan sampai, cakupan, nilai kontrak, keterangan. |
| `aset_tr_kontrak_servis_aset` | Baris aset kontrak, bernomor; nomor baris tidak dipakai ulang dan satu aset paling banyak sekali per kontrak (indeks unik parsial). |

Vendor dibaca lewat kontrak `VendorDirectory` Core dan wajib vendor entitas legal aset atau kontraknya. Penjamin garansi boleh kosong, karena garansi pabrikan sering datang dari pihak yang bukan vendor tercatat.

## Aturan yang dijaga, dan alasannya

- **Garansi tambahan adalah baris baru.** Perpanjangan garansi tidak menimpa garansi pertama, supaya riwayat cakupan aset tetap terbaca. Berbeda dari pertanggungan asuransi, baris garansi boleh disunting: ia salinan kartu garansi, bukan angka yang dijumlahkan.
- **Jangkauan organisasi.** Garansi mengikuti unit penanggung jawab asetnya. Kontrak servis milik entitas legal tanpa unit, terlihat oleh pengguna dengan hibah pada entitas legal itu; barisnya hanya menampilkan aset dalam jangkauan pengguna.
- **Menyimpan kontrak tidak mengeluarkan aset yang tidak terlihat.** Daftar aset yang dikirim disamakan hanya di dalam jangkauan pengguna. Tanpa aturan ini, pengguna satu unit yang menyimpan kontrak akan mengeluarkan aset unit lain yang tidak pernah ia lihat.
- **Versi baris** pada ubah dan arsipkan, untuk garansi maupun kontrak.

## Pemberitahuan di work order

Saat work order dibuka atau disusun, layar membaca garansi dan kontrak servis yang berlaku untuk aset-aset pada baris pekerjaannya pada tanggal jadwal mulai (atau tanggal diharapkan mulai, atau hari ini), lalu menampilkannya sebagai pemberitahuan di atas baris pekerjaan. Padanan notifikasi F&O saat work order dibuat untuk aset bergaransi dengan tanggal mulai di dalam masa garansi.

**Hanya informasi.** Work order tetap dapat disimpan, dijadwalkan, dan dikerjakan. Endpointnya, `GET pemeliharaan-aset/referensi/garansi`, dijaga permission baca work order — bukan permission garansi — karena isinya bagian dari layar work order dan hanya ringkasan yang dibutuhkan perencana.

## Akan berakhir

Tab **Akan berakhir** menggabungkan garansi dan kontrak servis yang berakhir antara hari ini dan 30, 60, atau 90 hari ke depan, menurut zona pengguna, diurutkan dari yang paling dekat. Garansi tampil bila pengguna boleh membaca garansi, kontrak bila boleh membaca kontrak servis. Rentang dibatasi tiga pilihan itu supaya angkanya sama dengan yang dibicarakan orang ("yang habis bulan ini", "kuartal ini").

## Layar dan hak akses

| Layar | Menu | Isi |
| --- | --- | --- |
| Garansi dan kontrak servis | Transaksi → Garansi dan kontrak servis | Tab Garansi, Kontrak servis, dan Akan berakhir. |
| Detail aset | Inventarisasi aset → aset → bagian **Garansi dan kontrak servis** | Garansi aset dan kontrak yang menanggungnya. Hanya baca. |
| Work order | Pemeliharaan aset → work order | Pemberitahuan garansi aktif. |

Duty `management-aset.garansi-aset.manage` (*Kelola garansi dan kontrak servis*) memuat lihat keduanya (termasuk baca register aset untuk pemilih), catat garansi, susun kontrak servis, dan arsipkan kontrak.

## Endpoint

| Endpoint | Guna |
| --- | --- |
| `GET/POST garansi-aset`, `PATCH/DELETE garansi-aset/{id}` | Garansi per aset. |
| `GET garansi-aset/vendor` | Pemilih penjamin dan vendor servis. |
| `GET garansi-aset/akan-berakhir?hari=30\|60\|90` | Yang akan berakhir. |
| `GET pemeliharaan-aset/referensi/garansi?aset_id[]=&tanggal=` | Pemberitahuan untuk work order. |
| `GET/POST kontrak-servis`, `GET/PATCH/DELETE kontrak-servis/{id}` | Kontrak servis; daftar dapat disaring `aset_id`. |

Kontraknya di `contracts/src/paths/garansi-kontrak-servis.yaml`.

## Belum ada

- Garansi bawaan per jenis aset (F&O: *Asset type defaults → Vendor warranty*), yang otomatis terpasang saat aset diterima.
- Syarat garansi rinci (batas jam, biaya, barang) dan klaim garansi ke vendor dari work order.
- Pengingat terjadwal (notifikasi) untuk yang akan berakhir; sekarang hanya daftar.

## Test

`tests/Feature/AssetWarrantyTest.php`: garansi per aset dan versi baris; daftar akan berakhir 30/60/90 hari untuk garansi dan kontrak beserta penyaringan per permission; pemberitahuan work order hanya untuk aset bergaransi dan tanggal yang tepat; sinkron kontrak tidak menyentuh aset di luar jangkauan dan penyaring per aset; garansi mengikuti jangkauan aset.
