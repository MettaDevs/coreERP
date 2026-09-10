# Manifest edisi pelanggan

Satu berkas per pelanggan. Isinya menjawab satu pertanyaan: **modul apa yang dibeli pelanggan
ini, dan rilis mana yang dipasang di servernya.** Dari berkas inilah image edisi dibangun, dan
modul yang tidak disebut di sini tidak pernah ikut masuk ke image itu — bukan disembunyikan
lewat lisensi, melainkan memang tidak ada berkasnya.

## Bentuknya

```yaml
pelanggan: Apotek Sejahtera
profil: on-prem
rilis: 0.1.0
modul:
  - management-aset
```

| Kunci | Arti |
| --- | --- |
| `pelanggan` | Nama yang dipakai manusia. Tidak dipakai mesin, tetapi ia yang membuat berkas ini bisa dibaca tanpa membuka katalog. |
| `profil` | Profil penempatan: `on-prem` untuk server pelanggan, `pooled` untuk SaaS bersama, `isolated` untuk SaaS dengan database sendiri. |
| `rilis` | Nomor rilis yang dipasang. Satu edisi satu nomor; pemutakhiran berarti mengganti angka ini lalu membangun ulang. |
| `modul` | Id modul yang **dibeli**. Ditulis apa adanya; dependency-nya dihitung mesin, bukan ditulis tangan. |

## Yang dihitung mesin, bukan ditulis di sini

`php artisan edition:resolve <nama berkas>` membaca manifest ini dan memulangkan daftar akhirnya.
Tiga aturan yang dijalankannya, dan ketiganya sengaja tidak dititipkan ke penulis manifest:

1. **Dependency ditutup secara transitif.** Modul yang dibeli membawa serta apa yang
   dibutuhkannya, sedalam apa pun rantainya. Menuliskannya tangan berarti daftar yang akan
   ketinggalan pada hari sebuah modul menambah dependency baru, dan ketinggalannya muncul
   sebagai image yang gagal menyala di server pelanggan.
2. **Modul penghubung ikut hanya bila kedua sisinya ada.** Modul ber-`kind: link` tidak pernah
   menarik dependency-nya masuk; ia ikut kalau — dan hanya kalau — seluruh modul yang
   dihubungkannya sudah ada di dalam hasil hitungan. Inilah yang membuat "integrasi apotek ke
   rawat jalan" tidak ikut terkirim ke pelanggan yang hanya membeli salah satunya.
3. **Modul bahan uji ditolak.** Modul ber-`kind: internal-fixture` hidup di repo sebagai bahan
   uji penjaga batas. Sebuah menu bernama "Contoh A" di layar pelanggan adalah kegagalan yang
   tidak boleh mungkin terjadi, jadi menyebutnya di sebuah edisi adalah kesalahan yang ditolak,
   bukan diabaikan diam-diam.

## Kenapa ada dua contoh, dan kenapa yang satu kosong

`apotek-sejahtera.yaml` membeli satu modul bisnis. `praktek-dr-budi.yaml` tidak membeli satu
pun — ia Core saja.

Pasangan itu disengaja. Edisi yang kosong adalah pemeriksaan kebocoran yang paling tajam yang
bisa dibuat hari ini: **apa pun** jejak modul aset di dalam image-nya — namespace di berkas PHP,
tabel berawalan `aset_`, atau rute modul di bundel JavaScript — adalah cacat, tanpa perlu
memperdebatkan apakah ia "seharusnya" ada. Edisi yang berisi menjadi pembandingnya: yang di sana
jejaknya justru wajib ada.

Katalog hari ini baru punya satu modul bisnis, jadi perbedaan "dibeli" dan "tidak dibeli" hanya
bisa ditunjukkan dengan pasangan ini. Aturan dependency dan modul penghubung sendiri tidak
menunggu katalog bertambah: `EditionResolverTest` membuktikannya pada manifest buatan, sehingga
ketiga aturan di atas sudah terbukti bekerja sebelum modul kedua mendarat.
