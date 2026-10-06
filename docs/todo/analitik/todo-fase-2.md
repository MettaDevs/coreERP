# TODO fase 2 — interaksi, lintas module, dan dunia luar

Butir kerja area 12–18 [engine analitik](/todo/analitik/). Aturan pengerjaan dan berkas bersama ada
di [indeks TODO](/todo/analitik/TODO). Fase ini dimulai setelah gate rilis fase 1 terpenuhi
([PRD](/todo/analitik/prd#_15-gate-rilis-per-fase)); area di sini boleh dikerjakan bersamaan
sesuai lajurnya.

Hasil fase ini: dasbor menjadi interaktif, angka dari beberapa module dapat dibandingkan, data dapat
dibaca Excel/Power BI dan sistem pelanggan, dan dasbor dapat dipasang di situs pelanggan.

---

### 12. [x] Slicer, cross-filter, drill, dan ekspor widget

**Tempat:** `resources/js/components/analytics/{slicer-bar, drill-sheet, cross-filter-chips}.tsx`,
`app/Platform/Analytics/Http/Controllers/DrillController.php`, perluasan `ExportQueue` (`kind = analytics`)
· **Setelah:** 7, 8 · **Skill:** `coreerp-ui`, `coreerp-analytics`, `coreerp-page-standard` ·
**Selesai bila:** cerita US-05, US-06, dan US-13 lulus, slicer tersimpan sebagai tautan yang dapat
dibagikan, dan daftar drill sama persis dengan daftar layar module untuk saringan yang sama.

- [x] 12.1 Slicer di `analytics_dashboards.slicers`: sumber dimensi bersama atau field satu dataset,
  kontrol pilih banyak / rentang tanggal / ekspresi, nilai bawaan.
- [x] 12.2 Pemetaan slicer ke widget dan penanda "tidak berlaku di sini"; nilai slicer di query string,
  dibaca ulang setiap render.
- [x] 12.3 Cross-filter: klik nilai menambah chip saringan sementara ke widget lain.
- [x] 12.4 Drill-down hierarki waktu, dan hierarki yang dinyatakan dataset (`hierarchy()` ditambahkan
  ke `DatasetDefinition` bila dibutuhkan; perubahan aditif).
- [x] 12.5 Drill-through: `POST api/v1/analytics/drill` — baris, kursor pada `id`, 100 per halaman,
  1.000 di layar; kolom data pribadi mengikuti gerbang; baris membuka `recordRoute`.
- [x] 12.6 Ekspor widget dan daftar drill ke Excel lewat antrean ekspor yang ada, jenis baru
  `analytics`, kolom bertipe seperti ekspor daftar.
- [x] 12.7 Test paritas drill terhadap endpoint daftar module (memakai ulang test paritas area 5);
  ekspor memulangkan baris yang sama dengan layar.

---

### 13. [ ] Rumus dan perbandingan periode

**Tempat:** `app/Platform/Analytics/Query/Formula/*`, `Query/Comparison.php`,
`resources/js/components/analytics/formula-editor.tsx` · **Setelah:** 3 · **Keputusan:** KA-19 ·
**Skill:** `coreerp-analytics`, `laravel-tdd` · **Selesai bila:** bahasa di
[bahasa rumus](/todo/analitik/mesin-query#bahasa-rumus) terbaca dan terkompilasi dengan seluruh
aturannya, US-11 lulus, dan percobaan injeksi ditolak oleh test.

- [ ] 13.1 Lexer, parser, simpul pohon, dan pemancar SQL; galat berposisi.
- [ ] 13.2 Validasi: fungsi tertutup, batas panjang dan kedalaman, warisan mata uang.
- [ ] 13.3 Rumus di query (`formulas`) dan di widget; urutan dan top-N atas rumus.
- [ ] 13.4 `compare: previous_period | previous_year` dengan kolom `__previous`, `__change`,
  `__change_pct`; persen dari nol kosong.
- [ ] 13.5 Persen terhadap total (`sum(x) over ()`) sebagai pilihan tampilan measure.
- [ ] 13.6 Token tahun fiskal (`@this_fiscal_year`, `@last_fiscal_year`) lewat `FiscalCalendarDirectory`,
  dengan legal entity dari saringan atau workspace; tanpa legal entity, token ditolak dengan pesan.
- [ ] 13.7 Editor rumus di pembangun: daftar measure, daftar fungsi, galat di posisinya.
- [ ] 13.8 Test parser (termasuk `[count]); drop table x; --`, angka `1.000,5`, bagi nol) dan test
  perbandingan pada batas tahun.

---

### 14. [~] Dimensi bersama dan gabungan lintas module

**Tempat:** `app/Platform/Analytics/Datasets/SharedDimensionRegistry.php` (perluasan),
`Query/Blend.php`, jenis widget `blend`, fixture dua module · **Setelah:** 3 · **Keputusan:** KA-03 ·
**Skill:** `coreerp-analytics`, `coreerp-architecture` · **Selesai bila:** satu widget menampilkan
angka dua dataset dari dua module menurut dimensi bersama yang sama, hanya ketika keduanya terpasang,
tanpa join baris lintas module.

> Draft area 14 sudah menyimpan dan menghitung dua query secara terpisah, menampilkan gabungan luar penuh di tabel,
> dan menerapkan kebijakan data, slicer, serta cross-filter per sumber. Pilihan sumber nilai untuk pemilih dimensi bersama masih menunggu keputusan
> pemilik produk. Rumus, pembanding periode, persen terhadap total, dan ekspor Excel widget blend belum didukung.
> PostgreSQL feature tests, CI gabungan, dan verifikasi layar runtime juga masih menunggu.

- [ ] 14.1 Registry dimensi bersama lengkap: resolver label, pemilih nilai untuk slicer, dan
  pendaftaran dari Foundation (vendor, mata uang) lewat penyedia layanan fiturnya.
- [ ] 14.2 Query gabungan: daftar query (masing-masing satu dataset) yang berbagi dimensi bersama yang
  sama; tiap query dijalankan dengan principal dan batasnya sendiri, lalu digabung menurut nilai
  dimensi (gabungan luar penuh).
- [ ] 14.3 Penolakan bila salah satu module tidak terpasang, dengan pesan yang menyebut data mana yang
  tidak tersedia.
- [ ] 14.4 Pembangun: memilih dataset kedua hanya dari dataset yang berbagi dimensi bersama dengan yang
  pertama.
- [ ] 14.5 Test dengan dua fixture module: angka cocok dengan dua query terpisah; tanpa module kedua,
  widget menjelaskan dirinya; kebijakan data diterapkan per dataset.

---

### 15. [ ] Publikasi dan Query API luar

**Tempat:** migration `analytics_publications`; `app/Platform/Analytics/{Models/Publication,
Security/PublicationPrincipal, External}/*`; `IntegrationClient::SCOPES`; layar
`pages/platform/analytics/publications.tsx`; `apps/core/contracts/internal/integrasi-analitik.yaml`
beserta `paths/` dan `components/` · **Setelah:** 4, 6 · **Keputusan:** KA-05, KA-09, **KA-11 (wajib
disetujui)** · **Skill:** `coreerp-architecture` (Contract decision gate), `api-design`,
`security-review`, `coreerp-docs` · **Selesai bila:** US-09 lulus dari n8n atau `curl`, kontrak terbit
di `/docs` dan lolos pemeriksa cakupan, dan semua test di
[akses luar](/todo/analitik/akses-luar#test-yang-wajib) hijau.

- [ ] 15.1 Tabel dan model publikasi ([bentuk](/todo/analitik/akses-luar#tabel)), kode unik per tenant.
- [ ] 15.2 Layar publikasi: buat dari query tersimpan atau dasbor, saringan terkunci, klien yang boleh,
  format, ambang kelompok kecil, hentikan, cabut, pindahkan pemilik.
- [ ] 15.3 `PublicationPrincipal`: pemeriksaan ulang pembuat per permintaan; 403
  `analytics.publication_suspended`.
- [ ] 15.4 Scope `analytics.read` dan `analytics.embed` di `IntegrationClient::SCOPES`; pastikan layar
  Klien integrasi menampilkannya.
- [ ] 15.5 Endpoint daftar, metadata, dan baris (JSON, CSV), kursor, saringan tambahan yang hanya
  menyempitkan.
- [ ] 15.6 Penyembunyian kelompok kecil.
- [ ] 15.7 Kontrak `integrasi-analitik.yaml` dengan panduan untuk orang luar (memulai, autentikasi,
  publikasi, kursor, CSV, Apps Script, n8n, galat); `python contracts/bundle.py`,
  `check-contract-coverage.py`; tambahan `DocsPortalTest` bila ada daftar nilai baru.
- [ ] 15.8 Log query sumber `api` dengan id klien dan publikasi.
- [ ] 15.9 Test di [akses luar](/todo/analitik/akses-luar#test-yang-wajib).

---

### 16. [ ] Feed OData

**Tempat:** `app/Platform/Analytics/External/OData/*`, middleware penerjemah autentikasi, path kontrak
OData · **Setelah:** 15 · **Keputusan:** KA-09, **KA-13 (diputuskan spike 16.1)** · **Skill:**
`api-design`, `security-review` · **Selesai bila:** Excel dan Power BI Desktop membaca feed, refresh
terjadwal Power BI service berhasil (atau batasannya tertulis jujur di panduan), dan kunci tidak
pernah tercatat di log maupun trace.

- [ ] 16.1 **Spike satu hari**: prototipe service document + `$metadata` + satu entity set di SaaS dev;
  uji Excel "From OData feed", Power BI Desktop, dan refresh terjadwal Power BI service dengan Basic
  tanpa gateway; periksa lisensi dan Laravel 13 untuk `flat3/lodata`. Tulis hasilnya sebagai teks di
  halaman [riset](/todo/analitik/riset#_7-yang-belum-pasti-dan-cara-memastikannya) dan putuskan KA-13
  bersama pemilik produk.
- [ ] 16.2 Service document dan `$metadata` CSDL dari publikasi berjenis query.
- [ ] 16.3 Entity set: `$select`, `$filter` (subset, hanya kolom dimensi, menjadi saringan query
  analitik), `$orderby`, `$top`, `$skip`, `$count`, `@odata.nextLink`.
- [ ] 16.4 Galat OData dan 501 untuk opsi yang tidak didukung.
- [ ] 16.5 Middleware Basic dan kunci Web API, dengan penyamaran di log proxy dan trace SigNoz.
- [ ] 16.6 Path kontrak OData dan bagian panduan "Membaca dari Excel dan Power BI".
- [ ] 16.7 Test parser `$filter` (termasuk injeksi), kunci tidak tercatat, `$filter` tidak melebarkan.

---

### 17. [ ] Embed

**Tempat:** migration `analytics_embed_tokens`; `app/Platform/Analytics/Embed/*`; rute
`/embed/analytics/{code}` dan `/api/embed/v1/*` di luar grup `web`; entri Vite
`resources/js/embed/analytics.tsx`; `vite.config.ts` · **Setelah:** 7, 15 · **Keputusan:** KA-10, KA-11 ·
**Skill:** `security-review`, `coreerp-ui` · **Selesai bila:** US-07 lulus dari halaman host uji di asal
berbeda, token kedaluwarsa diperbarui lewat `postMessage` tanpa memuat ulang, asal yang tidak terdaftar
ditolak peramban, dan halaman embed tidak pernah mengirim `Set-Cookie`.

- [ ] 17.1 Publikasi berjenis dasbor: asal situs dan parameter embed (`key`, `field`, `required`,
  `allowed_values`).
- [ ] 17.2 Tabel token dan `POST analytics/embed-tokens` sesuai [aturan token](/todo/analitik/akses-luar#aturan-token).
- [ ] 17.3 `AuthenticateEmbedToken`, `EmbedPrincipal`, rate limit per token dan per publikasi.
- [ ] 17.4 Halaman embed: rute tanpa sesi, header CSP dan `Referrer-Policy`, entri Vite tanpa Shell,
  token dari fragment lalu fragment dihapus.
- [ ] 17.5 Protokol `postMessage` dua arah dengan pemeriksaan asal.
- [ ] 17.6 Contoh host (backend PHP dan HTML) di panduan kontrak.
- [ ] 17.7 Retensi token kedaluwarsa (`RetentionPolicies`).
- [ ] 17.8 Verifikasi peramban: halaman host di asal lain (misalnya `php -S` pada port berbeda),
  embed terpasang, asal ketiga ditolak, pembaruan token berjalan; tinjauan keamanan sebelum `[x]`.

---

### 18. [ ] Template dasbor dan beranda per peran

**Tempat:** `Contracts\Analytics\{DashboardTemplate, DashboardTemplates}`,
`app/Platform/Analytics/Templates/*`, migration `analytics_role_dashboards` dan
`analytics_user_preferences`, halaman galeri, halaman `/dashboard`, template module aset · **Setelah:**
7 · **Keputusan:** KA-17 · **Skill:** `coreerp-ui`, `coreerp-analytics`, `coreerp-architecture` ·
**Selesai bila:** US-01 dan US-16 lulus, halaman `/dashboard` kerangka terganti, dan template aset
menghasilkan angka yang sama dengan endpoint dasbor aset lama.

- [ ] 18.1 Kontrak template dan registry (singleton seperti `Datasets`).
- [ ] 18.2 Galeri: hanya template dari module terpasang; Pakai → dasbor tenant dengan
  `template_code`/`template_version`; penanda versi baru tanpa menimpa.
- [ ] 18.3 Dasbor beranda per security role (dengan prioritas) dan pilihan pengguna; halaman
  `/dashboard` membaca keduanya.
- [ ] 18.4 Template `management-aset.asset-overview` dari dasbor aset sesi "Struktur layer", setelah
  dikoordinasikan dengan sesi itu; endpoint lamanya diarsipkan lewat pull request module tersendiri.
- [ ] 18.5 Butir palet perintah "Dashboard aset" dan "Finance dashboard" yang ditulis mati dan tidak
  menuju ke mana pun diganti dengan dasbor yang benar-benar ada, atau dibuang.
- [ ] 18.6 Test: template module yang tidak terpasang tidak tampil; pakai dua kali menghasilkan dua
  dasbor; beranda memilih menurut aturan.
