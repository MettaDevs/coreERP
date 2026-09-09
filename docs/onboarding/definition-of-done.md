# Definition of done

Standar "selesai" di tim ini lebih keras dari kebanyakan tempat. Baca sebelum kamu mengirim PR pertama, bukan sesudah PR-mu ditolak.

## Aturan dasarnya

> Sebuah pekerjaan dianggap selesai hanya ketika mempunyai **writer**, **reader**, **failure state**, dan **test yang membuktikan state sebelumnya tidak dapat menyamar sebagai state berikutnya.**

Kalimat terakhir itu inti seluruh aturan. Kalau kode "sudah dipasang" tidak bisa dibedakan dari "berhak memasang", kamu belum selesai — sekalipun semua test hijau. Sumbernya: [Gate fondasi Core](/dev/10-core-foundation-gates), aturan keputusan nomor 3.

## Tiga tingkat

### 1. Perubahan biasa

- [ ] `composer lint:check` lulus
- [ ] `composer types:check` lulus
- [ ] `php artisan test` lulus
- [ ] `npm run lint:check` dan `npm run format:check` lulus
- [ ] Perubahan user yang tidak terkait tidak ikut terbawa
- [ ] Hanya scope yang berubah yang diverifikasi

Keempat perintah itu dijalankan dari `apps/control-plane` dan **ikut menjangkau `modules/`**.
Mengubah module tanpa menjalankannya berarti CI yang menemukan masalahnya, bukan kamu.

### 2. Perubahan UI

Semua di atas, plus:

- [ ] Container runtime **dibuat ulang** lewat `.\start.ps1 -Build`
- [ ] Layar yang diubah dibuka di `http://localhost:8000` dan artifact barunya benar-benar tampil
- [ ] Health check stack selesai

::: warning
Build lokal lulus **bukan** bukti perubahan UI sudah tayang. Jangan melaporkan selesai berdasarkan type-check saja.
:::

Kalau kamu mengubah navigasi atau security di `app.yaml`, tambahan:

- [ ] `app:register-manifest <id module>` dijalankan lewat container `core-app`
- [ ] Kolom `apps.navigation` di database runtime diverifikasi
- [ ] Shell Core di-reload

### 3. Module baru

Semua di atas, plus **penjaga batas** dan **gate load test**. Module tidak selesai hanya karena feature test lulus.

Penjaga batasnya hidup sebagai test biasa di `apps/control-plane/tests/Feature/Boundary/` dan ikut
`php artisan test`. Ia memindai seluruh isi `modules/`, jadi module baru langsung masuk cakupannya
tanpa satu pun berkas yang perlu didaftarkan. Yang ditolaknya: tabel tanpa awalan module, tabel
milik module lain yang disentuh, model tenant tanpa `MilikTenant`, namespace yang menyeberang,
kerangka aplikasi Laravel di dalam folder module, rute module tanpa middleware konteks, dan
manifest yang susunannya tidak sah.

Kalau salah satunya merah, itu bukan test yang perlu dilonggarkan. Ia adalah satu-satunya hal yang
akan menangkap pelanggaran itu sebelum module dipasang pada tenant sungguhan.

| Dimensi | Minimum |
| --- | --- |
| Virtual user serentak | 1000+, ditahan, bukan lonjakan sesaat |
| Tenant digerakkan bersamaan | 100+ |
| Instance API di belakang load balancer | 2+, disarankan 4 |
| Database | PostgreSQL asli. **Tidak pernah SQLite** |
| Durasi pada beban penuh | 90 detik+ setelah pemanasan |

**Gate kebenaran — wajib nol, tidak peduli perangkat kerasnya:**

- error 5xx yang berasal dari aplikasi
- kode bisnis ganda atau `creation_key` ganda dalam satu tenant
- baris anak yang induknya milik tenant lain atau tidak ada
- baca, daftar, atau tulis yang menembus batas tenant
- permission satu resource yang membuka resource sebelahnya
- dua request serentak dengan `Idempotency-Key` sama menghasilkan dua record
- nomor dengan prefix milik reference lain

Diverifikasi lewat **SQL langsung ke database** sesudah run — bukan lewat API. API adalah yang sedang diuji; ia tidak boleh jadi hakim atas dirinya sendiri. Probe lintas tenant dijalankan *selama* beban penuh, bukan sesudahnya.

Detail lengkap termasuk gate latensi: [Load dan concurrency testing](/dev/20-load-and-concurrency-testing). Implementasi rujukan ada di `modules/apperp/management-aset/loadtest/`.

## Kalau gate tidak bisa dijalankan

Nyatakan terus terang bahwa module belum terverifikasi di bawah concurrency, dan **jangan** melaporkannya selesai. Lingkungan yang tidak lengkap adalah gap yang dilaporkan, bukan gate yang dilewati.

Prinsip yang sama berlaku umum: kalau sesuatu belum ada, katakan belum ada. Jangan membuat compatibility layer, tabel placeholder, atau status UI palsu untuk menutupinya.

## Penandaan di backlog

Di [`docs/todo/`](/todo/), sebuah item hanya boleh ditandai `[x]` kalau memenuhi aturan di atas — ada test yang membuktikannya, bukan sekadar kodenya ada.

| Tanda | Arti |
| --- | --- |
| `[ ]` | Belum dikerjakan |
| `[~]` | Sedang dikerjakan |
| `[x]` | Selesai **dan** ada test yang membuktikannya |

## Lihat juga

- [Gate fondasi Core](/dev/10-core-foundation-gates) — kapan sebuah fondasi boleh mulai dibangun
- [Load dan concurrency testing](/dev/20-load-and-concurrency-testing)
- [Cara berkontribusi](/onboarding/kontribusi)
