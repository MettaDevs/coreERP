# Bundle dan pemasangan di server pelanggan

Halaman ini adalah tugas **F5-06** dari pemindahan ke satu runtime, dipindahkan ke folder tersendiri
karena folder rencana kerja itu dibubarkan sementara tugas ini belum pernah dibangun. Isinya tidak
diubah maknanya; yang ditambahkan hanya keadaan hari ini, supaya orang yang mengerjakannya nanti
tidak mulai dari nol.

## Kenapa ini ada

PRD pemindahan menyebut bagian ini **"satu-satunya alasan seluruh arsitektur ini ada"**, dan
kalimat itu bukan dramatisasi. Model penjualannya perpetual dan on-prem: pelanggan membeli edisi,
memasangnya di kotak miliknya sendiri, lalu **admin pelanggan yang menjalankan pembaruannya** —
bukan kita. Selama cara memasang dan memutakhirkan belum ada dalam bentuk yang dapat dijalankan
orang lain, yang kita punya baru cara membangun, bukan cara mengirim.

## Yang sudah ada hari ini

| Bagian | Keadaan | Tempatnya |
| --- | --- | --- |
| Menghitung modul sebuah edisi | Ada | `php artisan edition:resolve` |
| Membangun image edisi | Ada | `scripts/build-edition.sh` |
| Membuktikan modul yang tidak dibeli tidak ada di dalam image | Ada, dijaga CI | `scripts/verify-edition.sh` |
| Membuktikan sisa mesin pembangun tidak ikut | Ada, dijaga CI | `scripts/periksa-sisa-mesin.sh` |
| Menyalakan satu edisi di satu server Linux | Ada, di repo penyebaran | `deploy.sh` + `compose.yaml` |
| Migration, seed, dan pendaftaran katalog yang aman diulang | Ada | service `core-migrate` |

Jalur yang ada itu **membangun di server pelanggan**: ia menyinkronkan repo CoreERP lewat git, lalu
`docker build`. Itu memadai untuk server yang kita pegang sendiri, dan **tidak** memadai untuk
pelanggan on-prem sungguhan — ia menuntut git, akses ke repo privat kita, composer, npm, dan
sambungan keluar. Bundle inilah yang menghapus keempat tuntutan itu.

## Yang belum ada

- `scripts/build-bundle.sh`
- `scripts/update.sh`
- Berkas compose yang ikut di dalam bundle, bukan yang ditarik dari repo lain

## Bentuk yang dituju

**Isi bundle.** Image edisi hasil simpan (`docker save`), berkas compose, manifest rilis, checksum,
dan tanda tangan.

**Urutan pemasangan, tetap dan tidak boleh diacak.** Periksa tanda tangan → cadangkan database →
muat image → ganti container → jalankan migrasi modul terpasang → periksa kesehatan → **kembalikan
ke image lama bila gagal**.

**Image lama tidak dihapus.** Pengembalian cukup mengganti satu nomor versi, bukan mengunduh ulang
apa pun. Ini yang membuat jalur mundurnya benar-benar dapat ditempuh oleh admin yang sedang panik
pada pukul dua pagi.

Urutan ini bertetangga dengan yang sudah tertulis di
[release dan on-prem](../../dev/03-release-and-on-prem.md#update-dan-rollback). Bila keduanya kelak
berbeda, yang di `docs/dev/` yang benar; halaman ini rencana kerja, bukan desain kanonik.

## Yang perlu diputuskan sebelum mulai

Tiga hal belum punya jawaban di dokumen mana pun, dan ketiganya menentukan bentuk skripnya:

1. **Tanda tangannya diverifikasi dengan apa.** Kunci publik yang ikut di dalam image, atau kunci
   yang dipasang admin terpisah? Yang pertama lebih mudah dan berarti bundle dapat memverifikasi
   dirinya sendiri — yang berarti ia tidak benar-benar memverifikasi apa pun bila penyerang
   mengganti keduanya sekaligus.
2. **Migrasi mundur.** Pengembalian image hanya sah bila migration-nya kompatibel mundur. Belum ada
   aturan yang mewajibkan itu pada migration modul, dan belum ada pemeriksa yang menolak migration
   yang melanggarnya.
3. **Cadangan database siapa yang menyimpan.** Skrip yang mencadangkan ke disk yang sama dengan
   databasenya tidak menolong pada kegagalan yang paling mungkin terjadi.

## Selesai bila

- Bundle edisi apotek dipasang di mesin yang **tidak punya git, composer, maupun npm**, dan tidak
  punya akses ke repo kita.
- Mesin itu berhasil dimutakhirkan ke versi berikutnya.
- Mesin itu berhasil **dikembalikan** ke versi sebelumnya, dan datanya utuh.
- Ketiganya dibuktikan dengan menjalankannya, bukan dengan membaca skripnya.
- Jalur gagalnya dibuktikan merah lebih dulu: pemasangan dengan tanda tangan yang salah **ditolak**,
  dan pemutakhiran yang health check-nya gagal benar-benar kembali ke image lama.

## Kenapa belum dikerjakan

Kriteria selesainya menuntut pemasangan di mesin virtual bersih, dan itu ditunda pemilik produk
sampai mesinnya tersedia. Bagian yang **tidak** menuntut mesin bersih — bentuk bundle, skrip
pembangunnya, dan jalur mundurnya — dapat dikerjakan dan diuji lebih dulu dengan Docker biasa.
