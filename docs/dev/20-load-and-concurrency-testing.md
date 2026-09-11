# Load dan concurrency testing

Dokumen ini menetapkan kapan sebuah modul boleh dinyatakan selesai. Test feature tidak cukup: ia menjalankan satu request pada satu proses terhadap SQLite, sehingga secara struktur tidak dapat melihat koneksi database habis, nomor yang terbit dua kali, batas tenant yang bocor hanya ketika request saling menyela, atau idempotency key yang berlomba dengan dirinya sendiri.

## Syarat minimum

| Dimensi | Minimum |
| --- | --- |
| Virtual user serentak | 1000+, ditahan, bukan lonjakan sesaat |
| Tenant yang digerakkan bersamaan | 100+ |
| Instance API di belakang load balancer | 2+, disarankan 4 |
| Database | engine asli yang dipakai modul (PostgreSQL). Tidak pernah SQLite |
| Durasi pada beban penuh | 90 detik+ setelah pemanasan |
| Number Sequence | penerbit sungguhan di dalam runtime; oracle-nya tabel terbitan Core, bukan stub |

Beberapa instance bukan hiasan. Itu satu-satunya cara membuktikan modul tidak menyimpan counter, identitas tenant, atau cache permission pada memori satu proses API. Satu instance akan lulus test yang seharusnya gagal.

## Gate kebenaran

Nilainya wajib **nol**, dan tidak bergantung pada perangkat keras. Mesin lambat bukan alasan.

- error 5xx yang berasal dari aplikasi
- kode bisnis ganda atau `creation_key` ganda dalam satu tenant
- baris anak yang induknya milik tenant lain, atau induknya tidak ada
- baca, daftar, atau tulis yang menembus batas tenant
- permission satu resource yang membuka resource sebelahnya
- dua request serentak dengan `Idempotency-Key` sama yang menghasilkan dua record
- nomor dengan prefix milik reference lain

Verifikasi lewat SQL langsung ke database sesudah run, bukan lewat API. API adalah yang sedang diuji; ia tidak boleh menjadi hakim atas dirinya sendiri. Probe lintas tenant dan probe eskalasi hak dijalankan **selama** beban penuh, bukan sesudahnya.

Bedakan 5xx aplikasi dari 502/504 load balancer. Yang pertama adalah cacat; yang kedua adalah kapasitas terlampaui dan wajib dinyatakan sebagai itu.

### Oracle yang tidak pernah bisa merah bukan oracle

Nol dari pemeriksa yang mati terbaca persis sama dengan nol dari sistem yang benar, dan keduanya tidak dapat dibedakan dari ringkasan run. Sebelum sebuah nol dipercaya, buktikan pemeriksanya bisa merah: jalankan sekali dengan salah satu pemeriksaan sengaja dilanggar — probe lintas tenant diarahkan ke record milik sendiri, penulis balapan mengirim gabungan kedua himpunan, satu baris cacat disuntik ke database lalu dihapus lagi — dan catat angka merahnya di samping angka hijaunya.

Yang dirusak adalah **permintaan atau data uji**, bukan produknya. Yang dibuktikan karenanya adalah pendeteksinya hidup, dan itu satu-satunya hal yang membuat nol berikutnya berarti.

Ada pemeriksaan yang tidak dapat dibuat merah lewat suntikan karena skema menolaknya lebih dulu — kunci asing komposit `(tenant_id, parent_id)` menolak induk lintas tenant di lapis database. Itu jawaban yang sah, dan wajib ditulis sebagai itu: yang menahannya bukan kode aplikasi, melainkan skema.

Satu jebakan yang mudah terlewat: **status 0 bukan jawaban.** Klien yang menyerah menunggu tidak mengatakan apa-apa tentang benar atau salah, dan pemeriksa yang memperlakukannya sebagai jawaban akan memerah setiap kali mesin kehabisan napas — sehingga run yang kapasitasnya terlampaui terlihat sama dengan run yang datanya bocor. Karena alasan yang sama, `http_req_failed` dan `checks` tidak layak menjadi gate kebenaran; yang layak adalah pencacah yang hanya naik ketika jawaban benar-benar salah.

## Endpoint pengganti wajib menahan baris yang digantinya

Endpoint yang mengganti sekumpulan baris sekaligus — "ini sekarang daftar lengkapnya" — bekerja dengan menghapus lalu menyisipkan ulang. Transaksi saja tidak cukup: dua pemanggil dapat saling menyela sehingga yang satu menghapus, yang lain menghapus dan menyisipkan, lalu yang pertama menyisipkan di atasnya.

