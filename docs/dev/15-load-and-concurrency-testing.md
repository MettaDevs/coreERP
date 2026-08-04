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
| Number Sequence | stub pengganti Control Plane yang mencatat setiap nomor terbit |

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

## Implementasi rujukan

`app-erp-management-aset/loadtest/` berisi contoh lengkap: Compose stack dengan empat instance API di belakang nginx, stub Number Sequence yang sekaligus menjadi oracle nomor, pembuat token konteks multi-tenant, skenario k6 dua profil, dan `verify.sql` sebagai oracle kebenaran. `loadtest/README.md` mencatat hasil terukur beserta batas kejujurannya.

## Lihat juga

- [Definition of done](../onboarding/definition-of-done.md) — posisi gate ini dalam standar selesai
- [Gate fondasi Core](10-core-foundation-gates.md) — aturan keputusan yang melandasinya
- [Standar module](02-module-standard.md) — module yang wajib melewati gate ini
- [Number sequence](14-number-sequences.md) — oracle nomor yang dipakai saat pengujian
