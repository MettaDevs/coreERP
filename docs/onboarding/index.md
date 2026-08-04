# Selamat datang di CoreERP

Halaman ini untuk orang yang baru masuk tim. Tujuannya satu: dalam beberapa hari kamu bisa mengubah sesuatu di CoreERP tanpa melanggar aturan yang tidak tertulis.

## Yang perlu kamu tahu duluan

CoreERP **bukan** satu aplikasi Laravel besar. Ia platform yang terdiri dari beberapa repository yang berdiri sendiri:

| Repository | Isinya |
| --- | --- |
| `CoreERP` | Control plane, web shell, provider console, SDK UI. Repo ini juga rumah dokumentasi platform. |
| `app-erp-management-aset` | App bisnis pertama. API, UI, database, migration, contract, container sendiri. |
| `app-erp-hr` | App bisnis kedua. |
| `app-erp-template` | Titik mulai untuk app baru. |
| `erp-dev` | Orkestrasi Docker untuk menjalankan semuanya di laptop. Bukan repo domain. |

Konsekuensinya ada dua, dan keduanya sering bikin kaget orang baru:

**Satu app tidak boleh membaca database app lain.** Tidak ada `join` lintas app. Integrasi memakai REST/OpenAPI atau event/AsyncAPI. Ini bukan preferensi gaya — ini yang membuat app bisa dirilis, di-upgrade, dan di-uninstall sendiri-sendiri.

**Dokumen di [`/dev/`](/dev/) mengikat semua repo.** Kalau kamu bekerja di `app-erp-hr`, aturan di sana tetap berlaku untukmu.

## Jalur baca

Jangan baca 19 dokumen sekaligus. Urutannya:

<div class="tip custom-block" style="padding-top: 8px">

**Hari pertama** — [Hari pertama](/onboarding/hari-pertama) → [Setup lingkungan lokal](/onboarding/setup) → [Glosarium](/onboarding/glosarium)

**Minggu pertama** — [Empat kebenaran lifecycle](/onboarding/empat-kebenaran) → [Alur end-to-end](/onboarding/alur-end-to-end) → [Peta kode ke dokumen](/onboarding/peta-kode) → [Cara berkontribusi](/onboarding/kontribusi)

**Saat dibutuhkan** — sisanya di [Desain kanonik](/dev/), dibuka sesuai tugas

</div>

Kalau kamu ditugaskan ke app tertentu, buka juga hub teknisnya: [Management Aset](/apps/management-aset/) atau [Human Resources](/apps/human-resources/). Kalau kamu akan membangun app baru, mulai dari [Membangun app baru](/apps/membangun-app-baru) — bukan dari `git clone` template.

## Tiga hal yang paling sering disalahpahami

**1. Tenant bukan organization.** Tenant adalah pemegang kontrak dan batas isolasi data tertinggi. Organization adalah identitas bisnis di dalamnya. Keduanya beda tabel, beda tujuan, beda kolom scope. Lihat [Glosarium](/onboarding/glosarium) dan [Tenant dan hierarki organisasi](/dev/01a-tenant-and-org-hierarchy).

**2. "Terpasang" bukan satu status.** Katalog, entitlement, installation, dan runtime health adalah empat fakta terpisah dengan sumber kebenaran masing-masing. Lihat [Empat kebenaran lifecycle](/onboarding/empat-kebenaran).

**3. Parent-child organization tidak permanen.** Ia hidup pada node hierarchy berversi, bukan pada identitas organization. Jangan menyimpan `parent_id` permanen untuk organization Core.

## Kalau ada yang tidak jelas

Dokumentasi ini masih tumbuh. Kalau kamu menemukan lubang, itu bug dokumentasi — laporkan atau langsung perbaiki lewat PR. Lihat [Cara berkontribusi](/onboarding/kontribusi).
