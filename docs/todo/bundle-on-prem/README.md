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

## Tiga keputusan yang sudah diambil

Ketiganya menentukan bentuk skripnya, dan ketiganya diputuskan pada 10 September 2026 setelah
membaca praktik yang dipakai orang lain — bukan ditebak. Sumbernya ada di akhir bagian ini.

### 1. Yang memverifikasi tanda tangan tiba lewat jalur lain

**Keputusan: tanda tangan terpisah dengan `openssl`, dan kunci publik dipasang sekali saat
pemasangan pertama lewat jalur yang berbeda dari bundle-nya.**

Prinsip yang menentukan pilihan ini bertahan terlepas dari alat apa pun yang dipakai: **kunci
pemverifikasi harus tiba lewat jalur yang berbeda dari benda yang diverifikasinya.** Bundle yang
membawa kunci pemverifikasinya sendiri tidak memverifikasi apa pun terhadap orang yang mengganti
keduanya sekaligus — ia hanya membuktikan bundle itu konsisten dengan dirinya sendiri.

Praktik industrinya hari ini **Sigstore/cosign**, dan untuk lingkungan terputus polanya jelas:
seluruh bahan verifikasi menyeberang lebih dulu, dan akar kepercayaannya diambil lewat **TUF**,
bukan lewat unduhan biasa. Itu bentuk yang lebih kuat, dan ia **dicatat sebagai jalur naik** ketika
pengiriman lewat registry sudah ada.

Alasan tidak memakainya sekarang disebut apa adanya, bukan disembunyikan: cosign menuntut pelanggan
memasang alat tambahan dan mengelola akar kepercayaan sendiri. Untuk satu server di sebuah apotek,
ongkos itu lebih besar daripada yang dibelinya. `openssl` sudah ada di mesin mana pun dan memenuhi
prinsip yang sama.

Checksum SHA-256 tetap ikut, dan perannya **berbeda dari tanda tangan**: checksum menjaga dari
berkas yang rusak saat disalin, tanda tangan menjaga dari berkas yang diganti orang. Keduanya bukan
pengganti satu sama lain.

### 2. Mundur berarti memulihkan image **dan** database

**Keputusan: kembalikan ke image terakhir yang terbukti sehat, dan pulihkan cadangan yang diambil
sebelum migrasi. Bila tidak ada versi sehat sebelumnya, skripnya berhenti — bukan menebak.**

Ini menuntut satu istilah dijelaskan lebih dulu, karena ia menentukan segalanya.

**"Health check gagal" artinya:** sesudah container ditukar ke image baru, skrip bertanya kepada
aplikasinya — apakah ia hidup dan menjawab benar. Bila jawaban itu tidak kunjung datang dalam batas
waktu, pembaruan dinyatakan gagal. Keadaan yang benar-benar memunculkannya di tempat pelanggan:
kunci setelan baru yang tidak ada di `.env` mereka, migrasi yang berhasil tetapi meninggalkan skema
yang tidak cocok dengan kode baru, perender atau antrean yang tidak terjangkau, atau image yang
ternyata dibangun untuk edisi lain sehingga modul yang dibeli tidak ada di dalamnya.

Mekanisme ini bukan hal baru. AWS menyebutnya **deployment circuit breaker** pada ECS: ia menunggu
task baru mencapai status berjalan, lalu memeriksa health check-nya; tiap kegagalan menambah
hitungan, dan begitu hitungan menyentuh ambang, deployment ditandai gagal lalu dikembalikan.

Dua perilakunya ditiru di sini:

- **Sasaran mundurnya versi yang terbukti pernah sehat**, bukan sekadar versi sebelumnya —
  *"it looks for the most recent deployment that is in a `COMPLETED` state"*.
- **Bila tidak ada versi seperti itu, ia berhenti** — *"the circuit breaker does not launch new
  tasks and the deployment is stalled"*. Berhenti dengan keadaan yang jelas lebih baik daripada
  memutar mundur ke sesuatu yang tidak diketahui pernah bekerja.

