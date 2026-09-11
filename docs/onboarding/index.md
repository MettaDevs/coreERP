# Selamat datang di CoreERP

Halaman ini untuk orang yang baru masuk tim. Tujuannya satu: dalam beberapa hari kamu bisa mengubah sesuatu di CoreERP tanpa melanggar aturan yang tidak tertulis.

## Yang perlu kamu tahu duluan

CoreERP adalah platform, dan modul bisnisnya hidup di dalam repo yang sama:

| Repository | Isinya |
| --- | --- |
| `CoreERP` | Control plane, web shell, provider console, SDK UI, **dan seluruh module bisnis** di bawah `modules/<penerbit>/<module>/`. Repo ini juga rumah dokumentasi platform. |
| Orkestrasi lokal | Docker Compose untuk menjalankan semuanya di laptop. Bukan repo domain. |
| `app-erp-*` | App yang belum dipindah ke runtime Core dan masih punya container serta database sendiri. |

Daftar module yang sudah ada bisa dibaca dari `modules/` di repo ini, atau dari
`php artisan module:list` pada runtime yang sedang jalan. Jangan menghafal daftarnya dari halaman
mana pun — ia bertambah.

Konsekuensinya ada tiga, dan ketiganya sering bikin kaget orang baru:

**Satu module tidak boleh menyentuh data module lain.** Tidak ada `join` ke tabel milik module
sebelah. Module berbagi satu database tenant, dan yang memisahkannya adalah awalan nama tabel
beserta penjaga batas yang menolak pelanggarnya di pull request. Batas yang dijaga pemeriksaan
tetap batas.

**Dua bentuk hidup berdampingan.** Module berjalan di runtime Core; app `app-erp-*` yang belum
pindah masih berjalan sebagai container dengan database dan token layanan sendiri. Aturannya
berbeda, dan menilai yang satu dengan aturan yang lain adalah kesalahan yang paling mudah terjadi.
Tabel perbandingannya ada di [Grand design](/dev/01-grand-design#dua-bentuk-yang-hidup-berdampingan).

**Dokumen di [`/dev/`](/dev/) mengikat semuanya.** Ia berlaku untuk module di dalam repo ini
maupun app yang masih berupa container.

## Jalur baca

Urutannya:

<div class="tip custom-block" style="padding-top: 8px">

**Hari pertama** — [Hari pertama](/onboarding/hari-pertama) → [Setup lingkungan lokal](/onboarding/setup) → [Glosarium](/onboarding/glosarium)

**Minggu pertama** — [Grand design](/dev/01-grand-design) → [Tiga kebenaran lifecycle](/onboarding/tiga-kebenaran) → [Alur end-to-end](/onboarding/alur-end-to-end) → [Peta kode ke dokumen](/onboarding/peta-kode) → [Cara berkontribusi](/onboarding/kontribusi)

**Saat dibutuhkan** — sisanya di [Desain kanonik](/dev/), dibuka sesuai tugas

</div>

Kalau kamu ditugaskan ke app tertentu, buka juga hub teknisnya: [Management Aset](/apps/management-aset/) atau [Human Resources](/apps/human-resources/). Kalau kamu akan membangun modul baru, mulai dari [Membangun modul baru](/apps/membangun-app-baru) — bukan dari menyalin folder modul yang sudah jadi.

## Tiga hal yang paling sering disalahpahami

**1. Tenant bukan organization.** Tenant adalah pemegang kontrak dan batas isolasi data tertinggi. Organization adalah identitas bisnis di dalamnya. Keduanya beda tabel, beda tujuan, beda kolom scope. Lihat [Glosarium](/onboarding/glosarium) dan [Tenant dan hierarki organisasi](/dev/01a-tenant-and-org-hierarchy).

**2. "Terpasang" bukan satu status.** Katalog, entitlement, dan pemasangan module adalah tiga fakta terpisah dengan sumber kebenaran masing-masing. Lihat [Tiga kebenaran lifecycle](/onboarding/tiga-kebenaran).

**3. Parent-child organization tidak permanen.** Ia hidup pada node hierarchy berversi, bukan pada identitas organization. Jangan menyimpan `parent_id` permanen untuk organization Core.

## Kalau ada yang tidak jelas

Dokumentasi ini masih tumbuh. Kalau kamu menemukan lubang, itu bug dokumentasi — laporkan atau langsung perbaiki lewat PR. Lihat [Cara berkontribusi](/onboarding/kontribusi).