Hasilnya **gabungan dua daftar**, yang tidak diminta pemanggil mana pun dan yang tidak ditolak batasan database mana pun, karena tiap baris yang tersisa masing-masing sah. Ambil `lockForUpdate()` pada baris pemiliknya di dalam transaksi, sebelum penghapusan.

Tabel penghubung yang disunting dari **dua arah** perlu perlakuan tambahan. Mengunci pemilik masing-masing arah tidak menyerialkan apa pun — keduanya memegang kunci pada tabel berbeda dan tetap saling menimpa. Kedua arah wajib mengunci **sisi yang sama**, atas gabungan daftar lama dan baru, sehingga dua operasi yang menyentuh baris kaitan yang sama pasti berbagi kunci. Ambil kunci dalam urutan tetap — misalnya urut `id` — atau dua transaksi akan mengambil baris yang sama dalam urutan berlawanan lalu saling menunggu selamanya.

Feature test tidak dapat memperlihatkan ini, dan review kode yang membaca satu request pada satu waktu juga tidak. Ia hanya terlihat di skenario yang benar-benar berebut.

### Skenario yang tidak pernah berebut tidak membuktikan apa pun

Menyebar pengguna merata ke seluruh tenant dan seluruh record adalah bentuk yang benar untuk profil saturasi, dan **salah** untuk balapan: dua penulis nyaris tidak pernah bertemu, run kembali hijau, dan cacatnya lolos.

Skenario balapan memusat — banyak pengguna, sedikit record, menulis nilai yang sengaja bertabrakan — lalu memastikan hasil baca-baliknya sama dengan salah satu nilai yang dikirim, bukan campuran keduanya. Simpan sebagai profil tersendiri di samping saturasi; keduanya menjawab pertanyaan yang berbeda.

**Permukaan baru butuh skenarionya sendiri.** Modul yang skenarionya mencakup master bawaan tetapi tidak yang ditambahkan kemudian berstatus belum terverifikasi untuk bagian yang berubah. Cocokkan daftar resource di skrip terhadap rute yang ada — nama yang kebetulan terdengar mirip bukan cakupan.

## Gate latensi

Diukur pada concurrency tertinggi yang masih memenuhi SLO, bukan pada titik jenuh. Latensi pada beban jenuh mengukur kedalaman antrean, bukan biaya kode.

| Operasi | p95 | p99 |
| --- | --- | --- |
| Baca (show, list) | < 200 ms | < 500 ms |
| Tulis (create, update, archive), termasuk satu panggilan ke Core | < 400 ms | < 900 ms |

Catat juga biaya request tunggal tanpa beban. Angka ini lebih dapat dipindahkan antar perangkat keras daripada persentil di bawah beban:

| Jalur | Anggaran |
| --- | --- |
| Tanpa auth, tanpa database | < 25 ms |
| Terautentikasi, baca satu tabel | < 50 ms |
| Tulis terautentikasi termasuk penerbitan nomor | < 120 ms |

## Menyebut bottleneck adalah bagian dari gate

Angka request-per-detik tidak berarti tanpa perangkat keras yang menyertainya. Yang selalu berarti: **resource mana yang jenuh lebih dulu**, dengan bukti berupa CPU per container, jumlah koneksi, dan jumlah worker. "Lambat" bukan hasil; "PostgreSQL membakar 5,5 core karena tiap request membuka koneksi baru, 36.809 session untuk 282.000 transaksi" adalah hasil.

### Penanganan koneksi jenuh lebih dulu daripada kode modul

Periksa ini pertama, setiap kali:

- Laravel membuka dan menutup koneksi database per request kecuali diperintahkan lain. PostgreSQL fork satu proses per koneksi, jadi koneksi per request menjadi badai fork jauh sebelum ada query yang lambat.
- Perbaiki di lapis deployment: koneksi persisten (`DB_PERSISTENT`, dengan `MaxRequestWorkers × jumlah instance` tetap di bawah `max_connections`) atau connection pooler. PgBouncer single-threaded dan menghitung ulang autentikasi tiap koneksi klien, sehingga ia menjadi bottleneck berikutnya bila app tetap membuka koneksi per request.
- Cache config dan route seperti produksi. Satu closure pada file route diam-diam mematikan `php artisan route:cache`; pakai controller.

## Bila load test tidak dapat dijalankan

Nyatakan terus terang bahwa modul belum terverifikasi di bawah concurrency, dan jangan melaporkannya selesai. Lingkungan yang tidak lengkap adalah gap yang dilaporkan, bukan gate yang dilewati.

## Setiap angka menyebut dirinya terukur atau perhitungan