Satu perilakunya **tidak dapat** ditiru, dan itu justru inti persoalannya: **AWS memutar mundur
container tanpa status; ia tidak pernah memutar mundur database.** Kasus ini punya migrasi di
tengah, dan di situlah model itu berhenti menolong.

Karena itu memulihkan image saja **ditolak sebagai pilihan**. Ia hanya sah bila setiap migration
kompatibel mundur, dan hari ini tidak ada aturan yang mewajibkannya maupun pemeriksa yang menolak
yang melanggarnya. Membangun keduanya adalah pekerjaan tersendiri yang jauh lebih besar daripada
skrip ini.

Ongkosnya disebut terang-terangan, bukan disembunyikan: **data yang ditulis setelah pembaruan
dimulai akan hilang saat mundur.** Itu alasan tambahan mengapa pembaruan dijalankan pada jendela
yang disepakati, bukan di tengah jam kerja.

Batasan jujur yang juga ditulis AWS dan berlaku sama di sini: circuit breaker melacak kegagalan
peluncuran dan health check, **bukan** metrik aplikasi. Versi baru yang menyala, lulus health check,
tetapi menjawab salah — tidak akan tertangkap mekanisme ini oleh siapa pun.

### 3. Cadangan mundur dan cadangan bencana adalah dua hal berbeda

**Keputusan: `update.sh` menulis cadangan mundur secara lokal, dan menolak jalan bila lokasinya
berada di filesystem yang sama dengan data database. Cadangan luar lokasi adalah kewajiban terpisah
di runbook pelanggan, bukan sesuatu yang dipura-purakan selesai oleh skrip ini.**

Acuannya aturan **3-2-1** — tiga salinan, dua jenis media, satu di luar lokasi — dan kalimat yang
paling mengena: sebuah cadangan yang duduk di sebelah produksi, pada perangkat keras yang sama,
berjarak satu kejadian buruk dari menjadi tidak berguna.

Tetapi menuntut cadangan menyeberang ke S3 atau NAS **sebelum** pembaruan boleh jalan akan membuat
pembaruan mustahil di tempat yang benar-benar terputus dari jaringan — dan pelanggan seperti itu
justru alasan bentuk on-prem ini ada.

Pembedaannya yang menyelesaikan pertentangan itu:

| | Cadangan mundur | Cadangan bencana |
| --- | --- | --- |
| Dipakai kapan | beberapa menit setelah dibuat, oleh skrip yang sedang berjalan | berhari-hari kemudian, oleh manusia |
| Harus | cepat, dan bekerja tanpa internet | jauh dari mesin aslinya |
| Tempatnya | lokal, di filesystem yang **berbeda** dari data database | luar lokasi — S3, NAS, mesin lain |
| Siapa yang mengurus | `update.sh` | runbook pelanggan |

Menolak jalan ketika lokasinya sefilesystem dengan data database adalah bagian yang mengikat:
cadangan di disk yang sama tidak menolong pada kegagalan yang paling mungkin terjadi, dan skrip yang
diam-diam menerimanya memberi rasa aman yang tidak ada dasarnya.

### Sumber

Dibaca dari sumbernya pada 10 September 2026, bukan dari ingatan.

- [How the Amazon ECS deployment circuit breaker detects failures](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/deployment-circuit-breaker.html)
  — dua tahap deteksi, ambang kegagalan, dan perilaku ketika tidak ada versi sehat untuk dituju.
- [Announcing Amazon ECS deployment circuit breaker](https://aws.amazon.com/blogs/containers/announcing-amazon-ecs-deployment-circuit-breaker/)
  — latar dan contoh pemakaiannya.
- [Cosign signing overview](https://docs.sigstore.dev/cosign/signing/overview/) — bentuk penandatanganan yang berlaku sekarang.
- [Verifying cosign signatures in an air-gapped environment](https://oneuptime.com/blog/post/2026-08-11-verify-cosign-air-gapped-sigstore-bundles/view)
  — apa saja yang harus menyeberang lebih dulu pada lingkungan terputus.
- [Sigstore: bring your own TUF](https://blog.sigstore.dev/sigstore-bring-your-own-stuf-with-tuf-40febfd2badd/)
  — kenapa akar kepercayaan diambil lewat TUF, bukan unduhan biasa.
- [3-2-1 backup rule](https://www.druva.com/learning-center/glossary/3-2-1-backup-rule) — tiga salinan, dua media, satu di luar lokasi.

## Selesai bila

- Bundle edisi apotek dipasang di mesin yang **tidak punya git, composer, maupun npm**, dan tidak
  punya akses ke repo kita.
- Mesin itu berhasil dimutakhirkan ke versi berikutnya.
- Mesin itu berhasil **dikembalikan** ke versi sebelumnya, dan datanya utuh.
- Ketiganya dibuktikan dengan menjalankannya, bukan dengan membaca skripnya.
- Jalur gagalnya dibuktikan merah lebih dulu, dan bukan hanya satu bentuk:
  - bundle dengan tanda tangan yang **salah** ditolak;
  - bundle yang tanda tangannya sah tetapi **checksum**-nya tidak cocok ditolak — keduanya jalur
    yang berbeda dan keduanya harus terbukti menolak;
  - pembaruan yang health check-nya gagal benar-benar kembali ke image lama **dan** memulihkan
    cadangannya;
  - pembaruan yang tidak punya versi sehat sebelumnya **berhenti**, bukan menebak;
  - `update.sh` menolak jalan ketika lokasi cadangan berada di filesystem yang sama dengan data
    database.

## Yang dikerjakan lebih dulu, dan yang menunggu mesin bersih

Kriteria selesai paling atas menuntut mesin virtual bersih, dan itu ditunda pemilik produk sampai
mesinnya tersedia.

Sisanya **tidak** menuntut mesin bersih dan dapat dikerjakan lebih dulu dengan Docker biasa: bentuk
bundle, `build-bundle.sh`, `update.sh` beserta jalur mundurnya, dan seluruh pembuktian merah di
atas. Menjalankannya di mesin yang punya git dan composer tidak membuktikan bundle-nya berdiri
sendiri — itu bagian yang menunggu — tetapi membuktikan urutan, penolakan, dan pemulihan berjalan
seperti yang tertulis.

Urutan pengerjaan yang disarankan, karena tiap langkah membuat langkah berikutnya dapat dibuktikan:

1. `build-bundle.sh` beserta bentuk isinya dan checksum-nya.
2. Penandatanganan dan verifikasinya, beserta dua bentuk penolakan di atas.
3. `update.sh` sampai health check — tanpa jalur mundur dulu.
4. Jalur mundurnya, dibuktikan dengan sengaja membuat health check gagal.
5. Penolakan lokasi cadangan yang sefilesystem dengan data database.

## Keadaan pada 10 September 2026

Ketiga berkas sudah ada: `scripts/build-bundle.sh`, `scripts/update.sh`, dan
`deploy/compose.edition.yaml`. Seluruh jalur di bawah **dijalankan**, bukan dibaca.

### Satu koreksi terhadap rencana di atas

Bagian "Bentuk yang dituju" menyebut bundle berisi **image edisi**. Itu tidak cukup: `core-db` dan
`core-renderer` memakai image dari registry publik, jadi bundle satu-image gagal menyala di mesin
tanpa internet — dan gagalnya pada langkah menyalakan container, sesudah admin mengira pemasangannya
berhasil.

Bundle sekarang membawa **seluruh** image, dan daftarnya **dibaca dari berkas compose** yang ikut di
dalamnya. Daftar yang ditulis tangan akan menyimpang pada hari sebuah layanan ditambahkan, dan
menyimpangnya baru ketahuan di mesin yang tidak punya internet untuk menambalnya. Akibat yang
mengikat: kedua image pendamping ditulis **harfiah** di `deploy/compose.edition.yaml`, bukan lewat
variabel — variabel membuat keduanya lolos dari pembacaan itu.

Ongkosnya disebut apa adanya: bundle edisi apotek menjadi **329 MB**.

### Dua cacat yang hanya ketahuan karena dijalankan

Keduanya lolos pembacaan berulang kali, dan keduanya muncul pada percobaan mundur yang pertama.

**Mundur memakai compose milik bundle yang baru saja ditolak.** Bundle yang gagal justru karena
compose-nya sendiri bermasalah akan membawa masalah itu ikut ke dalam pemulihannya: aplikasi tidak
menyala kembali sesudah mundur — kegagalan kedua yang lahir dari kegagalan pertama. Sekarang berkas
compose milik versi sehat ikut disimpan bersama nama image-nya, dan jalur mundur memakai yang itu.
Mundur menuntut keduanya: image yang terbukti sehat, dan berkas compose yang terbukti
menyalakannya.

**Pemulihan database tidak membuang apa yang ditambahkan migrasi yang gagal.** `pg_restore --clean`
hanya membuang objek yang ada **di dalam dump**; tabel yang dibuat migrasi yang gagal lahir sesudah
cadangan diambil, jadi ia tidak pernah tersentuh dan tetap berdiri sesudah mundur. Skema hasil mundur
karena itu bukan skema versi lama, melainkan campuran keduanya — dan campuran itu tidak pernah diuji
siapa pun. Sekarang database dibuang lalu dibuat ulang sebelum dipulihkan, dan container aplikasi
dihentikan lebih dulu supaya koneksinya tidak menahan penghapusan itu.

Percobaan pertama membuktikan tabel migrasi **masih ada** sesudah mundur; percobaan sesudah
perbaikan membuktikan ia **hilang**, sementara data sebelum pembaruan tetap utuh.

### Kelima jalur merah, dijalankan

| Yang dibuktikan | Hasil |
| --- | --- |
| Bundle ditandatangani kunci lain | ditolak, keluar dengan kode 1, sebelum apa pun disentuh |
| Tanda tangan sah, satu berkas diubah | tanda tangan **lolos**, checksum yang menolak — dua jalur yang memang berbeda |
| Pembaruan gagal | mundur: image kembali, database dibuang dan dipulihkan, aplikasi sehat lagi |
| Belum ada versi sehat | berhenti, bukan menebak |
| Cadangan sefilesystem dengan data | ditolak; di filesystem berbeda, lanjut |

Sesudah mundur: data sebelum pembaruan utuh, tabel buatan migrasi yang gagal hilang, `core-app`
sehat kembali pada image lama, dan modulnya masih dilayani.

### Yang masih menunggu mesin bersih

Pemasangan di mesin yang **tidak punya git, composer, maupun npm** belum diuji. Yang sudah terbukti
adalah urutan, penolakan, dan pemulihannya; yang belum adalah klaim bahwa bundle-nya benar-benar
berdiri sendiri.

Satu keadaan yang ditemui saat menguji dan pantas diketahui admin: memasang ke proyek compose yang
volume databasenya sudah ada dari pemasangan lain akan gagal dengan `password authentication
failed`. PostgreSQL hanya memakai `POSTGRES_PASSWORD` saat inisialisasi pertama; volume lama tetap
memegang sandi lama.

### Satu jebakan pada cara memverifikasinya, bukan pada kodenya

Pembangunan bundle pertama terbaca "selesai dengan kode 0" padahal `docker build` di dalamnya gagal.
Sebabnya bukan skripnya — ia dijalankan lewat `| tail`, dan **kode keluar sebuah pipeline adalah
milik perintah terakhirnya**. Jalankan tanpa pipa ketika yang dipercaya adalah kode keluarnya.