Angka yang tidak menyebut asalnya akan dikutip ulang sebagai fakta oleh orang berikutnya. Karena itu
setiap angka di dokumen mana pun wajib menyebut tiga hal: apakah ia **terukur** atau **perhitungan**,
kapan ia diambil, dan bagaimana mengulanginya.

Tabel yang mengalikan sebuah angka terukur — misalnya memperkirakan kebutuhan memori untuk sekian
tenant dari satu bacaan — wajib menyebut dirinya perhitungan, menyebut angka dasar mana yang terukur,
dan menyebut kapan angka dasar itu diambil. Tanpa ketiganya, tabel perhitungan tidak bisa dibedakan
dari tabel hasil pengukuran, dan itu jenis kesalahan yang bertahan lama karena tidak ada yang gagal.

Nama sebuah pengukuran juga mengikat. Tidak ada angka yang boleh dinamai "waktu pasang" kecuali ia
diambil dari mesin yang benar-benar bersih; angka dari mesin yang sudah punya image, dependency, atau
cache mengukur hal yang berbeda dengan nama yang sama.

## Cara membaca angka dari container tanpa merusaknya

Dua kebiasaan yang keduanya lahir dari kesalahan nyata:

**Ambil bacaan idle sebelum menjalankan pengukuran apa pun.** Memori proses pengukur ikut dihitung
oleh cgroup container yang sedang diukur, jadi bacaan yang diambil sambil menjalankan sesuatu di
dalam container mencampur keduanya. Bacaan tepat setelah stack menyala juga salah — angkanya masih
bergerak. Tunggu sampai berhenti bergerak, dan itu memakan waktu beberapa menit, bukan beberapa
detik.

**Pengukuran bundel wajib dapat diulang.** Bangun ulang susunan penuh sebagai kontrol dan pastikan
jumlah bytes-nya sama persis dengan bacaan sebelumnya. Kalau kontrolnya tidak sama, yang berubah
bukan hal yang sedang diukur, dan perbandingannya tidak berarti apa-apa.

## Oracle kebenaran dibuktikan merah lebih dulu

Sebuah oracle yang belum pernah terlihat gagal tidak dapat dibedakan dari oracle yang tidak memeriksa
apa pun. Setiap oracle karena itu dibuktikan bisa merah sebelum hasilnya dipercaya, dan oracle yang
**tidak dapat** dibuat merah dicatat apa adanya sebagai tidak terbukti — bukan dihitung sebagai
pembuktian yang berhasil.

Beberapa aturan yang mengikuti dari pengalaman menjalankannya:

- Uji beban berjalan di runtime yang sebenarnya, tanpa tiruan Core. Skenario menyiapkan tenant lewat
  alur pendaftaran usaha sungguhan, lalu memakai sesi seperti pengguna biasa.
- Oracle nomor membaca tabel penerbitan nomor dan menuntut setiap kode terikat pada satu penerbitan,
  untuk tenant **dan** reference yang benar. Menuntut keunikan saja akan lolos ketika dua tenant
  saling meminjam urutan.
- Status 0 dari sisi klien berarti permintaannya tidak pernah dijawab. Ia bukan jawaban, dan tidak
  boleh dihitung sebagai jawaban pada probe apa pun.

## Implementasi rujukan

`apps/core/loadtest/` adalah stack-nya, dan sejak 10 September 2026 hanya ada satu: Compose dengan empat instance runtime Core di belakang nginx, PgBouncer, PostgreSQL, `prepare.sh` yang menjalankan urutan bootstrap yang sama dengan stack pengembangan, dan `verify.sql` sebagai oracle sisi Core. `README.md` di dalam folder itu mencatat hasil terukur, perintah persis yang menghasilkannya, dan batas kejujurannya.

Skenario dan oracle milik module tinggal di folder module — `modules/apperp/management-aset/loadtest/` — karena permukaan yang diuji memang miliknya, dan keduanya berjalan di atas stack di atas lewat `k6/lib.js` yang sama.

Tiruan Core sudah tidak ada. Nomor diterbitkan proses yang sama lewat kontrak `PenerbitNomor`, jadi oracle nomor dibaca dari tabel `number_sequence_issues` — buku terbitan Core yang sungguhan, yang mengikat tiap kode tersimpan ke satu baris terbitan milik tenant dan reference yang benar.

## Lihat juga

- [Definition of done](../onboarding/definition-of-done.md) — posisi gate ini dalam standar selesai
- [Gate fondasi Core](10-core-foundation-gates.md) — aturan keputusan yang melandasinya
- [Standar module](02-module-standard.md) — module yang wajib melewati gate ini
- [Number sequence](14-number-sequences.md) — oracle nomor yang dipakai saat pengujian
